<?php
/**
 * Gradebook institucional — reglas de ciclo y escala.
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Gradebook;

use PHPUnit\Framework\TestCase;

final class InstitutionalGradebookPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../includes/gradebook/class-institutional-gradebook-policy.php';
	}

	public function test_period_transitions_are_forward_only(): void {
		$this->assertTrue( \CLMS_Institutional_Gradebook_Policy::can_transition_period( 'draft', 'open' ) );
		$this->assertTrue( \CLMS_Institutional_Gradebook_Policy::can_transition_period( 'open', 'closed' ) );
		$this->assertFalse( \CLMS_Institutional_Gradebook_Policy::can_transition_period( 'closed', 'open' ) );
		$this->assertFalse( \CLMS_Institutional_Gradebook_Policy::can_transition_period( 'archived', 'draft' ) );
	}

	public function test_cycle_requires_review_before_publication(): void {
		$this->assertTrue( \CLMS_Institutional_Gradebook_Policy::can_transition_cycle( 'draft', 'open' ) );
		$this->assertTrue( \CLMS_Institutional_Gradebook_Policy::can_transition_cycle( 'open', 'review' ) );
		$this->assertTrue( \CLMS_Institutional_Gradebook_Policy::can_transition_cycle( 'review', 'published' ) );
		$this->assertFalse( \CLMS_Institutional_Gradebook_Policy::can_transition_cycle( 'open', 'published' ) );
		$this->assertFalse( \CLMS_Institutional_Gradebook_Policy::can_transition_cycle( 'closed', 'open' ) );
	}

	public function test_only_draft_and_open_cycles_accept_direct_grade_edits(): void {
		$this->assertTrue( \CLMS_Institutional_Gradebook_Policy::cycle_accepts_grades( 'draft' ) );
		$this->assertTrue( \CLMS_Institutional_Gradebook_Policy::cycle_accepts_grades( 'open' ) );
		$this->assertFalse( \CLMS_Institutional_Gradebook_Policy::cycle_accepts_grades( 'published' ) );
		$this->assertFalse( \CLMS_Institutional_Gradebook_Policy::cycle_accepts_grades( 'closed' ) );
	}

	public function test_only_published_and_closed_cycles_accept_rectifications(): void {
		$this->assertFalse( \CLMS_Institutional_Gradebook_Policy::cycle_accepts_rectifications( 'review' ) );
		$this->assertTrue( \CLMS_Institutional_Gradebook_Policy::cycle_accepts_rectifications( 'published' ) );
		$this->assertTrue( \CLMS_Institutional_Gradebook_Policy::cycle_accepts_rectifications( 'closed' ) );
	}

	public function test_scale_bands_resolve_grade_and_pass_status(): void {
		$bands = array(
			array( 'code' => 'failed', 'label' => 'Reprobado', 'min' => 0, 'max' => 9.99, 'passed' => false ),
			array( 'code' => 'passed', 'label' => 'Aprobado', 'min' => 10, 'max' => 20, 'passed' => true ),
		);
		$normalized = \CLMS_Institutional_Gradebook_Policy::normalize_scale_bands( $bands, 0, 20 );
		$this->assertIsArray( $normalized );
		$this->assertSame( 'failed', \CLMS_Institutional_Gradebook_Policy::resolve_band( 9.5, $normalized )['code'] );
		$this->assertTrue( \CLMS_Institutional_Gradebook_Policy::resolve_band( 18, $normalized )['passed'] );
		$this->assertSame( array(), \CLMS_Institutional_Gradebook_Policy::resolve_band( 21, $normalized ) );
	}

	public function test_snapshot_hash_is_stable_regardless_of_query_order(): void {
		$first = array(
			array( 'student_id' => 8, 'grade' => 17, 'scale_code' => 'passed', 'status' => 'published', 'revision' => 1 ),
			array( 'student_id' => 2, 'grade' => 12, 'scale_code' => 'passed', 'status' => 'published', 'revision' => 3 ),
		);
		$second = array_reverse( $first );

		$this->assertSame(
			\CLMS_Institutional_Gradebook_Policy::canonical_snapshot_hash( $first ),
			\CLMS_Institutional_Gradebook_Policy::canonical_snapshot_hash( $second )
		);
	}
}
