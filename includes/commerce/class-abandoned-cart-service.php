<?php
/**
 * Abandoned_Cart_Service — Captura y recuperación de carritos abandonados (Fase 10)
 *
 * Integración con WooCommerce:
 *   - Hook woocommerce_add_to_cart → captura sesión
 *   - Hook woocommerce_cart_updated → actualiza carrito
 *   - Hook woocommerce_payment_complete → marca como recuperado + actualiza LTV
 *
 * @package ATORA_LMS\Commerce
 * @since   5.29.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATORA_Abandoned_Cart_Service {

	const TABLE        = 'atora_abandoned_carts';
	const EXPIRY_HOURS = 72; // Carritos expirados tras 72h

	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Capturar cuando el usuario actualiza el carrito o añade item
		add_action( 'woocommerce_add_to_cart',                        array( __CLASS__, 'capture_cart' ), 10, 0 );
		add_action( 'woocommerce_cart_item_removed',                  array( __CLASS__, 'capture_cart' ), 10, 0 );
		add_action( 'woocommerce_after_cart_item_quantity_update',    array( __CLASS__, 'capture_cart' ), 10, 0 );

		// Marcar como recuperado cuando se completa el pago
		add_action( 'woocommerce_payment_complete',        array( __CLASS__, 'mark_recovered' ),  10, 1 );
		add_action( 'woocommerce_order_status_completed',  array( __CLASS__, 'on_order_complete' ), 15, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_complete' ), 15, 1 );

		// Expirar carritos viejos vía cron diario
		add_action( 'atora_daily_cron', array( __CLASS__, 'expire_old_carts' ) );
	}

	// ── Captura ─────────────────────────────────────────────────────────────

	/**
	 * Captura o actualiza el carrito actual en la BD.
	 * Se dispara en eventos del carrito de WooCommerce.
	 */
	public static function capture_cart(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		global $wpdb;

		$table        = $wpdb->prefix . self::TABLE;
		$user_id      = get_current_user_id();
		$session_key  = self::get_session_key();
		$email        = self::resolve_email( $user_id );
		$cart         = WC()->cart;
		$cart_data    = wp_json_encode( $cart->get_cart_for_session() );
		$subtotal     = (float) $cart->get_cart_contents_total();
		$total        = (float) $cart->get_total( 'float' );
		$currency     = get_woocommerce_currency();
		$contact_id   = self::resolve_contact_id( $user_id, $email );
		$expires_at   = gmdate( 'Y-m-d H:i:s', strtotime( '+' . self::EXPIRY_HOURS . ' hours' ) );
		$checkout_url = wc_get_checkout_url();

		// Verificar que la tabla existe
		$table_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
		if ( ! $table_exists ) { return; }

		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE checkout_key = %s AND status = 'active' LIMIT 1", $session_key )
		);

		$row = array(
			'email'        => sanitize_email( $email ),
			'user_id'      => $user_id,
			'contact_id'   => $contact_id,
			'cart_data'    => $cart_data,
			'subtotal'     => $subtotal,
			'total'        => $total,
			'currency'     => sanitize_key( $currency ),
			'checkout_url' => esc_url_raw( $checkout_url ),
			'expires_at'   => $expires_at,
		);

		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ), null, array( '%d' ) );
		} else {
			$row['checkout_key'] = $session_key;
			$row['cart_hash']    = md5( $cart_data );
			$row['status']       = 'active';
			$row['abandoned_at'] = current_time( 'mysql', true );
			$wpdb->insert( $table, $row );

			if ( $wpdb->insert_id ) {
				// Disparar trigger para Automation Engine
				do_action( 'atora/cart/abandoned', (int) $wpdb->insert_id, $contact_id, $user_id, $total );
			}
		}
	}

	// ── Recuperación ────────────────────────────────────────────────────────

	public static function mark_recovered( int $order_id ): void {
		self::on_order_complete( $order_id );
	}

	public static function on_order_complete( $order_id ): void {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) { return; }

		$order = wc_get_order( $order_id );
		if ( ! $order ) { return; }

		$user_id = absint( $order->get_user_id() );
		$total   = (float) $order->get_total();
		$table   = $wpdb->prefix . self::TABLE;

		// Marcar carrito como recuperado
		$wpdb->update(
			$table,
			array( 'status' => 'recovered', 'order_id' => $order_id, 'recovered_at' => current_time( 'mysql', true ) ),
			array( 'user_id' => $user_id, 'status' => 'active' ),
			array( '%s', '%d', '%s' ),
			array( '%d', '%s' )
		);

		// Actualizar LTV del contacto (Fase 9)
		if ( $user_id && class_exists( '\ATORA\CRM_V2\Services\Contact_Service' ) ) {
			$contact_id = self::resolve_contact_id( $user_id, '' );
			if ( $contact_id ) {
				\ATORA\CRM_V2\Services\Contact_Service::update_ltv( $contact_id, $total, true );
				do_action( 'atora/contact/ltv_updated', $contact_id, $total, $order_id );
			}
		}

		do_action( 'atora/cart/recovered', $order_id, $user_id, $total );
	}

	// ── Expiración ──────────────────────────────────────────────────────────

	public static function expire_old_carts(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'expired'
				 WHERE status = 'active' AND expires_at < %s",
				current_time( 'mysql', true )
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	// ── Consultas ───────────────────────────────────────────────────────────

	public static function get_all( int $limit = 50, int $offset = 0, string $status = '' ): array {
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE;
		$where  = '';
		$params = array();

		if ( '' !== $status ) {
			$where    = 'WHERE status = %s';
			$params[] = sanitize_key( $status );
		}

		$params_c = $params;
		$total    = ! empty( $params_c )
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", ...$params_c ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$params[] = $limit;
		$params[] = $offset;
		$items    = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY abandoned_at DESC LIMIT %d OFFSET %d", ...$params ),
			ARRAY_A
		);

		return array( 'items' => $items, 'total' => $total );
	}

	public static function get_summary(): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$row   = (array) $wpdb->get_row(
			"SELECT
				COUNT(*) AS total,
				SUM(status='active') AS active,
				SUM(status='recovered') AS recovered,
				SUM(CASE WHEN status='recovered' THEN total ELSE 0 END) AS recovered_value
			 FROM {$table}
			 WHERE abandoned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)", // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			ARRAY_A
		);
		return array(
			'total'           => absint( $row['total'] ?? 0 ),
			'active'          => absint( $row['active'] ?? 0 ),
			'recovered'       => absint( $row['recovered'] ?? 0 ),
			'recovered_value' => (float) ( $row['recovered_value'] ?? 0 ),
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	private static function get_session_key(): string {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$key = WC()->session->get_customer_id();
			if ( $key ) { return sanitize_key( (string) $key ); }
		}
		if ( ! session_id() && ! headers_sent() ) {
			@session_start(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return sanitize_key( session_id() ?: uniqid( 'cart_', true ) );
	}

	private static function resolve_email( int $user_id ): string {
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) { return sanitize_email( $user->user_email ); }
		}
		// Intentar desde billing email de sesión WC
		if ( function_exists( 'WC' ) && WC()->session ) {
			$email = WC()->session->get( 'billing_email' );
			if ( $email ) { return sanitize_email( (string) $email ); }
		}
		return '';
	}

	private static function resolve_contact_id( int $user_id, string $email ): int {
		if ( ! class_exists( '\ATORA\CRM_V2\Services\Contact_Service' ) ) { return 0; }
		global $wpdb;
		if ( $user_id ) {
			$id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_contacts WHERE user_id = %d LIMIT 1", $user_id )
			);
			if ( $id ) { return $id; }
		}
		if ( $email ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_contacts WHERE email = %s LIMIT 1", $email )
			);
		}
		return 0;
	}
}
