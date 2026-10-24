<?php

use PHPUnit\Framework\TestCase;

final class ScormCmiMappingTest extends TestCase {
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/scorm/class-scorm-cmi.php';
	}

	public function test_scorm_12_lesson_status_maps_to_completion_and_success(): void {
		$derived = CLMS_SCORM_CMI::derive_state_from_values( '1.2', array( 'cmi.core.lesson_status' => 'passed' ) );
		$this->assertSame( 'completed', $derived['completion_status'] );
		$this->assertSame( 'passed', $derived['success_status'] );
		$this->assertTrue( $derived['is_complete'] );

		$derived = CLMS_SCORM_CMI::derive_state_from_values( '1.2', array( 'cmi.core.lesson_status' => 'incomplete' ) );
		$this->assertSame( 'incomplete', $derived['completion_status'] );
		$this->assertSame( 'unknown', $derived['success_status'] );
		$this->assertFalse( $derived['is_complete'] );
	}

	public function test_scorm_2004_completion_and_success_are_preserved(): void {
		$derived = CLMS_SCORM_CMI::derive_state_from_values( '2004', array( 'cmi.completion_status' => 'completed', 'cmi.success_status' => 'passed' ) );
		$this->assertSame( 'completed', $derived['completion_status'] );
		$this->assertSame( 'passed', $derived['success_status'] );
		$this->assertTrue( $derived['is_complete'] );
	}
}

