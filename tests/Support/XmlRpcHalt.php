<?php
/**
 * Thrown by HaltingXmlRpcServer in place of IXR_Server's real behavior
 * (rendering XML and calling exit()).
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Support;

/**
 * getCode() carries the IXR_Error fault code (423 for a Stillgate
 * block).
 */
final class XmlRpcHalt extends \Exception {

	public function __construct( string $message, int $code ) {
		parent::__construct( $message, $code );
	}
}
