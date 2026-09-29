<?php
/**
 * Capability-layer backstop: denies protected-object meta capabilities.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Enforcement;

use Digibold\DeploymentGuard\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Filters `map_meta_cap` so that, for a restricted actor, the meta
 * capabilities that let WordPress change a protected object resolve to
 * `do_not_allow`, whichever door (classic admin, the block editor, REST,
 * XML-RPC, or a third-party plugin) asks for them. It sits underneath the
 * request-level gates, which exist to answer at the door itself with a
 * clear 423 message. It can only stop code that asks WordPress whether the
 * current user may make a change: code that calls wp_insert_post() and
 * similar functions without any capability check, or writes with direct
 * SQL, never reaches it (DataLayerGuard covers the former in WP-CLI and
 * WP-Cron).
 *
 * Two tiers, both denied whenever the object is protected:
 *  - Tier A: the deletion capabilities.
 *  - Tier B: the edit/publish/term capabilities, and with them every meta
 *    capability core derives from them (edit_post_meta, for instance).
 *    Core also checks these while merely *reading* some objects
 *    (previewing a draft, `context=edit` REST reads), so those reads are
 *    unavailable to a restricted user as well — consistent with such a
 *    user not being able to open an editor for frozen content at all.
 *
 * A subtlety in core makes denying them safe for everything else:
 * `read_post`, `read_page`, and `edit_comment` all internally recurse
 * into `map_meta_cap( 'edit_post', ... )` for a non-public post status,
 * and that recursive call passes back through this very filter. Left
 * alone, that would make viewing another user's draft, or moderating a
 * comment on a protected post, fail too. When that happens, this filter
 * detects the leaked `do_not_allow` and recomputes the ORIGINAL
 * capability with itself suspended, restoring core's own answer.
 */
final class CapabilityFilter {

	/**
	 * Always denied for a protected object, regardless of request type.
	 *
	 * @var string[]
	 */
	private const DENY_TIER_A = array( 'delete_post', 'delete_page', 'delete_term' );

	/**
	 * Denied for a protected object; recursion into these from read_post/
	 * edit_comment is corrected for by the recompute step below.
	 *
	 * @var string[]
	 */
	private const DENY_TIER_B = array( 'edit_post', 'edit_page', 'publish_post', 'edit_term', 'assign_term' );

	/**
	 * Meta caps that must never be left denied purely because they
	 * recursed into a tier-B cap internally.
	 *
	 * @var string[]
	 */
	private const RECOMPUTE = array( 'read_post', 'read_page', 'edit_comment' );

	private Guard $guard;

	/** Recursion guard used only while recomputing (see maybe_recompute()). */
	private bool $recomputing = false;

	public function __construct( Guard $guard ) {
		$this->guard = $guard;
	}

	public function register(): void {
		add_filter( 'map_meta_cap', array( $this, 'filter' ), PHP_INT_MAX, 4 );
	}

	/**
	 * @param mixed  $caps    Expected array, but core hands us whatever the
	 *                        previous filter returned; a plugin ahead of us
	 *                        misbehaving must not break capability checks.
	 * @param mixed  $cap     Expected string.
	 * @param mixed  $user_id Expected int.
	 * @param mixed  $args    Expected array.
	 * @return mixed
	 */
	public function filter( $caps, $cap, $user_id, $args ) {
		if ( ! is_array( $caps ) || ! is_string( $cap ) || ! is_array( $args ) ) {
			return $caps;
		}

		if ( $this->recomputing ) {
			return $caps;
		}

		if ( ! did_action( 'init' ) ) {
			// Never resolve the current user this early; nothing relevant
			// to this plugin runs before init anyway.
			return $caps;
		}

		if ( in_array( $cap, self::RECOMPUTE, true ) ) {
			return $this->maybe_recompute( $caps, $cap, (int) $user_id, $args );
		}

		if ( in_array( $cap, self::DENY_TIER_A, true ) ) {
			return $this->maybe_deny( $caps, $cap, (int) $user_id, $args );
		}

		if ( in_array( $cap, self::DENY_TIER_B, true ) ) {
			return $this->maybe_deny( $caps, $cap, (int) $user_id, $args );
		}

		return $caps;
	}

	/**
	 * @return mixed
	 */
	private function maybe_deny( array $caps, string $cap, int $user_id, array $args ) {
		if ( get_current_user_id() !== $user_id ) {
			// Capability checks made against a DIFFERENT user (a plugin
			// checking what someone else can do) are not this actor's own
			// request; leave them to core.
			return $caps;
		}

		if ( ! $this->guard->is_restricted() ) {
			return $caps;
		}

		if ( ! $this->object_is_protected( $cap, $args ) ) {
			return $caps;
		}

		return array( 'do_not_allow' );
	}

	private function object_is_protected( string $cap, array $args ): bool {
		$object = $args[0] ?? null;

		if ( is_object( $object ) ) {
			// map_meta_cap() resolves a post object the same as its ID, and
			// plugins commonly pass one; never cast an object to int.
			$object_id = (int) ( $object->ID ?? $object->term_id ?? 0 );
		} else {
			$object_id = is_scalar( $object ) ? (int) $object : 0;
		}

		if ( $object_id <= 0 ) {
			return false;
		}

		switch ( $cap ) {
			case 'delete_post':
			case 'edit_post':
			case 'publish_post':
			case 'delete_page':
			case 'edit_page':
				return $this->guard->applies_to_post( $object_id );

			case 'delete_term':
			case 'edit_term':
				return $this->guard->applies_to_term( $object_id, 'edit' );

			case 'assign_term':
				return $this->guard->applies_to_term( $object_id, 'assign' );
		}

		return false;
	}

	/**
	 * @return mixed
	 */
	private function maybe_recompute( array $caps, string $cap, int $user_id, array $args ) {
		if ( ! in_array( 'do_not_allow', $caps, true ) ) {
			return $caps;
		}

		if ( ! $this->guard->is_restricted() ) {
			return $caps;
		}

		$this->recomputing = true;

		try {
			return map_meta_cap( $cap, $user_id, ...$args );
		} finally {
			$this->recomputing = false;
		}
	}
}
