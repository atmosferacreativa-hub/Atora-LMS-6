<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Gradebook_Grid_Service {

	/**
	 * Construye columnas del gradebook según lecciones del curso.
	 *
	 * @param int   $course_id ID del curso.
	 * @param array $schema    Esquema de gradebook.
	 * @param array $args      Filtros activos.
	 * @return array
	 */
	public function build_columns( $course_id, $schema, $args = array() ) {
		$course_id = absint( $course_id );
		$schema    = is_array( $schema ) ? $schema : array();
		$args      = is_array( $args ) ? $args : array();

		$lesson_ids = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_lessons( $course_id ) : array();
		$lesson_ids = is_array( $lesson_ids ) ? array_values( array_filter( array_map( 'absint', $lesson_ids ) ) ) : array();

		$activity_search = sanitize_text_field( (string) ( $args['activity_search'] ?? '' ) );
		$columns         = array();

		foreach ( $lesson_ids as $lesson_id ) {
			$title = get_the_title( $lesson_id );
			if ( '' !== $activity_search && false === stripos( (string) $title, $activity_search ) ) {
				continue;
			}

			$lesson_group = sanitize_key( (string) get_post_meta( $lesson_id, '_clms_gradebook_group', true ) );
			$column = array(
				'lesson_id'    => $lesson_id,
				'title'        => $title ? $title : sprintf( __( 'Lección %d', 'atora-lms' ), $lesson_id ),
				'type'         => sanitize_key( (string) get_post_meta( $lesson_id, '_clms_activity_type', true ) ),
				'max_points'   => absint( get_post_meta( $lesson_id, '_clms_gradebook_points', true ) ),
				'weight_group' => $lesson_group ? $lesson_group : sanitize_key( (string) ( $schema['default_group'] ?? 'general' ) ),
				'rubric_id'    => absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) ),
				'required'     => (bool) get_post_meta( $lesson_id, '_clms_gradebook_required', true ),
			);
			if ( ! $column['max_points'] ) {
				$column['max_points'] = absint( get_post_meta( $lesson_id, '_clms_max_points', true ) );
			}

			$columns[] = apply_filters( 'clms_gradebook_column', $column, $course_id, $lesson_id, $args, $schema );
		}

		return apply_filters( 'clms_gradebook_columns', $columns, $course_id, $args, $schema );
	}

	/**
	 * Construye filas del gradebook para estudiantes inscritos.
	 *
	 * @param int                                $course_id ID del curso.
	 * @param array                              $columns Columnas calculadas.
	 * @param CLMS_Gradebook_Calculation_Service $calculation_service Servicio de cálculo.
	 * @param array                              $args Argumentos de filtrado.
	 * @return array
	 */
	public function build_rows( $course_id, $columns, $schema, $calculation_service, $args = array() ) {
		$course_id = absint( $course_id );
		$columns   = is_array( $columns ) ? $columns : array();
		$schema    = is_array( $schema ) ? $schema : array();
		$args      = is_array( $args ) ? $args : array();

		$student_ids    = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_enrolled_student_ids( $course_id ) : array();
		$student_ids    = is_array( $student_ids ) ? array_values( array_filter( array_map( 'absint', $student_ids ) ) ) : array();

		$section_id = absint( $args['section_id'] ?? 0 );
		if ( $section_id ) {
			$student_ids = $this->filter_students_by_section( $student_ids, $section_id );
		} else {
			$cohort_id = absint( $args['cohort_id'] ?? 0 );
			if ( $cohort_id ) {
				$student_ids = $this->filter_students_by_cohort( $student_ids, $cohort_id );
			}
		}

		$student_search = sanitize_text_field( (string) ( $args['student_search'] ?? '' ) );
		$status_filter  = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$rows           = array();

		foreach ( $student_ids as $student_id ) {
			$user = get_user_by( 'id', $student_id );
			if ( ! $user ) {
				continue;
			}

			$display_name = (string) ( $user->display_name ? $user->display_name : $user->user_login );
			$email        = (string) $user->user_email;
			if ( '' !== $student_search && false === stripos( $display_name . ' ' . $email, $student_search ) ) {
				continue;
			}

			$assessment = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Assessment_Engine') : null;
			$gradebook  = ( $assessment && method_exists( $assessment, 'build_course_gradebook' ) )
				? (array) $assessment->build_course_gradebook( $student_id, $course_id )
				: array();
			$entries    = isset( $gradebook['entries'] ) && is_array( $gradebook['entries'] ) ? $gradebook['entries'] : array();
			$cells      = array();
			$has_status = false;

			foreach ( $columns as $column ) {
				$lesson_id = absint( $column['lesson_id'] ?? 0 );
				if ( ! $lesson_id ) {
					continue;
				}

				$entry = $this->find_entry_by_lesson( $entries, $lesson_id );
				$cell  = $this->build_cell( $entry, $lesson_id );

				if ( '' === $status_filter || $status_filter === (string) $cell['status'] ) {
					$has_status = true;
				}

				$cell             = apply_filters( 'clms_gradebook_cell', $cell, $student_id, $course_id, $lesson_id, $entry, $args );
				$cells[ $lesson_id ] = $cell;
			}

			if ( '' !== $status_filter && ! $has_status ) {
				continue;
			}

			$summary = ( $calculation_service && method_exists( $calculation_service, 'build_student_summary' ) )
				? $calculation_service->build_student_summary( $student_id, $course_id )
				: array( 'total' => 0, 'progress' => 0, 'risk_level' => 'low' );

			$rows[] = array(
				'student_id'    => $student_id,
				'student_name'  => $display_name,
				'student_email' => $email,
				'cells'         => $cells,
				'total'         => ( $calculation_service && method_exists( $calculation_service, 'calculate_weighted_total' ) )
					? $calculation_service->calculate_weighted_total( $cells, $columns, $schema, absint( $summary['total'] ?? 0 ) )
					: absint( $summary['total'] ?? 0 ),
				'progress'      => absint( $summary['progress'] ?? 0 ),
				'risk_level'    => sanitize_key( (string) ( $summary['risk_level'] ?? 'low' ) ),
			);
		}

		usort(
			$rows,
			static function ( $left, $right ) {
				return strcasecmp( (string) ( $left['student_name'] ?? '' ), (string) ( $right['student_name'] ?? '' ) );
			}
		);

		return apply_filters( 'clms_gradebook_rows', $rows, $course_id, $args, $columns );
	}

	/**
	 * Filtra student_ids por sección.
	 *
	 * @param array $student_ids IDs de estudiantes del curso.
	 * @param int   $section_id  ID de la sección.
	 * @return array
	 */
	protected function filter_students_by_section( $student_ids, $section_id ) {
		$section_id = absint( $section_id );
		if ( ! $section_id ) {
			return $student_ids;
		}

		if ( ! class_exists( 'ATORA\\LMS\\Section_Service' ) ) {
			return $student_ids;
		}

		$section_sids = \ATORA\LMS\Section_Service::get_section_student_ids( $section_id );

		if ( empty( $section_sids ) ) {
			return array();
		}

		return array_values( array_intersect( $student_ids, $section_sids ) );
	}

	/**
	 * Filtra student_ids para incluir solo los que pertenecen a la cohorte.
	 *
	 * @param array $student_ids IDs de estudiantes del curso.
	 * @param int   $cohort_id   ID de la cohorte.
	 * @return array
	 */
	protected function filter_students_by_cohort( $student_ids, $cohort_id ) {
		$cohort_id = absint( $cohort_id );
		if ( ! $cohort_id ) {
			return $student_ids;
		}

		if ( ! class_exists( 'CLMS_Cohort_Service' ) ) {
			return $student_ids;
		}

		$service     = new CLMS_Cohort_Service();
		$cohort_sids = is_array( $service->get_cohort_student_ids( $cohort_id ) )
			? array_values( array_filter( array_map( 'absint', $service->get_cohort_student_ids( $cohort_id ) ) ) )
			: array();

		if ( empty( $cohort_sids ) ) {
			return array();
		}

		return array_values( array_intersect( $student_ids, $cohort_sids ) );
	}

	/**
	 * Localiza entrada de evaluación por lección.
	 *
	 * @param array $entries Entradas de evaluación.
	 * @param int   $lesson_id ID de lección.
	 * @return array
	 */
	protected function find_entry_by_lesson( $entries, $lesson_id ) {
		$entries   = is_array( $entries ) ? $entries : array();
		$lesson_id = absint( $lesson_id );

		foreach ( $entries as $entry ) {
			$entry = is_array( $entry ) ? $entry : array();
			if ( $lesson_id === absint( $entry['lesson_id'] ?? 0 ) ) {
				return $entry;
			}
		}

		return array();
	}

	/**
	 * Construye celda por estudiante y actividad, incluyendo datos de rúbrica y normalización de nota.
	 *
	 * @param array $entry     Entrada de evaluación.
	 * @param int   $lesson_id ID de lección.
	 * @return array
	 */
	protected function build_cell( $entry, $lesson_id ) {
		$entry         = is_array( $entry ) ? $entry : array();
		$status        = sanitize_key( (string) ( $entry['submission_status'] ?? 'missing' ) );
		$raw_grade     = '' !== (string) ( $entry['assignment_grade'] ?? '' ) ? $entry['assignment_grade'] : '';
		$submission_id = absint( $entry['submission_id'] ?? 0 );
		$source        = sanitize_key( (string) ( $entry['grade_source'] ?? '' ) );

		// Obtener max_points de la actividad para normalizar.
		$max_points = absint( get_post_meta( absint( $lesson_id ), '_clms_gradebook_points', true ) );
		if ( ! $max_points ) {
			$max_points = absint( get_post_meta( absint( $lesson_id ), '_clms_max_points', true ) );
		}

		// Normalizar nota a porcentaje 0–100.
		$scores = class_exists( 'CLMS_Gradebook_Normalizer' )
			? CLMS_Gradebook_Normalizer::cell_scores( $raw_grade, $max_points )
			: array( 'raw_score' => $raw_grade, 'max_points' => $max_points, 'percent_score' => '' !== (string) $raw_grade ? (float) $raw_grade : -1.0 );

		// Retrocompatibilidad: 'grade' = percent_score para UI legacy.
		$grade = $scores['percent_score'] >= 0 ? absint( round( $scores['percent_score'] ) ) : '';

		$submission_id = absint( $entry['submission_id'] ?? 0 );
		$source        = sanitize_key( (string) ( $entry['grade_source'] ?? '' ) );

		$rubric_id = absint( get_post_meta( absint( $lesson_id ), '_clms_rubric_id', true ) );
		$rubric_data = $this->build_rubric_cell_data( $rubric_id, $submission_id, $grade, $source );

		$cell = array(
			'lesson_id'              => absint( $lesson_id ),
			// Scores normalizados (Sprint 3).
			'raw_score'              => $scores['raw_score'],
			'max_points'             => $scores['max_points'],
			'percent_score'          => $scores['percent_score'] >= 0 ? $scores['percent_score'] : '',
			// grade = percent_score redondeado, por retrocompatibilidad con UI y legacy.
			'grade'                  => $grade,
			'status'                 => $status ? $status : 'missing',
			'submission_id'          => $submission_id,
			'source'                 => $source,
			'rubric_score'           => $rubric_data['rubric_score'],
			'rubric_id'              => $rubric_id,
			'rubric_completed'       => $rubric_data['rubric_completed'],
			'rubric_criteria_count'  => $rubric_data['rubric_criteria_count'],
			'rubric_criteria_scored' => $rubric_data['rubric_criteria_scored'],
			'manual_override'        => ! empty( $entry['manual_override'] ),
			'late'                   => false,
			'missing'                => ( ! $submission_id || in_array( $status, array( '', 'missing' ), true ) ),
			'needs_review'           => in_array( $status, array( 'needs_revision', 'returned' ), true ),
			'speedgrade_url'         => '',
		);

		$grading = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading') : null;
		if ( $submission_id && $grading && method_exists( $grading, 'get_speedgrade_url' ) ) {
			$cell['speedgrade_url'] = (string) $grading->get_speedgrade_url( $submission_id, admin_url( 'admin.php?page=clms-gradebook' ) );
		}

		$cell = apply_filters( 'clms_gradebook_cell_indicators', $cell, $lesson_id, $submission_id, $entry );

		return $cell;
	}

	/**
	 * Construye los campos de rúbrica para una celda.
	 *
	 * @param int    $rubric_id     ID de la rúbrica de la lección.
	 * @param int    $submission_id ID de la entrega.
	 * @param mixed  $grade         Nota calculada.
	 * @param string $source        Fuente de la nota.
	 * @return array
	 */
	protected function build_rubric_cell_data( $rubric_id, $submission_id, $grade, $source ) {
		$defaults = array(
			'rubric_score'           => '',
			'rubric_completed'       => false,
			'rubric_criteria_count'  => 0,
			'rubric_criteria_scored' => 0,
		);

		if ( ! $rubric_id || ! $submission_id ) {
			return $defaults;
		}

		$criteria_count = 0;
		if ( class_exists( 'CLMS_Rubric' ) && method_exists( 'CLMS_Rubric', 'get_criteria' ) ) {
			$criteria       = (array) CLMS_Rubric::get_criteria( $rubric_id );
			$criteria_count = count( $criteria );
		}

		$rubric_scores = get_post_meta( $submission_id, '_clms_submission_rubric_scores', true );
		$rubric_scores = is_array( $rubric_scores ) ? $rubric_scores : array();

		$scored = 0;
		foreach ( $rubric_scores as $score ) {
			if ( isset( $score['points'] ) && '' !== (string) $score['points'] ) {
				++$scored;
			} elseif ( '' !== (string) $score ) {
				++$scored;
			}
		}

		$rubric_score = ( 'rubric' === $source && '' !== (string) $grade ) ? $grade : '';

		return array(
			'rubric_score'           => $rubric_score,
			'rubric_completed'       => $criteria_count > 0 && $scored >= $criteria_count,
			'rubric_criteria_count'  => $criteria_count,
			'rubric_criteria_scored' => $scored,
		);
	}
}
