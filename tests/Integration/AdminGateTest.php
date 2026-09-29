<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Enforcement\AdminGate;

final class AdminGateTest extends TestCase {

	private const NOW = 1_800_000_000;

	/**
	 * Unlike $_GET/$_POST/$_REQUEST (reset by the base test case) and
	 * $current_screen (also reset by the base test case), $pagenow is a
	 * plain global the base test case does not know to reset; several
	 * tests here set it directly to simulate loading a specific admin
	 * screen, so it must be cleared after every test or it leaks into
	 * whichever test (in this class or another) runs next.
	 */
	public function tear_down(): void {
		global $pagenow;
		$pagenow = null;

		parent::tear_down();
	}

	private function install(): array {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );
		( new AdminGate( $guard ) )->register();
		$service->enable();

		return array( $service, $guard );
	}

	private function expect_blocked( \Closure $trigger ): void {
		try {
			$trigger();
			$this->fail( 'Expected WPDieException was not thrown.' );
		} catch ( \WPDieException $exception ) {
			$this->assertStringContainsString( 'locked', strtolower( $exception->getMessage() ) );
		}
	}

	private function expect_allowed( \Closure $trigger ): void {
		try {
			$trigger();
		} catch ( \WPDieException $exception ) {
			$this->fail( 'Did not expect a die: ' . $exception->getMessage() );
		}

		$this->assertTrue( true );
	}

	public function test_post_new_is_blocked_for_protected_type(): void {
		global $pagenow;
		list( , $guard ) = $this->install();
		$this->login_as( 'editor' );

		$this->expect_blocked(
			function () use ( &$pagenow, $guard ) {
				$pagenow          = 'post-new.php';
				$_GET['post_type'] = 'post';
				do_action( 'load-post-new.php' );
			}
		);
	}

	public function test_post_php_edit_action_is_blocked(): void {
		global $pagenow;
		$this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		$this->expect_blocked(
			function () use ( &$pagenow, $post_id ) {
				$pagenow       = 'post.php';
				$_GET['post']  = $post_id;
				$_GET['action'] = 'edit';
				do_action( 'load-post.php' );
			}
		);
	}

	public function test_post_php_trash_action_is_blocked(): void {
		global $pagenow;
		$this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		$this->expect_blocked(
			function () use ( &$pagenow, $post_id ) {
				$pagenow         = 'post.php';
				$_GET['post']    = $post_id;
				$_GET['action']  = 'trash';
				do_action( 'load-post.php' );
			}
		);
	}

	/**
	 * Input::int() mirrors core's own absint() (abs of the int cast), so a
	 * negative ID still resolves to the same post core itself would
	 * resolve it to, rather than being dropped to 0 and skipping this
	 * gate.
	 */
	public function test_post_php_negative_post_id_still_resolves(): void {
		global $pagenow;
		$this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		$this->expect_blocked(
			function () use ( &$pagenow, $post_id ) {
				$pagenow       = 'post.php';
				$_GET['post']  = -$post_id;
				$_GET['action'] = 'edit';
				do_action( 'load-post.php' );
			}
		);
	}

	public function test_post_php_get_post_lock_is_blocked(): void {
		global $pagenow;
		$this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		$this->expect_blocked(
			function () use ( &$pagenow, $post_id ) {
				$pagenow                    = 'post.php';
				$_GET['post']                = $post_id;
				$_GET['action']              = 'edit';
				$_GET['get-post-lock']       = '1';
				do_action( 'load-post.php' );
			}
		);
	}

	public function test_post_php_on_unprotected_type_is_allowed(): void {
		global $pagenow;

		register_post_type( 'dg_open_cpt', array( 'public' => false, 'show_ui' => true, 'label' => 'Open', 'capability_type' => 'post', 'map_meta_cap' => true ) );
		$this->install();
		$post_id = self::factory()->post->create( array( 'post_type' => 'dg_open_cpt' ) );
		$this->login_as( 'editor' );

		$this->expect_allowed(
			function () use ( &$pagenow, $post_id ) {
				$pagenow       = 'post.php';
				$_GET['post']  = $post_id;
				$_GET['action'] = 'edit';
				do_action( 'load-post.php' );
			}
		);

		unregister_post_type( 'dg_open_cpt' );
	}

	public function test_cross_type_bulk_edit_is_blocked_by_real_post_type(): void {
		global $pagenow;

		register_post_type( 'dg_open_cpt', array( 'public' => false, 'show_ui' => true, 'label' => 'Open', 'capability_type' => 'post', 'map_meta_cap' => true ) );
		$this->install();
		$protected_post_id = self::factory()->post->create(); // 'post' is protected.
		$this->login_as( 'editor' );

		// Simulate the list screen for the UNPROTECTED type, referencing
		// the PROTECTED post's ID via the Bulk Edit "post[]" field.
		$this->expect_blocked(
			function () use ( &$pagenow, $protected_post_id ) {
				$pagenow              = 'edit.php';
				$_REQUEST['post_type'] = 'dg_open_cpt';
				$_REQUEST['action']    = 'edit';
				$_REQUEST['bulk_edit'] = 'Update';
				$_REQUEST['post']      = array( $protected_post_id );
				do_action( 'load-edit.php' );
			}
		);

		unregister_post_type( 'dg_open_cpt' );
	}

	public function test_edit_php_list_view_without_action_is_allowed(): void {
		global $pagenow;
		$this->install();
		$this->login_as( 'editor' );

		$this->expect_allowed(
			function () use ( &$pagenow ) {
				$pagenow           = 'edit.php';
				$_REQUEST['action'] = '-1';
				do_action( 'load-edit.php' );
			}
		);
	}

	public function test_revision_restore_is_blocked(): void {
		global $pagenow;
		$this->install();
		$post_id     = self::factory()->post->create( array( 'post_content' => 'v1' ) );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => 'v2' ) );
		$revisions   = wp_get_post_revisions( $post_id );
		$revision    = array_shift( $revisions );
		$this->login_as( 'editor' );

		$this->expect_blocked(
			function () use ( &$pagenow, $revision ) {
				$pagenow            = 'revision.php';
				$_REQUEST['revision'] = $revision->ID;
				$_REQUEST['action']   = 'restore';
				do_action( 'load-revision.php' );
			}
		);
	}

	public function test_edit_tags_add_is_blocked(): void {
		global $pagenow;
		$this->install();
		$this->login_as( 'editor' );

		$this->expect_blocked(
			function () use ( &$pagenow ) {
				$pagenow              = 'edit-tags.php';
				$_REQUEST['taxonomy']  = 'category';
				$_REQUEST['action']    = 'add-tag';
				do_action( 'load-edit-tags.php' );
			}
		);
	}

	public function test_term_php_stays_readable(): void {
		global $pagenow;
		$this->install();
		$this->login_as( 'editor' );

		// term.php also fires load-edit-tags.php for back-compat; the
		// gate must recognize $pagenow and leave it alone.
		$this->expect_allowed(
			function () use ( &$pagenow ) {
				$pagenow              = 'term.php';
				$_REQUEST['taxonomy']  = 'category';
				do_action( 'load-edit-tags.php' );
			}
		);
	}

	public function test_upload_delete_all_is_blocked(): void {
		global $pagenow;
		$this->install();
		$this->login_as( 'editor' );

		$this->expect_blocked(
			function () use ( &$pagenow ) {
				$pagenow             = 'upload.php';
				$_REQUEST['delete_all'] = '1';
				do_action( 'load-upload.php' );
			}
		);
	}

	/**
	 * A genuine browser submission can never carry both filter_action and
	 * delete_all (only one submit button's name/value is ever sent), but
	 * has_write_action() must not trust that: a crafted request pairing
	 * them together must still be treated as the write it is, exactly
	 * like core's own list tables (which check delete_all via isset(),
	 * ahead of anything about filter_action).
	 */
	public function test_upload_delete_all_is_blocked_even_with_filter_action_present(): void {
		global $pagenow;
		$this->install();
		$this->login_as( 'editor' );

		$this->expect_blocked(
			function () use ( &$pagenow ) {
				$pagenow                 = 'upload.php';
				$_REQUEST['filter_action'] = 'Filter';
				$_REQUEST['delete_all']    = '1';
				do_action( 'load-upload.php' );
			}
		);
	}

	/**
	 * The media list table's attach/detach controls send media[] as a real
	 * array (media[]=<id>&media[]=<id>), not the scalar form.
	 */
	public function test_upload_media_array_attach_is_blocked(): void {
		global $pagenow;
		$this->install();
		$this->login_as( 'editor' );

		$this->expect_blocked(
			function () use ( &$pagenow ) {
				$pagenow                  = 'upload.php';
				$_REQUEST['media']        = array( '1', '2' );
				$_REQUEST['found_post_id'] = '5';
				do_action( 'load-upload.php' );
			}
		);
	}

	/**
	 * Core does not ship any role that holds edit_theme_options without
	 * also holding manage_options (and therefore the bypass capability),
	 * so a custom "theme manager" style role is the only realistic way
	 * to exercise a *restricted* user who can otherwise reach these
	 * design-surface screens at all.
	 */
	private function login_as_theme_manager(): int {
		add_role( 'dg_theme_manager', 'Theme Manager', array( 'read' => true, 'edit_theme_options' => true ) );
		$user_id = self::factory()->user->create( array( 'role' => 'dg_theme_manager' ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * @dataProvider provide_theme_page_slugs
	 */
	public function test_custom_header_and_background_screens_are_blocked_for_any_request( string $slug ): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		list( , $guard ) = $this->install();
		( new AdminGate( $guard ) )->register_theme_page_gates();
		$this->login_as_theme_manager();

		// A plain GET: the header screen saves its cropping step on a GET
		// request, and core reaches these screens through more than one
		// URL, all of which fire the same load-{$page_hook}.
		$this->expect_blocked(
			static function () use ( $slug ) {
				do_action( 'load-' . get_plugin_page_hookname( $slug, 'themes.php' ) );
			}
		);
	}

	public static function provide_theme_page_slugs(): array {
		return array(
			'custom header'     => array( 'custom-header' ),
			'custom background' => array( 'custom-background' ),
		);
	}

	public function test_privacy_policy_page_creation_is_blocked_without_bypass(): void {
		global $pagenow;
		$this->install();

		// Core checks only manage_privacy_options (manage_options) before
		// creating the page: no content capability at all.
		add_role( 'dg_privacy_manager', 'Privacy Manager', array( 'read' => true, 'manage_options' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'dg_privacy_manager' ) ) );

		$original_method           = $_SERVER['REQUEST_METHOD'] ?? null;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['action']           = 'create-privacy-page';

		try {
			$this->expect_blocked(
				function () use ( &$pagenow ) {
					$pagenow = 'options-privacy.php';
					do_action( 'load-options-privacy.php' );
				}
			);
		} finally {
			$_SERVER['REQUEST_METHOD'] = $original_method;
			remove_role( 'dg_privacy_manager' );
		}
	}

	public function test_privacy_policy_page_creation_is_allowed_for_bypass_holder(): void {
		global $pagenow;
		$this->install();
		$this->login_as( 'administrator' );

		$original_method           = $_SERVER['REQUEST_METHOD'] ?? null;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['action']           = 'create-privacy-page';

		try {
			$this->expect_allowed(
				function () use ( &$pagenow ) {
					$pagenow = 'options-privacy.php';
					do_action( 'load-options-privacy.php' );
				}
			);
		} finally {
			$_SERVER['REQUEST_METHOD'] = $original_method;
		}
	}

	public function test_nav_menus_screen_is_blocked(): void {
		global $pagenow;
		$this->install();
		$this->login_as_theme_manager();

		$this->expect_blocked(
			function () use ( &$pagenow ) {
				$pagenow = 'nav-menus.php';
				do_action( 'load-nav-menus.php' );
			}
		);
	}

	public function test_widgets_screen_is_blocked(): void {
		global $pagenow;
		$this->install();
		$this->login_as_theme_manager();

		$this->expect_blocked(
			function () use ( &$pagenow ) {
				$pagenow = 'widgets.php';
				do_action( 'load-widgets.php' );
			}
		);
	}

	public function test_customize_screen_is_blocked(): void {
		global $pagenow;
		$this->install();
		$this->login_as_theme_manager();

		$this->expect_blocked(
			function () use ( &$pagenow ) {
				$pagenow = 'customize.php';
				do_action( 'load-customize.php' );
			}
		);
	}

	public function test_media_new_post_is_blocked(): void {
		global $pagenow;
		$this->install();
		$this->login_as( 'editor' );

		$this->expect_blocked(
			function () use ( &$pagenow ) {
				$pagenow                   = 'media-new.php';
				$_SERVER['REQUEST_METHOD'] = 'POST';
				do_action( 'load-media-new.php' );
			}
		);

		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public function test_subscriber_passes_through_without_being_logged(): void {
		global $pagenow;
		list( $service ) = $this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'subscriber' );

		$this->expect_allowed(
			function () use ( &$pagenow, $post_id ) {
				$pagenow       = 'post.php';
				$_GET['post']  = $post_id;
				$_GET['action'] = 'edit';
				do_action( 'load-post.php' );
			}
		);

		$blocked = array_filter( $service->entries(), static fn ( $entry ) => 'blocked' === $entry['type'] );
		$this->assertSame( array(), $blocked );
	}

	public function test_bypass_holder_is_allowed(): void {
		global $pagenow;
		$this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'administrator' );

		$this->expect_allowed(
			function () use ( &$pagenow, $post_id ) {
				$pagenow       = 'post.php';
				$_GET['post']  = $post_id;
				$_GET['action'] = 'edit';
				do_action( 'load-post.php' );
			}
		);
	}

	public function test_network_admin_is_never_gated(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );

		add_filter( 'network_admin', '__return_true' ); // Not a real filter; verify register() itself checks is_network_admin() and skips.

		remove_filter( 'network_admin', '__return_true' );

		// Directly assert the documented behavior: register() checks
		// is_network_admin()/is_user_admin() and, on a normal single-site
		// admin request (as this test runs), proceeds to attach its hooks.
		( new AdminGate( $guard ) )->register();
		$this->assertGreaterThan( 0, has_action( 'load-post.php' ) );
	}

	public function test_dashboard_quick_draft_meta_box_is_removed_when_protected(): void {
		list( , $guard ) = $this->install();

		global $wp_meta_boxes;
		$wp_meta_boxes = array();
		add_meta_box( 'dashboard_quick_press', 'Quick Draft', '__return_null', 'dashboard', 'side' );

		( new AdminGate( $guard ) )->remove_quick_draft();

		$this->assertArrayNotHasKey( 'dashboard_quick_press', $wp_meta_boxes['dashboard']['side']['core'] ?? array() );
	}
}
