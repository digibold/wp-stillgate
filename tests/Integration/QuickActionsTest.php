<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Admin\QuickActions;
use Digibold\DeploymentGuard\FreezeService;

final class QuickActionsTest extends TestCase {

	private FreezeService $service;

	private QuickActions $actions;

	public function set_up(): void {
		parent::set_up();

		$this->service = $this->make_service( 0 );
		$this->actions = new QuickActions( $this->service );
	}

	public function tear_down(): void {
		$_GET     = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	private function request( string $operation, ?string $nonce = null ): string {
		$nonce    = $nonce ?? wp_create_nonce( 'deployment_guard_quick_' . $operation );
		$_GET     = array( 'op' => $operation, '_wpnonce' => $nonce, 'action' => 'deployment_guard_quick' );
		$_REQUEST = $_GET;

		return $this->actions->resolve_redirect_url();
	}

	public function test_the_link_carries_the_action_the_operation_and_a_nonce(): void {
		$url = QuickActions::url( 'end' );

		$this->assertStringContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=deployment_guard_quick', $url );
		$this->assertStringContainsString( 'op=end', $url );
		$this->assertMatchesRegularExpression( '/_wpnonce=[a-f0-9]{10}/', $url );
	}

	public function test_end_stops_a_running_freeze_and_goes_to_the_settings_screen(): void {
		$this->login_as( 'administrator' );
		$this->service->freeze_for( 1800 );

		$location = $this->request( 'end' );

		$this->assertFalse( $this->service->is_active() );
		$this->assertStringContainsString( 'page=stillgate', $location );
		$this->assertStringContainsString( 'dg_result=disabled', $location );
	}

	public function test_extend_pushes_a_timed_freeze_back_by_fifteen_minutes(): void {
		$this->login_as( 'administrator' );
		$this->service->freeze_for( 1800 );
		$before = $this->service->ends_at();

		$location = $this->request( 'extend' );

		$this->assertSame( $before + 15 * MINUTE_IN_SECONDS, $this->service->ends_at() );
		$this->assertStringContainsString( 'dg_result=extended', $location );
	}

	public function test_extending_a_manual_freeze_reports_that_there_is_no_end_to_extend(): void {
		$this->login_as( 'administrator' );
		$this->service->enable();

		$location = $this->request( 'extend' );

		$this->assertStringContainsString( 'dg_error=not_extendable', $location );
		$this->assertNull( $this->service->ends_at() );
	}

	public function test_a_nonce_for_one_operation_does_not_work_for_the_other(): void {
		$this->login_as( 'administrator' );
		$this->service->freeze_for( 1800 );

		$this->expectException( \WPDieException::class );
		$this->request( 'end', wp_create_nonce( 'deployment_guard_quick_extend' ) );
	}

	public function test_a_bad_nonce_dies_and_changes_nothing(): void {
		$this->login_as( 'administrator' );
		$this->service->freeze_for( 1800 );

		try {
			$this->request( 'end', 'not-a-real-nonce' );
			$this->fail( 'Expected the request to die.' );
		} catch ( \WPDieException $exception ) {
			$this->assertTrue( $this->service->is_active() );
		}
	}

	public function test_an_unknown_operation_dies_with_400(): void {
		$this->login_as( 'administrator' );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionCode( 400 );
		$this->request( 'start' );
	}

	public function test_an_editor_is_refused_with_403_even_with_a_valid_nonce(): void {
		$this->service->freeze_for( 1800 );
		$this->login_as( 'editor' );

		try {
			$this->request( 'end' );
			$this->fail( 'Expected the request to die.' );
		} catch ( \WPDieException $exception ) {
			$this->assertSame( 403, $exception->getCode() );
			$this->assertTrue( $this->service->is_active() );
		}
	}

	public function test_an_administrator_without_the_bypass_capability_is_refused(): void {
		$this->service->freeze_for( 1800 );
		remove_role( 'dg_options_only3' );
		add_role( 'dg_options_only3', 'Options Only 3', array( 'read' => true, 'manage_options' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'dg_options_only3' ) ) );

		try {
			$this->request( 'end' );
			$this->fail( 'Expected the request to die.' );
		} catch ( \WPDieException $exception ) {
			$this->assertSame( 403, $exception->getCode() );
		}

		remove_role( 'dg_options_only3' );
	}
}
