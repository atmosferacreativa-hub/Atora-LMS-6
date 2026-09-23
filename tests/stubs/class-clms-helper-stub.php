<?php
/**
 * Stub compartido de CLMS_Helper para tests que necesitan la clase real
 * cargada (varios módulos hacen `class_exists('CLMS_Helper')` como gate).
 *
 * IMPORTANTE: es una clase GLOBAL sin namespace, declarada solo una vez
 * por proceso (`class_exists` guard). Cualquier archivo de test que
 * necesite un método de CLMS_Helper debe añadirlo AQUÍ — declarar una
 * versión distinta/incompleta en otro archivo produce una condición de
 * carrera donde el primer archivo en cargar (orden de PHPUnit, no
 * garantizado alfabético) "gana" y los demás se quedan sin los métodos
 * que ellos mismos necesitan.
 *
 * Todo el estado configurable vive en $GLOBALS y debe resetearse en el
 * tearDown() de cada test que lo toque — si no, contamina tests
 * posteriores no relacionados en la misma corrida de PHPUnit.
 *
 * @package ATORA_LMS\Tests
 */

declare( strict_types = 1 );

if ( ! function_exists( 'atora_test_set_can_manage_course' ) ) {
	function atora_test_set_can_manage_course( bool $can ): void {
		$GLOBALS['__atora_test_can_manage_course'] = $can;
	}
}
if ( ! function_exists( 'atora_test_set_enrolled_students' ) ) {
	function atora_test_set_enrolled_students( int $course_id, array $student_ids ): void {
		$GLOBALS['__atora_test_enrolled_students'][ $course_id ] = $student_ids;
	}
}
if ( ! function_exists( 'atora_test_set_course_lessons' ) ) {
	function atora_test_set_course_lessons( int $course_id, array $lesson_ids ): void {
		$GLOBALS['__atora_test_course_lessons'][ $course_id ] = $lesson_ids;
	}
}
if ( ! function_exists( 'atora_test_set_course_enrollment' ) ) {
	function atora_test_set_course_enrollment( int $user_id, int $course_id, bool $enrolled ): void {
		$GLOBALS['__atora_test_course_enrollment'][ $user_id . ':' . $course_id ] = $enrolled;
	}
}
if ( ! function_exists( 'atora_test_set_enroll_should_succeed' ) ) {
	function atora_test_set_enroll_should_succeed( bool $ok ): void {
		$GLOBALS['__atora_test_enroll_should_succeed'] = $ok;
	}
}
if ( ! function_exists( 'atora_test_get_enroll_calls' ) ) {
	function atora_test_get_enroll_calls(): array {
		return $GLOBALS['__atora_test_enroll_calls'] ?? array();
	}
}
if ( ! function_exists( 'atora_test_reset_clms_helper_stub' ) ) {
	function atora_test_reset_clms_helper_stub(): void {
		$GLOBALS['__atora_test_can_manage_course']       = false;
		$GLOBALS['__atora_test_enrolled_students']       = array();
		$GLOBALS['__atora_test_course_lessons']          = array();
		$GLOBALS['__atora_test_course_enrollment']       = array();
		$GLOBALS['__atora_test_enroll_should_succeed']   = true;
		$GLOBALS['__atora_test_enroll_calls']            = array();

		if ( class_exists( 'CLMS_Helper' ) && property_exists( 'CLMS_Helper', 'enrolled' ) ) {
			CLMS_Helper::$enrolled  = array();
			CLMS_Helper::$completed = array();
		}
	}
}

if ( ! class_exists( 'CLMS_Helper' ) ) {
	final class CLMS_Helper {
		/**
		 * Estado legacy para rutas móviles (lista de wp_post_id por usuario).
		 *
		 * @var array<int, array<int>>
		 */
		public static array $enrolled = array(); // [user_id] => wp_course_post_ids

		/**
		 * Estado legacy de completitud (lista de wp_post_id por usuario).
		 *
		 * @var array<int, array<int>>
		 */
		public static array $completed = array(); // [user_id] => wp_course_post_ids

		public static function get_user_enrolled_courses( $user_id ): array {
			$user_id = (int) $user_id;
			return (array) ( self::$enrolled[ $user_id ] ?? array() );
		}

		public static function user_can_manage_lms( $post_id = 0 ): bool {
			unset( $post_id );
			return ! empty( $GLOBALS['__atora_test_can_manage_course'] );
		}
		public static function get_course_id_from_lesson( int $lesson_id ): int {
			return absint( get_post_meta( $lesson_id, '_clms_lesson_course_id', true ) );
		}
		public static function get_lesson_course_id( int $lesson_id ): int {
			return self::get_course_id_from_lesson( $lesson_id );
		}
		public static function get_enrolled_student_ids( int $course_id ): array {
			return $GLOBALS['__atora_test_enrolled_students'][ $course_id ] ?? array();
		}
		public static function get_course_lessons( int $course_id ): array {
			return $GLOBALS['__atora_test_course_lessons'][ $course_id ] ?? array();
		}

		public static function user_is_enrolled_in_course( int $user_id, int $course_id ): bool {
			$key = $user_id . ':' . $course_id;
			if ( array_key_exists( $key, (array) ( $GLOBALS['__atora_test_course_enrollment'] ?? array() ) ) ) {
				return ! empty( $GLOBALS['__atora_test_course_enrollment'][ $key ] );
			}

			return in_array( $course_id, (array) ( self::$enrolled[ $user_id ] ?? array() ), true );
		}

		public static function is_course_completed( $user_id, $course_id ): bool {
			$user_id   = (int) $user_id;
			$course_id = (int) $course_id;
			return in_array( $course_id, (array) ( self::$completed[ $user_id ] ?? array() ), true );
		}

		public static function enroll_user_in_course( int $user_id, int $course_id ): bool {
			$GLOBALS['__atora_test_enroll_calls'][] = array( 'user_id' => $user_id, 'course_id' => $course_id );
			$ok = array_key_exists( '__atora_test_enroll_should_succeed', $GLOBALS )
				? (bool) $GLOBALS['__atora_test_enroll_should_succeed']
				: true;
			if ( $ok ) {
				$GLOBALS['__atora_test_course_enrollment'][ $user_id . ':' . $course_id ] = true;
				self::$enrolled[ $user_id ] ??= array();
				if ( ! in_array( $course_id, self::$enrolled[ $user_id ], true ) ) {
					self::$enrolled[ $user_id ][] = $course_id;
				}
			}
			return $ok;
		}
	}
}
