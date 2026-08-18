<?php
/**
 * CLMS_UI_Template_Presets
 *
 * Gestiona presets de template: configuraciones predefinidas que determinan
 * qué secciones están activas, su orden y su variante visual por sección.
 *
 * Un preset es un punto de partida. Aplicarlo produce un schema que el usuario
 * puede seguir personalizando desde el metabox sin perder la base del preset.
 *
 * Formato de registro de preset:
 *   label            string   — Etiqueta visible en el selector admin.
 *   description      string   — Texto de ayuda (tooltip).
 *   theme            string   — Tema visual sugerido ('light'|'dark'|'auto').
 *   contexts         string[] — Contextos donde aparece ([] = todos).
 *   sections         array[]  — Spec completa: [{id, enabled, variant, props}].
 *                               Cuando está presente, tiene prioridad sobre
 *                               sections_enabled / sections_order (formatos legados).
 *   sections_enabled string[] — Legado: IDs de secciones activas.
 *   sections_order   string[] — Legado: orden de secciones.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Template_Presets {

	/** @var array<string,array> */
	private static array $presets     = array();
	private static bool  $initialized = false;

	public function __construct() {
		self::maybe_init();
	}

	// ── API pública ────────────────────────────────────────────────────────────

	/**
	 * Registra un preset.
	 *
	 * @param string $name   Identificador único (ej: 'course-commercial-premium').
	 * @param array  $config Configuración del preset.
	 */
	public static function register( string $name, array $config ): void {
		self::$presets[ sanitize_key( $name ) ] = wp_parse_args( $config, array(
			'label'            => $name,
			'description'      => '',
			'theme'            => 'light',
			'contexts'         => array(),  // [] = visible en todos los contextos
			'sections'         => array(),  // spec completa (prioridad sobre campos legados)
			'sections_enabled' => array(),  // legado: IDs activos
			'sections_order'   => array(),  // legado: orden
		) );
	}

	/**
	 * Obtiene un preset por nombre. Null si no existe.
	 */
	public static function get( string $name ): ?array {
		self::maybe_init();
		return self::$presets[ sanitize_key( $name ) ] ?? null;
	}

	/**
	 * Lista de todos los presets registrados.
	 *
	 * @return array<string,array>
	 */
	public static function all(): array {
		self::maybe_init();
		return self::$presets;
	}

	/**
	 * Presets disponibles para un contexto determinado.
	 * Un preset sin `contexts` definidos aparece en todos los contextos.
	 *
	 * @param string $context  Ej: 'course_commercial', 'lesson', …
	 * @return array<string,array>
	 */
	public static function get_for_context( string $context ): array {
		self::maybe_init();
		$result = array();
		foreach ( self::$presets as $key => $preset ) {
			$contexts = $preset['contexts'] ?? array();
			if ( empty( $contexts ) || in_array( $context, $contexts, true ) ) {
				$result[ $key ] = $preset;
			}
		}
		return $result;
	}

	/**
	 * Aplica un preset sobre un schema existente y devuelve el schema modificado.
	 * No persiste nada; el llamador decide si guardar el resultado.
	 *
	 * Prioridad de aplicación:
	 *   1. `sections` (spec completa) — establece orden, enabled y variant por sección.
	 *   2. `sections_order` / `sections_enabled` (formato legado) — solo orden y enabled.
	 * Las secciones no incluidas en el preset se añaden al final como desactivadas.
	 *
	 * @param string $preset_name
	 * @param array  $schema  Schema v2 sobre el que aplicar.
	 * @return array Schema modificado.
	 */
	public static function apply( string $preset_name, array $schema ): array {
		self::maybe_init();
		$preset = self::$presets[ sanitize_key( $preset_name ) ] ?? null;
		if ( ! $preset ) {
			return $schema;
		}

		$schema['preset'] = $preset_name;
		$schema['theme']  = $preset['theme'];

		// Mapa id → sección del schema actual (para preservar props/visibility/style)
		$map = array();
		foreach ( $schema['sections'] as $sec ) {
			if ( is_array( $sec ) && ! empty( $sec['id'] ) ) {
				$map[ sanitize_key( (string) $sec['id'] ) ] = $sec;
			}
		}

		// ── Formato nuevo: sections spec completa ─────────────────────────────
		$spec = $preset['sections'] ?? array();
		if ( ! empty( $spec ) ) {
			$new_sections = array();
			$spec_ids     = array();

			foreach ( $spec as $entry ) {
				$sid = sanitize_key( (string) ( $entry['id'] ?? '' ) );
				if ( ! $sid ) {
					continue;
				}
				$spec_ids[] = $sid;

				// Parte del schema existente o plantilla vacía
				$sec = $map[ $sid ] ?? array(
					'id'         => $sid,
					'variant'    => 'default',
					'props'      => array(),
					'visibility' => 'always',
					'roles'      => array(),
					'style'      => array(),
				);

				$sec['enabled'] = (bool) ( $entry['enabled'] ?? true );

				// Sobrescribir variant si el preset lo especifica
				if ( isset( $entry['variant'] ) && '' !== $entry['variant'] ) {
					$sec['variant'] = (string) $entry['variant'];
				}

				// Merge superficial de props (el preset añade, no reemplaza)
				if ( ! empty( $entry['props'] ) && is_array( $entry['props'] ) ) {
					$sec['props'] = array_merge( (array) ( $sec['props'] ?? array() ), $entry['props'] );
				}

				$new_sections[] = $sec;
			}

			// Secciones del schema no cubiertas por el preset → desactivadas, al final
			foreach ( $map as $sid => $sec ) {
				if ( ! in_array( $sid, $spec_ids, true ) ) {
					$sec['enabled'] = false;
					$new_sections[] = $sec;
				}
			}

			$schema['sections'] = $new_sections;
			return $schema;
		}

		// ── Formato legado: sections_enabled / sections_order ─────────────────
		$enabled = $preset['sections_enabled'] ?? array();
		$order   = $preset['sections_order']   ?? array();

		if ( ! empty( $enabled ) || ! empty( $order ) ) {
			$ref          = ! empty( $order ) ? $order : $enabled;
			$new_sections = array();

			foreach ( $ref as $sid ) {
				$sid = sanitize_key( $sid );
				if ( isset( $map[ $sid ] ) ) {
					$entry            = $map[ $sid ];
					$entry['enabled'] = ! empty( $enabled ) ? in_array( $sid, $enabled, true ) : true;
					$new_sections[]   = $entry;
				}
			}

			foreach ( $map as $sid => $sec ) {
				if ( ! in_array( $sid, $ref, true ) ) {
					$sec['enabled'] = false;
					$new_sections[] = $sec;
				}
			}

			$schema['sections'] = $new_sections;
		}

		return $schema;
	}

	// ── Privado ────────────────────────────────────────────────────────────────

	private static function maybe_init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		self::register_defaults();

		/**
		 * Permite a extensiones o temas registrar presets adicionales.
		 *
		 * @param CLMS_UI_Template_Presets $class  (uso: CLMS_UI_Template_Presets::register())
		 */
		do_action( 'clms_ui_register_presets' );
	}

	private static function register_defaults(): void {

		// ── Presets genéricos (todos los contextos) ────────────────────────────

		self::register( 'default', array(
			'label'       => __( 'Por defecto', 'atora-lms' ),
			'description' => __( 'Todas las secciones activas en el orden estándar.', 'atora-lms' ),
			'theme'       => 'light',
		) );

		self::register( 'minimal', array(
			'label'            => __( 'Mínimo', 'atora-lms' ),
			'description'      => __( 'Solo las secciones esenciales: cabecera, temario y CTA.', 'atora-lms' ),
			'theme'            => 'light',
			'sections_enabled' => array( 'hero', 'curriculum', 'cta' ),
		) );

		// ── Presets de curso comercial ─────────────────────────────────────────

		self::register( 'course-commercial-premium', array(
			'label'       => __( 'Landing Premium', 'atora-lms' ),
			'description' => __( 'Optimizado para conversión máxima. Hero destacado, beneficios, testimonios, docente y CTA.', 'atora-lms' ),
			'theme'       => 'light',
			'contexts'    => array( 'course_commercial' ),
			'sections'    => array(
				array( 'id' => 'hero',         'enabled' => true,  'variant' => 'centered' ),
				array( 'id' => 'summary',      'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'benefits',     'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'testimonials', 'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'instructor',   'enabled' => true,  'variant' => 'minimal'  ),
				array( 'id' => 'curriculum',   'enabled' => true,  'variant' => 'accordion' ),
				array( 'id' => 'faq',          'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'cta',          'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'related',      'enabled' => true,  'variant' => 'default'  ),
			),
		) );

		self::register( 'landing', array(
			'label'            => __( 'Landing comercial', 'atora-lms' ),
			'description'      => __( 'Optimizado para conversión: hero, beneficios, docente, temario y CTA.', 'atora-lms' ),
			'theme'            => 'light',
			'contexts'         => array( 'course_commercial' ),
			'sections_enabled' => array( 'hero', 'benefits', 'instructor', 'curriculum', 'testimonials', 'faq', 'cta' ),
		) );

		// ── Presets de curso académico / mixto ─────────────────────────────────

		self::register( 'course-academic-classic', array(
			'label'       => __( 'Académico clásico', 'atora-lms' ),
			'description' => __( 'Estructura formal: presentación, perfiles, docente, temario, requisitos y FAQ.', 'atora-lms' ),
			'theme'       => 'light',
			'contexts'    => array( 'course_commercial', 'course_overview' ),
			'sections'    => array(
				array( 'id' => 'hero',         'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'about',        'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'profiles',     'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'instructor',   'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'curriculum',   'enabled' => true,  'variant' => 'accordion' ),
				array( 'id' => 'requirements', 'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'faq',          'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'cta',          'enabled' => true,  'variant' => 'default'  ),
			),
		) );

		self::register( 'course-bootcamp', array(
			'label'       => __( 'Bootcamp', 'atora-lms' ),
			'description' => __( 'Intensivo y directo: requisitos claros, temario compacto, CTA inmediato.', 'atora-lms' ),
			'theme'       => 'dark',
			'contexts'    => array( 'course_commercial', 'course_overview' ),
			'sections'    => array(
				array( 'id' => 'hero',         'enabled' => true,  'variant' => 'minimal'  ),
				array( 'id' => 'requirements', 'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'curriculum',   'enabled' => true,  'variant' => 'compact'  ),
				array( 'id' => 'faq',          'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'cta',          'enabled' => true,  'variant' => 'default'  ),
			),
		) );

		// ── Presets de lección ─────────────────────────────────────────────────

		self::register( 'lesson-focus-player', array(
			'label'       => __( 'Lección: Solo vídeo', 'atora-lms' ),
			'description' => __( 'Interfaz minimalista centrada en el reproductor. Sin distracciones.', 'atora-lms' ),
			'theme'       => 'dark',
			'contexts'    => array( 'lesson' ),
			'sections'    => array(
				array( 'id' => 'cover',      'enabled' => true,  'variant' => 'default' ),
				array( 'id' => 'content',    'enabled' => true,  'variant' => 'default' ),
				array( 'id' => 'navigation', 'enabled' => true,  'variant' => 'default' ),
			),
		) );

		self::register( 'lesson-academic-sidebar', array(
			'label'       => __( 'Lección: Académica completa', 'atora-lms' ),
			'description' => __( 'Estructura completa: breadcrumb, vídeo, contenido, recursos, evaluación, progreso y navegación.', 'atora-lms' ),
			'theme'       => 'light',
			'contexts'    => array( 'lesson' ),
			'sections'    => array(
				array( 'id' => 'breadcrumb',  'enabled' => true,  'variant' => 'default' ),
				array( 'id' => 'header',      'enabled' => true,  'variant' => 'default' ),
				array( 'id' => 'cover',       'enabled' => true,  'variant' => 'default' ),
				array( 'id' => 'content',     'enabled' => true,  'variant' => 'default' ),
				array( 'id' => 'resources',   'enabled' => true,  'variant' => 'default' ),
				array( 'id' => 'evaluation',  'enabled' => true,  'variant' => 'default' ),
				array( 'id' => 'tips',        'enabled' => true,  'variant' => 'default' ),
				array( 'id' => 'navigation',  'enabled' => true,  'variant' => 'default' ),
				array( 'id' => 'progress',    'enabled' => true,  'variant' => 'default' ),
			),
		) );

		// ── Presets de programa ────────────────────────────────────────────────

		self::register( 'program-commercial-authority', array(
			'label'       => __( 'Programa: Autoridad', 'atora-lms' ),
			'description' => __( 'Programa formativo de alto nivel. Docente destacado, módulos y CTA contundente.', 'atora-lms' ),
			'theme'       => 'light',
			'contexts'    => array( 'program_commercial', 'program_overview' ),
			'sections'    => array(
				array( 'id' => 'hero',       'enabled' => true,  'variant' => 'centered' ),
				array( 'id' => 'progress',   'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'instructor', 'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'summary',    'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'academic',   'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'modules',    'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'cta',        'enabled' => true,  'variant' => 'default'  ),
				array( 'id' => 'related',    'enabled' => true,  'variant' => 'default'  ),
			),
		) );
	}
}
