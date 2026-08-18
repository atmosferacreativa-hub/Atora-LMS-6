<?php
/**
 * Webhook Dispatcher — Fase IV S13
 *
 * Despacha eventos a endpoints externos firmados con HMAC-SHA256.
 * Registro: POST /atora-lms/v1/webhooks (requiere manage_options).
 *
 * @package ATORA_LMS
 * @since   5.30.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ATORA_Webhook_Dispatcher {

	const TABLE = 'atora_webhooks';

	/**
	 * Despacha un evento a todos los webhooks activos registrados para ese evento.
	 *
	 * @param string $event   Nombre del evento, p.ej. "order.completed".
	 * @param array  $payload Datos del evento.
	 */
	public static function dispatch( string $event, array $payload ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		$like  = $wpdb->esc_like( $table );
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) !== $table ) {
			return; // Tabla no existe aún
		}

		$hooks = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, url, secret FROM {$table}
				 WHERE event_type = %s AND is_active = 1",
				sanitize_key( $event )
			),
			ARRAY_A
		);

		if ( empty( $hooks ) ) { return; }

		$body      = wp_json_encode( array( 'event' => $event, 'data' => $payload, 'timestamp' => time() ) );
		$body_str  = $body ?: '{}';

		foreach ( $hooks as $hook ) {
			$url    = esc_url_raw( (string) ( $hook['url']    ?? '' ) );
			$secret = sanitize_text_field( (string) ( $hook['secret'] ?? '' ) );

			if ( '' === $url ) { continue; }

			$signature = hash_hmac( 'sha256', $body_str, $secret );

			wp_remote_post( $url, array(
				'timeout'     => 8,
				'blocking'    => false,
				'headers'     => array(
					'Content-Type'         => 'application/json',
					'X-ATORA-Signature'    => 'sha256=' . $signature,
					'X-ATORA-Event'        => $event,
					'User-Agent'           => 'ATORA-LMS-Webhook/1.0',
				),
				'body'        => $body_str,
			) );
		}
	}

	/**
	 * Registra un webhook. Devuelve ID o 0 si falla.
	 */
	public static function register( string $event_type, string $url, string $secret = '' ): int {
		global $wpdb;
		if ( '' === $secret ) {
			$secret = wp_generate_password( 32, false );
		}
		$ok = $wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'event_type' => sanitize_key( $event_type ),
				'url'        => esc_url_raw( $url ),
				'secret'     => sanitize_text_field( $secret ),
				'is_active'  => 1,
			),
			array( '%s', '%s', '%s', '%d' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Inicializa los hooks de WP que disparan webhooks (Fase IV S13).
	 */
	public static function init(): void {
		// order.completed — WooCommerce
		add_action( 'woocommerce_order_status_completed', function( $order_id ) {
			$order   = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_id ) ) : null;
			$payload = $order ? array(
				'order_id'    => absint( $order_id ),
				'total'       => (float) $order->get_total(),
				'currency'    => sanitize_key( $order->get_currency() ),
				'user_id'     => absint( $order->get_user_id() ),
				'billing_email' => sanitize_email( $order->get_billing_email() ),
			) : array( 'order_id' => absint( $order_id ) );
			self::dispatch( 'order.completed', $payload );
		}, 20, 1 );

		// enrollment.created — LMS propio
		add_action( 'atora/lms/enrolled', function( int $user_id, int $course_id, int $enroll_id ) {
			self::dispatch( 'enrollment.created', array(
				'user_id'    => $user_id,
				'course_id'  => $course_id,
				'enrollment_id' => $enroll_id,
			) );
		}, 20, 3 );

		// certificate.issued — curso completado
		add_action( 'atora/lms/course_completed', function( int $user_id, int $course_id ) {
			self::dispatch( 'certificate.issued', array(
				'user_id'   => $user_id,
				'course_id' => $course_id,
			) );
		}, 30, 2 );
	}
}
