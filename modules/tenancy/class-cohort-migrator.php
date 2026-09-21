<?php
/**
 * Cohort_Migrator — X-01
 *
 * Migra cohortes legacy (lm_cohort + postmeta serializado) a tablas:
 * - atora_cohorts
 * - atora_cohort_members
 * - atora_cohort_courses
 *
 * No borra ni modifica postmeta legacy.
 *
 * @package ATORA_LMS\Tenancy
 * @since   6.26.3
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Cohort_Migrator {

	public static function migrate_from_postmeta( int $institution_id ): array {
		global $wpdb;

		$institution_id = absint( $institution_id );
		if ( $institution_id <= 0 || ! class_exists( 'CLMS_Cohort_Service' ) ) {
			return array( 'cohorts' => 0, 'members' => 0, 'courses' => 0 );
		}

		$svc = new \CLMS_Cohort_Service();

		$wp_ids = get_posts(
			array(
				'post_type'              => \CLMS_Cohort_Service::POST_TYPE,
				'post_status'            => array( 'publish', 'private', 'draft' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);
		$wp_ids = is_array( $wp_ids ) ? array_values( array_filter( array_map( 'absint', $wp_ids ) ) ) : array();
		if ( empty( $wp_ids ) ) {
			return array( 'cohorts' => 0, 'members' => 0, 'courses' => 0 );
		}

		$cohort_table  = $wpdb->prefix . 'atora_cohorts';
		$member_table  = $wpdb->prefix . 'atora_cohort_members';
		$courses_table = $wpdb->prefix . 'atora_cohort_courses';

		$cohorts = 0;
		$members = 0;
		$courses = 0;

		foreach ( $wp_ids as $wp_post_id ) {
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$cohort_table} WHERE wp_post_id = %d LIMIT 1", $wp_post_id )
			);
			if ( $exists > 0 ) {
				continue;
			}

			$status = $svc->normalize_cohort_status( get_post_meta( $wp_post_id, \CLMS_Cohort_Service::META_STATUS, true ) );
			$code   = sanitize_text_field( (string) get_post_meta( $wp_post_id, \CLMS_Cohort_Service::META_COMPANY, true ) );
			if ( '' === $code ) {
				$code = 'cohort-' . $wp_post_id;
			}

			$program_ids = $svc->get_cohort_program_ids( $wp_post_id );
			$program_id  = absint( $program_ids[0] ?? 0 );

			$start = sanitize_text_field( (string) get_post_meta( $wp_post_id, \CLMS_Cohort_Service::META_START_DATE, true ) );
			$end   = sanitize_text_field( (string) get_post_meta( $wp_post_id, \CLMS_Cohort_Service::META_END_DATE, true ) );
			$cap   = absint( get_post_meta( $wp_post_id, \CLMS_Cohort_Service::META_CAPACITY, true ) );

			$ok = $wpdb->insert(
				$cohort_table,
				array(
					'institution_id' => $institution_id,
					'program_id'     => $program_id,
					'wp_post_id'     => $wp_post_id,
					'code'           => $code,
					'name'           => (string) get_the_title( $wp_post_id ),
					'status'         => $status,
					'start_date'     => self::to_date_or_null( $start ),
					'end_date'       => self::to_date_or_null( $end ),
					'capacity'       => $cap,
					'settings_json'  => null,
				),
				array( '%d','%d','%d','%s','%s','%s','%s','%s','%d','%s' )
			);
			if ( ! $ok ) { continue; }
			$cohort_id = (int) $wpdb->insert_id;
			$cohorts++;

			// Teachers.
			foreach ( $svc->get_cohort_teacher_ids( $wp_post_id ) as $teacher_id ) {
				$teacher_id = absint( $teacher_id );
				if ( $teacher_id <= 0 ) { continue; }
				$wpdb->insert(
					$member_table,
					array(
						'cohort_id'      => $cohort_id,
						'institution_id' => $institution_id,
						'user_id'        => $teacher_id,
						'role'           => 'teacher',
						'status'         => 'active',
						'meta_json'      => null,
					),
					array( '%d','%d','%d','%s','%s','%s' )
				);
				$members++;
			}

			// Students + status map.
			$map = $svc->get_cohort_student_status_map( $wp_post_id );
			foreach ( $svc->get_cohort_student_ids( $wp_post_id ) as $student_id ) {
				$student_id = absint( $student_id );
				if ( $student_id <= 0 ) { continue; }
				$st = sanitize_key( (string) ( $map[ $student_id ] ?? 'activo' ) );
				$st = $svc->normalize_student_status( $st );
				$wpdb->insert(
					$member_table,
					array(
						'cohort_id'      => $cohort_id,
						'institution_id' => $institution_id,
						'user_id'        => $student_id,
						'role'           => 'student',
						'status'         => 'active',
						'meta_json'      => wp_json_encode( array( 'legacy_student_status' => $st ) ),
					),
					array( '%d','%d','%d','%s','%s','%s' )
				);
				$members++;
			}

			// Courses.
			$order = 0;
			foreach ( $svc->get_cohort_course_ids( $wp_post_id ) as $wp_course_id ) {
				$wp_course_id = absint( $wp_course_id );
				if ( $wp_course_id <= 0 || ! class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) ) { continue; }
				$course = \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $wp_course_id );
				$course_id = absint( is_array( $course ) ? ( $course['id'] ?? 0 ) : 0 );
				if ( $course_id <= 0 ) { continue; }

				$wpdb->insert(
					$courses_table,
					array(
						'cohort_id'    => $cohort_id,
						'course_id'    => $course_id,
						'course_order' => $order,
						'is_required'  => 1,
						'opens_at'     => null,
						'closes_at'    => null,
					),
					array( '%d','%d','%d','%d','%s','%s' )
				);
				$order++;
				$courses++;
			}
		}

		return array( 'cohorts' => $cohorts, 'members' => $members, 'courses' => $courses );
	}

	public static function verify_parity(): array {
		global $wpdb;

		$errors = array();

		if ( ! class_exists( 'CLMS_Cohort_Service' ) ) {
			return array( 'errors' => array( 'CLMS_Cohort_Service no disponible.' ) );
		}

		$wp_count = (int) wp_count_posts( \CLMS_Cohort_Service::POST_TYPE )->publish;
		$table    = $wpdb->prefix . 'atora_cohorts';
		$tbl_count = 0;
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
			$tbl_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		if ( $tbl_count < $wp_count ) {
			$errors[] = "Cohortes en tablas ({$tbl_count}) menor a cohortes publish ({$wp_count}).";
		}

		return array( 'errors' => $errors );
	}

	private static function to_date_or_null( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) { return null; }
		if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $value ) ) {
			return $value;
		}
		return null;
	}
}

