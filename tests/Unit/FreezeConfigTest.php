<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Unit;

use Digibold\DeploymentGuard\FreezeConfig;
use PHPUnit\Framework\TestCase;

final class FreezeConfigTest extends TestCase {

	private const NOW = 1_800_000_000;

	public function test_defaults_are_inactive(): void {
		$config = FreezeConfig::defaults();

		$this->assertFalse( $config->is_active( self::NOW ) );
		$this->assertSame( 'inactive', $config->mode() );
		$this->assertSame( 'none', $config->schedule_status( self::NOW ) );
		$this->assertNull( $config->ends_at( self::NOW ) );
	}

	public function test_manual_freeze_is_active(): void {
		$config = FreezeConfig::defaults()->with_enabled( true, self::NOW, 1 );

		$this->assertTrue( $config->is_active( self::NOW ) );
		$this->assertSame( 'manual', $config->mode() );
		// Manual mode has no predictable end.
		$this->assertNull( $config->ends_at( self::NOW ) );
	}

	public function test_schedule_boundaries(): void {
		$start  = self::NOW + 1000;
		$end    = self::NOW + 2000;
		$config = FreezeConfig::defaults()->with_schedule( $start, $end, self::NOW, 1 );

		$this->assertSame( 'scheduled', $config->mode() );

		// Before the window.
		$this->assertFalse( $config->is_active( $start - 1 ) );
		$this->assertSame( 'upcoming', $config->schedule_status( $start - 1 ) );

		// Start is inclusive.
		$this->assertTrue( $config->is_active( $start ) );
		$this->assertSame( 'active', $config->schedule_status( $start ) );
		$this->assertSame( $end, $config->ends_at( $start ) );

		// Inside the window.
		$this->assertTrue( $config->is_active( $start + 500 ) );

		// End is exclusive.
		$this->assertFalse( $config->is_active( $end ) );
		$this->assertSame( 'expired', $config->schedule_status( $end ) );
		$this->assertNull( $config->ends_at( $end ) );

		// After the window.
		$this->assertFalse( $config->is_active( $end + 1 ) );
	}

	public function test_manual_freeze_with_expired_window_is_still_active(): void {
		$config = FreezeConfig::defaults()
			->with_schedule( self::NOW - 2000, self::NOW - 1000, self::NOW, 1 )
			->with_enabled( true, self::NOW, 1 );

		$this->assertTrue( $config->is_active( self::NOW ) );
		$this->assertSame( 'manual', $config->mode() );
		$this->assertSame( 'expired', $config->schedule_status( self::NOW ) );
		// Manual mode wins over an expired schedule for ends_at().
		$this->assertNull( $config->ends_at( self::NOW ) );
	}

	/**
	 * @dataProvider provide_garbage_input
	 */
	public function test_from_array_tolerates_garbage_input( mixed $data ): void {
		$config = FreezeConfig::from_array( $data );

		$this->assertFalse( $config->is_active( self::NOW ) );
		$this->assertSame( '', $config->message );
		$this->assertSame( 0, $config->updated_at );
		$this->assertSame( 0, $config->updated_by );
	}

	public static function provide_garbage_input(): array {
		return array(
			'null'                 => array( null ),
			'string'                => array( 'not an array' ),
			'int'                   => array( 42 ),
			'empty array'           => array( array() ),
			'wrong types'           => array(
				array(
					'enabled'        => 'yes',
					'schedule_start' => 'soon',
					'schedule_end'   => array(),
					'message'        => 12345,
					'updated_at'     => 'now',
					'updated_by'     => 'admin',
				),
			),
			'end before start'      => array(
				array(
					'schedule_start' => self::NOW + 2000,
					'schedule_end'   => self::NOW + 1000,
				),
			),
			'end equals start'      => array(
				array(
					'schedule_start' => self::NOW,
					'schedule_end'   => self::NOW,
				),
			),
			'only start'            => array( array( 'schedule_start' => self::NOW ) ),
			'only end'              => array( array( 'schedule_end' => self::NOW ) ),
		);
	}

	public function test_to_array_round_trip(): void {
		$original = FreezeConfig::defaults()
			->with_schedule( self::NOW + 100, self::NOW + 200, self::NOW, 7 )
			->with_message( 'Deploying now.', self::NOW, 7 );

		$restored = FreezeConfig::from_array( $original->to_array() );

		$this->assertSame( $original->to_array(), $restored->to_array() );
	}

	public function test_immutability(): void {
		$original = FreezeConfig::defaults();
		$changed  = $original->with_enabled( true, self::NOW, 1 );

		$this->assertFalse( $original->enabled );
		$this->assertTrue( $changed->enabled );
		$this->assertNotSame( $original, $changed );
	}

	public function test_without_schedule_clears_window_only(): void {
		$config = FreezeConfig::defaults()
			->with_schedule( self::NOW + 100, self::NOW + 200, self::NOW, 1 )
			->with_enabled( true, self::NOW, 1 )
			->without_schedule( self::NOW, 1 );

		$this->assertNull( $config->schedule_start );
		$this->assertNull( $config->schedule_end );
		$this->assertTrue( $config->enabled );
	}

	/**
	 * @dataProvider provide_validate_schedule_boundaries
	 */
	public function test_validate_schedule_boundaries( int $start, int $end, int $now, ?string $expected ): void {
		$this->assertSame( $expected, FreezeConfig::validate_schedule( $start, $end, $now ) );
	}

	public static function provide_validate_schedule_boundaries(): array {
		$now = self::NOW;

		return array(
			'valid window'                    => array( $now + 10, $now + 20, $now, null ),
			'end equals start'                => array( $now + 10, $now + 10, $now, 'end_not_after_start' ),
			'end one second before start'      => array( $now + 10, $now + 9, $now, 'end_not_after_start' ),
			'end exactly now'                  => array( $now - 10, $now, $now, 'end_in_past' ),
			'end one second in the past'       => array( $now - 20, $now - 1, $now, 'end_in_past' ),
			'end one second in the future'     => array( $now - 20, $now + 1, $now, null ),
			'window exactly max'               => array( $now, $now + FreezeConfig::MAX_WINDOW_SECONDS, $now, null ),
			'window one second over max'        => array( $now, $now + FreezeConfig::MAX_WINDOW_SECONDS + 1, $now, 'window_too_long' ),
			'start exactly max lead'           => array(
				$now + FreezeConfig::MAX_START_LEAD_SECONDS,
				$now + FreezeConfig::MAX_START_LEAD_SECONDS + 10,
				$now,
				null,
			),
			'start one second past max lead'   => array(
				$now + FreezeConfig::MAX_START_LEAD_SECONDS + 1,
				$now + FreezeConfig::MAX_START_LEAD_SECONDS + 10,
				$now,
				'start_too_far',
			),
			'start in the past is allowed'     => array( $now - 1000, $now + 1000, $now, null ),
		);
	}
}
