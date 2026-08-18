<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_AI {

	/**
	 * Providers registrados.
	 *
	 * @var array
	 */
	protected $providers = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->register_default_providers();
	}

	/**
	 * Genera una pre-revisión IA para una entrega.
	 *
	 * @param int   $submission_id ID de la entrega.
	 * @param array $args          Argumentos extra.
	 * @return array|WP_Error
	 */
	public function generate_submission_review( $submission_id, $args = array() ) {
		$submission_id = absint( $submission_id );
		$args          = is_array( $args ) ? $args : array();
		$ai_manager    = clms_core('CLMS_AI_Manager');
		$provider_slug = ( $ai_manager && method_exists( $ai_manager, 'get_active_provider_slug' ) )
			? (string) $ai_manager->get_active_provider_slug()
			: 'mock-local';
		$model_name    = ( $ai_manager && method_exists( $ai_manager, 'get_model' ) )
			? (string) $ai_manager->get_model( $provider_slug )
			: 'mock-local-review';

		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_submission', __( 'Entrega inválida para análisis IA.', 'atora-lms' ) );
		}

		$submission = clms_core('CLMS_Submission');

		if ( ! $submission || ! method_exists( $submission, 'get_ai_review_data' ) || ! method_exists( $submission, 'update_ai_review_data' ) ) {
			return new WP_Error( 'submission_module_missing', __( 'No se encontró el módulo de entregas para IA.', 'atora-lms' ) );
		}

		$submission->update_ai_review_data(
			$submission_id,
			array(
				'status'     => 'processing',
				'updated_at' => current_time( 'mysql' ),
				'error'      => '',
			)
		);

		$payload = $this->build_submission_ai_payload( $submission_id, $args );

		if ( is_wp_error( $payload ) ) {
			$submission->update_ai_review_data(
				$submission_id,
				array(
					'status'     => 'failed',
					'error'      => $payload->get_error_message(),
					'updated_at' => current_time( 'mysql' ),
				)
			);

			return $payload;
		}

		$provider = $this->get_active_provider();

		if ( $provider instanceof CLMS_AI_Provider_Interface && $provider->is_configured() ) {
			$response = $provider->generate_review( $payload );

			if ( is_wp_error( $response ) ) {
				$submission->update_ai_review_data(
					$submission_id,
					array(
						'status'     => 'failed',
						'error'      => $response->get_error_message(),
						'updated_at' => current_time( 'mysql' ),
					)
				);

				return $response;
			}

			if ( ! is_array( $response ) ) {
				$error = new WP_Error( 'invalid_provider_response', __( 'El provider IA devolvió una respuesta inválida.', 'atora-lms' ) );

				$submission->update_ai_review_data(
					$submission_id,
					array(
						'status'     => 'failed',
						'error'      => $error->get_error_message(),
						'updated_at' => current_time( 'mysql' ),
					)
				);

				return $error;
			}

			$review = $this->normalize_ai_review_response( $response, $payload );

			/*
			 * Si el provider devolvió algo vacío o inconsistente y terminó sin contenido,
			 * se marca como failed para evitar falsos "completed".
			 */
			if ( $this->is_empty_ai_review( $review ) ) {
				$review['status']     = 'failed';
				$review['error']      = ! empty( $review['error'] ) ? $review['error'] : 'La respuesta del provider IA no contiene datos utilizables.';
				$review['updated_at'] = current_time( 'mysql' );
			}
		} else {
			$response = $this->mock_submission_review_response( $payload );
			$review   = $this->normalize_ai_review_response( $response, $payload );
			$provider_slug = 'mock-local';
			$model_name    = 'mock-local-review';
		}

		$review['provider'] = ! empty( $review['provider'] ) ? sanitize_key( (string) $review['provider'] ) : $provider_slug;
		$review['model']    = ! empty( $review['model'] ) ? sanitize_text_field( (string) $review['model'] ) : $model_name;
		$review['confidence'] = isset( $review['confidence'] ) && '' !== (string) $review['confidence']
			? max( 0.0, min( 1.0, (float) $review['confidence'] ) )
			: ( isset( $response['confidence'] ) ? max( 0.0, min( 1.0, (float) $response['confidence'] ) ) : 0.6 );

		$submission->update_ai_review_data( $submission_id, $review );

		return $review;
	}

	/**
	 * Construye el payload base para la revisión IA.
	 *
	 * @param int   $submission_id ID de la entrega.
	 * @param array $args          Opciones extra.
	 * @return array|WP_Error
	 */
	public function build_submission_ai_payload( $submission_id, $args = array() ) {
		$submission_id = absint( $submission_id );
		$args          = is_array( $args ) ? $args : array();

		if ( ! $submission_id ) {
			return new WP_Error( 'invalid_submission', __( 'Entrega inválida.', 'atora-lms' ) );
		}

		$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		$lesson_id  = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$course_id  = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );

		if ( ! $lesson_id ) {
			return new WP_Error( 'missing_lesson', __( 'La entrega no tiene lección asociada.', 'atora-lms' ) );
		}

		$attachments = get_post_meta( $submission_id, '_clms_submission_attachments', true );
		if ( ! is_array( $attachments ) ) {
			$attachments = get_post_meta( $submission_id, '_clms_submission_files', true );
		}
		$attachments = is_array( $attachments ) ? array_values( array_filter( array_map( 'absint', $attachments ) ) ) : array();

		$student = $student_id ? get_user_by( 'id', $student_id ) : false;

		$text_bundle = $this->extract_submission_text_bundle( $submission_id, $attachments );

		if ( is_wp_error( $text_bundle ) ) {
			return $text_bundle;
		}

		// Load lesson transcription if available
		$transcription_text = '';
		if ( $lesson_id && class_exists( 'CLMS_Transcription' ) ) {
			$transcription_text = CLMS_Transcription::get_text( $lesson_id );
		}

		// Load rubric criteria if lesson has one assigned
		$rubric_id      = $lesson_id ? absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) ) : 0;
		$rubric_criteria = array();
		$rubric_text     = '';
		if ( $rubric_id && class_exists( 'CLMS_Rubric' ) ) {
			$rubric_criteria = CLMS_Rubric::get_criteria( $rubric_id );
			$rubric_text     = CLMS_Rubric::format_criteria_for_prompt( $rubric_id );
		}

		return array(
			'submission_id'   => $submission_id,
			'student_id'      => $student_id,
			'student_name'    => $student ? ( $student->display_name ? $student->display_name : $student->user_login ) : '',
			'course_id'       => $course_id,
			'course_title'    => $course_id ? get_the_title( $course_id ) : '',
			'lesson_id'       => $lesson_id,
			'lesson_title'    => get_the_title( $lesson_id ),
			'comment'         => (string) get_post_meta( $submission_id, '_clms_submission_comment', true ),
			'attachments'     => $attachments,
			'text_bundle'     => $text_bundle,
			'transcription'   => $transcription_text,
			'rubric_id'       => $rubric_id,
			'rubric_criteria' => $rubric_criteria,
			'rubric_text'     => $rubric_text,
			'context'         => array(
				'rubric'         => isset( $args['rubric'] ) ? sanitize_text_field( $args['rubric'] ) : '',
				'instructions'   => isset( $args['instructions'] ) ? sanitize_textarea_field( $args['instructions'] ) : '',
				'teacher_prompt' => isset( $args['teacher_prompt'] ) ? sanitize_textarea_field( $args['teacher_prompt'] ) : '',
			),
		);
	}

	/**
	 * Extrae el texto utilizable de la entrega.
	 *
	 * @param int   $submission_id ID de la entrega.
	 * @param array $attachments   Adjuntos.
	 * @return array|WP_Error
	 */
	protected function extract_submission_text_bundle( $submission_id, $attachments = array() ) {
		$submission_id = absint( $submission_id );
		$attachments   = is_array( $attachments ) ? array_values( array_filter( array_map( 'absint', $attachments ) ) ) : array();

		$comment = (string) get_post_meta( $submission_id, '_clms_submission_comment', true );
		$comment = trim( wp_strip_all_tags( $comment ) );

		$extractor = clms_core('CLMS_AI_Extractor');

		$bundle = array(
			'comment'          => $comment,
			'comment_words'    => $comment ? str_word_count( wp_strip_all_tags( $comment ) ) : 0,
			'attachment_texts' => array(),
			'combined_text'    => '',
			'source_files'     => array(),
		);

		foreach ( $attachments as $attachment_id ) {
			$item = array();

			if ( $extractor && method_exists( $extractor, 'extract_attachment_text' ) ) {
				$item = $extractor->extract_attachment_text( $attachment_id );
			} else {
				$item = $this->extract_attachment_text( $attachment_id );
			}

			if ( is_wp_error( $item ) ) {
				continue;
			}

			if ( ! is_array( $item ) || empty( $item['text'] ) ) {
				continue;
			}

			$bundle['attachment_texts'][] = array(
				'attachment_id' => absint( $attachment_id ),
				'label'         => isset( $item['label'] ) ? sanitize_text_field( $item['label'] ) : '',
				'text'          => isset( $item['text'] ) ? (string) $item['text'] : '',
			);

			$bundle['source_files'][] = absint( $attachment_id );
		}

		$pieces = array();

		if ( $comment ) {
			$pieces[] = 'Comentario del estudiante:' . "\n" . $comment;
		}

		foreach ( $bundle['attachment_texts'] as $file_item ) {
			$pieces[] = 'Archivo: ' . $file_item['label'] . "\n" . $file_item['text'];
		}

		$bundle['combined_text'] = trim( implode( "\n\n", $pieces ) );

		if ( '' === $bundle['combined_text'] ) {
			return new WP_Error( 'empty_submission', __( 'No hay contenido suficiente para analizar en la entrega.', 'atora-lms' ) );
		}

		return $bundle;
	}

	/**
	 * Fallback interno para obtener texto de un adjunto.
	 *
	 * @param int $attachment_id ID del adjunto.
	 * @return array
	 */
	protected function extract_attachment_text( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return array();
		}

		$file_path = get_attached_file( $attachment_id );
		$mime_type = (string) get_post_mime_type( $attachment_id );
		$label     = basename( (string) $file_path );

		if ( ! $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return array();
		}

		$text = '';

		if ( 0 === strpos( $mime_type, 'text/' ) ) {
			$text = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} elseif ( 'application/pdf' === $mime_type ) {
			$text = '';
		} elseif ( false !== strpos( $mime_type, 'json' ) || false !== strpos( $mime_type, 'xml' ) ) {
			$text = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} else {
			$ext = strtolower( (string) pathinfo( $file_path, PATHINFO_EXTENSION ) );

			if ( in_array( $ext, array( 'txt', 'md', 'csv', 'log' ), true ) ) {
				$text = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		}

		$text = is_string( $text ) ? trim( wp_strip_all_tags( $text ) ) : '';

		if ( '' === $text ) {
			return array(
				'label' => $label,
				'text'  => '',
			);
		}

		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		if ( strlen( $text ) > 12000 ) {
			$text = substr( $text, 0, 12000 ) . '...';
		}

		return array(
			'label' => $label,
			'text'  => $text,
		);
	}

	/**
	 * Simula una respuesta IA local para no depender aún de APIs externas.
	 *
	 * @param array $payload Payload de la entrega.
	 * @return array
	 */
	protected function mock_submission_review_response( $payload ) {
		$payload       = is_array( $payload ) ? $payload : array();
		$text_bundle   = isset( $payload['text_bundle'] ) && is_array( $payload['text_bundle'] ) ? $payload['text_bundle'] : array();
		$combined_text = isset( $text_bundle['combined_text'] ) ? (string) $text_bundle['combined_text'] : '';
		$comment       = isset( $payload['comment'] ) ? trim( (string) $payload['comment'] ) : '';
		$lesson_title  = isset( $payload['lesson_title'] ) ? (string) $payload['lesson_title'] : 'la lección';
		$student_name  = isset( $payload['student_name'] ) ? (string) $payload['student_name'] : 'El estudiante';

		$score = $this->calculate_mock_score( $payload );

		$highlights = array();

		if ( $comment ) {
			$highlights[] = 'Incluye comentario del estudiante para contextualizar la entrega.';
		}

		if ( ! empty( $text_bundle['source_files'] ) ) {
			$highlights[] = 'Se detectaron archivos adjuntos para revisión.';
		}

		if ( str_word_count( wp_strip_all_tags( $combined_text ) ) >= 120 ) {
			$highlights[] = 'El contenido tiene una extensión suficiente para revisión preliminar.';
		} else {
			$highlights[] = 'La entrega parece breve; conviene validarla manualmente.';
		}

		$summary = $student_name . ' presentó una entrega asociada a "' . $lesson_title . '". ';
		$summary .= 'La revisión automática detecta contenido utilizable y genera una sugerencia preliminar. ';
		$summary .= 'Esta salida debe servir como apoyo para la calificación manual.';

		$feedback = "Gracias por tu entrega.\n\n";
		$feedback .= "Fortalezas detectadas:\n";
		$feedback .= "- Hay material suficiente para una revisión inicial.\n";
		$feedback .= "- La entrega parece vinculada al objetivo de la actividad.\n\n";
		$feedback .= "Aspectos a mejorar:\n";
		$feedback .= "- Revisa claridad, profundidad y estructura final.\n";
		$feedback .= "- Si aplica, agrega más evidencia o explicación en los archivos enviados.\n\n";
		$feedback .= "Nota: esta retroalimentación fue generada como borrador y debe ser validada por el docente.";

		// Mock per-criterion scores if rubric provided
		$criteria_scores = array();
		if ( ! empty( $payload['rubric_criteria'] ) && is_array( $payload['rubric_criteria'] ) ) {
			foreach ( $payload['rubric_criteria'] as $c ) {
				$max             = isset( $c['max_points'] ) ? absint( $c['max_points'] ) : 0;
				$mock_score      = $max > 0 ? max( 1, (int) round( $max * ( ( $score ?: 70 ) / 100 ) ) ) : 0;
				$criteria_scores[] = array(
					'criterion'  => isset( $c['name'] ) ? $c['name'] : '',
					'score'      => $mock_score,
					'max_points' => $max,
					'rationale'  => 'Revisión automática de referencia. El docente debe validar esta puntuación.',
				);
			}
		}

		return array(
			'status'           => 'completed',
			'summary'          => $summary,
			'feedback_draft'   => $feedback,
			'score_suggestion' => $score,
			'criteria_scores'  => $criteria_scores,
			'raw'              => array(
				'engine'         => 'mock-local',
				'generated_at'   => current_time( 'mysql' ),
				'text_length'    => strlen( $combined_text ),
				'word_count'     => str_word_count( wp_strip_all_tags( $combined_text ) ),
				'attachment_cnt' => ! empty( $text_bundle['source_files'] ) ? count( $text_bundle['source_files'] ) : 0,
			),
			'updated_at'       => current_time( 'mysql' ),
			'error'            => '',
			'source_files'     => isset( $text_bundle['source_files'] ) && is_array( $text_bundle['source_files'] ) ? $text_bundle['source_files'] : array(),
			'highlights'       => $highlights,
		);
	}

	/**
	 * Normaliza la salida de IA.
	 *
	 * @param array $response Respuesta.
	 * @param array $payload  Payload origen.
	 * @return array
	 */
	protected function normalize_ai_review_response( $response, $payload = array() ) {
		$response = is_array( $response ) ? $response : array();
		$payload  = is_array( $payload ) ? $payload : array();

		$text_bundle = isset( $payload['text_bundle'] ) && is_array( $payload['text_bundle'] ) ? $payload['text_bundle'] : array();

		$allowed_statuses = array( 'not_requested', 'queued', 'processing', 'completed', 'failed' );
		$status           = isset( $response['status'] ) ? sanitize_key( $response['status'] ) : 'completed';

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'completed';
		}

		$summary        = isset( $response['summary'] ) ? wp_kses_post( $response['summary'] ) : '';
		$feedback_draft = isset( $response['feedback_draft'] ) ? wp_kses_post( $response['feedback_draft'] ) : '';
		$confidence     = isset( $response['confidence'] ) && '' !== (string) $response['confidence']
			? max( 0.0, min( 1.0, (float) $response['confidence'] ) )
			: '';
		$score          = isset( $response['score_suggestion'] ) && '' !== (string) $response['score_suggestion']
			? max( 0, min( 100, absint( $response['score_suggestion'] ) ) )
			: '';
		$raw            = isset( $response['raw'] ) && is_array( $response['raw'] ) ? $response['raw'] : array();
		$updated_at     = isset( $response['updated_at'] ) ? sanitize_text_field( $response['updated_at'] ) : current_time( 'mysql' );
		$error          = isset( $response['error'] ) ? sanitize_text_field( $response['error'] ) : '';
		$source_files   = isset( $response['source_files'] ) && is_array( $response['source_files'] )
			? array_values( array_filter( array_map( 'absint', $response['source_files'] ) ) )
			: array();
		$highlights     = isset( $response['highlights'] ) && is_array( $response['highlights'] )
			? array_values(
				array_filter(
					array_map(
						static function( $item ) {
							return sanitize_text_field( $item );
						},
						$response['highlights']
					)
				)
			)
			: array();

		if ( empty( $source_files ) && ! empty( $text_bundle['source_files'] ) ) {
			$source_files = array_values( array_filter( array_map( 'absint', $text_bundle['source_files'] ) ) );
		}

		$criteria_scores = isset( $response['criteria_scores'] ) && is_array( $response['criteria_scores'] )
			? array_values(
				array_map(
					static function( $item ) {
						if ( ! is_array( $item ) ) {
							return array();
						}
						return array(
							'criterion'  => isset( $item['criterion'] ) ? sanitize_text_field( (string) $item['criterion'] ) : '',
							'score'      => isset( $item['score'] ) && '' !== (string) $item['score'] ? max( 0, absint( $item['score'] ) ) : '',
							'max_points' => isset( $item['max_points'] ) ? absint( $item['max_points'] ) : 0,
							'rationale'  => isset( $item['rationale'] ) ? sanitize_textarea_field( (string) $item['rationale'] ) : '',
						);
					},
					$response['criteria_scores']
				)
			)
			: array();

		return array(
			'status'           => $status,
			'summary'          => $summary,
			'feedback_draft'   => $feedback_draft,
			'confidence'       => $confidence,
			'score_suggestion' => $score,
			'criteria_scores'  => $criteria_scores,
			'raw'              => $raw,
			'updated_at'       => $updated_at,
			'error'            => $error,
			'source_files'     => $source_files,
			'highlights'       => $highlights,
		);
	}

	/**
	 * Determina si una revisión IA quedó vacía o no utilizable.
	 *
	 * @param array $review Revisión normalizada.
	 * @return bool
	 */
	protected function is_empty_ai_review( $review ) {
		$review = is_array( $review ) ? $review : array();

		$summary        = isset( $review['summary'] ) ? trim( (string) $review['summary'] ) : '';
		$feedback_draft = isset( $review['feedback_draft'] ) ? trim( (string) $review['feedback_draft'] ) : '';
		$highlights     = isset( $review['highlights'] ) && is_array( $review['highlights'] ) ? $review['highlights'] : array();
		$score_present  = isset( $review['score_suggestion'] ) && '' !== (string) $review['score_suggestion'];

		return ( '' === $summary && '' === $feedback_draft && empty( $highlights ) && ! $score_present );
	}

	/**
	 * Calcula una sugerencia simple de nota.
	 *
	 * @param array $payload Payload.
	 * @return int
	 */
	protected function calculate_mock_score( $payload ) {
		$payload       = is_array( $payload ) ? $payload : array();
		$text_bundle   = isset( $payload['text_bundle'] ) && is_array( $payload['text_bundle'] ) ? $payload['text_bundle'] : array();
		$combined_text = isset( $text_bundle['combined_text'] ) ? (string) $text_bundle['combined_text'] : '';
		$comment       = isset( $payload['comment'] ) ? (string) $payload['comment'] : '';
		$file_count    = ! empty( $text_bundle['source_files'] ) && is_array( $text_bundle['source_files'] ) ? count( $text_bundle['source_files'] ) : 0;

		$score = 55;

		$word_count = str_word_count( wp_strip_all_tags( $combined_text ) );

		if ( $word_count >= 80 ) {
			$score += 10;
		}

		if ( $word_count >= 180 ) {
			$score += 10;
		}

		if ( $comment ) {
			$score += 5;
		}

		if ( $file_count >= 1 ) {
			$score += 10;
		}

		if ( $file_count >= 2 ) {
			$score += 5;
		}

		return max( 0, min( 100, absint( $score ) ) );
	}

	/**
	 * Registra providers por defecto.
	 *
	 * @return void
	 */
	protected function register_default_providers() {
		$this->maybe_require_provider_files();

		$instances = array(
			'openai'    => class_exists( 'CLMS_AI_Provider_OpenAI' ) ? new CLMS_AI_Provider_OpenAI() : null,
			'anthropic' => class_exists( 'CLMS_AI_Provider_Anthropic' ) ? new CLMS_AI_Provider_Anthropic() : null,
			'gemini'    => class_exists( 'CLMS_AI_Provider_Gemini' ) ? new CLMS_AI_Provider_Gemini() : null,
			'deepseek'  => class_exists( 'CLMS_AI_Provider_DeepSeek' ) ? new CLMS_AI_Provider_DeepSeek() : null,
		);

		foreach ( $instances as $slug => $provider ) {
			if ( $provider instanceof CLMS_AI_Provider_Interface ) {
				$this->providers[ $slug ] = $provider;
			}
		}
	}

	/**
	 * Carga providers si por alguna razón no entraron por loader.
	 *
	 * @return void
	 */
	protected function maybe_require_provider_files() {
		if ( defined( 'CLMS_PLUGIN_DIR' ) && CLMS_PLUGIN_DIR ) {
			$base = trailingslashit( CLMS_PLUGIN_DIR );
		} else {
			$base = trailingslashit( dirname( dirname( __DIR__ ) ) );
		}

		$files = array(
			'includes/ai/class-ai-provider-interface.php',
			'includes/ai/class-ai-provider-base.php',
			'includes/ai/class-ai-provider-openai.php',
			'includes/ai/class-ai-provider-anthropic.php',
			'includes/ai/class-ai-provider-gemini.php',
			'includes/ai/class-ai-provider-deepseek.php',
		);

		foreach ( $files as $file ) {
			$path = $base . $file;

			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Devuelve slug del provider activo.
	 *
	 * @return string
	 */
	public function get_active_provider_slug() {
		$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
		if ( $manager && method_exists( $manager, 'get_active_provider_slug' ) ) {
			return (string) $manager->get_active_provider_slug();
		}

		if ( class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_provider' ) ) {
			return (string) CLMS_AI_Settings_Service::get_provider();
		}

		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_ai_settings' ) ) {
			$settings = CLMS_Settings::get_ai_settings();
		} else {
			$settings = get_option( 'clms_ai_settings', array() );
		}

		$settings = is_array( $settings ) ? $settings : array();
		$slug     = isset( $settings['provider'] ) ? sanitize_key( $settings['provider'] ) : 'openai';

		return in_array( $slug, array( 'openai', 'anthropic', 'gemini', 'deepseek' ), true ) ? $slug : 'openai';
	}

	/**
	 * Devuelve provider activo.
	 *
	 * @return CLMS_AI_Provider_Interface|null
	 */
	public function get_active_provider() {
		$slug = $this->get_active_provider_slug();

		return isset( $this->providers[ $slug ] ) ? $this->providers[ $slug ] : null;
	}

	/**
	 * Lista providers disponibles.
	 *
	 * @return array
	 */
	public function get_available_providers() {
		return $this->providers;
	}
}
