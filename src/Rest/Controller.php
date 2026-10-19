<?php
/**
 * REST API for deployment pipelines: namespace stillgate/v1.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Rest;

use Digibold\DeploymentGuard\DateInput;
use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a CI/CD pipeline or deploy tool start, extend and end a freeze over
 * HTTPS, the same operations as `wp stillgate`, using WordPress's own
 * authentication (an Application Password, or a logged-in session with a
 * REST nonce). No token system of its own: whoever may change the freeze
 * on the settings screen may change it here, and nobody else.
 *
 * Reading the status needs `manage_options`. Changing anything also needs
 * the `deployment_guard_bypass` capability, exactly like the settings
 * screen, so an account whose own edits are frozen cannot lift the freeze
 * through the API either. All routes sit outside the freeze's write
 * protection: they only touch Stillgate's own state, which the freeze never
 * protects.
 *
 * Routes (all under /wp-json/stillgate/v1):
 *
 *     GET    /status           Current state.
 *     POST   /freeze           Start a freeze: `for` (optional duration such
 *                              as 30m, 2h), `message` (optional).
 *     DELETE /freeze           End the freeze.
 *     POST   /freeze/extend    Extend the running timed or scheduled freeze:
 *                              `by` (required duration).
 *     POST   /schedule         Schedule a window: `start`, `end` (required),
 *                              `message` (optional).
 *     DELETE /schedule         Clear the schedule.
 */
final class Controller {

	private const REST_NAMESPACE = 'stillgate/v1';

	private FreezeService $service;

	public function __construct( FreezeService $service ) {
		$this->service = $service;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'can_view' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/freeze',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start_freeze' ),
					'permission_callback' => array( $this, 'can_change' ),
					'args'                => array(
						'for'     => array(
							'description' => 'How long the freeze lasts before it ends by itself, for example 30m, 2h, 1d or 1h30m (a bare number means minutes). Omit for a freeze that lasts until it is ended.',
							'type'        => 'string',
						),
						'message' => array(
							'description' => 'Message shown to restricted users.',
							'type'        => 'string',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'end_freeze' ),
					'permission_callback' => array( $this, 'can_change' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/freeze/extend',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'extend_freeze' ),
					'permission_callback' => array( $this, 'can_change' ),
					'args'                => array(
						'by' => array(
							'description' => 'How much longer, for example 15m or 1h.',
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/schedule',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_schedule' ),
					'permission_callback' => array( $this, 'can_change' ),
					'args'                => array(
						'start'   => array(
							'description' => 'Window start: "now", a site-time value such as 2026-09-20 22:00, or an ISO 8601 time with Z or an offset.',
							'type'        => 'string',
							'required'    => true,
						),
						'end'     => array(
							'description' => 'Window end, same formats as start.',
							'type'        => 'string',
							'required'    => true,
						),
						'message' => array(
							'description' => 'Message shown to restricted users.',
							'type'        => 'string',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_schedule' ),
					'permission_callback' => array( $this, 'can_change' ),
				),
			)
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public function can_view() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return $this->forbidden();
	}

	/**
	 * @return true|\WP_Error
	 */
	public function can_change() {
		if ( current_user_can( 'manage_options' ) && current_user_can( Installer::CAPABILITY ) ) {
			return true;
		}

		return $this->forbidden();
	}

	private function forbidden(): \WP_Error {
		return new \WP_Error(
			'stillgate_forbidden',
			__( 'Sorry, you are not allowed to do that.', 'stillgate' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public function get_status(): \WP_REST_Response {
		return new \WP_REST_Response( $this->status_payload() );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start_freeze( \WP_REST_Request $request ) {
		$for     = $request->get_param( 'for' );
		$seconds = null;

		if ( is_string( $for ) && '' !== trim( $for ) ) {
			try {
				$seconds = DateInput::parse_duration( $for );
			} catch ( \DomainException $exception ) {
				return $this->error( $exception->getMessage(), 400 );
			}
		}

		// The message first, so one that is too long refuses the request
		// before anything is locked.
		$saved = $this->maybe_set_message( $request );

		if ( is_wp_error( $saved ) ) {
			return $this->from_wp_error( $saved );
		}

		$result = null === $seconds ? $this->service->enable( 'rest' ) : $this->service->freeze_for( $seconds, 'rest' );

		if ( is_wp_error( $result ) ) {
			return $this->from_wp_error( $result );
		}

		return $this->respond( $result );
	}

	public function end_freeze(): \WP_REST_Response {
		return $this->respond( $this->service->disable( 'rest' ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function extend_freeze( \WP_REST_Request $request ) {
		$by = $request->get_param( 'by' );

		try {
			$seconds = DateInput::parse_duration( is_string( $by ) ? $by : '' );
		} catch ( \DomainException $exception ) {
			return $this->error( $exception->getMessage(), 400 );
		}

		$result = $this->service->extend( $seconds, 'rest' );

		if ( is_wp_error( $result ) ) {
			return $this->from_wp_error( $result );
		}

		return $this->respond( $result );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_schedule( \WP_REST_Request $request ) {
		$timezone = wp_timezone();
		$now      = time();
		$start    = $request->get_param( 'start' );
		$end      = $request->get_param( 'end' );

		try {
			$start_at = DateInput::parse_cli( is_string( $start ) ? $start : '', $timezone, $now );
		} catch ( \DomainException $exception ) {
			return $this->error( 'invalid_start', 400, FreezeService::error_text( $exception->getMessage() ) );
		}

		try {
			$end_at = DateInput::parse_cli( is_string( $end ) ? $end : '', $timezone, $now );
		} catch ( \DomainException $exception ) {
			return $this->error( 'invalid_end', 400, FreezeService::error_text( $exception->getMessage() ) );
		}

		$saved = $this->maybe_set_message( $request );

		if ( is_wp_error( $saved ) ) {
			return $this->from_wp_error( $saved );
		}

		$result = $this->service->schedule( $start_at, $end_at, 'rest' );

		if ( is_wp_error( $result ) ) {
			return $this->from_wp_error( $result );
		}

		return $this->respond( $result );
	}

	public function clear_schedule(): \WP_REST_Response {
		return $this->respond( $this->service->clear_schedule( 'rest' ) );
	}

	/**
	 * @return string|\WP_Error|null Null when the request carries no message.
	 */
	private function maybe_set_message( \WP_REST_Request $request ) {
		$message = $request->get_param( 'message' );

		if ( ! is_string( $message ) ) {
			return null;
		}

		return $this->service->set_message( $message, 'rest' );
	}

	private function respond( string $result ): \WP_REST_Response {
		$payload           = $this->status_payload();
		$payload['result'] = $result;

		return new \WP_REST_Response( $payload );
	}

	private function error( string $code, int $status, ?string $message = null ): \WP_Error {
		return new \WP_Error( $code, $message ?? FreezeService::error_text( $code ), array( 'status' => $status ) );
	}

	/**
	 * A service error carried over as a REST error: 409 when the request
	 * conflicts with the current state, otherwise 400.
	 */
	private function from_wp_error( \WP_Error $error ): \WP_Error {
		$code   = (string) $error->get_error_code();
		$status = 'not_extendable' === $code ? 409 : 400;

		return new \WP_Error( $code, $error->get_error_message(), array( 'status' => $status ) );
	}

	/**
	 * The current state, with typed values and both site-time and UTC
	 * timestamps, shaped for scripts.
	 *
	 * @return array<string, mixed>
	 */
	private function status_payload(): array {
		$config   = $this->service->config();
		$now      = time();
		$timezone = wp_timezone();
		$forced   = $this->service->forced();
		$ends_at  = $this->service->ends_at();

		return array(
			'active'          => $this->service->is_active(),
			'forced'          => $forced,
			'mode'            => $config->mode(),
			'manual_enabled'  => $config->enabled,
			'schedule_status' => $config->schedule_status( $now ),
			'schedule_start'  => null !== $config->schedule_start ? gmdate( 'c', $config->schedule_start ) : null,
			'schedule_end'    => null !== $config->schedule_end ? gmdate( 'c', $config->schedule_end ) : null,
			'ends_at'         => null !== $ends_at ? gmdate( 'c', $ends_at ) : null,
			'seconds_left'    => null !== $ends_at ? max( 0, $ends_at - $now ) : null,
			'timezone'        => wp_timezone_string(),
			'schedule_local'  => array(
				'start' => null !== $config->schedule_start ? DateInput::to_input_value( $config->schedule_start, $timezone ) : null,
				'end'   => null !== $config->schedule_end ? DateInput::to_input_value( $config->schedule_end, $timezone ) : null,
			),
			'message'         => $config->message,
			'updated_at'      => $config->updated_at > 0 ? gmdate( 'c', $config->updated_at ) : null,
			'updated_by'      => $config->updated_by,
		);
	}
}
