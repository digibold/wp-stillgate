<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Installer;

final class GuardTest extends TestCase {

	private const NOW = 1_800_000_000;

	/**
	 * @dataProvider provide_web_roles
	 */
	public function test_web_restriction_by_role( string $role, bool $expect_restricted ): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );
		$service->enable();

		if ( 'anonymous' === $role ) {
			wp_set_current_user( 0 );
		} else {
			$this->login_as( $role );
		}

		$this->assertSame( $expect_restricted, $guard->is_restricted() );
	}

	public static function provide_web_roles(): array {
		return array(
			'anonymous'    => array( 'anonymous', false ),
			'subscriber'   => array( 'subscriber', true ),
			'contributor'  => array( 'contributor', true ),
			'author'       => array( 'author', true ),
			'editor'       => array( 'editor', true ),
			'administrator' => array( 'administrator', false ),
		);
	}

	public function test_editor_with_directly_granted_bypass_is_not_restricted(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );
		$service->enable();

		$user_id = $this->login_as( 'editor' );
		( new \WP_User( $user_id ) )->add_cap( Installer::CAPABILITY );

		$this->assertFalse( $guard->is_restricted() );
	}

	public function test_inactive_freeze_never_restricts(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );

		$this->login_as( 'editor' );

		$this->assertFalse( $guard->is_restricted() );
	}

	public function test_cli_user_zero_is_restricted(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service, true );
		$service->enable();

		wp_set_current_user( 0 );

		$this->assertSame( 'cli', $guard->context() );
		$this->assertTrue( $guard->is_restricted() );
	}

	public function test_cli_user_with_bypass_is_not_restricted(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service, true );
		$service->enable();

		$user_id = $this->login_as( 'administrator' ); // Administrator already has bypass via install.
		$this->assertTrue( user_can( $user_id, Installer::CAPABILITY ) );

		$this->assertFalse( $guard->is_restricted() );
	}

	public function test_cli_user_without_bypass_is_restricted(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service, true );
		$service->enable();

		$this->login_as( 'editor' );

		$this->assertTrue( $guard->is_restricted() );
	}

	public function test_deployment_guard_is_restricted_filter_applies_to_cli_and_web_not_cron(): void {
		$service = $this->make_service( self::NOW );
		$service->enable();

		add_filter( 'deployment_guard_is_restricted', '__return_false' );

		$web_guard = $this->make_guard( $service, false );
		$this->login_as( 'editor' );
		$this->assertFalse( $web_guard->is_restricted() );

		$cli_guard = $this->make_guard( $service, true );
		$this->assertFalse( $cli_guard->is_restricted() );

		remove_filter( 'deployment_guard_is_restricted', '__return_false' );
	}

	public function test_cron_is_always_restricted_regardless_of_bypass_or_filter(): void {
		$service = $this->make_service( self::NOW );
		$service->enable();

		add_filter( 'deployment_guard_is_restricted', '__return_false' );
		add_filter( 'wp_doing_cron', '__return_true' );

		$user_id = $this->login_as( 'administrator' );
		$this->assertTrue( user_can( $user_id, Installer::CAPABILITY ) );

		$guard = $this->make_guard( $service, false );

		$this->assertSame( 'cron', $guard->context() );
		$this->assertTrue( $guard->is_restricted() );

		remove_filter( 'wp_doing_cron', '__return_true' );
		remove_filter( 'deployment_guard_is_restricted', '__return_false' );
	}

	public function test_suspension_lifts_restriction_only_while_active(): void {
		$service    = $this->make_service( self::NOW );
		$suspension = new \Digibold\DeploymentGuard\Suspension();
		$guard      = $this->make_guard( $service, true, $suspension );
		$service->enable();

		$this->login_as( 'editor' );

		$this->assertTrue( $guard->is_restricted() );

		$observed = $suspension->run( static fn () => $guard->is_restricted() );
		$this->assertFalse( $observed );

		$this->assertTrue( $guard->is_restricted() );
	}

	public function test_memo_resets_on_user_switch(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );
		$service->enable();

		$this->login_as( 'editor' );
		$this->assertTrue( $guard->is_restricted() );

		$this->login_as( 'administrator' );
		$this->assertFalse( $guard->is_restricted() );
	}

	public function test_memo_resets_on_state_change(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );

		$this->login_as( 'editor' );
		$this->assertFalse( $guard->is_restricted() );

		$service->enable();

		$this->assertTrue( $guard->is_restricted() );
	}

	public function test_default_protected_types_include_post_page_and_attachment(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );

		$types = $guard->protected_types()['post_types'];

		$this->assertContains( 'post', $types );
		$this->assertContains( 'page', $types );
		$this->assertContains( 'attachment', $types );
	}

	public function test_late_registered_post_type_is_picked_up_before_wp_loaded_caches(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );

		register_post_type( 'dg_late_cpt', array( 'public' => true, 'label' => 'Late CPT' ) );

		$this->assertContains( 'dg_late_cpt', $guard->protected_types()['post_types'] );

		unregister_post_type( 'dg_late_cpt' );
	}

	public function test_protected_types_filter_can_narrow_scope(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );

		add_filter(
			'deployment_guard_protected_types',
			static function () {
				return array(
					'post_types' => array( 'post' ),
					'taxonomies' => array(),
				);
			}
		);

		$this->assertSame( array( 'post' ), $guard->protected_types()['post_types'] );
		$this->assertSame( array(), $guard->protected_types()['taxonomies'] );

		remove_all_filters( 'deployment_guard_protected_types' );
	}

	public function test_applies_is_false_for_subscriber_on_protected_type(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );
		$service->enable();

		$this->login_as( 'subscriber' );

		$post_id = self::factory()->post->create();

		$this->assertFalse( $guard->applies_to_post( $post_id ) );
		$this->assertFalse( $guard->applies_to_post_type( 'post' ) );
	}

	public function test_applies_is_true_for_editor_on_protected_post(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );
		$service->enable();

		$this->login_as( 'editor' );

		$post_id = self::factory()->post->create();

		$this->assertTrue( $guard->applies_to_post( $post_id ) );
	}

	public function test_applies_is_false_when_inactive(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );

		$this->login_as( 'editor' );

		$post_id = self::factory()->post->create();

		$this->assertFalse( $guard->applies_to_post( $post_id ) );
	}

	public function test_applies_is_false_for_bypass_holder(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );
		$service->enable();

		$this->login_as( 'administrator' );

		$post_id = self::factory()->post->create();

		$this->assertFalse( $guard->applies_to_post( $post_id ) );
	}
}
