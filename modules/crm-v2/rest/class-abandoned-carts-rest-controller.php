<?php
/**
 * REST Controller — Carritos abandonados (Fase 10)
 *
 * GET  /atora-crm/v2/abandoned-carts          → lista paginada
 * GET  /atora-crm/v2/abandoned-carts/summary  → resumen KPIs
 *
 * @package ATORA_LMS\CRM_V2\Rest
 * @since   5.29.0
 */

namespace ATORA\CRM_V2\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Abandoned_Carts_REST_Controller {

	const REST_NAMESPACE = 'atora-crm/v2';

	public static function register_routes(): void {
		$ns  = self::REST_NAMESPACE;
		$can = array( 'ATORA\CRM_V2\Rest\CRM_REST_Controller', 'can_access' );

		register_rest_route( $ns, '/abandoned-carts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_carts' ),
			'permission_callback' => $can,
		) );

		register_rest_route( $ns, '/abandoned-carts/summary', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'summary' ),
			'permission_callback' => $can,
		) );
	}

	public static function list_carts( \WP_REST_Request $r ): \WP_REST_Response {
		if ( ! class_exists( 'ATORA_Abandoned_Cart_Service' ) ) {
			return new \WP_REST_Response( array( 'items' => array(), 'total' => 0 ), 200 );
		}
		$limit  = absint( $r->get_param( 'limit' )  ?: 50 );
		$offset = absint( $r->get_param( 'offset' ) ?: 0 );
		$status = sanitize_key( (string) ( $r->get_param( 'status' ) ?: '' ) );
		$result = ATORA_Abandoned_Cart_Service::get_all( $limit, $offset, $status );
		return new \WP_REST_Response( array_merge( array( 'success' => true ), $result ), 200 );
	}

	public static function summary( \WP_REST_Request $r ): \WP_REST_Response {
		$summary = class_exists( 'ATORA_Abandoned_Cart_Service' )
			? ATORA_Abandoned_Cart_Service::get_summary()
			: array( 'total' => 0, 'active' => 0, 'recovered' => 0, 'recovered_value' => 0 );
		return new \WP_REST_Response( array( 'success' => true, 'summary' => $summary ), 200 );
	}
}
