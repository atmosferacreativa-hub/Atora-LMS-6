<?php
/**
 * Pedidos por institución (6.41.0).
 *
 * Los pedidos de WooCommerce no tenían institución: con varias instituciones en un mismo WordPress, el Auxiliar
 * administrativo de una veía los de toda la tienda. Aquí:
 *
 * - Cada pedido guarda `_atora_institution_id`, deducida de sus productos ligados a un curso (`_clms_linked_course_id`)
 *   o a un programa (`_clms_linked_program_id`). Un pedido que mezcla instituciones o no tiene productos de ATORA queda
 *   en 0: solo lo ve quien administra la tienda.
 * - Quien solo tiene acceso comercial de su institución (Auxiliar administrativo: `atora_view_finance` sin
 *   `manage_woocommerce`) ve en las listas solo los pedidos de su institución y no puede abrir, editar ni reembolsar los
 *   demás (pantallas clásica y HPOS, AJAX de reembolso y API REST de WooCommerce).
 *
 * @package ATORA_LMS
 * @since 6.41.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Order_Institution {

	const META = '_atora_institution_id';

	/** Para pruebas: cómo se carga un pedido por id (por defecto `wc_get_order`). @var callable|null */
	public static $loader = null;

	public static function boot(): void {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'assign' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'assign' ) );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'assign_by_id' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'assign_by_id' ), 5, 1 );

		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'filter_list_args' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_legacy_list' ) );
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 20, 4 );
		add_action( 'load-woocommerce_page_wc-orders', array( __CLASS__, 'guard_hpos_edit' ) );
		add_action( 'wp_ajax_woocommerce_refund_line_items', array( __CLASS__, 'guard_refund' ), 1 );
		add_filter( 'woocommerce_rest_check_permissions', array( __CLASS__, 'rest_permissions' ), 20, 4 );
	}

	// ── Institución del pedido ────────────────────────────────────────────

	/**
	 * Institución que corresponde a los productos del pedido (0: mezcla o sin productos de ATORA).
	 *
	 * @param object $order Objeto con `get_items()`; cada ítem con `get_product_id()` (y `get_variation_id()`).
	 */
	public static function resolve( $order ): int {
		$found = array();
		foreach ( (array) $order->get_items() as $item ) {
			$ids = array( absint( $item->get_product_id() ) );
			if ( method_exists( $item, 'get_variation_id' ) ) {
				$ids[] = absint( $item->get_variation_id() );
			}
			foreach ( array_filter( $ids ) as $product_id ) {
				$inst = self::product_institution( $product_id );
				if ( null !== $inst ) {
					$found[ $inst ] = true;
					break;
				}
			}
		}
		$keys = array_keys( $found );
		return 1 === count( $keys ) ? (int) $keys[0] : 0;
	}

	/** Institución de un producto ligado a un curso o programa; null si no es de ATORA. */
	public static function product_institution( int $product_id ): ?int {
		$course = absint( get_post_meta( $product_id, '_clms_linked_course_id', true ) );
		if ( $course > 0 ) {
			return class_exists( 'ATORA_Teacher_Scope' ) ? ATORA_Teacher_Scope::course_institution( $course ) : 0;
		}
		$program = absint( get_post_meta( $product_id, '_clms_linked_program_id', true ) );
		if ( $program > 0 ) {
			global $wpdb;
			$inst = (int) $wpdb->get_var( $wpdb->prepare( "SELECT institution_id FROM {$wpdb->prefix}atora_programs WHERE wp_post_id = %d LIMIT 1", $program ) ); // phpcs:ignore WordPress.DB
			return $inst > 0 ? $inst : absint( get_option( 'atora_default_institution', 0 ) );
		}
		return null;
	}

	/** Guarda la institución en el pedido. @param object $order */
	public static function assign( $order ): int {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return 0;
		}
		$inst = self::resolve( $order );
		$order->update_meta_data( self::META, (string) $inst );
		if ( method_exists( $order, 'save_meta_data' ) ) {
			$order->save_meta_data();
		} elseif ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
		return $inst;
	}

	public static function assign_by_id( $order_id ): void {
		$order = self::load( absint( $order_id ) );
		if ( $order && '' === (string) $order->get_meta( self::META ) ) {
			self::assign( $order );
		}
	}

	/** Institución guardada (o calculada y guardada si el pedido es anterior a 6.41.0). @param object $order */
	public static function institution_of( $order ): int {
		$stored = (string) $order->get_meta( self::META );
		return '' !== $stored ? (int) $stored : self::assign( $order );
	}

	// ── Quién ve qué ──────────────────────────────────────────────────────

	/** ¿El acceso comercial de este usuario se limita a su institución? */
	public static function restricted( int $user_id ): bool {
		return $user_id > 0
			&& user_can( $user_id, 'atora_view_finance' )
			&& ! user_can( $user_id, 'manage_woocommerce' )
			&& ! user_can( $user_id, 'manage_options' );
	}

	/**
	 * Institución del usuario para pedidos. Membresía ambigua o inválida → 0 (no ve nada). Sin membresía, la
	 * institución por defecto solo si la instalación tiene una sola institución activa (un WordPress por institución).
	 */
	public static function user_institution( int $user_id ): int {
		if ( class_exists( '\\ATORA\\LMS\\Tenant_Context' ) ) {
			$inst = \ATORA\LMS\Tenant_Context::resolve_institution_for_user( $user_id );
			if ( is_wp_error( $inst ) ) {
				return 0;
			}
			if ( $inst ) {
				return absint( $inst );
			}
		}
		global $wpdb;
		$active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_institutions WHERE status = 'active'" ); // phpcs:ignore WordPress.DB
		return $active <= 1 ? absint( get_option( 'atora_default_institution', 0 ) ) : 0;
	}

	/** @param object $order */
	public static function can_access( int $user_id, $order ): bool {
		if ( ! self::restricted( $user_id ) ) {
			return true;
		}
		$inst = self::institution_of( $order );
		return $inst > 0 && $inst === self::user_institution( $user_id );
	}

	public static function can_access_id( int $user_id, int $order_id ): bool {
		if ( ! self::restricted( $user_id ) ) {
			return true;
		}
		$order = self::load( $order_id );
		return $order ? self::can_access( $user_id, $order ) : false;
	}

	// ── Filtros de WooCommerce ────────────────────────────────────────────

	/** Lista de pedidos (HPOS). */
	public static function filter_list_args( $args ) {
		$user = get_current_user_id();
		if ( ! self::restricted( $user ) ) {
			return $args;
		}
		$args               = is_array( $args ) ? $args : array();
		$meta               = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$inst               = self::user_institution( $user );
		// Sin institución resoluble no ve ninguno (los pedidos «0» son mezclas: solo administración).
		$meta[]             = array( 'key' => self::META, 'value' => $inst > 0 ? (string) $inst : '-1', 'compare' => '=' );
		$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery
		return $args;
	}

	/** Lista de pedidos (pantalla clásica, pedidos como posts). */
	public static function filter_legacy_list( $query ): void {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}
		$type = $query->get( 'post_type' );
		if ( 'shop_order' !== $type && ! ( is_array( $type ) && in_array( 'shop_order', $type, true ) ) ) {
			return;
		}
		$args = self::filter_list_args( array( 'meta_query' => (array) $query->get( 'meta_query' ) ) );
		$query->set( 'meta_query', $args['meta_query'] ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	/** Abrir, editar o borrar un pedido de otra institución: no. */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_post', 'read_post', 'delete_post', 'edit_shop_order', 'read_shop_order', 'delete_shop_order' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}
		$id = absint( $args[0] );
		if ( in_array( $cap, array( 'edit_post', 'read_post', 'delete_post' ), true ) && ! in_array( get_post_type( $id ), array( 'shop_order', 'shop_order_placehold', 'shop_order_refund' ), true ) ) {
			return $caps;
		}
		if ( ! self::can_access_id( (int) $user_id, $id ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	public static function guard_hpos_edit(): void {
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id && ! self::can_access_id( get_current_user_id(), $id ) ) {
			wp_die( esc_html__( 'Este pedido es de otra institución.', 'atora-lms' ), '', array( 'response' => 403 ) );
		}
	}

	public static function guard_refund(): void {
		$id = absint( $_POST['order_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id && ! self::can_access_id( get_current_user_id(), $id ) ) {
			wp_send_json_error( array( 'error' => __( 'Este pedido es de otra institución.', 'atora-lms' ) ), 403 );
		}
	}

	public static function rest_permissions( $permission, $context, $object_id, $post_type ) {
		if ( $permission && 'shop_order' === $post_type && $object_id && ! self::can_access_id( get_current_user_id(), absint( $object_id ) ) ) {
			return false;
		}
		return $permission;
	}

	/** Pedidos de una institución (para «Pagos»). @return object[] */
	public static function orders_for( int $institution_id, int $limit = 200 ): array {
		if ( ! function_exists( 'wc_get_orders' ) || $institution_id <= 0 ) {
			return array();
		}
		return (array) wc_get_orders( array(
			'limit'      => $limit,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'meta_query' => array( array( 'key' => self::META, 'value' => (string) $institution_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
	}

	/** Completa la institución de los pedidos anteriores. @return array{checked:int,assigned:int,unassigned:int} */
	public static function backfill( bool $dry_run = false, int $batch = 200 ): array {
		$out = array( 'checked' => 0, 'assigned' => 0, 'unassigned' => 0 );
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $out;
		}
		$page = 1;
		do {
			$orders = (array) wc_get_orders( array( 'limit' => $batch, 'paged' => $page, 'type' => 'shop_order', 'return' => 'objects' ) );
			foreach ( $orders as $order ) {
				if ( '' !== (string) $order->get_meta( self::META ) ) {
					continue;
				}
				++$out['checked'];
				$inst = $dry_run ? self::resolve( $order ) : self::assign( $order );
				$inst > 0 ? ++$out['assigned'] : ++$out['unassigned'];
			}
			++$page;
		} while ( count( $orders ) === $batch );
		return $out;
	}

	private static function load( int $order_id ) {
		if ( $order_id <= 0 ) {
			return null;
		}
		$loader = self::$loader ?? ( function_exists( 'wc_get_order' ) ? 'wc_get_order' : null );
		$order  = $loader ? call_user_func( $loader, $order_id ) : null;
		return is_object( $order ) && method_exists( $order, 'get_items' ) ? $order : null;
	}
}
