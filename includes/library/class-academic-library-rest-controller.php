<?php
/**
 * API administrativa aislada de la Biblioteca académica.
 *
 * @package ATORA_LMS
 * @since 6.24.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Academic_Library_REST_Controller {

	const NAMESPACE = 'clms/v1';
	const BASE      = '/library';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route( self::NAMESPACE, self::BASE, array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'list_items' ), 'permission_callback' => array( $this, 'can_manage' ) ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'create_item' ), 'permission_callback' => array( $this, 'can_manage' ) ),
		) );
		register_rest_route( self::NAMESPACE, self::BASE . '/(?P<id>\d+)/versions', array(
			'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'add_version' ), 'permission_callback' => array( $this, 'can_manage' ),
		) );
		register_rest_route( self::NAMESPACE, self::BASE . '/(?P<id>\d+)/links', array(
			'methods' => WP_REST_Server::EDITABLE, 'callback' => array( $this, 'replace_links' ), 'permission_callback' => array( $this, 'can_manage' ),
		) );
		register_rest_route( self::NAMESPACE, self::BASE . '/(?P<id>\d+)/status', array(
			'methods' => WP_REST_Server::EDITABLE, 'callback' => array( $this, 'transition' ), 'permission_callback' => array( $this, 'can_manage' ),
		) );
	}

	public function can_manage() {
		$capability = (string) apply_filters( 'clms_library_manage_capability', 'manage_options' );
		return current_user_can( $capability );
	}

	public function list_items( WP_REST_Request $request ) {
		return rest_ensure_response( $this->service()->list_items( $request->get_param( 'status' ) ?: 'published', absint( $request->get_param( 'course_id' ) ) ) );
	}

	public function create_item( WP_REST_Request $request ) {
		return $this->respond( $this->service()->create_item( $request->get_json_params(), get_current_user_id() ) );
	}

	public function add_version( WP_REST_Request $request ) {
		return $this->respond( $this->service()->add_version( absint( $request['id'] ), $request->get_json_params(), get_current_user_id() ) );
	}

	public function replace_links( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		return $this->respond( $this->service()->replace_links( absint( $request['id'] ), $data['links'] ?? array(), get_current_user_id() ) );
	}

	public function transition( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		return $this->respond( $this->service()->transition( absint( $request['id'] ), $data['status'] ?? '', get_current_user_id() ) );
	}

	protected function service() {
		$service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Library_Service') : null;
		return $service ?: new CLMS_Academic_Library_Service();
	}

	protected function respond( $result ) {
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
