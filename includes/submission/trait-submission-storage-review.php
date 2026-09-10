<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Submission_Storage_Review_Trait {
	public function save_submission( $user_id, $lesson_id, $data = array(), $files_data = array() ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );
		$data      = is_array( $data ) ? $data : array();

		if ( ! $user_id || ! $lesson_id ) {
			return new WP_Error( 'invalid_submission_data', __( 'Faltan datos para guardar la entrega.', 'atora-lms' ) );
		}

		if ( 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'invalid_lesson', __( 'La lección no es válida.', 'atora-lms' ) );
		}

		if ( ! $this->user_can_submit_to_lesson( $user_id, $lesson_id ) ) {
			return new WP_Error( 'cannot_submit', __( 'No tienes permisos para enviar esta tarea.', 'atora-lms' ) );
		}

			$course_id = $this->get_course_id_for_lesson( $lesson_id );
			$comment   = isset( $data['comment'] ) ? wp_kses_post( (string) $data['comment'] ) : '';
			$evaluation_mode = sanitize_key( (string) get_post_meta( $lesson_id, '_clms_evaluation_mode', true ) );

			// Group assessment: one master submission per group + shadow submissions for members.
			if ( 'group' === $evaluation_mode ) {
				$groups_enabled = $course_id ? ( '1' === (string) get_post_meta( $course_id, '_clms_course_groups_enabled', true ) ) : false;
				if ( ! $groups_enabled ) {
					return new WP_Error( 'group_disabled', __( 'Este curso no tiene habilitada la evaluación por grupos.', 'atora-lms' ) );
				}

				$group_id = (int) apply_filters( 'atora/groups/user_group_id', 0, $user_id, absint( $course_id ), $lesson_id );
				$group_id = absint( $group_id );
				if ( ! $group_id ) {
					return new WP_Error( 'no_group', __( 'No tienes un grupo asignado para esta actividad.', 'atora-lms' ) );
				}

				$submission_id = $this->get_existing_group_master_submission_id( $group_id, $lesson_id );
				$is_new        = false;

				if ( $submission_id ) {
					wp_update_post(
						array(
							'ID'         => $submission_id,
							'post_title' => sprintf( 'Entrega (grupo): %s - %s', get_the_title( $lesson_id ), wp_date( 'Y-m-d H:i:s' ) ),
						)
					);
				} else {
					$submission_id = wp_insert_post(
						array(
							'post_type'   => self::CPT,
							'post_status' => 'publish',
							'post_author' => $user_id,
							'post_title'  => sprintf( 'Entrega (grupo): %s - %s', get_the_title( $lesson_id ), wp_date( 'Y-m-d H:i:s' ) ),
						),
						true
					);

					if ( is_wp_error( $submission_id ) || ! $submission_id ) {
						return new WP_Error( 'submission_create_failed', __( 'No se pudo crear la entrega grupal.', 'atora-lms' ) );
					}

					$is_new = true;
				}

				// Group master submission is owned by the group (not a single student) to avoid
				// double-counting in per-student analytics. The submitter is stored separately.
				update_post_meta( $submission_id, '_clms_submission_user_id', 0 );
				update_post_meta( $submission_id, '_clms_submission_submitted_by', $user_id );
				update_post_meta( $submission_id, '_clms_submission_lesson_id', $lesson_id );
				update_post_meta( $submission_id, '_clms_submission_course_id', $course_id );
				update_post_meta( $submission_id, '_clms_submission_comment', $comment );
				update_post_meta( $submission_id, '_clms_submission_status', 'submitted' );
				update_post_meta( $submission_id, '_clms_submission_submitted_at', current_time( 'mysql' ) );
				update_post_meta( $submission_id, '_clms_submission_group_id', $group_id );
				update_post_meta( $submission_id, '_clms_submission_group_master', '1' );

				$stale_review_meta = array(
					'_clms_submission_grade',
					'_clms_submission_feedback',
					'_clms_submission_rubric_scores',
					'_clms_peer_grade',
					'_clms_peer_scores',
					'_clms_peer_grade_at',
					'_clms_final_grade',
				);

				foreach ( $stale_review_meta as $meta_key ) {
					delete_post_meta( $submission_id, $meta_key );
				}

				$attachment_ids = $this->handle_uploaded_files( $submission_id, $user_id, $files_data );

				if ( is_wp_error( $attachment_ids ) ) {
					return $attachment_ids;
				}

				if ( ! empty( $attachment_ids ) ) {
					update_post_meta( $submission_id, '_clms_submission_files', $attachment_ids );
					update_post_meta( $submission_id, '_clms_submission_attachments', $attachment_ids );
				}

				$this->reset_ai_review_data( $submission_id );

				if ( $is_new ) {
					do_action( 'clms_submission_created', $submission_id, $lesson_id, $user_id );
				}

				do_action( 'clms_submission_saved', $submission_id, $user_id, $lesson_id, $course_id );
				do_action( 'atora/groups/master_submission_saved', $group_id, $submission_id, $lesson_id, absint( $course_id ), $user_id );

				return $submission_id;
			}

			$evaluation_mode = sanitize_key( (string) get_post_meta( $lesson_id, '_clms_evaluation_mode', true ) );
			if ( 'group' === $evaluation_mode ) {
				$course_id = $this->get_course_id_for_lesson( $lesson_id );
				$group_id  = (int) apply_filters( 'atora/groups/user_group_id', 0, $user_id, absint( $course_id ), $lesson_id );
				$group_id  = absint( $group_id );
				$submission_id = $group_id ? $this->get_existing_group_master_submission_id( $group_id, $lesson_id ) : 0;
			} else {
				$submission_id = $this->get_existing_submission_id( $user_id, $lesson_id );
			}
			$is_new        = false;

		if ( $submission_id ) {
			wp_update_post(
				array(
					'ID'         => $submission_id,
					'post_title' => sprintf( 'Entrega: %s - %s', get_the_title( $lesson_id ), wp_date( 'Y-m-d H:i:s' ) ),
				)
			);
		} else {
			$submission_id = wp_insert_post(
				array(
					'post_type'   => self::CPT,
					'post_status' => 'publish',
					'post_author' => $user_id,
					'post_title'  => sprintf( 'Entrega: %s - %s', get_the_title( $lesson_id ), wp_date( 'Y-m-d H:i:s' ) ),
				),
				true
			);

			if ( is_wp_error( $submission_id ) || ! $submission_id ) {
				return new WP_Error( 'submission_create_failed', __( 'No se pudo crear la entrega.', 'atora-lms' ) );
			}

			$is_new = true;
		}

		update_post_meta( $submission_id, '_clms_submission_user_id', $user_id );
		update_post_meta( $submission_id, '_clms_submission_lesson_id', $lesson_id );
		update_post_meta( $submission_id, '_clms_submission_course_id', $course_id );
		update_post_meta( $submission_id, '_clms_submission_comment', $comment );
		update_post_meta( $submission_id, '_clms_submission_status', 'submitted' );
		update_post_meta( $submission_id, '_clms_submission_submitted_at', current_time( 'mysql' ) );

		$stale_review_meta = array(
			'_clms_submission_grade',
			'_clms_submission_feedback',
			'_clms_submission_rubric_scores',
			'_clms_peer_grade',
			'_clms_peer_scores',
			'_clms_peer_grade_at',
			'_clms_final_grade',
		);

		foreach ( $stale_review_meta as $meta_key ) {
			delete_post_meta( $submission_id, $meta_key );
		}

		$attachment_ids = $this->handle_uploaded_files( $submission_id, $user_id, $files_data );

		if ( is_wp_error( $attachment_ids ) ) {
			return $attachment_ids;
		}

		if ( ! empty( $attachment_ids ) ) {
			update_post_meta( $submission_id, '_clms_submission_files', $attachment_ids );
			update_post_meta( $submission_id, '_clms_submission_attachments', $attachment_ids );
		}

		$this->reset_ai_review_data( $submission_id );

		if ( $is_new ) {
			do_action( 'clms_submission_created', $submission_id, $lesson_id, $user_id );
		}

		do_action( 'clms_submission_saved', $submission_id, $user_id, $lesson_id, $course_id );

		return $submission_id;
	}

	/**
	 * Busca la entrega del usuario para grading/progreso.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $lesson_id Lección.
	 * @return array
	 */
	public function get_user_submission_for_grading( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return array();
		}

		$submission_id = 0;

		$evaluation_mode = sanitize_key( (string) get_post_meta( $lesson_id, '_clms_evaluation_mode', true ) );
		if ( 'group' === $evaluation_mode ) {
			$course_id = $this->get_course_id_for_lesson( $lesson_id );
			$group_id  = $course_id ? absint( (int) apply_filters( 'atora/groups/user_group_id', 0, $user_id, absint( $course_id ), $lesson_id ) ) : 0;

			// Intentar primero la shadow (submission del estudiante).
			$submission_id = $this->get_existing_submission_id( $user_id, $lesson_id );

			// Hardening: si por alguna razón no existe shadow aún, crearla desde el master.
			if ( ! $submission_id && $group_id ) {
				$master_id = $this->get_existing_group_master_submission_id( $group_id, $lesson_id );
				if ( $master_id && class_exists( '\ATORA\Groups\Group_Service' ) ) {
					$service = new \ATORA\Groups\Group_Service();
					$shadow_id = $service->ensure_shadow_submission( $user_id, $lesson_id, absint( $course_id ), $group_id, absint( $master_id ) );
					if ( $shadow_id ) {
						$service->sync_shadow_grade_from_master( absint( $shadow_id ), absint( $master_id ) );
						$submission_id = absint( $shadow_id );
					}
				}
			}
		} else {
			$submission_id = $this->get_existing_submission_id( $user_id, $lesson_id );
		}

		if ( ! $submission_id ) {
			return array();
		}

		$files = get_post_meta( $submission_id, '_clms_submission_files', true );
		if ( ! is_array( $files ) || empty( $files ) ) {
			$files = get_post_meta( $submission_id, '_clms_submission_attachments', true );
		}

		$files = is_array( $files ) ? array_values( array_filter( array_map( 'absint', $files ) ) ) : array();
		$grade = get_post_meta( $submission_id, '_clms_submission_grade', true );

		if ( '' === (string) $grade ) {
			$grade = get_post_meta( $submission_id, '_clms_final_grade', true );
		}

		return array(
			'submission_id' => $submission_id,
			'status'        => (string) get_post_meta( $submission_id, '_clms_submission_status', true ),
			'grade'         => $grade,
			'feedback'      => (string) get_post_meta( $submission_id, '_clms_submission_feedback', true ),
			'comment'       => (string) get_post_meta( $submission_id, '_clms_submission_comment', true ),
			'rubric_scores' => get_post_meta( $submission_id, '_clms_submission_rubric_scores', true ),
			'files'         => $files,
		);
	}

	protected function build_student_rubric_feedback( $lesson_id, $submission ) {
		$lesson_id = absint( $lesson_id );
		$submission = is_array( $submission ) ? $submission : array();
		$rubric_id = $lesson_id ? absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) ) : 0;
		if ( ! $rubric_id || ! class_exists( 'CLMS_Rubric' ) ) {
			return array();
		}

		$criteria = CLMS_Rubric::get_criteria( $rubric_id );
		$scores   = isset( $submission['rubric_scores'] ) && is_array( $submission['rubric_scores'] ) ? $submission['rubric_scores'] : array();
		if ( empty( $criteria ) || empty( $scores ) ) {
			return array();
		}

		$rows = array();
		$strengths = array();
		$reinforce = array();
		$recommendation = '';

		foreach ( $criteria as $index => $criterion ) {
			$criterion_name = isset( $criterion['name'] ) ? sanitize_text_field( (string) $criterion['name'] ) : '';
			$criterion_comp = isset( $criterion['competency'] ) ? sanitize_text_field( (string) $criterion['competency'] ) : __( 'No definida', 'atora-lms' );
			$criterion_tip  = isset( $criterion['improvement_tip'] ) ? sanitize_text_field( (string) $criterion['improvement_tip'] ) : '';
			$max_points     = isset( $criterion['max_points'] ) ? max( 1, absint( $criterion['max_points'] ) ) : 100;
			$score_data     = isset( $scores[ $index ] ) && is_array( $scores[ $index ] ) ? $scores[ $index ] : array();
			$score_value    = isset( $score_data['score'] ) && '' !== (string) $score_data['score'] ? floatval( $score_data['score'] ) : 0;
			$feedback       = isset( $score_data['feedback'] ) ? sanitize_text_field( (string) $score_data['feedback'] ) : '';
			$ratio          = $max_points > 0 ? ( $score_value / $max_points ) : 0;

			$rows[] = array(
				'name'       => $criterion_name,
				'competency' => $criterion_comp,
				'score'      => round( $score_value, 2 ),
				'max'        => $max_points,
				'feedback'   => $feedback,
			);

			if ( $ratio >= 0.75 ) {
				$strengths[] = $criterion_name;
			} elseif ( $ratio <= 0.55 ) {
				$reinforce[] = $criterion_name;
				if ( '' === $recommendation && '' !== $criterion_tip ) {
					$recommendation = $criterion_tip;
				}
			}
		}

		return array(
			'rows'           => $rows,
			'strengths'      => array_values( array_filter( array_unique( $strengths ) ) ),
			'reinforce'      => array_values( array_filter( array_unique( $reinforce ) ) ),
			'recommendation' => $recommendation,
		);
	}

	/**
	 * Obtiene el estado IA guardado.
	 *
	 * @param int $submission_id ID entrega.
	 * @return array
	 */
	public function get_ai_review_data( $submission_id ) {
		$submission_id = absint( $submission_id );

		if ( ! $submission_id ) {
			return $this->get_empty_ai_review_data();
		}

		return array(
			'status'           => (string) get_post_meta( $submission_id, '_clms_ai_review_status', true ),
			'provider'         => (string) get_post_meta( $submission_id, '_clms_ai_review_provider', true ),
			'model'            => (string) get_post_meta( $submission_id, '_clms_ai_review_model', true ),
			'summary'          => (string) get_post_meta( $submission_id, '_clms_ai_review_summary', true ),
			'rubric'           => get_post_meta( $submission_id, '_clms_ai_review_rubric', true ),
			'raw_response'     => get_post_meta( $submission_id, '_clms_ai_review_raw_response', true ),
			'generated_at'     => (string) get_post_meta( $submission_id, '_clms_ai_review_generated_at', true ),
			'last_error'       => (string) get_post_meta( $submission_id, '_clms_ai_review_last_error', true ),
			'suggested_grade'  => get_post_meta( $submission_id, '_clms_ai_review_suggested_grade', true ),
			'suggested_status' => (string) get_post_meta( $submission_id, '_clms_ai_review_suggested_status', true ),
			'feedback_draft'   => (string) get_post_meta( $submission_id, '_clms_ai_review_feedback_draft', true ),
			'criteria_scores'  => get_post_meta( $submission_id, '_clms_ai_review_criteria_scores', true ),
			'updated_at'       => (string) get_post_meta( $submission_id, '_clms_ai_review_generated_at', true ),
			'error'            => (string) get_post_meta( $submission_id, '_clms_ai_review_last_error', true ),
			'raw'              => get_post_meta( $submission_id, '_clms_ai_review_raw_response', true ),
			'source_files'     => get_post_meta( $submission_id, '_clms_ai_review_source_files', true ),
			'highlights'       => get_post_meta( $submission_id, '_clms_ai_review_highlights', true ),
			'confidence'       => get_post_meta( $submission_id, '_clms_ai_review_confidence', true ),
		);
	}

	/**
	 * Persiste el estado enriquecido de revisión IA.
	 *
	 * @param int   $submission_id ID entrega.
	 * @param array $data          Datos IA normalizados.
	 * @return void
	 */
	public function update_ai_review_data( $submission_id, $data = array() ) {
		$submission_id = absint( $submission_id );
		$data          = is_array( $data ) ? $data : array();

		if ( ! $submission_id ) {
			return;
		}

		$map = array(
			'status'           => '_clms_ai_review_status',
			'provider'         => '_clms_ai_review_provider',
			'model'            => '_clms_ai_review_model',
			'summary'          => '_clms_ai_review_summary',
			'generated_at'     => '_clms_ai_review_generated_at',
			'updated_at'       => '_clms_ai_review_generated_at',
			'last_error'       => '_clms_ai_review_last_error',
			'error'            => '_clms_ai_review_last_error',
			'suggested_grade'  => '_clms_ai_review_suggested_grade',
			'score_suggestion' => '_clms_ai_review_suggested_grade',
			'suggested_status' => '_clms_ai_review_suggested_status',
			'feedback_draft'   => '_clms_ai_review_feedback_draft',
			'confidence'       => '_clms_ai_review_confidence',
		);

		foreach ( $map as $source_key => $meta_key ) {
			if ( ! array_key_exists( $source_key, $data ) ) {
				continue;
			}

			$value = $data[ $source_key ];

			if ( in_array( $source_key, array( 'suggested_grade', 'score_suggestion' ), true ) && '' !== (string) $value ) {
				$value = max( 0, min( 100, absint( $value ) ) );
			} elseif ( 'confidence' === $source_key && '' !== (string) $value ) {
				$value = max( 0.0, min( 1.0, (float) $value ) );
			} else {
				$value = is_scalar( $value ) ? sanitize_textarea_field( (string) $value ) : '';
			}

			if ( '' === (string) $value ) {
				delete_post_meta( $submission_id, $meta_key );
			} else {
				update_post_meta( $submission_id, $meta_key, $value );
			}
		}

		if ( array_key_exists( 'criteria_scores', $data ) ) {
			update_post_meta( $submission_id, '_clms_ai_review_criteria_scores', is_array( $data['criteria_scores'] ) ? $data['criteria_scores'] : array() );
			update_post_meta( $submission_id, '_clms_ai_review_rubric', is_array( $data['criteria_scores'] ) ? $data['criteria_scores'] : array() );
		}

		if ( array_key_exists( 'rubric', $data ) ) {
			update_post_meta( $submission_id, '_clms_ai_review_rubric', is_array( $data['rubric'] ) ? $data['rubric'] : array() );
		}

		if ( array_key_exists( 'raw', $data ) ) {
			update_post_meta( $submission_id, '_clms_ai_review_raw_response', is_array( $data['raw'] ) ? $data['raw'] : $data['raw'] );
		} elseif ( array_key_exists( 'raw_response', $data ) ) {
			update_post_meta( $submission_id, '_clms_ai_review_raw_response', is_array( $data['raw_response'] ) ? $data['raw_response'] : $data['raw_response'] );
		}

		if ( array_key_exists( 'source_files', $data ) ) {
			update_post_meta( $submission_id, '_clms_ai_review_source_files', is_array( $data['source_files'] ) ? array_values( array_filter( array_map( 'absint', $data['source_files'] ) ) ) : array() );
		}

		if ( array_key_exists( 'highlights', $data ) ) {
			update_post_meta( $submission_id, '_clms_ai_review_highlights', is_array( $data['highlights'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $data['highlights'] ) ) ) : array() );
		}
	}

	/**
	 * Limpia datos de IA al reenviar una tarea.
	 *
	 * @param int $submission_id ID entrega.
	 * @return void
	 */
	public function reset_ai_review_data( $submission_id ) {
		$submission_id = absint( $submission_id );

		if ( ! $submission_id ) {
			return;
		}

		$keys = array(
			'_clms_ai_review_status',
			'_clms_ai_review_provider',
			'_clms_ai_review_model',
			'_clms_ai_review_summary',
			'_clms_ai_review_rubric',
			'_clms_ai_review_raw_response',
			'_clms_ai_review_generated_at',
			'_clms_ai_review_last_error',
			'_clms_ai_review_suggested_grade',
			'_clms_ai_review_suggested_status',
			'_clms_ai_review_feedback_draft',
			'_clms_ai_review_criteria_scores',
			'_clms_ai_review_source_files',
			'_clms_ai_review_highlights',
			'_clms_ai_review_confidence',
		);

		foreach ( $keys as $key ) {
			delete_post_meta( $submission_id, $key );
		}
	}

	/**
	 * Estado vacío IA.
	 *
	 * @return array
	 */
	protected function get_empty_ai_review_data() {
		return array(
			'status'           => '',
			'provider'         => '',
			'model'            => '',
			'summary'          => '',
			'rubric'           => array(),
			'raw_response'     => array(),
			'generated_at'     => '',
			'last_error'       => '',
			'suggested_grade'  => '',
			'suggested_status' => '',
			'feedback_draft'   => '',
			'criteria_scores'  => array(),
			'updated_at'       => '',
			'error'            => '',
			'raw'              => array(),
			'source_files'     => array(),
			'highlights'       => array(),
			'confidence'       => '',
		);
	}

	/**
	 * Obtiene label del estado.
	 *
	 * @param string $status Estado.
	 * @return string
	 */
	public function get_status_label( $status ) {
		switch ( (string) $status ) {
			case 'graded':
				return 'Calificada';
			case 'needs_revision':
				return 'Requiere ajustes';
			case 'returned':
				return 'Devuelta para ajustes';
			case 'in_review':
				return 'En revisión';
			case 'submitted':
				return 'Enviada';
			default:
				return 'Actualizada';
		}
	}

	/**
	 * Redirige de vuelta con mensaje.
	 *
	 * @param int    $lesson_id Lección.
	 * @param string $status    success|error.
	 * @param string $message   Mensaje.
	 * @return void
	 */
	protected function redirect_back_with_submission_message( $lesson_id, $status = 'success', $message = '' ) {
		$lesson_id = absint( $lesson_id );
		$status    = sanitize_key( $status );
		$message   = sanitize_text_field( (string) $message );

		$redirect = wp_get_referer();

		if ( ! $redirect ) {
			$redirect = get_permalink( $lesson_id );
		}

		if ( ! $redirect ) {
			$redirect = home_url( '/' );
		}

		$args = array(
			'clms_submission' => $status,
		);

		if ( '' !== $message ) {
			$args['message'] = $message;
		}

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/**
	 * Renderiza aviso según query string.
	 *
	 * @return string
	 */
	protected function render_submission_notice() {
		if ( empty( $_GET['clms_submission'] ) ) {
			return '';
		}

		$status  = sanitize_key( wp_unslash( $_GET['clms_submission'] ) );
		$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '';

		if ( 'success' === $status ) {
			$text = $message ? $message : 'Tu entrega fue enviada correctamente.';
			return '<div class="clms-message-success">' . esc_html( $text ) . '</div>';
		}

		if ( 'error' === $status ) {
			$text = $message ? $message : 'No se pudo procesar tu entrega.';
			return '<div class="clms-message-error">' . esc_html( $text ) . '</div>';
		}

		return '';
	}

	/**
	 * Verifica si el usuario puede enviar a una lección.
	 *
	 * @param int $user_id Usuario.
	 * @param int $lesson_id Lección.
	 * @return bool
	 */
	protected function user_can_submit_to_lesson( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return false;
		}

		if ( CLMS_Helper::user_can_manage_lms( $lesson_id ) ) {
			return true;
		}

		if ( method_exists( 'CLMS_Helper', 'user_can_access_lesson' ) ) {
			return (bool) CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id );
		}

		$course_id = $this->get_course_id_for_lesson( $lesson_id );

		if ( ! $course_id ) {
			return false;
		}

		if ( method_exists( 'CLMS_Helper', 'user_is_enrolled_in_course' ) ) {
			return (bool) CLMS_Helper::user_is_enrolled_in_course( $user_id, $course_id );
		}

		return false;
	}

	/**
	 * Verifica si el usuario puede ver un attachment suyo.
	 *
	 * @param int $user_id Usuario.
	 * @param int $file_id Attachment.
	 * @return bool
	 */
	protected function user_can_view_attachment( $user_id, $file_id ) {
		$user_id = absint( $user_id );
		$file_id = absint( $file_id );

		if ( ! $user_id || ! $file_id ) {
			return false;
		}

		if ( CLMS_Helper::user_can_manage_lms() ) {
			return true;
		}

		// Group Assessment: permitir ver adjuntos del submission master si el usuario
		// pertenece al mismo grupo (los adjuntos se comparten a nivel grupal).
		$submission_id = absint( get_post_meta( $file_id, '_clms_submission_id', true ) );
		if ( $submission_id && '1' === (string) get_post_meta( $submission_id, '_clms_submission_group_master', true ) ) {
			$group_id  = absint( get_post_meta( $submission_id, '_clms_submission_group_id', true ) );
			$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
			if ( ! $course_id && $lesson_id && class_exists( 'CLMS_Helper' ) ) {
				$course_id = absint( CLMS_Helper::get_lesson_course_id( $lesson_id ) );
			}

			if ( $group_id && $course_id ) {
				$user_group_id = absint( (int) apply_filters( 'atora/groups/user_group_id', 0, $user_id, $course_id, $lesson_id ) );
				if ( $user_group_id && $user_group_id === $group_id ) {
					return true;
				}
			}
		}

		$owner = absint( get_post_meta( $file_id, '_clms_submission_owner', true ) );

		if ( $owner && $owner === $user_id ) {
			return true;
		}

		$post = get_post( $file_id );

		return ( $post && (int) $post->post_author === $user_id );
	}

	/**
	 * Normaliza la nota para salida.
	 *
	 * @param mixed $grade Nota.
	 * @return int
	 */
	protected function normalize_grade_display( $grade ) {
		if ( '' === (string) $grade ) {
			return 0;
		}

		return max( 0, min( 100, absint( $grade ) ) );
	}

	/**
	 * Determina si la nota ya es visible para el estudiante.
	 *
	 * @param array $submission Entrega normalizada.
	 * @return bool
	 */
	protected function can_student_view_published_grade( $submission ) {
		$submission = is_array( $submission ) ? $submission : array();
		$status     = sanitize_key( (string) ( $submission['status'] ?? '' ) );
		$grade      = $submission['grade'] ?? '';

		return 'graded' === $status && '' !== (string) $grade;
	}

	/**
	 * Determina si la retroalimentación es visible para el estudiante.
	 *
	 * @param array $submission Entrega normalizada.
	 * @return bool
	 */
	protected function can_student_view_feedback( $submission ) {
		$submission = is_array( $submission ) ? $submission : array();
		$status     = sanitize_key( (string) ( $submission['status'] ?? '' ) );

		if ( $this->can_student_view_published_grade( $submission ) ) {
			return true;
		}

		return in_array( $status, array( 'needs_revision', 'returned' ), true );
	}

	/**
	 * Mensaje de éxito al recibir una entrega.
	 *
	 * @param int $lesson_id     Lección.
	 * @param int $submission_id Entrega.
	 * @param int $user_id       Estudiante.
	 * @return string
	 */
	protected function build_submission_success_notice( $lesson_id, $submission_id, $user_id ) {
		$lesson_id     = absint( $lesson_id );
		$submission_id = absint( $submission_id );
		$user_id       = absint( $user_id );

		$context = $this->get_submission_retry_context( $lesson_id );
		$mode    = 'manual';
		$assessment = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Assessment_Engine') : null;
		if ( $assessment && method_exists( $assessment, 'get_lesson_evaluation_settings' ) ) {
			$settings = (array) $assessment->get_lesson_evaluation_settings( $lesson_id );
			$mode     = sanitize_key( (string) ( $settings['mode'] ?? 'manual' ) );
		}

		if ( 'ai_auto_grade' === $mode ) {
			$message = __( 'Recibimos tu entrega. La evaluación automática está en proceso y pronto verás tu rendimiento.', 'atora-lms' );
		} else {
			$message = __( 'Recibimos tu entrega correctamente. Tu docente la revisará y te notificaremos cuando haya actualización.', 'atora-lms' );
		}

		$message .= ' ' . $this->build_retry_hint_text( $context );

		/**
		 * Permite ajustar el mensaje de recepción de entrega para el estudiante.
		 *
		 * @param string $message       Mensaje final.
		 * @param int    $lesson_id     Lección.
		 * @param int    $submission_id Entrega.
		 * @param int    $user_id       Estudiante.
		 * @param array  $context       Contexto de oportunidades.
		 */
		$message = (string) apply_filters( 'clms_submission_success_notice', $message, $lesson_id, $submission_id, $user_id, $context );

		return sanitize_text_field( $message );
	}

	/**
	 * Mensaje pedagógico contextual según estado de evaluación.
	 *
	 * @param int   $lesson_id   Lección.
	 * @param array $submission  Datos de entrega.
	 * @return array{text:string,class:string}
	 */
	protected function build_submission_student_message( $lesson_id, $submission ) {
		$lesson_id   = absint( $lesson_id );
		$submission  = is_array( $submission ) ? $submission : array();
		$status      = sanitize_key( (string) ( $submission['status'] ?? '' ) );
		$grade       = isset( $submission['grade'] ) ? absint( $submission['grade'] ) : 0;
		$can_view_grade = $this->can_student_view_published_grade( $submission );
		$context     = $this->get_submission_retry_context( $lesson_id );

		$message = '';
		$class   = 'clms-message';

		if ( $can_view_grade ) {
			if ( $grade >= 90 ) {
				$message = __( 'Excelente nota. Tu trabajo refleja precisión y dominio.', 'atora-lms' );
				$class   = 'clms-message-success';
			} elseif ( $grade >= 70 ) {
				$message = __( 'Buen resultado. Tu avance es sólido y aún puedes potenciarlo.', 'atora-lms' );
				$class   = 'clms-message';
			} else {
				$message = __( 'En esta oportunidad faltó precisión. Revisa la lección y el material de apoyo para tu siguiente intento.', 'atora-lms' );
				$class   = 'clms-message';
			}
		} elseif ( in_array( $status, array( 'needs_revision', 'returned' ), true ) ) {
			$message = __( 'Recibiste observaciones para mejorar. Ajusta tu entrega y vuelve a presentarla con confianza.', 'atora-lms' );
		} elseif ( in_array( $status, array( 'submitted', 'in_review' ), true ) ) {
			$message = __( 'Tu entrega está en revisión. Mientras esperas, puedes reforzar los puntos clave de la lección.', 'atora-lms' );
		}

		if ( '' !== $message ) {
			$message .= ' ' . $this->build_retry_hint_text( $context );
		}

		return array(
			'text'  => sanitize_text_field( $message ),
			'class' => $class,
		);
	}

	/**
	 * Calcula si el estudiante puede reenviar según configuración y plazo.
	 *
	 * @param int $lesson_id Lección.
	 * @return array<string,mixed>
	 */
	protected function get_submission_retry_context( $lesson_id ) {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id ) {
			return array(
				'allow_resubmission' => true,
				'deadline_ts'        => 0,
				'deadline_label'     => '',
				'deadline_expired'   => false,
				'can_retry'          => true,
			);
		}

		$allow_resubmission = true;
		$evidence_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
		if ( $evidence_service && method_exists( $evidence_service, 'get_activity_evidence_config' ) ) {
			$config = (array) $evidence_service->get_activity_evidence_config( $lesson_id );
			$allow_resubmission = array_key_exists( 'allow_resubmission', $config ) ? ! empty( $config['allow_resubmission'] ) : true;
		}

		$deadline_ts = $this->get_lesson_deadline_timestamp( $lesson_id );
		$deadline_expired = $deadline_ts > 0 && time() > $deadline_ts;
		$deadline_label = $deadline_ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $deadline_ts ) : '';
		$can_retry = $allow_resubmission && ! $deadline_expired;

		return array(
			'allow_resubmission' => $allow_resubmission,
			'deadline_ts'        => $deadline_ts,
			'deadline_label'     => $deadline_label,
			'deadline_expired'   => $deadline_expired,
			'can_retry'          => $can_retry,
		);
	}

	/**
	 * Texto corto de oportunidades/plazo.
	 *
	 * @param array $context Contexto de reenvío.
	 * @return string
	 */
	protected function build_retry_hint_text( $context ) {
		$context = is_array( $context ) ? $context : array();
		$deadline = isset( $context['deadline_label'] ) ? (string) $context['deadline_label'] : '';

		if ( ! empty( $context['can_retry'] ) ) {
			$message = __( 'Aún puedes presentar una nueva versión para mejorar tu resultado.', 'atora-lms' );
			if ( $deadline ) {
				$message .= ' ' . sprintf( __( 'Tienes hasta %s para reenviar.', 'atora-lms' ), $deadline );
			}
			return $message;
		}

		if ( ! empty( $context['deadline_expired'] ) ) {
			return __( 'El plazo de esta actividad ya finalizó y no admite nuevos envíos.', 'atora-lms' );
		}

		if ( array_key_exists( 'allow_resubmission', $context ) && ! empty( $context['allow_resubmission'] ) ) {
			return '';
		}

		return __( 'Esta actividad no tiene nuevas oportunidades de envío.', 'atora-lms' );
	}

	/**
	 * Timestamp de fecha/hora límite de la actividad.
	 *
	 * @param int $lesson_id Lección.
	 * @return int
	 */
	protected function get_lesson_deadline_timestamp( $lesson_id ) {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id || ! class_exists( 'CLMS_Helper' ) ) {
			return 0;
		}

		$late_date = (string) CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_late_date', '_clms_due_date_late' ), '' );
		$late_time = (string) CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_late_time' ), '' );
		$due_date  = (string) CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_date', '_clms_due_date' ), '' );
		$due_time  = (string) CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_time', '_clms_due_time' ), '' );

		$date = trim( $late_date );
		$time = trim( $late_time );

		if ( '' === $date ) {
			$date = trim( $due_date );
			$time = trim( $due_time );
		}

		if ( '' === $date ) {
			return 0;
		}

		if ( '' === $time ) {
			$time = '23:59';
		}

		$ts = strtotime( $date . ' ' . $time );
		return $ts ? (int) $ts : 0;
	}

	/**
	 * Obtiene el ID de una entrega existente.
	 *
	 * @param int $user_id Usuario.
	 * @param int $lesson_id Lección.
	 * @return int
	 */
	protected function get_existing_submission_id( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return 0;
		}

		$submission_ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $user_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => '_clms_submission_lesson_id',
						'value' => $lesson_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		return ! empty( $submission_ids ) ? absint( $submission_ids[0] ) : 0;
	}

	/**
	 * Obtiene el ID de la entrega master del grupo para una lección.
	 *
	 * @param int $group_id
	 * @param int $lesson_id
	 * @return int
	 */
	protected function get_existing_group_master_submission_id( int $group_id, int $lesson_id ): int {
		$group_id  = absint( $group_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $group_id || ! $lesson_id ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_group_master',
						'value' => '1',
					),
					array(
						'key'   => '_clms_submission_group_id',
						'value' => $group_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => '_clms_submission_lesson_id',
						'value' => $lesson_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		return ! empty( $ids ) ? absint( $ids[0] ) : 0;
	}

	/**
	 * Sube archivos de una entrega.
	 *
	 * @param int   $submission_id Entrega.
	 * @param int   $user_id Usuario.
	 * @param array $files_data Files data.
	 * @return array|WP_Error
	 */
}
