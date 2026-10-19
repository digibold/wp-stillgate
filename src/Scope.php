<?php
/**
 * What a freeze covers beyond editorial content.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable set of the optional protections a freeze can add on top of
 * its always-on editorial protection: pausing visitor comments, and
 * pausing visitor form submissions (contact forms and similar).
 *
 * Both default to off, so installing or updating the plugin never
 * changes what visitors can do. They exist for the same reason editing is
 * frozen: anything saved to the database during a deployment or
 * migration can be lost when the database is replaced.
 */
final class Scope {

	public const COMMENTS = 'comments';
	public const FORMS    = 'forms';

	/**
	 * Every kind of visitor submission that can be paused.
	 *
	 * @var string[]
	 */
	public const KINDS = array( self::COMMENTS, self::FORMS );

	public bool $comments;

	public bool $forms;

	public function __construct( bool $comments = false, bool $forms = false ) {
		$this->comments = $comments;
		$this->forms    = $forms;
	}

	/**
	 * Tolerant reader for the stored value: anything that is not a
	 * recognizable array yields the default, and unknown keys are ignored.
	 *
	 * @param mixed $raw The stored option value.
	 */
	public static function from_array( mixed $raw ): self {
		if ( ! is_array( $raw ) ) {
			return new self();
		}

		return new self(
			! empty( $raw[ self::COMMENTS ] ),
			! empty( $raw[ self::FORMS ] )
		);
	}

	/**
	 * @return array{comments: bool, forms: bool}
	 */
	public function to_array(): array {
		return array(
			self::COMMENTS => $this->comments,
			self::FORMS    => $this->forms,
		);
	}

	public function includes( string $kind ): bool {
		return match ( $kind ) {
			self::COMMENTS => $this->comments,
			self::FORMS    => $this->forms,
			default        => false,
		};
	}

	public function is_empty(): bool {
		return ! $this->comments && ! $this->forms;
	}

	/**
	 * The enabled kinds, for logging and display.
	 *
	 * @return string[]
	 */
	public function enabled_kinds(): array {
		return array_values(
			array_filter(
				self::KINDS,
				fn ( string $kind ): bool => $this->includes( $kind )
			)
		);
	}
}
