<?php
/**
 * CLMS_Menu_Debug_Guard — PT-4.2.4 / PT-4.5.1 (sprint 6.3.0)
 *
 * Con WP_DEBUG activo, dos chequeos al final de 'admin_menu':
 *
 * 1. Slug duplicado — red contra el patrón de doble registro que PT-4.2
 *    colapsó: cualquier slug registrado más de una vez, incluso entre
 *    parents distintos (copia oculta parent='' + copia visible bajo
 *    'clms-dashboard').
 * 2. Página huérfana — cualquier slug que (a) no pertenece a ningún
 *    módulo del registro ni a la lista de páginas núcleo conocidas, o
 *    (b) pertenece a un módulo inactivo pero de todas formas quedó
 *    registrado (fuga del gate de PT-2/PT-4.4.2).
 *
 * Los resultados quedan en memoria (self::$duplicate_findings /
 * self::$orphan_findings) además de ir al log, para que el panel de
 * diagnóstico de PT-4.5.2 pueda mostrarlos sin volver a escanear.
 *
 * No hace nada en producción (WP_DEBUG apagado) — es una herramienta
 * de desarrollo, no una validación en runtime.
 *
 * @package ATORA_LMS
 * @since   6.3.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Menu_Debug_Guard {

	/** @var string[] Páginas núcleo/agregadoras que no pertenecen a un solo módulo del registro. */
	const CORE_PAGES = array(
		'clms-dashboard', 'clms-settings', 'clms-settings-hub', 'clms-my-profile',
		'clms-maintenance', 'clms-crm-hub', 'clms-commerce-hub', 'clms-analytics',
		'clms-messages', 'clms-email-hub', 'clms-template-parts', 'atora-onboarding',
		'atora-license', 'atora-modules', 'atora-students-hub', 'atora-teachers-hub',
		'atora-communication-hub', 'atora-growth-hub', 'atora-reports-hub',
	);

	/** @var array<string,string[]> slug => parents donde se encontró duplicado. */
	private static array $duplicate_findings = array();

	/** @var array<string,string> slug => motivo (huérfana / módulo inactivo). */
	private static array $orphan_findings = array();

	public static function init(): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) { return; }
		add_action( 'admin_menu', array( __CLASS__, 'log_duplicate_slugs' ), 1000 );
		add_action( 'admin_menu', array( __CLASS__, 'log_orphan_pages' ), 1000 );
	}

	/**
	 * @return array<string,string[]>
	 */
	public static function get_duplicate_findings(): array {
		return self::$duplicate_findings;
	}

	/**
	 * @return array<string,string>
	 */
	public static function get_orphan_findings(): array {
		return self::$orphan_findings;
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
				self::$duplicate_findings[ $slug ] = $parents;
				error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					'[ATORA] Slug de menú admin registrado %d veces: "%s" (parents: %s)',
					count( $parents ),
					$slug,
					implode( ', ', $parents )
				) );
			}
		}
	}

	/**
	 * PT-4.5.1: slugs registrados que no pertenecen a ningún módulo
	 * conocido ni a la lista de páginas núcleo, o que pertenecen a un
	 * módulo inactivo pero igual quedaron registrados.
	 */
	public static function log_orphan_pages(): void {
		global $submenu, $menu;

		if ( ! class_exists( 'CLMS_Module_Registry' ) ) { return; }

		// slug => slug del módulo dueño.
		$owned_by = array();
		foreach ( CLMS_Module_Registry::get_modules() as $mod_slug => $def ) {
			foreach ( (array) ( $def['provides_pages'] ?? array() ) as $page_slug ) {
				$owned_by[ $page_slug ] = $mod_slug;
			}
		}

		$all_slugs = array();
		foreach ( (array) $menu as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( '' !== $slug ) { $all_slugs[ $slug ] = true; }
		}
		foreach ( (array) $submenu as $items ) {
			foreach ( (array) $items as $item ) {
				$slug = isset( $item[2] ) ? (string) $item[2] : '';
				if ( '' !== $slug ) { $all_slugs[ $slug ] = true; }
			}
		}

		foreach ( array_keys( $all_slugs ) as $slug ) {
			// Slugs de post-type nativos (edit.php?post_type=...) no aplican.
			if ( false !== strpos( $slug, '.php' ) || false !== strpos( $slug, '?' ) ) { continue; }

			if ( isset( $owned_by[ $slug ] ) ) {
				if ( ! CLMS_Module_Registry::is_active( $owned_by[ $slug ] ) ) {
					self::$orphan_findings[ $slug ] = sprintf( 'módulo "%s" inactivo pero la página sigue registrada', $owned_by[ $slug ] );
				}
				continue;
			}

			if ( in_array( $slug, self::CORE_PAGES, true ) ) { continue; }

			// Alias legacy y sub-rutas condicionales conocidas — no son huérfanas, son variantes documentadas.
			if ( 0 === strpos( $slug, 'atora-crm-v2-' ) || 0 === strpos( $slug, 'atora-crm-' ) || 'clms-ai-hub' === $slug ) { continue; }

			self::$orphan_findings[ $slug ] = 'sin módulo dueño ni en la lista de páginas núcleo';
		}

		foreach ( self::$orphan_findings as $slug => $reason ) {
			error_log( sprintf( '[ATORA] Página admin huérfana: "%s" (%s)', $slug, $reason ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
