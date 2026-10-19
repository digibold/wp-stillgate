<?php
/**
 * Composition root.
 *
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Wires together the freeze service, the enforcement layers, the admin
 * screen, and the WP-CLI command. Kept deliberately thin: no enforcement
 * logic lives here, only construction and hook registration.
 */
final class Plugin {

	public const VERSION = '0.2.0';

	private string $file;

	private FreezeService $service;

	public function __construct( string $file ) {
		$this->file = $file;
	}

	public function register(): void {
		// An earlier release (published as "Deployment Guard") that loads
		// after this copy resolves this same class by name and calls
		// register() with its own main file. Only this copy's own main
		// file may register, or every hook would be added twice.
		if ( realpath( $this->file ) !== realpath( dirname( __DIR__ ) . '/stillgate.php' ) ) {
			return;
		}

		( new LegacyCopy() )->register();

		$suspension = new Suspension();
		$clock      = static fn (): int => time();

		$this->service = new FreezeService( $clock, $suspension );

		$is_cli = defined( 'WP_CLI' ) && WP_CLI;
		$guard  = new Guard( $this->service, $suspension, $is_cli );

		register_deactivation_hook( $this->file, array( $this, 'on_deactivation' ) );

		add_action( 'admin_init', array( Installer::class, 'maybe_install' ), 0 );
		add_action( 'admin_init', array( $this, 'register_privacy_policy_content' ) );

		// Enforcement layers that apply in every context (web, CLI, cron).
		( new Enforcement\CapabilityFilter( $guard ) )->register();
		( new Enforcement\DataLayerGuard( $guard ) )->register();
		( new Enforcement\ScheduledPublishGuard( $this->service, $guard ) )->register();

		$receipt = new Receipt( $this->service, $guard, $clock );
		$receipt->register();

		// Web-only gates. is_admin() also covers admin-ajax.php requests,
		// which WordPress itself treats as admin context.
		if ( is_admin() ) {
			( new Enforcement\AdminGate( $guard ) )->register();
			( new Enforcement\AjaxGate( $guard ) )->register();
			( new Admin\SettingsPage( $this->service, $guard, $receipt, plugins_url( 'assets/', $this->file ) ) )->register();
			( new Admin\Notices( $guard ) )->register();
			( new Admin\QuickActions( $this->service ) )->register();
			( new Admin\SiteHealth( $this->service, $receipt ) )->register();
		}

		if ( ! $is_cli ) {
			( new Admin\AdminBar( $this->service, $guard ) )->register();
		}

		// Optional pauses of visitor comments and form submissions; both are
		// off unless the site turned them on, and neither reads the freeze
		// state until a request that looks like a submission arrives.
		( new Enforcement\CommentGate( $guard, $receipt ) )->register();
		( new Enforcement\FormGate( $guard, $receipt ) )->register();

		( new Enforcement\RestGate( $guard ) )->register();
		( new Rest\Controller( $this->service, $receipt ) )->register();
		( new Enforcement\XmlRpcGate( $guard ) )->register();

		if ( $is_cli ) {
			\WP_CLI::add_command(
				'stillgate',
				new Cli\Command( $this->service, $guard, $suspension, $receipt ),
				array( 'shortdesc' => 'Manage the editorial deployment freeze.' )
			);
		}
	}

	/**
	 * Clear the freeze on deactivation, so reactivating the plugin later
	 * never silently restores an old freeze.
	 */
	public function on_deactivation(): void {
		$this->service->reset( 'deactivation' );
	}

	public function register_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p class="privacy-policy-tutorial">' . esc_html__(
			'While Stillgate is active, it keeps a short local log of who enabled or disabled it, when scheduled freezes were changed, and which logged-in users attempted to make changes that were blocked. Each entry stores a user ID, a timestamp, and a short description of what was attempted; it never stores page content, form data, or IP addresses. This log stays in your site\'s own database, is never transmitted anywhere, and is limited to a fixed number of entries. If you choose to pause comments or forms during a freeze, Stillgate counts the submissions it refuses and stores only that number, never the comment, the form data, or who sent it. A short receipt of the latest freeze keeps timestamps, counts and the IDs of posts that changed, never their content.',
			'stillgate'
		) . '</p>';

		wp_add_privacy_policy_content( __( 'Stillgate', 'stillgate' ), $content );
	}
}
