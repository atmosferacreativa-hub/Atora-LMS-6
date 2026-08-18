<?php
/**
 * Sequence_REST_Controller — Endpoints REST para secuencias y URL tracking (Fase 10)
 *
 * @package ATORA_LMS\CRM_V2\Rest
 * @since   5.29.0
 */

namespace ATORA\CRM_V2\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sequence_REST_Controller {

	public static function register_routes(): void {
		$ns = 'atora-crm/v2';

		// Fase 10: URL tracking genérico (short_key)
		register_rest_route( $ns, '/url/click/(?P<short_key>[a-zA-Z0-9_\-]+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_url_click' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * GET /url/click/{short_key} — Redirect trackeado con URL_Store_Service (Fase 10)
	 */
	public static function handle_url_click( \WP_REST_Request $request ): \WP_REST_Response {
		$short_key  = sanitize_key( (string) $request->get_param( 'short_key' ) );
		$contact_id = 0;
		$redirect   = home_url();

		if ( '' !== $short_key && class_exists( '\ATORA\CRM_V2\Services\URL_Store_Service' ) ) {
			$destination = \ATORA\CRM_V2\Services\URL_Store_Service::resolve_and_track( $short_key, $contact_id );
			if ( $destination ) {
				$redirect = $destination;
			}
		}

		if ( ! headers_sent() ) {
			wp_redirect( esc_url_raw( $redirect ), 302 );
			exit;
		}

		return new \WP_REST_Response( null, 302 );
	}
}
