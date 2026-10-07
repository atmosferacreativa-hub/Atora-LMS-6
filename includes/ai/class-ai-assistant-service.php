<?php
/**
 * Asistente del estudiante (6.32.0): el núcleo de `CLMS_Student_Assistant::ajax_chat()`
 * sin la petición AJAX, para la web y la app (`POST /ai/assistant`).
 *
 * Extracción pura: el cuerpo es el mismo; devuelve el resultado o un WP_Error
 * (con el código HTTP en `status`) en lugar de responder JSON.
 *
 * @package ATORA_LMS
 * @since 6.32.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATORA_AI_Assistant_Service {

	const MAX_HISTORY    = 10;    // pares de mensajes por sesión
	const RATE_LIMIT_REQ = 15;    // máximo requests
	const RATE_LIMIT_SEC = 300;   // en X segundos (5 minutos)

	/**
	 * @param int    $course_id Curso (post).
	 * @param string $message   Pregunta, ya saneada.
	 * @param string $mode      academic | sales.
	 * @param array  $history   Turnos anteriores [{role, content}], ya saneados.
	 * @return array|WP_Error {reply}
	 */
	public function chat( int $course_id, string $message, string $mode, array $history ) {
		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return new WP_Error( 'clms_sa_invalid_course', __( 'Curso no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( '' === trim( $message ) ) {
			return new WP_Error( 'clms_sa_empty_message', __( 'Mensaje vacío.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		// 6.32.0: con sesión, el límite diario por usuario (el mismo contador que la app);
		// sin sesión (modo ventas), el límite por IP de siempre.
		if ( is_user_logged_in() ) {
			$allowed = class_exists( 'ATORA_AI_Usage_Service' ) ? ATORA_AI_Usage_Service::check( get_current_user_id(), ATORA_AI_Usage_Service::ASSISTANT ) : true;
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		} elseif ( ! $this->check_rate_limit( 'clms_sa_rate_g_' . $this->get_client_ip() ) ) {
			return new WP_Error( 'clms_sa_rate_limited', __( 'Demasiadas preguntas seguidas. Espera un momento.', 'atora-lms' ), array( 'status' => 429 ) );
		}

		// Solo modo ventas está disponible para no autenticados
		if ( ! is_user_logged_in() && 'sales' !== $mode ) {
			$mode = 'sales';
		}

		$system  = $this->build_system_prompt( $course_id, $message, $mode );
		$messages = $this->build_messages( $history, $message );

		$reply = $this->call_ai(
			$system,
			$messages,
			$mode,
			array(
				'course_id'    => $course_id,
				'action_label' => 'chat',
				'mode'         => $mode,
			)
		);

		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		return array( 'reply' => $reply );
	}

	public function build_system_prompt( $course_id, $query, $mode ) {
		$course_title = get_the_title( $course_id );
		$lines        = array();

		if ( 'sales' === $mode ) {
			$lines[] = "Eres el Asistente de Admisiones de {$course_title}.";
			$lines[] = 'Tu objetivo es ayudar a los visitantes a entender el curso, resolver sus dudas y motivarlos a inscribirse.';
			$lines[] = 'Eres amigable, entusiasta y honesto. Nunca inventas información — si no sabes algo, lo dices.';
			$lines[] = 'Siempre respondes en español, de forma concisa y clara.';
			$lines[] = 'Si el visitante pregunta sobre precio, inscripción o requisitos, da la información disponible y ofrece el enlace de inscripción si lo tienes.';
		} else {
			$user_id      = get_current_user_id();
			$completed    = (array) get_user_meta( $user_id, '_clms_completed_lessons', true );
			$lesson_ids   = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_lessons( $course_id ) : array();
			$total        = count( $lesson_ids );
			$done         = count( array_intersect( array_map( 'absint', $completed ), array_map( 'absint', $lesson_ids ) ) );
			$progress     = $total > 0 ? round( $done / $total * 100 ) : 0;

			$lines[] = "Eres el Asistente Académico del curso \"{$course_title}\".";
			// 6.32.0: al proveedor no se envía el nombre ni ningún dato que identifique al estudiante.
			$lines[] = "Estás hablando con el estudiante, que lleva un {$progress}% del curso completado ({$done} de {$total} lecciones).";
			$lines[] = 'Tu misión es ayudar al estudiante a comprender el contenido del curso, resolver dudas conceptuales y motivarlo a seguir avanzando.';
			$lines[] = 'Respondes siempre en español, con tono pedagógico, paciente y motivador.';
			$lines[] = 'Cuando expliques conceptos, usa ejemplos prácticos del contexto del curso.';
			$lines[] = 'No hagas las tareas por el estudiante, sino guíalo para que llegue a las respuestas.';
		}

		// ── Contexto RAG desde la Knowledge Base ──────────────────────────────
		if ( class_exists( 'CLMS_AI_Knowledge_Base' ) ) {
			$kb = CLMS_AI_Knowledge_Base::instance();
			$context = '';

			if ( 'sales' === $mode && method_exists( $kb, 'get_public_context_for_query' ) ) {
				$context = $kb->get_public_context_for_query( $course_id, $query, 3 );
			} else {
				$context = $kb->get_context_for_query( $course_id, $query, 4 );
			}

			if ( $context ) {
				$lines[] = '';
				$lines[] = '--- CONTENIDO DEL CURSO (fragmentos relevantes) ---';
				$lines[] = $context;
				$lines[] = '--- FIN DEL CONTENIDO ---';
				$lines[] = 'Basa tus respuestas en el contenido anterior siempre que sea pertinente.';
			}
		}

		// ── Datos comerciales en modo ventas ──────────────────────────────────
		if ( 'sales' === $mode ) {
			$price      = (string) get_post_meta( $course_id, '_clms_course_price', true );
			$duration   = (string) get_post_meta( $course_id, '_clms_course_duration', true );
			$cert       = (string) get_post_meta( $course_id, '_clms_course_certificate', true );
			$cta_url    = (string) get_post_meta( $course_id, '_clms_commercial_cta_url', true );
			$cta_label  = (string) get_post_meta( $course_id, '_clms_commercial_cta_label', true ) ?: 'Inscribirme';

			$meta = array_filter( array(
				$price    ? 'Precio: ' . $price : '',
				$duration ? 'Duración: ' . $duration : '',
				$cert     ? 'Certificado: sí' : '',
				$cta_url  ? "Enlace de inscripción: {$cta_url} ({$cta_label})" : '',
			) );

			if ( $meta ) {
				$lines[] = '';
				$lines[] = '--- DATOS DEL CURSO ---';
				$lines[] = implode( "\n", $meta );
			}

			// Instructor
			$author_id = (int) get_post_field( 'post_author', $course_id );
			if ( $author_id ) {
				$author  = get_userdata( $author_id );
				$title   = (string) get_user_meta( $author_id, '_clms_instructor_title', true );
				$tagline = (string) get_user_meta( $author_id, '_clms_instructor_tagline', true );
				if ( $author ) {
					$lines[] = 'Instructor: ' . $author->display_name . ( $title ? " — {$title}" : '' ) . ( $tagline ? ". {$tagline}" : '' );
				}
			}
		}

		return implode( "\n", $lines );
	}

	public function call_ai( $system, $messages, $mode = 'academic', $context = array() ) {
		$copilots = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Copilots') : null;
		$context  = is_array( $context ) ? $context : array();
		$context['screen'] = 'student_assistant';

		if ( $copilots && method_exists( $copilots, 'run_with_meta' ) ) {
			$copilot_type = ( 'sales' === sanitize_key( (string) $mode ) ) ? 'commercial' : 'student';
			// 6.32.0: con metadatos, para registrar el uso por usuario.
			$result = $copilots->run_with_meta(
				$copilot_type,
				'chat',
				(array) $messages,
				array(
					'system'      => (string) $system,
					'max_tokens'  => 800,
					'temperature' => 0.7,
					'timeout'     => 60,
					'source'      => 'assistant',
				),
				$context
			);
			return self::with_usage( $result );
		}

		$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;

		if ( ! $manager || ! method_exists( $manager, 'chat' ) ) {
			return new WP_Error( 'clms_ai_manager_missing', __( 'CLMS_AI_Manager no está disponible.', 'atora-lms' ) );
		}

		return self::with_usage( $manager->chat_with_meta(
			(array) $messages,
			array(
				'system'      => (string) $system,
				'max_tokens'  => 800,
				'temperature' => 0.7,
				'timeout'     => 60,
				'source'      => 'assistant',
			)
		) );
	}

	/** 6.32.0: registra el uso (con sesión) y devuelve solo el texto, como antes. @return string|WP_Error */
	private static function with_usage( $result ) {
		$user_id = get_current_user_id();
		if ( $user_id && class_exists( 'ATORA_AI_Usage_Service' ) ) {
			ATORA_AI_Usage_Service::record(
				$user_id,
				ATORA_AI_Usage_Service::ASSISTANT,
				is_array( $result ) ? (string) ( $result['provider'] ?? '' ) : '',
				is_array( $result ) ? (string) ( $result['model'] ?? '' ) : '',
				is_array( $result ) ? (array) ( $result['usage'] ?? array() ) : array(),
				is_wp_error( $result ) ? 'error' : 'ok'
			);
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return is_array( $result ) ? (string) ( $result['text'] ?? '' ) : (string) $result;
	}

	public function build_messages( $history, $message ) {
		$messages = array();
		foreach ( (array) $history as $turn ) {
			if ( ! empty( $turn['role'] ) && ! empty( $turn['content'] ) ) {
				$messages[] = array(
					'role'    => in_array( $turn['role'], array( 'user', 'assistant' ), true ) ? $turn['role'] : 'user',
					'content' => (string) $turn['content'],
				);
			}
		}
		$messages[] = array( 'role' => 'user', 'content' => $message );
		return $messages;
	}

	/**
	 * PT-6 (6.5.7): antes usaba get_transient()+set_transient()
	 * (lectura-incremento-escritura no atómico) — dos preguntas
	 * concurrentes del mismo usuario/IP podían leer el mismo contador
	 * antes de que cualquiera escribiera, dejando pasar más de
	 * RATE_LIMIT_REQ peticiones (cada una con coste real de IA) bajo
	 * carga. Delega en ATORA_Rate_Limiter::consume(), atómico por
	 * bloqueo de fila. fail_open=false a propósito: si la tabla de
	 * rate limit no está disponible (migración incompleta, etc.), el
	 * asistente de IA debe fallar cerrado — nunca abierto — dado el
	 * coste real que cada respuesta genera.
	 *
	 * @param string $key Identificador ya construido por el llamador (user_id o IP).
	 * @return bool
	 */
	public function check_rate_limit( $key ) {
		return \ATORA_Rate_Limiter::consume( 'student_assistant', (string) $key, self::RATE_LIMIT_REQ, self::RATE_LIMIT_SEC, false );
	}

	/**
	 * PT-1 (6.5.5): delega en el resolutor centralizado
	 * (ATORA_Client_IP) — esta clase era la fuente original de la
	 * lógica de proxy de confianza; se extrajo a un helper compartido
	 * en 6.5.5 para que otros módulos (Forms_Builder, etc.) dejen de
	 * replicarla de forma insegura. El método público se mantiene
	 * (hash de la IP, nunca se persiste en claro) para no romper a
	 * quien ya dependa de esta clase.
	 */
	public function get_client_ip() {
		return \ATORA_Client_IP::get_hashed();
	}


	// ── App (6.32.0): preguntar sobre una lección ─────────────────────────────

	const APP_HISTORY = 6;
	const APP_TIMEOUT = 30;

	/**
	 * Pregunta del estudiante sobre una lección (la app). Sin datos que lo
	 * identifiquen; no se guarda la conversación (solo el registro de uso).
	 *
	 * @return array|WP_Error {reply}
	 */
	public function ask_lesson( int $user_id, int $wp_course, int $wp_lesson, string $message, array $history ) {
		$message = trim( $message );
		if ( '' === $message ) {
			return new WP_Error( 'atora_ai_empty', __( 'Escribe tu pregunta.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$allowed = ATORA_AI_Usage_Service::check( $user_id, ATORA_AI_Usage_Service::ASSISTANT );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$manager = function_exists( 'clms_core' ) ? clms_core( 'CLMS_AI_Manager' ) : null;
		if ( ! $manager || ! method_exists( $manager, 'chat_with_meta' ) ) {
			return new WP_Error( 'atora_ai_unavailable', __( 'El asistente no está disponible.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$turns  = array_slice( array_values( $history ), -self::APP_HISTORY );
		$result = $manager->chat_with_meta(
			$this->build_messages( $turns, $message ),
			array(
				'system'      => $this->lesson_system_prompt( $wp_course, $wp_lesson, $message ),
				'max_tokens'  => 700,
				'temperature' => 0.5,
				'timeout'     => self::APP_TIMEOUT,
				'source'      => 'assistant',
			)
		);
		ATORA_AI_Usage_Service::record(
			$user_id,
			ATORA_AI_Usage_Service::ASSISTANT,
			is_array( $result ) ? (string) ( $result['provider'] ?? '' ) : '',
			is_array( $result ) ? (string) ( $result['model'] ?? '' ) : '',
			is_array( $result ) ? (array) ( $result['usage'] ?? array() ) : array(),
			is_wp_error( $result ) ? 'error' : 'ok'
		);
		if ( is_wp_error( $result ) ) {
			return self::timed_out( $result )
				? new WP_Error( 'atora_ai_timeout', __( 'El asistente tardó demasiado en responder. Intenta de nuevo con una pregunta más corta.', 'atora-lms' ), array( 'status' => 504 ) )
				: new WP_Error( 'atora_ai_error', __( 'El asistente no pudo responder ahora. Intenta más tarde.', 'atora-lms' ), array( 'status' => 502 ) );
		}
		return array( 'reply' => trim( (string) ( $result['text'] ?? '' ) ) );
	}

	private static function timed_out( WP_Error $error ): bool {
		$text = strtolower( $error->get_error_message() . ' ' . wp_json_encode( $error->get_error_data() ) );
		return false !== strpos( $text, 'timed out' ) || false !== strpos( $text, 'curl error 28' ) || false !== strpos( $text, 'timeout' );
	}

	/** Instrucciones y contexto de una lección: contenido, base de conocimiento y enunciados (sin resolverlos). */
	public function lesson_system_prompt( int $wp_course, int $wp_lesson, string $query ): string {
		$course  = (string) get_the_title( $wp_course );
		$lesson  = (string) get_the_title( $wp_lesson );
		$content = trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $wp_lesson ) ) );
		$lines   = array(
			"Eres el Asistente Académico del curso \"{$course}\". Estás ayudando al estudiante con la lección \"{$lesson}\".",
			'Respondes en español, con tono pedagógico y claro, basándote en el contenido de la lección y del curso.',
			'No inventes información: si el contenido no lo cubre, dilo y sugiere a quién o dónde preguntar.',
			'IMPORTANTE: no resuelvas evaluaciones (quizzes) ni tareas del curso. Si la pregunta pide la respuesta de una evaluación o tarea, no la des: orienta con pistas, explica el concepto y remite al contenido para que el estudiante llegue solo a la respuesta.',
		);
		if ( '' !== $content ) {
			$lines[] = '';
			$lines[] = '--- CONTENIDO DE LA LECCIÓN ---';
			$lines[] = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 6000 ) : substr( $content, 0, 6000 );
			$lines[] = '--- FIN DE LA LECCIÓN ---';
		}
		if ( class_exists( 'CLMS_AI_Knowledge_Base' ) ) {
			$context = (string) CLMS_AI_Knowledge_Base::instance()->get_context_for_query( $wp_course, $query, 4 );
			if ( '' !== $context ) {
				$lines[] = '';
				$lines[] = '--- CONTENIDO DEL CURSO (fragmentos relevantes) ---';
				$lines[] = $context;
				$lines[] = '--- FIN DEL CONTENIDO ---';
			}
		}
		// Enunciados de la evaluación o la tarea de esta lección: para reconocerlos y no resolverlos.
		$statements = array();
		foreach ( (array) get_post_meta( $wp_lesson, '_clms_quiz_questions', true ) as $question ) {
			$text = is_array( $question ) ? (string) ( $question['question'] ?? $question['text'] ?? '' ) : '';
			if ( '' !== trim( $text ) ) {
				$statements[] = '- ' . sanitize_text_field( $text );
			}
		}
		$type = sanitize_key( (string) get_post_meta( $wp_lesson, 'lm_activity_type', true ) );
		if ( in_array( $type, array( 'tarea', 'task', 'assignment' ), true ) && '' !== $content ) {
			$statements[] = '- La lección es una tarea: su consigna es el contenido de la lección.';
		}
		if ( $statements ) {
			$lines[] = '';
			$lines[] = '--- ENUNCIADOS DE EVALUACIÓN DE ESTA LECCIÓN (no los resuelvas) ---';
			$lines   = array_merge( $lines, $statements );
		}
		return implode( "\n", $lines );
	}
}
