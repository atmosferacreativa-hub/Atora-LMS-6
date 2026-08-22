<?php

/**
 * CLMS_Grading — Calificaciones, resumen académico y SpeedGrade.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Grading_AI_Review_Trait {
	protected function render_ai_review_panel( $context ) {
		$submission_id = ! empty( $context['submission_id'] ) ? absint( $context['submission_id'] ) : 0;

		if ( ! $submission_id ) {
			return '';
		}

		$submission = clms_core('CLMS_Submission');
		if ( ! $submission || ! method_exists( $submission, 'get_ai_review_data' ) ) {
			return '';
		}

		$ai_data = $submission->get_ai_review_data( $submission_id );
		$ai_data = $this->normalize_ai_panel_data( $ai_data );
		$nonce   = wp_create_nonce( 'clms_ai_generate_' . $submission_id );
		$regen_label  = __( 'Regenerar análisis IA', 'atora-lms' );
		$default_label = __( 'Generar análisis IA', 'atora-lms' );
		$loading_label = __( 'Generando...', 'atora-lms' );
		$ai_i18n = array(
			'defaultLabel'      => $default_label,
			'regenLabel'        => $regen_label,
			'loadingLabel'      => $loading_label,
			'feedbackGenerating'=> __( 'Generando revisión IA...', 'atora-lms' ),
			'errorGenerate'     => __( 'No se pudo generar la revisión IA.', 'atora-lms' ),
			'errorGeneric'      => __( 'Ocurrió un error al generar la revisión IA.', 'atora-lms' ),
		);

		ob_start();
		?>
		<section class="clms-sg-card" id="clms-sg-ai-panel" data-submission-id="<?php echo esc_attr( $submission_id ); ?>">
			<div class="clms-sg-section-head">
				<div>
					<div class="clms-sg-eyebrow"><?php esc_html_e( 'Asistente IA', 'atora-lms' ); ?></div>
					<h2 class="clms-sg-subtitle"><?php esc_html_e( 'Pre-revisión automática', 'atora-lms' ); ?></h2>
				</div>
			</div>

			<div class="clms-sg-ai-feedback" id="clms-sg-ai-feedback" style="display:none;"></div>

			<div class="clms-sg-ai-status">
				<strong><?php esc_html_e( 'Estado:', 'atora-lms' ); ?></strong>
				<span id="clms-sg-ai-status-label"><?php echo esc_html( $this->get_ai_status_label( $ai_data['status'] ) ); ?></span>
			</div>

			<?php if ( ! empty( $ai_data['error'] ) ) : ?>
				<div class="clms-sg-flash is-error"><?php echo esc_html( $ai_data['error'] ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $ai_data['updated_at'] ) ) : ?>
				<p class="clms-sg-copy"><strong><?php esc_html_e( 'Última generación:', 'atora-lms' ); ?></strong> <?php echo esc_html( $this->format_datetime( $ai_data['updated_at'] ) ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== (string) $ai_data['confidence'] || ! empty( $ai_data['model'] ) ) : ?>
				<p class="clms-sg-copy">
					<?php if ( '' !== (string) $ai_data['confidence'] ) : ?>
						<strong><?php esc_html_e( 'Confianza:', 'atora-lms' ); ?></strong> <?php echo esc_html( round( (float) $ai_data['confidence'] * 100 ) ); ?>%
					<?php endif; ?>
					<?php if ( ! empty( $ai_data['model'] ) ) : ?>
						<?php if ( '' !== (string) $ai_data['confidence'] ) : ?> · <?php endif; ?>
						<strong><?php esc_html_e( 'Modelo:', 'atora-lms' ); ?></strong> <?php echo esc_html( $ai_data['model'] ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $ai_data['summary'] ) ) : ?>
				<div class="clms-sg-ai-block">
					<h3><?php esc_html_e( 'Resumen detectado', 'atora-lms' ); ?></h3>
					<div class="clms-sg-copy"><?php echo wp_kses_post( wpautop( $ai_data['summary'] ) ); ?></div>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $ai_data['highlights'] ) && is_array( $ai_data['highlights'] ) ) : ?>
				<div class="clms-sg-ai-block">
					<h3><?php esc_html_e( 'Observaciones IA', 'atora-lms' ); ?></h3>
					<ul class="clms-sg-ai-list">
						<?php foreach ( $ai_data['highlights'] as $item ) : ?>
							<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( '' !== (string) $ai_data['score_suggestion'] ) : ?>
				<div class="clms-sg-ai-block">
					<h3><?php esc_html_e( 'Sugerencia de nota', 'atora-lms' ); ?></h3>
					<p class="clms-sg-copy"><strong><?php echo esc_html( absint( $ai_data['score_suggestion'] ) ); ?>/100</strong></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $ai_data['criteria_scores'] ) ) : ?>
				<div class="clms-sg-ai-block">
					<h3><?php esc_html_e( 'Puntuación por criterio', 'atora-lms' ); ?></h3>
					<style>
					.clms-sg-ai-rubric{width:100%;border-collapse:collapse;font-size:12px;margin-top:6px}
					.clms-sg-ai-rubric th{padding:6px 8px;background:rgba(255,255,255,.08);border-bottom:1px solid rgba(255,255,255,.15);text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.04em}
					.clms-sg-ai-rubric td{padding:6px 8px;border-bottom:1px solid rgba(255,255,255,.06);vertical-align:top}
					.clms-sg-ai-rubric td.pts{text-align:center;white-space:nowrap;font-weight:700}
					.clms-sg-ai-rubric .rationale{font-size:11px;opacity:.7;margin:2px 0 0}
					.clms-sg-ai-rubric-apply{margin-top:8px}
					</style>
					<table class="clms-sg-ai-rubric">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Criterio', 'atora-lms' ); ?></th>
								<th style="width:72px;text-align:center"><?php esc_html_e( 'Pts IA', 'atora-lms' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $ai_data['criteria_scores'] as $cs ) :
								$cs_name = isset( $cs['criterion'] ) ? $cs['criterion'] : '';
								$cs_score= isset( $cs['score'] ) && '' !== (string) $cs['score'] ? absint( $cs['score'] ) : '—';
								$cs_max  = isset( $cs['max_points'] ) ? absint( $cs['max_points'] ) : 0;
								$cs_rat  = isset( $cs['rationale'] ) ? $cs['rationale'] : '';
							?>
							<tr>
								<td>
									<?php echo esc_html( $cs_name ); ?>
									<?php if ( $cs_rat ) : ?>
										<p class="rationale"><?php echo esc_html( $cs_rat ); ?></p>
									<?php endif; ?>
								</td>
								<td class="pts"><?php echo esc_html( $cs_score ); ?><?php if ( $cs_max ) : ?><span style="opacity:.5">/<?php echo esc_html( $cs_max ); ?></span><?php endif; ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<div class="clms-sg-actions clms-sg-ai-rubric-apply">
						<button type="button" class="clms-sg-btn clms-sg-btn--secondary" onclick="clmsApplyAiRubricScores();">
							<?php esc_html_e( 'Aplicar puntuaciones IA a rúbrica', 'atora-lms' ); ?>
						</button>
					</div>
					<script>
					function clmsApplyAiRubricScores(){
						var scores = <?php echo wp_json_encode( array_values( $ai_data['criteria_scores'] ) ); ?>;
						var rubricInputs = document.querySelectorAll('.clms-sg-rubric-score');
						scores.forEach(function(s, i){
							if(rubricInputs[i] !== undefined){
								var max = parseInt(rubricInputs[i].getAttribute('data-max'),10)||0;
								var v   = Math.min(max, Math.max(0, parseInt(s.score,10)||0));
								rubricInputs[i].value = v;
								rubricInputs[i].dispatchEvent(new Event('input'));
							}
						});
					}
					</script>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $ai_data['feedback_draft'] ) ) : ?>
				<div class="clms-sg-ai-block">
					<h3><?php esc_html_e( 'Borrador de retroalimentación', 'atora-lms' ); ?></h3>
					<textarea readonly rows="8" class="clms-sg-ai-draft" id="clms_sg_ai_feedback_draft"><?php echo esc_textarea( wp_strip_all_tags( $ai_data['feedback_draft'] ) ); ?></textarea>
					<div class="clms-sg-actions">
						<button type="button" class="clms-sg-btn clms-sg-btn--secondary" onclick="clmsUseAiDraft();">
							<?php esc_html_e( 'Usar borrador IA', 'atora-lms' ); ?>
						</button>
					</div>
				</div>
			<?php endif; ?>

			<div class="clms-sg-actions">
				<button
					type="button"
					class="clms-sg-btn clms-sg-btn--primary"
					id="clms-sg-ai-generate-btn"
					data-action="clms_generate_ai_review"
					data-submission-id="<?php echo esc_attr( $submission_id ); ?>"
					data-nonce="<?php echo esc_attr( $nonce ); ?>"
					data-label-default="<?php echo esc_attr( 'completed' === $ai_data['status'] ? $regen_label : $default_label ); ?>"
					data-label-loading="<?php echo esc_attr( $loading_label ); ?>"
				>
					<span class="clms-sg-btn-spinner" data-atora-loader hidden></span>
					<span class="clms-sg-btn-label"><?php echo esc_html( 'completed' === $ai_data['status'] ? $regen_label : $default_label ); ?></span>
				</button>
			</div>

			<p class="clms-sg-copy"><em><?php esc_html_e( 'La revisión final depende del evaluador.', 'atora-lms' ); ?></em></p>
		</section>

		<script>
		var clmsSgAiI18n = <?php echo wp_json_encode( $ai_i18n ); ?>;

		function clmsUseAiDraft() {
			var source = document.getElementById('clms_sg_ai_feedback_draft');
			var target = document.getElementById('clms_sg_feedback');
			if (!source || !target) return;
			target.value = source.value;
			target.focus();
		}

		(function() {
			var btn = document.getElementById('clms-sg-ai-generate-btn');
			if (!btn || btn.dataset.bound === '1') return;

			btn.dataset.bound = '1';

			btn.addEventListener('click', function() {
				var submissionId = btn.getAttribute('data-submission-id');
				var nonce = btn.getAttribute('data-nonce');
				var action = btn.getAttribute('data-action');
				var defaultLabel = btn.getAttribute('data-label-default') || (clmsSgAiI18n ? clmsSgAiI18n.defaultLabel : '');
				var loadingLabel = btn.getAttribute('data-label-loading') || (clmsSgAiI18n ? clmsSgAiI18n.loadingLabel : '');
				var feedbackBox = document.getElementById('clms-sg-ai-feedback');
				var panel = document.getElementById('clms-sg-ai-panel');

				if (!submissionId || !nonce || !action || !panel) return;

				btn.disabled = true;
				var labelEl = btn.querySelector('.clms-sg-btn-label');
				if (labelEl) {
					labelEl.textContent = loadingLabel;
				} else {
					btn.textContent = loadingLabel;
				}
				if (window.ATORA && window.ATORA.ui && window.ATORA.ui.setLoading) {
					window.ATORA.ui.setLoading(btn, true);
				}

				if (feedbackBox) {
					feedbackBox.style.display = 'block';
					feedbackBox.className = 'clms-sg-flash';
					feedbackBox.textContent = clmsSgAiI18n ? clmsSgAiI18n.feedbackGenerating : '';
				}

				var body = new URLSearchParams();
				body.append('action', action);
				body.append('submission_id', submissionId);
				body.append('nonce', nonce);

				fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
					},
					credentials: 'same-origin',
					body: body.toString()
				})
				.then(function(response) {
					return response.json();
				})
				.then(function(json) {
					if (!json || !json.success || !json.data || !json.data.html) {
						var message = (json && json.data && json.data.message) ? json.data.message : (clmsSgAiI18n ? clmsSgAiI18n.errorGenerate : '');
						throw new Error(message);
					}

					panel.outerHTML = json.data.html;

					var feedbackField = document.getElementById('clms_sg_feedback');
					if (feedbackField && json.data.feedback_draft && !feedbackField.value) {
						feedbackField.value = json.data.feedback_draft;
					}
				})
				.catch(function(error) {
					btn.disabled = false;
					if (window.ATORA && window.ATORA.ui && window.ATORA.ui.setLoading) {
						window.ATORA.ui.setLoading(btn, false);
					}
					if (labelEl) {
						labelEl.textContent = defaultLabel;
					} else {
						btn.textContent = defaultLabel;
					}

					if (feedbackBox) {
						feedbackBox.style.display = 'block';
						feedbackBox.className = 'clms-sg-flash is-error';
						feedbackBox.textContent = error && error.message ? error.message : (clmsSgAiI18n ? clmsSgAiI18n.errorGeneric : '');
					}
				});
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	public function ajax_generate_ai_review() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Debes iniciar sesión.', 'atora-lms' ),
				),
				401
			);
		}

		$submission_id = isset( $_POST['submission_id'] ) ? absint( wp_unslash( $_POST['submission_id'] ) ) : 0;
		$nonce         = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$user_id       = get_current_user_id();

		if ( ! $submission_id ) {
			wp_send_json_error(
				array(
					'message' => __( 'Entrega no válida.', 'atora-lms' ),
				),
				400
			);
		}

		if ( ! wp_verify_nonce( $nonce, 'clms_ai_generate_' . $submission_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No se pudo validar la solicitud.', 'atora-lms' ),
				),
				403
			);
		}

		if ( ! $this->current_user_can_grade_submission( $submission_id, $user_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No tienes permisos para generar análisis IA para esta entrega.', 'atora-lms' ),
				),
				403
			);
		}

		// PT-5.3 (6.5.8): migrado de get_transient()/set_transient() (no
		// atómico) a ATORA_Rate_Limiter — una revisión IA es una
		// operación con costo real, así que se falla cerrado si el
		// backend del limiter no está disponible.
		// Rate limit: max 5 revisiones IA por usuario cada 5 minutos.
		$rl_allowed = class_exists( 'ATORA_Rate_Limiter' )
			&& \ATORA_Rate_Limiter::consume( 'grading_ai_review', (string) absint( $user_id ), 5, 5 * MINUTE_IN_SECONDS, false );

		if ( ! $rl_allowed ) {
			wp_send_json_error(
				array(
					'message' => __( 'Límite de revisiones IA alcanzado. Espera unos minutos antes de continuar.', 'atora-lms' ),
				),
				429
			);
		}

		$result = $this->handle_ai_generate_request( $submission_id, $user_id, $nonce );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				),
				500
			);
		}

		$submission = clms_core('CLMS_Submission');
		$ai_data    = array();

		if ( $submission && method_exists( $submission, 'get_ai_review_data' ) ) {
			$ai_data = $submission->get_ai_review_data( $submission_id );
			$ai_data = $this->normalize_ai_panel_data( $ai_data );
		}

		$context = array(
			'submission_id' => $submission_id,
		);

		wp_send_json_success(
			array(
				'message'          => __( 'Revisión IA generada correctamente.', 'atora-lms' ),
				'html'             => $this->render_ai_review_panel( $context ),
				'feedback_draft'   => isset( $ai_data['feedback_draft'] ) ? (string) $ai_data['feedback_draft'] : '',
				'score_suggestion' => isset( $ai_data['score_suggestion'] ) ? (string) $ai_data['score_suggestion'] : '',
				'status'           => isset( $ai_data['status'] ) ? (string) $ai_data['status'] : 'not_requested',
			)
		);
	}

	protected function render_assessment_audit_panel( $context ) {
		$assessment = isset( $context['assessment'] ) && is_array( $context['assessment'] ) ? $context['assessment'] : array();
		$audit_log  = isset( $context['assessment_log'] ) && is_array( $context['assessment_log'] ) ? array_reverse( $context['assessment_log'] ) : array();
		$settings   = isset( $context['evaluation_settings'] ) && is_array( $context['evaluation_settings'] ) ? $context['evaluation_settings'] : array();
		$yes_label  = __( 'Sí', 'atora-lms' );
		$no_label   = __( 'No', 'atora-lms' );

		if ( empty( $assessment ) && empty( $audit_log ) ) {
			return '';
		}

		ob_start();
		?>
		<section class="clms-sg-card">
			<div class="clms-sg-section-head">
				<div>
					<div class="clms-sg-eyebrow"><?php esc_html_e( 'Auditoría', 'atora-lms' ); ?></div>
					<h2 class="clms-sg-subtitle"><?php esc_html_e( 'Trazabilidad de la calificación', 'atora-lms' ); ?></h2>
				</div>
			</div>

			<div class="clms-sg-audit-meta">
				<div class="clms-sg-audit-kpi">
					<span><?php esc_html_e( 'Fuente actual', 'atora-lms' ); ?></span>
					<strong><?php echo esc_html( $this->format_assessment_label( isset( $assessment['grade_source'] ) ? $assessment['grade_source'] : '' ) ); ?></strong>
				</div>
				<div class="clms-sg-audit-kpi">
					<span><?php esc_html_e( 'Modo', 'atora-lms' ); ?></span>
					<strong><?php echo esc_html( $this->format_assessment_label( isset( $settings['mode'] ) ? $settings['mode'] : '' ) ); ?></strong>
				</div>
				<div class="clms-sg-audit-kpi">
					<span><?php esc_html_e( 'Actividad', 'atora-lms' ); ?></span>
					<strong><?php echo esc_html( $this->format_assessment_label( isset( $settings['activity_type'] ) ? $settings['activity_type'] : '' ) ); ?></strong>
				</div>
				<div class="clms-sg-audit-kpi">
					<span><?php esc_html_e( 'Override manual', 'atora-lms' ); ?></span>
					<strong><?php echo esc_html( ! empty( $assessment['manual_override'] ) ? $yes_label : $no_label ); ?></strong>
				</div>
			</div>

			<?php if ( '' !== (string) ( $assessment['confidence'] ?? '' ) || '' !== (string) ( $assessment['raw_confidence'] ?? '' ) || ! empty( $assessment['provider'] ) || ! empty( $assessment['model_version'] ) ) : ?>
				<div class="clms-sg-audit-summary">
					<?php if ( '' !== (string) ( $assessment['raw_confidence'] ?? '' ) ) : ?>
						<p class="clms-sg-copy"><strong><?php esc_html_e( 'Confianza cruda:', 'atora-lms' ); ?></strong> <?php echo esc_html( round( (float) $assessment['raw_confidence'] * 100 ) ); ?>%</p>
					<?php endif; ?>
					<?php if ( '' !== (string) ( $assessment['confidence'] ?? '' ) ) : ?>
						<p class="clms-sg-copy"><strong><?php esc_html_e( 'Confianza normalizada:', 'atora-lms' ); ?></strong> <?php echo esc_html( round( (float) $assessment['confidence'] * 100 ) ); ?>%</p>
					<?php endif; ?>
					<?php if ( isset( $settings['ai_confidence_threshold'] ) && '' !== (string) $settings['ai_confidence_threshold'] ) : ?>
						<p class="clms-sg-copy"><strong><?php esc_html_e( 'Umbral aplicado:', 'atora-lms' ); ?></strong> <?php echo esc_html( round( (float) $settings['ai_confidence_threshold'] * 100 ) ); ?>%</p>
					<?php endif; ?>
					<?php if ( ! empty( $assessment['provider'] ) || ! empty( $assessment['model_version'] ) ) : ?>
						<p class="clms-sg-copy"><strong><?php esc_html_e( 'Motor:', 'atora-lms' ); ?></strong> <?php echo esc_html( trim( (string) ( $assessment['provider'] ?? '' ) . ' ' . (string) ( $assessment['model_version'] ?? '' ) ) ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $audit_log ) ) : ?>
				<div class="clms-sg-audit-log">
					<?php foreach ( $audit_log as $event ) :
						$event = is_array( $event ) ? $event : array();
						$parts = array();
						if ( ! empty( $event['trigger'] ) ) {
							$parts[] = sprintf( __( 'Trigger: %s', 'atora-lms' ), $event['trigger'] );
						}
						if ( ! empty( $event['status'] ) ) {
							$parts[] = sprintf( __( 'Estado: %s', 'atora-lms' ), $event['status'] );
						}
						if ( '' !== (string) ( $event['grade'] ?? '' ) ) {
							$parts[] = sprintf( __( 'Nota: %s', 'atora-lms' ), absint( $event['grade'] ) );
						}
						if ( '' !== (string) ( $event['raw_confidence'] ?? '' ) ) {
							$parts[] = sprintf( __( 'Cruda: %s%%', 'atora-lms' ), round( (float) $event['raw_confidence'] * 100 ) );
						}
						if ( '' !== (string) ( $event['confidence'] ?? '' ) ) {
							$parts[] = sprintf( __( 'Norm.: %s%%', 'atora-lms' ), round( (float) $event['confidence'] * 100 ) );
						}
						if ( '' !== (string) ( $event['threshold_applied'] ?? '' ) ) {
							$parts[] = sprintf( __( 'Umbral: %s%%', 'atora-lms' ), round( (float) $event['threshold_applied'] * 100 ) );
						}
						if ( ! empty( $event['provider'] ) || ! empty( $event['model_version'] ) ) {
							$parts[] = sprintf(
								__( 'Modelo: %s', 'atora-lms' ),
								trim( (string) ( $event['provider'] ?? '' ) . ' ' . (string) ( $event['model_version'] ?? '' ) )
							);
						}
						?>
						<article class="clms-sg-audit-entry">
							<div class="clms-sg-audit-entry__top">
								<strong><?php echo esc_html( $this->format_assessment_label( isset( $event['source'] ) ? $event['source'] : '' ) ); ?></strong>
								<span><?php echo esc_html( $this->format_datetime( isset( $event['recorded_at'] ) ? $event['recorded_at'] : '' ) ); ?></span>
							</div>
							<p class="clms-sg-copy"><?php echo esc_html( ! empty( $parts ) ? implode( ' | ', $parts ) : __( 'Evento registrado.', 'atora-lms' ) ); ?></p>
							<?php if ( ! empty( $event['note'] ) ) : ?>
								<p class="clms-sg-copy"><?php echo esc_html( $event['note'] ); ?></p>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php

		return ob_get_clean();
	}

	protected function handle_ai_generate_request( $submission_id, $user_id, $nonce = '' ) {
		$submission_id = absint( $submission_id );
		$user_id       = absint( $user_id );
		$nonce         = '' !== $nonce
			? sanitize_text_field( (string) $nonce )
			: ( isset( $_POST['clms_ai_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_ai_nonce'] ) ) : '' );

		if ( ! $submission_id || ! $user_id ) {
			return new WP_Error( 'invalid_request', __( 'Solicitud IA inválida.', 'atora-lms' ) );
		}

		if ( ! wp_verify_nonce( $nonce, 'clms_ai_generate_' . $submission_id ) ) {
			return new WP_Error( 'invalid_nonce', __( 'No se pudo validar la generación IA.', 'atora-lms' ) );
		}

		if ( ! $this->current_user_can_grade_submission( $submission_id, $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'No tienes permisos para generar análisis IA para esta entrega.', 'atora-lms' ) );
		}

		$assessment_engine = clms_core('CLMS_Assessment_Engine');
		if ( $assessment_engine && method_exists( $assessment_engine, 'request_ai_assessment' ) ) {
			return $assessment_engine->request_ai_assessment(
				$submission_id,
				array(
					'initiated_by'  => $user_id,
					'trigger'       => 'teacher_request',
					'allow_publish' => true,
				)
			);
		}

		$ai = clms_core('CLMS_AI');
		if ( ! $ai || ! method_exists( $ai, 'generate_submission_review' ) ) {
			return new WP_Error( 'ai_module_missing', __( 'El módulo IA no está disponible.', 'atora-lms' ) );
		}

		return $ai->generate_submission_review( $submission_id );
	}

	protected function get_ai_status_label( $status ) {
		switch ( (string) $status ) {
			case 'queued':
				return __( 'En cola', 'atora-lms' );
			case 'processing':
				return __( 'Procesando', 'atora-lms' );
			case 'completed':
				return __( 'Completado', 'atora-lms' );
			case 'failed':
				return __( 'Falló', 'atora-lms' );
			default:
				return __( 'No solicitado', 'atora-lms' );
		}
	}

	protected function normalize_ai_panel_data( $data ) {
		$data = is_array( $data ) ? $data : array();

		$defaults = array(
			'status'           => 'not_requested',
			'provider'         => '',
			'model'            => '',
			'summary'          => '',
			'feedback_draft'   => '',
			'confidence'       => '',
			'score_suggestion' => '',
			'criteria_scores'  => array(),
			'raw'              => array(),
			'updated_at'       => '',
			'error'            => '',
			'source_files'     => array(),
			'highlights'       => array(),
		);

		$data = array_merge( $defaults, $data );

		if ( '' === (string) $data['score_suggestion'] && isset( $data['suggested_grade'] ) && '' !== (string) $data['suggested_grade'] ) {
			$data['score_suggestion'] = $data['suggested_grade'];
		}

		if ( ! is_array( $data['highlights'] ) ) {
			$data['highlights'] = array();
		}

		if ( ! is_array( $data['criteria_scores'] ) ) {
			$data['criteria_scores'] = array();
		}

		if ( '' !== (string) $data['score_suggestion'] ) {
			$data['score_suggestion'] = max( 0, min( 100, absint( $data['score_suggestion'] ) ) );
		} else {
			$data['score_suggestion'] = '';
		}

		return $data;
	}

}
