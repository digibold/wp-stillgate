<?php
/**
 * Bootstrap for the integration test suite (wp-phpunit + MySQL).
 *
 * @package Digibold\DeploymentGuard
 */

$wp_phpunit_dir = getenv( 'WP_PHPUNIT__DIR' );

if ( false === $wp_phpunit_dir || '' === $wp_phpunit_dir ) {
	$wp_phpunit_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
	putenv( 'WP_PHPUNIT__DIR=' . $wp_phpunit_dir );
}

if ( false === getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

// wp-phpunit's own default guess for the Polyfills autoloader assumes it
// lives three directories above wp-phpunit's own install location, which
// is wrong once wp-phpunit is a dependency of a *plugin's* composer.json
// rather than of the root project; point it at the real path directly.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php' );
}

require $wp_phpunit_dir . '/includes/functions.php';

/**
 * Load the plugin the same way a real WordPress install would: as a
 * regular plugin on `muplugins_loaded`, well before the test suite starts
 * creating fixtures.
 */
function _deployment_guard_tests_load_plugin() {
	require dirname( __DIR__ ) . '/stillgate.php';
}
tests_add_filter( 'muplugins_loaded', '_deployment_guard_tests_load_plugin' );

/**
 * Fail loudly if the plugin ever triggers doing_it_wrong() or a
 * deprecation notice mentioning its own text domain: both indicate a bug
 * (for example, calling a translation function before `init`).
 */
function _deployment_guard_tests_fail_on_doing_it_wrong( $function_name, $message, $version ) {
	if ( false !== strpos( (string) $message, 'stillgate' ) || false !== strpos( (string) $function_name, 'Digibold\\DeploymentGuard' ) ) {
		throw new \Exception( sprintf( '_doing_it_wrong triggered by the plugin: %s (%s)', $function_name, $message ) );
	}
}
tests_add_filter( 'doing_it_wrong_run', '_deployment_guard_tests_fail_on_doing_it_wrong', 10, 3 );

require $wp_phpunit_dir . '/includes/bootstrap.php';

require __DIR__ . '/Support/CliExit.php';
require __DIR__ . '/Support/WpCliStub.php';
require __DIR__ . '/Support/WpCliUtilsStub.php';
require __DIR__ . '/Integration/TestCase.php';
