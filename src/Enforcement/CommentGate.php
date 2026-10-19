<?php
/**
 * Pauses visitor comments while a freeze runs, when the site chose to.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Enforcement;

use Digibold\DeploymentGuard\Guard;
use Digibold\DeploymentGuard\Receipt;
use Digibold\DeploymentGuard\Scope;

defined( 'ABSPATH' ) || exit;

/**
 * A comment is a database write like any other: one saved while a
 * deployment or migration runs can be lost when the database is replaced.
 * When the site has chosen to pause comments (see Scope), this refuses new
 * ones with a clear message instead.
 *
 * It hooks `pre_comment_approved`, the one place every new comment passes
 * through however it arrives: the comment form, the REST API, XML-RPC,
 * pingbacks and trackbacks, and `wp comment create`. The check happens when
 * the comment is submitted, never when a page is shown, so full-page caches
 * can neither cache a "comments closed" page that outlives the freeze nor
 * show a form that quietly fails.
 *
 * Only visitor-written comment types are paused. Everything else that
 * lives in the comments table (WooCommerce order notes, editorial notes)
 * is internal bookkeeping and keeps working.
 */
final class CommentGate {

	/**
	 * Comment types a visitor can submit. Filterable.
	 *
	 * @var string[]
	 */
	private const VISITOR_TYPES = array( '', 'comment', 'review', 'pingback', 'trackback' );

	private Guard $guard;

	private Receipt $receipt;

	public function __construct( Guard $guard, Receipt $receipt ) {
		$this->guard   = $guard;
		$this->receipt = $receipt;
	}

	public function register(): void {
		add_filter( 'pre_comment_approved', array( $this, 'filter_approval' ), 1, 2 );
	}

	/**
	 * @param mixed $approved    The approval status so far, or a WP_Error.
	 * @param mixed $commentdata The comment being checked.
	 * @return mixed The same status, or a WP_Error when the comment is refused.
	 */
	public function filter_approval( $approved, $commentdata ) {
		if ( is_wp_error( $approved ) ) {
			return $approved;
		}

		$type = is_array( $commentdata ) && isset( $commentdata['comment_type'] ) && is_string( $commentdata['comment_type'] )
			? $commentdata['comment_type']
			: '';

		/**
		 * Filters the comment types a pause on comments applies to.
		 *
		 * @param string[] $types Comment types a visitor can submit.
		 */
		$types = apply_filters( 'deployment_guard_paused_comment_types', self::VISITOR_TYPES );

		if ( ! is_array( $types ) || ! in_array( $type, $types, true ) ) {
			return $approved;
		}

		if ( ! $this->guard->submissions_paused( Scope::COMMENTS ) ) {
			return $approved;
		}

		$this->receipt->count_paused( Scope::COMMENTS );

		// The numeric error data is the HTTP status wp-comments-post.php
		// answers with; 423 Locked, like the REST API's own freeze errors.
		return new \WP_Error( 'stillgate_comments_paused', $this->guard->visitor_message( Scope::COMMENTS ), 423 );
	}
}
