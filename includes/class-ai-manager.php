<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CLMS_AI_Manager — Capa central de acceso a APIs de IA.
 *
 * Centraliza:
 *   - Resolución del proveedor activo (OpenAI / Anthropic / Gemini).
 *   - Lectura de API keys y modelos desde clms_ai_settings.
 *   - Envío de mensajes chat a cualquier proveedor.
 *   - Manejo uniforme de errores.
 *
 * Uso:
 *   $manager = CLMS_Helper::module('CLMS_AI_Manager');
 *   $text    = $manager->chat([['role'=>'user','content'=>'Hola']]);
 *
 *   // Con opciones:
 *   $text = $manager->chat($messages, [
 *       'max_tokens'  => 200,
 *       'temperature' => 0.3,
 *       'provider'    => 'anthropic',  // override del proveedor activo
 *       'system'      => 'Eres un asistente educativo.',
 *   ]);
 *
 * Retorna: string con el texto generado | WP_Error en caso de fallo.
 */
class CLMS_AI_Manager {

	// Legacy alias local. El owner de la option key vive en CLMS_Settings.
	const OPTION_KEY = 'clms_ai_settings';

	const ENDPOINTS = array(
		'openai'    => 'https://api.openai.com/v1/chat/completions',
		'anthropic' => 'https://api.anthropic.com/v1/messages',
		'deepseek'  => 'https://api.deepseek.com/chat/completions',
	);
	const EMBEDDINGS_ENDPOINT = 'https://api.openai.com/v1/embeddings';
	const WHISPER_ENDPOINT    = 'https://api.openai.com/v1/audio/transcriptions';

	const DEFAULT_MODELS = array(
		'openai'    => 'gpt-4o-mini',
		'anthropic' => 'claude-sonnet-4-6',
		'gemini'    => 'gemini-2.5-flash',
		'deepseek'  => 'deepseek-v4-flash',
	);
	const DEFAULT_EMBED_MODEL = 'text-embedding-3-small';

	const DEFAULT_MAX_TOKENS  = 512;
	const DEFAULT_TEMPERATURE = 0.2;
	const DEFAULT_TIMEOUT     = 45;

	/** @var array|null Caché de settings */
	private $settings_cache = null;

	// ── Punto de entrada principal ────────────────────────────────────────────────

	/**
	 * Envía una conversación al proveedor activo (o al indicado en $options).
	 *
	 * @param array $messages Array de mensajes: [['role'=>'user','content'=>'...'], ...]
	 * @param array $options  Opciones: max_tokens, temperature, provider, system, model, timeout
	 * @return string|WP_Error Texto generado o WP_Error.
	 */
	public function chat( array $messages, array $options = array() ) {
		return $this->request_chat( $messages, $options, false );
	}

	/**
	 * Envía una conversación y devuelve texto + metadatos.
	 *
	 * @param array $messages Array de mensajes: [['role'=>'user','content'=>'...'], ...]
	 * @param array $options  Opciones: max_tokens, temperature, provider, system, model, timeout, api_key
	 * @return array|WP_Error {text, usage, raw, provider, model}
	 */
	public function chat_with_meta( array $messages, array $options = array() ) {
		return $this->request_chat( $messages, $options, true );
	}

	/**
	 * Motor interno para chat (texto o meta).
	 *
	 * @param array $messages
	 * @param array $options
	 * @param bool  $return_meta
	 * @return string|array|WP_Error
	 */
	private function request_chat( array $messages, array $options, $return_meta ) {
		if ( empty( $messages ) ) {
			$error = new WP_Error( 'clms_ai_manager_empty_messages', __( 'No se proporcionaron mensajes.', 'atora-lms' ) );
			$this->report_ai_error( $error, '', array( 'source' => 'chat' ) );
			return $error;
		}

		$provider = isset( $options['provider'] ) && $options['provider']
			? sanitize_key( $options['provider'] )
			: $this->get_active_provider_slug();

		$api_key = '';
		if ( ! empty( $options['api_key'] ) ) {
			$api_key = trim( (string) $options['api_key'] );
		}
		if ( '' === $api_key ) {
			$api_key = $this->get_api_key( $provider );
		}

		if ( ! $api_key ) {
			$error = new WP_Error(
				'clms_ai_manager_no_key',
				sprintf( 'No hay API key configurada para el proveedor "%s".', $provider )
			);
			$this->report_ai_error( $error, $provider, array( 'source' => 'chat', 'reason' => 'missing_api_key' ) );
			return $error;
		}

		$model      = isset( $options['model'] ) && $options['model']
			? sanitize_text_field( $options['model'] )
			: $this->get_model( $provider );
		$max_tokens = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : self::DEFAULT_MAX_TOKENS;
		$temp       = isset( $options['temperature'] ) ? (float) $options['temperature'] : self::DEFAULT_TEMPERATURE;
		$timeout    = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : self::DEFAULT_TIMEOUT;
		$system     = isset( $options['system'] ) ? (string) $options['system'] : '';

		$result = $this->call_provider( $provider, $api_key, $model, $messages, $system, $max_tokens, $temp, $timeout, $return_meta );
		if ( is_wp_error( $result ) && $this->should_retry( $result ) ) {
			usleep( 200000 );
			$result = $this->call_provider( $provider, $api_key, $model, $messages, $system, $max_tokens, $temp, $timeout, $return_meta );
		}

		if ( is_wp_error( $result ) ) {
			$this->report_ai_error(
				$result,
				$provider,
				array(
					'source'     => 'chat',
					'model'      => $model,
					'message_count' => count( $messages ),
				)
			);
		}

		if ( ! is_wp_error( $result ) ) {
			$usage = array();
			if ( $return_meta && is_array( $result ) && isset( $result['usage'] ) && is_array( $result['usage'] ) ) {
				$usage = $result['usage'];
			}
			do_action(
				'clms_ai_request_completed',
				array(
					'provider'      => $provider,
					'model'         => $model,
					'message_count' => count( $messages ),
					'usage'         => $usage,
					'source'        => isset( $options['source'] ) ? sanitize_key( (string) $options['source'] ) : 'chat',
				)
			);
		}

		if ( $return_meta && is_array( $result ) ) {
			$result['provider'] = $provider;
			$result['model']    = $model;
		}

		return $result;
	}

	/**
	 * Despacha la llamada al proveedor.
	 */
	private function call_provider( $provider, $api_key, $model, $messages, $system, $max_tokens, $temperature, $timeout, $return_meta ) {
		switch ( $provider ) {
			case 'anthropic':
				return $this->call_anthropic( $api_key, $model, $messages, $system, $max_tokens, $temperature, $timeout, $return_meta );

			case 'gemini':
				return $this->call_gemini( $api_key, $model, $messages, $system, $max_tokens, $temperature, $timeout, $return_meta );

			case 'deepseek':
				return $this->call_deepseek( $api_key, $model, $messages, $system, $max_tokens, $temperature, $timeout, $return_meta );

			default:
				return $this->call_openai( $api_key, $model, $messages, $system, $max_tokens, $temperature, $timeout, $return_meta );
		}
	}

	// ── Acceso a configuración ────────────────────────────────────────────────────

	/**
	 * Slug del proveedor activo según ajustes.
	 *
	 * @return string
	 */
	public function get_active_provider_slug() {
		$settings = $this->get_settings();
		$slug     = isset( $settings['provider'] ) ? sanitize_key( $settings['provider'] ) : 'openai';

		return $this->normalize_provider( $slug );
	}

	/**
	 * Devuelve la API key para el proveedor indicado.
	 *
	 * @param string $provider openai|anthropic|gemini
	 * @return string
	 */
	public function get_api_key( $provider ) {
		if ( class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_provider_api_key' ) ) {
			return (string) CLMS_AI_Settings_Service::get_provider_api_key( (string) $provider );
		}

		$provider = $this->normalize_provider( $provider );
		$settings = $this->get_settings();
		$map      = array(
			'openai'    => 'openai_api_key',
			'anthropic' => 'anthropic_api_key',
			'gemini'    => 'gemini_api_key',
		);
		$key_field = isset( $map[ $provider ] ) ? $map[ $provider ] : 'openai_api_key';
		$key = trim( (string) ( $settings[ $key_field ] ?? '' ) );

		if ( ! $key && 'openai' === $provider ) {
			$legacy = get_option( 'clms_openai_api_key', '' );
			$key    = trim( (string) $legacy );
		}

		return $key;
	}

	/**
	 * Devuelve el modelo configurado para el proveedor indicado.
	 *
	 * @param string $provider openai|anthropic|gemini
	 * @return string
	 */
	public function get_model( $provider ) {
		if ( class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_provider_model' ) ) {
			return (string) CLMS_AI_Settings_Service::get_provider_model( (string) $provider );
		}

		$provider  = $this->normalize_provider( $provider );
		$settings  = $this->get_settings();
		$defaults  = $this->get_default_models();
		$map       = array(
			'openai'    => 'openai_model',
			'anthropic' => 'anthropic_model',
			'gemini'    => 'gemini_model',
		);
		$model_field = isset( $map[ $provider ] ) ? $map[ $provider ] : 'openai_model';
		$model       = trim( (string) ( $settings[ $model_field ] ?? '' ) );
		return $model ?: ( $defaults[ $provider ] ?? 'gpt-4o-mini' );
	}

	/**
	 * Indica si hay al menos un proveedor operativo configurado.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return (bool) $this->get_api_key( $this->get_active_provider_slug() );
	}

	/**
	 * Devuelve la API key para Whisper (si existe).
	 *
	 * @return string
	 */
	public function get_whisper_api_key() {
		if ( class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_whisper_api_key' ) ) {
			return (string) CLMS_AI_Settings_Service::get_whisper_api_key();
		}

		$settings = $this->get_settings();
		return trim( (string) ( $settings['whisper_api_key'] ?? '' ) );
	}

	/**
	 * POST JSON genérico para integraciones IA (centralizado).
	 *
	 * @param string $url
	 * @param array  $headers
	 * @param array  $body
	 * @param int    $timeout
	 * @param array  $context
	 * @return array|WP_Error
	 */
	public function post_json( $url, $headers, $body, $timeout = 60, $context = array() ) {
		$url     = esc_url_raw( (string) $url );
		$headers = is_array( $headers ) ? $headers : array();
		$body    = is_array( $body ) ? $body : array();
		$context = is_array( $context ) ? $context : array();

		if ( ! $url ) {
			$error = new WP_Error( 'clms_ai_manager_invalid_url', __( 'URL inválida para la solicitud IA.', 'atora-lms' ) );
			$this->report_ai_error( $error, $context['provider'] ?? '', array_merge( array( 'source' => 'post_json' ), $context ) );
			return $error;
		}

		$headers = array_merge(
			array(
				'Content-Type' => 'application/json; charset=utf-8',
				'Accept'       => 'application/json',
			),
			$headers
		);

		$encoded = wp_json_encode( $body );
		if ( false === $encoded || null === $encoded ) {
			$error = new WP_Error( 'clms_ai_manager_invalid_body', __( 'No se pudo serializar el body para la solicitud IA.', 'atora-lms' ) );
			$this->report_ai_error( $error, $context['provider'] ?? '', array_merge( array( 'source' => 'post_json' ), $context ) );
			return $error;
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => max( 5, absint( $timeout ) ),
				'headers' => $headers,
				'body'    => $encoded,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->report_ai_error( $response, $context['provider'] ?? '', array_merge( array( 'source' => 'post_json' ), $context ) );
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 ) {
			$msg = '';
			if ( is_array( $data ) ) {
				$msg = $data['error']['message']
					?? $data['error_description']
					?? $data['message']
					?? '';
			}
			$error = new WP_Error(
				'clms_ai_manager_http_error',
				__( 'Error HTTP en solicitud IA.', 'atora-lms' ) . ( $msg ? ' ' . $msg : '' ),
				array(
					'status'   => $status,
					'body_raw' => $raw,
					'body'     => is_array( $data ) ? $data : array(),
				)
			);
			$this->report_ai_error( $error, $context['provider'] ?? '', array_merge( array( 'source' => 'post_json' ), $context ) );
			return $error;
		}

		if ( ! is_array( $data ) ) {
			$error = new WP_Error( 'clms_ai_manager_invalid_json', __( 'Respuesta JSON inválida en solicitud IA.', 'atora-lms' ) );
			$this->report_ai_error( $error, $context['provider'] ?? '', array_merge( array( 'source' => 'post_json' ), $context ) );
			return $error;
		}

		return array(
			'status' => $status,
			'body'   => $data,
			'raw'    => $raw,
		);
	}

	/**
	 * Embeddings OpenAI (batch).
	 *
	 * @param array $inputs
	 * @param array $options
	 * @return array|WP_Error
	 */
	public function create_embeddings( array $inputs, array $options = array() ) {
		if ( empty( $inputs ) ) {
			return array();
		}

		$api_key = ! empty( $options['api_key'] ) ? trim( (string) $options['api_key'] ) : $this->get_api_key( 'openai' );
		if ( ! $api_key ) {
			$error = new WP_Error( 'clms_ai_manager_no_key', __( 'No hay API key configurada para embeddings.', 'atora-lms' ) );
			$this->report_ai_error( $error, 'openai', array( 'source' => 'embeddings' ) );
			return $error;
		}

		$model   = ! empty( $options['model'] ) ? sanitize_text_field( (string) $options['model'] ) : self::DEFAULT_EMBED_MODEL;
		$timeout = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 60;

		$result = $this->post_json(
			self::EMBEDDINGS_ENDPOINT,
			array( 'Authorization' => 'Bearer ' . $api_key ),
			array(
				'model' => $model,
				'input' => array_values( $inputs ),
			),
			$timeout,
			array(
				'provider' => 'openai',
				'model'    => $model,
				'source'   => 'embeddings',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$body = $result['body'] ?? array();
		if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return new WP_Error( 'clms_ai_manager_embeddings_empty', __( 'OpenAI devolvió embeddings vacíos.', 'atora-lms' ) );
		}

		$embeddings = array();
		foreach ( $body['data'] as $item ) {
			if ( isset( $item['embedding'] ) && is_array( $item['embedding'] ) ) {
				$embeddings[] = $item['embedding'];
			}
		}

		return $embeddings;
	}

	/**
	 * Embedding de una sola consulta.
	 *
	 * @param string $text
	 * @param array  $options
	 * @return array|WP_Error
	 */
	public function create_query_embedding( $text, array $options = array() ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'clms_ai_manager_empty_query', __( 'Consulta vacía para embeddings.', 'atora-lms' ) );
		}

		$result = $this->create_embeddings( array( mb_substr( $text, 0, 8000 ) ), $options );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result[0] ) ? $result[0] : new WP_Error( 'clms_ai_manager_embeddings_empty', __( 'No se pudo generar el embedding.', 'atora-lms' ) );
	}

	/**
	 * Transcripción de audio (Whisper).
	 *
	 * @param string $file_path
	 * @param array  $options {language, response_format, model, api_key, timeout, use_curl}
	 * @return array|WP_Error
	 */
	public function transcribe_audio( $file_path, array $options = array() ) {
		$file_path = (string) $file_path;

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'clms_ai_manager_audio_missing', __( 'Archivo de audio no encontrado.', 'atora-lms' ) );
		}

		$api_key = ! empty( $options['api_key'] ) ? trim( (string) $options['api_key'] ) : $this->get_whisper_api_key();
		if ( ! $api_key ) {
			return new WP_Error( 'clms_ai_manager_no_key', __( 'API key de Whisper no configurada.', 'atora-lms' ) );
		}

		$model          = ! empty( $options['model'] ) ? sanitize_text_field( (string) $options['model'] ) : 'whisper-1';
		$language       = ! empty( $options['language'] ) ? sanitize_text_field( (string) $options['language'] ) : '';
		$response_format = ! empty( $options['response_format'] ) ? sanitize_text_field( (string) $options['response_format'] ) : 'json';
		$timeout        = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 300;
		$use_curl        = ! empty( $options['use_curl'] ) && function_exists( 'curl_init' );

		if ( $use_curl ) {
			$ch = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions
			curl_setopt_array( $ch, array( // phpcs:ignore WordPress.WP.AlternativeFunctions
				CURLOPT_URL            => self::WHISPER_ENDPOINT,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_POST           => true,
				CURLOPT_HTTPHEADER     => array(
					'Authorization: Bearer ' . $api_key,
				),
				CURLOPT_POSTFIELDS => array(
					'file'            => new CURLFile( $file_path ),
					'model'           => $model,
					'language'        => $language,
					'response_format' => $response_format,
				),
			) );

			$body  = curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$errno = curl_errno( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( $errno ) {
				return new WP_Error( 'clms_ai_manager_curl_error', sprintf( __( 'Error de cURL: %s', 'atora-lms' ), $errno ) );
			}

			$data = json_decode( (string) $body, true );
			return $this->parse_whisper_payload( $data );
		}

		$contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $contents ) {
			return new WP_Error( 'clms_ai_manager_file_read', __( 'No se pudo leer el archivo de audio.', 'atora-lms' ) );
		}

		$boundary = '----------' . wp_generate_password( 20, false );
		$filename = basename( $file_path );
		$mime     = function_exists( 'mime_content_type' ) ? ( mime_content_type( $file_path ) ?: 'audio/mpeg' ) : 'audio/mpeg';

		$body  = "--{$boundary}\r\n";
		$body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
		$body .= "Content-Type: {$mime}\r\n\r\n";
		$body .= $contents;
		$body .= "\r\n--{$boundary}\r\n";
		$body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n{$model}\r\n";
		if ( $language ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"language\"\r\n\r\n{$language}\r\n";
		}
		$body .= "--{$boundary}\r\n";
		$body .= "Content-Disposition: form-data; name=\"response_format\"\r\n\r\n{$response_format}\r\n";
		$body .= "--{$boundary}--\r\n";

		$response = wp_remote_post(
			self::WHISPER_ENDPOINT,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body' => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( (string) $raw, true );

		return $this->parse_whisper_payload( $data, wp_remote_retrieve_response_code( $response ) );
	}

	// ── Llamadas por proveedor ────────────────────────────────────────────────────

	/**
	 * @param string $api_key
	 * @param string $model
	 * @param array  $messages
	 * @param string $system
	 * @param int    $max_tokens
	 * @param float  $temperature
	 * @param int    $timeout
	 * @return string|WP_Error
	 */
	private function call_openai( $api_key, $model, $messages, $system, $max_tokens, $temperature, $timeout, $return_meta = false ) {
		$payload = array(
			'model'       => $model,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
			'messages'    => $messages,
		);

		if ( $system ) {
			array_unshift( $payload['messages'], array( 'role' => 'system', 'content' => $system ) );
		}

		$response = wp_remote_post(
			self::ENDPOINTS['openai'],
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode( $payload ),
			)
		);

		if ( ! $return_meta ) {
			return $this->extract_text_openai( $response );
		}

		$parsed = $this->parse_http_response( $response, 'openai' );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$text = $parsed['choices'][0]['message']['content'] ?? null;
		if ( null === $text ) {
			return new WP_Error( 'clms_ai_manager_empty_openai', __( 'OpenAI devolvió respuesta vacía.', 'atora-lms' ) );
		}

		return array(
			'text'  => trim( (string) $text ),
			'usage' => isset( $parsed['usage'] ) && is_array( $parsed['usage'] ) ? $parsed['usage'] : array(),
			'raw'   => $parsed,
		);
	}

	/**
	 * DeepSeek — OpenAI-compatible transport, different endpoint/key.
	 *
	 * @param string $api_key
	 * @param string $model
	 * @param array  $messages
	 * @param string $system
	 * @param int    $max_tokens
	 * @param float  $temperature
	 * @param int    $timeout
	 * @param bool   $return_meta
	 * @return string|array|WP_Error
	 */
	private function call_deepseek( $api_key, $model, $messages, $system, $max_tokens, $temperature, $timeout, $return_meta = false ) {
		$payload = array(
			'model'       => $model,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
			'messages'    => $messages,
		);

		if ( $system ) {
			array_unshift( $payload['messages'], array( 'role' => 'system', 'content' => $system ) );
		}

		$response = wp_remote_post(
			self::ENDPOINTS['deepseek'],
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode( $payload ),
			)
		);

		if ( ! $return_meta ) {
			return $this->extract_text_openai( $response );
		}

		$parsed = $this->parse_http_response( $response, 'deepseek' );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$text = $parsed['choices'][0]['message']['content'] ?? null;
		if ( null === $text ) {
			return new WP_Error( 'clms_ai_manager_empty_deepseek', __( 'DeepSeek devolvió respuesta vacía.', 'atora-lms' ) );
		}

		return array(
			'text'  => trim( (string) $text ),
			'usage' => isset( $parsed['usage'] ) && is_array( $parsed['usage'] ) ? $parsed['usage'] : array(),
			'raw'   => $parsed,
		);
	}

	/**
	 * @param string $api_key
	 * @param string $model
	 * @param array  $messages
	 * @param string $system
	 * @param int    $max_tokens
	 * @param float  $temperature
	 * @param int    $timeout
	 * @return string|WP_Error
	 */
	private function call_anthropic( $api_key, $model, $messages, $system, $max_tokens, $temperature, $timeout, $return_meta = false ) {
		$payload = array(
			'model'       => $model,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
			'messages'    => $messages,
		);

		if ( $system ) {
			$payload['system'] = $system;
		}

		$response = wp_remote_post(
			self::ENDPOINTS['anthropic'],
			array(
				'timeout' => $timeout,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				'body' => wp_json_encode( $payload ),
			)
		);

		if ( ! $return_meta ) {
			return $this->extract_text_anthropic( $response );
		}

		$parsed = $this->parse_http_response( $response, 'anthropic' );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$text = $parsed['content'][0]['text'] ?? null;
		if ( null === $text ) {
			return new WP_Error( 'clms_ai_manager_empty_anthropic', __( 'Anthropic devolvió respuesta vacía.', 'atora-lms' ) );
		}

		return array(
			'text'  => trim( (string) $text ),
			'usage' => isset( $parsed['usage'] ) && is_array( $parsed['usage'] ) ? $parsed['usage'] : array(),
			'raw'   => $parsed,
		);
	}

	/**
	 * @param string $api_key
	 * @param string $model
	 * @param array  $messages
	 * @param string $system
	 * @param int    $max_tokens
	 * @param float  $temperature
	 * @param int    $timeout
	 * @return string|WP_Error
	 */
	private function call_gemini( $api_key, $model, $messages, $system, $max_tokens, $temperature, $timeout, $return_meta = false ) {
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/'
			. rawurlencode( $model )
			. ':generateContent?key='
			. rawurlencode( $api_key );

		$contents = array();

		if ( $system ) {
			$contents[] = array(
				'role'  => 'user',
				'parts' => array( array( 'text' => $system ) ),
			);
			$contents[] = array(
				'role'  => 'model',
				'parts' => array( array( 'text' => 'Entendido.' ) ),
			);
		}

		foreach ( $messages as $msg ) {
			$role     = isset( $msg['role'] ) && 'assistant' === $msg['role'] ? 'model' : 'user';
			$contents[] = array(
				'role'  => $role,
				'parts' => array( array( 'text' => (string) ( $msg['content'] ?? '' ) ) ),
			);
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'contents'         => $contents,
					'generationConfig' => array(
						'maxOutputTokens' => $max_tokens,
						'temperature'     => $temperature,
					),
				) ),
			)
		);

		if ( ! $return_meta ) {
			return $this->extract_text_gemini( $response );
		}

		$parsed = $this->parse_http_response( $response, 'gemini' );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$text = $parsed['candidates'][0]['content']['parts'][0]['text'] ?? null;
		if ( null === $text ) {
			return new WP_Error( 'clms_ai_manager_empty_gemini', __( 'Gemini devolvió respuesta vacía.', 'atora-lms' ) );
		}

		return array(
			'text'  => trim( (string) $text ),
			'usage' => isset( $parsed['usage'] ) && is_array( $parsed['usage'] ) ? $parsed['usage'] : array(),
			'raw'   => $parsed,
		);
	}

	// ── Parsers de respuesta ──────────────────────────────────────────────────────

	/**
	 * @param array|WP_Error $response
	 * @return string|WP_Error
	 */
	private function extract_text_openai( $response ) {
		$parsed = $this->parse_http_response( $response, 'openai' );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$text = $parsed['choices'][0]['message']['content'] ?? null;
		if ( null === $text ) {
			return new WP_Error( 'clms_ai_manager_empty_openai', __( 'OpenAI devolvió respuesta vacía.', 'atora-lms' ) );
		}
		return trim( (string) $text );
	}

	/**
	 * @param array|WP_Error $response
	 * @return string|WP_Error
	 */
	private function extract_text_anthropic( $response ) {
		$parsed = $this->parse_http_response( $response, 'anthropic' );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$text = $parsed['content'][0]['text'] ?? null;
		if ( null === $text ) {
			return new WP_Error( 'clms_ai_manager_empty_anthropic', __( 'Anthropic devolvió respuesta vacía.', 'atora-lms' ) );
		}
		return trim( (string) $text );
	}

	/**
	 * @param array|WP_Error $response
	 * @return string|WP_Error
	 */
	private function extract_text_gemini( $response ) {
		$parsed = $this->parse_http_response( $response, 'gemini' );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		$text = $parsed['candidates'][0]['content']['parts'][0]['text'] ?? null;
		if ( null === $text ) {
			return new WP_Error( 'clms_ai_manager_empty_gemini', __( 'Gemini devolvió respuesta vacía.', 'atora-lms' ) );
		}
		return trim( (string) $text );
	}

	/**
	 * Parsea respuesta de Whisper.
	 *
	 * @param mixed $data
	 * @param int   $http_code
	 * @return array|WP_Error
	 */
	private function parse_whisper_payload( $data, $http_code = 200 ) {
		$http_code = (int) $http_code;

		if ( $http_code < 200 || $http_code >= 300 ) {
			$message = '';
			if ( is_array( $data ) ) {
				$message = $data['error']['message'] ?? '';
			}
			return new WP_Error(
				'clms_ai_manager_whisper_error',
				__( 'Whisper devolvió un error.', 'atora-lms' ) . ( $message ? ' ' . $message : '' )
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'clms_ai_manager_whisper_invalid', __( 'Respuesta de Whisper no válida.', 'atora-lms' ) );
		}

		$text = isset( $data['text'] ) ? trim( (string) $data['text'] ) : '';
		if ( '' === $text ) {
			return new WP_Error( 'clms_ai_manager_whisper_empty', __( 'Whisper no devolvió texto.', 'atora-lms' ) );
		}

		return array(
			'text'     => $text,
			'language' => isset( $data['language'] ) ? sanitize_text_field( (string) $data['language'] ) : '',
			'raw'      => $data,
		);
	}

	/**
	 * Valida la respuesta HTTP y decodifica el JSON.
	 *
	 * @param array|WP_Error $response  Respuesta de wp_remote_post.
	 * @param string         $provider  Nombre del proveedor (para mensajes de error).
	 * @return array|WP_Error
	 */
	private function parse_http_response( $response, $provider ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'clms_ai_manager_http_' . $provider,
				sprintf( 'Error de conexión con %s: %s', $provider, $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 ) {
			$api_msg = '';
			if ( is_array( $data ) ) {
				$api_msg = $data['error']['message']
					?? $data['error_description']
					?? $data['message']
					?? '';
			}
			return new WP_Error(
				'clms_ai_manager_api_error_' . $provider,
				sprintf(
					'Error HTTP %d desde %s%s',
					$status,
					$provider,
					$api_msg ? ': ' . $api_msg : '.'
				),
				array( 'status' => $status, 'body' => $data )
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'clms_ai_manager_invalid_json_' . $provider,
				sprintf( '%s devolvió JSON inválido.', $provider )
			);
		}

		return $data;
	}

	// ── Internos ──────────────────────────────────────────────────────────────────

	/**
	 * Devuelve los ajustes cacheados.
	 *
	 * @return array
	 */
	private function get_settings() {
		if ( null === $this->settings_cache ) {
			if ( class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_settings' ) ) {
				$this->settings_cache = (array) CLMS_AI_Settings_Service::get_settings();
			} elseif ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_ai_settings' ) ) {
				$this->settings_cache = (array) CLMS_Settings::get_ai_settings();
			} else {
				$raw                  = get_option( $this->get_ai_option_key(), array() );
				$this->settings_cache = $this->normalize_settings_fallback( $raw );
			}
		}
		return $this->settings_cache;
	}

	/**
	 * Normaliza el slug del proveedor a una lista permitida.
	 *
	 * @param string $provider Slug de proveedor.
	 * @return string
	 */
	private function normalize_provider( $provider ) {
		$provider = sanitize_key( (string) $provider );
		$allowed  = array( 'openai', 'anthropic', 'gemini', 'deepseek' );

		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_allowed_ai_providers' ) ) {
			$allowed = (array) CLMS_Settings::get_allowed_ai_providers();
		}

		return in_array( $provider, $allowed, true ) ? $provider : 'openai';
	}

	/**
	 * Mapa de modelos por defecto.
	 *
	 * @return array<string,string>
	 */
	private function get_default_models() {
		$defaults = self::DEFAULT_MODELS;
		$settings_defaults = $this->get_settings_defaults();
		$defaults = array(
			'openai'    => isset( $settings_defaults['openai_model'] ) ? (string) $settings_defaults['openai_model'] : $defaults['openai'],
			'anthropic' => isset( $settings_defaults['anthropic_model'] ) ? (string) $settings_defaults['anthropic_model'] : $defaults['anthropic'],
			'gemini'    => isset( $settings_defaults['gemini_model'] ) ? (string) $settings_defaults['gemini_model'] : $defaults['gemini'],
			'deepseek'  => isset( $settings_defaults['deepseek_model'] ) ? (string) $settings_defaults['deepseek_model'] : self::DEFAULT_MODELS['deepseek'],
		);

		return $defaults;
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
	 * Defaults de settings AI desde el owner único (con fallback local).
	 *
	 * @return array
	 */
	private function get_settings_defaults() {
		if ( class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_defaults' ) ) {
			return (array) CLMS_AI_Settings_Service::get_defaults();
		}

		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_ai_defaults' ) ) {
			return (array) CLMS_Settings::get_ai_defaults();
		}

		return array(
			'provider'          => 'openai',
			'openai_api_key'    => '',
			'openai_model'      => self::DEFAULT_MODELS['openai'],
			'anthropic_api_key' => '',
			'anthropic_model'   => self::DEFAULT_MODELS['anthropic'],
			'gemini_api_key'    => '',
			'gemini_model'      => self::DEFAULT_MODELS['gemini'],
			'whisper_api_key'   => '',
		);
	}

	/**
	 * Normaliza settings cuando CLMS_Settings no está disponible.
	 *
	 * @param mixed $raw Valor leído desde option.
	 * @return array
	 */
	private function normalize_settings_fallback( $raw ) {
		$raw      = is_array( $raw ) ? $raw : array();
		$defaults = $this->get_settings_defaults();
		$data     = array_merge( $defaults, $raw );

		$data['provider'] = $this->normalize_provider( $data['provider'] ?? 'openai' );

		foreach ( array( 'openai_api_key', 'anthropic_api_key', 'gemini_api_key', 'whisper_api_key' ) as $field ) {
			$value         = sanitize_text_field( (string) ( $data[ $field ] ?? '' ) );
			$value         = preg_replace( '/\s+/', '', $value );
			$data[ $field ] = trim( (string) $value );
		}

		$model_defaults = array(
			'openai_model'    => (string) ( $defaults['openai_model'] ?? self::DEFAULT_MODELS['openai'] ),
			'anthropic_model' => (string) ( $defaults['anthropic_model'] ?? self::DEFAULT_MODELS['anthropic'] ),
			'gemini_model'    => (string) ( $defaults['gemini_model'] ?? self::DEFAULT_MODELS['gemini'] ),
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

		return $data;
	}

	private function report_ai_error( $error, $provider = '', $context = array() ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}

		do_action(
			'clms_ai_error',
			array_merge(
				array(
					'code'     => $error->get_error_code(),
					'message'  => $error->get_error_message(),
					'provider' => sanitize_key( (string) $provider ),
				),
				is_array( $context ) ? $context : array()
			)
		);
	}

	/**
	 * Decide si reintentar ante error transitorio.
	 *
	 * @param WP_Error $error
	 * @return bool
	 */
	private function should_retry( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			return false;
		}

		$status = isset( $data['status'] ) ? (int) $data['status'] : 0;
		return in_array( $status, array( 429, 500, 502, 503, 504 ), true );
	}
}
