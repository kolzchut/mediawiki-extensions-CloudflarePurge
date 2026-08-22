<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class CloudflarePurge {

	/** @var int Seconds to wait for the TCP/TLS connection to Cloudflare */
	private const CONNECT_TIMEOUT_SECONDS = 5;

	/** @var int Seconds to wait for the whole request, connection included */
	private const TOTAL_TIMEOUT_SECONDS = 15;

	/** @var string One chunk purged successfully */
	public const CHUNK_OK = 'ok';

	/**
	 * @var string One chunk failed for a reason of its own — the retry ladder
	 *   ran out, or the failure was one a retry cannot fix.
	 */
	public const CHUNK_FAILED = 'failed';

	/**
	 * @var string One chunk was cut off by the wall-clock budget rather than by
	 *   anything about the failure itself.
	 *
	 * This is a distinct outcome because it needs a distinct disposition. A
	 * chunk that failed on its merits has had every attempt it was going to
	 * get; a chunk that ran out of time has not, and throwing it away would
	 * turn a slow-but-recoverable purge into a full-TTL stale page — the very
	 * failure this extension exists to prevent.
	 */
	public const CHUNK_OUT_OF_TIME = 'out-of-time';

	/**
	 * Cloudflare's purge endpoint, as a sprintf pattern over the zone ID.
	 *
	 * Behind a method rather than inline so a test can point the real request
	 * path — timeouts, retry ladder and all — at a socket it controls. See
	 * the note on request().
	 *
	 * @param string $zoneID
	 * @return string
	 */
	protected static function endpointUrl( string $zoneID ): string {
		return 'https://api.cloudflare.com/client/v4/zones/' . $zoneID . '/purge_cache';
	}

	/**
	 * Current wall-clock time. A seam: the retry ladder is a sequence of
	 * waits, and a test that had to sit through them in real time would take
	 * 45 seconds to assert one chunk's worst case.
	 *
	 * @return float
	 */
	protected static function now(): float {
		return microtime( true );
	}

	/**
	 * @param float $seconds
	 */
	protected static function sleepSeconds( float $seconds ) {
		usleep( (int)round( $seconds * 1000000 ) );
	}

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
		$budget = self::budgetForThisCall(
			(float)$config->get( 'CloudflarePurgePreSendBudgetSeconds' )
		);

		return static::sendChunks(
			$chunks, $zoneID, $headers, $retries, $logger, $budget, $set->getUrlCount()
		);
	}

	/**
	 * Send every chunk, stopping if the wall-clock budget runs out.
	 *
	 * Split out from purgeUrls() so the deadline's effect on a multi-chunk
	 * call can be tested without a MediaWiki config.
	 *
	 * @param string[][] $chunks
	 * @param string $zoneID
	 * @param string[] $headers
	 * @param int $retries
	 * @param Psr\Log\LoggerInterface $logger
	 * @param CloudflarePurgeBudget $budget
	 * @param int $totalUrls URLs across all chunks, for the log line
	 * @return bool
	 */
	protected static function sendChunks(
		array $chunks, string $zoneID, array $headers, int $retries, $logger,
		CloudflarePurgeBudget $budget, int $totalUrls
	) {
		$chunks = array_values( $chunks );
		$ok = true;
		$attempted = 0;

		foreach ( $chunks as $i => $chunk ) {
			// Two ways the budget can stop us, and both must defer rather than
			// drop. The first is a chunk that never started. The second is the
			// one that actually happens on the pre-send path, where the whole
			// call is a single chunk: the ladder for *this* chunk ran out of
			// time part-way through, so there is no "remainder" to speak of —
			// the abandoned URLs are the ones we were in the middle of.
			if ( $budget->isExhausted( static::now() ) ) {
				return static::abandonFrom(
					$chunks, $i, $totalUrls, $attempted, $budget, $logger,
					'no time left to start the request'
				);
			}

			$attempted += count( $chunk );
			$status = static::sendChunk( $chunk, $zoneID, $headers, $retries, $logger, $budget );

			if ( $status === self::CHUNK_OUT_OF_TIME ) {
				return static::abandonFrom(
					$chunks, $i, $totalUrls, $attempted, $budget, $logger,
					'time ran out mid-request'
				);
			}
			if ( $status !== self::CHUNK_OK ) {
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Hand every URL from $from onwards to the job queue and say so.
	 *
	 * Shared by both budget exits so that neither can quietly grow a different
	 * disposition from the other.
	 *
	 * @param string[][] $chunks
	 * @param int $from Index of the first chunk that was not purged
	 * @param int $totalUrls
	 * @param int $attempted
	 * @param CloudflarePurgeBudget $budget
	 * @param Psr\Log\LoggerInterface $logger
	 * @param string $reason
	 * @return false
	 */
	private static function abandonFrom(
		array $chunks, int $from, int $totalUrls, int $attempted,
		CloudflarePurgeBudget $budget, $logger, string $reason
	) {
		$unsent = array_merge( ...array_slice( $chunks, $from ) );
		// Clipping the call must not become the very thing this extension
		// exists to prevent, so what the budget did not reach is handed to the
		// job queue rather than dropped. The job runs in CLI, where the budget
		// is unlimited, so it gets the full ladder and cannot re-enter here.
		$deferred = static::deferUrls( $unsent );

		$logger->error(
			'Cloudflare purge stopped by time budget ({reason}): {unsent} of {total} URLs {disposition}',
			[
				'reason' => $reason,
				'unsent' => count( $unsent ),
				'total' => $totalUrls,
				// "queued for" rather than "queued": lazyPush() schedules the
				// enqueue as a post-send deferred update, so a true here means
				// the hand-over was accepted, not that the job reached Redis.
				// A failure after this point is logged by DeferredUpdates, not
				// by us.
				'disposition' => $deferred ? 'queued for the job queue' : 'DROPPED',
				'deferred' => $deferred,
				'attempted' => $attempted,
				'requestsDone' => $from,
				'requestsTotal' => count( $chunks ),
				'budget' => $budget->budgetSeconds(),
				'firstUrl' => $unsent[0] ?? '',
			]
		);

		return false;
	}

	/**
	 * Hand URLs back to MediaWiki's own delayed-purge job.
	 *
	 * CdnPurgeJob::run() calls CdnCacheUpdate::purge(), which broadcasts on
	 * 'cdn-url-purges' and so arrives back here — on the job runner, in CLI,
	 * with no budget. Core uses the same job for its rebound purges.
	 *
	 * lazyPush() rather than push() because this is called from the path where
	 * the response has not been flushed: the enqueue itself must not become
	 * the next thing the editor waits on.
	 *
	 * That choice bounds what the return value can mean. lazyPush() registers
	 * a post-send JobQueueEnqueueUpdate; it does not reach the queue backend
	 * (Redis here) before returning. So true means "the hand-over was
	 * accepted", not "the job is queued" — an enqueue that fails afterwards is
	 * reported by DeferredUpdates, not on this extension's log channel. Using
	 * push() would make the return value exact at the cost of putting a Redis
	 * round-trip on the pre-send path, which is the thing being fixed. The log
	 * wording says "queued for" rather than "queued" so the line does not
	 * claim more than it knows.
	 *
	 * @param string[] $urls
	 * @return bool Whether the hand-over was accepted
	 */
	protected static function deferUrls( array $urls ) {
		if ( !$urls ) {
			return true;
		}
		if ( !class_exists( CdnPurgeJob::class ) ) {
			return false;
		}
		try {
			MediaWikiServices::getInstance()->getJobQueueGroup()->lazyPush(
				new CdnPurgeJob( [ 'urls' => array_values( $urls ) ] )
			);
		} catch ( Throwable $e ) {
			// Never throw from a purge; see purgeUrls().
			return false;
		}

		return true;
	}

	/**
	 * The wall-clock budget this call gets.
	 *
	 * The budget exists for one situation: a purge running in a *pre-send*
	 * deferred update, where the editor's save is not flushed until the purge
	 * returns and no timeout in the stack will end it (PHP's
	 * max_execution_time is a CPU-time timer and does not tick during a
	 * blocking curl or usleep; $wgRequestTimeLimit only becomes a wall-clock
	 * limit when the Excimer extension is installed). Left unbounded, a
	 * Cloudflare outage stalls every concurrent save at once, each holding a
	 * worker, until the web server's read timeout returns a gateway error for
	 * a save that in fact succeeded.
	 *
	 * It must NOT apply to the job-queue path, which carries the backlink
	 * fan-out — up to $wgUpdateRowsPerQuery pages per leaf job, and many jobs
	 * for a widely transcluded template. Nobody is waiting on those, and
	 * clipping them would trade a bounded editor stall for exactly the stale
	 * pages this extension exists to prevent.
	 *
	 * Two conditions separate the two, and both are needed. Not being in CLI
	 * rules out the dedicated job runner. Headers not yet sent rules out
	 * post-send work inside a web request — $wgJobRunRate defaults to 1, so a
	 * web request may run a job after its response is flushed, and that job's
	 * purge deserves the full ladder. Core makes the same distinction the
	 * same way (DeferredUpdates::doUpdates() tests !headers_sent() to decide
	 * whether a PRESEND update can still affect the response).
	 *
	 * @param float $seconds Configured budget; 0 or less disables it
	 * @return CloudflarePurgeBudget
	 */
	private static function budgetForThisCall( float $seconds ): CloudflarePurgeBudget {
		if ( $seconds <= 0 ) {
			return CloudflarePurgeBudget::unlimited();
		}
		if ( defined( 'MW_ENTRY_POINT' ) && MW_ENTRY_POINT === 'cli' ) {
			return CloudflarePurgeBudget::unlimited();
		}
		if ( headers_sent() ) {
			return CloudflarePurgeBudget::unlimited();
		}

		return CloudflarePurgeBudget::startingAt( $seconds, static::now() );
	}

	/**
	 * @param string[] $urls At most $wgCloudflarePurgeUrlsPerRequest entries
	 * @param string $zoneID
	 * @param string[] $headers
	 * @param int $retries
	 * @param Psr\Log\LoggerInterface $logger
	 * @param CloudflarePurgeBudget $budget Wall-clock deadline for the whole call
	 * @return string One of the CHUNK_* constants. Not a bool, because "failed"
	 *   and "ran out of time" need different dispositions from the caller: the
	 *   first has had all the attempts it was going to get, the second has not.
	 */
	protected static function sendChunk(
		array $urls, string $zoneID, array $headers, int $retries, $logger,
		CloudflarePurgeBudget $budget
	) {
		$attempt = 0;
		while ( true ) {
			$now = static::now();
			// Clamp the per-attempt timeouts to what is left, so the deadline
			// is a real bound and not one checked only between attempts: an
			// unclamped attempt can overrun it by TOTAL_TIMEOUT_SECONDS.
			$result = static::request(
				$urls,
				$zoneID,
				$headers,
				$budget->clampTimeout( self::CONNECT_TIMEOUT_SECONDS, $now ),
				$budget->clampTimeout( self::TOTAL_TIMEOUT_SECONDS, $now )
			);
			if ( $result['ok'] ) {
				return self::CHUNK_OK;
			}

			$transport = $result['status'] === null
				? CloudflarePurgeRetryPolicy::transportFailureClass( $result['curlErrno'] )
				: null;
			$delay = CloudflarePurgeRetryPolicy::retryDelaySeconds(
				$attempt,
				$retries,
				$result['status'],
				$result['curlErrno'],
				$result['retryAfter'],
				$budget->isLimited()
			);

			$outOfTime = $delay !== null
				&& !$budget->permitsSleep( $delay, static::now() );

			if ( $delay === null || $outOfTime ) {
				$logger->error(
					'Cloudflare purge failed for {count} URLs: {error}',
					[
						'count' => count( $urls ),
						'error' => $result['error'],
						'httpStatus' => $result['status'],
						'curlErrno' => $result['curlErrno'],
						'transport' => $transport,
						'outOfTime' => $outOfTime,
						'retryAfter' => $result['retryAfter'],
						'attempts' => $attempt + 1,
						'firstUrl' => $urls[0] ?? '',
					]
				);

				return $outOfTime ? self::CHUNK_OUT_OF_TIME : self::CHUNK_FAILED;
			}

			$logger->warning(
				'Cloudflare purge attempt {attempt} failed, retrying in {delay}s: {error}',
				[
					'attempt' => $attempt + 1,
					'delay' => $delay,
					'error' => $result['error'],
					'httpStatus' => $result['status'],
				]
			);
			static::sleepSeconds( $delay );
			$attempt++;
		}
	}

	/**
	 * Send one chunk to Cloudflare.
	 *
	 * The timeouts are load-bearing, not defensive tidiness. cURL defaults to
	 * a 300-second connect timeout and no total timeout at all, and this code
	 * now runs on a pre-send deferred update — a blackholed api.cloudflare.com
	 * (dropped SYNs rather than a refusal) would hold the editor's save open
	 * for minutes, and wedge a job runner's whole loop. They arrive already
	 * clamped to whatever is left of the call's wall-clock budget, because
	 * bounding one attempt is not the same as bounding the ladder.
	 *
	 * Overridable so a test can drive the real ladder — real curl, real
	 * timeouts, real clock — against a socket that accepts and never answers,
	 * which is the only way to show that the deadline is what stops it.
	 *
	 * @param string[] $urls
	 * @param string $zoneID
	 * @param string[] $headers
	 * @param float $connectTimeout Seconds, already clamped to the budget
	 * @param float $totalTimeout Seconds, already clamped to the budget
	 * @return array{ok:bool,status:int|null,error:string,curlErrno:int,retryAfter:float|null}
	 *   'status' is the HTTP status, or null for a transport-level failure
	 */
	protected static function request(
		array $urls, string $zoneID, array $headers,
		float $connectTimeout, float $totalTimeout
	) {
		$retryAfter = null;

		$curl = curl_init();
		curl_setopt( $curl, CURLOPT_URL, static::endpointUrl( $zoneID ) );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $curl, CURLOPT_POST, true );
		curl_setopt( $curl, CURLOPT_POSTFIELDS, json_encode( [ 'files' => $urls ] ) );
		curl_setopt( $curl, CURLOPT_HTTPHEADER, $headers );
		// The _MS variants because a clamped timeout is routinely fractional.
		curl_setopt( $curl, CURLOPT_CONNECTTIMEOUT_MS, (int)round( $connectTimeout * 1000 ) );
		curl_setopt( $curl, CURLOPT_TIMEOUT_MS, (int)round( $totalTimeout * 1000 ) );
		curl_setopt( $curl, CURLOPT_HEADERFUNCTION,
			static function ( $handle, $header ) use ( &$retryAfter ) {
				$parts = explode( ':', $header, 2 );
				if ( count( $parts ) === 2
					&& strcasecmp( trim( $parts[0] ), 'Retry-After' ) === 0
					&& is_numeric( trim( $parts[1] ) )
				) {
					$retryAfter = (float)trim( $parts[1] );
				}
				return strlen( $header );
			}
		);

		$response = curl_exec( $curl );
		$httpStatus = (int)curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$curlErrno = curl_errno( $curl );
		$curlError = curl_error( $curl );

		if ( $response === false ) {
			return [
				'ok' => false,
				'status' => null,
				'error' => $curlError ?: 'transport failure',
				'curlErrno' => $curlErrno,
				'retryAfter' => $retryAfter,
			];
		}

		$result = json_decode( $response, true );
		if ( !is_array( $result ) || !isset( $result['success'] ) ) {
			return [
				'ok' => false,
				'status' => $httpStatus ?: null,
				'error' => 'invalid response from Cloudflare API',
				'curlErrno' => $curlErrno,
				'retryAfter' => $retryAfter,
			];
		}

		if ( $result['success'] ) {
			return [
				'ok' => true,
				'status' => $httpStatus,
				'error' => '',
				'curlErrno' => 0,
				'retryAfter' => null,
			];
		}

		$messages = [];
		foreach ( $result['errors'] ?? [] as $error ) {
			if ( isset( $error['message'] ) ) {
				$messages[] = $error['message'];
			}
		}

		return [
			'ok' => false,
			'status' => $httpStatus ?: null,
			'error' => $messages ? implode( ', ', $messages ) : 'unknown error',
			'curlErrno' => $curlErrno,
			'retryAfter' => $retryAfter,
		];
	}
}
