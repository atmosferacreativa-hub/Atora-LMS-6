<?php
/**
 * REST de reportes CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Rest;

use ATORA\CRM_V2\Services\Report_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reports_REST_Controller {
	/**
	 * Registra rutas de reportes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$ns = CRM_REST_Controller::REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/reports/overview',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_overview' ),
				'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_access' ),
			)
		);

		register_rest_route(
			$ns,
			'/reports/sales',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_sales' ),
				'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_access' ),
			)
		);

		register_rest_route(
			$ns,
			'/reports/academic',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_academic' ),
				'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_access' ),
			)
		);
	}

	/**
	 * Overview.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_overview(): \WP_REST_Response {
		return rest_ensure_response( array( 'success' => true, 'data' => Report_Service::get_overview() ) );
	}

	/**
	 * Ventas.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_sales(): \WP_REST_Response {
		return rest_ensure_response( array( 'success' => true, 'data' => Report_Service::get_sales_report() ) );
	}

	/**
	 * Académico.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_academic(): \WP_REST_Response {
		return rest_ensure_response( array( 'success' => true, 'data' => Report_Service::get_academic_report() ) );
	}
}
