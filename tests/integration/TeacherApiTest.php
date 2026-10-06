<?php
/**
 * Integración 6.31.0: API del docente (`atora-mobile/v1/teacher/*`).
 *
 * - Un estudiante recibe 403 en todo `/teacher/*`; un docente sin el curso, 404.
 * - Calificar por la API y por SpeedGrader con los mismos datos da la misma nota,
 *   la misma auditoría y el mismo aviso al estudiante.
 * - 409 ante revisión vieja; decimales por criterio conservados; borrador
 *   invisible para el estudiante, publicada visible; idempotente.
 * - Aviso a un curso: llega al hilo "Avisos" de cada estudiante.
 */

declare( strict_types = 1 );

use ATORA\LMS\LMS_Course_Service;
use ATORA\LMS\LMS_Enrollment_Service;

final class TeacherApiTest extends WP_UnitTestCase {

	private int $teacher = 0;
	private int $stranger = 0;
	private int $student = 0;
	private int $student2 = 0;
	private int $wp_course = 0;
	private int $course = 0;
	private int $wp_lesson = 0;
	private int $lesson = 0;
	private array $tokens = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		( new CLMS_DB_Migration() )->run();
		update_option( 'atora_rubric_source', 'tables', false );
		global $wpdb;
		foreach ( array( 'atora_messages', 'atora_message_participants', 'atora_message_threads', 'atora_assignment_submissions', 'atora_sections', 'atora_section_teachers', 'atora_section_students', 'atora_early_warning' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		ATORA_Teacher_Scope::reset_cache();
		do_action( 'rest_api_init', rest_get_server() );

		$this->teacher  = self::factory()->user->create( array( 'role' => 'lms_instructor', 'display_name' => 'Docente API' ) );
		$this->stranger = self::factory()->user->create( array( 'role' => 'lms_instructor', 'display_name' => 'Otro docente' ) );
		$this->student  = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Ana API' ) );
		$this->student2 = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Beto API' ) );
		$author         = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$rubric = self::factory()->post->create( array( 'post_type' => 'clms_rubric', 'post_status' => 'publish', 'post_title' => 'Rúbrica API' ) );
		update_post_meta( $rubric, '_clms_rubric_scale_type', '0_100' );
		update_post_meta( $rubric, '_clms_rubric_criteria', array(
			array( 'name' => 'Claridad', 'max_points' => 10, 'weight' => 50, 'levels' => array( array( 'label' => 'Inicial', 'points' => 4 ), array( 'label' => 'Logrado', 'points' => 8 ), array( 'label' => 'Excelente', 'points' => 10 ) ) ),
			array( 'name' => 'Estructura', 'max_points' => 10, 'weight' => 50, 'levels' => array( array( 'label' => 'Inicial', 'points' => 4 ), array( 'label' => 'Logrado', 'points' => 8 ), array( 'label' => 'Excelente', 'points' => 10 ) ) ),
		) );
		\ATORA\LMS\Rubrics_CLI::migrate( array(), array( 'yes' => true, 'batch' => 50 ) );

		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $author, 'post_title' => 'Curso API' ) );
		$this->wp_lesson = self::factory()->post->create( array(
			'post_type'   => 'lm_lesson',
			'post_status' => 'publish',
			'post_author' => $author,
			'post_title'  => 'Ensayo',
			'meta_input'  => array( '_clms_course_id' => $this->wp_course, 'lm_activity_type' => 'tarea', '_clms_rubric_id' => $rubric ),
		) );
		$this->course = (int) LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'];
		$this->lesson = (int) LMS_Course_Service::get_lesson_by_wp_post( $this->wp_lesson )['id'];
		foreach ( array( $this->student, $this->student2 ) as $student ) {
			LMS_Enrollment_Service::enroll( $student, $this->course );
			CLMS_Helper::enroll_user_in_course( $student, $this->wp_course );
		}
		$section = (int) \ATORA\LMS\Section_Service::create( array( 'wp_course_id' => $this->wp_course, 'title' => 'Sección 1' ) );
		\ATORA\LMS\Section_Service::add_teacher( $section, $this->teacher );
		foreach ( array( $this->teacher, $this->stranger, $this->student, $this->student2 ) as $user ) {
			$this->tokens[ $user ] = ATORA_Mobile_Token_Service::issue( $user, 'test' )['access_token'];
		}
	}

	private function submission( int $student ): int {
		$id = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $student ) );
		update_post_meta( $id, '_clms_submission_user_id', $student );
		update_post_meta( $id, '_clms_submission_lesson_id', $this->wp_lesson );
		update_post_meta( $id, '_clms_submission_course_id', $this->wp_course );
		update_post_meta( $id, '_clms_submission_comment', 'Mi ensayo' );
		update_post_meta( $id, '_clms_submission_status', 'submitted' );
		return $id;
	}

	private function call( int $user, string $method, string $route, array $body = array(), array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/atora-mobile/v1' . $route );
		$request->set_header( 'Authorization', 'Bearer ' . $this->tokens[ $user ] );
		if ( $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		foreach ( $query as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_ensure_response( rest_get_server()->dispatch( $request ) );
		return apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
	}

	private function grade_body( string $event, bool $publish, ?int $expected = null ): array {
		$body = array(
			'client_event_id' => $event,
			'publish'         => $publish,
			'feedback'        => 'Buen ensayo',
			'scores'          => array(
				array( 'index' => 0, 'score' => 8.5, 'feedback' => 'Claro' ),
				array( 'index' => 1, 'score' => 7.25, 'feedback' => 'Ordenado' ),
			),
		);
		if ( null !== $expected ) {
			$body['expected_revision'] = $expected;
		}
		return $body;
	}

	public function test_student_gets_403_everywhere_and_stranger_teacher_404(): void {
		$sub = $this->submission( $this->student );
		foreach ( array(
			array( 'GET', '/teacher/today' ),
			array( 'GET', '/teacher/courses' ),
			array( 'GET', "/teacher/courses/{$this->course}/students" ),
			array( 'GET', "/teacher/students/{$this->student}" ),
			array( 'GET', '/teacher/submissions' ),
			array( 'GET', "/teacher/submissions/{$sub}" ),
			array( 'POST', "/teacher/submissions/{$sub}/grade" ),
			array( 'POST', '/teacher/announcements' ),
		) as $route ) {
			$this->assertSame( 403, $this->call( $this->student, $route[0], $route[1] )->get_status(), implode( ' ', $route ) );
		}

		$this->assertSame( 404, $this->call( $this->stranger, 'GET', "/teacher/submissions/{$sub}" )->get_status() );
		$this->assertSame( 404, $this->call( $this->stranger, 'POST', "/teacher/submissions/{$sub}/grade", $this->grade_body( 'x1', true ) )->get_status() );
		$this->assertSame( 404, $this->call( $this->stranger, 'GET', "/teacher/courses/{$this->course}/students" )->get_status() );
		$this->assertSame( 404, $this->call( $this->stranger, 'GET', "/teacher/students/{$this->student}", array(), array( 'course' => $this->course ) )->get_status() );
		$this->assertSame( array(), $this->call( $this->stranger, 'GET', '/teacher/courses' )->get_data()['items'] );
	}

	public function test_teacher_today_courses_students_queue_and_detail(): void {
		$sub      = $this->submission( $this->student );
		$response = $this->call( $this->teacher, 'GET', '/teacher/today' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] ?? '' );
		$this->assertSame( 1, $response->get_data()['to_grade']['count'] );
		$this->assertSame( $sub, $response->get_data()['to_grade']['oldest'][0]['id'] );

		$courses = $this->call( $this->teacher, 'GET', '/teacher/courses' )->get_data()['items'];
		$this->assertSame( $this->course, $courses[0]['id'] );
		$this->assertSame( 2, $courses[0]['students'] );
		$this->assertSame( 1, $courses[0]['pending_submissions'] );
		$this->assertSame( 'Sección 1', $courses[0]['sections'][0]['title'] );

		$students = $this->call( $this->teacher, 'GET', "/teacher/courses/{$this->course}/students", array(), array( 'search' => 'ana' ) )->get_data();
		$this->assertSame( 1, $students['total'] );
		$this->assertSame( 'Ana API', $students['items'][0]['name'] );
		$this->assertArrayHasKey( 'reasons', $students['items'][0]['risk'] );
		$this->assertContains( $students['items'][0]['risk']['level'], array( 'bajo', 'medio', 'alto' ) );

		$ficha = $this->call( $this->teacher, 'GET', "/teacher/students/{$this->student}", array(), array( 'course' => $this->course ) );
		$this->assertSame( 200, $ficha->get_status() );
		$this->assertSame( $sub, $ficha->get_data()['submissions'][0]['id'] );

		$queue = $this->call( $this->teacher, 'GET', '/teacher/submissions', array(), array( 'status' => 'pending', 'course' => $this->course ) )->get_data();
		$this->assertSame( $sub, $queue['items'][0]['id'] );

		$detail = $this->call( $this->teacher, 'GET', "/teacher/submissions/{$sub}" )->get_data()['submission'];
		$this->assertSame( 0, $detail['revision'] );
		$this->assertSame( 'Mi ensayo', $detail['attempts'][0]['body_text'] );
		$this->assertCount( 2, $detail['rubric']['criteria'] );
		$this->assertCount( 3, $detail['rubric']['criteria'][0]['levels'] );
		$this->assertNotEmpty( $detail['rubric']['criteria'][0]['bands'] );
	}

	public function test_api_and_speedgrader_give_same_grade_audit_and_notice(): void {
		$via_api = $this->submission( $this->student );
		$via_web = $this->submission( $this->student2 );

		$api = $this->call( $this->teacher, 'POST', "/teacher/submissions/{$via_api}/grade", $this->grade_body( 'evt-api-1', true, 0 ) );
		$this->assertSame( 200, $api->get_status(), wp_json_encode( $api->get_data() ) );

		wp_set_current_user( $this->teacher );
		$_POST = array(
			CLMS_Grading::SPEEDGRADE_NONCE => wp_create_nonce( CLMS_Grading::SPEEDGRADE_ACTION . '_' . $via_web ),
			'expected_revision' => (string) ATORA_Grading_Save_Service::revision( $via_web ), // 6.31.1: obligatoria
			'clms_sg_submit'  => 'publish',
			'status'          => 'graded',
			'feedback'        => 'Buen ensayo',
			'grade'           => '',
			'rubric_scores'   => array( '8.5', '7.25' ),
			'rubric_feedback' => array( 'Claro', 'Ordenado' ),
		);
		$grading = new CLMS_Grading();
		$save    = new ReflectionMethod( $grading, 'handle_speedgrade_save' );
		$save->setAccessible( true );
		$web   = $save->invoke( $grading, $via_web, $this->teacher );
		$_POST = array();
		$this->assertIsArray( $web );

		foreach ( array( '_clms_submission_grade', '_clms_submission_status', '_clms_submission_feedback' ) as $key ) {
			$this->assertSame( get_post_meta( $via_web, $key, true ), get_post_meta( $via_api, $key, true ), $key );
		}
		$this->assertSame( get_post_meta( $via_web, '_clms_submission_rubric_scores', true ), get_post_meta( $via_api, '_clms_submission_rubric_scores', true ), 'Mismos puntajes por criterio (decimales incluidos).' );
		$this->assertEquals( 8.5, get_post_meta( $via_api, '_clms_submission_rubric_scores', true )[0]['score'] );

		$eval_api = \ATORA\LMS\Rubric_Service::get_evaluation( $via_api );
		$eval_web = \ATORA\LMS\Rubric_Service::get_evaluation( $via_web );
		$this->assertSame( 'speedgrader', $eval_api['source'] );
		$snap_api = json_decode( (string) $eval_api['snapshot_json'], true );
		$snap_web = json_decode( (string) $eval_web['snapshot_json'], true );
		$this->assertSame( $snap_web['scores'], $snap_api['scores'], 'Misma auditoría.' );
		$this->assertSame( $snap_web['grade'], $snap_api['grade'] );

		$notice = static fn( int $user ) => array_values( array_filter( ATORA_Inbox_Store::received( $user ), static fn( $r ) => (int) $r['submission_id'] > 0 ) );
		$this->assertCount( count( $notice( $this->student2 ) ), $notice( $this->student ), 'Mismo aviso al estudiante.' );
		$this->assertSame( wp_list_pluck( $notice( $this->student2 ), 'kind' ), wp_list_pluck( $notice( $this->student ), 'kind' ) );
	}

	public function test_conflict_idempotency_and_draft_visibility(): void {
		$sub = $this->submission( $this->student );

		// 6.31.1: la API exige la revisión que vio el docente.
		$missing = $this->call( $this->teacher, 'POST', "/teacher/submissions/{$sub}/grade", $this->grade_body( 'evt-sin-revision', false ) );
		$this->assertSame( 400, $missing->get_status() );
		$this->assertSame( 'atora_grade_revision_required', $missing->get_data()['code'] );
		$this->assertSame( 0, ATORA_Grading_Save_Service::revision( $sub ) );

		$draft = $this->call( $this->teacher, 'POST', "/teacher/submissions/{$sub}/grade", $this->grade_body( 'evt-draft', false, 0 ) );
		$this->assertSame( 200, $draft->get_status() );
		$this->assertSame( 1, $draft->get_data()['submission']['revision'] );
		$this->assertSame( 8.5, $draft->get_data()['submission']['rubric']['criteria'][0]['score'], 'Decimales conservados.' );

		$replay = $this->call( $this->teacher, 'POST', "/teacher/submissions/{$sub}/grade", $this->grade_body( 'evt-draft', false, 0 ) );
		$this->assertTrue( $replay->get_data()['replayed'] );
		$this->assertSame( 1, ATORA_Grading_Save_Service::revision( $sub ), 'Idempotente: no guarda dos veces.' );

		$visible = static function ( int $student, int $lesson ) {
			wp_set_current_user( $student );
			$request = new WP_REST_Request( 'GET', "/atora-mobile/v1/assignments/{$lesson}" );
			$request->set_url_params( array( 'lesson_id' => $lesson ) );
			$data = ATORA_Mobile_REST_Controller::assignment( $request )->get_data();
			return CLMS_Student_Grade_Visibility::for_submission_post( (int) get_posts( array( 'post_type' => 'clms_submission', 'fields' => 'ids', 'author' => $student ) )[0] )['grade'];
		};
		$this->assertNull( $visible( $this->student, $this->lesson ), 'Borrador: el estudiante no ve la nota.' );

		$stale = $this->call( $this->teacher, 'POST', "/teacher/submissions/{$sub}/grade", $this->grade_body( 'evt-stale', true, 0 ) );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'atora_grade_revision_conflict', $stale->get_data()['code'] );
		$this->assertSame( 1, $stale->get_data()['data']['submission']['revision'], 'Devuelve la versión actual.' );
		$this->assertSame( 'in_review', get_post_meta( $sub, '_clms_submission_status', true ), 'No pisa.' );

		// Nota final opcional: la app la copia del % de la rúbrica (8,5 + 7,25 de 20 = 79).
		$publish = $this->call( $this->teacher, 'POST', "/teacher/submissions/{$sub}/grade", $this->grade_body( 'evt-pub', true, 1 ) + array( 'grade' => 79 ) );
		$this->assertSame( 200, $publish->get_status() );
		$this->assertSame( '79', (string) $visible( $this->student, $this->lesson ), 'Publicada: visible.' );
	}

	public function test_announcement_reaches_students_avisos_once(): void {
		$body = array( 'course_id' => $this->course, 'title' => 'Mañana no hay clase', 'body' => 'Nos vemos el jueves.', 'client_event_id' => 'aviso-1' );
		$sent = $this->call( $this->teacher, 'POST', '/teacher/announcements', $body );
		$this->assertSame( 201, $sent->get_status() );
		$this->assertSame( 2, $sent->get_data()['recipients'] );
		$this->assertTrue( $this->call( $this->teacher, 'POST', '/teacher/announcements', $body )->get_data()['replayed'] );

		$notices = ATORA_Inbox_Store::received( $this->student, array( 'system_only' => true ) );
		$this->assertCount( 1, array_filter( $notices, static fn( $r ) => 'course_announcement' === $r['kind'] ) );
		$this->assertSame( 'Mañana no hay clase', array_values( array_filter( $notices, static fn( $r ) => 'course_announcement' === $r['kind'] ) )[0]['title'] );
		$this->assertSame( 404, $this->call( $this->stranger, 'POST', '/teacher/announcements', $body + array() )->get_status() );
	}
}
