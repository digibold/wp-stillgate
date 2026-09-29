<?php
/**
 * Verifies that the plugin version is consistent across every file that
 * states it: the main plugin file's header, readme.txt's Stable tag,
 * Plugin::VERSION, and the top entry in CHANGELOG.md. Run in CI so these
 * can never quietly drift apart.
 *
 * Usage: php bin/check-version.php
 *
 * @package Digibold\DeploymentGuard
 */

declare( strict_types = 1 );

$root = dirname( __DIR__ );

/**
 * @return array{0: string, 1: string} [label, version] or exits on failure.
 */
function extract_or_fail( string $label, string $file, string $pattern ): string {
	$path = "$file";

	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "check-version: cannot read {$path}\n" );
		exit( 1 );
	}

	$contents = file_get_contents( $path );

	if ( false === $contents || 1 !== preg_match( $pattern, $contents, $matches ) ) {
		fwrite( STDERR, "check-version: could not find {$label} in {$path}\n" );
		exit( 1 );
	}

	return $matches[1];
}

$header_version = extract_or_fail(
	'the Version header',
	"$root/deployment-guard.php",
	'/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)\s*$/m'
);

$stable_tag = extract_or_fail(
	'the Stable tag',
	"$root/readme.txt",
	'/^Stable tag:\s*([0-9][0-9A-Za-z.\-]*)\s*$/m'
);

$class_version = extract_or_fail(
	'the VERSION constant',
	"$root/src/Plugin.php",
	'/const\s+VERSION\s*=\s*\'([0-9][0-9A-Za-z.\-]*)\'/'
);

$changelog_version = extract_or_fail(
	'the top Changelog entry',
	"$root/CHANGELOG.md",
	'/^## \[([0-9][0-9A-Za-z.\-]*)\]/m'
);

$found = array(
	'deployment-guard.php Version header' => $header_version,
	'readme.txt Stable tag'               => $stable_tag,
	'Plugin::VERSION'                     => $class_version,
	'CHANGELOG.md top entry'              => $changelog_version,
);

$unique = array_unique( array_values( $found ) );

if ( count( $unique ) > 1 ) {
	fwrite( STDERR, "check-version: version mismatch found:\n" );

	foreach ( $found as $label => $version ) {
		fwrite( STDERR, "  {$label}: {$version}\n" );
	}

	exit( 1 );
}

echo 'check-version: all sources agree on version ' . reset( $unique ) . "\n";
