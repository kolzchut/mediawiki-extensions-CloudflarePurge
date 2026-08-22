<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers CloudflarePurge::onRegistration
 */
class CloudflarePurgeRegistrationTest extends TestCase {

	/** @var bool */
	private $savedRelayersSet;

	/** @var array|null */
	private $savedRelayers;

	/** @var bool */
	private $savedFlagSet;

	/** @var mixed */
	private $savedFlag;

	protected function setUp(): void {
		parent::setUp();
		$this->savedRelayersSet = array_key_exists( 'wgEventRelayerConfig', $GLOBALS );
		$this->savedRelayers = $GLOBALS['wgEventRelayerConfig'] ?? null;
		$this->savedFlagSet = array_key_exists( 'wgCloudflarePurgeUseCdnRelay', $GLOBALS );
		$this->savedFlag = $GLOBALS['wgCloudflarePurgeUseCdnRelay'] ?? null;
		unset( $GLOBALS['wgEventRelayerConfig'], $GLOBALS['wgCloudflarePurgeUseCdnRelay'] );
	}

	protected function tearDown(): void {
		if ( $this->savedRelayersSet ) {
			$GLOBALS['wgEventRelayerConfig'] = $this->savedRelayers;
		} else {
			// Restore "absent", not "present and null" — a global set to null
			// is not the same state a wiki without the setting is in.
			unset( $GLOBALS['wgEventRelayerConfig'] );
		}
		if ( $this->savedFlagSet ) {
			$GLOBALS['wgCloudflarePurgeUseCdnRelay'] = $this->savedFlag;
		} else {
			unset( $GLOBALS['wgCloudflarePurgeUseCdnRelay'] );
		}
		parent::tearDown();
	}

	/**
	 * Enabling the extension is enough: it subscribes itself to the channel
	 * MediaWiki broadcasts every CDN purge on, which is what makes backlink
	 * purging work at all.
	 */
	public function testSubscribesToTheCdnPurgeChannel() {
		CloudflarePurge::onRegistration();

		$this->assertSame(
			'CloudflarePurgeRelayer',
			$GLOBALS['wgEventRelayerConfig']['cdn-url-purges']['class']
		);
	}

	public function testLeavesOtherChannelsAlone() {
		$GLOBALS['wgEventRelayerConfig'] = [
			'default' => [ 'class' => 'SomeOtherRelayer' ],
		];

		CloudflarePurge::onRegistration();

		$this->assertSame(
			'SomeOtherRelayer',
			$GLOBALS['wgEventRelayerConfig']['default']['class']
		);
		$this->assertSame(
			'CloudflarePurgeRelayer',
			$GLOBALS['wgEventRelayerConfig']['cdn-url-purges']['class']
		);
	}

	/**
	 * A wiki that already routes CDN purges somewhere (Kafka, a local Varnish
	 * relay) keeps its own wiring.
	 */
	public function testDoesNotStealAnAlreadyConfiguredChannel() {
		$GLOBALS['wgEventRelayerConfig'] = [
			'cdn-url-purges' => [ 'class' => 'KafkaRelayer' ],
		];

		CloudflarePurge::onRegistration();

		$this->assertSame(
			'KafkaRelayer',
			$GLOBALS['wgEventRelayerConfig']['cdn-url-purges']['class']
		);
	}

	public function testCanBeOptedOut() {
		$GLOBALS['wgCloudflarePurgeUseCdnRelay'] = false;

		CloudflarePurge::onRegistration();

		$this->assertArrayNotHasKey(
			'cdn-url-purges',
			$GLOBALS['wgEventRelayerConfig'] ?? []
		);
	}
}
