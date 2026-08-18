<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_CPT {

	public function __construct() {
		add_action( 'init', array( $this, 'register_post_types' ), 5 );
		add_action( 'init', array( $this, 'register_taxonomies' ), 6 );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_block_editor_for_lms_types' ), 10, 2 );
		add_filter( 'manage_lm_course_posts_columns', array( $this, 'filter_course_admin_columns' ) );
		add_action( 'manage_lm_course_posts_custom_column', array( $this, 'render_course_admin_column' ), 10, 2 );
		add_filter( 'manage_lm_program_posts_columns', array( $this, 'filter_program_admin_columns' ) );
		add_action( 'manage_lm_program_posts_custom_column', array( $this, 'render_program_admin_column' ), 10, 2 );
		add_filter( 'manage_lm_cohort_posts_columns', array( $this, 'filter_cohort_admin_columns' ) );
		add_action( 'manage_lm_cohort_posts_custom_column', array( $this, 'render_cohort_admin_column' ), 10, 2 );
		add_filter( 'manage_lm_lesson_posts_columns', array( $this, 'filter_lesson_admin_columns' ) );
		add_action( 'manage_lm_lesson_posts_custom_column', array( $this, 'render_lesson_admin_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'filter_admin_row_actions' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'render_lesson_admin_filters' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_lesson_admin_query' ) );
	}

	public function register_post_types() {
		$this->register_course_post_type();
		$this->register_program_post_type();
		$this->register_cohort_post_type();
		$this->register_teacher_post_type();
		$this->register_lesson_post_type();
	}

	public function register_taxonomies() {
		$labels = array(
			'name'              => __( 'Niveles', 'atora-lms' ),
			'singular_name'     => __( 'Nivel', 'atora-lms' ),
			'search_items'      => __( 'Buscar niveles', 'atora-lms' ),
			'all_items'         => __( 'Todos los niveles', 'atora-lms' ),
			'edit_item'         => __( 'Editar nivel', 'atora-lms' ),
			'update_item'       => __( 'Actualizar nivel', 'atora-lms' ),
			'add_new_item'      => __( 'Añadir nuevo nivel', 'atora-lms' ),
			'new_item_name'     => __( 'Nuevo nivel', 'atora-lms' ),
			'menu_name'         => __( 'Niveles', 'atora-lms' ),
		);

		register_taxonomy(
			'lm_course_level',
			array( 'lm_course' ),
			array(
				'labels'            => $labels,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'rewrite'           => array(
					'slug'       => 'nivel-curso',
					'with_front' => false,
				),
			)
		);
	}

	protected function register_course_post_type() {
		$labels = array(
			'name'                  => __( 'Cursos', 'atora-lms' ),
			'singular_name'         => __( 'Curso', 'atora-lms' ),
			'menu_name'             => __( 'Cursos', 'atora-lms' ),
			'name_admin_bar'        => __( 'Curso', 'atora-lms' ),
			'add_new'               => __( 'Añadir nuevo', 'atora-lms' ),
			'add_new_item'          => __( 'Añadir nuevo curso', 'atora-lms' ),
			'edit_item'             => __( 'Editar curso', 'atora-lms' ),
			'new_item'              => __( 'Nuevo curso', 'atora-lms' ),
			'view_item'             => __( 'Ver curso', 'atora-lms' ),
			'view_items'            => __( 'Ver cursos', 'atora-lms' ),
			'search_items'          => __( 'Buscar cursos', 'atora-lms' ),
			'not_found'             => __( 'No se encontraron cursos', 'atora-lms' ),
			'not_found_in_trash'    => __( 'No se encontraron cursos en la papelera', 'atora-lms' ),
			'all_items'             => __( 'Todos los cursos', 'atora-lms' ),
			'archives'              => __( 'Archivo de cursos', 'atora-lms' ),
			'attributes'            => __( 'Atributos del curso', 'atora-lms' ),
			'featured_image'        => __( 'Imagen destacada', 'atora-lms' ),
			'set_featured_image'    => __( 'Asignar imagen destacada', 'atora-lms' ),
			'remove_featured_image' => __( 'Quitar imagen destacada', 'atora-lms' ),
			'use_featured_image'    => __( 'Usar como imagen destacada', 'atora-lms' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => 'clms-dashboard',
			'show_in_nav_menus'   => true,
			'show_in_admin_bar'   => true,
			'show_in_rest'        => true,
			'menu_position'       => null,
			'menu_icon'           => 'dashicons-welcome-learn-more',
			'has_archive'         => 'cursos',
			'rewrite'             => array(
				'slug'       => 'cursos',
				'with_front' => false,
			),
			'supports'            => array(
				'title',
				'editor',
				'excerpt',
				'thumbnail',
				'author',
				'page-attributes',
			),
			'capability_type'     => array( 'lm_course', 'lm_courses' ),
			'map_meta_cap'        => true,
			'capabilities'        => array(
				'edit_post'              => 'edit_lm_course',
				'read_post'              => 'read_lm_course',
				'delete_post'            => 'delete_lm_course',
				'edit_posts'             => 'edit_lm_courses',
				'edit_others_posts'      => 'edit_others_lm_courses',
				'publish_posts'          => 'publish_lm_courses',
				'read_private_posts'     => 'read_private_lm_courses',
				'delete_posts'           => 'delete_lm_courses',
				'delete_private_posts'   => 'delete_private_lm_courses',
				'delete_published_posts' => 'delete_published_lm_courses',
				'delete_others_posts'    => 'delete_others_lm_courses',
				'edit_private_posts'     => 'edit_private_lm_courses',
				'edit_published_posts'   => 'edit_published_lm_courses',
				'create_posts'           => 'create_lm_courses',
			),
			'hierarchical'        => false,
			'exclude_from_search' => false,
			'query_var'           => true,
			'can_export'          => true,
			'delete_with_user'    => false,
		);

		register_post_type( 'lm_course', $args );
	}

	protected function register_program_post_type() {
		$labels = array(
			'name'                  => __( 'Programas', 'atora-lms' ),
			'singular_name'         => __( 'Programa', 'atora-lms' ),
			'menu_name'             => __( 'Programas', 'atora-lms' ),
			'name_admin_bar'        => __( 'Programa', 'atora-lms' ),
			'add_new'               => __( 'Añadir nuevo', 'atora-lms' ),
			'add_new_item'          => __( 'Añadir nuevo programa', 'atora-lms' ),
			'edit_item'             => __( 'Editar programa', 'atora-lms' ),
			'new_item'              => __( 'Nuevo programa', 'atora-lms' ),
			'view_item'             => __( 'Ver programa', 'atora-lms' ),
			'view_items'            => __( 'Ver programas', 'atora-lms' ),
			'search_items'          => __( 'Buscar programas', 'atora-lms' ),
			'not_found'             => __( 'No se encontraron programas', 'atora-lms' ),
			'not_found_in_trash'    => __( 'No se encontraron programas en la papelera', 'atora-lms' ),
			'all_items'             => __( 'Todos los programas', 'atora-lms' ),
			'archives'              => __( 'Archivo de programas', 'atora-lms' ),
			'attributes'            => __( 'Atributos del programa', 'atora-lms' ),
			'featured_image'        => __( 'Imagen destacada', 'atora-lms' ),
			'set_featured_image'    => __( 'Asignar imagen destacada', 'atora-lms' ),
			'remove_featured_image' => __( 'Quitar imagen destacada', 'atora-lms' ),
			'use_featured_image'    => __( 'Usar como imagen destacada', 'atora-lms' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => 'clms-dashboard',
			'show_in_nav_menus'   => true,
			'show_in_admin_bar'   => true,
			'show_in_rest'        => true,
			'menu_position'       => null,
			'menu_icon'           => 'dashicons-welcome-learn-more',
			'has_archive'         => 'programas',
			'rewrite'             => array(
				'slug'       => 'programas',
				'with_front' => false,
			),
			'supports'            => array(
				'title',
				'editor',
				'excerpt',
				'thumbnail',
				'author',
				'page-attributes',
			),
			'capability_type'     => array( 'lm_course', 'lm_courses' ),
			'map_meta_cap'        => true,
			'capabilities'        => array(
				'edit_post'              => 'edit_lm_course',
				'read_post'              => 'read_lm_course',
				'delete_post'            => 'delete_lm_course',
				'edit_posts'             => 'edit_lm_courses',
				'edit_others_posts'      => 'edit_others_lm_courses',
				'publish_posts'          => 'publish_lm_courses',
				'read_private_posts'     => 'read_private_lm_courses',
				'delete_posts'           => 'delete_lm_courses',
				'delete_private_posts'   => 'delete_private_lm_courses',
				'delete_published_posts' => 'delete_published_lm_courses',
				'delete_others_posts'    => 'delete_others_lm_courses',
				'edit_private_posts'     => 'edit_private_lm_courses',
				'edit_published_posts'   => 'edit_published_lm_courses',
				'create_posts'           => 'create_lm_courses',
			),
			'hierarchical'        => false,
			'exclude_from_search' => false,
			'query_var'           => true,
			'can_export'          => true,
			'delete_with_user'    => false,
		);

		register_post_type( 'lm_program', $args );
	}

	/**
	 * Registra CPT de cohortes/grupos.
	 *
	 * @return void
	 */
	protected function register_cohort_post_type() {
		$labels = array(
			'name'                  => __( 'Cohortes', 'atora-lms' ),
			'singular_name'         => __( 'Cohorte', 'atora-lms' ),
			'menu_name'             => __( 'Cohortes', 'atora-lms' ),
			'name_admin_bar'        => __( 'Cohorte', 'atora-lms' ),
			'add_new'               => __( 'Añadir nueva', 'atora-lms' ),
			'add_new_item'          => __( 'Añadir nueva cohorte', 'atora-lms' ),
			'edit_item'             => __( 'Editar cohorte', 'atora-lms' ),
			'new_item'              => __( 'Nueva cohorte', 'atora-lms' ),
			'view_item'             => __( 'Ver cohorte', 'atora-lms' ),
			'view_items'            => __( 'Ver cohortes', 'atora-lms' ),
			'search_items'          => __( 'Buscar cohortes', 'atora-lms' ),
			'not_found'             => __( 'No se encontraron cohortes', 'atora-lms' ),
			'not_found_in_trash'    => __( 'No se encontraron cohortes en la papelera', 'atora-lms' ),
			'all_items'             => __( 'Todas las cohortes', 'atora-lms' ),
			'archives'              => __( 'Archivo de cohortes', 'atora-lms' ),
			'attributes'            => __( 'Atributos de cohorte', 'atora-lms' ),
			'featured_image'        => __( 'Imagen destacada', 'atora-lms' ),
			'set_featured_image'    => __( 'Asignar imagen destacada', 'atora-lms' ),
			'remove_featured_image' => __( 'Quitar imagen destacada', 'atora-lms' ),
			'use_featured_image'    => __( 'Usar como imagen destacada', 'atora-lms' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_menu'        => 'clms-dashboard',
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => true,
			'show_in_rest'        => true,
			'menu_position'       => null,
			'menu_icon'           => 'dashicons-groups',
			'has_archive'         => false,
			'rewrite'             => array(
				'slug'       => 'cohortes',
				'with_front' => false,
			),
			'supports'            => array( 'title', 'editor', 'author' ),
			'capability_type'     => 'post', // Usa caps estándar: edit_posts, edit_others_posts, etc.
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'exclude_from_search' => true,
			'query_var'           => true,
			'can_export'          => true,
			'delete_with_user'    => false,
		);

		register_post_type( 'lm_cohort', $args );
	}

	protected function register_teacher_post_type() {
		$labels = array(
			'name'                  => __( 'Docentes', 'atora-lms' ),
			'singular_name'         => __( 'Docente', 'atora-lms' ),
			'menu_name'             => __( 'Docentes', 'atora-lms' ),
			'name_admin_bar'        => __( 'Docente', 'atora-lms' ),
			'add_new'               => __( 'Añadir nuevo', 'atora-lms' ),
			'add_new_item'          => __( 'Añadir nuevo docente', 'atora-lms' ),
			'edit_item'             => __( 'Editar docente', 'atora-lms' ),
			'new_item'              => __( 'Nuevo docente', 'atora-lms' ),
			'view_item'             => __( 'Ver docente', 'atora-lms' ),
			'view_items'            => __( 'Ver docentes', 'atora-lms' ),
			'search_items'          => __( 'Buscar docentes', 'atora-lms' ),
			'not_found'             => __( 'No se encontraron docentes', 'atora-lms' ),
			'not_found_in_trash'    => __( 'No se encontraron docentes en la papelera', 'atora-lms' ),
			'all_items'             => __( 'Todos los docentes', 'atora-lms' ),
			'archives'              => __( 'Archivo de docentes', 'atora-lms' ),
			'attributes'            => __( 'Atributos del docente', 'atora-lms' ),
			'featured_image'        => __( 'Foto del docente', 'atora-lms' ),
			'set_featured_image'    => __( 'Asignar foto', 'atora-lms' ),
			'remove_featured_image' => __( 'Quitar foto', 'atora-lms' ),
			'use_featured_image'    => __( 'Usar como foto', 'atora-lms' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => 'clms-dashboard',
			'show_in_nav_menus'   => true,
			'show_in_admin_bar'   => true,
			'show_in_rest'        => true,
			'menu_position'       => null,
			'menu_icon'           => 'dashicons-id',
			'has_archive'         => 'docentes-atora',
			'rewrite'             => array(
				'slug'       => 'docentes-atora',
				'with_front' => false,
			),
			'supports'            => array(
				'title',
				'editor',
				'excerpt',
				'thumbnail',
			),
			'capability_type'     => array( 'atora_teacher', 'atora_teachers' ),
			'map_meta_cap'        => true,
			'capabilities'        => array(
				'edit_post'              => 'edit_atora_teacher',
				'read_post'              => 'read_atora_teacher',
				'delete_post'            => 'delete_atora_teacher',
				'edit_posts'             => 'edit_atora_teachers',
				'edit_others_posts'      => 'edit_others_atora_teachers',
				'publish_posts'          => 'publish_atora_teachers',
				'read_private_posts'     => 'read_private_atora_teachers',
				'delete_posts'           => 'delete_atora_teachers',
				'delete_private_posts'   => 'delete_private_atora_teachers',
				'delete_published_posts' => 'delete_published_atora_teachers',
				'delete_others_posts'    => 'delete_others_atora_teachers',
				'edit_private_posts'     => 'edit_private_atora_teachers',
				'edit_published_posts'   => 'edit_published_atora_teachers',
				'create_posts'           => 'create_atora_teachers',
			),
			'hierarchical'        => false,
			'exclude_from_search' => false,
			'query_var'           => true,
			'can_export'          => true,
		);

		register_post_type( 'atora_teacher', $args );
	}

	protected function register_lesson_post_type() {
		$labels = array(
			'name'                  => __( 'Lecciones', 'atora-lms' ),
			'singular_name'         => __( 'Lección', 'atora-lms' ),
			'menu_name'             => __( 'Lecciones', 'atora-lms' ),
			'name_admin_bar'        => __( 'Lección', 'atora-lms' ),
			'add_new'               => __( 'Añadir nueva', 'atora-lms' ),
			'add_new_item'          => __( 'Añadir nueva lección', 'atora-lms' ),
			'edit_item'             => __( 'Editar lección', 'atora-lms' ),
			'new_item'              => __( 'Nueva lección', 'atora-lms' ),
			'view_item'             => __( 'Ver lección', 'atora-lms' ),
			'view_items'            => __( 'Ver lecciones', 'atora-lms' ),
			'search_items'          => __( 'Buscar lecciones', 'atora-lms' ),
			'not_found'             => __( 'No se encontraron lecciones', 'atora-lms' ),
			'not_found_in_trash'    => __( 'No se encontraron lecciones en la papelera', 'atora-lms' ),
			'all_items'             => __( 'Todas las lecciones', 'atora-lms' ),
			'archives'              => __( 'Archivo de lecciones', 'atora-lms' ),
			'attributes'            => __( 'Atributos de la lección', 'atora-lms' ),
			'featured_image'        => __( 'Imagen destacada', 'atora-lms' ),
			'set_featured_image'    => __( 'Asignar imagen destacada', 'atora-lms' ),
			'remove_featured_image' => __( 'Quitar imagen destacada', 'atora-lms' ),
			'use_featured_image'    => __( 'Usar como imagen destacada', 'atora-lms' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => 'clms-dashboard',
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => true,
			'show_in_rest'        => true,
			'menu_position'       => null,
			'menu_icon'           => 'dashicons-media-document',
			'has_archive'         => false,
			'rewrite'             => array(
				'slug'       => 'leccion',
				'with_front' => false,
			),
			'supports'            => array(
				'title',
				'editor',
				'excerpt',
				'thumbnail',
				'author',
				'page-attributes',
			),
			'capability_type'     => array( 'lm_lesson', 'lm_lessons' ),
			'map_meta_cap'        => true,
			'capabilities'        => array(
				'edit_post'              => 'edit_lm_lesson',
				'read_post'              => 'read_lm_lesson',
				'delete_post'            => 'delete_lm_lesson',
				'edit_posts'             => 'edit_lm_lessons',
				'edit_others_posts'      => 'edit_others_lm_lessons',
				'publish_posts'          => 'publish_lm_lessons',
				'read_private_posts'     => 'read_private_lm_lessons',
				'delete_posts'           => 'delete_lm_lessons',
				'delete_private_posts'   => 'delete_private_lm_lessons',
				'delete_published_posts' => 'delete_published_lm_lessons',
				'delete_others_posts'    => 'delete_others_lm_lessons',
				'edit_private_posts'     => 'edit_private_lm_lessons',
				'edit_published_posts'   => 'edit_published_lm_lessons',
				'create_posts'           => 'create_lm_lessons',
			),
			'hierarchical'        => false,
			'exclude_from_search' => false,
			'query_var'           => true,
			'can_export'          => true,
			'delete_with_user'    => false,
		);

		register_post_type( 'lm_lesson', $args );
	}

	/**
	 * Añade una columna operativa a cursos para saltar a sus lecciones.
	 *
	 * @param array $columns Columnas.
	 * @return array
	 */
	public function filter_course_admin_columns( $columns ) {
		return $this->inject_lessons_column( $columns );
	}

	/**
	 * Añade una columna operativa a programas para saltar a sus lecciones.
	 *
	 * @param array $columns Columnas.
	 * @return array
	 */
	public function filter_program_admin_columns( $columns ) {
		return $this->inject_lessons_column( $columns );
	}

	/**
	 * Columnas de cohortes.
	 *
	 * @param array $columns Columnas.
	 * @return array
	 */
	public function filter_cohort_admin_columns( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$updated = array();
		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;
			if ( 'title' === $key ) {
				$updated['clms_cohort_status'] = __( 'Estado', 'atora-lms' );
				$updated['clms_cohort_dates']  = __( 'Fechas', 'atora-lms' );
				$updated['clms_cohort_scope']  = __( 'Alcance', 'atora-lms' );
			}
		}

		return $updated;
	}

	/**
	 * Renderiza la columna de lecciones para cursos.
	 *
	 * @param string $column  Columna.
	 * @param int    $post_id Post.
	 * @return void
	 */
	public function render_course_admin_column( $column, $post_id ) {
		if ( 'clms_related_lessons' !== $column ) {
			return;
		}

		$this->render_related_lessons_link( absint( $post_id ), 'course' );
	}

	/**
	 * Renderiza la columna de lecciones para programas.
	 *
	 * @param string $column  Columna.
	 * @param int    $post_id Post.
	 * @return void
	 */
	public function render_program_admin_column( $column, $post_id ) {
		if ( 'clms_related_lessons' !== $column ) {
			return;
		}

		$this->render_related_lessons_link( absint( $post_id ), 'program' );
	}

	/**
	 * Render columnas cohorte.
	 *
	 * @param string $column  Columna.
	 * @param int    $post_id Post.
	 * @return void
	 */
	public function render_cohort_admin_column( $column, $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || 'lm_cohort' !== get_post_type( $post_id ) ) {
			return;
		}

		if ( 'clms_cohort_status' === $column ) {
			$status = sanitize_key( (string) get_post_meta( $post_id, '_clms_cohort_status', true ) );
			$labels = array(
				'proximo'    => __( 'Próximo', 'atora-lms' ),
				'activo'     => __( 'Activo', 'atora-lms' ),
				'en_cierre'  => __( 'En cierre', 'atora-lms' ),
				'finalizado' => __( 'Finalizado', 'atora-lms' ),
				'archivado'  => __( 'Archivado', 'atora-lms' ),
				'cancelado'  => __( 'Cancelado', 'atora-lms' ),
			);
			$label = isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Próximo', 'atora-lms' );
			echo esc_html( $label );
			return;
		}

		if ( 'clms_cohort_dates' === $column ) {
			$start = sanitize_text_field( (string) get_post_meta( $post_id, '_clms_cohort_start_date', true ) );
			$end   = sanitize_text_field( (string) get_post_meta( $post_id, '_clms_cohort_end_date', true ) );
			if ( '' === $start && '' === $end ) {
				echo '<span aria-hidden="true">-</span>';
				return;
			}
			$start_label = '' !== $start ? esc_html( $start ) : esc_html__( 'Sin fecha', 'atora-lms' );
			$end_label   = '' !== $end ? esc_html( $end ) : esc_html__( 'Sin fecha', 'atora-lms' );
			echo $start_label . ' → ' . $end_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}

		if ( 'clms_cohort_scope' === $column ) {
			$course_ids  = get_post_meta( $post_id, '_clms_cohort_course_ids', true );
			$program_ids = get_post_meta( $post_id, '_clms_cohort_program_ids', true );
			$student_ids = get_post_meta( $post_id, '_clms_cohort_student_ids', true );
			$course_ids  = is_array( $course_ids ) ? array_filter( array_map( 'absint', $course_ids ) ) : array();
			$program_ids = is_array( $program_ids ) ? array_filter( array_map( 'absint', $program_ids ) ) : array();
			$student_ids = is_array( $student_ids ) ? array_filter( array_map( 'absint', $student_ids ) ) : array();

			printf(
				/* translators: 1: courses, 2: programs, 3: students */
				esc_html__( '%1$d cursos · %2$d programas · %3$d estudiantes', 'atora-lms' ),
				count( $course_ids ),
				count( $program_ids ),
				count( $student_ids )
			);
		}
	}

	/**
	 * Reordena columnas del listado de lecciones para mostrar mejor su contexto académico.
	 *
	 * @param array $columns Columnas actuales.
	 * @return array
	 */
	public function filter_lesson_admin_columns( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$updated = array();

		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;

			if ( 'title' === $key ) {
				$updated['clms_lesson_course']    = __( 'Curso', 'atora-lms' );
				$updated['clms_lesson_hierarchy'] = __( 'Ruta académica', 'atora-lms' );
				$updated['clms_lesson_order']     = __( 'Orden', 'atora-lms' );
			}
		}

		return $updated;
	}

	/**
	 * Renderiza columnas enriquecidas del listado de lecciones.
	 *
	 * @param string $column  Columna.
	 * @param int    $post_id ID lección.
	 * @return void
	 */
	public function render_lesson_admin_column( $column, $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || 'lm_lesson' !== get_post_type( $post_id ) ) {
			return;
		}

		$course_id = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_id_from_lesson( $post_id ) : 0;

		if ( 'clms_lesson_course' === $column ) {
			if ( $course_id ) {
				printf(
					'<a href="%1$s">%2$s</a>',
					esc_url(
						add_query_arg(
							array(
								'post'   => $course_id,
								'action' => 'edit',
							),
							admin_url( 'post.php' )
						)
					),
					esc_html( get_the_title( $course_id ) )
				);
			} else {
				echo '<span aria-hidden="true">-</span>';
			}
			return;
		}

		if ( 'clms_lesson_hierarchy' === $column ) {
			$parts = array();

			if ( $course_id && class_exists( 'CLMS_Helper' ) ) {
				$program_titles = array();

				foreach ( CLMS_Helper::get_course_program_ids( $course_id ) as $program_id ) {
					$program_title = get_the_title( $program_id );
					if ( '' !== trim( $program_title ) ) {
						$program_titles[] = $program_title;
					}
				}

				if ( ! empty( $program_titles ) ) {
					$parts[] = implode( ', ', $program_titles );
				}

				$parts[] = get_the_title( $course_id );
			}

			$module = trim( (string) get_post_meta( $post_id, '_clms_lesson_module', true ) );
			if ( '' !== $module ) {
				$parts[] = $module;
			}

			if ( empty( $parts ) ) {
				echo '<span aria-hidden="true">-</span>';
				return;
			}

			echo esc_html( implode( ' > ', array_filter( $parts ) ) );
			return;
		}

		if ( 'clms_lesson_order' === $column ) {
			$module     = trim( (string) get_post_meta( $post_id, '_clms_lesson_module', true ) );
			$menu_order = (int) get_post_field( 'menu_order', $post_id );

			echo '<strong>' . esc_html( (string) $menu_order ) . '</strong>';

			if ( '' !== $module ) {
				echo '<br><span class="description">' . esc_html( $module ) . '</span>';
			}
		}
	}

	/**
	 * Filtros rápidos por curso/programa en el listado de lecciones.
	 *
	 * @return void
	 */
	public function render_lesson_admin_filters() {
		global $typenow;

		if ( 'lm_lesson' !== $typenow ) {
			return;
		}

		$selected_course  = isset( $_GET['clms_course_id'] ) ? absint( wp_unslash( $_GET['clms_course_id'] ) ) : 0;
		$selected_program = isset( $_GET['clms_program_id'] ) ? absint( wp_unslash( $_GET['clms_program_id'] ) ) : 0;

		$courses = get_posts(
			array(
				'post_type'      => 'lm_course',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$programs = get_posts(
			array(
				'post_type'      => 'lm_program',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<select name="clms_program_id" id="filter-by-clms-program">
			<option value="0"><?php esc_html_e( 'Todos los programas', 'atora-lms' ); ?></option>
			<?php foreach ( $programs as $program ) : ?>
				<option value="<?php echo esc_attr( $program->ID ); ?>" <?php selected( $selected_program, $program->ID ); ?>>
					<?php echo esc_html( $program->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<select name="clms_course_id" id="filter-by-clms-course">
			<option value="0"><?php esc_html_e( 'Todos los cursos', 'atora-lms' ); ?></option>
			<?php foreach ( $courses as $course ) : ?>
				<option value="<?php echo esc_attr( $course->ID ); ?>" <?php selected( $selected_course, $course->ID ); ?>>
					<?php echo esc_html( $course->post_title ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Ajusta la query admin de lecciones para filtrar por curso/programa y priorizar el orden editorial.
	 *
	 * @param WP_Query $query Query actual.
	 * @return void
	 */
	public function filter_lesson_admin_query( $query ) {
		if ( ! $query instanceof WP_Query || ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'lm_lesson' !== $query->get( 'post_type' ) ) {
			return;
		}

		$selected_course  = isset( $_GET['clms_course_id'] ) ? absint( wp_unslash( $_GET['clms_course_id'] ) ) : 0;
		$selected_program = isset( $_GET['clms_program_id'] ) ? absint( wp_unslash( $_GET['clms_program_id'] ) ) : 0;

		if ( $selected_course ) {
			$course_meta_keys = class_exists( 'CLMS_Helper' )
				? array_merge(
					array( CLMS_Helper::COURSE_META_KEY, CLMS_Helper::COURSE_META_KEY_LEGACY ),
					CLMS_Helper::COURSE_META_KEYS_FALLBACK
				)
				: array( '_clms_course_id', 'lm_course_id', '_clms_lesson_course_id', 'course_id' );
			$meta_query       = array( 'relation' => 'OR' );

			foreach ( array_unique( array_filter( $course_meta_keys ) ) as $meta_key ) {
				$meta_query[] = array(
					'key'     => $meta_key,
					'value'   => $selected_course,
					'compare' => '=',
					'type'    => 'NUMERIC',
				);
			}

			$query->set(
				'meta_query',
				$meta_query
			);
		} elseif ( $selected_program && class_exists( 'CLMS_Helper' ) ) {
			$lesson_ids = array();

			foreach ( CLMS_Helper::get_program_courses( $selected_program ) as $course_id ) {
				$lesson_ids = array_merge( $lesson_ids, CLMS_Helper::get_course_lessons( $course_id ) );
			}

			$lesson_ids = array_values( array_unique( array_filter( array_map( 'absint', $lesson_ids ) ) ) );
			$query->set( 'post__in', empty( $lesson_ids ) ? array( 0 ) : $lesson_ids );
		}

		if ( ! $query->get( 'orderby' ) ) {
			$query->set( 'orderby', 'menu_order title' );
			$query->set( 'order', 'ASC' );
		}
	}

	/**
	 * Añade acceso rápido a lecciones desde la fila de cursos/programas.
	 *
	 * @param array   $actions Acciones.
	 * @param WP_Post $post    Post actual.
	 * @return array
	 */
	public function filter_admin_row_actions( $actions, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return $actions;
		}

		if ( 'lm_course' === $post->post_type ) {
			$actions['clms_view_lessons'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $this->get_filtered_lessons_link( $post->ID, 'course' ) ),
				esc_html__( 'Ver lecciones', 'atora-lms' )
			);
			if ( class_exists( 'CLMS_Helper' ) && CLMS_Helper::user_can_manage_lms( $post->ID ) ) {
				$duplicate_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=clms_duplicate_course&course_id=' . absint( $post->ID ) ),
					'clms_duplicate_course_' . absint( $post->ID )
				);
				$actions['clms_duplicate'] = sprintf(
					'<a href="%1$s" onclick="return confirm(\'%2$s\');">%3$s</a>',
					esc_url( $duplicate_url ),
					esc_js( __( '¿Duplicar este curso con todas sus lecciones?', 'atora-lms' ) ),
					esc_html__( 'Duplicar', 'atora-lms' )
				);
			}
		} elseif ( 'lm_program' === $post->post_type ) {
			$actions['clms_view_lessons'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $this->get_filtered_lessons_link( $post->ID, 'program' ) ),
				esc_html__( 'Ver lecciones', 'atora-lms' )
			);
		}

		return $actions;
	}

	/**
	 * Inserta una columna común de lecciones relacionadas.
	 *
	 * @param array $columns Columnas.
	 * @return array
	 */
	protected function inject_lessons_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$updated = array();

		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;

			if ( 'title' === $key ) {
				$updated['clms_related_lessons'] = __( 'Lecciones', 'atora-lms' );
			}
		}

		return $updated;
	}

	/**
	 * Imprime contador y acceso rápido a lecciones relacionadas.
	 *
	 * @param int    $post_id ID del post.
	 * @param string $type    course|program.
	 * @return void
	 */
	protected function render_related_lessons_link( $post_id, $type ) {
		$post_id = absint( $post_id );
		$count   = $this->get_related_lessons_count( $post_id, $type );

		echo '<strong>' . esc_html( (string) $count ) . '</strong>';
		echo '<br><a href="' . esc_url( $this->get_filtered_lessons_link( $post_id, $type ) ) . '">' . esc_html__( 'Abrir listado', 'atora-lms' ) . '</a>';
	}

	/**
	 * Resuelve el total de lecciones relacionadas.
	 *
	 * @param int    $post_id ID del post.
	 * @param string $type    course|program.
	 * @return int
	 */
	protected function get_related_lessons_count( $post_id, $type ) {
		if ( ! class_exists( 'CLMS_Helper' ) || ! $post_id ) {
			return 0;
		}

		if ( 'program' === $type ) {
			$lesson_ids = array();

			foreach ( CLMS_Helper::get_program_courses( $post_id ) as $course_id ) {
				$lesson_ids = array_merge( $lesson_ids, CLMS_Helper::get_course_lessons( $course_id ) );
			}

			return count( array_unique( array_filter( array_map( 'absint', $lesson_ids ) ) ) );
		}

		return method_exists( 'CLMS_Helper', 'get_course_lesson_count' )
			? (int) CLMS_Helper::get_course_lesson_count( $post_id )
			: 0;
	}

	/**
	 * Construye URL filtrada al listado de lecciones.
	 *
	 * @param int    $post_id ID del post.
	 * @param string $type    course|program.
	 * @return string
	 */
	protected function get_filtered_lessons_link( $post_id, $type ) {
		$args = array( 'post_type' => 'lm_lesson' );

		if ( 'program' === $type ) {
			$args['clms_program_id'] = absint( $post_id );
		} else {
			$args['clms_course_id'] = absint( $post_id );
		}

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	public function disable_block_editor_for_lms_types( $use_block_editor, $post_type ) {
		// lm_cohort añadido: su editor usa metaboxes propios, no el block editor
		if ( in_array( $post_type, array( 'lm_course', 'lm_lesson', 'lm_program', 'lm_cohort' ), true ) ) {
			return false;
		}
		return $use_block_editor;
	}
}
