<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Enforcement\CapabilityFilter;
use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Installer;
use Digibold\DeploymentGuard\Suspension;

/**
 * @group ms-required
 */
final class MultisiteTest extends TestCase {

	private const NOW = 1_800_000_000;

	public function set_up(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite (run phpunit-integration.xml.dist against a WP_TESTS_MULTISITE=1 install).' );
		}

		parent::set_up();
	}

	public function test_freeze_on_one_site_does_not_affect_another(): void {
		$site_b = self::factory()->blog->create();

		$suspension = new Suspension();
		$service_a  = new FreezeService( $this->clock( self::NOW ), $suspension );
		$guard_a    = new \Digibold\DeploymentGuard\Guard( $service_a, $suspension, false );
		( new CapabilityFilter( $guard_a ) )->register();
		$service_a->enable();

		$this->login_as( 'editor' );
		$post_a = self::factory()->post->create();
		$this->assertFalse( current_user_can( 'edit_post', $post_a ) );

		switch_to_blog( $site_b );
		Installer::install_site();

		$suspension_b = new Suspension();
		$service_b    = new FreezeService( $this->clock( self::NOW ), $suspension_b );
		$guard_b      = new \Digibold\DeploymentGuard\Guard( $service_b, $suspension_b, false );
		( new CapabilityFilter( $guard_b ) )->register();

		$editor_b = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_b );
		$post_b = self::factory()->post->create();

		$this->assertTrue( current_user_can( 'edit_post', $post_b ) );

		restore_current_blog();
	}

	public function test_switch_to_blog_resets_the_memo(): void {
		$site_b = self::factory()->blog->create();

		$suspension = new Suspension();
		$service    = new FreezeService( $this->clock( self::NOW ), $suspension );
		$guard      = new \Digibold\DeploymentGuard\Guard( $service, $suspension, false );

		$this->login_as( 'editor' );
		$service->enable();
		$this->assertTrue( $guard->is_restricted() );

		switch_to_blog( $site_b );
		Installer::install_site();

		// $service still points at site A's options, but is_restricted()
		// must not serve a memoized answer computed before the switch.
		$this->assertFalse( $guard->is_restricted() );

		restore_current_blog();
	}

	public function test_freeze_state_follows_switch_to_blog_on_an_already_installed_site(): void {
		$site_b = self::factory()->blog->create();

		// Site B is installed (and not frozen) before anything reads site
		// A's state, so switching to it later fires no option hook at all.
		switch_to_blog( $site_b );
		Installer::install_site();
		restore_current_blog();

		$suspension = new Suspension();
		$service    = new FreezeService( $this->clock( self::NOW ), $suspension );
		$guard      = new \Digibold\DeploymentGuard\Guard( $service, $suspension, false );

		$this->login_as( 'editor' );
		$service->enable();
		$this->assertTrue( $service->is_active() );
		$this->assertTrue( $guard->is_restricted() );

		switch_to_blog( $site_b );
		$active_on_b     = $service->is_active();
		$restricted_on_b = $guard->is_restricted();
		restore_current_blog();

		$this->assertFalse( $active_on_b, 'Site B is not frozen; site A\'s cached state must not leak into it.' );
		$this->assertFalse( $restricted_on_b );
		$this->assertTrue( $service->is_active() );
	}

	public function test_blocked_attempt_is_logged_only_to_the_current_sites_history(): void {
		$site_b = self::factory()->blog->create();

		switch_to_blog( $site_b );
		Installer::install_site();
		restore_current_blog();

		$service = new FreezeService( $this->clock( self::NOW ), new Suspension() );
		$service->enable(); // Records an entry in site A's history.
		$this->assertNotEmpty( $service->entries() );

		switch_to_blog( $site_b );
		$service->record_blocked( 'rest', 'post_type:post' );
		$entries_b = get_option( FreezeService::OPTION_HISTORY );
		restore_current_blog();

		$this->assertCount( 1, $entries_b, 'Site A\'s history entries must not be copied into site B.' );
		$this->assertSame( 'blocked', $entries_b[0]['type'] );
	}

	public function test_lazy_install_on_a_new_site(): void {
		$site_b = self::factory()->blog->create();
		switch_to_blog( $site_b );

		$this->assertFalse( get_option( FreezeService::OPTION_STATE ) );

		Installer::maybe_install();

		$this->assertNotFalse( get_option( FreezeService::OPTION_STATE ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );

		restore_current_blog();
	}

	public function test_super_admin_bypasses_on_every_site(): void {
		$site_b = self::factory()->blog->create();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );

		$suspension = new Suspension();
		$service    = new FreezeService( $this->clock( self::NOW ), $suspension );
		$guard      = new \Digibold\DeploymentGuard\Guard( $service, $suspension, false );
		( new CapabilityFilter( $guard ) )->register();
		$service->enable();

		$post_id = self::factory()->post->create();
		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );

		switch_to_blog( $site_b );
		Installer::install_site();
		$post_id_b = self::factory()->post->create();
		$this->assertTrue( current_user_can( 'edit_post', $post_id_b ) );
		restore_current_blog();

		revoke_super_admin( $user_id );
	}

	public function test_uninstall_cleans_every_site(): void {
		$site_b = self::factory()->blog->create();

		switch_to_blog( $site_b );
		Installer::install_site();
		restore_current_blog();

		Installer::uninstall();

		$this->assertFalse( get_option( FreezeService::OPTION_STATE ) );

		switch_to_blog( $site_b );
		$this->assertFalse( get_option( FreezeService::OPTION_STATE ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );
		restore_current_blog();
	}

	public function test_removing_the_earlier_copy_keeps_every_sites_state_and_role_grants(): void {
		$site_b = self::factory()->blog->create();

		update_option( FreezeService::OPTION_STATE, array( 'schema' => 1, 'enabled' => false, 'message' => 'site A' ), false );
		remove_role( 'dg_release_editor' );
		add_role( 'dg_release_editor', 'Release Editor', array( 'read' => true, Installer::CAPABILITY => true ) );

		switch_to_blog( $site_b );
		Installer::install_site();
		update_option( FreezeService::OPTION_STATE, array( 'schema' => 1, 'enabled' => false, 'message' => 'site B' ), false );
		update_option( FreezeService::OPTION_HISTORY, array( array( 't' => 1, 'type' => 'enabled', 'user' => 1, 'ctx' => array() ) ), false );
		restore_current_blog();

		$legacy_copy = new \Digibold\DeploymentGuard\LegacyCopy();
		$legacy_copy->register();

		// The earlier release's uninstall walks every site of the network.
		do_action( 'pre_uninstall_plugin', 'deployment-guard/deployment-guard.php', array() );
		Installer::uninstall();
		$this->assertFalse( get_option( FreezeService::OPTION_STATE ), 'precondition: the uninstall wiped site A' );
		do_action( 'deleted_plugin', 'deployment-guard/deployment-guard.php', true );

		$this->assertSame( 'site A', get_option( FreezeService::OPTION_STATE )['message'] );
		$this->assertTrue( get_role( 'dg_release_editor' )->has_cap( Installer::CAPABILITY ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );

		switch_to_blog( $site_b );
		$this->assertSame( 'site B', get_option( FreezeService::OPTION_STATE )['message'] );
		$this->assertCount( 1, get_option( FreezeService::OPTION_HISTORY ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );
		restore_current_blog();
	}
}
