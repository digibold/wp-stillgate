<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Admin\SiteHealth;
use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Installer;

final class SiteHealthTest extends TestCase {

	private FreezeService $service;

	public function set_up(): void {
		parent::set_up();

		$this->service = $this->make_service( 0 );
	}

	public function test_the_test_is_added_as_a_direct_test(): void {
		$tests = ( new SiteHealth( $this->service ) )->add_test( array( 'direct' => array(), 'async' => array() ) );

		$this->assertArrayHasKey( 'stillgate', $tests['direct'] );
		$this->assertIsCallable( $tests['direct']['stillgate']['test'] );
		$this->assertSame( array(), $tests['async'] );
	}

	public function test_a_filter_value_that_is_not_an_array_is_returned_untouched(): void {
		$this->assertSame( 'x', ( new SiteHealth( $this->service ) )->add_test( 'x' ) );
	}

	public function test_all_is_well_with_no_freeze(): void {
		$result = ( new SiteHealth( $this->service ) )->run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'stillgate', $result['test'] );
		$this->assertStringContainsString( 'No editorial freeze', $result['label'] );
	}

	public function test_a_running_timed_freeze_is_simply_reported(): void {
		$this->service->freeze_for( 1800 );

		$result = ( new SiteHealth( $this->service ) )->run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'is active', $result['label'] );
		$this->assertStringContainsString( 'tools.php?page=stillgate', $result['actions'] );
	}

	public function test_a_fresh_manual_freeze_is_expected_and_fine(): void {
		$this->service->enable();

		$this->assertSame( 'good', ( new SiteHealth( $this->service ) )->run_test()['status'] );
	}

	public function test_a_manual_freeze_left_on_for_hours_is_flagged(): void {
		$old     = time() - 9 * HOUR_IN_SECONDS;
		$service = new FreezeService( static fn (): int => $old, new \Digibold\DeploymentGuard\Suspension() );
		$service->enable();

		$result = ( new SiteHealth( $this->service ) )->run_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'long time', $result['label'] );
		$this->assertStringContainsString( '9 hours', $result['description'] );
		$this->assertStringContainsString( 'timer', $result['description'] );
	}

	public function test_a_timed_freeze_is_never_called_forgotten(): void {
		$old     = time() - 9 * HOUR_IN_SECONDS;
		$service = new FreezeService( static fn (): int => $old, new \Digibold\DeploymentGuard\Suspension() );
		$service->freeze_for( 3 * DAY_IN_SECONDS );

		// The window has an end, so it is not forgotten however long it runs.
		$result = ( new SiteHealth( $this->service ) )->run_test();
		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'is active', $result['label'] );
	}

	public function test_nobody_being_able_to_end_a_freeze_is_flagged(): void {
		foreach ( wp_roles()->roles as $role_name => $data ) {
			$role = get_role( $role_name );

			if ( $role && $role->has_cap( Installer::CAPABILITY ) ) {
				$role->remove_cap( Installer::CAPABILITY );
				$removed[] = $role_name;
			}
		}

		$result = ( new SiteHealth( $this->service ) )->run_test();

		foreach ( $removed ?? array() as $role_name ) {
			get_role( $role_name )->add_cap( Installer::CAPABILITY );
		}

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'Nobody can end a freeze', $result['label'] );
	}
}
