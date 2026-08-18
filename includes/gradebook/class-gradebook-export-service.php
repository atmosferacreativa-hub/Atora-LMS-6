<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exportación CSV del Gradebook.
 * Genera CSV liviano con todos los datos del grid. Respeta filtros activos.
 */
class CLMS_Gradebook_Export_Service {

	/**
	 * Genera y envía al navegador el CSV del gradebook.
	 * Termina la ejecución después de enviar.
	 *
	 * @param array $grid    Grid construido por CLMS_Gradebook_Service.
	 * @param array $context Información adicional: course_id, cohort_name, etc.
	 * @return void
	 */
	public function stream_csv( $grid, $context = array() ) {
		$grid    = is_array( $grid ) ? $grid : array();
		$context = is_array( $context ) ? $context : array();

		$columns   = is_array( $grid['columns'] ?? null ) ? $grid['columns'] : array();
		$rows      = is_array( $grid['rows'] ?? null ) ? $grid['rows'] : array();
		$course_id = absint( $context['course_id'] ?? ( $grid['course_id'] ?? 0 ) );

		$course_slug = $course_id ? sanitize_title( get_the_title( $course_id ) ) : 'gradebook';
		$filename    = 'gradebook-' . $course_slug . '-' . gmdate( 'Ymd-His' ) . '.csv';

		$eligibility_service = class_exists( 'CLMS_Gradebook_Certificate_Eligibility_Service' )
			? new CLMS_Gradebook_Certificate_Eligibility_Service()
			: null;
		$schema = is_array( $grid['schema'] ?? null ) ? $grid['schema'] : array();

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Expires: 0' );
		echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — BOM UTF-8

		$out = fopen( 'php://output', 'w' );

		$header = array(
			'student_id',
			'student_name',
			'student_email',
			'total',
			'progress',
			'risk_level',
			'certificate_eligible',
		);

		foreach ( $columns as $col ) {
			$title    = sanitize_text_field( (string) ( $col['title'] ?? 'actividad' ) );
			$max_pts  = absint( $col['max_points'] ?? 0 );
			$header[] = $title . ( $max_pts ? ' (pts/' . $max_pts . ')' : ' (%)' );
			$header[] = $title . ' %';
		}

		$header = apply_filters( 'clms_gradebook_export_header', $header, $grid, $context );
		fputcsv( $out, $header );

		foreach ( $rows as $row ) {
			$cells = is_array( $row['cells'] ?? null ) ? $row['cells'] : array();

			$cert_status = '';
			if ( $eligibility_service ) {
				$elig        = $eligibility_service->check_row_eligibility( $row, $columns, $schema, $course_id );
				$cert_status = sanitize_key( (string) ( $elig['status'] ?? '' ) );
			}

			$line = array(
				absint( $row['student_id'] ?? 0 ),
				sanitize_text_field( (string) ( $row['student_name'] ?? '' ) ),
				sanitize_email( (string) ( $row['student_email'] ?? '' ) ),
				absint( $row['total'] ?? 0 ),
				absint( $row['progress'] ?? 0 ),
				sanitize_key( (string) ( $row['risk_level'] ?? '' ) ),
				$cert_status,
			);

			foreach ( $columns as $col ) {
				$lesson_id   = absint( $col['lesson_id'] ?? 0 );
				$cell        = isset( $cells[ $lesson_id ] ) && is_array( $cells[ $lesson_id ] ) ? $cells[ $lesson_id ] : array();
				$raw_score   = '' !== (string) ( $cell['raw_score'] ?? '' ) ? $cell['raw_score'] : ( '' !== (string) ( $cell['grade'] ?? '' ) ? $cell['grade'] : '' );
				$pct_score   = '' !== (string) ( $cell['percent_score'] ?? '' ) ? round( (float) $cell['percent_score'], 1 ) : ( '' !== (string) ( $cell['grade'] ?? '' ) ? $cell['grade'] : '' );
				$line[]      = $raw_score;
				$line[]      = $pct_score;
			}

			$line = apply_filters( 'clms_gradebook_export_row', $line, $row, $columns, $context );
			fputcsv( $out, $line );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Construye el CSV como string (para descargas vía REST o pruebas).
	 *
	 * @param array $grid    Grid del gradebook.
	 * @param array $context Información adicional.
	 * @return string CSV como string.
	 */
	public function build_csv_string( $grid, $context = array() ) {
		$grid    = is_array( $grid ) ? $grid : array();
		$context = is_array( $context ) ? $context : array();

		$columns   = is_array( $grid['columns'] ?? null ) ? $grid['columns'] : array();
		$rows      = is_array( $grid['rows'] ?? null ) ? $grid['rows'] : array();
		$course_id = absint( $context['course_id'] ?? ( $grid['course_id'] ?? 0 ) );
		$schema    = is_array( $grid['schema'] ?? null ) ? $grid['schema'] : array();

		$eligibility_service = class_exists( 'CLMS_Gradebook_Certificate_Eligibility_Service' )
			? new CLMS_Gradebook_Certificate_Eligibility_Service()
			: null;

		$buffer = fopen( 'php://memory', 'w' );

		$header = array( 'student_id', 'student_name', 'student_email', 'total', 'progress', 'risk_level', 'certificate_eligible' );
		foreach ( $columns as $col ) {
			$title    = sanitize_text_field( (string) ( $col['title'] ?? 'actividad' ) );
			$max_pts  = absint( $col['max_points'] ?? 0 );
			$header[] = $title . ( $max_pts ? ' (pts/' . $max_pts . ')' : ' (%)' );
			$header[] = $title . ' %';
		}
		fputcsv( $buffer, $header );

		foreach ( $rows as $row ) {
			$cells       = is_array( $row['cells'] ?? null ) ? $row['cells'] : array();
			$cert_status = '';
			if ( $eligibility_service ) {
				$elig        = $eligibility_service->check_row_eligibility( $row, $columns, $schema, $course_id );
				$cert_status = sanitize_key( (string) ( $elig['status'] ?? '' ) );
			}

			$line = array(
				absint( $row['student_id'] ?? 0 ),
				sanitize_text_field( (string) ( $row['student_name'] ?? '' ) ),
				sanitize_email( (string) ( $row['student_email'] ?? '' ) ),
				absint( $row['total'] ?? 0 ),
				absint( $row['progress'] ?? 0 ),
				sanitize_key( (string) ( $row['risk_level'] ?? '' ) ),
				$cert_status,
			);

			foreach ( $columns as $col ) {
				$lesson_id = absint( $col['lesson_id'] ?? 0 );
				$cell      = isset( $cells[ $lesson_id ] ) && is_array( $cells[ $lesson_id ] ) ? $cells[ $lesson_id ] : array();
				$raw_score = '' !== (string) ( $cell['raw_score'] ?? '' ) ? $cell['raw_score'] : ( '' !== (string) ( $cell['grade'] ?? '' ) ? $cell['grade'] : '' );
				$pct_score = '' !== (string) ( $cell['percent_score'] ?? '' ) ? round( (float) $cell['percent_score'], 1 ) : ( '' !== (string) ( $cell['grade'] ?? '' ) ? $cell['grade'] : '' );
				$line[]    = $raw_score;
				$line[]    = $pct_score;
			}

			fputcsv( $buffer, $line );
		}

		rewind( $buffer );
		$csv = stream_get_contents( $buffer );
		fclose( $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return (string) $csv;
	}
}
