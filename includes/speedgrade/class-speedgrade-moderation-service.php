<?php
/**
 * Moderación institucional de calificaciones desde SpeedGrader 2.
 *
 * @package ATORA_LMS
 * @since 6.23.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_SpeedGrade_Moderation_Service {

	public function get_context( $submission_id, $course_id, $actor_id = 0 ) {
		global $wpdb;

		$cycle = $this->get_active_cycle( $course_id );
		if ( empty( $cycle ) ) {
			return array(
				'institutional' => false,
				'status'        => 'none',
			);
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}atora_grade_moderations WHERE submission_id = %d AND cycle_id = %d LIMIT 1",
				absint( $submission_id ),
				absint( $cycle['id'] )
			),
			ARRAY_A
		);
		$row = is_array( $row ) ? $row : array();

		return array_merge(
			array(
				'institutional' => true,
				'cycle_id'      => absint( $cycle['id'] ),
				'cycle_status'  => sanitize_key( (string) $cycle['status'] ),
				'status'        => 'none',
				'lock_version'  => 0,
				'can_submit'    => 'open' === $cycle['status'],
				'can_moderate'  => false,
			),
			$row,
			array(
				'institutional' => true,
				'cycle_id'      => absint( $cycle['id'] ),
				'cycle_status'  => sanitize_key( (string) $cycle['status'] ),
				'status'        => sanitize_key( (string) ( $row['status'] ?? 'none' ) ),
				'can_submit'    => 'open' === $cycle['status'] && in_array( $row['status'] ?? 'none', array( 'none', 'changes_requested' ), true ),
				'can_moderate'  => current_user_can( 'manage_options' )
					&& CLMS_SpeedGrade_Moderation_Policy::can_moderate( $row['primary_grader_id'] ?? 0, $actor_id ?: get_current_user_id() )
					&& 'pending' === ( $row['status'] ?? '' ),
			)
		);
	}

	public function submit( $submission_id, $course_id, $student_id, $grade, $rubric_scores, $feedback, $actor_id = 0, $expected_lock_version = 0 ) {
		global $wpdb;

		$actor_id = absint( $actor_id ?: get_current_user_id() );
		$cycle    = $this->get_active_cycle( $course_id );
		if ( empty( $cycle ) || 'open' !== $cycle['status'] ) {
			return new WP_Error( 'clms_moderation_cycle_not_open', __( 'El curso no tiene un ciclo institucional abierto.', 'atora-lms' ) );
		}
		if ( ! is_numeric( $grade ) || (float) $grade < 0 || (float) $grade > 100 ) {
			return new WP_Error( 'clms_moderation_grade_required', __( 'La moderación requiere una nota válida.', 'atora-lms' ) );
		}

		$table = $wpdb->prefix . 'atora_grade_moderations';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE submission_id = %d AND cycle_id = %d LIMIT 1", absint( $submission_id ), absint( $cycle['id'] ) ),
			ARRAY_A
		);
		$current_status = sanitize_key( (string) ( $row['status'] ?? 'none' ) );
		if ( ! CLMS_SpeedGrade_Moderation_Policy::can_transition( $current_status, 'pending' ) ) {
			return new WP_Error( 'clms_moderation_invalid_transition', __( 'Esta propuesta no puede volver a enviarse en su estado actual.', 'atora-lms' ) );
		}

		$now     = current_time( 'mysql', true );
		$payload = array(
			'student_id'         => absint( $student_id ),
			'course_id'          => absint( $course_id ),
			'primary_grader_id'  => $actor_id,
			'primary_grade'      => (float) $grade,
			'primary_rubric_json'=> wp_json_encode( is_array( $rubric_scores ) ? $rubric_scores : array() ),
			'teacher_comment'    => sanitize_textarea_field( (string) $feedback ),
			'status'             => 'pending',
			'submitted_at'       => $now,
		);

		if ( $row ) {
			$lock = absint( $row['lock_version'] ?? 1 );
			if ( $expected_lock_version && $expected_lock_version !== $lock ) {
				return new WP_Error( 'clms_moderation_revision_conflict', __( 'La moderación cambió. Actualiza antes de reenviar.', 'atora-lms' ) );
			}
			$payload['lock_version'] = $lock + 1;
			$updated = $wpdb->update(
				$table,
				$payload,
				array( 'id' => absint( $row['id'] ), 'lock_version' => $lock, 'status' => $current_status ),
				array( '%d', '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%d' ),
				array( '%d', '%d', '%s' )
			);
			if ( 1 !== $updated ) {
				return new WP_Error( 'clms_moderation_concurrent_update', __( 'La moderación fue modificada por otra persona.', 'atora-lms' ) );
			}
			$id = absint( $row['id'] );
		} else {
			$payload = array_merge(
				array( 'submission_id' => absint( $submission_id ), 'cycle_id' => absint( $cycle['id'] ) ),
				$payload,
				array( 'lock_version' => 1 )
			);
			if ( ! $wpdb->insert( $table, $payload, array( '%d', '%d', '%d', '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%d' ) ) ) {
				return new WP_Error( 'clms_moderation_insert_failed', __( 'No se pudo enviar la propuesta a moderación.', 'atora-lms' ) );
			}
			$id = (int) $wpdb->insert_id;
		}

		$this->log_event( 'moderation_submitted', $id, array( 'cycle_id' => absint( $cycle['id'] ), 'submission_id' => absint( $submission_id ), 'grade' => (float) $grade ), $actor_id );
		return array( 'id' => $id, 'status' => 'pending' );
	}

	public function decide( $submission_id, $course_id, $decision, $grade, $rubric_scores, $comment, $actor_id = 0, $expected_lock_version = 0 ) {
		global $wpdb;

		$actor_id = absint( $actor_id ?: get_current_user_id() );
		$decision = sanitize_key( (string) $decision );
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'clms_moderation_forbidden', __( 'Solo una autoridad institucional puede moderar.', 'atora-lms' ) );
		}
		if ( ! in_array( $decision, array( 'approved', 'changes_requested' ), true ) ) {
			return new WP_Error( 'clms_moderation_invalid_decision', __( 'Decisión de moderación no válida.', 'atora-lms' ) );
		}
		if ( 'changes_requested' === $decision && strlen( trim( wp_strip_all_tags( (string) $comment ) ) ) < 5 ) {
			return new WP_Error( 'clms_moderation_comment_required', __( 'La devolución requiere una justificación para el docente.', 'atora-lms' ) );
		}

		$cycle = $this->get_active_cycle( $course_id );
		$table = $wpdb->prefix . 'atora_grade_moderations';
		$row   = empty( $cycle ) ? null : $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE submission_id = %d AND cycle_id = %d LIMIT 1", absint( $submission_id ), absint( $cycle['id'] ) ),
			ARRAY_A
		);
		if ( empty( $row ) || ! CLMS_SpeedGrade_Moderation_Policy::can_transition( $row['status'], $decision ) ) {
			return new WP_Error( 'clms_moderation_not_pending', __( 'No hay una propuesta pendiente que pueda moderarse.', 'atora-lms' ) );
		}
		if ( ! CLMS_SpeedGrade_Moderation_Policy::can_moderate( $row['primary_grader_id'], $actor_id ) ) {
			return new WP_Error( 'clms_moderation_separation_of_duties', __( 'Quien calificó no puede aprobar su propia propuesta.', 'atora-lms' ) );
		}

		$lock = absint( $row['lock_version'] ?? 1 );
		if ( $expected_lock_version && $expected_lock_version !== $lock ) {
			return new WP_Error( 'clms_moderation_revision_conflict', __( 'La moderación cambió. Actualiza antes de decidir.', 'atora-lms' ) );
		}
		$resolved_grade = is_numeric( $grade ) ? (float) $grade : (float) $row['primary_grade'];
		$updated = $wpdb->update(
			$table,
			array(
				'moderator_id'          => $actor_id,
				'moderator_grade'       => $resolved_grade,
				'resolved_grade'        => 'approved' === $decision ? $resolved_grade : null,
				'moderator_rubric_json' => wp_json_encode( is_array( $rubric_scores ) ? $rubric_scores : array() ),
				'moderator_comment'     => sanitize_textarea_field( (string) $comment ),
				'status'                => $decision,
				'lock_version'          => $lock + 1,
				'decided_at'            => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $row['id'] ), 'lock_version' => $lock, 'status' => 'pending' ),
			array( '%d', '%f', '%f', '%s', '%s', '%s', '%d', '%s' ),
			array( '%d', '%d', '%s' )
		);
		if ( 1 !== $updated ) {
			return new WP_Error( 'clms_moderation_concurrent_update', __( 'La propuesta ya fue procesada.', 'atora-lms' ) );
		}

		if ( 'approved' === $decision ) {
			$gradebook = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Institutional_Gradebook_Service') : null;
			if ( ! $gradebook || ! method_exists( $gradebook, 'save_grade' ) ) {
				return new WP_Error( 'clms_moderation_gradebook_unavailable', __( 'El Gradebook institucional no está disponible.', 'atora-lms' ) );
			}
			$saved = $gradebook->save_grade(
				$cycle['id'],
				$row['student_id'],
				$resolved_grade,
				0,
				$actor_id,
				array(
					'source'        => 'speedgrader_moderation',
					'moderation_id' => absint( $row['id'] ),
					'submission_id' => absint( $submission_id ),
				)
			);
			if ( is_wp_error( $saved ) ) {
				$wpdb->update( $table, array( 'status' => 'pending', 'resolved_grade' => null, 'moderator_id' => 0, 'decided_at' => null ), array( 'id' => absint( $row['id'] ), 'lock_version' => $lock + 1 ), array( '%s', '%f', '%d', '%s' ), array( '%d', '%d' ) );
				return $saved;
			}
		}

		$this->log_event( 'moderation_' . $decision, absint( $row['id'] ), array( 'cycle_id' => absint( $cycle['id'] ), 'submission_id' => absint( $submission_id ), 'grade' => $resolved_grade ), $actor_id );
		return array( 'id' => absint( $row['id'] ), 'status' => $decision, 'grade' => $resolved_grade );
	}

	public function count_unresolved( $cycle_id ) {
		global $wpdb;

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}atora_grade_moderations WHERE cycle_id = %d AND status IN ('pending','changes_requested')",
					absint( $cycle_id )
				)
			)
		);
	}

	protected function get_active_cycle( $course_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}atora_gradebook_cycles WHERE course_id = %d AND status IN ('open','review') ORDER BY CASE status WHEN 'open' THEN 0 ELSE 1 END, id DESC LIMIT 1",
				absint( $course_id )
			),
			ARRAY_A
		);
	}

	protected function log_event( $action, $moderation_id, $details, $actor_id ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'atora_gradebook_events',
			array(
				'actor_id'     => absint( $actor_id ?: get_current_user_id() ),
				'action'       => sanitize_key( (string) $action ),
				'object_type'  => 'grade_moderation',
				'object_id'    => absint( $moderation_id ),
				'details_json' => wp_json_encode( is_array( $details ) ? $details : array() ),
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);
	}
}
