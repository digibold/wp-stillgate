<?php
/**
 * XML-RPC write protection.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Enforcement;

use Digibold\DeploymentGuard\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks the content-changing XML-RPC methods while a freeze is active,
 * without disabling read-only XML-RPC functionality (comments, pingbacks,
 * or any `get*`/`system.*` method).
 *
 * Hooked on `xmlrpc_call`, which every built-in XML-RPC method fires with
 * its own canonical name after successfully authenticating the request
 * (`wp.uploadFile` and `wp.deleteFile`, for instance, are aliases that
 * internally call the same handlers as `metaWeblog.newMediaObject` and
 * `wp.deletePost`, and fire under those canonical names — so the
 * denylist below only needs to name each canonical method once).
 */
final class XmlRpcGate {

	/**
	 * Content-changing XML-RPC methods. Every read-only method (get*,
	 * system.*, comment methods, pingback.ping, wp.editProfile,
	 * wp.setOptions) is deliberately left off this list.
	 *
	 * @var string[]
	 */
	private const DENYLIST = array(
		'wp.newPost',
		'wp.editPost',
		'wp.deletePost',
		'wp.newTerm',
		'wp.editTerm',
		'wp.deleteTerm',
		'wp.newPage',
		'wp.deletePage',
		'wp.editPage',
		'wp.newCategory',
		'wp.deleteCategory',
		'wp.restoreRevision',
		'blogger.newPost',
		'blogger.editPost',
		'blogger.deletePost',
		'metaWeblog.newPost',
		'metaWeblog.editPost',
		'metaWeblog.newMediaObject',
		'mt.setPostCategories',
		'mt.publishPost',
	);

	private Guard $guard;

	public function __construct( Guard $guard ) {
		$this->guard = $guard;
	}

	public function register(): void {
		add_action( 'xmlrpc_call', array( $this, 'guard_call' ), 10, 3 );
	}

	/**
	 * @param mixed $name
	 * @param mixed $args
	 * @param mixed $server
	 */
	public function guard_call( $name, $args, $server = null ): void {
		if ( ! is_string( $name ) || ! in_array( $name, self::DENYLIST, true ) ) {
			return;
		}

		if ( ! $this->guard->applies_to_xmlrpc() ) {
			return;
		}

		$this->guard->report( 'xmlrpc', $name );

		$message = $this->guard->message_text();

		if ( $server instanceof \IXR_Server ) {
			// error() renders the fault and exits, exactly like core's own
			// authentication and capability failures do for this API.
			$server->error( new \IXR_Error( 423, $message ) );

			return;
		}

		// Defensive fallback: not reachable for a genuine XML-RPC request,
		// but never let an unexpected argument shape allow the call to
		// proceed silently.
		wp_die( esc_html( $message ), '', array( 'response' => 423 ) );
	}
}
