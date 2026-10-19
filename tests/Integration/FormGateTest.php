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
			'Everest Forms'       => array( array( 'everest_forms' => array( 'id' => '8', 'form_fields' => array( 'A' ) ) ) ),
			'Forminator (no AJAX)' => array( array( 'action' => 'forminator_submit_form_custom-forms', 'form_id' => '7' ) ),
		);
	}

	public function test_an_unrelated_post_is_never_refused(): void {
		$this->pause_forms();

		foreach ( array(
			array( 's' => 'search words' ),
			array( 'frm_action' => 'update' ),
			array( 'action' => 'some_other_action' ),
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

		$this->assertNotNull( $payload, $action );
		$this->assertFalse( $payload['success'] ?? $payload['pass'], $action . ' says the submission did not go through' );
		$this->assertStringContainsString( 'temporarily paused', wp_json_encode( $payload ), $action );
	}

	public static function provide_ajax_actions(): array {
		return array(
			'WPForms'      => array( 'wpforms_submit' ),
			'Ninja Forms'  => array( 'nf_ajax_submit' ),
			'Fluent Forms' => array( 'fluentform_submit' ),
			'Forminator'   => array( 'forminator_submit_form_custom-forms' ),
			'Formidable'   => array( 'frm_entries_create' ),
			'Elementor'    => array( 'elementor_pro_forms_send_form' ),
			'Everest'      => array( 'everest_forms_ajax_form_submission' ),
		);
	}

	public function test_a_form_without_an_adapter_gets_the_plain_error_with_a_code(): void {
		$this->pause_forms();
		$this->as_post( array( 'action' => 'elementor_pro_forms_send_form' ) );

		$payload = $this->refused_ajax();

		$this->assertSame( 'stillgate_forms_paused', $payload['data']['code'] );
		$this->assertStringContainsString( 'temporarily paused', $payload['data']['message'] );
	}

	public function test_formidable_is_answered_with_text_html_json_and_the_message(): void {
		$this->pause_forms();
		$this->as_post( array( 'action' => 'frm_entries_create' ) );

		$payload = $this->refused_ajax();

		$this->assertNotNull( $payload );
		$this->assertFalse( $payload['pass'] );
		$this->assertStringContainsString( 'temporarily paused', $payload['errors']['stillgate_forms_paused'] );
		$this->assertSame( 1, $this->receipt->report()['paused']['forms'], 'one attempt counts once' );
	}

	public function test_the_ajax_gate_runs_on_init_as_well_as_admin_init(): void {
		$this->gate->register();

		$this->assertSame( 1, has_action( 'init', array( $this->gate, 'guard_ajax_request' ) ) );
		$this->assertSame( 1, has_action( 'admin_init', array( $this->gate, 'guard_ajax_request' ) ) );
	}

	public function test_a_front_end_request_that_defines_doing_ajax_is_still_checked_as_a_post(): void {
		$this->pause_forms();
		set_current_screen( 'front' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ), 1, 1 );
		$this->as_post( array( 'everest_forms' => array( 'id' => '8' ) ) );

		try {
			$this->assertNotNull( $this->refused_page(), 'DOING_AJAX alone does not exempt a front-end form post' );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ), 1 );
		}
	}

	public function test_a_real_admin_ajax_post_is_left_to_the_ajax_gate(): void {
		$this->pause_forms();
		set_current_screen( 'dashboard' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->as_post( array( 'action' => 'forminator_submit_form_custom-forms' ) );

		try {
			$this->assertNull( $this->refused_page() );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			set_current_screen( 'front' );
		}
	}

	public function test_an_administrator_whose_rest_request_carries_only_the_cookie_is_not_refused(): void {
		$this->pause_forms();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( 0 );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $admin_id, time() + HOUR_IN_SECONDS, 'logged_in' );

		try {
			$result = $this->gate->guard_rest_request( null, null, $this->rest_request( 'POST', '/gf/v2/forms/3/submissions' ) );

			$this->assertNull( $result, 'a signed login cookie of somebody who can bypass the freeze lets the submission through' );
			$this->assertSame( 0, $this->receipt->report()['paused']['forms'] );
		} finally {
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		}
	}

	public function test_a_forged_or_expired_login_cookie_does_not_bypass_the_pause(): void {
		$this->pause_forms();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( 0 );

		try {
			$_COOKIE[ LOGGED_IN_COOKIE ] = 'admin|' . ( time() + HOUR_IN_SECONDS ) . '|token|' . str_repeat( 'a', 64 );
			$this->assertWPError( $this->gate->guard_rest_request( null, null, $this->rest_request( 'POST', '/gf/v2/forms/3/submissions' ) ), 'forged' );

			$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $admin_id, time() - HOUR_IN_SECONDS, 'logged_in' );
			$this->assertWPError( $this->gate->guard_rest_request( null, null, $this->rest_request( 'POST', '/gf/v2/forms/3/submissions' ) ), 'expired' );
		} finally {
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		}
	}

	public function test_a_cookie_of_somebody_without_the_bypass_capability_does_not_bypass_the_pause(): void {
		$this->pause_forms();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( 0 );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in' );

		try {
			$this->assertWPError( $this->gate->guard_rest_request( null, null, $this->rest_request( 'POST', '/gf/v2/forms/3/submissions' ) ) );
		} finally {
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		}
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

	/**
	 * @dataProvider provide_differently_cased_routes
	 */
	public function test_a_differently_cased_rest_route_is_refused_like_the_lowercase_one( string $route ): void {
		$this->pause_forms();

		$result = $this->gate->guard_rest_request( null, null, $this->rest_request( 'POST', $route ) );

		$this->assertNotNull( $result, $route );
	}

	public static function provide_differently_cased_routes(): array {
		return array(
			'mixed'          => array( '/Contact-Form-7/v1/contact-forms/12/feedback' ),
			'upper'          => array( '/CONTACT-FORM-7/V1/CONTACT-FORMS/12/FEEDBACK' ),
			'last segment'   => array( '/contact-form-7/v1/contact-forms/12/Feedback' ),
			'Gravity upper'  => array( '/GF/v2/forms/3/Submissions' ),
		);
	}

	public function test_a_form_post_sent_to_a_url_that_only_looks_like_rest_or_ajax_is_still_refused(): void {
		$this->pause_forms();
		$this->as_post( array( '_wpcf7' => '12', 'your-name' => 'A' ) );

		// A REST-looking URL no REST check recognizes.
		$_SERVER['REQUEST_URI'] = '/wp-json/anything/at/all';
		$this->assertNotNull( $this->refused_page(), 'wp-json URL that matches no form route' );

		$_SERVER['REQUEST_URI'] = '/index.php?rest_route=/x';
		$_GET                   = array( 'rest_route' => '/x' );
		$this->assertNotNull( $this->refused_page(), 'rest_route that matches no form route' );
		$_GET = array();

		// admin-ajax.php with an action no AJAX check recognizes.
		set_current_screen( 'dashboard' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ), 1, 1 );
		$this->as_post( array( '_wpcf7' => '12', 'action' => 'unlisted_action' ) );

		try {
			$this->assertNotNull( $this->refused_page(), 'admin-ajax.php with an unlisted action' );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ), 1 );
			set_current_screen( 'front' );
		}
	}

	public function test_a_recognized_rest_route_in_any_case_is_left_to_the_rest_check(): void {
		$this->pause_forms();
		$this->as_post( array( '_wpcf7' => '12' ) );
		$_SERVER['REQUEST_URI'] = '/wp-json/Contact-Form-7/v1/contact-forms/12/feedback';

		$this->assertNull( $this->refused_page() );
	}

	public function test_a_partial_filter_result_keeps_the_built_in_list_for_the_missing_kinds(): void {
		$this->pause_forms();
		add_filter( 'deployment_guard_form_requests', static fn () => array( 'ajax_actions' => array( 'my_form' ) ) );

		$this->as_post( array( '_wpcf7' => '12' ) );
		$this->assertNotNull( $this->refused_page(), 'post fields fall back to the defaults' );

		$result = $this->gate->guard_rest_request( null, null, $this->rest_request( 'POST', '/gf/v2/forms/3/submissions' ) );
		$this->assertWPError( $result, 'rest routes fall back to the defaults' );
	}

	public function test_a_site_can_still_recognize_nothing_of_a_kind_by_returning_an_empty_list(): void {
		$this->pause_forms();
		add_filter(
			'deployment_guard_form_requests',
			static fn ( $requests ) => array_merge( $requests, array( 'post_fields' => array() ) )
		);

		$this->as_post( array( '_wpcf7' => '12' ) );

		$this->assertNull( $this->refused_page() );
	}

	public function test_a_form_rest_route_is_refused_with_423(): void {
		$this->pause_forms();

		$result = $this->gate->guard_rest_request( null, null, $this->rest_request( 'POST', '/gf/v2/forms/3/submissions' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'stillgate_forms_paused', $result->get_error_code() );
		$this->assertSame( 423, $result->get_error_data()['status'] );
	}

	public function test_contact_form_7_is_answered_in_the_shape_its_script_displays(): void {
		$this->pause_forms();

		foreach ( array( '/contact-form-7/v1/contact-forms/12/feedback', '/contact-form-7/v1/contact-forms/abc1234/feedback' ) as $route ) {
			$request = $this->rest_request( 'POST', $route );
			$request->set_param( '_wpcf7_unit_tag', 'wpcf7-f12-p5-o1' );

			$result = $this->gate->guard_rest_request( null, null, $request );

			$this->assertInstanceOf( \WP_REST_Response::class, $result, $route );
			$this->assertSame( 200, $result->get_status(), 'its script only reads a 2xx answer' );
			$this->assertSame( 'aborted', $result->get_data()['status'] );
			$this->assertSame( '#wpcf7-f12-p5-o1', $result->get_data()['into'] );
			$this->assertStringContainsString( 'temporarily paused', $result->get_data()['message'] );
			$this->assertSame( 'forms', $result->get_headers()['X-Stillgate-Paused'] );
		}

		$this->assertSame( 2, $this->receipt->report()['paused']['forms'] );
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
