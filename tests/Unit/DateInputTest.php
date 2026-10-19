<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Unit;

use Digibold\DeploymentGuard\DateInput;
use PHPUnit\Framework\TestCase;

final class DateInputTest extends TestCase {

	private function riga(): \DateTimeZone {
		return new \DateTimeZone( 'Europe/Riga' );
	}

	/**
	 * @dataProvider provide_valid_local_forms
	 */
	public function test_parse_local_accepts_valid_forms( string $value, string $expectedUtc ): void {
		$timestamp = DateInput::parse_local( $value, $this->riga() );

		$this->assertSame(
			$expectedUtc,
			gmdate( 'Y-m-d\TH:i:s\Z', $timestamp )
		);
	}

	public static function provide_valid_local_forms(): array {
		// 2026-06-15 is well outside any DST transition in Europe/Riga
		// (EEST, UTC+3) so the expected UTC offset is stable and obvious.
		return array(
			'T separator, no seconds'    => array( '2026-06-15T14:30', '2026-06-15T11:30:00Z' ),
			'T separator, with seconds'  => array( '2026-06-15T14:30:45', '2026-06-15T11:30:45Z' ),
			'space separator, no seconds' => array( '2026-06-15 14:30', '2026-06-15T11:30:00Z' ),
			'space separator, seconds'   => array( '2026-06-15 14:30:45', '2026-06-15T11:30:45Z' ),
		);
	}

	/**
	 * @dataProvider provide_invalid_local_forms
	 */
	public function test_parse_local_rejects_invalid_forms( string $value, string $expectedCode ): void {
		$this->expectException( \DomainException::class );
		$this->expectExceptionMessage( $expectedCode );

		DateInput::parse_local( $value, $this->riga() );
	}

	public static function provide_invalid_local_forms(): array {
		return array(
			'empty string'           => array( '', 'invalid_format' ),
			'garbage'                => array( 'not a date', 'invalid_format' ),
			'missing time'           => array( '2026-06-15', 'invalid_format' ),
			// These are calendar-out-of-range values, not moments that
			// genuinely exist but are unreachable locally (a DST gap) —
			// PHP itself flags them via DateTimeImmutable::getLastErrors(),
			// which is what distinguishes them from the DST gap case
			// below. See test_parse_local_rejects_dst_gap_in_riga().
			'impossible day'         => array( '2026-02-30T10:00', 'invalid_format' ),
			'month out of range'     => array( '2026-13-05T10:00', 'invalid_format' ),
			'hour out of range'      => array( '2026-06-15T24:00', 'invalid_format' ),
			'minute out of range'    => array( '2026-06-15T10:60', 'invalid_format' ),
		);
	}

	public function test_parse_local_rejects_dst_gap_in_riga(): void {
		// Europe/Riga moves clocks from 02:59:59 EET straight to
		// 04:00:00 EEST on the last Sunday of March; 03:30 never occurs.
		$this->expectException( \DomainException::class );
		$this->expectExceptionMessage( 'nonexistent_local_time' );

		DateInput::parse_local( '2026-03-29T03:30', $this->riga() );
	}

	public function test_parse_local_pins_dst_overlap_in_riga(): void {
		// Europe/Riga moves clocks from 03:59:59 EEST back to 03:00:00
		// EET on the last Sunday of October; 03:30 occurs twice. This
		// test pins whatever PHP resolves it to, so a change in behaviour
		// is caught rather than silently accepted.
		$timestamp = DateInput::parse_local( '2026-10-25T03:30', $this->riga() );

		$this->assertIsInt( $timestamp );

		// Both interpretations are exactly one hour apart; assert the
		// result is one of the two rather than assuming a specific one.
		$early = gmmktime( 0, 30, 0, 10, 25, 2026 ); // 03:30 EEST = 00:30 UTC.
		$late  = gmmktime( 1, 30, 0, 10, 25, 2026 ); // 03:30 EET  = 01:30 UTC.

		$this->assertContains( $timestamp, array( $early, $late ) );
	}

	public function test_parse_local_accepts_utc_offset_timezone(): void {
		$timestamp = DateInput::parse_local( '2026-06-15T14:30', new \DateTimeZone( '+02:00' ) );

		$this->assertSame( '2026-06-15T12:30:00Z', gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) );
	}

	public function test_parse_cli_accepts_now(): void {
		$now = 1_800_000_000;

		$this->assertSame( $now, DateInput::parse_cli( 'now', $this->riga(), $now ) );
		$this->assertSame( $now, DateInput::parse_cli( 'NOW', $this->riga(), $now ) );
	}

	public function test_parse_cli_accepts_zulu_suffix(): void {
		$timestamp = DateInput::parse_cli( '2026-06-15T11:30:00Z', $this->riga(), 0 );

		$this->assertSame( '2026-06-15T11:30:00Z', gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) );
	}

	public function test_parse_cli_accepts_explicit_offset(): void {
		$timestamp = DateInput::parse_cli( '2026-06-15T14:30:00+03:00', $this->riga(), 0 );

		$this->assertSame( '2026-06-15T11:30:00Z', gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) );
	}

	public function test_parse_cli_accepts_negative_offset(): void {
		$timestamp = DateInput::parse_cli( '2026-06-15T09:30:00-05:30', $this->riga(), 0 );

		$this->assertSame( '2026-06-15T15:00:00Z', gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) );
	}

	public function test_parse_cli_falls_back_to_local_datetime(): void {
		$timestamp = DateInput::parse_cli( '2026-06-15T14:30', $this->riga(), 0 );

		$this->assertSame( '2026-06-15T11:30:00Z', gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) );
	}

	/**
	 * @dataProvider provide_rejected_cli_forms
	 */
	public function test_parse_cli_rejects_ambiguous_forms( string $value ): void {
		$this->expectException( \DomainException::class );

		DateInput::parse_cli( $value, $this->riga(), 1_800_000_000 );
	}

	public static function provide_rejected_cli_forms(): array {
		return array(
			'relative phrase'   => array( 'tomorrow' ),
			'relative offset'   => array( '+2 hours' ),
			'bare epoch'        => array( '1700000000' ),
			'empty string'      => array( '' ),
			'relative phrase with a Z designator'     => array( 'tomorrow Z' ),
			'relative phrase with an offset'          => array( 'next monday +02:00' ),
			'now with an offset'                      => array( 'now+02:00' ),
			'bare designator'                         => array( 'Z' ),
			'impossible calendar date with Z'         => array( '2026-02-30T10:00:00Z' ),
			'impossible time with an offset'          => array( '2026-06-15T24:30:00+02:00' ),
		);
	}

	public function test_parse_cli_accepts_compact_offset_and_space_separator(): void {
		$timestamp = DateInput::parse_cli( '2026-06-15 14:30+0300', $this->riga(), 0 );

		$this->assertSame( '2026-06-15T11:30:00Z', gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) );
	}

	public function test_to_input_value_round_trips(): void {
		$original  = '2026-06-15T14:30';
		$timestamp = DateInput::parse_local( $original, $this->riga() );

		$this->assertSame( $original, DateInput::to_input_value( $timestamp, $this->riga() ) );
	}
	/**
	 * @dataProvider provide_valid_durations
	 */
	public function test_parse_duration_accepts_valid_forms( string $value, int $expected_seconds ): void {
		$this->assertSame( $expected_seconds, DateInput::parse_duration( $value ) );
	}

	public static function provide_valid_durations(): array {
		return array(
			'minutes'                      => array( '30m', 1800 ),
			'minutes, long unit'           => array( '30 minutes', 1800 ),
			'minutes, mid unit'            => array( '5min', 300 ),
			'hours'                        => array( '2h', 7200 ),
			'hours, long unit'             => array( '1 hour', 3600 ),
			'days'                         => array( '1d', 86400 ),
			'seconds'                      => array( '90s', 90 ),
			'combined'                     => array( '1h30m', 5400 ),
			'combined with spaces'         => array( '1h 30m', 5400 ),
			'combined days and hours'      => array( '1d12h', 129600 ),
			'upper case'                   => array( '45M', 2700 ),
			'bare number means minutes'    => array( '45', 2700 ),
			'surrounding whitespace'       => array( '  15m ', 900 ),
		);
	}

	/**
	 * @dataProvider provide_invalid_durations
	 */
	public function test_parse_duration_rejects_invalid_forms( string $value ): void {
		$this->expectException( \DomainException::class );
		$this->expectExceptionMessage( 'invalid_duration' );

		DateInput::parse_duration( $value );
	}

	public static function provide_invalid_durations(): array {
		return array(
			'empty'               => array( '' ),
			'whitespace'          => array( '   ' ),
			'zero'                => array( '0' ),
			'zero with unit'      => array( '0m' ),
			'negative'            => array( '-5m' ),
			'decimal'             => array( '1.5h' ),
			'unit only'           => array( 'm' ),
			'word'                => array( 'soon' ),
			'relative phrase'     => array( 'in 2 hours' ),
			'trailing garbage'    => array( '30m later' ),
			'unknown unit'        => array( '3w' ),
			'iso 8601 duration'   => array( 'PT30M' ),
			'overflowing days'    => array( '9999999999999999d' ),
			'overflowing minutes' => array( '99999999999999999999' ),
			'saturating seconds'  => array( '9223372036854775807s' ),
			'beyond ten years'    => array( '4000d' ),
			'sum beyond ten years' => array( '3000d3000d' ),
		);
	}
}
