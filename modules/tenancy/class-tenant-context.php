<?php
/**
 * Tenant_Context — X-01
 *
 * Punto único de resolución de institución para el request actual.
 * Una resolución fallida debe producir error (nunca degrada a "todas").
 *
 * @package ATORA_LMS\Tenancy
 * @since   6.26.3
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Tenant_Context {

	private static $cached_institution_id = null;

	/**
	 * Contexto explícito para jobs/servicios (paso 1 de resolución).
	 *
	 * @var int|null
	 */
	private static ?int $explicit_institution_id = null;

	/**
	 * Resolución unificada: o institución válida o WP_Error (nunca 0).
	 *
	 * @return int|\WP_Error
	 */
	public static function current_institution_id() {
		if ( null !== self::$cached_institution_id ) {
			return self::$cached_institution_id;
		}

		// 1) Contexto explícito.
		if ( self::$explicit_institution_id && self::$explicit_institution_id > 0 ) {
			$valid = self::institution_exists( self::$explicit_institution_id );
			self::$cached_institution_id = $valid
				? self::$explicit_institution_id
				: new \WP_Error(
					'atora_tenancy_invalid_institution',
					__( 'Institución explícita no válida.', 'atora-lms' ),
					array( 'status' => 400 )
				);
			return self::$cached_institution_id;
		}

		$user_id = get_current_user_id();

		// 2–3) Selección explícita del usuario / pertenencia única.
		if ( $user_id > 0 ) {
			$resolved = self::resolve_institution_for_user( $user_id );
			if ( is_wp_error( $resolved ) ) {
				self::$cached_institution_id = $resolved;
				return $resolved;
			}
			if ( $resolved > 0 ) {
				self::$cached_institution_id = $resolved;
				return $resolved;
			}
		}

		// 4) Opción por defecto.
		$default = absint( get_option( 'atora_default_institution', 0 ) );
		if ( $default > 0 && self::institution_exists( $default ) ) {
			self::$cached_institution_id = $default;
			return $default;
		}

		// 5) Fallo → error (nunca "todas").
		self::$cached_institution_id = new \WP_Error(
			'atora_tenancy_unresolved',
			__( 'No se pudo resolver la institución para este request.', 'atora-lms' ),
			array( 'status' => 400 )
		);
		return self::$cached_institution_id;
	}

	public static function require_current_institution_id() {
		return self::current_institution_id();
	}

	/**
	 * Paso 2 (usermeta) + paso 3 (pertenencia única).
	 *
	 * Si el usuario tiene múltiples membresías activas y no hay selección,
	 * devuelve error (no elige "la menor").
	 *
	 * @return int|\WP_Error
	 */
	public static function resolve_institution_for_user( int $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) { return 0; }

		// 2) Selección explícita del administrador en usermeta.
		$selected = absint( get_user_meta( $user_id, '_atora_active_institution', true ) );
		if ( $selected > 0 ) {
			if ( self::user_is_active_member_of( $user_id, $selected ) ) {
				return $selected;
			}
			return new \WP_Error(
				'atora_tenancy_institution_forbidden',
				__( 'La institución seleccionada no está disponible para este usuario.', 'atora-lms' ),
				array( 'status' => 403 )
			);
		}

		// 3) Pertenencia única.
		$ids = self::active_institution_ids_for_user( $user_id );
		if ( 1 === count( $ids ) ) {
			return absint( $ids[0] );
		}
		if ( count( $ids ) >= 2 ) {
			return new \WP_Error(
				'atora_tenancy_ambiguous',
				__( 'Este usuario pertenece a múltiples instituciones. Selecciona una institución activa.', 'atora-lms' ),
				array( 'status' => 400 )
			);
		}

		return 0;
	}

	/**
	 * Ejecuta un callable en un contexto explícito de institución.
	 *
	 * @template T
	 * @param int $institution_id Institución.
	 * @param callable():T $fn Callable.
	 * @return T
	 */
	public static function with( int $institution_id, callable $fn ) {
		$institution_id = absint( $institution_id );
		if ( $institution_id <= 0 ) {
			return $fn();
		}

		$prev = self::$explicit_institution_id;
		self::$explicit_institution_id = $institution_id;
		self::reset_cache();
		try {
			return $fn();
		} finally {
			self::$explicit_institution_id = $prev;
			self::reset_cache();
		}
	}

	public static function reset_cache(): void {
		self::$cached_institution_id = null;
	}

	private static function active_institution_ids_for_user( int $user_id ): array {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) { return array(); }

		$table = $wpdb->prefix . 'atora_institution_members';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return array();
		}

		$rows = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT institution_id FROM {$table}
				 WHERE user_id = %d AND status = 'active'
				 ORDER BY institution_id ASC",
				$user_id
			)
		);
		return array_values( array_unique( array_filter( array_map( 'absint', $rows ) ) ) );
	}

	private static function user_is_active_member_of( int $user_id, int $institution_id ): bool {
		global $wpdb;

		$user_id = absint( $user_id );
		$institution_id = absint( $institution_id );
		if ( $user_id <= 0 || $institution_id <= 0 ) { return false; }

		$table = $wpdb->prefix . 'atora_institution_members';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return false;
		}

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table}
				 WHERE institution_id = %d AND user_id = %d AND status = 'active'
				 LIMIT 1",
				$institution_id,
				$user_id
			)
		);
	}

	private static function institution_exists( int $institution_id ): bool {
		global $wpdb;
		$institution_id = absint( $institution_id );
		if ( $institution_id <= 0 ) { return false; }

		$table = $wpdb->prefix . 'atora_institutions';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return false;
		}

		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$table} WHERE id = %d LIMIT 1", $institution_id )
		);
	}
}
