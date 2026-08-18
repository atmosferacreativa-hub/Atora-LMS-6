<?php
/**
 * REST de pipelines CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Rest;

use ATORA\CRM_V2\Services\Deal_Service;
use ATORA\CRM_V2\Services\Student_Followup_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pipeline_REST_Controller {
	/**
	 * Registra rutas de pipeline.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$ns = CRM_REST_Controller::REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/pipeline/sales',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_sales_board' ),
				'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_access' ),
			)
		);

		register_rest_route(
			$ns,
			'/pipeline/sales/move',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'move_sales_card' ),
				'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_manage' ),
			)
		);

		register_rest_route(
			$ns,
			'/pipeline/academic',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_academic_board' ),
				'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_access' ),
			)
		);

		register_rest_route(
			$ns,
			'/pipeline/academic/move',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'move_academic_card' ),
				'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_manage' ),
			)
		);
	}

	/**
	 * Tablero ventas.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_sales_board(): \WP_REST_Response {
		$board = Deal_Service::get_board();
		$items = (array) ( $board['items'] ?? array() );
		foreach ( $items as $stage => $rows ) {
			$items[ $stage ] = CRM_REST_Controller::filter_rows_by_scope( (array) $rows, 'user_id', 'contact_id' );
		}
		$board['items'] = $items;

		return rest_ensure_response(
			array(
				'success' => true,
				'board'   => $board,
			)
		);
	}

	/**
	 * Mueve card comercial.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function move_sales_card( \WP_REST_Request $request ): \WP_REST_Response {
		$deal_id      = absint( $request->get_param( 'deal_id' ) );
		if ( ! CRM_REST_Controller::deal_id_is_visible( $deal_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'No tienes permisos para esta oportunidad.', 'atora-lms' ),
				),
				403
			);
		}

		$to_stage     = sanitize_key( (string) $request->get_param( 'to_stage' ) );
		$lost_reason  = sanitize_textarea_field( (string) $request->get_param( 'lost_reason' ) );
		$moved        = Deal_Service::move_deal( $deal_id, $to_stage, $lost_reason );

		return rest_ensure_response(
			array(
				'success' => $moved,
				'message' => $moved
					? __( 'Pipeline comercial actualizado.', 'atora-lms' )
					: __( 'No se pudo mover la oportunidad.', 'atora-lms' ),
			)
		);
	}

	/**
	 * Tablero académico.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_academic_board(): \WP_REST_Response {
		$board = Student_Followup_Service::get_board();
		$items = (array) ( $board['items'] ?? array() );
		foreach ( $items as $stage => $rows ) {
			$items[ $stage ] = CRM_REST_Controller::filter_rows_by_scope( (array) $rows, 'user_id', 'contact_id' );
		}
		$board['items'] = $items;

		return rest_ensure_response(
			array(
				'success' => true,
				'board'   => $board,
			)
		);
	}

	/**
	 * Mueve card académica.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function move_academic_card( \WP_REST_Request $request ): \WP_REST_Response {
		$followup_id = absint( $request->get_param( 'followup_id' ) );
		if ( ! CRM_REST_Controller::followup_id_is_visible( $followup_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'No tienes permisos para este seguimiento.', 'atora-lms' ),
				),
				403
			);
		}

		$to_stage    = sanitize_key( (string) $request->get_param( 'to_stage' ) );
		$moved       = Student_Followup_Service::move_followup( $followup_id, $to_stage );

		return rest_ensure_response(
			array(
				'success' => $moved,
				'message' => $moved
					? __( 'Seguimiento académico actualizado.', 'atora-lms' )
					: __( 'No se pudo actualizar el estado académico.', 'atora-lms' ),
			)
		);
	}
}
