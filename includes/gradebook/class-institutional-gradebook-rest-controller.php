<?php
/**
 * API REST aislada para el Gradebook institucional.
 *
 * @package ATORA_LMS
 * @since 6.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Institutional_Gradebook_REST_Controller {

	/** @var CLMS_Institutional_Gradebook_Service */
	protected $service;

	public function __construct() {
		$this->service = new CLMS_Institutional_Gradebook_Service();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$namespace = 'clms/v1';

		register_rest_route(
			$namespace,
			'/gradebook/institutional',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_context' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			$namespace,
			'/gradebook/institutional/periods',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_period' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			$namespace,
			'/gradebook/institutional/periods/(?P<id>\d+)/transition',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'transition_period' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			$namespace,
			'/gradebook/institutional/scales',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_scale' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			$namespace,
			'/gradebook/institutional/cycles',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_cycle' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			$namespace,
			'/gradebook/institutional/cycles/(?P<id>\d+)/grades',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_grade' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			$namespace,
			'/gradebook/institutional/cycles/(?P<id>\d+)/transition',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'transition_cycle' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			$namespace,
			'/gradebook/institutional/rectifications',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'request_rectification' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			$namespace,
			'/gradebook/institutional/rectifications/(?P<id>\d+)/decision',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'decide_rectification' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	public function can_manage() {
		return current_user_can( 'manage_options' )
			? true
			: new WP_Error( 'rest_forbidden', __( 'Solo la autoridad académica puede administrar el gradebook institucional.', 'atora-lms' ), array( 'status' => 403 ) );
	}

	public function get_context( WP_REST_Request $request ) {
		$institution_id = absint( $request->get_param( 'institution_id' ) );
		if ( ! $institution_id ) {
			$legacy = absint( $request->get_param( 'academy_id' ) );
			if ( $legacy && function_exists( '_doing_it_wrong' ) ) {
				_doing_it_wrong( __METHOD__, 'El parámetro academy_id está obsoleto; usa institution_id.', '6.26.4' );
			}
			$institution_id = $legacy;
		}

		return new WP_REST_Response(
			$this->service->get_context(
				$institution_id,
				absint( $request->get_param( 'course_id' ) ),
				absint( $request->get_param( 'cycle_id' ) )
			),
			200
		);
	}

	public function create_period( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();
		if ( empty( $data['institution_id'] ) && isset( $data['academy_id'] ) ) {
			if ( function_exists( '_doing_it_wrong' ) ) {
				_doing_it_wrong( __METHOD__, 'academy_id está obsoleto; usa institution_id.', '6.26.4' );
			}
			$data['institution_id'] = absint( $data['academy_id'] );
		}

		return $this->created( $this->service->create_period( $data, get_current_user_id() ) );
	}

	public function transition_period( WP_REST_Request $request ) {
		return $this->result( $this->service->transition_period( absint( $request['id'] ), $request->get_param( 'status' ), get_current_user_id() ) );
	}

	public function create_scale( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		$data = is_array( $data ) ? $data : array();
		if ( empty( $data['institution_id'] ) && isset( $data['academy_id'] ) ) {
			if ( function_exists( '_doing_it_wrong' ) ) {
				_doing_it_wrong( __METHOD__, 'academy_id está obsoleto; usa institution_id.', '6.26.4' );
			}
			$data['institution_id'] = absint( $data['academy_id'] );
		}

		return $this->created( $this->service->create_scale( $data, get_current_user_id() ) );
	}

	public function create_cycle( WP_REST_Request $request ) {
		return $this->created(
			$this->service->create_cycle(
				$request->get_param( 'period_id' ),
				$request->get_param( 'course_id' ),
				$request->get_param( 'scale_id' ),
				get_current_user_id()
			)
		);
	}

	public function save_grade( WP_REST_Request $request ) {
		return $this->result(
			$this->service->save_grade(
				absint( $request['id'] ),
				$request->get_param( 'student_id' ),
				$request->get_param( 'grade' ),
				$request->get_param( 'expected_revision' ),
				get_current_user_id(),
				(array) $request->get_param( 'source' )
			)
		);
	}

	public function transition_cycle( WP_REST_Request $request ) {
		return $this->result(
			$this->service->transition_cycle(
				absint( $request['id'] ),
				$request->get_param( 'status' ),
				$request->get_param( 'expected_lock_version' ),
				get_current_user_id()
			)
		);
	}

	public function request_rectification( WP_REST_Request $request ) {
		return $this->created(
			$this->service->request_rectification(
				$request->get_param( 'grade_id' ),
				$request->get_param( 'proposed_grade' ),
				$request->get_param( 'reason' ),
				get_current_user_id()
			)
		);
	}

	public function decide_rectification( WP_REST_Request $request ) {
		return $this->result(
			$this->service->decide_rectification(
				absint( $request['id'] ),
				$request->get_param( 'decision' ),
				get_current_user_id()
			)
		);
	}

	protected function created( $value ) {
		return is_wp_error( $value ) ? $value : new WP_REST_Response( array( 'id' => absint( $value ) ), 201 );
	}

	protected function result( $value ) {
		return is_wp_error( $value ) ? $value : new WP_REST_Response( array( 'result' => $value ), 200 );
	}
}
