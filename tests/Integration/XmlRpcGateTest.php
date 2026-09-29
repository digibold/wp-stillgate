<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Enforcement\XmlRpcGate;
use Digibold\DeploymentGuard\Tests\Support\HaltingXmlRpcServer;
use Digibold\DeploymentGuard\Tests\Support\XmlRpcHalt;

/**
 * @group xmlrpc
 */
final class XmlRpcGateTest extends TestCase {

	private const NOW = 1_800_000_000;

	private function install(): array {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );
		( new XmlRpcGate( $guard ) )->register();
		$service->enable();

		return array( $service, $guard );
	}

	private function expect_halt( \Closure $trigger ): XmlRpcHalt {
		try {
			$trigger();
		} catch ( XmlRpcHalt $exception ) {
			$this->assertSame( 423, $exception->getCode() );

			return $exception;
		}

		$this->fail( 'Expected the XML-RPC server to halt via IXR_Error but it did not.' );
	}

	public function test_new_post_is_blocked(): void {
		$this->install();
		$this->login_as( 'editor' );
		$server = new HaltingXmlRpcServer();

		$this->expect_halt(
			static function () use ( $server ) {
				do_action( 'xmlrpc_call', 'wp.newPost', array(), $server );
			}
		);
	}

	public function test_edit_post_is_blocked(): void {
		$this->install();
		$post_id = self::factory()->post->create( array( 'post_title' => 'Original' ) );
		$this->login_as( 'editor' );
		$server = new HaltingXmlRpcServer();

		$this->expect_halt(
			static function () use ( $server ) {
				do_action( 'xmlrpc_call', 'wp.editPost', array(), $server );
			}
		);

		$this->assertSame( 'Original', get_post( $post_id )->post_title );
	}

	public function test_delete_post_is_blocked(): void {
		$this->install();
		$this->login_as( 'editor' );
		$server = new HaltingXmlRpcServer();

		$exception = $this->expect_halt(
			static function () use ( $server ) {
				do_action( 'xmlrpc_call', 'wp.deletePost', array(), $server );
			}
		);

		$this->assertSame( 423, $exception->getCode() );
	}

	public function test_upload_file_alias_is_blocked_under_its_canonical_name(): void {
		// wp.uploadFile is an alias that internally fires xmlrpc_call
		// with the canonical name 'metaWeblog.newMediaObject' (verified
		// against class-wp-xmlrpc-server.php); the denylist only needs
		// (and only has) the canonical name.
		$this->install();
		$this->login_as( 'editor' );
		$server = new HaltingXmlRpcServer();

		$this->expect_halt(
			static function () use ( $server ) {
				do_action( 'xmlrpc_call', 'metaWeblog.newMediaObject', array(), $server );
			}
		);
	}

	public function test_delete_file_alias_is_blocked_under_its_canonical_name(): void {
		$this->install();
		$this->login_as( 'editor' );
		$server = new HaltingXmlRpcServer();

		$this->expect_halt(
			static function () use ( $server ) {
				do_action( 'xmlrpc_call', 'wp.deletePost', array(), $server );
			}
		);
	}

	public function test_new_term_is_blocked(): void {
		$this->install();
		$this->login_as( 'editor' );
		$server = new HaltingXmlRpcServer();

		$this->expect_halt(
			static function () use ( $server ) {
				do_action( 'xmlrpc_call', 'wp.newTerm', array(), $server );
			}
		);
	}

	public function test_new_post_is_blocked_for_a_role_without_edit_posts(): void {
		// wp.newPost checks only the requested post type's own create_posts
		// and edit_posts; for a template, both are edit_theme_options.
		$this->install();

		if ( ! wp_roles()->is_role( 'dg_theme_manager' ) ) {
			add_role( 'dg_theme_manager', 'Theme Manager', array( 'read' => true, 'edit_theme_options' => true ) );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'dg_theme_manager' ) ) );
		$this->assertFalse( current_user_can( 'edit_posts' ) );

		$server = new HaltingXmlRpcServer();

		$this->expect_halt(
			static function () use ( $server ) {
				do_action( 'xmlrpc_call', 'wp.newPost', array(), $server );
			}
		);
	}

	public function test_get_post_is_allowed_for_an_editor(): void {
		$this->install();
		$this->login_as( 'editor' );
		$server = new HaltingXmlRpcServer();

		do_action( 'xmlrpc_call', 'wp.getPost', array(), $server );

		$this->assertTrue( true ); // No halt: read methods are never denylisted.
	}

	public function test_new_comment_is_allowed(): void {
		$this->install();
		$this->login_as( 'subscriber' );
		$server = new HaltingXmlRpcServer();

		do_action( 'xmlrpc_call', 'wp.newComment', array(), $server );

		$this->assertTrue( true );
	}

	public function test_bypass_holder_is_allowed(): void {
		$this->install();
		$this->login_as( 'administrator' );
		$server = new HaltingXmlRpcServer();

		do_action( 'xmlrpc_call', 'wp.newPost', array(), $server );

		$this->assertTrue( true );
	}

	public function test_inactive_freeze_allows_everything(): void {
		$service = $this->make_service( self::NOW );
		$guard   = $this->make_guard( $service );
		( new XmlRpcGate( $guard ) )->register();

		$this->login_as( 'editor' );
		$server = new HaltingXmlRpcServer();

		do_action( 'xmlrpc_call', 'wp.newPost', array(), $server );

		$this->assertTrue( true );
	}
}
