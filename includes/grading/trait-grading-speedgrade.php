<?php

/**
 * CLMS_Grading — Calificaciones, resumen académico y SpeedGrade.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Grading_SpeedGrade_Trait {
	public function render_speedgrade_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'submission_id' => 0,
			),
			(array) $atts,
			'clms_speedgrade'
		);

		$submission_id = absint( $atts['submission_id'] );

		if ( ! $submission_id && isset( $_GET['submission_id'] ) ) {
			$submission_id = absint( wp_unslash( $_GET['submission_id'] ) );
		}

		return $this->get_speedgrade_markup( $submission_id );
	}

	public function maybe_render_speedgrade_screen() {
		if ( empty( $_GET[ self::SPEEDGRADE_VAR ] ) ) {
			return;
		}

		$submission_id = isset( $_GET['submission_id'] ) ? absint( wp_unslash( $_GET['submission_id'] ) ) : 0;

		status_header( 200 );
		nocache_headers();

		echo $this->get_speedgrade_document( $submission_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function get_speedgrade_url( $submission_id, $return_url = '' ) {
		$args = array(
			self::SPEEDGRADE_VAR => 1,
			'submission_id'      => absint( $submission_id ),
		);

		if ( $return_url ) {
			$args[ self::SPEEDGRADE_RETURN ] = rawurlencode( esc_url_raw( $return_url ) );
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	protected function get_speedgrade_document( $submission_id ) {
		ob_start();
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php echo esc_html( sprintf( __( '%1$s - SpeedGrade', 'atora-lms' ), get_bloginfo( 'name' ) ) ); ?></title>
			<?php wp_head(); ?>
		</head>
		<body <?php body_class( 'clms-speedgrade-body' ); ?>>
			<?php wp_body_open(); ?>
			<?php echo $this->get_speedgrade_markup( $submission_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	protected function get_speedgrade_markup( $submission_id ) {
		$this->enqueue_assets();

		if ( ! is_user_logged_in() ) {
			return $this->render_notice_card(
				__( 'Acceso requerido', 'atora-lms' ),
				__( 'Debes iniciar sesión para revisar entregas.', 'atora-lms' )
			);
		}

		$user_id       = get_current_user_id();
		$submission_id = absint( $submission_id );

		if ( ! $submission_id || self::SUBMISSION_CPT !== get_post_type( $submission_id ) ) {
			return $this->render_notice_card(
				__( 'Entrega no válida', 'atora-lms' ),
				__( 'No encontramos una entrega para revisar.', 'atora-lms' )
			);
		}

		if ( ! $this->current_user_can_grade_submission( $submission_id, $user_id ) ) {
			return $this->render_notice_card(
				__( 'Sin permisos', 'atora-lms' ),
				__( 'No tienes permisos para revisar esta entrega.', 'atora-lms' )
			);
		}

		$flash = '';

		if (
			'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) &&
			isset( $_POST['clms_speedgrade_action'] ) &&
			self::SPEEDGRADE_ACTION === sanitize_text_field( wp_unslash( $_POST['clms_speedgrade_action'] ) ) &&
			isset( $_POST['submission_id'] ) &&
			absint( wp_unslash( $_POST['submission_id'] ) ) === $submission_id
		) {
			$result = $this->handle_speedgrade_save( $submission_id, $user_id );

			if ( is_wp_error( $result ) ) {
				$flash = '<div class="clms-sg-flash is-error">' . esc_html( $result->get_error_message() ) . '</div>';
			} else {
				$next_submission_id = 0;
				$raw_action         = isset( $_POST['clms_sg_submit'] ) ? wp_unslash( $_POST['clms_sg_submit'] ) : 'save_draft';
				$submit_action      = class_exists( 'CLMS_SpeedGrade_Actions' ) ? CLMS_SpeedGrade_Actions::normalize_submit_action( $raw_action ) : sanitize_key( (string) $raw_action );

				if ( 'save_next' === $submit_action ) {
					$adjacent = $this->get_adjacent_submissions( $submission_id, $user_id );
					if ( ! empty( $adjacent['next'] ) ) {
						$next_submission_id = absint( $adjacent['next'] );
					}
				}

				$return_url = $this->get_return_url_from_post();

				if ( $next_submission_id ) {
					wp_safe_redirect( $this->get_speedgrade_url( $next_submission_id, $return_url ) );
					exit;
				}

				wp_safe_redirect( $this->get_speedgrade_url( $submission_id, $return_url ) );
				exit;
			}
		}

		$context = $this->get_submission_context( $submission_id, $user_id );

		if ( empty( $context ) ) {
			return $this->render_notice_card(
				__( 'Entrega no disponible', 'atora-lms' ),
				__( 'No se pudo cargar el contexto de la entrega.', 'atora-lms' )
			);
		}

			$adjacent   = $this->get_adjacent_submissions( $submission_id, $user_id );
			$return_url = $this->get_return_url();
			$queue_items = $this->get_submission_queue( $submission_id, $user_id );
			$current_position = 0;
			$queue_total      = count( $queue_items );
			$display_current  = 0;
			$display_total    = $queue_total;

			foreach ( $queue_items as $queue_index => $queue_item ) {
				if ( ! empty( $queue_item['is_current'] ) ) {
					$current_position = $queue_index + 1;
					break;
				}
			}
			if ( $queue_total > 0 ) {
				$display_current = max( 1, $current_position );
			}

			ob_start();
			?>
			<div class="clms-sg-wrap">
				<div class="clms-sg-layout">
					<?php echo $this->render_speedgrade_queue_sidebar( $queue_items, $display_current, $display_total, $return_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

					<div class="clms-sg-shell">

				<?php echo $this->render_speedgrade_topbar_actions( $adjacent, $return_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php echo $flash; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<section class="clms-sg-hero">
					<div class="clms-sg-hero__main">
						<div class="clms-sg-pill"><?php esc_html_e( 'SpeedGrade', 'atora-lms' ); ?></div>
						<h1 class="clms-sg-title"><?php echo esc_html( $context['student_name'] ); ?></h1>
						<p class="clms-sg-copy clms-sg-copy--light"><?php echo esc_html( $context['lesson_title'] ); ?></p>
						<p class="clms-sg-copy clms-sg-copy--light"><?php echo esc_html( $context['course_title'] ); ?></p>
					</div>

					<div class="clms-sg-hero__stats">
						<div class="clms-sg-stat">
							<div class="clms-sg-stat__label"><?php esc_html_e( 'Estado', 'atora-lms' ); ?></div>
							<div class="clms-sg-stat__value"><?php echo esc_html( $context['status_label'] ); ?></div>
						</div>
						<div class="clms-sg-stat">
							<div class="clms-sg-stat__label"><?php esc_html_e( 'Enviada', 'atora-lms' ); ?></div>
							<div class="clms-sg-stat__value clms-sg-stat__value--sm"><?php echo esc_html( $context['submitted_at'] ); ?></div>
						</div>
						<div class="clms-sg-stat">
							<div class="clms-sg-stat__label"><?php esc_html_e( 'Archivos', 'atora-lms' ); ?></div>
							<div class="clms-sg-stat__value"><?php echo esc_html( count( $context['files'] ) ); ?></div>
						</div>
						<div class="clms-sg-stat">
							<div class="clms-sg-stat__label"><?php esc_html_e( 'Progreso del curso', 'atora-lms' ); ?></div>
							<div class="clms-sg-stat__value"><?php echo esc_html( absint( $context['student_snapshot']['progress_percent'] ?? 0 ) ); ?>%</div>
						</div>
						<div class="clms-sg-stat">
							<div class="clms-sg-stat__label"><?php esc_html_e( 'IA pendiente', 'atora-lms' ); ?></div>
							<div class="clms-sg-stat__value"><?php echo ! empty( $context['ai_pending_validation'] ) ? esc_html__( 'Sí', 'atora-lms' ) : esc_html__( 'No', 'atora-lms' ); ?></div>
						</div>
					</div>
				</section>

				<div class="clms-sg-grid">
					<main class="clms-sg-main">
						<section class="clms-sg-card">
							<div class="clms-sg-section-head">
								<div>
									<div class="clms-sg-eyebrow"><?php esc_html_e( 'Contexto', 'atora-lms' ); ?></div>
									<h2 class="clms-sg-subtitle"><?php esc_html_e( 'Resumen de la entrega', 'atora-lms' ); ?></h2>
								</div>
							</div>

							<div class="clms-sg-meta-grid">
								<div class="clms-sg-meta-item">
									<span><?php esc_html_e( 'Estudiante', 'atora-lms' ); ?></span>
									<strong><?php echo esc_html( $context['student_name'] ); ?></strong>
								</div>
								<div class="clms-sg-meta-item">
									<span><?php esc_html_e( 'Curso', 'atora-lms' ); ?></span>
									<strong><?php echo esc_html( $context['course_title'] ); ?></strong>
								</div>
								<div class="clms-sg-meta-item">
									<span><?php esc_html_e( 'Lección', 'atora-lms' ); ?></span>
									<strong><?php echo esc_html( $context['lesson_title'] ); ?></strong>
								</div>
								<div class="clms-sg-meta-item">
									<span><?php esc_html_e( 'Estado actual', 'atora-lms' ); ?></span>
									<strong><?php echo esc_html( $context['status_label'] ); ?></strong>
								</div>
							</div>

							<?php
							$delivery_comment = isset( $context['comment'] ) ? trim( (string) $context['comment'] ) : '';
							$lesson_comment   = isset( $context['lesson_comment'] ) ? trim( (string) $context['lesson_comment'] ) : '';
							?>
							<?php if ( $delivery_comment || $lesson_comment ) : ?>
								<div class="clms-sg-comment">
									<h3><?php esc_html_e( 'Comentario del estudiante', 'atora-lms' ); ?></h3>
									<?php if ( $delivery_comment ) : ?>
										<p class="clms-sg-copy" style="margin-bottom:6px"><strong><?php esc_html_e( 'Sobre la entrega:', 'atora-lms' ); ?></strong></p>
										<div class="clms-sg-copy"><?php echo wp_kses_post( wpautop( $delivery_comment ) ); ?></div>
									<?php endif; ?>
									<?php if ( $lesson_comment && $lesson_comment !== $delivery_comment ) : ?>
										<p class="clms-sg-copy" style="margin:12px 0 6px"><strong><?php esc_html_e( 'Sobre la clase:', 'atora-lms' ); ?></strong></p>
										<div class="clms-sg-copy"><?php echo wp_kses_post( wpautop( $lesson_comment ) ); ?></div>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</section>

						<section class="clms-sg-card">
							<div class="clms-sg-section-head">
								<div>
									<div class="clms-sg-eyebrow"><?php esc_html_e( 'Contexto académico', 'atora-lms' ); ?></div>
									<h2 class="clms-sg-subtitle"><?php esc_html_e( 'Historial y progreso del estudiante', 'atora-lms' ); ?></h2>
								</div>
							</div>

							<?php echo $this->render_speedgrade_history_panel( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</section>

						<section class="clms-sg-card">
							<div class="clms-sg-section-head">
								<div>
									<div class="clms-sg-eyebrow"><?php esc_html_e( 'Adjuntos', 'atora-lms' ); ?></div>
									<h2 class="clms-sg-subtitle"><?php esc_html_e( 'Archivos enviados', 'atora-lms' ); ?></h2>
								</div>
							</div>

							<?php if ( empty( $context['files'] ) ) : ?>
								<p class="clms-sg-copy"><?php esc_html_e( 'Esta entrega no tiene archivos adjuntos.', 'atora-lms' ); ?></p>
							<?php else : ?>
								<div class="clms-sg-files">
									<?php foreach ( $context['files'] as $file ) : ?>
										<article class="clms-sg-file">
											<div class="clms-sg-file__content">
												<h3 class="clms-sg-file__title"><?php echo esc_html( $file['label'] ); ?></h3>
												<?php if ( $file['meta'] ) : ?>
													<p class="clms-sg-file__meta"><?php echo esc_html( $file['meta'] ); ?></p>
												<?php endif; ?>
											</div>

											<a class="clms-sg-btn clms-sg-btn--secondary" href="<?php echo esc_url( $file['url'] ); ?>" target="_blank" rel="noopener noreferrer">
												<?php esc_html_e( 'Abrir archivo', 'atora-lms' ); ?>
											</a>
										</article>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</section>
					</main>

					<aside class="clms-sg-side">
						<?php echo $this->render_ai_review_panel( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo $this->render_assessment_audit_panel( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

						<section class="clms-sg-card clms-sg-card--accent">
							<div class="clms-sg-eyebrow clms-sg-eyebrow--light"><?php esc_html_e( 'Evaluación', 'atora-lms' ); ?></div>
							<h2 class="clms-sg-subtitle clms-sg-subtitle--light"><?php esc_html_e( 'Calificar entrega', 'atora-lms' ); ?></h2>

							<form method="post" class="clms-sg-form">
								<input type="hidden" name="clms_speedgrade_action" value="<?php echo esc_attr( self::SPEEDGRADE_ACTION ); ?>">
								<input type="hidden" name="submission_id" value="<?php echo esc_attr( $context['submission_id'] ); ?>">
								<input type="hidden" name="<?php echo esc_attr( self::SPEEDGRADE_RETURN ); ?>" value="<?php echo esc_attr( rawurlencode( $return_url ) ); ?>">
								<?php wp_nonce_field( self::SPEEDGRADE_ACTION . '_' . $context['submission_id'], self::SPEEDGRADE_NONCE ); ?>

								<?php echo $this->render_speedgrade_rubric_panel( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

								<div class="clms-sg-field">
									<?php
									$grade_label = __( 'Nota final (0–100)', 'atora-lms' );
									if ( ! empty( $criteria ) ) {
										$grade_label .= ' — ' . __( 'sobreescribe la rúbrica', 'atora-lms' );
									}
									?>
									<label for="clms_sg_grade"><?php echo esc_html( $grade_label ); ?></label>
									<input type="number" min="0" max="100" step="1" id="clms_sg_grade" name="grade" value="<?php echo esc_attr( '' !== (string) $context['grade'] ? $context['grade'] : '' ); ?>">
								</div>

								<div class="clms-sg-field">
									<label for="clms_sg_status"><?php esc_html_e( 'Estado', 'atora-lms' ); ?></label>
									<select id="clms_sg_status" name="status">
										<option value="submitted" <?php selected( $context['status'], 'submitted' ); ?>><?php esc_html_e( 'Enviada', 'atora-lms' ); ?></option>
										<option value="in_review" <?php selected( $context['status'], 'in_review' ); ?>><?php esc_html_e( 'En revisión', 'atora-lms' ); ?></option>
										<option value="needs_revision" <?php selected( $context['status'], 'needs_revision' ); ?>><?php esc_html_e( 'Requiere revisión', 'atora-lms' ); ?></option>
										<option value="returned" <?php selected( $context['status'], 'returned' ); ?>><?php esc_html_e( 'Devuelta para mejora', 'atora-lms' ); ?></option>
										<option value="graded" <?php selected( $context['status'], 'graded' ); ?>><?php esc_html_e( 'Calificada', 'atora-lms' ); ?></option>
									</select>
								</div>

								<div class="clms-sg-field">
									<label for="clms_sg_feedback"><?php esc_html_e( 'Retroalimentación final del evaluador', 'atora-lms' ); ?></label>
									<textarea id="clms_sg_feedback" name="feedback" rows="8"><?php echo esc_textarea( $context['feedback'] ); ?></textarea>
								</div>

								<div class="clms-sg-actions">
									<button type="submit" name="clms_sg_submit" value="save_draft" class="clms-sg-btn clms-sg-btn--secondary">
										<?php esc_html_e( 'Guardar en revisión', 'atora-lms' ); ?>
									</button>
									<button type="submit" name="clms_sg_submit" value="publish" class="clms-sg-btn clms-sg-btn--primary">
										<?php esc_html_e( 'Publicar calificación', 'atora-lms' ); ?>
									</button>
									<button type="submit" name="clms_sg_submit" value="return_revision" class="clms-sg-btn clms-sg-btn--secondary">
										<?php esc_html_e( 'Devolver para mejorar', 'atora-lms' ); ?>
									</button>
									<?php if ( ! empty( $context['evidence']['is_required_for_certificate'] ) ) : ?>
										<button type="submit" name="clms_sg_submit" value="approve_evidence" class="clms-sg-btn clms-sg-btn--secondary">
											<?php esc_html_e( 'Marcar evidencia aprobada', 'atora-lms' ); ?>
										</button>
									<?php endif; ?>
									<button type="submit" name="clms_sg_submit" value="insert_plan" class="clms-sg-btn clms-sg-btn--ghost">
										<?php esc_html_e( 'Insertar plan de mejora', 'atora-lms' ); ?>
									</button>
									<button type="submit" name="clms_sg_submit" value="insert_competency_recommendation" class="clms-sg-btn clms-sg-btn--ghost">
										<?php esc_html_e( 'Insertar recomendación por competencia', 'atora-lms' ); ?>
									</button>
									<button type="submit" name="clms_sg_submit" value="accept_ai_draft" class="clms-sg-btn clms-sg-btn--ghost">
										<?php esc_html_e( 'Usar sugerencia IA como borrador', 'atora-lms' ); ?>
									</button>

									<?php if ( ! empty( $adjacent['next'] ) ) : ?>
										<button type="submit" name="clms_sg_submit" value="save_next" class="clms-sg-btn clms-sg-btn--secondary">
											<?php esc_html_e( 'Guardar y siguiente →', 'atora-lms' ); ?>
										</button>
									<?php endif; ?>
								</div>
								</form>
							</section>
						</aside>
					</div>
				</div>
				</div>
				<script>
				(function() {
					var queueList = document.getElementById('clms-sg-queue-list');
					var filter = document.getElementById('clms-sg-filter');
					var counter = document.getElementById('clms-sg-queue-counter');
					if (!queueList || !filter) return;

					function getVisibleItems() {
						return Array.from(queueList.querySelectorAll('.clms-sg-queue-item')).filter(function(item) {
							return !item.hidden && !!item.getAttribute('data-id');
						});
					}

					function updateCounter() {
						if (!counter) return;
						var visibleItems = getVisibleItems();
						var active = queueList.querySelector('.clms-sg-queue-item.is-active');
						var activeIndex = visibleItems.indexOf(active);
						var current = activeIndex >= 0 ? activeIndex + 1 : 1;
						var total = visibleItems.length || 1;
						counter.textContent = current + ' <?php echo esc_js( __( 'de', 'atora-lms' ) ); ?> ' + total;
					}

					function applyFilter() {
						var value = filter.value;
						Array.from(queueList.querySelectorAll('.clms-sg-queue-item')).forEach(function(item) {
							var id = item.getAttribute('data-id');
							if (!id) {
								item.hidden = false;
								return;
							}
							var status = item.getAttribute('data-status');
							var visible = true;
							if (value === 'pending') {
								visible = status === 'submitted' || status === 'needs_revision' || status === 'returned';
							} else if (value === 'in_review') {
								visible = status === 'in_review';
							}
							item.hidden = !visible;
						});
						updateCounter();
					}

					filter.addEventListener('change', applyFilter);
					applyFilter();

					document.addEventListener('keydown', function(event) {
						var tag = event.target && event.target.tagName ? event.target.tagName.toLowerCase() : '';
						if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
						if (event.metaKey || event.ctrlKey || event.altKey) return;

						var visibleItems = getVisibleItems();
						if (!visibleItems.length) return;

						var currentItem = queueList.querySelector('.clms-sg-queue-item.is-active');
						var currentIndex = visibleItems.indexOf(currentItem);
						if (currentIndex < 0) {
							currentIndex = 0;
						}

						var key = event.key;
						if (key === 'j' || key === 'J' || key === 'ArrowRight') {
							event.preventDefault();
							if (currentIndex < visibleItems.length - 1) {
								var nextLink = visibleItems[currentIndex + 1].querySelector('a');
								if (nextLink) {
									nextLink.click();
								}
							}
						}

						if (key === 'k' || key === 'K' || key === 'ArrowLeft') {
							event.preventDefault();
							if (currentIndex > 0) {
								var prevLink = visibleItems[currentIndex - 1].querySelector('a');
								if (prevLink) {
									prevLink.click();
								}
							}
						}
					});
				})();
				</script>
			</div>
			<?php

		return ob_get_clean();
	}

	/**
	 * Wrapper del sidebar de cola para permitir fallback legacy seguro.
	 *
	 * @param array  $queue_items Cola de entregas.
	 * @param int    $display_current Posición actual visible.
	 * @param int    $display_total Total visible.
	 * @param string $return_url URL de retorno.
	 * @return string
	 */
	protected function render_speedgrade_queue_sidebar( $queue_items, $display_current, $display_total, $return_url ) {
		if ( class_exists( 'CLMS_SpeedGrade_Renderer' ) && method_exists( 'CLMS_SpeedGrade_Renderer', 'render_queue_sidebar' ) ) {
			$query_service = class_exists( 'CLMS_Grading_Query_Service' ) ? new CLMS_Grading_Query_Service() : null;
			$normalized    = ( $query_service && method_exists( $query_service, 'normalize_queue_items' ) ) ? $query_service->normalize_queue_items( $queue_items ) : $queue_items;
			return CLMS_SpeedGrade_Renderer::render_queue_sidebar( $normalized, $display_current, $display_total, $return_url, array( $this, 'get_speedgrade_url' ) );
		}

		return '';
	}

	/**
	 * Wrapper del topbar de navegación de SpeedGrade.
	 *
	 * @param array  $adjacent IDs adyacentes.
	 * @param string $return_url URL de retorno.
	 * @return string
	 */
	protected function render_speedgrade_topbar_actions( $adjacent, $return_url ) {
		if ( class_exists( 'CLMS_SpeedGrade_Renderer' ) && method_exists( 'CLMS_SpeedGrade_Renderer', 'render_topbar_actions' ) ) {
			return CLMS_SpeedGrade_Renderer::render_topbar_actions( $adjacent, $return_url, array( $this, 'get_speedgrade_url' ) );
		}

		return '';
	}

	/**
	 * Wrapper del panel visual de historial/contexto académico.
	 *
	 * @param array $context Contexto completo de la entrega.
	 * @return string
	 */
	protected function render_speedgrade_history_panel( $context ) {
		if ( class_exists( 'CLMS_Grading_History_Renderer' ) && method_exists( 'CLMS_Grading_History_Renderer', 'render' ) ) {
			return CLMS_Grading_History_Renderer::render(
				$context,
				array(
					'format_risk_label'               => array( $this, 'format_risk_label' ),
					'format_certificate_status_label' => array( $this, 'format_certificate_status_label' ),
					'format_assessment_label'         => array( $this, 'format_assessment_label' ),
				)
			);
		}

		return '';
	}

	/**
	 * Wrapper del panel de rúbrica de SpeedGrade.
	 *
	 * @param array $context Contexto completo de la entrega.
	 * @return string
	 */
	protected function render_speedgrade_rubric_panel( $context ) {
		if ( class_exists( 'CLMS_Rubric_Panel_Renderer' ) && method_exists( 'CLMS_Rubric_Panel_Renderer', 'render' ) ) {
			return CLMS_Rubric_Panel_Renderer::render( $context );
		}

		return '';
	}

	/**
	 * Determina si hay puntajes reales de rúbrica.
	 *
	 * @param array $rubric_scores Filas de rúbrica.
	 * @return bool
	 */
	protected function has_speedgrade_rubric_scores( $rubric_scores ) {
		if ( ! is_array( $rubric_scores ) || empty( $rubric_scores ) ) {
			return false;
		}

		foreach ( $rubric_scores as $row ) {
			$row   = is_array( $row ) ? $row : array();
			$score = isset( $row['score'] ) ? trim( (string) $row['score'] ) : '';
			if ( '' !== $score ) {
				return true;
			}
		}

		return false;
	}

	protected function handle_speedgrade_save( $submission_id, $user_id ) {
		$submission_id = absint( $submission_id );
		$user_id       = absint( $user_id );

		if ( ! $submission_id || ! $user_id ) {
			return new WP_Error( 'invalid_request', __( 'Solicitud inválida.', 'atora-lms' ) );
		}

		$nonce = isset( $_POST[ self::SPEEDGRADE_NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::SPEEDGRADE_NONCE ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::SPEEDGRADE_ACTION . '_' . $submission_id ) ) {
			return new WP_Error( 'invalid_nonce', __( 'No pudimos validar la solicitud.', 'atora-lms' ) );
		}

		if ( ! $this->current_user_can_grade_submission( $submission_id, $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'No tienes permisos para revisar esta entrega.', 'atora-lms' ) );
		}

		$status_service = class_exists( 'CLMS_Grading_Status_Service' ) ? new CLMS_Grading_Status_Service() : null;
		$status_raw     = isset( $_POST['status'] ) ? wp_unslash( $_POST['status'] ) : 'in_review';
		$status         = ( $status_service && method_exists( $status_service, 'normalize_status' ) )
			? $status_service->normalize_status( $status_raw )
			: sanitize_key( (string) $status_raw );
		$feedback_service = class_exists( 'CLMS_Grading_Feedback_Service' ) ? new CLMS_Grading_Feedback_Service() : null;
		$feedback         = isset( $_POST['feedback'] ) ? wp_unslash( $_POST['feedback'] ) : '';
		$feedback         = ( $feedback_service && method_exists( $feedback_service, 'sanitize_feedback' ) )
			? $feedback_service->sanitize_feedback( $feedback )
			: wp_kses_post( (string) $feedback );
		$submit_action_raw = isset( $_POST['clms_sg_submit'] ) ? wp_unslash( $_POST['clms_sg_submit'] ) : 'save_draft';
		$submit_action     = class_exists( 'CLMS_SpeedGrade_Actions' ) ? CLMS_SpeedGrade_Actions::normalize_submit_action( $submit_action_raw ) : sanitize_key( (string) $submit_action_raw );

		// Rubric: read per-criterion scores if available
		$lesson_id      = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$rubric_id      = $lesson_id ? absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) ) : 0;
		$rubric_snapshot = $rubric_id ? get_post_meta( $submission_id, '_clms_submission_rubric_snapshot', true ) : array();
		$rubric_snapshot = is_array( $rubric_snapshot ) ? $rubric_snapshot : array();
		$rubric_scores  = array();
		$grade_from_rubric = '';

		if ( $rubric_id && class_exists( 'CLMS_Rubric' ) ) {
			$criteria = array();
			$total_points = 0;
			$scale_type = '';
			$is_holistic = false;

			$has_snapshot = ! empty( $rubric_snapshot['rubric_id'] )
				&& $rubric_id === absint( $rubric_snapshot['rubric_id'] )
				&& ! empty( $rubric_snapshot['criteria'] )
				&& is_array( $rubric_snapshot['criteria'] );

			if ( $has_snapshot ) {
				$criteria     = (array) $rubric_snapshot['criteria'];
				$total_points = absint( $rubric_snapshot['total_points'] ?? 0 );
				$scale_type   = sanitize_key( (string) ( $rubric_snapshot['scale_type'] ?? '' ) );
				$is_holistic  = ! empty( $rubric_snapshot['is_holistic'] );
			} else {
				$criteria     = CLMS_Rubric::get_criteria( $rubric_id );
				$total_points = absint( CLMS_Rubric::get_total_points( $rubric_id ) );
				$scale_type   = sanitize_key( (string) get_post_meta( $rubric_id, CLMS_Rubric::META_SCALE, true ) );
				$is_holistic  = '1' === (string) get_post_meta( $rubric_id, CLMS_Rubric::META_HOLISTIC, true );

				// Snapshot por entrega: evita que cambios futuros en la rúbrica rompan la trazabilidad.
				if ( ! empty( $criteria ) ) {
					$rubric_snapshot = array(
						'rubric_id'     => $rubric_id,
						'rubric_title'  => (string) get_the_title( $rubric_id ),
						'scale_type'    => $scale_type,
						'is_holistic'   => $is_holistic ? 1 : 0,
						'total_points'  => $total_points,
						'criteria'      => $criteria,
						'captured_at'   => current_time( 'mysql' ),
					);
					update_post_meta( $submission_id, '_clms_submission_rubric_snapshot', $rubric_snapshot );
					update_post_meta( $submission_id, '_clms_submission_rubric_snapshot_hash', md5( wp_json_encode( $rubric_snapshot ) ) );
				}
			}

			$raw_scores  = isset( $_POST['rubric_scores'] ) ? wp_unslash( $_POST['rubric_scores'] ) : array();
			$raw_scores  = is_array( $raw_scores ) ? $raw_scores : array();
			$total_pts   = 0;
			$earned_pts  = 0;
			$total_weight = 0.0;
			$earned_weight = 0.0;
			$total_criteria = is_array( $criteria ) ? count( $criteria ) : 0;
			$scored_criteria = 0;

			foreach ( (array) $criteria as $i => $c ) {
				$max            = isset( $c['max_points'] ) ? absint( $c['max_points'] ) : 0;
				$weight         = isset( $c['weight'] ) ? (float) $c['weight'] : 0.0;
				$score_raw      = isset( $raw_scores[ $i ] ) ? trim( (string) $raw_scores[ $i ] ) : '';
				$score          = '' !== $score_raw ? max( 0, min( $max, absint( $score_raw ) ) ) : '';
				$rubric_fb_raw  = isset( $_POST['rubric_feedback'][ $i ] ) ? wp_unslash( $_POST['rubric_feedback'][ $i ] ) : '';
				$rubric_fb_safe = ( $feedback_service && method_exists( $feedback_service, 'sanitize_rubric_comment' ) )
					? $feedback_service->sanitize_rubric_comment( $rubric_fb_raw )
					: sanitize_textarea_field( (string) $rubric_fb_raw );

				$total_pts  += $max;
				$total_weight += max( 0.0, min( 100.0, $weight ) );
				if ( '' !== (string) $score ) {
					$earned_pts += absint( $score );
					if ( $max > 0 ) {
						$earned_weight += ( (float) absint( $score ) / (float) $max ) * max( 0.0, min( 100.0, $weight ) );
					}
					++$scored_criteria;
				}
				if ( '' !== (string) $score || '' !== trim( (string) $rubric_fb_safe ) ) {
					$rubric_scores[ $i ] = array(
						'name'            => isset( $c['name'] ) ? $c['name'] : '',
						'competency'      => isset( $c['competency'] ) ? sanitize_text_field( (string) $c['competency'] ) : '',
						'improvement_tip' => isset( $c['improvement_tip'] ) ? sanitize_textarea_field( (string) $c['improvement_tip'] ) : '',
						'max_points'      => $max,
						'score'           => $score,
						'feedback'        => $rubric_fb_safe,
					);
				}
			}

			// Derive overall grade 0-100 proportionally from rubric, only if all criteria scored
			$all_scored = $total_criteria > 0 && $scored_criteria === $total_criteria;
			if ( $all_scored && $total_pts > 0 ) {
				if ( $total_weight > 0.0 ) {
					$grade_from_rubric = (int) round( ( $earned_weight / $total_weight ) * 100 );
				} else {
					$grade_from_rubric = (int) round( ( $earned_pts / $total_pts ) * 100 );
				}
			}
		}

		// Manual override grade field (blank = derive from rubric, or leave empty)
		$grade_raw = isset( $_POST['grade'] ) ? trim( (string) wp_unslash( $_POST['grade'] ) ) : '';
		if ( '' !== $grade_raw ) {
			if ( ! is_numeric( $grade_raw ) ) {
				return new WP_Error( 'invalid_grade', __( 'La nota debe ser numérica.', 'atora-lms' ) );
			}
			$grade = max( 0, min( 100, (int) round( (float) $grade_raw ) ) );
		} elseif ( '' !== $grade_from_rubric ) {
			$grade = $grade_from_rubric;
		} else {
			$grade = '';
		}

		if ( ! in_array( $status, array( 'submitted', 'in_review', 'graded', 'needs_revision', 'returned' ), true ) ) {
			$status = 'in_review';
		}

		if ( 'save_draft' === $submit_action || 'save_next' === $submit_action ) {
			$status = 'in_review';
		} elseif ( 'publish' === $submit_action ) {
			$status = 'graded';
		} elseif ( 'return_revision' === $submit_action ) {
			$status = 'needs_revision';
		} elseif ( 'approve_evidence' === $submit_action ) {
			$status = 'graded';
		}

		if ( 'accept_ai_draft' === $submit_action ) {
			$ai_grade    = get_post_meta( $submission_id, '_clms_ai_review_suggested_grade', true );
			$ai_feedback = (string) get_post_meta( $submission_id, '_clms_ai_review_feedback_draft', true );
			if ( '' !== (string) $ai_grade && is_numeric( $ai_grade ) ) {
				$grade = max( 0, min( 100, absint( round( (float) $ai_grade ) ) ) );
			}
			if ( '' !== trim( $ai_feedback ) ) {
				$feedback = sanitize_textarea_field( $ai_feedback );
			}
			if ( 'submitted' === $status ) {
				$status = 'in_review';
			}
		}

		if ( 'insert_plan' === $submit_action ) {
			$plan_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Improvement_Plan_Service') : null;
			$plan_data    = ( $plan_service && method_exists( $plan_service, 'build_from_submission' ) ) ? (array) $plan_service->build_from_submission( $submission_id ) : array();
			$plan_line    = '';
			if ( ! empty( $plan_data['next_action'] ) ) {
				$plan_line = sanitize_text_field( (string) $plan_data['next_action'] );
			} elseif ( ! empty( $plan_data['recommendation'] ) ) {
				$plan_line = sanitize_text_field( (string) $plan_data['recommendation'] );
			} elseif ( ! empty( $plan_data['recommendations'][0] ) ) {
				$plan_line = sanitize_text_field( (string) $plan_data['recommendations'][0] );
			}
			if ( '' !== $plan_line ) {
				$append = sprintf(
					/* translators: %s: acción de mejora */
					__( 'Plan de mejora sugerido: %s', 'atora-lms' ),
					$plan_line
				);
				if ( false === strpos( wp_strip_all_tags( (string) $feedback ), $append ) ) {
					$feedback = trim( (string) $feedback );
					$feedback = '' !== $feedback ? $feedback . "\n\n" . $append : $append;
				}
			}
			if ( 'submitted' === $status ) {
				$status = 'in_review';
			}
		}

		if ( 'insert_competency_recommendation' === $submit_action ) {
			$comp_line = '';
			$context_for_comp = (array) $this->get_submission_context( $submission_id, $user_id );
			$focus_items = isset( $context_for_comp['competency_focus'] ) && is_array( $context_for_comp['competency_focus'] )
				? $context_for_comp['competency_focus']
				: array();

			if ( ! empty( $focus_items[0] ) && is_array( $focus_items[0] ) ) {
				$comp_title = sanitize_text_field( (string) ( $focus_items[0]['title'] ?? '' ) );
				$comp_rec   = sanitize_text_field( (string) ( $focus_items[0]['recommendation'] ?? '' ) );
				if ( '' !== $comp_title && '' !== $comp_rec ) {
					$comp_line = sprintf(
						/* translators: 1: competencia, 2: recomendación */
						__( 'Recomendación por competencia (%1$s): %2$s', 'atora-lms' ),
						$comp_title,
						$comp_rec
					);
				} elseif ( '' !== $comp_title ) {
					$comp_line = sprintf(
						/* translators: %s: competencia */
						__( 'Recomendación por competencia: refuerza %s con una nueva práctica guiada.', 'atora-lms' ),
						$comp_title
					);
				}
			}

			if ( '' === $comp_line ) {
				$comp_line = __( 'Recomendación por competencia: fortalece el criterio más débil con práctica específica antes de la próxima entrega.', 'atora-lms' );
			}

			if ( false === strpos( wp_strip_all_tags( (string) $feedback ), $comp_line ) ) {
				$feedback = trim( (string) $feedback );
				$feedback = '' !== $feedback ? $feedback . "\n\n" . $comp_line : $comp_line;
			}

			if ( 'submitted' === $status ) {
				$status = 'in_review';
			}
		}

		if ( 'approve_evidence' === $submit_action ) {
			$minimum_grade = 0;
			$evidence_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
			if ( $evidence_service && method_exists( $evidence_service, 'get_activity_evidence_config' ) ) {
				$evidence_config = (array) $evidence_service->get_activity_evidence_config( $lesson_id );
				$minimum_grade   = absint( $evidence_config['minimum_grade'] ?? 0 );
			}
			if ( $minimum_grade <= 0 ) {
				$minimum_grade = 70;
			}
			if ( '' === (string) $grade || ! is_numeric( $grade ) ) {
				$grade = $minimum_grade;
			} else {
				$grade = max( absint( $grade ), $minimum_grade );
			}

			$approval_line = __( 'Evidencia obligatoria marcada como aprobada.', 'atora-lms' );
			if ( false === strpos( wp_strip_all_tags( (string) $feedback ), $approval_line ) ) {
				$feedback = trim( (string) $feedback );
				$feedback = '' !== $feedback ? $feedback . "\n\n" . $approval_line : $approval_line;
			}
		}

		if ( '' !== $grade && 'submitted' === $status ) {
			$status = 'graded';
		}

		$assessment_engine = clms_core('CLMS_Assessment_Engine');
		$grade_source      = $this->has_speedgrade_rubric_scores( $rubric_scores ) ? 'rubric' : 'manual';
		if ( 'accept_ai_draft' === $submit_action ) {
			$grade_source = 'ai_assisted';
		}
		if ( $assessment_engine && method_exists( $assessment_engine, 'publish_submission_grade' ) ) {
			$result = $assessment_engine->publish_submission_grade(
				$submission_id,
				array(
					'grade'         => $grade,
					'status'        => $status,
					'feedback'      => $feedback,
					'source'        => $grade_source,
					'rubric_scores' => $rubric_scores,
					'audit_payload' => array(
						'trigger'       => 'speedgrade',
						'evaluation_mode' => 'accept_ai_draft' === $submit_action ? 'ai_assisted' : 'manual',
						'activity_type' => 'tarea',
					),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} else {
			update_post_meta( $submission_id, '_clms_submission_status', $status );
			update_post_meta( $submission_id, '_clms_submission_feedback', $feedback );
			if ( ! empty( $rubric_scores ) ) {
				update_post_meta( $submission_id, '_clms_submission_rubric_scores', $rubric_scores );
			} else {
				delete_post_meta( $submission_id, '_clms_submission_rubric_scores' );
			}
			if ( '' !== $grade ) {
				update_post_meta( $submission_id, '_clms_submission_grade', $grade );
			} else {
				delete_post_meta( $submission_id, '_clms_submission_grade' );
			}
			$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
			if ( ! $student_id ) {
				$student_id = absint( get_post_meta( $submission_id, '_clms_submission_student_id', true ) );
			}
			if ( ! $student_id ) {
				$student_id = absint( get_post_field( 'post_author', $submission_id ) );
			}

			$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
			if ( ! $course_id && $lesson_id && class_exists( 'CLMS_Helper' ) ) {
				$course_id = absint( CLMS_Helper::get_lesson_course_id( $lesson_id ) );
			}
			if ( $student_id && $course_id && method_exists( $this, 'calculate_and_store_course_grade' ) ) {
				$this->calculate_and_store_course_grade( $student_id, $course_id );
			}

			$grading_engine = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading_Engine') : null;
			if ( $grading_engine && method_exists( $grading_engine, 'invalidate_grade_cache' ) && $student_id && $course_id ) {
				$grading_engine->invalidate_grade_cache( $student_id, $course_id );
			}

			do_action( 'clms_submission_graded', $submission_id, $student_id > 0 ? $student_id : $user_id, $status, $grade, $feedback );
		}

		return array(
			'submission_id' => $submission_id,
			'status'        => $status,
			'grade'         => $grade,
			'feedback'      => $feedback,
		);
	}

	public function current_user_can_grade_submission( $submission_id, $user_id = 0 ) {
		$submission_id = absint( $submission_id );
		$user_id       = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $submission_id || ! $user_id ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );

		if ( ! $lesson_id && ! $course_id ) {
			return false;
		}

		if ( $course_id && CLMS_Helper::user_can_manage_lms( $course_id ) ) {
			return true;
		}

		return $lesson_id && CLMS_Helper::user_can_manage_lms( $lesson_id );
	}

	public function get_submission_context( $submission_id, $user_id = 0 ) {
		$submission_id = absint( $submission_id );
		$user_id       = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $submission_id || ! $this->current_user_can_grade_submission( $submission_id, $user_id ) ) {
			return array();
		}

		$student_id  = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		if ( ! $student_id && '1' === (string) get_post_meta( $submission_id, '_clms_submission_group_master', true ) ) {
			$student_id = absint( get_post_meta( $submission_id, '_clms_submission_submitted_by', true ) );
		}
		$lesson_id   = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$course_id   = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		$status      = (string) get_post_meta( $submission_id, '_clms_submission_status', true );
		$grade       = get_post_meta( $submission_id, '_clms_submission_grade', true );
		if ( '' === (string) $grade ) {
			$grade = get_post_meta( $submission_id, '_clms_final_grade', true );
		}
		$feedback    = (string) get_post_meta( $submission_id, '_clms_submission_feedback', true );
		$comment     = (string) get_post_meta( $submission_id, '_clms_submission_comment', true );
		$lesson_comment = $this->get_student_lesson_feedback_comment( $student_id, $lesson_id, $submission_id );
		$submitted   = (string) get_post_meta( $submission_id, '_clms_submission_submitted_at', true );
		$attachments = get_post_meta( $submission_id, '_clms_submission_attachments', true );
		$assessment_engine = clms_core('CLMS_Assessment_Engine');
		$assessment_record = ( $assessment_engine && method_exists( $assessment_engine, 'get_submission_grade_record' ) ) ? $assessment_engine->get_submission_grade_record( $submission_id ) : array();
		$assessment_log    = ( $assessment_engine && method_exists( $assessment_engine, 'get_submission_audit_log' ) ) ? $assessment_engine->get_submission_audit_log( $submission_id ) : array();
		$evaluation_settings = ( $assessment_engine && method_exists( $assessment_engine, 'get_lesson_evaluation_settings' ) ) ? $assessment_engine->get_lesson_evaluation_settings( $lesson_id ) : array();

		if ( ! is_array( $attachments ) || empty( $attachments ) ) {
			$attachments = get_post_meta( $submission_id, '_clms_submission_files', true );
		}

		$attachments = is_array( $attachments ) ? array_values( array_filter( array_map( 'absint', $attachments ) ) ) : array();

		$student = $student_id ? get_user_by( 'id', $student_id ) : false;
		$files   = array();

		foreach ( $attachments as $file_id ) {
			$url = wp_get_attachment_url( $file_id );

			if ( ! $url ) {
				continue;
			}

			$path  = get_attached_file( $file_id );
			$label = $path ? basename( (string) $path ) : 'Archivo ' . $file_id;
			$meta  = array();

			$size = $path && file_exists( $path ) ? filesize( $path ) : 0;
			if ( $size ) {
				$meta[] = size_format( $size );
			}

			$type = get_post_mime_type( $file_id );
			if ( $type ) {
				$meta[] = $type;
			}

			$files[] = array(
				'id'    => $file_id,
				'label' => $label,
				'url'   => $url,
				'meta'  => implode( ' · ', $meta ),
			);
		}

		if ( '' === $submitted ) {
			$submitted = get_post_time( 'Y-m-d H:i:s', false, $submission_id );
		}

		$student_name = '';
		if ( $student ) {
			$student_name = $student->display_name ? $student->display_name : $student->user_login;
		}

		$student_snapshot = $this->build_student_course_snapshot( $student_id, $course_id );
		$recent_history   = $this->get_student_recent_submission_history( $student_id, $course_id, $lesson_id, $submission_id, 5 );
		$ai_pending       = $this->is_submission_ai_pending_validation( $submission_id, $status, $assessment_record );
		$rubric_id        = $lesson_id ? absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) ) : 0;
		$rubric_snapshot  = $rubric_id ? get_post_meta( $submission_id, '_clms_submission_rubric_snapshot', true ) : array();
		$rubric_snapshot  = is_array( $rubric_snapshot ) ? $rubric_snapshot : array();
		$context = array(
			'submission_id' => $submission_id,
			'student_id'    => $student_id,
			'student_name'  => $student_name,
			'lesson_id'     => $lesson_id,
			'lesson_title'  => $lesson_id ? get_the_title( $lesson_id ) : '',
			'course_id'     => $course_id,
			'course_title'  => $course_id ? get_the_title( $course_id ) : '',
			'status'        => $status ? $status : 'submitted',
			'status_label'  => $this->get_submission_status_label( $status ),
			'grade'         => '' !== (string) $grade ? absint( $grade ) : '',
			'feedback'      => $feedback,
			'comment'       => $comment,
			'lesson_comment' => $lesson_comment,
			'files'         => $files,
			'submitted_at'  => $this->format_datetime( $submitted ),
			'rubric_id'     => $rubric_id,
			'rubric_title'  => $rubric_id ? get_the_title( $rubric_id ) : '',
			'rubric_snapshot' => $rubric_snapshot,
			'rubric_scores' => $this->get_rubric_scores( $submission_id ),
			'assessment'    => is_array( $assessment_record ) ? $assessment_record : array(),
			'assessment_log' => is_array( $assessment_log ) ? $assessment_log : array(),
			'evaluation_settings' => is_array( $evaluation_settings ) ? $evaluation_settings : array(),
			'student_snapshot' => $student_snapshot,
			'recent_history' => $recent_history,
			'ai_pending_validation' => $ai_pending,
		);

		$context_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Speedgrade_Context_Service') : null;
		if ( $context_service && method_exists( $context_service, 'enrich_submission_context' ) ) {
			$context = (array) $context_service->enrich_submission_context( $context );
		}

		return $context;
	}

	protected function get_rubric_scores( $submission_id ) {
		$raw = get_post_meta( absint( $submission_id ), '_clms_submission_rubric_scores', true );
		return is_array( $raw ) ? $raw : array();
	}

	protected function get_student_lesson_feedback_comment( $student_id, $lesson_id, $submission_id = 0 ) {
		$student_id    = absint( $student_id );
		$lesson_id     = absint( $lesson_id );
		$submission_id = absint( $submission_id );

		if ( ! $student_id || ! $lesson_id ) {
			return '';
		}

		if ( $submission_id ) {
			$submission_comment = sanitize_textarea_field( (string) get_post_meta( $submission_id, '_clms_student_class_comment', true ) );
			if ( '' !== $submission_comment ) {
				return $submission_comment;
			}
		}

		if ( class_exists( 'CLMS_Progress' ) && method_exists( 'CLMS_Progress', 'get_student_lesson_feedback_record' ) ) {
			$record = (array) CLMS_Progress::get_student_lesson_feedback_record( $student_id, $lesson_id );
			return isset( $record['comment'] ) ? sanitize_textarea_field( (string) $record['comment'] ) : '';
		}

		$log   = get_user_meta( $student_id, '_clms_lesson_feedback_log', true );
		$log   = is_array( $log ) ? $log : array();
		$entry = isset( $log[ $lesson_id ] ) && is_array( $log[ $lesson_id ] ) ? $log[ $lesson_id ] : array();
		return isset( $entry['comment'] ) ? sanitize_textarea_field( (string) $entry['comment'] ) : '';
	}

	protected function build_student_course_snapshot( $student_id, $course_id ) {
		$student_id = absint( $student_id );
		$course_id  = absint( $course_id );

		if ( ! $student_id || ! $course_id ) {
			return array(
				'progress_percent' => 0,
				'completed_lessons' => 0,
				'total_lessons' => 0,
				'last_activity' => '',
			);
		}

		$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );
		$lesson_ids = is_array( $lesson_ids ) ? array_values( array_filter( array_map( 'absint', $lesson_ids ) ) ) : array();
		$total      = count( $lesson_ids );

		$completed  = get_user_meta( $student_id, '_clms_completed_lessons', true );
		$completed  = is_array( $completed ) ? array_values( array_filter( array_map( 'absint', $completed ) ) ) : array();
		$done       = $total > 0 ? count( array_intersect( $lesson_ids, $completed ) ) : 0;
		$progress   = $total > 0 ? (int) round( ( $done / $total ) * 100 ) : 0;

		$recent_submission = get_posts(
			array(
				'post_type'      => self::SUBMISSION_CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $student_id,
					),
					array(
						'key'     => '_clms_submission_lesson_id',
						'value'   => $lesson_ids,
						'compare' => 'IN',
					),
				),
				'no_found_rows'  => true,
			)
		);

		$last_activity = '';
		if ( ! empty( $recent_submission[0] ) ) {
			$last_activity = $this->format_datetime( get_post_field( 'post_modified', absint( $recent_submission[0] ) ) );
		}

		return array(
			'progress_percent'   => $progress,
			'completed_lessons'  => $done,
			'total_lessons'      => $total,
			'last_activity'      => $last_activity,
		);
	}

	protected function get_student_recent_submission_history( $student_id, $course_id, $current_lesson_id, $current_submission_id, $limit = 5 ) {
		$student_id          = absint( $student_id );
		$course_id           = absint( $course_id );
		$current_lesson_id   = absint( $current_lesson_id );
		$current_submission_id = absint( $current_submission_id );
		$limit               = max( 1, absint( $limit ) );

		if ( ! $student_id || ! $course_id ) {
			return array();
		}

		$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );
		$lesson_ids = is_array( $lesson_ids ) ? array_values( array_filter( array_map( 'absint', $lesson_ids ) ) ) : array();

		if ( empty( $lesson_ids ) ) {
			return array();
		}

		$submission_ids = get_posts(
			array(
				'post_type'      => self::SUBMISSION_CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => min( 25, $limit + 5 ),
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $student_id,
					),
					array(
						'key'     => '_clms_submission_lesson_id',
						'value'   => $lesson_ids,
						'compare' => 'IN',
					),
				),
				'no_found_rows'  => true,
			)
		);

		$history = array();
		foreach ( (array) $submission_ids as $submission_id ) {
			$submission_id = absint( $submission_id );

			if ( ! $submission_id || $submission_id === $current_submission_id ) {
				continue;
			}

			$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$status    = (string) get_post_meta( $submission_id, '_clms_submission_status', true );
			$grade     = get_post_meta( $submission_id, '_clms_submission_grade', true );
			$is_same   = ( $lesson_id && $lesson_id === $current_lesson_id );

			$history[] = array(
				'submission_id' => $submission_id,
				'lesson_title'  => $lesson_id ? get_the_title( $lesson_id ) : __( 'Sin lección', 'atora-lms' ),
				'is_same_activity' => $is_same,
				'status'        => sanitize_key( $status ? $status : 'submitted' ),
				'status_label'  => $this->get_submission_status_label( $status ),
				'grade'         => '' !== (string) $grade ? absint( $grade ) : '',
				'updated_at'    => $this->format_datetime( get_post_field( 'post_modified', $submission_id ) ),
				'speedgrade_url'=> $this->get_speedgrade_url( $submission_id ),
			);

			if ( count( $history ) >= $limit ) {
				break;
			}
		}

		return $history;
	}

	protected function is_submission_ai_pending_validation( $submission_id, $status = '', $assessment_record = array() ) {
		$submission_id = absint( $submission_id );
		$status        = sanitize_key( (string) $status );
		$assessment_record = is_array( $assessment_record ) ? $assessment_record : array();

		if ( ! $submission_id ) {
			return false;
		}

		$source = isset( $assessment_record['grade_source'] ) ? sanitize_key( (string) $assessment_record['grade_source'] ) : '';
		$audit  = isset( $assessment_record['assessment_audit'] ) && is_array( $assessment_record['assessment_audit'] )
			? $assessment_record['assessment_audit']
			: array();

		if ( ! empty( $audit['review_required'] ) ) {
			return true;
		}

		if ( 'in_review' === $status && in_array( $source, array( 'ai_assisted', 'ai_auto_grade', 'hybrid' ), true ) ) {
			return true;
		}

		$ai_review_status = sanitize_key( (string) get_post_meta( $submission_id, '_clms_ai_review_status', true ) );
		return ( 'completed' === $ai_review_status && 'graded' !== $status );
	}

	protected function get_adjacent_submissions( $submission_id, $user_id ) {
		$submission_id = absint( $submission_id );
		$user_id       = absint( $user_id );

		$result = array(
			'prev' => 0,
			'next' => 0,
		);

		if ( ! $submission_id || ! $user_id ) {
			return $result;
		}

		$queue = $this->get_speedgrade_queue_for_user( $user_id );

		if ( empty( $queue ) ) {
			return $result;
		}

		$index = array_search( $submission_id, $queue, true );

		if ( false === $index ) {
			return $result;
		}

		if ( isset( $queue[ $index - 1 ] ) ) {
			$result['prev'] = absint( $queue[ $index - 1 ] );
		}

		if ( isset( $queue[ $index + 1 ] ) ) {
			$result['next'] = absint( $queue[ $index + 1 ] );
		}

		return $result;
	}

	protected function get_speedgrade_queue_for_user( $user_id ) {
		$user_id    = absint( $user_id );
		$lesson_ids = $this->get_teacher_lesson_ids( $user_id );

		if ( empty( $lesson_ids ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => self::SUBMISSION_CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 250,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'     => '_clms_submission_lesson_id',
						'value'   => $lesson_ids,
						'compare' => 'IN',
					),
				),
			)
		);

		$items = array();
		$assessment_engine = clms_core('CLMS_Assessment_Engine');
		foreach ( (array) $posts as $submission_id ) {
			$submission_id = absint( $submission_id );
			if ( ! $submission_id ) {
				continue;
			}

			// Group Assessment: hide shadow submissions to prevent double grading.
			if ( '1' === (string) get_post_meta( $submission_id, '_clms_submission_is_shadow', true ) ) {
				continue;
			}

			$lesson_id  = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$course_id  = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
			$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
			$status     = sanitize_key( (string) get_post_meta( $submission_id, '_clms_submission_status', true ) );

			if ( ! $lesson_id || ! in_array( $lesson_id, $lesson_ids, true ) ) {
				continue;
			}

			$evaluation_mode = sanitize_key( (string) get_post_meta( $lesson_id, '_clms_evaluation_mode', true ) );
			if ( 'group' === $evaluation_mode ) {
				// Only grade the master submission for a group lesson.
				if ( '1' !== (string) get_post_meta( $submission_id, '_clms_submission_group_master', true ) ) {
					continue;
				}
				// Use the submitter as a stable display user for SpeedGrade UI.
				if ( ! $student_id ) {
					$student_id = absint( get_post_meta( $submission_id, '_clms_submission_submitted_by', true ) );
				}
			}

			if ( ! $course_id ) {
				$course_id = CLMS_Helper::get_course_id_from_lesson( $lesson_id );
			}

			$submitted_ts = strtotime( (string) get_post_field( 'post_date_gmt', $submission_id ) );
			$updated_ts   = strtotime( (string) get_post_field( 'post_modified_gmt', $submission_id ) );
			$submitted_ts = $submitted_ts ? absint( $submitted_ts ) : 0;
			$updated_ts   = $updated_ts ? absint( $updated_ts ) : 0;
			$due_ts       = $this->get_lesson_due_timestamp( $lesson_id );
			$assessment_record = ( $assessment_engine && method_exists( $assessment_engine, 'get_submission_grade_record' ) )
				? $assessment_engine->get_submission_grade_record( $submission_id )
				: array();
			$ai_pending = $this->is_submission_ai_pending_validation( $submission_id, $status, $assessment_record );
			$at_risk    = $this->is_student_at_risk_for_speedgrade( $student_id, $course_id );
			$is_returned = in_array( $status, array( 'needs_revision', 'returned' ), true );
			$affects_certificate = $this->submission_impacts_certificate( $student_id, $course_id );

			$priority_rank  = 7;
			$priority_label = __( 'Seguimiento', 'atora-lms' );
			$priority_class = 'is-info';

			if ( $due_ts > 0 && current_time( 'timestamp' ) > $due_ts && 'graded' !== $status ) {
				$priority_rank  = 0;
				$priority_label = __( 'Vencida', 'atora-lms' );
				$priority_class = 'is-urgent';
			} elseif ( 'submitted' === $status && $submitted_ts > 0 && ( current_time( 'timestamp' ) - $submitted_ts ) <= DAY_IN_SECONDS ) {
				$priority_rank  = 1;
				$priority_label = __( 'Nueva', 'atora-lms' );
				$priority_class = 'is-warning';
			} elseif ( $at_risk && 'graded' !== $status ) {
				$priority_rank  = 2;
				$priority_label = __( 'En riesgo', 'atora-lms' );
				$priority_class = 'is-warning';
			} elseif ( $ai_pending ) {
				$priority_rank  = 3;
				$priority_label = __( 'IA por validar', 'atora-lms' );
				$priority_class = 'is-info';
			} elseif ( $is_returned ) {
				$priority_rank  = 4;
				$priority_label = __( 'Devuelta para mejora', 'atora-lms' );
				$priority_class = 'is-warning';
			} elseif ( $affects_certificate ) {
				$priority_rank  = 5;
				$priority_label = __( 'Impacta certificado', 'atora-lms' );
				$priority_class = 'is-info';
			} elseif ( 'graded' === $status && $updated_ts > 0 && ( current_time( 'timestamp' ) - $updated_ts ) <= ( 3 * DAY_IN_SECONDS ) ) {
				$priority_rank  = 6;
				$priority_label = __( 'Revisada recientemente', 'atora-lms' );
				$priority_class = 'is-success';
			}

			if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
				$priority_meta = (array) CLMS_Helper::modular_apply(
					'speedgrade_priority_meta',
					array(
						'priority_rank'  => $priority_rank,
						'priority_label' => $priority_label,
						'priority_class' => $priority_class,
					),
					$submission_id,
					$student_id,
					$course_id
				);
				$priority_rank  = isset( $priority_meta['priority_rank'] ) ? absint( $priority_meta['priority_rank'] ) : $priority_rank;
				$priority_label = isset( $priority_meta['priority_label'] ) ? sanitize_text_field( (string) $priority_meta['priority_label'] ) : $priority_label;
				$priority_class = isset( $priority_meta['priority_class'] ) ? sanitize_html_class( (string) $priority_meta['priority_class'] ) : $priority_class;
			}

			$items[] = array(
				'id'               => $submission_id,
				'priority_rank'    => $priority_rank,
				'submitted_ts'     => $submitted_ts,
				'priority_label'   => $priority_label,
				'priority_class'   => $priority_class,
				'ai_pending'       => $ai_pending,
			);
		}

		usort(
			$items,
			static function( $a, $b ) {
				$a_rank = isset( $a['priority_rank'] ) ? absint( $a['priority_rank'] ) : 5;
				$b_rank = isset( $b['priority_rank'] ) ? absint( $b['priority_rank'] ) : 5;
				if ( $a_rank === $b_rank ) {
					$a_time = isset( $a['submitted_ts'] ) ? absint( $a['submitted_ts'] ) : 0;
					$b_time = isset( $b['submitted_ts'] ) ? absint( $b['submitted_ts'] ) : 0;
					return $b_time <=> $a_time;
				}
				return $a_rank <=> $b_rank;
			}
		);

		$result = array_values(
			array_map(
				static function( $item ) {
					return absint( $item['id'] ?? 0 );
				},
				$items
			)
		);
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$result = (array) CLMS_Helper::modular_apply( 'speedgrade_queue_ids', $result, $user_id, $lesson_ids );
		}

		return $result;
	}

	protected function get_submission_queue( $submission_id, $user_id ) {
		$submission_id = absint( $submission_id );
		$user_id       = absint( $user_id );
		$queue_ids     = $this->get_speedgrade_queue_for_user( $user_id );

		if ( empty( $queue_ids ) ) {
			return array();
		}

		$queue_items = array();
		foreach ( $queue_ids as $queue_id ) {
			$queue_id = absint( $queue_id );
			if ( ! $queue_id ) {
				continue;
			}

			$student_id   = absint( get_post_meta( $queue_id, '_clms_submission_user_id', true ) );
			$student      = $student_id ? get_user_by( 'id', $student_id ) : false;
			$lesson_id    = absint( get_post_meta( $queue_id, '_clms_submission_lesson_id', true ) );
			$status       = (string) get_post_meta( $queue_id, '_clms_submission_status', true );
			$assessment_engine = clms_core('CLMS_Assessment_Engine');
			$assessment_record = ( $assessment_engine && method_exists( $assessment_engine, 'get_submission_grade_record' ) )
				? $assessment_engine->get_submission_grade_record( $queue_id )
				: array();
			$ai_pending   = $this->is_submission_ai_pending_validation( $queue_id, $status, $assessment_record );
			$priority     = $this->get_submission_queue_priority_data( $queue_id, $status, $assessment_record );
			$student_name = $student && $student->display_name ? $student->display_name : ( $student ? $student->user_login : __( 'Estudiante', 'atora-lms' ) );

			if ( '' === $status ) {
				$status = 'submitted';
			}

			$queue_items[] = array(
				'id'           => $queue_id,
				'student_name' => $student_name,
				'lesson_title' => $lesson_id ? get_the_title( $lesson_id ) : __( 'Sin lección', 'atora-lms' ),
				'status'       => sanitize_key( $status ),
				'status_label' => $this->get_submission_status_label( $status ),
				'priority_label' => $priority['label'],
				'priority_class' => $priority['class'],
				'ai_pending'   => $ai_pending,
				'is_current'   => $queue_id === $submission_id,
			);
		}

		return $queue_items;
	}

	protected function get_submission_queue_priority_data( $submission_id, $status = '', $assessment_record = array() ) {
		$submission_id = absint( $submission_id );
		$status        = sanitize_key( (string) $status );
		$assessment_record = is_array( $assessment_record ) ? $assessment_record : array();

		$lesson_id  = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$course_id  = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		$due_ts     = $this->get_lesson_due_timestamp( $lesson_id );
		$submitted_ts = strtotime( (string) get_post_field( 'post_date_gmt', $submission_id ) );
		$submitted_ts = $submitted_ts ? absint( $submitted_ts ) : 0;
		$updated_ts   = strtotime( (string) get_post_field( 'post_modified_gmt', $submission_id ) );
		$updated_ts   = $updated_ts ? absint( $updated_ts ) : 0;
		$now = current_time( 'timestamp' );

		if ( $due_ts > 0 && $now > $due_ts && 'graded' !== $status ) {
			return array(
				'label' => __( 'Vencida', 'atora-lms' ),
				'class' => 'is-urgent',
			);
		}

		if ( 'submitted' === $status && $submitted_ts > 0 && ( $now - $submitted_ts ) <= DAY_IN_SECONDS ) {
			return array(
				'label' => __( 'Nueva', 'atora-lms' ),
				'class' => 'is-warning',
			);
		}

		if ( $this->is_student_at_risk_for_speedgrade( $student_id, $course_id ) && 'graded' !== $status ) {
			return array(
				'label' => __( 'En riesgo', 'atora-lms' ),
				'class' => 'is-warning',
			);
		}

		if ( $this->is_submission_ai_pending_validation( $submission_id, $status, $assessment_record ) ) {
			return array(
				'label' => __( 'IA por validar', 'atora-lms' ),
				'class' => 'is-info',
			);
		}

		if ( in_array( $status, array( 'needs_revision', 'returned' ), true ) ) {
			return array(
				'label' => __( 'Devuelta para mejora', 'atora-lms' ),
				'class' => 'is-warning',
			);
		}

		if ( $this->submission_impacts_certificate( $student_id, $course_id ) ) {
			return array(
				'label' => __( 'Impacta certificado', 'atora-lms' ),
				'class' => 'is-info',
			);
		}

		if ( 'graded' === $status && $updated_ts > 0 && ( $now - $updated_ts ) <= ( 3 * DAY_IN_SECONDS ) ) {
			return array(
				'label' => __( 'Revisada recientemente', 'atora-lms' ),
				'class' => 'is-success',
			);
		}

		return array(
			'label' => __( 'Seguimiento', 'atora-lms' ),
			'class' => 'is-info',
		);
	}

	protected function get_lesson_due_timestamp( $lesson_id ) {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id ) {
			return 0;
		}

		$due_date = CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_date', '_clms_due_date' ), '' );
		$due_time = CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_time', '_clms_due_time' ), '' );
		if ( '' === trim( (string) $due_date ) ) {
			return 0;
		}

		$raw = trim( (string) $due_date . ' ' . (string) $due_time );
		$ts  = strtotime( $raw );
		return $ts ? absint( $ts ) : 0;
	}

	protected function is_student_at_risk_for_speedgrade( $student_id, $course_id ) {
		$student_id = absint( $student_id );
		$course_id  = absint( $course_id );
		if ( ! $student_id || ! $course_id ) {
			return false;
		}

		$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );
		$lesson_ids = is_array( $lesson_ids ) ? array_values( array_filter( array_map( 'absint', $lesson_ids ) ) ) : array();
		if ( empty( $lesson_ids ) ) {
			return false;
		}

		$completed = get_user_meta( $student_id, '_clms_completed_lessons', true );
		$completed = is_array( $completed ) ? array_values( array_filter( array_map( 'absint', $completed ) ) ) : array();
		$done      = count( array_intersect( $lesson_ids, $completed ) );
		$progress  = (int) round( ( $done / max( 1, count( $lesson_ids ) ) ) * 100 );

		$recent = get_posts(
			array(
				'post_type'      => self::SUBMISSION_CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $student_id,
					),
					array(
						'key'     => '_clms_submission_lesson_id',
						'value'   => $lesson_ids,
						'compare' => 'IN',
					),
				),
				'no_found_rows'  => true,
			)
		);

		$inactive = true;
		if ( ! empty( $recent[0] ) ) {
			$updated_ts = strtotime( (string) get_post_field( 'post_modified_gmt', absint( $recent[0] ) ) );
			if ( $updated_ts ) {
				$inactive = ( current_time( 'timestamp' ) - absint( $updated_ts ) ) > ( 10 * DAY_IN_SECONDS );
			}
		}

		return ( $progress < 35 ) || $inactive;
	}

	/**
	 * Indica si la evaluación puede alterar estado de certificado.
	 *
	 * @param int $student_id Estudiante.
	 * @param int $course_id  Curso.
	 * @return bool
	 */
	protected function submission_impacts_certificate( $student_id, $course_id ) {
		$student_id = absint( $student_id );
		$course_id  = absint( $course_id );
		if ( ! $student_id || ! $course_id || ! class_exists( 'CLMS_Helper' ) ) {
			return false;
		}

		$certificates = clms_core('CLMS_Certificates');
		if ( ! $certificates || ! method_exists( $certificates, 'get_certificate_status_for_student_course' ) ) {
			return false;
		}

		$status = (array) $certificates->get_certificate_status_for_student_course( $student_id, $course_id );
		$key    = isset( $status['status'] ) ? sanitize_key( (string) $status['status'] ) : 'pending';

		return in_array( $key, array( 'pending', 'eligible' ), true );
	}

	protected function get_teacher_lesson_ids( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		if ( CLMS_Access::can_manage_lessons() ) {
			$all = get_posts(
				array(
					'post_type'      => 'lm_lesson',
					'post_status'    => array( 'publish', 'private', 'draft' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			return array_map( 'absint', (array) $all );
		}

		$lesson_ids = get_posts(
			array(
				'post_type'      => 'lm_lesson',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'author'         => $user_id,
			)
		);

		$courses = get_posts(
			array(
				'post_type'      => 'lm_course',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'author'         => $user_id,
			)
		);

		foreach ( (array) $courses as $course_id ) {
			$course_lessons = CLMS_Helper::get_course_lessons( $course_id );
			foreach ( (array) $course_lessons as $lesson_id ) {
				$lesson_ids[] = absint( $lesson_id );
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', (array) $lesson_ids ) ) ) );
	}

}
