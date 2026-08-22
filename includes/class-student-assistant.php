<?php
/**
 * CLMS_Student_Assistant
 *
 * Chat de IA para estudiantes y visitantes. Opera en dos modos:
 *
 *   MODO ACADÉMICO  — disponible para alumnos inscritos en un curso.
 *     Conoce el contenido de las lecciones, las transcripciones,
 *     el progreso del estudiante y puede responder dudas del temario.
 *
 *   MODO VENTAS — disponible para visitantes en páginas comerciales.
 *     Actúa como asesor de admisiones: explica el curso, responde
 *     preguntas sobre requisitos, instructor, precio y beneficios,
 *     y guía hacia la inscripción.
 *
 * El asistente aprende del contenido indexado en CLMS_AI_Knowledge_Base.
 *
 * Shortcode:  [clms_student_chat]
 * Auto-inject: pages de lm_course (detecta modo automáticamente).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Student_Assistant {

	const MAX_HISTORY    = 10;    // pares de mensajes por sesión
	const RATE_LIMIT_REQ = 15;    // máximo requests
	const RATE_LIMIT_SEC = 300;   // en X segundos (5 minutos)

	public function __construct() {
		add_shortcode( 'clms_student_chat', array( $this, 'shortcode' ) );

		add_action( 'wp_footer',           array( $this, 'maybe_inject_widget' ) );
		add_action( 'wp_enqueue_scripts',  array( $this, 'register_assets' ) );

		add_action( 'wp_ajax_clms_sa_chat',           array( $this, 'ajax_chat' ) );
		add_action( 'wp_ajax_nopriv_clms_sa_chat',    array( $this, 'ajax_chat' ) );
		add_action( 'wp_ajax_clms_sa_summarize',      array( $this, 'ajax_summarize' ) );
	}

	// ── AJAX: resumen de sesión ───────────────────────────────────────────────────

	public function ajax_summarize() {
		$nonce     = isset( $_POST['nonce'] )     ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
		$history   = isset( $_POST['history'] ) && is_array( $_POST['history'] )
			? array_map( function( $h ) {
				return array(
					'role'    => sanitize_key( isset( $h['role'] ) ? $h['role'] : 'user' ),
					'content' => sanitize_textarea_field( isset( $h['content'] ) ? $h['content'] : '' ),
				);
			}, wp_unslash( $_POST['history'] ) )
			: array();

		if ( ! wp_verify_nonce( $nonce, 'clms_sa_nonce_' . $course_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'atora-lms' ) ), 403 );
		}

		if ( empty( $history ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin historial para resumir.', 'atora-lms' ) ) );
		}

		$transcript = '';
		foreach ( $history as $turn ) {
			$role        = 'user' === $turn['role'] ? 'Estudiante' : 'Asistente';
			$transcript .= "{$role}: {$turn['content']}\n";
		}

		$prompt = "Analiza esta conversación de tutoría académica y genera un resumen estructurado:\n\n"
			. $transcript . "\n\n"
			. "Genera el resumen con estas secciones (en markdown):\n"
			. "## Temas tratados\n(lista de conceptos o preguntas discutidas)\n\n"
			. "## Puntos clave aprendidos\n(lo que el estudiante comprendió o avanzó)\n\n"
			. "## Dudas pendientes\n(preguntas que quedaron sin resolver o que conviene profundizar)\n\n"
			. "## Próximos pasos recomendados\n(1-3 acciones concretas que el estudiante puede hacer)\n\n"
			. "Sé conciso. Máximo 200 palabras en total.";

		$reply = $this->call_ai(
			'Eres un asistente educativo que genera resúmenes de sesiones de tutoría. Responde siempre en español.',
			array( array( 'role' => 'user', 'content' => $prompt ) ),
			'academic',
			array(
				'course_id'     => $course_id,
				'action_label'  => 'session_summary',
			)
		);

		if ( is_wp_error( $reply ) ) {
			wp_send_json_error( array( 'message' => $reply->get_error_message() ) );
		}

		// Guardar en user meta para que el profesor lo pueda ver
		if ( is_user_logged_in() && $course_id ) {
			$user_id  = get_current_user_id();
			$key      = '_clms_chat_summary_' . $course_id;
			$existing = (array) get_user_meta( $user_id, $key, true );
			$existing[] = array(
				'date'    => current_time( 'mysql' ),
				'summary' => $reply,
				'turns'   => count( $history ),
			);
			// Mantener solo los últimos 10 resúmenes
			update_user_meta( $user_id, $key, array_slice( $existing, -10 ) );
		}

		wp_send_json_success( array( 'summary' => $reply ) );
	}

	// ── Assets ───────────────────────────────────────────────────────────────────

	public function register_assets() {
		// Solo cargamos en páginas de curso o lección
		if ( ! is_singular( array( 'lm_course', 'lm_lesson' ) ) ) {
			return;
		}
		wp_enqueue_style(
			'clms-student-assistant',
			false,
			array(),
			defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0'
		);
		$base_url = defined( 'ATORA_LMS_URL' )
			? ATORA_LMS_URL
			: ATORA_LMS_URL;
		wp_enqueue_script(
			'atora-ui',
			$base_url . 'assets/js/atora-ui.js',
			array(),
			defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0',
			true
		);
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'add_ui_i18n_script' ) ) {
			CLMS_Helper::add_ui_i18n_script( 'atora-ui' );
		}
		wp_add_inline_style( 'clms-student-assistant', $this->get_css() );
	}

	// ── Shortcode ────────────────────────────────────────────────────────────────

	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'course_id' => 0,
				'mode'      => 'auto', // auto | academic | sales
			),
			(array) $atts,
			'clms_student_chat'
		);

		$course_id = absint( $atts['course_id'] );
		if ( ! $course_id && is_singular( 'lm_course' ) ) {
			$course_id = get_the_ID();
		}

		if ( ! $course_id ) {
			return '';
		}

		$mode = $this->resolve_mode( $course_id, $atts['mode'] );

		if ( ! $this->is_ai_configured() ) {
			return '';
		}

		wp_add_inline_style( 'clms-student-assistant', $this->get_css() );

		return $this->render_widget( $course_id, $mode, 'inline' );
	}

	// ── Auto-inject ───────────────────────────────────────────────────────────────

	public function maybe_inject_widget() {
		if ( ! is_singular( array( 'lm_course', 'lm_lesson' ) ) ) {
			return;
		}

		if ( ! $this->is_ai_configured() ) {
			return;
		}

		$course_id = 0;
		if ( is_singular( 'lm_course' ) ) {
			$course_id = get_the_ID();
		} elseif ( is_singular( 'lm_lesson' ) ) {
			$course_id = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_lesson_course_id( get_the_ID() ) : 0;
		}

		if ( ! $course_id ) {
			return;
		}

		$mode = $this->resolve_mode( $course_id, 'auto' );

		echo $this->render_widget( $course_id, $mode, 'floating' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// ── AJAX: chat ────────────────────────────────────────────────────────────────

	public function ajax_chat() {
		$nonce     = isset( $_POST['nonce'] )     ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
		$message   = isset( $_POST['message'] )   ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$mode      = isset( $_POST['mode'] )      ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'academic';
		$history   = isset( $_POST['history'] ) && is_array( $_POST['history'] )
			? array_map( function( $h ) {
				return array(
					'role'    => sanitize_key( isset( $h['role'] ) ? $h['role'] : 'user' ),
					'content' => sanitize_textarea_field( isset( $h['content'] ) ? $h['content'] : '' ),
				);
			}, array_slice( wp_unslash( $_POST['history'] ), -self::MAX_HISTORY * 2 ) )
			: array();

		if ( ! wp_verify_nonce( $nonce, 'clms_sa_nonce_' . $course_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'atora-lms' ) ), 403 );
		}

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Curso no válido.', 'atora-lms' ) ), 400 );
		}

		if ( '' === trim( $message ) ) {
			wp_send_json_error( array( 'message' => __( 'Mensaje vacío.', 'atora-lms' ) ), 400 );
		}

		// Rate limiting
		$rate_key = is_user_logged_in()
			? 'clms_sa_rate_u_' . get_current_user_id()
			: 'clms_sa_rate_g_' . $this->get_client_ip();
		if ( ! $this->check_rate_limit( $rate_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Demasiadas preguntas seguidas. Espera un momento.', 'atora-lms' ) ), 429 );
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
			wp_send_json_error( array( 'message' => $reply->get_error_message() ) );
		}

		wp_send_json_success( array( 'reply' => $reply ) );
	}

	// ── System prompt ────────────────────────────────────────────────────────────

	protected function build_system_prompt( $course_id, $query, $mode ) {
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
			$user         = get_user_by( 'id', $user_id );
			$user_name    = $user ? ( $user->display_name ?: $user->user_login ) : 'estudiante';
			$completed    = (array) get_user_meta( $user_id, '_clms_completed_lessons', true );
			$lesson_ids   = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_lessons( $course_id ) : array();
			$total        = count( $lesson_ids );
			$done         = count( array_intersect( array_map( 'absint', $completed ), array_map( 'absint', $lesson_ids ) ) );
			$progress     = $total > 0 ? round( $done / $total * 100 ) : 0;

			$lines[] = "Eres el Asistente Académico del curso \"{$course_title}\".";
			$lines[] = "Estás hablando con {$user_name}, quien lleva un {$progress}% del curso completado ({$done} de {$total} lecciones).";
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

	// ── Llamada a la IA ───────────────────────────────────────────────────────────

	protected function call_ai( $system, $messages, $mode = 'academic', $context = array() ) {
		$copilots = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Copilots') : null;
		$context  = is_array( $context ) ? $context : array();
		$context['screen'] = 'student_assistant';

		if ( $copilots && method_exists( $copilots, 'run_text' ) ) {
			$copilot_type = ( 'sales' === sanitize_key( (string) $mode ) ) ? 'commercial' : 'student';
			return $copilots->run_text(
				$copilot_type,
				'chat',
				(array) $messages,
				array(
					'system'      => (string) $system,
					'max_tokens'  => 800,
					'temperature' => 0.7,
					'timeout'     => 60,
				),
				$context
			);
		}

		$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;

		if ( ! $manager || ! method_exists( $manager, 'chat' ) ) {
			return new WP_Error( 'clms_ai_manager_missing', __( 'CLMS_AI_Manager no está disponible.', 'atora-lms' ) );
		}

		return $manager->chat(
			(array) $messages,
			array(
				'system'      => (string) $system,
				'max_tokens'  => 800,
				'temperature' => 0.7,
				'timeout'     => 60,
			)
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

	protected function resolve_mode( $course_id, $requested ) {
		if ( 'academic' === $requested ) {
			return 'academic';
		}
		if ( 'sales' === $requested ) {
			return 'sales';
		}

		// Auto: comercial para no inscritos en cursos con modo commercial
		$commercial_mode = (string) get_post_meta( $course_id, '_clms_commercial_mode', true );
		$user_id         = get_current_user_id();

		if ( ! $user_id ) {
			return 'sales';
		}

		if ( 'commercial' === $commercial_mode ) {
			$is_admin    = current_user_can( 'manage_options' ) || current_user_can( 'clms_manage_courses' );
			$is_enrolled = class_exists( 'CLMS_Helper' )
				? CLMS_Helper::user_is_enrolled_in_course( $user_id, $course_id )
				: false;

			if ( ! $is_admin && ! $is_enrolled ) {
				return 'sales';
			}
		}

		return 'academic';
	}

	protected function is_ai_configured() {
		$copilots = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Copilots') : null;
		if ( $copilots && method_exists( $copilots, 'is_copilot_enabled' ) ) {
			$mode = is_singular( 'lm_course' ) ? $this->resolve_mode( get_the_ID(), 'auto' ) : 'academic';
			$type = ( 'sales' === $mode ) ? 'commercial' : 'student';
			if ( ! $copilots->is_copilot_enabled( $type ) ) {
				return false;
			}
		}

		if ( class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'has_any_generation_key' ) ) {
			return (bool) CLMS_AI_Settings_Service::has_any_generation_key();
		}

		$options = (array) get_option( 'clms_ai_settings', array() );
		$provider = isset( $options['provider'] ) ? $options['provider'] : 'openai';
		$key_map  = array(
			'openai'    => 'openai_api_key',
			'anthropic' => 'anthropic_api_key',
			'gemini'    => 'gemini_api_key',
		);
		$key_opt = isset( $key_map[ $provider ] ) ? $key_map[ $provider ] : 'openai_api_key';
		return ! empty( $options[ $key_opt ] );
	}

	protected function build_messages( $history, $message ) {
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
	protected function check_rate_limit( $key ) {
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
	protected function get_client_ip() {
		return \ATORA_Client_IP::get_hashed();
	}

	// ── Render ────────────────────────────────────────────────────────────────────

	public function render_widget( $course_id, $mode, $layout = 'floating' ) {
		$nonce      = wp_create_nonce( 'clms_sa_nonce_' . $course_id );
		$ajax_url   = admin_url( 'admin-ajax.php' );
		$kb_indexed = class_exists( 'CLMS_AI_Knowledge_Base' )
			? CLMS_AI_Knowledge_Base::instance()->has_index( $course_id )
			: false;

		$avatar       = 'sales' === $mode ? '🎓' : '📚';
		$title        = 'sales' === $mode ? 'Asesor de Admisiones' : 'Asistente de Aprendizaje';
		$placeholder  = 'sales' === $mode
			? '¿Tienes alguna pregunta sobre este curso?'
			: '¿En qué parte del curso necesitas ayuda?';
		$greeting     = 'sales' === $mode
			? '¡Hola! Soy tu asesor para ' . esc_html( get_the_title( $course_id ) ) . '. ¿Qué te gustaría saber?'
			: '¡Hola! Estoy aquí para ayudarte con el contenido del curso. ¿Qué duda tienes?';

		$widget_id = 'clms-sa-' . $course_id;
		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $widget_id ); ?>"
			class="clms-sa-wrap clms-sa-<?php echo esc_attr( $layout ); ?> clms-sa-mode-<?php echo esc_attr( $mode ); ?>"
			data-course="<?php echo esc_attr( $course_id ); ?>"
			data-mode="<?php echo esc_attr( $mode ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-ajax="<?php echo esc_url( $ajax_url ); ?>"
			aria-label="<?php echo esc_attr( $title ); ?>"
		>
			<?php if ( 'floating' === $layout ) : ?>
			<button class="clms-sa-trigger" aria-expanded="false" aria-controls="<?php echo esc_attr( $widget_id ); ?>-panel">
				<span class="clms-sa-trigger-icon"><?php echo $avatar; ?></span>
				<span class="clms-sa-trigger-label"><?php echo esc_html( $title ); ?></span>
			</button>
			<?php endif; ?>

			<div class="clms-sa-panel" id="<?php echo esc_attr( $widget_id ); ?>-panel" <?php echo 'floating' === $layout ? 'hidden' : ''; ?>>
				<div class="clms-sa-header">
					<span class="clms-sa-header-avatar"><?php echo $avatar; ?></span>
					<div>
						<strong class="clms-sa-header-title"><?php echo esc_html( $title ); ?></strong>
						<span class="clms-sa-header-sub">
							<?php echo $kb_indexed
								? esc_html__( 'Conoce el contenido del curso', 'atora-lms' )
								: esc_html__( 'Asistente IA', 'atora-lms' ); ?>
						</span>
					</div>
					<span class="clms-sa-loader" aria-live="polite" data-atora-loader hidden>
						<span class="clms-sa-spinner" aria-hidden="true"></span>
						<span class="clms-sa-loader-text">Procesando…</span>
					</span>
					<?php if ( is_user_logged_in() ) : ?>
					<button class="clms-sa-summarize-btn" title="Resumir esta sesión" aria-label="Resumir sesión">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M9 12h6M9 16h4M5 4h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
					</button>
					<?php endif; ?>
					<?php if ( 'floating' === $layout ) : ?>
					<button class="clms-sa-close" aria-label="Cerrar">&times;</button>
					<?php endif; ?>
				</div>

				<div class="clms-sa-messages" role="log" aria-live="polite">
					<div class="clms-sa-msg clms-sa-msg--bot">
						<span class="clms-sa-msg-text"><?php echo esc_html( $greeting ); ?></span>
					</div>
				</div>

				<form class="clms-sa-form" autocomplete="off">
					<div class="clms-sa-input-wrap">
						<textarea
							class="clms-sa-input"
							rows="1"
							placeholder="<?php echo esc_attr( $placeholder ); ?>"
							maxlength="500"
							aria-label="Escribe tu pregunta"
						></textarea>
						<button type="submit" class="clms-sa-send" aria-label="Enviar">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
								<path d="M22 2L11 13M22 2L15 22l-4-9-9-4 20-7z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</button>
					</div>
					<p class="clms-sa-disclaimer">Respuestas generadas por IA. Verifica información importante.</p>
				</form>
			</div>
		</div>

		<script>
		(function(){
			var wrap   = document.getElementById(<?php echo wp_json_encode( $widget_id ); ?>);
			if (!wrap) return;

			var panel     = wrap.querySelector('.clms-sa-panel');
			var trigger   = wrap.querySelector('.clms-sa-trigger');
			var closeBtn  = wrap.querySelector('.clms-sa-close');
			var msgBox    = wrap.querySelector('.clms-sa-messages');
			var form      = wrap.querySelector('.clms-sa-form');
			var textarea  = wrap.querySelector('.clms-sa-input');
			var sendBtn   = wrap.querySelector('.clms-sa-send');
			var loader    = wrap.querySelector('.clms-sa-loader');
			var history   = [];

			function setLoading(isLoading) {
				if (loader) {
					loader.hidden = !isLoading;
				}
				wrap.classList.toggle('clms-sa-loading', !!isLoading);
				if (window.ATORA && window.ATORA.ui && window.ATORA.ui.setLoading) {
					window.ATORA.ui.setLoading(wrap, !!isLoading);
				}
			}

			// Botón de resumen
			var summarizeBtn = wrap.querySelector('.clms-sa-summarize-btn');
			if (summarizeBtn) {
				summarizeBtn.addEventListener('click', function(){
					if (history.length < 2) {
						appendMsg('Necesitamos al menos una pregunta y respuesta para generar un resumen.', 'bot');
						return;
					}
					summarizeBtn.disabled = true;
					setLoading(true);
					var typing = appendTyping();
					var data = new FormData();
					data.append('action',    'clms_sa_summarize');
					data.append('nonce',     wrap.dataset.nonce);
					data.append('course_id', wrap.dataset.course);
					history.forEach(function(h, i){
						data.append('history[' + i + '][role]',    h.role);
						data.append('history[' + i + '][content]', h.content);
					});
					fetch(wrap.dataset.ajax, { method: 'POST', credentials: 'same-origin', body: data })
					.then(function(r){ return r.json(); })
					.then(function(res){
						typing.remove();
						summarizeBtn.disabled = false;
						setLoading(false);
						if (res.success && res.data && res.data.summary) {
							appendMsg('📋 **Resumen de tu sesión:**\n\n' + res.data.summary, 'bot');
						} else {
							appendMsg('No pude generar el resumen ahora. Intenta de nuevo.', 'bot');
						}
					})
					.catch(function(){
						typing.remove();
						summarizeBtn.disabled = false;
						setLoading(false);
					});
				});
			}

			// Toggle flotante
			if (trigger) {
				trigger.addEventListener('click', function(){
					var open = !panel.hasAttribute('hidden');
					if (open) {
						panel.setAttribute('hidden', '');
						trigger.setAttribute('aria-expanded', 'false');
					} else {
						panel.removeAttribute('hidden');
						trigger.setAttribute('aria-expanded', 'true');
						textarea.focus();
					}
				});
			}
			if (closeBtn) {
				closeBtn.addEventListener('click', function(){
					panel.setAttribute('hidden', '');
					if (trigger) trigger.setAttribute('aria-expanded', 'false');
				});
			}

			// Auto-resize textarea
			textarea.addEventListener('input', function(){
				this.style.height = 'auto';
				this.style.height = Math.min(this.scrollHeight, 120) + 'px';
			});

			// Enter para enviar (Shift+Enter = salto de línea)
			textarea.addEventListener('keydown', function(e){
				if (e.key === 'Enter' && !e.shiftKey) {
					e.preventDefault();
					form.dispatchEvent(new Event('submit'));
				}
			});

			function appendMsg(text, role) {
				var div = document.createElement('div');
				div.className = 'clms-sa-msg clms-sa-msg--' + (role === 'user' ? 'user' : 'bot');
				var span = document.createElement('span');
				span.className = 'clms-sa-msg-text';
				// Renderizar saltos de línea y markdown básico
				span.innerHTML = text
					.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
					.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
					.replace(/\*(.+?)\*/g,'<em>$1</em>')
					.replace(/\n/g,'<br>');
				div.appendChild(span);
				msgBox.appendChild(div);
				msgBox.scrollTop = msgBox.scrollHeight;
				return div;
			}

			function appendTyping() {
				var div = document.createElement('div');
				div.className = 'clms-sa-msg clms-sa-msg--bot clms-sa-typing';
				div.innerHTML = '<span class="clms-sa-msg-text"><span class="clms-sa-dots"><span></span><span></span><span></span></span></span>';
				msgBox.appendChild(div);
				msgBox.scrollTop = msgBox.scrollHeight;
				return div;
			}

			form.addEventListener('submit', function(e){
				e.preventDefault();
				var msg = textarea.value.trim();
				if (!msg || sendBtn.disabled) return;

				appendMsg(msg, 'user');
				history.push({ role: 'user', content: msg });
				textarea.value = '';
				textarea.style.height = 'auto';
				sendBtn.disabled = true;

				setLoading(true);
				var typing = appendTyping();

				var data = new FormData();
				data.append('action',    'clms_sa_chat');
				data.append('nonce',     wrap.dataset.nonce);
				data.append('course_id', wrap.dataset.course);
				data.append('mode',      wrap.dataset.mode);
				data.append('message',   msg);
				history.slice(-<?php echo self::MAX_HISTORY * 2; ?>).forEach(function(h, i){
					data.append('history[' + i + '][role]',    h.role);
					data.append('history[' + i + '][content]', h.content);
				});

				fetch(wrap.dataset.ajax, {
					method: 'POST',
					credentials: 'same-origin',
					body: data
				})
				.then(function(r){ return r.json(); })
				.then(function(res){
					typing.remove();
					sendBtn.disabled = false;
					setLoading(false);
					if (res.success && res.data && res.data.reply) {
						appendMsg(res.data.reply, 'bot');
						history.push({ role: 'assistant', content: res.data.reply });
						// Mantener historial acotado
						if (history.length > <?php echo self::MAX_HISTORY * 2; ?>) {
							history = history.slice(-<?php echo self::MAX_HISTORY * 2; ?>);
						}
					} else {
						var errMsg = (res.data && res.data.message) ? res.data.message : 'No pude obtener una respuesta. Intenta de nuevo.';
						appendMsg(errMsg, 'bot');
					}
				})
				.catch(function(){
					typing.remove();
					sendBtn.disabled = false;
					setLoading(false);
					appendMsg('Error de conexión. Comprueba tu internet e intenta de nuevo.', 'bot');
				});
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	// ── CSS ───────────────────────────────────────────────────────────────────────

	protected function get_css() {
		return '
/* ── Student Assistant ─────────────────────────────────────────────────── */
.clms-sa-wrap{font-family:inherit;--sa-primary:#6366f1;--sa-bg:#fff;--sa-border:#e5e7eb;--sa-radius:16px}
.clms-sa-wrap *{box-sizing:border-box}

/* Flotante */
.clms-sa-floating{position:fixed;bottom:24px;right:24px;z-index:9990;display:flex;flex-direction:column;align-items:flex-end;gap:12px}
.clms-sa-trigger{display:flex;align-items:center;gap:8px;background:var(--sa-primary);color:#fff;border:none;border-radius:999px;padding:12px 20px 12px 14px;cursor:pointer;font-size:14px;font-weight:700;box-shadow:0 4px 20px rgba(99,102,241,.4);transition:transform .15s,box-shadow .15s}
.clms-sa-trigger:hover{transform:translateY(-2px);box-shadow:0 6px 24px rgba(99,102,241,.5)}
.clms-sa-trigger-icon{font-size:20px;line-height:1}
.clms-sa-floating .clms-sa-panel{width:360px;max-width:calc(100vw - 32px)}

/* Panel */
.clms-sa-panel{background:var(--sa-bg);border:1px solid var(--sa-border);border-radius:var(--sa-radius);box-shadow:0 8px 40px rgba(15,23,42,.12);display:flex;flex-direction:column;overflow:hidden}
.clms-sa-panel[hidden]{display:none}

/* Header */
.clms-sa-header{display:flex;align-items:center;gap:10px;padding:14px 16px;background:var(--sa-primary);color:#fff}
.clms-sa-header-avatar{font-size:24px;line-height:1}
.clms-sa-header-title{display:block;font-size:15px;font-weight:700}
.clms-sa-header-sub{display:block;font-size:12px;opacity:.8}
.clms-sa-loader{margin-left:auto;display:flex;align-items:center;gap:6px;font-size:11px;opacity:.9}
.clms-sa-spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:clms-sa-spin .9s linear infinite}
.clms-sa-loader-text{white-space:nowrap}
.clms-sa-summarize-btn{margin-left:0;background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:6px;width:28px;height:28px;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;transition:background .15s}
.clms-sa-summarize-btn:hover{background:rgba(255,255,255,.3)}
.clms-sa-summarize-btn:disabled{opacity:.5;cursor:not-allowed}
.clms-sa-close{margin-left:6px;background:transparent;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1;padding:0;opacity:.8}
.clms-sa-close:hover{opacity:1}

/* Mensajes */
.clms-sa-messages{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px;min-height:200px;max-height:360px}
.clms-sa-msg{max-width:85%;display:flex}
.clms-sa-msg--user{align-self:flex-end;justify-content:flex-end}
.clms-sa-msg--bot{align-self:flex-start}
.clms-sa-msg-text{padding:10px 14px;border-radius:14px;font-size:14px;line-height:1.6;word-break:break-word}
.clms-sa-msg--user .clms-sa-msg-text{background:var(--sa-primary);color:#fff;border-bottom-right-radius:4px}
.clms-sa-msg--bot .clms-sa-msg-text{background:#f3f4f6;color:#111827;border-bottom-left-radius:4px}

/* Typing dots */
.clms-sa-dots{display:inline-flex;gap:4px;align-items:center;padding:2px 0}
.clms-sa-dots span{width:6px;height:6px;border-radius:50%;background:#9ca3af;animation:clms-sa-bounce .8s infinite}
.clms-sa-dots span:nth-child(2){animation-delay:.15s}
.clms-sa-dots span:nth-child(3){animation-delay:.3s}
@keyframes clms-sa-bounce{0%,80%,100%{transform:translateY(0)}40%{transform:translateY(-6px)}}
@keyframes clms-sa-spin{to{transform:rotate(360deg)}}

/* Input */
.clms-sa-form{padding:12px 16px;border-top:1px solid var(--sa-border)}
.clms-sa-input-wrap{display:flex;gap:8px;align-items:flex-end}
.clms-sa-input{flex:1;border:1px solid var(--sa-border);border-radius:10px;padding:10px 12px;font-size:14px;resize:none;font-family:inherit;line-height:1.5;color:#111827;outline:none;transition:border-color .15s}
.clms-sa-input:focus{border-color:var(--sa-primary)}
.clms-sa-send{flex-shrink:0;width:38px;height:38px;border-radius:10px;background:var(--sa-primary);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s}
.clms-sa-send:hover{background:#4f46e5}
.clms-sa-send:disabled{opacity:.5;cursor:not-allowed}
.clms-sa-disclaimer{margin:6px 0 0;font-size:11px;color:#9ca3af;text-align:center}

/* Inline */
.clms-sa-inline .clms-sa-panel{border-radius:var(--sa-radius)}

/* Modo ventas: acento diferente */
.clms-sa-mode-sales{--sa-primary:#0ea5e9}

@media(max-width:480px){
	.clms-sa-floating{bottom:16px;right:16px}
	.clms-sa-floating .clms-sa-panel{width:calc(100vw - 32px)}
	.clms-sa-messages{max-height:280px}
}';
	}
}
