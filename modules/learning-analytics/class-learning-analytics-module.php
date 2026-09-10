<?php
/**
 * Learning Analytics & Risk — dashboard + REST + cron.
 *
 * @package ATORA_LMS
 * @since   6.15.0
 */

namespace ATORA\LearningAnalytics;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Learning_Analytics_Module {

	const CRON_HOOK = 'atora_learning_analytics_daily_cron';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow midnight' ), 'daily', self::CRON_HOOK );
		}

		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_admin_menu' ) );
			add_action( 'admin_post_atora_learning_analytics_scan', array( __CLASS__, 'handle_scan_now' ) );
			add_action( 'admin_post_atora_learning_analytics_export', array( __CLASS__, 'handle_export_csv' ) );
		}
	}

	public static function register_admin_menu(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Analítica de riesgo', 'atora-lms' ),
			__( 'Analítica de riesgo', 'atora-lms' ),
			'edit_posts',
			'atora-learning-analytics',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function render_admin_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;

		echo '<div class="wrap"><h1>' . esc_html__( 'Analítica de riesgo', 'atora-lms' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Scoring por estudiante/curso basado en progreso, pendientes, notas e inactividad (MVP).', 'atora-lms' ) . '</p>';

		echo '<form method="get" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="atora-learning-analytics">';
		echo '<label><strong>' . esc_html__( 'Curso', 'atora-lms' ) . '</strong></label><br>';
		echo '<input type="number" name="course_id" value="' . esc_attr( (string) $course_id ) . '" min="1" style="width:180px">';
		echo '<button class="button button-primary" type="submit" style="margin-left:8px">' . esc_html__( 'Cargar', 'atora-lms' ) . '</button>';
		echo '</form>';

		if ( ! $course_id ) {
			echo '<p class="description">' . esc_html__( 'Indica un course_id para ver el dashboard.', 'atora-lms' ) . '</p>';
			echo '</div>';
			return;
		}

		$service = new Learning_Analytics_Service();
		if ( ! $service->viewer_can_access_course( get_current_user_id(), $course_id ) ) {
			wp_die( esc_html__( 'No tienes acceso a este curso.', 'atora-lms' ) );
		}

		$scan_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=atora_learning_analytics_scan&course_id=' . $course_id ),
			'atora_learning_analytics_scan_' . $course_id
		);
		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=atora_learning_analytics_export&course_id=' . $course_id ),
			'atora_learning_analytics_export_' . $course_id
		);

		echo '<p style="margin: 10px 0">';
		echo '<a class="button button-secondary" href="' . esc_url( $scan_url ) . '">' . esc_html__( 'Refrescar ahora', 'atora-lms' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Exportar CSV', 'atora-lms' ) . '</a>';
		echo '</p>';

		$rows = $service->list_course_students( $course_id );
		if ( empty( $rows ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No hay snapshots aún. Usa “Refrescar ahora”.', 'atora-lms' ) . '</p></div>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped" style="max-width: 1150px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Estudiante', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Riesgo', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Score', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Señales', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Acción sugerida', 'atora-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$signals = is_array( $row['signals'] ?? null ) ? (array) $row['signals'] : array();
			$reasons = isset( $signals['risk_reasons'] ) && is_array( $signals['risk_reasons'] ) ? array_values( array_map( 'sanitize_text_field', $signals['risk_reasons'] ) ) : array();
			$last    = sanitize_text_field( (string) ( $signals['last_access_at'] ?? '' ) );

			$signals_line = sprintf(
				/* translators: 1: progress, 2: average, 3: pending, 4: last access */
				__( 'Progreso: %1$s | Promedio: %2$s | Pendientes: %3$s | Último acceso: %4$s', 'atora-lms' ),
				absint( $signals['progress_percent'] ?? 0 ) . '%',
				( null !== ( $signals['final_average'] ?? null ) ? absint( $signals['final_average'] ) . '%' : '—' ),
				absint( $signals['pending_activities'] ?? 0 ),
				$last ? $last : '—'
			);

			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) ( $row['student_name'] ?? '' ) ) . '</strong><br><span class="description">' . esc_html( (string) ( $row['student_email'] ?? '' ) ) . '</span></td>';
			echo '<td>' . esc_html( sanitize_key( (string) ( $row['risk_level'] ?? 'unknown' ) ) ) . ( $reasons ? '<br><span class="description">' . esc_html( implode( ' ', array_slice( $reasons, 0, 2 ) ) ) . '</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( (string) absint( $row['risk_score'] ?? 0 ) ) . '</td>';
			echo '<td><span class="description">' . esc_html( $signals_line ) . '</span></td>';
			echo '<td>' . esc_html( sanitize_text_field( (string) ( $signals['recommended_action'] ?? '' ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	public static function handle_scan_now(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		check_admin_referer( 'atora_learning_analytics_scan_' . $course_id );

		$service = new Learning_Analytics_Service();
		if ( ! $service->viewer_can_access_course( get_current_user_id(), $course_id ) ) {
			wp_die( esc_html__( 'No tienes acceso a este curso.', 'atora-lms' ) );
		}

		$service->scan_course( $course_id, true );
		wp_safe_redirect( admin_url( 'admin.php?page=atora-learning-analytics&course_id=' . $course_id . '&scanned=1' ) );
		exit;
	}

	public static function handle_export_csv(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		check_admin_referer( 'atora_learning_analytics_export_' . $course_id );

		$service = new Learning_Analytics_Service();
		if ( ! $service->viewer_can_access_course( get_current_user_id(), $course_id ) ) {
			wp_die( esc_html__( 'No tienes acceso a este curso.', 'atora-lms' ) );
		}

		$csv = $service->export_course_csv( $course_id );
		if ( empty( $csv['content'] ) || empty( $csv['filename'] ) ) {
			wp_die( esc_html__( 'No hay datos para exportar.', 'atora-lms' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( (string) $csv['filename'] ) );
		echo (string) $csv['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function run_daily(): void {
		$service = new Learning_Analytics_Service();
		$service->scan_all_courses_daily();
	}

	public static function register_rest_routes(): void {
		register_rest_route(
			'atora/v1',
			'/learning-analytics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_list' ),
					'permission_callback' => array( __CLASS__, 'rest_can_view' ),
					'args'                => array(
						'course_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			'atora/v1',
			'/learning-analytics/scan',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_scan' ),
					'permission_callback' => array( __CLASS__, 'rest_can_view' ),
					'args'                => array(
						'course_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'notify' => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => true,
						),
					),
				),
			)
		);
	}

	public static function rest_can_view( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		$course_id = absint( $request->get_param( 'course_id' ) );
		$service = new Learning_Analytics_Service();
		return $service->viewer_can_access_course( get_current_user_id(), $course_id );
	}

	public static function rest_list( WP_REST_Request $request ): WP_REST_Response {
		$course_id = absint( $request->get_param( 'course_id' ) );
		$service   = new Learning_Analytics_Service();
		$rows      = $service->list_course_students( $course_id );

		return new WP_REST_Response(
			array(
				'course_id' => $course_id,
				'count'     => count( $rows ),
				'rows'      => $rows,
			),
			200
		);
	}

	public static function rest_scan( WP_REST_Request $request ): WP_REST_Response {
		$course_id = absint( $request->get_param( 'course_id' ) );
		$notify    = (bool) $request->get_param( 'notify' );

		$service = new Learning_Analytics_Service();
		$upserted = $service->scan_course( $course_id, $notify );

		return new WP_REST_Response(
			array(
				'course_id' => $course_id,
				'upserted'  => $upserted,
			),
			200
		);
	}
}

