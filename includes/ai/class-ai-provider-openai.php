<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_AI_Provider_OpenAI extends CLMS_AI_Provider_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'openai';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label() {
		return 'OpenAI';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured() {
		return '' !== trim( (string) $this->get_option( 'openai_api_key', '' ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_review( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();

		$api_key = trim( (string) $this->get_option( 'openai_api_key', '' ) );
		$model   = trim( (string) $this->get_option( 'openai_model', 'gpt-4o-mini' ) );

		if ( '' === $api_key ) {
			return new WP_Error( 'clms_ai_openai_missing_key', __( 'Falta la API key de OpenAI.', 'atora-lms' ) );
		}

		if ( '' === $model ) {
			return new WP_Error( 'clms_ai_openai_missing_model', __( 'No se definió un modelo de OpenAI.', 'atora-lms' ) );
		}

		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'status'           => array(
					'type' => 'string',
					'enum' => array( 'completed', 'failed' ),
				),
				'summary'          => array( 'type' => 'string' ),
				'feedback_draft'   => array( 'type' => 'string' ),
				'score_suggestion' => array( 'type' => array( 'integer', 'string' ) ),
				'criteria_scores'  => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'criterion'  => array( 'type' => 'string' ),
							'score'      => array( 'type' => array( 'integer', 'string' ) ),
							'max_points' => array( 'type' => 'integer' ),
							'rationale'  => array( 'type' => 'string' ),
						),
						'required' => array( 'criterion', 'score', 'max_points', 'rationale' ),
					),
				),
				'raw'              => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'updated_at'       => array( 'type' => 'string' ),
				'error'            => array( 'type' => 'string' ),
				'source_files'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'highlights'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'             => array(
				'status',
				'summary',
				'feedback_draft',
				'score_suggestion',
				'criteria_scores',
				'raw',
				'updated_at',
				'error',
				'source_files',
				'highlights',
			),
		);

		$body = array(
			'model' => $model,
			'input' => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => $this->build_unified_prompt( $payload ),
						),
					),
				),
			),
			'text'  => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'clms_review',
					'strict' => true,
					'schema' => $schema,
				),
			),
		);

		$response = $this->post_json(
			'https://api.openai.com/v1/responses',
			array(
				'Authorization' => 'Bearer ' . $api_key,
			),
			$body,
			90
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->extract_openai_json( isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array() );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['raw'] = array(
			'provider'    => 'openai',
			'model'       => $model,
			'response_id' => isset( $response['body']['id'] ) ? sanitize_text_field( $response['body']['id'] ) : '',
			'status_code' => isset( $response['status'] ) ? absint( $response['status'] ) : 0,
		);

		$normalized = $this->normalize_contract( $data );

		if ( $this->is_empty_review_result( $normalized ) ) {
			$normalized['status']     = 'failed';
			$normalized['error']      = 'OpenAI devolvió una respuesta estructurada pero sin contenido utilizable.';
			$normalized['updated_at'] = current_time( 'mysql' );
		}

		return $normalized;
	}

	/**
	 * Extrae JSON usable desde Responses API.
	 *
	 * @param array $body Respuesta decodificada.
	 * @return array|WP_Error
	 */
	protected function extract_openai_json( $body ) {
		$body       = is_array( $body ) ? $body : array();
		$candidates = array();

		if ( isset( $body['output_text'] ) && is_string( $body['output_text'] ) ) {
			$candidates[] = $body['output_text'];
		}

		if ( isset( $body['output'] ) && is_array( $body['output'] ) ) {
			foreach ( $body['output'] as $item ) {
				if ( empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
					continue;
				}

				foreach ( $item['content'] as $content ) {
					if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
						$candidates[] = $content['text'];
					}
				}
			}
		}

		$candidates = array_values(
			array_filter(
				array_map(
					static function( $text ) {
						return trim( (string) $text );
					},
					$candidates
				)
			)
		);

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

		return new WP_Error( 'clms_ai_openai_parse_error', __( 'No se pudo parsear la respuesta de OpenAI.', 'atora-lms' ) );
	}
}