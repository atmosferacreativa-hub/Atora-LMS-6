<?php
/**
 * Integración 6.40.0: contenido del curso en la app del docente.
 *
 * - Lista de lecciones en orden, también las ocultas, con tipo, fecha, entregas por calificar y clase en vivo.
 * - El docente abre una lección (también oculta) en vista de estudiante; un estudiante no ve la oculta; otro docente
 *   sin el curso recibe 404.
 * - Publicar u ocultar y cambiar la fecha de entrega (con aviso opcional), idempotente; el asistente con delegación no
 *   puede; fecha inválida, 400.
 */

declare( strict_types = 1 );

use ATORA\LMS\LMS_Course_Service;
use ATORA\LMS\LMS_Enrollment_Service;

final class TeacherContentApiTest extends WP_UnitTestCase {

	private int $teacher = 0;
	private int $assistant = 0;
	private int $stranger = 0;
	private int $student = 0;
	private int $wp_course = 0;
	private int $course = 0;
	private array $wp_lessons = array();
	private array $tokens = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		( new CLMS_DB_Migration() )->run();
		global $wpdb;
		foreach ( array( 'atora_sections', 'atora_section_teachers', 'atora_section_students', 'atora_messages', 'atora_message_participants', 'atora_message_threads' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		ATORA_Teacher_Scope::reset_cache();
		do_action( 'rest_api_init', rest_get_server() );

		$this->teacher   = self::factory()->user->create( array( 'role' => 'lms_instructor', 'display_name' => 'Docente' ) );
		$this->assistant = self::factory()->user->create( array( 'role' => 'lms_instructor_assistant', 'display_name' => 'Asistente' ) );
		$this->stranger  = self::factory()->user->create( array( 'role' => 'lms_instructor', 'display_name' => 'Otro' ) );
		$this->student   = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Ana' ) );

		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->teacher, 'post_title' => 'Curso' ) );
		$specs = array(
			array( 'Introducción', 'publish', 1, array( 'lm_activity_type' => 'lectura' ) ),
			array( 'Ensayo', 'publish', 2, array( 'lm_activity_type' => 'tarea', '_clms_due_date' => '2026-11-10', '_clms_due_time' => '18:00' ) ),
			array( 'Borrador', 'draft', 3, array( 'lm_activity_type' => 'lectura' ) ),
		);
		foreach ( $specs as $spec ) {
			$this->wp_lessons[] = self::factory()->post->create( array(
				'post_type'   => 'lm_lesson',
				'post_status' => $spec[1],
				'post_author' => $this->teacher,
				'post_title'  => $spec[0],
				'menu_order'  => $spec[2],
				'meta_input'  => array( '_clms_course_id' => $this->wp_course ) + $spec[3],
			) );
		}
		$this->course = (int) LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'];
		LMS_Enrollment_Service::enroll( $this->student, $this->course );
		CLMS_Helper::enroll_user_in_course( $this->student, $this->wp_course );

		// Asistente con delegación del docente sobre el curso (no es autor ni docente de sección).
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institutions WHERE slug = 'docente-inst'" );
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'docente-inst', 'name' => 'Docente', 'status' => 'active' ) );
		$inst = (int) $wpdb->insert_id;
		foreach ( array( $this->teacher => 'teacher', $this->assistant => 'assistant' ) as $user => $role ) {
			$wpdb->insert( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $inst, 'user_id' => $user, 'role' => $role, 'status' => 'active' ) );
			update_user_meta( $user, '_atora_active_institution', $inst );
		}
		\ATORA\LMS\Tenant_Context::reset_cache();
		wp_set_current_user( $this->teacher );
		$this->assertGreaterThan( 0, ATORA_Delegation_Service::grant( $this->teacher, $this->assistant, array( 'scope' => 'course', 'wp_course_id' => $this->wp_course, 'perms' => array( 'content' => true ) ) ) );
		wp_set_current_user( 0 );
		\ATORA\LMS\Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();

		foreach ( array( $this->teacher, $this->assistant, $this->stranger, $this->student ) as $user ) {
			$this->tokens[ $user ] = ATORA_Mobile_Token_Service::issue( $user, 'test' )['access_token'];
		}
	}

	protected function tearDown(): void {
		// Las tablas propias sobreviven al rollback (force_install hace DDL): se deja todo como estaba.
		global $wpdb;
		$inst = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}atora_institutions WHERE slug = 'docente-inst'" );
		if ( $inst ) {
			$wpdb->delete( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $inst ) );
			$wpdb->delete( $wpdb->prefix . 'atora_institutions', array( 'id' => $inst ) );
		}
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_instructor_delegations WHERE assistant_id = " . (int) $this->assistant ); // phpcs:ignore WordPress.DB
		parent::tearDown();
	}

	private function call( int $user, string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/atora-mobile/v1' . $route );
		$request->set_header( 'Authorization', 'Bearer ' . $this->tokens[ $user ] );
		if ( $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		// Cada petición real empieza sin cachés: aquí se limpian las de alcance e institución.
		wp_set_current_user( 0 );
		\ATORA\LMS\Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	private function table_lesson( int $wp ): int {
		return (int) LMS_Course_Service::get_lesson_by_wp_post( $wp )['id'];
	}

	public function test_teacher_lists_all_lessons_in_order_with_details(): void {
		$response = $this->call( $this->teacher, 'GET', '/teacher/courses/' . $this->course . '/lessons' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( 'Introducción', 'Ensayo', 'Borrador' ), array_column( $data['items'], 'title' ), 'En orden y con la oculta.' );
		$this->assertSame( array( true, true, false ), array_column( $data['items'], 'visible' ) );
		$this->assertSame( 'tarea', $data['items'][1]['type'] );
		$expected = ( new DateTimeImmutable( '2026-11-10 18:00', wp_timezone() ) )->getTimestamp();
		$this->assertSame( gmdate( 'c', $expected ), $data['items'][1]['due_at'], 'Vencimiento con la zona del sitio.' );
		$this->assertSame( array( '2026-11-10', '18:00' ), array( $data['items'][1]['due_date'], $data['items'][1]['due_time'] ), 'Para editar: fecha y hora del sitio.' );
		$this->assertNull( $data['items'][0]['due_date'] );
		$this->assertTrue( $data['can_manage'] );

		$this->assertSame( 404, $this->call( $this->stranger, 'GET', '/teacher/courses/' . $this->course . '/lessons' )->get_status(), 'Docente sin el curso.' );
		$this->assertSame( 403, $this->call( $this->student, 'GET', '/teacher/courses/' . $this->course . '/lessons' )->get_status(), 'Estudiante.' );
		$assistant = $this->call( $this->assistant, 'GET', '/teacher/courses/' . $this->course . '/lessons' );
		$this->assertSame( 200, $assistant->get_status(), wp_json_encode( $assistant->get_data() ) );
		$this->assertTrue( $assistant->get_data()['can_manage'], '6.41.0: el instructor asistente con delegación gestiona.' );
	}

	public function test_teacher_previews_lessons_including_hidden_student_does_not(): void {
		$hidden = $this->table_lesson( $this->wp_lessons[2] );
		$open   = $this->call( $this->teacher, 'GET', '/lessons/' . $hidden );
		$this->assertSame( 200, $open->get_status(), 'El docente abre la lección oculta.' );
		$this->assertTrue( $open->get_data()['lesson']['teacher_preview'] );
		$this->assertSame( 404, $this->call( $this->student, 'GET', '/lessons/' . $hidden )->get_status(), 'El estudiante no ve la oculta.' );
		$this->assertSame( 403, $this->call( $this->stranger, 'GET', '/lessons/' . $this->table_lesson( $this->wp_lessons[0] ) )->get_status(), 'Otro docente sin matrícula ni curso.' );

		$student = $this->call( $this->student, 'GET', '/lessons/' . $this->table_lesson( $this->wp_lessons[0] ) );
		$this->assertSame( 200, $student->get_status() );
		$this->assertFalse( $student->get_data()['lesson']['teacher_preview'] );
	}

	public function test_publish_hide_and_change_due_date_idempotent_with_notice(): void {
		$draft = $this->table_lesson( $this->wp_lessons[2] );
		$show  = $this->call( $this->teacher, 'POST', '/teacher/lessons/' . $draft, array( 'visible' => true, 'client_event_id' => 'ev-1' ) );
		$this->assertSame( 200, $show->get_status() );
		$this->assertSame( 'publish', get_post_status( $this->wp_lessons[2] ) );
		$this->assertSame( 200, $this->call( $this->student, 'GET', '/lessons/' . $draft )->get_status(), 'Publicada: el estudiante la ve.' );

		$task = $this->table_lesson( $this->wp_lessons[1] );
		$body = array( 'due_date' => '2026-11-20', 'due_time' => '20:30', 'notify' => true, 'client_event_id' => 'ev-2' );
		$first = $this->call( $this->teacher, 'POST', '/teacher/lessons/' . $task, $body );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( array( '2026-11-20', '20:30' ), array( get_post_meta( $this->wp_lessons[1], '_clms_due_date', true ), get_post_meta( $this->wp_lessons[1], '_clms_due_time', true ) ) );
		$this->assertSame( '2026-11-20', get_post_meta( $this->wp_lessons[1], 'lm_due_date', true ), 'También la clave que lee el editor.' );

		$again = $this->call( $this->teacher, 'POST', '/teacher/lessons/' . $task, $body );
		$this->assertTrue( $again->get_data()['replayed'], 'El mismo evento no se aplica dos veces.' );

		$notices = array_filter( ATORA_Inbox_Store::received( $this->student, array( 'system_only' => true ) ), static fn( $r ) => false !== strpos( (string) $r['title'], 'Nueva fecha de entrega' ) );
		$this->assertCount( 1, $notices, 'El estudiante recibe un solo aviso del cambio de fecha.' );

		$hide = $this->call( $this->teacher, 'POST', '/teacher/lessons/' . $task, array( 'visible' => false, 'client_event_id' => 'ev-3' ) );
		$this->assertSame( 200, $hide->get_status() );
		$this->assertFalse( $hide->get_data()['lesson']['visible'] );
		$this->assertSame( 404, $this->call( $this->student, 'GET', '/lessons/' . $task )->get_status(), 'Oculta: el estudiante ya no la ve.' );
	}

	public function test_assistant_manages_and_invalid_input_is_rejected(): void {
		$task = $this->table_lesson( $this->wp_lessons[1] );
		$this->assertSame( 200, $this->call( $this->assistant, 'POST', '/teacher/lessons/' . $task, array( 'due_date' => '2026-12-01', 'client_event_id' => 'a-2' ) )->get_status() );
		$this->assertSame( '2026-12-01', get_post_meta( $this->wp_lessons[1], '_clms_due_date', true ), '6.41.0: el asistente cambia la fecha.' );

		$this->assertSame( 400, $this->call( $this->teacher, 'POST', '/teacher/lessons/' . $task, array( 'due_date' => '2026-02-30', 'client_event_id' => 'b-1' ) )->get_status() );
		$this->assertSame( 400, $this->call( $this->teacher, 'POST', '/teacher/lessons/' . $task, array( 'visible' => true ) )->get_status(), 'Sin client_event_id.' );
		$this->assertSame( 404, $this->call( $this->stranger, 'POST', '/teacher/lessons/' . $task, array( 'visible' => false, 'client_event_id' => 'c-1' ) )->get_status() );
	}
}
