<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Enforcement\AjaxGate;

/**
 * Calls AjaxGate::guard_action() directly rather than firing the real
 * `admin_init` action: admin_init also carries a great deal of unrelated
 * core (and, since the plugin loads once for the whole test run, this
 * plugin's own) behavior that assumes a real HTTP admin request — up to
 * and including sending real HTTP headers — none of which this class is
 * responsible for or wants to exercise here.
 *
 * @group ajax
 */
final class AjaxGateTest extends TestCase {

	private const NOW = 1_800_000_000;

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		// wp_die() picks a *different* filter, 'wp_die_ajax_handler',
		// once wp_doing_ajax() is true (see wp_die() in core); the base
		// test case only wires up the non-ajax 'wp_die_handler' filter,
		// so without this, wp_die() here would fall through to core's
		// real _ajax_wp_die_handler and end the whole test process.
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
	}

	public function tear_down(): void {
		remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );

		$_REQUEST = array();
		$_POST    = array();
		$_GET     = array();

		parent::tear_down();
	}

	/**
	 * @return array{0: AjaxGate, 1: \Digibold\DeploymentGuard\FreezeService}
	 */
	private function install(): array {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );
		$gate    = new AjaxGate( $guard );
		$service->enable();

		return array( $gate, $service );
	}

	private function expect_die( \Closure $trigger ): void {
		ob_start();

		try {
			$trigger();
			ob_end_clean();
			$this->fail( 'Expected a die (wp_die()/wp_send_json_error(), both of which route through wp_die() in core) but the call completed normally.' );
		} catch ( \WPDieException $exception ) {
			ob_end_clean();
			$this->assertTrue( true );
		}
	}

	public function test_inline_save_is_blocked(): void {
		list( $gate ) = $this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		$_REQUEST['action'] = 'inline-save';
		$_POST['post_ID']   = $post_id;
		$_POST['post_type'] = 'post';

		$this->expect_die( static fn () => $gate->guard_action() );
	}

	public function test_add_tag_is_blocked(): void {
		list( $gate ) = $this->install();
		$this->login_as( 'editor' );

		$_REQUEST['action'] = 'add-tag';
		$_POST['taxonomy']  = 'category';

		$this->expect_die( static fn () => $gate->guard_action() );
	}

	public function test_add_tag_uses_the_taxonomy_core_actually_reads(): void {
		list( $gate ) = $this->install();
		register_taxonomy( 'dg_open_tax', 'post', array( 'public' => false, 'show_ui' => true ) );
		$this->login_as( 'editor' );

		// A taxonomy only in the query string: core reads $_POST alone and
		// falls back to post_tag, a protected taxonomy.
		$_REQUEST['action']   = 'add-tag';
		$_REQUEST['taxonomy'] = 'dg_open_tax';

		try {
			$this->expect_die( static fn () => $gate->guard_action() );
		} finally {
			unregister_taxonomy( 'dg_open_tax' );
		}
	}

	public function test_send_attachment_to_editor_is_left_to_core(): void {
		// Core's only write in this handler (attaching unattached media)
		// already requires edit_post, which the capability layer denies;
		// inserting an image into unprotected content must keep working.
		list( $gate ) = $this->install();
		$attachment_id = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
			)
		);
		$this->login_as( 'editor' );

		$_REQUEST['action']  = 'send-attachment-to-editor';
		$_POST['attachment'] = array( 'id' => $attachment_id );

		$gate->guard_action();

		$this->assertFalse( current_user_can( 'edit_post', $attachment_id ) );
	}

	public function test_add_meta_update_branch_trick_is_blocked(): void {
		list( $gate ) = $this->install();
		$protected_post_id = self::factory()->post->create();
		add_post_meta( $protected_post_id, 'dg_key', 'dg_value', false );

		global $wpdb;
		$mid = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = 'dg_key'", $protected_post_id ) );
		$this->login_as( 'editor' );

		$_REQUEST['action'] = 'add-meta';
		$_POST['meta']      = array( $mid => array( 'key' => 'dg_key', 'value' => 'changed' ) );

		$this->expect_die( static fn () => $gate->guard_action() );
	}

	public function test_save_attachment_is_blocked_as_json(): void {
		list( $gate ) = $this->install();
		$attachment_id = self::factory()->attachment->create_object( 'x.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$this->login_as( 'editor' );

		$_REQUEST['action']  = 'save-attachment';
		$_REQUEST['id']      = $attachment_id;
		$_REQUEST['changes'] = array( 'title' => 'x' );

		ob_start();
		try {
			$gate->guard_action();
			ob_end_clean();
			$this->fail( 'Expected a die.' );
		} catch ( \WPDieException $exception ) {
			$json = ob_get_clean();
		}

		$data = json_decode( $json, true );
		$this->assertIsArray( $data );
		$this->assertFalse( $data['success'] );
	}

	public function test_upload_attachment_is_blocked(): void {
		list( $gate ) = $this->install();
		$this->login_as( 'editor' );

		$_REQUEST['action'] = 'upload-attachment';

		ob_start();
		try {
			$gate->guard_action();
			ob_end_clean();
			$this->fail( 'Expected a die.' );
		} catch ( \WPDieException $exception ) {
			$json = ob_get_clean();
		}

		$data = json_decode( $json, true );
		$this->assertIsArray( $data );
		$this->assertFalse( $data['success'] );
		$this->assertArrayHasKey( 'message', $data['data'] );
	}

	public function test_add_menu_item_is_blocked(): void {
		list( $gate ) = $this->install();
		$this->login_as_theme_manager();

		$_REQUEST['action'] = 'add-menu-item';

		$this->expect_die( static fn () => $gate->guard_action() );
	}

	public function test_widgets_order_is_blocked(): void {
		list( $gate ) = $this->install();
		$this->login_as_theme_manager();

		$_REQUEST['action'] = 'widgets-order';

		$this->expect_die( static fn () => $gate->guard_action() );
	}

	public function test_customize_save_is_blocked_with_code(): void {
		list( $gate ) = $this->install();
		$this->login_as_theme_manager();

		$_REQUEST['action'] = 'customize_save';

		// wp_send_json_error() echoes its JSON body and then calls
		// wp_die() with an empty message, so the payload has to be read
		// from the output buffer, not from the exception itself.
		ob_start();

		try {
			$gate->guard_action();
			ob_end_clean();
			$this->fail( 'Expected a die.' );
		} catch ( \WPDieException $exception ) {
			$json = ob_get_clean();
		}

		$data = json_decode( $json, true );
		$this->assertIsArray( $data );
		$this->assertFalse( $data['success'] );
		$this->assertSame( 'deployment_guard_active', $data['data']['code'] ?? null );
	}

	public function test_custom_header_crop_is_blocked(): void {
		list( $gate ) = $this->install();
		$this->login_as_theme_manager();

		$_REQUEST['action'] = 'custom-header-crop';

		$this->expect_die( static fn () => $gate->guard_action() );
	}

	public function test_heartbeat_autosave_message_is_rewritten(): void {
		list( $gate ) = $this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		$response = $gate->rewrite_autosave_failure(
			array( 'wp_autosave' => array( 'success' => false, 'message' => 'generic' ) ),
			array( 'wp_autosave' => array( 'post_id' => $post_id ) )
		);

		$this->assertFalse( $response['wp_autosave']['success'] );
		$this->assertStringContainsString( 'locked', strtolower( $response['wp_autosave']['message'] ) );
	}

	public function test_heartbeat_leaves_successful_response_alone(): void {
		list( $gate ) = $this->install();
		$post_id = self::factory()->post->create();
		$this->login_as( 'editor' );

		$response = $gate->rewrite_autosave_failure(
			array( 'wp_autosave' => array( 'success' => true, 'message' => 'ok' ) ),
			array( 'wp_autosave' => array( 'post_id' => $post_id ) )
		);

		$this->assertSame( 'ok', $response['wp_autosave']['message'] );
	}

	public function test_query_attachments_is_allowed(): void {
		list( $gate ) = $this->install();
		$this->login_as( 'editor' );

		$_REQUEST['action'] = 'query-attachments';

		// No assertion of a die: query-attachments is not in the gated
		// list, so guard_action() must return without dying.
		$gate->guard_action();
		$this->assertTrue( true );
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
