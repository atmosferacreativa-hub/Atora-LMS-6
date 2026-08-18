<?php
/**
 * Vista: Campaign Builder CRM v2 — Fase 3
 *
 * Reemplaza la vista anterior (form POST con recarga).
 * Todo el builder opera via REST + JS — esta vista es solo el HTML esqueleto.
 * Los datos se cargan desde /campaigns/meta y /campaigns al montar.
 *
 * Variables esperadas: ninguna obligatoria (el JS carga todo).
 *
 * @package ATORA_LMS\CRM_V2
 * @since   5.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require __DIR__ . '/partials/header.php';
?>

<div id="crm-campaign-app" class="crm-cb-app">

	<?php /* Toast global */ ?>
	<div id="crm-cb-toast" class="crm-toast" aria-live="polite" aria-atomic="true" hidden></div>

	<?php /* ═══════════════════ LAYOUT ═══════════════════ */ ?>
	<div class="crm-cb-layout">

		<?php /* ── COLUMNA IZQUIERDA: Builder multi-paso ── */ ?>
		<div class="crm-cb-builder">

			<?php /* Stepper visual */ ?>
			<nav class="crm-cb-steps" aria-label="Pasos del builder">
				<button class="crm-cb-step is-active" data-step="1">
					<span class="crm-cb-step__num">1</span>
					<span class="crm-cb-step__label"><?php esc_html_e( 'Audiencia', 'atora-lms' ); ?></span>
				</button>
				<span class="crm-cb-step__connector"></span>
				<button class="crm-cb-step" data-step="2">
					<span class="crm-cb-step__num">2</span>
					<span class="crm-cb-step__label"><?php esc_html_e( 'Contenido', 'atora-lms' ); ?></span>
				</button>
				<span class="crm-cb-step__connector"></span>
				<button class="crm-cb-step" data-step="3">
					<span class="crm-cb-step__num">3</span>
					<span class="crm-cb-step__label"><?php esc_html_e( 'Envío', 'atora-lms' ); ?></span>
				</button>
				<span class="crm-cb-step__connector"></span>
				<button class="crm-cb-step" data-step="4">
					<span class="crm-cb-step__num">4</span>
					<span class="crm-cb-step__label"><?php esc_html_e( 'Revisar', 'atora-lms' ); ?></span>
				</button>
			</nav>

			<?php /* ──── Paso 1: Audiencia ──── */ ?>
			<section class="crm-cb-panel" data-panel="1">
				<h3 class="crm-cb-panel__title"><?php esc_html_e( 'Audiencia y segmento', 'atora-lms' ); ?></h3>
				<p class="crm-cb-panel__desc">
					<?php esc_html_e( 'Define quién recibirá esta campaña. El contador de audiencia se actualiza en tiempo real.', 'atora-lms' ); ?>
				</p>
				<div class="crm-cb-grid-2">
					<label class="crm-cb-label">
						<?php esc_html_e( 'Nombre interno', 'atora-lms' ); ?>
						<input id="cb-name" type="text" class="crm-cb-input" placeholder="<?php esc_attr_e( 'Ej: Reactivación mayo 2026', 'atora-lms' ); ?>">
					</label>
					<label class="crm-cb-label">
						<?php esc_html_e( 'Estado de contacto', 'atora-lms' ); ?>
						<select id="cb-status-filter" class="crm-cb-input"></select>
					</label>
					<label class="crm-cb-label">
						<?php esc_html_e( 'Etiqueta (tag)', 'atora-lms' ); ?>
						<input id="cb-tag" type="text" class="crm-cb-input" placeholder="#inactive-30d">
					</label>
					<label class="crm-cb-label">
						<?php esc_html_e( 'Buscar contacto', 'atora-lms' ); ?>
						<input id="cb-search" type="text" class="crm-cb-input" placeholder="<?php esc_attr_e( 'Nombre, email...', 'atora-lms' ); ?>">
					</label>
				</div>

				<?php /* Audiencia estimada — se actualiza en tiempo real */ ?>
				<div class="crm-cb-audience-bar" id="cb-audience-bar">
					<span class="crm-cb-audience-label"><?php esc_html_e( 'Audiencia estimada', 'atora-lms' ); ?></span>
					<span class="crm-cb-audience-count">
						<strong id="cb-audience-total">—</strong>
						<small id="cb-audience-email"><?php esc_html_e( 'contactos · estimado email', 'atora-lms' ); ?></small>
					</span>
					<span class="crm-cb-audience-spinner" id="cb-audience-spinner" hidden>⟳</span>
				</div>
			</section>

			<?php /* ──── Paso 2: Contenido ──── */ ?>
			<section class="crm-cb-panel" data-panel="2" hidden>
				<h3 class="crm-cb-panel__title"><?php esc_html_e( 'Contenido del mensaje', 'atora-lms' ); ?></h3>
				<p class="crm-cb-panel__desc">
					<?php esc_html_e( 'Construye el email con bloques, variables dinámicas y previsualización en tiempo real.', 'atora-lms' ); ?>
				</p>

				<?php /* Selector de plantilla */ ?>
				<div class="crm-cb-template-grid" id="cb-template-grid">
					<?php /* Rellenado por JS */ ?>
				</div>

				<label class="crm-cb-label" style="margin-top:16px">
					<?php esc_html_e( 'Asunto', 'atora-lms' ); ?>
					<input id="cb-subject" type="text" class="crm-cb-input" placeholder="<?php esc_attr_e( 'Línea de asunto del email', 'atora-lms' ); ?>">
				</label>

				<input id="cb-message" type="hidden" value="">
				<input id="cb-blocks-json" type="hidden" value="">

				<div class="atora-email-builder" style="margin-top:12px;">
					<aside class="atora-email-builder__sidebar">
						<h4 class="crm-cb-panel__title" style="font-size:15px;"><?php esc_html_e( 'Bloques', 'atora-lms' ); ?></h4>
						<div id="cb-block-library"></div>

						<h4 class="crm-cb-panel__title" style="font-size:15px;margin-top:16px;"><?php esc_html_e( 'Variables', 'atora-lms' ); ?></h4>
						<div id="cb-var-chips"></div>
					</aside>

					<div class="atora-email-builder__center">
						<div class="crm-cb-builder-stage">
							<div class="crm-cb-builder-stage__header">
								<strong><?php esc_html_e( 'Canvas del email', 'atora-lms' ); ?></strong>
								<button type="button" class="button button-small" id="cb-add-text-block"><?php esc_html_e( 'Agregar párrafo', 'atora-lms' ); ?></button>
							</div>
							<div class="atora-email-canvas" id="cb-email-canvas"></div>
						</div>

						<div class="crm-cb-block-editor" id="cb-block-editor">
							<p class="crm-cb-empty"><?php esc_html_e( 'Selecciona un bloque para editar su contenido.', 'atora-lms' ); ?></p>
						</div>
					</div>

					<aside class="atora-email-builder__preview">
						<div class="crm-cb-preview-head">
							<span><?php esc_html_e( 'Preview', 'atora-lms' ); ?></span>
						</div>
						<div class="atora-email-preview-frame">
							<div class="atora-email-preview-header">
								<div id="cb-preview-subject"></div>
							</div>
							<div class="atora-email-preview-body" id="cb-preview-message"></div>
						</div>
					</aside>
				</div>

				<div class="crm-cb-char-count">
					<span><?php esc_html_e( 'Caracteres:', 'atora-lms' ); ?> <strong id="cb-char-count">0</strong></span>
					<span id="cb-char-warn" class="crm-cb-warn" hidden><?php esc_html_e( 'Texto muy largo para email.', 'atora-lms' ); ?></span>
				</div>

				<label class="crm-cb-label" style="margin-top:10px">
					<?php esc_html_e( 'URL de llamada a la acción (opcional)', 'atora-lms' ); ?>
					<input id="cb-cta-url" type="url" class="crm-cb-input" placeholder="https://...">
				</label>

				<?php /* Email de prueba */ ?>
				<div class="crm-cb-test-row" id="cb-test-row">
					<input id="cb-test-email" type="email" class="crm-cb-input" placeholder="tu@email.com">
					<button type="button" class="button" id="cb-test-send">
						<?php esc_html_e( 'Enviar prueba', 'atora-lms' ); ?>
					</button>
				</div>
			</section>

			<?php /* ──── Paso 3: Envío ──── */ ?>
			<section class="crm-cb-panel" data-panel="3" hidden>
				<h3 class="crm-cb-panel__title"><?php esc_html_e( 'Configuración de envío', 'atora-lms' ); ?></h3>
				<div class="crm-cb-grid-2">
					<label class="crm-cb-label">
						<?php esc_html_e( 'Canal', 'atora-lms' ); ?>
						<select id="cb-channel" class="crm-cb-input"></select>
					</label>
					<label class="crm-cb-label">
						<?php esc_html_e( 'Identidad de envío', 'atora-lms' ); ?>
						<select id="cb-identity" class="crm-cb-input"></select>
					</label>
					<label class="crm-cb-label">
						<?php esc_html_e( 'Modo de ejecución', 'atora-lms' ); ?>
						<select id="cb-execution-mode" class="crm-cb-input">
							<option value="simulate"><?php esc_html_e( 'Simular — sin envíos reales', 'atora-lms' ); ?></option>
							<option value="queue"><?php esc_html_e( 'Encolar — envío real', 'atora-lms' ); ?></option>
						</select>
					</label>
					<label class="crm-cb-label">
						<?php esc_html_e( 'Fecha programada (opcional)', 'atora-lms' ); ?>
						<input id="cb-scheduled-at" type="datetime-local" class="crm-cb-input">
					</label>
				</div>
				<p class="crm-cb-hint">
					<?php esc_html_e( 'Simular no envía ningún email. Úsalo para verificar destinatarios antes del envío real.', 'atora-lms' ); ?>
				</p>

				<?php /* ── Editor visual email (Fase II S7) ── */ ?>
				<details class="crm-cb-email-editor-wrap" style="margin-top:14px;padding:12px;background:#f8fafc;border:.5px solid #e2e8f0;border-radius:10px">
					<summary style="font-size:13px;font-weight:600;cursor:pointer"><?php esc_html_e( '✏️ Editor visual de email (opcional)', 'atora-lms' ); ?></summary>
					<div style="margin-top:12px">
						<p style="font-size:12px;color:#64748b;margin:0 0 8px"><?php esc_html_e( 'Diseña el cuerpo del email con bloques arrastrables. El HTML resultante reemplaza el cuerpo del template.', 'atora-lms' ); ?></p>
						<div id="atora-email-editor"></div>
						<input type="hidden" id="cb-email-html" name="email_html">
					</div>
				</details>
			</section>

			<?php /* ──── Paso 4: Revisar y Lanzar ──── */ ?>
			<section class="crm-cb-panel" data-panel="4" hidden>
				<h3 class="crm-cb-panel__title"><?php esc_html_e( 'Resumen y lanzamiento', 'atora-lms' ); ?></h3>
				<div class="crm-cb-summary" id="cb-summary">
					<?php /* Rellenado por JS */ ?>
				</div>

				<?php /* Barra de resultado post-lanzamiento */ ?>
				<div class="crm-cb-result" id="cb-result" hidden>
					<div class="crm-cb-result__kpis" id="cb-result-kpis"></div>
					<p class="crm-cb-result__msg" id="cb-result-msg"></p>
				</div>
			</section>

			<?php /* ──── Navegación del stepper ──── */ ?>
			<div class="crm-cb-nav">
				<button type="button" class="button" id="cb-btn-prev" disabled>
					&#8592; <?php esc_html_e( 'Anterior', 'atora-lms' ); ?>
				</button>
				<div class="crm-cb-nav__center">
					<button type="button" class="button" id="cb-btn-save">
						<?php esc_html_e( 'Guardar borrador', 'atora-lms' ); ?>
					</button>
				</div>
				<button type="button" class="button button-primary" id="cb-btn-next">
					<?php esc_html_e( 'Siguiente', 'atora-lms' ); ?> &#8594;
				</button>
				<button type="button" class="button button-primary crm-cb-launch-btn" id="cb-btn-launch" hidden>
					<?php esc_html_e( '🚀 Lanzar campaña', 'atora-lms' ); ?>
				</button>
			</div>
		</div>

		<?php /* ── COLUMNA DERECHA: Historial ── */ ?>
		<aside class="crm-cb-history">
			<div class="crm-cb-history__head">
				<h3><?php esc_html_e( 'Campañas', 'atora-lms' ); ?></h3>
				<button type="button" class="button crm-cb-new-btn" id="cb-btn-new">
					+ <?php esc_html_e( 'Nueva', 'atora-lms' ); ?>
				</button>
			</div>

			<?php /* Filtro de estado */ ?>
			<div class="crm-cb-history__filters">
				<button class="crm-cb-filter-btn is-active" data-status="">
					<?php esc_html_e( 'Todas', 'atora-lms' ); ?>
				</button>
				<button class="crm-cb-filter-btn" data-status="draft">
					<?php esc_html_e( 'Borrador', 'atora-lms' ); ?>
				</button>
				<button class="crm-cb-filter-btn" data-status="scheduled">
					<?php esc_html_e( 'Programadas', 'atora-lms' ); ?>
				</button>
				<button class="crm-cb-filter-btn" data-status="sent,queued">
					<?php esc_html_e( 'Enviadas', 'atora-lms' ); ?>
				</button>
			</div>

			<?php /* Lista de campañas — rellenada por JS */ ?>
			<div class="crm-cb-list" id="cb-campaign-list">
				<p class="crm-cb-empty"><?php esc_html_e( 'Cargando campañas…', 'atora-lms' ); ?></p>
			</div>
		</aside>
	</div>
</div>


				<?php /* Toggle A/B (Fase 8) */ ?>
				<div class="crm-cb-ab-section" style="margin-top:14px;padding:12px;background:#f8fafc;border:.5px solid #e2e8f0;border-radius:10px">
					<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:600">
						<input type="checkbox" id="cb-ab-toggle" style="width:16px;height:16px">
						<?php esc_html_e( 'Activar test A/B', 'atora-lms' ); ?>
					</label>
					<p style="font-size:12px;color:#64748b;margin:4px 0 0"><?php esc_html_e( 'Prueba dos asuntos o mensajes. La variante ganadora se envía al resto.', 'atora-lms' ); ?></p>
					<div id="cb-ab-fields" hidden style="margin-top:10px;display:grid;gap:8px">
						<label class="crm-cb-label">
							<?php esc_html_e( 'Asunto variante B', 'atora-lms' ); ?>
							<input id="cb-ab-subject-b" type="text" class="crm-cb-input"
								placeholder="<?php esc_attr_e( 'Asunto alternativo para probar', 'atora-lms' ); ?>">
						</label>
						<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
							<label class="crm-cb-label">
								<?php esc_html_e( 'Split % (variante A)', 'atora-lms' ); ?>
								<input id="cb-ab-split" type="number" class="crm-cb-input" min="10" max="90" value="50">
							</label>
							<label class="crm-cb-label">
								<?php esc_html_e( 'Ganador tras (horas)', 'atora-lms' ); ?>
								<input id="cb-ab-winner-hours" type="number" class="crm-cb-input" min="1" max="72" value="24">
							</label>
						</div>
					</div>
				</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
