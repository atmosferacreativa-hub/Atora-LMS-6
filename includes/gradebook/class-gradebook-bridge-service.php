<?php
/**
 * Puente entre Gradebook, SpeedGrade y Rubrics.
 *
 * Regla arquitectónica:
 *   SpeedGrade corrige.
 *   Rubrics justifican.
 *   Gradebook resume, permite excepciones controladas y recalcula.
 *   Certificates consumen el resultado final.
 *
 * Responsabilidades de este servicio:
 * - Registrar el hook de sincronización SpeedGrade → Gradebook.
 * - Disparar clms_submission_graded cuando Gradebook salva en lote.
 * - Proveer helpers de fuente y trazabilidad.
 * - Evitar que un override manual del Gradebook borre datos de rúbrica.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Gradebook_Bridge_Service {

	/** Fuentes válidas de calificación. */
	const SOURCES = array( 'manual', 'speedgrade', 'rubric', 'ai', 'system', 'import', 'peer_review', 'quiz' );

	/**
	 * Registra hooks del puente.
	 * Llamar una sola vez desde el loader o init.
	 */
	public static function register_hooks(): void {
		// Cuando SpeedGrade publica una nota, invalida el cache del Gradebook.
		add_action( 'clms_submission_graded', array( __CLASS__, 'on_submission_graded' ), 5, 5 );

		// Cuando el Gradebook salva en lote, dispara el hook académico.
		add_action( 'clms_gradebook_batch_update_complete', array( __CLASS__, 'on_gradebook_batch_complete' ), 10, 3 );

		// Cuando el esquema de ponderaciones cambia, invalida caches de todos los estudiantes.
		add_action( 'clms_gradebook_scheme_updated', array( __CLASS__, 'on_scheme_updated' ), 10, 2 );
	}

	/**
	 * Invalida el caché del Gradebook cuando se graba una submission (SpeedGrade, IA, etc.).
	 *
	 * @param int    $submission_id ID de la entrega.
	 * @param int    $student_id    ID del estudiante.
	 * @param string $status        Estado.
	 * @param mixed  $grade         Nota.
	 * @param string $feedback      Feedback.
	 */
	public static function on_submission_graded( $submission_id, $student_id, $status = '', $grade = '', $feedback = '' ): void {
		$submission_id = absint( $submission_id );
		$student_id    = absint( $student_id );

		if ( ! $submission_id || ! $student_id ) {
			return;
		}

		$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		if ( ! $course_id ) {
			$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			if ( $lesson_id && class_exists( 'CLMS_Helper' ) ) {
				$course_id = absint( CLMS_Helper::get_lesson_course_id( $lesson_id ) );
			}
		}

		if ( ! $course_id ) {
			return;
		}

		// Invalidar caché del Grading Engine para este estudiante.
		if ( class_exists( 'CLMS_Grading_Engine' ) ) {
			$engine = new CLMS_Grading_Engine();
			if ( method_exists( $engine, 'invalidate_grade_cache' ) ) {
				$engine->invalidate_grade_cache( $student_id, $course_id );
			}
		}

		do_action( 'clms_gradebook_invalidated', $student_id, $course_id, $submission_id, 'submission_graded' );
	}

	/**
	 * Cuando el Gradebook batch-update completa, dispara clms_submission_graded para
	 * mantener sincronizadas dashboards, gamificación, notificaciones y analytics.
	 *
	 * @param int   $course_id ID del curso.
	 * @param array $results   Resultados del lote.
	 * @param int   $actor_id  ID del actor.
	 */
	public static function on_gradebook_batch_complete( $course_id, $results, $actor_id ): void {
		$results = is_array( $results ) ? $results : array();

		foreach ( $results as $result ) {
			$result = is_array( $result ) ? $result : array();
			if ( empty( $result['success'] ) || empty( $result['submission_id'] ) ) {
				continue;
			}

			$submission_id = absint( $result['submission_id'] );
			$student_id    = absint( $result['student_id'] ?? 0 );
			$grade         = $result['grade'] ?? '';

			if ( ! $submission_id || ! $student_id ) {
				continue;
			}

			$status = (string) get_post_meta( $submission_id, '_clms_submission_status', true );

			do_action( 'clms_submission_graded', $submission_id, $student_id, $status, $grade, '' );
		}
	}

	/**
	 * Cuando cambia el esquema de ponderaciones, invalida caches de todos los estudiantes.
	 *
	 * @param int   $course_id ID del curso.
	 * @param array $scheme    Nuevo esquema.
	 */
	public static function on_scheme_updated( $course_id, $scheme ): void {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return;
		}

		if ( class_exists( 'CLMS_Helper' ) ) {
			$student_ids = CLMS_Helper::get_enrolled_student_ids( $course_id );
			if ( ! is_array( $student_ids ) ) {
				return;
			}

			if ( class_exists( 'CLMS_Grading_Engine' ) ) {
				$engine = new CLMS_Grading_Engine();
				foreach ( $student_ids as $student_id ) {
					$student_id = absint( $student_id );
					if ( $student_id && method_exists( $engine, 'invalidate_grade_cache' ) ) {
						$engine->invalidate_grade_cache( $student_id, $course_id );
					}
				}
			}
		}

		do_action( 'clms_gradebook_scheme_cache_invalidated', $course_id );
	}

	/**
	 * Normaliza y valida la fuente de una calificación.
	 *
	 * @param string $source Fuente cruda.
	 * @return string Fuente válida (default: 'manual').
	 */
	public static function normalize_source( string $source ): string {
		$source = sanitize_key( $source );
		return in_array( $source, self::SOURCES, true ) ? $source : 'manual';
	}

	/**
	 * Devuelve la etiqueta legible de una fuente.
	 *
	 * @param string $source Fuente.
	 * @return string Etiqueta en español.
	 */
	public static function source_label( string $source ): string {
		$labels = array(
			'manual'       => __( 'Override manual', 'atora-lms' ),
			'speedgrade'   => 'SpeedGrade',
			'rubric'       => __( 'Rúbrica', 'atora-lms' ),
			'ai'           => __( 'IA', 'atora-lms' ),
			'system'       => __( 'Sistema', 'atora-lms' ),
			'import'       => __( 'Importación', 'atora-lms' ),
			'peer_review'  => __( 'Revisión entre pares', 'atora-lms' ),
			'quiz'         => __( 'Quiz automático', 'atora-lms' ),
		);

		$source = sanitize_key( $source );

		return $labels[ $source ] ?? sanitize_text_field( $source );
	}

	/**
	 * Verifica si una actualización manual del Gradebook debería marcar override.
	 * Un override es cuando el docente cambia una nota que ya fue calificada por rúbrica/IA/SpeedGrade.
	 *
	 * @param int $submission_id ID de la entrega.
	 * @return bool True si debe marcarse como override.
	 */
	public static function should_mark_override( int $submission_id ): bool {
		if ( ! $submission_id ) {
			return false;
		}

		$source = (string) get_post_meta( $submission_id, '_clms_submission_grade_source', true );
		if ( '' === $source ) {
			$source = (string) get_post_meta( $submission_id, '_clms_grade_source', true );
		}

		// Si ya tenía nota de rúbrica, IA o SpeedGrade, un cambio manual = override.
		return in_array( $source, array( 'rubric', 'ai', 'ai_assisted', 'ai_auto_grade', 'speedgrade', 'peer_review' ), true );
	}
}
