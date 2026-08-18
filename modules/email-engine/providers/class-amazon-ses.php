<?php
/**
 * ATORA LMS v5 — Provider Amazon SES
 *
 * Usa la API REST SES v2 directamente (sin SDK de AWS).
 *
 * @package ATORA_LMS\EmailEngine\Providers
 * @since   5.0.0
 */

namespace ATORA\EmailEngine\Providers;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Amazon_SES implements Provider_Interface {

	public static function get_name(): string { return 'Amazon SES'; }

	public static function send( string $to, string $subject, string $body_html, string $body_text, array $options = array() ): bool {
		$opts       = get_option( 'atora_email_engine_options', array() );
		$access_key = sanitize_text_field( $opts['ses_access_key'] ?? '' );
		$secret_key = sanitize_text_field( $opts['ses_secret_key'] ?? '' );
		$region     = sanitize_text_field( $opts['ses_region'] ?? 'us-east-1' );

		if ( ! $access_key || ! $secret_key ) { return false; }

		$from_email = sanitize_email( (string) ( $options['from_email'] ?? $opts['from_email'] ?? get_option( 'admin_email' ) ) );
		$from_name  = sanitize_text_field( (string) ( $options['from_name'] ?? $opts['from_name'] ?? get_bloginfo( 'name' ) ) );
		$reply_to   = sanitize_email( (string) ( $options['reply_to'] ?? '' ) );

		$endpoint = "https://email.{$region}.amazonaws.com/v2/email/outbound-emails";

		$payload_data = array(
			'FromEmailAddress' => "{$from_name} <{$from_email}>",
			'Destination'      => array( 'ToAddresses' => array( $to ) ),
			'Content'          => array(
				'Simple' => array(
					'Subject' => array( 'Data' => $subject, 'Charset' => 'UTF-8' ),
					'Body'    => array(
						'Text' => array( 'Data' => $body_text, 'Charset' => 'UTF-8' ),
						'Html' => array( 'Data' => $body_html, 'Charset' => 'UTF-8' ),
					),
				),
			),
		);
		if ( $reply_to && is_email( $reply_to ) ) {
			$payload_data['ReplyToAddresses'] = array( $reply_to );
		}

		$payload = wp_json_encode( $payload_data );

		// Firma AWS Signature v4.
		$headers = self::sign_request( $payload, $endpoint, $region, $access_key, $secret_key );

		$response = wp_remote_post( $endpoint, array(
			'headers' => $headers,
			'body'    => $payload,
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) { return false; }
		$code = wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	public static function test_connection(): bool {
		$opts = get_option( 'atora_email_engine_options', array() );
		return ! empty( $opts['ses_access_key'] ) && ! empty( $opts['ses_secret_key'] );
	}

	/**
	 * Genera los headers de autenticación AWS Signature v4.
	 *
	 * @param string $body       Body del request.
	 * @param string $endpoint   URL del endpoint.
	 * @param string $region     Región AWS.
	 * @param string $access_key Access key.
	 * @param string $secret_key Secret key.
	 * @return array
	 */
	private static function sign_request( string $body, string $endpoint, string $region, string $access_key, string $secret_key ): array {
		$service   = 'ses';
		$now       = gmdate( 'Ymd\THis\Z' );
		$date      = gmdate( 'Ymd' );
		$host      = parse_url( $endpoint, PHP_URL_HOST );
		$path      = parse_url( $endpoint, PHP_URL_PATH );

		$canonical_headers = "content-type:application/json\nhost:{$host}\nx-amz-date:{$now}\n";
		$signed_headers    = 'content-type;host;x-amz-date';
		$payload_hash      = hash( 'sha256', $body );

		$canonical_request = implode( "\n", array(
			'POST', $path, '',
			$canonical_headers, $signed_headers, $payload_hash,
		) );

		$scope     = "{$date}/{$region}/{$service}/aws4_request";
		$string_to_sign = implode( "\n", array(
			'AWS4-HMAC-SHA256', $now, $scope, hash( 'sha256', $canonical_request ),
		) );

		$signing_key = hash_hmac( 'sha256', 'aws4_request',
			hash_hmac( 'sha256', $service,
				hash_hmac( 'sha256', $region,
					hash_hmac( 'sha256', $date, 'AWS4' . $secret_key, true ),
					true ),
				true ),
			true );

		$signature = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		return array(
			'Content-Type'  => 'application/json',
			'X-Amz-Date'    => $now,
			'Authorization' => "AWS4-HMAC-SHA256 Credential={$access_key}/{$scope}, SignedHeaders={$signed_headers}, Signature={$signature}",
		);
	}
}
