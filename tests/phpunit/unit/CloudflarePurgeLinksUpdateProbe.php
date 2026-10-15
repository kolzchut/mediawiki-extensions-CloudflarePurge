<?php

/**
 * Drives CloudflarePurge::onLinksUpdateComplete() without MediaWiki.
 *
 * The handler reaches MediaWiki in three places, each behind a seam:
 * whether the relay is on (main config), which URLs the page has
 * (HtmlCacheUpdater) and where the purge goes (the job queue). This subclass
 * replaces all three with recorders. The decisions under test, which update
 * queues a purge and with what, are the real handler's.
 *
 * The real seams are not exercised here, because there is no wiki to answer
 * them; the dev reproduction on kolzchut/kz-infrastructure#615 runs them
 * end to end.
 */
class CloudflarePurgeLinksUpdateProbe extends CloudflarePurge {

	/** @var bool What relayEnabled() answers */
	public static $relay = true;

	/** @var string[] What linksUpdateUrls() answers */
	public static $urls = [];

	/** @var array Each page linksUpdateUrls() was asked about */
	public static $urlLookups = [];

	/** @var string[][] One entry per queueCdnPurge() call */
	public static $queued = [];

	public static function reset() {
		self::$relay = true;
		self::$urls = [];
		self::$urlLookups = [];
		self::$queued = [];
	}

	/**
	 * @return bool
	 */
	protected static function relayEnabled(): bool {
		return self::$relay;
	}

	/**
	 * @param mixed $page
	 * @return string[]
	 */
	protected static function linksUpdateUrls( $page ): array {
		self::$urlLookups[] = $page;
		return self::$urls;
	}

	/**
	 * @param string[] $urls
	 */
	protected static function queueCdnPurge( array $urls ) {
		self::$queued[] = $urls;
	}
}
