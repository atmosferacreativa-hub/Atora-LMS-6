<?php
/**
 * CLMS_AI_Grading — Evaluación con criterios en lenguaje natural
 *
 * El profesor define criterios de evaluación en texto libre (no solo rúbrica).
 * La IA lee el texto de la entrega + los criterios y genera:
 *   - Puntuación sugerida por criterio
 *   - Comentario de retroalimentación
 *   - Nivel de confianza
 *   - Detección de posible plagio o texto generado por IA
 *
 * Diferencia con class-ai.php:
 *   - class-ai.php evalúa según una rúbrica estructurada (CPT clms_rubric)
 *   - class-ai-grading.php evalúa según criterios en lenguaje natural guardados
 *     en meta de la lección (_clms_nl_criteria). Es más flexible y no requiere
 *     crear una rúbrica formal.
 *
 * Integración:
 *   - Metabox de lección: textarea "Criterios de evaluación (lenguaje natural)"
 *   - SpeedGrader: botón "Evaluar con IA (criterios naturales)"
 *   - AJAX: wp_ajax_clms_ai_nl_grade
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_AI_Grading {

	const META_CRITERIA    = '_clms_nl_criteria';
	const META_AI_RESULT   = '_clms_ai_nl_grade';

	public function __construct() {
		add_action( 'wp_ajax_clms_ai_nl_grade',  array( $this, 'ajax_grade' ) );
		add_action( 'add_meta_boxes',             array( $this, 'add_criteria_metabox' ) );
		add_action( 'save_post_lm_lesson',        array( $this, 'save_criteria', ) );
	}

	// ── Metabox de criterios ──────────────────────────────────────────────────────

	public function add_criteria_metabox() {
		add_meta_box(
			'clms_nl_criteria',
			'Criterios de evaluación IA (lenguaje natural)',
			array( $this, 'render_criteria_metabox' ),
			'lm_lesson',
			'normal',
			'default'
		);
	}

	public function render_criteria_metabox( $post ) {
		wp_nonce_field( 'clms_nl_criteria_save', 'clms_nl_criteria_nonce' );
		$criteria = (string) get_post_meta( $post->ID, self::META_CRITERIA, true );
		?>
		<p style="margin:0 0 8px;color:#555;font-size:13px">
			Describe en lenguaje natural cómo evaluar esta actividad. La IA usará este texto
			junto con la entrega del alumno para sugerir una nota y retroalimentación.
		</p>
		<textarea
			name="clms_nl_criteria"
			rows="5"
			style="width:100%"
			placeholder="Ej: El estudiante debe demostrar comprensión del concepto X (30 pts), aplicar al menos 2 ejemplos reales (40 pts) y redactar con claridad y coherencia (30 pts). Se valorará la originalidad. Se penalizará el plagio."
		><?php echo esc_textarea( $criteria ); ?></textarea>
		<p style="margin:8px 0 0;color:#888;font-size:12px">
			Máximo 1000 caracteres. Cuanto más específico seas, más precisos serán los resultados.
		</p>
		<?php
	}

	public function save_criteria( $post_id ) {
		if ( ! isset( $_POST['clms_nl_criteria_nonce'] ) ) { return; }
		if ( ! wp_verify_nonce( $_POST['clms_nl_criteria_nonce'], 'clms_nl_criteria_save' ) ) { return; }
		if ( ! CLMS_Helper::user_can_manage_lms( $post_id ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }

		$criteria = isset( $_POST['clms_nl_criteria'] )
			? sanitize_textarea_field( wp_unslash( $_POST['clms_nl_criteria'] ) )
			: '';

		update_post_meta( $post_id, self::META_CRITERIA, mb_substr( $criteria, 0, 1000 ) );
	}

	// ── AJAX: evaluar entrega ─────────────────────────────────────────────────────

	public function ajax_grade() {
		$nonce         = isset( $_POST['nonce'] )         ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$submission_id = isset( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;

		if ( ! wp_verify_nonce( $nonce, 'clms_ai_nl_grade_' . $submission_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'atora-lms' ) ), 403 );
		}

		if ( ! CLMS_Helper::user_can_manage_lms() ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'atora-lms' ) ), 403 );
		}

		if ( ! $submission_id ) {
			wp_send_json_error( array( 'message' => __( 'Entrega inválida.', 'atora-lms' ) ) );
		}

		$copilots = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Copilots') : null;
		if ( $copilots && method_exists( $copilots, 'get_evaluation_mode' ) ) {
			$mode = (string) $copilots->get_evaluation_mode();
			if ( 'manual' === $mode ) {
				wp_send_json_error(
					array(
						'message' => __( 'La evaluación IA está en modo manual y no permite sugerencias automáticas ahora mismo.', 'atora-lms' ),
					),
					403
				);
			}
		}

		$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$criteria  = (string) get_post_meta( $lesson_id, self::META_CRITERIA, true );

		if ( ! $criteria ) {
			wp_send_json_error( array( 'message' => __( 'Esta lección no tiene criterios de evaluación IA definidos. Agrégalos en el metabox de la lección.', 'atora-lms' ) ) );
		}

		// Obtener texto de la entrega
		$submission  = get_post( $submission_id );
		$text_content = $submission ? wp_strip_all_tags( apply_filters( 'the_content', $submission->post_content ) ) : '';

		// También leer los archivos adjuntos si los hay
		$attachments = get_post_meta( $submission_id, '_clms_submission_files', true );
		if ( is_array( $attachments ) ) {
			foreach ( $attachments as $att_id ) {
				$file_path = get_attached_file( absint( $att_id ) );
				if ( $file_path && file_exists( $file_path ) && 'text/' === substr( get_post_mime_type( $att_id ), 0, 5 ) ) {
					$text_content .= "\n\n" . file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			}
		}

		if ( ! trim( $text_content ) ) {
			wp_send_json_error( array( 'message' => __( 'La entrega no tiene contenido de texto evaluable.', 'atora-lms' ) ) );
		}

		$result = $this->evaluate( $text_content, $criteria, $lesson_id, $submission_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Persistir resultado para mostrarlo en SpeedGrader
		update_post_meta( $submission_id, self::META_AI_RESULT, $result );

		wp_send_json_success( $result );
	}

	// ── Motor de evaluación ───────────────────────────────────────────────────────

	/**
	 * @param string $submission_text Texto de la entrega.
	 * @param string $criteria        Criterios en lenguaje natural.
	 * @param int    $lesson_id       Para contexto adicional (título, objetivos).
	 * @return array|WP_Error
	 */
	public function evaluate( $submission_text, $criteria, $lesson_id = 0, $submission_id = 0 ) {
		$options = class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_settings' )
			? (array) CLMS_AI_Settings_Service::get_settings()
			: (array) get_option( 'clms_ai_settings', array() );
		$provider = class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_provider' )
			? (string) CLMS_AI_Settings_Service::get_provider()
			: (string) ( $options['provider'] ?? 'openai' );

		$lesson_context = '';
		if ( $lesson_id ) {
			$lesson_title = get_the_title( $lesson_id );
			$lesson_sub   = (string) get_post_meta( $lesson_id, '_clms_lesson_subtitle', true );
			$lesson_context = "Lección: {$lesson_title}" . ( $lesson_sub ? " — {$lesson_sub}" : '' ) . "\n";
		}

		$prompt = <<<PROMPT
Eres un evaluador académico experto. Evalúa la siguiente entrega de un estudiante según los criterios indicados.

{$lesson_context}

CRITERIOS DE EVALUACIÓN:
{$criteria}

ENTREGA DEL ESTUDIANTE:
{$submission_text}

Genera una evaluación estructurada en JSON con el siguiente formato exacto:
{
  "score": <número entero 0-100>,
  "confidence": <número decimal 0.0-1.0 que indica tu confianza en la evaluación>,
  "feedback": "<retroalimentación constructiva en 3-5 oraciones, en español, sin mencionar la puntuación>",
  "strengths": ["<punto fuerte 1>", "<punto fuerte 2>"],
  "improvements": ["<área de mejora 1>", "<área de mejora 2>"],
  "ai_detected": <true|false — si sospechas que el texto fue generado por IA>,
  "ai_detection_note": "<nota breve si ai_detected es true, vacío si false>"
}
Responde SOLO con el JSON, sin texto adicional.
PROMPT;

		$raw = $this->call_provider(
			$provider,
			$options,
			$prompt,
			array(
				'lesson_id'     => absint( $lesson_id ),
				'submission_id' => absint( $submission_id ),
			)
		);

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$raw = trim( (string) $raw );
		if ( preg_match( '/```(?:json)?([\s\S]*?)```/i', $raw, $m ) ) {
			$raw = trim( $m[1] );
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			// Intentar extraer JSON del texto
			if ( preg_match( '/\{[\s\S]+\}/', $raw, $m ) ) {
				$data = json_decode( $m[0], true );
			}
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'parse_error', __( 'La IA devolvió un formato inesperado.', 'atora-lms' ) );
		}

		return array(
			'score'              => max( 0, min( 100, absint( $data['score'] ?? 0 ) ) ),
			'confidence'         => max( 0.0, min( 1.0, (float) ( $data['confidence'] ?? 0.5 ) ) ),
			'feedback'           => sanitize_textarea_field( (string) ( $data['feedback'] ?? '' ) ),
			'strengths'          => array_map( 'sanitize_text_field', (array) ( $data['strengths'] ?? array() ) ),
			'improvements'       => array_map( 'sanitize_text_field', (array) ( $data['improvements'] ?? array() ) ),
			'ai_detected'        => ! empty( $data['ai_detected'] ),
			'ai_detection_note'  => sanitize_text_field( (string) ( $data['ai_detection_note'] ?? '' ) ),
			'provider'           => $provider,
			'evaluated_at'       => current_time( 'mysql' ),
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────────

	protected function call_provider( $provider, $options, $prompt, $context = array() ) {
		unset( $options );

		$copilots = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Copilots') : null;
		if ( $copilots && method_exists( $copilots, 'run_text' ) ) {
			return $copilots->run_text(
				'evaluator',
				'grade_submission',
				array( array( 'role' => 'user', 'content' => (string) $prompt ) ),
				array(
					'provider'    => (string) $provider,
					'max_tokens'  => 600,
					'temperature' => 0.2,
					'timeout'     => 60,
				),
				is_array( $context ) ? $context : array()
			);
		}

		$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
		if ( ! $manager || ! method_exists( $manager, 'chat' ) ) {
			return new WP_Error( 'clms_ai_manager_missing', __( 'CLMS_AI_Manager no está disponible.', 'atora-lms' ) );
		}

		return $manager->chat(
			array( array( 'role' => 'user', 'content' => (string) $prompt ) ),
			array(
				'provider'    => (string) $provider,
				'max_tokens'  => 600,
				'temperature' => 0.2,
				'timeout'     => 60,
			)
		);
	}

	/**
	 * Devuelve el resultado AI guardado para una entrega.
	 */
	public static function get_result( $submission_id ) {
		return get_post_meta( absint( $submission_id ), self::META_AI_RESULT, true );
	}

	/**
	 * Devuelve los criterios NL de una lección.
	 */
	public static function get_criteria( $lesson_id ) {
		return (string) get_post_meta( absint( $lesson_id ), self::META_CRITERIA, true );
	}
}
