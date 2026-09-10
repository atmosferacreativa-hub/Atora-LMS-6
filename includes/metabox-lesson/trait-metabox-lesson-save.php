<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Metabox_Lesson_Save_Trait {
	public function save_metabox( $post_id, $post ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || ! $post || 'lm_lesson' !== $post->post_type ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! CLMS_Helper::user_can_manage_lms( $post_id ) ) {
			return;
		}

			$course_id        = isset( $_POST['clms_course_id'] ) ? absint( wp_unslash( $_POST['clms_course_id'] ) ) : 0;
			$activity_type    = isset( $_POST['lm_activity_type'] ) ? $this->normalize_activity_type( wp_unslash( $_POST['lm_activity_type'] ) ) : 'lectura';
			$delivery_mode    = isset( $_POST['_clms_delivery_mode'] ) ? sanitize_key( wp_unslash( $_POST['_clms_delivery_mode'] ) ) : '';
		$session_type     = isset( $_POST['lm_session_type'] ) ? sanitize_key( wp_unslash( $_POST['lm_session_type'] ) ) : 'asincrono';
		$live_provider    = isset( $_POST[ self::LIVE_CLASS_PROVIDER ] ) ? sanitize_key( wp_unslash( $_POST[ self::LIVE_CLASS_PROVIDER ] ) ) : 'zoom';
		$live_url         = isset( $_POST[ self::LIVE_CLASS_URL ] ) ? esc_url_raw( wp_unslash( $_POST[ self::LIVE_CLASS_URL ] ) ) : '';
		$live_starts_at   = isset( $_POST[ self::LIVE_CLASS_STARTS_AT ] ) ? $this->sanitize_datetime_local( wp_unslash( $_POST[ self::LIVE_CLASS_STARTS_AT ] ) ) : '';
		$live_ends_at     = isset( $_POST[ self::LIVE_CLASS_ENDS_AT ] ) ? $this->sanitize_datetime_local( wp_unslash( $_POST[ self::LIVE_CLASS_ENDS_AT ] ) ) : '';
		$live_timezone    = isset( $_POST[ self::LIVE_CLASS_TIMEZONE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::LIVE_CLASS_TIMEZONE ] ) ) : '';
		$live_notes       = isset( $_POST[ self::LIVE_CLASS_NOTES ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::LIVE_CLASS_NOTES ] ) ) : '';
		$lesson_module    = isset( $_POST[ self::LESSON_MODULE_META ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::LESSON_MODULE_META ] ) ) : '';
		$lesson_order     = isset( $_POST['clms_lesson_menu_order'] ) ? max( 0, absint( wp_unslash( $_POST['clms_lesson_menu_order'] ) ) ) : (int) get_post_field( 'menu_order', $post_id );
		$task_title       = isset( $_POST['lm_task_title'] ) ? sanitize_text_field( wp_unslash( $_POST['lm_task_title'] ) ) : '';
		$task_description = isset( $_POST['lm_task_description'] ) ? wp_kses_post( wp_unslash( $_POST['lm_task_description'] ) ) : '';
		$show_in_calendar = isset( $_POST['lm_show_in_calendar'] ) ? ( '1' === wp_unslash( $_POST['lm_show_in_calendar'] ) ? '1' : '0' ) : '0';

		$due_date  = isset( $_POST['lm_due_date'] ) ? $this->sanitize_date( wp_unslash( $_POST['lm_due_date'] ) ) : '';
		$due_time  = isset( $_POST['lm_due_time'] ) ? $this->sanitize_time( wp_unslash( $_POST['lm_due_time'] ) ) : '';
		$late_date = isset( $_POST['lm_late_date'] ) ? $this->sanitize_date( wp_unslash( $_POST['lm_late_date'] ) ) : '';
		$late_time = isset( $_POST['lm_late_time'] ) ? $this->sanitize_time( wp_unslash( $_POST['lm_late_time'] ) ) : '';

		$lesson_subtitle  = isset( $_POST[ self::LESSON_SUBTITLE_META ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::LESSON_SUBTITLE_META ] ) ) : '';
		$lesson_public_snippet = isset( $_POST[ self::LESSON_PUBLIC_SNIPPET ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::LESSON_PUBLIC_SNIPPET ] ) ) : '';
		$lesson_cover_id  = isset( $_POST[ self::LESSON_COVER_IMAGE_ID ] ) ? absint( wp_unslash( $_POST[ self::LESSON_COVER_IMAGE_ID ] ) ) : 0;

		$video_count      = isset( $_POST[ self::LESSON_VIDEO_COUNT ] ) ? max( 1, absint( wp_unslash( $_POST[ self::LESSON_VIDEO_COUNT ] ) ) ) : 1;
		$ui_limit_videos  = isset( $_POST[ self::LESSON_UI_LIMIT_VIDEOS ] ) ? max( 0, absint( wp_unslash( $_POST[ self::LESSON_UI_LIMIT_VIDEOS ] ) ) ) : 0;
		$ui_limit_resources = isset( $_POST[ self::LESSON_UI_LIMIT_RESOURCES ] ) ? max( 0, absint( wp_unslash( $_POST[ self::LESSON_UI_LIMIT_RESOURCES ] ) ) ) : 0;
		$ui_limit_tips    = isset( $_POST[ self::LESSON_UI_LIMIT_TIPS ] ) ? max( 0, absint( wp_unslash( $_POST[ self::LESSON_UI_LIMIT_TIPS ] ) ) ) : 0;
		$extra_videos_raw = isset( $_POST[ self::LESSON_EXTRA_VIDEOS ] ) ? wp_unslash( $_POST[ self::LESSON_EXTRA_VIDEOS ] ) : array();
		$extra_videos     = $this->sanitize_extra_videos( $extra_videos_raw );

		// Sincronizar el contador con los videos que realmente tienen URL.
		$real_count  = count( $extra_videos );
		$video_count = $real_count > 0 ? $real_count : 1;

		// Derivar campos legacy desde video[0] para compatibilidad con el frontend
		$video_url    = isset( $extra_videos[0]['url'] ) ? $extra_videos[0]['url'] : '';
		$video_source = isset( $extra_videos[0]['source'] ) ? $extra_videos[0]['source'] : 'youtube';
		$tip_1        = isset( $extra_videos[0]['tip_1'] ) ? $extra_videos[0]['tip_1'] : '';
		$tip_2        = isset( $extra_videos[0]['tip_2'] ) ? $extra_videos[0]['tip_2'] : '';
		$tip_3        = isset( $extra_videos[0]['tip_3'] ) ? $extra_videos[0]['tip_3'] : '';

		$quiz_enabled              = isset( $_POST[ self::QUIZ_ENABLED_META_KEY ] ) ? ( '1' === wp_unslash( $_POST[ self::QUIZ_ENABLED_META_KEY ] ) ? '1' : '0' ) : '0';
		$peer_review_enabled       = isset( $_POST['_clms_peer_review_enabled'] ) ? ( '1' === wp_unslash( $_POST['_clms_peer_review_enabled'] ) ? '1' : '0' ) : '0';
		$peer_reviews_per_student  = isset( $_POST['_clms_peer_reviews_per_student'] ) ? max( 1, min( 5, absint( wp_unslash( $_POST['_clms_peer_reviews_per_student'] ) ) ) ) : 2;
		$peer_review_blind         = isset( $_POST['_clms_peer_review_blind'] ) ? ( '1' === wp_unslash( $_POST['_clms_peer_review_blind'] ) ? '1' : '0' ) : '0';
		$peer_review_calibration_enabled = isset( $_POST['_clms_pr_calibration_enabled'] ) ? ( '1' === wp_unslash( $_POST['_clms_pr_calibration_enabled'] ) ? '1' : '0' ) : '0';
		$peer_review_calibration_submission_id = isset( $_POST['_clms_pr_calibration_submission_id'] ) ? absint( wp_unslash( $_POST['_clms_pr_calibration_submission_id'] ) ) : 0;
		$peer_review_calibration_teacher_grade = isset( $_POST['_clms_pr_calibration_teacher_grade'] ) ? max( 0, min( 100, absint( wp_unslash( $_POST['_clms_pr_calibration_teacher_grade'] ) ) ) ) : '';
		$assessment_mode           = isset( $_POST['_clms_evaluation_mode'] ) ? sanitize_key( wp_unslash( $_POST['_clms_evaluation_mode'] ) ) : 'manual';
		$ai_confidence_threshold   = isset( $_POST['_clms_ai_confidence_threshold'] ) ? (float) wp_unslash( $_POST['_clms_ai_confidence_threshold'] ) : 0.75;
		$ai_confidence_thresholds  = isset( $_POST['_clms_ai_confidence_thresholds'] ) ? (array) wp_unslash( $_POST['_clms_ai_confidence_thresholds'] ) : array();
		$quiz_has_eval          = isset( $_POST['_lm_quiz_has_eval'] ) ? sanitize_key( wp_unslash( $_POST['_lm_quiz_has_eval'] ) ) : (string) get_post_meta( $post_id, '_lm_quiz_has_eval', true );
		$quiz_eval_mode         = isset( $_POST['_lm_quiz_eval_mode'] ) ? sanitize_key( wp_unslash( $_POST['_lm_quiz_eval_mode'] ) ) : (string) get_post_meta( $post_id, '_lm_quiz_eval_mode', true );
		$quiz_eval_type         = isset( $_POST['_lm_quiz_eval_type'] ) ? sanitize_key( wp_unslash( $_POST['_lm_quiz_eval_type'] ) ) : (string) get_post_meta( $post_id, '_lm_quiz_eval_type', true );
		$quiz_difficulty        = isset( $_POST['_lm_quiz_difficulty'] ) ? sanitize_key( wp_unslash( $_POST['_lm_quiz_difficulty'] ) ) : (string) get_post_meta( $post_id, '_lm_quiz_difficulty', true );
		$quiz_display_num       = isset( $_POST['_lm_quiz_display_num'] ) ? max( 1, absint( wp_unslash( $_POST['_lm_quiz_display_num'] ) ) ) : absint( get_post_meta( $post_id, '_lm_quiz_display_num', true ) );
		$quiz_time_limit        = isset( $_POST['_lm_quiz_time_limit'] ) ? absint( wp_unslash( $_POST['_lm_quiz_time_limit'] ) ) : 0;
		$quiz_max_attempts      = isset( $_POST['_lm_quiz_max_attempts'] ) ? absint( wp_unslash( $_POST['_lm_quiz_max_attempts'] ) ) : 0;
		$quiz_show_results      = isset( $_POST['_lm_quiz_show_results'] ) ? sanitize_key( wp_unslash( $_POST['_lm_quiz_show_results'] ) ) : (string) get_post_meta( $post_id, '_lm_quiz_show_results', true );
		$quiz_allow_attachments = isset( $_POST['_lm_quiz_allow_attachments'] ) ? sanitize_key( wp_unslash( $_POST['_lm_quiz_allow_attachments'] ) ) : (string) get_post_meta( $post_id, '_lm_quiz_allow_attachments', true );
		$quiz_max_files         = isset( $_POST['_lm_quiz_max_files'] ) ? max( 1, absint( wp_unslash( $_POST['_lm_quiz_max_files'] ) ) ) : absint( get_post_meta( $post_id, '_lm_quiz_max_files', true ) );
		$quiz_enable_dates      = isset( $_POST['_lm_quiz_enable_dates'] ) ? sanitize_key( wp_unslash( $_POST['_lm_quiz_enable_dates'] ) ) : 'no';
		$quiz_weight            = isset( $_POST['_lm_quiz_weight'] ) ? min( 100, absint( wp_unslash( $_POST['_lm_quiz_weight'] ) ) ) : absint( get_post_meta( $post_id, '_lm_quiz_weight', true ) );
		$grade_scale            = isset( $_POST['_lm_grade_scale'] ) ? sanitize_key( wp_unslash( $_POST['_lm_grade_scale'] ) ) : '0_20';
		$passing_threshold      = isset( $_POST['_lm_passing_threshold'] ) ? min( 100, absint( wp_unslash( $_POST['_lm_passing_threshold'] ) ) ) : 0;
		$min_score              = isset( $_POST['lm_min_score'] ) ? absint( wp_unslash( $_POST['lm_min_score'] ) ) : 0;
		$max_score              = isset( $_POST['lm_max_score'] ) ? absint( wp_unslash( $_POST['lm_max_score'] ) ) : 0;
		$quiz_base_text         = isset( $_POST['_lm_quiz_base_text'] ) ? wp_kses_post( wp_unslash( $_POST['_lm_quiz_base_text'] ) ) : '';
		$extra_material         = isset( $_POST['lm_extra_material'] ) ? wp_kses_post( wp_unslash( $_POST['lm_extra_material'] ) ) : '';
		$quiz_randomize         = isset( $_POST['_lm_quiz_randomize'] ) ? sanitize_key( wp_unslash( $_POST['_lm_quiz_randomize'] ) ) : 'no';
		$quiz_num_questions     = isset( $_POST['_lm_quiz_num_questions'] ) ? absint( wp_unslash( $_POST['_lm_quiz_num_questions'] ) ) : 0;

			$ai_source_mode   = isset( $_POST[ self::AI_SOURCE_MODE_META ] ) ? sanitize_key( wp_unslash( $_POST[ self::AI_SOURCE_MODE_META ] ) ) : 'text';
			$ai_guide_file_id = isset( $_POST[ self::AI_GUIDE_ATTACHMENT ] ) ? absint( wp_unslash( $_POST[ self::AI_GUIDE_ATTACHMENT ] ) ) : 0;
			$ai_source_label  = isset( $_POST[ self::AI_GUIDE_SOURCE_LABEL ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::AI_GUIDE_SOURCE_LABEL ] ) ) : '';
			$ai_bank_size     = isset( $_POST[ self::AI_BANK_SIZE_META ] ) ? absint( wp_unslash( $_POST[ self::AI_BANK_SIZE_META ] ) ) : 0;
			$quiz_questions_existing = get_post_meta( $post_id, self::QUIZ_QUESTIONS_META_KEY, true );
			$quiz_questions_existing = is_array( $quiz_questions_existing ) ? array_values( $quiz_questions_existing ) : array();
			$ai_selector_present     = isset( $_POST['_clms_ai_question_selector_present'] );
			$ai_keep_indexes         = null;
			if ( $ai_selector_present ) {
				$ai_keep_indexes = isset( $_POST['_clms_ai_question_keep'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['_clms_ai_question_keep'] ) ) : array();
			}
			$ai_new_question_text    = isset( $_POST['_clms_ai_new_question_text'] ) ? sanitize_text_field( wp_unslash( $_POST['_clms_ai_new_question_text'] ) ) : '';
			$ai_new_question_type    = isset( $_POST['_clms_ai_new_question_type'] ) ? sanitize_key( wp_unslash( $_POST['_clms_ai_new_question_type'] ) ) : 'single';
			$ai_new_question_options = isset( $_POST['_clms_ai_new_question_options'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_clms_ai_new_question_options'] ) ) : '';
			$ai_new_question_correct = isset( $_POST['_clms_ai_new_question_correct'] ) ? sanitize_text_field( wp_unslash( $_POST['_clms_ai_new_question_correct'] ) ) : '';
			$ai_new_question_feedback = isset( $_POST['_clms_ai_new_question_feedback'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_clms_ai_new_question_feedback'] ) ) : '';
			$ai_new_question_weight  = isset( $_POST['_clms_ai_new_question_weight'] ) ? max( 1, absint( wp_unslash( $_POST['_clms_ai_new_question_weight'] ) ) ) : 1;

		$rubric_id     = isset( $_POST['_clms_rubric_id'] ) ? absint( wp_unslash( $_POST['_clms_rubric_id'] ) ) : 0;
		$lesson_competencies = isset( $_POST['_clms_lesson_competencies'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_clms_lesson_competencies'] ) ) : '';
		$lesson_competencies_selected = isset( $_POST['_clms_lesson_competency_titles'] ) ? (array) wp_unslash( $_POST['_clms_lesson_competency_titles'] ) : array();
		$evidence_type = isset( $_POST['_clms_evidence_type'] ) ? sanitize_key( wp_unslash( $_POST['_clms_evidence_type'] ) ) : 'practice';
		$evidence_required_for_certificate = isset( $_POST['_clms_evidence_required_for_certificate'] ) ? '1' : '0';
		$evidence_competency_ids_raw = isset( $_POST['_clms_evidence_competency_ids'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_clms_evidence_competency_ids'] ) ) : '';
		$evidence_competency_ids_selected = isset( $_POST['_clms_evidence_competency_ids_selected'] ) ? (array) wp_unslash( $_POST['_clms_evidence_competency_ids_selected'] ) : array();
		$evidence_minimum_grade = isset( $_POST['_clms_evidence_minimum_grade'] ) ? absint( wp_unslash( $_POST['_clms_evidence_minimum_grade'] ) ) : 0;
		$evidence_allow_resubmission = isset( $_POST['_clms_evidence_allow_resubmission'] ) ? sanitize_key( wp_unslash( $_POST['_clms_evidence_allow_resubmission'] ) ) : '1';
		$evidence_read_requirement = isset( $_POST['_clms_evidence_read_requirement'] ) ? sanitize_key( wp_unslash( $_POST['_clms_evidence_read_requirement'] ) ) : 'seen_or_comment';
		$lesson_prerequisites = isset( $_POST['clms_lesson_prerequisite_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['clms_lesson_prerequisite_ids'] ) ) : array();

		$resources_raw = isset( $_POST['clms_lesson_resources'] ) ? wp_unslash( $_POST['clms_lesson_resources'] ) : array();
		$resources     = $this->sanitize_resources( $resources_raw );

		if ( $course_id && 'lm_course' !== get_post_type( $course_id ) ) {
			$course_id = 0;
		}

			$allowed_activity_types = array( 'lectura', 'tarea', 'quiz' );
			if ( ! in_array( $activity_type, $allowed_activity_types, true ) ) {
				$activity_type = 'lectura';
			}

			$allowed_delivery_modes = array( 'read_only', 'quiz', 'file', 'text', 'both' );
			if ( ! in_array( $delivery_mode, $allowed_delivery_modes, true ) ) {
				$delivery_mode = '';
			}

		$allowed_session_types = array( 'asincrono', 'sincrono', 'hibrido' );
		if ( ! in_array( $session_type, $allowed_session_types, true ) ) {
			$session_type = 'asincrono';
		}
		$allowed_live_providers = array( 'zoom', 'google_meet', 'youtube_live', 'custom' );
		if ( ! in_array( $live_provider, $allowed_live_providers, true ) ) {
			$live_provider = 'zoom';
		}
		if ( '' !== $live_timezone && ! preg_match( '/^[A-Za-z0-9_\-\/:+. ]{2,64}$/', $live_timezone ) ) {
			$live_timezone = '';
		}
		if ( '' !== $live_starts_at && '' !== $live_ends_at && strtotime( $live_ends_at ) < strtotime( $live_starts_at ) ) {
			$live_ends_at = '';
		}

		$quiz_has_eval          = in_array( $quiz_has_eval, array( 'yes', 'no' ), true ) ? $quiz_has_eval : 'no';
		$quiz_eval_mode         = in_array( $quiz_eval_mode, array( 'manual', 'automatico', 'mixto' ), true ) ? $quiz_eval_mode : 'manual';
		$quiz_eval_type         = in_array( $quiz_eval_type, array( 'test', 'desarrollo', 'mixta' ), true ) ? $quiz_eval_type : 'test';
		$quiz_difficulty        = in_array( $quiz_difficulty, array( 'baja', 'media', 'alta' ), true ) ? $quiz_difficulty : 'media';
		$quiz_show_results      = in_array( $quiz_show_results, array( 'yes', 'no' ), true ) ? $quiz_show_results : 'yes';
		$quiz_allow_attachments = in_array( $quiz_allow_attachments, array( 'yes', 'no' ), true ) ? $quiz_allow_attachments : 'no';
		$quiz_enable_dates      = in_array( $quiz_enable_dates, array( 'yes', 'no' ), true ) ? $quiz_enable_dates : 'no';
		$quiz_randomize         = in_array( $quiz_randomize, array( 'yes', 'no' ), true ) ? $quiz_randomize : 'no';
		$grade_scale            = in_array( $grade_scale, array( '0_10', '0_20', '0_100', 'a_f', 'logros' ), true ) ? $grade_scale : '0_20';
		$ai_source_mode         = in_array( $ai_source_mode, array( 'text', 'file', 'mixed' ), true ) ? $ai_source_mode : 'text';
			$assessment_mode        = in_array( $assessment_mode, array( 'manual', 'ai_assisted', 'ai_auto_grade', 'peer_review', 'hybrid' ), true ) ? $assessment_mode : 'manual';
			if ( 'peer_review' === $assessment_mode ) {
				$peer_review_enabled = '1';
			}
			if ( 'quiz' === $delivery_mode ) {
				$quiz_enabled = '1';
			}
			if ( in_array( $delivery_mode, array( 'read_only', 'file', 'text', 'both' ), true ) ) {
				$activity_type = ( 'read_only' === $delivery_mode ) ? 'lectura' : 'tarea';
			}
			if ( '1' === $quiz_enabled ) {
				$activity_type = 'quiz';
			}
			$quiz_has_eval          = ( '1' === $quiz_enabled ) ? 'yes' : 'no';
			$quiz_eval_mode         = $this->map_assessment_mode_to_legacy_quiz_mode( $assessment_mode );
		$quiz_display_num       = max( 1, $quiz_display_num > 0 ? $quiz_display_num : ( $quiz_num_questions > 0 ? $quiz_num_questions : 10 ) );
		$quiz_max_files         = max( 1, $quiz_max_files > 0 ? $quiz_max_files : 12 );
		$quiz_weight            = max( 0, min( 100, $quiz_weight ) );
		$allowed_evidence_types = array( 'practice', 'assignment', 'partial_exam', 'final_exam', 'certifiable_evidence', 'required', 'read_only' );
		if ( ! in_array( $evidence_type, $allowed_evidence_types, true ) ) {
			$evidence_type = 'practice';
		}
		$allowed_read_requirements = array( 'seen', 'comment', 'seen_or_comment' );
		if ( ! in_array( $evidence_read_requirement, $allowed_read_requirements, true ) ) {
			$evidence_read_requirement = 'seen_or_comment';
		}

		$lesson_competency_lines = preg_split( '/\r\n|\r|\n/', (string) $lesson_competencies );
		$lesson_competency_lines = is_array( $lesson_competency_lines ) ? $lesson_competency_lines : array();
		$lesson_competency_lines = array_map( 'sanitize_text_field', $lesson_competency_lines );
		$lesson_competency_lines = array_values( array_filter( $lesson_competency_lines ) );

		$lesson_competencies_selected = array_map( 'sanitize_text_field', $lesson_competencies_selected );
		$lesson_competencies_selected = array_values( array_filter( $lesson_competencies_selected ) );

		$merged_lesson_competencies = array_merge( $lesson_competencies_selected, $lesson_competency_lines );
		$merged_lesson_competencies = array_values( array_unique( array_filter( $merged_lesson_competencies ) ) );
		$lesson_competencies = implode( "\n", $merged_lesson_competencies );

		$evidence_competency_ids = array();
		if ( '' !== trim( $evidence_competency_ids_raw ) ) {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $evidence_competency_ids_raw );
			foreach ( (array) $lines as $line ) {
				$id = sanitize_key( (string) $line );
				if ( '' !== $id ) {
					$evidence_competency_ids[] = $id;
				}
			}
		}
		foreach ( $evidence_competency_ids_selected as $selected_id ) {
			$selected_id = sanitize_key( (string) $selected_id );
			if ( '' !== $selected_id ) {
				$evidence_competency_ids[] = $selected_id;
			}
		}
		$evidence_competency_ids = array_values( array_unique( $evidence_competency_ids ) );
		$evidence_minimum_grade = max( 0, min( 100, $evidence_minimum_grade ) );
		$evidence_allow_resubmission = in_array( $evidence_allow_resubmission, array( '0', '1' ), true ) ? $evidence_allow_resubmission : '1';
		$ai_confidence_threshold = max( 0.5, min( 0.99, (float) $ai_confidence_threshold ) );
		$normalized_thresholds  = array();

			foreach ( array( 'lectura', 'tarea', 'quiz' ) as $threshold_type ) {
				$value = isset( $ai_confidence_thresholds[ $threshold_type ] ) ? (float) $ai_confidence_thresholds[ $threshold_type ] : $ai_confidence_threshold;
				$normalized_thresholds[ $threshold_type ] = max( 0.5, min( 0.99, $value ) );
			}

			$quiz_questions_updated = array();
			if ( null === $ai_keep_indexes ) {
				$quiz_questions_updated = array_map( array( $this, 'sanitize_quiz_question' ), $quiz_questions_existing );
			} else {
				$ai_keep_indexes = array_values( array_unique( array_filter( array_map( 'absint', (array) $ai_keep_indexes ), static function ( $index ) {
					return $index >= 0;
				} ) ) );
				foreach ( $ai_keep_indexes as $keep_index ) {
					if ( ! isset( $quiz_questions_existing[ $keep_index ] ) ) {
						continue;
					}
					$quiz_questions_updated[] = $this->sanitize_quiz_question( $quiz_questions_existing[ $keep_index ] );
				}
			}
			$quiz_questions_updated = array_values(
				array_filter(
					$quiz_questions_updated,
					static function ( $question ) {
						return is_array( $question ) && ! empty( $question['question'] );
					}
				)
			);
			$new_quiz_question = $this->build_manual_quiz_question(
				$ai_new_question_text,
				$ai_new_question_type,
				$ai_new_question_options,
				$ai_new_question_correct,
				$ai_new_question_feedback,
				$ai_new_question_weight
			);
			if ( ! empty( $new_quiz_question['question'] ) ) {
				$quiz_questions_updated[] = $new_quiz_question;
			}

		update_post_meta( $post_id, CLMS_Helper::COURSE_META_KEY, $course_id );
		update_post_meta( $post_id, CLMS_Helper::COURSE_META_KEY_LEGACY, $course_id );
		update_post_meta( $post_id, self::COURSE_META_FALLBACK_1, $course_id );
		update_post_meta( $post_id, self::COURSE_META_FALLBACK_2, $course_id );
		update_post_meta( $post_id, self::LESSON_MODULE_META, $lesson_module );

		update_post_meta( $post_id, 'lm_activity_type', $activity_type );
		update_post_meta( $post_id, '_clms_activity_mode', $activity_type );
		update_post_meta( $post_id, 'lm_session_type', $session_type );
		update_post_meta( $post_id, self::LIVE_CLASS_PROVIDER, $live_provider );
		update_post_meta( $post_id, self::LIVE_CLASS_URL, $live_url );
		update_post_meta( $post_id, self::LIVE_CLASS_STARTS_AT, $live_starts_at );
		update_post_meta( $post_id, self::LIVE_CLASS_ENDS_AT, $live_ends_at );
		update_post_meta( $post_id, self::LIVE_CLASS_TIMEZONE, $live_timezone );
		update_post_meta( $post_id, self::LIVE_CLASS_NOTES, $live_notes );
		update_post_meta( $post_id, 'lm_task_title', $task_title );
		update_post_meta( $post_id, 'lm_task_description', $task_description );
		update_post_meta( $post_id, '_clms_task_instructions', $task_description );
		update_post_meta( $post_id, 'lm_show_in_calendar', $show_in_calendar );

		update_post_meta( $post_id, 'lm_due_date', $due_date );
		update_post_meta( $post_id, '_clms_due_date', $due_date );
		update_post_meta( $post_id, 'lm_due_time', $due_time );
		update_post_meta( $post_id, '_clms_due_time', $due_time );
		update_post_meta( $post_id, 'lm_late_date', $late_date );
		update_post_meta( $post_id, '_clms_due_date_late', $late_date );
		update_post_meta( $post_id, 'lm_late_time', $late_time );

		update_post_meta( $post_id, self::LESSON_SUBTITLE_META, $lesson_subtitle );
		update_post_meta( $post_id, self::LESSON_PUBLIC_SNIPPET, $lesson_public_snippet );
		update_post_meta( $post_id, self::LESSON_COVER_IMAGE_ID, $lesson_cover_id );
		update_post_meta( $post_id, self::LESSON_TIP_1, $tip_1 );
		update_post_meta( $post_id, self::LESSON_TIP_2, $tip_2 );
		update_post_meta( $post_id, self::LESSON_TIP_3, $tip_3 );
		update_post_meta( $post_id, self::LESSON_VIDEO_URL, $video_url );
		update_post_meta( $post_id, self::LESSON_VIDEO_SOURCE, $video_source );
		update_post_meta( $post_id, self::LESSON_VIDEO_COUNT, $video_count );
		update_post_meta( $post_id, self::LESSON_EXTRA_VIDEOS, $extra_videos );
		update_post_meta( $post_id, self::LESSON_UI_LIMIT_VIDEOS, $ui_limit_videos );
		update_post_meta( $post_id, self::LESSON_UI_LIMIT_RESOURCES, $ui_limit_resources );
		update_post_meta( $post_id, self::LESSON_UI_LIMIT_TIPS, $ui_limit_tips );
		update_post_meta( $post_id, '_clms_rubric_id', $rubric_id );
		update_post_meta( $post_id, '_clms_lesson_competencies', $lesson_competencies );
		update_post_meta( $post_id, '_clms_evidence_type', $evidence_type );
		if ( isset( $_POST['_clms_delivery_mode'] ) ) {
			update_post_meta( $post_id, '_clms_delivery_mode', $delivery_mode );
		}
		update_post_meta( $post_id, '_clms_evidence_required_for_certificate', $evidence_required_for_certificate );
		update_post_meta( $post_id, '_clms_evidence_competency_ids', $evidence_competency_ids );
		update_post_meta( $post_id, '_clms_evidence_minimum_grade', $evidence_minimum_grade );
		update_post_meta( $post_id, '_clms_evidence_allow_resubmission', $evidence_allow_resubmission );
		update_post_meta( $post_id, '_clms_evidence_read_requirement', $evidence_read_requirement );
		update_post_meta( $post_id, self::LESSON_SUPPORT_RESOURCES, $resources );
		update_post_meta( $post_id, CLMS_Helper::LESSON_PREREQUISITES_META, array_values( array_diff( array_unique( $lesson_prerequisites ), array( (int) $post_id ) ) ) );

		update_post_meta( $post_id, self::QUIZ_ENABLED_META_KEY, $quiz_enabled );
		update_post_meta( $post_id, '_clms_evaluation_mode', $assessment_mode );
		update_post_meta( $post_id, '_clms_ai_confidence_threshold', $ai_confidence_threshold );
		update_post_meta( $post_id, '_clms_ai_confidence_thresholds', $normalized_thresholds );
		update_post_meta( $post_id, '_clms_peer_review_enabled', $peer_review_enabled );
		update_post_meta( $post_id, '_clms_peer_reviews_per_student', $peer_reviews_per_student );
		update_post_meta( $post_id, '_clms_peer_review_blind', $peer_review_blind );
		update_post_meta( $post_id, '_clms_pr_calibration_enabled', $peer_review_calibration_enabled );
		update_post_meta( $post_id, '_clms_pr_calibration_submission_id', $peer_review_calibration_submission_id );
		update_post_meta( $post_id, '_clms_pr_calibration_teacher_grade', $peer_review_calibration_teacher_grade );
		update_post_meta( $post_id, '_lm_quiz_has_eval', $quiz_has_eval );
		update_post_meta( $post_id, '_lm_quiz_eval_mode', $quiz_eval_mode );
		update_post_meta( $post_id, '_lm_quiz_eval_type', $quiz_eval_type );
		update_post_meta( $post_id, '_lm_quiz_difficulty', $quiz_difficulty );
		update_post_meta( $post_id, '_lm_quiz_display_num', $quiz_display_num );
		update_post_meta( $post_id, '_lm_quiz_time_limit', $quiz_time_limit );
		update_post_meta( $post_id, '_lm_quiz_max_attempts', $quiz_max_attempts );
		update_post_meta( $post_id, '_lm_quiz_show_results', $quiz_show_results );
		update_post_meta( $post_id, '_lm_quiz_allow_attachments', $quiz_allow_attachments );
		update_post_meta( $post_id, '_lm_quiz_max_files', $quiz_max_files );
		update_post_meta( $post_id, '_lm_quiz_enable_dates', $quiz_enable_dates );
		update_post_meta( $post_id, '_lm_quiz_weight', $quiz_weight );
		update_post_meta( $post_id, '_lm_grade_scale', $grade_scale );
		update_post_meta( $post_id, '_lm_passing_threshold', $passing_threshold );
		update_post_meta( $post_id, '_clms_quiz_passing_score', $passing_threshold );
		update_post_meta( $post_id, 'lm_min_score', $min_score );
		update_post_meta( $post_id, 'lm_max_score', $max_score );
		update_post_meta( $post_id, '_lm_quiz_base_text', $quiz_base_text );
		update_post_meta( $post_id, 'lm_extra_material', $extra_material );
		update_post_meta( $post_id, '_lm_quiz_randomize', $quiz_randomize );
		update_post_meta( $post_id, '_lm_quiz_num_questions', $quiz_num_questions );

			update_post_meta( $post_id, self::AI_SOURCE_MODE_META, $ai_source_mode );
			update_post_meta( $post_id, self::AI_GUIDE_ATTACHMENT, $ai_guide_file_id );
			update_post_meta( $post_id, self::AI_GUIDE_SOURCE_LABEL, $ai_source_label );
			if ( ! empty( $quiz_questions_updated ) ) {
				update_post_meta( $post_id, self::QUIZ_QUESTIONS_META_KEY, array_values( $quiz_questions_updated ) );
			} else {
				delete_post_meta( $post_id, self::QUIZ_QUESTIONS_META_KEY );
			}
			if ( $ai_selector_present || '' !== trim( (string) $ai_new_question_text ) ) {
				$ai_bank_size = count( $quiz_questions_updated );
			}
			update_post_meta( $post_id, self::AI_BANK_SIZE_META, $ai_bank_size );

		if ( (int) get_post_field( 'menu_order', $post_id ) !== $lesson_order ) {
			remove_action( 'save_post_lm_lesson', array( $this, 'save_metabox' ), 20 );
			wp_update_post(
				array(
					'ID'         => $post_id,
					'menu_order' => $lesson_order,
				)
			);
			add_action( 'save_post_lm_lesson', array( $this, 'save_metabox' ), 20, 2 );
		}

		CLMS_Helper::flush_runtime_cache( $course_id, $post_id );
	}

	public function handle_clear_ai_quiz_bank() {
		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;

		if ( ! $post_id || 'lm_lesson' !== get_post_type( $post_id ) ) {
			wp_die( esc_html__( 'Lección no válida.', 'atora-lms' ) );
		}

		if ( ! CLMS_Helper::user_can_manage_lms( $post_id ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		check_admin_referer( 'clms_clear_ai_quiz_bank_' . $post_id );

		delete_post_meta( $post_id, self::QUIZ_QUESTIONS_META_KEY );
		delete_post_meta( $post_id, self::AI_GENERATED_AT_META );
		update_post_meta( $post_id, self::AI_BANK_SIZE_META, 0 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post'   => $post_id,
					'action' => 'edit',
				),
				admin_url( 'post.php' )
			)
		);
		exit;
	}

		// ── Helpers ──────────────────────────────────────────────────────────────────

		protected function normalize_activity_type( $activity_type ) {
			$activity_type = sanitize_key( (string) $activity_type );
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

			return isset( $aliases[ $activity_type ] ) ? $aliases[ $activity_type ] : 'lectura';
		}

		protected function get_quiz_question_preview( $question ) {
			$question = is_array( $question ) ? $question : array();
			$text     = isset( $question['question'] ) ? sanitize_text_field( (string) $question['question'] ) : '';
			if ( '' === $text && isset( $question['text'] ) ) {
				$text = sanitize_text_field( (string) $question['text'] );
			}
			return $text;
		}

		protected function sanitize_quiz_question( $question ) {
			$question = is_array( $question ) ? $question : array();

			$type = isset( $question['type'] ) ? sanitize_key( (string) $question['type'] ) : 'single';
			$allowed_types = array( 'single', 'multiple', 'text', 'textarea', 'true_false', 'number' );
			if ( ! in_array( $type, $allowed_types, true ) ) {
				$type = 'single';
			}

			$text = isset( $question['question'] ) ? sanitize_text_field( (string) $question['question'] ) : '';
			if ( '' === $text && isset( $question['text'] ) ) {
				$text = sanitize_text_field( (string) $question['text'] );
			}

			$options = array();
			if ( isset( $question['options'] ) && is_array( $question['options'] ) ) {
				foreach ( $question['options'] as $option ) {
					$option = sanitize_text_field( (string) $option );
					if ( '' !== $option ) {
						$options[] = $option;
					}
				}
			}

			if ( 'true_false' === $type && empty( $options ) ) {
				$options = array( 'Verdadero', 'Falso' );
			}

			$correct = array();
			if ( isset( $question['correct'] ) ) {
				$raw_correct = is_array( $question['correct'] ) ? $question['correct'] : array( $question['correct'] );
				foreach ( $raw_correct as $correct_item ) {
					$correct_item = sanitize_text_field( (string) $correct_item );
					if ( '' !== $correct_item ) {
						$correct[] = $correct_item;
					}
				}
			} elseif ( isset( $question['answer'] ) ) {
				$raw_answer = is_array( $question['answer'] ) ? $question['answer'] : array( $question['answer'] );
				foreach ( $raw_answer as $answer_item ) {
					$answer_item = sanitize_text_field( (string) $answer_item );
					if ( '' !== $answer_item ) {
						$correct[] = $answer_item;
					}
				}
			}

			$feedback = isset( $question['feedback'] ) ? sanitize_textarea_field( (string) $question['feedback'] ) : '';
			$weight   = isset( $question['weight'] ) ? max( 1, absint( $question['weight'] ) ) : 1;

			return array(
				'type'     => $type,
				'question' => $text,
				'options'  => array_values( array_unique( $options ) ),
				'correct'  => array_values( array_unique( $correct ) ),
				'feedback' => $feedback,
				'weight'   => $weight,
			);
		}

		protected function build_manual_quiz_question( $text, $type, $options_raw, $correct_raw, $feedback, $weight ) {
			$text = sanitize_text_field( (string) $text );
			if ( '' === $text ) {
				return array();
			}

			$type = sanitize_key( (string) $type );
			$allowed_types = array( 'single', 'multiple', 'text', 'textarea', 'true_false', 'number' );
			if ( ! in_array( $type, $allowed_types, true ) ) {
				$type = 'single';
			}

			$options = preg_split( '/\r\n|\r|\n/', (string) $options_raw );
			$options = is_array( $options ) ? $options : array();
			$options = array_values(
				array_filter(
					array_map(
						static function ( $option ) {
							return sanitize_text_field( (string) $option );
						},
						$options
					),
					'strlen'
				)
			);

			if ( 'true_false' === $type && empty( $options ) ) {
				$options = array( 'Verdadero', 'Falso' );
			}

			$correct_values = array();
			if ( 'multiple' === $type ) {
				$parts = explode( ',', (string) $correct_raw );
				foreach ( $parts as $part ) {
					$part = sanitize_text_field( trim( (string) $part ) );
					if ( '' !== $part ) {
						$correct_values[] = $part;
					}
				}
			} else {
				$single_correct = sanitize_text_field( (string) $correct_raw );
				if ( '' !== $single_correct ) {
					$correct_values[] = $single_correct;
				}
			}

			return $this->sanitize_quiz_question(
				array(
					'type'     => $type,
					'question' => $text,
					'options'  => $options,
					'correct'  => $correct_values,
					'feedback' => sanitize_textarea_field( (string) $feedback ),
					'weight'   => max( 1, absint( $weight ) ),
				)
			);
		}

		protected function map_assessment_mode_to_legacy_quiz_mode( $assessment_mode ) {
			$assessment_mode = sanitize_key( (string) $assessment_mode );
		if ( 'ai_auto_grade' === $assessment_mode ) {
			return 'automatico';
		}
		if ( in_array( $assessment_mode, array( 'ai_assisted', 'hybrid' ), true ) ) {
			return 'mixto';
		}
		return 'manual';
	}

	protected function sanitize_extra_videos( $raw ) {
		$raw     = is_array( $raw ) ? $raw : array();
		$allowed = array( 'youtube', 'vimeo', 'bunny', 'drive', 'url' );
		$clean   = array();

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$url = isset( $item['url'] ) ? esc_url_raw( trim( $item['url'] ) ) : '';

			// Omitir slots vacíos — no tiene sentido guardar videos sin URL.
			if ( '' === $url ) {
				continue;
			}

			$source = isset( $item['source'] ) ? sanitize_key( $item['source'] ) : 'youtube';
			if ( ! in_array( $source, $allowed, true ) ) {
				$source = 'youtube';
			}

			$thumb_id = isset( $item['thumb_id'] ) ? absint( $item['thumb_id'] ) : 0;
			if ( $thumb_id && 'attachment' !== get_post_type( $thumb_id ) ) {
				$thumb_id = 0;
			}

			$clean[] = array(
				'source'      => $source,
				'url'         => $url,
				'description' => isset( $item['description'] ) ? wp_kses_post( $item['description'] ) : '',
				'tip_1'       => isset( $item['tip_1'] ) ? sanitize_text_field( $item['tip_1'] ) : '',
				'tip_2'       => isset( $item['tip_2'] ) ? sanitize_text_field( $item['tip_2'] ) : '',
				'tip_3'       => isset( $item['tip_3'] ) ? sanitize_text_field( $item['tip_3'] ) : '',
				'thumb_id'    => $thumb_id,
			);
		}

		return array_values( $clean );
	}

	protected function sanitize_resources( $resources_raw ) {
		$resources_raw = is_array( $resources_raw ) ? $resources_raw : array();
		$clean         = array();
		$allowed_types = array( 'pdf', 'guia', 'presentacion', 'audio', 'video', 'link', 'archivo' );

		foreach ( $resources_raw as $resource ) {
			if ( ! is_array( $resource ) ) {
				continue;
			}

			$type = isset( $resource['type'] ) ? sanitize_key( $resource['type'] ) : 'pdf';
			if ( ! in_array( $type, $allowed_types, true ) ) {
				$type = 'pdf';
			}

			$item = array(
				'title'       => isset( $resource['title'] ) ? sanitize_text_field( $resource['title'] ) : '',
				'description' => isset( $resource['description'] ) ? sanitize_textarea_field( $resource['description'] ) : '',
				'type'        => $type,
				'file_id'     => isset( $resource['file_id'] ) ? absint( $resource['file_id'] ) : 0,
				'url'         => isset( $resource['url'] ) ? esc_url_raw( $resource['url'] ) : '',
				'thumb_id'    => isset( $resource['thumb_id'] ) ? absint( $resource['thumb_id'] ) : 0,
			);

			if ( $item['file_id'] && 'attachment' !== get_post_type( $item['file_id'] ) ) {
				$item['file_id'] = 0;
			}

			if ( $item['thumb_id'] && 'attachment' !== get_post_type( $item['thumb_id'] ) ) {
				$item['thumb_id'] = 0;
			}

			if ( '' === $item['title'] && ! $item['file_id'] && '' === $item['url'] ) {
				continue;
			}

			$clean[] = $item;
		}

		return array_values( $clean );
	}

	protected function get_meta( $post_id, $keys, $default = '' ) {
		return CLMS_Helper::get_post_meta_first( $post_id, $keys, $default );
	}

	protected function get_meta_int( $post_id, $keys, $default = 0 ) {
		return absint( CLMS_Helper::get_post_meta_first( $post_id, $keys, $default ) );
	}

	protected function sanitize_date( $value ) {
		$value = sanitize_text_field( $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	protected function sanitize_time( $value ) {
		$value = sanitize_text_field( $value );
		return preg_match( '/^\d{2}:\d{2}$/', $value ) ? $value : '';
	}

	protected function sanitize_datetime_local( $value ) {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ? $value : '';
	}
}
