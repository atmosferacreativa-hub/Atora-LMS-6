<?php
/**
 * Seguridad de los webhooks de proveedores de correo (6.38.0, Orden C · Comunicación).
 *
 * - Firma por proveedor: SendGrid (ECDSA), Mailgun (HMAC), Postmark (Basic Auth: Postmark no firma),
 *   Amazon SES (firma SNS con certificado de amazonaws.com), Brevo (token en cabecera o en la URL).
 * - Protección contra repetición: marca de tiempo dentro de una ventana (SendGrid, Mailgun, SNS) y cada evento
 *   se procesa una sola vez (tabla `atora_email_webhook_events`, clave única por proveedor y evento).
 * - Clasificación de rebotes: solo un rebote permanente (o una queja) suprime la dirección.
 *
 * @package ATORA_LMS
 * @since 6.38.0
 */

namespace ATORA\EmailEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Email_Webhook_Security {

	const WINDOW_SECONDS = 300;
	const KEEP_DAYS      = 14;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_email_webhook_events';
	}

	/** ¿Marca de tiempo (segundos) dentro de la ventana? */
	public static function fresh( $timestamp, ?int $now = null, int $window = self::WINDOW_SECONDS ): bool {
		if ( ! is_numeric( $timestamp ) ) {
			$parsed = is_string( $timestamp ) ? strtotime( $timestamp ) : false;
			if ( false === $parsed ) {
				return false;
			}
			$timestamp = $parsed;
		}
		$now = $now ?? time();
		return abs( $now - (int) $timestamp ) <= $window;
	}

	/**
	 * Registra un evento; false si ya se había procesado (repetición).
	 * Sin tabla (instalación a medias) no bloquea: devuelve true.
	 */
	public static function remember_once( string $provider, string $event_key ): bool {
		global $wpdb;
		$event_key = substr( $event_key, 0, 190 );
		if ( '' === $event_key ) {
			return true;
		}
		$inserted = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'INSERT IGNORE INTO ' . self::table() . ' (provider, event_key, received_at) VALUES (%s, %s, %s)',
			$provider,
			$event_key,
			current_time( 'mysql', true )
		) );
		if ( false === $inserted ) {
			return true;
		}
		return 1 === (int) $inserted;
	}

	public static function cleanup(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE received_at < %s', gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	}

	/** Clave única de un evento para no procesarlo dos veces. */
	public static function event_key( string $provider, array $raw ): string {
		switch ( $provider ) {
			case 'sendgrid':
				$id = (string) ( $raw['sg_event_id'] ?? '' );
				break;
			case 'mailgun':
				$id = (string) ( $raw['event-data']['id'] ?? '' );
				break;
			case 'postmark':
				$id = (string) ( $raw['MessageID'] ?? '' ) . ':' . (string) ( $raw['RecordType'] ?? '' ) . ':' . (string) ( $raw['ReceivedAt'] ?? $raw['DeliveredAt'] ?? $raw['BouncedAt'] ?? '' );
				break;
			case 'ses':
				$id = (string) ( $raw['MessageId'] ?? '' );
				break;
			case 'brevo':
				$id = (string) ( $raw['message-id'] ?? '' ) . ':' . (string) ( $raw['event'] ?? '' ) . ':' . (string) ( $raw['ts_event'] ?? $raw['ts'] ?? '' );
				break;
			default:
				$id = '';
		}
		return '' !== trim( $id, ':' ) ? $id : hash( 'sha256', wp_json_encode( $raw ) );
	}

	/** Postmark: credenciales HTTP Basic configuradas en la URL del webhook. */
	public static function verify_basic_auth( \WP_REST_Request $request, string $user, string $pass ): bool {
		if ( '' === $user || '' === $pass ) {
			return false;
		}
		$header = (string) $request->get_header( 'authorization' );
		if ( 0 !== stripos( $header, 'basic ' ) ) {
			return false;
		}
		$decoded = base64_decode( substr( $header, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return false;
		}
		list( $given_user, $given_pass ) = explode( ':', $decoded, 2 );
		return hash_equals( $user, $given_user ) && hash_equals( $pass, $given_pass );
	}

	/** Brevo: token compartido en la cabecera `X-Atora-Webhook-Token` (o `?token=` por compatibilidad). */
	public static function verify_token( \WP_REST_Request $request, string $expected ): bool {
		if ( '' === $expected ) {
			return false;
		}
		$given = (string) $request->get_header( 'x-atora-webhook-token' );
		if ( '' === $given ) {
			$given = (string) $request->get_param( 'token' );
		}
		return '' !== $given && hash_equals( $expected, $given );
	}

	/**
	 * Amazon SES vía SNS: firma del mensaje con el certificado publicado por AWS.
	 *
	 * @param array<string,mixed> $message Cuerpo SNS.
	 * @param callable|null       $fetch   (url) => PEM; para pruebas.
	 */
	public static function verify_sns( array $message, string $expected_topic = '', ?callable $fetch = null ): bool {
		$type = (string) ( $message['Type'] ?? '' );
		if ( ! in_array( $type, array( 'Notification', 'SubscriptionConfirmation', 'UnsubscribeConfirmation' ), true ) ) {
			return false;
		}
		if ( '' !== $expected_topic && ! hash_equals( $expected_topic, (string) ( $message['TopicArn'] ?? '' ) ) ) {
			return false;
		}
		if ( ! self::fresh( (string) ( $message['Timestamp'] ?? '' ), null, 3600 ) ) {
			return false;
		}
		$cert_url = (string) ( $message['SigningCertURL'] ?? '' );
		if ( ! self::is_aws_sns_url( $cert_url ) ) {
			return false;
		}
		$signature = base64_decode( (string) ( $message['Signature'] ?? '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $signature || ! function_exists( 'openssl_verify' ) ) {
			return false;
		}
		$fields = 'Notification' === $type
			? array( 'Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type' )
			: array( 'Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type' );
		$string = '';
		foreach ( $fields as $field ) {
			if ( 'Subject' === $field && ! isset( $message['Subject'] ) ) {
				continue;
			}
			$string .= $field . "\n" . (string) ( $message[ $field ] ?? '' ) . "\n";
		}
		$pem = $fetch ? (string) $fetch( $cert_url ) : self::fetch_cert( $cert_url );
		if ( '' === $pem ) {
			return false;
		}
		$algo = '2' === (string) ( $message['SignatureVersion'] ?? '1' ) ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
		return 1 === openssl_verify( $string, $signature, $pem, $algo );
	}

	public static function is_aws_sns_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& 1 === preg_match( '/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', strtolower( (string) ( $parts['host'] ?? '' ) ) );
	}

	private static function fetch_cert( string $url ): string {
		$cache_key = 'atora_sns_cert_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$response = wp_remote_get( $url, array( 'timeout' => 10, 'redirection' => 0 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$pem = (string) wp_remote_retrieve_body( $response );
		if ( false === strpos( $pem, 'BEGIN CERTIFICATE' ) ) {
			return '';
		}
		set_transient( $cache_key, $pem, DAY_IN_SECONDS );
		return $pem;
	}

	/**
	 * ¿Rebote permanente? Un rebote temporal (buzón lleno, servidor caído) no suprime la dirección.
	 *
	 * @param array<string,mixed> $raw
	 */
	public static function is_hard_bounce( string $provider, array $raw ): bool {
		switch ( $provider ) {
			case 'sendgrid':
				// SendGrid: `bounce` es permanente; `deferred`/`dropped` no llegan como bounce.
				return 'bounce' === ( $raw['event'] ?? '' ) && 'blocked' !== ( $raw['type'] ?? '' );
			case 'mailgun':
				return 'permanent' === ( $raw['event-data']['severity'] ?? '' );
			case 'postmark':
				return in_array( (string) ( $raw['Type'] ?? '' ), array( 'HardBounce', 'BadEmailAddress', 'ManuallyDeactivated' ), true );
			case 'ses':
				$msg = is_array( $raw['_sns_message'] ?? null ) ? $raw['_sns_message'] : array();
				return 'Permanent' === ( $msg['bounce']['bounceType'] ?? '' );
			case 'brevo':
				return in_array( (string) ( $raw['event'] ?? '' ), array( 'hard_bounce', 'invalid_email', 'blocked' ), true );
		}
		return false;
	}
}
