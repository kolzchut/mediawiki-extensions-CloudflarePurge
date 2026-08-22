<?php

/**
 * A batch of URLs to hand to the Cloudflare purge API, expanded over the
 * configured extra hosts, de-duplicated, capped and split into API-sized chunks.
 *
 * This class is deliberately free of MediaWiki dependencies so that the
 * fan-out arithmetic — the part that decides how many requests a single edit
 * can turn into — can be tested in isolation.
 */
class CloudflarePurgeUrlSet {

	/**
	 * Hard ceiling on the URLs one purge call may send.
	 *
	 * MediaWiki hands us at most $wgUpdateRowsPerQuery (100) pages per leaf
	 * HTMLCacheUpdateJob — $wgUpdateRowsPerJob (300) is the partition *range*
	 * BacklinkJobUtils::partitionBacklinkJob() covers, not the number of
	 * titles a leaf job carries — one URL each, multiplied by (1 + extra
	 * hosts). With one extra host that is 200 URLs. The default therefore
	 * never binds in normal operation; it exists to clamp a pathological
	 * caller (a maintenance script, a raised $wgUpdateRowsPerQuery) rather
	 * than to shape ordinary traffic.
	 */
	public const DEFAULT_MAX_URLS = 1000;

	/**
	 * URLs per API request. Cloudflare caps single-file purge at 100
	 * operations per request on Free/Pro/Business; the 500 figure applies to
	 * Enterprise single-file purge only — purge by hostname, tag or prefix is
	 * capped at 100 per request on every plan.
	 */
	public const DEFAULT_URLS_PER_REQUEST = 100;

	/** @var string[][] */
	private $chunks;

	/** @var int */
	private $dropped;

	/** @var int */
	private $count;

	/**
	 * @param string[][] $chunks
	 * @param int $count
	 * @param int $dropped
	 */
	private function __construct( array $chunks, int $count, int $dropped ) {
		$this->chunks = $chunks;
		$this->count = $count;
		$this->dropped = $dropped;
	}

	/**
	 * Pull the URLs out of a batch of 'cdn-url-purges' events.
	 *
	 * Kept here, next to the rest of the URL handling, so the shape of the
	 * event payload MediaWiki broadcasts can be asserted without a wiki.
	 *
	 * @param array[] $events List of [ 'url' => string, 'timestamp' => float ]
	 * @return string[]
	 */
	public static function urlsFromEvents( array $events ): array {
		$urls = [];
		foreach ( $events as $event ) {
			if ( is_array( $event ) && isset( $event['url'] ) && is_string( $event['url'] ) ) {
				$urls[] = $event['url'];
			}
		}

		return $urls;
	}

	/**
	 * @param string[] $urls Absolute URLs
	 * @param string[] $extraHosts Additional hostnames serving the same paths
	 * @param int $maxUrls Ceiling on total URLs; 0 or less means no ceiling
	 * @param int $urlsPerRequest URLs per API request; clamped to at least 1
	 * @return self
	 */
	public static function fromUrls(
		array $urls,
		array $extraHosts = [],
		int $maxUrls = self::DEFAULT_MAX_URLS,
		int $urlsPerRequest = self::DEFAULT_URLS_PER_REQUEST
	): self {
		$expanded = [];
		foreach ( $urls as $url ) {
			if ( !is_string( $url ) ) {
				continue;
			}
			$url = trim( $url );
			if ( $url === '' ) {
				continue;
			}
			$parsed = parse_url( $url );
			if ( !is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
				// Cloudflare only accepts absolute URLs; a relative or
				// malformed one would fail the whole request.
				continue;
			}
			$expanded[] = $url;
			foreach ( $extraHosts as $host ) {
				$host = trim( (string)$host );
				if ( $host === '' ) {
					continue;
				}
				$extra = $parsed['scheme'] . '://' . $host;
				if ( isset( $parsed['path'] ) ) {
					$extra .= $parsed['path'];
				}
				if ( isset( $parsed['query'] ) ) {
					$extra .= '?' . $parsed['query'];
				}
				$expanded[] = $extra;
			}
		}

		$expanded = array_values( array_unique( $expanded ) );

		$dropped = 0;
		if ( $maxUrls > 0 && count( $expanded ) > $maxUrls ) {
			$dropped = count( $expanded ) - $maxUrls;
			$expanded = array_slice( $expanded, 0, $maxUrls );
		}

		$chunkSize = max( 1, $urlsPerRequest );

		return new self(
			$expanded ? array_chunk( $expanded, $chunkSize ) : [],
			count( $expanded ),
			$dropped
		);
	}

	/**
	 * @return string[][] One entry per Cloudflare API request
	 */
	public function getChunks(): array {
		return $this->chunks;
	}

	/**
	 * @return int URLs that will actually be sent
	 */
	public function getUrlCount(): int {
		return $this->count;
	}

	/**
	 * @return int URLs discarded by the cap
	 */
	public function getDroppedCount(): int {
		return $this->dropped;
	}

	public function isEmpty(): bool {
		return $this->chunks === [];
	}
}
