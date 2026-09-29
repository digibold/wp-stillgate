<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Enforcement\ScheduledPublishGuard;
use Digibold\DeploymentGuard\Installer;

/**
 * ScheduledPublishGuard reads the real wall clock (it wraps a cron
 * callback that core itself schedules against real time), so — unlike
 * every other test class here — these tests use the actual current time
 * rather than an injected fixed clock, and build post dates relative to
 * it, to stay consistent with what the class under test will observe.
 */
final class ScheduledPublishTest extends TestCase {

	private function install( ?\Closure $clock = null ): array {
		$service = $this->make_service( 0 );
		$guard   = $this->make_guard( $service );
		$sp      = new ScheduledPublishGuard( $service, $guard );
		$sp->maybe_install();

		return array( $service, $guard, $sp );
	}

	private function reset_publish_future_post_hook(): void {
		remove_all_actions( 'publish_future_post' );
		add_action( 'publish_future_post', 'check_and_publish_future_post', 10, 1 );
	}

	/**
	 * Creates a post genuinely scheduled ("future") for $due_at, even
	 * when $due_at is in the past. wp_insert_post() itself would silently
	 * publish a "future" post whose date is not actually in the future
	 * (sensible for its own callers), which would defeat the purpose of
	 * this fixture; a direct backdate after creation sidesteps that,
	 * matching what a genuinely missed cron run looks like in the
	 * database.
	 */
	private function create_due_future_post( int $due_at, string $post_type = 'post' ): int {
		global $wpdb;

		$post_id = self::factory()->post->create(
			array(
				'post_type'     => $post_type,
				'post_status'   => 'future',
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			)
		);

		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => gmdate( 'Y-m-d H:i:s', $due_at ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $due_at ),
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );

		return $post_id;
	}

	public function test_swap_only_installs_over_cores_own_callback(): void {
		$this->reset_publish_future_post_hook();

		list( , , $sp ) = $this->install();

		$this->assertFalse( has_action( 'publish_future_post', 'check_and_publish_future_post' ) );
		$this->assertSame( 10, has_action( 'publish_future_post', array( $sp, 'publish' ) ) );

		$this->reset_publish_future_post_hook();
	}

	public function test_swap_is_skipped_when_another_plugin_already_replaced_the_publisher(): void {
		$this->reset_publish_future_post_hook();
		remove_action( 'publish_future_post', 'check_and_publish_future_post', 10 );
		add_action( 'publish_future_post', '__return_null', 10 );

		list( , , $sp ) = $this->install();

		$this->assertSame( 10, has_action( 'publish_future_post', '__return_null' ) );

		remove_action( 'publish_future_post', '__return_null', 10 );
		$this->reset_publish_future_post_hook();
	}

	public function test_swap_is_installed_before_wp_loaded_where_alternate_wp_cron_runs_events(): void {
		// wp_cron() (hooked on init) hooks _wp_cron() onto wp_loaded at
		// priority 20. With ALTERNATE_WP_CRON, _wp_cron() includes
		// wp-cron.php and runs every due event inside that same request, so
		// the swap must already be in place before wp_loaded starts.
		$service = $this->make_service( 0 );
		$guard   = $this->make_guard( $service );
		$sp      = new ScheduledPublishGuard( $service, $guard );
		$sp->register();

		$this->assertSame( 10, has_action( 'init', 'wp_cron' ) );
		$this->assertNotFalse( has_action( 'init', array( $sp, 'maybe_install' ) ) );
		$this->assertFalse( has_action( 'wp_loaded', array( $sp, 'maybe_install' ) ) );
	}

	public function test_due_protected_post_is_held_during_active_freeze(): void {
		list( $service, , $sp ) = $this->install();
		$service->enable();

		$now     = time();
		$post_id = $this->create_due_future_post( $now - 10 );

		$sp->publish( $post_id );

		$this->assertSame( 'future', get_post_status( $post_id ) );

		$next = wp_next_scheduled( 'publish_future_post', array( $post_id ) );
		$this->assertNotFalse( $next );
		$this->assertGreaterThan( $now, $next );
		$this->assertLessThanOrEqual( $now + 300, $next );

		$entries = $service->entries();
		$this->assertSame( 'blocked', $entries[0]['type'] );
		$this->assertSame( 'cron', $entries[0]['ctx']['vector'] );
	}

	public function test_unrelated_event_and_non_public_type_still_run_in_the_same_pass(): void {
		list( $service, , $sp ) = $this->install();
		$service->enable();

		register_post_type( 'dg_private_cpt', array( 'public' => false, 'show_ui' => true, 'label' => 'Private', 'capability_type' => 'post', 'map_meta_cap' => true ) );

		$now            = time();
		$protected_id   = $this->create_due_future_post( $now - 10 );
		$unprotected_id = $this->create_due_future_post( $now - 10, 'dg_private_cpt' );

		update_option( 'dg_probe_ran', false );
		add_action( 'dg_probe_event', static fn () => update_option( 'dg_probe_ran', true ) );
		do_action( 'dg_probe_event' );

		$sp->publish( $protected_id );
		$sp->publish( $unprotected_id );

		$this->assertSame( 'future', get_post_status( $protected_id ) );
		$this->assertSame( 'publish', get_post_status( $unprotected_id ) );
		$this->assertTrue( get_option( 'dg_probe_ran' ) );

		unregister_post_type( 'dg_private_cpt' );
	}

	public function test_retry_is_capped_at_a_sooner_scheduled_freeze_end(): void {
		list( $service, , $sp ) = $this->install();

		$now = time();
		$service->schedule( $now - 100, $now + 90 );

		$post_id = $this->create_due_future_post( $now - 10 );

		$sp->publish( $post_id );

		$next = wp_next_scheduled( 'publish_future_post', array( $post_id ) );
		$this->assertSame( $now + 90, $next );
	}

	public function test_post_publishes_after_freeze_ends(): void {
		list( $service, , $sp ) = $this->install();
		$service->enable();

		$now     = time();
		$post_id = $this->create_due_future_post( $now - 10 );
		$sp->publish( $post_id );
		$this->assertSame( 'future', get_post_status( $post_id ) );

		$service->disable();

		$fired = false;
		add_action(
			'transition_post_status',
			static function ( $new, $old ) use ( &$fired ) {
				if ( 'publish' === $new ) {
					$fired = true;
				}
			},
			10,
			2
		);

		$sp->publish( $post_id );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertTrue( $fired );

		$entries       = $service->entries();
		$blocked_count = count( array_filter( $entries, static fn ( $e ) => 'blocked' === $e['type'] ) );
		$this->assertSame( 1, $blocked_count ); // Only the earlier held attempt, not this successful one.
	}

	public function test_not_yet_due_post_is_delegated_to_core_unchanged(): void {
		list( $service, , $sp ) = $this->install();
		$service->enable();

		$post_id = $this->create_due_future_post( time() + 1000 );

		$sp->publish( $post_id );

		$this->assertSame( 'future', get_post_status( $post_id ) );

		$blocked = array_filter( $service->entries(), static fn ( $e ) => 'blocked' === $e['type'] );
		$this->assertSame( array(), $blocked ); // Not our doing; core's own future-scheduling ran.
	}

	public function test_bypass_administrator_cannot_open_cron_as_a_write_path(): void {
		list( $service, , $sp ) = $this->install();
		$service->enable();

		$admin_id = $this->login_as( 'administrator' );
		$this->assertTrue( user_can( $admin_id, Installer::CAPABILITY ) );

		$post_id = $this->create_due_future_post( time() - 10 );
		$sp->publish( $post_id );

		$this->assertSame( 'future', get_post_status( $post_id ) );
	}

	public function test_is_restricted_filter_returning_false_does_not_open_cron(): void {
		list( $service, $guard, $sp ) = $this->install();
		$service->enable();

		add_filter( 'deployment_guard_is_restricted', '__return_false' );

		$post_id = $this->create_due_future_post( time() - 10 );
		$sp->publish( $post_id );

		$this->assertSame( 'future', get_post_status( $post_id ) );

		remove_filter( 'deployment_guard_is_restricted', '__return_false' );
	}

	public function test_cron_context_data_layer_writes_fail_gracefully_not_via_cli_exit(): void {
		// Create the fixture, and enable the freeze, before switching into
		// cron context: otherwise the post creation itself would also be
		// caught by the very backstop under test here.
		$post_id = self::factory()->post->create();

		list( $service ) = $this->install();
		$guard = $this->make_guard( $service, false );
		( new \Digibold\DeploymentGuard\Enforcement\DataLayerGuard( $guard ) )->register();
		$service->enable();

		add_filter( 'wp_doing_cron', '__return_true' );

		$this->assertFalse( wp_trash_post( $post_id ) );
		$this->assertSame( 'publish', get_post_status( $post_id ) );

		remove_filter( 'wp_doing_cron', '__return_true' );
	}
}
