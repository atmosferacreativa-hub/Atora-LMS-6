<?php
/**
 * Groups service layer (DB + helpers).
 *
 * @package ATORA_LMS
 * @since   6.13.3
 */

namespace ATORA\Groups;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Group_Service {

	private function table_groups(): string {
		global $wpdb;
		return $wpdb->prefix . 'clms_groups';
	}

	private function table_members(): string {
		global $wpdb;
		return $wpdb->prefix . 'clms_group_members';
	}

	private function table_audit(): string {
		global $wpdb;
		return $wpdb->prefix . 'clms_group_audit_log';
	}

	private function table_submissions(): string {
		global $wpdb;
		return $wpdb->prefix . 'clms_group_submissions';
	}

	private function table_overrides(): string {
		global $wpdb;
		return $wpdb->prefix . 'clms_group_grade_overrides';
	}

	public function create_group( int $course_id, string $name, int $actor_user_id ): int {
		global $wpdb;

		$course_id     = absint( $course_id );
		$actor_user_id = absint( $actor_user_id );
		$name          = trim( $name );

		if ( ! $course_id || '' === $name ) {
			return 0;
		}

		$ok = (int) $wpdb->insert(
			$this->table_groups(),
			array(
				'course_id'   => $course_id,
				'name'        => $name,
				'created_by'  => $actor_user_id,
				'created_at'  => current_time( 'mysql' ),
				'updated_at'  => current_time( 'mysql' ),
				'locked_at'   => null,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		return $ok ? absint( $wpdb->insert_id ) : 0;
	}

	public function list_groups( int $course_id ): array {
		global $wpdb;
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return array();
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, course_id, name, locked_at, created_at, updated_at
			 FROM {$this->table_groups()}
			 WHERE course_id = %d
			 ORDER BY id ASC",
			$course_id
		), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	public function get_group( int $group_id ): ?array {
		global $wpdb;
		$group_id = absint( $group_id );
		if ( ! $group_id ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, course_id, name, locked_at, created_at, updated_at
				 FROM {$this->table_groups()}
				 WHERE id = %d
				 LIMIT 1",
				$group_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_group_course_id( int $group_id ): int {
		global $wpdb;
		$group_id = absint( $group_id );
		if ( ! $group_id ) {
			return 0;
		}
		$course_id = $wpdb->get_var( $wpdb->prepare( "SELECT course_id FROM {$this->table_groups()} WHERE id = %d", $group_id ) );
		return absint( $course_id );
	}

	public function get_group_member_ids( int $group_id ): array {
		global $wpdb;
		$group_id = absint( $group_id );
		if ( ! $group_id ) {
			return array();
		}

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT user_id FROM {$this->table_members()} WHERE group_id = %d ORDER BY user_id ASC",
			$group_id
		) );

		return array_values( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) );
	}

	public function get_user_group_id( int $user_id, int $course_id, int $lesson_id = 0 ): int {
		global $wpdb;
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$lesson_id = absint( $lesson_id );
		if ( ! $user_id || ! $course_id ) {
			return 0;
		}

		// MVP: groups are course-scoped; lesson_id reserved for future.
		$group_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT gm.group_id
			 FROM {$this->table_members()} gm
			 JOIN {$this->table_groups()} g ON g.id = gm.group_id
			 WHERE gm.user_id = %d AND g.course_id = %d
			 ORDER BY gm.group_id ASC
			 LIMIT 1",
			$user_id,
			$course_id
		) );

		return absint( $group_id );
	}

	public function set_members( int $group_id, array $member_ids, int $actor_user_id ) {
		global $wpdb;
		$group_id      = absint( $group_id );
		$actor_user_id = absint( $actor_user_id );
		$member_ids    = array_values( array_unique( array_filter( array_map( 'absint', $member_ids ) ) ) );

		if ( ! $group_id ) {
			return new \WP_Error( 'invalid_group', __( 'Grupo inválido.', 'atora-lms' ) );
		}

		$locked_at = $wpdb->get_var( $wpdb->prepare( "SELECT locked_at FROM {$this->table_groups()} WHERE id = %d", $group_id ) );
		if ( ! empty( $locked_at ) ) {
			return new \WP_Error( 'group_locked', __( 'El grupo ya tiene entregas registradas y no se puede modificar.', 'atora-lms' ) );
		}

		$current = $this->get_group_member_ids( $group_id );
		$to_add  = array_values( array_diff( $member_ids, $current ) );
		$to_del  = array_values( array_diff( $current, $member_ids ) );

		foreach ( $to_add as $uid ) {
			$wpdb->insert(
				$this->table_members(),
				array(
					'group_id'   => $group_id,
					'user_id'    => absint( $uid ),
					'joined_at'  => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s' )
			);
			$this->audit( $group_id, $actor_user_id, 'member_added', array( 'user_id' => absint( $uid ) ) );
		}

		foreach ( $to_del as $uid ) {
			$wpdb->delete( $this->table_members(), array( 'group_id' => $group_id, 'user_id' => absint( $uid ) ), array( '%d', '%d' ) );
			$this->audit( $group_id, $actor_user_id, 'member_removed', array( 'user_id' => absint( $uid ) ) );
		}

		$wpdb->update(
			$this->table_groups(),
			array( 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $group_id ),
			array( '%s' ),
			array( '%d' )
		);

		return array(
			'success'     => true,
			'group_id'    => $group_id,
			'member_ids'  => $this->get_group_member_ids( $group_id ),
		);
	}

	public function lock_group_if_needed( int $group_id, int $actor_user_id ): void {
		global $wpdb;
		$group_id = absint( $group_id );
		if ( ! $group_id ) {
			return;
		}

		$locked_at = $wpdb->get_var( $wpdb->prepare( "SELECT locked_at FROM {$this->table_groups()} WHERE id = %d", $group_id ) );
		if ( ! empty( $locked_at ) ) {
			return;
		}

		$wpdb->update(
			$this->table_groups(),
			array(
				'locked_at'  => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $group_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		$this->audit( $group_id, $actor_user_id, 'group_locked', array() );
	}

	public function record_group_submission( int $group_id, int $lesson_id, int $submission_id, int $actor_user_id ): void {
		global $wpdb;
		$group_id     = absint( $group_id );
		$lesson_id    = absint( $lesson_id );
		$submission_id = absint( $submission_id );
		if ( ! $group_id || ! $lesson_id || ! $submission_id ) {
			return;
		}

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$this->table_submissions()} WHERE group_id = %d AND lesson_id = %d LIMIT 1",
			$group_id,
			$lesson_id
		) );

		if ( $exists ) {
			$wpdb->update(
				$this->table_submissions(),
				array(
					'submission_id' => $submission_id,
					'updated_at'    => current_time( 'mysql' ),
				),
				array( 'id' => absint( $exists ) ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$this->table_submissions(),
				array(
					'group_id'      => $group_id,
					'lesson_id'     => $lesson_id,
					'submission_id' => $submission_id,
					'submitted_by'  => $actor_user_id,
					'submitted_at'  => current_time( 'mysql' ),
					'updated_at'    => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%d', '%d', '%s', '%s' )
			);
		}
	}

	public function ensure_shadow_submission( int $student_id, int $lesson_id, int $course_id, int $group_id, int $master_submission_id ): int {
		$student_id          = absint( $student_id );
		$lesson_id           = absint( $lesson_id );
		$course_id           = absint( $course_id );
		$group_id            = absint( $group_id );
		$master_submission_id = absint( $master_submission_id );

		if ( ! $student_id || ! $lesson_id || ! $course_id || ! $group_id || ! $master_submission_id ) {
			return 0;
		}

		$existing = $this->find_shadow_submission_id( $student_id, $lesson_id, $group_id, $master_submission_id );
		if ( $existing ) {
			return $existing;
		}

		$shadow_id = wp_insert_post(
			array(
				'post_type'   => 'clms_submission',
				'post_status' => 'private',
				'post_author' => $student_id,
				'post_title'  => sprintf( 'Entrega (grupo): %s - %s', get_the_title( $lesson_id ), wp_date( 'Y-m-d H:i:s' ) ),
			),
			true
		);

		if ( is_wp_error( $shadow_id ) || ! $shadow_id ) {
			return 0;
		}

		update_post_meta( $shadow_id, '_clms_submission_is_shadow', '1' );
		update_post_meta( $shadow_id, '_clms_submission_group_id', $group_id );
		update_post_meta( $shadow_id, '_clms_submission_group_master_id', $master_submission_id );
		update_post_meta( $shadow_id, '_clms_submission_user_id', $student_id );
		update_post_meta( $shadow_id, '_clms_submission_lesson_id', $lesson_id );
		update_post_meta( $shadow_id, '_clms_submission_course_id', $course_id );
		update_post_meta( $shadow_id, '_clms_submission_status', 'submitted' );
		update_post_meta( $shadow_id, '_clms_submission_submitted_at', current_time( 'mysql' ) );

		return absint( $shadow_id );
	}

	public function find_shadow_submission_id( int $student_id, int $lesson_id, int $group_id, int $master_submission_id ): int {
		$student_id          = absint( $student_id );
		$lesson_id           = absint( $lesson_id );
		$group_id            = absint( $group_id );
		$master_submission_id = absint( $master_submission_id );
		if ( ! $student_id || ! $lesson_id || ! $group_id || ! $master_submission_id ) {
			return 0;
		}

		$ids = get_posts( array(
			'post_type'      => 'clms_submission',
			'post_status'    => array( 'private', 'publish' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => '_clms_submission_is_shadow',
					'value' => '1',
				),
				array(
					'key'   => '_clms_submission_user_id',
					'value' => $student_id,
					'type'  => 'NUMERIC',
				),
				array(
					'key'   => '_clms_submission_lesson_id',
					'value' => $lesson_id,
					'type'  => 'NUMERIC',
				),
				array(
					'key'   => '_clms_submission_group_id',
					'value' => $group_id,
					'type'  => 'NUMERIC',
				),
				array(
					'key'   => '_clms_submission_group_master_id',
					'value' => $master_submission_id,
					'type'  => 'NUMERIC',
				),
			),
		) );

		return ! empty( $ids ) ? absint( $ids[0] ) : 0;
	}

	public function sync_shadow_grade_from_master( int $shadow_id, int $master_submission_id ): void {
		$shadow_id          = absint( $shadow_id );
		$master_submission_id = absint( $master_submission_id );
		if ( ! $shadow_id || ! $master_submission_id ) {
			return;
		}

		$grade    = get_post_meta( $master_submission_id, '_clms_submission_grade', true );
		$feedback = (string) get_post_meta( $master_submission_id, '_clms_submission_feedback', true );
		if ( '' !== (string) $grade ) {
			update_post_meta( $shadow_id, '_clms_submission_grade', $grade );
		}
		if ( '' !== trim( $feedback ) ) {
			update_post_meta( $shadow_id, '_clms_submission_feedback', $feedback );
		}
	}

	public function apply_override_if_any( int $group_id, int $lesson_id, int $student_id, $base_grade ) {
		global $wpdb;
		$group_id   = absint( $group_id );
		$lesson_id  = absint( $lesson_id );
		$student_id = absint( $student_id );
		if ( ! $group_id || ! $lesson_id || ! $student_id ) {
			return $base_grade;
		}

		$override = $wpdb->get_var( $wpdb->prepare(
			"SELECT override_grade FROM {$this->table_overrides()}
			 WHERE group_id = %d AND lesson_id = %d AND student_id = %d
			 ORDER BY id DESC
			 LIMIT 1",
			$group_id,
			$lesson_id,
			$student_id
		) );

		if ( '' === (string) $override ) {
			return $base_grade;
		}

		if ( ! is_numeric( $override ) ) {
			return $base_grade;
		}

		return max( 0, min( 100, (int) round( (float) $override ) ) );
	}

	public function publish_shadow_grade( int $shadow_id, $grade, string $feedback ): void {
		$shadow_id = absint( $shadow_id );
		if ( ! $shadow_id ) {
			return;
		}

		$assessment = class_exists( 'CLMS_Helper' ) ? clms_core( 'CLMS_Assessment_Engine' ) : null;
		if ( $assessment && method_exists( $assessment, 'publish_submission_grade' ) ) {
			$assessment->publish_submission_grade(
				$shadow_id,
				array(
					'grade'           => '' !== (string) $grade && is_numeric( $grade ) ? max( 0, min( 100, (int) round( (float) $grade ) ) ) : '',
					'feedback'        => wp_kses_post( $feedback ),
					'source'          => 'system',
					'status'          => 'graded',
					'manual_override' => true,
					'trigger'         => 'group_assessment',
				)
			);
			return;
		}

		if ( '' !== (string) $grade && is_numeric( $grade ) ) {
			update_post_meta( $shadow_id, '_clms_submission_grade', max( 0, min( 100, (int) round( (float) $grade ) ) ) );
		} else {
			delete_post_meta( $shadow_id, '_clms_submission_grade' );
		}
		update_post_meta( $shadow_id, '_clms_submission_feedback', wp_kses_post( $feedback ) );
		update_post_meta( $shadow_id, '_clms_submission_status', 'graded' );

		$student_id = absint( get_post_meta( $shadow_id, '_clms_submission_user_id', true ) );
		do_action( 'clms_submission_graded', $shadow_id, $student_id, 'graded', $grade, $feedback );
	}

	/**
	 * Sets an override grade for a student in a group/lesson.
	 *
	 * @return array|\WP_Error
	 */
	public function set_override( int $group_id, int $lesson_id, int $student_id, $override_grade, string $reason, int $actor_user_id ) {
		global $wpdb;
		$group_id      = absint( $group_id );
		$lesson_id     = absint( $lesson_id );
		$student_id    = absint( $student_id );
		$actor_user_id = absint( $actor_user_id );
		$reason        = sanitize_textarea_field( $reason );

		if ( ! $group_id || ! $lesson_id || ! $student_id ) {
			return new \WP_Error( 'invalid_request', __( 'Datos inválidos.', 'atora-lms' ) );
		}

		if ( '' !== (string) $override_grade ) {
			if ( ! is_numeric( $override_grade ) ) {
				return new \WP_Error( 'invalid_grade', __( 'La nota debe ser numérica.', 'atora-lms' ) );
			}
			$override_grade = max( 0, min( 100, (int) round( (float) $override_grade ) ) );
		} else {
			$override_grade = null;
		}

		$now = current_time( 'mysql' );
		if ( null === $override_grade ) {
			$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$this->table_overrides()}
					(group_id, lesson_id, student_id, override_grade, reason, set_by, set_at)
				 VALUES (%d, %d, %d, NULL, %s, %d, %s)",
				$group_id,
				$lesson_id,
				$student_id,
				$reason,
				$actor_user_id,
				$now
			) );
		} else {
			$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$this->table_overrides()}
					(group_id, lesson_id, student_id, override_grade, reason, set_by, set_at)
				 VALUES (%d, %d, %d, %d, %s, %d, %s)",
				$group_id,
				$lesson_id,
				$student_id,
				$override_grade,
				$reason,
				$actor_user_id,
				$now
			) );
		}

		$this->audit( $group_id, $actor_user_id, 'grade_override_set', array(
			'lesson_id'  => $lesson_id,
			'student_id' => $student_id,
			'grade'      => $override_grade,
		) );

		return array(
			'success'       => true,
			'group_id'      => $group_id,
			'lesson_id'     => $lesson_id,
			'student_id'    => $student_id,
			'override_grade' => $override_grade,
		);
	}

	private function audit( int $group_id, int $actor_user_id, string $action, array $data ): void {
		global $wpdb;
		$wpdb->insert(
			$this->table_audit(),
			array(
				'group_id'   => absint( $group_id ),
				'actor_id'   => absint( $actor_user_id ),
				'action'     => sanitize_key( $action ),
				'data'       => wp_json_encode( $data ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}
}
