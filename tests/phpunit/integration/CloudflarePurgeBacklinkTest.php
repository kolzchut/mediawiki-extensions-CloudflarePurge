<?php

use MediaWiki\MainConfigNames;
use MediaWiki\Title\Title;

/**
 * An upgrade canary on the assumption this extension is built from, not a test
 * of the extension.
 *
 * CloudflarePurgeRelayer does no backlink walking of its own: it relies on
 * MediaWiki queueing HTMLCacheUpdateJob for an edited page's backlinks and
 * broadcasting the resulting URLs on the 'cdn-url-purges' channel. If a future
 * MediaWiki release stops doing that — or narrows what it broadcasts — this
 * extension quietly stops purging anything but the edited page, with no error
 * anywhere.
 *
 * These tests therefore assert *core's* behaviour, through a capturing relayer
 * subscribed to the same channel. They deliberately do not exercise
 * CloudflarePurgeRelayer or CloudflarePurge: reaching those would mean issuing
 * a real purge against a real Cloudflare zone. The extension's own logic is
 * covered by the unit tests (CloudflarePurgeUrlSet, CloudflarePurgeRetryPolicy,
 * CloudflarePurge::onRegistration).
 *
 * @group Database
 * @coversNothing
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

	public function testEditingATemplateBroadcastsThePagesThatTranscludeIt() {
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

	public function testEditingAPageWithNoBacklinksBroadcastsOnlyItself() {
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
}
