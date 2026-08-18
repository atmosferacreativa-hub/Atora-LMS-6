<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Lesson {

	public function __construct() {
		add_action( 'save_post_lm_lesson', array( $this, 'sync_lesson_course_cache_on_save' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'flush_lesson_cache_on_delete' ), 10 );
	}

	/**
	 * Limpia cach谷 runtime al guardar lecci車n.
	 *
	 * @param int     $post_id ID del post.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Si es actualizaci車n.
	 * @return void
	 */
	public function sync_lesson_course_cache_on_save( $post_id, $post, $update ) {
		unset( $update );

		$post_id = absint( $post_id );

		if ( ! $post_id || ! ( $post instanceof WP_Post ) || 'lm_lesson' !== $post->post_type ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$course_id = CLMS_Helper::get_course_id_from_lesson( $post_id );
		CLMS_Helper::flush_runtime_cache( $course_id, $post_id );
	}

	/**
	 * Limpia cach谷 runtime al borrar lecci車n.
	 *
	 * @param int $post_id ID del post.
	 * @return void
	 */
	public function flush_lesson_cache_on_delete( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || 'lm_lesson' !== get_post_type( $post_id ) ) {
			return;
		}

		$course_id = CLMS_Helper::get_course_id_from_lesson( $post_id );
		CLMS_Helper::flush_runtime_cache( $course_id, $post_id );
	}

	/**
	 * Devuelve el curso de una lecci車n.
	 *
	 * @param int $lesson_id Lecci車n.
	 * @return int
	 */
	public static function get_course_id( $lesson_id ) {
		return absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) );
	}

	/**
	 * Comprueba si la lecci車n pertenece a un curso.
	 *
	 * @param int $lesson_id Lecci車n.
	 * @param int $course_id Curso.
	 * @return bool
	 */
	public static function belongs_to_course( $lesson_id, $course_id ) {
		$lesson_id = absint( $lesson_id );
		$course_id = absint( $course_id );

		if ( ! $lesson_id || ! $course_id ) {
			return false;
		}

		return self::get_course_id( $lesson_id ) === $course_id;
	}

	/**
	 * Comprueba acceso del usuario a la lecci車n.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $lesson_id Lecci車n.
	 * @return bool
	 */
	public static function user_can_access( $user_id, $lesson_id ) {
		return CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id );
	}

	/**
	 * Obtiene la siguiente lecci車n dentro del curso.
	 *
	 * @param int $lesson_id Lecci車n actual.
	 * @return int
	 */
	public static function get_next_lesson_id( $lesson_id ) {
		$lesson_id = absint( $lesson_id );

		if ( ! $lesson_id ) {
			return 0;
		}

		$course_id = self::get_course_id( $lesson_id );

		if ( ! $course_id ) {
			return 0;
		}

		$lessons = CLMS_Helper::get_course_lessons( $course_id );

		if ( empty( $lessons ) ) {
			return 0;
		}

		$lessons = array_values( array_map( 'absint', $lessons ) );
		$index   = array_search( $lesson_id, $lessons, true );

		if ( false === $index ) {
			return 0;
		}

		return isset( $lessons[ $index + 1 ] ) ? absint( $lessons[ $index + 1 ] ) : 0;
	}

	/**
	 * Obtiene la lecci車n anterior dentro del curso.
	 *
	 * @param int $lesson_id Lecci車n actual.
	 * @return int
	 */
	public static function get_previous_lesson_id( $lesson_id ) {
		$lesson_id = absint( $lesson_id );

		if ( ! $lesson_id ) {
			return 0;
		}

		$course_id = self::get_course_id( $lesson_id );

		if ( ! $course_id ) {
			return 0;
		}

		$lessons = CLMS_Helper::get_course_lessons( $course_id );

		if ( empty( $lessons ) ) {
			return 0;
		}

		$lessons = array_values( array_map( 'absint', $lessons ) );
		$index   = array_search( $lesson_id, $lessons, true );

		if ( false === $index || ! isset( $lessons[ $index - 1 ] ) ) {
			return 0;
		}

		return absint( $lessons[ $index - 1 ] );
	}

	/**
	 * Devuelve URL de siguiente lecci車n si existe.
	 *
	 * @param int $lesson_id Lecci車n actual.
	 * @return string
	 */
	public static function get_next_lesson_url( $lesson_id ) {
		$next_id = self::get_next_lesson_id( $lesson_id );

		return $next_id ? (string) get_permalink( $next_id ) : '';
	}

	/**
	 * Devuelve URL de lecci車n anterior si existe.
	 *
	 * @param int $lesson_id Lecci車n actual.
	 * @return string
	 */
	public static function get_previous_lesson_url( $lesson_id ) {
		$prev_id = self::get_previous_lesson_id( $lesson_id );

		return $prev_id ? (string) get_permalink( $prev_id ) : '';
	}
}