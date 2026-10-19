<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * uninstall.php runs in a request where the plugin is not loaded at all.
 * Every other test in both suites has the plugin's autoloader registered
 * already (the unit bootstrap requires it), which is exactly what would
 * hide a missing dependency here, so this runs uninstall.php in a fresh
 * PHP process instead.
 */
final class UninstallTest extends TestCase {

	public function test_uninstall_runs_without_the_plugin_being_loaded(): void {
		$harness = dirname( __DIR__ ) . '/Support/uninstall-harness.php';

		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $harness ) . ' 2>&1', $lines, $exit_code );

		$output = implode( "\n", $lines );

		$this->assertSame( 0, $exit_code, $output );

		$result = json_decode( $output, true );

		$this->assertIsArray( $result, $output );
		$this->assertSame( array( 'deployment_guard_state', 'deployment_guard_history', 'deployment_guard_scope', 'deployment_guard_receipt', 'deployment_guard_paused_comments', 'deployment_guard_paused_forms' ), $result['deleted'] );
		$this->assertSame( array( 'administrator:deployment_guard_bypass', 'editor:deployment_guard_bypass' ), $result['removed'] );
	}
}
