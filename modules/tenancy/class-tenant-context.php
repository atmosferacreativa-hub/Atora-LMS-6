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

	private static ?int $cached_institution_id = null;

	public static function current_institution_id(): int {
		if ( null !== self::$cached_institution_id ) {
			return self::$cached_institution_id;
		}

		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$inst = self::resolve_institution_for_user( $user_id );
			if ( $inst > 0 ) {
				self::$cached_institution_id = $inst;
				return $inst;
			}
		}

		$default = absint( get_option( 'atora_default_institution', 0 ) );
		self::$cached_institution_id = $default > 0 ? $default : 0;
		return self::$cached_institution_id;
	}

	public static function require_current_institution_id() {
		$inst = self::current_institution_id();
		if ( $inst <= 0 ) {
			return new \WP_Error(
				'atora_tenancy_unresolved',
				__( 'No se pudo resolver la institución para este request.', 'atora-lms' ),
				array( 'status' => 400 )
			);
		}
		return $inst;
	}

	public static function resolve_institution_for_user( int $user_id ): int {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) { return 0; }

		$table = $wpdb->prefix . 'atora_institution_members';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return 0;
		}

		$inst = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT institution_id FROM {$table}
				 WHERE user_id = %d AND status = 'active'
				 ORDER BY institution_id ASC
				 LIMIT 1",
				$user_id
			)
		);
		return absint( $inst );
	}

	public static function reset_cache(): void {
		self::$cached_institution_id = null;
	}
}

