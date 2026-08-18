<?php
/**
 * CLMS_UI_Schema_Repository
 *
 * Responsabilidades:
 *   1. Cargar schemas por defecto desde assets/ui/*.json (migrados a v2).
 *   2. Cargar overrides guardados en post meta.
 *   3. Fusionar default + preset + theme + override en el schema resultante.
 *   4. Validar estructura mínima.
 *   5. Persistir y eliminar overrides.
 *
 * ── Claves de post meta ───────────────────────────────────────────────────────
 *
 * Schema completo por contexto (JSON v2):
 *   course_overview    → _clms_ui_schema_course_overview
 *   course_commercial  → _clms_ui_schema_course_commercial
 *   lesson             → _clms_ui_schema_lesson
 *   program_commercial → _clms_ui_schema_program_commercial
 *   program_overview   → _clms_ui_schema_program_overview
 *
 * Overrides ligeros (sin schema completo):
 *   _clms_ui_preset    → nombre de preset (string): "default"|"minimal"|"landing"
 *   _clms_ui_theme     → tema visual (string): "light"|"dark"|"auto"
 *
 * ── Prioridad de fusión ───────────────────────────────────────────────────────
 *   1. Default schema (JSON de disco)
 *   2. _clms_ui_preset  → aplica preset al default (modifica sections/theme)
 *   3. _clms_ui_schema_{context} → override completo de sections/limits/variant/props
 *   4. _clms_ui_theme   → sobreescribe solo el theme (máxima prioridad)
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Schema_Repository {

	// ── Constantes ─────────────────────────────────────────────────────────────

	const META_KEY_PREFIX = '_clms_ui_schema_';
	const META_KEY_PRESET = '_clms_ui_preset';
	const META_KEY_THEME  = '_clms_ui_theme';

	const ALLOWED_THEMES = array( 'light', 'dark', 'auto' );

	/**
	 * Contextos válidos → archivo JSON correspondiente en assets/ui/
	 */
	private static array $file_map = array(
		'course_overview'    => 'course-overview.json',
		'course_commercial'  => 'course-commercial.json',
		'lesson'             => 'lesson.json',
		'program_commercial' => 'program-commercial.json',
		'program_overview'   => 'program-overview.json',
		'teacher'            => 'teacher.json',
	);

	/**
	 * Alias de compatibilidad avanzada → contexto canónico.
	 *
	 * Mantiene naming legacy de theme/widgets sin duplicar schemas base.
	 */
	private static array $context_alias_map = array(
		'landing_course'  => 'course_commercial',
		'landing_program' => 'program_commercial',
		'home_academy'    => 'course_overview',
	);

	/** @var array<string,array> Cache en memoria: default schema por contexto */
	private array $default_cache = array();

	public function __construct() {}

	// ── Schema por defecto ─────────────────────────────────────────────────────

	/**
	 * Schema por defecto (desde JSON de disco), migrado y con defaults rellenos.
	 */
	public function get_default_schema( string $context ): array {
		$context = $this->normalize_context( $context );
		if ( isset( $this->default_cache[ $context ] ) ) {
			return $this->default_cache[ $context ];
		}

		$schema = $this->load_json( $context );
		$schema = CLMS_UI_Template_Migrator::normalize_schema( $schema );

		$this->default_cache[ $context ] = $schema;
		return $schema;
	}

	// ── Overrides de post meta ─────────────────────────────────────────────────

	/**
	 * Override de schema completo guardado en post meta. Null si no existe o inválido.
	 */
	public function get_post_override( int $post_id, string $context ): ?array {
		$context = $this->normalize_context( $context );
		$raw = get_post_meta( $post_id, self::META_KEY_PREFIX . $context, true );

		if ( ! $raw || ! is_string( $raw ) ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$schema = CLMS_UI_Template_Migrator::normalize_schema( $decoded );

		return $this->validate( $schema ) ? $schema : null;
	}

	/**
	 * Nombre del preset ligero guardado en post meta. Null si no existe.
	 */
	public function get_post_preset( int $post_id ): ?string {
		$raw = get_post_meta( $post_id, self::META_KEY_PRESET, true );
		return ( $raw && is_string( $raw ) ) ? sanitize_key( $raw ) : null;
	}

	/**
	 * Tema ligero guardado en post meta. Null si no existe o no es válido.
	 */
	public function get_post_theme( int $post_id ): ?string {
		$raw = get_post_meta( $post_id, self::META_KEY_THEME, true );
		if ( ! $raw || ! is_string( $raw ) ) {
			return null;
		}
		$theme = sanitize_key( $raw );
		return in_array( $theme, self::ALLOWED_THEMES, true ) ? $theme : null;
	}

	// ── Schema fusionado ───────────────────────────────────────────────────────

	/**
	 * Schema definitivo: default + preset + override + theme, pasado por filtro.
	 * Es el método que deben usar los templates y el resolver.
	 *
	 * Prioridad (de menor a mayor):
	 *   default schema → preset meta → schema override meta → theme meta
	 *
	 * @param int    $post_id
	 * @param string $context
	 * @return array Schema v2 válido y completo.
	 */
	public function get_merged_schema( int $post_id, string $context ): array {
		$resolved_context = $this->normalize_context( $context );
		$schema           = $this->get_default_schema( $resolved_context );

		// 1. Aplicar preset ligero (solo si existe y hay clase Presets disponible)
		$preset_name = $this->get_post_preset( $post_id );
		if ( $preset_name && class_exists( 'CLMS_UI_Template_Presets', false ) ) {
			$schema = CLMS_UI_Template_Presets::apply( $preset_name, $schema );
		}

		// 2. Fusionar override completo de schema (máxima autoridad sobre secciones)
		$override = $this->get_post_override( $post_id, $resolved_context );
		if ( $override ) {
			$schema = $this->merge_override( $schema, $override );
		}

		// 3. Aplicar tema ligero (máxima prioridad sobre el campo theme)
		$theme = $this->get_post_theme( $post_id );
		if ( $theme ) {
			$schema['theme'] = $theme;
		}

		/**
		 * Filtro de bajo nivel: permite modificar el schema fusionado antes de que
		 * el template lo use. Los templates pueden añadir su propio filtro encima
		 * (p.ej. 'clms_course_commercial_ui_schema').
		 *
		 * @param array  $schema   Schema resultante (v2).
		 * @param int    $post_id  ID del post.
		 * @param string $context  Contexto ('course_overview', 'course_commercial', …).
		 */
		return apply_filters( 'clms_ui_schema_loaded', $schema, $post_id, $resolved_context, $context );
	}

	// ── Persistencia de overrides ──────────────────────────────────────────────

	/**
	 * Persiste un override de schema completo en post meta.
	 * Valida y normaliza antes de guardar.
	 *
	 * @return bool True si se guardó correctamente.
	 */
	public function save_post_override( int $post_id, string $context, array $schema ): bool {
		$context = $this->normalize_context( $context );
		if ( ! $this->is_valid_context( $context ) ) {
			return false;
		}

		$schema = CLMS_UI_Template_Migrator::normalize_schema( $schema );

		if ( ! $this->validate( $schema ) ) {
			return false;
		}

		return (bool) update_post_meta(
			$post_id,
			self::META_KEY_PREFIX . $context,
			wp_json_encode( $schema, JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * Guarda el preset ligero en post meta.
	 */
	public function save_post_preset( int $post_id, string $preset ): bool {
		$preset = sanitize_key( $preset );
		if ( ! $preset ) {
			return false;
		}
		return (bool) update_post_meta( $post_id, self::META_KEY_PRESET, $preset );
	}

	/**
	 * Guarda el tema ligero en post meta. Solo acepta valores permitidos.
	 */
	public function save_post_theme( int $post_id, string $theme ): bool {
		$theme = sanitize_key( $theme );
		if ( ! in_array( $theme, self::ALLOWED_THEMES, true ) ) {
			return false;
		}
		return (bool) update_post_meta( $post_id, self::META_KEY_THEME, $theme );
	}

	/**
	 * Elimina el override completo de schema, dejando el default.
	 */
	public function delete_post_override( int $post_id, string $context ): bool {
		$context = $this->normalize_context( $context );
		return delete_post_meta( $post_id, self::META_KEY_PREFIX . $context );
	}

	/**
	 * Elimina el preset ligero.
	 */
	public function delete_post_preset( int $post_id ): bool {
		return delete_post_meta( $post_id, self::META_KEY_PRESET );
	}

	/**
	 * Elimina el tema ligero.
	 */
	public function delete_post_theme( int $post_id ): bool {
		return delete_post_meta( $post_id, self::META_KEY_THEME );
	}

	/**
	 * Elimina todos los overrides de UI de un post (schema + preset + theme).
	 * Útil para "Restablecer todo".
	 */
	public function delete_all_post_overrides( int $post_id ): void {
		foreach ( array_keys( self::$file_map ) as $context ) {
			$this->delete_post_override( $post_id, $context );
		}
		$this->delete_post_preset( $post_id );
		$this->delete_post_theme( $post_id );
	}

	// ── Validación ─────────────────────────────────────────────────────────────

	/**
	 * Valida que el schema tiene estructura mínima usable.
	 * Comprueba: versión válida, secciones array, cada sección con id de string.
	 */
	public function validate( array $schema ): bool {
		if ( ! isset( $schema['version'] ) || (int) $schema['version'] < 1 ) {
			return false;
		}

		if ( ! isset( $schema['sections'] ) || ! is_array( $schema['sections'] ) ) {
			return false;
		}

		// Al menos todas las entradas de secciones deben tener id string
		foreach ( $schema['sections'] as $section ) {
			if ( is_string( $section ) ) {
				continue; // v1 legacy strings aún válidas
			}
			if ( ! is_array( $section ) || empty( $section['id'] ) || ! is_string( $section['id'] ) ) {
				return false;
			}
		}

		return true;
	}

	// ── Utilidades de consulta ─────────────────────────────────────────────────

	/**
	 * Contextos registrados.
	 *
	 * @return string[]
	 */
	public function get_contexts(): array {
		return array_keys( self::$file_map );
	}

	public function is_valid_context( string $context ): bool {
		$context = $this->normalize_context( $context );
		return array_key_exists( $context, self::$file_map );
	}

	/**
	 * Normaliza un contexto recibido (canónico o alias).
	 */
	public function normalize_context( string $context ): string {
		$context = sanitize_key( $context );
		if ( isset( self::$context_alias_map[ $context ] ) ) {
			return self::$context_alias_map[ $context ];
		}
		return $context;
	}

	/**
	 * Devuelve todos los datos de override de UI de un post.
	 * Útil para introspección / debug / exportación.
	 *
	 * @return array{
	 *   preset: string|null,
	 *   theme: string|null,
	 *   schemas: array<string, array|null>
	 * }
	 */
	public function get_all_post_data( int $post_id ): array {
		$schemas = array();
		foreach ( array_keys( self::$file_map ) as $context ) {
			$schemas[ $context ] = $this->get_post_override( $post_id, $context );
		}

		return array(
			'preset'  => $this->get_post_preset( $post_id ),
			'theme'   => $this->get_post_theme( $post_id ),
			'schemas' => $schemas,
		);
	}

	// ── Privado ────────────────────────────────────────────────────────────────

	/**
	 * Fusiona un override sobre el schema base.
	 * El override tiene máxima autoridad sobre sections, limits, variant y props.
	 * theme y preset del override se aplican como valores — pueden seguir siendo
	 * sobreescritos por el tema ligero (_clms_ui_theme) si viene después.
	 */
	private function merge_override( array $base, array $override ): array {
		$merged = $base;

		// Sections: el override reemplaza completamente el orden y estado
		if ( ! empty( $override['sections'] ) ) {
			$merged['sections'] = $override['sections'];
		}

		// Limits: merge individual, el override tiene prioridad clave a clave
		if ( ! empty( $override['limits'] ) && is_array( $override['limits'] ) ) {
			$merged['limits'] = array_merge( $merged['limits'] ?? array(), $override['limits'] );
		}

		// Campos escalares: theme, preset, variant
		foreach ( array( 'theme', 'preset', 'variant' ) as $key ) {
			if ( array_key_exists( $key, $override ) && '' !== (string) $override[ $key ] ) {
				$merged[ $key ] = $override[ $key ];
			}
		}

		// Props: merge recursivo superficial; override tiene prioridad clave a clave
		if ( isset( $override['props'] ) && is_array( $override['props'] ) ) {
			$merged['props'] = array_merge( $merged['props'] ?? array(), $override['props'] );
		}

		return $merged;
	}

	private function load_json( string $context ): array {
		$context  = $this->normalize_context( $context );
		$filename = self::$file_map[ $context ] ?? null;

		if ( ! $filename || ! defined( 'ATORA_LMS_DIR' ) ) {
			return $this->empty_schema();
		}

		$path = trailingslashit( ATORA_LMS_DIR ) . 'assets/ui/' . $filename;

		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return $this->empty_schema();
		}

		$raw     = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$decoded = $raw ? json_decode( $raw, true ) : null;

		if ( ! is_array( $decoded ) ) {
			if ( $raw && JSON_ERROR_NONE !== json_last_error() ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'ATORA LMS: JSON parse error in ' . $path . ' — ' . json_last_error_msg() );
			}
			return $this->empty_schema();
		}

		return $decoded;
	}

	private function empty_schema(): array {
		return CLMS_UI_Template_Migrator::fill_defaults( array(
			'version'  => CLMS_UI_Template_Migrator::CURRENT_VERSION,
			'sections' => array(),
		) );
	}
}
