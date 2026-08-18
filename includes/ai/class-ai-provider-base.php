<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class CLMS_AI_Provider_Base implements CLMS_AI_Provider_Interface {

	/**
	 * Devuelve opción del plugin.
	 *
	 * @param string $key     Clave.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	protected function get_option( $key, $default = '' ) {
		$options = $this->get_ai_settings();

		if ( ! is_array( $options ) ) {
			$options = array();
		}

		return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
	}

	/**
	 * Devuelve settings AI desde el owner central cuando está disponible.
	 *
	 * @return array
	 */
	protected function get_ai_settings() {
		if ( class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_settings' ) ) {
			$settings = CLMS_AI_Settings_Service::get_settings();
			return is_array( $settings ) ? $settings : array();
		}

		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_ai_settings' ) ) {
			$settings = CLMS_Settings::get_ai_settings();
			return is_array( $settings ) ? $settings : array();
		}

		$settings = get_option( 'clms_ai_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Hace POST JSON simple.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $headers Headers.
	 * @param array  $body    Body.
	 * @param int    $timeout Timeout.
	 * @return array|WP_Error
	 */
	protected function post_json( $url, $headers, $body, $timeout = 60 ) {
		$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
		if ( $manager && method_exists( $manager, 'post_json' ) ) {
			return $manager->post_json(
				$url,
				$headers,
				$body,
				$timeout,
				array( 'source' => 'provider_base' )
			);
		}

		return new WP_Error( 'clms_ai_manager_missing', __( 'CLMS_AI_Manager no está disponible para solicitudes IA.', 'atora-lms' ) );
	}

	/**
	 * Construye el prompt unificado para revisión.
	 *
	 * @param array $payload Datos de la entrega.
	 * @return string
	 */
	protected function build_unified_prompt( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();

		$student_name   = isset( $payload['student_name'] ) ? sanitize_text_field( $payload['student_name'] ) : '';
		$lesson_title   = isset( $payload['lesson_title'] ) ? sanitize_text_field( $payload['lesson_title'] ) : '';
		$course_title   = isset( $payload['course_title'] ) ? sanitize_text_field( $payload['course_title'] ) : '';
		$teacher_notes  = isset( $payload['teacher_notes'] ) ? (string) $payload['teacher_notes'] : '';
		$student_note   = isset( $payload['comment'] ) ? (string) $payload['comment'] : '';
		$text_bundle    = isset( $payload['text_bundle'] ) && is_array( $payload['text_bundle'] ) ? $payload['text_bundle'] : array();
		$combined_text  = isset( $text_bundle['combined_text'] ) ? (string) $text_bundle['combined_text'] : '';
		$source_files   = isset( $text_bundle['source_files'] ) && is_array( $text_bundle['source_files'] ) ? $text_bundle['source_files'] : array();

		$file_lines = array();

		foreach ( $source_files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			$file_lines[] = sprintf(
				'- ID: %d | Nombre: %s',
				isset( $file['id'] ) ? absint( $file['id'] ) : 0,
				isset( $file['name'] ) ? sanitize_text_field( $file['name'] ) : ''
			);
		}

		$files_text = $file_lines ? implode( "\n", $file_lines ) : '- Sin archivos identificados';

		$rubric_text     = isset( $payload['rubric_text'] ) ? (string) $payload['rubric_text'] : '';
		$transcription   = isset( $payload['transcription'] ) ? trim( (string) $payload['transcription'] ) : '';

		$parts   = array();
		$parts[] = 'Contexto de revisión:';
		$parts[] = 'Curso: ' . $course_title;
		$parts[] = 'Lección: ' . $lesson_title;
		$parts[] = 'Estudiante: ' . $student_name;
		$parts[] = '';
		$parts[] = 'Notas del profesor:';
		$parts[] = $teacher_notes ? $teacher_notes : 'Sin notas del profesor.';
		$parts[] = '';
		$parts[] = 'Comentario del estudiante:';
		$parts[] = $student_note ? $student_note : 'Sin comentario del estudiante.';
		$parts[] = '';
		$parts[] = 'Archivos fuente detectados:';
		$parts[] = $files_text;
		$parts[] = '';
		$parts[] = 'Texto extraído / consolidado de la entrega:';
		$parts[] = $combined_text ? $combined_text : 'Sin texto extraído.';

		if ( $transcription ) {
			// Limitar la transcripción a ~3000 palabras para no saturar el contexto
			$tr_words = explode( ' ', $transcription );
			if ( count( $tr_words ) > 3000 ) {
				$transcription = implode( ' ', array_slice( $tr_words, 0, 3000 ) ) . '…';
			}
			$parts[] = '';
			$parts[] = '--- TRANSCRIPCIÓN DE LA LECCIÓN ---';
			$parts[] = $transcription;
			$parts[] = '(Fin de la transcripción. Úsala como referencia del contenido impartido al evaluar la entrega.)';
		}

		if ( $rubric_text ) {
			$parts[] = '';
			$parts[] = '--- RÚBRICA DE EVALUACIÓN ---';
			$parts[] = $rubric_text;
			$parts[] = 'Para cada criterio incluye en criteria_scores: criterion (nombre), score (puntos obtenidos, entero), max_points (puntos máximos del criterio), rationale (explicación breve en español).';
			$parts[] = 'Si no hay rúbrica, devuelve criteria_scores como array vacío [].';
		} else {
			$parts[] = '';
			$parts[] = 'No hay rúbrica asignada. Devuelve criteria_scores como array vacío [].';
		}

		$parts[] = '';
		$parts[] = 'Devuelve solo un JSON válido siguiendo exactamente el contrato indicado.';

		return implode( "\n", $parts );
	}

	/**
	 * Prompt de contrato para proveedores sin schema estricto nativo.
	 *
	 * @return string
	 */
	protected function get_contract_prompt() {
		return implode(
			"\n",
			array(
				'Eres un asistente educativo que pre-revisa tareas de un LMS.',
				'Debes responder únicamente con JSON válido.',
				'No agregues markdown, comentarios ni texto fuera del JSON.',
				'El JSON debe incluir exactamente estas claves:',
				'status, summary, feedback_draft, score_suggestion, criteria_scores, raw, updated_at, error, source_files, highlights',
				'status debe ser "completed" o "failed".',
				'summary y feedback_draft deben ser strings en español, detallados y pedagógicos.',
				'score_suggestion puede ser entero o string numérico (0-100).',
				'criteria_scores debe ser un array de objetos con: criterion (string), score (entero), max_points (entero), rationale (string en español). Si no hay rúbrica, devuelve [].',
				'raw debe ser un objeto.',
				'updated_at debe ser string.',
				'error debe ser string.',
				'source_files debe ser array de enteros.',
				'highlights debe ser array de strings en español.',
			)
		);
	}

	/**
	 * Normaliza contrato base.
	 *
	 * @param array $data Datos crudos del provider.
	 * @return array
	 */
	protected function normalize_contract( $data ) {
		$data = is_array( $data ) ? $data : array();

		$highlights = isset( $data['highlights'] ) && is_array( $data['highlights'] ) ? $data['highlights'] : array();
		$highlights = array_values(
			array_filter(
				array_map(
					static function( $item ) {
						return trim( sanitize_text_field( (string) $item ) );
					},
					$highlights
				)
			)
		);

		$source_files = isset( $data['source_files'] ) && is_array( $data['source_files'] ) ? $data['source_files'] : array();
		$source_files = array_values(
			array_filter(
				array_map( 'absint', $source_files )
			)
		);

		$status = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'completed';
		if ( ! in_array( $status, array( 'completed', 'failed' ), true ) ) {
			$status = 'completed';
		}

		$criteria_scores = array();
		if ( isset( $data['criteria_scores'] ) && is_array( $data['criteria_scores'] ) ) {
			foreach ( $data['criteria_scores'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$criteria_scores[] = array(
					'criterion'  => isset( $item['criterion'] ) ? sanitize_text_field( (string) $item['criterion'] ) : '',
					'score'      => isset( $item['score'] ) && '' !== (string) $item['score'] ? max( 0, absint( $item['score'] ) ) : '',
					'max_points' => isset( $item['max_points'] ) ? absint( $item['max_points'] ) : 0,
					'rationale'  => isset( $item['rationale'] ) ? sanitize_textarea_field( (string) $item['rationale'] ) : '',
				);
			}
		}

		return array(
			'status'           => $status,
			'summary'          => isset( $data['summary'] ) ? wp_kses_post( (string) $data['summary'] ) : '',
			'feedback_draft'   => isset( $data['feedback_draft'] ) ? wp_kses_post( (string) $data['feedback_draft'] ) : '',
			'score_suggestion' => isset( $data['score_suggestion'] ) ? (string) $data['score_suggestion'] : '',
			'criteria_scores'  => $criteria_scores,
			'raw'              => isset( $data['raw'] ) && is_array( $data['raw'] ) ? $data['raw'] : array(),
			'updated_at'       => isset( $data['updated_at'] ) ? sanitize_text_field( (string) $data['updated_at'] ) : current_time( 'mysql' ),
			'error'            => isset( $data['error'] ) ? sanitize_text_field( (string) $data['error'] ) : '',
			'source_files'     => $source_files,
			'highlights'       => $highlights,
		);
	}

	/**
	 * Intenta aislar un objeto JSON dentro de un texto más grande.
	 *
	 * @param string $text Texto bruto.
	 * @return string
	 */
	protected function extract_json_object_from_text( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return '';
		}

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );

		if ( false === $start || false === $end || $end <= $start ) {
			return '';
		}

		return substr( $text, $start, ( $end - $start + 1 ) );
	}

	/**
	 * Verifica si el resultado estructurado quedó vacío.
	 *
	 * @param array $review Review normalizado.
	 * @return bool
	 */
	protected function is_empty_review_result( $review ) {
		$review = is_array( $review ) ? $review : array();

		$summary        = isset( $review['summary'] ) ? trim( (string) $review['summary'] ) : '';
		$feedback_draft = isset( $review['feedback_draft'] ) ? trim( (string) $review['feedback_draft'] ) : '';
		$highlights     = isset( $review['highlights'] ) && is_array( $review['highlights'] ) ? $review['highlights'] : array();
		$score_present  = isset( $review['score_suggestion'] ) && '' !== (string) $review['score_suggestion'];

		return ( '' === $summary && '' === $feedback_draft && empty( $highlights ) && ! $score_present );
	}
}
