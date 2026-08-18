<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Admin_Menu_Widgets_And_Hubs_Trait {
	public function register_wp_dashboard_widgets(): void {
		if ( ! current_user_can( 'read' ) ) {
			return;
		}

		$role = $this->get_current_role_context();

		// Quitar widgets de WP que no aportan al flujo LMS
		remove_meta_box( 'dashboard_primary', 'dashboard', 'side' ); // WordPress events & news
		remove_action( 'welcome_panel', 'wp_welcome_panel' );        // "Bienvenido a WordPress"

		// Widget 1: Pulso académico (columna principal, alta prioridad — todos los roles)
		wp_add_dashboard_widget(
			'atora_pulso',
			esc_html__( 'ATORA · Pulso académico', 'atora-lms' ),
			array( $this, 'render_wp_widget_pulso' ),
			null, null, 'normal', 'high'
		);

		// Widget 2: Cola de evaluación (sidebar) — instructor+
		if ( in_array( $role, array( 'admin', 'instructor' ), true ) ) {
			wp_add_dashboard_widget(
				'atora_evaluacion',
				esc_html__( 'ATORA · Cola de evaluación', 'atora-lms' ),
				array( $this, 'render_wp_widget_evaluacion' ),
				null, null, 'side', 'high'
			);
		}

			// Widget 3: Comercial (columna principal) — admin + collaborator
			if ( in_array( $role, array( 'admin', 'collaborator' ), true ) ) {
				wp_add_dashboard_widget(
					'atora_comercio',
					esc_html__( 'ATORA · Comercial', 'atora-lms' ),
					array( $this, 'render_wp_widget_comercio' ),
					null, null, 'normal', 'default'
				);
			}

		// Widget 4: Mensajes (sidebar — todos los roles)
		wp_add_dashboard_widget(
			'atora_mensajes',
			esc_html__( 'ATORA · Mensajes', 'atora-lms' ),
			array( $this, 'render_wp_widget_mensajes' ),
			null, null, 'side', 'default'
		);

		// Widget 5: Acciones operativas (columna principal) — admin/instructor/collaborator
		if ( in_array( $role, array( 'admin', 'instructor', 'collaborator' ), true ) ) {
			wp_add_dashboard_widget(
				'atora_acciones_operativas',
				esc_html__( 'ATORA · Acciones operativas', 'atora-lms' ),
				array( $this, 'render_wp_widget_acciones_operativas' ),
				null, null, 'normal', 'high'
			);
		}

		// Widget 6: Estudiantes en foco (columna principal) — admin/instructor
		if ( in_array( $role, array( 'admin', 'instructor' ), true ) ) {
			wp_add_dashboard_widget(
				'atora_estudiantes_foco',
				esc_html__( 'ATORA · Estudiantes en foco', 'atora-lms' ),
				array( $this, 'render_wp_widget_estudiantes_foco' ),
				null, null, 'normal', 'default'
			);
		}
	}

	// ── Render callbacks de los WP Dashboard widgets ────────────────────────────

	public function render_wp_widget_pulso(): void {
		$role_context = $this->get_current_role_context();
		$user_id      = get_current_user_id();
		$metrics      = $this->get_role_summary_metrics( $role_context, $user_id );

		$analytics = clms_core('CLMS_Analytics');
		if ( ! $analytics && class_exists( 'CLMS_Analytics' ) ) {
			$analytics = new CLMS_Analytics();
		}

		$snapshot  = ( $analytics && method_exists( $analytics, 'get_dashboard_snapshot' ) )
			? $analytics->get_dashboard_snapshot( $role_context, $user_id )
			: array();
		$academic  = isset( $snapshot['academic'] ) && is_array( $snapshot['academic'] )
			? $snapshot['academic']
			: array( 'cards' => array(), 'rows' => array() );

		$retention  = ( $analytics && method_exists( $analytics, 'get_event_series' ) )
			? $analytics->get_event_series( array( 'lesson_completed' ), 42, 'week', 'count' )
			: array();
		$engagement = ( $analytics && method_exists( $analytics, 'get_event_series' ) )
			? $analytics->get_event_series( array( 'submission_created' ), 42, 'week', 'count' )
			: array();

		$ret_vals = array_map( static fn( $b ) => (float) ( $b['value'] ?? 0 ), $retention );
		$eng_vals = array_map( static fn( $b ) => (float) ( $b['value'] ?? 0 ), $engagement );

		$palette = array( '#4f46e5', '#0ea5e9', '#22c55e', '#f59e0b', '#ec4899' );

		$bar_rows = array();
		foreach ( (array) ( $academic['rows'] ?? array() ) as $row ) {
			$label = (string) ( $row['course_title'] ?? '' );
			$val   = (float) ( $row['pending_submissions'] ?? 0 );
			if ( $label && $val > 0 ) {
				$bar_rows[] = array( 'label' => $label, 'value' => $val );
			}
		}
		?>
		<div class="atora-wp-widget">

			<!-- Metric tiles -->
			<div class="atora-wp-metrics">
				<?php foreach ( $metrics as $i => $m ) :
					$color = $palette[ $i % count( $palette ) ];
				?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-analytics' ) ); ?>"
					   class="atora-wp-metric" style="--m-color:<?php echo esc_attr( $color ); ?>">
						<span class="atora-wp-metric-num"><?php echo esc_html( (string) $m['value'] ); ?></span>
						<span class="atora-wp-metric-lbl"><?php echo esc_html( $m['label'] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>

			<?php if ( ! empty( $bar_rows ) ) : ?>
				<div class="atora-wp-section">
					<p class="atora-wp-section-title"><?php esc_html_e( 'Entregas pendientes por curso', 'atora-lms' ); ?></p>
					<?php echo $this->render_svg_hbar_chart( $bar_rows, '#4f46e5' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $ret_vals ) || ! empty( $eng_vals ) ) : ?>
				<div class="atora-wp-trends">
					<?php if ( ! empty( $ret_vals ) ) : ?>
						<div class="atora-wp-trend">
							<span class="atora-wp-trend-lbl"><?php esc_html_e( 'Retención · lecciones/semana', 'atora-lms' ); ?></span>
							<?php echo $this->render_sparkline_svg( $ret_vals, '#22c55e' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $eng_vals ) ) : ?>
						<div class="atora-wp-trend">
							<span class="atora-wp-trend-lbl"><?php esc_html_e( 'Engagement · entregas/semana', 'atora-lms' ); ?></span>
							<?php echo $this->render_sparkline_svg( $eng_vals, '#4f46e5' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="atora-wp-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-analytics' ) ); ?>" class="button button-primary button-small">
					<?php esc_html_e( 'Analítica completa', 'atora-lms' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-dashboard' ) ); ?>" class="button button-small">
					<?php esc_html_e( 'Escritorio ATORA', 'atora-lms' ); ?>
				</a>
				<?php if ( current_user_can( 'clms_manage_courses' ) || current_user_can( 'manage_options' ) ) : ?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=lm_course' ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Gestionar cursos', 'atora-lms' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function render_wp_widget_evaluacion(): void {
		if ( ! current_user_can( 'clms_grade_submissions' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id    = get_current_user_id();
		$role       = $this->get_current_role_context();
		$course_ids = ( 'admin' === $role ) ? array() : $this->get_courses_owned_by_user( $user_id );

		$pending = $this->count_submissions_for_courses( $course_ids, 'submitted' );
		$total   = $this->count_submissions_for_courses( $course_ids );
		$pct     = $total > 0 ? ( ( $total - $pending ) / $total ) * 100 : 0;

		$meta_q = array(
			array( 'key' => '_clms_submission_status', 'value' => 'submitted' ),
		);
		if ( ! empty( $course_ids ) ) {
			$meta_q[] = array(
				'key'     => '_clms_submission_course_id',
				'value'   => $course_ids,
				'compare' => 'IN',
				'type'    => 'NUMERIC',
			);
		}
		$recent = get_posts( array(
			'post_type'              => 'clms_submission',
			'post_status'            => 'publish',
			'posts_per_page'         => 5,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'meta_query'             => $meta_q,
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		) );
		$grading = $this->get_grading_instance();
		?>
		<div class="atora-wp-widget">
			<div class="atora-wp-eval-head">
				<div class="atora-wp-eval-donut">
					<?php echo $this->render_svg_donut( $pct, '#4f46e5' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<p class="atora-wp-eval-donut-lbl"><?php esc_html_e( 'Revisadas', 'atora-lms' ); ?></p>
				</div>
				<div class="atora-wp-eval-counts">
					<div class="atora-wp-eval-count" style="color:#dc2626">
						<span class="atora-wp-eval-num"><?php echo esc_html( $pending ); ?></span>
						<span><?php esc_html_e( 'Pendientes', 'atora-lms' ); ?></span>
					</div>
					<div class="atora-wp-eval-count" style="color:#4f46e5">
						<span class="atora-wp-eval-num"><?php echo esc_html( $total ); ?></span>
						<span><?php esc_html_e( 'Total', 'atora-lms' ); ?></span>
					</div>
				</div>
			</div>

			<?php if ( ! empty( $recent ) ) : ?>
				<ul class="atora-wp-list">
					<?php foreach ( $recent as $sub ) :
						$student_id   = absint( get_post_meta( $sub->ID, '_clms_submission_user_id', true ) );
						$sub_course   = absint( get_post_meta( $sub->ID, '_clms_submission_course_id', true ) );
						$student      = get_userdata( $student_id );
						$student_name = $student ? ( $student->display_name ?: $student->user_login ) : "#{$student_id}";
						$course_title = $sub_course ? get_the_title( $sub_course ) : '—';
						$date         = mysql2date( 'd/m/Y', $sub->post_date );
						$sg_url       = ( $grading && method_exists( $grading, 'get_speedgrade_url' ) )
							? $grading->get_speedgrade_url( $sub->ID, admin_url( 'admin.php?page=clms-speedgrader' ) )
							: get_edit_post_link( $sub->ID, '' );
					?>
						<li class="atora-wp-list-item">
							<span class="atora-wp-list-main">
								<strong><?php echo esc_html( $student_name ); ?></strong>
								<small><?php echo esc_html( $this->truncate_admin_text( $course_title, 32 ) ); ?> · <?php echo esc_html( $date ); ?></small>
							</span>
							<?php if ( $sg_url ) : ?>
								<a href="<?php echo esc_url( $sg_url ); ?>" class="button button-small atora-wp-btn-rev"><?php esc_html_e( 'Revisar', 'atora-lms' ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="atora-wp-empty"><?php esc_html_e( 'No hay entregas pendientes.', 'atora-lms' ); ?></p>
			<?php endif; ?>

			<div class="atora-wp-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-speedgrader' ) ); ?>" class="button button-primary button-small"><?php esc_html_e( 'SpeedGrade', 'atora-lms' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-gradebook' ) ); ?>" class="button button-small"><?php esc_html_e( 'Gradebook', 'atora-lms' ); ?></a>
			</div>
		</div>
		<?php
	}

	public function render_wp_widget_comercio(): void {
		if ( ! current_user_can( 'clms_manage_commerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$analytics = clms_core('CLMS_Analytics');
		if ( ! $analytics && class_exists( 'CLMS_Analytics' ) ) {
			$analytics = new CLMS_Analytics();
		}
		$snapshot   = ( $analytics && method_exists( $analytics, 'get_dashboard_snapshot' ) )
			? $analytics->get_dashboard_snapshot( 'admin', get_current_user_id() )
			: array();
		$commercial = isset( $snapshot['commercial'] ) && is_array( $snapshot['commercial'] )
			? $snapshot['commercial']
			: array( 'cards' => array(), 'rows' => array() );

		$woo_active = class_exists( 'WooCommerce' );

		$bar_rows = array();
		foreach ( (array) ( $commercial['rows'] ?? array() ) as $row ) {
			$label = (string) ( $row['title'] ?? '' );
			$val   = (float) ( $row['revenue'] ?? 0 );
			if ( $label ) {
				$bar_rows[] = array( 'label' => $label, 'value' => $val );
			}
		}

		$palette = array( '#0ea5e9', '#4f46e5', '#22c55e' );
		?>
		<div class="atora-wp-widget">
			<?php if ( ! $woo_active ) : ?>
				<div class="atora-wp-notice">
					<p><?php esc_html_e( 'WooCommerce no detectado. Instálalo para activar los datos comerciales.', 'atora-lms' ); ?></p>
					<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Instalar WooCommerce', 'atora-lms' ); ?>
					</a>
				</div>
			<?php else : ?>
				<?php if ( ! empty( $commercial['cards'] ) ) : ?>
					<div class="atora-wp-metrics">
						<?php foreach ( array_slice( $commercial['cards'], 0, 3 ) as $i => $card ) :
							$color = $palette[ $i % count( $palette ) ];
						?>
							<div class="atora-wp-metric" style="--m-color:<?php echo esc_attr( $color ); ?>">
								<span class="atora-wp-metric-num"><?php echo esc_html( (string) ( $card['value'] ?? '—' ) ); ?></span>
								<span class="atora-wp-metric-lbl"><?php echo esc_html( (string) ( $card['label'] ?? '' ) ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $bar_rows ) ) : ?>
					<div class="atora-wp-section">
						<p class="atora-wp-section-title"><?php esc_html_e( 'Ingresos estimados por producto', 'atora-lms' ); ?></p>
						<?php echo $this->render_svg_hbar_chart( $bar_rows, '#0ea5e9', '$' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				<?php elseif ( empty( $commercial['cards'] ) ) : ?>
					<p class="atora-wp-empty"><?php esc_html_e( 'Sin datos comerciales todavía.', 'atora-lms' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<div class="atora-wp-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-commerce-dashboard' ) ); ?>" class="button button-primary button-small"><?php esc_html_e( 'Dashboard comercial', 'atora-lms' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-commercial-hub' ) ); ?>" class="button button-small"><?php esc_html_e( 'Gestionar', 'atora-lms' ); ?></a>
			</div>
		</div>
		<?php
	}

	public function render_wp_widget_mensajes(): void {
		$user_id   = get_current_user_id();
		$messaging = clms_core('CLMS_Messaging');

		$stats = ( $messaging && method_exists( $messaging, 'get_message_stats' ) )
			? $messaging->get_message_stats( $user_id )
			: array( 'total' => 0, 'unread' => 0 );
		$msgs  = ( $messaging && method_exists( $messaging, 'get_messages' ) )
			? $messaging->get_messages( $user_id, array( 'limit' => 5 ) )
			: array();

		$unread = absint( $stats['unread'] ?? 0 );
		$total  = absint( $stats['total'] ?? 0 );
		?>
		<div class="atora-wp-widget">
			<div class="atora-wp-msg-head">
				<div class="atora-wp-msg-badge" style="background:<?php echo $unread > 0 ? '#4f46e5' : '#f3f4f6'; ?>;color:<?php echo $unread > 0 ? '#fff' : '#6b7280'; ?>">
					<?php echo esc_html( $unread ); ?>
				</div>
				<div>
					<strong style="display:block;font-size:15px;color:#1d2327"><?php echo esc_html( $unread ); ?> <?php esc_html_e( 'sin leer', 'atora-lms' ); ?></strong>
					<small style="color:#94a3b8"><?php echo esc_html( $total ); ?> <?php esc_html_e( 'mensajes totales', 'atora-lms' ); ?></small>
				</div>
			</div>

			<?php if ( ! empty( $msgs ) ) : ?>
				<ul class="atora-wp-list">
					<?php foreach ( $msgs as $msg ) :
						$is_unread = empty( $msg['is_read'] );
						$sender    = $this->format_message_sender_label( $msg['sender_type'] ?? 'system', $msg['sender_name'] ?? '' );
						$title     = $this->truncate_admin_text( (string) ( $msg['title'] ?? '' ), 52 );
						$date      = $this->format_admin_datetime( $msg['created_at'] ?? '' );
					?>
						<li class="atora-wp-list-item<?php echo $is_unread ? ' is-unread' : ''; ?>">
							<span class="atora-wp-list-main">
								<small><?php echo esc_html( $sender ); ?></small>
								<strong><?php echo esc_html( $title ); ?></strong>
								<small><?php echo esc_html( $date ); ?></small>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="atora-wp-empty"><?php esc_html_e( 'No hay mensajes todavía.', 'atora-lms' ); ?></p>
			<?php endif; ?>

			<div class="atora-wp-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-messages' ) ); ?>" class="button button-primary button-small"><?php esc_html_e( 'Ver mensajes', 'atora-lms' ); ?></a>
			</div>
		</div>
		<?php
	}

	public function render_wp_widget_acciones_operativas(): void {
		try {
		$operations = $this->get_admin_operations_service();
		$cards      = $operations ? (array) $operations->get_action_cards( get_current_user_id(), 6 ) : array();

		echo '<div class="atora-wp-widget">';
		if ( empty( $cards ) ) {
			echo '<div class="clms-empty-state">';
			echo '<p class="clms-empty-state__title">' . esc_html__( 'No hay acciones críticas por ahora', 'atora-lms' ) . '</p>';
			echo '<p class="clms-empty-state__text">' . esc_html__( 'El sistema está estable. Revisa analítica para detectar oportunidades de mejora.', 'atora-lms' ) . '</p>';
			echo '<p><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=clms-analytics' ) ) . '">' . esc_html__( 'Abrir analítica', 'atora-lms' ) . '</a></p>';
			echo '</div>';
		} else {
			echo '<div class="clms-dashboard-grid">';
			foreach ( array_slice( $cards, 0, 5 ) as $card ) {
				$type     = sanitize_key( (string) ( $card['type'] ?? 'system' ) );
				$priority = sanitize_key( (string) ( $card['priority'] ?? 'medium' ) );
				$title    = sanitize_text_field( (string) ( $card['title'] ?? '' ) );
				$desc     = sanitize_text_field( (string) ( $card['description'] ?? '' ) );
				$status   = sanitize_text_field( (string) ( $card['status'] ?? '' ) );
				$cta_url  = esc_url( (string) ( $card['cta_url'] ?? '' ) );
				$cta_lbl  = sanitize_text_field( (string) ( $card['cta_label'] ?? '' ) );
				$icon     = sanitize_html_class( (string) ( $card['icon'] ?? 'dashicons-admin-tools' ) );

				echo '<article class="clms-admin-card clms-action-card clms-action-card--' . esc_attr( $type ) . '">';
				echo '<div class="clms-action-card__head">';
				echo '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
				echo '<span class="clms-status-pill clms-priority-' . esc_attr( $priority ) . '">' . esc_html( strtoupper( $priority ) ) . '</span>';
				echo '</div>';
				echo '<h4>' . esc_html( $title ) . '</h4>';
				if ( '' !== $desc ) {
					echo '<p class="clms-action-card__desc">' . esc_html( $desc ) . '</p>';
				}
				if ( '' !== $status ) {
					echo '<p class="clms-action-card__status">' . esc_html( $status ) . '</p>';
				}
				if ( $cta_url && $cta_lbl ) {
					echo '<a class="button button-primary button-small" href="' . esc_url( $cta_url ) . '">' . esc_html( $cta_lbl ) . '</a>';
				}
				echo '</article>';
			}
			echo '</div>';
		}
		echo '</div>';
		} catch ( \Throwable $e ) { // phpcs:ignore
			echo '<p style="color:#c00;font-size:12px">'
				. esc_html__( 'Error al cargar acciones. Revisar log del servidor.', 'atora-lms' )
				. ( defined( 'ATORA_DEV_MODE' ) && ATORA_DEV_MODE ? ' <code>' . esc_html( $e->getMessage() ) . '</code>' : '' )
				. '</p>';
		}
	}

	public function render_wp_widget_estudiantes_foco(): void {
		$operations = $this->get_admin_operations_service();
		$filters    = $this->get_operational_student_filters_from_request();
		$options    = $operations && method_exists( $operations, 'get_students_filter_options' )
			? (array) $operations->get_students_filter_options( get_current_user_id() )
			: array();
		$rows       = $operations ? (array) $operations->get_students_visibility_rows( get_current_user_id(), 8, $filters ) : array();

		echo '<div class="atora-wp-widget">';
		$this->render_operational_student_filters( $filters, $options );
		if ( empty( $rows ) ) {
			echo '<div class="clms-empty-state">';
			echo '<p class="clms-empty-state__title">' . esc_html__( 'Todavía no hay estudiantes activos.', 'atora-lms' ) . '</p>';
			echo '<p class="clms-empty-state__text">' . esc_html__( 'Publica cursos o revisa matrículas para activar seguimiento estudiantil.', 'atora-lms' ) . '</p>';
			echo '<p><a class="button button-small" href="' . esc_url( admin_url( 'edit.php?post_type=lm_course' ) ) . '">' . esc_html__( 'Revisar cursos', 'atora-lms' ) . '</a></p>';
			echo '</div>';
		} else {
			echo '<div class="clms-students-summary">';
			foreach ( $rows as $row ) {
				$student_name  = sanitize_text_field( (string) ( $row['student_name'] ?? '' ) );
				$course_title  = sanitize_text_field( (string) ( $row['course_title'] ?? '' ) );
				$progress      = absint( $row['progress_percent'] ?? 0 );
				$risk_level    = sanitize_key( (string) ( $row['risk_level'] ?? 'unknown' ) );
				$risk_label    = sanitize_text_field( (string) ( $row['risk_label'] ?? __( 'Sin datos', 'atora-lms' ) ) );
				$pending       = absint( $row['pending_submissions'] ?? 0 );
				$certificate   = sanitize_text_field( (string) ( $row['certificate_label'] ?? __( 'En progreso', 'atora-lms' ) ) );
				$last_activity = sanitize_text_field( (string) ( $row['last_activity'] ?? __( 'Sin actividad reciente', 'atora-lms' ) ) );
				$profile_url   = esc_url( (string) ( $row['profile_url'] ?? '' ) );
				$course_url    = esc_url( (string) ( $row['course_url'] ?? '' ) );
				$speedgrade    = esc_url( (string) ( $row['speedgrade_url'] ?? '' ) );

				echo '<article class="clms-student-row">';
				echo '<div class="clms-student-row__head">';
				echo '<h4>' . esc_html( $student_name ) . '</h4>';
				echo '<span class="clms-status-pill clms-status-pill--risk-' . esc_attr( $risk_level ) . '">' . esc_html( $risk_label ) . '</span>';
				echo '</div>';
				echo '<p class="clms-student-row__meta">' . esc_html( $course_title ) . '</p>';
				echo '<div class="clms-student-row__stats">';
				echo '<span>' . esc_html( sprintf( __( 'Progreso: %d%%', 'atora-lms' ), $progress ) ) . '</span>';
				echo '<span>' . esc_html( sprintf( _n( '%d entrega pendiente', '%d entregas pendientes', $pending, 'atora-lms' ), $pending ) ) . '</span>';
				echo '<span>' . esc_html( sprintf( __( 'Certificado: %s', 'atora-lms' ), $certificate ) ) . '</span>';
				echo '</div>';
				echo '<p class="clms-student-row__meta">' . esc_html( sprintf( __( 'Última actividad: %s', 'atora-lms' ), $last_activity ) ) . '</p>';
				echo '<div class="clms-student-row__actions">';
				if ( $profile_url ) {
					echo '<a class="button button-small" href="' . esc_url( $profile_url ) . '">' . esc_html__( 'Ver perfil', 'atora-lms' ) . '</a>';
				}
				if ( $course_url ) {
					echo '<a class="button button-small" href="' . esc_url( $course_url ) . '">' . esc_html__( 'Ver curso', 'atora-lms' ) . '</a>';
				}
				if ( $speedgrade ) {
					echo '<a class="button button-small button-primary" href="' . esc_url( $speedgrade ) . '">' . esc_html__( 'Abrir SpeedGrade', 'atora-lms' ) . '</a>';
				}
				if ( ! empty( $row['certificate_view_url'] ) ) {
					echo '<a class="button button-small" href="' . esc_url( (string) $row['certificate_view_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Ver certificado', 'atora-lms' ) . '</a>';
				}
				if ( ! empty( $row['certificate_linkedin_url'] ) ) {
					echo '<a class="button button-small" href="' . esc_url( (string) $row['certificate_linkedin_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'LinkedIn', 'atora-lms' ) . '</a>';
				}
				echo '</div>';
				echo '</article>';
			}
			echo '</div>';
			echo '<p><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=clms-academic-reports' ) ) . '">' . esc_html__( 'Ver visibilidad completa de estudiantes', 'atora-lms' ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * Obtiene filtros de estudiantes desde query string.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_operational_student_filters_from_request() {
		return array(
			'course_id'          => isset( $_GET['clms_student_course_id'] ) ? absint( wp_unslash( $_GET['clms_student_course_id'] ) ) : 0,
			'program_id'         => isset( $_GET['clms_student_program_id'] ) ? absint( wp_unslash( $_GET['clms_student_program_id'] ) ) : 0,
			'cohort_id'          => isset( $_GET['clms_student_cohort_id'] ) ? absint( wp_unslash( $_GET['clms_student_cohort_id'] ) ) : 0,
			'certificate_status' => isset( $_GET['clms_student_certificate_status'] ) ? sanitize_key( wp_unslash( $_GET['clms_student_certificate_status'] ) ) : '',
		);
	}

	/**
	 * Renderiza filtros operativos de estudiantes.
	 *
	 * @param array<string,mixed> $filters Filtros activos.
	 * @param array<string,mixed> $options Opciones.
	 * @return void
	 */
	protected function render_operational_student_filters( $filters, $options ) {
		$filters = is_array( $filters ) ? $filters : array();
		$options = is_array( $options ) ? $options : array();

		$courses = isset( $options['courses'] ) && is_array( $options['courses'] ) ? $options['courses'] : array();
		$programs = isset( $options['programs'] ) && is_array( $options['programs'] ) ? $options['programs'] : array();
		$cohorts = isset( $options['cohorts'] ) && is_array( $options['cohorts'] ) ? $options['cohorts'] : array();
		$certificate_statuses = isset( $options['certificate_statuses'] ) && is_array( $options['certificate_statuses'] ) ? $options['certificate_statuses'] : array();

		echo '<form method="get" class="clms-admin-filters" style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px;">';
		if ( isset( $_GET['page'] ) ) {
			echo '<input type="hidden" name="page" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['page'] ) ) ) . '">';
		}

		echo '<select name="clms_student_course_id">';
		echo '<option value="0">' . esc_html__( 'Todos los cursos', 'atora-lms' ) . '</option>';
		foreach ( $courses as $course_id => $label ) {
			echo '<option value="' . esc_attr( (string) absint( $course_id ) ) . '" ' . selected( absint( $filters['course_id'] ?? 0 ), absint( $course_id ), false ) . '>' . esc_html( (string) $label ) . '</option>';
		}
		echo '</select>';

		echo '<select name="clms_student_program_id">';
		echo '<option value="0">' . esc_html__( 'Todos los programas', 'atora-lms' ) . '</option>';
		foreach ( $programs as $program_id => $label ) {
			echo '<option value="' . esc_attr( (string) absint( $program_id ) ) . '" ' . selected( absint( $filters['program_id'] ?? 0 ), absint( $program_id ), false ) . '>' . esc_html( (string) $label ) . '</option>';
		}
		echo '</select>';

		echo '<select name="clms_student_cohort_id">';
		echo '<option value="0">' . esc_html__( 'Todas las cohortes', 'atora-lms' ) . '</option>';
		foreach ( $cohorts as $cohort_id => $label ) {
			echo '<option value="' . esc_attr( (string) absint( $cohort_id ) ) . '" ' . selected( absint( $filters['cohort_id'] ?? 0 ), absint( $cohort_id ), false ) . '>' . esc_html( (string) $label ) . '</option>';
		}
		echo '</select>';

		echo '<select name="clms_student_certificate_status">';
		foreach ( $certificate_statuses as $status_key => $status_label ) {
			$status_key = sanitize_key( (string) $status_key );
			echo '<option value="' . esc_attr( $status_key ) . '" ' . selected( sanitize_key( (string) ( $filters['certificate_status'] ?? '' ) ), $status_key, false ) . '>' . esc_html( (string) $status_label ) . '</option>';
		}
		echo '</select>';

		echo '<button type="submit" class="button button-small">' . esc_html__( 'Filtrar', 'atora-lms' ) . '</button>';
		echo '<a class="button button-small" href="' . esc_url( remove_query_arg( array( 'clms_student_course_id', 'clms_student_program_id', 'clms_student_cohort_id', 'clms_student_certificate_status' ) ) ) . '">' . esc_html__( 'Limpiar', 'atora-lms' ) . '</a>';
		echo '</form>';
	}

	protected function get_admin_operations_service() {
		$service = clms_core('CLMS_Admin_Operations_Service');
		if ( ! $service && class_exists( 'CLMS_Admin_Operations_Service' ) ) {
			$service = new CLMS_Admin_Operations_Service();
		}

		return $service;
	}

	// ── SVG helpers (devuelven string) ───────────────────────────────────────────

	/**
	 * Genera un donut chart SVG con stroke-dasharray.
	 * $percent: 0–100
	 */
	protected function render_svg_donut( float $percent, string $color = '#4f46e5' ): string {
		$r     = 34;
		$cx    = 42;
		$cy    = 42;
		$size  = 84;
		$circ  = 2 * M_PI * $r;
		$dash  = max( 0.0, min( $circ, ( $percent / 100 ) * $circ ) );
		$gap   = $circ - $dash;
		$label = (int) round( $percent );

		return sprintf(
			'<svg viewBox="0 0 %d %d" width="%d" height="%d" aria-hidden="true" style="display:block;overflow:visible">
				<circle cx="%d" cy="%d" r="%d" fill="none" stroke="%s" stroke-width="7" opacity="0.15"/>
				<circle cx="%d" cy="%d" r="%d" fill="none" stroke="%s" stroke-width="7"
					stroke-dasharray="%.2f %.2f" stroke-linecap="round"
					transform="rotate(-90 %d %d)"/>
				<text x="%d" y="%d" text-anchor="middle" dominant-baseline="middle"
					font-size="15" font-weight="700" fill="%s">%d%%</text>
			</svg>',
			$size, $size, $size, $size,
			$cx, $cy, $r, esc_attr( $color ),
			$cx, $cy, $r, esc_attr( $color ),
			$dash, $gap, $cx, $cy,
			$cx, $cy + 1, esc_attr( $color ), $label
		);
	}

	/**
	 * Genera un bar chart horizontal SVG.
	 * $items: array of ['label' => string, 'value' => float]
	 * $unit: '' | '$' | '%'
	 */
	protected function render_svg_hbar_chart( array $items, string $color = '#4f46e5', string $unit = '' ): string {
		if ( empty( $items ) ) {
			return '';
		}

		$items   = array_slice( $items, 0, 6 );
		$values  = array_column( $items, 'value' );
		$max_val = max( 1.0, (float) max( $values ) );

		$lbl_w  = 128;
		$bar_mx = 170;
		$val_w  = 56;
		$row_h  = 28;
		$pad    = 4;
		$h      = count( $items ) * $row_h + $pad * 2;
		$w      = $lbl_w + $bar_mx + $val_w;

		$svg = sprintf(
			'<svg viewBox="0 0 %d %d" width="100%%" height="%dpx" aria-hidden="true" style="display:block">',
			$w, $h, $h
		);

		foreach ( $items as $i => $item ) {
			$y       = $pad + $i * $row_h;
			$val     = max( 0.0, (float) $item['value'] );
			$bar_w   = $max_val > 0 ? max( 2.0, ( $val / $max_val ) * $bar_mx ) : 2.0;
			$label   = $this->truncate_admin_text( (string) ( $item['label'] ?? '' ), 20 );
			$display = match ( $unit ) {
				'$'  => '$' . number_format_i18n( $val, 0 ),
				'%'  => number_format_i18n( $val, 0 ) . '%',
				default => number_format_i18n( $val, 0 ),
			};
			$mid_y   = $y + $row_h / 2;
			$bar_y   = $y + ( $row_h - 10 ) / 2;

			// Label
			$svg .= sprintf(
				'<text x="%d" y="%.1f" font-size="11" fill="#6b7280" text-anchor="end" dominant-baseline="middle">%s</text>',
				$lbl_w - 6, $mid_y, esc_html( $label )
			);
			// Track
			$svg .= sprintf(
				'<rect x="%d" y="%.1f" width="%d" height="10" rx="5" fill="%s" opacity="0.12"/>',
				$lbl_w, $bar_y, $bar_mx, esc_attr( $color )
			);
			// Bar
			$svg .= sprintf(
				'<rect x="%d" y="%.1f" width="%.1f" height="10" rx="5" fill="%s"/>',
				$lbl_w, $bar_y, $bar_w, esc_attr( $color )
			);
			// Value label
			$svg .= sprintf(
				'<text x="%d" y="%.1f" font-size="11" font-weight="700" fill="%s" dominant-baseline="middle">%s</text>',
				$lbl_w + $bar_mx + 5, $mid_y, esc_attr( $color ), esc_html( $display )
			);
		}

		$svg .= '</svg>';
		return $svg;
	}

	protected function get_crm_summary_snapshot(): array {
		global $wpdb;

		$snapshot = array(
			'contacts_count' => 0,
			'leads_count'    => 0,
			'students_count' => 0,
			'new_week'       => 0,
			'pending_emails' => 0,
			'recent_emails'  => array(),
			'upcoming_events' => array(),
		);

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}atora_contacts'" ) ) { // phpcs:ignore
			$snapshot['contacts_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_contacts" ); // phpcs:ignore
			$snapshot['leads_count']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_contacts WHERE status = 'lead'" ); // phpcs:ignore
			$snapshot['students_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_contacts WHERE status = 'student'" ); // phpcs:ignore
			$snapshot['new_week']       = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}atora_contacts WHERE created_at >= %s",
					gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
				)
			);
		}

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}atora_email_queue'" ) ) { // phpcs:ignore
			$snapshot['recent_emails'] = (array) $wpdb->get_results(
				"SELECT id, subject, status, created_at FROM {$wpdb->prefix}atora_email_queue ORDER BY created_at DESC LIMIT 6" // phpcs:ignore
			);
			$snapshot['pending_emails'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_email_queue WHERE status = 'pending'" ); // phpcs:ignore
		}

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}atora_calendar_events'" ) ) { // phpcs:ignore
			$snapshot['upcoming_events'] = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, title, start_datetime, event_type, location
					FROM {$wpdb->prefix}atora_calendar_events
					WHERE start_datetime >= %s
					ORDER BY start_datetime ASC LIMIT 6",
					current_time( 'mysql', true )
				)
			);
		}

		return $snapshot;
	}

	protected function get_message_hub_snapshot( int $user_id ): array {
		$messaging = clms_core('CLMS_Messaging');

		$stats = array(
			'total'           => 0,
			'unread'          => 0,
			'from_system'     => 0,
			'from_teacher'    => 0,
			'from_ai'         => 0,
			'recommendations' => 0,
		);
		$compose = array(
			'courses'  => array(),
			'students' => array(),
		);

		if ( $messaging && method_exists( $messaging, 'get_message_stats' ) ) {
			$stats = array_merge( $stats, (array) $messaging->get_message_stats( $user_id ) );
		}
		if ( $messaging && method_exists( $messaging, 'get_compose_context_for_user' ) ) {
			$compose = array_merge( $compose, (array) $messaging->get_compose_context_for_user( $user_id ) );
		}

		return array(
			'stats'   => $stats,
			'compose' => $compose,
		);
	}

	protected function count_users_with_capability( string $capability ): int {
		$query = new WP_User_Query(
			array(
				'fields'      => 'ids',
				'number'      => 1,
				'count_total' => true,
				'capability'  => sanitize_key( $capability ),
			)
		);

		if ( method_exists( $query, 'get_total' ) ) {
			return absint( $query->get_total() );
		}

		return absint( count( (array) $query->get_results() ) );
	}

	protected function get_hub_quick_links( string $role_context, int $limit = 12 ): array {
		$groups = $this->get_navigation_groups( $role_context );
		$items  = array();
		$seen   = array();

		foreach ( $groups as $group ) {
			if ( empty( $group['items'] ) || ! is_array( $group['items'] ) ) {
				continue;
			}

			foreach ( $group['items'] as $item ) {
				$url = esc_url_raw( (string) ( $item['url'] ?? '' ) );
				if ( '' === $url || isset( $seen[ $url ] ) || ! $this->current_user_can_visit_url( $url ) ) {
					continue;
				}
				$seen[ $url ] = true;

				$items[] = array(
					'title'       => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
					'description' => sanitize_text_field( (string) ( $item['description'] ?? '' ) ),
					'url'         => $url,
				);

				if ( count( $items ) >= $limit ) {
					break 2;
				}
			}
		}

		return $items;
	}

	// ═══════════════════════════════════════════════════════════════════════════════
	// PANEL ATORA · HUB ACADÉMICO · CRM HUB
	// ═══════════════════════════════════════════════════════════════════════════════

	public function render_escritorio_page(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$user_id      = get_current_user_id();
		$user         = wp_get_current_user();
		$display_name = $user->display_name ?: $user->user_login;
		$today        = wp_date( get_option( 'date_format' ) );
		$role_context = $this->get_current_role_context();
		$role_label   = $this->get_role_context_label( $role_context );
		$role_copy    = $this->get_role_context_description( $role_context );
		$crm_snapshot = $this->get_crm_summary_snapshot();
		$msg_snapshot = $this->get_message_hub_snapshot( $user_id );
		$stats        = array_slice( $this->get_role_summary_metrics( $role_context, $user_id ), 0, 4 );

		if ( count( $stats ) < 4 ) {
			$stats[] = array( 'label' => __( 'Contactos', 'atora-lms' ), 'value' => $crm_snapshot['contacts_count'] );
			$stats[] = array( 'label' => __( 'Leads', 'atora-lms' ), 'value' => $crm_snapshot['leads_count'] );
		}

		$stats = array_slice( $stats, 0, 4 );

		$academic_features = array();
		foreach ( array_slice( $stats, 0, 3 ) as $metric ) {
			$academic_features[] = sanitize_text_field( (string) $metric['label'] ) . ': ' . sanitize_text_field( (string) $metric['value'] );
		}
		if ( empty( $academic_features ) ) {
			$academic_features[] = __( 'Accede a estructura académica, rúbricas y evaluación.', 'atora-lms' );
		}

		$crm_features = array(
			sprintf(
				/* translators: %d: contacts in CRM */
				__( '%d contactos en CRM.', 'atora-lms' ),
				absint( $crm_snapshot['contacts_count'] )
			),
			sprintf(
				/* translators: %d: potential leads */
				__( '%d leads potenciales para seguimiento.', 'atora-lms' ),
				absint( $crm_snapshot['leads_count'] )
			),
			sprintf(
				/* translators: %d: unread messages */
				__( '%d mensajes sin leer en bandeja interna.', 'atora-lms' ),
				absint( $msg_snapshot['stats']['unread'] ?? 0 )
			),
		);

		if ( 'instructor' === $role_context ) {
			$crm_features = array(
				sprintf(
					/* translators: %d: students in message compose */
					__( '%d estudiantes en comunicación directa.', 'atora-lms' ),
					count( (array) ( $msg_snapshot['compose']['students'] ?? array() ) )
				),
				sprintf(
					/* translators: %d: pending submissions */
					__( '%d entregas pendientes de feedback.', 'atora-lms' ),
					absint( $this->count_submissions_for_courses( $this->get_courses_owned_by_user( $user_id ), 'submitted' ) )
				),
				sprintf(
					/* translators: %d: unread messages */
					__( '%d mensajes pendientes de revisar.', 'atora-lms' ),
					absint( $msg_snapshot['stats']['unread'] ?? 0 )
				),
			);
		}

		$next_event_label = '';
		if ( ! empty( $crm_snapshot['upcoming_events'][0]->title ) ) {
			$next_event_label = sanitize_text_field( (string) $crm_snapshot['upcoming_events'][0]->title );
		}

		$assistant_features = array(
			sprintf(
				/* translators: %d: recommendations count */
				__( '%d recomendaciones activas de seguimiento.', 'atora-lms' ),
				absint( $msg_snapshot['stats']['recommendations'] ?? 0 )
			),
			sprintf(
				/* translators: %d: pending email queue */
				__( '%d correos en cola pendientes de envío.', 'atora-lms' ),
				absint( $crm_snapshot['pending_emails'] )
			),
		);
		if ( '' !== $next_event_label ) {
			$assistant_features[] = sprintf(
				/* translators: %s: next event title */
				__( 'Próximo hito en calendario: %s', 'atora-lms' ),
				$next_event_label
			);
		}

		$panel_cards = array(
			array(
				'variant'     => 'blue',
				'icon'        => '🎓',
				'title'       => __( 'Hub académico', 'atora-lms' ),
				'description' => __( 'Gestiona programas, cursos, cohortes, docentes, lecciones, rúbricas y evaluaciones desde una sola cabina.', 'atora-lms' ),
				'features'    => $academic_features,
				'url'         => admin_url( 'admin.php?page=clms-academic-hub' ),
				'button'      => __( 'Abrir Hub académico', 'atora-lms' ),
			),
			array(
				'variant'     => 'indigo',
				'icon'        => '👥',
				'title'       => __( 'CRM Hub', 'atora-lms' ),
				'description' => __( 'Orquesta comunicación y relación con estudiantes, docentes, clientes activos y potenciales con contexto por rol.', 'atora-lms' ),
				'features'    => $crm_features,
				'url'         => admin_url( 'admin.php?page=clms-crm-hub' ),
				'button'      => __( 'Abrir CRM Hub', 'atora-lms' ),
			),
			array(
				'variant'     => 'teal',
				'icon'        => '💬',
				'title'       => __( 'Mensajería y experiencia', 'atora-lms' ),
				'description' => __( 'Conecta feedback, comunicación interna y seguimiento de experiencia del usuario sin cambiar de contexto.', 'atora-lms' ),
				'features'    => $assistant_features,
				'url'         => admin_url( 'admin.php?page=clms-messages' ),
				'button'      => __( 'Ir a Mensajes', 'atora-lms' ),
			),
		);
		$quick_links = $this->get_hub_quick_links( $role_context, 12 );
		?>
		<div class="wrap atora-hub">
			<div class="atora-hub__header">
				<div>
					<span class="atora-hub__context"><?php echo esc_html( $role_label ); ?></span>
					<h1 class="atora-hub__title">
						<?php
						/* translators: %s: user display name */
						echo esc_html( sprintf( __( 'Hola, %s', 'atora-lms' ), $display_name ) );
						?>
					</h1>
					<p class="atora-hub__date"><?php echo esc_html( $today ); ?> · <?php esc_html_e( 'Panel ATORA', 'atora-lms' ); ?></p>
					<p class="atora-hub__subtitle"><?php echo esc_html( $role_copy ); ?></p>
				</div>
			</div>

			<div class="atora-hub__stats">
				<?php foreach ( $stats as $metric ) : ?>
					<div class="atora-hub__stat">
						<span class="atora-hub__stat-num"><?php echo esc_html( (string) ( $metric['value'] ?? 0 ) ); ?></span>
						<span class="atora-hub__stat-lbl"><?php echo esc_html( (string) ( $metric['label'] ?? '' ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="atora-hub__actions">
				<?php foreach ( $panel_cards as $card ) : ?>
					<div class="atora-hub__action-card atora-hub__action-card--<?php echo esc_attr( sanitize_html_class( (string) $card['variant'] ) ); ?>">
						<div class="atora-hub__action-icon"><?php echo esc_html( (string) $card['icon'] ); ?></div>
						<h2 class="atora-hub__action-title"><?php echo esc_html( (string) $card['title'] ); ?></h2>
						<p class="atora-hub__action-desc"><?php echo esc_html( (string) $card['description'] ); ?></p>
						<ul class="atora-hub__feature-list">
							<?php foreach ( (array) ( $card['features'] ?? array() ) as $feature ) : ?>
								<li><?php echo esc_html( (string) $feature ); ?></li>
							<?php endforeach; ?>
						</ul>
						<a href="<?php echo esc_url( (string) $card['url'] ); ?>" class="atora-hub__action-btn">
							<?php echo esc_html( (string) $card['button'] ); ?> →
						</a>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="atora-hub__quick">
				<h3 class="atora-hub__quick-title"><?php esc_html_e( 'Acceso rápido', 'atora-lms' ); ?></h3>
				<div class="atora-hub__quick-grid">
					<?php foreach ( $quick_links as $item ) : ?>
						<a class="atora-hub__quick-link" href="<?php echo esc_url( (string) $item['url'] ); ?>">
							<strong><?php echo esc_html( (string) $item['title'] ); ?></strong>
							<span><?php echo esc_html( $this->truncate_admin_text( (string) $item['description'], 76 ) ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Hub simple genérico — PT-4.4.3 (6.3.0): grid de tarjetas
	 * icono+título+descripción+enlace, sin queries de stats en vivo.
	 * Reutiliza el CSS existente de `atora-hub__quick-grid` (mismo
	 * patrón que "Red de hubs ATORA" en los hubs ya existentes) como
	 * contenido principal en vez de sección secundaria.
	 *
	 * @param string $context  Etiqueta corta arriba del título (p.ej. "Estudiantes").
	 * @param string $title    Título principal de la página.
	 * @param string $subtitle Descripción de una línea.
	 * @param array  $links    Lista de array{title,description,url} (build_nav_item()).
	 */
	protected function render_simple_hub_page( string $context, string $title, string $subtitle, array $links ): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$links = $this->unique_hub_items_by_url( $links, 0, true );
		$today = wp_date( get_option( 'date_format' ) );
		?>
		<div class="wrap atora-hub">
			<div class="atora-hub__header">
				<div>
					<span class="atora-hub__context"><?php echo esc_html( $context ); ?></span>
					<h1 class="atora-hub__title"><?php echo esc_html( $title ); ?></h1>
					<p class="atora-hub__date"><?php echo esc_html( $today ); ?></p>
					<p class="atora-hub__subtitle"><?php echo esc_html( $subtitle ); ?></p>
				</div>
			</div>

			<?php if ( $links ) : ?>
			<div class="atora-hub__quick">
				<div class="atora-hub__quick-grid">
					<?php foreach ( $links as $item ) : ?>
					<a class="atora-hub__quick-link" href="<?php echo esc_url( (string) $item['url'] ); ?>">
						<strong><?php echo esc_html( (string) $item['title'] ); ?></strong>
						<span><?php echo esc_html( (string) $item['description'] ); ?></span>
					</a>
					<?php endforeach; ?>
				</div>
			</div>
			<?php else : ?>
			<p><?php esc_html_e( 'No hay páginas disponibles con tu rol actual.', 'atora-lms' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Hub "Estudiantes" — matrículas, progreso, gradebook, certificados (PT-4.4.3). */
	public function render_students_hub_page(): void {
		$links = array(
			$this->build_nav_item( __( 'Gradebook', 'atora-lms' ), __( 'Calificaciones y cálculo por curso.', 'atora-lms' ), admin_url( 'admin.php?page=clms-gradebook' ) ),
			$this->build_nav_item( __( 'Speedgrader', 'atora-lms' ), __( 'Corrección rápida de entregas pendientes.', 'atora-lms' ), admin_url( 'admin.php?page=clms-speedgrader' ) ),
			$this->build_nav_item( __( 'Estudiantes', 'atora-lms' ), __( 'Usuarios con rol Estudiante.', 'atora-lms' ), admin_url( 'users.php?role=lms_student' ) ),
			$this->build_nav_item( __( 'Migración LMS', 'atora-lms' ), __( 'Estado de matrícula y progreso en tablas propias.', 'atora-lms' ), admin_url( 'admin.php?page=atora-lms-migration' ) ),
		);
		$this->render_simple_hub_page(
			__( 'Estudiantes', 'atora-lms' ),
			__( 'Estudiantes', 'atora-lms' ),
			__( 'Matrícula, progreso, calificaciones y certificados en un solo lugar.', 'atora-lms' ),
			$links
		);
	}

	/** Hub "Docentes" (PT-4.4.3). */
	public function render_teachers_hub_page(): void {
		$links = array(
			$this->build_nav_item( __( 'Perfil de instructor', 'atora-lms' ), __( 'Vista de instructor para el usuario actual.', 'atora-lms' ), admin_url( 'admin.php?page=clms-instructor-profile' ) ),
			$this->build_nav_item( __( 'Docentes y contenidos', 'atora-lms' ), __( 'Gestión de contenidos por docente.', 'atora-lms' ), admin_url( 'admin.php?page=clms-academic-content' ) ),
			$this->build_nav_item( __( 'Docentes (listado)', 'atora-lms' ), __( 'CPT de docentes registrados.', 'atora-lms' ), admin_url( 'edit.php?post_type=atora_teacher' ) ),
		);
		$this->render_simple_hub_page(
			__( 'Docentes', 'atora-lms' ),
			__( 'Docentes', 'atora-lms' ),
			__( 'Perfiles, contenidos y gestión docente.', 'atora-lms' ),
			$links
		);
	}

	/** Hub "Comunicación" — mensajería, calendario, email (PT-4.4.3). */
	public function render_communication_hub_page(): void {
		$links = array(
			$this->build_nav_item( __( 'Mensajería', 'atora-lms' ), __( 'WhatsApp, Telegram y ruteo de mensajes.', 'atora-lms' ), admin_url( 'admin.php?page=atora-messaging' ) ),
			$this->build_nav_item( __( 'Calendario', 'atora-lms' ), __( 'Eventos y sincronización externa.', 'atora-lms' ), admin_url( 'admin.php?page=atora-calendar' ) ),
			$this->build_nav_item( __( 'Emails', 'atora-lms' ), __( 'Plantillas, colas y envío transaccional.', 'atora-lms' ), admin_url( 'admin.php?page=atora-emails' ) ),
			$this->build_nav_item( __( 'Newsletter', 'atora-lms' ), __( 'Boletín y archivo público.', 'atora-lms' ), admin_url( 'admin.php?page=atora-newsletter' ) ),
			$this->build_nav_item( __( 'Mensajes', 'atora-lms' ), __( 'Bandeja de mensajería interna.', 'atora-lms' ), admin_url( 'admin.php?page=clms-messages' ) ),
		);
		$this->render_simple_hub_page(
			__( 'Comunicación', 'atora-lms' ),
			__( 'Comunicación', 'atora-lms' ),
			__( 'Mensajería, calendario y email en un solo lugar.', 'atora-lms' ),
			$links
		);
	}

	/**
	 * Hub "Crecimiento" — CRM, comercio, afiliados (PT-4.4.3). Oculto por
	 * completo del sidebar en perfil institucional (register_admin_pages()
	 * lo gatea con is_active('crm')||is_active('commerce')||is_active('affiliates')).
	 */
	public function render_growth_hub_page(): void {
		$links = array(
			$this->build_nav_item( __( 'CRM', 'atora-lms' ), __( 'Contactos, pipeline y scoring.', 'atora-lms' ), admin_url( 'admin.php?page=atora-crm-v2' ) ),
			$this->build_nav_item( __( 'Comercio', 'atora-lms' ), __( 'Ventas, carritos y checkout.', 'atora-lms' ), admin_url( 'admin.php?page=clms-commercial-hub' ) ),
			$this->build_nav_item( __( 'Afiliados', 'atora-lms' ), __( 'Programa de afiliados y comisiones.', 'atora-lms' ), admin_url( 'admin.php?page=atora-affiliates' ) ),
			$this->build_nav_item( __( 'Marketing', 'atora-lms' ), __( 'Campañas de email y CRM.', 'atora-lms' ), admin_url( 'admin.php?page=clms-email-hub' ) ),
			$this->build_nav_item( __( 'Automatizaciones', 'atora-lms' ), __( 'Reglas automáticas.', 'atora-lms' ), admin_url( 'admin.php?page=atora-automations' ) ),
			$this->build_nav_item( __( 'Webhooks', 'atora-lms' ), __( 'Integraciones salientes.', 'atora-lms' ), admin_url( 'admin.php?page=atora-webhooks' ) ),
		);
		$this->render_simple_hub_page(
			__( 'Crecimiento', 'atora-lms' ),
			__( 'Crecimiento', 'atora-lms' ),
			__( 'CRM, comercio, afiliados y automatización comercial.', 'atora-lms' ),
			$links
		);
	}

	/** Hub "Informes" — analítica + reportes académicos (PT-4.4.3). */
	public function render_reports_hub_page(): void {
		$links = array(
			$this->build_nav_item( __( 'Analytics', 'atora-lms' ), __( 'Panel de analítica de la plataforma.', 'atora-lms' ), admin_url( 'admin.php?page=atora-analytics-dashboard' ) ),
			$this->build_nav_item( __( 'Reportes académicos', 'atora-lms' ), __( 'Reportes de desempeño académico.', 'atora-lms' ), admin_url( 'admin.php?page=clms-academic-reports' ) ),
			$this->build_nav_item( __( 'Formularios', 'atora-lms' ), __( 'Constructor de formularios.', 'atora-lms' ), admin_url( 'admin.php?page=atora-forms' ) ),
			$this->build_nav_item( __( 'Popups', 'atora-lms' ), __( 'Gestor de popups.', 'atora-lms' ), admin_url( 'admin.php?page=atora-popups' ) ),
		);
		$this->render_simple_hub_page(
			__( 'Informes', 'atora-lms' ),
			__( 'Informes', 'atora-lms' ),
			__( 'Analítica de plataforma y reportes académicos.', 'atora-lms' ),
			$links
		);
	}

	public function render_academic_hub_page(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$user_id      = get_current_user_id();
		$user         = wp_get_current_user();
		$display_name = $user->display_name ?: $user->user_login;
		$today        = wp_date( get_option( 'date_format' ) );
		$role_context = $this->get_current_role_context();
		$role_label   = $this->get_role_context_label( $role_context );

		$program_count = 'instructor' === $role_context
			? count( $this->get_programs_owned_by_user( $user_id ) )
			: $this->count_posts_by_type( 'lm_program' );
		$course_count = 'instructor' === $role_context
			? count( $this->get_courses_owned_by_user( $user_id ) )
			: $this->count_posts_by_type( 'lm_course' );
		$cohort_count = $this->count_posts_by_type( 'lm_cohort' );
		$lesson_count = 'instructor' === $role_context
			? count( $this->get_lessons_owned_by_user( $user_id ) )
			: $this->count_posts_by_type( 'lm_lesson' );
		$rubric_count = $this->count_posts_by_type( 'clms_rubric' );
		$teacher_count = $this->count_posts_by_type( 'atora_teacher' );
		$pending_submissions = 'instructor' === $role_context
			? $this->count_submissions_for_courses( $this->get_courses_owned_by_user( $user_id ), 'submitted' )
			: $this->count_submissions_by_status( 'submitted' );
		$academic_calendar_url = $this->get_academic_calendar_url();
		$rubrics_url           = $this->get_gradebook_rubrics_url();
		$academic_content_url  = $this->get_academic_content_url();
		$academic_operation_url = $this->can_access_calendar_page()
			? $academic_calendar_url
			: admin_url( 'admin.php?page=clms-dashboard' );
		$teacher_posts = get_posts(
			array(
				'post_type'              => 'atora_teacher',
				'post_status'            => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$stats = array(
			array( 'label' => __( 'Programas', 'atora-lms' ), 'value' => $program_count ),
			array( 'label' => __( 'Cursos', 'atora-lms' ), 'value' => $course_count ),
			array( 'label' => __( 'Lecciones', 'atora-lms' ), 'value' => $lesson_count ),
			array( 'label' => __( 'Evaluaciones pendientes', 'atora-lms' ), 'value' => $pending_submissions ),
		);
		$primary_links = array(
			array(
				'icon'  => '🏗️',
				'label' => __( 'Estructura académica', 'atora-lms' ),
				'url'   => $this->can_manage_programs() ? admin_url( 'edit.php?post_type=lm_program' ) : admin_url( 'admin.php?page=clms-my-profile' ),
			),
			array(
				'icon'  => '🧑‍🏫',
				'label' => __( 'Docentes y contenidos', 'atora-lms' ),
				'url'   => $academic_content_url,
			),
			array(
				'icon'  => '📝',
				'label' => __( 'Rúbricas y evaluación', 'atora-lms' ),
				'url'   => ( current_user_can( 'clms_grade_submissions' ) || current_user_can( 'manage_options' ) ) ? $rubrics_url : admin_url( 'admin.php?page=clms-messages' ),
			),
			array(
				'icon'  => '🧭',
				'label' => __( 'Operación académica', 'atora-lms' ),
				'url'   => $academic_operation_url,
			),
		);
		$primary_links = $this->unique_hub_items_by_url( $primary_links, 4, true );

		$cards = array(
			array(
				'variant'     => 'blue',
				'icon'        => '📚',
				'title'       => __( 'Estructura académica', 'atora-lms' ),
				'description' => __( 'Administra programas, cursos y cohortes con trazabilidad para planificación docente.', 'atora-lms' ),
				'features'    => array(
					sprintf( __( '%d programas activos.', 'atora-lms' ), absint( $program_count ) ),
					sprintf( __( '%d cursos publicados o en edición.', 'atora-lms' ), absint( $course_count ) ),
					sprintf( __( '%d cohortes registradas.', 'atora-lms' ), absint( $cohort_count ) ),
				),
				'url'         => $this->can_manage_programs() ? admin_url( 'edit.php?post_type=lm_program' ) : admin_url( 'admin.php?page=clms-my-profile' ),
				'button'      => __( 'Abrir estructura', 'atora-lms' ),
			),
			array(
				'variant'     => 'indigo',
				'icon'        => '🧑‍🏫',
				'title'       => __( 'Docentes y contenidos', 'atora-lms' ),
				'description' => __( 'Coordina equipo docente, producción de lecciones y consistencia pedagógica.', 'atora-lms' ),
				'features'    => array(
					sprintf( __( '%d perfiles docentes.', 'atora-lms' ), absint( $teacher_count ) ),
					sprintf( __( '%d lecciones en catálogo.', 'atora-lms' ), absint( $lesson_count ) ),
					__( 'Edición directa de perfiles, cursos y recursos.', 'atora-lms' ),
				),
				'url'         => $academic_content_url,
				'button'      => __( 'Gestionar contenidos', 'atora-lms' ),
			),
			array(
				'variant'     => 'amber',
				'icon'        => '📝',
				'title'       => __( 'Rúbricas y evaluación', 'atora-lms' ),
				'description' => __( 'Controla evaluación académica con SpeedGrade, Gradebook y rúbricas institucionales.', 'atora-lms' ),
				'features'    => array(
					sprintf( __( '%d rúbricas disponibles.', 'atora-lms' ), absint( $rubric_count ) ),
					sprintf( __( '%d entregas pendientes de revisión.', 'atora-lms' ), absint( $pending_submissions ) ),
					__( 'Trazabilidad completa del proceso de calificación.', 'atora-lms' ),
				),
				'url'         => ( current_user_can( 'clms_grade_submissions' ) || current_user_can( 'manage_options' ) ) ? $rubrics_url : admin_url( 'admin.php?page=clms-messages' ),
				'button'      => __( 'Ir a evaluación', 'atora-lms' ),
			),
			array(
				'variant'     => 'teal',
				'icon'        => '🧭',
				'title'       => __( 'Operación académica', 'atora-lms' ),
				'description' => __( 'Asistente de configuración académica, reportes y coordinación transversal con IA.', 'atora-lms' ),
				'features'    => array(
					__( 'Asistente académico para setup por pasos.', 'atora-lms' ),
					__( 'Reportes académicos para decisiones de mejora.', 'atora-lms' ),
					__( 'Calendario académico unificado para clases, evaluaciones y entregas.', 'atora-lms' ),
				),
				'url'         => $academic_operation_url,
				'button'      => __( 'Abrir operación', 'atora-lms' ),
			),
		);
		$cards = $this->unique_hub_items_by_url( $cards, 4, true );

		$quick_links = array_filter(
			array(
				$this->build_nav_item( __( 'Centro docente y contenidos', 'atora-lms' ), __( 'Edición de docentes, programas, cursos, lecciones, perfiles y recursos.', 'atora-lms' ), $academic_content_url ),
				$this->build_nav_item( __( 'Programas', 'atora-lms' ), __( 'Diplomados y arquitectura académica.', 'atora-lms' ), admin_url( 'edit.php?post_type=lm_program' ) ),
				$this->build_nav_item( __( 'Cursos', 'atora-lms' ), __( 'Diseño y operación de cursos.', 'atora-lms' ), admin_url( 'edit.php?post_type=lm_course' ) ),
				$this->build_nav_item( __( 'Cohortes', 'atora-lms' ), __( 'Grupos activos por programa o empresa.', 'atora-lms' ), admin_url( 'edit.php?post_type=lm_cohort' ) ),
				$this->build_nav_item( __( 'Docentes', 'atora-lms' ), __( 'Perfiles y presencia del equipo docente.', 'atora-lms' ), admin_url( 'edit.php?post_type=atora_teacher' ) ),
				$this->build_nav_item( __( 'Lecciones', 'atora-lms' ), __( 'Contenidos, actividades y secuencias.', 'atora-lms' ), admin_url( 'edit.php?post_type=lm_lesson' ) ),
				$this->build_nav_item( __( 'Calendario académico', 'atora-lms' ), __( 'Planificación de clases, entregas y eventos académicos.', 'atora-lms' ), $academic_calendar_url ),
				$this->build_nav_item( __( 'Rúbricas', 'atora-lms' ), __( 'Rúbricas conectadas al flujo de evaluación en Gradebook.', 'atora-lms' ), $rubrics_url ),
				$this->build_nav_item( __( 'Libro de calificaciones', 'atora-lms' ), __( 'Vista completa de progreso y notas.', 'atora-lms' ), admin_url( 'admin.php?page=clms-gradebook' ) ),
				$this->build_nav_item( __( 'SpeedGrade', 'atora-lms' ), __( 'Cola de revisión y feedback docente.', 'atora-lms' ), admin_url( 'admin.php?page=clms-speedgrader' ) ),
				$this->build_nav_item( __( 'Panel ATORA', 'atora-lms' ), __( 'Regresar al escritorio operativo.', 'atora-lms' ), admin_url( 'admin.php?page=clms-dashboard' ) ),
				$this->build_nav_item( __( 'CRM Hub', 'atora-lms' ), __( 'Coordinación de comunicación académica y comercial.', 'atora-lms' ), admin_url( 'admin.php?page=clms-crm-hub' ) ),
			)
		);
		$quick_links = $this->unique_hub_items_by_url( $quick_links, 0, true );
		?>
		<div class="wrap atora-hub atora-hub--academic">
			<div class="atora-hub__header">
				<div>
					<span class="atora-hub__context"><?php echo esc_html( $role_label ); ?></span>
					<h1 class="atora-hub__title">
						<?php
						/* translators: %s: user display name */
						echo esc_html( sprintf( __( 'Hub académico de %s', 'atora-lms' ), $display_name ) );
						?>
					</h1>
					<p class="atora-hub__date"><?php echo esc_html( $today ); ?> · <?php esc_html_e( 'Programas, cursos, cohortes, docentes y evaluación', 'atora-lms' ); ?></p>
					<p class="atora-hub__subtitle"><?php esc_html_e( 'Concentra el frente académico en un solo lugar operativo para tomar decisiones rápidas.', 'atora-lms' ); ?></p>
				</div>
			</div>

			<div class="atora-hub__primary">
				<div class="atora-hub__primary-grid">
					<?php foreach ( $primary_links as $item ) : ?>
						<a class="atora-hub__primary-link" href="<?php echo esc_url( (string) ( $item['url'] ?? '' ) ); ?>">
							<span><?php echo esc_html( (string) ( $item['label'] ?? '' ) ); ?></span>
							<span class="atora-hub__primary-icon" aria-hidden="true"><?php echo esc_html( (string) ( $item['icon'] ?? '→' ) ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="atora-hub__stats">
				<?php foreach ( $stats as $metric ) : ?>
					<div class="atora-hub__stat">
						<span class="atora-hub__stat-num"><?php echo esc_html( (string) ( $metric['value'] ?? 0 ) ); ?></span>
						<span class="atora-hub__stat-lbl"><?php echo esc_html( (string) ( $metric['label'] ?? '' ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="atora-hub__teachers">
				<h3 class="atora-hub__teachers-title"><?php esc_html_e( 'Docentes en el Hub académico', 'atora-lms' ); ?></h3>
				<p class="atora-hub__teachers-copy"><?php esc_html_e( 'Listado completo de docentes disponibles para gestión académica.', 'atora-lms' ); ?></p>
				<?php if ( empty( $teacher_posts ) ) : ?>
					<p><?php esc_html_e( 'No hay docentes registrados todavía.', 'atora-lms' ); ?></p>
				<?php else : ?>
					<div class="atora-hub__teachers-grid">
						<?php foreach ( $teacher_posts as $teacher_post_id ) : ?>
							<?php
							$teacher_post_id = absint( $teacher_post_id );
							$teacher_name    = get_the_title( $teacher_post_id );
							if ( '' === trim( (string) $teacher_name ) ) {
								$teacher_name = sprintf(
									/* translators: %d: teacher post id */
									__( 'Docente #%d', 'atora-lms' ),
									$teacher_post_id
								);
							}
							$teacher_url = current_user_can( 'edit_post', $teacher_post_id )
								? get_edit_post_link( $teacher_post_id, '' )
								: '';
							?>
							<?php if ( $teacher_url ) : ?>
								<a class="atora-hub__teacher-chip" href="<?php echo esc_url( $teacher_url ); ?>"><?php echo esc_html( $teacher_name ); ?></a>
							<?php else : ?>
								<span class="atora-hub__teacher-chip"><?php echo esc_html( $teacher_name ); ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="atora-hub__actions">
				<?php foreach ( $cards as $card ) : ?>
					<div class="atora-hub__action-card atora-hub__action-card--<?php echo esc_attr( sanitize_html_class( (string) $card['variant'] ) ); ?>">
						<div class="atora-hub__action-icon"><?php echo esc_html( (string) $card['icon'] ); ?></div>
						<h2 class="atora-hub__action-title"><?php echo esc_html( (string) $card['title'] ); ?></h2>
						<p class="atora-hub__action-desc"><?php echo esc_html( (string) $card['description'] ); ?></p>
						<ul class="atora-hub__feature-list">
							<?php foreach ( (array) ( $card['features'] ?? array() ) as $feature ) : ?>
								<li><?php echo esc_html( (string) $feature ); ?></li>
							<?php endforeach; ?>
						</ul>
						<a href="<?php echo esc_url( (string) $card['url'] ); ?>" class="atora-hub__action-btn">
							<?php echo esc_html( (string) $card['button'] ); ?> →
						</a>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="atora-hub__quick">
				<h3 class="atora-hub__quick-title"><?php esc_html_e( 'Navegación académica rápida', 'atora-lms' ); ?></h3>
				<div class="atora-hub__quick-grid">
					<?php foreach ( $quick_links as $item ) : ?>
						<a class="atora-hub__quick-link" href="<?php echo esc_url( (string) $item['url'] ); ?>">
							<strong><?php echo esc_html( (string) $item['title'] ); ?></strong>
							<span><?php echo esc_html( $this->truncate_admin_text( (string) $item['description'], 76 ) ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	public function render_academic_content_page(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$actions = array(
			array(
				'title'       => __( 'Docentes', 'atora-lms' ),
				'description' => __( 'Editar perfiles docentes y su información pública.', 'atora-lms' ),
				'url'         => admin_url( 'edit.php?post_type=atora_teacher' ),
				'visible'     => current_user_can( 'clms_view_teacher_dashboard' ) || current_user_can( 'manage_options' ),
			),
			array(
				'title'       => __( 'Programas', 'atora-lms' ),
				'description' => __( 'Gestionar programas académicos y su estructura.', 'atora-lms' ),
				'url'         => admin_url( 'edit.php?post_type=lm_program' ),
				'visible'     => $this->can_manage_programs(),
			),
			array(
				'title'       => __( 'Cursos', 'atora-lms' ),
				'description' => __( 'Gestionar cursos, módulos y configuración académica.', 'atora-lms' ),
				'url'         => admin_url( 'edit.php?post_type=lm_course' ),
				'visible'     => $this->can_manage_courses(),
			),
			array(
				'title'       => __( 'Lecciones', 'atora-lms' ),
				'description' => __( 'Crear o editar lecciones, recursos y secuencias.', 'atora-lms' ),
				'url'         => admin_url( 'edit.php?post_type=lm_lesson' ),
				'visible'     => $this->can_manage_lessons(),
			),
			array(
				'title'       => __( 'Perfiles docentes', 'atora-lms' ),
				'description' => __( 'Actualizar presencia docente y datos de instructor.', 'atora-lms' ),
				'url'         => admin_url( 'admin.php?page=clms-instructor-profile' ),
				'visible'     => current_user_can( 'clms_view_teacher_dashboard' ) || current_user_can( 'manage_options' ),
			),
			array(
				'title'       => __( 'Recursos asociados', 'atora-lms' ),
				'description' => __( 'Gestionar biblioteca de materiales y archivos académicos.', 'atora-lms' ),
				'url'         => admin_url( 'upload.php' ),
				'visible'     => current_user_can( 'upload_files' ),
			),
		);
		$actions = array_values(
			array_filter(
				$actions,
				function ( array $item ): bool {
					return ! empty( $item['visible'] );
				}
			)
		);
		$actions = $this->unique_hub_items_by_url( $actions, 0, true );
		?>
		<div class="wrap atora-hub atora-hub--academic">
			<div class="atora-hub__header">
				<div>
					<span class="atora-hub__context"><?php esc_html_e( 'Hub académico', 'atora-lms' ); ?></span>
					<h1 class="atora-hub__title"><?php esc_html_e( 'Docentes y contenidos', 'atora-lms' ); ?></h1>
					<p class="atora-hub__subtitle"><?php esc_html_e( 'Concentra edición académica de docentes, programas, cursos, lecciones, perfiles y recursos en un solo lugar.', 'atora-lms' ); ?></p>
				</div>
			</div>
			<div class="atora-hub__quick">
				<h2 class="atora-hub__quick-title"><?php esc_html_e( 'Acciones disponibles', 'atora-lms' ); ?></h2>
				<div class="atora-hub__quick-grid">
					<?php foreach ( $actions as $action ) : ?>
						<a class="atora-hub__quick-link" href="<?php echo esc_url( (string) $action['url'] ); ?>">
							<strong><?php echo esc_html( (string) $action['title'] ); ?></strong>
							<span><?php echo esc_html( (string) $action['description'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
				<p style="margin-top:14px;">
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=clms-academic-hub' ) ); ?>">
						<?php esc_html_e( 'Volver al Hub académico', 'atora-lms' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	public function render_crm_hub_page(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		if ( $this->is_crm_v2_enabled() ) {
			$app_class = '\ATORA\CRM_V2\CRM_V2_App';
			$app_file  = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'crm-v2/class-crm-v2-app.php' : '';
			if ( $app_file && file_exists( $app_file ) ) {
				require_once $app_file;
			}
			if ( class_exists( $app_class ) && method_exists( $app_class, 'can_access' ) && method_exists( $app_class, 'render_page' ) ) {
				if ( method_exists( $app_class, 'init' ) ) {
					$app_class::init();
				}
				if ( $app_class::can_access() ) {
					$app_class::render_page( 'atora-crm-v2' );
					return;
				}
			}
		}

		$user_id      = get_current_user_id();
		$user         = wp_get_current_user();
		$display_name = $user->display_name ?: $user->user_login;
		$today        = wp_date( get_option( 'date_format' ) );
		$role_context = $this->get_current_role_context();
		$role_label   = $this->get_role_context_label( $role_context );
		$crm_snapshot = $this->get_crm_summary_snapshot();
		$msg_snapshot = $this->get_message_hub_snapshot( $user_id );

		$students_inbox = count( (array) ( $msg_snapshot['compose']['students'] ?? array() ) );
		$courses_inbox  = count( (array) ( $msg_snapshot['compose']['courses'] ?? array() ) );
		$unread_count   = absint( $msg_snapshot['stats']['unread'] ?? 0 );
		$reco_count     = absint( $msg_snapshot['stats']['recommendations'] ?? 0 );
		$pending_emails = absint( $crm_snapshot['pending_emails'] ?? 0 );
		$teacher_users  = $this->count_users_with_capability( 'clms_view_teacher_dashboard' );
		$crm_console_url = admin_url( 'admin.php?page=atora-crm' );
		if ( $this->is_crm_v2_enabled() ) {
			$app_class = '\ATORA\CRM_V2\CRM_V2_App';
			$app_file  = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'crm-v2/class-crm-v2-app.php' : '';
			if ( $app_file && file_exists( $app_file ) ) {
				require_once $app_file;
			}

			$can_open_v2 = false;
			if ( class_exists( $app_class ) && method_exists( $app_class, 'can_access' ) ) {
				$can_open_v2 = (bool) $app_class::can_access();
			}

			if ( $can_open_v2 ) {
				$crm_console_url = admin_url( 'admin.php?page=atora-crm-v2' );
			}
		}

		$role_title = __( 'CRM Hub operativo', 'atora-lms' );
			$role_copy  = __( 'Conecta contactos, comunicación y seguimiento para sostener una experiencia académica y comercial coherente.', 'atora-lms' );
			$stats      = array(
				array( 'label' => __( 'Contactos', 'atora-lms' ), 'value' => absint( $crm_snapshot['contacts_count'] ) ),
				array( 'label' => __( 'Leads', 'atora-lms' ), 'value' => absint( $crm_snapshot['leads_count'] ) ),
				array( 'label' => __( 'Mensajes sin leer', 'atora-lms' ), 'value' => $unread_count ),
				array( 'label' => __( 'Recomendaciones', 'atora-lms' ), 'value' => $reco_count ),
			);
			$cards      = array();
			$crm_class  = '\ATORA\CRM\CRM';
			$crm_log_summary_enabled = class_exists( $crm_class ) && method_exists( $crm_class, 'can_access_crm' )
				? (bool) $crm_class::can_access_crm( $user_id )
				: current_user_can( 'manage_options' );
			$crm_log_summary_bootstrap = array();
			if ( $crm_log_summary_enabled ) {
				$crm_log_summary_bootstrap = class_exists( $crm_class ) && method_exists( $crm_class, 'get_message_log_summary_bootstrap' )
					? (array) $crm_class::get_message_log_summary_bootstrap()
					: array(
						'endpoint' => esc_url_raw( rest_url( 'atora/v1/crm/messages/log/summary' ) ),
						'nonce'    => wp_create_nonce( 'wp_rest' ),
						'labels' => array(
							'channel'    => array(),
							'status'     => array(),
							'event_type' => array(),
						),
						'empty'  => array(
							'channel'    => __( 'Sin actividad de canales.', 'atora-lms' ),
							'status'     => __( 'Sin estados registrados.', 'atora-lms' ),
							'event_type' => __( 'Sin eventos de trazabilidad.', 'atora-lms' ),
						),
						'error'  => __( 'No fue posible cargar la trazabilidad de mensajes.', 'atora-lms' ),
					);
			}

		if ( 'instructor' === $role_context ) {
			$pending = absint( $this->count_submissions_for_courses( $this->get_courses_owned_by_user( $user_id ), 'submitted' ) );
			$role_title = __( 'CRM Hub docente', 'atora-lms' );
			$role_copy  = __( 'Enfocado en comunicación con estudiantes, feedback oportuno y acompañamiento académico continuo.', 'atora-lms' );
			$stats      = array(
				array( 'label' => __( 'Estudiantes en cartera', 'atora-lms' ), 'value' => $students_inbox ),
				array( 'label' => __( 'Cursos con seguimiento', 'atora-lms' ), 'value' => $courses_inbox ),
				array( 'label' => __( 'Mensajes sin leer', 'atora-lms' ), 'value' => $unread_count ),
				array( 'label' => __( 'Entregas pendientes', 'atora-lms' ), 'value' => $pending ),
			);

			$cards = array(
				array(
					'variant'     => 'indigo',
					'icon'        => '💬',
					'title'       => __( 'Comunicación con estudiantes', 'atora-lms' ),
					'description' => __( 'Centraliza mensajes por curso, recomendaciones y seguimiento personalizado.', 'atora-lms' ),
					'features'    => array(
						sprintf( __( '%d estudiantes disponibles para contactar.', 'atora-lms' ), $students_inbox ),
						sprintf( __( '%d cursos activos en comunicación.', 'atora-lms' ), $courses_inbox ),
						sprintf( __( '%d mensajes sin leer para responder.', 'atora-lms' ), $unread_count ),
					),
					'url'         => admin_url( 'admin.php?page=clms-messages' ),
					'button'      => __( 'Abrir bandeja docente', 'atora-lms' ),
				),
				array(
					'variant'     => 'amber',
					'icon'        => '📝',
					'title'       => __( 'Feedback y evaluación', 'atora-lms' ),
					'description' => __( 'Convierte entregas en conversaciones accionables para mejorar el avance del estudiante.', 'atora-lms' ),
					'features'    => array(
						sprintf( __( '%d entregas pendientes de revisar.', 'atora-lms' ), $pending ),
						__( 'Acceso a SpeedGrade y libro de calificaciones.', 'atora-lms' ),
						__( 'Historial operativo por estudiante y curso.', 'atora-lms' ),
					),
					'url'         => ( current_user_can( 'clms_grade_submissions' ) || current_user_can( 'manage_options' ) ) ? admin_url( 'admin.php?page=clms-speedgrader' ) : admin_url( 'admin.php?page=clms-messages' ),
					'button'      => __( 'Ir a seguimiento', 'atora-lms' ),
				),
				array(
					'variant'     => 'teal',
					'icon'        => '🎓',
					'title'       => __( 'Coordinación académica', 'atora-lms' ),
					'description' => __( 'Conecta comunicación con estructura académica para anticipar riesgo y mejorar retención.', 'atora-lms' ),
					'features'    => array(
						sprintf( __( '%d recomendaciones activas para acompañamiento.', 'atora-lms' ), $reco_count ),
						__( 'Integra CRM con Hub académico en un clic.', 'atora-lms' ),
						__( 'Decisiones más rápidas por cohorte y curso.', 'atora-lms' ),
					),
					'url'         => admin_url( 'admin.php?page=clms-academic-hub' ),
					'button'      => __( 'Abrir Hub académico', 'atora-lms' ),
				),
			);
		} elseif ( 'admin' === $role_context ) {
			$role_title = __( 'CRM Hub ejecutivo', 'atora-lms' );
			$role_copy  = __( 'Coordinación de comunicación con docentes, estudiantes y clientes potenciales para sostener crecimiento y calidad académica.', 'atora-lms' );
			$stats      = array(
				array( 'label' => __( 'Contactos totales', 'atora-lms' ), 'value' => absint( $crm_snapshot['contacts_count'] ) ),
				array( 'label' => __( 'Leads potenciales', 'atora-lms' ), 'value' => absint( $crm_snapshot['leads_count'] ) ),
				array( 'label' => __( 'Estudiantes CRM', 'atora-lms' ), 'value' => absint( $crm_snapshot['students_count'] ) ),
				array( 'label' => __( 'Docentes activos', 'atora-lms' ), 'value' => $teacher_users ),
			);

			$cards = array(
				array(
					'variant'     => 'indigo',
					'icon'        => '👥',
					'title'       => __( 'Relación comercial y académica', 'atora-lms' ),
					'description' => __( 'Visibilidad completa de clientes activos, potenciales y estudiantes en tránsito académico.', 'atora-lms' ),
					'features'    => array(
						sprintf( __( '%d contactos trazables en CRM.', 'atora-lms' ), absint( $crm_snapshot['contacts_count'] ) ),
						sprintf( __( '%d leads listos para nurturing.', 'atora-lms' ), absint( $crm_snapshot['leads_count'] ) ),
						sprintf( __( '%d estudiantes en operación.', 'atora-lms' ), absint( $crm_snapshot['students_count'] ) ),
					),
					'url'         => $crm_console_url,
					'button'      => __( 'Abrir CRM', 'atora-lms' ),
				),
				array(
					'variant'     => 'teal',
					'icon'        => '✉️',
					'title'       => __( 'Comunicación multiaudiencia', 'atora-lms' ),
					'description' => __( 'Orquesta envíos y conversaciones entre administración, docentes, estudiantes y prospectos.', 'atora-lms' ),
					'features'    => array(
						sprintf( __( '%d correos pendientes en cola.', 'atora-lms' ), $pending_emails ),
						sprintf( __( '%d mensajes internos sin leer.', 'atora-lms' ), $unread_count ),
						__( 'Configura Email, WhatsApp y Telegram por área desde un panel central.', 'atora-lms' ),
					),
					'url'         => admin_url( 'admin.php?page=clms-settings-hub' ),
					'button'      => __( 'Configurar canales', 'atora-lms' ),
				),
				array(
					'variant'     => 'slate',
					'icon'        => '🤝',
					'title'       => __( 'Coordinación con docentes', 'atora-lms' ),
					'description' => __( 'Alinea comunicación interna con prioridades de equipo docente y metas de experiencia.', 'atora-lms' ),
					'features'    => array(
						sprintf( __( '%d docentes con acceso operativo.', 'atora-lms' ), $teacher_users ),
						__( 'Bandeja interna centralizada para liderazgo.', 'atora-lms' ),
						__( 'Conexión directa con Hub académico y control central.', 'atora-lms' ),
					),
					'url'         => admin_url( 'admin.php?page=clms-messages' ),
					'button'      => __( 'Abrir comunicación interna', 'atora-lms' ),
				),
				array(
					'variant'     => 'amber',
					'icon'        => '📊',
					'title'       => __( 'Analytics y tendencias', 'atora-lms' ),
					'description' => __( 'Consulta la analítica operativa y comercial sin salir del flujo de CRM Hub.', 'atora-lms' ),
					'features'    => array(
						__( 'Engagement, revenue y rendimiento en una sola vista.', 'atora-lms' ),
						__( 'Cruza señales académicas con salud comercial.', 'atora-lms' ),
						__( 'Acceso directo al tablero completo de Analytics.', 'atora-lms' ),
					),
					'url'         => $this->get_analytics_hub_url(),
					'button'      => __( 'Abrir Analytics', 'atora-lms' ),
				),
			);
		} else {
			$cards = array(
				array(
					'variant'     => 'indigo',
					'icon'        => '👥',
					'title'       => __( 'Seguimiento de contactos', 'atora-lms' ),
					'description' => __( 'Organiza el ciclo de contacto y continuidad de relación desde el frente operativo.', 'atora-lms' ),
					'features'    => array(
						sprintf( __( '%d contactos totales registrados.', 'atora-lms' ), absint( $crm_snapshot['contacts_count'] ) ),
						sprintf( __( '%d leads en seguimiento.', 'atora-lms' ), absint( $crm_snapshot['leads_count'] ) ),
						__( 'Sincronización con mensajes y operación académica.', 'atora-lms' ),
					),
					'url'         => current_user_can( 'manage_options' ) ? $crm_console_url : admin_url( 'admin.php?page=clms-messages' ),
					'button'      => __( 'Abrir seguimiento', 'atora-lms' ),
				),
				array(
					'variant'     => 'teal',
					'icon'        => '💬',
					'title'       => __( 'Mensajería operativa', 'atora-lms' ),
					'description' => __( 'Conversa con contexto académico y comercial desde una bandeja unificada.', 'atora-lms' ),
					'features'    => array(
						sprintf( __( '%d mensajes sin leer.', 'atora-lms' ), $unread_count ),
						sprintf( __( '%d recomendaciones activas.', 'atora-lms' ), $reco_count ),
						__( 'Flujos de comunicación adaptados por rol.', 'atora-lms' ),
					),
					'url'         => admin_url( 'admin.php?page=clms-messages' ),
					'button'      => __( 'Ir a mensajes', 'atora-lms' ),
				),
				array(
					'variant'     => 'blue',
					'icon'        => '🎓',
					'title'       => __( 'Puente académico', 'atora-lms' ),
					'description' => __( 'Lleva la comunicación al contexto de cursos, cohortes y evaluación para actuar más rápido.', 'atora-lms' ),
					'features'    => array(
						__( 'Acceso directo al Hub académico.', 'atora-lms' ),
						__( 'Conexión con rúbricas y seguimiento.', 'atora-lms' ),
						__( 'Experiencia integrada de usuario final.', 'atora-lms' ),
					),
					'url'         => admin_url( 'admin.php?page=clms-academic-hub' ),
					'button'      => __( 'Abrir Hub académico', 'atora-lms' ),
				),
			);
		}

		$quick_links = array_merge(
			$this->get_operational_hub_links( 'clms-crm-hub', 6 ),
			$this->get_hub_quick_links( $role_context, 10 )
		);
		$quick_links = $this->unique_hub_items_by_url( $quick_links, 12, true );
		?>
		<div class="wrap atora-hub">
			<div class="atora-hub__header">
				<div>
					<span class="atora-hub__context"><?php echo esc_html( $role_label ); ?></span>
					<h1 class="atora-hub__title"><?php echo esc_html( $role_title ); ?></h1>
					<p class="atora-hub__date">
						<?php
						/* translators: 1: date, 2: user name */
						echo esc_html( sprintf( __( '%1$s · Responsable: %2$s', 'atora-lms' ), $today, $display_name ) );
						?>
					</p>
					<p class="atora-hub__subtitle"><?php echo esc_html( $role_copy ); ?></p>
				</div>
			</div>

				<div class="atora-hub__stats">
					<?php foreach ( $stats as $metric ) : ?>
						<div class="atora-hub__stat">
							<span class="atora-hub__stat-num"><?php echo esc_html( (string) ( $metric['value'] ?? 0 ) ); ?></span>
							<span class="atora-hub__stat-lbl"><?php echo esc_html( (string) ( $metric['label'] ?? '' ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>

				<?php if ( $crm_log_summary_enabled ) : ?>
					<section
						class="atora-hub__log"
						id="atora-hub-log-summary"
						data-atora-log-summary="1"
						data-summary="<?php echo esc_attr( wp_json_encode( $crm_log_summary_bootstrap ) ); ?>"
						data-summary-item-class="atora-hub__log-item"
						data-summary-empty-class="atora-hub__log-item atora-hub__log-item--empty"
						data-summary-key-class="atora-hub__log-key"
						data-summary-value-class="atora-hub__log-value"
					>
						<div class="atora-hub__log-head">
							<div>
								<h2 class="atora-hub__log-title"><?php esc_html_e( 'Trazabilidad de mensajería', 'atora-lms' ); ?></h2>
								<p class="atora-hub__log-copy"><?php esc_html_e( 'Resumen agregado desde logs para revisar ejecución por canal, estado y tipo de evento.', 'atora-lms' ); ?></p>
								<span class="atora-hub__log-state" data-summary-loading><?php esc_html_e( 'Actualizando trazabilidad…', 'atora-lms' ); ?></span>
								<span class="atora-hub__log-state atora-hub__log-state--error" data-summary-error hidden></span>
							</div>
							<div class="atora-hub__log-total">
								<strong data-summary-total>0</strong>
								<span><?php esc_html_e( 'Eventos de log', 'atora-lms' ); ?></span>
							</div>
						</div>
						<div class="atora-hub__log-grid">
							<div class="atora-hub__log-card">
								<h3><?php esc_html_e( 'Canales', 'atora-lms' ); ?></h3>
								<ul class="atora-hub__log-list" data-summary-list="channel">
									<li class="atora-hub__log-item atora-hub__log-item--empty"><?php esc_html_e( 'Sin actividad de canales.', 'atora-lms' ); ?></li>
								</ul>
							</div>
							<div class="atora-hub__log-card">
								<h3><?php esc_html_e( 'Estados', 'atora-lms' ); ?></h3>
								<ul class="atora-hub__log-list" data-summary-list="status">
									<li class="atora-hub__log-item atora-hub__log-item--empty"><?php esc_html_e( 'Sin estados registrados.', 'atora-lms' ); ?></li>
								</ul>
							</div>
							<div class="atora-hub__log-card">
								<h3><?php esc_html_e( 'Eventos', 'atora-lms' ); ?></h3>
								<ul class="atora-hub__log-list" data-summary-list="event_type">
									<li class="atora-hub__log-item atora-hub__log-item--empty"><?php esc_html_e( 'Sin eventos de trazabilidad.', 'atora-lms' ); ?></li>
								</ul>
							</div>
						</div>
					</section>
				<?php endif; ?>

				<div class="atora-hub__actions">
					<?php foreach ( $cards as $card ) : ?>
						<div class="atora-hub__action-card atora-hub__action-card--<?php echo esc_attr( sanitize_html_class( (string) $card['variant'] ) ); ?>">
						<div class="atora-hub__action-icon"><?php echo esc_html( (string) $card['icon'] ); ?></div>
						<h2 class="atora-hub__action-title"><?php echo esc_html( (string) $card['title'] ); ?></h2>
						<p class="atora-hub__action-desc"><?php echo esc_html( (string) $card['description'] ); ?></p>
						<ul class="atora-hub__feature-list">
							<?php foreach ( (array) ( $card['features'] ?? array() ) as $feature ) : ?>
								<li><?php echo esc_html( (string) $feature ); ?></li>
							<?php endforeach; ?>
						</ul>
						<a href="<?php echo esc_url( (string) $card['url'] ); ?>" class="atora-hub__action-btn">
							<?php echo esc_html( (string) $card['button'] ); ?> →
						</a>
					</div>
				<?php endforeach; ?>
			</div>

					<div class="atora-hub__quick">
						<h3 class="atora-hub__quick-title"><?php esc_html_e( 'Acciones conectadas y hubs', 'atora-lms' ); ?></h3>
					<div class="atora-hub__quick-grid">
						<?php foreach ( $quick_links as $item ) : ?>
							<a class="atora-hub__quick-link" href="<?php echo esc_url( (string) $item['url'] ); ?>">
							<strong><?php echo esc_html( (string) $item['title'] ); ?></strong>
							<span><?php echo esc_html( $this->truncate_admin_text( (string) $item['description'], 76 ) ); ?></span>
						</a>
					<?php endforeach; ?>
					</div>
				</div>

				</div>
				<?php
			}

	/**
	 * Legacy method kept for back-compat in case other code calls it directly.
	 *
	 * @deprecated Use render_escritorio_page() which now redirects to index.php.
	 */
	public function render_escritorio_page_legacy(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$this->render_admin_styles();

		$role_context = $this->get_current_role_context();
		$role_label   = $this->get_role_context_label( $role_context );
		$user_id      = get_current_user_id();
		$widgets      = $this->get_escritorio_widgets( $role_context, $user_id );
		?>
		<div class="wrap atora-escritorio">
			<div class="atora-escritorio-header">
				<h1><?php esc_html_e( 'Escritorio', 'atora-lms' ); ?></h1>
				<p class="atora-escritorio-role">
					<?php
					/* translators: %s: role label */
					printf( esc_html__( 'Vista: %s', 'atora-lms' ), esc_html( $role_label ) );
					?>
				</p>
			</div>
			<div class="atora-escritorio-grid" id="atora-widgets">
				<?php
				foreach ( $widgets as $widget ) {
					$colspan  = isset( $widget['colspan'] ) ? (int) $widget['colspan'] : 1;
					$callback = $widget['callback'];
					echo '<div class="atora-widget" id="atora-widget-' . esc_attr( $widget['id'] ) . '" data-colspan="' . esc_attr( $colspan ) . '">';
					echo $this->$callback(); // phpcs:ignore WordPress.Security.EscapeOutput
					echo '</div>';
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Devuelve el listado de widgets filtrado por rol.
	 *
	 * @param string $role_context  'admin' | 'instructor' | 'collaborator' | 'student'
	 * @param int    $user_id
	 * @return array<int,array{id:string,title:string,callback:string,roles:string[],colspan:int}>
	 */
	protected function get_escritorio_widgets( string $role_context, int $user_id ): array {
		$widgets = array(
			array(
				'id'       => 'pulso',
				'title'    => __( 'Pulso académico', 'atora-lms' ),
				'callback' => 'render_widget_pulso',
				'roles'    => array( 'admin', 'instructor', 'collaborator', 'student' ),
				'colspan'  => 2,
			),
			array(
				'id'       => 'evaluacion',
				'title'    => __( 'Cola de evaluación', 'atora-lms' ),
				'callback' => 'render_widget_evaluacion',
				'roles'    => array( 'admin', 'instructor' ),
				'colspan'  => 1,
			),
			array(
				'id'       => 'ia',
				'title'    => __( 'Asistente IA', 'atora-lms' ),
				'callback' => 'render_widget_ia',
				'roles'    => array( 'admin', 'instructor' ),
				'colspan'  => 1,
			),
				array(
					'id'       => 'comercio',
					'title'    => __( 'Comercial', 'atora-lms' ),
					'callback' => 'render_widget_comercio',
					'roles'    => array( 'admin', 'collaborator' ),
					'colspan'  => 1,
			),
			array(
				'id'       => 'mensajes',
				'title'    => __( 'Mensajes', 'atora-lms' ),
				'callback' => 'render_widget_mensajes',
				'roles'    => array( 'admin', 'instructor', 'collaborator', 'student' ),
				'colspan'  => 1,
			),
			array(
				'id'       => 'perfil',
				'title'    => __( 'Mi perfil', 'atora-lms' ),
				'callback' => 'render_widget_perfil',
				'roles'    => array( 'admin', 'instructor', 'collaborator', 'student' ),
				'colspan'  => 1,
			),
			array(
				'id'       => 'salud',
				'title'    => __( 'Salud del sistema', 'atora-lms' ),
				'callback' => 'render_widget_salud',
				'roles'    => array( 'admin' ),
				'colspan'  => 1,
			),
			array(
				'id'       => 'config',
				'title'    => __( 'Configuración', 'atora-lms' ),
				'callback' => 'render_widget_config',
				'roles'    => array( 'admin' ),
				'colspan'  => 1,
			),
		);

		return array_values(
			array_filter(
				$widgets,
				static function ( array $w ) use ( $role_context ): bool {
					return in_array( $role_context, $w['roles'], true );
				}
			)
		);
	}

	// ── Widgets individuales ─────────────────────────────────────────────────────

	protected function render_widget_pulso(): string {
		$role_context = $this->get_current_role_context();
		$user_id      = get_current_user_id();
		$metrics      = $this->get_role_summary_metrics( $role_context, $user_id );

		$analytics        = clms_core('CLMS_Analytics');
		if ( ! $analytics && class_exists( 'CLMS_Analytics' ) ) {
			$analytics = new CLMS_Analytics();
		}
		$retention  = ( $analytics && method_exists( $analytics, 'get_event_series' ) )
			? $analytics->get_event_series( array( 'lesson_completed' ), 42, 'week', 'count' )
			: array();
		$engagement = ( $analytics && method_exists( $analytics, 'get_event_series' ) )
			? $analytics->get_event_series( array( 'submission_created' ), 42, 'week', 'count' )
			: array();

		$retention_values  = array_map( static fn( $b ) => (float) ( $b['value'] ?? 0 ), $retention );
		$engagement_values = array_map( static fn( $b ) => (float) ( $b['value'] ?? 0 ), $engagement );

		ob_start();
		?>
		<div class="atora-widget-head">
			<h3><?php esc_html_e( 'Pulso académico', 'atora-lms' ); ?></h3>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-analytics' ) ); ?>" class="atora-widget-link">
				<?php esc_html_e( 'Ver completo', 'atora-lms' ); ?>
			</a>
		</div>
		<div class="atora-widget-body">
			<div class="atora-widget-metrics">
				<?php foreach ( $metrics as $metric ) : ?>
					<div class="atora-widget-metric">
						<strong><?php echo esc_html( (string) $metric['value'] ); ?></strong>
						<span><?php echo esc_html( $metric['label'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( ! empty( $retention_values ) ) : ?>
				<div style="margin-top:14px">
					<p style="font-size:11px;color:var(--clms-muted);margin:0 0 4px;text-transform:uppercase;letter-spacing:.05em">
						<?php esc_html_e( 'Retención (lecciones completadas)', 'atora-lms' ); ?>
					</p>
					<?php echo $this->render_sparkline_svg( $retention_values, '#22c55e' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $engagement_values ) ) : ?>
				<div style="margin-top:10px">
					<p style="font-size:11px;color:var(--clms-muted);margin:0 0 4px;text-transform:uppercase;letter-spacing:.05em">
						<?php esc_html_e( 'Engagement (entregas)', 'atora-lms' ); ?>
					</p>
					<?php echo $this->render_sparkline_svg( $engagement_values, '#6366f1' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	protected function render_widget_evaluacion(): string {
		if ( ! current_user_can( 'clms_grade_submissions' ) && ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$user_id    = get_current_user_id();
		$role       = $this->get_current_role_context();
		$course_ids = ( 'admin' === $role )
			? array()
			: $this->get_courses_owned_by_user( $user_id );

		$pending_count  = $this->count_submissions_for_courses( $course_ids, 'submitted' );
		$ai_count       = $this->count_submissions_for_courses( $course_ids, 'in_review' );

		// Últimas 5 entregas pendientes.
		$meta_q = array(
			array(
				'key'   => '_clms_submission_status',
				'value' => 'submitted',
			),
		);
		if ( ! empty( $course_ids ) ) {
			$meta_q[] = array(
				'key'     => '_clms_submission_course_id',
				'value'   => $course_ids,
				'compare' => 'IN',
				'type'    => 'NUMERIC',
			);
		}
		$recent = get_posts( array(
			'post_type'              => 'clms_submission',
			'post_status'            => 'publish',
			'posts_per_page'         => 5,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'meta_query'             => $meta_q,
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		) );

		$grading = $this->get_grading_instance();

		ob_start();
		?>
		<div class="atora-widget-head">
			<h3><?php esc_html_e( 'Cola de evaluación', 'atora-lms' ); ?></h3>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-speedgrader' ) ); ?>" class="atora-widget-link">
				<?php esc_html_e( 'Ver completo', 'atora-lms' ); ?>
			</a>
		</div>
		<div class="atora-widget-body">
			<div class="atora-widget-metrics">
				<div class="atora-widget-metric">
					<strong><?php echo esc_html( $pending_count ); ?></strong>
					<span><?php esc_html_e( 'Pendientes', 'atora-lms' ); ?></span>
				</div>
				<div class="atora-widget-metric">
					<strong><?php echo esc_html( $ai_count ); ?></strong>
					<span><?php esc_html_e( 'En revisión', 'atora-lms' ); ?></span>
				</div>
			</div>
			<?php if ( ! empty( $recent ) ) : ?>
				<ul class="atora-widget-list" style="margin-top:12px">
					<?php foreach ( $recent as $sub ) :
						$student_id   = absint( get_post_meta( $sub->ID, '_clms_submission_user_id', true ) );
						$sub_course   = absint( get_post_meta( $sub->ID, '_clms_submission_course_id', true ) );
						$sub_status   = get_post_meta( $sub->ID, '_clms_submission_status', true ) ?: 'submitted';
						$student      = get_userdata( $student_id );
						$student_name = $student ? ( $student->display_name ?: $student->user_login ) : "#{$student_id}";
						$course_title = $sub_course ? get_the_title( $sub_course ) : '—';
						$date         = mysql2date( 'd/m/Y', $sub->post_date );
						$sg_url       = ( $grading && method_exists( $grading, 'get_speedgrade_url' ) )
							? $grading->get_speedgrade_url( $sub->ID, admin_url( 'admin.php?page=clms-speedgrader' ) )
							: get_edit_post_link( $sub->ID, '' );
					?>
						<li>
							<span>
								<strong><?php echo esc_html( $student_name ); ?></strong>
								<small style="display:block;color:var(--clms-muted);font-size:11px"><?php echo esc_html( $course_title ); ?></small>
							</span>
							<span style="display:flex;align-items:center;gap:8px">
								<small style="color:var(--clms-muted)"><?php echo esc_html( $date ); ?></small>
								<?php if ( $sg_url ) : ?>
									<a href="<?php echo esc_url( $sg_url ); ?>" class="button button-small"><?php esc_html_e( 'Revisar', 'atora-lms' ); ?></a>
								<?php endif; ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p style="color:var(--clms-muted);font-size:13px;margin-top:12px"><?php esc_html_e( 'No hay entregas pendientes.', 'atora-lms' ); ?></p>
			<?php endif; ?>
			<div style="margin-top:12px;display:flex;gap:8px">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-gradebook' ) ); ?>" class="button button-small"><?php esc_html_e( 'Gradebook', 'atora-lms' ); ?></a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	protected function render_widget_ia(): string {
		if ( ! current_user_can( 'clms_manage_courses' ) && ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$ai_settings = class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_settings' )
			? (array) CLMS_AI_Settings_Service::get_settings()
			: (array) get_option( 'clms_ai_settings', array() );
		$provider = class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_provider' )
			? (string) CLMS_AI_Settings_Service::get_provider()
			: sanitize_key( (string) ( $ai_settings['provider'] ?? '' ) );
		$model = '';
		if ( class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_provider_model' ) ) {
			$model = (string) CLMS_AI_Settings_Service::get_provider_model( $provider );
		} else {
			$model_map = array(
				'openai'    => 'openai_model',
				'anthropic' => 'anthropic_model',
				'gemini'    => 'gemini_model',
			);
			$model_key = $model_map[ $provider ] ?? 'openai_model';
			$model     = sanitize_text_field( (string) ( $ai_settings[ $model_key ] ?? '' ) );
		}
		$provider = sanitize_text_field( $provider );
		$model    = sanitize_text_field( $model );
		$provider_label = $provider ? ucfirst( $provider ) . ( $model ? ' · ' . $model : '' ) : __( 'No configurado', 'atora-lms' );

		$tools = array(
			array( 'id' => 'plan',       'label' => __( 'Planificar curso', 'atora-lms' ) ),
			array( 'id' => 'research',   'label' => __( 'Investigar', 'atora-lms' ) ),
			array( 'id' => 'improve',    'label' => __( 'Mejorar lección', 'atora-lms' ) ),
			array( 'id' => 'slides',     'label' => __( 'Presentación', 'atora-lms' ) ),
			array( 'id' => 'rubric',     'label' => __( 'Rúbrica', 'atora-lms' ) ),
			array( 'id' => 'quiz',       'label' => __( 'Quiz', 'atora-lms' ) ),
			array( 'id' => 'analyze',    'label' => __( 'Analizar grupo', 'atora-lms' ) ),
			array( 'id' => 'email',      'label' => __( 'Redactar email', 'atora-lms' ) ),
			array( 'id' => 'chat',       'label' => __( 'Chat libre', 'atora-lms' ) ),
		);

		ob_start();
		?>
		<div class="atora-widget-head">
			<h3><?php esc_html_e( 'Asistente IA', 'atora-lms' ); ?></h3>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-ai-hub' ) ); ?>" class="atora-widget-link">
				<?php esc_html_e( 'Ver completo', 'atora-lms' ); ?>
			</a>
		</div>
		<div class="atora-widget-body">
			<p style="font-size:11px;color:var(--clms-muted);margin:0 0 10px">
				<?php esc_html_e( 'Proveedor:', 'atora-lms' ); ?> <strong><?php echo esc_html( $provider_label ); ?></strong>
			</p>
			<div class="atora-tool-grid">
				<?php foreach ( $tools as $tool ) : ?>
					<button type="button"
						class="atora-tool-btn"
						data-clms-ta-tool="<?php echo esc_attr( $tool['id'] ); ?>"
						aria-label="<?php echo esc_attr( $tool['label'] ); ?>">
						<?php echo esc_html( $tool['label'] ); ?>
					</button>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	protected function render_widget_comercio(): string {
		if ( ! current_user_can( 'clms_manage_commerce' ) && ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$analytics = clms_core('CLMS_Analytics');
		if ( ! $analytics && class_exists( 'CLMS_Analytics' ) ) {
			$analytics = new CLMS_Analytics();
		}

		$snapshot   = ( $analytics && method_exists( $analytics, 'get_dashboard_snapshot' ) )
			? $analytics->get_dashboard_snapshot( 'admin', get_current_user_id() )
			: array();
		$commercial = isset( $snapshot['commercial'] ) && is_array( $snapshot['commercial'] )
			? $snapshot['commercial']
			: array( 'cards' => array(), 'rows' => array() );

		$woo_active = class_exists( 'WooCommerce' );

		ob_start();
			?>
			<div class="atora-widget-head">
				<h3><?php esc_html_e( 'Comercial', 'atora-lms' ); ?></h3>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-commerce-dashboard' ) ); ?>" class="atora-widget-link">
					<?php esc_html_e( 'Ver completo', 'atora-lms' ); ?>
				</a>
		</div>
		<div class="atora-widget-body">
			<?php if ( ! $woo_active ) : ?>
				<p style="color:var(--clms-muted);font-size:13px">
					<?php esc_html_e( 'WooCommerce no detectado.', 'atora-lms' ); ?>
					<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ); ?>">
						<?php esc_html_e( 'Instalar', 'atora-lms' ); ?>
					</a>
				</p>
			<?php elseif ( ! empty( $commercial['cards'] ) ) : ?>
				<div class="atora-widget-metrics">
					<?php foreach ( array_slice( $commercial['cards'], 0, 3 ) as $card ) : ?>
						<div class="atora-widget-metric">
							<strong><?php echo esc_html( (string) ( $card['value'] ?? '—' ) ); ?></strong>
							<span><?php echo esc_html( (string) ( $card['label'] ?? '' ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p style="color:var(--clms-muted);font-size:13px"><?php esc_html_e( 'Sin datos comerciales todavía.', 'atora-lms' ); ?></p>
			<?php endif; ?>
			<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-commercial-hub' ) ); ?>" class="button button-small">
					<?php esc_html_e( 'Gestionar', 'atora-lms' ); ?>
				</a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	protected function render_widget_mensajes(): string {
		$user_id   = get_current_user_id();
		$messaging = clms_core('CLMS_Messaging');

		$stats = ( $messaging && method_exists( $messaging, 'get_message_stats' ) )
			? $messaging->get_message_stats( $user_id )
			: array( 'total' => 0, 'unread' => 0 );
		$messages = ( $messaging && method_exists( $messaging, 'get_messages' ) )
			? $messaging->get_messages( $user_id, array( 'limit' => 5 ) )
			: array();

		$unread = absint( $stats['unread'] ?? 0 );
		$total  = absint( $stats['total'] ?? 0 );

		ob_start();
		?>
		<div class="atora-widget-head">
			<h3>
				<?php esc_html_e( 'Mensajes', 'atora-lms' ); ?>
				<?php if ( $unread > 0 ) : ?>
					<span style="display:inline-flex;align-items:center;justify-content:center;background:var(--clms-accent);color:#fff;border-radius:999px;font-size:10px;font-weight:700;min-width:18px;height:18px;padding:0 5px;margin-left:6px">
						<?php echo esc_html( $unread ); ?>
					</span>
				<?php endif; ?>
			</h3>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-messages' ) ); ?>" class="atora-widget-link">
				<?php esc_html_e( 'Ver completo', 'atora-lms' ); ?>
			</a>
		</div>
		<div class="atora-widget-body">
			<div class="atora-widget-metrics">
				<div class="atora-widget-metric">
					<strong><?php echo esc_html( $unread ); ?></strong>
					<span><?php esc_html_e( 'No leídos', 'atora-lms' ); ?></span>
				</div>
				<div class="atora-widget-metric">
					<strong><?php echo esc_html( $total ); ?></strong>
					<span><?php esc_html_e( 'Total', 'atora-lms' ); ?></span>
				</div>
			</div>
			<?php if ( ! empty( $messages ) ) : ?>
				<ul class="atora-widget-list" style="margin-top:12px">
					<?php foreach ( $messages as $msg ) :
						$is_unread = empty( $msg['is_read'] );
						$sender    = $this->format_message_sender_label( $msg['sender_type'] ?? 'system', $msg['sender_name'] ?? '' );
						$preview   = $this->truncate_admin_text( (string) ( $msg['title'] ?? '' ), 56 );
						$date      = $this->format_admin_datetime( $msg['created_at'] ?? '' );
					?>
						<li style="<?php echo $is_unread ? 'font-weight:600' : ''; ?>">
							<span>
								<small style="display:block;color:var(--clms-muted);font-size:11px"><?php echo esc_html( $sender ); ?></small>
								<?php echo esc_html( $preview ); ?>
							</span>
							<small style="color:var(--clms-muted);white-space:nowrap"><?php echo esc_html( $date ); ?></small>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p style="color:var(--clms-muted);font-size:13px;margin-top:12px"><?php esc_html_e( 'No hay mensajes todavía.', 'atora-lms' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	protected function render_widget_perfil(): string {
		$user_id      = get_current_user_id();
		$user         = get_userdata( $user_id );
		$role_context = $this->get_current_role_context();

		if ( ! $user ) {
			return '';
		}

		$display_name  = $user->display_name ?: $user->user_login;
		$member_since  = mysql2date( 'd/m/Y', $user->user_registered );
		$course_count  = class_exists( 'CLMS_Helper' ) ? count( ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $user_id ) ) ) : 0;
		$completed_raw = get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed     = is_array( $completed_raw ) ? count( array_filter( array_map( 'absint', $completed_raw ) ) ) : 0;

		$is_instructor = in_array( $role_context, array( 'admin', 'instructor' ), true );
		$created_courses = $is_instructor ? count( $this->get_courses_owned_by_user( $user_id ) ) : 0;

		ob_start();
		?>
		<div class="atora-widget-head">
			<h3><?php esc_html_e( 'Mi perfil', 'atora-lms' ); ?></h3>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-my-profile' ) ); ?>" class="atora-widget-link">
				<?php esc_html_e( 'Editar perfil', 'atora-lms' ); ?>
			</a>
		</div>
		<div class="atora-widget-body">
			<div style="display:flex;gap:12px;align-items:center;margin-bottom:12px">
				<?php echo get_avatar( $user_id, 48, '', '', array( 'force_display' => true ) ); ?>
				<div>
					<strong style="display:block;color:var(--clms-ink)"><?php echo esc_html( $display_name ); ?></strong>
					<span style="font-size:12px;color:var(--clms-muted)">
						<?php echo esc_html( $this->get_role_context_label( $role_context ) ); ?>
						·
						<?php
						/* translators: %s: date */
						printf( esc_html__( 'desde %s', 'atora-lms' ), esc_html( $member_since ) );
						?>
					</span>
				</div>
			</div>
			<div class="atora-widget-metrics">
				<div class="atora-widget-metric">
					<strong><?php echo esc_html( $course_count ); ?></strong>
					<span><?php esc_html_e( 'Cursos', 'atora-lms' ); ?></span>
				</div>
				<div class="atora-widget-metric">
					<strong><?php echo esc_html( $completed ); ?></strong>
					<span><?php esc_html_e( 'Lecciones', 'atora-lms' ); ?></span>
				</div>
				<?php if ( $is_instructor ) : ?>
					<div class="atora-widget-metric">
						<strong><?php echo esc_html( $created_courses ); ?></strong>
						<span><?php esc_html_e( 'Creados', 'atora-lms' ); ?></span>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( $is_instructor && ( current_user_can( 'clms_view_teacher_dashboard' ) || current_user_can( 'manage_options' ) ) ) : ?>
				<div style="margin-top:10px">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-instructor-profile' ) ); ?>" class="button button-small">
						<?php esc_html_e( 'Perfil docente', 'atora-lms' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	protected function render_widget_salud(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$cache_status = ( class_exists( 'CLMS_Cache' ) && method_exists( 'CLMS_Cache', 'get_object_cache_status' ) )
			? CLMS_Cache::get_object_cache_status()
			: array( 'enabled' => false, 'driver' => 'none' );

		$cache_enabled    = ! empty( $cache_status['enabled'] );
		$opcache_enabled  = function_exists( 'opcache_get_status' ) && (bool) ini_get( 'opcache.enable' );
		$wp_cache_enabled = defined( 'WP_CACHE' ) && WP_CACHE;

		$driver_map   = array( 'redis' => 'Redis', 'memcached' => 'Memcached', 'external' => 'Externo' );
		$cache_driver = $cache_enabled ? ( $driver_map[ $cache_status['driver'] ?? '' ] ?? __( 'Otro', 'atora-lms' ) ) : '';
		$cache_label  = $cache_enabled
			? sprintf( __( 'Activo · %s', 'atora-lms' ), $cache_driver )
			: __( 'No detectado', 'atora-lms' );

		$db_stats    = array();
		$maintenance = clms_core('CLMS_Maintenance');
		if ( ! $maintenance && class_exists( 'CLMS_Maintenance' ) ) {
			$maintenance = new CLMS_Maintenance();
		}
		if ( $maintenance && method_exists( $maintenance, 'get_db_stats' ) ) {
			$db_stats = (array) $maintenance->get_db_stats();
		}

		ob_start();
		?>
		<div class="atora-widget-head">
			<h3><?php esc_html_e( 'Salud del sistema', 'atora-lms' ); ?></h3>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-maintenance' ) ); ?>" class="atora-widget-link">
				<?php esc_html_e( 'Ver completo', 'atora-lms' ); ?>
			</a>
		</div>
		<div class="atora-widget-body">
			<div style="display:grid;gap:6px;margin-bottom:12px">
				<?php
				$checks = array(
					array( 'label' => __( 'Object cache', 'atora-lms' ), 'ok' => $cache_enabled, 'detail' => $cache_label ),
					array( 'label' => __( 'OPcache', 'atora-lms' ),      'ok' => $opcache_enabled, 'detail' => $opcache_enabled ? __( 'Activo', 'atora-lms' ) : __( 'Inactivo', 'atora-lms' ) ),
					array( 'label' => __( 'WP_CACHE', 'atora-lms' ),     'ok' => $wp_cache_enabled, 'detail' => $wp_cache_enabled ? __( 'Activo', 'atora-lms' ) : __( 'No configurado', 'atora-lms' ) ),
				);
				foreach ( $checks as $check ) :
					$cls = $check['ok'] ? 'atora-status-ok' : 'atora-status-warn';
				?>
					<div style="display:flex;justify-content:space-between;align-items:center;font-size:13px">
						<span><?php echo esc_html( $check['label'] ); ?></span>
						<span class="<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $check['detail'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( ! empty( $db_stats ) ) : ?>
				<div class="atora-widget-metrics" style="margin-bottom:12px">
					<?php foreach ( array_slice( $db_stats, 0, 3 ) as $key => $val ) : ?>
						<div class="atora-widget-metric">
							<strong><?php echo esc_html( (string) $val ); ?></strong>
							<span><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $key ) ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div style="display:flex;gap:8px;flex-wrap:wrap">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-control-center' ) ); ?>" class="button button-small">
					<?php esc_html_e( 'Control central', 'atora-lms' ); ?>
				</a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	protected function render_widget_config(): string {
		if ( ! current_user_can( 'clms_access_admin' ) && ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$academy      = (array) get_option( 'clms_academy_settings', array() );
		$ai           = class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_settings' )
			? (array) CLMS_AI_Settings_Service::get_settings()
			: (array) get_option( 'clms_ai_settings', array() );
		$academy_name = sanitize_text_field( (string) ( $academy['academy_name'] ?? ( $academy['name'] ?? '' ) ) );
		$provider     = class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_provider' )
			? (string) CLMS_AI_Settings_Service::get_provider()
			: sanitize_text_field( (string) ( $ai['provider'] ?? '' ) );

		$apis_active = 0;
		foreach ( array( 'openai_api_key', 'anthropic_api_key', 'gemini_api_key', 'whisper_api_key' ) as $key ) {
			if ( ! empty( $ai[ $key ] ) ) {
				++$apis_active;
			}
		}

		$settings_url = admin_url( 'admin.php?page=clms-settings' );

		ob_start();
		?>
		<div class="atora-widget-head">
			<h3><?php esc_html_e( 'Configuración', 'atora-lms' ); ?></h3>
			<a href="<?php echo esc_url( $settings_url ); ?>" class="atora-widget-link">
				<?php esc_html_e( 'Ver completo', 'atora-lms' ); ?>
			</a>
		</div>
		<div class="atora-widget-body">
			<div class="atora-widget-metrics">
				<div class="atora-widget-metric">
					<strong style="font-size:14px"><?php echo $academy_name ? esc_html( $this->truncate_admin_text( $academy_name, 20 ) ) : '—'; ?></strong>
					<span><?php esc_html_e( 'Academia', 'atora-lms' ); ?></span>
				</div>
				<div class="atora-widget-metric">
					<strong><?php echo esc_html( $apis_active ); ?></strong>
					<span><?php esc_html_e( 'APIs activas', 'atora-lms' ); ?></span>
				</div>
				<div class="atora-widget-metric">
					<strong style="font-size:13px"><?php echo $provider ? esc_html( ucfirst( $provider ) ) : '—'; ?></strong>
					<span><?php esc_html_e( 'Proveedor IA', 'atora-lms' ); ?></span>
				</div>
			</div>
			<div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'academia', $settings_url ) ); ?>" class="button button-small">
					<?php esc_html_e( 'Academia', 'atora-lms' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'apis', $settings_url ) ); ?>" class="button button-small">
					<?php esc_html_e( 'APIs', 'atora-lms' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'advanced', $settings_url ) ); ?>" class="button button-small">
					<?php esc_html_e( 'Avanzado', 'atora-lms' ); ?>
				</a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	// ── Sparkline SVG inline (devuelve string, no echea) ─────────────────────────

	protected function render_sparkline_svg( array $values, string $color = '#4f46e5' ): string {
		if ( empty( $values ) ) {
			return '';
		}

		$count = count( $values );
		$max   = max( 1, (float) max( $values ) );
		$w     = 200;
		$h     = 40;
		$step  = $count > 1 ? $w / ( $count - 1 ) : $w;

		$points = array();
		foreach ( $values as $i => $val ) {
			$x        = round( $i * $step, 1 );
			$y        = round( $h - ( ( (float) $val / $max ) * ( $h - 4 ) ) - 2, 1 );
			$points[] = "$x,$y";
		}

		$polyline = implode( ' ', $points );
		$area     = '0,' . $h . ' ' . $polyline . ' ' . $w . ',' . $h;

		return sprintf(
			'<svg viewBox="0 0 %d %d" width="100%%" height="%dpx" style="display:block;" aria-hidden="true">
				<polyline points="%s" fill="%s" opacity="0.12" stroke="none"/>
				<polyline points="%s" fill="none" stroke="%s" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>',
			$w,
			$h,
			$h,
			esc_attr( $area ),
			esc_attr( $color ),
			esc_attr( $polyline ),
			esc_attr( $color )
		);
	}
}
