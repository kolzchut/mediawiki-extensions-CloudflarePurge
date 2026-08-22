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
 * A timed-out or refused connection can; an unresolvable host or a rejected
 * TLS certificate cannot, and retrying those only multiplies the delay by the
 * attempt count while the outage lasts.
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

	/**
	 * libcurl CURLE_* codes a retry cannot fix, and the reason to log.
	 *
	 * Numeric literals rather than the CURLE_* constants so this class stays
	 * loadable without ext-curl; the numbers are part of libcurl's ABI and
	 * are never reassigned.
	 */
	private const PERMANENT_CURL_ERRORS = [
		1 => 'unsupported protocol',
		3 => 'malformed URL',
		5 => 'could not resolve proxy',
		6 => 'could not resolve host',
		35 => 'TLS handshake failed',
		51 => 'TLS certificate verification failed',
		58 => 'local TLS certificate problem',
		59 => 'no matching TLS cipher',
		60 => 'TLS CA certificate verification failed',
		77 => 'CA certificate file unreadable',
		83 => 'TLS issuer check failed',
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
	 * How long to wait before the next attempt.
	 *
	 * @param int $attempt Attempts already made, starting at 0
	 * @param int $maxRetries Retries allowed after the first attempt
	 * @param int|null $status HTTP status, or null for a transport failure
	 * @param int $curlErrno cURL error number; only meaningful when $status is null
	 * @param float|null $retryAfter Seconds requested by a Retry-After header
	 * @return float|null Seconds to wait, or null to stop retrying
	 */
	public static function retryDelaySeconds(
		int $attempt,
		int $maxRetries,
		?int $status,
		int $curlErrno = 0,
		?float $retryAfter = null
	): ?float {
		if ( $attempt >= $maxRetries ) {
			return null;
		}

		$backoff = ( self::BASE_DELAY_MS / 1000 ) * ( 2 ** $attempt );

		if ( $status === null ) {
			// Transport failure: retry only what a retry can fix.
			return self::permanentTransportReason( $curlErrno ) === null ? $backoff : null;
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
