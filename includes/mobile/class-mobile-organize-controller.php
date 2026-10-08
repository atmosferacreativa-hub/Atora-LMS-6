<?php
/**
 * API móvil — Organizarse (6.30.0): agenda, Hoy, dispositivos y preferencias
 * de notificación.
 *
 * @package ATORA_LMS
 * @since 6.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Mobile_Organize_Controller {

	public static function register_routes(): void {
		$ns   = ATORA_Mobile_REST_Controller::REST_NAMESPACE;
		$auth = array( 'ATORA_Mobile_REST_Controller', 'authorize' );
		register_rest_route( $ns, '/agenda', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'agenda' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/today', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'today' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/devices', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'register_device' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/devices/(?P<device_id>\d+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'delete_device' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/notification-preferences', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_preferences' ),
				'permission_callback' => $auth,
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'put_preferences' ),
				'permission_callback' => $auth,
			),
		) );
	}

	/** Fecha (Y-m-d o ISO 8601) → marca; los días sin hora toman el inicio (o el fin) del día en la zona del sitio. */
	private static function parse_date( string $raw, bool $end ): int {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return 0;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return CLMS_Agenda_Service::local_ts( $raw, $end ? '23:59:59' : '00:00:00' );
		}
		$ts = strtotime( $raw );
		return $ts ? $ts : 0;
	}

	/** GET /agenda?from=&to= (máximo 62 días; por defecto, hoy y los 14 siguientes). */
	public static function agenda( WP_REST_Request $request ) {
		if ( ! class_exists( 'CLMS_Agenda_Service' ) ) {
			return new WP_Error( 'atora_mobile_agenda_unavailable', __( 'La agenda no está disponible.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$from = self::parse_date( (string) $request->get_param( 'from' ), false );
		$to   = self::parse_date( (string) $request->get_param( 'to' ), true );
		if ( ! $from ) {
			$from = CLMS_Agenda_Service::local_ts( wp_date( 'Y-m-d' ), '00:00:00' );
		}
		if ( ! $to ) {
			$to = $from + 15 * DAY_IN_SECONDS - 1;
		}
		$items = CLMS_Agenda_Service::for_user( get_current_user_id(), $from, $to );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		return new WP_REST_Response( array(
			'from'         => CLMS_Agenda_Service::iso( $from ),
			'to'           => CLMS_Agenda_Service::iso( $to ),
			'timezone'     => wp_timezone_string(),
			'items'        => $items,
			'generated_at' => gmdate( 'c' ),
		), 200 );
	}

	/** GET /today — el Hoy del usuario según su rol (estudiante o personal). */
	public static function today( WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$aggregator = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Today_Aggregator_Service' ) : null;
		if ( ! $aggregator ) {
			return new WP_Error( 'atora_mobile_today_unavailable', __( 'Hoy no está disponible.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		if ( ATORA_Mobile_Messages_Controller::is_staff( $user_id ) && 'student' !== $request->get_param( 'role' ) ) {
			return new WP_REST_Response( array(
				'role'            => 'staff',
				'items'           => array_values( (array) $aggregator->get_today( $user_id ) ),
				'unread_messages' => class_exists( 'ATORA_Inbox_Store' ) ? ATORA_Inbox_Store::unread_count( $user_id ) : 0,
				'generated_at'    => gmdate( 'c' ),
			), 200 );
		}
		return new WP_REST_Response( $aggregator->get_student_today( $user_id ) + array( 'generated_at' => gmdate( 'c' ) ), 200 );
	}

	/** POST /devices { token, platform } */
	public static function register_device( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$token  = trim( (string) ( $params['token'] ?? '' ) );
		if ( ! ATORA_Mobile_Push_Service::valid_token( $token ) ) {
			return new WP_Error( 'atora_mobile_device_token', __( 'Token de notificaciones inválido.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		// 6.33.1 (E.6): atado a la sesión que lo registra; al revocarla se borra.
		$id = ATORA_Mobile_Push_Service::register_device( get_current_user_id(), $token, (string) ( $params['platform'] ?? '' ), (string) $request->get_param( '_atora_mobile_session_id' ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return new WP_REST_Response( array( 'id' => $id ), 201 );
	}

	/** DELETE /devices/{id} — solo los propios. */
	public static function delete_device( WP_REST_Request $request ) {
		$deleted = ATORA_Mobile_Push_Service::delete_device( get_current_user_id(), absint( $request['device_id'] ) );
		if ( ! $deleted ) {
			return new WP_Error( 'atora_mobile_device_not_found', __( 'Dispositivo no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	public static function get_preferences(): WP_REST_Response {
		return new WP_REST_Response( array( 'preferences' => ATORA_Mobile_Push_Service::preferences( get_current_user_id() ) ), 200 );
	}

	public static function put_preferences( WP_REST_Request $request ): WP_REST_Response {
		$params = (array) $request->get_json_params();
		$input  = is_array( $params['preferences'] ?? null ) ? $params['preferences'] : $params;
		return new WP_REST_Response( array( 'preferences' => ATORA_Mobile_Push_Service::save_preferences( get_current_user_id(), $input ) ), 200 );
	}
}
