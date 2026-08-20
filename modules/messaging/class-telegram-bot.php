<?php
/**
 * ATORA LMS v5 — Telegram Bot API
 *
 * @package ATORA_LMS\Messaging
 * @since   5.0.0
 */

namespace ATORA\Messaging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Telegram_Bot
 *
 * @since 5.0.0
 */
class Telegram_Bot {

	const API_BASE = 'https://api.telegram.org/bot';

	/**
	 * Inicializa los hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init',                   array( __CLASS__, 'register_webhook_route' ) );
		add_action( 'wp_ajax_atora_telegram_link',     array( __CLASS__, 'ajax_link_account' ) );
	}

	/**
	 * Envía un mensaje a un usuario de Telegram vinculado.
	 *
	 * @param int    $user_id ID del usuario de WordPress.
	 * @param string $message Texto del mensaje (Markdown simple soportado).
	 * @return bool
	 */
	public static function send_message( int $user_id, string $message ): bool {
		$chat_id = get_user_meta( $user_id, 'atora_telegram_chat_id', true );

		if ( ! $chat_id ) {
			return false;
		}

		return self::api_send_message( (string) $chat_id, $message );
	}

	/**
	 * Envía un mensaje a un chat_id directamente.
	 *
	 * @param string $chat_id ID del chat de Telegram.
	 * @param string $text    Texto del mensaje.
	 * @param array  $extra   Parámetros adicionales de la API.
	 * @return bool
	 */
	public static function api_send_message( string $chat_id, string $text, array $extra = array() ): bool {
		$token = self::get_token();
		if ( ! $token ) { return false; }

		$body = array_merge( array(
			'chat_id'    => $chat_id,
			'text'       => $text,
			'parse_mode' => 'HTML',
		), $extra );

		$response = wp_remote_post(
			self::API_BASE . $token . '/sendMessage',
			array(
				'body'    => $body,
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) { return false; }

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return ! empty( $data['ok'] );
	}

	// ── Webhook ───────────────────────────────────────────────────────────────

	/** @return void */
	public static function register_webhook_route(): void {
		// __return_true es necesario: Telegram no puede autenticarse con cookie/cap.
		// La verificación real se hace dentro del handler via X-Telegram-Bot-Api-Secret-Token.
		register_rest_route( 'atora/v1', '/telegram/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Verifica el token secreto del webhook de Telegram.
	 * Telegram envía X-Telegram-Bot-Api-Secret-Token si fue configurado al registrar el webhook.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return bool
	 */
	private static function verify_telegram_secret( \WP_REST_Request $r ): bool {
		$opts           = (array) get_option( 'atora_telegram_options', array() );
		$webhook_secret = sanitize_text_field( $opts['webhook_secret'] ?? '' );

		// Si no hay secreto configurado y estamos en modo dev, permitir.
		if ( ! $webhook_secret ) {
			return defined( 'ATORA_DEV_MODE' ) && ATORA_DEV_MODE;
		}

		$provided = $r->get_header( 'x-telegram-bot-api-secret-token' );
		return hash_equals( $webhook_secret, (string) $provided );
	}

	/**
	 * Procesa actualizaciones entrantes de Telegram.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_webhook( \WP_REST_Request $r ): \WP_REST_Response {
		if ( ! self::verify_telegram_secret( $r ) ) {
			return new \WP_REST_Response( array( 'ok' => false ), 403 );
		}

		$body    = $r->get_json_params();
		$message = $body['message'] ?? $body['callback_query']['message'] ?? null;

		if ( ! $message ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$chat_id = (string) ( $message['chat']['id'] ?? '' );
		$text    = sanitize_text_field( $message['text'] ?? '' );

		if ( ! $chat_id ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		self::handle_command( $chat_id, $text );

		do_action( 'atora/telegram/message_received', $chat_id, $text, $message );

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Procesa los comandos del bot.
	 *
	 * @param string $chat_id Chat ID de Telegram.
	 * @param string $text    Texto del mensaje.
	 * @return void
	 */
	private static function handle_command( string $chat_id, string $text ): void {
		$command = strtok( $text, ' ' );

		switch ( $command ) {
			case '/start':
				// Generar código de vinculación.
				$code = self::generate_link_code( $chat_id );
				self::api_send_message(
					$chat_id,
					sprintf(
						"👋 ¡Hola! Soy el bot de %s.\n\nPara vincular tu cuenta, ingresa este código en tu perfil:\n\n<code>%s</code>",
						get_bloginfo( 'name' ),
						$code
					)
				);
				break;

			case '/cursos':
				$user_id = self::get_user_by_chat( $chat_id );
				if ( ! $user_id ) {
					self::api_send_message( $chat_id, '❌ Debes vincular tu cuenta primero. Usa /start' );
					break;
				}
				$courses = class_exists( 'CLMS_Helper' ) ? (array) ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $user_id ) ) : array();
				if ( empty( $courses ) ) {
					self::api_send_message( $chat_id, 'No estás inscrito en ningún curso aún.' );
				} else {
					$list = array_map( static fn( $id ) => '• ' . get_the_title( $id ), $courses );
					self::api_send_message( $chat_id, "📚 Tus cursos:\n" . implode( "\n", $list ) );
				}
				break;

			case '/progreso':
				self::api_send_message( $chat_id, '📊 Visita tu dashboard en: ' . home_url( '/dashboard/' ) );
				break;

			case '/ayuda':
				self::api_send_message( $chat_id,
					"/start — Vincular cuenta\n/cursos — Ver mis cursos\n/progreso — Ver mi progreso\n/ayuda — Este menú"
				);
				break;
		}
	}

	// ── Vinculación de cuenta ─────────────────────────────────────────────────

	/** PT-3.2 (6.5.4): tope de intentos fallidos de vinculación — mismo patrón que Preferences::MAX_CODE_ATTEMPTS (6.5.1). */
	const MAX_LINK_ATTEMPTS = 5;
	const LINK_ATTEMPTS_META = 'atora_telegram_link_attempts';
	const LINK_ATTEMPTS_WINDOW_META = 'atora_telegram_link_attempts_window_start';
	const LINK_ATTEMPTS_LOCKOUT_MINUTES = 15;

	/**
	 * PT-3.1 (6.5.4): antes era substr(md5($chat_id . wp_salt() .
	 * time()), 0, 6) — 6 hex chars (~24 bits), derivado de entrada
	 * parcialmente predecible (time() es adivinable dentro de una
	 * ventana razonable). random_bytes(5) da 10 hex chars (40 bits),
	 * sin derivar de nada predecible.
	 *
	 * @param string $chat_id Chat ID de Telegram.
	 * @return string Código de 10 caracteres hex.
	 */
	public static function generate_link_code( string $chat_id ): string {
		$code = strtoupper( bin2hex( random_bytes( 5 ) ) );
		set_transient( 'atora_tg_link_' . $code, $chat_id, 10 * MINUTE_IN_SECONDS );
		return $code;
	}

	/**
	 * AJAX: vincula la cuenta de WordPress con un chat_id de Telegram.
	 *
	 * @return void
	 */
	public static function ajax_link_account(): void {
		check_ajax_referer( 'atora_telegram_link' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Código inválido o expirado.', 'atora-lms' ) ) );
		}

		// PT-3.2 (6.5.4): sin esto, el código de 10 caracteres hex
		// (40 bits) igual sería fuerza-bruteable dentro de su ventana
		// de 10 minutos con suficientes intentos sin límite.
		if ( self::link_attempts_locked( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Demasiados intentos. Espera unos minutos y vuelve a intentarlo con un código nuevo.', 'atora-lms' ) ) );
		}

		$code    = strtoupper( sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) );
		$chat_id = get_transient( 'atora_tg_link_' . $code );

		if ( ! $chat_id ) {
			self::register_link_attempt_failure( $user_id );
			wp_send_json_error( array( 'message' => __( 'Código inválido o expirado.', 'atora-lms' ) ) );
		}

		self::reset_link_attempts( $user_id );
		update_user_meta( $user_id, 'atora_telegram_chat_id', $chat_id );
		delete_transient( 'atora_tg_link_' . $code );

		self::api_send_message(
			$chat_id,
			sprintf(
				'✅ ¡Cuenta vinculada! Bienvenido/a, <b>%s</b>.',
				get_userdata( $user_id )->display_name
			)
		);

		wp_send_json_success( array( 'message' => __( 'Cuenta vinculada con Telegram.', 'atora-lms' ) ) );
	}

	/**
	 * PT-3.2 (6.5.4): true si el usuario agotó sus intentos dentro de
	 * la ventana de lockout vigente. Se evaluó extraer un helper
	 * compartido con Preferences::verify_phone_code() (6.5.1) — el
	 * caso de WhatsApp cuenta intentos contra un usuario objetivo ya
	 * conocido de antemano; acá el "objetivo" (a qué chat_id vincula
	 * el código) solo se resuelve al intentar, así que el contador
	 * queda atado al usuario de WP que está probando códigos, no a un
	 * código puntual — semántica distinta que complicaba la extracción
	 * sin tocar el flujo de WhatsApp ya probado. Implementación
	 * paralela a propósito; duplicación anotada en
	 * docs/DEUDA-TECNICA.md.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	private static function link_attempts_locked( int $user_id ): bool {
		$window_start = absint( get_user_meta( $user_id, self::LINK_ATTEMPTS_WINDOW_META, true ) );
		if ( ! $window_start ) {
			return false;
		}
		if ( ( time() - $window_start ) >= self::LINK_ATTEMPTS_LOCKOUT_MINUTES * MINUTE_IN_SECONDS ) {
			self::reset_link_attempts( $user_id );
			return false;
		}
		return absint( get_user_meta( $user_id, self::LINK_ATTEMPTS_META, true ) ) >= self::MAX_LINK_ATTEMPTS;
	}

	/**
	 * @param int $user_id
	 * @return void
	 */
	private static function register_link_attempt_failure( int $user_id ): void {
		$window_start = absint( get_user_meta( $user_id, self::LINK_ATTEMPTS_WINDOW_META, true ) );
		if ( ! $window_start ) {
			update_user_meta( $user_id, self::LINK_ATTEMPTS_WINDOW_META, time() );
			update_user_meta( $user_id, self::LINK_ATTEMPTS_META, 1 );
			return;
		}
		update_user_meta( $user_id, self::LINK_ATTEMPTS_META, absint( get_user_meta( $user_id, self::LINK_ATTEMPTS_META, true ) ) + 1 );
	}

	/**
	 * @param int $user_id
	 * @return void
	 */
	private static function reset_link_attempts( int $user_id ): void {
		delete_user_meta( $user_id, self::LINK_ATTEMPTS_META );
		delete_user_meta( $user_id, self::LINK_ATTEMPTS_WINDOW_META );
	}

	/**
	 * Obtiene el user_id de WordPress desde un chat_id de Telegram.
	 *
	 * @param string $chat_id Chat ID de Telegram.
	 * @return int|null
	 */
	private static function get_user_by_chat( string $chat_id ): ?int {
		global $wpdb;

		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'atora_telegram_chat_id' AND meta_value = %s LIMIT 1",
			$chat_id
		) );

		return $id ? absint( $id ) : null;
	}

	/**
	 * Obtiene el token del bot de Telegram.
	 *
	 * @return string
	 */
	private static function get_token(): string {
		$opts = (array) get_option( 'atora_telegram_options', array() );
		return sanitize_text_field( $opts['bot_token'] ?? '' );
	}
}
