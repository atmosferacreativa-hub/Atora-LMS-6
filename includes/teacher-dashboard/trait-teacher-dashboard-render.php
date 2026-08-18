<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Teacher_Dashboard_Render_Trait {
	public function render_shortcode( $atts ) {
		unset( $atts );

		$this->enqueue_assets();

		if ( ! is_user_logged_in() ) {
			ob_start();
			?>
			<div class="clms-td">
				<div class="clms-td-card">
					<div class="clms-td-card__body">
						<div class="clms-td-eyebrow"><?php esc_html_e( 'Acceso requerido', 'atora-lms' ); ?></div>
						<h2 class="clms-td-title"><?php esc_html_e( 'Debes iniciar sesión para ver tu panel docente', 'atora-lms' ); ?></h2>
						<p class="clms-td-text"><?php esc_html_e( 'Inicia sesión para revisar entregas, administrar tus cursos y dar seguimiento a tus estudiantes.', 'atora-lms' ); ?></p>
					</div>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		$user_id = get_current_user_id();
		$user    = wp_get_current_user();

		if ( ! $this->user_can_view_teacher_dashboard( $user_id ) ) {
			ob_start();
			?>
			<div class="clms-td">
				<div class="clms-td-card">
					<div class="clms-td-card__body">
						<div class="clms-td-eyebrow"><?php esc_html_e( 'Permisos', 'atora-lms' ); ?></div>
						<h2 class="clms-td-title"><?php esc_html_e( 'No tienes permisos para ver este panel', 'atora-lms' ); ?></h2>
						<p class="clms-td-text"><?php esc_html_e( 'Este panel está disponible solo para usuarios con cursos o permisos de gestión docente.', 'atora-lms' ); ?></p>
					</div>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		$schema              = $this->get_dashboard_ui_schema( $user_id );
		$inbox_limit         = $this->get_dashboard_limit( $schema, 'inbox_items', 24 );
		$pending_limit       = $this->get_dashboard_limit( $schema, 'pending_items', 8 );
		$reviewed_limit      = $this->get_dashboard_limit( $schema, 'reviewed_items', 6 );
		$recent_lessons_limit = $this->get_dashboard_limit( $schema, 'recent_lessons', 6 );
		$notifications_limit = $this->get_dashboard_limit( $schema, 'notifications', 4 );
		$course_cards_limit  = $this->get_dashboard_limit( $schema, 'course_cards', 6 );
		$priority_queue_limit = $this->get_dashboard_limit( $schema, 'priority_queue_items', 8 );
		$risk_students_limit = $this->get_dashboard_limit( $schema, 'risk_students_items', 6 );
		$ai_pending_limit    = $this->get_dashboard_limit( $schema, 'ai_pending_items', 6 );

		$course_ids          = $this->get_teacher_courses( $user_id );
		$lesson_ids          = $this->get_teacher_lessons( $user_id, $course_ids );
		$inbox_filters       = $this->get_inbox_filters();
		$inbox_page          = $this->get_inbox_page();
		$inbox_data          = $this->get_submission_inbox_data( $lesson_ids, $inbox_filters, $inbox_page, $inbox_limit );
		$inbox_items         = $inbox_data['items'];
		$inbox_options       = $this->get_submission_inbox_filter_options( $course_ids, $lesson_ids, $inbox_items );
		$pending_submissions = $this->get_pending_submissions( $user_id, $course_ids, $lesson_ids, $pending_limit );
		$reviewed_items      = $this->get_reviewed_submissions( $user_id, $course_ids, $lesson_ids, $reviewed_limit );
		$recent_lessons      = $this->get_recent_lessons_data( $lesson_ids, $recent_lessons_limit );
		$course_cards        = $this->get_course_cards( $course_ids );
		$metrics             = $this->get_metrics( $course_ids, $lesson_ids, $pending_submissions, $reviewed_items );
		$speedgrade_focus    = $this->get_speedgrade_focus_item( $pending_submissions );
		$notifications       = $this->get_teacher_notifications( $user_id, $notifications_limit );
		$group_overview      = $this->get_group_overview( $course_ids, $lesson_ids );
		$smart_queue         = $this->get_prioritized_evaluation_queue( $course_ids, $lesson_ids, $priority_queue_limit );
		$competency_overview = $this->get_competency_group_overview( $pending_submissions, $reviewed_items );
		$smart_summary       = $this->get_smart_panel_summary( $smart_queue, $group_overview, $notifications );
		$queue_service       = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Teacher_Priority_Queue_Service') : null;
		$ai_manager          = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
		$teacher_ai_enabled  = ( $ai_manager && method_exists( $ai_manager, 'is_configured' ) ) ? (bool) $ai_manager->is_configured() : false;
		if ( $queue_service && method_exists( $queue_service, 'normalize_queue' ) ) {
			$smart_queue = (array) $queue_service->normalize_queue( $smart_queue, $priority_queue_limit );
		}
		$students_at_risk_queue = ( $queue_service && method_exists( $queue_service, 'extract_students_at_risk' ) )
			? (array) $queue_service->extract_students_at_risk( $smart_queue, $risk_students_limit )
			: array();
		$ai_pending_reviews = ( $queue_service && method_exists( $queue_service, 'extract_ai_pending_reviews' ) )
			? (array) $queue_service->extract_ai_pending_reviews( $smart_queue, $ai_pending_limit )
			: array();
		$academic_report = ( $queue_service && method_exists( $queue_service, 'build_academic_report' ) )
			? (array) $queue_service->build_academic_report( $smart_summary, $group_overview, $competency_overview )
			: array();
		$teacher_data_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Teacher_Dashboard_Data') : null;
		$hub_data            = array();
		$display_name        = $user->display_name ? $user->display_name : $user->user_login;
		$show_hero           = $this->is_section_enabled( $schema, 'hero' );
		$show_smart_panel    = $this->is_section_enabled( $schema, 'smart_panel' );
		$show_quick_access   = $this->is_section_enabled( $schema, 'quick_access' );
		$show_pulse          = $this->is_section_enabled( $schema, 'pulse' );
		$show_inbox          = $this->is_section_enabled( $schema, 'inbox' );
		$show_reviewed       = $this->is_section_enabled( $schema, 'reviewed' );
		$show_recent_lessons = $this->is_section_enabled( $schema, 'recent_lessons' );
		$show_courses        = $this->is_section_enabled( $schema, 'courses' );
		$show_priority       = $this->is_section_enabled( $schema, 'priority' );
		$show_incidents      = $this->is_section_enabled( $schema, 'incidents' );
		$show_summary        = $this->is_section_enabled( $schema, 'summary' );
		$show_notifications  = $this->is_section_enabled( $schema, 'notifications' );
		$show_students       = $this->is_section_enabled( $schema, 'courses' );
		$show_grid           = $show_reviewed || $show_recent_lessons;
		$completion_rate     = absint( $group_overview['completion_rate'] ?? 0 );
		$enrolled_count      = absint( $group_overview['enrolled'] ?? 0 );
		$active_count        = absint( $group_overview['active'] ?? 0 );
		$pending_count       = absint( $group_overview['pending'] ?? 0 );
			$avg_grade_label     = ! empty( $group_overview['avg_grade'] ) ? absint( $group_overview['avg_grade'] ) . '%' : __( 'Sin datos', 'atora-lms' );
			$selected_course_id  = $this->get_selected_course_id( $course_ids );
			$academic_configuration_notice = '';
			$diagnostics_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Diagnostics_Service') : null;
			if ( ! $diagnostics_service && class_exists( 'CLMS_Academic_Diagnostics_Service' ) ) {
				$diagnostics_service = new CLMS_Academic_Diagnostics_Service();
			}
			if ( $selected_course_id && $diagnostics_service && method_exists( $diagnostics_service, 'diagnose_course' ) ) {
				$selected_diag = (array) $diagnostics_service->diagnose_course( $selected_course_id );
				$diag_errors   = isset( $selected_diag['errors'] ) && is_array( $selected_diag['errors'] ) ? $selected_diag['errors'] : array();
				$diag_warnings = isset( $selected_diag['warnings'] ) && is_array( $selected_diag['warnings'] ) ? $selected_diag['warnings'] : array();
				if ( ! empty( $diag_errors ) || ! empty( $diag_warnings ) ) {
					$academic_configuration_notice = sprintf(
						/* translators: 1: errores, 2: advertencias */
						__( 'Este curso tiene configuración académica incompleta (%1$d errores, %2$d advertencias). Revisa competencias, evidencias y rúbricas.', 'atora-lms' ),
						count( $diag_errors ),
						count( $diag_warnings )
					);
				}
			}
			$hub_data            = ( $teacher_data_service && method_exists( $teacher_data_service, 'build_hub_data' ) )
				? (array) $teacher_data_service->build_hub_data( $user_id, $smart_summary, $selected_course_id )
				: array();
			$teacher_dashboard_payload = ( $teacher_data_service && method_exists( $teacher_data_service, 'build_dashboard_payload' ) )
				? (array) $teacher_data_service->build_dashboard_payload(
					$user_id,
					array(
						'summary'            => $smart_summary,
						'selected_course_id' => $selected_course_id,
						'priority_queue'     => $smart_queue,
						'students_at_risk'   => $students_at_risk_queue,
						'ai_pending_reviews' => $ai_pending_reviews,
						'course_cards'       => $course_cards,
						'reviewed_items'     => $reviewed_items,
						'academic_report'    => $academic_report,
					)
				)
				: array();
			if ( ! empty( $teacher_dashboard_payload['summary'] ) && is_array( $teacher_dashboard_payload['summary'] ) ) {
				$smart_summary = array_merge( $smart_summary, $teacher_dashboard_payload['summary'] );
			}
			if ( ! empty( $teacher_dashboard_payload['priority_queue'] ) && is_array( $teacher_dashboard_payload['priority_queue'] ) ) {
				$smart_queue = $teacher_dashboard_payload['priority_queue'];
			}
			if ( ! empty( $teacher_dashboard_payload['students_at_risk'] ) && is_array( $teacher_dashboard_payload['students_at_risk'] ) ) {
				$students_at_risk_queue = $teacher_dashboard_payload['students_at_risk'];
			}
			if ( ! empty( $teacher_dashboard_payload['ai_pending_reviews'] ) && is_array( $teacher_dashboard_payload['ai_pending_reviews'] ) ) {
				$ai_pending_reviews = $teacher_dashboard_payload['ai_pending_reviews'];
			}
			if ( ! empty( $hub_data['summary'] ) && is_array( $hub_data['summary'] ) ) {
				$smart_summary = array_merge( $smart_summary, $hub_data['summary'] );
			}
			$quick_actions       = isset( $hub_data['quick_actions'] ) && is_array( $hub_data['quick_actions'] ) ? $hub_data['quick_actions'] : array();
			$student_rows        = $selected_course_id ? $this->get_student_table_data( $selected_course_id ) : array();
			$incident_cards      = array(
			array(
				'label'  => __( 'Pendientes', 'atora-lms' ),
				'value'  => absint( $group_overview['pending'] ?? 0 ),
				'note'   => __( 'Entregas sin revisar', 'atora-lms' ),
				'status' => ! empty( $group_overview['pending'] ) ? 'warning' : 'success',
			),
			array(
				'label'  => __( 'En riesgo', 'atora-lms' ),
				'value'  => absint( $group_overview['at_risk'] ?? 0 ),
				'note'   => __( 'Sin actividad reciente', 'atora-lms' ),
				'status' => ! empty( $group_overview['at_risk'] ) ? 'warning' : 'success',
			),
			array(
				'label'  => __( 'Notas bajas', 'atora-lms' ),
				'value'  => absint( $group_overview['low_grade'] ?? 0 ),
				'note'   => __( 'Bajo el umbral de desempeño', 'atora-lms' ),
				'status' => ! empty( $group_overview['low_grade'] ) ? 'warning' : 'success',
			),
		);

		ob_start();
		?>
		<div class="clms-td">
			<?php if ( $show_hero ) : ?>
			<section class="clms-td-hero">
				<div class="clms-td-hero__copy">
					<div class="clms-td-pill"><?php esc_html_e( 'Panel docente', 'atora-lms' ); ?></div>

					<h1 class="clms-td-hero__title">
						<?php
						printf(
							esc_html__( 'Hola, %s', 'atora-lms' ),
							esc_html( $display_name )
						);
						?>
					</h1>

					<p class="clms-td-hero__text">
						<?php echo esc_html( $this->build_lead_text( $metrics ) ); ?>
					</p>

					<div class="clms-td-hero__actions">
						<a class="clms-td-btn clms-td-btn--primary" href="#clms-td-inbox"><?php esc_html_e( 'Revisar entregas', 'atora-lms' ); ?></a>
						<?php if ( current_user_can( 'clms_grade_submissions' ) ) : ?>
							<a class="clms-td-btn clms-td-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=clms-speedgrader' ) ); ?>"><?php esc_html_e( 'Abrir SpeedGrade', 'atora-lms' ); ?></a>
						<?php endif; ?>
						<?php if ( $teacher_ai_enabled ) : ?>
							<button type="button" class="clms-td-btn clms-td-btn--ghost clms-td-ai-open" id="clms-td-open-assistant" title="<?php echo esc_attr__( 'Abrir asistente IA', 'atora-lms' ); ?>">
								<?php esc_html_e( 'Asistente IA', 'atora-lms' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</div>

				<div class="clms-td-hero__stats">
					<div class="clms-td-stat clms-td-stat--hero">
						<div class="clms-td-stat__label"><?php esc_html_e( 'Pendientes por revisar', 'atora-lms' ); ?></div>
						<div class="clms-td-stat__value"><?php echo esc_html( $metrics['pending_reviews'] ); ?></div>
						<div class="clms-td-stat__hint"><?php esc_html_e( 'Cola actual de revisión', 'atora-lms' ); ?></div>
					</div>

					<div class="clms-td-stat clms-td-stat--hero">
						<div class="clms-td-stat__label"><?php esc_html_e( 'Entregas revisadas', 'atora-lms' ); ?></div>
						<div class="clms-td-stat__value"><?php echo esc_html( $metrics['reviewed'] ); ?></div>
						<div class="clms-td-stat__hint"><?php esc_html_e( 'Actividad reciente', 'atora-lms' ); ?></div>
					</div>

					<div class="clms-td-stat-grid">
						<div class="clms-td-stat">
							<div class="clms-td-stat__value clms-td-stat__value--sm"><?php echo esc_html( $metrics['courses'] ); ?></div>
							<div class="clms-td-stat__label"><?php esc_html_e( 'Cursos', 'atora-lms' ); ?></div>
						</div>

						<div class="clms-td-stat">
							<div class="clms-td-stat__value clms-td-stat__value--sm"><?php echo esc_html( $metrics['lessons'] ); ?></div>
							<div class="clms-td-stat__label"><?php esc_html_e( 'Lecciones', 'atora-lms' ); ?></div>
						</div>

						<div class="clms-td-stat">
							<div class="clms-td-stat__value clms-td-stat__value--sm"><?php echo esc_html( count( $pending_submissions ) ); ?></div>
							<div class="clms-td-stat__label"><?php esc_html_e( 'En cola', 'atora-lms' ); ?></div>
						</div>

						<div class="clms-td-stat">
							<div class="clms-td-stat__value clms-td-stat__value--sm"><?php echo esc_html( count( $course_cards ) ); ?></div>
							<div class="clms-td-stat__label"><?php esc_html_e( 'Activos', 'atora-lms' ); ?></div>
						</div>
					</div>
				</div>
			</section>
			<?php endif; ?>

			<div class="clms-td-layout">
				<main class="clms-td-main">
					<?php if ( $show_quick_access ) : ?>
					<section class="clms-td-card">
						<div class="clms-td-section-head">
							<div>
								<div class="clms-td-eyebrow"><?php esc_html_e( 'Centro operativo', 'atora-lms' ); ?></div>
								<h2 class="clms-td-title"><?php esc_html_e( 'Accesos rápidos', 'atora-lms' ); ?></h2>
							</div>
						</div>

						<div class="clms-td-quick-grid">
							<?php if ( empty( $quick_actions ) ) : ?>
								<a class="clms-td-quick" href="#clms-td-inbox">
									<span class="clms-td-quick__title"><?php esc_html_e( 'Bandeja de entregas', 'atora-lms' ); ?></span>
									<span class="clms-td-quick__text"><?php esc_html_e( 'Entra directo a la cola de revisión y prioriza entregas pendientes.', 'atora-lms' ); ?></span>
								</a>
							<?php else : ?>
								<?php foreach ( $quick_actions as $action ) : ?>
									<a class="clms-td-quick" href="<?php echo esc_url( (string) $action['url'] ); ?>">
										<span class="clms-td-quick__title"><?php echo esc_html( (string) $action['label'] ); ?></span>
										<span class="clms-td-quick__text"><?php echo esc_html( (string) $action['description'] ); ?></span>
									</a>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</section>
					<?php endif; ?>

					<?php if ( $show_smart_panel ) : ?>
					<section class="clms-td-card clms-td-card--soft" id="clms-td-smart-panel">
						<div class="clms-td-section-head">
							<div>
								<div class="clms-td-eyebrow"><?php esc_html_e( 'Panel de Docencia Inteligente', 'atora-lms' ); ?></div>
								<h2 class="clms-td-title"><?php esc_html_e( 'Qué revisar hoy', 'atora-lms' ); ?></h2>
							</div>
						</div>

						<div class="clms-td-kpi-grid">
							<div class="clms-td-kpi">
								<span class="clms-td-kpi__label"><?php esc_html_e( 'Pendientes', 'atora-lms' ); ?></span>
								<strong class="clms-td-kpi__value"><?php echo esc_html( $smart_summary['pending'] ); ?></strong>
								<span class="clms-td-kpi__meta"><?php esc_html_e( 'Entregas por revisar', 'atora-lms' ); ?></span>
							</div>
							<div class="clms-td-kpi">
								<span class="clms-td-kpi__label"><?php esc_html_e( 'Urgentes o vencidas', 'atora-lms' ); ?></span>
								<strong class="clms-td-kpi__value"><?php echo esc_html( $smart_summary['urgent'] ); ?></strong>
								<span class="clms-td-kpi__meta"><?php esc_html_e( 'Requieren atención inmediata', 'atora-lms' ); ?></span>
							</div>
							<div class="clms-td-kpi">
								<span class="clms-td-kpi__label"><?php esc_html_e( 'Estudiantes en riesgo', 'atora-lms' ); ?></span>
								<strong class="clms-td-kpi__value"><?php echo esc_html( $smart_summary['at_risk'] ); ?></strong>
								<span class="clms-td-kpi__meta"><?php esc_html_e( 'Con baja actividad o desempeño', 'atora-lms' ); ?></span>
							</div>
							<div class="clms-td-kpi">
								<span class="clms-td-kpi__label"><?php esc_html_e( 'IA por validar', 'atora-lms' ); ?></span>
								<strong class="clms-td-kpi__value"><?php echo esc_html( $smart_summary['ai_pending'] ); ?></strong>
								<span class="clms-td-kpi__meta"><?php esc_html_e( 'Análisis IA con revisión docente', 'atora-lms' ); ?></span>
							</div>
							<div class="clms-td-kpi">
								<span class="clms-td-kpi__label"><?php esc_html_e( 'Alertas y mensajes', 'atora-lms' ); ?></span>
								<strong class="clms-td-kpi__value"><?php echo esc_html( $smart_summary['alerts'] ); ?></strong>
								<span class="clms-td-kpi__meta"><?php esc_html_e( 'Notificaciones operativas activas', 'atora-lms' ); ?></span>
							</div>
						</div>
						<?php if ( '' !== $academic_configuration_notice ) : ?>
							<p class="clms-td-text"><strong><?php esc_html_e( 'Aviso académico:', 'atora-lms' ); ?></strong> <?php echo esc_html( $academic_configuration_notice ); ?></p>
						<?php endif; ?>

						<?php if ( empty( $smart_queue ) ) : ?>
							<p class="clms-td-text clms-td-text--muted"><?php esc_html_e( 'No hay entregas en cola priorizada en este momento.', 'atora-lms' ); ?></p>
						<?php else : ?>
							<div class="clms-td-list">
								<?php foreach ( $smart_queue as $item ) : ?>
									<article class="clms-td-list-card">
										<div class="clms-td-list-card__head">
											<div class="clms-td-list-card__main">
												<h3 class="clms-td-list-card__title"><?php echo esc_html( $item['student_name'] ); ?></h3>
												<p class="clms-td-list-card__meta"><?php echo esc_html( $item['lesson_title'] ); ?></p>
											</div>

											<div class="clms-td-list-card__aside clms-td-list-card__aside--stack">
												<span class="clms-td-badge <?php echo esc_attr( $item['priority_class'] ); ?>"><?php echo esc_html( $item['priority_label'] ); ?></span>
												<span class="clms-td-badge is-warning"><?php echo esc_html( $item['status_label'] ); ?></span>
											</div>
										</div>

										<p class="clms-td-inline-meta">
											<?php
											echo esc_html(
												sprintf(
													/* translators: 1: course title, 2: updated datetime */
													__( '%1$s · %2$s', 'atora-lms' ),
													$item['course_title'] ? $item['course_title'] : __( 'Sin curso', 'atora-lms' ),
													$item['submitted_at']
												)
											);
											?>
										</p>
										<?php if ( ! empty( $item['priority_reason'] ) ) : ?>
											<p class="clms-td-inline-meta"><?php echo esc_html( $item['priority_reason'] ); ?></p>
										<?php endif; ?>
										<?php
										$extra_meta = array();
										if ( ! empty( $item['is_required_evidence'] ) ) {
											$extra_meta[] = __( 'Evidencia obligatoria', 'atora-lms' );
										}
										if ( ! empty( $item['competency_title'] ) ) {
											$extra_meta[] = sprintf(
												/* translators: %s: competencia */
												__( 'Competencia: %s', 'atora-lms' ),
												$item['competency_title']
											);
										}
										if ( ! empty( $item['affects_certificate'] ) ) {
											$extra_meta[] = __( 'Impacta certificación', 'atora-lms' );
										}
										?>
										<?php if ( ! empty( $extra_meta ) ) : ?>
											<p class="clms-td-inline-meta"><?php echo esc_html( implode( ' · ', $extra_meta ) ); ?></p>
										<?php endif; ?>

										<div class="clms-td-list-card__foot clms-td-list-card__foot--between">
											<?php if ( ! empty( $item['flags'] ) ) : ?>
												<span class="clms-td-inline-meta"><?php echo esc_html( implode( ' · ', $item['flags'] ) ); ?></span>
											<?php else : ?>
												<span class="clms-td-inline-meta">—</span>
											<?php endif; ?>
											<?php if ( ! empty( $item['speedgrade_url'] ) ) : ?>
												<a href="<?php echo esc_url( $item['speedgrade_url'] ); ?>" class="clms-td-btn clms-td-btn--primary"><?php esc_html_e( 'Revisar en SpeedGrade', 'atora-lms' ); ?></a>
											<?php endif; ?>
										</div>
									</article>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<div class="clms-td-grid clms-td-grid--mini">
							<details class="clms-td-card clms-td-card--soft" id="clms-td-ai">
								<summary class="clms-td-summary"><?php esc_html_e( 'Estudiantes que requieren atención', 'atora-lms' ); ?></summary>
								<?php if ( empty( $students_at_risk_queue ) ) : ?>
									<p class="clms-td-text clms-td-text--muted"><?php esc_html_e( 'No hay estudiantes en riesgo en este momento.', 'atora-lms' ); ?></p>
								<?php else : ?>
									<ul class="clms-td-list-plain">
										<?php foreach ( array_slice( $students_at_risk_queue, 0, min( 5, $risk_students_limit ) ) as $risk_item ) : ?>
											<li>
												<strong><?php echo esc_html( $risk_item['student_name'] ?? '' ); ?></strong>
												<span class="clms-td-inline-meta"><?php echo esc_html( $risk_item['reason'] ?? '' ); ?></span>
												<?php if ( ! empty( $risk_item['speedgrade_url'] ) ) : ?>
													<a class="clms-td-link" href="<?php echo esc_url( $risk_item['speedgrade_url'] ); ?>"><?php esc_html_e( 'Abrir SpeedGrade', 'atora-lms' ); ?></a>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</details>

							<details class="clms-td-card clms-td-card--soft">
								<summary class="clms-td-summary"><?php esc_html_e( 'Evaluaciones IA pendientes', 'atora-lms' ); ?></summary>
								<?php if ( empty( $ai_pending_reviews ) ) : ?>
									<p class="clms-td-text clms-td-text--muted"><?php esc_html_e( 'No hay evaluaciones IA pendientes de validación.', 'atora-lms' ); ?></p>
								<?php else : ?>
									<ul class="clms-td-list-plain">
										<?php foreach ( array_slice( $ai_pending_reviews, 0, min( 5, $ai_pending_limit ) ) as $ai_item ) : ?>
											<li>
												<strong><?php echo esc_html( $ai_item['student_name'] ?? '' ); ?></strong>
												<span class="clms-td-inline-meta"><?php echo esc_html( $ai_item['lesson_title'] ?? '' ); ?></span>
												<?php if ( ! empty( $ai_item['speedgrade_url'] ) ) : ?>
													<a class="clms-td-link" href="<?php echo esc_url( $ai_item['speedgrade_url'] ); ?>"><?php esc_html_e( 'Validar en SpeedGrade', 'atora-lms' ); ?></a>
												<?php endif; ?>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</details>
						</div>

						<?php if ( $teacher_ai_enabled ) : ?>
							<div class="clms-td-ai-actions">
								<button type="button" class="clms-td-btn clms-td-btn--secondary clms-td-ai-action" data-atora-ai-tool="analyze_group"><?php esc_html_e( 'Resumir desempeño del curso', 'atora-lms' ); ?></button>
								<button type="button" class="clms-td-btn clms-td-btn--secondary clms-td-ai-action" data-atora-ai-tool="improve_lesson"><?php esc_html_e( 'Sugerir feedback docente', 'atora-lms' ); ?></button>
								<button type="button" class="clms-td-btn clms-td-btn--secondary clms-td-ai-action" data-atora-ai-tool="analyze_group"><?php esc_html_e( 'Detectar errores comunes', 'atora-lms' ); ?></button>
								<button type="button" class="clms-td-btn clms-td-btn--secondary clms-td-ai-action" data-atora-ai-tool="plan_course"><?php esc_html_e( 'Proponer actividad de refuerzo', 'atora-lms' ); ?></button>
								<button type="button" class="clms-td-btn clms-td-btn--secondary clms-td-ai-action" data-atora-ai-tool="draft_email"><?php esc_html_e( 'Borrador para estudiantes rezagados', 'atora-lms' ); ?></button>
							</div>
						<?php else : ?>
							<p class="clms-td-text clms-td-text--muted"><?php esc_html_e( 'Activa la IA en ajustes para habilitar acciones docentes contextuales.', 'atora-lms' ); ?></p>
						<?php endif; ?>
					</section>
					<?php endif; ?>

					<?php if ( $show_pulse ) : ?>
					<section class="clms-td-card clms-td-card--soft">
						<div class="clms-td-section-head">
							<div>
								<div class="clms-td-eyebrow"><?php esc_html_e( 'Pulso del grupo', 'atora-lms' ); ?></div>
								<h2 class="clms-td-title"><?php esc_html_e( 'Salud académica del grupo', 'atora-lms' ); ?></h2>
							</div>
							<span class="clms-td-badge <?php echo $completion_rate >= 70 ? 'is-success' : 'is-warning'; ?>">
								<?php echo esc_html( $completion_rate ); ?>%
							</span>
						</div>

						<div class="clms-td-kpi-grid">
							<div class="clms-td-kpi">
								<span class="clms-td-kpi__label"><?php esc_html_e( 'Activos', 'atora-lms' ); ?></span>
								<strong class="clms-td-kpi__value"><?php echo esc_html( $active_count ); ?></strong>
								<span class="clms-td-kpi__meta">
									<?php
									printf(
										esc_html__( 'de %d inscritos', 'atora-lms' ),
										$enrolled_count
									);
									?>
								</span>
							</div>
							<div class="clms-td-kpi">
								<span class="clms-td-kpi__label"><?php esc_html_e( 'Completitud promedio', 'atora-lms' ); ?></span>
								<strong class="clms-td-kpi__value"><?php echo esc_html( $completion_rate ); ?>%</strong>
								<span class="clms-td-kpi__meta"><?php esc_html_e( 'Avance agregado', 'atora-lms' ); ?></span>
							</div>
							<div class="clms-td-kpi">
								<span class="clms-td-kpi__label"><?php esc_html_e( 'Nota promedio', 'atora-lms' ); ?></span>
								<strong class="clms-td-kpi__value"><?php echo esc_html( $avg_grade_label ); ?></strong>
								<span class="clms-td-kpi__meta"><?php esc_html_e( 'Evaluaciones calificadas', 'atora-lms' ); ?></span>
							</div>
							<div class="clms-td-kpi">
								<span class="clms-td-kpi__label"><?php esc_html_e( 'Pendientes', 'atora-lms' ); ?></span>
								<strong class="clms-td-kpi__value"><?php echo esc_html( $pending_count ); ?></strong>
								<span class="clms-td-kpi__meta"><?php esc_html_e( 'Entregas por revisar', 'atora-lms' ); ?></span>
							</div>
						</div>
						<?php if ( ! empty( $academic_report['recommendation'] ) ) : ?>
							<p class="clms-td-text"><strong><?php esc_html_e( 'Recomendación académica:', 'atora-lms' ); ?></strong> <?php echo esc_html( $academic_report['recommendation'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $academic_report['weakest_competency'] ) ) : ?>
							<p class="clms-td-text"><strong><?php esc_html_e( 'Competencia más débil del grupo:', 'atora-lms' ); ?></strong> <?php echo esc_html( $academic_report['weakest_competency'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $academic_report['strongest_competency'] ) ) : ?>
							<p class="clms-td-text"><strong><?php esc_html_e( 'Competencia más fuerte del grupo:', 'atora-lms' ); ?></strong> <?php echo esc_html( $academic_report['strongest_competency'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $academic_report['certificate_impact'] ) ) : ?>
							<p class="clms-td-text"><strong><?php esc_html_e( 'Entregas que impactan certificación:', 'atora-lms' ); ?></strong> <?php echo esc_html( absint( $academic_report['certificate_impact'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $academic_report['peer_reviews_total'] ) ) : ?>
							<p class="clms-td-text"><strong><?php esc_html_e( 'Peer reviews completadas:', 'atora-lms' ); ?></strong> <?php echo esc_html( absint( $academic_report['peer_reviews_total'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $academic_report['peer_reviews_late'] ) ) : ?>
							<p class="clms-td-text"><strong><?php esc_html_e( 'Peer reviews tardías:', 'atora-lms' ); ?></strong> <?php echo esc_html( absint( $academic_report['peer_reviews_late'] ) ); ?></p>
						<?php endif; ?>

						<div class="clms-td-progress">
							<div class="clms-td-progress__bar">
								<span style="width:<?php echo esc_attr( $completion_rate ); ?>%"></span>
							</div>
							<div class="clms-td-progress__meta">
								<?php esc_html_e( 'Completitud promedio del grupo', 'atora-lms' ); ?>
							</div>
						</div>
					</section>
					<?php endif; ?>

					<?php if ( $show_inbox ) : ?>
					<section class="clms-td-card" id="clms-td-inbox">
						<div class="clms-td-section-head">
							<div>
								<div class="clms-td-eyebrow"><?php esc_html_e( 'Bandeja', 'atora-lms' ); ?></div>
								<h2 class="clms-td-title"><?php esc_html_e( 'Bandeja de entregas', 'atora-lms' ); ?></h2>
							</div>
							<?php if ( current_user_can( 'clms_grade_submissions' ) ) : ?>
								<a class="clms-td-link" href="<?php echo esc_url( admin_url( 'admin.php?page=clms-speedgrader' ) ); ?>"><?php esc_html_e( 'Abrir SpeedGrade completo', 'atora-lms' ); ?></a>
							<?php endif; ?>
						</div>

						<?php
						$has_inbox_filters = $this->has_inbox_filters( $inbox_filters );
						$reset_url         = remove_query_arg(
							array(
								'clms_td_student',
								'clms_td_lesson',
								'clms_td_scope',
								'clms_td_status',
								'clms_td_date_from',
								'clms_td_date_to',
								'clms_td_page',
							)
						);
						?>

						<form class="clms-td-inbox-filters" method="get" action="">
							<input type="hidden" name="clms_td_page" value="1">
							<div class="clms-td-field">
								<label for="clms-td-student"><?php esc_html_e( 'Alumno', 'atora-lms' ); ?></label>
								<select id="clms-td-student" name="clms_td_student">
									<option value=""><?php esc_html_e( 'Todos', 'atora-lms' ); ?></option>
									<?php foreach ( $inbox_options['students'] as $student_id => $student_label ) : ?>
										<option value="<?php echo esc_attr( $student_id ); ?>" <?php selected( $inbox_filters['student_id'], $student_id ); ?>><?php echo esc_html( $student_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>

							<div class="clms-td-field">
								<label for="clms-td-lesson"><?php esc_html_e( 'Actividad', 'atora-lms' ); ?></label>
								<select id="clms-td-lesson" name="clms_td_lesson">
									<option value=""><?php esc_html_e( 'Todas', 'atora-lms' ); ?></option>
									<?php foreach ( $inbox_options['lessons'] as $lesson_id => $lesson_label ) : ?>
										<option value="<?php echo esc_attr( $lesson_id ); ?>" <?php selected( $inbox_filters['lesson_id'], $lesson_id ); ?>><?php echo esc_html( $lesson_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>

							<div class="clms-td-field">
								<label for="clms-td-scope"><?php esc_html_e( 'Curso / programa', 'atora-lms' ); ?></label>
								<select id="clms-td-scope" name="clms_td_scope">
									<option value=""><?php esc_html_e( 'Todos', 'atora-lms' ); ?></option>
									<?php if ( ! empty( $inbox_options['programs'] ) ) : ?>
										<optgroup label="<?php echo esc_attr__( 'Programas', 'atora-lms' ); ?>">
											<?php foreach ( $inbox_options['programs'] as $program_id => $program_label ) : ?>
												<?php $value = 'program-' . absint( $program_id ); ?>
												<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $inbox_filters['scope'], $value ); ?>><?php echo esc_html( $program_label ); ?></option>
											<?php endforeach; ?>
										</optgroup>
									<?php endif; ?>
									<?php if ( ! empty( $inbox_options['courses'] ) ) : ?>
										<optgroup label="<?php echo esc_attr__( 'Cursos', 'atora-lms' ); ?>">
											<?php foreach ( $inbox_options['courses'] as $course_id => $course_label ) : ?>
												<?php $value = 'course-' . absint( $course_id ); ?>
												<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $inbox_filters['scope'], $value ); ?>><?php echo esc_html( $course_label ); ?></option>
											<?php endforeach; ?>
										</optgroup>
									<?php endif; ?>
								</select>
							</div>

							<div class="clms-td-field">
								<label for="clms-td-date-from"><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></label>
								<div class="clms-td-date-row">
									<input type="date" id="clms-td-date-from" name="clms_td_date_from" value="<?php echo esc_attr( $inbox_filters['date_from'] ); ?>" placeholder="<?php echo esc_attr__( 'Desde', 'atora-lms' ); ?>">
									<input type="date" id="clms-td-date-to" name="clms_td_date_to" value="<?php echo esc_attr( $inbox_filters['date_to'] ); ?>" placeholder="<?php echo esc_attr__( 'Hasta', 'atora-lms' ); ?>">
								</div>
							</div>

							<div class="clms-td-field">
								<label for="clms-td-status"><?php esc_html_e( 'Estado', 'atora-lms' ); ?></label>
								<select id="clms-td-status" name="clms_td_status">
									<option value=""><?php esc_html_e( 'Pendientes', 'atora-lms' ); ?></option>
									<option value="all" <?php selected( $inbox_filters['status'], 'all' ); ?>><?php esc_html_e( 'Todos', 'atora-lms' ); ?></option>
									<option value="submitted" <?php selected( $inbox_filters['status'], 'submitted' ); ?>><?php esc_html_e( 'Enviadas', 'atora-lms' ); ?></option>
									<option value="in_review" <?php selected( $inbox_filters['status'], 'in_review' ); ?>><?php esc_html_e( 'En revisión', 'atora-lms' ); ?></option>
									<option value="graded" <?php selected( $inbox_filters['status'], 'graded' ); ?>><?php esc_html_e( 'Calificadas', 'atora-lms' ); ?></option>
								</select>
							</div>

							<div class="clms-td-filter-actions">
								<button type="submit" class="clms-td-btn clms-td-btn--secondary"><?php esc_html_e( 'Aplicar filtros', 'atora-lms' ); ?></button>
								<?php if ( $has_inbox_filters ) : ?>
									<a class="clms-td-link" href="<?php echo esc_url( $reset_url ); ?>"><?php esc_html_e( 'Limpiar', 'atora-lms' ); ?></a>
								<?php endif; ?>
							</div>
						</form>

						<?php if ( empty( $inbox_items ) ) : ?>
							<div class="atora-empty-state">
								<svg class="atora-empty-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
								<p class="atora-empty-state__title"><?php esc_html_e( 'Sin entregas pendientes', 'atora-lms' ); ?></p>
								<p class="atora-empty-state__text"><?php esc_html_e( 'Tus estudiantes están al día. Vuelve a revisar más tarde.', 'atora-lms' ); ?></p>
							</div>
						<?php else : ?>
							<div class="clms-td-table-wrap">
								<table class="clms-td-table">
									<thead>
										<tr>
											<th><?php esc_html_e( 'Alumno', 'atora-lms' ); ?></th>
											<th><?php esc_html_e( 'Actividad', 'atora-lms' ); ?></th>
											<th><?php esc_html_e( 'Curso / programa', 'atora-lms' ); ?></th>
											<th><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th>
											<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
											<th><?php esc_html_e( 'Acción', 'atora-lms' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $inbox_items as $item ) :
											$hours_waiting = isset( $item['submitted_timestamp'] ) && $item['submitted_timestamp']
												? (int) floor( ( time() - $item['submitted_timestamp'] ) / 3600 )
												: 0;
											$is_urgent = $hours_waiting >= 48;
											$row_link  = ! empty( $item['speedgrade_url'] ) ? $item['speedgrade_url'] : '';
										?>
											<tr <?php echo $row_link ? 'data-atora-row-link="' . esc_url( $row_link ) . '" tabindex="0" role="link"' : ''; ?> class="<?php echo $is_urgent ? 'clms-td-row-urgent' : ''; ?>">
												<td>
													<strong><?php echo esc_html( $item['student_name'] ); ?></strong>
													<?php if ( ! empty( $item['student_email'] ) ) : ?>
														<span class="clms-td-table-meta"><?php echo esc_html( $item['student_email'] ); ?></span>
													<?php endif; ?>
												</td>
												<td>
													<strong><?php echo esc_html( $item['lesson_title'] ); ?></strong>
													<?php if ( ! empty( $item['lesson_url'] ) ) : ?>
														<a class="clms-td-link" href="<?php echo esc_url( $item['lesson_url'] ); ?>"><?php esc_html_e( 'Ver lección', 'atora-lms' ); ?></a>
													<?php endif; ?>
												</td>
												<td>
													<?php echo esc_html( $item['course_title'] ? $item['course_title'] : '—' ); ?>
													<?php if ( ! empty( $item['program_titles'] ) ) : ?>
														<span class="clms-td-table-meta"><?php echo esc_html( $item['program_titles'] ); ?></span>
													<?php endif; ?>
												</td>
												<td>
													<?php echo esc_html( $item['submitted_at'] ? $item['submitted_at'] : '—' ); ?>
													<?php if ( $hours_waiting > 0 ) : ?>
														<span class="clms-td-table-meta">
															<?php
															if ( $hours_waiting >= 24 ) {
																printf(
																	esc_html__( '(hace %s días)', 'atora-lms' ),
																	esc_html( (int) floor( $hours_waiting / 24 ) )
																);
															} else {
																printf(
																	esc_html__( '(hace %s h)', 'atora-lms' ),
																	esc_html( $hours_waiting )
																);
															}
															?>
														</span>
													<?php endif; ?>
												</td>
												<td>
													<?php if ( $is_urgent ) : ?>
														<span class="clms-td-badge is-urgent"><?php esc_html_e( 'Urgente', 'atora-lms' ); ?></span>
													<?php endif; ?>
													<span class="clms-td-badge is-warning"><?php echo esc_html( $item['status_label'] ); ?></span>
												</td>
												<td>
													<?php if ( ! empty( $item['speedgrade_url'] ) ) : ?>
														<a class="clms-td-btn clms-td-btn--primary" href="<?php echo esc_url( $item['speedgrade_url'] ); ?>"><?php esc_html_e( 'Revisar', 'atora-lms' ); ?></a>
													<?php else : ?>
														<span class="clms-td-inline-meta">—</span>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>

							<?php if ( $inbox_data['pages'] > 1 ) : ?>
								<?php
								$pagination_base = remove_query_arg( 'clms_td_page' );
								$prev_url        = $inbox_data['page'] > 1 ? add_query_arg( 'clms_td_page', $inbox_data['page'] - 1, $pagination_base ) : '';
								$next_url        = $inbox_data['page'] < $inbox_data['pages'] ? add_query_arg( 'clms_td_page', $inbox_data['page'] + 1, $pagination_base ) : '';
								?>
								<div class="clms-td-pagination">
									<span class="clms-td-inline-meta">
										<?php
										printf(
											esc_html__( 'Página %1$s de %2$s (%3$s entregas)', 'atora-lms' ),
											esc_html( $inbox_data['page'] ),
											esc_html( $inbox_data['pages'] ),
											esc_html( $inbox_data['total'] )
										);
										?>
									</span>
									<div class="clms-td-pagination__actions">
										<?php if ( $prev_url ) : ?>
											<a class="clms-td-btn clms-td-btn--secondary" href="<?php echo esc_url( $prev_url ); ?>"><?php esc_html_e( 'Anterior', 'atora-lms' ); ?></a>
										<?php endif; ?>
										<?php if ( $next_url ) : ?>
											<a class="clms-td-btn clms-td-btn--secondary" href="<?php echo esc_url( $next_url ); ?>"><?php esc_html_e( 'Siguiente', 'atora-lms' ); ?></a>
										<?php endif; ?>
									</div>
								</div>
							<?php endif; ?>
						<?php endif; ?>
					</section>
					<?php endif; ?>

					<?php if ( $show_grid ) : ?>
					<div class="clms-td-grid-2">
						<?php if ( $show_reviewed ) : ?>
						<section class="clms-td-card" id="clms-td-reviewed">
							<div class="clms-td-section-head">
								<div>
									<div class="clms-td-eyebrow"><?php esc_html_e( 'Seguimiento', 'atora-lms' ); ?></div>
									<h2 class="clms-td-title"><?php esc_html_e( 'Entregas revisadas recientemente', 'atora-lms' ); ?></h2>
								</div>
							</div>

							<?php if ( empty( $reviewed_items ) ) : ?>
								<p class="clms-td-text clms-td-text--muted"><?php esc_html_e( 'Aquí aparecerán las entregas que ya revisaste.', 'atora-lms' ); ?></p>
							<?php else : ?>
								<details class="clms-td-details">
									<summary><?php esc_html_e( 'Ver revisiones recientes', 'atora-lms' ); ?></summary>
									<div class="clms-td-list">
										<?php foreach ( $reviewed_items as $item ) : ?>
											<article class="clms-td-list-card">
												<div class="clms-td-list-card__head">
													<div class="clms-td-list-card__main">
														<h3 class="clms-td-list-card__title"><?php echo esc_html( $item['student_name'] ); ?></h3>
														<p class="clms-td-list-card__meta"><?php echo esc_html( $item['lesson_title'] ); ?></p>
													</div>

													<div class="clms-td-list-card__aside clms-td-list-card__aside--stack">
														<span class="clms-td-badge is-success"><?php echo esc_html( $item['status_label'] ); ?></span>
														<?php if ( '' !== (string) $item['grade'] ) : ?>
															<span class="clms-td-grade"><?php echo esc_html( $item['grade'] ); ?>/100</span>
														<?php endif; ?>
													</div>
												</div>

												<?php if ( ! empty( $item['feedback'] ) ) : ?>
													<p class="clms-td-text"><?php echo esc_html( $this->truncate_text( $item['feedback'], 160 ) ); ?></p>
												<?php endif; ?>

												<div class="clms-td-list-card__foot clms-td-list-card__foot--between">
													<span class="clms-td-inline-meta"><?php echo esc_html( $item['updated_at'] ); ?></span>
													<?php if ( ! empty( $item['speedgrade_url'] ) ) : ?>
														<a href="<?php echo esc_url( $item['speedgrade_url'] ); ?>" class="clms-td-link"><?php esc_html_e( 'Abrir SpeedGrade', 'atora-lms' ); ?></a>
													<?php endif; ?>
												</div>
											</article>
										<?php endforeach; ?>
									</div>
								</details>
							<?php endif; ?>
						</section>
						<?php endif; ?>

						<?php if ( $show_recent_lessons ) : ?>
						<section class="clms-td-card" id="clms-td-lessons">
							<div class="clms-td-section-head">
								<div>
									<div class="clms-td-eyebrow"><?php esc_html_e( 'Contenido', 'atora-lms' ); ?></div>
									<h2 class="clms-td-title"><?php esc_html_e( 'Lecciones recientes', 'atora-lms' ); ?></h2>
								</div>
							</div>

							<?php if ( empty( $recent_lessons ) ) : ?>
								<p class="clms-td-text clms-td-text--muted"><?php esc_html_e( 'No encontramos lecciones asignadas todavía.', 'atora-lms' ); ?></p>
							<?php else : ?>
								<div class="clms-td-mini-list">
									<?php foreach ( $recent_lessons as $item ) : ?>
										<article class="clms-td-mini">
											<div class="clms-td-mini__content">
												<h3 class="clms-td-mini__title"><?php echo esc_html( $item['lesson_title'] ); ?></h3>
												<p class="clms-td-mini__meta"><?php echo esc_html( $item['course_title'] ); ?></p>
												<?php if ( ! empty( $item['due_date'] ) ) : ?>
													<p class="clms-td-mini__date"><?php echo esc_html( $item['due_date'] ); ?></p>
												<?php endif; ?>
											</div>

											<?php if ( ! empty( $item['lesson_url'] ) ) : ?>
												<a href="<?php echo esc_url( $item['lesson_url'] ); ?>" class="clms-td-link"><?php esc_html_e( 'Ver', 'atora-lms' ); ?></a>
											<?php endif; ?>
										</article>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</section>
						<?php endif; ?>
					</div>
					<?php endif; ?>

					<?php if ( $show_courses ) : ?>
					<section class="clms-td-card" id="clms-td-courses">
						<div class="clms-td-section-head">
							<div>
								<div class="clms-td-eyebrow"><?php esc_html_e( 'Gestión', 'atora-lms' ); ?></div>
								<h2 class="clms-td-title"><?php esc_html_e( 'Mis cursos', 'atora-lms' ); ?></h2>
							</div>
						</div>

						<?php if ( empty( $course_cards ) ) : ?>
							<p class="clms-td-text clms-td-text--muted"><?php esc_html_e( 'No tienes cursos asignados.', 'atora-lms' ); ?></p>
						<?php else : ?>
							<div class="clms-td-courses">
								<?php foreach ( array_slice( $course_cards, 0, $course_cards_limit ) as $item ) : ?>
									<article class="clms-td-course">
										<div class="clms-td-course__body">
											<h3 class="clms-td-course__title"><?php echo esc_html( $item['title'] ); ?></h3>
											<p class="clms-td-course__meta"><?php echo esc_html( $item['meta'] ); ?></p>
										</div>

										<div class="clms-td-course__actions">
											<a href="<?php echo esc_url( $item['course_url'] ); ?>" class="clms-td-btn clms-td-btn--secondary"><?php esc_html_e( 'Ver curso', 'atora-lms' ); ?></a>

											<?php if ( ! empty( $item['first_lesson_url'] ) ) : ?>
												<a href="<?php echo esc_url( $item['first_lesson_url'] ); ?>" class="clms-td-btn clms-td-btn--primary"><?php esc_html_e( 'Abrir lección', 'atora-lms' ); ?></a>
											<?php endif; ?>
										</div>
									</article>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</section>
					<?php endif; ?>

					<?php if ( $show_students ) : ?>
					<section class="clms-td-card" id="clms-td-students">
						<div class="clms-td-section-head">
							<div>
								<div class="clms-td-eyebrow"><?php esc_html_e( 'Acompañamiento', 'atora-lms' ); ?></div>
								<h2 class="clms-td-title"><?php esc_html_e( 'Lista de alumnos', 'atora-lms' ); ?></h2>
							</div>
						</div>

						<?php if ( empty( $course_ids ) ) : ?>
							<p class="clms-td-text clms-td-text--muted"><?php esc_html_e( 'No tienes cursos para gestionar alumnos.', 'atora-lms' ); ?></p>
						<?php else : ?>
							<div class="clms-td-students-head">
								<label for="clms-td-course-switch"><?php esc_html_e( 'Curso', 'atora-lms' ); ?></label>
								<select id="clms-td-course-switch" class="clms-td-select" aria-label="<?php esc_attr_e( 'Seleccionar curso', 'atora-lms' ); ?>">
									<?php foreach ( $course_ids as $course_id ) : ?>
										<option value="<?php echo esc_attr( absint( $course_id ) ); ?>" <?php selected( $selected_course_id, absint( $course_id ) ); ?>>
											<?php echo esc_html( get_the_title( $course_id ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>

							<?php if ( empty( $student_rows ) ) : ?>
								<p class="clms-td-text clms-td-text--muted"><?php esc_html_e( 'No hay estudiantes inscritos en este curso.', 'atora-lms' ); ?></p>
							<?php else : ?>
								<div
									class="clms-td-students-toolbar"
									id="clms-td-bulk-toolbar"
									data-course-id="<?php echo esc_attr( $selected_course_id ); ?>"
									hidden
								>
									<span id="clms-td-bulk-count"><?php esc_html_e( '0 seleccionados', 'atora-lms' ); ?></span>
									<button type="button" class="clms-td-btn clms-td-btn--secondary clms-td-bulk-btn" data-action="message"><?php esc_html_e( 'Enviar mensaje', 'atora-lms' ); ?></button>
									<button type="button" class="clms-td-btn clms-td-btn--secondary clms-td-bulk-btn" data-action="export_csv"><?php esc_html_e( 'Exportar CSV', 'atora-lms' ); ?></button>
									<button type="button" class="clms-td-btn clms-td-btn--secondary clms-td-bulk-btn clms-td-bulk-danger" data-action="flag_followup"><?php esc_html_e( 'Marcar seguimiento', 'atora-lms' ); ?></button>
									<button type="button" class="clms-td-btn clms-td-btn--secondary clms-td-bulk-btn clms-td-bulk-danger" data-action="unenroll"><?php esc_html_e( 'Desmatricular', 'atora-lms' ); ?></button>
								</div>

								<div class="clms-td-table-wrap">
									<table class="clms-td-table clms-td-students-table" data-course-id="<?php echo esc_attr( $selected_course_id ); ?>">
										<thead>
											<tr>
												<th><input type="checkbox" id="clms-td-select-all" aria-label="<?php esc_attr_e( 'Seleccionar todos', 'atora-lms' ); ?>"></th>
												<th><?php esc_html_e( 'Alumno', 'atora-lms' ); ?></th>
												<th><?php esc_html_e( 'Progreso', 'atora-lms' ); ?></th>
												<th><?php esc_html_e( 'Última actividad', 'atora-lms' ); ?></th>
												<th><?php esc_html_e( 'Nota promedio', 'atora-lms' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $student_rows as $row ) : ?>
												<tr class="<?php echo ! empty( $row['flagged_followup'] ) ? 'clms-td-row-followup' : ''; ?>">
													<td>
														<input type="checkbox" class="clms-td-student-check" value="<?php echo esc_attr( $row['id'] ); ?>" aria-label="<?php esc_attr_e( 'Seleccionar alumno', 'atora-lms' ); ?>">
													</td>
													<td>
														<strong><?php echo esc_html( $row['name'] ); ?></strong>
														<?php if ( ! empty( $row['email'] ) ) : ?>
															<span class="clms-td-table-meta"><?php echo esc_html( $row['email'] ); ?></span>
														<?php endif; ?>
													</td>
													<td><?php echo esc_html( $row['progress'] ); ?>%</td>
													<td><?php echo esc_html( $row['last_activity'] ); ?></td>
													<td><?php echo esc_html( $row['average_grade'] ); ?></td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							<?php endif; ?>
						<?php endif; ?>
					</section>
					<?php endif; ?>
				</main>

				<aside class="clms-td-side">
					<?php if ( $show_priority ) : ?>
					<section class="clms-td-card clms-td-card--accent">
						<div class="clms-td-eyebrow clms-td-eyebrow--light"><?php esc_html_e( 'Prioridad', 'atora-lms' ); ?></div>

						<?php if ( ! empty( $speedgrade_focus ) ) : ?>
							<h2 class="clms-td-title clms-td-title--light"><?php echo esc_html( $speedgrade_focus['student_name'] ); ?></h2>
							<p class="clms-td-text clms-td-text--light"><?php echo esc_html( $speedgrade_focus['lesson_title'] ); ?></p>
							<?php if ( ! empty( $speedgrade_focus['course_title'] ) ) : ?>
								<p class="clms-td-inline-meta clms-td-inline-meta--light"><?php echo esc_html( $speedgrade_focus['course_title'] ); ?></p>
							<?php endif; ?>

							<div class="clms-td-card__actions">
								<?php if ( ! empty( $speedgrade_focus['speedgrade_url'] ) ) : ?>
									<a href="<?php echo esc_url( $speedgrade_focus['speedgrade_url'] ); ?>" class="clms-td-btn clms-td-btn--white"><?php esc_html_e( 'Abrir SpeedGrade', 'atora-lms' ); ?></a>
								<?php endif; ?>
							</div>
						<?php else : ?>
							<h2 class="clms-td-title clms-td-title--light"><?php esc_html_e( 'No hay entregas urgentes ahora mismo', 'atora-lms' ); ?></h2>
							<p class="clms-td-text clms-td-text--light"><?php esc_html_e( 'Cuando entren nuevas tareas por revisar, aparecerán aquí.', 'atora-lms' ); ?></p>
						<?php endif; ?>
					</section>
					<?php endif; ?>

					<?php if ( $show_incidents ) : ?>
					<section class="clms-td-card">
						<div class="clms-td-section-head">
							<div>
								<div class="clms-td-eyebrow"><?php esc_html_e( 'Incidencias', 'atora-lms' ); ?></div>
								<h2 class="clms-td-title"><?php esc_html_e( 'Alertas del grupo', 'atora-lms' ); ?></h2>
							</div>
						</div>

						<?php
						$has_incidents = false;
						foreach ( $incident_cards as $card ) {
							if ( 'warning' === $card['status'] && $card['value'] > 0 ) {
								$has_incidents = true;
								break;
							}
						}
						?>

						<?php if ( ! $has_incidents ) : ?>
							<p class="clms-td-text clms-td-text--muted"><?php esc_html_e( 'No hay incidencias críticas detectadas.', 'atora-lms' ); ?></p>
						<?php endif; ?>

						<div class="clms-td-incident-grid">
							<?php foreach ( $incident_cards as $card ) : ?>
								<div class="clms-td-incident is-<?php echo esc_attr( $card['status'] ); ?>">
									<span class="clms-td-incident__label"><?php echo esc_html( $card['label'] ); ?></span>
									<strong class="clms-td-incident__value"><?php echo esc_html( $card['value'] ); ?></strong>
									<span class="clms-td-incident__note"><?php echo esc_html( $card['note'] ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					</section>
					<?php endif; ?>

					<?php if ( $show_summary ) : ?>
					<section class="clms-td-card">
						<div class="clms-td-section-head">
							<div>
								<div class="clms-td-eyebrow"><?php esc_html_e( 'Resumen', 'atora-lms' ); ?></div>
								<h2 class="clms-td-title"><?php esc_html_e( 'Tu operación docente', 'atora-lms' ); ?></h2>
							</div>
						</div>

						<div class="clms-td-summary">
							<div class="clms-td-summary__row">
								<span><?php esc_html_e( 'Cursos', 'atora-lms' ); ?></span>
								<strong><?php echo esc_html( $metrics['courses'] ); ?></strong>
							</div>
							<div class="clms-td-summary__row">
								<span><?php esc_html_e( 'Lecciones', 'atora-lms' ); ?></span>
								<strong><?php echo esc_html( $metrics['lessons'] ); ?></strong>
							</div>
							<div class="clms-td-summary__row">
								<span><?php esc_html_e( 'Pendientes', 'atora-lms' ); ?></span>
								<strong><?php echo esc_html( $metrics['pending_reviews'] ); ?></strong>
							</div>
							<div class="clms-td-summary__row">
								<span><?php esc_html_e( 'Revisadas', 'atora-lms' ); ?></span>
								<strong><?php echo esc_html( $metrics['reviewed'] ); ?></strong>
							</div>
							<?php if ( ! empty( $competency_overview['strongest'] ) ) : ?>
								<div class="clms-td-summary__row">
									<span><?php esc_html_e( 'Competencia más fuerte', 'atora-lms' ); ?></span>
									<strong><?php echo esc_html( $competency_overview['strongest'] ); ?></strong>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $competency_overview['weakest'] ) ) : ?>
								<div class="clms-td-summary__row">
									<span><?php esc_html_e( 'Competencia más débil', 'atora-lms' ); ?></span>
									<strong><?php echo esc_html( $competency_overview['weakest'] ); ?></strong>
								</div>
							<?php endif; ?>
						</div>
					</section>
					<?php endif; ?>

					<?php if ( $show_notifications ) : ?>
					<section class="clms-td-card">
						<div class="clms-td-section-head">
							<div>
								<div class="clms-td-eyebrow"><?php esc_html_e( 'Notificaciones', 'atora-lms' ); ?></div>
								<h2 class="clms-td-title"><?php esc_html_e( 'Pendientes por revisar', 'atora-lms' ); ?></h2>
							</div>
						</div>

						<?php if ( empty( $notifications ) ) : ?>
							<p class="clms-td-text clms-td-text--muted"><?php esc_html_e( 'No tienes notificaciones pendientes.', 'atora-lms' ); ?></p>
						<?php else : ?>
							<details class="clms-td-details">
								<summary><?php esc_html_e( 'Ver notificaciones', 'atora-lms' ); ?></summary>
								<div class="clms-td-notify-list">
									<?php foreach ( $notifications as $notice ) : ?>
										<article class="clms-td-notify">
											<strong><?php echo esc_html( $notice['title'] ); ?></strong>
											<?php if ( ! empty( $notice['message'] ) ) : ?>
												<p class="clms-td-inline-meta"><?php echo esc_html( $notice['message'] ); ?></p>
											<?php endif; ?>
											<?php if ( ! empty( $notice['created_at'] ) ) : ?>
												<span class="clms-td-inline-meta"><?php echo esc_html( $notice['created_at'] ); ?></span>
											<?php endif; ?>
											<div class="clms-td-list-card__foot">
												<?php if ( ! empty( $notice['speedgrade_url'] ) ) : ?>
													<a class="clms-td-link" href="<?php echo esc_url( $notice['speedgrade_url'] ); ?>"><?php esc_html_e( 'Abrir SpeedGrade', 'atora-lms' ); ?></a>
												<?php elseif ( ! empty( $notice['link'] ) ) : ?>
													<a class="clms-td-link" href="<?php echo esc_url( $notice['link'] ); ?>"><?php esc_html_e( 'Ver detalle', 'atora-lms' ); ?></a>
												<?php endif; ?>
											</div>
										</article>
									<?php endforeach; ?>
								</div>
							</details>
						<?php endif; ?>
					</section>
					<?php endif; ?>
				</aside>
			</div>
		</div>

		<script>
		(function () {
			var assistantBtn = document.getElementById('clms-td-open-assistant');
			if (assistantBtn) {
				assistantBtn.addEventListener('click', function () {
					var panel = document.getElementById('clms-ta-panel');
					var body  = document.getElementById('clms-ta-body');
					if (panel) {
						panel.classList.remove('clms-ta-minimized');
						if (body) body.removeAttribute('hidden');
						panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
					}
				});
			}

			Array.prototype.slice.call(document.querySelectorAll('.clms-td-ai-action')).forEach(function (button) {
				button.addEventListener('click', function () {
					var tool = button.getAttribute('data-atora-ai-tool');
					var panel = document.getElementById('clms-ta-panel');
					var body = document.getElementById('clms-ta-body');
					if (panel) {
						panel.classList.remove('clms-ta-minimized');
						if (body) body.removeAttribute('hidden');
						panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
					}

					if (!tool) {
						return;
					}

					var toolButton = document.querySelector('.clms-ta-tool-btn[data-tool="' + tool + '"]');
					if (toolButton) {
						toolButton.click();
					}
				});
			});

			var courseSwitch = document.getElementById('clms-td-course-switch');
			if (courseSwitch) {
				courseSwitch.addEventListener('change', function () {
					try {
						var url = new URL(window.location.href);
						if (this.value) {
							url.searchParams.set('clms_td_course', this.value);
						} else {
							url.searchParams.delete('clms_td_course');
						}
						window.location.href = url.toString();
					} catch (err) {}
				});
			}

			var table = document.querySelector('.clms-td-students-table');
			var toolbar = document.getElementById('clms-td-bulk-toolbar');
			if (!table || !toolbar) {
				return;
			}

			var selectAll = document.getElementById('clms-td-select-all');
			var countEl = document.getElementById('clms-td-bulk-count');
			var msgNoEndpoint = '<?php echo esc_js( __( 'No se detectó la configuración REST. Recarga la página e inténtalo de nuevo.', 'atora-lms' ) ); ?>';
			var msgGenericError = '<?php echo esc_js( __( 'No se pudo ejecutar la acción.', 'atora-lms' ) ); ?>';
			var msgPrompt = '<?php echo esc_js( __( 'Escribe el mensaje para los alumnos seleccionados:', 'atora-lms' ) ); ?>';
			var msgConfirmUnenroll = '<?php echo esc_js( __( '¿Desmatricular a %d alumno(s)? Esta acción no se puede deshacer.', 'atora-lms' ) ); ?>';
			var msgSelectedSingular = '<?php echo esc_js( __( '%d seleccionado', 'atora-lms' ) ); ?>';
			var msgSelectedPlural = '<?php echo esc_js( __( '%d seleccionados', 'atora-lms' ) ); ?>';

			function getChecks() {
				return Array.prototype.slice.call(table.querySelectorAll('.clms-td-student-check'));
			}

			function getSelectedIds() {
				return getChecks()
					.filter(function (input) { return input.checked; })
					.map(function (input) { return parseInt(input.value, 10); })
					.filter(function (value) { return value > 0; });
			}

			function updateToolbar() {
				var selected = getSelectedIds();
				var count = selected.length;
				toolbar.hidden = count === 0;
				if (countEl) {
					var template = count === 1 ? msgSelectedSingular : msgSelectedPlural;
					countEl.textContent = template.replace('%d', String(count));
				}
				if (selectAll) {
					var allChecks = getChecks();
					var allChecked = allChecks.length > 0 && allChecks.every(function (input) { return input.checked; });
					selectAll.checked = allChecked;
				}
			}

			if (selectAll) {
				selectAll.addEventListener('change', function () {
					getChecks().forEach(function (input) {
						input.checked = !!selectAll.checked;
					});
					updateToolbar();
				});
			}

			table.addEventListener('change', function (event) {
				var target = event.target;
				if (!target || !target.classList || !target.classList.contains('clms-td-student-check')) {
					return;
				}
				updateToolbar();
			});

			function resetSelection() {
				if (selectAll) {
					selectAll.checked = false;
				}
				getChecks().forEach(function (input) {
					input.checked = false;
				});
				updateToolbar();
			}

			function downloadCsv(payload) {
				if (!payload || !payload.csv_base64) {
					return;
				}
				try {
					var binary = window.atob(payload.csv_base64);
					var len = binary.length;
					var bytes = new Uint8Array(len);
					for (var i = 0; i < len; i += 1) {
						bytes[i] = binary.charCodeAt(i);
					}
					var blob = new Blob([bytes], { type: 'text/csv;charset=utf-8;' });
					var url = window.URL.createObjectURL(blob);
					var a = document.createElement('a');
					a.href = url;
					a.download = payload.filename || 'atora-students.csv';
					document.body.appendChild(a);
					a.click();
					document.body.removeChild(a);
					window.URL.revokeObjectURL(url);
				} catch (err) {}
			}

			function runBulkAction(action, extra) {
				var ids = getSelectedIds();
				if (!ids.length) {
					return;
				}

				var rest = (window.ATORA && window.ATORA.ui && window.ATORA.ui.getRestConfig)
					? window.ATORA.ui.getRestConfig()
					: ((window.ATORA && window.ATORA.rest) ? window.ATORA.rest : {});
				var endpoint = rest && rest.root ? rest.root.replace(/\/$/, '') + '/students/bulk-action' : '';
				if (!endpoint) {
					window.alert(msgNoEndpoint);
					return;
				}

				var courseId = parseInt(toolbar.getAttribute('data-course-id') || table.getAttribute('data-course-id') || '0', 10);
				var body = Object.assign({
					action: action,
					student_ids: ids,
					course_id: courseId
				}, extra || {});

				fetch(endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': rest.nonce || ''
					},
					body: JSON.stringify(body)
				})
					.then(function (response) {
						return response.json().catch(function () { return {}; }).then(function (json) {
							return { ok: response.ok, json: json };
						});
					})
					.then(function (result) {
						if (!result.ok || !result.json || result.json.success === false) {
							throw new Error(result.json && result.json.message ? result.json.message : msgGenericError);
						}

						if ('export_csv' === action) {
							downloadCsv(result.json);
						}

						window.alert(result.json.message || '<?php echo esc_js( __( 'Acción completada.', 'atora-lms' ) ); ?>');

						if ('unenroll' === action) {
							window.location.reload();
							return;
						}

						resetSelection();
					})
					.catch(function (error) {
						window.alert(error && error.message ? error.message : msgGenericError);
					});
			}

			Array.prototype.slice.call(toolbar.querySelectorAll('.clms-td-bulk-btn')).forEach(function (button) {
				button.addEventListener('click', function () {
					var action = button.getAttribute('data-action');
					if (!action) {
						return;
					}

					if ('message' === action) {
						var text = window.prompt(msgPrompt, '');
						if (!text) {
							return;
						}
						runBulkAction(action, { message_text: text });
						return;
					}

					if ('unenroll' === action) {
						var selected = getSelectedIds();
						var confirmMsg = msgConfirmUnenroll.replace('%d', String(selected.length));
						if (!window.confirm(confirmMsg)) {
							return;
						}
					}

					runBulkAction(action, {});
				});
			});

			updateToolbar();
		})();

		(function () {
			var tableRows = document.querySelectorAll('[data-atora-row-link]');
			if (!tableRows.length || !(window.ATORA && window.ATORA.ui && window.ATORA.ui.bindRowLinks)) {
				return;
			}
			window.ATORA.ui.bindRowLinks('[data-atora-row-link]');
		})();

		(function () {
			var panel = document.getElementById('clms-ta-panel');
			if (!panel) {
				return;
			}
			var body = document.getElementById('clms-ta-body');
			var btn = document.getElementById('clms-td-open-assistant');
			if (!btn) {
				return;
			}
			btn.addEventListener('keydown', function (event) {
				if (event.key !== 'Enter' && event.key !== ' ') {
					return;
				}
				event.preventDefault();
				var panel = document.getElementById('clms-ta-panel');
				if (panel) {
					panel.classList.remove('clms-ta-minimized');
					if (body) body.removeAttribute('hidden');
					panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
				}
			});
		})();
		</script>
		<?php

		return ob_get_clean();
	}

}
