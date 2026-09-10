<?php
/**
 * F4 — programmatic cutover gate.
 *
 * @package ATORA_LMS\Tests\LMS
 */
declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

require_once __DIR__ . '/Support/F4TestSupport.php';

class CutoverReadyGateTest extends WpdbSwapTestCase {

	/**
	 * Encola una secuencia "todo verde": 0 divergencias y las 4 tablas
	 * núcleo con filas.
	 */
	private function queue_all_green( FakeWpdbF4 $fake ): void {
		$fake->get_var_queue = array(
			0,                              // total_divergences()
			'wp_atora_courses', 5,          // core table 1: exists + count
			'wp_atora_lessons', 5,          // core table 2
			'wp_atora_enrollments', 5,      // core table 3
			'wp_atora_program_enrollments', 5, // core table 4
		);
	}

	/** @test */
	public function test_ready_true_when_all_conditions_pass(): void {
		$fake = $this->swap_wpdb();
		$this->queue_all_green( $fake );
		update_option( 'atora_lms_dualwrite', true );
		update_option( 'atora_lms_reconcile_result', array( 'total' => 0 ) );

		$gate = \ATORA\LMS\LMS_Parity::cutover_ready();

		$this->assertTrue( $gate['ready'] );
		$this->assertSame( array(), $gate['reasons'] );
	}

	/** @test */
	public function test_not_ready_when_dualwrite_disabled(): void {
		$fake = $this->swap_wpdb();
		$this->queue_all_green( $fake );
		update_option( 'atora_lms_dualwrite', false );
		update_option( 'atora_lms_reconcile_result', array( 'total' => 0 ) );

		$gate = \ATORA\LMS\LMS_Parity::cutover_ready();

		$this->assertFalse( $gate['ready'] );
		$this->assertNotEmpty( array_filter( $gate['reasons'], fn( $r ) => str_contains( $r, 'dualwrite' ) ) );
	}

	/** @test */
	public function test_not_ready_when_divergences_present(): void {
		$fake = $this->swap_wpdb();
		$fake->get_var_queue = array(
			3,                              // total_divergences() > 0
			'wp_atora_courses', 5,
			'wp_atora_lessons', 5,
			'wp_atora_enrollments', 5,
			'wp_atora_program_enrollments', 5,
		);
		update_option( 'atora_lms_dualwrite', true );
		update_option( 'atora_lms_reconcile_result', array( 'total' => 0 ) );

		$gate = \ATORA\LMS\LMS_Parity::cutover_ready();

		$this->assertFalse( $gate['ready'] );
		$this->assertNotEmpty( array_filter( $gate['reasons'], fn( $r ) => str_contains( $r, 'divergencia' ) ) );
	}

	/** @test */
	public function test_not_ready_when_reconcile_never_ran(): void {
		$fake = $this->swap_wpdb();
		$this->queue_all_green( $fake );
		update_option( 'atora_lms_dualwrite', true );
		// atora_lms_reconcile_result no seteado.

		$gate = \ATORA\LMS\LMS_Parity::cutover_ready();

		$this->assertFalse( $gate['ready'] );
		$this->assertNotEmpty( array_filter( $gate['reasons'], fn( $r ) => str_contains( $r, 'reconciliación' ) ) );
	}

	/** @test */
	public function test_not_ready_when_reconcile_has_pending(): void {
		$fake = $this->swap_wpdb();
		$this->queue_all_green( $fake );
		update_option( 'atora_lms_dualwrite', true );
		update_option( 'atora_lms_reconcile_result', array( 'total' => 4 ) );

		$gate = \ATORA\LMS\LMS_Parity::cutover_ready();

		$this->assertFalse( $gate['ready'] );
		$this->assertNotEmpty( array_filter( $gate['reasons'], fn( $r ) => str_contains( $r, 'pendiente' ) ) );
	}

	/** @test */
	public function test_not_ready_when_core_table_empty(): void {
		$fake = $this->swap_wpdb();
		$fake->get_var_queue = array(
			0,
			'wp_atora_courses', 0,          // tabla existe pero 0 filas
			'wp_atora_lessons', 5,
			'wp_atora_enrollments', 5,
			'wp_atora_program_enrollments', 5,
		);
		update_option( 'atora_lms_dualwrite', true );
		update_option( 'atora_lms_reconcile_result', array( 'total' => 0 ) );

		$gate = \ATORA\LMS\LMS_Parity::cutover_ready();

		$this->assertFalse( $gate['ready'] );
		$this->assertNotEmpty( array_filter( $gate['reasons'], fn( $r ) => str_contains( $r, 'no tiene filas' ) ) );
	}

	/** @test */
	public function test_reasons_accumulate_multiple_failures(): void {
		$fake = $this->swap_wpdb();
		$fake->get_var_queue = array(
			2,                              // divergencias
			'wp_atora_courses', 5,
			'wp_atora_lessons', 5,
			'wp_atora_enrollments', 5,
			'wp_atora_program_enrollments', 5,
		);
		update_option( 'atora_lms_dualwrite', false ); // también falla

		$gate = \ATORA\LMS\LMS_Parity::cutover_ready();

		$this->assertFalse( $gate['ready'] );
		$this->assertGreaterThanOrEqual( 3, count( $gate['reasons'] ), 'dualwrite + divergencias + reconciliación nunca ejecutada' );
	}
}
