<?php
/**
 * LMS_Migrator — Migra cursos y lecciones de CPTs (wp_posts) a tablas propias (Fase 11)
 *
 * Ejecución:
 *   LMS_Migrator::migrate_courses(100, 0);        → batch de 100 cursos
 *   LMS_Migrator::migrate_lessons_for_course(id); → lecciones de un curso
 *   LMS_Migrator::migrate_enrollments(100, 0);    → matrículas desde usermeta
 *
 * La migración es ADITIVA y NO DESTRUCTIVA.
 * Los CPTs permanecen activos. wp_post_id permite reconocer registros ya migrados.
 *
 * @package ATORA_LMS\LMS
 * @since   5.29.0
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LMS_Migrator {

	// ── Punto de entrada ─────────────────────────────────────────────────────

	/** Option donde se persisten los offsets de continuación entre llamadas. */
	private const CURSORS_OPTION = 'atora_lms_migration_cursors';

	/**
	 * Punto de entrada para cron y botón admin. Ejecuta un lote de cada
	 * migrador F1 y persiste cursores de continuación en wp_options, de modo
	 * que llamadas sucesivas (tick de cron, click de admin) avancen por todo
	 * el histórico en vez de repetir siempre la primera página.
	 *
	 * Los migradores NOT EXISTS (courses, programs, quizzes, submissions) no
	 * necesitan cursor: solo seleccionan pendientes. Los paginados (course_terms,
	 * enrollments, lesson_progress, program_enrollments, gradebook, certificates)
	 * recorren la tabla completa y por eso necesitan un offset persistido;
	 * al completar una pasada (processed < limit) el cursor vuelve a 0.
	 */
	public static function migrate_all( int $batch = 50 ): array {
		$cursors = self::get_migration_cursors();

		// Cursos y lecciones (NOT EXISTS, sin cursor)
		$courses_result = self::migrate_courses( $batch );

		// Taxonomías de curso D-003=B (paginado)
		$terms_limit  = $batch * 4;
		$terms_result = self::migrate_course_terms( $terms_limit, $cursors['course_terms'] );
		$cursors['course_terms'] = self::advance_cursor( $cursors['course_terms'], $terms_limit, (int) $terms_result['processed'] );

		// Programas D-001=A (NOT EXISTS, sin cursor)
		$programs_result = self::migrate_programs( $batch );

		// Matrículas a curso (paginado)
		$enroll_limit  = $batch * 10;
		$enroll_result = self::migrate_enrollments( $enroll_limit, $cursors['enrollments'] );
		$cursors['enrollments'] = self::advance_cursor( $cursors['enrollments'], $enroll_limit, (int) $enroll_result['users_processed'] );

		// Progreso de lecciones (paginado)
		$progress_limit  = $batch * 10;
		$progress_result = self::migrate_lesson_progress( $progress_limit, $cursors['lesson_progress'] );
		$cursors['lesson_progress'] = self::advance_cursor( $cursors['lesson_progress'], $progress_limit, (int) $progress_result['users_processed'] );

		// Matrículas a programa D-001=A (paginado)
		$prog_enroll_limit  = $batch * 10;
		$prog_enroll_result = self::migrate_program_enrollments( $prog_enroll_limit, $cursors['program_enrollments'] );
		$cursors['program_enrollments'] = self::advance_cursor( $cursors['program_enrollments'], $prog_enroll_limit, (int) $prog_enroll_result['users_processed'] );

		// Quizzes D-002 (NOT EXISTS, sin cursor)
		$quizzes_result = self::migrate_quizzes( $batch * 2 );

		// Submissions D-002 (NOT EXISTS, sin cursor)
		$submissions_result = self::migrate_quiz_submissions( $batch * 2 );

		// Gradebook D-002 (paginado)
		$gradebook_limit  = $batch * 10;
		$gradebook_result = self::migrate_gradebook( $gradebook_limit, $cursors['gradebook'] );
		$cursors['gradebook'] = self::advance_cursor( $cursors['gradebook'], $gradebook_limit, (int) $gradebook_result['processed'] );

		// Certificados D-002 (paginado)
		$certs_limit  = $batch * 10;
		$certs_result = self::migrate_certificates( $certs_limit, $cursors['certificates'] );
		$cursors['certificates'] = self::advance_cursor( $cursors['certificates'], $certs_limit, (int) $certs_result['processed'] );

		self::save_migration_cursors( $cursors );

		$results = array(
			'courses'             => $courses_result,
			'course_terms'        => $terms_result,
			'programs'            => $programs_result,
			'enrollments'         => $enroll_result,
			'lesson_progress'     => $progress_result,
			'program_enrollments' => $prog_enroll_result,
			'quizzes'             => $quizzes_result,
			'quiz_submissions'    => $submissions_result,
			'gradebook'           => $gradebook_result,
			'certificates'        => $certs_result,
			'cursors'             => $cursors,
		);
		do_action( 'atora/lms/migration_complete', $results );
		return $results;
	}

	/**
	 * Lee los offsets de continuación persistidos (0 si nunca se corrió).
	 *
	 * @return array{course_terms:int,enrollments:int,lesson_progress:int,program_enrollments:int,gradebook:int,certificates:int}
	 */
	private static function get_migration_cursors(): array {
		$defaults = array(
			'course_terms'        => 0,
			'enrollments'         => 0,
			'lesson_progress'     => 0,
			'program_enrollments' => 0,
			'gradebook'           => 0,
			'certificates'        => 0,
		);
		$stored = get_option( self::CURSORS_OPTION, array() );
		return array_merge( $defaults, is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Persiste los offsets de continuación (autoload=no: no se necesitan en
	 * cada carga de página, solo durante migraciones).
	 */
	private static function save_migration_cursors( array $cursors ): void {
		update_option( self::CURSORS_OPTION, $cursors, false );
	}

	/**
	 * Avanza el cursor de un migrador paginado: si la página devuelta vino
	 * incompleta (se procesaron menos registros que el límite solicitado) se
	 * llegó al final de la tabla → vuelve a 0 para recoger altas nuevas en la
	 * siguiente pasada. Si vino completa, avanza por lo realmente procesado.
	 */
	private static function advance_cursor( int $current, int $limit, int $processed ): int {
		return $processed < $limit ? 0 : $current + $processed;
	}

	// ── Cursos ───────────────────────────────────────────────────────────────

	/**
	 * Migra cursos CPT aún no presentes en atora_courses (NOT EXISTS).
	 * No usa offset fijo — siempre avanza hacia los pendientes reales.
	 */
	public static function migrate_courses( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		// Seleccionar CPTs cuyo wp_post_id NO existe aún en atora_courses
		$pending_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->prefix}atora_courses c ON c.wp_post_id = p.ID
				 WHERE p.post_type    = 'lm_course'
				   AND p.post_status IN ('publish','draft','private')
				   AND c.id IS NULL
				 ORDER BY p.ID ASC
				 LIMIT %d",
				$limit
			)
		);

		$migrated = $skipped = $errors = 0;

		foreach ( (array) $pending_ids as $post_id ) {
			$post_id = absint( $post_id );
			// Double-check (race condition guard)
			$existing_row = $wpdb->get_row(
				$wpdb->prepare( "SELECT id, instructor_id FROM {$wpdb->prefix}atora_courses WHERE wp_post_id = %d LIMIT 1", $post_id ),
				ARRAY_A
			);
			if ( $existing_row ) {
				// PT-3.1 (6.5.3): no confiar ciegamente en que "la fila
				// ya existe" significa "ya migrado correctamente" — esa
				// fila pudo haberse creado por REST durante la ventana
				// de cutover, antes de que el migrador llegara a este
				// CPT (el escenario que ordena este sprint). Se sigue
				// saltando (no sobreescribir automáticamente: podría
				// ser una reasignación intencional legítima), pero se
				// registra la discrepancia si el instructor_id no
				// coincide con el autor/instructor real del CPT.
				self::log_migration_discrepancy_if_instructor_mismatch( $post_id, absint( $existing_row['instructor_id'] ?? 0 ) );
				$skipped++;
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post ) { $errors++; continue; }

			$instructor_ids = get_post_meta( $post_id, '_clms_instructor_ids', true );
			$instructor_id  = is_array( $instructor_ids ) && ! empty( $instructor_ids )
				? absint( $instructor_ids[0] )
				: absint( $post->post_author );

			$price    = (float) get_post_meta( $post_id, '_price', true );
			$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';

			// D-003=B: nivel de dificultad del primer término de lm_course_level;
			// los términos de todas las taxonomías irán a atora_course_terms vía
			// migrate_course_terms() (ya no se escriben en meta_json.categories).
			$level_terms = self::resolve_course_categories( $post_id );

			$data = array(
				'wp_post_id'    => $post_id,
				'title'         => $post->post_title,
				'slug'          => $post->post_name,
				'description'   => $post->post_content,
				'excerpt'       => $post->post_excerpt,
				'status'        => 'publish' === $post->post_status ? 'published' : $post->post_status,
				'instructor_id' => $instructor_id,
				'price'         => $price,
				'currency'      => sanitize_key( $currency ),
				'thumbnail_url' => get_the_post_thumbnail_url( $post_id, 'large' ) ?: '',
				'passing_grade' => absint( get_post_meta( $post_id, '_clms_passing_grade', true ) ?: 70 ),
				'published_at'  => 'publish' === $post->post_status ? $post->post_date_gmt : null,
				'level'         => $level_terms[0] ?? 'beginner',
			);

			$id = LMS_Course_Service::create( $data );
			if ( $id ) {
				$migrated++;
				self::migrate_lessons_for_course( $post_id, $id );
				self::upsert_course_terms( $post_id, $id ); // D-003=B: tabla pivote
			} else {
				$errors++;
			}
		}

		return array(
			'migrated' => $migrated,
			'skipped'  => $skipped,
			'errors'   => $errors,
			'total'    => count( $pending_ids ),
		);
	}

	/**
	 * PT-3.1 (6.5.3): compara el instructor_id de una fila ya presente
	 * en atora_courses contra el autor/instructor real del CPT
	 * lm_course correspondiente. Si difieren, registra la divergencia
	 * en atora_lms_parity_log (misma tabla que ya usa LMS_Parity para
	 * "reportar divergencia, no asumir") — no sobreescribe nada, es
	 * solo visibilidad para el administrador.
	 *
	 * @param int $post_id       ID del CPT lm_course.
	 * @param int $row_instructor_id instructor_id de la fila ya existente en atora_courses.
	 * @return void
	 */
	private static function log_migration_discrepancy_if_instructor_mismatch( int $post_id, int $row_instructor_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) { return; }

		$instructor_ids  = get_post_meta( $post_id, '_clms_instructor_ids', true );
		$real_instructor  = is_array( $instructor_ids ) && ! empty( $instructor_ids )
			? absint( $instructor_ids[0] )
			: absint( $post->post_author );

		if ( $real_instructor === $row_instructor_id ) {
			return;
		}

		if ( ! class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . \ATORA\LMS\LMS_Parity::TABLE_SUFFIX;
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'reader'         => 'migrator_instructor_mismatch',
				'user_id'        => 0,
				'wp_course_id'   => $post_id,
				'legacy_digest'  => substr( md5( (string) $real_instructor ), 0, 8 ),
				'table_digest'   => substr( md5( (string) $row_instructor_id ), 0, 8 ),
				'legacy_summary' => sprintf( 'CPT instructor_id=%d', $real_instructor ),
				'table_summary'  => sprintf( 'atora_courses.instructor_id=%d (fila ya existente al migrar)', $row_instructor_id ),
				'logged_at'      => current_time( 'mysql', true ),
			)
		);
	}

	// ── Lecciones ────────────────────────────────────────────────────────────

	public static function migrate_lessons_for_course( int $wp_course_id, int $atora_course_id ): int {
		$lessons = get_posts( array(
			'post_type'      => 'lm_lesson',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 500,
			'meta_key'       => '_clms_course_id',
			'meta_value'     => $wp_course_id,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'fields'         => 'ids',
		) );

		$count = 0;
		foreach ( $lessons as $i => $lesson_id ) {
			$lesson_id = absint( $lesson_id );
			$post      = get_post( $lesson_id );
			if ( ! $post ) { continue; }

			$section = sanitize_text_field( (string) get_post_meta( $lesson_id, '_clms_section', true ) );
			$type    = sanitize_key( (string) ( get_post_meta( $lesson_id, '_clms_lesson_type', true ) ?: 'text' ) );
			$video   = esc_url_raw( (string) get_post_meta( $lesson_id, '_clms_video_url', true ) );

			$data = array(
				'wp_post_id'      => $lesson_id,
				'course_id'       => $atora_course_id,
				'title'           => $post->post_title,
				'slug'            => $post->post_name,
				'content'         => $post->post_content,
				'lesson_order'    => absint( $post->menu_order ) ?: ( $i + 1 ),
				'section'         => $section,
				'section_order'   => absint( get_post_meta( $lesson_id, '_clms_section_order', true ) ?: 0 ),
				'type'            => $type,
				'duration_min'    => absint( get_post_meta( $lesson_id, '_clms_duration', true ) ),
				'is_free_preview' => (bool) get_post_meta( $lesson_id, '_clms_free_preview', true ),
				'video_url'       => $video,
				'status'          => 'publish' === $post->post_status ? 'published' : 'draft',
			);

			if ( LMS_Course_Service::upsert_lesson( $data ) ) {
				$count++;
			}
		}

		return $count;
	}

	// ── Taxonomías de curso / atora_course_terms (D-003 = B) ────────────────

	/**
	 * Slugs de la taxonomía `lm_course_level` para la columna `level`
	 * (dificultad del curso). Solo lee esta taxonomía específica.
	 *
	 * @return string[]
	 */
	private static function resolve_course_categories( int $wp_post_id ): array {
		if ( ! taxonomy_exists( 'lm_course_level' ) ) { return array(); }
		$terms = wp_get_post_terms( $wp_post_id, 'lm_course_level', array( 'fields' => 'slugs' ) );
		return is_array( $terms ) ? array_values( array_filter( array_map( 'sanitize_key', $terms ) ) ) : array();
	}

	/**
	 * Escribe los términos de TODAS las taxonomías registradas en `lm_course`
	 * para un curso dado en `atora_course_terms` (D-003 = B).
	 * Idempotente: ON DUPLICATE KEY UPDATE term_name.
	 *
	 * @return int Número de pares (taxonomy, term_slug) insertados/actualizados.
	 */
	private static function upsert_course_terms( int $wp_post_id, int $atora_course_id ): int {
		global $wpdb;

		$taxonomies = array_values( array_filter( (array) get_object_taxonomies( 'lm_course', 'names' ) ) );
		if ( empty( $taxonomies ) ) { return 0; }

		$count = 0;
		$table = $wpdb->prefix . 'atora_course_terms';

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( $wp_post_id, $taxonomy, array( 'fields' => 'all' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) { continue; }

			foreach ( $terms as $term ) {
				$slug = sanitize_key( (string) $term->slug );
				$name = sanitize_text_field( (string) $term->name );
				if ( '' === $slug ) { continue; }

				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"INSERT INTO {$table} (course_id, taxonomy, term_slug, term_name)
						 VALUES (%d, %s, %s, %s)
						 ON DUPLICATE KEY UPDATE term_name = VALUES(term_name)",
						$atora_course_id, $taxonomy, $slug, $name
					)
				);
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Backfill de taxonomías para cursos ya migrados (D-003 = B).
	 * Paginado con cursor sobre atora_courses; idempotente por UNIQUE KEY.
	 */
	public static function migrate_course_terms( int $limit = 200, int $offset = 0 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, wp_post_id FROM {$wpdb->prefix}atora_courses
				 WHERE wp_post_id > 0 ORDER BY id ASC LIMIT %d OFFSET %d",
				$limit, $offset
			),
			ARRAY_A
		);

		$upserted = $skipped = 0;

		foreach ( $rows as $row ) {
			$inserted = self::upsert_course_terms( (int) $row['wp_post_id'], (int) $row['id'] );
			if ( $inserted > 0 ) { $upserted++; } else { $skipped++; }
		}

		return array(
			'processed' => count( $rows ),
			'upserted'  => $upserted,
			'skipped'   => $skipped,
		);
	}

	// ── Programas (D-001 = A) ─────────────────────────────────────────────────

	/**
	 * Migra programas CPT (lm_program) no presentes aún en atora_programs.
	 * Patrón NOT EXISTS: no necesita cursor, igual que migrate_courses().
	 */
	public static function migrate_programs( int $limit = 50 ): array {
		global $wpdb;

		$pending_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->prefix}atora_programs pg ON pg.wp_post_id = p.ID
				 WHERE p.post_type   = 'lm_program'
				   AND p.post_status IN ('publish','draft','private')
				   AND pg.id IS NULL
				 ORDER BY p.ID ASC
				 LIMIT %d",
				$limit
			)
		);

		$migrated = $skipped = $errors = 0;

		foreach ( (array) $pending_ids as $post_id ) {
			$post_id = absint( $post_id );

			$exists = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_programs WHERE wp_post_id = %d LIMIT 1", $post_id )
			);
			if ( $exists ) { $skipped++; continue; }

			$post = get_post( $post_id );
			if ( ! $post ) { $errors++; continue; }

			$instructor_ids = get_post_meta( $post_id, '_clms_program_teacher_ids', true );
			$instructor_id  = is_array( $instructor_ids ) && ! empty( $instructor_ids )
				? absint( $instructor_ids[0] )
				: absint( $post->post_author );

			$price    = (float) get_post_meta( $post_id, '_clms_program_price', true );
			$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';

			$row = array(
				'wp_post_id'    => $post_id,
				'title'         => $post->post_title,
				'slug'          => $post->post_name,
				'description'   => $post->post_content,
				'excerpt'       => $post->post_excerpt,
				'status'        => 'publish' === $post->post_status ? 'published' : $post->post_status,
				'instructor_id' => $instructor_id,
				'price'         => $price,
				'currency'      => sanitize_key( $currency ),
				'thumbnail_url' => get_the_post_thumbnail_url( $post_id, 'large' ) ?: '',
				'published_at'  => 'publish' === $post->post_status ? $post->post_date_gmt : null,
				'meta_json'     => wp_json_encode( array(
					'courses' => array_map( 'absint', (array) get_post_meta( $post_id, '_clms_program_courses', true ) ),
				) ),
			);

			$ok = $wpdb->insert( $wpdb->prefix . 'atora_programs', $row );
			$ok ? $migrated++ : $errors++;
		}

		return array(
			'migrated' => $migrated,
			'skipped'  => $skipped,
			'errors'   => $errors,
			'total'    => count( $pending_ids ),
		);
	}

	/**
	 * Migra matrículas a programa desde _clms_enrolled_programs (usermeta).
	 * Paginado con cursor.
	 */
	public static function migrate_program_enrollments( int $limit = 200, int $offset = 0 ): array {
		global $wpdb;

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta}
				 WHERE meta_key = '_clms_enrolled_programs'
				 LIMIT %d OFFSET %d",
				$limit, $offset
			)
		);

		$migrated = $skipped = $errors = 0;

		foreach ( $user_ids as $user_id ) {
			$user_id      = absint( $user_id );
			$programs_raw = get_user_meta( $user_id, '_clms_enrolled_programs', true );
			$programs     = is_array( $programs_raw ) ? $programs_raw : array();

			foreach ( $programs as $wp_program_id ) {
				$wp_program_id = absint( $wp_program_id );
				if ( ! $wp_program_id ) { $skipped++; continue; }

				$atora_program_id = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_programs WHERE wp_post_id = %d LIMIT 1", $wp_program_id )
				);
				if ( ! $atora_program_id ) { $skipped++; continue; }

				$exists = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}atora_program_enrollments WHERE user_id = %d AND program_id = %d LIMIT 1",
						$user_id, $atora_program_id
					)
				);
				if ( $exists ) { $skipped++; continue; }

				list( $enrolled_at ) = self::resolve_enrollment_date( $user_id, $wp_program_id );

				$expiry_map = get_user_meta( $user_id, '_clms_program_access_expiry', true );
				$expires_at = ( is_array( $expiry_map ) && ! empty( $expiry_map[ $wp_program_id ] ) )
					? sanitize_text_field( (string) $expiry_map[ $wp_program_id ] )
					: null;

				$row = array(
					'user_id'       => $user_id,
					'program_id'    => $atora_program_id,
					'wp_program_id' => $wp_program_id,
					'status'        => 'active',
					'enrolled_at'   => $enrolled_at,
					'expires_at'    => $expires_at,
				);

				$ok = $wpdb->insert( $wpdb->prefix . 'atora_program_enrollments', $row );
				$ok ? $migrated++ : $errors++;
			}
		}

		return array(
			'users_processed' => count( $user_ids ),
			'migrated'        => $migrated,
			'skipped'         => $skipped,
			'errors'          => $errors,
		);
	}

	// ── Quizzes (D-002) ──────────────────────────────────────────────────────

	/**
	 * Migra definiciones de quiz (postmeta _clms_quiz_questions en lm_lesson)
	 * a atora_quizzes. Patrón NOT EXISTS.
	 */
	public static function migrate_quizzes( int $limit = 100 ): array {
		global $wpdb;

		$pending_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT l.id
				 FROM {$wpdb->prefix}atora_lessons l
				 LEFT JOIN {$wpdb->prefix}atora_quizzes q ON q.lesson_id = l.id
				 INNER JOIN {$wpdb->postmeta} pm
				   ON pm.post_id = l.wp_post_id AND pm.meta_key = '_clms_quiz_enabled' AND pm.meta_value = '1'
				 WHERE l.wp_post_id > 0 AND q.id IS NULL
				 ORDER BY l.id ASC
				 LIMIT %d",
				$limit
			)
		);

		if ( empty( $pending_ids ) ) {
			return array( 'migrated' => 0, 'skipped' => 0, 'errors' => 0, 'total' => 0 );
		}

		$in   = implode( ',', array_fill( 0, count( $pending_ids ), '%d' ) );
		$info = array();
		foreach ( (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT id, wp_post_id, course_id FROM {$wpdb->prefix}atora_lessons WHERE id IN ({$in})", ...$pending_ids ),
			ARRAY_A
		) as $row ) {
			$info[ (int) $row['id'] ] = array( 'wp_post_id' => (int) $row['wp_post_id'], 'course_id' => (int) $row['course_id'] );
		}

		$migrated = $skipped = $errors = 0;

		foreach ( (array) $pending_ids as $atora_lesson_id ) {
			$atora_lesson_id = (int) $atora_lesson_id;
			$lesson          = $info[ $atora_lesson_id ] ?? null;
			if ( ! $lesson ) { $skipped++; continue; }

			$wp_lesson_id  = $lesson['wp_post_id'];
			$questions_raw = get_post_meta( $wp_lesson_id, '_clms_quiz_questions', true );
			if ( empty( $questions_raw ) ) { $skipped++; continue; }

			$exists = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_quizzes WHERE lesson_id = %d LIMIT 1", $atora_lesson_id )
			);
			if ( $exists ) { $skipped++; continue; }

			$ok = $wpdb->insert(
				$wpdb->prefix . 'atora_quizzes',
				array(
					'lesson_id'      => $atora_lesson_id,
					'wp_lesson_id'   => $wp_lesson_id,
					'course_id'      => $lesson['course_id'],
					'questions_json' => is_string( $questions_raw ) ? $questions_raw : wp_json_encode( $questions_raw ),
				)
			);
			$ok ? $migrated++ : $errors++;
		}

		return array( 'migrated' => $migrated, 'skipped' => $skipped, 'errors' => $errors, 'total' => count( $pending_ids ) );
	}

	/**
	 * Migra intentos de quiz (CPT clms_submission) a atora_quiz_submissions.
	 * Patrón NOT EXISTS.
	 */
	public static function migrate_quiz_submissions( int $limit = 100 ): array {
		global $wpdb;

		$pending_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->prefix}atora_quiz_submissions s ON s.wp_post_id = p.ID
				 WHERE p.post_type   = 'clms_submission'
				   AND p.post_status IN ('publish','draft','private','pending')
				   AND s.id IS NULL
				 ORDER BY p.ID ASC
				 LIMIT %d",
				$limit
			)
		);

		$migrated = $skipped = $errors = 0;

		foreach ( (array) $pending_ids as $post_id ) {
			$post_id = absint( $post_id );

			$exists = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_quiz_submissions WHERE wp_post_id = %d LIMIT 1", $post_id )
			);
			if ( $exists ) { $skipped++; continue; }

			$user_id      = absint(
				get_post_meta( $post_id, '_clms_submission_user_id', true )
				?: get_post_meta( $post_id, '_clms_submission_student_id', true )
			);
			$wp_lesson_id = absint( get_post_meta( $post_id, '_clms_submission_lesson_id', true ) );
			$wp_course_id = absint( get_post_meta( $post_id, '_clms_submission_course_id', true ) );
			$status       = sanitize_key( (string) ( get_post_meta( $post_id, '_clms_submission_status', true ) ?: 'pending' ) );
			$grade_raw    = get_post_meta( $post_id, '_clms_submission_grade', true );
			$grade        = '' !== (string) $grade_raw ? (float) $grade_raw : null;
			$feedback     = sanitize_textarea_field( (string) get_post_meta( $post_id, '_clms_submission_feedback', true ) ) ?: null;
			$rubric_raw   = get_post_meta( $post_id, '_clms_submission_rubric_scores', true );

			$atora_lesson_id = $wp_lesson_id
				? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_lessons WHERE wp_post_id = %d LIMIT 1", $wp_lesson_id ) )
				: 0;
			$atora_course_id = $wp_course_id
				? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_courses WHERE wp_post_id = %d LIMIT 1", $wp_course_id ) )
				: 0;
			$quiz_id         = $atora_lesson_id
				? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_quizzes WHERE lesson_id = %d LIMIT 1", $atora_lesson_id ) )
				: 0;

			$post = get_post( $post_id );

			$ok = $wpdb->insert(
				$wpdb->prefix . 'atora_quiz_submissions',
				array(
					'wp_post_id'   => $post_id,
					'user_id'      => $user_id,
					'quiz_id'      => $quiz_id,
					'lesson_id'    => $atora_lesson_id,
					'course_id'    => $atora_course_id,
					'wp_lesson_id' => $wp_lesson_id,
					'wp_course_id' => $wp_course_id,
					'status'       => $status,
					'grade'        => $grade,
					'feedback'     => $feedback,
					'rubric_json'  => is_array( $rubric_raw ) ? wp_json_encode( $rubric_raw ) : null,
					'submitted_at' => $post ? $post->post_date_gmt : null,
				)
			);
			$ok ? $migrated++ : $errors++;
		}

		return array( 'migrated' => $migrated, 'skipped' => $skipped, 'errors' => $errors, 'total' => count( $pending_ids ) );
	}

	// ── Gradebook (D-002) ─────────────────────────────────────────────────────

	/**
	 * Migra calificaciones finales (_clms_gradebook_course_{id} en usermeta)
	 * a atora_gradebook. Paginado con cursor.
	 */
	public static function migrate_gradebook( int $limit = 200, int $offset = 0 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_key, meta_value
				 FROM {$wpdb->usermeta}
				 WHERE meta_key LIKE %s
				 LIMIT %d OFFSET %d",
				$wpdb->esc_like( '_clms_gradebook_course_' ) . '%',
				$limit, $offset
			),
			ARRAY_A
		);

		$upserted = $skipped = $errors = 0;

		foreach ( $rows as $row ) {
			$user_id      = absint( $row['user_id'] );
			$wp_course_id = absint( str_replace( '_clms_gradebook_course_', '', (string) $row['meta_key'] ) );
			if ( ! $user_id || ! $wp_course_id ) { $skipped++; continue; }

			$atora_course_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_courses WHERE wp_post_id = %d LIMIT 1", $wp_course_id )
			);
			if ( ! $atora_course_id ) { $skipped++; continue; }

			$existing = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}atora_gradebook WHERE user_id = %d AND course_id = %d LIMIT 1",
					$user_id, $atora_course_id
				)
			);
			if ( $existing ) { $skipped++; continue; }

			$gradebook   = maybe_unserialize( $row['meta_value'] );
			$grade_json  = is_array( $gradebook ) ? wp_json_encode( $gradebook ) : null;
			$final_grade = null;
			if ( is_array( $gradebook ) ) {
				if ( isset( $gradebook['final_average'] ) ) {
					$final_grade = (float) $gradebook['final_average'];
				} elseif ( isset( $gradebook['final_grade'] ) ) {
					$final_grade = (float) $gradebook['final_grade'];
				}
			}

			$ok = $wpdb->insert(
				$wpdb->prefix . 'atora_gradebook',
				array(
					'user_id'      => $user_id,
					'course_id'    => $atora_course_id,
					'wp_course_id' => $wp_course_id,
					'final_grade'  => $final_grade,
					'grade_json'   => $grade_json,
				)
			);
			if ( $ok ) { $upserted++; } else { $errors++; }
		}

		return array(
			'processed' => count( $rows ),
			'upserted'  => $upserted,
			'skipped'   => $skipped,
			'errors'    => $errors,
		);
	}

	// ── Certificados (D-002) ──────────────────────────────────────────────────

	/**
	 * Migra certificados emitidos (usermeta) a atora_certificates.
	 * Lee tanto _clms_certificate_record_{wp_course_id} (cursos) como
	 * _clms_program_certificate_record_{wp_program_id} (programas).
	 * Paginado con cursor.
	 */
	public static function migrate_certificates( int $limit = 200, int $offset = 0 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_key, meta_value
				 FROM {$wpdb->usermeta}
				 WHERE meta_key LIKE %s OR meta_key LIKE %s
				 LIMIT %d OFFSET %d",
				$wpdb->esc_like( '_clms_certificate_record_' ) . '%',
				$wpdb->esc_like( '_clms_program_certificate_record_' ) . '%',
				$limit, $offset
			),
			ARRAY_A
		);

		$upserted = $skipped = $errors = 0;

		foreach ( $rows as $row ) {
			$user_id  = absint( $row['user_id'] );
			$meta_key = (string) $row['meta_key'];
			$record   = maybe_unserialize( $row['meta_value'] );
			if ( ! $user_id || ! is_array( $record ) || empty( $record['certificate_code'] ) ) {
				$skipped++;
				continue;
			}

			$is_program   = str_starts_with( $meta_key, '_clms_program_certificate_record_' );
			$target_type  = $is_program ? 'program' : 'course';
			$wp_target_id = absint( str_replace(
				$is_program ? '_clms_program_certificate_record_' : '_clms_certificate_record_',
				'',
				$meta_key
			) );
			if ( ! $wp_target_id ) { $skipped++; continue; }

			$existing = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}atora_certificates WHERE user_id = %d AND target_type = %s AND wp_target_id = %d LIMIT 1",
					$user_id, $target_type, $wp_target_id
				)
			);
			if ( $existing ) { $skipped++; continue; }

			$atora_course_id  = 0;
			$atora_program_id = 0;
			if ( $is_program ) {
				$atora_program_id = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_programs WHERE wp_post_id = %d LIMIT 1", $wp_target_id )
				);
			} else {
				$atora_course_id = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_courses WHERE wp_post_id = %d LIMIT 1", $wp_target_id )
				);
			}

			$cert_code  = sanitize_text_field( (string) $record['certificate_code'] );
			$ver_code   = sanitize_text_field( (string) ( $record['verification_code'] ?? '' ) );
			$ver_hash   = sanitize_text_field( (string) ( $record['verification_hash'] ?? '' ) );
			$status     = sanitize_key( (string) ( $record['status'] ?? 'valid' ) );
			$issued_at  = ! empty( $record['issued_at'] )  ? get_gmt_from_date( (string) $record['issued_at'] )  : null;
			$revoked_at = ! empty( $record['revoked_at'] ) ? get_gmt_from_date( (string) $record['revoked_at'] ) : null;
			$final_grade = isset( $record['final_average'] )
				? (float) $record['final_average']
				: ( isset( $record['final_grade'] ) ? (float) $record['final_grade'] : null );
			$progress_pct = absint( $record['progress_percent'] ?? 0 );

			// Resto de campos como JSON extendido
			$meta_json = wp_json_encode( array_diff_key( $record, array_flip( array(
				'certificate_code', 'verification_code', 'verification_hash',
				'issued_at', 'issue_date', 'status', 'final_average', 'progress_percent', 'revoked_at',
			) ) ) );

			$ok = $wpdb->insert(
				$wpdb->prefix . 'atora_certificates',
				array(
					'user_id'           => $user_id,
					'course_id'         => $atora_course_id,
					'program_id'        => $atora_program_id,
					'wp_target_id'      => $wp_target_id,
					'target_type'       => $target_type,
					'cert_code'         => $cert_code,
					'verification_code' => $ver_code,
					'verification_hash' => $ver_hash,
					'status'            => $status,
					'final_grade'       => $final_grade,
					'progress_pct'      => $progress_pct,
					'issued_at'         => $issued_at,
					'revoked_at'        => $revoked_at,
					'meta_json'         => $meta_json,
				)
			);
			if ( $ok ) { $upserted++; } else { $errors++; }
		}

		return array(
			'processed' => count( $rows ),
			'upserted'  => $upserted,
			'skipped'   => $skipped,
			'errors'    => $errors,
		);
	}

	// ── Matrículas ────────────────────────────────────────────────────────────

	public static function migrate_enrollments( int $limit = 200, int $offset = 0 ): array {
		global $wpdb;

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta}
				 WHERE meta_key = '_clms_enrolled_courses'
				 LIMIT %d OFFSET %d",
				$limit, $offset
			)
		);

		$migrated = $skipped = $errors = 0;

		foreach ( $user_ids as $user_id ) {
			$user_id      = absint( $user_id );
			$enrolled_raw = get_user_meta( $user_id, '_clms_enrolled_courses', true );
			$enrolled     = is_array( $enrolled_raw ) ? $enrolled_raw : array();

			foreach ( $enrolled as $wp_course_id ) {
				$wp_course_id = absint( $wp_course_id );

				$atora_course_id = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_courses WHERE wp_post_id = %d LIMIT 1", $wp_course_id )
				);
				if ( ! $atora_course_id ) { $skipped++; continue; }

				$exists = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}atora_enrollments WHERE user_id = %d AND course_id = %d LIMIT 1",
						$user_id, $atora_course_id
					)
				);
				if ( $exists ) { $skipped++; continue; }

				list( $enrolled_at, $enrolled_at_source )   = self::resolve_enrollment_date( $user_id, $wp_course_id );
				list( $completed_at, $completed_at_source ) = self::resolve_completion_date( $user_id, $wp_course_id );

				// _clms_course_{id}_completed nunca se escribe (clave muerta); la
				// completación real se determina como en el resto del sistema:
				// todas las lecciones del curso están en _clms_completed_lessons.
				$is_completed = class_exists( 'CLMS_Helper' ) && \CLMS_Helper::is_course_completed( $user_id, $wp_course_id );
				$progress_pct = self::estimate_legacy_progress_pct( $user_id, $wp_course_id );

				// _clms_course_access_expiry ya se guarda en GMT (gmdate() en
				// class-commerce-enrollment.php / trait-enrollment-manager-
				// access-enrollment.php), igual que expires_at: sin conversión.
				$expires_at = class_exists( 'CLMS_Helper' )
					? \CLMS_Helper::get_user_course_access_expiration( $user_id, $wp_course_id )
					: '';

				$row = array(
					'user_id'       => $user_id,
					'course_id'     => $atora_course_id,
					'wp_course_id'  => $wp_course_id,
					'status'        => $is_completed ? 'completed' : 'active',
					'progress_pct'  => $progress_pct,
					'enrolled_at'   => $enrolled_at,
					'completed_at'  => $completed_at,
					'expires_at'    => $expires_at ?: null,
					'last_activity' => $completed_at ?: $enrolled_at,
					'meta_json'     => wp_json_encode( array(
						'enrolled_at_source'  => $enrolled_at_source,
						'completed_at_source' => $completed_at_source,
					) ),
				);

				$ok = $wpdb->insert( $wpdb->prefix . 'atora_enrollments', $row );
				$ok ? $migrated++ : $errors++;
			}
		}

		return array(
			'users_processed' => count( $user_ids ),
			'migrated'        => $migrated,
			'skipped'         => $skipped,
			'errors'          => $errors,
		);
	}

	/**
	 * Resuelve `enrolled_at` por prioridad (D-004, orden propuesto, sin el
	 * paso de fecha de pedido WooCommerce — ver nota de F1.2 en DECISIONS.md):
	 *   1) _clms_enrollment_dates[$wp_course_id] (usermeta, hora local del
	 *      sitio — escrita por CLMS_Helper::enroll_user_in_course(), la vía
	 *      canónica de matrícula incluyendo checkout de WooCommerce).
	 *   2) user_registered (wp_users, ya en GMT).
	 *   3) Último recurso defensivo (usermeta huérfana de un usuario sin
	 *      user_registered): NOW(), marcado explícitamente como tal en
	 *      meta_json.enrolled_at_source para que sea auditable.
	 *
	 * @return array{0:string,1:string} [fecha GMT 'Y-m-d H:i:s', fuente]
	 */
	private static function resolve_enrollment_date( int $user_id, int $wp_course_id ): array {
		$dates = get_user_meta( $user_id, '_clms_enrollment_dates', true );
		if ( is_array( $dates ) && ! empty( $dates[ $wp_course_id ] ) ) {
			return array( get_gmt_from_date( (string) $dates[ $wp_course_id ] ), 'enrollment_date_meta' );
		}

		$user = get_userdata( $user_id );
		if ( $user && ! empty( $user->user_registered ) ) {
			return array( $user->user_registered, 'user_registered' );
		}

		return array( current_time( 'mysql', true ), 'fallback_now_orphan_user' );
	}

	/**
	 * Fecha real de completación si existe. La única fecha de "completación de
	 * curso" presente en legacy es `issued_at` del certificado emitido
	 * (_clms_certificate_record_{course_id}, hora local del sitio). Si no hay
	 * certificado emitido, NULL — F1.2 prohíbe inventar completed_at.
	 *
	 * @return array{0:?string,1:?string} [fecha GMT 'Y-m-d H:i:s'|null, fuente|null]
	 */
	private static function resolve_completion_date( int $user_id, int $wp_course_id ): array {
		$cert = get_user_meta( $user_id, '_clms_certificate_record_' . $wp_course_id, true );
		if ( is_array( $cert ) && ! empty( $cert['issued_at'] ) ) {
			return array( get_gmt_from_date( (string) $cert['issued_at'] ), 'certificate_issued_at' );
		}

		return array( null, null );
	}

	/**
	 * Estimación inicial de progress_pct desde _clms_completed_lessons vs las
	 * lecciones del curso en legacy (mismo criterio que
	 * CLMS_Helper::is_course_completed()). migrate_lesson_progress(), llamado
	 * a continuación dentro de migrate_all(), recalcula este valor desde
	 * atora_lesson_progress en cuanto esa tabla tiene filas para este usuario;
	 * esta estimación solo cubre el caso de invocar migrate_enrollments() por
	 * separado.
	 */
	private static function estimate_legacy_progress_pct( int $user_id, int $wp_course_id ): int {
		if ( ! class_exists( 'CLMS_Helper' ) ) { return 0; }

		$lessons = \CLMS_Helper::get_course_lessons( $wp_course_id );
		$lessons = is_array( $lessons ) ? array_map( 'absint', $lessons ) : array();
		if ( empty( $lessons ) ) { return 0; }

		$completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed = is_array( $completed ) ? array_map( 'absint', $completed ) : array();

		$done = count( array_intersect( $lessons, $completed ) );

		return (int) min( 100, round( $done / count( $lessons ) * 100 ) );
	}

	// ── Progreso por lección ─────────────────────────────────────────────────

	/**
	 * Backfill de atora_lesson_progress desde la lista global de lecciones
	 * completadas en legacy (usermeta `_clms_completed_lessons`, leída/escrita
	 * en class-progress.php, class-quiz.php y
	 * trait-rest-academics-core-resources.php — confirmado por grep, sin
	 * timestamp por lección).
	 *
	 * Por cada lección completada se hace upsert con status='completed' y
	 * completed_at/last_viewed_at = NULL (no se inventa una fecha; F1.2/F3
	 * podrán enriquecer estas filas si aparece una fuente de fecha real).
	 *
	 * Idempotente: UNIQUE (user_id, lesson_id) — una segunda corrida no
	 * duplica ni vuelve a escribir filas ya 'completed'.
	 */
	public static function migrate_lesson_progress( int $limit = 200, int $offset = 0 ): array {
		global $wpdb;

		$prog_table    = $wpdb->prefix . 'atora_lesson_progress';
		$lessons_table = $wpdb->prefix . 'atora_lessons';

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$wpdb->usermeta}
				 WHERE meta_key = '_clms_completed_lessons'
				 ORDER BY user_id ASC
				 LIMIT %d OFFSET %d",
				$limit, $offset
			)
		);

		// Mapa wp_post_id (lección) => atora_lessons.id / course_id, para resolver
		// las lecciones completadas sin un query por lección.
		$lesson_map = array();
		foreach ( $wpdb->get_results( "SELECT id, course_id, wp_post_id FROM {$lessons_table} WHERE wp_post_id > 0" ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$lesson_map[ (int) $row->wp_post_id ] = array(
				'id'        => (int) $row->id,
				'course_id' => (int) $row->course_id,
			);
		}

		$migrated = $skipped = $errors = $lessons_not_migrated = 0;

		foreach ( $user_ids as $user_id ) {
			$user_id   = absint( $user_id );
			$completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
			$completed = is_array( $completed ) ? array_filter( array_map( 'absint', $completed ) ) : array();

			if ( empty( $completed ) ) { continue; }

			$touched_courses = array(); // atora_course_id => true

			foreach ( $completed as $wp_lesson_id ) {
				if ( ! isset( $lesson_map[ $wp_lesson_id ] ) ) {
					// La lección aún no está en atora_lessons (huérfano legacy → tabla,
					// ver reconcile()). Se recogerá en una corrida posterior.
					$lessons_not_migrated++;
					continue;
				}

				$lesson_id = $lesson_map[ $wp_lesson_id ]['id'];
				$course_id = $lesson_map[ $wp_lesson_id ]['course_id'];

				$existing_status = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT status FROM {$prog_table} WHERE user_id = %d AND lesson_id = %d LIMIT 1",
						$user_id, $lesson_id
					)
				);

				if ( null === $existing_status ) {
					$ok = $wpdb->insert(
						$prog_table,
						array(
							'user_id'        => $user_id,
							'lesson_id'      => $lesson_id,
							'course_id'      => $course_id,
							'wp_lesson_id'   => $wp_lesson_id,
							'status'         => 'completed',
							'completed_at'   => null,
							'last_viewed_at' => null,
						),
						array( '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
					);
					if ( $ok ) { $migrated++; $touched_courses[ $course_id ] = true; } else { $errors++; }
				} elseif ( 'completed' !== $existing_status ) {
					$ok = $wpdb->update(
						$prog_table,
						array( 'status' => 'completed' ),
						array( 'user_id' => $user_id, 'lesson_id' => $lesson_id ),
						array( '%s' ),
						array( '%d', '%d' )
					);
					if ( false !== $ok ) { $migrated++; $touched_courses[ $course_id ] = true; } else { $errors++; }
				} else {
					$skipped++;
				}
			}

			foreach ( array_keys( $touched_courses ) as $course_id ) {
				self::recalculate_enrollment_progress_pct( $user_id, $course_id );
			}
		}

		return array(
			'users_processed'      => count( $user_ids ),
			'migrated'             => $migrated,
			'skipped'              => $skipped,
			'errors'               => $errors,
			'lessons_not_migrated' => $lessons_not_migrated,
		);
	}

	/**
	 * Recalcula atora_enrollments.progress_pct desde las filas reales de
	 * atora_lesson_progress (vía LMS_Enrollment_Service::get_progress(), que
	 * ya es la fuente que usará get_progress() tras el cutover).
	 *
	 * A diferencia de LMS_Enrollment_Service::recalculate_progress() (privado),
	 * esta variante NO toca `last_activity`/`completed_at`/`status` ni dispara
	 * `atora/lms/course_completed`: en un backfill histórico esos campos
	 * requieren una fecha real (F1.2) y no deben generar automatizaciones
	 * (certificados/emails) para completaciones pasadas.
	 */
	private static function recalculate_enrollment_progress_pct( int $user_id, int $course_id ): void {
		global $wpdb;

		$enroll_table = $wpdb->prefix . 'atora_enrollments';

		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$enroll_table} WHERE user_id = %d AND course_id = %d LIMIT 1", $user_id, $course_id )
		);
		if ( ! $exists ) { return; }

		$progress = LMS_Enrollment_Service::get_progress( $user_id, $course_id );

		$wpdb->update(
			$enroll_table,
			array( 'progress_pct' => $progress['progress_pct'] ),
			array( 'user_id' => $user_id, 'course_id' => $course_id ),
			array( '%d' ),
			array( '%d', '%d' )
		);
	}

	// ── Estado de migración ──────────────────────────────────────────────────

	/**
	 * Estados de post considerados por el migrador (cursos y lecciones).
	 * Debe coincidir con el filtro WHERE post_status IN (...) de migrate_courses().
	 *
	 * @var string[]
	 */
	private static $migratable_post_statuses = array( 'publish', 'draft', 'private' );

	public static function get_status(): array {
		global $wpdb;

		$total_cpt_courses = self::count_migratable_posts( 'lm_course' );
		$total_cpt_lessons = self::count_migratable_posts( 'lm_lesson' );
		$migrated_courses  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_courses WHERE wp_post_id > 0" ); // phpcs:ignore
		$migrated_lessons  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_lessons WHERE wp_post_id > 0" ); // phpcs:ignore
		$migrated_enroll   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_enrollments" ); // phpcs:ignore

		$reconcile     = self::reconcile();
		$pending_total = array_sum( $reconcile );

		return array(
			'cpt_courses'      => $total_cpt_courses,
			'cpt_lessons'      => $total_cpt_lessons,
			'migrated_courses' => $migrated_courses,
			'migrated_lessons' => $migrated_lessons,
			'migrated_enroll'  => $migrated_enroll,
			'courses_pct'      => $total_cpt_courses > 0 ? round( $migrated_courses / $total_cpt_courses * 100, 1 ) : 100.0,
			'lessons_pct'      => $total_cpt_lessons > 0 ? round( $migrated_lessons / $total_cpt_lessons * 100, 1 ) : 100.0,
			'reconcile'        => $reconcile,
			'pending_total'    => $pending_total,
			'is_complete'      => 0 === $pending_total,
		);
	}

	/**
	 * Cuenta posts de un tipo en los estados que migrate_courses()/migrate_lessons_for_course()
	 * realmente procesan (publish/draft/private). wp_count_posts() devuelve un conteo
	 * por estado; aquí solo se suman los estados migrables para que numerador y
	 * denominador de get_status() hablen del mismo universo.
	 *
	 * @param string $post_type CPT a contar.
	 * @return int
	 */
	private static function count_migratable_posts( string $post_type ): int {
		$counts = wp_count_posts( $post_type );
		$total  = 0;

		foreach ( self::$migratable_post_statuses as $status ) {
			$total += (int) ( $counts->{$status} ?? 0 );
		}

		return $total;
	}

	// ── Reconciliación ───────────────────────────────────────────────────────

	/**
	 * Compara el estado legacy (CPT + usermeta) contra las tablas atora_* SIN
	 * escribir nada. Devuelve conteos de divergencias para decidir si la
	 * migración está realmente completa (F1.7 es el gate de cierre de fase).
	 *
	 * @return array<string,int>
	 */
	public static function reconcile(): array {
		global $wpdb;

		$courses_table  = $wpdb->prefix . 'atora_courses';
		$lessons_table  = $wpdb->prefix . 'atora_lessons';
		$enroll_table   = $wpdb->prefix . 'atora_enrollments';
		$progress_table = $wpdb->prefix . 'atora_lesson_progress';
		$statuses       = "'" . implode( "','", array_map( 'esc_sql', self::$migratable_post_statuses ) ) . "'";

		// 1) CPT sin fila en tablas (huérfanos legacy → tabla).
		$orphan_courses_legacy = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 LEFT JOIN {$courses_table} c ON c.wp_post_id = p.ID
			 WHERE p.post_type = 'lm_course' AND p.post_status IN ({$statuses}) AND c.id IS NULL"
		);

		$orphan_lessons_legacy = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 LEFT JOIN {$lessons_table} l ON l.wp_post_id = p.ID
			 WHERE p.post_type = 'lm_lesson' AND p.post_status IN ({$statuses}) AND l.id IS NULL"
		);

		// 2) Filas en tablas sin CPT correspondiente (huérfanos tabla → legacy).
		$orphan_courses_table = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$courses_table} c
			 LEFT JOIN {$wpdb->posts} p ON p.ID = c.wp_post_id AND p.post_type = 'lm_course' AND p.post_status IN ({$statuses})
			 WHERE c.wp_post_id > 0 AND p.ID IS NULL"
		);

		$orphan_lessons_table = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$lessons_table} l
			 LEFT JOIN {$wpdb->posts} p ON p.ID = l.wp_post_id AND p.post_type = 'lm_lesson' AND p.post_status IN ({$statuses})
			 WHERE l.wp_post_id > 0 AND p.ID IS NULL"
		);

		// 3) Matrículas: usermeta «_clms_enrolled_courses» vs atora_enrollments.
		$course_map = array(); // wp_post_id (curso) => atora_courses.id
		foreach ( $wpdb->get_results( "SELECT id, wp_post_id FROM {$courses_table} WHERE wp_post_id > 0" ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$course_map[ (int) $row->wp_post_id ] = (int) $row->id;
		}

		$expected_pairs              = array(); // "user_id:atora_course_id" esperados según usermeta.
		$enrollments_blocked_by_course = 0;     // matrículas legacy cuyo curso aún no está migrado.

		$enrolled_rows = $wpdb->get_results( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_clms_enrolled_courses'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $enrolled_rows as $row ) {
			$wp_course_ids = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $wp_course_ids ) ) {
				continue;
			}

			foreach ( $wp_course_ids as $wp_course_id ) {
				$wp_course_id = absint( $wp_course_id );
				if ( ! $wp_course_id ) {
					continue;
				}

				if ( ! isset( $course_map[ $wp_course_id ] ) ) {
					$enrollments_blocked_by_course++;
					continue;
				}

				$expected_pairs[ (int) $row->user_id . ':' . $course_map[ $wp_course_id ] ] = true;
			}
		}

		$actual_pairs = array(); // "user_id:course_id" presentes en atora_enrollments.
		foreach ( $wpdb->get_results( "SELECT user_id, course_id FROM {$enroll_table}" ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$actual_pairs[ (int) $row->user_id . ':' . (int) $row->course_id ] = true;
		}

		$enrollments_missing_in_table    = count( array_diff_key( $expected_pairs, $actual_pairs ) );
		$enrollments_missing_in_usermeta = count( array_diff_key( $actual_pairs, $expected_pairs ) );

		// 4) Progreso: usuarios con «_clms_completed_lessons» no vacío y 0 filas en atora_lesson_progress.
		$users_with_legacy_progress = array();
		$progress_rows = $wpdb->get_results( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_clms_completed_lessons'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $progress_rows as $row ) {
			$completed = maybe_unserialize( $row->meta_value );
			$completed = is_array( $completed ) ? array_filter( array_map( 'absint', $completed ) ) : array();
			if ( ! empty( $completed ) ) {
				$users_with_legacy_progress[ (int) $row->user_id ] = true;
			}
		}

		$users_with_table_progress = array();
		foreach ( $wpdb->get_col( "SELECT DISTINCT user_id FROM {$progress_table}" ) as $uid ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$users_with_table_progress[ (int) $uid ] = true;
		}

		$users_progress_not_migrated = count( array_diff_key( $users_with_legacy_progress, $users_with_table_progress ) );

		// 5) Programas: lm_program CPTs sin fila en atora_programs.
		$programs_table         = $wpdb->prefix . 'atora_programs';
		$has_programs_table     = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $programs_table ) ) === $programs_table;
		$orphan_programs_legacy      = 0;
		$program_enrollments_missing = 0;

		if ( $has_programs_table ) {
			$orphan_programs_legacy = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 LEFT JOIN {$programs_table} pg ON pg.wp_post_id = p.ID
				 WHERE p.post_type = 'lm_program' AND p.post_status IN ({$statuses}) AND pg.id IS NULL"
			);

			$prog_enroll_table    = $wpdb->prefix . 'atora_program_enrollments';
			$has_prog_enroll      = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prog_enroll_table ) ) === $prog_enroll_table;

			if ( $has_prog_enroll ) {
				// Construir mapa wp_post_id → atora_programs.id
				$program_map = array();
				foreach ( (array) $wpdb->get_results( "SELECT id, wp_post_id FROM {$programs_table} WHERE wp_post_id > 0" ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$program_map[ (int) $row->wp_post_id ] = (int) $row->id;
				}

				$expected_prog_pairs = array();
				foreach ( (array) $wpdb->get_results( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_clms_enrolled_programs'" ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$prog_ids = maybe_unserialize( $row->meta_value );
					if ( ! is_array( $prog_ids ) ) { continue; }
					foreach ( $prog_ids as $wp_prog_id ) {
						$wp_prog_id = absint( $wp_prog_id );
						if ( ! $wp_prog_id || ! isset( $program_map[ $wp_prog_id ] ) ) { continue; }
						$expected_prog_pairs[ (int) $row->user_id . ':' . $program_map[ $wp_prog_id ] ] = true;
					}
				}
				$actual_prog_pairs = array();
				foreach ( (array) $wpdb->get_col( "SELECT CONCAT(user_id,':',program_id) FROM {$prog_enroll_table}" ) as $pair ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$actual_prog_pairs[ $pair ] = true;
				}
				$program_enrollments_missing = count( array_diff_key( $expected_prog_pairs, $actual_prog_pairs ) );
			}
		}

		// 6) Gradebook: usermeta _clms_gradebook_course_{id} sin fila en atora_gradebook.
		$gradebook_table  = $wpdb->prefix . 'atora_gradebook';
		$has_gradebook    = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $gradebook_table ) ) === $gradebook_table;
		$gradebook_missing = 0;

		if ( $has_gradebook ) {
			$gradebook_missing = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM {$wpdb->usermeta} um
				 INNER JOIN {$courses_table} c
				   ON c.wp_post_id = CAST(REPLACE(um.meta_key,'_clms_gradebook_course_','') AS UNSIGNED)
				 LEFT JOIN {$gradebook_table} gb ON gb.user_id = um.user_id AND gb.course_id = c.id
				 WHERE um.meta_key LIKE '_clms_gradebook_course_%' AND gb.id IS NULL"
			);
		}

		// 7) Certificados: usermeta _clms_certificate_record_* sin fila en atora_certificates.
		$certs_table       = $wpdb->prefix . 'atora_certificates';
		$has_certs         = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $certs_table ) ) === $certs_table;
		$certificates_missing = 0;

		if ( $has_certs ) {
			$certificates_missing = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM {$wpdb->usermeta} um
				 LEFT JOIN {$certs_table} cert
				   ON cert.user_id = um.user_id
				   AND cert.wp_target_id = CAST(
				     REPLACE(REPLACE(um.meta_key,'_clms_program_certificate_record_',''),'_clms_certificate_record_','')
				     AS UNSIGNED
				   )
				   AND cert.target_type = IF(um.meta_key LIKE '_clms_program%', 'program', 'course')
				 WHERE (um.meta_key LIKE '_clms_certificate_record_%'
				    OR  um.meta_key LIKE '_clms_program_certificate_record_%')
				   AND cert.id IS NULL"
			);
		}

		return array(
			'orphan_courses_legacy_to_table'   => $orphan_courses_legacy,
			'orphan_lessons_legacy_to_table'   => $orphan_lessons_legacy,
			'orphan_courses_table_to_legacy'   => $orphan_courses_table,
			'orphan_lessons_table_to_legacy'   => $orphan_lessons_table,
			'enrollments_missing_in_table'     => $enrollments_missing_in_table,
			'enrollments_missing_in_usermeta'  => $enrollments_missing_in_usermeta,
			'enrollments_blocked_by_course'    => $enrollments_blocked_by_course,
			'users_progress_not_migrated'      => $users_progress_not_migrated,
			'orphan_programs_legacy'           => $orphan_programs_legacy,
			'program_enrollments_missing'      => $program_enrollments_missing,
			'gradebook_missing'                => $gradebook_missing,
			'certificates_missing'             => $certificates_missing,
		);
	}
}
