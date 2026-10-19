<?php
/**
 * How a refused form submission is answered, per form plugin.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Enforcement;

defined( 'ABSPATH' ) || exit;

/**
 * A form plugin's own script only shows a message from the server when the
 * answer looks like one of its own. Anything else ends in a spinner that
 * never stops, a button that stays disabled, or a generic "something went
 * wrong", and the visitor never learns the form is paused. So the refusal
 * is shaped to what each script reads, and falls back to a plain 423 JSON
 * error for any form Stillgate has no adapter for (still refused, only
 * less pleasant to look at).
 *
 * The shapes follow the plugins' own error answers, observed on real sites
 * and read from their front-end scripts (see docs/forms.md for what was
 * run in a browser and what was only read). Every answer keeps
 * `success: false`, and carries the code `stillgate_forms_paused` wherever
 * the plugin's script tolerates an extra key, so a script or monitor can
 * tell a pause from a real failure.
 */
final class FormResponses {

	public const CODE = 'stillgate_forms_paused';

	/**
	 * The answer to a refused admin-ajax.php form submission.
	 *
	 * @param string $action  The admin-ajax.php action.
	 * @param string $message The visitor message, plain text.
	 * @return array{status: int, body: array<string, mixed>}
	 */
	public function ajax( string $action, string $message ): array {
		$plain = array(
			'code'    => self::CODE,
			'message' => $message,
		);

		switch ( $action ) {
			case 'wpforms_submit':
				// WPForms shows `data.errors.general.footer_styled` above the
				// button, but only from a 2xx answer.
				return array(
					'status' => 200,
					'body'   => array(
						'success' => false,
						'data'    => array(
							'errors' => array(
								'general' => array(
									'footer_styled' => '<div class="wpforms-error-container wpforms-error-styled-container" role="alert"><div class="wpforms-error"><p>' . esc_html( $message ) . '</p></div></div>',
								),
							),
						),
					),
				);

			case 'nf_ajax_submit':
				// Ninja Forms' script reads `errors.form` on every answer and
				// throws without it, which would leave the button disabled.
				return array(
					'status' => 423,
					'body'   => array(
						'success' => false,
						'data'    => $plain,
						'errors'  => array(
							'form' => array( self::CODE => $message ),
						),
						'debug'   => array(),
					),
				);

			case 'fluentform_submit':
				// Fluent Forms shows each key of `errors` as a message; a key
				// that is not a field name is shown under the form.
				return array(
					'status' => 423,
					'body'   => array(
						'success' => false,
						'data'    => $plain,
						'errors'  => array(
							self::CODE => array( $message ),
						),
					),
				);

			case 'forminator_submit_form_custom-forms':
				// Forminator inserts the message as HTML, from a 2xx answer.
				return array(
					'status' => 200,
					'body'   => array(
						'success' => false,
						'data'    => array(
							'code'    => self::CODE,
							'message' => esc_html( $message ),
							'success' => false,
							'notice'  => 'error',
							'errors'  => array(),
						),
					),
				);

			case 'everest_forms_ajax_form_submission':
				// Everest Forms reads `data.message` from a 2xx answer.
				return array(
					'status' => 200,
					'body'   => array(
						'success' => false,
						'data'    => $plain,
					),
				);
		}

		return array(
			'status' => 423,
			'body'   => array(
				'success' => false,
				'data'    => $plain,
			),
		);
	}

	/**
	 * The answer to a refused REST form submission that has an adapter, or
	 * null when the route should get the ordinary 423 error.
	 *
	 * @param string           $route   The REST route.
	 * @param \WP_REST_Request $request The request.
	 * @param string           $message The visitor message, plain text.
	 */
	public function rest( string $route, \WP_REST_Request $request, string $message ): ?\WP_REST_Response {
		if ( 1 !== preg_match( '#^/contact-form-7/v1/contact-forms/([^/]+)/feedback#', $route, $matches ) ) {
			return null;
		}

		// Contact Form 7's script treats any non-2xx answer as a crash and
		// shows nothing. Its own way of saying "not sent" is the status
		// `aborted`, which it displays in the form's response box.
		$unit = $request->get_param( '_wpcf7_unit_tag' );
		$unit = is_string( $unit ) ? (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $unit ) : '';

		$response = new \WP_REST_Response(
			array(
				'contact_form_id'  => ctype_digit( $matches[1] ) ? (int) $matches[1] : 0,
				'status'           => 'aborted',
				'message'          => $message,
				'posted_data_hash' => '',
				'into'             => '' === $unit ? '' : '#' . $unit,
				'invalid_fields'   => array(),
			),
			200
		);

		$response->header( 'X-Stillgate-Paused', 'forms' );

		return $response;
	}
}
