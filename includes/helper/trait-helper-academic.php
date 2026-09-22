<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Helper_Academic_Trait {
	/**
	 * Obtiene el course_id desde una lección (helper canónico).
	 *
	 * @param int $lesson_id ID de lección.
	 * @return int
	 */
	public static function get_lesson_course_id( $lesson_id ) {
		$lesson_id = absint( $lesson_id );

		if ( ! $lesson_id ) {
			return 0;
		}

		if ( isset( self::$lesson_course_cache[ $lesson_id ] ) ) {
			return self::$lesson_course_cache[ $lesson_id ];
		}

		$course_id  = absint( get_post_meta( $lesson_id, self::COURSE_META_KEY, true ) );
		$source_key = '';

		if ( ! $course_id ) {
			$course_id  = absint( get_post_meta( $lesson_id, self::COURSE_META_KEY_LEGACY, true ) );
			$source_key = $course_id ? self::COURSE_META_KEY_LEGACY : '';
		}

		if ( ! $course_id ) {
			foreach ( self::COURSE_META_KEYS_FALLBACK as $meta_key ) {
				$meta_key = is_string( $meta_key ) ? trim( $meta_key ) : '';
				if ( '' === $meta_key ) {
					continue;
				}
				$value = get_post_meta( $lesson_id, $meta_key, true );
				if ( '' !== $value && null !== $value ) {
					$course_id  = absint( $value );
					$source_key = $meta_key;
					break;
				}
			}
		}

		if ( $course_id && $source_key && self::COURSE_META_KEY !== $source_key ) {
			update_post_meta( $lesson_id, self::COURSE_META_KEY, $course_id );
		}

		self::$lesson_course_cache[ $lesson_id ] = $course_id;

		return $course_id;
	}

	/**
	 * Compatibilidad: alias del helper canónico.
	 *
	 * @param int $lesson_id ID de lección.
	 * @return int
	 */
	public static function get_course_id_from_lesson( $lesson_id ) {
		return self::get_lesson_course_id( $lesson_id );
	}

	/**
	 * Guarda relación lección -> curso usando la key canónica.
	 *
	 * @param int  $lesson_id
	 * @param int  $course_id
	 * @param bool $update_legacy
	 * @return bool
	 */
	public static function set_course_id_for_lesson( $lesson_id, $course_id, $update_legacy = true ) {
		$lesson_id = absint( $lesson_id );
		$course_id = absint( $course_id );

		if ( ! $lesson_id ) {
			return false;
		}

		if ( ! $course_id ) {
			delete_post_meta( $lesson_id, self::COURSE_META_KEY );
			if ( $update_legacy ) {
				delete_post_meta( $lesson_id, self::COURSE_META_KEY_LEGACY );
			}
			unset( self::$lesson_course_cache[ $lesson_id ] );
			return true;
		}

		update_post_meta( $lesson_id, self::COURSE_META_KEY, $course_id );
		if ( $update_legacy ) {
			update_post_meta( $lesson_id, self::COURSE_META_KEY_LEGACY, $course_id );
		}
		self::$lesson_course_cache[ $lesson_id ] = $course_id;
		return true;
	}

	/**
	 * Obtiene las lecciones de un curso.
	 *
	 * @param int $course_id ID del curso.
	 * @return array<int,int>
	 */
	public static function get_course_lessons( $course_id ) {
		$course_id = absint( $course_id );

		if ( ! $course_id ) {
			return array();
		}

		if ( isset( self::$course_lessons_cache[ $course_id ] ) && ! empty( self::$course_lessons_cache[ $course_id ] ) ) {
			$cached_lesson_ids = array_values( array_filter( array_map( 'absint', (array) self::$course_lessons_cache[ $course_id ] ) ) );
			$valid_cached_ids  = array();
			foreach ( $cached_lesson_ids as $lesson_id ) {
				if ( 'lm_lesson' !== get_post_type( $lesson_id ) ) {
					continue;
				}
				$cached_course_id = absint( get_post_meta( $lesson_id, self::COURSE_META_KEY, true ) );
				if ( ! $cached_course_id ) {
					$cached_course_id = absint( get_post_meta( $lesson_id, self::COURSE_META_KEY_LEGACY, true ) );
				}
				if ( ! $cached_course_id ) {
					foreach ( self::COURSE_META_KEYS_FALLBACK as $meta_key ) {
						$meta_key = is_string( $meta_key ) ? trim( $meta_key ) : '';
						if ( '' === $meta_key ) {
							continue;
						}
						$cached_course_id = absint( get_post_meta( $lesson_id, $meta_key, true ) );
						if ( $cached_course_id ) {
							break;
						}
					}
				}
				if ( $course_id === $cached_course_id ) {
					$valid_cached_ids[] = $lesson_id;
				}
			}
			if ( ! empty( $valid_cached_ids ) ) {
				return $valid_cached_ids;
			}
			unset( self::$course_lessons_cache[ $course_id ] );
		}

		$lesson_ids = self::query_lessons_by_course_meta( $course_id, self::COURSE_META_KEY );

		if ( empty( $lesson_ids ) ) {
			$lesson_ids = self::query_lessons_by_course_meta( $course_id, self::COURSE_META_KEY_LEGACY );
		}

		if ( empty( $lesson_ids ) ) {
			foreach ( self::COURSE_META_KEYS_FALLBACK as $meta_key ) {
				$lesson_ids = self::query_lessons_by_course_meta( $course_id, $meta_key );
				if ( ! empty( $lesson_ids ) ) {
					break;
				}
			}
		}

		$lesson_ids = array_values( array_unique( array_filter( array_map( 'absint', $lesson_ids ) ) ) );

		foreach ( $lesson_ids as $lesson_id ) {
			self::$lesson_course_cache[ $lesson_id ] = $course_id;
			// Autocorrección: asegura que la key canónica quede persistida.
			self::get_lesson_course_id( $lesson_id );
		}

		if ( empty( $lesson_ids ) ) {
			unset( self::$course_lessons_cache[ $course_id ] );
			return array();
		}

		self::$course_lessons_cache[ $course_id ] = $lesson_ids;

		return $lesson_ids;
	}

	/**
	 * Cuenta lecciones de un curso.
	 *
	 * @param int $course_id ID del curso.
	 * @return int
	 */
	public static function get_course_lesson_count( $course_id ) {
		return count( self::get_course_lessons( $course_id ) );
	}

	/**
	 * Saber si un usuario está inscrito en un curso.
	 *
	 * @param int $user_id ID usuario.
	 * @param int $course_id ID curso.
	 * @return bool
	 */
	public static function user_is_enrolled_in_course( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return false;
		}

		if ( self::user_can_manage_lms( $course_id ) ) {
			return true;
		}

		if ( self::has_user_course_access_expired( $user_id, $course_id ) ) {
			return false;
		}

		// F4.1 — tables is canonical source.
		if ( class_exists( '\ATORA\LMS\LMS_Read_Router' ) && \ATORA\LMS\LMS_Read_Router::is_tables()
			&& class_exists( '\ATORA\LMS\LMS_Enrollment_Service' ) ) {
			$result = \ATORA\LMS\LMS_Enrollment_Service::is_enrolled_by_wp_id( $user_id, $course_id );
			if ( class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
				try { \ATORA\LMS\LMS_Parity::shadow_is_enrolled_pc( $user_id, $course_id, $result ); } catch ( \Throwable $e ) {}
			}
			return $result;
		}

		$user_courses = self::get_user_enrolled_courses( $user_id );

		if ( in_array( $course_id, $user_courses, true ) ) {
			$result = true;
		} else {
			$course_users = self::get_enrolled_student_ids( $course_id );
			$result       = in_array( $user_id, $course_users, true );
		}

		// F3.2 — shadow-read
		if ( class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
			try { \ATORA\LMS\LMS_Parity::shadow_is_enrolled( $user_id, $course_id, $result ); } catch ( \Throwable $e ) {}
		}

		return $result;
	}

	/**
	 * Saber si un usuario puede acceder a un curso.
	 *
	 * @param int $user_id ID usuario.
	 * @param int $course_id ID curso.
	 * @return bool
	 */
	public static function user_can_access_course( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return false;
		}

		return self::user_is_enrolled_in_course( $user_id, $course_id );
	}

	/**
	 * Obtiene cursos agrupados en un programa.
	 *
	 * @param int $program_id ID programa.
	 * @return array<int,int>
	 */
	public static function get_program_courses( $program_id ) {
		$program_id = absint( $program_id );

		if ( ! $program_id || 'lm_program' !== get_post_type( $program_id ) ) {
			return array();
		}

		$course_ids = get_post_meta( $program_id, self::PROGRAM_COURSES_META, true );
		$course_ids = self::normalize_id_list( $course_ids );

		return array_values(
			array_filter(
				$course_ids,
				static function( $course_id ) {
					return 'lm_course' === get_post_type( $course_id );
				}
			)
		);
	}

	/**
	 * Obtiene programas asociados a un curso.
	 *
	 * @param int $course_id ID curso.
	 * @return array<int,int>
	 */
	public static function get_course_program_ids( $course_id ) {
		$course_id = absint( $course_id );

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return array();
		}

		$program_ids = get_post_meta( $course_id, self::COURSE_PROGRAMS_META, true );
		$program_ids = self::normalize_id_list( $program_ids );

		return array_values(
			array_filter(
				$program_ids,
				static function( $program_id ) {
					return 'lm_program' === get_post_type( $program_id );
				}
			)
		);
	}

	/**
	 * Sincroniza cursos de un programa y su relación inversa.
	 *
	 * @param int   $program_id  Programa.
	 * @param array $course_ids  Cursos.
	 * @return array<int,int>
	 */
	public static function sync_program_courses( $program_id, $course_ids ) {
		$program_id = absint( $program_id );

		if ( ! $program_id || 'lm_program' !== get_post_type( $program_id ) ) {
			return array();
		}

		$existing = self::get_program_courses( $program_id );
		$updated  = array_values(
			array_filter(
				self::normalize_id_list( $course_ids ),
				static function( $course_id ) {
					return 'lm_course' === get_post_type( $course_id );
				}
			)
		);

		update_post_meta( $program_id, self::PROGRAM_COURSES_META, $updated );

		foreach ( array_diff( $existing, $updated ) as $course_id ) {
			$course_programs = self::get_course_program_ids( $course_id );
			$course_programs = array_values( array_diff( $course_programs, array( $program_id ) ) );
			update_post_meta( $course_id, self::COURSE_PROGRAMS_META, $course_programs );
		}

		foreach ( $updated as $course_id ) {
			$course_programs = self::get_course_program_ids( $course_id );

			if ( ! in_array( $program_id, $course_programs, true ) ) {
				$course_programs[] = $program_id;
				update_post_meta( $course_id, self::COURSE_PROGRAMS_META, self::normalize_id_list( $course_programs ) );
			}
		}

		return $updated;
	}

	/**
	 * Sincroniza programas asociados a un curso y su relación inversa.
	 *
	 * @param int   $course_id    Curso.
	 * @param array $program_ids  Programas.
	 * @return array<int,int>
	 */
	public static function assign_course_to_programs( $course_id, $program_ids ) {
		$course_id = absint( $course_id );

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return array();
		}

		$existing = self::get_course_program_ids( $course_id );
		$updated  = array_values(
			array_filter(
				self::normalize_id_list( $program_ids ),
				static function( $program_id ) {
					return 'lm_program' === get_post_type( $program_id );
				}
			)
		);

		update_post_meta( $course_id, self::COURSE_PROGRAMS_META, $updated );

		foreach ( array_diff( $existing, $updated ) as $program_id ) {
			$program_courses = self::get_program_courses( $program_id );
			$program_courses = array_values( array_diff( $program_courses, array( $course_id ) ) );
			update_post_meta( $program_id, self::PROGRAM_COURSES_META, $program_courses );
		}

		foreach ( $updated as $program_id ) {
			$program_courses = self::get_program_courses( $program_id );

			if ( ! in_array( $course_id, $program_courses, true ) ) {
				$program_courses[] = $course_id;
				update_post_meta( $program_id, self::PROGRAM_COURSES_META, self::normalize_id_list( $program_courses ) );
			}
		}

		return $updated;
	}

	/**
	 * Indica si un curso usa modo comercial.
	 *
	 * @param int $course_id ID curso.
	 * @return bool
	 */
	public static function is_course_commercial( $course_id ) {
		$course_id = absint( $course_id );

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return false;
		}

		return 'commercial' === (string) get_post_meta( $course_id, '_clms_commercial_mode', true );
	}

	/**
	 * Indica si un programa usa modo comercial.
	 *
	 * @param int $program_id ID programa.
	 * @return bool
	 */
	public static function is_program_commercial( $program_id ) {
		$program_id = absint( $program_id );

		if ( ! $program_id || 'lm_program' !== get_post_type( $program_id ) ) {
			return false;
		}

		return 'commercial' === (string) get_post_meta( $program_id, '_clms_commercial_mode', true );
	}

	/**
	 * Determina si el usuario puede autoinscribirse en un programa.
	 *
	 * @param int $program_id ID programa.
	 * @param int $user_id    ID usuario opcional.
	 * @return bool
	 */
	public static function can_self_enroll_in_program( $program_id, $user_id = 0 ) {
		$program_id = absint( $program_id );
		$user_id    = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $program_id || 'lm_program' !== get_post_type( $program_id ) ) {
			return false;
		}

		if ( $user_id && self::user_can_manage_lms( $program_id ) ) {
			return true;
		}

		foreach ( self::get_program_courses( $program_id ) as $course_id ) {
			if ( ! self::can_self_enroll_in_course( $course_id, $user_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Determina si el usuario puede autoinscribirse sin pasar por comercio.
	 *
	 * @param int $course_id ID curso.
	 * @param int $user_id   ID usuario opcional.
	 * @return bool
	 */
	public static function can_self_enroll_in_course( $course_id, $user_id = 0 ) {
		$course_id = absint( $course_id );
		$user_id   = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return false;
		}

		if ( $user_id && self::user_can_manage_lms( $course_id ) ) {
			return true;
		}

		return ! self::is_course_commercial( $course_id );
	}

	/**
	 * Saber si un usuario puede acceder a una lección.
	 *
	 * @param int $user_id ID usuario.
	 * @param int $lesson_id ID lección.
	 * @return bool
	 */
	public static function user_can_access_lesson( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return false;
		}

		if ( self::user_can_manage_lms( $lesson_id ) ) {
			return true;
		}

		$course_id = self::get_course_id_from_lesson( $lesson_id );

		if ( ! $course_id || ! self::user_is_enrolled_in_course( $user_id, $course_id ) ) {
			return false;
		}

		$drip = self::module( 'CLMS_Drip' );

		if ( $drip ) {
			if ( method_exists( $drip, 'is_lesson_available' ) ) {
				return (bool) $drip->is_lesson_available( $user_id, $lesson_id );
			}

			if ( is_callable( array( 'CLMS_Drip', 'is_lesson_available' ) ) ) {
				return (bool) CLMS_Drip::is_lesson_available( $user_id, $lesson_id );
			}
		}

		return true;
	}

	/**
	 * Inscribir usuario en curso.
	 *
	 * @param int $user_id ID usuario.
	 * @param int $course_id ID curso.
	 * @return bool
	 */
	public static function enroll_user_in_course( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return false;
		}

		$user_courses = self::get_user_enrolled_courses( $user_id );

		if ( ! in_array( $course_id, $user_courses, true ) ) {
			$user_courses[] = $course_id;
			$user_courses   = array_values( array_unique( array_filter( array_map( 'absint', $user_courses ) ) ) );
			update_user_meta( $user_id, self::USER_ENROLLED_META, $user_courses );
		}

		$course_users = self::get_enrolled_student_ids( $course_id );

		if ( ! in_array( $user_id, $course_users, true ) ) {
			$course_users[] = $user_id;
			$course_users   = array_values( array_unique( array_filter( array_map( 'absint', $course_users ) ) ) );
			update_post_meta( $course_id, self::COURSE_ENROLLED_META, $course_users );
		}

		$user_dates = get_user_meta( $user_id, self::USER_ENROLLMENT_DATES_META, true );
		$user_dates = is_array( $user_dates ) ? $user_dates : array();

		if ( empty( $user_dates[ $course_id ] ) ) {
			$user_dates[ $course_id ] = current_time( 'mysql' );
			update_user_meta( $user_id, self::USER_ENROLLMENT_DATES_META, $user_dates );
		}

		$course_dates = get_post_meta( $course_id, self::COURSE_ENROLLMENT_DATES_META, true );
		$course_dates = is_array( $course_dates ) ? $course_dates : array();

		if ( empty( $course_dates[ $user_id ] ) ) {
			$course_dates[ $user_id ] = current_time( 'mysql' );
			update_post_meta( $course_id, self::COURSE_ENROLLMENT_DATES_META, $course_dates );
		}

		do_action( 'clms_user_enrolled', $user_id, $course_id );

		return true;
	}

	/**
	 * Inscribe usuario en un programa y en todos sus cursos.
	 *
	 * @param int $user_id    Usuario.
	 * @param int $program_id Programa.
	 * @return array|WP_Error
	 */
	public static function enroll_user_in_program( $user_id, $program_id ) {
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );

		if ( ! $user_id || ! $program_id || 'lm_program' !== get_post_type( $program_id ) ) {
			return new WP_Error( 'invalid_program_enrollment', __( 'Programa inválido para inscripción.', 'atora-lms' ) );
		}

		$courses = self::get_program_courses( $program_id );

		$user_programs = self::get_user_enrolled_programs( $user_id );
		if ( ! in_array( $program_id, $user_programs, true ) ) {
			$user_programs[] = $program_id;
			update_user_meta( $user_id, self::USER_ENROLLED_PROGRAMS_META, self::normalize_id_list( $user_programs ) );
		}

		$program_users = get_post_meta( $program_id, self::PROGRAM_ENROLLED_USERS_META, true );
		$program_users = self::normalize_id_list( $program_users );
		if ( ! in_array( $user_id, $program_users, true ) ) {
			$program_users[] = $user_id;
			update_post_meta( $program_id, self::PROGRAM_ENROLLED_USERS_META, self::normalize_id_list( $program_users ) );
		}

		$result = array(
			'program_id'               => $program_id,
			'courses'                  => $courses,
			'newly_enrolled_courses'   => array(),
			'already_enrolled_courses' => array(),
		);

		foreach ( $courses as $course_id ) {
			if ( self::user_is_enrolled_in_course( $user_id, $course_id ) ) {
				$result['already_enrolled_courses'][] = $course_id;
				continue;
			}

			if ( self::enroll_user_in_course( $user_id, $course_id ) ) {
				$result['newly_enrolled_courses'][] = $course_id;
			}
		}

		do_action( 'clms_user_enrolled_in_program', $user_id, $program_id, $result );

		return $result;
	}

	/**
	 * Obtener cursos inscritos de un usuario.
	 *
	 * @param int $user_id ID usuario.
	 * @return array<int,int>
	 */
	public static function get_user_enrolled_courses( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		// F4.1 — tables is canonical source.
		if ( class_exists( '\ATORA\LMS\LMS_Read_Router' ) && \ATORA\LMS\LMS_Read_Router::is_tables()
			&& class_exists( '\ATORA\LMS\LMS_Enrollment_Service' ) ) {
			$result = \ATORA\LMS\LMS_Enrollment_Service::get_enrolled_wp_course_ids( $user_id );
			if ( class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
				try { \ATORA\LMS\LMS_Parity::shadow_enrolled_courses_pc( $user_id, $result ); } catch ( \Throwable $e ) {}
			}
			return $result;
		}

		$course_ids = get_user_meta( $user_id, self::USER_ENROLLED_META, true );
		$course_ids = is_array( $course_ids ) ? array_map( 'absint', $course_ids ) : array();

		$course_ids = array_filter(
			$course_ids,
			static function( $course_id ) use ( $user_id ) {
				return ! self::has_user_course_access_expired( $user_id, $course_id );
			}
		);

		$result = array_values( array_unique( array_filter( $course_ids ) ) );
		// F3.2 — shadow-read (no afecta el resultado; falla en silencio)
		if ( class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
			try { \ATORA\LMS\LMS_Parity::shadow_enrolled_courses( $user_id, $result ); } catch ( \Throwable $e ) {}
		}
		return $result;
	}

	/**
	 * Obtener programas inscritos de un usuario.
	 *
	 * @param int $user_id ID usuario.
	 * @return array<int,int>
	 */
	public static function get_user_enrolled_programs( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		// F4.1 — tables is canonical source.
		if ( class_exists( '\ATORA\LMS\LMS_Read_Router' ) && \ATORA\LMS\LMS_Read_Router::is_tables()
			&& class_exists( '\ATORA\LMS\LMS_Enrollment_Service' ) ) {
			$result = \ATORA\LMS\LMS_Enrollment_Service::get_enrolled_wp_program_ids( $user_id );
			if ( class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
				try { \ATORA\LMS\LMS_Parity::shadow_enrolled_programs_pc( $user_id, $result ); } catch ( \Throwable $e ) {}
			}
			return $result;
		}

		$program_ids = get_user_meta( $user_id, self::USER_ENROLLED_PROGRAMS_META, true );
		$program_ids = self::normalize_id_list( $program_ids );

		$result = array_values(
			array_filter(
				$program_ids,
				static function( $program_id ) use ( $user_id ) {
					return 'lm_program' === get_post_type( $program_id ) && ! self::has_user_program_access_expired( $user_id, $program_id );
				}
			)
		);
		// F3.2 — shadow-read
		if ( class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
			try { \ATORA\LMS\LMS_Parity::shadow_enrolled_programs( $user_id, $result ); } catch ( \Throwable $e ) {}
		}
		return $result;
	}

	/**
	 * Verifica si el usuario está inscrito en un programa.
	 *
	 * @param int $user_id    Usuario.
	 * @param int $program_id Programa.
	 * @return bool
	 */
	public static function user_is_enrolled_in_program( $user_id, $program_id ) {
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );

		if ( ! $user_id || ! $program_id || 'lm_program' !== get_post_type( $program_id ) ) {
			return false;
		}

		if ( self::user_can_manage_lms( $program_id ) ) {
			return true;
		}

		if ( self::has_user_program_access_expired( $user_id, $program_id ) ) {
			return false;
		}

		$user_programs = self::get_user_enrolled_programs( $user_id );
		if ( in_array( $program_id, $user_programs, true ) ) {
			return true;
		}

		$program_users = get_post_meta( $program_id, self::PROGRAM_ENROLLED_USERS_META, true );
		$program_users = self::normalize_id_list( $program_users );

		return in_array( $user_id, $program_users, true );
	}

	/**
	 * Obtiene mapa de expiración por curso del usuario.
	 *
	 * @param int $user_id Usuario.
	 * @return array<string,string>
	 */
	public static function get_user_course_access_expirations( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		$expirations = get_user_meta( $user_id, self::USER_COURSE_ACCESS_EXPIRY_META, true );

		return is_array( $expirations ) ? $expirations : array();
	}

	/**
	 * Obtiene mapa de expiración por programa del usuario.
	 *
	 * @param int $user_id Usuario.
	 * @return array<string,string>
	 */
	public static function get_user_program_access_expirations( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		$expirations = get_user_meta( $user_id, self::USER_PROGRAM_ACCESS_EXPIRY_META, true );

		return is_array( $expirations ) ? $expirations : array();
	}

	/**
	 * Guarda expiración de acceso para un curso.
	 *
	 * @param int    $user_id Usuario.
	 * @param int    $course_id Curso.
	 * @param string $expires_at Fecha mysql.
	 * @return bool
	 */
	public static function set_user_course_access_expiration( $user_id, $course_id, $expires_at ) {
		$user_id    = absint( $user_id );
		$course_id  = absint( $course_id );
		$expires_at = sanitize_text_field( (string) $expires_at );

		if ( ! $user_id || ! $course_id || ! $expires_at ) {
			return false;
		}

		$expirations              = self::get_user_course_access_expirations( $user_id );
		$expirations[ $course_id ] = $expires_at;

		$saved = (bool) update_user_meta( $user_id, self::USER_COURSE_ACCESS_EXPIRY_META, $expirations );

		if ( $saved ) {
			do_action( 'clms_user_course_access_expiration_updated', $user_id, $course_id, $expires_at );
		}

		return $saved;
	}

	/**
	 * Guarda expiración de acceso para un programa.
	 *
	 * @param int    $user_id Usuario.
	 * @param int    $program_id Programa.
	 * @param string $expires_at Fecha mysql.
	 * @return bool
	 */
	public static function set_user_program_access_expiration( $user_id, $program_id, $expires_at ) {
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );
		$expires_at = sanitize_text_field( (string) $expires_at );

		if ( ! $user_id || ! $program_id || ! $expires_at ) {
			return false;
		}

		$expirations               = self::get_user_program_access_expirations( $user_id );
		$expirations[ $program_id ] = $expires_at;

		return (bool) update_user_meta( $user_id, self::USER_PROGRAM_ACCESS_EXPIRY_META, $expirations );
	}

	/**
	 * Obtiene expiración de acceso de un curso para un usuario.
	 *
	 * @param int $user_id Usuario.
	 * @param int $course_id Curso.
	 * @return string
	 */
	public static function get_user_course_access_expiration( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id ) {
			return '';
		}

		// F4.1 — tables is canonical source.
		if ( class_exists( '\ATORA\LMS\LMS_Read_Router' ) && \ATORA\LMS\LMS_Read_Router::is_tables()
			&& class_exists( '\ATORA\LMS\LMS_Enrollment_Service' ) ) {
			$result = \ATORA\LMS\LMS_Enrollment_Service::get_access_expiry_by_wp_id( $user_id, $course_id );
			if ( class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
				try { \ATORA\LMS\LMS_Parity::shadow_access_expiry_pc( $user_id, $course_id, $result ); } catch ( \Throwable $e ) {}
			}
			return $result;
		}

		$expirations = self::get_user_course_access_expirations( $user_id );

		$result = isset( $expirations[ $course_id ] ) ? sanitize_text_field( (string) $expirations[ $course_id ] ) : '';
		// F3.2 — shadow-read
		if ( class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
			try { \ATORA\LMS\LMS_Parity::shadow_access_expiry( $user_id, $course_id, $result ); } catch ( \Throwable $e ) {}
		}
		return $result;
	}

	/**
	 * Obtiene expiración de acceso de un programa para un usuario.
	 *
	 * @param int $user_id Usuario.
	 * @param int $program_id Programa.
	 * @return string
	 */
	public static function get_user_program_access_expiration( $user_id, $program_id ) {
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );

		if ( ! $user_id || ! $program_id ) {
			return '';
		}

		$expirations = self::get_user_program_access_expirations( $user_id );

		return isset( $expirations[ $program_id ] ) ? sanitize_text_field( (string) $expirations[ $program_id ] ) : '';
	}

	/**
	 * Indica si el acceso al curso expiró.
	 *
	 * @param int $user_id Usuario.
	 * @param int $course_id Curso.
	 * @return bool
	 */
	public static function has_user_course_access_expired( $user_id, $course_id ) {
		return self::is_access_datetime_expired( self::get_user_course_access_expiration( $user_id, $course_id ) );
	}

	/**
	 * Indica si el acceso al programa expiró.
	 *
	 * @param int $user_id Usuario.
	 * @param int $program_id Programa.
	 * @return bool
	 */
	public static function has_user_program_access_expired( $user_id, $program_id ) {
		return self::is_access_datetime_expired( self::get_user_program_access_expiration( $user_id, $program_id ) );
	}

	/**
	 * Obtener estudiantes inscritos en un curso.
	 *
	 * @param int $course_id ID curso.
	 * @return array<int,int>
	 */
	public static function get_enrolled_student_ids( $course_id ) {
		$course_id = absint( $course_id );

		if ( ! $course_id ) {
			return array();
		}

		// Match the canonical read source used by the other enrollment readers.
		// Do not merge stale legacy rosters after F4 (withdrawn students could reappear).
		if ( class_exists( '\\ATORA\\LMS\\LMS_Read_Router' ) && \ATORA\LMS\LMS_Read_Router::is_tables()
			&& class_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service' ) ) {
			return \ATORA\LMS\LMS_Enrollment_Service::get_student_ids_by_wp_course_id( $course_id );
		}

		$student_ids = get_post_meta( $course_id, self::COURSE_ENROLLED_META, true );
		$student_ids = is_array( $student_ids ) ? array_map( 'absint', $student_ids ) : array();

		return array_values( array_unique( array_filter( $student_ids ) ) );
	}

	/**
	 * Obtiene prerrequisitos de un curso.
	 *
	 * @param int $course_id Curso.
	 * @return array<int,int>
	 */
	public static function get_course_prerequisite_ids( $course_id ) {
		$course_id = absint( $course_id );

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return array();
		}

		$ids = self::normalize_id_list( get_post_meta( $course_id, self::COURSE_PREREQUISITES_META, true ) );

		return array_values(
			array_filter(
				$ids,
				static function( $candidate_id ) use ( $course_id ) {
					return $candidate_id !== $course_id && 'lm_course' === get_post_type( $candidate_id );
				}
			)
		);
	}

	/**
	 * Obtiene prerrequisitos directos de una lección.
	 *
	 * @param int $lesson_id Lección.
	 * @return array<int,int>
	 */
	public static function get_lesson_prerequisite_ids( $lesson_id ) {
		$lesson_id = absint( $lesson_id );

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return array();
		}

		$ids = self::normalize_id_list( get_post_meta( $lesson_id, self::LESSON_PREREQUISITES_META, true ) );

		return array_values(
			array_filter(
				$ids,
				static function( $candidate_id ) use ( $lesson_id ) {
					return $candidate_id !== $lesson_id && 'lm_lesson' === get_post_type( $candidate_id );
				}
			)
		);
	}

	/**
	 * Saber si un usuario completó un curso.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $course_id Curso.
	 * @return bool
	 */
	public static function is_course_completed( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return false;
		}

		// F4.1 — tables is canonical source.
		if ( class_exists( '\ATORA\LMS\LMS_Read_Router' ) && \ATORA\LMS\LMS_Read_Router::is_tables()
			&& class_exists( '\ATORA\LMS\LMS_Enrollment_Service' ) ) {
			$result = \ATORA\LMS\LMS_Enrollment_Service::is_course_completed_by_wp_id( $user_id, $course_id );
			if ( class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
				try { \ATORA\LMS\LMS_Parity::shadow_course_completed_pc( $user_id, $course_id, $result ); } catch ( \Throwable $e ) {}
			}
			return $result;
		}

		$lesson_ids = self::get_course_lessons( $course_id );

		if ( empty( $lesson_ids ) ) {
			return false;
		}

		$completed_lessons = self::normalize_id_list( get_user_meta( $user_id, '_clms_completed_lessons', true ) );

		$result = count( array_intersect( $lesson_ids, $completed_lessons ) ) === count( $lesson_ids );
		// F3.2 — shadow-read
		if ( class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
			try { \ATORA\LMS\LMS_Parity::shadow_course_completed( $user_id, $course_id, $result ); } catch ( \Throwable $e ) {}
		}
		return $result;
	}

	/**
	 * Evalúa si el usuario cumple prerrequisitos de un curso.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $course_id Curso.
	 * @return bool
	 */
	public static function are_course_prerequisites_met( $user_id, $course_id ) {
		foreach ( self::get_course_prerequisite_ids( $course_id ) as $required_course_id ) {
			if ( ! self::is_course_completed( $user_id, $required_course_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Evalúa si el usuario cumple prerrequisitos de una lección.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $lesson_id Lección.
	 * @return bool
	 */
	public static function are_lesson_prerequisites_met( $user_id, $lesson_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		$completed_lessons = self::normalize_id_list( get_user_meta( $user_id, '_clms_completed_lessons', true ) );

		foreach ( self::get_lesson_prerequisite_ids( $lesson_id ) as $required_lesson_id ) {
			if ( ! in_array( $required_lesson_id, $completed_lessons, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Construye estructura base de malla curricular para un programa.
	 *
	 * @param int $program_id Programa.
	 * @param int $user_id    Usuario opcional.
	 * @return array
	 */
	public static function get_program_curriculum( $program_id, $user_id = 0 ) {
		$program_id = absint( $program_id );
		$user_id    = absint( $user_id );
		$program    = get_post( $program_id );

		if ( ! $program || 'lm_program' !== $program->post_type ) {
			return array();
		}

		$courses = array();

		foreach ( self::get_program_courses( $program_id ) as $course_id ) {
			$lesson_ids         = self::get_course_lessons( $course_id );
			$prerequisite_ids   = self::get_course_prerequisite_ids( $course_id );
			$is_enrolled        = $user_id ? self::user_is_enrolled_in_course( $user_id, $course_id ) : false;
			$is_completed       = $user_id ? self::is_course_completed( $user_id, $course_id ) : false;
			$prerequisites_met  = $user_id ? self::are_course_prerequisites_met( $user_id, $course_id ) : empty( $prerequisite_ids );
			$progress_percent   = 0;

			if ( $user_id && ! empty( $lesson_ids ) ) {
				$completed_lessons = self::normalize_id_list( get_user_meta( $user_id, '_clms_completed_lessons', true ) );
				$done              = count( array_intersect( $lesson_ids, $completed_lessons ) );
				$progress_percent  = (int) round( ( $done / count( $lesson_ids ) ) * 100 );
			}

			$courses[] = array(
				'id'                     => $course_id,
				'title'                  => get_the_title( $course_id ),
				'url'                    => get_permalink( $course_id ),
				'lesson_count'           => count( $lesson_ids ),
				'program_ids'            => self::get_course_program_ids( $course_id ),
				'prerequisite_course_ids'=> $prerequisite_ids,
				'prerequisites_met'      => $prerequisites_met,
				'is_enrolled'            => $is_enrolled,
				'is_completed'           => $is_completed,
				'progress_percent'       => $progress_percent,
			);
		}

		return array(
			'id'           => $program_id,
			'title'        => get_the_title( $program_id ),
			'url'          => get_permalink( $program_id ),
			'course_count' => count( $courses ),
			'courses'      => $courses,
		);
	}

	/**
	 * Malla curricular base del estudiante para sus programas.
	 *
	 * @param int $user_id Usuario.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_user_program_curriculum_map( $user_id ) {
		$user_id   = absint( $user_id );
		$programs  = array();

		if ( ! $user_id ) {
			return $programs;
		}

		foreach ( self::get_user_enrolled_programs( $user_id ) as $program_id ) {
			$curriculum = self::get_program_curriculum( $program_id, $user_id );

			if ( ! empty( $curriculum ) ) {
				$programs[] = $curriculum;
			}
		}

		return $programs;
	}
}
