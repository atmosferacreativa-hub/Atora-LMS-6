<?php
/**
 * Baja de correos (6.38.0, Orden C · Comunicación).
 *
 * Antes: el enlace "Cancelar suscripción" de cada correo no llevaba token (no hacía nada) y la única forma
 * aceptada era el ID de la cola, un número consecutivo con el que cualquiera podía dar de baja a otra persona.
 *
 * Ahora:
 * - token firmado por usuario (HMAC con las sales del sitio), sin datos legibles salvo el ID;
 * - GET muestra una confirmación (los antivirus de correo abren enlaces y no deben dar de baja a nadie);
 * - POST da de baja: el botón de la página y el "un clic" de Gmail/Yahoo (RFC 8058, List-Unsubscribe-Post);
 * - cabeceras List-Unsubscribe y List-Unsubscribe-Post en cada correo con destinatario registrado.
 *
 * La baja corta lo comercial (boletín, ofertas, afiliados); lo de seguridad, cuenta y académico sigue llegando.
 *
 * @package ATORA_LMS
 * @since 6.38.0
 */

namespace ATORA\EmailEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Email_Unsubscribe {

	/** Categorías que corta una baja (y una queja de spam). */
	const COMMERCIAL = array( 'commercial', 'affiliates', 'newsletter', 'marketing' );

	public static function token( int $user_id, string $email ): string {
		return 'u' . $user_id . '.' . self::signature( $user_id, $email );
	}

	/** ID del usuario si el token es válido; 0 si no. */
	public static function verify( string $token ): int {
		if ( 1 !== preg_match( '/^u(\d{1,20})\.([a-f0-9]{32})$/', $token, $m ) ) {
			return 0;
		}
		$user = get_userdata( (int) $m[1] );
		if ( ! $user ) {
			return 0;
		}
		return hash_equals( self::signature( (int) $user->ID, (string) $user->user_email ), $m[2] ) ? (int) $user->ID : 0;
	}

	public static function url( int $user_id, string $email ): string {
		return add_query_arg(
			array(
				'atora_email_action' => 'unsubscribe',
				'atora_email_token'  => self::token( $user_id, $email ),
			),
			home_url( '/' )
		);
	}

	/** @return array<string,string> */
	public static function headers( int $user_id, string $email ): array {
		if ( $user_id <= 0 || '' === $email ) {
			return array();
		}
		return array(
			'List-Unsubscribe'      => '<' . self::url( $user_id, $email ) . '>',
			'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
		);
	}

	/**
	 * Cabeceras extra seguras para los proveedores: nombre `[A-Za-z0-9-]`, valor sin saltos de línea.
	 *
	 * @param mixed $headers
	 * @return array<string,string>
	 */
	public static function clean_headers( $headers ): array {
		$out = array();
		foreach ( is_array( $headers ) ? $headers : array() as $name => $value ) {
			$name  = (string) $name;
			$value = trim( (string) $value );
			if ( 1 === preg_match( '/^[A-Za-z0-9-]{1,64}$/', $name ) && '' !== $value && 1 !== preg_match( '/[\r\n]/', $value ) ) {
				$out[ $name ] = $value;
			}
		}
		return $out;
	}

	/** ¿El usuario se dio de baja de todo lo comercial? */
	public static function is_unsubscribed( int $user_id ): bool {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return false;
		}
		$table = $wpdb->prefix . 'atora_email_preferences';
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT unsubscribed_all FROM {$table} WHERE user_id = %d LIMIT 1", $user_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Resuelve la petición. Devuelve el estado de la página: confirm | done | invalid.
	 */
	public static function handle( string $token, string $method ): string {
		$user_id = self::verify( $token );
		if ( ! $user_id ) {
			return 'invalid';
		}
		if ( 'POST' !== strtoupper( $method ) ) {
			return 'confirm';
		}
		Email_Engine::unsubscribe_all( $user_id );
		self::log_consent( $user_id );
		return 'done';
	}

	private static function log_consent( int $user_id ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'atora_email_consent_log',
			array(
				'user_id'        => $user_id,
				'action'         => 'unsubscribe',
				'preference_key' => 'unsubscribed_all',
				'old_value'      => 0,
				'new_value'      => 1,
				'ip_address'     => self::ip(),
				'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '',
				'source'         => 'email_link',
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	private static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	private static function signature( int $user_id, string $email ): string {
		return substr( hash_hmac( 'sha256', 'atora-unsub|' . $user_id . '|' . strtolower( trim( $email ) ), wp_salt( 'auth' ) ), 0, 32 );
	}
}
