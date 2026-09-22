<?php
/**
 * ATORA LMS v5 — Webhooks salientes
 *
 * Sistema de webhooks configurables para notificar sistemas externos
 * (Slack, Zapier, Make, APIs propias) cuando ocurren eventos en ATORA.
 *
 * @package ATORA_LMS\Automation
 * @since   5.0.0
 */

namespace ATORA\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Outbound_Webhooks
 *
 * @since 5.0.0
 */
class Outbound_Webhooks {

	/**
	 * Inicializa los hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Admin.
		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu',             array( __CLASS__, 'register_admin_menu' ) );
			add_action( 'wp_ajax_atora_webhook_save',       array( __CLASS__, 'ajax_save' ) );
			add_action( 'wp_ajax_atora_webhook_delete',     array( __CLASS__, 'ajax_delete' ) );
			add_action( 'wp_ajax_atora_webhook_test',       array( __CLASS__, 'ajax_test' ) );
		}

		// Registrar hooks para todos los triggers configurados.
		self::register_hooks();
	}

	/**
	 * Registra los hooks según los webhooks activos guardados.
	 *
	 * @return void
	 */
	private static function register_hooks(): void {
		$webhooks = self::get_active_webhooks();

		$triggers_registered = array();

		foreach ( $webhooks as $wh ) {
			$trigger = sanitize_key( $wh->trigger ?? '' );
			if ( ! $trigger || in_array( $trigger, $triggers_registered, true ) ) {
				continue;
			}

			$wp_hook = self::trigger_to_wp_hook( $trigger );
			if ( $wp_hook ) {
				add_action( $wp_hook, static function () use ( $trigger ) {
					$args = func_get_args();
					Outbound_Webhooks::fire( $trigger, $args );
				}, 30, PHP_INT_MAX );

				$triggers_registered[] = $trigger;
			}
		}
	}

	/**
	 * Dispara todos los webhooks configurados para un trigger.
	 *
	 * @param string $trigger Nombre del trigger.
	 * @param array  $args    Argumentos del hook de WordPress.
	 * @return void
	 */
	public static function fire( string $trigger, array $args ): void {
		$webhooks = self::get_webhooks_for_trigger( $trigger );

		foreach ( $webhooks as $wh ) {
			$payload = self::build_payload(
				$wh->payload_template ?? '{}',
				$trigger,
				$args
			);

			self::dispatch(
				$wh->url,
				$wh->method ?? 'POST',
				$payload,
				(int) $wh->id
			);
		}
	}

	/**
	 * Envía un webhook a una URL externa.
	 *
	 * @param string $url       URL destino.
	 * @param string $method    HTTP method (POST|GET|PUT).
	 * @param string $payload   Body JSON.
	 * @param int    $webhook_id ID del webhook (para logging).
	 * @return bool
	 */
	public static function dispatch( string $url, string $method, string $payload, int $webhook_id = 0 ): bool {
		$url    = esc_url_raw( $url );
		$method = strtoupper( sanitize_key( $method ) );

		if ( ! $url ) { return false; }

		$response = wp_remote_request( $url, array(
			'method'  => $method,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => $payload,
			'timeout' => 10,
		) );

		$success = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 400;

		if ( $webhook_id ) {
			self::log_delivery( $webhook_id, $success, $response );
		}

		return $success;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Construye el payload del webhook interpolando variables.
	 *
	 * @param string $template Template JSON con {{variables}}.
	 * @param string $trigger  Trigger.
	 * @param array  $args     Args del hook WP.
	 * @return string
	 */
	private static function build_payload( string $template, string $trigger, array $args ): string {
		$user_id   = is_int( $args[0] ?? null ) ? $args[0] : get_current_user_id();
		$user      = get_userdata( $user_id );

		$vars = array(
			'user_id'          => $user_id,
			'user_name'        => $user ? $user->display_name : '',
			'user_email'       => $user ? $user->user_email : '',
			'event_timestamp'  => gmdate( 'c' ),
			'site_url'         => home_url( '/' ),
		);

		// Añadir args específicos del trigger.
		foreach ( $args as $i => $arg ) {
			if ( is_scalar( $arg ) ) {
				$vars[ 'arg_' . $i ] = $arg;
			}
		}

		return preg_replace_callback( '/\{\{([\w.]+)\}\}/', static function ( $m ) use ( $vars ) {
			return addslashes( (string) ( $vars[ $m[1] ] ?? '' ) );
		}, $template );
	}

	/**
	 * Registra el resultado de un envío.
	 *
	 * @param int                         $webhook_id ID del webhook.
	 * @param bool                        $success    Si fue exitoso.
	 * @param \WP_Error|array             $response   Respuesta HTTP.
	 * @return void
	 */
	private static function log_delivery( int $webhook_id, bool $success, $response ): void {
		$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );

		$deliveries   = get_option( 'atora_webhook_deliveries_' . $webhook_id, array() );
		$deliveries[] = array(
			'status'    => $success ? 'ok' : 'error',
			'http_code' => $code,
			'at'        => current_time( 'mysql' ),
		);

		// Conservar solo los últimos 50 envíos.
		if ( count( $deliveries ) > 50 ) {
			$deliveries = array_slice( $deliveries, -50 );
		}

		update_option( 'atora_webhook_deliveries_' . $webhook_id, $deliveries, false );
	}

	/**
	 * Devuelve todos los webhooks activos.
	 *
	 * @return array
	 */
	private static function get_active_webhooks(): array {
		return (array) get_option( 'atora_outbound_webhooks', array() );
	}

	/**
	 * Devuelve los webhooks configurados para un trigger.
	 *
	 * @param string $trigger Nombre del trigger.
	 * @return array
	 */
	private static function get_webhooks_for_trigger( string $trigger ): array {
		return array_values( array_filter( self::get_active_webhooks(), static function ( $wh ) use ( $trigger ) {
			return ( $wh->trigger ?? '' ) === $trigger && ! empty( $wh->active );
		} ) );
	}

	/**
	 * Mapea nombres de triggers ATORA a hooks de WordPress.
	 *
	 * @param string $trigger Trigger ATORA.
	 * @return string
	 */
	private static function trigger_to_wp_hook( string $trigger ): string {
		$map = array(
			'course_completed' => 'clms_course_completed',
			'lesson_completed' => 'clms_lesson_completed',
			'course_enrolled'  => 'clms_user_enrolled_in_course',
			'user_registered'  => 'user_register',
			'purchase_completed' => 'woocommerce_order_status_completed',
		);

		return $map[ $trigger ] ?? '';
	}

	// ── Admin ─────────────────────────────────────────────────────────────────

	/** @return void */
		public static function register_admin_menu(): void {
			add_submenu_page(
				'clms-dashboard', // PT-4.4.3: acceso desde hub "Crecimiento" (atora-growth-hub); se oculta del sidebar en cleanup_atora_submenus().
				__( 'Webhooks', 'atora-lms' ),
				__( 'Webhooks', 'atora-lms' ),
				'manage_options',
				'atora-webhooks',
			static function () {
				$view = ATORA_LMS_MODULES_DIR . 'automation/views/webhooks.php';
				if ( file_exists( $view ) ) { require $view; }
			}
		);
	}

	/** @return void */
	public static function ajax_save(): void {
		check_ajax_referer( 'atora_webhook_admin' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

		$webhooks = self::get_active_webhooks();
		$id       = absint( wp_unslash( $_POST['id'] ?? 0 ) ) ?: wp_rand( 1000, 9999 );

		$entry = (object) array(
			'id'               => $id,
			'name'             => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'url'              => esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) ),
			'trigger'          => sanitize_key( wp_unslash( $_POST['trigger'] ?? '' ) ),
			'method'           => sanitize_key( wp_unslash( $_POST['method'] ?? 'POST' ) ),
			'payload_template' => wp_kses_post( wp_unslash( $_POST['payload'] ?? '{}' ) ),
			'active'           => ! empty( $_POST['active'] ) ? 1 : 0,
		);

		// Reemplazar si ya existe.
		$found = false;
		foreach ( $webhooks as &$wh ) {
			if ( (int) $wh->id === $id ) {
				$wh    = $entry;
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			$webhooks[] = $entry;
		}

		update_option( 'atora_outbound_webhooks', $webhooks );
		wp_send_json_success( array( 'id' => $id ) );
	}

	/** @return void */
	public static function ajax_delete(): void {
		check_ajax_referer( 'atora_webhook_admin' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

		$id       = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$webhooks = array_filter( self::get_active_webhooks(), static fn( $wh ) => (int) $wh->id !== $id );
		update_option( 'atora_outbound_webhooks', array_values( $webhooks ) );
		wp_send_json_success();
	}

	/** @return void */
	public static function ajax_test(): void {
		check_ajax_referer( 'atora_webhook_admin' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

		$url     = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		$payload = wp_json_encode( array( 'test' => true, 'source' => 'ATORA LMS', 'timestamp' => gmdate( 'c' ) ) );
		$success = self::dispatch( $url, 'POST', $payload );

		$success
			? wp_send_json_success( array( 'message' => __( 'Webhook enviado correctamente.', 'atora-lms' ) ) )
			: wp_send_json_error( array( 'message' => __( 'Falló el envío. Verifica la URL.', 'atora-lms' ) ) );
	}
}
