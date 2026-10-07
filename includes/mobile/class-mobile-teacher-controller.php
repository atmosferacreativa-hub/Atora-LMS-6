<?php
/**
 * API móvil — Docente (6.31.0): `atora-mobile/v1/teacher/*`.
 *
 * - Toda ruta exige token móvil y rol docente (`lms_instructor`,
 *   `lms_instructor_assistant`, `lms_coordinator`, `administrator`): si no, 403.
 * - Cada recurso pasa por `ATORA_Teacher_Scope` (sección, autoría, editar lo
 *   ajeno, delegación; administrador solo de su institución): si no, 404.
 * - Calificar sale de `ATORA_Grading_Save_Service`, el mismo de SpeedGrader.
 * - Los ids de curso y lección son los de tabla (como el resto de la API); los
 *   de entrega y estudiante, los de WordPress.
 *
 * @package ATORA_LMS
 * @since 6.31.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Mobile_Teacher_Controller {

	const PER_PAGE        = 20;
	const QUEUE_PAGE      = 20;
	const FILE_LINK_TTL   = 15 * MINUTE_IN_SECONDS;
	const ANNOUNCE_LIMIT  = 10;
	const ANNOUNCE_WINDOW = HOUR_IN_SECONDS;

	/** Estados de la cola: nombre de la API → estado de la entrega. */
	const QUEUE_STATUSES = array(
		'pending'  => 'submitted',
		'draft'    => 'in_review',
		'graded'   => 'graded',
		'late'     => 'late',
	);

	public static function register_routes(): void {
		$ns   = ATORA_Mobile_REST_Controller::REST_NAMESPACE;
		$auth = array( __CLASS__, 'authorize' );
		$routes = array(
			'/teacher/today'                                                  => array( 'GET', 'today' ),
			'/teacher/courses'                                                => array( 'GET', 'courses' ),
			'/teacher/courses/(?P<course_id>\d+)/students'                    => array( 'GET', 'students' ),
			'/teacher/students/(?P<student_id>\d+)'                           => array( 'GET', 'student' ),
			'/teacher/submissions'                                            => array( 'GET', 'submissions' ),
			'/teacher/submissions/(?P<submission_id>\d+)'                     => array( 'GET', 'submission' ),
			'/teacher/submissions/(?P<submission_id>\d+)/grade'               => array( 'POST', 'grade' ),
			'/teacher/submissions/(?P<submission_id>\d+)/files/(?P<file_id>\d+)' => array( 'GET', 'file' ),
			'/teacher/announcements'                                          => array( 'POST', 'announce' ),
		);
		foreach ( $routes as $route => $spec ) {
			register_rest_route( $ns, $route, array(
				'methods'             => $spec[0],
				'callback'            => array( __CLASS__, $spec[1] ),
				'permission_callback' => $auth,
			) );
		}
	}

	/** Token móvil + rol docente. Un estudiante recibe 403 en todo `/teacher/*`. */
	public static function authorize( WP_REST_Request $request ) {
		$auth = ATORA_Mobile_REST_Controller::authorize( $request );
		if ( true !== $auth ) {
			return $auth;
		}
		if ( ! ATORA_Teacher_Scope::has_teacher_role( get_current_user_id() ) ) {
			return new WP_Error( 'atora_mobile_teacher_only', __( 'Esta sección es solo para docentes.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		return true;
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'atora_mobile_not_found', __( 'No encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
	}

	/** 503 si alguna consulta de la petición falló. */
	private static function db_failed(): ?WP_Error {
		global $wpdb;
		return ! empty( $wpdb->last_error ) ? ATORA_Mobile_Db_Errors::unavailable( 'docente' ) : null;
	}

	private static function iso( string $gmt ): ?string {
		return '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ? gmdate( 'c', strtotime( $gmt . ' UTC' ) ) : null;
	}

	private static function user_name( int $user_id ): string {
		$user = $user_id ? get_userdata( $user_id ) : false;
		return $user ? (string) ( $user->display_name ?: $user->user_login ) : '';
	}

	/** Cursos del docente: [table_id => ['wp' => post, 'title' => …]]. */
	private static function teacher_courses( int $user_id ): array {
		$out = array();
		foreach ( ATORA_Teacher_Scope::course_ids( $user_id ) as $wp_course ) {
			$table = ATORA_Mobile_REST_Controller::table_course_id( $wp_course );
			if ( $table > 0 ) {
				$out[ $table ] = array( 'wp' => $wp_course, 'title' => sanitize_text_field( (string) get_the_title( $wp_course ) ) );
			}
		}
		return $out;
	}

	/** Curso de tabla → post, solo si el docente lo tiene. */
	private static function course_for_teacher( int $user_id, int $table_course_id ): int {
		$course = $table_course_id > 0 ? \ATORA\LMS\LMS_Course_Service::get( $table_course_id ) : null;
		$wp     = $course ? absint( $course['wp_post_id'] ?? 0 ) : 0;
		return $wp > 0 && ATORA_Teacher_Scope::can_access_course( $user_id, $wp ) ? $wp : 0;
	}

	private static function lesson_ids_for( array $courses ): array {
		$ids = array();
		foreach ( $courses as $course ) {
			foreach ( (array) CLMS_Helper::get_course_lessons( (int) $course['wp'] ) as $lesson_id ) {
				$ids[] = absint( $lesson_id );
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	private static function student_ids( int $wp_course_id, int $section_id = 0 ): array {
		if ( $section_id > 0 && class_exists( '\\ATORA\\LMS\\Section_Service' ) ) {
			return array_map( 'absint', \ATORA\LMS\Section_Service::get_section_student_ids( $section_id ) );
		}
		return array_values( array_filter( array_map( 'absint', (array) CLMS_Helper::get_enrolled_student_ids( $wp_course_id ) ) ) );
	}

	private static function queue_engine() {
		$dashboard = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Teacher_Dashboard' ) : null;
		return $dashboard && method_exists( $dashboard, 'teacher_submission_queue' ) ? $dashboard : null;
	}

	private static function queue_item( array $item ): array {
		$submission_id = (int) $item['submission_id'];
		$group_master  = '1' === (string) get_post_meta( $submission_id, '_clms_submission_group_master', true );
		$student_id    = $group_master ? absint( get_post_meta( $submission_id, '_clms_submission_submitted_by', true ) ) : (int) $item['student_id'];
		return array(
			'id'           => $submission_id,
			'student'      => array( 'id' => $student_id, 'name' => self::user_name( $student_id ) ),
			'course'       => array( 'id' => ATORA_Mobile_REST_Controller::table_course_id( (int) $item['course_id'] ), 'title' => (string) $item['course_title'] ),
			'lesson'       => array( 'id' => self::table_lesson_id( (int) $item['lesson_id'] ), 'title' => (string) $item['lesson_title'] ),
			'status'       => array_search( (string) $item['status'], self::QUEUE_STATUSES, true ) ?: (string) $item['status'],
			'status_label' => (string) $item['status_label'],
			'is_late'      => '1' === (string) get_post_meta( $submission_id, '_clms_submission_is_late', true ),
			'group'        => $group_master,
			'submitted_at' => self::iso( (string) get_post_field( 'post_date_gmt', $submission_id ) ),
		);
	}

	private static function table_lesson_id( int $wp_lesson_id ): int {
		$lesson = $wp_lesson_id > 0 ? \ATORA\LMS\LMS_Course_Service::get_lesson_by_wp_post( $wp_lesson_id ) : null;
		return $lesson ? absint( $lesson['id'] ) : 0;
	}

	/** Cola sobre `get_submission_inbox_data()`. @return array{items:array,total:int,pages:int}|WP_Error */
	private static function queue( int $user_id, array $courses, string $status, int $wp_lesson_id, int $page, int $limit, string $order ) {
		$engine = self::queue_engine();
		if ( ! $engine ) {
			return new WP_Error( 'atora_mobile_teacher_unavailable', __( 'La cola de entregas no está disponible.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$filters = array( 'exclude_shadows' => true, 'order' => $order );
		if ( 'late' === $status ) {
			$filters['late'] = true;
		} elseif ( isset( self::QUEUE_STATUSES[ $status ] ) ) {
			$filters['status'] = self::QUEUE_STATUSES[ $status ];
		} elseif ( 'all' === $status ) {
			$filters['status'] = 'all';
		}
		if ( $wp_lesson_id > 0 ) {
			$filters['lesson_id'] = $wp_lesson_id;
		}
		$data = $engine->teacher_submission_queue( self::lesson_ids_for( $courses ), $filters, max( 1, $page ), $limit );
		return array(
			'items' => array_map( array( __CLASS__, 'queue_item' ), (array) $data['items'] ),
			'total' => (int) $data['total'],
			'pages' => (int) $data['pages'],
		);
	}

	// ── GET /teacher/today ─────────────────────────────────────────────────

	public static function today( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$courses = self::teacher_courses( $user_id );

		$to_grade = self::queue( $user_id, $courses, '', 0, 1, 5, 'ASC' );
		if ( is_wp_error( $to_grade ) ) {
			return $to_grade;
		}

		$at_risk = array();
		foreach ( $courses as $table_id => $course ) {
			foreach ( array_slice( self::student_ids( (int) $course['wp'] ), 0, 200 ) as $student_id ) {
				$risk = ATORA_Student_Risk_Service::for_student( $student_id, (int) $course['wp'] );
				if ( 'bajo' !== $risk['level'] ) {
					$at_risk[] = array(
						'student' => array( 'id' => $student_id, 'name' => self::user_name( $student_id ) ),
						'course'  => array( 'id' => $table_id, 'title' => $course['title'] ),
						'risk'    => self::risk_out( $risk ),
					);
				}
			}
		}
		usort( $at_risk, static fn( $a, $b ) => ATORA_Student_Risk_Service::LEVELS[ $b['risk']['level'] ] <=> ATORA_Student_Risk_Service::LEVELS[ $a['risk']['level'] ] );

		$from   = CLMS_Agenda_Service::local_ts( wp_date( 'Y-m-d' ), '00:00:00' );
		$agenda = class_exists( 'CLMS_Agenda_Service' ) ? CLMS_Agenda_Service::for_courses( $user_id, $from, $from + DAY_IN_SECONDS - 1, $courses ) : array();
		$agenda = array_map( static function ( $item ) {
			unset( $item['done'] );
			return $item;
		}, $agenda );

		$failed = self::db_failed();
		if ( $failed ) {
			return $failed;
		}
		return new WP_REST_Response( array(
			'to_grade'        => array( 'count' => $to_grade['total'], 'oldest' => $to_grade['items'] ),
			'at_risk'         => array( 'count' => count( $at_risk ), 'items' => array_slice( $at_risk, 0, 10 ) ),
			'today'           => array_values( $agenda ),
			'unread_messages' => class_exists( 'ATORA_Inbox_Store' ) ? ATORA_Inbox_Store::unread_count( $user_id ) : 0,
			'generated_at'    => gmdate( 'c' ),
		), 200 );
	}

	private static function risk_out( array $risk ): array {
		return array( 'level' => $risk['level'], 'label' => $risk['label'], 'reasons' => $risk['reasons'] );
	}

	// ── GET /teacher/courses ───────────────────────────────────────────────

	public static function courses( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$items   = array();
		foreach ( self::teacher_courses( $user_id ) as $table_id => $course ) {
			$sections = array();
			if ( class_exists( '\\ATORA\\LMS\\Section_Service' ) ) {
				$all_sections = (array) \ATORA\LMS\Section_Service::get_sections_by_course( (int) $course['wp'] );
				$own          = ATORA_Teacher_Scope::is_section_teacher( $user_id, (int) $course['wp'] );
				foreach ( $all_sections as $section ) {
					$teachers = wp_list_pluck( (array) \ATORA\LMS\Section_Service::get_teachers( (int) $section['id'] ), 'user_id' );
					if ( $own && ! in_array( $user_id, array_map( 'intval', $teachers ), true ) ) {
						continue;
					}
					$sections[] = array(
						'id'       => (int) $section['id'],
						'title'    => sanitize_text_field( (string) $section['title'] ),
						'students' => count( self::student_ids( (int) $course['wp'], (int) $section['id'] ) ),
					);
				}
			}
			$pending = self::queue( $user_id, array( $table_id => $course ), '', 0, 1, 1, 'ASC' );
			$items[] = array(
				'id'                  => $table_id,
				'title'               => $course['title'],
				'students'            => count( self::student_ids( (int) $course['wp'] ) ),
				'pending_submissions' => is_wp_error( $pending ) ? 0 : $pending['total'],
				'sections'            => $sections,
			);
		}
		$failed = self::db_failed();
		return $failed ? $failed : new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	// ── GET /teacher/courses/{id}/students?page=&search=&section= ──────────

	public static function students( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$wp      = self::course_for_teacher( $user_id, absint( $request['course_id'] ) );
		if ( ! $wp ) {
			return self::not_found();
		}
		$ids    = self::student_ids( $wp, absint( $request->get_param( 'section' ) ) );
		$search = trim( (string) $request->get_param( 'search' ) );
		$rows   = array();
		foreach ( $ids as $student_id ) {
			$name = self::user_name( $student_id );
			if ( '' !== $search && false === mb_stripos( remove_accents( $name ), remove_accents( $search ) ) ) {
				continue;
			}
			$rows[] = array( 'id' => $student_id, 'name' => $name );
		}
		usort( $rows, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
		$page  = max( 1, absint( $request->get_param( 'page' ) ) );
		$total = count( $rows );
		$table = absint( $request['course_id'] );
		$items = array();
		foreach ( array_slice( $rows, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ) as $row ) {
			$items[] = self::student_row( (int) $row['id'], $wp, $table ) + array( 'name' => $row['name'] );
		}
		$failed = self::db_failed();
		return $failed ? $failed : new WP_REST_Response( array(
			'items'     => $items,
			'page'      => $page,
			'per_page'  => self::PER_PAGE,
			'total'     => $total,
			'next_page' => $page * self::PER_PAGE < $total ? $page + 1 : null,
		), 200 );
	}

	private static function student_row( int $student_id, int $wp_course, int $table_course ): array {
		$summary = class_exists( 'CLMS_Student_Grades_Service' ) ? CLMS_Student_Grades_Service::course_summary( $student_id, $wp_course ) : array();
		global $wpdb;
		$last = (string) $wpdb->get_var( $wpdb->prepare( "SELECT last_activity FROM {$wpdb->prefix}atora_enrollments WHERE user_id = %d AND course_id = %d LIMIT 1", $student_id, $table_course ) ); // phpcs:ignore WordPress.DB
		$grading = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Grading' ) : null;
		$status  = $grading ? (array) $grading->get_student_course_status( $student_id, $wp_course ) : array();
		return array(
			'id'          => $student_id,
			'progress'    => (int) ( $summary['progress'] ?? $status['progress_percent'] ?? 0 ),
			'final_grade' => isset( $summary['final_grade'] ) && null !== $summary['final_grade'] ? (float) $summary['final_grade'] : null,
			'last_access' => '' !== $last ? self::iso( get_gmt_from_date( $last ) ) : null,
			'risk'        => self::risk_out( ATORA_Student_Risk_Service::for_student( $student_id, $wp_course, $status ) ),
		);
	}

	// ── GET /teacher/students/{id}?course= ─────────────────────────────────

	public static function student( WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$student_id = absint( $request['student_id'] );
		$table      = absint( $request->get_param( 'course' ) );
		$wp         = self::course_for_teacher( $user_id, $table );
		if ( ! $wp || ! $student_id || ! in_array( $student_id, self::student_ids( $wp ), true ) ) {
			return self::not_found();
		}
		$submissions = array();
		$posts       = get_posts( array(
			'post_type'      => 'clms_submission',
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => 50,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => '_clms_submission_user_id', 'value' => $student_id, 'type' => 'NUMERIC' ),
				array( 'key' => '_clms_submission_lesson_id', 'value' => (array) CLMS_Helper::get_course_lessons( $wp ) ?: array( 0 ), 'compare' => 'IN' ),
			),
		) );
		foreach ( $posts as $post_id ) {
			$lesson    = absint( get_post_meta( $post_id, '_clms_submission_lesson_id', true ) );
			$status    = (string) get_post_meta( $post_id, '_clms_submission_status', true );
			$grade     = get_post_meta( $post_id, '_clms_submission_grade', true );
			$submissions[] = array(
				'id'           => (int) $post_id,
				'lesson'       => array( 'id' => self::table_lesson_id( $lesson ), 'title' => (string) get_the_title( $lesson ) ),
				'status'       => array_search( $status, self::QUEUE_STATUSES, true ) ?: $status,
				'grade'        => '' !== (string) $grade && is_numeric( $grade ) ? (int) $grade : null,
				'submitted_at' => self::iso( (string) get_post_field( 'post_date_gmt', $post_id ) ),
			);
		}
		$grading = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Grading' ) : null;
		$status  = $grading ? (array) $grading->get_student_course_status( $student_id, $wp ) : array();
		$alerts  = array();
		global $wpdb;
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT warning_type, data, severity, status, updated_at FROM {$wpdb->prefix}atora_early_warning WHERE course_id = %d AND user_id = %d ORDER BY updated_at DESC LIMIT 20", $wp, $student_id ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$data     = json_decode( (string) $row['data'], true );
			$alerts[] = array(
				'type'       => (string) $row['warning_type'],
				'status'     => (string) $row['status'],
				'severity'   => (int) $row['severity'],
				'count'      => absint( is_array( $data ) ? ( $data['count'] ?? 0 ) : 0 ),
				'updated_at' => self::iso( (string) $row['updated_at'] ),
			);
		}
		$failed = self::db_failed();
		if ( $failed ) {
			return $failed;
		}
		return new WP_REST_Response( array(
			'student'     => array( 'id' => $student_id, 'name' => self::user_name( $student_id ), 'email' => (string) get_userdata( $student_id )->user_email ),
			'course'      => array( 'id' => $table, 'title' => (string) get_the_title( $wp ) ),
			'summary'     => self::student_row( $student_id, $wp, $table ),
			'grades'      => class_exists( 'CLMS_Student_Grades_Service' ) ? array_values( CLMS_Student_Grades_Service::course_activities( $student_id, $wp ) ) : array(),
			'submissions' => $submissions,
			'alerts'      => $alerts,
			'pending'     => absint( $status['pending_activities'] ?? 0 ),
		), 200 );
	}

	// ── GET /teacher/submissions?status=&course=&lesson=&cursor= ───────────

	public static function submissions( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$courses = self::teacher_courses( $user_id );
		$table   = absint( $request->get_param( 'course' ) );
		if ( $table > 0 ) {
			if ( ! isset( $courses[ $table ] ) ) {
				return self::not_found();
			}
			$courses = array( $table => $courses[ $table ] );
		}
		$wp_lesson = 0;
		$lesson    = absint( $request->get_param( 'lesson' ) );
		if ( $lesson > 0 ) {
			$row       = \ATORA\LMS\LMS_Course_Service::get_lesson( $lesson );
			$wp_lesson = $row ? absint( $row['wp_post_id'] ?? 0 ) : 0;
			if ( ! $wp_lesson || ! in_array( $wp_lesson, self::lesson_ids_for( $courses ), true ) ) {
				return self::not_found();
			}
		}
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$page   = preg_match( '/^p(\d+)$/', (string) $request->get_param( 'cursor' ), $m ) ? absint( $m[1] ) : 1;
		$queue  = self::queue( $user_id, $courses, '' === $status ? 'pending' : $status, $wp_lesson, $page, self::QUEUE_PAGE, 'ASC' );
		if ( is_wp_error( $queue ) ) {
			return $queue;
		}
		$failed = self::db_failed();
		return $failed ? $failed : new WP_REST_Response( array(
			'items'       => $queue['items'],
			'total'       => $queue['total'],
			'next_cursor' => $page < $queue['pages'] ? 'p' . ( $page + 1 ) : null,
		), 200 );
	}

	// ── GET /teacher/submissions/{id} ──────────────────────────────────────

	public static function submission( WP_REST_Request $request ) {
		$user_id       = get_current_user_id();
		$submission_id = absint( $request['submission_id'] );
		if ( ! ATORA_Teacher_Scope::can_grade_submission( $user_id, $submission_id ) ) {
			return self::not_found();
		}
		$detail = self::detail( $user_id, $submission_id );
		$failed = self::db_failed();
		return $failed ? $failed : new WP_REST_Response( array( 'submission' => $detail ), 200 );
	}

	/** Lo que el docente necesita para calificar (también va en la respuesta 409). */
	public static function detail( int $user_id, int $submission_id ): array {
		$grading = new CLMS_Grading();
		$context = (array) $grading->get_submission_context( $submission_id, $user_id );
		$scores  = (array) ( $context['rubric_scores'] ?? array() );

		$attempts = array();
		foreach ( (array) ( $context['attempts'] ?? array() ) as $attempt ) {
			$attempts[] = array(
				'attempt'             => (int) $attempt['attempt'],
				'source'              => (string) $attempt['source'],
				'server_received_at'  => $attempt['received_at'] ? self::iso( (string) $attempt['received_at'] ) : null,
				'client_submitted_at' => $attempt['client_at'] ? self::iso( (string) $attempt['client_at'] ) : null,
				'is_late'             => (bool) $attempt['is_late'],
				'body_text'           => (string) $attempt['body_text'],
				'files'               => array_map( static fn( $file ) => self::file_out( $user_id, $submission_id, $file ), (array) $attempt['files'] ),
			);
		}
		if ( ! $attempts ) {
			// Entrega sin historial (anterior a la migración): el post como intento 1.
			$attempts[] = array(
				'attempt'             => 1,
				'source'              => (string) get_post_meta( $submission_id, '_clms_submission_source', true ) ?: 'web',
				'server_received_at'  => self::iso( (string) get_post_field( 'post_date_gmt', $submission_id ) ),
				'client_submitted_at' => null,
				'is_late'             => '1' === (string) get_post_meta( $submission_id, '_clms_submission_is_late', true ),
				'body_text'           => (string) ( $context['comment'] ?? '' ),
				'files'               => array_map( static fn( $file ) => self::file_out( $user_id, $submission_id, array( 'attachment_id' => (int) $file['id'], 'filename' => (string) $file['label'], 'mime_type' => (string) get_post_mime_type( (int) $file['id'] ), 'bytes' => 0 ) ), (array) ( $context['files'] ?? array() ) ),
			);
		}

		$criteria = array();
		foreach ( self::criteria( (int) ( $context['rubric_id'] ?? 0 ), (array) ( $context['rubric_snapshot'] ?? array() ) ) as $i => $criterion ) {
			$max      = absint( $criterion['max_points'] ?? 0 );
			$levels   = array_values( array_map( static fn( $lv ) => array( 'label' => sanitize_text_field( (string) ( $lv['label'] ?? '' ) ), 'points' => (float) ( $lv['points'] ?? 0 ), 'descriptor' => sanitize_textarea_field( (string) ( $lv['descriptor'] ?? '' ) ) ), (array) ( $criterion['levels'] ?? array() ) ) );
			$score    = isset( $scores[ $i ]['score'] ) ? CLMS_Rubric_Level_Bands::score_field_value( $scores[ $i ]['score'] ) : '';
			$criteria[] = array(
				'index'       => (int) $i,
				'name'        => sanitize_text_field( (string) ( $criterion['name'] ?? $criterion['title'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $criterion['description'] ?? '' ) ),
				'max_points'  => $max,
				'weight'      => (float) ( $criterion['weight'] ?? 0 ),
				'levels'      => $levels,
				'bands'       => CLMS_Rubric_Level_Bands::build( (array) ( $criterion['levels'] ?? array() ), $max ),
				'score'       => '' !== $score ? (float) $score : null,
				'level'       => '' !== $score ? CLMS_Rubric_Level_Bands::level_for( (array) ( $criterion['levels'] ?? array() ), $max, (float) $score ) : null,
				'feedback'    => (string) ( $scores[ $i ]['feedback'] ?? '' ),
			);
		}

		$master = '1' === (string) get_post_meta( $submission_id, '_clms_submission_group_master', true );
		$group  = null;
		if ( $master && class_exists( '\\ATORA\\Groups\\Group_Service' ) ) {
			$service  = new \ATORA\Groups\Group_Service();
			$group_id = absint( get_post_meta( $submission_id, '_clms_submission_group_id', true ) );
			$by       = absint( get_post_meta( $submission_id, '_clms_submission_submitted_by', true ) );
			$group    = array(
				'id'           => $group_id,
				'name'         => (string) ( $service->get_group( $group_id )['name'] ?? '' ),
				'members'      => array_map( static fn( $id ) => array( 'id' => (int) $id, 'name' => self::user_name( (int) $id ) ), $service->get_group_member_ids( $group_id ) ),
				'submitted_by' => $by ? array( 'id' => $by, 'name' => self::user_name( $by ) ) : null,
			);
		}

		$student_id = $master ? absint( get_post_meta( $submission_id, '_clms_submission_submitted_by', true ) ) : (int) ( $context['student_id'] ?? 0 );
		$status     = (string) ( $context['status'] ?? 'submitted' );
		return array(
			'id'               => $submission_id,
			'revision'         => ATORA_Grading_Save_Service::revision( $submission_id ),
			'student'          => array( 'id' => $student_id, 'name' => self::user_name( $student_id ) ),
			'course'           => array( 'id' => ATORA_Mobile_REST_Controller::table_course_id( (int) ( $context['course_id'] ?? 0 ) ), 'title' => (string) ( $context['course_title'] ?? '' ) ),
			'lesson'           => array( 'id' => self::table_lesson_id( (int) ( $context['lesson_id'] ?? 0 ) ), 'title' => (string) ( $context['lesson_title'] ?? '' ) ),
			'status'           => array_search( $status, self::QUEUE_STATUSES, true ) ?: $status,
			'grade'            => '' !== (string) ( $context['grade'] ?? '' ) ? (int) $context['grade'] : null,
			'feedback'         => (string) ( $context['feedback'] ?? '' ),
			'graded_attempt'   => (int) ( $context['selected_attempt'] ?? 0 ),
			'attempts'         => $attempts,
			'rubric'           => (int) ( $context['rubric_id'] ?? 0 ) > 0 ? array(
				'id'           => (int) $context['rubric_id'],
				'title'        => (string) ( $context['rubric_title'] ?? '' ),
				'total_points' => array_sum( wp_list_pluck( $criteria, 'max_points' ) ),
				'criteria'     => $criteria,
			) : null,
			'group'            => $group,
			'moderated'        => ! empty( $context['moderation']['institutional'] ),
		);
	}

	/** Criterios: la foto de la evaluación si existe; si no, la rúbrica vigente (como el guardado). */
	public static function criteria( int $rubric_id, array $snapshot ): array {
		if ( ! empty( $snapshot['criteria'] ) && $rubric_id === absint( $snapshot['rubric_id'] ?? 0 ) ) {
			return (array) $snapshot['criteria'];
		}
		if ( $rubric_id <= 0 ) {
			return array();
		}
		$row = class_exists( '\\ATORA\\LMS\\Rubric_Service' ) ? \ATORA\LMS\Rubric_Service::get( $rubric_id ) : null;
		if ( is_array( $row ) ) {
			return (array) \ATORA\LMS\Rubric_Service::get_criteria( $rubric_id, absint( $row['revision'] ?? 1 ) );
		}
		return class_exists( 'CLMS_Rubric' ) ? (array) CLMS_Rubric::get_criteria( $rubric_id ) : array();
	}

	// ── Archivos: enlace firmado y temporal ────────────────────────────────

	public static function file_signature( int $user_id, int $submission_id, int $file_id, int $expires ): string {
		return hash_hmac( 'sha256', $user_id . '|' . $submission_id . '|' . $file_id . '|' . $expires, wp_salt( 'auth' ) . 'atora-mobile-teacher-file' );
	}

	private static function file_out( int $user_id, int $submission_id, array $file ): array {
		$file_id = absint( $file['attachment_id'] ?? 0 );
		$expires = time() + self::FILE_LINK_TTL;
		return array(
			'id'          => $file_id,
			'filename'    => (string) ( $file['filename'] ?? '' ),
			'mime_type'   => (string) ( $file['mime_type'] ?? '' ),
			'bytes'       => (int) ( $file['bytes'] ?? 0 ),
			'url'         => $file_id ? esc_url_raw( add_query_arg( array( 'expires' => $expires, 'sig' => self::file_signature( $user_id, $submission_id, $file_id, $expires ) ), rest_url( ATORA_Mobile_REST_Controller::REST_NAMESPACE . "/teacher/submissions/{$submission_id}/files/{$file_id}" ) ) ) : null,
			'expires_at'  => gmdate( 'Y-m-d\TH:i:s\Z', $expires ),
		);
	}

	public static function file( WP_REST_Request $request ) {
		$user_id       = get_current_user_id();
		$submission_id = absint( $request['submission_id'] );
		$file_id       = absint( $request['file_id'] );
		$expires       = absint( $request->get_param( 'expires' ) );
		$signature     = (string) $request->get_param( 'sig' );
		if ( $expires < time() || '' === $signature || ! hash_equals( self::file_signature( $user_id, $submission_id, $file_id, $expires ), $signature ) ) {
			return new WP_Error( 'atora_mobile_file_link', __( 'El enlace del archivo venció o no es válido.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		if ( ! ATORA_Teacher_Scope::can_grade_submission( $user_id, $submission_id ) || ! in_array( $file_id, self::submission_file_ids( $submission_id ), true ) ) {
			return self::not_found();
		}
		$path = (string) get_attached_file( $file_id );
		if ( '' === $path || ! is_readable( $path ) ) {
			return self::not_found();
		}
		$mime = (string) get_post_mime_type( $file_id ) ?: 'application/octet-stream';
		add_filter( 'rest_pre_serve_request', static function ( $served ) use ( $path, $mime ) {
			if ( ! headers_sent() ) {
				header( 'Content-Type: ' . $mime );
				header( 'Content-Length: ' . (string) filesize( $path ) );
				header( 'Content-Disposition: inline; filename="' . rawurlencode( wp_basename( $path ) ) . '"' );
			}
			readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return true;
		} );
		return new WP_REST_Response( null, 200 );
	}

	/** Adjuntos del post y de todos sus intentos. */
	private static function submission_file_ids( int $submission_id ): array {
		$ids = array_merge( (array) get_post_meta( $submission_id, '_clms_submission_files', true ), (array) get_post_meta( $submission_id, '_clms_submission_attachments', true ) );
		foreach ( ATORA_Web_Submission_History::attempts_for_post( $submission_id ) as $attempt ) {
			$ids = array_merge( $ids, wp_list_pluck( $attempt['files'], 'attachment_id' ) );
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	// ── POST /teacher/submissions/{id}/grade ───────────────────────────────

	/**
	 * { scores: [{index, score, feedback}], feedback, grade?, publish, attempt?,
	 *   expected_revision, client_event_id }
	 */
	public static function grade( WP_REST_Request $request ) {
		$user_id       = get_current_user_id();
		$submission_id = absint( $request['submission_id'] );
		if ( ! ATORA_Teacher_Scope::can_grade_submission( $user_id, $submission_id ) ) {
			return self::not_found();
		}
		$params = (array) $request->get_json_params();
		$event  = sanitize_text_field( (string) ( $params['client_event_id'] ?? '' ) );
		if ( '' === $event || strlen( $event ) > 64 ) {
			return new WP_Error( 'atora_mobile_client_event_required', __( 'Falta client_event_id.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		// Idempotente: el mismo evento devuelve lo ya guardado, sin volver a guardar.
		$event_key = '_atora_grade_event_' . md5( $user_id . '|' . $event );
		$stored    = get_post_meta( $submission_id, $event_key, true );
		if ( is_array( $stored ) ) {
			return new WP_REST_Response( array( 'result' => $stored, 'submission' => self::detail( $user_id, $submission_id ), 'replayed' => true ), 200 );
		}

		$input = array(
			'clms_sg_submit'  => ! empty( $params['publish'] ) ? 'publish' : 'save_draft',
			'status'          => ! empty( $params['publish'] ) ? 'graded' : 'in_review',
			'feedback'        => (string) ( $params['feedback'] ?? '' ),
			'grade'           => isset( $params['grade'] ) && null !== $params['grade'] ? (string) $params['grade'] : '',
			'rubric_scores'   => array(),
			'rubric_feedback' => array(),
		);
		foreach ( (array) ( $params['scores'] ?? array() ) as $row ) {
			$index = absint( $row['index'] ?? -1 );
			if ( ! isset( $row['index'] ) ) {
				continue;
			}
			$input['rubric_scores'][ $index ]   = isset( $row['score'] ) && null !== $row['score'] ? (string) $row['score'] : '';
			$input['rubric_feedback'][ $index ] = (string) ( $row['feedback'] ?? '' );
		}
		if ( isset( $params['attempt'] ) ) {
			$input['attempt'] = absint( $params['attempt'] );
		}
		if ( array_key_exists( 'expected_revision', $params ) ) {
			$input['expected_revision'] = (string) absint( $params['expected_revision'] );
		}

		$result = ( new ATORA_Grading_Save_Service( new CLMS_Grading() ) )->save( $submission_id, $user_id, $input );
		if ( is_wp_error( $result ) ) {
			if ( 'atora_grade_revision_conflict' === $result->get_error_code() ) {
				// Sin pisar: se devuelve la versión actual para que el docente la revise.
				return new WP_Error(
					'atora_grade_revision_conflict',
					$result->get_error_message(),
					array( 'status' => 409, 'current_revision' => ATORA_Grading_Save_Service::revision( $submission_id ), 'submission' => self::detail( $user_id, $submission_id ) )
				);
			}
			$data   = (array) $result->get_error_data();
			$status = isset( $data['status'] ) ? (int) $data['status'] : ( 'forbidden' === $result->get_error_code() ? 404 : 422 );
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => $status ) );
		}
		update_post_meta( $submission_id, $event_key, $result );
		return new WP_REST_Response( array( 'result' => $result, 'submission' => self::detail( $user_id, $submission_id ), 'replayed' => false ), 200 );
	}

	// ── POST /teacher/announcements ────────────────────────────────────────

	/** { course_id, section_id?, title, body, client_event_id } → aviso en "Avisos" de cada estudiante. */
	public static function announce( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$params  = (array) $request->get_json_params();
		$table   = absint( $params['course_id'] ?? 0 );
		$wp      = self::course_for_teacher( $user_id, $table );
		if ( ! $wp ) {
			return self::not_found();
		}
		$section = absint( $params['section_id'] ?? 0 );
		if ( $section > 0 ) {
			$row = class_exists( '\\ATORA\\LMS\\Section_Service' ) ? \ATORA\LMS\Section_Service::get( $section ) : null;
			if ( ! $row || absint( $row['wp_course_id'] ?? 0 ) !== $wp ) {
				return self::not_found();
			}
		}
		$title = trim( sanitize_text_field( (string) ( $params['title'] ?? '' ) ) );
		$body  = trim( sanitize_textarea_field( (string) ( $params['body'] ?? '' ) ) );
		$event = sanitize_text_field( (string) ( $params['client_event_id'] ?? '' ) );
		if ( '' === $body ) {
			return new WP_Error( 'atora_mobile_announcement_empty', __( 'El aviso no puede estar vacío.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		if ( '' === $event || strlen( $event ) > 64 ) {
			return new WP_Error( 'atora_mobile_client_event_required', __( 'Falta client_event_id.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$dedupe = 'announcement_' . md5( $user_id . '|' . $event );
		$done   = get_user_meta( $user_id, '_atora_announcement_' . md5( $event ), true );
		if ( is_array( $done ) ) {
			return new WP_REST_Response( $done + array( 'replayed' => true ), 200 );
		}
		if ( class_exists( 'ATORA_Rate_Limiter' ) && ! ATORA_Rate_Limiter::consume( 'mobile_announcements', (string) $user_id, self::ANNOUNCE_LIMIT, self::ANNOUNCE_WINDOW, true ) ) {
			return new WP_Error( 'atora_mobile_rate_limited', __( 'Enviaste muchos avisos. Espera un momento.', 'atora-lms' ), array( 'status' => 429 ) );
		}
		$notifications = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Notifications' ) : null;
		if ( ! $notifications || ! method_exists( $notifications, 'add_notification' ) ) {
			return new WP_Error( 'atora_mobile_announcement_unavailable', __( 'Los avisos no están disponibles.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$sent = 0;
		foreach ( self::student_ids( $wp, $section ) as $student_id ) {
			if ( $notifications->add_notification( $student_id, array(
				'type'       => 'course_announcement',
				'title'      => '' !== $title ? $title : sprintf( __( 'Aviso de %s', 'atora-lms' ), self::user_name( $user_id ) ),
				'message'    => $body,
				'link'       => (string) get_permalink( $wp ),
				'course_id'  => $wp,
				'dedupe_key' => $dedupe,
			) ) ) {
				++$sent;
			}
		}
		$failed = self::db_failed();
		if ( $failed ) {
			return $failed;
		}
		$out = array( 'course_id' => $table, 'section_id' => $section ?: null, 'recipients' => $sent );
		update_user_meta( $user_id, '_atora_announcement_' . md5( $event ), $out );
		return new WP_REST_Response( $out + array( 'replayed' => false ), 201 );
	}
}
