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
}
