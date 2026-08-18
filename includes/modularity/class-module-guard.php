<?php
/**
 * CLMS_Module_Guard — PT-2.6 (sprint 6.3.0)
 *
 * Defensa en profundidad para módulos desactivados: registra un stub por
 * cada shortcode de un módulo inactivo (así nunca queda "colgado" en el
 * contenido de un usuario) y bloquea el acceso directo a sus páginas admin
 * con un wp_die() legible. El gate real ya ocurre antes, en los 3 puntos de
 * carga (CLMS_Loader::module_condition_passes(), V5_Modules::boot(), y
 * atora_lms_require_module_if_active()) — esto es un respaldo para el caso
 * de un módulo futuro que declare provides_pages/provides_shortcodes sin
 * estar aún conectado al gate de carga.
 *
 * @package ATORA_LMS
 * @since   6.3.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Module_Guard {

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_shortcode_stubs' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'guard_disabled_pages' ), 0 );
	}

	/**
	 * Registra un shortcode "inocuo" para cada tag de un módulo inactivo.
	 * Prioridad 20 en `init`: después de que los 3 sistemas de carga hayan
	 * corrido (todos cuelgan de `init` prioridad 1), así si el módulo real
	 * SÍ estuviera activo su propio add_shortcode() ya ganó y este no lo
	 * pisa (WP usa el último registrado, así que el orden importa).
	 */
	public static function register_shortcode_stubs(): void {
		if ( ! class_exists( 'CLMS_Module_Registry' ) ) { return; }

		foreach ( CLMS_Module_Registry::get_modules() as $slug => $def ) {
			if ( CLMS_Module_Registry::is_active( $slug ) ) { continue; }
			foreach ( (array) ( $def['provides_shortcodes'] ?? array() ) as $tag ) {
				add_shortcode( $tag, array( __CLASS__, 'render_disabled_shortcode' ) );
			}
		}
	}

	/**
	 * @return string
	 */
	public static function render_disabled_shortcode(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		return '<p style="padding:8px 12px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;font-size:13px">'
			. esc_html__( 'Este contenido pertenece a un módulo desactivado. Actívalo en ATORA → Módulos.', 'atora-lms' )
			. '</p>';
	}

	/**
	 * Bloquea el acceso directo a una página admin cuyo módulo está inactivo.
	 */
	public static function guard_disabled_pages(): void {
		if ( ! class_exists( 'CLMS_Module_Registry' ) ) { return; }

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $page ) { return; }

		foreach ( CLMS_Module_Registry::get_modules() as $slug => $def ) {
			if ( CLMS_Module_Registry::is_active( $slug ) ) { continue; }
			if ( in_array( $page, (array) ( $def['provides_pages'] ?? array() ), true ) ) {
				wp_die(
					esc_html__( 'Este módulo está desactivado. Actívalo desde ATORA → Módulos para acceder a esta página.', 'atora-lms' ),
					esc_html__( 'Módulo desactivado', 'atora-lms' ),
					array( 'response' => 403, 'back_link' => true )
				);
			}
		}
	}
}
