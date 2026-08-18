<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_REST_Routes {

	protected $api;

	public function __construct( CLMS_REST_API $api ) {
		$this->api = $api;
	}

	public function register() {
		$routes = array(
			array(
				'route'  => '/public/courses',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_public_courses' ),
						'permission_callback' => array( $this->api, 'can_read_public' ),
						'args'                => array(
							'page'       => array(
								'default'           => 1,
								'sanitize_callback' => 'absint',
							),
							'per_page'   => array(
								'default'           => 20,
								'sanitize_callback' => array( $this->api, 'sanitize_per_page' ),
							),
							'search'     => array(
								'default'           => '',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'teacher_id' => array(
								'default'           => 0,
								'sanitize_callback' => 'absint',
							),
						),
					),
				),
			),
			array(
				'route'  => '/public/courses/(?P<id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_public_course' ),
						'permission_callback' => array( $this->api, 'can_read_public' ),
					),
				),
			),
			array(
				'route'  => '/public/programs',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_public_programs' ),
						'permission_callback' => array( $this->api, 'can_read_public' ),
						'args'                => array(
							'page'       => array(
								'default'           => 1,
								'sanitize_callback' => 'absint',
							),
							'per_page'   => array(
								'default'           => 20,
								'sanitize_callback' => array( $this->api, 'sanitize_per_page' ),
							),
							'search'     => array(
								'default'           => '',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'teacher_id' => array(
								'default'           => 0,
								'sanitize_callback' => 'absint',
							),
						),
					),
				),
			),
			array(
				'route'  => '/public/programs/(?P<id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_public_program' ),
						'permission_callback' => array( $this->api, 'can_read_public' ),
					),
				),
			),
			array(
				'route'  => '/public/catalog',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_public_catalog' ),
						'permission_callback' => array( $this->api, 'can_read_public' ),
						'args'                => array(
							'page'       => array(
								'default'           => 1,
								'sanitize_callback' => 'absint',
							),
							'per_page'   => array(
								'default'           => 12,
								'sanitize_callback' => array( $this->api, 'sanitize_per_page' ),
							),
							'search'     => array(
								'default'           => '',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'type'       => array(
								'default'           => 'all',
								'sanitize_callback' => 'sanitize_key',
							),
							'level'      => array(
								'default'           => '',
								'sanitize_callback' => 'sanitize_key',
							),
							'teacher_id' => array(
								'default'           => 0,
								'sanitize_callback' => 'absint',
							),
						),
					),
				),
			),
			array(
				'route'  => '/public/catalog/recommendations',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_public_catalog_recommendations' ),
						'permission_callback' => array( $this->api, 'can_read_public' ),
						'args'                => array(
							'limit'        => array(
								'default'           => 6,
								'sanitize_callback' => 'absint',
							),
							'type'         => array(
								'default'           => 'all',
								'sanitize_callback' => 'sanitize_key',
							),
							'context_type' => array(
								'default'           => '',
								'sanitize_callback' => 'sanitize_key',
							),
							'context_id'   => array(
								'default'           => 0,
								'sanitize_callback' => 'absint',
							),
						),
					),
				),
			),
			array(
				'route'  => '/courses',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_courses' ),
						'permission_callback' => array( $this->api, 'can_read_course' ),
						'args'                => array(
							'page'       => array(
								'default'           => 1,
								'sanitize_callback' => 'absint',
							),
							'per_page'   => array(
								'default'           => 20,
								'sanitize_callback' => array( $this->api, 'sanitize_per_page' ),
							),
							'search'     => array(
								'default'           => '',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'status'     => array(
								'default'           => 'publish',
								'sanitize_callback' => 'sanitize_key',
							),
							'teacher_id' => array(
								'default'           => 0,
								'sanitize_callback' => 'absint',
							),
						),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'create_course' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/courses/(?P<id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_course' ),
						'permission_callback' => array( $this->api, 'can_read_course' ),
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( $this->api, 'update_course' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
					array(
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => array( $this->api, 'delete_course' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/courses/(?P<id>\d+)/enroll',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'enroll_in_course' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
				),
			),
			array(
				'route'  => '/programs',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_programs' ),
						'permission_callback' => array( $this->api, 'can_read_program' ),
						'args'                => array(
							'page'       => array(
								'default'           => 1,
								'sanitize_callback' => 'absint',
							),
							'per_page'   => array(
								'default'           => 20,
								'sanitize_callback' => array( $this->api, 'sanitize_per_page' ),
							),
							'search'     => array(
								'default'           => '',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'status'     => array(
								'default'           => 'publish',
								'sanitize_callback' => 'sanitize_key',
							),
							'teacher_id' => array(
								'default'           => 0,
								'sanitize_callback' => 'absint',
							),
						),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'create_program' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/programs/(?P<id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_program' ),
						'permission_callback' => array( $this->api, 'can_read_program' ),
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( $this->api, 'update_program' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
					array(
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => array( $this->api, 'delete_program' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/programs/(?P<id>\d+)/enroll',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'enroll_in_program' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
				),
			),
			array(
				'route'  => '/lessons',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_lessons' ),
						'permission_callback' => array( $this->api, 'can_read_lesson' ),
						'args'                => array(
							'course_id'    => array(
								'default'           => 0,
								'sanitize_callback' => 'absint',
							),
							'teacher_id'   => array(
								'default'           => 0,
								'sanitize_callback' => 'absint',
							),
							'status'       => array(
								'default'           => 'publish',
								'sanitize_callback' => 'sanitize_key',
							),
							'page'         => array(
								'default'           => 1,
								'sanitize_callback' => 'absint',
							),
							'per_page'     => array(
								'default'           => 50,
								'sanitize_callback' => array( $this->api, 'sanitize_per_page' ),
							),
							'search'       => array(
								'default'           => '',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'available_to' => array(
								'default'           => 0,
								'sanitize_callback' => 'absint',
							),
						),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'create_lesson' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/lessons/(?P<id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_lesson' ),
						'permission_callback' => array( $this->api, 'can_read_lesson' ),
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( $this->api, 'update_lesson' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
					array(
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => array( $this->api, 'delete_lesson' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/lessons/(?P<id>\d+)/quick-edit',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( $this->api, 'quick_edit_lesson' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/lesson/(?P<id>\d+)/complete',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'complete_lesson' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
				),
			),
			array(
				'route'  => '/progress/(?P<user_id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_progress' ),
						'permission_callback' => array( $this->api, 'can_view_user_resource' ),
					),
				),
			),
			array(
				'route'  => '/grades/(?P<user_id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_grades' ),
						'permission_callback' => array( $this->api, 'can_view_user_resource' ),
					),
				),
			),
			array(
				'route'  => '/submissions/(?P<user_id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_submissions' ),
						'permission_callback' => array( $this->api, 'can_view_user_resource' ),
					),
				),
			),
			array(
				'route'  => '/submissions/(?P<user_id>\d+)/(?P<lesson_id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_submissions_by_lesson' ),
						'permission_callback' => array( $this->api, 'can_view_user_resource' ),
					),
				),
			),
			array(
				'route'  => '/institution/students/(?P<user_id>\d+)/profile',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_student_profile_report' ),
						'permission_callback' => array( $this->api, 'can_view_user_resource' ),
					),
				),
			),
			array(
				'route'  => '/institution/teachers/(?P<user_id>\d+)/profile',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_teacher_profile_report' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
				),
			),
			array(
				'route'  => '/institution/courses/(?P<course_id>\d+)/report',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_course_advanced_report' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
				),
			),
			array(
				'route'  => '/institution/reports/admin',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_admin_advanced_report' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
				),
			),
			array(
				'route'  => '/institution/reports/certification',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_certification_report' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
						'args'                => array(
							'course_id' => array(
								'required'          => false,
								'default'           => 0,
								'sanitize_callback' => 'absint',
							),
						),
					),
				),
			),
			array(
				'route'  => '/institution/risk/(?P<user_id>\d+)/(?P<course_id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_risk_indicators' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
				),
			),
			array(
				'route'  => '/students/bulk-action',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'bulk_students_action' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/lesson-presets',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_lesson_presets' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'create_lesson_preset' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/lesson-presets/(?P<id>[a-zA-Z0-9_-]+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => array( $this->api, 'delete_lesson_preset' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/lessons/apply-preset',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'apply_lesson_preset' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/quizzes/(?P<lesson_id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_quiz' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( $this->api, 'update_quiz' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/quizzes/(?P<lesson_id>\d+)/submit',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'submit_quiz' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
				),
			),

			// ── Rubrics ────────────────────────────────────────────────────────
			array(
				'route'  => '/rubrics',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_rubrics' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
						'args'                => array(
							'page'     => array( 'default' => 1,  'sanitize_callback' => 'absint' ),
							'per_page' => array( 'default' => 50, 'sanitize_callback' => array( $this->api, 'sanitize_per_page' ) ),
						),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'create_rubric' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/rubrics/(?P<id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_rubric' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( $this->api, 'update_rubric' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
					array(
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => array( $this->api, 'delete_rubric' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/lessons/(?P<id>\d+)/rubric',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_lesson_rubric' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( $this->api, 'set_lesson_rubric' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),

			// ── Transcriptions ─────────────────────────────────────────────────
			array(
				'route'  => '/lessons/(?P<id>\d+)/transcription',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_transcription' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'trigger_transcription' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),

			// ── Peer Review ────────────────────────────────────────────────────
			array(
				'route'  => '/peer-reviews/(?P<lesson_id>\d+)/assignments',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_peer_assignments' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'assign_peer_reviews' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/peer-reviews/(?P<assignment_id>\d+)/submit',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'submit_peer_review' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
				),
			),
			array(
				'route'  => '/peer-reviews/my-assignments',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_my_peer_assignments' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
				),
			),

			// ── Webhooks ───────────────────────────────────────────────────────
			array(
				'route'  => '/webhooks',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_webhooks' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'create_webhook' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/webhooks/(?P<id>[A-Za-z0-9._-]+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::DELETABLE,
						'callback'            => array( $this->api, 'delete_webhook' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),

			// ── Me (current user) ──────────────────────────────────────────────
			array(
				'route'  => '/me',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_me' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
				),
			),
			array(
				'route'  => '/me/profile',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_my_profile' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( $this->api, 'update_my_profile' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
				),
			),
			array(
				'route'  => '/me/courses',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_my_courses' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
				),
			),
			array(
				'route'  => '/me/programs',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_my_programs' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
				),
			),

			// ── OpenAPI Docs ───────────────────────────────────────────────────
			array(
				'route'  => '/openapi',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_openapi_spec' ),
						'permission_callback' => array( $this->api, 'can_read_public' ),
					),
				),
			),
			array(
				'route'  => '/public/openapi',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_public_openapi_spec' ),
						'permission_callback' => array( $this->api, 'can_read_public' ),
					),
				),
			),

			// ── Performance ────────────────────────────────────────────────────
			array(
				'route'  => '/performance/status',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_performance_status' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),

			// ── Grading Engine (v4.22) ────────────────────────────────────────
			array(
				'route'  => '/grades/engine/(?P<user_id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_final_grade' ),
						'permission_callback' => array( $this->api, 'can_view_grade_resource' ),
						'args'                => array(
							'course_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
						),
					),
				),
			),
			array(
				'route'  => '/grades/breakdown/(?P<user_id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_grade_breakdown' ),
						'permission_callback' => array( $this->api, 'can_view_grade_resource' ),
						'args'                => array(
							'course_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
						),
					),
				),
			),
			array(
				'route'  => '/grades/scheme/(?P<course_id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_grading_scheme' ),
						'permission_callback' => array( $this->api, 'can_manage_grading' ),
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( $this->api, 'update_grading_scheme' ),
						'permission_callback' => array( $this->api, 'can_manage_grading' ),
					),
				),
			),
			array(
				'route'  => '/grades/appeal',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'submit_grade_appeal' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
						'args'                => array(
							'submission_id' => array( 'required' => true,  'sanitize_callback' => 'absint' ),
							'component'     => array( 'required' => true,  'sanitize_callback' => 'sanitize_key' ),
							'reason'        => array( 'required' => true,  'sanitize_callback' => 'sanitize_textarea_field' ),
						),
					),
				),
			),
			array(
				'route'  => '/grades/appeal/(?P<appeal_id>[a-zA-Z0-9_-]+)/process',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( $this->api, 'process_grade_appeal' ),
						'permission_callback' => array( $this->api, 'can_manage_grading' ),
						'args'                => array(
							'decision'  => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
							'new_grade' => array( 'required' => false ),
							'notes'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
						),
					),
				),
			),

			// ── Gradebook (Sprint 6) ───────────────────────────────────────────
			array(
				'route'  => '/gradebook/(?P<course_id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_gradebook_grid' ),
						'permission_callback' => array( $this->api, 'can_view_gradebook' ),
						'args'                => array(
							'student_search'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
							'activity_search' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
							'status'          => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
							'group'           => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
							'cohort_id'       => array( 'required' => false, 'sanitize_callback' => 'absint' ),
						),
					),
				),
			),
			array(
				'route'  => '/gradebook/schema/(?P<course_id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_gradebook_schema' ),
						'permission_callback' => array( $this->api, 'can_view_gradebook' ),
					),
				),
			),
			array(
				'route'  => '/gradebook/summary/(?P<course_id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_gradebook_summary' ),
						'permission_callback' => array( $this->api, 'can_view_gradebook' ),
					),
				),
			),
			array(
				'route'  => '/gradebook/scheme/(?P<course_id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'save_gradebook_scheme' ),
						'permission_callback' => array( $this->api, 'can_manage_gradebook' ),
						'args'                => array(
							'groups' => array( 'required' => true, 'type' => 'array' ),
						),
					),
				),
			),
			array(
				'route'  => '/gradebook/export/(?P<course_id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'gradebook_export_csv' ),
						'permission_callback' => array( $this->api, 'can_view_gradebook' ),
						'args'                => array(
							'student_search'  => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
							'activity_search' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
							'status'          => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
							'cohort_id'       => array( 'required' => false, 'sanitize_callback' => 'absint' ),
						),
					),
				),
			),
			array(
				'route'  => '/gradebook/batch-update',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'gradebook_batch_update' ),
						'permission_callback' => array( $this->api, 'can_manage_gradebook' ),
						'args'                => array(
							'course_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
							'updates'   => array( 'required' => true, 'type' => 'array' ),
						),
					),
				),
			),
			array(
				'route'  => '/gradebook/cell-detail',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_cell_detail' ),
						'permission_callback' => array( $this->api, 'can_view_gradebook' ),
						'args'                => array(
							'submission_id' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
							'lesson_id'     => array( 'required' => false, 'sanitize_callback' => 'absint' ),
							'student_id'    => array( 'required' => false, 'sanitize_callback' => 'absint' ),
							'course_id'     => array( 'required' => false, 'sanitize_callback' => 'absint' ),
						),
					),
				),
			),

			// ── Feedback Loop (v4.22) ──────────────────────────────────────────
			array(
				'route'  => '/feedback/action-plan/(?P<tracking_id>[a-zA-Z0-9_-]+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_feedback_action_plan' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
					),
				),
			),
			array(
				'route'  => '/feedback/practice/generate',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'generate_feedback_practice' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
						'args'                => array(
							'gap_area'      => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
							'gap_label'     => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
							'current_level' => array( 'required' => false, 'default' => 50 ),
							'difficulty'    => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
						),
					),
				),
			),
			array(
				'route'  => '/feedback/progress/(?P<tracking_id>[a-zA-Z0-9_-]+)/event',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'record_feedback_progress' ),
						'permission_callback' => array( $this->api, 'can_access_logged_in' ),
						'args'                => array(
							'event_type' => array( 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
							'event_data' => array( 'required' => false ),
						),
					),
				),
			),
			array(
				'route'  => '/feedback/trackings/(?P<user_id>\d+)',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this->api, 'get_feedback_trackings' ),
						'permission_callback' => array( $this->api, 'can_view_grade_resource' ),
						'args'                => array(
							'course_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
						),
					),
				),
			),

			// ── Curriculum Reorder (Batch Operations) ──────────────────────────
			array(
				'route'  => '/lessons/reorder',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'reorder_lessons' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
			array(
				'route'  => '/programs/reorder',
				'routes' => array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this->api, 'reorder_programs' ),
						'permission_callback' => array( $this->api, 'can_manage_content' ),
					),
				),
			),
		);

		foreach ( $routes as $route_group ) {
			register_rest_route( CLMS_REST_API::API_NAMESPACE, $route_group['route'], $route_group['routes'] );
		}
	}
}
