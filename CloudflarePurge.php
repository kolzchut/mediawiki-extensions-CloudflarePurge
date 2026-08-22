<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class CloudflarePurge {

	/** @var int Milliseconds to wait before the first retry; doubled each attempt */
	private const RETRY_BASE_DELAY_MS = 250;

	/**
	 * Subscribe to MediaWiki's own CDN purge stream unless the wiki has
	 * already claimed that channel or has opted out.
	 *
	 * Doing this from the registration callback rather than from
	 * LocalSettings.php keeps the wiring an implementation detail of the
	 * extension: enabling the extension is enough to get complete purging.
	 */
	public static function onRegistration() {
		if ( isset( $GLOBALS['wgCloudflarePurgeUseCdnRelay'] )
			&& !$GLOBALS['wgCloudflarePurgeUseCdnRelay']
		) {
			return;
		}
		if ( isset( $GLOBALS['wgEventRelayerConfig']['cdn-url-purges'] ) ) {
			// Someone else already owns this channel; do not fight over it.
			return;
		}
		$GLOBALS['wgEventRelayerConfig']['cdn-url-purges'] = [
			'class' => CloudflarePurgeRelayer::class,
		];
	}

	/**
	 * @return bool Whether purges arrive via MediaWiki's CDN purge stream
	 */
	private static function relayEnabled(): bool {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		if ( !$config->get( 'CloudflarePurgeUseCdnRelay' ) ) {
			return false;
		}
		$relayers = $config->get( 'EventRelayerConfig' );

		return ( $relayers['cdn-url-purges']['class'] ?? null ) === CloudflarePurgeRelayer::class;
	}

	/**
	 * Purge the Cloudflare cache of the changed page
	 *
	 * When the CDN relay is active this is a no-op: core purges the page
	 * itself in WikiPage::onArticleEdit() and queues HTMLCacheUpdateJob for
	 * everything that transcludes or redirects to it, and every one of those
	 * URLs reaches us through CloudflarePurgeRelayer. Purging here as well
	 * would only duplicate the first of those requests.
	 *
	 * @param WikiPage $wikiPage
	 */
	public static function onPageSaveComplete( WikiPage $wikiPage ) {
		if ( self::relayEnabled() ) {
			return;
		}
		$title = $wikiPage->getTitle();
		self::purge( $title->getFullURL() );
	}

	/**
	 * Purge URL when a page is deleted
	 *
	 * As above: WikiPage::onArticleDelete() already purges the page, its talk
	 * page and its backlinks when the relay is active.
	 *
	 * @param MediaWiki\Page\ProperPageIdentity $page
	 */
	public static function onPageDeleteComplete( MediaWiki\Page\ProperPageIdentity $page ) {
		if ( self::relayEnabled() ) {
			return;
		}
		$title = Title::newFromPageIdentity( $page );
		self::purge( $title->getFullURL() );
	}

	/**
	 * Purge the given URL
	 *
	 * @param string $url URL of the page to purge
	 * @return bool Success
	 */
	public static function purge( string $url ) {
		return self::purgeUrls( [ $url ] );
	}

	/**
	 * Purge a batch of URLs.
	 *
	 * Never throws. A purge that fails is a page that stays stale until its
	 * TTL expires, which is bad; a purge that throws is worse, because the
	 * caller is usually a job — and a job that fails without a working retry
	 * path is lost outright while the page_touched bump it already made makes
	 * a re-run a no-op. So failures are logged at error level and reported
	 * through the return value.
	 *
	 * @param string[] $urls
	 * @return bool True if everything that was attempted succeeded
	 */
	public static function purgeUrls( array $urls ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$logger = LoggerFactory::getInstance( 'CloudflarePurge' );

		$zoneID = $config->get( 'CloudflarePurgeZoneID' );
		if ( !$zoneID ) {
			return true;
		}

		$purgeToken = $config->get( 'CloudflarePurgeToken' );
		$authEmail = $config->get( 'CloudflarePurgeAuthEmail' );
		$authKey = $config->get( 'CloudflarePurgeAuthKey' );
		if ( $purgeToken ) {
			$headers = [
				'Authorization: Bearer ' . $purgeToken,
				'Content-Type: application/json'
			];
		} elseif ( $authEmail && $authKey ) {
			$headers = [
				'X-Auth-Email: ' . $authEmail,
				'X-Auth-Key: ' . $authKey,
				'Content-Type: application/json'
			];
		} else {
			return true;
		}

		$set = CloudflarePurgeUrlSet::fromUrls(
			$urls,
			(array)$config->get( 'CloudflarePurgeExtraHosts' ),
			(int)$config->get( 'CloudflarePurgeMaxUrlsPerBatch' ),
			(int)$config->get( 'CloudflarePurgeUrlsPerRequest' )
		);

		if ( $set->getDroppedCount() ) {
			// The cap is a circuit breaker, so tripping it is a real finding:
			// those pages will stay stale for a full TTL.
			$logger->error(
				'Cloudflare purge batch capped: {dropped} of {total} URLs discarded',
				[
					'dropped' => $set->getDroppedCount(),
					'total' => $set->getDroppedCount() + $set->getUrlCount(),
					'cap' => (int)$config->get( 'CloudflarePurgeMaxUrlsPerBatch' ),
				]
			);
		}

		if ( $set->isEmpty() ) {
			return true;
		}

		$chunks = $set->getChunks();
		$logger->debug(
			'Purging {count} URL(s) in {requests} request(s): {urls}',
			[
				'count' => $set->getUrlCount(),
				'requests' => count( $chunks ),
				'urls' => implode( ' ', array_slice( array_merge( ...$chunks ), 0, 50 ) ),
			]
		);

		$retries = max( 0, (int)$config->get( 'CloudflarePurgeMaxRetries' ) );
		$ok = true;
		foreach ( $chunks as $chunk ) {
			$ok = self::sendChunk( $chunk, $zoneID, $headers, $retries, $logger ) && $ok;
		}

		return $ok;
	}

	/**
	 * @param string[] $urls At most $wgCloudflarePurgeUrlsPerRequest entries
	 * @param string $zoneID
	 * @param string[] $headers
	 * @param int $retries
	 * @param Psr\Log\LoggerInterface $logger
	 * @return bool
	 */
	private static function sendChunk(
		array $urls, string $zoneID, array $headers, int $retries, $logger
	) {
		$attempt = 0;
		while ( true ) {
			[ $status, $error ] = self::request( $urls, $zoneID, $headers );
			if ( $status === true ) {
				return true;
			}

			$retryable = ( $status === null || $status === 429 || $status >= 500 );
			if ( !$retryable || $attempt >= $retries ) {
				$logger->error(
					'Cloudflare purge failed for {count} URLs: {error}',
					[
						'count' => count( $urls ),
						'error' => $error,
						'httpStatus' => $status,
						'attempts' => $attempt + 1,
						'firstUrl' => $urls[0] ?? '',
					]
				);
				return false;
			}

			$logger->warning(
				'Cloudflare purge attempt {attempt} failed, retrying: {error}',
				[ 'attempt' => $attempt + 1, 'error' => $error, 'httpStatus' => $status ]
			);
			usleep( self::RETRY_BASE_DELAY_MS * 1000 * ( 2 ** $attempt ) );
			$attempt++;
		}
	}

	/**
	 * @param string[] $urls
	 * @param string $zoneID
	 * @param string[] $headers
	 * @return array{0:true|int|null,1:string} true on success, otherwise the
	 *   HTTP status (or null for a transport-level failure) and a message
	 */
	private static function request( array $urls, string $zoneID, array $headers ) {
		$curl = curl_init();
		curl_setopt( $curl, CURLOPT_URL,
			'https://api.cloudflare.com/client/v4/zones/' . $zoneID . '/purge_cache' );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $curl, CURLOPT_POST, true );
		curl_setopt( $curl, CURLOPT_POSTFIELDS, json_encode( [ 'files' => $urls ] ) );
		curl_setopt( $curl, CURLOPT_HTTPHEADER, $headers );

		$response = curl_exec( $curl );
		$httpStatus = (int)curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$curlError = curl_error( $curl );

		if ( $response === false ) {
			return [ null, $curlError ?: 'transport failure' ];
		}

		$result = json_decode( $response, true );
		if ( !is_array( $result ) || !isset( $result['success'] ) ) {
			return [ $httpStatus ?: null, 'invalid response from Cloudflare API' ];
		}

		if ( $result['success'] ) {
			return [ true, '' ];
		}

		$messages = [];
		foreach ( $result['errors'] ?? [] as $error ) {
			if ( isset( $error['message'] ) ) {
				$messages[] = $error['message'];
			}
		}

		return [ $httpStatus ?: null, $messages ? implode( ', ', $messages ) : 'unknown error' ];
	}
}
