<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Admin\Notices;
use Digibold\DeploymentGuard\Admin\SettingsPage;
use Digibold\DeploymentGuard\Enforcement\AdminGate;
use Digibold\DeploymentGuard\Plugin;

/**
 * Exercises the composition root itself in wp-admin context. The
 * integration bootstrap loads the plugin outside wp-admin (is_admin() is
 * false there), so without this test the admin-only wiring in
 * Plugin::register() would never run under test at all.
 */
final class PluginTest extends TestCase {

	public function tear_down(): void {
		$GLOBALS['current_screen'] = null;

		parent::tear_down();
	}

	public function test_register_in_admin_context_wires_every_admin_class(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );

		( new Plugin( dirname( __DIR__, 2 ) . '/stillgate.php' ) )->register();

		$this->assertTrue( $this->has_callback_of( 'admin_notices', Notices::class ) );
		$this->assertTrue( $this->has_callback_of( 'admin_menu', SettingsPage::class ) );
		$this->assertTrue( $this->has_callback_of( 'load-post.php', AdminGate::class ) );
	}

	public function test_register_ignores_a_main_file_that_is_not_this_copies_own(): void {
		global $wp_filter;

		$count = static function () use ( &$wp_filter ): int {
			$total = 0;

			foreach ( $wp_filter as $hook ) {
				foreach ( $hook->callbacks as $callbacks ) {
					$total += count( $callbacks );
				}
			}

			return $total;
		};

		$before = $count();

		// What an earlier "Deployment Guard" copy loaded after this one does:
		// it resolves this class by name and passes its own main file.
		( new Plugin( WP_PLUGIN_DIR . '/deployment-guard/deployment-guard.php' ) )->register();

		$this->assertSame( $before, $count() );
	}

	private function has_callback_of( string $hook, string $class_name ): bool {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return false;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof $class_name ) {
					return true;
				}
			}
		}

		return false;
	}
}
