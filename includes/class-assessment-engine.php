<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Assessment_Engine {

	const AUTO_GRADE_CRON_HOOK           = 'clms_assessment_auto_grade_async';
	const EVALUATION_MODE_META            = '_clms_evaluation_mode';
	const ACTIVITY_MODE_META              = '_clms_activity_mode';
	const AUTOMATION_LEVEL_META           = '_clms_automation_level';
	const AI_CONFIDENCE_THRESHOLD_META    = '_clms_ai_confidence_threshold';
	const AI_CONFIDENCE_THRESHOLDS_META   = '_clms_ai_confidence_thresholds';
	const GRADE_SOURCE_META               = '_clms_grade_source';
	const GRADE_CONFIDENCE_META           = '_clms_grade_confidence';
	const GRADE_RAW_CONFIDENCE_META       = '_clms_grade_confidence_raw';
	const GRADE_CONFIDENCE_PROFILE_META   = '_clms_grade_confidence_profile';
	const GRADE_MODEL_VERSION_META        = '_clms_grade_model_version';
	const GRADE_PROVIDER_META             = '_clms_grade_provider';
	const GRADE_MANUAL_OVERRIDE_META      = '_clms_grade_manual_override';
	const ASSESSMENT_AUDIT_LOG_META       = '_clms_assessment_audit_log';
	const ASSESSMENT_LAST_RESULT_META     = '_clms_assessment_last_result';
	const GRADEBOOK_META_PREFIX           = '_clms_gradebook_course_';
	const DEFAULT_AI_CONFIDENCE_THRESHOLD = 0.75;
	const AUTO_GRADE_QUEUE_DELAY          = 5;
	const GRADEBOOK_CACHE_TTL             = 300;

	const EVALUATION_MODES = array(
		'manual',
		'ai_assisted',
		'ai_auto_grade',
		'peer_review',
		'hybrid',
	);

	const GRADE_STATUSES = array(
		'submitted',
		'in_review',
		'graded',
		'needs_revision',
		'returned',
	);

	public function __construct() {
		add_action( 'clms_submission_saved', array( $this, 'maybe_run_automatic_assessment' ), 20, 4 );
		add_action( 'clms_peer_grades_aggregated', array( $this, 'capture_peer_review_assessment' ), 10, 3 );
		add_action( self::AUTO_GRADE_CRON_HOOK, array( $this, 'process_scheduled_auto_grade' ), 10, 1 );
		add_action( 'clms_submission_created', array( $this, 'invalidate_cache_from_submission' ), 10, 3 );
		add_action( 'clms_submission_graded', array( $this, 'invalidate_cache_from_submission' ), 10, 5 );
		add_action( 'clms_lesson_completed', array( $this, 'invalidate_cache_from_lesson' ), 10, 2 );
		add_action( 'save_post_lm_course', array( $this, 'invalidate_cache_from_course' ), 10, 3 );
		add_action( 'save_post_lm_program', array( $this, 'invalidate_cache_from_program' ), 10, 3 );
	}

	public function get_lesson_evaluation_settings( $lesson_id ) {
		$lesson_id = absint( $lesson_id );

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			$defaults = array(
				'mode'                 => 'manual',
				'activity_type'        => 'lectura',
				'automation_level'     => 'assisted',
				'ai_confidence_threshold' => self::DEFAULT_AI_CONFIDENCE_THRESHOLD,
				'ai_confidence_threshold_default' => self::DEFAULT_AI_CONFIDENCE_THRESHOLD,
				'ai_confidence_thresholds' => $this->get_default_ai_thresholds(),
				'supports_ai'          => false,
				'peer_review_enabled'  => false,
			);
			if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
				$defaults = (array) CLMS_Helper::modular_apply( 'assessment_lesson_settings', $defaults, $lesson_id );
			}
			return $defaults;
		}

		$mode = sanitize_key( (string) get_post_meta( $lesson_id, self::EVALUATION_MODE_META, true ) );

		if ( ! in_array( $mode, self::EVALUATION_MODES, true ) ) {
			$mode = (bool) get_post_meta( $lesson_id, '_clms_peer_review_enabled', true ) ? 'peer_review' : 'manual';
		}

		$activity_type      = $this->get_lesson_activity_type( $lesson_id );
		$automation_default = $this->resolve_automation_level_from_mode( $mode );
		$automation_level   = $this->get_lesson_automation_level( $lesson_id, $automation_default );
		$threshold_default  = (float) get_post_meta( $lesson_id, self::AI_CONFIDENCE_THRESHOLD_META, true );
		$thresholds_by_type = $this->get_lesson_ai_confidence_thresholds( $lesson_id, $threshold_default );
		$threshold          = $this->get_resolved_lesson_ai_threshold( $lesson_id, $activity_type, $threshold_default, $thresholds_by_type );

		$settings = array(
			'mode'                    => $mode,
			'activity_type'           => $activity_type,
			'automation_level'        => $automation_level,
			'ai_confidence_threshold' => $threshold,
			'ai_confidence_threshold_default' => $threshold_default > 0 ? max( 0.5, min( 0.99, $threshold_default ) ) : self::DEFAULT_AI_CONFIDENCE_THRESHOLD,
			'ai_confidence_thresholds' => $thresholds_by_type,
			'supports_ai'             => $this->lesson_supports_ai_assessment( $lesson_id ),
			'peer_review_enabled'     => (bool) get_post_meta( $lesson_id, '_clms_peer_review_enabled', true ),
		);
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$settings = (array) CLMS_Helper::modular_apply( 'assessment_lesson_settings', $settings, $lesson_id );
		}

		return $settings;
	}

	public function maybe_run_automatic_assessment( $submission_id, $user_id, $lesson_id, $course_id = 0 ) {
		unset( $user_id, $course_id );

		$submission_id = absint( $submission_id );
		$lesson_id     = absint( $lesson_id );

		if ( ! $submission_id || ! $lesson_id ) {
			return;
		}

		$settings = $this->get_lesson_evaluation_settings( $lesson_id );

		if ( 'ai_auto_grade' !== $settings['mode'] ) {
			return;
		}

		if ( $this->has_manual_override( $submission_id ) ) {
			return;
		}

		$this->schedule_automatic_assessment(
			$submission_id,
			$lesson_id,
			'submission_saved'
		);
	}

	public function process_scheduled_auto_grade( $submission_id ) {
		$submission_id = absint( $submission_id );

		if ( ! $submission_id || 'clms_submission' !== get_post_type( $submission_id ) ) {
			return;
		}

		if ( $this->has_manual_override( $submission_id ) ) {
			return;
		}

		$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$settings  = $this->get_lesson_evaluation_settings( $lesson_id );

		if ( 'ai_auto_grade' !== $settings['mode'] ) {
			return;
		}

		$this->run_submission_assessment(
			$submission_id,
			array(
				'trigger'       => 'cron_auto_grade',
				'allow_publish' => true,
			)
		);
	}

	public function request_ai_assessment( $submission_id, $args = array() ) {
		$args = is_array( $args ) ? $args : array();

		$params = array_merge(
			array(
				'trigger' => 'teacher_request',
			),
			$args
		);

		/*
		 * En solicitudes iniciadas por docente, la IA debe permanecer como asistencia:
		 * genera sugerencias y deja la decisión final de publicación en revisión manual.
		 */
		$params['allow_publish'] = false;

		return $this->run_submission_assessment( $submission_id, $params );
	}

	public function run_submission_assessment( $submission_id, $args = array() ) {
		$submission_id = absint( $submission_id );
		$args          = is_array( $args ) ? $args : array();

		if ( ! $submission_id || 'clms_submission' !== get_post_type( $submission_id ) ) {
			return new WP_Error( 'invalid_submission', __( 'La entrega no es válida para evaluación.', 'atora-lms' ) );
		}

		$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );

		if ( ! $lesson_id ) {
			return new WP_Error( 'missing_lesson', __( 'La entrega no tiene una lección asociada.', 'atora-lms' ) );
		}

		$settings = $this->get_lesson_evaluation_settings( $lesson_id );
		$mode     = $settings['mode'];
		$trigger  = isset( $args['trigger'] ) ? sanitize_key( (string) $args['trigger'] ) : 'system';

		$automation = $this->resolve_automation_policy(
			$args,
			$mode,
			isset( $settings['activity_type'] ) ? (string) $settings['activity_type'] : 'lectura',
			isset( $settings['automation_level'] ) ? (string) $settings['automation_level'] : ''
		);

		$automation_requested     = $automation['requested'];
		$automation_level         = $automation['effective'];
		$automation_allowed       = ! empty( $automation['allowed'] );
		$automation_rule          = isset( $automation['rule'] ) ? (string) $automation['rule'] : '';
		$review_required          = 'auto' !== $automation_level;
		$allow_publish_requested  = ! empty( $args['allow_publish'] );
		$allow_publish            = $allow_publish_requested && in_array( $automation_level, array( 'semi', 'auto' ), true );

		if ( in_array( $mode, array( 'manual', 'peer_review' ), true ) ) {
			$this->log_audit_event(
				$submission_id,
				array(
					'source'          => $mode,
					'evaluation_mode' => $mode,
					'published'       => false,
					'trigger'         => $trigger,
					'note'            => 'La actividad requiere evaluación no automática.',
					'automation_level' => $automation_level,
					'automation_level_requested' => $automation_requested,
					'automation_allowed' => $automation_allowed,
					'review_required' => $review_required,
					'automation_rule' => $automation_rule,
				)
			);

			return array(
				'source'          => $mode,
				'evaluation_mode' => $mode,
				'published'       => false,
				'automation_level' => $automation_level,
				'automation_level_requested' => $automation_requested,
				'automation_allowed' => $automation_allowed,
				'review_required' => $review_required,
				'automation_rule' => $automation_rule,
			);
		}

		if ( ! $settings['supports_ai'] ) {
			if ( 'hybrid' === $mode ) {
				$fallback = array(
					'source'             => 'hybrid',
					'evaluation_mode'    => $mode,
					'published'          => false,
					'status'             => 'in_review',
					'trigger'            => $trigger,
					'note'               => 'Hybrid degradado a revisión manual: no hay diseño instruccional suficiente para IA.',
					'activity_type'      => $settings['activity_type'],
					'threshold_applied'  => $settings['ai_confidence_threshold'],
					'automation_level'   => $automation_level,
					'automation_level_requested' => $automation_requested,
					'automation_allowed' => $automation_allowed,
					'review_required'    => true,
					'automation_rule'    => $automation_rule,
				);
				$this->store_assessment_result( $submission_id, $fallback );

				return $fallback;
			}

			$this->log_audit_event(
				$submission_id,
				array(
					'source'          => $mode,
					'evaluation_mode' => $mode,
					'published'       => false,
					'trigger'         => $trigger,
					'note'            => 'No hay diseño instruccional suficiente para evaluación IA.',
					'automation_level' => $automation_level,
					'automation_level_requested' => $automation_requested,
					'automation_allowed' => $automation_allowed,
					'review_required' => true,
					'automation_rule' => $automation_rule,
				)
			);

			return new WP_Error( 'ai_design_missing', __( 'La actividad no tiene rúbrica ni criterios para evaluación IA segura.', 'atora-lms' ) );
		}

		$assessment = $this->generate_ai_assessment( $submission_id, $lesson_id, $mode );

		if ( is_wp_error( $assessment ) ) {
			if ( 'hybrid' === $mode ) {
				$fallback = array(
					'source'             => 'hybrid',
					'evaluation_mode'    => $mode,
					'published'          => false,
					'status'             => 'in_review',
					'trigger'            => $trigger,
					'note'               => $assessment->get_error_message(),
					'activity_type'      => $settings['activity_type'],
					'threshold_applied'  => $settings['ai_confidence_threshold'],
					'automation_level'   => $automation_level,
					'automation_level_requested' => $automation_requested,
					'automation_allowed' => $automation_allowed,
					'review_required'    => true,
					'automation_rule'    => $automation_rule,
				);
				$this->store_assessment_result( $submission_id, $fallback );

				return $fallback;
			}

			$this->log_audit_event(
				$submission_id,
				array(
					'source'          => $mode,
					'evaluation_mode' => $mode,
					'published'       => false,
					'trigger'         => $trigger,
					'note'            => $assessment->get_error_message(),
					'automation_level' => $automation_level,
					'automation_level_requested' => $automation_requested,
					'automation_allowed' => $automation_allowed,
					'review_required' => true,
					'automation_rule' => $automation_rule,
				)
			);

			return $assessment;
		}

		$assessment['evaluation_mode'] = $mode;
		$assessment['trigger']         = $trigger;
		$assessment['activity_type']   = $settings['activity_type'];
		$assessment['threshold_applied'] = $settings['ai_confidence_threshold'];
		$assessment['automation_level'] = $automation_level;
		$assessment['automation_level_requested'] = $automation_requested;
		$assessment['automation_allowed'] = $automation_allowed;
		$assessment['review_required'] = $review_required;
		$assessment['automation_rule'] = $automation_rule;

		if ( isset( $assessment['confidence'] ) && '' !== (string) $assessment['confidence'] ) {
			$assessment['raw_confidence']    = max( 0.0, min( 1.0, (float) $assessment['confidence'] ) );
			$assessment['confidence_profile'] = $this->get_confidence_profile_key(
				isset( $assessment['provider'] ) ? $assessment['provider'] : '',
				isset( $assessment['model_version'] ) ? $assessment['model_version'] : ''
			);
			$assessment['confidence'] = $this->normalize_confidence_score(
				$assessment['raw_confidence'],
				isset( $assessment['provider'] ) ? $assessment['provider'] : '',
				isset( $assessment['model_version'] ) ? $assessment['model_version'] : ''
			);
		}

		if ( ! $automation_allowed ) {
			$assessment['published'] = false;
			$assessment['status']    = 'in_review';
			$assessment['note']      = sprintf(
				'Nivel de automatización "%1$s" bloqueado para actividad tipo "%2$s".',
				$automation_requested,
				$settings['activity_type']
			);
			$this->store_assessment_result( $submission_id, $assessment );

			return $assessment;
		}

		if ( 'hybrid' === $mode && ( ! isset( $assessment['confidence'] ) || '' === (string) $assessment['confidence'] || $assessment['confidence'] < $settings['ai_confidence_threshold'] ) ) {
			$assessment['published'] = false;
			$assessment['status']    = 'in_review';
			$this->store_assessment_result( $submission_id, $assessment );

			return $assessment;
		}

		$confidence_score = isset( $assessment['confidence'] ) && '' !== (string) $assessment['confidence']
			? max( 0.0, min( 1.0, (float) $assessment['confidence'] ) )
			: 0.0;

		$should_publish = $allow_publish
			&& '' !== (string) $assessment['grade']
			&& $confidence_score >= $settings['ai_confidence_threshold'];

		$assessment['published'] = $should_publish;
		$assessment['status']    = $should_publish ? 'graded' : 'in_review';

		$this->store_assessment_result( $submission_id, $assessment );

		return $assessment;
	}

	public function record_manual_assessment( $submission_id, $assessment = array() ) {
		$submission_id = absint( $submission_id );
		$assessment    = is_array( $assessment ) ? $assessment : array();

		if ( ! $submission_id ) {
			return;
		}

		if ( array_key_exists( 'grade', $assessment ) || array_key_exists( 'status', $assessment ) || array_key_exists( 'feedback', $assessment ) ) {
			$this->publish_submission_grade(
				$submission_id,
				array(
					'grade'      => array_key_exists( 'grade', $assessment ) ? $assessment['grade'] : '',
					'status'     => isset( $assessment['status'] ) ? $assessment['status'] : 'graded',
					'feedback'   => isset( $assessment['feedback'] ) ? $assessment['feedback'] : '',
					'source'     => 'manual',
					'audit_payload' => array(
						'trigger'       => 'speedgrade',
						'evaluation_mode' => 'manual',
						'activity_type' => $this->get_lesson_activity_type( absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) ) ),
					),
				)
			);
			return;
		}

		$previous = $this->get_submission_grade_record( $submission_id );
		$grade    = array_key_exists( 'grade', $assessment ) ? $assessment['grade'] : '';

		update_post_meta( $submission_id, self::GRADE_SOURCE_META, 'manual' );
		delete_post_meta( $submission_id, self::GRADE_CONFIDENCE_META );
		delete_post_meta( $submission_id, self::GRADE_RAW_CONFIDENCE_META );
		delete_post_meta( $submission_id, self::GRADE_CONFIDENCE_PROFILE_META );
		delete_post_meta( $submission_id, self::GRADE_MODEL_VERSION_META );
		delete_post_meta( $submission_id, self::GRADE_PROVIDER_META );
		update_post_meta( $submission_id, self::GRADE_MANUAL_OVERRIDE_META, ( ! empty( $previous['grade_source'] ) && 'manual' !== $previous['grade_source'] ) ? '1' : '0' );

		$this->log_audit_event(
			$submission_id,
			array(
				'source'          => 'manual',
				'evaluation_mode' => $this->get_lesson_evaluation_mode_for_submission( $submission_id ),
				'grade'           => '' !== (string) $grade ? max( 0, min( 100, absint( $grade ) ) ) : '',
				'raw_confidence'  => '',
				'confidence'      => '',
				'confidence_profile' => '',
				'provider'        => '',
				'model_version'   => '',
				'override_manual' => ( ! empty( $previous['grade_source'] ) && 'manual' !== $previous['grade_source'] ),
				'published'       => true,
				'status'          => isset( $assessment['status'] ) ? sanitize_key( (string) $assessment['status'] ) : '',
				'trigger'         => 'speedgrade',
				'activity_type'   => $this->get_lesson_activity_type( absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) ) ),
			)
		);
	}

	public function capture_peer_review_assessment( $submission_id, $peer_grade, $avg_scores = array() ) {
		$submission_id = absint( $submission_id );

		if ( ! $submission_id ) {
			return;
		}

		$final_grade = get_post_meta( $submission_id, '_clms_final_grade', true );
		$final_grade = '' !== (string) $final_grade ? max( 0, min( 100, absint( $final_grade ) ) ) : max( 0, min( 100, absint( $peer_grade ) ) );
		$manual_override = $this->has_manual_override( $submission_id );
		$feedback = (string) get_post_meta( $submission_id, '_clms_submission_feedback', true );

		// Si ya existe override manual, conservamos la decisión docente y solo auditamos.
		if ( $manual_override ) {
			$this->log_audit_event(
				$submission_id,
				array(
					'source'             => 'peer_review',
					'evaluation_mode'    => $this->get_lesson_evaluation_mode_for_submission( $submission_id ),
					'grade'              => $final_grade,
					'raw_confidence'     => '',
					'confidence'         => '',
					'confidence_profile' => '',
					'provider'           => '',
					'model_version'      => '',
					'override_manual'    => true,
					'published'          => true,
					'status'             => 'graded',
					'trigger'            => 'peer_review_completed',
					'raw'                => is_array( $avg_scores ) ? $avg_scores : array(),
					'activity_type'      => $this->get_lesson_activity_type( absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) ) ),
					'note'               => __( 'Revisión entre pares registrada sin sobrescribir override manual.', 'atora-lms' ),
				)
			);
			return;
		}

		$result = $this->publish_submission_grade(
			$submission_id,
			array(
				'grade'         => $final_grade,
				'status'        => 'graded',
				'feedback'      => $feedback,
				'source'        => 'peer_review',
				'rubric_scores' => is_array( $avg_scores ) ? $avg_scores : array(),
				'audit_payload' => array(
					'trigger'         => 'peer_review_completed',
					'evaluation_mode' => $this->get_lesson_evaluation_mode_for_submission( $submission_id ),
					'activity_type'   => $this->get_lesson_activity_type( absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) ) ),
					'note'            => __( 'Publicación desde agregación de revisión entre pares.', 'atora-lms' ),
				),
			)
		);

		if ( ! is_wp_error( $result ) ) {
			return;
		}

		if ( ! $manual_override ) {
			update_post_meta( $submission_id, self::GRADE_SOURCE_META, 'peer_review' );
			delete_post_meta( $submission_id, self::GRADE_CONFIDENCE_META );
			delete_post_meta( $submission_id, self::GRADE_RAW_CONFIDENCE_META );
			delete_post_meta( $submission_id, self::GRADE_CONFIDENCE_PROFILE_META );
			delete_post_meta( $submission_id, self::GRADE_MODEL_VERSION_META );
			delete_post_meta( $submission_id, self::GRADE_PROVIDER_META );
			update_post_meta( $submission_id, self::GRADE_MANUAL_OVERRIDE_META, '0' );
		}

		$this->log_audit_event(
			$submission_id,
			array(
				'source'          => 'peer_review',
				'evaluation_mode' => $this->get_lesson_evaluation_mode_for_submission( $submission_id ),
				'grade'           => $final_grade,
				'raw_confidence'  => '',
				'confidence'      => '',
				'confidence_profile' => '',
				'provider'        => '',
				'model_version'   => '',
				'override_manual' => $manual_override,
				'published'       => true,
				'status'          => 'graded',
				'trigger'         => 'peer_review_completed',
				'raw'             => is_array( $avg_scores ) ? $avg_scores : array(),
				'activity_type'   => $this->get_lesson_activity_type( absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) ) ),
			)
		);
	}

	public function get_submission_grade_record( $submission_id ) {
		$submission_id = absint( $submission_id );

		if ( ! $submission_id ) {
			return array();
		}

		$grade = get_post_meta( $submission_id, '_clms_submission_grade', true );
		if ( '' === (string) $grade ) {
			$grade = get_post_meta( $submission_id, '_clms_final_grade', true );
		}

		$source = (string) get_post_meta( $submission_id, self::GRADE_SOURCE_META, true );
		if ( '' === $source ) {
			$source = $this->infer_grade_source( $submission_id, $grade );
		}

		return array(
			'grade'              => '' !== (string) $grade ? max( 0, min( 100, absint( $grade ) ) ) : '',
			'grade_source'       => $source,
			'raw_confidence'     => get_post_meta( $submission_id, self::GRADE_RAW_CONFIDENCE_META, true ),
			'confidence'         => get_post_meta( $submission_id, self::GRADE_CONFIDENCE_META, true ),
			'confidence_profile' => (string) get_post_meta( $submission_id, self::GRADE_CONFIDENCE_PROFILE_META, true ),
			'provider'           => (string) get_post_meta( $submission_id, self::GRADE_PROVIDER_META, true ),
			'model_version'      => (string) get_post_meta( $submission_id, self::GRADE_MODEL_VERSION_META, true ),
			'manual_override'    => (bool) get_post_meta( $submission_id, self::GRADE_MANUAL_OVERRIDE_META, true ),
			'assessment_audit'   => get_post_meta( $submission_id, self::ASSESSMENT_LAST_RESULT_META, true ),
		);
	}

	public function get_submission_audit_log( $submission_id ) {
		$submission_id = absint( $submission_id );

		if ( ! $submission_id ) {
			return array();
		}

		$log = get_post_meta( $submission_id, self::ASSESSMENT_AUDIT_LOG_META, true );

		return is_array( $log ) ? array_values( $log ) : array();
	}

	public function build_course_gradebook( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id ) {
			return array(
				'summary' => $this->get_empty_gradebook_summary(),
				'entries' => array(),
			);
		}

		if ( class_exists( 'CLMS_Cache' ) ) {
			$cached = CLMS_Cache::get( 'gradebook', array( 'entries', $user_id, $course_id ), array() );
			if ( is_array( $cached ) && isset( $cached['entries'] ) ) {
				return $cached;
			}
		}

		$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );
		$lesson_ids = is_array( $lesson_ids ) ? array_map( 'absint', $lesson_ids ) : array();

		if ( empty( $lesson_ids ) ) {
			return array(
				'summary' => $this->get_empty_gradebook_summary(),
				'entries' => array(),
			);
		}

		$completed_lessons = get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed_lessons = is_array( $completed_lessons ) ? array_map( 'absint', $completed_lessons ) : array();

		$entries            = array();
		$quiz_scores        = array();
		$assignment_scores  = array();
		$source_breakdown   = array();

		$submission_module = clms_core('CLMS_Submission');

		foreach ( $lesson_ids as $lesson_id ) {
			$quiz_grade     = $this->get_quiz_grade( $user_id, $lesson_id );
			$submission     = ( $submission_module && method_exists( $submission_module, 'get_user_submission_for_grading' ) )
				? $submission_module->get_user_submission_for_grading( $user_id, $lesson_id )
				: array();
			$record         = ! empty( $submission['submission_id'] ) ? $this->get_submission_grade_record( $submission['submission_id'] ) : array();
			$lesson_settings = $this->get_lesson_evaluation_settings( $lesson_id );
			$assignment     = isset( $record['grade'] ) ? $record['grade'] : '';
			$source         = isset( $record['grade_source'] ) ? (string) $record['grade_source'] : '';

			if ( null !== $quiz_grade ) {
				$quiz_scores[] = $quiz_grade;
			}

			if ( '' !== (string) $assignment ) {
				$assignment_scores[] = $assignment;
			}

			if ( $source ) {
				if ( ! isset( $source_breakdown[ $source ] ) ) {
					$source_breakdown[ $source ] = 0;
				}
				++$source_breakdown[ $source ];
			}

			$entries[] = array(
				'lesson_id'         => $lesson_id,
				'lesson_title'      => get_the_title( $lesson_id ),
				'evaluation_mode'   => $lesson_settings['mode'],
				'activity_type'     => $lesson_settings['activity_type'],
				'quiz_grade'        => null !== $quiz_grade ? $quiz_grade : '',
				'assignment_grade'  => $assignment,
				'grade_source'      => $source,
				'raw_confidence'    => isset( $record['raw_confidence'] ) ? $record['raw_confidence'] : '',
				'confidence'        => isset( $record['confidence'] ) ? $record['confidence'] : '',
				'confidence_profile' => isset( $record['confidence_profile'] ) ? $record['confidence_profile'] : '',
				'confidence_threshold' => $lesson_settings['ai_confidence_threshold'],
				'provider'          => isset( $record['provider'] ) ? $record['provider'] : '',
				'model_version'     => isset( $record['model_version'] ) ? $record['model_version'] : '',
				'manual_override'   => ! empty( $record['manual_override'] ),
				'assessment_audit'  => isset( $record['assessment_audit'] ) && is_array( $record['assessment_audit'] ) ? $record['assessment_audit'] : array(),
				'submission_id'     => isset( $submission['submission_id'] ) ? absint( $submission['submission_id'] ) : 0,
				'submission_status' => isset( $submission['status'] ) ? sanitize_key( (string) $submission['status'] ) : '',
				'completed'         => in_array( $lesson_id, $completed_lessons, true ),
			);
		}

		$total_lessons   = count( $lesson_ids );
		$completed_count = count( array_intersect( $lesson_ids, $completed_lessons ) );
		$quiz_average    = $this->calculate_average( $quiz_scores );
		$task_average    = $this->calculate_average( $assignment_scores );

		if ( $quiz_average > 0 && $task_average > 0 ) {
			$final_average = (int) round( ( $quiz_average + $task_average ) / 2 );
		} elseif ( $quiz_average > 0 ) {
			$final_average = $quiz_average;
		} else {
			$final_average = $task_average;
		}

		$summary = array(
			'completed_lessons'  => $completed_count,
			'total_lessons'      => $total_lessons,
			'progress_percent'   => $total_lessons > 0 ? (int) round( ( $completed_count / $total_lessons ) * 100 ) : 0,
			'quiz_average'       => $quiz_average,
			'assignment_average' => $task_average,
			'final_average'      => $final_average,
			'graded_lessons'     => count( $assignment_scores ),
			'source_breakdown'   => $source_breakdown,
			'updated_at'         => current_time( 'mysql' ),
		);

		$gradebook = array(
			'summary' => $summary,
			'entries' => $entries,
		);

		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::set( 'gradebook', array( 'entries', $user_id, $course_id ), $gradebook, self::GRADEBOOK_CACHE_TTL );
		}

		return $gradebook;
	}

	public function invalidate_cache_for_user_course( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id ) {
			return;
		}

		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::delete( 'gradebook', array( 'entries', $user_id, $course_id ) );
		}
	}

	public function invalidate_cache_from_lesson( $user_id, $lesson_id ) {
		$course_id = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_lesson_course_id( $lesson_id ) : 0;
		$this->invalidate_cache_for_user_course( $user_id, $course_id );
	}

	public function invalidate_cache_from_submission( $submission_id, $lesson_id, $user_id ) {
		unset( $submission_id );
		$course_id = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_lesson_course_id( $lesson_id ) : 0;
		$this->invalidate_cache_for_user_course( $user_id, $course_id );
	}

	public function invalidate_cache_from_course( $post_id, $post, $update ) {
		unset( $post );
		if ( ! $update || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::flush_course( $post_id );
		}
	}

	public function invalidate_cache_from_program( $post_id, $post, $update ) {
		unset( $post );
		if ( ! $update || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::flush_program( $post_id );
		}
	}

	public function store_course_gradebook( $user_id, $course_id, $gradebook ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$gradebook = is_array( $gradebook ) ? $gradebook : array();

		if ( ! $user_id || ! $course_id || empty( $gradebook ) ) {
			return false;
		}

		update_user_meta( $user_id, self::GRADEBOOK_META_PREFIX . $course_id, $gradebook );

		return true;
	}

	protected function generate_ai_assessment( $submission_id, $lesson_id, $mode ) {
		$submission_id = absint( $submission_id );
		$lesson_id     = absint( $lesson_id );
		$mode          = sanitize_key( (string) $mode );

		$criteria = class_exists( 'CLMS_AI_Grading' )
			? trim( (string) get_post_meta( $lesson_id, CLMS_AI_Grading::META_CRITERIA, true ) )
			: '';

		if ( '' !== $criteria ) {
			return $this->generate_natural_language_assessment( $submission_id, $lesson_id, $mode, $criteria );
		}

		$ai = clms_core('CLMS_AI');

		if ( ! $ai || ! method_exists( $ai, 'generate_submission_review' ) ) {
			return new WP_Error( 'ai_module_missing', __( 'El módulo IA no está disponible para esta actividad.', 'atora-lms' ) );
		}

		$review = $ai->generate_submission_review( $submission_id );

		if ( is_wp_error( $review ) ) {
			return $review;
		}

		$submission = clms_core('CLMS_Submission');
		if ( $submission && method_exists( $submission, 'get_ai_review_data' ) ) {
			$review = array_merge( $review, $submission->get_ai_review_data( $submission_id ) );
		}

		return array(
			'source'        => $mode,
			'grade'         => isset( $review['suggested_grade'] ) && '' !== (string) $review['suggested_grade'] ? max( 0, min( 100, absint( $review['suggested_grade'] ) ) ) : '',
			'feedback'      => isset( $review['feedback_draft'] ) ? wp_kses_post( $review['feedback_draft'] ) : '',
			'confidence'    => isset( $review['confidence'] ) && '' !== (string) $review['confidence'] ? max( 0.0, min( 1.0, (float) $review['confidence'] ) ) : 0.6,
			'provider'      => isset( $review['provider'] ) ? sanitize_key( (string) $review['provider'] ) : '',
			'model_version' => isset( $review['model'] ) ? sanitize_text_field( (string) $review['model'] ) : '',
			'raw'           => isset( $review['raw_response'] ) ? $review['raw_response'] : $review,
			'status'        => 'graded',
		);
	}

	protected function generate_natural_language_assessment( $submission_id, $lesson_id, $mode, $criteria ) {
		$submission_id = absint( $submission_id );
		$lesson_id     = absint( $lesson_id );
		$mode          = sanitize_key( (string) $mode );
		$criteria      = sanitize_textarea_field( (string) $criteria );

		$ai          = clms_core('CLMS_AI');
		$ai_grading  = clms_core('CLMS_AI_Grading');
		$submission  = clms_core('CLMS_Submission');

		if ( ! $ai || ! method_exists( $ai, 'build_submission_ai_payload' ) ) {
			return new WP_Error( 'ai_payload_missing', __( 'No se pudo construir el contexto de evaluación IA.', 'atora-lms' ) );
		}

		if ( ! $ai_grading || ! method_exists( $ai_grading, 'evaluate' ) ) {
			return new WP_Error( 'ai_grading_missing', __( 'No se encontró el evaluador IA de criterios naturales.', 'atora-lms' ) );
		}

		$payload = $ai->build_submission_ai_payload( $submission_id );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$text_bundle  = isset( $payload['text_bundle'] ) && is_array( $payload['text_bundle'] ) ? $payload['text_bundle'] : array();
		$combined_text = isset( $text_bundle['combined_text'] ) ? trim( (string) $text_bundle['combined_text'] ) : '';

		if ( '' === $combined_text ) {
			return new WP_Error( 'empty_submission_text', __( 'La entrega no tiene suficiente texto para evaluación IA automática.', 'atora-lms' ) );
		}

		$result = $ai_grading->evaluate( $combined_text, $criteria, $lesson_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $submission && method_exists( $submission, 'update_ai_review_data' ) ) {
			$submission->update_ai_review_data(
				$submission_id,
				array(
					'status'           => 'completed',
					'provider'         => isset( $result['provider'] ) ? $result['provider'] : '',
					'model'            => $this->get_active_ai_model( isset( $result['provider'] ) ? $result['provider'] : '' ),
					'summary'          => isset( $result['feedback'] ) ? $result['feedback'] : '',
					'feedback_draft'   => isset( $result['feedback'] ) ? $result['feedback'] : '',
					'confidence'       => isset( $result['confidence'] ) ? $result['confidence'] : '',
					'suggested_grade'  => isset( $result['score'] ) ? $result['score'] : '',
					'suggested_status' => 'graded',
					'raw_response'     => $result,
					'generated_at'     => isset( $result['evaluated_at'] ) ? $result['evaluated_at'] : current_time( 'mysql' ),
					'highlights'       => array_merge(
						isset( $result['strengths'] ) && is_array( $result['strengths'] ) ? $result['strengths'] : array(),
						isset( $result['improvements'] ) && is_array( $result['improvements'] ) ? $result['improvements'] : array()
					),
				)
			);
		}

		return array(
			'source'        => $mode,
			'grade'         => isset( $result['score'] ) ? max( 0, min( 100, absint( $result['score'] ) ) ) : '',
			'feedback'      => isset( $result['feedback'] ) ? sanitize_textarea_field( (string) $result['feedback'] ) : '',
			'confidence'    => isset( $result['confidence'] ) ? max( 0.0, min( 1.0, (float) $result['confidence'] ) ) : 0.5,
			'provider'      => isset( $result['provider'] ) ? sanitize_key( (string) $result['provider'] ) : '',
			'model_version' => $this->get_active_ai_model( isset( $result['provider'] ) ? $result['provider'] : '' ),
			'raw'           => $result,
			'status'        => 'graded',
		);
	}

	protected function store_assessment_result( $submission_id, $assessment ) {
		$submission_id = absint( $submission_id );
		$assessment    = is_array( $assessment ) ? $assessment : array();

		if ( ! $submission_id ) {
			return;
		}

		$grade      = isset( $assessment['grade'] ) && '' !== (string) $assessment['grade'] ? max( 0, min( 100, absint( $assessment['grade'] ) ) ) : '';
		$published  = ! empty( $assessment['published'] );
		$raw_confidence = isset( $assessment['raw_confidence'] ) && '' !== (string) $assessment['raw_confidence']
			? max( 0.0, min( 1.0, (float) $assessment['raw_confidence'] ) )
			: '';
		$confidence = isset( $assessment['confidence'] ) && '' !== (string) $assessment['confidence']
			? max( 0.0, min( 1.0, (float) $assessment['confidence'] ) )
			: '';
		$confidence_profile = isset( $assessment['confidence_profile'] ) ? sanitize_key( (string) $assessment['confidence_profile'] ) : '';
		$provider   = isset( $assessment['provider'] ) ? sanitize_key( (string) $assessment['provider'] ) : '';
		$model      = isset( $assessment['model_version'] ) ? sanitize_text_field( (string) $assessment['model_version'] ) : '';
		$source     = isset( $assessment['source'] ) ? sanitize_key( (string) $assessment['source'] ) : 'manual';
		$status     = isset( $assessment['status'] ) && $assessment['status'] ? sanitize_key( (string) $assessment['status'] ) : ( $published ? 'graded' : 'in_review' );
		$feedback   = isset( $assessment['feedback'] ) ? wp_kses_post( $assessment['feedback'] ) : '';
		$automation_level = isset( $assessment['automation_level'] ) ? sanitize_key( (string) $assessment['automation_level'] ) : '';
		$automation_level_requested = isset( $assessment['automation_level_requested'] ) ? sanitize_key( (string) $assessment['automation_level_requested'] ) : '';
		$automation_allowed = array_key_exists( 'automation_allowed', $assessment ) ? ! empty( $assessment['automation_allowed'] ) : true;
		$review_required = array_key_exists( 'review_required', $assessment ) ? ! empty( $assessment['review_required'] ) : false;
		$automation_rule = isset( $assessment['automation_rule'] ) ? sanitize_text_field( (string) $assessment['automation_rule'] ) : '';
		$note = isset( $assessment['note'] ) ? sanitize_text_field( (string) $assessment['note'] ) : '';

		update_post_meta( $submission_id, self::GRADE_SOURCE_META, $source );

		if ( '' !== (string) $raw_confidence ) {
			update_post_meta( $submission_id, self::GRADE_RAW_CONFIDENCE_META, $raw_confidence );
		} else {
			delete_post_meta( $submission_id, self::GRADE_RAW_CONFIDENCE_META );
		}

		if ( '' !== (string) $confidence ) {
			update_post_meta( $submission_id, self::GRADE_CONFIDENCE_META, $confidence );
		} else {
			delete_post_meta( $submission_id, self::GRADE_CONFIDENCE_META );
		}

		if ( $confidence_profile ) {
			update_post_meta( $submission_id, self::GRADE_CONFIDENCE_PROFILE_META, $confidence_profile );
		} else {
			delete_post_meta( $submission_id, self::GRADE_CONFIDENCE_PROFILE_META );
		}

		if ( $provider ) {
			update_post_meta( $submission_id, self::GRADE_PROVIDER_META, $provider );
		} else {
			delete_post_meta( $submission_id, self::GRADE_PROVIDER_META );
		}

		if ( $model ) {
			update_post_meta( $submission_id, self::GRADE_MODEL_VERSION_META, $model );
		} else {
			delete_post_meta( $submission_id, self::GRADE_MODEL_VERSION_META );
		}

		update_post_meta( $submission_id, self::GRADE_MANUAL_OVERRIDE_META, '0' );

		if ( $published && '' !== (string) $grade ) {
			$this->publish_submission_grade(
				$submission_id,
				array(
					'grade'            => $grade,
					'feedback'         => $feedback,
					'status'           => $status,
					'source'           => $source,
					'rubric_scores'    => isset( $assessment['rubric_scores'] ) && is_array( $assessment['rubric_scores'] ) ? $assessment['rubric_scores'] : array(),
					'ai_confidence'    => '' !== (string) $confidence ? $confidence : '',
					'ai_confidence_raw'=> '' !== (string) $raw_confidence ? $raw_confidence : '',
					'ai_provider'      => $provider,
					'ai_model_version' => $model,
					'audit_payload'    => array(
						'trigger'         => isset( $assessment['trigger'] ) ? sanitize_key( (string) $assessment['trigger'] ) : 'system',
						'evaluation_mode' => isset( $assessment['evaluation_mode'] ) ? sanitize_key( (string) $assessment['evaluation_mode'] ) : '',
						'activity_type'   => isset( $assessment['activity_type'] ) ? sanitize_key( (string) $assessment['activity_type'] ) : '',
						'threshold_applied' => isset( $assessment['threshold_applied'] ) ? $assessment['threshold_applied'] : '',
						'automation_level' => $automation_level,
						'automation_level_requested' => $automation_level_requested,
						'automation_allowed' => $automation_allowed,
						'review_required' => $review_required,
						'automation_rule' => $automation_rule,
						'note'            => $note,
						'raw'             => isset( $assessment['raw'] ) ? $assessment['raw'] : array(),
					),
				)
			);
		} elseif ( 'submitted' === (string) get_post_meta( $submission_id, '_clms_submission_status', true ) ) {
			update_post_meta( $submission_id, '_clms_submission_status', 'in_review' );
		}

		$this->log_audit_event(
			$submission_id,
			array(
				'source'          => $source,
				'evaluation_mode' => isset( $assessment['evaluation_mode'] ) ? sanitize_key( (string) $assessment['evaluation_mode'] ) : '',
				'grade'           => $grade,
				'raw_confidence'  => $raw_confidence,
				'confidence'      => $confidence,
				'confidence_profile' => $confidence_profile,
				'provider'        => $provider,
				'model_version'   => $model,
				'override_manual' => false,
				'published'       => $published,
				'status'          => $status,
				'trigger'         => isset( $assessment['trigger'] ) ? sanitize_key( (string) $assessment['trigger'] ) : 'system',
				'threshold_applied' => isset( $assessment['threshold_applied'] ) && '' !== (string) $assessment['threshold_applied'] ? max( 0.5, min( 0.99, (float) $assessment['threshold_applied'] ) ) : '',
				'activity_type'   => isset( $assessment['activity_type'] ) ? sanitize_key( (string) $assessment['activity_type'] ) : '',
				'automation_level' => $automation_level,
				'automation_level_requested' => $automation_level_requested,
				'automation_allowed' => $automation_allowed,
				'review_required' => $review_required,
				'automation_rule' => $automation_rule,
				'note'            => $note,
				'raw'             => isset( $assessment['raw'] ) ? $assessment['raw'] : array(),
			)
		);
	}

	/**
	 * Punto central para publicar una calificación de entrega.
	 *
	 * @param int   $submission_id ID de entrega.
	 * @param array $data          Datos de publicación.
	 * @return array|WP_Error
	 */
	public function publish_submission_grade( $submission_id, $data = array() ) {
		$submission_id = absint( $submission_id );
		$data          = is_array( $data ) ? $data : array();

		if ( ! $submission_id || 'clms_submission' !== get_post_type( $submission_id ) ) {
			return new WP_Error( 'invalid_submission', __( 'La entrega no es válida para publicar calificación.', 'atora-lms' ) );
		}

		$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		if ( ! $student_id ) {
			$student_id = absint( get_post_meta( $submission_id, '_clms_submission_student_id', true ) );
		}
		if ( ! $student_id ) {
			$student_id = absint( get_post_field( 'post_author', $submission_id ) );
		}
		$lesson_id  = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$course_id  = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );

		if ( ! $course_id && $lesson_id && class_exists( 'CLMS_Helper' ) ) {
			$course_id = absint( CLMS_Helper::get_lesson_course_id( $lesson_id ) );
		}

		if ( ! $student_id || ! $lesson_id ) {
			return new WP_Error( 'missing_submission_context', __( 'No se pudo resolver estudiante o lección de la entrega.', 'atora-lms' ) );
		}

		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : 'graded';
		if ( ! in_array( $status, self::GRADE_STATUSES, true ) ) {
			$status = 'graded';
		}

		$grade = isset( $data['grade'] ) && '' !== (string) $data['grade']
			? max( 0, min( 100, (float) $data['grade'] ) )
			: '';
		$grade = '' !== (string) $grade ? (int) round( $grade ) : '';
		$is_grade_published = ( 'graded' === $status && '' !== (string) $grade );

		$feedback = isset( $data['feedback'] ) ? wp_kses_post( (string) $data['feedback'] ) : '';
		$source   = isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : 'manual';
		$source   = in_array( $source, array( 'manual', 'ai_assisted', 'ai_auto_grade', 'peer_review', 'quiz', 'rubric', 'hybrid' ), true ) ? $source : 'manual';
		$rubric_scores = isset( $data['rubric_scores'] ) && is_array( $data['rubric_scores'] ) ? $data['rubric_scores'] : array();
		$has_rubric_score = false;
		foreach ( $rubric_scores as $row ) {
			$row   = is_array( $row ) ? $row : array();
			$score = isset( $row['score'] ) ? trim( (string) $row['score'] ) : '';
			if ( '' !== $score ) {
				$has_rubric_score = true;
				break;
			}
		}
		if ( 'rubric' === $source && ! $has_rubric_score ) {
			$source = 'manual';
		}

		update_post_meta( $submission_id, '_clms_submission_status', $status );
		update_post_meta( $submission_id, '_clms_submission_feedback', $feedback );

		if ( '' !== (string) $grade ) {
			update_post_meta( $submission_id, '_clms_submission_grade', $grade );
		} else {
			delete_post_meta( $submission_id, '_clms_submission_grade' );
		}

		update_post_meta( $submission_id, self::GRADE_SOURCE_META, $source );
		update_post_meta( $submission_id, self::GRADE_MANUAL_OVERRIDE_META, 'manual' === $source ? '1' : '0' );

		if ( ! empty( $rubric_scores ) ) {
			update_post_meta( $submission_id, '_clms_submission_rubric_scores', $rubric_scores );
		} else {
			delete_post_meta( $submission_id, '_clms_submission_rubric_scores' );
		}

		$confidence = isset( $data['ai_confidence'] ) && '' !== (string) $data['ai_confidence']
			? max( 0.0, min( 1.0, (float) $data['ai_confidence'] ) )
			: '';
		$raw_confidence = isset( $data['ai_confidence_raw'] ) && '' !== (string) $data['ai_confidence_raw']
			? max( 0.0, min( 1.0, (float) $data['ai_confidence_raw'] ) )
			: '';
		$provider = isset( $data['ai_provider'] ) ? sanitize_key( (string) $data['ai_provider'] ) : '';
		$model    = isset( $data['ai_model_version'] ) ? sanitize_text_field( (string) $data['ai_model_version'] ) : '';

		if ( '' !== (string) $raw_confidence ) {
			update_post_meta( $submission_id, self::GRADE_RAW_CONFIDENCE_META, $raw_confidence );
		}
		if ( '' !== (string) $confidence ) {
			update_post_meta( $submission_id, self::GRADE_CONFIDENCE_META, $confidence );
		}
		if ( $provider ) {
			update_post_meta( $submission_id, self::GRADE_PROVIDER_META, $provider );
		}
		if ( $model ) {
			update_post_meta( $submission_id, self::GRADE_MODEL_VERSION_META, $model );
		}

		$audit_payload = isset( $data['audit_payload'] ) && is_array( $data['audit_payload'] ) ? $data['audit_payload'] : array();
		$this->log_audit_event(
			$submission_id,
			array_merge(
				array(
					'source'          => $source,
					'evaluation_mode' => isset( $audit_payload['evaluation_mode'] ) ? sanitize_key( (string) $audit_payload['evaluation_mode'] ) : $this->get_lesson_evaluation_mode_for_submission( $submission_id ),
					'grade'           => $grade,
					'raw_confidence'  => $raw_confidence,
					'confidence'      => $confidence,
					'provider'        => $provider,
					'model_version'   => $model,
					'override_manual' => 'manual' === $source,
					'published'       => $is_grade_published,
					'status'          => $status,
					'trigger'         => isset( $audit_payload['trigger'] ) ? sanitize_key( (string) $audit_payload['trigger'] ) : 'publish_grade',
					'activity_type'   => isset( $audit_payload['activity_type'] ) ? sanitize_key( (string) $audit_payload['activity_type'] ) : $this->get_lesson_activity_type( $lesson_id ),
					'threshold_applied' => isset( $audit_payload['threshold_applied'] ) ? (float) $audit_payload['threshold_applied'] : '',
					'automation_level' => isset( $audit_payload['automation_level'] ) ? sanitize_key( (string) $audit_payload['automation_level'] ) : '',
					'automation_level_requested' => isset( $audit_payload['automation_level_requested'] ) ? sanitize_key( (string) $audit_payload['automation_level_requested'] ) : '',
					'automation_allowed' => isset( $audit_payload['automation_allowed'] ) ? (bool) $audit_payload['automation_allowed'] : true,
					'review_required' => isset( $audit_payload['review_required'] ) ? (bool) $audit_payload['review_required'] : false,
					'automation_rule' => isset( $audit_payload['automation_rule'] ) ? sanitize_text_field( (string) $audit_payload['automation_rule'] ) : '',
					'note'            => isset( $audit_payload['note'] ) ? sanitize_text_field( (string) $audit_payload['note'] ) : '',
					'raw'             => isset( $audit_payload['raw'] ) ? $audit_payload['raw'] : array(),
				)
			)
		);

		$this->invalidate_cache_for_user_course( $student_id, $course_id );

		$grading = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading') : null;
		if ( $grading && method_exists( $grading, 'calculate_and_store_course_grade' ) ) {
			$grading->calculate_and_store_course_grade( $student_id, $course_id );
		}

		$grading_engine = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading_Engine') : null;
		if ( ! $grading_engine && class_exists( 'CLMS_Grading_Engine' ) ) {
			$grading_engine = new CLMS_Grading_Engine();
		}
		if ( $grading_engine && method_exists( $grading_engine, 'invalidate_grade_cache' ) ) {
			$grading_engine->invalidate_grade_cache( $student_id, $course_id );
		}

		do_action( 'clms_submission_graded', $submission_id, $student_id, $status, $grade, $feedback );

		$payload = array(
			'submission_id' => $submission_id,
			'student_id'    => $student_id,
			'lesson_id'     => $lesson_id,
			'course_id'     => $course_id,
			'status'        => $status,
			'grade'         => $grade,
			'feedback'      => $feedback,
			'source'        => $source,
			'confidence'    => $confidence,
			'provider'      => $provider,
			'model_version' => $model,
			'is_published'  => $is_grade_published,
		);

		if ( $is_grade_published ) {
			do_action( 'clms_grade_published', $payload );
		}

		return $payload;
	}

	protected function log_audit_event( $submission_id, $event ) {
		$submission_id = absint( $submission_id );
		$event         = is_array( $event ) ? $event : array();

		if ( ! $submission_id ) {
			return;
		}

		$defaults = array(
			'recorded_at'     => current_time( 'mysql' ),
			'source'          => '',
			'evaluation_mode' => '',
			'grade'           => '',
			'raw_confidence'  => '',
			'confidence'      => '',
			'confidence_profile' => '',
			'provider'        => '',
			'model_version'   => '',
			'override_manual' => false,
			'published'       => false,
			'status'          => '',
			'trigger'         => '',
			'threshold_applied' => '',
			'activity_type'   => '',
			'automation_level' => '',
			'automation_level_requested' => '',
			'automation_allowed' => true,
			'review_required' => false,
			'automation_rule' => '',
			'note'            => '',
			'raw'             => array(),
		);

		$event = array_merge( $defaults, $event );

		$log = get_post_meta( $submission_id, self::ASSESSMENT_AUDIT_LOG_META, true );
		$log = is_array( $log ) ? array_values( $log ) : array();
		$log[] = $event;

		if ( count( $log ) > 20 ) {
			$log = array_slice( $log, -20 );
		}

		update_post_meta( $submission_id, self::ASSESSMENT_AUDIT_LOG_META, $log );
		update_post_meta( $submission_id, self::ASSESSMENT_LAST_RESULT_META, $event );
	}

	protected function lesson_supports_ai_assessment( $lesson_id ) {
		$lesson_id = absint( $lesson_id );

		if ( ! $lesson_id ) {
			return false;
		}

		$rubric_id   = absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) );
		$nl_criteria = class_exists( 'CLMS_AI_Grading' )
			? trim( (string) get_post_meta( $lesson_id, CLMS_AI_Grading::META_CRITERIA, true ) )
			: '';

		return ( $rubric_id > 0 || '' !== $nl_criteria );
	}

	protected function get_lesson_evaluation_mode_for_submission( $submission_id ) {
		$lesson_id = absint( get_post_meta( absint( $submission_id ), '_clms_submission_lesson_id', true ) );
		$settings  = $this->get_lesson_evaluation_settings( $lesson_id );

		return isset( $settings['mode'] ) ? $settings['mode'] : 'manual';
	}

	protected function has_manual_override( $submission_id ) {
		return (bool) get_post_meta( absint( $submission_id ), self::GRADE_MANUAL_OVERRIDE_META, true );
	}

	protected function infer_grade_source( $submission_id, $grade ) {
		$submission_id = absint( $submission_id );

		if ( (bool) get_post_meta( $submission_id, self::GRADE_MANUAL_OVERRIDE_META, true ) ) {
			return 'manual';
		}

		if ( '' !== (string) get_post_meta( $submission_id, '_clms_final_grade', true ) ) {
			return 'peer_review';
		}

		return '' !== (string) $grade ? 'manual' : '';
	}

	protected function calculate_average( $values ) {
		$values = is_array( $values ) ? array_filter( array_map( 'absint', $values ), static function( $value ) {
			return $value >= 0;
		} ) : array();

		if ( empty( $values ) ) {
			return 0;
		}

		return (int) round( array_sum( $values ) / count( $values ) );
	}

	/**
	 * Lista de niveles de automatización válidos.
	 *
	 * @return array<int,string>
	 */
	protected function get_valid_automation_levels() {
		return array( 'assisted', 'semi', 'auto' );
	}

	/**
	 * Lee el nivel de automatización de la lección y lo persiste si falta.
	 *
	 * @param int    $lesson_id      ID de lección.
	 * @param string $fallback_level Nivel fallback (derivado del modo).
	 * @return string
	 */
	protected function get_lesson_automation_level( $lesson_id, $fallback_level = 'assisted' ) {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id ) {
			return 'assisted';
		}

		$valid_levels   = $this->get_valid_automation_levels();
		$fallback_level = sanitize_key( (string) $fallback_level );
		if ( ! in_array( $fallback_level, $valid_levels, true ) ) {
			$fallback_level = 'assisted';
		}

		$stored_level = sanitize_key( (string) get_post_meta( $lesson_id, self::AUTOMATION_LEVEL_META, true ) );
		if ( in_array( $stored_level, $valid_levels, true ) ) {
			return $stored_level;
		}

		update_post_meta( $lesson_id, self::AUTOMATION_LEVEL_META, $fallback_level );
		return $fallback_level;
	}

	/**
	 * Resuelve política de automatización para una evaluación.
	 *
	 * @param array  $args                     Argumentos de ejecución.
	 * @param string $mode                     Modo de evaluación legacy de la lección.
	 * @param string $activity_type            Tipo de actividad (lectura|tarea|quiz).
	 * @param string $lesson_automation_level  Nivel persistido en meta de lección.
	 * @return array{requested:string,effective:string,allowed:bool,rule:string}
	 */
	protected function resolve_automation_policy( $args, $mode, $activity_type, $lesson_automation_level = '' ) {
		$args                    = is_array( $args ) ? $args : array();
		$mode                    = sanitize_key( (string) $mode );
		$activity_type           = sanitize_key( (string) $activity_type );
		$lesson_automation_level = sanitize_key( (string) $lesson_automation_level );
		$valid_levels            = $this->get_valid_automation_levels();

		$requested = isset( $args['automation_level'] ) ? sanitize_key( (string) $args['automation_level'] ) : '';
		if ( ! in_array( $requested, $valid_levels, true ) ) {
			if ( in_array( $lesson_automation_level, $valid_levels, true ) ) {
				$requested = $lesson_automation_level;
			} else {
				$requested = $this->resolve_automation_level_from_mode( $mode );
			}
		}
		if ( ! in_array( $requested, $valid_levels, true ) ) {
			$requested = 'assisted';
		}

		$allowed_matrix = $this->get_allowed_automation_levels_by_activity();
		$allowed_levels = isset( $allowed_matrix[ $activity_type ] ) ? $allowed_matrix[ $activity_type ] : $allowed_matrix['lectura'];
		$is_allowed     = in_array( $requested, $allowed_levels, true );
		$effective      = $is_allowed ? $requested : 'assisted';

		return array(
			'requested' => $requested,
			'effective' => $effective,
			'allowed'   => $is_allowed,
			'rule'      => $is_allowed ? 'allowed' : 'blocked_by_activity',
		);
	}

	/**
	 * Mapea modo legacy de evaluación al nivel explícito de automatización.
	 *
	 * @param string $mode manual|ai_assisted|ai_auto_grade|peer_review|hybrid
	 * @return string assisted|semi|auto
	 */
	protected function resolve_automation_level_from_mode( $mode ) {
		$mode = sanitize_key( (string) $mode );

		if ( 'ai_auto_grade' === $mode ) {
			return 'auto';
		}

		if ( 'hybrid' === $mode ) {
			return 'semi';
		}

		return 'assisted';
	}

	/**
	 * Matriz mínima de elegibilidad por tipo de actividad.
	 *
	 * @return array<string,array<int,string>>
	 */
	protected function get_allowed_automation_levels_by_activity() {
		return array(
			'lectura' => array( 'assisted' ),
			'tarea'   => array( 'assisted', 'semi' ),
			'quiz'    => array( 'assisted', 'semi', 'auto' ),
		);
	}

	protected function get_quiz_grade( $user_id, $lesson_id ) {
		$key   = 'clms_quiz_attempt_' . absint( $lesson_id );
		$value = get_user_meta( absint( $user_id ), $key, true );

		if ( is_array( $value ) && isset( $value['score'] ) ) {
			return max( 0, min( 100, absint( $value['score'] ) ) );
		}

		if ( '' !== (string) $value ) {
			return max( 0, min( 100, absint( $value ) ) );
		}

		return null;
	}

	protected function get_active_ai_model( $provider = '' ) {
		$provider = sanitize_key( (string) $provider );
		$manager  = clms_core('CLMS_AI_Manager');

		if ( ! $manager ) {
			return '';
		}

		if ( '' === $provider && method_exists( $manager, 'get_active_provider_slug' ) ) {
			$provider = (string) $manager->get_active_provider_slug();
		}

		if ( $provider && method_exists( $manager, 'get_model' ) ) {
			return (string) $manager->get_model( $provider );
		}

		return '';
	}

	protected function get_empty_gradebook_summary() {
		return array(
			'completed_lessons'  => 0,
			'total_lessons'      => 0,
			'progress_percent'   => 0,
			'quiz_average'       => 0,
			'assignment_average' => 0,
			'final_average'      => 0,
			'graded_lessons'     => 0,
			'source_breakdown'   => array(),
			'updated_at'         => '',
		);
	}

	protected function schedule_automatic_assessment( $submission_id, $lesson_id, $trigger = 'system' ) {
		$submission_id = absint( $submission_id );
		$lesson_id     = absint( $lesson_id );
		$trigger       = sanitize_key( (string) $trigger );

		if ( ! $submission_id || ! $lesson_id ) {
			return false;
		}

		$submission = clms_core('CLMS_Submission');
		if ( $submission && method_exists( $submission, 'update_ai_review_data' ) ) {
			$submission->update_ai_review_data(
				$submission_id,
				array(
					'status'     => 'queued',
					'updated_at' => current_time( 'mysql' ),
					'error'      => '',
				)
			);
		}

		$this->log_audit_event(
			$submission_id,
			array(
				'source'          => 'ai_auto_grade',
				'evaluation_mode' => 'ai_auto_grade',
				'published'       => false,
				'status'          => 'queued',
				'trigger'         => $trigger,
				'note'            => 'Evaluación IA enviada a cola interna WP-Cron.',
				'activity_type'   => $this->get_lesson_activity_type( $lesson_id ),
				'threshold_applied' => $this->get_lesson_evaluation_settings( $lesson_id )['ai_confidence_threshold'],
				'automation_level' => 'auto',
				'automation_level_requested' => 'auto',
				'automation_allowed' => true,
				'review_required' => false,
				'automation_rule' => 'scheduled_auto_grade',
			)
		);

		if ( ! wp_next_scheduled( self::AUTO_GRADE_CRON_HOOK, array( $submission_id ) ) ) {
			wp_schedule_single_event(
				time() + self::AUTO_GRADE_QUEUE_DELAY,
				self::AUTO_GRADE_CRON_HOOK,
				array( $submission_id )
			);
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return true;
	}

	protected function get_lesson_activity_type( $lesson_id ) {
		$lesson_id = absint( $lesson_id );

		if ( ! $lesson_id ) {
			return 'lectura';
		}

		$type = sanitize_key( (string) get_post_meta( $lesson_id, self::ACTIVITY_MODE_META, true ) );

		if ( ! $type ) {
			$type = sanitize_key( (string) get_post_meta( $lesson_id, 'lm_activity_type', true ) );
		}

		$aliases = array(
			'quiz'       => 'quiz',
			'evaluacion' => 'quiz',
			'evaluation' => 'quiz',
			'tarea'      => 'tarea',
			'task'       => 'tarea',
			'assignment' => 'tarea',
			'lectura'    => 'lectura',
			'reading'    => 'lectura',
		);

		return isset( $aliases[ $type ] ) ? $aliases[ $type ] : 'lectura';
	}

	protected function get_default_ai_thresholds() {
		return array(
			'lectura' => self::DEFAULT_AI_CONFIDENCE_THRESHOLD,
			'tarea'   => self::DEFAULT_AI_CONFIDENCE_THRESHOLD,
			'quiz'    => self::DEFAULT_AI_CONFIDENCE_THRESHOLD,
		);
	}

	protected function get_lesson_ai_confidence_thresholds( $lesson_id, $threshold_default = 0 ) {
		$lesson_id = absint( $lesson_id );
		$defaults  = $this->get_default_ai_thresholds();
		$stored    = get_post_meta( $lesson_id, self::AI_CONFIDENCE_THRESHOLDS_META, true );
		$stored    = is_array( $stored ) ? $stored : array();
		$fallback  = $threshold_default > 0 ? max( 0.5, min( 0.99, (float) $threshold_default ) ) : self::DEFAULT_AI_CONFIDENCE_THRESHOLD;

		foreach ( $defaults as $activity_type => $default_threshold ) {
			$threshold = isset( $stored[ $activity_type ] ) && '' !== (string) $stored[ $activity_type ]
				? (float) $stored[ $activity_type ]
				: $fallback;
			$defaults[ $activity_type ] = max( 0.5, min( 0.99, $threshold > 0 ? $threshold : $default_threshold ) );
		}

		return $defaults;
	}

	protected function get_resolved_lesson_ai_threshold( $lesson_id, $activity_type = '', $threshold_default = 0, $thresholds_by_type = array() ) {
		$lesson_id          = absint( $lesson_id );
		$activity_type      = $activity_type ? sanitize_key( (string) $activity_type ) : $this->get_lesson_activity_type( $lesson_id );
		$thresholds_by_type = is_array( $thresholds_by_type ) ? $thresholds_by_type : array();

		if ( empty( $thresholds_by_type ) ) {
			$thresholds_by_type = $this->get_lesson_ai_confidence_thresholds( $lesson_id, $threshold_default );
		}

		if ( isset( $thresholds_by_type[ $activity_type ] ) ) {
			return max( 0.5, min( 0.99, (float) $thresholds_by_type[ $activity_type ] ) );
		}

		if ( $threshold_default > 0 ) {
			return max( 0.5, min( 0.99, (float) $threshold_default ) );
		}

		return self::DEFAULT_AI_CONFIDENCE_THRESHOLD;
	}

	protected function normalize_confidence_score( $confidence, $provider = '', $model = '' ) {
		$confidence = max( 0.0, min( 1.0, (float) $confidence ) );
		$profile    = $this->get_confidence_profile( $provider, $model );
		$center     = isset( $profile['center'] ) ? (float) $profile['center'] : 0.5;
		$scale      = isset( $profile['scale'] ) ? (float) $profile['scale'] : 1.0;
		$bias       = isset( $profile['bias'] ) ? (float) $profile['bias'] : 0.0;

		$normalized = $center + ( ( $confidence - $center ) * $scale ) + $bias;

		return max( 0.0, min( 1.0, round( $normalized, 4 ) ) );
	}

	protected function get_confidence_profile( $provider = '', $model = '' ) {
		$provider = sanitize_key( (string) $provider );
		$model    = strtolower( sanitize_text_field( (string) $model ) );

		$profile = array(
			'center' => 0.5,
			'scale'  => 0.94,
			'bias'   => 0.0,
		);

		if ( 'openai' === $provider ) {
			$profile['scale'] = false !== strpos( $model, 'mini' ) ? 0.88 : 0.95;
		} elseif ( 'anthropic' === $provider ) {
			$profile['scale'] = false !== strpos( $model, 'haiku' ) ? 0.86 : 0.93;
		} elseif ( 'gemini' === $provider ) {
			$profile['scale'] = false !== strpos( $model, 'flash' ) ? 0.87 : 0.93;
		} elseif ( 'mock-local' === $provider ) {
			$profile['scale'] = 0.82;
		}

		return apply_filters( 'clms_assessment_confidence_profile', $profile, $provider, $model );
	}

	protected function get_confidence_profile_key( $provider = '', $model = '' ) {
		$provider = sanitize_key( (string) $provider );
		$model    = strtolower( sanitize_text_field( (string) $model ) );

		if ( ! $provider ) {
			return '';
		}

		if ( 'openai' === $provider && false !== strpos( $model, 'mini' ) ) {
			return 'openai_mini';
		}

		if ( 'anthropic' === $provider && false !== strpos( $model, 'haiku' ) ) {
			return 'anthropic_haiku';
		}

		if ( 'gemini' === $provider && false !== strpos( $model, 'flash' ) ) {
			return 'gemini_flash';
		}

		return $provider . '_default';
	}
}
