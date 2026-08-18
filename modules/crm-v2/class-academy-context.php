<?php
/**
 * Academy_Context — Base multi-tenant (Fase IV S15)
 *
 * Lee el academy_id activo del usuario actual.
 * Los scopes REST filtran por este ID cuando > 0.
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
	 * Orden de prioridad:
	 *   1. Parámetro ?academy_id=N en la request REST (solo admins).
	 *   2. Usermeta _atora_academy_id del usuario actual.
	 *   3. Option global atora_active_academy_id (configuración del sitio).
	 *   4. 0 = sin filtro (acceso completo).
	 *
	 * @return int Academy ID o 0 si no aplica.
	 */
	public static function get_current_academy_id(): int {
		// 1. Request param (solo admins)
		if ( current_user_can( 'manage_options' ) && isset( $_GET['academy_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$from_param = absint( $_GET['academy_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $from_param > 0 ) { return $from_param; }
		}

		// 2. Usermeta del usuario actual
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$from_meta = absint( get_user_meta( $user_id, self::USER_META, true ) );
			if ( $from_meta > 0 ) { return $from_meta; }
		}

		// 3. Option global
		$from_option = absint( get_option( self::OPTION_KEY, 0 ) );
		return $from_option;
	}

	/**
	 * Genera la cláusula WHERE para filtrar por academy_id.
	 * Si academy_id = 0, devuelve cadena vacía (sin filtro).
	 *
	 * @param string $alias Alias de tabla, p.ej. 'c' para `c.academy_id`.
	 * @return string SQL fragment o ''.
	 */
	public static function where_clause( string $alias = '' ): string {
		$id = self::get_current_academy_id();
		if ( 0 === $id ) { return ''; }
		global $wpdb;
		$col = $alias ? sanitize_key( $alias ) . '.academy_id' : 'academy_id';
		return $wpdb->prepare( "AND {$col} = %d", $id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Asigna un usuario a un academy.
	 *
	 * @param int $user_id    Usuario.
	 * @param int $academy_id Academy.
	 */
	public static function assign_user( int $user_id, int $academy_id ): void {
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::USER_META, $academy_id );
		}
	}

	/**
	 * Establece el academy activo global (opción del sitio).
	 *
	 * @param int $academy_id Academy ID o 0 para deshabilitar.
	 */
	public static function set_global( int $academy_id ): void {
		update_option( self::OPTION_KEY, $academy_id, false );
	}

	/**
	 * Añade el filtro academy_id a una query REST si es necesario.
	 * Llamar desde los callbacks REST antes de ejecutar la query.
	 *
	 * @param array  $args   Args de la query (se modifica por referencia).
	 * @param string $field  Nombre del campo, por defecto 'academy_id'.
	 */
	public static function apply_to_args( array &$args, string $field = 'academy_id' ): void {
		$id = self::get_current_academy_id();
		if ( $id > 0 ) {
			$args[ $field ] = $id;
		}
	}
}
