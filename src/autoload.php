<?php
/**
 * Minimal PSR-4 autoloader for the plugin's own classes.
 *
 * Deployment Guard does not require Composer at runtime. This tiny
 * autoloader lets the plugin ship without a vendor/ directory while still
 * using namespaced, one-class-per-file PHP.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_file( $path ) ) {
			require $path;
		}
	}
);
