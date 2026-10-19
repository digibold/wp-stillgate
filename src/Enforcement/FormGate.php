<?php
/**
 * Pauses visitor form submissions while a freeze runs, when the site chose to.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Enforcement;

use Digibold\DeploymentGuard\Guard;
use Digibold\DeploymentGuard\Input;
use Digibold\DeploymentGuard\Receipt;
use Digibold\DeploymentGuard\Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Contact and lead forms that store their entries in the database lose
 * them the same way comments are lost when the database is replaced. When
 * the site has chosen to pause forms (see Scope), a submission from a
 * visitor is refused with a clear message while a freeze runs.
 *
 * WordPress has no single place every form passes through, so this
 * recognizes the submission requests of the common form plugins by what
 * those requests look like: an admin-ajax.php action, a REST route, or a
 * field in an ordinary POST. A request is only ever inspected when it
 * already looks like one of those, so an ordinary page view or an
 * unrelated POST costs nothing and never reads the freeze state.
 * Everything the recognition uses can be extended or replaced with the
 * `deployment_guard_form_requests` filter, and any other code can pause
 * its own submissions with the `deployment_guard_submissions_paused`
 * filter and Guard's answer.
 */
final class FormGate {

	/**
	 * What a form submission looks like, per request kind. Verified
	 * against the plugins named in docs/forms.md; filterable.
	 *
	 * ajax_actions: the admin-ajax.php `action` value.
	 * rest_routes:  regular expressions matched against the REST route.
	 * post_fields:  POST field => required value, or null for "any value".
	 *
	 * @var array{ajax_actions: string[], rest_routes: string[], post_fields: array<string, string|null>}
	 */
	private const DEFAULT_REQUESTS = array(
		'ajax_actions' => array(
			'wpforms_submit',
			'nf_ajax_submit',
			'fluentform_submit',
			'forminator_submit_form_custom-forms',
			'frm_entries_create',
			'elementor_pro_forms_send_form',
			'everest_forms_ajax_form_submission',
		),
		'rest_routes'  => array(
			'#^/contact-form-7/v1/contact-forms/[^/]+/feedback#',
			'#^/gf/v2/forms/\d+/submissions#',
		),
		'post_fields'  => array(
			'_wpcf7'        => null,
			'gform_submit'  => null,
			'wpforms'       => null,
			'frm_action'    => 'create',
			'everest_forms' => null,
			'action'        => 'forminator_submit_form_custom-forms',
		),
	);

	private Guard $guard;

	private Receipt $receipt;

	private FormResponses $responses;

	public function __construct( Guard $guard, Receipt $receipt, ?FormResponses $responses = null ) {
		$this->guard     = $guard;
		$this->receipt   = $receipt;
		$this->responses = $responses ?? new FormResponses();
	}

	public function register(): void {
		add_action( 'init', array( $this, 'guard_post_request' ), 1 );
		// Some form plugins answer their admin-ajax.php action before
		// admin_init fires (Formidable does, on wp_loaded), so the AJAX
		// check runs on both. Whichever sees the request first refuses it;
		// the other never runs.
		add_action( 'init', array( $this, 'guard_ajax_request' ), 1 );
		add_action( 'admin_init', array( $this, 'guard_ajax_request' ), 1 );
		add_filter( 'rest_pre_dispatch', array( $this, 'guard_rest_request' ), 1, 3 );
	}

	/**
	 * Ordinary (non-AJAX, non-REST) form posts.
	 */
	public function guard_post_request(): void {
		if ( ! Input::is_post() ) {
			return;
		}

		// A request is left to the AJAX or REST check only when that check
		// will actually recognize it. The URL alone decides nothing: the
		// sender chooses it, and a form plugin that reads its POST fields on
		// init runs before WordPress has dispatched an AJAX action or a REST
		// route, so the same body sent to /wp-json/x or to admin-ajax.php
		// with an unlisted action must still be inspected here. A front-end
		// request that merely defines DOING_AJAX (Everest Forms does, for its
		// own endpoint) is an ordinary form post too.
		if ( wp_doing_ajax() && is_admin() && in_array( Input::key( 'action' ), $this->requests()['ajax_actions'], true ) ) {
			return;
		}

		if ( $this->route_is_gated( $this->request_rest_route() ) ) {
			return;
		}

		if ( ! $this->post_fields_match() || ! $this->paused() ) {
			return;
		}

		if ( ! headers_sent() ) {
			header( 'X-Stillgate-Paused: forms' );
		}

		wp_die(
			esc_html( $this->guard->visitor_message( Scope::FORMS ) ),
			esc_html__( 'Form submissions are paused', 'stillgate' ),
			array(
				'response'  => 423,
				'back_link' => true,
			)
		);
	}

	/**
	 * Form submissions sent through admin-ajax.php.
	 */
	public function guard_ajax_request(): void {
		if ( ! wp_doing_ajax() ) {
			return;
		}

		$action = Input::key( 'action' );

		if ( ! in_array( $action, $this->requests()['ajax_actions'], true ) ) {
			return;
		}

		if ( ! $this->paused() ) {
			return;
		}

		$answer = $this->responses->ajax( $action, $this->guard->visitor_message( Scope::FORMS ) );

		if ( ! headers_sent() ) {
			header( 'X-Stillgate-Paused: forms' );
		}

		if ( isset( $answer['content_type'] ) ) {
			// A plugin whose script cannot take a JSON content type.
			if ( ! headers_sent() ) {
				status_header( $answer['status'] );
				header( 'Content-Type: ' . $answer['content_type'] );
			}

			echo wp_json_encode( $answer['body'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON built from fixed keys; the visitor message is escaped in FormResponses.
			wp_die( '', '', array( 'response' => null ) );
		}

		wp_send_json( $answer['body'], $answer['status'] );
	}

	/**
	 * Form submissions sent through a REST route.
	 *
	 * @param mixed $result  Short-circuit value; anything but null is left alone.
	 * @param mixed $server  The REST server.
	 * @param mixed $request The request about to be dispatched.
	 * @return mixed
	 */
	public function guard_rest_request( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- filter signature.
		if ( null !== $result || ! $request instanceof \WP_REST_Request || 'POST' !== strtoupper( $request->get_method() ) ) {
			return $result;
		}

		$matched = $this->route_is_gated( $request->get_route() );

		if ( ! $matched || ! $this->paused() ) {
			return $result;
		}

		$message = $this->guard->visitor_message( Scope::FORMS );
		$adapted = $this->responses->rest( $request->get_route(), $request, $message );

		if ( null !== $adapted ) {
			return $adapted;
		}

		return new \WP_Error(
			FormResponses::CODE,
			$message,
			array( 'status' => 423 )
		);
	}

	/**
	 * Whether the submission being inspected is paused, counting it when so.
	 */
	private function paused(): bool {
		if ( ! $this->guard->submissions_paused( Scope::FORMS ) ) {
			return false;
		}

		$this->receipt->count_paused( Scope::FORMS );

		return true;
	}

	private function post_fields_match(): bool {
		foreach ( $this->requests()['post_fields'] as $field => $expected ) {
			if ( ! Input::present( $field, 'POST' ) ) {
				continue;
			}

			if ( null === $expected || Input::key( $field, 'POST' ) === $expected ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The REST route a request is addressed to, read from its URL: this
	 * runs on `init`, before WordPress has decided whether the request is
	 * one. Null when the URL is not a REST URL.
	 */
	private function request_rest_route(): ?string {
		$query_route = Input::string( 'rest_route', 'GET' );

		if ( '' !== $query_route ) {
			return '/' . ltrim( $query_route, '/' );
		}

		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared against a fixed prefix, never output or stored.
		$path   = rawurldecode( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		$prefix = (string) wp_parse_url( home_url( '/' . rest_get_url_prefix() . '/' ), PHP_URL_PATH );

		if ( '' === $prefix || 0 !== strncasecmp( $path, $prefix, strlen( $prefix ) ) ) {
			return null;
		}

		return '/' . ltrim( substr( $path, strlen( $prefix ) ), '/' );
	}

	/**
	 * Whether a REST route is one the REST check refuses. WordPress matches
	 * routes without regard to case, so the patterns are matched the same
	 * way; otherwise /Contact-Form-7/... would reach the form unchecked.
	 *
	 * @param string|null $route The REST route, or null.
	 */
	private function route_is_gated( ?string $route ): bool {
		if ( null === $route ) {
			return false;
		}

		foreach ( $this->requests()['rest_routes'] as $pattern ) {
			if ( 1 === preg_match( $pattern . 'i', $route ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{ajax_actions: string[], rest_routes: string[], post_fields: array<string, string|null>}
	 */
	private function requests(): array {
		/**
		 * Filters how Stillgate recognizes a visitor form submission.
		 *
		 * Add the admin-ajax.php action, REST route pattern or POST field
		 * of a form plugin that is not covered, or remove one that is.
		 *
		 * @param array $requests {
		 *     @type string[]             $ajax_actions admin-ajax.php `action` values.
		 *     @type string[]             $rest_routes  Regular expressions matched against the REST route.
		 *     @type array<string,?string> $post_fields  POST field => required value, or null for any value.
		 * }
		 */
		$requests = apply_filters( 'deployment_guard_form_requests', self::DEFAULT_REQUESTS );

		if ( ! is_array( $requests ) ) {
			return self::DEFAULT_REQUESTS;
		}

		// A key left out falls back to the built-in list; to recognize
		// nothing of a kind, return it as an empty array.
		return array(
			'ajax_actions' => isset( $requests['ajax_actions'] ) && is_array( $requests['ajax_actions'] ) ? array_values( array_filter( $requests['ajax_actions'], 'is_string' ) ) : self::DEFAULT_REQUESTS['ajax_actions'],
			'rest_routes'  => isset( $requests['rest_routes'] ) && is_array( $requests['rest_routes'] ) ? array_values( array_filter( $requests['rest_routes'], 'is_string' ) ) : self::DEFAULT_REQUESTS['rest_routes'],
			'post_fields'  => isset( $requests['post_fields'] ) && is_array( $requests['post_fields'] ) ? $requests['post_fields'] : self::DEFAULT_REQUESTS['post_fields'],
		);
	}
}
