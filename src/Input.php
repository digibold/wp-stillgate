<?php
/**
 * Safe request-superglobal readers.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Small, centralized helpers for reading request data.
 *
 * Enforcement gates need to inspect $_GET/$_POST/$_REQUEST to decide
 * whether a request targets protected content, purely to route the
 * request to a denial — never to act on the values themselves. Routing
 * this through one place keeps every read consistently unslashed and
 * type-checked, and keeps the justification for skipping nonce
 * verification (nonce verification is core's job for the request it is
 * already handling; Stillgate only reads the same fields core is
 * about to read) in one place instead of repeated at every call site.
 */
final class Input {

	/**
	 * Read a single request key as a trimmed string, or '' if absent or
	 * not scalar.
	 */
	public static function string( string $key, string $source = 'REQUEST' ): string {
		$value = self::source( $source )[ $key ] ?? null;

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing to an enforcement gate; core verifies its own nonce for the request being classified.
		return trim( (string) wp_unslash( $value ) );
	}

	/**
	 * Read a single request key as a non-negative integer, or 0 if absent
	 * or non-scalar. Mirrors core's own `absint()` — which is what the
	 * admin-ajax handlers this class routes around actually use — so a
	 * negative value (`-5`) resolves to the same ID (`5`) here as it does
	 * in core, rather than being dropped to 0 and letting the request
	 * through this gate unrecognized.
	 */
	public static function int( string $key, string $source = 'REQUEST' ): int {
		$value = self::source( $source )[ $key ] ?? null;

		if ( ! is_scalar( $value ) ) {
			return 0;
		}

		return abs( (int) $value );
	}

	/**
	 * Read a single request key with sanitize_key(), or '' if absent.
	 */
	public static function key( string $key, string $source = 'REQUEST' ): string {
		$value = self::source( $source )[ $key ] ?? null;

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing to an enforcement gate; see class docblock.
		return sanitize_key( wp_unslash( (string) $value ) );
	}

	/**
	 * Read a request key expected to hold a list of positive integer IDs,
	 * accepting either a real array (`post[]=1&post[]=2`) or a
	 * comma-separated string (`ids=1,2,3`, as used by bulk actions).
	 *
	 * @return int[] Deduplicated, always >= 1.
	 */
	public static function ids( string $key, string $source = 'REQUEST' ): array {
		$value = self::source( $source )[ $key ] ?? null;

		if ( is_array( $value ) ) {
			$raw = $value;
		} elseif ( is_scalar( $value ) ) {
			$raw = explode( ',', (string) $value );
		} else {
			return array();
		}

		$ids = array();

		foreach ( $raw as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$id = (int) trim( (string) $item );

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Whether a request key is present at all, regardless of whether it
	 * was sent as a scalar or as an array (`media[]=1&media[]=2`, as the
	 * media list table sends it) — unlike string(), which only recognizes
	 * a scalar and would otherwise miss the array form entirely.
	 */
	public static function present( string $key, string $source = 'REQUEST' ): bool {
		$value = self::source( $source )[ $key ] ?? null;

		if ( is_array( $value ) ) {
			return array() !== $value;
		}

		if ( is_scalar( $value ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing to an enforcement gate; core verifies its own nonce for the request being classified.
			return '' !== trim( (string) wp_unslash( $value ) );
		}

		return false;
	}

	/**
	 * Whether the current request method is POST.
	 */
	public static function is_post(): bool {
		return isset( $_SERVER['REQUEST_METHOD'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the method, not acting on input.
			&& 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function source( string $source ): array {
		switch ( $source ) {
			case 'GET':
				return $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			case 'POST':
				return $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only routing to an enforcement gate; core verifies its own nonce for this request.
			default:
				return $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}
}
