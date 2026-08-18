<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CLMS_AI_Log
 *
 * Historial básico de acciones IA para trazabilidad operativa.
 */
class CLMS_AI_Log {

	const OPTION_LOGS = 'clms_ai_action_logs';
	const MAX_LOGS    = 500;

	public function __construct() {
		add_action( 'clms_ai_copilot_event', array( $this, 'store_copilot_event' ), 10, 1 );
		add_action( 'clms_ai_error', array( $this, 'store_manager_error' ), 10, 1 );
		add_action( 'clms_ai_request_completed', array( $this, 'store_generic_success' ), 10, 1 );
	}

	public function store_copilot_event( $event ) {
		if ( ! $this->is_logging_enabled() ) {
			return;
		}

		$event = is_array( $event ) ? $event : array();
		$usage = isset( $event['usage'] ) && is_array( $event['usage'] ) ? $event['usage'] : array();
		$provider = isset( $event['provider'] ) ? sanitize_key( (string) $event['provider'] ) : '';

		$record = array(
			'id'            => wp_generate_uuid4(),
			'event_type'    => 'copilot_action',
			'user_id'       => isset( $event['user_id'] ) ? absint( $event['user_id'] ) : 0,
			'role'          => isset( $event['role'] ) ? sanitize_key( (string) $event['role'] ) : 'unknown',
			'copilot'       => isset( $event['copilot'] ) ? sanitize_key( (string) $event['copilot'] ) : '',
			'action'        => isset( $event['action'] ) ? sanitize_key( (string) $event['action'] ) : '',
			'context'       => isset( $event['context'] ) && is_array( $event['context'] ) ? $event['context'] : array(),
			'success'       => ! empty( $event['success'] ),
			'error_code'    => isset( $event['error_code'] ) ? sanitize_key( (string) $event['error_code'] ) : '',
			'error_message' => isset( $event['error_message'] ) ? sanitize_text_field( (string) $event['error_message'] ) : '',
			'provider'      => $provider,
			'model'         => isset( $event['model'] ) ? sanitize_text_field( (string) $event['model'] ) : '',
			'usage'         => $usage,
			'estimated_cost'=> $this->estimate_cost( $provider, $usage ),
			'created_at'    => isset( $event['created_at'] ) ? sanitize_text_field( (string) $event['created_at'] ) : current_time( 'mysql' ),
		);

		$this->append_log( $record );
	}

	public function store_manager_error( $event ) {
		if ( ! $this->is_logging_enabled() ) {
			return;
		}

		$event = is_array( $event ) ? $event : array();
		$record = array(
			'id'            => wp_generate_uuid4(),
			'event_type'    => 'provider_error',
			'user_id'       => get_current_user_id(),
			'role'          => $this->resolve_user_role( get_current_user_id() ),
			'copilot'       => isset( $event['copilot'] ) ? sanitize_key( (string) $event['copilot'] ) : '',
			'action'        => isset( $event['source'] ) ? sanitize_key( (string) $event['source'] ) : 'manager',
			'context'       => $this->sanitize_context( $event ),
			'success'       => false,
			'error_code'    => isset( $event['code'] ) ? sanitize_key( (string) $event['code'] ) : 'ai_error',
			'error_message' => isset( $event['message'] ) ? sanitize_text_field( (string) $event['message'] ) : __( 'Error IA no especificado.', 'atora-lms' ),
			'provider'      => isset( $event['provider'] ) ? sanitize_key( (string) $event['provider'] ) : '',
			'model'         => isset( $event['model'] ) ? sanitize_text_field( (string) $event['model'] ) : '',
			'usage'         => array(),
			'estimated_cost'=> 0,
			'created_at'    => current_time( 'mysql' ),
		);

		$this->append_log( $record );
	}

	public function store_generic_success( $event ) {
		if ( ! $this->is_logging_enabled() ) {
			return;
		}

		$event = is_array( $event ) ? $event : array();
		$usage = isset( $event['usage'] ) && is_array( $event['usage'] ) ? $event['usage'] : array();
		$provider = isset( $event['provider'] ) ? sanitize_key( (string) $event['provider'] ) : '';

		$record = array(
			'id'            => wp_generate_uuid4(),
			'event_type'    => 'manager_success',
			'user_id'       => get_current_user_id(),
			'role'          => $this->resolve_user_role( get_current_user_id() ),
			'copilot'       => '',
			'action'        => isset( $event['source'] ) ? sanitize_key( (string) $event['source'] ) : 'chat',
			'context'       => array(
				'message_count' => isset( $event['message_count'] ) ? absint( $event['message_count'] ) : 0,
			),
			'success'       => true,
			'error_code'    => '',
			'error_message' => '',
			'provider'      => $provider,
			'model'         => isset( $event['model'] ) ? sanitize_text_field( (string) $event['model'] ) : '',
			'usage'         => $usage,
			'estimated_cost'=> $this->estimate_cost( $provider, $usage ),
			'created_at'    => current_time( 'mysql' ),
		);

		$this->append_log( $record );
	}

	public static function get_logs( $limit = 50 ) {
		$limit = max( 1, absint( $limit ) );
		$all   = get_option( self::OPTION_LOGS, array() );
		$all   = is_array( $all ) ? $all : array();

		return array_slice( $all, 0, $limit );
	}

	private function append_log( array $record ) {
		$logs = get_option( self::OPTION_LOGS, array() );
		$logs = is_array( $logs ) ? $logs : array();

		array_unshift( $logs, $record );
		if ( count( $logs ) > self::MAX_LOGS ) {
			$logs = array_slice( $logs, 0, self::MAX_LOGS );
		}

		update_option( self::OPTION_LOGS, $logs, false );
	}

	private function is_logging_enabled() {
		$settings = class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_settings' )
			? (array) CLMS_AI_Settings_Service::get_settings()
			: (array) get_option( 'clms_ai_settings', array() );

		return ! isset( $settings['ai_logs_enabled'] ) || ! empty( $settings['ai_logs_enabled'] );
	}

	private function estimate_cost( $provider, array $usage ) {
		$provider = sanitize_key( (string) $provider );
		$input = isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : ( isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0 );
		$output = isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : ( isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0 );
		if ( $input <= 0 && $output <= 0 ) {
			return 0.0;
		}

		$pricing = array(
			'openai'    => array( 'in' => 0.0005, 'out' => 0.0015 ),
			'anthropic' => array( 'in' => 0.0030, 'out' => 0.0150 ),
			'gemini'    => array( 'in' => 0.00035, 'out' => 0.0010 ),
		);
		$rate = isset( $pricing[ $provider ] ) ? $pricing[ $provider ] : $pricing['openai'];

		$cost = ( ( $input / 1000 ) * (float) $rate['in'] ) + ( ( $output / 1000 ) * (float) $rate['out'] );
		return (float) round( $cost, 6 );
	}

	private function resolve_user_role( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return 'guest';
		}
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->roles ) ) {
			return 'unknown';
		}
		return sanitize_key( (string) reset( $user->roles ) );
	}

	private function sanitize_context( array $raw ) {
		$context = array();
		foreach ( array( 'source', 'reason', 'message_count', 'status', 'course_id', 'lesson_id', 'submission_id' ) as $key ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}
			$value = $raw[ $key ];
			$context[ $key ] = is_numeric( $value ) ? absint( $value ) : sanitize_text_field( (string) $value );
		}
		return $context;
	}
}
