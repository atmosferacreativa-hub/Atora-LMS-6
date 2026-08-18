<?php
/**
 * CLMS_Sentiment — Análisis de sentimiento en entregas y mensajes de chat
 *
 * Detecta frustración, confusión, desmotivación o bienestar en el texto
 * del estudiante. Cuando se detecta un estado negativo severo:
 *   - Alerta al profesor por email (una vez cada 48h por alumno/curso)
 *   - Guarda el resultado en meta de la entrega (_clms_sentiment)
 *   - Expone el resultado en el SpeedGrader y en el dashboard del profesor
 *
 * Análisis síncrono: se ejecuta en save_post / AJAX chat (ligero, < 1s).
 * Incluye un analizador por reglas (sin API) como fallback rápido.
 *
 * Integración:
 *   - Llama a CLMS_Student_Memory::record_chat_message() con el sentimiento detectado
 *   - Acción do_action('clms_sentiment_alert', $user_id, $course_id, $sentiment, $text)
 *     para que otros módulos reaccionen
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Sentiment {

	const META_SUBMISSION = '_clms_sentiment';
	const ALERT_COOLDOWN  = 48 * HOUR_IN_SECONDS;
	const ALERT_META      = '_clms_sentiment_alert_sent_';

	// Palabras clave por categoría (español + inglés básico)
	const KEYWORDS = array(
		'frustrated' => array(
			'no entiendo', 'no comprendo', 'estoy perdido', 'me perdí', 'no tiene sentido',
			'imposible', 'qué difícil', 'no puedo', 'demasiado difícil', 'terrible',
			'horrible', 'odio', 'odio esto', 'rendirse', 'me rindo', 'para qué',
			'no sirve', 'inútil', 'no funciona', 'no sé cómo', "don't understand",
			"can't do this", "too hard", "giving up",
		),
		'confused' => array(
			'confundido', 'no sé', 'no entiendo bien', 'no estoy seguro', 'qué significa',
			'cómo funciona', 'en qué parte', 'dónde', 'cuál es la diferencia',
			'no queda claro', 'podría explicar', '¿por qué?', 'no recuerdo',
			'confused', "what does", "how does", "i'm not sure",
		),
		'positive' => array(
			'entendí', 'logré', 'pude', 'excelente', 'genial', 'perfecto', 'muy bien',
			'me quedó claro', 'ya entiendo', 'funciona', 'gracias', 'aprendí',
			'interesante', 'me gustó', 'great', 'awesome', 'i got it', 'makes sense',
		),
		'disengaged' => array(
			'aburrido', 'aburrida', 'no me importa', 'para qué sirve esto', 'irrelevante',
			'no le veo utilidad', 'boring', "doesn't matter", "who cares",
		),
	);

	private static $instance = null;
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		// Analizar entregas al guardar
		add_action( 'save_post_clms_submission', array( $this, 'analyze_submission' ), 20, 1 );
		// AJAX para analizar texto en tiempo real desde el chat
		add_action( 'wp_ajax_clms_analyze_sentiment',        array( $this, 'ajax_analyze' ) );
	}

	// ── Análisis de entregas ──────────────────────────────────────────────────────

	public function analyze_submission( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'clms_submission' !== $post->post_type ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }

		$text = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
		if ( ! trim( $text ) ) { return; }

		$result = $this->analyze( $text );
		update_post_meta( $post_id, self::META_SUBMISSION, $result );

		// Notificar si el estado es negativo
		if ( in_array( $result['label'], array( 'frustrated', 'disengaged' ), true ) && $result['score'] >= 0.6 ) {
			$user_id   = (int) ( $post->post_author );
			$lesson_id = (int) get_post_meta( $post_id, '_clms_submission_lesson_id', true );
			$course_id = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_id_from_lesson( $lesson_id ) : 0;
			if ( $user_id && $course_id ) {
				$this->maybe_alert_teacher( $user_id, $course_id, $result['label'], $text );
				do_action( 'clms_sentiment_alert', $user_id, $course_id, $result['label'], $text );
			}
		}
	}

	// ── AJAX: analizar texto desde el asistente de chat ──────────────────────────

	public function ajax_analyze() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Debes iniciar sesión para analizar texto.', 'atora-lms' ) ), 401 );
		}

		$text      = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
		$course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
		$user_id   = get_current_user_id();

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( $nonce && $course_id && ! wp_verify_nonce( $nonce, 'clms_sa_nonce_' . $course_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'atora-lms' ) ), 403 );
		}

		if ( ! $text ) {
			wp_send_json_error( array( 'message' => __( 'Sin texto.', 'atora-lms' ) ) );
		}

		if ( $course_id && class_exists( 'CLMS_Helper' ) ) {
			if ( 'lm_course' !== get_post_type( $course_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Curso no válido.', 'atora-lms' ) ) );
			}

			if ( ! CLMS_Helper::user_can_access_course( $user_id, $course_id ) ) {
				wp_send_json_error( array( 'message' => __( 'No tienes acceso a este curso.', 'atora-lms' ) ), 403 );
			}
		}

		$result = $this->analyze( $text );

		// Registrar en la memoria del estudiante
		if ( $user_id && $course_id && class_exists( 'CLMS_Student_Memory' ) ) {
			CLMS_Student_Memory::instance()->record_chat_message( $user_id, $course_id, $text, $result['label'] );
		}

		wp_send_json_success( $result );
	}

	// ── Motor de análisis ─────────────────────────────────────────────────────────

	/**
	 * Analiza el sentimiento de un texto.
	 * Usa IA si está configurada, si no usa reglas locales.
	 *
	 * @param  string $text
	 * @return array { label, score, keywords_found, method }
	 */
	public function analyze( $text ) {
		$options = class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_settings' )
			? (array) CLMS_AI_Settings_Service::get_settings()
			: (array) get_option( 'clms_ai_settings', array() );
		$provider = class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_provider' )
			? (string) CLMS_AI_Settings_Service::get_provider()
			: (string) ( $options['provider'] ?? 'openai' );
		$has_key  = $this->has_api_key( $provider, $options );

		// Para textos cortos (< 50 palabras) usar reglas siempre (ahorra tokens)
		$word_count = str_word_count( $text );
		if ( ! $has_key || $word_count < 50 ) {
			return $this->analyze_rules( $text );
		}

		$result = $this->analyze_with_ai( $text, $provider, $options );
		if ( is_wp_error( $result ) ) {
			return $this->analyze_rules( $text );
		}
		return $result;
	}

	/**
	 * Análisis por reglas (palabras clave) — sin API, instantáneo.
	 */
	public function analyze_rules( $text ) {
		$lower = mb_strtolower( $text );
		$scores = array(
			'frustrated'  => 0,
			'confused'    => 0,
			'positive'    => 0,
			'disengaged'  => 0,
		);
		$found = array();

		foreach ( self::KEYWORDS as $label => $words ) {
			foreach ( $words as $word ) {
				if ( false !== mb_strpos( $lower, $word ) ) {
					$scores[ $label ]++;
					$found[] = $word;
				}
			}
		}

		// Determinar etiqueta dominante
		arsort( $scores );
		$top_label = key( $scores );
		$top_count = current( $scores );
		$total     = max( 1, array_sum( $scores ) );

		// Si no hay señales claras, es neutral
		if ( 0 === $top_count ) {
			$top_label = 'neutral';
			$score     = 0.5;
		} else {
			$score = min( 1.0, $top_count / max( 3, $total * 0.5 ) );
		}

		return array(
			'label'          => $top_label,
			'score'          => round( $score, 2 ),
			'keywords_found' => array_unique( array_slice( $found, 0, 5 ) ),
			'method'         => 'rules',
		);
	}

	/**
	 * Análisis con IA (más preciso, especialmente con textos largos).
	 */
	private function analyze_with_ai( $text, $provider, $options ) {
		$excerpt = mb_substr( $text, 0, 800 ); // Ahorrar tokens
		$prompt  = <<<PROMPT
Analiza el sentimiento del siguiente texto de un estudiante universitario.
Clasifícalo en una de estas categorías: frustrated, confused, positive, disengaged, neutral.

Texto: "{$excerpt}"

Responde SOLO con JSON:
{"label": "<categoría>", "score": <0.0-1.0>, "reason": "<explicación breve en español>"}
PROMPT;

		$raw = $this->call_provider( $provider, $options, $prompt, 100 );
		if ( is_wp_error( $raw ) ) { return $raw; }

		if ( preg_match( '/\{[^}]+\}/', $raw, $m ) ) {
			$data = json_decode( $m[0], true );
		} else {
			$data = json_decode( $raw, true );
		}

		if ( ! is_array( $data ) || ! isset( $data['label'] ) ) {
			return new WP_Error( 'parse', __( 'Respuesta IA inválida.', 'atora-lms' ) );
		}

		$valid_labels = array( 'frustrated', 'confused', 'positive', 'disengaged', 'neutral' );
		return array(
			'label'          => in_array( $data['label'], $valid_labels, true ) ? $data['label'] : 'neutral',
			'score'          => max( 0.0, min( 1.0, (float) ( $data['score'] ?? 0.5 ) ) ),
			'keywords_found' => array(),
			'reason'         => sanitize_text_field( (string) ( $data['reason'] ?? '' ) ),
			'method'         => 'ai',
		);
	}

	// ── Alerta al profesor ────────────────────────────────────────────────────────

	private function maybe_alert_teacher( $user_id, $course_id, $sentiment, $text ) {
		$meta_key  = self::ALERT_META . $course_id . '_' . $sentiment;
		$last_sent = (int) get_user_meta( $user_id, $meta_key, true );

		if ( $last_sent && ( time() - $last_sent ) < self::ALERT_COOLDOWN ) {
			return; // anti-spam
		}

		$course       = get_post( $course_id );
		$teacher_id   = $course ? (int) $course->post_author : 0;
		$teacher      = $teacher_id ? get_userdata( $teacher_id ) : null;
		$student      = get_userdata( $user_id );

		if ( ! $teacher || ! $teacher->user_email ) { return; }

		$student_name   = $student ? $student->display_name : "ID:{$user_id}";
		$sentiment_text = array(
			'frustrated' => 'frustración',
			'disengaged' => 'desconexión / desmotivación',
		)[ $sentiment ] ?? $sentiment;

		$subject = sprintf(
			'[Atora] Alerta: %s muestra %s en el curso %s',
			$student_name,
			$sentiment_text,
			get_the_title( $course_id )
		);

		$excerpt = mb_substr( wp_strip_all_tags( $text ), 0, 300 );

		$html_body  = '<p>Hola ' . esc_html( $teacher->display_name ) . ',</p>';
		$html_body .= '<p>El sistema ha detectado señales de <strong>' . esc_html( $sentiment_text ) . '</strong> '
			. 'en la entrega/mensaje de <strong>' . esc_html( $student_name ) . '</strong> '
			. 'en el curso <strong>' . esc_html( get_the_title( $course_id ) ) . '</strong>.</p>';
		$html_body .= '<blockquote style="border-left:3px solid #e5e7eb;padding:0 0 0 1em;'
			. 'color:#374151;font-style:italic;margin:1em 0;">'
			. esc_html( $excerpt ) . '...'
			. '</blockquote>';
		$html_body .= '<p>Te recomendamos ponerte en contacto con el estudiante para ofrecerle apoyo.</p>';

		CLMS_Email::send(
			$teacher->user_email,
			$subject,
			$html_body,
			array( 'headline' => 'Alerta de sentimiento detectado' )
		);
		update_user_meta( $user_id, $meta_key, time() );
	}

	// ── Helpers públicos ──────────────────────────────────────────────────────────

	/**
	 * Obtiene el resultado de sentimiento guardado para una entrega.
	 */
	public static function get_submission_sentiment( $submission_id ) {
		return get_post_meta( absint( $submission_id ), self::META_SUBMISSION, true );
	}

	/**
	 * Etiqueta legible para mostrar en UI.
	 */
	public static function label_text( $label ) {
		$map = array(
			'frustrated'  => '😤 Frustrado/a',
			'confused'    => '🤔 Confundido/a',
			'positive'    => '😊 Positivo',
			'disengaged'  => '😐 Desconectado/a',
			'neutral'     => '😶 Neutral',
		);
		return $map[ $label ] ?? ucfirst( $label );
	}

	// ── Provider helper — delegado a CLMS_AI_Manager ─────────────────────────────

	private function has_api_key( $provider, $options ) {
		unset( $options );
		$manager = clms_core('CLMS_AI_Manager');
		if ( $manager ) {
			return (bool) $manager->get_api_key( $provider );
		}
		return false;
	}

	private function call_provider( $provider, $options, $prompt, $max_tokens = 150 ) {
		unset( $options );

		$manager = clms_core('CLMS_AI_Manager');

		if ( ! $manager ) {
			return new WP_Error( 'clms_ai_manager_missing', __( 'CLMS_AI_Manager no está disponible.', 'atora-lms' ) );
		}

		return $manager->chat(
			array( array( 'role' => 'user', 'content' => $prompt ) ),
			array(
				'provider'    => $provider,
				'max_tokens'  => $max_tokens,
				'temperature' => 0.0,
				'timeout'     => 20,
			)
		);
	}
}
