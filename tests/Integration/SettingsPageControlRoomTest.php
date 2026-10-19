<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Admin\SettingsPage;
use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Receipt;
use Digibold\DeploymentGuard\Scope;

/**
 * The timed-freeze, extend and scope operations of the settings screen, and
 * the control-room layout around them.
 */
final class SettingsPageControlRoomTest extends TestCase {

	private FreezeService $service;

	private Receipt $receipt;

	private SettingsPage $page;

	public function set_up(): void {
		parent::set_up();

		$this->service = $this->make_service( 0 );
		$guard         = $this->make_guard( $this->service );
		$this->receipt = new Receipt( $this->service, $guard, static fn (): int => time() );
		$this->receipt->register();
		$this->page = new SettingsPage( $this->service, $guard, $this->receipt, 'http://example.test/assets/' );
		$this->login_as( 'administrator' );
	}

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();

		parent::tear_down();
	}

	private function submit( array $data ): string {
		$data     = array_merge( array( '_wpnonce' => wp_create_nonce( 'deployment_guard_update' ) ), $data );
		$_POST    = $data;
		$_REQUEST = $data;

		return $this->page->resolve_redirect_url();
	}

	private function html(): string {
		ob_start();
		$this->page->render();

		return ob_get_clean();
	}

	public function test_freeze_for_starts_a_timed_freeze(): void {
		$location = $this->submit( array( 'operation' => 'freeze_for', 'duration' => '30', 'confirm' => '1' ) );

		$this->assertStringContainsString( 'dg_result=timed', $location );
		$this->assertTrue( $this->service->is_active() );
		$this->assertEqualsWithDelta( time() + 1800, $this->service->ends_at(), 5 );
	}

	public function test_freeze_for_needs_the_confirmation_box(): void {
		$location = $this->submit( array( 'operation' => 'freeze_for', 'duration' => '30' ) );

		$this->assertStringContainsString( 'dg_error=confirmation_required', $location );
		$this->assertFalse( $this->service->is_active() );
	}

	public function test_freeze_for_until_i_end_it_is_a_manual_freeze(): void {
		$location = $this->submit( array( 'operation' => 'freeze_for', 'duration' => 'manual', 'confirm' => '1' ) );

		$this->assertStringContainsString( 'dg_result=enabled', $location );
		$this->assertTrue( $this->service->config()->enabled );
	}

	/**
	 * @dataProvider provide_bad_durations
	 */
	public function test_freeze_for_rejects_a_bad_duration( string $duration, string $expected_error ): void {
		$location = $this->submit( array( 'operation' => 'freeze_for', 'duration' => $duration, 'confirm' => '1' ) );

		$this->assertStringContainsString( 'dg_error=' . $expected_error, $location );
		$this->assertFalse( $this->service->is_active() );
	}

	public static function provide_bad_durations(): array {
		return array(
			'empty'     => array( '', 'invalid_duration' ),
			'zero'      => array( '0', 'invalid_duration' ),
			'words'     => array( 'soon', 'invalid_duration' ),
			'too long'  => array( (string) ( 8 * 24 * 60 ), 'window_too_long' ),
		);
	}

	public function test_freeze_for_saves_an_edited_message_first(): void {
		$this->submit( array( 'operation' => 'freeze_for', 'duration' => '15', 'confirm' => '1', 'message' => 'Release 12, back soon.' ) );

		$this->assertSame( 'Release 12, back soon.', $this->service->config()->message );
		$this->assertTrue( $this->service->is_active() );
	}

	public function test_a_message_that_is_too_long_stops_the_freeze_from_starting(): void {
		$location = $this->submit( array( 'operation' => 'freeze_for', 'duration' => '15', 'confirm' => '1', 'message' => str_repeat( 'x', 501 ) ) );

		$this->assertStringContainsString( 'dg_error=message_too_long', $location );
		$this->assertFalse( $this->service->is_active() );
	}

	public function test_an_unchanged_message_is_not_logged_again(): void {
		$this->service->set_message( 'Same message' );
		$entries = count( $this->service->entries() );

		$this->submit( array( 'operation' => 'freeze_for', 'duration' => '15', 'confirm' => '1', 'message' => 'Same message' ) );

		$types = array_column( array_slice( $this->service->entries(), 0, count( $this->service->entries() ) - $entries ), 'type' );
		$this->assertNotContains( 'message_updated', $types );
	}

	public function test_extend_pushes_the_end_back(): void {
		$this->service->freeze_for( 600 );
		$before = $this->service->ends_at();

		$location = $this->submit( array( 'operation' => 'extend', 'by' => '30' ) );

		$this->assertStringContainsString( 'dg_result=extended', $location );
		$this->assertSame( $before + 1800, $this->service->ends_at() );
	}

	public function test_extend_accepts_only_the_offered_lengths(): void {
		$this->service->freeze_for( 600 );

		$this->assertStringContainsString( 'dg_error=invalid_duration', $this->submit( array( 'operation' => 'extend', 'by' => '17' ) ) );
		$this->assertStringContainsString( 'dg_error=invalid_duration', $this->submit( array( 'operation' => 'extend' ) ) );
	}

	public function test_extending_a_manual_freeze_is_refused(): void {
		$this->service->enable();

		$this->assertStringContainsString( 'dg_error=not_extendable', $this->submit( array( 'operation' => 'extend', 'by' => '15' ) ) );
	}

	public function test_the_scope_is_saved_from_the_checkboxes_without_a_confirmation(): void {
		$location = $this->submit( array( 'operation' => 'scope', 'scope_comments' => '1', 'scope_forms' => '1' ) );

		$this->assertStringContainsString( 'dg_result=scope_saved', $location );
		$this->assertTrue( $this->service->scope()->comments );
		$this->assertTrue( $this->service->scope()->forms );

		$this->submit( array( 'operation' => 'scope', 'scope_forms' => '1' ) );

		$this->assertFalse( $this->service->scope()->comments, 'an unchecked box turns it off' );
		$this->assertTrue( $this->service->scope()->forms );
	}

	public function test_saving_the_same_scope_says_nothing_changed(): void {
		$this->submit( array( 'operation' => 'scope', 'scope_comments' => '1' ) );

		$this->assertStringContainsString( 'dg_result=unchanged', $this->submit( array( 'operation' => 'scope', 'scope_comments' => '1' ) ) );
	}

	public function test_an_idle_screen_offers_the_quick_start(): void {
		$html = $this->html();

		$this->assertStringContainsString( 'sg-hero--idle', $html );
		$this->assertStringContainsString( 'Open — editing is not restricted', $html );
		$this->assertStringContainsString( 'name="operation" value="freeze_for"', $html );
		$this->assertStringContainsString( 'Start freeze', $html );
		$this->assertStringContainsString( 'Until I end it', $html );

		foreach ( array( 15, 30, 60, 120, 240 ) as $minutes ) {
			$this->assertStringContainsString( 'name="duration" value="' . $minutes . '"', $html );
		}

		$this->assertStringNotContainsString( 'End freeze now', $html );
	}

	public function test_the_quick_start_defaults_to_thirty_minutes_and_requires_confirmation(): void {
		$html = $this->html();

		$this->assertMatchesRegularExpression( '/name="duration" value="30" checked=\'checked\'|name="duration" value="30" checked/', $html );
		$this->assertMatchesRegularExpression( '/name="confirm" value="1" required/', $html );
	}

	public function test_a_running_timed_freeze_shows_the_status_end_and_extension_buttons(): void {
		$this->service->freeze_for( 1800 );

		$html = $this->html();

		$this->assertStringContainsString( 'sg-hero--active', $html );
		$this->assertStringContainsString( 'Content is frozen', $html );
		$this->assertStringContainsString( 'Ends in', $html );
		$this->assertStringContainsString( 'End freeze now', $html );
		$this->assertStringContainsString( 'Taking longer?', $html );

		foreach ( array( 15, 30, 60 ) as $minutes ) {
			$this->assertStringContainsString( 'name="by" value="' . $minutes . '"', $html );
		}

		$this->assertStringNotContainsString( 'name="duration"', $html, 'no quick start while a freeze runs' );
	}

	public function test_a_manual_freeze_has_no_extension_buttons(): void {
		$this->service->enable();

		$html = $this->html();

		$this->assertStringContainsString( 'It stays on until somebody ends it.', $html );
		$this->assertStringContainsString( 'End freeze now', $html );
		$this->assertStringNotContainsString( 'Taking longer?', $html );
	}

	public function test_a_scheduled_window_is_shown_as_upcoming(): void {
		$start = time() + 2 * HOUR_IN_SECONDS;
		$this->service->schedule( $start, $start + HOUR_IN_SECONDS );

		$html = $this->html();

		$this->assertStringContainsString( 'sg-hero--upcoming', $html );
		$this->assertStringContainsString( 'a freeze is scheduled', $html );
		$this->assertStringContainsString( 'Starting a freeze here replaces the scheduled window.', $html );
	}

	public function test_the_scope_card_reflects_the_saved_choice(): void {
		$this->service->set_scope( new Scope( true, false ) );

		$html = $this->html();

		$this->assertMatchesRegularExpression( '/name="scope_comments" value="1" checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="scope_forms" value="1" checked/', $html );
		$this->assertStringContainsString( 'Contact Form 7', $html );
	}

	public function test_the_receipt_card_explains_itself_before_any_freeze(): void {
		$this->assertStringContainsString( 'No freeze has run yet', $this->html() );
	}

	public function test_the_receipt_card_says_when_nothing_changed(): void {
		$this->service->enable();
		$this->service->disable();

		$html = $this->html();

		$this->assertStringContainsString( 'sg-receipt--clean', $html );
		$this->assertStringContainsString( 'Nothing changed while the freeze was on.', $html );
	}

	public function test_the_receipt_card_lists_changed_posts_and_the_export_hint(): void {
		$this->service->enable();
		$post_id = self::factory()->post->create( array( 'post_title' => 'Edited during the freeze' ) );

		$html = $this->html();

		$this->assertStringContainsString( 'sg-receipt--dirty', $html );
		$this->assertStringContainsString( 'Edited during the freeze', $html );
		$this->assertStringContainsString( 'post=' . $post_id, $html );
		$this->assertStringContainsString( 'wp export --post__in', $html );
	}

	public function test_the_receipt_card_escapes_post_titles(): void {
		$this->service->enable();
		self::factory()->post->create( array( 'post_title' => '<script>alert(3)</script>' ) );

		$this->assertStringNotContainsString( '<script>alert(3)</script>', $this->html() );
	}

	public function test_the_receipt_card_reports_paused_visitors(): void {
		$this->service->enable();
		$this->receipt->count_paused( 'comments' );
		$this->receipt->count_paused( 'forms' );
		$this->receipt->count_paused( 'forms' );

		$this->assertStringContainsString( 'Paused visitors: 1 comments, 2 form submissions.', $this->html() );
	}

	public function test_a_receipt_with_no_changed_posts_but_a_comment_does_not_say_zero_post_changed(): void {
		$post = self::factory()->post->create( array( 'post_date' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		$this->service->enable();
		self::factory()->comment->create( array( 'comment_post_ID' => $post, 'comment_approved' => '1' ) );

		$html = $this->html();

		$this->assertStringNotContainsString( '0 post changed', $html );
		$this->assertStringNotContainsString( '0 posts changed', $html );
		$this->assertStringContainsString( 'Content changed during the freeze.', $html );
		$this->assertStringContainsString( 'sg-receipt--dirty', $html );
	}

	public function test_a_running_receipt_says_since_when(): void {
		$this->service->enable();

		$this->assertStringContainsString( 'Since ', $this->html() );
		$this->assertStringNotContainsString( 'still running', $this->html() );
	}

	public function test_term_changes_read_as_a_count_like_the_other_facts(): void {
		$this->service->enable();

		$this->assertMatchesRegularExpression( '/<strong>0<\/strong> term changes/', $this->html() );
	}

	public function test_the_coverage_card_is_called_coverage(): void {
		$html = $this->html();

		$this->assertStringContainsString( 'sg-scope-title">Coverage<', $html );
		$this->assertStringNotContainsString( 'What a freeze covers', $html );
	}

	public function test_a_duration_that_would_overflow_is_refused_without_an_error(): void {
		$location = $this->submit( array( 'operation' => 'freeze_for', 'duration' => '99999999999999999999', 'confirm' => '1' ) );

		$this->assertStringContainsString( 'dg_error=invalid_duration', $location );
		$this->assertFalse( $this->service->is_active() );
	}

	public function test_the_pipelines_card_shows_real_commands_and_endpoint(): void {
		$html = $this->html();

		$this->assertStringContainsString( 'wp stillgate enable --for=30m', $html );
		$this->assertStringContainsString( 'wp stillgate receipt --check', $html );
		$this->assertStringContainsString( rest_url( 'stillgate/v1/freeze' ), html_entity_decode( $html ) );
	}

	public function test_controls_are_disabled_without_the_bypass_capability(): void {
		remove_role( 'dg_options_only4' );
		add_role( 'dg_options_only4', 'Options Only 4', array( 'read' => true, 'manage_options' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'dg_options_only4' ) ) );

		$html = $this->html();

		$this->assertMatchesRegularExpression( '/<button type="submit" class="button button-primary button-hero sg-button-start"\s+disabled/', $html );
		$this->assertMatchesRegularExpression( '/name="scope_forms" value="1"[^>]*disabled/', $html );

		remove_role( 'dg_options_only4' );
	}

	public function test_the_stylesheet_is_loaded_on_this_screen_only(): void {
		wp_dequeue_style( 'stillgate-admin' );
		wp_deregister_style( 'stillgate-admin' );

		$this->page->enqueue_assets( 'plugins.php' );
		$this->assertFalse( wp_style_is( 'stillgate-admin', 'enqueued' ) );

		$this->page->enqueue_assets( 'tools_page_stillgate' );
		$this->assertTrue( wp_style_is( 'stillgate-admin', 'enqueued' ) );
		$this->assertSame( 'http://example.test/assets/admin.css', wp_styles()->registered['stillgate-admin']->src );
	}

	public function test_no_stylesheet_is_requested_without_an_assets_url(): void {
		wp_dequeue_style( 'stillgate-admin' );
		wp_deregister_style( 'stillgate-admin' );
		$page = new SettingsPage( $this->service, $this->make_guard( $this->service ) );

		$page->enqueue_assets( 'tools_page_stillgate' );

		$this->assertFalse( wp_style_is( 'stillgate-admin', 'enqueued' ) );
	}

	public function test_the_coverage_change_appears_in_the_activity_log(): void {
		$this->service->set_scope( new Scope( true, true ) );

		$html = $this->html();

		$this->assertStringContainsString( 'Coverage changed', $html );
		$this->assertStringContainsString( 'comments,forms', $html );
	}
}
