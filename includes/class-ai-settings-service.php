<?php
/**
 * CLMS_AI_Settings_Service
 *
 * Servicio liviano para lectura consistente de la configuración AI.
 * Centraliza acceso para evitar más lecturas directas de clms_ai_settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_AI_Settings_Service {

	const OPTION_KEY            = 'clms_ai_settings';
	const LEGACY_OPENAI_OPTION  = 'clms_openai_api_key';

	/**
	 * Devuelve settings AI normalizados desde el owner central.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_settings(): array {
		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_ai_settings' ) ) {
			$settings = CLMS_Settings::get_ai_settings();
			return is_array( $settings ) ? $settings : array();
		}

		$raw = get_option( self::OPTION_KEY, array() );
		$raw = is_array( $raw ) ? $raw : array();

		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'sanitize_ai_settings' ) ) {
			return (array) CLMS_Settings::sanitize_ai_settings( $raw, $raw );
		}

		return self::normalize_settings_fallback( $raw );
	}

	/**
	 * Defaults AI canónicos.
	 *
	 * @return array<string,string>
	 */
	public static function get_defaults(): array {
		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_ai_defaults' ) ) {
			$defaults = CLMS_Settings::get_ai_defaults();
			return is_array( $defaults ) ? $defaults : array();
		}

		return array(
			'provider'           => 'openai',
			'openai_api_key'     => '',
			'openai_model'       => 'gpt-4o-mini',
			'anthropic_api_key'  => '',
			'anthropic_model'    => 'claude-sonnet-4-6',
			'gemini_api_key'     => '',
			'gemini_model'       => 'gemini-2.5-flash',
			'deepseek_api_key'   => '',
			'deepseek_model'     => 'deepseek-v4-flash',
			'whisper_api_key'    => '',
			'ai_enabled'        => '1',
			'ai_copilot_commercial_enabled' => '1',
			'ai_copilot_teacher_enabled'    => '1',
			'ai_copilot_evaluator_enabled'  => '1',
			'ai_copilot_student_enabled'    => '1',
			'ai_evaluation_mode'            => 'assisted',
			'ai_logs_enabled'               => '1',
			'ai_limit_role_admin_hour'      => 120,
			'ai_limit_role_teacher_hour'    => 80,
			'ai_limit_role_student_hour'    => 40,
			'ai_limit_role_guest_hour'      => 20,
		);
	}

	/**
	 * Proveedor activo normalizado.
	 */
	public static function get_provider(): string {
		$settings = self::get_settings();
		$provider = isset( $settings['provider'] ) ? (string) $settings['provider'] : 'openai';

		return self::normalize_provider( $provider );
	}

	/**
	 * API key de proveedor generativo.
	 *
	 * @param string $provider openai|anthropic|gemini
	 */
	public static function get_provider_api_key( string $provider = '' ): string {
		$provider = '' !== $provider ? self::normalize_provider( $provider ) : self::get_provider();
		$settings = self::get_settings();
		$map      = array(
			'openai'    => 'openai_api_key',
			'anthropic' => 'anthropic_api_key',
			'gemini'    => 'gemini_api_key',
			'deepseek'  => 'deepseek_api_key',
		);
		$field = $map[ $provider ] ?? 'openai_api_key';
		$key   = trim( (string) ( $settings[ $field ] ?? '' ) );

		if ( '' === $key && 'openai' === $provider ) {
			$key = trim( (string) get_option( self::LEGACY_OPENAI_OPTION, '' ) );
		}

		return $key;
	}

	/**
	 * Modelo configurado para proveedor generativo.
	 *
	 * @param string $provider openai|anthropic|gemini
	 */
	public static function get_provider_model( string $provider = '' ): string {
		$provider = '' !== $provider ? self::normalize_provider( $provider ) : self::get_provider();
		$settings = self::get_settings();
		$defaults = self::get_defaults();
		$map      = array(
			'openai'    => 'openai_model',
			'anthropic' => 'anthropic_model',
			'gemini'    => 'gemini_model',
			'deepseek'  => 'deepseek_model',
		);
		$field = $map[ $provider ] ?? 'openai_model';
		$model = trim( (string) ( $settings[ $field ] ?? '' ) );

		if ( '' === $model ) {
			$model = trim( (string) ( $defaults[ $field ] ?? '' ) );
		}

		return $model;
	}

	/**
	 * API key dedicada de Whisper.
	 */
	public static function get_whisper_api_key(): string {
		$settings = self::get_settings();
		return trim( (string) ( $settings['whisper_api_key'] ?? '' ) );
	}

	/**
	 * ¿Existe al menos una key de proveedor generativo?
	 */
	public static function has_any_generation_key(): bool {
		foreach ( array( 'openai', 'anthropic', 'gemini', 'deepseek' ) as $provider ) {
			if ( '' !== self::get_provider_api_key( $provider ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Alias de compatibilidad.
	 */
	public static function has_any_api_key(): bool {
		return self::has_any_generation_key();
	}

	/**
	 * Normalización local cuando CLMS_Settings no está disponible.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private static function normalize_settings_fallback( array $raw ): array {
		$defaults = self::get_defaults();
		$data     = array_merge( $defaults, $raw );

		$data['provider'] = self::normalize_provider( (string) ( $data['provider'] ?? 'openai' ) );

		foreach ( array( 'openai_api_key', 'anthropic_api_key', 'gemini_api_key', 'deepseek_api_key', 'whisper_api_key' ) as $field ) {
			$value         = sanitize_text_field( (string) ( $data[ $field ] ?? '' ) );
			$value         = preg_replace( '/\s+/', '', $value );
			$data[ $field ] = trim( (string) $value );
		}

		$model_defaults = array(
			'openai_model'    => (string) ( $defaults['openai_model']    ?? 'gpt-4o-mini' ),
			'anthropic_model' => (string) ( $defaults['anthropic_model'] ?? 'claude-sonnet-4-6' ),
			'gemini_model'    => (string) ( $defaults['gemini_model']    ?? 'gemini-2.5-flash' ),
			'deepseek_model'  => (string) ( $defaults['deepseek_model']  ?? 'deepseek-v4-flash' ),
		);

		foreach ( $model_defaults as $field => $fallback ) {
			$model = sanitize_text_field( (string) ( $data[ $field ] ?? '' ) );
			$model = trim( $model );

			if ( '' !== $model ) {
				$model = preg_replace( '/\s+/', '', $model );
			}

			if ( '' === $model || ! preg_match( '/^[A-Za-z0-9._:\\/\\-]+$/', $model ) ) {
				$model = $fallback;
			}

			$data[ $field ] = $model;
		}

		foreach ( array(
			'ai_enabled',
			'ai_copilot_commercial_enabled',
			'ai_copilot_teacher_enabled',
			'ai_copilot_evaluator_enabled',
			'ai_copilot_student_enabled',
			'ai_logs_enabled',
		) as $field ) {
			$data[ $field ] = ! empty( $data[ $field ] ) ? '1' : '0';
		}

		$mode = isset( $data['ai_evaluation_mode'] ) ? sanitize_key( (string) $data['ai_evaluation_mode'] ) : 'assisted';
		if ( ! in_array( $mode, array( 'manual', 'assisted', 'automatic', 'hybrid' ), true ) ) {
			$mode = 'assisted';
		}
		$data['ai_evaluation_mode'] = $mode;

		foreach ( array(
			'ai_limit_role_admin_hour'   => 120,
			'ai_limit_role_teacher_hour' => 80,
			'ai_limit_role_student_hour' => 40,
			'ai_limit_role_guest_hour'   => 20,
		) as $field => $fallback ) {
			$value = isset( $data[ $field ] ) ? absint( $data[ $field ] ) : $fallback;
			$data[ $field ] = max( 1, min( 500, $value ) );
		}

		return $data;
	}

	private static function normalize_provider( string $provider ): string {
		$provider = sanitize_key( $provider );
		$allowed  = array( 'openai', 'anthropic', 'gemini', 'deepseek' );

		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_allowed_ai_providers' ) ) {
			$providers = CLMS_Settings::get_allowed_ai_providers();
			if ( is_array( $providers ) && ! empty( $providers ) ) {
				$allowed = array_values( array_map( 'sanitize_key', $providers ) );
			}
		}

		return in_array( $provider, $allowed, true ) ? $provider : 'openai';
	}
}
