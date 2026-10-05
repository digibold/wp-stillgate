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
 * Shows a visible but professional notice on the classic wp-admin screens
 * where a freeze actually affects editorial work, and an equivalent notice
 * inside the block editor, whenever a freeze is active. Explains that
 * Stillgate is active, when it is expected to end (if scheduled), the
 * operator's own message, whether the current user can bypass it, and how
 * the freeze ends — so a restricted user understands why editing is
 * unavailable, rather than encountering it as a confusing error.
 *
 * The classic notice is deliberately not site-wide: it appears only on the
 * dashboard and on the screens that list or edit protected content (see
 * screen_is_affected()), and it is rendered only while a freeze is
 * active, so it disappears by itself the moment the freeze ends. Nothing
 * is stored about it: there is no dismissal state.
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

		if ( ! $screen || $screen->is_block_editor() || 'tools_page_stillgate' === $screen->id ) {
			return;
		}

		if ( ! $this->screen_is_affected( $screen ) ) {
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

		if ( $can_bypass ) {
			$html .= '<p>' . esc_html__( 'You can continue editing, but your changes may still be lost if a deployment or migration proceeds.', 'stillgate' ) . '</p>';

			if ( current_user_can( 'manage_options' ) ) {
				$html .= '<p>' . wp_kses(
					sprintf(
						/* translators: %s: URL of the Stillgate settings screen. */
						__( 'You can end the freeze under <a href="%s">Tools &gt; Stillgate</a>.', 'stillgate' ),
						esc_url( admin_url( 'tools.php?page=stillgate' ) )
					),
					array( 'a' => array( 'href' => array() ) )
				) . '</p>';
			} else {
				$html .= '<p>' . esc_html__( 'To end the freeze, ask a site administrator.', 'stillgate' ) . '</p>';
			}
		} else {
			$html .= '<p>' . esc_html__( 'Editing is unavailable to your account until the freeze ends. This notice disappears when it does; if you need to change something sooner, ask a site administrator.', 'stillgate' ) . '</p>';
		}

		wp_admin_notice(
			$html,
			array(
				'type'           => 'warning',
				'paragraph_wrap' => false,
			)
		);
	}

	/**
	 * Whether a freeze affects editorial work on this screen. Derived from
	 * the screen itself and from the same protected post types and
	 * taxonomies the enforcement layers use (including anything a site adds
	 * or removes through the `deployment_guard_protected_types` filter), not
	 * from a fixed list of screen IDs: a custom post type's list and edit
	 * screens, and a custom taxonomy's term screens, are covered exactly
	 * when that type is protected. The remaining cases are the surfaces that
	 * are protected as a group (media, menus, widgets, the Customizer, the
	 * classic Custom Header and Custom Background screens) and the dashboard,
	 * where Quick Draft is withdrawn during a freeze.
	 */
	private function screen_is_affected( \WP_Screen $screen ): bool {
		switch ( $screen->base ) {
			case 'dashboard':
			case 'upload':
			case 'media':
			case 'nav-menus':
			case 'widgets':
			case 'customize':
			case 'site-editor':
				return true;

			case 'edit':
			case 'post':
				return '' !== (string) $screen->post_type && $this->guard->is_protected_post_type( (string) $screen->post_type );

			case 'edit-tags':
			case 'term':
				return '' !== (string) $screen->taxonomy && $this->guard->is_protected_taxonomy( (string) $screen->taxonomy );
		}

		return in_array( $screen->id, array( 'appearance_page_custom-header', 'appearance_page_custom-background' ), true );
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

		wp_register_script( 'stillgate-editor-notice', false, array( 'wp-data', 'wp-notices' ), Plugin::VERSION, true );
		wp_enqueue_script( 'stillgate-editor-notice' );

		wp_add_inline_script(
			'stillgate-editor-notice',
			'wp.data.dispatch("core/notices").createNotice("warning",' . wp_json_encode( $message ) . ',{id:"stillgate-freeze",isDismissible:false});'
		);
	}
}
