<?php

use PHPUnit\Framework\TestCase;

/**
 * The pre-send deadline, measured on the real clock through real cURL.
 *
 * CloudflarePurgeLadderTest proves the arithmetic on a virtual clock, which is
 * the only way to reach the un-budgeted ladder's 45.75s worst case in a test.
 * What it cannot prove is that the arithmetic reaches libcurl — that the
 * clamped timeout is the one actually set on the handle, and that it actually
 * ends the attempt. That needs a socket, a stopwatch, and patience measured in
 * seconds rather than minutes.
 *
 * The server here accepts connections into the kernel's backlog and never
 * answers, which is the shape of the outage the budget exists for: the
 * connection succeeds, so CURLOPT_CONNECTTIMEOUT never fires, and only the
 * total timeout ends the wait.
 *
 * Two budgets rather than one. A single measurement inside a bound is also
 * what a fast failure looks like; two that track the budget show that the
 * deadline is what is doing the stopping.
 *
 * @covers CloudflarePurge::sendChunk
 * @covers CloudflarePurge::request
 */
class CloudflarePurgeDeadlineWallClockTest extends TestCase {

	/** @var resource|null */
	private $server;

	/** @var array<string,string|false> */
	private $savedProxyEnv = [];

	protected function setUp(): void {
		parent::setUp();
		// libcurl honours http_proxy even for a loopback URL, so on a machine
		// with one configured the request would go to the proxy — which
		// answers immediately, and the timing this test measures would be the
		// proxy's, not the deadline's. Reproduced: 0.25s and 0.75s instead of
		// 0.6s and 1.6s.
		foreach ( [ 'http_proxy', 'HTTP_PROXY', 'all_proxy', 'ALL_PROXY' ] as $var ) {
			$this->savedProxyEnv[$var] = getenv( $var );
			putenv( $var );
		}
		if ( !function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'ext-curl is required to exercise the real request path' );
		}
		// Silenced because a sandbox that forbids listening should skip this
		// test, not fail it with a PHP warning; $errstr carries the reason.
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		$this->server = @stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
		if ( !$this->server ) {
			$this->markTestSkipped( "could not listen on 127.0.0.1: $errstr" );
		}
		// Never accept(): the handshake completes in the backlog, so cURL sees
		// a connected socket that never sends a byte.
		CloudflarePurgeSocketProbe::$url =
			'http://' . stream_socket_get_name( $this->server, false ) . '/purge';
	}

	protected function tearDown(): void {
		foreach ( $this->savedProxyEnv as $var => $value ) {
			if ( $value === false ) {
				putenv( $var );
			} else {
				putenv( "$var=$value" );
			}
		}
		$this->savedProxyEnv = [];
		if ( $this->server ) {
			fclose( $this->server );
			$this->server = null;
		}
		parent::tearDown();
	}

	/**
	 * @return array[]
	 */
	public function provideBudgets() {
		return [
			'0.6s budget' => [ 0.6 ],
			'1.6s budget' => [ 1.6 ],
		];
	}

	/**
	 * @dataProvider provideBudgets
	 * @param float $seconds
	 */
	public function testTheLadderReturnsWithinItsBudget( float $seconds ) {
		$logger = new CloudflarePurgeArrayLogger();

		$start = microtime( true );
		$ok = CloudflarePurgeSocketProbe::runChunk(
			[ 'https://example.org/A' ], 2, $logger,
			CloudflarePurgeBudget::startingAt( $seconds, $start )
		);
		$elapsed = microtime( true ) - $start;

		$this->assertFalse( $ok, 'a silent endpoint is a failed purge' );
		// It used the budget rather than failing early for some other reason...
		$this->assertGreaterThan( $seconds * 0.75, $elapsed );
		// ...and it did not exceed it. Without the clamp this call would have
		// waited out CloudflarePurge's 15-second per-request timeout instead.
		$this->assertLessThan(
			$seconds + 2.0,
			$elapsed,
			"ladder ran {$elapsed}s on a {$seconds}s budget"
		);
		$this->assertLessThan( 15.0, $elapsed );

		$errors = $logger->contextsAt( 'error' );
		$this->assertCount( 1, $errors );
		// 28 = CURLE_OPERATION_TIMEDOUT, and 'transient' is the honest label:
		// it was time, not the failure class, that ended this.
		$this->assertSame( 28, $errors[0]['curlErrno'] );
	}
}
