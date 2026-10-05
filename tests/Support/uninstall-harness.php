<?php
/**
 * Runs uninstall.php the way WordPress does when the plugin is deleted
 * from the Plugins screen: in a request where the plugin itself was never
 * loaded (it has to be deactivated before it can be deleted), so neither
 * stillgate.php nor the plugin's autoloader has run. Only the few
 * WordPress functions the uninstall routine needs are stubbed.
 *
 * Executed as a separate PHP process by tests/Unit/UninstallTest.php;
 * prints what was removed as JSON.
 *
 * @package Digibold\DeploymentGuard
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_UNINSTALL_PLUGIN', 'stillgate/stillgate.php' );

$GLOBALS['dg_deleted_options'] = array();
$GLOBALS['dg_removed_caps']    = array();

function is_multisite() {
	return false;
}

function delete_option( $option ) {
	$GLOBALS['dg_deleted_options'][] = $option;

	return true;
}

final class DG_Uninstall_Role_Stub {

	private string $name;

	public function __construct( string $name ) {
		$this->name = $name;
	}

	public function has_cap( $cap ) {
		return true;
	}

	public function remove_cap( $cap ) {
		$GLOBALS['dg_removed_caps'][] = $this->name . ':' . $cap;
	}
}

function wp_roles() {
	return (object) array(
		'roles' => array(
			'administrator' => array(),
			'editor'        => array(),
		),
	);
}

function get_role( $role ) {
	return new DG_Uninstall_Role_Stub( $role );
}

require dirname( __DIR__, 2 ) . '/uninstall.php';

echo json_encode(
	array(
		'deleted' => $GLOBALS['dg_deleted_options'],
		'removed' => $GLOBALS['dg_removed_caps'],
	)
);
