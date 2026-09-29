<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that every class the plugin ships loads through its own
 * src/autoload.php, without help from Composer's autoloader. This is what
 * guarantees the distributed plugin (which never ships a vendor/
 * directory) actually works standalone.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AutoloaderTest extends TestCase {

	private function src_dir(): string {
		return dirname( __DIR__, 2 ) . '/src';
	}

	public function test_every_class_file_loads_via_the_shipped_autoloader(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/' );
		}

		// Deliberately do NOT use Composer's autoloader for the plugin's
		// own namespace: require only the shipped autoloader, exactly as
		// a production install (with no vendor/ directory) would.
		require $this->src_dir() . '/autoload.php';

		foreach ( $this->collect_classes() as $class_name ) {
			$this->assertTrue(
				class_exists( $class_name ) || interface_exists( $class_name ),
				"Failed to autoload {$class_name} via src/autoload.php"
			);
		}
	}

	/**
	 * @return string[] Fully-qualified class names for every *.php file
	 *                   under src/, derived from its path.
	 */
	private function collect_classes(): array {
		$classes = array();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->src_dir(), \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			if ( 'autoload.php' === $file->getFilename() ) {
				continue;
			}

			$relative = substr( $file->getPathname(), strlen( $this->src_dir() ) + 1 );
			$relative = substr( $relative, 0, -4 ); // Strip ".php".
			$classes[] = 'Digibold\\DeploymentGuard\\' . str_replace( '/', '\\', $relative );
		}

		return $classes;
	}
}
