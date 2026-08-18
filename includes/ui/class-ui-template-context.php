<?php
/**
 * CLMS_UI_Template_Context
 *
 * Contexto normalizado por entidad y usuario.
 * Se crea una vez por template y se pasa al engine y al resolver.
 *
 * Campos públicos:
 *   entity_id      int     ID del post (curso, lección, programa)
 *   entity_type    string  'course' | 'lesson' | 'program'
 *   schema_context string  Contexto de schema: 'course_overview' | 'course_commercial' | 'lesson' | 'program_commercial'
 *   user_id        int     0 si no está logueado
 *   user_role      string  'guest' | 'student' | 'instructor' | 'admin'
 *   is_enrolled    bool
 *   is_admin       bool
 *   theme          string  'light' | 'dark' | 'auto'
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_Template_Context {

	private const CONTEXT_ALIASES = array(
		'landing_course'  => 'course_commercial',
		'landing_program' => 'program_commercial',
		'home_academy'    => 'course_overview',
	);

	public int    $entity_id;
	public string $entity_type;
	public string $schema_context;
	public int    $user_id;
	public string $user_role;
	public bool   $is_enrolled;
	public bool   $is_admin;
	public string $theme;

	public function __construct() {}

	/**
	 * Crea un contexto normalizado para la entidad y usuario actuales.
	 *
	 * El segundo parámetro es el schema_context explícito (ej: 'course_commercial',
	 * 'course_overview'). El entity_type se deriva de él internamente. No se
	 * sobreescribe el schema_context según enrollment — ese dispatch lo hace el
	 * template, no el contexto.
	 *
	 * @param int      $entity_id      ID del post.
	 * @param string   $schema_context Contexto de schema: 'course_commercial' | 'course_overview' | 'lesson' | …
	 * @param int|null $user_id        Null para usar el usuario actual de WP.
	 */
	public static function make( int $entity_id, string $schema_context, ?int $user_id = null ): self {
		$ctx = new self();

		$ctx->entity_id      = $entity_id;
		$ctx->schema_context = self::normalize_schema_context( sanitize_key( $schema_context ) );
		$ctx->entity_type    = self::derive_entity_type( $ctx->schema_context );
		$ctx->user_id        = $user_id ?? get_current_user_id();
		$ctx->is_admin       = current_user_can( 'manage_options' ) || current_user_can( 'clms_manage_courses' );
		$ctx->is_enrolled    = false;

		if ( $ctx->user_id && ! $ctx->is_admin && class_exists( 'CLMS_Helper' ) ) {
			if ( 'course' === $ctx->entity_type ) {
				$ctx->is_enrolled = (bool) CLMS_Helper::user_is_enrolled_in_course( $ctx->user_id, $entity_id );
			} elseif ( 'program' === $ctx->entity_type ) {
				$ctx->is_enrolled = (bool) CLMS_Helper::user_is_enrolled_in_program( $ctx->user_id, $entity_id );
			} elseif ( 'lesson' === $ctx->entity_type ) {
				$ctx->is_enrolled = (bool) CLMS_Helper::user_can_access_lesson( $ctx->user_id, $entity_id );
			}
		}

		if (
			'lesson' === $ctx->entity_type
			&& $ctx->user_id
			&& ! $ctx->is_enrolled
			&& (
				current_user_can( 'manage_options' )
				|| current_user_can( 'clms_manage_courses' )
				|| current_user_can( 'clms_manage_lessons' )
			)
		) {
			$ctx->is_enrolled = true;
		}

		if ( $ctx->is_admin ) {
			$ctx->is_enrolled = true;
		}

		$ctx->user_role = self::resolve_role( $ctx );
		$ctx->theme     = 'light';

		return $ctx;
	}

	/**
	 * Convierte el contexto a array simple (útil para pasar a filtros/JS).
	 */
	public function to_array(): array {
		return array(
			'entity_id'      => $this->entity_id,
			'entity_type'    => $this->entity_type,
			'schema_context' => $this->schema_context,
			'user_id'        => $this->user_id,
			'user_role'      => $this->user_role,
			'is_enrolled'    => $this->is_enrolled,
			'is_admin'       => $this->is_admin,
			'theme'          => $this->theme,
		);
	}

	// ── Privado ────────────────────────────────────────────────────────────────

	private static function resolve_role( self $ctx ): string {
		if ( $ctx->is_admin ) {
			return 'admin';
		}
		if ( ! $ctx->user_id ) {
			return 'guest';
		}
		if (
			$ctx->user_id
			&& (
				user_can( $ctx->user_id, 'clms_manage_courses' )
				|| user_can( $ctx->user_id, 'clms_manage_lessons' )
			)
		) {
			return 'instructor';
		}
		return 'student';
	}

	private static function derive_entity_type( string $schema_context ): string {
		if ( 0 === strpos( $schema_context, 'course' ) ) {
			return 'course';
		}
		if ( 0 === strpos( $schema_context, 'program' ) ) {
			return 'program';
		}
		if ( 'lesson' === $schema_context ) {
			return 'lesson';
		}
		return $schema_context;
	}

	private static function normalize_schema_context( string $schema_context ): string {
		return self::CONTEXT_ALIASES[ $schema_context ] ?? $schema_context;
	}
}
