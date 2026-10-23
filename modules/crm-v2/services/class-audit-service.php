<?php
/**
 * Servicio de auditoría CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Audit_Service {
	/**
	 * Registra un evento de auditoría.
	 *
	 * @param string $action_key  Acción (p.ej. contact_created).
	 * @param string $entity_type Tipo de entidad (p.ej. contact).
	 * @param int    $entity_id   ID de entidad.
	 * @param array  $payload     Payload.
	 * @param int    $actor_user_id Actor (0 = usuario actual).
	 * @param int    $institution_id Institución (0 = resolver contexto actual).
	 * @return void
	 */
	public static function log( string $action_key, string $entity_type, int $entity_id = 0, array $payload = array(), int $actor_user_id = 0, int $institution_id = 0 ): void {
		global $wpdb;

		$action_key = sanitize_key( $action_key );
		$entity_type = sanitize_key( $entity_type );
		$entity_id = absint( $entity_id );
		$actor_user_id = absint( $actor_user_id ?: get_current_user_id() );
		$institution_id = absint( $institution_id ?: self::resolve_institution_id() );

		if ( '' === $action_key || '' === $entity_type ) {
			return;
		}

		$table = $wpdb->prefix . 'atora_crm_audit_log';
		if ( ! DB_Service::table_exists( $table ) ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'institution_id' => $institution_id,
				'actor_user_id'  => $actor_user_id,
				'action_key'     => $action_key,
				'entity_type'    => $entity_type,
				'entity_id'      => $entity_id,
				'payload'        => wp_json_encode( $payload ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Resuelve institution_id para auditoría (nunca degrada a "todas").
	 *
	 * @return int 0 cuando no se puede resolver.
	 */
	private static function resolve_institution_id(): int {
		if ( class_exists( '\\ATORA\\LMS\\Tenant_Context' ) ) {
			$resolved = \ATORA\LMS\Tenant_Context::current_institution_id();
			if ( is_wp_error( $resolved ) ) {
				return 0;
			}
			return absint( $resolved );
		}
		return 0;
	}
}

