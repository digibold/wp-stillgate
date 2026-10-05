<?php
/**
 * WordPress test-suite configuration.
 *
 * Values are read from the environment so CI can point this at its own
 * MySQL service without editing this file; sensible local defaults are
 * used otherwise (see docs/testing setup in README.md).
 *
 * @package Digibold\DeploymentGuard
 */

$table_prefix = 'wptests_';

define( 'DB_NAME', getenv( 'WP_TEST_DB_NAME' ) ?: 'wordpress_tests' );
define( 'DB_USER', getenv( 'WP_TEST_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TEST_DB_PASSWORD' ) ?: 'root' );
define( 'DB_HOST', getenv( 'WP_TEST_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Stillgate Test Site' );

define( 'WP_PHP_BINARY', PHP_BINARY );
define( 'WPLANG', '' );

define( 'ABSPATH', dirname( __DIR__ ) . '/vendor/roots/wordpress-no-content/' );
