<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Gradebook_Calculation_Service {

	/**
	 * Construye el resumen de un estudiante sin sustituir el motor actual.
	 *
	 * @param int $student_id ID del estudiante.
	 * @param int $course_id  ID del curso.
	 * @return array
	 */
	public function build_student_summary( $student_id, $course_id ) {
		$student_id = absint( $student_id );
		$course_id  = absint( $course_id );

		$summary = array(
			'total'      => 0,
			'progress'   => 0,
			'risk_level' => 'low',
		);

		$grading = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading') : null;
		if ( $grading && method_exists( $grading, 'get_course_grade_summary' ) ) {
			$course_summary = (array) $grading->get_course_grade_summary( $student_id, $course_id );
			$summary['total']    = absint( $course_summary['final_average'] ?? 0 );
			$summary['progress'] = absint( $course_summary['progress_percent'] ?? 0 );
		}

		if ( $summary['total'] < 60 ) {
			$summary['risk_level'] = 'high';
		} elseif ( $summary['total'] < 80 ) {
			$summary['risk_level'] = 'medium';
		}

		return $summary;
	}

	/**
	 * Calcula total ponderado usando percent_score normalizado.
	 * Delega en CLMS_Gradebook_Normalizer si está disponible (Sprint 3).
	 *
	 * @param array $cells    Celdas del estudiante.
	 * @param array $columns  Columnas del gradebook.
	 * @param array $schema   Esquema del curso.
	 * @param int   $fallback Total legado como fallback.
	 * @return int Nota final 0–100.
	 */
	public function calculate_weighted_total( $cells, $columns, $schema, $fallback = 0 ) {
		$cells    = is_array( $cells ) ? $cells : array();
		$columns  = is_array( $columns ) ? $columns : array();
		$schema   = is_array( $schema ) ? $schema : array();
		$fallback = absint( $fallback );

		// Usar normalizador si está disponible.
		if ( class_exists( 'CLMS_Gradebook_Normalizer' ) ) {
			$result = CLMS_Gradebook_Normalizer::calculate_weighted_total( $cells, $columns, $schema );
			$score  = $result['final_score'] ?? -1.0;
			if ( $score >= 0 ) {
				return max( 0, min( 100, absint( round( $score ) ) ) );
			}
			return $fallback;
		}

		// Fallback legacy sin normalizador.
		$groups = isset( $schema['weight_groups'] ) && is_array( $schema['weight_groups'] ) ? $schema['weight_groups'] : array();
		if ( empty( $groups ) || empty( $columns ) ) {
			return $fallback;
		}

		$per_group = array();
		foreach ( $columns as $column ) {
			$column    = is_array( $column ) ? $column : array();
			$lesson_id = absint( $column['lesson_id'] ?? 0 );
			$group_key = sanitize_key( (string) ( $column['weight_group'] ?? '' ) );
			if ( ! $lesson_id || '' === $group_key || ! isset( $groups[ $group_key ] ) ) {
				continue;
			}

			$cell  = isset( $cells[ $lesson_id ] ) && is_array( $cells[ $lesson_id ] ) ? $cells[ $lesson_id ] : array();
			$grade = ( '' !== (string) ( $cell['grade'] ?? '' ) ) ? (float) $cell['grade'] : null;
			if ( null === $grade ) {
				continue;
			}

			if ( ! isset( $per_group[ $group_key ] ) ) {
				$per_group[ $group_key ] = array( 'total' => 0.0, 'count' => 0 );
			}
			$per_group[ $group_key ]['total'] += $grade;
			++$per_group[ $group_key ]['count'];
		}

		if ( empty( $per_group ) ) {
			return $fallback;
		}

		$weighted_total = 0.0;
		$weights_used   = 0.0;
		foreach ( $per_group as $group_key => $group_stats ) {
			$weight = (float) ( $groups[ $group_key ]['weight'] ?? 0 );
			if ( $weight <= 0 || empty( $group_stats['count'] ) ) {
				continue;
			}

			$average        = $group_stats['total'] / max( 1, $group_stats['count'] );
			$weighted_total += $average * ( $weight / 100 );
			$weights_used   += $weight;
		}

		if ( $weights_used <= 0 ) {
			return $fallback;
		}

		if ( abs( 100 - $weights_used ) > 0.01 ) {
			$weighted_total = $weighted_total * ( 100 / $weights_used );
		}

		return max( 0, min( 100, absint( round( $weighted_total ) ) ) );
	}
}
