<?php
/**
 * One-click freeze actions from the admin bar.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Admin;

use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Input;
use Digibold\DeploymentGuard\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * "End freeze now" and "Extend by 15 minutes" for the admin bar, where a
 * form cannot go. Each is a link carrying its own single-use-style nonce,
 * checked together with the same two capabilities the settings screen
 * needs, then answered with a redirect to the settings screen, which shows
 * what happened.
 *
 * Only these two actions exist here: both shorten or lengthen a freeze
 * that is already running. Starting a freeze needs the confirmation box on
 * the settings screen and is not offered as a link.
 */
final class QuickActions {

	private const ACTION     = 'deployment_guard_quick';
	private const OPERATIONS = array( 'end', 'extend' );

	/**
	 * How far the "extend" link pushes the end back.
	 */
	public const EXTEND_MINUTES = 15;

	private FreezeService $service;

	public function __construct( FreezeService $service ) {
		$this->service = $service;
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * The link for an operation, with its nonce.
	 *
	 * @param string $operation One of 'end' or 'extend'.
	 */
	public static function url( string $operation ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'op'     => $operation,
				),
				admin_url( 'admin-post.php' )
			),
			self::nonce_action( $operation )
		);
	}

	private static function nonce_action( string $operation ): string {
		return self::ACTION . '_' . $operation;
	}

	public function handle(): void {
		wp_safe_redirect( $this->resolve_redirect_url() );
		exit;
	}

	/**
	 * Everything handle() does except the redirect itself, so the whole
	 * decision can be tested without ending the process.
	 */
	public function resolve_redirect_url(): string {
		$operation = Input::key( 'op', 'GET' );

		if ( ! in_array( $operation, self::OPERATIONS, true ) ) {
			wp_die( esc_html__( 'Unknown Stillgate action.', 'stillgate' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( self::nonce_action( $operation ) );

		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( Installer::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to change Stillgate settings.', 'stillgate' ),
				'',
				array( 'response' => 403 )
			);
		}

		$result = 'end' === $operation
			? $this->service->disable( 'ui' )
			: $this->service->extend( self::EXTEND_MINUTES * MINUTE_IN_SECONDS, 'ui' );

		$args = is_wp_error( $result )
			? array( 'dg_error' => $result->get_error_code() )
			: array( 'dg_result' => $result );

		return add_query_arg( $args, admin_url( 'tools.php?page=stillgate' ) );
	}
}
