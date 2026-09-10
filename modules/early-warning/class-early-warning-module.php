<?php
/**
 * ATORA LMS — Early Warning (docente)
 *
 * MVP: señales por entregas perdidas (lessons con due_date vencida sin submission).
 *
 * @package ATORA_LMS
 * @since   6.13.3
 */

namespace ATORA\EarlyWarning;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Early_Warning_Module {

	const CRON_HOOK = 'atora_early_warning_daily_cron';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow midnight' ), 'daily', self::CRON_HOOK );
		}

		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		}
	}

	public static function register_admin_menu(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Alertas tempranas', 'atora-lms' ),
			__( 'Alertas tempranas', 'atora-lms' ),
			'edit_posts',
			'atora-early-warning',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function render_admin_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Alertas tempranas', 'atora-lms' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'MVP: estudiantes con entregas perdidas por curso.', 'atora-lms' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'También disponible vía REST: GET /atora/v1/early-warning?course_id=123', 'atora-lms' ) . '</p>';
		echo '</div>';
	}

	// ── Cron ─────────────────────────────────────────────────────────────────

	public static function run_daily(): void {
		$service = new Early_Warning_Service();
		$service->scan_and_notify();
	}

	// ── REST ─────────────────────────────────────────────────────────────────

	public static function register_rest_routes(): void {
		register_rest_route( 'atora/v1', '/early-warning', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_list' ),
			'permission_callback' => static function( \WP_REST_Request $r ): bool {
				$course_id = absint( $r->get_param( 'course_id' ) );
				if ( ! $course_id ) {
					return false;
				}
				if ( class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'user_can_manage_lms' ) ) {
					return (bool) \CLMS_Helper::user_can_manage_lms( $course_id );
				}
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'course_id' => array( 'sanitize_callback' => 'absint', 'required' => true ),
			),
		) );
	}

	public static function rest_list( \WP_REST_Request $r ) {
		$course_id = absint( $r->get_param( 'course_id' ) );
		$service   = new Early_Warning_Service();
		return rest_ensure_response( $service->list_course_warnings( $course_id ) );
	}
}
