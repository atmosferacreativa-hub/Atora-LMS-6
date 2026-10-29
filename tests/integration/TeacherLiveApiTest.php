<?php
/**
 * Integración 6.40.0: clase en vivo del docente en la app.
 *
 * - Hoy del docente: la clase en vivo trae el enlace cuando abre la ventana.
 * - Grabación desde la app: publicar un enlace, quitarla (el proveedor ya no la vuelve a poner), enlace inválido 400,
 *   y permisos.
 * - Asistencia del proveedor visible para el docente.
 * - Regresión: guardar la clase en vivo de una lección no pisa la sesión presencial (asistencia del aula) de esa
 *   misma lección.
 */

declare( strict_types = 1 );

use ATORA\LMS\LMS_Course_Service;
use ATORA\LMS\LMS_Enrollment_Service;
use ATORA\LiveStreaming\Live_Streaming;

final class TeacherLiveApiTest extends WP_UnitTestCase {

	private int $teacher = 0;
	private int $stranger = 0;
	private int $student = 0;
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
		if ( ! class_exists( Live_Streaming::class ) ) {
			$dir = dirname( __DIR__, 2 ) . '/modules/live-streaming/';
			foreach ( array( 'class-live-session-repository.php', 'providers/class-provider-interface.php', 'providers/class-provider-zoom.php', 'providers/class-provider-teams.php', 'class-live-settings.php', 'class-live-streaming.php' ) as $file ) {
				require_once $dir . $file;
			}
			Live_Streaming::init();
		}
		global $wpdb;
		foreach ( array( 'atora_live_sessions', 'atora_attendance', 'atora_sections', 'atora_section_teachers', 'atora_section_students' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		ATORA_Teacher_Scope::reset_cache();
		do_action( 'rest_api_init', rest_get_server() );

		$this->teacher  = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$this->stranger = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$this->student  = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Ana' ) );

		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->teacher ) );
		$this->wp_lesson = self::factory()->post->create( array( 'post_type' => 'lm_lesson', 'post_status' => 'publish', 'post_author' => $this->teacher, 'post_title' => 'Clase en vivo', 'meta_input' => array( '_clms_course_id' => $this->wp_course ) ) );
		$this->course    = (int) LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'];
		$this->lesson    = (int) LMS_Course_Service::get_lesson_by_wp_post( $this->wp_lesson )['id'];
		LMS_Enrollment_Service::enroll( $this->student, $this->course );
		CLMS_Helper::enroll_user_in_course( $this->student, $this->wp_course );
		foreach ( array( $this->teacher, $this->stranger, $this->student ) as $user ) {
			$this->tokens[ $user ] = ATORA_Mobile_Token_Service::issue( $user, 'test' )['access_token'];
		}
	}

	private function call( int $user, string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/atora-mobile/v1' . $route );
		$request->set_header( 'Authorization', 'Bearer ' . $this->tokens[ $user ] );
		if ( $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		wp_set_current_user( 0 );
		\ATORA\LMS\Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	private function live_in( int $seconds, string $mode = 'provider' ): void {
		Live_Streaming::save_session( $this->wp_lesson, array(
			'provider'         => 'custom',
			'start_datetime'   => wp_date( 'Y-m-d H:i', time() + $seconds, wp_timezone() ),
			'duration_minutes' => 60,
			'timezone'         => wp_timezone_string(),
			'recording_mode'   => $mode,
			'custom_url'       => 'https://meet.example.test/clase',
		), $this->teacher );
	}

	public function test_today_brings_the_join_link_when_the_class_opens(): void {
		$this->live_in( 5 * MINUTE_IN_SECONDS );
		$today = $this->call( $this->teacher, 'GET', '/teacher/today' )->get_data()['today'];
		$live  = array_values( array_filter( $today, static fn( $i ) => 'live_class' === $i['type'] ) );
		$this->assertCount( 1, $live );
		$this->assertSame( 'https://meet.example.test/clase', $live[0]['live']['join_url'] );
		$this->assertSame( 'open', $live[0]['live']['status'] );
	}

	public function test_class_running_past_midnight_still_shows_today(): void {
		$midnight = ( new DateTimeImmutable( 'today', wp_timezone() ) );
		Live_Streaming::save_session( $this->wp_lesson, array(
			'provider'         => 'custom',
			'start_datetime'   => $midnight->modify( '-1 hour' )->format( 'Y-m-d H:i' ),
			'duration_minutes' => 180,
			'timezone'         => wp_timezone_string(),
			'recording_mode'   => 'provider',
			'custom_url'       => 'https://meet.example.test/clase',
		), $this->teacher );
		$today = $this->call( $this->teacher, 'GET', '/teacher/today' )->get_data()['today'];
		$this->assertCount( 1, array_filter( $today, static fn( $i ) => 'live_class' === $i['type'] ), 'Empezó ayer y termina hoy: está en Hoy.' );

		Live_Streaming::save_session( $this->wp_lesson, array(
			'provider'         => 'custom',
			'start_datetime'   => $midnight->modify( '-10 hours' )->format( 'Y-m-d H:i' ),
			'duration_minutes' => 60,
			'timezone'         => wp_timezone_string(),
			'recording_mode'   => 'provider',
			'custom_url'       => 'https://meet.example.test/clase',
		), $this->teacher );
		$today = $this->call( $this->teacher, 'GET', '/teacher/today' )->get_data()['today'];
		$this->assertCount( 0, array_filter( $today, static fn( $i ) => 'live_class' === $i['type'] ), 'Terminó ayer: no está en Hoy.' );
	}

	public function test_teacher_publishes_and_clears_the_recording(): void {
		$this->live_in( -3 * HOUR_IN_SECONDS );
		$route = '/teacher/lessons/' . $this->lesson . '/recording';

		$this->assertSame( 400, $this->call( $this->teacher, 'POST', $route, array( 'url' => 'javascript:alert(1)' ) )->get_status() );
		$saved = $this->call( $this->teacher, 'POST', $route, array( 'url' => 'https://drive.example.test/grabacion' ) );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'https://drive.example.test/grabacion', $saved->get_data()['lesson']['live']['recording']['url'] );

		$this->assertSame( 403, $this->call( $this->student, 'POST', $route, array( 'clear' => true ) )->get_status(), 'Estudiante.' );
		$this->assertSame( 404, $this->call( $this->stranger, 'POST', $route, array( 'clear' => true ) )->get_status(), 'Otro docente.' );

		$cleared = $this->call( $this->teacher, 'POST', $route, array( 'clear' => true ) );
		$this->assertNull( $cleared->get_data()['lesson']['live']['recording'] );
		$this->assertFalse( Live_Streaming::store_provider_recording( $this->wp_lesson, 'zoom', 'https://zoom.test/rec' ), 'Quitada por el docente: el proveedor no la vuelve a poner.' );
	}

	public function test_teacher_sees_provider_attendance(): void {
		$this->live_in( -3 * HOUR_IN_SECONDS );
		$session = \ATORA\LiveStreaming\Live_Session_Repository::get_by_lesson( $this->wp_lesson );
		\ATORA\LiveStreaming\Live_Session_Repository::record_attendance( array( 'user_id' => $this->student, 'session_id' => (int) $session['id'], 'course_id' => $this->wp_course, 'source' => 'zoom', 'duration_seconds' => 3300, 'status' => 'presente' ) );
		\ATORA\LiveStreaming\Live_Session_Repository::record_attendance( array( 'user_id' => 0, 'external_participant_id' => 'g1', 'display_name' => 'Invitado', 'session_id' => (int) $session['id'], 'course_id' => $this->wp_course, 'source' => 'zoom', 'duration_seconds' => 600, 'status' => 'ausente' ) );

		$data = $this->call( $this->teacher, 'GET', '/teacher/lessons/' . $this->lesson . '/live-attendance' )->get_data();
		$this->assertSame( array( 'Ana', 'Invitado' ), array_column( $data['items'], 'name' ) );
		$this->assertSame( array( true, false ), array_column( $data['items'], 'registered' ) );
		$this->assertSame( 55, $data['items'][0]['duration_minutes'] );
		$this->assertSame( 404, $this->call( $this->stranger, 'GET', '/teacher/lessons/' . $this->lesson . '/live-attendance' )->get_status() );
	}

	public function test_saving_the_live_class_does_not_overwrite_in_person_attendance(): void {
		$in_person = ATORA_Attendance_Service::record_for( $this->teacher, $this->wp_course, $this->wp_lesson, '2026-11-03', array(
			array( 'student_id' => $this->student, 'status' => 'presente', 'marked_at' => gmdate( 'c', time() - 60 ) ),
		) );
		$this->assertIsArray( $in_person );
		$this->live_in( DAY_IN_SECONDS );

		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT provider FROM {$wpdb->prefix}atora_live_sessions WHERE lesson_id = %d ORDER BY id", $this->wp_lesson ), ARRAY_A );
		$this->assertSame( array( 'presencial', 'custom' ), array_column( $rows, 'provider' ), 'Dos sesiones distintas: la del aula sigue intacta.' );
		$this->assertSame( 'custom', \ATORA\LiveStreaming\Live_Session_Repository::get_by_lesson( $this->wp_lesson )['provider'] );
		$roster = ATORA_Attendance_Service::roster_for( $this->teacher, $this->wp_course, $this->wp_lesson, '2026-11-03' );
		$this->assertSame( 'presente', $roster['students'][0]['status'] );
	}
}
