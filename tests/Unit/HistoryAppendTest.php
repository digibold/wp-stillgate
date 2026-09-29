<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Unit;

use Digibold\DeploymentGuard\History;
use PHPUnit\Framework\TestCase;

final class HistoryAppendTest extends TestCase {

	private const NOW = 1_800_000_000;

	private function blocked_entry( int $t, int $user = 1, string $vector = 'admin', string $target = 'post.php' ): array {
		return array(
			't'    => $t,
			'type' => 'blocked',
			'user' => $user,
			'ctx'  => array(
				'vector' => $vector,
				'target' => $target,
			),
		);
	}

	private function state_entry( int $t, string $type = 'enabled', int $user = 1 ): array {
		return array(
			't'    => $t,
			'type' => $type,
			'user' => $user,
			'ctx'  => array(),
		);
	}

	public function test_new_entry_is_prepended(): void {
		$entries = array( $this->state_entry( self::NOW - 10, 'enabled' ) );
		$entry   = $this->state_entry( self::NOW, 'disabled' );

		$result = History::append( $entries, $entry, self::NOW );

		$this->assertSame( 'disabled', $result[0]['type'] );
		$this->assertSame( 'enabled', $result[1]['type'] );
	}

	public function test_entries_are_trimmed_at_max_entries(): void {
		$entries = array();

		for ( $i = 0; $i < History::MAX_ENTRIES; $i++ ) {
			$entries[] = $this->state_entry( self::NOW - $i, 'enabled', 1 + ( $i % 5 ) );
		}

		$result = History::append( $entries, $this->state_entry( self::NOW + 1, 'disabled' ), self::NOW + 1 );

		$this->assertCount( History::MAX_ENTRIES, $result );
		$this->assertSame( 'disabled', $result[0]['type'] );
	}

	public function test_blocked_entries_are_capped_evicting_oldest_first(): void {
		$entries = array();

		// Spread MAX_BLOCKED entries out far enough apart that none of
		// them collide with the dedup or throttle windows, but each
		// still fits under MAX_ENTRIES total.
		$far_apart = History::THROTTLE_WINDOW + 100;

		for ( $i = 0; $i < History::MAX_BLOCKED; $i++ ) {
			$entries[] = $this->blocked_entry( self::NOW - ( $i * $far_apart ), 1, 'admin', 'target-' . $i );
		}

		$new_entry = $this->blocked_entry( self::NOW + 1, 1, 'admin', 'newest' );
		$result    = History::append( $entries, $new_entry, self::NOW + 1 );

		$this->assertNotNull( $result );
		$this->assertCount( History::MAX_BLOCKED, $result );
		$this->assertSame( 'newest', $result[0]['ctx']['target'] );

		// The single oldest blocked entry should have been evicted.
		$targets = array_column( array_column( $result, 'ctx' ), 'target' );
		$this->assertNotContains( 'target-' . ( History::MAX_BLOCKED - 1 ), $targets );
	}

	public function test_duplicate_blocked_entry_within_dedup_window_is_dropped(): void {
		$entries = array( $this->blocked_entry( self::NOW - 10, 1, 'admin', 'post.php' ) );
		$entry   = $this->blocked_entry( self::NOW, 1, 'admin', 'post.php' );

		$result = History::append( $entries, $entry, self::NOW );

		$this->assertNull( $result );
	}

	public function test_duplicate_blocked_entry_outside_dedup_window_is_kept(): void {
		$entries = array( $this->blocked_entry( self::NOW - History::DEDUP_WINDOW - 1, 1, 'admin', 'post.php' ) );
		$entry   = $this->blocked_entry( self::NOW, 1, 'admin', 'post.php' );

		$result = History::append( $entries, $entry, self::NOW );

		$this->assertNotNull( $result );
		$this->assertCount( 2, $result );
	}

	public function test_different_vector_or_target_is_not_deduplicated(): void {
		$entries = array( $this->blocked_entry( self::NOW - 10, 1, 'admin', 'post.php' ) );

		$different_vector = $this->blocked_entry( self::NOW, 1, 'rest', 'post.php' );
		$result            = History::append( $entries, $different_vector, self::NOW );
		$this->assertNotNull( $result );

		$different_target = $this->blocked_entry( self::NOW, 1, 'admin', 'edit.php' );
		$result            = History::append( $entries, $different_target, self::NOW );
		$this->assertNotNull( $result );
	}

	public function test_global_throttle_drops_entries_beyond_limit(): void {
		$entries = array();

		for ( $i = 0; $i < History::THROTTLE_GLOBAL; $i++ ) {
			// Distinct users and targets so nothing collides with dedup
			// or the per-user throttle.
			$entries[] = $this->blocked_entry( self::NOW - $i, 100 + $i, 'admin', 'target-' . $i );
		}

		$entry  = $this->blocked_entry( self::NOW + 1, 999, 'admin', 'target-new' );
		$result = History::append( $entries, $entry, self::NOW + 1 );

		$this->assertNull( $result );
	}

	public function test_per_user_throttle_drops_entries_beyond_limit_even_under_global_cap(): void {
		$entries = array();

		for ( $i = 0; $i < History::THROTTLE_PER_USER; $i++ ) {
			$entries[] = $this->blocked_entry( self::NOW - ( $i * 5 ), 42, 'admin', 'target-' . $i );
		}

		// Same user, a fresh target so dedup does not intervene.
		$entry  = $this->blocked_entry( self::NOW + 1, 42, 'admin', 'target-new' );
		$result = History::append( $entries, $entry, self::NOW + 1 );

		$this->assertNull( $result );
		$this->assertLessThan( History::THROTTLE_GLOBAL, count( $entries ) );
	}

	public function test_throttle_does_not_apply_outside_the_window(): void {
		$entries = array();

		for ( $i = 0; $i < History::THROTTLE_GLOBAL; $i++ ) {
			$entries[] = $this->blocked_entry(
				self::NOW - History::THROTTLE_WINDOW - 10 - $i,
				100 + $i,
				'admin',
				'target-' . $i
			);
		}

		$entry  = $this->blocked_entry( self::NOW, 999, 'admin', 'target-new' );
		$result = History::append( $entries, $entry, self::NOW );

		$this->assertNotNull( $result );
	}

	public function test_state_events_are_never_deduplicated_or_throttled(): void {
		$entries = array();

		for ( $i = 0; $i < History::THROTTLE_GLOBAL + 5; $i++ ) {
			$entries[] = $this->state_entry( self::NOW - $i, 'enabled', 1 );
		}

		$entry  = $this->state_entry( self::NOW + 1, 'enabled', 1 );
		$result = History::append( $entries, $entry, self::NOW + 1 );

		$this->assertNotNull( $result );
		$this->assertSame( 'enabled', $result[0]['type'] );
	}

	public function test_sanitize_context_keeps_only_allowlisted_keys(): void {
		$clean = History::sanitize_context(
			array(
				'vector'    => 'admin',
				'target'    => 'post.php',
				'ip'        => '203.0.113.1',
				'payload'   => array( 'secret' => 'value' ),
				'object_id' => 42,
			)
		);

		$this->assertSame(
			array(
				'vector'    => 'admin',
				'target'    => 'post.php',
				'object_id' => 42,
			),
			$clean
		);
	}

	public function test_sanitize_context_caps_string_length(): void {
		$clean = History::sanitize_context( array( 'target' => str_repeat( 'a', 500 ) ) );

		$this->assertSame( History::MAX_CONTEXT_VALUE_LENGTH, strlen( $clean['target'] ) );
	}
}
