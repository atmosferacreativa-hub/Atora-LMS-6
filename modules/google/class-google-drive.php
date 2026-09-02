<?php
/**
 * Google_Drive — P10.4 (sprint 6.13.0)
 *
 * Solo `drive.file` (no sensible, verificación básica): acceso por
 * archivo mediante Google Picker, nunca `drive` ni `drive.readonly` —
 * esos son scopes restringidos que exigirían una evaluación de
 * seguridad anual con un tercero aprobado por Google para una app que
 * accede a datos restringidos desde un servidor propio. Esa decisión
 * ahorra meses y coste recurrente.
 *
 * El usuario elige el archivo explícitamente en el Picker; Google le da
 * acceso a la app SOLO a ese archivo. Este módulo únicamente guarda la
 * referencia (file_id, nombre, link) — nunca descarga ni sirve el
 * contenido del archivo desde el servidor de ATORA.
 *
 * @package ATORA_LMS\Google
 * @since   6.13.0
 */

namespace ATORA\Google;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Google_Drive {

	/**
	 * C3 (6.13.1): lista blanca explícita de qué usuario puede adjuntar/
	 * leer archivos de un contexto — fail-closed en cualquier
	 * context_type no reconocido. Mismo tipo de fallo de autorización ya
	 * remediado en las rutas del CRM en la serie 6.5.x: is_user_logged_in()
	 * por sí solo no dice nada sobre si ESTE usuario puede tocar ESTE
	 * recurso.
	 *
	 * @param string $context_type
	 * @param int    $context_id
	 * @return bool
	 */
	private static function can_attach_to_context( string $context_type, int $context_id ): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! $context_id ) {
			return false;
		}

		switch ( $context_type ) {
			case 'submission':
				if ( ! class_exists( 'CLMS_Helper' ) || 'clms_submission' !== get_post_type( $context_id ) ) {
					return false;
				}
				$author_id = absint( get_post_meta( $context_id, '_clms_submission_user_id', true ) );
				return $author_id === $user_id || \CLMS_Helper::user_can_manage_lms( $context_id );

			case 'lesson':
				return current_user_can( 'edit_post', $context_id );

			case 'course':
				// D3 (6.13.2): la OT 6.13.1 pedía "matriculado, o puede
				// editarlo" — user_is_enrolled_in_course() ya reconoce
				// internamente a quien puede gestionar el LMS, pero se
				// deja explícito, mismo patrón que 'submission' arriba,
				// para que un docente o admin sin matrícula propia en su
				// curso no quede bloqueado.
				if ( ! class_exists( 'CLMS_Helper' ) ) {
					return false;
				}
				return \CLMS_Helper::user_is_enrolled_in_course( $user_id, $context_id )
					|| \CLMS_Helper::user_can_manage_lms( $context_id );

			default:
				// Fail-closed: un context_type inventado o futuro sin
				// regla explícita se deniega, no se permite por omisión.
				return false;
		}
	}

	/**
	 * POST /google/drive/attach — guarda la referencia de un archivo
	 * elegido por Picker. No se valida el acceso al archivo en sí (eso
	 * lo garantiza drive.file: si el usuario lo eligió en el Picker, la
	 * app ya tiene grant sobre ese file_id concreto) — solo se valida
	 * que el contexto (lección/entrega/curso) le sea accesible al
	 * usuario actual (C3, 6.13.1).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function rest_attach_file( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$context_type = sanitize_key( (string) $request->get_param( 'context_type' ) );
		$context_id   = absint( $request->get_param( 'context_id' ) );
		$file_id      = sanitize_text_field( (string) $request->get_param( 'file_id' ) );
		$file_name    = sanitize_text_field( (string) $request->get_param( 'file_name' ) );

		if ( ! $context_type || ! $context_id || ! $file_id ) {
			return new \WP_REST_Response( array( 'message' => __( 'Faltan datos del archivo.', 'atora-lms' ) ), 400 );
		}

		if ( ! self::can_attach_to_context( $context_type, $context_id ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'No tienes acceso a este contenido.', 'atora-lms' ) ), 403 );
		}

		$table = $wpdb->prefix . 'atora_google_drive_files';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return new \WP_REST_Response( array( 'message' => __( 'Módulo Google no inicializado.', 'atora-lms' ) ), 500 );
		}

		$wpdb->insert( $table, array(
			'user_id'       => get_current_user_id(),
			'context_type'  => $context_type,
			'context_id'    => $context_id,
			'file_id'       => $file_id,
			'file_name'     => $file_name,
			'mime_type'     => sanitize_text_field( (string) $request->get_param( 'mime_type' ) ),
			'web_view_link' => esc_url_raw( (string) $request->get_param( 'web_view_link' ) ),
			'created_at'    => current_time( 'mysql', true ),
		) );

		do_action( 'atora/google/drive_file_attached', get_current_user_id(), $context_type, $context_id, $file_id );

		return new \WP_REST_Response( array( 'id' => (int) $wpdb->insert_id ), 201 );
	}

	/**
	 * @param string $context_type p.ej. 'lesson', 'submission'.
	 * @param int    $context_id
	 * @return array<int,array<string,mixed>> Vacío si el usuario actual no tiene acceso al contexto (C3, 6.13.1).
	 */
	public static function get_files_for_context( string $context_type, int $context_id ): array {
		global $wpdb;

		if ( ! self::can_attach_to_context( $context_type, $context_id ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'atora_google_drive_files';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE context_type = %s AND context_id = %d ORDER BY created_at DESC",
			sanitize_key( $context_type ), $context_id
		), ARRAY_A );
	}

	/**
	 * Helper JS reutilizable para abrir el Google Picker desde cualquier
	 * vista (lección, entrega) — encolarlo donde haga falta con
	 * `wp_enqueue_script('atora-google-picker')`. Requiere que el usuario
	 * ya haya conectado Google (P10.2) y que se le pase un access token
	 * con scope drive.file vigente — obtenerlo es responsabilidad de la
	 * vista concreta (fuera de alcance genérico de este módulo: cada
	 * pantalla decide cuándo pedirlo).
	 */
	public static function register_picker_script(): void {
		wp_register_script( 'google-picker-api', 'https://apis.google.com/js/api.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	}
}
