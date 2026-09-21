<?php
/**
 * Delegation caps hooks (E-10).
 *
 * @package ATORA_LMS
 * @since   6.26.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Delegation_Caps {

	public static function init(): void {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_delegated_caps' ), 10, 4 );
		add_filter( 'user_has_cap', array( __CLASS__, 'user_has_cap' ), 10, 4 );
	}

	/**
	 * Delegación para edición/publicación/lectura de lm_course y lm_lesson.
	 *
	 * Nunca delega delete_post.
	 *
	 * @param array  $caps    Primitive caps.
	 * @param string $cap     Requested cap.
	 * @param int    $user_id User ID.
	 * @param array  $args    Context args (post_id en [0] para edit_post/publish_post/read_post).
	 * @return array
	 */
	public static function map_delegated_caps( $caps, $cap, $user_id, $args ) {
		static $resolving = false;
		if ( $resolving ) { return $caps; }

		if ( ! in_array( $cap, array( 'edit_post', 'publish_post', 'read_post' ), true ) ) {
			return $caps;
		}

		$post_id = absint( $args[0] ?? 0 );
		if ( ! $post_id ) { return $caps; }

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( (string) $post->post_type, array( 'lm_course', 'lm_lesson' ), true ) ) {
			return $caps;
		}

		if ( ! class_exists( 'ATORA_Delegation_Service' ) ) {
			return $caps;
		}

		$resolving = true;
		$covered   = ATORA_Delegation_Service::covers( (int) $user_id, $post, 'content' );
		$resolving = false;

		if ( ! $covered ) {
			return $caps;
		}

		return array_map(
			static function ( $c ) {
				return is_string( $c ) ? str_replace( '_others_', '_', $c ) : $c;
			},
			(array) $caps
		);
	}

	/**
	 * Capacidades contextuales por delegación.
	 *
	 * - Si no hay recurso en contexto, deniega (no concede globalmente).
	 *
	 * @param array $allcaps All caps.
	 * @param array $caps    Requested caps.
	 * @param array $args    Context args (cap, user_id, object_id?).
	 * @param WP_User $user  User.
	 * @return array
	 */
	public static function user_has_cap( $allcaps, $caps, $args, $user ) {
		if ( empty( $caps ) || ! is_array( $allcaps ) ) {
			return $allcaps;
		}

		$requested = sanitize_key( (string) ( $args[0] ?? '' ) );
		if ( '' === $requested ) {
			return $allcaps;
		}

		if ( ! in_array( $requested, array( 'clms_grade_submissions', 'clms_manage_enrollments' ), true ) ) {
			return $allcaps;
		}

		// Ya concedida por rol/admin.
		if ( ! empty( $allcaps[ $requested ] ) ) {
			return $allcaps;
		}

		$object_id = absint( $args[2] ?? 0 );
		if ( $object_id <= 0 || ! class_exists( 'ATORA_Delegation_Service' ) ) {
			return $allcaps;
		}

		$perm = ( 'clms_grade_submissions' === $requested ) ? 'grade' : 'enroll';

		$post = get_post( $object_id );
		if ( $post && in_array( (string) $post->post_type, array( 'lm_course', 'lm_lesson' ), true ) ) {
			if ( ATORA_Delegation_Service::covers( absint( $user->ID ), $post, $perm ) ) {
				$allcaps[ $requested ] = true;
			}
			return $allcaps;
		}

		// Submissions: resolver curso desde meta si está disponible.
		if ( $post && 'clms_submission' === (string) $post->post_type ) {
			$wp_course_id = absint( get_post_meta( $object_id, '_clms_submission_course_id', true ) );
			if ( $wp_course_id > 0 ) {
				$course = get_post( $wp_course_id );
				if ( $course && 'lm_course' === (string) $course->post_type ) {
					if ( ATORA_Delegation_Service::covers( absint( $user->ID ), $course, $perm ) ) {
						$allcaps[ $requested ] = true;
					}
				}
			}
		}

		return $allcaps;
	}
}

