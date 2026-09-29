<?php
/**
 * Bounded activity history.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Maintains a small, bounded log of freeze state changes and blocked write
 * attempts, stored in the `deployment_guard_history` option.
 *
 * The log is advisory, not an audit trail: entries are capped, deduplicated
 * and throttled so that a determined or automated stream of blocked
 * requests cannot grow the option without bound, and no request payload,
 * IP address or message body is ever stored.
 */
final class History {

	/** Maximum number of entries kept in total. */
	public const MAX_ENTRIES = 100;

	/** Maximum number of "blocked" entries kept, out of MAX_ENTRIES. */
	public const MAX_BLOCKED = 60;

	/**
	 * A "blocked" entry with the same user, vector and target as one
	 * already logged within this many seconds is dropped as a duplicate.
	 */
	public const DEDUP_WINDOW = 600;

	/** Maximum "blocked" entries accepted within THROTTLE_WINDOW, in total. */
	public const THROTTLE_GLOBAL = 30;

	/** Maximum "blocked" entries accepted within THROTTLE_WINDOW, per user. */
	public const THROTTLE_PER_USER = 10;

	/** Sliding window, in seconds, the throttle counters apply over. */
	public const THROTTLE_WINDOW = 600;

	/** Maximum length, in bytes, of a single string context value. */
	public const MAX_CONTEXT_VALUE_LENGTH = 120;

	/**
	 * Allowlist of context keys that may ever be stored. Anything else is
	 * silently dropped: this is what keeps request payloads, IP addresses
	 * and message bodies out of the log even if a caller passes them by
	 * mistake.
	 *
	 * @var string[]
	 */
	private const ALLOWED_CONTEXT_KEYS = array(
		'source',
		'vector',
		'target',
		'method',
		'object_id',
		'start',
		'end',
		'schedule_cleared',
		'length',
	);

	/** @var array<int, array{t:int,type:string,user:int,ctx:array}> */
	private array $entries;

	private \Closure $now;

	/**
	 * @param \Closure $now     Returns the current Unix timestamp. Injected
	 *                          so tests can control it.
	 * @param array    $entries Existing entries, newest first.
	 */
	public function __construct( \Closure $now, array $entries = array() ) {
		$this->now     = $now;
		$this->entries = $entries;
	}

	/**
	 * @return array<int, array{t:int,type:string,user:int,ctx:array}> Newest first.
	 */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * Record a state-change event (enabled, disabled, schedule_set,
	 * schedule_cleared, message_updated). These are never deduplicated or
	 * throttled: each one reflects a deliberate action.
	 */
	public function record( string $type, int $user_id, array $context = array() ): void {
		$entry = array(
			't'    => ( $this->now )(),
			'type' => $type,
			'user' => $user_id,
			'ctx'  => self::sanitize_context( $context ),
		);

		$this->entries = self::append( $this->entries, $entry, $entry['t'] ) ?? $this->entries;
	}

	/**
	 * Record a blocked write attempt. Subject to deduplication and
	 * throttling (see class constants); returns false when the entry was
	 * dropped for either reason.
	 */
	public function record_blocked( string $vector, string $target, int $user_id, array $context = array() ): bool {
		$context['vector'] = $vector;
		$context['target'] = $target;

		$entry = array(
			't'    => ( $this->now )(),
			'type' => 'blocked',
			'user' => $user_id,
			'ctx'  => self::sanitize_context( $context ),
		);

		$result = self::append( $this->entries, $entry, $entry['t'] );

		if ( null === $result ) {
			return false;
		}

		$this->entries = $result;

		return true;
	}

	/**
	 * Pure merge: prepend $entry to $entries, applying the dedup/throttle
	 * rules for "blocked" entries and the size caps, or return null when
	 * $entry itself should be dropped.
	 *
	 * $entries is assumed newest-first, which lets the dedup/throttle scan
	 * stop as soon as it reaches an entry older than THROTTLE_WINDOW.
	 *
	 * @return array|null The new entries list, or null if $entry was
	 *                     dropped (in which case $entries is unchanged).
	 */
	public static function append( array $entries, array $entry, int $now ): ?array {
		if ( 'blocked' === $entry['type'] ) {
			$global_count = 0;
			$user_count   = 0;

			foreach ( $entries as $existing ) {
				if ( 'blocked' !== ( $existing['type'] ?? null ) ) {
					continue;
				}

				$age = $now - (int) ( $existing['t'] ?? 0 );

				if ( $age > self::THROTTLE_WINDOW ) {
					break; // Entries are newest-first: nothing further can be within the window.
				}

				if ( $age <= self::DEDUP_WINDOW
					&& ( $existing['user'] ?? null ) === $entry['user']
					&& ( $existing['ctx']['vector'] ?? null ) === ( $entry['ctx']['vector'] ?? null )
					&& ( $existing['ctx']['target'] ?? null ) === ( $entry['ctx']['target'] ?? null )
				) {
					return null;
				}

				++$global_count;

				if ( ( $existing['user'] ?? null ) === $entry['user'] ) {
					++$user_count;
				}
			}

			if ( $global_count >= self::THROTTLE_GLOBAL || $user_count >= self::THROTTLE_PER_USER ) {
				return null;
			}
		}

		array_unshift( $entries, $entry );

		$blocked_indexes = array();

		foreach ( $entries as $index => $existing ) {
			if ( 'blocked' === ( $existing['type'] ?? null ) ) {
				$blocked_indexes[] = $index;
			}
		}

		if ( count( $blocked_indexes ) > self::MAX_BLOCKED ) {
			$excess    = count( $blocked_indexes ) - self::MAX_BLOCKED;
			$to_remove = array_slice( $blocked_indexes, -$excess );

			foreach ( $to_remove as $index ) {
				unset( $entries[ $index ] );
			}

			$entries = array_values( $entries );
		}

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, 0, self::MAX_ENTRIES );
		}

		return $entries;
	}

	/**
	 * Keep only allowlisted context keys, and hard-cap string values as a
	 * defense-in-depth measure (callers are also expected to sanitize
	 * before this point).
	 */
	public static function sanitize_context( array $context ): array {
		$clean = array();

		foreach ( self::ALLOWED_CONTEXT_KEYS as $key ) {
			if ( ! array_key_exists( $key, $context ) ) {
				continue;
			}

			$value = $context[ $key ];

			if ( is_bool( $value ) || is_int( $value ) ) {
				$clean[ $key ] = $value;
				continue;
			}

			if ( is_string( $value ) ) {
				$value = substr( $value, 0, self::MAX_CONTEXT_VALUE_LENGTH );

				if ( '' !== $value ) {
					$clean[ $key ] = $value;
				}
			}
		}

		return $clean;
	}
}
