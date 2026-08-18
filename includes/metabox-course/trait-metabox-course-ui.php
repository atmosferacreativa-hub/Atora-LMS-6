<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Metabox_Course_UI_Trait {
	public function enqueue_media_on_course( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'lm_course' !== $screen->post_type ) {
			return;
		}
		$course_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		if ( ! $course_id ) {
			$post_obj = get_post();
			$course_id = $post_obj ? absint( $post_obj->ID ) : 0;
		}
		wp_enqueue_media();

		$version = defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0';
		$assets  = defined( 'ATORA_LMS_ASSETS_URL' ) ? ATORA_LMS_ASSETS_URL : ( defined( 'ATORA_LMS_URL' ) ? ATORA_LMS_URL . 'assets/' : '' );

		// SortableJS (CDN)
		wp_enqueue_script(
			'sortablejs',
			'https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.3/Sortable.min.js',
			array(),
			'1.15.3',
			true
		);

		// Curriculum builder CSS
		wp_enqueue_style(
			'atora-curriculum-builder',
			$assets . 'css/curriculum-builder.css',
			array(),
			$version
		);

		// Curriculum builder JS
		wp_enqueue_script(
			'atora-curriculum-builder',
			$assets . 'js/curriculum-builder.js',
			array( 'sortablejs' ),
			$version,
			true
		);

		// Drip timeline CSS/JS
		wp_enqueue_style(
			'atora-drip-timeline',
			$assets . 'css/drip-timeline.css',
			array(),
			$version
		);
		wp_enqueue_script(
			'atora-drip-timeline',
			$assets . 'js/drip-timeline.js',
			array(),
			$version,
			true
		);

		// Pass config to JS
		wp_localize_script(
			'atora-curriculum-builder',
			'ATORA_CB',
			array(
				'restUrl'         => esc_url_raw( rest_url() ),
				'nonce'           => wp_create_nonce( 'wp_rest' ),
				'courseId'        => $course_id,
				'adminUrl'        => esc_url_raw( admin_url() ),
				'rubrics'         => $this->get_rubric_options(),
				'evaluationModes' => array(
					'manual'        => __( 'Manual', 'atora-lms' ),
					'ai_assisted'   => __( 'AI assisted', 'atora-lms' ),
					'ai_auto_grade' => __( 'AI auto grade', 'atora-lms' ),
					'peer_review'   => __( 'Peer review', 'atora-lms' ),
					'hybrid'        => __( 'Hybrid', 'atora-lms' ),
				),
				'activityModes' => array(
					'lectura'     => __( 'Lectura', 'atora-lms' ),
					'tarea'       => __( 'Tarea', 'atora-lms' ),
					'quiz'        => __( 'Quiz', 'atora-lms' ),
					'interactiva' => __( 'Interactiva', 'atora-lms' ),
				),
				'dripTypes' => array(
					'none'                => __( 'Sin restricción', 'atora-lms' ),
					'date'                => __( 'Fecha específica', 'atora-lms' ),
					'days_enrolled'       => __( 'Días desde inscripción', 'atora-lms' ),
					'days_after_previous' => __( 'Días después de la anterior', 'atora-lms' ),
				),
				'statusOptions' => array(
					'draft'   => __( 'Borrador', 'atora-lms' ),
					'publish' => __( 'Publicado', 'atora-lms' ),
					'private' => __( 'Privado', 'atora-lms' ),
				),
				'i18n' => array(
					'Cargando malla curricular…'                                  => __( 'Cargando malla curricular…', 'atora-lms' ),
					'No se pudo cargar la malla curricular.'                      => __( 'No se pudo cargar la malla curricular.', 'atora-lms' ),
					'Reintentar'                                                  => __( 'Reintentar', 'atora-lms' ),
					'Este curso todavía no tiene lecciones.'                      => __( 'Este curso todavía no tiene lecciones.', 'atora-lms' ),
					'Crear primera lección'                                       => __( 'Crear primera lección', 'atora-lms' ),
					'Arrastrar para reordenar módulo'                             => __( 'Arrastrar para reordenar módulo', 'atora-lms' ),
					'Sin módulo'                                                  => __( 'Sin módulo', 'atora-lms' ),
					'Clic para renombrar'                                         => __( 'Clic para renombrar', 'atora-lms' ),
					'lección'                                                     => __( 'lección', 'atora-lms' ),
					'lecciones'                                                   => __( 'lecciones', 'atora-lms' ),
					'Arrastra lecciones aquí.'                                    => __( 'Arrastra lecciones aquí.', 'atora-lms' ),
					'Lección en este módulo'                                      => __( 'Lección en este módulo', 'atora-lms' ),
					'Arrastrar para reordenar'                                    => __( 'Arrastrar para reordenar', 'atora-lms' ),
					'(Sin título)'                                                => __( '(Sin título)', 'atora-lms' ),
					'Ej. Introducción al tema'                                    => __( 'Ej. Introducción al tema', 'atora-lms' ),
					'Editar'                                                      => __( 'Editar', 'atora-lms' ),
					'Agregar módulo'                                              => __( 'Agregar módulo', 'atora-lms' ),
					'Nueva lección'                                               => __( 'Nueva lección', 'atora-lms' ),
					'Guardar como plantilla...'                                   => __( 'Guardar como plantilla...', 'atora-lms' ),
					'Aplicar plantilla...'                                        => __( 'Aplicar plantilla...', 'atora-lms' ),
					'Aplicar a seleccionadas (%d)'                                => __( 'Aplicar a seleccionadas (%d)', 'atora-lms' ),
					'Seleccionadas (%d)'                                          => __( 'Seleccionadas (%d)', 'atora-lms' ),
					'Título de la lección'                                        => __( 'Título de la lección', 'atora-lms' ),
					'Módulo'                                                      => __( 'Módulo', 'atora-lms' ),
					'Cancelar'                                                    => __( 'Cancelar', 'atora-lms' ),
					'Crear lección'                                               => __( 'Crear lección', 'atora-lms' ),
					'Creando…'                                                    => __( 'Creando…', 'atora-lms' ),
					'Creando lección…'                                            => __( 'Creando lección…', 'atora-lms' ),
					'Lección creada ✓'                                            => __( 'Lección creada ✓', 'atora-lms' ),
					'Error al crear la lección.'                                  => __( 'Error al crear la lección.', 'atora-lms' ),
					'Guardando…'                                                  => __( 'Guardando…', 'atora-lms' ),
					'Guardado ✓'                                                  => __( 'Guardado ✓', 'atora-lms' ),
					'Error al guardar.'                                           => __( 'Error al guardar.', 'atora-lms' ),
					'Ya existe un módulo con ese nombre.'                         => __( 'Ya existe un módulo con ese nombre.', 'atora-lms' ),
					'Nombre del nuevo módulo:'                                    => __( 'Nombre del nuevo módulo:', 'atora-lms' ),
					'Debes seleccionar al menos una lección.'                     => __( 'Debes seleccionar al menos una lección.', 'atora-lms' ),
					'Debes seleccionar una plantilla.'                            => __( 'Debes seleccionar una plantilla.', 'atora-lms' ),
					'¿Aplicar la plantilla seleccionada a %d lecciones? Esto sobrescribirá su configuración actual.' => __( '¿Aplicar la plantilla seleccionada a %d lecciones? Esto sobrescribirá su configuración actual.', 'atora-lms' ),
					'Nombre de la plantilla'                                      => __( 'Nombre de la plantilla', 'atora-lms' ),
					'Plantilla guardada ✓'                                        => __( 'Plantilla guardada ✓', 'atora-lms' ),
					'Error al guardar la plantilla.'                              => __( 'Error al guardar la plantilla.', 'atora-lms' ),
					'Plantilla aplicada ✓'                                        => __( 'Plantilla aplicada ✓', 'atora-lms' ),
					'Error al aplicar la plantilla.'                              => __( 'Error al aplicar la plantilla.', 'atora-lms' ),
					'Eliminar plantilla'                                          => __( 'Eliminar plantilla', 'atora-lms' ),
					'¿Eliminar esta plantilla?'                                   => __( '¿Eliminar esta plantilla?', 'atora-lms' ),
					'Plantilla eliminada.'                                        => __( 'Plantilla eliminada.', 'atora-lms' ),
					'Error al eliminar la plantilla.'                             => __( 'Error al eliminar la plantilla.', 'atora-lms' ),
					'Cargando ajuste rápido…'                                     => __( 'Cargando ajuste rápido…', 'atora-lms' ),
					'No se pudo cargar el formulario rápido.'                     => __( 'No se pudo cargar el formulario rápido.', 'atora-lms' ),
					'Quick-edit'                                                  => __( 'Quick-edit', 'atora-lms' ),
					'Evaluación'                                                  => __( 'Evaluación', 'atora-lms' ),
					'Rúbrica'                                                     => __( 'Rúbrica', 'atora-lms' ),
					'Sin rúbrica'                                                 => __( 'Sin rúbrica', 'atora-lms' ),
					'Actividad'                                                   => __( 'Actividad', 'atora-lms' ),
					'Drip'                                                        => __( 'Drip', 'atora-lms' ),
					'Fecha de desbloqueo'                                         => __( 'Fecha de desbloqueo', 'atora-lms' ),
					'Días de espera'                                              => __( 'Días de espera', 'atora-lms' ),
					'Peer review'                                                 => __( 'Peer review', 'atora-lms' ),
					'Estado'                                                      => __( 'Estado', 'atora-lms' ),
					'Guardar ✓'                                                   => __( 'Guardar ✓', 'atora-lms' ),
					'Guardando cambios…'                                          => __( 'Guardando cambios…', 'atora-lms' ),
					'Cambios guardados ✓'                                         => __( 'Cambios guardados ✓', 'atora-lms' ),
					'Error guardando cambios.'                                    => __( 'Error guardando cambios.', 'atora-lms' ),
				),
			)
		);

		wp_localize_script(
			'atora-drip-timeline',
			'CLMS_TL',
			array(
				'restBase' => trailingslashit( esc_url_raw( rest_url( 'clms/v1' ) ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'i18n'     => array(
					'modeRelative' => __( 'Días desde inscripción', 'atora-lms' ),
					'modeAbsolute' => __( 'Calendario', 'atora-lms' ),
					'zoomWeeks'    => __( 'Semanas', 'atora-lms' ),
					'zoomDays'     => __( 'Días', 'atora-lms' ),
					'startDate'    => __( 'Fecha de inicio:', 'atora-lms' ),
					'legendNone'   => __( 'Sin restricción', 'atora-lms' ),
					'legendDate'   => __( 'Fecha fija', 'atora-lms' ),
					'legendDays'   => __( 'Días post-inscripción', 'atora-lms' ),
					'legendPrev'   => __( 'Días post-anterior', 'atora-lms' ),
					'dayLabel'     => __( 'Día %d', 'atora-lms' ),
					'weekLabel'    => __( 'Sem %d', 'atora-lms' ),
					'saved'        => __( 'Guardado ✓', 'atora-lms' ),
					'saveError'    => __( 'Error al guardar cambios de liberación.', 'atora-lms' ),
				),
			)
		);
	}

	/**
	 * Devuelve opciones de rúbricas para quick-edit en builder.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_rubric_options(): array {
		$ids = get_posts(
			array(
				'post_type'      => 'clms_rubric',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		$options = array();
		foreach ( (array) $ids as $rubric_id ) {
			$rubric_id = absint( $rubric_id );
			if ( ! $rubric_id ) {
				continue;
			}
			$options[] = array(
				'id'    => $rubric_id,
				'title' => get_the_title( $rubric_id ),
			);
		}

		return $options;
	}

	/**
	 * Campos de ficha académica para curso.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function get_course_academic_fields(): array {
		$fields = array(
			'_clms_course_academic_objective_general' => array(
				'label' => __( 'Objetivo general', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 2,
			),
			'_clms_course_academic_objectives_specific' => array(
				'label' => __( 'Objetivos específicos', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 3,
				'help'  => __( 'Un objetivo por línea.', 'atora-lms' ),
			),
			'_clms_course_competencies' => array(
				'label' => __( 'Competencias', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 3,
				'help'  => __( 'Una competencia por línea.', 'atora-lms' ),
			),
			'_clms_course_learning_outcomes' => array(
				'label' => __( 'Resultados de aprendizaje', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 3,
			),
			'_clms_course_entry_profile' => array(
				'label' => __( 'Perfil de ingreso', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 2,
			),
			'_clms_course_exit_profile' => array(
				'label' => __( 'Perfil de egreso', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 2,
			),
			'_clms_course_methodology' => array(
				'label' => __( 'Metodología', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 2,
			),
			'_clms_course_evidence' => array(
				'label' => __( 'Evidencias evaluables', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 2,
			),
			'_clms_course_evaluation_criteria' => array(
				'label' => __( 'Criterios de evaluación', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 2,
			),
			'_clms_course_difficulty' => array(
				'label' => __( 'Nivel de dificultad', 'atora-lms' ),
				'type'  => 'select',
				'options' => array(
					''            => __( 'Sin definir', 'atora-lms' ),
					'basico'      => __( 'Básico', 'atora-lms' ),
					'intermedio'  => __( 'Intermedio', 'atora-lms' ),
					'avanzado'    => __( 'Avanzado', 'atora-lms' ),
				),
			),
			'_clms_course_modality' => array(
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
			'_clms_course_prerequisites_text' => array(
				'label' => __( 'Requisitos previos (texto)', 'atora-lms' ),
				'type'  => 'textarea',
				'rows'  => 2,
			),
			'_clms_course_certification_text' => array(
				'label' => __( 'Certificación o constancia', 'atora-lms' ),
				'type'  => 'text',
			),
			'_clms_course_certificate_enabled' => array(
				'label' => __( 'Certificado del curso', 'atora-lms' ),
				'type'  => 'select',
				'options' => array(
					''  => __( 'Usar configuración global', 'atora-lms' ),
					'1' => __( 'Activado para este curso', 'atora-lms' ),
					'0' => __( 'Desactivado para este curso', 'atora-lms' ),
				),
			),
			'_clms_course_certificate_min_progress' => array(
				'label' => __( 'Progreso mínimo para certificar (%)', 'atora-lms' ),
				'type'  => 'text',
				'help'  => __( 'Vacío = usa regla global.', 'atora-lms' ),
			),
			'_clms_course_certificate_min_grade' => array(
				'label' => __( 'Nota mínima para certificar (%)', 'atora-lms' ),
				'type'  => 'text',
				'help'  => __( 'Vacío = usa regla global.', 'atora-lms' ),
			),
			'_clms_course_certificate_auto_email' => array(
				'label' => __( 'Email automático de certificado', 'atora-lms' ),
				'type'  => 'select',
				'options' => array(
					''  => __( 'Usar configuración global', 'atora-lms' ),
					'1' => __( 'Enviar email en este curso', 'atora-lms' ),
					'0' => __( 'No enviar email en este curso', 'atora-lms' ),
				),
			),
			'_clms_course_hours' => array(
				'label' => __( 'Horas académicas', 'atora-lms' ),
				'type'  => 'text',
				'help'  => __( 'Se muestra en el certificado.', 'atora-lms' ),
			),
		);
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$fields = (array) CLMS_Helper::modular_apply( 'course_academic_fields', $fields );
		}

		return $fields;
	}

	public function add_meta_boxes() {
		add_meta_box(
			'clms_course_meta',
			__( 'Detalles del curso', 'atora-lms' ),
			array( $this, 'render_meta_box' ),
			'lm_course',
			'normal',
			'high'
		);
		add_meta_box(
			'clms_course_commercial',
			__( 'Página comercial del curso', 'atora-lms' ),
			array( $this, 'render_commercial_box' ),
			'lm_course',
			'normal',
			'default'
		);
		add_meta_box(
			'clms_course_academic',
			__( 'Modelo académico', 'atora-lms' ),
			array( $this, 'render_academic_box' ),
			'lm_course',
			'side',
			'default'
		);
		add_meta_box(
			'clms_course_curriculum_preview',
			__( 'Malla del curso', 'atora-lms' ),
			array( $this, 'render_curriculum_preview_box' ),
			'lm_course',
			'normal',
			'default'
		);
		add_meta_box(
			'clms_course_drip_timeline',
			__( 'Timeline de liberación', 'atora-lms' ),
			array( $this, 'render_drip_timeline_box' ),
			'lm_course',
			'normal',
			'default'
		);
		add_meta_box(
			'clms_course_teachers',
			__( 'Docentes del curso', 'atora-lms' ),
			array( $this, 'render_teacher_box' ),
			'lm_course',
			'side',
			'default'
		);
		add_meta_box(
			'clms_course_enrollment',
			__( 'Control de acceso y matriculación', 'atora-lms' ),
			array( $this, 'render_enrollment_box' ),
			'lm_course',
			'normal',
			'default'
		);
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'clms_save_course_meta', 'clms_course_nonce' );

		$fields = array(
			'_clms_course_subtitle'        => __( 'Subtítulo', 'atora-lms' ),
			'_clms_course_duration'        => __( 'Duración', 'atora-lms' ),
			'_clms_course_price'           => __( 'Precio', 'atora-lms' ),
			'_clms_course_price_label'     => __( 'Etiqueta de precio', 'atora-lms' ),
			'_clms_course_cta_text'        => __( 'Texto CTA', 'atora-lms' ),
			'_clms_course_certificate'     => __( 'Certificado', 'atora-lms' ),
			'_clms_course_benefits'        => __( 'Beneficios', 'atora-lms' ),
			'_clms_course_requirements'    => __( 'Requisitos', 'atora-lms' ),
			'_clms_course_target_audience' => __( 'Público objetivo', 'atora-lms' ),
			'_clms_course_excerpt'         => __( 'Resumen', 'atora-lms' ),
		);

		foreach ( $fields as $meta_key => $label ) {
			$raw   = get_post_meta( $post->ID, $meta_key, true );
			$value = is_array( $raw ) ? implode( "\n", array_filter( array_map( 'strval', $raw ) ) ) : (string) $raw;
			echo '<p><label>' . esc_html( $label ) . '</label><br>';
			if ( in_array( $meta_key, array( '_clms_course_benefits', '_clms_course_requirements', '_clms_course_target_audience' ), true ) ) {
				echo '<textarea style="width:100%" name="' . esc_attr( $meta_key ) . '" rows="3">' . esc_textarea( $value ) . '</textarea>';
			} else {
				echo '<input style="width:100%" type="text" name="' . esc_attr( $meta_key ) . '" value="' . esc_attr( $value ) . '">';
			}
			echo '</p>';
		}
	}

	public function render_commercial_box( $post ) {
		wp_nonce_field( 'clms_save_course_commercial', 'clms_course_commercial_nonce' );

		$mode            = get_post_meta( $post->ID, '_clms_commercial_mode', true );
		$hero_video      = get_post_meta( $post->ID, '_clms_commercial_hero_video', true );
		$hero_video_src  = get_post_meta( $post->ID, '_clms_commercial_hero_video_source', true ) ?: 'youtube';
		$tagline         = get_post_meta( $post->ID, '_clms_commercial_tagline', true );
		$ingress_profile = get_post_meta( $post->ID, '_clms_commercial_ingress_profile', true );
		$egress_profile  = get_post_meta( $post->ID, '_clms_commercial_egress_profile', true );
		$testimonials    = get_post_meta( $post->ID, '_clms_commercial_testimonials', true );
		$gallery_ids     = get_post_meta( $post->ID, '_clms_commercial_gallery_ids', true );
		$cta_url         = get_post_meta( $post->ID, '_clms_commercial_cta_url', true );
		$cta_label       = get_post_meta( $post->ID, '_clms_commercial_cta_label', true );
		$faq             = get_post_meta( $post->ID, '_clms_commercial_faq', true );
		$related_products = get_post_meta( $post->ID, '_clms_commercial_related_product_ids', true );
		$related_courses  = get_post_meta( $post->ID, '_clms_commercial_related_course_ids', true );
		$related_programs = get_post_meta( $post->ID, '_clms_commercial_related_program_ids', true );
		$gallery_list     = array_filter( array_map( 'absint', preg_split( '/\s*,\s*/', (string) $gallery_ids ) ) );
		$gallery_items    = array();
		if ( ! empty( $gallery_list ) ) {
			foreach ( $gallery_list as $image_id ) {
				$thumb = wp_get_attachment_image_url( $image_id, 'thumbnail' );
				if ( $thumb ) {
					$gallery_items[] = array(
						'id'    => $image_id,
						'url'   => $thumb,
						'title' => get_the_title( $image_id ),
					);
				}
			}
		}
		?>
		<style>
		.clms-gallery-upload{display:grid;gap:10px;margin-top:6px}
		.clms-gallery-drop{display:grid;gap:4px;place-items:center;border:1px dashed #d1d5db;border-radius:8px;padding:14px;text-align:center;background:#f8fafc;cursor:pointer;transition:border-color .15s,background .15s}
		.clms-gallery-drop.is-dragover{border-color:#6366f1;background:rgba(99,102,241,.08)}
		.clms-gallery-drop-title{font-weight:700;color:#1d2327;font-size:12px}
		.clms-gallery-drop-sub{font-size:11px;color:#6b7280}
		.clms-gallery-actions{display:flex;gap:8px;flex-wrap:wrap}
		.clms-gallery-preview{display:flex;flex-wrap:wrap;gap:6px}
		.clms-gallery-preview img{width:56px;height:56px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb}
		.clms-gallery-empty{font-size:12px;color:#6b7280}
		</style>
		<p>
			<label><strong><?php esc_html_e( 'Modo de página de curso', 'atora-lms' ); ?></strong></label><br>
			<select name="_clms_commercial_mode" style="width:280px">
				<option value="enrolled" <?php selected( $mode, 'enrolled' ); ?>><?php esc_html_e( 'Estándar (alumno con acceso)', 'atora-lms' ); ?></option>
				<option value="commercial" <?php selected( $mode, 'commercial' ); ?>><?php esc_html_e( 'Comercial (marketplace / venta)', 'atora-lms' ); ?></option>
			</select>
			<span class="description"><?php esc_html_e( '— Cuando sea Comercial, la página pública usará la plantilla de ventas.', 'atora-lms' ); ?></span>
		</p>
		<p style="margin-top:8px">
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'clms_preview_template', 'commercial', get_permalink( $post->ID ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Vista previa comercial', 'atora-lms' ); ?></a>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'clms_preview_template', 'overview', get_permalink( $post->ID ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Vista previa educativa', 'atora-lms' ); ?></a>
			<span class="description" style="margin-left:8px"><?php esc_html_e( 'Abre la página pública en una pestaña nueva.', 'atora-lms' ); ?></span>
		</p>
		<hr>
		<p><label><strong><?php esc_html_e( 'Video principal', 'atora-lms' ); ?></strong> <?php esc_html_e( '(URL de YouTube, Vimeo o embebido)', 'atora-lms' ); ?></label><br>
			<input style="width:100%" type="url" name="_clms_commercial_hero_video" value="<?php echo esc_attr( (string) $hero_video ); ?>">
		</p>
		<p><label><strong><?php esc_html_e( 'Fuente del video', 'atora-lms' ); ?></strong></label><br>
			<select name="_clms_commercial_hero_video_source">
				<option value="youtube" <?php selected( $hero_video_src, 'youtube' ); ?>><?php esc_html_e( 'YouTube', 'atora-lms' ); ?></option>
				<option value="vimeo"   <?php selected( $hero_video_src, 'vimeo' ); ?>><?php esc_html_e( 'Vimeo', 'atora-lms' ); ?></option>
				<option value="url"     <?php selected( $hero_video_src, 'url' ); ?>><?php esc_html_e( 'URL directa', 'atora-lms' ); ?></option>
			</select>
		</p>
		<p><label><strong><?php esc_html_e( 'Subtítulo / frase gancho', 'atora-lms' ); ?></strong></label><br>
			<input style="width:100%" type="text" name="_clms_commercial_tagline" value="<?php echo esc_attr( (string) $tagline ); ?>">
		</p>
		<p><label><strong><?php esc_html_e( 'Perfil de ingreso', 'atora-lms' ); ?></strong> <?php esc_html_e( '(¿Para quién es este curso?)', 'atora-lms' ); ?></label><br>
			<textarea style="width:100%" name="_clms_commercial_ingress_profile" rows="3"><?php echo esc_textarea( (string) $ingress_profile ); ?></textarea>
		</p>
		<p><label><strong><?php esc_html_e( 'Perfil de egreso', 'atora-lms' ); ?></strong> <?php esc_html_e( '(¿Qué logrará el alumno al terminar?)', 'atora-lms' ); ?></label><br>
			<textarea style="width:100%" name="_clms_commercial_egress_profile" rows="3"><?php echo esc_textarea( (string) $egress_profile ); ?></textarea>
		</p>
		<p><label><strong><?php esc_html_e( 'Preguntas frecuentes', 'atora-lms' ); ?></strong> <?php esc_html_e( '(una pregunta y respuesta por línea, separadas con |)', 'atora-lms' ); ?></label><br>
			<textarea style="width:100%" name="_clms_commercial_faq" rows="4" placeholder="<?php echo esc_attr__( '¿Necesito experiencia previa?|No, el curso parte desde cero.', 'atora-lms' ); ?>"><?php echo esc_textarea( (string) $faq ); ?></textarea>
		</p>
			<p><label><strong><?php esc_html_e( 'Testimonios', 'atora-lms' ); ?></strong> <?php esc_html_e( '(uno por línea: Nombre | Cargo | Texto)', 'atora-lms' ); ?></label><br>
				<textarea style="width:100%" name="_clms_commercial_testimonials" rows="4" placeholder="<?php echo esc_attr__( 'María López | Estudiante | Excelente curso, muy práctico.', 'atora-lms' ); ?>"><?php echo esc_textarea( (string) $testimonials ); ?></textarea>
			</p>
			<p>
				<label><strong><?php esc_html_e( 'Galería de imágenes', 'atora-lms' ); ?></strong> <?php esc_html_e( '(puedes arrastrar o seleccionar desde la biblioteca)', 'atora-lms' ); ?></label>
				<div class="clms-gallery-upload">
					<div class="clms-gallery-drop" id="clms_gallery_drop" tabindex="0" role="button" aria-label="<?php echo esc_attr__( 'Arrastra imágenes aquí', 'atora-lms' ); ?>">
						<span class="clms-gallery-drop-title"><?php esc_html_e( 'Arrastra imágenes aquí', 'atora-lms' ); ?></span>
						<span class="clms-gallery-drop-sub"><?php esc_html_e( 'o haz clic para seleccionar en la biblioteca', 'atora-lms' ); ?></span>
					</div>
					<div class="clms-gallery-actions">
						<button type="button" class="button" id="clms_gallery_pick"><?php esc_html_e( 'Seleccionar imágenes', 'atora-lms' ); ?></button>
						<button type="button" class="button" id="clms_gallery_clear"><?php esc_html_e( 'Quitar', 'atora-lms' ); ?></button>
					</div>
					<div class="clms-gallery-preview" id="clms_gallery_preview">
						<?php if ( ! empty( $gallery_items ) ) : ?>
							<?php foreach ( $gallery_items as $item ) : ?>
								<img src="<?php echo esc_url( $item['url'] ); ?>" alt="<?php echo esc_attr( $item['title'] ); ?>">
							<?php endforeach; ?>
						<?php else : ?>
							<span class="clms-gallery-empty"><?php esc_html_e( 'Sin imágenes seleccionadas.', 'atora-lms' ); ?></span>
						<?php endif; ?>
					</div>
					<input style="width:100%" type="text" name="_clms_commercial_gallery_ids" id="clms_commercial_gallery_ids" value="<?php echo esc_attr( (string) $gallery_ids ); ?>">
					<span class="description"><?php esc_html_e( 'IDs de medios separados por coma. Ejemplo: 42, 58, 73', 'atora-lms' ); ?></span>
				</div>
			</p>
			<p><label><strong><?php esc_html_e( 'URL de la CTA de compra', 'atora-lms' ); ?></strong></label><br>
				<input style="width:100%" type="url" name="_clms_commercial_cta_url" value="<?php echo esc_attr( (string) $cta_url ); ?>">
			</p>
		<p><label><strong><?php esc_html_e( 'Texto del botón CTA', 'atora-lms' ); ?></strong></label><br>
			<input style="width:100%" type="text" name="_clms_commercial_cta_label" value="<?php echo esc_attr( (string) $cta_label ); ?>" placeholder="<?php echo esc_attr__( 'Inscribirme ahora', 'atora-lms' ); ?>">
		</p>
		<p><label><strong><?php esc_html_e( 'Productos relacionados', 'atora-lms' ); ?></strong></label><br>
			<input style="width:100%" type="text" name="_clms_commercial_related_product_ids" value="<?php echo esc_attr( (string) $related_products ); ?>" placeholder="<?php echo esc_attr__( '12, 38, 44', 'atora-lms' ); ?>">
			<span class="description"><?php esc_html_e( 'IDs de productos WooCommerce para venta cruzada manual.', 'atora-lms' ); ?></span>
		</p>
		<p><label><strong><?php esc_html_e( 'Cursos relacionados', 'atora-lms' ); ?></strong></label><br>
			<input style="width:100%" type="text" name="_clms_commercial_related_course_ids" value="<?php echo esc_attr( (string) $related_courses ); ?>" placeholder="<?php echo esc_attr__( '101, 102', 'atora-lms' ); ?>">
		</p>
			<p><label><strong><?php esc_html_e( 'Programas relacionados', 'atora-lms' ); ?></strong></label><br>
				<input style="width:100%" type="text" name="_clms_commercial_related_program_ids" value="<?php echo esc_attr( (string) $related_programs ); ?>" placeholder="<?php echo esc_attr__( '201, 202', 'atora-lms' ); ?>">
			</p>
			<script>
			document.addEventListener('DOMContentLoaded', function(){
				var input   = document.getElementById('clms_commercial_gallery_ids');
				var pick    = document.getElementById('clms_gallery_pick');
				var drop    = document.getElementById('clms_gallery_drop');
				var clear   = document.getElementById('clms_gallery_clear');
				var preview = document.getElementById('clms_gallery_preview');
				if (!input) { return; }

				var frame;
				var emptyHtml = '<span class="clms-gallery-empty"><?php echo esc_js( __( 'Sin imágenes seleccionadas.', 'atora-lms' ) ); ?></span>';

				function renderPreview(items){
					if (!preview) return;
					if (!items || !items.length) { preview.innerHTML = emptyHtml; return; }
					var html = '';
					items.forEach(function(item){
						if (!item || !item.url) return;
						html += '<img src="' + item.url + '" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb">';
					});
					preview.innerHTML = html || emptyHtml;
				}

				function openFrame(){
					// wp.media puede no estar disponible hasta que el pie de página cargue.
					if (typeof wp === 'undefined' || !wp.media) {
						// Fallback: abrir selector de archivo nativo.
						var tmp = document.createElement('input');
						tmp.type = 'file';
						tmp.accept = 'image/*';
						tmp.multiple = true;
						tmp.click();
						return;
					}
					if (frame) { frame.open(); return; }
					frame = wp.media({
						title: '<?php echo esc_js( __( 'Seleccionar imágenes de galería', 'atora-lms' ) ); ?>',
						button: { text: '<?php echo esc_js( __( 'Usar imágenes seleccionadas', 'atora-lms' ) ); ?>' },
						multiple: true,
						library: { type: 'image' }
					});
					frame.on('select', function(){
						var selection = frame.state().get('selection');
						var ids = [], items = [];
						selection.each(function(att){
							var data = att.toJSON();
							if (!data || !data.id) return;
							ids.push(data.id);
							var thumb = (data.sizes && data.sizes.thumbnail) ? data.sizes.thumbnail.url : (data.url || '');
							items.push({ id: data.id, url: thumb });
						});
						input.value = ids.join(', ');
						renderPreview(items);
					});
					frame.open();
				}

				if (pick)  { pick.addEventListener('click', function(e){ e.preventDefault(); openFrame(); }); }
				if (drop)  {
					drop.addEventListener('click', function(e){ e.preventDefault(); openFrame(); });
					drop.addEventListener('keydown', function(e){
						if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openFrame(); }
					});
					drop.addEventListener('dragover',  function(e){ e.preventDefault(); drop.classList.add('is-dragover'); });
					drop.addEventListener('dragleave', function(){ drop.classList.remove('is-dragover'); });
					drop.addEventListener('drop',      function(e){ e.preventDefault(); drop.classList.remove('is-dragover'); openFrame(); });
				}
				if (clear) {
					clear.addEventListener('click', function(e){ e.preventDefault(); input.value = ''; renderPreview([]); });
				}
			});
			</script>
			<?php
		}

	public function render_academic_box( $post ) {
		wp_nonce_field( 'clms_save_course_academic', 'clms_course_academic_nonce' );

		$program_ids         = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_program_ids( $post->ID ) : array();
		$prerequisite_ids    = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_prerequisite_ids( $post->ID ) : array();
		$academic_fields     = $this->get_course_academic_fields();
		$academic_values     = array();
		foreach ( $academic_fields as $field_key => $field_config ) {
			$academic_values[ $field_key ] = (string) get_post_meta( $post->ID, $field_key, true );
		}
		$available_programs  = get_posts( array(
			'post_type'      => 'lm_program',
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		$available_courses   = get_posts( array(
			'post_type'      => 'lm_course',
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'post__not_in'   => array( $post->ID ),
		) );
		?>
		<p>
			<label for="clms_course_program_ids"><strong><?php esc_html_e( 'Programas', 'atora-lms' ); ?></strong></label><br>
			<select style="width:100%;min-height:110px" id="clms_course_program_ids" name="clms_course_program_ids[]" multiple>
				<?php foreach ( $available_programs as $program ) : ?>
					<option value="<?php echo esc_attr( $program->ID ); ?>" <?php selected( in_array( $program->ID, $program_ids, true ), true ); ?>>
						<?php echo esc_html( $program->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="clms_course_prerequisite_ids"><strong><?php esc_html_e( 'Prerrequisitos', 'atora-lms' ); ?></strong></label><br>
			<select style="width:100%;min-height:130px" id="clms_course_prerequisite_ids" name="clms_course_prerequisite_ids[]" multiple>
				<?php foreach ( $available_courses as $course ) : ?>
					<option value="<?php echo esc_attr( $course->ID ); ?>" <?php selected( in_array( $course->ID, $prerequisite_ids, true ), true ); ?>>
						<?php echo esc_html( $course->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<span class="description"><?php esc_html_e( 'Cmd+clic (Mac) o Ctrl+clic (Windows) para seleccionar varios o deseleccionar.', 'atora-lms' ); ?></span>
		</p>
		<p>
			<label>
				<input type="checkbox"
					name="_clms_auto_enroll"
					value="1"
					<?php checked( '1', get_post_meta( $post->ID, '_clms_auto_enroll', true ) ); ?> />
				<strong><?php esc_html_e( 'Auto-matricular nuevos estudiantes', 'atora-lms' ); ?></strong>
			</label><br>
			<span class="description"><?php esc_html_e( 'Al asignar el rol "lms_student" a un usuario sin cursos, se le inscribe aquí automáticamente.', 'atora-lms' ); ?></span>
		</p>
		<details style="margin-top:12px;border-top:1px solid #e5e7eb;padding-top:12px" open>
			<summary><strong><?php esc_html_e( 'Ficha académica del curso', 'atora-lms' ); ?></strong></summary>
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
		<?php $this->render_academic_diagnostics_panel( $post->ID ); ?>
		<?php
	}

	protected function render_academic_diagnostics_panel( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return;
		}

		$diagnostics_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Diagnostics_Service') : null;
		if ( ! $diagnostics_service && class_exists( 'CLMS_Academic_Diagnostics_Service' ) ) {
			$diagnostics_service = new CLMS_Academic_Diagnostics_Service();
		}
		if ( ! $diagnostics_service || ! method_exists( $diagnostics_service, 'diagnose_course' ) ) {
			return;
		}

		$diag      = (array) $diagnostics_service->diagnose_course( $course_id );
		$warnings  = isset( $diag['warnings'] ) && is_array( $diag['warnings'] ) ? $diag['warnings'] : array();
		$errors    = isset( $diag['errors'] ) && is_array( $diag['errors'] ) ? $diag['errors'] : array();
		$checklist = isset( $diag['checklist'] ) && is_array( $diag['checklist'] ) ? $diag['checklist'] : array();
		$maturity  = isset( $diag['maturity_checklist'] ) && is_array( $diag['maturity_checklist'] ) ? $diag['maturity_checklist'] : array();
		$action_urls = array(
			'course_editor'  => admin_url( 'post.php?post=' . absint( $course_id ) . '&action=edit' ),
			'commercial'     => admin_url( 'post.php?post=' . absint( $course_id ) . '&action=edit#clms_course_commercial' ),
			'lessons'        => admin_url( 'edit.php?post_type=lm_lesson' ),
			'certification'  => admin_url( 'admin.php?page=clms-academic-reports&course_id=' . absint( $course_id ) ),
		);
		?>
		<details style="margin-top:12px;border-top:1px solid #e5e7eb;padding-top:12px">
			<summary><strong><?php esc_html_e( 'Checklist: curso listo para certificar', 'atora-lms' ); ?></strong></summary>
			<div style="margin-top:10px">
				<?php if ( ! empty( $maturity ) ) : ?>
					<p style="margin:0 0 8px"><strong><?php esc_html_e( 'Madurez académica del curso', 'atora-lms' ); ?></strong></p>
					<ul style="margin:0;padding-left:18px;display:grid;gap:8px">
						<?php foreach ( array( 'publish', 'sell', 'evaluate', 'certify' ) as $m_key ) : ?>
							<?php
							$block  = isset( $maturity[ $m_key ] ) && is_array( $maturity[ $m_key ] ) ? $maturity[ $m_key ] : array();
							$label  = sanitize_text_field( (string) ( $block['label'] ?? '' ) );
							$status = sanitize_key( (string) ( $block['status'] ?? 'warning' ) );
							$icon   = 'warning' === $status ? '[!]' : ( 'error' === $status ? '[X]' : '[OK]' );
							$missing = isset( $block['missing'] ) && is_array( $block['missing'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $block['missing'] ) ) ) : array();
							$action  = sanitize_text_field( (string) ( $block['action'] ?? '' ) );
							$target  = sanitize_key( (string) ( $block['action_target'] ?? '' ) );
							$url     = isset( $action_urls[ $target ] ) ? $action_urls[ $target ] : '';
							?>
							<li>
								<strong><?php echo esc_html( $icon . ' ' . $label ); ?></strong>
								<?php if ( ! empty( $missing ) ) : ?>
									<div style="margin-top:4px"><?php echo esc_html( implode( ' · ', array_slice( $missing, 0, 4 ) ) ); ?></div>
								<?php endif; ?>
								<?php if ( '' !== $action ) : ?>
									<div style="margin-top:4px"><span class="description"><?php echo esc_html( $action ); ?></span></div>
								<?php endif; ?>
								<?php if ( '' !== $url ) : ?>
									<div style="margin-top:4px"><a class="button button-small" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Resolver ahora', 'atora-lms' ); ?></a></div>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<hr style="margin:10px 0">
				<?php endif; ?>

				<?php if ( empty( $checklist ) ) : ?>
					<p class="description"><?php esc_html_e( 'No hay datos suficientes para construir el checklist académico.', 'atora-lms' ); ?></p>
				<?php else : ?>
					<ul style="margin:0;padding-left:18px;display:grid;gap:6px">
						<?php foreach ( $checklist as $item ) : ?>
							<?php
							$item   = is_array( $item ) ? $item : array();
							$status = sanitize_key( (string) ( $item['status'] ?? 'warning' ) );
							$icon   = '-';
							if ( 'ok' === $status ) {
								$icon = '[OK]';
							} elseif ( 'error' === $status ) {
								$icon = '[X]';
							} elseif ( 'warning' === $status ) {
								$icon = '[!]';
							}
							$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
							$value = isset( $item['value'] ) ? sanitize_text_field( (string) $item['value'] ) : '';
							?>
							<li>
								<?php echo esc_html( $icon . ' ' . $label ); ?>
								<?php if ( '' !== $value ) : ?>
									<strong><?php echo esc_html( ': ' . $value ); ?></strong>
								<?php endif; ?>
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

	public function render_curriculum_preview_box( $post ) {
		$duplicate_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=clms_duplicate_course&course_id=' . absint( $post->ID ) ),
			'clms_duplicate_course_' . absint( $post->ID )
		);
		?>
		<p style="display:flex;justify-content:flex-end;margin:0 0 12px;">
			<a
				class="button button-secondary"
				href="<?php echo esc_url( $duplicate_url ); ?>"
				onclick="return confirm('<?php echo esc_js( __( '¿Duplicar este curso con todas sus lecciones?', 'atora-lms' ) ); ?>');"
			>
				<?php esc_html_e( 'Duplicar curso', 'atora-lms' ); ?>
			</a>
		</p>
		<div
			id="atora-curriculum-builder"
			data-course-id="<?php echo absint( $post->ID ); ?>"
		>
			<noscript>
				<p><?php esc_html_e( 'El constructor de malla curricular requiere JavaScript. Por favor actívalo en tu navegador.', 'atora-lms' ); ?></p>
			</noscript>
		</div>
		<?php
	}

	public function render_drip_timeline_box( $post ): void {
		$course_id = absint( $post->ID );
		$lessons   = $this->get_drip_timeline_lessons( $course_id );
		?>
		<div
			id="clms-drip-timeline"
			class="clms-drip-timeline-root"
			data-course-id="<?php echo esc_attr( $course_id ); ?>"
			data-lessons="<?php echo esc_attr( wp_json_encode( $lessons ) ); ?>"
		>
			<?php if ( empty( $lessons ) ) : ?>
				<p class="description"><?php esc_html_e( 'Este curso todavía no tiene lecciones para mostrar en el timeline.', 'atora-lms' ); ?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Arrastra cada barra para ajustar la liberación de las lecciones sin salir del curso.', 'atora-lms' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_drip_timeline_lessons( int $course_id ): array {
		if ( ! $course_id || ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );
		$lesson_ids = is_array( $lesson_ids ) ? array_map( 'absint', $lesson_ids ) : array();

		$lessons = array();
		foreach ( $lesson_ids as $lesson_id ) {
			if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
				continue;
			}

			$drip_type = sanitize_key( (string) get_post_meta( $lesson_id, '_clms_drip_type', true ) );
			if ( ! in_array( $drip_type, array( 'none', 'date', 'days_enrolled', 'days_after_previous' ), true ) ) {
				$drip_type = 'none';
			}

			$drip_date  = '';
			$drip_value = 0;
			if ( 'date' === $drip_type ) {
				$drip_date = (string) get_post_meta( $lesson_id, '_clms_drip_date', true );
			} elseif ( 'days_enrolled' === $drip_type ) {
				$drip_value = absint( get_post_meta( $lesson_id, '_clms_drip_days_enrolled', true ) );
			} elseif ( 'days_after_previous' === $drip_type ) {
				$drip_value = absint( get_post_meta( $lesson_id, '_clms_drip_days_after_previous', true ) );
			}

			$lessons[] = array(
				'id'         => $lesson_id,
				'title'      => get_the_title( $lesson_id ),
				'menu_order' => (int) get_post_field( 'menu_order', $lesson_id ),
				'module'     => (string) get_post_meta( $lesson_id, '_clms_lesson_module', true ),
				'drip_type'  => $drip_type,
				'drip_value' => $drip_value,
				'drip_date'  => $drip_date,
			);
		}

		usort(
			$lessons,
			static function ( array $a, array $b ): int {
				if ( (int) $a['menu_order'] === (int) $b['menu_order'] ) {
					return (int) $a['id'] <=> (int) $b['id'];
				}
				return (int) $a['menu_order'] <=> (int) $b['menu_order'];
			}
		);

		return array_values( $lessons );
	}

	public function render_teacher_box( $post ) {
		if ( ! $post || 'lm_course' !== $post->post_type || ! CLMS_Helper::user_can_manage_lms( $post->ID ) ) {
			return;
		}

		wp_nonce_field( 'clms_save_course_teachers', 'clms_course_teacher_nonce' );

		$teacher_ids = get_post_meta( $post->ID, '_clms_course_teacher_ids', true );
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
			<label for="clms_course_teacher_ids"><strong><?php esc_html_e( 'Seleccionar docentes', 'atora-lms' ); ?></strong></label><br>
			<div id="clms-course-teacher-order" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:8px 0 12px">
				<button type="button" class="button" data-clms-move="up"><?php esc_html_e( 'Subir', 'atora-lms' ); ?></button>
				<button type="button" class="button" data-clms-move="down"><?php esc_html_e( 'Bajar', 'atora-lms' ); ?></button>
				<span class="description"><?php esc_html_e( 'Usa subir o bajar para ordenar el equipo docente en la landing.', 'atora-lms' ); ?></span>
			</div>
			<select style="width:100%;min-height:120px" id="clms_course_teacher_ids" name="clms_course_teacher_ids[]" multiple>
				<?php foreach ( $ordered_teachers as $teacher ) : ?>
					<option value="<?php echo esc_attr( $teacher->ID ); ?>" <?php selected( in_array( $teacher->ID, $teacher_ids, true ), true ); ?>>
						<?php echo esc_html( $teacher->post_title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<div id="clms-course-teacher-preview" style="margin-top:10px">
				<strong><?php esc_html_e( 'Orden actual:', 'atora-lms' ); ?></strong>
				<ol style="margin:6px 0 0 18px"></ol>
			</div>
			<span class="description"><?php esc_html_e( 'Puedes asignar uno o varios docentes. Si no eliges ninguno, se mostrará el autor del curso como respaldo.', 'atora-lms' ); ?></span>
		</p>
		<script>
		(function() {
			var container = document.getElementById('clms-course-teacher-order');
			var select = document.getElementById('clms_course_teacher_ids');
			var preview = document.getElementById('clms-course-teacher-preview');
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
			<label for="clms_new_teacher_name"><?php esc_html_e( 'Nombre', 'atora-lms' ); ?></label><br>
			<input id="clms_new_teacher_name" type="text" name="clms_new_teacher_name" style="width:100%" placeholder="<?php echo esc_attr__( 'Nombre del docente', 'atora-lms' ); ?>">
		</p>
		<p>
			<label for="clms_new_teacher_short_bio"><?php esc_html_e( 'Bio corta', 'atora-lms' ); ?></label><br>
			<textarea id="clms_new_teacher_short_bio" name="clms_new_teacher_short_bio" rows="2" style="width:100%"></textarea>
		</p>
		<p>
			<label for="clms_new_teacher_specialty"><?php esc_html_e( 'Especialidad', 'atora-lms' ); ?></label><br>
			<input id="clms_new_teacher_specialty" type="text" name="clms_new_teacher_specialty" style="width:100%">
		</p>
		<p>
			<label>
				<input type="checkbox" name="clms_new_teacher_public" value="1" checked>
				<?php esc_html_e( 'Visible en páginas públicas', 'atora-lms' ); ?>
			</label>
		</p>
		<p class="description"><?php esc_html_e( 'La bio larga y la foto se completan en la ficha del docente.', 'atora-lms' ); ?></p>
		<?php
	}

}
