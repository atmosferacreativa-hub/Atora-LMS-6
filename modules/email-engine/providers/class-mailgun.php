<?php
/**
 * ATORA LMS v5 — Provider Mailgun
 *
 * @package ATORA_LMS\EmailEngine\Providers
 * @since   5.0.0
 */

namespace ATORA\EmailEngine\Providers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mailgun implements Provider_Interface {

	public static function get_name(): string { return 'Mailgun'; }

	public static function send( string $to, string $subject, string $body_html, string $body_text, array $options = array() ): bool {
		$opts    = get_option( 'atora_email_engine_options', array() );
		$api_key = sanitize_text_field( $opts['mailgun_api_key'] ?? '' );
		$domain  = sanitize_text_field( $opts['mailgun_domain'] ?? '' );
		$region  = sanitize_key( $opts['mailgun_region'] ?? 'us' );

		if ( ! $api_key || ! $domain ) { return false; }

		$base_url = 'eu' === $region
			? "https://api.eu.mailgun.net/v3/{$domain}/messages"
			: "https://api.mailgun.net/v3/{$domain}/messages";

		$from_email = sanitize_email( (string) ( $options['from_email'] ?? $opts['from_email'] ?? get_option( 'admin_email' ) ) );
		$from_name  = sanitize_text_field( (string) ( $options['from_name'] ?? $opts['from_name'] ?? get_bloginfo( 'name' ) ) );
		$reply_to   = sanitize_email( (string) ( $options['reply_to'] ?? '' ) );

		$body = array(
			'from'    => "{$from_name} <{$from_email}>",
			'to'      => $to,
			'subject' => $subject,
			'html'    => $body_html,
			'text'    => $body_text,
			'v:atora_queue_id' => (string) ( $options['atora_queue_id'] ?? '' ),
		);
		if ( $reply_to && is_email( $reply_to ) ) {
			$body['h:Reply-To'] = $reply_to;
		}

		$response = wp_remote_post( $base_url, array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( 'api:' . $api_key ), // phpcs:ignore
			),
			'body' => $body,
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) { return false; }
		$code = wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	public static function test_connection(): bool {
		$opts    = get_option( 'atora_email_engine_options', array() );
		$api_key = $opts['mailgun_api_key'] ?? '';
		$domain  = $opts['mailgun_domain'] ?? '';
		if ( ! $api_key || ! $domain ) { return false; }

		$r = wp_remote_get( "https://api.mailgun.net/v3/domains/{$domain}", array(
			'headers' => array( 'Authorization' => 'Basic ' . base64_encode( 'api:' . $api_key ) ), // phpcs:ignore
			'timeout' => 10,
		) );
		return ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200;
	}
}
