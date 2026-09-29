<?php
/**
 * Immutable freeze configuration value object.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Represents the stored `deployment_guard_state` option as an immutable
 * value object with pure, dependency-free schedule rules.
 *
 * This class never calls a WordPress function: every method takes the
 * current time as a plain integer, which is what lets the schedule logic
 * be unit-tested without loading WordPress.
 */
final class FreezeConfig {

	public const SCHEMA_VERSION = 1;

	/** Maximum span, in seconds, between a scheduled start and end. */
	public const MAX_WINDOW_SECONDS = 7 * 86400;

	/** Maximum distance, in seconds, a scheduled start may lie in the future. */
	public const MAX_START_LEAD_SECONDS = 365 * 86400;

	/** Maximum stored message length, in characters. */
	public const MAX_MESSAGE_LENGTH = 500;

	private function __construct(
		public readonly bool $enabled,
		public readonly ?int $schedule_start,
		public readonly ?int $schedule_end,
		public readonly string $message,
		public readonly int $updated_at,
		public readonly int $updated_by,
		public readonly int $schema = self::SCHEMA_VERSION
	) {}

	/**
	 * Build a config from whatever is stored in the option, tolerating
	 * missing keys, wrong types, or a corrupted value entirely.
	 */
	public static function from_array( mixed $data ): self {
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		// Strict comparison on purpose: the option only ever stores a real
		// boolean, so anything else (a stray string, a number) is treated
		// as corrupt data and falls back to "not enabled" rather than
		// being coerced with a loose truthy check.
		$enabled = true === ( $data['enabled'] ?? null );

		$start = self::to_nullable_int( $data['schedule_start'] ?? null );
		$end   = self::to_nullable_int( $data['schedule_end'] ?? null );

		if ( null === $start || null === $end || $end <= $start ) {
			$start = null;
			$end   = null;
		}

		$message = isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : '';

		return new self(
			$enabled,
			$start,
			$end,
			$message,
			self::to_nullable_int( $data['updated_at'] ?? null ) ?? 0,
			self::to_nullable_int( $data['updated_by'] ?? null ) ?? 0
		);
	}

	/**
	 * Build the default (inactive, unscheduled) configuration.
	 */
	public static function defaults(): self {
		return new self( false, null, null, '', 0, 0 );
	}

	public function to_array(): array {
		return array(
			'schema'         => $this->schema,
			'enabled'        => $this->enabled,
			'schedule_start' => $this->schedule_start,
			'schedule_end'   => $this->schedule_end,
			'message'        => $this->message,
			'updated_at'     => $this->updated_at,
			'updated_by'     => $this->updated_by,
		);
	}

	/**
	 * Whether a freeze is in effect at $now: either manually enabled, or
	 * inside a scheduled window (start inclusive, end exclusive).
	 */
	public function is_active( int $now ): bool {
		if ( $this->enabled ) {
			return true;
		}

		return null !== $this->schedule_start
			&& $this->schedule_start <= $now
			&& $now < $this->schedule_end;
	}

	/**
	 * Why the freeze is (or would be) active: 'manual' when the operator
	 * turned it on directly, 'scheduled' when only a window is configured,
	 * 'inactive' when neither is set.
	 */
	public function mode(): string {
		if ( $this->enabled ) {
			return 'manual';
		}

		if ( null !== $this->schedule_start ) {
			return 'scheduled';
		}

		return 'inactive';
	}

	/**
	 * Where $now falls relative to the configured schedule, independent
	 * of the manual `enabled` flag: 'none', 'upcoming', 'active', or
	 * 'expired'.
	 */
	public function schedule_status( int $now ): string {
		if ( null === $this->schedule_start ) {
			return 'none';
		}

		if ( $now < $this->schedule_start ) {
			return 'upcoming';
		}

		if ( $now < $this->schedule_end ) {
			return 'active';
		}

		return 'expired';
	}

	/**
	 * The UTC timestamp the current freeze is expected to end at, or null
	 * when there is no predictable end (no freeze active, or the freeze
	 * is manual and will last until an operator disables it).
	 */
	public function ends_at( int $now ): ?int {
		if ( $this->enabled ) {
			return null;
		}

		if ( 'active' === $this->schedule_status( $now ) ) {
			return $this->schedule_end;
		}

		return null;
	}

	public function with_enabled( bool $enabled, int $now, int $user_id ): self {
		return new self( $enabled, $this->schedule_start, $this->schedule_end, $this->message, $now, $user_id );
	}

	public function with_schedule( int $start, int $end, int $now, int $user_id ): self {
		return new self( $this->enabled, $start, $end, $this->message, $now, $user_id );
	}

	public function without_schedule( int $now, int $user_id ): self {
		return new self( $this->enabled, null, null, $this->message, $now, $user_id );
	}

	public function with_message( string $message, int $now, int $user_id ): self {
		return new self( $this->enabled, $this->schedule_start, $this->schedule_end, $message, $now, $user_id );
	}

	/**
	 * Validate a proposed schedule window. Assumes $start and $end have
	 * already been parsed into timestamps (see DateInput); this only
	 * checks the relationship between them and $now.
	 *
	 * @return string|null An error code, or null when the window is valid.
	 */
	public static function validate_schedule( int $start, int $end, int $now ): ?string {
		if ( $end <= $start ) {
			return 'end_not_after_start';
		}

		if ( $end <= $now ) {
			return 'end_in_past';
		}

		if ( ( $end - $start ) > self::MAX_WINDOW_SECONDS ) {
			return 'window_too_long';
		}

		if ( ( $start - $now ) > self::MAX_START_LEAD_SECONDS ) {
			return 'start_too_far';
		}

		return null;
	}

	private static function to_nullable_int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && 1 === preg_match( '/^-?\d+$/', $value ) ) {
			return (int) $value;
		}

		if ( is_float( $value ) ) {
			return (int) $value;
		}

		return null;
	}
}
