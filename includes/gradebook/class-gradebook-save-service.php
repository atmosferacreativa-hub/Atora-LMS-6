<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Servicio de guardado por lote del Gradebook.
 * Valida, guarda y registra cambios pasando por CLMS_Assessment_Engine.
 */
class CLMS_Gradebook_Save_Service {

	/**
	 * Guarda un lote de actualizaciones de celdas del gradebook.
	 *
	 * @param int   $course_id ID del curso.
	 * @param array $updates   Array de actualizaciones: [{student_id, lesson_id, submission_id?, grade, feedback?, source?}].
	 * @param int   $actor_id  ID del usuario que realiza la acción.
	 * @return array {results: [{student_id, lesson_id, success: bool, error?: string, grade: int}], saved: int, failed: int}
	 */
	public function batch_update( $course_id, $updates, $actor_id = 0 ) {
		$course_id = absint( $course_id );
		$actor_id  = $actor_id ? absint( $actor_id ) : get_current_user_id();
		$updates   = is_array( $updates ) ? $updates : array();

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return array(
				'results' => array(),
				'saved'   => 0,
				'failed'  => 0,
				'error'   => __( 'Curso no válido.', 'atora-lms' ),
			);
		}

		$assessment = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Assessment_Engine') : null;

		$results = array();
		$saved   = 0;
		$failed  = 0;

		foreach ( $updates as $update ) {
			$update = is_array( $update ) ? $update : array();
			$result = $this->process_update( $course_id, $update, $actor_id, $assessment );

			$results[] = $result;
			if ( $result['success'] ) {
				++$saved;
			} else {
				++$failed;
			}
		}

		if ( $saved > 0 ) {
			do_action( 'clms_gradebook_batch_update_complete', $course_id, $results, $actor_id );
		}

		return array(
			'results' => $results,
			'saved'   => $saved,
			'failed'  => $failed,
		);
	}

	/**
	 * Procesa una actualización individual.
	 *
	 * @param int   $course_id  ID del curso.
	 * @param array $update     Datos de la celda a actualizar.
	 * @param int   $actor_id   ID del usuario que actúa.
	 * @param mixed $assessment Instancia de CLMS_Assessment_Engine o null.
	 * @return array {student_id, lesson_id, submission_id, success: bool, error?: string, grade}
	 */
	protected function process_update( $course_id, $update, $actor_id, $assessment ) {
		$student_id    = absint( $update['student_id'] ?? 0 );
		$lesson_id     = absint( $update['lesson_id'] ?? 0 );
		$submission_id = absint( $update['submission_id'] ?? 0 );
		$grade         = $update['grade'] ?? '';
		$feedback      = isset( $update['feedback'] ) ? wp_kses_post( (string) $update['feedback'] ) : '';
		$source        = 'manual';

		$base = array(
			'student_id'    => $student_id,
			'lesson_id'     => $lesson_id,
			'submission_id' => $submission_id,
			'grade'         => $grade,
		);

		if ( ! $student_id || ! $lesson_id ) {
			return array_merge( $base, array( 'success' => false, 'error' => __( 'student_id y lesson_id son obligatorios.', 'atora-lms' ) ) );
		}

		$max_points = absint( get_post_meta( $lesson_id, '_clms_gradebook_points', true ) );
		if ( ! $max_points ) {
			$max_points = absint( get_post_meta( $lesson_id, '_clms_max_points', true ) );
		}
		if ( ! $max_points ) {
			$max_points = 100;
		}

		if ( '' !== (string) $grade ) {
			$grade_int = absint( $grade );
			if ( $grade_int < 0 || $grade_int > $max_points ) {
				return array_merge( $base, array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %d: max points */
						__( 'La nota debe estar entre 0 y %d.', 'atora-lms' ),
						$max_points
					),
				) );
			}
		}

		if ( ! $submission_id && $assessment && method_exists( $assessment, 'get_student_lesson_submission_id' ) ) {
			$submission_id = absint( $assessment->get_student_lesson_submission_id( $student_id, $lesson_id ) );
		}

		if ( ! $submission_id ) {
			return array_merge( $base, array(
				'success' => false,
				'error'   => __( 'No existe una entrega para esta actividad. Solo se puede calificar entregas registradas.', 'atora-lms' ),
			) );
		}

		if ( 'clms_submission' !== get_post_type( $submission_id ) ) {
			return array_merge( $base, array( 'success' => false, 'error' => __( 'La entrega no es válida.', 'atora-lms' ) ) );
		}

		$sub_course = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		if ( $sub_course && $sub_course !== $course_id ) {
			return array_merge( $base, array( 'success' => false, 'error' => __( 'La entrega no pertenece al curso indicado.', 'atora-lms' ) ) );
		}

		if ( ! $assessment || ! method_exists( $assessment, 'publish_submission_grade' ) ) {
			return array_merge( $base, array( 'success' => false, 'error' => __( 'El motor de evaluación no está disponible.', 'atora-lms' ) ) );
		}

		$publish_data = array(
			'grade'          => '' !== (string) $grade ? max( 0, min( 100, absint( $grade ) ) ) : '',
			'feedback'       => $feedback,
			'source'         => $source,
			'status'         => 'graded',
			'manual_override' => true,
		);

		$result = $assessment->publish_submission_grade( $submission_id, $publish_data );

		if ( is_wp_error( $result ) ) {
			return array_merge( $base, array( 'success' => false, 'error' => $result->get_error_message() ) );
		}

		$this->record_audit_entry( $submission_id, $student_id, $lesson_id, $course_id, $grade, $actor_id );

		$this->recalculate_student_total( $student_id, $course_id );

		return array_merge( $base, array(
			'success'       => true,
			'submission_id' => $submission_id,
			'grade'         => '' !== (string) $grade ? absint( $grade ) : '',
		) );
	}

	/**
	 * Registra entrada de auditoría para la edición de nota.
	 */
	protected function record_audit_entry( $submission_id, $student_id, $lesson_id, $course_id, $grade, $actor_id ) {
		$log = (array) get_post_meta( $submission_id, '_clms_gradebook_audit_log', true );
		$log[] = array(
			'action'     => 'gradebook_batch_update',
			'actor_id'   => $actor_id,
			'student_id' => $student_id,
			'lesson_id'  => $lesson_id,
			'course_id'  => $course_id,
			'grade'      => $grade,
			'timestamp'  => current_time( 'mysql' ),
		);
		$log = array_slice( $log, -50 );
		update_post_meta( $submission_id, '_clms_gradebook_audit_log', $log );
	}

	/**
	 * Dispara recálculo del total del estudiante si hay servicios disponibles.
	 */
	protected function recalculate_student_total( $student_id, $course_id ) {
		if ( class_exists( 'CLMS_Grading_Engine' ) ) {
			$engine = new CLMS_Grading_Engine();
			if ( method_exists( $engine, 'invalidate_grade_cache' ) ) {
				$engine->invalidate_grade_cache( $student_id, $course_id );
			}
		}

		do_action( 'clms_gradebook_student_grade_updated', $student_id, $course_id );
	}
}
