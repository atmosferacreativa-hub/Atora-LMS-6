<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Commerce_Hooks {

	/**
	 * Meta de cursos ya notificados por orden.
	 *
	 * @var string
	 */
	const ORDER_NOTIFIED_META = '_clms_commerce_notified_courses';

	/**
	 * Meta para guardar errores/avisos del flujo.
	 *
	 * @var string
	 */
	const ORDER_LOG_META = '_clms_commerce_log';

	/**
	 * Inicializa hooks.
	 *
	 * Nota: esta clase tiene control exclusivo de los hooks de WooCommerce desde v4.21.1
	 * para evitar procesamiento duplicado. CLMS_Enrollment_Manager ya no escucha estos eventos.
	 */
	public function __construct() {
		// Pago / orden.
		add_action( 'woocommerce_payment_complete', array( $this, 'handle_order_event' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'handle_order_event' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_event' ), 20 );

		// Cuando Woo crea cuenta en checkout.
		add_action( 'woocommerce_created_customer', array( $this, 'handle_created_customer' ), 10, 3 );

		// Hook interno opcional por si tu enrollment class dispara esto.
		add_action( 'clms_commerce_order_processed', array( $this, 'handle_order_event' ), 10, 1 );

		// Acciones manuales de recuperación postcompra.
		add_action( 'admin_post_clms_commerce_retry_activation', array( $this, 'handle_retry_activation' ) );
		add_action( 'admin_post_clms_commerce_resend_access_email', array( $this, 'handle_resend_access_email' ) );
	}

	/**
	 * Ejecuta el flujo principal.
	 *
	 * @param int|WC_Order $order Order ID o WC_Order.
	 * @return void
	 */
	public function handle_order_event( $order ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = is_numeric( $order ) ? wc_get_order( absint( $order ) ) : $order;

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$order_id = absint( $order->get_id() );

		if ( ! $order_id ) {
			return;
		}

		$user_id = $this->resolve_order_user_id( $order );

		if ( ! $user_id ) {
			$this->add_order_note(
				$order,
				'CLMS: no se pudo resolver un usuario para matrícula automática.'
			);
			$this->append_order_log(
				$order_id,
				array(
					'type'    => 'missing_user',
					'message' => __( 'No se pudo resolver usuario desde la orden.', 'atora-lms' ),
					'time'    => current_time( 'mysql' ),
				)
			);
			return;
		}

		$enrollment_result = $this->enroll_user_from_order( $user_id, $order );

		if ( empty( $enrollment_result['courses'] ) ) {
			// Añadir nota de orden solo una vez (evitar duplicados si el hook se dispara varias veces).
			if ( ! get_post_meta( $order_id, '_clms_commerce_no_courses_noted', true ) ) {
				$this->add_order_note(
					$order,
					__( 'CLMS: no se encontraron cursos o programas vinculados en los productos de esta orden.', 'atora-lms' )
				);
				update_post_meta( $order_id, '_clms_commerce_no_courses_noted', '1' );
			}

			$this->append_order_log(
				$order_id,
				array(
					'type'    => 'no_courses',
					'message' => __( 'No se encontraron cursos vinculados en los productos de la orden.', 'atora-lms' ),
					'time'    => current_time( 'mysql' ),
				)
			);
			return;
		}

		$new_account = (bool) get_post_meta( $order_id, '_clms_account_autocreated', true );

		$this->maybe_notify_enrolled_courses( $user_id, $order, $enrollment_result, $new_account );

		do_action( 'clms_commerce_order_after_processed', $order_id, $user_id, $enrollment_result, $order );
	}

	/**
	 * Guarda una referencia cuando Woo crea cliente.
	 *
	 * @param int   $customer_id ID cliente.
	 * @param array $new_customer_data Datos.
	 * @param bool  $password_generated Password generada.
	 * @return void
	 */
	public function handle_created_customer( $customer_id, $new_customer_data = array(), $password_generated = false ) {
		$customer_id = absint( $customer_id );

		if ( ! $customer_id ) {
			return;
		}

		update_user_meta( $customer_id, '_clms_wc_customer_created', current_time( 'mysql' ) );

		if ( ! empty( $new_customer_data['user_email'] ) ) {
			update_user_meta( $customer_id, '_clms_wc_customer_email', sanitize_email( $new_customer_data['user_email'] ) );
		}

		do_action( 'clms_commerce_customer_created', $customer_id, $new_customer_data, $password_generated );
	}

	/**
	 * Resuelve el usuario de la orden.
	 *
	 * @param WC_Order $order Orden.
	 * @return int
	 */
	protected function resolve_order_user_id( $order ) {
		$user_id = absint( $order->get_user_id() );

		if ( $user_id ) {
			return $user_id;
		}

		// Intenta delegar al módulo customer si existe.
		if ( class_exists( 'CLMS_Commerce_Customer' ) ) {
			$customer_module = $this->get_commerce_service( 'customer' );

			if ( $customer_module && method_exists( $customer_module, 'resolve_order_user_id' ) ) {
				$resolved = absint( $customer_module->resolve_order_user_id( $order ) );

				if ( $resolved ) {
					return $resolved;
				}
			}
		}

		$email = sanitize_email( (string) $order->get_billing_email() );

		if ( $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user && ! empty( $user->ID ) ) {
				return absint( $user->ID );
			}
		}

		return 0;
	}

	/**
	 * Matricula usuario desde productos de la orden.
	 *
	 * @param int      $user_id Usuario.
	 * @param WC_Order $order Orden.
	 * @return array
	 */
	protected function enroll_user_from_order( $user_id, $order ) {
		$result = array(
			'courses'          => array(),
			'newly_enrolled'   => array(),
			'already_enrolled' => array(),
			'products'         => array(),
		);

		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return $result;
		}

		// Intenta delegar al módulo enrollment si existe.
		$enrollment_module = $this->get_commerce_service( 'enrollment' );
		if ( $enrollment_module && method_exists( $enrollment_module, 'enroll_user_from_order' ) ) {
			$delegated = $enrollment_module->enroll_user_from_order( $user_id, $order );

			if ( is_array( $delegated ) ) {
				return wp_parse_args(
					$delegated,
					$result
				);
			}
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
				$course_id = absint( get_post_meta( $candidate_product_id, '_clms_linked_course_id', true ) );

				if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
					continue;
				}

				$result['courses'][]  = $course_id;
				$result['products'][] = $candidate_product_id;

				if ( ! class_exists( 'CLMS_Helper' ) || ! method_exists( 'CLMS_Helper', 'user_is_enrolled_in_course' ) || ! method_exists( 'CLMS_Helper', 'enroll_user_in_course' ) ) {
					continue;
				}

				if ( CLMS_Helper::user_is_enrolled_in_course( $user_id, $course_id ) ) {
					$result['already_enrolled'][] = $course_id;
					continue;
				}

				$enrolled = CLMS_Helper::enroll_user_in_course( $user_id, $course_id );

				if ( false !== $enrolled ) {
					$result['newly_enrolled'][] = $course_id;

					$this->add_order_note(
						$order,
						sprintf(
							'CLMS: usuario #%1$d inscrito automáticamente al curso #%2$d (%3$s).',
							$user_id,
							$course_id,
							get_the_title( $course_id )
						)
					);

					do_action( 'clms_commerce_course_enrolled_from_order', $user_id, $course_id, $order->get_id(), $candidate_product_id, $order );
				}
			}
		}

		$result['courses']          = array_values( array_unique( array_map( 'absint', $result['courses'] ) ) );
		$result['newly_enrolled']   = array_values( array_unique( array_map( 'absint', $result['newly_enrolled'] ) ) );
		$result['already_enrolled'] = array_values( array_unique( array_map( 'absint', $result['already_enrolled'] ) ) );
		$result['products']         = array_values( array_unique( array_map( 'absint', $result['products'] ) ) );

		return $result;
	}

	/**
	 * Dispara notificaciones para cursos nuevos no notificados.
	 *
	 * @param int      $user_id Usuario.
	 * @param WC_Order $order Orden.
	 * @param array    $enrollment_result Resultado.
	 * @param bool     $new_account Si la cuenta del usuario fue creada automáticamente para esta orden.
	 * @return void
	 */
	protected function maybe_notify_enrolled_courses( $user_id, $order, $enrollment_result, $new_account = false ) {
		$user_id      = absint( $user_id );
		$order_id     = absint( $order->get_id() );
		$all_courses  = ! empty( $enrollment_result['courses'] ) ? array_map( 'absint', (array) $enrollment_result['courses'] ) : array();
		$new_courses  = ! empty( $enrollment_result['newly_enrolled'] ) ? array_map( 'absint', (array) $enrollment_result['newly_enrolled'] ) : array();
		$notified_map = get_post_meta( $order_id, self::ORDER_NOTIFIED_META, true );
		$notified_map = is_array( $notified_map ) ? array_map( 'absint', $notified_map ) : array();

		if ( empty( $all_courses ) ) {
			return;
		}

		$notifications_module = $this->get_commerce_service( 'notifications' );

		foreach ( $all_courses as $course_id ) {
			if ( in_array( $course_id, $notified_map, true ) ) {
				continue;
			}

			$is_new_access = in_array( $course_id, $new_courses, true );

			if ( $notifications_module && method_exists( $notifications_module, 'send_course_access_notification' ) ) {
				$notifications_module->send_course_access_notification(
					$user_id,
					$course_id,
					$order,
					array(
						'is_new_access' => $is_new_access,
						'new_account'   => $new_account,
					)
				);
			}

			$notified_map[] = $course_id;
		}

		update_post_meta(
			$order_id,
			self::ORDER_NOTIFIED_META,
			array_values( array_unique( array_map( 'absint', $notified_map ) ) )
		);
	}

	/**
	 * Añade nota al pedido.
	 *
	 * @param WC_Order $order Orden.
	 * @param string   $note Nota.
	 * @return void
	 */
	protected function add_order_note( $order, $note ) {
		if ( ! $order || ! method_exists( $order, 'add_order_note' ) ) {
			return;
		}

		$order->add_order_note( wp_strip_all_tags( (string) $note ) );
	}

	/**
	 * Agrega log simple al pedido.
	 *
	 * @param int   $order_id Pedido.
	 * @param array $entry Entrada.
	 * @return void
	 */
	protected function append_order_log( $order_id, $entry ) {
		$order_id = absint( $order_id );
		$entry    = is_array( $entry ) ? $entry : array();

		if ( ! $order_id ) {
			return;
		}

		$log = get_post_meta( $order_id, self::ORDER_LOG_META, true );
		$log = is_array( $log ) ? $log : array();

		$log[] = array(
			'type'    => isset( $entry['type'] ) ? sanitize_key( $entry['type'] ) : 'general',
			'message' => isset( $entry['message'] ) ? sanitize_text_field( $entry['message'] ) : '',
			'time'    => isset( $entry['time'] ) ? sanitize_text_field( $entry['time'] ) : current_time( 'mysql' ),
		);

		if ( count( $log ) > 30 ) {
			$log = array_slice( $log, -30 );
		}

		update_post_meta( $order_id, self::ORDER_LOG_META, $log );
	}

	/**
	 * Obtiene servicio desde el contenedor commerce si existe.
	 *
	 * @param string $service Servicio.
	 * @return object|null
	 */
	protected function get_commerce_service( $service ) {
		$service = sanitize_key( (string) $service );

		if ( ! $service ) {
			return null;
		}

		if ( function_exists( 'clms_core' ) ) {
			$core = clms_core();

			if ( $core && method_exists( $core, 'get_module' ) ) {
				$commerce = $core->get_module( 'CLMS_Commerce' );

				if ( $commerce && method_exists( $commerce, 'get' ) ) {
					return $commerce->get( $service );
				}
			}
		}

		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'module' ) ) {
			$commerce = clms_core('CLMS_Commerce');

			if ( $commerce && method_exists( $commerce, 'get' ) ) {
				return $commerce->get( $service );
			}
		}

		return null;
	}

	/**
	 * Reintenta el procesamiento de activación para una orden.
	 *
	 * @return void
	 */
	public function handle_retry_activation() {
		if ( ! current_user_can( 'clms_manage_commerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para esta acción.', 'atora-lms' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;

		if ( ! $order_id ) {
			wp_die( esc_html__( 'Pedido inválido.', 'atora-lms' ) );
		}

		check_admin_referer( 'clms_commerce_retry_activation_' . $order_id );

		$this->handle_order_event( $order_id );

		$redirect_url = add_query_arg(
			array(
				'page'         => 'clms-commercial-operations',
				'commerce_msg' => 'retry_ok',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Reenvía email de acceso para cursos de una orden.
	 *
	 * @return void
	 */
	public function handle_resend_access_email() {
		if ( ! current_user_can( 'clms_manage_commerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para esta acción.', 'atora-lms' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;

		if ( ! $order_id ) {
			wp_die( esc_html__( 'Pedido inválido.', 'atora-lms' ) );
		}

		check_admin_referer( 'clms_commerce_resend_access_email_' . $order_id );

		$success = $this->resend_order_access_email( $order_id );

		$redirect_url = add_query_arg(
			array(
				'page'         => 'clms-commercial-operations',
				'commerce_msg' => $success ? 'resend_ok' : 'resend_error',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Reenvía notificaciones de acceso al curso para una orden.
	 *
	 * @param int|WC_Order $order Orden o ID.
	 * @return bool
	 */
	protected function resend_order_access_email( $order ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$order = is_numeric( $order ) ? wc_get_order( absint( $order ) ) : $order;

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}

		$order_id = absint( $order->get_id() );
		$user_id  = $this->resolve_order_user_id( $order );

		if ( ! $order_id || ! $user_id ) {
			return false;
		}

		$course_ids = array_map( 'absint', (array) get_post_meta( $order_id, '_clms_commerce_enrolled_courses', true ) );
		$course_ids = array_values( array_filter( $course_ids ) );

		if ( empty( $course_ids ) ) {
			$enrollment_module = $this->get_commerce_service( 'enrollment' );
			if ( $enrollment_module && method_exists( $enrollment_module, 'get_courses_from_order' ) ) {
				$courses_from_order = (array) $enrollment_module->get_courses_from_order( $order );
				foreach ( $courses_from_order as $row ) {
					$course_id = isset( $row['course_id'] ) ? absint( $row['course_id'] ) : 0;
					if ( $course_id ) {
						$course_ids[] = $course_id;
					}
				}
				$course_ids = array_values( array_unique( array_filter( array_map( 'absint', $course_ids ) ) ) );
			}
		}

		if ( empty( $course_ids ) ) {
			$this->append_order_log(
				$order_id,
				array(
					'type'    => 'resend_skipped',
					'message' => __( 'No se encontraron cursos para reenviar el email de acceso.', 'atora-lms' ),
					'time'    => current_time( 'mysql' ),
				)
			);
			return false;
		}

		$notifications = $this->get_commerce_service( 'notifications' );
		if ( ! $notifications || ! method_exists( $notifications, 'send_course_access_notification' ) ) {
			return false;
		}

		foreach ( $course_ids as $course_id ) {
			$notifications->send_course_access_notification(
				$user_id,
				$course_id,
				$order,
				array(
					'is_new_access' => false,
				)
			);
		}

		$this->add_order_note(
			$order,
			sprintf(
				/* translators: %d: order ID */
				__( 'CLMS: reenvío manual de email de acceso ejecutado para el pedido #%d.', 'atora-lms' ),
				$order_id
			)
		);

		$this->append_order_log(
			$order_id,
			array(
				'type'    => 'resend_success',
				'message' => __( 'Reenvío manual de email de acceso ejecutado.', 'atora-lms' ),
				'time'    => current_time( 'mysql' ),
			)
		);

		return true;
	}
}
