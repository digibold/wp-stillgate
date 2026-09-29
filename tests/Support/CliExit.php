<?php
/**
 * Thrown by the WpCliStub in place of a real process exit().
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Support;

/**
 * getCode() carries the exit code a real `wp` invocation would have used.
 */
final class CliExit extends \Exception {

	public function __construct( string $message, int $exit_code ) {
		parent::__construct( $message, $exit_code );
	}
}
