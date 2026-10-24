<?php
/**
 * API móvil v1: SCORM runtime (Bloque 3).
 *
 * Namespace: atora-mobile/v1
 * Base: scorm
 *
 * @package ATORA_LMS
 * @since 6.35.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Mobile_SCORM_Controller {
	private const DOWNLOAD_TTL = 15 * MINUTE_IN_SECONDS;

	public static function register_routes(): void {
		if ( ! class_exists( 'CLMS_SCORM_Attempt_Service' ) || ! class_exists( 'CLMS_SCORM_Launch_Service' ) ) {
			return;
		}
		register_rest_route( ATORA_Mobile_REST_Controller::REST_NAMESPACE, '/scorm/attempts/start', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'start_attempt' ),
			'permission_callback' => array( 'ATORA_Mobile_REST_Controller', 'authorize' ),
		) );
		register_rest_route( ATORA_Mobile_REST_Controller::REST_NAMESPACE, '/scorm/attempts/(?P<id>\\d+)/values', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_values' ),
			'permission_callback' => array( 'ATORA_Mobile_REST_Controller', 'authorize' ),
			'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( ATORA_Mobile_REST_Controller::REST_NAMESPACE, '/scorm/attempts/(?P<id>\\d+)/commit', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'commit' ),
			'permission_callback' => array( 'ATORA_Mobile_REST_Controller', 'authorize' ),
			'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
		) );
		register_rest_route( ATORA_Mobile_REST_Controller::REST_NAMESPACE, '/scorm/packages/link', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'package_download_link' ),
			'permission_callback' => array( 'ATORA_Mobile_REST_Controller', 'authorize' ),
		) );
		register_rest_route( ATORA_Mobile_REST_Controller::REST_NAMESPACE, '/scorm/packages/download', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'package_download' ),
			'permission_callback' => array( 'ATORA_Mobile_REST_Controller', 'authorize' ),
		) );
	}

	/**
	 * @return array{lesson_id:int,wp_lesson_id:int,course_id:int}|WP_Error
	 */
	private static function lesson_context( int $lesson_id ) {
		$user_id  = get_current_user_id();
		$lesson_id = absint( $lesson_id );
		$lesson    = $lesson_id && class_exists( '\\ATORA\\LMS\\LMS_Course_Service' )
			? \ATORA\LMS\LMS_Course_Service::get_lesson( $lesson_id )
			: null;
		if ( ! $lesson || 'published' !== (string) ( $lesson['status'] ?? '' ) ) {
			return new WP_Error( 'atora_mobile_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$course_id = absint( $lesson['course_id'] ?? 0 );
		$auth      = ATORA_Mobile_REST_Controller::authorize_course_id( $user_id, $course_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$wp_lesson_id = absint( $lesson['wp_post_id'] ?? 0 );
		if ( $wp_lesson_id <= 0 || 'lm_lesson' !== get_post_type( $wp_lesson_id ) ) {
			return new WP_Error( 'atora_mobile_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		return array(
			'lesson_id'    => $lesson_id,
			'wp_lesson_id' => $wp_lesson_id,
			'course_id'    => $course_id,
		);
	}

	public static function start_attempt( WP_REST_Request $request ) {
		$params          = (array) $request->get_json_params();
		$lesson_id       = absint( $params['lesson_id'] ?? 0 );
		$library_item_id = absint( $params['library_item_id'] ?? 0 );
		if ( ! $lesson_id || ! $library_item_id ) {
			return new WP_Error( 'atora_scorm_invalid_params', __( 'Parámetros inválidos.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$context = self::lesson_context( $lesson_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$wp_lesson_id = absint( $context['wp_lesson_id'] ?? 0 );
		$user_id      = get_current_user_id();

		$resource = CLMS_SCORM_Launch_Service::find_scorm_resource_for_lesson( $wp_lesson_id, $library_item_id );
		if ( is_wp_error( $resource ) ) {
			return $resource;
		}
		$resource_version_id = absint( $resource['library_version_id'] ?? 0 );
		$version = CLMS_SCORM_Launch_Service::resolve_library_version( $library_item_id, $resource_version_id );
		if ( is_wp_error( $version ) ) {
			return $version;
		}
		$meta = CLMS_SCORM_Launch_Service::extract_scorm_meta( $version );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		$attempts = new CLMS_SCORM_Attempt_Service();
		$max_attempts = max( 0, absint( get_post_meta( $wp_lesson_id, '_clms_scorm_max_attempts', true ) ) );
		$attempt = $attempts->start_or_resume_attempt( $user_id, $wp_lesson_id, $library_item_id, absint( $version['id'] ?? 0 ), (string) $meta['scorm_version'], $max_attempts );
		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}

		$sco_hrefs = CLMS_SCORM_Launch_Service::list_sco_hrefs_from_install( (string) $meta['content_base_url'] );
		if ( is_wp_error( $sco_hrefs ) ) {
			$sco_hrefs = array( (string) $meta['launch_href'] );
		}

		$link = self::download_link_data( $user_id, $wp_lesson_id, $library_item_id, absint( $version['id'] ?? 0 ) );
		if ( is_wp_error( $link ) ) {
			return $link;
		}

		return new WP_REST_Response(
			array(
				'attempt_id'                => absint( $attempt['id'] ?? 0 ),
				'lesson_id'                 => $lesson_id,
				'wp_lesson_id'              => $wp_lesson_id,
				'library_item_id'           => $library_item_id,
				'library_version_id'        => absint( $version['id'] ?? 0 ),
				'version_checksum_sha256'   => sanitize_text_field( (string) ( $version['checksum_sha256'] ?? '' ) ),
				'scorm_version'             => (string) $meta['scorm_version'],
				'launch_href'               => (string) $meta['launch_href'],
				'sco_hrefs'                 => array_values( array_filter( array_map( 'strval', (array) $sco_hrefs ) ) ),
			) + $link,
			201
		);
	}

	public static function get_values( WP_REST_Request $request ) {
		global $wpdb;
		$attempt_id = absint( $request->get_param( 'id' ) );
		$table      = CLMS_SCORM_Attempt_Service::table_cmi();
		$rows       = (array) $wpdb->get_results( $wpdb->prepare( "SELECT cmi_key, cmi_value FROM {$table} WHERE attempt_id = %d", $attempt_id ), ARRAY_A );
		$out = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['cmi_key'] ) ) {
				$out[ (string) $row['cmi_key'] ] = (string) ( $row['cmi_value'] ?? '' );
			}
		}
		return new WP_REST_Response( array( 'values' => $out ), 200 );
	}

	public static function commit( WP_REST_Request $request ) {
		$attempt_id = absint( $request->get_param( 'id' ) );
		$params = (array) $request->get_json_params();
		$values = isset( $params['values'] ) && is_array( $params['values'] ) ? (array) $params['values'] : array();
		$client_event_id = sanitize_text_field( (string) ( $params['client_event_id'] ?? '' ) );
		$device_time     = sanitize_text_field( (string) ( $params['device_time'] ?? '' ) );

		$service = new CLMS_SCORM_Attempt_Service();
		$result  = $service->commit_values_with_client_event( $attempt_id, get_current_user_id(), $values, $client_event_id, $device_time );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	private static function signature( int $user_id, int $wp_lesson_id, int $library_item_id, int $library_version_id, int $expires ): string {
		$payload = $user_id . '|' . $wp_lesson_id . '|' . $library_item_id . '|' . $library_version_id . '|' . $expires;
		return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) . 'atora-mobile-scorm-download' );
	}

	/**
	 * @return array{download_url:string,download_expires_at:string,download_bytes:int}|WP_Error
	 */
	public static function download_link_data( int $user_id, int $wp_lesson_id, int $library_item_id, int $library_version_id ) {
		global $wpdb;
		$user_id           = absint( $user_id );
		$wp_lesson_id      = absint( $wp_lesson_id );
		$library_item_id   = absint( $library_item_id );
		$library_version_id = absint( $library_version_id );
		$expires = time() + self::DOWNLOAD_TTL;
		$sig = self::signature( $user_id, $wp_lesson_id, $library_item_id, $library_version_id, $expires );
		$url = add_query_arg(
			array(
				'lesson_id'         => $wp_lesson_id,
				'library_item_id'   => $library_item_id,
				'library_version_id'=> $library_version_id,
				'expires'           => $expires,
				'sig'               => $sig,
			),
			rest_url( ATORA_Mobile_REST_Controller::REST_NAMESPACE . '/scorm/packages/download' )
		);

		$bytes = 0;
		$attachment_id = absint( $wpdb->get_var( $wpdb->prepare( "SELECT attachment_id FROM {$wpdb->prefix}atora_library_versions WHERE id = %d LIMIT 1", $library_version_id ) ) );
		if ( $attachment_id > 0 && function_exists( 'get_attached_file' ) ) {
			$path = (string) get_attached_file( $attachment_id );
			if ( '' !== $path && file_exists( $path ) ) {
				$bytes = max( 0, (int) filesize( $path ) );
			}
		}

		return array(
			'download_url'        => esc_url_raw( $url ),
			'download_expires_at' => gmdate( 'Y-m-d\\TH:i:s\\Z', $expires ),
			'download_bytes'      => $bytes,
		);
	}

	public static function package_download_link( WP_REST_Request $request ) {
		$params          = (array) $request->get_json_params();
		$lesson_id       = absint( $params['lesson_id'] ?? 0 );
		$library_item_id = absint( $params['library_item_id'] ?? 0 );
		if ( ! $lesson_id || ! $library_item_id ) {
			return new WP_Error( 'atora_scorm_invalid_params', __( 'Parámetros inválidos.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$context = self::lesson_context( $lesson_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$wp_lesson_id = absint( $context['wp_lesson_id'] ?? 0 );
		$user_id      = get_current_user_id();

		$resource = CLMS_SCORM_Launch_Service::find_scorm_resource_for_lesson( $wp_lesson_id, $library_item_id );
		if ( is_wp_error( $resource ) ) {
			return $resource;
		}
		$resource_version_id = absint( $resource['library_version_id'] ?? 0 );
		$version = CLMS_SCORM_Launch_Service::resolve_library_version( $library_item_id, $resource_version_id );
		if ( is_wp_error( $version ) ) {
			return $version;
		}
		$meta = CLMS_SCORM_Launch_Service::extract_scorm_meta( $version );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		$attempts = new CLMS_SCORM_Attempt_Service();
		$max_attempts = max( 0, absint( get_post_meta( $wp_lesson_id, '_clms_scorm_max_attempts', true ) ) );
		$attempt = $attempts->start_or_resume_attempt( $user_id, $wp_lesson_id, $library_item_id, absint( $version['id'] ?? 0 ), (string) $meta['scorm_version'], $max_attempts );
		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}

		$link = self::download_link_data( $user_id, $wp_lesson_id, $library_item_id, absint( $version['id'] ?? 0 ) );
		return is_wp_error( $link )
			? $link
			: new WP_REST_Response(
				array(
					'attempt_id'              => absint( $attempt['id'] ?? 0 ),
					'library_version_id'      => absint( $version['id'] ?? 0 ),
					'version_checksum_sha256' => sanitize_text_field( (string) ( $version['checksum_sha256'] ?? '' ) ),
					'scorm_version'           => (string) $meta['scorm_version'],
				) + $link,
				200
			);
	}

	public static function package_download( WP_REST_Request $request ) {
		global $wpdb;
		$user_id           = get_current_user_id();
		$wp_lesson_id      = absint( $request->get_param( 'lesson_id' ) );
		$library_item_id   = absint( $request->get_param( 'library_item_id' ) );
		$library_version_id = absint( $request->get_param( 'library_version_id' ) );
		$expires           = absint( $request->get_param( 'expires' ) );
		$sig               = (string) $request->get_param( 'sig' );
		$expected          = self::signature( $user_id, $wp_lesson_id, $library_item_id, $library_version_id, $expires );
		if ( $expires < time() || '' === $sig || ! hash_equals( $expected, $sig ) ) {
			return new WP_Error( 'atora_scorm_download_link', __( 'El enlace venció o no es válido.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		if ( ! $wp_lesson_id || ! $library_item_id || ! $library_version_id ) {
			return new WP_Error( 'atora_scorm_invalid_params', __( 'Parámetros inválidos.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$resource = CLMS_SCORM_Launch_Service::find_scorm_resource_for_lesson( $wp_lesson_id, $library_item_id );
		if ( is_wp_error( $resource ) ) {
			return $resource;
		}
		$resource_version_id = absint( $resource['library_version_id'] ?? 0 );
		$resolved_version = CLMS_SCORM_Launch_Service::resolve_library_version( $library_item_id, $resource_version_id );
		if ( is_wp_error( $resolved_version ) ) {
			return $resolved_version;
		}
		if ( absint( $resolved_version['id'] ?? 0 ) !== $library_version_id ) {
			return new WP_Error( 'atora_scorm_download_mismatch', __( 'Versión inválida.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$attachment_id = absint( $resolved_version['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 || ! function_exists( 'get_attached_file' ) ) {
			return new WP_Error( 'atora_scorm_download_unavailable', __( 'No se pudo resolver el ZIP.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$path = (string) get_attached_file( $attachment_id );
		if ( '' === $path || ! file_exists( $path ) ) {
			return new WP_Error( 'atora_scorm_download_missing', __( 'ZIP no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$bytes = max( 0, (int) filesize( $path ) );
		$filename = 'scorm-' . $library_item_id . '-v' . $library_version_id . '.zip';

		add_filter( 'rest_pre_serve_request', static function () use ( $path, $bytes, $filename ) {
			if ( ! headers_sent() ) {
				header( 'Content-Type: application/zip' );
				header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
				if ( $bytes > 0 ) {
					header( 'Content-Length: ' . $bytes );
				}
			}
			readfile( $path );
			return true;
		} );
		return new WP_REST_Response( null, 200 );
	}
}

