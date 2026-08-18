<?php
/**
 * REST Controller — Empresas y Listas CRM (Fase 9)
 *
 * @package ATORA_LMS\CRM_V2\Rest
 * @since   5.28.0
 */

namespace ATORA\CRM_V2\Rest;

use ATORA\CRM_V2\Services\Company_Service;
use ATORA\CRM_V2\Services\List_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Companies_Lists_REST_Controller {

	const REST_NAMESPACE = 'atora-crm/v2';

	public static function register_routes(): void {
		$ns  = self::REST_NAMESPACE;
		// PT-1.2 (6.5.1): lectura se queda en can_access; crear/actualizar/
		// asignar/suscribir exige can_manage — antes todo compartía $can.
		$can         = array( 'ATORA\CRM_V2\Rest\CRM_REST_Controller', 'can_access' );
		$can_manage  = array( 'ATORA\CRM_V2\Rest\CRM_REST_Controller', 'can_manage' );

		register_rest_route( $ns, '/companies', array(
			array( 'methods' => 'GET',  'callback' => array( __CLASS__, 'list_companies' ),  'permission_callback' => $can ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_company' ), 'permission_callback' => $can_manage ),
		) );
		register_rest_route( $ns, '/companies/(?P<company_id>\d+)', array(
			array( 'methods' => 'GET',  'callback' => array( __CLASS__, 'get_company' ),    'permission_callback' => $can ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'update_company' ), 'permission_callback' => $can_manage ),
		) );
		register_rest_route( $ns, '/companies/(?P<company_id>\d+)/assign', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'assign_contact' ), 'permission_callback' => $can_manage,
		) );
		register_rest_route( $ns, '/lists', array(
			array( 'methods' => 'GET',  'callback' => array( __CLASS__, 'list_lists' ),  'permission_callback' => $can ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_list' ), 'permission_callback' => $can_manage ),
		) );
		register_rest_route( $ns, '/lists/(?P<list_id>\d+)/subscribe', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'subscribe' ), 'permission_callback' => $can_manage,
		) );
		register_rest_route( $ns, '/lists/(?P<list_id>\d+)/unsubscribe', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'unsubscribe' ), 'permission_callback' => $can_manage,
		) );
	}

	private static function ok( string $msg, array $data = array() ): \WP_REST_Response {
		return new \WP_REST_Response( array_merge( array( 'success' => true, 'message' => $msg ), $data ), 200 );
	}
	private static function fail( string $msg, int $s = 400 ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'success' => false, 'message' => $msg ), $s );
	}

	public static function list_companies( \WP_REST_Request $r ): \WP_REST_Response {
		$limit  = absint( $r->get_param( 'limit' )  ?: 30 );
		$offset = absint( $r->get_param( 'offset' ) ?: 0 );
		$search = sanitize_text_field( (string) ( $r->get_param( 'search' ) ?: '' ) );
		return self::ok( '', Company_Service::get_all( $limit, $offset, $search ) );
	}

	public static function create_company( \WP_REST_Request $r ): \WP_REST_Response {
		$id = Company_Service::create( $r->get_json_params() ?: array() );
		if ( ! $id ) { return self::fail( __( 'El nombre es obligatorio.', 'atora-lms' ) ); }
		return self::ok( __( 'Empresa creada.', 'atora-lms' ), array( 'company' => Company_Service::get( $id ) ) );
	}

	public static function get_company( \WP_REST_Request $r ): \WP_REST_Response {
		$id = absint( $r->get_param( 'company_id' ) );
		$c  = Company_Service::get( $id );
		if ( ! $c ) { return self::fail( __( 'Empresa no encontrada.', 'atora-lms' ), 404 ); }
		$c['contacts'] = Company_Service::get_contacts( $id );
		$c['ltv']      = Company_Service::get_ltv( $id );
		return self::ok( '', array( 'company' => $c ) );
	}

	public static function update_company( \WP_REST_Request $r ): \WP_REST_Response {
		$id = absint( $r->get_param( 'company_id' ) );
		$ok = Company_Service::update( $id, $r->get_json_params() ?: array() );
		if ( ! $ok ) { return self::fail( __( 'No se pudo actualizar.', 'atora-lms' ) ); }
		return self::ok( __( 'Empresa actualizada.', 'atora-lms' ), array( 'company' => Company_Service::get( $id ) ) );
	}

	public static function assign_contact( \WP_REST_Request $r ): \WP_REST_Response {
		$b          = $r->get_json_params() ?: array();
		$company_id = absint( $r->get_param( 'company_id' ) );
		$contact_id = absint( $b['contact_id'] ?? 0 );
		if ( ! $contact_id ) { return self::fail( __( 'Se requiere contact_id.', 'atora-lms' ) ); }
		$ok = Company_Service::assign_contact( $company_id, $contact_id );
		return $ok ? self::ok( __( 'Contacto asignado.', 'atora-lms' ) ) : self::fail( __( 'Error al asignar.', 'atora-lms' ) );
	}

	public static function list_lists( \WP_REST_Request $r ): \WP_REST_Response {
		$type = sanitize_key( (string) ( $r->get_param( 'type' ) ?: '' ) );
		return self::ok( '', array( 'lists' => List_Service::get_all( $type ) ) );
	}

	public static function create_list( \WP_REST_Request $r ): \WP_REST_Response {
		$id = List_Service::create( $r->get_json_params() ?: array() );
		if ( ! $id ) { return self::fail( __( 'El título es obligatorio.', 'atora-lms' ) ); }
		return self::ok( __( 'Lista creada.', 'atora-lms' ), array( 'list_id' => $id ) );
	}

	public static function subscribe( \WP_REST_Request $r ): \WP_REST_Response {
		$b          = $r->get_json_params() ?: array();
		$list_id    = absint( $r->get_param( 'list_id' ) );
		$contact_id = absint( $b['contact_id'] ?? 0 );
		if ( ! $contact_id ) { return self::fail( __( 'Se requiere contact_id.', 'atora-lms' ) ); }
		$ok = List_Service::subscribe_contact( $list_id, $contact_id );
		return $ok ? self::ok( __( 'Contacto suscrito.', 'atora-lms' ) ) : self::fail( __( 'Error al suscribir.', 'atora-lms' ) );
	}

	public static function unsubscribe( \WP_REST_Request $r ): \WP_REST_Response {
		$b          = $r->get_json_params() ?: array();
		$list_id    = absint( $r->get_param( 'list_id' ) );
		$contact_id = absint( $b['contact_id'] ?? 0 );
		if ( ! $contact_id ) { return self::fail( __( 'Se requiere contact_id.', 'atora-lms' ) ); }
		$ok = List_Service::unsubscribe_contact( $list_id, $contact_id );
		return $ok ? self::ok( __( 'Contacto desuscrito.', 'atora-lms' ) ) : self::fail( __( 'Error al desuscribir.', 'atora-lms' ) );
	}
}
