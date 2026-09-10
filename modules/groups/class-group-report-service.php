<?php
/**
 * Group reports / exports.
 *
 * @package ATORA_LMS
 * @since   6.13.3
 */

namespace ATORA\Groups;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Group_Report_Service {

	public function export_course_csv( int $course_id ): string {
		global $wpdb;
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return '';
		}

		$groups_table     = $wpdb->prefix . 'clms_groups';
		$members_table    = $wpdb->prefix . 'clms_group_members';
		$submissions_table = $wpdb->prefix . 'clms_group_submissions';
		$overrides_table  = $wpdb->prefix . 'clms_group_grade_overrides';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				g.id AS group_id,
				g.name AS group_name,
				g.course_id,
				m.user_id AS student_id,
				s.lesson_id,
				s.submission_id AS group_submission_id,
				o.override_grade
			FROM {$groups_table} g
			LEFT JOIN {$members_table} m ON m.group_id = g.id
			LEFT JOIN {$submissions_table} s ON s.group_id = g.id
			LEFT JOIN {$overrides_table} o ON o.group_id = g.id AND o.lesson_id = s.lesson_id AND o.student_id = m.user_id
			WHERE g.course_id = %d
			ORDER BY g.id ASC, m.user_id ASC, s.lesson_id ASC",
			$course_id
		), ARRAY_A );

		$header = array(
			'course_id',
			'group_id',
			'group_name',
			'lesson_id',
			'group_submission_id',
			'student_id',
			'student_name',
			'group_grade_0_100',
			'override_grade_0_100',
			'final_grade_0_100',
		);

		$out = fopen( 'php://temp', 'r+' );
		fputcsv( $out, $header );

		foreach ( (array) $rows as $row ) {
			$student_id = absint( $row['student_id'] ?? 0 );
			$submission_id = absint( $row['group_submission_id'] ?? 0 );
			$group_grade = $submission_id ? get_post_meta( $submission_id, '_clms_submission_grade', true ) : '';
			$override    = $row['override_grade'] ?? '';

			$final = $group_grade;
			if ( '' !== (string) $override && is_numeric( $override ) ) {
				$final = max( 0, min( 100, (int) round( (float) $override ) ) );
			}

			$student_name = '';
			if ( $student_id ) {
				$u = get_userdata( $student_id );
				$student_name = $u ? (string) ( $u->display_name ?? '' ) : '';
			}

			fputcsv( $out, array(
				$course_id,
				absint( $row['group_id'] ?? 0 ),
				(string) ( $row['group_name'] ?? '' ),
				absint( $row['lesson_id'] ?? 0 ),
				$submission_id,
				$student_id,
				$student_name,
				'' !== (string) $group_grade ? (int) $group_grade : '',
				'' !== (string) $override ? (int) $override : '',
				'' !== (string) $final ? (int) $final : '',
			) );
		}

		rewind( $out );
		return (string) stream_get_contents( $out );
	}
}
