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
	 * An unresolvable host or a rejected certificate cannot be fixed by
	 * waiting, and retrying it multiplies the delay on a path where an editor
	 * is waiting for their save.
	 */
	public function testPermanentTransportFailureIsNotRetried() {
		// 6 = CURLE_COULDNT_RESOLVE_HOST
		$this->assertNull( CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 2, null, 6 ) );
		// 60 = CURLE_SSL_CACERT
		$this->assertNull( CloudflarePurgeRetryPolicy::retryDelaySeconds( 0, 2, null, 60 ) );

		$this->assertSame(
			'could not resolve host',
			CloudflarePurgeRetryPolicy::permanentTransportReason( 6 )
		);
		$this->assertNull( CloudflarePurgeRetryPolicy::permanentTransportReason( 28 ) );
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
