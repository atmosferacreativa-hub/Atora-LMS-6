<?php
/**
 * LMS_Enrollment_Service — Matrículas y progreso en tablas propias (Fase 11)
 *
 * Coexiste con la lógica de CLMS_Enrollment (wp_usermeta).
 * Los nuevos registros van a atora_enrollments y atora_lesson_progress.
 *
 * @package ATORA_LMS\LMS
 * @since   5.29.0
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMS_Enrollment_Service {

	// ── Matrículas ───────────────────────────────────────────────────────────

	/**
	 * Crea o activa una matrícula en atora_enrollments.
	 * Idempotente: si ya existe la reactiva en lugar de duplicar.
	 */
	public static function enroll( int $user_id, int $course_id, int $order_id = 0 ): int {
		global $wpdb;

		$table   = $wpdb->prefix . 'atora_enrollments';
		$now     = current_time( 'mysql', true );

		$wp_row     = $wpdb->get_var(
			$wpdb->prepare( "SELECT wp_post_id FROM {$wpdb->prefix}atora_courses WHERE id = %d LIMIT 1", $course_id )
		);
		$wp_post_id = absint( $wp_row );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$table} WHERE user_id = %d AND course_id = %d LIMIT 1",
				$user_id, $course_id
			),
			ARRAY_A
		);

		if ( $existing ) {
			if ( 'active' !== $existing['status'] ) {
				$wpdb->update( $table, array( 'status' => 'active', 'enrolled_at' => $now ), array( 'id' => (int) $existing['id'] ), array( '%s', '%s' ), array( '%d' ) );
			}
			return (int) $existing['id'];
		}

		$ok = $wpdb->insert(
			$table,
			array(
				'user_id'       => $user_id,
				'course_id'     => $course_id,
				'wp_course_id'  => $wp_post_id,
				'status'        => 'active',
				'progress_pct'  => 0,
				'order_id'      => $order_id,
				'enrolled_at'   => $now,
				'last_activity' => $now,
			),
			array( '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s' )
		);

		if ( ! $ok ) { return 0; }
		$enroll_id = (int) $wpdb->insert_id;

		do_action( 'atora/lms/enrolled', $user_id, $course_id, $enroll_id );
		return $enroll_id;
	}

	public static function get_enrollment( int $user_id, int $course_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}atora_enrollments
				 WHERE user_id = %d AND course_id = %d AND status = 'active' LIMIT 1",
				$user_id, $course_id
			),
			ARRAY_A
		);
		return $row ? self::format_enrollment( $row ) : null;
	}

	public static function get_user_enrollments( int $user_id, string $status = 'active' ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*, c.title AS course_title, c.thumbnail_url, c.type AS course_type
				 FROM {$wpdb->prefix}atora_enrollments e
				 LEFT JOIN {$wpdb->prefix}atora_courses c ON c.id = e.course_id
				 WHERE e.user_id = %d AND e.status = %s
				 ORDER BY e.enrolled_at DESC",
				$user_id, sanitize_key( $status )
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format_enrollment' ), $rows );
	}

	public static function get_course_stats( int $course_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_enrollments';
		$row   = (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(status='active') AS active,
					SUM(status='completed') AS completed,
					AVG(progress_pct) AS avg_progress,
					AVG(grade) AS avg_grade
				 FROM {$table} WHERE course_id = %d",
				$course_id
			),
			ARRAY_A
		);
		return array(
			'total'        => absint( $row['total'] ?? 0 ),
			'active'       => absint( $row['active'] ?? 0 ),
			'completed'    => absint( $row['completed'] ?? 0 ),
			'avg_progress' => round( (float) ( $row['avg_progress'] ?? 0 ), 1 ),
			'avg_grade'    => round( (float) ( $row['avg_grade'] ?? 0 ), 2 ),
		);
	}

	// ── Progreso de lecciones ────────────────────────────────────────────────

	public static function complete_lesson( int $user_id, int $lesson_id ): bool {
		global $wpdb;

		$lesson = LMS_Course_Service::get_lesson( $lesson_id );
		if ( ! $lesson ) { return false; }

		$course_id  = absint( $lesson['course_id'] );
		$now        = current_time( 'mysql', true );
		$prog_table = $wpdb->prefix . 'atora_lesson_progress';

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$prog_table} WHERE user_id = %d AND lesson_id = %d LIMIT 1",
				$user_id, $lesson_id
			)
		);

		if ( $existing ) {
			$current_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$prog_table} WHERE id = %d", $existing ) );
			if ( 'completed' === $current_status ) {
				return true;
			}
			$wpdb->update(
				$prog_table,
				array( 'status' => 'completed', 'completed_at' => $now, 'last_viewed_at' => $now ),
				array( 'id' => (int) $existing ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$prog_table,
				array(
					'user_id'        => $user_id,
					'lesson_id'      => $lesson_id,
					'course_id'      => $course_id,
					'wp_lesson_id'   => absint( $lesson['wp_post_id'] ),
					'status'         => 'completed',
					'completed_at'   => $now,
					'last_viewed_at' => $now,
				),
				array( '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
			);
		}

		self::recalculate_progress( $user_id, $course_id );

		do_action( 'atora/lms/lesson_completed', $user_id, $lesson_id, $course_id );
		return true;
	}

	public static function get_progress( int $user_id, int $course_id ): array {
		global $wpdb;

		$total_lessons = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}atora_lessons
				 WHERE course_id = %d AND status = 'published' AND is_required = 1",
				$course_id
			)
		);

		$completed_lessons = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}atora_lesson_progress lp
				 INNER JOIN {$wpdb->prefix}atora_lessons l ON l.id = lp.lesson_id
				 WHERE lp.user_id = %d AND lp.course_id = %d
				   AND lp.status = 'completed' AND l.is_required = 1",
				$user_id, $course_id
			)
		);

		$pct = $total_lessons > 0 ? min( 100, (int) round( $completed_lessons / $total_lessons * 100 ) ) : 0;

		return array(
			'user_id'           => $user_id,
			'course_id'         => $course_id,
			'total_lessons'     => $total_lessons,
			'completed_lessons' => $completed_lessons,
			'progress_pct'      => $pct,
			'is_complete'       => $pct >= 100,
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	private static function recalculate_progress( int $user_id, int $course_id ): void {
		global $wpdb;

		$progress = self::get_progress( $user_id, $course_id );
		$pct      = $progress['progress_pct'];

		$wpdb->update(
			$wpdb->prefix . 'atora_enrollments',
			array( 'progress_pct' => $pct, 'last_activity' => current_time( 'mysql', true ) ),
			array( 'user_id' => $user_id, 'course_id' => $course_id ),
			array( '%d', '%s' ),
			array( '%d', '%d' )
		);

		if ( $pct >= 100 ) {
			$wpdb->update(
				$wpdb->prefix . 'atora_enrollments',
				array( 'status' => 'completed', 'completed_at' => current_time( 'mysql', true ) ),
				array( 'user_id' => $user_id, 'course_id' => $course_id, 'status' => 'active' ),
				array( '%s', '%s' ),
				array( '%d', '%d', '%s' )
			);
			do_action( 'atora/lms/course_completed', $user_id, $course_id );
		}
	}

	// ── F3.1 — Lectores de tabla (fuente sombra para shadow-read) ────────────

	/** Academic roster by WordPress course ID; never compare it with a table ID. */
	public static function get_student_ids_by_wp_course_id( int $wp_course_id ): array {
		global $wpdb;
		if ( $wp_course_id <= 0 ) { return array(); }
		$ids = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT e.user_id
			 FROM {$wpdb->prefix}atora_enrollments e
			 INNER JOIN {$wpdb->prefix}atora_courses c ON c.id = e.course_id
			 WHERE c.wp_post_id = %d AND e.status IN ('active', 'completed')
			 ORDER BY e.user_id",
			$wp_course_id
		) );
		// Expired access does not erase academic records. This is not an access check.
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * IDs de WP post de cursos con matrícula activa en tabla.
	 * Equivalente tabular de CLMS_Helper::get_user_enrolled_courses().
	 *
	 * @param int $user_id WP user ID.
	 * @return array<int,int>
	 */
	public static function get_enrolled_wp_course_ids( int $user_id ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT wp_course_id, expires_at
				 FROM {$wpdb->prefix}atora_enrollments
				 WHERE user_id = %d AND status IN ('active', 'completed') AND wp_course_id > 0",
				$user_id
			),
			ARRAY_A
		);
		$now = current_time( 'mysql', true );
		$ids = array();
		foreach ( $rows as $row ) {
			if ( $row['expires_at'] && $row['expires_at'] < $now ) { continue; }
			$ids[] = absint( $row['wp_course_id'] );
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Verifica matrícula activa en tabla por wp_post_id del curso.
	 * Equivalente tabular de CLMS_Helper::user_is_enrolled_in_course().
	 *
	 * @param int $user_id       WP user ID.
	 * @param int $wp_course_id  WP post ID del curso.
	 * @return bool
	 */
	public static function is_enrolled_by_wp_id( int $user_id, int $wp_course_id ): bool {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT expires_at FROM {$wpdb->prefix}atora_enrollments
				 WHERE user_id = %d AND wp_course_id = %d AND status IN ('active', 'completed') LIMIT 1",
				$user_id, $wp_course_id
			),
			ARRAY_A
		);
		if ( ! $row ) { return false; }
		if ( $row['expires_at'] ) {
			$now = current_time( 'mysql', true );
			if ( $row['expires_at'] < $now ) { return false; }
		}
		return true;
	}

	/**
	 * Caducidad de acceso desde tabla, en el mismo formato que el usermeta legacy.
	 * Equivalente tabular de CLMS_Helper::get_user_course_access_expiration().
	 *
	 * @param int $user_id       WP user ID.
	 * @param int $wp_course_id  WP post ID del curso.
	 * @return string  Datetime en el mismo formato que la meta legacy; vacío = perpetuo.
	 */
	public static function get_access_expiry_by_wp_id( int $user_id, int $wp_course_id ): string {
		global $wpdb;
		$expires_at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT expires_at FROM {$wpdb->prefix}atora_enrollments
				 WHERE user_id = %d AND wp_course_id = %d AND status IN ('active', 'completed') LIMIT 1",
				$user_id, $wp_course_id
			)
		);
		return $expires_at ? (string) $expires_at : '';
	}

	/**
	 * Completación de curso desde tabla (status = 'completed' en atora_enrollments).
	 * Equivalente tabular de CLMS_Helper::is_course_completed().
	 *
	 * @param int $user_id       WP user ID.
	 * @param int $wp_course_id  WP post ID del curso.
	 * @return bool
	 */
	public static function is_course_completed_by_wp_id( int $user_id, int $wp_course_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}atora_enrollments
				 WHERE user_id = %d AND wp_course_id = %d AND status = 'completed' LIMIT 1",
				$user_id, $wp_course_id
			)
		);
	}

	/**
	 * IDs de WP post de programas con matrícula activa en tabla.
	 * Equivalente tabular de CLMS_Helper::get_user_enrolled_programs().
	 *
	 * @param int $user_id WP user ID.
	 * @return array<int,int>
	 */
	public static function get_enrolled_wp_program_ids( int $user_id ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.wp_post_id, pe.expires_at
				 FROM {$wpdb->prefix}atora_program_enrollments pe
				 INNER JOIN {$wpdb->prefix}atora_programs p ON p.id = pe.program_id
				 WHERE pe.user_id = %d AND pe.status IN ('active', 'completed')",
				$user_id
			),
			ARRAY_A
		);
		$now = current_time( 'mysql', true );
		$ids = array();
		foreach ( $rows as $row ) {
			if ( $row['expires_at'] && $row['expires_at'] < $now ) { continue; }
			$ids[] = absint( $row['wp_post_id'] );
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	private static function format_enrollment( array $row ): array {
		return array(
			'id'            => absint( $row['id'] ),
			'user_id'       => absint( $row['user_id'] ),
			'course_id'     => absint( $row['course_id'] ),
			'wp_course_id'  => absint( $row['wp_course_id'] ?? 0 ),
			'status'        => sanitize_key( (string) ( $row['status']      ?? 'active' ) ),
			'progress_pct'  => absint( $row['progress_pct'] ?? 0 ),
			'grade'         => isset( $row['grade'] ) && $row['grade'] !== null ? (float) $row['grade'] : null,
			'course_title'  => sanitize_text_field( (string) ( $row['course_title']  ?? '' ) ),
			'enrolled_at'   => sanitize_text_field( (string) ( $row['enrolled_at']   ?? '' ) ),
			'completed_at'  => sanitize_text_field( (string) ( $row['completed_at']  ?? '' ) ),
			'last_activity' => sanitize_text_field( (string) ( $row['last_activity'] ?? '' ) ),
			'expires_at'    => sanitize_text_field( (string) ( $row['expires_at']    ?? '' ) ),
		);
	}
}
