<?php
/**
 * A tiny mu-plugin used only by tests/e2e/cli.sh, to prove that an
 * unrelated cron event still runs during a freeze (Deployment Guard must
 * never disable WP-Cron itself, only hold back scheduled publication of
 * protected content).
 *
 * @package Digibold\DeploymentGuard
 */

// Classic Custom Background support, so tests/e2e/cli.sh can check that
// the Custom Background screen is gated on every URL core serves it from.
add_action(
	'after_setup_theme',
	static function () {
		add_theme_support( 'custom-background' );
	},
	0
);

add_action(
	'dg_e2e_probe',
	static function () {
		update_option( 'dg_e2e_probe_ran', 1 );
	}
);
