<?php
/**
 * Editor de WordPress → tablas `atora_courses` / `atora_lessons` (6.27.4).
 *
 * Hasta 6.27.3 el editor solo escribía el post y sus metas: un curso ya migrado
 * no se actualizaba en la tabla, las lecciones nuevas no entraban al currículo
 * y `revision` no subía. Ahora, después de cada guardado (incluidas las
 * metaboxes), la fila se inserta o actualiza con las mismas funciones del
 * migrador y `revision` sube una vez si algo cambió. Despublicar, papelera y
 * borrado también se reflejan.
 *
 * @package ATORA_LMS\LMS
 * @since   6.27.4
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LMS_Editor_Sync {
	const COURSE_TYPE   = 'lm_course';
	const LESSON_TYPE   = 'lm_lesson';
	const DELETED       = 'deleted';
	const REPAIR_OPTION = 'atora_lms_editor_sync_repaired';
	const REPAIR_HOOK   = 'atora_lms_editor_sync_repair';

	/** Estados de post que el recorrido de reparación revisa. */
	const POST_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );

	/** Columnas de curso que sigue el editor (instructor, precio, etc. quedan fuera: los gestiona su propio flujo). */
	const COURSE_FIELDS = array( 'title', 'slug', 'description', 'excerpt', 'status', 'thumbnail_url', 'published_at' );

	const LESSON_FIELDS = array( 'course_id', 'title', 'slug', 'content', 'lesson_order', 'section', 'section_order', 'type', 'duration_min', 'is_free_preview', 'video_url', 'status' );

	public static function init(): void {
		// Prioridad tardía: wp_after_insert_post corre después de save_post (metaboxes).
		add_action( 'wp_after_insert_post', array( __CLASS__, 'on_post_saved' ), 99, 2 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_post_deleted' ), 10, 1 );
		add_action( self::REPAIR_HOOK, array( __CLASS__, 'run_scheduled_repair' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_repair' ), 20 );
	}

	// ── Ganchos ──────────────────────────────────────────────────────────────

	public static function on_post_saved( $post_id, $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( absint( $post_id ) );
		if ( ! $post || 'auto-draft' === $post->post_status || wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		if ( self::COURSE_TYPE === $post->post_type ) {
			self::sync_course( $post );
		} elseif ( self::LESSON_TYPE === $post->post_type ) {
			self::sync_lesson( $post );
		}
	}

	public static function on_post_deleted( $post_id ): void {
		$post_id = absint( $post_id );
		$type    = get_post_type( $post_id );
		if ( self::COURSE_TYPE === $type ) {
			self::mark_deleted( 'atora_courses', $post_id );
		} elseif ( self::LESSON_TYPE === $type ) {
			self::mark_deleted( 'atora_lessons', $post_id );
		}
	}

	// ── Sincronización ───────────────────────────────────────────────────────

	/** @return string inserted|updated|unchanged|skipped */
	public static function sync_course( object $post, bool $write = true ): string {
		if ( ! class_exists( __NAMESPACE__ . '\\LMS_Migrator' ) ) {
			return 'skipped';
		}
		$row = self::course_row( absint( $post->ID ) );
		if ( ! $row ) {
			// Mismos estados que migra el migrador.
			if ( ! in_array( $post->post_status, array( 'publish', 'draft', 'private' ), true ) ) {
				return 'skipped';
			}
			if ( $write && ! LMS_Migrator::migrate_course_post( $post ) ) {
				return 'skipped';
			}
			return 'inserted';
		}

		$changes = self::changes(
			LMS_Course_Service::normalize_course_fields( LMS_Migrator::course_row_from_post( $post ) ),
			$row,
			self::COURSE_FIELDS
		);
		if ( empty( $changes ) ) {
			return 'unchanged';
		}
		if ( $write ) {
			LMS_Course_Service::update( absint( $row['id'] ), $changes );
		}
		return 'updated';
	}

	/** @return string inserted|updated|unchanged|skipped */
	public static function sync_lesson( object $post, bool $write = true ): string {
		if ( ! class_exists( __NAMESPACE__ . '\\LMS_Migrator' ) ) {
			return 'skipped';
		}
		$wp_course_id = absint( get_post_meta( $post->ID, '_clms_course_id', true ) );
		$course       = $wp_course_id ? self::course_row( $wp_course_id ) : null;
		if ( ! $course ) {
			// El curso aún no tiene fila: al insertarlo entran también sus lecciones.
			$course_post = $wp_course_id ? get_post( $wp_course_id ) : null;
			if ( ! $course_post || self::COURSE_TYPE !== $course_post->post_type || ! $write ) {
				return 'skipped';
			}
			if ( 'inserted' !== self::sync_course( $course_post ) ) {
				return 'skipped';
			}
			$course = self::course_row( $wp_course_id );
			if ( ! $course ) {
				return 'skipped';
			}
		}
		$atora_course_id = absint( $course['id'] );
		$row             = self::lesson_row( absint( $post->ID ) );

		if ( ! $row ) {
			// Mismos estados que migra el migrador; una lección en la papelera no entra.
			if ( ! in_array( $post->post_status, array( 'publish', 'draft' ), true ) ) {
				return 'skipped';
			}
			$order = absint( $post->menu_order ) ?: self::next_lesson_order( $atora_course_id );
			if ( $write && ! LMS_Course_Service::upsert_lesson( LMS_Migrator::lesson_row_from_post( $post, $atora_course_id, $order ) ) ) {
				return 'skipped';
			}
			return 'inserted';
		}

		// Sin orden en el editor (menu_order = 0) se conserva el de la tabla.
		$order   = absint( $post->menu_order ) ?: absint( $row['lesson_order'] );
		$changes = self::changes(
			LMS_Course_Service::normalize_lesson_fields( LMS_Migrator::lesson_row_from_post( $post, $atora_course_id, $order ) ),
			$row,
			self::LESSON_FIELDS
		);
		if ( empty( $changes ) ) {
			return 'unchanged';
		}
		if ( $write ) {
			LMS_Course_Service::update_lesson( absint( $row['id'] ), $changes );
		}
		return 'updated';
	}

	// ── Reparación ───────────────────────────────────────────────────────────

	/**
	 * Alinea la tabla con los posts. Repetible: lo que ya está bien no se toca.
	 *
	 * @return array<string,int>
	 */
	public static function repair_all( bool $write = true ): array {
		global $wpdb;
		$result = array(
			'courses_inserted' => 0,
			'courses_updated'  => 0,
			'lessons_inserted' => 0,
			'lessons_updated'  => 0,
			'rows_deleted'     => 0,
		);

		foreach ( array( self::COURSE_TYPE => 'courses', self::LESSON_TYPE => 'lessons' ) as $type => $label ) {
			$ids = get_posts( array(
				'post_type'        => $type,
				'post_status'      => self::POST_STATUSES,
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			) );
			foreach ( (array) $ids as $id ) {
				$post = get_post( absint( $id ) );
				if ( ! $post ) {
					continue;
				}
				$status = self::COURSE_TYPE === $type ? self::sync_course( $post, $write ) : self::sync_lesson( $post, $write );
				if ( 'inserted' === $status || 'updated' === $status ) {
					++$result[ $label . '_' . $status ];
				}
			}
		}

		// Filas cuyo post ya no existe.
		foreach ( array( 'atora_courses', 'atora_lessons' ) as $table ) {
			$orphans = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT t.wp_post_id FROM {$wpdb->prefix}{$table} t
				 LEFT JOIN {$wpdb->posts} p ON p.ID = t.wp_post_id
				 WHERE t.wp_post_id > 0 AND p.ID IS NULL AND t.status <> '" . self::DELETED . "'"
			);
			foreach ( $orphans as $wp_post_id ) {
				if ( $write ) {
					self::mark_deleted( $table, absint( $wp_post_id ) );
				}
				++$result['rows_deleted'];
			}
		}

		return $result;
	}

	/** Una sola vez tras actualizar: se programa en segundo plano para no cargar la petición. */
	public static function maybe_schedule_repair(): void {
		if ( get_option( self::REPAIR_OPTION ) || wp_next_scheduled( self::REPAIR_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + 30, self::REPAIR_HOOK );
	}

	public static function run_scheduled_repair(): void {
		if ( get_option( self::REPAIR_OPTION ) ) {
			return;
		}
		$result = self::repair_all();
		update_option( self::REPAIR_OPTION, array( 'version' => defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '' ) + $result, false );
	}

	// ── Auxiliares ───────────────────────────────────────────────────────────

	private static function course_row( int $wp_post_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_courses WHERE wp_post_id = %d LIMIT 1", $wp_post_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	private static function lesson_row( int $wp_post_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_lessons WHERE wp_post_id = %d LIMIT 1", $wp_post_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	private static function next_lesson_order( int $atora_course_id ): int {
		global $wpdb;
		return 1 + (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT MAX(lesson_order) FROM {$wpdb->prefix}atora_lessons WHERE course_id = %d", $atora_course_id )
		);
	}

	/** Campos deseados que difieren de la fila (comparados como texto, tal como los guarda MySQL). */
	private static function changes( array $desired, array $row, array $fields ): array {
		$changes = array();
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $desired ) ) {
				continue;
			}
			$value = is_bool( $desired[ $field ] ) ? (int) $desired[ $field ] : $desired[ $field ];
			if ( (string) $value !== (string) ( $row[ $field ] ?? '' ) ) {
				$changes[ $field ] = $desired[ $field ];
			}
		}
		return $changes;
	}

	private static function mark_deleted( string $table, int $wp_post_id ): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}{$table} SET status = %s, revision = revision + 1 WHERE wp_post_id = %d AND status <> %s",
				self::DELETED,
				$wp_post_id,
				self::DELETED
			)
		);
	}
}
