<?php
/**
 * Integración 6.30.0: mensajes, agenda, Hoy y notificaciones al teléfono (API móvil).
 */

declare( strict_types = 1 );

use ATORA\LMS\LMS_Course_Service;
use ATORA\LMS\LMS_Enrollment_Service;

final class MobileOrganizeTest extends WP_UnitTestCase {

	private int $teacher = 0;
	private int $student = 0;
	private int $other = 0;
	private int $wp_course = 0;
	private int $course = 0;
	private int $wp_task = 0;
	private int $wp_draft = 0;
	private int $wp_foreign_course = 0;
	private array $http = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		global $wpdb;
		foreach ( array( 'atora_messages', 'atora_message_participants', 'atora_message_threads', 'atora_mobile_push_tokens', 'atora_lessons', 'atora_courses', 'atora_enrollments', 'atora_lesson_progress' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		$this->teacher = self::factory()->user->create( array( 'role' => 'administrator', 'display_name' => 'Docente' ) );
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Estudiante' ) );
		$this->other   = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Otro' ) );

		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_title' => 'Curso agenda', 'post_author' => $this->teacher ) );
		$this->course    = absint( LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'] );
		$tomorrow        = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );
		$this->wp_task   = $this->lesson( $this->wp_course, 'Tarea mañana', 'publish', $tomorrow );
		$this->wp_draft  = $this->lesson( $this->wp_course, 'Tarea en borrador', 'draft', $tomorrow );
		$this->wp_foreign_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_title' => 'Curso ajeno' ) );
		LMS_Course_Service::get_by_wp_post( $this->wp_foreign_course );
		$this->lesson( $this->wp_foreign_course, 'Tarea de otro curso', 'publish', $tomorrow );

		foreach ( array( $this->student, $this->other ) as $user ) {
			LMS_Enrollment_Service::enroll( $user, $this->course );
			if ( method_exists( 'CLMS_Helper', 'enroll_user_in_course' ) ) {
				CLMS_Helper::enroll_user_in_course( $user, $this->wp_course );
			}
		}
		$this->http = array();
		add_filter( 'pre_http_request', array( $this, 'fake_expo' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_expo' ), 10 );
		parent::tearDown();
	}

	public function fake_expo( $pre, $args, $url ) {
		$this->http[] = array( 'url' => $url, 'body' => (string) ( $args['body'] ?? '' ) );
		$count   = count( (array) json_decode( (string) $args['body'], true ) );
		$tickets = array_fill( 0, $count, array( 'status' => 'error', 'details' => array( 'error' => 'DeviceNotRegistered' ) ) );
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'data' => $tickets ) ), 'headers' => array(), 'cookies' => array() );
	}

	private function lesson( int $wp_course, string $title, string $status, string $due ): int {
		$id = self::factory()->post->create( array(
			'post_type'   => 'lm_lesson',
			'post_status' => $status,
			'post_title'  => $title,
			'meta_input'  => array( '_clms_course_id' => $wp_course, 'lm_activity_type' => 'tarea', '_clms_due_date' => $due, '_clms_due_time' => '18:00' ),
		) );
		LMS_Course_Service::get_lesson_by_wp_post( $id );
		return $id;
	}

	private function call( int $user, string $class, string $method, array $url = array(), array $json = array(), array $query = array() ) {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( 'POST', '/atora-mobile/v1/x' );
		$request->set_url_params( $url );
		foreach ( $query as $key => $value ) {
			$request->set_param( $key, $value );
		}
		if ( $json ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $json ) );
		}
		$response = $class::$method( $request );
		return is_wp_error( $response ) ? $response : $response->get_data();
	}

	private function send( int $user, array $json ) {
		return $this->call( $user, 'ATORA_Mobile_Messages_Controller', 'send', array(), $json );
	}

	public function test_student_writes_to_teacher_idempotently(): void {
		$first  = $this->send( $this->student, array( 'recipient_id' => $this->teacher, 'course_id' => $this->course, 'body' => 'Tengo una duda', 'client_event_id' => 'evt-1' ) );
		$replay = $this->send( $this->student, array( 'recipient_id' => $this->teacher, 'course_id' => $this->course, 'body' => 'Tengo una duda', 'client_event_id' => 'evt-1' ) );
		$this->assertIsArray( $first );
		$this->assertSame( $first['message']['id'], $replay['message']['id'], 'Mismo client_event_id: un solo mensaje.' );
		$this->assertTrue( $replay['replayed'] );

		// El docente lo ve en la web (API pública de CLMS_Messaging) y responde en el mismo hilo.
		$web = clms_core( 'CLMS_Messaging' )->get_messages( $this->teacher );
		$this->assertSame( 'Tengo una duda', $web[0]['message'] );
		$reply = $this->send( $this->teacher, array( 'thread_id' => $first['message']['thread_id'], 'body' => 'Claro', 'client_event_id' => 'evt-t1' ) );
		$this->assertSame( $first['message']['thread_id'], $reply['message']['thread_id'] );

		$thread = $this->call( $this->student, 'ATORA_Mobile_Messages_Controller', 'thread', array( 'thread_id' => $first['message']['thread_id'] ) );
		$this->assertSame( array( 'Claro', 'Tengo una duda' ), wp_list_pluck( $thread['messages'], 'body' ) );
	}

	public function test_foreign_thread_is_404_and_wrong_recipient_403(): void {
		$sent = $this->send( $this->student, array( 'recipient_id' => $this->teacher, 'body' => 'Hola', 'client_event_id' => 'evt-2' ) );
		$tid  = $sent['message']['thread_id'];

		$read = $this->call( $this->other, 'ATORA_Mobile_Messages_Controller', 'thread', array( 'thread_id' => $tid ) );
		$this->assertSame( 404, $read->get_error_data()['status'], 'Otro estudiante no lee un hilo ajeno.' );
		$write = $this->send( $this->other, array( 'thread_id' => $tid, 'body' => 'Intruso', 'client_event_id' => 'evt-3' ) );
		$this->assertSame( 404, $write->get_error_data()['status'] );

		$peer = $this->send( $this->student, array( 'recipient_id' => $this->other, 'body' => 'Entre estudiantes', 'client_event_id' => 'evt-4' ) );
		$this->assertSame( 403, $peer->get_error_data()['status'], 'No hay mensajes entre estudiantes.' );

		$avisos = ATORA_Inbox_Store::system_thread_id( $this->student );
		$reply  = $this->send( $this->student, array( 'thread_id' => $avisos, 'body' => 'x', 'client_event_id' => 'evt-5' ) );
		$this->assertSame( 403, $reply->get_error_data()['status'], 'Los avisos no se responden.' );
	}

	public function test_threads_list_pins_avisos_and_counts_once(): void {
		// La matrícula del setUp ya dejó su aviso de bienvenida.
		$base = count( ATORA_Inbox_Store::received( $this->student, array( 'system_only' => true, 'unread_only' => true ) ) );
		clms_core( 'CLMS_Notifications' )->add_notification( $this->student, array( 'type' => 'submission_graded', 'title' => 'Nota', 'lesson_id' => $this->wp_task ) );
		$this->send( $this->teacher, array( 'recipient_id' => $this->student, 'course_id' => $this->course, 'body' => 'Bien hecho', 'client_event_id' => 'evt-6' ) );
		$list = $this->call( $this->student, 'ATORA_Mobile_Messages_Controller', 'threads' );
		$this->assertSame( 'system', $list['avisos']['type'] );
		$this->assertSame( $base + 1, $list['avisos']['unread'] );
		$this->assertCount( 1, $list['threads'] );
		$this->assertSame( $base + 2, $list['unread'] );
		$this->assertSame( $base + 2, clms_core( 'CLMS_Notifications' )->get_unread_count( $this->student ), 'Web y app cuentan lo mismo.' );

		$thread = $this->call( $this->student, 'ATORA_Mobile_Messages_Controller', 'thread', array( 'thread_id' => $list['avisos']['id'] ) );
		$this->assertSame( 'submission_graded', $thread['messages'][0]['kind'] );
		$this->assertSame( array( 'type' => 'assignment', 'id' => absint( LMS_Course_Service::get_lesson_by_wp_post( $this->wp_task )['id'] ), 'course_id' => $this->course ), $thread['messages'][0]['link'] );
	}

	public function test_agenda_only_enrolled_courses_and_published_lessons(): void {
		$agenda = $this->call( $this->student, 'ATORA_Mobile_Organize_Controller', 'agenda', array(), array(), array( 'from' => wp_date( 'Y-m-d' ), 'to' => wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ) ) );
		$titles = wp_list_pluck( $agenda['items'], 'title' );
		$this->assertSame( array( 'Tarea mañana' ), $titles );
		$this->assertSame( 'assignment_due', $agenda['items'][0]['type'] );
		$this->assertMatchesRegularExpression( '/T18:00:00[+-]\d{2}:\d{2}$/', $agenda['items'][0]['starts_at'], 'ISO 8601 con desfase.' );
		$this->assertSame( 'assignment', $agenda['items'][0]['link']['type'] );

		$too_long = $this->call( $this->student, 'ATORA_Mobile_Organize_Controller', 'agenda', array(), array(), array( 'from' => '2026-01-01', 'to' => '2026-04-01' ) );
		$this->assertSame( 400, $too_long->get_error_data()['status'], 'Máximo 62 días.' );
	}

	public function test_student_today(): void {
		$today = $this->call( $this->student, 'ATORA_Mobile_Organize_Controller', 'today' );
		$this->assertSame( 'student', $today['role'] );
		$this->assertSame( 'Tarea mañana', $today['upcoming'][0]['title'] );
		$this->assertSame( $this->course, $today['continue']['course']['id'] );
		$this->assertArrayHasKey( 'unread_messages', $today );
		$this->assertArrayHasKey( 'new_grades', $today );
	}

	public function test_push_is_queued_not_sent_in_request_and_dead_token_is_deleted(): void {
		$device = $this->call( $this->teacher, 'ATORA_Mobile_Organize_Controller', 'register_device', array(), array( 'token' => 'ExponentPushToken[abc123]', 'platform' => 'android' ) );
		$this->assertGreaterThan( 0, $device['id'] );

		$sent = $this->send( $this->student, array( 'recipient_id' => $this->teacher, 'body' => 'Texto privado del mensaje', 'client_event_id' => 'evt-7' ) );
		$this->assertSame( array(), $this->http, 'La petición que crea el mensaje no llama a Expo.' );
		$args = array( $this->teacher, $sent['message']['id'], 0 );
		$this->assertNotFalse( wp_next_scheduled( ATORA_Mobile_Push_Service::SEND_HOOK, $args ), 'Queda en la cola.' );

		$result = ATORA_Mobile_Push_Service::send_job( ...$args );
		$this->assertSame( 'sent', $result );
		$this->assertCount( 1, $this->http );
		$this->assertStringNotContainsString( 'Texto privado', $this->http[0]['body'], 'Sin el cuerpo del mensaje.' );
		$this->assertStringContainsString( 'Nuevo mensaje', $this->http[0]['body'] );
		$this->assertSame( array(), ATORA_Mobile_Push_Service::tokens_for( $this->teacher ), 'DeviceNotRegistered borra el token.' );
	}

	/**
	 * 6.33.1 (E.6): el token de notificaciones queda atado a la sesión que lo
	 * registró. Al revocarla (cerrar sesión) se borra; a una sesión revocada o
	 * vencida no se le envía nada.
	 */
	public function test_push_tokens_follow_the_session(): void {
		global $wpdb;
		$s1 = ATORA_Mobile_Token_Service::issue( $this->teacher, 'teléfono 1' );
		$s2 = ATORA_Mobile_Token_Service::issue( $this->teacher, 'teléfono 2' );
		$this->call( $this->teacher, 'ATORA_Mobile_Organize_Controller', 'register_device', array(), array( 'token' => 'ExponentPushToken[uno]', 'platform' => 'android' ), array( '_atora_mobile_session_id' => $s1['session_id'] ) );
		$this->call( $this->teacher, 'ATORA_Mobile_Organize_Controller', 'register_device', array(), array( 'token' => 'ExponentPushToken[dos]', 'platform' => 'ios' ), array( '_atora_mobile_session_id' => $s2['session_id'] ) );
		$this->assertCount( 2, ATORA_Mobile_Push_Service::tokens_for( $this->teacher ) );

		// Cerrar sesión en el teléfono 1 (también la revocación pendiente que llega al volver la red).
		$this->assertTrue( ATORA_Mobile_Token_Service::revoke_token( $s1['access_token'] ) );
		$this->assertSame( array( 'ExponentPushToken[dos]' ), wp_list_pluck( ATORA_Mobile_Push_Service::tokens_for( $this->teacher ), 'token' ) );
		$this->assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_mobile_push_tokens WHERE token = 'ExponentPushToken[uno]'" ), 'El token del teléfono 1 ya no está en el servidor.' );

		// La sesión del teléfono 2 vence: no se le envía y su token se borra.
		$wpdb->update( $wpdb->prefix . 'atora_mobile_sessions', array( 'refresh_expires' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'session_id' => $s2['session_id'] ) );
		$sent = $this->send( $this->student, array( 'recipient_id' => $this->teacher, 'body' => 'Hola', 'client_event_id' => 'evt-e6' ) );
		$this->assertSame( 'skipped', ATORA_Mobile_Push_Service::send_job( $this->teacher, (int) $sent['message']['id'], 0 ) );
		$this->assertSame( array(), $this->http, 'Nada sale hacia Expo.' );
		$this->assertSame( array(), ATORA_Mobile_Push_Service::tokens_for( $this->teacher ) );

		// El mismo teléfono inicia otra sesión: el token pasa a la nueva.
		$s3 = ATORA_Mobile_Token_Service::issue( $this->teacher, 'teléfono 1' );
		$this->call( $this->teacher, 'ATORA_Mobile_Organize_Controller', 'register_device', array(), array( 'token' => 'ExponentPushToken[uno]', 'platform' => 'android' ), array( '_atora_mobile_session_id' => $s1['session_id'] ) );
		$this->call( $this->teacher, 'ATORA_Mobile_Organize_Controller', 'register_device', array(), array( 'token' => 'ExponentPushToken[uno]', 'platform' => 'android' ), array( '_atora_mobile_session_id' => $s3['session_id'] ) );
		$this->assertSame( $s3['session_id'], $wpdb->get_var( "SELECT session_id FROM {$wpdb->prefix}atora_mobile_push_tokens WHERE token = 'ExponentPushToken[uno]'" ) );
		$this->assertSame( array( 'ExponentPushToken[uno]' ), wp_list_pluck( ATORA_Mobile_Push_Service::tokens_for( $this->teacher ), 'token' ) );
	}

	/**
	 * 6.33.1 (E.6): cerrar sesión en modo avión y reconectar cuando el token de
	 * acceso (15 min) ya venció: la app revoca con el token de renovación y el
	 * servidor borra el token de notificaciones.
	 */
	public function test_pending_logout_with_refresh_token_after_access_expired(): void {
		global $wpdb;
		do_action( 'rest_api_init', rest_get_server() );
		$session = ATORA_Mobile_Token_Service::issue( $this->teacher, 'teléfono' );
		$this->call( $this->teacher, 'ATORA_Mobile_Organize_Controller', 'register_device', array(), array( 'token' => 'ExponentPushToken[avion]', 'platform' => 'android' ), array( '_atora_mobile_session_id' => $session['session_id'] ) );
		$wpdb->update( $wpdb->prefix . 'atora_mobile_sessions', array( 'access_expires' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'session_id' => $session['session_id'] ) );
		wp_set_current_user( 0 );

		$logout = static function ( array $headers, array $body ) {
			$request = new WP_REST_Request( 'POST', '/atora-mobile/v1/auth/logout' );
			foreach ( $headers as $key => $value ) {
				$request->set_header( $key, $value );
			}
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
			return rest_get_server()->dispatch( $request );
		};
		$this->assertSame( 401, $logout( array( 'Authorization' => 'Bearer ' . $session['access_token'] ), array() )->get_status(), 'El acceso vencido no sirve.' );
		$this->assertSame( 401, $logout( array(), array( 'refresh_token' => 'basura' ) )->get_status() );
		$ok = $logout( array(), array( 'refresh_token' => $session['refresh_token'] ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertTrue( $ok->get_data()['revoked'] );
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( "SELECT revoked_at FROM {$wpdb->prefix}atora_mobile_sessions WHERE session_id = %s", $session['session_id'] ) ) );
		$this->assertSame( array(), ATORA_Mobile_Push_Service::tokens_for( $this->teacher ), 'El servidor ya no tiene el token.' );
		$this->assertTrue( is_wp_error( ATORA_Mobile_Token_Service::validate( $session['refresh_token'], 'refresh' ) ), 'Ya no se puede renovar.' );
	}

	public function test_preferences_by_type_silence_pushes(): void {
		$this->call( $this->student, 'ATORA_Mobile_Organize_Controller', 'register_device', array(), array( 'token' => 'ExponentPushToken[st1]', 'platform' => 'android' ) );
		$this->call( $this->student, 'ATORA_Mobile_Organize_Controller', 'put_preferences', array(), array( 'preferences' => array( 'grades' => false ) ) );
		clms_core( 'CLMS_Notifications' )->add_notification( $this->student, array( 'type' => 'submission_graded', 'title' => 'Nota' ) );
		$notice = ATORA_Inbox_Store::received( $this->student, array( 'system_only' => true ) )[0];
		$this->assertSame( 'skipped', ATORA_Mobile_Push_Service::send_job( $this->student, (int) $notice['id'], 0 ) );
		$this->assertSame( array(), $this->http );
	}

	public function test_deadline_reminder_once_per_lesson(): void {
		$soon = time() + 2 * HOUR_IN_SECONDS;
		$this->lesson( $this->wp_course, 'Tarea en dos horas', 'publish', wp_date( 'Y-m-d', $soon ) );
		$id = self::factory()->post->create( array(
			'post_type'   => 'lm_lesson',
			'post_status' => 'publish',
			'post_title'  => 'Quiz pronto',
			'meta_input'  => array( '_clms_course_id' => $this->wp_course, 'lm_activity_type' => 'tarea', '_clms_due_date' => wp_date( 'Y-m-d', $soon ), '_clms_due_time' => wp_date( 'H:i', $soon ) ),
		) );
		LMS_Course_Service::get_lesson_by_wp_post( $id );

		$first  = CLMS_Agenda_Service::send_deadline_reminders();
		$second = CLMS_Agenda_Service::send_deadline_reminders();
		$this->assertGreaterThanOrEqual( 1, $first );
		$this->assertSame( 0, $second, 'Un recordatorio por lección: no se repite.' );
		$kinds = wp_list_pluck( ATORA_Inbox_Store::received( $this->student, array( 'system_only' => true ) ), 'kind' );
		$this->assertContains( 'deadline_reminder', $kinds );
		$this->assertSame( 'deadlines', ATORA_Mobile_Push_Service::category_for( 'system', 'deadline_reminder' ) );
	}

	public function test_new_routes_are_not_cacheable(): void {
		do_action( 'rest_api_init', rest_get_server() );
		foreach ( array( '/atora-mobile/v1/messages/threads', '/atora-mobile/v1/messages/unread-count', '/atora-mobile/v1/agenda', '/atora-mobile/v1/today', '/atora-mobile/v1/notification-preferences' ) as $route ) {
			$request  = new WP_REST_Request( 'GET', $route );
			$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_get_server()->dispatch( $request ) ), rest_get_server(), $request );
			$this->assertSame( 'no-cache', $response->get_headers()['X-LiteSpeed-Cache-Control'] ?? null, $route );
			$this->assertSame( 401, $response->get_status(), $route . ' exige sesión móvil.' );
		}
		$this->assertTrue( ATORA_Mobile_REST_Controller::discovery()->get_data()['capabilities']['messages'] );
	}
}
