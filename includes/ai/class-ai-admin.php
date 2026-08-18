<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla legacy de IA.
 *
 * Mantiene compatibilidad de rutas/slugs antiguos:
 * - page=clms-ai-settings
 * - grupo de settings clms_ai_settings_group
 *
 * La configuración real es propiedad de CLMS_Settings.
 */
class CLMS_AI_Admin {

	// Legacy alias. El owner canónico de option key vive en CLMS_Settings.
	const OPTION_KEY     = 'clms_ai_settings';
	const SETTINGS_GROUP = 'clms_ai_settings_group';
	const PAGE_SLUG      = 'clms-ai-settings';

	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_legacy_setting_group' ) );
		add_action( 'admin_menu', array( $this, 'register_legacy_page' ), 20 );
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_page' ), 25 );
	}

	/**
	 * Mantiene el grupo legacy de options.php, delegando sanitización al owner.
	 *
	 * @return void
	 */
	public function register_legacy_setting_group() {
		register_setting(
			self::SETTINGS_GROUP,
			$this->get_ai_option_key(),
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_legacy_settings' ),
				'default'           => class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_ai_defaults' )
					? CLMS_Settings::get_ai_defaults()
					: array(),
			)
		);
	}

	/**
	 * Sanitizador legacy: usa el owner único (CLMS_Settings).
	 *
	 * @param mixed $input Input recibido.
	 * @return array
	 */
	public function sanitize_legacy_settings( $input ) {
		$current = get_option( $this->get_ai_option_key(), array() );
		$current = is_array( $current ) ? $current : array();

		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'prepare_ai_settings_for_storage' ) ) {
			return CLMS_Settings::prepare_ai_settings_for_storage( $input, $current );
		}

		return is_array( $input ) ? $input : array();
	}

	/**
	 * Option key AI canónica.
	 *
	 * @return string
	 */
	private function get_ai_option_key() {
		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_ai_option_key' ) ) {
			return (string) CLMS_Settings::get_ai_option_key();
		}

		return self::OPTION_KEY;
	}

	/**
	 * Registra la página legacy para mantener resolución de slug.
	 *
	 * @return void
	 */
	public function register_legacy_page() {
		// Página oculta del sidebar: accesible por URL directa desde Ajustes → APIs.
		add_submenu_page(
			'',
			__( 'Ajustes IA', 'atora-lms' ),
			__( 'IA', 'atora-lms' ),
			'clms_access_admin',
			self::PAGE_SLUG,
			array( $this, 'render_legacy_wrapper' )
		);
	}

	/**
	 * Redirección temprana de ruta legacy.
	 *
	 * @return void
	 */
	public function maybe_redirect_legacy_page() {
		if ( ! is_admin() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		if ( class_exists( 'CLMS_Access' ) && method_exists( 'CLMS_Access', 'can_access_admin' ) ) {
			if ( ! CLMS_Access::can_access_admin() ) {
				return;
			}
		}

		wp_safe_redirect( $this->get_redirect_url(), 302 );
		exit;
	}

	/**
	 * Fallback de render si no se ejecutó redirección temprana.
	 *
	 * @return void
	 */
	public function render_legacy_wrapper() {
		if ( class_exists( 'CLMS_Access' ) && method_exists( 'CLMS_Access', 'can_access_admin' ) ) {
			if ( ! CLMS_Access::can_access_admin() ) {
				wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
			}
		}

		wp_safe_redirect( $this->get_redirect_url(), 302 );
		exit;
	}

	/**
	 * URL canónica del panel APIs e IA.
	 *
	 * @return string
	 */
	private function get_redirect_url() {
		$page_slug = class_exists( 'CLMS_Settings' ) && defined( 'CLMS_Settings::PAGE_SLUG' )
			? CLMS_Settings::PAGE_SLUG
			: 'clms-settings';

		return add_query_arg(
			array(
				'page' => $page_slug,
				'tab'  => 'apis',
			),
			admin_url( 'admin.php' )
		);
	}
}
