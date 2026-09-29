<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

/**
 * DEPLOYMENT_GUARD_FORCE is a real PHP constant: once defined, it cannot
 * be undefined for the rest of the process. Each test here runs in its
 * own process so defining it does not leak into any other test file.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ForcedOverrideTest extends TestCase {

	private const NOW = 1_800_000_000;

	public function test_forced_true_overrides_inactive_state(): void {
		define( 'DEPLOYMENT_GUARD_FORCE', true );

		$service = $this->make_service( self::NOW );

		$this->assertTrue( $service->is_active() );
		$this->assertTrue( $service->forced() );
		$this->assertNull( $service->ends_at() );
	}

	public function test_forced_false_overrides_active_state(): void {
		define( 'DEPLOYMENT_GUARD_FORCE', false );

		$service = $this->make_service( self::NOW );
		$service->enable();

		$this->assertFalse( $service->is_active() );
		$this->assertFalse( $service->forced() );
	}

	public function test_non_boolean_force_is_treated_as_unset(): void {
		define( 'DEPLOYMENT_GUARD_FORCE', 'yes' );

		$service = $this->make_service( self::NOW );

		$this->assertNull( $service->forced() );
		$this->assertFalse( $service->is_active() );
	}
}
