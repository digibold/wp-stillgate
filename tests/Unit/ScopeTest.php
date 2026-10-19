<?php
/**
 * @package Digibold\DeploymentGuard
 */

namespace Digibold\DeploymentGuard\Tests\Unit;

use Digibold\DeploymentGuard\Scope;
use PHPUnit\Framework\TestCase;

final class ScopeTest extends TestCase {

	public function test_defaults_to_editorial_only(): void {
		$scope = new Scope();

		$this->assertFalse( $scope->comments );
		$this->assertFalse( $scope->forms );
		$this->assertTrue( $scope->is_empty() );
		$this->assertSame( array(), $scope->enabled_kinds() );
	}

	/**
	 * @dataProvider provide_unusable_values
	 */
	public function test_unusable_stored_values_fall_back_to_the_default( mixed $raw ): void {
		$this->assertTrue( Scope::from_array( $raw )->is_empty() );
	}

	public static function provide_unusable_values(): array {
		return array(
			'false'          => array( false ),
			'empty string'   => array( '' ),
			'a string'       => array( 'comments' ),
			'an integer'     => array( 1 ),
			'null'           => array( null ),
			'empty array'    => array( array() ),
			'unknown keys'   => array( array( 'surprise' => true ) ),
		);
	}

	public function test_reads_and_round_trips_both_flags(): void {
		$scope = Scope::from_array( array( 'comments' => true, 'forms' => '1', 'ignored' => true ) );

		$this->assertTrue( $scope->comments );
		$this->assertTrue( $scope->forms );
		$this->assertSame( array( 'comments' => true, 'forms' => true ), $scope->to_array() );
		$this->assertSame( array( 'comments', 'forms' ), $scope->enabled_kinds() );
		$this->assertEquals( $scope, Scope::from_array( $scope->to_array() ) );
	}

	public function test_includes_answers_per_kind_and_rejects_unknown_kinds(): void {
		$scope = new Scope( true, false );

		$this->assertTrue( $scope->includes( 'comments' ) );
		$this->assertFalse( $scope->includes( 'forms' ) );
		$this->assertFalse( $scope->includes( 'users' ) );
		$this->assertFalse( $scope->includes( '' ) );
	}
}
