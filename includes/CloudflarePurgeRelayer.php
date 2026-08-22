<?php

use Wikimedia\EventRelayer\EventRelayer;

/**
 * Relays MediaWiki's own CDN purge stream to Cloudflare.
 *
 * MediaWiki already computes the complete set of URLs a change invalidates —
 * the edited page, the pages that transclude it, the pages that redirect to
 * it, the pages that embed a re-uploaded file — and it already does so in
 * batched, de-duplicated, job-queued form (HTMLCacheUpdateJob). It hands that
 * set to CdnCacheUpdate::purge(), which broadcasts it on the 'cdn-url-purges'
 * event channel before falling back to $wgCdnServers.
 *
 * Subscribing to that channel is therefore all this extension needs in order
 * to purge backlinks: the fan-out, the batching and the de-duplication are
 * core's, not ours.
 */
class CloudflarePurgeRelayer extends EventRelayer {

	/**
	 * @param string $channel
	 * @param array[] $events List of [ 'url' => string, 'timestamp' => float ]
	 * @return bool
	 */
	protected function doNotify( $channel, array $events ) {
		$urls = [];
		foreach ( $events as $event ) {
			if ( isset( $event['url'] ) && is_string( $event['url'] ) ) {
				$urls[] = $event['url'];
			}
		}

		return CloudflarePurge::purgeUrls( $urls );
	}
}
