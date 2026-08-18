<?php
/**
 * Servicio de tareas y agenda CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Task_Service {
	/**
	 * Lista de tipos de tarea.
	 *
	 * @return array<string,string>
	 */
	public static function get_task_types(): array {
		return array(
			'commercial_call' => __( 'Llamada comercial', 'atora-lms' ),
			'followup_email'  => __( 'Email de seguimiento', 'atora-lms' ),
			'tutoring'        => __( 'Tutoría', 'atora-lms' ),
			'grading'         => __( 'Evaluación pendiente', 'atora-lms' ),
			'campaign'        => __( 'Campaña', 'atora-lms' ),
			'payment'         => __( 'Pago pendiente', 'atora-lms' ),
			'academic_event'  => __( 'Evento académico', 'atora-lms' ),
		);
	}

	/**
	 * Crea una tarea.
	 *
	 * @param array $data Datos.
	 * @return int
	 */
	public static function create_task( array $data ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_crm_tasks';
		if ( ! DB_Service::table_exists( $table ) ) {
			return 0;
		}

		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title ) {
			return 0;
		}

		$task_type = sanitize_key( (string) ( $data['task_type'] ?? 'followup_email' ) );
		if ( ! isset( self::get_task_types()[ $task_type ] ) ) {
			$task_type = 'followup_email';
		}

		$priority = sanitize_key( (string) ( $data['priority'] ?? 'medium' ) );
		if ( ! in_array( $priority, array( 'low', 'medium', 'high', 'urgent' ), true ) ) {
			$priority = 'medium';
		}

		$status = sanitize_key( (string) ( $data['status'] ?? 'pending' ) );
		if ( ! in_array( $status, array( 'pending', 'in_progress', 'completed', 'canceled' ), true ) ) {
			$status = 'pending';
		}

		$contact_id = absint( $data['contact_id'] ?? 0 );
		$user_id    = absint( $data['user_id'] ?? 0 );
		$due_at     = self::sanitize_datetime( (string) ( $data['due_at'] ?? '' ) );
		$assigned_to = absint( $data['assigned_to'] ?? get_current_user_id() );

		$inserted = $wpdb->insert(
			$table,
			array(
				'title'        => $title,
				'contact_id'   => $contact_id,
				'user_id'      => $user_id,
				'related_type' => sanitize_key( (string) ( $data['related_type'] ?? '' ) ),
				'related_id'   => absint( $data['related_id'] ?? 0 ),
				'task_type'    => $task_type,
				'priority'     => $priority,
				'status'       => $status,
				'due_at'       => $due_at,
				'assigned_to'  => $assigned_to,
				'notes'        => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
			),
			array( '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return 0;
		}

		$task_id = absint( $wpdb->insert_id );
		if ( $contact_id ) {
			Activity_Service::log_contact_activity(
				$contact_id,
				'task_created',
				array(
					'task_id'   => $task_id,
					'task_type' => $task_type,
					'priority'  => $priority,
					'due_at'    => $due_at,
				)
			);
		}

		if ( $user_id ) {
			Activity_Service::log_user_activity(
				$user_id,
				'crm_task_created',
				array(
					'task_id'   => $task_id,
					'task_type' => $task_type,
				)
			);
		}

		return $task_id;
	}

	/**
	 * Completa tarea.
	 *
	 * @param int $task_id ID.
	 * @return bool
	 */
	public static function complete_task( int $task_id ): bool {
		global $wpdb;

		$task_id = absint( $task_id );
		$table   = $wpdb->prefix . 'atora_crm_tasks';
		if ( ! $task_id || ! DB_Service::table_exists( $table ) ) {
			return false;
		}

		$task = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $task_id ), ARRAY_A );
		if ( empty( $task ) ) {
			return false;
		}

		$updated = $wpdb->update(
			$table,
			array(
				'status'       => 'completed',
				'completed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $task_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		$contact_id = absint( $task['contact_id'] ?? 0 );
		$user_id    = absint( $task['user_id'] ?? 0 );
		if ( $contact_id ) {
			Activity_Service::log_contact_activity(
				$contact_id,
				'task_completed',
				array(
					'task_id'   => $task_id,
					'task_type' => sanitize_key( (string) ( $task['task_type'] ?? '' ) ),
				)
			);
		}
		if ( $user_id ) {
			Activity_Service::log_user_activity( $user_id, 'crm_task_completed', array( 'task_id' => $task_id ) );
		}

		return true;
	}

	/**
	 * Lista tareas.
	 *
	 * @param array $args Filtros.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public static function list_tasks( array $args = array() ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_crm_tasks';
		if ( ! DB_Service::table_exists( $table ) ) {
			return array( 'items' => array(), 'total' => 0 );
		}

		$defaults = array(
			'status'         => '',
			'task_type'      => '',
			'scope'          => '',
			'assigned_to'    => 0,
			'contact_id'     => 0,
			'scope_user_ids' => null,
			'limit'          => 40,
			'offset'         => 0,
			'date_from'      => '',
			'date_to'        => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$clauses = array( '1=1' );
		$params  = array();

		$status = sanitize_key( (string) $args['status'] );
		if ( '' !== $status ) {
			$clauses[] = 'status = %s';
			$params[]  = $status;
		}

		$task_type = sanitize_key( (string) $args['task_type'] );
		if ( '' !== $task_type ) {
			$clauses[] = 'task_type = %s';
			$params[]  = $task_type;
		}

		$scope = sanitize_key( (string) $args['scope'] );
		if ( 'commercial' === $scope ) {
			$clauses[] = "task_type IN ('commercial_call','followup_email','campaign','payment')";
		} elseif ( 'academic' === $scope ) {
			$clauses[] = "task_type IN ('tutoring','grading','academic_event')";
		}

		$assigned_to = absint( $args['assigned_to'] );
		if ( $assigned_to > 0 ) {
			$clauses[] = 'assigned_to = %d';
			$params[]  = $assigned_to;
		}

		$contact_id = absint( $args['contact_id'] );
		if ( $contact_id > 0 ) {
			$clauses[] = 'contact_id = %d';
			$params[]  = $contact_id;
		}

		$scope_user_ids = $args['scope_user_ids'];
		if ( is_array( $scope_user_ids ) ) {
			$scope_user_ids = array_values( array_filter( array_map( 'absint', $scope_user_ids ) ) );
			if ( empty( $scope_user_ids ) ) {
				$clauses[] = '1=0';
			} else {
				$in_users = implode( ',', array_fill( 0, count( $scope_user_ids ), '%d' ) );
				$clauses[] = "(user_id IN ({$in_users}) OR contact_id IN (SELECT id FROM {$wpdb->prefix}atora_contacts WHERE user_id IN ({$in_users})))";
				foreach ( $scope_user_ids as $scope_user_id ) {
					$params[] = $scope_user_id;
				}
				foreach ( $scope_user_ids as $scope_user_id ) {
					$params[] = $scope_user_id;
				}
			}
		}

		$date_from = self::sanitize_datetime( (string) $args['date_from'] );
		if ( '' !== $date_from ) {
			$clauses[] = 'due_at >= %s';
			$params[]  = $date_from;
		}

		$date_to = self::sanitize_datetime( (string) $args['date_to'] );
		if ( '' !== $date_to ) {
			$clauses[] = 'due_at <= %s';
			$params[]  = $date_to;
		}

		$where = implode( ' AND ', $clauses );
		$total = (int) $wpdb->get_var(
			empty( $params )
				? "SELECT COUNT(*) FROM {$table} WHERE {$where}" // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				: $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", ...$params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$limit  = max( 1, min( 300, absint( $args['limit'] ) ) );
		$offset = max( 0, absint( $args['offset'] ) );

		$list_params   = $params;
		$list_params[] = $limit;
		$list_params[] = $offset;
		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY status = 'pending' DESC, due_at ASC, created_at DESC LIMIT %d OFFSET %d";

		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}

	/**
	 * Conteo de tareas vencidas.
	 *
	 * @return int
	 */
	public static function count_overdue(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_crm_tasks';
		if ( ! DB_Service::table_exists( $table ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','in_progress') AND due_at IS NOT NULL AND due_at < %s",
				current_time( 'mysql', true )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Conteo de tareas próximas (48h).
	 *
	 * @return int
	 */
	public static function count_upcoming(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_crm_tasks';
		if ( ! DB_Service::table_exists( $table ) ) {
			return 0;
		}

		$from = current_time( 'mysql', true );
		$to   = gmdate( 'Y-m-d H:i:s', strtotime( '+48 hours', current_time( 'timestamp', true ) ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','in_progress') AND due_at BETWEEN %s AND %s",
				$from,
				$to
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Conteo de tareas pendientes por tipo.
	 *
	 * @param string $task_type Tipo.
	 * @return int
	 */
	public static function count_by_type( string $task_type ): int {
		global $wpdb;

		$task_type = sanitize_key( $task_type );
		$table     = $wpdb->prefix . 'atora_crm_tasks';
		if ( '' === $task_type || ! DB_Service::table_exists( $table ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','in_progress') AND task_type = %s",
				$task_type
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Normaliza datetime a Y-m-d H:i:s.
	 *
	 * @param string $value Valor.
	 * @return string
	 */
	private static function sanitize_datetime( string $value ): string {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			return '';
		}

		$dt = date_create( $value, wp_timezone() );
		if ( ! $dt ) {
			return '';
		}
		$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
		return $dt->format( 'Y-m-d H:i:s' );
	}
}
