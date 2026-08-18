<?php
/**
 * Messaging_Router — tipos académicos, categorías y guarda de
 * "nunca al estudiante" (PT-1, sprint 6.4.0).
 *
 * @package ATORA_LMS\Tests\Messaging
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Messaging;

use PHPUnit\Framework\TestCase;

class MessagingRouterTypesTest extends TestCase {

	/** @test */
	public function test_academic_types_have_a_category(): void {
		$expected = array(
			'assignment_graded'         => 'academico',
			'submission_received'       => 'academico',
			'at_risk_flagged'           => 'academico',
			'improvement_plan_assigned' => 'academico',
			'assignment_due_soon'       => 'recordatorios',
			'student_inactive'          => 'recordatorios',
			'lesson_published'          => 'recordatorios',
			'section_announcement'      => 'institucional',
		);

		foreach ( $expected as $type => $category ) {
			$this->assertSame( $category, \ATORA\Messaging\Messaging_Router::category_for_type( $type ), "tipo '{$type}'" );
		}
	}

	/** @test */
	public function test_transactional_types_have_no_category(): void {
		foreach ( array( '2fa_code', 'purchase' ) as $type ) {
			$this->assertNull( \ATORA\Messaging\Messaging_Router::category_for_type( $type ), "tipo '{$type}' debe ser transaccional" );
		}
	}

	/** @test */
	public function test_unknown_type_has_no_category(): void {
		$this->assertNull( \ATORA\Messaging\Messaging_Router::category_for_type( 'algo_inventado' ) );
	}

	/** @test */
	public function test_get_type_categories_returns_all_eight(): void {
		$categories = \ATORA\Messaging\Messaging_Router::get_type_categories();
		$this->assertCount( 8, $categories );
	}

	/** @test */
	public function test_at_risk_flagged_is_never_student_facing(): void {
		$this->assertTrue( \ATORA\Messaging\Messaging_Router::is_never_student_facing( 'at_risk_flagged' ) );
	}

	/** @test */
	public function test_other_academic_types_are_student_facing(): void {
		foreach ( array( 'assignment_graded', 'assignment_due_soon', 'student_inactive', 'improvement_plan_assigned' ) as $type ) {
			$this->assertFalse( \ATORA\Messaging\Messaging_Router::is_never_student_facing( $type ), "tipo '{$type}'" );
		}
	}
}
