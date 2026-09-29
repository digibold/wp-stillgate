<?php
/**
 * Holds scheduled publication of protected content during a freeze.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Enforcement;

use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Prevents a scheduled ("future") post of a protected type from
 * transitioning to "publish" while a freeze is active, without touching
 * WP-Cron itself or any other scheduled event.
 *
 * Deployment Guard's purpose is to stop protected content from changing
 * in the database during a deployment; core's own `publish_future_post`
 * cron event does exactly that, on a timer, independent of any user or
 * capability. Nothing else in this plugin can intercept it: it runs as
 * user 0, so Guard::is_restricted() would (correctly, for every other
 * purpose) treat it as unrestricted, and `wp_publish_post()` writes the
 * new status with a direct `$wpdb->update()` call that has no filterable
 * hook of its own. This class stands in front of the process instead.
 *
 * At the very end of `init`, once, it replaces core's own
 * `check_and_publish_future_post` callback with its own — but only if that
 * callback is still registered at its original priority; if another plugin
 * has already replaced it, this class steps aside rather than risk layering
 * two publishers on top of each other. When a due post would be held, it is
 * rescheduled a few minutes out (or exactly at a scheduled freeze's end, if
 * that comes sooner) and left as "future"; every other due event in the same
 * cron run, and every other post, proceeds through core's own function
 * completely unmodified.
 */
final class ScheduledPublishGuard {

	private const RETRY_DELAY = 5 * MINUTE_IN_SECONDS;

	private FreezeService $service;

	private Guard $guard;

	public function __construct( FreezeService $service, Guard $guard ) {
		$this->service = $service;
		$this->guard   = $guard;
	}

	public function register(): void {
		// Late enough in init that another plugin replacing core's
		// publisher has already done so, yet still before wp_loaded: that
		// is where wp_cron() runs _wp_cron() (priority 20), which, with
		// ALTERNATE_WP_CRON, runs every due event inside the same request.
		add_action( 'init', array( $this, 'maybe_install' ), PHP_INT_MAX );
	}

	public function maybe_install(): void {
		if ( 10 !== has_action( 'publish_future_post', 'check_and_publish_future_post' ) ) {
			return;
		}

		remove_action( 'publish_future_post', 'check_and_publish_future_post', 10 );
		add_action( 'publish_future_post', array( $this, 'publish' ), 10 );
	}

	/**
	 * @param mixed $post_id
	 */
	public function publish( $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'future' !== $post->post_status ) {
			check_and_publish_future_post( $post_id );
			return;
		}

		$due = strtotime( $post->post_date_gmt . ' GMT' );

		if ( false === $due || $due > time() ) {
			// Not due yet (or an unparseable date, which core's own
			// function is left to make sense of): let core reschedule it.
			check_and_publish_future_post( $post_id );
			return;
		}

		if ( $this->should_hold( $post ) ) {
			$this->hold( (int) $post->ID, $post->post_type );
			return;
		}

		check_and_publish_future_post( $post_id );
	}

	private function should_hold( \WP_Post $post ): bool {
		if ( ! $this->service->is_active() ) {
			return false;
		}

		if ( 'customize_changeset' === $post->post_type ) {
			// Not in Guard's protected-types list (the Customizer screen
			// is protected directly instead), but a changeset publishing
			// on schedule writes theme mods and options the same as any
			// other protected design-surface change.
			return true;
		}

		return $this->guard->is_protected_post_type( $post->post_type );
	}

	private function hold( int $post_id, string $post_type ): void {
		$retry   = time() + self::RETRY_DELAY;
		$ends_at = $this->service->ends_at();

		if ( null !== $ends_at && $ends_at > time() && $ends_at < $retry ) {
			$retry = $ends_at;
		}

		wp_clear_scheduled_hook( 'publish_future_post', array( $post_id ) );
		wp_schedule_single_event( $retry, 'publish_future_post', array( $post_id ) );

		$this->guard->report( 'cron', 'publish_future_post:' . $post_type, array( 'object_id' => $post_id ) );
	}
}
