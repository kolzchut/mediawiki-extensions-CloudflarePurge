<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers CloudflarePurgeUrlSet
 */
class CloudflarePurgeUrlSetTest extends TestCase {

	/**
	 * A page with nothing pointing at it purges exactly itself.
	 */
	public function testSingleUrlProducesOneRequest() {
		$set = CloudflarePurgeUrlSet::fromUrls( [ 'https://example.org/wiki/Lonely' ] );

		$this->assertSame( 1, $set->getUrlCount() );
		$this->assertSame( 0, $set->getDroppedCount() );
		$this->assertSame(
			[ [ 'https://example.org/wiki/Lonely' ] ],
			$set->getChunks()
		);
	}

	/**
	 * The URLs MediaWiki hands us for a template's backlinks all survive, and
	 * they are split into requests Cloudflare will accept.
	 */
	public function testBacklinkUrlsAreChunkedAtTheApiLimit() {
		$urls = [];
		for ( $i = 0; $i < 250; $i++ ) {
			$urls[] = "https://example.org/wiki/Page$i";
		}

		$set = CloudflarePurgeUrlSet::fromUrls( $urls );

		$this->assertSame( 250, $set->getUrlCount() );
		$this->assertSame( 0, $set->getDroppedCount() );

		$chunks = $set->getChunks();
		$this->assertCount( 3, $chunks );
		$this->assertCount( 100, $chunks[0] );
		$this->assertCount( 100, $chunks[1] );
		$this->assertCount( 50, $chunks[2] );
		$this->assertSame( $urls, array_merge( ...$chunks ) );
	}

	/**
	 * The cap bounds the fan-out of a single purge call, and reports what it
	 * discarded rather than dropping it silently.
	 */
	public function testCapIsHonoured() {
		$urls = [];
		for ( $i = 0; $i < 1500; $i++ ) {
			$urls[] = "https://example.org/wiki/Page$i";
		}

		$set = CloudflarePurgeUrlSet::fromUrls( $urls, [], 1000, 100 );

		$this->assertSame( 1000, $set->getUrlCount() );
		$this->assertSame( 500, $set->getDroppedCount() );
		$this->assertCount( 10, $set->getChunks() );
	}

	public function testCapCanBeDisabled() {
		$urls = [];
		for ( $i = 0; $i < 120; $i++ ) {
			$urls[] = "https://example.org/wiki/Page$i";
		}

		$set = CloudflarePurgeUrlSet::fromUrls( $urls, [], 0, 100 );

		$this->assertSame( 120, $set->getUrlCount() );
		$this->assertSame( 0, $set->getDroppedCount() );
	}

	/**
	 * Extra hosts multiply the fan-out, so they must be counted against the
	 * cap rather than added after it.
	 */
	public function testExtraHostsCountTowardsTheCap() {
		$urls = [];
		for ( $i = 0; $i < 400; $i++ ) {
			$urls[] = "https://example.org/wiki/Page$i";
		}

		$set = CloudflarePurgeUrlSet::fromUrls( $urls, [ 'kiosk.example.org' ], 500, 100 );

		$this->assertSame( 500, $set->getUrlCount() );
		$this->assertSame( 300, $set->getDroppedCount() );
	}

	public function testExtraHostsPreservePathAndQuery() {
		$set = CloudflarePurgeUrlSet::fromUrls(
			[ 'https://example.org/wiki/Page?action=history' ],
			[ 'kiosk.example.org' ]
		);

		$this->assertSame(
			[ [
				'https://example.org/wiki/Page?action=history',
				'https://kiosk.example.org/wiki/Page?action=history',
			] ],
			$set->getChunks()
		);
	}

	/**
	 * Core de-duplicates its own list, but extra-host expansion and several
	 * purge sources in one request can still collide, and every duplicate
	 * spends quota.
	 */
	public function testDuplicatesAreCollapsed() {
		$set = CloudflarePurgeUrlSet::fromUrls( [
			'https://example.org/wiki/A',
			'https://example.org/wiki/A',
			'https://example.org/wiki/B',
		] );

		$this->assertSame( 2, $set->getUrlCount() );
	}

	/**
	 * A relative or empty URL would make Cloudflare reject the whole request,
	 * taking the valid URLs in the same chunk down with it.
	 */
	public function testUnusableUrlsAreDiscarded() {
		$set = CloudflarePurgeUrlSet::fromUrls( [
			'https://example.org/wiki/Good',
			'/wiki/Relative',
			'',
			'   ',
			'not a url',
		] );

		$this->assertSame( [ [ 'https://example.org/wiki/Good' ] ], $set->getChunks() );
	}

	public function testEmptyInputSendsNothing() {
		$set = CloudflarePurgeUrlSet::fromUrls( [] );

		$this->assertTrue( $set->isEmpty() );
		$this->assertSame( [], $set->getChunks() );
	}
}
