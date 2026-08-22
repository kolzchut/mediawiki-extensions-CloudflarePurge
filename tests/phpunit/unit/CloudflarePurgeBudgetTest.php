<?php

use PHPUnit\Framework\TestCase;

/**
 * @covers CloudflarePurgeBudget
 */
class CloudflarePurgeBudgetTest extends TestCase {

	public function testUnlimitedBoundsNothing() {
		$budget = CloudflarePurgeBudget::unlimited();

		$this->assertFalse( $budget->isLimited() );
		$this->assertNull( $budget->remaining( 1000.0 ) );
		$this->assertFalse( $budget->isExhausted( 1e12 ) );
		$this->assertSame( 15.0, $budget->clampTimeout( 15.0, 1e12 ) );
		$this->assertTrue( $budget->permitsSleep( 3600.0, 1e12 ) );
	}

	public function testRemainingCountsDownAndFloorsAtZero() {
		$budget = CloudflarePurgeBudget::startingAt( 5.0, 100.0 );

		$this->assertSame( 5.0, $budget->remaining( 100.0 ) );
		$this->assertSame( 1.5, $budget->remaining( 103.5 ) );
		$this->assertSame( 0.0, $budget->remaining( 200.0 ) );
	}

	/**
	 * The point of clamping: an attempt started with 1.2s left must not be
	 * allowed to run for the full 15s per-request timeout, or the deadline is
	 * decorative.
	 */
	public function testClampNeverExceedsWhatIsLeft() {
		$budget = CloudflarePurgeBudget::startingAt( 5.0, 100.0 );

		$this->assertSame( 5.0, $budget->clampTimeout( 15.0, 100.0 ) );
		$this->assertEqualsWithDelta( 1.2, $budget->clampTimeout( 15.0, 103.8 ), 1e-9 );
		// The ceiling still wins when it is the smaller of the two.
		$this->assertSame( 5.0, $budget->clampTimeout( 5.0, 100.0 ) );
	}

	/**
	 * Zero means "no timeout" to libcurl, which is the opposite of what an
	 * exhausted budget wants.
	 */
	public function testClampNeverReachesZero() {
		$budget = CloudflarePurgeBudget::startingAt( 5.0, 100.0 );

		$this->assertGreaterThan( 0.0, $budget->clampTimeout( 15.0, 105.0 ) );
		$this->assertGreaterThan( 0.0, $budget->clampTimeout( 15.0, 999.0 ) );
	}

	public function testExhaustedOnceThereIsNoUsefulTimeLeft() {
		$budget = CloudflarePurgeBudget::startingAt( 5.0, 100.0 );

		$this->assertFalse( $budget->isExhausted( 100.0 ) );
		$this->assertFalse( $budget->isExhausted( 104.5 ) );
		$this->assertTrue( $budget->isExhausted( 104.95 ) );
		$this->assertTrue( $budget->isExhausted( 110.0 ) );
	}

	/**
	 * Sleeping out the last of the budget and then giving up spends the
	 * editor's time on nothing at all.
	 */
	public function testASleepWithNoAttemptAfterItIsRefused() {
		$budget = CloudflarePurgeBudget::startingAt( 5.0, 100.0 );

		$this->assertTrue( $budget->permitsSleep( 0.25, 100.0 ) );
		$this->assertTrue( $budget->permitsSleep( 0.5, 104.0 ) );
		// 0.5s of sleep with 0.5s left leaves nothing for the retry.
		$this->assertFalse( $budget->permitsSleep( 0.5, 104.5 ) );
		$this->assertFalse( $budget->permitsSleep( 0.25, 105.0 ) );
	}
}
