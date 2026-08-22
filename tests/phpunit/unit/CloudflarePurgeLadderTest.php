<?php

use PHPUnit\Framework\TestCase;

/**
 * The retry ladder's wall-clock cost, and what the pre-send budget does to it.
 *
 * These are the control for CloudflarePurgeBudget: they run the same ladder
 * twice, once as it behaved before the budget existed and once with it, and
 * measure the difference. Asserting that a constant is 5 would not have caught
 * anything — the constant was never the question. The question was whether the
 * ladder is bounded at all.
 *
 * @covers CloudflarePurge::sendChunk
 * @covers CloudflarePurgeBudget
 */
class CloudflarePurgeLadderTest extends TestCase {

	/** @var CloudflarePurgeArrayLogger */
	private $logger;

	protected function setUp(): void {
		parent::setUp();
		CloudflarePurgeLadderProbe::reset();
		$this->logger = new CloudflarePurgeArrayLogger();
	}

	/**
	 * The old behaviour, and the reason this issue exists.
	 *
	 * An unbounded ladder against an API that accepts the connection and then
	 * stops answering costs three full 15-second request timeouts plus the
	 * backoff between them. That is 45.75 seconds of a *pre-send* deferred
	 * update: the editor's save is not flushed until it returns, PHP's
	 * max_execution_time is a CPU-time timer and does not tick through it, and
	 * php-fpm here sets no request_terminate_timeout. Nothing ends it.
	 */
	public function testAnUnboundedLadderRunsForThreeQuartersOfAMinute() {
		CloudflarePurgeLadderProbe::$cost = 'total';

		$status = CloudflarePurgeLadderProbe::runChunk(
			[ 'https://example.org/A' ], 2, $this->logger,
			CloudflarePurgeBudget::unlimited()
		);

		$this->assertSame( CloudflarePurge::CHUNK_FAILED, $status );
		$this->assertSame( 3, CloudflarePurgeLadderProbe::attempts() );
		$this->assertEqualsWithDelta( 45.75, CloudflarePurgeLadderProbe::$clock, 1e-9 );
		// Every attempt was handed the full per-request timeouts.
		$this->assertSame(
			[ [ 5.0, 15.0 ], [ 5.0, 15.0 ], [ 5.0, 15.0 ] ],
			CloudflarePurgeLadderProbe::$granted
		);
	}

	/**
	 * The blackholed-API case, for cross-reference: dropped SYNs cost the
	 * connect timeout rather than the total one, so the same ladder is 15.75s.
	 * Still three times the default pre-send budget.
	 */
	public function testAnUnboundedLadderAgainstABlackholeCostsFifteenSeconds() {
		CloudflarePurgeLadderProbe::$cost = 'connect';

		CloudflarePurgeLadderProbe::runChunk(
			[ 'https://example.org/A' ], 2, $this->logger,
			CloudflarePurgeBudget::unlimited()
		);

		$this->assertEqualsWithDelta( 15.75, CloudflarePurgeLadderProbe::$clock, 1e-9 );
	}

	/**
	 * The same ladder, given the default 5-second pre-send budget: it stops on
	 * the deadline, not past it, and says so.
	 */
	public function testTheBudgetStopsTheLadderOnItsDeadline() {
		CloudflarePurgeLadderProbe::$cost = 'total';

		$status = CloudflarePurgeLadderProbe::runChunk(
			[ 'https://example.org/A' ], 2, $this->logger,
			CloudflarePurgeBudget::startingAt( 5.0, 0.0 )
		);

		// Not merely "failed" — the caller has to be able to tell that time,
		// rather than the failure itself, is what ended this.
		$this->assertSame( CloudflarePurge::CHUNK_OUT_OF_TIME, $status );
		$this->assertLessThanOrEqual( 5.0, CloudflarePurgeLadderProbe::$clock );
		// The first attempt alone would have overrun the budget threefold had
		// its timeout not been clamped to what the budget had left.
		$this->assertSame( [ [ 5.0, 5.0 ] ], CloudflarePurgeLadderProbe::$granted );

		$errors = $this->logger->contextsAt( 'error' );
		$this->assertCount( 1, $errors );
		$this->assertTrue( $errors[0]['outOfTime'] );
	}

	/**
	 * Clamping has to happen on *every* attempt, not just the first: the
	 * ladder is only bounded if the last attempt is cut to the remainder.
	 *
	 * With a 12-second budget against a blackhole the ladder gets all three
	 * attempts, and the third is handed 1.25s rather than 5s — the exact
	 * amount left after two attempts and two backoffs.
	 */
	public function testEveryAttemptIsClampedToTheRemainder() {
		CloudflarePurgeLadderProbe::$cost = 'connect';

		CloudflarePurgeLadderProbe::runChunk(
			[ 'https://example.org/A' ], 2, $this->logger,
			CloudflarePurgeBudget::startingAt( 12.0, 0.0 )
		);

		$this->assertSame( 3, CloudflarePurgeLadderProbe::attempts() );
		$this->assertEqualsWithDelta( 12.0, CloudflarePurgeLadderProbe::$clock, 1e-9 );

		$granted = CloudflarePurgeLadderProbe::$granted;
		$this->assertEqualsWithDelta( 5.0, $granted[0][0], 1e-9 );
		$this->assertEqualsWithDelta( 5.0, $granted[1][0], 1e-9 );
		$this->assertEqualsWithDelta( 1.25, $granted[2][0], 1e-9 );
		$this->assertEqualsWithDelta( 1.25, $granted[2][1], 1e-9 );
	}

	/**
	 * A budget does not turn a working purge into a failing one.
	 */
	public function testAFastFailureStillGetsItsRetriesInsideTheBudget() {
		// 7 = CURLE_COULDNT_CONNECT: a refusal costs no time at all.
		CloudflarePurgeLadderProbe::$cost = 'none';
		CloudflarePurgeLadderProbe::$curlErrno = 7;

		CloudflarePurgeLadderProbe::runChunk(
			[ 'https://example.org/A' ], 2, $this->logger,
			CloudflarePurgeBudget::startingAt( 5.0, 0.0 )
		);

		// Three attempts, and the budget was never what stopped it: the
		// 0.75s of backoff is all it cost.
		$this->assertSame( 3, CloudflarePurgeLadderProbe::attempts() );
		$this->assertEqualsWithDelta( 0.75, CloudflarePurgeLadderProbe::$clock, 1e-9 );
		$errors = $this->logger->contextsAt( 'error' );
		$this->assertFalse( $errors[0]['outOfTime'] );
	}

	/**
	 * A DNS blip: before this change the ladder made exactly one attempt and
	 * dropped the purge. See CloudflarePurgeRetryPolicyTest for the
	 * classification itself; this is the ladder acting on it.
	 *
	 * On the job path — no budget, nobody waiting, and the backlink fan-out to
	 * protect — it gets the full configured ladder.
	 */
	public function testAResolverFailureGetsTheFullLadderWhenNothingIsWaiting() {
		CloudflarePurgeLadderProbe::$cost = 'none';
		// 6 = CURLE_COULDNT_RESOLVE_HOST
		CloudflarePurgeLadderProbe::$curlErrno = 6;

		CloudflarePurgeLadderProbe::runChunk(
			[ 'https://example.org/A' ], 2, $this->logger,
			CloudflarePurgeBudget::unlimited()
		);

		$this->assertSame( 3, CloudflarePurgeLadderProbe::attempts() );
		$errors = $this->logger->contextsAt( 'error' );
		$this->assertSame( 'fast-retry', $errors[0]['transport'] );
	}

	/**
	 * ...and one retry, not three, where an editor is waiting for the save.
	 */
	public function testAResolverFailureIsCappedAtOneRetryUnderABudget() {
		CloudflarePurgeLadderProbe::$cost = 'none';
		CloudflarePurgeLadderProbe::$curlErrno = 6;

		CloudflarePurgeLadderProbe::runChunk(
			[ 'https://example.org/A' ], 2, $this->logger,
			CloudflarePurgeBudget::startingAt( 5.0, 0.0 )
		);

		$this->assertSame( 2, CloudflarePurgeLadderProbe::attempts() );
		// The budget was nowhere near spent — the cap is what stopped it.
		$this->assertEqualsWithDelta( 0.25, CloudflarePurgeLadderProbe::$clock, 1e-9 );
		$errors = $this->logger->contextsAt( 'error' );
		$this->assertFalse( $errors[0]['outOfTime'] );
	}

	/**
	 * A rejected certificate still gets one attempt and no more: it will fail
	 * identically every time, and this ladder runs where an editor is waiting.
	 */
	public function testAPermanentTransportFailureStillStopsAtOneAttempt() {
		CloudflarePurgeLadderProbe::$cost = 'connect';
		// 60 = CURLE_SSL_CACERT
		CloudflarePurgeLadderProbe::$curlErrno = 60;

		CloudflarePurgeLadderProbe::runChunk(
			[ 'https://example.org/A' ], 2, $this->logger,
			CloudflarePurgeBudget::unlimited()
		);

		$this->assertSame( 1, CloudflarePurgeLadderProbe::attempts() );
		$errors = $this->logger->contextsAt( 'error' );
		$this->assertSame( 'permanent', $errors[0]['transport'] );
	}

	/**
	 * The case that actually occurs.
	 *
	 * A pre-send purge is a *single chunk* — the edited page's URL plus
	 * action=history, times one plus the extra hosts. So the budget almost
	 * never stops the call "between chunks"; it stops it in the middle of the
	 * only chunk there is. If the deferral covered only chunks that had not
	 * started, it would never fire on the path it was written for, and every
	 * clipped pre-send purge would be a silent full-TTL stale page — the exact
	 * failure this extension exists to prevent, newly introduced by the budget
	 * meant to protect the editor.
	 */
	public function testASingleChunkClippedMidLadderIsDeferred() {
		CloudflarePurgeLadderProbe::$cost = 'total';
		$urls = [
			'https://example.org/A',
			'https://example.org/A?action=history',
			'https://kiosk.example.org/A',
			'https://kiosk.example.org/A?action=history',
		];

		$ok = CloudflarePurgeLadderProbe::runChunks(
			[ $urls ], 2, $this->logger, CloudflarePurgeBudget::startingAt( 5.0, 0.0 )
		);

		$this->assertFalse( $ok );
		$this->assertSame( $urls, CloudflarePurgeLadderProbe::$deferred );

		$errors = $this->logger->contextsAt( 'error' );
		$budgetLine = end( $errors );
		$this->assertSame( 4, $budgetLine['unsent'] );
		$this->assertSame( 4, $budgetLine['total'] );
		$this->assertSame( 'time ran out mid-request', $budgetLine['reason'] );
		$this->assertTrue( $budgetLine['deferred'] );
		$this->assertSame( 5.0, $budgetLine['budget'] );
	}

	/**
	 * With several chunks, the deferral covers the one the budget cut off *and*
	 * everything behind it — not just the ones that never started.
	 */
	public function testTheClippedChunkIsDeferredAlongWithTheRemainder() {
		CloudflarePurgeLadderProbe::$cost = 'total';
		$chunks = [
			[ 'https://example.org/A', 'https://example.org/B' ],
			[ 'https://example.org/C' ],
			[ 'https://example.org/D' ],
		];

		$ok = CloudflarePurgeLadderProbe::runChunks(
			$chunks, 2, $this->logger, CloudflarePurgeBudget::startingAt( 5.0, 0.0 )
		);

		$this->assertFalse( $ok );
		// A and B are the chunk the budget cut off. They belong here just as
		// much as C and D do.
		$this->assertSame(
			[
				'https://example.org/A',
				'https://example.org/B',
				'https://example.org/C',
				'https://example.org/D',
			],
			CloudflarePurgeLadderProbe::$deferred
		);

		$errors = $this->logger->contextsAt( 'error' );
		$budgetLine = end( $errors );
		$this->assertSame( 4, $budgetLine['unsent'] );
		$this->assertSame( 4, $budgetLine['total'] );
		$this->assertTrue( $budgetLine['deferred'] );
		$this->assertSame( 'queued for the job queue', $budgetLine['disposition'] );
	}

	/**
	 * A chunk that never started is deferred too, by the same path.
	 */
	public function testAChunkThatNeverStartedIsDeferred() {
		// The first chunk succeeds, but takes the whole budget doing it, so
		// the second is refused before its first request rather than during it.
		CloudflarePurgeLadderProbe::$cost = 'total';
		CloudflarePurgeLadderProbe::$succeeds = true;
		$chunks = [ [ 'https://example.org/A' ], [ 'https://example.org/B' ] ];

		CloudflarePurgeLadderProbe::runChunks(
			$chunks, 2, $this->logger, CloudflarePurgeBudget::startingAt( 5.0, 0.0 )
		);

		$this->assertSame( [ 'https://example.org/B' ], CloudflarePurgeLadderProbe::$deferred );
		$errors = $this->logger->contextsAt( 'error' );
		$budgetLine = end( $errors );
		$this->assertSame( 'no time left to start the request', $budgetLine['reason'] );
	}

	/**
	 * A failed hand-over *is* a full-TTL stale page, so it must not read like
	 * a successful deferral in the log.
	 */
	public function testAFailedHandOverIsReportedAsADrop() {
		CloudflarePurgeLadderProbe::$cost = 'total';
		CloudflarePurgeLadderProbe::$deferSucceeds = false;

		CloudflarePurgeLadderProbe::runChunks(
			[ [ 'https://example.org/A' ], [ 'https://example.org/B' ] ],
			2, $this->logger, CloudflarePurgeBudget::startingAt( 5.0, 0.0 )
		);

		$errors = $this->logger->contextsAt( 'error' );
		$budgetLine = end( $errors );
		$this->assertFalse( $budgetLine['deferred'] );
		$this->assertSame( 'DROPPED', $budgetLine['disposition'] );
	}

	/**
	 * The budget's own value reaches the log, so that a misconfigured
	 * $wgCloudflarePurgePreSendBudgetSeconds is greppable from the abandonment
	 * it causes rather than only inferable from it.
	 */
	public function testTheAbandonmentLineNamesTheDeadline() {
		CloudflarePurgeLadderProbe::$cost = 'total';

		CloudflarePurgeLadderProbe::runChunks(
			[ [ 'https://example.org/A' ] ], 2, $this->logger,
			CloudflarePurgeBudget::startingAt( 2.5, 0.0 )
		);

		$errors = $this->logger->contextsAt( 'error' );
		$budgetLine = end( $errors );
		$this->assertSame( 2.5, $budgetLine['budget'] );
	}

	/**
	 * A call that fits its budget defers nothing at all.
	 */
	public function testNothingIsDeferredWhenTheBudgetIsNotExhausted() {
		CloudflarePurgeLadderProbe::$cost = 'none';
		CloudflarePurgeLadderProbe::$curlErrno = 7;

		CloudflarePurgeLadderProbe::runChunks(
			[ [ 'https://example.org/A' ], [ 'https://example.org/B' ] ],
			2, $this->logger, CloudflarePurgeBudget::startingAt( 5.0, 0.0 )
		);

		$this->assertNull( CloudflarePurgeLadderProbe::$deferred );
	}
}
