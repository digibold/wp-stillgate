<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

final class FreezeServiceTest extends TestCase {

	private const NOW = 1_800_000_000;

	public function test_enable_is_idempotent(): void {
		$service = $this->make_service( self::NOW );

		$this->assertSame( 'enabled', $service->enable() );
		$this->assertSame( 'unchanged', $service->enable() );
		$this->assertTrue( $service->is_active() );
	}

	public function test_disable_when_inactive_is_unchanged(): void {
		$service = $this->make_service( self::NOW );

		$this->assertSame( 'unchanged', $service->disable() );
	}

	public function test_disable_clears_a_currently_running_scheduled_window(): void {
		$service = $this->make_service( self::NOW );

		$service->schedule( self::NOW - 100, self::NOW + 100 );
		$this->assertTrue( $service->is_active() );

		$this->assertSame( 'disabled', $service->disable() );
		$this->assertFalse( $service->is_active() );
		$this->assertNull( $service->config()->schedule_start );
	}

	public function test_disable_keeps_a_future_scheduled_window(): void {
		$service = $this->make_service( self::NOW );

		$service->schedule( self::NOW + 1000, self::NOW + 2000 );
		$service->enable();

		$this->assertSame( 'disabled', $service->disable() );
		$this->assertFalse( $service->is_active() );
		$this->assertNotNull( $service->config()->schedule_start );
	}

	public function test_schedule_replaces_existing_window(): void {
		$service = $this->make_service( self::NOW );

		$service->schedule( self::NOW + 100, self::NOW + 200 );
		$service->schedule( self::NOW + 300, self::NOW + 400 );

		$this->assertSame( self::NOW + 300, $service->config()->schedule_start );
		$this->assertSame( self::NOW + 400, $service->config()->schedule_end );
	}

	public function test_clear_schedule_while_running(): void {
		$service = $this->make_service( self::NOW );

		$service->schedule( self::NOW - 100, self::NOW + 100 );
		$this->assertSame( 'schedule_cleared', $service->clear_schedule() );
		$this->assertFalse( $service->is_active() );
	}

	/**
	 * @dataProvider provide_schedule_errors
	 */
	public function test_schedule_validation_errors( int $start, int $end, string $expected_code ): void {
		$service = $this->make_service( self::NOW );
		$result  = $service->schedule( $start, $end );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $expected_code, $result->get_error_code() );
	}

	public static function provide_schedule_errors(): array {
		$now = self::NOW;

		return array(
			'end before start' => array( $now + 100, $now + 50, 'end_not_after_start' ),
			'end in the past'  => array( $now - 200, $now - 100, 'end_in_past' ),
		);
	}

	public function test_message_crlf_is_normalized_before_length_check(): void {
		$service = $this->make_service( self::NOW );

		$message = str_repeat( "line\r\n", 100 ); // 600 raw bytes, but each \r\n counts as one char once normalized.
		$result  = $service->set_message( $message );

		$this->assertSame( 'message_saved', $result );
	}

	public function test_message_too_long_is_rejected(): void {
		$service = $this->make_service( self::NOW );

		$result = $service->set_message( str_repeat( 'a', 501 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'message_too_long', $result->get_error_code() );
	}

	public function test_enable_records_history(): void {
		$service = $this->make_service( self::NOW );
		$service->enable();

		$entries = $service->entries();

		$this->assertSame( 'enabled', $entries[0]['type'] );
		$this->assertSame( self::NOW, $entries[0]['t'] );
	}

	public function test_state_changed_action_fires_with_old_and_new_state(): void {
		$service = $this->make_service( self::NOW );

		$seen = array();
		add_action(
			'deployment_guard_state_changed',
			static function ( $new, $old, $source ) use ( &$seen ) {
				$seen[] = array( $new, $old, $source );
			},
			10,
			3
		);

		$service->enable();

		$this->assertCount( 1, $seen );
		$this->assertTrue( $seen[0][0]['enabled'] );
		$this->assertFalse( $seen[0][1]['enabled'] );
		$this->assertSame( 'ui', $seen[0][2] );
	}
	public function test_freeze_for_starts_a_freeze_that_ends_by_itself(): void {
		$service = $this->make_service( self::NOW );

		$this->assertSame( 'timed', $service->freeze_for( 1800 ) );
		$this->assertTrue( $service->is_active() );
		$this->assertSame( self::NOW + 1800, $service->ends_at() );
		$this->assertFalse( $service->config()->enabled );

		// The same stored state, read a moment after the window closes.
		$later = $this->make_service( self::NOW + 1800 );
		$this->assertFalse( $later->is_active() );
		$this->assertSame( self::NOW + 1800, $this->make_service( self::NOW + 1799 )->ends_at(), 'still running one second before the end' );
	}

	public function test_freeze_for_replaces_a_manual_freeze_and_an_existing_schedule(): void {
		$service = $this->make_service( self::NOW );
		$service->enable();
		$service->schedule( self::NOW + 5000, self::NOW + 6000 );

		$this->assertSame( 'timed', $service->freeze_for( 600 ) );

		$config = $service->config();
		$this->assertFalse( $config->enabled, 'the manual switch is replaced by the timer' );
		$this->assertSame( self::NOW, $config->schedule_start );
		$this->assertSame( self::NOW + 600, $config->schedule_end );
	}

	public function test_freeze_for_logs_a_schedule_entry_and_fires_the_state_changed_action(): void {
		$service = $this->make_service( self::NOW );
		$seen    = array();
		add_action(
			'deployment_guard_state_changed',
			static function ( $new, $old, $source ) use ( &$seen ) {
				$seen[] = array( $new['enabled'], $source );
			},
			10,
			3
		);

		$service->freeze_for( 600, 'rest' );

		$this->assertSame( array( array( false, 'rest' ) ), $seen );
		$entries = $service->entries();
		$this->assertSame( 'schedule_set', $entries[0]['type'] );
		$this->assertSame( 'rest', $entries[0]['ctx']['source'] );
	}

	/**
	 * @dataProvider provide_bad_timer_durations
	 */
	public function test_freeze_for_rejects_bad_durations( int $seconds, string $expected_code ): void {
		$service = $this->make_service( self::NOW );

		$result = $service->freeze_for( $seconds );

		$this->assertWPError( $result );
		$this->assertSame( $expected_code, $result->get_error_code() );
		$this->assertFalse( $service->is_active() );
	}

	public static function provide_bad_timer_durations(): array {
		return array(
			'shorter than a minute' => array( 59, 'duration_too_short' ),
			'zero'                  => array( 0, 'duration_too_short' ),
			'negative'              => array( -5, 'duration_too_short' ),
			'longer than a window'  => array( 7 * DAY_IN_SECONDS + 1, 'window_too_long' ),
		);
	}

	public function test_freeze_for_accepts_exactly_the_longest_window(): void {
		$service = $this->make_service( self::NOW );

		$this->assertSame( 'timed', $service->freeze_for( 7 * DAY_IN_SECONDS ) );
	}

	public function test_extend_pushes_the_end_of_a_running_window_back(): void {
		$service = $this->make_service( self::NOW );
		$service->freeze_for( 600 );

		$this->assertSame( 'extended', $service->extend( 900 ) );
		$this->assertSame( self::NOW + 1500, $service->ends_at() );
		$this->assertSame( self::NOW, $service->config()->schedule_start );
	}

	public function test_extend_works_on_a_scheduled_window_that_is_running(): void {
		$service = $this->make_service( self::NOW );
		$service->schedule( self::NOW - 100, self::NOW + 100 );

		$this->assertSame( 'extended', $service->extend( 3600 ) );
		$this->assertSame( self::NOW + 3700, $service->ends_at() );
	}

	/**
	 * @dataProvider provide_states_that_cannot_be_extended
	 */
	public function test_extend_refuses_when_there_is_no_running_window( string $state ): void {
		$service = $this->make_service( self::NOW );

		if ( 'manual' === $state ) {
			$service->enable();
		} elseif ( 'upcoming' === $state ) {
			$service->schedule( self::NOW + 1000, self::NOW + 2000 );
		} elseif ( 'expired' === $state ) {
			$service->schedule( self::NOW - 2000, self::NOW + 10 );
			$service = $this->make_service( self::NOW + 500 );
		}

		$result = $service->extend( 600 );

		$this->assertWPError( $result );
		$this->assertSame( 'not_extendable', $result->get_error_code() );
	}

	public static function provide_states_that_cannot_be_extended(): array {
		return array(
			'nothing configured' => array( 'none' ),
			'manual freeze'      => array( 'manual' ),
			'upcoming window'    => array( 'upcoming' ),
			'expired window'     => array( 'expired' ),
		);
	}

	public function test_extend_cannot_stretch_a_window_past_the_maximum(): void {
		$service = $this->make_service( self::NOW );
		$service->freeze_for( 6 * DAY_IN_SECONDS );

		$result = $service->extend( 2 * DAY_IN_SECONDS );

		$this->assertWPError( $result );
		$this->assertSame( 'window_too_long', $result->get_error_code() );
		$this->assertSame( self::NOW + 6 * DAY_IN_SECONDS, $service->ends_at(), 'the window is unchanged after a refused extension' );
	}

	public function test_extend_rejects_a_duration_shorter_than_a_minute(): void {
		$service = $this->make_service( self::NOW );
		$service->freeze_for( 600 );

		$result = $service->extend( 30 );

		$this->assertWPError( $result );
		$this->assertSame( 'duration_too_short', $result->get_error_code() );
	}
	public function test_scope_defaults_to_editorial_only(): void {
		$service = $this->make_service( self::NOW );

		$this->assertTrue( $service->scope()->is_empty() );
	}

	public function test_set_scope_saves_logs_and_is_visible_to_another_instance(): void {
		$service = $this->make_service( self::NOW );

		$this->assertSame( 'scope_saved', $service->set_scope( new \Digibold\DeploymentGuard\Scope( true, false ) ) );
		$this->assertTrue( $service->scope()->comments );
		$this->assertFalse( $service->scope()->forms );

		$other = $this->make_service( self::NOW );
		$this->assertTrue( $other->scope()->comments, 'a second instance reads the stored value' );

		$entry = $service->entries()[0];
		$this->assertSame( 'scope_changed', $entry['type'] );
		$this->assertSame( 'comments', $entry['ctx']['target'] );
	}

	public function test_set_scope_with_nothing_changed_is_unchanged_and_not_logged(): void {
		$service = $this->make_service( self::NOW );
		$service->set_scope( new \Digibold\DeploymentGuard\Scope( true, true ) );
		$count = count( $service->entries() );

		$this->assertSame( 'unchanged', $service->set_scope( new \Digibold\DeploymentGuard\Scope( true, true ) ) );
		$this->assertCount( $count, $service->entries() );
	}

	public function test_scope_survives_a_freeze_ending_and_is_not_autoloaded(): void {
		$service = $this->make_service( self::NOW );
		$service->set_scope( new \Digibold\DeploymentGuard\Scope( false, true ) );
		$service->enable();
		$service->disable();

		$this->assertTrue( $service->scope()->forms );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'deployment_guard_scope' ) );
		$this->assertContains( $autoload, array( 'off', 'no' ) );
	}

	public function test_scope_can_be_switched_back_to_editorial_only(): void {
		$service = $this->make_service( self::NOW );
		$service->set_scope( new \Digibold\DeploymentGuard\Scope( true, true ) );
		$service->set_scope( new \Digibold\DeploymentGuard\Scope() );

		$this->assertTrue( $service->scope()->is_empty() );
		$this->assertSame( 'editorial only', $service->entries()[0]['ctx']['target'] );
	}
}
