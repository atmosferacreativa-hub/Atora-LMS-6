<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Admin_Menu_Academic_Ops_Trait {
	public function render_speedgrader_page() {
		if ( ! CLMS_Access::can_grade_submissions() ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$this->render_admin_styles();

		$submission_id = isset( $_GET['submission_id'] ) ? absint( wp_unslash( $_GET['submission_id'] ) ) : 0;
		$course_id     = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		$cohort_id     = isset( $_GET['cohort_id'] ) ? absint( wp_unslash( $_GET['cohort_id'] ) ) : 0;
		$section_id    = isset( $_GET['section_id'] ) ? absint( wp_unslash( $_GET['section_id'] ) ) : 0;
		$status        = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$courses       = $this->get_courses();
		$cohorts       = $this->get_speedgrader_cohort_options();
		$sections      = $this->get_speedgrader_section_options( $course_id );

		if ( $submission_id ) {
			$this->redirect_to_canonical_speedgrader( $submission_id, $course_id, $status, $cohort_id );
			return;
		}

		echo '<div class="wrap clms-admin-wrap">';
		echo '<h1>' . esc_html__( 'SpeedGrade', 'atora-lms' ) . '</h1>';
		echo '<p>' . esc_html__( 'Revisa entregas y entra al revisor unificado.', 'atora-lms' ) . '</p>';

		echo '<div class="clms-admin-card">';
		$this->render_speedgrader_filters( $courses, $course_id, $status, $cohorts, $cohort_id, $sections, $section_id );
		echo '</div>';

		echo '<div class="clms-admin-card">';
		$this->render_speedgrader_queue( $course_id, $status, $cohort_id, $section_id );
		echo '</div>';

		echo '</div>';
	}

	protected function render_speedgrader_filters( $courses, $selected_course_id, $selected_status, $cohorts = array(), $selected_cohort_id = 0, $sections = array(), $selected_section_id = 0 ) {
		echo '<form method="get" action="" class="clms-admin-filter">';
		echo '<input type="hidden" name="page" value="clms-speedgrader">';

		echo '<label for="sg-course-id"><strong>' . esc_html__( 'Curso', 'atora-lms' ) . '</strong></label>';
		echo '<select name="course_id" id="sg-course-id">';
		echo '<option value="">' . esc_html__( 'Todos los cursos', 'atora-lms' ) . '</option>';
		foreach ( $courses as $cid => $ctitle ) {
			echo '<option value="' . esc_attr( $cid ) . '" ' . selected( $selected_course_id, $cid, false ) . '>' . esc_html( $ctitle ) . '</option>';
		}
		echo '</select>';

		echo '<label for="sg-status"><strong>' . esc_html__( 'Estado', 'atora-lms' ) . '</strong></label>';
		echo '<select name="status" id="sg-status">';
		$statuses = array(
			''          => __( 'Todos', 'atora-lms' ),
			'submitted' => __( 'Enviadas', 'atora-lms' ),
			'in_review' => __( 'En revisión', 'atora-lms' ),
			'reviewed'  => __( 'Revisadas', 'atora-lms' ),
			'graded'    => __( 'Calificadas', 'atora-lms' ),
			'updated'   => __( 'Actualizadas', 'atora-lms' ),
		);
		foreach ( $statuses as $sval => $slabel ) {
			echo '<option value="' . esc_attr( $sval ) . '" ' . selected( $selected_status, $sval, false ) . '>' . esc_html( $slabel ) . '</option>';
		}
		echo '</select>';

		echo '<label for="sg-cohort-id"><strong>' . esc_html__( 'Cohorte', 'atora-lms' ) . '</strong></label>';
		echo '<select name="cohort_id" id="sg-cohort-id">';
		echo '<option value="">' . esc_html__( 'Todas las cohortes', 'atora-lms' ) . '</option>';
		foreach ( (array) $cohorts as $cohort_value => $cohort_label ) {
			echo '<option value="' . esc_attr( (string) absint( $cohort_value ) ) . '" ' . selected( absint( $selected_cohort_id ), absint( $cohort_value ), false ) . '>' . esc_html( (string) $cohort_label ) . '</option>';
		}
		echo '</select>';

		if ( ! empty( $sections ) ) {
			echo '<label for="sg-section-id"><strong>' . esc_html__( 'Sección', 'atora-lms' ) . '</strong></label>';
			echo '<select name="section_id" id="sg-section-id">';
			echo '<option value="">' . esc_html__( 'Todas las secciones', 'atora-lms' ) . '</option>';
			foreach ( (array) $sections as $section_value => $section_label ) {
				echo '<option value="' . esc_attr( (string) absint( $section_value ) ) . '" ' . selected( absint( $selected_section_id ), absint( $section_value ), false ) . '>' . esc_html( (string) $section_label ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<input type="hidden" name="section_id" value="0">';
		}

		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Filtrar', 'atora-lms' ) . '</button>';
		echo '</form>';
	}

	protected function render_speedgrader_queue( $course_id, $status, $cohort_id = 0, $section_id = 0 ) {
		$args = array(
			'post_type'      => 'clms_submission',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$meta_query = array();

		if ( $course_id ) {
			$meta_query[] = array(
				'key'   => '_clms_submission_course_id',
				'value' => $course_id,
				'type'  => 'NUMERIC',
			);
		}

		$section_id = absint( $section_id );
		if ( $section_id && class_exists( 'ATORA\\LMS\\Section_Service' ) ) {
			$section     = \ATORA\LMS\Section_Service::get( $section_id );
			$sec_course  = $section ? (int) $section['wp_course_id'] : 0;
			$sec_students = \ATORA\LMS\Section_Service::get_section_student_ids( $section_id );

			if ( empty( $sec_students ) ) {
				echo '<p>' . esc_html__( 'La sección seleccionada no tiene alumnos para revisión.', 'atora-lms' ) . '</p>';
				return;
			}

			if ( $sec_course ) {
				$meta_query[] = array(
					'key'   => '_clms_submission_course_id',
					'value' => $sec_course,
					'type'  => 'NUMERIC',
				);
			}
			$meta_query[] = array(
				'key'     => '_clms_submission_user_id',
				'value'   => $sec_students,
				'compare' => 'IN',
				'type'    => 'NUMERIC',
			);
		} else {
			$cohort_id = absint( $cohort_id );
			if ( $cohort_id ) {
				$cohort_service = $this->get_cohort_service();
				$cohort_courses = ( $cohort_service && method_exists( $cohort_service, 'get_cohort_course_ids' ) )
					? (array) $cohort_service->get_cohort_course_ids( $cohort_id )
					: array();
				$cohort_students = ( $cohort_service && method_exists( $cohort_service, 'get_cohort_student_ids' ) )
					? (array) $cohort_service->get_cohort_student_ids( $cohort_id )
					: array();
				$cohort_courses  = array_values( array_filter( array_map( 'absint', $cohort_courses ) ) );
				$cohort_students = array_values( array_filter( array_map( 'absint', $cohort_students ) ) );

				if ( $course_id && ! empty( $cohort_courses ) ) {
					$cohort_courses = array_values( array_intersect( $cohort_courses, array( absint( $course_id ) ) ) );
				}

				if ( empty( $cohort_courses ) || empty( $cohort_students ) ) {
					echo '<p>' . esc_html__( 'La cohorte seleccionada no tiene cursos o estudiantes activos para revisión.', 'atora-lms' ) . '</p>';
					return;
				}

				$meta_query[] = array(
					'key'     => '_clms_submission_course_id',
					'value'   => $cohort_courses,
					'compare' => 'IN',
					'type'    => 'NUMERIC',
				);
				$meta_query[] = array(
					'key'     => '_clms_submission_user_id',
					'value'   => $cohort_students,
					'compare' => 'IN',
					'type'    => 'NUMERIC',
				);
			}
		}

		if ( $status ) {
			$meta_query[] = array(
				'key'   => '_clms_submission_status',
				'value' => sanitize_key( $status ),
			);
		}

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		$submissions = get_posts( $args );

		if ( empty( $submissions ) ) {
			echo '<p>' . esc_html__( 'No hay entregas con los filtros seleccionados.', 'atora-lms' ) . '</p>';
			return;
		}

		$grading = $this->get_grading_instance();
		$status_labels = array(
			'submitted' => __( 'Enviada', 'atora-lms' ),
			'in_review' => __( 'En revisión', 'atora-lms' ),
			'reviewed'  => __( 'Revisada', 'atora-lms' ),
			'graded'    => __( 'Calificada', 'atora-lms' ),
			'updated'   => __( 'Actualizada', 'atora-lms' ),
		);

		echo '<table class="widefat striped clms-admin-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Alumno', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Lección', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Curso', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Estado', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Fecha', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Acción', 'atora-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $submissions as $sub ) {
			$student_id  = absint( get_post_meta( $sub->ID, '_clms_submission_user_id', true ) );
			$lesson_id   = absint( get_post_meta( $sub->ID, '_clms_submission_lesson_id', true ) );
			$sub_course  = absint( get_post_meta( $sub->ID, '_clms_submission_course_id', true ) );
			$sub_status  = get_post_meta( $sub->ID, '_clms_submission_status', true ) ?: 'submitted';
			$student     = get_userdata( $student_id );
			$student_name = $student ? esc_html( $student->display_name ?: $student->user_login ) : "#{$student_id}";
			$lesson_title = $lesson_id ? esc_html( get_the_title( $lesson_id ) ) : esc_html__( '—', 'atora-lms' );
			$course_title = $sub_course ? esc_html( get_the_title( $sub_course ) ) : esc_html__( '—', 'atora-lms' );
			$date         = esc_html( mysql2date( 'd/m/Y H:i', $sub->post_date ) );
			$status_label = isset( $status_labels[ $sub_status ] ) ? $status_labels[ $sub_status ] : $sub_status;

			$sg_url = '';
			if ( $grading && method_exists( $grading, 'get_speedgrade_url' ) ) {
				$sg_url = $grading->get_speedgrade_url(
					$sub->ID,
					add_query_arg( array( 'page' => 'clms-speedgrader', 'course_id' => $sub_course, 'status' => $sub_status, 'cohort_id' => $cohort_id ), admin_url( 'admin.php' ) )
				);
			}

			echo '<tr>';
			echo '<td><strong>' . $student_name . '</strong></td>';
			echo '<td>' . $lesson_title . '</td>';
			echo '<td>' . $course_title . '</td>';
			echo '<td>' . esc_html( $status_label ) . '</td>';
			echo '<td>' . $date . '</td>';
			echo '<td>';
			if ( $sg_url ) {
				echo '<a class="button button-small" href="' . esc_url( $sg_url ) . '">' . esc_html__( 'Revisar', 'atora-lms' ) . '</a>';
			} else {
				echo '<a class="button button-small" href="' . esc_url( get_edit_post_link( $sub->ID, '' ) ) . '">' . esc_html__( 'Ver', 'atora-lms' ) . '</a>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	protected function redirect_to_canonical_speedgrader( $submission_id, $course_id = 0, $status = '', $cohort_id = 0 ) {
		$submission_id = absint( $submission_id );
		$course_id     = absint( $course_id );
		$status        = sanitize_key( $status );
		$cohort_id     = absint( $cohort_id );

		if ( ! $submission_id || 'clms_submission' !== get_post_type( $submission_id ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Entrega no válida.', 'atora-lms' ) . '</p></div></div>';
			return;
		}

		$grading = $this->get_grading_instance();

		if ( ! $grading || ! method_exists( $grading, 'get_speedgrade_url' ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'El módulo de SpeedGrade no está disponible.', 'atora-lms' ) . '</p></div></div>';
			return;
		}

		$return_args = array(
			'page' => 'clms-speedgrader',
		);

		if ( $course_id ) {
			$return_args['course_id'] = $course_id;
		}

		if ( $status ) {
			$return_args['status'] = $status;
		}
		if ( $cohort_id ) {
			$return_args['cohort_id'] = $cohort_id;
		}

		$return_url = add_query_arg( $return_args, admin_url( 'admin.php' ) );

		wp_safe_redirect( $grading->get_speedgrade_url( $submission_id, $return_url ) );
		exit;
	}

	protected function render_sparkline( $series ) {
		$series = is_array( $series ) ? $series : array();
		if ( empty( $series ) ) {
			$series = array_fill( 0, 6, array( 'value' => 0 ) );
		}

		$values = array_map(
			static function( $item ) {
				return isset( $item['value'] ) ? (float) $item['value'] : 0;
			},
			$series
		);
		$max_value = max( 1, (float) max( $values ) );

		echo '<div class="clms-admin-sparkline" aria-hidden="true">';
		foreach ( $values as $value ) {
			$height = min( 100, ( $value / $max_value ) * 100 );
			echo '<span style="height:' . esc_attr( round( $height, 2 ) ) . '%"></span>';
		}
		echo '</div>';
	}

	protected function render_trend_card( $title, $series, $unit = '', $subtitle = '' ) {
		$series = is_array( $series ) ? $series : array();
		$last_value = 0;
		$prev_value = 0;
		$count      = count( $series );

		if ( $count > 0 ) {
			$last_value = (float) ( $series[ $count - 1 ]['value'] ?? 0 );
			if ( $count > 1 ) {
				$prev_value = (float) ( $series[ $count - 2 ]['value'] ?? 0 );
			}
		}

		$delta       = 0;
		$delta_label = '—';
		if ( $prev_value > 0 ) {
			$delta       = ( ( $last_value - $prev_value ) / $prev_value ) * 100;
			$delta_label = number_format_i18n( $delta, 1 ) . '%';
		} elseif ( $last_value > 0 ) {
			$delta_label = number_format_i18n( 100, 0 ) . '%';
		}
		$delta_class = $delta >= 0 ? 'is-up' : 'is-down';

		$display_value = number_format_i18n( $last_value, 0 );
		if ( '%' === $unit ) {
			$display_value .= '%';
		} elseif ( '' !== $unit ) {
			$display_value .= $unit;
		}

		echo '<div class="clms-admin-trend-card">';
		echo '<span class="clms-admin-trend-title">' . esc_html( $title ) . '</span>';
		echo '<strong class="clms-admin-trend-value">' . esc_html( $display_value ) . '</strong>';
		echo '<div class="clms-admin-trend-meta">';
		if ( $subtitle ) {
			echo '<span>' . esc_html( $subtitle ) . '</span>';
		}
		echo '<span class="clms-admin-trend-delta ' . esc_attr( $delta_class ) . '">' . esc_html( $delta_label ) . '</span>';
		echo '</div>';
		$this->render_sparkline( $series );
		echo '</div>';
	}

	protected function render_admin_styles() {
			?>
			<style>
				.clms-admin-wrap .clms-admin-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px;margin:16px 0}
				.clms-admin-wrap .clms-admin-hero{margin-top:16px}
			.clms-admin-wrap .clms-admin-kicker{display:block;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#646970;font-weight:700;margin-bottom:6px}
			.clms-admin-wrap .clms-admin-role-banner{display:flex;gap:18px;justify-content:space-between;align-items:flex-start;flex-wrap:wrap}
			.clms-admin-wrap .clms-admin-chip-group{display:flex;gap:10px;flex-wrap:wrap}
			.clms-admin-wrap .clms-admin-chip{padding:12px 14px;border:1px solid #dcdcde;border-radius:12px;background:#f8fafc;min-width:120px}
			.clms-admin-wrap .clms-admin-chip span{display:block;font-size:12px;color:#646970;margin-bottom:4px}
			.clms-admin-wrap .clms-admin-chip strong{font-size:18px;color:#0f172a}
			.clms-admin-wrap .clms-admin-section-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px}
			.clms-admin-wrap .clms-admin-nav-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
			.clms-admin-wrap .clms-admin-nav-card{display:block;padding:16px;border:1px solid #dcdcde;border-radius:12px;background:#fff;text-decoration:none;color:#1d2327}
			.clms-admin-wrap .clms-admin-nav-card:hover{border-color:#2271b1;box-shadow:0 2px 12px rgba(15,23,42,.06)}
			.clms-admin-wrap .clms-admin-nav-card strong{display:block;font-size:16px;margin-bottom:6px}
			.clms-admin-wrap .clms-admin-nav-card span{display:block;color:#646970;line-height:1.5}
			.clms-admin-wrap .clms-admin-profile-head{display:flex;gap:18px;align-items:flex-start}
			.clms-admin-wrap .clms-admin-profile-avatar{width:120px;height:120px;object-fit:cover;border-radius:999px;border:1px solid #dcdcde;background:#f8fafc}
			.clms-admin-wrap .clms-admin-profile-form{display:grid;gap:14px}
			.clms-admin-wrap .clms-admin-profile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
			.clms-admin-wrap .clms-admin-profile-grid label,
			.clms-admin-wrap .clms-admin-profile-full{display:grid;gap:4px;font-size:12px;color:#475569;font-weight:600}
			.clms-admin-wrap .clms-admin-profile-grid input,
			.clms-admin-wrap .clms-admin-profile-grid select,
			.clms-admin-wrap .clms-admin-profile-full textarea{width:100%;max-width:100%;padding:8px 10px;border:1px solid #d0d7de;border-radius:8px;background:#fff}
			.clms-admin-wrap .clms-admin-profile-full textarea{resize:vertical}
			.clms-admin-wrap .clms-admin-filter{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
			.clms-admin-wrap .clms-admin-filter select{min-width:260px}
			.clms-admin-wrap .clms-admin-table th{font-weight:600}
			.clms-admin-wrap .clms-admin-note{margin:0 0 16px;color:#475569}
			.clms-admin-wrap .clms-admin-btn-speedgrade{font-weight:700}
			.clms-admin-wrap .clms-admin-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0}
			.clms-admin-wrap .clms-admin-metric{padding:14px;border:1px solid #dcdcde;border-radius:10px;background:#f8fafc}
			.clms-admin-wrap .clms-admin-metric span{display:block;color:#646970;font-size:12px;margin-bottom:4px}
			.clms-admin-wrap .clms-admin-trend-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:10px}
			.clms-admin-wrap .clms-admin-trend-card{padding:16px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;display:flex;flex-direction:column;gap:8px}
			.clms-admin-wrap .clms-admin-trend-title{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;font-weight:700}
			.clms-admin-wrap .clms-admin-trend-value{font-size:24px;color:#0f172a}
			.clms-admin-wrap .clms-admin-trend-meta{display:flex;justify-content:space-between;gap:8px;font-size:12px;color:#64748b}
			.clms-admin-wrap .clms-admin-trend-delta{font-weight:700}
			.clms-admin-wrap .clms-admin-trend-delta.is-up{color:#16a34a}
			.clms-admin-wrap .clms-admin-trend-delta.is-down{color:#dc2626}
			.clms-admin-wrap .clms-admin-sparkline{display:flex;align-items:flex-end;gap:4px;height:38px}
			.clms-admin-wrap .clms-admin-sparkline span{flex:1;background:#e2e8f0;border-radius:6px 6px 0 0;min-height:6px}
			.clms-admin-wrap .clms-admin-details{margin:0;border:1px dashed #dcdcde;border-radius:10px;padding:10px;background:#fff}
			.clms-admin-wrap .clms-admin-details summary{cursor:pointer;font-weight:700;font-size:12px;color:#1d2327;list-style:none}
			.clms-admin-wrap .clms-admin-details summary::-webkit-details-marker{display:none}
			.clms-admin-wrap .clms-admin-details[open]{border-style:solid}
			.clms-admin-wrap .clms-admin-detail-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin:10px 0}
			.clms-admin-wrap .clms-admin-detail-row span{display:block;font-size:11px;color:#646970}
			.clms-admin-wrap .clms-admin-detail-row strong{display:block;font-size:13px;color:#1d2327}
			.clms-admin-wrap .clms-admin-audit-list{display:grid;gap:8px}
			.clms-admin-wrap .clms-admin-audit-item{padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff}
			.clms-admin-wrap .clms-admin-message-form textarea,
			.clms-admin-wrap .clms-admin-message-form select{min-width:320px;max-width:100%}
			.clms-admin-wrap .clms-admin-message-form textarea{width:min(720px,100%)}
			.clms-admin-wrap .clms-admin-message-list{display:grid;gap:14px}
			.clms-admin-wrap .clms-admin-thread-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
			.clms-admin-wrap .clms-admin-thread-card{display:block;padding:14px;border:1px solid #dcdcde;border-radius:12px;background:#fff;color:#1d2327;text-decoration:none}
			.clms-admin-wrap .clms-admin-thread-card.is-active{border-color:#2271b1;box-shadow:0 2px 12px rgba(34,113,177,.08);background:#f8fbff}
			.clms-admin-wrap .clms-admin-thread-card strong,
			.clms-admin-wrap .clms-admin-thread-card span,
			.clms-admin-wrap .clms-admin-thread-card small{display:block}
			.clms-admin-wrap .clms-admin-thread-card span{color:#646970;margin:6px 0}
			.clms-admin-wrap .clms-admin-thread-card small{color:#646970}
			.clms-admin-wrap .clms-admin-message-card{border:1px solid #dcdcde;border-radius:12px;padding:16px;background:#fff}
			.clms-admin-wrap .clms-admin-message-card.is-unread{border-color:#2271b1;box-shadow:0 2px 12px rgba(34,113,177,.08)}
			.clms-admin-wrap .clms-admin-message-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}
			.clms-admin-wrap .clms-admin-message-meta{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:8px}
			.clms-admin-wrap .clms-admin-message-badge{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:#0f172a;color:#fff;font-size:12px;font-weight:600}
			.clms-admin-wrap .clms-admin-message-badge--soft{background:#e2e8f0;color:#0f172a}
			.clms-admin-wrap .clms-admin-message-date{font-size:12px;color:#646970}
			.clms-admin-wrap .clms-admin-message-foot{display:flex;gap:12px;flex-wrap:wrap;color:#646970;font-size:12px}
			.clms-admin-wrap .clms-admin-status-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
			.clms-admin-wrap .clms-admin-status-card{padding:14px;border-radius:12px;border:1px solid #dcdcde;background:#fff}
			.clms-admin-wrap .clms-admin-status-card strong,.clms-admin-wrap .clms-admin-status-card span{display:block}
			.clms-admin-wrap .clms-admin-status-card span{margin-top:4px;color:#646970}
				.clms-admin-wrap .clms-admin-status-card.is-ok{border-color:#bbf7d0;background:#f0fdf4}
				.clms-admin-wrap .clms-admin-status-card.is-warning{border-color:#fde68a;background:#fffbeb}
				.clms-admin-wrap .clms-admin-status-card.is-info{border-color:#bfdbfe;background:#eff6ff}
				.clms-admin-wrap .clms-admin-status-pill{display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700}
				.clms-admin-wrap .clms-admin-status-pill.is-error{background:#fee2e2;color:#991b1b}
				.clms-admin-wrap .clms-admin-status-pill.is-warning{background:#fef3c7;color:#92400e}
				.clms-admin-wrap .clms-admin-status-pill.is-info{background:#dbeafe;color:#1d4ed8}
				.clms-admin-wrap .clms-admin-grid-2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
				.clms-admin-wrap .clms-admin-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
				.clms-admin-wrap .clms-admin-funnel{display:grid;gap:12px}
				.clms-admin-wrap .clms-admin-funnel-step{display:grid;gap:6px}
				.clms-admin-wrap .clms-admin-funnel-head{display:flex;justify-content:space-between;gap:10px;align-items:center}
				.clms-admin-wrap .clms-admin-funnel-label{font-size:12px;font-weight:600;color:#475569}
				.clms-admin-wrap .clms-admin-funnel-value{font-size:14px;color:#1d2327}
				.clms-admin-wrap .clms-admin-funnel-bar{height:10px;background:#e2e8f0;border-radius:999px;overflow:hidden}
				.clms-admin-wrap .clms-admin-funnel-bar span{display:block;height:100%;background:#6366f1;border-radius:999px}
				.clms-admin-wrap .clms-admin-event-list{display:grid;gap:10px;margin-top:10px}
				.clms-admin-wrap .clms-admin-event{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}
				.clms-admin-wrap .clms-admin-event strong{display:block;font-size:14px;color:#1d2327}
				.clms-admin-wrap .clms-admin-event-meta{display:block;font-size:12px;color:#64748b;margin-top:4px}
				.clms-admin-wrap .clms-admin-view-toggle{gap:8px;margin:10px 0 18px}
				.clms-admin-wrap .clms-admin-view-link{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid #dcdcde;border-radius:999px;text-decoration:none;color:#1d2327;font-size:12px;font-weight:600;background:#fff}
				.clms-admin-wrap .clms-admin-view-link.is-active{background:#1d2327;color:#fff;border-color:#1d2327}
				.clms-admin-wrap.clms-admin-compact .clms-admin-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
				.clms-admin-wrap .clms-admin-chart{margin:18px 0}
				.clms-admin-wrap .clms-admin-chart-title{font-size:14px;font-weight:700;margin:0 0 10px;color:#1d2327}
				.clms-admin-wrap .clms-admin-chart-row{display:grid;grid-template-columns:minmax(160px,1.1fr) 3fr minmax(64px,auto);gap:12px;align-items:center;padding:6px 0}
				.clms-admin-wrap .clms-admin-chart-label{font-size:12px;color:#475569}
				.clms-admin-wrap .clms-admin-chart-bar{height:10px;background:#eef2ff;border-radius:999px;overflow:hidden}
				.clms-admin-wrap .clms-admin-chart-bar span{display:block;height:100%;background:#6366f1;border-radius:inherit}
				.clms-admin-wrap .clms-admin-chart-value{font-size:12px;font-weight:700;color:#1d2327;text-align:right}
				@media (max-width: 960px){.clms-admin-wrap .clms-admin-metrics{grid-template-columns:1fr}.clms-admin-wrap .clms-admin-profile-head{flex-direction:column}.clms-admin-wrap .clms-admin-grid-2{grid-template-columns:1fr}.clms-admin-wrap .clms-admin-profile-grid{grid-template-columns:1fr}}
				@media (max-width: 960px){.clms-admin-wrap .clms-admin-chart-row{grid-template-columns:1fr}.clms-admin-wrap .clms-admin-chart-value{text-align:left}}
			</style>
			<?php
		}

		protected function render_bar_chart( $title, $rows, $label_key, $value_key, $unit = '', $max_override = 0, $limit = 0 ) {
			$rows = is_array( $rows ) ? $rows : array();
			$items = array();

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$label = isset( $row[ $label_key ] ) ? sanitize_text_field( (string) $row[ $label_key ] ) : '';
				$value = isset( $row[ $value_key ] ) ? (float) $row[ $value_key ] : 0;
				if ( '' === $label ) {
					continue;
				}
				$items[] = array(
					'label' => $label,
					'value' => $value,
				);
			}

			if ( empty( $items ) ) {
				return;
			}

			if ( $limit > 0 ) {
				$items = array_slice( $items, 0, $limit );
			}

			$values = array_map(
				static function( $item ) {
					return isset( $item['value'] ) ? (float) $item['value'] : 0;
				},
				$items
			);
			$max_value = $max_override > 0 ? (float) $max_override : (float) max( $values );
			if ( $max_value <= 0 ) {
				$max_value = 1;
			}

			echo '<div class="clms-admin-chart">';
			echo '<p class="clms-admin-chart-title">' . esc_html( $title ) . '</p>';
			foreach ( $items as $item ) {
				$value   = (float) $item['value'];
				$percent = min( 100, ( $value / $max_value ) * 100 );
				$display = number_format_i18n( $value, $unit === '$' ? 2 : 0 );
				if ( '%' === $unit ) {
					$display .= '%';
				} elseif ( '$' === $unit ) {
					$display = '$' . $display;
				}
				echo '<div class="clms-admin-chart-row">';
				echo '<span class="clms-admin-chart-label">' . esc_html( $item['label'] ) . '</span>';
				echo '<div class="clms-admin-chart-bar"><span style="width:' . esc_attr( round( $percent, 2 ) ) . '%"></span></div>';
				echo '<span class="clms-admin-chart-value">' . esc_html( $display ) . '</span>';
				echo '</div>';
			}
			echo '</div>';
		}

	protected function find_card_value( $cards, $label, $fallback = '—' ) {
		$cards     = is_array( $cards ) ? $cards : array();
		$label_key = sanitize_title( (string) $label );

		foreach ( $cards as $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}
			$card_label = sanitize_title( (string) ( $card['label'] ?? '' ) );
			if ( $card_label && $card_label === $label_key ) {
				return $card['value'] ?? $fallback;
			}
		}

		return $fallback;
	}

	protected function parse_numeric_value( $value ) {
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}

		$raw = (string) $value;
		if ( false !== strpos( $raw, '/' ) ) {
			$raw = trim( strstr( $raw, '/', true ) );
		}

		$clean = preg_replace( '/[^0-9\.,-]/', '', $raw );
		if ( '' === $clean ) {
			return 0;
		}

		if ( false !== strpos( $clean, ',' ) && false !== strpos( $clean, '.' ) ) {
			$clean = str_replace( ',', '', $clean );
		} else {
			$clean = str_replace( ',', '.', $clean );
		}

		return (float) $clean;
	}

	protected function format_source_breakdown( $source_breakdown ) {
		$source_breakdown = is_array( $source_breakdown ) ? $source_breakdown : array();

		if ( empty( $source_breakdown ) ) {
			return '—';
		}

		$labels = array(
			'manual'        => 'Manual',
			'ai_assisted'   => 'AI assisted',
			'ai_auto_grade' => 'AI auto',
			'peer_review'   => 'Peer',
			'hybrid'        => 'Hybrid',
		);
		$parts = array();

		foreach ( $source_breakdown as $source => $count ) {
			$source = sanitize_key( (string) $source );
			$count  = absint( $count );

			if ( ! $count ) {
				continue;
			}

			$parts[] = ( isset( $labels[ $source ] ) ? $labels[ $source ] : ucfirst( $source ) ) . ': ' . $count;
		}

		return ! empty( $parts ) ? implode( ' | ', $parts ) : '—';
	}

	protected function format_source_label( $source ) {
		$source = sanitize_key( (string) $source );

		if ( ! $source ) {
			return '—';
		}

		$labels = array(
			'manual'        => 'Manual',
			'ai_assisted'   => 'AI assisted',
			'ai_auto_grade' => 'AI auto',
			'peer_review'   => 'Peer review',
			'hybrid'        => 'Hybrid',
		);

		return isset( $labels[ $source ] ) ? $labels[ $source ] : ucfirst( str_replace( '_', ' ', $source ) );
	}

	// ═══════════════════════════════════════════════════════════════════════════════
	// WP DASHBOARD — Widgets nativos en index.php
	// ═══════════════════════════════════════════════════════════════════════════════

}
