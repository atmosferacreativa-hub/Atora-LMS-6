<?php
/**
 * Capa central de seguridad de ATORA.
 * Helpers reutilizables para REST, AJAX, webhooks y formularios.
 * Complementa CLMS_Access y CLMS_REST_Permissions.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATORA_Security {

	// ── Capabilities ──────────────────────────────────────────────────────

	/**
	 * Puede gestionar el gradebook (calificar o ser admin).
	 */
	public static function can_manage_gradebook(): bool {
		return current_user_can( 'manage_options' )
			|| ( class_exists( 'CLMS_Access' ) && CLMS_Access::can_grade_submissions() );
	}

	/**
	 * Puede ver datos de un estudiante concreto.
	 *
	 * @param int $student_id ID del estudiante.
	 */
	public static function can_view_student( int $student_id ): bool {
		$current = get_current_user_id();

		if ( ! $current ) {
			return false;
		}
		if ( $current === $student_id ) {
			return true;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return class_exists( 'CLMS_Access' )
			&& ( CLMS_Access::can_grade_submissions()
				|| CLMS_Access::can_view_teacher_dashboard()
				|| CLMS_Access::can_manage_courses() );
	}

	/**
	 * Puede calificar un curso concreto.
	 *
	 * @param int $course_id ID del curso.
	 */
	public static function can_grade_course( int $course_id ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( ! class_exists( 'CLMS_Access' ) || ! CLMS_Access::can_grade_submissions() ) {
			return false;
		}

		return class_exists( 'CLMS_Helper' ) ? (bool) CLMS_Helper::user_can_manage_lms( $course_id ) : true;
	}

	/**
	 * Puede gestionar la configuración del plugin.
	 */
	public static function can_manage_settings(): bool {
		return current_user_can( 'manage_options' );
	}

	// ── Nonces / AJAX ─────────────────────────────────────────────────────

	/**
	 * Verifica un nonce AJAX y muere con JSON si falla.
	 *
	 * @param string $action     Acción del nonce.
	 * @param string $field      Campo del nonce en $_POST/$_GET (default: 'nonce').
	 * @param bool   $die_on_fail Terminar si falla (default true).
	 * @return bool
	 */
	public static function verify_ajax_nonce( string $action, string $field = 'nonce', bool $die_on_fail = true ): bool {
		$raw   = $_POST[ $field ] ?? $_GET[ $field ] ?? '';
		$nonce = sanitize_text_field( wp_unslash( (string) $raw ) );

		if ( wp_verify_nonce( $nonce, $action ) ) {
			return true;
		}

		if ( $die_on_fail ) {
			wp_send_json_error( array( 'message' => __( 'Acción no autorizada.', 'atora-lms' ) ), 403 );
		}

		return false;
	}

	// ── Webhook signatures ────────────────────────────────────────────────

	/**
	 * Verifica una firma HMAC-SHA256 genérica de webhook.
	 *
	 * @param string $body      Cuerpo crudo del request.
	 * @param string $signature Firma recibida (hex o con prefijo "sha256=").
	 * @param string $secret    Secreto compartido.
	 * @param string $prefix    Prefijo esperado en la firma (p.ej. "sha256="). Vacío = sin prefijo.
	 * @return bool
	 */
	public static function verify_webhook_hmac( string $body, string $signature, string $secret, string $prefix = '' ): bool {
		if ( ! $secret ) {
			return defined( 'ATORA_DEV_MODE' ) && ATORA_DEV_MODE;
		}

		$sig_clean = $prefix ? ltrim( $signature, $prefix ) : $signature;
		if ( $prefix && ! str_starts_with( $signature, $prefix ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $body, $secret );

		return hash_equals( $expected, $sig_clean );
	}

	// ── Rate limiting ─────────────────────────────────────────────────────

	/**
	 * Rate limit simple basado en transients.
	 * Retorna false si se superó el límite.
	 *
	 * @param string $key        Clave única del rate limit (ej: 'ajax_2fa_' . $ip).
	 * @param int    $max        Máximo de llamadas permitidas.
	 * @param int    $window_sec Ventana en segundos.
	 * @return bool True si está dentro del límite.
	 */
	public static function rate_limit( string $key, int $max = 10, int $window_sec = 60 ): bool {
		$transient_key = 'atora_rl_' . md5( $key );
		$current       = (int) get_transient( $transient_key );

		if ( $current >= $max ) {
			return false;
		}

		if ( 0 === $current ) {
			set_transient( $transient_key, 1, $window_sec );
		} else {
			set_transient( $transient_key, $current + 1, $window_sec );
		}

		return true;
	}

	// ── Respuestas coherentes ─────────────────────────────────────────────

	/**
	 * Respuesta JSON de error estandarizada para AJAX/REST.
	 *
	 * @param string $message Mensaje de error.
	 * @param int    $code    Código HTTP.
	 * @param bool   $is_ajax Si true, usa wp_send_json_error(); si false, retorna WP_Error.
	 * @return WP_Error|void
	 */
	public static function error_response( string $message, int $code = 403, bool $is_ajax = true ) {
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => $message ), $code );
		}
		return new WP_Error( 'atora_forbidden', $message, array( 'status' => $code ) );
	}

	/**
	 * Respuesta JSON de éxito estandarizada para AJAX.
	 *
	 * @param mixed $data Datos a devolver.
	 * @return void
	 */
	public static function success_response( $data = array() ): void {
		wp_send_json_success( $data );
	}

	// ── Sanitización ──────────────────────────────────────────────────────

	/**
	 * Obtiene y sanitiza un campo string de $_POST.
	 *
	 * @param string $key     Nombre del campo.
	 * @param string $default Valor por defecto.
	 * @return string
	 */
	public static function post_string( string $key, string $default = '' ): string {
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ?? $default ) );
	}

	/**
	 * Obtiene y sanitiza un campo int de $_POST.
	 *
	 * @param string $key     Nombre del campo.
	 * @param int    $default Valor por defecto.
	 * @return int
	 */
	public static function post_int( string $key, int $default = 0 ): int {
		return absint( wp_unslash( $_POST[ $key ] ?? $default ) );
	}

	/**
	 * Obtiene y sanitiza un campo string de $_GET.
	 *
	 * @param string $key     Nombre del campo.
	 * @param string $default Valor por defecto.
	 * @return string
	 */
	public static function get_string( string $key, string $default = '' ): string {
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ?? $default ) );
	}

	/**
	 * Obtiene y sanitiza un campo int de $_GET.
	 *
	 * @param string $key     Nombre del campo.
	 * @param int    $default Valor por defecto.
	 * @return int
	 */
	public static function get_int( string $key, int $default = 0 ): int {
		return absint( wp_unslash( $_GET[ $key ] ?? $default ) );
	}

	// ── Logs seguros ──────────────────────────────────────────────────────

	/**
	 * Log de seguridad sin datos sensibles.
	 * Usa do_action para que otros módulos puedan escuchar.
	 *
	 * @param string $event   Nombre del evento.
	 * @param array  $context Contexto no sensible.
	 */
	public static function log( string $event, array $context = array() ): void {
		if ( defined( 'ATORA_DEV_MODE' ) && ATORA_DEV_MODE ) {
			// En dev mode: log a error_log si WP_DEBUG está activo.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[ATORA Security] ' . sanitize_text_field( $event ) . ' ' . wp_json_encode( $context ) );
			}
		}

		do_action( 'atora/security/log', sanitize_key( $event ), $context );
	}
}
