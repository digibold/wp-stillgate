<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Installer;

final class InstallerTest extends TestCase {

	public function test_bypass_capability_granted_only_to_manage_options_roles(): void {
		$this->assertTrue( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Installer::CAPABILITY ) );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( Installer::CAPABILITY ) );
	}

	public function test_install_is_idempotent(): void {
		Installer::install_site();
		Installer::install_site();

		$this->assertTrue( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );
	}

	public function test_removed_capability_is_not_regranted(): void {
		get_role( 'administrator' )->remove_cap( Installer::CAPABILITY );

		Installer::install_site();

		$this->assertFalse( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );
	}

	public function test_options_are_not_autoloaded(): void {
		global $wpdb;

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM $wpdb->options WHERE option_name = %s",
				FreezeService::OPTION_STATE
			)
		);

		$this->assertNotNull( $autoload );
		$this->assertNotEquals( 'yes', $autoload );

		$alloptions = wp_load_alloptions();
		$this->assertArrayNotHasKey( FreezeService::OPTION_STATE, $alloptions );
		$this->assertArrayNotHasKey( FreezeService::OPTION_HISTORY, $alloptions );
	}

	public function test_uninstall_removes_options_and_capability(): void {
		Installer::uninstall();

		$this->assertFalse( get_option( FreezeService::OPTION_STATE ) );
		$this->assertFalse( get_option( FreezeService::OPTION_HISTORY ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Installer::CAPABILITY ) );
	}
}
