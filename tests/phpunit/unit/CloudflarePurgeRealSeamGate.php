<?php

/**
 * Exposes CloudflarePurge's budget gate with NOTHING stubbed.
 *
 * The sibling CloudflarePurgeBudgetGateProbe replaces isCommandLine() and
 * headersSent(), which is what makes the gate's branches reachable from a
 * test at all — and is also the one thing such a test cannot check: a seam
 * hard-coded to false passes every stubbed assertion. This subclass overrides
 * neither, so the gate reads the real headers_sent() and the real
 * MW_ENTRY_POINT.
 *
 * Only reachable from tests/phpunit/unit/fixtures/realHeadersSentGate.php,
 * which runs it in a subprocess: headers_sent() cannot be un-sent, so making
 * it true in the test process would arm that condition for every test after.
 */
class CloudflarePurgeRealSeamGate extends CloudflarePurge {

	/**
	 * @param float $seconds
	 * @param CloudflarePurgeArrayLogger $logger
	 * @return CloudflarePurgeBudget
	 */
	public static function gate( float $seconds, $logger ): CloudflarePurgeBudget {
		return static::budgetForThisCall( $seconds, $logger );
	}
}
