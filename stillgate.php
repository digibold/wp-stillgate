<?php
/**
 * Plugin Name:       Stillgate — Editorial Freeze for Deployments
 * Plugin URI:        https://github.com/digibold/wp-stillgate
 * Description:       Freezes editing during deployments and migrations, optionally pauses comments and forms, ends on a timer, and reports what changed.
 * Version:           0.2.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Linards Lazdins
 * Author URI:        https://createit.lv
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stillgate
 * Domain Path:       /languages
 *
 * Stillgate protects production WordPress installations during
 * migrations, deployments and maintenance windows. It places the site into
 * a controlled editorial freeze so that editors cannot make changes that
 * could be lost during a database or file synchronization, while the
 * public-facing frontend stays fully available.
 *
 * Stillgate does not collect telemetry, does not make outbound HTTP
 * requests, and does not require an external account or cloud service. All
 * of its state stays in this WordPress installation's own database.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/autoload.php';

/*
 * Releases up to 0.1.0 were published as "Deployment Guard" and share this
 * plugin's namespace, options and capability. If such a copy (or a second
 * Stillgate folder) has already loaded, its classes are in memory: stay
 * passive, so the two never register twice, and explain on the Plugins
 * screen how to switch over. See LegacyCopy.
 */
if ( class_exists( __NAMESPACE__ . '\\Plugin', false ) ) {
	( new LegacyCopy( true ) )->register_notice();

	return;
}

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );

( new Plugin( __FILE__ ) )->register();
