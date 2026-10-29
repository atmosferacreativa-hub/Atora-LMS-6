<?php
/**
 * Forums domain service (DB + permission checks).
 *
 * @package ATORA_LMS
 */

declare( strict_types = 1 );

namespace ATORA\Forums;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Forums_Service {
	private const SCOPE_COURSE = 'course';
	private const SCOPE_PROGRAM = 'program';
	private const SCOPE_COHORT = 'cohort';

	public static function scopes(): array {
		return array( self::SCOPE_COURSE, self::SCOPE_PROGRAM, self::SCOPE_COHORT );
	}

	public static function institution_for_scope( string $scope_type, int $scope_id ): int {
		$scope_id = absint( $scope_id );
		if ( $scope_id <= 0 ) {
			return absint( get_option( 'atora_default_institution', 0 ) );
		}
		global $wpdb;
		if ( self::SCOPE_COURSE === $scope_type ) {
			return class_exists( 'ATORA_Teacher_Scope' ) ? (int) \ATORA_Teacher_Scope::course_institution( $scope_id ) : absint( get_option( 'atora_default_institution', 0 ) );
		}
		if ( self::SCOPE_PROGRAM === $scope_type ) {
			$inst = (int) $wpdb->get_var( $wpdb->prepare( "SELECT institution_id FROM {$wpdb->prefix}atora_programs WHERE wp_post_id = %d LIMIT 1", $scope_id ) ); // phpcs:ignore WordPress.DB
			return $inst > 0 ? $inst : absint( get_option( 'atora_default_institution', 0 ) );
		}
		if ( self::SCOPE_COHORT === $scope_type ) {
			$inst = (int) $wpdb->get_var( $wpdb->prepare( "SELECT institution_id FROM {$wpdb->prefix}atora_cohorts WHERE wp_post_id = %d LIMIT 1", $scope_id ) ); // phpcs:ignore WordPress.DB
			return $inst > 0 ? $inst : absint( get_option( 'atora_default_institution', 0 ) );
		}
		return absint( get_option( 'atora_default_institution', 0 ) );
	}

	public static function forum_for_scope( string $scope_type, int $scope_id ) {
		$scope_type = sanitize_key( $scope_type );
		$scope_id = absint( $scope_id );
		if ( ! in_array( $scope_type, self::scopes(), true ) || $scope_id <= 0 ) {
			return new WP_Error( 'atora_forums_scope', __( 'Foro inválido.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$inst = self::institution_for_scope( $scope_type, $scope_id );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forums WHERE institution_id = %d AND scope_type = %s AND scope_id = %d LIMIT 1", $inst, $scope_type, $scope_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( is_array( $row ) ) {
			return $row;
		}
		$ok = $wpdb->insert( $wpdb->prefix . 'atora_forums', array(
			'institution_id' => $inst,
			'scope_type'     => $scope_type,
			'scope_id'       => $scope_id,
		) ); // phpcs:ignore WordPress.DB
		if ( ! $ok ) {
			return new WP_Error( 'atora_forums_db', __( 'No se pudo crear el foro.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forums WHERE id = %d LIMIT 1", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function forum_by_id( int $forum_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_forums WHERE id = %d LIMIT 1", absint( $forum_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : null;
	}

	public static function can_participate( int $user_id, array $forum ): bool {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( ! self::in_institution( $user_id, absint( $forum['institution_id'] ?? 0 ), (string) ( $forum['scope_type'] ?? '' ), absint( $forum['scope_id'] ?? 0 ) ) ) {
			return false;
		}
		$scope_type = (string) ( $forum['scope_type'] ?? '' );
		$scope_id = absint( $forum['scope_id'] ?? 0 );
		if ( self::SCOPE_COURSE === $scope_type ) {
			return self::is_enrolled_in_course( $user_id, $scope_id );
		}
		if ( self::SCOPE_PROGRAM === $scope_type ) {
			return self::is_enrolled_in_program( $user_id, $scope_id );
		}
		if ( self::SCOPE_COHORT === $scope_type ) {
			return self::is_in_cohort( $user_id, $scope_id );
		}
		return false;
	}

	private static function in_institution( int $user_id, int $institution_id, string $scope_type, int $scope_id ): bool {
		if ( $institution_id <= 0 ) {
			return true;
		}
		if ( class_exists( 'ATORA_Teacher_Scope' ) && method_exists( 'ATORA_Teacher_Scope', 'multi_institution' ) && \ATORA_Teacher_Scope::multi_institution() ) {
			if ( self::SCOPE_COURSE === $scope_type && class_exists( 'ATORA_Teacher_Scope' ) ) {
				return \ATORA_Teacher_Scope::in_tenant( $user_id, $scope_id );
			}
			if ( class_exists( '\\ATORA\\LMS\\Tenant_Context' ) ) {
				$inst = \ATORA\LMS\Tenant_Context::resolve_institution_for_user( $user_id );
				if ( is_wp_error( $inst ) || ! $inst ) {
					$inst = absint( get_option( 'atora_default_institution', 0 ) );
				}
				return absint( $inst ) === $institution_id;
			}
		}
		return true;
	}

	public static function is_read_only( array $forum ): bool {
		$scope_type = (string) ( $forum['scope_type'] ?? '' );
		$scope_id = absint( $forum['scope_id'] ?? 0 );
		if ( self::SCOPE_COURSE === $scope_type ) {
			return 'publish' !== (string) get_post_status( $scope_id );
		}
		return false;
	}

	public static function can_moderate( int $user_id, array $forum ): bool {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$scope_type = (string) ( $forum['scope_type'] ?? '' );
		$scope_id = absint( $forum['scope_id'] ?? 0 );
		if ( self::SCOPE_COURSE === $scope_type && class_exists( 'ATORA_Teacher_Scope' ) ) {
			if ( \ATORA_Teacher_Scope::can_access_course( $user_id, $scope_id ) ) {
				return true;
			}
			if ( class_exists( 'ATORA_Delegation_Service' ) && ( \ATORA_Delegation_Service::covers( $user_id, $scope_id, 'content' ) || \ATORA_Delegation_Service::covers( $user_id, $scope_id, 'grade' ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function can_accept_reply( int $user_id, array $forum, array $topic ): bool {
		if ( self::can_moderate( $user_id, $forum ) ) {
			return true;
		}
		return absint( $topic['author_id'] ?? 0 ) === absint( $user_id );
	}

	public static function can_edit_message( int $user_id, array $row ): bool {
		$user_id = absint( $user_id );
		$author = absint( $row['author_id'] ?? 0 );
		if ( $user_id <= 0 || $author !== $user_id ) {
			return false;
		}
		$created = (string) ( $row['created_at'] ?? '' );
		if ( '' === $created ) {
			return false;
		}
		$ts = strtotime( $created . ' UTC' );
		return $ts > 0 && ( time() - $ts ) <= 15 * MINUTE_IN_SECONDS;
	}

	private static function is_enrolled_in_course( int $user_id, int $wp_course_id ): bool {
		if ( ! class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) || ! class_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service' ) ) {
			return false;
		}
		$course = \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $wp_course_id );
		$course_id = absint( is_array( $course ) ? ( $course['id'] ?? 0 ) : 0 );
		return $course_id > 0 && \ATORA\LMS\LMS_Enrollment_Service::is_enrolled( $user_id, $course_id );
	}

	private static function is_enrolled_in_program( int $user_id, int $wp_program_id ): bool {
		global $wpdb;
		$program_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_programs WHERE wp_post_id = %d LIMIT 1", $wp_program_id ) ); // phpcs:ignore WordPress.DB
		if ( $program_id <= 0 ) {
			return false;
		}
		$row = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}atora_program_enrollments WHERE program_id = %d AND user_id = %d AND status IN ('active','enrolled') LIMIT 1", $program_id, $user_id ) ); // phpcs:ignore WordPress.DB
		return (bool) $row;
	}

	private static function is_in_cohort( int $user_id, int $wp_cohort_id ): bool {
		global $wpdb;
		$cohort_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_cohorts WHERE wp_post_id = %d LIMIT 1", $wp_cohort_id ) ); // phpcs:ignore WordPress.DB
		if ( $cohort_id <= 0 ) {
			return false;
		}
		$row = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}atora_cohort_members WHERE cohort_id = %d AND user_id = %d AND role = 'student' AND status = 'active' LIMIT 1", $cohort_id, $user_id ) ); // phpcs:ignore WordPress.DB
		return (bool) $row;
	}
}

