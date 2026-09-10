<?php
/**
 * REST Controller — H5P Tracking ingest.
 *
 * Route:
 * - POST /atora/v1/h5p/track
 *
 * @package ATORA_LMS
 * @since   6.19.0
 */

namespace ATORA\H5P\REST;

use ATORA\H5P\H5P_Tracking_Service;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class H5P_Tracking_Controller extends WP_REST_Controller {

	private H5P_Tracking_Service $tracking;

	public function __construct( H5P_Tracking_Service $tracking ) {
		$this->namespace = 'atora/v1';
		$this->rest_base = 'h5p/track';
		$this->tracking  = $tracking;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'can_track' ),
				),
			)
		);
	}

	public function can_track( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Debes iniciar sesión.', 'atora-lms' ), array( 'status' => 401 ) );
		}

		$lesson_id  = absint( $request->get_param( 'lesson_id' ) );
		$content_id = absint( $request->get_param( 'content_id' ) );
		if ( ! $lesson_id || ! $content_id ) {
			return new WP_Error( 'atora_h5p_invalid_params', __( 'Parámetros inválidos.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'atora_h5p_invalid_lesson', __( 'Lección inválida.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$user_id = get_current_user_id();
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'user_can_access_lesson' ) ) {
			if ( ! \CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
				return new WP_Error( 'rest_forbidden', __( 'No tienes acceso a esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
			}
		}

		return true;
	}

	public function create_item( WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$lesson_id  = absint( $request->get_param( 'lesson_id' ) );
		$content_id = absint( $request->get_param( 'content_id' ) );

		$params    = $request->get_json_params();
		$params    = is_array( $params ) ? $params : array();
		$statement = isset( $params['statement'] ) && is_array( $params['statement'] ) ? (array) $params['statement'] : array();
		if ( empty( $statement ) ) {
			return new WP_Error( 'atora_h5p_invalid_statement', __( 'Statement inválido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$course_id = class_exists( 'CLMS_Helper' ) ? absint( \CLMS_Helper::get_lesson_course_id( $lesson_id ) ) : 0;
		$derived   = $this->tracking->derive_from_statement( $statement );
		$store     = $this->tracking->upsert_tracking( $user_id, $lesson_id, $course_id, $content_id, $statement, $derived );
		if ( is_wp_error( $store ) ) {
			return $store;
		}

		$autoscore = '1' === (string) get_post_meta( $lesson_id, '_clms_h5p_autoscore', true );
		if ( $autoscore && null !== ( $derived['score_percent'] ?? null ) ) {
			$this->tracking->maybe_sync_autoscore_to_quiz_channel( $user_id, $lesson_id, (int) $derived['score_percent'], $statement );
		}

		return new WP_REST_Response( array( 'id' => (int) ( $store['row_id'] ?? 0 ), 'derived' => $derived ), 201 );
	}
}
