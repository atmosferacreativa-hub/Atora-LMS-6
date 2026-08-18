<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determina si un estudiante es elegible para certificado basándose en
 * el gradebook. Aplica reglas configuradas y mantiene compatibilidad
 * con el sistema de certificados existente. No emite certificados.
 */
class CLMS_Gradebook_Certificate_Eligibility_Service {

	const STATUS_ELIGIBLE          = 'eligible';
	const STATUS_NOT_ELIGIBLE      = 'not_eligible';
	const STATUS_PENDING_REVIEW    = 'pending_review';
	const STATUS_NEEDS_APPROVAL    = 'needs_approval';

	/**
	 * Evalúa elegibilidad de un estudiante para el certificado de un curso.
	 *
	 * @param array $row       Fila del gradebook del estudiante.
	 * @param array $columns   Columnas del gradebook.
	 * @param array $schema    Esquema del curso.
	 * @param int   $course_id ID del curso.
	 * @return array {status, reasons, override}
	 */
	public function check_row_eligibility( $row, $columns, $schema, $course_id ) {
		$row       = is_array( $row ) ? $row : array();
		$columns   = is_array( $columns ) ? $columns : array();
		$schema    = is_array( $schema ) ? $schema : array();
		$course_id = absint( $course_id );

		$student_id = absint( $row['student_id'] ?? 0 );
		$total      = absint( $row['total'] ?? 0 );
		$progress   = absint( $row['progress'] ?? 0 );
		$cells      = is_array( $row['cells'] ?? null ) ? $row['cells'] : array();
		$reasons    = array();
		$fails      = array();

		$passing_grade  = $this->get_passing_grade( $course_id );
		$min_progress   = $this->get_min_progress( $course_id );

		if ( $total < $passing_grade ) {
			$fails[] = sprintf(
				/* translators: 1: total, 2: required */
				__( 'Nota %1$d%% no alcanza el mínimo requerido de %2$d%%.', 'atora-lms' ),
				$total,
				$passing_grade
			);
		} else {
			$reasons[] = sprintf(
				/* translators: 1: total */
				__( 'Nota %1$d%% supera el mínimo.', 'atora-lms' ),
				$total
			);
		}

		if ( $progress < $min_progress ) {
			$fails[] = sprintf(
				/* translators: 1: progress, 2: required */
				__( 'Progreso %1$d%% no alcanza el mínimo requerido de %2$d%%.', 'atora-lms' ),
				$progress,
				$min_progress
			);
		} else {
			$reasons[] = sprintf(
				/* translators: 1: progress */
				__( 'Progreso %1$d%% cumple el requisito.', 'atora-lms' ),
				$progress
			);
		}

		foreach ( $columns as $column ) {
			$column    = is_array( $column ) ? $column : array();
			$lesson_id = absint( $column['lesson_id'] ?? 0 );
			$required  = ! empty( $column['required'] );
			if ( ! $lesson_id || ! $required ) {
				continue;
			}

			$cell   = isset( $cells[ $lesson_id ] ) && is_array( $cells[ $lesson_id ] ) ? $cells[ $lesson_id ] : array();
			$status = sanitize_key( (string) ( $cell['status'] ?? '' ) );
			$grade  = '' !== (string) ( $cell['grade'] ?? '' ) ? absint( $cell['grade'] ) : null;

			if ( empty( $status ) || in_array( $status, array( 'missing', '' ), true ) || null === $grade ) {
				$fails[] = sprintf(
					/* translators: %s: activity title */
					__( 'Actividad obligatoria "%s" no fue entregada o calificada.', 'atora-lms' ),
					esc_html( (string) ( $column['title'] ?? 'Actividad' ) )
				);
			}
		}

		$pending_rubrics = 0;
		foreach ( $cells as $cell ) {
			$rubric_id = absint( $cell['rubric_id'] ?? 0 );
			if ( $rubric_id && empty( $cell['rubric_completed'] ) && '' !== (string) ( $cell['grade'] ?? '' ) ) {
				++$pending_rubrics;
			}
		}

		if ( $pending_rubrics > 0 ) {
			$reasons[] = sprintf(
				/* translators: %d: count */
				_n( '%d rúbrica incompleta.', '%d rúbricas incompletas.', $pending_rubrics, 'atora-lms' ),
				$pending_rubrics
			);
		}

		$context = array(
			'student_id'     => $student_id,
			'course_id'      => $course_id,
			'total'          => $total,
			'progress'       => $progress,
			'passing_grade'  => $passing_grade,
			'min_progress'   => $min_progress,
			'pending_rubrics' => $pending_rubrics,
			'fails'          => $fails,
			'reasons'        => $reasons,
		);

		$override = (string) get_user_meta( $student_id, '_clms_cert_override_' . $course_id, true );

		if ( 'force_eligible' === $override ) {
			$result = array(
				'status'   => self::STATUS_ELIGIBLE,
				'reasons'  => array_merge( $reasons, array( __( 'Elegibilidad confirmada manualmente por el docente.', 'atora-lms' ) ) ),
				'fails'    => $fails,
				'override' => $override,
			);
		} elseif ( 'force_not_eligible' === $override ) {
			$result = array(
				'status'  => self::STATUS_NOT_ELIGIBLE,
				'reasons' => array_merge( $reasons, array( __( 'No elegible confirmado manualmente por el docente.', 'atora-lms' ) ) ),
				'fails'   => $fails,
				'override' => $override,
			);
		} elseif ( ! empty( $fails ) ) {
			$result = array(
				'status'  => self::STATUS_NOT_ELIGIBLE,
				'reasons' => $reasons,
				'fails'   => $fails,
				'override' => $override,
			);
		} elseif ( $pending_rubrics > 0 ) {
			$result = array(
				'status'  => self::STATUS_PENDING_REVIEW,
				'reasons' => $reasons,
				'fails'   => array(),
				'override' => $override,
			);
		} else {
			$result = array(
				'status'  => self::STATUS_ELIGIBLE,
				'reasons' => $reasons,
				'fails'   => array(),
				'override' => $override,
			);
		}

		$result = apply_filters( 'clms_certificate_eligibility_result', $result, $student_id, $course_id, $context );

		return $result;
	}

	/**
	 * Nota mínima requerida para el curso.
	 */
	protected function get_passing_grade( $course_id ) {
		$course_grade = absint( get_post_meta( $course_id, '_clms_cert_passing_grade', true ) );
		if ( $course_grade >= 1 && $course_grade <= 100 ) {
			return $course_grade;
		}
		$global = absint( get_option( 'clms_certificate_passing_grade', 70 ) );
		return ( $global >= 1 && $global <= 100 ) ? $global : 70;
	}

	/**
	 * Progreso mínimo requerido para el curso.
	 */
	protected function get_min_progress( $course_id ) {
		$course_prog = absint( get_post_meta( $course_id, '_clms_cert_min_progress', true ) );
		if ( $course_prog >= 1 && $course_prog <= 100 ) {
			return $course_prog;
		}
		$global = absint( get_option( 'clms_certificate_min_progress', 100 ) );
		return ( $global >= 1 && $global <= 100 ) ? $global : 100;
	}
}
