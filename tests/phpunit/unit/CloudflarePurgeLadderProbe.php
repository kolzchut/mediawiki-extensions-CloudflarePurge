<?php

/**
 * Drives CloudflarePurge's retry ladder on a virtual clock.
 *
 * The ladder's cost is almost entirely time it spends waiting — for a cURL
 * timeout, then for a backoff sleep, then for the next timeout. A test that
 * sat through that in real time would take three quarters of a minute to
 * assert one chunk's worst case, which is precisely why the un-budgeted
 * behaviour went unnoticed. So this replaces the two seams that consume
 * wall-clock — now() and sleepSeconds() — with an accumulator, and replaces
 * request() with a script.
 *
 * Nothing else is stubbed: the attempt loop, the retry policy and the budget
 * arithmetic under test are the real ones.
 */
class CloudflarePurgeLadderProbe extends CloudflarePurge {

	/** @var float Virtual now, in seconds since the start of the call */
	public static $clock = 0.0;

	/** @var int cURL error number every scripted attempt returns */
	public static $curlErrno = 28;

	/** @var int|null HTTP status, or null for a transport failure */
	public static $status = null;

	/**
	 * How long a scripted attempt takes.
	 *
	 * 'total' models an endpoint that accepts the connection and then stops
	 * answering: cURL waits out CURLOPT_TIMEOUT. 'connect' models a
	 * blackholed endpoint dropping SYNs: cURL gives up at
	 * CURLOPT_CONNECTTIMEOUT, whichever of the two is shorter. 'none' models a
	 * failure that costs no time at all — a refused connection, a resolver
	 * answering NXDOMAIN from cache.
	 *
	 * @var string
	 */
	public static $cost = 'total';

	/** @var array[] One [ connectTimeout, totalTimeout ] per attempt made */
	public static $granted = [];

	public static function reset() {
		self::$clock = 0.0;
		self::$curlErrno = 28;
		self::$status = null;
		self::$cost = 'total';
		self::$granted = [];
	}

	/**
	 * @param string[] $urls
	 * @param int $retries
	 * @param CloudflarePurgeArrayLogger $logger
	 * @param CloudflarePurgeBudget $budget
	 * @return bool
	 */
	public static function runChunk(
		array $urls, int $retries, $logger, CloudflarePurgeBudget $budget
	) {
		return static::sendChunk( $urls, 'zone-id', [], $retries, $logger, $budget );
	}

	/**
	 * @return int Attempts the ladder made
	 */
	public static function attempts(): int {
		return count( self::$granted );
	}

	/**
	 * @return float
	 */
	protected static function now(): float {
		return self::$clock;
	}

	/**
	 * @param float $seconds
	 */
	protected static function sleepSeconds( float $seconds ) {
		self::$clock += $seconds;
	}

	/**
	 * @param string[] $urls
	 * @param string $zoneID
	 * @param string[] $headers
	 * @param float $connectTimeout
	 * @param float $totalTimeout
	 * @return array
	 */
	protected static function request(
		array $urls, string $zoneID, array $headers,
		float $connectTimeout, float $totalTimeout
	) {
		self::$granted[] = [ $connectTimeout, $totalTimeout ];
		if ( self::$cost === 'connect' ) {
			self::$clock += min( $connectTimeout, $totalTimeout );
		} elseif ( self::$cost === 'total' ) {
			self::$clock += $totalTimeout;
		}

		return [
			'ok' => false,
			'status' => self::$status,
			'error' => 'scripted failure',
			'curlErrno' => self::$curlErrno,
			'retryAfter' => null,
		];
	}
}
