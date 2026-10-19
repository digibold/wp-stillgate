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
	 * Prefix of the two running counters of refused submissions, one option
	 * per kind. Each refusal is one atomic increment of one small row, so
	 * concurrent refusals are all counted and a counter write can never
	 * overwrite a receipt that another request has just closed.
	 */
	public const COUNTER_PREFIX = 'deployment_guard_paused_';

	/**
	 * How far back an unnoticed scheduled window is still recorded.
	 */
	private const UNOBSERVED_WITHIN = 7 * DAY_IN_SECONDS;

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
	public function on_state_changed( $new_state = null, $old_state = null, $source = 'ui' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the action passes three arguments; the new state is already held by the service.
		$this->close_if_stale( $old_state );
		$this->sync( is_string( $source ) ? $source : 'ui' );
	}

	/**
	 * A receipt still open although the freeze it belonged to ran out
	 * before this change (nobody opened wp-admin to notice, and a CLI or
	 * REST request does not sync): close it where that freeze ended, so the
	 * new freeze gets a receipt of its own instead of inheriting the old
	 * start and counting the idle gap as frozen.
	 *
	 * @param mixed $old_state The state before the change.
	 */
	private function close_if_stale( $old_state ): void {
		$data = $this->data();

		if ( null === $data['opened'] || null !== $data['closed'] || ! is_array( $old_state ) || null !== $this->service->forced() ) {
			return;
		}

		$now = $this->now();
		$old = FreezeConfig::from_array( $old_state );

		if ( $old->is_active( $now ) ) {
			return;
		}

		$this->close( $this->closing_time( $old, $data, $now ) );
	}

	/**
	 * When a freeze that is no longer active ended: at the end of its window
	 * if that is what ran out during this receipt, otherwise now. Never
	 * before the receipt opened, which a leftover window from an earlier
	 * freeze would otherwise cause.
	 *
	 * @param FreezeConfig         $config The configuration the freeze ended under.
	 * @param array<string, mixed> $data   The whole receipt.
	 * @param int                  $now    The current time.
	 */
	private function closing_time( FreezeConfig $config, array $data, int $now ): int {
		$opened = (int) $data['opened'];

		if ( ! $config->enabled && null !== $config->schedule_end && $config->schedule_end <= $now && $config->schedule_end >= $opened ) {
			return $config->schedule_end;
		}

		return max( $opened, $now );
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
		$config = $this->service->config();

		if ( $active && ! $open ) {
			$start = $now;

			if ( ! $config->enabled && 'active' === $config->schedule_status( $now ) && null !== $config->schedule_start ) {
				// A scheduled window is dated from its own start, not from
				// when somebody first noticed it.
				$start = min( $now, $config->schedule_start );
			}

			$this->open( $start, $source );
		} elseif ( ! $active && $open ) {
			$this->close( $this->closing_time( $config, $data, $now ) );
		} elseif ( ! $active ) {
			$this->record_unobserved_window( $config, $data, $now );
		}
	}

	/**
	 * A scheduled window that began and ended between two requests that could
	 * sync leaves no receipt of its own, and the previous freeze's receipt
	 * would then be read as the latest. Record it as a closed receipt that
	 * says nobody was there to see what changed, so a script does not take an
	 * older clean receipt for this freeze.
	 *
	 * @param FreezeConfig         $config The stored configuration.
	 * @param array<string, mixed> $data   The whole receipt.
	 * @param int                  $now    The current time.
	 */
	private function record_unobserved_window( FreezeConfig $config, array $data, int $now ): void {
		$start = $config->schedule_start;
		$end   = $config->schedule_end;

		if ( null === $start || null === $end || $end > $now || $end < $now - self::UNOBSERVED_WITHIN ) {
			return;
		}

		if ( null !== $data['opened'] && $end <= (int) ( $data['closed'] ?? 0 ) ) {
			return;
		}

		$counters = $this->counters();

		$this->save(
			array(
				'v'           => 1,
				'opened'      => $start,
				'closed'      => $end,
				'source'      => 'detected',
				'unverified'  => true,
				'baseline'    => array(),
				'final'       => array( 'paused' => $counters ),
				'paused_base' => $this->paused_base( $data ),
			)
		);
	}

	/**
	 * When the receipt that is still open began, or null when none is.
	 */
	public function opened_at(): ?int {
		$data = $this->data();

		return null !== $data['opened'] && null === $data['closed'] ? (int) $data['opened'] : null;
	}

	/**
	 * Count a visitor submission that was paused during the freeze.
	 *
	 * @param string $kind One of Scope::KINDS.
	 */
	public function count_paused( string $kind ): void {
		if ( ! in_array( $kind, Scope::KINDS, true ) || ! $this->service->is_active() ) {
			return;
		}

		// Never opens a receipt (and so never scans the database) on a
		// visitor's request: the next request that can sync opens it, and
		// takes in what was counted before, because the counters are
		// running totals.
		$this->increment( $kind );
	}

	private function increment( string $kind ): void {
		global $wpdb;

		$name = self::COUNTER_PREFIX . $kind;

		if ( false === get_option( $name, false ) ) {
			add_option( $name, '0', '', false );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic increment is the point: a read-modify-write through update_option would lose counts under concurrent refusals; the cache entry is cleared on the next line.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", $name ) );
		wp_cache_delete( $name, 'options' );
	}

	/**
	 * The running totals of refused submissions.
	 *
	 * @return array<string, int>
	 */
	private function counters(): array {
		$counters = array();

		foreach ( Scope::KINDS as $kind ) {
			$counters[ $kind ] = (int) get_option( self::COUNTER_PREFIX . $kind, 0 );
		}

		return $counters;
	}

	/**
	 * Where a new receipt starts counting: at the totals when the previous
	 * receipt closed, so refusals that happened before anyone noticed the
	 * freeze began belong to the new receipt.
	 *
	 * @param array<string, mixed> $previous The receipt being replaced.
	 * @return array<string, int>
	 */
	private function paused_base( array $previous ): array {
		$base = array();

		foreach ( Scope::KINDS as $kind ) {
			$base[ $kind ] = isset( $previous['final']['paused'][ $kind ] ) ? (int) $previous['final']['paused'][ $kind ] : 0;
		}

		return $base;
	}

	private function open( int $at, string $source ): void {
		$this->save(
			array(
				'v'           => 1,
				'opened'      => $at,
				'closed'      => null,
				'source'      => $source,
				'baseline'    => $this->capture(),
				'final'       => null,
				'paused_base' => $this->paused_base( $this->data() ),
			)
		);
	}

	private function close( int $at ): void {
		$data = $this->data();
		$from = (int) $data['opened'];

		// What changed is fixed at the moment the freeze ends. Read later
		// from the database it would shrink as posts are saved again (a shop
		// recalculating a rating a second after the freeze ended would drop
		// the product from the list a deploy script is about to export).
		$final             = $this->capture();
		$final['changed']  = $this->changed_posts( $from, $at, $this->baseline_max_id( $data ) );
		$final['deleted']  = $this->deleted_estimate( is_array( $data['baseline'] ) ? $data['baseline'] : array(), $final, $from, $at );
		$final['comments'] = $this->comments_added( $from, $at );
		$final['paused']   = $this->counters();

		$data['closed'] = $at;
		$data['final']  = $final;

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

		if ( ! empty( $data['unverified'] ) ) {
			return array(
				'state'      => 'closed',
				'opened_at'  => gmdate( 'c', (int) $data['opened'] ),
				'closed_at'  => gmdate( 'c', (int) $data['closed'] ),
				'source'     => (string) $data['source'],
				'clean'      => null,
				'unverified' => true,
				'content'    => array(
					'changed_posts'    => 0,
					'changed_ids'      => array(),
					'ids_truncated'    => false,
					'deleted_estimate' => 0,
					'terms_changed'    => false,
					'comments_added'   => 0,
				),
				'paused'     => $this->paused_since( $data, $data['final']['paused'] ?? array() ),
			);
		}

		$open    = null === $data['closed'];
		$from    = (int) $data['opened'];
		$until   = $open ? null : (int) $data['closed'];
		$final   = ! $open && is_array( $data['final'] ) ? $data['final'] : null;
		$current = null === $final ? $this->capture() : $final;
		$frozen  = null !== $final && isset( $final['changed'], $final['deleted'], $final['comments'] );
		$changed = $frozen ? $final['changed'] : $this->changed_posts( $from, $until, $this->baseline_max_id( $data ) );
		$deleted = $frozen ? (int) $final['deleted'] : $this->deleted_estimate( $data['baseline'], $current, $from, $until );
		$terms   = $this->terms_changed( $data['baseline'], $current );
		$added   = $frozen ? (int) $final['comments'] : $this->comments_added( $from, $until );

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
			'paused'    => $this->paused_since( $data, null === $final ? $this->counters() : ( $final['paused'] ?? array() ) ),
		);
	}

	/**
	 * Refused submissions during this receipt: the running totals now (or
	 * when it closed) less the totals when it opened.
	 *
	 * @param array<string, mixed> $data   The whole receipt.
	 * @param array<string, mixed> $totals The running totals to compare.
	 * @return array<string, int>
	 */
	private function paused_since( array $data, array $totals ): array {
		$paused = array();

		foreach ( Scope::KINDS as $kind ) {
			$paused[ $kind ] = max( 0, (int) ( $totals[ $kind ] ?? 0 ) - (int) ( $data['paused_base'][ $kind ] ?? 0 ) );
		}

		return $paused;
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

		if ( null !== $data['closed'] && is_array( $data['final'] ) && isset( $data['final']['changed']['ids'] ) ) {
			return array_map( 'intval', (array) $data['final']['changed']['ids'] );
		}

		$until = null === $data['closed'] ? null : (int) $data['closed'];

		return $this->changed_posts( (int) $data['opened'], $until, $this->baseline_max_id( $data ) )['ids'];
	}

	/**
	 * The newest post ID when the receipt opened, if it was recorded.
	 *
	 * @param array<string, mixed> $data The whole receipt.
	 */
	private function baseline_max_id( array $data ): ?int {
		return is_array( $data['baseline'] ) && isset( $data['baseline']['max_id'] ) ? (int) $data['baseline']['max_id'] : null;
	}

	/**
	 * Posts saved, created or trashed since the receipt opened.
	 *
	 * A post is changed when it was modified since then, or when it is newer
	 * than the newest post at that moment. The second test exists because
	 * WordPress stores a brand-new draft with a zero modified time until it
	 * is saved again, which the first test alone cannot see.
	 *
	 * @param int      $from      When the receipt opened.
	 * @param int|null $until     When it closed, or null while it is open.
	 * @param int|null $above_id  The newest post ID when it opened, if known.
	 * @return array{count: int, ids: int[]}
	 */
	private function changed_posts( int $from, ?int $until, ?int $above_id = null ): array {
		global $wpdb;

		$types = $this->post_types();

		if ( ! $types ) {
			return array(
				'count' => 0,
				'ids'   => array(),
			);
		}

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$args         = array_merge( $types, array( gmdate( 'Y-m-d H:i:s', $from ) ) );
		$modified     = 'post_modified_gmt >= %s';

		if ( null !== $until ) {
			$modified .= ' AND post_modified_gmt <= %s';
			$args[]    = gmdate( 'Y-m-d H:i:s', $until );
		}

		$changed = "( {$modified} )";

		if ( null !== $above_id ) {
			$newer  = 'ID > %d';
			$args[] = $above_id;

			if ( null !== $until ) {
				// A receipt noticed late is closed at the time the freeze
				// ended; a post created afterwards is not blamed on it. A
				// brand-new draft has no GMT date to compare, and stays in.
				$newer .= " AND ( post_date_gmt <= %s OR post_date_gmt = '0000-00-00 00:00:00' )";
				$args[] = gmdate( 'Y-m-d H:i:s', $until );
			}

			$changed = "( {$changed} OR ( {$newer} ) )";
		}

		$where = "post_type IN ( {$placeholders} ) AND post_status <> 'auto-draft' AND {$changed}";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- a read-only, one-off report that must see the live table; the dynamic part is only the list of placeholders built above, and every value is passed to prepare().
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
	 * The post types the receipt inspects: by default the ones a freeze
	 * protects.
	 *
	 * @return string[]
	 */
	private function post_types(): array {
		$types = $this->guard->protected_types()['post_types'];

		/**
		 * Filters the post types the freeze receipt inspects.
		 *
		 * A shop whose products change on their own while it sells (stock,
		 * sales counts, ratings) can leave them out so the receipt reports
		 * editorial changes only. This does not change what a freeze
		 * protects; see `deployment_guard_protected_types` for that.
		 *
		 * @param string[] $types Post type names.
		 */
		$filtered = apply_filters( 'deployment_guard_receipt_post_types', $types );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $types;
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

		$types = $this->post_types();

		if ( ! $types ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		if ( isset( $baseline['max_id'] ) ) {
			// Newer than the newest post when the receipt opened: counts a
			// brand-new draft, whose creation dates are zero.
			$args  = array_merge( $types, array( (int) $baseline['max_id'] ) );
			$where = "post_type IN ( {$placeholders} ) AND post_status <> 'auto-draft' AND ID > %d";

			if ( null !== $until ) {
				$where .= " AND ( post_date_gmt <= %s OR post_date_gmt = '0000-00-00 00:00:00' )";
				$args[] = gmdate( 'Y-m-d H:i:s', $until );
			}
		} else {
			$args  = array_merge( $types, array( gmdate( 'Y-m-d H:i:s', $from ) ) );
			$upper = '';

			if ( null !== $until ) {
				$upper  = ' AND post_date_gmt <= %s';
				$args[] = gmdate( 'Y-m-d H:i:s', $until );
			}

			$where = "post_type IN ( {$placeholders} ) AND post_status <> 'auto-draft' AND post_date_gmt >= %s{$upper}";
		}

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
	 * @return array{posts: int, terms: string, max_id: int}
	 */
	private function capture(): array {
		global $wpdb;

		$types      = $this->guard->protected_types();
		$post_types = $this->post_types();
		$post_total = 0;
		$terms      = '';

		if ( $post_types ) {
			$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- read-only one-off snapshot; the interpolated part is only a list of %s placeholders and every value goes through prepare().
			$post_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ( {$placeholders} ) AND post_status <> 'auto-draft'", $post_types ) );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only snapshot; no user input.
		$max_id = (int) $wpdb->get_var( "SELECT COALESCE( MAX( ID ), 0 ) FROM {$wpdb->posts}" );

		return array(
			'posts'  => $post_total,
			'terms'  => $terms,
			'max_id' => $max_id,
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
