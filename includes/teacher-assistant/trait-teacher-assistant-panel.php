<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Teacher_Assistant_Panel_Trait {
	// ── Panel Admin ───────────────────────────────────────────────────────────

	public function render_panel() {
		if ( ! $this->current_user_can_use_assistant() ) {
			return;
		}

		// Solo en páginas de edición de lección/curso y dashboards del plugin
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$allowed_bases = array( 'lm_lesson', 'lm_course', 'clms_rubric' );
		$allowed_pages = array( 'clms-dashboard', 'clms-ai-settings', 'clms-ai-hub', 'clms-messages', 'clms-instructor-profile' );
		$on_post_edit  = in_array( $screen->post_type, $allowed_bases, true ) && 'post' === $screen->base;
		$on_plugin_page = in_array( $screen->id, $allowed_pages, true )
			|| 0 === strpos( (string) $screen->id, 'atora' )
			|| 0 === strpos( (string) $screen->id, 'clms' )
			|| false !== strpos( (string) $screen->id, 'clms' );

		if ( ! $on_post_edit && ! $on_plugin_page ) {
			return;
		}

		$nonce     = wp_create_nonce( 'clms_ta_nonce' );
		$lesson_id = 0;
		$course_id = 0;

		global $post;
		if ( $post ) {
			if ( 'lm_lesson' === $post->post_type ) {
				$lesson_id = $post->ID;
				$course_id = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_id_from_lesson( $post->ID ) : 0;
			} elseif ( 'lm_course' === $post->post_type ) {
				$course_id = $post->ID;
			}
		}

		$this->render_panel_html( $nonce, $lesson_id, $course_id );
	}

	protected function render_panel_html( $nonce, $lesson_id, $course_id ) {
		$ajax_url   = admin_url( 'admin-ajax.php' );
		$history    = $this->get_history( get_current_user_id() );
		$has_api    = $this->has_any_api_key();
		$tool_forms = array(
			'plan_course' => array(
				'label'  => __( 'Planificador de curso', 'atora-lms' ),
				'fields' => array(
					array(
						'key'         => 'topic',
						'label'       => __( 'Tema del curso', 'atora-lms' ),
						'type'        => 'text',
						'placeholder' => __( 'Ej. Marketing Digital para emprendedores', 'atora-lms' ),
					),
					array(
						'key'     => 'level',
						'label'   => __( 'Nivel', 'atora-lms' ),
						'type'    => 'select',
						'options' => array(
							__( 'principiante', 'atora-lms' ),
							__( 'intermedio', 'atora-lms' ),
							__( 'avanzado', 'atora-lms' ),
							__( 'mixto', 'atora-lms' ),
						),
					),
					array(
						'key'         => 'duration',
						'label'       => __( 'Duración', 'atora-lms' ),
						'type'        => 'text',
						'placeholder' => __( 'Ej. 8 semanas', 'atora-lms' ),
					),
					array(
						'key'         => 'lessons',
						'label'       => __( 'N.° de lecciones', 'atora-lms' ),
						'type'        => 'number',
						'placeholder' => __( '12', 'atora-lms' ),
					),
				),
			),
			'research' => array(
				'label'  => __( 'Investigación pedagógica', 'atora-lms' ),
				'fields' => array(
					array(
						'key'         => 'topic',
						'label'       => __( 'Tema a investigar', 'atora-lms' ),
						'type'        => 'text',
						'placeholder' => __( 'Ej. Aprendizaje basado en proyectos', 'atora-lms' ),
					),
				),
			),
			'improve_lesson' => array(
				'label'  => __( 'Mejorar lección', 'atora-lms' ),
				'fields' => array(
					array(
						'key'         => 'focus',
						'label'       => __( '¿En qué quieres enfocarte? (opcional)', 'atora-lms' ),
						'type'        => 'text',
						'placeholder' => __( 'Ej. Agregar más ejemplos prácticos', 'atora-lms' ),
					),
				),
			),
			'presentation' => array(
				'label'  => __( 'Crear presentación', 'atora-lms' ),
				'fields' => array(
					array(
						'key'         => 'topic',
						'label'       => __( 'Tema', 'atora-lms' ),
						'type'        => 'text',
						'placeholder' => __( 'Ej. Fundamentos de diseño UX', 'atora-lms' ),
					),
					array(
						'key'         => 'slides',
						'label'       => __( 'N.° de slides', 'atora-lms' ),
						'type'        => 'number',
						'placeholder' => __( '8', 'atora-lms' ),
					),
				),
			),
			'rubric' => array(
				'label'  => __( 'Crear rúbrica', 'atora-lms' ),
				'fields' => array(
					array(
						'key'         => 'objective',
						'label'       => __( 'Objetivo de aprendizaje', 'atora-lms' ),
						'type'        => 'text',
						'placeholder' => __( 'Ej. El estudiante diseña una interfaz accesible...', 'atora-lms' ),
					),
					array(
						'key'         => 'criteria',
						'label'       => __( 'Número de criterios', 'atora-lms' ),
						'type'        => 'number',
						'placeholder' => __( '5', 'atora-lms' ),
					),
				),
			),
			'quiz' => array(
				'label'  => __( 'Generar preguntas', 'atora-lms' ),
				'fields' => array(
					array(
						'key'         => 'topic',
						'label'       => __( 'Tema (dejar en blanco para usar la lección)', 'atora-lms' ),
						'type'        => 'text',
						'placeholder' => __( 'Opcional', 'atora-lms' ),
					),
					array(
						'key'         => 'count',
						'label'       => __( 'Número de preguntas', 'atora-lms' ),
						'type'        => 'number',
						'placeholder' => __( '10', 'atora-lms' ),
					),
					array(
						'key'     => 'difficulty',
						'label'   => __( 'Dificultad', 'atora-lms' ),
						'type'    => 'select',
						'options' => array(
							__( 'baja', 'atora-lms' ),
							__( 'media', 'atora-lms' ),
							__( 'alta', 'atora-lms' ),
							__( 'mixta', 'atora-lms' ),
						),
					),
				),
			),
			'analyze_group' => array(
				'label'  => __( 'Análisis del grupo', 'atora-lms' ),
				'fields' => array(),
			),
			'draft_email' => array(
				'label'  => __( 'Redactar comunicación', 'atora-lms' ),
				'fields' => array(
					array(
						'key'         => 'purpose',
						'label'       => __( 'Propósito del mensaje', 'atora-lms' ),
						'type'        => 'text',
						'placeholder' => __( 'Ej. Recordatorio de entrega final', 'atora-lms' ),
					),
					array(
						'key'     => 'tone',
						'label'   => __( 'Tono', 'atora-lms' ),
						'type'    => 'select',
						'options' => array(
							__( 'motivador y profesional', 'atora-lms' ),
							__( 'formal', 'atora-lms' ),
							__( 'cercano y empático', 'atora-lms' ),
							__( 'urgente pero respetuoso', 'atora-lms' ),
						),
					),
					array(
						'key'     => 'audience',
						'label'   => __( 'Destinatarios', 'atora-lms' ),
						'type'    => 'select',
						'options' => array(
							__( 'todos los estudiantes', 'atora-lms' ),
							__( 'estudiantes con entregas pendientes', 'atora-lms' ),
							__( 'estudiantes destacados', 'atora-lms' ),
							__( 'estudiantes en riesgo', 'atora-lms' ),
						),
					),
				),
			),
		);
		$tool_labels = array(
			'plan_course'    => __( 'Planificar curso', 'atora-lms' ),
			'research'       => __( 'Investigar tema', 'atora-lms' ),
			'improve_lesson' => __( 'Mejorar lección', 'atora-lms' ),
			'presentation'   => __( 'Crear presentación', 'atora-lms' ),
			'rubric'         => __( 'Crear rúbrica', 'atora-lms' ),
			'quiz'           => __( 'Generar preguntas', 'atora-lms' ),
			'analyze_group'  => __( 'Analizar grupo', 'atora-lms' ),
			'draft_email'    => __( 'Redactar comunicación', 'atora-lms' ),
		);
		$i18n = array(
			'confirmClearHistory' => __( '¿Borrar el historial de conversación?', 'atora-lms' ),
			'historyCleared'      => __( 'Historial borrado. ¿En qué te puedo ayudar?', 'atora-lms' ),
			'generate'            => __( 'Generar →', 'atora-lms' ),
			'generating'          => __( 'Generando…', 'atora-lms' ),
			'errorGenerate'       => __( 'Error al generar la respuesta.', 'atora-lms' ),
			'errorConnection'     => __( 'Error de conexión. Inténtalo de nuevo.', 'atora-lms' ),
			'saving'              => __( 'Guardando…', 'atora-lms' ),
			'saveError'           => __( 'Error al guardar.', 'atora-lms' ),
			'saveConnectionError' => __( 'Error de conexión al guardar.', 'atora-lms' ),
			'savedOk'             => __( 'Guardado correctamente.', 'atora-lms' ),
			'viewLink'            => __( 'Ver →', 'atora-lms' ),
			'copiedClipboard'     => __( 'Copiado al portapapeles', 'atora-lms' ),
			'copied'              => __( 'Copiado', 'atora-lms' ),
			'presentationSummary' => __( 'Presentación generada: %s diapositivas.', 'atora-lms' ),
			'toolForms'           => $tool_forms,
			'toolLabels'          => $tool_labels,
		);
		?>
		<div id="clms-ta-panel" class="clms-ta-panel clms-ta-minimized" role="complementary" aria-label="<?php echo esc_attr__( 'Asistente ATORA', 'atora-lms' ); ?>">

			<!-- Toggle button -->
			<button id="clms-ta-toggle" class="clms-ta-toggle" type="button" aria-label="<?php echo esc_attr__( 'Abrir asistente IA', 'atora-lms' ); ?>">
				<span class="clms-ta-toggle-icon">🤖</span>
				<span class="clms-ta-toggle-label"><?php echo esc_html__( 'Asistente', 'atora-lms' ); ?></span>
			</button>

			<!-- Panel -->
			<div class="clms-ta-body" id="clms-ta-body" hidden>

				<!-- Header -->
				<div class="clms-ta-header">
					<div class="clms-ta-header-left">
						<span class="clms-ta-avatar">🤖</span>
						<div>
							<div class="clms-ta-title"><?php echo esc_html__( 'Asistente ATORA', 'atora-lms' ); ?></div>
							<?php if ( $lesson_id ) : ?>
								<div class="clms-ta-ctx"><?php echo esc_html( get_the_title( $lesson_id ) ); ?></div>
							<?php elseif ( $course_id ) : ?>
								<div class="clms-ta-ctx"><?php echo esc_html( get_the_title( $course_id ) ); ?></div>
							<?php endif; ?>
						</div>
					</div>
					<div class="clms-ta-header-right">
						<span id="clms-ta-loader" class="clms-ta-loader" aria-live="polite" data-atora-loader hidden>
							<span class="clms-ta-spinner" aria-hidden="true"></span>
							<span class="clms-ta-loader-text"><?php echo esc_html__( 'Procesando…', 'atora-lms' ); ?></span>
						</span>
						<button type="button" class="clms-ta-icon-btn" id="clms-ta-clear-history" title="<?php echo esc_attr__( 'Borrar historial', 'atora-lms' ); ?>">🗑️</button>
						<button type="button" class="clms-ta-icon-btn" id="clms-ta-minimize" title="<?php echo esc_attr__( 'Minimizar', 'atora-lms' ); ?>">✕</button>
					</div>
				</div>

				<?php if ( ! $has_api ) : ?>
				<div class="clms-ta-no-api">
					<?php
					printf(
						wp_kses(
							__( '⚠️ Configura una API key en <a href="%s">Configuración de IA</a> para activar el asistente.', 'atora-lms' ),
							array(
								'a' => array(
									'href' => true,
								),
							)
						),
						esc_url( admin_url( 'admin.php?page=clms-settings&tab=apis' ) )
					);
					?>
				</div>
				<?php endif; ?>

				<!-- Herramientas rápidas -->
				<div class="clms-ta-tools-bar">
					<button type="button" class="clms-ta-tool-btn" data-tool="plan_course" title="<?php echo esc_attr__( 'Planificar curso', 'atora-lms' ); ?>">📋 <?php echo esc_html__( 'Planificar', 'atora-lms' ); ?></button>
					<button type="button" class="clms-ta-tool-btn" data-tool="research" title="<?php echo esc_attr__( 'Investigar tema', 'atora-lms' ); ?>">🔍 <?php echo esc_html__( 'Investigar', 'atora-lms' ); ?></button>
					<button type="button" class="clms-ta-tool-btn" data-tool="improve_lesson" title="<?php echo esc_attr__( 'Mejorar lección', 'atora-lms' ); ?>">✏️ <?php echo esc_html__( 'Mejorar', 'atora-lms' ); ?></button>
					<button type="button" class="clms-ta-tool-btn" data-tool="presentation" title="<?php echo esc_attr__( 'Crear presentación', 'atora-lms' ); ?>">📊 <?php echo esc_html__( 'Presentación', 'atora-lms' ); ?></button>
					<button type="button" class="clms-ta-tool-btn" data-tool="rubric" title="<?php echo esc_attr__( 'Crear rúbrica', 'atora-lms' ); ?>">📐 <?php echo esc_html__( 'Rúbrica', 'atora-lms' ); ?></button>
					<button type="button" class="clms-ta-tool-btn" data-tool="quiz" title="<?php echo esc_attr__( 'Generar preguntas', 'atora-lms' ); ?>">❓ <?php echo esc_html__( 'Preguntas', 'atora-lms' ); ?></button>
					<button type="button" class="clms-ta-tool-btn" data-tool="analyze_group" title="<?php echo esc_attr__( 'Analizar grupo', 'atora-lms' ); ?>">📊 <?php echo esc_html__( 'Grupo', 'atora-lms' ); ?></button>
					<button type="button" class="clms-ta-tool-btn" data-tool="draft_email" title="<?php echo esc_attr__( 'Redactar comunicación', 'atora-lms' ); ?>">📧 <?php echo esc_html__( 'Email', 'atora-lms' ); ?></button>
				</div>

				<!-- Formulario de herramienta activa -->
				<div id="clms-ta-tool-form" class="clms-ta-tool-form" hidden></div>

				<!-- Mensajes -->
				<div class="clms-ta-messages" id="clms-ta-messages">
					<?php if ( empty( $history ) ) : ?>
					<div class="clms-ta-msg clms-ta-msg--assistant">
						<div class="clms-ta-msg-bubble">
							<?php echo esc_html__( '¡Hola! Soy tu asistente de enseñanza. Puedo ayudarte a planificar cursos, investigar temas, mejorar tus lecciones, crear presentaciones, rúbricas y evaluaciones. ¿Por dónde empezamos?', 'atora-lms' ); ?>
						</div>
					</div>
					<?php else : ?>
					<?php foreach ( array_slice( $history, -10 ) as $turn ) :
						$role = isset( $turn['role'] ) ? $turn['role'] : 'user';
						$class = 'assistant' === $role ? 'clms-ta-msg--assistant' : 'clms-ta-msg--user';
					?>
					<div class="clms-ta-msg <?php echo esc_attr( $class ); ?>">
						<div class="clms-ta-msg-bubble"><?php echo wp_kses_post( nl2br( esc_html( mb_substr( $turn['content'], 0, 500 ) ) ) ); ?></div>
					</div>
					<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<!-- Response area -->
				<div class="clms-ta-response" id="clms-ta-response" hidden>
					<div class="clms-ta-response-text" id="clms-ta-response-text"></div>
					<div class="clms-ta-response-actions" id="clms-ta-response-actions"></div>
				</div>

				<!-- Input -->
				<div class="clms-ta-input-wrap">
					<textarea
						id="clms-ta-input"
						class="clms-ta-input"
						placeholder="<?php echo esc_attr__( 'Escribe tu pregunta o instrucción…', 'atora-lms' ); ?>"
						rows="2"
					></textarea>
					<button type="button" class="clms-ta-send" id="clms-ta-send">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 2L11 13M22 2L15 22l-4-9-9-4 20-7z"/></svg>
					</button>
				</div>

				<div class="clms-ta-footer">
					<span id="clms-ta-status" class="clms-ta-status" data-atora-status></span>
				</div>
			</div>
		</div>

		<style id="clms-ta-styles">
		/* ── Panel container ───────────────────────────────────────────────────── */
		#clms-ta-panel{position:fixed;bottom:24px;right:24px;z-index:99999;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:14px}
		.clms-ta-toggle{display:flex;align-items:center;gap:8px;background:linear-gradient(135deg,#4353ff,#7b2ff7);color:#fff;border:none;border-radius:99px;padding:12px 20px;cursor:pointer;font-size:14px;font-weight:700;box-shadow:0 4px 24px rgba(67,83,255,.4);transition:transform .2s,box-shadow .2s}
		.clms-ta-toggle:hover{transform:translateY(-2px);box-shadow:0 6px 32px rgba(67,83,255,.5)}
		.clms-ta-body{width:440px;max-height:80vh;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.18);display:flex;flex-direction:column;overflow:hidden;margin-bottom:12px;border:1px solid rgba(67,83,255,.15)}

		/* ── Header ──────────────────────────────────────────────────────────── */
		.clms-ta-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:linear-gradient(135deg,#4353ff,#7b2ff7);color:#fff;flex-shrink:0}
		.clms-ta-header-left{display:flex;align-items:center;gap:10px}
		.clms-ta-avatar{font-size:22px;line-height:1}
		.clms-ta-title{font-weight:800;font-size:14px}
		.clms-ta-ctx{font-size:11px;opacity:.75;margin-top:1px;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
		.clms-ta-header-right{display:flex;gap:4px}
		.clms-ta-loader{display:flex;align-items:center;gap:6px;font-size:11px;opacity:.9;margin-right:6px}
		.clms-ta-spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:clms-ta-spin .9s linear infinite}
		.clms-ta-loader-text{white-space:nowrap}
		.clms-ta-icon-btn{background:rgba(255,255,255,.2);border:none;color:#fff;width:28px;height:28px;border-radius:6px;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;transition:background .15s}
		.clms-ta-icon-btn:hover{background:rgba(255,255,255,.35)}

		/* ── No API ──────────────────────────────────────────────────────────── */
		.clms-ta-no-api{padding:10px 14px;background:#fff8e6;border-bottom:1px solid #f0d080;font-size:12px;color:#7a5f00}
		.clms-ta-no-api a{color:#4353ff}

		/* ── Tools bar ───────────────────────────────────────────────────────── */
		.clms-ta-tools-bar{display:flex;gap:4px;padding:10px 12px;overflow-x:auto;flex-shrink:0;border-bottom:1px solid #f0f0f1;scrollbar-width:none}
		.clms-ta-tools-bar::-webkit-scrollbar{display:none}
		.clms-ta-tool-btn{flex-shrink:0;background:#f4f5ff;border:1px solid #e0e2ff;color:#4353ff;border-radius:99px;padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;transition:all .15s;white-space:nowrap}
		.clms-ta-tool-btn:hover,.clms-ta-tool-btn.active{background:#4353ff;color:#fff;border-color:#4353ff}

		/* ── Tool form ───────────────────────────────────────────────────────── */
		.clms-ta-tool-form{padding:12px;border-bottom:1px solid #f0f0f1;flex-shrink:0;background:#fafafa}
		.clms-ta-tool-form .clms-ta-field{margin-bottom:8px}
		.clms-ta-tool-form label{display:block;font-size:11px;font-weight:700;color:#444;margin-bottom:3px;text-transform:uppercase;letter-spacing:.04em}
		.clms-ta-tool-form input,.clms-ta-tool-form select{width:100%;padding:6px 10px;border:1px solid #dcdcde;border-radius:6px;font-size:13px;box-sizing:border-box}
		.clms-ta-form-row{display:grid;grid-template-columns:1fr 1fr;gap:8px}
		.clms-ta-run-tool{background:#4353ff;color:#fff;border:none;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;width:100%;margin-top:4px;transition:opacity .15s}
		.clms-ta-run-tool:hover{opacity:.88}

		/* ── Messages ────────────────────────────────────────────────────────── */
		.clms-ta-messages{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;min-height:80px;max-height:240px}
		.clms-ta-msg{display:flex}
		.clms-ta-msg--user{justify-content:flex-end}
		.clms-ta-msg--assistant{justify-content:flex-start}
		.clms-ta-msg-bubble{max-width:88%;padding:10px 14px;border-radius:14px;font-size:13px;line-height:1.5}
		.clms-ta-msg--user .clms-ta-msg-bubble{background:#4353ff;color:#fff;border-radius:14px 14px 4px 14px}
		.clms-ta-msg--assistant .clms-ta-msg-bubble{background:#f0f0f4;color:#1d2327;border-radius:14px 14px 14px 4px}

		/* ── Response ────────────────────────────────────────────────────────── */
		.clms-ta-response{flex-shrink:0;border-top:1px solid #f0f0f1;max-height:320px;overflow-y:auto}
		.clms-ta-response-text{padding:14px;font-size:13px;line-height:1.65;color:#1d2327;white-space:pre-wrap;word-break:break-word}
		.clms-ta-response-text h1,.clms-ta-response-text h2,.clms-ta-response-text h3{font-size:14px;font-weight:700;margin:12px 0 6px;color:#4353ff}
		.clms-ta-response-text h1{font-size:16px}
		.clms-ta-response-text ul,.clms-ta-response-text ol{padding-left:20px;margin:6px 0}
		.clms-ta-response-text li{margin-bottom:4px}
		.clms-ta-response-text strong{font-weight:700}
		.clms-ta-response-actions{display:flex;gap:8px;flex-wrap:wrap;padding:8px 14px 12px;border-top:1px solid #f0f0f1}
		.clms-ta-action-btn{padding:6px 14px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;transition:opacity .15s}
		.clms-ta-action-btn--primary{background:#4353ff;color:#fff}
		.clms-ta-action-btn--secondary{background:#f0f0f4;color:#1d2327}
		.clms-ta-action-btn:hover{opacity:.85}

		/* ── Input ───────────────────────────────────────────────────────────── */
		.clms-ta-input-wrap{display:flex;gap:8px;padding:10px 12px;border-top:1px solid #f0f0f1;flex-shrink:0;align-items:flex-end}
		.clms-ta-input{flex:1;resize:none;border:1px solid #dcdcde;border-radius:10px;padding:8px 12px;font-size:13px;font-family:inherit;line-height:1.5;max-height:120px;overflow-y:auto;transition:border-color .15s}
		.clms-ta-input:focus{border-color:#4353ff;outline:none;box-shadow:0 0 0 2px rgba(67,83,255,.15)}
		.clms-ta-send{flex-shrink:0;width:38px;height:38px;background:linear-gradient(135deg,#4353ff,#7b2ff7);color:#fff;border:none;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:opacity .15s}
		.clms-ta-send:hover{opacity:.88}
		.clms-ta-send:disabled{opacity:.5;cursor:not-allowed}

		/* ── Footer ──────────────────────────────────────────────────────────── */
		.clms-ta-footer{padding:4px 14px 8px;flex-shrink:0}
		.clms-ta-status{font-size:11px;color:#646970}

		/* ── Loader ──────────────────────────────────────────────────────────── */
		.clms-ta-typing{display:flex;gap:4px;padding:10px 14px;align-items:center}
		.clms-ta-typing span{width:7px;height:7px;background:#4353ff;border-radius:50%;animation:clms-ta-bounce .9s infinite}
		.clms-ta-typing span:nth-child(2){animation-delay:.15s}
		.clms-ta-typing span:nth-child(3){animation-delay:.3s}
		@keyframes clms-ta-bounce{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
		@keyframes clms-ta-spin{to{transform:rotate(360deg)}}

		/* ── Minimized ───────────────────────────────────────────────────────── */
		.clms-ta-minimized .clms-ta-body{display:none}

		@media(max-width:600px){
			.clms-ta-body{width:calc(100vw - 32px)}
			#clms-ta-panel{right:16px;bottom:16px}
		}
		</style>

		<script id="clms-ta-script">
		(function(){
			'use strict';

			var AJAX     = <?php echo wp_json_encode( $ajax_url ); ?>;
			var NONCE    = <?php echo wp_json_encode( $nonce ); ?>;
			var LESSON   = <?php echo wp_json_encode( $lesson_id ); ?>;
			var COURSE   = <?php echo wp_json_encode( $course_id ); ?>;
			var I18N     = <?php echo wp_json_encode( $i18n ); ?>;

			var panel     = document.getElementById('clms-ta-panel');
			var toggle    = document.getElementById('clms-ta-toggle');
			var body      = document.getElementById('clms-ta-body');
			var msgs      = document.getElementById('clms-ta-messages');
			var input     = document.getElementById('clms-ta-input');
			var sendBtn   = document.getElementById('clms-ta-send');
			var status    = document.getElementById('clms-ta-status');
			var toolForm  = document.getElementById('clms-ta-tool-form');
			var respArea  = document.getElementById('clms-ta-response');
			var respText  = document.getElementById('clms-ta-response-text');
			var respActs  = document.getElementById('clms-ta-response-actions');
			var clearBtn  = document.getElementById('clms-ta-clear-history');
			var minBtn    = document.getElementById('clms-ta-minimize');
			var loader    = document.getElementById('clms-ta-loader');

			var activeTool     = 'chat';
			var lastResult     = null;
			var presentWin     = null;

			// ── Toggle ──────────────────────────────────────────────────────
			toggle.addEventListener('click', function(){
				var isMin = panel.classList.contains('clms-ta-minimized');
				if(isMin){
					panel.classList.remove('clms-ta-minimized');
					body.hidden = false;
					input.focus();
				} else {
					panel.classList.add('clms-ta-minimized');
					body.hidden = true;
				}
			});

			minBtn.addEventListener('click', function(){
				panel.classList.add('clms-ta-minimized');
				body.hidden = true;
			});

			clearBtn.addEventListener('click', function(){
				if(!confirm(I18N.confirmClearHistory || '')) return;
				post({action:'clms_ta_clear_history',nonce:NONCE}, function(){
					msgs.innerHTML = '<div class="clms-ta-msg clms-ta-msg--assistant"><div class="clms-ta-msg-bubble">' + esc(I18N.historyCleared || '') + '</div></div>';
					hideResponse();
				});
			});

			// ── Tools ───────────────────────────────────────────────────────
			var TOOL_FORMS = (I18N && I18N.toolForms) ? I18N.toolForms : {};

			document.querySelectorAll('.clms-ta-tool-btn').forEach(function(btn){
				btn.addEventListener('click', function(){
					var tool = btn.getAttribute('data-tool');
					activateTool(tool);
				});
			});

			function activateTool(tool){
				activeTool = tool;
				document.querySelectorAll('.clms-ta-tool-btn').forEach(function(b){
					b.classList.toggle('active', b.getAttribute('data-tool') === tool);
				});

				var def = TOOL_FORMS[tool];
				if(!def || def.fields.length === 0){
					toolForm.hidden = true;
					toolForm.innerHTML = '';
					if(def && def.fields.length === 0){
						// Auto-ejecutar herramientas sin parámetros
						if(tool === 'analyze_group'){
							runTool(tool, {}, '');
						} else if(tool === 'improve_lesson'){
							runTool(tool, {}, '');
						}
					}
					return;
				}

				var html = '<div style="font-size:12px;font-weight:700;color:#4353ff;margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em">' + esc(def.label) + '</div>';

				var rows = '';
				def.fields.forEach(function(f){
					var inp = '';
					if(f.type === 'select'){
						inp = '<select name="'+esc(f.key)+'">';
						f.options.forEach(function(o){ inp += '<option>'+esc(o)+'</option>'; });
						inp += '</select>';
					} else {
						inp = '<input type="'+esc(f.type)+'" name="'+esc(f.key)+'" placeholder="'+esc(f.placeholder||'')+'">';
					}
					rows += '<div class="clms-ta-field"><label>'+esc(f.label)+'</label>'+inp+'</div>';
				});

				html += rows;
				html += '<button type="button" class="clms-ta-run-tool" id="clms-ta-run-btn">' + esc(I18N.generate || '') + '</button>';

				toolForm.innerHTML = html;
				toolForm.hidden = false;

				document.getElementById('clms-ta-run-btn').addEventListener('click', function(){
					var params = {};
					toolForm.querySelectorAll('input,select').forEach(function(el){
						if(el.name && el.value.trim()) params[el.name] = el.value.trim();
					});
					runTool(activeTool, params, input.value.trim());
				});
			}

			// ── Send (chat libre) ───────────────────────────────────────────
			sendBtn.addEventListener('click', sendMessage);
			input.addEventListener('keydown', function(e){
				if(e.key === 'Enter' && !e.shiftKey){
					e.preventDefault();
					sendMessage();
				}
			});

			function sendMessage(){
				var msg = input.value.trim();
				if(!msg) return;
				input.value = '';
				runTool(activeTool === 'chat' ? 'chat' : activeTool, {}, msg);
			}

			// ── Core runner ─────────────────────────────────────────────────
			function runTool(tool, params, message){
				if(!message && Object.keys(params).length === 0 && tool !== 'analyze_group') return;

				addMessage('user', message || describeTool(tool));
				showTyping();
				setStatus(I18N.generating || '', false);
				setInputLocked(true);
				hideResponse();

				var data = {
					action:    'clms_ta_run',
					nonce:     NONCE,
					tool:      tool,
					message:   message,
					lesson_id: LESSON,
					course_id: COURSE,
				};

				Object.keys(params).forEach(function(k){
					data['params['+k+']'] = params[k];
				});

				post(data, function(json){
					removeTyping();
					setInputLocked(false);
					setStatus('');

					if(!json.success || !json.data){
						var errMsg = (json.data && json.data.message) ? json.data.message : (I18N.errorGenerate || '');
						setStatus(errMsg, true);
						addMessage('assistant', '⚠️ ' + errMsg);
						return;
					}

					lastResult = json.data;
					showResponse(json.data);
				}, function(){
					removeTyping();
					setInputLocked(false);
					setStatus(I18N.errorConnection || '', true);
					addMessage('assistant', '⚠️ ' + (I18N.errorConnection || ''));
				});
			}

			// ── Response rendering ──────────────────────────────────────────
			function showResponse(data){
				var text = data.text || '';

				// Para presentaciones, mostrar info especial
				if(data.tool === 'presentation' && data.slides_count){
					var summary = '✅ ' + formatText(I18N.presentationSummary || '', data.slides_count);
					addMessage('assistant', summary);
				} else {
					addMessage('assistant', text.substring(0, 300) + (text.length > 300 ? '…' : ''));
				}

				// Mostrar el texto completo en el área de response
				respText.innerHTML = formatMarkdown(text);
				respArea.hidden = false;

				// Renderizar botones de acción
				respActs.innerHTML = '';
				var actions = data.actions || [];

				actions.forEach(function(action){
					var btn = document.createElement('button');
					btn.className = 'clms-ta-action-btn clms-ta-action-btn--' + (action.type === 'copy' ? 'secondary' : 'primary');
					btn.textContent = action.label;

					btn.addEventListener('click', function(){
						handleAction(action, data);
					});

					respActs.appendChild(btn);
				});

				// Scroll to bottom
				msgs.scrollTop = msgs.scrollHeight;
				respArea.scrollTop = 0;
			}

			function handleAction(action, data){
				if(action.type === 'copy'){
					copyToClipboard(data.text || '');
					return;
				}

				if(action.type === 'preview_presentation'){
					var html = data.presentation_html || '';
					if(!html) return;
					presentWin = window.open('', '_blank', 'width=1024,height=768');
					presentWin.document.write(html);
					presentWin.document.close();
					return;
				}

				if(action.type === 'print_presentation'){
					var html2 = data.presentation_html || '';
					if(!html2) return;
					var w = window.open('', '_blank');
					w.document.write(html2);
					w.document.close();
					w.print();
					return;
				}

				if(action.type === 'save'){
					saveArtifact(action.artifact, data);
					return;
				}
			}

			function saveArtifact(type, data){
				setStatus(I18N.saving || '', false);

				var payload = {
					action:    'clms_ta_save_artifact',
					nonce:     NONCE,
					type:      type,
					lesson_id: LESSON,
					course_id: COURSE,
					content:   data.presentation_html || data.text || '',
					raw:       data.text || '',
				};

				post(payload, function(json){
					setStatus('');
					if(!json.success || !json.data){
						var errMsg = (json.data && json.data.message) ? json.data.message : (I18N.saveError || '');
						setStatus(errMsg, true);
						addMessage('assistant', '⚠️ ' + errMsg);
						return;
					}
					var res = json.data;
					var msg = '✅ ' + (res.message || (I18N.savedOk || ''));
					if(res.edit_url){
						msg += ' <a href="'+res.edit_url+'" target="_blank" style="color:#4353ff">'+esc(I18N.viewLink || '')+'</a>';
					}
					addMessage('assistant', msg, true);
				}, function(){
					setStatus(I18N.saveConnectionError || '', true);
					addMessage('assistant', '⚠️ ' + (I18N.saveConnectionError || ''));
				});
			}

			// ── Helpers de UI ───────────────────────────────────────────────
			function addMessage(role, text, isHtml){
				var div  = document.createElement('div');
				var cls  = 'assistant' === role ? 'clms-ta-msg--assistant' : 'clms-ta-msg--user';
				div.className = 'clms-ta-msg ' + cls;

				var bubble = document.createElement('div');
				bubble.className = 'clms-ta-msg-bubble';

				if(isHtml){
					bubble.innerHTML = text;
				} else {
					bubble.textContent = text;
				}

				div.appendChild(bubble);
				msgs.appendChild(div);
				msgs.scrollTop = msgs.scrollHeight;
			}

			function showTyping(){
				var div = document.createElement('div');
				div.className = 'clms-ta-msg clms-ta-msg--assistant clms-ta-typing-wrap';
				div.innerHTML = '<div class="clms-ta-typing"><span></span><span></span><span></span></div>';
				msgs.appendChild(div);
				msgs.scrollTop = msgs.scrollHeight;
			}

			function removeTyping(){
				var t = msgs.querySelector('.clms-ta-typing-wrap');
				if(t) t.parentNode.removeChild(t);
			}

			function hideResponse(){
				respArea.hidden = true;
				respText.innerHTML = '';
				respActs.innerHTML = '';
			}

			function setStatus(txt, isError){
				if (window.ATORA && window.ATORA.ui && window.ATORA.ui.showStatus) {
					window.ATORA.ui.showStatus(status, txt, !!isError);
				} else {
					status.textContent = txt || '';
				}
			}

			function setInputLocked(locked){
				input.disabled = locked;
				sendBtn.disabled = locked;
			}

			function setLoading(isLoading){
				if (loader) {
					loader.hidden = !isLoading;
				}
				panel.classList.toggle('clms-ta-loading', !!isLoading);
				panel.setAttribute('aria-busy', isLoading ? 'true' : 'false');
				if (window.ATORA && window.ATORA.ui && window.ATORA.ui.setLoading) {
					window.ATORA.ui.setLoading(panel, !!isLoading);
				}
			}

			function formatMarkdown(text){
				// Escape primero
				var safe = text
					.replace(/&/g,'&amp;')
					.replace(/</g,'&lt;')
					.replace(/>/g,'&gt;');

				// Headers
				safe = safe.replace(/^### (.+)$/gm, '<h3 style="font-size:13px;font-weight:700;color:#4353ff;margin:12px 0 4px">$1</h3>');
				safe = safe.replace(/^## (.+)$/gm,  '<h2 style="font-size:14px;font-weight:800;color:#4353ff;margin:14px 0 6px">$1</h2>');
				safe = safe.replace(/^# (.+)$/gm,   '<h1 style="font-size:15px;font-weight:800;color:#4353ff;margin:16px 0 8px">$1</h1>');
				// Bold
				safe = safe.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
				// Italic
				safe = safe.replace(/\*(.+?)\*/g, '<em>$1</em>');
				// Bullets
				safe = safe.replace(/^[\-\*] (.+)$/gm, '<li>$1</li>');
				// Numbered list
				safe = safe.replace(/^\d+\. (.+)$/gm, '<li>$1</li>');
				// Wrap lists
				safe = safe.replace(/(<li>[\s\S]*?<\/li>)/g, '<ul style="padding-left:18px;margin:6px 0">$1</ul>');
				// Code
				safe = safe.replace(/`([^`]+)`/g, '<code style="background:#f0f0f4;padding:2px 5px;border-radius:4px;font-size:12px">$1</code>');
				// Line breaks → párrafos
				safe = safe.replace(/\n\n+/g, '</p><p style="margin:8px 0">');
				safe = '<p style="margin:0">' + safe + '</p>';
				safe = safe.replace(/\n/g, '<br>');

				return safe;
			}

			function copyToClipboard(text){
				if(navigator.clipboard && navigator.clipboard.writeText){
					navigator.clipboard.writeText(text).then(function(){
						setStatus('✅ ' + (I18N.copiedClipboard || ''));
						setTimeout(function(){ setStatus(''); }, 2000);
					});
				} else {
					var ta = document.createElement('textarea');
					ta.value = text;
					ta.style.position = 'fixed';
					ta.style.opacity = '0';
					document.body.appendChild(ta);
					ta.select();
					document.execCommand('copy');
					document.body.removeChild(ta);
					setStatus('✅ ' + (I18N.copied || ''));
					setTimeout(function(){ setStatus(''); }, 2000);
				}
			}

			function describeTool(tool){
				if (I18N && I18N.toolLabels && I18N.toolLabels[tool]) {
					return I18N.toolLabels[tool];
				}
				return tool;
			}

			function formatText(str){
				var args = Array.prototype.slice.call(arguments, 1);
				return String(str).replace(/%s/g, function(){
					return args.length ? args.shift() : '';
				});
			}

			function esc(s){ return String(s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

			// ── Fetch helper ────────────────────────────────────────────────
			function post(data, onSuccess, onError){
				var body = new URLSearchParams();
				Object.keys(data).forEach(function(k){ body.append(k, data[k]); });

				setLoading(true);
				fetch(AJAX, {
					method: 'POST',
					headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
					credentials: 'same-origin',
					body: body.toString()
				})
				.then(function(r){ return r.json(); })
				.then(function(json){
					setLoading(false);
					onSuccess && onSuccess(json);
				})
				.catch(function(e){
					setLoading(false);
					onError && onError(e);
				});
			}

		})();
		</script>
		<?php
	}

	protected function has_any_api_key() {
		if ( class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'has_any_generation_key' ) ) {
			return (bool) CLMS_AI_Settings_Service::has_any_generation_key();
		}

		$options = get_option( 'clms_ai_settings', array() );
		return (
			! empty( $options['openai_api_key'] ) ||
			! empty( $options['anthropic_api_key'] ) ||
			! empty( $options['gemini_api_key'] )
		);
	}
}
