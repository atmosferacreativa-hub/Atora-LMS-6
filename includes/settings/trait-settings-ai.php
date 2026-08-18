<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Settings_AI_Trait {
	public static function get_allowed_ai_providers() {
		return array( 'openai', 'anthropic', 'gemini', 'deepseek' );
	}

	/**
	 * Option key canónica de configuración AI.
	 *
	 * @return string
	 */
	public static function get_ai_option_key() {
		return self::OPTION_AI;
	}

	public static function get_ai_defaults() {
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
	 * Devuelve configuración AI normalizada.
	 *
	 * @return array
	 */
	public static function get_ai_settings() {
		$stored = get_option( self::get_ai_option_key(), array() );
		$stored = is_array( $stored ) ? $stored : array();

		return self::sanitize_ai_settings( $stored, $stored );
	}

	/**
	 * Devuelve ajustes avanzados normalizados.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_advanced_settings() {
		$defaults = array(
			'disable_rest_api'               => '0',
			'enable_debug_log'               => '0',
			'inactivity_days_threshold'      => 14,
			'inactivity_email_enabled'       => '0',
			'inactivity_email_cooldown_hours'=> 72,
			'inactivity_email_subject'       => 'Te extrañamos en {course_title}',
			'inactivity_email_headline'      => 'Retoma tu ruta de aprendizaje',
			'inactivity_email_button_text'   => 'Continuar curso',
			'inactivity_email_footer_note'   => 'Este recordatorio fue enviado automáticamente por tu academia.',
			'inactivity_email_body'          => 'Hola {student_name}, notamos que llevas {days_inactive} días sin ingresar a {course_title}. Te recomendamos retomar hoy con un bloque corto de estudio para mantener tu avance.',
		);
		$stored = get_option( self::OPTION_ADV, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$data   = array_merge( $defaults, $stored );

		$data['disable_rest_api']                = ! empty( $data['disable_rest_api'] ) ? '1' : '0';
		$data['enable_debug_log']                = ! empty( $data['enable_debug_log'] ) ? '1' : '0';
		$data['inactivity_email_enabled']        = ! empty( $data['inactivity_email_enabled'] ) ? '1' : '0';
		$data['inactivity_days_threshold']       = max( 1, min( 90, absint( $data['inactivity_days_threshold'] ) ) );
		$data['inactivity_email_cooldown_hours'] = max( 1, min( 720, absint( $data['inactivity_email_cooldown_hours'] ) ) );
		$data['inactivity_email_subject']        = sanitize_text_field( (string) $data['inactivity_email_subject'] );
		$data['inactivity_email_headline']       = sanitize_text_field( (string) $data['inactivity_email_headline'] );
		$data['inactivity_email_button_text']    = sanitize_text_field( (string) $data['inactivity_email_button_text'] );
		$data['inactivity_email_footer_note']    = sanitize_text_field( (string) $data['inactivity_email_footer_note'] );
		$data['inactivity_email_body']           = sanitize_textarea_field( (string) $data['inactivity_email_body'] );

		return $data;
	}

	/**
	 * Pipeline único de guardado para clms_ai_settings.
	 *
	 * @param mixed $input   Input entrante.
	 * @param mixed $current Valor actual.
	 * @return array
	 */
	public static function prepare_ai_settings_for_storage( $input, $current = array() ) {
		$current = is_array( $current ) ? $current : array();
		$data    = self::sanitize_ai_settings( $input, $current );
		self::sync_legacy_openai_key( $data );

		return $data;
	}

	/**
	 * Sanitiza y normaliza clms_ai_settings.
	 *
	 * @param mixed $input   Input entrante.
	 * @param array $current Valor actual.
	 * @return array
	 */
	public static function sanitize_ai_settings( $input, $current = array() ) {
		$current  = is_array( $current ) ? $current : array();
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::get_ai_defaults();
		$data     = array_merge( $defaults, $current );

		if ( array_key_exists( 'provider', $input ) ) {
			$provider = sanitize_key( (string) $input['provider'] );
			if ( ! in_array( $provider, self::get_allowed_ai_providers(), true ) ) {
				$provider = 'openai';
			}
			$data['provider'] = $provider;
		}

		$key_fields = array(
			'openai_api_key',
			'anthropic_api_key',
			'gemini_api_key',
			'deepseek_api_key',
			'whisper_api_key',
		);

		foreach ( $key_fields as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$value         = sanitize_text_field( (string) $input[ $field ] );
				$value         = preg_replace( '/\s+/', '', $value );
				$data[ $field ] = trim( (string) $value );
			}
		}

		$model_fields = array(
			'openai_model'    => $defaults['openai_model'],
			'anthropic_model' => $defaults['anthropic_model'],
			'gemini_model'    => $defaults['gemini_model'],
			'deepseek_model'  => $defaults['deepseek_model'],
		);

		foreach ( $model_fields as $field => $fallback ) {
			if ( array_key_exists( $field, $input ) ) {
				$model = sanitize_text_field( (string) $input[ $field ] );
				$model = trim( $model );
				if ( '' !== $model ) {
					$model = preg_replace( '/\s+/', '', $model );
				}

				if ( '' === $model || ! preg_match( '/^[A-Za-z0-9._:\\/\\-]+$/', $model ) ) {
					$model = $fallback;
				}

				$data[ $field ] = $model;
			}

			if ( '' === trim( (string) $data[ $field ] ) ) {
				$data[ $field ] = $fallback;
			}
		}

		if ( empty( $data['provider'] ) || ! in_array( $data['provider'], self::get_allowed_ai_providers(), true ) ) {
			$data['provider'] = 'openai';
		}

		$checkbox_fields = array(
			'ai_enabled',
			'ai_copilot_commercial_enabled',
			'ai_copilot_teacher_enabled',
			'ai_copilot_evaluator_enabled',
			'ai_copilot_student_enabled',
			'ai_logs_enabled',
		);
		foreach ( $checkbox_fields as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = ! empty( $input[ $field ] ) ? '1' : '0';
			} elseif ( ! isset( $data[ $field ] ) ) {
				$data[ $field ] = '0';
			}
		}

		if ( array_key_exists( 'ai_evaluation_mode', $input ) ) {
			$mode = sanitize_key( (string) $input['ai_evaluation_mode'] );
			$allowed_modes = array( 'manual', 'assisted', 'automatic', 'hybrid' );
			if ( ! in_array( $mode, $allowed_modes, true ) ) {
				$mode = 'assisted';
			}
			$data['ai_evaluation_mode'] = $mode;
		}
		if ( empty( $data['ai_evaluation_mode'] ) ) {
			$data['ai_evaluation_mode'] = 'assisted';
		}

		$numeric_limits = array(
			'ai_limit_role_admin_hour'   => 120,
			'ai_limit_role_teacher_hour' => 80,
			'ai_limit_role_student_hour' => 40,
			'ai_limit_role_guest_hour'   => 20,
		);
		foreach ( $numeric_limits as $field => $fallback ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = max( 1, min( 500, absint( $input[ $field ] ) ) );
			} elseif ( ! isset( $data[ $field ] ) ) {
				$data[ $field ] = $fallback;
			} else {
				$data[ $field ] = max( 1, min( 500, absint( $data[ $field ] ) ) );
			}
		}

		return $data;
	}

	/**
	 * Sincroniza clave legacy usada por módulos antiguos.
	 *
	 * @param array $data Configuración AI normalizada.
	 * @return void
	 */
	public static function sync_legacy_openai_key( array $data ) {
		$openai_key = isset( $data['openai_api_key'] ) ? trim( (string) $data['openai_api_key'] ) : '';

		if ( '' !== $openai_key ) {
			update_option( 'clms_openai_api_key', $openai_key );
			return;
		}

		delete_option( 'clms_openai_api_key' );
	}

	/**
	 * Devuelve los ajustes de academia con defaults.
	 */
}
