<?php
/**
 * Tenancy query detector (X-01 §1.7).
 *
 * Bajo ATORA_DEV_MODE, registra (error_log) queries que tocan tablas
 * multi-inquilino sin ninguna referencia a institution_id.
 *
 * @package ATORA_LMS\Tenancy
 * @since   6.26.3
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Tenancy_Query_Detector {

	public static function init(): void {
		if ( ! defined( 'ATORA_DEV_MODE' ) || ! ATORA_DEV_MODE ) {
			return;
		}

		add_filter( 'query', array( __CLASS__, 'check' ), 1, 1 );
	}

	public static function check( $query ) {
		global $wpdb;

		if ( ! is_string( $query ) || '' === trim( $query ) ) {
			return $query;
		}

		$upper = strtoupper( ltrim( $query ) );
		if ( 0 === strpos( $upper, 'SHOW ' )
			|| 0 === strpos( $upper, 'CREATE ' )
			|| 0 === strpos( $upper, 'ALTER ' )
			|| 0 === strpos( $upper, 'DROP ' )
			|| 0 === strpos( $upper, 'DESCRIBE ' )
			|| 0 === strpos( $upper, 'INSERT ' )
			|| 0 === strpos( $upper, 'REPLACE ' ) ) {
			return $query;
		}

		if ( ! preg_match( '/^\\s*(SELECT|UPDATE|DELETE)\\b/i', $query ) ) {
			return $query;
		}

		$watch = array(
			$wpdb->prefix . 'atora_programs',
			$wpdb->prefix . 'atora_courses',
			$wpdb->prefix . 'atora_enrollments',
			$wpdb->prefix . 'atora_program_enrollments',
			$wpdb->prefix . 'atora_cohorts',
			$wpdb->prefix . 'atora_cohort_members',
			$wpdb->prefix . 'atora_cohort_courses',
			$wpdb->prefix . 'atora_institution_members',
			$wpdb->prefix . 'atora_instructor_delegations',
		);

		$hits = false;
		foreach ( $watch as $t ) {
			if ( false !== stripos( $query, $t ) ) {
				$hits = true;
				break;
			}
		}

		if ( ! $hits ) {
			return $query;
		}

		if ( false === stripos( $query, 'institution_id' ) ) {
			$msg = substr( preg_replace( '/\\s+/', ' ', $query ), 0, 500 );
			error_log( '[ATORA TENANCY] Unscoped query (missing institution_id): ' . $msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $query;
	}
}

