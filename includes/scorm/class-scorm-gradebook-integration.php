<?php
/**
 * SCORM: inyección de calificación hacia el gradebook existente.
 *
 * @package ATORA_LMS
 * @since 6.35.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-scorm-attempt-service.php';

final class CLMS_SCORM_Gradebook_Integration {
	public static function init(): void {
		add_filter( 'clms_gradebook_cell', array( __CLASS__, 'filter_gradebook_cell' ), 10, 6 );
	}

	/**
	 * @param array $cell
	 * @param int   $student_id
	 * @param int   $course_id
	 * @param int   $lesson_id
	 * @param array $entry
	 * @param array $args
	 * @return array
	 */
	public static function filter_gradebook_cell( $cell, $student_id, $course_id, $lesson_id, $entry, $args ) {
		unset( $course_id, $entry, $args );
		$cell = is_array( $cell ) ? $cell : array();
		$student_id = absint( $student_id );
		$lesson_id  = absint( $lesson_id );
		if ( ! $student_id || ! $lesson_id ) {
			return $cell;
		}

		$resources = get_post_meta( $lesson_id, '_clms_lesson_resources', true );
		$resources = is_array( $resources ) ? $resources : array();
		$has_scorm = false;
		foreach ( $resources as $r ) {
			if ( is_array( $r ) && 'scorm' === ( $r['type'] ?? '' ) ) {
				$has_scorm = true;
				break;
			}
		}
		if ( ! $has_scorm ) {
			return $cell;
		}

		$rule = sanitize_key( (string) get_post_meta( $lesson_id, '_clms_scorm_attempt_grading', true ) );
		if ( '' === $rule ) {
			$rule = sanitize_key( (string) get_option( 'atora_scorm_attempt_grading', 'best' ) );
		}
		if ( ! in_array( $rule, array( 'best', 'last', 'first' ), true ) ) {
			$rule = 'best';
		}

		$best = self::get_best_attempt_row( $student_id, $lesson_id, $rule );
		if ( is_wp_error( $best ) || empty( $best ) ) {
			return $cell;
		}

		$grade = ( null !== ( $best['score_percent'] ?? null ) ) ? (float) $best['score_percent'] : null;
		$status = sanitize_key( (string) ( $best['grade_status'] ?? 'in_review' ) );
		$visible = class_exists( 'CLMS_Student_Grade_Visibility' )
			? CLMS_Student_Grade_Visibility::grade_visible( $status, null !== $grade ? (string) $grade : '' )
			: ( 'graded' === $status && null !== $grade );

		if ( ! $visible || null === $grade ) {
			return $cell;
		}

		$grade = max( 0.0, min( 100.0, $grade ) );
		$cell['raw_score']     = $grade;
		$cell['max_points']    = 100;
		$cell['percent_score'] = $grade;
		$cell['grade']         = absint( round( $grade ) );
		$cell['status']        = 'graded';
		$cell['source']        = 'scorm';
		$cell['submission_id'] = 0;
		$cell['missing']       = false;
		$cell['needs_review']  = false;
		$cell['speedgrade_url']= '';
		return $cell;
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private static function get_best_attempt_row( int $student_id, int $lesson_id, string $rule ) {
		global $wpdb;
		$table = CLMS_SCORM_Attempt_Service::table_attempts();
		$order_sql = 'attempt_number DESC';
		if ( 'first' === $rule ) {
			$order_sql = 'attempt_number ASC';
		} elseif ( 'best' === $rule ) {
			$order_sql = 'score_percent DESC, attempt_number DESC';
		}

		$row = (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND lesson_id = %d ORDER BY {$order_sql} LIMIT 1",
				$student_id,
				$lesson_id
			),
			ARRAY_A
		);
		return $row;
	}
}

