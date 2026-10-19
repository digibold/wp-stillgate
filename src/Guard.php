<?php
/**
 * Restriction policy: who is affected by an active freeze, and why.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the current actor is restricted by an active freeze, and
 * whether a specific object or surface is protected.
 *
 * Three actor contexts exist: `web` (browser requests, including the
 * block editor and REST calls made from it), `cli` (WP-CLI, any command
 * other than Stillgate's own), and `cron` (a WP-Cron event
 * actually running, including `wp cron event run`). Cron is always
 * restricted, with no bypass and no filter: a bypass capability or an
 * explicit request context is a property of a person taking an action,
 * neither of which a scheduled task has. CLI and web restriction is
 * capability-based throughout, never role-based, so a custom role granted
 * `deployment_guard_bypass` is honored exactly like the Administrator
 * role.
 */
final class Guard {

	/**
	 * Public post types protected in addition to whatever
	 * `get_post_types( [ 'public' => true ] )` already returns.
	 *
	 * @var string[]
	 */
	private const EXTRA_POST_TYPES = array(
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
		'nav_menu_item',
	);

	/**
	 * Post types never protected even if they are otherwise public:
	 * revisions are resolved to their parent instead, and the rest are
	 * internal bookkeeping types with no editorial meaning.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_POST_TYPES = array(
		'revision',
		'oembed_cache',
		'user_request',
		'customize_changeset',
	);

	/**
	 * Taxonomies protected in addition to public taxonomies.
	 *
	 * @var string[]
	 */
	private const EXTRA_TAXONOMIES = array(
		'nav_menu',
		'wp_pattern_category',
	);

	/**
	 * Taxonomies never protected.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_TAXONOMIES = array(
		'link_category',
	);

	private FreezeService $service;

	private Suspension $suspension;

	private bool $is_cli;

	/** @var array<string, bool> Memoized is_restricted() results, keyed by "blog_id|user_id|context". */
	private array $restricted_memo = array();

	private bool $computing_restriction = false;

	/** @var array{post_types: string[], taxonomies: string[]}|null */
	private ?array $protected_types_cache = null;

	public function __construct( FreezeService $service, Suspension $suspension, bool $is_cli ) {
		$this->service    = $service;
		$this->suspension = $suspension;
		$this->is_cli     = $is_cli;

		add_action( 'set_current_user', array( $this, 'reset_memo' ) );
		add_action( 'switch_blog', array( $this, 'reset_memo' ) );
		add_action( 'add_option_' . FreezeService::OPTION_STATE, array( $this, 'reset_memo' ) );
		add_action( 'update_option_' . FreezeService::OPTION_STATE, array( $this, 'reset_memo' ) );
		add_action( 'delete_option_' . FreezeService::OPTION_STATE, array( $this, 'reset_memo' ) );
	}

	/**
	 * Clear memoized restriction results. Called automatically when the
	 * current user or site changes, or when the freeze state option is
	 * added, updated, or deleted (which is how a state change made
	 * through one FreezeService instance is picked up by a Guard built
	 * around a different one, and by this same request's own writes).
	 */
	public function reset_memo(): void {
		$this->restricted_memo       = array();
		$this->protected_types_cache = null;
	}

	/**
	 * The current actor's context: 'cron', 'cli', or 'web'. Cron takes
	 * priority over cli, because `wp cron event run` defines both
	 * `WP_CLI` and `DOING_CRON` in the same process, and a cron event is
	 * never restricted by an operator's identity.
	 */
	public function context(): string {
		if ( wp_doing_cron() ) {
			return 'cron';
		}

		if ( $this->is_cli ) {
			return 'cli';
		}

		return 'web';
	}

	/**
	 * Whether the current actor is restricted by the freeze right now.
	 */
	public function is_restricted(): bool {
		if ( ! did_action( 'init' ) ) {
			return false;
		}

		if ( $this->suspension->active() ) {
			return false;
		}

		$context = $this->context();

		if ( 'web' === $context ) {
			if ( ! did_action( 'set_current_user' ) ) {
				return false;
			}

			$user_id = get_current_user_id();

			if ( 0 === $user_id ) {
				return false;
			}
		} else {
			$user_id = get_current_user_id();
		}

		$memo_key = get_current_blog_id() . '|' . $user_id . '|' . $context;

		if ( array_key_exists( $memo_key, $this->restricted_memo ) ) {
			return $this->restricted_memo[ $memo_key ];
		}

		if ( $this->computing_restriction ) {
			// Recursion guard: current_user_can() below can, in principle,
			// re-enter this method through unrelated plugin code. Fail
			// open on the recursive call only; the outer call still
			// computes and caches the real answer.
			return false;
		}

		$this->computing_restriction = true;

		try {
			$restricted = $this->compute_restricted( $context, $user_id );
		} finally {
			$this->computing_restriction = false;
		}

		$this->restricted_memo[ $memo_key ] = $restricted;

		return $restricted;
	}

	private function compute_restricted( string $context, int $user_id ): bool {
		if ( ! $this->service->is_active() ) {
			return false;
		}

		if ( 'cron' === $context ) {
			// No bypass, no filter: a scheduled task carries no identity
			// to check a capability against, and must not become an
			// unrestricted write path just because it happens to run
			// under a bypass holder's or a super admin's user context.
			return true;
		}

		$restricted = ! user_can( $user_id, 'deployment_guard_bypass' );

		/**
		 * Filters whether the current web or CLI actor is restricted by
		 * an active freeze. Never consulted for cron.
		 *
		 * @param bool   $restricted Whether the actor is restricted.
		 * @param int    $user_id    The acting user ID (0 for WP-CLI with no --user).
		 * @param string $context    Either 'web' or 'cli'.
		 */
		return (bool) apply_filters( 'deployment_guard_is_restricted', $restricted, $user_id, $context );
	}

	/**
	 * Whether the current actor, in the current context, is blocked from
	 * acting on a protected object: restricted, the object is protected,
	 * and (for web only) the actor natively holds the matching primitive
	 * capability. CLI and cron actors carry no editorial capabilities to
	 * test, so the primitive check is skipped for them entirely.
	 */
	private function applies( bool $is_protected, string $primitive_cap ): bool {
		if ( ! $is_protected ) {
			return false;
		}

		if ( ! $this->is_restricted() ) {
			return false;
		}

		if ( 'web' !== $this->context() ) {
			return true;
		}

		return current_user_can( $primitive_cap );
	}

	public function applies_to_post_type( string $post_type ): bool {
		return $this->applies( $this->is_protected_post_type( $post_type ), $this->primitive_for_post_type( $post_type ) );
	}

	public function applies_to_post( int $post_id ): bool {
		$post = get_post( $post_id );

		if ( $post && 'revision' === $post->post_type ) {
			// A revision (or autosave) is part of its parent's content,
			// protected exactly when the parent is.
			$post = get_post( (int) $post->post_parent );
		}

		if ( ! $post ) {
			return false;
		}

		return $this->applies_to_post_type( $post->post_type );
	}

	/**
	 * @param string $mode Either 'edit' (editing/deleting the taxonomy's
	 *                     terms) or 'assign' (assigning existing terms to
	 *                     an object).
	 */
	public function applies_to_taxonomy( string $taxonomy, string $mode = 'edit' ): bool {
		return $this->applies( $this->is_protected_taxonomy( $taxonomy ), $this->primitive_for_taxonomy( $taxonomy, $mode ) );
	}

	public function applies_to_term( int $term_id, string $mode = 'edit' ): bool {
		$term = get_term( $term_id );

		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}

		return $this->applies_to_taxonomy( $term->taxonomy, $mode );
	}

	/**
	 * Menus, widgets, the Customizer, the Site Editor, and the classic
	 * custom header/background screens: always protected as a group,
	 * gated on `edit_theme_options` for web actors.
	 */
	public function applies_to_surface(): bool {
		return $this->applies( true, 'edit_theme_options' );
	}

	/**
	 * The same restricted + primitive-capability check applies() performs,
	 * for a caller that has already determined the object is protected
	 * through means of its own (RestGate's fallback for a REST controller
	 * this class does not otherwise recognize).
	 */
	public function applies_generic( string $primitive_cap ): bool {
		return $this->applies( true, $primitive_cap );
	}

	/**
	 * XML-RPC is web-only (never invoked by cron or CLI), so this always
	 * runs the primitive check: the actor is blocked when they natively
	 * hold any capability that lets XML-RPC change protected content. That
	 * is not only `edit_posts`/`upload_files` — `wp.newPost` checks the
	 * requested post type's own `create_posts`/`edit_posts` (which, for
	 * templates or a custom post type, can be a different capability
	 * entirely), and creating a post never consults a meta capability the
	 * capability layer could deny.
	 */
	public function applies_to_xmlrpc(): bool {
		if ( ! $this->is_restricted() ) {
			return false;
		}

		$caps = array( 'edit_posts', 'upload_files' );

		foreach ( $this->protected_types()['post_types'] as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( $object ) {
				$caps[] = $object->cap->edit_posts;
				$caps[] = $object->cap->create_posts;
			}
		}

		foreach ( $this->protected_types()['taxonomies'] as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );

			if ( $object ) {
				$caps[] = $object->cap->edit_terms;
				$caps[] = $object->cap->assign_terms;
			}
		}

		foreach ( array_unique( array_filter( $caps, 'is_string' ) ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}

		return false;
	}

	public function is_protected_post_type( string $post_type ): bool {
		return in_array( $post_type, $this->protected_types()['post_types'], true );
	}

	public function is_protected_taxonomy( string $taxonomy ): bool {
		return in_array( $taxonomy, $this->protected_types()['taxonomies'], true );
	}

	/**
	 * The effective set of protected post types and taxonomies. Resolved
	 * fresh on every call until `wp_loaded` has fired (registering a
	 * custom post type happens on `init`, at any priority, so caching
	 * before `wp_loaded` risks missing one registered after this method
	 * first runs); cached from then on.
	 *
	 * @return array{post_types: string[], taxonomies: string[]}
	 */
	public function protected_types(): array {
		if ( null !== $this->protected_types_cache && did_action( 'wp_loaded' ) ) {
			return $this->protected_types_cache;
		}

		$post_types = get_post_types( array( 'public' => true ) );

		foreach ( self::EXTRA_POST_TYPES as $extra ) {
			if ( post_type_exists( $extra ) ) {
				$post_types[ $extra ] = $extra;
			}
		}

		foreach ( self::EXCLUDED_POST_TYPES as $excluded ) {
			unset( $post_types[ $excluded ] );
		}

		$taxonomies = get_taxonomies( array( 'public' => true ) );

		foreach ( self::EXTRA_TAXONOMIES as $extra ) {
			if ( taxonomy_exists( $extra ) ) {
				$taxonomies[ $extra ] = $extra;
			}
		}

		foreach ( self::EXCLUDED_TAXONOMIES as $excluded ) {
			unset( $taxonomies[ $excluded ] );
		}

		$defaults = array(
			'post_types' => array_values( $post_types ),
			'taxonomies' => array_values( $taxonomies ),
		);

		/**
		 * Filters the set of post types and taxonomies protected while a
		 * freeze is active.
		 *
		 * @param array{post_types: string[], taxonomies: string[]} $protected Effective protected types.
		 */
		$result = apply_filters( 'deployment_guard_protected_types', $defaults );

		if ( ! is_array( $result )
			|| ! isset( $result['post_types'], $result['taxonomies'] )
			|| ! is_array( $result['post_types'] )
			|| ! is_array( $result['taxonomies'] )
		) {
			$result = $defaults;
		} else {
			$result = array(
				'post_types' => array_values( array_filter( $result['post_types'], 'is_string' ) ),
				'taxonomies' => array_values( array_filter( $result['taxonomies'], 'is_string' ) ),
			);
		}

		if ( did_action( 'wp_loaded' ) ) {
			$this->protected_types_cache = $result;
		}

		return $result;
	}

	/**
	 * The paragraphs making up the freeze message shown to a restricted
	 * user: a fixed explanation, an expected-resumption sentence when the
	 * freeze became active purely through a schedule, and the operator's
	 * own custom message, if any.
	 *
	 * @return string[]
	 */
	public function message_paragraphs(): array {
		$paragraphs   = array();
		$paragraphs[] = __( 'Content changes are temporarily locked while a deployment is in progress.', 'stillgate' );

		$ends_at = $this->ends_at();

		if ( null !== $ends_at ) {
			$paragraphs[] = sprintf(
				/* translators: %s: date and time editing is expected to resume. */
				__( 'Editing is expected to resume on %s.', 'stillgate' ),
				$this->format_datetime( $ends_at )
			);
		}

		$custom = trim( $this->service->config()->message );

		if ( '' !== $custom ) {
			$paragraphs[] = $custom;
		}

		return $paragraphs;
	}

	public function message_text(): string {
		return implode( "\n\n", $this->message_paragraphs() );
	}

	/**
	 * The freeze message as already-escaped HTML paragraphs, safe to
	 * `echo` directly.
	 */
	public function message_html(): string {
		$html = '';

		foreach ( $this->message_paragraphs() as $paragraph ) {
			$html .= '<p>' . esc_html( $paragraph ) . '</p>';
		}

		return $html;
	}

	/**
	 * When the current freeze is expected to end, or null if there is no
	 * predictable end (no freeze active, forced via configuration, or
	 * active because an operator manually enabled it).
	 */
	public function ends_at(): ?int {
		return $this->service->ends_at();
	}

	/**
	 * Whether visitor submissions of the given kind are paused for the
	 * current request: a freeze is active, the site chose to pause that
	 * kind (see Scope), and the actor is not somebody who can bypass the
	 * freeze. Unlike is_restricted() this also covers anonymous visitors,
	 * since they are exactly who comments and contact forms come from; it
	 * is only ever asked at the moment a submission arrives, never on an
	 * ordinary page view.
	 *
	 * @param string $kind One of Scope::KINDS.
	 */
	public function submissions_paused( string $kind ): bool {
		if ( ! did_action( 'init' ) || $this->suspension->active() ) {
			return false;
		}

		if ( ! $this->service->scope()->includes( $kind ) || ! $this->service->is_active() ) {
			return false;
		}

		if ( 'cron' !== $this->context() && current_user_can( Installer::CAPABILITY ) ) {
			return false;
		}

		/**
		 * Filters whether visitor submissions of a kind are paused right
		 * now, once a freeze, the site's scope settings and the actor have
		 * all said yes.
		 *
		 * @param bool   $paused Whether the submission is paused.
		 * @param string $kind   Either 'comments' or 'forms'.
		 */
		return (bool) apply_filters( 'deployment_guard_submissions_paused', true, $kind );
	}

	/**
	 * What a visitor is told when a submission is paused.
	 *
	 * @param string $kind One of Scope::KINDS.
	 */
	public function visitor_message( string $kind ): string {
		$text = Scope::COMMENTS === $kind
			? __( 'Comments are temporarily paused while the site is being updated.', 'stillgate' )
			: __( 'Form submissions are temporarily paused while the site is being updated.', 'stillgate' );

		$ends_at = $this->ends_at();

		if ( null !== $ends_at ) {
			$text .= ' ' . sprintf(
				/* translators: %s: date and time submissions are expected to reopen. */
				__( 'Please try again after %s.', 'stillgate' ),
				$this->format_datetime( $ends_at )
			);
		} else {
			$text .= ' ' . __( 'Please try again in a few minutes.', 'stillgate' );
		}

		/**
		 * Filters the message shown to a visitor whose submission is paused.
		 *
		 * @param string $text Message text (plain text).
		 * @param string $kind Either 'comments' or 'forms'.
		 */
		return (string) apply_filters( 'deployment_guard_visitor_message', $text, $kind );
	}
	/**
	 * Whether a freeze is in effect right now, independent of any actor
	 * or capability. Used only where there is no user to check a
	 * capability against at all, such as post-by-email (which runs as
	 * user 0): everywhere else, is_restricted() is the right check.
	 */
	public function is_active(): bool {
		return $this->service->is_active();
	}

	/**
	 * A ready-to-return WP_Error for a blocked REST request.
	 */
	public function rest_error(): \WP_Error {
		$ends_at = $this->ends_at();

		return new \WP_Error(
			'deployment_guard_active',
			$this->message_text(),
			array(
				'status'           => 423,
				'deployment_guard' => array(
					'ends_at' => null !== $ends_at ? gmdate( 'c', $ends_at ) : null,
				),
			)
		);
	}

	/**
	 * Record a blocked write attempt and notify listeners. Safe to call
	 * often: History applies its own deduplication and throttling.
	 *
	 * @param string $vector  One of: admin, ajax, rest, xmlrpc, data, cli, cron, mail.
	 * @param string $target  A short, non-sensitive identifier for what was blocked.
	 * @param array  $context Additional allowlisted context (see History::sanitize_context()).
	 */
	public function report( string $vector, string $target, array $context = array() ): void {
		$this->service->record_blocked( $vector, $target, $context );

		/**
		 * Fires whenever a write attempt is blocked by an active freeze.
		 *
		 * @param string $vector  Where the attempt was blocked.
		 * @param string $target  What was targeted.
		 * @param int    $user_id The acting user ID (0 for cron or anonymous CLI).
		 */
		do_action( 'deployment_guard_blocked', $vector, $target, get_current_user_id() );
	}

	private function primitive_for_post_type( string $post_type ): string {
		if ( 'attachment' === $post_type ) {
			// The attachment post type maps create_posts to upload_files
			// but leaves edit_posts as the generic capability, so
			// upload_files is the specific, correct primitive to test.
			return 'upload_files';
		}

		$object = get_post_type_object( $post_type );

		if ( ! $object || ! isset( $object->cap->edit_posts ) ) {
			return 'edit_posts';
		}

		return $object->cap->edit_posts;
	}

	private function primitive_for_taxonomy( string $taxonomy, string $mode ): string {
		$object = get_taxonomy( $taxonomy );

		if ( ! $object || ! isset( $object->cap ) ) {
			return 'edit_terms' === $mode ? 'edit_terms' : 'assign_terms';
		}

		if ( 'assign' === $mode ) {
			return $object->cap->assign_terms ?? 'assign_terms';
		}

		return $object->cap->edit_terms ?? 'edit_terms';
	}

	private function format_datetime( int $timestamp ): string {
		return sprintf(
			/* translators: 1: date, 2: time */
			__( '%1$s at %2$s', 'stillgate' ),
			wp_date( get_option( 'date_format', 'F j, Y' ), $timestamp ),
			wp_date( get_option( 'time_format', 'g:i a' ), $timestamp )
		);
	}
}
