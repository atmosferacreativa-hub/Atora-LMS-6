<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Shortcodes_Listings_Trait {
	public function render_course_list_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'   => -1,
				'columns' => 0,
			),
			(array) $atts,
			'clms_course_list'
		);

		$limit   = (int) $atts['limit'];
		$columns = absint( $atts['columns'] );

		if ( 0 === $limit ) {
			$limit = -1;
		}

		if ( $columns < 1 || $columns > 6 ) {
			$columns = 0;
		}

		$courses = get_posts(
			array(
				'post_type'              => 'lm_course',
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		if ( empty( $courses ) ) {
			return '<div class="clms-ui clms-course-list-wrap"><p>No hay cursos disponibles.</p></div>';
		}

		$this->enqueue_assets();

		$user_id   = get_current_user_id();
		$logged_in = is_user_logged_in();

		ob_start();
		?>
		<div class="clms-ui clms-course-list-wrap">
			<div class="<?php echo esc_attr( trim( 'clms-course-grid clms-course-grid--enhanced' . ( $columns ? ' clms-grid-cols-' . $columns : '' ) ) ); ?>">
				<?php foreach ( $courses as $course ) : ?>
					<?php
					$course_id   = absint( $course->ID );
					$course_link = get_permalink( $course_id );

					if ( ! $course_link ) {
						continue;
					}

					$title        = get_the_title( $course_id );
					$subtitle     = sanitize_text_field( (string) get_post_meta( $course_id, '_clms_course_subtitle', true ) );
					$description  = (string) get_post_meta( $course_id, '_clms_course_excerpt', true );
					$description  = '' !== trim( $description ) ? $description : ( has_excerpt( $course_id ) ? get_the_excerpt( $course_id ) : '' );

					if ( '' === trim( $description ) && '' !== trim( $subtitle ) ) {
						$description = $subtitle;
						$subtitle    = '';
					}

					if ( '' === trim( $description ) ) {
						$description = (string) get_post_field( 'post_content', $course_id );
					}

					$excerpt = wp_trim_words( wp_strip_all_tags( $description ), 30, '...' );

					if ( '' !== trim( $subtitle ) && trim( wp_strip_all_tags( $subtitle ) ) === trim( wp_strip_all_tags( $excerpt ) ) ) {
						$subtitle = '';
					}

					$lesson_count = (int) CLMS_Helper::get_course_lesson_count( $course_id );

					$thumb_html = get_the_post_thumbnail(
						$course_id,
						'medium_large',
						array(
							'class'   => 'clms-course-card-thumb-img',
							'loading' => 'lazy',
						)
					);

					if ( ! $thumb_html ) {
						$thumb_html = $this->get_thumb_fallback( 'Curso sin imagen', 'clms-course-card-thumb-placeholder' );
					}

					$duration    = (string) get_post_meta( $course_id, '_clms_course_duration', true );
					$price       = (string) get_post_meta( $course_id, '_clms_course_price', true );
					$price_label = (string) get_post_meta( $course_id, '_clms_course_price_label', true );

					$terms_level = get_the_terms( $course_id, 'lm_course_level' );
					$level_label = '';

					if ( ! empty( $terms_level ) && ! is_wp_error( $terms_level ) ) {
						$first_level = reset( $terms_level );
						if ( $first_level && ! empty( $first_level->name ) ) {
							$level_label = (string) $first_level->name;
						}
					}

					$is_enrolled = $logged_in ? CLMS_Helper::user_is_enrolled_in_course( $user_id, $course_id ) : false;
					$can_manage  = CLMS_Helper::user_can_manage_lms( $course_id );

					$progress_percent = 0;
					$continue_url     = $course_link;

					if ( $logged_in && ( $is_enrolled || $can_manage ) ) {
						$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );
						$lesson_ids = is_array( $lesson_ids ) ? array_map( 'absint', $lesson_ids ) : array();
						$completed  = 0;

						foreach ( $lesson_ids as $lesson_id ) {
							if ( $this->is_lesson_completed_by_user( $user_id, $lesson_id ) ) {
								$completed++;
								continue;
							}

							if ( $continue_url === $course_link && CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
								$lesson_permalink = get_permalink( $lesson_id );
								if ( $lesson_permalink ) {
									$continue_url = $lesson_permalink;
								}
							}
						}

						if ( $lesson_count > 0 ) {
							$progress_percent = (int) round( ( $completed / $lesson_count ) * 100 );
						}
					}

					$primary_cta   = '';
					$secondary_cta = '';

					if ( $logged_in && ( $is_enrolled || $can_manage ) ) {
						$primary_cta   = '<a class="clms-course-card-btn clms-course-card-btn-primary" href="' . esc_url( $continue_url ) . '">Continuar</a>';
						$secondary_cta = '<a class="clms-course-card-btn clms-course-card-btn-ghost" href="' . esc_url( $course_link ) . '">Ver curso</a>';
					} elseif ( ! $logged_in ) {
						$primary_cta   = '<a class="clms-course-card-btn clms-course-card-btn-primary" href="' . esc_url( wp_login_url( $course_link ) ) . '">Iniciar sesión</a>';
						$secondary_cta = '<a class="clms-course-card-btn clms-course-card-btn-ghost" href="' . esc_url( $course_link ) . '">Ver detalles</a>';
					} else {
						$primary_cta   = $this->get_enroll_button_html( $course_id, $user_id, $course_link );
						$secondary_cta = '<a class="clms-course-card-btn clms-course-card-btn-ghost" href="' . esc_url( $course_link ) . '">Ver detalles</a>';
					}

					$status_label = '';
					$status_class = '';

					if ( $logged_in && ( $is_enrolled || $can_manage ) ) {
						$status_label = $progress_percent >= 100 ? 'Completado' : 'Inscrito';
						$status_class = $progress_percent >= 100 ? 'is-complete' : 'is-enrolled';
					} elseif ( $logged_in ) {
						$status_label = 'Disponible';
						$status_class = 'is-open';
					} else {
						$status_label = 'Acceso con login';
						$status_class = 'is-login';
					}
					?>
					<article class="clms-course-card clms-course-card--enhanced">
						<a class="clms-course-card-thumb" href="<?php echo esc_url( $course_link ); ?>" aria-label="<?php echo esc_attr( $title ); ?>">
							<?php echo $thumb_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="clms-course-card-status <?php echo esc_attr( $status_class ); ?>">
								<?php echo esc_html( $status_label ); ?>
							</span>
						</a>

						<div class="clms-course-card-body">
							<div class="clms-course-card-top">
								<?php if ( $level_label ) : ?>
									<div class="clms-course-card-level"><?php echo esc_html( $level_label ); ?></div>
								<?php endif; ?>

								<h3 class="clms-course-card-title">
									<a href="<?php echo esc_url( $course_link ); ?>">
										<?php echo esc_html( $title ); ?>
									</a>
								</h3>

								<?php if ( $subtitle ) : ?>
									<p class="clms-course-card-subtitle"><?php echo esc_html( $subtitle ); ?></p>
								<?php endif; ?>

								<?php if ( $excerpt ) : ?>
									<p class="clms-course-card-excerpt"><?php echo esc_html( $excerpt ); ?></p>
								<?php endif; ?>
							</div>

							<div class="clms-course-card-meta">
								<div class="clms-course-card-meta-item">
									<span class="clms-course-card-meta-label">Lecciones</span>
									<strong><?php echo esc_html( $lesson_count ); ?></strong>
								</div>

								<?php if ( $duration ) : ?>
									<div class="clms-course-card-meta-item">
										<span class="clms-course-card-meta-label">Duración</span>
										<strong><?php echo esc_html( $duration ); ?></strong>
									</div>
								<?php endif; ?>

								<?php if ( $price || $price_label ) : ?>
									<div class="clms-course-card-meta-item">
										<span class="clms-course-card-meta-label">Acceso</span>
										<strong><?php echo esc_html( $price ? $price : $price_label ); ?></strong>
									</div>
								<?php endif; ?>
							</div>

							<?php if ( $logged_in && ( $is_enrolled || $can_manage ) ) : ?>
								<div class="clms-course-card-progress">
									<div class="clms-course-card-progress-head">
										<span><?php esc_html_e( 'Progreso', 'atora-lms' ); ?></span>
										<strong><?php echo esc_html( $progress_percent ); ?>%</strong>
									</div>
									<div class="clms-course-card-progress-bar"
										role="progressbar"
										aria-valuenow="<?php echo esc_attr( $progress_percent ); ?>"
										aria-valuemin="0"
										aria-valuemax="100"
										aria-label="<?php
											/* translators: %d: completion percentage */
											printf( esc_attr__( '%d%% completado', 'atora-lms' ), (int) $progress_percent );
										?>">
										<span style="width:<?php echo esc_attr( $progress_percent ); ?>%"></span>
									</div>
								</div>
							<?php endif; ?>

							<div class="clms-course-card-actions clms-course-actions">
								<?php echo $primary_cta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo $secondary_cta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>

							<div class="clms-course-message" aria-live="polite">
								<?php
								$flash = $this->get_flash_message_for_course( $course_id );
								if ( $flash ) {
									echo esc_html( $flash );
								}
								?>
							</div>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Catálogo unificado de cursos, programas y productos.
	 *
	 * @param array $atts Atributos.
	 * @return string
	 */
	public function render_catalog_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'per_page'                    => 12,
				'filters'                     => true,
				'type'                        => 'all',
				'level'                       => '',
				'columns'                     => 0,
				'recommendations'             => true,
				'recommendations_limit'       => 3,
				'recommendations_context_type' => '',
				'recommendations_context_id'  => 0,
			),
			(array) $atts,
			'clms_catalog'
		);

		$per_page = (int) $atts['per_page'];

		if ( 0 === $per_page ) {
			$per_page = 12;
		}

		if ( $per_page < -1 ) {
			$per_page = 12;
		}

		$show_filters = filter_var( $atts['filters'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( null === $show_filters ) {
			$show_filters = true;
		}

		$requested_type  = sanitize_key( (string) $atts['type'] );
		$requested_level = sanitize_key( (string) $atts['level'] );
		$columns         = absint( $atts['columns'] );

		if ( '' === $requested_type ) {
			$requested_type = 'all';
		}

		// En modo "metabox" (sin filtros en frontend) evitamos que un nivel residual
		// anule un tipo fijado a programas/productos.
		if ( ! $show_filters && $requested_level && in_array( $requested_type, array( 'product', 'program' ), true ) ) {
			$requested_level = '';
		}

		if ( $columns < 1 || $columns > 6 ) {
			$columns = 0;
		}

		$show_recommendations = filter_var( $atts['recommendations'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( null === $show_recommendations ) {
			$show_recommendations = true;
		}

		$recommendations_limit = absint( $atts['recommendations_limit'] );
		if ( $recommendations_limit < 1 ) {
			$recommendations_limit = 3;
		}
		if ( $recommendations_limit > 12 ) {
			$recommendations_limit = 12;
		}

		$recommendations_context_type = sanitize_key( (string) $atts['recommendations_context_type'] );
		$recommendations_context_id   = absint( $atts['recommendations_context_id'] );

		$context_type_map = array(
			'lm_course'  => 'course',
			'lm_program' => 'program',
			'product'    => 'product',
		);

		if ( ! $recommendations_context_id && is_singular() ) {
			$current_id = absint( get_queried_object_id() );
			if ( $current_id ) {
				$current_type = get_post_type( $current_id );
				if ( $current_type && isset( $context_type_map[ $current_type ] ) ) {
					$recommendations_context_id   = $current_id;
					$recommendations_context_type = $context_type_map[ $current_type ];
				}
			}
		}

		if ( $recommendations_context_id && ! $recommendations_context_type ) {
			$context_post_type = get_post_type( $recommendations_context_id );
			if ( $context_post_type && isset( $context_type_map[ $context_post_type ] ) ) {
				$recommendations_context_type = $context_type_map[ $context_post_type ];
			}
		}

		if ( $recommendations_context_type && ! in_array( $recommendations_context_type, array( 'course', 'program', 'product' ), true ) ) {
			$recommendations_context_type = '';
		}

		if ( ! $recommendations_context_id ) {
			$recommendations_context_type = '';
		}

		$available_types = array(
			'course'  => array(
				'label'     => __( 'Cursos', 'atora-lms' ),
				'post_type' => 'lm_course',
			),
			'program' => array(
				'label'     => __( 'Programas', 'atora-lms' ),
				'post_type' => 'lm_program',
			),
		);

		if ( post_type_exists( 'product' ) ) {
			$available_types['product'] = array(
				'label'     => __( 'Productos', 'atora-lms' ),
				'post_type' => 'product',
			);
		}

		$selected_type = $requested_type;
		if ( 'all' !== $selected_type && ! isset( $available_types[ $selected_type ] ) ) {
			$selected_type = 'all';
		}

		$level_terms    = array();
		$selected_level = '';

		if ( taxonomy_exists( 'lm_course_level' ) ) {
			if ( $requested_level ) {
				$term = get_term_by( 'slug', $requested_level, 'lm_course_level' );
				if ( $term && ! is_wp_error( $term ) ) {
					$selected_level = $requested_level;
				}
			}

			if ( $show_filters ) {
				$level_terms = get_terms(
					array(
						'taxonomy'   => 'lm_course_level',
						'hide_empty' => true,
					)
				);
			}

			if ( $show_filters && isset( $_GET['clms_catalog_level'] ) ) {
				$level_raw = sanitize_key( wp_unslash( $_GET['clms_catalog_level'] ) );

				if ( '' === $level_raw ) {
					$selected_level = '';
				} else {
					$term = get_term_by( 'slug', $level_raw, 'lm_course_level' );
					if ( $term && ! is_wp_error( $term ) ) {
						$selected_level = $level_raw;
					}
				}
			}
		}

		if ( $show_filters && isset( $_GET['clms_catalog_type'] ) ) {
			$type_raw = sanitize_key( wp_unslash( $_GET['clms_catalog_type'] ) );

			if ( '' === $type_raw ) {
				$type_raw = 'all';
			}

			$selected_type = $type_raw;
			if ( 'all' !== $selected_type && ! isset( $available_types[ $selected_type ] ) ) {
				$selected_type = 'all';
			}
		}

		$post_types = array_values( array_unique( wp_list_pluck( $available_types, 'post_type' ) ) );

		if ( 'all' !== $selected_type ) {
			$post_types = array( $available_types[ $selected_type ]['post_type'] );
		}

		if ( $selected_level ) {
			$post_types    = array( 'lm_course' );
			$selected_type = 'course';
		}

		$tax_query = array();
		if ( $selected_level ) {
			$tax_query[] = array(
				'taxonomy' => 'lm_course_level',
				'field'    => 'slug',
				'terms'    => $selected_level,
			);
		}

		$paged = max( 1, absint( get_query_var( 'paged' ) ) );
		if ( 1 === $paged ) {
			$page_var = absint( get_query_var( 'page' ) );
			if ( $page_var ) {
				$paged = $page_var;
			}
		}

		$query_args = array(
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'posts_per_page'         => $per_page,
			'paged'                  => $paged,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		);

		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query;
		}

		$query = new WP_Query( $query_args );

		$this->enqueue_assets();

		$displayed_ids = wp_list_pluck( (array) $query->posts, 'ID' );
		$displayed_ids = is_array( $displayed_ids ) ? array_map( 'absint', $displayed_ids ) : array();

		$recommendation_ids = array();
		if ( $show_recommendations ) {
			$recommendation_ids = $this->get_catalog_recommendations(
				$recommendations_limit,
				$post_types,
				$recommendations_context_type,
				$recommendations_context_id,
				$displayed_ids,
				$tax_query
			);
		}

		ob_start();
		?>
		<div class="clms-ui clms-catalog-wrap">
			<?php if ( $show_filters && ( count( $available_types ) > 1 || ! empty( $level_terms ) ) ) : ?>
				<form class="clms-catalog-filters" method="get" action="<?php echo esc_url( get_pagenum_link( 1 ) ); ?>">
					<?php
					$preserve = isset( $_GET ) ? (array) wp_unslash( $_GET ) : array();
					$skip     = array( 'clms_catalog_type', 'clms_catalog_level', 'paged', 'page' );

					foreach ( $preserve as $key => $value ) {
						if ( in_array( $key, $skip, true ) ) {
							continue;
						}

						if ( is_array( $value ) ) {
							foreach ( $value as $nested_value ) {
								printf(
									'<input type="hidden" name="%s[]" value="%s" />',
									esc_attr( $key ),
									esc_attr( sanitize_text_field( (string) $nested_value ) )
								);
							}
							continue;
						}

						printf(
							'<input type="hidden" name="%s" value="%s" />',
							esc_attr( $key ),
							esc_attr( sanitize_text_field( (string) $value ) )
						);
					}
					?>

					<?php if ( count( $available_types ) > 1 ) : ?>
						<label class="clms-catalog-field">
							<span><?php esc_html_e( 'Tipo', 'atora-lms' ); ?></span>
							<select name="clms_catalog_type">
								<option value="all"><?php esc_html_e( 'Todos', 'atora-lms' ); ?></option>
								<?php foreach ( $available_types as $type_key => $type_data ) : ?>
									<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $selected_type, $type_key ); ?>>
										<?php echo esc_html( $type_data['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>

					<?php if ( ! empty( $level_terms ) ) : ?>
						<label class="clms-catalog-field">
							<span><?php esc_html_e( 'Nivel', 'atora-lms' ); ?></span>
							<select name="clms_catalog_level">
								<option value=""><?php esc_html_e( 'Todos', 'atora-lms' ); ?></option>
								<?php foreach ( $level_terms as $level_term ) : ?>
									<option value="<?php echo esc_attr( $level_term->slug ); ?>" <?php selected( $selected_level, $level_term->slug ); ?>>
										<?php echo esc_html( $level_term->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					<?php endif; ?>

					<button class="clms-catalog-submit" type="submit"><?php esc_html_e( 'Filtrar', 'atora-lms' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( ! $query->have_posts() ) : ?>
				<p class="clms-catalog-empty"><?php esc_html_e( 'No hay contenidos disponibles.', 'atora-lms' ); ?></p>
				<?php if ( $show_recommendations && ! empty( $recommendation_ids ) ) : ?>
					<div class="clms-catalog-recommendations">
						<h3 class="clms-catalog-recommendations-title"><?php esc_html_e( 'Recomendados', 'atora-lms' ); ?></h3>
						<div class="<?php echo esc_attr( trim( 'clms-course-grid clms-catalog-grid' . ( $columns ? ' clms-grid-cols-' . $columns : '' ) ) ); ?>">
							<?php foreach ( $recommendation_ids as $recommendation_id ) : ?>
								<?php echo $this->render_catalog_card( $recommendation_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<div class="<?php echo esc_attr( trim( 'clms-course-grid clms-catalog-grid' . ( $columns ? ' clms-grid-cols-' . $columns : '' ) ) ); ?>">
					<?php while ( $query->have_posts() ) : ?>
						<?php
						$query->the_post();
						$post_id = get_the_ID();
						echo $this->render_catalog_card( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					<?php endwhile; ?>
				</div>

				<?php if ( $query->max_num_pages > 1 ) : ?>
					<nav class="clms-catalog-pagination" aria-label="<?php esc_attr_e( 'Paginación', 'atora-lms' ); ?>">
						<?php
						$paginate_args = array(
							'total'   => (int) $query->max_num_pages,
							'current' => (int) $paged,
							'type'    => 'array',
						);

						if ( $show_filters ) {
							$paginate_args['add_args'] = array_filter(
								array(
									'clms_catalog_type'  => 'all' !== $selected_type ? $selected_type : '',
									'clms_catalog_level' => $selected_level ?: '',
								)
							);
						}

						$pagination = paginate_links(
							$paginate_args
						);

						if ( $pagination ) {
							echo implode( '', $pagination ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</nav>
				<?php endif; ?>

				<?php if ( $show_recommendations && ! empty( $recommendation_ids ) ) : ?>
					<div class="clms-catalog-recommendations">
						<h3 class="clms-catalog-recommendations-title"><?php esc_html_e( 'Recomendados', 'atora-lms' ); ?></h3>
						<div class="<?php echo esc_attr( trim( 'clms-course-grid clms-catalog-grid' . ( $columns ? ' clms-grid-cols-' . $columns : '' ) ) ); ?>">
							<?php foreach ( $recommendation_ids as $recommendation_id ) : ?>
								<?php echo $this->render_catalog_card( $recommendation_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php

		wp_reset_postdata();

		return ob_get_clean();
	}

	/**
	 * Lista lecciones de un curso.
	 *
	 * @param array $atts Atributos.
	 * @return string
	 */
	public function render_lesson_list_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'course_id' => 0,
			),
			(array) $atts,
			'clms_lesson_list'
		);

		$course_id = absint( $atts['course_id'] );

		if ( ! $course_id && is_singular( 'lm_course' ) ) {
			$course_id = get_the_ID();
		}

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return '<div class="clms-ui clms-lesson-list-wrap"><p>No se encontró un curso válido.</p></div>';
		}

		$lessons = CLMS_Helper::get_course_lessons( $course_id );
		$lessons = is_array( $lessons ) ? array_map( 'absint', $lessons ) : array();

		if ( empty( $lessons ) ) {
			return '<div class="clms-ui clms-lesson-list-wrap"><p>Este curso aún no tiene lecciones.</p></div>';
		}

		$this->enqueue_assets();

		$user_id    = get_current_user_id();
		$logged_in  = is_user_logged_in();
		$can_manage = CLMS_Helper::user_can_manage_lms( $course_id );

		ob_start();
		?>
		<div class="clms-ui clms-lesson-list-wrap">
			<div class="clms-lesson-list-card">
				<h3 class="clms-lesson-list-title"><?php echo esc_html( get_the_title( $course_id ) ); ?></h3>

				<ul class="clms-lesson-list">
					<?php foreach ( $lessons as $lesson_id ) : ?>
						<?php
						$lesson_link = get_permalink( $lesson_id );

						$due_date  = $this->format_date( CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_date', '_clms_due_date' ) ) );
						$late_date = $this->format_date( CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_late_date', '_clms_due_date_late' ) ) );
						$due_time  = $this->format_time( CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_time', '_clms_due_time' ) ) );

						$activity_label = $this->get_activity_label( $lesson_id );
						$thumb_html     = get_the_post_thumbnail(
							$lesson_id,
							'medium',
							array(
								'class'   => 'clms-lesson-thumb-img',
								'loading' => 'lazy',
							)
						);
						$thumb_fallback = $this->get_thumb_fallback( 'Lección sin imagen', 'clms-lesson-thumb-placeholder' );

						$can_access = false;

						if ( $can_manage ) {
							$can_access = true;
						} elseif ( $logged_in ) {
							$can_access = CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id );
						}
						?>
						<li class="clms-lesson-item">
							<div class="clms-lesson-thumb">
								<?php if ( $can_access && $lesson_link ) : ?>
									<a href="<?php echo esc_url( $lesson_link ); ?>">
										<?php echo $thumb_html ? $thumb_html : $thumb_fallback; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									</a>
								<?php else : ?>
									<?php echo $thumb_html ? $thumb_html : $thumb_fallback; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php endif; ?>
							</div>

							<div class="clms-lesson-main">
								<h4>
									<?php if ( $can_access && $lesson_link ) : ?>
										<a href="<?php echo esc_url( $lesson_link ); ?>"><?php echo esc_html( get_the_title( $lesson_id ) ); ?></a>
									<?php else : ?>
										<?php echo esc_html( get_the_title( $lesson_id ) ); ?>
									<?php endif; ?>
								</h4>

								<div class="clms-lesson-meta">
									<span><strong>Tipo:</strong> <?php echo esc_html( $activity_label ); ?></span>

									<?php if ( $due_date ) : ?>
										<span> · <strong>Entrega:</strong> <?php echo esc_html( $due_date ); ?><?php echo $due_time ? ' ' . esc_html( $due_time ) : ''; ?></span>
									<?php endif; ?>

									<?php if ( $late_date ) : ?>
										<span> · <strong>Con retraso hasta:</strong> <?php echo esc_html( $late_date ); ?></span>
									<?php endif; ?>

									<?php if ( ! $can_access ) : ?>
										<span class="clms-pill">Bloqueada</span>
									<?php endif; ?>
								</div>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Panel de cursos del usuario.
	 *
	 * @param array $atts Atributos.
	 * @return string
	 */
}
