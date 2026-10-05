<?php
/**
 * Coexistence with, and safe removal of, the earlier "Deployment Guard" copy.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Releases up to 0.1.0 were published as "Deployment Guard" (main file
 * `deployment-guard.php`). Stillgate deliberately keeps that release's
 * namespace, options, capability and hooks, so both copies read and write
 * one shared set of stored state. Two things follow from that, and this
 * class handles both:
 *
 *  1. Two copies must never run at once. The main file stays passive when
 *     another copy's classes are already loaded, and `Plugin::register()`
 *     ignores a main file that is not this copy's own; both leave a notice on
 *     the Plugins screen explaining how to switch over.
 *
 *  2. The earlier release's `uninstall.php` deletes the shared options and
 *     removes the bypass capability from every role. WordPress runs it when
 *     the earlier copy is deleted, which is a normal step after switching to
 *     Stillgate. While Stillgate is running, it therefore remembers that
 *     state just before the earlier copy is uninstalled and puts back
 *     whatever the uninstall removed, so the freeze configuration, the
 *     activity log and every role's bypass grant survive the switch.
 *
 * This class deliberately depends on no other class of the plugin except
 * inside the snapshot and restore methods, which only ever run once this
 * copy has registered normally: it is also used while another copy's
 * classes are loaded, and must not mix versions.
 */
final class LegacyCopy {

	/**
	 * The main file name every release up to 0.1.0 shipped under.
	 */
	private const LEGACY_FILE = 'deployment-guard.php';

	/**
	 * Number of sites processed per `get_sites()` page on a Multisite
	 * network, as in Installer.
	 */
	private const SITES_PER_PAGE = 100;

	private bool $known_duplicate;

	/**
	 * State captured before the earlier copy's uninstall, keyed by blog ID.
	 *
	 * @var array<int, array{state: mixed, history: mixed, roles: string[]}>|null
	 */
	private ?array $remembered = null;

	/**
	 * @param bool $known_duplicate True when another copy's classes are
	 *                              already loaded, so this one stays passive.
	 */
	public function __construct( bool $known_duplicate = false ) {
		$this->known_duplicate = $known_duplicate;
	}

	/**
	 * Whether a plugin basename is the earlier "Deployment Guard" release,
	 * wherever its folder is called (a GitHub source archive unpacks to a
	 * differently named folder than the release ZIP).
	 */
	public static function is_legacy_basename( string $plugin_file ): bool {
		return self::LEGACY_FILE === $plugin_file || str_ends_with( $plugin_file, '/' . self::LEGACY_FILE );
	}

	/**
	 * Registration for a copy that is running normally.
	 */
	public function register(): void {
		$this->register_notice();

		add_action( 'pre_uninstall_plugin', array( $this, 'remember_before_uninstall' ) );
		// `deleted_plugin` covers deletion from the Plugins screen and
		// `delete_plugins()`; `shutdown` covers a bare `uninstall_plugin()`
		// call, which has no hook after it (for example
		// `wp plugin uninstall`). Restoring is idempotent.
		add_action( 'deleted_plugin', array( $this, 'restore_after_uninstall' ) );
		add_action( 'shutdown', array( $this, 'restore_after_uninstall' ) );
	}

	/**
	 * Registration for a copy that stays passive because another copy is
	 * already loaded: only the explanatory notice.
	 */
	public function register_notice(): void {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * A notice on the Plugins screen, for people who can manage plugins,
	 * while two copies are active. It disappears as soon as one is
	 * deactivated.
	 */
	public function render_notice(): void {
		if ( ! $this->known_duplicate && ! $this->legacy_is_active() ) {
			return;
		}

		if ( ! current_user_can( is_network_admin() ? 'manage_network_plugins' : 'activate_plugins' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->base, array( 'plugins', 'plugins-network' ), true ) ) {
			return;
		}

		if ( $this->known_duplicate ) {
			$first = esc_html__( 'Stillgate has not started because another copy of this plugin is already active: the earlier release published as Deployment Guard, or a second Stillgate folder. Both would use the same stored settings, so only one can run at a time.', 'stillgate' );
		} else {
			$first = esc_html__( 'The earlier release of this plugin, published as Deployment Guard, is still active alongside Stillgate. Both use the same stored settings, so only Stillgate is running.', 'stillgate' );
		}

		$second = esc_html__( 'Deactivate the other copy on this screen. Stillgate then continues with the same settings. Deactivating Deployment Guard ends a freeze that is currently active.', 'stillgate' );

		wp_admin_notice(
			'<p>' . $first . '</p><p>' . $second . '</p>',
			array(
				'type'           => 'warning',
				'paragraph_wrap' => false,
			)
		);
	}

	/**
	 * Whether the earlier release is active on this site (or network-wide).
	 */
	public function legacy_is_active(): bool {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( $active as $plugin_file ) {
			if ( is_string( $plugin_file ) && self::is_legacy_basename( $plugin_file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Hooked to `pre_uninstall_plugin`: when the earlier release is about to
	 * be uninstalled, remember the state its `uninstall.php` is about to
	 * remove, on every site it will touch.
	 *
	 * @param mixed $plugin_file The basename being uninstalled.
	 */
	public function remember_before_uninstall( $plugin_file ): void {
		if ( ! is_string( $plugin_file ) || ! self::is_legacy_basename( $plugin_file ) ) {
			return;
		}

		$this->remembered = array();

		$this->for_each_site(
			function ( int $blog_id ): void {
				$this->remembered[ $blog_id ] = array(
					'state'   => get_option( FreezeService::OPTION_STATE, false ),
					'history' => get_option( FreezeService::OPTION_HISTORY, false ),
					'roles'   => $this->roles_with_bypass(),
				);
			}
		);
	}

	/**
	 * Put back whatever the earlier release's uninstall removed. Only fills
	 * gaps: a value that still exists is never overwritten, and nothing is
	 * restored unless an uninstall of the earlier release was actually seen.
	 */
	public function restore_after_uninstall(): void {
		if ( null === $this->remembered ) {
			return;
		}

		$remembered       = $this->remembered;
		$this->remembered = null;

		foreach ( $remembered as $blog_id => $saved ) {
			if ( is_multisite() ) {
				switch_to_blog( $blog_id );
			}

			if ( false !== $saved['state'] && false === get_option( FreezeService::OPTION_STATE, false ) ) {
				add_option( FreezeService::OPTION_STATE, $saved['state'], '', false );
			}

			if ( false !== $saved['history'] && false === get_option( FreezeService::OPTION_HISTORY, false ) ) {
				add_option( FreezeService::OPTION_HISTORY, $saved['history'], '', false );
			}

			foreach ( $saved['roles'] as $role_name ) {
				$role = get_role( $role_name );

				if ( $role && ! $role->has_cap( Installer::CAPABILITY ) ) {
					$role->add_cap( Installer::CAPABILITY );
				}
			}

			if ( is_multisite() ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * The roles that currently hold the bypass capability on this site.
	 *
	 * @return string[]
	 */
	private function roles_with_bypass(): array {
		$names = array();

		foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
			$role = get_role( $role_name );

			if ( $role && $role->has_cap( Installer::CAPABILITY ) ) {
				$names[] = (string) $role_name;
			}
		}

		return $names;
	}

	/**
	 * Run a callback for the current site, or for every site of a Multisite
	 * network (paged, as the earlier release's own uninstall routine does).
	 *
	 * @param callable $callback Receives the blog ID.
	 */
	private function for_each_site( callable $callback ): void {
		if ( ! is_multisite() ) {
			$callback( get_current_blog_id() );

			return;
		}

		$offset = 0;

		do {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => self::SITES_PER_PAGE,
					'offset' => $offset,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				$callback( (int) $site_id );
				restore_current_blog();
			}

			$offset    += self::SITES_PER_PAGE;
			$page_count = count( $site_ids );
		} while ( self::SITES_PER_PAGE === $page_count );
	}
}
