<?php
/**
 * App móvil (6.33.0): ajustes de la academia para la app publicada.
 *
 * - Reporte de cierres inesperados de la app (sin datos personales): activo por
 *   defecto; la academia puede desactivarlo. Lo lee la app en `/discovery`
 *   (`capabilities.crash_reports`).
 * - Enlaces públicos que piden las tiendas: eliminar la cuenta sin la app y
 *   verificar certificados.
 *
 * @package ATORA_LMS
 * @since 6.33.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Mobile_Settings_Admin {

	const PAGE          = 'atora-mobile-app';
	const CRASH_OPTION  = 'atora_mobile_crash_reports';

	public static function boot(): void {
		add_action( 'atora_lms_admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_atora_mobile_settings', array( __CLASS__, 'save' ) );
	}

	public static function crash_reports_enabled(): bool {
		return '0' !== (string) get_option( self::CRASH_OPTION, '1' );
	}

	public static function menu(): void {
		add_submenu_page( 'clms-dashboard', __( 'App móvil', 'atora-lms' ), __( 'App móvil', 'atora-lms' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}
		check_admin_referer( 'atora_mobile_settings' );
		update_option( self::CRASH_OPTION, empty( $_POST['crash_reports'] ) ? '0' : '1', false );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'App móvil', 'atora-lms' ) . '</h1>';
		if ( ! empty( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Ajustes guardados.', 'atora-lms' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'atora_mobile_settings' );
		echo '<input type="hidden" name="action" value="atora_mobile_settings">';
		printf(
			'<p><label><input type="checkbox" name="crash_reports" value="1" %1$s> %2$s</label></p><p class="description">%3$s</p>',
			checked( self::crash_reports_enabled(), true, false ),
			esc_html__( 'Enviar reportes de cierres inesperados de la app', 'atora-lms' ),
			esc_html__( 'Ayudan a corregir fallos. No incluyen nombre, correo, contenido ni datos de la cuenta: solo el error técnico, el modelo de teléfono y la versión de la app.', 'atora-lms' )
		);
		submit_button( __( 'Guardar', 'atora-lms' ) );
		echo '</form><h2>' . esc_html__( 'Enlaces públicos para las tiendas', 'atora-lms' ) . '</h2><ul>';
		if ( class_exists( 'ATORA_Account_Deletion' ) ) {
			printf( '<li>%1$s: <a href="%2$s">%2$s</a></li>', esc_html__( 'Eliminar la cuenta sin la app', 'atora-lms' ), esc_url( ATORA_Account_Deletion::public_url() ) );
		}
		if ( class_exists( 'ATORA_Certificate_Verify' ) ) {
			printf( '<li>%1$s: <a href="%2$s">%2$s</a></li>', esc_html__( 'Verificar certificados', 'atora-lms' ), esc_url( get_option( 'permalink_structure' ) ? home_url( '/verificar/' ) : add_query_arg( ATORA_Certificate_Verify::QUERY_VAR, '__form', home_url( '/' ) ) ) );
		}
		echo '</ul></div>';
	}
}
