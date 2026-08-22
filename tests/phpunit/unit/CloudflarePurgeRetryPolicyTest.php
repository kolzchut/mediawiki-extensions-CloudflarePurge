<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers CloudflarePurgeRetryPolicy
 */
class CloudflarePurgeRetryPolicyTest extends TestCase {

	/**
	 * A refused or timed-out connection is exactly what a retry is for.
	 */
	public function testTransientTransportFailureIsRetried() {
		// 7 = CURLE_COULDNT_CONNECT, 28 = CURLE_OPERATION_TIMEDOUT
		$this->assertSame( 0.25, CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 2, null, 7 ) );
		$this->assertSame( 0.5, CloudflarePurgeRetryPolicy::retryDelaySeconds( 1, 2, null, 28 ) );
	}

	/**
	 * A rejected certificate cannot be fixed by waiting, and retrying it
	 * multiplies the delay on a path where an editor is waiting for their save.
	 */
	public function testPermanentTransportFailureIsNotRetried() {
		// 60 = CURLE_SSL_CACERT
		$this->assertNull( CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 2, null, 60 ) );
		// 3 = CURLE_URL_MALFORMAT
		$this->assertNull( CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 2, null, 3 ) );

		$this->assertSame(
			'TLS CA certificate verification failed',
			CloudflarePurgeRetryPolicy::permanentTransportReason( 60 )
		);
		$this->assertNull( CloudflarePurgeRetryPolicy::permanentTransportReason( 28 ) );
	}

	/**
	 * The regression this class was changed for.
	 *
	 * libcurl reports every resolver failure as errno 6 — a SERVFAIL, a
	 * resolver timeout, a cycling Docker embedded DNS — not only a name that
	 * does not exist. Classifying it permanent abandoned the purge on the
	 * first attempt during a sub-second DNS blip, and nothing retries a
	 * dropped purge: purgeUrls() does not throw, CdnCacheUpdate::purge()
	 * discards its return value, and the page_touched bump makes a job re-run
	 * a no-op. The page then serves stale from the edge for a full TTL.
	 */
	public function testAResolverFailureGetsOneRetry() {
		// 6 = CURLE_COULDNT_RESOLVE_HOST
		$this->assertSame( 0.25, CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 2, null, 6 ) );
		$this->assertNull( CloudflarePurgeRetryPolicy::retryDelaySeconds( 1, 2, null, 6 ) );

		$this->assertNull( CloudflarePurgeRetryPolicy::permanentTransportReason( 6 ) );
		$this->assertSame(
			'could not resolve host',
			CloudflarePurgeRetryPolicy::fastRetryTransportReason( 6 )
		);
	}

	/**
	 * 5 is the same argument for a proxied install, and 35 is libcurl's
	 * catch-all for "the handshake went wrong" — which covers a mid-handshake
	 * reset or an edge node cycling as readily as a real misconfiguration.
	 * The genuinely permanent TLS errors are 51/58/59/60/77/83.
	 */
	public function testProxyResolutionAndHandshakeFailuresGetOneRetryToo() {
		// 5 = CURLE_COULDNT_RESOLVE_PROXY, 35 = CURLE_SSL_CONNECT_ERROR
		$this->assertSame( 0.25, CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 2, null, 5 ) );
		$this->assertNull( CloudflarePurgeRetryPolicy::retryDelaySeconds( 1, 2, null, 5 ) );
		$this->assertSame( 0.25, CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 2, null, 35 ) );
		$this->assertNull( CloudflarePurgeRetryPolicy::retryDelaySeconds( 1, 2, null, 35 ) );
	}

	/**
	 * A fast retry is one retry whatever $wgCloudflarePurgeMaxRetries says: on
	 * a resolver that is genuinely down, a full ladder spends the whole
	 * pre-send budget re-asking it.
	 */
	public function testTheFastRetryCapIsNotRaisedByMaxRetries() {
		$this->assertSame( 0.25, CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 9, null, 6 ) );
		$this->assertNull( CloudflarePurgeRetryPolicy::retryDelaySeconds( 1, 9, null, 6 ) );
		// ...while an ordinary transient failure still gets the configured ladder.
		$this->assertSame( 0.5, CloudflarePurgeRetryPolicy::retryDelaySeconds( 1, 9, null, 28 ) );
	}

	/**
	 * A dropped purge is invisible to everything upstream, so the class it was
	 * dropped under has to reach the log.
	 */
	public function testTransportFailuresAreClassifiedForTheLog() {
		$this->assertSame( 'permanent', CloudflarePurgeRetryPolicy::transportFailureClass( 60 ) );
		$this->assertSame( 'fast-retry', CloudflarePurgeRetryPolicy::transportFailureClass( 6 ) );
		$this->assertSame( 'transient', CloudflarePurgeRetryPolicy::transportFailureClass( 28 ) );
		$this->assertSame( 'transient', CloudflarePurgeRetryPolicy::transportFailureClass( 0 ) );
	}

	public function testServerErrorsAreRetriedAndClientErrorsAreNot() {
		$this->assertSame( 0.25, CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 2, 503 ) );
		$this->assertSame( 0.25, CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 2, 429 ) );

		// A bad token or a wrong zone ID will fail identically every time.
		$this->assertNull( CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 2, 403 ) );
		$this->assertNull( CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 2, 400 ) );
	}

	public function testRetriesAreBounded() {
		$this->assertNull( CloudflarePurgeRetryPolicy::retryDelaySeconds( 2, 2, 503 ) );
		$this->assertNull( CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 0, 503 ) );
	}

	public function testBackoffDoubles() {
		$this->assertSame( 0.25, CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 5, 500 ) );
		$this->assertSame( 0.5, CloudflarePurgeRetryPolicy::retryDelaySeconds( 1, 5, 500 ) );
		$this->assertSame( 1.0, CloudflarePurgeRetryPolicy::retryDelaySeconds( 2, 5, 500 ) );
	}

	/**
	 * Cloudflare's own number wins on a 429 — but only up to the point where
	 * waiting costs more than the purge is worth.
	 */
	public function testRetryAfterIsHonouredWithinTheCeiling() {
		$this->assertSame(
			2.0,
			CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 2, 429, 0, 2.0 )
		);
	}

	public function testRetryAfterNeverShortensTheBackoff() {
		$this->assertSame(
			0.5,
			CloudflarePurgeRetryPolicy::retryDelaySeconds( 1, 3, 429, 0, 0.1 )
		);
	}

	public function testAnUnreasonableRetryAfterEndsTheAttempt() {
		$this->assertNull(
			CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 2, 429, 0, 120.0 )
		);
	}

	/**
	 * Retry-After on any other status is not a licence to retry it.
	 */
	public function testRetryAfterDoesNotMakeAClientErrorRetryable() {
		$this->assertNull(
			CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 2, 403, 0, 1.0 )
		);
	}
}
