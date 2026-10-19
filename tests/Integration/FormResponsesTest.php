<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Enforcement\FormResponses;

/**
 * The refusal is shaped to what each form plugin's own script displays.
 * These shapes were observed on real sites and read from the plugins'
 * front-end scripts: see docs/forms.md.
 */
final class FormResponsesTest extends TestCase {

	private FormResponses $responses;

	private const MESSAGE = 'Forms are paused until 14:00 & back soon.';

	public function set_up(): void {
		parent::set_up();

		$this->responses = new FormResponses();
	}

	public function test_wpforms_gets_a_2xx_answer_with_its_general_error_html(): void {
		$answer = $this->responses->ajax( 'wpforms_submit', self::MESSAGE );

		$this->assertSame( 200, $answer['status'], 'its script displays server messages from 2xx answers only' );
		$this->assertFalse( $answer['body']['success'] );

		$html = $answer['body']['data']['errors']['general']['footer_styled'];

		$this->assertStringContainsString( 'role="alert"', $html );
		$this->assertStringContainsString( 'Forms are paused until 14:00 &amp; back soon.', $html, 'the message is escaped because the script inserts it as HTML' );
	}

	public function test_ninja_forms_gets_the_errors_form_envelope_it_requires(): void {
		$answer = $this->responses->ajax( 'nf_ajax_submit', self::MESSAGE );

		$this->assertSame( 423, $answer['status'] );
		$this->assertSame( self::MESSAGE, $answer['body']['errors']['form']['email_not_sent'], 'the key its script does not treat as blocking' );
		$this->assertIsArray( $answer['body']['data'], 'its script needs data to be an object' );
		$this->assertSame( array(), $answer['body']['debug'] );
	}

	public function test_fluent_forms_gets_a_message_under_a_key_that_is_not_a_field(): void {
		$answer = $this->responses->ajax( 'fluentform_submit', self::MESSAGE );

		$this->assertSame( 423, $answer['status'] );
		$this->assertSame( array( self::MESSAGE ), $answer['body']['errors']['stillgate_forms_paused'] );
	}

	public function test_formidable_gets_a_2xx_html_answer_so_its_script_shows_the_message(): void {
		$answer = $this->responses->ajax( 'frm_entries_create', self::MESSAGE );

		$this->assertSame( 200, $answer['status'], 'any other status makes its script submit the form natively' );
		$this->assertSame( 'text/html; charset=UTF-8', $answer['content_type'], 'its script calls replace() on the answer' );
		$this->assertFalse( $answer['body']['pass'] );
		$this->assertSame( '', $answer['body']['content'] );
		$this->assertStringContainsString( 'Forms are paused until 14:00 &amp; back soon.', $answer['body']['errors']['stillgate_forms_paused'] );
		$this->assertArrayHasKey( 'error_message', $answer['body'] );
	}

	public function test_forminator_gets_a_2xx_answer_with_an_escaped_message(): void {
		$answer = $this->responses->ajax( 'forminator_submit_form_custom-forms', self::MESSAGE );

		$this->assertSame( 200, $answer['status'] );
		$this->assertFalse( $answer['body']['success'] );
		$this->assertFalse( $answer['body']['data']['success'] );
		$this->assertSame( 'error', $answer['body']['data']['notice'] );
		$this->assertSame( array(), $answer['body']['data']['errors'] );
		$this->assertStringContainsString( '&amp;', $answer['body']['data']['message'] );
	}

	public function test_everest_forms_gets_a_2xx_answer_with_a_message_string(): void {
		$answer = $this->responses->ajax( 'everest_forms_ajax_form_submission', self::MESSAGE );

		$this->assertSame( 200, $answer['status'] );
		$this->assertSame( self::MESSAGE, $answer['body']['data']['message'] );
	}

	public function test_an_unknown_form_gets_the_plain_423_error_with_a_code(): void {
		$answer = $this->responses->ajax( 'something_else', self::MESSAGE );
		$this->assertArrayNotHasKey( 'content_type', $answer );

		$this->assertSame( 423, $answer['status'] );
		$this->assertSame( 'stillgate_forms_paused', $answer['body']['data']['code'] );
		$this->assertSame( self::MESSAGE, $answer['body']['data']['message'] );
	}

	public function test_every_answer_says_the_submission_failed(): void {
		foreach ( array( 'wpforms_submit', 'nf_ajax_submit', 'fluentform_submit', 'forminator_submit_form_custom-forms', 'everest_forms_ajax_form_submission', 'x' ) as $action ) {
			$this->assertFalse( $this->responses->ajax( $action, self::MESSAGE )['body']['success'], $action );
		}
	}

	public function test_a_rest_route_without_an_adapter_is_left_for_the_default_error(): void {
		$request = new \WP_REST_Request( 'POST', '/gf/v2/forms/3/submissions' );

		$this->assertNull( $this->responses->rest( '/gf/v2/forms/3/submissions', $request, self::MESSAGE ) );
	}

	public function test_the_contact_form_7_unit_tag_is_sanitized(): void {
		$request = new \WP_REST_Request( 'POST', '/contact-form-7/v1/contact-forms/12/feedback' );
		$request->set_param( '_wpcf7_unit_tag', 'wpcf7-f12"><script>alert(1)</script>' );

		$data = $this->responses->rest( '/contact-form-7/v1/contact-forms/12/feedback', $request, self::MESSAGE )->get_data();

		$this->assertSame( '#wpcf7-f12scriptalert1script', $data['into'] );
		$this->assertSame( 12, $data['contact_form_id'] );
	}

	public function test_a_missing_unit_tag_gives_no_selector(): void {
		$request = new \WP_REST_Request( 'POST', '/contact-form-7/v1/contact-forms/12/feedback' );

		$this->assertSame( '', $this->responses->rest( '/contact-form-7/v1/contact-forms/12/feedback', $request, self::MESSAGE )->get_data()['into'] );
	}
}
