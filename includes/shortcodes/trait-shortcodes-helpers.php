<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Shortcodes_Helpers_Trait {
	protected function get_continue_course_url( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $course_id ) {
			return '';
		}

		$course_url = get_permalink( $course_id );
		$lessons    = CLMS_Helper::get_course_lessons( $course_id );
		$lessons    = is_array( $lessons ) ? array_map( 'absint', $lessons ) : array();

		if ( empty( $lessons ) ) {
			return $course_url ? $course_url : '';
		}

		foreach ( $lessons as $lesson_id ) {
			$lesson_url = get_permalink( $lesson_id );

			if ( ! $lesson_url ) {
				continue;
			}

			if ( ! $this->is_lesson_completed_by_user( $user_id, $lesson_id ) ) {
				if ( ! $user_id || CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) || CLMS_Helper::user_can_manage_lms( $course_id ) ) {
					return $lesson_url;
				}
			}
		}

		$last_lesson_id = (int) end( $lessons );
		$last_lesson_url = $last_lesson_id ? get_permalink( $last_lesson_id ) : '';

		return $last_lesson_url ? $last_lesson_url : $course_url;
	}

	/**
	 * Comprueba si la lección está completada.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $lesson_id Lección.
	 * @return bool
	 */
	protected function is_lesson_completed_by_user( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return false;
		}

		$grading = clms_core('CLMS_Grading');

		if ( $grading && method_exists( $grading, 'is_lesson_completed_by_user' ) ) {
			return (bool) $grading->is_lesson_completed_by_user( $user_id, $lesson_id );
		}

		$completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed = is_array( $completed ) ? array_map( 'absint', $completed ) : array();

		return in_array( $lesson_id, $completed, true );
	}

	/**
	 * Texto del tipo de actividad.
	 *
	 * @param int $lesson_id Lección.
	 * @return string
	 */
	protected function get_activity_label( $lesson_id ) {
		$type = (string) CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_activity_type', '_clms_activity_mode' ), '' );
		$type = strtolower( $type );

		switch ( $type ) {
			case 'quiz':
			case 'evaluacion':
			case 'evaluation':
				return 'Evaluación';

			case 'task':
			case 'tarea':
			case 'assignment':
				return 'Tarea';

			case 'lectura':
			case 'reading':
			default:
				return 'Lección';
		}
	}

	/**
	 * Formatea fecha.
	 *
	 * @param string $date Fecha.
	 * @return string
	 */
	protected function format_date( $date ) {
		$date = (string) $date;

		if ( '' === $date ) {
			return '';
		}

		$timestamp = strtotime( $date );

		if ( ! $timestamp ) {
			return $date;
		}

		return wp_date( get_option( 'date_format' ), $timestamp );
	}

	/**
	 * Formatea hora.
	 *
	 * @param string $time Hora.
	 * @return string
	 */
	protected function format_time( $time ) {
		$time = trim( (string) $time );

		if ( '' === $time ) {
			return '';
		}

		$timestamp = strtotime( $time );

		if ( ! $timestamp ) {
			return $time;
		}

		return wp_date( get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Fallback de imagen.
	 *
	 * @param string $text  Texto.
	 * @param string $class Clase CSS.
	 * @return string
	 */
	protected function get_thumb_fallback( $text = 'Sin imagen', $class = '' ) {
		$class = $class ? sanitize_html_class( $class ) : '';
		return '<div class="' . esc_attr( $class ) . '">' . esc_html( $text ) . '</div>';
	}

	/**
	 * Mensaje flash por query string.
	 *
	 * @param int $course_id Curso.
	 * @return string
	 */
	protected function get_flash_message_for_course( $course_id ) {
		$course_id = absint( $course_id );

		if ( ! isset( $_GET['clms_course'], $_GET['clms_msg'] ) ) {
			return '';
		}

		$flash_course = absint( wp_unslash( $_GET['clms_course'] ) );
		$flash_msg    = sanitize_key( wp_unslash( $_GET['clms_msg'] ) );

		if ( $flash_course !== $course_id ) {
			return '';
		}

		if ( 'enrolled' === $flash_msg ) {
			return 'Te has inscrito correctamente.';
		}

		if ( 'purchase_required' === $flash_msg ) {
			return 'Debes completar la compra antes de inscribirte en este curso.';
		}

		return '';
	}
}
