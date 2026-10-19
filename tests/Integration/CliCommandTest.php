<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Cli\Command;
use Digibold\DeploymentGuard\Enforcement\CapabilityFilter;
use Digibold\DeploymentGuard\Enforcement\DataLayerGuard;
use Digibold\DeploymentGuard\Suspension;
use Digibold\DeploymentGuard\Tests\Support\CliExit;

/**
 * Command::do_set_schedule() parses --start/--end against the real wall
 * clock (a CLI user types real-world dates), so — like
 * ScheduledPublishTest — these tests use the real current time rather
 * than an injected fixed clock, to stay consistent with what the command
 * itself will observe.
 */
final class CliCommandTest extends TestCase {

	/**
	 * @return array{0: Command, 1: \Digibold\DeploymentGuard\FreezeService}
	 */
	private function install(): array {
		$suspension = new Suspension();
		$service    = $this->make_service( 0, $suspension );
		$guard      = $this->make_guard( $service, true, $suspension );
		( new CapabilityFilter( $guard ) )->register();
		( new DataLayerGuard( $guard ) )->register();

		\WP_CLI::reset_log();
		$GLOBALS['dg_test_formatted_items'] = array();

		return array( new Command( $service, $guard, $suspension ), $service );
	}

	public function test_status_reports_as_json(): void {
		list( $command, $service ) = $this->install();
		$service->enable();

		$command->status( array(), array( 'format' => 'json' ) );

		$printed = \WP_CLI::messages_of( 'print_value' );
		$this->assertNotEmpty( $printed );
		$this->assertSame( 'json', $printed[0][1]['format'] );
		$this->assertTrue( $printed[0][0]['active'] === 'true' );
	}

	public function test_status_table_format_lists_every_field(): void {
		list( $command ) = $this->install();

		$command->status( array(), array() );

		$this->assertNotEmpty( $GLOBALS['dg_test_formatted_items'] );
		$fields = array_column( $GLOBALS['dg_test_formatted_items'][0][1], 'Field' );

		foreach ( array( 'active', 'mode', 'schedule_status', 'timezone', 'protected_post_types', 'roles_with_bypass' ) as $expected ) {
			$this->assertContains( $expected, $fields );
		}
	}

	public function test_enable_disable_schedule_all_succeed_while_active(): void {
		list( $command, $service ) = $this->install();

		$command->enable( array(), array() );
		$this->assertTrue( $service->is_active() );

		$command->enable( array(), array( 'message' => 'Deploying now.' ) );
		$this->assertSame( 'Deploying now.', $service->config()->message );

		$command->disable( array(), array() );
		$this->assertFalse( $service->is_active() );

		$now = time();
		$command->schedule(
			array(),
			array(
				'start' => 'now',
				'end'   => gmdate( 'c', $now + 3600 ),
			)
		);
		$this->assertTrue( $service->is_active() );

		$command->schedule( array(), array( 'clear' => true ) );
		$this->assertNull( $service->config()->schedule_start );

		foreach ( \WP_CLI::$log as $entry ) {
			$this->assertNotSame( 'error', $entry[0] );
		}
	}

	public function test_suspension_is_inactive_after_every_command_including_failures(): void {
		list( $command ) = $this->install();
		$property = new \ReflectionProperty( $command, 'suspension' );
		$property->setAccessible( true );
		/** @var Suspension $suspension */
		$suspension = $property->getValue( $command );

		$command->enable( array(), array() );
		$this->assertFalse( $suspension->active() );

		try {
			$command->schedule( array(), array( 'start' => 'garbage', 'end' => 'garbage' ) );
		} catch ( CliExit $exception ) {
			// schedule() calls WP_CLI::error() on bad input, which throws.
		}

		$this->assertFalse( $suspension->active() );
	}

	public function test_state_changed_listener_writing_during_enable_is_still_blocked(): void {
		list( $command ) = $this->install();

		$post_id = self::factory()->post->create( array( 'post_title' => 'Original' ) );

		// A hypothetical third-party plugin reacting to the freeze turning
		// on — this must NOT inherit the suspension.
		add_action(
			'deployment_guard_state_changed',
			static function () use ( $post_id ) {
				try {
					wp_update_post( array( 'ID' => $post_id, 'post_title' => 'From listener' ), true );
				} catch ( CliExit $exception ) {
					// Expected: the listener's own write is blocked.
				}
			}
		);

		$command->enable( array(), array() );

		$this->assertSame( 'Original', get_post( $post_id )->post_title );
	}

	public function test_option_update_listener_during_a_command_is_still_blocked(): void {
		list( $command, $service ) = $this->install();

		$post_id = self::factory()->post->create( array( 'post_title' => 'Original' ) );
		$service->enable();

		// A hypothetical third-party plugin reacting to option writes in
		// general (a cache or audit plugin, say): the freeze option being
		// saved by `wp stillgate enable --message` must not hand it
		// the command's own suspension.
		add_action(
			'updated_option',
			static function ( $option ) use ( $post_id ) {
				if ( \Digibold\DeploymentGuard\FreezeService::OPTION_STATE !== $option ) {
					return;
				}

				try {
					wp_update_post( array( 'ID' => $post_id, 'post_title' => 'From listener' ), true );
				} catch ( CliExit $exception ) {
					// Expected: the listener's own write is blocked.
				}
			}
		);

		$command->enable( array(), array( 'message' => 'Deploying now.' ) );

		$this->assertSame( 'Deploying now.', $service->config()->message );
		$this->assertSame( 'Original', get_post( $post_id )->post_title );
	}

	public function test_any_hook_dispatched_inside_the_suspension_is_enforced_normally(): void {
		list( $command, $service ) = $this->install();

		$property = new \ReflectionProperty( $command, 'suspension' );
		$property->setAccessible( true );
		/** @var Suspension $suspension */
		$suspension = $property->getValue( $command );

		$post_id = self::factory()->post->create( array( 'post_title' => 'Original' ) );
		$service->enable();

		add_action(
			'dg_test_hook_inside_suspension',
			static function () use ( $post_id ) {
				try {
					wp_update_post( array( 'ID' => $post_id, 'post_title' => 'From hook' ), true );
				} catch ( CliExit $exception ) {
					// Expected: blocked.
				}
			}
		);

		$suspension->run(
			static function (): void {
				do_action( 'dg_test_hook_inside_suspension' );
			}
		);

		$this->assertSame( 'Original', get_post( $post_id )->post_title );
	}

	public function test_missing_end_is_a_usage_error(): void {
		list( $command ) = $this->install();

		$this->expectException( CliExit::class );
		$command->schedule( array(), array( 'start' => 'now' ) );
	}

	public function test_clear_combined_with_start_is_a_usage_error(): void {
		list( $command ) = $this->install();

		$this->expectException( CliExit::class );
		$command->schedule(
			array(),
			array(
				'clear' => true,
				'start' => 'now',
			)
		);
	}

	public function test_invalid_datetime_is_a_usage_error(): void {
		list( $command ) = $this->install();

		$this->expectException( CliExit::class );
		$command->schedule(
			array(),
			array(
				'start' => 'tomorrow',
				'end'   => 'now',
			)
		);
	}

	public function test_zulu_and_offset_datetimes_are_accepted(): void {
		list( $command, $service ) = $this->install();

		$now = time();
		$command->schedule(
			array(),
			array(
				'start' => gmdate( 'Y-m-d\TH:i:s\Z', $now + 100 ),
				'end'   => gmdate( 'Y-m-d\TH:i:sP', $now + 3700 ),
			)
		);

		$this->assertSame( $now + 100, $service->config()->schedule_start );
		$this->assertSame( $now + 3700, $service->config()->schedule_end );
	}

	public function test_object_cache_warning_is_emitted_when_in_use(): void {
		list( $command ) = $this->install();

		wp_using_ext_object_cache( true );
		$command->enable( array(), array() );
		wp_using_ext_object_cache( false );

		$this->assertNotEmpty( \WP_CLI::messages_of( 'warning' ) );
	}
	public function test_enable_for_starts_a_timed_freeze(): void {
		list( $command, $service ) = $this->install();

		$command->enable( array(), array( 'for' => '30m' ) );

		$this->assertTrue( $service->is_active() );
		$this->assertFalse( $service->config()->enabled );
		$ends_at = $service->ends_at();
		$this->assertNotNull( $ends_at );
		$this->assertEqualsWithDelta( time() + 1800, $ends_at, 5 );
	}

	public function test_enable_for_with_a_message(): void {
		list( $command, $service ) = $this->install();

		$command->enable( array(), array( 'for' => '2h', 'message' => 'Deploying.' ) );

		$this->assertSame( 'Deploying.', $service->config()->message );
		$this->assertEqualsWithDelta( time() + 7200, $service->ends_at(), 5 );
	}

	public function test_enable_for_with_an_invalid_duration_is_a_usage_error(): void {
		list( $command, $service ) = $this->install();

		try {
			$command->enable( array(), array( 'for' => 'soon' ) );
			$this->fail( 'Expected a CLI error.' );
		} catch ( CliExit $exit ) {
			$this->assertSame( 1, $exit->getCode() );
			$this->assertStringContainsString( 'Invalid --for', $exit->getMessage() );
		}

		$this->assertFalse( $service->is_active() );
	}

	public function test_enable_for_beyond_the_maximum_is_refused(): void {
		list( $command, $service ) = $this->install();

		try {
			$command->enable( array(), array( 'for' => '8d' ) );
			$this->fail( 'Expected a CLI error.' );
		} catch ( CliExit $exit ) {
			$this->assertSame( 1, $exit->getCode() );
		}

		$this->assertFalse( $service->is_active() );
	}

	public function test_extend_pushes_the_end_back(): void {
		list( $command, $service ) = $this->install();
		$command->enable( array(), array( 'for' => '10m' ) );
		$before = $service->ends_at();

		$command->extend( array( '15m' ), array() );

		$this->assertSame( $before + 900, $service->ends_at() );
	}

	public function test_extend_without_a_running_window_is_an_error(): void {
		list( $command, $service ) = $this->install();
		$service->enable();

		try {
			$command->extend( array( '15m' ), array() );
			$this->fail( 'Expected a CLI error.' );
		} catch ( CliExit $exit ) {
			$this->assertSame( 1, $exit->getCode() );
			$this->assertStringContainsString( 'no running timed or scheduled freeze', $exit->getMessage() );
		}
	}

	public function test_extend_with_a_missing_or_bad_duration_is_a_usage_error(): void {
		list( $command ) = $this->install();

		foreach ( array( array(), array( 'later' ) ) as $args ) {
			try {
				$command->extend( $args, array() );
				$this->fail( 'Expected a CLI error.' );
			} catch ( CliExit $exit ) {
				$this->assertStringContainsString( 'Invalid duration', $exit->getMessage() );
			}
		}
	}

	public function test_status_reports_when_the_freeze_ends(): void {
		list( $command, $service ) = $this->install();
		$service->freeze_for( 600 );

		$command->status( array(), array( 'format' => 'json' ) );

		$printed = \WP_CLI::messages_of( 'print_value' );
		$this->assertNotSame( '', $printed[0][0]['ends_at_utc'] );
		$this->assertNotFalse( strtotime( $printed[0][0]['ends_at_utc'] ) );
	}
}
