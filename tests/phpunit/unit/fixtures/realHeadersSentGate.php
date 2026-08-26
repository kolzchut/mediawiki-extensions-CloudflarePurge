<?php
/**
 * Runs CloudflarePurge's budget gate against the REAL headers_sent(), and
 * reports what it did as JSON on the last line of stdout.
 *
 * A subprocess, because there is no way back: headers_sent() is a property of
 * the process, so a test that made it true in-process would arm that
 * condition for every test that ran afterwards. And it has to be made true
 * for real — stubbing the seam is what CloudflarePurgeBudgetGateTest's other
 * cases do, and a stub cannot show that the seam is wired to the function it
 * names.
 *
 * Deliberately not named *Test.php and deliberately not in the directory
 * PHPUnit scans, so it is never collected as a test case of its own. Its
 * caller is CloudflarePurgeBudgetGateTest::testRealHeadersSentDeclinesARealBudget().
 * It defines no classes: the two it needs are required by path, because a
 * subprocess has no PHPUnit bootstrap and therefore no autoloader.
 */

$extensionDir = dirname( __DIR__, 4 );
require_once "$extensionDir/includes/CloudflarePurgeBudget.php";
require_once "$extensionDir/CloudflarePurge.php";
require_once "$extensionDir/tests/phpunit/unit/CloudflarePurgeArrayLogger.php";
require_once "$extensionDir/tests/phpunit/unit/CloudflarePurgeRealSeamGate.php";

// Nothing has been written yet, so this is the editor path: the budget must
// arm, and must say nothing.
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
