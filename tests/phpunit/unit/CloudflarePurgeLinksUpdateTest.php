<?php

use PHPUnit\Framework\TestCase;

/**
 * Which LinksUpdates queue a purge from onLinksUpdateComplete().
 *
 * The handler exists because core's leaf HTMLCacheUpdateJob skips a page
 * that Semantic MediaWiki touched first, leaving a transcluding page stale at
 * the edge for a full TTL (kolzchut/kz-infrastructure#615). Each guard in it
 * is there for a reason of its own, so each has a test that fails when only
 * that guard is removed. Verified by mutation, one guard at a time:
 * - dropping the isRecursive() check reddens only
 *   testRecursiveUpdateQueuesNothing;
 * - dropping the cause check reddens only
 *   testRefreshLinksMaintenanceQueuesNothing;
 * - widening the skip list to every cause, or matching it loosely, reddens
 *   testOtherCausesStillQueue;
 * - dropping the relay check reddens only testRelayOffQueuesNothing;
 * - dropping the empty-URL check reddens only testNoUrlsQueuesNothing;
 * - queueing twice, or a subset of the URLs, reddens
 *   testJobDrivenUpdateQueuesOnePurgeWithThePageUrls;
 * - removing the hook from extension.json reddens
 *   testHandlerIsRegistered.
 *
 * @covers CloudflarePurge::onLinksUpdateComplete
 */
class CloudflarePurgeLinksUpdateTest extends TestCase {

	private const URLS = [
		'https://www.example.org/wiki/Unemployment_benefit',
		'https://kiosk.example.org/wiki/Unemployment_benefit',
	];

	protected function setUp(): void {
		parent::setUp();
		CloudflarePurgeLinksUpdateProbe::reset();
		CloudflarePurgeLinksUpdateProbe::$urls = self::URLS;
	}

	/**
	 * A stand-in for MediaWiki's LinksUpdate: the handler reads only these
	 * three methods.
	 *
	 * @param bool $recursive
	 * @param string $cause
	 * @return mixed An anonymous stand-in, not a LinksUpdate
	 */
	private function linksUpdate( bool $recursive, string $cause = 'edit-page' ) {
		return new class( $recursive, $cause ) {
			/** @var bool */
			private $recursive;

			/** @var string */
			private $cause;

			/** @var stdClass */
			public $title;

			/**
			 * @param bool $recursive
			 * @param string $cause
			 */
			public function __construct( bool $recursive, string $cause ) {
				$this->recursive = $recursive;
				$this->cause = $cause;
				$this->title = new stdClass();
			}

			public function isRecursive() {
				return $this->recursive;
			}

			public function getCauseAction() {
				return $this->cause;
			}

			public function getTitle() {
				return $this->title;
			}
		};
	}

	/**
	 * The case the handler exists for: a refreshLinks job re-rendered a page
	 * after a template edit. Exactly one purge, with exactly the page's URLs,
	 * looked up for exactly that page.
	 */
	public function testJobDrivenUpdateQueuesOnePurgeWithThePageUrls() {
		$update = $this->linksUpdate( false );

		CloudflarePurgeLinksUpdateProbe::onLinksUpdateComplete( $update, null );

		$this->assertSame( [ self::URLS ], CloudflarePurgeLinksUpdateProbe::$queued );
		$this->assertSame( [ $update->title ], CloudflarePurgeLinksUpdateProbe::$urlLookups );
	}

	/**
	 * A direct edit runs a recursive LinksUpdate, and core purges the edited
	 * page itself. Queueing here would only duplicate that purge on every
	 * save.
	 */
	public function testRecursiveUpdateQueuesNothing() {
		CloudflarePurgeLinksUpdateProbe::onLinksUpdateComplete( $this->linksUpdate( true ), null );

		$this->assertSame( [], CloudflarePurgeLinksUpdateProbe::$queued );
	}

	/**
	 * maintenance/refreshLinks.php runs one non-recursive update per page.
	 * A whole-wiki run would otherwise be one Cloudflare request per page,
	 * enough to trip the API's rate limit and lose editors' purges with it.
	 */
	public function testRefreshLinksMaintenanceQueuesNothing() {
		CloudflarePurgeLinksUpdateProbe::onLinksUpdateComplete(
			$this->linksUpdate( false, 'refresh-links-maintenance' ), null
		);

		$this->assertSame( [], CloudflarePurgeLinksUpdateProbe::$queued );
	}

	/**
	 * @return array[]
	 */
	public static function provideKeptCauses() {
		return [
			// A refreshLinks job carries its root's cause: a template edit.
			'template edit' => [ 'edit-page' ],
			// The cause a refreshLinks job falls back to with none set.
			'unknown' => [ 'unknown' ],
			'action=purge with forcelinkupdate' => [ 'api-purge' ],
			// Close to the skipped cause, so a prefix or substring match
			// would catch it.
			'similar name' => [ 'refresh-links' ],
		];
	}

	/**
	 * The skip list is for the bulk maintenance cause only. Every per-page
	 * cause must still be purged, or the gap this handler closes reopens.
	 *
	 * @dataProvider provideKeptCauses
	 * @param string $cause
	 */
	public function testOtherCausesStillQueue( string $cause ) {
		CloudflarePurgeLinksUpdateProbe::onLinksUpdateComplete(
			$this->linksUpdate( false, $cause ), null
		);

		$this->assertSame( [ self::URLS ], CloudflarePurgeLinksUpdateProbe::$queued );
	}

	/**
	 * With $wgCloudflarePurgeUseCdnRelay off, or the channel owned by
	 * someone else, the extension purges only edited pages. A purge queued
	 * here would reach whatever owns the channel instead.
	 */
	public function testRelayOffQueuesNothing() {
		CloudflarePurgeLinksUpdateProbe::$relay = false;

		CloudflarePurgeLinksUpdateProbe::onLinksUpdateComplete( $this->linksUpdate( false ), null );

		$this->assertSame( [], CloudflarePurgeLinksUpdateProbe::$queued );
	}

	/**
	 * HtmlCacheUpdater answers no URLs for a page that cannot exist. An empty
	 * job would cost a job run and purge nothing.
	 */
	public function testNoUrlsQueuesNothing() {
		CloudflarePurgeLinksUpdateProbe::$urls = [];

		CloudflarePurgeLinksUpdateProbe::onLinksUpdateComplete( $this->linksUpdate( false ), null );

		$this->assertSame( [], CloudflarePurgeLinksUpdateProbe::$queued );
	}

	/**
	 * The handler is only as good as its registration: without it, every
	 * test above passes and nothing is ever purged.
	 */
	public function testHandlerIsRegistered() {
		$json = json_decode( file_get_contents( dirname( __DIR__, 3 ) . '/extension.json' ), true );

		$this->assertSame(
			'CloudflarePurge::onLinksUpdateComplete',
			$json['Hooks']['LinksUpdateComplete'] ?? null
		);
		$this->assertTrue( is_callable( [ CloudflarePurge::class, 'onLinksUpdateComplete' ] ) );
	}
}
