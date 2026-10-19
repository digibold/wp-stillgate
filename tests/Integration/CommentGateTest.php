<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Enforcement\CommentGate;
use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Receipt;
use Digibold\DeploymentGuard\Scope;

/**
 * Comments are paused at the one place every new comment passes through,
 * when the site chose to pause them. Nothing here depends on a theme or on
 * what a page shows, so a page cache cannot get it wrong.
 */
final class CommentGateTest extends TestCase {

	private FreezeService $service;

	private Receipt $receipt;

	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		$this->service = $this->make_service( 0 );
		$guard         = $this->make_guard( $this->service );
		$this->receipt = new Receipt( $this->service, $guard, static fn (): int => time() );
		( new CommentGate( $guard, $this->receipt ) )->register();
		$this->post_id = self::factory()->post->create( array( 'post_date' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		wp_set_current_user( 0 );
	}

	private function submit( string $type = 'comment' ): int|\WP_Error|false {
		return wp_new_comment(
			array(
				'comment_post_ID'      => $this->post_id,
				'comment_author'       => 'Visitor',
				'comment_author_email' => 'visitor@example.test',
				'comment_author_url'   => '',
				'comment_parent'       => 0,
				'user_id'              => get_current_user_id(),
				'comment_content'      => 'Hello there',
				'comment_type'         => $type,
			),
			true
		);
	}

	private function pause_comments(): void {
		$this->service->set_scope( new Scope( true, false ) );
		$this->service->enable();
	}

	public function test_a_visitor_comment_is_refused_while_frozen_when_comments_are_in_scope(): void {
		$this->pause_comments();

		$result = $this->submit();

		$this->assertWPError( $result );
		$this->assertSame( 'stillgate_comments_paused', $result->get_error_code() );
		$this->assertSame( 423, $result->get_error_data() );
		$this->assertStringContainsString( 'temporarily paused', $result->get_error_message() );
		$this->assertSame( 0, (int) get_comments_number( $this->post_id ) );
		$this->assertCount( 0, get_comments( array( 'post_id' => $this->post_id, 'status' => 'all' ) ) );
	}

	public function test_a_refused_rest_comment_is_answered_with_423_not_500(): void {
		$this->pause_comments();
		add_filter( 'rest_allow_anonymous_comments', '__return_true' );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_body_params(
			array(
				'post'         => $this->post_id,
				'content'      => 'Hello from the API',
				'author_name'  => 'Visitor',
				'author_email' => 'visitor@example.test',
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 423, $response->get_status(), 'core would otherwise report a 500' );
		$this->assertSame( 'stillgate_comments_paused', $response->get_data()['code'] );
		$this->assertCount( 0, get_comments( array( 'post_id' => $this->post_id, 'status' => 'all' ) ) );
	}

	public function test_other_rest_errors_keep_their_own_status(): void {
		$gate = new CommentGate( $this->make_guard( $this->service ), $this->receipt );

		$error = new \WP_Error( 'rest_comment_exploded', 'No', array( 'status' => 409 ) );

		$this->assertSame( $error, $gate->rest_status( $error ) );
		$this->assertSame( 'kept', $gate->rest_status( 'kept' ) );
	}

	public function test_comments_are_untouched_when_the_scope_does_not_include_them(): void {
		$this->service->set_scope( new Scope( false, true ) );
		$this->service->enable();

		$this->assertIsInt( $this->submit() );
	}

	public function test_comments_are_untouched_when_no_freeze_is_active(): void {
		$this->service->set_scope( new Scope( true, true ) );

		$this->assertIsInt( $this->submit() );
	}

	public function test_comments_work_again_when_the_freeze_ends(): void {
		$this->pause_comments();
		$this->assertWPError( $this->submit() );

		$this->service->disable();

		$this->assertIsInt( $this->submit() );
	}

	public function test_a_logged_in_subscriber_is_paused_too(): void {
		$this->pause_comments();
		$this->login_as( 'subscriber' );

		$this->assertWPError( $this->submit() );
	}

	public function test_someone_who_can_bypass_the_freeze_can_still_comment(): void {
		$this->pause_comments();
		$this->login_as( 'administrator' );

		$this->assertIsInt( $this->submit() );
	}

	/**
	 * @dataProvider provide_paused_types
	 */
	public function test_every_visitor_comment_type_is_paused( string $type ): void {
		$this->pause_comments();

		$this->assertWPError( $this->submit( $type ) );
	}

	public static function provide_paused_types(): array {
		return array(
			'default'   => array( '' ),
			'comment'   => array( 'comment' ),
			'review'    => array( 'review' ),
			'pingback'  => array( 'pingback' ),
			'trackback' => array( 'trackback' ),
		);
	}

	/**
	 * @dataProvider provide_internal_types
	 */
	public function test_internal_comment_types_keep_working( string $type ): void {
		$this->pause_comments();

		$this->assertIsInt( $this->submit( $type ) );
	}

	public static function provide_internal_types(): array {
		return array(
			'WooCommerce order note' => array( 'order_note' ),
			'editorial note'         => array( 'note' ),
			'custom internal type'   => array( 'audit_log' ),
		);
	}

	public function test_the_paused_types_are_filterable(): void {
		$this->pause_comments();
		add_filter(
			'deployment_guard_paused_comment_types',
			static function ( $types ) {
				$types[] = 'audit_log';

				return $types;
			}
		);

		$this->assertWPError( $this->submit( 'audit_log' ) );
	}

	public function test_the_pause_itself_is_filterable(): void {
		$this->pause_comments();
		add_filter( 'deployment_guard_submissions_paused', '__return_false' );

		$this->assertIsInt( $this->submit() );
	}

	public function test_the_form_submission_path_refuses_with_the_same_error(): void {
		$this->pause_comments();
		add_filter( 'comments_open', '__return_true' );

		$result = wp_handle_comment_submission(
			array(
				'comment_post_ID' => $this->post_id,
				'comment'         => 'Hello there',
				'author'          => 'Visitor',
				'email'           => 'visitor@example.test',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'stillgate_comments_paused', $result->get_error_code() );
	}

	public function test_the_rest_api_refuses_a_visitor_comment(): void {
		$this->pause_comments();
		add_filter( 'rest_allow_anonymous_comments', '__return_true' );

		$server                  = new \WP_REST_Server();
		$GLOBALS['wp_rest_server'] = $server;
		do_action( 'rest_api_init', $server );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_body_params(
			array(
				'post'         => $this->post_id,
				'content'      => 'Hello there',
				'author_name'  => 'Visitor',
				'author_email' => 'visitor@example.test',
			)
		);
		$response = $server->dispatch( $request );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( 'stillgate_comments_paused', $response->get_data()['code'] );
		$GLOBALS['wp_rest_server'] = null;
	}

	public function test_the_message_says_when_a_timed_freeze_ends(): void {
		$this->service->set_scope( new Scope( true, false ) );
		$this->service->freeze_for( 1800 );

		$result = $this->submit();

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'try again after', $result->get_error_message() );
	}

	public function test_a_manual_freeze_has_no_end_to_announce(): void {
		$this->pause_comments();

		$this->assertStringContainsString( 'in a few minutes', $this->submit()->get_error_message() );
	}

	public function test_paused_comments_are_counted_in_the_receipt(): void {
		$this->pause_comments();

		$this->submit();
		$this->submit();

		$this->assertSame( 2, $this->receipt->report()['paused']['comments'] );
		$this->assertSame( 0, $this->receipt->report()['paused']['forms'] );
	}
}
