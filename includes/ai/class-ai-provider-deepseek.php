<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_AI_Provider_DeepSeek extends CLMS_AI_Provider_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'deepseek';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label() {
		return 'DeepSeek';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured() {
		return '' !== trim( (string) $this->get_option( 'deepseek_api_key', '' ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_review( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();

		$api_key = trim( (string) $this->get_option( 'deepseek_api_key', '' ) );
		$model   = trim( (string) $this->get_option( 'deepseek_model', 'deepseek-v4-flash' ) );

		if ( '' === $api_key ) {
			return new WP_Error( 'clms_ai_deepseek_missing_key', __( 'Falta la API key de DeepSeek.', 'atora-lms' ) );
		}

		if ( '' === $model ) {
			return new WP_Error( 'clms_ai_deepseek_missing_model', __( 'No se definió un modelo de DeepSeek.', 'atora-lms' ) );
		}

		$body = array(
			'model'       => $model,
			'max_tokens'  => 1800,
			'temperature' => 0.2,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $this->get_contract_prompt(),
				),
				array(
					'role'    => 'user',
					'content' => $this->build_unified_prompt( $payload ),
				),
			),
		);

		$response = $this->post_json(
			'https://api.deepseek.com/chat/completions',
			array(
				'Authorization' => 'Bearer ' . $api_key,
			),
			$body,
			90
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->extract_deepseek_json( isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array() );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['raw'] = array(
			'provider'    => 'deepseek',
			'model'       => $model,
			'response_id' => isset( $response['body']['id'] ) ? sanitize_text_field( $response['body']['id'] ) : '',
			'status_code' => isset( $response['status'] ) ? absint( $response['status'] ) : 0,
		);

		$normalized = $this->normalize_contract( $data );

		if ( $this->is_empty_review_result( $normalized ) ) {
			$normalized['status']     = 'failed';
			$normalized['error']      = 'DeepSeek devolvió una respuesta estructurada pero sin contenido utilizable.';
			$normalized['updated_at'] = current_time( 'mysql' );
		}

		return $normalized;
	}

	/**
	 * Extrae JSON usable desde Chat Completions API (OpenAI-compatible).
	 *
	 * @param array $body Respuesta decodificada.
	 * @return array|WP_Error
	 */
	protected function extract_deepseek_json( $body ) {
		$body = is_array( $body ) ? $body : array();

		$text = $body['choices'][0]['message']['content'] ?? null;

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return new WP_Error( 'clms_ai_deepseek_empty', __( 'DeepSeek devolvió una respuesta vacía.', 'atora-lms' ) );
		}

		$text = trim( $text );

		$json = json_decode( $text, true );
		if ( is_array( $json ) ) {
			return $json;
		}

		$extracted = $this->extract_json_object_from_text( $text );
		if ( $extracted ) {
			$json = json_decode( $extracted, true );
			if ( is_array( $json ) ) {
				return $json;
			}
		}

		return new WP_Error( 'clms_ai_deepseek_parse_error', __( 'No se pudo parsear la respuesta de DeepSeek.', 'atora-lms' ) );
	}
}
