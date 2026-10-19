<?php
/**
 * The freeze receipt: what happened to the content while a freeze ran.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the question every deployment asks afterwards: "did anything
 * change while we had it frozen?" A freeze that held leaves a clean
 * receipt, which tells a deploy script the database it dumped at the
 * start is still complete. When something did change (a bypass user, a
 * plugin, direct database work) the receipt lists exactly which posts, so
 * they can be carried over deliberately, for example with WordPress's own
 * `wp export --post__in=...` and `wp import`, instead of being lost.
 *
 * It is a detector, not a synchronizer: it only reads. A receipt is opened
 * when a freeze begins and closed when it ends, and kept until the next
 * freeze replaces it. Posts are compared by their modification time since
 * the freeze began, which is exact for anything saved through WordPress.
 * Terms are compared by a checksum, deletions are estimated from row
 * counts, and visitor comments and form submissions that were paused are
 * counted. Rows written behind WordPress's back without touching the
 * modification time cannot be seen; the documentation says so.
 *
 * Nothing here runs on an ordinary visitor page view: a freeze that began
 * on a schedule is noticed on the next admin, API or CLI request, and its
 * start time is taken from the schedule, not from when it was noticed.
 */
final class Receipt {

	public const OPTION = 'deployment_guard_receipt';

	/**
	 * Most changed post IDs the receipt lists; the count is always exact.
	 */
	public const MAX_LISTED_IDS = 200;

	private FreezeService $service;

	private Guard $guard;

	private \Closure $clock;

	/** @var array<string, mixed>|null */
	private ?array $data = null;

	public function __construct( FreezeService $service, Guard $guard, \Closure $clock ) {
		$this->service = $service;
		$this->guard   = $guard;
		$this->clock   = $clock;
	}

	public function register(): void {
		add_action( 'deployment_guard_state_changed', array( $this, 'on_state_changed' ), 5, 3 );
		add_action( 'admin_init', array( $this, 'maybe_sync' ), 1 );
		add_action( 'switch_blog', array( $this, 'forget' ) );
		add_action( 'update_option_' . self::OPTION, array( $this, 'forget' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'forget' ) );
		add_action( 'delete_option_' . self::OPTION, array( $this, 'forget' ) );
	}

	public function forget(): void {
		$this->data = null;
	}

	/**
	 * A deliberate change of the freeze (settings screen, WP-CLI, REST,
	 * deactivation): open or close the receipt to match.
	 *
	 * @param mixed  $new_state Unused; the service already holds the new state.
	 * @param mixed  $old_state Unused.
	 * @param string $source    Where the change came from.
	 */
	public function on_state_changed( $new_state = null, $old_state = null, $source = 'ui' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the action passes three arguments; only the source matters.
		$this->sync( is_string( $source ) ? $source : 'ui' );
	}

	/**
	 * Catch up with a freeze that began or ended without a deliberate
	 * change: a scheduled window starting or expiring, or the
	 * DEPLOYMENT_GUARD_FORCE constant. Runs for logged-in users in wp-admin
	 * only, never for anonymous visitors.
	 */
	public function maybe_sync(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$this->sync();
	}

	/**
	 * Open the receipt when a freeze is active and none is open; close it
	 * when no freeze is active and one is open.
	 */
	public function sync( string $source = 'detected' ): void {
		$now    = $this->now();
		$active = $this->service->is_active();
		$data   = $this->data();
		$open   = null !== $data['opened'] && null === $data['closed'];

		if ( $active && ! $open ) {
			$config = $this->service->config();
			$start  = $now;

			if ( ! $config->enabled && 'active' === $config->schedule_status( $now ) && null !== $config->schedule_start ) {
				// A scheduled window is dated from its own start, not from
				// when somebody first noticed it.
				$start = min( $now, $config->schedule_start );
			}

			$this->open( $start, $source );
		} elseif ( ! $active && $open ) {
			$config = $this->service->config();
			$end    = $now;

			if ( null !== $config->schedule_end && $config->schedule_end <= $now ) {
				// A window that ran out ended when it said it would.
				$end = $config->schedule_end;
			}

			$this->close( $end );
		}
	}

	/**
	 * Count a visitor submission that was paused during the freeze.
	 *
	 * @param string $kind One of Scope::KINDS.
	 */
	public function count_paused( string $kind ): void {
		if ( ! in_array( $kind, Scope::KINDS, true ) ) {
			return;
		}

		$this->sync();

		$data = $this->data();

		if ( null === $data['opened'] || null !== $data['closed'] ) {
			return;
		}

		$data['paused'][ $kind ] = (int) ( $data['paused'][ $kind ] ?? 0 ) + 1;
		$this->save( $data );
	}

	private function open( int $at, string $source ): void {
		$this->save(
			array(
				'v'        => 1,
				'opened'   => $at,
				'closed'   => null,
				'source'   => $source,
				'baseline' => $this->capture(),
				'final'    => null,
				'paused'   => array(
					Scope::COMMENTS => 0,
					Scope::FORMS    => 0,
				),
			)
		);
	}

	private function close( int $at ): void {
		$data = $this->data();

		$data['closed'] = $at;
		$data['final']  = $this->capture();

		$this->save( $data );
	}

	/**
	 * The current receipt, computed fresh against the database.
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array {
		$data = $this->data();

		if ( null === $data['opened'] ) {
			return array(
				'state' => 'none',
				'clean' => null,
			);
		}

		$open    = null === $data['closed'];
		$from    = (int) $data['opened'];
		$until   = $open ? null : (int) $data['closed'];
		$current = $open || ! is_array( $data['final'] ) ? $this->capture() : $data['final'];
		$changed = $this->changed_posts( $from, $until );
		$deleted = $this->deleted_estimate( $data['baseline'], $current, $from, $until );
		$terms   = $this->terms_changed( $data['baseline'], $current );
		$added   = $this->comments_added( $from, $until );

		return array(
			'state'     => $open ? 'open' : 'closed',
			'opened_at' => gmdate( 'c', $from ),
			'closed_at' => null !== $until ? gmdate( 'c', $until ) : null,
			'source'    => (string) $data['source'],
			'clean'     => 0 === $changed['count'] && 0 === $deleted && ! $terms && 0 === $added,
			'content'   => array(
				'changed_posts'    => $changed['count'],
				'changed_ids'      => $changed['ids'],
				'ids_truncated'    => $changed['count'] > count( $changed['ids'] ),
				'deleted_estimate' => $deleted,
				'terms_changed'    => $terms,
				'comments_added'   => $added,
			),
			'paused'    => array(
				Scope::COMMENTS => (int) ( $data['paused'][ Scope::COMMENTS ] ?? 0 ),
				Scope::FORMS    => (int) ( $data['paused'][ Scope::FORMS ] ?? 0 ),
			),
		);
	}

	/**
	 * Post IDs changed since the receipt opened, for piping into
	 * `wp export --post__in=`. Exact count, at most MAX_LISTED_IDS IDs.
	 *
	 * @return int[]
	 */
	public function changed_ids(): array {
		$data = $this->data();

		if ( null === $data['opened'] ) {
			return array();
		}

		$until = null === $data['closed'] ? null : (int) $data['closed'];

		return $this->changed_posts( (int) $data['opened'], $until )['ids'];
	}

	/**
	 * @return array{count: int, ids: int[]}
	 */
	private function changed_posts( int $from, ?int $until ): array {
		global $wpdb;

		$types = $this->guard->protected_types()['post_types'];

		if ( ! $types ) {
			return array(
				'count' => 0,
				'ids'   => array(),
			);
		}

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$args         = array_merge( $types, array( gmdate( 'Y-m-d H:i:s', $from ) ) );
		$upper        = '';

		if ( null !== $until ) {
			$upper  = ' AND post_modified_gmt <= %s';
			$args[] = gmdate( 'Y-m-d H:i:s', $until );
		}

		$where = "post_type IN ( {$placeholders} ) AND post_status <> 'auto-draft' AND post_modified_gmt >= %s{$upper}";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- a read-only, one-off report that must see the live table; the dynamic part is only the list of %s placeholders built above, and every value is passed to prepare().
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where}", $args ) );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE {$where} ORDER BY ID ASC LIMIT %d",
				array_merge( $args, array( self::MAX_LISTED_IDS ) )
			)
		);
		// phpcs:enable

		return array(
			'count' => $count,
			'ids'   => array_map( 'intval', is_array( $ids ) ? $ids : array() ),
		);
	}

	/**
	 * Posts that were removed outright: the baseline total plus what was
	 * created since, minus what is there now. An estimate, because a row
	 * deleted from the database leaves nothing to inspect.
	 *
	 * @param array<string, mixed> $baseline Counts captured when the receipt opened.
	 * @param array<string, mixed> $current  Counts now (or when it closed).
	 */
	private function deleted_estimate( array $baseline, array $current, int $from, ?int $until ): int {
		global $wpdb;

		$types = $this->guard->protected_types()['post_types'];

		if ( ! $types ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$args         = array_merge( $types, array( gmdate( 'Y-m-d H:i:s', $from ) ) );
		$upper        = '';

		if ( null !== $until ) {
			$upper  = ' AND post_date_gmt <= %s';
			$args[] = gmdate( 'Y-m-d H:i:s', $until );
		}

		$where = "post_type IN ( {$placeholders} ) AND post_status <> 'auto-draft' AND post_date_gmt >= %s{$upper}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- read-only one-off report; see changed_posts().
		$created = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where}", $args ) );

		return max( 0, (int) ( $baseline['posts'] ?? 0 ) + $created - (int) ( $current['posts'] ?? 0 ) );
	}

	/**
	 * @param array<string, mixed> $baseline Counts captured when the receipt opened.
	 * @param array<string, mixed> $current  Counts now (or when it closed).
	 */
	private function terms_changed( array $baseline, array $current ): bool {
		return (string) ( $baseline['terms'] ?? '' ) !== (string) ( $current['terms'] ?? '' );
	}

	private function comments_added( int $from, ?int $until ): int {
		global $wpdb;

		$args  = array( gmdate( 'Y-m-d H:i:s', $from ) );
		$upper = '';

		if ( null !== $until ) {
			$upper  = ' AND comment_date_gmt <= %s';
			$args[] = gmdate( 'Y-m-d H:i:s', $until );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- read-only one-off report; only a fixed optional clause is interpolated, and every value goes through prepare().
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type IN ( 'comment', 'review', 'pingback', 'trackback' ) AND comment_approved NOT IN ( 'spam', 'trash' ) AND comment_date_gmt >= %s{$upper}",
				$args
			)
		);
		// phpcs:enable
	}

	/**
	 * A cheap picture of the content right now: the number of protected
	 * posts and a checksum of the protected terms.
	 *
	 * @return array{posts: int, terms: string}
	 */
	private function capture(): array {
		global $wpdb;

		$types      = $this->guard->protected_types();
		$post_total = 0;
		$terms      = '';

		if ( $types['post_types'] ) {
			$placeholders = implode( ', ', array_fill( 0, count( $types['post_types'] ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- read-only one-off snapshot; the interpolated part is only a list of %s placeholders and every value goes through prepare().
			$post_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ( {$placeholders} ) AND post_status <> 'auto-draft'", $types['post_types'] ) );
		}

		if ( $types['taxonomies'] ) {
			$placeholders = implode( ', ', array_fill( 0, count( $types['taxonomies'] ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- read-only one-off snapshot; see above.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS n, COALESCE( BIT_XOR( CRC32( CONCAT_WS( '|', t.term_id, tt.taxonomy, tt.parent, t.name, t.slug ) ) ), 0 ) AS x FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy IN ( {$placeholders} )",
					$types['taxonomies']
				),
				ARRAY_A
			);
			// phpcs:enable

			if ( is_array( $row ) ) {
				$terms = ( (int) $row['n'] ) . ':' . $row['x'];
			}
		}

		return array(
			'posts' => $post_total,
			'terms' => $terms,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function data(): array {
		if ( null === $this->data ) {
			$stored = get_option( self::OPTION, array() );

			$this->data = array_merge(
				array(
					'v'        => 1,
					'opened'   => null,
					'closed'   => null,
					'source'   => '',
					'baseline' => array(),
					'final'    => null,
					'paused'   => array(
						Scope::COMMENTS => 0,
						Scope::FORMS    => 0,
					),
				),
				is_array( $stored ) ? $stored : array()
			);
		}

		return $this->data;
	}

	/**
	 * @param array<string, mixed> $data The whole receipt.
	 */
	private function save( array $data ): void {
		$this->data = $data;
		update_option( self::OPTION, $data, false );
	}

	private function now(): int {
		return ( $this->clock )();
	}
}
