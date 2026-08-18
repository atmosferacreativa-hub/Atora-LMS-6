<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Commerce_Enrollment {

	/**
	 * Meta en la orden para registrar cursos ya procesados.
	 *
	 * @var string
	 */
	const ORDER_ENROLLED_COURSES_META = '_clms_commerce_enrolled_courses';

	/**
	 * Meta en la orden para registrar programas ya procesados.
	 *
	 * @var string
	 */
	const ORDER_ENROLLED_PROGRAMS_META = '_clms_commerce_enrolled_programs';

	/**
	 * Matricula usuario a todos los cursos encontrados en una orden.
	 *
	 * @param int      $user_id Usuario.
	 * @param WC_Order $order   Orden WooCommerce.
	 * @return array
	 */
	public function enroll_user_from_order( $user_id, $order ) {
		$result = array(
			'courses'          => array(),
			'newly_enrolled'   => array(),
			'already_enrolled' => array(),
			'programs'         => array(),
			'newly_enrolled_programs' => array(),
			'already_enrolled_programs' => array(),
			'products'         => array(),
			'errors'           => array(),
		);

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			$result['errors'][] = 'Usuario inválido para matrícula.';
			return $result;
		}

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			$result['errors'][] = 'Orden inválida.';
			return $result;
		}

		if ( ! class_exists( 'CLMS_Helper' ) ) {
			$result['errors'][] = 'CLMS_Helper no está disponible.';
			return $result;
		}

		if ( ! method_exists( 'CLMS_Helper', 'user_is_enrolled_in_course' ) || ! method_exists( 'CLMS_Helper', 'enroll_user_in_course' ) ) {
			$result['errors'][] = 'Faltan métodos de matrícula en CLMS_Helper.';
			return $result;
		}

		$order_id = absint( $order->get_id() );

		$processed_courses = get_post_meta( $order_id, self::ORDER_ENROLLED_COURSES_META, true );
		$processed_courses = is_array( $processed_courses ) ? array_map( 'absint', $processed_courses ) : array();
		$processed_programs = get_post_meta( $order_id, self::ORDER_ENROLLED_PROGRAMS_META, true );
		$processed_programs = is_array( $processed_programs ) ? array_map( 'absint', $processed_programs ) : array();

		$access_map = $this->get_access_map_from_order( $order );

		if ( empty( $access_map ) ) {
			return $result;
		}

		foreach ( $access_map as $entry ) {
			$product_id = ! empty( $entry['product_id'] ) ? absint( $entry['product_id'] ) : 0;
			$course_ids = ! empty( $entry['course_ids'] ) && is_array( $entry['course_ids'] ) ? array_values( array_filter( array_map( 'absint', $entry['course_ids'] ) ) ) : array();
			$program_ids = ! empty( $entry['program_ids'] ) && is_array( $entry['program_ids'] ) ? array_values( array_filter( array_map( 'absint', $entry['program_ids'] ) ) ) : array();
			$access_days = ! empty( $entry['access_days'] ) ? absint( $entry['access_days'] ) : 0;
			$expires_at  = $access_days > 0 ? gmdate( 'Y-m-d H:i:s', time() + ( $access_days * DAY_IN_SECONDS ) ) : '';

			if ( ! $product_id || ( empty( $course_ids ) && empty( $program_ids ) ) ) {
				continue;
			}

			$result['products'][] = $product_id;

			foreach ( $program_ids as $program_id ) {
				$result['programs'][] = $program_id;

				if ( in_array( $program_id, $processed_programs, true ) ) {
					if ( CLMS_Helper::user_is_enrolled_in_program( $user_id, $program_id ) ) {
						$result['already_enrolled_programs'][] = $program_id;
					}
					if ( $expires_at ) {
						CLMS_Helper::set_user_program_access_expiration( $user_id, $program_id, $expires_at );
					}
					continue;
				}

				if ( CLMS_Helper::user_is_enrolled_in_program( $user_id, $program_id ) ) {
					$result['already_enrolled_programs'][] = $program_id;
					$processed_programs[]                  = $program_id;
					if ( $expires_at ) {
						CLMS_Helper::set_user_program_access_expiration( $user_id, $program_id, $expires_at );
					}
					continue;
				}

				$enrolled = CLMS_Helper::enroll_user_in_program( $user_id, $program_id );

				if ( is_wp_error( $enrolled ) ) {
					$result['errors'][] = sprintf(
						'No se pudo matricular al usuario #%1$d en el programa #%2$d.',
						$user_id,
						$program_id
					);
					continue;
				}

				$result['newly_enrolled_programs'][] = $program_id;
				$processed_programs[]                = $program_id;

				if ( $expires_at ) {
					CLMS_Helper::set_user_program_access_expiration( $user_id, $program_id, $expires_at );
				}

				if ( method_exists( $order, 'add_order_note' ) ) {
					$order->add_order_note(
						sprintf(
							'CLMS: usuario #%1$d inscrito automáticamente al programa #%2$d (%3$s) desde producto #%4$d.',
							$user_id,
							$program_id,
							get_the_title( $program_id ),
							$product_id
						)
					);
				}
			}

			foreach ( $course_ids as $course_id ) {
				$result['courses'][]  = $course_id;

				if ( in_array( $course_id, $processed_courses, true ) ) {
					if ( CLMS_Helper::user_is_enrolled_in_course( $user_id, $course_id ) ) {
						$result['already_enrolled'][] = $course_id;
					}
					if ( $expires_at ) {
						CLMS_Helper::set_user_course_access_expiration( $user_id, $course_id, $expires_at );
					}
					continue;
				}

				if ( CLMS_Helper::user_is_enrolled_in_course( $user_id, $course_id ) ) {
					$result['already_enrolled'][] = $course_id;
					$processed_courses[]          = $course_id;
					if ( $expires_at ) {
						CLMS_Helper::set_user_course_access_expiration( $user_id, $course_id, $expires_at );
					}
					continue;
				}

				$enrolled = CLMS_Helper::enroll_user_in_course( $user_id, $course_id );

				if ( false === $enrolled ) {
					$result['errors'][] = sprintf(
						'No se pudo matricular al usuario #%1$d en el curso #%2$d.',
						$user_id,
						$course_id
					);
					continue;
				}

				$result['newly_enrolled'][] = $course_id;
				$processed_courses[]        = $course_id;

				if ( $expires_at ) {
					CLMS_Helper::set_user_course_access_expiration( $user_id, $course_id, $expires_at );
				}

				if ( method_exists( $order, 'add_order_note' ) ) {
					$order->add_order_note(
						sprintf(
							'CLMS: usuario #%1$d inscrito automáticamente al curso #%2$d (%3$s) desde producto #%4$d.',
							$user_id,
							$course_id,
							get_the_title( $course_id ),
							$product_id
						)
					);
				}

				do_action( 'clms_commerce_user_enrolled_from_order', $user_id, $course_id, $order_id, $product_id, $order );
			}
		}

		$result['products']         = array_values( array_unique( array_map( 'absint', $result['products'] ) ) );
		$result['courses']          = array_values( array_unique( array_map( 'absint', $result['courses'] ) ) );
		$result['newly_enrolled']   = array_values( array_unique( array_map( 'absint', $result['newly_enrolled'] ) ) );
		$result['already_enrolled'] = array_values( array_unique( array_map( 'absint', $result['already_enrolled'] ) ) );
		$result['programs']         = array_values( array_unique( array_map( 'absint', $result['programs'] ) ) );
		$result['newly_enrolled_programs'] = array_values( array_unique( array_map( 'absint', $result['newly_enrolled_programs'] ) ) );
		$result['already_enrolled_programs'] = array_values( array_unique( array_map( 'absint', $result['already_enrolled_programs'] ) ) );

		update_post_meta(
			$order_id,
			self::ORDER_ENROLLED_COURSES_META,
			array_values( array_unique( array_map( 'absint', $processed_courses ) ) )
		);
		update_post_meta(
			$order_id,
			self::ORDER_ENROLLED_PROGRAMS_META,
			array_values( array_unique( array_map( 'absint', $processed_programs ) ) )
		);

		do_action( 'clms_commerce_enrollment_result', $user_id, $order_id, $result, $order );

		return $result;
	}

	/**
	 * Obtiene los cursos vinculados a los productos de una orden.
	 *
	 * Devuelve un array de entradas:
	 * array(
	 *   array(
	 *     'product_id' => 123,
	 *     'course_id'  => 456,
	 *   )
	 * )
	 *
	 * @param WC_Order $order Orden.
	 * @return array
	 */
	public function get_courses_from_order( $order ) {
		$rows = array();

		foreach ( $this->get_access_map_from_order( $order ) as $row ) {
			foreach ( (array) $row['course_ids'] as $course_id ) {
				$rows[] = array(
					'product_id' => absint( $row['product_id'] ),
					'course_id'  => absint( $course_id ),
				);
			}
		}

		$unique = array();
		$seen   = array();

		foreach ( $rows as $row ) {
			$key = absint( $row['product_id'] ) . ':' . absint( $row['course_id'] );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$unique[]     = $row;
		}

		return $unique;
	}

	/**
	 * Obtiene mapa de entitlements desde una orden.
	 *
	 * @param WC_Order $order Orden.
	 * @return array
	 */
	public function get_access_map_from_order( $order ) {
		$rows = array();

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return $rows;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$product_id   = absint( $item->get_product_id() );
			$variation_id = absint( $item->get_variation_id() );

			$candidate_product_ids = array_filter(
				array_unique(
					array(
						$product_id,
						$variation_id,
					)
				)
			);

			foreach ( $candidate_product_ids as $candidate_product_id ) {
				$map = class_exists( 'CLMS_WooCommerce' ) && method_exists( 'CLMS_WooCommerce', 'get_product_access_map' )
					? CLMS_WooCommerce::get_product_access_map( $candidate_product_id )
					: array();

				if ( empty( $map ) ) {
					$course_id = $this->get_course_id_for_product( $candidate_product_id );

					if ( ! $course_id ) {
						continue;
					}

					$map = array(
						'mode'            => 'course',
						'course_id'       => $course_id,
						'program_id'      => 0,
						'bundle_courses'  => array(),
						'bundle_programs' => array(),
						'access_days'     => 0,
					);
				}

				$course_ids = array();
				$program_ids = array();

				if ( ! empty( $map['course_id'] ) ) {
					$course_ids[] = absint( $map['course_id'] );
				}

				if ( ! empty( $map['program_id'] ) ) {
					$program_ids[] = absint( $map['program_id'] );
				}

				$course_ids  = array_values( array_unique( array_merge( $course_ids, isset( $map['bundle_courses'] ) && is_array( $map['bundle_courses'] ) ? array_map( 'absint', $map['bundle_courses'] ) : array() ) ) );
				$program_ids = array_values( array_unique( array_merge( $program_ids, isset( $map['bundle_programs'] ) && is_array( $map['bundle_programs'] ) ? array_map( 'absint', $map['bundle_programs'] ) : array() ) ) );

				if ( empty( $course_ids ) && empty( $program_ids ) ) {
					continue;
				}

				$rows[] = array(
					'product_id'  => absint( $candidate_product_id ),
					'mode'        => isset( $map['mode'] ) ? sanitize_key( (string) $map['mode'] ) : 'course',
					'course_ids'  => $course_ids,
					'program_ids' => $program_ids,
					'access_days' => isset( $map['access_days'] ) ? absint( $map['access_days'] ) : 0,
				);
			}
		}

		return $rows;
	}

	/**
	 * Resuelve curso desde producto o variación.
	 *
	 * @param int $product_id Producto.
	 * @return int
	 */
	public function get_course_id_for_product( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return 0;
		}

		$course_id = 0;

		if ( class_exists( 'CLMS_WooCommerce' ) && method_exists( 'CLMS_WooCommerce', 'get_course_id_for_product' ) ) {
			$course_id = absint( CLMS_WooCommerce::get_course_id_for_product( $product_id ) );
		} else {
			$course_id = absint( get_post_meta( $product_id, '_clms_linked_course_id', true ) );
		}

		if ( $course_id && 'lm_course' === get_post_type( $course_id ) ) {
			return $course_id;
		}

		// Fallback: si es una variación, intenta con el padre.
		$parent_id = wp_get_post_parent_id( $product_id );
		if ( $parent_id ) {
			if ( class_exists( 'CLMS_WooCommerce' ) && method_exists( 'CLMS_WooCommerce', 'get_course_id_for_product' ) ) {
				$course_id = absint( CLMS_WooCommerce::get_course_id_for_product( $parent_id ) );
			} else {
				$course_id = absint( get_post_meta( $parent_id, '_clms_linked_course_id', true ) );
			}

			if ( $course_id && 'lm_course' === get_post_type( $course_id ) ) {
				return $course_id;
			}
		}

		return 0;
	}

	/**
	 * Comprueba si una orden tiene al menos un curso vinculado.
	 *
	 * @param WC_Order $order Orden.
	 * @return bool
	 */
	public function order_has_courses( $order ) {
		$rows = $this->get_courses_from_order( $order );
		return ! empty( $rows );
	}

	/**
	 * Obtiene solo IDs de cursos únicos desde una orden.
	 *
	 * @param WC_Order $order Orden.
	 * @return array
	 */
	public function get_course_ids_from_order( $order ) {
		$rows       = $this->get_courses_from_order( $order );
		$course_ids = array();

		foreach ( $rows as $row ) {
			if ( ! empty( $row['course_id'] ) ) {
				$course_ids[] = absint( $row['course_id'] );
			}
		}

		return array_values( array_unique( $course_ids ) );
	}

	/**
	 * Obtiene solo IDs de productos vinculados a cursos desde una orden.
	 *
	 * @param WC_Order $order Orden.
	 * @return array
	 */
	public function get_product_ids_from_order( $order ) {
		$rows        = $this->get_courses_from_order( $order );
		$product_ids = array();

		foreach ( $rows as $row ) {
			if ( ! empty( $row['product_id'] ) ) {
				$product_ids[] = absint( $row['product_id'] );
			}
		}

		return array_values( array_unique( $product_ids ) );
	}
}
