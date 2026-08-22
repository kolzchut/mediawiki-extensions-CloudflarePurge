<?php

use Wikimedia\EventRelayer\EventRelayer;

/**
 * Records the URLs MediaWiki broadcasts on the CDN purge channel, standing in
 * for CloudflarePurgeRelayer so the test needs no network.
 */
class CloudflarePurgeCapturingRelayer extends EventRelayer {
	/** @var string[] */
	public static $urls = [];

	/**
	 * @param string $channel
	 * @param array[] $events
	 * @return bool
	 */
	protected function doNotify( $channel, array $events ) {
		foreach ( $events as $event ) {
			if ( isset( $event['url'] ) ) {
				self::$urls[] = $event['url'];
			}
		}
		return true;
	}
}
