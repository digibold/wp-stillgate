<?php
/**
 * A Site Health check for the freeze.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Admin;

use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Adds one test to Tools > Site Health that catches the two ways a freeze
 * goes wrong in practice: it was left on, or nobody could end it. It only
 * shows up where somebody goes to look, so it never nags.
 */
final class SiteHealth {

	/**
	 * A manual freeze with no end that has run this long is probably
	 * forgotten.
	 */
	private const FORGOTTEN_AFTER = 6 * HOUR_IN_SECONDS;

	private FreezeService $service;

	public function __construct( FreezeService $service ) {
		$this->service = $service;
	}

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * @param mixed $tests Site Health tests.
	 * @return mixed
	 */
	public function add_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['direct']['stillgate'] = array(
			'label' => __( 'Stillgate editorial freeze', 'stillgate' ),
			'test'  => array( $this, 'run_test' ),
		);

		return $tests;
	}

	/**
	 * @return array<string, mixed> A Site Health result.
	 */
	public function run_test(): array {
		$result = array(
			'label'       => __( 'No editorial freeze is blocking editors', 'stillgate' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Stillgate', 'stillgate' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Editing is not restricted, and a freeze can be started from Tools > Stillgate, WP-CLI or the REST API.', 'stillgate' ) . '</p>',
			'actions'     => '',
			'test'        => 'stillgate',
		);

		$settings = '<a href="' . esc_url( admin_url( 'tools.php?page=stillgate' ) ) . '">' . esc_html__( 'Open Stillgate', 'stillgate' ) . '</a>';
		$now      = time();
		$config   = $this->service->config();
		$forced   = $this->service->forced();

		if ( true === $forced ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'A freeze is forced on in wp-config.php', 'stillgate' );
			$result['description'] = '<p>' . esc_html__( 'DEPLOYMENT_GUARD_FORCE is set to true, so editing stays locked whatever the settings say. Remove it from wp-config.php when the deployment is over.', 'stillgate' ) . '</p>';
			$result['actions']     = $settings;

			return $result;
		}

		if ( $this->service->is_active() ) {
			$ends_at = $this->service->ends_at();
			$since   = $config->updated_at;

			if ( null === $ends_at && $since > 0 && ( $now - $since ) > self::FORGOTTEN_AFTER ) {
				$result['status']      = 'recommended';
				$result['label']       = __( 'A manual freeze has been on for a long time', 'stillgate' );
				$result['description'] = '<p>' . esc_html(
					sprintf(
						/* translators: %s: how long, such as "9 hours". */
						__( 'Editors have been locked out for about %s and the freeze has no end time. If the deployment is finished, end it. Next time, start it with a timer so it ends by itself.', 'stillgate' ),
						human_time_diff( $since, $now )
					)
				) . '</p>';
				$result['actions'] = $settings;

				return $result;
			}

			$result['label']       = __( 'An editorial freeze is active', 'stillgate' );
			$result['description'] = '<p>' . esc_html__( 'Editing is locked for users without the bypass capability. That is expected during a deployment.', 'stillgate' ) . '</p>';
			$result['actions']     = $settings;

			return $result;
		}

		if ( ! $this->anyone_can_bypass() ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Nobody can end a freeze from wp-admin', 'stillgate' );
			$result['description'] = '<p>' . esc_html__( 'No role has the "deployment_guard_bypass" capability, so a freeze could only be ended with WP-CLI or by editing wp-config.php. Grant the capability to your administrator role.', 'stillgate' ) . '</p>';
			$result['actions']     = $settings;
		}

		return $result;
	}

	private function anyone_can_bypass(): bool {
		foreach ( wp_roles()->roles as $role_data ) {
			if ( ! empty( $role_data['capabilities'][ Installer::CAPABILITY ] ) ) {
				return true;
			}
		}

		return false;
	}
}
