<?php

/**
 * The smallest thing CloudflarePurge::sendChunk() will accept as a logger.
 *
 * Deliberately not a Psr\Log\LoggerInterface: sendChunk() takes an untyped
 * $logger and calls only error() and warning(), so keeping this to those two
 * methods lets the ladder tests run with no dependency beyond PHPUnit.
 */
class CloudflarePurgeArrayLogger {

	/** @var array[] List of [ level, message, context ] */
	public $lines = [];

	/**
	 * @param string $message
	 * @param array $context
	 */
	public function error( $message, array $context = [] ) {
		$this->lines[] = [ 'error', $message, $context ];
	}

	/**
	 * @param string $message
	 * @param array $context
	 */
	public function warning( $message, array $context = [] ) {
		$this->lines[] = [ 'warning', $message, $context ];
	}

	/**
	 * @param string $level
	 * @return array[] Contexts of the lines logged at $level
	 */
	public function contextsAt( string $level ): array {
		$out = [];
		foreach ( $this->lines as [ $lineLevel, , $context ] ) {
			if ( $lineLevel === $level ) {
				$out[] = $context;
			}
		}

		return $out;
	}
}
