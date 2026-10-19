<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Enforcement\FormGate;
use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Receipt;
use Digibold\DeploymentGuard\Scope;

/**
 * Form submissions are recognized by what the request looks like (an
 * admin-ajax action, a REST route, a POST field) and refused while a freeze
 * runs when the site chose to pause forms. The same recognition is checked
 * against the real form plugins by tests/e2e/forms.sh.
 */
final class FormGateTest extends TestCase {

	private FreezeService $service;

	private FormGate $gate;

	private Receipt $receipt;

	private array $server_backup;

	public function set_up(): void {
		parent::set_up();

		$this->server_backup = $_SERVER;
		$this->service       = $this->make_service( 0 );
		$guard               = $this->make_guard( $this->service );
		$this->receipt       = new Receipt( $this->service, $guard, static fn (): int => time() );
		$this->gate          = new FormGate( $guard, $this->receipt );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		$_SERVER  = $this->server_backup;

		parent::tear_down();
	}

	private function pause_forms(): void {
		$this->service->set_scope( new Scope( false, true ) );
		$this->service->enable();
	}

	private function as_post( array $fields ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = $fields;
		$_REQUEST                  = $fields;
	}

	/**
	 * @return string|null The wp_die message, or null if nothing was refused.
	 */
	private function refused_page(): ?string {
		try {
			$this->gate->guard_post_request();
		} catch ( \WPDieException $exception ) {
			return $exception->getMessage();
		}

		return null;
	}

	/**
	 * @return array|null The JSON error payload, or null if nothing was refused.
	 */
	private function refused_ajax(): ?array {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ), 1, 1 );
		ob_start();

		try {
			$this->gate->guard_ajax_request();
		} catch ( \WPDieException $exception ) {
			$output = ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );

			return json_decode( $output, true );
		}

		ob_end_clean();
		remove_filter( 'wp_doing_ajax', '__return_true' );

		return null;
	}

	private function rest_request( string $method, string $route ): \WP_REST_Request {
		return new \WP_REST_Request( $method, $route );
	}

	/**
	 * @dataProvider provide_form_posts
	 */
	public function test_a_visitor_form_post_is_refused_while_forms_are_paused( array $fields ): void {
		$this->pause_forms();
		$this->as_post( $fields );

		$message = $this->refused_page();

		$this->assertNotNull( $message );
		$this->assertStringContainsString( 'Form submissions are temporarily paused', $message );
	}

	public static function provide_form_posts(): array {
		return array(
			'Contact Form 7'     => array( array( '_wpcf7' => '12', 'your-name' => 'A' ) ),
			'Gravity Forms'      => array( array( 'gform_submit' => '3', 'input_1' => 'A' ) ),
			'WPForms'            => array( array( 'wpforms' => array( 'id' => '5', 'fields' => array( 'A' ) ) ) ),
			'Formidable (create)' => array( array( 'frm_action' => 'create', 'form_id' => '2' ) ),
		);
	}

	public function test_an_unrelated_post_is_never_refused(): void {
		$this->pause_forms();

		foreach ( array(
			array( 's' => 'search words' ),
			array( 'frm_action' => 'update' ),
			array( 'log' => 'user', 'pwd' => 'secret' ),
			array( 'comment' => 'hi', 'comment_post_ID' => '1' ),
		) as $fields ) {
			$this->as_post( $fields );
			$this->assertNull( $this->refused_page(), wp_json_encode( $fields ) );
		}
	}

	public function test_a_get_request_is_never_refused(): void {
		$this->pause_forms();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array( '_wpcf7' => '12' );

		$this->assertNull( $this->refused_page() );
	}

	public function test_nothing_is_refused_when_forms_are_not_in_scope(): void {
		$this->service->set_scope( new Scope( true, false ) );
		$this->service->enable();
		$this->as_post( array( '_wpcf7' => '12' ) );

		$this->assertNull( $this->refused_page() );
	}

	public function test_nothing_is_refused_when_no_freeze_is_active(): void {
		$this->service->set_scope( new Scope( true, true ) );
		$this->as_post( array( '_wpcf7' => '12' ) );

		$this->assertNull( $this->refused_page() );
	}

	public function test_forms_work_again_when_the_freeze_ends(): void {
		$this->pause_forms();
		$this->as_post( array( '_wpcf7' => '12' ) );
		$this->assertNotNull( $this->refused_page() );

		$this->service->disable();

		$this->assertNull( $this->refused_page() );
	}

	public function test_someone_who_can_bypass_the_freeze_can_still_submit_a_form(): void {
		$this->pause_forms();
		$this->login_as( 'administrator' );
		$this->as_post( array( '_wpcf7' => '12' ) );

		$this->assertNull( $this->refused_page() );
	}

	public function test_a_logged_in_customer_is_paused_like_a_visitor(): void {
		$this->pause_forms();
		$this->login_as( 'subscriber' );
		$this->as_post( array( 'gform_submit' => '3' ) );

		$this->assertNotNull( $this->refused_page() );
	}

	public function test_a_rest_request_is_left_to_the_rest_gate_on_init(): void {
		$this->pause_forms();
		$this->as_post( array( '_wpcf7' => '12' ) );
		$_SERVER['REQUEST_URI'] = '/wp-json/contact-form-7/v1/contact-forms/12/feedback';

		$this->assertNull( $this->refused_page(), 'handled as a REST request, not as an HTML page' );

		$_SERVER['REQUEST_URI'] = '/index.php?rest_route=/contact-form-7/v1/contact-forms/12/feedback';
		$_GET                   = array( 'rest_route' => '/contact-form-7/v1/contact-forms/12/feedback' );

		$this->assertNull( $this->refused_page() );
	}

	/**
	 * @dataProvider provide_ajax_actions
	 */
	public function test_a_form_ajax_action_is_refused_with_json( string $action ): void {
		$this->pause_forms();
		$this->as_post( array( 'action' => $action ) );

		$payload = $this->refused_ajax();

		$this->assertNotNull( $payload );
		$this->assertFalse( $payload['success'] );
		$this->assertSame( 'stillgate_forms_paused', $payload['data']['code'] );
		$this->assertStringContainsString( 'temporarily paused', $payload['data']['message'] );
	}

	public static function provide_ajax_actions(): array {
		return array(
			'WPForms'     => array( 'wpforms_submit' ),
			'Ninja Forms' => array( 'nf_ajax_submit' ),
			'Fluent Forms' => array( 'fluentform_submit' ),
			'Forminator'  => array( 'forminator_submit_form_custom-forms' ),
			'Formidable'  => array( 'frm_entries_create' ),
			'Elementor'   => array( 'elementor_pro_forms_send_form' ),
			'Everest'     => array( 'everest_forms_ajax_form_submission' ),
		);
	}

	public function test_other_ajax_actions_are_not_touched(): void {
		$this->pause_forms();
		$this->as_post( array( 'action' => 'heartbeat' ) );

		$this->assertNull( $this->refused_ajax() );
	}

	public function test_ajax_is_not_inspected_outside_an_ajax_request(): void {
		$this->pause_forms();
		$this->as_post( array( 'action' => 'wpforms_submit' ) );

		$this->gate->guard_ajax_request();

		$this->addToAssertionCount( 1 );
	}

	public function test_a_form_rest_route_is_refused_with_423(): void {
		$this->pause_forms();

		foreach ( array( '/contact-form-7/v1/contact-forms/12/feedback', '/contact-form-7/v1/contact-forms/abc1234/feedback', '/gf/v2/forms/3/submissions' ) as $route ) {
			$result = $this->gate->guard_rest_request( null, null, $this->rest_request( 'POST', $route ) );

			$this->assertWPError( $result, $route );
			$this->assertSame( 'stillgate_forms_paused', $result->get_error_code() );
			$this->assertSame( 423, $result->get_error_data()['status'] );
		}
	}

	public function test_other_rest_requests_are_not_touched(): void {
		$this->pause_forms();

		$this->assertNull( $this->gate->guard_rest_request( null, null, $this->rest_request( 'POST', '/wp/v2/posts' ) ) );
		$this->assertNull( $this->gate->guard_rest_request( null, null, $this->rest_request( 'GET', '/contact-form-7/v1/contact-forms/12/feedback' ) ) );
		$this->assertSame( 'kept', $this->gate->guard_rest_request( 'kept', null, $this->rest_request( 'POST', '/gf/v2/forms/3/submissions' ) ), 'an earlier short-circuit is respected' );
	}

	public function test_the_recognition_can_be_extended_with_a_filter(): void {
		$this->pause_forms();
		add_filter(
			'deployment_guard_form_requests',
			static function ( $requests ) {
				$requests['ajax_actions'][]        = 'my_custom_form_submit';
				$requests['rest_routes'][]         = '#^/my-forms/v1/entries#';
				$requests['post_fields']['my_form'] = null;

				return $requests;
			}
		);

		$this->as_post( array( 'action' => 'my_custom_form_submit' ) );
		$this->assertNotNull( $this->refused_ajax() );

		$this->as_post( array( 'my_form' => '1' ) );
		$this->assertNotNull( $this->refused_page() );

		$this->assertWPError( $this->gate->guard_rest_request( null, null, $this->rest_request( 'POST', '/my-forms/v1/entries' ) ) );
	}

	public function test_a_broken_filter_result_cannot_disable_or_break_the_gate(): void {
		$this->pause_forms();
		add_filter( 'deployment_guard_form_requests', static fn () => 'not an array' );
		$this->as_post( array( '_wpcf7' => '12' ) );

		$this->assertNotNull( $this->refused_page(), 'falls back to the built-in list' );
	}

	public function test_refusals_are_counted_in_the_receipt(): void {
		$this->pause_forms();
		$this->as_post( array( '_wpcf7' => '12' ) );

		$this->refused_page();
		$this->refused_page();

		$this->assertSame( 2, $this->receipt->report()['paused']['forms'] );
		$this->assertSame( 0, $this->receipt->report()['paused']['comments'] );
	}

	public function test_the_pause_is_filterable_for_custom_code(): void {
		$this->pause_forms();
		add_filter( 'deployment_guard_submissions_paused', '__return_false' );
		$this->as_post( array( '_wpcf7' => '12' ) );

		$this->assertNull( $this->refused_page() );
	}
}
