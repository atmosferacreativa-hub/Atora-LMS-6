<?php
/**
 * CLMS_UI_Course_Overview_Sections
 *
 * Callbacks de sección para la vista overview del curso (alumno inscrito o vista
 * académica del curso). Extiende los datos del template comercial con campos de
 * progreso, acceso y contenido del curso.
 *
 * Uso: CLMS_UI_Course_Overview_Sections::register_callbacks() — llamado vía load_ui_module().
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Course_Overview_Sections {

	// ── Cache de datos ─────────────────────────────────────────────────────────

	/** @var array<int,array> Cache de datos estáticos (no dependen del usuario) */
	private static array $static_cache = array();

	/**
	 * Carga datos del overview del curso en dos capas:
	 * - Capa 1 (cacheada): datos del curso que no dependen del usuario.
	 * - Capa 2 (al vuelo): enrollment, progreso y CTA del usuario actual.
	 *
	 * @param int $course_id
	 * @return array
	 */
	public static function data( int $course_id ): array {
		// ── Capa 1: datos estáticos del curso (cacheables) ───────────────────
		if ( ! isset( self::$static_cache[ $course_id ] ) ) {
			$base = class_exists( 'CLMS_UI_Course_Commercial_Sections', false )
				? CLMS_UI_Course_Commercial_Sections::data( $course_id )
				: array();

			$course_content = (string) get_post_field( 'post_content', $course_id );
			$course_excerpt = (string) get_post_field( 'post_excerpt', $course_id );
			if ( ! $course_excerpt ) {
				$course_excerpt = wp_trim_words( $course_content, 35 );
			}

			$course_thumbnail_id = get_post_thumbnail_id( $course_id );

			$instructor_ids_raw = get_post_meta( $course_id, '_clms_instructor_ids', true );
			if ( ! is_array( $instructor_ids_raw ) || empty( $instructor_ids_raw ) ) {
				$author_id          = (int) get_post_field( 'post_author', $course_id );
				$instructor_ids_raw = $author_id ? array( $author_id ) : array();
			}
			$instructor_names = array();
			foreach ( $instructor_ids_raw as $iid ) {
				$iuser = get_userdata( absint( $iid ) );
				if ( $iuser ) {
					$instructor_names[] = $iuser->display_name ?: $iuser->user_login;
				}
			}

			$overview_instructor_html = $base['instructor_html'] ?? '';
			if ( ! $overview_instructor_html && ! empty( $instructor_ids_raw ) ) {
				ob_start();
				foreach ( $instructor_ids_raw as $iid ) {
					$iid   = absint( $iid );
					$iuser = get_userdata( $iid );
					if ( ! $iuser ) {
						continue;
					}
					$i_name  = $iuser->display_name ?: $iuser->user_login;
					$i_bio   = (string) get_user_meta( $iid, 'description', true );
					$i_title = (string) get_user_meta( $iid, '_clms_instructor_title', true );
					$i_photo = get_user_meta( $iid, '_clms_instructor_photo_id', true );
					?>
					<div class="cov-instructor-card">
						<div class="cov-instructor-avatar">
							<?php if ( $i_photo ) : ?>
								<?php echo wp_get_attachment_image( absint( $i_photo ), 'thumbnail', false, array( 'alt' => esc_attr( $i_name ) ) ); ?>
							<?php else : ?>
								<?php echo get_avatar( $iid, 80, '', $i_name ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endif; ?>
						</div>
						<div class="cov-instructor-info">
							<p class="cov-instructor-name"><?php echo esc_html( $i_name ); ?></p>
							<?php if ( $i_title ) : ?>
								<p class="cov-instructor-role"><?php echo esc_html( $i_title ); ?></p>
							<?php endif; ?>
							<?php if ( $i_bio ) : ?>
								<div class="cov-instructor-bio"><p><?php echo esc_html( $i_bio ); ?></p></div>
							<?php endif; ?>
						</div>
					</div>
					<?php
				}
				$overview_instructor_html = (string) ob_get_clean();
			}

			$overview_instructor_title = count( $instructor_ids_raw ) > 1
				? __( 'Docentes del curso', 'atora-lms' )
				: __( 'Sobre el docente', 'atora-lms' );

			$type_labels = array(
				'lectura'    => __( 'Lección', 'atora-lms' ),
				'tarea'      => __( 'Tarea', 'atora-lms' ),
				'evaluacion' => __( 'Evaluación', 'atora-lms' ),
			);

			$academic_sheet = array(
				'objective_general'    => (string) get_post_meta( $course_id, '_clms_course_academic_objective_general', true ),
				'objectives_specific'  => self::normalize_lines( get_post_meta( $course_id, '_clms_course_academic_objectives_specific', true ) ),
				'competencies'         => self::normalize_lines( get_post_meta( $course_id, '_clms_course_competencies', true ) ),
				'learning_outcomes'    => self::normalize_lines( get_post_meta( $course_id, '_clms_course_learning_outcomes', true ) ),
				'entry_profile'        => (string) get_post_meta( $course_id, '_clms_course_entry_profile', true ),
				'exit_profile'         => (string) get_post_meta( $course_id, '_clms_course_exit_profile', true ),
				'methodology'          => (string) get_post_meta( $course_id, '_clms_course_methodology', true ),
				'evidence'             => self::normalize_lines( get_post_meta( $course_id, '_clms_course_evidence', true ) ),
				'evaluation_criteria'  => self::normalize_lines( get_post_meta( $course_id, '_clms_course_evaluation_criteria', true ) ),
				'duration_estimate'    => (string) get_post_meta( $course_id, '_clms_course_duration', true ),
				'difficulty'           => (string) get_post_meta( $course_id, '_clms_course_difficulty', true ),
				'modality'             => (string) get_post_meta( $course_id, '_clms_course_modality', true ),
				'prerequisites'        => (string) get_post_meta( $course_id, '_clms_course_prerequisites_text', true ),
				'certification'        => (string) get_post_meta( $course_id, '_clms_course_certification_text', true ),
			);

			self::$static_cache[ $course_id ] = array_merge(
				$base,
				compact(
					'course_content', 'course_excerpt', 'course_thumbnail_id',
					'instructor_ids_raw', 'instructor_names',
					'overview_instructor_html', 'overview_instructor_title',
					'type_labels', 'academic_sheet'
				)
			);
		}

		// ── Capa 2: datos dinámicos del usuario (siempre al vuelo) ───────────
		$lesson_ids = self::$static_cache[ $course_id ]['lesson_ids'] ?? array();

		$user_id     = get_current_user_id();
		$is_admin    = current_user_can( 'manage_options' ) || current_user_can( 'clms_manage_courses' );
		$is_enrolled = $is_admin;

		if ( $user_id && ! $is_admin && class_exists( 'CLMS_Helper' ) ) {
			$is_enrolled = (bool) CLMS_Helper::user_is_enrolled_in_course( $user_id, $course_id );
		}

		$completed = array();
		if ( $user_id ) {
			$raw       = get_user_meta( $user_id, '_clms_completed_lessons', true );
			$completed = is_array( $raw ) ? array_map( 'absint', $raw ) : array();
		}

		$total    = count( $lesson_ids );
		$done     = count( array_intersect( $lesson_ids, $completed ) );
		$progress = $total > 0 ? (int) round( $done / $total * 100 ) : 0;

		$cta_lesson_id    = 0;
		$cta_label_key    = 'Comenzar curso';
		$found_incomplete = false;

		if ( $user_id && $is_enrolled && ! empty( $lesson_ids ) && class_exists( 'CLMS_Helper' ) ) {
			foreach ( $lesson_ids as $lid ) {
				$lid = absint( $lid );
				if ( ! $lid ) {
					continue;
				}
				if ( $is_admin || CLMS_Helper::user_can_access_lesson( $user_id, $lid ) ) {
					if ( ! in_array( $lid, $completed, true ) && ! $found_incomplete ) {
						$cta_lesson_id    = $lid;
						$cta_label_key    = $done > 0 ? 'Continuar curso' : 'Comenzar curso';
						$found_incomplete = true;
					}
					if ( ! $cta_lesson_id ) {
						$cta_lesson_id = $lid;
					}
				}
			}
			if ( ! $cta_lesson_id && ! empty( $lesson_ids ) ) {
				$cta_lesson_id = end( $lesson_ids );
				$cta_label_key = 'Repasar curso';
			}
		}

		$competency_progress = self::build_competency_progress( $lesson_ids, $completed );

		return array_merge(
			self::$static_cache[ $course_id ],
			compact(
				'user_id', 'is_admin', 'is_enrolled',
				'completed', 'total', 'done', 'progress',
				'cta_lesson_id', 'cta_label_key',
				'competency_progress'
			)
		);
	}

	// ── Helper de partial ──────────────────────────────────────────────────────

	private static function include_partial( string $name, array $vars = array() ): void {
		$partial = trailingslashit( get_stylesheet_directory() ) . 'templates/partials/course/overview/' . $name;
		if ( ! file_exists( $partial ) ) {
			$partial = trailingslashit( ATORA_LMS_DIR ) . 'templates/partials/course/overview/' . $name;
		}
		if ( file_exists( $partial ) ) {
			if ( ! empty( $vars ) ) {
				extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			}
			include $partial;
		}
	}

	// ── Callbacks de render ────────────────────────────────────────────────────

	public static function render_hero( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( 'course_overview' !== $ctx->schema_context ) {
			CLMS_UI_Course_Commercial_Sections::render_hero( $section, $ctx, $schema );
			return;
		}
		$d         = self::data( $ctx->entity_id );
		$course_id = $ctx->entity_id;
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		self::include_partial( 'section-hero.php', get_defined_vars() );
	}

	public static function render_video( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['embed_src'] ) && empty( $d['hero_direct_video_url'] ) && empty( $d['hero_fallback_iframe_src'] ) && empty( $d['tagline'] ) ) {
			return;
		}
		$course_id = $ctx->entity_id;
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		self::include_partial( 'section-video.php', get_defined_vars() );
	}

	public static function render_about( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['course_content'] ) ) {
			return;
		}
		$course_id = $ctx->entity_id;
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		self::include_partial( 'section-about.php', get_defined_vars() );
	}

	public static function render_summary( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( 'course_overview' !== $ctx->schema_context ) {
			CLMS_UI_Course_Commercial_Sections::render_summary( $section, $ctx, $schema );
			return;
		}

		$d = self::data( $ctx->entity_id );

		$user_id      = absint( $d['user_id'] ?? 0 );
		$is_enrolled  = ! empty( $d['is_enrolled'] );
		$total        = max( 0, absint( $d['total'] ?? 0 ) );
		$done         = max( 0, absint( $d['done'] ?? 0 ) );
		$progress     = max( 0, min( 100, absint( $d['progress'] ?? 0 ) ) );
		$summary_items = isset( $d['course_include_items'] ) && is_array( $d['course_include_items'] )
			? array_values( array_filter( array_map( 'strval', $d['course_include_items'] ) ) )
			: array();

		if ( $user_id && $is_enrolled && $total > 0 ) {
			array_unshift(
				$summary_items,
				sprintf(
					/* translators: 1: completed lessons, 2: total lessons */
					__( 'Lecciones completadas: %1$d de %2$d', 'atora-lms' ),
					$done,
					$total
				)
			);
		}

		$note = '';
		if ( ! $user_id ) {
			$note = __( 'Inicia sesión para guardar tu progreso en este curso.', 'atora-lms' );
		} elseif ( ! $is_enrolled ) {
			$note = __( 'Inscríbete para desbloquear el seguimiento de progreso y la continuidad de lecciones.', 'atora-lms' );
		}

		if ( empty( $summary_items ) && '' === $note && ( ! $user_id || ! $is_enrolled ) ) {
			return;
		}
		?>
		<div class="cov-about">
			<h2 class="cov-section-title"><?php esc_html_e( 'Resumen académico', 'atora-lms' ); ?></h2>
			<div class="cov-about-content">
				<?php if ( $user_id && $is_enrolled && $total > 0 ) : ?>
					<div class="cov-progress-row">
						<div class="cov-progress-bar-wrap">
							<div class="cov-progress-bar" style="width:<?php echo esc_attr( $progress ); ?>%"></div>
						</div>
						<span class="cov-progress-label">
							<?php
							printf(
								/* translators: 1: progress percent, 2: completed lessons, 3: total lessons */
								esc_html__( '%1$d%% completado (%2$d/%3$d)', 'atora-lms' ),
								$progress,
								$done,
								$total
							);
							?>
						</span>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $summary_items ) ) : ?>
					<div class="cov-meta-row">
						<?php foreach ( $summary_items as $item ) : ?>
							<span class="cov-meta-item"><?php echo esc_html( $item ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( '' !== $note ) : ?>
					<p class="cov-empty"><?php echo esc_html( $note ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function render_academic( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( 'course_overview' !== $ctx->schema_context ) {
			return;
		}

		$d = self::data( $ctx->entity_id );
		$sheet = isset( $d['academic_sheet'] ) && is_array( $d['academic_sheet'] ) ? $d['academic_sheet'] : array();
		$competency_progress = isset( $d['competency_progress'] ) && is_array( $d['competency_progress'] ) ? $d['competency_progress'] : array();
		$has_content = false;

		foreach ( $sheet as $value ) {
			if ( ( is_array( $value ) && ! empty( $value ) ) || ( is_string( $value ) && '' !== trim( $value ) ) ) {
				$has_content = true;
				break;
			}
		}

		if ( ! $has_content && empty( $competency_progress ) ) {
			return;
		}
		?>
		<div class="cov-about">
			<h2 class="cov-section-title"><?php esc_html_e( 'Ficha académica del curso', 'atora-lms' ); ?></h2>
			<div class="cov-about-content">
				<?php if ( ! empty( $sheet['objective_general'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Objetivo general:', 'atora-lms' ); ?></strong> <?php echo esc_html( $sheet['objective_general'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $sheet['objectives_specific'] ) ) : ?>
					<details><summary><?php esc_html_e( 'Objetivos específicos', 'atora-lms' ); ?></summary><ul><?php foreach ( $sheet['objectives_specific'] as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></details>
				<?php endif; ?>
				<?php if ( ! empty( $sheet['learning_outcomes'] ) ) : ?>
					<details><summary><?php esc_html_e( 'Resultados de aprendizaje', 'atora-lms' ); ?></summary><ul><?php foreach ( $sheet['learning_outcomes'] as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></details>
				<?php endif; ?>
				<?php if ( ! empty( $sheet['competencies'] ) ) : ?>
					<details><summary><?php esc_html_e( 'Competencias esperadas', 'atora-lms' ); ?></summary><ul><?php foreach ( $sheet['competencies'] as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul></details>
				<?php endif; ?>
				<?php if ( ! empty( $competency_progress ) ) : ?>
					<details><summary><?php esc_html_e( 'Tu avance por competencias', 'atora-lms' ); ?></summary>
						<?php if ( ! empty( $competency_progress['developed'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Competencias desarrolladas:', 'atora-lms' ); ?></strong> <?php echo esc_html( implode( ' · ', $competency_progress['developed'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $competency_progress['in_progress'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Competencias en progreso:', 'atora-lms' ); ?></strong> <?php echo esc_html( implode( ' · ', $competency_progress['in_progress'] ) ); ?></p>
						<?php endif; ?>
						<p><strong><?php esc_html_e( 'Evidencias completadas:', 'atora-lms' ); ?></strong> <?php echo esc_html( absint( $competency_progress['evidences_done'] ?? 0 ) ); ?>/<?php echo esc_html( absint( $competency_progress['evidences_total'] ?? 0 ) ); ?></p>
						<?php if ( ! empty( $competency_progress['recommendation'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Recomendación:', 'atora-lms' ); ?></strong> <?php echo esc_html( $competency_progress['recommendation'] ); ?></p>
						<?php endif; ?>
					</details>
				<?php endif; ?>
				<?php if ( ! empty( $sheet['entry_profile'] ) || ! empty( $sheet['exit_profile'] ) || ! empty( $sheet['methodology'] ) || ! empty( $sheet['evaluation_criteria'] ) || ! empty( $sheet['evidence'] ) || ! empty( $sheet['prerequisites'] ) || ! empty( $sheet['certification'] ) ) : ?>
					<details><summary><?php esc_html_e( 'Información complementaria', 'atora-lms' ); ?></summary>
						<?php if ( ! empty( $sheet['entry_profile'] ) ) : ?><p><strong><?php esc_html_e( 'Perfil de ingreso:', 'atora-lms' ); ?></strong> <?php echo esc_html( $sheet['entry_profile'] ); ?></p><?php endif; ?>
						<?php if ( ! empty( $sheet['exit_profile'] ) ) : ?><p><strong><?php esc_html_e( 'Perfil de egreso:', 'atora-lms' ); ?></strong> <?php echo esc_html( $sheet['exit_profile'] ); ?></p><?php endif; ?>
						<?php if ( ! empty( $sheet['methodology'] ) ) : ?><p><strong><?php esc_html_e( 'Metodología:', 'atora-lms' ); ?></strong> <?php echo esc_html( $sheet['methodology'] ); ?></p><?php endif; ?>
						<?php if ( ! empty( $sheet['evidence'] ) ) : ?><p><strong><?php esc_html_e( 'Evidencias evaluables:', 'atora-lms' ); ?></strong> <?php echo esc_html( implode( ' · ', $sheet['evidence'] ) ); ?></p><?php endif; ?>
						<?php if ( ! empty( $sheet['evaluation_criteria'] ) ) : ?><p><strong><?php esc_html_e( 'Criterios de evaluación:', 'atora-lms' ); ?></strong> <?php echo esc_html( implode( ' · ', $sheet['evaluation_criteria'] ) ); ?></p><?php endif; ?>
						<?php if ( ! empty( $sheet['duration_estimate'] ) ) : ?><p><strong><?php esc_html_e( 'Duración estimada:', 'atora-lms' ); ?></strong> <?php echo esc_html( $sheet['duration_estimate'] ); ?></p><?php endif; ?>
						<?php if ( ! empty( $sheet['difficulty'] ) ) : ?><p><strong><?php esc_html_e( 'Dificultad:', 'atora-lms' ); ?></strong> <?php echo esc_html( ucfirst( $sheet['difficulty'] ) ); ?></p><?php endif; ?>
						<?php if ( ! empty( $sheet['modality'] ) ) : ?><p><strong><?php esc_html_e( 'Modalidad:', 'atora-lms' ); ?></strong> <?php echo esc_html( ucfirst( $sheet['modality'] ) ); ?></p><?php endif; ?>
						<?php if ( ! empty( $sheet['prerequisites'] ) ) : ?><p><strong><?php esc_html_e( 'Requisitos previos:', 'atora-lms' ); ?></strong> <?php echo esc_html( $sheet['prerequisites'] ); ?></p><?php endif; ?>
						<?php if ( ! empty( $sheet['certification'] ) ) : ?><p><strong><?php esc_html_e( 'Certificación:', 'atora-lms' ); ?></strong> <?php echo esc_html( $sheet['certification'] ); ?></p><?php endif; ?>
					</details>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function render_instructor( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( 'course_overview' !== $ctx->schema_context ) {
			CLMS_UI_Course_Commercial_Sections::render_instructor( $section, $ctx, $schema );
			return;
		}
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['overview_instructor_html'] ) ) {
			return;
		}
		$course_id = $ctx->entity_id;
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		self::include_partial( 'section-instructor.php', get_defined_vars() );
	}

	public static function render_profiles( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( 'course_overview' !== $ctx->schema_context ) {
			CLMS_UI_Course_Commercial_Sections::render_profiles( $section, $ctx, $schema );
			return;
		}
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['ingress'] ) && empty( $d['egress'] ) ) {
			return;
		}
		$course_id = $ctx->entity_id;
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		self::include_partial( 'section-profiles.php', get_defined_vars() );
	}

	public static function render_curriculum( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( 'course_overview' !== $ctx->schema_context ) {
			CLMS_UI_Course_Commercial_Sections::render_curriculum( $section, $ctx, $schema );
			return;
		}
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['lesson_ids'] ) ) {
			return;
		}
		$course_id = $ctx->entity_id;
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		self::include_partial( 'section-curriculum.php', get_defined_vars() );
	}

	public static function render_gallery( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( 'course_overview' !== $ctx->schema_context ) {
			CLMS_UI_Course_Commercial_Sections::render_gallery( $section, $ctx, $schema );
			return;
		}
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['gallery_image_ids'] ) ) {
			return;
		}
		$course_id        = $ctx->entity_id;
		$gallery_interactive = $d['is_admin'] || ( $d['user_id'] && $d['is_enrolled'] );
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		self::include_partial( 'section-gallery.php', get_defined_vars() );
	}

	public static function render_testimonials( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( 'course_overview' !== $ctx->schema_context ) {
			CLMS_UI_Course_Commercial_Sections::render_testimonials( $section, $ctx, $schema );
			return;
		}
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['testimonial_items'] ) ) {
			return;
		}
		$course_id = $ctx->entity_id;
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		self::include_partial( 'section-testimonials.php', get_defined_vars() );
	}

	public static function render_faq( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( 'course_overview' !== $ctx->schema_context ) {
			CLMS_UI_Course_Commercial_Sections::render_faq( $section, $ctx, $schema );
			return;
		}
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['faq_items'] ) ) {
			return;
		}
		$course_id = $ctx->entity_id;
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		self::include_partial( 'section-faq.php', get_defined_vars() );
	}

	public static function render_cta( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( 'course_overview' !== $ctx->schema_context ) {
			CLMS_UI_Course_Commercial_Sections::render_cta( $section, $ctx, $schema );
			return;
		}

		$d            = self::data( $ctx->entity_id );
		$user_id      = absint( $d['user_id'] ?? 0 );
		$is_enrolled  = ! empty( $d['is_enrolled'] );
		$cta_lesson_id = absint( $d['cta_lesson_id'] ?? 0 );

		// Para no duplicar el footer legacy del wrapper, aquí solo renderizamos
		// el CTA académico cuando hay continuidad real de lección.
		if ( ! $user_id || ! $is_enrolled || ! $cta_lesson_id ) {
			return;
		}

		$cta_url = get_permalink( $cta_lesson_id );
		if ( ! $cta_url ) {
			return;
		}

		$cta_label_key = (string) ( $d['cta_label_key'] ?? 'Continuar curso' );
		$done          = max( 0, absint( $d['done'] ?? 0 ) );
		$total         = max( 0, absint( $d['total'] ?? 0 ) );
		$cta_note      = __( 'Avanza con la siguiente lección recomendada.', 'atora-lms' );

		if ( $total > 0 && $done >= $total ) {
			$cta_note = __( 'Ya completaste el curso. Puedes repasar los contenidos clave.', 'atora-lms' );
		} elseif ( $done > 0 ) {
			$cta_note = __( 'Retoma tu avance donde lo dejaste.', 'atora-lms' );
		}
		?>
		<div class="cov-cta-footer">
			<p class="cov-cta-footer-kicker"><?php esc_html_e( 'Siguiente paso', 'atora-lms' ); ?></p>
			<h2 class="cov-cta-footer-title"><?php echo esc_html( __( $cta_label_key, 'atora-lms' ) ); // phpcs:ignore WordPress.WP.I18n ?></h2>
			<p class="cov-cta-footer-sub"><?php echo esc_html( $cta_note ); ?></p>
			<a class="cov-btn cov-btn-primary" href="<?php echo esc_url( $cta_url ); ?>">
				<?php echo esc_html( __( $cta_label_key, 'atora-lms' ) ); // phpcs:ignore WordPress.WP.I18n ?>
			</a>
		</div>
		<?php
	}

	// ── Registro en el registry ────────────────────────────────────────────────

	/**
	 * Registra los callbacks en el Section Registry.
	 * Envuelve los callbacks existentes (comercial) para hacer dispatch por contexto.
	 * Llamado vía load_ui_module() DESPUÉS de CLMS_UI_Course_Commercial_Sections::register_callbacks().
	 */
	public static function register_callbacks(): void {
		if ( ! class_exists( 'CLMS_UI_Section_Registry', false ) ) {
			return;
		}

		$registry = CLMS_UI_Section_Registry::instance();

		$callbacks = array(
			'hero'         => array( self::class, 'render_hero' ),
			'video'        => array( self::class, 'render_video' ),
			'about'        => array( self::class, 'render_about' ),
			'academic'     => array( self::class, 'render_academic' ),
			'summary'      => array( self::class, 'render_summary' ),
			'instructor'   => array( self::class, 'render_instructor' ),
			'profiles'     => array( self::class, 'render_profiles' ),
			'curriculum'   => array( self::class, 'render_curriculum' ),
			'gallery'      => array( self::class, 'render_gallery' ),
			'testimonials' => array( self::class, 'render_testimonials' ),
			'faq'          => array( self::class, 'render_faq' ),
			'cta'          => array( self::class, 'render_cta' ),
		);

		$labels = array(
			'hero'         => __( 'Cabecera / Hero', 'atora-lms' ),
			'video'        => __( 'Video del curso', 'atora-lms' ),
			'about'        => __( 'Descripción del curso', 'atora-lms' ),
			'academic'     => __( 'Ficha académica', 'atora-lms' ),
			'summary'      => __( 'Resumen académico', 'atora-lms' ),
			'instructor'   => __( 'Docentes', 'atora-lms' ),
			'profiles'     => __( 'Perfiles', 'atora-lms' ),
			'curriculum'   => __( 'Contenido del curso', 'atora-lms' ),
			'gallery'      => __( 'Galería', 'atora-lms' ),
			'testimonials' => __( 'Testimonios', 'atora-lms' ),
			'faq'          => __( 'Preguntas frecuentes', 'atora-lms' ),
			'cta'          => __( 'Llamada a la acción', 'atora-lms' ),
		);

		foreach ( $callbacks as $id => $cb ) {
			$section = $registry->get( $id );
			if ( ! $section ) {
				$registry->register(
					$id,
					array(
						'label'    => $labels[ $id ] ?? $id,
						'contexts' => array( 'course_overview' ),
					)
				);
				$section = $registry->get( $id );
			}
			if ( ! $section ) {
				continue;
			}

			$contexts            = isset( $section['contexts'] ) && is_array( $section['contexts'] ) ? $section['contexts'] : array();
			$section['contexts'] = array_values( array_unique( array_merge( $contexts, array( 'course_overview' ) ) ) );
			$section['render_callback'] = $cb;
			$registry->register( $id, $section );
		}
	}

	private static function normalize_lines( $raw ): array {
		if ( is_array( $raw ) ) {
			$lines = $raw;
		} else {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		}
		return array_values(
			array_filter(
				array_map(
					static function( $line ) {
						return sanitize_text_field( (string) $line );
					},
					(array) $lines
				),
				static function( $line ) {
					return '' !== trim( (string) $line );
				}
			)
		);
	}

	private static function build_competency_progress( array $lesson_ids, array $completed_lessons ): array {
		$lesson_ids = array_values( array_filter( array_map( 'absint', $lesson_ids ) ) );
		if ( empty( $lesson_ids ) ) {
			return array();
		}

		$completed_map = array_fill_keys( array_map( 'absint', $completed_lessons ), true );
		$competency_map = array();

		foreach ( $lesson_ids as $lesson_id ) {
			$lines = self::normalize_lines( get_post_meta( $lesson_id, '_clms_lesson_competencies', true ) );
			foreach ( $lines as $competency ) {
				if ( ! isset( $competency_map[ $competency ] ) ) {
					$competency_map[ $competency ] = array( 'total' => 0, 'done' => 0 );
				}
				$competency_map[ $competency ]['total']++;
				if ( isset( $completed_map[ $lesson_id ] ) ) {
					$competency_map[ $competency ]['done']++;
				}
			}
		}

		if ( empty( $competency_map ) ) {
			return array();
		}

		$developed = array();
		$in_progress = array();
		foreach ( $competency_map as $competency => $stats ) {
			$total = max( 1, absint( $stats['total'] ) );
			$done  = absint( $stats['done'] );
			$ratio = $done / $total;
			if ( $ratio >= 1 ) {
				$developed[] = $competency;
			} elseif ( $ratio > 0 ) {
				$in_progress[] = $competency;
			}
		}

		$recommendation = '';
		if ( ! empty( $in_progress[0] ) ) {
			$recommendation = sprintf(
				/* translators: %s: competencia */
				__( 'Refuerza la competencia "%s" con una práctica adicional esta semana.', 'atora-lms' ),
				$in_progress[0]
			);
		}

		return array(
			'developed'      => array_values( array_unique( $developed ) ),
			'in_progress'    => array_values( array_unique( $in_progress ) ),
			'evidences_done' => count( array_intersect( $lesson_ids, array_keys( $completed_map ) ) ),
			'evidences_total'=> count( $lesson_ids ),
			'recommendation' => $recommendation,
		);
	}
}
