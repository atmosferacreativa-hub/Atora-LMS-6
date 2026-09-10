<?php
/**
 * REST Controller — H5P Libraries.
 *
 * Routes:
 * - GET/POST /atora/v1/h5p/libraries
 *
 * @package ATORA_LMS
 * @since   6.19.0
 */

namespace ATORA\H5P\REST;

use ATORA\H5P\H5P_Library_Service;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class H5P_Library_Controller extends WP_REST_Controller {

	private H5P_Library_Service $libs;

	public function __construct( H5P_Library_Service $libs ) {
		$this->namespace = 'atora/v1';
		$this->rest_base = 'h5p/libraries';
		$this->libs      = $libs;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => array( $this, 'can_manage_h5p' ),
					'args'                => array(
						'limit'  => array( 'sanitize_callback' => 'absint', 'default' => 100 ),
						'offset' => array( 'sanitize_callback' => 'absint', 'default' => 0 ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upsert_item' ),
					'permission_callback' => array( $this, 'can_manage_h5p' ),
				),
			)
		);
	}

	public function can_manage_h5p(): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return class_exists( 'CLMS_Access' ) && ( \CLMS_Access::can_manage_lessons() || \CLMS_Access::can_manage_courses() );
	}

	public function list_items( WP_REST_Request $request ) {
		$limit  = absint( $request->get_param( 'limit' ) );
		$offset = absint( $request->get_param( 'offset' ) );
		$items  = $this->libs->list( $limit, $offset );
		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	public function upsert_item( WP_REST_Request $request ) {
		$payload = (array) $request->get_json_params();
		if ( empty( $payload ) ) {
			$payload = $request->get_params();
		}

		$ok = $this->libs->upsert( is_array( $payload ) ? $payload : array() );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
