<?php
/**
 * Read-only Step 5 helper: locate a graded submission inside Gradebook grid.
 *
 * Run (example):
 *   wp eval-file scripts/step5-gradebook-cell-evidence.php 6726 1
 *
 * Args:
 *   [0] submission_id (clms_submission)
 *   [1] optional: user_id to impersonate for capability checks (default 1)
 */

if ( PHP_SAPI !== 'cli' || ! defined( 'ABSPATH' ) ) {
	exit( "Run this file through WP-CLI eval-file.\n" );
}

$submission_id = absint( ( $args[0] ?? 0 ) );
$as_user_id    = absint( ( $args[1] ?? 1 ) );

if ( $as_user_id > 0 && function_exists( 'wp_set_current_user' ) ) {
	wp_set_current_user( $as_user_id );
}

$report = array(
	'plugin_version' => defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : null,
	'submission_id'  => $submission_id,
	'as_user_id'     => $as_user_id,
	'error'          => null,
	'submission'     => array(),
	'gradebook'      => array(
		'available'      => class_exists( 'CLMS_Gradebook_Service' ),
		'matched_cells'  => array(),
		'match_count'    => 0,
		'rows'           => 0,
		'columns'        => 0,
		'first_columns'  => array(),
	),
);

if ( $submission_id <= 0 || 'clms_submission' !== get_post_type( $submission_id ) ) {
	$report['error'] = 'not_a_submission';
	echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
	return;
}

$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) )
	?: absint( get_post_meta( $submission_id, '_clms_submission_student_id', true ) );
$student_id = $student_id ?: absint( get_post_field( 'post_author', $submission_id ) );

$report['submission'] = array(
	'post_type'   => get_post_type( $submission_id ),
	'course_id'   => $course_id,
	'lesson_id'   => $lesson_id,
	'student_id'  => $student_id,
	'status'      => (string) get_post_meta( $submission_id, '_clms_submission_status', true ),
	'grade'       => get_post_meta( $submission_id, '_clms_submission_grade', true ),
);

if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
	$report['error'] = 'invalid_course_meta';
	echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
	return;
}

if ( ! class_exists( 'CLMS_Gradebook_Service' ) ) {
	$report['error'] = 'gradebook_service_unavailable';
	echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
	return;
}

$service = new CLMS_Gradebook_Service();
if ( ! method_exists( $service, 'build_grid' ) ) {
	$report['error'] = 'gradebook_service_missing_build_grid';
	echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
	return;
}

$grid = (array) $service->build_grid( $course_id, array() );
$rows = is_array( $grid['rows'] ?? null ) ? $grid['rows'] : array();
$cols = is_array( $grid['columns'] ?? null ) ? $grid['columns'] : array();

$report['gradebook']['rows']    = count( $rows );
$report['gradebook']['columns'] = count( $cols );
$report['gradebook']['first_columns'] = array_slice( array_map( static function ( $c ) {
	$c = is_array( $c ) ? $c : array();
	return array(
		'id'    => $c['id'] ?? null,
		'type'  => $c['type'] ?? null,
		'title' => $c['title'] ?? null,
	);
}, $cols ), 0, 5 );

$matches = array();
foreach ( $rows as $row ) {
	$row = is_array( $row ) ? $row : array();
	$row_student = absint( $row['student_id'] ?? ( $row['student']['id'] ?? ( $row['user_id'] ?? 0 ) ) );
	$cells = is_array( $row['cells'] ?? null ) ? $row['cells'] : array();
	foreach ( $cells as $cell ) {
		$cell = is_array( $cell ) ? $cell : array();
		$cell_submission_id = absint( $cell['submission_id'] ?? ( $cell['submissionId'] ?? 0 ) );
		if ( $cell_submission_id !== $submission_id ) {
			continue;
		}
		$cell_lesson_id = absint( $cell['lesson_id'] ?? 0 );
		$matches[] = array(
			'row_student_id' => $row_student,
			'cell_lesson_id' => $cell_lesson_id,
			'same_student'   => $row_student === $student_id,
			'same_lesson'    => $cell_lesson_id === $lesson_id,
			'cell_grade'     => $cell['grade'] ?? null,
			'cell_status'    => $cell['status'] ?? null,
			'cell_keys'      => array_values( array_keys( $cell ) ),
		);
		if ( count( $matches ) >= 10 ) {
			break 2;
		}
	}
}

$report['gradebook']['matched_cells'] = $matches;
$report['gradebook']['match_count']   = count( $matches );

echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";

