<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Access {

	/**
	 * Capacidades LMS generales (no ligadas a CPT).
	 *
	 * @return array
	 */
	public static function get_capabilities() {
		return array(
			// ── LMS generales ────────────────────────────────────────────────
			'clms_access_admin',
			'clms_manage_courses',
			'clms_manage_lessons',
			'clms_manage_submissions',
			'clms_grade_submissions',
			'clms_view_teacher_dashboard',
			'clms_manage_commerce',
			'clms_manage_enrollments',
			'clms_manage_course_access',
			// Legacy: mantener por compatibilidad hacia atrás.
			'clms_manage_enrollment_access',
			// ── CRM — acceso y gestión (Fase 5) ──────────────────────────────
			'clms_access_crm_view',
			'clms_manage_crm',
			'crm_manage_campaigns',
			'crm_manage_pipelines',
			'crm_export_contacts',
			'crm_send_email',
			'crm_view_reports',
		);
	}

	/**
	 * Capacidades primitivas del CPT lm_course.
	 *
	 * @return array
	 */
	public static function get_course_caps() {
		return array(
			// Operaciones sobre el CPT
			'edit_lm_courses',
			'edit_others_lm_courses',
			'edit_private_lm_courses',
			'edit_published_lm_courses',
			'publish_lm_courses',
			'read_private_lm_courses',
			'delete_lm_courses',
			'delete_private_lm_courses',
			'delete_published_lm_courses',
			'delete_others_lm_courses',
			'create_lm_courses',
		);
	}

	/**
	 * Capacidades primitivas del CPT lm_lesson.
	 *
	 * @return array
	 */
	public static function get_lesson_caps() {
		return array(
			'edit_lm_lessons',
			'edit_others_lm_lessons',
			'edit_private_lm_lessons',
			'edit_published_lm_lessons',
			'publish_lm_lessons',
			'read_private_lm_lessons',
			'delete_lm_lessons',
			'delete_private_lm_lessons',
			'delete_published_lm_lessons',
			'delete_others_lm_lessons',
			'create_lm_lessons',
		);
	}

	/**
	 * Capacidades primitivas del CPT atora_teacher.
	 *
	 * @return array
	 */
	public static function get_teacher_caps() {
		return array(
			'edit_atora_teachers',
			'edit_others_atora_teachers',
			'edit_private_atora_teachers',
			'edit_published_atora_teachers',
			'publish_atora_teachers',
			'read_private_atora_teachers',
			'delete_atora_teachers',
			'delete_private_atora_teachers',
			'delete_published_atora_teachers',
			'delete_others_atora_teachers',
			'create_atora_teachers',
		);
	}


	/**
	 * Capacidades primitivas de CPTs secundarios del LMS.
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function get_secondary_cpt_caps() {
		return array(
			'rubric' => array(
				'edit_clms_rubrics',
				'edit_others_clms_rubrics',
				'edit_private_clms_rubrics',
				'edit_published_clms_rubrics',
				'publish_clms_rubrics',
				'read_private_clms_rubrics',
				'delete_clms_rubrics',
				'delete_private_clms_rubrics',
				'delete_published_clms_rubrics',
				'delete_others_clms_rubrics',
				'create_clms_rubrics',
			),
			'submission' => array(
				'edit_clms_submissions',
				'edit_others_clms_submissions',
				'edit_private_clms_submissions',
				'edit_published_clms_submissions',
				'publish_clms_submissions',
				'read_private_clms_submissions',
				'delete_clms_submissions',
				'delete_private_clms_submissions',
				'delete_published_clms_submissions',
				'delete_others_clms_submissions',
				'create_clms_submissions',
			),
			'peer_review' => array(
				'edit_clms_peer_reviews',
				'edit_others_clms_peer_reviews',
				'edit_private_clms_peer_reviews',
				'edit_published_clms_peer_reviews',
				'publish_clms_peer_reviews',
				'read_private_clms_peer_reviews',
				'delete_clms_peer_reviews',
				'delete_private_clms_peer_reviews',
				'delete_published_clms_peer_reviews',
				'delete_others_clms_peer_reviews',
				'create_clms_peer_reviews',
			),
		);
	}

	/**
	 * Crea/actualiza roles y capacidades.
	 *
	 * @return void
	 */
	public static function add_roles_and_caps() {

		// ── Administrador: acceso total ──────────────────────────────────────
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::get_capabilities() as $cap ) {
				$admin->add_cap( $cap, true );
			}
			foreach ( self::get_course_caps() as $cap ) {
				$admin->add_cap( $cap, true );
			}
			foreach ( self::get_lesson_caps() as $cap ) {
				$admin->add_cap( $cap, true );
			}
			foreach ( self::get_teacher_caps() as $cap ) {
				$admin->add_cap( $cap, true );
			}
			foreach ( self::get_secondary_cpt_caps() as $caps ) {
				foreach ( $caps as $cap ) {
					$admin->add_cap( $cap, true );
				}
			}
		}

		// ── Shop Manager: solo comercio ──────────────────────────────────────
		$shop_manager = get_role( 'shop_manager' );
		if ( $shop_manager ) {
			$shop_manager->add_cap( 'clms_manage_commerce', true );
		}

		// ── Instructor: gestiona sus propios contenidos ──────────────────────
		// map_meta_cap=true hace que WordPress llame al filtro map_meta_cap
		// para edit_lm_course/edit_lm_lesson. Al NO tener edit_others_lm_courses
		// el instructor sólo puede editar posts de los que es author.
		$instructor_caps = array(
			// WordPress base
			'read'                        => true,
			'upload_files'                => true,
			// LMS generales
			'clms_access_admin'           => true,
			'clms_manage_courses'         => true,
			'clms_manage_lessons'         => true,
			'clms_manage_submissions'     => true,
			'clms_grade_submissions'      => true,
			'clms_view_teacher_dashboard' => true,
			'clms_manage_enrollments'     => true,
			'clms_manage_course_access'   => true,
			'clms_manage_enrollment_access' => true,
			// CPT lm_course (propios)
			'create_lm_courses'           => true,
			'edit_lm_courses'             => true,
			'edit_published_lm_courses'   => true,
			'publish_lm_courses'          => true,
			'delete_lm_courses'           => true,
			'delete_published_lm_courses' => true,
			// CPT lm_lesson (propios)
			'create_lm_lessons'           => true,
			'edit_lm_lessons'             => true,
			'edit_published_lm_lessons'   => true,
			'publish_lm_lessons'          => true,
			'delete_lm_lessons'           => true,
			'delete_published_lm_lessons' => true,
			// CPT atora_teacher (propios)
			'create_atora_teachers'           => true,
			'edit_atora_teachers'             => true,
			'edit_published_atora_teachers'   => true,
			'publish_atora_teachers'          => true,
			'delete_atora_teachers'           => true,
			'delete_published_atora_teachers' => true,
			// Sin acceso a contenidos de otros instructores
			'edit_others_lm_courses'      => false,
			'edit_others_lm_lessons'      => false,
			'delete_others_lm_courses'    => false,
			'delete_others_lm_lessons'    => false,
			'edit_others_atora_teachers'  => false,
			'delete_others_atora_teachers'=> false,
		);

		if ( ! get_role( 'lms_instructor' ) ) {
			$initial_caps = array_filter( $instructor_caps );
			add_role( 'lms_instructor', 'Instructor', $initial_caps );
		} else {
			$role = get_role( 'lms_instructor' );
			if ( $role ) {
				// Actualizar nombre visible si todavía dice "Instructor LMS".
				global $wp_roles;
				if ( isset( $wp_roles->roles['lms_instructor'] ) && 'Instructor' !== $wp_roles->roles['lms_instructor']['name'] ) {
					$wp_roles->roles['lms_instructor']['name'] = 'Instructor';
					$wp_roles->role_names['lms_instructor']    = 'Instructor';
					update_option( $wp_roles->role_key, $wp_roles->roles );
				}
				foreach ( $instructor_caps as $cap => $grant ) {
					if ( $grant ) {
						$role->add_cap( $cap, true );
					} else {
						$role->remove_cap( $cap );
					}
				}
			}
		}

		// ── Instructor asistente: gestiona contenidos delegados (sin edit_others estático) ──
		$assistant_caps = array(
			'read'                        => true,
			'upload_files'                => true,
			'clms_access_admin'           => true,
			'clms_manage_courses'         => true,
			'clms_manage_lessons'         => true,
			'clms_manage_submissions'     => true,
			'clms_view_teacher_dashboard' => true,
			'create_lm_courses'           => true,
			'edit_lm_courses'             => true,
			'edit_published_lm_courses'   => true,
			'publish_lm_courses'          => true,
			'create_lm_lessons'           => true,
			'edit_lm_lessons'             => true,
			'edit_published_lm_lessons'   => true,
			'publish_lm_lessons'          => true,
			// PROHIBIDO conceder estáticamente:
			'edit_others_lm_courses'      => false,
			'edit_others_lm_lessons'      => false,
			'delete_others_lm_courses'    => false,
			'delete_others_lm_lessons'    => false,
			// Solo por delegación, en runtime:
			'clms_grade_submissions'      => false,
			'clms_manage_enrollments'     => false,
			'clms_manage_course_access'   => false,
			'clms_manage_enrollment_access' => false,
			// Destructivo:
			'delete_lm_courses'           => false,
			'delete_published_lm_courses' => false,
			'delete_lm_lessons'           => false,
			'delete_published_lm_lessons' => false,
		);

		if ( ! get_role( 'lms_instructor_assistant' ) ) {
			add_role( 'lms_instructor_assistant', 'Instructor asistente', array_filter( $assistant_caps ) );
		} else {
			$role = get_role( 'lms_instructor_assistant' );
			if ( $role ) {
				foreach ( $assistant_caps as $cap => $grant ) {
					$grant ? $role->add_cap( $cap, true ) : $role->remove_cap( $cap );
				}
			}
		}

		// ── Estudiante: solo lectura, sin edición ────────────────────────────
		if ( ! get_role( 'lms_student' ) ) {
			add_role(
				'lms_student',
				'Estudiante',
				array( 'read' => true )
			);
		} else {
			// Actualizar nombre visible si todavía dice "Estudiante LMS".
			global $wp_roles;
			if ( isset( $wp_roles->roles['lms_student'] ) && 'Estudiante' !== $wp_roles->roles['lms_student']['name'] ) {
				$wp_roles->roles['lms_student']['name'] = 'Estudiante';
				$wp_roles->role_names['lms_student']    = 'Estudiante';
				update_option( $wp_roles->role_key, $wp_roles->roles );
			}
		}

		// ── CRM Manager: gestión operativa CRM sin necesitar admin WP ────────
		$crm_manager_caps = array(
			'read'                    => true,
			'clms_access_crm_view'    => true,
			'clms_manage_crm'         => true,
			'crm_manage_campaigns'    => true,
			'crm_manage_pipelines'    => true,
			'crm_export_contacts'     => true,
			'crm_send_email'          => true,
			'crm_view_reports'        => true,
		);

		if ( ! get_role( 'crm_manager' ) ) {
			add_role( 'crm_manager', 'CRM Manager', array_filter( $crm_manager_caps ) );
		} else {
			$role = get_role( 'crm_manager' );
			if ( $role ) {
				foreach ( $crm_manager_caps as $cap => $grant ) {
					$grant ? $role->add_cap( $cap, true ) : $role->remove_cap( $cap );
				}
			}
		}

		// ── CRM Operator: agente de ventas con acceso operativo acotado ───────
		$crm_operator_caps = array(
			'read'                    => true,
			'clms_access_crm_view'    => true,
			'crm_manage_pipelines'    => true,
			'crm_send_email'          => true,
			'crm_view_reports'        => true,
			'crm_manage_campaigns'    => false,
			'crm_export_contacts'     => false,
			'clms_manage_crm'         => false,
		);

		if ( ! get_role( 'crm_operator' ) ) {
			add_role( 'crm_operator', 'Operador CRM', array_filter( $crm_operator_caps ) );
		} else {
			$role = get_role( 'crm_operator' );
			if ( $role ) {
				foreach ( $crm_operator_caps as $cap => $grant ) {
					$grant ? $role->add_cap( $cap, true ) : $role->remove_cap( $cap );
				}
			}
		}

		// ── LMS Coordinator: staff académico sin gestión editorial de cursos ──
		$coordinator_caps = array(
			'read'                        => true,
			'clms_view_teacher_dashboard' => true,
			'clms_manage_enrollments'     => true,
			'clms_manage_course_access'   => true,
			'clms_access_crm_view'        => true,
			'crm_view_reports'            => true,
			'clms_manage_courses'         => false,
			'clms_manage_lessons'         => false,
			'clms_manage_crm'             => false,
			'crm_manage_campaigns'        => false,
		);

		if ( ! get_role( 'lms_coordinator' ) ) {
			add_role( 'lms_coordinator', 'Coordinador', array_filter( $coordinator_caps ) );
		} else {
			$role = get_role( 'lms_coordinator' );
			if ( $role ) {
				foreach ( $coordinator_caps as $cap => $grant ) {
					$grant ? $role->add_cap( $cap, true ) : $role->remove_cap( $cap );
				}
			}
		}

		// ── Añadir caps CRM al administrador ──────────────────────────────────
		if ( $admin ) {
			$crm_caps = array(
				'clms_access_crm_view', 'clms_manage_crm', 'crm_manage_campaigns',
				'crm_manage_pipelines', 'crm_export_contacts', 'crm_send_email', 'crm_view_reports',
			);
			foreach ( $crm_caps as $cap ) {
				$admin->add_cap( $cap, true );
			}
		}

		// ── Añadir cap de vista CRM al instructor ─────────────────────────────
		$instructor_role = get_role( 'lms_instructor' );
		if ( $instructor_role ) {
			$instructor_role->add_cap( 'clms_access_crm_view', true );
			$instructor_role->add_cap( 'crm_view_reports', true );
		}

		// ── Limpiar roles huérfanos de otros plugins LMS ─────────────────────
		self::remove_orphan_lms_roles();
	}

	/**
	 * Elimina roles de plugins LMS externos que ya no están activos.
	 * Solo actúa si el plugin origen no está cargado.
	 *
	 * @return void
	 */
	public static function remove_orphan_lms_roles(): void {
		// Roles creados por TutorLMS.
		$tutor_roles = array(
			'tutor_instructor',
			'tutor_preview_student',
			'tutor_guest_student',
		);

		// Solo eliminar si TutorLMS no está activo.
		$tutor_active = defined( 'TUTOR_VERSION' ) || class_exists( 'TUTOR\Tutor' ) || function_exists( 'tutor' );

		if ( ! $tutor_active ) {
			foreach ( $tutor_roles as $role_name ) {
				if ( get_role( $role_name ) ) {
					remove_role( $role_name );
				}
			}
		}

		// Rol "profesor" genérico (huérfano de instalaciones previas).
		// Solo lo eliminamos si no hay usuarios que lo tengan.
		$orphan_roles = array( 'profesor' );
		foreach ( $orphan_roles as $role_name ) {
			if ( ! get_role( $role_name ) ) {
				continue;
			}
			$users_with_role = get_users( array(
				'role'   => $role_name,
				'number' => 1,
				'fields' => 'ids',
			) );
			if ( empty( $users_with_role ) ) {
				remove_role( $role_name );
			}
		}
	}

	/**
	 * Elimina capacidades del plugin.
	 *
	 * No borra roles por seguridad de datos.
	 *
	 * @return void
	 */
	public static function remove_caps() {
		$roles = array( 'administrator', 'shop_manager', 'lms_instructor', 'lms_student', 'crm_manager', 'crm_operator', 'lms_coordinator' );

		$secondary_caps = array();
		foreach ( self::get_secondary_cpt_caps() as $caps ) {
			$secondary_caps = array_merge( $secondary_caps, $caps );
		}

		$all_caps = array_merge(
			self::get_capabilities(),
			self::get_course_caps(),
			self::get_lesson_caps(),
			self::get_teacher_caps(),
			$secondary_caps
		);

		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $all_caps as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	// ── Filtro de roles en el panel de administración ───────────────────────

	/**
	 * Registra el filtro que limpia el dropdown de roles.
	 * Se llama desde CLMS_Loader al inicializar el módulo.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_filter( 'editable_roles', array( static::class, 'filter_editable_roles' ) );
	}

	/**
	 * Deja en el dropdown solo los roles relevantes para ATORA LMS.
	 * Oculta roles de plugins de terceros (TutorLMS, Yoast, etc.) pero
	 * nunca los elimina: solo los filtra de la UI.
	 *
	 * @param array $roles Lista de roles indexada por slug.
	 * @return array
	 */
	public static function filter_editable_roles( array $roles ): array {
		// Roles que siempre mostramos.
		$allowed = array(
			'administrator',   // WP core
			'editor',          // WP core — puede gestionar contenidos
			'author',          // WP core
			'contributor',     // WP core
			'subscriber',      // WP core
			'lms_instructor',   // ATORA — Instructor
			'lms_student',      // ATORA — Estudiante
			'crm_manager',      // ATORA — CRM Manager
			'crm_operator',     // ATORA — Operador CRM
			'lms_coordinator',  // ATORA — Coordinador académico
		);

		// Si WooCommerce está activo, mantener sus roles comerciales.
		if ( class_exists( 'WooCommerce' ) ) {
			$allowed[] = 'shop_manager';
			$allowed[] = 'customer';
		}

		// Filtrar: solo mostrar los roles de la lista blanca que existan.
		return array_intersect_key( $roles, array_flip( $allowed ) );
	}

	// ── Helpers de comprobación ──────────────────────────────────────────────

	public static function can_access_admin() {
		return current_user_can( 'clms_access_admin' ) || current_user_can( 'manage_options' );
	}

	public static function can_manage_courses() {
		return current_user_can( 'clms_manage_courses' ) || current_user_can( 'manage_options' );
	}

	public static function can_manage_lessons() {
		return current_user_can( 'clms_manage_lessons' ) || current_user_can( 'manage_options' );
	}

	public static function can_manage_submissions() {
		return current_user_can( 'clms_manage_submissions' ) || current_user_can( 'manage_options' );
	}

	public static function can_grade_submissions() {
		return current_user_can( 'clms_grade_submissions' ) || current_user_can( 'manage_options' );
	}

	public static function can_view_teacher_dashboard() {
		return current_user_can( 'clms_view_teacher_dashboard' ) || current_user_can( 'manage_options' );
	}

	public static function can_manage_commerce() {
		return current_user_can( 'clms_manage_commerce' ) || current_user_can( 'manage_options' );
	}

	public static function can_manage_enrollments() {
		return current_user_can( 'clms_manage_enrollments' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Capacidad de gestión de accesos de matrícula (invitaciones y links).
	 *
	 * Incluye fallback a cap legacy y a clms_manage_enrollments para
	 * compatibilidad gradual.
	 *
	 * @return bool
	 */
	public static function can_manage_course_access() {
		return current_user_can( 'clms_manage_course_access' )
			|| current_user_can( 'clms_manage_enrollment_access' )
			|| current_user_can( 'clms_manage_enrollments' )
			|| current_user_can( 'manage_options' );
	}

	/**
	 * Alias legacy para compatibilidad con código existente.
	 *
	 * @return bool
	 */
	public static function can_manage_enrollment_access() {
		return self::can_manage_course_access();
	}

	/**
	 * Comprueba si el usuario actual puede gestionar matrículas de un curso concreto.
	 *
	 * Administradores → siempre permitido.
	 * Instructores    → solo si son autores del curso (o $course_id === 0 para
	 *                   operaciones no ligadas a un curso específico).
	 * Resto           → denegado.
	 *
	 * @param int $course_id  ID del curso. 0 = comprobación global sin curso específico.
	 * @return bool
	 */
	public static function can_manage_course_enrollment( int $course_id = 0 ): bool {
		return self::can_manage_course_context( $course_id, 'enrollment' );
	}

	/**
	 * Comprueba si el usuario actual puede gestionar accesos de matrícula
	 * (invitaciones / enlaces) en el contexto de un curso.
	 *
	 * @param int $course_id ID del curso. 0 = comprobación global.
	 * @return bool
	 */
	public static function can_manage_course_enrollment_access( int $course_id = 0 ): bool {
		return self::can_manage_course_context( $course_id, 'access' );
	}

	/**
	 * Helper contextual reusable para autorización por curso.
	 *
	 * @param int    $course_id ID del curso. 0 = comprobación global.
	 * @param string $scope     enrollment|access.
	 * @return bool
	 */
	public static function can_manage_course_context( int $course_id = 0, string $scope = 'enrollment' ): bool {
		return self::can_manage_resource_context( $course_id, $scope, 'course' );
	}

	/**
	 * Helper contextual reusable para autorización por recurso.
	 *
	 * @param int    $resource_id   ID del recurso. 0 = comprobación global.
	 * @param string $scope         enrollment|access.
	 * @param string $resource_type course|program|teacher.
	 * @return bool
	 */
	public static function can_manage_resource_context( int $resource_id = 0, string $scope = 'enrollment', string $resource_type = 'course' ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$scope = ( 'access' === $scope ) ? 'access' : 'enrollment';
		$has_cap = ( 'access' === $scope )
			? self::can_manage_course_access()
			: self::can_manage_enrollments();

		if ( ! $has_cap ) {
			return false;
		}

		// Sin recurso concreto → la cap es suficiente.
		if ( 0 === $resource_id ) {
			return true;
		}

		$post_type = self::resource_post_type( $resource_type );
		if ( '' === $post_type ) {
			return false;
		}

		$post = get_post( $resource_id );
		if ( ! $post || $post_type !== $post->post_type ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		if ( (int) $post->post_author === $user_id ) {
			return true;
		}

		$edit_others_cap = self::resource_edit_others_cap( $resource_type );
		if ( '' !== $edit_others_cap && current_user_can( $edit_others_cap ) ) {
			return true;
		}

		// E-10: delegación como tercera vía (solo cursos/lecciones).
		if ( class_exists( 'ATORA_Delegation_Service' ) && in_array( $resource_type, array( 'course', 'program' ), true ) ) {
			$perm = ( 'access' === $scope ) ? 'access' : 'enroll';
			return ATORA_Delegation_Service::covers( $user_id, $post, $perm );
		}

		return false;
	}

	/**
	 * Tipo de post esperado para una comprobación contextual.
	 *
	 * @param string $resource_type course|program|teacher.
	 * @return string
	 */
	private static function resource_post_type( string $resource_type ): string {
		$resource_type = sanitize_key( $resource_type );

		if ( 'course' === $resource_type ) {
			return 'lm_course';
		}
		if ( 'program' === $resource_type ) {
			return 'lm_program';
		}
		if ( 'teacher' === $resource_type ) {
			return 'atora_teacher';
		}

		return '';
	}

	/**
	 * Cap de edición de terceros por tipo de recurso.
	 *
	 * @param string $resource_type course|program|teacher.
	 * @return string
	 */
	private static function resource_edit_others_cap( string $resource_type ): string {
		$resource_type = sanitize_key( $resource_type );

		if ( 'course' === $resource_type || 'program' === $resource_type ) {
			return 'edit_others_lm_courses';
		}
		if ( 'teacher' === $resource_type ) {
			return 'edit_others_atora_teachers';
		}

		return '';
	}

	/**
	 * Devuelve el mensaje de error estándar para operaciones de matrícula denegadas.
	 * Usar en wp_send_json_error para homogeneidad.
	 */
	public static function enrollment_permission_error(): array {
		return array( 'message' => __( 'No tienes permiso para gestionar matrículas o accesos en este curso.', 'atora-lms' ) );
	}

	/**
	 * Mensaje de error estándar cuando el contexto del curso es inválido.
	 *
	 * @return array
	 */
	public static function enrollment_context_error(): array {
		return array( 'message' => __( 'Curso no válido para esta operación.', 'atora-lms' ) );
	}

	// ── Helpers CRM granulares (Fase 5) ─────────────────────────────────────

	public static function can_access_crm_view(): bool {
		return current_user_can( 'clms_access_crm_view' )
			|| current_user_can( 'clms_manage_crm' )
			|| current_user_can( 'manage_options' );
	}

	public static function can_manage_crm(): bool {
		return current_user_can( 'clms_manage_crm' ) || current_user_can( 'manage_options' );
	}

	public static function can_manage_crm_campaigns(): bool {
		return current_user_can( 'crm_manage_campaigns' ) || current_user_can( 'clms_manage_crm' ) || current_user_can( 'manage_options' );
	}

	public static function can_manage_crm_pipelines(): bool {
		return current_user_can( 'crm_manage_pipelines' ) || current_user_can( 'clms_manage_crm' ) || current_user_can( 'manage_options' );
	}

	public static function can_export_contacts(): bool {
		return current_user_can( 'crm_export_contacts' ) || current_user_can( 'clms_manage_crm' ) || current_user_can( 'manage_options' );
	}

	public static function can_send_crm_email(): bool {
		return current_user_can( 'crm_send_email' ) || current_user_can( 'clms_manage_crm' ) || current_user_can( 'manage_options' );
	}

	public static function can_view_crm_reports(): bool {
		return current_user_can( 'crm_view_reports' ) || current_user_can( 'clms_manage_crm' ) || current_user_can( 'manage_options' );
	}
}
