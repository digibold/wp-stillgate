<?php
/**
 * WP-CLI command: wp stillgate.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Cli;

use Digibold\DeploymentGuard\DateInput;
use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Guard;
use Digibold\DeploymentGuard\Installer;
use Digibold\DeploymentGuard\Receipt;
use Digibold\DeploymentGuard\Scope;
use Digibold\DeploymentGuard\Suspension;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the Stillgate editorial freeze from the command line.
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

	private ?Receipt $receipt;

	public function __construct( FreezeService $service, Guard $guard, Suspension $suspension, ?Receipt $receipt = null ) {
		$this->service    = $service;
		$this->guard      = $guard;
		$this->suspension = $suspension;
		$this->receipt    = $receipt;
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
	 *     wp stillgate status
	 *     wp stillgate status --format=json
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
	 * Without --for the freeze stays on until it is turned off. With --for
	 * it ends by itself after the given time, so a deployment that fails
	 * halfway can never leave editing locked indefinitely.
	 *
	 * ## OPTIONS
	 *
	 * [--for=<duration>]
	 * : End the freeze automatically after this long, for example 30m, 2h,
	 * 1d or 1h30m (a bare number means minutes). At least one minute and
	 * at most seven days. Replaces any manual freeze or scheduled window.
	 *
	 * [--message=<message>]
	 * : Sets the message shown to restricted users.
	 *
	 * ## EXAMPLES
	 *
	 *     wp stillgate enable
	 *     wp stillgate enable --for=30m
	 *     wp stillgate enable --for=2h --message="Deploying now, back in two hours."
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function enable( $args, $assoc_args ): void {
		$this->suspension->run(
			function () use ( $assoc_args ): void {
				if ( isset( $assoc_args['for'] ) ) {
					$this->do_timed_freeze( (string) $assoc_args['for'] );
				} else {
					$result = $this->service->enable( 'cli' );

					\WP_CLI::success( 'unchanged' === $result ? 'The freeze was already on.' : 'Stillgate is now active.' );
				}

				$this->maybe_set_message( $assoc_args );
				$this->maybe_warn_forced();
				$this->maybe_warn_object_cache();
			}
		);
	}

	/**
	 * Pushes the end of the running timed or scheduled freeze back.
	 *
	 * For a deployment that is taking longer than planned. A manual freeze
	 * has no end time to extend, and the total window cannot exceed seven
	 * days.
	 *
	 * ## OPTIONS
	 *
	 * <duration>
	 * : How much longer, for example 15m, 1h or 1h30m (a bare number means minutes).
	 *
	 * ## EXAMPLES
	 *
	 *     wp stillgate extend 15m
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function extend( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- extend takes no options; both parameters are required by WP-CLI's command method signature.
		$this->suspension->run(
			function () use ( $args ): void {
				try {
					$seconds = DateInput::parse_duration( isset( $args[0] ) ? (string) $args[0] : '' );
				} catch ( \DomainException $exception ) {
					$this->fail( 'Invalid duration: ' . FreezeService::error_text( $exception->getMessage() ) );

					return;
				}

				$result = $this->service->extend( $seconds, 'cli' );

				if ( is_wp_error( $result ) ) {
					$this->fail( $result->get_error_message() );

					return;
				}

				$ends_at = $this->service->ends_at();

				\WP_CLI::success(
					null !== $ends_at
						? sprintf( 'The freeze now ends at %s (UTC).', gmdate( 'c', $ends_at ) )
						: 'The freeze was extended.'
				);

				$this->maybe_warn_forced();
				$this->maybe_warn_object_cache();
			}
		);
	}
	/**
	 * Chooses what a freeze covers beyond editorial content.
	 *
	 * A freeze always locks editing. It can also pause comments and form
	 * submissions from visitors, because anything saved to the database
	 * while a deployment or migration runs can be lost when the database is
	 * replaced. Both are off by default. With no options, shows the
	 * current choice.
	 *
	 * ## OPTIONS
	 *
	 * [--comments=<on|off>]
	 * : Pause new visitor comments, reviews, pingbacks and trackbacks during a freeze.
	 *
	 * [--forms=<on|off>]
	 * : Pause visitor submissions of contact and other forms (tested with
	 * Contact Form 7, WPForms, Ninja Forms, Fluent Forms, Forminator,
	 * Formidable and Everest Forms; others can be added with a filter)
	 * during a freeze.
	 *
	 * ## EXAMPLES
	 *
	 *     wp stillgate scope
	 *     wp stillgate scope --comments=on --forms=on
	 *     wp stillgate scope --forms=off
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function scope( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the positional arguments are unused; both parameters are required by WP-CLI's command method signature.
		$this->suspension->run(
			function () use ( $assoc_args ): void {
				$scope = $this->service->scope();

				foreach ( array( Scope::COMMENTS, Scope::FORMS ) as $kind ) {
					if ( ! isset( $assoc_args[ $kind ] ) ) {
						continue;
					}

					$value = strtolower( trim( (string) $assoc_args[ $kind ] ) );

					if ( ! in_array( $value, array( 'on', 'off' ), true ) ) {
						$this->fail( sprintf( 'Invalid --%s: use "on" or "off".', $kind ) );

						return;
					}

					$scope = new Scope(
						Scope::COMMENTS === $kind ? 'on' === $value : $scope->comments,
						Scope::FORMS === $kind ? 'on' === $value : $scope->forms
					);
				}

				$result = $this->service->set_scope( $scope, 'cli' );

				if ( 'scope_saved' === $result ) {
					\WP_CLI::success( 'The scope was saved.' );
				}

				\WP_CLI::log( sprintf( 'comments: %s', $this->service->scope()->comments ? 'on' : 'off' ) );
				\WP_CLI::log( sprintf( 'forms: %s', $this->service->scope()->forms ? 'on' : 'off' ) );
			}
		);
	}

	/**
	 * Shows what happened to the content during the latest freeze.
	 *
	 * The receipt lists the posts saved, created or removed while the freeze
	 * ran, term changes, comments added, and how many visitor comments and
	 * form submissions were paused. A clean receipt means the database a
	 * deploy dumped when the freeze began is still complete.
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
	 * [--check]
	 * : Exit with status 1 when the content changed during the freeze, so a
	 * deploy script can stop before it relies on a dump that is out of date.
	 *
	 * ## EXAMPLES
	 *
	 *     wp stillgate receipt
	 *     wp stillgate receipt --format=json
	 *     wp stillgate receipt --check || echo "content changed during the freeze"
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function receipt( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the positional arguments are unused; both parameters are required by WP-CLI's command method signature.
		$this->suspension->run(
			function () use ( $assoc_args ): void {
				$report = $this->current_receipt();

				if ( null === $report ) {
					return;
				}

				$format = isset( $assoc_args['format'] ) && is_string( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

				if ( 'table' === $format ) {
					$items = array();

					foreach ( $this->flatten_receipt( $report ) as $field => $value ) {
						$items[] = array(
							'Field' => $field,
							'Value' => $value,
						);
					}

					\WP_CLI\Utils\format_items( 'table', $items, array( 'Field', 'Value' ) );
				} else {
					\WP_CLI::print_value( $report, array( 'format' => $format ) );
				}

				if ( ! empty( $assoc_args['check'] ) && false === $report['clean'] ) {
					$this->suspension->outside(
						static function (): void {
							\WP_CLI::halt( 1 );
						}
					);
				}
			}
		);
	}

	/**
	 * Lists the posts that changed during the latest freeze.
	 *
	 * Prints the IDs on one line, comma separated, ready to hand to
	 * WordPress's own export so they can be carried to another environment:
	 * wp export --post__in="$(wp stillgate drift)". At most 200 IDs are
	 * listed; the receipt shows the exact count.
	 *
	 * ## EXAMPLES
	 *
	 *     wp stillgate drift
	 *     wp export --post__in="$(wp stillgate drift)" --dir=/tmp
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function drift( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- takes no options; both parameters are required by WP-CLI's command method signature.
		$this->suspension->run(
			function (): void {
				$report = $this->current_receipt();

				if ( null === $report ) {
					return;
				}

				\WP_CLI::line( implode( ',', $report['content']['changed_ids'] ?? array() ) );
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
	 *     wp stillgate disable
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc_args
	 */
	public function disable( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- disable takes no options; both parameters are required by WP-CLI's command method signature.
		$this->suspension->run(
			function (): void {
				$result = $this->service->disable( 'cli' );

				\WP_CLI::success( 'unchanged' === $result ? 'The freeze was already off.' : 'Stillgate is now inactive.' );

				$config = $this->service->config();

				if ( null !== $config->schedule_start ) {
					\WP_CLI::warning(
						sprintf(
							'An upcoming scheduled freeze remains configured (starts %s). Run "wp stillgate schedule --clear" to remove it.',
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
	 *     wp stillgate schedule --start="2026-09-20 22:00" --end="2026-09-21 02:00"
	 *     wp stillgate schedule --clear
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
			'ends_at_utc'             => null !== $this->service->ends_at() ? gmdate( 'c', $this->service->ends_at() ) : '',
			'scope_comments'          => $this->service->scope()->comments ? 'true' : 'false',
			'scope_forms'             => $this->service->scope()->forms ? 'true' : 'false',
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

	private function do_timed_freeze( string $duration_raw ): void {
		try {
			$seconds = DateInput::parse_duration( $duration_raw );
		} catch ( \DomainException $exception ) {
			$this->fail( 'Invalid --for: ' . FreezeService::error_text( $exception->getMessage() ) );

			return;
		}

		$result = $this->service->freeze_for( $seconds, 'cli' );

		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message() );

			return;
		}

		$ends_at = $this->service->ends_at();

		\WP_CLI::success(
			sprintf(
				'Stillgate is now active and ends by itself at %s (UTC).',
				null !== $ends_at ? gmdate( 'c', $ends_at ) : gmdate( 'c', time() + $seconds )
			)
		);
	}
	/**
	 * The receipt report, or null (after reporting an error) when receipts
	 * are unavailable or no freeze has run yet.
	 *
	 * @return array<string, mixed>|null
	 */
	private function current_receipt(): ?array {
		if ( null === $this->receipt ) {
			$this->fail( 'Receipts are not available.' );

			return null;
		}

		$this->receipt->sync( 'cli' );
		$report = $this->receipt->report();

		if ( 'none' === $report['state'] ) {
			$this->fail( 'No freeze has run yet, so there is no receipt.' );

			return null;
		}

		return $report;
	}

	/**
	 * @param array<string, mixed> $report
	 * @return array<string, string>
	 */
	private function flatten_receipt( array $report ): array {
		$content = $report['content'] ?? array();
		$paused  = $report['paused'] ?? array();

		return array(
			'state'            => (string) $report['state'],
			'opened_at'        => (string) $report['opened_at'],
			'closed_at'        => (string) ( $report['closed_at'] ?? '' ),
			'source'           => (string) $report['source'],
			'clean'            => $report['clean'] ? 'true' : 'false',
			'changed_posts'    => (string) ( $content['changed_posts'] ?? 0 ),
			'changed_ids'      => implode( ',', $content['changed_ids'] ?? array() ),
			'deleted_estimate' => (string) ( $content['deleted_estimate'] ?? 0 ),
			'terms_changed'    => ! empty( $content['terms_changed'] ) ? 'true' : 'false',
			'comments_added'   => (string) ( $content['comments_added'] ?? 0 ),
			'paused_comments'  => (string) ( $paused[ Scope::COMMENTS ] ?? 0 ),
			'paused_forms'     => (string) ( $paused[ Scope::FORMS ] ?? 0 ),
		);
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
