<?php
/**
 * REST Controller — Acciones de Draft/Segmento/Campaña CRM v2
 *
 * Expone los métodos de class-crm-v2.php como endpoints REST
 * para que el frontend pueda ejecutarlos sin recargar la página.
 *
 * Rutas registradas:
 *   POST /atora-crm/v2/draft/save          → guardar borrador
 *   POST /atora-crm/v2/draft/reset         → restaurar borrador base
 *   POST /atora-crm/v2/draft/segment/save  → guardar segmento nombrado
 *   POST /atora-crm/v2/draft/segment/apply → aplicar segmento guardado
 *   POST /atora-crm/v2/draft/segment/delete→ eliminar segmento
 *   POST /atora-crm/v2/draft/preview       → reconstruir muestra del segmento
 *   POST /atora-crm/v2/draft/bulk          → acción masiva
 *   POST /atora-crm/v2/draft/campaign      → lanzar campaña
 *
 * @package ATORA_LMS\CRM_V2\Rest
 * @since   5.22.0
 */

namespace ATORA\CRM_V2\Rest;

use ATORA\CRM_V2\CRM_V2;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CRM_Draft_REST_Controller {

	const REST_NAMESPACE = 'atora-crm/v2';

	/**
	 * Registra todas las rutas de este controlador.
	 *
	 * Llamar desde CRM_V2_App::register_rest_routes().
	 */
	public static function register_routes(): void {
		$ns = self::REST_NAMESPACE;
		$cb = array( __CLASS__, 'can_access' );

		$routes = array(
			array( '/draft/save',           'POST', 'handle_save_draft' ),
			array( '/draft/reset',          'POST', 'handle_reset_draft' ),
			array( '/draft/segment/save',   'POST', 'handle_save_segment' ),
			array( '/draft/segment/apply',  'POST', 'handle_apply_segment' ),
			array( '/draft/segment/delete', 'POST', 'handle_delete_segment' ),
			array( '/draft/preview',        'POST', 'handle_preview' ),
			array( '/draft/bulk',           'POST', 'handle_bulk' ),
			array( '/draft/campaign',       'POST', 'handle_campaign' ),
		);

		foreach ( $routes as $route ) {
			register_rest_route(
				$ns,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( __CLASS__, $route[2] ),
					'permission_callback' => $cb,
				)
			);
		}
	}

	/* ----------------------------------------------------------------
	 * Permiso
	 * ---------------------------------------------------------------- */

	public static function can_access(): bool {
		if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'can_manage_crm' ) ) {
			return (bool) \ATORA\CRM\CRM::can_manage_crm( get_current_user_id() );
		}
		return current_user_can( 'manage_options' );
	}

	/* ----------------------------------------------------------------
	 * Helpers internos
	 * ---------------------------------------------------------------- */

	/**
	 * Extrae y sanea el payload crm_v2 del body JSON del request.
	 *
	 * @param \WP_REST_Request $request
	 * @return array
	 */
	private static function get_input( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		$raw  = isset( $body['crm_v2'] ) && is_array( $body['crm_v2'] ) ? $body['crm_v2'] : array();
		// Delegamos el saneamiento completo a CRM_V2::sanitize_draft (método público)
		return $raw;
	}

	/**
	 * Devuelve el draft actual fusionado con el input y saneado.
	 *
	 * @param array $input Datos crudos del request.
	 * @return array Draft saneado.
	 */
	private static function merge_draft( array $input ): array {
		$current = CRM_V2::get_draft();
		return CRM_V2::sanitize_draft( array_merge( $current, $input ) );
	}

	/**
	 * Respuesta JSON estándar de éxito.
	 *
	 * @param string $message
	 * @param array  $data    Datos adicionales opcionales.
	 * @return \WP_REST_Response
	 */
	private static function ok( string $message, array $data = array() ): \WP_REST_Response {
		return new \WP_REST_Response(
			array_merge( array( 'success' => true, 'message' => $message ), $data ),
			200
		);
	}

	/**
	 * Respuesta JSON estándar de error.
	 *
	 * @param string $message
	 * @param int    $status  HTTP status code.
	 * @return \WP_REST_Response
	 */
	private static function fail( string $message, int $status = 400 ): \WP_REST_Response {
		return new \WP_REST_Response(
			array( 'success' => false, 'message' => $message ),
			$status
		);
	}

	/* ----------------------------------------------------------------
	 * Handlers
	 * ---------------------------------------------------------------- */

	/**
	 * POST /draft/save
	 * Guarda el borrador actual y devuelve la muestra actualizada.
	 */
	public static function handle_save_draft( \WP_REST_Request $request ): \WP_REST_Response {
		$input = self::get_input( $request );
		$draft = self::merge_draft( $input );
		update_option( CRM_V2::DRAFT_OPTION, $draft, false );

		$preview = CRM_V2::build_segment_result( $draft );

		return self::ok(
			__( 'Borrador guardado. Puedes seguir afinando antes de ejecutar.', 'atora-lms' ),
			array( 'preview' => $preview )
		);
	}

	/**
	 * POST /draft/reset
	 * Restaura el borrador a los valores por defecto.
	 */
	public static function handle_reset_draft( \WP_REST_Request $request ): \WP_REST_Response {
		$draft = CRM_V2::get_default_draft();
		update_option( CRM_V2::DRAFT_OPTION, $draft, false );

		$preview = CRM_V2::build_segment_result( $draft );

		return self::ok(
			__( 'Se restauró la configuración base del flujo CRM v2.', 'atora-lms' ),
			array( 'draft' => $draft, 'preview' => $preview )
		);
	}

	/**
	 * POST /draft/segment/save
	 * Guarda el segmento actual con un nombre reutilizable.
	 */
	public static function handle_save_segment( \WP_REST_Request $request ): \WP_REST_Response {
		$body  = $request->get_json_params();
		$input = isset( $body['crm_v2'] ) && is_array( $body['crm_v2'] ) ? $body['crm_v2'] : array();
		$name  = sanitize_text_field( (string) ( $input['segment_name'] ?? '' ) );

		if ( '' === $name ) {
			return self::fail( __( 'Indica un nombre para el segmento antes de guardarlo.', 'atora-lms' ) );
		}

		$draft = self::merge_draft( $input );
		update_option( CRM_V2::DRAFT_OPTION, $draft, false );

		$saved = CRM_V2::save_named_segment( $draft, $name );

		if ( ! $saved ) {
			return self::fail( __( 'No se pudo guardar el segmento. Verifica el nombre.', 'atora-lms' ) );
		}

		return self::ok(
			__( 'Segmento guardado para reutilizarlo en campañas futuras.', 'atora-lms' ),
			array( 'segments' => CRM_V2::get_saved_segments() )
		);
	}

	/**
	 * POST /draft/segment/apply
	 * Carga un segmento guardado al borrador activo.
	 */
	public static function handle_apply_segment( \WP_REST_Request $request ): \WP_REST_Response {
		$body       = $request->get_json_params();
		$segment_id = sanitize_key( (string) ( $body['segment_id'] ?? '' ) );

		if ( '' === $segment_id ) {
			return self::fail( __( 'Indica el segmento que deseas aplicar.', 'atora-lms' ) );
		}

		$loaded = CRM_V2::load_segment_filters( $segment_id );

		if ( empty( $loaded ) ) {
			return self::fail( __( 'No se encontró el segmento solicitado.', 'atora-lms' ) );
		}

		$current = CRM_V2::get_draft();
		$draft   = CRM_V2::sanitize_draft( array_merge( $current, $loaded ) );
		update_option( CRM_V2::DRAFT_OPTION, $draft, false );

		$preview = CRM_V2::build_segment_result( $draft );

		return self::ok(
			__( 'Segmento aplicado al borrador actual.', 'atora-lms' ),
			array( 'draft' => $draft, 'preview' => $preview )
		);
	}

	/**
	 * POST /draft/segment/delete
	 * Elimina un segmento guardado por ID.
	 */
	public static function handle_delete_segment( \WP_REST_Request $request ): \WP_REST_Response {
		$body       = $request->get_json_params();
		$segment_id = sanitize_key( (string) ( $body['segment_id'] ?? '' ) );

		if ( '' === $segment_id ) {
			return self::fail( __( 'Indica el segmento que deseas eliminar.', 'atora-lms' ) );
		}

		$deleted = CRM_V2::delete_named_segment( $segment_id );

		if ( ! $deleted ) {
			return self::fail( __( 'No se pudo eliminar el segmento.', 'atora-lms' ) );
		}

		return self::ok(
			__( 'Segmento eliminado.', 'atora-lms' ),
			array( 'segments' => CRM_V2::get_saved_segments() )
		);
	}

	/**
	 * POST /draft/preview
	 * Recalcula la muestra del segmento sin guardar el borrador.
	 * Útil para preview en tiempo real mientras el usuario filtra.
	 */
	public static function handle_preview( \WP_REST_Request $request ): \WP_REST_Response {
		$input   = self::get_input( $request );
		$draft   = CRM_V2::sanitize_draft( array_merge( CRM_V2::get_draft(), $input ) );
		$preview = CRM_V2::build_segment_result( $draft );

		return self::ok( '', array( 'preview' => $preview ) );
	}

	/**
	 * POST /draft/bulk
	 * Ejecuta una acción masiva sobre el segmento activo.
	 */
	public static function handle_bulk( \WP_REST_Request $request ): \WP_REST_Response {
		$input = self::get_input( $request );
		$draft = self::merge_draft( $input );
		update_option( CRM_V2::DRAFT_OPTION, $draft, false );

		$result = CRM_V2::run_bulk_action( $draft );

		if ( empty( $result['success'] ) ) {
			return self::fail( (string) ( $result['message'] ?? __( 'No se pudo ejecutar la acción masiva.', 'atora-lms' ) ) );
		}

		$preview = CRM_V2::build_segment_result( $draft );

		return self::ok(
			(string) ( $result['message'] ?? '' ),
			array( 'preview' => $preview )
		);
	}

	/**
	 * POST /draft/campaign
	 * Lanza una campaña en modo simulado o encolado.
	 */
	public static function handle_campaign( \WP_REST_Request $request ): \WP_REST_Response {
		$input = self::get_input( $request );
		$draft = self::merge_draft( $input );
		update_option( CRM_V2::DRAFT_OPTION, $draft, false );

		$result = CRM_V2::launch_campaign( $draft );

		if ( empty( $result['success'] ) ) {
			return self::fail( (string) ( $result['message'] ?? __( 'No se pudo registrar la campaña.', 'atora-lms' ) ) );
		}

		// Devolver historial actualizado
		$campaigns = CRM_V2::get_campaign_history();

		return self::ok(
			(string) ( $result['message'] ?? '' ),
			array( 'campaigns' => $campaigns )
		);
	}
}
