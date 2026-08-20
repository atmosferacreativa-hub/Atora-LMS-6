<?php
/**
 * ATORA LMS v5 — Captcha inteligente
 *
 * Soporta hCaptcha y Cloudflare Turnstile.
 * Se aplica en: registro, login (tras 3 fallos), recuperar contraseña.
 *
 * @package ATORA_LMS\Security
 * @since   5.0.0
 */

namespace ATORA\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Captcha
 *
 * @since 5.0.0
 */
class Captcha {

	/** Nombre del campo oculto del captcha. */
	const FIELD_NAME = 'atora_captcha_token';

	/** Meta key que almacena el conteo de fallos de login. */
	const FAIL_COUNT_META = 'atora_login_fail_count';

	/**
	 * Registra los hooks según la configuración activa.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		// Registro.
		add_action( 'register_form',                  array( __CLASS__, 'render_widget' ) );
		add_filter( 'registration_errors',            array( __CLASS__, 'verify_on_registration' ), 20, 3 );

		// Login.
		add_action( 'login_form',                     array( __CLASS__, 'maybe_render_login_widget' ) );
		add_filter( 'authenticate',                   array( __CLASS__, 'maybe_verify_login' ), 30, 3 );
		add_action( 'wp_login_failed',                array( __CLASS__, 'increment_fail_count' ) );
		add_action( 'wp_login',                       array( __CLASS__, 'reset_fail_count' ), 10, 2 );

		// Recuperar contraseña.
		add_action( 'lostpassword_form',              array( __CLASS__, 'render_widget' ) );
		add_action( 'lostpassword_post',              array( __CLASS__, 'verify_on_lost_password' ) );

		// Encolar script del proveedor.
		add_action( 'login_enqueue_scripts',          array( __CLASS__, 'enqueue_provider_script' ) );
		add_action( 'wp_enqueue_scripts',             array( __CLASS__, 'enqueue_provider_script' ) );
	}

	// ── Renderizado ───────────────────────────────────────────────────────────

	/**
	 * Imprime el widget del captcha.
	 *
	 * @return void
	 */
	public static function render_widget(): void {
		$opts     = Security::get_options();
		$site_key = $opts['captcha_site_key'] ?? '';

		if ( ! $site_key ) {
			return;
		}

		switch ( $opts['captcha_provider'] ) {
			case 'hcaptcha':
				printf(
					'<div class="h-captcha" data-sitekey="%s" style="margin:12px 0;"></div>',
					esc_attr( $site_key )
				);
				break;

			case 'turnstile':
				printf(
					'<div class="cf-turnstile" data-sitekey="%s" style="margin:12px 0;"></div>',
					esc_attr( $site_key )
				);
				break;
		}
	}

	/**
	 * Muestra el captcha en el login solo si el usuario tiene ≥3 fallos.
	 *
	 * @return void
	 */
	public static function maybe_render_login_widget(): void {
		$login    = sanitize_user( wp_unslash( $_POST['log'] ?? '' ) );
		$user     = $login ? get_user_by( 'login', $login ) : null;
		$fail_cnt = $user ? (int) get_user_meta( $user->ID, self::FAIL_COUNT_META, true ) : 0;

		if ( $fail_cnt >= 3 ) {
			self::render_widget();
		}
	}

	// ── Verificación ──────────────────────────────────────────────────────────

	/**
	 * Verifica el captcha durante el registro.
	 *
	 * @param \WP_Error $errors               Errores.
	 * @param string    $sanitized_user_login Login.
	 * @param string    $user_email           Email.
	 * @return \WP_Error
	 */
	public static function verify_on_registration( \WP_Error $errors, string $sanitized_user_login, string $user_email ): \WP_Error {
		if ( ! self::verify_token() ) {
			$errors->add( 'atora_captcha_failed', __( 'La verificación de seguridad falló. Por favor inténtalo de nuevo.', 'atora-lms' ) );
		}
		return $errors;
	}

	/**
	 * Verifica el captcha en el login (solo si el usuario tiene ≥3 fallos).
	 *
	 * @param \WP_User|\WP_Error|null $user     Resultado de autenticación previa.
	 * @param string                  $username Usuario ingresado.
	 * @param string                  $password Contraseña ingresada.
	 * @return \WP_User|\WP_Error|null
	 */
	public static function maybe_verify_login( $user, string $username, string $password ) {
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$existing = $username ? get_user_by( 'login', $username ) : null;
		$fail_cnt = $existing ? (int) get_user_meta( $existing->ID, self::FAIL_COUNT_META, true ) : 0;

		if ( $fail_cnt >= 3 && ! self::verify_token() ) {
			return new \WP_Error(
				'atora_captcha_failed',
				__( 'La verificación de seguridad falló. Por favor inténtalo de nuevo.', 'atora-lms' )
			);
		}

		return $user;
	}

	/**
	 * Verifica el captcha en la recuperación de contraseña.
	 *
	 * @param \WP_Error $errors Errores de WP.
	 * @return void
	 */
	public static function verify_on_lost_password( \WP_Error $errors ): void {
		if ( ! self::verify_token() ) {
			$errors->add( 'atora_captcha_failed', __( 'La verificación de seguridad falló.', 'atora-lms' ) );
		}
	}

	// ── Contador de fallos ────────────────────────────────────────────────────

	/**
	 * Incrementa el contador de fallos de login para un usuario.
	 *
	 * @param string $username Login o email ingresado.
	 * @return void
	 */
	public static function increment_fail_count( string $username ): void {
		$user = get_user_by( 'login', $username ) ?: get_user_by( 'email', $username );
		if ( $user ) {
			$count = (int) get_user_meta( $user->ID, self::FAIL_COUNT_META, true );
			update_user_meta( $user->ID, self::FAIL_COUNT_META, $count + 1 );
		}
	}

	/**
	 * Resetea el contador de fallos tras un login exitoso.
	 *
	 * @param string   $user_login Login.
	 * @param \WP_User $user       Objeto usuario.
	 * @return void
	 */
	public static function reset_fail_count( string $user_login, \WP_User $user ): void {
		delete_user_meta( $user->ID, self::FAIL_COUNT_META );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Verifica el token del captcha contra la API del proveedor.
	 *
	 * @return bool
	 */
	private static function verify_token(): bool {
		$opts       = Security::get_options();
		$provider   = $opts['captcha_provider'] ?? '';
		$secret_key = $opts['captcha_secret_key'] ?? '';

		if ( ! $provider || ! $secret_key ) {
			return true; // Sin captcha configurado: siempre pasa.
		}

		$token = sanitize_text_field( wp_unslash( $_POST['h-captcha-response'] ?? $_POST['cf-turnstile-response'] ?? '' ) );

		if ( ! $token ) {
			return false;
		}

		$verify_url = 'hcaptcha' === $provider
			? 'https://hcaptcha.com/siteverify'
			: 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

		$response = wp_remote_post( $verify_url, array(
			'body'    => array(
				'secret'   => $secret_key,
				'response' => $token,
				// PT-1/PT-8 (6.5.5): Extended_Registration::get_client_ip() es
				// privado — la llamada anterior a esta clase producía un
				// fatal error ("call to private method") en cuanto había un
				// proveedor de captcha configurado. Se usa el resolutor
				// centralizado directamente en su lugar.
				'remoteip' => \ATORA_Client_IP::get(),
			),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return ! empty( $body['success'] );
	}

	/**
	 * Indica si el captcha está habilitado.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$opts = Security::get_options();
		return ! empty( $opts['captcha_provider'] ) && ! empty( $opts['captcha_site_key'] );
	}

	/**
	 * Encola el script del proveedor elegido.
	 *
	 * @return void
	 */
	public static function enqueue_provider_script(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$opts = Security::get_options();

		switch ( $opts['captcha_provider'] ) {
			case 'hcaptcha':
				wp_enqueue_script(
					'hcaptcha',
					'https://js.hcaptcha.com/1/api.js',
					array(),
					null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
					false
				);
				break;

			case 'turnstile':
				wp_enqueue_script(
					'cf-turnstile',
					'https://challenges.cloudflare.com/turnstile/v0/api.js',
					array(),
					null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
					false
				);
				break;
		}
	}
}
