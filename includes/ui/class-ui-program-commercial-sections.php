<?php
/**
 * CLMS_UI_Program_Commercial_Sections
 *
 * Render de secciones para programa en dos contextos:
 * - program_commercial (landing pública/comercial)
 * - program_overview   (vista académica del alumno)
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Program_Commercial_Sections {

	/** @var array<int,array<string,mixed>> */
	private static array $cache = array();

	/** @var array<string,callable> */
	private static array $fallback_callbacks = array();

	/**
	 * Carga y cachea datos de programa para render UI.
	 */
	public static function data( int $program_id ): array {
		if ( ! isset( self::$cache[ $program_id ] ) ) {
			// === DATOS ESTÁTICOS (cacheables, no dependen del usuario) ===
			$title             = (string) get_the_title( $program_id );
			$subtitle          = (string) get_post_meta( $program_id, '_clms_program_subtitle', true );
			$duration          = (string) get_post_meta( $program_id, '_clms_program_duration', true );
			$tagline           = (string) get_post_meta( $program_id, '_clms_commercial_tagline', true );
			$price             = (string) get_post_meta( $program_id, '_clms_program_price', true );
			$price_label       = (string) get_post_meta( $program_id, '_clms_program_price_label', true );
			$hero_video        = (string) get_post_meta( $program_id, '_clms_commercial_hero_video', true );
			$hero_src          = (string) get_post_meta( $program_id, '_clms_commercial_hero_video_source', true ) ?: 'youtube';
			$program_permalink = (string) get_permalink( $program_id );

			$offer_data    = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_entity_offer_data( $program_id ) : array();
			$cta_url       = $offer_data['url'] ?? '';
			$cta_label     = $offer_data['label'] ?? '';
			$offer_source  = sanitize_key( (string) ( $offer_data['source'] ?? 'none' ) );
			$product_id    = class_exists( 'CLMS_Helper' ) ? absint( CLMS_Helper::get_entity_linked_product_id( $program_id ) ) : 0;
			$product_title = $product_id ? (string) get_the_title( $product_id ) : '';
			$product_url   = $product_id ? (string) get_permalink( $product_id ) : '';
			$modules       = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_entity_commercial_modules( $program_id ) : array();
			$related_items = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_commercial_related_items( $program_id, 6 ) : array();

			$modules       = is_array( $modules ) ? array_values( $modules ) : array();
			$related_items = is_array( $related_items ) ? array_values( $related_items ) : array();

			if ( '' === trim( $cta_url ) ) {
				$cta_url = (string) get_post_meta( $program_id, '_clms_commercial_cta_url', true );
			}
			if ( '' === trim( $cta_label ) ) {
				$cta_label = (string) get_post_meta( $program_id, '_clms_commercial_cta_label', true );
				if ( '' === trim( $cta_label ) ) {
					$cta_label = __( 'Comprar programa', 'atora-lms' );
				}
			}
			if ( '' === trim( $price_label ) && ! empty( $offer_data['price_label'] ) ) {
				$price_label = (string) $offer_data['price_label'];
			}
			if ( '' === trim( $price ) && ! empty( $offer_data['price'] ) ) {
				$price = (string) $offer_data['price'];
			}

			$program_excerpt = trim( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $program_id ) ) );
			if ( '' === $program_excerpt ) {
				$program_excerpt = trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $program_id ) ) );
			}
			if ( '' !== $program_excerpt ) {
				$program_excerpt = wp_trim_words( $program_excerpt, 38, '...' );
			}

			$academic_sheet = array(
				'objective_general'   => (string) get_post_meta( $program_id, '_clms_program_objective_general', true ),
				'competencies'        => self::normalize_lines( get_post_meta( $program_id, '_clms_program_competencies', true ) ),
				'learning_outcomes'   => self::normalize_lines( get_post_meta( $program_id, '_clms_program_learning_outcomes', true ) ),
				'methodology'         => (string) get_post_meta( $program_id, '_clms_program_methodology', true ),
				'evidence'            => self::normalize_lines( get_post_meta( $program_id, '_clms_program_evidence', true ) ),
				'evaluation_criteria' => self::normalize_lines( get_post_meta( $program_id, '_clms_program_evaluation_criteria', true ) ),
				'difficulty'          => (string) get_post_meta( $program_id, '_clms_program_difficulty', true ),
				'modality'            => (string) get_post_meta( $program_id, '_clms_program_modality', true ),
				'entry_profile'       => (string) get_post_meta( $program_id, '_clms_program_entry_profile', true ),
				'exit_profile'        => (string) get_post_meta( $program_id, '_clms_program_exit_profile', true ),
				'certification'       => (string) get_post_meta( $program_id, '_clms_program_certification', true ),
			);
			$has_academic_sheet = false;
			foreach ( $academic_sheet as $value ) {
				if ( ( is_array( $value ) && ! empty( $value ) ) || ( is_string( $value ) && '' !== trim( $value ) ) ) {
					$has_academic_sheet = true;
					break;
				}
			}

			$difficulty_labels = array(
				'basico'     => __( 'Básico', 'atora-lms' ),
				'intermedio' => __( 'Intermedio', 'atora-lms' ),
				'avanzado'   => __( 'Avanzado', 'atora-lms' ),
			);
			$modality_labels = array(
				'asincrona'  => __( 'Asíncrona', 'atora-lms' ),
				'sincrona'   => __( 'Síncrona', 'atora-lms' ),
				'hibrida'    => __( 'Híbrida', 'atora-lms' ),
				'presencial' => __( 'Presencial', 'atora-lms' ),
			);

			$related_type_labels = array(
				'course'     => __( 'Curso', 'atora-lms' ),
				'curso'      => __( 'Curso', 'atora-lms' ),
				'program'    => __( 'Programa', 'atora-lms' ),
				'programa'   => __( 'Programa', 'atora-lms' ),
				'mentoria'   => __( 'Mentoría', 'atora-lms' ),
				'mentoring'  => __( 'Mentoría', 'atora-lms' ),
				'bundle'     => __( 'Paquete', 'atora-lms' ),
				'membership' => __( 'Membresía', 'atora-lms' ),
				'recurso'    => __( 'Recurso', 'atora-lms' ),
				'resource'   => __( 'Recurso', 'atora-lms' ),
				'podcast'    => __( 'Podcast', 'atora-lms' ),
				'book'       => __( 'Libro', 'atora-lms' ),
			);

			$instructor_html       = '';
			$teacher_section_title = __( 'Docente líder', 'atora-lms' );
			$teacher_ids           = get_post_meta( $program_id, '_clms_program_teacher_ids', true );
			$teacher_ids           = is_array( $teacher_ids ) ? array_values( array_filter( array_map( 'absint', $teacher_ids ) ) ) : array();
			if ( count( $teacher_ids ) > 1 ) {
				$teacher_section_title = __( 'Equipo docente', 'atora-lms' );
			}

			if ( class_exists( 'CLMS_Instructor' ) ) {
				$instructor = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'module' )
					? clms_core('CLMS_Instructor')
					: null;
				if ( ! $instructor ) {
					$instructor = new CLMS_Instructor();
				}
				if ( method_exists( $instructor, 'get_teachers_html_for_program' ) ) {
					$instructor_html = $instructor->get_teachers_html_for_program(
						$program_id,
						array(
							'show_bio'          => true,
							'show_specialty'    => true,
							'show_achievements' => true,
							'show_socials'      => true,
							'show_profile_link' => true,
						)
					);
				}
			}

			$embed_src             = '';
			$hero_direct_video_url = '';
			if ( '' !== trim( $hero_video ) ) {
				$hero_video = trim( $hero_video );
				if ( 'youtube' === $hero_src && preg_match( '#(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([a-zA-Z0-9_-]{11})#', $hero_video, $m ) ) {
					$embed_src = 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0&showinfo=0';
				} elseif ( 'vimeo' === $hero_src && preg_match( '#vimeo\.com/(?:video/)?(\d+)#', $hero_video, $m ) ) {
					$embed_src = 'https://player.vimeo.com/video/' . $m[1];
				} elseif ( 'url' === $hero_src && preg_match( '/\.(?:mp4|webm|ogg|m4v|mov)(?:\?.*)?$/i', $hero_video ) ) {
					$hero_direct_video_url = $hero_video;
				}
			}

			self::$cache[ $program_id ] = compact(
				'title', 'subtitle', 'duration', 'tagline', 'price', 'price_label',
				'offer_data', 'offer_source', 'product_id', 'product_title', 'product_url', 'cta_url', 'cta_label',
				'modules', 'related_items', 'program_excerpt',
				'related_type_labels', 'hero_video', 'hero_src',
				'academic_sheet', 'has_academic_sheet', 'difficulty_labels', 'modality_labels',
				'instructor_html', 'teacher_section_title',
				'embed_src', 'hero_direct_video_url', 'program_permalink'
			);
		}

		// === DATOS DINÁMICOS (dependen del usuario, siempre al vuelo) ===
		$user_id     = get_current_user_id();
		$is_admin    = current_user_can( 'manage_options' ) || current_user_can( 'clms_manage_courses' );
		$is_enrolled = $is_admin;
		if ( $user_id && ! $is_admin && class_exists( 'CLMS_Helper' ) ) {
			$is_enrolled = (bool) CLMS_Helper::user_is_enrolled_in_program( $user_id, $program_id );
		}

		$curriculum         = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_program_curriculum( $program_id, $user_id ) : array();
		$curriculum_courses = ! empty( $curriculum['courses'] ) && is_array( $curriculum['courses'] ) ? array_values( $curriculum['courses'] ) : array();
		$courses_count      = count( $curriculum_courses );

		// Si los módulos comerciales estáticos están vacíos, derivarlos del curriculum
		$modules = self::$cache[ $program_id ]['modules'];
		if ( empty( $modules ) && ! empty( $curriculum_courses ) ) {
			$modules = array();
			foreach ( $curriculum_courses as $course ) {
				$course_id = absint( $course['id'] ?? 0 );
				if ( ! $course_id ) {
					continue;
				}
				$modules[] = array(
					'id'               => $course_id,
					'type'             => 'course',
					'title'            => sanitize_text_field( (string) ( $course['title'] ?? '' ) ),
					'subtitle'         => (string) get_post_meta( $course_id, '_clms_course_subtitle', true ),
					'url'              => esc_url_raw( (string) ( $course['url'] ?? '' ) ),
					'lesson_count'     => absint( $course['lesson_count'] ?? 0 ),
					'progress_percent' => absint( $course['progress_percent'] ?? 0 ),
					'prerequisites_met' => ! empty( $course['prerequisites_met'] ),
				);
			}
		}

		$total_lessons     = 0;
		$done_lessons      = 0;
		$completed_courses = 0;
		$next_course_url   = '';
		$first_course_url  = '';

		foreach ( $curriculum_courses as $course ) {
			$lesson_count   = absint( $course['lesson_count'] ?? 0 );
			$progress_value = max( 0, min( 100, absint( $course['progress_percent'] ?? 0 ) ) );
			$course_url     = esc_url_raw( (string) ( $course['url'] ?? '' ) );

			$total_lessons += $lesson_count;
			$done_lessons  += (int) round( ( $progress_value / 100 ) * $lesson_count );

			if ( $progress_value >= 100 ) {
				$completed_courses++;
			}

			if ( '' === $first_course_url && '' !== trim( $course_url ) ) {
				$first_course_url = $course_url;
			}

			if ( '' === $next_course_url && '' !== trim( $course_url ) && ! empty( $course['prerequisites_met'] ) ) {
				$next_course_url = $course_url;
			}
		}

		if ( '' === $next_course_url ) {
			$next_course_url = $first_course_url;
		}

		$program_progress = $total_lessons > 0
			? max( 0, min( 100, (int) round( ( $done_lessons / $total_lessons ) * 100 ) ) )
			: 0;

		$cached_duration = self::$cache[ $program_id ]['duration'];
		$summary_items   = array();
		if ( '' !== trim( $cached_duration ) ) {
			$summary_items[] = sprintf( __( 'Duración estimada: %s', 'atora-lms' ), $cached_duration );
		}
		if ( $courses_count > 0 ) {
			$summary_items[] = sprintf(
				/* translators: %d: course count */
				_n( '%d curso en la malla', '%d cursos en la malla', $courses_count, 'atora-lms' ),
				$courses_count
			);
		}
		if ( $is_enrolled && $courses_count > 0 ) {
			$summary_items[] = sprintf(
				/* translators: 1: completed courses, 2: total courses */
				__( 'Cursos completados: %1$d de %2$d', 'atora-lms' ),
				$completed_courses,
				$courses_count
			);
		}
		if ( $is_enrolled && $total_lessons > 0 ) {
			$summary_items[] = sprintf(
				/* translators: 1: completed lessons, 2: total lessons */
				__( 'Lecciones completadas: %1$d de %2$d', 'atora-lms' ),
				$done_lessons,
				$total_lessons
			);
		}
		if ( ! empty( $summary_items ) ) {
			$summary_items = array_slice( array_values( $summary_items ), 0, 6 );
		}

		return array_merge(
			self::$cache[ $program_id ],
			compact(
				'user_id', 'is_admin', 'is_enrolled',
				'curriculum_courses', 'courses_count', 'modules',
				'total_lessons', 'done_lessons', 'completed_courses',
				'program_progress', 'next_course_url',
				'summary_items'
			)
		);
	}

	public static function render_hero( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( ! self::is_program_context( $ctx->schema_context ) ) {
			self::dispatch_to_fallback( 'hero', $section, $ctx, $schema );
			return;
		}

		$is_overview = self::is_program_overview_context( $ctx->schema_context );
		$d           = self::data( $ctx->entity_id );
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract

		$quick_links = array();
		if ( ! empty( $program_excerpt ) || ! empty( $summary_items ) ) {
			$quick_links['apl-summary'] = __( 'Resumen', 'atora-lms' );
		}
		if ( $is_overview && ! empty( $courses_count ) ) {
			$quick_links['apl-progress'] = __( 'Progreso', 'atora-lms' );
		}
		if ( ! empty( $has_academic_sheet ) ) {
			$quick_links['apl-academic'] = __( 'Ficha académica', 'atora-lms' );
		}
		if ( ! empty( $modules ) || ! empty( $curriculum_courses ) ) {
			$quick_links['apl-modules'] = __( 'Malla', 'atora-lms' );
		}
		if ( '' !== trim( (string) $instructor_html ) ) {
			$quick_links['apl-instructor'] = __( 'Docentes', 'atora-lms' );
		}
		?>
		<div class="apl-hero">
			<div>
				<p class="apl-kicker">
					<?php echo esc_html( $is_overview ? __( 'Programa académico', 'atora-lms' ) : __( 'Diplomado / Programa', 'atora-lms' ) ); ?>
				</p>
				<h1 class="apl-title"><?php echo esc_html( $title ); ?></h1>
				<?php if ( '' !== trim( (string) ( $tagline ?: $subtitle ) ) ) : ?>
					<p class="apl-copy"><?php echo esc_html( $tagline ?: $subtitle ); ?></p>
				<?php endif; ?>

				<div class="apl-meta">
					<?php if ( '' !== trim( $duration ) ) : ?>
						<span><?php echo esc_html( $duration ); ?></span>
					<?php endif; ?>
					<?php if ( $courses_count > 0 ) : ?>
						<span>
							<?php
							printf(
								/* translators: %d: course count */
								esc_html( _n( '%d curso', '%d cursos', $courses_count, 'atora-lms' ) ),
								$courses_count
							);
							?>
						</span>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $quick_links ) ) : ?>
					<nav class="apl-quicknav" aria-label="<?php esc_attr_e( 'Navegación rápida del programa', 'atora-lms' ); ?>">
						<?php foreach ( $quick_links as $target => $label ) : ?>
							<a href="#<?php echo esc_attr( $target ); ?>"><?php echo esc_html( $label ); ?></a>
						<?php endforeach; ?>
					</nav>
				<?php endif; ?>

				<div class="apl-cta">
					<?php if ( ! $is_overview ) : ?>
						<?php if ( '' !== trim( $price ) ) : ?>
							<p class="apl-price"><?php echo esc_html( $price ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== trim( $price_label ) ) : ?>
							<p class="apl-price-copy"><?php echo esc_html( $price_label ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== trim( $cta_url ) ) : ?>
							<a class="apl-btn" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $cta_label ); ?></a>
						<?php elseif ( ! is_user_logged_in() ) : ?>
							<a class="apl-btn" href="<?php echo esc_url( wp_login_url( $program_permalink ) ); ?>"><?php esc_html_e( 'Iniciar sesión', 'atora-lms' ); ?></a>
						<?php endif; ?>
					<?php elseif ( ! $user_id ) : ?>
						<p class="apl-price-copy"><?php esc_html_e( 'Accede para guardar tu progreso o inscríbete desde la oferta comercial.', 'atora-lms' ); ?></p>
						<?php if ( '' !== trim( $cta_url ) ) : ?>
							<a class="apl-btn" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $cta_label ); ?></a>
						<?php endif; ?>
						<div class="apl-hero-links">
							<a href="<?php echo esc_url( wp_login_url( $program_permalink ) ); ?>"><?php esc_html_e( 'Iniciar sesión', 'atora-lms' ); ?></a>
						</div>
					<?php elseif ( ! $is_enrolled ) : ?>
						<p class="apl-price-copy"><?php esc_html_e( 'Debes estar inscrito para habilitar el itinerario privado del programa.', 'atora-lms' ); ?></p>
						<?php if ( '' !== trim( $cta_url ) ) : ?>
							<a class="apl-btn" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $cta_label ); ?></a>
						<?php endif; ?>
					<?php else : ?>
						<?php if ( $total_lessons > 0 ) : ?>
							<p class="apl-price-copy">
								<?php
								printf(
									/* translators: 1: progress percent, 2: completed lessons, 3: total lessons */
									esc_html__( '%1$d%% de avance (%2$d/%3$d lecciones)', 'atora-lms' ),
									$program_progress,
									$done_lessons,
									$total_lessons
								);
								?>
							</p>
						<?php endif; ?>
						<?php if ( '' !== trim( $next_course_url ) ) : ?>
							<a class="apl-btn" href="<?php echo esc_url( $next_course_url ); ?>"><?php esc_html_e( 'Continuar programa', 'atora-lms' ); ?></a>
						<?php endif; ?>
					<?php endif; ?>

					<?php if ( '' !== trim( $product_title ) && '' !== trim( $product_url ) && 'woocommerce' === $offer_source ) : ?>
						<p class="apl-offer-hint">
							<?php esc_html_e( 'Producto en tienda:', 'atora-lms' ); ?>
							<a href="<?php echo esc_url( $product_url ); ?>"><?php echo esc_html( $product_title ); ?></a>
						</p>
					<?php elseif ( '' !== trim( $cta_url ) && 'manual' === $offer_source ) : ?>
						<p class="apl-offer-hint"><?php esc_html_e( 'Oferta activa por URL manual.', 'atora-lms' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="apl-media">
				<?php if ( '' !== trim( $embed_src ) ) : ?>
					<iframe src="<?php echo esc_url( $embed_src ); ?>" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
				<?php elseif ( '' !== trim( $hero_direct_video_url ) ) : ?>
					<video controls preload="metadata"><source src="<?php echo esc_url( $hero_direct_video_url ); ?>"></video>
				<?php elseif ( has_post_thumbnail( $ctx->entity_id ) ) : ?>
					<?php echo get_the_post_thumbnail( $ctx->entity_id, 'large' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function render_summary( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( ! self::is_program_context( $ctx->schema_context ) ) {
			self::dispatch_to_fallback( 'summary', $section, $ctx, $schema );
			return;
		}

		$d = self::data( $ctx->entity_id );
		if ( empty( $d['program_excerpt'] ) && empty( $d['summary_items'] ) ) {
			return;
		}

		$is_overview = self::is_program_overview_context( $ctx->schema_context );
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		?>
		<section id="apl-summary" class="apl-section">
			<h2 class="apl-section-title">
				<?php echo esc_html( $is_overview ? __( 'Resumen académico', 'atora-lms' ) : __( 'Resumen del programa', 'atora-lms' ) ); ?>
			</h2>
			<?php if ( ! empty( $program_excerpt ) ) : ?>
				<p class="apl-summary-copy"><?php echo esc_html( $program_excerpt ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $summary_items ) ) : ?>
				<div class="apl-summary-grid">
					<?php foreach ( $summary_items as $item ) : ?>
						<div class="apl-summary-card"><?php echo esc_html( $item ); ?></div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( '' !== trim( $cta_url ) || '' !== trim( $product_title ) ) : ?>
				<div class="apl-offer-row">
					<?php if ( '' !== trim( $product_title ) ) : ?>
						<span class="apl-offer-chip">
							<?php esc_html_e( 'Producto vinculado', 'atora-lms' ); ?>:
							<strong><?php echo esc_html( $product_title ); ?></strong>
						</span>
					<?php endif; ?>
					<?php if ( '' !== trim( $cta_url ) ) : ?>
						<span class="apl-offer-chip">
							<?php esc_html_e( 'Canal de inscripción', 'atora-lms' ); ?>:
							<strong><?php echo esc_html( 'woocommerce' === $offer_source ? __( 'Tienda', 'atora-lms' ) : __( 'URL externa', 'atora-lms' ) ); ?></strong>
						</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	public static function render_academic( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( ! self::is_program_context( $ctx->schema_context ) ) {
			self::dispatch_to_fallback( 'academic', $section, $ctx, $schema );
			return;
		}

		$d = self::data( $ctx->entity_id );
		if ( empty( $d['has_academic_sheet'] ) ) {
			return;
		}

		$academic_sheet    = is_array( $d['academic_sheet'] ?? null ) ? $d['academic_sheet'] : array();
		$difficulty_labels = is_array( $d['difficulty_labels'] ?? null ) ? $d['difficulty_labels'] : array();
		$modality_labels   = is_array( $d['modality_labels'] ?? null ) ? $d['modality_labels'] : array();

		$difficulty = sanitize_key( (string) ( $academic_sheet['difficulty'] ?? '' ) );
		$modality   = sanitize_key( (string) ( $academic_sheet['modality'] ?? '' ) );
		?>
		<section id="apl-academic" class="apl-section">
			<h2 class="apl-section-title"><?php esc_html_e( 'Ficha académica del programa', 'atora-lms' ); ?></h2>
			<div class="apl-academic-card">
				<?php if ( ! empty( $academic_sheet['objective_general'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Objetivo general:', 'atora-lms' ); ?></strong> <?php echo esc_html( $academic_sheet['objective_general'] ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $academic_sheet['competencies'] ) ) : ?>
					<details>
						<summary><?php esc_html_e( 'Competencias del programa', 'atora-lms' ); ?></summary>
						<ul><?php foreach ( $academic_sheet['competencies'] as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul>
					</details>
				<?php endif; ?>

				<?php if ( ! empty( $academic_sheet['learning_outcomes'] ) ) : ?>
					<details>
						<summary><?php esc_html_e( 'Resultados de aprendizaje', 'atora-lms' ); ?></summary>
						<ul><?php foreach ( $academic_sheet['learning_outcomes'] as $item ) : ?><li><?php echo esc_html( $item ); ?></li><?php endforeach; ?></ul>
					</details>
				<?php endif; ?>

				<?php if ( ! empty( $academic_sheet['methodology'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Metodología:', 'atora-lms' ); ?></strong> <?php echo esc_html( $academic_sheet['methodology'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $academic_sheet['entry_profile'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Perfil de ingreso:', 'atora-lms' ); ?></strong> <?php echo esc_html( $academic_sheet['entry_profile'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $academic_sheet['exit_profile'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Perfil de egreso:', 'atora-lms' ); ?></strong> <?php echo esc_html( $academic_sheet['exit_profile'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $academic_sheet['certification'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Certificación:', 'atora-lms' ); ?></strong> <?php echo esc_html( $academic_sheet['certification'] ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $academic_sheet['evidence'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Evidencias evaluables:', 'atora-lms' ); ?></strong> <?php echo esc_html( implode( ' · ', $academic_sheet['evidence'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $academic_sheet['evaluation_criteria'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Criterios de evaluación:', 'atora-lms' ); ?></strong> <?php echo esc_html( implode( ' · ', $academic_sheet['evaluation_criteria'] ) ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $difficulty ) && isset( $difficulty_labels[ $difficulty ] ) ) : ?>
					<p><strong><?php esc_html_e( 'Dificultad:', 'atora-lms' ); ?></strong> <?php echo esc_html( $difficulty_labels[ $difficulty ] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $modality ) && isset( $modality_labels[ $modality ] ) ) : ?>
					<p><strong><?php esc_html_e( 'Modalidad:', 'atora-lms' ); ?></strong> <?php echo esc_html( $modality_labels[ $modality ] ); ?></p>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	public static function render_progress( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( ! self::is_program_overview_context( $ctx->schema_context ) ) {
			self::dispatch_to_fallback( 'progress', $section, $ctx, $schema );
			return;
		}

		$d = self::data( $ctx->entity_id );
		if ( empty( $d['courses_count'] ) ) {
			return;
		}

		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		?>
		<section id="apl-progress" class="apl-section">
			<h2 class="apl-section-title"><?php esc_html_e( 'Progreso del programa', 'atora-lms' ); ?></h2>
			<div class="apl-progress-card">
				<?php if ( ! $user_id ) : ?>
					<p><?php esc_html_e( 'Inicia sesión para guardar y visualizar tu avance del programa.', 'atora-lms' ); ?></p>
				<?php elseif ( ! $is_enrolled ) : ?>
					<p><?php esc_html_e( 'Inscríbete para activar el seguimiento de avance del programa.', 'atora-lms' ); ?></p>
				<?php else : ?>
					<p class="apl-progress-head">
						<?php
						printf(
							/* translators: 1: progress percent, 2: completed courses, 3: total courses */
							esc_html__( '%1$d%% completado · %2$d/%3$d cursos finalizados', 'atora-lms' ),
							$program_progress,
							$completed_courses,
							$courses_count
						);
						?>
					</p>
					<div class="apl-progress-bar"><span style="width:<?php echo esc_attr( $program_progress ); ?>%"></span></div>
					<?php if ( $total_lessons > 0 ) : ?>
						<p class="apl-progress-meta">
							<?php
							printf(
								/* translators: 1: completed lessons, 2: total lessons */
								esc_html__( '%1$d de %2$d lecciones completadas', 'atora-lms' ),
								$done_lessons,
								$total_lessons
							);
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	public static function render_instructor( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( ! self::is_program_context( $ctx->schema_context ) ) {
			self::dispatch_to_fallback( 'instructor', $section, $ctx, $schema );
			return;
		}
		$d = self::data( $ctx->entity_id );
		if ( empty( $d['instructor_html'] ) ) {
			return;
		}
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		?>
		<section id="apl-instructor" class="apl-section">
			<h2 class="apl-section-title"><?php echo esc_html( $teacher_section_title ); ?></h2>
			<?php echo $instructor_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>
		<?php
	}

	public static function render_modules( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( ! self::is_program_context( $ctx->schema_context ) ) {
			self::dispatch_to_fallback( 'modules', $section, $ctx, $schema );
			return;
		}

		$d           = self::data( $ctx->entity_id );
		$is_overview = self::is_program_overview_context( $ctx->schema_context );
		$source      = $is_overview ? ( $d['curriculum_courses'] ?? array() ) : ( $d['modules'] ?? array() );
		if ( ! is_array( $source ) || empty( $source ) ) {
			return;
		}

		$modules_display = array_values( $source );
		$engine          = null;
		if ( function_exists( 'atora_lms' ) ) {
			$engine = atora_lms()->get_module( 'CLMS_UI_Template_Engine' );
		}
		if ( ! $engine instanceof CLMS_UI_Template_Engine && class_exists( 'CLMS_UI_Template_Engine', false ) ) {
			$engine = new CLMS_UI_Template_Engine();
		}
		$modules_limit = $engine instanceof CLMS_UI_Template_Engine ? $engine->get_limit( $schema, 'modules', 0 ) : 0;
		if ( $modules_limit > 0 ) {
			$modules_display = array_slice( $modules_display, 0, $modules_limit );
		}

		if ( empty( $modules_display ) ) {
			return;
		}
		?>
		<section id="apl-modules" class="apl-section">
			<h2 class="apl-section-title"><?php esc_html_e( 'Malla curricular', 'atora-lms' ); ?></h2>
			<div class="apl-modules">
				<?php foreach ( $modules_display as $module ) : ?>
					<?php
					$title            = isset( $module['title'] ) ? (string) $module['title'] : '';
					$subtitle_item    = isset( $module['subtitle'] ) ? (string) $module['subtitle'] : '';
					$lesson_count     = absint( $module['lesson_count'] ?? 0 );
					$url              = esc_url_raw( (string) ( $module['url'] ?? '' ) );
					$progress_value   = max( 0, min( 100, absint( $module['progress_percent'] ?? 0 ) ) );
					$prerequisites_met = ! empty( $module['prerequisites_met'] );
					$module_id        = absint( $module['id'] ?? 0 );
					$module_thumb     = '';
					$module_initial   = '';

					if ( '' === trim( $title ) ) {
						continue;
					}

					if ( ! $module_id && '' !== trim( $url ) ) {
						$module_id = absint( url_to_postid( $url ) );
					}

					if ( $module_id && has_post_thumbnail( $module_id ) ) {
						$module_thumb = (string) get_the_post_thumbnail(
							$module_id,
							'medium_large',
							array(
								'class'   => 'apl-module-cover-image',
								'loading' => 'lazy',
								'alt'     => $title,
							)
						);
					}

					$module_initial = function_exists( 'mb_substr' )
						? mb_substr( $title, 0, 1 )
						: substr( $title, 0, 1 );
					$module_initial = strtoupper( (string) $module_initial );
					?>
					<div class="apl-module">
						<div class="apl-module-cover">
							<?php if ( '' !== trim( $module_thumb ) ) : ?>
								<?php echo $module_thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php else : ?>
								<div class="apl-module-cover-fallback" aria-hidden="true">
									<span><?php echo esc_html( $module_initial ); ?></span>
								</div>
							<?php endif; ?>

							<?php if ( $lesson_count > 0 ) : ?>
								<span class="apl-module-lessons-badge">
									<?php
									printf(
										/* translators: %d: lesson count */
										esc_html( _n( '%d lección', '%d lecciones', $lesson_count, 'atora-lms' ) ),
										$lesson_count
									);
									?>
								</span>
							<?php endif; ?>
						</div>

						<div class="apl-module-body">
							<h3 class="apl-module-title"><?php echo esc_html( $title ); ?></h3>
							<?php if ( '' !== trim( $subtitle_item ) ) : ?>
								<p class="apl-module-copy"><?php echo esc_html( $subtitle_item ); ?></p>
							<?php endif; ?>
							<div class="apl-module-meta">
								<?php if ( $is_overview && ! empty( $d['is_enrolled'] ) ) : ?>
									<span><?php echo esc_html( $progress_value ); ?>% <?php esc_html_e( 'de avance', 'atora-lms' ); ?></span>
								<?php else : ?>
									<span><?php esc_html_e( 'Ruta activa en este programa', 'atora-lms' ); ?></span>
								<?php endif; ?>
							</div>

							<?php if ( $is_overview && ! empty( $d['is_enrolled'] ) ) : ?>
								<div class="apl-module-progress" aria-hidden="true">
									<span style="width:<?php echo esc_attr( $progress_value ); ?>%"></span>
								</div>
							<?php endif; ?>

							<?php if ( $is_overview && ! empty( $d['is_enrolled'] ) && ! $prerequisites_met ) : ?>
								<span class="apl-tag apl-tag-warning"><?php esc_html_e( 'Con prerrequisitos pendientes', 'atora-lms' ); ?></span>
							<?php elseif ( $is_overview && empty( $d['is_enrolled'] ) ) : ?>
								<span class="apl-tag apl-tag-warning"><?php esc_html_e( 'Disponible al inscribirte', 'atora-lms' ); ?></span>
							<?php else : ?>
								<span class="apl-tag"><?php esc_html_e( 'Disponible', 'atora-lms' ); ?></span>
							<?php endif; ?>

							<?php if ( '' !== trim( $url ) ) : ?>
								<div class="apl-module-actions">
									<a class="apl-btn apl-btn-inline" href="<?php echo esc_url( $url ); ?>">
										<?php echo esc_html( $is_overview ? __( 'Ir al curso', 'atora-lms' ) : __( 'Ver módulo', 'atora-lms' ) ); ?>
									</a>
								</div>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	public static function render_cta( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( ! self::is_program_context( $ctx->schema_context ) ) {
			self::dispatch_to_fallback( 'cta', $section, $ctx, $schema );
			return;
		}

		$d           = self::data( $ctx->entity_id );
		$is_overview = self::is_program_overview_context( $ctx->schema_context );
		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract

		// Instructores asignados al programa no necesitan CTA de inscripción
		if ( 'instructor' === $ctx->user_role ) {
			return;
		}
		// Alumno inscrito en vista overview: ya tiene acceso, sin CTA de compra
		if ( $is_overview && $ctx->is_enrolled ) {
			return;
		}
		// Vista comercial: ocultar solo si el usuario ya está inscrito y no hay nada que mostrar
		if ( ! $is_overview && empty( $cta_url ) && $ctx->is_enrolled && ! $ctx->is_admin ) {
			return;
		}
		?>
		<div id="apl-cta" class="apl-cta-bottom">
			<p class="apl-cta-bottom-title">
				<?php echo esc_html( $is_overview ? __( 'Accede al programa', 'atora-lms' ) : __( 'Inscríbete al programa', 'atora-lms' ) ); ?>
			</p>
			<?php if ( '' !== trim( (string) ( $tagline ?: $subtitle ) ) ) : ?>
				<p><?php echo esc_html( $tagline ?: $subtitle ); ?></p>
			<?php endif; ?>

			<?php if ( ! $user_id ) : ?>
				<a class="apl-btn apl-btn-inline" href="<?php echo esc_url( wp_login_url( $program_permalink ) ); ?>">
					<?php esc_html_e( 'Iniciar sesión', 'atora-lms' ); ?>
				</a>
			<?php elseif ( '' !== trim( $cta_url ) ) : ?>
				<a class="apl-btn apl-btn-inline" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $cta_label ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_crm_lead( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( ! self::is_program_context( $ctx->schema_context ) ) {
			self::dispatch_to_fallback( 'crm_lead', $section, $ctx, $schema );
			return;
		}
		unset( $section, $schema );
		if ( ! class_exists( 'CLMS_UI_CRM_Lead_Section', false ) ) {
			return;
		}
		CLMS_UI_CRM_Lead_Section::render( $ctx->entity_id, $ctx->schema_context );
	}

	public static function render_related( array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		if ( ! self::is_program_context( $ctx->schema_context ) ) {
			self::dispatch_to_fallback( 'related', $section, $ctx, $schema );
			return;
		}

		$d = self::data( $ctx->entity_id );
		if ( empty( $d['related_items'] ) ) {
			return;
		}

		extract( $d, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		$engine = null;
		if ( function_exists( 'atora_lms' ) ) {
			$engine = atora_lms()->get_module( 'CLMS_UI_Template_Engine' );
		}
		if ( ! $engine instanceof CLMS_UI_Template_Engine && class_exists( 'CLMS_UI_Template_Engine', false ) ) {
			$engine = new CLMS_UI_Template_Engine();
		}
		$related_limit = $engine instanceof CLMS_UI_Template_Engine ? $engine->get_limit( $schema, 'related_items', 6 ) : 6;
		if ( $related_limit > 0 ) {
			$related_items = array_slice( $related_items, 0, $related_limit );
		}

		if ( empty( $related_items ) ) {
			return;
		}
		?>
		<section id="apl-related" class="apl-section">
			<h2 class="apl-section-title"><?php esc_html_e( 'Complementos recomendados', 'atora-lms' ); ?></h2>
			<div class="apl-grid">
				<?php foreach ( $related_items as $item ) : ?>
					<?php
					$type_raw   = isset( $item['type'] ) ? (string) $item['type'] : '';
					$type_key   = strtolower( $type_raw );
					$type_label = isset( $related_type_labels[ $type_key ] ) ? $related_type_labels[ $type_key ] : ucfirst( $type_raw );
					$title_item = isset( $item['title'] ) ? (string) $item['title'] : '';
					$subtitle_item = isset( $item['subtitle'] ) ? (string) $item['subtitle'] : '';
					$price_item = isset( $item['price'] ) ? (string) $item['price'] : '';
					$url_item   = isset( $item['url'] ) ? (string) $item['url'] : '';
					if ( '' === trim( $title_item ) || '' === trim( $url_item ) ) {
						continue;
					}
					?>
					<div class="apl-card">
						<p class="apl-card-type"><?php echo esc_html( $type_label ); ?></p>
						<h3 class="apl-card-title"><?php echo esc_html( $title_item ); ?></h3>
						<?php if ( '' !== trim( $subtitle_item ) ) : ?>
							<p class="apl-card-copy"><?php echo esc_html( $subtitle_item ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== trim( $price_item ) ) : ?>
							<p class="apl-card-meta"><?php echo esc_html( $price_item ); ?></p>
						<?php endif; ?>
						<a class="apl-card-link" href="<?php echo esc_url( $url_item ); ?>"><?php esc_html_e( 'Ver detalle', 'atora-lms' ); ?></a>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Registra callbacks de programa conservando fallback.
	 */
	public static function register_callbacks(): void {
		if ( ! class_exists( 'CLMS_UI_Section_Registry', false ) ) {
			return;
		}

		$registry  = CLMS_UI_Section_Registry::instance();
		$callbacks = array(
			'hero'       => array( self::class, 'render_hero' ),
			'summary'    => array( self::class, 'render_summary' ),
			'academic'   => array( self::class, 'render_academic' ),
			'progress'   => array( self::class, 'render_progress' ),
			'instructor' => array( self::class, 'render_instructor' ),
			'modules'    => array( self::class, 'render_modules' ),
			'cta'        => array( self::class, 'render_cta' ),
			'crm_lead'   => array( self::class, 'render_crm_lead' ),
			'related'    => array( self::class, 'render_related' ),
		);
		$labels = array(
			'hero'       => __( 'Cabecera / Hero', 'atora-lms' ),
			'summary'    => __( 'Resumen del programa', 'atora-lms' ),
			'academic'   => __( 'Ficha académica', 'atora-lms' ),
			'progress'   => __( 'Progreso del programa', 'atora-lms' ),
			'instructor' => __( 'Docentes', 'atora-lms' ),
			'modules'    => __( 'Malla curricular', 'atora-lms' ),
			'cta'        => __( 'Llamada a la acción', 'atora-lms' ),
			'crm_lead'   => __( 'CRM — Captura de lead', 'atora-lms' ),
			'related'    => __( 'Relacionados', 'atora-lms' ),
		);

		foreach ( $callbacks as $id => $cb ) {
			$section = $registry->get( $id );
			if ( ! $section ) {
				$registry->register(
					$id,
					array(
						'label'    => $labels[ $id ] ?? $id,
						'contexts' => array( 'program_commercial', 'program_overview' ),
					)
				);
				$section = $registry->get( $id );
			}
			if ( ! $section ) {
				continue;
			}

			$contexts = isset( $section['contexts'] ) && is_array( $section['contexts'] ) ? $section['contexts'] : array();
			$section['contexts'] = array_values( array_unique( array_merge( $contexts, array( 'program_commercial', 'program_overview' ) ) ) );

			$current_cb = $section['render_callback'] ?? null;
			if ( is_callable( $current_cb ) && ! self::is_self_callback( $current_cb ) ) {
				self::$fallback_callbacks[ $id ] = $current_cb;
			}

			$section['render_callback'] = $cb;
			$registry->register( $id, $section );
		}
	}

	/**
	 * Reenvía al callback previo cuando el contexto no es de programa.
	 */
	private static function dispatch_to_fallback( string $section_id, array $section, CLMS_UI_Template_Context $ctx, array $schema ): void {
		$fallback = self::$fallback_callbacks[ $section_id ] ?? null;
		if ( is_callable( $fallback ) ) {
			call_user_func( $fallback, $section, $ctx, $schema );
		}
	}

	private static function is_self_callback( $callback ): bool {
		return is_array( $callback )
			&& isset( $callback[0] )
			&& isset( $callback[1] )
			&& self::class === $callback[0];
	}

	private static function is_program_context( string $schema_context ): bool {
		return in_array( $schema_context, array( 'program_commercial', 'program_overview' ), true );
	}

	private static function is_program_overview_context( string $schema_context ): bool {
		return 'program_overview' === $schema_context;
	}

	/**
	 * Normaliza metaboxes multilinea guardados como string/array.
	 *
	 * @param mixed $raw
	 * @return array<int,string>
	 */
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
}
