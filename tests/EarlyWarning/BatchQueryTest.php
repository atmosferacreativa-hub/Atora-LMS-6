<?php
/**
 * Early Warning — S3.0: el escaneo de entregas perdidas debe usar UNA
 * consulta por curso para resolver qué pares (estudiante, lección) ya
 * tienen entrega, no un get_posts() por cada combinación
 * estudiante×lección.
 *
 * @package ATORA_LMS\Tests\EarlyWarning
 */

declare( strict_types = 1 );

namespace {
	require_once __DIR__ . '/../stubs/class-clms-helper-stub.php';
}

namespace ATORA\Tests\EarlyWarning {

use PHPUnit\Framework\TestCase;

final class FakeWpdbEarlyWarning {
	public string $prefix   = 'wp_';
	public string $postmeta = 'wp_postmeta';
	public string $posts    = 'wp_posts';

	/** Filas que get_existing_submission_pairs() debe "encontrar" ya entregadas. */
	public array $submission_rows = array();
	public int $get_results_call_count = 0;

	/** Capturadas por upsert_warning()/close_warning(). */
	public array $insert_calls = array();
	public array $update_calls = array();

	public function prepare( $sql, ...$args ): string {
		return is_string( $sql ) ? $sql : '';
	}

	public function get_results( $sql, $output = null ) {
		$this->get_results_call_count++;
		return $this->submission_rows;
	}

	public function get_var( $sql ) {
		return null; // no hay warning previo -> upsert_warning() inserta
	}

	public function get_col( $sql ) {
		return array();
	}

	public function insert( $table, $data, $format = null ): int {
		$this->insert_calls[] = $data;
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ): int {
		$this->update_calls[] = array( 'data' => $data, 'where' => $where );
		return 1;
	}
}

final class BatchQueryTest extends TestCase {

	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../modules/early-warning/class-early-warning-service.php';

		\atora_test_reset_clms_helper_stub();
		atora_test_reset_post_meta();

		global $wpdb;
		$this->original_wpdb = $wpdb;
		$wpdb = new FakeWpdbEarlyWarning();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		\atora_test_reset_clms_helper_stub();
		parent::tearDown();
	}

	/**
	 * 3 estudiantes x 2 lecciones vencidas = 6 combinaciones. Con el fix
	 * de S3.0, eso es UNA sola llamada a get_results() (la consulta
	 * batch), sin importar cuántos estudiantes/lecciones haya — antes
	 * del fix habrían sido 6 llamadas a get_posts().
	 */
	public function test_scan_uses_a_single_batch_query_regardless_of_student_lesson_count(): void {
		$course_id = 1;
		$lesson_a  = 10;
		$lesson_b  = 11;

		atora_test_set_course_lessons( $course_id, array( $lesson_a, $lesson_b ) );
		atora_test_set_enrolled_students( $course_id, array( 100, 200, 300 ) );

		$past = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		foreach ( array( $lesson_a, $lesson_b ) as $lesson_id ) {
			atora_test_set_post_meta( $lesson_id, '_clms_due_date', $past );
			atora_test_set_post_meta( $lesson_id, '_clms_due_time', '23:59' );
		}

		// Nadie entregó nada -> submission_rows queda vacío (default).
		$service = new \ATORA\EarlyWarning\Early_Warning_Service();
		$service->scan_course( $course_id, false );

		global $wpdb;
		$this->assertSame(
			1,
			$wpdb->get_results_call_count,
			'debe resolver todas las entregas del curso en una sola consulta batch, no una por estudiante x lección'
		);
	}

	/** @test */
	public function students_with_all_submissions_are_not_flagged_as_missing(): void {
		$course_id = 1;
		$lesson_a  = 10;
		$lesson_b  = 11;

		atora_test_set_course_lessons( $course_id, array( $lesson_a, $lesson_b ) );
		atora_test_set_enrolled_students( $course_id, array( 100, 200 ) );

		$past = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		foreach ( array( $lesson_a, $lesson_b ) as $lesson_id ) {
			atora_test_set_post_meta( $lesson_id, '_clms_due_date', $past );
			atora_test_set_post_meta( $lesson_id, '_clms_due_time', '23:59' );
		}

		global $wpdb;
		// Estudiante 100 entregó AMBAS lecciones; 200 no entregó ninguna.
		$wpdb->submission_rows = array(
			array( 'lesson_id' => (string) $lesson_a, 'user_id' => '100' ),
			array( 'lesson_id' => (string) $lesson_b, 'user_id' => '100' ),
		);

		$service = new \ATORA\EarlyWarning\Early_Warning_Service();
		$service->scan_course( $course_id, false );

		// Solo debe haber una alerta insertada (para el 200); el 100 se
		// cierra (close_warning -> update), no se inserta.
		$this->assertCount( 1, $wpdb->insert_calls, 'solo el estudiante sin entregas genera una alerta nueva' );
		$this->assertSame( 200, (int) $wpdb->insert_calls[0]['user_id'] );

		$missed_data = json_decode( (string) $wpdb->insert_calls[0]['data'], true );
		$this->assertSame( 2, $missed_data['count'], 'al estudiante 200 le faltan las 2 lecciones' );

		$this->assertNotEmpty( $wpdb->update_calls, 'el estudiante 100 (al día) pasa por close_warning()' );
	}
}
}
