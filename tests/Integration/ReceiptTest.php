<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Receipt;

/**
 * The receipt compares posts by modification time since the freeze began,
 * so these tests use the real clock, and every fixture is dated an hour
 * back to sit clearly before the freeze.
 */
final class ReceiptTest extends TestCase {

	private FreezeService $service;

	private Receipt $receipt;

	/** @var array{now: int} */
	private array $clock = array( 'now' => 0 );

	public function set_up(): void {
		parent::set_up();

		$this->clock['now'] = time();
		$clock              = function (): int {
			return $this->clock['now'];
		};

		$this->service = new FreezeService( $clock, new \Digibold\DeploymentGuard\Suspension() );
		$guard         = $this->make_guard( $this->service );
		$this->receipt = new Receipt( $this->service, $guard, $clock );

		// The plugin's own receipt (real clock) is hooked up by the test
		// bootstrap too; these tests drive time themselves.
		remove_all_actions( 'deployment_guard_state_changed' );
		$this->receipt->register();
		$this->login_as( 'administrator' );
	}

	private function old_post( string $title = 'Old' ): int {
		$when = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		return self::factory()->post->create(
			array(
				'post_title'        => $title,
				'post_date'         => $when,
				'post_date_gmt'     => $when,
				'post_modified'     => $when,
				'post_modified_gmt' => $when,
			)
		);
	}

	public function test_there_is_no_receipt_before_any_freeze(): void {
		$this->assertSame( 'none', $this->receipt->report()['state'] );
		$this->assertSame( array(), $this->receipt->changed_ids() );
	}

	public function test_a_freeze_that_held_leaves_a_clean_receipt(): void {
		$this->old_post();
		$this->old_post();

		$this->service->enable( 'cli' );
		$open = $this->receipt->report();

		$this->assertSame( 'open', $open['state'] );
		$this->assertSame( 'cli', $open['source'] );
		$this->assertTrue( $open['clean'] );
		$this->assertNull( $open['closed_at'] );

		$this->service->disable( 'cli' );
		$closed = $this->receipt->report();

		$this->assertSame( 'closed', $closed['state'] );
		$this->assertTrue( $closed['clean'] );
		$this->assertSame( 0, $closed['content']['changed_posts'] );
		$this->assertSame( array(), $closed['content']['changed_ids'] );
		$this->assertFalse( $closed['content']['terms_changed'] );
		$this->assertNotNull( $closed['closed_at'] );
	}

	public function test_a_post_saved_during_the_freeze_is_listed(): void {
		$changed   = $this->old_post( 'Will change' );
		$untouched = $this->old_post( 'Stays' );

		$this->service->enable();
		wp_update_post( array( 'ID' => $changed, 'post_title' => 'Changed by a bypass user' ) );

		$report = $this->receipt->report();

		$this->assertFalse( $report['clean'] );
		$this->assertSame( 1, $report['content']['changed_posts'] );
		$this->assertSame( array( $changed ), $report['content']['changed_ids'] );
		$this->assertSame( array( $changed ), $this->receipt->changed_ids() );
		$this->assertNotContains( $untouched, $report['content']['changed_ids'] );
	}

	public function test_a_new_draft_is_listed_although_wordpress_stores_it_with_a_zero_modified_time(): void {
		$this->old_post();
		$this->service->enable();

		$draft = self::factory()->post->create( array( 'post_status' => 'draft', 'post_title' => 'Drafted during the freeze', 'post_date' => '', 'post_date_gmt' => '' ) );

		global $wpdb;
		// WordPress leaves a new draft's GMT dates at zero until it is saved
		// again; make sure the fixture really looks like that.
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '0000-00-00 00:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ), array( 'ID' => $draft ) );
		clean_post_cache( $draft );

		$report = $this->receipt->report();

		$this->assertContains( $draft, $report['content']['changed_ids'] );
		$this->assertFalse( $report['clean'] );
	}

	public function test_a_new_draft_does_not_hide_a_deletion_in_the_estimate(): void {
		$doomed = $this->old_post( 'Will be deleted' );
		$this->old_post( 'Stays' );

		$this->service->enable();
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '0000-00-00 00:00:00', 'post_date_gmt' => '0000-00-00 00:00:00' ), array( 'ID' => $draft ) );
		wp_delete_post( $doomed, true );

		$this->assertSame( 1, $this->receipt->report()['content']['deleted_estimate'] );
	}

	public function test_a_new_post_created_during_the_freeze_is_listed(): void {
		$this->service->enable();
		$new = self::factory()->post->create( array( 'post_title' => 'Created during' ) );

		$report = $this->receipt->report();

		$this->assertContains( $new, $report['content']['changed_ids'] );
		$this->assertFalse( $report['clean'] );
	}

	public function test_auto_drafts_and_revisions_are_not_counted(): void {
		$post = $this->old_post();
		$this->service->enable();

		self::factory()->post->create( array( 'post_status' => 'auto-draft' ) );
		wp_save_post_revision( $post );

		$this->assertTrue( $this->receipt->report()['clean'] );
	}

	public function test_a_deleted_post_is_estimated(): void {
		$keep = $this->old_post();
		$gone = $this->old_post();

		$this->service->enable();
		wp_delete_post( $gone, true );

		$report = $this->receipt->report();

		$this->assertSame( 1, $report['content']['deleted_estimate'] );
		$this->assertFalse( $report['clean'] );
		$this->assertNotNull( get_post( $keep ) );
	}

	public function test_a_post_created_and_another_deleted_do_not_cancel_out(): void {
		$gone = $this->old_post();
		$this->service->enable();

		self::factory()->post->create();
		wp_delete_post( $gone, true );

		$report = $this->receipt->report();

		$this->assertSame( 1, $report['content']['deleted_estimate'] );
		$this->assertSame( 1, $report['content']['changed_posts'] );
	}

	public function test_a_term_created_or_renamed_during_the_freeze_is_detected(): void {
		$term = wp_insert_term( 'Before', 'category' );
		$this->service->enable();

		$this->assertFalse( $this->receipt->report()['content']['terms_changed'] );

		wp_update_term( $term['term_id'], 'category', array( 'name' => 'After' ) );

		$this->assertTrue( $this->receipt->report()['content']['terms_changed'] );
		$this->assertFalse( $this->receipt->report()['clean'] );
	}

	public function test_a_new_term_is_detected(): void {
		$this->service->enable();
		wp_insert_term( 'Brand new', 'post_tag' );

		$this->assertTrue( $this->receipt->report()['content']['terms_changed'] );
	}

	public function test_visitor_comments_added_during_the_freeze_are_counted(): void {
		$post = $this->old_post();
		$this->service->enable();

		self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_approved' => '1' ) );
		self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_approved' => 'spam' ) );
		self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_approved' => '1', 'comment_type' => 'order_note' ) );

		$this->assertSame( 1, $this->receipt->report()['content']['comments_added'] );
	}

	public function test_changes_after_the_freeze_ended_are_not_blamed_on_it(): void {
		$post = $this->old_post();

		$this->service->enable();
		$this->service->disable();
		$closed_at = $this->receipt->report()['closed_at'];

		// Editing resumes: the receipt is closed at its end time.
		$this->clock['now'] += 5;
		sleep( 2 );
		wp_update_post( array( 'ID' => $post, 'post_title' => 'Edited afterwards' ) );

		$report = $this->receipt->report();

		$this->assertSame( 'closed', $report['state'] );
		$this->assertSame( $closed_at, $report['closed_at'] );
		$this->assertSame( 0, $report['content']['changed_posts'] );
	}

	public function test_a_closed_receipt_keeps_what_changed_when_the_posts_are_saved_again(): void {
		$post = $this->old_post();

		$this->service->enable();
		wp_update_post( array( 'ID' => $post, 'post_title' => 'Changed during' ) );
		$this->service->disable();

		$this->clock['now'] += 5;
		sleep( 2 );
		// A shop recalculating a rating, an editor fixing a typo: the post is
		// saved again after the freeze, and must stay on the list of what
		// the freeze let through.
		wp_update_post( array( 'ID' => $post, 'post_title' => 'Saved again afterwards' ) );

		$report = $this->receipt->report();

		$this->assertSame( 1, $report['content']['changed_posts'] );
		$this->assertSame( array( $post ), $report['content']['changed_ids'] );
		$this->assertSame( array( $post ), $this->receipt->changed_ids() );
		$this->assertFalse( $report['clean'] );
	}

	public function test_a_closed_receipt_keeps_its_comment_count_when_a_comment_is_deleted_later(): void {
		$post = $this->old_post();

		$this->service->enable();
		$comment = self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_approved' => '1' ) );
		$this->service->disable();

		wp_delete_comment( $comment, true );

		$this->assertSame( 1, $this->receipt->report()['content']['comments_added'] );
	}

	public function test_the_post_types_the_receipt_inspects_can_be_narrowed_with_a_filter(): void {
		$post = $this->old_post();
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_date' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );

		add_filter(
			'deployment_guard_receipt_post_types',
			static fn ( array $types ): array => array_values( array_diff( $types, array( 'post' ) ) )
		);

		$this->service->enable();
		wp_update_post( array( 'ID' => $post, 'post_title' => 'A product-like change the shop owner does not care about' ) );
		wp_update_post( array( 'ID' => $page, 'post_title' => 'A page edit' ) );

		$report = $this->receipt->report();

		$this->assertSame( array( $page ), $report['content']['changed_ids'] );
		$this->assertSame( 1, $report['content']['changed_posts'] );
	}

	public function test_a_broken_receipt_post_types_filter_falls_back_to_the_protected_types(): void {
		$post = $this->old_post();
		add_filter( 'deployment_guard_receipt_post_types', static fn () => 'nonsense' );

		$this->service->enable();
		wp_update_post( array( 'ID' => $post, 'post_title' => 'Changed' ) );

		$this->assertSame( array( $post ), $this->receipt->report()['content']['changed_ids'] );
	}

	public function test_a_leftover_expired_window_does_not_close_a_later_manual_freeze_before_it_opened(): void {
		$post = $this->old_post();

		// A timed freeze ran days ago and its window stays stored.
		$this->clock['now'] = time() - 3 * DAY_IN_SECONDS;
		$this->service->freeze_for( 600 );
		$this->clock['now'] = time();
		$this->receipt->sync();

		$this->service->enable();
		wp_update_post( array( 'ID' => $post, 'post_title' => 'Edited during the later freeze' ) );
		$this->service->disable();

		$report = $this->receipt->report();

		$this->assertGreaterThanOrEqual( strtotime( $report['opened_at'] ), strtotime( $report['closed_at'] ), 'a receipt never closes before it opened' );
		$this->assertSame( array( $post ), $report['content']['changed_ids'] );
		$this->assertFalse( $report['clean'] );
	}

	public function test_a_receipt_left_open_by_an_expired_window_is_closed_when_the_next_freeze_begins(): void {
		$post = $this->old_post();

		// Nobody opened wp-admin, so nothing synced when the timer ran out.
		$this->clock['now'] = time() - 3 * HOUR_IN_SECONDS;
		$this->service->freeze_for( 1800 );
		$old_opened = $this->receipt->report()['opened_at'];

		wp_update_post( array( 'ID' => $post, 'post_title' => 'Edited while nothing was frozen' ) );

		// Saved long before the second freeze: a few minutes is plenty.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1800 ) ), array( 'ID' => $post ) );
		clean_post_cache( $post );

		$this->clock['now'] = time();
		$this->service->freeze_for( 1800 );

		$report = $this->receipt->report();

		$this->assertNotSame( $old_opened, $report['opened_at'], 'the new freeze has a receipt of its own' );
		$this->assertSame( 'open', $report['state'] );
		$this->assertTrue( $report['clean'], 'the idle gap is not counted as frozen' );
	}

	public function test_a_window_nobody_saw_is_recorded_as_unverified_instead_of_leaving_an_older_receipt_standing(): void {
		$base               = time() - 2 * HOUR_IN_SECONDS;
		$this->clock['now'] = $base;
		$this->service->schedule( $base + 60, $base + 600 );
		$this->assertSame( 'none', $this->receipt->report()['state'] );

		$this->clock['now'] = time();
		$this->receipt->sync();

		$report = $this->receipt->report();

		$this->assertSame( 'closed', $report['state'] );
		$this->assertTrue( $report['unverified'] );
		$this->assertNull( $report['clean'], 'nothing can be promised about it' );
		$this->assertSame( gmdate( 'c', $base + 60 ), $report['opened_at'] );
		$this->assertSame( gmdate( 'c', $base + 600 ), $report['closed_at'] );

		$this->receipt->sync();
		$this->assertSame( $report, $this->receipt->report(), 'recorded once' );
	}

	public function test_an_old_window_is_not_recorded_as_unverified(): void {
		$base               = time() - 10 * DAY_IN_SECONDS;
		$this->clock['now'] = $base;
		$this->service->schedule( $base + 60, $base + 600 );

		$this->clock['now'] = time();
		$this->receipt->sync();

		$this->assertSame( 'none', $this->receipt->report()['state'] );
	}

	public function test_a_window_the_receipt_already_covers_is_not_recorded_twice(): void {
		$this->clock['now'] = time() - HOUR_IN_SECONDS;
		$this->service->freeze_for( 600 );
		$this->clock['now'] = time();
		$this->receipt->sync();

		$report = $this->receipt->report();

		$this->receipt->sync();

		$this->assertSame( $report, $this->receipt->report() );
		$this->assertArrayNotHasKey( 'unverified', $report );
	}

	public function test_counting_a_refusal_never_opens_a_receipt_and_the_counts_are_kept_for_the_next_one(): void {
		$this->service->enable();
		delete_option( Receipt::OPTION );
		$this->receipt->forget();

		$this->receipt->count_paused( 'forms' );
		$this->receipt->count_paused( 'forms' );

		$this->assertSame( 'none', $this->receipt->report()['state'], 'nothing is scanned on a visitor request' );

		$this->receipt->sync();

		$this->assertSame( 2, $this->receipt->report()['paused']['forms'] );
	}

	public function test_each_receipt_counts_only_its_own_refusals(): void {
		$this->service->enable();
		$this->receipt->count_paused( 'comments' );
		$this->receipt->count_paused( 'comments' );
		$this->service->disable();

		$this->assertSame( 2, $this->receipt->report()['paused']['comments'] );

		$this->service->enable();
		$this->receipt->count_paused( 'comments' );

		$this->assertSame( 1, $this->receipt->report()['paused']['comments'] );
	}

	public function test_a_closed_receipt_keeps_its_count_when_more_are_refused_afterwards(): void {
		$this->service->enable();
		$this->receipt->count_paused( 'forms' );
		$this->service->disable();
		$this->receipt->count_paused( 'forms' );

		$this->assertSame( 1, $this->receipt->report()['paused']['forms'] );
	}

	public function test_the_counters_are_single_non_autoloaded_rows_incremented_in_place(): void {
		$this->service->enable();
		$this->receipt->count_paused( 'forms' );
		$this->receipt->count_paused( 'forms' );
		$this->receipt->count_paused( 'forms' );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", 'deployment_guard_paused_forms' ), ARRAY_A );

		$this->assertSame( '3', $row['option_value'] );
		$this->assertNotSame( 'yes', $row['autoload'] );
	}

	public function test_a_receipt_closed_late_does_not_blame_posts_created_after_the_freeze_ended(): void {
		$this->clock['now'] = time() - HOUR_IN_SECONDS;
		$this->service->freeze_for( 600 );

		// The window ended 50 minutes ago; this is created now.
		$late = self::factory()->post->create( array( 'post_title' => 'Created long after the freeze' ) );

		$this->clock['now'] = time();
		$this->receipt->sync();

		$report = $this->receipt->report();

		$this->assertNotContains( $late, $report['content']['changed_ids'] );
		$this->assertTrue( $report['clean'] );
	}

	public function test_a_new_freeze_starts_a_new_receipt(): void {
		$post = $this->old_post();

		$this->service->enable();
		wp_update_post( array( 'ID' => $post, 'post_title' => 'Changed' ) );
		$this->service->disable();
		$this->assertFalse( $this->receipt->report()['clean'] );

		$this->clock['now'] += 10;
		sleep( 2 );
		$this->service->enable();

		$report = $this->receipt->report();
		$this->assertSame( 'open', $report['state'] );
		$this->assertTrue( $report['clean'], 'the earlier change belongs to the earlier receipt' );
	}

	public function test_a_timed_freeze_keeps_one_receipt_when_extended_or_replaced(): void {
		$this->service->freeze_for( 600 );
		$opened = $this->receipt->report()['opened_at'];

		$this->service->extend( 300 );
		$this->service->freeze_for( 1200 );

		$this->assertSame( $opened, $this->receipt->report()['opened_at'] );
		$this->assertSame( 'open', $this->receipt->report()['state'] );
	}

	public function test_a_window_that_runs_out_is_closed_at_its_end_not_when_noticed(): void {
		$this->service->freeze_for( 600 );
		$end = $this->clock['now'] + 600;

		// Nobody looked until long after the window ended.
		$this->clock['now'] += 4000;
		$this->receipt->sync();

		$report = $this->receipt->report();
		$this->assertSame( 'closed', $report['state'] );
		$this->assertSame( gmdate( 'c', $end ), $report['closed_at'] );
	}

	public function test_a_scheduled_window_is_dated_from_its_own_start(): void {
		$start = $this->clock['now'] + 300;
		$this->service->schedule( $start, $start + 600 );
		$this->assertSame( 'none', $this->receipt->report()['state'], 'not started yet' );

		// Nobody does anything until the first admin request after the
		// window began, 150 seconds in.
		$this->clock['now'] = $start + 150;
		$this->receipt->maybe_sync();

		$report = $this->receipt->report();
		$this->assertSame( 'open', $report['state'] );
		$this->assertSame( 'detected', $report['source'] );
		$this->assertSame( gmdate( 'c', $start ), $report['opened_at'] );
	}

	public function test_an_anonymous_request_never_syncs(): void {
		$start = $this->clock['now'] + 300;
		$this->service->schedule( $start, $start + 600 );
		$this->clock['now'] = $start + 150;
		wp_set_current_user( 0 );

		$this->receipt->maybe_sync();

		$this->assertSame( 'none', $this->receipt->report()['state'] );
	}

	public function test_scheduling_a_window_that_is_already_running_opens_the_receipt_at_once(): void {
		$start = $this->clock['now'] - 300;
		$this->service->schedule( $start, $this->clock['now'] + 300 );

		$report = $this->receipt->report();

		$this->assertSame( 'open', $report['state'] );
		$this->assertSame( 'ui', $report['source'] );
		$this->assertSame( gmdate( 'c', $start ), $report['opened_at'] );
	}

	public function test_deactivation_style_reset_closes_the_receipt(): void {
		$this->service->enable();
		$this->service->reset( 'deactivation' );

		$this->assertSame( 'closed', $this->receipt->report()['state'] );
	}

	public function test_paused_submissions_are_only_counted_while_a_freeze_runs(): void {
		$this->receipt->count_paused( 'comments' );
		$this->assertSame( 'none', $this->receipt->report()['state'] );

		$this->service->enable();
		$this->receipt->count_paused( 'comments' );
		$this->receipt->count_paused( 'forms' );
		$this->receipt->count_paused( 'forms' );
		$this->receipt->count_paused( 'users' );

		$paused = $this->receipt->report()['paused'];
		$this->assertSame( 1, $paused['comments'] );
		$this->assertSame( 2, $paused['forms'] );

		$this->service->disable();
		$this->receipt->count_paused( 'forms' );
		$this->assertSame( 2, $this->receipt->report()['paused']['forms'], 'nothing is counted once it ended' );
	}

	public function test_the_receipt_is_a_single_non_autoloaded_option(): void {
		$this->service->enable();

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Receipt::OPTION ) );

		$this->assertContains( $autoload, array( 'off', 'no' ) );
		$this->assertIsArray( get_option( Receipt::OPTION ) );
	}

	public function test_the_report_is_pure_data_with_no_secrets(): void {
		$this->service->enable();
		$json = wp_json_encode( $this->receipt->report() );

		$this->assertStringNotContainsString( 'password', strtolower( (string) $json ) );
		$this->assertSame( array( 'state', 'opened_at', 'closed_at', 'source', 'clean', 'content', 'paused' ), array_keys( $this->receipt->report() ) );
	}
}
