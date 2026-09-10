<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_REST_Academics_Helpers_Trait {
	protected function prepare_course_response( $post, $full = false ) {
		$post = get_post( $post );

		if ( ! $post || 'lm_course' !== $post->post_type ) {
			return array();
		}

		$data = array(
			'id'           => $post->ID,
			'title'        => get_the_title( $post->ID ),
			'slug'         => $post->post_name,
			'status'       => $post->post_status,
			'link'         => get_permalink( $post->ID ),
			'teacher_id'   => (int) $post->post_author,
			'teacher_name' => get_the_author_meta( 'display_name', $post->post_author ),
			'lesson_count' => class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_lesson_count( $post->ID ) : 0,
			'subtitle'     => (string) get_post_meta( $post->ID, '_clms_course_subtitle', true ),
			'duration'     => (string) get_post_meta( $post->ID, '_clms_course_duration', true ),
			'difficulty'   => (string) get_post_meta( $post->ID, '_clms_course_difficulty', true ),
			'modality'     => (string) get_post_meta( $post->ID, '_clms_course_modality', true ),
			'price'        => (string) get_post_meta( $post->ID, '_clms_course_price', true ),
			'program_ids'  => class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_program_ids( $post->ID ) : array(),
			'prerequisite_course_ids' => class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_prerequisite_ids( $post->ID ) : array(),
		);

		if ( $full ) {
			$data['content']      = apply_filters( 'the_content', $post->post_content );
			$data['excerpt']      = $post->post_excerpt;
			$data['benefits']     = $this->normalize_lines_meta( get_post_meta( $post->ID, '_clms_course_benefits', true ) );
			$data['requirements'] = $this->normalize_lines_meta( get_post_meta( $post->ID, '_clms_course_requirements', true ) );
			$data['audience']     = $this->normalize_lines_meta( get_post_meta( $post->ID, '_clms_course_target_audience', true ) );
			$data['academic_sheet'] = array(
				'objective_general'   => (string) get_post_meta( $post->ID, '_clms_course_academic_objective_general', true ),
				'objectives_specific' => $this->normalize_lines_meta( get_post_meta( $post->ID, '_clms_course_academic_objectives_specific', true ) ),
				'competencies'        => $this->normalize_lines_meta( get_post_meta( $post->ID, '_clms_course_competencies', true ) ),
				'competencies_struct' => $this->get_course_competencies_struct( $post->ID ),
				'learning_outcomes'   => $this->normalize_lines_meta( get_post_meta( $post->ID, '_clms_course_learning_outcomes', true ) ),
				'entry_profile'       => (string) get_post_meta( $post->ID, '_clms_course_entry_profile', true ),
				'exit_profile'        => (string) get_post_meta( $post->ID, '_clms_course_exit_profile', true ),
				'methodology'         => (string) get_post_meta( $post->ID, '_clms_course_methodology', true ),
				'evidence'            => $this->normalize_lines_meta( get_post_meta( $post->ID, '_clms_course_evidence', true ) ),
				'evaluation_criteria' => $this->normalize_lines_meta( get_post_meta( $post->ID, '_clms_course_evaluation_criteria', true ) ),
				'prerequisites_text'  => (string) get_post_meta( $post->ID, '_clms_course_prerequisites_text', true ),
				'certification'       => (string) get_post_meta( $post->ID, '_clms_course_certification_text', true ),
			);
		}

		return $data;
	}

	protected function prepare_program_response( $post, $full = false ) {
		$post = get_post( $post );

		if ( ! $post || 'lm_program' !== $post->post_type ) {
			return array();
		}

		$user_id    = get_current_user_id();
		$course_ids = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_program_courses( $post->ID ) : array();

		$data = array(
			'id'           => $post->ID,
			'title'        => get_the_title( $post->ID ),
			'slug'         => $post->post_name,
			'status'       => $post->post_status,
			'link'         => get_permalink( $post->ID ),
			'teacher_id'   => (int) $post->post_author,
			'teacher_name' => get_the_author_meta( 'display_name', $post->post_author ),
			'subtitle'     => (string) get_post_meta( $post->ID, '_clms_program_subtitle', true ),
			'duration'     => (string) get_post_meta( $post->ID, '_clms_program_duration', true ),
			'difficulty'   => (string) get_post_meta( $post->ID, '_clms_program_difficulty', true ),
			'modality'     => (string) get_post_meta( $post->ID, '_clms_program_modality', true ),
			'course_count' => count( $course_ids ),
			'course_ids'   => array_values( array_map( 'absint', $course_ids ) ),
			'is_enrolled'  => class_exists( 'CLMS_Helper' ) ? CLMS_Helper::user_is_enrolled_in_program( $user_id, $post->ID ) : false,
		);

		if ( $full ) {
			$data['content']    = apply_filters( 'the_content', $post->post_content );
			$data['excerpt']    = $post->post_excerpt;
			$data['curriculum'] = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_program_curriculum( $post->ID, $user_id ) : array();
			$data['academic_sheet'] = array(
				'objective_general'   => (string) get_post_meta( $post->ID, '_clms_program_objective_general', true ),
				'competencies'        => $this->normalize_lines_meta( get_post_meta( $post->ID, '_clms_program_competencies', true ) ),
				'learning_outcomes'   => $this->normalize_lines_meta( get_post_meta( $post->ID, '_clms_program_learning_outcomes', true ) ),
				'entry_profile'       => (string) get_post_meta( $post->ID, '_clms_program_entry_profile', true ),
				'exit_profile'        => (string) get_post_meta( $post->ID, '_clms_program_exit_profile', true ),
				'methodology'         => (string) get_post_meta( $post->ID, '_clms_program_methodology', true ),
				'evidence'            => $this->normalize_lines_meta( get_post_meta( $post->ID, '_clms_program_evidence', true ) ),
				'evaluation_criteria' => $this->normalize_lines_meta( get_post_meta( $post->ID, '_clms_program_evaluation_criteria', true ) ),
				'certification'       => (string) get_post_meta( $post->ID, '_clms_program_certification', true ),
			);
		}

		return $data;
	}

	protected function prepare_lesson_response( $post, $full = false ) {
		$post = get_post( $post );

		if ( ! $post || 'lm_lesson' !== $post->post_type ) {
			return array();
		}

		$course_id = class_exists( 'CLMS_Helper' ) ? absint( CLMS_Helper::get_course_id_from_lesson( $post->ID ) ) : 0;

		$assessment_settings = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Assessment_Engine') : null;
		$assessment_settings = ( $assessment_settings && method_exists( $assessment_settings, 'get_lesson_evaluation_settings' ) ) ? $assessment_settings->get_lesson_evaluation_settings( $post->ID ) : array();

		$data = array(
			'id'                 => $post->ID,
			'title'              => get_the_title( $post->ID ),
			'slug'               => $post->post_name,
			'status'             => $post->post_status,
			'link'               => get_permalink( $post->ID ),
			'teacher_id'         => (int) $post->post_author,
			'teacher_name'       => get_the_author_meta( 'display_name', $post->post_author ),
			'course_id'          => $course_id,
			'course_title'       => $course_id ? get_the_title( $course_id ) : '',
			'menu_order'         => (int) $post->menu_order,
			'module'             => (string) get_post_meta( $post->ID, '_clms_lesson_module', true ),
			'due_date'           => (string) get_post_meta( $post->ID, '_clms_due_date', true ),
			'due_date_late'      => (string) get_post_meta( $post->ID, '_clms_due_date_late', true ),
			'due_time'           => (string) get_post_meta( $post->ID, '_clms_due_time', true ),
			'drip_type'          => (string) get_post_meta( $post->ID, '_clms_drip_type', true ),
			'drip_date'          => (string) get_post_meta( $post->ID, '_clms_drip_date', true ),
			'drip_days'          => absint( get_post_meta( $post->ID, '_clms_drip_days_enrolled', true ) ),
			'drip_after_prev'    => absint( get_post_meta( $post->ID, '_clms_drip_days_after_previous', true ) ),
			'quiz_enabled'       => (bool) get_post_meta( $post->ID, '_clms_quiz_enabled', true ),
			'quiz_passing_score' => absint( get_post_meta( $post->ID, '_clms_quiz_passing_score', true ) ),
			'prerequisite_lesson_ids' => class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_lesson_prerequisite_ids( $post->ID ) : array(),
			'evaluation_mode'    => (string) get_post_meta( $post->ID, '_clms_evaluation_mode', true ),
			'rubric_id'          => absint( get_post_meta( $post->ID, '_clms_rubric_id', true ) ),
			'peer_review_enabled' => $this->sanitize_bool( get_post_meta( $post->ID, '_clms_peer_review_enabled', true ) ),
			'peer_review_blind'   => $this->sanitize_bool( get_post_meta( $post->ID, '_clms_peer_review_blind', true ) ),
			'peer_review_training_required' => $this->sanitize_bool( get_post_meta( $post->ID, '_clms_peer_review_training_required', true ) ),
			'peer_review_calibration_enabled' => $this->sanitize_bool( get_post_meta( $post->ID, '_clms_pr_calibration_enabled', true ) ),
			'peer_review_calibration_submission_id' => absint( get_post_meta( $post->ID, '_clms_pr_calibration_submission_id', true ) ),
			'peer_review_calibration_teacher_grade' => absint( get_post_meta( $post->ID, '_clms_pr_calibration_teacher_grade', true ) ),
			'ai_confidence_threshold' => (float) get_post_meta( $post->ID, '_clms_ai_confidence_threshold', true ),
			'ai_confidence_threshold_resolved' => isset( $assessment_settings['ai_confidence_threshold'] ) ? (float) $assessment_settings['ai_confidence_threshold'] : (float) get_post_meta( $post->ID, '_clms_ai_confidence_threshold', true ),
			'ai_confidence_thresholds' => is_array( get_post_meta( $post->ID, '_clms_ai_confidence_thresholds', true ) ) ? get_post_meta( $post->ID, '_clms_ai_confidence_thresholds', true ) : array(),
			'activity_mode'      => (string) get_post_meta( $post->ID, '_clms_activity_mode', true ),
			'activity_type'      => isset( $assessment_settings['activity_type'] ) ? (string) $assessment_settings['activity_type'] : (string) get_post_meta( $post->ID, '_clms_activity_mode', true ),
			'competencies'       => $this->normalize_lines_meta( get_post_meta( $post->ID, '_clms_lesson_competencies', true ) ),
		);

		if ( $full ) {
			$data['content'] = apply_filters( 'the_content', $post->post_content );
			$data['excerpt'] = $post->post_excerpt;
		}

		return $data;
	}

	protected function prepare_submission_response( $submission_id, $lesson_id = 0 ) {
		$submission_id = absint( $submission_id );
		$lesson_id     = absint( $lesson_id );

		if ( ! $submission_id ) {
			return array();
		}

		$post = get_post( $submission_id );

		if ( ! $post ) {
			return array();
		}

		$submission_lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );

		if ( $lesson_id && $lesson_id !== $submission_lesson_id ) {
			return array();
		}

		$course_id  = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		$user_id    = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		$status     = (string) get_post_meta( $submission_id, '_clms_submission_status', true );
		$grade      = get_post_meta( $submission_id, '_clms_submission_grade', true );
		$feedback   = (string) get_post_meta( $submission_id, '_clms_submission_feedback', true );
		$comment    = (string) get_post_meta( $submission_id, '_clms_submission_comment', true );
		$files      = get_post_meta( $submission_id, '_clms_submission_files', true );
		$files      = is_array( $files ) ? array_values( array_filter( array_map( 'absint', $files ) ) ) : array();
		$student    = $user_id ? get_user_by( 'id', $user_id ) : false;
		$submission = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Submission') : null;
		$ai_review  = ( $submission && method_exists( $submission, 'get_ai_review_data' ) ) ? $submission->get_ai_review_data( $submission_id ) : array();
		$assessment = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Assessment_Engine') : null;
		$assessment = ( $assessment && method_exists( $assessment, 'get_submission_grade_record' ) ) ? $assessment->get_submission_grade_record( $submission_id ) : array();
		if ( '' === (string) $grade && isset( $assessment['grade'] ) && '' !== (string) $assessment['grade'] ) {
			$grade = $assessment['grade'];
		}

		return array(
			'id'           => $submission_id,
			'title'        => get_the_title( $submission_id ),
			'user_id'      => $user_id,
			'user_name'    => $student ? ( $student->display_name ? $student->display_name : $student->user_login ) : '',
			'lesson_id'    => $submission_lesson_id,
			'lesson_title' => $submission_lesson_id ? get_the_title( $submission_lesson_id ) : '',
			'course_id'    => $course_id,
			'course_title' => $course_id ? get_the_title( $course_id ) : '',
			'status'       => $status,
			'grade'        => '' !== (string) $grade ? absint( $grade ) : '',
			'feedback'     => $feedback,
			'comment'      => $comment,
			'files'        => $files,
			'edit_link'    => get_edit_post_link( $submission_id, '' ),
			'created_at'   => get_post_field( 'post_date', $submission_id ),
			'updated_at'   => get_post_field( 'post_modified', $submission_id ),
			'ai_review'    => is_array( $ai_review ) ? $ai_review : array(),
			'assessment'   => is_array( $assessment ) ? $assessment : array(),
		);
	}

	protected function save_course_meta( $post_id, $data ) {
		$post_id = absint( $post_id );
		$data    = is_array( $data ) ? $data : array();

		$this->maybe_update_post_meta( $post_id, '_clms_course_subtitle', $data, 'subtitle', 'sanitize_text_field' );
		$this->maybe_update_post_meta( $post_id, '_clms_course_duration', $data, 'duration', 'sanitize_text_field' );
		$this->maybe_update_post_meta( $post_id, '_clms_course_price', $data, 'price', 'sanitize_text_field' );

		if ( array_key_exists( 'benefits', $data ) ) {
			update_post_meta( $post_id, '_clms_course_benefits', $this->normalize_lines_input( $data['benefits'] ) );
		}

		if ( array_key_exists( 'requirements', $data ) ) {
			update_post_meta( $post_id, '_clms_course_requirements', $this->normalize_lines_input( $data['requirements'] ) );
		}

		if ( array_key_exists( 'audience', $data ) ) {
			update_post_meta( $post_id, '_clms_course_target_audience', $this->normalize_lines_input( $data['audience'] ) );
		}
		$this->maybe_update_post_meta( $post_id, '_clms_course_academic_objective_general', $data, 'objective_general', 'sanitize_text_field' );
		if ( array_key_exists( 'objectives_specific', $data ) ) {
			update_post_meta( $post_id, '_clms_course_academic_objectives_specific', $this->normalize_lines_input( $data['objectives_specific'] ) );
		}
		if ( array_key_exists( 'competencies', $data ) ) {
			update_post_meta( $post_id, '_clms_course_competencies', $this->normalize_lines_input( $data['competencies'] ) );
		}
		if ( array_key_exists( 'competencies_struct', $data ) ) {
			$competency_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Competency_Service') : null;
			if ( $competency_service && method_exists( $competency_service, 'save_course_competencies' ) ) {
				$competency_service->save_course_competencies( $post_id, is_array( $data['competencies_struct'] ) ? $data['competencies_struct'] : array() );
			}
		}
		if ( array_key_exists( 'learning_outcomes', $data ) ) {
			update_post_meta( $post_id, '_clms_course_learning_outcomes', $this->normalize_lines_input( $data['learning_outcomes'] ) );
		}
		$this->maybe_update_post_meta( $post_id, '_clms_course_entry_profile', $data, 'entry_profile', 'sanitize_textarea_field' );
		$this->maybe_update_post_meta( $post_id, '_clms_course_exit_profile', $data, 'exit_profile', 'sanitize_textarea_field' );
		$this->maybe_update_post_meta( $post_id, '_clms_course_methodology', $data, 'methodology', 'sanitize_textarea_field' );
		if ( array_key_exists( 'evidence', $data ) ) {
			update_post_meta( $post_id, '_clms_course_evidence', $this->normalize_lines_input( $data['evidence'] ) );
		}
		if ( array_key_exists( 'evaluation_criteria', $data ) ) {
			update_post_meta( $post_id, '_clms_course_evaluation_criteria', $this->normalize_lines_input( $data['evaluation_criteria'] ) );
		}
		$this->maybe_update_post_meta( $post_id, '_clms_course_difficulty', $data, 'difficulty', 'sanitize_key' );
		$this->maybe_update_post_meta( $post_id, '_clms_course_modality', $data, 'modality', 'sanitize_key' );
		$this->maybe_update_post_meta( $post_id, '_clms_course_prerequisites_text', $data, 'prerequisites_text', 'sanitize_textarea_field' );
		$this->maybe_update_post_meta( $post_id, '_clms_course_certification_text', $data, 'certification', 'sanitize_text_field' );

		if ( array_key_exists( 'program_ids', $data ) && class_exists( 'CLMS_Helper' ) ) {
			CLMS_Helper::assign_course_to_programs( $post_id, is_array( $data['program_ids'] ) ? $data['program_ids'] : array() );
		}

		if ( array_key_exists( 'prerequisite_course_ids', $data ) && class_exists( 'CLMS_Helper' ) ) {
			$prerequisite_ids = array_values(
				array_diff(
					array_unique( array_map( 'absint', is_array( $data['prerequisite_course_ids'] ) ? $data['prerequisite_course_ids'] : array() ) ),
					array( $post_id )
				)
			);
			update_post_meta( $post_id, CLMS_Helper::COURSE_PREREQUISITES_META, $prerequisite_ids );
		}
	}

	protected function save_program_meta( $post_id, $data ) {
		$post_id = absint( $post_id );
		$data    = is_array( $data ) ? $data : array();

		$this->maybe_update_post_meta( $post_id, '_clms_program_subtitle', $data, 'subtitle', 'sanitize_text_field' );
		$this->maybe_update_post_meta( $post_id, '_clms_program_duration', $data, 'duration', 'sanitize_text_field' );
		$this->maybe_update_post_meta( $post_id, '_clms_program_objective_general', $data, 'objective_general', 'sanitize_text_field' );
		if ( array_key_exists( 'competencies', $data ) ) {
			update_post_meta( $post_id, '_clms_program_competencies', $this->normalize_lines_input( $data['competencies'] ) );
		}
		if ( array_key_exists( 'learning_outcomes', $data ) ) {
			update_post_meta( $post_id, '_clms_program_learning_outcomes', $this->normalize_lines_input( $data['learning_outcomes'] ) );
		}
		$this->maybe_update_post_meta( $post_id, '_clms_program_entry_profile', $data, 'entry_profile', 'sanitize_textarea_field' );
		$this->maybe_update_post_meta( $post_id, '_clms_program_exit_profile', $data, 'exit_profile', 'sanitize_textarea_field' );
		$this->maybe_update_post_meta( $post_id, '_clms_program_methodology', $data, 'methodology', 'sanitize_textarea_field' );
		if ( array_key_exists( 'evidence', $data ) ) {
			update_post_meta( $post_id, '_clms_program_evidence', $this->normalize_lines_input( $data['evidence'] ) );
		}
		if ( array_key_exists( 'evaluation_criteria', $data ) ) {
			update_post_meta( $post_id, '_clms_program_evaluation_criteria', $this->normalize_lines_input( $data['evaluation_criteria'] ) );
		}
		$this->maybe_update_post_meta( $post_id, '_clms_program_difficulty', $data, 'difficulty', 'sanitize_key' );
		$this->maybe_update_post_meta( $post_id, '_clms_program_modality', $data, 'modality', 'sanitize_key' );
		$this->maybe_update_post_meta( $post_id, '_clms_program_certification', $data, 'certification', 'sanitize_text_field' );

		if ( array_key_exists( 'course_ids', $data ) && class_exists( 'CLMS_Helper' ) ) {
			CLMS_Helper::sync_program_courses( $post_id, is_array( $data['course_ids'] ) ? $data['course_ids'] : array() );
		}
	}

	protected function save_lesson_meta( $post_id, $data ) {
		$post_id = absint( $post_id );
		$data    = is_array( $data ) ? $data : array();

		if ( array_key_exists( 'course_id', $data ) ) {
			$course_id = absint( $data['course_id'] );
			update_post_meta( $post_id, '_clms_course_id', $course_id );
			update_post_meta( $post_id, '_clms_lesson_course_id', $course_id );
			update_post_meta( $post_id, 'course_id', $course_id );
		}

		$this->maybe_update_post_meta( $post_id, '_clms_due_date', $data, 'due_date', 'sanitize_text_field' );
		$this->maybe_update_post_meta( $post_id, '_clms_due_date_late', $data, 'due_date_late', 'sanitize_text_field' );
		$this->maybe_update_post_meta( $post_id, '_clms_due_time', $data, 'due_time', 'sanitize_text_field' );
		$this->maybe_update_post_meta( $post_id, '_clms_drip_type', $data, 'drip_type', 'sanitize_key' );
		$this->maybe_update_post_meta( $post_id, '_clms_drip_date', $data, 'drip_date', 'sanitize_text_field' );

		if ( array_key_exists( 'drip_days_enrolled', $data ) ) {
			update_post_meta( $post_id, '_clms_drip_days_enrolled', absint( $data['drip_days_enrolled'] ) );
		}

		if ( array_key_exists( 'drip_days_after_previous', $data ) ) {
			update_post_meta( $post_id, '_clms_drip_days_after_previous', absint( $data['drip_days_after_previous'] ) );
		}

		if ( array_key_exists( 'quiz_enabled', $data ) ) {
			update_post_meta( $post_id, '_clms_quiz_enabled', $this->sanitize_bool( $data['quiz_enabled'] ) ? 1 : 0 );
		}

		if ( array_key_exists( 'quiz_passing_score', $data ) ) {
			update_post_meta( $post_id, '_clms_quiz_passing_score', $this->sanitize_score( $data['quiz_passing_score'] ) );
		}

		if ( array_key_exists( 'quiz_questions', $data ) && is_array( $data['quiz_questions'] ) ) {
			update_post_meta( $post_id, '_clms_quiz_questions', $this->sanitize_quiz_questions( $data['quiz_questions'] ) );
		}

		if ( array_key_exists( 'evaluation_mode', $data ) ) {
			$mode = sanitize_key( (string) $data['evaluation_mode'] );
			$mode = in_array( $mode, array( 'manual', 'ai_assisted', 'ai_auto_grade', 'peer_review', 'group', 'hybrid' ), true ) ? $mode : 'manual';
			update_post_meta( $post_id, '_clms_evaluation_mode', $mode );
		}

		if ( array_key_exists( 'activity_mode', $data ) ) {
			$activity_mode = sanitize_key( (string) $data['activity_mode'] );
			$allowed_modes = array( 'lectura', 'tarea', 'quiz', 'interactiva' );
			if ( ! in_array( $activity_mode, $allowed_modes, true ) ) {
				$activity_mode = 'lectura';
			}
			update_post_meta( $post_id, '_clms_activity_mode', $activity_mode );
		}

		if ( array_key_exists( 'rubric_id', $data ) ) {
			$rubric_id = absint( $data['rubric_id'] );
			if ( $rubric_id && 'clms_rubric' !== get_post_type( $rubric_id ) ) {
				$rubric_id = 0;
			}
			update_post_meta( $post_id, '_clms_rubric_id', $rubric_id );
		}

		if ( array_key_exists( 'peer_review_enabled', $data ) ) {
			update_post_meta( $post_id, '_clms_peer_review_enabled', $this->sanitize_bool( $data['peer_review_enabled'] ) ? '1' : '0' );
		}
		if ( array_key_exists( 'peer_review_blind', $data ) ) {
			update_post_meta( $post_id, '_clms_peer_review_blind', $this->sanitize_bool( $data['peer_review_blind'] ) ? '1' : '0' );
		}
		if ( array_key_exists( 'peer_review_training_required', $data ) ) {
			update_post_meta( $post_id, '_clms_peer_review_training_required', $this->sanitize_bool( $data['peer_review_training_required'] ) ? '1' : '0' );
		}
		if ( array_key_exists( 'peer_review_calibration_enabled', $data ) ) {
			update_post_meta( $post_id, '_clms_pr_calibration_enabled', $this->sanitize_bool( $data['peer_review_calibration_enabled'] ) ? '1' : '0' );
		}
		if ( array_key_exists( 'peer_review_calibration_submission_id', $data ) ) {
			update_post_meta( $post_id, '_clms_pr_calibration_submission_id', absint( $data['peer_review_calibration_submission_id'] ) );
		}
		if ( array_key_exists( 'peer_review_calibration_teacher_grade', $data ) ) {
			update_post_meta( $post_id, '_clms_pr_calibration_teacher_grade', max( 0, min( 100, absint( $data['peer_review_calibration_teacher_grade'] ) ) ) );
		}

		if ( array_key_exists( 'ai_confidence_threshold', $data ) ) {
			$threshold = max( 0.5, min( 0.99, (float) $data['ai_confidence_threshold'] ) );
			update_post_meta( $post_id, '_clms_ai_confidence_threshold', $threshold );
		}

		if ( array_key_exists( 'ai_confidence_thresholds', $data ) ) {
			$thresholds = is_array( $data['ai_confidence_thresholds'] ) ? $data['ai_confidence_thresholds'] : array();
			$normalized = array();

			foreach ( array( 'lectura', 'tarea', 'quiz' ) as $threshold_type ) {
				$value = isset( $thresholds[ $threshold_type ] ) ? (float) $thresholds[ $threshold_type ] : (float) get_post_meta( $post_id, '_clms_ai_confidence_threshold', true );
				$normalized[ $threshold_type ] = max( 0.5, min( 0.99, $value > 0 ? $value : 0.75 ) );
			}

			update_post_meta( $post_id, '_clms_ai_confidence_thresholds', $normalized );
		}

		if ( array_key_exists( 'prerequisite_lesson_ids', $data ) && class_exists( 'CLMS_Helper' ) ) {
			$prerequisite_ids = array_values(
				array_diff(
					array_unique( array_map( 'absint', is_array( $data['prerequisite_lesson_ids'] ) ? $data['prerequisite_lesson_ids'] : array() ) ),
					array( $post_id )
				)
			);
			update_post_meta( $post_id, CLMS_Helper::LESSON_PREREQUISITES_META, $prerequisite_ids );
		}

		if ( array_key_exists( 'module', $data ) ) {
			update_post_meta( $post_id, '_clms_lesson_module', sanitize_text_field( (string) $data['module'] ) );
		}
		if ( array_key_exists( 'competencies', $data ) ) {
			update_post_meta( $post_id, '_clms_lesson_competencies', $this->normalize_lines_input( $data['competencies'] ) );
		}
	}

	protected function normalize_lesson_quick_edit_data( array $data, string $current_drip_type = '' ): array {
		$normalized = array();

		if ( array_key_exists( 'evaluation_mode', $data ) ) {
			$normalized['evaluation_mode'] = sanitize_key( (string) $data['evaluation_mode'] );
		}

		if ( array_key_exists( 'rubric_id', $data ) ) {
			$normalized['rubric_id'] = absint( $data['rubric_id'] );
		}

		if ( array_key_exists( 'peer_review_enabled', $data ) ) {
			$normalized['peer_review_enabled'] = $this->sanitize_bool( $data['peer_review_enabled'] );
		}
		if ( array_key_exists( 'peer_review_blind', $data ) ) {
			$normalized['peer_review_blind'] = $this->sanitize_bool( $data['peer_review_blind'] );
		}
		if ( array_key_exists( 'peer_review_training_required', $data ) ) {
			$normalized['peer_review_training_required'] = $this->sanitize_bool( $data['peer_review_training_required'] );
		}
		if ( array_key_exists( 'peer_review_calibration_enabled', $data ) ) {
			$normalized['peer_review_calibration_enabled'] = $this->sanitize_bool( $data['peer_review_calibration_enabled'] );
		}
		if ( array_key_exists( 'peer_review_calibration_submission_id', $data ) ) {
			$normalized['peer_review_calibration_submission_id'] = absint( $data['peer_review_calibration_submission_id'] );
		}
		if ( array_key_exists( 'peer_review_calibration_teacher_grade', $data ) ) {
			$normalized['peer_review_calibration_teacher_grade'] = max( 0, min( 100, absint( $data['peer_review_calibration_teacher_grade'] ) ) );
		}

		if ( array_key_exists( 'activity_mode', $data ) ) {
			$normalized['activity_mode'] = sanitize_key( (string) $data['activity_mode'] );
		}

		if ( array_key_exists( 'ai_confidence_threshold', $data ) ) {
			$normalized['ai_confidence_threshold'] = (float) $data['ai_confidence_threshold'];
		}

		$has_drip_changes = array_key_exists( 'drip_type', $data ) || array_key_exists( 'drip_value', $data ) || array_key_exists( 'drip_date', $data );
		if ( $has_drip_changes ) {
			$drip_type = array_key_exists( 'drip_type', $data ) ? sanitize_key( (string) $data['drip_type'] ) : sanitize_key( $current_drip_type );
			if ( ! in_array( $drip_type, array( 'none', 'date', 'days_enrolled', 'days_after_previous' ), true ) ) {
				$drip_type = 'none';
			}

			$normalized['drip_type'] = $drip_type;

			if ( 'date' === $drip_type ) {
				$drip_date                = array_key_exists( 'drip_date', $data ) ? (string) $data['drip_date'] : (string) ( $data['drip_value'] ?? '' );
				$normalized['drip_date']  = sanitize_text_field( $drip_date );
				$normalized['drip_days_enrolled']      = 0;
				$normalized['drip_days_after_previous'] = 0;
			} elseif ( 'days_enrolled' === $drip_type ) {
				$normalized['drip_days_enrolled']       = absint( $data['drip_value'] ?? $data['drip_days_enrolled'] ?? 0 );
				$normalized['drip_days_after_previous'] = 0;
				$normalized['drip_date']                = '';
			} elseif ( 'days_after_previous' === $drip_type ) {
				$normalized['drip_days_after_previous'] = absint( $data['drip_value'] ?? $data['drip_days_after_previous'] ?? 0 );
				$normalized['drip_days_enrolled']       = 0;
				$normalized['drip_date']                = '';
			} else {
				$normalized['drip_date']                = '';
				$normalized['drip_days_enrolled']       = 0;
				$normalized['drip_days_after_previous'] = 0;
			}
		}

		return $normalized;
	}

	protected function get_lesson_presets_store(): array {
		$stored = get_option( 'clms_lesson_presets', array() );
		$stored = is_array( $stored ) ? $stored : array();

		if ( empty( $stored ) ) {
			$stored = $this->get_default_lesson_presets();
			update_option( 'clms_lesson_presets', $stored, false );
		}

		$clean = array();
		foreach ( $stored as $preset ) {
			if ( ! is_array( $preset ) ) {
				continue;
			}
			$id     = isset( $preset['id'] ) ? sanitize_key( (string) $preset['id'] ) : '';
			$name   = isset( $preset['name'] ) ? sanitize_text_field( (string) $preset['name'] ) : '';
			$config = isset( $preset['config'] ) && is_array( $preset['config'] ) ? $this->normalize_lesson_quick_edit_data( $preset['config'] ) : array();
			if ( ! $id || ! $name || empty( $config ) ) {
				continue;
			}
			$clean[] = array(
				'id'     => $id,
				'name'   => $name,
				'config' => $config,
			);
		}

		return $clean;
	}

	protected function get_default_lesson_presets(): array {
		return array(
			array(
				'id'     => 'default_task_ai',
				'name'   => __( 'Tarea + AI assisted', 'atora-lms' ),
				'config' => array(
					'evaluation_mode'       => 'ai_assisted',
					'activity_mode'         => 'tarea',
					'peer_review_enabled'   => false,
					'ai_confidence_threshold' => 0.75,
					'drip_type'             => 'none',
				),
			),
			array(
				'id'     => 'default_quiz_auto',
				'name'   => __( 'Quiz auto-evaluado', 'atora-lms' ),
				'config' => array(
					'evaluation_mode'       => 'ai_auto_grade',
					'activity_mode'         => 'quiz',
					'peer_review_enabled'   => false,
					'ai_confidence_threshold' => 0.8,
					'drip_type'             => 'none',
				),
			),
			array(
				'id'     => 'default_reading',
				'name'   => __( 'Lectura sin evaluación', 'atora-lms' ),
				'config' => array(
					'evaluation_mode'     => 'manual',
					'activity_mode'       => 'lectura',
					'peer_review_enabled' => false,
					'drip_type'           => 'none',
				),
			),
		);
	}

	protected function get_json_or_body_params( WP_REST_Request $request ) {
		$data = $request->get_json_params();

		if ( ! is_array( $data ) || empty( $data ) ) {
			$data = $request->get_body_params();
		}

		return is_array( $data ) ? $data : array();
	}

	protected function get_quiz_data( $lesson_id ) {
		$lesson_id = absint( $lesson_id );

		return array(
			'enabled'       => (bool) get_post_meta( $lesson_id, '_clms_quiz_enabled', true ),
			'passing_score' => absint( get_post_meta( $lesson_id, '_clms_quiz_passing_score', true ) ),
			'questions'     => $this->sanitize_quiz_questions( get_post_meta( $lesson_id, '_clms_quiz_questions', true ) ),
		);
	}

	/**
	 * Competencias estructuradas del curso para REST.
	 *
	 * @param int $course_id Curso.
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_course_competencies_struct( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id || ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$service = clms_core('CLMS_Competency_Service');
		if ( ! $service || ! method_exists( $service, 'get_course_competencies' ) ) {
			return array();
		}

		$items = (array) $service->get_course_competencies( $course_id );
		$out   = array();
		foreach ( $items as $item ) {
			$item = is_array( $item ) ? $item : array();
			$out[] = array(
				'id'          => sanitize_key( (string) ( $item['id'] ?? '' ) ),
				'title'       => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
				'level'       => sanitize_key( (string) ( $item['level'] ?? 'basic' ) ),
				'required'    => ! empty( $item['required'] ),
				'weight'      => absint( $item['weight'] ?? 0 ),
			);
		}

		return $out;
	}

	protected function maybe_update_post_meta( $post_id, $meta_key, $data, $field_key, $sanitize_callback = null ) {
		if ( ! array_key_exists( $field_key, $data ) ) {
			return;
		}

		$value = $data[ $field_key ];

		if ( $sanitize_callback && is_callable( $sanitize_callback ) ) {
			$value = call_user_func( $sanitize_callback, $value );
		}

		update_post_meta( $post_id, $meta_key, $value );
	}

	protected function normalize_lines_input( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( "\n", array_map( 'sanitize_text_field', $value ) );
		}

		$value = (string) $value;
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );

		$lines = array_filter(
			array_map(
				'trim',
				explode( "\n", $value )
			)
		);

		return array_values( $lines );
	}

	protected function normalize_lines_meta( $value ) {
		if ( is_array( $value ) ) {
			return array_values(
				array_filter(
					array_map( 'sanitize_text_field', $value )
				)
			);
		}

		return $this->normalize_lines_input( $value );
	}

	protected function sanitize_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (bool) absint( $value );
		}

		$value = strtolower( trim( (string) $value ) );

		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}

	protected function sanitize_score( $value ) {
		$value = absint( $value );

		if ( $value < 0 ) {
			$value = 0;
		}

		if ( $value > 100 ) {
			$value = 100;
		}

		return $value;
	}

	protected function sanitize_per_page( $value ) {
		$value = absint( $value );

		if ( $value < 1 ) {
			$value = 20;
		}

		// Allow managers to request up to 500 items (e.g. curriculum builder loading all lessons).
		$max = ( CLMS_Access::can_manage_lessons() || CLMS_Access::can_manage_courses() ) ? 500 : 100;
		if ( $value > $max ) {
			$value = $max;
		}

		return $value;
	}

	protected function get_course_enrolled_student_ids( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return array();
		}

		$student_ids = array();

		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_enrolled_student_ids' ) ) {
			$student_ids = CLMS_Helper::get_enrolled_student_ids( $course_id );
		}

		if ( empty( $student_ids ) ) {
			$student_ids = get_post_meta( $course_id, '_clms_enrolled_users', true );
		}

		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $student_ids ) ? $student_ids : array() ) ) ) );
	}

	protected function build_students_bulk_export_rows( $course_id, array $student_ids ) {
		$course_id   = absint( $course_id );
		$student_ids = array_values( array_unique( array_filter( array_map( 'absint', $student_ids ) ) ) );

		if ( ! $course_id || empty( $student_ids ) ) {
			return array();
		}

		$lesson_ids = array();
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_lessons' ) ) {
			$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );
			$lesson_ids = is_array( $lesson_ids ) ? array_values( array_filter( array_map( 'absint', $lesson_ids ) ) ) : array();
		}

		$submission_type = class_exists( 'CLMS_Submission' ) ? CLMS_Submission::CPT : 'clms_submission';
		$grades_map      = array();
		$activity_map    = array();

		if ( ! empty( $lesson_ids ) ) {
			$submission_ids = get_posts(
				array(
					'post_type'      => $submission_type,
					'post_status'    => array( 'publish', 'private' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'key'     => '_clms_submission_lesson_id',
							'value'   => $lesson_ids,
							'compare' => 'IN',
						),
						array(
							'key'     => '_clms_submission_user_id',
							'value'   => $student_ids,
							'compare' => 'IN',
						),
					),
				)
			);

			foreach ( $submission_ids as $submission_id ) {
				$submission_id = absint( $submission_id );
				if ( ! $submission_id ) {
					continue;
				}

				$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
				if ( ! $student_id ) {
					continue;
				}

				$updated_timestamp = get_post_modified_time( 'U', false, $submission_id );
				if ( $updated_timestamp ) {
					$updated_timestamp = absint( $updated_timestamp );
					if ( empty( $activity_map[ $student_id ] ) || $updated_timestamp > $activity_map[ $student_id ] ) {
						$activity_map[ $student_id ] = $updated_timestamp;
					}
				}

				$status = (string) get_post_meta( $submission_id, '_clms_submission_status', true );
				$grade  = get_post_meta( $submission_id, '_clms_submission_grade', true );

				if ( 'graded' === $status && '' !== (string) $grade ) {
					if ( ! isset( $grades_map[ $student_id ] ) ) {
						$grades_map[ $student_id ] = array();
					}
					$grades_map[ $student_id ][] = absint( $grade );
				}
			}
		}

		$datetime_format = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		if ( '' === $datetime_format ) {
			$datetime_format = 'Y-m-d H:i';
		}

		$rows = array();

		foreach ( $student_ids as $student_id ) {
			$user = get_userdata( $student_id );
			if ( ! $user ) {
				continue;
			}

			$completed = get_user_meta( $student_id, '_clms_completed_lessons', true );
			$completed = is_array( $completed ) ? array_values( array_map( 'absint', $completed ) ) : array();
			$progress  = 0;

			if ( ! empty( $lesson_ids ) ) {
				$done     = count( array_intersect( $lesson_ids, $completed ) );
				$progress = (int) round( ( $done / count( $lesson_ids ) ) * 100 );
			}

			$last_activity = __( 'Sin actividad', 'atora-lms' );
			$last_seen     = isset( $activity_map[ $student_id ] ) ? absint( $activity_map[ $student_id ] ) : 0;
			if ( $last_seen > 0 ) {
				$last_activity = wp_date( $datetime_format, $last_seen );
			}

			$avg_grade = '';
			if ( ! empty( $grades_map[ $student_id ] ) ) {
				$avg_grade = (int) round( array_sum( $grades_map[ $student_id ] ) / count( $grades_map[ $student_id ] ) ) . '%';
			}

			$rows[] = array(
				'name'          => $user->display_name ? $user->display_name : $user->user_login,
				'email'         => $user->user_email,
				'progress'      => $progress . '%',
				'last_activity' => $last_activity,
				'average_grade' => $avg_grade,
			);
		}

		usort(
			$rows,
			static function( $a, $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return $rows;
	}

	protected function sanitize_quiz_questions( $questions ) {
		if ( ! is_array( $questions ) ) {
			return array();
		}

		$clean = array();

		foreach ( $questions as $question ) {
			if ( ! is_array( $question ) ) {
				continue;
			}

			$item = array(
				'question' => isset( $question['question'] ) ? sanitize_text_field( $question['question'] ) : '',
				'type'     => isset( $question['type'] ) ? sanitize_key( $question['type'] ) : 'single',
				'options'  => array(),
				'answer'   => isset( $question['answer'] ) ? $question['answer'] : '',
			);

			if ( isset( $question['options'] ) && is_array( $question['options'] ) ) {
				foreach ( $question['options'] as $option ) {
					$option = sanitize_text_field( $option );

					if ( '' !== $option ) {
						$item['options'][] = $option;
					}
				}
			}

			if ( is_array( $item['answer'] ) ) {
				$item['answer'] = array_values( array_map( 'absint', $item['answer'] ) );
			} elseif ( is_numeric( $item['answer'] ) ) {
				$item['answer'] = absint( $item['answer'] );
			} else {
				$item['answer'] = sanitize_text_field( (string) $item['answer'] );
			}

			if ( '' === $item['question'] ) {
				continue;
			}

			$clean[] = $item;
		}

		return array_values( $clean );
	}

	/**
	 * Batch reorder lessons dentro de un curso.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reorder_lessons( WP_REST_Request $request ) {
		$data      = $this->get_json_or_body_params( $request );
		$course_id = absint( $data['course_id'] ?? 0 );
		$items     = (array) ( $data['items'] ?? array() );

		// Validación: curso existe y es publicado.
		if ( ! $course_id ) {
			return new WP_Error( 'clms_missing_course_id', __( 'El ID del curso es obligatorio.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$course = get_post( $course_id );
		if ( ! $course || 'lm_course' !== $course->post_type ) {
			return new WP_Error( 'clms_course_not_found', __( 'Curso no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		// Validación: permisos.
		if ( ! $this->current_user_can_manage_post_resource( $course_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para reordenar lecciones en este curso.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'clms_empty_items', __( 'La lista de lecciones no puede estar vacía.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		// Procesar cada item.
		$updated_items = array();

		foreach ( $items as $item ) {
			$lesson_id = absint( $item['id'] ?? 0 );
			$menu_order = absint( $item['menu_order'] ?? 0 );
			$module = sanitize_text_field( $item['module'] ?? '' );

			if ( ! $lesson_id ) {
				continue;
			}

			// Validación: lección existe.
			$lesson = get_post( $lesson_id );
			if ( ! $lesson || 'lm_lesson' !== $lesson->post_type ) {
				continue;
			}

			// Validación: lección pertenece al curso.
			if ( class_exists( 'CLMS_Helper' ) ) {
				$lesson_course_id = CLMS_Helper::get_lesson_course_id( $lesson_id );
				if ( $lesson_course_id !== $course_id ) {
					continue;
				}
			}

			// Actualizar menu_order y módulo.
			wp_update_post( array(
				'ID'         => $lesson_id,
				'menu_order' => $menu_order,
			) );

			update_post_meta( $lesson_id, '_clms_lesson_module', $module );

			// Incluir en respuesta.
			$updated_items[] = array(
				'id'         => $lesson_id,
				'menu_order' => $menu_order,
				'module'     => $module,
				'title'      => $lesson->post_title,
			);
		}

		// Invalidar cachés.
		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::flush_course( $course_id );
		}

		if ( class_exists( 'CLMS_Helper' ) ) {
			CLMS_Helper::flush_runtime_cache( $course_id );
		}

		return rest_ensure_response( array(
			'success' => true,
			'course_id' => $course_id,
			'items'   => $updated_items,
		) );
	}

	/**
	 * Batch reorder cursos dentro de un programa.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reorder_programs( WP_REST_Request $request ) {
		$data        = $this->get_json_or_body_params( $request );
		$program_id  = absint( $data['program_id'] ?? 0 );
		$course_ids  = (array) ( $data['course_ids'] ?? array() );

		// Validación: programa existe y es publicado.
		if ( ! $program_id ) {
			return new WP_Error( 'clms_missing_program_id', __( 'El ID del programa es obligatorio.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$program = get_post( $program_id );
		if ( ! $program || 'lm_program' !== $program->post_type ) {
			return new WP_Error( 'clms_program_not_found', __( 'Programa no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		// Validación: permisos.
		if ( ! $this->current_user_can_manage_post_resource( $program_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para reordenar cursos en este programa.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		// Normalizar y validar IDs de cursos (lista vacía = eliminar todos, permitido).
		$valid_course_ids = array();
		foreach ( $course_ids as $cid ) {
			$cid = absint( $cid );
			if ( $cid ) {
				$course = get_post( $cid );
				if ( $course && 'lm_course' === $course->post_type ) {
					$valid_course_ids[] = $cid;
				}
			}
		}

		// Llamar al helper para sincronizar cursos (si existe).
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'sync_program_courses' ) ) {
			CLMS_Helper::sync_program_courses( $program_id, $valid_course_ids );
		} else {
			// Fallback: actualizar directamente la meta.
			update_post_meta( $program_id, CLMS_Helper::PROGRAM_COURSES_META, $valid_course_ids );
		}

		// Invalidar cachés.
		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::flush_program( $program_id );
		}

		return rest_ensure_response( array(
			'success'    => true,
			'program_id' => $program_id,
			'course_ids' => $valid_course_ids,
		) );
	}

	/**
	 * Sanitiza competencias para salida REST.
	 *
	 * @param mixed $competencies Competencias.
	 * @return array<int,array<string,mixed>>
	 */
	protected function sanitize_rest_competencies( $competencies ) {
		$competencies = is_array( $competencies ) ? $competencies : array();
		$list = array();
		foreach ( $competencies as $competency ) {
			$competency = is_array( $competency ) ? $competency : array();
			$list[] = array(
				'competency_id'       => sanitize_key( (string) ( $competency['competency_id'] ?? '' ) ),
				'title'               => sanitize_text_field( (string) ( $competency['title'] ?? '' ) ),
				'status'              => sanitize_key( (string) ( $competency['status'] ?? 'sin_evidencia' ) ),
				'score'               => absint( $competency['score'] ?? 0 ),
				'completed_evidences' => absint( $competency['completed_evidences'] ?? 0 ),
				'required_evidences'  => absint( $competency['required_evidences'] ?? 0 ),
				'recommendation'      => sanitize_text_field( (string) ( $competency['recommendation'] ?? '' ) ),
			);
		}

		return $list;
	}

	/**
	 * Sanitiza evidencias para salida REST.
	 *
	 * @param mixed $evidences Evidencias.
	 * @return array<string,mixed>
	 */
	protected function sanitize_rest_evidences( $evidences ) {
		$evidences = is_array( $evidences ) ? $evidences : array();

		$items = isset( $evidences['items'] ) && is_array( $evidences['items'] ) ? $evidences['items'] : array();
		$clean_items = array();
		foreach ( $items as $item ) {
			$item = is_array( $item ) ? $item : array();
			$clean_items[] = array(
				'activity_id'                 => absint( $item['activity_id'] ?? 0 ),
				'activity_title'              => sanitize_text_field( (string) ( $item['activity_title'] ?? '' ) ),
				'evidence_type'               => sanitize_key( (string) ( $item['evidence_type'] ?? 'practice' ) ),
				'is_required_for_certificate' => ! empty( $item['is_required_for_certificate'] ),
				'minimum_grade'               => absint( $item['minimum_grade'] ?? 0 ),
				'status'                      => sanitize_key( (string) ( $item['status'] ?? '' ) ),
				'grade'                       => isset( $item['grade'] ) && is_numeric( $item['grade'] ) ? absint( $item['grade'] ) : null,
				'approved'                    => ! empty( $item['approved'] ),
				'competency_ids'              => isset( $item['competency_ids'] ) && is_array( $item['competency_ids'] ) ? array_values( array_map( 'sanitize_key', $item['competency_ids'] ) ) : array(),
			);
		}

		return array(
			'required_total'    => absint( $evidences['required_total'] ?? 0 ),
			'required_approved' => absint( $evidences['required_approved'] ?? 0 ),
			'required_pending'  => absint( $evidences['required_pending'] ?? 0 ),
			'items'             => $clean_items,
		);
	}

	/**
	 * Resuelve el servicio institucional de reportes.
	 *
	 * @return CLMS_Academic_Report_Service|null
	 */
	protected function resolve_academic_report_service() {
		$service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Report_Service') : null;
		if ( $service ) {
			return $service;
		}

		if ( class_exists( 'CLMS_Academic_Report_Service' ) ) {
			return new CLMS_Academic_Report_Service();
		}

		return null;
	}
}
