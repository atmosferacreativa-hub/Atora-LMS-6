<?php
/**
 * Notas del estudiante (6.29.0): un solo servicio para la web y la app.
 *
 * Todo pasa por CLMS_Student_Grade_Visibility: una nota guardada pero no
 * liberada no aparece en la lista ni cuenta en el promedio. El promedio del
 * curso es el mismo que muestra el panel web (CLMS_Grading, que ya cuenta solo
 * notas liberadas) y el avance es atora_lms_get_progress(), el mismo de la app
 * y el tema.
 *
 * Los IDs de curso y lección son los del post de WordPress (los del gradebook).
 *
 * @package ATORA_LMS
 * @since 6.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLMS_Student_Grades_Service {

	/**
	 * Notas por actividad de un curso.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function course_activities( int $user_id, int $wp_course_id ): array {
		if ( $user_id <= 0 || $wp_course_id <= 0 || ! class_exists( 'CLMS_Gradebook_Grid_Service' ) ) {
			return array();
		}
		$schema  = class_exists( 'CLMS_Gradebook_Schema_Service' ) ? ( new CLMS_Gradebook_Schema_Service() )->get_schema( $wp_course_id ) : array();
		$weights = array();
		foreach ( (array) ( $schema['weight_groups'] ?? array() ) as $group ) {
			if ( is_array( $group ) && ! empty( $group['key'] ) ) {
				$weights[ sanitize_key( (string) $group['key'] ) ] = array(
					'label'  => sanitize_text_field( (string) ( $group['label'] ?? $group['key'] ) ),
					'weight' => (float) ( $group['weight'] ?? 0 ),
				);
			}
		}
		$columns = ( new CLMS_Gradebook_Grid_Service() )->build_columns( $wp_course_id, $schema );

		$engine    = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Assessment_Engine' ) : null;
		$gradebook = $engine && method_exists( $engine, 'build_course_gradebook' ) ? (array) $engine->build_course_gradebook( $user_id, $wp_course_id ) : array();
		$entries   = array();
		foreach ( (array) ( $gradebook['entries'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) ) {
				$entries[ absint( $entry['lesson_id'] ?? 0 ) ] = $entry;
			}
		}

		$items = array();
		foreach ( $columns as $column ) {
			$lesson_id = absint( $column['lesson_id'] ?? 0 );
			if ( ! $lesson_id ) {
				continue;
			}
			$entry   = $entries[ $lesson_id ] ?? array();
			$status  = sanitize_key( (string) ( $entry['submission_status'] ?? '' ) );
			$sub_id  = absint( $entry['submission_id'] ?? 0 );
			$visible = $sub_id && class_exists( 'CLMS_Student_Grade_Visibility' )
				? CLMS_Student_Grade_Visibility::for_submission_post( $sub_id )
				: array( 'grade' => null, 'feedback' => null, 'status' => $status );

			$grade = null;
			$kind  = 'activity';
			if ( null !== $visible['grade'] && '' !== (string) $visible['grade'] ) {
				$grade = self::percent( $visible['grade'], absint( $column['max_points'] ?? 0 ) );
				$kind  = 'assignment';
			} elseif ( '' !== (string) ( $entry['quiz_grade'] ?? '' ) ) {
				// Los quizzes se califican solos al entregarse: su nota se ve siempre.
				$grade = max( 0.0, min( 100.0, (float) $entry['quiz_grade'] ) );
				$kind  = 'quiz';
			}

			$group = sanitize_key( (string) ( $column['weight_group'] ?? '' ) );
			$items[] = array(
				'wp_lesson_id'  => $lesson_id,
				'title'         => sanitize_text_field( (string) ( $column['title'] ?? '' ) ),
				'kind'          => $kind,
				'activity_type' => sanitize_key( (string) ( $entry['activity_type'] ?? $column['type'] ?? '' ) ),
				'weight_group'  => $group,
				'weight_label'  => $weights[ $group ]['label'] ?? '',
				'weight'        => isset( $weights[ $group ] ) ? $weights[ $group ]['weight'] : null,
				'grade'         => null === $grade ? null : round( $grade, 1 ),
				'status'        => self::status_for( $status, null !== $grade, ! empty( $entry['completed'] ) ),
				'has_feedback'  => null !== $visible['feedback'] && '' !== trim( (string) $visible['feedback'] ),
				'graded_at'     => null !== $grade && $sub_id ? self::iso( (string) get_post_field( 'post_modified_gmt', $sub_id ) ) : null,
			);
		}
		return $items;
	}

	/** Resumen de un curso: nota acumulada (solo notas liberadas), avance y estado. */
	public static function course_summary( int $user_id, int $wp_course_id ): array {
		$grading = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Grading' ) : null;
		$summary = $grading && method_exists( $grading, 'get_course_grade_summary' ) ? (array) $grading->get_course_grade_summary( $user_id, $wp_course_id ) : array();
		// 6.29.5: null = sin notas, 0 = nota cero (antes un quiz en 0 no contaba como nota).
		$final   = isset( $summary['final_average'] ) && is_numeric( $summary['final_average'] ) ? (float) $summary['final_average'] : null;
		$progress = function_exists( 'atora_lms_get_progress' ) ? atora_lms_get_progress( $user_id, $wp_course_id ) : absint( $summary['progress_percent'] ?? 0 );
		$passing  = absint( get_post_meta( $wp_course_id, '_clms_passing_grade', true ) ?: 70 );

		return array(
			'wp_course_id' => $wp_course_id,
			'title'        => sanitize_text_field( (string) get_the_title( $wp_course_id ) ),
			'final_grade'  => null === $final ? null : round( $final, 1 ),
			'passing_grade'=> $passing,
			'progress'     => $progress,
			'status'       => self::academic_status( $final, $progress, $passing ),
		);
	}

	/**
	 * Resumen por programa: promedio de las notas de sus cursos con nota y avance medio.
	 *
	 * @param array<int, array> $course_summaries wp_course_id => course_summary().
	 */
	public static function program_summaries( int $user_id, array $course_summaries ): array {
		if ( ! class_exists( 'CLMS_Helper' ) || ! method_exists( 'CLMS_Helper', 'get_user_enrolled_programs' ) ) {
			return array();
		}
		$items = array();
		foreach ( array_filter( array_map( 'absint', (array) CLMS_Helper::get_user_enrolled_programs( $user_id ) ) ) as $program_id ) {
			$course_ids = method_exists( 'CLMS_Helper', 'get_program_courses' ) ? array_filter( array_map( 'absint', (array) CLMS_Helper::get_program_courses( $program_id ) ) ) : array();
			$grades     = array();
			$progress   = array();
			foreach ( $course_ids as $wp_course_id ) {
				$summary    = $course_summaries[ $wp_course_id ] ?? self::course_summary( $user_id, $wp_course_id );
				$progress[] = (int) $summary['progress'];
				if ( null !== $summary['final_grade'] ) {
					$grades[] = (float) $summary['final_grade'];
				}
			}
			$items[] = array(
				'wp_program_id' => $program_id,
				'title'         => sanitize_text_field( (string) get_the_title( $program_id ) ),
				'courses'       => count( $course_ids ),
				'final_grade'   => $grades ? round( array_sum( $grades ) / count( $grades ), 1 ) : null,
				'progress'      => $progress ? (int) round( array_sum( $progress ) / count( $progress ) ) : 0,
			);
		}
		return $items;
	}

	private static function percent( $raw, int $max_points ): float {
		if ( class_exists( 'CLMS_Gradebook_Normalizer' ) ) {
			$scores = CLMS_Gradebook_Normalizer::cell_scores( $raw, $max_points );
			if ( isset( $scores['percent_score'] ) && $scores['percent_score'] >= 0 ) {
				return (float) $scores['percent_score'];
			}
		}
		return max( 0.0, min( 100.0, (float) $raw ) );
	}

	/** Estado que ve el estudiante: nunca dice "calificada" si la nota no está liberada. */
	private static function status_for( string $submission_status, bool $has_visible_grade, bool $completed ): string {
		if ( $has_visible_grade ) {
			return 'graded';
		}
		if ( in_array( $submission_status, array( 'needs_revision', 'returned' ), true ) ) {
			return 'needs_revision';
		}
		if ( in_array( $submission_status, array( 'submitted', 'in_review', 'graded' ), true ) ) {
			return 'in_review';
		}
		return $completed ? 'completed' : 'pending';
	}

	private static function academic_status( ?float $final, int $progress, int $passing ): string {
		if ( $progress >= 100 && null !== $final ) {
			return $final >= $passing ? 'approved' : 'not_approved';
		}
		if ( null !== $final && $final < $passing ) {
			return 'at_risk';
		}
		return $progress > 0 ? 'in_progress' : 'not_started';
	}

	private static function iso( string $gmt ): ?string {
		return '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ? str_replace( ' ', 'T', $gmt ) . 'Z' : null;
	}
}
