<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_AI_Provider_Gemini extends CLMS_AI_Provider_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gemini';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_label() {
		return 'Gemini';
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured() {
		return '' !== trim( (string) $this->get_option( 'gemini_api_key', '' ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_review( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();

		$api_key = trim( (string) $this->get_option( 'gemini_api_key', '' ) );
		$model   = trim( (string) $this->get_option( 'gemini_model', 'gemini-2.5-flash' ) );

		if ( '' === $api_key ) {
			return new WP_Error( 'clms_ai_gemini_missing_key', __( 'Falta la API key de Gemini.', 'atora-lms' ) );
		}

		if ( '' === $model ) {
			return new WP_Error( 'clms_ai_gemini_missing_model', __( 'No se definió un modelo de Gemini.', 'atora-lms' ) );
		}

		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $api_key );

		$schema = array(
			'type'       => 'OBJECT',
			'properties' => array(
				'status'           => array(
					'type' => 'STRING',
					'enum' => array( 'completed', 'failed' ),
				),
				'summary'          => array( 'type' => 'STRING' ),
				'feedback_draft'   => array( 'type' => 'STRING' ),
				// Gemini es estricto con tipos mixtos; usamos STRING y normalizamos en PHP.
				'score_suggestion' => array( 'type' => 'STRING' ),
				'criteria_scores'  => array(
					'type'  => 'ARRAY',
					'items' => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'criterion'  => array( 'type' => 'STRING' ),
							'score'      => array( 'type' => 'STRING' ),
							'max_points' => array( 'type' => 'INTEGER' ),
							'rationale'  => array( 'type' => 'STRING' ),
						),
					),
				),
				'raw'              => array(
					'type'       => 'OBJECT',
					'properties' => array(),
				),
				'updated_at'       => array( 'type' => 'STRING' ),
				'error'            => array( 'type' => 'STRING' ),
				'source_files'     => array(
					'type'  => 'ARRAY',
					'items' => array( 'type' => 'INTEGER' ),
				),
				'highlights'       => array(
					'type'  => 'ARRAY',
					'items' => array( 'type' => 'STRING' ),
				),
			),
			'required'   => array(
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
			'contents' => array(
				array(
					'parts' => array(
						array(
							'text' => $this->get_contract_prompt() . "\n\n" . $this->build_unified_prompt( $payload ),
						),
					),
				),
			),
			'generationConfig' => array(
				'responseMimeType' => 'application/json',
				'responseSchema'   => $schema,
			),
		);

		$response = $this->post_json(
			$url,
			array(),
			$body,
			90
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $this->extract_gemini_json( isset( $response['body'] ) && is_array( $response['body'] ) ? $response['body'] : array() );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['raw'] = array(
			'provider'    => 'gemini',
			'model'       => $model,
			'status_code' => isset( $response['status'] ) ? absint( $response['status'] ) : 0,
			'finish'      => isset( $response['body']['candidates'][0]['finishReason'] ) ? sanitize_text_field( $response['body']['candidates'][0]['finishReason'] ) : '',
		);

		$normalized = $this->normalize_contract( $data );

		if ( $this->is_empty_review_result( $normalized ) ) {
			$normalized['status']     = 'failed';
			$normalized['error']      = 'Gemini devolvió una respuesta estructurada pero sin contenido utilizable.';
			$normalized['updated_at'] = current_time( 'mysql' );
		}

		return $normalized;
	}

	/**
	 * Extrae JSON usable desde Gemini.
	 *
	 * @param array $body Respuesta decodificada.
	 * @return array|WP_Error
	 */
	protected function extract_gemini_json( $body ) {
		$body = is_array( $body ) ? $body : array();

		if ( empty( $body['candidates'] ) || ! is_array( $body['candidates'] ) ) {
			return new WP_Error( 'clms_ai_gemini_empty', __( 'Gemini devolvió una respuesta vacía.', 'atora-lms' ) );
		}

		$candidates = array();

		foreach ( $body['candidates'] as $candidate ) {
			if ( empty( $candidate['content']['parts'] ) || ! is_array( $candidate['content']['parts'] ) ) {
				continue;
			}

			foreach ( $candidate['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$candidates[] = trim( $part['text'] );
				}
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

		return new WP_Error( 'clms_ai_gemini_parse_error', __( 'No se pudo parsear la respuesta de Gemini.', 'atora-lms' ) );
	}
}