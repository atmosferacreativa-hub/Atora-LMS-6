<?php
/**
 * ATORA LMS v5 — Comisiones de afiliados
 *
 * Detecta el cookie de referido al completar una compra de WooCommerce
 * y crea la comisión en la BD.
 *
 * @package ATORA_LMS\Affiliates
 * @since   5.0.0
 */

namespace ATORA\Affiliates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Affiliate_Commissions
 *
 * @since 5.0.0
 */
class Affiliate_Commissions {

	/**
	 * Registra los hooks de comisiones.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Crear comisión al completar o procesar un pedido.
		add_action( 'woocommerce_order_status_completed',  array( __CLASS__, 'process_order' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'process_order' ), 20 );
	}

	/**
	 * Procesa un pedido y crea la comisión si procede.
	 *
	 * @param int $order_id ID del pedido.
	 * @return void
	 */
	public static function process_order( int $order_id ): void {
		// Evitar duplicados: comprobar si ya se procesó este pedido.
		if ( get_post_meta( $order_id, '_atora_affiliate_processed', true ) ) {
			return;
		}

		$code = self::get_ref_code_from_order( $order_id );
		if ( ! $code ) {
			return;
		}

		$affiliate = Affiliates::get_affiliate_by_code( $code );
		if ( ! $affiliate || 'active' !== $affiliate->status ) {
			return;
		}

		// No pagar comisión si el comprador es el mismo afiliado.
		$order   = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$buyer_id = $order ? absint( $order->get_customer_id() ) : 0;
		if ( $buyer_id && $buyer_id === (int) $affiliate->user_id ) {
			return;
		}

		$total      = $order ? (float) $order->get_total() : 0.0;
		$rate       = (float) $affiliate->commission_rate / 100;
		$commission = round( $total * $rate, 2 );
		$currency   = $order ? $order->get_currency() : get_woocommerce_currency();

		self::create_commission( (int) $affiliate->id, $order_id, $total, $commission, $currency );

		update_post_meta( $order_id, '_atora_affiliate_processed', '1' );
		update_post_meta( $order_id, '_atora_affiliate_code', $code );

		// Limpiar cookie tras conversión exitosa.
		Affiliate_Tracker::clear_cookie();

		/**
		 * @param int    $affiliate_id ID del afiliado.
		 * @param int    $order_id     ID del pedido.
		 * @param float  $commission   Monto de la comisión.
		 * @param string $currency     Moneda.
		 */
		do_action( 'atora/affiliates/commission_created', (int) $affiliate->id, $order_id, $commission, $currency );
	}

	/**
	 * Inserta una comisión en la BD.
	 *
	 * @param int    $affiliate_id ID del afiliado.
	 * @param int    $order_id     ID del pedido.
	 * @param float  $amount       Total del pedido.
	 * @param float  $commission   Monto comisión.
	 * @param string $currency     Moneda.
	 * @return int|false
	 */
	public static function create_commission( int $affiliate_id, int $order_id, float $amount, float $commission, string $currency = 'USD' ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			"{$wpdb->prefix}atora_affiliate_commissions",
			array(
				'affiliate_id'      => $affiliate_id,
				'order_id'          => $order_id,
				'amount'            => $amount,
				'commission_amount' => $commission,
				'currency'          => sanitize_text_field( $currency ),
				'status'            => 'pending',
			),
			array( '%d', '%d', '%f', '%f', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Aprueba comisiones pendientes para un afiliado.
	 *
	 * @param int $affiliate_id ID del afiliado.
	 * @return int Número de filas actualizadas.
	 */
	public static function approve_pending( int $affiliate_id ): int {
		global $wpdb;

		return (int) $wpdb->update(
			"{$wpdb->prefix}atora_affiliate_commissions",
			array(
				'status'      => 'approved',
				'approved_at' => current_time( 'mysql', true ),
			),
			array(
				'affiliate_id' => $affiliate_id,
				'status'       => 'pending',
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Obtiene el balance pendiente de pago de un afiliado.
	 *
	 * @param int $affiliate_id ID del afiliado.
	 * @return float
	 */
	public static function get_pending_balance( int $affiliate_id ): float {
		global $wpdb;

		$balance = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(commission_amount)
				 FROM {$wpdb->prefix}atora_affiliate_commissions
				 WHERE affiliate_id = %d AND status = 'approved'",
				$affiliate_id
			)
		);

		return (float) $balance;
	}

	/**
	 * Devuelve el historial de comisiones de un afiliado.
	 *
	 * @param int $affiliate_id ID del afiliado.
	 * @param int $limit        Máximo de resultados.
	 * @param int $offset       Desplazamiento.
	 * @return array
	 */
	public static function get_history( int $affiliate_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}atora_affiliate_commissions
				 WHERE affiliate_id = %d
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				$affiliate_id,
				$limit,
				$offset
			)
		);
	}

	/**
	 * Obtiene las estadísticas de un afiliado para el mes actual.
	 *
	 * @param int $affiliate_id ID del afiliado.
	 * @return array { clicks, conversions, commission_total, rate }
	 */
	public static function get_monthly_stats( int $affiliate_id ): array {
		global $wpdb;

		$month_start = gmdate( 'Y-m-01 00:00:00' );

		$clicks = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}atora_affiliate_clicks
				 WHERE affiliate_id = %d AND created_at >= %s",
				$affiliate_id,
				$month_start
			)
		);

		$conversions = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}atora_affiliate_commissions
				 WHERE affiliate_id = %d AND created_at >= %s",
				$affiliate_id,
				$month_start
			)
		);

		$commission_total = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(commission_amount) FROM {$wpdb->prefix}atora_affiliate_commissions
				 WHERE affiliate_id = %d AND created_at >= %s",
				$affiliate_id,
				$month_start
			)
		);

		return array(
			'clicks'           => $clicks,
			'conversions'      => $conversions,
			'commission_total' => $commission_total,
			'rate'             => $clicks > 0 ? round( $conversions / $clicks * 100, 1 ) : 0,
		);
	}

	// ── Privado ───────────────────────────────────────────────────────────────

	/**
	 * Obtiene el código de referido asociado al pedido.
	 * Primero busca en el meta del pedido, luego en la cookie del navegador.
	 *
	 * @param int $order_id ID del pedido.
	 * @return string
	 */
	private static function get_ref_code_from_order( int $order_id ): string {
		// Meta guardado al crear el pedido (session de WC).
		$meta_code = get_post_meta( $order_id, '_atora_affiliate_code_pre', true );
		if ( $meta_code ) {
			return strtoupper( sanitize_text_field( $meta_code ) );
		}

		// Cookie actual del navegador.
		return Affiliate_Tracker::get_current_ref_code();
	}
}
