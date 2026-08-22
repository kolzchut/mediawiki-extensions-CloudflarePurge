<?php

/**
 * Drives the real request path — real cURL, real timeouts, real clock — at a
 * socket the test owns.
 *
 * The virtual-clock probe proves the arithmetic. This proves the arithmetic is
 * connected to anything: that a clamped timeout is actually handed to libcurl
 * and actually ends the attempt.
 */
class CloudflarePurgeSocketProbe extends CloudflarePurge {

	/** @var string Endpoint every request goes to */
	public static $url = '';

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
	 * @param string $zoneID
	 * @return string
	 */
	protected static function endpointUrl( string $zoneID ): string {
		return self::$url;
	}
}
