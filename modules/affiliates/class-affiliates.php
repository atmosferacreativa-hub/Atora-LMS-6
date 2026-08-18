<?php
/**
 * ATORA LMS v5 — Módulo Afiliados
 *
 * Orquesta: settings, registro de afiliados, dashboard público y admin panel.
 *
 * @package ATORA_LMS\Affiliates
 * @since   5.0.0
 */

namespace ATORA\Affiliates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Affiliates
 *
 * @since 5.0.0
 */
class Affiliates {

	/**
	 * Inicializa el módulo de afiliados.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Tracker de clicks (siempre activo en public).
		if ( class_exists( 'ATORA\Affiliates\Affiliate_Tracker' ) ) {
			Affiliate_Tracker::init();
		}

		// Admin.
		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu',  array( __CLASS__, 'register_admin_menu' ) );
			add_action( 'admin_init',            array( __CLASS__, 'register_settings' ) );

			// AJAX admin.
			add_action( 'wp_ajax_atora_affiliate_approve',  array( __CLASS__, 'ajax_approve' ) );
			add_action( 'wp_ajax_atora_affiliate_reject',   array( __CLASS__, 'ajax_reject' ) );
			add_action( 'wp_ajax_atora_affiliate_pay',      array( __CLASS__, 'ajax_mark_paid' ) );
		}

		// Dashboard público del afiliado.
		add_shortcode( 'atora_affiliate_dashboard', array( __CLASS__, 'render_dashboard_shortcode' ) );

		// Página de solicitud para unirse.
		add_shortcode( 'atora_affiliate_apply', array( __CLASS__, 'render_apply_shortcode' ) );
		add_action( 'wp_ajax_atora_affiliate_apply', array( __CLASS__, 'ajax_apply' ) );

		// Comisiones via WooCommerce.
		if ( class_exists( 'ATORA\Affiliates\Affiliate_Commissions' ) ) {
			Affiliate_Commissions::init();
		}
	}

	// ── Admin ─────────────────────────────────────────────────────────────────

	/**
	 * Registra el menú admin.
	 *
	 * @return void
	 */
	public static function register_admin_menu(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Afiliados', 'atora-lms' ),
			__( 'Afiliados', 'atora-lms' ),
			'manage_options',
			'atora-affiliates',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Registra las opciones de settings del módulo.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting( 'atora_affiliates_settings', 'atora_affiliates_options', array(
			'sanitize_callback' => array( __CLASS__, 'sanitize_options' ),
		) );

		add_settings_section(
			'atora_affiliates_general',
			__( 'Configuración general de afiliados', 'atora-lms' ),
			'__return_false',
			'atora-affiliates-settings'
		);

		$fields = array(
			'enabled'           => __( 'Activar programa de afiliados', 'atora-lms' ),
			'default_commission'=> __( 'Comisión por defecto (%)', 'atora-lms' ),
			'cookie_days'       => __( 'Duración de cookie (días)', 'atora-lms' ),
			'auto_approve'      => __( 'Aprobación automática', 'atora-lms' ),
			'min_payout'        => __( 'Pago mínimo (USD)', 'atora-lms' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'atora_affiliates_' . $key,
				$label,
				array( __CLASS__, 'field_' . $key ),
				'atora-affiliates-settings',
				'atora_affiliates_general'
			);
		}
	}

	/**
	 * Renderiza la página de administración de afiliados.
	 *
	 * @return void
	 */
	public static function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}

		$view = ATORA_LMS_MODULES_DIR . 'affiliates/views/admin.php';
		if ( file_exists( $view ) ) {
			require $view;
		}
	}

	// ── Shortcodes ────────────────────────────────────────────────────────────

	/**
	 * Shortcode [atora_affiliate_dashboard].
	 *
	 * @return string
	 */
	public static function render_dashboard_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Debes iniciar sesión para ver tu panel de afiliado.', 'atora-lms' )
			       . ' <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">'
			       . esc_html__( 'Iniciar sesión', 'atora-lms' ) . '</a></p>';
		}

		$user_id   = get_current_user_id();
		$affiliate = self::get_affiliate_by_user( $user_id );

		if ( ! $affiliate ) {
			return '<p>' . esc_html__( 'No tienes una cuenta de afiliado. ', 'atora-lms' )
			       . do_shortcode( '[atora_affiliate_apply]' ) . '</p>';
		}

		if ( 'active' !== $affiliate->status ) {
			return '<p>' . sprintf(
				/* translators: %s: estado */
				esc_html__( 'Tu cuenta de afiliado está en estado: %s. Te notificaremos cuando sea aprobada.', 'atora-lms' ),
				esc_html( $affiliate->status )
			) . '</p>';
		}

		ob_start();
		$view = ATORA_LMS_MODULES_DIR . 'affiliates/views/dashboard.php';
		if ( file_exists( $view ) ) {
			require $view;
		}
		return ob_get_clean();
	}

	/**
	 * Shortcode [atora_affiliate_apply].
	 *
	 * @return string
	 */
	public static function render_apply_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$user_id = get_current_user_id();
		if ( self::get_affiliate_by_user( $user_id ) ) {
			return '<p>' . esc_html__( 'Ya tienes una cuenta de afiliado.', 'atora-lms' ) . '</p>';
		}

		ob_start();
		$view = ATORA_LMS_MODULES_DIR . 'affiliates/views/apply.php';
		if ( file_exists( $view ) ) {
			require $view;
		}
		return ob_get_clean();
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────

	/**
	 * Solicitud de ingreso al programa de afiliados.
	 *
	 * @return void
	 */
	public static function ajax_apply(): void {
		check_ajax_referer( 'atora_affiliate_apply' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Debes iniciar sesión.', 'atora-lms' ) ) );
		}

		if ( self::get_affiliate_by_user( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Ya tienes cuenta de afiliado.', 'atora-lms' ) ) );
		}

		$payout_method  = sanitize_key( wp_unslash( $_POST['payout_method'] ?? 'paypal' ) );
		$payout_details = sanitize_text_field( wp_unslash( $_POST['payout_details'] ?? '' ) );

		$affiliate_id = self::create_affiliate( $user_id, $payout_method, $payout_details );

		if ( ! $affiliate_id ) {
			wp_send_json_error( array( 'message' => __( 'Error al crear la cuenta. Inténtalo de nuevo.', 'atora-lms' ) ) );
		}

		do_action( 'atora/affiliates/applied', $user_id, $affiliate_id );

		$opts       = self::get_options();
		$auto       = ! empty( $opts['auto_approve'] );
		$status_msg = $auto
			? __( '¡Cuenta creada y activada! Ya puedes empezar a compartir tu enlace.', 'atora-lms' )
			: __( 'Solicitud enviada. Te avisaremos cuando sea aprobada.', 'atora-lms' );

		wp_send_json_success( array( 'message' => $status_msg ) );
	}

	/**
	 * Aprobar afiliado (admin).
	 *
	 * @return void
	 */
	public static function ajax_approve(): void {
		check_ajax_referer( 'atora_affiliate_admin' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$id = absint( wp_unslash( $_POST['affiliate_id'] ?? 0 ) );
		self::update_status( $id, 'active' );
		wp_send_json_success();
	}

	/**
	 * Rechazar afiliado (admin).
	 *
	 * @return void
	 */
	public static function ajax_reject(): void {
		check_ajax_referer( 'atora_affiliate_admin' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$id = absint( wp_unslash( $_POST['affiliate_id'] ?? 0 ) );
		self::update_status( $id, 'rejected' );
		wp_send_json_success();
	}

	/**
	 * Marcar comisiones como pagadas (admin).
	 *
	 * @return void
	 */
	public static function ajax_mark_paid(): void {
		check_ajax_referer( 'atora_affiliate_admin' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		global $wpdb;

		$affiliate_id = absint( wp_unslash( $_POST['affiliate_id'] ?? 0 ) );

		$wpdb->update(
			"{$wpdb->prefix}atora_affiliate_commissions",
			array(
				'status'  => 'paid',
				'paid_at' => current_time( 'mysql', true ),
			),
			array(
				'affiliate_id' => $affiliate_id,
				'status'       => 'approved',
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		wp_send_json_success();
	}

	// ── API pública ───────────────────────────────────────────────────────────

	/**
	 * Crea un nuevo afiliado.
	 *
	 * @param int    $user_id        ID del usuario.
	 * @param string $payout_method  Método de pago.
	 * @param string $payout_details Detalles del pago.
	 * @return int|false ID del afiliado o false en error.
	 */
	public static function create_affiliate( int $user_id, string $payout_method = 'paypal', string $payout_details = '' ) {
		global $wpdb;

		$opts         = self::get_options();
		$auto_approve = ! empty( $opts['auto_approve'] );
		$commission   = (float) ( $opts['default_commission'] ?? 20 );

		$inserted = $wpdb->insert(
			"{$wpdb->prefix}atora_affiliates",
			array(
				'user_id'         => $user_id,
				'referral_code'   => self::generate_referral_code( $user_id ),
				'status'          => $auto_approve ? 'active' : 'pending',
				'commission_rate' => $commission,
				'payout_method'   => sanitize_key( $payout_method ),
				'payout_details'  => sanitize_text_field( $payout_details ),
				'notes'           => '',
			),
			array( '%d', '%s', '%s', '%f', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Obtiene el registro de afiliado de un usuario.
	 *
	 * @param int $user_id ID del usuario.
	 * @return object|null
	 */
	public static function get_affiliate_by_user( int $user_id ): ?object {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}atora_affiliates WHERE user_id = %d LIMIT 1",
				$user_id
			)
		) ?: null;
	}

	/**
	 * Obtiene el registro de afiliado por su código de referido.
	 *
	 * @param string $code Código de referido.
	 * @return object|null
	 */
	public static function get_affiliate_by_code( string $code ): ?object {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}atora_affiliates WHERE referral_code = %s LIMIT 1",
				strtoupper( $code )
			)
		) ?: null;
	}

	/**
	 * Actualiza el estado de un afiliado.
	 *
	 * @param int    $affiliate_id ID del afiliado.
	 * @param string $status       Nuevo estado.
	 * @return void
	 */
	public static function update_status( int $affiliate_id, string $status ): void {
		global $wpdb;

		$allowed = array( 'pending', 'active', 'suspended', 'rejected' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return;
		}

		$wpdb->update(
			"{$wpdb->prefix}atora_affiliates",
			array( 'status' => $status ),
			array( 'id'     => $affiliate_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Devuelve las opciones del módulo con defaults.
	 *
	 * @return array
	 */
	public static function get_options(): array {
		$defaults = array(
			'enabled'            => true,
			'default_commission' => 20.0,
			'cookie_days'        => 30,
			'auto_approve'       => false,
			'min_payout'         => 50.0,
		);
		return wp_parse_args( get_option( 'atora_affiliates_options', array() ), $defaults );
	}

	// ── Helpers privados ──────────────────────────────────────────────────────

	/**
	 * Genera un código de referido único basado en el nombre del usuario.
	 *
	 * Formato: NOMBRE + 3 dígitos aleatorios (ej: JUAN042).
	 *
	 * @param int $user_id ID del usuario.
	 * @return string
	 */
	private static function generate_referral_code( int $user_id ): string {
		global $wpdb;

		$user = get_userdata( $user_id );
		$base = $user
			? strtoupper( preg_replace( '/[^A-Z0-9]/', '', strtoupper( $user->user_login ) ) )
			: 'AFF';

		$base = substr( $base, 0, 8 ) ?: 'AFF';

		do {
			$code = $base . str_pad( (string) random_int( 0, 999 ), 3, '0', STR_PAD_LEFT );
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}atora_affiliates WHERE referral_code = %s LIMIT 1",
					$code
				)
			);
		} while ( $exists );

		return $code;
	}

	// ── Campos de settings ────────────────────────────────────────────────────

	/** @return void */
	public static function field_enabled(): void {
		$opts = self::get_options();
		printf(
			'<input type="checkbox" name="atora_affiliates_options[enabled]" value="1"%s>',
			checked( ! empty( $opts['enabled'] ), true, false )
		);
	}

	/** @return void */
	public static function field_default_commission(): void {
		$opts = self::get_options();
		printf(
			'<input type="number" name="atora_affiliates_options[default_commission]" value="%s" min="0" max="100" step="0.01"> %%',
			esc_attr( $opts['default_commission'] )
		);
	}

	/** @return void */
	public static function field_cookie_days(): void {
		$opts = self::get_options();
		printf(
			'<input type="number" name="atora_affiliates_options[cookie_days]" value="%s" min="1" max="365"> %s',
			esc_attr( $opts['cookie_days'] ),
			esc_html__( 'días', 'atora-lms' )
		);
	}

	/** @return void */
	public static function field_auto_approve(): void {
		$opts = self::get_options();
		printf(
			'<input type="checkbox" name="atora_affiliates_options[auto_approve]" value="1"%s> %s',
			checked( ! empty( $opts['auto_approve'] ), true, false ),
			esc_html__( 'Aprobar automáticamente las solicitudes de afiliado', 'atora-lms' )
		);
	}

	/** @return void */
	public static function field_min_payout(): void {
		$opts = self::get_options();
		printf(
			'$ <input type="number" name="atora_affiliates_options[min_payout]" value="%s" min="0" step="0.01"> USD',
			esc_attr( $opts['min_payout'] )
		);
	}

	/**
	 * Sanitiza las opciones del módulo.
	 *
	 * @param mixed $input Input del formulario.
	 * @return array
	 */
	public static function sanitize_options( $input ): array {
		$input = is_array( $input ) ? $input : array();
		return array(
			'enabled'            => ! empty( $input['enabled'] ),
			'default_commission' => min( 100, max( 0, (float) ( $input['default_commission'] ?? 20 ) ) ),
			'cookie_days'        => min( 365, max( 1, absint( $input['cookie_days'] ?? 30 ) ) ),
			'auto_approve'       => ! empty( $input['auto_approve'] ),
			'min_payout'         => max( 0, (float) ( $input['min_payout'] ?? 50 ) ),
		);
	}

	// ── Fase IV S13: métodos de gestión masiva ────────────────────────────────

	/**
	 * Exporta comisiones filtradas como CSV.
	 *
	 * @param array $filters { affiliate_id?: int, date_from?: string, date_to?: string, status?: string }
	 */
	public static function export_commissions_csv( array $filters = array() ): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'atora_affiliate_commissions';
		$where  = 'WHERE 1=1';
		$params = array();

		if ( ! empty( $filters['affiliate_id'] ) ) { $where .= ' AND affiliate_id = %d'; $params[] = absint( $filters['affiliate_id'] ); }
		if ( ! empty( $filters['status'] ) )       { $where .= ' AND status = %s';       $params[] = sanitize_key( (string) $filters['status'] ); }
		if ( ! empty( $filters['date_from'] ) )    { $where .= ' AND created_at >= %s';  $params[] = sanitize_text_field( (string) $filters['date_from'] ) . ' 00:00:00'; }
		if ( ! empty( $filters['date_to'] ) )      { $where .= ' AND created_at <= %s';  $params[] = sanitize_text_field( (string) $filters['date_to'] ) . ' 23:59:59'; }

		$sql  = ! empty( $params )
			? $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC", ...$params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: "SELECT * FROM {$table} ORDER BY created_at DESC"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="atora-commissions-' . gmdate( 'Y-m-d' ) . '.csv"' );
			header( 'Pragma: no-cache' );
		}
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! empty( $rows ) ) {
			fputcsv( $out, array_keys( $rows[0] ) );
			foreach ( $rows as $row ) { fputcsv( $out, $row ); }
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Marca masivamente comisiones como pagadas y envía email de confirmación.
	 *
	 * @param array $commission_ids IDs de comisiones a marcar.
	 * @return int Número de comisiones actualizadas.
	 */
	public static function bulk_mark_paid( array $commission_ids ): int {
		global $wpdb;
		$commission_ids = array_filter( array_map( 'absint', $commission_ids ) );
		if ( empty( $commission_ids ) ) { return 0; }

		$table   = $wpdb->prefix . 'atora_affiliate_commissions';
		$in      = implode( ',', array_fill( 0, count( $commission_ids ), '%d' ) );
		$now     = current_time( 'mysql', true );
		$updated = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'paid', paid_at = %s WHERE id IN ({$in}) AND status = 'approved'", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				array_merge( array( $now ), $commission_ids )
			)
		);

		if ( $updated > 0 ) {
			$affiliate_ids = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "SELECT DISTINCT affiliate_id FROM {$table} WHERE id IN ({$in})", ...$commission_ids ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
			foreach ( $affiliate_ids as $aff_id ) {
				$aff = $wpdb->get_row( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}atora_affiliates WHERE id = %d LIMIT 1", absint( $aff_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( $aff ) {
					$user = get_userdata( absint( $aff['user_id'] ) );
					if ( $user && $user->user_email ) {
						wp_mail(
							$user->user_email,
							__( 'Tu comisión ha sido pagada — ATORA LMS', 'atora-lms' ),
							sprintf( __( 'Hola %s, hemos procesado el pago de tus comisiones aprobadas.', 'atora-lms' ), sanitize_text_field( $user->display_name ) )
						);
					}
				}
			}
			do_action( 'atora/affiliates/bulk_paid', $commission_ids, $updated );
		}
		return $updated;
	}
}
