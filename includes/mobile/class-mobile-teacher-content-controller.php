<?php
/**
 * App del docente — contenido del curso (6.40.0).
 *
 * - `GET  /teacher/courses/{id}/lessons`: lecciones en orden (también las ocultas) con tipo, estado, fecha de
 *   entrega, entregas por calificar, clase en vivo y si es encuentro presencial.
 * - `POST /teacher/lessons/{id}`: publicar u ocultar una lección y cambiar la fecha de entrega de una tarea, con
 *   aviso opcional al curso. Idempotente por `client_event_id`.
 *
 * Permisos: el curso debe estar en el alcance del docente (`ATORA_Teacher_Scope`) y poder editar la lección. El
 * asistente con delegación ve todo, pero no publica, no oculta ni cambia fechas de evaluación (misma regla que la
 * planificación híbrida).
 *
 * @package ATORA_LMS
 * @since 6.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Mobile_Teacher_Content_Controller {

	const STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future' );

	public static function register_routes(): void {
		$ns   = ATORA_Mobile_REST_Controller::REST_NAMESPACE;
		$auth = array( 'ATORA_Mobile_Teacher_Controller', 'authorize' );
		register_rest_route( $ns, '/teacher/courses/(?P<course_id>\d+)/lessons', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'lessons' ),
			'permission_callback' => $auth,
		) );
		// 6.40.0: asistencia presencial.
		register_rest_route( $ns, '/teacher/courses/(?P<course_id>\d+)/attendance', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'attendance' ), 'permission_callback' => $auth ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'record_attendance' ), 'permission_callback' => $auth ),
		) );
		// 6.40.0: clase en vivo del docente — grabación y asistencia del proveedor.
		register_rest_route( $ns, '/teacher/lessons/(?P<lesson_id>\d+)/recording', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'recording' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/teacher/lessons/(?P<lesson_id>\d+)/live-attendance', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'live_attendance' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/teacher/lessons/(?P<lesson_id>\d+)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'update_lesson' ),
			'permission_callback' => $auth,
		) );
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'atora_mobile_not_found', __( 'No encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
	}

	/** Curso de tabla → post, solo si está en el alcance del docente. */
	public static function course_for_teacher( int $user_id, int $table_course_id ): int {
		$course = $table_course_id > 0 ? \ATORA\LMS\LMS_Course_Service::get( $table_course_id ) : null;
		$wp     = $course ? absint( $course['wp_post_id'] ?? 0 ) : 0;
		return $wp > 0 && ATORA_Teacher_Scope::can_access_course( $user_id, $wp ) ? $wp : 0;
	}

	/** Lecciones del curso en cualquier estado editable, en el orden del editor. @return int[] */
	public static function lesson_post_ids( int $wp_course ): array {
		$ids = get_posts( array(
			'post_type'        => 'lm_lesson',
			'post_status'      => self::STATUSES,
			'posts_per_page'   => 500,
			'fields'           => 'ids',
			'orderby'          => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
			'meta_key'         => '_clms_course_id', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'       => $wp_course, // phpcs:ignore WordPress.DB.SlowDBQuery
			'suppress_filters' => true,
		) );
		return array_map( 'absint', (array) $ids );
	}

	/** Vencimiento de una tarea en ISO UTC (fecha y hora locales del sitio). */
	public static function due_iso( int $wp_lesson ): ?string {
		$date = trim( (string) get_post_meta( $wp_lesson, '_clms_due_date', true ) );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}
		$time = trim( (string) get_post_meta( $wp_lesson, '_clms_due_time', true ) );
		$time = 1 === preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time : '23:59';
		try {
			$at = new DateTimeImmutable( $date . ' ' . $time, wp_timezone() );
		} catch ( \Exception $e ) {
			return null;
		}
		return gmdate( 'c', $at->getTimestamp() );
	}

	public static function activity_type( int $wp_lesson ): string {
		$type = sanitize_key( (string) get_post_meta( $wp_lesson, 'lm_activity_type', true ) );
		if ( '' === $type ) {
			$type = sanitize_key( (string) get_post_meta( $wp_lesson, '_clms_activity_mode', true ) );
		}
		return in_array( $type, array( 'tarea', 'quiz', 'lectura', 'video', 'proyecto', 'foro' ), true ) ? $type : ( '' !== $type ? $type : 'lectura' );
	}

	private static function is_evaluation( int $wp_lesson ): bool {
		return in_array( self::activity_type( $wp_lesson ), array( 'tarea', 'proyecto', 'quiz' ), true ) || '' !== (string) get_post_meta( $wp_lesson, '_clms_due_date', true );
	}

	/** Entregas por calificar de una lección (enviadas o en revisión). */
	private static function pending_count( int $wp_lesson ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} l ON l.post_id = p.ID AND l.meta_key = '_clms_submission_lesson_id' AND l.meta_value = %s
			 JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_clms_submission_status' AND s.meta_value IN ('submitted','in_review')
			 LEFT JOIN {$wpdb->postmeta} sh ON sh.post_id = p.ID AND sh.meta_key = '_clms_submission_is_shadow'
			 WHERE p.post_type = 'clms_submission' AND p.post_status IN ('publish','private') AND sh.post_id IS NULL",
			(string) $wp_lesson
		) );
	}

	public static function lesson_row( int $user_id, int $wp_lesson ): array {
		$table   = \ATORA\LMS\LMS_Course_Service::get_lesson_by_wp_post( $wp_lesson );
		$status  = (string) get_post_status( $wp_lesson );
		$live    = class_exists( 'ATORA_Live_Session_Service' ) ? ATORA_Live_Session_Service::join_info( $wp_lesson, $user_id ) : null;
		return array(
			'id'                  => $table ? (int) $table['id'] : 0,
			'wp_id'               => $wp_lesson,
			'title'               => sanitize_text_field( (string) get_the_title( $wp_lesson ) ),
			'order'               => (int) get_post_field( 'menu_order', $wp_lesson ),
			'type'                => self::activity_type( $wp_lesson ),
			'visible'             => in_array( $status, array( 'publish', 'private' ), true ),
			'status'              => $status,
			'due_at'              => self::due_iso( $wp_lesson ),
			// Fecha y hora tal como las guarda el sitio (zona del sitio), para editarlas.
			'due_date'            => self::due_iso( $wp_lesson ) ? (string) get_post_meta( $wp_lesson, '_clms_due_date', true ) : null,
			'due_time'            => self::due_iso( $wp_lesson ) ? ( 1 === preg_match( '/^\d{2}:\d{2}$/', (string) get_post_meta( $wp_lesson, '_clms_due_time', true ) ) ? (string) get_post_meta( $wp_lesson, '_clms_due_time', true ) : '23:59' ) : null,
			'site_timezone'       => wp_timezone_string(),
			'pending_submissions' => self::pending_count( $wp_lesson ),
			'in_person'           => '1' === (string) get_post_meta( $wp_lesson, '_atora_in_person', true ),
			'live'                => $live,
		);
	}

	// ── GET /teacher/courses/{id}/lessons ──────────────────────────────────

	public static function lessons( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$wp      = self::course_for_teacher( $user_id, absint( $request['course_id'] ) );
		if ( ! $wp ) {
			return self::not_found();
		}
		$items = array();
		foreach ( self::lesson_post_ids( $wp ) as $wp_lesson ) {
			$items[] = self::lesson_row( $user_id, $wp_lesson );
		}
		return new WP_REST_Response( array(
			'course_id'   => absint( $request['course_id'] ),
			'items'       => $items,
			'can_manage'  => self::can_manage( $user_id, $wp ),
		), 200 );
	}

	/** Puede publicar, ocultar y cambiar fechas (no el asistente con delegación). */
	public static function can_manage( int $user_id, int $wp_course ): bool {
		if ( ! user_can( $user_id, 'edit_lm_lessons' ) || ! ATORA_Teacher_Scope::can_access_course( $user_id, $wp_course ) ) {
			return false;
		}
		return ! ( class_exists( 'ATORA_Hybrid_Plan_Service' ) && ATORA_Hybrid_Plan_Service::is_assistant_only( $user_id, $wp_course ) );
	}

	// ── POST /teacher/lessons/{id} ─────────────────────────────────────────

	public static function update_lesson( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$params  = (array) $request->get_json_params();
		$lesson  = \ATORA\LMS\LMS_Course_Service::get_lesson( absint( $request['lesson_id'] ) );
		$wp      = $lesson ? absint( $lesson['wp_post_id'] ?? 0 ) : 0;
		$course  = $wp ? absint( get_post_meta( $wp, '_clms_course_id', true ) ) : 0;
		if ( ! $wp || ! $course || ! ATORA_Teacher_Scope::can_access_course( $user_id, $course ) ) {
			return self::not_found();
		}
		if ( ! self::can_manage( $user_id, $course ) || ! user_can( $user_id, 'edit_post', $wp ) ) {
			return new WP_Error( 'atora_mobile_teacher_forbidden', __( 'Tu rol no permite cambiar esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$event = sanitize_text_field( (string) ( $params['client_event_id'] ?? '' ) );
		if ( '' === $event || strlen( $event ) > 64 ) {
			return new WP_Error( 'atora_mobile_client_event_required', __( 'Falta client_event_id.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$done_key = '_atora_lesson_change_' . md5( $user_id . '|' . $event );
		$done     = get_post_meta( $wp, $done_key, true );
		if ( is_array( $done ) ) {
			return new WP_REST_Response( array( 'lesson' => self::lesson_row( $user_id, $wp ), 'replayed' => true ), 200 );
		}

		$changes = array();

		if ( array_key_exists( 'visible', $params ) ) {
			$visible = (bool) $params['visible'];
			if ( $visible && ! user_can( $user_id, 'publish_lm_lessons' ) ) {
				return new WP_Error( 'atora_mobile_teacher_forbidden', __( 'Tu rol no permite publicar lecciones.', 'atora-lms' ), array( 'status' => 403 ) );
			}
			$target = $visible ? 'publish' : 'draft';
			if ( get_post_status( $wp ) !== $target ) {
				$result = wp_update_post( array( 'ID' => $wp, 'post_status' => $target ), true );
				if ( is_wp_error( $result ) ) {
					return new WP_Error( 'atora_mobile_lesson_update_failed', $result->get_error_message(), array( 'status' => 500 ) );
				}
				$changes['visible'] = $visible;
			}
		}

		if ( array_key_exists( 'due_date', $params ) ) {
			$date = trim( (string) $params['due_date'] );
			$time = trim( (string) ( $params['due_time'] ?? '23:59' ) );
			if ( '' !== $date ) {
				$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, wp_timezone() );
				if ( ! $parsed || $parsed->format( 'Y-m-d H:i' ) !== $date . ' ' . $time ) {
					return new WP_Error( 'atora_mobile_due_date_invalid', __( 'La fecha de entrega no es válida.', 'atora-lms' ), array( 'status' => 400 ) );
				}
			}
			foreach ( array( '_clms_due_date' => $date, 'lm_due_date' => $date, '_clms_due_time' => '' !== $date ? $time : '', 'lm_due_time' => '' !== $date ? $time : '' ) as $key => $value ) {
				update_post_meta( $wp, $key, $value );
			}
			// La fecha tardía anterior queda sin sentido si es antes del nuevo vencimiento.
			$late = (string) get_post_meta( $wp, '_clms_due_date_late', true );
			if ( '' !== $late && '' !== $date && $late < $date ) {
				update_post_meta( $wp, '_clms_due_date_late', '' );
				update_post_meta( $wp, 'lm_late_date', '' );
			}
			$changes['due_at'] = self::due_iso( $wp );
			do_action( 'atora/lesson/due_date_changed', $wp, $date, $time, $user_id );

			if ( ! empty( $params['notify'] ) && '' !== $date ) {
				self::notify_due_change( $user_id, $course, $wp, $event );
			}
		}

		if ( ! $changes && ! array_key_exists( 'visible', $params ) && ! array_key_exists( 'due_date', $params ) ) {
			return new WP_Error( 'atora_mobile_nothing_to_change', __( 'No hay cambios.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		update_post_meta( $wp, $done_key, array( 'at' => gmdate( 'c' ), 'changes' => $changes ) );
		clean_post_cache( $wp );
		return new WP_REST_Response( array( 'lesson' => self::lesson_row( $user_id, $wp ), 'replayed' => false ), 200 );
	}

	// ── Asistencia presencial ──────────────────────────────────────────────

	private static function wp_lesson_for( int $table_lesson, int $wp_course ): int {
		if ( $table_lesson <= 0 ) {
			return 0;
		}
		$lesson = \ATORA\LMS\LMS_Course_Service::get_lesson( $table_lesson );
		$wp     = $lesson ? absint( $lesson['wp_post_id'] ?? 0 ) : 0;
		return $wp && absint( get_post_meta( $wp, '_clms_course_id', true ) ) === $wp_course ? $wp : -1;
	}

	private static function attendance_out( array $roster, int $table_course ): array {
		$session              = $roster['session'];
		$session['course_id'] = $table_course;
		$session['lesson_id'] = $session['lesson_id'] ? self::table_lesson( (int) $session['lesson_id'] ) : 0;
		return array( 'session' => $session, 'students' => $roster['students'] );
	}

	private static function table_lesson( int $wp_lesson ): int {
		$row = \ATORA\LMS\LMS_Course_Service::get_lesson_by_wp_post( $wp_lesson );
		return $row ? (int) $row['id'] : 0;
	}

	/** GET /teacher/courses/{id}/attendance?date=&lesson= — lista del día, lecciones presenciales y sesiones recientes. */
	public static function attendance( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$table   = absint( $request['course_id'] );
		$wp      = $table > 0 && class_exists( 'ATORA_Attendance_Service' ) ? self::course_for_teacher( $user_id, $table ) : 0;
		if ( ! $wp ) {
			return self::not_found();
		}
		$date   = (string) ( $request->get_param( 'date' ) ?: wp_date( 'Y-m-d' ) );
		$lesson = self::wp_lesson_for( absint( $request->get_param( 'lesson' ) ), $wp );
		if ( $lesson < 0 ) {
			return self::not_found();
		}
		$roster = ATORA_Attendance_Service::roster_for( $user_id, $wp, $lesson, $date );
		if ( is_wp_error( $roster ) ) {
			return $roster;
		}
		$in_person = array();
		foreach ( ATORA_Attendance_Service::in_person_lessons( $wp ) as $wp_lesson ) {
			$in_person[] = array(
				'id'    => self::table_lesson( $wp_lesson ),
				'title' => sanitize_text_field( (string) get_the_title( $wp_lesson ) ),
				'date'  => (string) get_post_meta( $wp_lesson, '_atora_in_person_date', true ) ?: null,
			);
		}
		$recent = array_map( function ( array $session ) use ( $table ): array {
			$session['course_id'] = $table;
			$session['lesson_id'] = $session['lesson_id'] ? self::table_lesson( (int) $session['lesson_id'] ) : 0;
			return $session;
		}, ATORA_Attendance_Service::recent_sessions( $wp ) );
		return new WP_REST_Response( self::attendance_out( $roster, $table ) + array(
			'today'             => wp_date( 'Y-m-d' ),
			'site_timezone'     => wp_timezone_string(),
			'in_person_lessons' => $in_person,
			'recent'            => $recent,
		), 200 );
	}

	/** POST /teacher/courses/{id}/attendance — abre la sesión del día si hace falta y guarda las marcas. */
	public static function record_attendance( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$params  = (array) $request->get_json_params();
		$table   = absint( $request['course_id'] );
		$wp      = $table > 0 && class_exists( 'ATORA_Attendance_Service' ) ? self::course_for_teacher( $user_id, $table ) : 0;
		if ( ! $wp ) {
			return self::not_found();
		}
		$lesson = self::wp_lesson_for( absint( $params['lesson_id'] ?? 0 ), $wp );
		if ( $lesson < 0 ) {
			return self::not_found();
		}
		$marks = is_array( $params['marks'] ?? null ) ? $params['marks'] : array();
		if ( ! $marks ) {
			return new WP_Error( 'atora_attendance_empty', __( 'No hay marcas para guardar.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$result = ATORA_Attendance_Service::record_for( $user_id, $wp, $lesson, (string) ( $params['date'] ?? '' ), $marks );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$roster = ATORA_Attendance_Service::roster( $user_id, (int) $result['session']['id'] );
		return new WP_REST_Response( array(
			'applied' => $result['applied'],
			'ignored' => $result['ignored'],
		) + ( is_wp_error( $roster ) ? array() : self::attendance_out( $roster, $table ) ), 200 );
	}

	// ── Clase en vivo del docente ──────────────────────────────────────────

	/** Lección de tabla → post, con el curso en el alcance del docente. @return array{0:int,1:int} [wp_lesson, wp_course] */
	private static function teacher_lesson( int $user_id, int $table_lesson ): array {
		$lesson = \ATORA\LMS\LMS_Course_Service::get_lesson( $table_lesson );
		$wp     = $lesson ? absint( $lesson['wp_post_id'] ?? 0 ) : 0;
		$course = $wp ? absint( get_post_meta( $wp, '_clms_course_id', true ) ) : 0;
		return $wp && $course && ATORA_Teacher_Scope::can_access_course( $user_id, $course ) ? array( $wp, $course ) : array( 0, 0 );
	}

	/**
	 * POST /teacher/lessons/{id}/recording — `{url}` publica un enlace; `{clear:true}` la quita (y el proveedor ya no
	 * la vuelve a poner). Mismas reglas que la web: solo quien gestiona la lección.
	 */
	public static function recording( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		list( $wp, $course ) = self::teacher_lesson( $user_id, absint( $request['lesson_id'] ) );
		if ( ! $wp || ! class_exists( 'ATORA_Live_Session_Service' ) || ! ATORA_Live_Session_Service::get( $wp ) ) {
			return self::not_found();
		}
		if ( ! ATORA_Live_Session_Service::can_manage( $user_id, $wp ) || ! self::can_manage( $user_id, $course ) ) {
			return new WP_Error( 'atora_mobile_teacher_forbidden', __( 'Tu rol no permite cambiar la grabación.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$params = (array) $request->get_json_params();
		if ( ! empty( $params['clear'] ) ) {
			ATORA_Live_Session_Service::clear_recording( $wp );
		} else {
			$result = ATORA_Live_Session_Service::set_recording( $wp, array( 'url' => (string) ( $params['url'] ?? '' ), 'note' => (string) ( $params['note'] ?? '' ) ), $user_id );
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 400 ) );
				return $result;
			}
		}
		return new WP_REST_Response( array( 'lesson' => self::lesson_row( $user_id, $wp ) ), 200 );
	}

	/** GET /teacher/lessons/{id}/live-attendance — quiénes entraron a la clase en vivo según el proveedor. */
	public static function live_attendance( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		list( $wp ) = self::teacher_lesson( $user_id, absint( $request['lesson_id'] ) );
		$session    = $wp && class_exists( '\ATORA\LiveStreaming\Live_Session_Repository' ) ? \ATORA\LiveStreaming\Live_Session_Repository::get_by_lesson( $wp ) : null;
		if ( ! $wp ) {
			return self::not_found();
		}
		$items = array();
		foreach ( $session ? \ATORA\LiveStreaming\Live_Session_Repository::get_attendance_for_session( (int) $session['id'] ) : array() as $row ) {
			$user    = (int) $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : false;
			$items[] = array(
				'name'             => $user ? (string) ( $user->display_name ?: $user->user_login ) : (string) $row['display_name'],
				'registered'       => (bool) $user,
				'status'           => (string) $row['status'],
				'duration_minutes' => (int) round( (int) $row['duration_seconds'] / 60 ),
				'source'           => (string) $row['source'],
			);
		}
		return new WP_REST_Response( array( 'items' => $items, 'session_status' => $session ? (string) ( $session['status'] ?? '' ) : null ), 200 );
	}

	private static function notify_due_change( int $user_id, int $wp_course, int $wp_lesson, string $event ): void {
		$notifications = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Notifications' ) : null;
		if ( ! $notifications || ! method_exists( $notifications, 'add_notification' ) ) {
			return;
		}
		$due = self::due_iso( $wp_lesson );
		$when = $due ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $due ) ) : '';
		foreach ( (array) CLMS_Helper::get_enrolled_student_ids( $wp_course ) as $student ) {
			$notifications->add_notification( absint( $student ), array(
				'type'       => 'course_announcement',
				/* translators: %s: lesson title */
				'title'      => sprintf( __( 'Nueva fecha de entrega: %s', 'atora-lms' ), get_the_title( $wp_lesson ) ),
				/* translators: %s: date */
				'message'    => sprintf( __( 'Ahora vence el %s.', 'atora-lms' ), $when ),
				'link'       => (string) get_permalink( $wp_lesson ),
				'course_id'  => $wp_course,
				'dedupe_key' => 'due_change_' . md5( $user_id . '|' . $event ),
			) );
		}
	}
}
