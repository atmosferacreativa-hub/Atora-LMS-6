<?php
/**
 * REST controller de dashboards de reportes.
 *
 * @package ATORA_LMS\CRM_V2\Rest
 */

namespace ATORA\CRM_V2\Rest;

use ATORA\CRM_V2\Services\CRM_Email_Service;
use ATORA\CRM_V2\Services\Report_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reports_Dashboard_REST_Controller {
	/**
	 * Namespace REST.
	 */
	const REST_NAMESPACE = 'atora-crm/v2';

	/**
	 * Registra rutas.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/reports/dashboard',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_dashboard' ),
				'permission_callback' => array( CRM_REST_Controller::class, 'can_access' ),
			)
		);
	}

	/**
	 * Entrega datasets del dashboard.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_dashboard(): \WP_REST_Response {
		CRM_Email_Service::sync_tracking_to_recipients();

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => Report_Service::get_dashboard_data(),
			)
		);
	}
}
