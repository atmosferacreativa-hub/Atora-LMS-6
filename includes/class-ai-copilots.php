<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CLMS_AI_Copilots
 *
 * Capa de orquestación de IA por propósito:
 * - comercial
 * - docente
 * - evaluador
 * - estudiantil
 *
 * Reutiliza CLMS_AI_Manager y mantiene compatibilidad con consumidores existentes.
 */
class CLMS_AI_Copilots {

	const COPILOT_COMMERCIAL = 'commercial';
	const COPILOT_TEACHER    = 'teacher';
	const COPILOT_EVALUATOR  = 'evaluator';
	const COPILOT_STUDENT    = 'student';

	const EVAL_MODE_MANUAL    = 'manual';
	const EVAL_MODE_ASSISTED  = 'assisted';
	const EVAL_MODE_AUTOMATIC = 'automatic';
	const EVAL_MODE_HYBRID    = 'hybrid';

	/**
	 * Ejecuta una acción de copiloto y retorna texto.
	 *
	 * @param string $copilot commercial|teacher|evaluator|student
	 * @param string $action  Acción concreta
	 * @param array  $messages Mensajes chat
	 * @param array  $options Opciones de CLMS_AI_Manager
	 * @param array  $context Contexto seguro para trazabilidad
	 * @return string|WP_Error
	 */
	public function run_text( $copilot, $action, array $messages, array $options = array(), array $context = array() ) {
		$result = $this->run_with_meta( $copilot, $action, $messages, $options, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['text'] ) ? (string) $result['text'] : '';
	}

	/**
	 * Ejecuta una acción de copiloto y retorna texto + metadatos.
	 *
	 * @return array|WP_Error
	 */
	public function run_with_meta( $copilot, $action, array $messages, array $options = array(), array $context = array() ) {
		$copilot = $this->normalize_copilot( $copilot );
		$action  = sanitize_key( (string) $action );
		$user_id = get_current_user_id();

		if ( ! $this->is_copilot_enabled( $copilot ) ) {
			$error = new WP_Error( 'clms_ai_copilot_disabled', __( 'Este copiloto IA está desactivado en la configuración.', 'atora-lms' ) );
			$this->log_event( $copilot, $action, $context, $error, array(), $user_id );
			return $error;
		}

		if ( ! $this->can_user_access_copilot( $copilot, $user_id ) ) {
			$error = new WP_Error( 'clms_ai_copilot_forbidden', __( 'No tienes permisos para usar este copiloto IA.', 'atora-lms' ) );
			$this->log_event( $copilot, $action, $context, $error, array(), $user_id );
			return $error;
		}

		if ( ! $this->check_rate_limit( $copilot, $user_id ) ) {
			$error = new WP_Error( 'clms_ai_copilot_rate_limit', __( 'Has alcanzado el límite de solicitudes IA para tu rol. Intenta nuevamente más tarde.', 'atora-lms' ) );
			$this->log_event( $copilot, $action, $context, $error, array(), $user_id );
			return $error;
		}

		$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
		if ( ! $manager || ! method_exists( $manager, 'chat_with_meta' ) ) {
			$error = new WP_Error( 'clms_ai_manager_missing', __( 'CLMS_AI_Manager no está disponible.', 'atora-lms' ) );
			$this->log_event( $copilot, $action, $context, $error, array(), $user_id );
			return $error;
		}

		$prepared = $this->prepare_payload( $copilot, $action, $messages, $options, $context, $user_id );
		$result   = $manager->chat_with_meta( $prepared['messages'], $prepared['options'] );

		$this->log_event( $copilot, $action, $context, $result, is_array( $result ) ? $result : array(), $user_id );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'clms_ai_copilot_error', $this->human_error_message( $result ), $result->get_error_data() );
		}

		return is_array( $result ) ? $result : array(
			'text' => (string) $result,
			'usage' => array(),
			'provider' => '',
			'model' => '',
		);
	}

	public function get_evaluation_mode() {
		$settings = $this->get_ai_settings();
		$mode = isset( $settings['ai_evaluation_mode'] ) ? sanitize_key( (string) $settings['ai_evaluation_mode'] ) : self::EVAL_MODE_ASSISTED;
		$allowed = array( self::EVAL_MODE_MANUAL, self::EVAL_MODE_ASSISTED, self::EVAL_MODE_AUTOMATIC, self::EVAL_MODE_HYBRID );
		if ( ! in_array( $mode, $allowed, true ) ) {
			$mode = self::EVAL_MODE_ASSISTED;
		}

		/**
		 * Permite modificar el modo global de evaluación IA.
		 */
		return (string) apply_filters( 'clms_ai_evaluation_mode', $mode, $settings );
	}

	public function allows_automatic_evaluation() {
		return self::EVAL_MODE_AUTOMATIC === $this->get_evaluation_mode();
	}

	public function is_copilot_enabled( $copilot ) {
		$copilot  = $this->normalize_copilot( $copilot );
		$settings = $this->get_ai_settings();

		if ( empty( $settings['ai_enabled'] ) ) {
			return false;
		}

		$key_map = array(
			self::COPILOT_COMMERCIAL => 'ai_copilot_commercial_enabled',
			self::COPILOT_TEACHER    => 'ai_copilot_teacher_enabled',
			self::COPILOT_EVALUATOR  => 'ai_copilot_evaluator_enabled',
			self::COPILOT_STUDENT    => 'ai_copilot_student_enabled',
		);

		$key = isset( $key_map[ $copilot ] ) ? $key_map[ $copilot ] : 'ai_copilot_student_enabled';
		$enabled = ! empty( $settings[ $key ] );

		return (bool) apply_filters( 'clms_ai_copilot_enabled', $enabled, $copilot, $settings );
	}

	public function can_user_access_copilot( $copilot, $user_id = 0 ) {
		$copilot = $this->normalize_copilot( $copilot );
		$user_id = absint( $user_id );

		if ( self::COPILOT_COMMERCIAL === $copilot ) {
			$allowed = true;
		} elseif ( self::COPILOT_STUDENT === $copilot ) {
			$allowed = $user_id > 0;
		} else {
			if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'user_can_manage_lms' ) ) {
				$allowed = (bool) CLMS_Helper::user_can_manage_lms();
			} else {
				$allowed = user_can( $user_id, 'manage_options' );
			}
		}

		return (bool) apply_filters( 'clms_ai_copilot_user_access', $allowed, $copilot, $user_id );
	}

	private function prepare_payload( $copilot, $action, array $messages, array $options, array $context, $user_id ) {
		$messages = $this->sanitize_messages( $messages );
		$options  = is_array( $options ) ? $options : array();
		$context  = is_array( $context ) ? $context : array();
		$safe_ctx = $this->sanitize_context( $context );

		if ( ! empty( $options['system'] ) ) {
			$options['system'] = sanitize_textarea_field( (string) $options['system'] );
		}

		if ( empty( $options['max_tokens'] ) ) {
			$options['max_tokens'] = $this->resolve_default_max_tokens( $copilot );
		}
		if ( empty( $options['temperature'] ) ) {
			$options['temperature'] = self::COPILOT_EVALUATOR === $copilot ? 0.2 : 0.6;
		}
		if ( empty( $options['timeout'] ) ) {
			$options['timeout'] = 60;
		}

		if ( ! empty( $options['system'] ) ) {
			$options['system'] = (string) apply_filters( 'clms_ai_copilot_system_prompt', $options['system'], $copilot, $action, $safe_ctx, $user_id );
		}

		$messages = apply_filters( 'clms_ai_copilot_messages', $messages, $copilot, $action, $safe_ctx, $user_id );
		$messages = $this->sanitize_messages( is_array( $messages ) ? $messages : array() );

		return array(
			'messages' => $messages,
			'options'  => $options,
			'context'  => $safe_ctx,
		);
	}

	private function sanitize_messages( array $messages ) {
		$clean = array();
		foreach ( $messages as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$role    = isset( $item['role'] ) ? sanitize_key( (string) $item['role'] ) : 'user';
			$content = isset( $item['content'] ) ? sanitize_textarea_field( (string) $item['content'] ) : '';
			if ( '' === trim( $content ) ) {
				continue;
			}
			if ( ! in_array( $role, array( 'system', 'user', 'assistant' ), true ) ) {
				$role = 'user';
			}
			$clean[] = array(
				'role'    => $role,
				'content' => mb_substr( $content, 0, 5000 ),
			);
		}

		return array_slice( $clean, -14 );
	}

	private function sanitize_context( array $context ) {
		$safe = array();
		$allowed = array(
			'course_id',
			'lesson_id',
			'program_id',
			'submission_id',
			'user_id',
			'action_label',
			'mode',
			'tool',
			'screen',
		);

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $context ) ) {
				continue;
			}
			$value = $context[ $key ];
			if ( is_numeric( $value ) ) {
				$safe[ $key ] = absint( $value );
			} else {
				$safe[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $safe;
	}

	private function normalize_copilot( $copilot ) {
		$copilot = sanitize_key( (string) $copilot );
		$allowed = array(
			self::COPILOT_COMMERCIAL,
			self::COPILOT_TEACHER,
			self::COPILOT_EVALUATOR,
			self::COPILOT_STUDENT,
		);
		if ( ! in_array( $copilot, $allowed, true ) ) {
			$copilot = self::COPILOT_STUDENT;
		}

		return $copilot;
	}

	private function resolve_default_max_tokens( $copilot ) {
		switch ( $copilot ) {
			case self::COPILOT_EVALUATOR:
				return 1200;
			case self::COPILOT_TEACHER:
				return 1800;
			case self::COPILOT_COMMERCIAL:
				return 700;
			default:
				return 900;
		}
	}

	private function check_rate_limit( $copilot, $user_id ) {
		if ( $user_id <= 0 && self::COPILOT_COMMERCIAL === $copilot ) {
			$client_key = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'guest';
			$client_key = hash( 'sha256', $client_key . '|' . wp_salt( 'auth' ) );
			$user_key   = 'g_' . $client_key;
			$limit      = (int) $this->get_limit_for_role( 'guest' );
		} else {
			$user        = $user_id > 0 ? get_userdata( $user_id ) : null;
			$role        = ( $user && ! empty( $user->roles ) && is_array( $user->roles ) ) ? (string) $user->roles[0] : 'student';
			$user_key    = 'u_' . absint( $user_id );
			$limit       = (int) $this->get_limit_for_role( $role );
		}

		$limit = max( 1, $limit );
		$window_seconds = HOUR_IN_SECONDS;
		$key = 'clms_ai_rl_' . sanitize_key( $copilot ) . '_' . sanitize_key( $user_key );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, $window_seconds );
		return true;
	}

	private function get_limit_for_role( $role ) {
		$settings = $this->get_ai_settings();
		$role = sanitize_key( (string) $role );
		$map = array(
			'administrator' => 'ai_limit_role_admin_hour',
			'instructor'    => 'ai_limit_role_teacher_hour',
			'teacher'       => 'ai_limit_role_teacher_hour',
			'editor'        => 'ai_limit_role_teacher_hour',
			'student'       => 'ai_limit_role_student_hour',
			'subscriber'    => 'ai_limit_role_student_hour',
			'guest'         => 'ai_limit_role_guest_hour',
		);
		$key = isset( $map[ $role ] ) ? $map[ $role ] : 'ai_limit_role_student_hour';
		$default = ( 'ai_limit_role_teacher_hour' === $key ) ? 80 : ( 'ai_limit_role_admin_hour' === $key ? 120 : ( 'ai_limit_role_guest_hour' === $key ? 20 : 40 ) );

		return isset( $settings[ $key ] ) ? absint( $settings[ $key ] ) : $default;
	}

	private function get_ai_settings() {
		if ( class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_settings' ) ) {
			return (array) CLMS_AI_Settings_Service::get_settings();
		}

		return (array) get_option( 'clms_ai_settings', array() );
	}

	private function human_error_message( WP_Error $error ) {
		$code = (string) $error->get_error_code();
		$message = (string) $error->get_error_message();

		if ( false !== strpos( $code, 'no_key' ) ) {
			return __( 'La IA no está lista todavía. Falta configurar una API key válida.', 'atora-lms' );
		}
		if ( false !== strpos( $code, 'rate' ) || false !== strpos( $message, '429' ) ) {
			return __( 'El proveedor IA está con alta demanda. Intenta nuevamente en unos minutos.', 'atora-lms' );
		}

		return __( 'No fue posible completar la acción IA en este momento. Intenta nuevamente.', 'atora-lms' );
	}

	private function log_event( $copilot, $action, array $context, $result, array $meta_result, $user_id ) {
		$usage = isset( $meta_result['usage'] ) && is_array( $meta_result['usage'] ) ? $meta_result['usage'] : array();
		$provider = isset( $meta_result['provider'] ) ? sanitize_key( (string) $meta_result['provider'] ) : '';
		$model = isset( $meta_result['model'] ) ? sanitize_text_field( (string) $meta_result['model'] ) : '';

		do_action(
			'clms_ai_copilot_event',
			array(
				'user_id'      => absint( $user_id ),
				'role'         => $this->resolve_primary_role( $user_id ),
				'copilot'      => sanitize_key( (string) $copilot ),
				'action'       => sanitize_key( (string) $action ),
				'context'      => $this->sanitize_context( $context ),
				'success'      => ! is_wp_error( $result ),
				'error_code'   => is_wp_error( $result ) ? $result->get_error_code() : '',
				'error_message'=> is_wp_error( $result ) ? $result->get_error_message() : '',
				'provider'     => $provider,
				'model'        => $model,
				'usage'        => $usage,
				'created_at'   => current_time( 'mysql' ),
			)
		);
	}

	private function resolve_primary_role( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return 'guest';
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_array( $user->roles ) || empty( $user->roles ) ) {
			return 'unknown';
		}

		return sanitize_key( (string) reset( $user->roles ) );
	}
}
