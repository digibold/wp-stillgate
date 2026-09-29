<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Admin\SettingsPage;

final class SettingsPageTest extends TestCase {

	private const NOW = 1_800_000_000;

	/**
	 * @return array{0: SettingsPage, 1: \Digibold\DeploymentGuard\FreezeService}
	 */
	private function install(): array {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );

		return array( new SettingsPage( $service, $guard ), $service );
	}

	private function post( array $data ): void {
		$_POST    = $data;
		$_REQUEST = $data;
	}

	private function submit( SettingsPage $page, array $data ): string {
		$this->post( array_merge( array( '_wpnonce' => wp_create_nonce( 'deployment_guard_update' ) ), $data ) );

		return $page->resolve_redirect_url();
	}

	public function test_handle_post_requires_manage_options(): void {
		list( $page ) = $this->install();
		$this->login_as( 'subscriber' );
		$this->post( array( 'operation' => 'enable', '_wpnonce' => wp_create_nonce( 'deployment_guard_update' ) ) );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionCode( 403 );
		$page->resolve_redirect_url();
	}

	public function test_handle_post_requires_bypass_even_with_manage_options(): void {
		list( $page ) = $this->install();

		add_role( 'dg_options_only', 'Options Only', array( 'read' => true, 'manage_options' => true ) );
		$user_id = self::factory()->user->create( array( 'role' => 'dg_options_only' ) );
		wp_set_current_user( $user_id );

		$this->post( array( 'operation' => 'enable', '_wpnonce' => wp_create_nonce( 'deployment_guard_update' ) ) );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionCode( 403 );
		$page->resolve_redirect_url();
	}

	public function test_bad_nonce_dies(): void {
		list( $page ) = $this->install();
		$this->login_as( 'administrator' );
		$this->post( array( 'operation' => 'enable', '_wpnonce' => 'not-a-real-nonce' ) );

		$this->expectException( \WPDieException::class );
		$page->resolve_redirect_url();
	}

	public function test_unknown_operation_dies_with_400(): void {
		list( $page ) = $this->install();
		$this->login_as( 'administrator' );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionCode( 400 );
		$this->submit( $page, array( 'operation' => 'not-a-real-operation' ) );
	}

	public function test_enable_without_confirmation_returns_error(): void {
		list( $page ) = $this->install();
		$this->login_as( 'administrator' );

		$location = $this->submit( $page, array( 'operation' => 'enable' ) );

		$this->assertStringContainsString( 'dg_error=confirmation_required', $location );
	}

	public function test_disable_without_confirmation_succeeds(): void {
		list( $page, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'administrator' );

		$location = $this->submit( $page, array( 'operation' => 'disable' ) );

		$this->assertStringContainsString( 'dg_result=disabled', $location );
		$this->assertFalse( $service->is_active() );
	}

	public function test_enable_with_confirmation_succeeds(): void {
		list( $page, $service ) = $this->install();
		$this->login_as( 'administrator' );

		$location = $this->submit( $page, array( 'operation' => 'enable', 'confirm' => '1' ) );

		$this->assertStringContainsString( 'dg_result=enabled', $location );
		$this->assertTrue( $service->is_active() );
	}

	public function test_schedule_with_confirmation_succeeds(): void {
		list( $page, $service ) = $this->install();
		$this->login_as( 'administrator' );

		$start = gmdate( 'Y-m-d\TH:i', self::NOW + 100 );
		$end   = gmdate( 'Y-m-d\TH:i', self::NOW + 200 );

		$location = $this->submit(
			$page,
			array(
				'operation' => 'schedule',
				'confirm'   => '1',
				'start'     => $start,
				'end'       => $end,
			)
		);

		$this->assertStringContainsString( 'dg_result=scheduled', $location );
	}

	public function test_schedule_invalid_start_returns_specific_error(): void {
		list( $page ) = $this->install();
		$this->login_as( 'administrator' );

		$location = $this->submit(
			$page,
			array(
				'operation' => 'schedule',
				'confirm'   => '1',
				'start'     => 'not a date',
				'end'       => gmdate( 'Y-m-d\TH:i', self::NOW + 200 ),
			)
		);

		$this->assertStringContainsString( 'dg_error=invalid_start', $location );
	}

	public function test_message_operation_saves_message(): void {
		list( $page, $service ) = $this->install();
		$this->login_as( 'administrator' );

		$location = $this->submit( $page, array( 'operation' => 'message', 'message' => 'Hello editors' ) );

		$this->assertStringContainsString( 'dg_result=message_saved', $location );
		$this->assertSame( 'Hello editors', $service->config()->message );
	}

	public function test_bypass_administrator_can_disable_active_freeze(): void {
		list( $page, $service ) = $this->install();
		$service->enable();
		$this->login_as( 'administrator' );

		$location = $this->submit( $page, array( 'operation' => 'disable' ) );

		$this->assertStringContainsString( 'dg_result=disabled', $location );
	}

	public function test_render_escapes_seeded_xss_in_message(): void {
		list( $page, $service ) = $this->install();
		$service->set_message( '<script>alert(1)</script>' );
		$service->enable();

		$this->login_as( 'administrator' );

		ob_start();
		$page->render();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	public function test_render_escapes_seeded_xss_in_history_context(): void {
		list( $page, $service ) = $this->install();
		$this->login_as( 'editor' );
		$service->record_blocked( 'admin', '"><script>alert(2)</script>' );

		$this->login_as( 'administrator' );

		ob_start();
		$page->render();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( '<script>alert(2)</script>', $html );
	}

	public function test_render_disables_forms_without_bypass(): void {
		list( $page ) = $this->install();

		add_role( 'dg_options_only2', 'Options Only 2', array( 'read' => true, 'manage_options' => true ) );
		$user_id = self::factory()->user->create( array( 'role' => 'dg_options_only2' ) );
		wp_set_current_user( $user_id );

		ob_start();
		$page->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'disabled', $html );
	}

	public function test_render_has_labels_fieldset_and_nonce(): void {
		list( $page, $service ) = $this->install();
		$this->login_as( 'administrator' );
		$service->enable(); // Seeds one activity row so the table (and its caption) renders.

		ob_start();
		$page->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'for="dg-start"', $html );
		$this->assertStringContainsString( 'id="dg-start"', $html );
		$this->assertStringContainsString( 'for="dg-end"', $html );
		$this->assertStringContainsString( '<fieldset>', $html );
		$this->assertStringContainsString( 'aria-describedby="dg-schedule-desc"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( '<caption', $html );
		$this->assertStringContainsString( 'scope="row"', $html );
	}

	public function test_render_view_requires_manage_options(): void {
		list( $page ) = $this->install();
		$this->login_as( 'subscriber' );

		$this->expectException( \WPDieException::class );
		$page->render();
	}
}
