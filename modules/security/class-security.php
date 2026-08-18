<?php
/**
 * ATORA LMS v5 — Security Module
 *
 * Orquesta: registro extendido, 2FA, captcha y settings de seguridad.
 *
 * @package ATORA_LMS\Security
 * @since   5.0.0
 */

namespace ATORA\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Security
 *
 * @since 5.0.0
 */
class Security {

	/**
	 * Inicializa todos los sub-módulos de seguridad.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Registro extendido.
		if ( class_exists( 'ATORA\Security\Extended_Registration' ) ) {
			Extended_Registration::init();
		}

		// Captcha.
		if ( class_exists( 'ATORA\Security\Captcha' ) ) {
			Captcha::init();
		}

		// 2FA.
		if ( class_exists( 'ATORA\Security\Two_FA_Manager' ) ) {
			Two_FA_Manager::init();
		}

		// Admin settings page.
		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_settings_page' ) );
			add_action( 'admin_init',           array( __CLASS__, 'register_settings_fields' ) );
		}
	}

	/**
	 * Registra la página de ajustes de seguridad en el menú de ATORA.
	 *
	 * @return void
	 */
	public static function register_settings_page(): void {
		add_submenu_page(
			'', // PT-4.4.3: reubicado bajo el hub "Ajustes" (clms-settings-hub).
			__( 'Seguridad', 'atora-lms' ),
			__( 'Seguridad', 'atora-lms' ),
			'manage_options',
			'atora-security',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Registra secciones y campos de ajustes.
	 *
	 * @return void
	 */
	public static function register_settings_fields(): void {
		register_setting( 'atora_security_settings', 'atora_security_options', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_options' ),
		) );

		// ── Sección 2FA ───────────────────────────────────────────────────────
		add_settings_section(
			'atora_security_2fa',
			__( '2FA — Autenticación de dos factores', 'atora-lms' ),
			'__return_false',
			'atora-security'
		);

		add_settings_field(
			'twofa_policy',
			__( 'Política 2FA', 'atora-lms' ),
			array( __CLASS__, 'field_twofa_policy' ),
			'atora-security',
			'atora_security_2fa'
		);

		add_settings_field(
			'twofa_methods',
			__( 'Métodos permitidos', 'atora-lms' ),
			array( __CLASS__, 'field_twofa_methods' ),
			'atora-security',
			'atora_security_2fa'
		);

		// ── Sección Captcha ───────────────────────────────────────────────────
		add_settings_section(
			'atora_security_captcha',
			__( 'Captcha inteligente', 'atora-lms' ),
			'__return_false',
			'atora-security'
		);

		add_settings_field(
			'captcha_provider',
			__( 'Proveedor', 'atora-lms' ),
			array( __CLASS__, 'field_captcha_provider' ),
			'atora-security',
			'atora_security_captcha'
		);

		add_settings_field(
			'captcha_site_key',
			__( 'Site Key', 'atora-lms' ),
			array( __CLASS__, 'field_captcha_site_key' ),
			'atora-security',
			'atora_security_captcha'
		);

		add_settings_field(
			'captcha_secret_key',
			__( 'Secret Key', 'atora-lms' ),
			array( __CLASS__, 'field_captcha_secret_key' ),
			'atora-security',
			'atora_security_captcha'
		);
	}

	/**
	 * Renderiza la página de ajustes de seguridad.
	 *
	 * @return void
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}

		$view = ATORA_LMS_MODULES_DIR . 'security/views/settings.php';
		if ( file_exists( $view ) ) {
			require $view;
		}
	}

	/**
	 * Sanitiza las opciones antes de guardar.
	 *
	 * @param mixed $input Datos enviados por el formulario.
	 * @return array
	 */
	public static function sanitize_options( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = array();

		$allowed_policies = array( 'disabled', 'optional', 'admins_only', 'everyone' );
		$clean['twofa_policy'] = in_array( $input['twofa_policy'] ?? '', $allowed_policies, true )
			? $input['twofa_policy']
			: 'optional';

		$allowed_methods = array( 'totp', 'email', 'sms', 'whatsapp' );
		$methods = isset( $input['twofa_methods'] ) && is_array( $input['twofa_methods'] )
			? $input['twofa_methods']
			: array( 'email' );
		$clean['twofa_methods'] = array_values( array_intersect( $methods, $allowed_methods ) );

		$allowed_providers = array( 'hcaptcha', 'turnstile', '' );
		$clean['captcha_provider'] = in_array( $input['captcha_provider'] ?? '', $allowed_providers, true )
			? $input['captcha_provider']
			: '';

		$clean['captcha_site_key']   = sanitize_text_field( $input['captcha_site_key'] ?? '' );
		$clean['captcha_secret_key'] = sanitize_text_field( $input['captcha_secret_key'] ?? '' );

		return $clean;
	}

	// ── Callbacks de campos ───────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function field_twofa_policy(): void {
		$opts    = get_option( 'atora_security_options', array() );
		$current = $opts['twofa_policy'] ?? 'optional';
		$choices = array(
			'disabled'    => __( 'Desactivado', 'atora-lms' ),
			'optional'    => __( 'Opcional (recomendado)', 'atora-lms' ),
			'admins_only' => __( 'Obligatorio solo admins', 'atora-lms' ),
			'everyone'    => __( 'Obligatorio para todos', 'atora-lms' ),
		);
		echo '<select name="atora_security_options[twofa_policy]">';
		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * @return void
	 */
	public static function field_twofa_methods(): void {
		$opts    = get_option( 'atora_security_options', array() );
		$current = $opts['twofa_methods'] ?? array( 'email' );
		$methods = array(
			'totp'      => __( 'TOTP (Google Authenticator, Authy)', 'atora-lms' ),
			'email'     => __( 'Email (código 6 dígitos)', 'atora-lms' ),
			'sms'       => __( 'SMS (Twilio — requiere configuración)', 'atora-lms' ),
			'whatsapp'  => __( 'WhatsApp (requiere módulo Messaging)', 'atora-lms' ),
		);
		foreach ( $methods as $value => $label ) {
			printf(
				'<label><input type="checkbox" name="atora_security_options[twofa_methods][]" value="%s"%s> %s</label><br>',
				esc_attr( $value ),
				checked( in_array( $value, $current, true ), true, false ),
				esc_html( $label )
			);
		}
	}

	/**
	 * @return void
	 */
	public static function field_captcha_provider(): void {
		$opts     = get_option( 'atora_security_options', array() );
		$current  = $opts['captcha_provider'] ?? '';
		$choices  = array(
			''          => __( 'Desactivado', 'atora-lms' ),
			'hcaptcha'  => 'hCaptcha',
			'turnstile' => 'Cloudflare Turnstile',
		);
		echo '<select name="atora_security_options[captcha_provider]">';
		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * @return void
	 */
	public static function field_captcha_site_key(): void {
		$opts = get_option( 'atora_security_options', array() );
		printf(
			'<input type="text" class="regular-text" name="atora_security_options[captcha_site_key]" value="%s">',
			esc_attr( $opts['captcha_site_key'] ?? '' )
		);
	}

	/**
	 * @return void
	 */
	public static function field_captcha_secret_key(): void {
		$opts = get_option( 'atora_security_options', array() );
		printf(
			'<input type="password" class="regular-text" name="atora_security_options[captcha_secret_key]" value="%s">',
			esc_attr( $opts['captcha_secret_key'] ?? '' )
		);
	}

	// ── Helpers públicos ──────────────────────────────────────────────────────

	/**
	 * Devuelve las opciones actuales del módulo.
	 *
	 * @return array
	 */
	public static function get_options(): array {
		$defaults = array(
			'twofa_policy'      => 'optional',
			'twofa_methods'     => array( 'email' ),
			'captcha_provider'  => '',
			'captcha_site_key'  => '',
			'captcha_secret_key' => '',
		);
		return wp_parse_args( get_option( 'atora_security_options', array() ), $defaults );
	}
}
