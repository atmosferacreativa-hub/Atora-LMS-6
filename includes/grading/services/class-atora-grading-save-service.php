<?php
/**
 * Guardado de una calificación (6.31.0): la única puerta para SpeedGrader web y
 * para la app del docente (`POST /teacher/submissions/{id}/grade`).
 *
 * Extracción pura de `CLMS_Grading::handle_speedgrade_save()`: el cuerpo es el
 * mismo; solo lee de `$input` (ya sin barras) en lugar de `$_POST`, y usa el
 * `CLMS_Grading` recibido para permisos, contexto y nota del curso.
 *
 * `$input`: status, feedback, clms_sg_submit, rubric_scores[], rubric_feedback[],
 * grade, moderation_lock_version, moderation_comment.
 *
 * @package ATORA_LMS
 * @since 6.31.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATORA_Grading_Save_Service {

	/** @var CLMS_Grading */
	private $grading;

	public function __construct( $grading ) {
		$this->grading = $grading;
	}

	/**
	 * @param int   $submission_id Entrega (`clms_submission`).
	 * @param int   $actor_id      Quien califica.
	 * @param array $input         Datos del formulario o de la API, sin barras.
	 * @return array|WP_Error {submission_id, status, grade, feedback}
	 */
	public function save( int $submission_id, int $actor_id, array $input ) {
		$submission_id = absint( $submission_id );
		$user_id       = absint( $actor_id );

		if ( ! $submission_id || ! $user_id ) {
			return new WP_Error( 'invalid_request', __( 'Solicitud inválida.', 'atora-lms' ) );
		}

		if ( ! $this->grading->current_user_can_grade_submission( $submission_id, $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'No tienes permisos para revisar esta entrega.', 'atora-lms' ) );
		}

		$status_service = class_exists( 'CLMS_Grading_Status_Service' ) ? new CLMS_Grading_Status_Service() : null;
		$status_raw     = isset( $input['status'] ) ? $input['status'] : 'in_review';
		$status         = ( $status_service && method_exists( $status_service, 'normalize_status' ) )
			? $status_service->normalize_status( $status_raw )
			: sanitize_key( (string) $status_raw );
		$feedback_service = class_exists( 'CLMS_Grading_Feedback_Service' ) ? new CLMS_Grading_Feedback_Service() : null;
		$feedback         = isset( $input['feedback'] ) ? $input['feedback'] : '';
		$feedback         = ( $feedback_service && method_exists( $feedback_service, 'sanitize_feedback' ) )
			? $feedback_service->sanitize_feedback( $feedback )
			: wp_kses_post( (string) $feedback );
		$submit_action_raw = isset( $input['clms_sg_submit'] ) ? $input['clms_sg_submit'] : 'save_draft';
		$submit_action     = class_exists( 'CLMS_SpeedGrade_Actions' ) ? CLMS_SpeedGrade_Actions::normalize_submit_action( $submit_action_raw ) : sanitize_key( (string) $submit_action_raw );

		// Rubric: read per-criterion scores if available
		$lesson_id      = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$rubric_id      = ( $lesson_id && class_exists( '\ATORA\LMS\Rubric_Service' ) )
			? \ATORA\LMS\Rubric_Service::get_rubric_id_for_lesson( $lesson_id )
			: 0;
		$rubric_snapshot = array();
		$rubric_scores  = array();
		$grade_from_rubric = '';

		if ( $rubric_id && class_exists( 'CLMS_Rubric' ) ) {
			$criteria = array();
			$total_points = 0;
			$scale_type = '';
			$is_holistic = false;

			$rubric_row = class_exists( '\ATORA\LMS\Rubric_Service' ) ? \ATORA\LMS\Rubric_Service::get( $rubric_id ) : null;
			$rubric_revision = absint( is_array( $rubric_row ) ? ( $rubric_row['revision'] ?? 1 ) : 1 );

			// Preferir snapshot inmutable desde tabla de evaluaciones.
			if ( class_exists( '\ATORA\LMS\Rubric_Service' ) ) {
				$existing_eval = \ATORA\LMS\Rubric_Service::get_evaluation( $submission_id );
				if ( is_array( $existing_eval ) && absint( $existing_eval['rubric_id'] ?? 0 ) === $rubric_id ) {
					$decoded = json_decode( (string) ( $existing_eval['snapshot_json'] ?? '' ), true );
					if ( is_array( $decoded ) && isset( $decoded['rubric'] ) && is_array( $decoded['rubric'] ) ) {
						$rubric_snapshot = (array) $decoded['rubric'];
					}
				}
			}

			$has_snapshot = ! empty( $rubric_snapshot['rubric_id'] )
				&& $rubric_id === absint( $rubric_snapshot['rubric_id'] )
				&& ! empty( $rubric_snapshot['criteria'] )
				&& is_array( $rubric_snapshot['criteria'] );

			if ( $has_snapshot ) {
				$criteria     = (array) $rubric_snapshot['criteria'];
				$total_points = absint( $rubric_snapshot['total_points'] ?? 0 );
				$scale_type   = sanitize_key( (string) ( $rubric_snapshot['scale_type'] ?? '' ) );
				$is_holistic  = ! empty( $rubric_snapshot['is_holistic'] );
			} else {
				$criteria     = class_exists( '\ATORA\LMS\Rubric_Service' ) && is_array( $rubric_row )
					? \ATORA\LMS\Rubric_Service::get_criteria( $rubric_id, $rubric_revision )
					: CLMS_Rubric::get_criteria( $rubric_id );
				$total_points = is_array( $rubric_row ) ? absint( $rubric_row['total_points'] ?? 0 ) : absint( CLMS_Rubric::get_total_points( $rubric_id ) );
				$scale_type   = is_array( $rubric_row ) ? sanitize_key( (string) ( $rubric_row['scale_type'] ?? '' ) ) : '';
				$is_holistic  = is_array( $rubric_row ) ? ! empty( $rubric_row['is_holistic'] ) : false;

				// Snapshot por entrega: evita que cambios futuros en la rúbrica rompan la trazabilidad.
				if ( ! empty( $criteria ) ) {
					$rubric_snapshot = array(
						'rubric_id'     => $rubric_id,
						'rubric_title'  => (string) get_the_title( $rubric_id ),
						'scale_type'    => $scale_type,
						'is_holistic'   => $is_holistic ? 1 : 0,
						'total_points'  => $total_points,
						'rubric_revision' => $rubric_revision,
						'criteria'      => $criteria,
						'captured_at'   => current_time( 'mysql' ),
					);
				}
			}

			$raw_scores  = isset( $input['rubric_scores'] ) ? $input['rubric_scores'] : array();
			$raw_scores  = is_array( $raw_scores ) ? $raw_scores : array();
			$total_pts   = 0;
			$earned_pts  = 0.0;
			$total_weight = 0.0;
			$earned_weight = 0.0;
			$total_criteria = is_array( $criteria ) ? count( $criteria ) : 0;
			$scored_criteria = 0;
			$rubric_pct_ref = '';

			$score_precision = 2;
			foreach ( (array) $criteria as $i => $c ) {
				$max            = isset( $c['max_points'] ) ? absint( $c['max_points'] ) : 0;
				$weight         = isset( $c['weight'] ) ? (float) $c['weight'] : 0.0;
				$score_raw      = isset( $raw_scores[ $i ] ) ? trim( (string) $raw_scores[ $i ] ) : '';
				$score          = '';
				if ( '' !== $score_raw ) {
					$score_raw_norm = str_replace( ',', '.', $score_raw );
					if ( ! is_numeric( $score_raw_norm ) ) {
						return new \WP_Error( 'invalid_rubric_score', sprintf(
							/* translators: %s: criterion name */
							__( 'Puntaje inválido para el criterio "%s": debe ser un número.', 'atora-lms' ),
							sanitize_text_field( (string) ( $c['name'] ?? (string) $i ) )
						) );
					}
					$score_float = (float) $score_raw_norm;
					$decimals    = 0;
					$score_text  = (string) $score_raw_norm;
					if ( false !== strpos( $score_text, '.' ) ) {
						$parts    = explode( '.', $score_text, 2 );
						$decimals = strlen( preg_replace( '/\D+/', '', (string) ( $parts[1] ?? '' ) ) );
					}
					if ( $decimals > $score_precision ) {
						return new \WP_Error( 'invalid_rubric_score_precision', sprintf(
							/* translators: 1: criterion name, 2: decimals */
							__( 'Puntaje inválido para el criterio "%1$s": máximo %2$d decimales.', 'atora-lms' ),
							sanitize_text_field( (string) ( $c['name'] ?? (string) $i ) ),
							$score_precision
						) );
					}
					if ( $score_float < 0 || $score_float > (float) $max ) {
						return new \WP_Error( 'invalid_rubric_score_range', sprintf(
							/* translators: 1: criterion name, 2: max */
							__( 'Puntaje fuera de rango para el criterio "%1$s": debe estar entre 0 y %2$d.', 'atora-lms' ),
							sanitize_text_field( (string) ( $c['name'] ?? (string) $i ) ),
							$max
						) );
					}
					$score = round( $score_float, $score_precision );
				}
				$rubric_fb_raw  = isset( $input['rubric_feedback'][ $i ] ) ? $input['rubric_feedback'][ $i ] : '';
				$rubric_fb_safe = ( $feedback_service && method_exists( $feedback_service, 'sanitize_rubric_comment' ) )
					? $feedback_service->sanitize_rubric_comment( $rubric_fb_raw )
					: sanitize_textarea_field( (string) $rubric_fb_raw );

				$total_pts  += $max;
				$total_weight += max( 0.0, min( 100.0, $weight ) );
				if ( '' !== (string) $score ) {
					$earned_pts += (float) $score;
					if ( $max > 0 ) {
						$earned_weight += ( (float) $score / (float) $max ) * max( 0.0, min( 100.0, $weight ) );
					}
					++$scored_criteria;
				}
				if ( '' !== (string) $score || '' !== trim( (string) $rubric_fb_safe ) ) {
					$rubric_scores[ $i ] = array(
						'name'            => isset( $c['name'] ) ? $c['name'] : '',
						'competency'      => isset( $c['competency'] ) ? sanitize_text_field( (string) $c['competency'] ) : '',
						'improvement_tip' => isset( $c['improvement_tip'] ) ? sanitize_textarea_field( (string) $c['improvement_tip'] ) : '',
						'max_points'      => $max,
						'score'           => $score,
						'feedback'        => $rubric_fb_safe,
					);
				}
			}

			if ( $total_pts > 0 && $scored_criteria > 0 ) {
				$rubric_pct_ref = (string) (int) round( ( $earned_pts / (float) $total_pts ) * 100 );
			}
		}

		// Manual override grade field (blank = derive from rubric, or leave empty)
		$grade_raw = isset( $input['grade'] ) ? trim( (string) $input['grade'] ) : '';
		if ( '' !== $grade_raw ) {
			if ( ! is_numeric( $grade_raw ) ) {
				return new WP_Error( 'invalid_grade', __( 'La nota debe ser numérica.', 'atora-lms' ) );
			}
			$grade = max( 0, min( 100, (int) round( (float) $grade_raw ) ) );
		} else {
			$grade = '';
		}

		$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		if ( ! $student_id ) {
			$student_id = absint( get_post_meta( $submission_id, '_clms_submission_student_id', true ) );
		}
		if ( ! $student_id ) {
			$student_id = absint( get_post_field( 'post_author', $submission_id ) );
		}
		$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		if ( ! $course_id && $lesson_id && class_exists( 'CLMS_Helper' ) ) {
			$course_id = absint( CLMS_Helper::get_lesson_course_id( $lesson_id ) );
		}
		$moderation_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_SpeedGrade_Moderation_Service') : null;
		$moderation_context = ( $moderation_service && method_exists( $moderation_service, 'get_context' ) )
			? (array) $moderation_service->get_context( $submission_id, $course_id, $user_id )
			: array( 'institutional' => false, 'status' => 'none' );
		if (
			! CLMS_SpeedGrade_Moderation_Policy::direct_publish_allowed( ! empty( $moderation_context['institutional'] ), $moderation_context['status'] ?? 'none' )
			&& in_array( $submit_action, array( 'publish', 'approve_evidence' ), true )
		) {
			return new WP_Error( 'clms_moderation_required', __( 'Esta calificación pertenece a un ciclo institucional y debe aprobarse mediante moderación.', 'atora-lms' ) );
		}

		if ( ! in_array( $status, array( 'submitted', 'in_review', 'graded', 'needs_revision', 'returned' ), true ) ) {
			$status = 'in_review';
		}

		if ( 'save_draft' === $submit_action || 'save_next' === $submit_action ) {
			$status = 'in_review';
		} elseif ( 'publish' === $submit_action ) {
			$status = 'graded';
		} elseif ( 'return_revision' === $submit_action ) {
			$status = 'needs_revision';
		} elseif ( 'approve_evidence' === $submit_action || 'approve_moderation' === $submit_action ) {
			$status = 'graded';
		} elseif ( 'submit_moderation' === $submit_action ) {
			$status = 'in_review';
		} elseif ( 'request_moderation_changes' === $submit_action ) {
			$status = 'in_review';
		}

		if ( 'accept_ai_draft' === $submit_action ) {
			$ai_grade    = get_post_meta( $submission_id, '_clms_ai_review_suggested_grade', true );
			$ai_feedback = (string) get_post_meta( $submission_id, '_clms_ai_review_feedback_draft', true );
			if ( '' !== (string) $ai_grade && is_numeric( $ai_grade ) ) {
				$grade = max( 0, min( 100, absint( round( (float) $ai_grade ) ) ) );
			}
			if ( '' !== trim( $ai_feedback ) ) {
				$feedback = sanitize_textarea_field( $ai_feedback );
			}
			if ( 'submitted' === $status ) {
				$status = 'in_review';
			}
		}

		if ( 'insert_plan' === $submit_action ) {
			$plan_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Improvement_Plan_Service') : null;
			$plan_data    = ( $plan_service && method_exists( $plan_service, 'build_from_submission' ) ) ? (array) $plan_service->build_from_submission( $submission_id ) : array();
			$plan_line    = '';
			if ( ! empty( $plan_data['next_action'] ) ) {
				$plan_line = sanitize_text_field( (string) $plan_data['next_action'] );
			} elseif ( ! empty( $plan_data['recommendation'] ) ) {
				$plan_line = sanitize_text_field( (string) $plan_data['recommendation'] );
			} elseif ( ! empty( $plan_data['recommendations'][0] ) ) {
				$plan_line = sanitize_text_field( (string) $plan_data['recommendations'][0] );
			}
			if ( '' !== $plan_line ) {
				$append = sprintf(
					/* translators: %s: acción de mejora */
					__( 'Plan de mejora sugerido: %s', 'atora-lms' ),
					$plan_line
				);
				if ( false === strpos( wp_strip_all_tags( (string) $feedback ), $append ) ) {
					$feedback = trim( (string) $feedback );
					$feedback = '' !== $feedback ? $feedback . "\n\n" . $append : $append;
				}
			}
			if ( 'submitted' === $status ) {
				$status = 'in_review';
			}
		}

		if ( 'insert_competency_recommendation' === $submit_action ) {
			$comp_line = '';
			$context_for_comp = (array) $this->grading->get_submission_context( $submission_id, $user_id );
			$focus_items = isset( $context_for_comp['competency_focus'] ) && is_array( $context_for_comp['competency_focus'] )
				? $context_for_comp['competency_focus']
				: array();

			if ( ! empty( $focus_items[0] ) && is_array( $focus_items[0] ) ) {
				$comp_title = sanitize_text_field( (string) ( $focus_items[0]['title'] ?? '' ) );
				$comp_rec   = sanitize_text_field( (string) ( $focus_items[0]['recommendation'] ?? '' ) );
				if ( '' !== $comp_title && '' !== $comp_rec ) {
					$comp_line = sprintf(
						/* translators: 1: competencia, 2: recomendación */
						__( 'Recomendación por competencia (%1$s): %2$s', 'atora-lms' ),
						$comp_title,
						$comp_rec
					);
				} elseif ( '' !== $comp_title ) {
					$comp_line = sprintf(
						/* translators: %s: competencia */
						__( 'Recomendación por competencia: refuerza %s con una nueva práctica guiada.', 'atora-lms' ),
						$comp_title
					);
				}
			}

			if ( '' === $comp_line ) {
				$comp_line = __( 'Recomendación por competencia: fortalece el criterio más débil con práctica específica antes de la próxima entrega.', 'atora-lms' );
			}

			if ( false === strpos( wp_strip_all_tags( (string) $feedback ), $comp_line ) ) {
				$feedback = trim( (string) $feedback );
				$feedback = '' !== $feedback ? $feedback . "\n\n" . $comp_line : $comp_line;
			}

			if ( 'submitted' === $status ) {
				$status = 'in_review';
			}
		}

		if ( 'approve_evidence' === $submit_action ) {
			$minimum_grade = 0;
			$evidence_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
			if ( $evidence_service && method_exists( $evidence_service, 'get_activity_evidence_config' ) ) {
				$evidence_config = (array) $evidence_service->get_activity_evidence_config( $lesson_id );
				$minimum_grade   = absint( $evidence_config['minimum_grade'] ?? 0 );
			}
			if ( $minimum_grade <= 0 ) {
				$minimum_grade = 70;
			}
			if ( '' === (string) $grade || ! is_numeric( $grade ) ) {
				$grade = $minimum_grade;
			} else {
				$grade = max( absint( $grade ), $minimum_grade );
			}

			$approval_line = __( 'Evidencia obligatoria marcada como aprobada.', 'atora-lms' );
			if ( false === strpos( wp_strip_all_tags( (string) $feedback ), $approval_line ) ) {
				$feedback = trim( (string) $feedback );
				$feedback = '' !== $feedback ? $feedback . "\n\n" . $approval_line : $approval_line;
			}
		}

		if ( '' !== $grade && 'submitted' === $status ) {
			$status = 'graded';
		}

		// 6.31.0: concurrencia. Quien envía la revisión que vio solo guarda si nadie
		// guardó después (comparar y sumar en una sola consulta); si no, 409.
		$revision = self::claim_revision( $submission_id, isset( $input['expected_revision'] ) && '' !== (string) $input['expected_revision'] ? absint( $input['expected_revision'] ) : null );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}

		// 6.31.0: intento calificado (por defecto, el último que se mostró).
		$graded_attempt = isset( $input['attempt'] ) ? absint( $input['attempt'] ) : 0;
		if ( $graded_attempt > 0 ) {
			update_post_meta( $submission_id, '_clms_submission_graded_attempt', $graded_attempt );
		}

		if ( in_array( $submit_action, array( 'submit_moderation', 'approve_moderation', 'request_moderation_changes' ), true ) ) {
			if ( ! $moderation_service ) {
				return new WP_Error( 'clms_moderation_unavailable', __( 'El servicio de moderación no está disponible.', 'atora-lms' ) );
			}
			$expected_lock = isset( $input['moderation_lock_version'] ) ? absint( $input['moderation_lock_version'] ) : 0;
			if ( 'submit_moderation' === $submit_action ) {
				$moderation_result = $moderation_service->submit( $submission_id, $course_id, $student_id, $grade, $rubric_scores, $feedback, $user_id, $expected_lock );
			} else {
				$decision = 'approve_moderation' === $submit_action ? 'approved' : 'changes_requested';
				$moderation_comment = isset( $input['moderation_comment'] ) ? sanitize_textarea_field( $input['moderation_comment'] ) : '';
				$moderation_result = $moderation_service->decide( $submission_id, $course_id, $decision, $grade, $rubric_scores, $moderation_comment, $user_id, $expected_lock );
				if ( ! is_wp_error( $moderation_result ) && 'approved' === $decision ) {
					$grade = $moderation_result['grade'];
				}
			}
			if ( is_wp_error( $moderation_result ) ) {
				return $moderation_result;
			}
		}

		$assessment_engine = clms_core('CLMS_Assessment_Engine');
		$grade_source      = 'manual';
		if ( 'accept_ai_draft' === $submit_action ) {
			$grade_source = 'ai_assisted';
		}
		if ( $assessment_engine && method_exists( $assessment_engine, 'publish_submission_grade' ) ) {
			$result = $assessment_engine->publish_submission_grade(
				$submission_id,
				array(
					'grade'         => $grade,
					'status'        => $status,
					'feedback'      => $feedback,
					'source'        => $grade_source,
					'rubric_scores' => $rubric_scores,
					'audit_payload' => array(
						'trigger'       => 'speedgrade',
						'evaluation_mode' => 'accept_ai_draft' === $submit_action ? 'ai_assisted' : 'manual',
						'activity_type' => 'tarea',
					),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} else {
			update_post_meta( $submission_id, '_clms_submission_status', $status );
			update_post_meta( $submission_id, '_clms_submission_feedback', $feedback );
			if ( ! empty( $rubric_scores ) ) {
				update_post_meta( $submission_id, '_clms_submission_rubric_scores', $rubric_scores );
			} else {
				delete_post_meta( $submission_id, '_clms_submission_rubric_scores' );
			}
			if ( '' !== $grade ) {
				update_post_meta( $submission_id, '_clms_submission_grade', $grade );
			} else {
				delete_post_meta( $submission_id, '_clms_submission_grade' );
			}
			$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
			if ( ! $student_id ) {
				$student_id = absint( get_post_meta( $submission_id, '_clms_submission_student_id', true ) );
			}
			if ( ! $student_id ) {
				$student_id = absint( get_post_field( 'post_author', $submission_id ) );
			}

			$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
			if ( ! $course_id && $lesson_id && class_exists( 'CLMS_Helper' ) ) {
				$course_id = absint( CLMS_Helper::get_lesson_course_id( $lesson_id ) );
			}
			if ( $student_id && $course_id && method_exists( $this->grading, 'calculate_and_store_course_grade' ) ) {
				$this->grading->calculate_and_store_course_grade( $student_id, $course_id );
			}

			$grading_engine = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading_Engine') : null;
			if ( $grading_engine && method_exists( $grading_engine, 'invalidate_grade_cache' ) && $student_id && $course_id ) {
				$grading_engine->invalidate_grade_cache( $student_id, $course_id );
			}

			do_action( CLMS_Student_Grade_Visibility::grade_saved_hook( $status ), $submission_id, $student_id > 0 ? $student_id : $user_id, $status, $grade, $feedback );
		}

		// 6.26.5: registrar evaluación inmutable en tabla (atora_rubric_evaluations).
		if ( $rubric_id > 0 && ! empty( $rubric_snapshot ) && class_exists( '\ATORA\LMS\Rubric_Service' ) ) {
			$student_id_eval = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
			if ( ! $student_id_eval ) {
				$student_id_eval = absint( get_post_meta( $submission_id, '_clms_submission_student_id', true ) );
			}
			if ( ! $student_id_eval ) {
				$student_id_eval = absint( get_post_field( 'post_author', $submission_id ) );
			}

			$institution_id = absint( (int) get_option( 'atora_default_institution', 0 ) );
			if ( class_exists( '\ATORA\LMS\Tenant_Context' ) ) {
				$inst = \ATORA\LMS\Tenant_Context::require_current_institution_id();
				if ( ! is_wp_error( $inst ) ) {
					$institution_id = absint( $inst );
				}
			}

			$grader_id = absint( get_current_user_id() );
			$on_behalf_of = 0;
			if ( class_exists( 'ATORA_Delegation_Service' ) && $lesson_id > 0 && ATORA_Delegation_Service::covers( $grader_id, $lesson_id, 'grade' ) ) {
				$on_behalf_of = absint( (int) get_post_field( 'post_author', $lesson_id ) );
			}

			$earned_points = 0.0;
			foreach ( (array) $rubric_scores as $row ) {
				$row = is_array( $row ) ? $row : array();
				if ( '' !== (string) ( $row['score'] ?? '' ) ) {
					$earned_points += (float) $row['score'];
				}
			}

			$payload = array(
				'rubric'   => $rubric_snapshot,
				'scores'   => $rubric_scores,
				'grade'    => $grade,
				'grade_manual' => '' !== (string) $grade_raw,
				'status'   => $status,
				'feedback' => $feedback,
				'rubric_total_points' => $earned_points,
				'rubric_max_points'   => absint( $rubric_snapshot['total_points'] ?? 0 ),
				'rubric_percent'      => ( '' !== (string) $rubric_pct_ref ) ? absint( $rubric_pct_ref ) : '',
				'attempt'             => $graded_attempt,
			);

			\ATORA\LMS\Rubric_Service::record_evaluation( array(
				'institution_id'   => $institution_id,
				'submission_id'    => 0,
				'wp_submission_id' => $submission_id,
				'student_id'       => $student_id_eval,
				'rubric_id'        => $rubric_id,
				'rubric_revision'  => absint( $rubric_snapshot['rubric_revision'] ?? 1 ),
				'total_points'     => absint( $rubric_snapshot['total_points'] ?? 0 ),
				'earned_points'    => absint( (int) round( $earned_points ) ),
				'scale_type'       => sanitize_key( (string) ( $rubric_snapshot['scale_type'] ?? '' ) ),
				'scale_code'       => sanitize_key( (string) ( $rubric_snapshot['scale_code'] ?? '' ) ),
				'source'           => 'speedgrader',
				'grader_id'        => $grader_id,
				'on_behalf_of'     => $on_behalf_of,
				'ai_assisted'      => 'accept_ai_draft' === $submit_action,
				'snapshot_json'    => wp_json_encode( $payload ),
			) );
		}

		return array(
			'submission_id' => $submission_id,
			'status'        => $status,
			'grade'         => $grade,
			'feedback'      => $feedback,
			'revision'      => $revision,
			'attempt'       => $graded_attempt,
		);
	}

	const REVISION_META = '_clms_submission_grade_revision';

	/** Revisión de calificación actual de una entrega (0 si nunca se guardó). */
	public static function revision( int $submission_id ): int {
		return absint( get_post_meta( $submission_id, self::REVISION_META, true ) );
	}

	/**
	 * Sube la revisión. Con `$expected`, solo si sigue siendo esa (atómico).
	 *
	 * @return int|WP_Error Nueva revisión, o 409 con la revisión actual.
	 */
	public static function claim_revision( int $submission_id, ?int $expected ) {
		global $wpdb;
		if ( null === $expected ) {
			// Sin revisión esperada (formularios anteriores): solo se cuenta el guardado.
			$next = self::revision( $submission_id ) + 1;
			update_post_meta( $submission_id, self::REVISION_META, (string) $next );
			return $next;
		}
		add_post_meta( $submission_id, self::REVISION_META, '0', true );
		$where   = $wpdb->prepare( 'post_id = %d AND meta_key = %s AND CAST(meta_value AS UNSIGNED) = %d', $submission_id, self::REVISION_META, $expected );
		$updated = $wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE {$where}" ); // phpcs:ignore WordPress.DB
		wp_cache_delete( $submission_id, 'post_meta' );
		if ( false === $updated ) {
			return new WP_Error( 'atora_db_error', __( 'No se pudo guardar la calificación. Intenta de nuevo.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$current = self::revision( $submission_id );
		if ( 0 === (int) $updated ) {
			return new WP_Error(
				'atora_grade_revision_conflict',
				__( 'Otro docente guardó esta entrega; recarga para ver su versión.', 'atora-lms' ),
				array( 'status' => 409, 'current_revision' => $current )
			);
		}
		return $current;
	}
}
