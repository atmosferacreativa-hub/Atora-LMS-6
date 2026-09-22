<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Admin_Menu_Navigation_Trait {
	protected function get_current_role_context() {
		$user = wp_get_current_user();
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return 'student';
		}

		return $this->get_role_context_for_user( $user );
	}

	/**
	 * Resuelve contexto de rol para un usuario concreto.
	 *
	 * @param WP_User $user Usuario a evaluar.
	 * @return string
	 */
	protected function get_role_context_for_user( WP_User $user ) {
		if ( user_can( $user, 'manage_options' ) ) {
			return 'admin';
		}

		// Roles CRM de Fase 5
		if ( user_can( $user, 'clms_manage_crm' ) ) {
			return 'crm_manager';
		}
		if ( user_can( $user, 'clms_access_crm_view' ) && ! user_can( $user, 'clms_manage_courses' ) ) {
			return 'crm_operator';
		}

		// Roles académicos
		if ( user_can( $user, 'clms_view_teacher_dashboard' ) || user_can( $user, 'clms_manage_courses' ) || user_can( $user, 'clms_grade_submissions' ) ) {
			return 'instructor';
		}
		if ( user_can( $user, 'clms_manage_enrollments' ) ) {
			return 'coordinator';
		}

		if ( user_can( $user, 'clms_manage_commerce' ) ) {
			return 'collaborator';
		}

		return 'student';
	}

	protected function get_role_context_label( $role_context ) {
		$labels = array(
			'admin'        => __( 'Administrador', 'atora-lms' ),
			'crm_manager'  => __( 'CRM Manager', 'atora-lms' ),
			'crm_operator' => __( 'Operador CRM', 'atora-lms' ),
			'coordinator'  => __( 'Coordinador', 'atora-lms' ),
			'instructor'   => __( 'Docente', 'atora-lms' ),
			'collaborator' => __( 'Colaborador', 'atora-lms' ),
			'student'      => __( 'Estudiante', 'atora-lms' ),
		);

		return isset( $labels[ $role_context ] ) ? $labels[ $role_context ] : __( 'Usuario', 'atora-lms' );
	}

	/**
	 * Devuelve el label del rol principal WP para mostrar perfil real.
	 *
	 * @param WP_User $user Usuario objetivo.
	 * @return string
	 */
	protected function get_primary_wp_role_label( WP_User $user ): string {
		$role_key = '';
		if ( ! empty( $user->roles ) && is_array( $user->roles ) ) {
			$role_key = sanitize_key( (string) reset( $user->roles ) );
		}

		if ( '' !== $role_key && function_exists( 'translate_user_role' ) ) {
			global $wp_roles;
			if ( $wp_roles instanceof WP_Roles && isset( $wp_roles->roles[ $role_key ]['name'] ) ) {
				return translate_user_role( (string) $wp_roles->roles[ $role_key ]['name'] );
			}
		}

		return $this->get_role_context_label( $this->get_role_context_for_user( $user ) );
	}

		protected function get_role_context_description( $role_context ) {
			$descriptions = array(
				'crm_manager'  => __( 'Gestión operativa del CRM: contactos, pipeline, campañas, secuencias y reportes.', 'atora-lms' ),
				'crm_operator' => __( 'Gestión de pipeline, contactos y comunicaciones con clientes y estudiantes.', 'atora-lms' ),
				'coordinator'  => __( 'Coordinación académica: matrículas, progreso y acompañamiento de estudiantes.', 'atora-lms' ),
				'admin'        => __( 'Tienes visibilidad completa del núcleo ATORA: operación académica, IA, operación comercial, analítica y configuración.', 'atora-lms' ),
				'instructor'   => __( 'Tienes foco en cursos, lecciones, evaluaciones, IA académica y tu presencia docente.', 'atora-lms' ),
				'collaborator' => __( 'Tienes foco operativo en operación comercial, seguimiento, analítica y coordinación transversal.', 'atora-lms' ),
				'student'      => __( 'Aquí encuentras tus accesos rápidos, perfil y visibilidad de tu ruta de aprendizaje.', 'atora-lms' ),
			);

		return isset( $descriptions[ $role_context ] ) ? $descriptions[ $role_context ] : '';
	}

	protected function get_role_summary_metrics( $role_context, $user_id ) {
		$user_id = absint( $user_id );
		$items   = array();

		if ( 'student' === $role_context ) {
			$items[] = array(
				'label' => __( 'Programas', 'atora-lms' ),
				'value' => class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_user_enrolled_programs' ) ? count( ( method_exists('CLMS_Helper','get_user_enrolled_programs') ? CLMS_Helper::get_user_enrolled_programs( $user_id ) : array() ) ) : 0,
			);
			$items[] = array(
				'label' => __( 'Cursos', 'atora-lms' ),
				'value' => class_exists( 'CLMS_Helper' ) ? count( ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $user_id ) ) ) : 0,
			);
			$completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
			$items[] = array(
				'label' => __( 'Lecciones', 'atora-lms' ),
				'value' => is_array( $completed ) ? count( array_filter( array_map( 'absint', $completed ) ) ) : 0,
			);
			return $items;
		}

		if ( 'instructor' === $role_context ) {
			$course_ids = $this->get_courses_owned_by_user( $user_id );
			$lesson_ids = $this->get_lessons_owned_by_user( $user_id );
			$cohort_service = $this->get_cohort_service();
			$cohort_count = ( $cohort_service && method_exists( $cohort_service, 'get_visible_cohort_ids' ) )
				? count( (array) $cohort_service->get_visible_cohort_ids( $user_id, array(), 200 ) )
				: 0;
			$items[] = array( 'label' => __( 'Programas', 'atora-lms' ), 'value' => count( $this->get_programs_owned_by_user( $user_id ) ) );
			$items[] = array( 'label' => __( 'Cursos', 'atora-lms' ), 'value' => count( $course_ids ) );
			$items[] = array( 'label' => __( 'Cohortes', 'atora-lms' ), 'value' => $cohort_count );
			$items[] = array( 'label' => __( 'Lecciones', 'atora-lms' ), 'value' => count( $lesson_ids ) );
			$items[] = array( 'label' => __( 'Evaluaciones', 'atora-lms' ), 'value' => $this->count_submissions_for_courses( $course_ids ) );
			return $items;
		}

		if ( 'collaborator' === $role_context ) {
			$items[] = array( 'label' => __( 'Productos', 'atora-lms' ), 'value' => post_type_exists( 'product' ) ? $this->count_posts_by_type( 'product' ) : 0 );
			$items[] = array( 'label' => __( 'Cursos comerciales', 'atora-lms' ), 'value' => $this->count_commercial_entities( 'lm_course' ) );
			$items[] = array( 'label' => __( 'Programas comerciales', 'atora-lms' ), 'value' => $this->count_commercial_entities( 'lm_program' ) );
			return $items;
		}

			// Para el Panel/Hub: no contamos borradores ni papelera como contenido real.
			$items[] = array( 'label' => __( 'Programas', 'atora-lms' ), 'value' => $this->count_posts_by_type( 'lm_program', array( 'publish', 'private' ) ) );
			$items[] = array( 'label' => __( 'Cursos', 'atora-lms' ), 'value' => $this->count_posts_by_type( 'lm_course', array( 'publish', 'private' ) ) );
		$items[] = array( 'label' => __( 'Cohortes', 'atora-lms' ), 'value' => $this->count_posts_by_type( 'lm_cohort' ) );
		$items[] = array( 'label' => __( 'Lecciones', 'atora-lms' ), 'value' => $this->count_posts_by_type( 'lm_lesson' ) );
		$items[] = array( 'label' => __( 'Entregas', 'atora-lms' ), 'value' => $this->count_posts_by_type( 'clms_submission' ) );

		return $items;
	}

	protected function get_navigation_groups( $role_context ) {
		$groups = array();

		$operations = array_filter(
			array(
				$this->build_nav_item( __( 'Mi perfil', 'atora-lms' ), __( 'Gestiona tu identidad y tus accesos principales.', 'atora-lms' ), admin_url( 'admin.php?page=clms-my-profile' ) ),
				( $this->can_manage_programs() ? $this->build_nav_item( __( 'Programas', 'atora-lms' ), __( 'Diplomados y agrupaciones académicas.', 'atora-lms' ), admin_url( 'edit.php?post_type=lm_program' ) ) : array() ),
				( $this->can_manage_courses() ? $this->build_nav_item( __( 'Cursos', 'atora-lms' ), __( 'Estructura principal de la oferta formativa.', 'atora-lms' ), admin_url( 'edit.php?post_type=lm_course' ) ) : array() ),
				( $this->can_manage_courses() ? $this->build_nav_item( __( 'Cohortes', 'atora-lms' ), __( 'Grupos académicos por generación, empresa o docente.', 'atora-lms' ), admin_url( 'edit.php?post_type=lm_cohort' ) ) : array() ),
				( $this->can_manage_lessons() ? $this->build_nav_item( __( 'Lecciones', 'atora-lms' ), __( 'Sesiones, actividades y contenidos.', 'atora-lms' ), admin_url( 'edit.php?post_type=lm_lesson' ) ) : array() ),
			)
		);
		$groups[] = array(
			'eyebrow' => __( 'Jerarquía académica', 'atora-lms' ),
			'title'   => __( 'Programas, cursos y lecciones', 'atora-lms' ),
			'items'   => $operations,
		);

		$assessment = array_filter(
			array(
				( current_user_can( 'clms_manage_lessons' ) ? $this->build_nav_item( __( 'Rúbricas', 'atora-lms' ), __( 'Matrices de evaluación integradas con Gradebook.', 'atora-lms' ), ( current_user_can( 'clms_grade_submissions' ) || current_user_can( 'manage_options' ) ) ? $this->get_gradebook_rubrics_url() : admin_url( 'edit.php?post_type=clms_rubric' ) ) : array() ),
				( current_user_can( 'clms_grade_submissions' ) ? $this->build_nav_item( __( 'Libro de calificaciones', 'atora-lms' ), __( 'Vista formal de calificaciones por curso.', 'atora-lms' ), admin_url( 'admin.php?page=clms-gradebook' ) ) : array() ),
				( current_user_can( 'clms_grade_submissions' ) ? $this->build_nav_item( __( 'SpeedGrade', 'atora-lms' ), __( 'Cola operativa de revisión y feedback.', 'atora-lms' ), admin_url( 'admin.php?page=clms-speedgrader' ) ) : array() ),
			)
		);
		$groups[] = array(
			'eyebrow' => __( 'Evaluaciones', 'atora-lms' ),
			'title'   => __( 'Calificación y revisión', 'atora-lms' ),
			'items'   => $assessment,
		);

		$intelligence = array_filter(
			array(
				( current_user_can( 'clms_manage_courses' ) || current_user_can( 'manage_options' ) ? $this->build_nav_item( __( 'IA', 'atora-lms' ), __( 'Configuración, exámenes IA y evaluación asistida.', 'atora-lms' ), admin_url( 'admin.php?page=clms-ai-hub' ) ) : array() ),
				( current_user_can( 'clms_view_teacher_dashboard' ) || current_user_can( 'manage_options' ) ? $this->build_nav_item( __( 'Perfil docente', 'atora-lms' ), __( 'Presencia pública y comercial del docente.', 'atora-lms' ), admin_url( 'admin.php?page=clms-instructor-profile' ) ) : array() ),
			)
		);
		$groups[] = array(
			'eyebrow' => __( 'IA y perfil', 'atora-lms' ),
			'title'   => __( 'Automatización y presencia docente', 'atora-lms' ),
			'items'   => $intelligence,
		);

		$business = array_filter(
			array(
					( current_user_can( 'manage_options' ) ? $this->build_nav_item( __( 'Control central', 'atora-lms' ), __( 'Visión integral del estado del negocio.', 'atora-lms' ), admin_url( 'admin.php?page=clms-control-center' ) ) : array() ),
					( $this->can_access_academic_calendar() ? $this->build_nav_item( __( 'Calendario académico', 'atora-lms' ), __( 'Entregas, clases y evaluaciones desde Hub académico.', 'atora-lms' ), $this->get_academic_calendar_url() ) : array() ),
					( $this->can_access_academic_calendar() ? $this->build_nav_item( __( 'Planes de seguimiento', 'atora-lms' ), __( 'Tu ritmo de contacto con estudiantes en riesgo, sobre un calendario visual.', 'atora-lms' ), admin_url( 'admin.php?page=atora-followup-plans' ) ) : array() ),
					( $this->can_access_commercial_calendar() ? $this->build_nav_item( __( 'Calendario comercial', 'atora-lms' ), __( 'Reuniones y seguimiento comercial desde Hub comercial.', 'atora-lms' ), $this->get_commercial_calendar_url() ) : array() ),
					( current_user_can( 'manage_options' ) ? $this->build_nav_item( __( 'Hub ajustes', 'atora-lms' ), __( 'Email, WhatsApp, Telegram y configuración operativa desde un solo lugar.', 'atora-lms' ), admin_url( 'admin.php?page=clms-settings-hub' ) ) : array() ),
					( current_user_can( 'clms_manage_commerce' ) || current_user_can( 'manage_options' ) ? $this->build_nav_item( __( 'Hub comercial', 'atora-lms' ), __( 'Ventas, afiliados y crecimiento comercial desde una sola vista.', 'atora-lms' ), admin_url( 'admin.php?page=clms-commercial-hub' ) ) : array() ),
					( current_user_can( 'clms_manage_commerce' ) || current_user_can( 'manage_options' ) ? $this->build_nav_item( __( 'Dashboard comercial', 'atora-lms' ), __( 'Funnel comercial, ingresos y productos clave.', 'atora-lms' ), admin_url( 'admin.php?page=clms-commerce-dashboard' ) ) : array() ),
					$this->build_nav_item( __( 'Analítica', 'atora-lms' ), __( 'Lectura operativa por rol del estado de la plataforma.', 'atora-lms' ), $this->get_analytics_hub_url() ),
					$this->build_nav_item( __( 'Mensajes', 'atora-lms' ), __( 'Feedback, avisos y comunicación operativa.', 'atora-lms' ), admin_url( 'admin.php?page=clms-messages' ) ),
				)
			);
			$groups[] = array(
				'eyebrow' => __( 'Operación', 'atora-lms' ),
				'title'   => __( 'Operación comercial, analítica y mensajes', 'atora-lms' ),
				'items'   => $business,
			);

		if ( 'student' === $role_context ) {
			$groups[0]['items'] = array_filter(
				array(
					$this->build_nav_item( __( 'Mi perfil', 'atora-lms' ), __( 'Visibilidad de tu progreso, cursos y programas.', 'atora-lms' ), admin_url( 'admin.php?page=clms-my-profile' ) ),
					$this->build_nav_item( __( 'Mensajes', 'atora-lms' ), __( 'Revisa feedback y avisos académicos.', 'atora-lms' ), admin_url( 'admin.php?page=clms-messages' ) ),
					$this->build_nav_item( __( 'Analítica', 'atora-lms' ), __( 'Resumen de tu actividad y avance.', 'atora-lms' ), $this->get_analytics_hub_url() ),
				)
			);
			$groups[1]['items'] = array();
			$groups[2]['items'] = array();
		}

		if ( 'collaborator' === $role_context ) {
			$groups[0]['items'] = array_filter(
				array(
					$this->build_nav_item( __( 'Mi perfil', 'atora-lms' ), __( 'Identidad operativa dentro de ATORA.', 'atora-lms' ), admin_url( 'admin.php?page=clms-my-profile' ) ),
					( current_user_can( 'clms_manage_commerce' ) ? $this->build_nav_item( __( 'Hub comercial', 'atora-lms' ), __( 'Productos, afiliados y operación de ventas.', 'atora-lms' ), admin_url( 'admin.php?page=clms-commercial-hub' ) ) : array() ),
				)
			);
			$groups[1]['items'] = array();
			$groups[2]['items'] = array();
		}

		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$groups = (array) CLMS_Helper::modular_apply( 'admin_navigation_groups', $groups, $role_context );
		}

		return $groups;
	}

	protected function get_profile_quick_links( $role_context ) {
		$links = array(
			$this->build_nav_item( __( 'Resumen', 'atora-lms' ), __( 'Volver al hub principal de ATORA.', 'atora-lms' ), admin_url( 'admin.php?page=clms-dashboard' ) ),
			$this->build_nav_item( __( 'Mensajes', 'atora-lms' ), __( 'Feedback, avisos y comunicación operativa.', 'atora-lms' ), admin_url( 'admin.php?page=clms-messages' ) ),
		);

		if ( 'student' === $role_context ) {
			$links[] = $this->build_nav_item( __( 'Analítica', 'atora-lms' ), __( 'Mide tu progreso y actividad académica.', 'atora-lms' ), $this->get_analytics_hub_url() );
		}

		if ( 'instructor' === $role_context || 'admin' === $role_context ) {
			$links[] = $this->build_nav_item( __( 'Perfil docente', 'atora-lms' ), __( 'Editar y revisar tu presencia pública.', 'atora-lms' ), admin_url( 'admin.php?page=clms-instructor-profile' ) );
		}

		if ( 'collaborator' === $role_context || 'admin' === $role_context ) {
			$links[] = $this->build_nav_item( __( 'Hub comercial', 'atora-lms' ), __( 'Acceso rápido a ventas, afiliados y crecimiento.', 'atora-lms' ), admin_url( 'admin.php?page=clms-commercial-hub' ) );
		}

		return $links;
	}

	protected function get_analytics_cards( $role_context, $user_id ) {
		$user_id = absint( $user_id );

		if ( 'student' === $role_context ) {
			return array(
				array( 'label' => __( 'Programas activos', 'atora-lms' ), 'value' => class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_user_enrolled_programs' ) ? count( ( method_exists('CLMS_Helper','get_user_enrolled_programs') ? CLMS_Helper::get_user_enrolled_programs( $user_id ) : array() ) ) : 0 ),
				array( 'label' => __( 'Cursos activos', 'atora-lms' ), 'value' => class_exists( 'CLMS_Helper' ) ? count( ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $user_id ) ) ) : 0 ),
				array( 'label' => __( 'Lecciones completadas', 'atora-lms' ), 'value' => count( array_filter( array_map( 'absint', (array) get_user_meta( $user_id, '_clms_completed_lessons', true ) ) ) ) ),
				array( 'label' => __( 'Evaluaciones entregadas', 'atora-lms' ), 'value' => $this->count_user_submissions( $user_id ) ),
			);
		}

		if ( 'instructor' === $role_context ) {
			$course_ids = $this->get_courses_owned_by_user( $user_id );
			return array(
				array( 'label' => __( 'Programas', 'atora-lms' ), 'value' => count( $this->get_programs_owned_by_user( $user_id ) ) ),
				array( 'label' => __( 'Cursos', 'atora-lms' ), 'value' => count( $course_ids ) ),
				array( 'label' => __( 'Lecciones', 'atora-lms' ), 'value' => count( $this->get_lessons_owned_by_user( $user_id ) ) ),
				array( 'label' => __( 'Entregas pendientes', 'atora-lms' ), 'value' => $this->count_submissions_for_courses( $course_ids, 'submitted' ) ),
			);
		}

		if ( 'collaborator' === $role_context ) {
			return array(
				array( 'label' => __( 'Productos', 'atora-lms' ), 'value' => post_type_exists( 'product' ) ? $this->count_posts_by_type( 'product' ) : 0 ),
				array( 'label' => __( 'Cursos comerciales', 'atora-lms' ), 'value' => $this->count_commercial_entities( 'lm_course' ) ),
				array( 'label' => __( 'Programas comerciales', 'atora-lms' ), 'value' => $this->count_commercial_entities( 'lm_program' ) ),
				array( 'label' => __( 'Configuraciones activas', 'atora-lms' ), 'value' => 3 ),
			);
		}

			return array(
				array( 'label' => __( 'Programas', 'atora-lms' ), 'value' => $this->count_posts_by_type( 'lm_program', array( 'publish', 'private' ) ) ),
				array( 'label' => __( 'Cursos', 'atora-lms' ), 'value' => $this->count_posts_by_type( 'lm_course', array( 'publish', 'private' ) ) ),
				array( 'label' => __( 'Lecciones', 'atora-lms' ), 'value' => $this->count_posts_by_type( 'lm_lesson' ) ),
				array( 'label' => __( 'Entregas', 'atora-lms' ), 'value' => $this->count_posts_by_type( 'clms_submission' ) ),
			);
		}

	protected function build_nav_item( $title, $description, $url ) {
		return array(
			'title'       => sanitize_text_field( $title ),
			'description' => sanitize_text_field( $description ),
			'url'         => esc_url_raw( $url ),
		);
	}

	/**
	 * Obtiene el slug `page` desde una URL de admin.php.
	 */
	protected function get_admin_page_slug_from_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['query'] ) ) {
			return '';
		}

		$query = array();
		parse_str( (string) $parts['query'], $query );

		return sanitize_key( (string) ( $query['page'] ?? '' ) );
	}

	/**
	 * Red principal de hubs para mantener navegación consistente entre paneles.
	 *
	 * @param string $current_slug Slug de la página actual para excluirla del listado.
	 * @param int    $limit        Máximo de elementos (0 = sin límite).
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_operational_hub_links( string $current_slug = '', int $limit = 0 ): array {
		$current_slug = sanitize_key( $current_slug );

		$links = array(
			$this->build_nav_item( __( 'Panel ATORA', 'atora-lms' ), __( 'Vista principal con estado operativo general.', 'atora-lms' ), admin_url( 'admin.php?page=clms-dashboard' ) ),
			$this->build_nav_item( __( 'Hub académico', 'atora-lms' ), __( 'Programas, cursos, lecciones, rúbricas y evaluación.', 'atora-lms' ), admin_url( 'admin.php?page=clms-academic-hub' ) ),
			$this->build_nav_item( __( 'CRM Hub', 'atora-lms' ), __( 'Seguimiento comercial y académico en una sola vista.', 'atora-lms' ), admin_url( 'admin.php?page=clms-crm-hub' ) ),
			$this->build_nav_item( __( 'Hub comercial', 'atora-lms' ), __( 'Ventas, afiliados y operación comercial integrada.', 'atora-lms' ), admin_url( 'admin.php?page=clms-commercial-hub' ) ),
			$this->build_nav_item( __( 'Email Hub', 'atora-lms' ), __( 'Email Engine y Newsletter centralizados.', 'atora-lms' ), admin_url( 'admin.php?page=clms-email-hub' ) ),
			$this->build_nav_item( __( 'Hub ajustes', 'atora-lms' ), __( 'Configuración global, email y mensajería.', 'atora-lms' ), admin_url( 'admin.php?page=clms-settings-hub' ) ),
			$this->build_nav_item( __( 'Hub automatizaciones', 'atora-lms' ), __( 'Reglas automáticas, webhooks y orquestación.', 'atora-lms' ), admin_url( 'admin.php?page=clms-automation-hub' ) ),
			$this->build_nav_item( __( 'Analítica', 'atora-lms' ), __( 'KPIs y tendencias para decisiones rápidas.', 'atora-lms' ), $this->get_analytics_hub_url() ),
		);

		if ( '' !== $current_slug ) {
			$links = array_values(
				array_filter(
					$links,
					function ( array $item ) use ( $current_slug ): bool {
						$item_slug = $this->get_admin_page_slug_from_url( (string) ( $item['url'] ?? '' ) );
						return $item_slug !== $current_slug;
					}
				)
			);
		}

		return $this->unique_hub_items_by_url( $links, $limit, true );
	}

	/**
	 * Elimina duplicados por URL en colecciones de links/cards del Hub.
	 *
	 * @param array $items          Lista de items con clave url.
	 * @param int   $limit          Límite máximo (0 = sin límite).
	 * @param bool  $require_access Si true, filtra URLs sin acceso para el usuario actual.
	 * @return array<int,array<string,mixed>>
	 */
	protected function unique_hub_items_by_url( array $items, int $limit = 0, bool $require_access = false ): array {
		$unique = array();
		$seen   = array();

		foreach ( $items as $item ) {
			$item = is_array( $item ) ? $item : array();
			$url  = esc_url_raw( (string) ( $item['url'] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}
			if ( $require_access && ! $this->current_user_can_visit_url( $url ) ) {
				continue;
			}
			if ( isset( $seen[ $url ] ) ) {
				continue;
			}

			$seen[ $url ] = true;
			$unique[]     = $item;

			if ( $limit > 0 && count( $unique ) >= $limit ) {
				break;
			}
		}

		return array_values( $unique );
	}

	protected function get_courses_owned_by_user( $user_id ) {
		return get_posts(
			array(
				'post_type'              => 'lm_course',
				'post_status'            => array( 'publish', 'private', 'draft' ),
				'author'                 => absint( $user_id ),
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}

	protected function get_programs_owned_by_user( $user_id ) {
		return get_posts(
			array(
				'post_type'              => 'lm_program',
				'post_status'            => array( 'publish', 'private', 'draft' ),
				'author'                 => absint( $user_id ),
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}

	protected function get_lessons_owned_by_user( $user_id ) {
		return get_posts(
			array(
				'post_type'              => 'lm_lesson',
				'post_status'            => array( 'publish', 'private', 'draft' ),
				'author'                 => absint( $user_id ),
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}

		/**
		 * Cuenta posts por tipo, opcionalmente restringiendo a ciertos estados.
		 *
		 * Nota: `wp_count_posts()` devuelve un objeto con contadores por estado
		 * incluyendo `trash` y `auto-draft`. Para los contadores del Panel/Hub
		 * que se presentan como "contenido real", se pasa una lista explícita
		 * de estados (p.ej. `publish` y `private`) desde el callsite.
		 */
		protected function count_posts_by_type( $post_type, $statuses = null ) {
			$count = wp_count_posts( $post_type );

			if ( ! $count ) {
				return 0;
			}

			if ( null === $statuses ) {
				return absint( array_sum( (array) $count ) );
			}

			$total = 0;
			foreach ( (array) $statuses as $status ) {
				$status = sanitize_key( (string) $status );
				if ( '' === $status ) {
					continue;
				}
				$total += (int) ( $count->{$status} ?? 0 );
			}

			return absint( $total );
		}

	protected function count_commercial_entities( $post_type ) {
		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'private', 'draft' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'meta_key'               => '_clms_commercial_mode',
				'meta_value'             => 'commercial',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return absint( $query->found_posts );
	}

	protected function count_submissions_for_courses( $course_ids, $status = '' ) {
		$course_ids = array_values( array_filter( array_map( 'absint', (array) $course_ids ) ) );

		if ( empty( $course_ids ) ) {
			return 0;
		}

		$meta_query = array(
			array(
				'key'     => '_clms_submission_course_id',
				'value'   => $course_ids,
				'compare' => 'IN',
				'type'    => 'NUMERIC',
			),
		);

		if ( '' !== $status ) {
			$meta_query[] = array(
				'key'   => '_clms_submission_status',
				'value' => sanitize_key( $status ),
			);
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'clms_submission',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'meta_query'             => $meta_query,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return absint( $query->found_posts );
	}

	protected function count_user_submissions( $user_id ) {
		$query = new WP_Query(
			array(
				'post_type'              => 'clms_submission',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'meta_key'               => '_clms_submission_user_id',
				'meta_value'             => absint( $user_id ),
				'meta_type'              => 'NUMERIC',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return absint( $query->found_posts );
	}

	protected function count_submissions_by_status( $status = '' ) {
		$args = array(
			'post_type'              => 'clms_submission',
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$status = sanitize_key( (string) $status );
		if ( '' !== $status ) {
			$args['meta_key']   = '_clms_submission_status';
			$args['meta_value'] = $status;
		}

		$query = new WP_Query( $args );

		return absint( $query->found_posts );
	}

	/**
	 * Resuelve capabilities de menús custom con fallback para administradores.
	 *
	 * @param string $capability Capability objetivo.
	 * @return string
	 */
	protected function resolve_menu_capability( string $capability ): string {
		$capability = sanitize_key( $capability );
		if ( '' === $capability || 'read' === $capability || 'manage_options' === $capability ) {
			return '' !== $capability ? $capability : 'read';
		}

		if ( current_user_can( $capability ) ) {
			return $capability;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return 'manage_options';
		}

		return $capability;
	}

	protected function current_user_can_visit_url( $url ) {
		$url = (string) $url;

		if ( false !== strpos( $url, 'clms-speedgrader' ) || false !== strpos( $url, 'clms-gradebook' ) ) {
			return current_user_can( 'clms_grade_submissions' ) || current_user_can( 'manage_options' );
		}

		if ( false !== strpos( $url, 'clms-ai-hub' ) ) {
			return current_user_can( 'clms_manage_courses' ) || current_user_can( 'manage_options' );
		}

		if ( false !== strpos( $url, 'clms-academic-wizard' ) ) {
			return current_user_can( 'clms_manage_courses' ) || current_user_can( 'manage_options' );
		}

		if ( false !== strpos( $url, 'clms-academic-reports' ) ) {
			return current_user_can( 'clms_view_teacher_dashboard' ) || current_user_can( 'manage_options' );
		}

		if ( false !== strpos( $url, 'clms-settings' ) ) {
			return $this->can_access_settings_hub();
		}
		if ( false !== strpos( $url, 'clms-email-hub' ) ) {
			return $this->can_access_settings_hub();
		}
		if ( false !== strpos( $url, 'clms-automation-hub' ) ) {
			return $this->can_access_settings_hub();
		}
		if ( false !== strpos( $url, 'clms-commercial-hub' ) || false !== strpos( $url, 'clms-commercial-operations' ) || false !== strpos( $url, 'clms-commerce-hub' ) ) {
			return $this->can_access_commercial_hub();
		}
		if ( false !== strpos( $url, 'clms-settings-hub' ) ) {
			return $this->can_access_settings_hub();
		}

		if ( false !== strpos( $url, 'clms-control-center' ) || false !== strpos( $url, 'clms-maintenance' ) ) {
			return current_user_can( 'manage_options' );
		}

		if ( false !== strpos( $url, 'atora-crm-v2' ) ) {
			if ( ! $this->is_crm_v2_enabled() ) {
				return false;
			}
			$app_class = '\ATORA\CRM_V2\CRM_V2_App';
			if ( class_exists( $app_class ) && method_exists( $app_class, 'can_access' ) ) {
				return (bool) $app_class::can_access();
			}
			return current_user_can( 'manage_options' );
		}
		if ( false !== strpos( $url, 'atora-crm' ) ) {
			$crm_class = '\ATORA\CRM\CRM';
			if ( class_exists( $crm_class ) && method_exists( $crm_class, 'can_access_crm' ) ) {
				return (bool) $crm_class::can_access_crm( get_current_user_id() );
			}
			return current_user_can( 'manage_options' );
		}
		if ( false !== strpos( $url, 'atora-analytics' ) ) {
			return current_user_can( 'manage_options' );
		}
		if ( false !== strpos( $url, 'atora-emails' ) || false !== strpos( $url, 'atora-newsletter' ) ) {
			return $this->can_access_settings_hub();
		}
		if ( false !== strpos( $url, 'atora-messaging' ) || false !== strpos( $url, 'atora-webhooks' ) || false !== strpos( $url, 'atora-automations' ) ) {
			return $this->can_access_settings_hub();
		}
		if ( false !== strpos( $url, 'atora-security' ) ) {
			return $this->can_access_settings_hub();
		}
		if ( false !== strpos( $url, 'atora-affiliates' ) ) {
			return $this->can_access_commercial_hub();
		}
		if ( false !== strpos( $url, 'atora-calendar' ) ) {
			return $this->can_access_calendar_page();
		}

		return true;
	}

	protected function can_access_academic_calendar(): bool {
		return current_user_can( 'manage_options' )
			|| current_user_can( 'clms_access_admin' )
			|| current_user_can( 'clms_manage_courses' )
			|| current_user_can( 'clms_manage_lessons' )
			|| current_user_can( 'clms_view_teacher_dashboard' )
			|| current_user_can( 'clms_grade_submissions' )
			|| current_user_can( 'edit_posts' );
	}

	/**
	 * PT-5.1 (6.7.0): planes de seguimiento ahora sirve tanto al
	 * dominio académico como al comercial (Followup_Plan_Resolver por
	 * `domain`) — el gate de la página no puede seguir siendo SOLO
	 * can_access_academic_calendar(), o un vendedor sin ningún rol
	 * académico quedaría afuera de sus propios planes comerciales.
	 * Amplía con la misma capacidad ya usada para el hub Crecimiento
	 * (clms_access_crm_view) — no inventa una capacidad nueva.
	 */
	protected function can_access_followup_plans(): bool {
		return $this->can_access_academic_calendar()
			|| current_user_can( 'clms_access_crm_view' )
			|| current_user_can( 'manage_options' );
	}

	protected function can_access_settings_hub(): bool {
		return current_user_can( 'clms_access_admin' ) || current_user_can( 'manage_options' );
	}

	protected function can_access_commercial_hub(): bool {
		return current_user_can( 'manage_options' )
			|| current_user_can( 'clms_access_admin' )
			|| current_user_can( 'clms_manage_commerce' )
			|| current_user_can( 'edit_posts' );
	}

	protected function can_access_commercial_calendar(): bool {
		return $this->can_access_commercial_hub();
	}

	protected function can_access_calendar_page(): bool {
		// Cualquier usuario logueado puede ver el calendario (sus propias tareas y eventos).
		// La gestión de eventos queda restringida por can_manage_*_calendar_events().
		if ( is_user_logged_in() ) { return true; }
		return $this->can_access_academic_calendar() || $this->can_access_commercial_calendar();
	}

	protected function can_manage_academic_calendar_events(): bool {
		return current_user_can( 'manage_options' )
			|| current_user_can( 'clms_manage_courses' )
			|| current_user_can( 'clms_manage_lessons' )
			|| current_user_can( 'clms_view_teacher_dashboard' );
	}

	protected function can_manage_commercial_calendar_events(): bool {
		return current_user_can( 'manage_options' ) || current_user_can( 'clms_manage_commerce' );
	}

	protected function can_manage_calendar_events(): bool {
		return $this->can_manage_academic_calendar_events() || $this->can_manage_commercial_calendar_events();
	}

	protected function can_manage_programs() {
		return current_user_can( 'clms_manage_courses' ) || current_user_can( 'manage_options' );
	}

	protected function can_manage_courses() {
		return current_user_can( 'clms_manage_courses' ) || current_user_can( 'manage_options' );
	}

	protected function can_manage_lessons() {
		return current_user_can( 'clms_manage_lessons' ) || current_user_can( 'manage_options' );
	}

	protected function get_courses() {
		$posts = get_posts(
			array(
				'post_type'              => 'lm_course',
				'post_status'            => array( 'publish', 'private' ),
				'posts_per_page'         => self::COURSE_FILTER_LIMIT,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$options = array();

		foreach ( $posts as $post ) {
			$options[ $post->ID ] = $post->post_title;
		}

		return $options;
	}

	protected function get_grading_instance() {
		$grading = clms_core('CLMS_Grading');

		if ( $grading ) {
			return $grading;
		}

		return null;
	}

	/**
	 * Obtiene servicio de cohortes.
	 *
	 * @return CLMS_Cohort_Service|null
	 */
	protected function get_cohort_service() {
		$service = clms_core('CLMS_Cohort_Service');
		if ( ! $service && class_exists( 'CLMS_Cohort_Service' ) ) {
			$service = new CLMS_Cohort_Service();
		}

		return $service;
	}

	/**
	 * Opciones de cohortes para filtros de SpeedGrade.
	 *
	 * @return array<int,string>
	 */
	protected function get_speedgrader_cohort_options() {
		$service = $this->get_cohort_service();
		if ( ! $service || ! method_exists( $service, 'get_cohort_options_for_user' ) ) {
			return array();
		}

		return (array) $service->get_cohort_options_for_user( get_current_user_id() );
	}

	/**
	 * Secciones del curso seleccionado para filtro del SpeedGrade.
	 *
	 * @param int $wp_course_id WP post ID del curso (0 = todas las secciones del profesor).
	 * @return array<int,string>
	 */
	protected function get_speedgrader_section_options( int $wp_course_id = 0 ): array {
		if ( ! class_exists( 'ATORA\\LMS\\Section_Service' ) ) {
			return array();
		}

		$user_id = get_current_user_id();

		if ( $wp_course_id ) {
			$sections = \ATORA\LMS\Section_Service::get_sections_by_course( $wp_course_id );
		} else {
			$sections_list = \ATORA\LMS\Section_Service::get_sections_by_teacher( $user_id );
			$sections = array();
			foreach ( $sections_list as $s ) {
				$sections[ (int) $s['id'] ] = $s;
			}
		}

		if ( empty( $sections ) ) {
			return array();
		}

		$options = array();
		foreach ( $sections as $section ) {
			$sid   = (int) $section['id'];
			$label = sanitize_text_field( (string) ( $section['title'] ?: "Sección #{$sid}" ) );
			$options[ $sid ] = $label;
		}

		return $options;
	}

	protected function can_compose_messages() {
		return current_user_can( 'manage_options' ) || current_user_can( 'clms_view_teacher_dashboard' ) || current_user_can( 'clms_grade_submissions' );
	}

	protected function format_message_sender_label( $sender_type, $sender_name = '' ) {
		$sender_type = sanitize_key( (string) $sender_type );
		$sender_name = sanitize_text_field( (string) $sender_name );

		if ( 'teacher' === $sender_type ) {
			return $sender_name ? sprintf( __( 'Docente · %s', 'atora-lms' ), $sender_name ) : __( 'Docente', 'atora-lms' );
		}

		if ( 'ai' === $sender_type ) {
			return sprintf( __( 'IA · %s', 'atora-lms' ), $sender_name ? $sender_name : __( 'ATORA', 'atora-lms' ) );
		}

		return $sender_name ? sprintf( __( 'Sistema · %s', 'atora-lms' ), $sender_name ) : __( 'Sistema', 'atora-lms' );
	}

	protected function format_recommendation_label( $recommendation_type ) {
		$recommendation_type = sanitize_key( (string) $recommendation_type );
		$labels              = array(
			'progress'      => __( 'Avance', 'atora-lms' ),
			'reinforcement' => __( 'Refuerzo', 'atora-lms' ),
			'upsell'        => __( 'Venta adicional', 'atora-lms' ),
			'reminder'      => __( 'Recordatorio', 'atora-lms' ),
		);

		return isset( $labels[ $recommendation_type ] ) ? $labels[ $recommendation_type ] : ucfirst( str_replace( '_', ' ', $recommendation_type ) );
	}

	protected function format_message_type_label( $message_type ) {
		$message_type = sanitize_key( (string) $message_type );
		$labels       = array(
			'enrollment'         => __( 'Inscripción', 'atora-lms' ),
			'program_enrollment' => __( 'Programa', 'atora-lms' ),
			'progress_update'    => __( 'Seguimiento', 'atora-lms' ),
				'assessment_update'  => __( 'Evaluación', 'atora-lms' ),
				'lesson_available'   => __( 'Nueva lección', 'atora-lms' ),
				'commerce_followup'  => __( 'Comercial', 'atora-lms' ),
				'manual'             => __( 'Directo', 'atora-lms' ),
			);

		return isset( $labels[ $message_type ] ) ? $labels[ $message_type ] : ucfirst( str_replace( '_', ' ', $message_type ) );
	}

	protected function format_thread_type_label( $thread_type ) {
		$thread_type = sanitize_key( (string) $thread_type );
		$labels      = array(
			'general' => __( 'General', 'atora-lms' ),
			'course'  => __( 'Curso', 'atora-lms' ),
			'program' => __( 'Programa', 'atora-lms' ),
		);

		return isset( $labels[ $thread_type ] ) ? $labels[ $thread_type ] : ucfirst( str_replace( '_', ' ', $thread_type ) );
	}

	protected function format_entity_type_label( $type ) {
		$type   = sanitize_key( (string) $type );
		$labels = array(
			'lm_course'  => __( 'Curso', 'atora-lms' ),
			'lm_program' => __( 'Programa', 'atora-lms' ),
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( str_replace( '_', ' ', $type ) );
	}

	protected function format_event_type_label( $type ) {
		$type   = sanitize_key( (string) $type );
		$labels = array(
			'lesson_completed'         => __( 'Lección completada', 'atora-lms' ),
			'submission_created'       => __( 'Entrega creada', 'atora-lms' ),
			'submission_graded'        => __( 'Entrega evaluada', 'atora-lms' ),
			'commerce_order_processed' => __( 'Orden procesada', 'atora-lms' ),
			'course_access_notified'   => __( 'Acceso comunicado', 'atora-lms' ),
			'ai_error'                => __( 'Error IA', 'atora-lms' ),
			'permission_denied'       => __( 'Permiso denegado', 'atora-lms' ),
			'system_log'              => __( 'Sistema', 'atora-lms' ),
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : ucfirst( str_replace( '_', ' ', $type ) );
	}

	protected function resolve_thread_title_from_messages( $messages, $threads, $selected_thread ) {
		$selected_thread = sanitize_key( (string) $selected_thread );
		$messages        = is_array( $messages ) ? $messages : array();
		$threads         = is_array( $threads ) ? $threads : array();

		foreach ( $threads as $thread ) {
			if ( $selected_thread === sanitize_key( (string) ( $thread['thread_id'] ?? '' ) ) ) {
				return sanitize_text_field( (string) ( $thread['thread_label'] ?? __( 'General', 'atora-lms' ) ) );
			}
		}

		if ( ! empty( $messages[0]['thread_label'] ) ) {
			return sanitize_text_field( (string) $messages[0]['thread_label'] );
		}

		return __( 'General', 'atora-lms' );
	}

	protected function truncate_admin_text( $text, $length = 72 ) {
		$text   = trim( wp_strip_all_tags( (string) $text ) );
		$length = max( 20, absint( $length ) );

		if ( strlen( $text ) <= $length ) {
			return $text;
		}

		return rtrim( substr( $text, 0, $length - 3 ) ) . '...';
	}

	protected function format_admin_datetime( $datetime ) {
		$datetime = sanitize_text_field( (string) $datetime );
		$timestamp = $datetime ? strtotime( $datetime ) : false;

		if ( ! $timestamp ) {
			return '—';
		}

		return date_i18n( 'd/m/Y H:i', $timestamp );
	}

}
