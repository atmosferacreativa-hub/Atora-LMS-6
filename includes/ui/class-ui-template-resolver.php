<?php
/**
 * CLMS_UI_Template_Resolver
 *
 * Decide qué schema usar para una entidad dada.
 * Orquesta repositorio + contexto para devolver el schema correcto,
 * con cadena de fallback segura si el schema resultante no es válido.
 *
 * Uso típico desde un template:
 *
 *   $schema = (new CLMS_UI_Template_Resolver())->resolve( $course_id, 'course_commercial' );
 *   $schema = apply_filters( 'clms_course_commercial_ui_schema', $schema, $course_id );
 *   $ctx    = CLMS_UI_Template_Context::make( $course_id, 'course_commercial' );
 *   $out    = (new CLMS_UI_Template_Engine())->render_to_array( $ctx, $schema );
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Template_Resolver {

	private CLMS_UI_Schema_Repository $repository;

	public function __construct( ?CLMS_UI_Schema_Repository $repository = null ) {
		$this->repository = $repository ?? new CLMS_UI_Schema_Repository();
	}

	/**
	 * Resuelve el schema definitivo para post_id + schema_context.
	 *
	 * Cadena de fallback:
	 *   1. Repositorio (default + preset meta + override meta + theme meta)
	 *   2. get_default_schema() del repositorio
	 *   3. fallback_schema() interno (hardcoded)
	 *
	 * @param int    $post_id        ID del post.
	 * @param string $schema_context Contexto ('course_commercial', 'course_overview', …).
	 */
	public function resolve( int $post_id, string $schema_context ): array {
		if ( method_exists( $this->repository, 'normalize_context' ) ) {
			$schema_context = $this->repository->normalize_context( $schema_context );
		}

		// Paso 1: schema fusionado completo (default + metas)
		$schema = $this->repository->get_merged_schema( $post_id, $schema_context );

		if ( $this->repository->validate( $schema ) ) {
			return $schema;
		}

		// Paso 2: solo el default del JSON, sin metas
		$schema = $this->repository->get_default_schema( $schema_context );

		if ( $this->repository->validate( $schema ) ) {
			return $schema;
		}

		// Paso 3: fallback hardcoded (garantiza siempre un schema válido)
		return $this->fallback_schema( $schema_context );
	}

	/**
	 * Acceso directo al repositorio subyacente.
	 */
	public function repository(): CLMS_UI_Schema_Repository {
		return $this->repository;
	}

	// ── Privado ────────────────────────────────────────────────────────────────

	/**
	 * Schema hardcoded de emergencia. Solo se usa si JSON y metas fallan.
	 * Garantiza que siempre se devuelve algo usable.
	 */
	private function fallback_schema( string $schema_context ): array {
		$defaults = array(
			'course_overview'    => array( 'hero', 'video', 'about', 'academic', 'instructor', 'profiles', 'curriculum', 'gallery', 'testimonials', 'faq' ),
			'course_commercial'  => array( 'hero', 'instructor', 'benefits', 'summary', 'profiles', 'curriculum', 'requirements', 'gallery', 'testimonials', 'faq', 'cta', 'crm_lead', 'related' ),
			'lesson'             => array( 'breadcrumb', 'header', 'cover', 'videos', 'content', 'tips', 'live_class', 'resources', 'quiz', 'submission', 'next' ),
			'program_commercial' => array( 'hero', 'summary', 'academic', 'instructor', 'modules', 'cta', 'crm_lead', 'related' ),
			'program_overview'   => array( 'hero', 'summary', 'progress', 'academic', 'modules', 'instructor', 'cta' ),
			'teacher'            => array( 'teacher_hero', 'teacher_bio', 'teacher_achievements', 'teacher_courses' ),
		);

		$sections_raw = $defaults[ $schema_context ] ?? array();
		$sections     = array_values( array_filter(
			array_map( array( 'CLMS_UI_Template_Migrator', 'normalize_section' ), $sections_raw )
		) );

		return CLMS_UI_Template_Migrator::fill_defaults( array(
			'version'  => CLMS_UI_Template_Migrator::CURRENT_VERSION,
			'preset'   => 'default',
			'theme'    => 'light',
			'variant'  => 'default',
			'props'    => array(),
			'sections' => $sections,
			'limits'   => array(),
		) );
	}
}
