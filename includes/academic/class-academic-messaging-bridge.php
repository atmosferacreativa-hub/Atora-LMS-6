<?php
/**
 * CLMS_Academic_Messaging_Bridge — PT-3 (sprint 6.4.0)
 *
 * Conecta los eventos académicos que ya existen en el código (grado
 * publicado, entrega recibida, lección publicada, alertas de IA) con
 * Messaging_Router::send(), sin tocar ni duplicar los listeners
 * existentes (CLMS_Messaging sigue intacto — este puente es un
 * segundo listener en paralelo sobre los mismos hooks, no un
 * reemplazo).
 *
 * Todo handler de aquí es un no-op si
 * Messaging_Router::is_academic_routing_enabled() es false (default)
 * — regla 3 del sprint: cero cambio de comportamiento hasta que un
 * admin active el flag.
 *
 * @package ATORA_LMS
 * @since   6.4.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Academic_Messaging_Bridge {

	public static function init(): void {
		if ( ! class_exists( '\ATORA\Messaging\Messaging_Router' ) ) { return; }

		// PT-3.1 — assignment_graded.
		add_action( 'clms_grade_published', array( __CLASS__, 'on_grade_published' ) );
	}

	/**
	 * PT-3.1: al publicarse una calificación → estudiante.
	 *
	 * @param array $payload {submission_id, student_id, lesson_id, course_id, status, grade, feedback, source, confidence, provider, model_version, is_published} — ver CLMS_Assessment_Engine::publish_submission_grade().
	 */
	public static function on_grade_published( array $payload ): void {
		if ( ! \ATORA\Messaging\Messaging_Router::is_academic_routing_enabled() ) { return; }

		$student_id = absint( $payload['student_id'] ?? 0 );
		$lesson_id  = absint( $payload['lesson_id'] ?? 0 );
		$course_id  = absint( $payload['course_id'] ?? 0 );
		if ( ! $student_id || ! $course_id ) { return; }

		$grade = $payload['grade'] ?? null;
		if ( null === $grade || '' === $grade ) { return; }

		\ATORA\Messaging\Messaging_Router::send(
			$student_id,
			'assignment_graded',
			'atora_assignment_graded',
			array(
				'student_name'  => self::display_name( $student_id ),
				'course_title'  => sanitize_text_field( (string) get_the_title( $course_id ) ),
				'lesson_title'  => sanitize_text_field( (string) get_the_title( $lesson_id ) ),
				'grade'         => sanitize_text_field( (string) $grade ),
				'button_url'    => (string) get_permalink( $lesson_id ),
			),
			array(
				// dedupe por submission: si se re-publica el mismo grade
				// (recalificación) dentro de la ventana, no duplica aviso.
				'dedupe_key'            => 'assignment_graded_' . absint( $payload['submission_id'] ?? 0 ),
				'dedupe_window_minutes' => 15,
			)
		);
	}

	/**
	 * @param int $user_id
	 * @return string
	 */
	private static function display_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? sanitize_text_field( (string) $user->display_name ) : '';
	}
}
