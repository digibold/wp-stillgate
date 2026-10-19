<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Receipt;
use Digibold\DeploymentGuard\Rest\Controller;
use Digibold\DeploymentGuard\Scope;

/**
 * The /scope and /receipt routes of the stillgate/v1 REST API.
 */
final class RestScopeReceiptTest extends TestCase {

	private \WP_REST_Server $server;

	private FreezeService $service;

	private Receipt $receipt;

	public function set_up(): void {
		parent::set_up();

		$this->service = $this->make_service( 0 );
		$guard         = $this->make_guard( $this->service );
		$this->receipt = new Receipt( $this->service, $guard, static fn (): int => time() );
		$this->receipt->register();

		$this->server              = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $this->server;
		remove_all_actions( 'rest_api_init' );
		( new Controller( $this->service, $this->receipt ) )->register();
		do_action( 'rest_api_init', $this->server );
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null;

		parent::tear_down();
	}

	private function request( string $method, string $route, array $params = array(), ?string $json = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/stillgate/v1' . $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		if ( null !== $json ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( $json );
		}

		return $this->server->dispatch( $request );
	}

	public function test_scope_is_readable_by_an_administrator_and_defaults_to_off(): void {
		$this->login_as( 'administrator' );

		$response = $this->request( 'GET', '/scope' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'comments' => false, 'forms' => false ), $response->get_data() );
	}

	public function test_scope_can_be_changed_with_form_params(): void {
		$this->login_as( 'administrator' );

		$response = $this->request( 'POST', '/scope', array( 'comments' => true, 'forms' => 'true' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'scope_saved', $response->get_data()['result'] );
		$this->assertTrue( $this->service->scope()->comments );
		$this->assertTrue( $this->service->scope()->forms );
	}

	public function test_scope_can_be_changed_with_a_json_body_and_a_single_flag_leaves_the_other(): void {
		$this->login_as( 'administrator' );
		$this->service->set_scope( new Scope( true, true ) );

		$response = $this->request( 'POST', '/scope', array(), '{"forms": false}' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $this->service->scope()->comments, 'not mentioned: unchanged' );
		$this->assertFalse( $this->service->scope()->forms );
	}

	public function test_setting_the_same_scope_again_is_unchanged(): void {
		$this->login_as( 'administrator' );
		$this->request( 'POST', '/scope', array( 'comments' => true ) );

		$again = $this->request( 'POST', '/scope', array( 'comments' => true ) );

		$this->assertSame( 'unchanged', $again->get_data()['result'] );
	}

	public function test_changing_the_scope_needs_the_same_permission_as_changing_the_freeze(): void {
		$this->login_as( 'editor' );
		$this->assertSame( 403, $this->request( 'GET', '/scope' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/scope', array( 'forms' => true ) )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'POST', '/scope', array( 'forms' => true ) )->get_status() );
		$this->assertTrue( $this->service->scope()->is_empty() );
	}

	public function test_status_includes_the_scope(): void {
		$this->login_as( 'administrator' );
		$this->service->set_scope( new Scope( false, true ) );

		$data = $this->request( 'GET', '/status' )->get_data();

		$this->assertSame( array( 'comments' => false, 'forms' => true ), $data['scope'] );
	}

	public function test_receipt_is_404_free_and_reports_no_freeze_before_any(): void {
		$this->login_as( 'administrator' );

		$response = $this->request( 'GET', '/receipt' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'none', $response->get_data()['state'] );
	}

	public function test_receipt_reports_a_freeze_and_what_changed(): void {
		$this->login_as( 'administrator' );
		$this->request( 'POST', '/freeze' );
		$new = self::factory()->post->create( array( 'post_title' => 'During the freeze' ) );

		$data = $this->request( 'GET', '/receipt' )->get_data();

		$this->assertSame( 'open', $data['state'] );
		$this->assertSame( 'rest', $data['source'] );
		$this->assertFalse( $data['clean'] );
		$this->assertContains( $new, $data['content']['changed_ids'] );
	}

	public function test_receipt_needs_manage_options(): void {
		$this->login_as( 'editor' );

		$this->assertSame( 403, $this->request( 'GET', '/receipt' )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'GET', '/receipt' )->get_status() );
	}

	public function test_receipt_route_without_a_receipt_service_is_a_clean_404(): void {
		$server                    = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		remove_all_actions( 'rest_api_init' );
		( new Controller( $this->service ) )->register();
		do_action( 'rest_api_init', $server );
		$this->login_as( 'administrator' );

		$response = $server->dispatch( new \WP_REST_Request( 'GET', '/stillgate/v1/receipt' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'receipt_unavailable', $response->get_data()['code'] );
	}
}
