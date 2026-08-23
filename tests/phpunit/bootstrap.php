<?php
/**
 * Bootstrap for the MediaWiki-free half of this extension's test suite.
 *
 * Everything under tests/phpunit/unit/ exercises plain classes: the budget,
 * the retry policy, the URL set, and the request ladder. None of them touch
 * MediaWiki services, so none of them need MediaWiki's test runner, a wiki
 * install, LocalSettings.php, or a database — only PHP with ext-curl and
 * PHPUnit. That is what makes it cheap enough to run on every push, and it is
 * the reason this file exists rather than a `phpunit.php --wiki=...` line.
 *
 * The class map is read from extension.json rather than restated here, so a
 * class added to the extension is autoloadable in tests without a second
 * edit. Registration is lazy, so the one integration-only helper listed in
 * TestAutoloadClasses (which does extend a MediaWiki test case) is never
 * loaded by a unit run.
 *
 * tests/phpunit/integration/ is a different animal — an upgrade canary that
 * needs a real MediaWiki and a database — and is excluded by phpunit.xml.dist.
 */

$extensionDir = dirname( __DIR__, 2 );

spl_autoload_register( static function ( $class ) use ( $extensionDir ) {
	static $map = null;
	if ( $map === null ) {
		$json = json_decode( file_get_contents( "$extensionDir/extension.json" ), true );
		// Union, not array_merge: both halves are keyed by class name, and
		// '+' keeps the production entry if a name ever collides.
		$map = ( $json['AutoloadClasses'] ?? [] ) + ( $json['TestAutoloadClasses'] ?? [] );
	}
	if ( isset( $map[$class] ) ) {
		require_once "$extensionDir/" . $map[$class];
	}
} );
