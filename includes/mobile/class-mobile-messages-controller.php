<?php
/**
 * API móvil — Mensajes (6.30.0). Buzón propio: hilos, historial, responder.
 *
 * Reglas de quién escribe a quién:
 * - Personal → estudiante: las de CLMS_Messaging::current_user_can_message_student(), sin cambios.
 * - Estudiante: responde en los hilos donde participa y puede escribir a sus
 *   docentes de curso (autor del curso o `_clms_course_teacher_ids`).
 * - No hay mensajes entre estudiantes. "Avisos" no se responde.
 * Un hilo ajeno responde 404; un destinatario no permitido, 403.
 * Envío idempotente por `client_event_id` y con límite de frecuencia.
 *
 * @package ATORA_LMS
 * @since 6.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Mobile_Messages_Controller {

	const SEND_LIMIT  = 20;
	const SEND_WINDOW = 60;
	const MAX_BODY    = 4000;

	public static function register_routes(): void {
		$ns   = ATORA_Mobile_REST_Controller::REST_NAMESPACE;
		$auth = array( 'ATORA_Mobile_REST_Controller', 'authorize' );
		register_rest_route( $ns, '/messages/threads', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'threads' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/messages/threads/(?P<thread_id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'thread' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/messages/threads/(?P<thread_id>\d+)/read', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'read' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/messages', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'send' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/messages/unread-count', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'unread_count' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/messages/recipients', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'recipients' ),
			'permission_callback' => $auth,
		) );
	}

	private static function unavailable(): WP_Error {
		return new WP_Error( 'atora_mobile_inbox_unavailable', __( 'El buzón no está disponible.', 'atora-lms' ), array( 'status' => 503 ) );
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'atora_mobile_thread_not_found', __( 'Conversación no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
	}

	private static function iso( ?string $gmt ): ?string {
		return $gmt && '0000-00-00 00:00:00' !== $gmt ? str_replace( ' ', 'T', $gmt ) . 'Z' : null;
	}

	private static function user_name( int $user_id ): string {
		$user = $user_id > 0 ? get_user_by( 'id', $user_id ) : null;
		return $user ? ( $user->display_name ?: $user->user_login ) : '';
	}

	/** Enlace interno de un mensaje: a la lección, tarea o quiz (ids de la API), o null. */
	public static function link_for( array $row ): ?array {
		$lesson = absint( $row['lesson_id'] ?? 0 );
		if ( $lesson > 0 ) {
			$link = ATORA_Mobile_REST_Controller::lesson_link( $lesson );
			if ( $link ) {
				return $link;
			}
		}
		$course = ATORA_Mobile_REST_Controller::table_course_id( absint( $row['course_id'] ?? 0 ) );
		return $course > 0 ? array( 'type' => 'course', 'id' => $course, 'course_id' => $course ) : null;
	}

	public static function shape_message( array $row, int $user_id, ?array $thread = null ): array {
		$thread  = $thread ?: ATORA_Inbox_Store::thread( (int) $row['thread_id'] );
		$author  = (int) $row['author_id'];
		$system  = $thread && 'system' === $thread['type'];
		$read    = $system ? null !== $row['read_at'] : ( $author === $user_id || (int) $row['id'] <= (int) ( $row['last_read_message_id'] ?? PHP_INT_MAX ) );
		return array(
			'id'              => (int) $row['id'],
			'thread_id'       => (int) $row['thread_id'],
			'kind'            => (string) $row['kind'],
			'author'          => $author > 0 ? array( 'id' => $author, 'name' => self::user_name( $author ) ) : null,
			'mine'            => $author === $user_id,
			'title'           => (string) $row['title'],
			'body'            => (string) $row['body'],
			'link'            => self::link_for( $row ),
			'client_event_id' => null !== $row['client_event_id'] ? (string) $row['client_event_id'] : null,
			'created_at'      => self::iso( (string) $row['created_at'] ),
			'read'            => (bool) $read,
		);
	}

	private static function shape_thread( array $thread, int $user_id ): array {
		$last = (int) $thread['last_message_id'] > 0 ? ATORA_Inbox_Store::message( (int) $thread['last_message_id'] ) : null;
		return array(
			'id'              => (int) $thread['id'],
			'type'            => (string) $thread['type'],
			'title'           => CLMS_Messaging::thread_label( $thread, $user_id ),
			'course_id'       => ATORA_Mobile_REST_Controller::table_course_id( (int) $thread['course_id'] ),
			'unread'          => (int) ( $thread['unread'] ?? 0 ),
			'muted'           => ! empty( $thread['muted'] ),
			'can_reply'       => 'system' !== $thread['type'],
			'last_message_at' => self::iso( $thread['last_message_at'] ?? null ),
			'last_message'    => $last ? array(
				'id'      => (int) $last['id'],
				'kind'    => (string) $last['kind'],
				'preview' => wp_html_excerpt( '' !== (string) $last['body'] ? (string) $last['body'] : (string) $last['title'], 140, '…' ),
				'mine'    => (int) $last['author_id'] === $user_id,
			) : null,
		);
	}

	/** GET /messages/threads?cursor= — "Avisos" va aparte (la app lo fija arriba). */
	public static function threads( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! class_exists( 'ATORA_Inbox_Store' ) || ! ATORA_Inbox_Store::tables_ready() ) {
			return self::unavailable();
		}
		$system_id = ATORA_Inbox_Store::system_thread_id( $user_id );
		if ( is_wp_error( $system_id ) ) {
			return $system_id;
		}
		$page   = ATORA_Inbox_Store::threads_for_user( $user_id, sanitize_text_field( (string) $request->get_param( 'cursor' ) ), 20 );
		$items  = array();
		$avisos = null;
		foreach ( $page['items'] as $thread ) {
			if ( 'system' === $thread['type'] ) {
				$avisos = self::shape_thread( $thread, $user_id );
				continue;
			}
			$items[] = self::shape_thread( $thread, $user_id );
		}
		if ( null === $avisos ) {
			$thread           = (array) ATORA_Inbox_Store::thread( (int) $system_id );
			$thread['unread'] = count( ATORA_Inbox_Store::received( $user_id, array( 'system_only' => true, 'unread_only' => true ) ) );
			$avisos           = self::shape_thread( $thread, $user_id );
		}
		return new WP_REST_Response( array(
			'avisos'      => $avisos,
			'threads'     => $items,
			'next_cursor' => $page['next_cursor'] ?: null,
			'unread'      => ATORA_Inbox_Store::unread_count( $user_id ),
		), 200 );
	}

	/** GET /messages/threads/{id}?before= — historial hacia atrás, 30 por página. */
	public static function thread( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$thread_id = absint( $request['thread_id'] );
		if ( ! class_exists( 'ATORA_Inbox_Store' ) ) {
			return self::unavailable();
		}
		$thread = ATORA_Inbox_Store::thread( $thread_id );
		if ( ! $thread || ! ATORA_Inbox_Store::is_participant( $thread_id, $user_id ) ) {
			return self::not_found();
		}
		$before   = absint( $request->get_param( 'before' ) );
		$rows     = ATORA_Inbox_Store::thread_messages( $thread_id, $before, 30 );
		$pointer  = self::last_read( $thread_id, $user_id );
		$messages = array();
		foreach ( $rows as $row ) {
			$row['last_read_message_id'] = $pointer;
			$messages[]                  = self::shape_message( $row, $user_id, $thread );
		}
		$thread['unread'] = 0;
		$thread['muted']  = 0;
		return new WP_REST_Response( array(
			'thread'      => self::shape_thread( $thread, $user_id ),
			'messages'    => $messages,
			'next_before' => count( $rows ) === 30 ? (int) end( $rows )['id'] : null,
		), 200 );
	}

	private static function last_read( int $thread_id, int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT last_read_message_id FROM {$wpdb->prefix}atora_message_participants WHERE thread_id = %d AND user_id = %d", $thread_id, $user_id ) ); // phpcs:ignore WordPress.DB
	}

	/** POST /messages/threads/{id}/read  { upto? } */
	public static function read( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$thread_id = absint( $request['thread_id'] );
		if ( ! class_exists( 'ATORA_Inbox_Store' ) ) {
			return self::unavailable();
		}
		if ( ! ATORA_Inbox_Store::thread( $thread_id ) || ! ATORA_Inbox_Store::is_participant( $thread_id, $user_id ) ) {
			return self::not_found();
		}
		$params = (array) $request->get_json_params();
		ATORA_Inbox_Store::mark_thread_read( $thread_id, $user_id, absint( $params['upto'] ?? $request->get_param( 'upto' ) ) );
		return new WP_REST_Response( array( 'unread' => ATORA_Inbox_Store::unread_count( $user_id ) ), 200 );
	}

	public static function unread_count(): WP_REST_Response {
		return new WP_REST_Response( array( 'unread' => class_exists( 'ATORA_Inbox_Store' ) ? ATORA_Inbox_Store::unread_count( get_current_user_id() ) : 0 ), 200 );
	}

	/** Es personal (puede escribir a estudiantes). */
	public static function is_staff( int $user_id ): bool {
		return user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'clms_view_teacher_dashboard' ) || user_can( $user_id, 'clms_grade_submissions' );
	}

	/** Docentes de los cursos del estudiante: user_id => [wp_course_id...]. */
	public static function teachers_of_student( int $student_id ): array {
		$out = array();
		$courses = class_exists( 'CLMS_Helper' ) ? array_filter( array_map( 'absint', (array) CLMS_Helper::get_user_enrolled_courses( $student_id ) ) ) : array();
		foreach ( $courses as $wp_course ) {
			$ids = get_post_meta( $wp_course, '_clms_course_teacher_ids', true );
			$ids = is_string( $ids ) ? preg_split( '/\s*,\s*/', trim( $ids ) ) : (array) $ids;
			$ids[] = (int) get_post_field( 'post_author', $wp_course );
			foreach ( array_filter( array_map( 'absint', $ids ) ) as $teacher ) {
				if ( $teacher !== $student_id && self::is_staff( $teacher ) ) {
					$out[ $teacher ][] = $wp_course;
				}
			}
		}
		return $out;
	}

	/** GET /messages/recipients — a quién puede escribir el usuario (estudiante: sus docentes). */
	public static function recipients(): WP_REST_Response {
		$user_id = get_current_user_id();
		$items   = array();
		foreach ( self::teachers_of_student( $user_id ) as $teacher => $courses ) {
			$courses = array_values( array_unique( $courses ) );
			$items[] = array(
				'id'      => $teacher,
				'name'    => self::user_name( $teacher ),
				'courses' => array_values( array_filter( array_map(
					static function ( $wp_course ) {
						$id = ATORA_Mobile_REST_Controller::table_course_id( $wp_course );
						return $id ? array( 'id' => $id, 'title' => get_the_title( $wp_course ) ) : null;
					},
					$courses
				) ) ),
			);
		}
		return new WP_REST_Response( array( 'recipients' => $items ), 200 );
	}

	/**
	 * POST /messages  { thread_id? | recipient_id + course_id?, body, client_event_id }
	 */
	public static function send( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! class_exists( 'ATORA_Inbox_Store' ) || ! ATORA_Inbox_Store::tables_ready() ) {
			return self::unavailable();
		}
		$params = (array) $request->get_json_params();
		$body   = trim( sanitize_textarea_field( (string) ( $params['body'] ?? '' ) ) );
		$event  = sanitize_text_field( (string) ( $params['client_event_id'] ?? '' ) );
		if ( '' === $body ) {
			return new WP_Error( 'atora_mobile_message_empty', __( 'El mensaje no puede estar vacío.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		if ( '' === $event || strlen( $event ) > 64 ) {
			return new WP_Error( 'atora_mobile_client_event_required', __( 'Falta client_event_id.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $body ) > self::MAX_BODY : strlen( $body ) > self::MAX_BODY ) {
			return new WP_Error( 'atora_mobile_message_too_long', __( 'El mensaje es demasiado largo.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		// Reenvío del mismo evento: responde lo ya creado, sin pasar por el límite.
		global $wpdb;
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_messages WHERE author_id = %d AND client_event_id = %s", $user_id, $event ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $existing ) {
			return new WP_REST_Response( array( 'message' => self::shape_message( $existing, $user_id ), 'replayed' => true ), 200 );
		}

		if ( class_exists( 'ATORA_Rate_Limiter' ) && ! ATORA_Rate_Limiter::consume( 'mobile_messages', (string) $user_id, self::SEND_LIMIT, self::SEND_WINDOW, true ) ) {
			return new WP_Error( 'atora_mobile_rate_limited', __( 'Estás enviando muchos mensajes. Espera un momento.', 'atora-lms' ), array( 'status' => 429 ) );
		}

		$thread_id = absint( $params['thread_id'] ?? 0 );
		if ( $thread_id > 0 ) {
			$thread = ATORA_Inbox_Store::thread( $thread_id );
			if ( ! $thread || ! ATORA_Inbox_Store::is_participant( $thread_id, $user_id ) ) {
				return self::not_found();
			}
			if ( 'system' === $thread['type'] ) {
				return new WP_Error( 'atora_mobile_message_forbidden', __( 'Los avisos no se responden.', 'atora-lms' ), array( 'status' => 403 ) );
			}
			$course_wp = (int) $thread['course_id'];
		} else {
			$recipient = absint( $params['recipient_id'] ?? 0 );
			$course    = absint( $params['course_id'] ?? 0 );
			$course_wp = 0;
			if ( $course > 0 && class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) ) {
				$row       = \ATORA\LMS\LMS_Course_Service::get( $course );
				$course_wp = $row ? absint( $row['wp_post_id'] ?? 0 ) : 0;
			}
			$allowed = self::can_start( $user_id, $recipient, $course_wp );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
			$course_wp = $allowed;
			$staff     = self::is_staff( $user_id );
			$thread_id = ATORA_Inbox_Store::direct_thread_id(
				$user_id,
				$recipient,
				$course_wp,
				$course_wp ? get_the_title( $course_wp ) : '',
				array( $user_id => $staff ? 'teacher' : 'student', $recipient => $staff ? 'student' : 'teacher' )
			);
			if ( is_wp_error( $thread_id ) ) {
				return $thread_id;
			}
		}

		$result = ATORA_Inbox_Store::add_message(
			(int) $thread_id,
			$user_id,
			array(
				'kind'            => 'message',
				'body'            => $body,
				'course_id'       => $course_wp,
				'client_event_id' => $event,
				'meta'            => array( 'sender_type' => self::is_staff( $user_id ) ? 'teacher' : 'student', 'message_type' => 'manual', 'source' => 'mobile' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( array( 'message' => self::shape_message( $result['message'], $user_id ), 'replayed' => ! $result['created'] ), $result['created'] ? 201 : 200 );
	}

	/**
	 * ¿Puede el usuario abrir una conversación con el destinatario? Devuelve el
	 * curso (post) de la conversación o WP_Error 403.
	 *
	 * @return int|WP_Error
	 */
	private static function can_start( int $user_id, int $recipient, int $course_wp ) {
		$forbidden = new WP_Error( 'atora_mobile_message_forbidden', __( 'No puedes escribir a esta persona.', 'atora-lms' ), array( 'status' => 403 ) );
		if ( $recipient <= 0 || $recipient === $user_id || ! get_user_by( 'id', $recipient ) ) {
			return $forbidden;
		}
		if ( self::is_staff( $user_id ) ) {
			$messaging = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Messaging' ) : null;
			return $messaging && method_exists( $messaging, 'can_message_student' ) && $messaging->can_message_student( $user_id, $recipient, $course_wp ) ? $course_wp : $forbidden;
		}
		// Estudiante: solo a sus docentes de curso; nunca a otro estudiante.
		$teachers = self::teachers_of_student( $user_id );
		if ( ! isset( $teachers[ $recipient ] ) ) {
			return $forbidden;
		}
		if ( $course_wp > 0 ) {
			return in_array( $course_wp, $teachers[ $recipient ], true ) ? $course_wp : $forbidden;
		}
		return (int) $teachers[ $recipient ][0];
	}
}
