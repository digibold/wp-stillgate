<?php
/**
 * Pure datetime parsing and formatting.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Parses and formats the datetimes used by the settings screen and WP-CLI.
 *
 * Every method here is free of WordPress and PHP runtime state: no
 * `time()`, no `wp_timezone()`. Callers pass in the timezone and, where
 * relevant, the current time, which keeps date handling deterministic and
 * unit-testable without loading WordPress.
 */
final class DateInput {

	/**
	 * The only accepted keyword for "the current moment" in CLI input.
	 */
	private const CLI_NOW = 'now';

	/**
	 * Formats accepted for a local wall-clock datetime, most specific
	 * first, each paired with a regular expression that recognizes its
	 * shape. Both an ISO-8601-style "T" separator and a plain space are
	 * accepted, because the space form is what a browser without
	 * `<input type="datetime-local">` support, or a copy-pasted value,
	 * is likely to submit.
	 *
	 * The regular expression decides which single format a value is
	 * attempting to use, before any parsing happens: this is what lets a
	 * value that matches a shape but names an impossible moment be
	 * reported precisely, rather than falling through to `invalid_format`
	 * just because a later shape does not match either. An out-of-range
	 * component (February 30th, a 13th month, a 25th hour) is
	 * `invalid_format`; a real calendar moment that simply does not occur
	 * locally because of a daylight-saving transition is
	 * `nonexistent_local_time` — see parse_matched_format().
	 *
	 * @var array<string, string>
	 */
	private const LOCAL_FORMATS = array(
		'Y-m-d\TH:i:s' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/',
		'Y-m-d\TH:i'   => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',
		'Y-m-d H:i:s'  => '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
		'Y-m-d H:i'    => '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/',
	);

	/**
	 * Parse a datetime-local style value ("2026-09-14T22:07", optionally
	 * with seconds, optionally with a space instead of "T") as wall-clock
	 * time in the given timezone.
	 *
	 * @param string        $value    Raw input.
	 * @param \DateTimeZone $timezone Timezone the value is expressed in.
	 * @return int Unix timestamp (UTC).
	 *
	 * @throws \DomainException With message `invalid_format` or
	 *                          `nonexistent_local_time`. The message is a
	 *                          machine-readable code, not a sentence.
	 */
	public static function parse_local( string $value, \DateTimeZone $timezone ): int {
		$value = trim( $value );

		if ( '' === $value ) {
			throw new \DomainException( 'invalid_format' );
		}

		foreach ( self::LOCAL_FORMATS as $format => $pattern ) {
			if ( 1 === preg_match( $pattern, $value ) ) {
				return self::parse_matched_format( $value, $format, $timezone );
			}
		}

		throw new \DomainException( 'invalid_format' );
	}

	/**
	 * Parse a WP-CLI `--start`/`--end` value: the literal "now", a local
	 * wall-clock datetime in the given timezone, or a datetime carrying an
	 * explicit UTC/offset designator ("Z" or "+02:00").
	 *
	 * Deliberately rejects relative phrases ("tomorrow", "+2 hours") and
	 * bare Unix timestamps: both are easy to mistype and hard to audit in
	 * a deployment script.
	 *
	 * @param string        $value    Raw input.
	 * @param \DateTimeZone $timezone Site timezone, used when no explicit
	 *                                offset is present.
	 * @param int           $now      Current time (Unix, UTC), used only
	 *                                for the literal "now".
	 * @return int Unix timestamp (UTC).
	 *
	 * @throws \DomainException With message `invalid_format` or
	 *                          `nonexistent_local_time`.
	 */
	public static function parse_cli( string $value, \DateTimeZone $timezone, int $now ): int {
		$value = trim( $value );

		if ( '' === $value ) {
			throw new \DomainException( 'invalid_format' );
		}

		if ( self::CLI_NOW === strtolower( $value ) ) {
			return $now;
		}

		if ( 1 === preg_match( '/^\d+$/', $value ) ) {
			// Reject bare epoch seconds: indistinguishable from a typo of
			// a local date and easy to get wrong in a shell script.
			throw new \DomainException( 'invalid_format' );
		}

		if ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?)(Z|[+-]\d{2}:?\d{2})$/', $value, $matches ) ) {
			// An explicit designator names a fixed offset: parse the date
			// and time before it with the same strict, round-tripping rules
			// as a local value, in that offset instead of the site timezone.
			$designator = 'Z' === $matches[2] ? '+00:00' : $matches[2];

			try {
				$offset = new \DateTimeZone( $designator );
			} catch ( \Exception $exception ) {
				throw new \DomainException( 'invalid_format' );
			}

			return self::parse_local( $matches[1], $offset );
		}

		if ( 1 === preg_match( '/(?:Z|[+-]\d{2}:?\d{2})$/', $value ) ) {
			// Anything else carrying a designator ("tomorrow Z", "now+02:00")
			// is a relative phrase PHP would otherwise happily accept.
			throw new \DomainException( 'invalid_format' );
		}

		return self::parse_local( $value, $timezone );
	}

	/**
	 * Parse a short duration such as "30m", "2h", "1d", "1h30m" or "90s"
	 * into seconds. A bare integer means minutes. Units are s, m, h and d
	 * (also "min", "hour", "day" and their plurals), written without
	 * relative phrases, signs or decimals, so scripts get one unambiguous
	 * spelling.
	 *
	 * @param string $value Raw input.
	 * @return int Seconds, always greater than zero.
	 *
	 * @throws \DomainException With message `invalid_duration`.
	 */
	public static function parse_duration( string $value ): int {
		// Far beyond the longest window anyone may start (the service
		// reports that as window_too_long), and far below anything that
		// could overflow an integer when added to a timestamp.
		$ceiling = 10 * 365 * 86400;

		$value = strtolower( trim( $value ) );

		if ( 1 === preg_match( '/^\d+$/', $value ) ) {
			$value .= 'm';
		}

		if ( '' === $value || 1 !== preg_match( '/^(?:\d+\s*(?:seconds?|secs?|s|minutes?|mins?|m|hours?|hrs?|h|days?|d)\s*)+$/', $value ) ) {
			throw new \DomainException( 'invalid_duration' );
		}

		preg_match_all( '/(\d+)\s*(seconds?|secs?|s|minutes?|mins?|m|hours?|hrs?|h|days?|d)/', $value, $parts, PREG_SET_ORDER );

		$seconds = 0;

		foreach ( $parts as $part ) {
			$unit       = $part[2][0];
			$multiplier = 'd' === $unit ? 86400 : ( 'h' === $unit ? 3600 : ( 'm' === $unit ? 60 : 1 ) );

			// Compared before multiplying, as digits: a number too large for
			// an integer must be refused, not wrapped into a float.
			if ( strlen( ltrim( $part[1], '0' ) ) > 10 || (int) $part[1] > intdiv( $ceiling, $multiplier ) ) {
				throw new \DomainException( 'invalid_duration' );
			}

			$seconds += (int) $part[1] * $multiplier;

			if ( $seconds > $ceiling ) {
				throw new \DomainException( 'invalid_duration' );
			}
		}

		if ( $seconds <= 0 ) {
			throw new \DomainException( 'invalid_duration' );
		}

		return $seconds;
	}

	/**
	 * Format a UTC timestamp as a value suitable for an HTML
	 * `datetime-local` input, in the given timezone.
	 */
	public static function to_input_value( int $timestamp, \DateTimeZone $timezone ): string {
		$date = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone );

		return $date->format( 'Y-m-d\TH:i' );
	}

	/**
	 * Parse $value against $format, once its shape has already been
	 * matched by the corresponding regular expression in LOCAL_FORMATS.
	 * PHP silently normalizes an out-of-range component (2026-13-05,
	 * 2026-02-30, a 25th hour) into a real, different moment instead of
	 * failing outright, but flags it with a warning from
	 * DateTimeImmutable::getLastErrors() — that is `invalid_format`, the
	 * same code an unparseable value gets. A daylight-saving gap (a local
	 * time that never occurred, such as 03:30 on a spring-forward day)
	 * parses with no warning at all, and is caught only by the round-trip
	 * check below failing to reproduce the exact same wall-clock string —
	 * that is `nonexistent_local_time`.
	 *
	 * @throws \DomainException With message `invalid_format` or
	 *                          `nonexistent_local_time`.
	 */
	private static function parse_matched_format( string $value, string $format, \DateTimeZone $timezone ): int {
		$date = \DateTimeImmutable::createFromFormat( '!' . $format, $value, $timezone );

		if ( false === $date ) {
			throw new \DomainException( 'invalid_format' );
		}

		$errors        = \DateTimeImmutable::getLastErrors();
		$warning_count = 0;
		$error_count   = 0;

		if ( is_array( $errors ) ) {
			$warning_count = $errors['warning_count'] ?? 0;
			$error_count   = $errors['error_count'] ?? 0;
		}

		if ( $warning_count > 0 || $error_count > 0 ) {
			throw new \DomainException( 'invalid_format' );
		}

		if ( $date->format( $format ) !== $value ) {
			throw new \DomainException( 'nonexistent_local_time' );
		}

		return $date->getTimestamp();
	}
}
