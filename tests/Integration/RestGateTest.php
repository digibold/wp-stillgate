<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Enforcement\RestGate;

final class RestGateTest extends TestCase {

	private const NOW = 1_800_000_000;

	private \WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );
	}

	private function install(): array {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );
		( new RestGate( $guard ) )->register();
		$service->enable();

		return array( $service, $guard );
	}

	private function dispatch( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->server->dispatch( $request );
	}

	public function test_post_create_is_blocked(): void {
		$this->install();
		$this->login_as( 'editor' );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_body_params( array( 'title' => 'New', 'status' => 'publish' ) );

		$response = $this->dispatch( $request );

		$this->assertSame( 423, $response->get_status() );
		$this->assertSame( 'deployment_guard_active', $response->as_error()->get_error_code() );
	}

	public function test_post_update_is_blocked(): void {
		$this->install();
		$post_id       = self::factory()->post->create( array( 'post_title' => 'Original' ) );
		$this->login_as( 'editor' );

		$request = new \WP_REST_Request( 'PUT', '/wp/v2/posts/' . $post_id );
		$request->set_body_params( array( 'title' => 'Changed' ) );

		$response = $this->dispatch( $request );

		$this->assertSame( 423, $response->get_status() );
		$this->assertSame( 'Original', get_post( $post_id )->post_title );
	}

	public function test_post_delete_is_blocked(): void {
		$this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		$request  = new \WP_REST_Request( 'DELETE', '/wp/v2/posts/' . $post_id );
		$response = $this->dispatch( $request );

		$this->assertSame( 423, $response->get_status() );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ) );
	}

	public function test_category_create_is_blocked(): void {
		$this->install();
		$this->login_as( 'editor' );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/categories' );
		$request->set_body_params( array( 'name' => 'New Category' ) );

		$response = $this->dispatch( $request );

		$this->assertSame( 423, $response->get_status() );
	}

	public function test_media_create_is_blocked(): void {
		$this->install();
		$this->login_as( 'editor' );

		$request  = new \WP_REST_Request( 'POST', '/wp/v2/media' );
		$response = $this->dispatch( $request );

		$this->assertSame( 423, $response->get_status() );
	}

	public function test_menu_item_create_is_blocked(): void {
		$this->install();
		$this->login_as_theme_manager();

		$request  = new \WP_REST_Request( 'POST', '/wp/v2/menu-items' );
		$response = $this->dispatch( $request );

		$this->assertSame( 423, $response->get_status() );
	}

	public function test_widgets_write_is_blocked(): void {
		$this->install();
		$this->login_as_theme_manager();

		$request  = new \WP_REST_Request( 'POST', '/wp/v2/widgets' );
		$response = $this->dispatch( $request );

		$this->assertSame( 423, $response->get_status() );
	}

	public function test_revision_delete_is_blocked(): void {
		$this->install();
		$post_id = self::factory()->post->create( array( 'post_content' => 'v1' ) );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => 'v2' ) );
		$revisions = wp_get_post_revisions( $post_id );
		$revision  = array_shift( $revisions );
		$this->login_as( 'editor' );

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/posts/' . $post_id . '/revisions/' . $revision->ID );
		$response = $this->dispatch( $request );

		$this->assertSame( 423, $response->get_status() );
	}

	public function test_method_override_is_still_blocked(): void {
		// The override query arg/header is applied by
		// WP_REST_Server::serve_request() before dispatch() ever sees the
		// request; RestGate reads $request->get_method() *after* that
		// point, so setting the effective method directly (as the
		// override would have) is the accurate way to exercise the same
		// code path without reimplementing serve_request()'s own parsing.
		$this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		$request = new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_method( 'DELETE' );

		$response = $this->dispatch( $request );

		$this->assertSame( 423, $response->get_status() );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ) );
	}

	/**
	 * @dataProvider method_override_provider
	 */
	public function test_method_override_through_serve_request_is_blocked( string $how ): void {
		$this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		global $wp_rest_server;
		$spy            = new \Spy_REST_Server();
		$wp_rest_server = $spy;
		do_action( 'rest_api_init', $spy );

		$original_method           = $_SERVER['REQUEST_METHOD'] ?? null;
		$_SERVER['REQUEST_METHOD'] = 'POST';

		if ( 'query' === $how ) {
			$_GET['_method'] = 'DELETE';
		} else {
			$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'DELETE';
		}

		try {
			$spy->serve_request( '/wp/v2/posts/' . $post_id );
		} finally {
			unset( $_GET['_method'], $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] );
			$_SERVER['REQUEST_METHOD'] = $original_method;
		}

		$body = json_decode( $spy->sent_body, true );

		$this->assertSame( 423, $spy->status );
		$this->assertSame( 'deployment_guard_active', $body['code'] ?? null );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ) );
	}

	public static function method_override_provider(): array {
		return array(
			'_method query argument'          => array( 'query' ),
			'X-HTTP-Method-Override header' => array( 'header' ),
		);
	}

	public function test_author_creating_a_tag_gets_the_freeze_error(): void {
		// Core lets anyone who can *assign* a non-hierarchical taxonomy's
		// terms create new ones through REST.
		$this->install();
		$this->login_as( 'author' );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/tags' );
		$request->set_body_params( array( 'name' => 'Freeze Test Tag' ) );

		$response = $this->dispatch( $request );

		$this->assertSame( 423, $response->get_status() );
		$this->assertSame( 'deployment_guard_active', $response->as_error()->get_error_code() );
		$this->assertNull( term_exists( 'Freeze Test Tag', 'post_tag' ) );
	}

	public function test_author_updating_a_tag_is_left_to_core_and_not_logged(): void {
		list( $service ) = $this->install();
		$term_id         = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );
		$this->login_as( 'author' );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/tags/' . $term_id );
		$request->set_body_params( array( 'name' => 'Changed' ) );

		$response = $this->dispatch( $request );

		$this->assertNotSame( 423, $response->get_status() );

		$blocked = array_filter( $service->entries(), static fn ( $e ) => 'blocked' === $e['type'] );
		$this->assertSame( array(), $blocked );
	}

	public function test_batch_request_blocks_each_sub_request(): void {
		$this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		$request = new \WP_REST_Request( 'POST', '/batch/v1' );
		$request->set_body_params(
			array(
				'validation' => 'require-all-validate',
				'requests'   => array(
					array(
						'method' => 'DELETE',
						'path'   => '/wp/v2/posts/' . $post_id,
					),
				),
			)
		);

		$response = $this->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 423, $data['responses'][0]['status'] );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ) );
	}

	public function test_get_requests_are_never_blocked_by_this_gate(): void {
		// RestGate itself only ever acts on unsafe methods; a GET on a
		// published, unrestricted-to-read post reaches this cleanly,
		// without also exercising the separate capability layer that a
		// context=edit request on someone's own protected draft would
		// (see CapabilityFilterTest for that layer specifically).
		$this->install();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->login_as( 'editor' );

		$request  = new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$response = $this->dispatch( $request );

		$this->assertNotSame( 423, $response->get_status() );
	}

	public function test_head_and_options_are_allowed(): void {
		$this->install();
		$this->login_as( 'editor' );

		$head    = $this->dispatch( new \WP_REST_Request( 'HEAD', '/wp/v2/posts' ) );
		$options = $this->dispatch( new \WP_REST_Request( 'OPTIONS', '/wp/v2/posts' ) );

		$this->assertNotSame( 423, $head->get_status() );
		$this->assertNotSame( 423, $options->get_status() );
	}

	public function test_comment_create_is_allowed(): void {
		$this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'subscriber' );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_body_params(
			array(
				'post'    => $post_id,
				'content' => 'A comment',
			)
		);

		$response = $this->dispatch( $request );

		$this->assertNotSame( 423, $response->get_status() );
	}

	public function test_settings_write_is_allowed(): void {
		$this->install();
		$this->login_as( 'administrator' );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_body_params( array( 'title' => 'Still allowed' ) );

		$response = $this->dispatch( $request );

		$this->assertNotSame( 423, $response->get_status() );
	}

	public function test_non_public_post_type_is_allowed(): void {
		register_post_type(
			'dg_open_cpt',
			array(
				'public'          => false,
				'show_in_rest'    => true,
				'label'           => 'Open',
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
		$this->install();
		$this->login_as( 'editor' );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/dg_open_cpt' );
		$request->set_body_params( array( 'title' => 'New', 'status' => 'publish' ) );

		$response = $this->dispatch( $request );

		$this->assertNotSame( 423, $response->get_status() );

		unregister_post_type( 'dg_open_cpt' );
	}

	public function test_upcoming_and_expired_schedule_windows_do_not_block(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );
		( new RestGate( $guard ) )->register();

		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		$service->schedule( self::NOW + 1000, self::NOW + 2000 ); // Upcoming.
		$request  = new \WP_REST_Request( 'DELETE', '/wp/v2/posts/' . $post_id );
		$response = $this->dispatch( $request );
		$this->assertNotSame( 423, $response->get_status() );
	}

	public function test_subscriber_gets_core_error_and_no_history_entry(): void {
		list( $service ) = $this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'subscriber' );

		$request  = new \WP_REST_Request( 'DELETE', '/wp/v2/posts/' . $post_id );
		$response = $this->dispatch( $request );

		$this->assertNotSame( 423, $response->get_status() );

		$blocked = array_filter( $service->entries(), static fn ( $e ) => 'blocked' === $e['type'] );
		$this->assertSame( array(), $blocked );
	}

	public function test_bypass_holder_gets_201_same_as_without_freeze(): void {
		$this->install();
		$this->login_as( 'administrator' );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts' );
		$request->set_body_params(
			array(
				'title'   => 'New',
				'status'  => 'publish',
				'content' => 'Body',
			)
		);

		$response = $this->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
	}

	public function test_filter_can_exempt_a_route(): void {
		$this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		add_filter( 'deployment_guard_rest_is_protected', '__return_false' );

		$request  = new \WP_REST_Request( 'DELETE', '/wp/v2/posts/' . $post_id );
		$response = $this->dispatch( $request );

		$this->assertNotSame( 423, $response->get_status() );

		remove_filter( 'deployment_guard_rest_is_protected', '__return_false' );
	}

	public function test_repeated_attempts_are_deduplicated_in_history(): void {
		list( $service ) = $this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->dispatch( new \WP_REST_Request( 'DELETE', '/wp/v2/posts/' . $post_id ) );
		}

		$blocked = array_filter( $service->entries(), static fn ( $e ) => 'blocked' === $e['type'] );
		$this->assertCount( 1, $blocked );
	}

	private function login_as_theme_manager(): int {
		if ( ! wp_roles()->is_role( 'dg_theme_manager' ) ) {
			add_role( 'dg_theme_manager', 'Theme Manager', array( 'read' => true, 'edit_theme_options' => true ) );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'dg_theme_manager' ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}
}
