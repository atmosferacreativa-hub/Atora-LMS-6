<?php
/**
 * ATORA LMS v5 — WhatsApp Business API (Meta)
 *
 * @package ATORA_LMS\Messaging
 * @since   5.0.0
 */

namespace ATORA\Messaging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WhatsApp
 *
 * @since 5.0.0
 */
class WhatsApp {

	const API_BASE = 'https://graph.facebook.com/v18.0/';

	/**
	 * Inicializa los hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Webhook entrante de Meta.
		add_action( 'rest_api_init', array( __CLASS__, 'register_webhook_route' ) );
	}

	/**
	 * Envía un template de WhatsApp Business.
	 *
	 * @param string $phone        Número en formato internacional (+52…).
	 * @param string $template_key Nombre del template aprobado en Meta.
	 * @param array  $variables    Parámetros del template ({{1}}, {{2}}…).
	 * @return bool
	 */
	public static function send_template( string $phone, string $template_key, array $variables = array() ): bool {
		$opts  = self::get_options();
		$token = $opts['access_token'] ?? '';
		$phone_id = $opts['phone_number_id'] ?? '';

		if ( ! $token || ! $phone_id ) {
			return false;
		}

		// Limpiar número: solo dígitos con prefijo +.
		$phone = '+' . preg_replace( '/[^0-9]/', '', $phone );

		// Construir componentes del template.
		$components = array();
		if ( ! empty( $variables ) ) {
			$params = array_map( static function ( $v ) {
				return array( 'type' => 'text', 'text' => (string) $v );
			}, array_values( $variables ) );

			$components[] = array( 'type' => 'body', 'parameters' => $params );
		}

		$body = wp_json_encode( array(
			'messaging_product' => 'whatsapp',
			'to'                => $phone,
			'type'              => 'template',
			'template'          => array(
				'name'       => sanitize_key( $template_key ),
				'language'   => array( 'code' => $opts['language'] ?? 'es' ),
				'components' => $components,
			),
		) );

		$response = wp_remote_post(
			self::API_BASE . $phone_id . '/messages',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => $body,
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return ! empty( $data['messages'][0]['id'] );
	}

	/**
	 * Registra el endpoint de webhook para mensajes entrantes de Meta.
	 *
	 * @return void
	 */
	public static function register_webhook_route(): void {
		// __return_true es necesario: Meta no puede usar cookie/cap.
		// GET: verifica hub_verify_token. POST: verifica x-hub-signature-256 dentro del handler.
		register_rest_route( 'atora/v1', '/webhooks/whatsapp', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_webhook_verify' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_webhook_event' ),
				'permission_callback' => '__return_true',
			),
		) );
	}

	/**
	 * Verifica la firma x-hub-signature-256 de Meta.
	 * Meta firma cada POST con HMAC-SHA256 del app_secret.
	 *
	 * @param string $body       Cuerpo crudo del request.
	 * @param string $signature  Valor del header x-hub-signature-256.
	 * @return bool
	 */
	private static function verify_meta_signature( string $body, string $signature ): bool {
		$opts       = self::get_options();
		$app_secret = sanitize_text_field( $opts['app_secret'] ?? '' );

		if ( ! $app_secret ) {
			return defined( 'ATORA_DEV_MODE' ) && ATORA_DEV_MODE;
		}

		if ( ! str_starts_with( $signature, 'sha256=' ) ) {
			return false;
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $body, $app_secret );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Verifica el webhook de Meta (challenge).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_webhook_verify( \WP_REST_Request $r ): \WP_REST_Response {
		$opts     = self::get_options();
		$mode     = sanitize_text_field( (string) ( $r->get_param( 'hub_mode' ) ?: $r->get_param( 'hub.mode' ) ) );
		$token    = sanitize_text_field( (string) ( $r->get_param( 'hub_verify_token' ) ?: $r->get_param( 'hub.verify_token' ) ) );
		$challenge = (string) ( $r->get_param( 'hub_challenge' ) ?: $r->get_param( 'hub.challenge' ) );
		$expected = sanitize_text_field( (string) ( $opts['webhook_verify_token'] ?? '' ) );

		// Si no hay token configurado, solo permitir verificación en dev mode.
		if ( '' === $expected && ! ( defined( 'ATORA_DEV_MODE' ) && ATORA_DEV_MODE ) ) {
			return new \WP_REST_Response( 'Forbidden', 403 );
		}

		if ( 'subscribe' === $mode && '' !== $expected && hash_equals( $expected, $token ) ) {
			return new \WP_REST_Response( $challenge, 200 );
		}

		if ( 'subscribe' === $mode && '' === $expected && defined( 'ATORA_DEV_MODE' ) && ATORA_DEV_MODE ) {
			return new \WP_REST_Response( $challenge, 200 );
		}

		return new \WP_REST_Response( 'Forbidden', 403 );
	}

	/**
	 * Procesa un evento entrante de Meta (mensaje recibido, estado entregado…).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_webhook_event( \WP_REST_Request $r ): \WP_REST_Response {
		$signature = (string) $r->get_header( 'x-hub-signature-256' );
		if ( ! self::verify_meta_signature( $r->get_body(), $signature ) ) {
			return new \WP_REST_Response( array( 'status' => 'invalid_signature' ), 403 );
		}

		$body = $r->get_json_params();

		foreach ( $body['entry'] ?? array() as $entry ) {
			foreach ( $entry['changes'] ?? array() as $change ) {
				$value    = $change['value'] ?? array();
				$messages = $value['messages'] ?? array();
				$statuses = $value['statuses'] ?? array();

				foreach ( $messages as $msg ) {
					do_action( 'atora/whatsapp/message_received', $msg, $value );
				}

				foreach ( $statuses as $status ) {
					global $wpdb;

					$msg_id = sanitize_text_field( $status['id'] ?? '' );
					$state  = sanitize_key( $status['status'] ?? '' );

					if ( $msg_id && in_array( $state, array( 'sent', 'delivered', 'read' ), true ) ) {
						$wpdb->update(
							"{$wpdb->prefix}atora_message_queue",
							array( 'status' => $state, $state . '_at' => current_time( 'mysql', true ) ),
							array( 'provider_message_id' => $msg_id ),
							null,
							array( '%s' )
						);
					}
				}
			}
		}

		return rest_ensure_response( array( 'status' => 'ok' ) );
	}

	/**
	 * Devuelve las opciones de configuración de WhatsApp.
	 *
	 * @return array
	 */
	public static function get_options(): array {
		return (array) get_option( 'atora_whatsapp_options', array() );
	}
}
