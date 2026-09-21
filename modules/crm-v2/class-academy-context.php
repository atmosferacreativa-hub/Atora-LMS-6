<?php
/**
 * Academy_Context — Base multi-tenant (Fase IV S15)
 *
 * @deprecated 6.26.4 Usar \ATORA\LMS\Tenant_Context e institution_id.
 *
 * En 6.26.3 se introdujo `atora_institutions` y la resolución robusta de
 * inquilino. `academy_id` no tiene entidad canónica y su resolvedor podía
 * degradar a consultas sin filtro. Esta clase queda como envoltura de
 * compatibilidad y será retirada en 6.27.0.
 *
 * @package ATORA_LMS\CRM_V2
 * @since   5.30.0
 */

namespace ATORA\CRM_V2;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Academy_Context {

	const OPTION_KEY  = 'atora_active_academy_id';
	const USER_META   = '_atora_academy_id';

	/**
	 * Devuelve el academy_id activo para el usuario actual.
	 *
	 * Desde 6.26.4, `academy_id` se considera un alias obsoleto de
	 * `institution_id`. Este método delega en Tenant_Context.
	 *
	 * @return int Academy ID (alias de institution_id) o 0 solo si no se pudo resolver.
	 */
	public static function get_current_academy_id(): int {
		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( __METHOD__, 'Academy_Context está obsoleto; usa Tenant_Context e institution_id.', '6.26.4' );
		}

		if ( ! class_exists( '\ATORA\LMS\Tenant_Context' ) && defined( 'ATORA_LMS_MODULES_DIR' ) ) {
			$file = ATORA_LMS_MODULES_DIR . 'tenancy/class-tenant-context.php';
			if ( file_exists( $file ) ) { require_once $file; }
		}

		$resolved = class_exists( '\ATORA\LMS\Tenant_Context' )
			? \ATORA\LMS\Tenant_Context::current_institution_id()
			: null;

		if ( is_wp_error( $resolved ) || null === $resolved ) {
			return 0;
		}

		return absint( $resolved );
	}

	/**
	 * Genera la cláusula WHERE para filtrar por academy_id.
	 *
	 * Si no se resuelve inquilino, devuelve una condición imposible
	 * para evitar consultas sin filtro.
	 *
	 * @param string $alias Alias de tabla, p.ej. 'c' para `c.academy_id`.
	 * @return string SQL fragment.
	 */
	public static function where_clause( string $alias = '' ): string {
		$id = self::get_current_academy_id();
		if ( 0 === $id ) { return ''; }
		global $wpdb;
		$alias = (string) preg_replace( '/[^a-zA-Z0-9_]/', '', $alias );
		$col   = $alias ? "{$alias}.institution_id" : 'institution_id';
		return $wpdb->prepare( "AND {$col} = %d", $id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Asigna un usuario a un academy.
	 *
	 * @param int $user_id    Usuario.
	 * @param int $academy_id Academy.
	 */
	public static function assign_user( int $user_id, int $academy_id ): void {
		global $wpdb;

		$user_id    = absint( $user_id );
		$academy_id = absint( $academy_id );
		if ( $user_id <= 0 || $academy_id <= 0 ) { return; }

		$table = $wpdb->prefix . 'atora_institution_members';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"INSERT INTO {$table} (institution_id, user_id, role, status)
				 VALUES (%d, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE status = VALUES(status)",
				$academy_id,
				$user_id,
				'student',
				'active'
			)
		);
	}

	/**
	 * Establece el academy activo global (opción del sitio).
	 *
	 * @param int $academy_id Academy ID o 0 para deshabilitar.
	 */
	public static function set_global( int $academy_id ): void {
		update_option( 'atora_default_institution', absint( $academy_id ), false );
	}

	/**
	 * Añade el filtro academy_id a una query REST si es necesario.
	 * Llamar desde los callbacks REST antes de ejecutar la query.
	 *
	 * @param array  $args   Args de la query (se modifica por referencia).
	 * @param string $field  Nombre del campo, por defecto 'institution_id'.
	 */
	public static function apply_to_args( array &$args, string $field = 'institution_id' ): void {
		if ( 'academy_id' === $field && function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( __METHOD__, 'El campo academy_id está obsoleto; usa institution_id.', '6.26.4' );
			$field = 'institution_id';
		}

		$id = self::get_current_academy_id();
		if ( $id > 0 ) {
			$args[ $field ] = $id;
		}
	}
}
