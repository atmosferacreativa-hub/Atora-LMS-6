<?php
/**
 * CLMS_Audit_Log_Service — P5 (sprint 6.12.0)
 *
 * Log de auditoría de acciones administrativas, transversal a módulos:
 * quién matriculó, quién cambió una nota, quién exportó datos. Es lo que
 * se enseña en la evaluación institucional del perfil `institucion` —
 * ver includes/settings/class-institucion-settings-page.php.
 *
 * No re-instrumenta nada desde cero: se engancha a puntos que ya
 * disparaban una acción (atora/lms/enrolled, clms_gradebook_audit_logged,
 * atora/gradebook/exported), así que el log queda poblado sin tocar la
 * lógica de negocio existente.
 *
 * @package ATORA_LMS
 * @since   6.12.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Audit_Log_Service {

	public static function init(): void {
		add_action( 'atora/lms/enrolled', array( __CLASS__, 'on_enrolled' ), 10, 3 );
		add_action( 'clms_gradebook_audit_logged', array( __CLASS__, 'on_grade_changed' ), 10, 2 );
		add_action( 'atora/gradebook/exported', array( __CLASS__, 'on_gradebook_exported' ), 10, 3 );
	}

	/**
	 * @param int $user_id   Estudiante matriculado.
	 * @param int $course_id Curso.
	 * @param int $enroll_id ID de la matrícula.
	 */
	public static function on_enrolled( $user_id, $course_id, $enroll_id ): void {
		self::log(
			get_current_user_id(),
			'enrollment_created',
			'course',
			absint( $course_id ),
			array( 'student_id' => absint( $user_id ), 'enrollment_id' => absint( $enroll_id ) )
		);
	}

	/**
	 * @param int   $submission_id
	 * @param array $entry Entrada ya armada por CLMS_Gradebook_Audit_Service::log().
	 */
	public static function on_grade_changed( $submission_id, $entry ): void {
		$entry = is_array( $entry ) ? $entry : array();
		self::log(
			absint( $entry['actor_id'] ?? get_current_user_id() ),
			'grade_changed',
			'submission',
			absint( $submission_id ),
			array(
				'action'     => sanitize_key( (string) ( $entry['action'] ?? '' ) ),
				'grade_prev' => $entry['grade_prev'] ?? '',
				'grade_new'  => $entry['grade_new'] ?? '',
			)
		);
	}

	/**
	 * @param int $actor_id
	 * @param int $course_id
	 * @param int $row_count
	 */
	public static function on_gradebook_exported( $actor_id, $course_id, $row_count ): void {
		self::log(
			absint( $actor_id ),
			'gradebook_exported',
			'course',
			absint( $course_id ),
			array( 'rows' => absint( $row_count ) )
		);
	}

	/**
	 * @param int    $actor_id
	 * @param string $action      Slug corto, p.ej. 'enrollment_created'.
	 * @param string $object_type p.ej. 'course', 'submission'.
	 * @param int    $object_id
	 * @param array  $details     Datos adicionales, se guardan como JSON.
	 */
	public static function log( int $actor_id, string $action, string $object_type, int $object_id, array $details = array() ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'atora_audit_log',
			array(
				'actor_id'     => $actor_id,
				'action'       => sanitize_key( $action ),
				'object_type'  => sanitize_key( $object_type ),
				'object_id'    => $object_id,
				'details_json' => wp_json_encode( $details ),
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * @param int   $limit
	 * @param array $filters {action?: string, object_type?: string}
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_recent( int $limit = 50, array $filters = array() ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = sanitize_key( (string) $filters['action'] );
		}
		if ( ! empty( $filters['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$params[] = sanitize_key( (string) $filters['object_type'] );
		}

		$params[] = max( 1, $limit );

		$table = $wpdb->prefix . 'atora_audit_log';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
	}
}
