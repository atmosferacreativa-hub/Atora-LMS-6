<?php
/**
 * LMS_Course_Service — CRUD de cursos en tablas propias (Fase 11)
 *
 * Coexiste con los CPTs lm_course/lm_lesson durante la transición.
 * El campo wp_post_id permite sincronización bidireccional.
 *
 * @package ATORA_LMS\LMS
 * @since   5.29.0
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMS_Course_Service {

	// ── Cursos ───────────────────────────────────────────────────────────────

	public static function get( int $course_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_courses WHERE id = %d LIMIT 1", $course_id ),
			ARRAY_A
		);
		return $row ? self::format_course( $row ) : null;
	}

	public static function get_by_wp_post( int $wp_post_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_courses WHERE wp_post_id = %d LIMIT 1", $wp_post_id ),
			ARRAY_A
		);
		return $row ? self::format_course( $row ) : null;
	}

	public static function get_all( array $args = array() ): array {
		global $wpdb;

		$table    = $wpdb->prefix . 'atora_courses';
		$limit    = max( 1, min( 200, absint( $args['limit']  ?? 20 ) ) );
		$offset   = absint( $args['offset'] ?? 0 );
		$status   = sanitize_key( (string) ( $args['status'] ?? 'published' ) );
		$search   = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$instr_id = absint( $args['instructor_id'] ?? 0 );

		$where  = 'WHERE 1=1';
		$params = array();

		if ( '' !== $status && 'all' !== $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}
		if ( '' !== $search ) {
			$where   .= ' AND (title LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		if ( $instr_id ) {
			$where   .= ' AND instructor_id = %d';
			$params[] = $instr_id;
		}

		$params_c = $params;
		$total    = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			! empty( $params_c )
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", ...$params_c )
				: "SELECT COUNT(*) FROM {$table} {$where}"
		);

		$params[] = $limit;
		$params[] = $offset;
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY published_at DESC, id DESC LIMIT %d OFFSET %d", ...$params ),
			ARRAY_A
		);

		return array(
			'items' => array_map( array( __CLASS__, 'format_course' ), $rows ),
			'total' => $total,
		);
	}

	public static function create( array $data ): int {
		global $wpdb;
		$row = self::sanitize_course_data( $data );
		if ( '' === ( $row['title'] ?? '' ) ) { return 0; }

		$ok = $wpdb->insert( $wpdb->prefix . 'atora_courses', $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update( int $course_id, array $data ): bool {
		global $wpdb;
		$row = self::sanitize_course_data( $data );
		unset( $row['created_at'] );
		if ( empty( $row ) ) { return false; }
		return false !== $wpdb->update(
			$wpdb->prefix . 'atora_courses',
			$row,
			array( 'id' => $course_id ),
			null,
			array( '%d' )
		);
	}

	/**
	 * PT-1.3 (6.5.3): única vía de escritura de wp_post_id fuera del
	 * migrador — el CRUD genérico (create()/update()) ya no acepta ese
	 * campo en absoluto, sin importar la capability del llamador. Es
	 * el puente de identidad con el LMS legado (CPT lm_course), no
	 * metadata decorativa: LMS_Enrollment_Service lo usa para resolver
	 * matrícula cuando atora_lms_read_source = tables, y el migrador
	 * lo consulta para decidir si un curso ya fue migrado.
	 *
	 * @param int $course_id
	 * @param int $wp_post_id
	 * @return array{ok:bool, reason?:string}
	 */
	public static function link_to_legacy_post( int $course_id, int $wp_post_id ): array {
		global $wpdb;

		if ( ! $course_id || ! $wp_post_id ) {
			return array( 'ok' => false, 'reason' => 'datos_invalidos' );
		}

		if ( 'lm_course' !== get_post_type( $wp_post_id ) ) {
			return array( 'ok' => false, 'reason' => 'no_es_lm_course' );
		}

		if ( ! current_user_can( 'edit_post', $wp_post_id ) ) {
			return array( 'ok' => false, 'reason' => 'sin_permiso_sobre_el_post' );
		}

		// Defensa en profundidad además del UNIQUE KEY de la tabla —
		// para devolver un error claro en vez de que el UPDATE falle
		// en seco por la restricción de base de datos.
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}atora_courses WHERE wp_post_id = %d AND id != %d LIMIT 1",
				$wp_post_id,
				$course_id
			)
		);
		if ( $existing ) {
			return array( 'ok' => false, 'reason' => 'ya_vinculado_a_otro_curso' );
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'atora_courses',
			array( 'wp_post_id' => $wp_post_id ),
			array( 'id' => $course_id ),
			array( '%d' ),
			array( '%d' )
		);

		return array( 'ok' => false !== $updated );
	}

	// ── Lecciones ────────────────────────────────────────────────────────────

	public static function get_lessons( int $course_id ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}atora_lessons
				 WHERE course_id = %d AND status = 'published'
				 ORDER BY section_order ASC, lesson_order ASC",
				$course_id
			),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'format_lesson' ), $rows );
	}

	public static function get_lesson( int $lesson_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_lessons WHERE id = %d LIMIT 1", $lesson_id ),
			ARRAY_A
		);
		return $row ? self::format_lesson( $row ) : null;
	}

	public static function get_lesson_by_wp_post( int $wp_post_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_lessons WHERE wp_post_id = %d LIMIT 1", $wp_post_id ),
			ARRAY_A
		);
		return $row ? self::format_lesson( $row ) : null;
	}

	public static function upsert_lesson( array $data ): int {
		global $wpdb;
		$row = self::sanitize_lesson_data( $data );
		if ( '' === ( $row['title'] ?? '' ) || ! ( $row['course_id'] ?? 0 ) ) { return 0; }

		$wp_post_id = absint( $data['wp_post_id'] ?? 0 );
		$existing   = $wp_post_id
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_lessons WHERE wp_post_id = %d LIMIT 1", $wp_post_id ) )
			: 0;

		if ( $existing ) {
			unset( $row['created_at'] );
			$wpdb->update( $wpdb->prefix . 'atora_lessons', $row, array( 'id' => $existing ), null, array( '%d' ) );
			return $existing;
		}

		$ok = $wpdb->insert( $wpdb->prefix . 'atora_lessons', $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	// ── Curriculum estructurado ───────────────────────────────────────────────

	public static function get_curriculum( int $course_id ): array {
		// Fase V S16: Object cache (Redis si disponible, WP default como fallback)
		$cache_key  = 'atora_curriculum_' . $course_id;
		$cache_group = 'atora_lms';
		$cached     = wp_cache_get( $cache_key, $cache_group );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$lessons  = self::get_lessons( $course_id );
		$sections = array();

		foreach ( $lessons as $lesson ) {
			$section = $lesson['section'] ?: __( 'General', 'atora-lms' );
			if ( ! isset( $sections[ $section ] ) ) {
				$sections[ $section ] = array(
					'title'   => $section,
					'order'   => $lesson['section_order'],
					'lessons' => array(),
				);
			}
			$sections[ $section ]['lessons'][] = $lesson;
		}

		usort( $sections, fn( $a, $b ) => $a['order'] <=> $b['order'] );
		$curriculum = array_values( $sections );

		wp_cache_set( $cache_key, $curriculum, $cache_group, 300 ); // 5 minutos
		return $curriculum;
	}

	/**
	 * Invalida el cache del curriculum de un curso.
	 * Llamar tras actualizar lecciones.
	 *
	 * @param int $course_id ID del curso.
	 */
	public static function invalidate_curriculum_cache( int $course_id ): void {
		wp_cache_delete( 'atora_curriculum_' . $course_id, 'atora_lms' );
	}

	// ── Helpers de formato ───────────────────────────────────────────────────

	private static function format_course( array $row ): array {
		return array(
			'id'             => absint( $row['id'] ),
			'wp_post_id'     => absint( $row['wp_post_id'] ),
			'title'          => sanitize_text_field( (string) ( $row['title']          ?? '' ) ),
			'slug'           => sanitize_title(      (string) ( $row['slug']           ?? '' ) ),
			'excerpt'        => sanitize_textarea_field( (string) ( $row['excerpt']    ?? '' ) ),
			'status'         => sanitize_key(        (string) ( $row['status']         ?? 'draft' ) ),
			'type'           => sanitize_key(        (string) ( $row['type']           ?? 'self_paced' ) ),
			'instructor_id'  => absint(                        $row['instructor_id']   ?? 0 ),
			'price'          => (float)                       ( $row['price']          ?? 0 ),
			'currency'       => sanitize_key(        (string) ( $row['currency']       ?? 'USD' ) ),
			'duration_hours' => (float)                       ( $row['duration_hours'] ?? 0 ),
			'level'          => sanitize_key(        (string) ( $row['level']          ?? 'beginner' ) ),
			'language'       => sanitize_key(        (string) ( $row['language']       ?? 'es' ) ),
			'thumbnail_url'  => esc_url_raw(         (string) ( $row['thumbnail_url']  ?? '' ) ),
			'passing_grade'  => absint(                        $row['passing_grade']   ?? 70 ),
			'settings_json'  => json_decode( (string) ( $row['settings_json'] ?? '{}' ), true ) ?: array(),
			'meta_json'      => json_decode( (string) ( $row['meta_json']     ?? '{}' ), true ) ?: array(),
			'published_at'   => sanitize_text_field( (string) ( $row['published_at']   ?? '' ) ),
			'created_at'     => sanitize_text_field( (string) ( $row['created_at']     ?? '' ) ),
		);
	}

	private static function format_lesson( array $row ): array {
		return array(
			'id'              => absint( $row['id'] ),
			'wp_post_id'      => absint( $row['wp_post_id'] ),
			'course_id'       => absint( $row['course_id'] ),
			'title'           => sanitize_text_field( (string) ( $row['title']        ?? '' ) ),
			'slug'            => sanitize_title(      (string) ( $row['slug']         ?? '' ) ),
			'lesson_order'    => absint(                        $row['lesson_order']  ?? 0 ),
			'section'         => sanitize_text_field( (string) ( $row['section']      ?? '' ) ),
			'section_order'   => absint(                        $row['section_order'] ?? 0 ),
			'type'            => sanitize_key(        (string) ( $row['type']         ?? 'text' ) ),
			'duration_min'    => absint(                        $row['duration_min']  ?? 0 ),
			'is_free_preview' => (bool) ( $row['is_free_preview'] ?? false ),
			'is_required'     => (bool) ( $row['is_required']     ?? true ),
			'video_url'       => esc_url_raw( (string) ( $row['video_url'] ?? '' ) ),
			'status'          => sanitize_key( (string) ( $row['status'] ?? 'published' ) ),
			'created_at'      => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
		);
	}

	private static function sanitize_course_data( array $d ): array {
		return array_filter( array(
			'wp_post_id'     => isset( $d['wp_post_id'] )     ? absint( $d['wp_post_id'] )                         : null,
			'title'          => isset( $d['title'] )          ? sanitize_text_field( (string) $d['title'] )        : null,
			'slug'           => isset( $d['slug'] )           ? sanitize_title( (string) $d['slug'] )              : null,
			'description'    => isset( $d['description'] )    ? wp_kses_post( (string) $d['description'] )         : null,
			'excerpt'        => isset( $d['excerpt'] )        ? sanitize_textarea_field( (string) $d['excerpt'] )  : null,
			'status'         => isset( $d['status'] )         ? sanitize_key( (string) $d['status'] )              : null,
			'type'           => isset( $d['type'] )           ? sanitize_key( (string) $d['type'] )                : null,
			'instructor_id'  => isset( $d['instructor_id'] )  ? absint( $d['instructor_id'] )                      : null,
			'price'          => isset( $d['price'] )          ? (float) $d['price']                                : null,
			'currency'       => isset( $d['currency'] )       ? sanitize_key( (string) $d['currency'] )            : null,
			'duration_hours' => isset( $d['duration_hours'] ) ? (float) $d['duration_hours']                       : null,
			'level'          => isset( $d['level'] )          ? sanitize_key( (string) $d['level'] )               : null,
			'language'       => isset( $d['language'] )       ? sanitize_key( (string) $d['language'] )            : null,
			'thumbnail_url'  => isset( $d['thumbnail_url'] )  ? esc_url_raw( (string) $d['thumbnail_url'] )        : null,
			'passing_grade'  => isset( $d['passing_grade'] )  ? absint( $d['passing_grade'] )                      : null,
			'settings_json'  => isset( $d['settings_json'] )  ? wp_json_encode( $d['settings_json'] )              : null,
			'meta_json'      => isset( $d['meta_json'] )      ? wp_json_encode( $d['meta_json'] )                  : null,
			'published_at'   => isset( $d['published_at'] )   ? sanitize_text_field( (string) $d['published_at'] ) : null,
		), fn( $v ) => $v !== null );
	}

	private static function sanitize_lesson_data( array $d ): array {
		return array_filter( array(
			'wp_post_id'      => isset( $d['wp_post_id'] )     ? absint( $d['wp_post_id'] )                       : null,
			'course_id'       => isset( $d['course_id'] )      ? absint( $d['course_id'] )                        : null,
			'title'           => isset( $d['title'] )          ? sanitize_text_field( (string) $d['title'] )      : null,
			'slug'            => isset( $d['slug'] )           ? sanitize_title( (string) $d['slug'] )            : null,
			'content'         => isset( $d['content'] )        ? wp_kses_post( (string) $d['content'] )           : null,
			'lesson_order'    => isset( $d['lesson_order'] )   ? absint( $d['lesson_order'] )                     : null,
			'section'         => isset( $d['section'] )        ? sanitize_text_field( (string) $d['section'] )   : null,
			'section_order'   => isset( $d['section_order'] )  ? absint( $d['section_order'] )                    : null,
			'type'            => isset( $d['type'] )           ? sanitize_key( (string) $d['type'] )              : null,
			'duration_min'    => isset( $d['duration_min'] )   ? absint( $d['duration_min'] )                     : null,
			'is_free_preview' => isset( $d['is_free_preview'] )? (int) (bool) $d['is_free_preview']               : null,
			'is_required'     => isset( $d['is_required'] )    ? (int) (bool) $d['is_required']                   : null,
			'video_url'       => isset( $d['video_url'] )      ? esc_url_raw( (string) $d['video_url'] )          : null,
			'status'          => isset( $d['status'] )         ? sanitize_key( (string) $d['status'] )            : null,
		), fn( $v ) => $v !== null );
	}
}
