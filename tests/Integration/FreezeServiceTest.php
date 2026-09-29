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
}
