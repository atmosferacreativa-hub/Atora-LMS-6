<?php
/**
 * Integración 6.40.0: asistencia presencial.
 *
 * - Tomar lista por la API del docente: una sola sesión por curso, lección y fecha aunque lleguen dos teléfonos.
 * - Gana la toma más reciente; un envío viejo que llega tarde no pisa una corrección; reenviar no cambia nada.
 * - Validación (estudiante ajeno, estado inválido), permisos (otro docente, estudiante) y el asistente con delegación.
 * - Tres ausencias seguidas en fechas distintas activan la racha del seguimiento (regla existente).
 * - Resumen en la ficha del estudiante; la planificación híbrida marca su encuentro presencial; la web guarda igual.
 */

declare( strict_types = 1 );

use ATORA\LMS\LMS_Course_Service;
use ATORA\LMS\LMS_Enrollment_Service;

final class AttendanceTest extends WP_UnitTestCase {

	private int $teacher = 0;
	private int $stranger = 0;
	private int $student = 0;
	private int $student2 = 0;
	private int $outsider = 0;
	private int $wp_course = 0;
	private int $course = 0;
	private array $tokens = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		( new CLMS_DB_Migration() )->run();
		$this->assertTrue( class_exists( 'ATORA_Attendance_Service' ), 'El servicio de asistencia debe estar cargado.' );
		global $wpdb;
		foreach ( array( 'atora_attendance', 'atora_sections', 'atora_section_teachers', 'atora_section_students' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_live_sessions WHERE provider = 'presencial'" );
		ATORA_Teacher_Scope::reset_cache();
		do_action( 'rest_api_init', rest_get_server() );

		$this->teacher  = self::factory()->user->create( array( 'role' => 'lms_instructor', 'display_name' => 'Docente' ) );
		$this->stranger = self::factory()->user->create( array( 'role' => 'lms_instructor', 'display_name' => 'Otro' ) );
		$this->student  = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Ana' ) );
		$this->student2 = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Beto' ) );
		$this->outsider = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Ajeno' ) );

		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->teacher, 'post_title' => 'Curso' ) );
		$this->course    = (int) LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'];
		foreach ( array( $this->student, $this->student2 ) as $student ) {
			LMS_Enrollment_Service::enroll( $student, $this->course );
			CLMS_Helper::enroll_user_in_course( $student, $this->wp_course );
		}
		foreach ( array( $this->teacher, $this->stranger, $this->student ) as $user ) {
			$this->tokens[ $user ] = ATORA_Mobile_Token_Service::issue( $user, 'test' )['access_token'];
		}
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
		wp_set_current_user( 0 );
		\ATORA\LMS\Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	private function mark( int $student, string $status, string $at, string $note = '' ): array {
		return array( 'student_id' => $student, 'status' => $status, 'marked_at' => $at, 'note' => $note );
	}

	private function statuses( array $roster ): array {
		$out = array();
		foreach ( $roster['students'] as $row ) {
			$out[ $row['name'] ] = $row['status'];
		}
		return $out;
	}

	public function test_take_attendance_one_session_per_day_and_latest_wins(): void {
		$route = '/teacher/courses/' . $this->course . '/attendance';
		$empty = $this->call( $this->teacher, 'GET', $route, array(), array( 'date' => '2026-11-03' ) );
		$this->assertSame( 200, $empty->get_status() );
		$this->assertSame( 0, $empty->get_data()['session']['id'], 'Ver la lista no crea la sesión.' );
		$this->assertSame( array( 'Ana' => null, 'Beto' => null ), $this->statuses( $empty->get_data() ) );

		// Teléfono 1 (tomada hace 2 h) y teléfono 2 (corrección hace 1 h) sobre la misma clase. Las horas son del
		// pasado: el servidor no acepta horas futuras (reloj del teléfono adelantado) y las toma como "ahora".
		$t1    = gmdate( 'c', time() - 2 * HOUR_IN_SECONDS );
		$t2    = gmdate( 'c', time() - HOUR_IN_SECONDS );
		$first = $this->call( $this->teacher, 'POST', $route, array( 'date' => '2026-11-03', 'marks' => array(
			$this->mark( $this->student, 'presente', $t1 ),
			$this->mark( $this->student2, 'ausente', $t1 ),
		) ) );
		$this->assertSame( 200, $first->get_status(), wp_json_encode( $first->get_data() ) );
		$this->assertSame( 2, $first->get_data()['applied'] );

		$fix = $this->call( $this->teacher, 'POST', $route, array( 'date' => '2026-11-03', 'marks' => array(
			$this->mark( $this->student2, 'justificado', $t2, 'Cita médica' ),
		) ) );
		$this->assertSame( 1, $fix->get_data()['applied'] );

		// El envío viejo del teléfono 1 llega tarde (sin conexión): no pisa la corrección.
		$late = $this->call( $this->teacher, 'POST', $route, array( 'date' => '2026-11-03', 'marks' => array(
			$this->mark( $this->student2, 'ausente', $t1 ),
		) ) );
		$this->assertSame( array( 0, 1 ), array( $late->get_data()['applied'], $late->get_data()['ignored'] ) );

		// Reenvío idéntico: nada cambia.
		$again = $this->call( $this->teacher, 'POST', $route, array( 'date' => '2026-11-03', 'marks' => array(
			$this->mark( $this->student2, 'justificado', $t2, 'Cita médica' ),
		) ) );
		$this->assertSame( 0, $again->get_data()['applied'] );

		// Un teléfono con el reloj adelantado no bloquea correcciones posteriores: su hora futura cuenta como "ahora".
		$this->call( $this->teacher, 'POST', $route, array( 'date' => '2026-11-03', 'marks' => array( $this->mark( $this->student, 'tarde', gmdate( 'c', time() + 30 * DAY_IN_SECONDS ) ) ) ) );
		sleep( 1 );
		$after = $this->call( $this->teacher, 'POST', $route, array( 'date' => '2026-11-03', 'marks' => array( $this->mark( $this->student, 'presente', gmdate( 'c' ) ) ) ) );
		$this->assertSame( 1, $after->get_data()['applied'], 'La corrección real se aplica.' );

		$roster = $this->call( $this->teacher, 'GET', $route, array(), array( 'date' => '2026-11-03' ) )->get_data();
		$this->assertSame( array( 'Ana' => 'presente', 'Beto' => 'justificado' ), $this->statuses( $roster ) );
		$this->assertSame( 'Cita médica', $roster['students'][1]['note'] );

		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_live_sessions WHERE provider = 'presencial' AND course_id = %d", $this->wp_course ) ), 'Una sola sesión para ese día.' );
		$this->assertSame( 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_attendance WHERE course_id = %d", $this->wp_course ) ), 'Una fila por estudiante.' );
		$this->assertSame( 1, count( $roster['recent'] ) );
		$this->assertSame( 1, $roster['recent'][0]['counts']['justificado'] );
	}

	public function test_validation_and_permissions(): void {
		$route = '/teacher/courses/' . $this->course . '/attendance';
		$this->assertSame( 400, $this->call( $this->teacher, 'POST', $route, array( 'date' => '2026-11-03', 'marks' => array( $this->mark( $this->outsider, 'presente', '2026-11-03T12:00:00Z' ) ) ) )->get_status(), 'Estudiante no matriculado.' );
		$this->assertSame( 400, $this->call( $this->teacher, 'POST', $route, array( 'date' => '2026-11-03', 'marks' => array( $this->mark( $this->student, 'quizas', '2026-11-03T12:00:00Z' ) ) ) )->get_status(), 'Estado inválido.' );
		$this->assertSame( 400, $this->call( $this->teacher, 'POST', $route, array( 'date' => '2026-02-30', 'marks' => array( $this->mark( $this->student, 'presente', '2026-11-03T12:00:00Z' ) ) ) )->get_status(), 'Fecha inválida.' );
		$this->assertSame( 404, $this->call( $this->stranger, 'GET', $route )->get_status(), 'Docente sin el curso.' );
		$this->assertSame( 403, $this->call( $this->student, 'GET', $route )->get_status(), 'Estudiante.' );

		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_attendance" ), 'Nada se guardó.' );
	}

	public function test_three_absences_in_a_row_start_the_streak(): void {
		foreach ( array( '2026-11-03', '2026-11-05', '2026-11-10' ) as $i => $date ) {
			$result = ATORA_Attendance_Service::record_for( $this->teacher, $this->wp_course, 0, $date, array( $this->mark( $this->student, 'ausente', $date . 'T12:00:00Z' ) ) );
			$this->assertIsArray( $result );
			$this->assertSame( 2 === $i, CLMS_Attendance_Academic_Bridge::has_consecutive_absences( $this->student, $this->wp_course, 3 ), 'Racha al tercer día.' );
		}
		// Una presencia corta la racha.
		ATORA_Attendance_Service::record_for( $this->teacher, $this->wp_course, 0, '2026-11-12', array( $this->mark( $this->student, 'presente', '2026-11-12T12:00:00Z' ) ) );
		$this->assertFalse( CLMS_Attendance_Academic_Bridge::has_consecutive_absences( $this->student, $this->wp_course, 3 ) );

		$summary = ATORA_Attendance_Service::summary( $this->student, $this->wp_course );
		$this->assertSame( array( 4, 1, 3 ), array( $summary['sessions'], $summary['presente'], $summary['ausente'] ) );
		$this->assertSame( 25.0, $summary['rate'] );

		$file = $this->call( $this->teacher, 'GET', '/teacher/students/' . $this->student, array(), array( 'course' => $this->course ) );
		$this->assertSame( 4, $file->get_data()['attendance']['sessions'], 'La ficha del estudiante trae su asistencia.' );
	}

	public function test_assistant_with_delegation_takes_attendance(): void {
		global $wpdb;
		$assistant = self::factory()->user->create( array( 'role' => 'lms_instructor_assistant' ) );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institutions WHERE slug = 'asis-inst'" );
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'asis-inst', 'name' => 'Asis', 'status' => 'active' ) );
		$inst = (int) $wpdb->insert_id;
		foreach ( array( $this->teacher => 'teacher', $assistant => 'assistant' ) as $user => $role ) {
			$wpdb->insert( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $inst, 'user_id' => $user, 'role' => $role, 'status' => 'active' ) );
			update_user_meta( $user, '_atora_active_institution', $inst );
		}
		\ATORA\LMS\Tenant_Context::reset_cache();
		wp_set_current_user( $this->teacher );
		$this->assertGreaterThan( 0, ATORA_Delegation_Service::grant( $this->teacher, $assistant, array( 'scope' => 'course', 'wp_course_id' => $this->wp_course, 'perms' => array( 'content' => true ) ) ) );
		wp_set_current_user( $assistant );
		\ATORA\LMS\Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();

		$result = ATORA_Attendance_Service::record_for( $assistant, $this->wp_course, 0, '2026-11-03', array( $this->mark( $this->student, 'presente', '2026-11-03T12:00:00Z' ) ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 1, $result['applied'] );

		$wpdb->delete( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $inst ) );
		$wpdb->delete( $wpdb->prefix . 'atora_institutions', array( 'id' => $inst ) );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_instructor_delegations WHERE assistant_id = " . (int) $assistant ); // phpcs:ignore WordPress.DB
	}

	public function test_hybrid_plan_marks_in_person_lesson_and_api_lists_it(): void {
		wp_set_current_user( $this->teacher );
		$data = array(
			'title'     => 'Semana 1',
			'objective' => 'Aprender',
			'in_person' => array( 'date' => '2026-11-04', 'time' => '09:00', 'place' => 'Aula 3', 'activities' => 'Debate' ),
			'remote'    => array( 'instructions' => 'Leer' ),
		);
		$plan = ATORA_Hybrid_Plan_Service::create( $this->teacher, $this->wp_course, $data );
		$this->assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_message() : '' );
		$published = ATORA_Hybrid_Plan_Service::publish( $this->teacher, (int) $plan['id'], (int) $plan['revision'] );
		$this->assertIsArray( $published, is_wp_error( $published ) ? $published->get_error_message() : '' );

		$lessons = ATORA_Attendance_Service::in_person_lessons( $this->wp_course );
		$this->assertCount( 1, $lessons, 'El encuentro presencial queda marcado.' );
		$this->assertSame( '2026-11-04', get_post_meta( $lessons[0], '_atora_in_person_date', true ) );

		$api = $this->call( $this->teacher, 'GET', '/teacher/courses/' . $this->course . '/attendance', array(), array( 'date' => '2026-11-04' ) )->get_data();
		$this->assertSame( '2026-11-04', $api['in_person_lessons'][0]['date'] );
		$lesson = (int) $api['in_person_lessons'][0]['id'];
		$saved  = $this->call( $this->teacher, 'POST', '/teacher/courses/' . $this->course . '/attendance', array( 'date' => '2026-11-04', 'lesson_id' => $lesson, 'marks' => array( $this->mark( $this->student, 'tarde', '2026-11-04T13:10:00Z', 'Llegó 10 min tarde' ) ) ) );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( $lesson, $saved->get_data()['session']['lesson_id'] );
	}

	public function test_web_page_saves_the_same_attendance(): void {
		wp_set_current_user( $this->teacher );
		$_GET  = array( 'page' => 'atora-attendance', 'course' => $this->wp_course, 'date' => '2026-11-06', 'lesson' => 0 );
		$_POST = array(
			'atora_attendance_nonce' => wp_create_nonce( 'atora_attendance_' . $this->wp_course ),
			'status'                 => array( $this->student => 'presente', $this->student2 => 'tarde' ),
			'note'                   => array( $this->student2 => 'Tráfico' ),
		);
		$_REQUEST                  = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		ob_start();
		ATORA_Attendance_Admin::render();
		$html = (string) ob_get_clean();
		$_GET = $_POST = $_REQUEST = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assertStringContainsString( 'Asistencia guardada (2 marcas)', $html );
		$roster = ATORA_Attendance_Service::roster_for( $this->teacher, $this->wp_course, 0, '2026-11-06' );
		$this->assertSame( array( 'Ana' => 'presente', 'Beto' => 'tarde' ), $this->statuses( $roster ) );
		$this->assertSame( 'Tráfico', $roster['students'][1]['note'] );
	}
}
