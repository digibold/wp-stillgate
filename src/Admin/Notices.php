<?php
/**
 * Admin warning notice for an active freeze.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Admin;

use Digibold\DeploymentGuard\Guard;
use Digibold\DeploymentGuard\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a visible but professional notice on classic wp-admin screens,
 * and an equivalent notice inside the block editor, whenever a freeze is
 * active. Explains that Deployment Guard is active, when it is expected
 * to end (if scheduled), the operator's own message, and whether the
 * current user can bypass it — so a restricted user understands why
 * editing is unavailable, rather than encountering it as a confusing
 * error.
 */
final class Notices {

	private Guard $guard;

	public function __construct( Guard $guard ) {
		$this->guard = $guard;
	}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'block_editor_notice' ) );
	}

	public function admin_notice(): void {
		if ( is_network_admin() || is_user_admin() ) {
			return;
		}

		if ( ! $this->guard->is_active() ) {
			return;
		}

		$screen = get_current_screen();

		if ( $screen && ( $screen->is_block_editor() || 'tools_page_deployment-guard' === $screen->id ) ) {
			return;
		}

		$can_bypass = current_user_can( 'deployment_guard_bypass' );

		if ( ! $can_bypass && ! $this->user_could_be_affected() ) {
			return;
		}

		$html = '';

		foreach ( $this->guard->message_paragraphs() as $paragraph ) {
			$html .= '<p>' . esc_html( $paragraph ) . '</p>';
		}

		$html .= '<p>' . ( $can_bypass
			? esc_html__( 'You can continue editing, but your changes may still be lost if a deployment or migration proceeds.', 'deployment-guard' )
			: esc_html__( 'Editing is unavailable to your account until the freeze ends.', 'deployment-guard' ) )
			. '</p>';

		wp_admin_notice(
			$html,
			array(
				'type'           => 'warning',
				'paragraph_wrap' => false,
			)
		);
	}

	/**
	 * Whether the current user natively holds any of the primitive
	 * capabilities a freeze can restrict — not only `edit_posts`, which
	 * misses a theme-options-only, upload-only, or taxonomy-only role
	 * (each a real way to reach *some* protected surface; see
	 * `Guard::applies()`). This is deliberately broader than any single
	 * request's actual target: it decides whether to show the notice at
	 * all, not whether a specific action is blocked.
	 */
	private function user_could_be_affected(): bool {
		foreach ( array( 'edit_posts', 'upload_files', 'edit_theme_options', 'edit_terms', 'assign_terms' ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Shows the same warning inside the block editor (post editor, Site
	 * Editor, widgets screen). A restricted user cannot open these
	 * screens in the first place — see AdminGate — so in practice this
	 * only ever runs for a bypass holder or on an unprotected content
	 * type; it is a heads-up, not an access control.
	 */
	public function block_editor_notice(): void {
		if ( ! $this->guard->is_active() ) {
			return;
		}

		$message = implode( ' ', $this->guard->message_paragraphs() );

		wp_register_script( 'deployment-guard-editor-notice', false, array( 'wp-data', 'wp-notices' ), Plugin::VERSION, true );
		wp_enqueue_script( 'deployment-guard-editor-notice' );

		wp_add_inline_script(
			'deployment-guard-editor-notice',
			'wp.data.dispatch("core/notices").createNotice("warning",' . wp_json_encode( $message ) . ',{id:"deployment-guard-freeze",isDismissible:false});'
		);
	}
}
