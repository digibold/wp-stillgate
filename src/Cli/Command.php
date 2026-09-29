<?php
/**
 * WP-CLI command: wp deployment-guard.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Cli;

use Digibold\DeploymentGuard\DateInput;
use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Guard;
use Digibold\DeploymentGuard\Installer;
use Digibold\DeploymentGuard\Suspension;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the Deployment Guard editorial freeze from the command line.
 *
 * Every subcommand's body runs inside Suspension::run(), the narrowly
 * scoped mechanism described on the Suspension class: it lets these
 * specific commands read and change the freeze state even while a freeze
 * is active, without making WP-CLI in general a bypass. Any other WP-CLI
 * command (`wp post update`, `wp term create`, and so on) remains subject
 * to the same enforcement as everything else.
 *
 * Output and messages here are deliberately left untranslated: WP-CLI
 * output is meant to be stable and parseable by deployment scripts.
 */
final class Command {

	private FreezeService $service;

	private Guard $guard;

	private Suspension $suspension;

	public function __construct( FreezeService $service, Guard $guard, Suspension $suspension ) {
		$this->service    = $service;
		$this->guard      = $guard;
		$this->suspension = $suspension;
	}

	/**
	 * Shows the current freeze status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp deployment-guard status
	 *     wp deployment-guard status --format=json
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function status( $args, $assoc_args ): void {
		$this->suspension->run(
			function () use ( $assoc_args ): void {
				$this->do_status( $assoc_args );
			}
		);
	}

	/**
	 * Turns the freeze on immediately.
	 *
	 * ## OPTIONS
	 *
	 * [--message=<message>]
	 * : Sets the message shown to restricted users.
	 *
	 * ## EXAMPLES
	 *
	 *     wp deployment-guard enable
	 *     wp deployment-guard enable --message="Deploying now, back in 10 minutes."
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function enable( $args, $assoc_args ): void {
		$this->suspension->run(
			function () use ( $assoc_args ): void {
				$result = $this->service->enable( 'cli' );

				\WP_CLI::success( 'unchanged' === $result ? 'The freeze was already on.' : 'Deployment Guard is now active.' );

				$this->maybe_set_message( $assoc_args );
				$this->maybe_warn_forced();
				$this->maybe_warn_object_cache();
			}
		);
	}

	/**
	 * Turns the freeze off.
	 *
	 * A currently running scheduled window is cleared too; an upcoming
	 * one is left in place and a warning is shown, so a deploy script
	 * cannot accidentally believe editing is fully unrestricted going
	 * forward.
	 *
	 * ## EXAMPLES
	 *
	 *     wp deployment-guard disable
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function disable( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- disable takes no options; both parameters are required by WP-CLI's command method signature.
		$this->suspension->run(
			function (): void {
				$result = $this->service->disable( 'cli' );

				\WP_CLI::success( 'unchanged' === $result ? 'The freeze was already off.' : 'Deployment Guard is now inactive.' );

				$config = $this->service->config();

				if ( null !== $config->schedule_start ) {
					\WP_CLI::warning(
						sprintf(
							'An upcoming scheduled freeze remains configured (starts %s). Run "wp deployment-guard schedule --clear" to remove it.',
							gmdate( 'c', $config->schedule_start )
						)
					);
				}

				$this->maybe_warn_forced();
				$this->maybe_warn_object_cache();
			}
		);
	}

	/**
	 * Configures or clears the scheduled freeze window.
	 *
	 * ## OPTIONS
	 *
	 * [--start=<datetime>]
	 * : Start of the window: "now", a local datetime ("2026-09-20 22:00"),
	 * or a datetime with an explicit UTC/offset designator
	 * ("2026-09-20T22:00:00Z"). Required unless --clear is used.
	 *
	 * [--end=<datetime>]
	 * : End of the window, in the same accepted forms as --start.
	 * Required unless --clear is used.
	 *
	 * [--clear]
	 * : Remove the scheduled window instead of setting one. Cannot be
	 * combined with --start or --end.
	 *
	 * [--message=<message>]
	 * : Sets the message shown to restricted users.
	 *
	 * ## EXAMPLES
	 *
	 *     wp deployment-guard schedule --start="2026-09-20 22:00" --end="2026-09-21 02:00"
	 *     wp deployment-guard schedule --clear
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function schedule( $args, $assoc_args ): void {
		$this->suspension->run(
			function () use ( $assoc_args ): void {
				$clear     = isset( $assoc_args['clear'] );
				$has_start = isset( $assoc_args['start'] );
				$has_end   = isset( $assoc_args['end'] );

				if ( $clear ) {
					if ( $has_start || $has_end ) {
						$this->fail( 'Use either --clear or --start/--end, not both.' );

						return;
					}

					$this->do_clear_schedule();
				} else {
					if ( ! $has_start || ! $has_end ) {
						$this->fail( 'Both --start and --end are required (or use --clear).' );

						return;
					}

					if ( ! $this->do_set_schedule( (string) $assoc_args['start'], (string) $assoc_args['end'] ) ) {
						return;
					}
				}

				$this->maybe_set_message( $assoc_args );
				$this->maybe_warn_forced();
				$this->maybe_warn_object_cache();
			}
		);
	}

	/**
	 * @param array<string, mixed> $assoc_args
	 */
	private function do_status( array $assoc_args ): void {
		$config   = $this->service->config();
		$now      = time();
		$timezone = wp_timezone();
		$forced   = $this->service->forced();

		$data = array(
			'active'                  => $this->service->is_active() ? 'true' : 'false',
			'forced'                  => null === $forced ? '' : ( $forced ? 'true' : 'false' ),
			'mode'                    => $config->mode(),
			'manual_enabled'          => $config->enabled ? 'true' : 'false',
			'schedule_status'         => $config->schedule_status( $now ),
			'schedule_start'          => null !== $config->schedule_start ? DateInput::to_input_value( $config->schedule_start, $timezone ) : '',
			'schedule_start_utc'      => null !== $config->schedule_start ? gmdate( 'c', $config->schedule_start ) : '',
			'schedule_end'            => null !== $config->schedule_end ? DateInput::to_input_value( $config->schedule_end, $timezone ) : '',
			'schedule_end_utc'        => null !== $config->schedule_end ? gmdate( 'c', $config->schedule_end ) : '',
			'timezone'                => wp_timezone_string(),
			'message'                 => $config->message,
			'updated_at_utc'          => $config->updated_at > 0 ? gmdate( 'c', $config->updated_at ) : '',
			'updated_by'              => (string) $config->updated_by,
			'site_url'                => site_url(),
			'protected_post_types'    => implode( ',', $this->guard->protected_types()['post_types'] ),
			'protected_taxonomies'    => implode( ',', $this->guard->protected_types()['taxonomies'] ),
			'roles_with_bypass'       => implode( ',', $this->roles_with_bypass() ),
			'persistent_object_cache' => wp_using_ext_object_cache() ? 'true' : 'false',
		);

		$format = isset( $assoc_args['format'] ) && is_string( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		if ( 'table' === $format ) {
			$items = array();

			foreach ( $data as $field => $value ) {
				$items[] = array(
					'Field' => $field,
					'Value' => $value,
				);
			}

			\WP_CLI\Utils\format_items( 'table', $items, array( 'Field', 'Value' ) );
		} else {
			\WP_CLI::print_value( $data, array( 'format' => $format ) );
		}
	}

	private function do_clear_schedule(): void {
		$result = $this->service->clear_schedule( 'cli' );

		\WP_CLI::success( 'unchanged' === $result ? 'No schedule was configured.' : 'The scheduled freeze has been cleared.' );
	}

	private function do_set_schedule( string $start_raw, string $end_raw ): bool {
		$timezone = wp_timezone();
		$now      = time();

		try {
			$start = DateInput::parse_cli( $start_raw, $timezone, $now );
		} catch ( \DomainException $exception ) {
			$this->fail( 'Invalid --start: ' . FreezeService::error_text( $exception->getMessage() ) );

			return false;
		}

		try {
			$end = DateInput::parse_cli( $end_raw, $timezone, $now );
		} catch ( \DomainException $exception ) {
			$this->fail( 'Invalid --end: ' . FreezeService::error_text( $exception->getMessage() ) );

			return false;
		}

		$result = $this->service->schedule( $start, $end, 'cli' );

		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message() );

			return false;
		}

		\WP_CLI::success(
			sprintf(
				'Scheduled: %s to %s (site time), %s to %s (UTC).',
				DateInput::to_input_value( $start, $timezone ),
				DateInput::to_input_value( $end, $timezone ),
				gmdate( 'c', $start ),
				gmdate( 'c', $end )
			)
		);

		return true;
	}

	/**
	 * @param array<string, mixed> $assoc_args
	 */
	private function maybe_set_message( array $assoc_args ): void {
		if ( ! isset( $assoc_args['message'] ) ) {
			return;
		}

		$result = $this->service->set_message( (string) $assoc_args['message'], 'cli' );

		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message() );
		}
	}

	/**
	 * @return string[]
	 */
	private function roles_with_bypass(): array {
		$roles = array();

		foreach ( wp_roles()->roles as $role_name => $role_data ) {
			if ( ! empty( $role_data['capabilities'][ Installer::CAPABILITY ] ) ) {
				$roles[] = $role_name;
			}
		}

		return $roles;
	}

	/**
	 * Report a usage or validation error and end the command.
	 * WP_CLI::error() ends the process with exit(), which skips
	 * Suspension::run()'s `finally`, so it is always called outside the
	 * suspension: shutdown callbacks that run afterwards never find one
	 * still open.
	 */
	private function fail( string $message ): void {
		$this->suspension->outside(
			static function () use ( $message ): void {
				\WP_CLI::error( $message );
			}
		);
	}

	private function maybe_warn_forced(): void {
		if ( null !== $this->service->forced() ) {
			\WP_CLI::warning( 'DEPLOYMENT_GUARD_FORCE overrides this change.' );
		}
	}

	private function maybe_warn_object_cache(): void {
		if ( wp_using_ext_object_cache() ) {
			\WP_CLI::warning( 'A persistent object cache is in use. If web servers do not share it with WP-CLI, run "wp cache flush" so they see this change immediately.' );
		}
	}
}
