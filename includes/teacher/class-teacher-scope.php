<?php
/**
 * Alcance del docente (6.31.0): una sola regla para SpeedGrader web y para
 * `atora-mobile/v1/teacher/*`.
 *
 * Puede ver y calificar un curso (y sus entregas) quien cumpla cualquiera de:
 * - asignado a una sección del curso (`atora_section_teachers`);
 * - autor del curso o de la lección;
 * - permiso de editar lo ajeno (cursos, lecciones o entregas);
 * - delegación vigente del autor (`ATORA_Delegation_Service`, permiso `grade` o `content`);
 * - la regla anterior (`CLMS_Helper::user_can_manage_lms`) para el usuario en sesión:
 *   es una suma, nadie que hoy califica pierde acceso por las condiciones nuevas.
 *
 * Inquilino: con más de una institución activa, el administrador y quien edita lo
 * ajeno solo alcanzan los cursos de su institución, resuelta desde el curso
 * (`atora_courses.institution_id`; si el curso no la tiene, la institución por
 * defecto; sin ninguna de las dos, no se restringe). Con una sola institución no
 * cambia nada.
 *
 * @package ATORA_LMS
 * @since 6.31.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Teacher_Scope {

	/** Roles que abren `/teacher/*`. */
	const ROLES = array( 'lms_instructor', 'lms_instructor_assistant', 'lms_coordinator', 'administrator' );

	/** @var array<string,mixed> */
	private static array $cache = array();

	public static function reset_cache(): void {
		self::$cache = array();
	}

	public static function has_teacher_role( int $user_id ): bool {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user && array_intersect( self::ROLES, (array) $user->roles );
	}

	/** ¿Hay más de una institución activa? */
	public static function multi_institution(): bool {
		if ( ! array_key_exists( 'multi', self::$cache ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'atora_institutions';
			$count = 0;
			if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
				$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" ); // phpcs:ignore WordPress.DB
			}
			self::$cache['multi'] = $count > 1;
		}
		return self::$cache['multi'];
	}

	/** Institución de un curso (post `lm_course`), desde su fila de tabla. */
	public static function course_institution( int $wp_course_id ): int {
		$key = 'inst_' . $wp_course_id;
		if ( ! array_key_exists( $key, self::$cache ) ) {
			global $wpdb;
			$inst = (int) $wpdb->get_var( $wpdb->prepare( "SELECT institution_id FROM {$wpdb->prefix}atora_courses WHERE wp_post_id = %d LIMIT 1", $wp_course_id ) ); // phpcs:ignore WordPress.DB
			self::$cache[ $key ] = $inst > 0 ? $inst : absint( get_option( 'atora_default_institution', 0 ) );
		}
		return self::$cache[ $key ];
	}

	/** Con varias instituciones, ¿el curso es de la institución del usuario? */
	public static function in_tenant( int $user_id, int $wp_course_id ): bool {
		if ( ! self::multi_institution() ) {
			return true;
		}
		if ( ! class_exists( '\\ATORA\\LMS\\Tenant_Context' ) ) {
			return false;
		}
		$course_inst = self::course_institution( $wp_course_id );
		if ( $course_inst <= 0 ) {
			// Curso sin institución (ni institución por defecto): no hay inquilino que aplicar.
			return true;
		}
		$inst = \ATORA\LMS\Tenant_Context::resolve_institution_for_user( $user_id );
		if ( is_wp_error( $inst ) || ! $inst ) {
			$inst = absint( get_option( 'atora_default_institution', 0 ) );
		}
		return $inst > 0 && (int) $inst === $course_inst;
	}

	public static function is_section_teacher( int $user_id, int $wp_course_id ): bool {
		global $wpdb;
		$teachers = $wpdb->prefix . 'atora_section_teachers';
		$sections = $wpdb->prefix . 'atora_sections';
		return (bool) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT 1 FROM {$teachers} t INNER JOIN {$sections} s ON s.id = t.section_id
			 WHERE t.user_id = %d AND s.wp_course_id = %d AND s.status <> 'archived' LIMIT 1",
			$user_id,
			$wp_course_id
		) );
	}

	/** Cursos (post) de las secciones asignadas al usuario. */
	public static function section_course_ids( int $user_id ): array {
		global $wpdb;
		return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT DISTINCT s.wp_course_id FROM {$wpdb->prefix}atora_section_teachers t
			 INNER JOIN {$wpdb->prefix}atora_sections s ON s.id = t.section_id
			 WHERE t.user_id = %d AND s.status <> 'archived'",
			$user_id
		) ) );
	}

	private static function edits_others( int $user_id ): bool {
		return user_can( $user_id, 'edit_others_lm_courses' )
			|| user_can( $user_id, 'edit_others_lm_lessons' )
			|| user_can( $user_id, 'edit_others_clms_submissions' );
	}

	private static function delegated( int $user_id, int $post_id ): bool {
		return class_exists( 'ATORA_Delegation_Service' ) && $post_id > 0
			&& ( ATORA_Delegation_Service::covers( $user_id, $post_id, 'grade' ) || ATORA_Delegation_Service::covers( $user_id, $post_id, 'content' ) );
	}

	/** Regla anterior a 6.31.0 (solo evaluable para el usuario en sesión). */
	private static function legacy( int $user_id, int $post_id ): bool {
		return $post_id > 0 && get_current_user_id() === $user_id && class_exists( 'CLMS_Helper' ) && CLMS_Helper::user_can_manage_lms( $post_id );
	}

	/** ¿Puede ver el curso como docente (estudiantes, entregas, avisos)? */
	public static function can_access_course( int $user_id, int $wp_course_id ): bool {
		if ( $user_id <= 0 || $wp_course_id <= 0 || 'lm_course' !== get_post_type( $wp_course_id ) ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) || self::edits_others( $user_id ) ) {
			return self::in_tenant( $user_id, $wp_course_id );
		}
		return self::is_section_teacher( $user_id, $wp_course_id )
			|| (int) get_post_field( 'post_author', $wp_course_id ) === $user_id
			|| self::delegated( $user_id, $wp_course_id )
			|| self::legacy( $user_id, $wp_course_id );
	}

	/** Curso (post) y lección de una entrega. */
	public static function submission_course_lesson( int $submission_id ): array {
		$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		if ( ! $course_id && $lesson_id && class_exists( 'CLMS_Helper' ) ) {
			$course_id = absint( CLMS_Helper::get_lesson_course_id( $lesson_id ) );
		}
		return array( $course_id, $lesson_id );
	}

	/** ¿Puede ver y calificar la entrega? */
	public static function can_grade_submission( int $user_id, int $submission_id ): bool {
		if ( $user_id <= 0 || $submission_id <= 0 || 'clms_submission' !== get_post_type( $submission_id ) ) {
			return false;
		}
		list( $course_id, $lesson_id ) = self::submission_course_lesson( $submission_id );
		if ( ! $course_id && ! $lesson_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) || self::edits_others( $user_id ) ) {
			return ! $course_id || self::in_tenant( $user_id, $course_id );
		}
		if ( $course_id && self::can_access_course( $user_id, $course_id ) ) {
			return true;
		}
		return $lesson_id > 0 && (
			(int) get_post_field( 'post_author', $lesson_id ) === $user_id
			|| self::delegated( $user_id, $lesson_id )
			|| self::legacy( $user_id, $lesson_id )
		);
	}

	/**
	 * Cursos (post) que el usuario ve como docente.
	 *
	 * @return int[]
	 */
	public static function course_ids( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		if ( user_can( $user_id, 'manage_options' ) || self::edits_others( $user_id ) ) {
			$all = get_posts( array( 'post_type' => 'lm_course', 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
			return array_values( array_filter( array_map( 'absint', $all ), static fn( $id ) => self::in_tenant( $user_id, $id ) ) );
		}
		$ids = self::section_course_ids( $user_id );
		$ids = array_merge( $ids, array_map( 'absint', get_posts( array( 'post_type' => 'lm_course', 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => -1, 'fields' => 'ids', 'author' => $user_id, 'no_found_rows' => true ) ) ) );
		foreach ( get_posts( array( 'post_type' => 'lm_lesson', 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => -1, 'fields' => 'ids', 'author' => $user_id, 'no_found_rows' => true ) ) as $lesson_id ) {
			$ids[] = class_exists( 'CLMS_Helper' ) ? absint( CLMS_Helper::get_lesson_course_id( (int) $lesson_id ) ) : 0;
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		// Delegaciones: cursos de los instructores que delegaron en este usuario.
		foreach ( get_posts( array( 'post_type' => 'lm_course', 'post_status' => array( 'publish', 'private' ), 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'post__not_in' => $ids ?: array( 0 ) ) ) as $course_id ) {
			if ( self::delegated( $user_id, (int) $course_id ) ) {
				$ids[] = (int) $course_id;
			}
		}
		return $ids;
	}
}
