<?php
/**
 * Activation, lazy per-site install, and uninstall.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that happens when the plugin is turned on, first used on a
 * given site, or removed entirely.
 *
 * All of this operates per site by design (see the multisite note in
 * install_site()). On a Multisite network activation, only the site the
 * activation request targets is installed immediately; every other site
 * installs itself lazily, the first time an administrator visits wp-admin
 * or WP-CLI writes to it (see maybe_install()).
 */
final class Installer {

	public const CAPABILITY = 'deployment_guard_bypass';

	/**
	 * Number of sites processed per `get_sites()` page while uninstalling
	 * a Multisite network, so a very large network cannot exhaust memory
	 * or run past a web request's time limit in one query.
	 */
	private const UNINSTALL_SITES_PER_PAGE = 100;

	/**
	 * Fired on activation. Installs the current site only, even when
	 * WordPress reports a network-wide activation: see install_site().
	 */
	public static function activate( $network_wide = false ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- part of the activate_{plugin} hook signature; deliberately ignored, see the docblock above.
		self::install_site();
	}

	/**
	 * Install the current site if it has not been installed yet. Cheap to
	 * call often: it is called on every `admin_init` and before every
	 * FreezeService write, which is what makes a Multisite network's
	 * other sites install themselves lazily on first use.
	 */
	public static function maybe_install(): void {
		if ( false !== get_option( FreezeService::OPTION_STATE, false ) ) {
			return;
		}

		self::install_site();
	}

	/**
	 * Install the current site: grant the bypass capability to every role
	 * that already has `manage_options` (never only to "administrator" by
	 * name, so a custom role with the same responsibility is covered),
	 * and create the plugin's two options if they do not exist yet.
	 *
	 * Idempotent, and safe to call after an administrator has
	 * deliberately removed the capability from a role: it only grants the
	 * capability the first time this site is installed, keyed on whether
	 * the state option already exists.
	 */
	public static function install_site(): void {
		if ( false !== get_option( FreezeService::OPTION_STATE, false ) ) {
			return;
		}

		self::grant_capability_to_privileged_roles();

		add_option( FreezeService::OPTION_STATE, FreezeConfig::defaults()->to_array(), '', false );
		add_option( FreezeService::OPTION_HISTORY, array(), '', false );
	}

	/**
	 * Remove all trace of the plugin from the database: both options and
	 * the bypass capability, on every site on a Multisite network.
	 */
	public static function uninstall(): void {
		if ( is_multisite() ) {
			self::uninstall_network();
			return;
		}

		self::uninstall_current_site();
	}

	private static function uninstall_network(): void {
		$offset = 0;

		do {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => self::UNINSTALL_SITES_PER_PAGE,
					'offset' => $offset,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::uninstall_current_site();
				restore_current_blog();
			}

			$offset    += self::UNINSTALL_SITES_PER_PAGE;
			$page_count = count( $site_ids );
		} while ( self::UNINSTALL_SITES_PER_PAGE === $page_count );
	}

	private static function uninstall_current_site(): void {
		delete_option( FreezeService::OPTION_STATE );
		delete_option( FreezeService::OPTION_HISTORY );
		delete_option( FreezeService::OPTION_SCOPE );
		delete_option( Receipt::OPTION );

		self::revoke_capability_from_all_roles();
	}

	private static function grant_capability_to_privileged_roles(): void {
		$roles = wp_roles();

		foreach ( $roles->roles as $role_name => $role_data ) {
			if ( empty( $role_data['capabilities']['manage_options'] ) ) {
				continue;
			}

			$role = get_role( $role_name );

			if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
				$role->add_cap( self::CAPABILITY );
			}
		}
	}

	private static function revoke_capability_from_all_roles(): void {
		$roles = wp_roles();

		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );

			if ( $role && $role->has_cap( self::CAPABILITY ) ) {
				$role->remove_cap( self::CAPABILITY );
			}
		}
	}
}
