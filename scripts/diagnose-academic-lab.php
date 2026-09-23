<?php
/**
 * Read-only B.1/B.3/B.5 evidence. Run: wp eval-file scripts/diagnose-academic-lab.php 425 6726
 * No enrollment repairs, metadata writes, or publication changes are performed.
 */
if ( PHP_SAPI !== 'cli' || ! defined( 'ABSPATH' ) ) {
	exit( "Run this file through WP-CLI eval-file.\n" );
}

global $wpdb;
$report = array(
	'plugin_version' => defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : null,
	'build' => file_exists( dirname( __DIR__ ) . '/build-info.json' ) ? json_decode( file_get_contents( dirname( __DIR__ ) . '/build-info.json' ), true ) : null,
	'read_source' => get_option( 'atora_lms_read_source', 'legacy' ),
	'dualwrite' => (bool) get_option( 'atora_lms_dualwrite', false ),
	'counts' => array(),
	'submissions' => array(),
);
foreach ( array( 'lm_course', 'lm_program' ) as $type ) {
	$counts = (array) wp_count_posts( $type );
	$report['counts'][ $type ] = array(
		'by_status' => $counts,
		'admin_dashboard_publish_private' => (int) ( $counts['publish'] ?? 0 ) + (int) ( $counts['private'] ?? 0 ),
		'listed_publish_private_ids' => get_posts( array( 'post_type' => $type, 'post_status' => array( 'publish', 'private' ), 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ) ),
	);
}
foreach ( array_filter( array_map( 'absint', $args ?? array() ) ) as $id ) {
	if ( 'clms_submission' !== get_post_type( $id ) ) {
		$report['submissions'][ $id ] = array( 'error' => 'not_a_submission' );
		continue;
	}
	$course = absint( get_post_meta( $id, '_clms_submission_course_id', true ) );
	$student = absint( get_post_meta( $id, '_clms_submission_user_id', true ) )
		?: absint( get_post_meta( $id, '_clms_submission_student_id', true ) );
	if ( ! $student && '1' === (string) get_post_meta( $id, '_clms_submission_group_master', true ) ) {
		$student = absint( get_post_meta( $id, '_clms_submission_submitted_by', true ) );
	}
	$student = $student ?: absint( get_post_field( 'post_author', $id ) );
	$report['submissions'][ $id ] = array(
		'wp_course_id_meta' => $course,
		'course_post_type' => get_post_type( $course ),
		'lesson_id' => absint( get_post_meta( $id, '_clms_submission_lesson_id', true ) ),
		'student_id' => $student,
		'student_exists' => (bool) get_user_by( 'id', $student ),
		'status' => get_post_meta( $id, '_clms_submission_status', true ),
		'grade' => get_post_meta( $id, '_clms_submission_grade', true ),
		'legacy_roster_contains_student' => in_array( $student, array_map( 'absint', (array) get_post_meta( $course, '_clms_enrolled_users', true ) ), true ),
		'table_enrollments' => $wpdb->get_results( $wpdb->prepare(
			"SELECT e.course_id AS table_course_id, c.wp_post_id, e.wp_course_id, e.status
			 FROM {$wpdb->prefix}atora_enrollments e
			 INNER JOIN {$wpdb->prefix}atora_courses c ON c.id = e.course_id
			 WHERE c.wp_post_id = %d AND e.user_id = %d", $course, $student
		), ARRAY_A ),
	);
}
echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
