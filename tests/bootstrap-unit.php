<?php
/**
 * Bootstrap for the unit test suite (no WordPress).
 *
 * @package Digibold\DeploymentGuard
 */

// The plugin's classes guard against direct access; the unit suite loads
// them directly, so satisfy that guard the same way WordPress would.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require dirname( __DIR__ ) . '/src/autoload.php';
