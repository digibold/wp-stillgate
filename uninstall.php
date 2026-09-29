<?php
/**
 * Uninstall handler.
 *
 * WordPress runs this file when the plugin is deleted from the Plugins
 * screen (or via `wp plugin uninstall`), never on ordinary deactivation.
 * It removes every trace of the plugin's stored state from the database.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

// If this file is not called by WordPress's own uninstall process, bail.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The plugin itself is not loaded during uninstall (it has to be
// deactivated before it can be deleted), so its autoloader is not either.
require_once __DIR__ . '/src/autoload.php';

Installer::uninstall();
