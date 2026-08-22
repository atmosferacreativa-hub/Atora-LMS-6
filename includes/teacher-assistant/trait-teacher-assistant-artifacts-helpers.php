<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Teacher_Assistant_Artifacts_Helpers_Trait {
	// ── Herramienta: Redactar email ───────────────────────────────────────────

	protected function tool_draft_email( $message, $params, $context ) {
		$system   = $this->build_system_prompt( $context );
		$purpose  = isset( $params['purpose'] ) ? $params['purpose'] : $message;
		$tone     = isset( $params['tone'] )    ? $params['tone']    : 'motivador y profesional';
		$audience = isset( $params['audience'] ) ? $params['audience'] : 'todos los estudiantes';

		$prompt = "Redacta una comunicación para enviar a los estudiantes.\n\n"
			. "PROPÓSITO: {$purpose}\n"
			. "TONO: {$tone}\n"
			. "DESTINATARIOS: {$audience}\n"
			. ( $context['course_title'] ? "CURSO: {$context['course_title']}\n" : '' )
			. "\nGenera tres versiones:\n\n"
			. "## 📧 Versión EMAIL (formal, con asunto)\n"
			. "Asunto: [asunto del correo]\n"
			. "[cuerpo del email, 150-250 palabras]\n\n"
			. "## 💬 Versión ANUNCIO CORTO (para foro/muro del curso)\n"
			. "[50-80 palabras, directo y motivador]\n\n"
			. "## 📱 Versión NOTIFICACIÓN (para push/WhatsApp)\n"
			. "[máximo 2 oraciones, máximo 160 caracteres]\n\n"
			. "Usa un lenguaje cercano, empático y que motive a la acción.";

		$raw = $this->call_ai( $system, array( array( 'role' => 'user', 'content' => $prompt ) ) );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		return array(
			'tool'    => 'draft_email',
			'text'    => $raw,
			'actions' => array(
				array( 'label' => __( 'Copiar al portapapeles', 'atora-lms' ), 'type' => 'copy' ),
			),
		);
	}

	// ── Guardado de artefactos ────────────────────────────────────────────────

	protected function save_rubric_artifact( $raw, $course_id ) {
		if ( ! class_exists( 'CLMS_Rubric' ) ) {
			return new WP_Error( 'no_rubric_class', __( 'El módulo de rúbricas no está disponible.', 'atora-lms' ) );
		}

		$parsed = $this->parse_rubric( $raw );

		if ( empty( $parsed ) ) {
			return new WP_Error( 'parse_error', __( 'No se pudieron extraer criterios de la rúbrica generada.', 'atora-lms' ) );
		}

		// Obtener nombre del curso para el título
		$course_title = $course_id ? get_the_title( $course_id ) : '';
		$rubric_title = 'Rúbrica IA' . ( $course_title ? ': ' . $course_title : '' ) . ' · ' . date_i18n( 'd/m/Y' );

		$post_id = wp_insert_post( array(
			'post_type'   => CLMS_Rubric::CPT,
			'post_title'  => $rubric_title,
			'post_status' => 'publish',
			'post_author' => get_current_user_id(),
		) );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, CLMS_Rubric::META_CRITERIA, $parsed );

		return array(
			'saved'     => true,
			'rubric_id' => $post_id,
			'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
			'title'     => $rubric_title,
			'criteria'  => count( $parsed ),
			'message'   => __( 'Rúbrica guardada. Puedes asignarla a una lección desde el metabox de relación.', 'atora-lms' ),
		);
	}

	protected function save_quiz_artifact( $raw, $lesson_id ) {
		$questions = $this->parse_quiz( $raw );

		if ( empty( $questions ) ) {
			return new WP_Error( 'parse_error', __( 'No se pudieron extraer preguntas del quiz generado.', 'atora-lms' ) );
		}

		// Adaptar al formato de _clms_quiz_questions
		$formatted = array_map( function( $q ) {
			return array(
				'question'    => $q['question'],
				'type'        => 'multiple_choice',
				'options'     => array_values( $q['options'] ),
				'correct'     => array_search( $q['correct'], array( 'A', 'B', 'C', 'D' ), true ),
				'explanation' => $q['explanation'],
				'difficulty'  => $q['difficulty'],
				'source'      => 'ai_assistant',
			);
		}, $questions );

		if ( $lesson_id && 'lm_lesson' === get_post_type( $lesson_id ) ) {
			// Agregar al banco existente
			$existing = get_post_meta( $lesson_id, '_clms_quiz_questions', true );
			$existing = is_array( $existing ) ? $existing : array();
			$merged   = array_merge( $existing, $formatted );
			update_post_meta( $lesson_id, '_clms_quiz_questions', $merged );
			update_post_meta( $lesson_id, '_clms_lesson_ta_quiz_draft', $raw );

			return array(
				'saved'    => true,
				'count'    => count( $formatted ),
				'total'    => count( $merged ),
				'edit_url' => get_edit_post_link( $lesson_id, 'raw' ),
				'message'  => count( $formatted ) . ' preguntas agregadas al banco de la lección.',
			);
		}

		return new WP_Error( 'no_lesson', __( 'Abre una lección para guardar el banco de preguntas.', 'atora-lms' ) );
	}

	protected function save_lesson_notes_artifact( $content, $lesson_id ) {
		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'no_lesson', __( 'Abre una lección para guardar las notas.', 'atora-lms' ) );
		}

		update_post_meta( $lesson_id, '_clms_lesson_ta_notes', wp_kses_post( $content ) );

		return array(
			'saved'    => true,
			'edit_url' => get_edit_post_link( $lesson_id, 'raw' ),
			'message'  => __( 'Notas guardadas en la lección.', 'atora-lms' ),
		);
	}

	protected function save_presentation_artifact( $content, $lesson_id ) {
		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'no_lesson', __( 'Abre una lección para guardar la presentación.', 'atora-lms' ) );
		}

		update_post_meta( $lesson_id, '_clms_lesson_ta_presentation', wp_kses_post( $content ) );

		return array(
			'saved'    => true,
			'edit_url' => get_edit_post_link( $lesson_id, 'raw' ),
			'message'  => __( 'Presentación guardada en la lección.', 'atora-lms' ),
		);
	}

	protected function save_study_guide_artifact( $content, $lesson_id ) {
		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'no_lesson', __( 'Abre una lección para guardar la guía de estudio.', 'atora-lms' ) );
		}

		update_post_meta( $lesson_id, '_clms_lesson_study_guide', wp_kses_post( $content ) );

		return array(
			'saved'    => true,
			'edit_url' => get_edit_post_link( $lesson_id, 'raw' ),
			'message'  => __( 'Guía de estudio guardada en la lección.', 'atora-lms' ),
		);
	}

	// ── Historial ─────────────────────────────────────────────────────────────

	protected function get_history( $user_id ) {
		$history = get_user_meta( absint( $user_id ), self::USER_META_HISTORY, true );
		return is_array( $history ) ? $history : array();
	}

	protected function save_history( $user_id, $history ) {
		// Mantener máximo MAX_HISTORY_TURNS pares (user+assistant)
		$max = self::MAX_HISTORY_TURNS * 2;
		if ( count( $history ) > $max ) {
			$history = array_slice( $history, -$max );
		}
		update_user_meta( absint( $user_id ), self::USER_META_HISTORY, $history );
	}

	protected function build_messages_from_history( $history, $current_message ) {
		$messages = array();

		// Incluir máximo los últimos 6 turnos del historial para no saturar tokens
		$recent = array_slice( $history, -12 );
		foreach ( $recent as $turn ) {
			if ( isset( $turn['role'], $turn['content'] ) ) {
				$messages[] = array(
					'role'    => in_array( $turn['role'], array( 'user', 'assistant' ), true ) ? $turn['role'] : 'user',
					'content' => mb_substr( (string) $turn['content'], 0, 2000 ),
				);
			}
		}

		$messages[] = array( 'role' => 'user', 'content' => $current_message );

		return $messages;
	}

	protected function describe_tool_call( $tool, $params ) {
		$descriptions = array(
			'plan_course'    => __( 'Generar plan de curso', 'atora-lms' ),
			'research'       => __( 'Investigar tema', 'atora-lms' ),
			'improve_lesson' => __( 'Mejorar lección', 'atora-lms' ),
			'presentation'   => __( 'Crear presentación', 'atora-lms' ),
			'rubric'         => __( 'Crear rúbrica', 'atora-lms' ),
			'quiz'           => __( 'Generar preguntas', 'atora-lms' ),
			'analyze_group'  => __( 'Analizar grupo', 'atora-lms' ),
			'draft_email'    => __( 'Redactar comunicación', 'atora-lms' ),
		);
		return isset( $descriptions[ $tool ] ) ? $descriptions[ $tool ] : $tool;
	}

	// ── API IA ────────────────────────────────────────────────────────────────

	/**
	 * Llama al provider activo. Usa el sistema existente de providers cuando es posible,
	 * con fallback directo a la API de OpenAI.
	 */
	protected function call_ai( $system_prompt, $messages ) {
		$copilots = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Copilots') : null;
		if ( $copilots && method_exists( $copilots, 'run_text' ) ) {
			$lesson_id = isset( $_POST['lesson_id'] ) ? absint( wp_unslash( $_POST['lesson_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $copilots->run_text(
				'teacher',
				'teacher_assistant',
				(array) $messages,
				array(
					'system'      => (string) $system_prompt,
					'max_tokens'  => 3000,
					'temperature' => 0.7,
					'timeout'     => 120,
				),
				array(
					'lesson_id' => $lesson_id,
					'course_id' => $course_id,
					'screen'    => 'teacher_assistant',
				)
			);
		}

		$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;

		if ( ! $manager || ! method_exists( $manager, 'chat' ) ) {
			return new WP_Error( 'clms_ai_manager_missing', __( 'CLMS_AI_Manager no está disponible.', 'atora-lms' ) );
		}

		return $manager->chat(
			(array) $messages,
			array(
				'system'      => (string) $system_prompt,
				'max_tokens'  => 3000,
				'temperature' => 0.7,
				'timeout'     => 120,
			)
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Rate limiter por usuario/acción — PT-5.4 (6.5.8): migrado de
	 * get_transient()/set_transient() (no atómico, y cada renovación
	 * extendía la ventana completa en vez de mantener una ventana fija)
	 * a ATORA_Rate_Limiter. El Teacher Assistant dispara operaciones de
	 * IA con costo real, así que se falla cerrado si el backend del
	 * limiter no está disponible.
	 *
	 * @param int    $user_id        ID del usuario.
	 * @param string $action         Clave única de la acción.
	 * @param int    $max_requests   Máximo de peticiones permitidas en la ventana.
	 * @param int    $window_seconds Duración de la ventana en segundos.
	 * @return bool
	 */
	protected function check_rate_limit( $user_id, $action, $max_requests, $window_seconds ) {
		if ( ! class_exists( 'ATORA_Rate_Limiter' ) ) {
			return false;
		}

		return \ATORA_Rate_Limiter::consume(
			'teacher_assistant_' . sanitize_key( $action ),
			(string) absint( $user_id ),
			max( 1, (int) $max_requests ),
			max( 1, (int) $window_seconds ),
			false
		);
	}

	protected function current_user_can_use_assistant() {
		if ( ! is_user_logged_in() || ! CLMS_Helper::user_can_manage_lms() ) {
			return false;
		}

		$copilots = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Copilots') : null;
		if ( $copilots && method_exists( $copilots, 'is_copilot_enabled' ) ) {
			return (bool) $copilots->is_copilot_enabled( 'teacher' );
		}

		return true;
	}

	/**
	 * Convierte Markdown básico a HTML seguro.
	 */
	protected function markdown_to_html( $text ) {
		$text = esc_html( $text );
		// Headers
		$text = preg_replace( '/^### (.+)$/m',  '<h3>$1</h3>', $text );
		$text = preg_replace( '/^## (.+)$/m',   '<h2>$1</h2>', $text );
		$text = preg_replace( '/^# (.+)$/m',    '<h1>$1</h1>', $text );
		// Bold
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		// Italic
		$text = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $text );
		// Bullets
		$text = preg_replace( '/^[\-\*] (.+)$/m', '<li>$1</li>', $text );
		$text = preg_replace( '/(<li>.*<\/li>)/s', '<ul>$1</ul>', $text );
		// Párrafos
		$text = wpautop( $text );
		return $text;
	}

}
