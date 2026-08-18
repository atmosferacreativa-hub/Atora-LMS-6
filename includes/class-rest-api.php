<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/rest/class-rest-permissions.php';
require_once __DIR__ . '/rest/class-rest-routes.php';
require_once __DIR__ . '/rest/class-rest-public-controller.php';
require_once __DIR__ . '/rest/class-rest-academics-controller.php';
require_once __DIR__ . '/rest/class-rest-extensions-controller.php';
require_once __DIR__ . '/rest/class-rest-grading-controller.php';
require_once __DIR__ . '/gradebook/class-gradebook-normalizer.php';
require_once __DIR__ . '/gradebook/class-gradebook-schema-service.php';
require_once __DIR__ . '/gradebook/class-gradebook-calculation-service.php';
require_once __DIR__ . '/gradebook/class-gradebook-grid-service.php';
require_once __DIR__ . '/gradebook/class-gradebook-service.php';
require_once __DIR__ . '/gradebook/class-gradebook-save-service.php';
require_once __DIR__ . '/gradebook/class-gradebook-certificate-eligibility-service.php';
require_once __DIR__ . '/gradebook/class-gradebook-export-service.php';
require_once __DIR__ . '/rest/class-rest-gradebook-controller.php';

class CLMS_REST_API {

	const API_NAMESPACE = 'clms/v1';

	protected $permissions;
	protected $public_controller;
	protected $academics_controller;
	protected $extensions_controller;
	protected $grading_controller;
	protected $gradebook_controller;

	public function __construct() {
		$this->permissions           = new CLMS_REST_Permissions();
		$this->public_controller     = new CLMS_REST_Public_Controller( $this->permissions );
		$this->academics_controller  = new CLMS_REST_Academics_Controller( $this->permissions );
		$this->extensions_controller = new CLMS_REST_Extensions_Controller( $this->permissions );
		$this->grading_controller    = new CLMS_REST_Grading_Controller( $this->permissions );
		$this->gradebook_controller  = new CLMS_REST_Gradebook_Controller( $this->permissions );

		add_action( 'init', array( $this, 'register_rest_meta' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'guard_native_lesson_rest_access' ), 10, 3 );
	}

	/* --------------------------------------------------------------
	 * BOOT
	 * -------------------------------------------------------------- */

	public function register_rest_meta() {
		$common_auth = array( $this, 'can_read_lesson_meta' );

		register_post_meta(
			'lm_lesson',
			'_clms_course_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $common_auth,
			)
		);

		register_post_meta(
			'lm_lesson',
			'_clms_lesson_course_id',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $common_auth,
			)
		);

		register_post_meta(
			'lm_lesson',
			'_clms_drip_type',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => $common_auth,
			)
		);

		register_post_meta(
			'lm_lesson',
			'_clms_drip_date',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $common_auth,
			)
		);

		register_post_meta(
			'lm_lesson',
			'_clms_drip_days_enrolled',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $common_auth,
			)
		);

		register_post_meta(
			'lm_lesson',
			'_clms_drip_days_after_previous',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $common_auth,
			)
		);

		register_post_meta(
			'lm_lesson',
			'_clms_due_date',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $common_auth,
			)
		);

		register_post_meta(
			'lm_lesson',
			'_clms_due_date_late',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $common_auth,
			)
		);

		register_post_meta(
			'lm_lesson',
			'_clms_due_time',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $common_auth,
			)
		);

		register_post_meta(
			'lm_lesson',
			'_clms_quiz_enabled',
			array(
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'auth_callback'     => $common_auth,
			)
		);

		register_post_meta(
			'lm_lesson',
			'_clms_quiz_passing_score',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_score' ),
				'auth_callback'     => $common_auth,
			)
		);

		register_post_meta(
			'lm_lesson',
			'_clms_quiz_questions',
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( $this, 'sanitize_quiz_questions' ),
				'auth_callback'     => array( $this, 'can_manage_content' ),
			)
		);
	}

	public function register_routes() {
		$router = new CLMS_REST_Routes( $this );
		$router->register();
	}

	/* --------------------------------------------------------------
	 * PERMISSIONS
	 * -------------------------------------------------------------- */

	public function can_read_course( WP_REST_Request $request ) {
		return $this->permissions->can_read_course( $request );
	}

	public function can_read_program( WP_REST_Request $request ) {
		return $this->permissions->can_read_program( $request );
	}

	public function can_read_public( WP_REST_Request $request ) {
		return $this->permissions->can_read_public( $request );
	}

	public function can_read_lesson( WP_REST_Request $request ) {
		return $this->permissions->can_read_lesson( $request );
	}

	public function can_access_logged_in() {
		return $this->permissions->can_access_logged_in();
	}

	public function can_manage_content() {
		return $this->permissions->can_manage_content();
	}

	public function can_read_lesson_meta( $allowed = false, $meta_key = '', $post_id = 0, $user_id = 0 ) {
		return $this->permissions->can_read_lesson_meta( $allowed, $meta_key, $post_id, $user_id );
	}

	public function can_view_user_resource( WP_REST_Request $request ) {
		return $this->permissions->can_view_user_resource( $request );
	}

	protected function current_user_can_manage_post_resource( $post_id ) {
		return $this->permissions->current_user_can_manage_post_resource( $post_id );
	}

	protected function current_user_can_assign_author( $author_id ) {
		return $this->permissions->current_user_can_assign_author( $author_id );
	}

	protected function current_user_can_view_submission_resource( $submission_id, $requested_user_id = 0 ) {
		return $this->permissions->current_user_can_view_submission_resource( $submission_id, $requested_user_id );
	}

	protected function filter_course_ids_for_current_viewer( array $course_ids, $requested_user_id ) {
		return $this->permissions->filter_course_ids_for_current_viewer( $course_ids, $requested_user_id );
	}

	public function guard_native_lesson_rest_access( $result, $server, $request ) {
		return $this->permissions->guard_native_lesson_rest_access( $result, $server, $request );
	}

	/* --------------------------------------------------------------
	 * OPENAPI + PERFORMANCE
	 * -------------------------------------------------------------- */

	public function get_openapi_spec( WP_REST_Request $request ) {
		return $this->extensions_controller->get_openapi_spec( $request );
	}

	public function get_public_openapi_spec( WP_REST_Request $request ) {
		return $this->extensions_controller->get_public_openapi_spec( $request );
	}

	public function get_performance_status( WP_REST_Request $request ) {
		return $this->extensions_controller->get_performance_status( $request );
	}

	/* --------------------------------------------------------------
	 * PUBLIC CATALOG
	 * -------------------------------------------------------------- */

	public function get_public_courses( WP_REST_Request $request ) {
		return $this->public_controller->get_public_courses( $request );
	}

	public function get_public_course( WP_REST_Request $request ) {
		return $this->public_controller->get_public_course( $request );
	}

	public function get_public_programs( WP_REST_Request $request ) {
		return $this->public_controller->get_public_programs( $request );
	}

	public function get_public_program( WP_REST_Request $request ) {
		return $this->public_controller->get_public_program( $request );
	}

	public function get_public_catalog( WP_REST_Request $request ) {
		return $this->public_controller->get_public_catalog( $request );
	}

	public function get_public_catalog_recommendations( WP_REST_Request $request ) {
		return $this->public_controller->get_public_catalog_recommendations( $request );
	}

	/* --------------------------------------------------------------
	 * COURSES
	 * -------------------------------------------------------------- */

	public function get_courses( WP_REST_Request $request ) {
		return $this->academics_controller->get_courses( $request );
	}

	public function get_course( WP_REST_Request $request ) {
		return $this->academics_controller->get_course( $request );
	}

	public function create_course( WP_REST_Request $request ) {
		return $this->academics_controller->create_course( $request );
	}

	public function update_course( WP_REST_Request $request ) {
		return $this->academics_controller->update_course( $request );
	}

	public function delete_course( WP_REST_Request $request ) {
		return $this->academics_controller->delete_course( $request );
	}

	public function enroll_in_course( WP_REST_Request $request ) {
		return $this->academics_controller->enroll_in_course( $request );
	}

	/* --------------------------------------------------------------
	 * PROGRAMS
	 * -------------------------------------------------------------- */

	public function get_programs( WP_REST_Request $request ) {
		return $this->academics_controller->get_programs( $request );
	}

	public function get_program( WP_REST_Request $request ) {
		return $this->academics_controller->get_program( $request );
	}

	public function create_program( WP_REST_Request $request ) {
		return $this->academics_controller->create_program( $request );
	}

	public function update_program( WP_REST_Request $request ) {
		return $this->academics_controller->update_program( $request );
	}

	public function delete_program( WP_REST_Request $request ) {
		return $this->academics_controller->delete_program( $request );
	}

	public function enroll_in_program( WP_REST_Request $request ) {
		return $this->academics_controller->enroll_in_program( $request );
	}

	/* --------------------------------------------------------------
	 * LESSONS + PROGRESS
	 * -------------------------------------------------------------- */

	public function get_lessons( WP_REST_Request $request ) {
		return $this->academics_controller->get_lessons( $request );
	}

	public function get_lesson( WP_REST_Request $request ) {
		return $this->academics_controller->get_lesson( $request );
	}

	public function create_lesson( WP_REST_Request $request ) {
		return $this->academics_controller->create_lesson( $request );
	}

	public function update_lesson( WP_REST_Request $request ) {
		return $this->academics_controller->update_lesson( $request );
	}

	public function delete_lesson( WP_REST_Request $request ) {
		return $this->academics_controller->delete_lesson( $request );
	}

	public function complete_lesson( WP_REST_Request $request ) {
		return $this->academics_controller->complete_lesson( $request );
	}

	public function get_progress( WP_REST_Request $request ) {
		return $this->academics_controller->get_progress( $request );
	}

	public function get_grades( WP_REST_Request $request ) {
		return $this->academics_controller->get_grades( $request );
	}

	public function get_submissions( WP_REST_Request $request ) {
		return $this->academics_controller->get_submissions( $request );
	}

	public function get_submissions_by_lesson( WP_REST_Request $request ) {
		return $this->academics_controller->get_submissions_by_lesson( $request );
	}

	public function get_student_profile_report( WP_REST_Request $request ) {
		return $this->academics_controller->get_student_profile_report( $request );
	}

	public function get_teacher_profile_report( WP_REST_Request $request ) {
		return $this->academics_controller->get_teacher_profile_report( $request );
	}

	public function get_course_advanced_report( WP_REST_Request $request ) {
		return $this->academics_controller->get_course_advanced_report( $request );
	}

	public function get_admin_advanced_report( WP_REST_Request $request ) {
		return $this->academics_controller->get_admin_advanced_report( $request );
	}

	public function get_certification_report( WP_REST_Request $request ) {
		return $this->academics_controller->get_certification_report( $request );
	}

	public function get_risk_indicators( WP_REST_Request $request ) {
		return $this->academics_controller->get_risk_indicators( $request );
	}

	public function get_quiz( WP_REST_Request $request ) {
		return $this->academics_controller->get_quiz( $request );
	}

	public function update_quiz( WP_REST_Request $request ) {
		return $this->academics_controller->update_quiz( $request );
	}

	public function submit_quiz( WP_REST_Request $request ) {
		return $this->academics_controller->submit_quiz( $request );
	}

	/* --------------------------------------------------------------
	 * TEACHER OPERATIONS
	 * -------------------------------------------------------------- */

	public function bulk_students_action( WP_REST_Request $request ) {
		return $this->academics_controller->bulk_students_action( $request );
	}

	public function quick_edit_lesson( WP_REST_Request $request ) {
		return $this->academics_controller->quick_edit_lesson( $request );
	}

	public function get_lesson_presets( WP_REST_Request $request ) {
		return $this->academics_controller->get_lesson_presets( $request );
	}

	public function create_lesson_preset( WP_REST_Request $request ) {
		return $this->academics_controller->create_lesson_preset( $request );
	}

	public function delete_lesson_preset( WP_REST_Request $request ) {
		return $this->academics_controller->delete_lesson_preset( $request );
	}

	public function apply_lesson_preset( WP_REST_Request $request ) {
		return $this->academics_controller->apply_lesson_preset( $request );
	}

	public function reorder_lessons( WP_REST_Request $request ) {
		return $this->academics_controller->reorder_lessons( $request );
	}

	public function reorder_programs( WP_REST_Request $request ) {
		return $this->academics_controller->reorder_programs( $request );
	}

	/* --------------------------------------------------------------
	 * RUBRICS
	 * -------------------------------------------------------------- */

	public function get_rubrics( WP_REST_Request $request ) {
		return $this->extensions_controller->get_rubrics( $request );
	}

	public function create_rubric( WP_REST_Request $request ) {
		return $this->extensions_controller->create_rubric( $request );
	}

	public function get_rubric( WP_REST_Request $request ) {
		return $this->extensions_controller->get_rubric( $request );
	}

	public function update_rubric( WP_REST_Request $request ) {
		return $this->extensions_controller->update_rubric( $request );
	}

	public function delete_rubric( WP_REST_Request $request ) {
		return $this->extensions_controller->delete_rubric( $request );
	}

	public function get_lesson_rubric( WP_REST_Request $request ) {
		return $this->extensions_controller->get_lesson_rubric( $request );
	}

	public function set_lesson_rubric( WP_REST_Request $request ) {
		return $this->extensions_controller->set_lesson_rubric( $request );
	}

	/* --------------------------------------------------------------
	 * TRANSCRIPTIONS
	 * -------------------------------------------------------------- */

	public function get_transcription( WP_REST_Request $request ) {
		return $this->extensions_controller->get_transcription( $request );
	}

	public function trigger_transcription( WP_REST_Request $request ) {
		return $this->extensions_controller->trigger_transcription( $request );
	}

	/* --------------------------------------------------------------
	 * PEER REVIEW
	 * -------------------------------------------------------------- */

	public function get_peer_assignments( WP_REST_Request $request ) {
		return $this->extensions_controller->get_peer_assignments( $request );
	}

	public function assign_peer_reviews( WP_REST_Request $request ) {
		return $this->extensions_controller->assign_peer_reviews( $request );
	}

	public function submit_peer_review( WP_REST_Request $request ) {
		return $this->extensions_controller->submit_peer_review( $request );
	}

	public function get_my_peer_assignments( WP_REST_Request $request ) {
		return $this->extensions_controller->get_my_peer_assignments( $request );
	}

	/* --------------------------------------------------------------
	 * WEBHOOKS
	 * -------------------------------------------------------------- */

	public function get_webhooks( WP_REST_Request $request ) {
		return $this->extensions_controller->get_webhooks( $request );
	}

	public function create_webhook( WP_REST_Request $request ) {
		return $this->extensions_controller->create_webhook( $request );
	}

	public function delete_webhook( WP_REST_Request $request ) {
		return $this->extensions_controller->delete_webhook( $request );
	}

	/* --------------------------------------------------------------
	 * ME (current user)
	 * -------------------------------------------------------------- */

	public function get_me( WP_REST_Request $request ) {
		return $this->extensions_controller->get_me( $request );
	}

	public function get_my_profile( WP_REST_Request $request ) {
		return $this->extensions_controller->get_my_profile( $request );
	}

	public function update_my_profile( WP_REST_Request $request ) {
		return $this->extensions_controller->update_my_profile( $request );
	}

	public function get_my_courses( WP_REST_Request $request ) {
		return $this->extensions_controller->get_my_courses( $request );
	}

	public function get_my_programs( WP_REST_Request $request ) {
		return $this->extensions_controller->get_my_programs( $request );
	}

	/* --------------------------------------------------------------
	 * SANITIZERS
	 * -------------------------------------------------------------- */

	public function sanitize_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (bool) absint( $value );
		}

		$value = strtolower( trim( (string) $value ) );

		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}

	public function sanitize_score( $value ) {
		$value = absint( $value );

		if ( $value < 0 ) {
			$value = 0;
		}

		if ( $value > 100 ) {
			$value = 100;
		}

		return $value;
	}

	public function sanitize_per_page( $value ) {
		$value = absint( $value );

		if ( $value < 1 ) {
			$value = 20;
		}

		if ( $value > 100 ) {
			$value = 100;
		}

		return $value;
	}

	/* --------------------------------------------------------------
	 * GRADING ENGINE + FEEDBACK LOOP (v4.22)
	 * -------------------------------------------------------------- */

	public function get_final_grade( WP_REST_Request $request ) {
		return $this->grading_controller->get_final_grade( $request );
	}

	public function get_grade_breakdown( WP_REST_Request $request ) {
		return $this->grading_controller->get_grade_breakdown( $request );
	}

	public function get_grading_scheme( WP_REST_Request $request ) {
		return $this->grading_controller->get_grading_scheme( $request );
	}

	public function update_grading_scheme( WP_REST_Request $request ) {
		return $this->grading_controller->update_grading_scheme( $request );
	}

	public function submit_grade_appeal( WP_REST_Request $request ) {
		return $this->grading_controller->submit_appeal( $request );
	}

	public function process_grade_appeal( WP_REST_Request $request ) {
		return $this->grading_controller->process_appeal( $request );
	}

	public function get_feedback_action_plan( WP_REST_Request $request ) {
		return $this->grading_controller->get_action_plan( $request );
	}

	public function generate_feedback_practice( WP_REST_Request $request ) {
		return $this->grading_controller->generate_practice( $request );
	}

	public function record_feedback_progress( WP_REST_Request $request ) {
		return $this->grading_controller->record_progress_event( $request );
	}

	public function get_feedback_trackings( WP_REST_Request $request ) {
		return $this->grading_controller->get_user_trackings( $request );
	}

	public function can_view_grade_resource( WP_REST_Request $request ) {
		return $this->grading_controller->can_view_grade_resource( $request );
	}

	public function can_manage_grading( WP_REST_Request $request ) {
		return $this->grading_controller->can_manage_grading( $request );
	}

	/* --------------------------------------------------------------
	 * GRADEBOOK REST
	 * -------------------------------------------------------------- */

	public function get_gradebook_grid( WP_REST_Request $request ) {
		return $this->gradebook_controller->get_gradebook_grid( $request );
	}

	public function get_gradebook_schema( WP_REST_Request $request ) {
		return $this->gradebook_controller->get_gradebook_schema( $request );
	}

	public function get_gradebook_summary( WP_REST_Request $request ) {
		return $this->gradebook_controller->get_gradebook_summary( $request );
	}

	public function can_view_gradebook( WP_REST_Request $request ) {
		return $this->gradebook_controller->can_view_gradebook( $request );
	}

	public function save_gradebook_scheme( WP_REST_Request $request ) {
		return $this->gradebook_controller->save_gradebook_scheme( $request );
	}

	public function gradebook_batch_update( WP_REST_Request $request ) {
		return $this->gradebook_controller->batch_update( $request );
	}

	public function gradebook_export_csv( WP_REST_Request $request ) {
		return $this->gradebook_controller->export_csv( $request );
	}

	public function get_cell_detail( WP_REST_Request $request ) {
		return $this->gradebook_controller->get_cell_detail( $request );
	}

	public function can_manage_gradebook( WP_REST_Request $request ) {
		return $this->gradebook_controller->can_manage_gradebook( $request );
	}

	/* --------------------------------------------------------------
	 * SANITIZERS
	 * -------------------------------------------------------------- */

	public function sanitize_quiz_questions( $questions ) {
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
}
