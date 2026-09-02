<?php
/**
 * CLMS_Attendance_Academic_Bridge — P9 (sprint 6.13.0)
 *
 * Conecta atora_attendance (P6) con el resto del producto académico:
 * N ausencias consecutivas mueve al estudiante a la cola de seguimiento
 * del docente, y expone el dato para el gradebook y el panel "Hoy".
 * Es lo que ningún competidor de WordPress tiene, y es rápido de cablear
 * una vez que P6 existe la tabla real de asistencia.
 *
 * @package ATORA_LMS
 * @since   6.13.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Attendance_Academic_Bridge {

	public static function init(): void {
		add_action( 'atora/attendance/recorded', array( __CLASS__, 'on_attendance_recorded' ), 10, 3 );
	}

	/**
	 * @param int    $user_id
	 * @param int    $course_id
	 * @param string $status presente|tarde|ausente|justificado
	 */
	public static function on_attendance_recorded( int $user_id, int $course_id, string $status ): void {
		// C7.3 (6.13.1): anónimos (user_id = 0, participantes de Meet sin
		// identidad) no tienen racha propia — no deben entrar al cálculo
		// ni disparar seguimiento sobre "el estudiante 0".
		if ( 'ausente' !== $status || ! $user_id || ! $course_id ) {
			return;
		}

		$threshold = (int) apply_filters( 'atora/attendance/consecutive_absence_threshold', 3 );
		if ( self::has_consecutive_absences( $user_id, $course_id, $threshold ) ) {
			self::move_to_followup_queue( $user_id, $course_id, $threshold );
		}
	}

	/**
	 * @param int $user_id
	 * @param int $course_id
	 * @param int $threshold
	 * @return bool true si las últimas $threshold asistencias son todas 'ausente'.
	 */
	public static function has_consecutive_absences( int $user_id, int $course_id, int $threshold ): bool {
		global $wpdb;

		$table    = $wpdb->prefix . 'atora_attendance';
		$sessions = $wpdb->prefix . 'atora_live_sessions';

		// C7.1 (6.13.1): ordenar por cuándo fue la clase (start_datetime),
		// no por cuándo se insertó la fila (created_at) — un docente que
		// registra hoy la asistencia de la semana pasada no debe disparar
		// una alerta de racha basada en el orden de captura. Asistencia
		// manual sin sesión (session_id = 0) cae al fallback de created_at,
		// que es lo único que tiene.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$statuses = $wpdb->get_col( $wpdb->prepare(
			"SELECT a.status FROM {$table} a LEFT JOIN {$sessions} s ON s.id = a.session_id
			 WHERE a.user_id = %d AND a.course_id = %d
			 ORDER BY COALESCE( s.start_datetime, a.created_at ) DESC LIMIT %d",
			$user_id, $course_id, $threshold
		) );

		if ( count( $statuses ) < $threshold ) {
			return false;
		}

		return count( array_filter( $statuses, static fn( $s ) => 'ausente' === $s ) ) === $threshold;
	}

	/**
	 * Mueve al estudiante a la etapa 'at_risk' del tablero de seguimiento
	 * — y, a diferencia de la versión original (P9, 6.13.0), crea la
	 * ficha si no existe (C7.2, 6.13.1). Antes, un alumno que nunca había
	 * recibido seguimiento —el que más lo necesita— nunca se marcaba,
	 * porque el método salía sin hacer nada si no encontraba una fila.
	 *
	 * @param int $user_id
	 * @param int $course_id
	 * @param int $threshold Solo para el log de actividad.
	 */
	private static function move_to_followup_queue( int $user_id, int $course_id, int $threshold ): void {
		if ( ! class_exists( '\ATORA\CRM_V2\Services\Student_Followup_Service' ) ) {
			return;
		}

		global $wpdb;
		$table       = $wpdb->prefix . 'atora_crm_student_followups';
		$followup_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE user_id = %d AND course_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$user_id, $course_id
		) );

		if ( $followup_id ) {
			\ATORA\CRM_V2\Services\Student_Followup_Service::move_followup( $followup_id, 'at_risk' );
		} else {
			$followup_id = self::create_followup_at_risk( $user_id, $course_id );
			if ( ! $followup_id ) {
				return; // Sin contacto asociado a este usuario — no hay dónde crear la ficha (ver create_followup_at_risk()).
			}
		}

		if ( class_exists( '\ATORA\CRM_V2\Services\Activity_Service' ) ) {
			\ATORA\CRM_V2\Services\Activity_Service::log_user_activity(
				$user_id,
				'attendance_consecutive_absences',
				array( 'course_id' => $course_id, 'threshold' => $threshold )
			);
		}
	}

	/**
	 * Crea una ficha de seguimiento nueva en 'at_risk' — requiere que el
	 * usuario ya tenga un contacto CRM asociado (contact_id es obligatorio
	 * en atora_crm_student_followups); si no lo tiene, no hay dónde
	 * insertar y se omite: crear el contacto en sí es responsabilidad de
	 * Contact_Service, fuera de alcance de este bridge.
	 *
	 * @param int $user_id
	 * @param int $course_id
	 * @return int ID de la ficha creada, 0 si no se pudo.
	 */
	private static function create_followup_at_risk( int $user_id, int $course_id ): int {
		global $wpdb;

		$contacts_table = $wpdb->prefix . 'atora_contacts';
		$contact_id     = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$contacts_table} WHERE user_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$user_id
		) );

		if ( ! $contact_id ) {
			return 0;
		}

		$table = $wpdb->prefix . 'atora_crm_student_followups';
		$wpdb->insert(
			$table,
			array(
				'contact_id'         => $contact_id,
				'user_id'            => $user_id,
				'course_id'          => $course_id,
				'stage'              => 'at_risk',
				'progress_percent'   => 0,
				'pending_activities' => 0,
				'risk_level'         => 'high',
				'next_action'        => 'attendance_consecutive_absences',
				'assigned_to'        => 0,
			),
			array( '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%d' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Porcentaje de asistencia de un estudiante en un curso — componente
	 * opcional para el gradebook (P9, 6.13.0). La integración como
	 * columna de peso configurable en el grid queda para un follow-up:
	 * esto expone el cálculo para que CLMS_Gradebook_Calculation_Service
	 * pueda consumirlo vía un weight_group una vez que el curso lo active
	 * explícitamente en su schema.
	 *
	 * @param int $user_id
	 * @param int $course_id
	 * @return float 0-100.
	 */
	public static function get_attendance_percentage( int $user_id, int $course_id ): float {
		global $wpdb;

		$table  = $wpdb->prefix . 'atora_attendance';
		$counts = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) AS total, SUM(status IN ('presente','tarde')) AS present FROM {$table} WHERE user_id = %d AND course_id = %d",
			$user_id, $course_id
		) );

		$total = (int) ( $counts->total ?? 0 );
		if ( ! $total ) {
			return 0.0;
		}

		return round( ( (int) ( $counts->present ?? 0 ) / $total ) * 100, 1 );
	}

	/**
	 * @param int $teacher_id
	 * @param int $threshold
	 * @return array<int,array{user_id:int,course_id:int,course_title:string,absences:int}>
	 */
	public static function get_at_risk_students_for_teacher( int $teacher_id, int $threshold = 3 ): array {
		$course_ids = get_posts( array(
			'post_type'      => 'lm_course',
			'author'         => $teacher_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		if ( ! $course_ids ) {
			return array();
		}

		global $wpdb;
		$table        = $wpdb->prefix . 'atora_attendance';
		$sessions     = $wpdb->prefix . 'atora_live_sessions';
		$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );

		// C7.1/C7.3 (6.13.1): orden por start_datetime de la sesión (con
		// fallback a created_at para asistencia manual sin sesión), y
		// user_id = 0 (anónimos) excluido — no tienen racha propia.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.user_id, a.course_id, a.status FROM {$table} a LEFT JOIN {$sessions} s ON s.id = a.session_id
			 WHERE a.course_id IN ({$placeholders}) AND a.user_id > 0
			 ORDER BY a.user_id, a.course_id, COALESCE( s.start_datetime, a.created_at ) DESC",
			...$course_ids
		) );

		$grouped = array();
		foreach ( (array) $rows as $row ) {
			$key = $row->user_id . ':' . $row->course_id;
			$grouped[ $key ][] = $row->status;
		}

		$results = array();
		foreach ( $grouped as $key => $statuses ) {
			$streak = 0;
			foreach ( $statuses as $status ) {
				if ( 'ausente' !== $status ) { break; }
				$streak++;
			}
			if ( $streak >= $threshold ) {
				list( $user_id, $course_id ) = array_map( 'absint', explode( ':', $key ) );
				$results[] = array(
					'user_id'      => $user_id,
					'course_id'    => $course_id,
					'course_title' => get_the_title( $course_id ),
					'absences'     => $streak,
				);
			}
		}

		return $results;
	}
}
