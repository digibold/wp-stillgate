<?php
/**
 * admin-ajax.php gates.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Enforcement;

use Digibold\DeploymentGuard\Guard;
use Digibold\DeploymentGuard\Input;

defined( 'ABSPATH' ) || exit;

/**
 * Stops a restricted user's admin-ajax.php write requests for protected
 * content, before the matching `wp_ajax_*` handler runs.
 *
 * Registered on `admin_init` at priority 1, which fires before
 * admin-ajax.php has even registered its own action handlers, this reads
 * `$_REQUEST['action']` the same way admin-ajax.php's own dispatcher does
 * and, for the actions listed here, resolves the real object the request
 * targets (never trusting a type the request merely claims).
 *
 * Each denial mirrors the response shape the matching core handler itself
 * uses for a permission failure, so the admin JS that is already prepared
 * to handle a "you can't do that" response displays this one the same
 * way, only with Stillgate's own message where the handler would
 * normally show its own.
 *
 * Not every legacy admin-ajax action is listed here individually: several
 * write operations that have no request-level gate of their own (creating
 * a term, uploading a file) are caught by DataLayerGuard's short-circuits
 * on the underlying core functions instead, and handlers whose only write
 * is already conditional on a capability the capability layer denies
 * (such as `send-attachment-to-editor`, which is otherwise a read the
 * media modal needs for unprotected content) are left to that layer.
 */
final class AjaxGate {

	private Guard $guard;

	public function __construct( Guard $guard ) {
		$this->guard = $guard;
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'guard_action' ), 1 );
		add_filter( 'heartbeat_received', array( $this, 'rewrite_autosave_failure' ), 501, 2 );
	}

	public function guard_action(): void {
		if ( ! wp_doing_ajax() ) {
			return;
		}

		switch ( Input::key( 'action' ) ) {
			case 'inline-save':
				$this->guard_inline_save();
				break;

			case 'add-meta':
				$this->guard_add_meta();
				break;

			case 'delete-meta':
				$this->guard_delete_meta();
				break;

			case 'delete-post':
			case 'trash-post':
			case 'untrash-post':
			case 'delete-page':
				$this->guard_post_id_action( Input::key( 'action' ) );
				break;

			case 'set-post-thumbnail':
				$this->guard_set_post_thumbnail();
				break;

			case 'set-attachment-thumbnail':
				if ( $this->guard->applies_to_post_type( 'attachment' ) ) {
					$this->deny_json( 'set-attachment-thumbnail' );
				}
				break;

			case 'upload-attachment':
				if ( $this->guard->applies_to_post_type( 'attachment' ) ) {
					$this->deny_upload( 'upload-attachment' );
				}
				break;

			case 'save-attachment':
			case 'save-attachment-compat':
				$this->guard_post_id_json( Input::int( 'id', 'REQUEST' ), Input::key( 'action' ) );
				break;

			case 'save-attachment-order':
				$this->guard_post_id_json( Input::int( 'post_id', 'REQUEST' ), 'save-attachment-order' );
				break;

			case 'image-editor':
				$this->guard_post_id_legacy( Input::int( 'postid', 'POST' ), 'image-editor' );
				break;

			case 'crop-image':
				$this->guard_post_id_json( Input::int( 'id', 'POST' ), 'crop-image' );
				break;

			case 'media-create-image-subsizes':
				if ( $this->guard->applies_to_post( Input::int( 'attachment_id', 'POST' ) ) ) {
					$this->deny_json_message( 'media-create-image-subsizes' );
				}
				break;

			case 'wp-fullscreen-save-post':
				$this->guard_post_id_json( Input::int( 'post_ID', 'POST' ), 'wp-fullscreen-save-post' );
				break;

			case 'add-tag':
				// Core reads the taxonomy from $_POST only, defaulting to post_tag.
				$taxonomy = Input::key( 'taxonomy', 'POST' );
				$taxonomy = '' === $taxonomy ? 'post_tag' : $taxonomy;
				if ( $this->guard->applies_to_taxonomy( $taxonomy, 'edit' ) ) {
					$this->deny_legacy( 'add-tag:' . $taxonomy );
				}
				break;

			case 'delete-tag':
				if ( $this->guard->applies_to_term( Input::int( 'tag_ID', 'POST' ), 'edit' ) ) {
					$this->deny_legacy( 'delete-tag' );
				}
				break;

			case 'inline-save-tax':
				if ( $this->guard->applies_to_term( Input::int( 'tax_ID', 'POST' ), 'edit' ) ) {
					$this->deny_legacy( 'inline-save-tax' );
				}
				break;

			case 'add-menu-item':
			case 'menu-locations-save':
			case 'customize-nav-menus-insert-auto-draft':
			case 'widgets-order':
			case 'save-widget':
			case 'delete-inactive-widgets':
			case 'update-widget':
				if ( $this->guard->applies_to_surface() ) {
					$this->deny_legacy( Input::key( 'action' ) );
				}
				break;

			case 'customize_save':
			case 'customize_trash':
			case 'customize_override_changeset_lock':
			case 'customize_dismiss_autosave_or_lock':
				if ( $this->guard->applies_to_surface() ) {
					$this->deny_customize( Input::key( 'action' ) );
				}
				break;

			case 'custom-header-crop':
			case 'custom-header-add':
			case 'custom-header-remove':
			case 'custom-background-add':
				if ( $this->guard->applies_to_surface() ) {
					$this->deny_json( Input::key( 'action' ) );
				}
				break;

			case 'set-background-image':
				if ( $this->guard->applies_to_surface() ) {
					$this->deny_exit( 'set-background-image' );
				}
				break;
		}
	}

	/**
	 * Rewrites the message of an already-failed autosave (the capability
	 * layer already denies the autosave itself; this only replaces core's
	 * generic wording with the freeze message, mainly so a browser tab
	 * left open from before a freeze started shows a clear reason).
	 *
	 * @param mixed $response
	 * @param mixed $data
	 * @return mixed
	 */
	public function rewrite_autosave_failure( $response, $data ) {
		if ( ! is_array( $response ) || ! isset( $response['wp_autosave'] ) || ! is_array( $response['wp_autosave'] ) ) {
			return $response;
		}

		if ( ! empty( $response['wp_autosave']['success'] ) ) {
			return $response;
		}

		$post_id = 0;

		if ( isset( $data['wp_autosave'] ) && is_array( $data['wp_autosave'] ) && isset( $data['wp_autosave']['post_id'] ) ) {
			$post_id = (int) $data['wp_autosave']['post_id'];
		}

		if ( 0 === $post_id || ! $this->guard->applies_to_post( $post_id ) ) {
			return $response;
		}

		$response['wp_autosave']['message'] = $this->guard->message_text();

		return $response;
	}

	private function guard_inline_save(): void {
		$post_id = Input::int( 'post_ID', 'POST' );

		if ( 0 === $post_id ) {
			return;
		}

		if ( $this->guard->applies_to_post( $post_id ) ) {
			$this->deny_inline_save_text( 'inline-save:' . $post_id );
		}
	}

	private function guard_add_meta(): void {
		$post_id = 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only routing to an enforcement gate; core verifies its own nonce for this request.
		if ( isset( $_POST['metakeyselect'] ) || isset( $_POST['metakeyinput'] ) ) {
			$post_id = Input::int( 'post_id', 'POST' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only routing to an enforcement gate; core verifies its own nonce for this request.
		} elseif ( isset( $_POST['meta'] ) && is_array( $_POST['meta'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only routing to an enforcement gate; only the array key (a meta ID) is read, via key(), and immediately cast to int — the (int) cast is the sanitization, and the value itself is never read or used.
			$meta_id = (int) key( wp_unslash( $_POST['meta'] ) );
			$meta    = get_metadata_by_mid( 'post', $meta_id );

			if ( $meta ) {
				$post_id = (int) $meta->post_id;
			}
		}

		if ( 0 === $post_id ) {
			return;
		}

		if ( $this->guard->applies_to_post( $post_id ) ) {
			$this->deny_legacy( 'add-meta:' . $post_id );
		}
	}

	private function guard_delete_meta(): void {
		$id = Input::int( 'id', 'POST' );

		if ( 0 === $id ) {
			return;
		}

		$meta = get_metadata_by_mid( 'post', $id );

		if ( ! $meta ) {
			return;
		}

		if ( $this->guard->applies_to_post( (int) $meta->post_id ) ) {
			$this->deny_legacy( 'delete-meta:' . $meta->post_id );
		}
	}

	private function guard_post_id_action( string $action ): void {
		$id = Input::int( 'id', 'POST' );

		if ( 0 === $id ) {
			return;
		}

		if ( $this->guard->applies_to_post( $id ) ) {
			$this->deny_legacy( $action . ':' . $id );
		}
	}

	private function guard_set_post_thumbnail(): void {
		$post_id = Input::int( 'post_id', 'POST' );

		if ( 0 === $post_id || ! $this->guard->applies_to_post( $post_id ) ) {
			return;
		}

		$this->guard->report( 'ajax', 'set-post-thumbnail:' . $post_id );

		if ( '' !== Input::string( 'json' ) ) {
			wp_send_json_error();
		}

		wp_die( -1 );
	}

	private function guard_post_id_json( int $post_id, string $target ): void {
		if ( 0 === $post_id ) {
			return;
		}

		if ( $this->guard->applies_to_post( $post_id ) ) {
			$this->deny_json( $target . ':' . $post_id );
		}
	}

	private function guard_post_id_legacy( int $post_id, string $target ): void {
		if ( 0 === $post_id ) {
			return;
		}

		if ( $this->guard->applies_to_post( $post_id ) ) {
			$this->deny_legacy( $target . ':' . $post_id );
		}
	}

	private function deny_legacy( string $target ): void {
		$this->guard->report( 'ajax', $target );
		wp_die( -1 );
	}

	private function deny_json( string $target ): void {
		$this->guard->report( 'ajax', $target );
		wp_send_json_error();
	}

	private function deny_json_message( string $target ): void {
		$this->guard->report( 'ajax', $target );
		wp_send_json_error( array( 'message' => $this->guard->message_text() ) );
	}

	private function deny_customize( string $target ): void {
		$this->guard->report( 'ajax', $target );
		wp_send_json_error(
			array(
				'code'    => 'deployment_guard_active',
				'message' => $this->guard->message_text(),
			)
		);
	}

	private function deny_upload( string $target ): void {
		$this->guard->report( 'ajax', $target );

		$filename = '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only; used only to echo the uploaded filename back in the denial message, never to process the file.
		if ( isset( $_FILES['async-upload']['name'] ) ) {
			$filename = sanitize_file_name( wp_unslash( $_FILES['async-upload']['name'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		}

		echo wp_json_encode(
			array(
				'success' => false,
				'data'    => array(
					'message'  => $this->guard->message_text(),
					'filename' => esc_html( $filename ),
				),
			)
		);

		wp_die();
	}

	private function deny_exit( string $target ): void {
		$this->guard->report( 'ajax', $target );
		exit;
	}

	private function deny_inline_save_text( string $target ): void {
		$this->guard->report( 'ajax', $target );
		wp_die( esc_html( $this->guard->message_text() ) );
	}
}
