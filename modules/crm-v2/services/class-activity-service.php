<?php
/**
 * Servicio de actividad y timeline CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activity_Service {
	/**
	 * Registra actividad para un contacto.
	 *
	 * @param int    $contact_id Contacto.
	 * @param string $type       Tipo de actividad.
	 * @param array  $payload    Datos.
	 * @param int    $created_by Autor.
	 * @return void
	 */
	public static function log_contact_activity( int $contact_id, string $type, array $payload = array(), int $created_by = 0, string $linked_entity_type = '', int $linked_entity_id = 0 ): void {
		global $wpdb;

		$contact_id = absint( $contact_id );
		$type       = sanitize_key( $type );
		$created_by = absint( $created_by ?: get_current_user_id() );
		if ( ! $contact_id || '' === $type ) {
			return;
		}

		$table = $wpdb->prefix . 'atora_contact_activities';
		if ( ! DB_Service::table_exists( $table ) ) {
			return;
		}

		$row    = array(
			'contact_id'    => $contact_id,
			'activity_type' => $type,
			'activity_data' => wp_json_encode( $payload ),
			'created_by'    => $created_by,
		);
		$format = array( '%d', '%s', '%s', '%d' );

		if ( '' !== $linked_entity_type && $linked_entity_id > 0 ) {
			$col_exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
					 WHERE TABLE_SCHEMA = DATABASE()
					   AND TABLE_NAME   = %s
					   AND COLUMN_NAME  = 'linked_entity_type'",
					$table
				)
			);
			if ( $col_exists > 0 ) {
				$row['linked_entity_type'] = sanitize_key( $linked_entity_type );
				$row['linked_entity_id']   = absint( $linked_entity_id );
				$format[]                  = '%s';
				$format[]                  = '%d';
			}
		}

		$wpdb->insert( $table, $row, $format );
	}

	/**
	 * Registra actividad por usuario si CRM legacy está disponible.
	 *
	 * @param int    $user_id Usuario.
	 * @param string $type    Tipo.
	 * @param array  $payload Datos.
	 * @return void
	 */
	public static function log_user_activity( int $user_id, string $type, array $payload = array() ): void {
		$user_id = absint( $user_id );
		$type    = sanitize_key( $type );
		if ( ! $user_id || '' === $type ) {
			return;
		}

		if ( class_exists( '\\ATORA\\CRM\\CRM' ) && method_exists( '\\ATORA\\CRM\\CRM', 'log_activity' ) ) {
			\ATORA\CRM\CRM::log_activity( $user_id, $type, $payload );
		}
	}

	/**
	 * PT-3.1 (6.9.0, feed de "Actividad"): cambios de etapa a mejor
	 * registrados por $user_id en los últimos $days días, en cualquier
	 * dominio — lectura puntual nueva sobre atora_contact_activities,
	 * la misma tabla que move_followup()/move_deal() ya escriben (sin
	 * tocar esos métodos). "A mejor" se define reutilizando el mismo
	 * conjunto de etapas de riesgo académico que
	 * CLMS_Today_Aggregator_Service::ACADEMIC_HIGH_RISK_STAGES (6.8.0)
	 * ya usa: de una etapa de riesgo a una que no lo es. En comercial,
	 * "a mejor" es llegar a won/enrolled, o salir de lost/reactivate_later.
	 *
	 * @param int $user_id
	 * @param int $days
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_recent_stage_improvements_for_user( int $user_id, int $days = 7 ): array {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return array();
		}

		$table          = $wpdb->prefix . 'atora_contact_activities';
		$contacts_table = $wpdb->prefix . 'atora_contacts';
		if ( ! DB_Service::table_exists( $table ) || ! DB_Service::table_exists( $contacts_table ) ) {
			return array();
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . max( 1, absint( $days ) ) . ' days', current_time( 'timestamp', true ) ) );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.contact_id, a.activity_type, a.activity_data, a.created_at,
				        c.name AS contact_name
				 FROM {$table} a
				 INNER JOIN {$contacts_table} c ON c.id = a.contact_id
				 WHERE a.created_by = %d
				   AND a.activity_type IN ('academic_stage_changed', 'deal_stage_changed')
				   AND a.created_at >= %s
				 ORDER BY a.created_at DESC",
				$user_id,
				$cutoff
			),
			ARRAY_A
		);

		$academic_high_risk = array( 'at_risk', 'intervention', 'needs_support' );
		$commercial_lost    = array( 'lost', 'reactivate_later' );
		$results            = array();

		foreach ( $rows as $row ) {
			$payload   = json_decode( (string) ( $row['activity_data'] ?? '' ), true );
			$payload   = is_array( $payload ) ? $payload : array();
			$from      = sanitize_key( (string) ( $payload['from_stage'] ?? '' ) );
			$to        = sanitize_key( (string) ( $payload['to_stage'] ?? '' ) );
			$is_deal   = 'deal_stage_changed' === $row['activity_type'];

			$is_improvement = $is_deal
				? ( in_array( $to, array( 'won', 'enrolled' ), true ) || ( in_array( $from, $commercial_lost, true ) && ! in_array( $to, $commercial_lost, true ) ) )
				: ( in_array( $from, $academic_high_risk, true ) && ! in_array( $to, $academic_high_risk, true ) );

			if ( ! $is_improvement ) {
				continue;
			}

			$results[] = array(
				'contact_id'   => absint( $row['contact_id'] ?? 0 ),
				'contact_name' => sanitize_text_field( (string) ( $row['contact_name'] ?? '' ) ),
				'domain'       => $is_deal ? 'commercial' : 'academic',
				'from_stage'   => $from,
				'to_stage'     => $to,
				'created_at'   => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
			);
		}

		return $results;
	}
}
