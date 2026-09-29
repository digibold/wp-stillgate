<?php
/**
 * Data-layer backstops: short-circuit the underlying write functions
 * themselves, regardless of which door was used to reach them.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Enforcement;

use Digibold\DeploymentGuard\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * The layer underneath AdminGate, AjaxGate, RestGate and XmlRpcGate: those
 * gates stop a *known* door before it is even opened, but WP-CLI, WP-Cron,
 * and third-party plugin code reach `wp_trash_post()`, `wp_insert_term()`
 * and similar functions directly, with no door to gate at all. This class
 * hooks the functions themselves.
 *
 * Two response styles, chosen by actor context:
 *  - In a real WP-CLI command (any command other than Deployment Guard's
 *    own — see Suspension), a blocked write ends the process immediately
 *    with `WP_CLI::error()`: a clear message and exit code 1, exactly as
 *    required for a deployment script to detect and stop on.
 *  - Everywhere else (a web request the other gates did not cover, or a
 *    WP-Cron event), the underlying core function is only made to report
 *    its own ordinary failure (`false`, a `WP_Error`, or, for uploads, a
 *    filled-in `$file['error']`). A cron RUN processes several due events
 *    in one pass; one blocked event must fail on its own without ending
 *    that pass, so unrelated events still run.
 */
final class DataLayerGuard {

	private Guard $guard;

	private bool $user_deletion_in_progress = false;

	public function __construct( Guard $guard ) {
		$this->guard = $guard;
	}

	public function register(): void {
		add_filter( 'pre_trash_post', array( $this, 'block_trash_post' ), 10, 2 );
		add_filter( 'pre_untrash_post', array( $this, 'block_untrash_post' ), 10, 2 );
		add_filter( 'pre_delete_post', array( $this, 'block_delete_post' ), 10, 2 );
		add_filter( 'pre_delete_attachment', array( $this, 'block_delete_attachment' ), 10, 2 );
		add_filter( 'pre_insert_term', array( $this, 'block_insert_term' ), 10, 2 );

		// wp_update_term() and wp_delete_term() have no filter that can
		// short-circuit them (only these two action hooks, fired before
		// either makes any change), so they can only be turned into a
		// block in CLI context, where WP_CLI::error() ends the process
		// before either function proceeds. On the web, AdminGate,
		// AjaxGate, RestGate and the capability layer already cover
		// every core path to updating or deleting a term; on cron,
		// updating or deleting an existing term through *this* path is a
		// documented limitation (see the term "edit_terms" callback).
		add_action( 'edit_terms', array( $this, 'guard_edit_terms' ), 10, 2 );
		add_action( 'pre_delete_term', array( $this, 'guard_pre_delete_term' ), 10, 2 );

		add_filter( 'wp_handle_upload_prefilter', array( $this, 'block_upload' ) );
		add_filter( 'wp_handle_sideload_prefilter', array( $this, 'block_upload' ) );
		add_filter( 'wp_insert_post_empty_content', array( $this, 'block_insert_post' ), PHP_INT_MAX, 2 );
		add_action( 'wp-mail.php', array( $this, 'block_post_by_email' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		// A post belonging to a deleted user is reassigned or removed as
		// part of that deletion; these backstops must not interfere with
		// WordPress's own account-deletion cleanup.
		add_action( 'delete_user', array( $this, 'on_user_deletion_start' ) );
		add_action( 'deleted_user', array( $this, 'on_user_deletion_end' ) );
	}

	public function on_user_deletion_start(): void {
		$this->user_deletion_in_progress = true;
	}

	public function on_user_deletion_end(): void {
		$this->user_deletion_in_progress = false;
	}

	/**
	 * @param mixed $check
	 */
	public function block_trash_post( $check, $post ) {
		if ( ! $this->skip_this_post( $post ) && $this->guard->applies_to_post( $post->ID ) ) {
			$this->report( 'trash_post:' . $post->ID );

			return false;
		}

		return $check;
	}

	/**
	 * @param mixed $check
	 */
	public function block_untrash_post( $check, $post ) {
		if ( ! $this->skip_this_post( $post ) && $this->guard->applies_to_post( $post->ID ) ) {
			$this->report( 'untrash_post:' . $post->ID );

			return false;
		}

		return $check;
	}

	/**
	 * @param mixed $check
	 */
	public function block_delete_post( $check, $post ) {
		if ( ! $this->skip_this_post( $post ) && $this->guard->applies_to_post( $post->ID ) ) {
			$this->report( 'delete_post:' . $post->ID );

			return false;
		}

		return $check;
	}

	/**
	 * @param mixed $check
	 */
	public function block_delete_attachment( $check, $post ) {
		if ( ! $this->skip_this_post( $post ) && $this->guard->applies_to_post( $post->ID ) ) {
			$this->report( 'delete_attachment:' . $post->ID );

			return false;
		}

		return $check;
	}

	/**
	 * @param mixed $term
	 */
	public function block_insert_term( $term, $taxonomy ) {
		if ( is_wp_error( $term ) || ! is_string( $taxonomy ) ) {
			return $term;
		}

		if ( ! $this->guard->applies_to_taxonomy( $taxonomy, 'assign' ) ) {
			return $term;
		}

		$this->report( 'insert_term:' . $taxonomy );

		return new \WP_Error( 'deployment_guard_active', $this->guard->message_text() );
	}

	/**
	 * @param mixed $term_id
	 * @param mixed $taxonomy
	 */
	public function guard_edit_terms( $term_id, $taxonomy ): void {
		if ( 'cli' !== $this->guard->context() || ! is_string( $taxonomy ) ) {
			return;
		}

		if ( ! $this->guard->applies_to_term( (int) $term_id, 'edit' ) ) {
			return;
		}

		$this->report( 'update_term:' . $taxonomy . ':' . $term_id );
	}

	/**
	 * @param mixed $term
	 * @param mixed $taxonomy
	 */
	public function guard_pre_delete_term( $term, $taxonomy ): void {
		if ( 'cli' !== $this->guard->context() || ! is_string( $taxonomy ) ) {
			return;
		}

		if ( ! $this->guard->applies_to_term( (int) $term, 'edit' ) ) {
			return;
		}

		$this->report( 'delete_term:' . $taxonomy . ':' . $term );
	}

	/**
	 * @param array $file
	 * @return array
	 */
	public function block_upload( $file ) {
		if ( ! is_array( $file ) || ! $this->upload_backstop_applies() ) {
			return $file;
		}

		if ( ! $this->guard->applies_to_post_type( 'attachment' ) ) {
			return $file;
		}

		$this->report( 'upload' );

		$file['error'] = $this->guard->message_text();

		return $file;
	}

	/**
	 * @param mixed $maybe_empty
	 * @param mixed $postarr
	 * @return mixed
	 */
	public function block_insert_post( $maybe_empty, $postarr ) {
		// Scoped to CLI and cron only: every core web path into
		// wp_insert_post() is already covered by AdminGate, AjaxGate,
		// RestGate and CapabilityFilter, and applying this broadly on the
		// web risks false positives against core's own internal
		// empty-content inserts (auto-drafts, revisions). Third-party web
		// code that calls wp_insert_post() without any capability check is
		// a documented limitation.
		if ( 'web' === $this->guard->context() ) {
			return $maybe_empty;
		}

		if ( ! is_array( $postarr ) ) {
			return $maybe_empty;
		}

		$post_type = $this->resolve_post_type( $postarr );

		if ( '' === $post_type ) {
			return $maybe_empty;
		}

		// WordPress creates these automatically while rendering, with no
		// user action involved; never block that creation. Changing one
		// that already exists is an ordinary protected write.
		if ( empty( $postarr['ID'] ) && in_array( $post_type, array( 'wp_global_styles', 'wp_navigation' ), true ) ) {
			return $maybe_empty;
		}

		if ( ! $this->guard->applies_to_post_type( $post_type ) ) {
			return $maybe_empty;
		}

		$this->report( 'insert_post:' . $post_type );

		// A truthy return here always short-circuits wp_insert_post(),
		// regardless of $maybe_empty's own value.
		return true;
	}

	public function block_post_by_email(): void {
		// Post-by-email runs as user 0 (no logged-in actor at all), so the
		// normal capability-based is_restricted() check does not apply
		// here; whether the freeze is active is the only relevant fact.
		if ( ! $this->guard->is_active() || ! $this->guard->is_protected_post_type( 'post' ) ) {
			return;
		}

		$this->guard->report( 'mail', 'wp-mail.php' );

		wp_die(
			esc_html( $this->guard->message_text() ),
			esc_html__( 'Deployment freeze active', 'deployment-guard' ),
			array( 'response' => 423 )
		);
	}

	private function skip_this_post( $post ): bool {
		if ( ! $post instanceof \WP_Post ) {
			return true;
		}

		// Revisions are not skipped: Guard::applies_to_post() resolves one
		// to its parent, so deleting a revision of protected content is
		// blocked exactly like changing that content.
		return $this->user_deletion_in_progress;
	}

	/**
	 * @param array $postarr
	 */
	private function resolve_post_type( array $postarr ): string {
		if ( ! empty( $postarr['post_type'] ) && is_string( $postarr['post_type'] ) ) {
			return $postarr['post_type'];
		}

		if ( ! empty( $postarr['ID'] ) ) {
			$existing = get_post( (int) $postarr['ID'] );

			if ( $existing ) {
				return $existing->post_type;
			}
		}

		return '';
	}

	/**
	 * Whether the upload prefilter backstop should run at all: always in
	 * CLI/cron, and on the web only outside the frontend (admin screens,
	 * admin-ajax.php, admin-post.php all satisfy is_admin()) and never on
	 * update.php, which handles plugin/theme ZIP uploads rather than media.
	 */
	private function upload_backstop_applies(): bool {
		if ( 'web' !== $this->guard->context() ) {
			return true;
		}

		global $pagenow;

		if ( 'update.php' === $pagenow ) {
			return false;
		}

		return is_admin();
	}

	private function report( string $target ): void {
		$context = $this->guard->context();
		$vector  = 'cli' === $context ? 'cli' : ( 'cron' === $context ? 'cron' : 'data' );

		$this->guard->report( $vector, $target );

		if ( 'cli' === $context ) {
			\WP_CLI::error( $this->cli_message() );
		}
	}

	private function cli_message(): string {
		return sprintf(
			/* translators: %s: the reason the write was blocked. */
			__( 'Deployment Guard: %s', 'deployment-guard' ),
			$this->guard->message_text()
		);
	}
}
