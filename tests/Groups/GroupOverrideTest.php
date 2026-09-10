<?php
/**
 * Groups — override grade.
 *
 * @package ATORA_LMS\Tests\Groups
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Groups;

use PHPUnit\Framework\TestCase;

final class GroupOverrideTest extends TestCase {

	/** @test */
	public function it_clamps_override_grade_to_0_100(): void {
		require_once __DIR__ . '/../../modules/groups/class-group-service.php';

		$service = new \ATORA\Groups\Group_Service();
		$result  = $service->set_override( 10, 20, 30, 120, 'extra', 1 );

		$this->assertIsArray( $result );
		$this->assertSame( 100, $result['override_grade'] );
	}

	/** @test */
	public function it_accepts_null_override_to_clear(): void {
		require_once __DIR__ . '/../../modules/groups/class-group-service.php';

		$service = new \ATORA\Groups\Group_Service();
		$result  = $service->set_override( 10, 20, 30, '', '', 1 );

		$this->assertIsArray( $result );
		$this->assertNull( $result['override_grade'] );
	}
}

