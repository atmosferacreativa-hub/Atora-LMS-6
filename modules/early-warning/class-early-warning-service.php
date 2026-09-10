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
	 * Scan a single course (helper for admin actions / REST).
	 *
	 * @param int  $course_id
	 * @param bool $notify
	 * @return void
	 */
	public function scan_course( int $course_id, bool $notify = true ): void {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return;
		}
		$this->scan_course_missed_submissions( $course_id, $notify );
	}

	/**
	 * Lista entregas perdidas (MVP) para un estudiante en un curso.
	 *
	 * @param int $course_id
	 * @param int $student_id
	 * @return array<int,array{lesson_id:int,deadline_ts:int,lesson_title:string,deadline_date:string}>
	 */
	public function list_missed_submissions_for_student( int $course_id, int $student_id ): array {
		$course_id  = absint( $course_id );
		$student_id = absint( $student_id );
		if ( ! $course_id || ! $student_id || ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$deadline_lessons = $this->get_deadline_lessons( $course_id, time() );
		if ( empty( $deadline_lessons ) ) {
			return array();
		}

		$lesson_ids = array_values( array_filter( array_map(
			static function ( $item ) {
				return absint( $item['lesson_id'] ?? 0 );
			},
			$deadline_lessons
		) ) );

		$submitted = $this->get_existing_submission_pairs( $lesson_ids, array( $student_id ) );

		$missed = array();
		foreach ( $deadline_lessons as $item ) {
			$lesson_id = absint( $item['lesson_id'] ?? 0 );
			if ( ! $lesson_id ) {
				continue;
			}

			if ( ! empty( $submitted[ $student_id ][ $lesson_id ] ) ) {
				continue;
			}

			$deadline_ts  = absint( $item['deadline_ts'] ?? 0 );
			$deadline_day = $deadline_ts ? gmdate( 'Y-m-d', $deadline_ts ) : '';

			$missed[] = array(
				'lesson_id'      => $lesson_id,
				'deadline_ts'    => $deadline_ts,
				'lesson_title'   => (string) get_the_title( $lesson_id ),
				'deadline_date'  => $deadline_day,
			);
		}

		return $missed;
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

	/**
	 * List warnings for a course.
	 *
	 * @param int         $course_id
	 * @param string|null $status 'open'|'closed'|'resolved' or null for any.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_course_warnings( int $course_id, ?string $status = 'open' ): array {
		global $wpdb;
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return array();
		}

		$where = "course_id = %d";
		$args  = array( $course_id );
		if ( null !== $status && '' !== trim( (string) $status ) ) {
			$where .= ' AND status = %s';
			$args[] = sanitize_key( (string) $status );
		}

		$sql = $wpdb->prepare(
			"SELECT user_id, warning_type, data, severity, status, last_notified_at, created_at, updated_at
			 FROM {$this->table()}
			 WHERE {$where}
			 ORDER BY severity DESC, updated_at DESC
			 LIMIT 500",
			$args
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as &$row ) {
			$row['user_id'] = absint( $row['user_id'] ?? 0 );
			$row['course_id'] = $course_id;
			$row['data'] = $row['data'] ? json_decode( (string) $row['data'], true ) : array();

			$u = $row['user_id'] ? get_userdata( $row['user_id'] ) : null;
			$row['student_name']  = $u ? (string) ( $u->display_name ?? '' ) : '';
			$row['student_email'] = $u ? (string) ( $u->user_email ?? '' ) : '';
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Resolve/dismiss a warning (teacher acknowledged).
	 *
	 * @param int    $course_id
	 * @param int    $user_id
	 * @param string $type
	 * @return bool
	 */
	public function resolve_warning( int $course_id, int $user_id, string $type ): bool {
		global $wpdb;
		$course_id = absint( $course_id );
		$user_id   = absint( $user_id );
		$type      = sanitize_key( $type );
		if ( ! $course_id || ! $user_id || '' === $type ) {
			return false;
		}

		$updated = $wpdb->update(
			$this->table(),
			array( 'status' => 'resolved', 'updated_at' => current_time( 'mysql' ) ),
			array( 'course_id' => $course_id, 'user_id' => $user_id, 'warning_type' => $type, 'status' => 'open' )
		);

		return false !== $updated;
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

		$student_ids = (array) \CLMS_Helper::get_enrolled_student_ids( $course_id );
		$student_ids = array_values( array_filter( array_map( 'absint', $student_ids ) ) );
		if ( empty( $student_ids ) ) {
			return;
		}

		$deadline_lessons = $this->get_deadline_lessons( $course_id, time() );

		if ( empty( $deadline_lessons ) ) {
			return;
		}

		$lesson_ids = array_values( array_filter( array_map(
			static function ( $item ) {
				return absint( $item['lesson_id'] ?? 0 );
			},
			$deadline_lessons
		) ) );

		// Una sola consulta trae todas las entregas del curso (todos los
		// estudiantes x todas las lecciones vencidas) en vez de un
		// get_posts() por cada combinación estudiante×lección.
		$submitted = $this->get_existing_submission_pairs( $lesson_ids, $student_ids );

		foreach ( $student_ids as $student_id ) {
			$missed = array();
			foreach ( $deadline_lessons as $item ) {
				$lesson_id = absint( $item['lesson_id'] );

				if ( empty( $submitted[ $student_id ][ $lesson_id ] ) ) {
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

	/**
	 * Trae en una sola consulta qué pares (estudiante, lección) ya tienen
	 * entrega, para las lecciones y estudiantes dados.
	 *
	 * @param int[] $lesson_ids
	 * @param int[] $student_ids
	 * @return array<int,array<int,bool>> [ user_id => [ lesson_id => true ] ]
	 */
	private function get_existing_submission_pairs( array $lesson_ids, array $student_ids ): array {
		global $wpdb;

		$lesson_ids  = array_values( array_unique( array_filter( array_map( 'absint', $lesson_ids ) ) ) );
		$student_ids = array_values( array_unique( array_filter( array_map( 'absint', $student_ids ) ) ) );
		if ( empty( $lesson_ids ) || empty( $student_ids ) ) {
			return array();
		}

		$post_type = class_exists( 'CLMS_Submission' ) ? \CLMS_Submission::CPT : 'clms_submission';

		$lesson_placeholders  = implode( ',', array_fill( 0, count( $lesson_ids ), '%d' ) );
		$student_placeholders = implode( ',', array_fill( 0, count( $student_ids ), '%d' ) );

		$sql = "SELECT pm_lesson.meta_value AS lesson_id, pm_user.meta_value AS user_id
			FROM {$wpdb->postmeta} pm_lesson
			INNER JOIN {$wpdb->postmeta} pm_user ON pm_user.post_id = pm_lesson.post_id AND pm_user.meta_key = '_clms_submission_user_id'
			INNER JOIN {$wpdb->posts} p ON p.ID = pm_lesson.post_id
			WHERE pm_lesson.meta_key = '_clms_submission_lesson_id'
			  AND pm_lesson.meta_value IN ({$lesson_placeholders})
			  AND pm_user.meta_value IN ({$student_placeholders})
			  AND p.post_type = %s
			  AND p.post_status IN ('publish','private')";

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, array_merge( $lesson_ids, $student_ids, array( $post_type ) ) ),
			ARRAY_A
		);

		$pairs = array();
		foreach ( (array) $rows as $row ) {
			$user_id   = absint( $row['user_id'] ?? 0 );
			$lesson_id = absint( $row['lesson_id'] ?? 0 );
			if ( ! $user_id || ! $lesson_id ) {
				continue;
			}
			$pairs[ $user_id ][ $lesson_id ] = true;
		}

		return $pairs;
	}

	/**
	 * Lecciones con deadline en el pasado (due o late date).
	 *
	 * @param int $course_id
	 * @param int $now_ts
	 * @return array<int,array{lesson_id:int,deadline_ts:int}>
	 */
	private function get_deadline_lessons( int $course_id, int $now_ts ): array {
		if ( ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$course_id = absint( $course_id );
		$now_ts    = absint( $now_ts );
		if ( ! $course_id || ! $now_ts ) {
			return array();
		}

		$lesson_ids = (array) \CLMS_Helper::get_course_lessons( $course_id );
		$lesson_ids = array_values( array_filter( array_map( 'absint', $lesson_ids ) ) );
		if ( empty( $lesson_ids ) ) {
			return array();
		}

		$deadline_lessons = array();
		foreach ( $lesson_ids as $lesson_id ) {
			$due_date  = (string) get_post_meta( $lesson_id, '_clms_due_date', true );
			$late_date = (string) get_post_meta( $lesson_id, '_clms_due_date_late', true );
			$due_time  = (string) get_post_meta( $lesson_id, '_clms_due_time', true );

			$date = '' !== trim( $late_date ) ? trim( $late_date ) : trim( $due_date );
			if ( '' === $date ) {
				continue;
			}
			$time = '' !== trim( $due_time ) ? trim( $due_time ) : '23:59';
			try {
				$ts = ( new \DateTimeImmutable( $date . ' ' . $time, wp_timezone() ) )->getTimestamp();
			} catch ( \Exception $e ) {
				continue;
			}
			if ( $ts && $ts < $now_ts ) {
				$deadline_lessons[] = array( 'lesson_id' => absint( $lesson_id ), 'deadline_ts' => absint( $ts ) );
			}
		}

		return $deadline_lessons;
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
