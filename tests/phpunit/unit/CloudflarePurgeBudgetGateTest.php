<?php

use PHPUnit\Framework\TestCase;

/**
 * The pre-send budget's gate: when it arms, when it stands down, and whether
 * standing down leaves a trace.
 *
 * The gate declining is correct behaviour on the job path and a silent
 * self-disable on the editor path, and until kolzchut/kz-infrastructure#1039
 * the two were the same event as far as any log was concerned. So the
 * assertions here are as much about the line NOT being written as about it
 * being written: a message that fires on every purge is one people filter,
 * and filtering it would take the rare one with it.
 *
 * Every case asserts the returned budget as well as the log, which is what
 * keeps the negative cases honest. "No line was logged" is also true of a
 * gate that was never called; "no line was logged AND the budget came back
 * armed for 5 seconds" is not.
 *
 * @covers CloudflarePurge
 */
class CloudflarePurgeBudgetGateTest extends TestCase {

	/** @var string What both decline lines say, so one grep finds both */
	private const MESSAGE =
		'Cloudflare pre-send purge budget of {budget}s not applied: {reason}';

	protected function setUp(): void {
		parent::setUp();
		CloudflarePurgeBudgetGateProbe::reset();
	}

	public function testBudgetArmsSilentlyOnTheEditorPath() {
		$logger = new CloudflarePurgeArrayLogger();
		CloudflarePurgeBudgetGateProbe::$clock = 1000.0;

		$budget = CloudflarePurgeBudgetGateProbe::gate( 5.0, $logger );

		// Not just "no line": the budget really was built, from this call,
		// with this clock. Without these three the test would also pass
		// against a gate that was never reached.
		$this->assertTrue( $budget->isLimited() );
		$this->assertSame( 5.0, $budget->budgetSeconds() );
		$this->assertSame( 5.0, $budget->remaining( 1000.0 ) );
		$this->assertSame( [], $logger->lines,
			'an armed budget is the normal case and must be silent' );
	}

	public function testHeadersSentDeclineIsLoggedAtInfo() {
		$logger = new CloudflarePurgeArrayLogger();
		CloudflarePurgeBudgetGateProbe::$headersSent = true;

		$budget = CloudflarePurgeBudgetGateProbe::gate( 5.0, $logger );

		$this->assertFalse( $budget->isLimited() );
		$this->assertSame( [ [ 'info', self::MESSAGE, [
			'budget' => 5.0,
			'reason' => 'headers-sent',
			'entryPoint' => 'unknown',
		] ] ], $logger->lines );
	}

	public function testCliDeclineIsLoggedAtDebug() {
		$logger = new CloudflarePurgeArrayLogger();
		CloudflarePurgeBudgetGateProbe::$cli = true;

		$budget = CloudflarePurgeBudgetGateProbe::gate( 5.0, $logger );

		$this->assertFalse( $budget->isLimited() );
		// Debug, not info: this is every purge the job runner makes. The
		// channel is routed to the error log at DEBUG on both wikis, so the
		// line is written either way — the level is what keeps a per-job fact
		// from reading as a finding.
		$this->assertSame( [ [ 'debug', self::MESSAGE, [
			'budget' => 5.0,
			'reason' => 'cli',
		] ] ], $logger->lines );
	}

	public function testCliIsReportedWhenBothConditionsHold() {
		$logger = new CloudflarePurgeArrayLogger();
		CloudflarePurgeBudgetGateProbe::$cli = true;
		CloudflarePurgeBudgetGateProbe::$headersSent = true;

		CloudflarePurgeBudgetGateProbe::gate( 5.0, $logger );

		// A job runner has flushed nothing, but it also does not matter: CLI
		// is the more fundamental reason and is tested first. Reporting
		// 'headers-sent' for a job runner would send a reader hunting a stray
		// echo that does not exist.
		$this->assertCount( 1, $logger->lines );
		$this->assertSame( 'debug', $logger->lines[0][0] );
		$this->assertSame( 'cli', $logger->lines[0][2]['reason'] );
	}

	/**
	 * @dataProvider provideUnconfiguredBudgets
	 * @param float $seconds
	 */
	public function testUnconfiguredBudgetIsNotLogged( float $seconds ) {
		$logger = new CloudflarePurgeArrayLogger();
		// The same probe state that logs at debug in testCliDeclineIsLoggedAtDebug.
		// The only difference is the budget, so silence here can only come
		// from the seconds test short-circuiting ahead of the CLI one — this
		// is not a case where the gate was simply never reached.
		CloudflarePurgeBudgetGateProbe::$cli = true;

		$budget = CloudflarePurgeBudgetGateProbe::gate( $seconds, $logger );

		$this->assertFalse( $budget->isLimited() );
		$this->assertSame( [], $logger->lines,
			'nothing was configured, so there is no decline to report' );
	}

	/**
	 * @return array[]
	 */
	public static function provideUnconfiguredBudgets() {
		return [
			'disabled' => [ 0.0 ],
			'nonsense' => [ -1.0 ],
		];
	}

	/**
	 * The seams the three tests above replace are wired to the functions they
	 * name.
	 *
	 * Stubbing isCommandLine() and headersSent() is what makes the gate
	 * testable at all, and it is also the one thing those tests cannot check:
	 * a seam hard-coded to false would pass every one of them. headers_sent()
	 * is irreversible within a process, so this runs in a subprocess that
	 * flushes real output and asks the real gate what it did.
	 */
	public function testRealHeadersSentDeclinesARealBudget() {
		$fixture = __DIR__ . '/fixtures/realHeadersSentGate.php';
		$output = [];
		$status = null;
		// Shelling out is the point, not an oversight: see the docblock. Both
		// arguments are paths this file computed, not input.
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $fixture ) . ' 2>&1';
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.exec
		exec( $command, $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );

		$result = json_decode( end( $output ), true );
		$this->assertIsArray( $result, implode( "\n", $output ) );

		// The scenario really happened: output was flushed between the two
		// calls. Without this the two halves below could both be describing
		// the same state.
		$this->assertFalse( $result['headersSentBefore'] );
		$this->assertTrue( $result['headersSentAfter'] );

		// Before: the editor path. Armed, and silent.
		$this->assertTrue( $result['beforeLimited'] );
		$this->assertSame( 5, $result['beforeSeconds'] );
		$this->assertSame( [], $result['beforeLines'] );

		// After: the same call, the same budget, output flushed in between.
		// Unbounded now — and it says so.
		$this->assertFalse( $result['afterLimited'] );
		$this->assertSame( [ [ 'info', self::MESSAGE, [
			'budget' => 5,
			'reason' => 'headers-sent',
			'entryPoint' => 'unknown',
		] ] ], $result['afterLines'] );
	}
}
