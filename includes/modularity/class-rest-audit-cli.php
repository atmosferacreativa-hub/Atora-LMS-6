<?php
/**
 * CLMS_Rest_Audit_CLI — P2.4 (sprint 6.12.0)
 *
 * `wp atora rest-audit`
 *
 * Lista todas las rutas REST realmente registradas (vía rest_api_init,
 * que WP-CLI dispara como parte de su bootstrap completo de WordPress) y
 * las clasifica contra el `provides_rest` declarado por cada módulo en
 * CLMS_Module_Registry. Es la evidencia de que el gate de P2 quedó
 * completo: con cada perfil aplicado, ninguna ruta de un módulo inactivo
 * debería aparecer en el listado.
 *
 * Una ruta que no matchea ningún prefijo declarado se marca "SIN
 * CLASIFICAR" — igual criterio fail-open que CLMS_Module_Registry::
 * is_active() con un slug desconocido: se registra igual, no se bloquea,
 * pero queda visible para revisión manual en vez de desaparecer en
 * silencio.
 *
 * @package ATORA_LMS
 * @since   6.12.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }

class CLMS_Rest_Audit_CLI {

	public static function init(): void {
		\WP_CLI::add_command( 'atora rest-audit', array( __CLASS__, 'audit' ) );
	}

	/**
	 * Lista todas las rutas REST registradas y su módulo declarado.
	 *
	 * ## OPTIONS
	 *
	 * [--unclassified-only]
	 * : Muestra solo las rutas que no matchean ningún módulo declarado.
	 *
	 * [--format=<format>]
	 * : table, csv, json o yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp atora rest-audit
	 *     wp atora rest-audit --unclassified-only
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public static function audit( array $args, array $assoc_args ): void {
		unset( $args );

		if ( ! class_exists( 'CLMS_Module_Registry' ) ) {
			\WP_CLI::error( 'CLMS_Module_Registry no disponible. ¿Está el plugin activo?' );
			return;
		}

		$server = rest_get_server();
		$routes = $server->get_routes();

		$rows = array();
		foreach ( $routes as $route => $handlers ) {
			unset( $handlers );
			$classification = self::classify_route( $route );

			if ( ! empty( $assoc_args['unclassified-only'] ) && null !== $classification['module'] ) {
				continue;
			}

			$rows[] = array(
				'route'  => $route,
				'module' => $classification['module'] ?? 'SIN CLASIFICAR',
				'active' => null === $classification['module']
					? '—'
					: ( $classification['active'] ? 'sí' : 'no' ),
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'route', 'module', 'active' ) );

		$unclassified = count( array_filter( $rows, static fn( $r ) => 'SIN CLASIFICAR' === $r['module'] ) );
		\WP_CLI::log( sprintf( '%d rutas totales, %d sin clasificar.', count( $routes ), $unclassified ) );
	}

	/**
	 * @param string $route Ruta completa con namespace, p.ej. '/atora/v1/contacts'.
	 * @return array{module: string|null, active: bool}
	 */
	private static function classify_route( string $route ): array {
		// C8.1 (6.13.1): quitar el namespace ('/atora/v1', '/clms/v1', …)
		// antes de comparar — sin esto, strpos() sin anclar clasificaba
		// por subcadena en cualquier posición de la ruta completa (p.ej.
		// coincidir con un prefijo que aparece a mitad de un parámetro de
		// ruta), no solo al inicio del path real.
		$path = (string) preg_replace( '#^/[^/]+/v\d+#', '', $route );

		foreach ( CLMS_Module_Registry::get_modules() as $slug => $def ) {
			foreach ( (array) ( $def['provides_rest'] ?? array() ) as $prefix ) {
				if ( '' === $prefix ) { continue; }
				// Anclado al inicio del path (tras el namespace) — un
				// prefijo ya no puede acertar por coincidir en cualquier
				// posición de la ruta.
				if ( 0 === strpos( $path, $prefix ) ) {
					return array( 'module' => $slug, 'active' => CLMS_Module_Registry::is_active( $slug ) );
				}
			}
		}

		return array( 'module' => null, 'active' => false );
	}
}
