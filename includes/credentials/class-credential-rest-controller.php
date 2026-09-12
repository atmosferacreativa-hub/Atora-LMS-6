<?php
/**
 * API REST pública y administrativa para credenciales institucionales.
 *
 * @package ATORA_LMS
 * @since 6.25.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLMS_Credential_REST_Controller {
	const NAMESPACE = 'clms/v1';
	const RATE_LIMIT = 30;

	private $service;

	public function __construct() {
		$this->service = new CLMS_Credential_Service();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/credentials/verify/(?P<token>[0-9a-fA-F-]{36})', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'verify' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NAMESPACE, '/credentials', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'issue' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );
		register_rest_route( self::NAMESPACE, '/credentials/(?P<id>\d+)/revocations', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'request_revocation' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );
		register_rest_route( self::NAMESPACE, '/credential-revocations/(?P<id>\d+)/decision', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'decide_revocation' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );
		register_rest_route( self::NAMESPACE, '/credentials/(?P<id>\d+)/reissue', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'reissue' ),
			'permission_callback' => array( $this, 'can_manage' ),
		) );
	}

	public function can_manage(): bool {
		return current_user_can( CLMS_Credential_Service::REVOCATION_CAPABILITY );
	}

	public function verify( WP_REST_Request $request ) {
		if ( ! $this->consume_public_quota() ) {
			return new WP_Error( 'atora_credential_rate_limited', __( 'Demasiadas consultas. Intenta nuevamente en un minuto.', 'atora-lms' ), array( 'status' => 429 ) );
		}
		$result = $this->service->verify( $request['token'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function issue( WP_REST_Request $request ) {
		$result = $this->service->issue( (array) $request->get_json_params(), get_current_user_id() );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
	}

	public function request_revocation( WP_REST_Request $request ) {
		$result = $this->service->request_revocation( $request['id'], $request->get_param( 'category' ), $request->get_param( 'reason' ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 202 );
	}

	public function decide_revocation( WP_REST_Request $request ) {
		$result = $this->service->decide_revocation( $request['id'], $request->get_param( 'decision' ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function reissue( WP_REST_Request $request ) {
		$result = $this->service->reissue( $request['id'], (array) $request->get_param( 'overrides' ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
	}

	private function consume_public_quota(): bool {
		$raw_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'atora_cred_verify_' . hash_hmac( 'sha256', $raw_ip, wp_salt( 'nonce' ) );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
