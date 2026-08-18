<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Shortcodes_Enrollment_Catalog_Trait {
	public function render_my_courses_shortcode( $atts ) {
		unset( $atts );

		if ( ! is_user_logged_in() ) {
			return '<div class="clms-ui clms-my-courses-wrap"><p>Debes iniciar sesión para ver tus cursos.</p></div>';
		}

		$this->enqueue_assets();

		$user_id    = get_current_user_id();
		$course_ids = ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $user_id ) );
		$course_ids = is_array( $course_ids ) ? array_map( 'absint', $course_ids ) : array();

		if ( empty( $course_ids ) && CLMS_Helper::user_can_manage_lms() ) {
			$course_ids = get_posts(
				array(
					'post_type'      => 'lm_course',
					'post_status'    => array( 'publish', 'private' ),
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);
			$course_ids = is_array( $course_ids ) ? array_map( 'absint', $course_ids ) : array();
		}

		if ( empty( $course_ids ) ) {
			return '<div class="clms-ui clms-my-courses-wrap"><p>No tienes cursos inscritos todavía.</p></div>';
		}

		ob_start();
		?>
		<div class="clms-ui clms-my-courses-wrap">
			<div class="clms-my-courses-card">
				<div class="clms-my-courses-body">
					<h3 class="clms-my-courses-title">Mis cursos</h3>

					<?php foreach ( $course_ids as $course_id ) : ?>
						<?php
						if ( 'lm_course' !== get_post_type( $course_id ) ) {
							continue;
						}

						$course_url   = get_permalink( $course_id );
						$continue_url = $this->get_continue_course_url( $user_id, $course_id );

						if ( ! $course_url ) {
							continue;
						}
						?>
						<div class="clms-my-course-item">
							<h4><a href="<?php echo esc_url( $course_url ); ?>"><?php echo esc_html( get_the_title( $course_id ) ); ?></a></h4>
							<div class="clms-course-actions">
								<a class="clms-course-link" href="<?php echo esc_url( $course_url ); ?>">Ver curso</a>
								<?php if ( $continue_url ) : ?>
									<a class="clms-course-continue-btn" href="<?php echo esc_url( $continue_url ); ?>">Continuar</a>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Botón de inscripción o continuación.
	 *
	 * @param int    $course_id    Curso.
	 * @param int    $user_id      Usuario.
	 * @param string $redirect_url URL de retorno.
	 * @return string
	 */
	public function get_enroll_button_html( $course_id, $user_id = 0, $redirect_url = '' ) {
		$course_id    = absint( $course_id );
		$user_id      = absint( $user_id );
		$redirect_url = $redirect_url ? esc_url_raw( $redirect_url ) : get_permalink( $course_id );

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return '';
		}

		if ( ! is_user_logged_in() ) {
			return '<a class="clms-course-login-btn" href="' . esc_url( wp_login_url( $redirect_url ) ) . '">Iniciar sesión</a>';
		}

		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( CLMS_Helper::user_is_enrolled_in_course( $user_id, $course_id ) || CLMS_Helper::user_can_manage_lms( $course_id ) ) {
			$continue_url = $this->get_continue_course_url( $user_id, $course_id );

			return '<a class="clms-course-continue-btn" href="' . esc_url( $continue_url ) . '">Continuar</a>';
		}

		if ( ! CLMS_Helper::can_self_enroll_in_course( $course_id, $user_id ) ) {
			return '<a class="clms-course-login-btn" href="' . esc_url( $redirect_url ) . '">Ver detalles</a>';
		}

		$nonce = wp_create_nonce( 'clms_enroll_course_' . $course_id );

		return '<button type="button" class="clms-course-enroll-btn clms-js-enroll" data-course-id="' . esc_attr( $course_id ) . '" data-nonce="' . esc_attr( $nonce ) . '" data-redirect="' . esc_attr( $redirect_url ) . '">' . esc_html__( 'Inscribirme', 'atora-lms' ) . '</button>';
	}

	/**
	 * Inscripción AJAX.
	 *
	 * @return void
	 */
	public function ajax_enroll_course() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Debes iniciar sesión.', 'atora-lms' ) ), 403 );
		}

		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
		$nonce     = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$redirect  = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : '';

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Curso inválido.', 'atora-lms' ) ), 400 );
		}

		if ( ! wp_verify_nonce( $nonce, 'clms_enroll_course_' . $course_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Solicitud inválida.', 'atora-lms' ) ), 403 );
		}

		$user_id = get_current_user_id();

		if ( CLMS_Helper::user_is_enrolled_in_course( $user_id, $course_id ) ) {
			wp_send_json_success(
				array(
					'message'     => __( 'Ya estás inscrito en este curso.', 'atora-lms' ),
					'button_html' => $this->get_enroll_button_html( $course_id, $user_id, $redirect ),
				)
			);
		}

		if ( ! CLMS_Helper::can_self_enroll_in_course( $course_id, $user_id ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Este curso requiere completar la compra antes de inscribirte.', 'atora-lms' ),
				),
				403
			);
		}

		$enrolled = CLMS_Helper::enroll_user_in_course( $user_id, $course_id );

		if ( false === $enrolled ) {
			wp_send_json_error( array( 'message' => __( 'No se pudo completar la inscripción.', 'atora-lms' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'message'     => __( 'Te has inscrito correctamente.', 'atora-lms' ),
				'button_html' => $this->get_enroll_button_html( $course_id, $user_id, $redirect ),
			)
		);
	}

	/**
	 * Inscripción por POST clásico.
	 *
	 * @return void
	 */
	public function handle_enroll_post() {
		if ( is_admin() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( empty( $_POST['clms_enroll_course_submit'] ) ) {
			return;
		}

		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
		$nonce     = isset( $_POST['clms_enroll_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_enroll_nonce'] ) ) : '';
		$redirect  = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $nonce, 'clms_enroll_course_' . $course_id ) ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! CLMS_Helper::can_self_enroll_in_course( $course_id, $user_id ) ) {
			$target = $redirect ? $redirect : get_permalink( $course_id );

			if ( ! $target ) {
				$target = home_url( '/' );
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'clms_course' => $course_id,
						'clms_msg'    => 'purchase_required',
					),
					$target
				)
			);
			exit;
		}

		CLMS_Helper::enroll_user_in_course( $user_id, $course_id );

		$target = $redirect ? $redirect : get_permalink( $course_id );

		if ( ! $target ) {
			$target = home_url( '/' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'clms_course' => $course_id,
					'clms_msg'    => 'enrolled',
				),
				$target
			)
		);
		exit;
	}

	/**
	 * Renderiza una tarjeta de catálogo reutilizable.
	 *
	 * @param int $post_id ID del contenido.
	 * @return string
	 */
	protected function render_catalog_card( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return '';
		}

		$post_type = $post->post_type;
		$title     = get_the_title( $post_id );
		$subtitle  = '';
		$excerpt   = '';

		if ( 'lm_course' === $post_type ) {
			$subtitle = sanitize_text_field( (string) get_post_meta( $post_id, '_clms_course_subtitle', true ) );
			$excerpt  = (string) get_post_meta( $post_id, '_clms_course_excerpt', true );
		} elseif ( 'lm_program' === $post_type ) {
			$subtitle = sanitize_text_field( (string) get_post_meta( $post_id, '_clms_program_subtitle', true ) );
		}

		$excerpt = '' !== trim( $excerpt )
			? $excerpt
			: ( has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : '' );

		if ( '' === trim( $excerpt ) && '' !== trim( $subtitle ) ) {
			$excerpt  = $subtitle;
			$subtitle = '';
		}

		if ( '' === trim( $excerpt ) ) {
			$excerpt = (string) $post->post_content;
		}

		$excerpt = wp_trim_words( wp_strip_all_tags( $excerpt ), 28, '...' );

		if ( '' !== trim( $subtitle ) && trim( wp_strip_all_tags( $subtitle ) ) === trim( wp_strip_all_tags( $excerpt ) ) ) {
			$subtitle = '';
		}

		$thumb_html = get_the_post_thumbnail(
			$post_id,
			'medium_large',
			array(
				'class'   => 'clms-course-card-thumb-img',
				'loading' => 'lazy',
			)
		);

		if ( ! $thumb_html ) {
			$thumb_html = $this->get_thumb_fallback( __( 'Sin imagen', 'atora-lms' ), 'clms-course-card-thumb-placeholder' );
		}

		$type_label = __( 'Contenido', 'atora-lms' );
		if ( 'lm_course' === $post_type ) {
			$type_label = __( 'Curso', 'atora-lms' );
		} elseif ( 'lm_program' === $post_type ) {
			$type_label = __( 'Programa', 'atora-lms' );
		} elseif ( 'product' === $post_type ) {
			$type_label = __( 'Producto', 'atora-lms' );
		}

		$cta_url     = get_permalink( $post_id );
		$cta_label   = __( 'Ver detalles', 'atora-lms' );
		$price       = '';
		$details_url = get_permalink( $post_id );

		if ( in_array( $post_type, array( 'lm_course', 'lm_program' ), true ) ) {
			if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_entity_offer_data' ) ) {
				$offer = CLMS_Helper::get_entity_offer_data( $post_id );
				if ( ! empty( $offer['url'] ) ) {
					$cta_url = (string) $offer['url'];
				}
				if ( ! empty( $offer['label'] ) ) {
					$cta_label = (string) $offer['label'];
				}
				$price = (string) ( $offer['price_label'] ?: $offer['price'] );
			}
		} elseif ( 'product' === $post_type ) {
			$cta_label = __( 'Ver producto', 'atora-lms' );
			if ( function_exists( 'wc_get_product' ) ) {
				$wc_product = wc_get_product( $post_id );
				if ( $wc_product ) {
					$price = wp_strip_all_tags( (string) $wc_product->get_price_html() );
				}
			}
		}

		$meta_items = array();

		if ( 'lm_course' === $post_type && class_exists( 'CLMS_Helper' ) ) {
			$lesson_count = (int) CLMS_Helper::get_course_lesson_count( $post_id );
			$meta_items[] = array(
				'label' => __( 'Lecciones', 'atora-lms' ),
				'value' => $lesson_count,
			);

			$level_terms = get_the_terms( $post_id, 'lm_course_level' );
			if ( ! empty( $level_terms ) && ! is_wp_error( $level_terms ) ) {
				$level = reset( $level_terms );
				if ( $level && ! empty( $level->name ) ) {
					$meta_items[] = array(
						'label' => __( 'Nivel', 'atora-lms' ),
						'value' => (string) $level->name,
					);
				}
			}
		} elseif ( 'lm_program' === $post_type && class_exists( 'CLMS_Helper' ) ) {
			$course_count = count( CLMS_Helper::get_program_courses( $post_id ) );
			$meta_items[] = array(
				'label' => __( 'Cursos', 'atora-lms' ),
				'value' => $course_count,
			);
		}

		if ( $price ) {
			$meta_items[] = array(
				'label' => __( 'Precio', 'atora-lms' ),
				'value' => $price,
			);
		}

		$show_details_cta = $details_url && ( $details_url !== $cta_url || $cta_label !== __( 'Ver detalles', 'atora-lms' ) );

		ob_start();
		?>
		<article class="clms-course-card clms-catalog-card">
			<a class="clms-course-card-thumb" href="<?php echo esc_url( $cta_url ); ?>" aria-label="<?php echo esc_attr( $title ); ?>">
				<?php echo $thumb_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>

			<div class="clms-course-card-body">
				<div class="clms-course-card-top">
					<div class="clms-course-card-level"><?php echo esc_html( $type_label ); ?></div>

					<h3 class="clms-course-card-title">
						<a href="<?php echo esc_url( $cta_url ); ?>">
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

				<?php if ( ! empty( $meta_items ) ) : ?>
					<div class="clms-course-card-meta">
						<?php foreach ( $meta_items as $meta_item ) : ?>
							<div class="clms-course-card-meta-item">
								<span class="clms-course-card-meta-label"><?php echo esc_html( $meta_item['label'] ); ?></span>
								<strong><?php echo esc_html( $meta_item['value'] ); ?></strong>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="clms-course-card-actions clms-course-actions">
					<a class="clms-course-card-btn clms-course-card-btn-primary" href="<?php echo esc_url( $cta_url ); ?>">
						<?php echo esc_html( $cta_label ); ?>
					</a>
					<?php if ( $show_details_cta ) : ?>
						<a class="clms-course-card-btn clms-course-card-btn-ghost" href="<?php echo esc_url( $details_url ); ?>">
							<?php esc_html_e( 'Ver detalles', 'atora-lms' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}

	/**
	 * Obtiene recomendaciones básicas para el catálogo.
	 *
	 * @param int    $limit        Cantidad máxima.
	 * @param array  $post_types   Tipos permitidos.
	 * @param string $context_type Tipo de contexto.
	 * @param int    $context_id   ID de contexto.
	 * @param array  $exclude_ids  IDs a excluir.
	 * @param array  $tax_query    Filtros de taxonomía.
	 * @return array
	 */
	protected function get_catalog_recommendations( $limit, $post_types, $context_type = '', $context_id = 0, $exclude_ids = array(), $tax_query = array() ) {
		$limit = absint( $limit );
		if ( $limit < 1 ) {
			$limit = 3;
		}
		if ( $limit > 12 ) {
			$limit = 12;
		}

		$post_types = array_values( array_filter( (array) $post_types ) );
		if ( empty( $post_types ) ) {
			return array();
		}

		$context_type = sanitize_key( (string) $context_type );
		$context_id   = absint( $context_id );
		$exclude_ids  = array_values( array_unique( array_filter( array_map( 'absint', (array) $exclude_ids ) ) ) );

		$seen = array();
		foreach ( $exclude_ids as $exclude_id ) {
			$seen[ $exclude_id ] = true;
		}
		if ( $context_id ) {
			$seen[ $context_id ] = true;
		}

		$type_map = array(
			'course'  => 'lm_course',
			'program' => 'lm_program',
			'product' => 'product',
		);

		$recommendations = array();

		if ( $context_id ) {
			if ( in_array( $context_type, array( 'course', 'program' ), true ) && class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_commercial_related_items' ) ) {
				$related = CLMS_Helper::get_commercial_related_items( $context_id, $limit );
				foreach ( $related as $rel ) {
					$rel_id   = isset( $rel['id'] ) ? absint( $rel['id'] ) : 0;
					$rel_type = isset( $rel['type'] ) ? sanitize_key( (string) $rel['type'] ) : '';

					if ( ! $rel_id || ! $rel_type || ! isset( $type_map[ $rel_type ] ) ) {
						continue;
					}

					$post_type = $type_map[ $rel_type ];
					if ( ! in_array( $post_type, $post_types, true ) ) {
						continue;
					}

					if ( isset( $seen[ $rel_id ] ) ) {
						continue;
					}

					$recommendations[] = $rel_id;
					$seen[ $rel_id ]   = true;

					if ( count( $recommendations ) >= $limit ) {
						break;
					}
				}
			} elseif ( 'product' === $context_type && function_exists( 'wc_get_related_products' ) && in_array( 'product', $post_types, true ) ) {
				$related_ids = wc_get_related_products( $context_id, $limit );
				foreach ( $related_ids as $rel_id ) {
					$rel_id = absint( $rel_id );
					if ( ! $rel_id || isset( $seen[ $rel_id ] ) ) {
						continue;
					}

					$recommendations[] = $rel_id;
					$seen[ $rel_id ]   = true;

					if ( count( $recommendations ) >= $limit ) {
						break;
					}
				}
			}
		}

		if ( count( $recommendations ) < $limit ) {
			$remaining = $limit - count( $recommendations );
			$args      = array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => max( $remaining * 2, $remaining ),
				'fields'                 => 'ids',
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			if ( ! empty( $seen ) ) {
				$args['post__not_in'] = array_keys( $seen );
			}

			if ( ! empty( $tax_query ) ) {
				$args['tax_query'] = $tax_query;
			}

			$fallback_ids = get_posts( $args );
			foreach ( $fallback_ids as $fallback_id ) {
				$fallback_id = absint( $fallback_id );
				if ( ! $fallback_id || isset( $seen[ $fallback_id ] ) ) {
					continue;
				}

				$recommendations[] = $fallback_id;
				$seen[ $fallback_id ] = true;

				if ( count( $recommendations ) >= $limit ) {
					break;
				}
			}
		}

		return $recommendations;
	}

	/**
	 * URL de continuación.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $course_id Curso.
	 * @return string
	 */
}
