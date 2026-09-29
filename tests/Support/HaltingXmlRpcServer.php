<?php
/**
 * A real IXR_Server whose error() throws instead of rendering XML and
 * calling exit(), so XmlRpcGateTest can assert on the fault without
 * ending the test process.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Support;

final class HaltingXmlRpcServer extends \IXR_Server {

	public function __construct() {
		// $wait = true skips IXR_Server::serve(), which would otherwise
		// try to process real XML-RPC request input.
		parent::__construct( false, false, true );
	}

	/**
	 * @param mixed $error
	 * @param mixed $message
	 */
	public function error( $error, $message = false ): void {
		if ( $message && ! is_object( $error ) ) {
			$error = new \IXR_Error( $error, $message );
		}

		throw new XmlRpcHalt( (string) $error->message, (int) $error->code );
	}
}
