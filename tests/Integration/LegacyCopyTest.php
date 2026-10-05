<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Installer;
use Digibold\DeploymentGuard\LegacyCopy;

/**
 * The earlier "Deployment Guard" release shares this plugin's options and
 * capability, and its uninstall.php deletes them. These tests simulate that
 * uninstall (the same Installer::uninstall() routine the earlier release
 * runs from uninstall.php) around the hooks WordPress fires when a plugin
 * is deleted, and cover the Plugins-screen notice shown while both copies
 * are active. The real earlier release is exercised end to end by
 * tests/e2e/migration.sh.
 */
final class LegacyCopyTest extends TestCase {

	private const LEGACY = 'deployment-guard/deployment-guard.php';

	public function tear_down(): void {
		remove_role( 'dg_release_editor' );
		$GLOBALS['current_screen'] = null;

		parent::tear_down();
	}

	/**
	 * @return array{state: array, history: array}
	 */
	private function seed_shared_state(): array {
		$state = array(
			'schema'         => 1,
			'enabled'        => false,
			'schedule_start' => 1_800_000_000,
			'schedule_end'   => 1_800_003_600,
			'message'        => 'Back at noon.',
			'updated_at'     => 1_799_999_000,
			'updated_by'     => 7,
		);

		$history = array(
			array(
				't'    => 1_799_999_000,
				'type' => 'schedule_set',
				'user' => 7,
				'ctx'  => array( 'source' => 'ui' ),
			),
			array(
				't'    => 1_799_998_000,
				'type' => 'enabled',
				'user' => 7,
				'ctx'  => array( 'source' => 'cli' ),
			),
		);

		update_option( FreezeService::OPTION_STATE, $state, false );
		update_option( FreezeService::OPTION_HISTORY, $history, false );

		// The in-memory role registry outlives the per-test database
		// rollback, so start from a role that does not exist yet.
		remove_role( 'dg_release_editor' );

		// A custom role that is not an administrator and has no
		// manage_options: re-installing would never grant it the capability.
		add_role(
			'dg_release_editor',
			'Release Editor',
			array(
				'read'                  => true,
				'edit_posts'            => true,
				Installer::CAPABILITY   => true,
			)
		);

		return array(
			'state'   => $state,
			'history' => $history,
		);
	}

	private function simulate_earlier_copy_uninstall( LegacyCopy $legacy_copy, string $plugin_file = self::LEGACY ): void {
		do_action( 'pre_uninstall_plugin', $plugin_file, array() );
		Installer::uninstall();
		do_action( 'deleted_plugin', $plugin_file, true );
	}

	public function test_state_history_and_every_roles_bypass_grant_survive_the_earlier_copy_being_uninstalled(): void {
		$saved = $this->seed_shared_state();

		$legacy_copy = new LegacyCopy();
		$legacy_copy->register();

		// Without the safety net this is exactly what the earlier release does.
		do_action( 'pre_uninstall_plugin', self::LEGACY, array() );
		Installer::uninstall();
		$this->assertFalse( get_option( FreezeService::OPTION_STATE, false ), 'precondition: the uninstall removes the state' );
		$this->assertFalse( get_option( FreezeService::OPTION_HISTORY, false ) );
		$this->assertFalse( get_role( 'dg_release_editor' )->has_cap( Installer::CAPABILITY ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );

		do_action( 'deleted_plugin', self::LEGACY, true );

		$this->assertSame( $saved['state'], get_option( FreezeService::OPTION_STATE ) );
		$this->assertSame( $saved['history'], get_option( FreezeService::OPTION_HISTORY ) );
		$this->assertTrue( get_role( 'dg_release_editor' )->has_cap( Installer::CAPABILITY ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );
	}

	public function test_restored_options_are_not_autoloaded(): void {
		$this->seed_shared_state();
		$legacy_copy = new LegacyCopy();
		$legacy_copy->register();

		$this->simulate_earlier_copy_uninstall( $legacy_copy );

		global $wpdb;
		$autoload = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name IN (%s, %s)",
				FreezeService::OPTION_STATE,
				FreezeService::OPTION_HISTORY
			)
		);

		$this->assertCount( 2, $autoload );
		foreach ( $autoload as $value ) {
			$this->assertContains( $value, array( 'off', 'no' ) );
		}
	}

	public function test_a_github_source_archive_folder_name_is_recognised(): void {
		$this->assertTrue( LegacyCopy::is_legacy_basename( 'deployment-guard/deployment-guard.php' ) );
		$this->assertTrue( LegacyCopy::is_legacy_basename( 'wp-deployment-guard-0.1.0/deployment-guard.php' ) );
		$this->assertTrue( LegacyCopy::is_legacy_basename( 'deployment-guard.php' ) );
		$this->assertFalse( LegacyCopy::is_legacy_basename( 'stillgate/stillgate.php' ) );
		$this->assertFalse( LegacyCopy::is_legacy_basename( 'deployment-guard/other.php' ) );
		$this->assertFalse( LegacyCopy::is_legacy_basename( 'not-deployment-guard.php' ) );

		$saved       = $this->seed_shared_state();
		$legacy_copy = new LegacyCopy();
		$legacy_copy->register();
		$this->simulate_earlier_copy_uninstall( $legacy_copy, 'wp-deployment-guard-0.1.0/deployment-guard.php' );

		$this->assertSame( $saved['state'], get_option( FreezeService::OPTION_STATE ) );
	}

	public function test_uninstalling_any_other_plugin_is_never_remembered_or_restored(): void {
		$this->seed_shared_state();
		$legacy_copy = new LegacyCopy();
		$legacy_copy->register();

		$this->simulate_earlier_copy_uninstall( $legacy_copy, 'akismet/akismet.php' );

		$this->assertFalse( get_option( FreezeService::OPTION_STATE, false ) );
		$this->assertFalse( get_role( 'dg_release_editor' )->has_cap( Installer::CAPABILITY ) );
	}

	public function test_nothing_is_restored_without_an_uninstall_having_been_seen(): void {
		$this->seed_shared_state();
		$legacy_copy = new LegacyCopy();
		$legacy_copy->register();

		// A removal by other means: no pre_uninstall_plugin was seen.
		Installer::uninstall();
		do_action( 'deleted_plugin', self::LEGACY, true );
		$legacy_copy->restore_after_uninstall();

		$this->assertFalse( get_option( FreezeService::OPTION_STATE, false ) );
	}

	public function test_restore_never_overwrites_a_value_that_still_exists_and_runs_only_once(): void {
		$this->seed_shared_state();
		$legacy_copy = new LegacyCopy();
		$legacy_copy->register();

		do_action( 'pre_uninstall_plugin', self::LEGACY, array() );
		// An uninstall that did not remove the state (for example one
		// that failed part-way) leaves newer data untouched.
		update_option( FreezeService::OPTION_STATE, array( 'schema' => 1, 'enabled' => true, 'message' => 'newer' ), false );
		$legacy_copy->restore_after_uninstall();

		$this->assertSame( 'newer', get_option( FreezeService::OPTION_STATE )['message'] );

		// A second call (the shutdown hook after deleted_plugin) is a no-op.
		delete_option( FreezeService::OPTION_STATE );
		$legacy_copy->restore_after_uninstall();
		$this->assertFalse( get_option( FreezeService::OPTION_STATE, false ) );
	}

	public function test_a_bare_uninstall_without_deleted_plugin_is_restored_by_the_shutdown_hook(): void {
		$saved       = $this->seed_shared_state();
		$legacy_copy = new LegacyCopy();
		$legacy_copy->register();

		$this->assertNotFalse( has_action( 'shutdown', array( $legacy_copy, 'restore_after_uninstall' ) ) );

		// `wp plugin uninstall` calls uninstall_plugin() directly, which
		// fires pre_uninstall_plugin but nothing after the uninstall.
		do_action( 'pre_uninstall_plugin', self::LEGACY, array() );
		Installer::uninstall();
		$legacy_copy->restore_after_uninstall();

		$this->assertSame( $saved['state'], get_option( FreezeService::OPTION_STATE ) );
		$this->assertTrue( get_role( 'dg_release_editor' )->has_cap( Installer::CAPABILITY ) );
	}

	private function render_notice( LegacyCopy $legacy_copy ): string {
		ob_start();
		$legacy_copy->render_notice();

		return ob_get_clean();
	}

	private function go_to_screen( string $screen_id ): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		set_current_screen( $screen_id );
	}

	public function test_notice_appears_on_the_plugins_screen_while_the_earlier_copy_is_active(): void {
		update_option( 'active_plugins', array( self::LEGACY ) );
		$this->login_as( 'administrator' );
		$this->go_to_screen( 'plugins' );

		$html = $this->render_notice( new LegacyCopy() );

		$this->assertStringContainsString( 'still active alongside Stillgate', $html );
		$this->assertStringContainsString( 'ends a freeze that is currently active', $html );
		$this->assertStringNotContainsString( '<p><p>', $html );
	}

	public function test_passive_copy_notice_says_it_has_not_started(): void {
		$this->login_as( 'administrator' );
		$this->go_to_screen( 'plugins' );

		$html = $this->render_notice( new LegacyCopy( true ) );

		$this->assertStringContainsString( 'has not started', $html );
	}

	public function test_notice_is_scoped_to_the_plugins_screen_and_to_people_who_can_manage_plugins(): void {
		update_option( 'active_plugins', array( self::LEGACY ) );

		$this->login_as( 'administrator' );
		$this->go_to_screen( 'dashboard' );
		$this->assertSame( '', $this->render_notice( new LegacyCopy() ), 'not on other screens' );

		$this->login_as( 'editor' );
		$this->go_to_screen( 'plugins' );
		$this->assertSame( '', $this->render_notice( new LegacyCopy() ), 'not for users who cannot manage plugins' );
	}

	public function test_notice_disappears_once_the_earlier_copy_is_no_longer_active(): void {
		update_option( 'active_plugins', array( 'stillgate/stillgate.php' ) );
		$this->login_as( 'administrator' );
		$this->go_to_screen( 'plugins' );

		$this->assertSame( '', $this->render_notice( new LegacyCopy() ) );
	}

	public function test_a_github_source_folder_counts_as_the_earlier_copy_being_active(): void {
		update_option( 'active_plugins', array( 'wp-deployment-guard-0.1.0/deployment-guard.php', 'stillgate/stillgate.php' ) );

		$this->assertTrue( ( new LegacyCopy() )->legacy_is_active() );
	}
}
