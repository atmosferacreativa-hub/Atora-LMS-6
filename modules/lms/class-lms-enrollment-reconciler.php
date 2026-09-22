<?php
/**
 * LMS Enrollment Reconciler — legacy metas
 *
 * Recorre la lista autoritativa del curso (_clms_enrolled_users) y asegura que
 * exista el espejo del lado del alumno (_clms_enrolled_courses).
 *
 * @package ATORA_LMS\LMS
 * @since   6.26.6
 */
namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LMS_Enrollment_Reconciler {
	private const COURSE_META = '_clms_enrolled_users';
	private const USER_META   = '_clms_enrolled_courses';

	/**
	 * @return int[] ids únicos (ints > 0)
	 */
	private static function id_list( $raw ): array {
		if ( empty( $raw ) ) { return array(); }
		if ( is_string( $raw ) ) {
			$raw = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		}
		$ids = array_filter( array_map( 'absint', is_array( $raw ) ? $raw : array( $raw ) ) );
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Plan (dry-run).
	 *
	 * @return array{pairs_to_fix:int,phantom_pairs_to_prune:int,courses_scanned:int}
	 */
	public static function plan(): array {
		$courses = get_posts( array(
			'post_type'      => 'lm_course',
			// Nota: 'any' NO incluye 'trash'. Lo incluimos explícitamente porque
			// en Lab aparecieron cursos en trash con blobs de matrícula corruptos.
			'post_status'    => array( 'any', 'trash' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$to_fix   = 0;
		$to_prune = 0;
		foreach ( $courses as $course_id ) {
			$all_meta = get_post_meta( (int) $course_id, self::COURSE_META, false );
			$students = array();
			foreach ( (array) $all_meta as $raw ) {
				$students = array_merge( $students, self::id_list( $raw ) );
			}
			$students = array_values( array_unique( array_filter( array_map( 'absint', $students ) ) ) );

			foreach ( $students as $user_id ) {
				if ( ! get_userdata( (int) $user_id ) ) {
					$to_prune++;
					continue;
				}
				$in_user = self::id_list( get_user_meta( (int) $user_id, self::USER_META, true ) );
				if ( ! in_array( (int) $course_id, $in_user, true ) ) {
					$to_fix++;
				}
			}
		}

		return array(
			'pairs_to_fix'           => $to_fix,
			'phantom_pairs_to_prune' => $to_prune,
			'courses_scanned'        => count( $courses ),
		);
	}

	/**
	 * Aplica reconciliación curso → alumno usando el entry point legacy
	 * (CLMS_Helper::enroll_user_in_course), no meta directo.
	 *
	 * También poda IDs de usuario inexistentes del postmeta del curso para que
	 * el paridad-check (curso vs usuarios reales) no quede contaminado.
	 *
	 * @return array{fixed:int,pruned:int,errors:string[]}
	 */
	public static function apply(): array {
		$courses = get_posts( array(
			'post_type'      => 'lm_course',
			'post_status'    => array( 'any', 'trash' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$fixed   = 0;
		$pruned  = 0;
		$errors  = array();

		foreach ( $courses as $course_id ) {
			$course_id = (int) $course_id;
			$all_meta = get_post_meta( $course_id, self::COURSE_META, false );
			$students_raw = array();
			foreach ( (array) $all_meta as $raw ) {
				$students_raw = array_merge( $students_raw, self::id_list( $raw ) );
			}
			$students_raw = array_values( array_unique( array_filter( array_map( 'absint', $students_raw ) ) ) );
			$students     = array();

			foreach ( $students_raw as $user_id ) {
				if ( ! get_userdata( (int) $user_id ) ) {
					$pruned++;
					continue;
				}
				$students[] = (int) $user_id;
			}

			// Poda IDs inexistentes del blob del curso.
			if ( count( $students ) !== count( $students_raw ) || count( (array) $all_meta ) > 1 ) {
				delete_post_meta( $course_id, self::COURSE_META );
				update_post_meta( $course_id, self::COURSE_META, array_values( array_unique( $students ) ) );
			}

			foreach ( $students as $user_id ) {
				$in_user = self::id_list( get_user_meta( (int) $user_id, self::USER_META, true ) );
				if ( in_array( $course_id, $in_user, true ) ) { continue; }

				if ( ! class_exists( '\CLMS_Helper' ) ) {
					$errors[] = "CLMS_Helper no disponible (user_id={$user_id}, course_id={$course_id}).";
					continue;
				}
				$ok = (bool) \CLMS_Helper::enroll_user_in_course( (int) $user_id, $course_id );
				if ( $ok ) {
					$fixed++;
				} else {
					$errors[] = "No se pudo matricular (user_id={$user_id}, course_id={$course_id}).";
				}
			}
		}

		return array(
			'fixed'  => $fixed,
			'pruned' => $pruned,
			'errors' => $errors,
		);
	}
}
