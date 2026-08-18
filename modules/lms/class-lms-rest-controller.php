<?php
/**
 * REST Controller — LMS Cursos, Lecciones y Matrículas (Fase 11)
 *
 * Namespace: atora-lms/v1
 *
 * GET  /atora-lms/v1/courses                → lista
 * GET  /atora-lms/v1/courses/{id}           → curso con curriculum
 * POST /atora-lms/v1/courses                → crear
 * POST /atora-lms/v1/courses/{id}           → actualizar
 * GET  /atora-lms/v1/courses/{id}/stats     → estadísticas de estudiantes
 * POST /atora-lms/v1/courses/{id}/enroll    → matricular usuario
 * GET  /atora-lms/v1/courses/{id}/progress  → progreso del usuario actual
 * POST /atora-lms/v1/lessons/{id}/complete  → completar lección
 * GET  /atora-lms/v1/migration/status       → estado de migración CPTs
 * POST /atora-lms/v1/migration/run          → ejecutar migración (admin)
 *
 * @package ATORA_LMS\LMS
 * @since   5.29.0
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMS_REST_Controller {

	const REST_NAMESPACE = 'atora-lms/v1';

	public static function register_routes(): void {
		$ns       = self::REST_NAMESPACE;
		$can_read = array( __CLASS__, 'can_read' );
		$can_inst = array( __CLASS__, 'can_manage_courses' );
		$can_admin = array( __CLASS__, 'can_admin' );

		register_rest_route( $ns, '/courses', array(
			array( 'methods' => 'GET',  'callback' => array( __CLASS__, 'list_courses' ),  'permission_callback' => $can_read ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_course' ), 'permission_callback' => $can_inst ),
		) );
		register_rest_route( $ns, '/courses/(?P<course_id>\d+)', array(
			array( 'methods' => 'GET',  'callback' => array( __CLASS__, 'get_course' ),    'permission_callback' => $can_read ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'update_course' ), 'permission_callback' => $can_inst ),
		) );
		register_rest_route( $ns, '/courses/(?P<course_id>\d+)/stats', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'course_stats' ), 'permission_callback' => $can_inst,
		) );
		register_rest_route( $ns, '/courses/(?P<course_id>\d+)/enroll', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'enroll' ), 'permission_callback' => $can_inst,
		) );
		register_rest_route( $ns, '/courses/(?P<course_id>\d+)/progress', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'get_progress' ), 'permission_callback' => 'is_user_logged_in',
		) );
		register_rest_route( $ns, '/lessons/(?P<lesson_id>\d+)/complete', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'complete_lesson' ), 'permission_callback' => 'is_user_logged_in',
		) );
		register_rest_route( $ns, '/migration/status', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'migration_status' ), 'permission_callback' => $can_admin,
		) );
		register_rest_route( $ns, '/migration/run', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'run_migration' ), 'permission_callback' => $can_admin,
		) );
		// Fase II S6 — Vista de cohorte
		register_rest_route( $ns, '/courses/(?P<course_id>\d+)/cohort', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'cohort' ),
			'permission_callback' => $can_inst,
		) );
	}

	// ── Permisos ─────────────────────────────────────────────────────────────

	public static function can_read(): bool {
		return is_user_logged_in();
	}

	public static function can_manage_courses(): bool {
		return current_user_can( 'clms_manage_courses' ) || current_user_can( 'manage_options' );
	}

	public static function can_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	// ── Cursos ───────────────────────────────────────────────────────────────

	public static function list_courses( \WP_REST_Request $r ): \WP_REST_Response {
		$args = array(
			'limit'         => absint( $r->get_param( 'limit' ) ?: 20 ),
			'offset'        => absint( $r->get_param( 'offset' ) ?: 0 ),
			'status'        => sanitize_key( (string) ( $r->get_param( 'status' ) ?: 'published' ) ),
			'search'        => sanitize_text_field( (string) ( $r->get_param( 'search' ) ?: '' ) ),
			'instructor_id' => absint( $r->get_param( 'instructor_id' ) ?: 0 ),
		);
		return new \WP_REST_Response( array_merge( array( 'success' => true ), LMS_Course_Service::get_all( $args ) ), 200 );
	}

	public static function get_course( \WP_REST_Request $r ): \WP_REST_Response {
		$id     = absint( $r->get_param( 'course_id' ) );
		$course = LMS_Course_Service::get( $id );
		if ( ! $course ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'Curso no encontrado.', 'atora-lms' ) ), 404 );
		}
		$course['curriculum'] = LMS_Course_Service::get_curriculum( $id );
		return new \WP_REST_Response( array( 'success' => true, 'course' => $course ), 200 );
	}

	public static function create_course( \WP_REST_Request $r ): \WP_REST_Response {
		$id = LMS_Course_Service::create( $r->get_json_params() ?: array() );
		if ( ! $id ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'El título es obligatorio.', 'atora-lms' ) ), 400 );
		}
		return new \WP_REST_Response( array( 'success' => true, 'course' => LMS_Course_Service::get( $id ) ), 201 );
	}

	public static function update_course( \WP_REST_Request $r ): \WP_REST_Response {
		$id = absint( $r->get_param( 'course_id' ) );
		$ok = LMS_Course_Service::update( $id, $r->get_json_params() ?: array() );
		if ( ! $ok ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'No se pudo actualizar.', 'atora-lms' ) ), 400 );
		}
		return new \WP_REST_Response( array( 'success' => true, 'course' => LMS_Course_Service::get( $id ) ), 200 );
	}

	public static function course_stats( \WP_REST_Request $r ): \WP_REST_Response {
		$id = absint( $r->get_param( 'course_id' ) );
		return new \WP_REST_Response( array( 'success' => true, 'stats' => LMS_Enrollment_Service::get_course_stats( $id ) ), 200 );
	}

	public static function enroll( \WP_REST_Request $r ): \WP_REST_Response {
		$b         = $r->get_json_params() ?: array();
		$course_id = absint( $r->get_param( 'course_id' ) );
		$user_id   = absint( $b['user_id'] ?? get_current_user_id() );
		$order_id  = absint( $b['order_id'] ?? 0 );

		if ( ! $user_id ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => __( 'Se requiere user_id.', 'atora-lms' ) ), 400 );
		}
		$id = LMS_Enrollment_Service::enroll( $user_id, $course_id, $order_id );
		return $id
			? new \WP_REST_Response( array( 'success' => true, 'enrollment_id' => $id ), 200 )
			: new \WP_REST_Response( array( 'success' => false, 'message' => __( 'No se pudo matricular.', 'atora-lms' ) ), 400 );
	}

	public static function get_progress( \WP_REST_Request $r ): \WP_REST_Response {
		if ( ! is_user_logged_in() ) {
			return new \WP_REST_Response( array( 'success' => false ), 401 );
		}
		$course_id = absint( $r->get_param( 'course_id' ) );
		$user_id   = get_current_user_id();
		return new \WP_REST_Response( array( 'success' => true, 'progress' => LMS_Enrollment_Service::get_progress( $user_id, $course_id ) ), 200 );
	}

	public static function complete_lesson( \WP_REST_Request $r ): \WP_REST_Response {
		if ( ! is_user_logged_in() ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Login requerido.' ), 401 );
		}
		$lesson_id = absint( $r->get_param( 'lesson_id' ) );
		$user_id   = get_current_user_id();

		// Verificar que la lección existe y obtener course_id
		$lesson = LMS_Course_Service::get_lesson( $lesson_id );
		if ( ! $lesson ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Lección no encontrada.' ), 404 );
		}

		// Verificar matrícula activa (P1 audit fix)
		$course_id   = absint( $lesson['course_id'] );
		$enrollment  = LMS_Enrollment_Service::get_enrollment( $user_id, $course_id );
		if ( ! $enrollment || 'active' !== ( $enrollment['status'] ?? '' ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'No tienes una matrícula activa en este curso.' ), 403 );
		}

		$ok       = LMS_Enrollment_Service::complete_lesson( $user_id, $lesson_id );
		$progress = $ok ? LMS_Enrollment_Service::get_progress( $user_id, $course_id ) : array();
		return new \WP_REST_Response( array( 'success' => $ok, 'progress' => $progress ), $ok ? 200 : 400 );
	}

	public static function migration_status( \WP_REST_Request $r ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'success' => true, 'status' => LMS_Migrator::get_status() ), 200 );
	}

	public static function run_migration( \WP_REST_Request $r ): \WP_REST_Response {
		$b      = $r->get_json_params() ?: array();
		$batch  = absint( $b['batch'] ?? 30 );
		$result = LMS_Migrator::migrate_all( $batch );
		return new \WP_REST_Response( array( 'success' => true, 'result' => $result, 'status' => LMS_Migrator::get_status() ), 200 );
	}

	// ── GET /courses/{id}/cohort (Fase II S6) ────────────────────────────────

	public static function cohort( \WP_REST_Request $r ): \WP_REST_Response {
		global $wpdb;

		$course_id = absint( $r->get_param( 'course_id' ) );
		$limit     = absint( $r->get_param( 'limit' )  ?: 50 );
		$offset    = absint( $r->get_param( 'offset' ) ?: 0 );

		$enroll_table   = $wpdb->prefix . 'atora_enrollments';
		$progress_table = $wpdb->prefix . 'atora_lesson_progress';
		$contacts_table = $wpdb->prefix . 'atora_contacts';
		$followup_table = $wpdb->prefix . 'atora_crm_student_followups';
		$users_table    = $wpdb->users;

		// Datos base desde atora_enrollments (SIN usermeta ni postmeta)
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT
					e.user_id,
					e.progress_pct,
					e.grade,
					e.enrolled_at,
					e.completed_at,
					e.last_activity,
					e.status      AS enroll_status,
					u.display_name,
					(
						SELECT COUNT(*) FROM {$progress_table} lp
						WHERE lp.user_id = e.user_id AND lp.course_id = e.course_id
						  AND lp.status = 'completed'
					) AS completed_lessons,
					c.conversion_score AS score,
					c.id              AS contact_id,
					sf.risk_level
				 FROM {$enroll_table} e
				 LEFT JOIN {$users_table} u  ON u.ID = e.user_id
				 LEFT JOIN {$contacts_table} c ON c.user_id = e.user_id
				 LEFT JOIN {$followup_table} sf ON sf.user_id = e.user_id AND sf.course_id = e.course_id
				 WHERE e.course_id = %d
				 ORDER BY e.progress_pct DESC
				 LIMIT %d OFFSET %d",
				$course_id, $limit, $offset
			),
			ARRAY_A
		);

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT COUNT(*) FROM {$enroll_table} WHERE course_id = %d", $course_id )
		);

		$items = array_map( function( array $row ): array {
			return array(
				'user_id'           => absint( $row['user_id'] ),
				'display_name'      => sanitize_text_field( (string) ( $row['display_name']   ?? '' ) ),
				'progress_pct'      => absint( $row['progress_pct'] ),
				'completed_lessons' => absint( $row['completed_lessons'] ),
				'grade'             => isset( $row['grade'] ) && $row['grade'] !== null ? (float) $row['grade'] : null,
				'last_activity'     => sanitize_text_field( (string) ( $row['last_activity']  ?? '' ) ),
				'enroll_status'     => sanitize_key(        (string) ( $row['enroll_status']  ?? 'active' ) ),
				'score'             => absint( $row['score'] ?? 0 ),
				'contact_id'        => absint( $row['contact_id'] ?? 0 ),
				'risk_level'        => sanitize_key( (string) ( $row['risk_level'] ?? 'normal' ) ),
			);
		}, $rows );

		return new \WP_REST_Response( array( 'success' => true, 'items' => $items, 'total' => $total ), 200 );
	}
}
