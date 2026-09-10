<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Helper_Utilities_Trait {
	/**
	 * Sanea un valor antes de escribirlo en una celda CSV para evitar
	 * inyección de fórmulas (Excel/LibreOffice ejecutan celdas que
	 * empiezan con = + - @ como fórmulas al abrir el archivo).
	 *
	 * @param mixed $value Valor a escribir en la celda.
	 * @return string
	 */
	public static function csv_safe_field( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * Helper meta: busca primera key válida.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $keys Keys.
	 * @param mixed $default Default.
	 * @return mixed
	 */
	public static function get_post_meta_first( $post_id, $keys, $default = '' ) {
		$post_id = absint( $post_id );
		$keys    = (array) $keys;

		if ( ! $post_id || empty( $keys ) ) {
			return $default;
		}

		foreach ( $keys as $key ) {
			$key = is_string( $key ) ? trim( $key ) : '';

			if ( '' === $key ) {
				continue;
			}

			$value = get_post_meta( $post_id, $key, true );

			if ( '' !== $value && null !== $value ) {
				return $value;
			}
		}

		return $default;
	}

	/**
	 * Limpia caches internas.
	 *
	 * @param int $course_id Curso opcional.
	 * @param int $lesson_id Lección opcional.
	 * @return void
	 */
	public static function flush_runtime_cache( $course_id = 0, $lesson_id = 0 ) {
		$course_id = absint( $course_id );
		$lesson_id = absint( $lesson_id );

		if ( $course_id ) {
			unset( self::$course_lessons_cache[ $course_id ] );
		}

		if ( $lesson_id ) {
			unset( self::$lesson_course_cache[ $lesson_id ] );
		}
	}

	/**
	 * Query interna de lecciones por meta de curso.
	 *
	 * @param int    $course_id Curso.
	 * @param string $meta_key  Meta key.
	 * @return array<int,int>
	 */
	protected static function query_lessons_by_course_meta( $course_id, $meta_key ) {
		$course_id = absint( $course_id );
		$meta_key  = is_string( $meta_key ) ? trim( $meta_key ) : '';

		if ( ! $course_id || '' === $meta_key ) {
			return array();
		}

		$lesson_ids = get_posts(
			array(
				'post_type'              => 'lm_lesson',
				'post_status'            => array( 'publish', 'private' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => array(
					'menu_order' => 'ASC',
					'date'       => 'ASC',
				),
				'meta_key'               => $meta_key,
				'meta_value'             => $course_id,
				'meta_type'              => 'NUMERIC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return is_array( $lesson_ids ) ? array_map( 'absint', $lesson_ids ) : array();
	}

	/**
	 * Normaliza listas de IDs guardadas como array, CSV o valor escalar.
	 *
	 * @param mixed $ids Lista cruda.
	 * @return array<int,int>
	 */
	protected static function normalize_id_list( $ids ) {
		if ( is_string( $ids ) ) {
			$ids = '' === trim( $ids ) ? array() : preg_split( '/\s*,\s*/', trim( $ids ) );
		}

		if ( ! is_array( $ids ) ) {
			$ids = array( $ids );
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Valida expiración mysql/UTC.
	 *
	 * @param string $datetime Fecha mysql.
	 * @return bool
	 */
	protected static function is_access_datetime_expired( $datetime ) {
		$datetime = sanitize_text_field( (string) $datetime );

		if ( '' === $datetime ) {
			return false;
		}

		$timestamp = strtotime( $datetime . ' UTC' );

		if ( ! $timestamp ) {
			$timestamp = strtotime( $datetime );
		}

		if ( ! $timestamp ) {
			return false;
		}

		return $timestamp < (int) current_time( 'timestamp', true );
	}

	/**
	 * Normaliza una entidad comercial para render de landing.
	 *
	 * @param string $type product|course|program.
	 * @param int    $object_id ID entidad.
	 * @return array<string,mixed>
	 */
	protected static function build_commercial_related_item( $type, $object_id ) {
		$type      = sanitize_key( (string) $type );
		$object_id = absint( $object_id );

		if ( ! $object_id ) {
			return array();
		}

		if ( 'product' === $type ) {
			$product = get_post( $object_id );

			if ( ! $product || 'product' !== $product->post_type || 'publish' !== $product->post_status ) {
				return array();
			}

			$subtitle = '';
			$price    = '';

			if ( function_exists( 'wc_get_product' ) ) {
				$wc_product = wc_get_product( $object_id );
				if ( $wc_product ) {
					$price = wp_strip_all_tags( (string) $wc_product->get_price_html() );
					$subtitle = $wc_product->get_short_description();
				}
			}

			if ( '' === $subtitle ) {
				$subtitle = (string) get_post_field( 'post_excerpt', $object_id );
			}

			return array(
				'id'       => $object_id,
				'type'     => 'product',
				'title'    => get_the_title( $object_id ),
				'subtitle' => $subtitle,
				'url'      => get_permalink( $object_id ),
				'price'    => $price,
			);
		}

		$post_type = 'program' === $type ? 'lm_program' : 'lm_course';
		$post      = get_post( $object_id );

		if ( ! $post || $post_type !== $post->post_type || 'publish' !== $post->post_status ) {
			return array();
		}

		$subtitle = 'lm_program' === $post_type
			? (string) get_post_meta( $object_id, '_clms_program_subtitle', true )
			: (string) get_post_meta( $object_id, '_clms_course_subtitle', true );
		$price    = 'lm_program' === $post_type
			? (string) get_post_meta( $object_id, '_clms_program_price', true )
			: (string) get_post_meta( $object_id, '_clms_course_price', true );

		return array(
			'id'       => $object_id,
			'type'     => 'lm_program' === $post_type ? 'program' : 'course',
			'title'    => get_the_title( $object_id ),
			'subtitle' => sanitize_text_field( $subtitle ),
			'url'      => get_permalink( $object_id ),
			'price'    => sanitize_text_field( $price ),
		);
	}
}
