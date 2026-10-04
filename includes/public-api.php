<?php
/**
 * API pública estable del plugin para el tema (6.28.2).
 *
 * Funciones con firma estable: el tema Atora Meridian las usa si existen y
 * cae a su propio respaldo si no. No cambiar firma ni semántica sin subir la
 * versión mínima recomendada en el README del tema.
 *
 * @package ATORA_LMS
 * @since 6.28.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'atora_lms_get_progress' ) ) {
	/**
	 * Progreso del usuario en un curso, de 0 a 100.
	 *
	 * Recibe el ID del **post** del curso (`lm_course`), que es lo que conoce el
	 * tema, y lo traduce al `id` de `atora_courses` antes de consultar
	 * `LMS_Enrollment_Service::get_progress()` (confundir ambos causó el 404 de
	 * matrículas en 6.1.x). Mismo cálculo que la app.
	 *
	 * @param int $user_id      Usuario.
	 * @param int $wp_course_id ID del post `lm_course`.
	 * @return int Porcentaje 0–100 (0 si el curso no tiene fila o no hay servicio).
	 */
	function atora_lms_get_progress( int $user_id, int $wp_course_id ): int {
		if ( $user_id <= 0 || $wp_course_id <= 0
			|| ! class_exists( '\\ATORA\\LMS\\LMS_Course_Service' )
			|| ! class_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service' ) ) {
			return 0;
		}
		$course = \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $wp_course_id );
		if ( ! $course ) {
			return 0;
		}
		$progress = \ATORA\LMS\LMS_Enrollment_Service::get_progress( $user_id, absint( $course['id'] ) );
		return max( 0, min( 100, absint( $progress['progress_pct'] ?? 0 ) ) );
	}
}
