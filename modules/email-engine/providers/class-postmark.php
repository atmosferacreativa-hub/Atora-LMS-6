<?php
/**
 * ATORA LMS v5 — Provider Postmark
 *
 * @package ATORA_LMS\EmailEngine\Providers
 * @since   5.0.0
 */

namespace ATORA\EmailEngine\Providers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Postmark implements Provider_Interface {

	const API_ENDPOINT = 'https://api.postmarkapp.com/email';

	public static function get_name(): string { return 'Postmark'; }

	public static function send( string $to, string $subject, string $body_html, string $body_text, array $options = array() ): bool {
		$opts    = get_option( 'atora_email_engine_options', array() );
		$api_key = sanitize_text_field( $opts['postmark_api_key'] ?? '' );
		if ( ! $api_key ) { return false; }

		$from_email = sanitize_email( (string) ( $options['from_email'] ?? $opts['from_email'] ?? get_option( 'admin_email' ) ) );
		$from_name  = sanitize_text_field( (string) ( $options['from_name'] ?? $opts['from_name'] ?? get_bloginfo( 'name' ) ) );
		$reply_to   = sanitize_email( (string) ( $options['reply_to'] ?? '' ) );

		$payload = array(
			'From'     => "{$from_name} <{$from_email}>",
			'To'       => $to,
			'Subject'  => $subject,
			'HtmlBody' => $body_html,
			'TextBody' => $body_text,
			'TrackOpens'  => true,
			'TrackLinks'  => 'HtmlAndText',
			'Metadata'    => array( 'atora_queue_id' => (string) ( $options['atora_queue_id'] ?? '' ) ),
		);
		if ( $reply_to && is_email( $reply_to ) ) {
			$payload['ReplyTo'] = $reply_to;
		}

		$body = wp_json_encode( $payload );

		$response = wp_remote_post( self::API_ENDPOINT, array(
			'headers' => array(
				'X-Postmark-Server-Token' => $api_key,
				'Content-Type'            => 'application/json',
				'Accept'                  => 'application/json',
			),
			'body'    => $body,
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) { return false; }
		$code = wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	public static function test_connection(): bool {
		$opts    = get_option( 'atora_email_engine_options', array() );
		$api_key = $opts['postmark_api_key'] ?? '';
		if ( ! $api_key ) { return false; }
		$r = wp_remote_get( 'https://api.postmarkapp.com/server', array(
			'headers' => array( 'X-Postmark-Server-Token' => $api_key ),
			'timeout' => 10,
		) );
		return ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200;
	}
}
