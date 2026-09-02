<?php
/**
 * ATORA LMS v5 — Analytics Engine
 *
 * Dashboard de 3 niveles: Operacional (tiempo real), Táctico (campañas)
 * y Estratégico (negocio). Engagement scoring, predictive analytics
 * y sistema de exportación CSV/PDF.
 *
 * @package ATORA_LMS\Analytics
 * @since   5.0.0
 */

namespace ATORA\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Analytics_Engine
 *
 * @since 5.0.0
 */
class Analytics_Engine {

	/**
	 * Inicializa el módulo.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Cron diario para calcular métricas.
		add_action( 'atora_analytics_daily_cron', array( __CLASS__, 'calculate_daily_metrics' ) );
		add_action( 'atora_engagement_score_cron', array( __CLASS__, 'recalculate_engagement_scores' ) );

		if ( ! wp_next_scheduled( 'atora_analytics_daily_cron' ) ) {
			wp_schedule_event( strtotime( 'tomorrow midnight' ), 'daily', 'atora_analytics_daily_cron' );
		}

		if ( ! wp_next_scheduled( 'atora_engagement_score_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'atora_engagement_score_cron' );
		}

		// REST API para el dashboard.
		// P2 (6.12.0): gateado por módulo 'analytics'.
		if ( ! class_exists( '\CLMS_Module_Registry' ) || \CLMS_Module_Registry::is_active( 'analytics' ) ) {
			add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		}

		// Actualizar score al abrir/clickar email.
		add_action( 'atora/email/webhook_event', array( __CLASS__, 'on_email_event' ), 10, 3 );

		// Admin.
		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_admin_menu' ) );
			add_action( 'wp_ajax_atora_analytics_export', array( __CLASS__, 'ajax_export' ) );
		}
	}

	// ── REST ──────────────────────────────────────────────────────────────────

	/**
	 * Registra los endpoints REST del dashboard de analytics.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		$routes = array(
			'/analytics/emails'       => 'rest_email_metrics',
			'/analytics/engagement'   => 'rest_engagement_metrics',
			'/analytics/revenue'      => 'rest_revenue_metrics',
			'/analytics/cohorts'      => 'rest_cohort_analysis',
		);

		foreach ( $routes as $path => $callback ) {
			register_rest_route( 'atora/v1', $path, array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, $callback ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'args'                => array(
					'period' => array( 'sanitize_callback' => 'absint', 'default' => 30 ),
				),
			) );
		}
	}

	/**
	 * GET /atora/v1/analytics/emails
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_email_metrics( \WP_REST_Request $r ): \WP_REST_Response {
		$period = absint( $r->get_param( 'period' ) );
		return rest_ensure_response( self::get_email_metrics( $period ) );
	}

	/**
	 * GET /atora/v1/analytics/engagement
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_engagement_metrics( \WP_REST_Request $r ): \WP_REST_Response {
		return rest_ensure_response( self::get_engagement_distribution() );
	}

	/**
	 * GET /atora/v1/analytics/revenue
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_revenue_metrics( \WP_REST_Request $r ): \WP_REST_Response {
		$period = absint( $r->get_param( 'period' ) );
		return rest_ensure_response( self::get_revenue_attribution( $period ) );
	}

	/**
	 * GET /atora/v1/analytics/cohorts
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_cohort_analysis( \WP_REST_Request $r ): \WP_REST_Response {
		return rest_ensure_response( self::get_cohort_data() );
	}

	// ── Nivel 1: Operacional ──────────────────────────────────────────────────

	/**
	 * Métricas de email para un periodo dado.
	 *
	 * @param int $period_days Días a analizar.
	 * @return array
	 */
	public static function get_email_metrics( int $period_days = 30 ): array {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$period_days} days" ) );

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(*)                                           AS sent,
				SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END)  AS delivered,
				SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
				SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
				SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
				SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END)  AS failed
			 FROM {$wpdb->prefix}atora_email_queue
			 WHERE created_at >= %s",
			$since
		) );

		if ( ! $row ) {
			return array( 'sent' => 0, 'delivered' => 0, 'opened' => 0, 'clicked' => 0, 'bounced' => 0, 'failed' => 0,
				'open_rate' => 0, 'click_rate' => 0, 'bounce_rate' => 0 );
		}

		$sent      = (int) $row->sent;
		$delivered = (int) $row->delivered;

		return array(
			'period_days'  => $period_days,
			'sent'         => $sent,
			'delivered'    => $delivered,
			'opened'       => (int) $row->opened,
			'clicked'      => (int) $row->clicked,
			'bounced'      => (int) $row->bounced,
			'failed'       => (int) $row->failed,
			'open_rate'    => $delivered > 0 ? round( $row->opened / $delivered, 4 ) : 0,
			'click_rate'   => $delivered > 0 ? round( $row->clicked / $delivered, 4 ) : 0,
			'bounce_rate'  => $sent > 0 ? round( $row->bounced / $sent, 4 ) : 0,
			'generated_at' => gmdate( 'c' ),
		);
	}

	// ── Nivel 2: Táctico ──────────────────────────────────────────────────────

	/**
	 * Distribución de engagement scores.
	 *
	 * @return array
	 */
	public static function get_engagement_distribution(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			"SELECT
				SUM(CASE WHEN engagement_score >= 80 THEN 1 ELSE 0 END) AS high,
				SUM(CASE WHEN engagement_score >= 40 AND engagement_score < 80 THEN 1 ELSE 0 END) AS medium,
				SUM(CASE WHEN engagement_score < 40 THEN 1 ELSE 0 END)  AS low,
				AVG(engagement_score)                                    AS avg_score,
				COUNT(*)                                                 AS total
			 FROM {$wpdb->prefix}atora_user_engagement"
		);

		$row = $rows[0] ?? null;

		return array(
			'high'     => (int) ( $row->high ?? 0 ),
			'medium'   => (int) ( $row->medium ?? 0 ),
			'low'      => (int) ( $row->low ?? 0 ),
			'avg'      => round( (float) ( $row->avg_score ?? 0 ), 1 ),
			'total'    => (int) ( $row->total ?? 0 ),
		);
	}

	// ── Nivel 3: Estratégico ──────────────────────────────────────────────────

	/**
	 * Atribución de ingresos por canal.
	 *
	 * @param int $period_days Días a analizar.
	 * @return array
	 */
	public static function get_revenue_attribution( int $period_days = 30 ): array {
		global $wpdb;

		if ( ! function_exists( 'wc_get_order' ) ) {
			return array();
		}

		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$period_days} days" ) );

		// Pedidos completados en el periodo.
		$orders = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_date, pm_total.meta_value AS total
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm_total ON pm_total.post_id = p.ID AND pm_total.meta_key = '_order_total'
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status = 'wc-completed'
			   AND p.post_date >= %s",
			$since
		) );

		$total_revenue    = 0.0;
		$email_direct     = 0.0;
		$email_assisted   = 0.0;

		foreach ( $orders as $order ) {
			$amount = (float) $order->total;
			$total_revenue += $amount;

			// Verificar si el pedido fue precedido por un email abierto.
			$customer_id = absint( get_post_meta( $order->ID, '_customer_user', true ) );
			if ( ! $customer_id ) {
				continue;
			}

			$email_before_order = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}atora_email_queue
				 WHERE user_id = %d
				   AND opened_at IS NOT NULL
				   AND opened_at <= %s
				   AND opened_at >= DATE_SUB(%s, INTERVAL 7 DAY)
				 LIMIT 1",
				$customer_id,
				$order->post_date,
				$order->post_date
			) );

			if ( $email_before_order ) {
				$email_direct += $amount;
			}

			$email_assisted_check = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}atora_email_queue
				 WHERE user_id = %d
				   AND opened_at IS NOT NULL
				   AND opened_at <= %s
				   AND opened_at >= DATE_SUB(%s, INTERVAL 30 DAY)
				 LIMIT 1",
				$customer_id,
				$order->post_date,
				$order->post_date
			) );

			if ( $email_assisted_check ) {
				$email_assisted += $amount;
			}
		}

		return array(
			'total_revenue'      => round( $total_revenue, 2 ),
			'email_direct'       => round( $email_direct, 2 ),
			'email_assisted'     => round( $email_assisted, 2 ),
			'email_direct_pct'   => $total_revenue > 0 ? round( $email_direct / $total_revenue * 100, 1 ) : 0,
			'email_assisted_pct' => $total_revenue > 0 ? round( $email_assisted / $total_revenue * 100, 1 ) : 0,
			'period_days'        => $period_days,
		);
	}

	/**
	 * Análisis de cohortes por mes de inscripción.
	 *
	 * @return array
	 */
	public static function get_cohort_data(): array {
		global $wpdb;

		$cohorts = array();

		for ( $i = 5; $i >= 0; $i-- ) {
			$month_start = gmdate( 'Y-m-01 00:00:00', strtotime( "-{$i} months" ) );
			$month_end   = gmdate( 'Y-m-t 23:59:59', strtotime( "-{$i} months" ) );
			$label       = date_i18n( 'M Y', strtotime( $month_start ) );

			// Usuarios que se registraron este mes.
			$enrolled = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}usermeta
				 WHERE meta_key = 'atora_consent_academic_emails'
				   AND meta_value = '1'
				   AND umeta_id IN (
					   SELECT umeta_id FROM {$wpdb->prefix}usermeta u2
					   JOIN {$wpdb->users} us ON us.ID = u2.user_id
					   WHERE us.user_registered BETWEEN %s AND %s
				   )",
				$month_start, $month_end
			) );

			// Usuarios activos (al menos 1 email abierto) en cada mes posterior.
			$retention = array();
			for ( $m = 1; $m <= min( $i + 1, 3 ); $m++ ) {
				$check_start = gmdate( 'Y-m-01 00:00:00', strtotime( "+{$m} months", strtotime( $month_start ) ) );
				$check_end   = gmdate( 'Y-m-t 23:59:59', strtotime( "+{$m} months", strtotime( $month_start ) ) );

				$active = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(DISTINCT user_id)
					 FROM {$wpdb->prefix}atora_email_queue
					 WHERE opened_at BETWEEN %s AND %s
					   AND user_id IN (
						   SELECT ID FROM {$wpdb->users}
						   WHERE user_registered BETWEEN %s AND %s
					   )",
					$check_start, $check_end, $month_start, $month_end
				) );

				$retention[ "month_{$m}" ] = $enrolled > 0 ? round( $active / $enrolled * 100, 1 ) : 0;
			}

			$cohorts[] = array(
				'label'     => $label,
				'enrolled'  => $enrolled,
				'retention' => $retention,
			);
		}

		return $cohorts;
	}

	// ── Engagement Scoring ────────────────────────────────────────────────────

	/**
	 * Recalcula los scores de engagement de todos los usuarios.
	 * Se ejecuta diariamente via cron.
	 *
	 * @return void
	 */
	public static function recalculate_engagement_scores(): void {
		global $wpdb;

		$users = $wpdb->get_col(
			"SELECT DISTINCT user_id FROM {$wpdb->prefix}atora_email_queue"
		);

		foreach ( $users as $user_id ) {
			self::calculate_user_score( (int) $user_id );
		}
	}

	/**
	 * Calcula el score de engagement de un usuario.
	 *
	 * Algoritmo:
	 *   +15 por email abierto
	 *   +30 por click
	 *   +100 por conversión (compra)
	 *   -10 por cada 7 días sin abrir
	 *   Min: 0 — Max: 100
	 *
	 * @param int $user_id ID del usuario.
	 * @return int Score calculado.
	 */
	public static function calculate_user_score( int $user_id ): int {
		global $wpdb;

		$stats = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(*)                                               AS total_sent,
				SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opens,
				SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicks,
				MAX(opened_at)                                          AS last_open
			 FROM {$wpdb->prefix}atora_email_queue
			 WHERE user_id = %d AND status IN ('sent','opened')",
			$user_id
		) );

		if ( ! $stats || ! (int) $stats->total_sent ) {
			return 0;
		}

		$score = 0;
		$score += min( (int) $stats->opens  * 15, 60 );
		$score += min( (int) $stats->clicks * 30, 90 );

		// Decaimiento por inactividad.
		if ( $stats->last_open ) {
			$days_inactive = (int) floor( ( time() - strtotime( $stats->last_open ) ) / DAY_IN_SECONDS );
			$weeks_inactive = (int) floor( $days_inactive / 7 );
			$score -= $weeks_inactive * 10;
		} else {
			$score -= 50;
		}

		$score = max( 0, min( 100, $score ) );

		$risk = $score >= 80 ? 'low' : ( $score >= 40 ? 'medium' : 'high' );

		$wpdb->replace(
			"{$wpdb->prefix}atora_user_engagement",
			array(
				'user_id'          => $user_id,
				'emails_received'  => (int) $stats->total_sent,
				'emails_opened'    => (int) $stats->opens,
				'emails_clicked'   => (int) $stats->clicks,
				'last_open_date'   => $stats->last_open,
				'engagement_score' => $score,
				'risk_level'       => $risk,
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( '%d','%d','%d','%d','%s','%d','%s','%s' )
		);

		do_action( 'atora/analytics/score_updated', $user_id, $score, $risk );

		return $score;
	}

	// ── Webhook events → score ────────────────────────────────────────────────

	/**
	 * Actualiza el score cuando ocurre un evento de email.
	 *
	 * @param string $event_type Tipo de evento.
	 * @param int    $queue_id   ID en la queue.
	 * @param array  $event      Datos del evento.
	 * @return void
	 */
	public static function on_email_event( string $event_type, int $queue_id, array $event ): void {
		global $wpdb;

		if ( ! in_array( $event_type, array( 'opened', 'clicked' ), true ) ) {
			return;
		}

		$user_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->prefix}atora_email_queue WHERE id = %d LIMIT 1",
			$queue_id
		) );

		if ( $user_id ) {
			// Recalcular en background (evitar bloquear el webhook).
			wp_schedule_single_event( time() + 30, 'atora_recalculate_user_score', array( $user_id ) );
		}
	}

	// ── Cron: métricas diarias ────────────────────────────────────────────────

	/**
	 * Calcula y almacena las métricas del día anterior.
	 *
	 * @return void
	 */
	public static function calculate_daily_metrics(): void {
		global $wpdb;

		$yesterday_start = gmdate( 'Y-m-d 00:00:00', strtotime( '-1 day' ) );
		$yesterday_end   = gmdate( 'Y-m-d 23:59:59', strtotime( '-1 day' ) );

		$metrics = self::get_email_metrics_for_range( $yesterday_start, $yesterday_end );

		$wpdb->replace(
			"{$wpdb->prefix}atora_email_analytics",
			array(
				'period_type'         => 'day',
				'period_start'        => $yesterday_start,
				'period_end'          => $yesterday_end,
				'emails_sent'         => $metrics['sent'],
				'emails_delivered'    => $metrics['delivered'],
				'emails_opened'       => $metrics['opened'],
				'emails_clicked'      => $metrics['clicked'],
				'emails_bounced'      => $metrics['bounced'],
				'emails_complained'   => 0,
				'unique_opens'        => $metrics['opened'],
				'unique_clicks'       => $metrics['clicked'],
				'calculated_metrics'  => wp_json_encode( array(
					'open_rate'   => $metrics['open_rate'],
					'click_rate'  => $metrics['click_rate'],
					'bounce_rate' => $metrics['bounce_rate'],
				) ),
			),
			null
		);
	}

	/**
	 * Métricas para un rango de fechas exacto.
	 *
	 * @param string $start Fecha inicio.
	 * @param string $end   Fecha fin.
	 * @return array
	 */
	private static function get_email_metrics_for_range( string $start, string $end ): array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(*)                                                    AS sent,
				SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END)             AS delivered,
				SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END)     AS opened,
				SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END)    AS clicked,
				SUM(CASE WHEN status='bounced' THEN 1 ELSE 0 END)          AS bounced
			 FROM {$wpdb->prefix}atora_email_queue
			 WHERE created_at BETWEEN %s AND %s",
			$start, $end
		) );

		$sent      = (int) ( $row->sent ?? 0 );
		$delivered = (int) ( $row->delivered ?? 0 );
		$opened    = (int) ( $row->opened ?? 0 );
		$clicked   = (int) ( $row->clicked ?? 0 );
		$bounced   = (int) ( $row->bounced ?? 0 );

		return array(
			'sent'        => $sent,
			'delivered'   => $delivered,
			'opened'      => $opened,
			'clicked'     => $clicked,
			'bounced'     => $bounced,
			'open_rate'   => $delivered > 0 ? round( $opened / $delivered, 4 ) : 0,
			'click_rate'  => $delivered > 0 ? round( $clicked / $delivered, 4 ) : 0,
			'bounce_rate' => $sent > 0 ? round( $bounced / $sent, 4 ) : 0,
		);
	}

	// ── Exportación ───────────────────────────────────────────────────────────

	/**
	 * Exporta métricas a CSV.
	 *
	 * @return void
	 */
	public static function ajax_export(): void {
		check_ajax_referer( 'atora_analytics_export' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}

		$period  = absint( wp_unslash( $_GET['period'] ?? 30 ) );
		$metrics = self::get_email_metrics( $period );

		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="atora-analytics-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array_keys( $metrics ) );
		fputcsv( $out, array_values( $metrics ) );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	// ── Admin ─────────────────────────────────────────────────────────────────

	/** @return void */
	public static function register_admin_menu(): void {
		global $submenu;
		$existing_items = (array) ( $submenu['clms-dashboard'] ?? array() );
		foreach ( $existing_items as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( 'atora-analytics' === $slug ) {
				return;
			}
		}

		add_submenu_page(
			'clms-dashboard',
			__( 'Analytics', 'atora-lms' ),
			__( 'Analytics', 'atora-lms' ),
			'manage_options',
			'atora-analytics',
			static function () {
				$view = ATORA_LMS_MODULES_DIR . 'analytics/views/dashboard.php';
				if ( file_exists( $view ) ) {
					try {
						require $view;
					} catch ( \Throwable $e ) {
						if ( function_exists( 'error_log' ) ) {
							error_log( '[ATORA Analytics] Error al renderizar dashboard: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
						echo '<div class="wrap"><h1>' . esc_html__( 'Analytics', 'atora-lms' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'No se pudo renderizar el panel de Analytics. Revisa el log de errores.', 'atora-lms' ) . '</p></div></div>';
					}
					return;
				}

				echo '<div class="wrap"><h1>' . esc_html__( 'Analytics', 'atora-lms' ) . '</h1><div class="notice notice-warning"><p>' . esc_html__( 'Vista de Analytics no disponible. Falta el archivo modules/analytics/views/dashboard.php.', 'atora-lms' ) . '</p></div></div>';
			}
		);
	}
}
