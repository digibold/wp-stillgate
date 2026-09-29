<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Enforcement\CapabilityFilter;

final class CapabilityFilterTest extends TestCase {

	private const NOW = 1_800_000_000;

	private function install( bool $active = true ): array {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );

		( new CapabilityFilter( $guard ) )->register();

		if ( $active ) {
			$service->enable();
		}

		return array( $service, $guard );
	}

	public function test_editor_is_denied_edit_delete_publish_on_protected_post(): void {
		list( $service ) = $this->install();
		$this->login_as( 'editor' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertFalse( current_user_can( 'edit_post', $post_id ) );
		$this->assertFalse( current_user_can( 'delete_post', $post_id ) );
		$this->assertFalse( current_user_can( 'publish_post', $post_id ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $post_id ) );
	}

	public function test_editor_is_denied_edit_delete_page(): void {
		$this->install();
		$this->login_as( 'editor' );

		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertFalse( current_user_can( 'edit_page', $page_id ) );
		$this->assertFalse( current_user_can( 'delete_page', $page_id ) );
	}

	public function test_editor_is_denied_edit_delete_assign_term(): void {
		$this->install();

		$term = self::factory()->term->create_and_get( array( 'taxonomy' => 'category' ) );
		$this->login_as( 'editor' );

		$this->assertFalse( current_user_can( 'edit_term', $term->term_id ) );
		$this->assertFalse( current_user_can( 'delete_term', $term->term_id ) );
		$this->assertFalse( current_user_can( 'assign_term', $term->term_id ) );
	}

	public function test_post_object_argument_is_resolved_the_same_as_an_id(): void {
		$this->install();
		$post = self::factory()->post->create_and_get();
		$this->login_as( 'editor' );

		// Core's own map_meta_cap() accepts a WP_Post for these, and
		// plugins commonly pass one instead of an ID.
		$this->assertFalse( current_user_can( 'edit_post', $post ) );
		$this->assertFalse( current_user_can( 'delete_post', $post ) );
		$this->assertFalse( current_user_can( 'publish_post', $post ) );
	}

	public function test_read_post_of_others_draft_still_works(): void {
		$this->install();

		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $author_id,
			)
		);

		$this->login_as( 'editor' );

		$this->assertTrue( current_user_can( 'read_post', $post_id ) );
	}

	public function test_edit_comment_on_protected_post_still_works_for_editor(): void {
		$this->install();

		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		$this->login_as( 'editor' );

		$this->assertTrue( current_user_can( 'edit_comment', $comment_id ) );
	}

	public function test_unprotected_post_type_is_unaffected(): void {
		register_post_type(
			'dg_private_cpt',
			array(
				'public'   => false,
				'show_ui'  => true,
				'label'    => 'Private CPT',
				'supports' => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);

		$this->install();
		$this->login_as( 'editor' );

		$post_id = self::factory()->post->create( array( 'post_type' => 'dg_private_cpt' ) );

		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );

		unregister_post_type( 'dg_private_cpt' );
	}

	public function test_inactive_freeze_does_not_deny_anything(): void {
		$this->install( false );
		$this->login_as( 'editor' );

		$post_id = self::factory()->post->create();

		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );
		$this->assertTrue( current_user_can( 'delete_post', $post_id ) );
	}

	public function test_bypass_holder_is_unaffected(): void {
		$this->install();
		$this->login_as( 'administrator' );

		$post_id = self::factory()->post->create();

		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );
		$this->assertTrue( current_user_can( 'delete_post', $post_id ) );
	}

	public function test_super_admin_is_unaffected_on_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$this->install();

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );

		$post_id = self::factory()->post->create();

		$this->assertTrue( current_user_can( 'edit_post', $post_id ) );

		revoke_super_admin( $user_id );
	}
}
