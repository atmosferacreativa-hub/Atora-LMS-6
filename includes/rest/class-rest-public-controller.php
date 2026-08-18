<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_REST_Public_Controller {

	protected $permissions;

	public function __construct( CLMS_REST_Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	public function get_public_courses( WP_REST_Request $request ) {
		$page       = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page   = $this->sanitize_per_page( $request->get_param( 'per_page' ) );
		$search     = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$teacher_id = absint( $request->get_param( 'teacher_id' ) );

		$args = array(
			'post_type'      => 'lm_course',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			's'              => $search,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( $teacher_id ) {
			$args['author'] = $teacher_id;
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->prepare_public_course_response( $post );
		}

		return rest_ensure_response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			)
		);
	}

	public function get_public_course( WP_REST_Request $request ) {
		$post = get_post( absint( $request['id'] ) );

		if ( ! $post || 'lm_course' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error( 'clms_course_not_found', __( 'Curso no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->prepare_public_course_response( $post, true ) );
	}

	public function get_public_programs( WP_REST_Request $request ) {
		$page       = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page   = $this->sanitize_per_page( $request->get_param( 'per_page' ) );
		$search     = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$teacher_id = absint( $request->get_param( 'teacher_id' ) );

		$args = array(
			'post_type'      => 'lm_program',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			's'              => $search,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( $teacher_id ) {
			$args['author'] = $teacher_id;
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->prepare_public_program_response( $post );
		}

		return rest_ensure_response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			)
		);
	}

	public function get_public_program( WP_REST_Request $request ) {
		$post = get_post( absint( $request['id'] ) );

		if ( ! $post || 'lm_program' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error( 'clms_program_not_found', __( 'Programa no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->prepare_public_program_response( $post, true ) );
	}

	public function get_public_catalog( WP_REST_Request $request ) {
		$page       = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page   = $this->sanitize_per_page( $request->get_param( 'per_page' ) );
		$search     = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$type       = sanitize_key( (string) $request->get_param( 'type' ) );
		$level      = sanitize_key( (string) $request->get_param( 'level' ) );
		$teacher_id = absint( $request->get_param( 'teacher_id' ) );

		$available_types = $this->get_catalog_type_map();

		if ( 'all' !== $type && ! isset( $available_types[ $type ] ) ) {
			$type = 'all';
		}

		$post_types = 'all' === $type ? array_values( $available_types ) : array( $available_types[ $type ] );
		$tax_query  = array();

		if ( $level && taxonomy_exists( 'lm_course_level' ) ) {
			$post_types = array( 'lm_course' );
			$type       = 'course';
			$tax_query[] = array(
				'taxonomy' => 'lm_course_level',
				'field'    => 'slug',
				'terms'    => $level,
			);
		}

		$args = array(
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			's'                      => $search,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( $teacher_id ) {
			$args['author'] = $teacher_id;
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query;
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$item = $this->prepare_catalog_item( $post );
			if ( ! empty( $item ) ) {
				$items[] = $item;
			}
		}

		return rest_ensure_response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
				'type'        => $type,
				'level'       => $level,
			)
		);
	}

	public function get_public_catalog_recommendations( WP_REST_Request $request ) {
		$limit        = absint( $request->get_param( 'limit' ) );
		$type         = sanitize_key( (string) $request->get_param( 'type' ) );
		$context_type = sanitize_key( (string) $request->get_param( 'context_type' ) );
		$context_id   = absint( $request->get_param( 'context_id' ) );

		if ( $limit < 1 ) {
			$limit = 6;
		}
		if ( $limit > 20 ) {
			$limit = 20;
		}

		$available_types = $this->get_catalog_type_map();

		if ( 'all' !== $type && ! isset( $available_types[ $type ] ) ) {
			$type = 'all';
		}

		$allowed_post_types = 'all' === $type ? array_values( $available_types ) : array( $available_types[ $type ] );

		$items = array();
		$seen  = array();

		if ( $context_id ) {
			if ( in_array( $context_type, array( 'course', 'program' ), true ) && class_exists( 'CLMS_Helper' ) ) {
				$related = CLMS_Helper::get_commercial_related_items( $context_id, $limit );
				foreach ( $related as $rel ) {
					if ( empty( $rel['id'] ) || empty( $rel['type'] ) ) {
						continue;
					}

					$rel_type = sanitize_key( (string) $rel['type'] );
					$post_id  = absint( $rel['id'] );

					$item = $this->prepare_catalog_item( $post_id, $rel_type );
					if ( empty( $item ) ) {
						continue;
					}

					if ( ! in_array( $item['post_type'], $allowed_post_types, true ) ) {
						continue;
					}

					$key = $item['post_type'] . ':' . $item['id'];
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}

					$seen[ $key ] = true;
					$items[]      = $item;

					if ( count( $items ) >= $limit ) {
						break;
					}
				}
			} elseif ( 'product' === $context_type && function_exists( 'wc_get_related_products' ) ) {
				$related_ids = wc_get_related_products( $context_id, $limit );
				foreach ( $related_ids as $related_id ) {
					$item = $this->prepare_catalog_item( $related_id, 'product' );
					if ( empty( $item ) ) {
						continue;
					}

					$key = $item['post_type'] . ':' . $item['id'];
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}

					$seen[ $key ] = true;
					$items[]      = $item;

					if ( count( $items ) >= $limit ) {
						break;
					}
				}
			}
		}

		if ( count( $items ) < $limit ) {
			$remaining = $limit - count( $items );

			$fallback = get_posts(
				array(
					'post_type'              => $allowed_post_types,
					'post_status'            => 'publish',
					'posts_per_page'         => max( $remaining * 2, $remaining ),
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $fallback as $post ) {
				$item = $this->prepare_catalog_item( $post );
				if ( empty( $item ) ) {
					continue;
				}

				if ( $context_id && $item['id'] === $context_id ) {
					continue;
				}

				$key = $item['post_type'] . ':' . $item['id'];
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				$items[]      = $item;

				if ( count( $items ) >= $limit ) {
					break;
				}
			}
		}

		return rest_ensure_response(
			array(
				'items' => array_values( $items ),
				'total' => count( $items ),
			)
		);
	}

	protected function get_catalog_type_map() {
		$types = array(
			'course'  => 'lm_course',
			'program' => 'lm_program',
		);

		if ( post_type_exists( 'product' ) ) {
			$types['product'] = 'product';
		}

		/**
		 * Filtro para ajustar los tipos disponibles en el catálogo público.
		 *
		 * @param array $types Mapa tipo => post_type.
		 */
		return apply_filters( 'clms_catalog_type_map', $types );
	}

	protected function prepare_catalog_item( $post, $expected_type = '' ) {
		$post = get_post( $post );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return array();
		}

		$type_map  = $this->get_catalog_type_map();
		$post_type = $post->post_type;
		$type_key  = array_search( $post_type, $type_map, true );

		if ( ! $type_key ) {
			return array();
		}

		$expected_type = sanitize_key( (string) $expected_type );
		if ( $expected_type ) {
			if ( ! isset( $type_map[ $expected_type ] ) ) {
				return array();
			}

			if ( $type_map[ $expected_type ] !== $post_type ) {
				return array();
			}
		}

		$post_id = $post->ID;
		$title   = get_the_title( $post_id );
		$excerpt = has_excerpt( $post_id )
			? get_the_excerpt( $post_id )
			: wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 24 );

		$thumb_id  = get_post_thumbnail_id( $post_id );
		$thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium_large' ) : '';
		$thumb_alt = $thumb_id ? (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) : '';

		$type_label = __( 'Contenido', 'atora-lms' );
		if ( 'course' === $type_key ) {
			$type_label = __( 'Curso', 'atora-lms' );
		} elseif ( 'program' === $type_key ) {
			$type_label = __( 'Programa', 'atora-lms' );
		} elseif ( 'product' === $type_key ) {
			$type_label = __( 'Producto', 'atora-lms' );
		}

		$cta_url     = get_permalink( $post_id );
		$cta_label   = __( 'Ver detalles', 'atora-lms' );
		$price       = '';
		$price_label = '';

		if ( in_array( $post_type, array( 'lm_course', 'lm_program' ), true ) ) {
			if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_entity_offer_data' ) ) {
				$offer = CLMS_Helper::get_entity_offer_data( $post_id );

				if ( ! empty( $offer['url'] ) ) {
					$cta_url = (string) $offer['url'];
				}

				if ( ! empty( $offer['label'] ) ) {
					$cta_label = (string) $offer['label'];
				}

				if ( ! empty( $offer['price'] ) ) {
					$price = (string) $offer['price'];
				}

				if ( ! empty( $offer['price_label'] ) ) {
					$price_label = (string) $offer['price_label'];
				} elseif ( $price ) {
					$price_label = $price;
				}
			}
		} elseif ( 'product' === $post_type ) {
			$cta_label = __( 'Ver producto', 'atora-lms' );
			if ( function_exists( 'wc_get_product' ) ) {
				$wc_product = wc_get_product( $post_id );
				if ( $wc_product ) {
					$price       = (string) $wc_product->get_price();
					$price_label = wp_strip_all_tags( (string) $wc_product->get_price_html() );
				}
			}
		}

		$meta = array();

		if ( 'lm_course' === $post_type ) {
			$lesson_count = class_exists( 'CLMS_Helper' ) ? (int) CLMS_Helper::get_course_lesson_count( $post_id ) : 0;
			$meta['lesson_count'] = $lesson_count;

			$level_terms = get_the_terms( $post_id, 'lm_course_level' );
			if ( ! empty( $level_terms ) && ! is_wp_error( $level_terms ) ) {
				$level = reset( $level_terms );
				if ( $level && ! empty( $level->name ) ) {
					$meta['level'] = (string) $level->name;
				}
			}
		} elseif ( 'lm_program' === $post_type ) {
			$course_count = class_exists( 'CLMS_Helper' ) ? count( CLMS_Helper::get_program_courses( $post_id ) ) : 0;
			$meta['course_count'] = (int) $course_count;
		}

		if ( $price_label ) {
			$meta['price_label'] = $price_label;
		}

		return array(
			'id'         => $post_id,
			'type'       => $type_key,
			'type_label' => $type_label,
			'post_type'  => $post_type,
			'title'      => $title,
			'excerpt'    => $excerpt,
			'link'       => get_permalink( $post_id ) ?: '',
			'thumbnail'  => array(
				'id'  => $thumb_id ? absint( $thumb_id ) : 0,
				'url' => $thumb_url ? (string) $thumb_url : '',
				'alt' => $thumb_alt,
			),
			'cta'        => array(
				'url'         => $cta_url ? (string) $cta_url : '',
				'label'       => $cta_label,
				'price'       => $price,
				'price_label' => $price_label,
			),
			'meta'       => $meta,
		);
	}

	protected function prepare_public_course_response( $post, $full = false ) {
		$post = get_post( $post );

		if ( ! $post || 'lm_course' !== $post->post_type ) {
			return array();
		}

		$course_id  = $post->ID;
		$lesson_ids = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_lessons( $course_id ) : array();
		$lesson_ids = is_array( $lesson_ids ) ? array_values( array_map( 'absint', $lesson_ids ) ) : array();
		$lesson_preview = array();

		if ( $full && ! empty( $lesson_ids ) ) {
			$preview_max = 6;
			$shown       = 0;
			foreach ( $lesson_ids as $index => $lesson_id ) {
				$lesson = get_post( $lesson_id );
				if ( ! $lesson || 'publish' !== $lesson->post_status ) {
					continue;
				}
				$lesson_preview[] = array(
					'id'       => $lesson_id,
					'title'    => get_the_title( $lesson_id ),
					'position' => $index + 1,
				);
				$shown++;
				if ( $shown >= $preview_max ) {
					break;
				}
			}
		}

		$data = array(
			'id'              => $course_id,
			'title'           => get_the_title( $course_id ),
			'slug'            => $post->post_name,
			'link'            => get_permalink( $course_id ),
			'subtitle'        => (string) get_post_meta( $course_id, '_clms_course_subtitle', true ),
			'tagline'         => (string) get_post_meta( $course_id, '_clms_commercial_tagline', true ),
			'duration'        => (string) get_post_meta( $course_id, '_clms_course_duration', true ),
			'price'           => (string) get_post_meta( $course_id, '_clms_course_price', true ),
			'price_label'     => (string) get_post_meta( $course_id, '_clms_course_price_label', true ),
			'certificate'     => (string) get_post_meta( $course_id, '_clms_course_certificate', true ),
			'commercial_mode' => (string) get_post_meta( $course_id, '_clms_commercial_mode', true ),
			'cta_url'         => class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_entity_default_cta_url( $course_id ) : (string) get_post_meta( $course_id, '_clms_commercial_cta_url', true ),
			'cta_label'       => class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_entity_default_cta_label( $course_id ) : (string) get_post_meta( $course_id, '_clms_commercial_cta_label', true ),
			'teacher_ids'     => $this->normalize_id_list( get_post_meta( $course_id, '_clms_course_teacher_ids', true ) ),
			'lesson_count'    => class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_lesson_count( $course_id ) : count( $lesson_ids ),
		);

		if ( $full ) {
			$excerpt = (string) get_post_meta( $course_id, '_clms_course_excerpt', true );
			$data['excerpt']           = $excerpt;
			$data['benefits']          = $this->normalize_lines_meta( get_post_meta( $course_id, '_clms_course_benefits', true ) );
			$data['requirements']      = $this->normalize_lines_meta( get_post_meta( $course_id, '_clms_course_requirements', true ) );
			$data['target_audience']   = $this->normalize_lines_meta( get_post_meta( $course_id, '_clms_course_target_audience', true ) );
			$data['ingress_profile']   = (string) get_post_meta( $course_id, '_clms_commercial_ingress_profile', true );
			$data['egress_profile']    = (string) get_post_meta( $course_id, '_clms_commercial_egress_profile', true );
			$data['hero_video']        = (string) get_post_meta( $course_id, '_clms_commercial_hero_video', true );
			$data['hero_video_source'] = (string) get_post_meta( $course_id, '_clms_commercial_hero_video_source', true );
			$data['gallery_ids']       = $this->normalize_id_list( get_post_meta( $course_id, '_clms_commercial_gallery_ids', true ) );
			$data['lessons_preview']   = $lesson_preview;

			$faq_raw = (string) get_post_meta( $course_id, '_clms_commercial_faq', true );
			$faq     = array();
			if ( $faq_raw ) {
				foreach ( explode( "\n", $faq_raw ) as $line ) {
					$parts = explode( '|', $line, 2 );
					if ( count( $parts ) === 2 ) {
						$faq[] = array(
							'q' => trim( $parts[0] ),
							'a' => trim( $parts[1] ),
						);
					}
				}
			}
			$data['faq'] = $faq;

			$testimonials_raw = (string) get_post_meta( $course_id, '_clms_commercial_testimonials', true );
			$testimonials     = array();
			if ( $testimonials_raw ) {
				foreach ( explode( "\n", $testimonials_raw ) as $line ) {
					$parts = explode( '|', $line, 2 );
					if ( count( $parts ) === 2 ) {
						$testimonials[] = array(
							'quote'  => trim( $parts[0] ),
							'author' => trim( $parts[1] ),
						);
					} elseif ( trim( $line ) ) {
						$testimonials[] = array(
							'quote'  => trim( $line ),
							'author' => '',
						);
					}
				}
			}
			$data['testimonials'] = $testimonials;
			$data['related_items'] = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_commercial_related_items( $course_id, 6 ) : array();
		}

		return $data;
	}

	protected function prepare_public_program_response( $post, $full = false ) {
		$post = get_post( $post );

		if ( ! $post || 'lm_program' !== $post->post_type ) {
			return array();
		}

		$program_id = $post->ID;
		$modules    = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_entity_commercial_modules( $program_id ) : array();

		$data = array(
			'id'              => $program_id,
			'title'           => get_the_title( $program_id ),
			'slug'            => $post->post_name,
			'link'            => get_permalink( $program_id ),
			'subtitle'        => (string) get_post_meta( $program_id, '_clms_program_subtitle', true ),
			'tagline'         => (string) get_post_meta( $program_id, '_clms_commercial_tagline', true ),
			'duration'        => (string) get_post_meta( $program_id, '_clms_program_duration', true ),
			'price'           => (string) get_post_meta( $program_id, '_clms_program_price', true ),
			'price_label'     => (string) get_post_meta( $program_id, '_clms_program_price_label', true ),
			'commercial_mode' => (string) get_post_meta( $program_id, '_clms_commercial_mode', true ),
			'cta_url'         => class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_entity_default_cta_url( $program_id ) : (string) get_post_meta( $program_id, '_clms_commercial_cta_url', true ),
			'cta_label'       => class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_entity_default_cta_label( $program_id ) : (string) get_post_meta( $program_id, '_clms_commercial_cta_label', true ),
			'teacher_ids'     => $this->normalize_id_list( get_post_meta( $program_id, '_clms_program_teacher_ids', true ) ),
			'module_count'    => is_array( $modules ) ? count( $modules ) : 0,
		);

		if ( $full ) {
			$excerpt = trim( wp_strip_all_tags( $post->post_excerpt ) );
			if ( ! $excerpt ) {
				$excerpt = trim( wp_strip_all_tags( $post->post_content ) );
			}
			if ( $excerpt ) {
				$excerpt = wp_trim_words( $excerpt, 38, '...' );
			}

			$summary_items = array();
			if ( $data['duration'] ) {
				$summary_items[] = sprintf( __( 'Duración estimada: %s', 'atora-lms' ), $data['duration'] );
			}
			if ( $data['module_count'] ) {
				$summary_items[] = sprintf( __( '%d módulos estructurados', 'atora-lms' ), $data['module_count'] );
			}

			$data['excerpt']           = $excerpt;
			$data['summary_items']     = array_slice( array_values( $summary_items ), 0, 4 );
			$data['modules']           = $this->sanitize_public_modules( $modules );
			$data['hero_video']        = (string) get_post_meta( $program_id, '_clms_commercial_hero_video', true );
			$data['hero_video_source'] = (string) get_post_meta( $program_id, '_clms_commercial_hero_video_source', true );
			$data['related_items']     = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_commercial_related_items( $program_id, 6 ) : array();
		}

		return $data;
	}

	protected function normalize_lines_input( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( "\n", array_map( 'sanitize_text_field', $value ) );
		}

		$value = (string) $value;
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );

		$lines = array_filter(
			array_map(
				'trim',
				explode( "\n", $value )
			)
		);

		return array_values( $lines );
	}

	protected function normalize_lines_meta( $value ) {
		if ( is_array( $value ) ) {
			return array_values(
				array_filter(
					array_map( 'sanitize_text_field', $value )
				)
			);
		}

		return $this->normalize_lines_input( $value );
	}

	protected function normalize_id_list( $ids ) {
		if ( is_string( $ids ) ) {
			$ids = preg_split( '/\s*,\s*/', trim( $ids ) );
		}

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $ids )
				)
			)
		);
	}

	protected function sanitize_public_modules( $modules ) {
		if ( ! is_array( $modules ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $modules as $module ) {
			if ( ! is_array( $module ) ) {
				continue;
			}

			$item = array(
				'id'       => isset( $module['id'] ) ? absint( $module['id'] ) : 0,
				'type'     => isset( $module['type'] ) ? sanitize_key( (string) $module['type'] ) : '',
				'title'    => isset( $module['title'] ) ? sanitize_text_field( (string) $module['title'] ) : '',
				'subtitle' => isset( $module['subtitle'] ) ? sanitize_text_field( (string) $module['subtitle'] ) : '',
			);

			if ( isset( $module['lesson_count'] ) ) {
				$item['lesson_count'] = absint( $module['lesson_count'] );
			}

			if ( ! empty( $module['url'] ) ) {
				$item['url'] = esc_url_raw( (string) $module['url'] );
			}

			$sanitized[] = $item;
		}

		return $sanitized;
	}

	protected function sanitize_per_page( $value ) {
		$value = absint( $value );

		if ( $value < 1 ) {
			$value = 20;
		}

		if ( $value > 100 ) {
			$value = 100;
		}

		return $value;
	}
}
