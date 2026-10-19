<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Installer;
use Digibold\DeploymentGuard\Rest\Controller;

/**
 * Drives the stillgate/v1 routes through a real WP_REST_Server, with the
 * plugin's own enforcement layers (RestGate included) still registered, so
 * "the API keeps working during a freeze" is tested for real.
 */
final class RestControllerTest extends TestCase {

	private \WP_REST_Server $server;

	private FreezeService $service;

	public function set_up(): void {
		parent::set_up();

		$this->service = $this->make_service( 0 );

		// A fresh server holding only this controller's routes: the
		// bootstrap already registered one for the plugin's own instance.
		$this->server                = new \WP_REST_Server();
		$GLOBALS['wp_rest_server']   = $this->server;
		remove_all_actions( 'rest_api_init' );
		( new Controller( $this->service ) )->register();
		do_action( 'rest_api_init', $this->server );
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null;

		parent::tear_down();
	}

	private function request( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/stillgate/v1' . $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $this->server->dispatch( $request );
	}

	private function error_code( \WP_REST_Response $response ): string {
		$data = $response->get_data();

		return is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
	}

	public function test_routes_are_registered_in_the_stillgate_namespace(): void {
		$routes = array_keys( $this->server->get_routes( 'stillgate/v1' ) );

		foreach ( array( '/stillgate/v1/status', '/stillgate/v1/freeze', '/stillgate/v1/freeze/extend', '/stillgate/v1/schedule' ) as $route ) {
			$this->assertContains( $route, $routes );
		}
	}

	public function test_anonymous_requests_are_refused_with_401(): void {
		wp_set_current_user( 0 );

		foreach ( array( array( 'GET', '/status' ), array( 'POST', '/freeze' ), array( 'DELETE', '/freeze' ) ) as $call ) {
			$response = $this->request( $call[0], $call[1] );
			$this->assertSame( 401, $response->get_status(), $call[0] . ' ' . $call[1] );
			$this->assertSame( 'stillgate_forbidden', $this->error_code( $response ) );
		}

		$this->assertFalse( $this->service->is_active() );
	}

	public function test_an_editor_is_refused_with_403_even_for_the_status(): void {
		$this->login_as( 'editor' );

		$this->assertSame( 403, $this->request( 'GET', '/status' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/freeze' )->get_status() );
		$this->assertFalse( $this->service->is_active() );
	}

	public function test_an_administrator_without_the_bypass_capability_can_read_but_not_change(): void {
		// A role that manages options but was never granted the bypass
		// capability (or had it removed on purpose).
		remove_role( 'dg_options_manager' );
		add_role( 'dg_options_manager', 'Options Manager', array( 'read' => true, 'manage_options' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'dg_options_manager' ) ) );
		$this->assertFalse( current_user_can( Installer::CAPABILITY ) );

		$this->assertSame( 200, $this->request( 'GET', '/status' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/freeze' )->get_status() );
		$this->assertSame( 403, $this->request( 'DELETE', '/freeze' )->get_status() );
		$this->assertFalse( $this->service->is_active() );

		remove_role( 'dg_options_manager' );
	}

	public function test_status_has_typed_values(): void {
		$this->login_as( 'administrator' );

		$data = $this->request( 'GET', '/status' )->get_data();

		$this->assertFalse( $data['active'] );
		$this->assertIsBool( $data['manual_enabled'] );
		$this->assertSame( 'inactive', $data['mode'] );
		$this->assertNull( $data['ends_at'] );
		$this->assertNull( $data['seconds_left'] );
		$this->assertIsString( $data['timezone'] );
	}

	public function test_start_a_freeze_until_it_is_ended(): void {
		$this->login_as( 'administrator' );

		$response = $this->request( 'POST', '/freeze' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'enabled', $data['result'] );
		$this->assertTrue( $data['active'] );
		$this->assertSame( 'manual', $data['mode'] );
		$this->assertNull( $data['ends_at'] );
	}

	public function test_start_a_timed_freeze_with_a_message(): void {
		$this->login_as( 'administrator' );

		$data = $this->request( 'POST', '/freeze', array( 'for' => '30m', 'message' => 'Deploying release 12.' ) )->get_data();

		$this->assertSame( 'timed', $data['result'] );
		$this->assertTrue( $data['active'] );
		$this->assertFalse( $data['manual_enabled'] );
		$this->assertEqualsWithDelta( 1800, $data['seconds_left'], 5 );
		$this->assertNotNull( $data['ends_at'] );
		$this->assertSame( 'Deploying release 12.', $data['message'] );
	}

	/**
	 * @dataProvider provide_bad_freeze_requests
	 */
	public function test_bad_freeze_requests_are_refused_and_change_nothing( array $params, string $expected_code ): void {
		$this->login_as( 'administrator' );

		$response = $this->request( 'POST', '/freeze', $params );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $expected_code, $this->error_code( $response ) );
		$this->assertFalse( $this->service->is_active() );
	}

	public static function provide_bad_freeze_requests(): array {
		return array(
			'unparseable duration'     => array( array( 'for' => 'soon' ), 'invalid_duration' ),
			'iso duration'             => array( array( 'for' => 'PT30M' ), 'invalid_duration' ),
			'shorter than a minute'    => array( array( 'for' => '30s' ), 'duration_too_short' ),
			'longer than a window'     => array( array( 'for' => '8d' ), 'window_too_long' ),
			'message that is too long' => array( array( 'for' => '30m', 'message' => str_repeat( 'x', 501 ) ), 'message_too_long' ),
		);
	}

	public function test_end_the_freeze_and_ending_again_is_unchanged(): void {
		$this->login_as( 'administrator' );
		$this->request( 'POST', '/freeze', array( 'for' => '1h' ) );

		$first = $this->request( 'DELETE', '/freeze' );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 'disabled', $first->get_data()['result'] );
		$this->assertFalse( $first->get_data()['active'] );

		$second = $this->request( 'DELETE', '/freeze' );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( 'unchanged', $second->get_data()['result'] );
	}

	public function test_the_api_keeps_working_while_a_freeze_is_active(): void {
		$this->login_as( 'administrator' );
		$this->request( 'POST', '/freeze' );
		$this->assertTrue( $this->service->is_active() );

		// RestGate is still registered for the plugin's own guard.
		$this->assertSame( 200, $this->request( 'GET', '/status' )->get_status() );
		$this->assertSame( 200, $this->request( 'DELETE', '/freeze' )->get_status() );
		$this->assertFalse( $this->service->is_active() );
	}

	public function test_extend_a_timed_freeze(): void {
		$this->login_as( 'administrator' );
		$this->request( 'POST', '/freeze', array( 'for' => '10m' ) );

		$response = $this->request( 'POST', '/freeze/extend', array( 'by' => '15m' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'extended', $response->get_data()['result'] );
		$this->assertEqualsWithDelta( 1500, $response->get_data()['seconds_left'], 5 );
	}

	public function test_extending_a_manual_freeze_is_a_conflict(): void {
		$this->login_as( 'administrator' );
		$this->request( 'POST', '/freeze' );

		$response = $this->request( 'POST', '/freeze/extend', array( 'by' => '15m' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'not_extendable', $this->error_code( $response ) );
	}

	public function test_extend_needs_a_valid_duration(): void {
		$this->login_as( 'administrator' );
		$this->request( 'POST', '/freeze', array( 'for' => '10m' ) );

		$this->assertSame( 400, $this->request( 'POST', '/freeze/extend' )->get_status(), 'missing by' );
		$this->assertSame( 'invalid_duration', $this->error_code( $this->request( 'POST', '/freeze/extend', array( 'by' => 'later' ) ) ) );
	}

	public function test_schedule_a_window_with_iso_times_and_clear_it(): void {
		$this->login_as( 'administrator' );
		$start = gmdate( 'c', time() + 3600 );
		$end   = gmdate( 'c', time() + 7200 );

		$response = $this->request( 'POST', '/schedule', array( 'start' => $start, 'end' => $end, 'message' => 'Night window.' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'scheduled', $data['result'] );
		$this->assertSame( 'upcoming', $data['schedule_status'] );
		$this->assertFalse( $data['active'] );
		$this->assertSame( $start, $data['schedule_start'] );
		$this->assertSame( 'Night window.', $data['message'] );

		$cleared = $this->request( 'DELETE', '/schedule' );
		$this->assertSame( 'schedule_cleared', $cleared->get_data()['result'] );
		$this->assertSame( 'none', $cleared->get_data()['schedule_status'] );
	}

	public function test_schedule_accepts_now_and_site_time(): void {
		$this->login_as( 'administrator' );

		$response = $this->request( 'POST', '/schedule', array( 'start' => 'now', 'end' => gmdate( 'c', time() + 600 ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['active'] );
	}

	/**
	 * @dataProvider provide_bad_schedules
	 */
	public function test_bad_schedules_are_refused( array $params, string $expected_code ): void {
		$this->login_as( 'administrator' );

		$response = $this->request( 'POST', '/schedule', $params );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $expected_code, $this->error_code( $response ) );
		$this->assertSame( 'none', $this->service->config()->schedule_status( time() ) );
	}

	public static function provide_bad_schedules(): array {
		$future = gmdate( 'c', time() + 3600 );

		return array(
			'unparseable start'  => array( array( 'start' => 'tomorrow-ish', 'end' => $future ), 'invalid_start' ),
			'unparseable end'    => array( array( 'start' => 'now', 'end' => 'garbage' ), 'invalid_end' ),
			'end before start'   => array( array( 'start' => $future, 'end' => gmdate( 'c', time() + 60 ) ), 'end_not_after_start' ),
			'window too long'    => array( array( 'start' => 'now', 'end' => gmdate( 'c', time() + 8 * DAY_IN_SECONDS ) ), 'window_too_long' ),
		);
	}

	public function test_schedule_requires_both_times(): void {
		$this->login_as( 'administrator' );

		$this->assertSame( 400, $this->request( 'POST', '/schedule', array( 'start' => 'now' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/schedule', array( 'end' => gmdate( 'c', time() + 600 ) ) )->get_status() );
	}

	public function test_changes_are_recorded_as_coming_from_the_rest_api(): void {
		$this->login_as( 'administrator' );

		$this->request( 'POST', '/freeze', array( 'for' => '20m' ) );
		$this->request( 'DELETE', '/freeze' );

		$entries = $this->service->entries();
		$this->assertSame( 'disabled', $entries[0]['type'] );
		$this->assertSame( 'rest', $entries[0]['ctx']['source'] );
		$this->assertSame( 'rest', $entries[1]['ctx']['source'] );
	}
}
