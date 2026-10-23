<?php
/**
 * REST importación CSV — CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Rest;

use ATORA\CRM_V2\Services\CSV_Import_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Import_REST_Controller {
	const REST_NAMESPACE = 'atora-crm/v2';

	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/contacts/import/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'preview' ),
				'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/contacts/import/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'run_import' ),
				'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_manage' ),
			)
		);
	}

	public static function preview( \WP_REST_Request $request ): \WP_REST_Response {
		$csv = (string) $request->get_param( 'csv' );
		$max = absint( $request->get_param( 'max_rows' ) ?: 10 );
		return new \WP_REST_Response(
			CSV_Import_Service::preview_csv( $csv, $max ),
			200
		);
	}

	public static function run_import( \WP_REST_Request $request ): \WP_REST_Response {
		$csv            = (string) $request->get_param( 'csv' );
		$mapping        = (array) $request->get_param( 'mapping' );
		$update_existing = (bool) $request->get_param( 'update_existing' );

		$result = CSV_Import_Service::import_csv(
			$csv,
			array_map( 'strval', $mapping ),
			array(
				'update_existing' => $update_existing,
			),
		);

		return new \WP_REST_Response( $result, 200 );
	}
}

