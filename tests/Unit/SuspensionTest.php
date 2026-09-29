<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Unit;

use Digibold\DeploymentGuard\Suspension;
use PHPUnit\Framework\TestCase;

final class SuspensionTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wp_current_filter'] );

		parent::tearDown();
	}

	public function test_not_active_inside_a_hook_dispatched_while_suspended(): void {
		$suspension = new Suspension();
		$observed   = null;

		$suspension->run(
			function () use ( $suspension, &$observed ) {
				// What do_action()/apply_filters() do around every callback.
				$GLOBALS['wp_current_filter'][] = 'updated_option';

				try {
					$observed = $suspension->active();
				} finally {
					array_pop( $GLOBALS['wp_current_filter'] );
				}

				$this->assertTrue( $suspension->active() );
			}
		);

		$this->assertFalse( $observed );
		$this->assertFalse( $suspension->active() );
	}

	public function test_run_entered_inside_a_hook_is_active_at_that_depth_only(): void {
		$suspension                   = new Suspension();
		$GLOBALS['wp_current_filter'] = array( 'outer_hook' );

		$suspension->run(
			function () use ( $suspension ) {
				$this->assertTrue( $suspension->active() );

				$GLOBALS['wp_current_filter'][] = 'inner_hook';
				$this->assertFalse( $suspension->active() );
				array_pop( $GLOBALS['wp_current_filter'] );

				$this->assertTrue( $suspension->active() );
			}
		);

		$this->assertFalse( $suspension->active() );
	}

	public function test_inactive_by_default(): void {
		$suspension = new Suspension();

		$this->assertFalse( $suspension->active() );
	}

	public function test_active_while_running_and_returns_value(): void {
		$suspension = new Suspension();
		$observed   = null;

		$result = $suspension->run(
			function () use ( $suspension, &$observed ) {
				$observed = $suspension->active();
				return 'value';
			}
		);

		$this->assertTrue( $observed );
		$this->assertSame( 'value', $result );
		$this->assertFalse( $suspension->active() );
	}

	public function test_restored_when_closure_throws(): void {
		$suspension = new Suspension();

		try {
			$suspension->run(
				static function (): void {
					throw new \RuntimeException( 'boom' );
				}
			);
			$this->fail( 'Expected exception was not thrown.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'boom', $exception->getMessage() );
		}

		$this->assertFalse( $suspension->active() );
	}

	public function test_nested_run_only_closes_on_outermost_return(): void {
		$suspension = new Suspension();
		$inner_seen = null;

		$suspension->run(
			function () use ( $suspension, &$inner_seen ) {
				$suspension->run(
					function () use ( $suspension, &$inner_seen ) {
						$inner_seen = $suspension->active();
					}
				);

				// Still active after the inner call returns.
				$this->assertTrue( $suspension->active() );
			}
		);

		$this->assertTrue( $inner_seen );
		$this->assertFalse( $suspension->active() );
	}

	public function test_outside_reports_inactive_during_call(): void {
		$suspension = new Suspension();
		$observed   = null;

		$suspension->run(
			function () use ( $suspension, &$observed ) {
				$suspension->outside(
					function () use ( $suspension, &$observed ) {
						$observed = $suspension->active();
					}
				);

				// Restored after outside() returns, still inside run().
				$this->assertTrue( $suspension->active() );
			}
		);

		$this->assertFalse( $observed );
		$this->assertFalse( $suspension->active() );
	}

	public function test_outside_restores_state_even_when_closure_throws(): void {
		$suspension = new Suspension();

		$suspension->run(
			function () use ( $suspension ) {
				try {
					$suspension->outside(
						static function (): void {
							throw new \RuntimeException( 'boom' );
						}
					);
				} catch ( \RuntimeException $exception ) {
					// Expected; fall through to the assertion below.
				}

				$this->assertTrue( $suspension->active() );
			}
		);

		$this->assertFalse( $suspension->active() );
	}

	public function test_outside_is_a_no_op_when_not_suspended(): void {
		$suspension = new Suspension();
		$observed   = $suspension->outside( static fn () => 'ran' );

		$this->assertSame( 'ran', $observed );
		$this->assertFalse( $suspension->active() );
	}
}
