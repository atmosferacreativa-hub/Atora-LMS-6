<?php
/**
 * REST Controller — H5P Contents (CRUD).
 *
 * Routes:
 * - GET/POST        /atora/v1/h5p/contents
 * - GET/PATCH/DELETE /atora/v1/h5p/contents/{id}
 *
 * @package ATORA_LMS
 * @since   6.19.0
 */

namespace ATORA\H5P\REST;

use ATORA\H5P\H5P_Content_Manager;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class H5P_Content_Controller extends WP_REST_Controller {

	private H5P_Content_Manager $content;

	public function __construct( H5P_Content_Manager $content ) {
		$this->namespace = 'atora/v1';
		$this->rest_base = 'h5p/contents';
		$this->content   = $content;
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
						'search'   => array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
						'per_page' => array( 'sanitize_callback' => 'absint', 'default' => 20 ),
						'page'     => array( 'sanitize_callback' => 'absint', 'default' => 1 ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'can_manage_h5p' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'can_manage_h5p' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'can_manage_h5p' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
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

	/** @param WP_REST_Request $request */
	public function list_items( $request ) {
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$per_page = max( 1, min( 50, absint( $request->get_param( 'per_page' ) ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$ids = get_posts(
			array(
				'post_type'      => 'h5p_content',
				'post_status'    => 'any',
				'numberposts'    => $per_page,
				'offset'         => $offset,
				'orderby'        => 'date',
				'order'          => 'DESC',
				's'              => $search,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$ids = is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();

		$items = array();
		foreach ( $ids as $wp_post_id ) {
			$row = $this->content->get_by_wp_post_id( $wp_post_id );
			$items[] = array(
				'id'         => $wp_post_id,
				'title'      => get_the_title( $wp_post_id ),
				'status'     => get_post_status( $wp_post_id ),
				'provider'   => $row['provider'] ?? 'wp_h5p',
				'visibility' => $row['visibility'] ?? 'private',
				'updated_at' => $row['updated_at'] ?? '',
			);
		}

		return new WP_REST_Response( array( 'items' => $items ), 200 );
	}

	/** @param WP_REST_Request $request */
	public function create_item( $request ) {
		$payload = (array) $request->get_json_params();
		if ( empty( $payload ) ) {
			$payload = $request->get_params();
		}

		$id = $this->content->create( is_array( $payload ) ? $payload : array() );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return new WP_REST_Response( array( 'id' => (int) $id ), 201 );
	}

	/** @param WP_REST_Request $request */
	public function get_item( $request ) {
		$id = absint( $request['id'] );
		if ( ! $id || 'h5p_content' !== get_post_type( $id ) ) {
			return new WP_Error( 'atora_h5p_not_found', __( 'Contenido no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$row = $this->content->get_by_wp_post_id( $id );
		return new WP_REST_Response(
			array(
				'id'          => $id,
				'title'       => get_the_title( $id ),
				'status'      => get_post_status( $id ),
				'provider'    => $row['provider'] ?? 'wp_h5p',
				'external_id' => $row['external_id'] ?? '',
				'visibility'  => $row['visibility'] ?? 'private',
				'license'     => $row['license'] ?? '',
				'updated_at'  => $row['updated_at'] ?? '',
			),
			200
		);
	}

	/** @param WP_REST_Request $request */
	public function update_item( $request ) {
		$id = absint( $request['id'] );
		if ( ! $id || 'h5p_content' !== get_post_type( $id ) ) {
			return new WP_Error( 'atora_h5p_not_found', __( 'Contenido no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$payload = (array) $request->get_json_params();
		if ( empty( $payload ) ) {
			$payload = $request->get_params();
		}

		if ( isset( $payload['title'] ) ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => sanitize_text_field( (string) $payload['title'] ),
				)
			);
		}

		$ok = $this->content->ensure_row( $id, is_array( $payload ) ? $payload : array() );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/** @param WP_REST_Request $request */
	public function delete_item( $request ) {
		$id = absint( $request['id'] );
		if ( ! $id || 'h5p_content' !== get_post_type( $id ) ) {
			return new WP_Error( 'atora_h5p_not_found', __( 'Contenido no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$ok = (bool) wp_delete_post( $id, true );
		return new WP_REST_Response( array( 'ok' => $ok ), 200 );
	}
}
