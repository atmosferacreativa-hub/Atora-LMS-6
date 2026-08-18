<?php
/**
 * CLMS_UI_Template_Migrator
 *
 * Migra schemas de UI de versiones anteriores al formato actual (v2).
 * Se aplica en tiempo de ejecución: los archivos JSON en disco pueden
 * permanecer en v1 sin romper nada.
 *
 * Versión 1 → Versión 2:
 *   - Secciones como string simple → objeto normalizado con id, enabled, variant, etc.
 *   - Añade campos top-level: preset, theme, variant, props, limits.
 *
 * fill_defaults()     → rellena campos top-level faltantes en un schema v2+.
 * normalize_schema()  → pipeline completo: migrate + fill_defaults + normalize sections.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Template_Migrator {

	const CURRENT_VERSION = 2;

	/**
	 * Valores top-level por defecto de un schema v2.
	 * Usados por fill_defaults() y empty_schema() en el repositorio.
	 */
	const SCHEMA_DEFAULTS = array(
		'version' => 2,
		'preset'  => 'default',
		'theme'   => 'light',
		'variant' => 'default',
		'props'   => array(),
		'limits'  => array(),
	);

	public function __construct() {}

	// ── Pipeline completo ──────────────────────────────────────────────────────

	/**
	 * Migra un schema al formato más reciente.
	 * Idempotente: si ya es v2+ solo rellena defaults y normaliza secciones.
	 */
	public static function migrate( array $schema ): array {
		$version = (int) ( $schema['version'] ?? 1 );

		if ( $version < 2 ) {
			$schema = self::v1_to_v2( $schema );
		}

		return self::fill_defaults( $schema );
	}

	/**
	 * Normalización completa: migrate + fill_defaults + normalizar cada sección.
	 * Punto de entrada recomendado para schemas recibidos de fuentes externas.
	 */
	public static function normalize_schema( array $schema ): array {
		$schema = self::migrate( $schema );

		$sections = array();
		foreach ( $schema['sections'] as $entry ) {
			$normalized = self::normalize_section( $entry );
			if ( $normalized ) {
				$sections[] = $normalized;
			}
		}
		$schema['sections'] = $sections;

		return $schema;
	}

	/**
	 * Rellena los campos top-level que falten en un schema ya migrado.
	 * No toca campos que ya existen. Seguro de llamar múltiples veces.
	 */
	public static function fill_defaults( array $schema ): array {
		foreach ( self::SCHEMA_DEFAULTS as $key => $default ) {
			if ( ! array_key_exists( $key, $schema ) ) {
				$schema[ $key ] = $default;
			}
		}
		// Garantizar que version siempre sea int
		$schema['version'] = max( (int) $schema['version'], self::CURRENT_VERSION );
		return $schema;
	}

	/**
	 * Indica si un schema necesita migración.
	 */
	public static function needs_migration( array $schema ): bool {
		return (int) ( $schema['version'] ?? 1 ) < self::CURRENT_VERSION;
	}

	/**
	 * Normaliza una entrada de sección individual al formato v2.
	 * Acepta string (solo id) o array parcial.
	 *
	 * @param string|array $raw
	 * @return array Array v2 normalizado, o array vacío si el id es inválido.
	 */
	public static function normalize_section( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = array( 'id' => $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$id = sanitize_key( (string) ( $raw['id'] ?? '' ) );
		if ( ! $id ) {
			return array();
		}

		return array(
			'id'         => $id,
			'enabled'    => isset( $raw['enabled'] ) ? (bool) $raw['enabled'] : true,
			'variant'    => sanitize_key( (string) ( $raw['variant'] ?? 'default' ) ),
			'props'      => is_array( $raw['props'] ?? null ) ? $raw['props'] : array(),
			'visibility' => sanitize_key( (string) ( $raw['visibility'] ?? 'always' ) ),
			'roles'      => is_array( $raw['roles'] ?? null )
				? array_values( array_map( 'sanitize_key', $raw['roles'] ) )
				: array(),
			'style'      => is_array( $raw['style'] ?? null ) ? $raw['style'] : array(),
		);
	}

	// ── Privado ────────────────────────────────────────────────────────────────

	private static function v1_to_v2( array $schema ): array {
		$sections = array();

		foreach ( ( $schema['sections'] ?? array() ) as $entry ) {
			$normalized = self::normalize_section( $entry );
			if ( $normalized ) {
				$sections[] = $normalized;
			}
		}

		return array_merge(
			$schema,
			array(
				'version'  => 2,
				'preset'   => $schema['preset'] ?? 'default',
				'theme'    => $schema['theme'] ?? 'light',
				'variant'  => $schema['variant'] ?? 'default',
				'props'    => is_array( $schema['props'] ?? null ) ? $schema['props'] : array(),
				'sections' => $sections,
				'limits'   => is_array( $schema['limits'] ?? null ) ? $schema['limits'] : array(),
			)
		);
	}
}
