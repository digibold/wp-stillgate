<?php
/**
 * The Tools > Deployment Guard settings screen.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Admin;

use Digibold\DeploymentGuard\DateInput;
use Digibold\DeploymentGuard\FreezeConfig;
use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Guard;
use Digibold\DeploymentGuard\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the settings screen under Tools > Deployment Guard and handles
 * its single admin-post.php submission target.
 *
 * Viewing the page requires `manage_options`; actually changing anything
 * on it additionally requires the `deployment_guard_bypass` capability
 * (see the class docblock on Guard for why), so an administrator whose
 * bypass has been deliberately removed sees the page, understands why
 * their own edits are frozen too, but cannot use this screen to lift the
 * freeze — WP-CLI or the `DEPLOYMENT_GUARD_FORCE` constant remain
 * available for that.
 */
final class SettingsPage {

	private const NONCE_ACTION = 'deployment_guard_update';
	private const PAGE_SLUG    = 'deployment-guard';
	private const VIEW_CAP     = 'manage_options';

	private FreezeService $service;

	private Guard $guard;

	public function __construct( FreezeService $service, Guard $guard ) {
		$this->service = $service;
		$this->guard   = $guard;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_deployment_guard_update', array( $this, 'handle_post' ) );
		add_filter( 'removable_query_args', array( $this, 'add_removable_query_args' ) );
	}

	public function add_page(): void {
		add_management_page(
			__( 'Deployment Guard', 'deployment-guard' ),
			__( 'Deployment Guard', 'deployment-guard' ),
			self::VIEW_CAP,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * @param mixed $args
	 * @return mixed
	 */
	public function add_removable_query_args( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$args[] = 'dg_result';
		$args[] = 'dg_error';

		return $args;
	}

	public function handle_post(): void {
		wp_safe_redirect( $this->resolve_redirect_url() );
		exit;
	}

	/**
	 * Everything handle_post() does except the actual redirect: verifies
	 * the request, applies the requested change, and returns where to
	 * send the browser next. Split out from handle_post() so the whole
	 * decision can be exercised (and asserted on) without triggering a
	 * real redirect and process exit.
	 */
	public function resolve_redirect_url(): string {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! current_user_can( self::VIEW_CAP ) || ! current_user_can( Installer::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to change Deployment Guard settings.', 'deployment-guard' ),
				'',
				array( 'response' => 403 )
			);
		}

		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$allowed   = array( 'enable', 'disable', 'schedule', 'clear_schedule', 'message' );

		if ( ! in_array( $operation, $allowed, true ) ) {
			wp_die( esc_html__( 'Unknown Deployment Guard action.', 'deployment-guard' ), '', array( 'response' => 400 ) );
		}

		$confirm = ! empty( $_POST['confirm'] );
		$result  = $this->process( $operation, $confirm );

		$args = is_wp_error( $result )
			? array( 'dg_error' => $result->get_error_code() )
			: array( 'dg_result' => $result );

		return add_query_arg( $args, admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
	}

	/**
	 * @return string|\WP_Error
	 */
	private function process( string $operation, bool $confirm ) {
		if ( in_array( $operation, array( 'enable', 'schedule' ), true ) && ! $confirm ) {
			return new \WP_Error( 'confirmation_required', FreezeService::error_text( 'confirmation_required' ) );
		}

		switch ( $operation ) {
			case 'enable':
				return $this->service->enable( 'ui' );

			case 'disable':
				return $this->service->disable( 'ui' );

			case 'clear_schedule':
				return $this->service->clear_schedule( 'ui' );

			case 'message':
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce for this whole submission was already verified in resolve_redirect_url() before process() is ever called.
				$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

				// FreezeService::set_message() applies sanitize_textarea_field()
				// again itself (it is also called directly from WP-CLI, which
				// never passes through here); sanitizing here too is what
				// satisfies static analysis that this value is sanitized at
				// its read site, and sanitize_textarea_field() is idempotent,
				// so calling it twice changes nothing about the result.
				return $this->service->set_message( $message, 'ui' );

			case 'schedule':
				return $this->process_schedule();
		}

		return new \WP_Error( 'unknown_operation', __( 'Unknown Deployment Guard action.', 'deployment-guard' ) );
	}

	/**
	 * @return string|\WP_Error
	 */
	private function process_schedule() {
		$timezone = wp_timezone();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce for this whole submission was already verified in resolve_redirect_url() before process()/process_schedule() are ever called.
		$start_raw = isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '';
		$end_raw   = isset( $_POST['end'] ) ? sanitize_text_field( wp_unslash( $_POST['end'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.

		try {
			$start = DateInput::parse_local( $start_raw, $timezone );
		} catch ( \DomainException $exception ) {
			return new \WP_Error( 'invalid_start', FreezeService::error_text( $exception->getMessage() ) );
		}

		try {
			$end = DateInput::parse_local( $end_raw, $timezone );
		} catch ( \DomainException $exception ) {
			return new \WP_Error( 'invalid_end', FreezeService::error_text( $exception->getMessage() ) );
		}

		return $this->service->schedule( $start, $end, 'ui' );
	}

	public function render(): void {
		if ( ! current_user_can( self::VIEW_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'deployment-guard' ) );
		}

		$can_change = current_user_can( Installer::CAPABILITY );
		$config     = $this->service->config();
		$now        = time();
		$timezone   = wp_timezone();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Deployment Guard', 'deployment-guard' ) . '</h1>';

		$this->render_notices();
		$this->render_status_table( $config, $now, $timezone );

		if ( ! $can_change ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'You can view this screen because you can manage options, but you do not have the "deployment_guard_bypass" capability, so you cannot change the freeze from here.', 'deployment-guard' )
				. ' ' . esc_html__( 'Ask an administrator to grant it to your role, use WP-CLI, or set the DEPLOYMENT_GUARD_FORCE constant in wp-config.php.', 'deployment-guard' ) . '</p></div>';
		}

		echo '<hr />';

		$this->render_manual_form( $config, $now, $can_change );
		$this->render_schedule_form( $config, $timezone, $can_change );
		$this->render_message_form( $config, $can_change );
		$this->render_protected_summary();
		$this->render_activity_table();

		echo '</div>';
	}

	private function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a fixed result code from our own redirect; no data is acted on.
		$result = isset( $_GET['dg_result'] ) ? sanitize_key( wp_unslash( $_GET['dg_result'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a fixed result code from our own redirect; no data is acted on.
		$error = isset( $_GET['dg_error'] ) ? sanitize_key( wp_unslash( $_GET['dg_error'] ) ) : '';

		if ( '' !== $result ) {
			wp_admin_notice( esc_html( $this->result_text( $result ) ), array( 'type' => 'success' ) );
		}

		if ( '' !== $error ) {
			wp_admin_notice( esc_html( FreezeService::error_text( $error ) ), array( 'type' => 'error' ) );
		}
	}

	private function result_text( string $code ): string {
		switch ( $code ) {
			case 'enabled':
				return __( 'Deployment Guard is now active.', 'deployment-guard' );
			case 'disabled':
				return __( 'Deployment Guard is now inactive.', 'deployment-guard' );
			case 'scheduled':
				return __( 'The scheduled freeze has been saved.', 'deployment-guard' );
			case 'schedule_cleared':
				return __( 'The scheduled freeze has been cleared.', 'deployment-guard' );
			case 'message_saved':
				return __( 'The message has been saved.', 'deployment-guard' );
			case 'unchanged':
				return __( 'Nothing changed.', 'deployment-guard' );
			default:
				return __( 'Done.', 'deployment-guard' );
		}
	}

	private function render_status_table( FreezeConfig $config, int $now, \DateTimeZone $timezone ): void {
		$forced          = $this->service->forced();
		$active          = $this->service->is_active();
		$ends_at         = $this->guard->ends_at();
		$protected_types = $this->guard->protected_types();

		echo '<h2>' . esc_html__( 'Status', 'deployment-guard' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:800px;">';
		echo '<tbody>';

		$this->status_row(
			__( 'State', 'deployment-guard' ),
			$active
				? '<strong>' . esc_html__( 'Active — content changes are locked.', 'deployment-guard' ) . '</strong>'
				: esc_html__( 'Inactive — no restrictions in effect.', 'deployment-guard' )
		);

		if ( null !== $forced ) {
			$this->status_row(
				__( 'Forced by configuration', 'deployment-guard' ),
				$forced
					? esc_html__( 'Yes — DEPLOYMENT_GUARD_FORCE is set to true. It overrides the settings below.', 'deployment-guard' )
					: esc_html__( 'Yes — DEPLOYMENT_GUARD_FORCE is set to false. It overrides the settings below.', 'deployment-guard' )
			);
		}

		$this->status_row( __( 'Manual freeze', 'deployment-guard' ), $config->enabled ? esc_html__( 'On', 'deployment-guard' ) : esc_html__( 'Off', 'deployment-guard' ) );

		$this->status_row( __( 'Schedule', 'deployment-guard' ), $this->schedule_summary( $config, $now, $timezone ) );

		if ( null !== $ends_at ) {
			$this->status_row( __( 'Expected to resume', 'deployment-guard' ), esc_html( $this->format_datetime( $ends_at, $timezone ) ) );
		}

		$this->status_row(
			__( 'Site timezone', 'deployment-guard' ),
			esc_html( wp_timezone_string() ) . ' &mdash; <a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'change', 'deployment-guard' ) . '</a>'
		);

		$this->status_row(
			__( 'Your account', 'deployment-guard' ),
			current_user_can( Installer::CAPABILITY )
				? esc_html__( 'Can bypass the freeze and change these settings.', 'deployment-guard' )
				: esc_html__( 'Restricted while the freeze is active; cannot change these settings.', 'deployment-guard' )
		);

		$this->status_row( __( 'Protected content', 'deployment-guard' ), $this->protected_types_summary( $protected_types ) );

		if ( $config->updated_at > 0 ) {
			$this->status_row( __( 'Last changed', 'deployment-guard' ), esc_html( $this->format_datetime( $config->updated_at, $timezone ) ) . ' ' . $this->user_label( $config->updated_by ) );
		}

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'What a freeze does', 'deployment-guard' ) . '</h3>';
		echo '<p>' . esc_html__( 'While active, users without the "Bypass Deployment Guard" capability cannot create, edit, delete, or restore content of a protected type; cannot manage protected taxonomies, menus, widgets, or Customizer settings; and cannot use the Site Editor. Scheduled posts of a protected type do not publish while the freeze is active — they remain scheduled and publish normally once it ends.', 'deployment-guard' ) . '</p>';
		echo '<p>' . esc_html__( 'Content changes made through the REST API, XML-RPC, WP-CLI, and WP-Cron events are blocked the same way. WP-Cron itself keeps running, and scheduled posts are held rather than lost. Changes that go around WordPress\'s own content functions and capability checks, such as direct database queries, cannot be blocked.', 'deployment-guard' ) . '</p>';
		echo '<p>' . esc_html__( 'The public, logged-out frontend is never affected. Comments, users, plugins, themes, and general site settings are never affected. Users with the bypass capability, and Super Admins on Multisite, can always continue — but their changes can still be overwritten if a deployment or migration proceeds.', 'deployment-guard' ) . '</p>';
	}

	private function status_row( string $label, string $value_html ): void {
		echo '<tr><th scope="row" style="width:220px;">' . esc_html( $label ) . '</th><td>' . $value_html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $value_html is pre-escaped by each caller.
	}

	private function schedule_summary( FreezeConfig $config, int $now, \DateTimeZone $timezone ): string {
		if ( null === $config->schedule_start ) {
			return esc_html__( 'None configured.', 'deployment-guard' );
		}

		$status = $config->schedule_status( $now );

		$label = array(
			'upcoming' => __( 'Upcoming', 'deployment-guard' ),
			'active'   => __( 'Currently active', 'deployment-guard' ),
			'expired'  => __( 'Expired', 'deployment-guard' ),
		)[ $status ] ?? $status;

		return sprintf(
			/* translators: 1: status label, 2: start date and time, 3: end date and time. */
			esc_html__( '%1$s: %2$s to %3$s', 'deployment-guard' ),
			esc_html( $label ),
			esc_html( $this->format_datetime( $config->schedule_start, $timezone ) ),
			esc_html( $this->format_datetime( $config->schedule_end, $timezone ) )
		);
	}

	private function protected_types_summary( array $protected_types ): string {
		$post_type_labels = array();

		foreach ( $protected_types['post_types'] as $post_type ) {
			$object             = get_post_type_object( $post_type );
			$post_type_labels[] = $object ? $object->labels->name : $post_type;
		}

		$taxonomy_labels = array();

		foreach ( $protected_types['taxonomies'] as $taxonomy ) {
			$object            = get_taxonomy( $taxonomy );
			$taxonomy_labels[] = $object ? $object->labels->name : $taxonomy;
		}

		$parts = array();

		if ( $post_type_labels ) {
			$parts[] = esc_html( implode( ', ', $post_type_labels ) );
		}

		if ( $taxonomy_labels ) {
			$parts[] = esc_html( implode( ', ', $taxonomy_labels ) );
		}

		$parts[] = esc_html__( 'menus, widgets, the Customizer, and the Site Editor', 'deployment-guard' );

		return implode( '; ', $parts );
	}

	private function render_manual_form( FreezeConfig $config, int $now, bool $can_change ): void {
		$active_now = $config->is_active( $now );

		echo '<h2>' . esc_html__( 'Manual freeze', 'deployment-guard' ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="deployment_guard_update" />';

		if ( $config->enabled ) {
			echo '<p>' . esc_html__( 'The freeze is currently turned on manually.', 'deployment-guard' ) . '</p>';
			echo '<button type="submit" class="button" name="operation" value="disable"' . disabled( $can_change, false, false ) . '>' . esc_html__( 'Turn off freeze', 'deployment-guard' ) . '</button>';
		} else {
			echo '<p>' . esc_html__( 'Turning this on immediately locks protected content for every user without the bypass capability, until you turn it off again.', 'deployment-guard' ) . '</p>';
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Editors will be unable to save changes while this is on. Make sure any in-progress work is saved first.', 'deployment-guard' ) . '</p></div>';
			echo '<p><label><input type="checkbox" name="confirm" value="1" required /> ' . esc_html__( 'I understand this will lock editing for other users.', 'deployment-guard' ) . '</label></p>';
			echo '<button type="submit" class="button button-primary" name="operation" value="enable"' . disabled( $can_change, false, false ) . '>' . esc_html__( 'Turn on freeze now', 'deployment-guard' ) . '</button>';
		}

		echo '</form>';

		if ( $active_now && ! $config->enabled ) {
			echo '<p class="description">' . esc_html__( 'The freeze is currently active because of the schedule below, not because of this manual switch.', 'deployment-guard' ) . '</p>';
		}
	}

	private function render_schedule_form( FreezeConfig $config, \DateTimeZone $timezone, bool $can_change ): void {
		echo '<h2>' . esc_html__( 'Scheduled freeze window', 'deployment-guard' ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="deployment_guard_update" />';

		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Scheduled freeze window', 'deployment-guard' ) . '</legend>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$start_value = null !== $config->schedule_start ? DateInput::to_input_value( $config->schedule_start, $timezone ) : '';
		$end_value   = null !== $config->schedule_end ? DateInput::to_input_value( $config->schedule_end, $timezone ) : '';

		echo '<tr><th scope="row"><label for="dg-start">' . esc_html__( 'Start', 'deployment-guard' ) . '</label></th><td>';
		echo '<input type="datetime-local" id="dg-start" name="start" step="60" value="' . esc_attr( $start_value ) . '" aria-describedby="dg-schedule-desc" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="dg-end">' . esc_html__( 'End', 'deployment-guard' ) . '</label></th><td>';
		echo '<input type="datetime-local" id="dg-end" name="end" step="60" value="' . esc_attr( $end_value ) . '" aria-describedby="dg-schedule-desc" />';
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<p id="dg-schedule-desc" class="description">' . sprintf(
			/* translators: 1: site timezone name, 2: maximum window length in days. */
			esc_html__( 'Times are in the site timezone (%1$s). Format: YYYY-MM-DD HH:MM. Maximum window: %2$d days.', 'deployment-guard' ),
			esc_html( wp_timezone_string() ),
			(int) ( FreezeConfig::MAX_WINDOW_SECONDS / DAY_IN_SECONDS )
		) . '</p>';

		echo '<p><label><input type="checkbox" name="confirm" value="1" required /> ' . esc_html__( 'I understand this will lock editing for other users during the window above.', 'deployment-guard' ) . '</label></p>';

		echo '<button type="submit" class="button button-primary" name="operation" value="schedule"' . disabled( $can_change, false, false ) . '>' . esc_html__( 'Save schedule', 'deployment-guard' ) . '</button> ';
		echo '<button type="submit" class="button" name="operation" value="clear_schedule" formnovalidate' . disabled( $can_change, false, false ) . '>' . esc_html__( 'Clear schedule', 'deployment-guard' ) . '</button>';

		echo '</fieldset>';
		echo '</form>';
	}

	private function render_message_form( FreezeConfig $config, bool $can_change ): void {
		echo '<h2>' . esc_html__( 'Message shown to editors', 'deployment-guard' ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="deployment_guard_update" />';

		echo '<p><label for="dg-message" class="screen-reader-text">' . esc_html__( 'Custom message', 'deployment-guard' ) . '</label>';
		echo '<textarea id="dg-message" name="message" rows="4" maxlength="' . (int) FreezeConfig::MAX_MESSAGE_LENGTH . '" class="large-text" aria-describedby="dg-message-desc">' . esc_textarea( $config->message ) . '</textarea></p>';
		echo '<p id="dg-message-desc" class="description">' . sprintf(
			/* translators: %d: maximum message length in characters. */
			esc_html__( 'Shown to restricted users in addition to the standard explanation. Up to %d characters.', 'deployment-guard' ),
			(int) FreezeConfig::MAX_MESSAGE_LENGTH
		) . '</p>';

		echo '<button type="submit" class="button button-primary" name="operation" value="message"' . disabled( $can_change, false, false ) . '>' . esc_html__( 'Save message', 'deployment-guard' ) . '</button>';

		echo '</form>';
	}

	private function render_protected_summary(): void {
		$roles_with_bypass = array();

		foreach ( wp_roles()->roles as $role_name => $role_data ) {
			if ( ! empty( $role_data['capabilities'][ Installer::CAPABILITY ] ) ) {
				$roles_with_bypass[] = translate_user_role( $role_data['name'] );
			}
		}

		echo '<h2>' . esc_html__( 'Roles that can bypass the freeze', 'deployment-guard' ) . '</h2>';

		if ( $roles_with_bypass ) {
			echo '<p>' . esc_html( implode( ', ', $roles_with_bypass ) ) . '</p>';
		} else {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No role currently has the "deployment_guard_bypass" capability. Only WP-CLI and the DEPLOYMENT_GUARD_FORCE constant can change the freeze.', 'deployment-guard' ) . '</p></div>';
		}
	}

	private function render_activity_table(): void {
		$entries = $this->service->entries();

		echo '<h2>' . esc_html__( 'Activity', 'deployment-guard' ) . '</h2>';

		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'No activity recorded yet.', 'deployment-guard' ) . '</p>';

			return;
		}

		$timezone = wp_timezone();

		echo '<table class="widefat striped">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Deployment Guard activity log', 'deployment-guard' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Time', 'deployment-guard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Event', 'deployment-guard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'User', 'deployment-guard' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Details', 'deployment-guard' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( array_slice( $entries, 0, 50 ) as $entry ) {
			$time = isset( $entry['t'] ) ? (int) $entry['t'] : 0;
			$type = isset( $entry['type'] ) && is_string( $entry['type'] ) ? $entry['type'] : '';
			$user = isset( $entry['user'] ) ? (int) $entry['user'] : 0;

			echo '<tr>';
			echo '<td><time datetime="' . esc_attr( gmdate( 'c', $time ) ) . '">' . esc_html( $this->format_datetime( $time, $timezone ) ) . '</time></td>';
			echo '<td>' . esc_html( $this->event_label( $type ) ) . '</td>';
			echo '<td>' . $this->user_label( $user ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- user_label() already escapes its output.
			echo '<td>' . esc_html( $this->context_summary( $entry['ctx'] ?? array() ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function event_label( string $type ): string {
		switch ( $type ) {
			case 'enabled':
				return __( 'Freeze enabled', 'deployment-guard' );
			case 'disabled':
				return __( 'Freeze disabled', 'deployment-guard' );
			case 'schedule_set':
				return __( 'Schedule changed', 'deployment-guard' );
			case 'schedule_cleared':
				return __( 'Schedule cleared', 'deployment-guard' );
			case 'message_updated':
				return __( 'Message changed', 'deployment-guard' );
			case 'blocked':
				return __( 'Blocked write attempt', 'deployment-guard' );
			default:
				return $type;
		}
	}

	private function context_summary( array $ctx ): string {
		$parts = array();

		foreach ( array( 'vector', 'target', 'source' ) as $key ) {
			if ( ! empty( $ctx[ $key ] ) && is_string( $ctx[ $key ] ) ) {
				$parts[] = $ctx[ $key ];
			}
		}

		return implode( ' / ', $parts );
	}

	private function user_label( int $user_id ): string {
		if ( 0 === $user_id ) {
			return esc_html__( 'System / WP-CLI', 'deployment-guard' );
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return esc_html(
				sprintf(
					/* translators: %d: numeric user ID of a since-deleted account. */
					__( '#%d (deleted)', 'deployment-guard' ),
					$user_id
				)
			);
		}

		return esc_html( $user->display_name );
	}

	private function format_datetime( int $timestamp, \DateTimeZone $timezone ): string {
		return sprintf(
			/* translators: 1: date, 2: time */
			__( '%1$s at %2$s', 'deployment-guard' ),
			wp_date( get_option( 'date_format', 'F j, Y' ), $timestamp, $timezone ),
			wp_date( get_option( 'time_format', 'g:i a' ), $timestamp, $timezone )
		);
	}
}
