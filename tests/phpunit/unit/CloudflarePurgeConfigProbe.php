<?php

/**
 * Drives CloudflarePurge::purgeUrls() from a plain settings array.
 *
 * purgeUrls() reads MediaWiki's main config and logger service, neither of
 * which exists in this MediaWiki-free suite, so it hands both to
 * purgeUrlsWithConfig() — and this subclass calls that directly with an
 * array-backed config and a CloudflarePurgeArrayLogger.
 *
 * request() is replaced by a recorder that always succeeds, so a test can see
 * whether a request was sent at all and with which headers. Everything
 * between the config and the request — the zone and credential checks, the
 * URL set, the chunking, the ladder — is the real code.
 */
class CloudflarePurgeConfigProbe extends CloudflarePurge {

	/** @var array[] One [ zoneID, headers, urls ] per request sent */
	public static $requests = [];

	/** @var array The extension's defaults, as extension.json declares them */
	private const DEFAULTS = [
		'CloudflarePurgeZoneID' => '',
		'CloudflarePurgeToken' => '',
		'CloudflarePurgeAuthEmail' => '',
		'CloudflarePurgeAuthKey' => '',
		'CloudflarePurgeExtraHosts' => [],
		'CloudflarePurgeMaxUrlsPerBatch' => 1000,
		'CloudflarePurgeUrlsPerRequest' => 100,
		'CloudflarePurgeMaxRetries' => 2,
		// 0, so the budget gate returns before reading its two
		// process-global seams; the gate has its own test.
		'CloudflarePurgePreSendBudgetSeconds' => 0,
	];

	public static function reset() {
		self::$requests = [];
	}

	/**
	 * @param string[] $urls
	 * @param array $settings Overrides for DEFAULTS, keyed without the wg prefix
	 * @param CloudflarePurgeArrayLogger $logger
	 * @return bool What purgeUrls() would have returned
	 */
	public static function run( array $urls, array $settings, $logger ) {
		$values = $settings + self::DEFAULTS;
		$config = new class( $values ) {
			/** @var array */
			private $values;

			/**
			 * @param array $values
			 */
			public function __construct( array $values ) {
				$this->values = $values;
			}

			/**
			 * @param string $name
			 * @return mixed
			 */
			public function get( $name ) {
				if ( !array_key_exists( $name, $this->values ) ) {
					throw new InvalidArgumentException( "Unknown setting $name" );
				}
				return $this->values[$name];
			}
		};

		return static::purgeUrlsWithConfig( $urls, $config, $logger );
	}

	/**
	 * @param string[] $urls
	 * @param string $zoneID
	 * @param string[] $headers
	 * @param float $connectTimeout
	 * @param float $totalTimeout
	 * @return array
	 */
	protected static function request(
		array $urls, string $zoneID, array $headers,
		float $connectTimeout, float $totalTimeout
	) {
		self::$requests[] = [ $zoneID, $headers, $urls ];

		return [
			'ok' => true,
			'status' => 200,
			'error' => '',
			'curlErrno' => 0,
			'retryAfter' => null,
		];
	}
}
