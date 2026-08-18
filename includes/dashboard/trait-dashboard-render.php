<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Dashboard_Render_Trait {
	public function render_dashboard_shortcode( $atts ) {
		unset( $atts );

		$this->enqueue_assets();

		if ( ! is_user_logged_in() ) {
			ob_start();
			?>
			<div class="clms-sd">
				<div class="clms-sd__guest">
					<div class="clms-sd-card">
						<div class="clms-sd-card__body">
							<div class="clms-sd-eyebrow"><?php esc_html_e( 'Acceso requerido', 'atora-lms' ); ?></div>
							<h2 class="clms-sd-title"><?php esc_html_e( 'Debes iniciar sesión para ver tu panel', 'atora-lms' ); ?></h2>
							<p class="clms-sd-text"><?php esc_html_e( 'Inicia sesión para revisar tu avance, tus cursos activos y tus actividades pendientes.', 'atora-lms' ); ?></p>
						</div>
					</div>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		$user_id = get_current_user_id();
		$user    = wp_get_current_user();

		$schema              = $this->get_dashboard_ui_schema( $user_id );
		$schema_hash         = $this->get_dashboard_schema_hash( $schema );
		$course_cards_limit  = $this->get_dashboard_limit( $schema, 'course_cards', 3 );
		$pending_limit       = $this->get_dashboard_limit( $schema, 'pending_items', 6 );
		$feedback_limit      = $this->get_dashboard_limit( $schema, 'feedback_items', 4 );
		$notifications_limit = $this->get_dashboard_limit( $schema, 'notification_items', 5 );
		$lessons_limit       = $this->get_dashboard_limit( $schema, 'lesson_items', 5 );
		$alerts_limit        = $this->get_dashboard_limit( $schema, 'alert_items', 2 );
		$journey_limit       = $this->get_dashboard_limit( $schema, 'journey_items', 4 );
		$assistant_actions_limit = $this->get_dashboard_limit( $schema, 'assistant_actions', 5 );

		$payload        = $this->get_cached_payload( $user_id );
		$needs_refresh  = empty( $payload ) || ! isset( $payload['metrics'] );
		$needs_refresh  = $needs_refresh || empty( $payload['schema_hash'] ) || $payload['schema_hash'] !== $schema_hash;

		if ( $needs_refresh ) {
			$course_ids         = class_exists( 'CLMS_Helper' ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) \CLMS_Helper::get_user_enrolled_courses( $user_id ) ) ) ) ) : array();
			$academic_statuses  = $this->get_academic_status_map( $user_id, $course_ids );
			$continue_item      = $this->get_continue_item( $user_id, $course_ids );
			$feedback_items     = $this->get_recent_feedback_items( $user_id, $feedback_limit );
			$pending_items      = $this->get_pending_items( $user_id, $course_ids, $pending_limit );
			$notification_items = $this->get_recent_notifications( $user_id, $notifications_limit );
			$course_cards       = $this->get_course_cards( $user_id, $course_ids, $academic_statuses );
			$lesson_items       = $this->get_upcoming_lessons( $user_id, $course_ids, $lessons_limit );
			$metrics            = $this->get_metrics( $user_id, $course_ids, $feedback_items, $pending_items, $notification_items, $academic_statuses );
			$profile_snapshot   = $this->get_student_profile_snapshot( $user_id );
			$onboarding         = $this->get_onboarding_context( $user_id, $course_ids, $continue_item, $profile_snapshot );

			$payload = array(
				'course_ids'         => $course_ids,
				'academic_statuses'  => $academic_statuses,
				'continue_item'      => $continue_item,
				'feedback_items'     => $feedback_items,
				'pending_items'      => $pending_items,
				'notification_items' => $notification_items,
				'course_cards'       => $course_cards,
				'lesson_items'       => $lesson_items,
				'metrics'            => $metrics,
				'profile_snapshot'   => $profile_snapshot,
				'onboarding'         => $onboarding,
				'schema_hash'        => $schema_hash,
			);
			$this->set_cached_payload( $user_id, $payload );
		}

		$course_ids         = $payload['course_ids'];
		$academic_statuses  = isset( $payload['academic_statuses'] ) && is_array( $payload['academic_statuses'] ) ? $payload['academic_statuses'] : array();
		$continue_item      = $payload['continue_item'];
		$feedback_items     = $payload['feedback_items'];
		$pending_items      = $payload['pending_items'];
		$notification_items = $payload['notification_items'];
		$course_cards       = $payload['course_cards'];
		$lesson_items       = $payload['lesson_items'];
		$metrics            = $payload['metrics'];
		$profile_snapshot   = isset( $payload['profile_snapshot'] ) ? $payload['profile_snapshot'] : array();
		$onboarding         = isset( $payload['onboarding'] ) ? $payload['onboarding'] : array();

		$display_name = $user->display_name ? $user->display_name : $user->user_login;
		$next_step    = $this->get_next_step_item( $continue_item, $pending_items, $lesson_items, $user_id, $course_ids );
		$primary_cta  = $this->get_primary_action( $continue_item, $next_step, $course_ids );
		$alert_items  = $this->get_alert_items( $pending_items, $feedback_items, $notification_items, $alerts_limit );
		$alert_summary = $this->get_alert_summary( $metrics, $alert_items );
		$evaluation_overview = $this->get_recent_submission_overview( $user_id, $next_step );
		$memory_course_id = ! empty( $continue_item['course_id'] ) ? absint( $continue_item['course_id'] ) : ( ! empty( $course_ids[0] ) ? absint( $course_ids[0] ) : 0 );
		$memory_insights  = class_exists( 'CLMS_Student_Memory' )
			? CLMS_Student_Memory::get_memory_insights( $user_id, $memory_course_id )
			: array(
				'title' => __( 'Tu progreso personal', 'atora-lms' ),
				'focus_topics' => array(),
				'message' => __( 'Cuando avances en el curso, aquí verás patrones y recomendaciones útiles.', 'atora-lms' ),
				'last_seen' => '',
				'needs_attention' => false,
			);
		$journey_items = $this->build_journey_items( $continue_item, $lesson_items, $journey_limit );
		$learning_route = $this->build_learning_route_context( $continue_item, $next_step, $pending_items, $feedback_items, $course_cards, $lesson_items, $metrics, $memory_insights, $primary_cta );
		$feedback_plan = $this->build_feedback_improvement_plan( $feedback_items, $memory_insights );
		$assistant_context = $this->get_student_assistant_context( $learning_route['course_id'] ?? 0 );
		$active_status = $this->get_primary_academic_status(
			$academic_statuses,
			! empty( $learning_route['course_id'] ) ? absint( $learning_route['course_id'] ) : 0
		);
		$dashboard_data_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Student_Dashboard_Data') : null;
		$hero_state = ( $dashboard_data_service && method_exists( $dashboard_data_service, 'build_hero_state' ) )
			? (array) $dashboard_data_service->build_hero_state( $user_id, $active_status )
			: array();
		$gamification_panel = $this->build_gamification_panel( $user_id, $active_status );
		$certificate_panel  = $this->build_certificate_panel( $active_status );
		$student_dashboard_payload = ( $dashboard_data_service && method_exists( $dashboard_data_service, 'build_dashboard_payload' ) )
			? (array) $dashboard_data_service->build_dashboard_payload(
				$user_id,
				array(
					'active_status'     => $active_status,
					'next_step'         => $next_step,
					'learning_route'    => $learning_route,
					'feedback_plan'     => $feedback_plan,
					'gamification_panel'=> $gamification_panel,
					'certificate_panel' => $certificate_panel,
					'course_cards'      => $course_cards,
					'alert_items'       => $alert_items,
					'assistant_context' => $assistant_context,
				)
			)
			: array();
		$assistant_actions = isset( $student_dashboard_payload['assistant_actions'] ) && is_array( $student_dashboard_payload['assistant_actions'] )
			? $student_dashboard_payload['assistant_actions']
			: array();
		$pending_due_count = 0;
		foreach ( $pending_items as $pending_item ) {
			if ( ! empty( $pending_item['due_date'] ) ) {
				++$pending_due_count;
			}
		}
		$incident_cards = array(
			array(
				'label'  => __( 'Pendientes', 'atora-lms' ),
				'value'  => absint( $metrics['pending'] ?? 0 ),
				'status' => ( ! empty( $metrics['pending'] ) ) ? 'warning' : 'success',
				'note'   => __( 'Actividades sin completar', 'atora-lms' ),
			),
			array(
				'label'  => __( 'Con fecha límite', 'atora-lms' ),
				'value'  => absint( $pending_due_count ),
				'status' => $pending_due_count > 0 ? 'warning' : 'success',
				'note'   => __( 'Pendientes con vencimiento', 'atora-lms' ),
			),
			array(
				'label'  => __( 'Feedback', 'atora-lms' ),
				'value'  => absint( $metrics['feedback'] ?? 0 ),
				'status' => ( ! empty( $metrics['feedback'] ) ) ? 'warning' : 'success',
				'note'   => __( 'Comentarios por revisar', 'atora-lms' ),
			),
			array(
				'label'  => __( 'No leídos', 'atora-lms' ),
				'value'  => absint( $metrics['unread'] ?? 0 ),
				'status' => ( ! empty( $metrics['unread'] ) ) ? 'warning' : 'success',
				'note'   => __( 'Avisos pendientes', 'atora-lms' ),
			),
		);

		ob_start();
		$sections_output = array();

		ob_start();
		?>
			<section class="clms-sd-hero">
				<div class="clms-sd-hero__copy">
					<div class="clms-sd-pill"><?php esc_html_e( 'Tu aprendizaje hoy', 'atora-lms' ); ?></div>
					<h1 class="clms-sd-hero__title"><?php esc_html_e( 'Continúa donde lo dejaste', 'atora-lms' ); ?></h1>
					<?php if ( ! empty( $hero_state['status_label'] ) ) : ?>
						<p class="clms-sd-hero__meta"><?php echo esc_html( $hero_state['status_label'] ); ?></p>
					<?php endif; ?>
					<p class="clms-sd-hero__text">
						<?php
						echo esc_html(
							$continue_item
								? __( 'Retoma tu siguiente lección con un solo paso.', 'atora-lms' )
								: __( 'Cuando tengas lecciones activas, las verás aquí para continuar sin fricciones.', 'atora-lms' )
						);
						?>
					</p>
					<?php if ( ! empty( $continue_item['lesson_title'] ) ) : ?>
						<p class="clms-sd-hero__meta"><?php echo esc_html( $continue_item['course_title'] . ' · ' . $continue_item['lesson_title'] ); ?></p>
					<?php endif; ?>
						<div class="clms-sd-hero__actions">
							<?php if ( ! empty( $primary_cta['url'] ) ) : ?>
								<a class="clms-sd-btn clms-sd-btn--primary" href="<?php echo esc_url( $primary_cta['url'] ); ?>">
									<?php echo esc_html( $primary_cta['label'] ); ?>
								</a>
							<?php endif; ?>
						</div>
				</div>

				<div class="clms-sd-hero__stats">
					<div class="clms-sd-stat clms-sd-stat--hero">
						<div class="clms-sd-stat__label"><?php esc_html_e( 'Progreso global', 'atora-lms' ); ?></div>
						<div class="clms-sd-stat__value"><?php echo esc_html( $metrics['progress'] ); ?>%</div>
						<div class="clms-sd-progress" aria-hidden="true">
							<span style="width: <?php echo esc_attr( min( 100, max( 0, (int) $metrics['progress'] ) ) ); ?>%;"></span>
						</div>
					</div>
					<div class="clms-sd-stat clms-sd-stat--hero">
						<div class="clms-sd-stat__label"><?php esc_html_e( 'Promedio actual', 'atora-lms' ); ?></div>
						<div class="clms-sd-stat__value"><?php echo esc_html( $metrics['average'] ); ?>%</div>
						<div class="clms-sd-progress" aria-hidden="true">
							<span style="width: <?php echo esc_attr( min( 100, max( 0, (int) $metrics['average'] ) ) ); ?>%;"></span>
						</div>
					</div>
				</div>
			</section>
		<?php
		$sections_output['hero'] = ob_get_clean();

		if ( ! empty( $onboarding ) ) {
			ob_start();
			?>
				<section class="clms-sd-card clms-sd-card--onboarding">
					<div class="clms-sd-section-head">
						<div>
							<div class="clms-sd-eyebrow"><?php esc_html_e( 'Bienvenida', 'atora-lms' ); ?></div>
							<h2 class="clms-sd-title"><?php esc_html_e( 'Tus primeros pasos', 'atora-lms' ); ?></h2>
						</div>
						<?php if ( ! empty( $onboarding['course_title'] ) ) : ?>
							<span class="clms-sd-badge is-info"><?php echo esc_html( $onboarding['course_title'] ); ?></span>
						<?php endif; ?>
					</div>

					<?php if ( ! empty( $onboarding['message'] ) ) : ?>
						<p class="clms-sd-text"><?php echo esc_html( $onboarding['message'] ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $onboarding['items'] ) ) : ?>
						<div class="clms-sd-onboard-list">
							<?php foreach ( $onboarding['items'] as $item ) : ?>
								<div class="clms-sd-onboard-item <?php echo ! empty( $item['done'] ) ? 'is-done' : 'is-pending'; ?>">
									<span class="clms-sd-onboard-dot" aria-hidden="true">
										<?php echo ! empty( $item['done'] ) ? '✓' : '•'; ?>
									</span>
									<div class="clms-sd-onboard-content">
										<p class="clms-sd-onboard-title"><?php echo esc_html( $item['title'] ); ?></p>
										<?php if ( ! empty( $item['meta'] ) ) : ?>
											<p class="clms-sd-mini__meta"><?php echo esc_html( $item['meta'] ); ?></p>
										<?php endif; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $onboarding['primary']['url'] ) ) : ?>
						<div class="clms-sd-card__actions">
							<a class="clms-sd-btn clms-sd-btn--primary" href="<?php echo esc_url( $onboarding['primary']['url'] ); ?>">
								<?php echo esc_html( $onboarding['primary']['label'] ); ?>
							</a>
							<?php if ( ! empty( $onboarding['secondary']['url'] ) ) : ?>
								<a class="clms-sd-btn clms-sd-btn--secondary" href="<?php echo esc_url( $onboarding['secondary']['url'] ); ?>">
									<?php echo esc_html( $onboarding['secondary']['label'] ); ?>
								</a>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</section>
			<?php
			$sections_output['onboarding'] = ob_get_clean();
		}

		ob_start();
		?>
			<section class="clms-sd-card clms-sd-card--next">
				<div class="clms-sd-section-head">
					<div>
						<div class="clms-sd-eyebrow"><?php esc_html_e( 'Tu siguiente paso', 'atora-lms' ); ?></div>
						<h2 class="clms-sd-title"><?php esc_html_e( 'Lo más importante hoy', 'atora-lms' ); ?></h2>
					</div>
				</div>

				<?php if ( empty( $next_step ) ) : ?>
					<p class="clms-sd-text clms-sd-text--muted"><?php esc_html_e( 'No hay pasos urgentes. Cuando tengas pendientes, aparecerán aquí.', 'atora-lms' ); ?></p>
				<?php else : ?>
						<div class="clms-sd-step">
							<div>
								<h3 class="clms-sd-step__title"><?php echo esc_html( $next_step['title'] ); ?></h3>
								<p class="clms-sd-step__meta"><?php echo esc_html( $next_step['meta'] ); ?></p>
								<p class="clms-sd-text"><?php echo esc_html( $next_step['description'] ); ?></p>
							</div>
							<?php if ( ! empty( $next_step['url'] ) && ( empty( $primary_cta['url'] ) || $primary_cta['url'] !== $next_step['url'] ) ) : ?>
								<a class="clms-sd-btn clms-sd-btn--secondary" href="<?php echo esc_url( $next_step['url'] ); ?>">
									<?php echo esc_html( ! empty( $next_step['button_label'] ) ? $next_step['button_label'] : __( 'Ir al paso', 'atora-lms' ) ); ?>
								</a>
							<?php endif; ?>
						</div>
				<?php endif; ?>
			</section>
		<?php
		$sections_output['next_step'] = ob_get_clean();

		ob_start();
		?>
			<section class="clms-sd-card clms-sd-card--journey" id="clms-sd-journey">
				<div class="clms-sd-section-head">
					<div>
						<div class="clms-sd-eyebrow"><?php esc_html_e( 'Mi ruta de aprendizaje', 'atora-lms' ); ?></div>
						<h2 class="clms-sd-title"><?php esc_html_e( 'Qué hacer ahora para avanzar', 'atora-lms' ); ?></h2>
					</div>
					<span class="clms-sd-badge <?php echo esc_attr( $learning_route['state_class'] ?? 'is-muted' ); ?>">
						<?php echo esc_html( $learning_route['state_label'] ?? __( 'Pendiente', 'atora-lms' ) ); ?>
					</span>
				</div>

				<div class="clms-sd-route-grid">
					<div class="clms-sd-route-main">
						<?php if ( ! empty( $learning_route['primary']['url'] ) ) : ?>
							<a class="clms-sd-btn clms-sd-btn--primary clms-sd-btn--wide" href="<?php echo esc_url( $learning_route['primary']['url'] ); ?>">
								<?php echo esc_html( $learning_route['primary']['label'] ); ?>
							</a>
						<?php endif; ?>

						<?php if ( ! empty( $learning_route['course_title'] ) ) : ?>
							<p class="clms-sd-route-course"><?php echo esc_html( $learning_route['course_title'] ); ?></p>
						<?php endif; ?>

						<div class="clms-sd-journey">
							<?php foreach ( (array) $learning_route['items'] as $item ) : ?>
								<div class="clms-sd-journey-item">
									<span class="clms-sd-journey-dot" aria-hidden="true"></span>
									<div class="clms-sd-journey-content">
										<p class="clms-sd-journey-title"><?php echo esc_html( $item['title'] ); ?></p>
										<p class="clms-sd-journey-meta"><?php echo esc_html( $item['meta'] ); ?></p>
									</div>
									<div class="clms-sd-journey-actions">
										<span class="clms-sd-badge <?php echo esc_attr( $item['badge_class'] ); ?>"><?php echo esc_html( $item['label'] ); ?></span>
										<?php if ( ! empty( $item['url'] ) ) : ?>
											<a class="clms-sd-link" href="<?php echo esc_url( $item['url'] ); ?>"><?php esc_html_e( 'Abrir', 'atora-lms' ); ?></a>
										<?php endif; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="clms-sd-route-aside">
						<div class="clms-sd-route-progress">
							<div class="clms-sd-inline-meta"><?php esc_html_e( 'Avance general', 'atora-lms' ); ?></div>
							<strong><?php echo esc_html( absint( $learning_route['progress'] ) ); ?>%</strong>
							<div class="clms-sd-progress" aria-hidden="true">
								<span style="width: <?php echo esc_attr( min( 100, max( 0, (int) $learning_route['progress'] ) ) ); ?>%;"></span>
							</div>
						</div>
						<p class="clms-sd-text clms-sd-text--sm">
							<?php
							printf(
								esc_html__( 'Pendientes: %d', 'atora-lms' ),
								absint( $learning_route['pending_count'] )
							);
							?>
						</p>
						<?php if ( ! empty( $learning_route['last_feedback'] ) ) : ?>
							<div class="clms-sd-route-note">
								<div class="clms-sd-inline-meta"><?php esc_html_e( 'Último feedback', 'atora-lms' ); ?></div>
								<p class="clms-sd-text clms-sd-text--sm"><?php echo esc_html( $learning_route['last_feedback'] ); ?></p>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $learning_route['improvement_tip'] ) ) : ?>
							<div class="clms-sd-route-note">
								<div class="clms-sd-inline-meta"><?php esc_html_e( 'Recomendación de mejora', 'atora-lms' ); ?></div>
								<p class="clms-sd-text clms-sd-text--sm"><?php echo esc_html( $learning_route['improvement_tip'] ); ?></p>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $learning_route['resource'] ) && ! empty( $learning_route['resource']['url'] ) ) : ?>
							<a class="clms-sd-btn clms-sd-btn--secondary clms-sd-btn--wide" href="<?php echo esc_url( $learning_route['resource']['url'] ); ?>">
								<?php echo esc_html( $learning_route['resource']['label'] ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			</section>
		<?php
		$sections_output['journey'] = ob_get_clean();

		ob_start();
		?>
			<section class="clms-sd-card" id="clms-sd-courses">
				<div class="clms-sd-section-head">
					<div>
						<div class="clms-sd-eyebrow"><?php esc_html_e( 'Progreso por módulos', 'atora-lms' ); ?></div>
						<h2 class="clms-sd-title"><?php esc_html_e( 'Cómo vas en tus cursos', 'atora-lms' ); ?></h2>
					</div>
				</div>

				<?php if ( empty( $course_cards ) ) : ?>
					<div class="atora-empty-state">
						<svg class="atora-empty-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>
						<p class="atora-empty-state__title"><?php esc_html_e( 'Aún no tienes cursos', 'atora-lms' ); ?></p>
						<p class="atora-empty-state__text"><?php esc_html_e( 'Cuando te inscribas en un curso aparecerá aquí tu progreso.', 'atora-lms' ); ?></p>
					</div>
				<?php else : ?>
						<div class="clms-sd-modules">
							<?php foreach ( array_slice( $course_cards, 0, $course_cards_limit ) as $item ) : ?>
								<article class="clms-sd-module">
									<div>
										<h3 class="clms-sd-module__title"><?php echo esc_html( $item['title'] ); ?></h3>
										<div class="clms-sd-module__meta-row">
											<p class="clms-sd-module__meta"><?php echo esc_html( $item['lessons_text'] ); ?></p>
											<span class="clms-sd-badge <?php echo esc_attr( $item['status_class'] ); ?>">
												<?php echo esc_html( $item['status_label'] ); ?>
											</span>
										</div>
									</div>
									<div class="clms-sd-module__progress">
										<div class="clms-sd-module__value"><?php echo esc_html( $item['progress'] ); ?>%</div>
										<div class="clms-sd-progress" aria-hidden="true">
											<span style="width: <?php echo esc_attr( min( 100, max( 0, (int) $item['progress'] ) ) ); ?>%;"></span>
									</div>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
		<?php
		$sections_output['progress'] = ob_get_clean();

		ob_start();
		?>
			<section class="clms-sd-card" id="clms-sd-alerts">
				<div class="clms-sd-section-head">
					<div>
						<div class="clms-sd-eyebrow"><?php esc_html_e( 'Alertas útiles', 'atora-lms' ); ?></div>
						<h2 class="clms-sd-title"><?php esc_html_e( 'Lo que requiere tu atención', 'atora-lms' ); ?></h2>
					</div>
				</div>

					<?php if ( ! empty( $evaluation_overview ) ) : ?>
						<div class="clms-sd-eval">
							<div class="clms-sd-inline-meta"><?php esc_html_e( 'Estado de evaluación', 'atora-lms' ); ?></div>
							<h3 class="clms-sd-mini__title"><?php echo esc_html( $evaluation_overview['lesson_title'] ); ?></h3>
							<p class="clms-sd-mini__meta"><?php echo esc_html( $evaluation_overview['course_title'] ); ?></p>
							<?php echo $this->render_evaluation_timeline( $evaluation_overview['timeline'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php if ( '' !== (string) $evaluation_overview['grade'] ) : ?>
								<span class="clms-sd-grade">
									<?php
									printf(
										esc_html__( 'Nota final %s/100', 'atora-lms' ),
										esc_html( $evaluation_overview['grade'] )
									);
									?>
								</span>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $feedback_plan ) ) : ?>
						<div class="clms-sd-eval clms-sd-eval--plan">
							<div class="clms-sd-inline-meta"><?php esc_html_e( 'Plan de mejora después de tu evaluación', 'atora-lms' ); ?></div>
							<h3 class="clms-sd-mini__title"><?php echo esc_html( $feedback_plan['title'] ); ?></h3>
							<?php if ( '' !== (string) $feedback_plan['grade'] ) : ?>
								<p class="clms-sd-mini__meta">
									<?php
									printf(
										esc_html__( 'Calificación: %s/100', 'atora-lms' ),
										esc_html( $feedback_plan['grade'] )
									);
									?>
								</p>
							<?php endif; ?>
							<?php if ( ! empty( $feedback_plan['feedback'] ) ) : ?>
								<p class="clms-sd-text clms-sd-text--sm"><?php echo esc_html( $feedback_plan['feedback'] ); ?></p>
							<?php endif; ?>
							<div class="clms-sd-memory-grid">
								<div class="clms-sd-memory-block">
									<div class="clms-sd-inline-meta"><?php esc_html_e( 'Criterios fuertes', 'atora-lms' ); ?></div>
									<?php if ( ! empty( $feedback_plan['strengths'] ) ) : ?>
										<p class="clms-sd-text clms-sd-text--sm"><?php echo esc_html( implode( ' · ', $feedback_plan['strengths'] ) ); ?></p>
									<?php else : ?>
										<p class="clms-sd-text clms-sd-text--muted clms-sd-text--sm"><?php esc_html_e( 'Aún no hay suficiente detalle de criterios.', 'atora-lms' ); ?></p>
									<?php endif; ?>
								</div>
								<div class="clms-sd-memory-block">
									<div class="clms-sd-inline-meta"><?php esc_html_e( 'Criterios a reforzar', 'atora-lms' ); ?></div>
									<?php if ( ! empty( $feedback_plan['reinforce'] ) ) : ?>
										<p class="clms-sd-text clms-sd-text--sm"><?php echo esc_html( implode( ' · ', $feedback_plan['reinforce'] ) ); ?></p>
									<?php else : ?>
										<p class="clms-sd-text clms-sd-text--muted clms-sd-text--sm"><?php esc_html_e( 'Mantén el ritmo actual y sigue practicando.', 'atora-lms' ); ?></p>
									<?php endif; ?>
								</div>
							</div>
							<?php if ( ! empty( $feedback_plan['recommendation'] ) ) : ?>
								<p class="clms-sd-text clms-sd-text--sm"><strong><?php esc_html_e( 'Recomendación concreta:', 'atora-lms' ); ?></strong> <?php echo esc_html( $feedback_plan['recommendation'] ); ?></p>
							<?php endif; ?>
							<?php if ( ! empty( $feedback_plan['next_action'] ) ) : ?>
								<p class="clms-sd-text clms-sd-text--sm"><strong><?php esc_html_e( 'Próxima acción:', 'atora-lms' ); ?></strong> <?php echo esc_html( $feedback_plan['next_action'] ); ?></p>
							<?php endif; ?>
							<div class="clms-sd-card__actions">
								<?php if ( ! empty( $feedback_plan['feedback_url'] ) ) : ?>
									<a class="clms-sd-btn clms-sd-btn--secondary" href="<?php echo esc_url( $feedback_plan['feedback_url'] ); ?>">
										<?php esc_html_e( 'Ver feedback completo', 'atora-lms' ); ?>
									</a>
								<?php endif; ?>
								<?php if ( ! empty( $feedback_plan['resource']['url'] ) ) : ?>
									<a class="clms-sd-btn clms-sd-btn--secondary" href="<?php echo esc_url( $feedback_plan['resource']['url'] ); ?>">
										<?php echo esc_html( $feedback_plan['resource']['label'] ); ?>
									</a>
								<?php endif; ?>
								<?php if ( ! empty( $assistant_context['enabled'] ) && ! empty( $assistant_context['shortcut'] ) ) : ?>
									<a class="clms-sd-btn clms-sd-btn--secondary" href="<?php echo esc_url( $assistant_context['shortcut'] ); ?>">
										<?php esc_html_e( 'Preguntar al asistente IA', 'atora-lms' ); ?>
									</a>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( $alert_summary ) : ?>
						<p class="clms-sd-text clms-sd-text--muted"><?php echo esc_html( $alert_summary ); ?></p>
					<?php endif; ?>

					<?php if ( empty( $alert_items ) ) : ?>
						<p class="clms-sd-text clms-sd-text--muted"><?php esc_html_e( 'No hay alertas pendientes por ahora.', 'atora-lms' ); ?></p>
					<?php else : ?>
						<details class="clms-sd-details">
							<summary><?php esc_html_e( 'Ver alertas', 'atora-lms' ); ?></summary>
							<div class="clms-sd-list">
								<?php foreach ( $alert_items as $item ) : ?>
									<article class="clms-sd-list-card">
										<div class="clms-sd-list-card__head">
											<div class="clms-sd-list-card__main">
												<h3 class="clms-sd-list-card__title"><?php echo esc_html( $item['title'] ); ?></h3>
												<p class="clms-sd-list-card__meta"><?php echo esc_html( $item['meta'] ); ?></p>
											</div>
											<div class="clms-sd-list-card__aside">
												<span class="clms-sd-badge <?php echo esc_attr( $item['badge_class'] ); ?>">
													<?php echo esc_html( $item['label'] ); ?>
												</span>
											</div>
										</div>
										<p class="clms-sd-text"><?php echo esc_html( $item['description'] ); ?></p>
										<?php if ( ! empty( $item['url'] ) ) : ?>
											<div class="clms-sd-list-card__foot">
												<a href="<?php echo esc_url( $item['url'] ); ?>" class="clms-sd-link"><?php esc_html_e( 'Abrir', 'atora-lms' ); ?></a>
											</div>
										<?php endif; ?>
									</article>
								<?php endforeach; ?>
							</div>
						</details>
					<?php endif; ?>

					<details class="clms-sd-details">
						<summary><?php esc_html_e( 'Asistente de estudio IA', 'atora-lms' ); ?></summary>
						<div class="clms-sd-list">
							<p class="clms-sd-text clms-sd-text--sm"><?php esc_html_e( 'Este asistente te ayuda a estudiar y entender mejor el feedback. No reemplaza la evaluación formal de tu curso.', 'atora-lms' ); ?></p>
							<div class="clms-sd-chips clms-sd-assistant-actions">
								<?php if ( ! empty( $assistant_actions ) ) : ?>
									<?php foreach ( array_slice( $assistant_actions, 0, $assistant_actions_limit ) as $assistant_action ) : ?>
										<span class="clms-sd-chip"><?php echo esc_html( $assistant_action['label'] ?? '' ); ?></span>
									<?php endforeach; ?>
								<?php else : ?>
									<span class="clms-sd-chip"><?php esc_html_e( 'Explicar una lección', 'atora-lms' ); ?></span>
									<span class="clms-sd-chip"><?php esc_html_e( 'Resumir contenido', 'atora-lms' ); ?></span>
									<span class="clms-sd-chip"><?php esc_html_e( 'Entender feedback', 'atora-lms' ); ?></span>
									<span class="clms-sd-chip"><?php esc_html_e( 'Plan de estudio', 'atora-lms' ); ?></span>
									<span class="clms-sd-chip"><?php esc_html_e( 'Preparar una entrega', 'atora-lms' ); ?></span>
								<?php endif; ?>
							</div>
							<?php if ( ! empty( $assistant_context['enabled'] ) ) : ?>
								<?php if ( ! empty( $assistant_context['widget'] ) ) : ?>
									<div class="clms-sd-assistant-widget">
										<?php echo $assistant_context['widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</div>
								<?php elseif ( ! empty( $assistant_context['shortcut'] ) ) : ?>
									<a class="clms-sd-btn clms-sd-btn--secondary" href="<?php echo esc_url( $assistant_context['shortcut'] ); ?>">
										<?php esc_html_e( 'Abrir asistente IA', 'atora-lms' ); ?>
									</a>
								<?php endif; ?>
							<?php else : ?>
								<p class="clms-sd-text clms-sd-text--muted clms-sd-text--sm"><?php esc_html_e( 'El asistente IA no está configurado en este momento.', 'atora-lms' ); ?></p>
							<?php endif; ?>
						</div>
					</details>
			</section>
		<?php
		$sections_output['alerts'] = ob_get_clean();

		ob_start();
		?>
			<section class="clms-sd-card" id="clms-sd-incidents">
				<div class="clms-sd-section-head">
					<div>
						<div class="clms-sd-eyebrow"><?php esc_html_e( 'Incidencias', 'atora-lms' ); ?></div>
						<h2 class="clms-sd-title"><?php esc_html_e( 'Señales que vale la pena atender', 'atora-lms' ); ?></h2>
					</div>
				</div>

				<?php
				$has_incidents = false;
				foreach ( $incident_cards as $card ) {
					if ( ! empty( $card['value'] ) ) {
						$has_incidents = true;
						break;
					}
				}
				if ( ! $has_incidents && empty( $memory_insights['needs_attention'] ) ) :
					?>
					<p class="clms-sd-text clms-sd-text--muted"><?php esc_html_e( 'Todo bajo control. Tu panel está limpio por ahora.', 'atora-lms' ); ?></p>
				<?php else : ?>
					<div class="clms-sd-incident-grid">
						<?php foreach ( $incident_cards as $card ) : ?>
							<div class="clms-sd-incident-card is-<?php echo esc_attr( $card['status'] ); ?>">
								<span class="clms-sd-incident-label"><?php echo esc_html( $card['label'] ); ?></span>
								<strong class="clms-sd-incident-value"><?php echo esc_html( $card['value'] ); ?></strong>
								<p class="clms-sd-incident-note"><?php echo esc_html( $card['note'] ); ?></p>
							</div>
						<?php endforeach; ?>
					</div>
					<?php if ( ! empty( $memory_insights['needs_attention'] ) ) : ?>
						<p class="clms-sd-text clms-sd-text--muted"><?php esc_html_e( 'Detectamos señales de atención personal. Si necesitas ayuda, contacta a tu docente.', 'atora-lms' ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</section>
		<?php
		$sections_output['incidents'] = ob_get_clean();

		ob_start();
		?>
			<section class="clms-sd-card" id="clms-sd-academic-status">
				<div class="clms-sd-section-head">
					<div>
						<div class="clms-sd-eyebrow"><?php esc_html_e( 'Estado académico', 'atora-lms' ); ?></div>
						<h2 class="clms-sd-title"><?php esc_html_e( 'Tu avance hacia la certificación', 'atora-lms' ); ?></h2>
					</div>
					<span class="clms-sd-badge <?php echo esc_attr( $certificate_panel['badge_class'] ); ?>">
						<?php echo esc_html( $certificate_panel['label'] ); ?>
					</span>
				</div>

				<div class="clms-sd-memory-grid">
					<div class="clms-sd-memory-block">
						<div class="clms-sd-inline-meta"><?php esc_html_e( 'Requisitos pendientes', 'atora-lms' ); ?></div>
						<p class="clms-sd-text clms-sd-text--sm">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: progreso requerido, 2: promedio requerido */
									__( 'Meta mínima: %1$d%% de progreso y %2$d%% de promedio.', 'atora-lms' ),
									absint( $certificate_panel['required_progress'] ?? 100 ),
									absint( $certificate_panel['required_average'] ?? 70 )
								)
							);
							?>
						</p>
						<?php if ( empty( $certificate_panel['missing'] ) ) : ?>
							<p class="clms-sd-text clms-sd-text--sm"><?php esc_html_e( 'No tienes requisitos pendientes en este curso.', 'atora-lms' ); ?></p>
						<?php else : ?>
							<p class="clms-sd-text clms-sd-text--sm"><?php echo esc_html( implode( ' · ', $certificate_panel['missing'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $certificate_panel['friendly_notice'] ) ) : ?>
							<p class="clms-sd-text clms-sd-text--sm"><?php echo esc_html( $certificate_panel['friendly_notice'] ); ?></p>
						<?php endif; ?>
						<?php if ( absint( $certificate_panel['required_evidences'] ?? 0 ) > 0 ) : ?>
							<p class="clms-sd-text clms-sd-text--sm">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: evidencias aprobadas, 2: evidencias requeridas */
										__( 'Evidencias certificables: %1$d de %2$d aprobadas.', 'atora-lms' ),
										absint( $certificate_panel['completed_evidences'] ?? 0 ),
										absint( $certificate_panel['required_evidences'] ?? 0 )
									)
								);
								?>
							</p>
						<?php endif; ?>
						<?php if ( absint( $certificate_panel['required_competencies'] ?? 0 ) > 0 ) : ?>
							<p class="clms-sd-text clms-sd-text--sm">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: competencias logradas, 2: competencias requeridas */
										__( 'Competencias requeridas: %1$d de %2$d demostradas.', 'atora-lms' ),
										absint( $certificate_panel['completed_competencies'] ?? 0 ),
										absint( $certificate_panel['required_competencies'] ?? 0 )
									)
								);
								?>
							</p>
						<?php endif; ?>
						<div class="clms-sd-card__actions">
							<?php if ( ! empty( $certificate_panel['certificate_url'] ) ) : ?>
								<a class="clms-sd-btn clms-sd-btn--secondary" href="<?php echo esc_url( $certificate_panel['certificate_url'] ); ?>">
									<?php esc_html_e( 'Ver certificado', 'atora-lms' ); ?>
								</a>
							<?php elseif ( ! empty( $certificate_panel['missing'] ) && ! empty( $learning_route['url'] ) ) : ?>
								<a class="clms-sd-btn clms-sd-btn--ghost" href="<?php echo esc_url( $learning_route['url'] ); ?>">
									<?php esc_html_e( 'Ver requisitos para certificar', 'atora-lms' ); ?>
								</a>
							<?php elseif ( ! empty( $certificate_panel['missing'] ) ) : ?>
								<span class="clms-sd-inline-meta"><?php esc_html_e( 'Sigue en tu ruta para completar requisitos de certificación.', 'atora-lms' ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $certificate_panel['verification_url'] ) ) : ?>
								<a class="clms-sd-btn clms-sd-btn--ghost" href="<?php echo esc_url( $certificate_panel['verification_url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Verificar certificado', 'atora-lms' ); ?>
								</a>
							<?php endif; ?>
							<?php if ( ! empty( $certificate_panel['linkedin_url'] ) ) : ?>
								<a class="clms-sd-btn clms-sd-btn--ghost" href="<?php echo esc_url( $certificate_panel['linkedin_url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Agregar a LinkedIn', 'atora-lms' ); ?>
								</a>
							<?php endif; ?>
							<?php if ( ! empty( $certificate_panel['whatsapp_url'] ) ) : ?>
								<a class="clms-sd-btn clms-sd-btn--ghost" href="<?php echo esc_url( $certificate_panel['whatsapp_url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Compartir en WhatsApp', 'atora-lms' ); ?>
								</a>
							<?php endif; ?>
						</div>
						<?php if ( ! empty( $certificate_panel['share_url'] ) ) : ?>
							<p class="clms-sd-text clms-sd-text--sm">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: URL pública */
										__( 'Enlace público: %s', 'atora-lms' ),
										(string) $certificate_panel['share_url']
									)
								);
								?>
							</p>
						<?php endif; ?>
						<?php if ( ! empty( $certificate_panel['is_revoked'] ) ) : ?>
							<p class="clms-sd-text clms-sd-text--sm"><?php esc_html_e( 'Este certificado fue revocado. Contacta a soporte académico para más información.', 'atora-lms' ); ?></p>
						<?php endif; ?>
					</div>
					<div class="clms-sd-memory-block">
						<div class="clms-sd-inline-meta"><?php esc_html_e( 'Plan de mejora', 'atora-lms' ); ?></div>
						<?php if ( ! empty( $active_status['improvement_plan']['next_action'] ) ) : ?>
							<p class="clms-sd-text clms-sd-text--sm"><?php echo esc_html( $active_status['improvement_plan']['next_action'] ); ?></p>
						<?php elseif ( ! empty( $active_status['improvement_plan']['recommendation'] ) ) : ?>
							<p class="clms-sd-text clms-sd-text--sm"><?php echo esc_html( $active_status['improvement_plan']['recommendation'] ); ?></p>
						<?php elseif ( ! empty( $active_status['improvement_plan']['recommendations'][0] ) ) : ?>
							<p class="clms-sd-text clms-sd-text--sm"><?php echo esc_html( $active_status['improvement_plan']['recommendations'][0] ); ?></p>
						<?php elseif ( ! empty( $active_status['next_step'] ) ) : ?>
							<p class="clms-sd-text clms-sd-text--sm"><?php echo esc_html( $active_status['next_step'] ); ?></p>
						<?php else : ?>
							<p class="clms-sd-text clms-sd-text--muted clms-sd-text--sm"><?php esc_html_e( 'Sigue con tu próxima actividad para mantener el avance.', 'atora-lms' ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $active_status['improvement_plan']['strengths'][0] ) ) : ?>
							<p class="clms-sd-text clms-sd-text--sm"><strong><?php esc_html_e( 'Fortaleza:', 'atora-lms' ); ?></strong> <?php echo esc_html( $active_status['improvement_plan']['strengths'][0] ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $active_status['improvement_plan']['weaknesses'][0] ) ) : ?>
							<p class="clms-sd-text clms-sd-text--sm"><strong><?php esc_html_e( 'A reforzar:', 'atora-lms' ); ?></strong> <?php echo esc_html( $active_status['improvement_plan']['weaknesses'][0] ); ?></p>
						<?php endif; ?>
					</div>
				</div>

				<details class="clms-sd-details">
					<summary><?php esc_html_e( 'Mis competencias', 'atora-lms' ); ?></summary>
					<?php
					$competencies = isset( $active_status['competencies'] ) && is_array( $active_status['competencies'] ) ? $active_status['competencies'] : array();
					$evidence_summary = isset( $active_status['evidences'] ) && is_array( $active_status['evidences'] ) ? $active_status['evidences'] : array();
					?>
					<?php if ( empty( $competencies ) ) : ?>
						<p class="clms-sd-text clms-sd-text--sm"><?php esc_html_e( 'Este curso aún no tiene competencias configuradas.', 'atora-lms' ); ?></p>
					<?php else : ?>
						<div class="clms-sd-memory-grid">
							<?php foreach ( array_slice( $competencies, 0, 4 ) as $comp_item ) : ?>
								<div class="clms-sd-memory-block">
									<div class="clms-sd-inline-meta"><?php echo esc_html( $comp_item['title'] ?? '' ); ?></div>
									<p class="clms-sd-text clms-sd-text--sm">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: estado, 2: puntaje */
												__( 'Estado: %1$s · Puntaje: %2$s%%', 'atora-lms' ),
												$this->get_competency_status_label( isset( $comp_item['status'] ) ? (string) $comp_item['status'] : '' ),
												absint( $comp_item['score'] ?? 0 )
											)
										);
										?>
									</p>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $evidence_summary ) ) : ?>
						<p class="clms-sd-text clms-sd-text--sm">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: evidencias aprobadas, 2: total */
									__( 'Evidencias obligatorias aprobadas: %1$d de %2$d', 'atora-lms' ),
									absint( $evidence_summary['required_approved'] ?? 0 ),
									absint( $evidence_summary['required_total'] ?? 0 )
								)
							);
							?>
						</p>
					<?php endif; ?>
				</details>

				<div class="clms-sd-incident-grid">
					<div class="clms-sd-incident-card is-info">
						<span class="clms-sd-incident-label"><?php esc_html_e( 'Nivel actual', 'atora-lms' ); ?></span>
						<strong class="clms-sd-incident-value"><?php echo esc_html( $gamification_panel['level'] ); ?></strong>
						<p class="clms-sd-incident-note"><?php echo esc_html( ! empty( $gamification_panel['narrative'] ) ? $gamification_panel['narrative'] : __( 'Progreso gamificado académico', 'atora-lms' ) ); ?></p>
					</div>
					<div class="clms-sd-incident-card is-success">
						<span class="clms-sd-incident-label"><?php esc_html_e( 'Puntos acumulados', 'atora-lms' ); ?></span>
						<strong class="clms-sd-incident-value"><?php echo esc_html( $gamification_panel['points'] ); ?></strong>
						<p class="clms-sd-incident-note"><?php echo esc_html( $gamification_panel['next_level_note'] ); ?></p>
					</div>
					<div class="clms-sd-incident-card is-warning">
						<span class="clms-sd-incident-label"><?php esc_html_e( 'Racha actual', 'atora-lms' ); ?></span>
						<strong class="clms-sd-incident-value"><?php echo esc_html( $gamification_panel['streak'] ); ?></strong>
						<p class="clms-sd-incident-note"><?php esc_html_e( 'Días consecutivos de actividad', 'atora-lms' ); ?></p>
					</div>
					<div class="clms-sd-incident-card is-muted">
						<span class="clms-sd-incident-label"><?php esc_html_e( 'Último logro', 'atora-lms' ); ?></span>
						<strong class="clms-sd-incident-value"><?php echo esc_html( $gamification_panel['last_event_title'] ); ?></strong>
						<p class="clms-sd-incident-note"><?php echo esc_html( $gamification_panel['last_event_date'] ); ?></p>
						<?php if ( isset( $gamification_panel['pathway_progress'] ) ) : ?>
							<p class="clms-sd-incident-note">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: porcentaje de ruta */
										__( 'Ruta de logros completada: %d%%', 'atora-lms' ),
										absint( $gamification_panel['pathway_progress'] )
									)
								);
								?>
							</p>
						<?php endif; ?>
						<?php if ( ! empty( $gamification_panel['next_badge'] ) ) : ?>
							<p class="clms-sd-incident-note"><?php echo esc_html( sprintf( __( 'Próximo logro: %s', 'atora-lms' ), $gamification_panel['next_badge'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $gamification_panel['pending_badges'] ) && is_array( $gamification_panel['pending_badges'] ) ) : ?>
							<p class="clms-sd-incident-note">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: lista breve de badges */
										__( 'Pendientes: %s', 'atora-lms' ),
										implode( ', ', array_slice( array_map( 'sanitize_text_field', $gamification_panel['pending_badges'] ), 0, 2 ) )
									)
								);
								?>
							</p>
						<?php endif; ?>
					</div>
				</div>
			</section>
		<?php
		$sections_output['academic'] = ob_get_clean();

		ob_start();
		?>
			<section class="clms-sd-card clms-sd-card--memory">
					<div class="clms-sd-section-head">
						<div>
							<div class="clms-sd-eyebrow"><?php esc_html_e( 'Tu progreso personal', 'atora-lms' ); ?></div>
							<h2 class="clms-sd-title"><?php echo esc_html( $memory_insights['title'] ); ?></h2>
						</div>
					</div>

					<p class="clms-sd-text"><?php echo esc_html( $memory_insights['summary'] ?? $memory_insights['message'] ); ?></p>

					<div class="clms-sd-memory-grid">
						<div class="clms-sd-memory-block">
							<div class="clms-sd-inline-meta"><?php esc_html_e( 'Conviene reforzar', 'atora-lms' ); ?></div>
							<?php if ( ! empty( $memory_insights['reinforce_topics'] ) ) : ?>
								<div class="clms-sd-chips">
									<?php foreach ( $memory_insights['reinforce_topics'] as $topic ) : ?>
										<span class="clms-sd-chip"><?php echo esc_html( $topic ); ?></span>
									<?php endforeach; ?>
								</div>
							<?php else : ?>
								<p class="clms-sd-text clms-sd-text--muted clms-sd-text--sm"><?php esc_html_e( 'No hay temas críticos ahora mismo.', 'atora-lms' ); ?></p>
							<?php endif; ?>
						</div>
						<div class="clms-sd-memory-block">
							<div class="clms-sd-inline-meta"><?php esc_html_e( 'Has mejorado en', 'atora-lms' ); ?></div>
							<?php if ( ! empty( $memory_insights['highlight_topics'] ) ) : ?>
								<p class="clms-sd-text clms-sd-text--sm"><?php echo esc_html( implode( ' · ', $memory_insights['highlight_topics'] ) ); ?></p>
							<?php else : ?>
								<p class="clms-sd-text clms-sd-text--muted clms-sd-text--sm"><?php esc_html_e( 'Sigue avanzando para generar más señales de mejora.', 'atora-lms' ); ?></p>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( ! empty( $memory_insights['recommendation'] ) ) : ?>
						<div class="clms-sd-profile">
							<div class="clms-sd-inline-meta"><?php esc_html_e( 'Recomendación siguiente', 'atora-lms' ); ?></div>
							<p class="clms-sd-text clms-sd-text--sm"><?php echo esc_html( $memory_insights['recommendation'] ); ?></p>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $memory_insights['cta'] ) && ! empty( $memory_insights['cta']['url'] ) ) : ?>
						<div class="clms-sd-card__actions">
							<a class="clms-sd-btn clms-sd-btn--secondary" href="<?php echo esc_url( $memory_insights['cta']['url'] ); ?>">
								<?php echo esc_html( $memory_insights['cta']['label'] ); ?>
							</a>
						</div>
					<?php endif; ?>

				<?php if ( ! empty( $profile_snapshot['summary'] ) || ! empty( $profile_snapshot['goals'] ) ) : ?>
					<div class="clms-sd-profile">
						<div class="clms-sd-inline-meta"><?php esc_html_e( 'Tu enfoque', 'atora-lms' ); ?></div>
						<?php if ( ! empty( $profile_snapshot['summary'] ) ) : ?>
							<p class="clms-sd-text clms-sd-text--sm"><?php echo esc_html( $profile_snapshot['summary'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $profile_snapshot['goals'] ) ) : ?>
							<p class="clms-sd-text clms-sd-text--muted clms-sd-text--sm"><?php esc_html_e( 'Objetivo:', 'atora-lms' ); ?> <?php echo esc_html( $profile_snapshot['goals'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<p class="clms-sd-text clms-sd-text--muted clms-sd-text--sm"><?php esc_html_e( 'Completa tu perfil para personalizar tu experiencia.', 'atora-lms' ); ?></p>
					<?php if ( function_exists( 'clms_core' ) && clms_core() && method_exists( clms_core(), 'get_module' ) ) : ?>
						<?php
						$student_profile = clms_core()->get_module( 'CLMS_Student_Profile' );
						$profile_url     = $student_profile && method_exists( $student_profile, 'get_profile_url' )
							? $student_profile->get_profile_url( $user_id )
							: '';
						?>
						<?php if ( $profile_url ) : ?>
							<div class="clms-sd-card__actions">
								<a class="clms-sd-btn clms-sd-btn--secondary" href="<?php echo esc_url( $profile_url ); ?>">
									<?php esc_html_e( 'Completar perfil', 'atora-lms' ); ?>
								</a>
							</div>
						<?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( ! empty( $memory_insights['last_seen'] ) ) : ?>
					<p class="clms-sd-text clms-sd-text--muted">
						<?php
						printf(
							esc_html__( 'Última actividad: %s', 'atora-lms' ),
							esc_html( $this->format_datetime( $memory_insights['last_seen'] ) )
						);
						?>
					</p>
				<?php endif; ?>
				<?php if ( ! empty( $memory_insights['needs_attention'] ) ) : ?>
					<p class="clms-sd-text clms-sd-text--muted"><?php esc_html_e( 'Si necesitas apoyo, pide ayuda a tu docente o tutor.', 'atora-lms' ); ?></p>
				<?php endif; ?>
			</section>
		<?php
		$sections_output['memory'] = ob_get_clean();

		// ── Sprint S8: Sección "Tu próxima acción" + puntos + práctica ───────
		ob_start();
		// ── a. Próxima acción (Learning Path) ────────────────────────────────
		$s8_next_lesson = null;
		if ( ! empty( $course_ids ) && class_exists( 'CLMS_Learning_Path' ) ) {
			$lp = new CLMS_Learning_Path();
			$lp_data = $lp->generate( $user_id, reset( $course_ids ) );
			$s8_next_lesson = is_array( $lp_data ) && ! empty( $lp_data['next_lessons'] )
				? $lp_data['next_lessons'][0] : null;
		}
		// ── b. Progreso del primer curso activo ───────────────────────────────
		$s8_progress = null;
		if ( ! empty( $course_ids ) && class_exists( '\ATORA\LMS\LMS_Enrollment_Service' ) && class_exists( '\ATORA\LMS\LMS_Course_Service' ) ) {
			$first_course_row   = \ATORA\LMS\LMS_Course_Service::get_by_wp_post( (int) reset( $course_ids ) );
			$internal_course_id = $first_course_row ? (int) $first_course_row['id'] : 0;
			$s8_progress        = $internal_course_id
				? \ATORA\LMS\LMS_Enrollment_Service::get_progress( $user_id, $internal_course_id )
				: null;
		}
		// ── c. Widget de gamificación ─────────────────────────────────────────
		$s8_gamification = null;
		if ( class_exists( 'CLMS_Gamification_Core' ) ) {
			$gc = new CLMS_Gamification_Core();
			$s8_gamification = $gc->get_user_summary( $user_id );
		}
		// ── d. Práctica recomendada (Feedback Loop) ───────────────────────────
		$s8_practice = array();
		if ( class_exists( 'CLMS_Feedback_Loop' ) ) {
			$fl = new CLMS_Feedback_Loop();
			if ( method_exists( $fl, 'get_active_gaps' ) ) {
				$gaps = $fl->get_active_gaps( $user_id );
				$gap  = is_array( $gaps ) && ! empty( $gaps ) ? reset( $gaps ) : null;
				if ( $gap && method_exists( $fl, 'ai_generate_practice_items' ) ) {
					$s8_practice = array_slice( (array) $fl->ai_generate_practice_items( $gap, 'basic' ), 0, 3 );
				}
			}
		}

		if ( $s8_next_lesson || $s8_gamification || ! empty( $s8_practice ) ) :
		?>
		<section class="clms-sd-card clms-sd-s8-widgets" style="margin-bottom:1.5rem">
			<div class="clms-sd-card__body" style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(240px,1fr))">

			<?php if ( $s8_next_lesson ) : ?>
			<!-- a. Tu próxima acción -->
			<div class="clms-sd-s8-block" style="background:#eff6ff;border-radius:12px;padding:1rem;border:.5px solid #bfdbfe">
				<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#1d4ed8;margin-bottom:6px"><?php esc_html_e( 'Tu próxima acción', 'atora-lms' ); ?></div>
				<p style="font-size:14px;font-weight:600;color:#0f172a;margin:0 0 10px;line-height:1.4"><?php echo esc_html( (string) ( $s8_next_lesson['title'] ?? '' ) ); ?></p>
				<?php if ( ! empty( $s8_next_lesson['reason'] ) ) : ?>
				<p style="font-size:12px;color:#475569;margin:0 0 10px"><?php echo esc_html( (string) $s8_next_lesson['reason'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $s8_next_lesson['url'] ) ) : ?>
				<a href="<?php echo esc_url( (string) $s8_next_lesson['url'] ); ?>" class="clms-sd-btn clms-sd-btn--primary" style="font-size:13px;display:inline-block;padding:7px 16px;background:#1d4ed8;color:#fff;border-radius:7px;text-decoration:none;font-weight:600">
					<?php esc_html_e( 'Continuar ahora', 'atora-lms' ); ?>
				</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php if ( $s8_progress ) : ?>
			<!-- b. Barra de progreso por sección -->
			<div class="clms-sd-s8-block" style="background:#f8fafc;border-radius:12px;padding:1rem;border:.5px solid #e2e8f0">
				<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:10px"><?php esc_html_e( 'Progreso del curso', 'atora-lms' ); ?></div>
				<?php
				$pct = absint( $s8_progress['progress_pct'] ?? 0 );
				$done = absint( $s8_progress['completed_lessons'] ?? 0 );
				$total = absint( $s8_progress['total_lessons'] ?? 0 );
				$color = $pct < 30 ? '#e24b4a' : ( $pct < 70 ? '#f59e0b' : '#1d9e75' );
				?>
				<div style="background:#e2e8f0;border-radius:999px;height:8px;margin-bottom:8px">
					<div style="width:<?php echo esc_attr( (string) $pct ); ?>%;background:<?php echo esc_attr( $color ); ?>;height:8px;border-radius:999px;transition:width .4s"></div>
				</div>
				<p style="font-size:13px;color:#334155;margin:0">
					<?php echo esc_html( sprintf( __( '%d%% completado — %d de %d lecciones', 'atora-lms' ), $pct, $done, $total ) ); ?>
				</p>
			</div>
			<?php endif; ?>

			<?php if ( $s8_gamification ) : ?>
			<!-- c. Widget de puntos -->
			<div class="clms-sd-s8-block" style="background:#fefce8;border-radius:12px;padding:1rem;border:.5px solid #fde68a">
				<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#92400e;margin-bottom:6px"><?php esc_html_e( 'Tus puntos', 'atora-lms' ); ?></div>
				<div style="font-size:28px;font-weight:800;color:#0f172a;line-height:1"><?php echo esc_html( number_format_i18n( absint( $s8_gamification['points'] ?? 0 ) ) ); ?></div>
				<div style="font-size:12px;color:#64748b;margin-top:4px">
					<?php echo esc_html( sprintf( __( 'Nivel %d', 'atora-lms' ), absint( $s8_gamification['level'] ?? 1 ) ) ); ?>
					<?php if ( isset( $s8_gamification['points_to_next_level'] ) && $s8_gamification['points_to_next_level'] > 0 ) : ?>
					— <?php echo esc_html( sprintf( __( '%d pts para el siguiente', 'atora-lms' ), absint( $s8_gamification['points_to_next_level'] ) ) ); ?>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $s8_gamification['streak_days'] ) ) : ?>
				<div style="font-size:11px;color:#f59e0b;margin-top:6px;font-weight:600">
					🔥 <?php echo esc_html( sprintf( _n( '%d día seguido', '%d días seguidos', absint( $s8_gamification['streak_days'] ), 'atora-lms' ), absint( $s8_gamification['streak_days'] ) ) ); ?>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			</div><!-- /grid -->

			<?php if ( ! empty( $s8_practice ) ) : ?>
			<!-- d. Práctica recomendada -->
			<div style="margin-top:1rem;padding:1rem;background:#f0fdf4;border-radius:12px;border:.5px solid #bbf7d0">
				<div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#065f46;margin-bottom:12px"><?php esc_html_e( 'Práctica recomendada', 'atora-lms' ); ?></div>
				<ol style="margin:0;padding-left:18px;display:grid;gap:10px">
				<?php foreach ( $s8_practice as $item ) :
					$q = sanitize_text_field( (string) ( $item['question'] ?? '' ) );
					if ( '' === $q ) { continue; }
				?>
					<li style="font-size:13px;color:#0f172a;line-height:1.5">
						<?php echo esc_html( $q ); ?>
						<?php if ( ! empty( $item['hint'] ) ) : ?>
						<br><small style="color:#64748b"><?php echo esc_html( '💡 ' . (string) $item['hint'] ); ?></small>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
				</ol>
			</div>
			<?php endif; ?>
		</section>
		<?php
		endif;
		$sections_output['s8_widgets'] = ob_get_clean();

		$ordered_sections = $this->resolve_dashboard_sections( $schema, array_keys( $sections_output ) );
		?>
		<div class="clms-sd">
			<?php
			foreach ( $ordered_sections as $section_id ) {
				if ( isset( $sections_output[ $section_id ] ) ) {
					echo $sections_output[ $section_id ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
			?>
			<?php if ( ! empty( $primary_cta['url'] ) ) : ?>
				<div class="clms-sd-sticky-cta">
					<a class="clms-sd-btn clms-sd-btn--primary" href="<?php echo esc_url( $primary_cta['url'] ); ?>">
						<?php echo esc_html( $primary_cta['label'] ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php

		return ob_get_clean();
	}

}
