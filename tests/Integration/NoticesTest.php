<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Admin\Notices;

final class NoticesTest extends TestCase {

	private const NOW = 1_800_000_000;

	private function install(): array {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );

		return array( new Notices( $guard ), $service );
	}

	private function render_admin_notice( Notices $notices ): string {
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

		add_role( 'dg_theme_manager', 'Theme Manager', array( 'read' => true, 'edit_theme_options' => true ) );
		$user_id = self::factory()->user->create( array( 'role' => 'dg_theme_manager' ) );
		wp_set_current_user( $user_id );

		$html = $this->render_admin_notice( $notices );

		$this->assertStringContainsString( 'unavailable', $html );
	}

	public function test_own_settings_page_shows_nothing(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'administrator' );

		set_current_screen( 'tools_page_stillgate' );
		$html = $this->render_admin_notice( $notices );

		$this->assertSame( '', $html );
	}

	public function test_no_nested_paragraph_tags(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'editor' );

		$html = $this->render_admin_notice( $notices );

		$this->assertStringNotContainsString( '<p><p>', $html );
	}

	public function test_block_editor_notice_enqueues_inline_script(): void {
		list( $notices, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'administrator' );

		$notices->block_editor_notice();

		$this->assertTrue( wp_script_is( 'stillgate-editor-notice', 'enqueued' ) );
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
