<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Admin_Menu_Academic_Ops_Trait {
	public function render_enrollments_page(): void {
		if ( ! CLMS_Access::can_manage_enrollments() ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$this->render_admin_styles();

		if ( defined( 'ATORA_LMS_URL' ) ) {
			wp_enqueue_script(
				'atora-enrollments-admin',
				ATORA_LMS_URL . 'assets/js/atora-enrollments-admin.js',
				array( 'jquery' ),
				defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '1.0.0',
				true
			);
			wp_localize_script(
				'atora-enrollments-admin',
				'atoraEnrollmentsAdmin',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'atora_enrollments_admin' ),
				)
			);
		}

		$notice_type = isset( $_GET['clms_notice_type'] ) ? sanitize_key( wp_unslash( $_GET['clms_notice_type'] ) ) : '';
		$notice_text = isset( $_GET['clms_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['clms_notice'] ) ) : '';
		$notice_type = in_array( $notice_type, array( 'success', 'error' ), true ) ? $notice_type : '';
		$programs_url = admin_url( 'edit.php?post_type=lm_program' );
		$courses_url  = admin_url( 'edit.php?post_type=lm_course' );

		echo '<div class="wrap clms-admin-wrap">';
		echo '<h1>' . esc_html__( 'Inscripciones', 'atora-lms' ) . '</h1>';
		echo '<p class="clms-admin-note">' . esc_html__( 'Centraliza las rutas de matrícula (manual/CSV, invitaciones, enlaces) y gestiona el ciclo de vida del acceso (paid_until).', 'atora-lms' ) . '</p>';

		if ( $notice_text && $notice_type ) {
			echo '<div class="notice notice-' . esc_attr( $notice_type ) . ' is-dismissible"><p>' . esc_html( $notice_text ) . '</p></div>';
		}

		echo '<div class="clms-admin-card">';
		echo '<div class="clms-admin-section-head">';
		echo '<div>';
		echo '<span class="clms-admin-kicker">' . esc_html__( 'Métodos', 'atora-lms' ) . '</span>';
		echo '<h2 style="margin:0">' . esc_html__( 'Cómo inscribir estudiantes', 'atora-lms' ) . '</h2>';
		echo '</div>';
		echo '</div>';
		echo '<div class="clms-admin-nav-grid">';
		echo '<a class="clms-admin-nav-card" href="' . esc_url( $programs_url ) . '"><strong>' . esc_html__( 'Programas: Manual / CSV', 'atora-lms' ) . '</strong><span>' . esc_html__( 'Abre un programa y usa la caja “Matriculados / Manual / CSV masivo”.', 'atora-lms' ) . '</span></a>';
		echo '<a class="clms-admin-nav-card" href="' . esc_url( $programs_url ) . '"><strong>' . esc_html__( 'Programas: Invitaciones', 'atora-lms' ) . '</strong><span>' . esc_html__( 'Genera tokens nominales por email desde el metabox del programa.', 'atora-lms' ) . '</span></a>';
		echo '<a class="clms-admin-nav-card" href="' . esc_url( $courses_url ) . '"><strong>' . esc_html__( 'Cursos: Invitaciones + Enlace de acceso', 'atora-lms' ) . '</strong><span>' . esc_html__( 'Invita por email o crea un enlace (free/password/register) desde el metabox del curso.', 'atora-lms' ) . '</span></a>';
		echo '</div>';
		echo '</div>';

		echo '<div class="clms-admin-card">';
		echo '<span class="clms-admin-kicker">' . esc_html__( 'Ciclo de vida', 'atora-lms' ) . '</span>';
		echo '<h2 style="margin-top:0">' . esc_html__( 'Caducidad de acceso (paid_until)', 'atora-lms' ) . '</h2>';
		echo '<p class="clms-admin-note">' . esc_html__( 'Define hasta cuándo un estudiante puede ver un programa o curso. Útil para instituciones (pagos externos, renovaciones manuales) y para pruebas controladas.', 'atora-lms' ) . '</p>';

		$action_url = admin_url( 'admin-post.php' );

		echo '<style>.atora-enroll-suggest{display:grid;gap:6px}.atora-enroll-suggest button{justify-content:flex-start;text-align:left}</style>';
		echo '<div class="clms-admin-profile-grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));align-items:end">';
		echo '<form method="post" action="' . esc_url( $action_url ) . '" class="clms-admin-profile-form" style="margin:0">';
		wp_nonce_field( 'atora_enrollments_set_access' );
		echo '<input type="hidden" name="action" value="atora_enrollments_set_access">';
		echo '<input type="hidden" name="target_type" value="program">';
		echo '<label>' . esc_html__( 'Estudiante (email/usuario/ID)', 'atora-lms' ) . '<input name="student" type="text" placeholder="email@dominio.com" data-atora-enroll-search="user" data-atora-enroll-results="#atora-enroll-user-results-program"></label>';
		echo '<div id="atora-enroll-user-results-program" class="atora-enroll-suggest" aria-live="polite"></div>';
		echo '<label>' . esc_html__( 'Buscar programa (título)', 'atora-lms' ) . '<input type="text" placeholder="Diplomado…" data-atora-enroll-search="program" data-atora-enroll-target="#atora-enroll-target-program" data-atora-enroll-results="#atora-enroll-program-results"></label>';
		echo '<div id="atora-enroll-program-results" class="atora-enroll-suggest" aria-live="polite"></div>';
		echo '<label>' . esc_html__( 'Programa (ID)', 'atora-lms' ) . '<input id="atora-enroll-target-program" name="target_id" type="number" min="1" placeholder="123"></label>';
		echo '<label>' . esc_html__( 'Caduca (hora local)', 'atora-lms' ) . '<input name="expires_at" type="datetime-local"></label>';
		echo '<label style="display:flex;gap:8px;align-items:center;font-weight:600;color:#475569"><input type="checkbox" name="perpetual" value="1"> ' . esc_html__( 'Acceso perpetuo', 'atora-lms' ) . '</label>';
		echo '<label style="display:flex;gap:8px;align-items:center;font-weight:600;color:#475569"><input type="checkbox" name="create_if_missing" value="1"> ' . esc_html__( 'Crear matrícula si no existe', 'atora-lms' ) . '</label>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Guardar programa', 'atora-lms' ) . '</button>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( $action_url ) . '" class="clms-admin-profile-form" style="margin:0">';
		wp_nonce_field( 'atora_enrollments_set_access' );
		echo '<input type="hidden" name="action" value="atora_enrollments_set_access">';
		echo '<input type="hidden" name="target_type" value="course">';
		echo '<label>' . esc_html__( 'Estudiante (email/usuario/ID)', 'atora-lms' ) . '<input name="student" type="text" placeholder="email@dominio.com" data-atora-enroll-search="user" data-atora-enroll-results="#atora-enroll-user-results-course"></label>';
		echo '<div id="atora-enroll-user-results-course" class="atora-enroll-suggest" aria-live="polite"></div>';
		echo '<label>' . esc_html__( 'Buscar curso (título)', 'atora-lms' ) . '<input type="text" placeholder="Fotografía…" data-atora-enroll-search="course" data-atora-enroll-target="#atora-enroll-target-course" data-atora-enroll-results="#atora-enroll-course-results"></label>';
		echo '<div id="atora-enroll-course-results" class="atora-enroll-suggest" aria-live="polite"></div>';
		echo '<label>' . esc_html__( 'Curso (ID)', 'atora-lms' ) . '<input id="atora-enroll-target-course" name="target_id" type="number" min="1" placeholder="456"></label>';
		echo '<label>' . esc_html__( 'Caduca (hora local)', 'atora-lms' ) . '<input name="expires_at" type="datetime-local"></label>';
		echo '<label style="display:flex;gap:8px;align-items:center;font-weight:600;color:#475569"><input type="checkbox" name="perpetual" value="1"> ' . esc_html__( 'Acceso perpetuo', 'atora-lms' ) . '</label>';
		echo '<label style="display:flex;gap:8px;align-items:center;font-weight:600;color:#475569"><input type="checkbox" name="create_if_missing" value="1"> ' . esc_html__( 'Crear matrícula si no existe', 'atora-lms' ) . '</label>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Guardar curso', 'atora-lms' ) . '</button>';
		echo '</form>';
		echo '</div>';

		echo '<details class="clms-admin-details" style="margin-top:12px">';
		echo '<summary>' . esc_html__( 'Notas rápidas', 'atora-lms' ) . '</summary>';
		echo '<div style="padding-top:10px">';
		echo '<p class="clms-admin-note" style="margin:0">' . esc_html__( 'Tip: los IDs se ven en la URL al editar un curso/programa (post=123). “Acceso perpetuo” guarda caducidad NULL (sin vencimiento).', 'atora-lms' ) . '</p>';
		echo '</div>';
		echo '</details>';

		echo '</div>';
		echo '</div>';
	}

	public function handle_enrollments_set_access(): void {
		if ( ! CLMS_Access::can_manage_enrollments() ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		check_admin_referer( 'atora_enrollments_set_access' );

		$target_type      = isset( $_POST['target_type'] ) ? sanitize_key( wp_unslash( $_POST['target_type'] ) ) : '';
		$student_raw      = isset( $_POST['student'] ) ? sanitize_text_field( wp_unslash( $_POST['student'] ) ) : '';
		$target_id        = isset( $_POST['target_id'] ) ? absint( wp_unslash( $_POST['target_id'] ) ) : 0;
		$expires_at_raw   = isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) ) : '';
		$perpetual        = ! empty( $_POST['perpetual'] );
		$create_if_missing = ! empty( $_POST['create_if_missing'] );

		$user_id = $this->resolve_user_id_from_identifier( $student_raw );
		if ( ! $user_id ) {
			$this->redirect_enrollments_notice( 'error', __( 'No se encontró el estudiante.', 'atora-lms' ) );
		}

		if ( ! $target_id || ! in_array( $target_type, array( 'program', 'course' ), true ) ) {
			$this->redirect_enrollments_notice( 'error', __( 'Destino inválido.', 'atora-lms' ) );
		}

		$post_type = get_post_type( $target_id );
		if ( 'program' === $target_type && 'lm_program' !== $post_type ) {
			$this->redirect_enrollments_notice( 'error', __( 'El ID no corresponde a un programa.', 'atora-lms' ) );
		}
		if ( 'course' === $target_type && 'lm_course' !== $post_type ) {
			$this->redirect_enrollments_notice( 'error', __( 'El ID no corresponde a un curso.', 'atora-lms' ) );
		}

		if ( ! class_exists( 'CLMS_Helper' ) ) {
			$this->redirect_enrollments_notice( 'error', __( 'CLMS_Helper no está disponible.', 'atora-lms' ) );
		}

		$expires_at_utc = '';
		if ( ! $perpetual && $expires_at_raw ) {
			$expires_at_local = str_replace( 'T', ' ', $expires_at_raw );
			if ( 16 === strlen( $expires_at_local ) ) { // Y-m-d H:i
				$expires_at_local .= ':00';
			}
			$expires_at_utc = get_gmt_from_date( $expires_at_local );
		}

		if ( 'program' === $target_type ) {
			if ( $create_if_missing && ! CLMS_Helper::user_is_enrolled_in_program( $user_id, $target_id ) ) {
				CLMS_Helper::enroll_user_in_program( $user_id, $target_id );
			}
			$ok = CLMS_Helper::set_user_program_access_expiration( $user_id, $target_id, $expires_at_utc );
			if ( ! $ok ) {
				$this->redirect_enrollments_notice( 'error', __( 'No se pudo guardar el acceso del programa.', 'atora-lms' ) );
			}
			$this->redirect_enrollments_notice( 'success', __( 'Acceso del programa actualizado.', 'atora-lms' ) );
		}

		if ( $create_if_missing && ! CLMS_Helper::user_is_enrolled_in_course( $user_id, $target_id ) ) {
			CLMS_Helper::enroll_user_in_course( $user_id, $target_id );
		}
		$ok = CLMS_Helper::set_user_course_access_expiration( $user_id, $target_id, $expires_at_utc );
		if ( ! $ok ) {
			$this->redirect_enrollments_notice( 'error', __( 'No se pudo guardar el acceso del curso.', 'atora-lms' ) );
		}
		$this->redirect_enrollments_notice( 'success', __( 'Acceso del curso actualizado.', 'atora-lms' ) );
	}

	protected function resolve_user_id_from_identifier( string $identifier ): int {
		$identifier = trim( (string) $identifier );
		if ( '' === $identifier ) { return 0; }

		if ( is_numeric( $identifier ) ) {
			$u = get_userdata( absint( $identifier ) );
			return $u ? (int) $u->ID : 0;
		}
		if ( is_email( $identifier ) ) {
			$u = get_user_by( 'email', $identifier );
			return $u ? (int) $u->ID : 0;
		}

		$u = get_user_by( 'login', $identifier );
		return $u ? (int) $u->ID : 0;
	}

	protected function redirect_enrollments_notice( string $type, string $message ): void {
		$url = add_query_arg(
			array(
				'page'            => 'atora-enrollments',
				'clms_notice_type' => $type,
				'clms_notice'      => $message,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	public function ajax_enrollments_search(): void {
		check_ajax_referer( 'atora_enrollments_admin' );
		if ( ! CLMS_Access::can_manage_enrollments() ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$q    = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$q    = trim( (string) $q );

		if ( strlen( $q ) < 2 ) {
			wp_send_json_success( array() );
		}

		if ( 'user' === $type ) {
			$query = new WP_User_Query(
				array(
					'number'         => 10,
					'orderby'        => 'registered',
					'order'          => 'DESC',
					'fields'         => array( 'ID', 'user_email', 'user_login', 'display_name' ),
					'search'         => '*' . $q . '*',
					'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				)
			);
			$users = array();
			foreach ( (array) $query->get_results() as $u ) {
				$users[] = array(
					'id'           => (int) $u->ID,
					'email'        => (string) $u->user_email,
					'login'        => (string) $u->user_login,
					'display_name' => (string) $u->display_name,
				);
			}
			wp_send_json_success( $users );
		}

		if ( in_array( $type, array( 'program', 'course' ), true ) ) {
			$post_type = 'program' === $type ? 'lm_program' : 'lm_course';
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => array( 'publish', 'draft', 'private' ),
					's'              => $q,
					'posts_per_page' => 10,
					'orderby'        => 'ID',
					'order'          => 'DESC',
				)
			);
			$out = array();
			foreach ( (array) $posts as $p ) {
				$out[] = array(
					'id'     => (int) $p->ID,
					'title'  => (string) $p->post_title,
					'status' => (string) $p->post_status,
				);
			}
			wp_send_json_success( $out );
		}

		wp_send_json_success( array() );
	}

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
