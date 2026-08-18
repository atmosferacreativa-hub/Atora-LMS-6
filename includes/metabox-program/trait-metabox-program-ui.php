<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Metabox_Program_UI_Trait {
	public function enqueue_on_program( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'lm_program' !== $screen->post_type ) {
			return;
		}

		$version = defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0';
		$assets  = defined( 'ATORA_LMS_ASSETS_URL' ) ? ATORA_LMS_ASSETS_URL : ( defined( 'ATORA_LMS_URL' ) ? ATORA_LMS_URL . 'assets/' : '' );

		// SortableJS (CDN) — may already be registered by curriculum-builder on course screens
		if ( ! wp_script_is( 'sortablejs', 'registered' ) ) {
			wp_register_script(
				'sortablejs',
				'https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.3/Sortable.min.js',
				array(),
				'1.15.3',
				true
			);
		}
		wp_enqueue_script( 'sortablejs' );

		// Shared CSS (curriculum-builder.css contains .pa-* tokens)
		wp_enqueue_style(
			'atora-curriculum-builder',
			$assets . 'css/curriculum-builder.css',
			array(),
			$version
		);

		// Program arranger JS
		wp_enqueue_script(
			'atora-program-arranger',
			$assets . 'js/program-arranger.js',
			array( 'sortablejs' ),
			$version,
			true
		);

		// Build enrolled + all-courses data for the JS.
		// get_the_ID() puede devolver 0 en admin; preferimos `post` del query string
		// y caemos al post global solo como fallback.
		$program_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $program_id ) {
			$post_obj   = get_post();
			$program_id = $post_obj ? absint( $post_obj->ID ) : 0;
		}
		$enrolled_ids    = class_exists( 'CLMS_Helper' ) ? (array) CLMS_Helper::get_program_courses( $program_id ) : array();
		$enrolled_courses = array();

		foreach ( $enrolled_ids as $cid ) {
			$cid = absint( $cid );
			if ( ! $cid ) { continue; }
			$enrolled_courses[] = array(
				'id'    => $cid,
				'title' => (string) get_the_title( $cid ),
			);
		}

		$all_posts = get_posts(
			array(
				'post_type'      => 'lm_course',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$all_courses = array();
		foreach ( $all_posts as $post ) {
			$all_courses[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
			);
		}

		wp_localize_script(
			'atora-program-arranger',
			'ATORA_PA',
			array(
				'restUrl'         => esc_url_raw( rest_url() ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'programId'       => $program_id,
				'adminUrl'        => esc_url_raw( admin_url() ),
				'enrolledCourses' => $enrolled_courses,
				'allCourses'      => $all_courses,
			)
		);
	}

	public function add_meta_boxes() {
		add_meta_box(
			'clms_program_meta',
			__( 'Modelo académico del programa', 'atora-lms' ),
			array( $this, 'render_meta_box' ),
			'lm_program',
			'normal',
			'high'
		);
		add_meta_box(
			'clms_program_enrollment',
			__( 'Estudiantes / Matrículas', 'atora-lms' ),
			array( $this, 'render_enrollment_box' ),
			'lm_program',
			'normal',
			'default'
		);
		add_meta_box(
			'clms_program_commercial',
			__( 'Página comercial del programa', 'atora-lms' ),
			array( $this, 'render_commercial_box' ),
			'lm_program',
			'normal',
			'default'
		);
		add_meta_box(
			'clms_program_curriculum_preview',
			__( 'Malla del programa', 'atora-lms' ),
			array( $this, 'render_curriculum_preview_box' ),
			'lm_program',
			'side',
			'default'
		);
		add_meta_box(
			'clms_program_teachers',
			__( 'Docentes del programa', 'atora-lms' ),
			array( $this, 'render_teacher_box' ),
			'lm_program',
			'side',
			'default'
		);
	}

	/**
	 * Campos de ficha académica para programa.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function get_program_academic_fields(): array {
		$fields = array(
			'_clms_program_objective_general' => array(
				'label' => __( 'Objetivo general', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 2,
			),
			'_clms_program_competencies' => array(
				'label' => __( 'Competencias del programa', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 3,
				'help'  => __( 'Una competencia por línea.', 'atora-lms' ),
			),
			'_clms_program_learning_outcomes' => array(
				'label' => __( 'Resultados de aprendizaje', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 3,
			),
			'_clms_program_methodology' => array(
				'label' => __( 'Metodología', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 2,
			),
			'_clms_program_evidence' => array(
				'label' => __( 'Evidencias evaluables', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 2,
			),
			'_clms_program_evaluation_criteria' => array(
				'label' => __( 'Criterios de evaluación', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 2,
			),
			'_clms_program_difficulty' => array(
				'label' => __( 'Nivel de dificultad', 'atora-lms' ),
				'type'  => 'select',
				'options' => array(
					''            => __( 'Sin definir', 'atora-lms' ),
					'basico'      => __( 'Básico', 'atora-lms' ),
					'intermedio'  => __( 'Intermedio', 'atora-lms' ),
					'avanzado'    => __( 'Avanzado', 'atora-lms' ),
				),
			),
			'_clms_program_modality' => array(
				'label' => __( 'Modalidad', 'atora-lms' ),
				'type'  => 'select',
				'options' => array(
					''            => __( 'Sin definir', 'atora-lms' ),
					'asincrona'   => __( 'Asíncrona', 'atora-lms' ),
					'sincrona'    => __( 'Síncrona', 'atora-lms' ),
					'hibrida'     => __( 'Híbrida', 'atora-lms' ),
					'presencial'  => __( 'Presencial', 'atora-lms' ),
				),
			),
			'_clms_program_entry_profile' => array(
				'label' => __( 'Perfil de ingreso', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 2,
			),
			'_clms_program_exit_profile' => array(
				'label' => __( 'Perfil de egreso', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 2,
			),
			'_clms_program_certification' => array(
				'label' => __( 'Certificación o constancia', 'atora-lms' ),
				'type'  => 'text',
			),
		);
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$fields = (array) CLMS_Helper::modular_apply( 'program_academic_fields', $fields );
		}

		return $fields;
	}

	public function render_meta_box( $post ) {
		if ( ! $post || 'lm_program' !== $post->post_type || ! CLMS_Helper::user_can_manage_lms( $post->ID ) ) {
			return;
		}

		wp_nonce_field( 'clms_save_program_meta', 'clms_program_nonce' );

		$subtitle = (string) get_post_meta( $post->ID, '_clms_program_subtitle', true );
		$duration = (string) get_post_meta( $post->ID, '_clms_program_duration', true );
		$academic_fields = $this->get_program_academic_fields();
		$academic_values = array();
		foreach ( $academic_fields as $field_key => $field_config ) {
			$academic_values[ $field_key ] = (string) get_post_meta( $post->ID, $field_key, true );
		}
		?>
		<p>
			<label for="_clms_program_subtitle"><strong><?php esc_html_e( 'Subtítulo', 'atora-lms' ); ?></strong></label><br>
			<input style="width:100%" type="text" id="_clms_program_subtitle" name="_clms_program_subtitle" value="<?php echo esc_attr( $subtitle ); ?>">
		</p>
		<p style="margin-top:8px">
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'clms_preview_template', 'commercial', get_permalink( $post->ID ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Vista previa comercial', 'atora-lms' ); ?></a>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'clms_preview_template', 'overview', get_permalink( $post->ID ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Vista previa educativa', 'atora-lms' ); ?></a>
			<span class="description" style="margin-left:8px"><?php esc_html_e( 'Abre la página pública en una pestaña nueva.', 'atora-lms' ); ?></span>
		</p>
		<p>
			<label for="_clms_program_duration"><strong><?php esc_html_e( 'Duración', 'atora-lms' ); ?></strong></label><br>
			<input style="width:100%" type="text" id="_clms_program_duration" name="_clms_program_duration" value="<?php echo esc_attr( $duration ); ?>" placeholder="<?php echo esc_attr__( 'Ej. 12 semanas / 6 meses', 'atora-lms' ); ?>">
		</p>
		<p>
			<strong><?php esc_html_e( 'Cursos del programa', 'atora-lms' ); ?></strong>
		</p>
		<div
			id="atora-program-arranger"
			data-program-id="<?php echo absint( $post->ID ); ?>"
		>
			<noscript>
				<p><?php esc_html_e( 'El ordenador de cursos requiere JavaScript. Por favor actívalo en tu navegador.', 'atora-lms' ); ?></p>
			</noscript>
		</div>
		<details style="margin-top:12px;border-top:1px solid #e5e7eb;padding-top:12px">
			<summary><strong><?php esc_html_e( 'Ficha académica del programa', 'atora-lms' ); ?></strong></summary>
			<div style="display:grid;gap:10px;margin-top:10px">
				<?php foreach ( $academic_fields as $field_key => $field_config ) : ?>
					<p style="margin:0">
						<label for="<?php echo esc_attr( $field_key ); ?>"><strong><?php echo esc_html( $field_config['label'] ); ?></strong></label><br>
						<?php if ( ! empty( $field_config['type'] ) && 'select' === $field_config['type'] ) : ?>
							<select id="<?php echo esc_attr( $field_key ); ?>" name="<?php echo esc_attr( $field_key ); ?>" style="width:100%">
								<?php foreach ( (array) ( $field_config['options'] ?? array() ) as $opt_key => $opt_label ) : ?>
									<option value="<?php echo esc_attr( $opt_key ); ?>" <?php selected( $academic_values[ $field_key ], $opt_key ); ?>>
										<?php echo esc_html( $opt_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						<?php elseif ( ! empty( $field_config['type'] ) && 'textarea' === $field_config['type'] ) : ?>
							<textarea id="<?php echo esc_attr( $field_key ); ?>" name="<?php echo esc_attr( $field_key ); ?>" rows="<?php echo esc_attr( (string) ( $field_config['rows'] ?? 3 ) ); ?>" style="width:100%"><?php echo esc_textarea( $academic_values[ $field_key ] ); ?></textarea>
						<?php else : ?>
							<input id="<?php echo esc_attr( $field_key ); ?>" name="<?php echo esc_attr( $field_key ); ?>" type="text" value="<?php echo esc_attr( $academic_values[ $field_key ] ); ?>" style="width:100%">
						<?php endif; ?>
						<?php if ( ! empty( $field_config['help'] ) ) : ?>
							<span class="description"><?php echo esc_html( $field_config['help'] ); ?></span>
						<?php endif; ?>
					</p>
				<?php endforeach; ?>
			</div>
		</details>
		<?php $this->render_program_diagnostics_panel( $post->ID ); ?>
		<?php
	}

	protected function render_program_diagnostics_panel( $program_id ) {
		$program_id = absint( $program_id );
		if ( ! $program_id ) {
			return;
		}

		$diagnostics_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Diagnostics_Service') : null;
		if ( ! $diagnostics_service && class_exists( 'CLMS_Academic_Diagnostics_Service' ) ) {
			$diagnostics_service = new CLMS_Academic_Diagnostics_Service();
		}
		if ( ! $diagnostics_service || ! method_exists( $diagnostics_service, 'diagnose_program' ) ) {
			return;
		}

		$diag     = (array) $diagnostics_service->diagnose_program( $program_id );
		$warnings = isset( $diag['warnings'] ) && is_array( $diag['warnings'] ) ? $diag['warnings'] : array();
		$errors   = isset( $diag['errors'] ) && is_array( $diag['errors'] ) ? $diag['errors'] : array();
		$courses  = isset( $diag['courses'] ) && is_array( $diag['courses'] ) ? $diag['courses'] : array();
		?>
		<details style="margin-top:12px;border-top:1px solid #e5e7eb;padding-top:12px">
			<summary><strong><?php esc_html_e( 'Diagnóstico académico del programa', 'atora-lms' ); ?></strong></summary>
			<div style="margin-top:10px">
				<p class="description">
					<?php
					printf(
						esc_html__(
							'Cursos listos para certificar dentro del programa: %1$d de %2$d.',
							'atora-lms'
						),
						absint( $diag['ready_courses'] ?? 0 ),
						absint( $diag['courses_count'] ?? 0 )
					);
					?>
				</p>
				<?php if ( ! empty( $courses ) ) : ?>
					<ul style="margin:0;padding-left:18px;display:grid;gap:4px">
						<?php foreach ( array_slice( $courses, 0, 8 ) as $course_item ) : ?>
							<?php
							$course_item = is_array( $course_item ) ? $course_item : array();
							$course_title = sanitize_text_field( (string) ( $course_item['course_title'] ?? '' ) );
							$is_ready = ! empty( $course_item['ready'] );
							?>
							<li>
								<?php echo esc_html( ( $is_ready ? '[OK] ' : '[!] ' ) . $course_title ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( ! empty( $warnings ) ) : ?>
					<p style="margin:10px 0 4px"><strong><?php esc_html_e( 'Advertencias', 'atora-lms' ); ?></strong></p>
					<ul style="margin:0;padding-left:18px;display:grid;gap:4px">
						<?php foreach ( array_slice( $warnings, 0, 8 ) as $warning ) : ?>
							<li><?php echo esc_html( sanitize_text_field( (string) $warning ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( ! empty( $errors ) ) : ?>
					<p style="margin:10px 0 4px"><strong><?php esc_html_e( 'Errores críticos', 'atora-lms' ); ?></strong></p>
					<ul style="margin:0;padding-left:18px;display:grid;gap:4px">
						<?php foreach ( array_slice( $errors, 0, 8 ) as $error ) : ?>
							<li><?php echo esc_html( sanitize_text_field( (string) $error ) ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	public function render_commercial_box( $post ) {
		if ( ! $post || 'lm_program' !== $post->post_type || ! CLMS_Helper::user_can_manage_lms( $post->ID ) ) {
			return;
		}

		wp_nonce_field( 'clms_save_program_commercial', 'clms_program_commercial_nonce' );

		$mode             = (string) get_post_meta( $post->ID, '_clms_commercial_mode', true );
		$tagline          = (string) get_post_meta( $post->ID, '_clms_commercial_tagline', true );
		$hero_video       = (string) get_post_meta( $post->ID, '_clms_commercial_hero_video', true );
		$hero_video_src   = (string) get_post_meta( $post->ID, '_clms_commercial_hero_video_source', true ) ?: 'youtube';
		$cta_url          = (string) get_post_meta( $post->ID, '_clms_commercial_cta_url', true );
		$cta_label        = (string) get_post_meta( $post->ID, '_clms_commercial_cta_label', true );
		$price            = (string) get_post_meta( $post->ID, '_clms_program_price', true );
		$price_label      = (string) get_post_meta( $post->ID, '_clms_program_price_label', true );
		$related_products = (string) get_post_meta( $post->ID, '_clms_commercial_related_product_ids', true );
		$related_courses  = (string) get_post_meta( $post->ID, '_clms_commercial_related_course_ids', true );
		$related_programs = (string) get_post_meta( $post->ID, '_clms_commercial_related_program_ids', true );
		?>
		<p>
			<label><strong><?php esc_html_e( 'Modo de página de programa', 'atora-lms' ); ?></strong></label><br>
			<select name="_clms_commercial_mode" style="width:280px">
				<option value="enrolled" <?php selected( $mode, 'enrolled' ); ?>><?php esc_html_e( 'Interna / alumno inscrito', 'atora-lms' ); ?></option>
				<option value="commercial" <?php selected( $mode, 'commercial' ); ?>><?php esc_html_e( 'Landing comercial', 'atora-lms' ); ?></option>
			</select>
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Precio', 'atora-lms' ); ?></strong></label><br>
			<input style="width:100%" type="text" name="_clms_program_price" value="<?php echo esc_attr( $price ); ?>">
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Etiqueta de precio', 'atora-lms' ); ?></strong></label><br>
			<input style="width:100%" type="text" name="_clms_program_price_label" value="<?php echo esc_attr( $price_label ); ?>" placeholder="<?php echo esc_attr__( 'Pago único / 12 cuotas / Membresía incluida', 'atora-lms' ); ?>">
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Subtítulo / frase gancho', 'atora-lms' ); ?></strong></label><br>
			<input style="width:100%" type="text" name="_clms_commercial_tagline" value="<?php echo esc_attr( $tagline ); ?>">
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Video principal', 'atora-lms' ); ?></strong></label><br>
			<input style="width:100%" type="url" name="_clms_commercial_hero_video" value="<?php echo esc_attr( $hero_video ); ?>">
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Fuente del video', 'atora-lms' ); ?></strong></label><br>
			<select name="_clms_commercial_hero_video_source">
				<option value="youtube" <?php selected( $hero_video_src, 'youtube' ); ?>><?php esc_html_e( 'YouTube', 'atora-lms' ); ?></option>
				<option value="vimeo" <?php selected( $hero_video_src, 'vimeo' ); ?>><?php esc_html_e( 'Vimeo', 'atora-lms' ); ?></option>
				<option value="url" <?php selected( $hero_video_src, 'url' ); ?>><?php esc_html_e( 'URL directa', 'atora-lms' ); ?></option>
			</select>
		</p>
		<p>
			<label><strong><?php esc_html_e( 'URL CTA', 'atora-lms' ); ?></strong></label><br>
			<input style="width:100%" type="url" name="_clms_commercial_cta_url" value="<?php echo esc_attr( $cta_url ); ?>">
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Texto CTA', 'atora-lms' ); ?></strong></label><br>
			<input style="width:100%" type="text" name="_clms_commercial_cta_label" value="<?php echo esc_attr( $cta_label ); ?>" placeholder="<?php echo esc_attr__( 'Comprar programa', 'atora-lms' ); ?>">
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Productos relacionados', 'atora-lms' ); ?></strong></label><br>
			<input style="width:100%" type="text" name="_clms_commercial_related_product_ids" value="<?php echo esc_attr( $related_products ); ?>" placeholder="<?php echo esc_attr__( '12, 38, 44', 'atora-lms' ); ?>">
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Cursos relacionados', 'atora-lms' ); ?></strong></label><br>
			<input style="width:100%" type="text" name="_clms_commercial_related_course_ids" value="<?php echo esc_attr( $related_courses ); ?>" placeholder="<?php echo esc_attr__( '101, 102', 'atora-lms' ); ?>">
		</p>
		<p>
			<label><strong><?php esc_html_e( 'Programas relacionados', 'atora-lms' ); ?></strong></label><br>
			<input style="width:100%" type="text" name="_clms_commercial_related_program_ids" value="<?php echo esc_attr( $related_programs ); ?>" placeholder="<?php echo esc_attr__( '201, 202', 'atora-lms' ); ?>">
		</p>
		<?php
	}

	public function render_curriculum_preview_box( $post ) {
		if ( ! $post || 'lm_program' !== $post->post_type || ! CLMS_Helper::user_can_manage_lms( $post->ID ) ) {
			return;
		}

		$course_ids = CLMS_Helper::get_program_courses( $post->ID );
		?>
		<style>
		.clms-program-outline{display:grid;gap:10px}
		.clms-program-course{border:1px solid #dcdcde;border-radius:8px;background:#fff;padding:10px}
		.clms-program-course-title{margin:0 0 8px;font-size:13px;font-weight:700;color:#1d2327}
		.clms-program-course-meta{display:block;margin:0 0 8px;color:#646970;font-size:11px}
		.clms-program-outline-group{margin-top:8px}
		.clms-program-outline-group-title{margin:0 0 6px;font-size:11px;font-weight:700;color:#50575e;text-transform:uppercase;letter-spacing:.02em}
		.clms-program-outline-list{margin:0;padding:0;list-style:none;display:grid;gap:6px}
		.clms-program-outline-item{display:flex;gap:8px;align-items:flex-start}
		.clms-program-outline-order{display:inline-flex;min-width:24px;justify-content:center;border-radius:999px;background:#f0f0f1;color:#1d2327;font-size:11px;padding:2px 6px}
		.clms-program-outline-link{flex:1;text-decoration:none}
		.clms-program-outline-empty{margin:0;color:#646970}
		</style>
		<div class="clms-program-outline">
			<?php if ( empty( $course_ids ) ) : ?>
				<p class="clms-program-outline-empty"><?php esc_html_e( 'Este programa todavía no tiene cursos asociados.', 'atora-lms' ); ?></p>
			<?php else : ?>
				<?php foreach ( $course_ids as $course_id ) : ?>
					<?php
					$course_id = absint( $course_id );

					if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
						continue;
					}

					$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );
					$grouped    = array();
					$ungrouped  = array();

					foreach ( $lesson_ids as $lesson_id ) {
						$lesson_id = absint( $lesson_id );

						if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
							continue;
						}

						$module = trim( (string) get_post_meta( $lesson_id, '_clms_lesson_module', true ) );
						$item   = array(
							'title'      => get_the_title( $lesson_id ),
							'menu_order' => (int) get_post_field( 'menu_order', $lesson_id ),
							'edit_url'   => add_query_arg(
								array(
									'post'   => $lesson_id,
									'action' => 'edit',
								),
								admin_url( 'post.php' )
							),
						);

						if ( '' === $module ) {
							$ungrouped[] = $item;
						} else {
							if ( ! isset( $grouped[ $module ] ) ) {
								$grouped[ $module ] = array();
							}

							$grouped[ $module ][] = $item;
						}
					}
					?>
					<div class="clms-program-course">
						<p class="clms-program-course-title"><?php echo esc_html( get_the_title( $course_id ) ); ?></p>
						<span class="clms-program-course-meta">
							<?php
							$lesson_count = count( $lesson_ids );
							printf(
								esc_html( _n( '%s lección', '%s lecciones', $lesson_count, 'atora-lms' ) ),
								esc_html( (string) $lesson_count )
							);
							?>
						</span>

						<?php if ( empty( $lesson_ids ) ) : ?>
							<p class="clms-program-outline-empty"><?php esc_html_e( 'Este curso aún no tiene lecciones.', 'atora-lms' ); ?></p>
						<?php else : ?>
							<?php foreach ( $grouped as $module_name => $items ) : ?>
								<div class="clms-program-outline-group">
									<p class="clms-program-outline-group-title"><?php echo esc_html( $module_name ); ?></p>
									<ul class="clms-program-outline-list">
										<?php foreach ( $items as $item ) : ?>
											<li class="clms-program-outline-item">
												<span class="clms-program-outline-order"><?php echo esc_html( (string) $item['menu_order'] ); ?></span>
												<a class="clms-program-outline-link" href="<?php echo esc_url( $item['edit_url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
											</li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endforeach; ?>

							<?php if ( ! empty( $ungrouped ) ) : ?>
								<div class="clms-program-outline-group">
									<p class="clms-program-outline-group-title"><?php esc_html_e( 'Sin módulo', 'atora-lms' ); ?></p>
									<ul class="clms-program-outline-list">
										<?php foreach ( $ungrouped as $item ) : ?>
											<li class="clms-program-outline-item">
												<span class="clms-program-outline-order"><?php echo esc_html( (string) $item['menu_order'] ); ?></span>
												<a class="clms-program-outline-link" href="<?php echo esc_url( $item['edit_url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
											</li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Vista agregada del programa por curso. Puedes reorganizarla editando módulo y orden en cada lección.', 'atora-lms' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_teacher_box( $post ) {
		if ( ! $post || 'lm_program' !== $post->post_type || ! CLMS_Helper::user_can_manage_lms( $post->ID ) ) {
			return;
		}

		wp_nonce_field( 'clms_save_program_teachers', 'clms_program_teacher_nonce' );

		$teacher_ids = get_post_meta( $post->ID, '_clms_program_teacher_ids', true );
		$teacher_ids = is_string( $teacher_ids ) ? preg_split( '/\s*,\s*/', trim( $teacher_ids ) ) : $teacher_ids;
		$teacher_ids = is_array( $teacher_ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $teacher_ids ) ) ) ) : array();

		$teachers = get_posts(
			array(
				'post_type'      => 'atora_teacher',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$teacher_map = array();
		foreach ( $teachers as $teacher ) {
			$teacher_map[ $teacher->ID ] = $teacher;
		}
		$ordered_teachers = array();
		foreach ( $teacher_ids as $teacher_id ) {
			if ( isset( $teacher_map[ $teacher_id ] ) ) {
				$ordered_teachers[] = $teacher_map[ $teacher_id ];
				unset( $teacher_map[ $teacher_id ] );
			}
		}
		if ( ! empty( $teacher_map ) ) {
			$ordered_teachers = array_merge( $ordered_teachers, array_values( $teacher_map ) );
		}
		?>
		<p>
			<label for="clms_program_teacher_ids"><strong><?php esc_html_e( 'Seleccionar docentes', 'atora-lms' ); ?></strong></label><br>
			<div id="clms-program-teacher-order" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0 12px">
				<button type="button" class="button" data-clms-move="up"><?php esc_html_e( 'Subir', 'atora-lms' ); ?></button>
				<button type="button" class="button" data-clms-move="down"><?php esc_html_e( 'Bajar', 'atora-lms' ); ?></button>
				<span class="description"><?php esc_html_e( 'Usa subir o bajar para ordenar el equipo docente del programa.', 'atora-lms' ); ?></span>
			</div>
			<select style="width:100%;min-height:120px" id="clms_program_teacher_ids" name="clms_program_teacher_ids[]" multiple>
				<?php foreach ( $ordered_teachers as $teacher ) : ?>
					<option value="<?php echo esc_attr( $teacher->ID ); ?>" <?php selected( in_array( $teacher->ID, $teacher_ids, true ), true ); ?>>
						<?php echo esc_html( $teacher->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<div id="clms-program-teacher-preview" style="margin-top:10px">
				<strong><?php esc_html_e( 'Orden actual:', 'atora-lms' ); ?></strong>
				<ol style="margin:6px 0 0 18px"></ol>
			</div>
			<span class="description"><?php esc_html_e( 'Puedes asignar uno o varios docentes al programa.', 'atora-lms' ); ?></span>
		</p>
		<script>
		(function() {
			var container = document.getElementById('clms-program-teacher-order');
			var select = document.getElementById('clms_program_teacher_ids');
			var preview = document.getElementById('clms-program-teacher-preview');
			if (!container || !select) {
				return;
			}
			var renderPreview = function() {
				if (!preview) {
					return;
				}
				var list = preview.querySelector('ol');
				if (!list) {
					return;
				}
				list.innerHTML = '';
				Array.prototype.slice.call(select.options).forEach(function(option) {
					if (!option.selected) {
						return;
					}
					var item = document.createElement('li');
					item.textContent = option.textContent;
					list.appendChild(item);
				});
				if (!list.children.length) {
					var empty = document.createElement('li');
					empty.textContent = '<?php echo esc_js( __( 'Sin docentes seleccionados.', 'atora-lms' ) ); ?>';
					list.appendChild(empty);
				}
			};
			var moveOptions = function(direction) {
				var options = Array.prototype.slice.call(select.options);
				var selected = [];
				options.forEach(function(option, index) {
					if (option.selected) {
						selected.push(index);
					}
				});
				if (!selected.length) {
					return;
				}
				if (direction === 'up') {
					selected.forEach(function(index) {
						if (index <= 0) {
							return;
						}
						var option = select.options[index];
						var prev = select.options[index - 1];
						if (option && prev) {
							select.insertBefore(option, prev);
						}
					});
				}
				if (direction === 'down') {
					selected.slice().reverse().forEach(function(index) {
						if (index >= select.options.length - 1) {
							return;
						}
						var option = select.options[index];
						var next = select.options[index + 1];
						if (option && next) {
							select.insertBefore(next, option);
						}
					});
				}
				renderPreview();
			};
			container.querySelectorAll('[data-clms-move]').forEach(function(button) {
				button.addEventListener('click', function(event) {
					event.preventDefault();
					moveOptions(button.getAttribute('data-clms-move'));
				});
			});
			select.addEventListener('change', renderPreview);
			renderPreview();
		})();
		</script>

		<hr>

		<p><strong><?php esc_html_e( 'Crear docente rápido', 'atora-lms' ); ?></strong></p>
		<p>
			<label for="clms_new_program_teacher_name"><?php esc_html_e( 'Nombre', 'atora-lms' ); ?></label><br>
			<input id="clms_new_program_teacher_name" type="text" name="clms_new_program_teacher_name" style="width:100%" placeholder="<?php echo esc_attr__( 'Nombre del docente', 'atora-lms' ); ?>">
		</p>
		<p>
			<label for="clms_new_program_teacher_short_bio"><?php esc_html_e( 'Bio corta', 'atora-lms' ); ?></label><br>
			<textarea id="clms_new_program_teacher_short_bio" name="clms_new_program_teacher_short_bio" rows="2" style="width:100%"></textarea>
		</p>
		<p>
			<label for="clms_new_program_teacher_specialty"><?php esc_html_e( 'Especialidad', 'atora-lms' ); ?></label><br>
			<input id="clms_new_program_teacher_specialty" type="text" name="clms_new_program_teacher_specialty" style="width:100%">
		</p>
		<p>
			<label>
				<input type="checkbox" name="clms_new_program_teacher_public" value="1" checked>
				<?php esc_html_e( 'Visible en páginas públicas', 'atora-lms' ); ?>
			</label>
		</p>
		<p class="description"><?php esc_html_e( 'La bio larga y la foto se completan en la ficha del docente.', 'atora-lms' ); ?></p>
		<?php
	}

}
