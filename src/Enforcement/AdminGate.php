<?php
/**
 * wp-admin screen gates.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Enforcement;

use Digibold\DeploymentGuard\Guard;
use Digibold\DeploymentGuard\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Stops a restricted user from reaching a classic wp-admin screen that
 * would change protected content, before core even renders the screen.
 *
 * This is the layer that gives a restricted user a clear explanation
 * instead of a confusing partial failure: every gate here fires on a
 * `load-{$pagenow}` hook (or, for the Custom Header and Custom Background
 * screens, on their own `load-{$page_hook}`), at a priority low enough to
 * run before the screen does any of its own work, and ends the request
 * with a 423 `wp_die()` carrying the freeze message.
 *
 * Every check re-resolves the real type of whatever object ID a request
 * references (rather than trusting a `post_type` query argument, say),
 * which is what closes tricks like using Bulk Edit on an unprotected post
 * type's list screen to still reach a protected post's ID.
 */
final class AdminGate {

	private Guard $guard;

	public function __construct( Guard $guard ) {
		$this->guard = $guard;
	}

	public function register(): void {
		if ( is_network_admin() || is_user_admin() ) {
			// Network and User admin reuse some of the same $pagenow
			// values (network's edit.php, for instance) for entirely
			// different, network-level screens; none of what this class
			// guards applies there.
			return;
		}

		add_action( 'load-post-new.php', array( $this, 'guard_post_new' ), 1 );
		add_action( 'load-post.php', array( $this, 'guard_post' ), 1 );
		add_action( 'load-edit.php', array( $this, 'guard_edit' ), 1 );
		add_action( 'load-revision.php', array( $this, 'guard_revision' ), 1 );
		add_action( 'load-edit-tags.php', array( $this, 'guard_edit_tags' ), 1 );
		add_action( 'load-upload.php', array( $this, 'guard_upload' ), 1 );
		add_action( 'load-media-new.php', array( $this, 'guard_media_screen' ), 1 );
		add_action( 'load-media-upload.php', array( $this, 'guard_media_screen' ), 1 );
		add_action( 'load-async-upload.php', array( $this, 'guard_media_screen' ), 1 );
		add_action( 'load-press-this.php', array( $this, 'guard_press_this' ), 1 );
		add_action( 'load-options-privacy.php', array( $this, 'guard_privacy_policy_page' ), 1 );

		add_action(
			'load-nav-menus.php',
			function (): void {
				$this->guard_surface( 'nav-menus.php' );
			},
			1
		);
		add_action(
			'load-widgets.php',
			function (): void {
				$this->guard_surface( 'widgets.php' );
			},
			1
		);
		add_action(
			'load-customize.php',
			function (): void {
				$this->guard_surface( 'customize.php' );
			},
			1
		);
		add_action(
			'load-site-editor.php',
			function (): void {
				$this->guard_surface( 'site-editor.php' );
			},
			1
		);

		add_action( 'admin_menu', array( $this, 'register_theme_page_gates' ), PHP_INT_MAX );
		add_action( 'wp_dashboard_setup', array( $this, 'remove_quick_draft' ) );
	}

	public function guard_post_new(): void {
		$post_type = Input::key( 'post_type', 'GET' );

		if ( '' === $post_type ) {
			$post_type = 'post';
		}

		if ( $this->guard->applies_to_post_type( $post_type ) ) {
			$this->deny( 'post-new.php:' . $post_type );
		}
	}

	/**
	 * Blocks the entire post.php screen for a protected post, for every
	 * action (edit, trash, untrash, delete, preview, editattachment, a
	 * lock takeover): opening the editor is itself what this plugin
	 * prevents for restricted users, not only saving.
	 */
	public function guard_post(): void {
		$post_id = Input::int( 'post', 'GET' );

		if ( 0 === $post_id ) {
			$post_id = Input::int( 'post_ID', 'POST' );
		}

		if ( 0 === $post_id ) {
			return;
		}

		if ( $this->guard->applies_to_post( $post_id ) ) {
			$this->deny( 'post.php:' . $post_id );
		}
	}

	public function guard_edit(): void {
		global $pagenow;

		if ( 'edit.php' !== $pagenow ) {
			return;
		}

		if ( ! $this->has_write_action() ) {
			return;
		}

		$post_type = Input::key( 'post_type', 'REQUEST' );

		if ( '' === $post_type ) {
			$post_type = 'post';
		}

		if ( $this->guard->applies_to_post_type( $post_type ) ) {
			$this->deny( 'edit.php:' . $post_type );
		}

		$ids = Input::ids( 'post' );

		if ( ! $ids ) {
			$ids = Input::ids( 'ids' );
		}

		foreach ( $ids as $id ) {
			if ( $this->guard->applies_to_post( $id ) ) {
				$this->deny( 'edit.php:post:' . $id );
			}
		}
	}

	public function guard_revision(): void {
		global $pagenow;

		if ( 'revision.php' !== $pagenow ) {
			return;
		}

		if ( 'restore' !== Input::string( 'action' ) ) {
			return;
		}

		$revision_id = Input::int( 'revision' );

		if ( 0 === $revision_id ) {
			$revision_id = Input::int( 'to' );
		}

		if ( 0 === $revision_id ) {
			return;
		}

		$revision = wp_get_post_revision( $revision_id );

		if ( ! $revision ) {
			return;
		}

		if ( $this->guard->applies_to_post( (int) $revision->post_parent ) ) {
			$this->deny( 'revision.php:restore:' . $revision->post_parent );
		}
	}

	public function guard_edit_tags(): void {
		global $pagenow;

		if ( 'edit-tags.php' !== $pagenow ) {
			// term.php also fires load-edit-tags.php for backward
			// compatibility; that screen only ever displays a single
			// term for editing (a GET request) and stays readable.
			return;
		}

		$is_write = $this->has_write_action() || array() !== Input::ids( 'delete_tags' );

		if ( ! $is_write ) {
			return;
		}

		$taxonomy = Input::key( 'taxonomy' );

		if ( '' === $taxonomy ) {
			return;
		}

		if ( $this->guard->applies_to_taxonomy( $taxonomy, 'edit' ) ) {
			$this->deny( 'edit-tags.php:' . $taxonomy );
		}
	}

	public function guard_upload(): void {
		global $pagenow;

		if ( 'upload.php' !== $pagenow ) {
			return;
		}

		$is_write = $this->has_write_action();

		// The media list table's own attach/detach controls send
		// media[]=<id>&media[]=<id> as a real array, which Input::string()
		// (scalars only) never sees; present() recognizes either shape.
		if ( ! $is_write && Input::present( 'media' ) ) {
			$is_write = Input::present( 'found_post_id' ) || Input::present( 'parent_post_id' );
		}

		if ( ! $is_write ) {
			return;
		}

		if ( $this->guard->applies_to_post_type( 'attachment' ) ) {
			$this->deny( 'upload.php' );
		}
	}

	public function guard_media_screen(): void {
		if ( ! Input::is_post() ) {
			return;
		}

		if ( $this->guard->applies_to_post_type( 'attachment' ) ) {
			$this->deny( 'media-upload' );
		}
	}

	public function guard_press_this(): void {
		if ( $this->guard->applies_to_post_type( 'post' ) ) {
			$this->deny( 'press-this.php' );
		}
	}

	/**
	 * Settings > Privacy can create a draft Privacy Policy page, and core
	 * checks nothing but `manage_privacy_options` before doing so — no
	 * content capability that the capability layer could deny. The
	 * primitive capability check other gates use does not apply for the
	 * same reason: anyone who reaches this action can create the page.
	 */
	public function guard_privacy_policy_page(): void {
		if ( ! Input::is_post() || 'create-privacy-page' !== Input::key( 'action', 'POST' ) ) {
			return;
		}

		if ( $this->guard->is_protected_post_type( 'page' ) && $this->guard->is_restricted() ) {
			$this->deny( 'options-privacy.php:create-privacy-page' );
		}
	}

	public function guard_surface( string $target ): void {
		if ( $this->guard->applies_to_surface() ) {
			$this->deny( $target );
		}
	}

	/**
	 * Gates the classic Custom Header and Custom Background screens, once
	 * the admin menu that registers them exists. Each screen's own
	 * `load-{$page_hook}` at priority 1 runs ahead of both core handlers
	 * (the header screen saves on `admin_head-{$page_hook}`, the background
	 * screen on `load-{$page_hook}` at priority 49), and fires for every URL
	 * core itself resolves to these screens — `themes.php?page=...`,
	 * `admin.php?page=...`, a trailing `.php` — for GET and POST alike (the
	 * header screen's cropping step saves on a GET request).
	 */
	public function register_theme_page_gates(): void {
		if ( ! function_exists( 'get_plugin_page_hookname' ) ) {
			return;
		}

		foreach ( array( 'custom-header', 'custom-background' ) as $slug ) {
			add_action(
				'load-' . get_plugin_page_hookname( $slug, 'themes.php' ),
				function () use ( $slug ): void {
					$this->guard_surface( 'themes.php:' . $slug );
				},
				1
			);
		}
	}

	/**
	 * Hides the Dashboard's "Quick Draft" box while posts are protected:
	 * it always creates an auto-draft post as soon as the box loads,
	 * before any explicit save action exists to gate.
	 */
	public function remove_quick_draft(): void {
		if ( $this->guard->applies_to_post_type( 'post' ) ) {
			remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
		}
	}

	/**
	 * Whether the request carries one of the generic list-table write
	 * signals: a bulk action selected (and not just the "Filter" button),
	 * a "Empty Trash" submit, or the Bulk Edit "Update" submit. Shared by
	 * every classic list screen (edit.php, edit-tags.php, upload.php).
	 *
	 * "Empty Trash" is checked first, exactly the order core's own list
	 * tables use (an `isset()` check on `delete_all`/`delete_all2`, ahead
	 * of anything about `filter_action`): a real browser submission can
	 * never carry both `filter_action` and `delete_all` at once (only one
	 * submit button's name/value is ever sent), but a hand-crafted request
	 * could, and checking `filter_action` first would let it skip this
	 * gate while core still processed the delete.
	 */
	private function has_write_action(): bool {
		if ( Input::present( 'delete_all' ) || Input::present( 'delete_all2' ) ) {
			return true;
		}

		if ( '' !== Input::string( 'filter_action' ) ) {
			return false;
		}

		$action = Input::string( 'action' );

		if ( '' !== $action && '-1' !== $action ) {
			return true;
		}

		if ( '' !== Input::string( 'bulk_edit' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * End the request with a 423 explanation and record the attempt.
	 */
	private function deny( string $target ): void {
		$this->guard->report( 'admin', $target );

		wp_die(
			'<h1>' . esc_html__( 'Editing is temporarily locked', 'stillgate' ) . '</h1>' . wp_kses_post( $this->guard->message_html() ),
			esc_html__( 'Deployment freeze active', 'stillgate' ),
			array(
				'response'  => 423,
				'back_link' => true,
			)
		);

		exit; // wp_die() may not exit when a custom die handler is installed.
	}
}
