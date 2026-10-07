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
	 * Both seams the tests above replace are wired to what they name, and the
	 * decline line carries the real entry point.
	 *
	 * Stubbing isCommandLine() and headersSent() is what makes the gate
	 * testable at all, and it is also the one thing those tests cannot check:
	 * a seam hard-coded to false would pass every one of them. Both conditions
	 * are irreversible within a process — MW_ENTRY_POINT is a constant and
	 * headers_sent() cannot be un-sent — so each case runs in its own
	 * subprocess, which defines the constant for real (or leaves it undefined),
	 * flushes real output, and asks the unstubbed gate what it did.
	 *
	 * What each case is there to catch:
	 * - 'cli' is the only case where the real isCommandLine() is true. Without
	 *   it, isCommandLine() could return false outright — or read the wrong
	 *   constant, or compare against the wrong value — and nothing would notice.
	 * - 'index' is a defined, non-CLI entry point. It is what fails if
	 *   isCommandLine() stops comparing and only asks whether the constant is
	 *   defined, and it is the only case in which the decline line's
	 *   entryPoint is a real value rather than the 'unknown' fallback — the
	 *   value someone chasing an early flush actually needs.
	 * - Undefined is the state of the PHPUnit process itself, and of any
	 *   caller that reaches the gate outside a MediaWiki entry point; it pins
	 *   the fallback and the real headers_sent() seam.
	 *
	 * @dataProvider provideEntryPoints
	 * @param string|null $entryPoint MW_ENTRY_POINT for the subprocess; null leaves it undefined
	 * @param array $expectBefore [ limited, lines ] before output is flushed
	 * @param array $expectAfter [ limited, lines ] after output is flushed
	 */
	public function testRealSeamsDriveARealBudget(
		?string $entryPoint, array $expectBefore, array $expectAfter
	) {
		$fixture = __DIR__ . '/fixtures/realSeamGate.php';
		$output = [];
		$status = null;
		// Shelling out is the point, not an oversight: see the docblock. Every
		// argument is a path this file computed or a literal from the provider,
		// not input.
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $fixture );
		if ( $entryPoint !== null ) {
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.escapeshellarg
			$command .= ' ' . escapeshellarg( $entryPoint );
		}
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.exec
		exec( $command . ' 2>&1', $output, $status );
		$this->assertSame( 0, $status, implode( "\n", $output ) );

		$result = json_decode( end( $output ), true );
		$this->assertIsArray( $result, implode( "\n", $output ) );

		// The scenario really happened: output was flushed between the two
		// calls. Without this the two halves below could both be describing
		// the same state.
		$this->assertFalse( $result['headersSentBefore'] );
		$this->assertTrue( $result['headersSentAfter'] );

		// Before: nothing flushed yet.
		$this->assertSame( $expectBefore[0], $result['beforeLimited'] );
		if ( $expectBefore[0] ) {
			$this->assertSame( 5, $result['beforeSeconds'] );
		}
		$this->assertSame( $expectBefore[1], $result['beforeLines'] );

		// After: the same call, the same budget, output flushed in between.
		$this->assertSame( $expectAfter[0], $result['afterLimited'] );
		$this->assertSame( $expectAfter[1], $result['afterLines'] );
	}

	/**
	 * @return array[]
	 */
	public static function provideEntryPoints() {
		$headersSent = static function ( string $entryPoint ): array {
			return [ [ 'info', self::MESSAGE, [
				'budget' => 5,
				'reason' => 'headers-sent',
				'entryPoint' => $entryPoint,
			] ] ];
		};
		$cli = [ [ 'debug', self::MESSAGE, [ 'budget' => 5, 'reason' => 'cli' ] ] ];

		return [
			// Armed and silent until output is flushed; unbounded after, and
			// says so, naming the entry point that flushed.
			'undefined' => [ null, [ true, [] ], [ false, $headersSent( 'unknown' ) ] ],
			'index' => [ 'index', [ true, [] ], [ false, $headersSent( 'index' ) ] ],
			// The job runner: stands down before anything is flushed, and after
			// it still reports 'cli' — the CLI test comes first.
			'cli' => [ 'cli', [ false, $cli ], [ false, $cli ] ],
		];
	}
}
