<?php

/**
 * A wall-clock deadline for one purge call.
 *
 * The per-request cURL timeouts bound a single attempt. They do not bound the
 * ladder: three attempts against a blackholed API cost 3 x CONNECT_TIMEOUT
 * plus backoff, and against an API that accepts the connection and then stops
 * talking they cost 3 x TOTAL_TIMEOUT. Multiply by the number of chunks and a
 * single purge call can run for minutes.
 *
 * That matters because the purge of an edited page runs in a *pre-send*
 * deferred update — before the response is flushed — and nothing in the stack
 * stops it. PHP's max_execution_time is a CPU-time timer (ITIMER_PROF) and
 * does not tick while the process is blocked in curl or usleep;
 * $wgRequestTimeLimit arms a wall-clock timer only when the Excimer extension
 * is present. So the request does not die: it stalls, holding a worker, until
 * the web server's own read timeout hands the editor a gateway error for a
 * save that actually succeeded. During a Cloudflare outage every concurrent
 * save pays that at once.
 *
 * This class is the arithmetic of a deadline and nothing else: no clock, no
 * MediaWiki, no cURL. Callers pass the current time in, which is what makes
 * the ladder's timing testable without waiting for it.
 */
class CloudflarePurgeBudget {

	/**
	 * Never hand cURL a timeout below this, even when the budget is nearly
	 * spent. A timeout of zero means "no timeout" to libcurl, which is the
	 * opposite of what an exhausted budget wants, and a couple of
	 * milliseconds is not worth a syscall.
	 */
	private const MIN_TIMEOUT_SECONDS = 0.1;

	/** @var float|null Absolute deadline, or null when unbounded */
	private $deadline;

	/** @var float|null The budget as configured, kept for the log line */
	private $seconds;

	/**
	 * @param float|null $deadline
	 * @param float|null $seconds
	 */
	private function __construct( ?float $deadline, ?float $seconds ) {
		$this->deadline = $deadline;
		$this->seconds = $seconds;
	}

	/**
	 * A budget that never expires — the job-queue path, where the work is
	 * worth waiting for and no reader is blocked on it.
	 *
	 * @return self
	 */
	public static function unlimited(): self {
		return new self( null, null );
	}

	/**
	 * @param float $seconds Budget for the whole call
	 * @param float $now Current time, from microtime( true )
	 * @return self
	 */
	public static function startingAt( float $seconds, float $now ): self {
		return new self( $now + $seconds, $seconds );
	}

	/**
	 * @return bool Whether this budget bounds anything at all
	 */
	public function isLimited(): bool {
		return $this->deadline !== null;
	}

	/**
	 * The configured budget, so that a log line about an abandoned purge can
	 * name the deadline that produced it. Without this a misconfigured
	 * $wgCloudflarePurgePreSendBudgetSeconds is not greppable from the log it
	 * causes.
	 *
	 * @return float|null Seconds, or null when unbounded
	 */
	public function budgetSeconds(): ?float {
		return $this->seconds;
	}

	/**
	 * @param float $now
	 * @return float|null Seconds left, never negative; null when unbounded
	 */
	public function remaining( float $now ): ?float {
		if ( $this->deadline === null ) {
			return null;
		}
		return max( 0.0, $this->deadline - $now );
	}

	/**
	 * @param float $now
	 * @return bool Whether there is no useful time left to start anything new
	 */
	public function isExhausted( float $now ): bool {
		$remaining = $this->remaining( $now );

		return $remaining !== null && $remaining < self::MIN_TIMEOUT_SECONDS;
	}

	/**
	 * Clamp a cURL timeout to what is actually left.
	 *
	 * Without this the deadline would only be checked *between* attempts, so
	 * a single attempt could overrun it by the whole per-request timeout —
	 * which on the slow-API path is 15 seconds, three times the default
	 * budget. Clamping makes the deadline the real bound rather than an
	 * approximate one.
	 *
	 * @param float $ceiling The configured timeout for this option
	 * @param float $now
	 * @return float Seconds
	 */
	public function clampTimeout( float $ceiling, float $now ): float {
		$remaining = $this->remaining( $now );
		if ( $remaining === null ) {
			return $ceiling;
		}

		return max( self::MIN_TIMEOUT_SECONDS, min( $ceiling, $remaining ) );
	}

	/**
	 * Whether sleeping for $delay still leaves time to make the attempt the
	 * sleep exists to enable. Sleeping out the last of the budget and then
	 * giving up wastes the editor's time for nothing.
	 *
	 * @param float $delay Seconds the retry policy wants to wait
	 * @param float $now
	 * @return bool
	 */
	public function permitsSleep( float $delay, float $now ): bool {
		$remaining = $this->remaining( $now );

		return $remaining === null || $delay + self::MIN_TIMEOUT_SECONDS <= $remaining;
	}
}
