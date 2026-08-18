<?php
/**
 * ATORA LMS v5 — Provider Brevo (SendinBlue)
 *
 * @package ATORA_LMS\EmailEngine\Providers
 * @since   5.0.0
 */

namespace ATORA\EmailEngine\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Brevo
 *
 * @since 5.0.0
 */
class Brevo implements Provider_Interface {

	const API_ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

	/** @return string */
	public static function get_name(): string {
		return 'Brevo (SendinBlue)';
	}

	/**
	 * Envía un email transaccional vía Brevo API v3.
	 *
	 * @param string $to        Email destinatario.
	 * @param string $subject   Asunto.
	 * @param string $body_html HTML.
	 * @param string $body_text Texto.
	 * @param array  $options   Opciones.
	 * @return bool
	 */
	public static function send( string $to, string $subject, string $body_html, string $body_text, array $options = array() ): bool {
		$api_key = self::get_api_key();
		if ( ! $api_key ) {
			return false;
		}

		$opts = get_option( 'atora_email_engine_options', array() );
		$from_email = sanitize_email( (string) ( $options['from_email'] ?? $opts['from_email'] ?? get_option( 'admin_email' ) ) );
		$from_name  = sanitize_text_field( (string) ( $options['from_name'] ?? $opts['from_name'] ?? get_bloginfo( 'name' ) ) );
		$reply_to   = sanitize_email( (string) ( $options['reply_to'] ?? '' ) );

		$payload = array(
			'sender'      => array( 'email' => $from_email, 'name' => $from_name ),
			'to'          => array( array( 'email' => $to ) ),
			'subject'     => $subject,
			'htmlContent' => $body_html,
			'textContent' => $body_text,
			'headers'     => array(
				'X-Atora-Queue-ID' => (string) ( $options['atora_queue_id'] ?? '' ),
			),
		);
		if ( $reply_to && is_email( $reply_to ) ) {
			$payload['replyTo'] = array( 'email' => $reply_to );
		}

		$body = wp_json_encode( $payload );

		$response = wp_remote_post( self::API_ENDPOINT, array(
			'headers' => array(
				'api-key'      => $api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'    => $body,
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/** @return bool */
	public static function test_connection(): bool {
		$api_key = self::get_api_key();
		if ( ! $api_key ) {
			return false;
		}

		$response = wp_remote_get( 'https://api.brevo.com/v3/account', array(
			'headers' => array( 'api-key' => $api_key ),
			'timeout' => 10,
		) );

		return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
	}

	/** @return string */
	private static function get_api_key(): string {
		$opts = get_option( 'atora_email_engine_options', array() );
		return sanitize_text_field( $opts['brevo_api_key'] ?? '' );
	}
}
