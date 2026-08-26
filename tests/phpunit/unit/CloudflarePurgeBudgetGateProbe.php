<?php

/**
 * Drives CloudflarePurge's budget gate with both of its conditions under
 * test control.
 *
 * Neither condition can be set from a test as it stands. MW_ENTRY_POINT is a
 * constant, so a process can only ever be CLI or not-CLI, never both; and the
 * only way to make headers_sent() true is to emit output, which this suite
 * forbids (phpunit.xml.dist sets beStrictAboutOutputDuringTests). So
 * CloudflarePurge reads each through a seam, exactly as it already does for
 * now(), and this subclass replaces the two.
 *
 * The gate itself — the order of the tests, which level each decline is
 * logged at, and what the returned budget is — is the real one.
 */
class CloudflarePurgeBudgetGateProbe extends CloudflarePurge {

	/** @var bool What isCommandLine() should report */
	public static $cli = false;

	/** @var bool What headersSent() should report */
	public static $headersSent = false;

	/** @var float Fixed clock, so an armed budget's deadline is predictable */
	public static $clock = 1000.0;

	public static function reset() {
		self::$cli = false;
		self::$headersSent = false;
		self::$clock = 1000.0;
	}

	/**
	 * @param float $seconds
	 * @param CloudflarePurgeArrayLogger $logger
	 * @return CloudflarePurgeBudget
	 */
	public static function gate( float $seconds, $logger ): CloudflarePurgeBudget {
		return static::budgetForThisCall( $seconds, $logger );
	}

	/**
	 * @return bool
	 */
	protected static function isCommandLine(): bool {
		return self::$cli;
	}

	/**
	 * @return bool
	 */
	protected static function headersSent(): bool {
		return self::$headersSent;
	}

	/**
	 * @return float
	 */
	protected static function now(): float {
		return self::$clock;
	}
}
