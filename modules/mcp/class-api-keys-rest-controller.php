<?php
/**
 * REST Controller — API Keys para MCP (Fase 12C)
 *
 * GET    /atora-lms/v1/api-keys        → lista de keys del usuario actual
 * POST   /atora-lms/v1/api-keys        → crear nueva key (devuelve el token UNA VEZ)
 * DELETE /atora-lms/v1/api-keys/{id}   → revocar key
 *
 * @package ATORA_LMS
 * @since   5.29.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ATORA_API_Keys_REST_Controller {

	public static function register_routes(): void {
		$ns = 'atora-lms/v1';

		register_rest_route( $ns, '/api-keys', array(
			array( 'methods' => 'GET',  'callback' => array( __CLASS__, 'list_keys' ),  'permission_callback' => array( __CLASS__, 'can_manage' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_key' ), 'permission_callback' => array( __CLASS__, 'can_manage' ) ),
		) );
		register_rest_route( $ns, '/api-keys/(?P<key_id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'revoke_key' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'clms_manage_crm' );
	}

	public static function list_keys( WP_REST_Request $r ): WP_REST_Response {
		if ( ! class_exists( 'ATORA_API_Key_Service' ) ) {
			return new WP_REST_Response( array( 'keys' => array() ), 200 );
		}
		$keys = ATORA_API_Key_Service::list( get_current_user_id() );
		return new WP_REST_Response( array( 'success' => true, 'keys' => $keys ), 200 );
	}

	public static function create_key( WP_REST_Request $r ): WP_REST_Response {
		if ( ! class_exists( 'ATORA_API_Key_Service' ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Servicio no disponible.' ), 503 );
		}
		$b      = $r->get_json_params() ?: array();
		$name   = sanitize_text_field( (string) ( $b['name']   ?? 'API Key' ) );
		$scopes = sanitize_text_field( (string) ( $b['scopes'] ?? 'read' ) );
		$result = ATORA_API_Key_Service::create( get_current_user_id(), $name, $scopes );
		if ( ! $result ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'No se pudo crear la key.' ), 400 );
		}
		return new WP_REST_Response( array(
			'success' => true,
			'message' => 'API Key creada. Cópiala ahora — no se mostrará de nuevo.',
			'key'     => $result,
		), 201 );
	}

	public static function revoke_key( WP_REST_Request $r ): WP_REST_Response {
		if ( ! class_exists( 'ATORA_API_Key_Service' ) ) {
			return new WP_REST_Response( array( 'success' => false ), 503 );
		}
		$key_id = absint( $r->get_param( 'key_id' ) );
		$ok     = ATORA_API_Key_Service::revoke( $key_id, get_current_user_id() );
		return new WP_REST_Response( array( 'success' => $ok ), $ok ? 200 : 400 );
	}
}
