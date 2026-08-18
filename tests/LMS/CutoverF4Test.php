<?php
/**
 * F4 — Cutover de lectura: Read Router, lectores canónicos de tabla y gate
 * programático (LMS_Parity::cutover_ready()).
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

/**
 * $wpdb de prueba controlable por cola: cada test encola las respuestas que
 * espera para get_var/get_row/get_results/get_col, en el orden en que el
 * código bajo test las invoca. `executed` guarda cada SQL para poder
 * inspeccionar filtros (p.ej. que una query incluya `status IN`).
 */
class FakeWpdbF4 {
	public string $prefix = 'wp_';
	public array $get_var_queue     = array();
	public array $get_row_queue     = array();
	public array $get_results_queue = array();
	public array $get_col_queue     = array();
	public array $executed          = array();

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
			return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
		}, $sql );
	}
	public function get_var( $sql ) {
		$this->executed[] = $sql;
		return array_shift( $this->get_var_queue );
	}
	public function get_row( $sql, $output = OBJECT ) {
		$this->executed[] = $sql;
		return array_shift( $this->get_row_queue );
	}
	public function get_results( $sql, $output = OBJECT ) {
		$this->executed[] = $sql;
		$next = array_shift( $this->get_results_queue );
		return null === $next ? array() : $next;
	}
	public function get_col( $sql ) {
		$this->executed[] = $sql;
		$next = array_shift( $this->get_col_queue );
		return null === $next ? array() : $next;
	}
	public function insert( ...$a ) { return 1; }
	public function update( ...$a ) { return 1; }
	public function delete( ...$a ) { return 1; }
	public function query( $sql ) { $this->executed[] = $sql; return 1; }
	public function esc_like( string $s ): string { return $s; }
	public function get_charset_collate(): string { return ''; }
}

abstract class WpdbSwapTestCase extends TestCase {
	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->original_wpdb = $wpdb;
		atora_test_reset_options();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		atora_test_reset_options();
		parent::tearDown();
	}

	protected function swap_wpdb(): FakeWpdbF4 {
		global $wpdb;
		$fake  = new FakeWpdbF4();
		$wpdb  = $fake;
		return $fake;
	}
}

// ── LMS_Read_Router ──────────────────────────────────────────────────────────

class ReadRouterTest extends WpdbSwapTestCase {

	/** @test */
	public function test_default_source_is_legacy(): void {
		$this->assertSame( 'legacy', \ATORA\LMS\LMS_Read_Router::source() );
	}

	/** @test */
	public function test_default_is_not_tables(): void {
		$this->assertFalse( \ATORA\LMS\LMS_Read_Router::is_tables() );
	}

	/** @test */
	public function test_set_source_tables_switches_is_tables(): void {
		\ATORA\LMS\LMS_Read_Router::set_source( 'tables' );
		$this->assertSame( 'tables', \ATORA\LMS\LMS_Read_Router::source() );
		$this->assertTrue( \ATORA\LMS\LMS_Read_Router::is_tables() );
	}

	/** @test */
	public function test_set_source_invalid_value_clamps_to_legacy(): void {
		\ATORA\LMS\LMS_Read_Router::set_source( 'bogus' );
		$this->assertSame( 'legacy', \ATORA\LMS\LMS_Read_Router::source() );
		$this->assertFalse( \ATORA\LMS\LMS_Read_Router::is_tables() );
	}

	/** @test */
	public function test_set_source_back_to_legacy(): void {
		\ATORA\LMS\LMS_Read_Router::set_source( 'tables' );
		\ATORA\LMS\LMS_Read_Router::set_source( 'legacy' );
		$this->assertSame( 'legacy', \ATORA\LMS\LMS_Read_Router::source() );
	}
}

// ── LMS_Enrollment_Service — lectores de tabla (F3.1) ────────────────────────

class EnrollmentServiceReadersTest extends WpdbSwapTestCase {

	/** @test */
	public function test_get_enrolled_wp_course_ids_filters_expired_and_dedupes(): void {
		$fake = $this->swap_wpdb();
		$fake->get_results_queue[] = array(
			array( 'wp_course_id' => '5', 'expires_at' => '' ),
			array( 'wp_course_id' => '5', 'expires_at' => '' ),
			array( 'wp_course_id' => '9', 'expires_at' => '2000-01-01 00:00:00' ), // expirado
		);

		$ids = \ATORA\LMS\LMS_Enrollment_Service::get_enrolled_wp_course_ids( 1 );

		$this->assertSame( array( 5 ), $ids );
		$this->assertIsInt( $ids[0], 'Los IDs deben ser int, no string' );
	}

	/** @test */
	public function test_get_enrolled_wp_course_ids_empty_returns_empty_array(): void {
		$this->swap_wpdb();
		$this->assertSame( array(), \ATORA\LMS\LMS_Enrollment_Service::get_enrolled_wp_course_ids( 1 ) );
	}

	/** @test */
	public function test_is_enrolled_by_wp_id_true_when_active_row(): void {
		$fake = $this->swap_wpdb();
		$fake->get_row_queue[] = array( 'expires_at' => '' );
		$this->assertTrue( \ATORA\LMS\LMS_Enrollment_Service::is_enrolled_by_wp_id( 1, 5 ) );
	}

	/** @test */
	public function test_is_enrolled_by_wp_id_false_when_no_row(): void {
		$this->swap_wpdb();
		$this->assertFalse( \ATORA\LMS\LMS_Enrollment_Service::is_enrolled_by_wp_id( 1, 5 ) );
	}

	/** @test */
	public function test_is_enrolled_by_wp_id_false_when_expired(): void {
		$fake = $this->swap_wpdb();
		$fake->get_row_queue[] = array( 'expires_at' => '2000-01-01 00:00:00' );
		$this->assertFalse( \ATORA\LMS\LMS_Enrollment_Service::is_enrolled_by_wp_id( 1, 5 ) );
	}

	/**
	 * Regresión: get_access_expiry_by_wp_id() no filtraba por status, a
	 * diferencia de sus hermanos — podía devolver una caducidad "fantasma"
	 * de una fila 'unenrolled'. La query ahora debe incluir el mismo filtro
	 * `status IN ('active', 'completed')` que is_enrolled_by_wp_id().
	 *
	 * @test
	 */
	public function test_get_access_expiry_by_wp_id_query_filters_by_status(): void {
		$fake = $this->swap_wpdb();
		$fake->get_var_queue[] = '2099-01-01 00:00:00';

		\ATORA\LMS\LMS_Enrollment_Service::get_access_expiry_by_wp_id( 1, 5 );

		$this->assertNotEmpty( $fake->executed );
		$this->assertStringContainsString(
			"status IN ('active', 'completed')",
			$fake->executed[0],
			'get_access_expiry_by_wp_id debe filtrar por status igual que is_enrolled_by_wp_id'
		);
	}

	/** @test */
	public function test_get_access_expiry_by_wp_id_returns_string(): void {
		$fake = $this->swap_wpdb();
		$fake->get_var_queue[] = '2099-01-01 00:00:00';
		$expiry = \ATORA\LMS\LMS_Enrollment_Service::get_access_expiry_by_wp_id( 1, 5 );
		$this->assertSame( '2099-01-01 00:00:00', $expiry );
	}

	/** @test */
	public function test_get_access_expiry_by_wp_id_empty_means_perpetual(): void {
		$this->swap_wpdb();
		$this->assertSame( '', \ATORA\LMS\LMS_Enrollment_Service::get_access_expiry_by_wp_id( 1, 5 ) );
	}

	/** @test */
	public function test_is_course_completed_by_wp_id_returns_bool(): void {
		$fake = $this->swap_wpdb();
		$fake->get_var_queue[] = '1';
		$this->assertTrue( \ATORA\LMS\LMS_Enrollment_Service::is_course_completed_by_wp_id( 1, 5 ) );
	}

	/** @test */
	public function test_get_enrolled_wp_program_ids_returns_int_array(): void {
		$fake = $this->swap_wpdb();
		$fake->get_results_queue[] = array(
			array( 'wp_post_id' => '7', 'expires_at' => '' ),
		);
		$ids = \ATORA\LMS\LMS_Enrollment_Service::get_enrolled_wp_program_ids( 1 );
		$this->assertSame( array( 7 ), $ids );
	}
}

// ── LMS_Parity::cutover_ready() — gate programático (F4 — task 1.3) ──────────

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
