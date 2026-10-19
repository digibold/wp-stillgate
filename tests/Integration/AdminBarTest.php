<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Integration;

use Digibold\DeploymentGuard\Admin\AdminBar;
use Digibold\DeploymentGuard\FreezeService;

final class AdminBarTest extends TestCase {

	private FreezeService $service;

	public function set_up(): void {
		parent::set_up();

		$this->service = $this->make_service( 0 );
	}

	public function tear_down(): void {
		unset( $GLOBALS['show_admin_bar'] );

		parent::tear_down();
	}

	private function build(): \WP_Admin_Bar {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

		$bar = new \WP_Admin_Bar();
		( new AdminBar( $this->service, $this->make_guard( $this->service ) ) )->add_node( $bar );

		return $bar;
	}

	private function child_ids( \WP_Admin_Bar $bar ): array {
		$ids = array();

		foreach ( $bar->get_nodes() ?? array() as $node ) {
			if ( 'stillgate' === $node->parent ) {
				$ids[] = $node->id;
			}
		}

		return $ids;
	}

	public function test_nothing_is_shown_when_no_freeze_is_running_or_near(): void {
		$this->login_as( 'administrator' );

		$this->assertNull( $this->build()->get_node( 'stillgate' ) );
	}

	public function test_a_running_manual_freeze_shows_the_pill_with_end_and_settings_for_a_controller(): void {
		$this->login_as( 'administrator' );
		$this->service->enable();

		$bar  = $this->build();
		$node = $bar->get_node( 'stillgate' );

		$this->assertNotNull( $node );
		$this->assertStringContainsString( 'Freeze active', $node->title );
		$this->assertStringNotContainsString( 'ends', $node->title );
		$this->assertContains( 'sg-ab--active', array( $node->meta['class'] ) );
		$this->assertStringContainsString( 'tools.php?page=stillgate', (string) $node->href );
		$this->assertSame( array( 'stillgate-end', 'stillgate-open' ), $this->child_ids( $bar ), 'a manual freeze has no end to extend' );
		$this->assertStringContainsString( 'op=end', (string) $bar->get_node( 'stillgate-end' )->href );
	}

	public function test_a_timed_freeze_shows_when_it_ends_and_offers_to_extend(): void {
		$this->login_as( 'administrator' );
		$this->service->freeze_for( 1800 );

		$bar = $this->build();

		$this->assertStringContainsString( 'ends', $bar->get_node( 'stillgate' )->title );
		$this->assertSame( array( 'stillgate-extend', 'stillgate-end', 'stillgate-open' ), $this->child_ids( $bar ) );
		$this->assertStringContainsString( 'op=extend', (string) $bar->get_node( 'stillgate-extend' )->href );
		$this->assertStringContainsString( '15', $bar->get_node( 'stillgate-extend' )->title );
	}

	public function test_an_editor_sees_the_pill_but_no_actions_and_no_link(): void {
		$this->service->freeze_for( 1800 );
		$this->login_as( 'editor' );

		$bar  = $this->build();
		$node = $bar->get_node( 'stillgate' );

		$this->assertNotNull( $node );
		$this->assertStringContainsString( 'ends', $node->title );
		$this->assertFalse( $node->href );
		$this->assertSame( array(), $this->child_ids( $bar ) );
	}

	public function test_a_bypass_holder_who_cannot_manage_options_sees_no_actions(): void {
		$this->service->freeze_for( 1800 );
		remove_role( 'dg_release_editor2' );
		add_role( 'dg_release_editor2', 'Release Editor 2', array( 'read' => true, 'edit_posts' => true, 'deployment_guard_bypass' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'dg_release_editor2' ) ) );

		$bar = $this->build();

		$this->assertNotNull( $bar->get_node( 'stillgate' ) );
		$this->assertSame( array(), $this->child_ids( $bar ) );

		remove_role( 'dg_release_editor2' );
	}

	public function test_people_a_freeze_cannot_affect_never_see_it(): void {
		$this->service->freeze_for( 1800 );

		$this->login_as( 'subscriber' );
		$this->assertNull( $this->build()->get_node( 'stillgate' ) );

		wp_set_current_user( 0 );
		$this->assertNull( $this->build()->get_node( 'stillgate' ) );
	}

	public function test_a_freeze_starting_within_the_hour_is_announced(): void {
		$this->login_as( 'administrator' );
		$start = time() + 20 * MINUTE_IN_SECONDS;
		$this->service->schedule( $start, $start + HOUR_IN_SECONDS );

		$bar  = $this->build();
		$node = $bar->get_node( 'stillgate' );

		$this->assertNotNull( $node );
		$this->assertStringContainsString( 'Freeze starts', $node->title );
		$this->assertSame( 'sg-ab--upcoming', $node->meta['class'] );
		$this->assertSame( array( 'stillgate-open' ), $this->child_ids( $bar ), 'nothing to end or extend yet' );
	}

	public function test_a_freeze_starting_later_is_not_announced_yet(): void {
		$this->login_as( 'administrator' );
		$start = time() + 3 * HOUR_IN_SECONDS;
		$this->service->schedule( $start, $start + HOUR_IN_SECONDS );

		$this->assertNull( $this->build()->get_node( 'stillgate' ) );
	}

	public function test_the_pill_disappears_when_the_freeze_ends(): void {
		$this->login_as( 'administrator' );
		$this->service->enable();
		$this->assertNotNull( $this->build()->get_node( 'stillgate' ) );

		$this->service->disable();

		$this->assertNull( $this->build()->get_node( 'stillgate' ) );
	}

	public function test_the_title_markup_is_escaped_and_free_of_user_content(): void {
		$this->login_as( 'administrator' );
		$this->service->set_message( '<img src=x onerror=alert(1)>' );
		$this->service->enable();

		$node = $this->build()->get_node( 'stillgate' );

		$this->assertStringNotContainsString( 'onerror', $node->title );
		$this->assertStringNotContainsString( '<img', $node->title );
	}

	public function test_the_style_is_only_added_when_there_is_something_to_show(): void {
		$GLOBALS['show_admin_bar'] = true;
		$this->login_as( 'administrator' );
		wp_styles()->registered['admin-bar']->extra = array();
		$adminbar = new AdminBar( $this->service, $this->make_guard( $this->service ) );

		$adminbar->add_style();
		$this->assertEmpty( wp_styles()->get_data( 'admin-bar', 'after' ) );

		$this->service->enable();
		$adminbar = new AdminBar( $this->service, $this->make_guard( $this->service ) );
		$adminbar->add_style();

		$this->assertStringContainsString( 'sg-ab-dot', implode( '', (array) wp_styles()->get_data( 'admin-bar', 'after' ) ) );
	}
}
