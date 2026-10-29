<?php
/**
 * Forums module bootstrap.
 *
 * @package ATORA_LMS
 */

declare( strict_types = 1 );

use ATORA\Forums\Forums_Installer;
use ATORA\Forums\Forums_REST_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-forums-installer.php';
require_once __DIR__ . '/class-forums-service.php';
require_once __DIR__ . '/class-forums-notifications.php';
require_once __DIR__ . '/class-forums-rest-controller.php';

final class ATORA_Forums {
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'ensure_tables' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_shortcode( 'atora_forum', array( __CLASS__, 'shortcode_forum' ) );
		add_action( 'atora_lms_admin_menu', array( __CLASS__, 'admin_menu' ) );
	}

	public static function ensure_tables(): void {
		Forums_Installer::ensure_tables();
	}

	public static function rest(): void {
		Forums_REST_Controller::register_routes();
	}

	public static function shortcode_forum( array $atts = array() ): string {
		$atts = shortcode_atts( array( 'course' => '' ), $atts, 'atora_forum' );
		$course = absint( $atts['course'] );
		if ( $course <= 0 ) {
			return '<p>' . esc_html__( 'Foro no configurado.', 'atora-lms' ) . '</p>';
		}
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Inicia sesión para ver el foro.', 'atora-lms' ) . '</p>';
		}
		return '<div class="atora-forum" role="region" aria-label="' . esc_attr__( 'Foro', 'atora-lms' ) . '"><p>' . esc_html__( 'Foro (MVP): usa la app móvil para participar.', 'atora-lms' ) . '</p></div>';
	}

	public static function admin_menu(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		add_submenu_page(
			'atora-lms',
			__( 'Foros', 'atora-lms' ),
			__( 'Foros', 'atora-lms' ),
			'manage_options',
			'atora-forums',
			array( __CLASS__, 'render_admin' )
		);
	}

	public static function render_admin(): void {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}atora_forum_reports WHERE status = 'open' ORDER BY id DESC LIMIT 50", ARRAY_A ); // phpcs:ignore WordPress.DB
		echo '<div class="wrap"><h1>' . esc_html__( 'Foros: reportes', 'atora-lms' ) . '</h1>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No hay reportes pendientes.', 'atora-lms' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Tipo', 'atora-lms' ) . '</th><th>' . esc_html__( 'Objetivo', 'atora-lms' ) . '</th><th>' . esc_html__( 'Motivo', 'atora-lms' ) . '</th><th>' . esc_html__( 'Quién', 'atora-lms' ) . '</th><th>' . esc_html__( 'Fecha', 'atora-lms' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$u = get_user_by( 'id', (int) $row['reporter_id'] );
			echo '<tr><td>' . (int) $row['id'] . '</td><td>' . esc_html( (string) $row['target_type'] ) . '</td><td>' . esc_html( (string) $row['target_id'] ) . '</td><td>' . esc_html( (string) $row['reason'] ) . '</td><td>' . esc_html( $u ? ( $u->display_name ?: $u->user_login ) : '#' . (int) $row['reporter_id'] ) . '</td><td>' . esc_html( (string) $row['created_at'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}

