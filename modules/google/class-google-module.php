<?php
/**
 * ATORA LMS — Módulo Google (P10, sprint 6.13.0)
 *
 * Identidad, Calendar/Meet (ya resuelto en P8 vía Calendar_Sync) y Drive.
 *
 * P10.2 — BYO Client ID: cada instalación crea su propio proyecto en
 * Google Cloud. La verificación de app deja de ser cuello de botella de
 * ATORA, y una universidad prefiere que la integración viva en su propio
 * proyecto. El coste es fricción de configuración — se compensa con esta
 * página mostrando el redirect URI exacto y los scopes listos para
 * copiar.
 *
 * Comparte credenciales (client_id/client_secret) con
 * ATORA\Calendar\Calendar_Sync — conectar Google una sola vez acá basta
 * para Calendar, Meet y Drive, sin volver a pedir client_id/secret.
 *
 * @package ATORA_LMS\Google
 * @since   6.13.0
 */

namespace ATORA\Google;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Google_Module {

	/** Credenciales compartidas (client_id/client_secret) del proyecto propio de la instalación. */
	const OPTION = 'atora_google_oauth';

	/** Scopes base que la instalación necesita — mostrados en la página de ajustes para copiar. */
	const SCOPES = array(
		'openid',
		'email',
		'profile',
		'https://www.googleapis.com/auth/calendar',
		'https://www.googleapis.com/auth/drive.file',
	);

	/** Scopes adicionales para Epic 6 (Google Classroom). */
	const CLASSROOM_SCOPES = array(
		'https://www.googleapis.com/auth/classroom.courses.readonly',
		'https://www.googleapis.com/auth/classroom.rosters.readonly',
		'https://www.googleapis.com/auth/classroom.coursework.students.readonly',
	);

	public static function init(): void {
		add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

		// P2 (6.12.0): gateado por módulo 'google'.
		if ( ! class_exists( '\CLMS_Module_Registry' ) || \CLMS_Module_Registry::is_active( 'google' ) ) {
			add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		}
	}

	public static function register_menu(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Google', 'atora-lms' ),
			__( '🔗 Google', 'atora-lms' ),
			'manage_options',
			'atora-google',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings(): void {
		register_setting( 'atora_google', self::OPTION, array( __CLASS__, 'sanitize_options' ) );
	}

	/**
	 * @param mixed $input
	 * @return array{client_id:string,client_secret:string,hd_domain:string,hd_auto_enroll:bool,classroom_enabled:bool}
	 */
	public static function sanitize_options( $input ): array {
		$input = is_array( $input ) ? $input : array();

		$clean = array(
			'client_id'      => sanitize_text_field( (string) ( $input['client_id'] ?? '' ) ),
			'client_secret'  => sanitize_text_field( (string) ( $input['client_secret'] ?? '' ) ),
			// P10.3: restricción por dominio para el perfil 'institucion'.
			'hd_domain'      => sanitize_text_field( (string) ( $input['hd_domain'] ?? '' ) ),
			'hd_auto_enroll' => ! empty( $input['hd_auto_enroll'] ),
			'classroom_enabled' => ! empty( $input['classroom_enabled'] ),
		);

		// Las mismas credenciales alimentan a Calendar_Sync — un solo
		// lugar para configurarlas, sin duplicar el flujo OAuth.
		if ( $clean['client_id'] && $clean['client_secret'] ) {
			update_option( 'atora_calendar_oauth_google', array(
				'client_id'     => $clean['client_id'],
				'client_secret' => $clean['client_secret'],
			) );
		}

		return $clean;
	}

	/**
	 * @return array{client_id:string,client_secret:string,hd_domain:string,hd_auto_enroll:bool,classroom_enabled:bool}
	 */
	public static function get_options(): array {
		return wp_parse_args( get_option( self::OPTION, array() ), array(
			'client_id'      => '',
			'client_secret'  => '',
			'hd_domain'      => '',
			'hd_auto_enroll' => false,
			'classroom_enabled' => false,
		) );
	}

	/**
	 * Scopes efectivos para la URL de autorización.
	 *
	 * Classroom se habilita explícitamente para evitar pedir scopes
	 * adicionales en instalaciones que solo usan Calendar/Drive/Identity.
	 *
	 * @return array<int,string>
	 */
	public static function get_scopes(): array {
		$opts = self::get_options();
		$scopes = self::SCOPES;
		if ( ! empty( $opts['classroom_enabled'] ) ) {
			$scopes = array_merge( $scopes, self::CLASSROOM_SCOPES );
		}
		$scopes = array_values( array_unique( array_filter( array_map( 'trim', (array) $scopes ) ) ) );
		return $scopes;
	}

	/**
	 * @return string Client ID público — seguro de exponer en el HTML del login (GIS lo requiere ahí).
	 */
	public static function get_client_id(): string {
		return self::get_options()['client_id'];
	}

	/**
	 * URI de redirect para el flujo de autorización (Drive/Calendar) —
	 * debe coincidir EXACTO con lo whitelisteado en Google Cloud Console.
	 * Misma construcción que Calendar_Sync::exchange_code_for_tokens().
	 *
	 * @return string
	 */
	public static function get_redirect_uri(): string {
		return add_query_arg( 'atora_oauth_provider', 'google', home_url() );
	}

	/**
	 * URL de autorización de Google — con todos los scopes de una vez
	 * (Calendar + Drive.file + identidad), para que conectar Google una
	 * sola vez habilite Calendar, Meet y Drive sin pedir consentimiento
	 * de nuevo por cada feature. No existía ningún punto en el código que
	 * generara esta URL — Calendar_Sync::handle_oauth_callback() solo
	 * procesaba el callback, nunca había un botón "Conectar" real.
	 *
	 * @return string|null null si no hay client_id configurado (BYO pendiente).
	 */
	public static function get_authorize_url(): ?string {
		$client_id = self::get_client_id();
		if ( ! $client_id || ! get_current_user_id() ) {
			return null;
		}

		$params = array(
			'client_id'              => $client_id,
			'redirect_uri'           => self::get_redirect_uri(),
			'response_type'          => 'code',
			'scope'                  => implode( ' ', self::get_scopes() ),
			'access_type'            => 'offline',
			'prompt'                 => 'consent',
			// Mismo esquema de state que ya valida Calendar_Sync::handle_oauth_callback().
			'state'                  => wp_create_nonce( 'atora_oauth_google_' . get_current_user_id() ),
		);

		return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params );
	}

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}

		$view = ATORA_LMS_MODULES_DIR . 'google/views/settings.php';
		if ( file_exists( $view ) ) {
			require $view;
		}
	}

	public static function register_rest_routes(): void {
		register_rest_route( 'atora/v1', '/google/verify-identity', array(
			'methods'             => 'POST',
			'callback'            => array( Google_Identity::class, 'rest_verify_identity' ),
			'permission_callback' => '__return_true', // Sin sesión todavía: es el propio login/registro.
		) );

		register_rest_route( 'atora/v1', '/google/link', array(
			'methods'             => 'POST',
			'callback'            => array( Google_Identity::class, 'rest_link_account' ),
			'permission_callback' => static fn() => is_user_logged_in(),
		) );

		register_rest_route( 'atora/v1', '/google/unlink', array(
			'methods'             => 'POST',
			'callback'            => array( Google_Identity::class, 'rest_unlink_account' ),
			'permission_callback' => static fn() => is_user_logged_in(),
		) );

		register_rest_route( 'atora/v1', '/google/drive/attach', array(
			'methods'             => 'POST',
			'callback'            => array( Google_Drive::class, 'rest_attach_file' ),
			'permission_callback' => static fn() => is_user_logged_in(),
		) );
	}
}
