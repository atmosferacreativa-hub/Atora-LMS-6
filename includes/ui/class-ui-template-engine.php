<?php
/**
 * CLMS_UI_Template_Engine
 *
 * Motor central que:
 *   1. Resuelve qué secciones del schema están activas para el contexto dado
 *      (filtra por enabled, roles, visibility).
 *   2. Devuelve los IDs ordenados para que los templates iteren.
 *   3. Cuando las secciones tienen render_callback registrado en el registry,
 *      puede renderizarlas directamente (uso futuro con el builder admin).
 *   4. Extrae límites del schema con fallback seguro.
 *
 * Los templates actuales siguen renderizando su propio HTML —
 * este engine les provee el orden y activación de secciones.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Template_Engine {

	private CLMS_UI_Section_Registry $registry;

	public function __construct( ?CLMS_UI_Section_Registry $registry = null ) {
		if ( $registry ) {
			$this->registry = $registry;
		} elseif ( class_exists( 'CLMS_UI_Section_Registry' ) ) {
			$this->registry = CLMS_UI_Section_Registry::instance();
		}
	}

	// ── API para templates ─────────────────────────────────────────────────────

	/**
	 * Devuelve los IDs de sección activos y en orden, filtrados por contexto.
	 *
	 * Es el reemplazo de las funciones locales clms_*_resolve_sections() de
	 * los templates individuales.
	 *
	 * @param array                    $schema  Schema v2 (ya migrado).
	 * @param CLMS_UI_Template_Context $context Contexto del usuario/entidad.
	 * @return string[]
	 */
	public function ordered_section_ids( array $schema, CLMS_UI_Template_Context $context ): array {
		return array_keys( $this->resolve_sections( $schema, $context ) );
	}

	/**
	 * Resuelve secciones activas → array indexado por id con la definición v2.
	 *
	 * @return array<string,array>
	 */
	public function resolve_sections( array $schema, CLMS_UI_Template_Context $context ): array {
		$raw      = $schema['sections'] ?? array();
		$resolved = array();

		foreach ( $raw as $entry ) {
			if ( is_string( $entry ) ) {
				$entry = array( 'id' => $entry, 'enabled' => true );
			}
			if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
				continue;
			}

			// Desactivada explícitamente
			if ( ! ( (bool) ( $entry['enabled'] ?? true ) ) ) {
				continue;
			}

			// Visibilidad — "always" pasa siempre; "enrolled_only" requiere inscripción
			$visibility = (string) ( $entry['visibility'] ?? 'always' );
			if ( 'enrolled_only' === $visibility && ! $context->is_enrolled ) {
				continue;
			}
			if ( 'admin_only' === $visibility && ! $context->is_admin ) {
				continue;
			}

			// Filtro por rol — lista vacía = sin restricción
			$roles = $entry['roles'] ?? array();
			if ( ! empty( $roles ) && ! in_array( $context->user_role, $roles, true ) ) {
				continue;
			}

			$id             = sanitize_key( (string) $entry['id'] );
			$resolved[ $id ] = $entry;
		}

		return $resolved;
	}

	/**
	 * Extrae un límite numérico del schema con fallback seguro.
	 *
	 * @param array  $schema  Schema cargado.
	 * @param string $key     Clave del límite (ej: 'lesson_preview').
	 * @param int    $default Valor por defecto si no existe la clave.
	 */
	public function get_limit( array $schema, string $key, int $default = 0 ): int {
		$limits = $schema['limits'] ?? array();
		if ( ! is_array( $limits ) || ! array_key_exists( $key, $limits ) ) {
			return max( 0, $default );
		}
		return max( 0, (int) $limits[ $key ] );
	}

	// ── Render con callbacks ───────────────────────────────────────────────────

	/**
	 * Renderiza secciones con render_callback y devuelve array id → html.
	 * Usado por thin-wrapper templates para controlar el layout de ensamblado.
	 *
	 * @return array<string,string>
	 */
	public function render_to_array( CLMS_UI_Template_Context $context, array $schema ): array {
		$sections = $this->resolve_sections( $schema, $context );
		$output   = array();

		foreach ( $sections as $id => $section ) {
			if ( ! isset( $this->registry ) ) {
				continue;
			}

			$definition = $this->registry->get( $id );
			if ( ! $definition || ! is_callable( $definition['render_callback'] ?? null ) ) {
				continue;
			}

			ob_start();
			call_user_func( $definition['render_callback'], $section, $context, $schema );
			$html = (string) ob_get_clean();

			if ( $html ) {
				$output[ $id ] = (string) apply_filters( 'clms_ui_section_output', $html, $id, $context, $section );
			}
		}

		return $output;
	}

	/**
	 * Renderiza secciones que tienen render_callback registrado.
	 * Devuelve HTML ensamblado.
	 */
	public function render( CLMS_UI_Template_Context $context, array $schema ): string {
		return implode( "\n", $this->render_to_array( $context, $schema ) );
	}
}
