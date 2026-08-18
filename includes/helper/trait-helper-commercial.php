<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Helper_Commercial_Trait {
	/**
	 * Obtiene producto comercial vinculado a una entidad académica.
	 *
	 * @param int $post_id Curso o programa.
	 * @return int
	 */
	public static function get_entity_linked_product_id( $post_id ) {
		$post_id   = absint( $post_id );
		$post_type = $post_id ? get_post_type( $post_id ) : '';

		if ( ! $post_id || ! class_exists( 'CLMS_WooCommerce' ) ) {
			return 0;
		}

		if ( 'lm_course' === $post_type && method_exists( 'CLMS_WooCommerce', 'get_course_product_id' ) ) {
			return absint( CLMS_WooCommerce::get_course_product_id( $post_id ) );
		}

		if ( 'lm_program' === $post_type && method_exists( 'CLMS_WooCommerce', 'get_program_product_id' ) ) {
			return absint( CLMS_WooCommerce::get_program_product_id( $post_id ) );
		}

		return 0;
	}

	/**
	 * Resuelve URL CTA por defecto para curso/programa.
	 *
	 * @param int $post_id Curso o programa.
	 * @return string
	 */
	public static function get_entity_default_cta_url( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return '';
		}

		$manual_url = (string) get_post_meta( $post_id, '_clms_commercial_cta_url', true );
		$manual_url = apply_filters( 'clms_entity_cta_url_manual', $manual_url, $post_id );

		if ( '' !== trim( $manual_url ) ) {
			return esc_url_raw( $manual_url );
		}

		$product_id = self::get_entity_linked_product_id( $post_id );

		if ( $product_id ) {
			return (string) get_permalink( $product_id );
		}

		$external_url = apply_filters( 'clms_entity_cta_url_fallback', '', $post_id );
		if ( '' !== trim( (string) $external_url ) ) {
			return esc_url_raw( (string) $external_url );
		}

		return '';
	}

	/**
	 * Resuelve etiqueta CTA por defecto para curso/programa.
	 *
	 * @param int $post_id Curso o programa.
	 * @return string
	 */
	public static function get_entity_default_cta_label( $post_id ) {
		$post_id    = absint( $post_id );
		$manual     = (string) get_post_meta( $post_id, '_clms_commercial_cta_label', true );
		$post_type  = $post_id ? get_post_type( $post_id ) : '';
		$product_id = self::get_entity_linked_product_id( $post_id );

		$manual = apply_filters( 'clms_entity_cta_label_manual', $manual, $post_id );
		if ( '' !== trim( $manual ) ) {
			return sanitize_text_field( $manual );
		}

		if ( $product_id ) {
			return 'lm_program' === $post_type
				? __( 'Comprar programa', 'atora-lms' )
				: __( 'Comprar curso', 'atora-lms' );
		}

		$fallback_label = apply_filters( 'clms_entity_cta_label_fallback', '', $post_id );
		if ( '' !== trim( (string) $fallback_label ) ) {
			return sanitize_text_field( (string) $fallback_label );
		}

		return __( 'Solicitar información', 'atora-lms' );
	}

	/**
	 * Resuelve la oferta comercial canónica para curso/programa.
	 *
	 * @param int $post_id Entidad.
	 * @return array<string,mixed>
	 */
	public static function get_entity_offer_data( $post_id ) {
		$post_id   = absint( $post_id );
		$post_type = $post_id ? get_post_type( $post_id ) : '';

		$price       = '';
		$price_label = '';

		if ( 'lm_course' === $post_type ) {
			$price       = (string) get_post_meta( $post_id, '_clms_course_price', true );
			$price_label = (string) get_post_meta( $post_id, '_clms_course_price_label', true );
		} elseif ( 'lm_program' === $post_type ) {
			$price       = (string) get_post_meta( $post_id, '_clms_program_price', true );
			$price_label = (string) get_post_meta( $post_id, '_clms_program_price_label', true );
		}

		$url   = self::get_entity_default_cta_url( $post_id );
		$label = self::get_entity_default_cta_label( $post_id );

		$source     = $url ? 'manual' : 'none';
		$product_id = self::get_entity_linked_product_id( $post_id );

		if ( $product_id ) {
			$source = 'woocommerce';
			if ( ( '' === trim( $price_label ) ) && class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					if ( function_exists( 'wc_price' ) ) {
						$price_label = wp_strip_all_tags( wc_price( (float) $product->get_price() ) );
					} else {
						$price_label = (string) $product->get_price();
					}
				}
			}
		}

		if ( $url && $product_id && $url !== get_permalink( $product_id ) ) {
			$source = 'manual';
		}

		$data = array(
			'url'         => (string) $url,
			'label'       => (string) $label,
			'price'       => (string) $price,
			'price_label' => (string) $price_label,
			'source'      => $source,
		);

		return apply_filters( 'clms_entity_offer_data', $data, $post_id );
	}

	/**
	 * Construye listado reusable de módulos para landings/comercial.
	 *
	 * @param int $post_id Entidad.
	 * @param int $limit   Límite opcional.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_entity_commercial_modules( $post_id, $limit = 0 ) {
		$post_id   = absint( $post_id );
		$post_type = $post_id ? get_post_type( $post_id ) : '';
		$limit     = max( 0, absint( $limit ) );
		$items     = array();

		if ( 'lm_course' === $post_type ) {
			foreach ( self::get_course_lessons( $post_id ) as $lesson_id ) {
				$lesson = get_post( $lesson_id );

				if ( ! $lesson || 'publish' !== $lesson->post_status ) {
					continue;
				}

				$items[] = array(
					'id'       => $lesson_id,
					'type'     => 'lesson',
					'title'    => get_the_title( $lesson_id ),
					'subtitle' => (string) get_post_meta( $lesson_id, '_clms_lesson_subtitle', true ),
					'url'      => get_permalink( $lesson_id ),
				);
			}
		} elseif ( 'lm_program' === $post_type ) {
			$curriculum_courses = self::get_program_curriculum( $post_id );
			$curriculum_courses = ! empty( $curriculum_courses['courses'] ) && is_array( $curriculum_courses['courses'] ) ? $curriculum_courses['courses'] : array();

			foreach ( $curriculum_courses as $course ) {
				$items[] = array(
					'id'          => absint( $course['id'] ),
					'type'        => 'course',
					'title'       => sanitize_text_field( (string) $course['title'] ),
					'subtitle'    => (string) get_post_meta( absint( $course['id'] ), '_clms_course_subtitle', true ),
					'url'         => esc_url_raw( (string) $course['url'] ),
					'lesson_count'=> absint( $course['lesson_count'] ),
				);
			}
		}

		if ( $limit > 0 ) {
			$items = array_slice( $items, 0, $limit );
		}

		return $items;
	}

	/**
	 * Devuelve un bloque normalizado de productos relacionados para cross-sell.
	 *
	 * @param int $post_id Entidad fuente.
	 * @param int $limit   Límite máximo.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_commercial_related_items( $post_id, $limit = 6 ) {
		$post_id   = absint( $post_id );
		$post_type = $post_id ? get_post_type( $post_id ) : '';
		$limit     = max( 1, absint( $limit ) );
		$items     = array();
		$seen      = array();

		if ( ! in_array( $post_type, array( 'lm_course', 'lm_program' ), true ) ) {
			return $items;
		}

		$manual_products = self::normalize_id_list( get_post_meta( $post_id, '_clms_commercial_related_product_ids', true ) );
		$manual_courses  = self::normalize_id_list( get_post_meta( $post_id, '_clms_commercial_related_course_ids', true ) );
		$manual_programs = self::normalize_id_list( get_post_meta( $post_id, '_clms_commercial_related_program_ids', true ) );

		foreach ( $manual_products as $product_id ) {
			$item = self::build_commercial_related_item( 'product', $product_id );
			if ( ! empty( $item ) && ! isset( $seen[ $item['type'] . ':' . $item['id'] ] ) ) {
				$seen[ $item['type'] . ':' . $item['id'] ] = true;
				$items[]                                    = $item;
			}
		}

		foreach ( $manual_courses as $course_id ) {
			if ( $course_id === $post_id ) {
				continue;
			}
			$item = self::build_commercial_related_item( 'course', $course_id );
			if ( ! empty( $item ) && ! isset( $seen[ $item['type'] . ':' . $item['id'] ] ) ) {
				$seen[ $item['type'] . ':' . $item['id'] ] = true;
				$items[]                                    = $item;
			}
		}

		foreach ( $manual_programs as $program_id ) {
			if ( $program_id === $post_id ) {
				continue;
			}
			$item = self::build_commercial_related_item( 'program', $program_id );
			if ( ! empty( $item ) && ! isset( $seen[ $item['type'] . ':' . $item['id'] ] ) ) {
				$seen[ $item['type'] . ':' . $item['id'] ] = true;
				$items[]                                    = $item;
			}
		}

		if ( count( $items ) >= $limit ) {
			return array_slice( $items, 0, $limit );
		}

		$fallback_ids = array();

		if ( 'lm_course' === $post_type ) {
			foreach ( self::get_course_program_ids( $post_id ) as $program_id ) {
				$fallback_ids[] = array( 'program', $program_id );
				foreach ( self::get_program_courses( $program_id ) as $course_id ) {
					if ( $course_id !== $post_id ) {
						$fallback_ids[] = array( 'course', $course_id );
					}
				}
			}
		} elseif ( 'lm_program' === $post_type ) {
			foreach ( self::get_program_courses( $post_id ) as $course_id ) {
				$fallback_ids[] = array( 'course', $course_id );
			}
		}

		$author_id = absint( get_post_field( 'post_author', $post_id ) );

		if ( $author_id ) {
			$author_courses = get_posts(
				array(
					'post_type'              => array( 'lm_course', 'lm_program' ),
					'post_status'            => 'publish',
					'author'                 => $author_id,
					'post__not_in'           => array( $post_id ),
					'posts_per_page'         => $limit,
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $author_courses as $related_post ) {
				$fallback_ids[] = array( 'lm_program' === $related_post->post_type ? 'program' : 'course', $related_post->ID );
			}
		}

		foreach ( $fallback_ids as $candidate ) {
			if ( count( $items ) >= $limit ) {
				break;
			}

			$item = self::build_commercial_related_item( $candidate[0], $candidate[1] );
			if ( empty( $item ) ) {
				continue;
			}

			$key = $item['type'] . ':' . $item['id'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$items[]      = $item;
		}

		return array_slice( $items, 0, $limit );
	}
}
