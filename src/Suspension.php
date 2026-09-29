<?php
/**
 * Internal suspension mechanism for Deployment Guard's own CLI commands.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

defined( 'ABSPATH' ) || exit;

/**
 * A narrowly scoped, in-memory guard that lets Deployment Guard's own
 * `wp deployment-guard` commands run while a freeze is active, without
 * becoming a general-purpose bypass for anything else running under
 * WP-CLI.
 *
 * Nothing outside this class can open a suspension: there is no hook, no
 * option, and no constant that activates it. It exists only for the
 * duration of a single call to run(), always closes via `finally` (even
 * if the operation throws), and is never consulted by cron or by the
 * scheduled-publish guard.
 *
 * It also never extends to anything WordPress dispatches through an action
 * or filter while it is open: active() is only true at the exact hook
 * nesting depth run() was entered at. A plugin's callback on
 * `updated_option` (fired when a command saves the freeze state), on
 * `shutdown`, or on any other hook runs at least one level deeper, and is
 * evaluated exactly as it would be outside the command. Deployment Guard's
 * own commands only read and write Deployment Guard's own options, which
 * no enforcement layer protects, so they need nothing broader than that.
 *
 * @internal
 */
final class Suspension {

	/** @var int[] The hook nesting depth each currently open run() was entered at. */
	private array $frames = array();

	/**
	 * Whether a suspension is in effect for code running right now.
	 */
	public function active(): bool {
		if ( array() === $this->frames ) {
			return false;
		}

		return self::hook_depth() === $this->frames[ array_key_last( $this->frames ) ];
	}

	/**
	 * Run $operation with the suspension active for its duration only.
	 * Suspensions nest safely: each run() closes only its own frame when it
	 * returns or throws.
	 */
	public function run( \Closure $operation ): mixed {
		$this->frames[] = self::hook_depth();

		try {
			return $operation();
		} finally {
			array_pop( $this->frames );
		}
	}

	/**
	 * Run $operation with any currently open suspension temporarily
	 * lifted. Used when dispatching hooks to third-party listeners, and
	 * before anything that may end the process (WP_CLI::error() calls
	 * exit(), which skips run()'s `finally`).
	 */
	public function outside( \Closure $operation ): mixed {
		$saved        = $this->frames;
		$this->frames = array();

		try {
			return $operation();
		} finally {
			$this->frames = $saved;
		}
	}

	/**
	 * How many actions and filters are executing right now (0 outside any
	 * hook, and always 0 without WordPress loaded).
	 */
	private static function hook_depth(): int {
		global $wp_current_filter;

		return is_array( $wp_current_filter ) ? count( $wp_current_filter ) : 0;
	}
}
