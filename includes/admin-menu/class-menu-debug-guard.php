<?php
/**
 * CLMS_Menu_Debug_Guard — PT-4.2.4 (sprint 6.3.0)
 *
 * Red de seguridad contra el patrón de doble registro que PT-4.2
 * colapsó: con WP_DEBUG activo, registra en el log cualquier slug de
 * página admin registrado más de una vez durante 'admin_menu' —
 * incluso entre parents distintos (el patrón real que se encontró:
 * una copia oculta con parent='' y otra visible bajo 'clms-dashboard').
 *
 * No hace nada en producción (WP_DEBUG apagado) — es una herramienta
 * de desarrollo, no una validación en runtime.
 *
 * @package ATORA_LMS
 * @since   6.3.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Menu_Debug_Guard {

	public static function init(): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) { return; }
		add_action( 'admin_menu', array( __CLASS__, 'log_duplicate_slugs' ), 1000 );
	}

	public static function log_duplicate_slugs(): void {
		global $submenu, $menu;

		$seen = array();

		// Slugs de páginas de primer nivel.
		foreach ( (array) $menu as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( '' === $slug ) { continue; }
			$seen[ $slug ][] = 'top-level';
		}

		// Slugs de submenús, por cada parent registrado (incluye parent='').
		foreach ( (array) $submenu as $parent => $items ) {
			foreach ( (array) $items as $item ) {
				$slug = isset( $item[2] ) ? (string) $item[2] : '';
				if ( '' === $slug ) { continue; }
				$seen[ $slug ][] = '' === (string) $parent ? '(oculta)' : (string) $parent;
			}
		}

		foreach ( $seen as $slug => $parents ) {
			if ( count( $parents ) > 1 ) {
				error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					'[ATORA] Slug de menú admin registrado %d veces: "%s" (parents: %s)',
					count( $parents ),
					$slug,
					implode( ', ', $parents )
				) );
			}
		}
	}
}
