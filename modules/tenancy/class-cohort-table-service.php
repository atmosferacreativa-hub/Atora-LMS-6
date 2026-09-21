<?php
/**
 * Cohort_Table_Service — lectura tabular de cohortes (X-01).
 *
 * @package ATORA_LMS\Tenancy
 * @since   6.26.3
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Cohort_Table_Service {

	/**
	 * Cache wp_post_id -> atora_cohorts.id.
	 *
	 * @var array<int,int>
	 */
	private static array $cohort_id_cache = array();

	public static function cohort_id_from_wp_post( int $wp_post_id ): int {
		global $wpdb;
		$wp_post_id = absint( $wp_post_id );
		if ( $wp_post_id <= 0 ) { return 0; }

		if ( isset( self::$cohort_id_cache[ $wp_post_id ] ) ) {
			return self::$cohort_id_cache[ $wp_post_id ];
		}

		$table = $wpdb->prefix . 'atora_cohorts';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			self::$cohort_id_cache[ $wp_post_id ] = 0;
			return 0;
		}

		$inst = Tenant_Context::require_current_institution_id();
		if ( is_wp_error( $inst ) ) {
			self::$cohort_id_cache[ $wp_post_id ] = 0;
			return 0;
		}
		$institution_id = absint( $inst );

		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE institution_id = %d AND wp_post_id = %d LIMIT 1",
				$institution_id,
				$wp_post_id
			)
		);
		self::$cohort_id_cache[ $wp_post_id ] = absint( $id );
		return self::$cohort_id_cache[ $wp_post_id ];
	}

	/**
	 * Cohortes visibles para el usuario en la institución actual.
	 *
	 * @param int   $user_id       Usuario.
	 * @param array $status_filter Estados.
	 * @param int   $limit         Máximo total (0 = sin límite).
	 * @return array<int,int> wp_post_id
	 */
	public static function get_visible_wp_post_ids( int $user_id, array $status_filter = array(), int $limit = 0 ): array {
		global $wpdb;

		$inst = Tenant_Context::require_current_institution_id();
		if ( is_wp_error( $inst ) ) { return array(); }
		$institution_id = absint( $inst );

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) { return array(); }

		$cohort_table = $wpdb->prefix . 'atora_cohorts';
		$member_table = $wpdb->prefix . 'atora_cohort_members';

		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $cohort_table ) ) ) !== $cohort_table ) {
			return array();
		}
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $member_table ) ) ) !== $member_table ) {
			return array();
		}

		$status_filter = is_array( $status_filter ) ? array_values( array_filter( array_map( 'sanitize_key', $status_filter ) ) ) : array();
		$limit = absint( $limit );

		$per_page = 250;
		$offset   = 0;
		$out      = array();

		while ( true ) {
			$where = "WHERE c.institution_id = %d AND c.wp_post_id IS NOT NULL AND c.wp_post_id > 0";
			$args  = array( $institution_id );

			if ( ! ( current_user_can( 'manage_options' ) || current_user_can( 'clms_access_admin' ) ) ) {
				$where .= " AND m.institution_id = %d AND m.user_id = %d AND m.role = 'teacher' AND m.status = 'active'";
				$args[] = $institution_id;
				$args[] = $user_id;
			}

			if ( ! empty( $status_filter ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $status_filter ), '%s' ) );
				$where .= " AND c.status IN ({$placeholders})";
				foreach ( $status_filter as $st ) {
					$args[] = $st;
				}
			}

			$sql = "SELECT DISTINCT c.wp_post_id
					FROM {$cohort_table} c
					LEFT JOIN {$member_table} m ON m.cohort_id = c.id
					{$where}
					ORDER BY c.created_at DESC, c.id DESC
					LIMIT %d OFFSET %d";
			$args[] = $per_page;
			$args[] = $offset;

			$chunk = (array) $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$chunk = array_values( array_unique( array_filter( array_map( 'absint', $chunk ) ) ) );
			if ( empty( $chunk ) ) {
				break;
			}

			foreach ( $chunk as $id ) {
				$out[] = $id;
				if ( $limit > 0 && count( $out ) >= $limit ) {
					return array_values( array_unique( $out ) );
				}
			}

			$offset += $per_page;
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * IDs de miembros para un rol (teacher|student).
	 *
	 * @return array<int,int>
	 */
	public static function get_member_ids( int $wp_post_id, string $role ): array {
		global $wpdb;

		$role = sanitize_key( $role );
		if ( ! in_array( $role, array( 'teacher', 'student' ), true ) ) {
			$role = 'student';
		}

		$cohort_id = self::cohort_id_from_wp_post( $wp_post_id );
		if ( $cohort_id <= 0 ) { return array(); }

		$table = $wpdb->prefix . 'atora_cohort_members';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return array();
		}

		$inst = Tenant_Context::require_current_institution_id();
		if ( is_wp_error( $inst ) ) { return array(); }
		$institution_id = absint( $inst );

		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$table}
				 WHERE institution_id = %d AND cohort_id = %d AND role = %s AND status = 'active'
				 ORDER BY user_id ASC",
				$institution_id,
				$cohort_id,
				$role
			)
		);
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Cursos (wp_post_id de lm_course) asociados a la cohorte.
	 *
	 * @return array<int,int>
	 */
	public static function get_wp_course_ids( int $wp_post_id ): array {
		global $wpdb;
		$cohort_id = self::cohort_id_from_wp_post( $wp_post_id );
		if ( $cohort_id <= 0 ) { return array(); }

		$table = $wpdb->prefix . 'atora_cohort_courses';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return array();
		}

		$course_ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT course_id FROM {$table}
				 WHERE cohort_id = %d
				 ORDER BY course_order ASC, id ASC",
				$cohort_id
			)
		);

		$wp_ids = array();
		if ( class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) ) {
			foreach ( array_values( array_unique( array_filter( array_map( 'absint', $course_ids ) ) ) ) as $course_id ) {
				$course = \ATORA\LMS\LMS_Course_Service::get( $course_id );
				$wp_course_id = absint( is_array( $course ) ? ( $course['wp_post_id'] ?? 0 ) : 0 );
				if ( $wp_course_id > 0 ) {
					$wp_ids[] = $wp_course_id;
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $wp_ids ) ) ) );
	}

	/**
	 * Estado legacy por estudiante (desde meta_json).
	 *
	 * @return array<int,string>
	 */
	public static function get_student_status_map( int $wp_post_id ): array {
		global $wpdb;

		$cohort_id = self::cohort_id_from_wp_post( $wp_post_id );
		if ( $cohort_id <= 0 ) { return array(); }

		$table = $wpdb->prefix . 'atora_cohort_members';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return array();
		}

		$inst = Tenant_Context::require_current_institution_id();
		if ( is_wp_error( $inst ) ) { return array(); }
		$institution_id = absint( $inst );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_json FROM {$table}
				 WHERE institution_id = %d AND cohort_id = %d AND role = 'student' AND status = 'active'",
				$institution_id,
				$cohort_id
			),
			ARRAY_A
		);

		$map = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$uid = absint( $row['user_id'] ?? 0 );
			if ( $uid <= 0 ) { continue; }

			$status = 'activo';
			$meta = $row['meta_json'] ?? null;
			if ( is_string( $meta ) && '' !== trim( $meta ) ) {
				$decoded = json_decode( $meta, true );
				if ( is_array( $decoded ) && isset( $decoded['legacy_student_status'] ) ) {
					$status = sanitize_key( (string) $decoded['legacy_student_status'] );
				}
			}
			$map[ $uid ] = $status ?: 'activo';
		}

		return $map;
	}
}

