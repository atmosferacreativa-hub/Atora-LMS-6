<?php
/**
 * REST de calendario CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Rest;

use ATORA\CRM_V2\Services\Task_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Calendar_REST_Controller {
	/**
	 * Registra rutas de calendario.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$ns = CRM_REST_Controller::REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/calendar',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_calendar' ),
				'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_access' ),
			)
		);
	}

	/**
	 * Obtiene tareas por rango.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_calendar( \WP_REST_Request $request ): \WP_REST_Response {
		$date_from = sanitize_text_field( (string) $request->get_param( 'date_from' ) );
		$date_to   = sanitize_text_field( (string) $request->get_param( 'date_to' ) );
		$scope_user_ids = CRM_REST_Controller::has_global_scope() ? null : CRM_REST_Controller::get_scope_user_ids();

		$tasks = Task_Service::list_tasks(
			array(
				'date_from'      => $date_from,
				'date_to'        => $date_to,
				'scope_user_ids' => $scope_user_ids,
				'limit'          => absint( $request->get_param( 'limit' ) ?: 200 ),
			)
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'events'  => (array) ( $tasks['items'] ?? array() ),
			)
		);
	}
}
