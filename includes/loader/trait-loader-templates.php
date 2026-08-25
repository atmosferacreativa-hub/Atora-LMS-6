<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Loader_Templates_Trait {
	protected function load_ui_module(): void {
		$ui_files = array(
			'includes/ui/class-ui-template-migrator.php',
			'includes/ui/class-ui-schema-repository.php',
			'includes/ui/class-ui-section-registry.php',
			'includes/ui/class-ui-template-context.php',
			'includes/ui/class-ui-template-engine.php',
			'includes/ui/class-ui-template-resolver.php',
			'includes/ui/class-ui-template-presets.php',
			'includes/ui/class-ui-admin-builder.php',
			'includes/ui/class-ui-crm-lead-section.php',
			'includes/ui/class-ui-course-commercial-sections.php',
			'includes/ui/class-ui-course-overview-sections.php',
			'includes/ui/class-ui-program-commercial-sections.php',
			'includes/ui/class-ui-lesson-sections.php',
			'includes/ui/class-ui-teacher-sections.php',
			'includes/ui/class-ui-template-parts.php',
			'includes/ui/class-ui-blocks.php',
			'includes/ui/class-ui-shortcode-blocks.php',
			'includes/ui/class-ui-editor-templates.php',
			// PT-1 (6.9.0) — componentes compartidos de lista+panel.
			'includes/ui/class-ui-list-row.php',
			'includes/ui/class-ui-followup-panel.php',
			'includes/ui/class-ui-search-service.php',
		);

		foreach ( $ui_files as $file ) {
			$path = $this->resolve_file_path( $file );
			if ( $path ) {
				require_once $path;
			}
		}

		// Instanciar las clases que otros módulos y templates consultarán.
		$instantiable = array(
			'CLMS_UI_Schema_Repository',
			'CLMS_UI_Section_Registry',
			'CLMS_UI_Template_Engine',
			'CLMS_UI_Template_Resolver',
			'CLMS_UI_Template_Presets',
			'CLMS_UI_Template_Parts',
			// Migrator y Context son de uso estático / factory — no se instancian aquí.
			// Builder solo en admin: registra metaboxes y hooks de guardado.
			'CLMS_UI_Admin_Builder',
			// Blocks: registra bloques Gutenberg dinámicos en hook 'init'.
			'CLMS_UI_Blocks',
			'CLMS_UI_Shortcode_Blocks',
			'CLMS_UI_Editor_Templates',
			// PT-2 (6.9.0): registra su propia ruta REST en el constructor
			// (rest_api_init) -- necesita instanciarse, no es estática
			// como List_Row/Followup_Panel.
			'CLMS_UI_Search_Service',
		);

		foreach ( $instantiable as $class ) {
			if ( class_exists( $class, false ) && ! isset( $this->instances[ $class ] ) ) {
				try {
					$this->instances[ $class ] = new $class();
				} catch ( Throwable $e ) {
					$this->log( 'UI module: error al iniciar ' . $class . ': ' . $e->getMessage() );
				}
			}
		}

		if ( class_exists( 'CLMS_UI_Course_Commercial_Sections', false ) ) {
			CLMS_UI_Course_Commercial_Sections::register_callbacks();
		}

		if ( class_exists( 'CLMS_UI_Course_Overview_Sections', false ) ) {
			CLMS_UI_Course_Overview_Sections::register_callbacks();
		}

		if ( class_exists( 'CLMS_UI_Lesson_Sections', false ) ) {
			CLMS_UI_Lesson_Sections::register_callbacks();
		}

		if ( class_exists( 'CLMS_UI_Teacher_Sections', false ) ) {
			CLMS_UI_Teacher_Sections::register_callbacks();
		}

		// Se registra al final para capturar callbacks fallback más recientes
		// (incluyendo "progress" de lesson) y despachar por contexto de programa.
		if ( class_exists( 'CLMS_UI_Program_Commercial_Sections', false ) ) {
			CLMS_UI_Program_Commercial_Sections::register_callbacks();
		}

		do_action( 'clms_ui_loaded', $this );
	}

	protected function register_template_hooks() {
		add_filter( 'template_include', array( $this, 'maybe_load_single_course_template' ), 20 );
		add_filter( 'template_include', array( $this, 'maybe_load_single_program_template' ), 20 );
		add_filter( 'template_include', array( $this, 'maybe_load_single_lesson_template' ), 20 );
		add_filter( 'template_include', array( $this, 'maybe_load_single_product_template' ), 20 );
		add_filter( 'template_include', array( $this, 'capture_active_lesson_template' ), 99 );
		add_filter( 'archive_template', array( $this, 'maybe_load_course_archive_template' ), 20 );
		add_action( 'admin_notices', array( $this, 'maybe_show_lesson_template_notice' ) );
		add_action( 'template_redirect', array( $this, 'gate_lesson_access' ), 1 );
	}

	public function maybe_load_single_course_template( $template ) {
		if ( ! is_singular( 'lm_course' ) ) {
			return $template;
		}

		$course_id = get_queried_object_id();
		$forced_template = $this->get_forced_template_preview( 'course' );
		if ( 'commercial' === $forced_template ) {
			$commercial = $this->resolve_file_path( 'templates/single-course-commercial.php' );
			if ( $commercial ) {
				return $commercial;
			}
		}
		if ( 'overview' === $forced_template ) {
			$custom = $this->resolve_file_path( 'templates/single-course.php' );
			return $custom ? $custom : $template;
		}

		$user_id   = get_current_user_id();
		$is_admin  = class_exists( 'CLMS_Access' )
			? CLMS_Access::can_manage_courses()
			: ( current_user_can( 'manage_options' ) || current_user_can( 'clms_manage_courses' ) );
		$enrolled  = $user_id && ! $is_admin && class_exists( 'CLMS_Helper' )
			? CLMS_Helper::user_is_enrolled_in_course( $user_id, $course_id )
			: false;

		// Mostrar la landing comercial para cualquier usuario no inscrito (incluye admins).
		// Alumnos inscritos siempre ven single-course.php.
		$mode = $course_id ? (string) get_post_meta( $course_id, '_clms_commercial_mode', true ) : '';

		$is_builder_preview = $this->is_builder_preview_request( 'course', $course_id );
		$preview_flag = false;
		if ( isset( $_GET['clms_preview_commercial'] ) ) {
			$preview_flag = '1' === sanitize_text_field( wp_unslash( $_GET['clms_preview_commercial'] ) );
		}

		if ( 'commercial' === $mode && ( $is_builder_preview || $preview_flag || ! $enrolled ) ) {
			$commercial = $this->resolve_file_path( 'templates/single-course-commercial.php' );
			if ( $commercial ) {
				return $commercial;
			}
		}

		$custom = $this->resolve_file_path( 'templates/single-course.php' );

		return $custom ? $custom : $template;
	}

	public function maybe_load_single_program_template( $template ) {
		if ( ! is_singular( 'lm_program' ) ) {
			return $template;
		}

		$program_id = get_queried_object_id();
		$forced_template = $this->get_forced_template_preview( 'program' );
		if ( 'commercial' === $forced_template ) {
			$commercial = $this->resolve_file_path( 'templates/single-program-commercial.php' );
			if ( $commercial ) {
				return $commercial;
			}
		}
		if ( 'overview' === $forced_template ) {
			$custom = $this->resolve_file_path( 'templates/single-program.php' );
			return $custom ? $custom : $template;
		}

		$mode       = $program_id ? (string) get_post_meta( $program_id, '_clms_commercial_mode', true ) : '';

		if ( 'commercial' === $mode ) {
			$commercial = $this->resolve_file_path( 'templates/single-program-commercial.php' );
			if ( $commercial ) {
				return $commercial;
			}
		}

		$custom = $this->resolve_file_path( 'templates/single-program.php' );

		return $custom ? $custom : $template;
	}

	/**
	 * Gate de acceso a lecciones: impide que usuarios sin matrícula vean el
	 * contenido por URL directa. Admin/docente y drip se resuelven dentro de
	 * CLMS_Helper::user_can_access_lesson().
	 *
	 * @return void
	 */
	public function gate_lesson_access() {
		if ( ! is_singular( 'lm_lesson' ) || ! class_exists( 'CLMS_Helper' ) ) {
			return;
		}

		$lesson_id = absint( get_queried_object_id() );
		if ( ! $lesson_id ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( $user_id && CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
			return; // Inscrito, o admin/docente, o lección liberada por drip.
		}

		// Sin acceso: redirigir al curso (que muestra la landing comercial a no
		// inscritos) o al login si no hay sesión.
		$course_id = method_exists( 'CLMS_Helper', 'get_course_id_from_lesson' )
			? absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) )
			: 0;

		if ( $course_id ) {
			$redirect = get_permalink( $course_id );
		} elseif ( ! $user_id ) {
			$redirect = wp_login_url( get_permalink( $lesson_id ) );
		} else {
			$redirect = home_url( '/' );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	public function maybe_load_single_lesson_template( $template ) {
		if ( ! is_singular( 'lm_lesson' ) ) {
			return $template;
		}

		$custom = $this->resolve_file_path( 'templates/single-lesson.php' );

		return $custom ? $custom : $template;
	}

	public function maybe_load_single_product_template( $template ) {
		if ( ! is_singular( 'product' ) ) {
			return $template;
		}

		$product_id = absint( get_queried_object_id() );
		if ( ! $product_id ) {
			return $template;
		}

		$map = array();
		if ( class_exists( 'CLMS_WooCommerce' ) && method_exists( 'CLMS_WooCommerce', 'get_product_access_map' ) ) {
			$map = CLMS_WooCommerce::get_product_access_map( $product_id );
		}

		$is_builder_preview = $this->is_builder_preview_request( 'product', $product_id );
		// Por defecto, dejamos la tienda con el template nativo de WooCommerce.
		// Si se requiere un template ATORA para productos vinculados al LMS,
		// puede habilitarse explícitamente via filtro.
		$enabled_default    = false;
		$enabled            = (bool) apply_filters( 'clms_enable_single_product_template', $enabled_default, $product_id, $map, $is_builder_preview );
		if ( ! $enabled ) {
			return $template;
		}

		$custom = $this->resolve_file_path( 'templates/single-product-atora.php' );

		return $custom ? $custom : $template;
	}

	protected function is_builder_preview_request( string $post_type = '', int $post_id = 0 ): bool {
		$is_preview = function_exists( 'atora_lms_is_builder_preview_request' )
			? atora_lms_is_builder_preview_request( $post_type, $post_id )
			: false;

		/**
		 * Permite extender detección de contexto builder/preview.
		 *
		 * @param bool   $is_preview Estado detectado.
		 * @param string $post_type  Tipo de post actual.
		 * @param int    $post_id    ID del post actual.
		 */
		return (bool) apply_filters( 'clms_is_builder_preview_request', $is_preview, $post_type, $post_id );
	}

	protected function get_forced_template_preview( string $entity_type ): string {
		$entity_type = sanitize_key( $entity_type );
		if ( ! in_array( $entity_type, array( 'course', 'program' ), true ) ) {
			return '';
		}

		$preview_template = isset( $_GET['clms_preview_template'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( (string) wp_unslash( $_GET['clms_preview_template'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		if ( in_array( $preview_template, array( 'commercial', 'overview' ), true ) ) {
			return $preview_template;
		}

		if ( isset( $_GET['clms_preview_commercial'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$is_legacy_commercial = '1' === sanitize_text_field( wp_unslash( $_GET['clms_preview_commercial'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $is_legacy_commercial ) {
				return 'commercial';
			}
		}

		return '';
	}

	public function capture_active_lesson_template( $template ) {
		if ( ! is_singular( 'lm_lesson' ) ) {
			return $template;
		}

		if ( ! is_user_logged_in() ) {
			return $template;
		}

		$can_manage_courses = class_exists( 'CLMS_Access' )
			? CLMS_Access::can_manage_courses()
			: ( current_user_can( 'manage_options' ) || current_user_can( 'clms_manage_courses' ) );
		if ( ! $can_manage_courses ) {
			return $template;
		}

		set_transient(
			'clms_active_lesson_template',
			array(
				'template' => (string) $template,
				'updated'  => time(),
			),
			DAY_IN_SECONDS
		);

		return $template;
	}

	public function maybe_show_lesson_template_notice() {
		if ( ! is_admin() ) {
			return;
		}
		$can_manage_courses = class_exists( 'CLMS_Access' )
			? CLMS_Access::can_manage_courses()
			: ( current_user_can( 'manage_options' ) || current_user_can( 'clms_manage_courses' ) );
		if ( ! $can_manage_courses ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'lm_lesson' !== $screen->post_type ) {
			return;
		}

		$expected = $this->resolve_file_path( 'templates/single-lesson.php' );
		if ( ! $expected ) {
			return;
		}

		$info = get_transient( 'clms_active_lesson_template' );
		if ( empty( $info['template'] ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'ATORA aún no ha verificado qué plantilla de lección está activa. Abre una lección en la vista pública para confirmar que se usa la plantilla del plugin.', 'atora-lms' ) . '</p></div>';
			return;
		}

		$expected_realpath = realpath( $expected );
		$expected_path     = is_string( $expected_realpath ) ? wp_normalize_path( $expected_realpath ) : '';

		$active_template_raw = $info['template'] ?? '';
		$active_template     = is_string( $active_template_raw ) ? $active_template_raw : '';
		$active_realpath     = '' !== $active_template ? realpath( $active_template ) : false;
		$active_path         = is_string( $active_realpath ) ? wp_normalize_path( $active_realpath ) : '';

		if ( $expected_path && $active_path && $expected_path !== $active_path ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'La plantilla activa de lecciones no coincide con la de ATORA. Esto puede ocultar mejoras de UI en nuevos sitios.', 'atora-lms' ) .
				'</p><p>' .
				sprintf(
					/* translators: 1: expected template path, 2: active template path */
					esc_html__( 'Esperada: %1$s | Activa: %2$s', 'atora-lms' ),
					esc_html( $expected_path ),
					esc_html( $active_path )
				) .
				'</p></div>';
		}
	}

	public function maybe_load_course_archive_template( $template ) {
		if ( ! is_post_type_archive( 'lm_course' ) ) {
			return $template;
		}

		$custom = $this->resolve_file_path( 'templates/archive-course.php' );

		return $custom ? $custom : $template;
	}

	protected function log( $message ) {
		do_action(
			'clms_system_log',
			array(
				'severity' => 'warning',
				'message'  => (string) $message,
				'source'   => 'loader',
			)
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[CLMS_LOADER] ' . (string) $message );
		}
	}
}
