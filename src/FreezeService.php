<?php
/**
 * Freeze state repository and the single place that changes it.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the `deployment_guard_state` and `deployment_guard_history`
 * options and every operation that changes the freeze: `enable()`,
 * `disable()`, `schedule()`, `clear_schedule()`, `set_message()`, and
 * `reset()`. The settings screen and WP-CLI both call this one service,
 * so their behavior can never drift apart.
 */
final class FreezeService {

	public const OPTION_STATE   = 'deployment_guard_state';
	public const OPTION_HISTORY = 'deployment_guard_history';

	private \Closure $clock;

	private Suspension $suspension;

	private ?FreezeConfig $config = null;

	private ?History $history = null;

	public function __construct( \Closure $clock, Suspension $suspension ) {
		$this->clock      = $clock;
		$this->suspension = $suspension;

		// Invalidate the in-memory caches below whenever the underlying
		// options change, however that happens: through this exact
		// instance's own save()/log() calls (where it is a same-request
		// no-op, since the value just written is what gets read back),
		// or through a *different* FreezeService instance, or a direct
		// update_option() call from elsewhere. Without this, an instance
		// that outlives a single write (the plugin's own instance, which
		// lives for the whole request) would keep serving a stale value
		// forever once config()/history() had been called once.
		add_action( 'add_option_' . self::OPTION_STATE, array( $this, 'invalidate_config' ) );
		add_action( 'update_option_' . self::OPTION_STATE, array( $this, 'invalidate_config' ) );
		add_action( 'delete_option_' . self::OPTION_STATE, array( $this, 'invalidate_config' ) );
		add_action( 'add_option_' . self::OPTION_HISTORY, array( $this, 'invalidate_history' ) );
		add_action( 'update_option_' . self::OPTION_HISTORY, array( $this, 'invalidate_history' ) );
		add_action( 'delete_option_' . self::OPTION_HISTORY, array( $this, 'invalidate_history' ) );

		// Both caches belong to the site they were read from: after
		// switch_to_blog()/restore_current_blog(), read them again from
		// the site that is now current, rather than enforcing (or logging
		// to) one site with another site's state.
		add_action( 'switch_blog', array( $this, 'invalidate_config' ) );
		add_action( 'switch_blog', array( $this, 'invalidate_history' ) );
	}

	public function invalidate_config(): void {
		$this->config = null;
	}

	public function invalidate_history(): void {
		$this->history = null;
	}

	public function config(): FreezeConfig {
		if ( null === $this->config ) {
			$this->config = FreezeConfig::from_array( get_option( self::OPTION_STATE, array() ) );
		}

		return $this->config;
	}

	/**
	 * The `DEPLOYMENT_GUARD_FORCE` constant's value, or null when it is
	 * not defined or not a boolean. A non-boolean value is treated as
	 * unset rather than coerced, since a typo here should not silently
	 * change what "forced" means.
	 */
	public function forced(): ?bool {
		if ( ! defined( 'DEPLOYMENT_GUARD_FORCE' ) ) {
			return null;
		}

		$value = constant( 'DEPLOYMENT_GUARD_FORCE' );

		return is_bool( $value ) ? $value : null;
	}

	/**
	 * Whether a freeze is in effect right now: the forced constant when
	 * set, otherwise the stored configuration evaluated against the
	 * current time.
	 */
	public function is_active(): bool {
		$forced = $this->forced();

		if ( null !== $forced ) {
			return $forced;
		}

		return $this->config()->is_active( $this->now() );
	}

	/**
	 * When the current freeze is expected to end, or null when there is
	 * no predictable end.
	 */
	public function ends_at(): ?int {
		if ( null !== $this->forced() ) {
			return null;
		}

		return $this->config()->ends_at( $this->now() );
	}

	/**
	 * @return array<int, array{t:int,type:string,user:int,ctx:array}> Newest first.
	 */
	public function entries(): array {
		return $this->history()->entries();
	}

	public function record_blocked( string $vector, string $target, array $context = array() ): void {
		$user_id = get_current_user_id();

		if ( $this->history()->record_blocked( $vector, $target, $user_id, $context ) ) {
			update_option( self::OPTION_HISTORY, $this->history()->entries(), false );
		}
	}

	public function enable( string $source = 'ui' ): string {
		Installer::maybe_install();

		$old = $this->config();

		if ( $old->enabled ) {
			return 'unchanged';
		}

		$new = $old->with_enabled( true, $this->now(), get_current_user_id() );

		$this->save( $new );
		$this->log( 'enabled', array( 'source' => $source ) );
		$this->dispatch_state_changed( $new, $old, $source );

		return 'enabled';
	}

	public function disable( string $source = 'ui' ): string {
		Installer::maybe_install();

		$old = $this->config();
		$now = $this->now();

		if ( ! $old->is_active( $now ) ) {
			return 'unchanged';
		}

		$new     = $old->with_enabled( false, $now, get_current_user_id() );
		$context = array( 'source' => $source );

		// A currently running scheduled window is cleared too, so
		// disabling never leaves the freeze active a moment later; a
		// future window is left untouched and reported unchanged.
		if ( 'active' === $old->schedule_status( $now ) ) {
			$new                         = $new->without_schedule( $now, get_current_user_id() );
			$context['schedule_cleared'] = true;
		}

		$this->save( $new );
		$this->log( 'disabled', $context );
		$this->dispatch_state_changed( $new, $old, $source );

		return 'disabled';
	}

	/**
	 * @return string|\WP_Error 'scheduled' on success, or a WP_Error whose
	 *                          code is one of FreezeConfig::validate_schedule()'s codes.
	 */
	public function schedule( int $start, int $end, string $source = 'ui' ): string|\WP_Error {
		Installer::maybe_install();

		$now   = $this->now();
		$error = FreezeConfig::validate_schedule( $start, $end, $now );

		if ( null !== $error ) {
			return new \WP_Error( $error, self::error_text( $error ) );
		}

		$old = $this->config();
		$new = $old->with_schedule( $start, $end, $now, get_current_user_id() );

		$this->save( $new );
		$this->log(
			'schedule_set',
			array(
				'source' => $source,
				'start'  => $start,
				'end'    => $end,
			)
		);
		$this->dispatch_state_changed( $new, $old, $source );

		return 'scheduled';
	}

	public function clear_schedule( string $source = 'ui' ): string {
		Installer::maybe_install();

		$old = $this->config();

		if ( null === $old->schedule_start ) {
			return 'unchanged';
		}

		$new = $old->without_schedule( $this->now(), get_current_user_id() );

		$this->save( $new );
		$this->log( 'schedule_cleared', array( 'source' => $source ) );
		$this->dispatch_state_changed( $new, $old, $source );

		return 'schedule_cleared';
	}

	/**
	 * @return string|\WP_Error 'message_saved' on success, or a
	 *                          WP_Error( 'message_too_long' ).
	 */
	public function set_message( string $message, string $source = 'ui' ): string|\WP_Error {
		Installer::maybe_install();

		$normalized = str_replace( "\r\n", "\n", $message );
		$normalized = sanitize_textarea_field( $normalized );
		$length     = function_exists( 'mb_strlen' ) ? mb_strlen( $normalized ) : strlen( $normalized );

		if ( $length > FreezeConfig::MAX_MESSAGE_LENGTH ) {
			return new \WP_Error( 'message_too_long', self::error_text( 'message_too_long' ) );
		}

		$old = $this->config();
		$new = $old->with_message( $normalized, $this->now(), get_current_user_id() );

		$this->save( $new );
		$this->log(
			'message_updated',
			array(
				'source' => $source,
				'length' => $length,
			)
		);
		$this->dispatch_state_changed( $new, $old, $source );

		return 'message_saved';
	}

	/**
	 * Clear the freeze entirely, regardless of current state: used on
	 * deactivation so that reactivating the plugin never silently
	 * restores an old freeze.
	 */
	public function reset( string $source ): void {
		$old = $this->config();

		if ( ! $old->enabled && null === $old->schedule_start ) {
			return;
		}

		$now = $this->now();
		$new = $old
			->with_enabled( false, $now, get_current_user_id() )
			->without_schedule( $now, get_current_user_id() );

		$this->save( $new );
		$this->log( 'disabled', array( 'source' => $source ) );
		$this->dispatch_state_changed( $new, $old, $source );
	}

	/**
	 * Human-readable text for a FreezeConfig/DateInput error code.
	 */
	public static function error_text( string $code ): string {
		switch ( $code ) {
			case 'invalid_format':
				return __( 'That date could not be understood. Use the format YYYY-MM-DD HH:MM.', 'stillgate' );
			case 'nonexistent_local_time':
				return __( 'That local time does not exist, likely because of a daylight-saving change. Choose a different time.', 'stillgate' );
			case 'end_not_after_start':
				return __( 'The end date must be after the start date.', 'stillgate' );
			case 'end_in_past':
				return __( 'The end date must be in the future.', 'stillgate' );
			case 'window_too_long':
				return sprintf(
					/* translators: %d: maximum number of days a scheduled freeze window may span. */
					__( 'The scheduled window cannot be longer than %d days.', 'stillgate' ),
					(int) ( FreezeConfig::MAX_WINDOW_SECONDS / DAY_IN_SECONDS )
				);
			case 'start_too_far':
				return sprintf(
					/* translators: %d: maximum number of days a scheduled start may lie in the future. */
					__( 'The start date cannot be more than %d days from now.', 'stillgate' ),
					(int) ( FreezeConfig::MAX_START_LEAD_SECONDS / DAY_IN_SECONDS )
				);
			case 'message_too_long':
				return sprintf(
					/* translators: %d: maximum message length in characters. */
					__( 'The message cannot be longer than %d characters.', 'stillgate' ),
					FreezeConfig::MAX_MESSAGE_LENGTH
				);
			case 'confirmation_required':
				return __( 'Please check the confirmation box to continue.', 'stillgate' );
			default:
				return __( 'The request could not be completed.', 'stillgate' );
		}
	}

	private function now(): int {
		return ( $this->clock )();
	}

	private function save( FreezeConfig $config ): void {
		$this->config = $config;
		update_option( self::OPTION_STATE, $config->to_array(), false );
	}

	private function log( string $type, array $context ): void {
		$this->history()->record( $type, get_current_user_id(), $context );
		update_option( self::OPTION_HISTORY, $this->history()->entries(), false );
	}

	private function dispatch_state_changed( FreezeConfig $new_config, FreezeConfig $old_config, string $source ): void {
		$this->suspension->outside(
			static function () use ( $new_config, $old_config, $source ): void {
				/**
				 * Fires after the freeze state changes, for any reason.
				 *
				 * @param array  $new    The new state (FreezeConfig::to_array()).
				 * @param array  $old    The previous state.
				 * @param string $source One of: ui, cli, deactivation.
				 */
				do_action( 'deployment_guard_state_changed', $new_config->to_array(), $old_config->to_array(), $source );
			}
		);
	}

	private function history(): History {
		if ( null === $this->history ) {
			$entries       = get_option( self::OPTION_HISTORY, array() );
			$this->history = new History( $this->clock, is_array( $entries ) ? $entries : array() );
		}

		return $this->history;
	}
}
