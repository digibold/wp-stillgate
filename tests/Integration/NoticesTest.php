<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Admin\Notices;

final class NoticesTest extends TestCase {

	private const NOW = 1_800_000_000;

	public function tear_down(): void {
		$GLOBALS['current_screen'] = null;

		parent::tear_down();
	}

	private function install(): array {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );

		return array( new Notices( $guard ), $service );
	}

	/**
	 * Notices::admin_notice() only ever runs on a real admin screen, so
	 * every test states which one. The default is the dashboard.
	 */
	private function go_to_screen( string $screen_id ): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		set_current_screen( $screen_id );
	}

	private function render_admin_notice( Notices $notices, string $screen_id = 'dashboard' ): string {
		$this->go_to_screen( $screen_id );

		ob_start();
		$notices->admin_notice();

		return ob_get_clean();
	}

	public function test_active_editor_sees_active_message_and_restricted_sentence(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'editor' );

		$html = $this->render_admin_notice( $notices );

		$this->assertStringContainsString( 'temporarily locked', $html );
		$this->assertStringContainsString( 'unavailable', $html );
	}

	public function test_message_and_end_time_are_shown(): void {
		list( $notices, $service ) = $this->install();
		$service->schedule( self::NOW - 10, self::NOW + 1000 );
		$service->set_message( 'Deploying the new release.' );
		$this->login_as( 'editor' );

		$html = $this->render_admin_notice( $notices );

		$this->assertStringContainsString( 'Deploying the new release.', $html );
		$this->assertStringContainsString( 'expected to resume', $html );
	}

	public function test_bypass_sentence_for_bypass_holder(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'administrator' );

		$html = $this->render_admin_notice( $notices );

		$this->assertStringContainsString( 'may still be lost', $html );
	}

	public function test_inactive_freeze_shows_nothing(): void {
		list( $notices ) = $this->install();
		$this->login_as( 'editor' );

		$html = $this->render_admin_notice( $notices );

		$this->assertSame( '', $html );
	}

	public function test_user_without_edit_posts_or_bypass_sees_nothing(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'subscriber' );

		$html = $this->render_admin_notice( $notices );

		$this->assertSame( '', $html );
	}

	/**
	 * Core ships no role with edit_theme_options (or upload_files, or
	 * edit_terms/assign_terms) that lacks edit_posts too, so a role that
	 * isolates one of them is the only way to exercise a restricted user
	 * the notice previously missed entirely (it checked edit_posts only).
	 */
	public function test_theme_options_only_role_sees_the_restricted_notice(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();

		remove_role( 'dg_theme_manager' );
		add_role( 'dg_theme_manager', 'Theme Manager', array( 'read' => true, 'edit_theme_options' => true ) );
		$user_id = self::factory()->user->create( array( 'role' => 'dg_theme_manager' ) );
		wp_set_current_user( $user_id );

		$html = $this->render_admin_notice( $notices, 'nav-menus' );

		$this->assertStringContainsString( 'unavailable', $html );

		remove_role( 'dg_theme_manager' );
	}

	public function test_own_settings_page_shows_nothing(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'administrator' );

		$html = $this->render_admin_notice( $notices, 'tools_page_stillgate' );

		$this->assertSame( '', $html );
	}

	public function test_block_editor_screens_show_nothing_here(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'administrator' );

		$this->go_to_screen( 'post' );
		get_current_screen()->is_block_editor( true );

		ob_start();
		$notices->admin_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_no_screen_means_no_notice(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'editor' );

		$GLOBALS['current_screen'] = null;

		ob_start();
		$notices->admin_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_no_nested_paragraph_tags(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'editor' );

		$html = $this->render_admin_notice( $notices );

		$this->assertStringNotContainsString( '<p><p>', $html );
	}

	/**
	 * The notice is scoped to screens where a freeze affects editorial
	 * work, not site-wide.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function affected_screens(): array {
		return array(
			'dashboard'             => array( 'dashboard' ),
			'posts list'            => array( 'edit-post' ),
			'pages list'            => array( 'edit-page' ),
			'media library'         => array( 'upload' ),
			'add new media'         => array( 'media' ),
			'categories'            => array( 'edit-category' ),
			'tags'                  => array( 'edit-post_tag' ),
			'menus'                 => array( 'nav-menus' ),
			'custom header'         => array( 'appearance_page_custom-header' ),
			'custom background'     => array( 'appearance_page_custom-background' ),
			'classic widgets'       => array( 'widgets' ),
		);
	}

	/**
	 * @dataProvider affected_screens
	 */
	public function test_notice_appears_on_screens_where_editing_is_affected( string $screen_id ): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'administrator' );

		$this->assertStringContainsString( 'temporarily locked', $this->render_admin_notice( $notices, $screen_id ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function unaffected_screens(): array {
		return array(
			'plugins'            => array( 'plugins' ),
			'general settings'   => array( 'options-general' ),
			'users'              => array( 'users' ),
			'comments'           => array( 'edit-comments' ),
			'updates'            => array( 'update-core' ),
			'tools'              => array( 'tools' ),
			'themes'             => array( 'themes' ),
			'profile'            => array( 'profile' ),
		);
	}

	/**
	 * @dataProvider unaffected_screens
	 */
	public function test_notice_is_not_site_wide( string $screen_id ): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'administrator' );

		$this->assertSame( '', $this->render_admin_notice( $notices, $screen_id ) );
	}

	public function test_notice_appears_on_the_classic_post_editor_when_the_block_editor_is_off(): void {
		add_filter( 'use_block_editor_for_post_type', '__return_false' );
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'administrator' );

		$this->assertStringContainsString( 'temporarily locked', $this->render_admin_notice( $notices, 'post' ) );

		remove_filter( 'use_block_editor_for_post_type', '__return_false' );
	}

	public function test_a_public_custom_post_type_screen_is_covered(): void {
		register_post_type( 'dg_book', array( 'public' => true, 'show_ui' => true ) );
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'editor' );

		$this->assertStringContainsString( 'temporarily locked', $this->render_admin_notice( $notices, 'edit-dg_book' ) );
		$this->assertStringContainsString( 'temporarily locked', $this->render_admin_notice( $notices, 'dg_book' ) );

		unregister_post_type( 'dg_book' );
	}

	public function test_an_unprotected_custom_post_type_screen_is_not_covered(): void {
		register_post_type( 'dg_internal', array( 'public' => false, 'show_ui' => true ) );
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'editor' );

		$this->assertSame( '', $this->render_admin_notice( $notices, 'edit-dg_internal' ) );

		unregister_post_type( 'dg_internal' );
	}

	public function test_scope_follows_the_protected_types_filter(): void {
		register_post_type( 'dg_internal', array( 'public' => false, 'show_ui' => true ) );
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'administrator' );

		$add_internal = static function ( $types ) {
			$types['post_types'][] = 'dg_internal';

			return $types;
		};
		add_filter( 'deployment_guard_protected_types', $add_internal );

		// A new Guard per configuration: it caches the protected types once
		// wp_loaded has fired, and filters are static for a real request.
		list( $notices ) = $this->install();
		$this->assertStringContainsString( 'temporarily locked', $this->render_admin_notice( $notices, 'edit-dg_internal' ), 'a type a site adds is covered' );

		remove_filter( 'deployment_guard_protected_types', $add_internal );

		$remove_posts = static function ( $types ) {
			$types['post_types'] = array_values( array_diff( $types['post_types'], array( 'post' ) ) );

			return $types;
		};
		add_filter( 'deployment_guard_protected_types', $remove_posts );

		list( $notices ) = $this->install();
		$this->assertSame( '', $this->render_admin_notice( $notices, 'edit-post' ), 'a type a site removes is not covered' );

		remove_filter( 'deployment_guard_protected_types', $remove_posts );
		unregister_post_type( 'dg_internal' );
	}

	public function test_a_public_custom_taxonomy_screen_is_covered_and_a_private_one_is_not(): void {
		register_taxonomy( 'dg_genre', 'post', array( 'public' => true, 'show_ui' => true ) );
		register_taxonomy( 'dg_hidden', 'post', array( 'public' => false, 'show_ui' => true ) );
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'editor' );

		$this->assertStringContainsString( 'temporarily locked', $this->render_admin_notice( $notices, 'edit-dg_genre' ) );
		$this->assertSame( '', $this->render_admin_notice( $notices, 'edit-dg_hidden' ) );

		unregister_taxonomy( 'dg_genre' );
		unregister_taxonomy( 'dg_hidden' );
	}

	public function test_notice_disappears_by_itself_when_a_scheduled_freeze_ends(): void {
		$service = $this->make_service( self::NOW );
		$service->schedule( self::NOW - 10, self::NOW + 100 );
		$this->login_as( 'editor' );

		$during = new Notices( $this->make_guard( $service ) );
		$this->assertStringContainsString( 'temporarily locked', $this->render_admin_notice( $during ) );

		// The same stored schedule, evaluated a little after its end.
		$after = new Notices( $this->make_guard( $this->make_service( self::NOW + 200 ) ) );
		$this->assertSame( '', $this->render_admin_notice( $after ) );
	}

	public function test_notice_disappears_when_a_manual_freeze_is_disabled(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'editor' );
		$this->assertNotSame( '', $this->render_admin_notice( $notices ) );

		$service->disable();

		$this->assertSame( '', $this->render_admin_notice( $notices ) );
	}

	public function test_administrator_is_told_where_to_end_the_freeze(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'administrator' );

		$html = $this->render_admin_notice( $notices );

		$this->assertStringContainsString( 'tools.php?page=stillgate', $html );
		$this->assertStringContainsString( 'end the freeze', $html );
	}

	public function test_bypass_holder_without_manage_options_is_pointed_to_an_administrator(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();

		remove_role( 'dg_release_editor' );
		add_role( 'dg_release_editor', 'Release Editor', array( 'read' => true, 'edit_posts' => true, 'deployment_guard_bypass' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'dg_release_editor' ) ) );

		$html = $this->render_admin_notice( $notices );

		$this->assertStringContainsString( 'may still be lost', $html );
		$this->assertStringContainsString( 'ask a site administrator', $html );
		$this->assertStringNotContainsString( 'tools.php?page=stillgate', $html );

		remove_role( 'dg_release_editor' );
	}

	public function test_restricted_user_is_told_it_ends_by_itself_and_whom_to_ask(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'editor' );

		$html = $this->render_admin_notice( $notices );

		$this->assertStringContainsString( 'disappears when it does', $html );
		$this->assertStringContainsString( 'ask a site administrator', $html );
		$this->assertStringNotContainsString( 'tools.php?page=stillgate', $html );
	}

	public function test_the_classic_notice_cannot_be_dismissed(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'editor' );

		$html = $this->render_admin_notice( $notices );

		$this->assertStringNotContainsString( 'is-dismissible', $html );
		$this->assertStringNotContainsString( 'notice-dismiss', $html );
	}

	public function test_block_editor_notice_enqueues_inline_script(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'administrator' );

		$notices->block_editor_notice();

		$this->assertTrue( wp_script_is( 'stillgate-editor-notice', 'enqueued' ) );
	}

	public function test_block_editor_notice_stays_undismissible(): void {
		wp_dequeue_script( 'stillgate-editor-notice' );
		wp_deregister_script( 'stillgate-editor-notice' );

		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'administrator' );

		$notices->block_editor_notice();

		$inline = wp_scripts()->get_data( 'stillgate-editor-notice', 'after' );
		$this->assertStringContainsString( 'isDismissible:false', implode( '', (array) $inline ) );
	}

	public function test_block_editor_notice_does_nothing_when_inactive(): void {
		// wp_enqueue_script()'s registry is not part of the per-test
		// database rollback; start from a known state regardless of
		// what an earlier test in this class left enqueued.
		wp_dequeue_script( 'stillgate-editor-notice' );
		wp_deregister_script( 'stillgate-editor-notice' );

		list( $notices ) = $this->install();
		$this->login_as( 'administrator' );

		$notices->block_editor_notice();

		$this->assertFalse( wp_script_is( 'stillgate-editor-notice', 'enqueued' ) );
	}
}
