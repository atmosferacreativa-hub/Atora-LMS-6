<?php
/**
 * ATORA LMS v5 — Provider SendGrid
 *
 * @package ATORA_LMS\EmailEngine\Providers
 * @since   5.0.0
 */

namespace ATORA\EmailEngine\Providers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SendGrid implements Provider_Interface {

	const API_ENDPOINT = 'https://api.sendgrid.com/v3/mail/send';

	public static function get_name(): string { return 'SendGrid'; }

	public static function send( string $to, string $subject, string $body_html, string $body_text, array $options = array() ): bool {
		$api_key = self::get_api_key();
		if ( ! $api_key ) { return false; }

		$opts       = get_option( 'atora_email_engine_options', array() );
		$from_email = sanitize_email( (string) ( $options['from_email'] ?? $opts['from_email'] ?? get_option( 'admin_email' ) ) );
		$from_name  = sanitize_text_field( (string) ( $options['from_name'] ?? $opts['from_name'] ?? get_bloginfo( 'name' ) ) );
		$reply_to   = sanitize_email( (string) ( $options['reply_to'] ?? '' ) );

		$payload = array(
			'personalizations' => array( array(
					'to'                       => array( array( 'email' => $to ) ),
					'custom_args'              => array( 'atora_queue_id' => (string) ( $options['atora_queue_id'] ?? '' ) ),
			) ),
			'from'    => array( 'email' => $from_email, 'name' => $from_name ),
			'subject' => $subject,
			'content' => array(
				array( 'type' => 'text/plain', 'value' => $body_text ),
				array( 'type' => 'text/html',  'value' => $body_html ),
			),
			'tracking_settings' => array(
				'open_tracking'  => array( 'enable' => true ),
				'click_tracking' => array( 'enable' => true, 'enable_text' => false ),
			),
		);
		if ( $reply_to && is_email( $reply_to ) ) {
			$payload['reply_to'] = array( 'email' => $reply_to );
		}

		$body = wp_json_encode( $payload );

		$response = wp_remote_post( self::API_ENDPOINT, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => $body,
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) { return false; }
		$code = wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	public static function test_connection(): bool {
		$api_key = self::get_api_key();
		if ( ! $api_key ) { return false; }
		$r = wp_remote_get( 'https://api.sendgrid.com/v3/user/profile', array(
			'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
			'timeout' => 10,
		) );
		return ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200;
	}

	private static function get_api_key(): string {
		$opts = get_option( 'atora_email_engine_options', array() );
		return sanitize_text_field( $opts['sendgrid_api_key'] ?? '' );
	}
}
