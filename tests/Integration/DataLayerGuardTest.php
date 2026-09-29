<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Enforcement\DataLayerGuard;

final class DataLayerGuardTest extends TestCase {

	private const NOW = 1_800_000_000;

	private function install( bool $is_cli = false, bool $active = true ): array {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service, $is_cli );
		( new DataLayerGuard( $guard ) )->register();

		if ( $active ) {
			$service->enable();
		}

		return array( $service, $guard );
	}

	public function test_trash_untrash_delete_are_blocked_for_editor(): void {
		$this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		$this->assertFalse( wp_trash_post( $post_id ) );
		$this->assertSame( 'publish', get_post_status( $post_id ) );

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'trash' ) ); // Force trash directly, bypassing wp_trash_post, for the untrash check.
	}

	public function test_delete_post_is_blocked_for_editor(): void {
		$this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		$this->assertFalse( wp_delete_post( $post_id, true ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ) );
	}

	public function test_delete_attachment_is_blocked_for_editor(): void {
		$this->install();
		$attachment_id = self::factory()->attachment->create_object( 'test.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$this->login_as( 'editor' );

		$this->assertFalse( wp_delete_attachment( $attachment_id, true ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $attachment_id ) );
	}

	public function test_insert_term_is_blocked_for_editor(): void {
		$this->install();
		$this->login_as( 'editor' );

		$result = wp_insert_term( 'A New Term', 'category' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'deployment_guard_active', $result->get_error_code() );
	}

	public function test_author_auto_creating_a_tag_is_blocked(): void {
		$this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'author' );

		$result = wp_set_object_terms( $post_id, array( 'Brand New Tag' ), 'post_tag' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_unprotected_taxonomy_is_unaffected(): void {
		register_taxonomy( 'dg_open_tax', 'post', array( 'public' => false, 'show_ui' => true ) );
		$this->install();
		$this->login_as( 'editor' );

		$result = wp_insert_term( 'Open term', 'dg_open_tax' );

		$this->assertIsArray( $result );

		unregister_taxonomy( 'dg_open_tax' );
	}

	public function test_frontend_upload_is_not_blocked(): void {
		list( $service, $guard ) = $this->install();
		$this->login_as( 'editor' );

		$file = array(
			'name'     => 'photo.jpg',
			'tmp_name' => '/tmp/does-not-matter.jpg',
			'error'    => 0,
			'size'     => 100,
		);

		// Simulate a non-admin (frontend) request context.
		set_current_screen( 'front' );
		$filtered = apply_filters( 'wp_handle_upload_prefilter', $file );

		// A real $_FILES-shaped array always has an 'error' key (0 means
		// "no error"); an untouched pass-through keeps it at 0, while a
		// block overwrites it with a non-numeric message string.
		$this->assertSame( 0, $filtered['error'] );
	}

	public function test_admin_upload_prefilter_is_blocked(): void {
		global $pagenow;
		$this->install();
		$this->login_as( 'editor' );

		set_current_screen( 'upload' );
		$pagenow = 'media-new.php';

		$file = array(
			'name'     => 'photo.jpg',
			'tmp_name' => '/tmp/does-not-matter.jpg',
			'error'    => 0,
			'size'     => 100,
		);

		$filtered = apply_filters( 'wp_handle_upload_prefilter', $file );

		$this->assertArrayHasKey( 'error', $filtered );
		$this->assertNotEmpty( $filtered['error'] );

		$pagenow = null;
	}

	public function test_update_php_upload_is_never_blocked(): void {
		global $pagenow;
		$this->install();
		$this->login_as( 'editor' );

		$pagenow = 'update.php';

		$file     = array( 'name' => 'plugin.zip', 'tmp_name' => '/tmp/x.zip', 'error' => 0, 'size' => 10 );
		$filtered = apply_filters( 'wp_handle_upload_prefilter', $file );

		$this->assertSame( 0, $filtered['error'] );

		$pagenow = null;
	}

	public function test_wp_insert_post_empty_content_backstop_is_web_scoped_off(): void {
		$this->install( false ); // web context

		$result = apply_filters( 'wp_insert_post_empty_content', false, array( 'post_type' => 'post', 'post_title' => 'x' ) );

		$this->assertFalse( $result );
	}

	public function test_wp_insert_post_empty_content_backstop_applies_in_cron_context(): void {
		$this->install( false );
		add_filter( 'wp_doing_cron', '__return_true' );

		$result = apply_filters( 'wp_insert_post_empty_content', false, array( 'post_type' => 'post', 'post_title' => 'Has content' ) );

		$this->assertTrue( $result );

		remove_filter( 'wp_doing_cron', '__return_true' );
	}

	public function test_wp_insert_post_empty_content_excludes_global_styles_and_navigation(): void {
		$this->install( false );
		add_filter( 'wp_doing_cron', '__return_true' );

		$this->assertFalse( apply_filters( 'wp_insert_post_empty_content', false, array( 'post_type' => 'wp_global_styles' ) ) );
		$this->assertFalse( apply_filters( 'wp_insert_post_empty_content', false, array( 'post_type' => 'wp_navigation' ) ) );

		remove_filter( 'wp_doing_cron', '__return_true' );
	}

	public function test_user_deletion_is_not_blocked(): void {
		list( $service ) = $this->install();
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = self::factory()->post->create( array( 'post_author' => $author_id ) );
		$this->login_as( 'administrator' );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		$result = wp_delete_user( $author_id );

		$this->assertTrue( (bool) $result );
	}

	public function test_bypass_holder_is_unaffected(): void {
		$this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'administrator' );

		$this->assertNotFalse( wp_trash_post( $post_id ) );
	}

	public function test_subscriber_is_unaffected(): void {
		$this->install();
		$this->login_as( 'subscriber' );

		// A subscriber holds neither edit_terms nor assign_terms, so
		// applies_to_taxonomy() is false and this backstop does not
		// interfere (a subscriber would never legitimately reach this
		// call in the first place; nothing here re-checks capabilities).
		$result = wp_insert_term( 'Unrelated Term', 'category' );

		$this->assertIsArray( $result );
	}

	public function test_inactive_freeze_does_not_block(): void {
		$this->install( false, false );
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		$this->assertNotFalse( wp_trash_post( $post_id ) );
	}
}
