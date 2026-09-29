<?php
/**
 * Plugin Name:       Deployment Guard
 * Plugin URI:        https://github.com/digibold/wp-deployment-guard
 * Description:       Locks editorial changes during deployments and migrations while the site stays online.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Linards Lazdins
 * Author URI:        https://createit.lv
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       deployment-guard
 * Domain Path:       /languages
 *
 * Deployment Guard protects production WordPress installations during
 * migrations, deployments and maintenance windows. It places the site into
 * a controlled editorial freeze so that editors cannot make changes that
 * could be lost during a database or file synchronization, while the
 * public-facing frontend stays fully available.
 *
 * Deployment Guard does not collect telemetry, does not make outbound HTTP
 * requests, and does not require an external account or cloud service. All
 * of its state stays in this WordPress installation's own database.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/autoload.php';

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );

( new Plugin( __FILE__ ) )->register();
