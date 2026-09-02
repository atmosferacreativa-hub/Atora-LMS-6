<?php
/**
 * ATORA LMS — Interfaz de providers de streaming en vivo (P7, sprint 6.13.0)
 *
 * Mismo patrón que modules/email-engine/providers/class-provider-interface.php.
 * `supports()` es lo que apaga la promesa: un provider que declara solo
 * ['create'] (p.ej. Teams, YouTube) hace que la UI deje de ofrecer
 * asistencia para él, en vez de ofrecerla y no cumplirla.
 *
 * @package ATORA_LMS\LiveStreaming\Providers
 * @since   6.13.0
 */

namespace ATORA\LiveStreaming\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Provider_Interface {

	/**
	 * Crea la sesión en el provider (reunión/evento).
	 *
	 * @param array $data Datos de la sesión: title, start_datetime,
	 *                     duration_minutes, timezone, record, etc.
	 * @return array|\WP_Error Debe incluir al menos 'external_id' y 'join_url'.
	 */
	public static function create_session( array $data );

	/**
	 * Resuelve la URL de unión para un usuario concreto.
	 *
	 * @param int $session_id ID en atora_live_sessions.
	 * @param int $user_id    Usuario que solicita unirse.
	 * @return string|\WP_Error
	 */
	public static function get_join_url( int $session_id, int $user_id );

	/**
	 * Obtiene la asistencia registrada por el provider para una sesión.
	 *
	 * @param string $external_id ID de la sesión en el provider.
	 * @return array|\WP_Error
	 */
	public static function fetch_attendance( string $external_id );

	/**
	 * Procesa un webhook entrante del provider.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response;

	/**
	 * Capacidades soportadas por este provider.
	 *
	 * @return string[] Subconjunto de array('create','attendance','recording','webhook').
	 */
	public static function supports(): array;
}
