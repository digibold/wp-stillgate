<?php
/**
 * A minimal stand-in for WP_CLI, used only by the integration test suite.
 *
 * Real WP-CLI is not loaded when running under plain PHPUnit, but
 * DataLayerGuard and Cli\Command call \WP_CLI directly (exactly as they
 * would in a real `wp` invocation), so a global WP_CLI class must exist
 * for CLI-context tests to exercise that real code path. error() throws
 * instead of exiting the PHP process, which is what lets a test assert
 * both "the write was refused" and "the command would have exited
 * non-zero" without ending the test run itself.
 *
 * @package Digibold\DeploymentGuard
 */

if ( ! class_exists( 'WP_CLI', false ) ) {
	#[AllowDynamicProperties]
	class WP_CLI {

		/** @var array<int, array{0: string, 1: mixed}> */
		public static array $log = array();

		/**
		 * @param string|\WP_Error $message
		 * @param bool|int         $exit
		 */
		public static function error( $message, $exit = true ): void {
			$text = is_wp_error( $message ) ? $message->get_error_message() : (string) $message;

			self::$log[] = array( 'error', $text );

			if ( false !== $exit ) {
				throw new \Digibold\DeploymentGuard\Tests\Support\CliExit( $text, true === $exit ? 1 : (int) $exit );
			}
		}

		/**
		 * @param mixed $message
		 */
		public static function success( $message ): void {
			self::$log[] = array( 'success', $message );
		}

		/**
		 * @param mixed $message
		 */
		public static function warning( $message ): void {
			self::$log[] = array( 'warning', $message );
		}

		/**
		 * @param mixed $message
		 */
		public static function log( $message ): void {
			self::$log[] = array( 'log', $message );
		}

		public static function line( $message = '' ): void {
			self::$log[] = array( 'line', $message );
		}

		public static function halt( int $code ): void {
			throw new \Digibold\DeploymentGuard\Tests\Support\CliExit( 'halt', $code );
		}

		/**
		 * @param mixed                $value
		 * @param array<string, mixed> $assoc_args
		 */
		public static function print_value( $value, $assoc_args = array() ): void {
			self::$log[] = array( 'print_value', array( $value, $assoc_args ) );
		}

		/**
		 * @param string       $name
		 * @param mixed        $callable
		 * @param array<mixed> $args
		 */
		public static function add_command( $name, $callable, $args = array() ): void {
			self::$log[] = array( 'add_command', $name );
		}

		public static function reset_log(): void {
			self::$log = array();
		}

		/**
		 * @return array<int, mixed>
		 */
		public static function messages_of( string $type ): array {
			$messages = array();

			foreach ( self::$log as $entry ) {
				if ( $type === $entry[0] ) {
					$messages[] = $entry[1];
				}
			}

			return $messages;
		}
	}
}
