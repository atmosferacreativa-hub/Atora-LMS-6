<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Commerce_Readiness {

	/**
	 * Devuelve checklist base para academia lista para vender.
	 *
	 * @return array<string,bool>
	 */
	public function get_launch_checklist() {
		$woocommerce_connected = class_exists( 'WooCommerce' );
		$diagnostics_ok        = $this->has_healthy_academic_diagnostics();

		return array(
			'lms_pages_created'          => $this->has_lms_pages(),
			'woocommerce_connected'      => $woocommerce_connected,
			'emails_configured'          => $this->has_email_identity(),
			'smtp_active'                => $this->has_smtp_signal(),
			'certificates_configured'    => $this->has_certificates_ready(),
			'role_menus_configured'      => $this->has_role_menus(),
			'courses_published'          => $this->has_published_courses(),
			'commercial_landings_ready'  => $this->has_commercial_landings(),
			'academic_diagnostics_ok'    => $diagnostics_ok,
			'payment_products_linked'    => $woocommerce_connected ? $this->has_linked_products() : false,
		);
	}

	/**
	 * Filas para tabla de estado comercial.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_checklist_rows() {
		$checks = $this->get_launch_checklist();

		$rows = array(
			array(
				'key'    => 'lms_pages_created',
				'area'   => __( 'Páginas LMS creadas', 'atora-lms' ),
				'ok'     => ! empty( $checks['lms_pages_created'] ),
				'action' => array(
					'label' => __( 'Configurar páginas', 'atora-lms' ),
					'url'   => admin_url( 'edit.php?post_type=page' ),
				),
			),
			array(
				'key'    => 'woocommerce_connected',
				'area'   => __( 'WooCommerce conectado', 'atora-lms' ),
				'ok'     => ! empty( $checks['woocommerce_connected'] ),
				'action' => array(
					'label' => __( 'Revisar WooCommerce', 'atora-lms' ),
					'url'   => class_exists( 'WooCommerce' ) ? admin_url( 'admin.php?page=wc-settings' ) : admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ),
				),
			),
			array(
				'key'    => 'emails_configured',
				'area'   => __( 'Emails configurados', 'atora-lms' ),
				'ok'     => ! empty( $checks['emails_configured'] ),
				'action' => array(
					'label' => __( 'Configurar emails', 'atora-lms' ),
					'url'   => admin_url( 'admin.php?page=clms-settings&tab=academia' ),
				),
			),
			array(
				'key'    => 'smtp_active',
				'area'   => __( 'SMTP activo', 'atora-lms' ),
				'ok'     => ! empty( $checks['smtp_active'] ),
				'action' => array(
					'label' => __( 'Revisar SMTP', 'atora-lms' ),
					'url'   => admin_url( 'plugin-install.php?s=smtp&tab=search&type=term' ),
				),
			),
			array(
				'key'    => 'certificates_configured',
				'area'   => __( 'Certificados configurados', 'atora-lms' ),
				'ok'     => ! empty( $checks['certificates_configured'] ),
				'action' => array(
					'label' => __( 'Revisar certificados', 'atora-lms' ),
					'url'   => admin_url( 'options-general.php' ),
				),
			),
			array(
				'key'    => 'role_menus_configured',
				'area'   => __( 'Menús por rol', 'atora-lms' ),
				'ok'     => ! empty( $checks['role_menus_configured'] ),
				'action' => array(
					'label' => __( 'Configurar navegación', 'atora-lms' ),
					'url'   => admin_url( 'admin.php?page=clms-settings&tab=navigation' ),
				),
			),
			array(
				'key'    => 'courses_published',
				'area'   => __( 'Cursos publicados', 'atora-lms' ),
				'ok'     => ! empty( $checks['courses_published'] ),
				'action' => array(
					'label' => __( 'Revisar cursos', 'atora-lms' ),
					'url'   => admin_url( 'edit.php?post_type=lm_course' ),
				),
			),
			array(
				'key'    => 'commercial_landings_ready',
				'area'   => __( 'Landings comerciales listas', 'atora-lms' ),
				'ok'     => ! empty( $checks['commercial_landings_ready'] ),
				'action' => array(
					'label' => __( 'Revisar landings', 'atora-lms' ),
					'url'   => admin_url( 'edit.php?post_type=lm_course' ),
				),
			),
				array(
					'key'    => 'academic_diagnostics_ok',
					'area'   => __( 'Diagnóstico académico', 'atora-lms' ),
					'ok'     => ! empty( $checks['academic_diagnostics_ok'] ),
					'action' => array(
						'label' => __( 'Ver diagnóstico académico', 'atora-lms' ),
						'url'   => admin_url( 'admin.php?page=clms-academic-reports' ),
					),
				),
			array(
				'key'    => 'payment_products_linked',
				'area'   => __( 'Productos vinculados', 'atora-lms' ),
				'ok'     => ! empty( $checks['payment_products_linked'] ),
				'action' => array(
					'label' => __( 'Vincular productos', 'atora-lms' ),
					'url'   => admin_url( 'edit.php?post_type=product' ),
				),
			),
		);

		return apply_filters( 'clms_commerce_launch_checklist_rows', $rows, $checks );
	}

	/**
	 * Obtiene un resumen de activaciones de compra recientes.
	 *
	 * @param int $limit Límite.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_recent_activation_rows( $limit = 10 ) {
		$limit = max( 1, min( 30, absint( $limit ) ) );

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'limit'   => $limit,
				'orderby' => 'date',
				'order'   => 'DESC',
				'status'  => array( 'processing', 'completed' ),
			)
		);

		if ( empty( $orders ) ) {
			return array();
		}

		$rows = array();

		foreach ( $orders as $order ) {
			if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
				continue;
			}

			$order_id = absint( $order->get_id() );
			if ( ! $order_id ) {
				continue;
			}

			$user_id     = absint( $order->get_user_id() );
			$user        = $user_id ? get_user_by( 'id', $user_id ) : null;
			$user_name   = $user ? ( $user->display_name ? $user->display_name : $user->user_login ) : '';
			$user_email  = $user && ! empty( $user->user_email ) ? $user->user_email : sanitize_email( (string) $order->get_billing_email() );
			$item_labels = array();

			foreach ( $order->get_items() as $item ) {
				if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
					continue;
				}
				$item_labels[] = sanitize_text_field( (string) $item->get_name() );
			}

			$item_labels = array_values( array_unique( array_filter( $item_labels ) ) );

			$course_ids    = array_map( 'absint', (array) get_post_meta( $order_id, '_clms_commerce_enrolled_courses', true ) );
			$program_ids   = array_map( 'absint', (array) get_post_meta( $order_id, '_clms_commerce_enrolled_programs', true ) );
			$notified      = array_map( 'absint', (array) get_post_meta( $order_id, '_clms_commerce_notified_courses', true ) );
			$order_log     = get_post_meta( $order_id, '_clms_commerce_log', true );
			$order_log     = is_array( $order_log ) ? $order_log : array();
			$last_log      = ! empty( $order_log ) ? end( $order_log ) : array();
			$last_message  = is_array( $last_log ) && ! empty( $last_log['message'] ) ? sanitize_text_field( (string) $last_log['message'] ) : '';
			$has_error_log = false;

			foreach ( $order_log as $log_entry ) {
				$log_type = is_array( $log_entry ) && ! empty( $log_entry['type'] ) ? sanitize_key( (string) $log_entry['type'] ) : '';
				if ( in_array( $log_type, array( 'missing_user', 'no_courses', 'error' ), true ) ) {
					$has_error_log = true;
					break;
				}
			}

			$retry_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'clms_commerce_retry_activation',
						'order_id' => $order_id,
					),
					admin_url( 'admin-post.php' )
				),
				'clms_commerce_retry_activation_' . $order_id
			);

			$resend_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'   => 'clms_commerce_resend_access_email',
						'order_id' => $order_id,
					),
					admin_url( 'admin-post.php' )
				),
				'clms_commerce_resend_access_email_' . $order_id
			);

			$rows[] = array(
				'order_id'         => $order_id,
				'order_url'        => admin_url( 'post.php?post=' . $order_id . '&action=edit' ),
				'status'           => sanitize_key( (string) $order->get_status() ),
				'user_id'          => $user_id,
				'user_name'        => $user_name,
				'user_email'       => $user_email,
				'products'         => array_slice( $item_labels, 0, 3 ),
				'course_ids'       => array_values( array_filter( $course_ids ) ),
				'program_ids'      => array_values( array_filter( $program_ids ) ),
				'notified_courses' => array_values( array_filter( $notified ) ),
				'last_log'         => $last_message,
				'has_error'        => $has_error_log,
				'created_at'       => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '',
				'total'            => wp_strip_all_tags( (string) $order->get_formatted_order_total() ),
				'retry_url'        => $retry_url,
				'resend_url'       => $resend_url,
			);
		}

		return $rows;
	}

	protected function has_lms_pages() {
		$dashboard_page_id = absint( get_option( 'clms_student_dashboard_page_id', 0 ) );
		if ( $dashboard_page_id && get_post( $dashboard_page_id ) ) {
			return true;
		}

		$page = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 12,
				's'              => 'clms_student_dashboard',
				'fields'         => 'ids',
			)
		);

		return ! empty( $page );
	}

	protected function has_email_identity() {
		$academy = class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_academy_settings' )
			? (array) CLMS_Settings::get_academy_settings()
			: (array) get_option( 'clms_academy_settings', array() );

		$academy_name  = ! empty( $academy['academy_name'] ) ? sanitize_text_field( (string) $academy['academy_name'] ) : '';
		$contact_email = ! empty( $academy['contact_email'] ) ? sanitize_email( (string) $academy['contact_email'] ) : '';

		if ( $academy_name && $contact_email && is_email( $contact_email ) ) {
			return true;
		}

		$admin_email = sanitize_email( (string) get_option( 'admin_email' ) );
		return (bool) ( $academy_name && $admin_email && is_email( $admin_email ) );
	}

	protected function has_smtp_signal() {
		if ( class_exists( 'WPMS_SMTP' ) || class_exists( 'WPMailSMTP\Core' ) || defined( 'WPMS_ON' ) ) {
			return true;
		}

		return has_action( 'phpmailer_init' ) > 0;
	}

	protected function has_certificates_ready() {
		if ( ! class_exists( 'CLMS_Certificates' ) ) {
			return false;
		}

		$enabled = (bool) get_option( 'clms_certificates_enabled', true );
		$min_p   = absint( get_option( 'clms_certificate_min_progress', 100 ) );
		$min_g   = absint( get_option( 'clms_certificate_passing_grade', 70 ) );

		return $enabled && $min_p > 0 && $min_g > 0;
	}

	protected function has_role_menus() {
		$navigation = (array) get_option( 'clms_navigation_settings', array() );
		$keys       = array(
			'header_menu_guest',
			'header_menu_student',
			'header_menu_instructor',
			'header_menu_collaborator',
			'header_menu_admin',
		);

		foreach ( $keys as $key ) {
			if ( ! empty( $navigation[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	protected function has_published_courses() {
		$count = wp_count_posts( 'lm_course' );
		return $count && ! empty( $count->publish );
	}

	protected function has_commercial_landings() {
		$course_ids = get_posts(
			array(
				'post_type'      => 'lm_course',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 5,
				'meta_query'     => array(
					array(
						'key'   => '_clms_commercial_mode',
						'value' => 'commercial',
					),
				),
			)
		);

		if ( ! empty( $course_ids ) ) {
			return true;
		}

		$program_ids = get_posts(
			array(
				'post_type'      => 'lm_program',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 5,
				'meta_query'     => array(
					array(
						'key'   => '_clms_commercial_mode',
						'value' => 'commercial',
					),
				),
			)
		);

		return ! empty( $program_ids );
	}

	protected function has_healthy_academic_diagnostics() {
		$diagnostics = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'module' )
			? clms_core('CLMS_Academic_Diagnostics_Service')
			: null;

		if ( ! $diagnostics || ! method_exists( $diagnostics, 'diagnose_course' ) ) {
			return true;
		}

		$course_ids = get_posts(
			array(
				'post_type'      => 'lm_course',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 8,
			)
		);

		if ( empty( $course_ids ) ) {
			return true;
		}

		foreach ( $course_ids as $course_id ) {
			$diag = (array) $diagnostics->diagnose_course( absint( $course_id ) );
			if ( ! empty( $diag['errors'] ) ) {
				return false;
			}
		}

		return true;
	}

	protected function has_linked_products() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		$products = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => '_clms_linked_course_id',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_clms_linked_program_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return ! empty( $products );
	}
}
