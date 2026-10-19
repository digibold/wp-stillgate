<?php
/**
 * A freeze indicator in the admin bar.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Admin;

use Digibold\DeploymentGuard\FreezeService;
use Digibold\DeploymentGuard\Guard;
use Digibold\DeploymentGuard\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * While a freeze is running, or about to start within the hour, the admin
 * bar shows a small status pill to the people it concerns, on every screen
 * and on the site itself when they are logged in. People who can change
 * the freeze get its quick actions in a drop-down; everyone else just sees
 * when it ends.
 *
 * It appears only while there is something to show and disappears on its
 * own, so it is a status indicator, not a notice, and it asks for nothing.
 * Nothing here runs for visitors: the admin bar is only built for logged-in
 * users.
 */
final class AdminBar {

	/**
	 * Minutes before a scheduled freeze the pill starts to show.
	 */
	private const UPCOMING_WINDOW = 60 * MINUTE_IN_SECONDS;

	private FreezeService $service;

	private Guard $guard;

	/** @var array<string, mixed>|false|null */
	private $state = null;

	public function __construct( FreezeService $service, Guard $guard ) {
		$this->service = $service;
		$this->guard   = $guard;
	}

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'add_style' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'add_style' ), 20 );
	}

	public function add_style(): void {
		if ( ! is_admin_bar_showing() || false === $this->state() ) {
			return;
		}

		wp_add_inline_style(
			'admin-bar',
			'#wpadminbar #wp-admin-bar-stillgate>.ab-item{display:flex;align-items:center;gap:8px}' .
			'#wpadminbar .sg-ab-dot{flex:none;width:9px;height:9px;border-radius:50%;background:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.35)}' .
			'#wpadminbar .sg-ab--upcoming .sg-ab-dot{background:#38bdf8;box-shadow:0 0 0 3px rgba(56,189,248,.35)}' .
			'@media (prefers-reduced-motion:no-preference){#wpadminbar .sg-ab--active .sg-ab-dot{animation:sg-ab-pulse 2.2s ease-in-out infinite}}' .
			'@keyframes sg-ab-pulse{0%,100%{box-shadow:0 0 0 2px rgba(245,158,11,.35)}50%{box-shadow:0 0 0 6px rgba(245,158,11,.1)}}'
		);
	}

	/**
	 * @param \WP_Admin_Bar $admin_bar The admin bar being built.
	 */
	public function add_node( $admin_bar ): void {
		$state = $this->state();

		if ( false === $state || ! $admin_bar instanceof \WP_Admin_Bar ) {
			return;
		}

		$settings_url = admin_url( 'tools.php?page=stillgate' );
		$active       = 'active' === $state['kind'];

		$admin_bar->add_node(
			array(
				'id'    => 'stillgate',
				'title' => '<span class="sg-ab-dot" aria-hidden="true"></span><span class="ab-label">' . esc_html( $state['label'] ) . '</span>',
				'href'  => $state['control'] ? $settings_url : false,
				'meta'  => array(
					'class' => $active ? 'sg-ab--active' : 'sg-ab--upcoming',
					'title' => $active
						? __( 'Stillgate: content changes are locked.', 'stillgate' )
						: __( 'Stillgate: a freeze is about to start.', 'stillgate' ),
				),
			)
		);

		if ( ! $state['control'] ) {
			return;
		}

		if ( $active && $state['extendable'] ) {
			$admin_bar->add_node(
				array(
					'parent' => 'stillgate',
					'id'     => 'stillgate-extend',
					'title'  => esc_html(
						sprintf(
							/* translators: %d: number of minutes. */
							__( 'Extend by %d minutes', 'stillgate' ),
							QuickActions::EXTEND_MINUTES
						)
					),
					'href'   => QuickActions::url( 'extend' ),
				)
			);
		}

		if ( $active ) {
			$admin_bar->add_node(
				array(
					'parent' => 'stillgate',
					'id'     => 'stillgate-end',
					'title'  => esc_html__( 'End freeze now', 'stillgate' ),
					'href'   => QuickActions::url( 'end' ),
				)
			);
		}

		$admin_bar->add_node(
			array(
				'parent' => 'stillgate',
				'id'     => 'stillgate-open',
				'title'  => esc_html__( 'Stillgate settings', 'stillgate' ),
				'href'   => $settings_url,
			)
		);
	}

	/**
	 * What the pill shows for the current user right now, or false for
	 * nothing. Worked out once per request.
	 *
	 * @return array<string, mixed>|false
	 */
	private function state() {
		if ( null !== $this->state ) {
			return $this->state;
		}

		$this->state = false;

		if ( ! is_user_logged_in() ) {
			return $this->state;
		}

		$can_bypass = current_user_can( Installer::CAPABILITY );
		$control    = $can_bypass && current_user_can( 'manage_options' );

		if ( ! $can_bypass && ! $this->guard->could_be_affected() ) {
			return $this->state;
		}

		$now    = time();
		$config = $this->service->config();

		if ( $this->service->is_active() ) {
			$ends_at = $this->service->ends_at();

			$this->state = array(
				'kind'       => 'active',
				'control'    => $control,
				'extendable' => ! $config->enabled && 'active' === $config->schedule_status( $now ),
				'label'      => null !== $ends_at
					? sprintf(
						/* translators: %s: time the freeze ends, such as 2:30 pm. */
						__( 'Freeze active · ends %s', 'stillgate' ),
						wp_date( get_option( 'time_format', 'g:i a' ), $ends_at )
					)
					: __( 'Freeze active', 'stillgate' ),
			);

			return $this->state;
		}

		if ( ! $config->enabled && null !== $config->schedule_start && 'upcoming' === $config->schedule_status( $now ) && ( $config->schedule_start - $now ) <= self::UPCOMING_WINDOW ) {
			$this->state = array(
				'kind'       => 'upcoming',
				'control'    => $control,
				'extendable' => false,
				'label'      => sprintf(
					/* translators: %s: time the freeze starts, such as 10:00 pm. */
					__( 'Freeze starts %s', 'stillgate' ),
					wp_date( get_option( 'time_format', 'g:i a' ), $config->schedule_start )
				),
			);
		}

		return $this->state;
	}
}
