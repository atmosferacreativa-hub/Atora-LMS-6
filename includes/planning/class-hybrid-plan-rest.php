<?php
/**
 * REST de la planificación híbrida (6.37.0): `atora/v1/hybrid-plans`.
 * Cada ruta comprueba el alcance con ATORA_Hybrid_Plan_Service (no basta con iniciar sesión).
 *
 * @package ATORA_LMS
 * @since 6.37.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Hybrid_Plan_REST {

	const NS = 'atora/v1';

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$auth = static fn() => is_user_logged_in() && current_user_can( 'edit_lm_lessons' );
		$id   = array( 'id' => array( 'sanitize_callback' => 'absint' ) );

		register_rest_route( self::NS, '/hybrid-plans', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'index' ), 'permission_callback' => $auth ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'create' ), 'permission_callback' => $auth ),
		) );
		register_rest_route( self::NS, '/hybrid-plans/courses', array(
			'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'courses' ), 'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/hybrid-plans/(?P<id>\d+)', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'show' ), 'permission_callback' => $auth, 'args' => $id ),
			array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'update' ), 'permission_callback' => $auth, 'args' => $id ),
		) );
		foreach ( array( 'duplicate', 'preview', 'publish', 'suggest' ) as $action ) {
			register_rest_route( self::NS, '/hybrid-plans/(?P<id>\d+)/' . $action, array(
				'methods'             => 'preview' === $action ? WP_REST_Server::READABLE : WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, $action ),
				'permission_callback' => $auth,
				'args'                => $id,
			) );
		}
	}

	public static function courses(): WP_REST_Response {
		$user_id = get_current_user_id();
		$items   = array();
		foreach ( ATORA_Teacher_Scope::course_ids( $user_id ) as $course_id ) {
			if ( ATORA_Hybrid_Plan_Service::can_edit( $user_id, (int) $course_id ) ) {
				$items[] = array(
					'id'          => (int) $course_id,
					'title'       => get_the_title( (int) $course_id ),
					'can_publish' => ATORA_Hybrid_Plan_Service::can_publish( $user_id, (int) $course_id ),
				);
			}
		}
		return self::ok( array( 'courses' => $items, 'ai_available' => ATORA_Hybrid_Plan_Service::ai_available() ) );
	}

	public static function index( WP_REST_Request $request ): WP_REST_Response {
		return self::ok( array( 'plans' => ATORA_Hybrid_Plan_Service::list_for_course( get_current_user_id(), absint( $request->get_param( 'course_id' ) ) ) ) );
	}

	public static function create( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return self::respond( ATORA_Hybrid_Plan_Service::create( get_current_user_id(), absint( $params['course_id'] ?? 0 ), $params['data'] ?? array() ), 201 );
	}

	public static function show( WP_REST_Request $request ) {
		return self::respond( ATORA_Hybrid_Plan_Service::get_for_user( get_current_user_id(), absint( $request['id'] ) ) );
	}

	public static function update( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return self::respond( ATORA_Hybrid_Plan_Service::update( get_current_user_id(), absint( $request['id'] ), $params['data'] ?? array(), absint( $params['revision'] ?? 0 ) ) );
	}

	public static function duplicate( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return self::respond( ATORA_Hybrid_Plan_Service::duplicate( get_current_user_id(), absint( $request['id'] ), absint( $params['course_id'] ?? 0 ) ), 201 );
	}

	public static function preview( WP_REST_Request $request ) {
		return self::respond( ATORA_Hybrid_Plan_Service::preview( get_current_user_id(), absint( $request['id'] ) ) );
	}

	public static function publish( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return self::respond( ATORA_Hybrid_Plan_Service::publish( get_current_user_id(), absint( $request['id'] ), absint( $params['revision'] ?? 0 ) ) );
	}

	public static function suggest( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		return self::respond( ATORA_Hybrid_Plan_Service::suggest( get_current_user_id(), absint( $request['id'] ), sanitize_key( (string) ( $params['field'] ?? '' ) ) ) );
	}

	/** @param array|WP_Error $result */
	private static function respond( $result, int $status = 200 ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::ok( $result, $status );
	}

	private static function ok( array $data, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
