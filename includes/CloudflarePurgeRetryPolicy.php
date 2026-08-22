<?php

/**
 * Decides whether a failed purge request is worth trying again, and how long
 * to wait before doing so.
 *
 * This lives apart from the request itself for two reasons. It is the part
 * that has to be right — a purge now runs inside a PRESEND deferred update,
 * so every second spent retrying is a second the editor waits for their save
 * — and it is the part that can be tested without MediaWiki, a network or a
 * Cloudflare account.
 *
 * The rule it encodes: retry only failures that a retry can plausibly fix.
 * A timed-out or refused connection can; a rejected TLS certificate cannot,
 * and retrying that only multiplies the delay by the attempt count while the
 * outage lasts.
 *
 * Between those two sits a third class, which is the reason this file exists
 * in its present form. libcurl reports *every* resolver failure as errno 6,
 * not only a name that does not exist: a SERVFAIL, a resolver timeout, a
 * cycling Docker embedded DNS, a systemd-resolved restart. Treating 6 as
 * permanent abandons the purge on the first attempt during a sub-second DNS
 * blip — and because purgeUrls() never throws, CdnCacheUpdate::purge()
 * discards its return value, and the page_touched bump has already happened,
 * that purge is not retried by anything, ever. The page stays stale at the
 * edge for a full TTL and only a log line records it. errno 35 has the same
 * shape: CURLE_SSL_CONNECT_ERROR is libcurl's catch-all for "the handshake
 * went wrong", which covers a mid-handshake reset or an edge node cycling as
 * readily as a genuine misconfiguration.
 *
 * So on a time-bounded path those get exactly one fast retry rather than the
 * full ladder. One retry costs BASE_DELAY_MS where an editor is waiting and
 * recovers the blip; a full ladder would spend the whole budget re-asking a
 * resolver that is genuinely down.
 *
 * That justification reaches exactly as far as the budget does, so the cap
 * does too. Where there is no budget — the job-queue path, which carries the
 * backlink fan-out and which nobody is waiting on — the full ladder applies,
 * because there a blip outlasting one 250 ms backoff would otherwise drop the
 * larger and more valuable URL set for nothing saved.
 */
class CloudflarePurgeRetryPolicy {

	/** @var int Milliseconds to wait before the first retry; doubled each attempt */
	public const BASE_DELAY_MS = 250;

	/**
	 * Longest Retry-After Cloudflare may ask us to honour.
	 *
	 * Beyond this we give up rather than sleep: the caller is either a job
	 * (which will be re-run) or a pre-send deferred update (where the editor
	 * is waiting), and neither should block for a rate-limit window.
	 */
	public const MAX_RETRY_AFTER_SECONDS = 5.0;

	/** @var int Retries allowed for a failure that is usually, but not always, permanent */
	public const FAST_RETRY_ATTEMPTS = 1;

	/**
	 * libcurl CURLE_* codes a retry cannot fix, and the reason to log.
	 *
	 * Every one of these is a configuration or environment fault that will
	 * produce the identical error on the next attempt: a URL this build of
	 * cURL cannot speak, a malformed URL, a certificate that does not verify.
	 *
	 * Numeric literals rather than the CURLE_* constants so this class stays
	 * loadable without ext-curl; the numbers are part of libcurl's ABI and
	 * are never reassigned. 51 is kept for completeness only — libcurl folded
	 * it into 60 in 7.62, so it cannot fire on any currently shipping build.
	 */
	private const PERMANENT_CURL_ERRORS = [
		1 => 'unsupported protocol',
		3 => 'malformed URL',
		51 => 'TLS certificate verification failed',
		58 => 'local TLS certificate problem',
		59 => 'no matching TLS cipher',
		60 => 'TLS CA certificate verification failed',
		77 => 'CA certificate file unreadable',
		83 => 'TLS issuer check failed',
	];

	/**
	 * libcurl CURLE_* codes that usually mean a lasting fault but are also
	 * what a momentary one looks like. See the class docblock: on a
	 * time-bounded path these get FAST_RETRY_ATTEMPTS retries rather than
	 * $wgCloudflarePurgeMaxRetries.
	 */
	private const FAST_RETRY_CURL_ERRORS = [
		5 => 'could not resolve proxy',
		6 => 'could not resolve host',
		35 => 'TLS handshake failed',
	];

	/**
	 * @param int $curlErrno cURL error number, 0 when the transport succeeded
	 * @return string|null Reason this failure is permanent, or null if a retry
	 *   might succeed
	 */
	public static function permanentTransportReason( int $curlErrno ): ?string {
		return self::PERMANENT_CURL_ERRORS[$curlErrno] ?? null;
	}

	/**
	 * @param int $curlErrno cURL error number
	 * @return string|null Reason this failure gets only one fast retry, or
	 *   null if it is not in that class
	 */
	public static function fastRetryTransportReason( int $curlErrno ): ?string {
		return self::FAST_RETRY_CURL_ERRORS[$curlErrno] ?? null;
	}

	/**
	 * How a transport failure was classified, for the log line. A dropped
	 * purge is invisible to everything upstream, so the reason it was dropped
	 * has to be greppable.
	 *
	 * @param int $curlErrno
	 * @return string One of 'permanent', 'fast-retry', 'transient'
	 */
	public static function transportFailureClass( int $curlErrno ): string {
		if ( self::permanentTransportReason( $curlErrno ) !== null ) {
			return 'permanent';
		}
		if ( self::fastRetryTransportReason( $curlErrno ) !== null ) {
			return 'fast-retry';
		}

		return 'transient';
	}

	/**
	 * How long to wait before the next attempt.
	 *
	 * @param int $attempt Attempts already made, starting at 0
	 * @param int $maxRetries Retries allowed after the first attempt
	 * @param int|null $status HTTP status, or null for a transport failure
	 * @param int $curlErrno cURL error number; only meaningful when $status is null
	 * @param float|null $retryAfter Seconds requested by a Retry-After header
	 * @param bool $timeBounded Whether the caller is running under a wall-clock
	 *   budget. Only then is the fast-retry cap applied — see the class
	 *   docblock. Defaults to false, the permissive value, so that a caller
	 *   which forgets to say gets the full configured ladder rather than a cap
	 *   it never asked for; on the pre-send path the budget itself is the
	 *   backstop either way.
	 * @return float|null Seconds to wait, or null to stop retrying
	 */
	public static function retryDelaySeconds(
		int $attempt,
		int $maxRetries,
		?int $status,
		int $curlErrno = 0,
		?float $retryAfter = null,
		bool $timeBounded = false
	): ?float {
		if ( $attempt >= $maxRetries ) {
			return null;
		}

		$backoff = ( self::BASE_DELAY_MS / 1000 ) * ( 2 ** $attempt );

		if ( $status === null ) {
			// Transport failure: retry only what a retry can fix.
			if ( self::permanentTransportReason( $curlErrno ) !== null ) {
				return null;
			}
			// ...and where time is scarce, give the ambiguous ones one chance
			// rather than the full ladder.
			if ( $timeBounded
				&& self::fastRetryTransportReason( $curlErrno ) !== null
				&& $attempt >= self::FAST_RETRY_ATTEMPTS
			) {
				return null;
			}

			return $backoff;
		}

		if ( $status === 429 ) {
			if ( $retryAfter === null ) {
				return $backoff;
			}
			if ( $retryAfter > self::MAX_RETRY_AFTER_SECONDS ) {
				return null;
			}
			// Cloudflare's own number wins, but never shortens the backoff.
			return max( $retryAfter, $backoff );
		}

		return $status >= 500 ? $backoff : null;
	}
}
