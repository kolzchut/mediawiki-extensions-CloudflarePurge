<?php

use PHPUnit\Framework\TestCase;

/**
 * A configured zone with no usable credential must not report success.
 *
 * The token is read from the environment as `getenv( ... ) ?: ''`, so a token
 * that lapsed, was rotated away or was never rendered lands a running wiki in
 * exactly that state. Until kolzchut/kz-infrastructure#1076 purgeUrls()
 * returned true there without logging anything: every edit saved, the
 * extension reported success, nothing was purged, and readers saw stale pages
 * for a full TTL with no signal anywhere.
 *
 * The negative cases matter as much as the positive one. A fully configured
 * wiki must not see the line, and neither must a wiki with no zone at all —
 * that is an unconfigured install, where silence is correct.
 *
 * @covers CloudflarePurge
 */
class CloudflarePurgeCredentialTest extends TestCase {

	/** @var string Must match CloudflarePurge::NO_CREDENTIAL_MESSAGE */
	private const MESSAGE =
		'Cloudflare purge not sent: a zone is configured but no usable credential is ({reason}); ' .
		'{count} URL(s) will stay stale until their TTL expires';

	private const URLS = [
		'https://www.example.org/wiki/A',
		'https://www.example.org/wiki/B',
	];

	private const TOKEN = 'tok-SECRET-0123456789abcdef';
	private const EMAIL = 'ops-SECRET@example.org';
	private const KEY = 'key-SECRET-fedcba9876543210';

	protected function setUp(): void {
		parent::setUp();
		CloudflarePurgeConfigProbe::reset();
	}

	/**
	 * @return array[]
	 */
	public static function provideZoneWithoutUsableCredential() {
		return [
			'nothing set' => [ [], 'no-token' ],
			'token is blank' => [ [ 'CloudflarePurgeToken' => '   ' ], 'no-token' ],
			'legacy email without key' => [
				[ 'CloudflarePurgeAuthEmail' => self::EMAIL ], 'partial-legacy-credential'
			],
			'legacy key without email' => [
				[ 'CloudflarePurgeAuthKey' => self::KEY ], 'partial-legacy-credential'
			],
		];
	}

	/**
	 * @dataProvider provideZoneWithoutUsableCredential
	 * @param array $credential
	 * @param string $reason
	 */
	public function testZoneWithoutUsableCredentialFailsLoudly( array $credential, string $reason ) {
		$logger = new CloudflarePurgeArrayLogger();

		$ok = CloudflarePurgeConfigProbe::run(
			self::URLS, [ 'CloudflarePurgeZoneID' => 'zone-123' ] + $credential, $logger
		);

		$this->assertFalse( $ok, 'a configured zone that cannot be authenticated is a failure' );
		$this->assertSame( [], CloudflarePurgeConfigProbe::$requests,
			'no request can be sent without a credential' );
		$this->assertSame( [ [ 'error', self::MESSAGE, [
			'reason' => $reason,
			'count' => 2,
			'firstUrl' => self::URLS[0],
		] ] ], $logger->lines );
		$this->assertNoCredentialLogged( $logger );
	}

	public function testFullyConfiguredTokenPurgesWithoutTheLine() {
		$logger = new CloudflarePurgeArrayLogger();

		$ok = CloudflarePurgeConfigProbe::run( self::URLS, [
			'CloudflarePurgeZoneID' => 'zone-123',
			'CloudflarePurgeToken' => self::TOKEN,
		], $logger );

		$this->assertTrue( $ok );
		// The request really went out, with the token — so the absence of the
		// line below is not just the absence of a call.
		$this->assertCount( 1, CloudflarePurgeConfigProbe::$requests );
		[ $zone, $headers, $urls ] = CloudflarePurgeConfigProbe::$requests[0];
		$this->assertSame( 'zone-123', $zone );
		$this->assertContains( 'Authorization: Bearer ' . self::TOKEN, $headers );
		$this->assertSame( self::URLS, $urls );
		$this->assertNoCredentialLine( $logger );
		$this->assertSame( [], $logger->contextsAt( 'error' ) );
		$this->assertNoCredentialLogged( $logger );
	}

	public function testFullyConfiguredLegacyPairPurgesWithoutTheLine() {
		$logger = new CloudflarePurgeArrayLogger();

		$ok = CloudflarePurgeConfigProbe::run( self::URLS, [
			'CloudflarePurgeZoneID' => 'zone-123',
			'CloudflarePurgeAuthEmail' => self::EMAIL,
			'CloudflarePurgeAuthKey' => self::KEY,
		], $logger );

		$this->assertTrue( $ok );
		$this->assertCount( 1, CloudflarePurgeConfigProbe::$requests );
		$headers = CloudflarePurgeConfigProbe::$requests[0][1];
		$this->assertContains( 'X-Auth-Email: ' . self::EMAIL, $headers );
		$this->assertContains( 'X-Auth-Key: ' . self::KEY, $headers );
		$this->assertNoCredentialLine( $logger );
		$this->assertSame( [], $logger->contextsAt( 'error' ) );
		$this->assertNoCredentialLogged( $logger );
	}

	public function testNoZoneIsASilentNoOpEvenWithoutACredential() {
		$logger = new CloudflarePurgeArrayLogger();

		$ok = CloudflarePurgeConfigProbe::run( self::URLS, [], $logger );

		$this->assertTrue( $ok, 'no zone means no CDN: nothing to do, not a failure' );
		$this->assertSame( [], CloudflarePurgeConfigProbe::$requests );
		$this->assertSame( [], $logger->lines,
			'an unconfigured install must stay silent, or every non-Cloudflare wiki logs per purge' );
	}

	/**
	 * @param CloudflarePurgeArrayLogger $logger
	 */
	private function assertNoCredentialLine( CloudflarePurgeArrayLogger $logger ) {
		foreach ( $logger->lines as [ , $message ] ) {
			$this->assertNotSame( self::MESSAGE, $message );
		}
	}

	/**
	 * Messages and contexts alike: a credential must never reach a log line.
	 *
	 * @param CloudflarePurgeArrayLogger $logger
	 */
	private function assertNoCredentialLogged( CloudflarePurgeArrayLogger $logger ) {
		$dump = json_encode( $logger->lines );
		foreach ( [ self::TOKEN, self::EMAIL, self::KEY ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $dump );
		}
		$this->assertStringNotContainsString( 'SECRET', $dump );
	}
}
