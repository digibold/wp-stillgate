<?php
/**
 * Shared base class for integration tests.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Guard;
use Digibold\DeploymentGuard\Installer;
use Digibold\DeploymentGuard\Suspension;

/**
 * Every integration test gets a freshly "installed" site (the bypass
 * capability granted to privileged roles, both options created) at the
 * start of each test method, mirroring a real site where the plugin has
 * already been active for a while; the core test suite's own per-test
 * database transaction rollback undoes it afterwards.
 */
abstract class TestCase extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		Installer::install_site();
	}

	protected function clock( int $now ): \Closure {
		return static fn (): int => $now;
	}

	protected function make_service( int $now = 0, ?Suspension $suspension = null ): FreezeService {
		if ( 0 === $now ) {
			$now = time();
		}

		return new FreezeService( $this->clock( $now ), $suspension ?? new Suspension() );
	}

	protected function make_guard( FreezeService $service, bool $is_cli = false, ?Suspension $suspension = null ): Guard {
		return new Guard( $service, $suspension ?? new Suspension(), $is_cli );
	}

	/**
	 * Create and log in as a user of the given role, returning their ID.
	 */
	protected function login_as( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}
}
