<?php
/**
 * Early warning detection + notifications.
 *
 * @package ATORA_LMS
 * @since   6.13.3
 */

namespace ATORA\EarlyWarning;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Early_Warning_Service {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_early_warning';
	}

	/**
	 * Daily scan across published courses (MVP: minimal).
	 *
	 * @return void
	 */
	public function scan_and_notify(): void {
		$courses = get_posts( array(
			'post_type'      => 'lm_course',
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		foreach ( (array) $courses as $course_id ) {
			$course_id = absint( $course_id );
			if ( ! $course_id ) {
				continue;
			}
			$this->scan_course_missed_submissions( $course_id, true );
		}
	}

	public function list_course_warnings( int $course_id ): array {
		global $wpdb;
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return array();
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id, warning_type, data, severity, status, created_at, updated_at
			 FROM {$this->table()}
			 WHERE course_id = %d AND status = 'open'
			 ORDER BY severity DESC, updated_at DESC
			 LIMIT 500",
			$course_id
		), ARRAY_A );

		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as &$row ) {
			$row['user_id'] = absint( $row['user_id'] ?? 0 );
			$row['course_id'] = $course_id;
			$row['data'] = $row['data'] ? json_decode( (string) $row['data'], true ) : array();
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Detect missed submissions for a course and optionally push notifications to teachers.
	 *
	 * Preset (MVP):
	 * - if any lesson has due_date (or late date) in the past and student has no submission → warning open.
	 *
	 * @param int  $course_id
	 * @param bool $notify
	 * @return void
	 */
	private function scan_course_missed_submissions( int $course_id, bool $notify ): void {
		global $wpdb;

		if ( ! class_exists( 'CLMS_Helper' ) ) {
			return;
		}

		$lesson_ids = (array) \CLMS_Helper::get_course_lessons( $course_id );
		$lesson_ids = array_values( array_filter( array_map( 'absint', $lesson_ids ) ) );
		if ( empty( $lesson_ids ) ) {
			return;
		}

		$student_ids = (array) \CLMS_Helper::get_enrolled_student_ids( $course_id );
		$student_ids = array_values( array_filter( array_map( 'absint', $student_ids ) ) );
		if ( empty( $student_ids ) ) {
			return;
		}

		$now = time();

		$deadline_lessons = array();
		foreach ( $lesson_ids as $lesson_id ) {
			$due_date = (string) get_post_meta( $lesson_id, '_clms_due_date', true );
			$late_date = (string) get_post_meta( $lesson_id, '_clms_due_date_late', true );
			$due_time = (string) get_post_meta( $lesson_id, '_clms_due_time', true );

			$date = '' !== trim( $late_date ) ? trim( $late_date ) : trim( $due_date );
			if ( '' === $date ) {
				continue;
			}
			$time = '' !== trim( $due_time ) ? trim( $due_time ) : '23:59';
			$ts   = strtotime( $date . ' ' . $time );
			if ( $ts && $ts < $now ) {
				$deadline_lessons[] = array( 'lesson_id' => $lesson_id, 'deadline_ts' => (int) $ts );
			}
		}

		if ( empty( $deadline_lessons ) ) {
			return;
		}

		// For MVP, we do a simple per-student scan with get_posts() per (student, lesson) capped by early exits.
		foreach ( $student_ids as $student_id ) {
			$missed = array();
			foreach ( $deadline_lessons as $item ) {
				$lesson_id = absint( $item['lesson_id'] );
				$has = get_posts( array(
					'post_type'      => class_exists( 'CLMS_Submission' ) ? \CLMS_Submission::CPT : 'clms_submission',
					'post_status'    => array( 'publish', 'private' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array(
						array( 'key' => '_clms_submission_user_id', 'value' => $student_id, 'type' => 'NUMERIC' ),
						array( 'key' => '_clms_submission_lesson_id', 'value' => $lesson_id, 'type' => 'NUMERIC' ),
					),
				) );

				if ( empty( $has ) ) {
					$missed[] = array(
						'lesson_id'    => $lesson_id,
						'deadline_ts'  => (int) ( $item['deadline_ts'] ?? 0 ),
					);
				}
			}

			if ( empty( $missed ) ) {
				$this->close_warning( $course_id, $student_id, 'missed_submission' );
				continue;
			}

			$severity = min( 100, 50 + ( 10 * count( $missed ) ) );
			$this->upsert_warning(
				$course_id,
				$student_id,
				'missed_submission',
				array( 'missed' => $missed, 'count' => count( $missed ) ),
				$severity
			);

			if ( $notify ) {
				$this->notify_teacher( $course_id, $student_id, count( $missed ) );
			}
		}
	}

	private function upsert_warning( int $course_id, int $user_id, string $type, array $data, int $severity ): void {
		global $wpdb;

		$course_id = absint( $course_id );
		$user_id   = absint( $user_id );
		$type      = sanitize_key( $type );
		$severity  = max( 0, min( 100, absint( $severity ) ) );

		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$this->table()} WHERE course_id = %d AND user_id = %d AND warning_type = %s LIMIT 1",
			$course_id,
			$user_id,
			$type
		) );

		$payload = array(
			'course_id'    => $course_id,
			'user_id'      => $user_id,
			'warning_type' => $type,
			'data'         => wp_json_encode( $data ),
			'severity'     => $severity,
			'status'       => 'open',
			'updated_at'   => current_time( 'mysql' ),
		);

		if ( $existing_id ) {
			$wpdb->update( $this->table(), $payload, array( 'id' => absint( $existing_id ) ) );
		} else {
			$payload['created_at'] = current_time( 'mysql' );
			$wpdb->insert( $this->table(), $payload );
		}
	}

	private function close_warning( int $course_id, int $user_id, string $type ): void {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array( 'status' => 'closed', 'updated_at' => current_time( 'mysql' ) ),
			array( 'course_id' => absint( $course_id ), 'user_id' => absint( $user_id ), 'warning_type' => sanitize_key( $type ) )
		);
	}

	private function notify_teacher( int $course_id, int $student_id, int $missed_count ): void {
		$course_id = absint( $course_id );
		$student_id = absint( $student_id );
		if ( ! $course_id || ! $student_id ) {
			return;
		}

		// Anti-spam: 1 notification per student/course per day.
		global $wpdb;
		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$this->table()}
			 WHERE course_id = %d AND user_id = %d AND warning_type = %s AND status = 'open'
			 LIMIT 1",
			$course_id,
			$student_id,
			'missed_submission'
		) );
		if ( ! $existing_id ) {
			return;
		}

		$last_notified = $wpdb->get_var( $wpdb->prepare(
			"SELECT last_notified_at FROM {$this->table()} WHERE id = %d",
			absint( $existing_id )
		) );
		if ( $last_notified ) {
			$ts = strtotime( (string) $last_notified );
			if ( $ts && ( time() - $ts ) < DAY_IN_SECONDS ) {
				return;
			}
		}

		$teacher_ids = array();
		$raw = get_post_meta( $course_id, '_clms_course_teacher_ids', true );
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\s*,\s*/', trim( $raw ) );
		}
		if ( is_array( $raw ) ) {
			foreach ( $raw as $tid ) {
				$tid = absint( $tid );
				if ( $tid ) {
					$teacher_ids[] = $tid;
				}
			}
		}
		$author = absint( get_post_field( 'post_author', $course_id ) );
		if ( $author && ! in_array( $author, $teacher_ids, true ) ) {
			$teacher_ids[] = $author;
		}

		$student = get_userdata( $student_id );
		$student_name = $student ? (string) ( $student->display_name ?? '' ) : __( 'Estudiante', 'atora-lms' );

		$notifications = class_exists( 'CLMS_Helper' ) ? clms_core( 'CLMS_Notifications' ) : null;
		if ( ! $notifications || ! method_exists( $notifications, 'add_notification' ) ) {
			return;
		}

		foreach ( array_values( array_unique( $teacher_ids ) ) as $teacher_id ) {
			$teacher_id = absint( $teacher_id );
			if ( ! $teacher_id ) {
				continue;
			}

			$notifications->add_notification(
				$teacher_id,
				array(
					'type'      => 'early_warning',
					'title'     => __( 'Alerta temprana: entregas perdidas', 'atora-lms' ),
					'message'   => sprintf(
						/* translators: 1: student name, 2: count */
						__( '%1$s tiene %2$d entregas vencidas sin enviar.', 'atora-lms' ),
						$student_name,
						$missed_count
					),
					'link'      => admin_url( 'admin.php?page=atora-early-warning&course_id=' . $course_id ),
					'course_id' => $course_id,
					'user_id'   => $student_id,
				)
			);
		}

		$wpdb->update( $this->table(), array( 'last_notified_at' => current_time( 'mysql' ) ), array( 'id' => absint( $existing_id ) ) );
	}
}
