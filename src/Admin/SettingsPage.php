<?php
/**
 * The Tools > Stillgate settings screen.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Admin;

use Digibold\DeploymentGuard\DateInput;
use Digibold\DeploymentGuard\FreezeConfig;
use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Guard;
use Digibold\DeploymentGuard\Installer;
use Digibold\DeploymentGuard\Plugin;
use Digibold\DeploymentGuard\Receipt;
use Digibold\DeploymentGuard\Scope;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the settings screen under Tools > Stillgate and handles
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
	private const PAGE_SLUG    = 'stillgate';
	private const VIEW_CAP     = 'manage_options';

	/**
	 * Minutes offered as one-click timed freezes, and the extensions.
	 *
	 * @var int[]
	 */
	private const TIMER_CHOICES = array( 15, 30, 60, 120, 240 );

	/**
	 * @var int[]
	 */
	private const EXTEND_CHOICES = array( 15, 30, 60 );

	private FreezeService $service;

	private Guard $guard;

	private ?Receipt $receipt;

	private string $assets_url;

	public function __construct( FreezeService $service, Guard $guard, ?Receipt $receipt = null, string $assets_url = '' ) {
		$this->service    = $service;
		$this->guard      = $guard;
		$this->receipt    = $receipt;
		$this->assets_url = $assets_url;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_deployment_guard_update', array( $this, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'removable_query_args', array( $this, 'add_removable_query_args' ) );
	}

	public function add_page(): void {
		add_management_page(
			__( 'Stillgate', 'stillgate' ),
			__( 'Stillgate', 'stillgate' ),
			self::VIEW_CAP,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The stylesheet is loaded on this one screen only.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook_suffix || '' === $this->assets_url ) {
			return;
		}

		wp_enqueue_style( 'stillgate-admin', $this->assets_url . 'admin.css', array(), Plugin::VERSION );
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
				esc_html__( 'Sorry, you are not allowed to change Stillgate settings.', 'stillgate' ),
				'',
				array( 'response' => 403 )
			);
		}

		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$allowed   = array( 'enable', 'disable', 'schedule', 'clear_schedule', 'message', 'freeze_for', 'extend', 'scope' );

		if ( ! in_array( $operation, $allowed, true ) ) {
			wp_die( esc_html__( 'Unknown Stillgate action.', 'stillgate' ), '', array( 'response' => 400 ) );
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
		if ( in_array( $operation, array( 'enable', 'schedule', 'freeze_for' ), true ) && ! $confirm ) {
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

			case 'freeze_for':
				return $this->process_freeze_for();

			case 'extend':
				return $this->process_extend();

			case 'scope':
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce for this whole submission was already verified in resolve_redirect_url().
				$comments = ! empty( $_POST['scope_comments'] );
				$forms    = ! empty( $_POST['scope_forms'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.

				return $this->service->set_scope( new Scope( $comments, $forms ), 'ui' );
		}

		return new \WP_Error( 'unknown_operation', __( 'Unknown Stillgate action.', 'stillgate' ) );
	}

	/**
	 * Start a freeze from the quick-start panel: for a number of minutes,
	 * or until somebody ends it. An edited message is saved first, so a
	 * message that is too long refuses the request before anything locks.
	 *
	 * @return string|\WP_Error
	 */
	private function process_freeze_for() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce for this whole submission was already verified in resolve_redirect_url().
		$duration = isset( $_POST['duration'] ) ? sanitize_key( wp_unslash( $_POST['duration'] ) ) : '';
		$minutes  = 'manual' === $duration ? 0 : absint( $duration );

		if ( 'manual' !== $duration && $minutes < 1 ) {
			return new \WP_Error( 'invalid_duration', FreezeService::error_text( 'invalid_duration' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		if ( isset( $_POST['message'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
			$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ) );

			if ( $message !== $this->service->config()->message ) {
				$saved = $this->service->set_message( $message, 'ui' );

				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
			}
		}

		if ( 'manual' === $duration ) {
			return $this->service->enable( 'ui' );
		}

		return $this->service->freeze_for( $minutes * MINUTE_IN_SECONDS, 'ui' );
	}

	/**
	 * @return string|\WP_Error
	 */
	private function process_extend() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce for this whole submission was already verified in resolve_redirect_url().
		$minutes = isset( $_POST['by'] ) ? absint( wp_unslash( $_POST['by'] ) ) : 0;

		if ( ! in_array( $minutes, self::EXTEND_CHOICES, true ) ) {
			return new \WP_Error( 'invalid_duration', FreezeService::error_text( 'invalid_duration' ) );
		}

		return $this->service->extend( $minutes * MINUTE_IN_SECONDS, 'ui' );
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
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'stillgate' ) );
		}

		$can_change = current_user_can( Installer::CAPABILITY );
		$config     = $this->service->config();
		$now        = time();
		$timezone   = wp_timezone();

		echo '<div class="wrap stillgate">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Stillgate', 'stillgate' ) . '</h1>';
		echo '<span class="sg-tagline">' . esc_html__( 'Editorial freeze for deployments', 'stillgate' ) . '</span>';
		echo '<hr class="wp-header-end" />';

		$this->render_notices();

		if ( ! $can_change ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'You can view this screen because you can manage options, but you do not have the "deployment_guard_bypass" capability, so you cannot change the freeze from here.', 'stillgate' )
				. ' ' . esc_html__( 'Ask an administrator to grant it to your role, use WP-CLI, or set the DEPLOYMENT_GUARD_FORCE constant in wp-config.php.', 'stillgate' ) . '</p></div>';
		}

		$this->render_hero( $config, $now, $timezone, $can_change );

		echo '<div class="sg-grid">';
		$this->render_receipt_card( $timezone );
		$this->render_scope_card( $can_change );
		$this->render_schedule_card( $config, $now, $timezone, $can_change );
		$this->render_message_card( $config, $can_change );
		$this->render_pipelines_card();
		$this->render_access_card( $config, $timezone );
		echo '</div>';

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
				return __( 'Stillgate is now active.', 'stillgate' );
			case 'timed':
				$ends_at = $this->guard->ends_at();

				return null !== $ends_at
					? sprintf(
						/* translators: %s: date and time the freeze ends by itself. */
						__( 'Stillgate is now active and ends by itself on %s.', 'stillgate' ),
						$this->format_datetime( $ends_at, wp_timezone() )
					)
					: __( 'Stillgate is now active.', 'stillgate' );
			case 'extended':
				return __( 'The freeze was extended.', 'stillgate' );
			case 'disabled':
				return __( 'Stillgate is now inactive.', 'stillgate' );
			case 'scheduled':
				return __( 'The scheduled freeze has been saved.', 'stillgate' );
			case 'schedule_cleared':
				return __( 'The scheduled freeze has been cleared.', 'stillgate' );
			case 'message_saved':
				return __( 'The message has been saved.', 'stillgate' );
			case 'scope_saved':
				return __( 'What a freeze covers has been saved.', 'stillgate' );
			case 'unchanged':
				return __( 'Nothing changed.', 'stillgate' );
			default:
				return __( 'Done.', 'stillgate' );
		}
	}

	/**
	 * The status panel: what is happening right now, and the one or two
	 * things somebody standing in front of it most likely wants to do.
	 */
	private function render_hero( FreezeConfig $config, int $now, \DateTimeZone $timezone, bool $can_change ): void {
		$active   = $this->service->is_active();
		$forced   = $this->service->forced();
		$ends_at  = $this->guard->ends_at();
		$upcoming = ! $active && 'upcoming' === $config->schedule_status( $now );

		$modifier = $active ? 'active' : ( $upcoming ? 'upcoming' : 'idle' );

		echo '<section class="sg-hero sg-hero--' . esc_attr( $modifier ) . '" aria-labelledby="sg-hero-title">';
		echo '<div class="sg-hero__status">';
		echo '<span class="sg-dot" aria-hidden="true"></span>';
		echo '<div>';
		echo '<p class="sg-eyebrow">' . esc_html__( 'Status', 'stillgate' ) . '</p>';

		if ( $active ) {
			echo '<h2 id="sg-hero-title">' . esc_html__( 'Content is frozen', 'stillgate' ) . '</h2>';
		} elseif ( $upcoming ) {
			echo '<h2 id="sg-hero-title">' . esc_html__( 'Open — a freeze is scheduled', 'stillgate' ) . '</h2>';
		} else {
			echo '<h2 id="sg-hero-title">' . esc_html__( 'Open — editing is not restricted', 'stillgate' ) . '</h2>';
		}

		echo '<p class="sg-hero__detail">' . esc_html( $this->hero_detail( $config, $now, $timezone, $active, $upcoming, $ends_at ) ) . '</p>';

		if ( null !== $forced ) {
			echo '<p class="sg-hero__forced">' . esc_html(
				$forced
					? __( 'DEPLOYMENT_GUARD_FORCE is set to true in wp-config.php: it overrides everything below.', 'stillgate' )
					: __( 'DEPLOYMENT_GUARD_FORCE is set to false in wp-config.php: it overrides everything below.', 'stillgate' )
			) . '</p>';
		}

		echo '</div></div>';

		echo '<div class="sg-hero__actions">';

		if ( $active ) {
			$this->render_active_actions( $config, $now, $can_change );
		} else {
			$this->render_quick_start( $config, $can_change );
		}

		echo '</div></section>';
	}

	private function hero_detail( FreezeConfig $config, int $now, \DateTimeZone $timezone, bool $active, bool $upcoming, ?int $ends_at ): string {
		if ( $active && null !== $ends_at ) {
			return sprintf(
				/* translators: 1: relative time such as "24 minutes", 2: date and time the freeze ends. */
				__( 'Ends in %1$s, on %2$s.', 'stillgate' ),
				human_time_diff( $now, $ends_at ),
				$this->format_datetime( $ends_at, $timezone )
			);
		}

		if ( $active ) {
			return __( 'It stays on until somebody ends it.', 'stillgate' );
		}

		if ( $upcoming && null !== $config->schedule_start && null !== $config->schedule_end ) {
			return sprintf(
				/* translators: 1: window start, 2: window end. */
				__( 'Next freeze: %1$s to %2$s.', 'stillgate' ),
				$this->format_datetime( $config->schedule_start, $timezone ),
				$this->format_datetime( $config->schedule_end, $timezone )
			);
		}

		return __( 'Start one below before a deployment or migration, or schedule a window.', 'stillgate' );
	}

	private function render_active_actions( FreezeConfig $config, int $now, bool $can_change ): void {
		$extendable = ! $config->enabled && 'active' === $config->schedule_status( $now );

		if ( $extendable ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sg-inline-form">';
			wp_nonce_field( self::NONCE_ACTION );
			echo '<input type="hidden" name="action" value="deployment_guard_update" />';
			echo '<input type="hidden" name="operation" value="extend" />';
			echo '<span class="sg-actions-label" id="sg-extend-label">' . esc_html__( 'Taking longer?', 'stillgate' ) . '</span> ';
			echo '<span role="group" aria-labelledby="sg-extend-label">';

			foreach ( self::EXTEND_CHOICES as $minutes ) {
				echo '<button type="submit" class="button" name="by" value="' . (int) $minutes . '"' . disabled( $can_change, false, false ) . '>' . esc_html( $this->extend_label( $minutes ) ) . '</button> ';
			}

			echo '</span>';
			echo '</form>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sg-inline-form">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="deployment_guard_update" />';
		echo '<button type="submit" class="button button-primary sg-button-end" name="operation" value="disable"' . disabled( $can_change, false, false ) . '>' . esc_html__( 'End freeze now', 'stillgate' ) . '</button>';
		echo '</form>';

		if ( ! $config->enabled && ! $extendable ) {
			echo '<p class="description">' . esc_html__( 'The freeze is active because of the schedule below, not because of a manual switch.', 'stillgate' ) . '</p>';
		}
	}

	private function extend_label( int $minutes ): string {
		if ( $minutes >= 60 ) {
			return sprintf(
				/* translators: %d: number of hours to extend the freeze by. */
				_n( '+%d hour', '+%d hours', intdiv( $minutes, 60 ), 'stillgate' ),
				intdiv( $minutes, 60 )
			);
		}

		return sprintf(
			/* translators: %d: number of minutes to extend the freeze by. */
			__( '+%d min', 'stillgate' ),
			$minutes
		);
	}

	private function timer_label( int $minutes ): string {
		if ( $minutes >= 60 ) {
			return sprintf(
				/* translators: %d: number of hours. */
				_n( '%d hour', '%d hours', intdiv( $minutes, 60 ), 'stillgate' ),
				intdiv( $minutes, 60 )
			);
		}

		return sprintf(
			/* translators: %d: number of minutes. */
			__( '%d min', 'stillgate' ),
			$minutes
		);
	}

	/**
	 * One form, pick a length and go: the common case of a deployment,
	 * with a timer so it can never be left on by accident.
	 */
	private function render_quick_start( FreezeConfig $config, bool $can_change ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sg-quick">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="deployment_guard_update" />';
		echo '<input type="hidden" name="operation" value="freeze_for" />';

		echo '<fieldset class="sg-pills"><legend>' . esc_html__( 'Freeze for', 'stillgate' ) . '</legend>';

		foreach ( self::TIMER_CHOICES as $index => $minutes ) {
			$id = 'sg-for-' . $minutes;
			echo '<span class="sg-pill"><input type="radio" id="' . esc_attr( $id ) . '" name="duration" value="' . (int) $minutes . '"' . checked( 1 === $index, true, false ) . ' /><label for="' . esc_attr( $id ) . '">' . esc_html( $this->timer_label( $minutes ) ) . '</label></span>';
		}

		echo '<span class="sg-pill"><input type="radio" id="sg-for-manual" name="duration" value="manual" /><label for="sg-for-manual">' . esc_html__( 'Until I end it', 'stillgate' ) . '</label></span>';
		echo '</fieldset>';

		echo '<p><label for="sg-quick-message" class="sg-label">' . esc_html__( 'Message to editors (optional)', 'stillgate' ) . '</label>';
		echo '<input type="text" id="sg-quick-message" name="message" class="large-text" maxlength="' . (int) FreezeConfig::MAX_MESSAGE_LENGTH . '" value="' . esc_attr( $config->message ) . '" placeholder="' . esc_attr__( 'Deploying release 12, back in 30 minutes.', 'stillgate' ) . '" /></p>';

		if ( null !== $config->schedule_start ) {
			echo '<p class="description">' . esc_html__( 'Starting a freeze here replaces the scheduled window.', 'stillgate' ) . '</p>';
		}

		echo '<p><label><input type="checkbox" name="confirm" value="1" required /> ' . esc_html__( 'I understand this will lock editing for other users.', 'stillgate' ) . '</label></p>';
		echo '<button type="submit" class="button button-primary button-hero sg-button-start"' . disabled( $can_change, false, false ) . '>' . esc_html__( 'Start freeze', 'stillgate' ) . '</button>';
		echo '</form>';
	}

	/**
	 * A card with a heading, so every section reads the same way.
	 */
	private function open_card( string $id, string $title, string $extra_class = '' ): void {
		echo '<section class="sg-card ' . esc_attr( $extra_class ) . '" aria-labelledby="' . esc_attr( $id ) . '">';
		echo '<h2 id="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</h2>';
	}

	private function close_card(): void {
		echo '</section>';
	}

	private function render_receipt_card( \DateTimeZone $timezone ): void {
		$this->open_card( 'sg-receipt-title', __( 'Freeze receipt', 'stillgate' ) );

		$report = null === $this->receipt ? array( 'state' => 'none' ) : $this->receipt->report();

		if ( 'none' === $report['state'] ) {
			echo '<p>' . esc_html__( 'No freeze has run yet. After one, this shows whether anything changed while it was on, so you know a database dump taken at the start is still complete.', 'stillgate' ) . '</p>';
			$this->close_card();

			return;
		}

		$clean   = ! empty( $report['clean'] );
		$content = $report['content'];
		$open    = 'open' === $report['state'];

		echo '<p class="sg-receipt sg-receipt--' . ( $clean ? 'clean' : 'dirty' ) . '"><span class="sg-receipt__mark" aria-hidden="true">' . ( $clean ? '&#10003;' : '!' ) . '</span> ';

		if ( $clean ) {
			echo esc_html( $open ? __( 'Nothing has changed so far.', 'stillgate' ) : __( 'Nothing changed while the freeze was on.', 'stillgate' ) );
		} else {
			echo esc_html(
				sprintf(
					/* translators: %d: number of posts that changed. */
					_n( '%d post changed during the freeze.', '%d posts changed during the freeze.', max( 1, (int) $content['changed_posts'] ), 'stillgate' ),
					(int) $content['changed_posts']
				)
			);
		}

		echo '</p>';

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: freeze start, 2: freeze end or "still running". */
				__( '%1$s to %2$s', 'stillgate' ),
				$this->format_datetime( (int) strtotime( (string) $report['opened_at'] ), $timezone ),
				null !== $report['closed_at'] ? $this->format_datetime( (int) strtotime( (string) $report['closed_at'] ), $timezone ) : __( 'still running', 'stillgate' )
			)
		) . '</p>';

		echo '<ul class="sg-facts">';
		$this->fact( (int) $content['changed_posts'], __( 'posts saved, created or trashed', 'stillgate' ) );
		$this->fact( (int) $content['deleted_estimate'], __( 'posts removed (estimate)', 'stillgate' ) );
		$this->fact( ! empty( $content['terms_changed'] ) ? 1 : 0, __( 'term changes', 'stillgate' ), true );
		$this->fact( (int) $content['comments_added'], __( 'comments added', 'stillgate' ) );
		echo '</ul>';

		$paused = $report['paused'];

		if ( $paused[ Scope::COMMENTS ] > 0 || $paused[ Scope::FORMS ] > 0 ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: number of comments paused, 2: number of form submissions paused. */
					__( 'Paused visitors: %1$d comments, %2$d form submissions.', 'stillgate' ),
					(int) $paused[ Scope::COMMENTS ],
					(int) $paused[ Scope::FORMS ]
				)
			) . '</p>';
		}

		if ( ! $clean && $content['changed_ids'] ) {
			$links = array();

			foreach ( array_slice( $content['changed_ids'], 0, 10 ) as $post_id ) {
				$title   = get_the_title( (int) $post_id );
				$links[] = '<a href="' . esc_url( (string) get_edit_post_link( (int) $post_id ) ) . '">' . esc_html( '' !== $title ? $title : '#' . (int) $post_id ) . '</a>';
			}

			echo '<p class="sg-changed">' . wp_kses( implode( ', ', $links ), array( 'a' => array( 'href' => array() ) ) ) . ( count( $content['changed_ids'] ) > 10 ? '&hellip;' : '' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'To carry these over to another environment with WordPress’s own export:', 'stillgate' ) . '</p>';
			echo '<pre class="sg-code">wp export --post__in="$(wp stillgate drift)"</pre>';
		}

		$this->close_card();
	}

	private function fact( int $count, string $label, bool $as_yes_no = false ): void {
		$value = $as_yes_no ? ( $count > 0 ? __( 'yes', 'stillgate' ) : __( 'no', 'stillgate' ) ) : (string) $count;

		echo '<li><strong>' . esc_html( $value ) . '</strong> ' . esc_html( $label ) . '</li>';
	}

	private function render_scope_card( bool $can_change ): void {
		$scope = $this->service->scope();

		$this->open_card( 'sg-scope-title', __( 'What a freeze covers', 'stillgate' ) );
		echo '<p>' . esc_html__( 'A freeze always locks editing of content, media, menus and widgets. It can also pause what visitors send in, because anything saved to the database during a deployment or migration can be lost when the database is replaced.', 'stillgate' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="deployment_guard_update" />';
		echo '<input type="hidden" name="operation" value="scope" />';

		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Pause these during a freeze', 'stillgate' ) . '</legend>';
		echo '<p><label><input type="checkbox" name="scope_comments" value="1"' . checked( $scope->comments, true, false ) . disabled( $can_change, false, false ) . ' /> <strong>' . esc_html__( 'Visitor comments', 'stillgate' ) . '</strong></label><br />';
		echo '<span class="description">' . esc_html__( 'New comments, reviews, pingbacks and trackbacks are refused with a friendly message. Order notes and other internal comments keep working.', 'stillgate' ) . '</span></p>';
		echo '<p><label><input type="checkbox" name="scope_forms" value="1"' . checked( $scope->forms, true, false ) . disabled( $can_change, false, false ) . ' /> <strong>' . esc_html__( 'Contact and other forms', 'stillgate' ) . '</strong></label><br />';
		echo '<span class="description">' . esc_html__( 'Submissions to Contact Form 7, WPForms, Gravity Forms, Ninja Forms, Fluent Forms, Forminator, Formidable and Everest Forms are refused. Shops keep taking orders.', 'stillgate' ) . '</span></p>';
		echo '</fieldset>';

		echo '<button type="submit" class="button"' . disabled( $can_change, false, false ) . '>' . esc_html__( 'Save', 'stillgate' ) . '</button> ';
		echo '<span class="description">' . esc_html__( 'Applies at once, even to a freeze that is already running.', 'stillgate' ) . '</span>';
		echo '</form>';
		$this->close_card();
	}

	private function render_schedule_card( FreezeConfig $config, int $now, \DateTimeZone $timezone, bool $can_change ): void {
		$this->open_card( 'sg-schedule-title', __( 'Scheduled freeze window', 'stillgate' ) );
		echo '<p>' . esc_html( $this->schedule_text( $config, $now, $timezone ) ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="deployment_guard_update" />';

		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Scheduled freeze window', 'stillgate' ) . '</legend>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$start_value = null !== $config->schedule_start ? DateInput::to_input_value( $config->schedule_start, $timezone ) : '';
		$end_value   = null !== $config->schedule_end ? DateInput::to_input_value( $config->schedule_end, $timezone ) : '';

		echo '<tr><th scope="row"><label for="dg-start">' . esc_html__( 'Start', 'stillgate' ) . '</label></th><td>';
		echo '<input type="datetime-local" id="dg-start" name="start" step="60" value="' . esc_attr( $start_value ) . '" aria-describedby="dg-schedule-desc" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="dg-end">' . esc_html__( 'End', 'stillgate' ) . '</label></th><td>';
		echo '<input type="datetime-local" id="dg-end" name="end" step="60" value="' . esc_attr( $end_value ) . '" aria-describedby="dg-schedule-desc" />';
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<p id="dg-schedule-desc" class="description">' . sprintf(
			/* translators: 1: site timezone name, 2: maximum window length in days. */
			esc_html__( 'Times are in the site timezone (%1$s). Format: YYYY-MM-DD HH:MM. Maximum window: %2$d days.', 'stillgate' ),
			esc_html( wp_timezone_string() ),
			(int) ( FreezeConfig::MAX_WINDOW_SECONDS / DAY_IN_SECONDS )
		) . '</p>';

		echo '<p><label><input type="checkbox" name="confirm" value="1" required /> ' . esc_html__( 'I understand this will lock editing for other users during the window above.', 'stillgate' ) . '</label></p>';

		echo '<button type="submit" class="button button-primary" name="operation" value="schedule"' . disabled( $can_change, false, false ) . '>' . esc_html__( 'Save schedule', 'stillgate' ) . '</button> ';
		echo '<button type="submit" class="button" name="operation" value="clear_schedule" formnovalidate' . disabled( $can_change, false, false ) . '>' . esc_html__( 'Clear schedule', 'stillgate' ) . '</button>';

		echo '</fieldset>';
		echo '</form>';
		$this->close_card();
	}

	private function schedule_text( FreezeConfig $config, int $now, \DateTimeZone $timezone ): string {
		if ( null === $config->schedule_start || null === $config->schedule_end ) {
			return __( 'No window is scheduled.', 'stillgate' );
		}

		$labels = array(
			'upcoming' => __( 'Upcoming', 'stillgate' ),
			'active'   => __( 'Running now', 'stillgate' ),
			'expired'  => __( 'Finished', 'stillgate' ),
		);

		return sprintf(
			/* translators: 1: status label, 2: start date and time, 3: end date and time. */
			__( '%1$s: %2$s to %3$s', 'stillgate' ),
			$labels[ $config->schedule_status( $now ) ] ?? $config->schedule_status( $now ),
			$this->format_datetime( $config->schedule_start, $timezone ),
			$this->format_datetime( $config->schedule_end, $timezone )
		);
	}

	private function render_message_card( FreezeConfig $config, bool $can_change ): void {
		$this->open_card( 'sg-message-title', __( 'Message shown to editors', 'stillgate' ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="deployment_guard_update" />';

		echo '<p><label for="dg-message" class="screen-reader-text">' . esc_html__( 'Custom message', 'stillgate' ) . '</label>';
		echo '<textarea id="dg-message" name="message" rows="4" maxlength="' . (int) FreezeConfig::MAX_MESSAGE_LENGTH . '" class="large-text" aria-describedby="dg-message-desc">' . esc_textarea( $config->message ) . '</textarea></p>';
		echo '<p id="dg-message-desc" class="description">' . sprintf(
			/* translators: %d: maximum message length in characters. */
			esc_html__( 'Shown to restricted users in addition to the standard explanation. Up to %d characters.', 'stillgate' ),
			(int) FreezeConfig::MAX_MESSAGE_LENGTH
		) . '</p>';

		echo '<button type="submit" class="button button-primary" name="operation" value="message"' . disabled( $can_change, false, false ) . '>' . esc_html__( 'Save message', 'stillgate' ) . '</button>';

		echo '</form>';
		$this->close_card();
	}

	/**
	 * How a deploy tool drives Stillgate. Plain snippets, nothing sent
	 * anywhere.
	 */
	private function render_pipelines_card(): void {
		$this->open_card( 'sg-pipelines-title', __( 'Deployment pipelines', 'stillgate' ) );
		echo '<p>' . esc_html__( 'Start and end a freeze from your deploy script, so it can never be forgotten.', 'stillgate' ) . '</p>';

		echo '<p class="sg-label">' . esc_html__( 'WP-CLI', 'stillgate' ) . '</p>';
		echo '<pre class="sg-code">wp stillgate enable --for=30m
wp stillgate receipt --check
wp stillgate disable</pre>';

		echo '<p class="sg-label">' . esc_html__( 'REST API (use an Application Password)', 'stillgate' ) . '</p>';
		echo '<pre class="sg-code">curl -u "user:application-password" \
  -X POST ' . esc_html( rest_url( 'stillgate/v1/freeze' ) ) . ' \
  -d "for=30m"</pre>';
		$this->close_card();
	}

	private function render_access_card( FreezeConfig $config, \DateTimeZone $timezone ): void {
		$roles_with_bypass = array();

		foreach ( wp_roles()->roles as $role_data ) {
			if ( ! empty( $role_data['capabilities'][ Installer::CAPABILITY ] ) ) {
				$roles_with_bypass[] = translate_user_role( $role_data['name'] );
			}
		}

		$this->open_card( 'sg-access-title', __( 'Access', 'stillgate' ) );

		echo '<table class="sg-details" role="presentation"><tbody>';

		$this->detail_row(
			__( 'Your account', 'stillgate' ),
			current_user_can( Installer::CAPABILITY )
				? esc_html__( 'Can bypass the freeze and change these settings.', 'stillgate' )
				: esc_html__( 'Restricted while the freeze is active; cannot change these settings.', 'stillgate' )
		);

		$this->detail_row(
			__( 'Roles that can bypass', 'stillgate' ),
			$roles_with_bypass
				? esc_html( implode( ', ', $roles_with_bypass ) )
				: esc_html__( 'None. Only WP-CLI and the DEPLOYMENT_GUARD_FORCE constant can change the freeze.', 'stillgate' )
		);

		$this->detail_row(
			__( 'Site timezone', 'stillgate' ),
			esc_html( wp_timezone_string() ) . ' &mdash; <a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'change', 'stillgate' ) . '</a>'
		);

		if ( $config->updated_at > 0 ) {
			$this->detail_row( __( 'Last changed', 'stillgate' ), esc_html( $this->format_datetime( $config->updated_at, $timezone ) ) . ' ' . $this->user_label( $config->updated_by ) );
		}

		echo '</tbody></table>';
		$this->close_card();
	}

	private function detail_row( string $label, string $value_html ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $value_html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $value_html is pre-escaped by each caller.
	}

	private function render_protected_summary(): void {
		echo '<details class="sg-details-block"><summary>' . esc_html__( 'What a freeze does', 'stillgate' ) . '</summary>';
		echo '<p>' . esc_html__( 'While active, users without the "deployment_guard_bypass" capability cannot create, edit, delete, or restore content of a protected type; cannot manage protected taxonomies, menus, widgets, or Customizer settings; and cannot use the Site Editor. Scheduled posts of a protected type do not publish while the freeze is active — they remain scheduled and publish normally once it ends.', 'stillgate' ) . '</p>';
		echo '<p>' . esc_html__( 'Content changes made through the REST API, XML-RPC, WP-CLI, and WP-Cron events are blocked the same way. WP-Cron itself keeps running, and scheduled posts are held rather than lost. Changes that go around WordPress\'s own content functions and capability checks, such as direct database queries, cannot be blocked.', 'stillgate' ) . '</p>';
		echo '<p>' . esc_html__( 'The public, logged-out frontend is never affected unless you pause comments or forms above. Users, plugins, themes, and general site settings are never affected. Users with the bypass capability, and Super Admins on Multisite, can always continue — but their changes can still be overwritten if a deployment or migration proceeds.', 'stillgate' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Protected content:', 'stillgate' ) . '</strong> ' . $this->protected_types_summary( $this->guard->protected_types() ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- protected_types_summary() escapes its output.
		echo '</details>';
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

		$parts[] = esc_html__( 'menus, widgets, the Customizer, and the Site Editor', 'stillgate' );

		return implode( '; ', $parts );
	}

	private function render_activity_table(): void {
		$entries = $this->service->entries();

		echo '<h2 class="sg-activity-title">' . esc_html__( 'Activity', 'stillgate' ) . '</h2>';

		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'No activity recorded yet.', 'stillgate' ) . '</p>';

			return;
		}

		$timezone = wp_timezone();

		echo '<table class="widefat striped">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Stillgate activity log', 'stillgate' ) . '</caption>';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Time', 'stillgate' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Event', 'stillgate' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'User', 'stillgate' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Details', 'stillgate' ) . '</th>';
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
				return __( 'Freeze enabled', 'stillgate' );
			case 'disabled':
				return __( 'Freeze disabled', 'stillgate' );
			case 'schedule_set':
				return __( 'Schedule or timer set', 'stillgate' );
			case 'schedule_cleared':
				return __( 'Schedule cleared', 'stillgate' );
			case 'message_updated':
				return __( 'Message changed', 'stillgate' );
			case 'scope_changed':
				return __( 'Coverage changed', 'stillgate' );
			case 'blocked':
				return __( 'Blocked write attempt', 'stillgate' );
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
			return esc_html__( 'System / WP-CLI', 'stillgate' );
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return esc_html(
				sprintf(
					/* translators: %d: numeric user ID of a since-deleted account. */
					__( '#%d (deleted)', 'stillgate' ),
					$user_id
				)
			);
		}

		return esc_html( $user->display_name );
	}

	private function format_datetime( int $timestamp, \DateTimeZone $timezone ): string {
		return sprintf(
			/* translators: 1: date, 2: time */
			__( '%1$s at %2$s', 'stillgate' ),
			wp_date( get_option( 'date_format', 'F j, Y' ), $timestamp, $timezone ),
			wp_date( get_option( 'time_format', 'g:i a' ), $timestamp, $timezone )
		);
	}
}
