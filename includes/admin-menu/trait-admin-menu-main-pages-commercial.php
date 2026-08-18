<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Admin_Menu_Main_Pages_Commercial_Trait {
	public function render_legacy_commerce_hub_alias_page(): void {
		if ( ! current_user_can( 'clms_manage_commerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		wp_safe_redirect( $this->get_commercial_operations_url() );
		exit;
	}

	public function render_commerce_hub_page() {
		if ( ! current_user_can( 'clms_manage_commerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$this->render_admin_styles();

		$commerce_module = clms_core('CLMS_Commerce');
		$readiness       = ( $commerce_module && method_exists( $commerce_module, 'get' ) )
			? $commerce_module->get( 'readiness' )
			: null;

		$checklist_rows  = ( $readiness && method_exists( $readiness, 'get_checklist_rows' ) )
			? (array) $readiness->get_checklist_rows()
			: array();
		$activation_rows = ( $readiness && method_exists( $readiness, 'get_recent_activation_rows' ) )
			? (array) $readiness->get_recent_activation_rows( 12 )
			: array();

		$commerce_msg = isset( $_GET['commerce_msg'] ) ? sanitize_key( wp_unslash( $_GET['commerce_msg'] ) ) : '';
		$notice_html  = '';
		if ( 'retry_ok' === $commerce_msg ) {
			$notice_html = '<div class="notice notice-success"><p>' . esc_html__( 'Se reintentó la activación del pedido correctamente.', 'atora-lms' ) . '</p></div>';
		} elseif ( 'resend_ok' === $commerce_msg ) {
			$notice_html = '<div class="notice notice-success"><p>' . esc_html__( 'Se reenvió el email de acceso al estudiante.', 'atora-lms' ) . '</p></div>';
		} elseif ( 'resend_error' === $commerce_msg ) {
			$notice_html = '<div class="notice notice-error"><p>' . esc_html__( 'No se pudo reenviar el email. Verifica que el pedido tenga cursos vinculados y usuario asociado.', 'atora-lms' ) . '</p></div>';
		}

		$items = array(
			array(
				'title'       => __( 'Productos', 'atora-lms' ),
				'description' => __( 'Gestiona productos vinculados a cursos, programas, bundles y membresías.', 'atora-lms' ),
				'url'         => admin_url( 'edit.php?post_type=product' ),
			),
			array(
				'title'       => __( 'Cursos comerciales', 'atora-lms' ),
				'description' => __( 'Revisa las landings y CTA configuradas para venta por curso.', 'atora-lms' ),
				'url'         => admin_url( 'edit.php?post_type=lm_course' ),
			),
			array(
				'title'       => __( 'Programas comerciales', 'atora-lms' ),
				'description' => __( 'Administra diplomados y programas con pricing y cross-sell.', 'atora-lms' ),
				'url'         => admin_url( 'edit.php?post_type=lm_program' ),
			),
			array(
				'title'       => __( 'Calendario comercial', 'atora-lms' ),
				'description' => __( 'Agenda de reuniones, demos, webinars y seguimiento comercial.', 'atora-lms' ),
				'url'         => $this->current_user_can_visit_url( $this->get_commercial_calendar_url() ) ? $this->get_commercial_calendar_url() : admin_url( 'admin.php?page=clms-crm-hub' ),
			),
			array(
				'title'       => __( 'Configuración', 'atora-lms' ),
				'description' => __( 'Ajustes centrales de academia, branding y APIs.', 'atora-lms' ),
				'url'         => admin_url( 'admin.php?page=clms-settings' ),
			),
		);

		echo '<div class="wrap clms-admin-wrap">';
		echo '<h1>' . esc_html__( 'Operación comercial ATORA', 'atora-lms' ) . '</h1>';
		echo '<p>' . esc_html__( 'Centro operativo para monetización, activación postcompra y control comercial.', 'atora-lms' ) . '</p>';
		if ( $notice_html ) {
			echo $notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<div class="clms-admin-card"><div class="clms-admin-nav-grid">';
		foreach ( $items as $item ) {
			echo '<a class="clms-admin-nav-card" href="' . esc_url( $item['url'] ) . '"><strong>' . esc_html( $item['title'] ) . '</strong><span>' . esc_html( $item['description'] ) . '</span></a>';
		}
		echo '</div></div>';

		if ( ! empty( $checklist_rows ) ) {
			echo '<div class="clms-admin-card">';
			echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Academia lista para vender', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Checklist de lanzamiento comercial', 'atora-lms' ) . '</h2></div></div>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Área', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Estado', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Acción sugerida', 'atora-lms' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $checklist_rows as $row ) {
				$area   = isset( $row['area'] ) ? sanitize_text_field( (string) $row['area'] ) : '';
				$ok     = ! empty( $row['ok'] );
				$action = isset( $row['action'] ) && is_array( $row['action'] ) ? $row['action'] : array();
				$label  = isset( $action['label'] ) ? sanitize_text_field( (string) $action['label'] ) : '';
				$url    = isset( $action['url'] ) ? esc_url( (string) $action['url'] ) : '';
				$state  = $ok ? __( 'Correcto', 'atora-lms' ) : __( 'Pendiente', 'atora-lms' );
				echo '<tr>';
				echo '<td>' . esc_html( $area ) . '</td>';
				echo '<td><span class="clms-admin-status-pill ' . ( $ok ? 'is-ok' : 'is-warning' ) . '">' . esc_html( $state ) . '</span></td>';
				echo '<td>';
				if ( $url && $label ) {
					echo '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
				} else {
					echo '—';
				}
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '<p class="clms-admin-note">' . esc_html__( 'Tip: ejecuta este checklist antes de abrir campañas o recibir estudiantes pagos.', 'atora-lms' ) . '</p>';
			echo '</div>';
		}

		echo '<div class="clms-admin-card">';
		echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Postcompra', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Centro de activaciones recientes', 'atora-lms' ) . '</h2></div></div>';
		if ( empty( $activation_rows ) ) {
			echo '<p class="clms-admin-note">' . esc_html__( 'No hay pedidos recientes para mostrar.', 'atora-lms' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Pedido', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Usuario', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Producto/curso', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Matrícula', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Email', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Acciones', 'atora-lms' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $activation_rows as $row ) {
				$order_id   = isset( $row['order_id'] ) ? absint( $row['order_id'] ) : 0;
				$order_url  = isset( $row['order_url'] ) ? esc_url( (string) $row['order_url'] ) : '';
				$user_name  = isset( $row['user_name'] ) ? sanitize_text_field( (string) $row['user_name'] ) : __( 'Invitado', 'atora-lms' );
				$user_email = isset( $row['user_email'] ) ? sanitize_email( (string) $row['user_email'] ) : '';
				$products   = isset( $row['products'] ) && is_array( $row['products'] ) ? array_map( 'sanitize_text_field', $row['products'] ) : array();
				$course_ids = isset( $row['course_ids'] ) && is_array( $row['course_ids'] ) ? array_map( 'absint', $row['course_ids'] ) : array();
				$program_ids = isset( $row['program_ids'] ) && is_array( $row['program_ids'] ) ? array_map( 'absint', $row['program_ids'] ) : array();
				$notified   = isset( $row['notified_courses'] ) && is_array( $row['notified_courses'] ) ? array_map( 'absint', $row['notified_courses'] ) : array();
				$last_log   = isset( $row['last_log'] ) ? sanitize_text_field( (string) $row['last_log'] ) : '';
				$retry_url  = isset( $row['retry_url'] ) ? esc_url( (string) $row['retry_url'] ) : '';
				$resend_url = isset( $row['resend_url'] ) ? esc_url( (string) $row['resend_url'] ) : '';
				$created_at = isset( $row['created_at'] ) ? sanitize_text_field( (string) $row['created_at'] ) : '';

				$enrollment_ok    = ! empty( $course_ids ) || ! empty( $program_ids );
				$enrollment_state = $enrollment_ok ? __( 'Activa', 'atora-lms' ) : __( 'Pendiente', 'atora-lms' );
				$email_ok         = ! empty( $notified );
				$email_state      = $email_ok ? __( 'Enviado', 'atora-lms' ) : __( 'Pendiente', 'atora-lms' );

				echo '<tr>';
				echo '<td>';
				if ( $order_url ) {
					echo '<a href="' . esc_url( $order_url ) . '">#' . esc_html( $order_id ) . '</a>';
				} else {
					echo '#' . esc_html( $order_id );
				}
				if ( $created_at ) {
					echo '<br><small>' . esc_html( $created_at ) . '</small>';
				}
				echo '</td>';
				echo '<td><strong>' . esc_html( $user_name ) . '</strong>';
				if ( $user_email ) {
					echo '<br><small>' . esc_html( $user_email ) . '</small>';
				}
				echo '</td>';
				echo '<td>';
				if ( ! empty( $products ) ) {
					echo esc_html( implode( ', ', $products ) );
				} else {
					echo '—';
				}
				if ( ! empty( $course_ids ) ) {
					echo '<br><small>' . esc_html( sprintf( __( '%d curso(s) vinculados', 'atora-lms' ), count( $course_ids ) ) ) . '</small>';
				}
				if ( ! empty( $program_ids ) ) {
					echo '<br><small>' . esc_html( sprintf( __( '%d programa(s) vinculados', 'atora-lms' ), count( $program_ids ) ) ) . '</small>';
				}
				echo '</td>';
				echo '<td><span class="clms-admin-status-pill ' . ( $enrollment_ok ? 'is-ok' : 'is-warning' ) . '">' . esc_html( $enrollment_state ) . '</span>';
				if ( $last_log ) {
					echo '<br><small>' . esc_html( $last_log ) . '</small>';
				}
				echo '</td>';
				echo '<td><span class="clms-admin-status-pill ' . ( $email_ok ? 'is-ok' : 'is-info' ) . '">' . esc_html( $email_state ) . '</span></td>';
				echo '<td>';
				if ( $retry_url ) {
					echo '<a class="button button-small" href="' . esc_url( $retry_url ) . '">' . esc_html__( 'Reintentar activación', 'atora-lms' ) . '</a> ';
				}
				if ( $resend_url ) {
					echo '<a class="button button-small" href="' . esc_url( $resend_url ) . '">' . esc_html__( 'Reenviar email', 'atora-lms' ) . '</a>';
				}
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
		echo '</div>';
	}

	public function render_control_center_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$this->render_admin_styles();

		$analytics = clms_core('CLMS_Analytics');
		if ( ! $analytics && class_exists( 'CLMS_Analytics' ) ) {
			$analytics = new CLMS_Analytics();
		}

		$role_context  = $this->get_current_role_context();
		$user_id       = get_current_user_id();
		$snapshot      = ( $analytics && method_exists( $analytics, 'get_dashboard_snapshot' ) )
			? $analytics->get_dashboard_snapshot( $role_context, $user_id )
			: array();
		$academic      = isset( $snapshot['academic'] ) && is_array( $snapshot['academic'] ) ? $snapshot['academic'] : array( 'cards' => array(), 'rows' => array() );
		$commercial    = isset( $snapshot['commercial'] ) && is_array( $snapshot['commercial'] ) ? $snapshot['commercial'] : array( 'cards' => array(), 'rows' => array() );
		$observability = isset( $snapshot['observability'] ) && is_array( $snapshot['observability'] ) ? $snapshot['observability'] : array( 'cards' => array(), 'events' => array(), 'health' => array() );

		$summary_cards = array(
			array( 'label' => __( 'Cursos activos', 'atora-lms' ), 'value' => $this->find_card_value( $academic['cards'], 'Cursos activos', 0 ) ),
			array( 'label' => __( 'Ingresos', 'atora-lms' ), 'value' => $this->find_card_value( $commercial['cards'], 'Ingresos', '$0' ) ),
			array( 'label' => __( 'Eventos recientes', 'atora-lms' ), 'value' => $this->find_card_value( $observability['cards'], 'Eventos recientes', 0 ) ),
			array( 'label' => __( 'Salud', 'atora-lms' ), 'value' => $this->find_card_value( $observability['cards'], 'Salud', '0/0' ) ),
		);

			echo '<div class="wrap clms-admin-wrap">';
			echo '<h1>' . esc_html__( 'Control central', 'atora-lms' ) . '</h1>';
			echo '<p>' . esc_html__( 'Visión unificada del rendimiento académico, la operación comercial y la observabilidad de ATORA.', 'atora-lms' ) . '</p>';

		echo '<div class="clms-admin-metrics">';
		foreach ( $summary_cards as $card ) {
			echo '<div class="clms-admin-metric"><span>' . esc_html( $card['label'] ) . '</span><strong>' . esc_html( $card['value'] ) . '</strong></div>';
		}
		echo '</div>';

		$operations_service = $this->get_admin_operations_service();
		$action_cards       = $operations_service ? (array) $operations_service->get_action_cards( $user_id, 5 ) : array();
		$student_filters    = $this->get_operational_student_filters_from_request();
		$student_filter_options = $operations_service && method_exists( $operations_service, 'get_students_filter_options' )
			? (array) $operations_service->get_students_filter_options( $user_id )
			: array();
		$student_rows       = $operations_service ? (array) $operations_service->get_students_visibility_rows( $user_id, 6, $student_filters ) : array();

		echo '<div class="clms-admin-card">';
		echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Acciones', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Qué atender hoy', 'atora-lms' ) . '</h2></div></div>';
		if ( empty( $action_cards ) ) {
			echo '<p class="clms-admin-note">' . esc_html__( 'No hay acciones críticas por ahora. Puedes revisar analítica para planificar la siguiente semana.', 'atora-lms' ) . '</p>';
		} else {
			echo '<div class="clms-dashboard-grid">';
			foreach ( $action_cards as $card ) {
				$type     = sanitize_key( (string) ( $card['type'] ?? 'system' ) );
				$priority = sanitize_key( (string) ( $card['priority'] ?? 'medium' ) );
				$title    = sanitize_text_field( (string) ( $card['title'] ?? '' ) );
				$desc     = sanitize_text_field( (string) ( $card['description'] ?? '' ) );
				$status   = sanitize_text_field( (string) ( $card['status'] ?? '' ) );
				$url      = esc_url( (string) ( $card['cta_url'] ?? '' ) );
				$label    = sanitize_text_field( (string) ( $card['cta_label'] ?? '' ) );
				$icon     = sanitize_html_class( (string) ( $card['icon'] ?? 'dashicons-admin-tools' ) );

				echo '<article class="clms-admin-card clms-action-card clms-action-card--' . esc_attr( $type ) . '">';
				echo '<div class="clms-action-card__head"><span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span><span class="clms-status-pill clms-priority-' . esc_attr( $priority ) . '">' . esc_html( strtoupper( $priority ) ) . '</span></div>';
				echo '<h4>' . esc_html( $title ) . '</h4>';
				echo '<p class="clms-action-card__desc">' . esc_html( $desc ) . '</p>';
				if ( '' !== $status ) {
					echo '<p class="clms-action-card__status">' . esc_html( $status ) . '</p>';
				}
				if ( $url && $label ) {
					echo '<a class="button button-primary button-small" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
				}
				echo '</article>';
			}
			echo '</div>';
		}
		echo '</div>';

		echo '<div class="clms-admin-card">';
		echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Estudiantes', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Visibilidad rápida del grupo', 'atora-lms' ) . '</h2></div></div>';
		$this->render_operational_student_filters( $student_filters, $student_filter_options );
		if ( empty( $student_rows ) ) {
			echo '<p class="clms-admin-note">' . esc_html__( 'Todavía no hay estudiantes activos para mostrar en esta vista.', 'atora-lms' ) . '</p>';
		} else {
			echo '<div class="clms-students-summary">';
			foreach ( $student_rows as $row ) {
				$risk_level = sanitize_key( (string) ( $row['risk_level'] ?? 'unknown' ) );
				$pending    = absint( $row['pending_submissions'] ?? 0 );
				echo '<article class="clms-student-row">';
				echo '<div class="clms-student-row__head"><h4>' . esc_html( (string) ( $row['student_name'] ?? '' ) . ' · ' . (string) ( $row['course_title'] ?? '' ) ) . '</h4><span class="clms-status-pill clms-status-pill--risk-' . esc_attr( $risk_level ) . '">' . esc_html( (string) ( $row['risk_label'] ?? '' ) ) . '</span></div>';
				echo '<div class="clms-student-row__stats">';
				echo '<span>' . esc_html( sprintf( __( 'Progreso: %d%%', 'atora-lms' ), absint( $row['progress_percent'] ?? 0 ) ) ) . '</span>';
				echo '<span>' . esc_html( sprintf( _n( '%d entrega pendiente', '%d entregas pendientes', $pending, 'atora-lms' ), $pending ) ) . '</span>';
				echo '<span>' . esc_html( sprintf( __( 'Certificado: %s', 'atora-lms' ), (string) ( $row['certificate_label'] ?? __( 'En progreso', 'atora-lms' ) ) ) ) . '</span>';
				echo '</div>';
				echo '<div class="clms-student-row__actions">';
				if ( ! empty( $row['profile_url'] ) ) {
					echo '<a class="button button-small" href="' . esc_url( (string) $row['profile_url'] ) . '">' . esc_html__( 'Ver perfil', 'atora-lms' ) . '</a>';
				}
				if ( ! empty( $row['course_url'] ) ) {
					echo '<a class="button button-small" href="' . esc_url( (string) $row['course_url'] ) . '">' . esc_html__( 'Ver curso', 'atora-lms' ) . '</a>';
				}
				if ( ! empty( $row['speedgrade_url'] ) ) {
					echo '<a class="button button-small button-primary" href="' . esc_url( (string) $row['speedgrade_url'] ) . '">' . esc_html__( 'SpeedGrade', 'atora-lms' ) . '</a>';
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
		}
		echo '</div>';

		echo '<div class="clms-admin-grid-2">';
		echo '<div class="clms-admin-card">';
		echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Pulso académico', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Cursos, progreso y pendientes', 'atora-lms' ) . '</h2></div></div>';
		if ( ! empty( $academic['cards'] ) ) {
			echo '<div class="clms-admin-metrics">';
			foreach ( $academic['cards'] as $card ) {
				echo '<div class="clms-admin-metric"><span>' . esc_html( $card['label'] ) . '</span><strong>' . esc_html( $card['value'] ) . '</strong></div>';
			}
			echo '</div>';
		} else {
			echo '<p class="clms-admin-note">' . esc_html__( 'No hay datos académicos suficientes todavía.', 'atora-lms' ) . '</p>';
		}
		$this->render_bar_chart(
			__( 'Pendientes por curso', 'atora-lms' ),
			$academic['rows'] ?? array(),
			'course_title',
			'pending_submissions',
			'',
			0,
			5
		);
		echo '</div>';

		echo '<div class="clms-admin-card">';
		echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Observabilidad', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Eventos y salud del sistema', 'atora-lms' ) . '</h2></div></div>';
		if ( ! empty( $observability['cards'] ) ) {
			echo '<div class="clms-admin-metrics">';
			foreach ( $observability['cards'] as $card ) {
				echo '<div class="clms-admin-metric"><span>' . esc_html( $card['label'] ) . '</span><strong>' . esc_html( $card['value'] ) . '</strong></div>';
			}
			echo '</div>';
		}

		if ( ! empty( $observability['health'] ) ) {
			echo '<div class="clms-admin-status-grid">';
			foreach ( $observability['health'] as $item ) {
				$status = sanitize_key( (string) ( $item['status'] ?? 'info' ) );
				$status = in_array( $status, array( 'ok', 'warning', 'info' ), true ) ? $status : 'info';
				echo '<div class="clms-admin-status-card is-' . esc_attr( $status ) . '">';
				echo '<strong>' . esc_html( $item['label'] ?? '' ) . '</strong>';
				echo '<span>' . esc_html( $item['detail'] ?? '' ) . '</span>';
				echo '</div>';
			}
			echo '</div>';
		}

		if ( ! empty( $observability['events'] ) ) {
			echo '<div class="clms-admin-event-list">';
			foreach ( $observability['events'] as $event ) {
				$severity = sanitize_key( (string) ( $event['severity'] ?? 'info' ) );
				$severity = in_array( $severity, array( 'error', 'warning', 'info' ), true ) ? $severity : 'info';
				$meta = array();
				if ( ! empty( $event['type'] ) ) {
					$meta[] = sanitize_text_field( (string) $event['type'] );
				}
				if ( ! empty( $event['created_at'] ) ) {
					$meta[] = mysql2date( 'd/m/Y H:i', $event['created_at'] );
				}
				echo '<div class="clms-admin-event">';
				echo '<div>';
				echo '<strong>' . esc_html( $event['message'] ?? '' ) . '</strong>';
				if ( ! empty( $meta ) ) {
					echo '<small class="clms-admin-event-meta">' . esc_html( implode( ' · ', $meta ) ) . '</small>';
				}
				echo '</div>';
				echo '<span class="clms-admin-status-pill is-' . esc_attr( $severity ) . '">' . esc_html( strtoupper( $severity ) ) . '</span>';
				echo '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';

		echo '<div class="clms-admin-card">';
		echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Comercial', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Conversiones y productos clave', 'atora-lms' ) . '</h2></div></div>';
		if ( ! empty( $commercial['cards'] ) ) {
			echo '<div class="clms-admin-metrics">';
			foreach ( $commercial['cards'] as $card ) {
				echo '<div class="clms-admin-metric"><span>' . esc_html( $card['label'] ) . '</span><strong>' . esc_html( $card['value'] ) . '</strong></div>';
			}
			echo '</div>';
		}
		$this->render_bar_chart(
			__( 'Ingresos estimados por producto', 'atora-lms' ),
			$commercial['rows'] ?? array(),
			'title',
			'revenue',
			'$',
			0,
			6
		);
		echo '<div class="clms-admin-actions">';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=clms-commerce-dashboard' ) ) . '">' . esc_html__( 'Ver dashboard comercial', 'atora-lms' ) . '</a>';
		echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=clms-analytics' ) ) . '">' . esc_html__( 'Abrir analítica completa', 'atora-lms' ) . '</a>';
		echo '</div>';
		echo '</div>';

		echo '</div>';
	}

	public function render_commerce_dashboard_page() {
		if ( ! current_user_can( 'clms_manage_commerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$this->render_admin_styles();

		$analytics = clms_core('CLMS_Analytics');
		if ( ! $analytics && class_exists( 'CLMS_Analytics' ) ) {
			$analytics = new CLMS_Analytics();
		}

		$snapshot   = ( $analytics && method_exists( $analytics, 'get_dashboard_snapshot' ) )
			? $analytics->get_dashboard_snapshot( 'admin', get_current_user_id() )
			: array();
		$commercial = isset( $snapshot['commercial'] ) && is_array( $snapshot['commercial'] ) ? $snapshot['commercial'] : array( 'cards' => array(), 'rows' => array() );
		$events     = ( $analytics && method_exists( $analytics, 'get_recent_events' ) )
			? $analytics->get_recent_events( 8, array( 'type' => 'commerce_order_processed' ) )
			: array();

		$conversion_display = $this->find_card_value( $commercial['cards'], 'Conversiones', 0 );
		$sales_display      = $this->find_card_value( $commercial['cards'], 'Ventas académicas', 0 );
		$cross_display      = $this->find_card_value( $commercial['cards'], 'Cross-sell', '0%' );

		$funnel_steps = array(
			array(
				'label'   => __( 'Conversiones', 'atora-lms' ),
				'display' => $conversion_display,
				'value'   => $this->parse_numeric_value( $conversion_display ),
			),
			array(
				'label'   => __( 'Ventas académicas', 'atora-lms' ),
				'display' => $sales_display,
				'value'   => $this->parse_numeric_value( $sales_display ),
			),
			array(
				'label'   => __( 'Cross-sell', 'atora-lms' ),
				'display' => $cross_display,
				'value'   => $this->parse_numeric_value( $cross_display ),
			),
		);
		$max_funnel = 1;
		foreach ( $funnel_steps as $step ) {
			$max_funnel = max( $max_funnel, (float) $step['value'] );
		}

		echo '<div class="wrap clms-admin-wrap">';
		echo '<h1>' . esc_html__( 'Dashboard comercial', 'atora-lms' ) . '</h1>';
		echo '<p>' . esc_html__( 'Seguimiento de conversiones, ventas y productos con mejor desempeño.', 'atora-lms' ) . '</p>';

		if ( ! empty( $commercial['cards'] ) ) {
			echo '<div class="clms-admin-metrics">';
			foreach ( $commercial['cards'] as $card ) {
				echo '<div class="clms-admin-metric"><span>' . esc_html( $card['label'] ) . '</span><strong>' . esc_html( $card['value'] ) . '</strong></div>';
			}
			echo '</div>';
		}

		echo '<div class="clms-admin-grid-2">';
		echo '<div class="clms-admin-card">';
		echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Funnel', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Conversiones y cross-sell', 'atora-lms' ) . '</h2></div></div>';
		echo '<div class="clms-admin-funnel">';
		foreach ( $funnel_steps as $step ) {
			$width = $max_funnel > 0 ? min( 100, ( (float) $step['value'] / $max_funnel ) * 100 ) : 0;
			echo '<div class="clms-admin-funnel-step">';
			echo '<div class="clms-admin-funnel-head">';
			echo '<span class="clms-admin-funnel-label">' . esc_html( $step['label'] ) . '</span>';
			echo '<strong class="clms-admin-funnel-value">' . esc_html( $step['display'] ) . '</strong>';
			echo '</div>';
			echo '<div class="clms-admin-funnel-bar"><span style="width:' . esc_attr( round( $width, 2 ) ) . '%"></span></div>';
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';

		echo '<div class="clms-admin-card">';
		echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Productos', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Top ingresos por producto', 'atora-lms' ) . '</h2></div></div>';
		$this->render_bar_chart(
			__( 'Ingresos estimados por producto', 'atora-lms' ),
			$commercial['rows'] ?? array(),
			'title',
			'revenue',
			'$',
			0,
			6
		);
		echo '</div>';
		echo '</div>';

		echo '<div class="clms-admin-card">';
		echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Eventos', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Actividad comercial reciente', 'atora-lms' ) . '</h2></div></div>';
		if ( empty( $events ) ) {
			echo '<p class="clms-admin-note">' . esc_html__( 'No hay eventos comerciales recientes.', 'atora-lms' ) . '</p>';
		} else {
			echo '<div class="clms-admin-event-list">';
			foreach ( $events as $event ) {
				$severity = sanitize_key( (string) ( $event['severity'] ?? 'info' ) );
				$severity = in_array( $severity, array( 'error', 'warning', 'info' ), true ) ? $severity : 'info';
				$meta     = array();
				if ( ! empty( $event['context']['order_id'] ) ) {
					$meta[] = sprintf( __( 'Orden #%d', 'atora-lms' ), absint( $event['context']['order_id'] ) );
				}
				if ( ! empty( $event['context']['user_id'] ) ) {
					$meta[] = sprintf( __( 'Usuario %d', 'atora-lms' ), absint( $event['context']['user_id'] ) );
				}
				if ( ! empty( $event['created_at'] ) ) {
					$meta[] = mysql2date( 'd/m/Y H:i', $event['created_at'] );
				}
				echo '<div class="clms-admin-event">';
				echo '<div>';
				echo '<strong>' . esc_html( $event['message'] ?? '' ) . '</strong>';
				if ( ! empty( $meta ) ) {
					echo '<small class="clms-admin-event-meta">' . esc_html( implode( ' · ', $meta ) ) . '</small>';
				}
				echo '</div>';
				echo '<span class="clms-admin-status-pill is-' . esc_attr( $severity ) . '">' . esc_html( strtoupper( $severity ) ) . '</span>';
				echo '</div>';
			}
			echo '</div>';
		}
		echo '</div>';

		echo '</div>';
	}

}
