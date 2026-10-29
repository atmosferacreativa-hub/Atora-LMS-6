<?php
/**
 * Página «Pagos» y comando de completado (6.41.0).
 *
 * El Auxiliar administrativo ve los pedidos de su institución (sin los informes globales de WooCommerce, que mezclan
 * instituciones) y los exporta a CSV. `wp atora orders-institution-backfill [--dry-run]` asigna institución a los
 * pedidos anteriores a 6.41.0.
 *
 * @package ATORA_LMS
 * @since 6.41.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Order_Institution_Admin {

	const SLUG = 'atora-payments';
	const CSV  = 'atora_payments_csv';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 32 );
		add_action( 'admin_post_' . self::CSV, array( __CLASS__, 'csv' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( 'atora orders-institution-backfill', array( __CLASS__, 'cli' ) );
		}
	}

	public static function menu(): void {
		add_submenu_page( 'clms-dashboard', __( 'Pagos', 'atora-lms' ), __( 'Pagos', 'atora-lms' ), 'atora_view_finance', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Filas de pedidos que este usuario puede ver: los de su institución.
	 *
	 * @param object[]|null $orders Pedidos a usar (pruebas); por defecto los de la institución del usuario.
	 * @return array<int,array{id:int,date:string,customer:string,email:string,items:string,total:string,status:string}>
	 */
	public static function rows( int $user_id, ?array $orders = null ): array {
		if ( ! user_can( $user_id, 'atora_view_finance' ) ) {
			return array();
		}
		$inst   = ATORA_Order_Institution::user_institution( $user_id );
		$orders = $orders ?? ATORA_Order_Institution::orders_for( $inst );
		$rows   = array();
		foreach ( $orders as $order ) {
			// Doble control: aunque la consulta ya filtra, solo se muestra lo que el usuario puede abrir.
			if ( ATORA_Order_Institution::institution_of( $order ) !== $inst || ! ATORA_Order_Institution::can_access( $user_id, $order ) ) {
				continue;
			}
			$names = array();
			foreach ( (array) $order->get_items() as $item ) {
				$names[] = method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '';
			}
			$date   = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
			$rows[] = array(
				'id'       => (int) $order->get_id(),
				'date'     => $date ? $date->date_i18n( 'Y-m-d H:i' ) : '',
				'customer' => trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() ),
				'email'    => (string) $order->get_billing_email(),
				'items'    => implode( ', ', array_filter( $names ) ),
				'total'    => (string) $order->get_total(),
				'status'   => (string) $order->get_status(),
			);
		}
		return $rows;
	}

	public static function csv(): void {
		check_admin_referer( self::CSV );
		$user = get_current_user_id();
		if ( ! user_can( $user, 'atora_view_finance' ) ) {
			wp_die( esc_html__( 'No tienes acceso a los pagos.', 'atora-lms' ), '', array( 'response' => 403 ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pagos.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( __( 'Pedido', 'atora-lms' ), __( 'Fecha', 'atora-lms' ), __( 'Cliente', 'atora-lms' ), __( 'Correo', 'atora-lms' ), __( 'Productos', 'atora-lms' ), __( 'Total', 'atora-lms' ), __( 'Estado', 'atora-lms' ) ) );
		foreach ( self::rows( $user ) as $row ) {
			fputcsv( $out, array_values( $row ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public static function render(): void {
		$user = get_current_user_id();
		echo '<div class="wrap"><h1>' . esc_html__( 'Pagos', 'atora-lms' ) . '</h1>';
		if ( ! function_exists( 'wc_get_orders' ) ) {
			echo '<p>' . esc_html__( 'WooCommerce no está activo.', 'atora-lms' ) . '</p></div>';
			return;
		}
		echo '<p>' . esc_html__( 'Pedidos de cursos y programas de tu institución.', 'atora-lms' ) . '</p>';
		$csv = wp_nonce_url( add_query_arg( 'action', self::CSV, admin_url( 'admin-post.php' ) ), self::CSV );
		echo '<p><a class="button" href="' . esc_url( $csv ) . '">' . esc_html__( 'Exportar CSV', 'atora-lms' ) . '</a></p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Pedido', 'atora-lms' ), __( 'Fecha', 'atora-lms' ), __( 'Cliente', 'atora-lms' ), __( 'Productos', 'atora-lms' ), __( 'Total', 'atora-lms' ), __( 'Estado', 'atora-lms' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$rows = self::rows( $user );
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No hay pedidos.', 'atora-lms' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$link = function_exists( 'wc_get_order' ) && current_user_can( 'edit_shop_order', $row['id'] ) ? admin_url( 'post.php?post=' . $row['id'] . '&action=edit' ) : '';
			if ( $link && class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
				$link = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $row['id'] );
			}
			echo '<tr><td>' . ( $link ? '<a href="' . esc_url( $link ) . '">#' . esc_html( (string) $row['id'] ) . '</a>' : '#' . esc_html( (string) $row['id'] ) ) . '</td><td>' . esc_html( $row['date'] ) . '</td><td>' . esc_html( $row['customer'] ) . '<br><small>' . esc_html( $row['email'] ) . '</small></td><td>' . esc_html( $row['items'] ) . '</td><td>' . esc_html( $row['total'] ) . '</td><td>' . esc_html( $row['status'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Asigna institución a los pedidos que no la tienen.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Solo cuenta, no guarda.
	 */
	public static function cli( $args, $assoc ): void {
		$dry = ! empty( $assoc['dry-run'] );
		$res = ATORA_Order_Institution::backfill( $dry );
		\WP_CLI::success( sprintf( '%s: %d revisados, %d con institución, %d sin institución (mezcla o sin productos ATORA; solo administración).', $dry ? 'Simulación' : 'Hecho', $res['checked'], $res['assigned'], $res['unassigned'] ) );
	}
}
