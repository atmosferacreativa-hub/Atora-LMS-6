<?php
/**
 * ATORA LMS v5 — Gestor de 2FA
 *
 * Coordina la autenticación de dos factores: TOTP, email, SMS y WhatsApp.
 * Gestiona el flujo de login, códigos de respaldo y dispositivos de confianza.
 *
 * @package ATORA_LMS\Security
 * @since   5.0.0
 */

namespace ATORA\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Two_FA_Manager
 *
 * @since 5.0.0
 */
class Two_FA_Manager {

	/** Session key para el usuario pendiente de 2FA. */
	const SESSION_KEY = 'atora_2fa_pending_user';

	/** Duración del token de 2FA en minutos. */
	const TOKEN_EXPIRY_MINUTES = 10;

	/** Duración de un dispositivo de confianza en días. */
	const TRUSTED_DEVICE_DAYS = 30;

	/**
	 * Registra todos los hooks del sistema 2FA.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Interceptar login si 2FA requerido.
		add_filter( 'authenticate',                   array( __CLASS__, 'intercept_login' ), 100, 3 );
		add_action( 'login_form_atora_2fa',           array( __CLASS__, 'handle_2fa_form' ) );
		add_action( 'login_message',                  array( __CLASS__, 'render_2fa_page' ) );

		// AJAX.
		add_action( 'wp_ajax_nopriv_atora_2fa_verify',  array( __CLASS__, 'ajax_verify' ) );
		add_action( 'wp_ajax_nopriv_atora_2fa_resend',  array( __CLASS__, 'ajax_resend' ) );

		// Perfil: activar/desactivar 2FA.
		add_action( 'show_user_profile',              array( __CLASS__, 'render_2fa_profile_section' ) );
		add_action( 'edit_user_profile',              array( __CLASS__, 'render_2fa_profile_section' ) );
		add_action( 'wp_ajax_atora_2fa_activate',     array( __CLASS__, 'ajax_activate' ) );
		add_action( 'wp_ajax_atora_2fa_deactivate',   array( __CLASS__, 'ajax_deactivate' ) );
		add_action( 'wp_ajax_atora_2fa_verify_totp',  array( __CLASS__, 'ajax_verify_totp_setup' ) );

		// Limpiar tokens expirados diariamente.
		add_action( 'atora_daily_cron',               array( __CLASS__, 'cleanup_expired_tokens' ) );
	}

	// ── Flujo de login ────────────────────────────────────────────────────────

	/**
	 * Intercepta el login si el usuario requiere 2FA.
	 *
	 * @param \WP_User|\WP_Error|null $user     Resultado previo de autenticación.
	 * @param string                  $username Login ingresado.
	 * @param string                  $password Contraseña ingresada.
	 * @return \WP_User|\WP_Error|null
	 */
	public static function intercept_login( $user, string $username, string $password ) {
		if ( ! $user instanceof \WP_User ) {
			return $user;
		}

		if ( ! self::user_requires_2fa( $user ) ) {
			return $user;
		}

		// Verificar si el dispositivo es de confianza.
		if ( self::is_trusted_device( $user->ID ) ) {
			return $user;
		}

		// Guardar usuario en sesión y redirigir a pantalla 2FA.
		self::set_pending_user( $user->ID );

		// Enviar código por el método por defecto del usuario.
		self::send_token( $user->ID, self::get_default_method( $user->ID ) );

		// Devolver error controlado para evitar que WP complete el login.
		return new \WP_Error(
			'atora_2fa_required',
			'',
			array( 'redirect' => self::get_2fa_url() )
		);
	}

	/**
	 * Maneja el formulario de verificación 2FA enviado por POST.
	 *
	 * @return void
	 */
	public static function handle_2fa_form(): void {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		check_admin_referer( 'atora_2fa_verify' );

		$user_id = self::get_pending_user();
		if ( ! $user_id ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		$code = sanitize_text_field( wp_unslash( $_POST['atora_2fa_code'] ?? '' ) );

		// Código de respaldo.
		if ( ! empty( $_POST['atora_2fa_backup'] ) ) {
			if ( self::verify_backup_code( $user_id, $code ) ) {
				self::complete_login( $user_id );
			} else {
				add_filter( 'login_message', static fn() => '<p class="message error">' . esc_html__( 'Código de respaldo incorrecto.', 'atora-lms' ) . '</p>' );
			}
			return;
		}

		// Código normal.
		if ( self::verify_token( $user_id, $code ) ) {
			// ¿Confiar en este dispositivo?
			if ( ! empty( $_POST['atora_2fa_trust_device'] ) ) {
				self::register_trusted_device( $user_id );
			}
			self::complete_login( $user_id );
		} else {
			add_filter( 'login_message', static fn() => '<p class="message error">' . esc_html__( 'Código incorrecto o expirado. Inténtalo de nuevo.', 'atora-lms' ) . '</p>' );
		}
	}

	/**
	 * Renderiza la pantalla de verificación 2FA.
	 *
	 * @param string $message Mensaje previo (ignorado).
	 * @return string
	 */
	public static function render_2fa_page( string $message ): string {
		if ( ! isset( $_GET['action'] ) || 'atora_2fa' !== sanitize_key( $_GET['action'] ) ) {
			return $message;
		}

		$view = ATORA_LMS_MODULES_DIR . 'security/views/2fa-form.php';
		if ( file_exists( $view ) ) {
			ob_start();
			require $view;
			return ob_get_clean();
		}

		return $message;
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────

	/**
	 * Verifica un código 2FA via AJAX.
	 *
	 * @return void
	 */
	public static function ajax_verify(): void {
		check_ajax_referer( 'atora_2fa_verify' );

		// Rate limit: máx 10 intentos por IP en 5 minutos.
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		if ( class_exists( 'ATORA_Security' ) && ! ATORA_Security::rate_limit( '2fa_verify_' . md5( $ip ), 10, 300 ) ) {
			wp_send_json_error( array( 'message' => __( 'Demasiados intentos. Espera unos minutos.', 'atora-lms' ) ), 429 );
		}

		$user_id = self::get_pending_user();
		$code    = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Sesión expirada.', 'atora-lms' ) ) );
		}

		if ( self::verify_token( $user_id, $code ) ) {
			if ( ! empty( $_POST['trust_device'] ) ) {
				self::register_trusted_device( $user_id );
			}
			self::complete_login( $user_id );
			wp_send_json_success( array( 'redirect' => admin_url() ) );
		}

		wp_send_json_error( array( 'message' => __( 'Código incorrecto o expirado.', 'atora-lms' ) ) );
	}

	/**
	 * Reenvía el código 2FA via AJAX.
	 *
	 * @return void
	 */
	public static function ajax_resend(): void {
		check_ajax_referer( 'atora_2fa_resend' );

		// Rate limit: máx 5 reenvíos por IP en 10 minutos.
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		if ( class_exists( 'ATORA_Security' ) && ! ATORA_Security::rate_limit( '2fa_resend_' . md5( $ip ), 5, 600 ) ) {
			wp_send_json_error( array( 'message' => __( 'Demasiados reenvíos. Espera unos minutos.', 'atora-lms' ) ), 429 );
		}

		$user_id = self::get_pending_user();
		$method  = sanitize_key( wp_unslash( $_POST['method'] ?? '' ) );

		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Sesión expirada.', 'atora-lms' ) ) );
		}

		self::send_token( $user_id, $method ?: self::get_default_method( $user_id ) );
		wp_send_json_success( array( 'message' => __( 'Código reenviado.', 'atora-lms' ) ) );
	}

	/**
	 * Activa 2FA para un usuario (AJAX).
	 *
	 * @return void
	 */
	public static function ajax_activate(): void {
		check_ajax_referer( 'atora_2fa_activate' );

		$user_id = get_current_user_id();
		$method  = sanitize_key( wp_unslash( $_POST['method'] ?? 'email' ) );

		if ( ! $user_id ) {
			wp_send_json_error();
		}

		update_user_meta( $user_id, 'atora_2fa_enabled', 1 );
		update_user_meta( $user_id, 'atora_2fa_method', $method );

		// Generar códigos de respaldo si no existen.
		if ( ! get_user_meta( $user_id, 'atora_2fa_backup_codes', true ) ) {
			self::generate_backup_codes( $user_id );
		}

		/**
		 * Fires when a user activates 2FA.
		 *
		 * @param int    $user_id ID del usuario.
		 * @param string $method  Método elegido.
		 */
		do_action( 'atora/security/2fa_activated', $user_id, $method );

		wp_send_json_success( array(
			'message'      => __( '2FA activado correctamente.', 'atora-lms' ),
			'backup_codes' => self::get_backup_codes_plain( $user_id ),
		) );
	}

	/**
	 * Desactiva 2FA para un usuario (AJAX).
	 *
	 * @return void
	 */
	public static function ajax_deactivate(): void {
		check_ajax_referer( 'atora_2fa_deactivate' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error();
		}

		delete_user_meta( $user_id, 'atora_2fa_enabled' );
		delete_user_meta( $user_id, 'atora_2fa_method' );
		delete_user_meta( $user_id, 'atora_2fa_totp_secret' );
		delete_user_meta( $user_id, 'atora_2fa_backup_codes' );

		do_action( 'atora/security/2fa_deactivated', $user_id );

		wp_send_json_success( array( 'message' => __( '2FA desactivado.', 'atora-lms' ) ) );
	}

	/**
	 * Verifica el TOTP durante la configuración (antes de activar definitivamente).
	 *
	 * @return void
	 */
	public static function ajax_verify_totp_setup(): void {
		check_ajax_referer( 'atora_2fa_verify_totp_setup' );

		$user_id = get_current_user_id();
		$code    = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );
		$secret  = sanitize_text_field( wp_unslash( $_POST['secret'] ?? '' ) );

		if ( ! $user_id || ! $code || ! $secret ) {
			wp_send_json_error();
		}

		if ( class_exists( 'ATORA\Security\Two_FA_TOTP' ) && Two_FA_TOTP::verify( $secret, $code ) ) {
			// Guardar secreto definitivamente.
			update_user_meta( $user_id, 'atora_2fa_totp_secret', $secret );
			wp_send_json_success();
		}

		wp_send_json_error( array( 'message' => __( 'Código TOTP incorrecto.', 'atora-lms' ) ) );
	}

	// ── Tokens ────────────────────────────────────────────────────────────────

	/**
	 * Genera y envía un token 2FA para el usuario por el método indicado.
	 *
	 * @param int    $user_id ID del usuario.
	 * @param string $method  Método de envío: email|sms|whatsapp.
	 * @return bool
	 */
	public static function send_token( int $user_id, string $method = 'email' ): bool {
		global $wpdb;

		$code       = self::generate_numeric_code();
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( self::TOKEN_EXPIRY_MINUTES * MINUTE_IN_SECONDS ) );

		// Invalidar tokens anteriores del mismo usuario/método.
		$wpdb->update(
			"{$wpdb->prefix}atora_2fa_tokens",
			array( 'expires_at' => current_time( 'mysql', true ) ),
			array( 'user_id' => $user_id, 'method' => $method ),
			array( '%s' ),
			array( '%d', '%s' )
		);

		// Insertar nuevo token.
		$wpdb->insert(
			"{$wpdb->prefix}atora_2fa_tokens",
			array(
				'user_id'    => $user_id,
				'token'      => $code,
				'method'     => $method,
				'expires_at' => $expires_at,
				'verified'   => 0,
			),
			array( '%d', '%s', '%s', '%s', '%d' )
		);

		return self::dispatch_token( $user_id, $code, $method );
	}

	/**
	 * Verifica un código enviado por el usuario.
	 *
	 * @param int    $user_id ID del usuario.
	 * @param string $code    Código ingresado.
	 * @return bool
	 */
	public static function verify_token( int $user_id, string $code ): bool {
		global $wpdb;

		$code = sanitize_text_field( $code );

		// Para TOTP, usar el provider directamente.
		if ( class_exists( 'ATORA\Security\Two_FA_TOTP' ) ) {
			$secret = get_user_meta( $user_id, 'atora_2fa_totp_secret', true );
			if ( $secret && Two_FA_TOTP::verify( $secret, $code ) ) {
				return true;
			}
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}atora_2fa_tokens
				 WHERE user_id = %d
				   AND token = %s
				   AND verified = 0
				   AND expires_at > %s
				 LIMIT 1",
				$user_id,
				$code,
				current_time( 'mysql', true )
			)
		);

		if ( ! $row ) {
			return false;
		}

		// Marcar como usado.
		$wpdb->update(
			"{$wpdb->prefix}atora_2fa_tokens",
			array( 'verified' => 1 ),
			array( 'id' => $row->id ),
			array( '%d' ),
			array( '%d' )
		);

		return true;
	}

	// ── Dispositivos de confianza ─────────────────────────────────────────────

	/**
	 * Registra el dispositivo actual como de confianza.
	 *
	 * @param int $user_id ID del usuario.
	 * @return void
	 */
	private static function register_trusted_device( int $user_id ): void {
		global $wpdb;

		$hash       = self::get_device_hash();
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( self::TRUSTED_DEVICE_DAYS * DAY_IN_SECONDS ) );

		$wpdb->replace(
			"{$wpdb->prefix}atora_trusted_devices",
			array(
				'user_id'     => $user_id,
				'device_hash' => $hash,
				'device_label' => self::get_device_label(),
				'ip_address'  => Extended_Registration::get_client_ip(),
				'user_agent'  => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
				'expires_at'  => $expires_at,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		// Cookie de confianza en el cliente.
		setcookie(
			'atora_td_' . $user_id,
			$hash,
			time() + ( self::TRUSTED_DEVICE_DAYS * DAY_IN_SECONDS ),
			COOKIEPATH,
			COOKIE_DOMAIN,
			is_ssl(),
			true
		);
	}

	/**
	 * Verifica si el dispositivo actual es de confianza para el usuario.
	 *
	 * @param int $user_id ID del usuario.
	 * @return bool
	 */
	private static function is_trusted_device( int $user_id ): bool {
		global $wpdb;

		$cookie_key = 'atora_td_' . $user_id;
		$hash       = isset( $_COOKIE[ $cookie_key ] ) ? sanitize_text_field( $_COOKIE[ $cookie_key ] ) : '';

		if ( ! $hash ) {
			return false;
		}

		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}atora_trusted_devices
				 WHERE user_id = %d
				   AND device_hash = %s
				   AND expires_at > %s
				 LIMIT 1",
				$user_id,
				$hash,
				current_time( 'mysql', true )
			)
		);

		return (bool) $row;
	}

	// ── Códigos de respaldo ───────────────────────────────────────────────────

	/**
	 * Genera 10 códigos de respaldo aleatorios para el usuario.
	 *
	 * @param int $user_id ID del usuario.
	 * @return void
	 */
	public static function generate_backup_codes( int $user_id ): void {
		$codes      = array();
		$plain      = array();

		for ( $i = 0; $i < 10; $i++ ) {
			$code     = strtoupper( bin2hex( random_bytes( 5 ) ) ); // 10 caracteres hex.
			$plain[]  = $code;
			$codes[]  = wp_hash_password( $code );
		}

		update_user_meta( $user_id, 'atora_2fa_backup_codes', $codes );

		// Los códigos en claro se devuelven al usuario una sola vez y luego se borran.
		update_user_meta( $user_id, 'atora_2fa_backup_codes_plain_once', $plain );
	}

	/**
	 * Devuelve los códigos de respaldo en claro (solo disponibles la primera vez).
	 *
	 * @param int $user_id ID del usuario.
	 * @return array
	 */
	public static function get_backup_codes_plain( int $user_id ): array {
		$codes = get_user_meta( $user_id, 'atora_2fa_backup_codes_plain_once', true );
		$codes = is_array( $codes ) ? $codes : array();
		delete_user_meta( $user_id, 'atora_2fa_backup_codes_plain_once' );
		return $codes;
	}

	/**
	 * Verifica un código de respaldo y lo consume (uso único).
	 *
	 * @param int    $user_id ID del usuario.
	 * @param string $code    Código ingresado.
	 * @return bool
	 */
	private static function verify_backup_code( int $user_id, string $code ): bool {
		$code   = strtoupper( sanitize_text_field( $code ) );
		$hashed = (array) get_user_meta( $user_id, 'atora_2fa_backup_codes', true );

		foreach ( $hashed as $index => $hash ) {
			if ( wp_check_password( $code, $hash ) ) {
				unset( $hashed[ $index ] );
				update_user_meta( $user_id, 'atora_2fa_backup_codes', array_values( $hashed ) );
				return true;
			}
		}

		return false;
	}

	// ── Renderizado de perfil ─────────────────────────────────────────────────

	/**
	 * Sección 2FA en el perfil del usuario.
	 *
	 * @param \WP_User $user Usuario.
	 * @return void
	 */
	public static function render_2fa_profile_section( \WP_User $user ): void {
		$view = ATORA_LMS_MODULES_DIR . 'security/views/2fa-profile.php';
		if ( file_exists( $view ) ) {
			include $view;
		}
	}

	// ── Mantenimiento ─────────────────────────────────────────────────────────

	/**
	 * Limpia tokens y dispositivos expirados de la BD.
	 *
	 * @return void
	 */
	public static function cleanup_expired_tokens(): void {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}atora_2fa_tokens WHERE expires_at < %s",
				$now
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}atora_trusted_devices WHERE expires_at < %s",
				$now
			)
		);
	}

	// ── Helpers privados ──────────────────────────────────────────────────────

	/**
	 * Determina si un usuario requiere 2FA según la política activa.
	 *
	 * @param \WP_User $user Usuario.
	 * @return bool
	 */
	private static function user_requires_2fa( \WP_User $user ): bool {
		$opts   = Security::get_options();
		$policy = $opts['twofa_policy'] ?? 'optional';

		if ( 'disabled' === $policy ) {
			return false;
		}

		if ( 'everyone' === $policy ) {
			return true;
		}

		if ( 'admins_only' === $policy ) {
			return user_can( $user, 'manage_options' );
		}

		// 'optional': solo si el usuario lo ha activado explícitamente.
		return (bool) get_user_meta( $user->ID, 'atora_2fa_enabled', true );
	}

	/**
	 * Obtiene el método 2FA por defecto del usuario.
	 *
	 * @param int $user_id ID del usuario.
	 * @return string
	 */
	private static function get_default_method( int $user_id ): string {
		$method = get_user_meta( $user_id, 'atora_2fa_method', true );
		return $method ?: 'email';
	}

	/**
	 * Envía el token por el canal apropiado.
	 *
	 * @param int    $user_id ID del usuario.
	 * @param string $code    Código.
	 * @param string $method  Canal: email|sms|whatsapp.
	 * @return bool
	 */
	private static function dispatch_token( int $user_id, string $code, string $method ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		switch ( $method ) {
			case 'email':
				return Two_FA_Email::send( $user, $code );

			case 'sms':
				// SMS via Twilio — se implementa en Sprint 11-12.
				return apply_filters( 'atora/security/2fa_send_sms', false, $user, $code );

			case 'whatsapp':
				// WhatsApp via Meta — se implementa en Sprint 11-12.
				return apply_filters( 'atora/security/2fa_send_whatsapp', false, $user, $code );

			default:
				return Two_FA_Email::send( $user, $code );
		}
	}

	/**
	 * Genera un código numérico de 6 dígitos criptográficamente seguro.
	 *
	 * @return string
	 */
	private static function generate_numeric_code(): string {
		return str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Genera un hash del dispositivo actual (IP + User-Agent).
	 *
	 * @return string
	 */
	private static function get_device_hash(): string {
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		$ip = Extended_Registration::get_client_ip();
		return hash( 'sha256', $ip . '|' . $ua . '|' . wp_salt() );
	}

	/**
	 * Genera una etiqueta legible para el dispositivo.
	 *
	 * @return string
	 */
	private static function get_device_label(): string {
		$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
		if ( str_contains( $ua, 'Mobile' ) ) {
			return __( 'Dispositivo móvil', 'atora-lms' );
		}
		return __( 'Navegador de escritorio', 'atora-lms' );
	}

	/**
	 * Guarda en sesión el ID del usuario pendiente de 2FA.
	 *
	 * @param int $user_id ID del usuario.
	 * @return void
	 */
	private static function set_pending_user( int $user_id ): void {
		if ( ! session_id() && ! headers_sent() ) {
			session_start();
		}
		$_SESSION[ self::SESSION_KEY ] = $user_id;
	}

	/**
	 * Recupera el ID del usuario pendiente de 2FA desde la sesión.
	 *
	 * @return int|null
	 */
	private static function get_pending_user(): ?int {
		if ( ! session_id() && ! headers_sent() ) {
			session_start();
		}
		$user_id = $_SESSION[ self::SESSION_KEY ] ?? null;
		return $user_id ? absint( $user_id ) : null;
	}

	/**
	 * Completa el login del usuario pendiente y destruye la sesión 2FA.
	 *
	 * @param int $user_id ID del usuario.
	 * @return void
	 */
	private static function complete_login( int $user_id ): void {
		if ( session_id() ) {
			unset( $_SESSION[ self::SESSION_KEY ] );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		wp_clear_auth_cookie();
		wp_set_auth_cookie( $user_id, false );
		wp_set_current_user( $user_id );

		do_action( 'wp_login', $user->user_login, $user );

		$redirect = apply_filters( 'login_redirect', admin_url(), admin_url(), $user );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * URL del formulario de verificación 2FA.
	 *
	 * @return string
	 */
	private static function get_2fa_url(): string {
		return add_query_arg( 'action', 'atora_2fa', wp_login_url() );
	}
}
