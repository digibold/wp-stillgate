<?php
/**
 * A minimal stand-in for the WP_CLI\Utils\format_items() function, used
 * only by the integration test suite. See WpCliStub.php for why this
 * needs to exist at all; this is a separate file only because a
 * namespace declaration must be the first statement in a file, and
 * WpCliStub.php's WP_CLI class needs to live in the global namespace.
 *
 * @package Digibold\DeploymentGuard
 */

namespace WP_CLI\Utils;

if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
	/**
	 * Records each call instead of printing a table, so a test can assert
	 * what Command::status() would have shown.
	 *
	 * @var array<int, array{0: string, 1: array, 2: array}>
	 */
	$GLOBALS['dg_test_formatted_items'] = array();

	/**
	 * @param string       $format
	 * @param array<mixed> $items
	 * @param array<mixed> $fields
	 */
	function format_items( $format, $items, $fields ): void {
		$GLOBALS['dg_test_formatted_items'][] = array( $format, $items, $fields );
	}
}
