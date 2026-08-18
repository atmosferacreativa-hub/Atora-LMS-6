<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Settings_Academy_Navigation_Trait {
	public static function get_academy_settings() {
		$defaults = array(
			'academy_name'    => get_bloginfo( 'name' ),
			'academy_tagline' => get_bloginfo( 'description' ),
			'primary_color'   => '#6366f1',
			'logo_id'         => 0,
			'contact_email'   => get_bloginfo( 'admin_email' ),
			'teacher_contact_email' => get_bloginfo( 'admin_email' ),
			'admin_contact_email'   => get_bloginfo( 'admin_email' ),
			'ui_theme'        => 'light',
			'ui_color_bg'           => '#ffffff',
			'ui_color_surface'      => '#f8fafc',
			'ui_color_text'         => '#0f172a',
			'ui_color_muted'        => '#475569',
			'ui_color_border'       => '#e2e8f0',
			'ui_color_accent'       => '#6366f1',
			'ui_color_accent_hover' => '#4f46e5',
			'ui_color_accent_soft'  => '#eef2ff',
		);
		$stored = get_option( self::OPTION_ACADEMY, array() );
		return array_merge( $defaults, is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Devuelve los ajustes de navegación con defaults.
	 */
	public static function get_navigation_settings() {
		$defaults = array(
			'header_location'          => '',
			'footer_location'          => '',
			'header_menu_guest'        => 0,
			'header_menu_student'      => 0,
			'header_menu_instructor'   => 0,
			'header_menu_collaborator' => 0,
			'header_menu_admin'        => 0,
			'footer_menu'              => 0,
		);

		$stored = get_option( self::OPTION_NAV, array() );
		return array_merge( $defaults, is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Feature flag central de CRM v2.
	 *
	 * @return bool
	 */
	public static function is_crm_v2_enabled(): bool {
		$enabled = get_option( self::OPTION_CRM_V2_ENABLED, false );
		$value   = self::sanitize_feature_flag_boolean( $enabled );

		/**
		 * Permite sobrescribir el estado del flag CRM v2 desde integraciones.
		 *
		 * @param bool $value Estado actual.
		 */
		return (bool) apply_filters( 'atora/crm_v2/enabled', $value );
	}

	/**
	 * Devuelve el email institucional para contacto docente.
	 *
	 * @return string
	 */
	public static function get_teacher_contact_email() {
		$academy = self::get_academy_settings();
		$email   = sanitize_email( (string) ( $academy['teacher_contact_email'] ?? '' ) );
		if ( $email && is_email( $email ) ) {
			return $email;
		}

		$fallback = sanitize_email( (string) ( $academy['contact_email'] ?? '' ) );
		return $fallback && is_email( $fallback ) ? $fallback : sanitize_email( (string) get_option( 'admin_email' ) );
	}

	/**
	 * Devuelve el email institucional para contacto administrativo.
	 *
	 * @return string
	 */
	public static function get_admin_contact_email() {
		$academy = self::get_academy_settings();
		$email   = sanitize_email( (string) ( $academy['admin_contact_email'] ?? '' ) );
		if ( $email && is_email( $email ) ) {
			return $email;
		}

		return sanitize_email( (string) get_option( 'admin_email' ) );
	}

	/**
	 * Devuelve la Whisper API key dedicada.
	 */
	public static function get_whisper_key() {
		$ai = self::get_ai_settings();

		return trim( (string) ( $ai['whisper_api_key'] ?? '' ) );
	}
}
