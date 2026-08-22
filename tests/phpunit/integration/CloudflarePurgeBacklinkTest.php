<?php

use MediaWiki\MainConfigNames;
use MediaWiki\Title\Title;

/**
 * The behaviour this extension exists for: an edit must invalidate the pages
 * that *show* the edited content, not just the page that was edited.
 *
 * @group Database
 * @covers CloudflarePurgeRelayer
 * @covers CloudflarePurge
 */
class CloudflarePurgeBacklinkTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		CloudflarePurgeCapturingRelayer::$urls = [];
		$this->overrideConfigValues( [
			MainConfigNames::EventRelayerConfig => [
				'default' => [ 'class' => \Wikimedia\EventRelayer\EventRelayerNull::class ],
				'cdn-url-purges' => [ 'class' => CloudflarePurgeCapturingRelayer::class ],
			],
			MainConfigNames::UseCdn => true,
		] );
	}

	private function purgedUrls(): array {
		return array_values( array_unique( CloudflarePurgeCapturingRelayer::$urls ) );
	}

	private function urlFor( string $titleText ): string {
		return Title::newFromText( $titleText )->getInternalURL();
	}

	public function testEditingATemplatePurgesThePagesThatTranscludeIt() {
		$this->editPage( 'Template:BenefitAmount', '1,234 NIS' );
		$this->editPage( 'Unemployment benefit', 'Amount: {{BenefitAmount}}' );
		$this->editPage( 'Disability benefit', 'Amount: {{BenefitAmount}}' );
		$this->runJobs();

		CloudflarePurgeCapturingRelayer::$urls = [];
		$this->editPage( 'Template:BenefitAmount', '5,678 NIS' );
		$this->runJobs();

		$purged = $this->purgedUrls();
		$this->assertContains( $this->urlFor( 'Unemployment benefit' ), $purged );
		$this->assertContains( $this->urlFor( 'Disability benefit' ), $purged );
		$this->assertContains( $this->urlFor( 'Template:BenefitAmount' ), $purged );
	}

	public function testEditingAPageWithNoBacklinksPurgesOnlyItself() {
		$this->editPage( 'Template:BenefitAmount', '1,234 NIS' );
		$this->editPage( 'Unemployment benefit', 'Amount: {{BenefitAmount}}' );
		$this->editPage( 'Standalone page', 'Nothing transcludes this.' );
		$this->runJobs();

		CloudflarePurgeCapturingRelayer::$urls = [];
		$this->editPage( 'Standalone page', 'Nothing transcludes this. Still.' );
		$this->runJobs();

		$purged = $this->purgedUrls();
		$this->assertContains( $this->urlFor( 'Standalone page' ), $purged );
		$this->assertNotContains( $this->urlFor( 'Unemployment benefit' ), $purged );
		$this->assertNotContains( $this->urlFor( 'Template:BenefitAmount' ), $purged );
	}

	/**
	 * The relayer is what turns that broadcast into a Cloudflare call, so the
	 * extension must actually claim the channel when it loads.
	 */
	public function testExtensionClaimsTheCdnPurgeChannelByDefault() {
		$globals = $GLOBALS;
		unset( $GLOBALS['wgEventRelayerConfig'], $GLOBALS['wgCloudflarePurgeUseCdnRelay'] );
		try {
			CloudflarePurge::onRegistration();
			$this->assertSame(
				CloudflarePurgeRelayer::class,
				$GLOBALS['wgEventRelayerConfig']['cdn-url-purges']['class']
			);
		} finally {
			$GLOBALS['wgEventRelayerConfig'] = $globals['wgEventRelayerConfig'] ?? null;
		}
	}
}
