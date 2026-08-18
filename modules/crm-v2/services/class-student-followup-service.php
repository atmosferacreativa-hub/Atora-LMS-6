<?php
/**
 * Servicio de acompañamiento académico CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Student_Followup_Service {
	/**
	 * Etapas de acompañamiento.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function get_stages(): array {
		return array(
			'new_enrolled'   => array( 'label' => __( 'Nuevo inscrito', 'atora-lms' ), 'color' => '#0ea5e9' ),
			'onboarding'     => array( 'label' => __( 'En inducción', 'atora-lms' ), 'color' => '#3b82f6' ),
			'active'         => array( 'label' => __( 'Activo', 'atora-lms' ), 'color' => '#16a34a' ),
			'low_progress'   => array( 'label' => __( 'Bajo avance', 'atora-lms' ), 'color' => '#f59e0b' ),
			'at_risk'        => array( 'label' => __( 'En riesgo', 'atora-lms' ), 'color' => '#f97316' ),
			'intervention'   => array( 'label' => __( 'Intervención', 'atora-lms' ), 'color' => '#dc2626' ),
			'needs_support'  => array( 'label' => __( 'Requiere apoyo', 'atora-lms' ), 'color' => '#d946ef' ),
			'recovered'      => array( 'label' => __( 'Recuperado', 'atora-lms' ), 'color' => '#0f766e' ),
			'inactive'       => array( 'label' => __( 'Inactivo', 'atora-lms' ), 'color' => '#ef4444' ),
			'completed'      => array( 'label' => __( 'Completado', 'atora-lms' ), 'color' => '#0f766e' ),
			'graduated'      => array( 'label' => __( 'Egresado', 'atora-lms' ), 'color' => '#ca8a04' ),
		);
	}

	/**
	 * Sincroniza snapshot de estudiantes y devuelve tablero.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_board(): array {
		self::sync_from_lms();

		global $wpdb;
		$table = $wpdb->prefix . 'atora_crm_student_followups';
		if ( ! DB_Service::table_exists( $table ) ) {
			return array( 'stages' => self::get_stages(), 'items' => array() );
		}

		$rows = (array) $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY FIELD(stage,'new_enrolled','onboarding','active','low_progress','at_risk','intervention','needs_support','recovered','inactive','completed','graduated'), updated_at DESC",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$grouped = array();
		foreach ( array_keys( self::get_stages() ) as $stage_key ) {
			$grouped[ $stage_key ] = array();
		}

		foreach ( $rows as $row ) {
			$stage = sanitize_key( (string) ( $row['stage'] ?? 'active' ) );
			if ( ! isset( $grouped[ $stage ] ) ) {
				$grouped[ $stage ] = array();
			}
			$grouped[ $stage ][] = self::normalize_row( $row );
		}

		return array(
			'stages' => self::get_stages(),
			'items'  => $grouped,
		);
	}

	/**
	 * Devuelve mini tablero filtrado por etapas.
	 *
	 * @param array $args Argumentos.
	 * @return array<string,mixed>
	 */
	public static function get_mini_board( array $args = array() ): array {
		self::sync_from_lms();

		global $wpdb;
		$table = $wpdb->prefix . 'atora_crm_student_followups';
		if ( ! DB_Service::table_exists( $table ) ) {
			return array( 'stages' => array(), 'items' => array() );
		}

		$requested_stages = array_values( array_filter( array_map( 'sanitize_key', (array) ( $args['stages'] ?? array() ) ) ) );
		if ( empty( $requested_stages ) ) {
			$requested_stages = array( 'new_enrolled', 'active', 'at_risk', 'intervention' );
		}

		$stage_groups = array(
			'new_enrolled' => array( 'new_enrolled', 'onboarding' ),
			'active'       => array( 'active', 'recovered' ),
			'at_risk'      => array( 'at_risk', 'low_progress' ),
			'intervention' => array( 'intervention', 'needs_support', 'inactive' ),
			'completed'    => array( 'completed', 'graduated' ),
		);

		$stages = self::get_stages();
		$items  = array();
		$mini_stages = array();

		foreach ( $requested_stages as $stage_key ) {
			$mini_stages[ $stage_key ] = $stages[ $stage_key ] ?? array(
				'label' => ucwords( str_replace( '_', ' ', $stage_key ) ),
				'color' => '#64748b',
			);
			$items[ $stage_key ] = array();
		}

		$rows = (array) $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY updated_at DESC",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $rows as $row ) {
			$source_stage = sanitize_key( (string) ( $row['stage'] ?? 'active' ) );
			foreach ( $requested_stages as $target_stage ) {
				$allowed = $stage_groups[ $target_stage ] ?? array( $target_stage );
				if ( in_array( $source_stage, $allowed, true ) ) {
					$normalized              = self::normalize_row( $row );
					$normalized['mini_stage'] = $target_stage;
					$items[ $target_stage ][] = $normalized;
					break;
				}
			}
		}

		return array(
			'stages' => $mini_stages,
			'items'  => $items,
		);
	}

	/**
	 * Mueve estado académico manualmente.
	 *
	 * @param int    $followup_id ID.
	 * @param string $to_stage    Etapa.
	 * @return bool
	 */
	public static function move_followup( int $followup_id, string $to_stage ): bool {
		global $wpdb;

		$followup_id = absint( $followup_id );
		$to_stage    = sanitize_key( $to_stage );
		$table       = $wpdb->prefix . 'atora_crm_student_followups';
		$stages      = self::get_stages();
		if ( ! $followup_id || ! isset( $stages[ $to_stage ] ) || ! DB_Service::table_exists( $table ) ) {
			return false;
		}

		$current = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $followup_id ), ARRAY_A );
		if ( empty( $current ) ) {
			return false;
		}

		$updated = $wpdb->update(
			$table,
			array( 'stage' => $to_stage ),
			array( 'id' => $followup_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		$contact_id = absint( $current['contact_id'] ?? 0 );
		$user_id    = absint( $current['user_id'] ?? 0 );
		if ( $contact_id ) {
			Activity_Service::log_contact_activity(
				$contact_id,
				'academic_stage_changed',
				array(
					'followup_id' => $followup_id,
					'from_stage'  => sanitize_key( (string) ( $current['stage'] ?? '' ) ),
					'to_stage'    => $to_stage,
				)
			);
		}
		if ( $user_id ) {
			Activity_Service::log_user_activity( $user_id, 'crm_academic_stage_changed', array( 'followup_id' => $followup_id, 'to_stage' => $to_stage ) );
		}

		return true;
	}

	/**
	 * Conteo de estudiantes en riesgo.
	 *
	 * @return int
	 */
	public static function count_risk_students(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_crm_student_followups';
		if ( ! DB_Service::table_exists( $table ) ) {
			return 0;
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE risk_level IN ('high','critical') OR stage IN ('low_progress','at_risk','intervention','needs_support','inactive')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Calcula tasa de finalización.
	 *
	 * @return float
	 */
	public static function compute_completion_rate(): float {
		global $wpdb;

		self::sync_from_lms();

		$table = $wpdb->prefix . 'atora_crm_student_followups';
		if ( ! DB_Service::table_exists( $table ) ) {
			return 0.0;
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $total <= 0 ) {
			return 0.0;
		}

		$completed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE stage IN ('completed','graduated')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return round( ( $completed / $total ) * 100, 1 );
	}

	/**
	 * Cuenta seguimientos sin actualizar en N días.
	 *
	 * @param int $days Días.
	 * @return int
	 */
	public static function count_stale( int $days ): int {
		global $wpdb;

		self::sync_from_lms();

		$days  = max( 1, absint( $days ) );
		$table = $wpdb->prefix . 'atora_crm_student_followups';
		if ( ! DB_Service::table_exists( $table ) ) {
			return 0;
		}

		$threshold = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $days . ' days', current_time( 'timestamp', true ) ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE updated_at < %s
				   AND stage NOT IN ('completed','graduated')",
				$threshold
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Devuelve seguimientos con mayor riesgo.
	 *
	 * @param int $limit Límite.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_top_risk( int $limit = 5 ): array {
		global $wpdb;

		self::sync_from_lms();

		$limit = max( 1, min( 50, absint( $limit ) ) );
		$table = $wpdb->prefix . 'atora_crm_student_followups';
		if ( ! DB_Service::table_exists( $table ) ) {
			return array();
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 ORDER BY FIELD(risk_level,'critical','high','medium','normal'), progress_percent ASC, updated_at ASC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( __CLASS__, 'normalize_row' ), $rows );
	}

	/**
	 * Sincroniza tabla local con datos LMS.
	 *
	 * @return void
	 */
	public static function sync_from_lms(): void {
		global $wpdb;

		$table          = $wpdb->prefix . 'atora_crm_student_followups';
		$contacts_table = $wpdb->prefix . 'atora_contacts';
		if ( ! DB_Service::table_exists( $table ) || ! DB_Service::table_exists( $contacts_table ) ) {
			return;
		}

		$contacts = (array) $wpdb->get_results(
			"SELECT id, user_id, status FROM {$contacts_table} WHERE user_id > 0 AND status IN ('student','alumni') ORDER BY updated_at DESC LIMIT 300",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$academic_service = class_exists( 'CLMS_Academic_Status_Service' ) ? new \CLMS_Academic_Status_Service() : null;

		foreach ( $contacts as $contact ) {
			$contact_id = absint( $contact['id'] ?? 0 );
			$user_id    = absint( $contact['user_id'] ?? 0 );
			if ( ! $contact_id || ! $user_id ) {
				continue;
			}

			$course_ids = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_user_enrolled_courses' )
				? array_values( array_filter( array_map( 'absint', (array) ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $user_id ) ) ) ) )
				: array();
			$course_id  = absint( $course_ids[0] ?? 0 );

			$status = array();
			if ( $academic_service && $course_id && method_exists( $academic_service, 'get_student_course_status' ) ) {
				$status = (array) $academic_service->get_student_course_status( $user_id, $course_id, array( 'skip_improvement_plan' => true ) );
			}

			$progress = absint( $status['progress_percent'] ?? ( $course_id ? get_user_meta( $user_id, 'clms_course_' . $course_id . '_progress', true ) : 0 ) );
			$pending  = absint( $status['pending_activities'] ?? 0 );
			$risk     = sanitize_key( (string) ( $status['risk_level'] ?? 'normal' ) );
			$last     = sanitize_text_field( (string) ( $status['last_access_at'] ?? ( $course_id ? get_user_meta( $user_id, '_clms_last_access_course_' . $course_id, true ) : '' ) ) );
			$stage    = self::resolve_stage( $progress, $pending, $risk, $last, sanitize_key( (string) ( $contact['status'] ?? 'student' ) ) );
			$next_action = self::resolve_next_action( $stage, $pending );

			$exists_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d AND course_id = %d LIMIT 1", $user_id, $course_id )
			);

			$row = array(
				'contact_id'          => $contact_id,
				'user_id'             => $user_id,
				'course_id'           => $course_id,
				'stage'               => $stage,
				'progress_percent'    => max( 0, min( 100, $progress ) ),
				'pending_activities'  => $pending,
				'risk_level'          => $risk ?: 'normal',
				'last_access_at'      => '' !== $last ? gmdate( 'Y-m-d H:i:s', strtotime( $last ) ) : null,
				'next_action'         => $next_action,
				'assigned_to'         => get_current_user_id(),
			);

			if ( $exists_id > 0 ) {
				$wpdb->update(
					$table,
					$row,
					array( 'id' => $exists_id ),
					array( '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d' ),
					array( '%d' )
				);
			} else {
				$wpdb->insert(
					$table,
					$row,
					array( '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d' )
				);
			}

			if ( $pending > 0 && in_array( $stage, array( 'low_progress', 'at_risk', 'needs_support' ), true ) ) {
				Task_Service::create_task(
					array(
						'title'       => __( 'Seguimiento académico por actividad pendiente', 'atora-lms' ),
						'contact_id'  => $contact_id,
						'user_id'     => $user_id,
						'related_type'=> 'academic_followup',
						'related_id'  => $course_id,
						'task_type'   => 'tutoring',
						'priority'    => 'high',
						'due_at'      => gmdate( 'Y-m-d H:i:s', strtotime( '+2 days', current_time( 'timestamp', true ) ) ),
					)
				);
			}
		}
	}

	/**
	 * Determina etapa académica inicial.
	 *
	 * @param int    $progress      Progreso.
	 * @param int    $pending       Pendientes.
	 * @param string $risk          Riesgo.
	 * @param string $last_access   Último acceso.
	 * @param string $contact_state Estado de contacto.
	 * @return string
	 */
	private static function resolve_stage( int $progress, int $pending, string $risk, string $last_access, string $contact_state ): string {
		if ( 'alumni' === $contact_state || $progress >= 95 ) {
			return 'completed';
		}

		$days_inactive = 0;
		if ( '' !== $last_access ) {
			$last_ts = strtotime( $last_access );
			if ( $last_ts ) {
				$days_inactive = (int) floor( ( current_time( 'timestamp' ) - $last_ts ) / DAY_IN_SECONDS );
			}
		}

		if ( $days_inactive >= 21 ) {
			return 'inactive';
		}
		if ( $pending >= 5 || 'critical' === $risk ) {
			return 'intervention';
		}
		if ( $days_inactive >= 14 || 'high' === $risk ) {
			return 'at_risk';
		}
		if ( $days_inactive >= 7 || $progress < 35 ) {
			return 'low_progress';
		}
		if ( $pending >= 3 ) {
			return 'needs_support';
		}
		if ( $progress < 12 ) {
			return 'onboarding';
		}

		return 'active';
	}

	/**
	 * Próxima acción sugerida por etapa.
	 *
	 * @param string $stage   Etapa.
	 * @param int    $pending Pendientes.
	 * @return string
	 */
	private static function resolve_next_action( string $stage, int $pending ): string {
		switch ( $stage ) {
			case 'onboarding':
				return __( 'Enviar guía de inducción y primera meta semanal.', 'atora-lms' );
			case 'low_progress':
				return __( 'Recordar plan de estudio y sugerir bloque de recuperación.', 'atora-lms' );
			case 'at_risk':
				return __( 'Contactar en menos de 24h y agendar tutoría.', 'atora-lms' );
			case 'intervention':
				return __( 'Escalar al equipo académico y activar intervención intensiva.', 'atora-lms' );
			case 'needs_support':
				return sprintf( __( 'Resolver %d actividad(es) pendiente(s) con acompañamiento docente.', 'atora-lms' ), $pending );
			case 'inactive':
				return __( 'Campaña de reactivación académica con incentivo de retorno.', 'atora-lms' );
			case 'graduated':
			case 'completed':
				return __( 'Invitar a certificación y siguiente ruta formativa.', 'atora-lms' );
			default:
				return __( 'Mantener seguimiento semanal de avance.', 'atora-lms' );
		}
	}

	/**
	 * Normaliza fila de seguimiento.
	 *
	 * @param array $row DB row.
	 * @return array<string,mixed>
	 */
	private static function normalize_row( array $row ): array {
		$stage  = sanitize_key( (string) ( $row['stage'] ?? 'active' ) );
		$stages = self::get_stages();

		return array(
			'id'                 => absint( $row['id'] ?? 0 ),
			'contact_id'         => absint( $row['contact_id'] ?? 0 ),
			'user_id'            => absint( $row['user_id'] ?? 0 ),
			'course_id'          => absint( $row['course_id'] ?? 0 ),
			'course_title'       => absint( $row['course_id'] ?? 0 ) ? get_the_title( absint( $row['course_id'] ) ) : '',
			'stage'              => $stage,
			'stage_label'        => (string) ( $stages[ $stage ]['label'] ?? $stage ),
			'progress_percent'   => absint( $row['progress_percent'] ?? 0 ),
			'pending_activities' => absint( $row['pending_activities'] ?? 0 ),
			'risk_level'         => sanitize_key( (string) ( $row['risk_level'] ?? 'normal' ) ),
			'last_access_at'     => sanitize_text_field( (string) ( $row['last_access_at'] ?? '' ) ),
			'next_action'        => sanitize_text_field( (string) ( $row['next_action'] ?? '' ) ),
			'assigned_to'        => absint( $row['assigned_to'] ?? 0 ),
			'updated_at'         => sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) ),
		);
	}
}
