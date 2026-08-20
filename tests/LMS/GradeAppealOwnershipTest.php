<?php
/**
 * CLMS_Grading_Engine — apelaciones de nota, verificación de dueño — PT-8/PT-9 (sprint 6.5.5).
 *
 * Hallazgos confirmados por el barrido de auditoría de este sprint:
 *
 * 1. submit_grade_appeal() no verificaba que la entrega (submission_id)
 *    perteneciera al alumno que apela — cualquier usuario autenticado
 *    podía leer la nota original de otro alumno (devuelta en la
 *    respuesta 201) pasando cualquier submission_id.
 *
 * 2. process_appeal() solo estaba gateado por una capability genérica
 *    (can_manage_content()), sin verificar que el curso de la
 *    apelación perteneciera al instructor que la procesa — cualquier
 *    instructor podía aprobar/rechazar apelaciones (y así cambiar
 *    notas) de cursos ajenos.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

class GradeAppealOwnershipTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\atora_test_reset_user_caps();
		$GLOBALS['__atora_test_posts']     = array();
		$GLOBALS['__atora_test_post_meta'] = array();
		$GLOBALS['__atora_test_current_user_id'] = 0;
	}

	protected function tearDown(): void {
		\atora_test_reset_user_caps();
		$GLOBALS['__atora_test_posts']     = array();
		$GLOBALS['__atora_test_post_meta'] = array();
		parent::tearDown();
	}

	private function install_wpdb_fixture(): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class {
			public string $prefix = 'wp_';
			public int    $insert_id = 500;
			public ?array $last_insert_data = null;
			public ?array $last_update_data = null;
			public array  $appeals_by_id = array();

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
					return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
				}, $sql );
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) {
				foreach ( $this->appeals_by_id as $id => $row ) {
					if ( false !== strpos( $sql, $id ) ) {
						return $row;
					}
				}
				return null;
			}

			public function get_var( $sql ) { return null; }
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }

			public function insert( $table, $data, $format = null ): int {
				$this->last_insert_data = $data;
				$this->insert_id++;
				return 1;
			}

			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				$this->last_update_data = $data;
				return 1;
			}

			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function query( $sql ): int { return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};

		return $original;
	}

	private function restore_wpdb( object $original ): void {
		global $wpdb;
		$wpdb = $original;
	}

	// ── submit_grade_appeal() ────────────────────────────────────────────────

	/** @test */
	public function test_student_can_appeal_their_own_submission(): void {
		$original = $this->install_wpdb_fixture();
		atora_test_set_post_meta( 900, '_clms_submission_course_id', 10 );
		atora_test_set_post( 900, array( 'post_author' => 7 ) );

		$engine = new \CLMS_Grading_Engine();
		$appeal = $engine->submit_grade_appeal( 7, 900, 'quiz', 'creo que hubo un error' );

		$this->assertIsArray( $appeal );
		$this->assertSame( 7, $appeal['student_id'] );

		$this->restore_wpdb( $original );
	}

	/**
	 * Caso confirmado del hallazgo: un alumno no puede apelar (ni leer
	 * la nota de) la entrega de OTRO alumno.
	 *
	 * @test
	 */
	public function test_student_cannot_appeal_another_students_submission(): void {
		$original = $this->install_wpdb_fixture();
		atora_test_set_post_meta( 900, '_clms_submission_course_id', 10 );
		atora_test_set_post( 900, array( 'post_author' => 7 ) ); // dueño real: 7.

		$engine = new \CLMS_Grading_Engine();
		$appeal = $engine->submit_grade_appeal( 8, 900, 'quiz', 'no es mío' ); // 8 intenta apelar la entrega de 7.

		$this->assertFalse( $appeal, 'un alumno no debe poder apelar (ni obtener datos de) la entrega de otro alumno' );

		global $wpdb;
		$this->assertNull( $wpdb->last_insert_data, 'no debe crearse ninguna apelación' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_admin_can_appeal_on_behalf_regardless_of_submission_owner(): void {
		$original = $this->install_wpdb_fixture();
		atora_test_set_post_meta( 900, '_clms_submission_course_id', 10 );
		atora_test_set_post( 900, array( 'post_author' => 7 ) );
		atora_test_set_user_cap( 1, 'manage_options' );
		$GLOBALS['__atora_test_current_user_id'] = 1;

		$engine = new \CLMS_Grading_Engine();
		$appeal = $engine->submit_grade_appeal( 1, 900, 'quiz', 'apelación administrativa' );

		$this->assertIsArray( $appeal );

		$this->restore_wpdb( $original );
	}

	// ── process_appeal() ──────────────────────────────────────────────────────

	/**
	 * Caso confirmado del hallazgo: un instructor no debe poder procesar
	 * (aprobar/rechazar, y así cambiar la nota de) una apelación de un
	 * curso que no le pertenece.
	 *
	 * @test
	 */
	public function test_instructor_cannot_process_appeal_of_a_course_they_do_not_own(): void {
		$original = $this->install_wpdb_fixture();
		global $wpdb;
		$wpdb->appeals_by_id['appeal_xyz'] = array(
			'appeal_id'     => 'appeal_xyz',
			'student_id'    => 7,
			'course_id'     => 10,
			'submission_id' => 900,
			'component'     => 'quiz',
			'status'        => 'pending',
		);
		atora_test_set_post( 10, array( 'post_author' => 2 ) ); // el curso 10 es del instructor 2.
		$GLOBALS['__atora_test_current_user_id'] = 3; // instructor DISTINTO intenta procesarla.

		$engine = new \CLMS_Grading_Engine();
		$result = $engine->process_appeal( 'appeal_xyz', 'approved', 95.0 );

		$this->assertFalse( $result, 'un instructor no debe poder procesar apelaciones de cursos ajenos' );
		$this->assertNull( $wpdb->last_update_data, 'no debe escribirse ningún cambio de estado' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_owning_instructor_can_process_the_appeal(): void {
		$original = $this->install_wpdb_fixture();
		global $wpdb;
		$wpdb->appeals_by_id['appeal_abc'] = array(
			'appeal_id'     => 'appeal_abc',
			'student_id'    => 7,
			'course_id'     => 10,
			'submission_id' => 900,
			'component'     => 'quiz',
			'status'        => 'pending',
		);
		atora_test_set_post( 10, array( 'post_author' => 2 ) );
		$GLOBALS['__atora_test_current_user_id'] = 2; // el dueño real del curso.

		$engine = new \CLMS_Grading_Engine();
		$result = $engine->process_appeal( 'appeal_abc', 'approved', 95.0 );

		$this->assertTrue( $result );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_edit_others_lm_courses_can_process_any_appeal(): void {
		$original = $this->install_wpdb_fixture();
		global $wpdb;
		$wpdb->appeals_by_id['appeal_def'] = array(
			'appeal_id'     => 'appeal_def',
			'student_id'    => 7,
			'course_id'     => 10,
			'submission_id' => 900,
			'component'     => 'quiz',
			'status'        => 'pending',
		);
		atora_test_set_post( 10, array( 'post_author' => 2 ) );
		atora_test_set_user_cap( 9, 'edit_others_lm_courses' );
		$GLOBALS['__atora_test_current_user_id'] = 9;

		$engine = new \CLMS_Grading_Engine();
		$result = $engine->process_appeal( 'appeal_def', 'rejected' );

		$this->assertTrue( $result );

		$this->restore_wpdb( $original );
	}
}
