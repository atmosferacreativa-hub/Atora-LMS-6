<?php
/**
 * ATORA LMS v5 — Rastreador de afiliados
 *
 * Detecta el parámetro ?ref= en la URL, valida el código de afiliado,
 * guarda una cookie y registra el click en la BD.
 *
 * @package ATORA_LMS\Affiliates
 * @since   5.0.0
 */

namespace ATORA\Affiliates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Affiliate_Tracker
 *
 * @since 5.0.0
 */
class Affiliate_Tracker {

	/** Nombre del parámetro URL de referido. */
	const PARAM = 'ref';

	/** Nombre de la cookie que almacena el código de referido. */
	const COOKIE_NAME = 'atora_affiliate_ref';

	/**
	 * Registra los hooks de tracking.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init',           array( __CLASS__, 'track_visit' ), 1 );
		add_action( 'woocommerce_init', array( __CLASS__, 'track_visit' ), 1 );
	}

	/**
	 * Procesa la visita: detecta el parámetro ref, establece la cookie y registra el click.
	 *
	 * @return void
	 */
	public static function track_visit(): void {
		$code = isset( $_GET[ self::PARAM ] )
			? strtoupper( sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) )
			: '';

		if ( ! $code ) {
			return;
		}

		$affiliate = Affiliates::get_affiliate_by_code( $code );

		if ( ! $affiliate || 'active' !== $affiliate->status ) {
			return;
		}

		// No rastrear si el propio afiliado visita su enlace.
		if ( is_user_logged_in() && (int) $affiliate->user_id === get_current_user_id() ) {
			return;
		}

		$opts     = Affiliates::get_options();
		$days     = absint( $opts['cookie_days'] ?? 30 );
		$expires  = time() + ( $days * DAY_IN_SECONDS );

		// Establecer cookie de referido.
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE_NAME,
				$code,
				$expires,
				COOKIEPATH,
				COOKIE_DOMAIN,
				is_ssl(),
				false // No httponly para que WooCommerce también pueda leerla en JS si fuera necesario.
			);
		}

		$_COOKIE[ self::COOKIE_NAME ] = $code;

		// Registrar click en BD (deduplicar por IP+affiliate en el mismo minuto).
		self::record_click( (int) $affiliate->id );
	}

	/**
	 * Devuelve el código de referido almacenado en la cookie.
	 *
	 * @return string Código o cadena vacía si no hay ninguno.
	 */
	public static function get_current_ref_code(): string {
		return isset( $_COOKIE[ self::COOKIE_NAME ] )
			? strtoupper( sanitize_text_field( $_COOKIE[ self::COOKIE_NAME ] ) )
			: '';
	}

	/**
	 * Elimina la cookie de referido (tras conversión).
	 *
	 * @return void
	 */
	public static function clear_cookie(): void {
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE_NAME, '', time() - HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );
		}
		unset( $_COOKIE[ self::COOKIE_NAME ] );
	}

	// ── Privado ───────────────────────────────────────────────────────────────

	/**
	 * Registra un click en la tabla atora_affiliate_clicks.
	 *
	 * @param int $affiliate_id ID del afiliado.
	 * @return void
	 */
	private static function record_click( int $affiliate_id ): void {
		global $wpdb;

		$ip = self::get_ip();

		// Deduplicar: mismo affiliate_id + IP en los últimos 60 segundos.
		$recent = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}atora_affiliate_clicks
				 WHERE affiliate_id = %d
				   AND ip = %s
				   AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 SECOND)
				 LIMIT 1",
				$affiliate_id,
				$ip
			)
		);

		if ( $recent ) {
			return;
		}

		$landing_url = esc_url_raw( ( is_ssl() ? 'https' : 'http' ) . '://' . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' ) );

		$wpdb->insert(
			"{$wpdb->prefix}atora_affiliate_clicks",
			array(
				'affiliate_id' => $affiliate_id,
				'ip'           => $ip,
				'user_agent'   => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
				'landing_url'  => $landing_url,
				'referrer_url' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Obtiene la IP del cliente.
	 *
	 * @return string
	 */
	private static function get_ip(): string {
		$headers = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' );
		foreach ( $headers as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}
}
