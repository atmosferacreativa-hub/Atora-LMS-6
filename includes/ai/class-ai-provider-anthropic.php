<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_AI_Provider_Anthropic extends CLMS_AI_Provider_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'anthropic';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label() {
		return 'Claude';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured() {
		return '' !== trim( (string) $this->get_option( 'anthropic_api_key', '' ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_review( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();

		$api_key = trim( (string) $this->get_option( 'anthropic_api_key', '' ) );
		$model   = trim( (string) $this->get_option( 'anthropic_model', 'claude-sonnet-4-6' ) );

		if ( '' === $api_key ) {
			return new WP_Error( 'clms_ai_anthropic_missing_key', __( 'Falta la API key de Anthropic.', 'atora-lms' ) );
		}

		if ( '' === $model ) {
			return new WP_Error( 'clms_ai_anthropic_missing_model', __( 'No se definió un modelo de Anthropic.', 'atora-lms' ) );
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => 1800,
			'system'     => $this->get_contract_prompt(),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $this->build_unified_prompt( $payload ),
				),
			),
		);

		$response = $this->post_json(
			'https://api.anthropic.com/v1/messages',
			array(
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			$body,
			90
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->extract_anthropic_json( isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array() );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['raw'] = array(
			'provider'    => 'anthropic',
			'model'       => $model,
			'response_id' => isset( $response['body']['id'] ) ? sanitize_text_field( $response['body']['id'] ) : '',
			'type'        => isset( $response['body']['type'] ) ? sanitize_text_field( $response['body']['type'] ) : '',
			'stop_reason' => isset( $response['body']['stop_reason'] ) ? sanitize_text_field( $response['body']['stop_reason'] ) : '',
			'status_code' => isset( $response['status'] ) ? absint( $response['status'] ) : 0,
		);

		$normalized = $this->normalize_contract( $data );

		if ( $this->is_empty_review_result( $normalized ) ) {
			$normalized['status']     = 'failed';
			$normalized['error']      = 'Anthropic devolvió una respuesta estructurada pero sin contenido utilizable.';
			$normalized['updated_at'] = current_time( 'mysql' );
		}

		return $normalized;
	}

	/**
	 * Extrae JSON usable desde Messages API.
	 *
	 * @param array $body Respuesta decodificada.
	 * @return array|WP_Error
	 */
	protected function extract_anthropic_json( $body ) {
		$body = is_array( $body ) ? $body : array();

		if ( empty( $body['content'] ) || ! is_array( $body['content'] ) ) {
			return new WP_Error( 'clms_ai_anthropic_empty', __( 'Anthropic devolvió una respuesta vacía.', 'atora-lms' ) );
		}

		$candidates = array();

		foreach ( $body['content'] as $chunk ) {
			if ( ! is_array( $chunk ) ) {
				continue;
			}

			if ( isset( $chunk['text'] ) && is_string( $chunk['text'] ) ) {
				$candidates[] = trim( $chunk['text'] );
			}
		}

		$candidates = array_values( array_filter( $candidates ) );

		foreach ( $candidates as $text ) {
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
		}

		return new WP_Error( 'clms_ai_anthropic_parse_error', __( 'No se pudo parsear la respuesta de Anthropic.', 'atora-lms' ) );
	}
}