<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Enforcement\CapabilityFilter;
use Digibold\DeploymentGuard\Enforcement\DataLayerGuard;
use Digibold\DeploymentGuard\Installer;
use Digibold\DeploymentGuard\Tests\Support\CliExit;

/**
 * Proves the mandatory correction from the approved plan: WP-CLI is not
 * globally exempt. Arbitrary commands that write protected content
 * (`wp post update`, `wp post delete`, `wp term create/update/delete`,
 * `wp media import`) are blocked the same as any other actor, by
 * exercising the exact core functions those commands call (verified
 * against the real wp-cli/entity-command and wp-cli/media-command
 * sources), while Deployment Guard's own commands keep working through
 * Suspension (see CliCommandTest).
 */
final class CliEnforcementTest extends TestCase {

	/**
	 * @return array{0: \Digibold\DeploymentGuard\FreezeService, 1: \Digibold\DeploymentGuard\Guard}
	 */
	private function install_cli( bool $active = true ): array {
		$service = $this->make_service( 0 );
		$guard   = $this->make_guard( $service, true );
		( new CapabilityFilter( $guard ) )->register();
		( new DataLayerGuard( $guard ) )->register();

		if ( $active ) {
			$service->enable();
		}

		\WP_CLI::reset_log();

		return array( $service, $guard );
	}

	private function expect_cli_exit( \Closure $trigger ): void {
		try {
			$trigger();
		} catch ( CliExit $exit ) {
			$this->assertSame( 1, $exit->getCode() );
			$this->assertStringContainsStringIgnoringCase( 'deployment guard', $exit->getMessage() );

			return;
		}

		$this->fail( 'Expected a CliExit (WP_CLI::error()) but the call completed normally.' );
	}

	public function test_post_update_is_blocked(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Original' ) );
		$this->install_cli();

		$this->expect_cli_exit(
			static function () use ( $post_id ) {
				wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Changed' ), true );
			}
		);

		$this->assertSame( 'Original', get_post( $post_id )->post_title );
	}

	public function test_post_delete_trash_is_blocked(): void {
		$post_id = self::factory()->post->create();
		$this->install_cli();

		$this->expect_cli_exit(
			static function () use ( $post_id ) {
				wp_delete_post( $post_id );
			}
		);

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	public function test_post_delete_force_is_blocked(): void {
		$post_id = self::factory()->post->create();
		$this->install_cli();

		$this->expect_cli_exit(
			static function () use ( $post_id ) {
				wp_delete_post( $post_id, true );
			}
		);

		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ) );
	}

	public function test_post_create_is_blocked(): void {
		$this->install_cli();
		$count_before = (int) wp_count_posts()->publish;

		$this->expect_cli_exit(
			static function () {
				wp_insert_post(
					array(
						'post_title'  => 'New',
						'post_status' => 'publish',
						'post_type'   => 'post',
					),
					true
				);
			}
		);

		$this->assertSame( $count_before, (int) wp_count_posts()->publish );
	}

	public function test_term_create_is_blocked(): void {
		$this->install_cli();

		$this->expect_cli_exit(
			static function () {
				$result = wp_insert_term( 'New Term', 'category' );

				if ( is_wp_error( $result ) ) {
					// Term_Command::create() reports this exact way.
					\WP_CLI::error( $result->get_error_message() );
				}
			}
		);
	}

	public function test_term_update_is_blocked(): void {
		$term = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'category',
				'name'     => 'Original',
			)
		);
		$this->install_cli();

		$this->expect_cli_exit(
			static function () use ( $term ) {
				wp_update_term( $term->term_id, 'category', array( 'name' => 'Changed' ) );
			}
		);

		$this->assertSame( 'Original', get_term( $term->term_id, 'category' )->name );
	}

	public function test_term_delete_is_blocked(): void {
		$term = self::factory()->term->create_and_get( array( 'taxonomy' => 'category' ) );
		$this->install_cli();

		$this->expect_cli_exit(
			static function () use ( $term ) {
				wp_delete_term( $term->term_id, 'category' );
			}
		);

		$this->assertInstanceOf( \WP_Term::class, get_term( $term->term_id, 'category' ) );
	}

	public function test_media_sideload_is_blocked(): void {
		$fixture = dirname( __DIR__ ) . '/Support/fixtures/pixel.png';
		$this->install_cli();
		$count_before = (int) wp_count_posts( 'attachment' )->inherit;

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$this->expect_cli_exit(
			static function () use ( $fixture ) {
				$file_array = array(
					'name'     => 'pixel.png',
					'tmp_name' => $fixture,
				);

				$result = media_handle_sideload( $file_array, 0 );

				if ( is_wp_error( $result ) ) {
					\WP_CLI::error( $result->get_error_message() );
				}
			}
		);

		$this->assertSame( $count_before, (int) wp_count_posts( 'attachment' )->inherit );
	}

	public function test_media_import_skip_copy_is_blocked(): void {
		$fixture = dirname( __DIR__ ) . '/Support/fixtures/pixel.png';
		$this->install_cli();

		$this->expect_cli_exit(
			static function () use ( $fixture ) {
				$result = wp_insert_attachment(
					array(
						'post_mime_type' => 'image/png',
						'post_title'     => 'pixel',
						'post_status'    => 'inherit',
					),
					$fixture,
					0,
					true
				);

				if ( is_wp_error( $result ) ) {
					\WP_CLI::error( $result->get_error_message() );
				}
			}
		);
	}

	public function test_updating_existing_global_styles_is_blocked(): void {
		$post_id  = self::factory()->post->create(
			array(
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'publish',
				'post_content' => '{"version": 3, "isGlobalStylesUserThemeJSON": true}',
			)
		);
		$original = get_post( $post_id )->post_content;
		$this->install_cli();

		$this->expect_cli_exit(
			static function () use ( $post_id ) {
				wp_update_post( array( 'ID' => $post_id, 'post_content' => '{"version": 3, "isGlobalStylesUserThemeJSON": true, "settings": {}}' ), true );
			}
		);

		$this->assertSame( $original, get_post( $post_id )->post_content );
	}

	public function test_updating_existing_navigation_is_blocked(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'Original navigation',
				'post_content' => '<!-- wp:page-list /-->',
			)
		);
		$this->install_cli();

		$this->expect_cli_exit(
			static function () use ( $post_id ) {
				wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Changed navigation' ), true );
			}
		);

		$this->assertSame( 'Original navigation', get_post( $post_id )->post_title );
	}

	public function test_automatic_navigation_creation_is_still_allowed(): void {
		$this->install_cli();

		// What WP_Navigation_Fallback does, with no user action, the first
		// time a Navigation block renders without a menu to use.
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'Navigation',
				'post_content' => '<!-- wp:page-list /-->',
			),
			true
		);

		$this->assertIsInt( $post_id );
	}

	public function test_deleting_a_revision_of_a_protected_post_is_blocked(): void {
		$post_id = self::factory()->post->create( array( 'post_content' => 'v1' ) );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => 'v2' ) );
		$revisions = wp_get_post_revisions( $post_id );
		$revision  = array_shift( $revisions );
		$this->assertInstanceOf( \WP_Post::class, $revision );

		$this->install_cli();

		// `wp post delete <revision ID> --force`.
		$this->expect_cli_exit(
			static function () use ( $revision ) {
				wp_delete_post( $revision->ID, true );
			}
		);

		$this->assertInstanceOf( \WP_Post::class, get_post( $revision->ID ) );
	}

	public function test_user_with_bypass_via_cli_user_flag_is_allowed(): void {
		$post_id  = self::factory()->post->create( array( 'post_title' => 'Original' ) );
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->install_cli();
		$this->assertTrue( user_can( $admin_id, Installer::CAPABILITY ) );

		wp_set_current_user( $admin_id ); // The --user=<account> equivalent.

		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Changed by admin' ), true );

		$this->assertSame( 'Changed by admin', get_post( $post_id )->post_title );
	}

	public function test_user_without_bypass_via_cli_user_flag_is_still_blocked(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Original' ) );
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->install_cli();
		wp_set_current_user( $editor );

		$this->expect_cli_exit(
			static function () use ( $post_id ) {
				wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Changed' ), true );
			}
		);

		$this->assertSame( 'Original', get_post( $post_id )->post_title );
	}

	public function test_unprotected_post_type_is_allowed(): void {
		register_post_type( 'dg_open_cpt', array( 'public' => false, 'show_ui' => true, 'label' => 'Open', 'capability_type' => 'post', 'map_meta_cap' => true ) );
		$post_id = self::factory()->post->create( array( 'post_type' => 'dg_open_cpt', 'post_title' => 'Original' ) );

		$this->install_cli();

		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Changed' ), true );

		$this->assertSame( 'Changed', get_post( $post_id )->post_title );

		unregister_post_type( 'dg_open_cpt' );
	}

	public function test_inactive_freeze_allows_everything(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Original' ) );
		$this->install_cli( false );

		wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Changed' ), true );

		$this->assertSame( 'Changed', get_post( $post_id )->post_title );
	}

	public function test_options_transients_and_user_meta_are_unaffected(): void {
		$this->install_cli();

		update_option( 'dg_unrelated_option', 'value' );
		set_transient( 'dg_unrelated_transient', 'value', 60 );

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'dg_unrelated_meta', 'value' );

		$this->assertSame( 'value', get_option( 'dg_unrelated_option' ) );
		$this->assertSame( 'value', get_transient( 'dg_unrelated_transient' ) );
		$this->assertSame( 'value', get_user_meta( $user_id, 'dg_unrelated_meta', true ) );
	}
}
