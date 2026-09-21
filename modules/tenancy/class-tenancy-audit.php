<?php
/**
 * Tenancy_Audit_Service — bitácora de tenencia y delegación.
 *
 * @package ATORA_LMS\Tenancy
 * @since   6.26.3
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Tenancy_Audit_Service {

	/**
	 * Registra una acción en atora_tenancy_audit.
	 *
	 * @param int   $institution_id Institución.
	 * @param int   $actor_id       Actor real (asistente/coordinador/admin).
	 * @param int   $on_behalf_of   Titular (si aplica).
	 * @param int   $target_user_id Usuario objetivo (si aplica).
	 * @param string $object_type   Tipo (delegation, cohort, enrollment, etc).
	 * @param int   $object_id      ID del objeto.
	 * @param string $action        Acción (grant, revoke, etc).
	 * @param array  $payload       Payload (JSON).
	 * @return void
	 */
	public static function record( int $institution_id, int $actor_id, int $on_behalf_of, int $target_user_id, string $object_type, int $object_id, string $action, array $payload ): void {
		global $wpdb;

		$institution_id = absint( $institution_id );
		$actor_id       = absint( $actor_id );
		$object_id      = absint( $object_id );
		if ( $institution_id <= 0 || $actor_id <= 0 || $object_id <= 0 ) {
			return;
		}

		$table = $wpdb->prefix . 'atora_tenancy_audit';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'institution_id' => $institution_id,
				'cohort_id'      => 0,
				'actor_id'       => $actor_id,
				'on_behalf_of'   => absint( $on_behalf_of ),
				'target_user_id' => absint( $target_user_id ),
				'object_type'    => sanitize_key( $object_type ),
				'object_id'      => $object_id,
				'action'         => sanitize_key( $action ),
				'payload_json'   => wp_json_encode( $payload ),
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d','%d','%d','%d','%d','%s','%d','%s','%s','%s' )
		);
	}
}

