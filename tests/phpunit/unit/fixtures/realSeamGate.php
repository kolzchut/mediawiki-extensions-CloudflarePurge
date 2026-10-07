<?php
/**
 * Runs CloudflarePurge's budget gate with NOTHING stubbed, under the
 * MW_ENTRY_POINT named by its one optional argument, and reports what it did
 * as JSON on the last line of stdout.
 *
 * A subprocess, for two reasons that are both properties of the process and
 * both irreversible within it. headers_sent() cannot be un-sent, so a test
 * that made it true in-process would arm that condition for every test that
 * ran afterwards. And MW_ENTRY_POINT is a constant: once defined it cannot be
 * changed or removed, so one process can only ever show the gate one entry
 * point. Stubbing both is what CloudflarePurgeBudgetGateTest's other cases do,
 * and a stub cannot show that the seam is wired to what it names — so here
 * the constant is really defined (or really left undefined) and the output is
 * really flushed.
 *
 * Usage: php realSeamGate.php [entry-point]
 * With no argument MW_ENTRY_POINT stays undefined, which is the state the
 * PHPUnit process itself is in.
 *
 * Not named *Test.php, which is what keeps PHPUnit from collecting it as a
 * test case of its own: it does live under tests/phpunit/unit/, which
 * phpunit.xml.dist scans recursively, so the suffix convention is the only
 * thing excluding it. Its caller is
 * CloudflarePurgeBudgetGateTest::testRealSeamsDriveARealBudget().
 * It defines no classes: the ones it needs are required by path, because a
 * subprocess has no PHPUnit bootstrap and therefore no autoloader.
 */

if ( isset( $argv[1] ) ) {
	define( 'MW_ENTRY_POINT', $argv[1] );
}

$extensionDir = dirname( __DIR__, 4 );
require_once "$extensionDir/includes/CloudflarePurgeBudget.php";
require_once "$extensionDir/CloudflarePurge.php";
require_once "$extensionDir/tests/phpunit/unit/CloudflarePurgeArrayLogger.php";
require_once "$extensionDir/tests/phpunit/unit/CloudflarePurgeRealSeamGate.php";

// Nothing has been written yet. For a web entry point this is the editor
// path: the budget must arm, and must say nothing. For 'cli' it is the job
// runner, and the budget must stand down whether or not anything was flushed.
$beforeLogger = new CloudflarePurgeArrayLogger();
$before = CloudflarePurgeRealSeamGate::gate( 5.0, $beforeLogger );
$headersSentBefore = headers_sent();

// Something writes output before the pre-send deferred update runs. That is
// the whole scenario: a stray debug print, an extension echoing, an output
// buffer flushed early.
while ( ob_get_level() > 0 ) {
	ob_end_flush();
}
echo "-- output flushed by the fixture --\n";
flush();

$afterLogger = new CloudflarePurgeArrayLogger();
$after = CloudflarePurgeRealSeamGate::gate( 5.0, $afterLogger );

echo json_encode( [
	'headersSentBefore' => $headersSentBefore,
	'headersSentAfter' => headers_sent(),
	'beforeLimited' => $before->isLimited(),
	'beforeSeconds' => $before->budgetSeconds(),
	'beforeLines' => $beforeLogger->lines,
	'afterLimited' => $after->isLimited(),
	'afterLines' => $afterLogger->lines,
] ) . "\n";
