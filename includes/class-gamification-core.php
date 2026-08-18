<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Gamification_Core {

	const META_SUMMARY    = '_clms_gamification_summary';
	const META_LEDGER     = '_clms_gamification_ledger';
	const META_EVENT_KEYS = '_clms_gamification_event_keys';

	const MAX_LEDGER_ENTRIES = 200;
	const MAX_EVENT_KEYS     = 400;

	public function __construct() {
		add_action( 'clms_lesson_completed', array( $this, 'on_lesson_completed' ), 10, 2 );
		add_action( 'clms_submission_saved', array( $this, 'on_submission_saved' ), 10, 2 );
		add_action( 'clms_submission_graded', array( $this, 'on_submission_graded' ), 10, 5 );
		add_action( 'clms_quiz_submitted', array( $this, 'on_quiz_submitted' ), 10, 3 );
		add_action( 'clms_course_completed', array( $this, 'on_course_completed' ), 10, 2 );
		add_action( 'clms_program_completed', array( $this, 'on_program_completed' ), 10, 2 );
		add_action( 'clms_feedback_loop_processed', array( $this, 'on_feedback_loop_processed' ), 10, 3 );
		add_action( 'clms_certificate_issued', array( $this, 'on_certificate_issued' ), 10, 3 );
		add_action( 'clms_peer_review_quality_scored', array( $this, 'on_peer_review_quality_scored' ), 10, 3 );
	}

	/**
	 * Listener: entrega enviada/actualizada.
	 *
	 * @param int $submission_id Entrega.
	 * @param int $user_id       Estudiante.
	 * @return void
	 */
	public function on_submission_saved( $submission_id, $user_id = 0 ) {
		$submission_id = absint( $submission_id );
		$user_id       = absint( $user_id );

		if ( ! $submission_id ) {
			return;
		}

		if ( ! $user_id ) {
			$user_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		}

		if ( ! $user_id ) {
			return;
		}

		$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );

		$this->record_event(
			'submission_sent',
			array(
				'user_id'       => $user_id,
				'submission_id' => $submission_id,
				'lesson_id'     => $lesson_id,
				'course_id'     => $course_id,
				'occurred_on'   => current_time( 'Y-m-d' ),
			)
		);
	}

	/**
	 * Listener: lección completada.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $lesson_id Lección.
	 * @return void
	 */
	public function on_lesson_completed( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return;
		}

		$course_id = 0;
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_id_from_lesson' ) ) {
			$course_id = absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) );
		}

		$this->record_event(
			'lesson_completed',
			array(
				'user_id'     => $user_id,
				'lesson_id'   => $lesson_id,
				'course_id'   => $course_id,
				'occurred_on' => current_time( 'Y-m-d' ),
			)
		);
	}

	/**
	 * Listener: entrega calificada.
	 *
	 * @param int        $submission_id Entrega.
	 * @param int|string $actor_id      Revisor (no usado).
	 * @param string     $status        Estado.
	 * @param mixed      $grade         Nota.
	 * @param string     $feedback      Feedback (no usado).
	 * @return void
	 */
	public function on_submission_graded( $submission_id, $actor_id, $status = '', $grade = '', $feedback = '' ) {
		unset( $actor_id, $feedback );

		$submission_id = absint( $submission_id );
		$status        = sanitize_key( (string) $status );

		if ( ! $submission_id || 'graded' !== $status ) {
			return;
		}

		$user_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		if ( ! $user_id ) {
			return;
		}

		if ( '' === (string) $grade ) {
			$grade = get_post_meta( $submission_id, '_clms_submission_grade', true );
		}

		if ( '' === (string) $grade || ! is_numeric( $grade ) ) {
			return;
		}

		$grade = max( 0, min( 100, (float) $grade ) );
		if ( $grade < $this->get_evaluation_pass_threshold() ) {
			return;
		}

		$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );

		if ( ! $course_id && $lesson_id && class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_id_from_lesson' ) ) {
			$course_id = absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) );
		}

		$this->record_event(
			'evaluation_passed',
			array(
				'user_id'       => $user_id,
				'submission_id' => $submission_id,
				'lesson_id'     => $lesson_id,
				'course_id'     => $course_id,
				'grade'         => $grade,
				'status'        => $status,
				'occurred_on'   => current_time( 'Y-m-d' ),
			)
		);
	}

	/**
	 * Listener: quiz enviado.
	 *
	 * @param int   $user_id   Usuario.
	 * @param int   $lesson_id Lección.
	 * @param array $result    Resultado del quiz.
	 * @return void
	 */
	public function on_quiz_submitted( $user_id, $lesson_id, $result = array() ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );
		$result    = is_array( $result ) ? $result : array();

		if ( ! $user_id || ! $lesson_id ) {
			return;
		}

		$score = isset( $result['score'] ) && is_numeric( $result['score'] )
			? max( 0, min( 100, (float) $result['score'] ) )
			: 0;

		$passing_score = absint( get_post_meta( $lesson_id, '_clms_quiz_passing_score', true ) );
		if ( $passing_score <= 0 ) {
			$passing_score = $this->get_evaluation_pass_threshold();
		}
		$passing_score = max( 0, min( 100, $passing_score ) );

		if ( $score < $passing_score ) {
			return;
		}

		$course_id = 0;
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_id_from_lesson' ) ) {
			$course_id = absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) );
		}

		$this->record_event(
			'evaluation_passed',
			array(
				'user_id'       => $user_id,
				'lesson_id'     => $lesson_id,
				'course_id'     => $course_id,
				'grade'         => $score,
				'passing_score' => $passing_score,
				'status'        => 'quiz_passed',
				'occurred_on'   => current_time( 'Y-m-d' ),
			)
		);
	}

	public function on_course_completed( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || ! $course_id ) {
			return;
		}

		$this->record_event(
			'course_completed',
			array(
				'user_id'     => $user_id,
				'course_id'   => $course_id,
				'occurred_on' => current_time( 'Y-m-d' ),
			)
		);
	}

	public function on_program_completed( $user_id, $program_id ) {
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );
		if ( ! $user_id || ! $program_id ) {
			return;
		}

		$this->record_event(
			'program_completed',
			array(
				'user_id'     => $user_id,
				'program_id'  => $program_id,
				'occurred_on' => current_time( 'Y-m-d' ),
			)
		);
	}

	public function on_feedback_loop_processed( $result = array(), $student_id = 0, $course_id = 0 ) {
		// Compatibilidad: payload moderno (result, student_id, course_id)
		// y fallback legacy donde el primer argumento era student_id.
		if ( is_array( $result ) ) {
			$tracking   = $result;
			$student_id = $student_id ? absint( $student_id ) : absint( $tracking['student_id'] ?? 0 );
			$course_id  = $course_id ? absint( $course_id ) : absint( $tracking['course_id'] ?? 0 );
		} else {
			$tracking = array();
			if ( is_array( $course_id ) ) {
				$tracking  = $course_id;
				$course_id = absint( $student_id );
			}
			$student_id = absint( $result );
			$course_id  = absint( $course_id );
		}

		$student_id = absint( $student_id );
		$course_id  = absint( $course_id );
		$tracking   = is_array( $tracking ) ? $tracking : array();

		if ( ! $student_id ) {
			return;
		}

		$submission_id = isset( $tracking['submission_id'] ) ? absint( $tracking['submission_id'] ) : 0;
		$tracking_id   = isset( $tracking['tracking_id'] ) ? sanitize_text_field( (string) $tracking['tracking_id'] ) : '';

		$this->record_event(
			'feedback_received',
			array(
				'user_id'       => $student_id,
				'course_id'     => $course_id,
				'submission_id' => $submission_id,
				'tracking_id'   => $tracking_id,
				'occurred_on'   => current_time( 'Y-m-d' ),
			)
		);
	}

	public function on_certificate_issued( $record, $user_id, $course_id ) {
		$record    = is_array( $record ) ? $record : array();
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$target_type = isset( $record['target_type'] ) ? sanitize_key( (string) $record['target_type'] ) : 'course';
		$target_id   = isset( $record['target_id'] ) ? absint( $record['target_id'] ) : 0;

		if ( ! $target_id ) {
			$target_id = 'program' === $target_type ? absint( $record['program_id'] ?? 0 ) : $course_id;
		}

		if ( ! $user_id || ! $target_id ) {
			return;
		}

		$this->record_event(
			'certificate_issued',
			array(
				'user_id'      => $user_id,
				'course_id'    => 'course' === $target_type ? $target_id : 0,
				'program_id'   => 'program' === $target_type ? $target_id : 0,
				'target_type'  => $target_type,
				'target_id'    => $target_id,
				'occurred_on'  => current_time( 'Y-m-d' ),
			)
		);
	}

	/**
	 * Listener: calidad de revisión entre pares.
	 *
	 * @param int   $assignment_id Asignación de revisión.
	 * @param array $quality       Calidad calculada.
	 * @param int   $submission_id Entrega revisada.
	 * @return void
	 */
	public function on_peer_review_quality_scored( $assignment_id, $quality = array(), $submission_id = 0 ) {
		$assignment_id = absint( $assignment_id );
		$submission_id = absint( $submission_id );
		$quality       = is_array( $quality ) ? $quality : array();

		if ( ! $assignment_id ) {
			return;
		}

		$reviewer_id = isset( $quality['reviewer_id'] ) ? absint( $quality['reviewer_id'] ) : 0;
		if ( ! $reviewer_id ) {
			$reviewer_id = absint( get_post_meta( $assignment_id, '_clms_pr_reviewer_id', true ) );
		}
		if ( ! $reviewer_id ) {
			return;
		}

		$score  = isset( $quality['score'] ) && is_numeric( $quality['score'] ) ? max( 0, min( 100, (float) $quality['score'] ) ) : 0;
		$status = isset( $quality['status'] ) ? sanitize_key( (string) $quality['status'] ) : 'low';
		if ( 'high' !== $status && $score < 60 ) {
			return;
		}

		$lesson_id = absint( get_post_meta( $assignment_id, '_clms_pr_lesson_id', true ) );
		$course_id = 0;
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_id_from_lesson' ) ) {
			$course_id = absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) );
		}

		$this->record_event(
			'peer_review_quality',
			array(
				'user_id'        => $reviewer_id,
				'assignment_id'  => $assignment_id,
				'submission_id'  => $submission_id,
				'lesson_id'      => $lesson_id,
				'course_id'      => $course_id,
				'quality_score'  => $score,
				'quality_status' => $status,
				'occurred_on'    => current_time( 'Y-m-d' ),
			)
		);
	}

	/**
	 * Registra un evento gamificado con idempotencia por usuario.
	 *
	 * @param string $event_type Tipo de evento.
	 * @param array  $context    Contexto.
	 * @return array<string,mixed>
	 */
	public function record_event( $event_type, $context = array() ) {
		$event_type = sanitize_key( (string) $event_type );
		$context    = is_array( $context ) ? $context : array();
		$user_id    = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		$result = array(
			'applied'       => false,
			'event_type'    => $event_type,
			'event_key'     => '',
			'reason'        => '',
			'points_awarded'=> 0,
		);

		if ( ! $user_id || ! $event_type ) {
			$result['reason'] = 'invalid_context';
			return $result;
		}

		$rules = $this->get_rules();
		if ( ! isset( $rules[ $event_type ] ) || ! is_array( $rules[ $event_type ] ) ) {
			$result['reason'] = 'unsupported_event';
			return $result;
		}

		$rule = $rules[ $event_type ];
		if ( empty( $rule['enabled'] ) ) {
			$result['reason'] = 'event_disabled';
			return $result;
		}

		$event_key = $this->build_event_key( $event_type, $context );
		if ( '' === $event_key ) {
			$result['reason'] = 'missing_event_key';
			return $result;
		}

		$result['event_key'] = $event_key;

		if ( $this->has_processed_event( $user_id, $event_key ) ) {
			$result['reason'] = 'duplicate_event';
			return $result;
		}

		$points  = max( 0, absint( $rule['points'] ?? 0 ) );
		$summary = $this->get_user_summary( $user_id );

		$summary['points'] = max( 0, absint( $summary['points'] ) + $points );
		$summary['events_total'] = max( 0, absint( $summary['events_total'] ) + 1 );

		$summary = $this->update_streak( $summary, $context );

		switch ( $event_type ) {
			case 'lesson_completed':
				$summary['lessons_completed'] = max( 0, absint( $summary['lessons_completed'] ) + 1 );
				if ( 1 === $summary['lessons_completed'] ) {
					$summary['milestones'] = $this->append_unique_slug( $summary['milestones'], 'primera_leccion_completada' );
					$summary['badges']     = $this->append_unique_slug( $summary['badges'], 'primer_paso' );
				}
				break;

			case 'submission_sent':
				$summary['badges'] = $this->append_unique_slug( $summary['badges'], 'entrega_enviada' );
				break;

			case 'evaluation_passed':
				$summary['evaluations_passed'] = max( 0, absint( $summary['evaluations_passed'] ) + 1 );
				if ( 1 === $summary['evaluations_passed'] ) {
					$summary['milestones'] = $this->append_unique_slug( $summary['milestones'], 'primera_evaluacion_aprobada' );
				}
				$summary['badges'] = $this->append_unique_slug( $summary['badges'], 'evaluacion_aprobada' );
				break;

			case 'feedback_received':
				$summary['badges'] = $this->append_unique_slug( $summary['badges'], 'mejora_continua' );
				break;

			case 'course_completed':
				$summary['milestones'] = $this->append_unique_slug( $summary['milestones'], 'curso_completado' );
				$summary['badges']     = $this->append_unique_slug( $summary['badges'], 'curso_completado' );
				break;

			case 'program_completed':
				$summary['milestones'] = $this->append_unique_slug( $summary['milestones'], 'programa_completado' );
				$summary['badges']     = $this->append_unique_slug( $summary['badges'], 'programa_completado' );
				break;

			case 'certificate_issued':
				$summary['milestones'] = $this->append_unique_slug( $summary['milestones'], 'certificado_emitido' );
				$summary['badges']     = $this->append_unique_slug( $summary['badges'], 'certificado_emitido' );
				break;

			case 'peer_review_quality':
				$summary['peer_reviews_quality'] = max( 0, absint( $summary['peer_reviews_quality'] ?? 0 ) + 1 );
				$quality_score  = isset( $context['quality_score'] ) && is_numeric( $context['quality_score'] ) ? (float) $context['quality_score'] : 0;
				$quality_status = isset( $context['quality_status'] ) ? sanitize_key( (string) $context['quality_status'] ) : '';
				if ( 'high' === $quality_status || $quality_score >= 90 ) {
					$summary['badges'] = $this->append_unique_slug( $summary['badges'], 'revisor_confiable' );
				}
				if ( $quality_score >= 75 ) {
					$summary['badges'] = $this->append_unique_slug( $summary['badges'], 'feedback_util' );
				}
				if ( $quality_score >= 60 ) {
					$summary['badges'] = $this->append_unique_slug( $summary['badges'], 'evaluador_colaborativo' );
				}
				break;
		}

		$summary = $this->update_level_and_unlocks( $summary );
		$summary['updated_at'] = current_time( 'mysql' );

		update_user_meta( $user_id, self::META_SUMMARY, $summary );
		$this->mark_event_as_processed( $user_id, $event_key );

		$this->append_ledger_entry(
			$user_id,
			array(
				'event'         => $event_type,
				'event_key'     => $event_key,
				'points'        => $points,
				'points_total'  => absint( $summary['points'] ),
				'recorded_at'   => current_time( 'mysql' ),
				'context'       => $this->sanitize_ledger_context( $context ),
			)
		);

		$result['applied']        = true;
		$result['points_awarded'] = $points;
		$result['summary']        = $summary;

		// ── Fase III S9: Verificar criterios de badge ─────────────────────────
		$this->check_badge_criteria( $user_id, $event_type, $summary );

		return $result;
	}

	/**
	 * Verifica si se cumplen criterios de badge tras un evento y dispara el action.
	 * Se añade al final de record_event() sin modificar la lógica existente.
	 *
	 * @param int    $user_id    Usuario.
	 * @param string $event_type Tipo de evento registrado.
	 * @param array  $summary    Summary actualizado del usuario.
	 */
	private function check_badge_criteria( int $user_id, string $event_type, array $summary ): void {
		global $wpdb;

		$badges_table = $wpdb->prefix . 'clms_badges';
		$like         = $wpdb->esc_like( $badges_table );
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) !== $badges_table ) {
			return;
		}

		// Badges por evento (criterio = event_type)
		$candidates = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT slug, criterio, threshold FROM {$badges_table}
				 WHERE is_active = 1 AND (criterio = %s OR criterio = 'points_milestone')",
				$event_type
			),
			ARRAY_A
		);

		$earned_badges = (array) ( $summary['badges'] ?? array() );

		foreach ( $candidates as $badge ) {
			$slug      = sanitize_key( (string) ( $badge['slug'] ?? '' ) );
			$criterio  = sanitize_key( (string) ( $badge['criterio'] ?? '' ) );
			$threshold = absint( $badge['threshold'] ?? 1 );

			if ( '' === $slug || in_array( $slug, $earned_badges, true ) ) { continue; }

			$earned = false;

			if ( 'points_milestone' === $criterio ) {
				$earned = absint( $summary['points'] ?? 0 ) >= $threshold;
			} elseif ( $criterio === $event_type ) {
				// Contar eventos de este tipo desde el ledger
				$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}atora_contacts WHERE 1=1 AND 0=1"
						// Fallback: usar summary data
					)
				);
				// Usar datos de summary en lugar de tabla de eventos
				$map = array(
					'lesson_completed' => 'lessons_completed',
					'course_completed' => 'events_total', // aproximación
					'submission_sent'  => 'events_total',
					'evaluation_passed'=> 'evaluations_passed',
					'course_enrolled'  => 'events_total',
					'daily_login'      => 'streak_days',
					'peer_review_done' => 'peer_reviews_quality',
				);
				$summary_key = $map[ $criterio ] ?? '';
				$count       = $summary_key ? absint( $summary[ $summary_key ] ?? 0 ) : 0;
				$earned      = $count >= $threshold;
			}

			if ( $earned ) {
				// Añadir al summary y persistir
				$updated_summary           = $this->get_user_summary( $user_id );
				$updated_summary['badges'] = $this->append_unique_slug( $updated_summary['badges'], $slug );
				update_user_meta( $user_id, self::META_SUMMARY, $updated_summary );

				/**
				 * Fires cuando un usuario gana un badge (Fase III S9).
				 *
				 * @param int    $user_id    ID del usuario.
				 * @param string $badge_slug Slug del badge ganado.
				 */
				do_action( 'atora/gamification/badge_earned', $user_id, $slug );
			}
		}
	}

	/**
	 * Resumen gamificado del usuario.
	 *
	 * @param int $user_id Usuario.
	 * @return array<string,mixed>
	 */
	public function get_user_summary( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return $this->get_default_summary();
		}

		$stored = get_user_meta( $user_id, self::META_SUMMARY, true );
		$stored = is_array( $stored ) ? $stored : array();

		return $this->normalize_summary( array_merge( $this->get_default_summary(), $stored ) );
	}

	/**
	 * Reglas base del núcleo de gamificación.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function get_rules() {
		$rules_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Gamification_Rules') : null;
		if ( $rules_service && method_exists( $rules_service, 'get_rules' ) ) {
			$rules = (array) $rules_service->get_rules();
			$rules = apply_filters( 'clms_gamification_rules', $rules );
			return is_array( $rules ) ? $rules : array();
		}

		$rules = array(
			'lesson_completed' => array(
				'enabled' => true,
				'points'  => 10,
			),
			'submission_sent' => array(
				'enabled' => true,
				'points'  => 8,
			),
			'evaluation_passed' => array(
				'enabled'        => true,
				'points'         => 25,
				'pass_threshold' => 70,
			),
			'feedback_received' => array(
				'enabled' => true,
				'points'  => 6,
			),
			'course_completed' => array(
				'enabled' => true,
				'points'  => 80,
			),
			'program_completed' => array(
				'enabled' => true,
				'points'  => 140,
			),
			'certificate_issued' => array(
				'enabled' => true,
				'points'  => 120,
			),
			'peer_review_quality' => array(
				'enabled' => true,
				'points'  => 12,
			),
		);

		$rules = apply_filters( 'clms_gamification_rules', $rules );

		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Mínimo de nota para considerar evaluación aprobada.
	 *
	 * @return int
	 */
	protected function get_evaluation_pass_threshold() {
		$rules = $this->get_rules();
		$rule  = isset( $rules['evaluation_passed'] ) && is_array( $rules['evaluation_passed'] )
			? $rules['evaluation_passed']
			: array();

		$threshold = isset( $rule['pass_threshold'] ) ? absint( $rule['pass_threshold'] ) : 70;

		return max( 0, min( 100, $threshold ) );
	}

	/**
	 * Construye clave idempotente por evento.
	 *
	 * @param string $event_type Evento.
	 * @param array  $context    Contexto.
	 * @return string
	 */
	protected function build_event_key( $event_type, $context ) {
		$event_type = sanitize_key( (string) $event_type );
		$context    = is_array( $context ) ? $context : array();
		$user_id    = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		if ( ! $user_id ) {
			return '';
		}

		switch ( $event_type ) {
			case 'lesson_completed':
				$lesson_id = isset( $context['lesson_id'] ) ? absint( $context['lesson_id'] ) : 0;
				return $lesson_id ? 'lesson_completed:' . $user_id . ':' . $lesson_id : '';

			case 'submission_sent':
				$submission_id = isset( $context['submission_id'] ) ? absint( $context['submission_id'] ) : 0;
				return $submission_id ? 'submission_sent:' . $user_id . ':submission:' . $submission_id : '';

			case 'evaluation_passed':
				$submission_id = isset( $context['submission_id'] ) ? absint( $context['submission_id'] ) : 0;
				if ( $submission_id ) {
					return 'evaluation_passed:' . $user_id . ':submission:' . $submission_id;
				}
				$lesson_id = isset( $context['lesson_id'] ) ? absint( $context['lesson_id'] ) : 0;
				return $lesson_id ? 'evaluation_passed:' . $user_id . ':lesson:' . $lesson_id : '';

			case 'feedback_received':
				$tracking_id = isset( $context['tracking_id'] ) ? sanitize_text_field( (string) $context['tracking_id'] ) : '';
				if ( '' !== $tracking_id ) {
					return 'feedback_received:' . $user_id . ':tracking:' . md5( $tracking_id );
				}
				$submission_id = isset( $context['submission_id'] ) ? absint( $context['submission_id'] ) : 0;
				if ( $submission_id ) {
					return 'feedback_received:' . $user_id . ':submission:' . $submission_id;
				}
				$course_id = isset( $context['course_id'] ) ? absint( $context['course_id'] ) : 0;
				return $course_id ? 'feedback_received:' . $user_id . ':course:' . $course_id : '';

			case 'course_completed':
				$course_id = isset( $context['course_id'] ) ? absint( $context['course_id'] ) : 0;
				return $course_id ? 'course_completed:' . $user_id . ':' . $course_id : '';

			case 'program_completed':
				$program_id = isset( $context['program_id'] ) ? absint( $context['program_id'] ) : 0;
				return $program_id ? 'program_completed:' . $user_id . ':' . $program_id : '';

			case 'certificate_issued':
				$target_type = isset( $context['target_type'] ) ? sanitize_key( (string) $context['target_type'] ) : 'course';
				$target_id   = isset( $context['target_id'] ) ? absint( $context['target_id'] ) : 0;
				if ( ! $target_id ) {
					$target_id = 'program' === $target_type
						? ( isset( $context['program_id'] ) ? absint( $context['program_id'] ) : 0 )
						: ( isset( $context['course_id'] ) ? absint( $context['course_id'] ) : 0 );
				}
				return $target_id ? 'certificate_issued:' . $target_type . ':' . $user_id . ':' . $target_id : '';

			case 'peer_review_quality':
				$assignment_id = isset( $context['assignment_id'] ) ? absint( $context['assignment_id'] ) : 0;
				return $assignment_id ? 'peer_review_quality:' . $user_id . ':assignment:' . $assignment_id : '';
		}

		return '';
	}

	/**
	 * Verifica si el evento ya fue aplicado.
	 *
	 * @param int    $user_id   Usuario.
	 * @param string $event_key Clave.
	 * @return bool
	 */
	protected function has_processed_event( $user_id, $event_key ) {
		$event_key = sanitize_key( (string) $event_key );
		if ( ! $user_id || '' === $event_key ) {
			return false;
		}

		$keys = get_user_meta( $user_id, self::META_EVENT_KEYS, true );
		$keys = is_array( $keys ) ? $keys : array();

		return isset( $keys[ $event_key ] );
	}

	/**
	 * Marca evento como procesado.
	 *
	 * @param int    $user_id   Usuario.
	 * @param string $event_key Clave.
	 * @return void
	 */
	protected function mark_event_as_processed( $user_id, $event_key ) {
		$event_key = sanitize_key( (string) $event_key );
		if ( ! $user_id || '' === $event_key ) {
			return;
		}

		$keys = get_user_meta( $user_id, self::META_EVENT_KEYS, true );
		$keys = is_array( $keys ) ? $keys : array();

		$keys[ $event_key ] = current_time( 'mysql' );

		if ( count( $keys ) > self::MAX_EVENT_KEYS ) {
			asort( $keys );
			$keys = array_slice( $keys, -1 * self::MAX_EVENT_KEYS, null, true );
		}

		update_user_meta( $user_id, self::META_EVENT_KEYS, $keys );
	}

	/**
	 * Actualiza racha diaria sin recompensar actividad repetida del mismo día.
	 *
	 * @param array $summary Resumen.
	 * @param array $context Contexto.
	 * @return array
	 */
	protected function update_streak( $summary, $context ) {
		$summary = is_array( $summary ) ? $summary : array();
		$context = is_array( $context ) ? $context : array();

		$current_day = $this->normalize_day( isset( $context['occurred_on'] ) ? $context['occurred_on'] : '' );
		$last_day    = $this->normalize_day( isset( $summary['last_activity_date'] ) ? $summary['last_activity_date'] : '' );

		$streak = max( 0, absint( $summary['streak_days'] ?? 0 ) );

		if ( '' === $current_day ) {
			$current_day = current_time( 'Y-m-d' );
		}

		if ( '' === $last_day ) {
			$streak = 1;
		} elseif ( $last_day === $current_day ) {
			$streak = max( 1, $streak );
		} else {
			$last_ts    = strtotime( $last_day );
			$current_ts = strtotime( $current_day );
			$delta_days = ( $last_ts && $current_ts ) ? (int) floor( ( $current_ts - $last_ts ) / DAY_IN_SECONDS ) : 0;

			if ( 1 === $delta_days ) {
				$streak = max( 1, $streak + 1 );
			} else {
				$streak = 1;
			}
		}

		$summary['streak_days']        = $streak;
		$summary['last_activity_date'] = $current_day;

		return $summary;
	}

	/**
	 * Recalcula nivel y desbloqueos por puntos acumulados.
	 *
	 * @param array $summary Resumen.
	 * @return array
	 */
	protected function update_level_and_unlocks( $summary ) {
		$summary    = is_array( $summary ) ? $summary : array();
		$points     = max( 0, absint( $summary['points'] ?? 0 ) );
		$thresholds = $this->get_level_thresholds();

		$level            = 1;
		$next_level       = 0;
		$next_threshold   = 0;

		foreach ( $thresholds as $candidate_level => $candidate_threshold ) {
			$candidate_level     = max( 1, absint( $candidate_level ) );
			$candidate_threshold = max( 0, absint( $candidate_threshold ) );

			if ( $points >= $candidate_threshold ) {
				$level = max( $level, $candidate_level );
				continue;
			}

			if ( 0 === $next_level ) {
				$next_level     = $candidate_level;
				$next_threshold = $candidate_threshold;
			}
		}

		$summary['level'] = $level;

		if ( $next_level > 0 && $next_threshold > $points ) {
			$summary['next_level']           = $next_level;
			$summary['points_to_next_level'] = $next_threshold - $points;
		} else {
			$summary['next_level']           = $level;
			$summary['points_to_next_level'] = 0;
		}

		if ( $level >= 2 ) {
			$summary['unlocks'] = $this->append_unique_slug( $summary['unlocks'], 'nivel_2' );
		}
		if ( $level >= 3 ) {
			$summary['unlocks'] = $this->append_unique_slug( $summary['unlocks'], 'nivel_3' );
		}

		return $summary;
	}

	/**
	 * Umbrales por nivel.
	 *
	 * @return array<int,int>
	 */
	protected function get_level_thresholds() {
		$rules_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Gamification_Rules') : null;
		if ( $rules_service && method_exists( $rules_service, 'get_level_thresholds' ) ) {
			$thresholds = (array) $rules_service->get_level_thresholds();
			$thresholds = apply_filters( 'clms_gamification_level_thresholds', $thresholds );
			return is_array( $thresholds ) ? $thresholds : array( 1 => 0 );
		}

		$thresholds = array(
			1 => 0,
			2 => 100,
			3 => 250,
			4 => 450,
			5 => 700,
		);

		$thresholds = apply_filters( 'clms_gamification_level_thresholds', $thresholds );
		$thresholds = is_array( $thresholds ) ? $thresholds : array();

		$normalized = array();
		foreach ( $thresholds as $level => $points ) {
			$normalized[ max( 1, absint( $level ) ) ] = max( 0, absint( $points ) );
		}

		ksort( $normalized );

		if ( empty( $normalized ) ) {
			$normalized = array( 1 => 0 );
		}

		return $normalized;
	}

	/**
	 * Inserta entrada en ledger.
	 *
	 * @param int   $user_id Usuario.
	 * @param array $entry   Entrada.
	 * @return void
	 */
	protected function append_ledger_entry( $user_id, $entry ) {
		$user_id = absint( $user_id );
		$entry   = is_array( $entry ) ? $entry : array();

		if ( ! $user_id || empty( $entry ) ) {
			return;
		}

		$ledger = get_user_meta( $user_id, self::META_LEDGER, true );
		$ledger = is_array( $ledger ) ? $ledger : array();

		array_unshift( $ledger, $entry );

		if ( count( $ledger ) > self::MAX_LEDGER_ENTRIES ) {
			$ledger = array_slice( $ledger, 0, self::MAX_LEDGER_ENTRIES );
		}

		update_user_meta( $user_id, self::META_LEDGER, $ledger );
	}

	/**
	 * Reduce contexto de ledger a campos seguros.
	 *
	 * @param array $context Contexto.
	 * @return array<string,mixed>
	 */
	protected function sanitize_ledger_context( $context ) {
		$context = is_array( $context ) ? $context : array();

		return array(
			'user_id'       => isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0,
			'course_id'     => isset( $context['course_id'] ) ? absint( $context['course_id'] ) : 0,
			'program_id'    => isset( $context['program_id'] ) ? absint( $context['program_id'] ) : 0,
			'target_id'     => isset( $context['target_id'] ) ? absint( $context['target_id'] ) : 0,
			'target_type'   => isset( $context['target_type'] ) ? sanitize_key( (string) $context['target_type'] ) : '',
			'lesson_id'     => isset( $context['lesson_id'] ) ? absint( $context['lesson_id'] ) : 0,
			'submission_id' => isset( $context['submission_id'] ) ? absint( $context['submission_id'] ) : 0,
			'assignment_id' => isset( $context['assignment_id'] ) ? absint( $context['assignment_id'] ) : 0,
			'grade'         => isset( $context['grade'] ) && is_numeric( $context['grade'] ) ? (float) $context['grade'] : '',
			'quality_score' => isset( $context['quality_score'] ) && is_numeric( $context['quality_score'] ) ? (float) $context['quality_score'] : '',
			'quality_status'=> isset( $context['quality_status'] ) ? sanitize_key( (string) $context['quality_status'] ) : '',
			'passing_score' => isset( $context['passing_score'] ) ? absint( $context['passing_score'] ) : 0,
			'occurred_on'   => $this->normalize_day( isset( $context['occurred_on'] ) ? $context['occurred_on'] : '' ),
		);
	}

	/**
	 * Normaliza fecha YYYY-mm-dd.
	 *
	 * @param mixed $raw Día.
	 * @return string
	 */
	protected function normalize_day( $raw ) {
		$raw = sanitize_text_field( (string) $raw );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return $raw;
		}

		$timestamp = strtotime( $raw );
		if ( ! $timestamp ) {
			return '';
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Agrega slug único.
	 *
	 * @param mixed  $items Lista.
	 * @param string $slug  Slug.
	 * @return array<int,string>
	 */
	protected function append_unique_slug( $items, $slug ) {
		$items = is_array( $items ) ? $items : array();
		$slug  = sanitize_key( (string) $slug );

		$clean = array();
		foreach ( $items as $item ) {
			$item = sanitize_key( (string) $item );
			if ( '' !== $item && ! in_array( $item, $clean, true ) ) {
				$clean[] = $item;
			}
		}

		if ( '' !== $slug && ! in_array( $slug, $clean, true ) ) {
			$clean[] = $slug;
		}

		return $clean;
	}

	/**
	 * Defaults del resumen.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_default_summary() {
		return array(
			'points'               => 0,
			'level'                => 1,
			'next_level'           => 2,
			'points_to_next_level' => 100,
			'streak_days'          => 0,
			'last_activity_date'   => '',
			'lessons_completed'    => 0,
			'evaluations_passed'   => 0,
			'peer_reviews_quality' => 0,
			'events_total'         => 0,
			'milestones'           => array(),
			'badges'               => array(),
			'unlocks'              => array(),
			'updated_at'           => '',
		);
	}

	/**
	 * Normaliza tipos en resumen.
	 *
	 * @param array $summary Resumen.
	 * @return array<string,mixed>
	 */
	protected function normalize_summary( $summary ) {
		$summary = is_array( $summary ) ? $summary : array();
		$summary = array_merge( $this->get_default_summary(), $summary );

		$summary['points']               = max( 0, absint( $summary['points'] ) );
		$summary['level']                = max( 1, absint( $summary['level'] ) );
		$summary['next_level']           = max( $summary['level'], absint( $summary['next_level'] ) );
		$summary['points_to_next_level'] = max( 0, absint( $summary['points_to_next_level'] ) );
		$summary['streak_days']          = max( 0, absint( $summary['streak_days'] ) );
		$summary['last_activity_date']   = $this->normalize_day( $summary['last_activity_date'] );
		$summary['lessons_completed']    = max( 0, absint( $summary['lessons_completed'] ) );
		$summary['evaluations_passed']   = max( 0, absint( $summary['evaluations_passed'] ) );
		$summary['peer_reviews_quality'] = max( 0, absint( $summary['peer_reviews_quality'] ) );
		$summary['events_total']         = max( 0, absint( $summary['events_total'] ) );
		$summary['milestones']           = $this->append_unique_slug( $summary['milestones'], '' );
		$summary['badges']               = $this->append_unique_slug( $summary['badges'], '' );
		$summary['unlocks']              = $this->append_unique_slug( $summary['unlocks'], '' );
		$summary['updated_at']           = sanitize_text_field( (string) $summary['updated_at'] );

		return $summary;
	}
}
