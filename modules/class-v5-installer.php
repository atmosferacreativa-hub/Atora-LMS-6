<?php
/**
 * ATORA LMS v5 — Instalador de base de datos
 *
 * Crea / actualiza todas las tablas nuevas introducidas en v5.
 * Se invoca desde el activation hook y también al hacer upgrade.
 *
 * @package ATORA_LMS
 * @since   5.0.0
 */

namespace ATORA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class V5_Installer
 *
 * @since 5.0.0
 */
class V5_Installer {

	/** Versión del esquema. Incrementar para forzar re-instalación. */
	const SCHEMA_VERSION = '6.26.4-tenant-unified';

	/** Option key que almacena la versión instalada. */
	const OPTION_KEY = 'atora_v5_schema_version';

	/**
	 * Ejecuta la instalación si la versión de esquema cambió.
	 *
	 * @return void
	 */
	public static function install(): void {
		if ( get_option( self::OPTION_KEY ) === self::SCHEMA_VERSION ) {
			return;
		}

		$prev = (string) get_option( self::OPTION_KEY, '' );
		if ( '' !== $prev && ! get_option( 'atora_tenancy_prev_v5_schema_version', false ) ) {
			update_option( 'atora_tenancy_prev_v5_schema_version', $prev, false );
		}

		if ( self::create_tables()
			&& self::migrate_tenancy_columns()
			&& self::migrate_academy_to_institution()
			&& self::migrate_wp_post_id_nullable_columns()
			&& self::migrate_rate_limit_indexes()
			&& self::migrate_telegram_links_from_usermeta()
			&& self::ensure_parity_tables()
			&& self::migrate_followup_plan_column()
			&& self::migrate_followup_plan_domain_columns()
			&& self::migrate_6131_schema_fixes() ) {
			update_option( self::OPTION_KEY, self::SCHEMA_VERSION );
		}
	}

	/**
	 * Fuerza la re-creación de todas las tablas (usado en testing y re-instalaciones).
	 *
	 * @return void
	 */
	public static function force_install(): void {
		$prev = (string) get_option( self::OPTION_KEY, '' );
		if ( '' !== $prev && ! get_option( 'atora_tenancy_prev_v5_schema_version', false ) ) {
			update_option( 'atora_tenancy_prev_v5_schema_version', $prev, false );
		}

		if ( self::create_tables()
			&& self::migrate_tenancy_columns()
			&& self::migrate_academy_to_institution()
			&& self::migrate_wp_post_id_nullable_columns()
			&& self::migrate_rate_limit_indexes()
			&& self::migrate_telegram_links_from_usermeta()
			&& self::ensure_parity_tables()
			&& self::migrate_followup_plan_column()
			&& self::migrate_followup_plan_domain_columns()
			&& self::migrate_6131_schema_fixes() ) {
			update_option( self::OPTION_KEY, self::SCHEMA_VERSION );
		}
	}

	/**
	 * X-01 (6.26.3): columnas de tenencia que dbDelta() no aplica de forma confiable
	 * en tablas ya existentes (adds de columna / índices en updates).
	 *
	 * @return bool
	 */
	private static function migrate_tenancy_columns(): bool {
		global $wpdb;

		$has_column = static function( string $table, string $column ) use ( $wpdb ): bool {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
					 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
					$table,
					$column
				)
			) > 0;
		};

		$has_index = static function( string $table, string $index ) use ( $wpdb ): bool {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
					 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
					$table,
					$index
				)
			) > 0;
		};

		$ensure_column = static function( string $table, string $column, string $ddl ) use ( $has_column, $wpdb ): void {
			if ( ! $has_column( $table, $column ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( $ddl );
			}
		};

		$ensure_index = static function( string $table, string $index, string $ddl ) use ( $has_index, $wpdb ): void {
			if ( ! $has_index( $table, $index ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( $ddl );
			}
		};

		// ── Columnas de tenencia en tablas existentes ───────────────────────

		$programs = $wpdb->prefix . 'atora_programs';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $programs ) ) ) === $programs ) {
			$ensure_column( $programs, 'institution_id', "ALTER TABLE {$programs} ADD COLUMN institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id" );
			$ensure_index( $programs, 'institution_id', "ALTER TABLE {$programs} ADD KEY institution_id (institution_id)" );
			$ensure_index( $programs, 'inst_status', "ALTER TABLE {$programs} ADD KEY inst_status (institution_id, status)" );
		}

		$courses = $wpdb->prefix . 'atora_courses';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $courses ) ) ) === $courses ) {
			$ensure_column( $courses, 'institution_id', "ALTER TABLE {$courses} ADD COLUMN institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id" );
			$ensure_column( $courses, 'scope', "ALTER TABLE {$courses} ADD COLUMN scope VARCHAR(20) NOT NULL DEFAULT 'institution' AFTER visibility" );
			$ensure_index( $courses, 'institution_id', "ALTER TABLE {$courses} ADD KEY institution_id (institution_id)" );
			$ensure_index( $courses, 'inst_status', "ALTER TABLE {$courses} ADD KEY inst_status (institution_id, status)" );
		}

		$enrollments = $wpdb->prefix . 'atora_enrollments';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $enrollments ) ) ) === $enrollments ) {
			$ensure_column( $enrollments, 'institution_id', "ALTER TABLE {$enrollments} ADD COLUMN institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id" );
			$ensure_column( $enrollments, 'cohort_id', "ALTER TABLE {$enrollments} ADD COLUMN cohort_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER course_id" );
			$ensure_index( $enrollments, 'institution_id', "ALTER TABLE {$enrollments} ADD KEY institution_id (institution_id)" );
			$ensure_index( $enrollments, 'cohort_id', "ALTER TABLE {$enrollments} ADD KEY cohort_id (cohort_id)" );
			$ensure_index( $enrollments, 'inst_status_activity', "ALTER TABLE {$enrollments} ADD KEY inst_status_activity (institution_id, status, last_activity)" );
		}

		$program_enrollments = $wpdb->prefix . 'atora_program_enrollments';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $program_enrollments ) ) ) === $program_enrollments ) {
			$ensure_column( $program_enrollments, 'institution_id', "ALTER TABLE {$program_enrollments} ADD COLUMN institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id" );
			$ensure_column( $program_enrollments, 'cohort_id', "ALTER TABLE {$program_enrollments} ADD COLUMN cohort_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER program_id" );
			$ensure_index( $program_enrollments, 'institution_id', "ALTER TABLE {$program_enrollments} ADD KEY institution_id (institution_id)" );
			$ensure_index( $program_enrollments, 'cohort_id', "ALTER TABLE {$program_enrollments} ADD KEY cohort_id (cohort_id)" );
		}

		$invitations = $wpdb->prefix . 'clms_invitations';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $invitations ) ) ) === $invitations ) {
			$ensure_column( $invitations, 'institution_id', "ALTER TABLE {$invitations} ADD COLUMN institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id" );
			$ensure_column( $invitations, 'cohort_id', "ALTER TABLE {$invitations} ADD COLUMN cohort_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER program_id" );
			$ensure_column( $invitations, 'batch_id', "ALTER TABLE {$invitations} ADD COLUMN batch_id VARCHAR(64) NOT NULL DEFAULT '' AFTER cohort_id" );
			$ensure_index( $invitations, 'institution_id', "ALTER TABLE {$invitations} ADD KEY institution_id (institution_id)" );
			$ensure_index( $invitations, 'cohort_id', "ALTER TABLE {$invitations} ADD KEY cohort_id (cohort_id)" );
			$ensure_index( $invitations, 'batch_id', "ALTER TABLE {$invitations} ADD KEY batch_id (batch_id)" );
		}

		// ── E-10: normalización del nombre de columna wp_course_id ──────────
		$delegations = $wpdb->prefix . 'atora_instructor_delegations';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $delegations ) ) ) === $delegations ) {
			if ( ! $has_column( $delegations, 'wp_course_id' ) && $has_column( $delegations, 'course_id' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$delegations} CHANGE COLUMN course_id wp_course_id BIGINT UNSIGNED NOT NULL DEFAULT 0" );
			}

			// Re-crear unique key solo si no coincide con la definición esperada.
			$delegation_cols = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.STATISTICS
					 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
					 ORDER BY SEQ_IN_INDEX ASC",
					$delegations,
					'delegation'
				)
			);
			$delegation_cols = array_values( array_filter( array_map( 'sanitize_key', $delegation_cols ) ) );
			$expected_cols   = array( 'instructor_id', 'assistant_id', 'scope', 'wp_course_id' );
			if ( $has_index( $delegations, 'delegation' ) && $delegation_cols !== $expected_cols ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$delegations} DROP INDEX delegation" );
			}
			$ensure_index( $delegations, 'delegation', "ALTER TABLE {$delegations} ADD UNIQUE KEY delegation (instructor_id, assistant_id, scope, wp_course_id)" );

			if ( $has_index( $delegations, 'course_id' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$delegations} DROP INDEX course_id" );
			}
			$ensure_index( $delegations, 'wp_course_id', "ALTER TABLE {$delegations} ADD KEY wp_course_id (wp_course_id)" );
		}

		return true;
	}

	/**
	 * 6.26.4: reconciliación de inquilino — renombra academy_id → institution_id
	 * en tablas del gradebook institucional y biblioteca académica.
	 *
	 * Idempotente: solo actúa si la columna legacy existe y la nueva no.
	 *
	 * @return bool
	 */
	private static function migrate_academy_to_institution(): bool {
		global $wpdb;

		$has_column = static function( string $table, string $column ) use ( $wpdb ): bool {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
					 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
					$table,
					$column
				)
			) > 0;
		};

		$has_index = static function( string $table, string $index ) use ( $wpdb ): bool {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
					 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
					$table,
					$index
				)
			) > 0;
		};

		$ensure_table = static function( string $table ) use ( $wpdb ): bool {
			return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
		};

		$rename_index = static function( string $table, string $from, string $to ) use ( $has_index, $wpdb ): void {
			if ( $has_index( $table, $from ) && ! $has_index( $table, $to ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$table} RENAME INDEX {$from} TO {$to}" );
				return;
			}

			if ( $has_index( $table, $from ) && $has_index( $table, $to ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$table} DROP INDEX {$from}" );
			}
		};

		$tables = array(
			$wpdb->prefix . 'atora_academic_periods' => array(
				'academy_code' => 'inst_code',
			),
			$wpdb->prefix . 'atora_grading_scales' => array(
				'academy_code_version' => 'inst_code_version',
			),
			$wpdb->prefix . 'atora_gradebook_cycles' => array(
				'academy_status' => 'inst_status',
			),
			$wpdb->prefix . 'atora_library_items' => array(
				'academy_slug' => 'inst_slug',
			),
		);

		foreach ( $tables as $table => $index_renames ) {
			if ( ! $ensure_table( $table ) ) {
				continue;
			}

			if ( $has_column( $table, 'academy_id' ) && ! $has_column( $table, 'institution_id' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$table} CHANGE COLUMN academy_id institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0" );
			}

			foreach ( $index_renames as $from => $to ) {
				$rename_index( $table, $from, $to );
			}
		}

		return true;
	}

	/**
	 * C0 (6.13.1): ALTER TABLE explícitos que dbDelta() no puede aplicar
	 * (no diffea columnas/índices en updates) — ver
	 * CLMS_Migration_6131::maybe_run(), idempotente y versionada por su
	 * propia option (atora_schema_version), independiente de
	 * SCHEMA_VERSION de esta clase.
	 *
	 * @return bool
	 */
	private static function migrate_6131_schema_fixes(): bool {
		if ( ! class_exists( 'CLMS_Migration_6131' ) ) {
			$file = defined( 'ATORA_LMS_DIR' ) ? ATORA_LMS_DIR . 'includes/migrations/class-migration-6131.php' : '';
			if ( $file && file_exists( $file ) ) {
				require_once $file;
			}
		}

		if ( ! class_exists( 'CLMS_Migration_6131' ) ) {
			return true; // Archivo genuinamente ausente — no bloquear el resto del ciclo de instalación.
		}

		\CLMS_Migration_6131::maybe_run();

		return true;
	}

	/**
	 * PT-1.2 (6.6.0): agrega followup_plan_id (nullable) a
	 * atora_calendar_events en instalaciones existentes — dbDelta() no
	 * agrega de forma confiable una columna nueva a una tabla ya
	 * creada en todos los casos (mismo criterio ya establecido para
	 * índices vía migrate_add_index()), así que se verifica y agrega
	 * explícitamente. Un evento manual del calendario actual queda con
	 * este campo en NULL, sin ningún cambio de comportamiento — es
	 * exactamente el valor por defecto que ya tendría en una tabla
	 * recién creada por create_tables().
	 *
	 * @return bool
	 */
	private static function migrate_followup_plan_column(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_calendar_events';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return false;
		}

		$has_column = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'followup_plan_id'",
				$table
			)
		) > 0;

		if ( ! $has_column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN followup_plan_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER recurrence_rule, ADD KEY followup_plan_id (followup_plan_id)" );
		}

		$has_column = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'followup_plan_id'",
				$table
			)
		) > 0;

		return $has_column;
	}

	/**
	 * PT-1.3 (6.7.0): agrega `domain` y `domain_config` a
	 * atora_followup_plans en instalaciones existentes — mismo criterio
	 * que migrate_followup_plan_column(): ALTER TABLE explícito
	 * verificado vía INFORMATION_SCHEMA, dbDelta() no es confiable para
	 * agregar columnas a una tabla ya creada.
	 *
	 * `domain` default 'academic': todo plan creado antes de 6.7.0 (el
	 * único dominio que existía) sigue resolviendo exactamente igual —
	 * Followup_Plan_Resolver despacha al Academic_Domain_Provider por
	 * default. `domain_config` nullable: ningún plan académico existente
	 * usaba configuración extra, así que NULL es un no-op para ellos.
	 *
	 * @return bool
	 */
	private static function migrate_followup_plan_domain_columns(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_followup_plans';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return false;
		}

		$existing_columns = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME IN ('domain','domain_config')",
				$table
			)
		);

		if ( ! in_array( 'domain', $existing_columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN domain VARCHAR(20) NOT NULL DEFAULT 'academic' AFTER teacher_id, ADD KEY domain (domain)" );
		}
		if ( ! in_array( 'domain_config', $existing_columns, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN domain_config LONGTEXT NULL DEFAULT NULL AFTER stage_filter" );
		}

		$final_columns = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME IN ('domain','domain_config')",
				$table
			)
		);

		return in_array( 'domain', $final_columns, true ) && in_array( 'domain_config', $final_columns, true );
	}

	/**
	 * PT-3 (6.5.10): las tablas de paridad LMS (atora_lms_parity_log,
	 * atora_lms_parity_reads) vivían solo detrás de
	 * ATORA_LMS_Migration_Admin::init() en el hook 'init' — si CUALQUIER
	 * fatal ocurría antes en la misma cadena de bootstrap (p.ej. el
	 * fatal de namespace de PT-1, cargado antes en la misma secuencia
	 * de atora_lms.php), ensure_table() nunca llegaba a ejecutarse y la
	 * tabla runtime-only quedaba huérfana — sin ningún camino de
	 * instalación oficial. Se integra acá, en el instalador real
	 * (activación + upgrade-en-caliente ya cubierto por install(), sin
	 * depender de que 'init' se complete sin errores). Reutiliza el
	 * ensure_table() ya existente de LMS_Parity en vez de duplicar su
	 * esquema acá.
	 *
	 * @return bool
	 */
	private static function ensure_parity_tables(): bool {
		// install()/force_install() corren ANTES de que
		// modules/lms/class-lms-parity.php se requiera en la secuencia
		// de atora_lms.php (V5_Installer::install() se invoca en la
		// sección "Fase V" del bootstrap, el módulo LMS se carga
		// después) — class_exists() por sí solo devolvería false acá
		// incluso en un bootstrap sano. Se requiere el archivo
		// directamente: es un require_once idempotente sin efectos
		// secundarios de carga (misma ruta que usa el propio bootstrap
		// para este archivo), no una condición de módulo activable.
		if ( ! class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
			$parity_file = defined( 'ATORA_LMS_DIR' ) ? ATORA_LMS_DIR . 'modules/lms/class-lms-parity.php' : '';
			if ( $parity_file && file_exists( $parity_file ) ) {
				require_once $parity_file;
			}
		}

		if ( ! class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
			// Archivo genuinamente ausente (deploy incompleto) — no
			// bloquear el resto de la instalación por esto; se
			// reintentará en el próximo request no gateado por
			// OPTION_KEY solo si install() vuelve a correr, lo cual
			// ocurre en cada activación/upgrade con SCHEMA_VERSION nueva.
			return true;
		}

		\ATORA\LMS\LMS_Parity::ensure_table();

		return true;
	}

	/**
	 * PT-2 (6.5.3) / PT-6.1 (6.5.4): wp_post_id BIGINT UNSIGNED NOT
	 * NULL DEFAULT 0 con UNIQUE KEY solo permitía una fila con 0 — un
	 * segundo curso/lección/programa nativo sin CPT asociado fallaba
	 * al crearse. dbDelta() no modifica de forma confiable NOT
	 * NULL/DEFAULT de una columna ya existente, así que el cambio de
	 * esquema se hace explícito acá, con ALTER TABLE directo — install
	 * nuevo ya crea las columnas nullable vía create_tables(), esto es
	 * solo para instalaciones existentes.
	 *
	 * PT-6.1 (6.5.4): a diferencia de la versión de 6.5.3
	 * (migrate_course_wp_post_id_nullable(), void, sin verificar el
	 * resultado del ALTER antes de que install()/force_install()
	 * marcaran el esquema como actualizado), esta versión sí verifica
	 * — devuelve bool, y el llamador solo marca la versión si las tres
	 * tablas quedaron de verdad nullable. Si algo falla (permisos,
	 * etc.), se reintenta en la próxima carga en vez de darlo por
	 * hecho — la robustez que la propia auditoría de 6.5.3 señaló
	 * como pendiente.
	 *
	 * PT-6.3 (6.5.4): atora_quiz_submissions NO se incluye acá a
	 * propósito — verificado que su wp_post_id vincula con el CPT
	 * legado clms_submission del que siempre se migra (el comentario
	 * de la propia definición de la tabla lo dice: "migra CPT
	 * clms_submission"), y no existe ningún punto de escritura nativa
	 * hoy — solo el migrador la escribe. No es "contenido nativo
	 * opcionalmente sin CPT" como cursos/lecciones/programas, es un
	 * espejo de un registro legado que siempre debe existir. Aplicar
	 * la migración por uniformidad sería resolver un problema que esa
	 * tabla no tiene.
	 *
	 * @return bool true si las tres columnas quedaron nullable (o ya lo estaban).
	 */
	private static function migrate_wp_post_id_nullable_columns(): bool {
		$ok = true;
		$ok = self::migrate_column_nullable( 'atora_courses', 'wp_post_id' ) && $ok;
		$ok = self::migrate_column_nullable( 'atora_lessons', 'wp_post_id' ) && $ok;
		$ok = self::migrate_column_nullable( 'atora_programs', 'wp_post_id' ) && $ok;
		return $ok;
	}

	/**
	 * @param string $table_suffix Nombre de tabla sin el prefijo de WP.
	 * @param string $column
	 * @return bool true si la columna quedó (o ya estaba) nullable.
	 */
	private static function migrate_column_nullable( string $table_suffix, string $column ): bool {
		global $wpdb;

		$table = $wpdb->prefix . $table_suffix;
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			// La tabla no existe todavía (create_tables() falló para
			// esta en particular) — no hay nada que migrar, pero
			// tampoco se puede confirmar éxito.
			return false;
		}

		if ( self::column_is_nullable( $table, $column ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN {$column} BIGINT UNSIGNED NULL DEFAULT NULL" );

		// Todo registro con 0 ("sin vínculo legado") pasa a NULL — a lo
		// sumo una fila podía tener 0 bajo el UNIQUE KEY anterior, así
		// que esto nunca choca con el propio índice único.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "UPDATE {$table} SET {$column} = NULL WHERE {$column} = 0" );

		return self::column_is_nullable( $table, $column );
	}

	/**
	 * @param string $table Nombre completo (con prefijo).
	 * @param string $column
	 * @return bool
	 */
	private static function column_is_nullable( string $table, string $column ): bool {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return isset( $row['Null'] ) && 'YES' === $row['Null'];
	}

	/**
	 * PT-6 (6.5.7): agrega el índice KEY window_start /
	 * KEY minute_key a atora_form_throttle / atora_api_rate_limit en
	 * instalaciones existentes — dbDelta() no altera de forma
	 * confiable los índices de una tabla ya creada (mismo criterio que
	 * migrate_column_nullable()), así que se verifica y agrega
	 * explícitamente, con ALTER TABLE directo y verificación posterior
	 * antes de reportar éxito. Necesario para que la limpieza periódica
	 * (ATORA_Security_Maintenance) pueda borrar por window_start/
	 * minute_key sin table scan.
	 *
	 * @return bool
	 */
	private static function migrate_rate_limit_indexes(): bool {
		$ok = true;
		$ok = self::migrate_add_index( 'atora_form_throttle', 'window_start', 'window_start', 'INT UNSIGNED' ) && $ok;
		$ok = self::migrate_add_index( 'atora_api_rate_limit', 'minute_key', 'minute_key', 'CHAR(12)' ) && $ok;
		return $ok;
	}

	/**
	 * @param string $table_suffix Nombre de tabla sin el prefijo de WP.
	 * @param string $index_name   Nombre del índice a verificar/crear.
	 * @param string $column       Columna sobre la que crear el índice (debe existir ya).
	 * @param string $column_type  Sin uso funcional — documenta el tipo esperado en el comentario del ALTER.
	 * @return bool true si el índice ya existía o quedó creado.
	 */
	private static function migrate_add_index( string $table_suffix, string $index_name, string $column, string $column_type ): bool {
		global $wpdb;
		unset( $column_type );

		$table = $wpdb->prefix . $table_suffix;
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			// P3 (6.12.0): la tabla puede no existir legítimamente porque
			// pertenece a un módulo inactivo en el perfil actual — ya no es
			// señal de que create_tables() falló. No hay nada que indexar,
			// y no indexarla no es un fallo: éxito vacío.
			return true;
		}

		if ( self::table_has_index( $table, $index_name ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE {$table} ADD KEY {$index_name} ({$column})" );

		return self::table_has_index( $table, $index_name );
	}

	/**
	 * @param string $table Nombre completo (con prefijo).
	 * @param string $index_name
	 * @return bool
	 */
	private static function table_has_index( string $table, string $index_name ): bool {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index_name ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return ! empty( $row );
	}

	/**
	 * PT-5 (6.5.7) / PT-4 (6.5.8): backfill de atora_telegram_links
	 * desde usermeta, para instalaciones que ya tenían vínculos de
	 * Telegram antes de esta tabla existir. Idempotente — una fila ya
	 * presente para un user_id se deja intacta.
	 *
	 * PT-4 (6.5.8): la versión de 6.5.7 detectaba un chat_id
	 * compartido por dos usuarios solo AL LLEGAR a la segunda fila —
	 * "quien se procesara primero" (orden no garantizado de MySQL)
	 * terminaba quedándose con el vínculo, un ganador arbitrario. Ahora
	 * los chat_id ambiguos se detectan de antemano con
	 * `GROUP BY chat_id HAVING COUNT(*) > 1` — NINGÚN usuario
	 * involucrado en un chat_id ambiguo recibe ownership automático, ni
	 * siquiera "el primero"; todos quedan fuera de la tabla nueva,
	 * disponibles en usermeta para revisión manual (nunca borrados).
	 *
	 * Genera un reporte de migración (migrated/conflicts/skipped/errors)
	 * guardado en una opción, sin exponer chat_id completos — solo un
	 * hash corto, suficiente para que un administrador correlacione el
	 * conflicto sin que quede un identificador de Telegram en claro en
	 * logs u opciones.
	 *
	 * @return bool true si la tabla existe (migración corrida o no
	 *              necesaria); false solo si la tabla ni siquiera existe.
	 */
	private static function migrate_telegram_links_from_usermeta(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_telegram_links';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			// P3 (6.12.0): la tabla puede no existir legítimamente si el
			// módulo 'messaging' está inactivo en el perfil actual — no
			// hay nada que migrar, y eso no es un fallo.
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT user_id, meta_value AS chat_id FROM {$wpdb->usermeta} WHERE meta_key = 'atora_telegram_chat_id' AND meta_value != ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		// Paso 1: detectar de antemano qué chat_id están reclamados por
		// más de un usuario en usermeta — ninguno de esos se migra.
		$counts = array();
		foreach ( (array) $rows as $row ) {
			$chat_id = sanitize_text_field( (string) ( $row['chat_id'] ?? '' ) );
			if ( '' === $chat_id ) {
				continue;
			}
			$counts[ $chat_id ] = ( $counts[ $chat_id ] ?? 0 ) + 1;
		}
		$ambiguous_chat_ids = array_keys( array_filter( $counts, static fn( $n ) => $n > 1 ) );
		$ambiguous_lookup   = array_flip( $ambiguous_chat_ids );

		$report = array( 'migrated' => 0, 'conflicts' => 0, 'skipped' => 0, 'errors' => 0 );

		foreach ( (array) $rows as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			$chat_id = sanitize_text_field( (string) ( $row['chat_id'] ?? '' ) );

			if ( ! $user_id || '' === $chat_id ) {
				$report['skipped']++;
				continue;
			}

			$already_migrated = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT user_id FROM {$table} WHERE user_id = %d", $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			if ( $already_migrated ) {
				$report['skipped']++;
				continue;
			}

			if ( isset( $ambiguous_lookup[ $chat_id ] ) ) {
				$report['conflicts']++;
				if ( function_exists( 'error_log' ) ) {
					error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						sprintf(
							'[ATORA][telegram-migration] chat_id hash=%s reclamado por %d usuarios en usermeta — user_id %d NO migrado, requiere revisión manual (dato ambiguo heredado, ningún usuario recibe ownership automático).',
							substr( hash( 'sha256', $chat_id ), 0, 12 ),
							$counts[ $chat_id ],
							$user_id
						)
					);
				}
				continue;
			}

			$inserted = $wpdb->insert(
				$table,
				array(
					'user_id'   => $user_id,
					'chat_id'   => $chat_id,
					'linked_at' => current_time( 'mysql', true ),
				),
				array( '%d', '%s', '%s' )
			);

			if ( $inserted ) {
				$report['migrated']++;
			} else {
				$report['errors']++;
			}
		}

		update_option( 'atora_telegram_migration_report', $report, false );

		return true;
	}

	/**
	 * P3 (6.12.0): true si las tablas del módulo `$slug` deben crearse —
	 * módulo activo, o registry aún no cargado (fail-open, mismo criterio
	 * que CLMS_Module_Registry::is_active() para slugs/estados no
	 * resueltos). Los grupos de tablas de módulos core (lms, academic,
	 * gradebook, security) no pasan por aquí — siempre se crean, porque
	 * esos módulos nunca se pueden desactivar.
	 *
	 * @param string $slug Slug de CLMS_Module_Registry.
	 * @return bool
	 */
	private static function module_wants_tables( string $slug ): bool {
		return ! class_exists( 'CLMS_Module_Registry' ) || \CLMS_Module_Registry::is_active( $slug );
	}

	/**
	 * Crea las tablas de los módulos actualmente activos que aún no
	 * existan. dbDelta() es idempotente (nunca borra columnas ni tablas),
	 * así que es seguro invocarlo de nuevo en cualquier momento — pensado
	 * para llamarse justo después de activar un módulo desde ATORA →
	 * Módulos (P3, 6.12.0), sin esperar a que cambie SCHEMA_VERSION.
	 *
	 * @return bool
	 */
	public static function ensure_active_module_tables(): bool {
		$installed = self::create_tables();

		if ( self::module_wants_tables( 'crm' ) ) {
			$schema_service = '\\ATORA\\CRM_V2\\Services\\DB_Service';
			if ( ! class_exists( $schema_service ) ) {
				$file = defined( 'ATORA_LMS_DIR' )
					? ATORA_LMS_DIR . 'modules/crm-v2/services/class-db-service.php'
					: '';
				if ( $file && file_exists( $file ) ) {
					require_once $file;
				}
			}

			if ( class_exists( $schema_service ) && method_exists( $schema_service, 'reconcile_after_module_activation' ) ) {
				$schema_service::reconcile_after_module_activation();
			}
		}

		return $installed;
	}

	/**
	 * Crea o actualiza las tablas v5 usando dbDelta().
	 *
	 * @return bool True cuando todas las tablas v5 esperadas existen.
	 */
	private static function create_tables(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// ── Seguridad: 2FA ─────────────────────────────────────────────────────

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_2fa_tokens (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id       BIGINT UNSIGNED NOT NULL,
			token         VARCHAR(12)     NOT NULL DEFAULT '',
			method        VARCHAR(20)     NOT NULL DEFAULT 'email' COMMENT 'totp|email|sms|whatsapp',
			expires_at    DATETIME        NOT NULL,
			verified      TINYINT(1)      NOT NULL DEFAULT 0,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_method (user_id, method),
			KEY expires_at (expires_at)
		) $charset_collate;" );

		// ── Seguridad: Dispositivos de confianza ───────────────────────────────

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_trusted_devices (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id       BIGINT UNSIGNED NOT NULL,
			device_hash   VARCHAR(64)     NOT NULL DEFAULT '',
			device_label  VARCHAR(120)    NOT NULL DEFAULT '',
			ip_address    VARCHAR(45)     NOT NULL DEFAULT '',
			user_agent    TEXT            NOT NULL,
			expires_at    DATETIME        NOT NULL,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id  (user_id),
			KEY device_hash (device_hash),
			KEY expires_at (expires_at)
		) $charset_collate;" );

		// ── P5 (6.12.0): Log de auditoría institucional ─────────────────────────
		// Core (parte de 'security', siempre activo) — quién matriculó, quién
		// cambió una nota, quién exportó datos. Ver CLMS_Audit_Log_Service.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_audit_log (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			actor_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action      VARCHAR(60)     NOT NULL DEFAULT '',
			object_type VARCHAR(40)     NOT NULL DEFAULT '',
			object_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			details_json JSON,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY actor_id    (actor_id),
			KEY action      (action),
			KEY object      (object_type, object_id),
			KEY created_at  (created_at)
		) $charset_collate;" );

		// ── Afiliados ──────────────────────────────────────────────────────────
		// P3 (6.12.0): gateado por módulo 'affiliates'.
		if ( self::module_wants_tables( 'affiliates' ) ) {

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_affiliates (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id         BIGINT UNSIGNED NOT NULL,
			referral_code   VARCHAR(30)     NOT NULL DEFAULT '',
			status          VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending|active|suspended|rejected',
			commission_rate DECIMAL(5,2)    NOT NULL DEFAULT 20.00,
			payout_method   VARCHAR(30)     NOT NULL DEFAULT 'paypal' COMMENT 'paypal|bank_transfer',
			payout_details  TEXT            NOT NULL,
			notes           TEXT            NOT NULL,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY referral_code (referral_code),
			KEY user_id (user_id),
			KEY status (status)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_affiliate_clicks (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			affiliate_id BIGINT UNSIGNED NOT NULL,
			ip           VARCHAR(45)     NOT NULL DEFAULT '',
			user_agent   TEXT            NOT NULL,
			landing_url  TEXT            NOT NULL,
			referrer_url TEXT            NOT NULL,
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY affiliate_id (affiliate_id),
			KEY created_at   (created_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_affiliate_commissions (
			id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			affiliate_id      BIGINT UNSIGNED NOT NULL,
			order_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
			amount            DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			commission_amount DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			currency          VARCHAR(3)      NOT NULL DEFAULT 'USD',
			status            VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|paid|rejected',
			approved_at       DATETIME                 DEFAULT NULL,
			paid_at           DATETIME                 DEFAULT NULL,
			created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY affiliate_id (affiliate_id),
			KEY order_id     (order_id),
			KEY status       (status)
		) $charset_collate;" );

		} // /affiliates

		// ── Sprint 3-4: Calendario ─────────────────────────────────────────────
		// P3 (6.12.0): gateado por módulo 'calendar'.
		if ( self::module_wants_tables( 'calendar' ) ) {

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_calendar_events (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title            VARCHAR(255)    NOT NULL DEFAULT '',
			description      TEXT            NOT NULL,
			event_type       VARCHAR(40)     NOT NULL DEFAULT 'academy_event',
			start_datetime   DATETIME        NOT NULL,
			end_datetime     DATETIME                 DEFAULT NULL,
			course_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			lesson_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
			location         VARCHAR(500)    NOT NULL DEFAULT '',
			max_participants INT UNSIGNED    NOT NULL DEFAULT 0,
			recurrence_rule  VARCHAR(500)    NOT NULL DEFAULT '',
			created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY start_datetime (start_datetime),
			KEY course_id      (course_id),
			KEY event_type     (event_type)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_calendar_bookings (
			id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id           BIGINT UNSIGNED NOT NULL,
			user_id            BIGINT UNSIGNED NOT NULL,
			status             VARCHAR(20)     NOT NULL DEFAULT 'confirmed' COMMENT 'confirmed|cancelled',
			booked_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			cancelled_at       DATETIME                 DEFAULT NULL,
			cancellation_reason TEXT           NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id  (event_id),
			KEY user_id   (user_id),
			KEY status    (status)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_calendar_sync (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id        BIGINT UNSIGNED NOT NULL,
			provider       VARCHAR(20)     NOT NULL DEFAULT 'google' COMMENT 'google|outlook',
			access_token   TEXT            NOT NULL,
			refresh_token  TEXT            NOT NULL,
			expires_at     DATETIME                 DEFAULT NULL,
			sync_enabled   TINYINT(1)      NOT NULL DEFAULT 1,
			last_sync_at   DATETIME                 DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_provider (user_id, provider)
		) $charset_collate;" );

		} // /calendar

		// ── Sprint 5-6: Email Engine ──────────────────────────────────────────
		// P3 (6.12.0): gateado por módulo 'email-engine'.
		if ( self::module_wants_tables( 'email-engine' ) ) {

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_email_queue (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			recipient_email  VARCHAR(200)    NOT NULL DEFAULT '',
			recipient_name   VARCHAR(200)    NOT NULL DEFAULT '',
			user_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
			template_id      INT UNSIGNED    NOT NULL DEFAULT 0,
			subject          VARCHAR(500)    NOT NULL DEFAULT '',
			body_html        LONGTEXT        NOT NULL,
			body_text        LONGTEXT        NOT NULL,
			provider         VARCHAR(30)     NOT NULL DEFAULT 'smtp',
			status           VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending|sending|sent|failed|bounced',
			scheduled_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			sent_at          DATETIME                 DEFAULT NULL,
			opened_at        DATETIME                 DEFAULT NULL,
			clicked_at       DATETIME                 DEFAULT NULL,
			error_message    TEXT            NOT NULL,
			retry_count      TINYINT UNSIGNED NOT NULL DEFAULT 0,
			priority         TINYINT UNSIGNED NOT NULL DEFAULT 5,
			metadata         JSON,
			identity_key     VARCHAR(40)     NOT NULL DEFAULT 'academia',
			created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status_scheduled (status, scheduled_at),
			KEY user_id     (user_id),
			KEY priority    (priority)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_email_templates (
			id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
			key_slug   VARCHAR(80)     NOT NULL DEFAULT '',
			name       VARCHAR(200)    NOT NULL DEFAULT '',
			type       VARCHAR(30)     NOT NULL DEFAULT 'transactional',
			subject    VARCHAR(500)    NOT NULL DEFAULT '',
			body_html  LONGTEXT        NOT NULL,
			body_text  LONGTEXT        NOT NULL,
			variables  JSON,
			category   VARCHAR(40)     NOT NULL DEFAULT 'academic',
			active     TINYINT(1)      NOT NULL DEFAULT 1,
			is_system  TINYINT(1)      NOT NULL DEFAULT 0,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY key_slug (key_slug)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_email_preferences (
			user_id                     BIGINT UNSIGNED NOT NULL,
			academic_notifications      TINYINT(1)      NOT NULL DEFAULT 1,
			grade_notifications         TINYINT(1)      NOT NULL DEFAULT 1,
			new_content_notifications   TINYINT(1)      NOT NULL DEFAULT 1,
			certificate_notifications   TINYINT(1)      NOT NULL DEFAULT 1,
			inactivity_reminders        TINYINT(1)      NOT NULL DEFAULT 1,
			marketing_offers            TINYINT(1)      NOT NULL DEFAULT 0,
			marketing_newsletter        TINYINT(1)      NOT NULL DEFAULT 0,
			marketing_promotions        TINYINT(1)      NOT NULL DEFAULT 0,
			frequency_mode              VARCHAR(20)      NOT NULL DEFAULT 'immediate',
			unsubscribed_all            TINYINT(1)      NOT NULL DEFAULT 0,
			unsubscribed_at             DATETIME                  DEFAULT NULL,
			updated_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (user_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_email_consent_log (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id        BIGINT UNSIGNED NOT NULL,
			action         VARCHAR(30)     NOT NULL DEFAULT '',
			preference_key VARCHAR(60)     NOT NULL DEFAULT '',
			old_value      TINYINT(1)               DEFAULT NULL,
			new_value      TINYINT(1)               DEFAULT NULL,
			ip_address     VARCHAR(45)     NOT NULL DEFAULT '',
			user_agent     TEXT            NOT NULL,
			source         VARCHAR(60)     NOT NULL DEFAULT '',
			created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_email_analytics (
			id                 INT UNSIGNED    NOT NULL AUTO_INCREMENT,
			period_type        VARCHAR(10)     NOT NULL DEFAULT 'day' COMMENT 'day|week|month',
			period_start       DATETIME        NOT NULL,
			period_end         DATETIME        NOT NULL,
			emails_sent        INT UNSIGNED    NOT NULL DEFAULT 0,
			emails_delivered   INT UNSIGNED    NOT NULL DEFAULT 0,
			emails_opened      INT UNSIGNED    NOT NULL DEFAULT 0,
			emails_clicked     INT UNSIGNED    NOT NULL DEFAULT 0,
			emails_bounced     INT UNSIGNED    NOT NULL DEFAULT 0,
			emails_complained  INT UNSIGNED    NOT NULL DEFAULT 0,
			unique_opens       INT UNSIGNED    NOT NULL DEFAULT 0,
			unique_clicks      INT UNSIGNED    NOT NULL DEFAULT 0,
			calculated_metrics JSON,
			PRIMARY KEY  (id),
			UNIQUE KEY period (period_type, period_start)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_email_events (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			queue_id   BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(30)     NOT NULL DEFAULT '',
			event_data JSON,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY queue_type (queue_id, event_type)
		) $charset_collate;" );

		} // /email-engine

		// ── Sprint 7-8: Newsletter ────────────────────────────────────────────
		// P3 (6.12.0): gateado por módulo 'newsletter'.
		if ( self::module_wants_tables( 'newsletter' ) ) {

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_newsletters (
			id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
			title           VARCHAR(300)    NOT NULL DEFAULT '',
			type            VARCHAR(20)     NOT NULL DEFAULT 'academic' COMMENT 'academic|commercial',
			sections        JSON,
			template_id     INT UNSIGNED    NOT NULL DEFAULT 0,
			status          VARCHAR(20)     NOT NULL DEFAULT 'draft' COMMENT 'draft|scheduled|sent',
			schedule_type   VARCHAR(20)     NOT NULL DEFAULT 'once',
			schedule_config JSON,
			target_segment  JSON,
			ab_test_enabled TINYINT(1)      NOT NULL DEFAULT 0,
			ab_variants     JSON,
			sent_count      INT UNSIGNED    NOT NULL DEFAULT 0,
			sent_at         DATETIME                 DEFAULT NULL,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status)
		) $charset_collate;" );

		} // /newsletter

		// ── Sprint 9-10: Analytics / Engagement ──────────────────────────────
		// P3 (6.12.0): gateado por módulo 'analytics'.
		if ( self::module_wants_tables( 'analytics' ) ) {

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_user_engagement (
			user_id          BIGINT UNSIGNED NOT NULL,
			emails_received  INT UNSIGNED    NOT NULL DEFAULT 0,
			emails_opened    INT UNSIGNED    NOT NULL DEFAULT 0,
			emails_clicked   INT UNSIGNED    NOT NULL DEFAULT 0,
			last_open_date   DATETIME                 DEFAULT NULL,
			last_click_date  DATETIME                 DEFAULT NULL,
			engagement_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
			risk_level       VARCHAR(10)     NOT NULL DEFAULT 'medium' COMMENT 'low|medium|high',
			updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (user_id),
			KEY engagement_score (engagement_score),
			KEY risk_level       (risk_level)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_form_entries (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id    BIGINT UNSIGNED NOT NULL,
			user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			entry_data JSON,
			ip_address VARCHAR(45)     NOT NULL DEFAULT '',
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY form_id (form_id)
		) $charset_collate;" );

		// PT-1 (6.5.5): contador de throttle por IP/formulario/ventana,
		// atómico vía INSERT ... ON DUPLICATE KEY UPDATE (bloqueo de fila
		// InnoDB) — reemplaza el patrón get_transient()+set_transient()
		// (lectura-incremento-escritura no atómico, vulnerable a
		// condiciones de carrera bajo concurrencia real). La cardinalidad
		// de filas está acotada: Forms_Builder solo llama a esto tras
		// confirmar que form_id corresponde a un atora_form real — nunca
		// con un form_id arbitrario/inexistente.
		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_form_throttle (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id      BIGINT UNSIGNED NOT NULL,
			ip_hash      CHAR(64)        NOT NULL,
			window_start INT UNSIGNED    NOT NULL,
			attempts     INT UNSIGNED    NOT NULL DEFAULT 1,
			updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY form_ip_window (form_id, ip_hash, window_start),
			KEY window_start (window_start)
		) $charset_collate;" );

		} // /analytics

		// ── Sprint 11-12: Messaging / CRM ────────────────────────────────────
		// P3 (6.12.0): gateado por módulo 'messaging' (colas de envío
		// WhatsApp/Telegram/SMS/Email, no la bandeja CRM de abajo).
		if ( self::module_wants_tables( 'messaging' ) ) {

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_message_queue (
			id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			recipient_phone     VARCHAR(30)     NOT NULL DEFAULT '',
			recipient_name      VARCHAR(200)    NOT NULL DEFAULT '',
			user_id             BIGINT UNSIGNED NOT NULL DEFAULT 0,
			channel             VARCHAR(20)     NOT NULL DEFAULT 'email' COMMENT 'whatsapp|telegram|sms|email',
			template_key        VARCHAR(80)     NOT NULL DEFAULT '',
			variables           JSON,
			provider_message_id VARCHAR(100)    NOT NULL DEFAULT '',
			status              VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending|sending|sent|delivered|read|failed',
			scheduled_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			sent_at             DATETIME                 DEFAULT NULL,
			delivered_at        DATETIME                 DEFAULT NULL,
			read_at             DATETIME                 DEFAULT NULL,
			error_message       TEXT            NOT NULL,
			retry_count         TINYINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status_scheduled (status, scheduled_at),
			KEY user_id          (user_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_message_log (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			queue_id   BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(30)     NOT NULL DEFAULT '',
			event_data JSON,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY queue_id (queue_id)
		) $charset_collate;" );

		// PT-5 (6.5.7): fuente de verdad para la unicidad del vínculo
		// Telegram — antes solo vivía en usermeta (sin restricción
		// UNIQUE nativa posible) más un candado de mejor esfuerzo vía
		// transient. UNIQUE(user_id) + UNIQUE(chat_id) hace que la
		// propia base de datos rechace, a nivel de fila, cualquier
		// intento de vincular el mismo chat a dos usuarios — incluso
		// bajo dos solicitudes concurrentes, MySQL solo permite que una
		// de las dos inserciones tenga éxito. usermeta se mantiene en
		// paralelo (Telegram_Bot::ajax_link_account() sigue
		// escribiéndolo) porque otros módulos (CRM) todavía lo leen
		// directamente; esta tabla es la que decide unicidad de verdad.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_telegram_links (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id    BIGINT UNSIGNED NOT NULL,
			chat_id    VARCHAR(64)     NOT NULL,
			linked_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY user_id (user_id),
			UNIQUE KEY chat_id (chat_id)
		) $charset_collate;" );

		} // /messaging

		// P3 (6.12.0): gateado por módulo 'crm' (bandeja omnicanal + contactos).
		if ( self::module_wants_tables( 'crm' ) ) {

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_conversations (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			channel         VARCHAR(20)     NOT NULL DEFAULT 'email' COMMENT 'email|whatsapp|telegram|sms|system',
			identity_key    VARCHAR(40)     NOT NULL DEFAULT '',
			subject         VARCHAR(255)    NOT NULL DEFAULT '',
			status          VARCHAR(20)     NOT NULL DEFAULT 'open' COMMENT 'open|pending|closed|archived',
			last_message_at DATETIME                 DEFAULT NULL,
			assigned_to     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			course_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
			order_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY contact_id (contact_id),
			KEY user_id (user_id),
			KEY channel (channel),
			KEY status (status),
			KEY last_message_at (last_message_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_conversation_messages (
			id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id     BIGINT UNSIGNED NOT NULL,
			direction           VARCHAR(20)     NOT NULL DEFAULT 'outbound' COMMENT 'inbound|outbound|system',
			channel             VARCHAR(20)     NOT NULL DEFAULT 'email',
			provider_message_id VARCHAR(100)    NOT NULL DEFAULT '',
			sender              VARCHAR(200)    NOT NULL DEFAULT '',
			recipient           VARCHAR(200)    NOT NULL DEFAULT '',
			subject             VARCHAR(255)    NOT NULL DEFAULT '',
			body_text           LONGTEXT        NOT NULL,
			body_html           LONGTEXT        NOT NULL,
			raw_payload_json    LONGTEXT        NOT NULL,
			status              VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending|sent|delivered|read|failed|received',
			error_message       TEXT            NOT NULL,
			created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY channel (channel),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_contacts (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id    BIGINT UNSIGNED          DEFAULT NULL,
			email      VARCHAR(200)    NOT NULL DEFAULT '',
			name       VARCHAR(200)    NOT NULL DEFAULT '',
			phone      VARCHAR(30)     NOT NULL DEFAULT '',
			whatsapp   VARCHAR(30)     NOT NULL DEFAULT '',
			country    VARCHAR(5)      NOT NULL DEFAULT '',
			city       VARCHAR(100)    NOT NULL DEFAULT '',
			company    VARCHAR(200)    NOT NULL DEFAULT '',
			job_title  VARCHAR(200)    NOT NULL DEFAULT '',
			source     VARCHAR(30)     NOT NULL DEFAULT 'manual' COMMENT 'form|import|woocommerce|manual|registration',
			status     VARCHAR(20)     NOT NULL DEFAULT 'lead' COMMENT 'lead|prospect|student|alumni',
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY email   (email),
				KEY user_id (user_id),
				KEY phone   (phone),
				KEY whatsapp (whatsapp),
				KEY status  (status)
			) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_contact_tags (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			tag_name   VARCHAR(100)    NOT NULL DEFAULT '',
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY contact_tag (contact_id, tag_name),
			KEY tag_name (tag_name)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_contact_activities (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id    BIGINT UNSIGNED NOT NULL,
			activity_type VARCHAR(60)     NOT NULL DEFAULT '',
			activity_data JSON,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_by    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY contact_id    (contact_id),
			KEY activity_type (activity_type),
			KEY created_at    (created_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_contact_notes (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			user_id    BIGINT UNSIGNED NOT NULL,
			note_text  LONGTEXT        NOT NULL,
			is_pinned  TINYINT(1)      NOT NULL DEFAULT 0,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY contact_id (contact_id)
		) $charset_collate;" );

		} // /crm (conversations + contactos)

		// ── Sprint 13-14: Automatizaciones ────────────────────────────────────
		// P3 (6.12.0): gateado por módulo 'automation'.
		if ( self::module_wants_tables( 'automation' ) ) {

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_automations (
			id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
			name            VARCHAR(200) NOT NULL DEFAULT '',
			description     TEXT         NOT NULL,
			trigger_type    VARCHAR(60)  NOT NULL DEFAULT '',
			trigger_config  JSON,
			conditions      JSON,
			actions         JSON,
			active          TINYINT(1)   NOT NULL DEFAULT 0,
			priority        TINYINT UNSIGNED NOT NULL DEFAULT 10,
			created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY trigger_type (trigger_type),
			KEY active       (active)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_automation_queue (
				id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				automation_id  INT UNSIGNED    NOT NULL,
				user_id        BIGINT UNSIGNED NOT NULL,
			action_index   TINYINT UNSIGNED NOT NULL DEFAULT 0,
			action_data    JSON,
			context        JSON,
			execute_at     DATETIME        NOT NULL,
			status         VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending|running|completed|failed',
			retry_count    TINYINT UNSIGNED NOT NULL DEFAULT 0,
			executed_at    DATETIME                  DEFAULT NULL,
			created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
				KEY status_execute (status, execute_at),
				KEY automation_id  (automation_id)
			) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_automation_execution_log (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			automation_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			queue_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			contact_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			trigger_type    VARCHAR(60)     NOT NULL DEFAULT '',
			action_type     VARCHAR(60)     NOT NULL DEFAULT '',
			action_index    TINYINT UNSIGNED NOT NULL DEFAULT 0,
			status          ENUM('success','failed','skipped') NOT NULL DEFAULT 'success',
			error_message   TEXT            NULL,
			duration_ms     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			context_json    JSON,
			executed_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY automation_id (automation_id),
			KEY user_id       (user_id),
			KEY contact_id    (contact_id),
			KEY status        (status),
			KEY executed_at   (executed_at)
		) $charset_collate;" );

		} // /automation

		// P3 (6.12.0): gateado por módulo 'crm' (empresas y listas).
		if ( self::module_wants_tables( 'crm' ) ) {

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_companies (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name            VARCHAR(200)    NOT NULL DEFAULT '',
			industry        VARCHAR(100)    NOT NULL DEFAULT '',
			email           VARCHAR(200)    NOT NULL DEFAULT '',
			phone           VARCHAR(30)     NOT NULL DEFAULT '',
			website         VARCHAR(300)    NOT NULL DEFAULT '',
			linkedin_url    VARCHAR(300)    NOT NULL DEFAULT '',
			country         VARCHAR(5)      NOT NULL DEFAULT '',
			city            VARCHAR(100)    NOT NULL DEFAULT '',
			state           VARCHAR(100)    NOT NULL DEFAULT '',
			address         VARCHAR(300)    NOT NULL DEFAULT '',
			employees_count INT UNSIGNED    NOT NULL DEFAULT 0,
			description     TEXT            NOT NULL,
			owner_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_by      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY name (name(100)),
			KEY owner_id (owner_id),
			KEY country (country)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_crm_lists (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title       VARCHAR(200)    NOT NULL DEFAULT '',
			slug        VARCHAR(200)    NOT NULL DEFAULT '',
			description TEXT            NOT NULL,
			type        VARCHAR(30)     NOT NULL DEFAULT 'marketing',
			is_public   TINYINT(1)      NOT NULL DEFAULT 0,
			created_by  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug),
			KEY type (type)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_contact_list_pivot (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id  BIGINT UNSIGNED NOT NULL,
			list_id     BIGINT UNSIGNED NOT NULL,
			status      ENUM('subscribed','unsubscribed','pending') NOT NULL DEFAULT 'subscribed',
			joined_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY contact_list (contact_id, list_id),
			KEY list_id (list_id),
			KEY status (status)
		) $charset_collate;" );

		} // /crm (empresas y listas)

		// ── Fase 10: Carritos abandonados + URL tracking ──────────────────────
		// P3 (6.12.0): gateado por módulo 'commerce'.
		if ( self::module_wants_tables( 'commerce' ) ) {
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_abandoned_carts (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			checkout_key    VARCHAR(64)     NOT NULL DEFAULT '',
			cart_hash       VARCHAR(64)     NOT NULL DEFAULT '',
			email           VARCHAR(200)    NOT NULL DEFAULT '',
			contact_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			provider        VARCHAR(30)     NOT NULL DEFAULT 'woocommerce',
			cart_data       LONGTEXT        NOT NULL,
			subtotal        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			total           DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			currency        VARCHAR(3)      NOT NULL DEFAULT 'USD',
			status          VARCHAR(20)     NOT NULL DEFAULT 'active',
			order_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			automation_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			is_optout       TINYINT(1)      NOT NULL DEFAULT 0,
			checkout_url    VARCHAR(500)    NOT NULL DEFAULT '',
			abandoned_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			recovered_at    DATETIME                 DEFAULT NULL,
			expires_at      DATETIME                 DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY checkout_key (checkout_key),
			KEY email       (email),
			KEY contact_id  (contact_id),
			KEY status      (status),
			KEY abandoned_at (abandoned_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_url_store (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			short_key    VARCHAR(32)     NOT NULL DEFAULT '',
			original_url VARCHAR(2000)   NOT NULL DEFAULT '',
			campaign_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sequence_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY short_key (short_key),
			KEY campaign_id (campaign_id),
			KEY sequence_id (sequence_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_url_clicks (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url_id          BIGINT UNSIGNED NOT NULL,
			recipient_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			contact_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			clicked_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ip_address      VARCHAR(45)     NOT NULL DEFAULT '',
			user_agent      VARCHAR(500)    NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY url_id      (url_id),
			KEY contact_id  (contact_id),
			KEY clicked_at  (clicked_at)
		) $charset_collate;" );
		} // /commerce
		// ── /Fase 10 ───────────────────────────────────────────────────────────

		// ── Fase 11: LMS — tablas propias (desacopla wp_posts) ───────────────
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_courses (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			institution_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			wp_post_id      BIGINT UNSIGNED NULL DEFAULT NULL,
			title           VARCHAR(500)    NOT NULL DEFAULT '',
			slug            VARCHAR(500)    NOT NULL DEFAULT '',
			description     LONGTEXT        NOT NULL,
			excerpt         TEXT            NOT NULL,
			status          VARCHAR(20)     NOT NULL DEFAULT 'draft',
			visibility      VARCHAR(20)     NOT NULL DEFAULT 'public',
			scope           VARCHAR(20)     NOT NULL DEFAULT 'institution',
			type            VARCHAR(20)     NOT NULL DEFAULT 'self_paced',
			instructor_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			price           DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			currency        VARCHAR(3)      NOT NULL DEFAULT 'USD',
			duration_hours  DECIMAL(5,1)    NOT NULL DEFAULT 0.0,
			level           VARCHAR(20)     NOT NULL DEFAULT 'beginner',
			language        VARCHAR(5)      NOT NULL DEFAULT 'es',
			thumbnail_url   VARCHAR(500)    NOT NULL DEFAULT '',
			certificate_tpl VARCHAR(200)    NOT NULL DEFAULT '',
			passing_grade   TINYINT UNSIGNED NOT NULL DEFAULT 70,
			settings_json   LONGTEXT        DEFAULT NULL,
			meta_json       LONGTEXT        DEFAULT NULL,
			published_at    DATETIME                 DEFAULT NULL,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY wp_post_id  (wp_post_id),
			KEY status         (status),
			KEY institution_id (institution_id),
			KEY inst_status    (institution_id, status),
			KEY instructor_id  (instructor_id),
			KEY slug           (slug(200))
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_lessons (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_post_id      BIGINT UNSIGNED NULL DEFAULT NULL,
			course_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title           VARCHAR(500)    NOT NULL DEFAULT '',
			slug            VARCHAR(500)    NOT NULL DEFAULT '',
			content         LONGTEXT        NOT NULL,
			lesson_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			section         VARCHAR(200)    NOT NULL DEFAULT '',
			section_order   TINYINT UNSIGNED NOT NULL DEFAULT 0,
			type            VARCHAR(20)     NOT NULL DEFAULT 'text',
			duration_min    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			is_free_preview TINYINT(1)      NOT NULL DEFAULT 0,
			is_required     TINYINT(1)      NOT NULL DEFAULT 1,
			video_url       VARCHAR(500)    NOT NULL DEFAULT '',
			resources_json  LONGTEXT        DEFAULT NULL,
			settings_json   LONGTEXT        DEFAULT NULL,
			status          VARCHAR(20)     NOT NULL DEFAULT 'published',
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY wp_post_id   (wp_post_id),
			KEY course_id       (course_id),
			KEY lesson_order    (course_id, lesson_order),
			KEY section         (course_id, section_order)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_enrollments (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			institution_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id         BIGINT UNSIGNED NOT NULL,
			course_id       BIGINT UNSIGNED NOT NULL,
			cohort_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
			wp_course_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status          VARCHAR(20)     NOT NULL DEFAULT 'active',
			progress_pct    TINYINT UNSIGNED NOT NULL DEFAULT 0,
			grade           DECIMAL(5,2)             DEFAULT NULL,
			order_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			enrolled_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			completed_at    DATETIME                 DEFAULT NULL,
			expires_at      DATETIME                 DEFAULT NULL,
			last_activity   DATETIME                 DEFAULT NULL,
			meta_json       LONGTEXT        DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY user_course  (user_id, course_id),
			KEY status      (status),
			KEY institution_id (institution_id),
			KEY inst_status_activity (institution_id, status, last_activity),
			KEY course_id   (course_id),
			KEY cohort_id   (cohort_id),
			KEY enrolled_at (enrolled_at),
			KEY last_activity (last_activity)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_lesson_progress (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id         BIGINT UNSIGNED NOT NULL,
			lesson_id       BIGINT UNSIGNED NOT NULL,
			course_id       BIGINT UNSIGNED NOT NULL,
			wp_lesson_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status          VARCHAR(20)     NOT NULL DEFAULT 'in_progress',
			time_spent_sec  INT UNSIGNED    NOT NULL DEFAULT 0,
			attempts        TINYINT UNSIGNED NOT NULL DEFAULT 1,
			score           DECIMAL(5,2)             DEFAULT NULL,
			completed_at    DATETIME                 DEFAULT NULL,
			last_viewed_at  DATETIME                 DEFAULT NULL,
			meta_json       LONGTEXT        DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY user_lesson  (user_id, lesson_id),
			KEY course_id       (course_id),
			KEY status          (status),
			KEY completed_at    (completed_at)
		) $charset_collate;" );
		// ── /Fase 11 ──────────────────────────────────────────────────────────

		// ── Fase 11b: Tablas LMS extendidas (D-001/D-002/D-003, 2026-06-15) ──

		// Taxonomías/categorías de cursos — tabla pivote (D-003 = B).
		// Reemplaza el enfoque meta_json.categories[] (D-003 = A, descartado).
		// PT-4 (6.5.10): con utf8mb4 (4 bytes/char), el prefijo original
		// de este índice compuesto era course_id(8) + taxonomy(80)*4=320
		// + term_slug(200)*4=800 = 1128 bytes — por encima del límite de
		// 1000 bytes reportado en producción (y del límite histórico más
		// conservador de 767 bytes, aún vigente en instalaciones sin
		// innodb_large_prefix). Los prefijos de índice se reducen a
		// taxonomy(32)/term_slug(150) — course_id(8) + 32*4=128 +
		// 150*4=600 = 736 bytes, con margen bajo el límite de 767. La
		// longitud LÓGICA de las columnas (VARCHAR(100)/VARCHAR(200)) no
		// cambia — solo cuántos caracteres iniciales indexa MySQL; una
		// colisión real requeriría dos taxonomías con el mismo nombre en
		// los primeros 32 caracteres o dos slugs idénticos en los
		// primeros 150, prácticamente imposible para slugs de URL.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_course_terms (
			id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			course_id   BIGINT UNSIGNED  NOT NULL,
			taxonomy    VARCHAR(100)     NOT NULL,
			term_slug   VARCHAR(200)     NOT NULL,
			term_name   VARCHAR(200)     NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			UNIQUE KEY uq_course_tax_slug (course_id, taxonomy(32), term_slug(150)),
			KEY idx_taxonomy_slug         (taxonomy(32), term_slug(150))
		) $charset_collate;" );

		// Programas/diplomados (D-001 = A).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_programs (
			id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			institution_id  BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			wp_post_id      BIGINT UNSIGNED  NULL DEFAULT NULL,
			title           VARCHAR(500)     NOT NULL DEFAULT '',
			slug            VARCHAR(500)     NOT NULL DEFAULT '',
			description     LONGTEXT         NOT NULL,
			excerpt         TEXT             NOT NULL,
			status          VARCHAR(20)      NOT NULL DEFAULT 'draft',
			instructor_id   BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			price           DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
			currency        VARCHAR(3)       NOT NULL DEFAULT 'USD',
			duration_hours  DECIMAL(5,1)     NOT NULL DEFAULT 0.0,
			thumbnail_url   VARCHAR(500)     NOT NULL DEFAULT '',
			passing_grade   TINYINT UNSIGNED NOT NULL DEFAULT 70,
			settings_json   LONGTEXT                  DEFAULT NULL,
			meta_json       LONGTEXT                  DEFAULT NULL,
			published_at    DATETIME                  DEFAULT NULL,
			created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY wp_post_id    (wp_post_id),
			KEY status               (status),
			KEY institution_id       (institution_id),
			KEY inst_status          (institution_id, status),
			KEY instructor_id        (instructor_id),
			KEY slug                 (slug(200))
		) $charset_collate;" );

		// Matrículas a programa (D-001 = A).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_program_enrollments (
			id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			institution_id  BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			user_id         BIGINT UNSIGNED  NOT NULL,
			program_id      BIGINT UNSIGNED  NOT NULL,
			cohort_id       BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			wp_program_id   BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			status          VARCHAR(20)      NOT NULL DEFAULT 'active',
			enrolled_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			completed_at    DATETIME                  DEFAULT NULL,
			expires_at      DATETIME                  DEFAULT NULL,
			last_activity   DATETIME                  DEFAULT NULL,
			meta_json       LONGTEXT                  DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY user_program  (user_id, program_id),
			KEY status               (status),
			KEY institution_id       (institution_id),
			KEY program_id           (program_id),
			KEY cohort_id            (cohort_id),
			KEY enrolled_at          (enrolled_at)
		) $charset_collate;" );

		// ── X-01: Instituciones + Cohortes (tenencia) ───────────────────────
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_institutions (
			id                   BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			slug                 VARCHAR(190)     NOT NULL DEFAULT '',
			name                 VARCHAR(255)     NOT NULL DEFAULT '',
			legal_name           VARCHAR(255)     NOT NULL DEFAULT '',
			status               VARCHAR(20)      NOT NULL DEFAULT 'active',
			locale               VARCHAR(10)      NOT NULL DEFAULT 'es',
			timezone             VARCHAR(64)      NOT NULL DEFAULT '',
			logo_url             VARCHAR(500)     NOT NULL DEFAULT '',
			contact_email        VARCHAR(190)     NOT NULL DEFAULT '',
			grading_policy_json  LONGTEXT                  DEFAULT NULL,
			ai_policy_json       LONGTEXT                  DEFAULT NULL,
			settings_json        LONGTEXT                  DEFAULT NULL,
			seats_licensed       INT UNSIGNED     NOT NULL DEFAULT 0,
			created_at           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at           DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug),
			KEY status (status)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_institution_members (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			institution_id  BIGINT UNSIGNED NOT NULL,
			user_id         BIGINT UNSIGNED NOT NULL,
			role            VARCHAR(40)     NOT NULL DEFAULT 'student',
			status          VARCHAR(20)     NOT NULL DEFAULT 'active',
			scope_json      LONGTEXT                 DEFAULT NULL,
			student_code    VARCHAR(60)     NOT NULL DEFAULT '',
			joined_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY inst_user_role (institution_id, user_id, role),
			KEY inst_role_status (institution_id, role, status),
			KEY user_id (user_id),
			KEY inst_code (institution_id, student_code)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_cohorts (
			id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			institution_id  BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			program_id      BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			wp_post_id      BIGINT UNSIGNED  NULL DEFAULT NULL,
			code            VARCHAR(60)      NOT NULL DEFAULT '',
			name            VARCHAR(255)     NOT NULL DEFAULT '',
			status          VARCHAR(20)      NOT NULL DEFAULT 'planned',
			start_date      DATE                      DEFAULT NULL,
			end_date        DATE                      DEFAULT NULL,
			capacity        INT UNSIGNED     NOT NULL DEFAULT 0,
			settings_json   LONGTEXT                  DEFAULT NULL,
			created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY wp_post_id (wp_post_id),
			KEY inst_status (institution_id, status),
			KEY program_id (program_id),
			KEY dates (start_date, end_date)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_cohort_members (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cohort_id       BIGINT UNSIGNED NOT NULL,
			institution_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id         BIGINT UNSIGNED NOT NULL,
			role            VARCHAR(20)     NOT NULL DEFAULT 'student',
			status          VARCHAR(20)     NOT NULL DEFAULT 'active',
			joined_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			left_at         DATETIME                 DEFAULT NULL,
			meta_json       LONGTEXT                 DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY cohort_user_role (cohort_id, user_id, role),
			KEY cohort_status (cohort_id, status),
			KEY inst_user (institution_id, user_id),
			KEY user_id (user_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_cohort_courses (
			id            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
			cohort_id     BIGINT UNSIGNED   NOT NULL,
			course_id     BIGINT UNSIGNED   NOT NULL,
			course_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			is_required   TINYINT(1)        NOT NULL DEFAULT 1,
			opens_at      DATETIME                   DEFAULT NULL,
			closes_at     DATETIME                   DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY cohort_course (cohort_id, course_id),
			KEY course_id (course_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_tenancy_audit (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			institution_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			cohort_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
			actor_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			on_behalf_of    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			target_user_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			object_type     VARCHAR(40)     NOT NULL DEFAULT '',
			object_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action          VARCHAR(40)     NOT NULL DEFAULT '',
			payload_json    LONGTEXT                 DEFAULT NULL,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY inst_created (institution_id, created_at),
			KEY cohort_id (cohort_id),
			KEY actor_id (actor_id),
			KEY target_user (target_user_id)
		) $charset_collate;" );

		// ── E-10: Delegación de instructor asistente ────────────────────────
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_instructor_delegations (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			institution_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			instructor_id   BIGINT UNSIGNED NOT NULL,
			assistant_id    BIGINT UNSIGNED NOT NULL,
			scope           VARCHAR(20)     NOT NULL DEFAULT 'instructor',
			wp_course_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status          VARCHAR(20)     NOT NULL DEFAULT 'active',
			perms_json      LONGTEXT                 DEFAULT NULL,
			created_by      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			expires_at      DATETIME                 DEFAULT NULL,
			revoked_by      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			revoked_at      DATETIME                 DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY delegation (instructor_id, assistant_id, scope, wp_course_id),
			KEY assistant_status (assistant_id, status),
			KEY instructor_id (instructor_id),
			KEY wp_course_id (wp_course_id),
			KEY institution_id (institution_id)
		) $charset_collate;" );

		// Definición de quiz por lección (D-002; una fila por lección con quiz activo).
		// Las preguntas en JSON provienen de _clms_quiz_questions (postmeta de lm_lesson).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_quizzes (
			id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			lesson_id       BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			wp_lesson_id    BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			course_id       BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			questions_json  LONGTEXT                  DEFAULT NULL,
			settings_json   LONGTEXT                  DEFAULT NULL,
			created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY lesson_id  (lesson_id),
			KEY course_id         (course_id),
			KEY wp_lesson_id      (wp_lesson_id)
		) $charset_collate;" );

		// Intentos de quiz por alumno (D-002; migra CPT clms_submission).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_quiz_submissions (
			id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			wp_post_id      BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			user_id         BIGINT UNSIGNED  NOT NULL,
			quiz_id         BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			lesson_id       BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			course_id       BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			wp_lesson_id    BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			wp_course_id    BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			status          VARCHAR(30)      NOT NULL DEFAULT 'pending',
			grade           DECIMAL(5,2)              DEFAULT NULL,
			feedback        TEXT                      DEFAULT NULL,
			rubric_json     LONGTEXT                  DEFAULT NULL,
			submitted_at    DATETIME                  DEFAULT NULL,
			graded_at       DATETIME                  DEFAULT NULL,
			meta_json       LONGTEXT                  DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY wp_post_id  (wp_post_id),
			KEY user_id            (user_id),
			KEY lesson_id          (lesson_id),
			KEY course_id          (course_id),
			KEY status             (status)
		) $charset_collate;" );

		// ── Gradebook institucional (6.22.0) ────────────────────────────────
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_academic_periods (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			institution_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			code        VARCHAR(60) NOT NULL,
			name        VARCHAR(190) NOT NULL,
			starts_at   DATE NOT NULL,
			ends_at     DATE NOT NULL,
			status      VARCHAR(20) NOT NULL DEFAULT 'draft',
			created_by  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY inst_code (institution_id, code),
			KEY status (status),
			KEY dates (starts_at, ends_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_grading_scales (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			institution_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			code        VARCHAR(60) NOT NULL,
			name        VARCHAR(190) NOT NULL,
			minimum     DECIMAL(9,4) NOT NULL DEFAULT 0,
			maximum     DECIMAL(9,4) NOT NULL DEFAULT 100,
			bands_json  LONGTEXT NOT NULL,
			status      VARCHAR(20) NOT NULL DEFAULT 'active',
			version     INT UNSIGNED NOT NULL DEFAULT 1,
			created_by  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY inst_code_version (institution_id, code, version),
			KEY status (status)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_gradebook_cycles (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			institution_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			period_id      BIGINT UNSIGNED NOT NULL,
			course_id      BIGINT UNSIGNED NOT NULL,
			scale_id       BIGINT UNSIGNED NOT NULL,
			status         VARCHAR(20) NOT NULL DEFAULT 'draft',
			lock_version   INT UNSIGNED NOT NULL DEFAULT 1,
			snapshot_hash  CHAR(64) NOT NULL DEFAULT '',
			snapshot_json  LONGTEXT NULL DEFAULT NULL,
			published_at   DATETIME NULL DEFAULT NULL,
			published_by   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			closed_at      DATETIME NULL DEFAULT NULL,
			closed_by      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_by     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY period_course (period_id, course_id),
			KEY inst_status (institution_id, status),
			KEY course_id (course_id),
			KEY scale_id (scale_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_grade_moderations (
			id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			submission_id           BIGINT UNSIGNED NOT NULL,
			cycle_id                BIGINT UNSIGNED NOT NULL,
			student_id              BIGINT UNSIGNED NOT NULL,
			course_id               BIGINT UNSIGNED NOT NULL,
			primary_grader_id       BIGINT UNSIGNED NOT NULL,
			moderator_id            BIGINT UNSIGNED NOT NULL DEFAULT 0,
			primary_grade           DECIMAL(9,4) NOT NULL,
			moderator_grade         DECIMAL(9,4) NULL DEFAULT NULL,
			resolved_grade          DECIMAL(9,4) NULL DEFAULT NULL,
			primary_rubric_json     LONGTEXT NULL DEFAULT NULL,
			moderator_rubric_json   LONGTEXT NULL DEFAULT NULL,
			teacher_comment         TEXT NULL DEFAULT NULL,
			moderator_comment       TEXT NULL DEFAULT NULL,
			status                  VARCHAR(30) NOT NULL DEFAULT 'pending',
			lock_version            INT UNSIGNED NOT NULL DEFAULT 1,
			submitted_at            DATETIME NOT NULL,
			decided_at              DATETIME NULL DEFAULT NULL,
			created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY submission_cycle (submission_id, cycle_id),
			KEY cycle_status (cycle_id, status),
			KEY moderator_status (moderator_id, status),
			KEY course_id (course_id),
			KEY student_id (student_id)
		) $charset_collate;" );
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_institutional_grades (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cycle_id       BIGINT UNSIGNED NOT NULL,
			student_id     BIGINT UNSIGNED NOT NULL,
			course_id      BIGINT UNSIGNED NOT NULL,
			grade          DECIMAL(9,4) NULL DEFAULT NULL,
			scale_code     VARCHAR(60) NOT NULL DEFAULT '',
			status         VARCHAR(20) NOT NULL DEFAULT 'draft',
			revision       INT UNSIGNED NOT NULL DEFAULT 1,
			source_json    LONGTEXT NULL DEFAULT NULL,
			last_actor_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY cycle_student (cycle_id, student_id),
			KEY student_id (student_id),
			KEY course_id (course_id),
			KEY status (status)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_grade_rectifications (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cycle_id        BIGINT UNSIGNED NOT NULL,
			grade_id        BIGINT UNSIGNED NOT NULL,
			student_id      BIGINT UNSIGNED NOT NULL,
			previous_grade  DECIMAL(9,4) NOT NULL,
			proposed_grade  DECIMAL(9,4) NOT NULL,
			reason          TEXT NOT NULL,
			status          VARCHAR(20) NOT NULL DEFAULT 'requested',
			requested_by    BIGINT UNSIGNED NOT NULL,
			decided_by      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			decided_at      DATETIME NULL DEFAULT NULL,
			applied_at      DATETIME NULL DEFAULT NULL,
			created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY cycle_status (cycle_id, status),
			KEY grade_id (grade_id),
			KEY student_id (student_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_gradebook_events (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			actor_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action        VARCHAR(60) NOT NULL,
			object_type   VARCHAR(40) NOT NULL,
			object_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			details_json  LONGTEXT NULL DEFAULT NULL,
			created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY actor_id (actor_id),
			KEY action (action),
			KEY object_ref (object_type, object_id),
			KEY created_at (created_at)
		) $charset_collate;" );

		// Biblioteca académica versionada.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_library_items (
			id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			institution_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			slug               VARCHAR(190) NOT NULL,
			title              VARCHAR(255) NOT NULL,
			description        TEXT NULL DEFAULT NULL,
			resource_type      VARCHAR(30) NOT NULL DEFAULT 'document',
			status             VARCHAR(20) NOT NULL DEFAULT 'draft',
			current_version_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			published_at       DATETIME NULL DEFAULT NULL,
			published_by       BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_by         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY inst_slug (institution_id, slug),
			KEY status_type (status, resource_type),
			KEY current_version_id (current_version_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_library_versions (
			id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			item_id            BIGINT UNSIGNED NOT NULL,
			version_number     INT UNSIGNED NOT NULL,
			attachment_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			content_url        TEXT NULL DEFAULT NULL,
			mime_type          VARCHAR(120) NOT NULL DEFAULT '',
			metadata_json      LONGTEXT NULL DEFAULT NULL,
			checksum_sha256    CHAR(64) NOT NULL,
			change_note        TEXT NULL DEFAULT NULL,
			created_by         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY item_version (item_id, version_number),
			KEY checksum_sha256 (checksum_sha256),
			KEY attachment_id (attachment_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_library_links (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			item_id     BIGINT UNSIGNED NOT NULL,
			link_type   VARCHAR(20) NOT NULL,
			course_id   BIGINT UNSIGNED NOT NULL,
			object_key  VARCHAR(190) NOT NULL DEFAULT '',
			object_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY item_academic_link (item_id, link_type, course_id, object_key, object_id),
			KEY course_type (course_id, link_type),
			KEY object_id (object_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_library_events (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			actor_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action       VARCHAR(60) NOT NULL,
			item_id      BIGINT UNSIGNED NOT NULL,
			details_json LONGTEXT NULL DEFAULT NULL,
			created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY actor_id (actor_id),
			KEY item_action (item_id, action),
			KEY created_at (created_at)
		) $charset_collate;" );
		// Calificaciones finales por alumno/curso (D-002; migra _clms_gradebook_course_{id}).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_gradebook (
			id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			user_id         BIGINT UNSIGNED  NOT NULL,
			course_id       BIGINT UNSIGNED  NOT NULL,
			wp_course_id    BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			final_grade     DECIMAL(5,2)              DEFAULT NULL,
			status          VARCHAR(20)      NOT NULL DEFAULT 'in_progress',
			grade_json      LONGTEXT                  DEFAULT NULL,
			calculated_at   DATETIME                  DEFAULT NULL,
			created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY user_course  (user_id, course_id),
			KEY course_id           (course_id),
			KEY status              (status)
		) $charset_collate;" );

		// Certificados emitidos (D-002; migra _clms_certificate_record_{course_id}).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_certificates (
			id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			user_id           BIGINT UNSIGNED  NOT NULL,
			course_id         BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			program_id        BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			wp_target_id      BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			target_type       VARCHAR(20)      NOT NULL DEFAULT 'course',
			cert_code         VARCHAR(100)     NOT NULL DEFAULT '',
			verification_code VARCHAR(100)     NOT NULL DEFAULT '',
			verification_hash VARCHAR(64)      NOT NULL DEFAULT '',
			status            VARCHAR(20)      NOT NULL DEFAULT 'valid',
			final_grade       DECIMAL(5,2)              DEFAULT NULL,
			progress_pct      TINYINT UNSIGNED NOT NULL DEFAULT 0,
			issued_at         DATETIME                  DEFAULT NULL,
			revoked_at        DATETIME                  DEFAULT NULL,
			meta_json         LONGTEXT                  DEFAULT NULL,
			created_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY cert_code             (cert_code),
			UNIQUE KEY user_target_type      (user_id, target_type, wp_target_id),
			KEY user_id                      (user_id),
			KEY course_id                    (course_id),
			KEY program_id                   (program_id),
			KEY status                       (status),
			KEY verification_code            (verification_code(20))
		) $charset_collate;" );

		// Credenciales verificables: snapshot inmutable, doble control y auditoría encadenada.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_credentials (
			id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			credential_uuid         CHAR(36)        NOT NULL,
			user_id                 BIGINT UNSIGNED NOT NULL,
			target_type             VARCHAR(20)     NOT NULL,
			target_id               BIGINT UNSIGNED NOT NULL,
			cycle_id                BIGINT UNSIGNED NOT NULL DEFAULT 0,
			cert_code               VARCHAR(100)    NOT NULL DEFAULT '',
			verification_token_hash CHAR(64)        NOT NULL,
			status                  VARCHAR(30)     NOT NULL DEFAULT 'valid',
			snapshot_json           LONGTEXT        NOT NULL,
			snapshot_hash           CHAR(64)        NOT NULL,
			template_version        VARCHAR(40)     NOT NULL DEFAULT '1',
			issued_by               BIGINT UNSIGNED NOT NULL DEFAULT 0,
			issued_at               DATETIME        NOT NULL,
			expires_at              DATETIME                 DEFAULT NULL,
			revoked_at              DATETIME                 DEFAULT NULL,
			superseded_by_uuid      CHAR(36)        NOT NULL DEFAULT '',
			created_at              DATETIME        NOT NULL,
			updated_at              DATETIME        NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY credential_uuid (credential_uuid),
			UNIQUE KEY verification_token_hash (verification_token_hash),
			KEY holder_target (user_id, target_type, target_id),
			KEY status (status)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_credential_revocations (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			credential_id BIGINT UNSIGNED NOT NULL,
			category      VARCHAR(40)     NOT NULL,
			reason        TEXT            NOT NULL,
			status        VARCHAR(20)     NOT NULL DEFAULT 'requested',
			requested_by  BIGINT UNSIGNED NOT NULL,
			decided_by    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			requested_at  DATETIME        NOT NULL,
			decided_at    DATETIME                 DEFAULT NULL,
			PRIMARY KEY (id),
			KEY credential_status (credential_id, status),
			KEY requested_by (requested_by)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_credential_events (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			credential_id BIGINT UNSIGNED NOT NULL,
			action        VARCHAR(60)     NOT NULL,
			actor_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			details_json  LONGTEXT                 DEFAULT NULL,
			previous_hash CHAR(64)        NOT NULL DEFAULT '',
			event_hash    CHAR(64)        NOT NULL,
			created_at    DATETIME        NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY event_hash (event_hash),
			KEY credential_id (credential_id)
		) $charset_collate;" );

		// ── /Fase 11b ─────────────────────────────────────────────────────────

		// ── Fase 12C: API Keys para MCP y acceso externo ─────────────────────
		// P3 (6.12.0): gateado por módulo 'mcp'.
		if ( self::module_wants_tables( 'mcp' ) ) {
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_api_keys (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			name       VARCHAR(200)    NOT NULL DEFAULT '',
			key_hash   VARCHAR(64)     NOT NULL,
			key_prefix VARCHAR(8)      NOT NULL,
			scopes     VARCHAR(500)    NOT NULL DEFAULT 'read',
			last_used  DATETIME                 DEFAULT NULL,
			expires_at DATETIME                 DEFAULT NULL,
			is_active  TINYINT(1)      NOT NULL DEFAULT 1,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY key_hash  (key_hash),
			KEY user_id    (user_id),
			KEY key_prefix (key_prefix),
			KEY is_active  (is_active)
		) $charset_collate;" );

		// PT-5 (6.5.5): contador de rate limit por API key/operación/minuto,
		// atómico vía INSERT ... ON DUPLICATE KEY UPDATE — reemplaza el
		// patrón get_transient()+set_transient() de
		// ATORA_API_Key_Service::validate() (lectura-incremento-escritura
		// no atómico, racy bajo concurrencia real; wp_cache_incr()/add()
		// solo son realmente atómicos con un object cache persistente
		// como Redis/Memcached, que no todas las instalaciones tienen).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_api_rate_limit (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			key_id     BIGINT UNSIGNED NOT NULL,
			operation  VARCHAR(20)     NOT NULL,
			minute_key CHAR(12)        NOT NULL,
			requests   INT UNSIGNED    NOT NULL DEFAULT 1,
			PRIMARY KEY (id),
			UNIQUE KEY key_op_minute (key_id, operation, minute_key),
			KEY minute_key (minute_key)
		) $charset_collate;" );
		} // /mcp
		// ── /Fase 12C ─────────────────────────────────────────────────────────

		// PT-6 (6.5.7): contador de rate limit genérico y reutilizable
		// (ATORA_Rate_Limiter::consume()) — mismo patrón atómico que las
		// dos tablas de arriba, para que nuevos usos (Student Assistant,
		// y cualquier futuro límite sensible) no vuelvan a caer en
		// get_transient()+set_transient(). index en window_start para
		// que la limpieza periódica (ATORA_Security_Maintenance) pueda
		// borrar por rango de forma eficiente.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_rate_limit_counters (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			scope            VARCHAR(60)     NOT NULL,
			identifier_hash  CHAR(64)        NOT NULL,
			window_start     INT UNSIGNED    NOT NULL,
			attempts         INT UNSIGNED    NOT NULL DEFAULT 1,
			updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY scope_identifier_window (scope, identifier_hash, window_start),
			KEY window_start (window_start)
		) $charset_collate;" );

		// ── Fase III S9: Badges de gamificación ───────────────────────────────
		// P3 (6.12.0): gateado por módulo 'gamification'.
		if ( self::module_wants_tables( 'gamification' ) ) {
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}clms_badges (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug        VARCHAR(80)     NOT NULL DEFAULT '',
			label       VARCHAR(200)    NOT NULL DEFAULT '',
			icon_emoji  VARCHAR(10)     NOT NULL DEFAULT '🏅',
			description TEXT            NOT NULL,
			criterio    VARCHAR(50)     NOT NULL DEFAULT '' COMMENT 'event_type o condicion',
			threshold   INT UNSIGNED    NOT NULL DEFAULT 1 COMMENT 'cantidad de eventos necesaria',
			is_active   TINYINT(1)      NOT NULL DEFAULT 1,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug),
			KEY criterio (criterio)
		) $charset_collate;" );

		// Insertar 12 badges base (idempotente)
		$badges_table = $wpdb->prefix . 'clms_badges';
		$base_badges  = array(
			array( 'primer_paso',         '🐣', 'Primer Paso',          'Completaste tu primera lección.',       'lesson_completed',    1  ),
			array( 'maratonista',          '🏃', 'Maratonista',          'Completaste 10 lecciones.',             'lesson_completed',    10 ),
			array( 'imparable',            '🚀', 'Imparable',            'Completaste 25 lecciones.',             'lesson_completed',    25 ),
			array( 'entrega_enviada',      '📬', 'Entrega Enviada',      'Enviaste tu primera entrega.',          'submission_sent',     1  ),
			array( 'evaluacion_aprobada',  '✅', 'Evaluación Aprobada',  'Aprobaste tu primera evaluación.',      'evaluation_passed',   1  ),
			array( 'racha_semanal',        '🔥', 'Racha Semanal',        '7 días consecutivos de actividad.',     'daily_login',         7  ),
			array( 'colaborador',          '🤝', 'Colaborador',          'Completaste una revisión por pares.',   'peer_review_done',    1  ),
			array( 'primer_curso',         '🎓', 'Primer Curso',         'Completaste un curso completo.',        'course_completed',    1  ),
			array( 'explorador',           '🗺', 'Explorador',           'Te matriculaste en 3 cursos.',          'course_enrolled',     3  ),
			array( 'feedback_loop',        '💡', 'Feedback Loop',        'Completaste un ciclo de práctica IA.',  'ai_practice_done',    1  ),
			array( 'puntuador',            '⭐', 'Puntuador',            'Acumulaste 500 puntos.',                'points_milestone',    500 ),
			array( 'leyenda',              '🏆', 'Leyenda',              'Acumulaste 2000 puntos.',               'points_milestone',    2000 ),
		);

		$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$badges_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 0 === $existing ) {
			foreach ( $base_badges as $b ) {
				$wpdb->insert(
					$badges_table,
					array(
						'slug'        => sanitize_key( $b[0] ),
						'icon_emoji'  => $b[1],
						'label'       => sanitize_text_field( $b[2] ),
						'description' => sanitize_text_field( $b[3] ),
						'criterio'    => sanitize_key( $b[4] ),
						'threshold'   => absint( $b[5] ),
					),
					array( '%s', '%s', '%s', '%s', '%s', '%d' )
				);
			}
		}
		} // /gamification
		// ── /Fase III S9 ──────────────────────────────────────────────────────

		// ── Secciones académicas (atora-cohort-sections / DC-1) ──────────────
		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_sections (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_course_id  BIGINT UNSIGNED NOT NULL,
			cohort_id     BIGINT UNSIGNED          DEFAULT NULL,
			title         VARCHAR(200)    NOT NULL DEFAULT '',
			schedule_json LONGTEXT                 DEFAULT NULL,
			capacity      INT UNSIGNED    NOT NULL DEFAULT 0,
			status        VARCHAR(20)     NOT NULL DEFAULT 'active',
			start_date    DATE                     DEFAULT NULL,
			end_date      DATE                     DEFAULT NULL,
			meta_json     LONGTEXT                 DEFAULT NULL,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY wp_course_id (wp_course_id),
			KEY cohort_id    (cohort_id),
			KEY status       (status)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_section_teachers (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			section_id BIGINT UNSIGNED NOT NULL,
			user_id    BIGINT UNSIGNED NOT NULL,
			role       VARCHAR(20)     NOT NULL DEFAULT 'lead',
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY section_user (section_id, user_id),
			KEY user_id (user_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_section_students (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			section_id BIGINT UNSIGNED NOT NULL,
			user_id    BIGINT UNSIGNED NOT NULL,
			status     VARCHAR(30)     NOT NULL DEFAULT 'active',
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY section_user (section_id, user_id),
			KEY user_id (user_id)
		) $charset_collate;" );
		// ── /Secciones académicas ─────────────────────────────────────────────

		// ── Fase IV S13: Webhooks ─────────────────────────────────────────────
		// P3 (6.12.0): gateado por módulo 'webhooks'.
		if ( self::module_wants_tables( 'webhooks' ) ) {
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_webhooks (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(60)     NOT NULL DEFAULT '',
			url        VARCHAR(500)    NOT NULL DEFAULT '',
			secret     VARCHAR(64)     NOT NULL DEFAULT '',
			is_active  TINYINT(1)      NOT NULL DEFAULT 1,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY event_type (event_type),
			KEY is_active  (is_active)
		) $charset_collate;" );
		} // /webhooks
		// ── /Fase IV S13 ──────────────────────────────────────────────────────

		// ── P6 (6.13.0): Live streaming — sesiones y asistencia ────────────────
		// Gateado por módulo 'live-streaming'.
		// C0.2 (6.13.1): external_id nullable — varios proveedores sin
		// external_id (custom/YouTube/Teams sin webhook) deben poder
		// coexistir bajo el UNIQUE KEY; MySQL permite varios NULL, no
		// varios ''.
		// C0.3 (6.13.1): created_by_user_id — necesario para Meet
		// (restricción meetings.space.created); sustituye el
		// update_option() por-clase que usaba Provider_Meet antes.
		if ( self::module_wants_tables( 'live-streaming' ) ) {
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_live_sessions (
			id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lesson_id           BIGINT UNSIGNED NOT NULL DEFAULT 0,
			course_id           BIGINT UNSIGNED NOT NULL DEFAULT 0,
			provider            VARCHAR(20)     NOT NULL DEFAULT 'zoom' COMMENT 'zoom|meet|teams|youtube|custom',
			external_id         VARCHAR(120)    NULL     DEFAULT NULL,
			join_url            VARCHAR(500)    NOT NULL DEFAULT '',
			start_datetime      DATETIME                 DEFAULT NULL,
			duration_minutes    INT UNSIGNED    NOT NULL DEFAULT 60,
			timezone            VARCHAR(60)     NOT NULL DEFAULT 'UTC',
			recording_url       VARCHAR(500)    NOT NULL DEFAULT '',
			status              VARCHAR(20)     NOT NULL DEFAULT 'scheduled' COMMENT 'scheduled|live|ended|cancelled',
			created_by_user_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY lesson_id (lesson_id),
			KEY course_id (course_id),
			KEY start_datetime (start_datetime),
			UNIQUE KEY provider_external (provider, external_id)
		) $charset_collate;" );

		// PT-6.12: no 'atora_live_attendance' — 'atora_attendance' a
		// propósito, la columna `source` (zoom|meet|teams|manual|qr) es lo
		// que permite reusar esta misma tabla para asistencia presencial
		// más adelante sin migrar nada.
		// C0.1 (6.13.1): external_participant_id (`participant` de Meet o
		// UUID de Zoom) — clave estable de deduplicación si alguien se
		// reconecta a mitad de clase, y lo que desbloquea que varios
		// anónimos (user_id = 0) de la misma sesión no colapsen en una
		// sola fila bajo el UNIQUE KEY. display_name para mostrar
		// "No identificado — <nombre>" en el metabox (C4).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_attendance (
			id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id                  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			session_id               BIGINT UNSIGNED NOT NULL DEFAULT 0,
			external_participant_id  VARCHAR(160)    NOT NULL DEFAULT '',
			display_name             VARCHAR(200)    NOT NULL DEFAULT '',
			course_id                BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source                   VARCHAR(20)     NOT NULL DEFAULT 'manual' COMMENT 'zoom|meet|teams|manual|qr',
			joined_at                DATETIME                 DEFAULT NULL,
			left_at                  DATETIME                 DEFAULT NULL,
			duration_seconds         INT UNSIGNED    NOT NULL DEFAULT 0,
			status                   VARCHAR(20)     NOT NULL DEFAULT 'ausente' COMMENT 'presente|tarde|ausente|justificado',
			recorded_by              BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY course_user (course_id, user_id),
			UNIQUE KEY session_participant (session_id, user_id, external_participant_id)
		) $charset_collate;" );
		} // /live-streaming
		// ── /P6 (6.13.0) ─────────────────────────────────────────────────────

		// ── P10.4 (6.13.0): Google Drive — referencias de archivos ──────────────
		// Gateado por módulo 'google'.
		if ( self::module_wants_tables( 'google' ) ) {
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_google_drive_files (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
			context_type  VARCHAR(40)     NOT NULL DEFAULT '',
			context_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			file_id       VARCHAR(120)    NOT NULL DEFAULT '',
			file_name     VARCHAR(255)    NOT NULL DEFAULT '',
			mime_type     VARCHAR(120)    NOT NULL DEFAULT '',
			web_view_link VARCHAR(500)    NOT NULL DEFAULT '',
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY context (context_type, context_id),
			KEY user_id (user_id)
		) $charset_collate;" );
		} // /google
		// ── /P10.4 (6.13.0) ──────────────────────────────────────────────────

		// ── PT-1 (6.6.0): Planes de seguimiento (CRM académico) ─────────────────
		// section_ids/stage_filter como JSON en un LONGTEXT, no una tabla
		// puente — el resto del esquema ya usa este mismo patrón para config
		// de alcance acotado por fila propia (atora_sections.schedule_json/
		// meta_json), y a diferencia de atora_crm_campaign_recipients (que sí
		// necesita JOIN/agregación SQL por fila individual), acá el conjunto
		// de secciones/etapas es config del plan mismo, leída siempre junto
		// con el resto de la fila — no hay necesidad de JOIN.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_followup_plans (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			teacher_id      BIGINT UNSIGNED NOT NULL,
			domain          VARCHAR(20)     NOT NULL DEFAULT 'academic',
			name            VARCHAR(190)    NOT NULL DEFAULT '',
			template_key    VARCHAR(60)              DEFAULT NULL,
			section_ids     LONGTEXT                 DEFAULT NULL,
			stage_filter    LONGTEXT                 DEFAULT NULL,
			domain_config   LONGTEXT                 DEFAULT NULL,
			recurrence_rule VARCHAR(255)    NOT NULL DEFAULT '',
			action_type     VARCHAR(30)     NOT NULL DEFAULT 'checkin',
			active          TINYINT(1)      NOT NULL DEFAULT 1,
			end_date        DATE                     DEFAULT NULL,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY teacher_id (teacher_id),
			KEY active     (active),
			KEY domain     (domain)
		) $charset_collate;" );

		// Estado propio de UNA ocurrencia puntual (fila de
		// atora_calendar_events con followup_plan_id poblado) — saltar una
		// ocurrencia o excluir un estudiante de ella nunca toca el plan ni
		// la serie completa, solo esta fila.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_followup_occurrence_state (
			id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id          BIGINT UNSIGNED NOT NULL,
			skipped           TINYINT(1)      NOT NULL DEFAULT 0,
			excluded_user_ids LONGTEXT                 DEFAULT NULL,
			created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY event_id (event_id)
		) $charset_collate;" );

		// Registro de "marcado como contactado" por estudiante/ocurrencia —
		// deliberadamente SIN ninguna columna de etapa: registrar un
		// contacto acá nunca mueve al estudiante en Student_Followup_Service,
		// ver el principio de separación de responsabilidades de la OT.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_followup_contacts (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id     BIGINT UNSIGNED NOT NULL,
			user_id      BIGINT UNSIGNED NOT NULL,
			contacted_by BIGINT UNSIGNED NOT NULL,
			contacted_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY event_user (event_id, user_id),
			KEY user_id (user_id)
		) $charset_collate;" );
		// ── /PT-1 (6.6.0) ────────────────────────────────────────────────────

		return self::all_tables_exist();
	}

	/**
	 * Verifica que todas las tablas del esquema v5 existan en la base de datos.
	 *
	 * @return bool
	 */
	private static function all_tables_exist(): bool {
		global $wpdb;

		$prefix = (string) $wpdb->prefix;

		foreach ( self::get_tables() as $table ) {
			// P3 (6.12.0): una tabla de un módulo inactivo (perfil docente/
			// institución sin CRM, comercio, etc.) legítimamente no existe
			// todavía — no debe contar como esquema incompleto, o install()
			// nunca marcaría SCHEMA_VERSION como al día y create_tables()
			// se re-ejecutaría en cada carga.
			$bare_name = 0 === strpos( $table, $prefix ) ? substr( $table, strlen( $prefix ) ) : $table;
			$owner     = self::table_owner_module( $bare_name );
			if ( null !== $owner && ! self::module_wants_tables( $owner ) ) {
				continue;
			}

			$like = $wpdb->esc_like( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
			if ( $exists !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Módulo dueño de una tabla (sin prefijo de $wpdb), para que
	 * all_tables_exist() no exija tablas de módulos inactivos. null para
	 * tablas core/no mapeadas — siempre se exigen, igual que antes de P3.
	 *
	 * @param string $bare_table Nombre de tabla sin el prefijo de $wpdb.
	 * @return string|null
	 */
	private static function table_owner_module( string $bare_table ): ?string {
		static $map = null;
		if ( null === $map ) {
			$map = array();
			foreach ( array(
				'affiliates'   => array( 'atora_affiliates', 'atora_affiliate_clicks', 'atora_affiliate_commissions' ),
				'calendar'     => array( 'atora_calendar_events', 'atora_calendar_bookings', 'atora_calendar_sync' ),
				'email-engine' => array( 'atora_email_queue', 'atora_email_templates', 'atora_email_preferences', 'atora_email_consent_log', 'atora_email_analytics', 'atora_email_events' ),
				'newsletter'   => array( 'atora_newsletters' ),
				'analytics'    => array( 'atora_user_engagement', 'atora_form_entries', 'atora_form_throttle' ),
				'mcp'          => array( 'atora_api_keys', 'atora_api_rate_limit' ),
				'messaging'    => array( 'atora_message_queue', 'atora_message_log', 'atora_telegram_links' ),
				'crm'          => array( 'atora_conversations', 'atora_conversation_messages', 'atora_contacts', 'atora_contact_tags', 'atora_contact_activities', 'atora_contact_notes', 'atora_companies', 'atora_crm_lists', 'atora_contact_list_pivot' ),
				'automation'   => array( 'atora_automations', 'atora_automation_queue', 'atora_automation_execution_log' ),
				'commerce'     => array( 'atora_abandoned_carts', 'atora_url_store', 'atora_url_clicks' ),
				'gamification'   => array( 'clms_badges' ),
				'webhooks'       => array( 'atora_webhooks' ),
				'live-streaming' => array( 'atora_live_sessions', 'atora_attendance' ),
				'google'         => array( 'atora_google_drive_files' ),
			) as $slug => $tables ) {
				foreach ( $tables as $t ) {
					$map[ $t ] = $slug;
				}
			}
		}

		return $map[ $bare_table ] ?? null;
	}

	/**
	 * Lista única de tablas v5.
	 *
	 * @return array<int,string>
	 */
	private static function get_tables(): array {
		global $wpdb;

		return array(
			// Security.
			"{$wpdb->prefix}atora_2fa_tokens",
			"{$wpdb->prefix}atora_trusted_devices",
			// Gradebook institucional.
			"{$wpdb->prefix}atora_academic_periods",
			"{$wpdb->prefix}atora_grading_scales",
			"{$wpdb->prefix}atora_gradebook_cycles",
			"{$wpdb->prefix}atora_grade_moderations",
			"{$wpdb->prefix}atora_institutional_grades",
			"{$wpdb->prefix}atora_grade_rectifications",
			"{$wpdb->prefix}atora_gradebook_events",
			// Biblioteca académica.
			"{$wpdb->prefix}atora_library_items",
			"{$wpdb->prefix}atora_library_versions",
			"{$wpdb->prefix}atora_library_links",
			"{$wpdb->prefix}atora_library_events",
			// Affiliates.
			"{$wpdb->prefix}atora_affiliates",
			"{$wpdb->prefix}atora_affiliate_clicks",
			"{$wpdb->prefix}atora_affiliate_commissions",
			// Calendar.
			"{$wpdb->prefix}atora_calendar_events",
			"{$wpdb->prefix}atora_calendar_bookings",
			"{$wpdb->prefix}atora_calendar_sync",
			// Planes de seguimiento (PT-1, 6.6.0).
			"{$wpdb->prefix}atora_followup_plans",
			"{$wpdb->prefix}atora_followup_occurrence_state",
			"{$wpdb->prefix}atora_followup_contacts",
			// Email Engine.
			"{$wpdb->prefix}atora_email_queue",
			"{$wpdb->prefix}atora_email_templates",
			"{$wpdb->prefix}atora_email_preferences",
			"{$wpdb->prefix}atora_email_consent_log",
			"{$wpdb->prefix}atora_email_analytics",
			"{$wpdb->prefix}atora_email_events",
			// Newsletter.
			"{$wpdb->prefix}atora_newsletters",
			// Analytics.
			"{$wpdb->prefix}atora_user_engagement",
			"{$wpdb->prefix}atora_form_entries",
			"{$wpdb->prefix}atora_form_throttle",
			// PT-5 (6.5.5): atora_api_keys nunca se había agregado a esta
			// lista (hallazgo de la auditoría de esquema de este sprint)
			// — all_tables_exist() no la verificaba, así que un fallo
			// silencioso al crearla no habría impedido que install()
			// marcara el esquema como completo.
			"{$wpdb->prefix}atora_api_keys",
			"{$wpdb->prefix}atora_api_rate_limit",
			"{$wpdb->prefix}atora_rate_limit_counters",
			// Messaging.
			"{$wpdb->prefix}atora_message_queue",
			"{$wpdb->prefix}atora_message_log",
			"{$wpdb->prefix}atora_telegram_links",
			"{$wpdb->prefix}atora_conversations",
			"{$wpdb->prefix}atora_conversation_messages",
			// CRM.
			"{$wpdb->prefix}atora_contacts",
			"{$wpdb->prefix}atora_contact_tags",
			"{$wpdb->prefix}atora_contact_activities",
			"{$wpdb->prefix}atora_contact_notes",
			// Automation.
			"{$wpdb->prefix}atora_automations",
			"{$wpdb->prefix}atora_automation_queue",
			"{$wpdb->prefix}atora_automation_execution_log",
			// Fase 9
			"{$wpdb->prefix}atora_companies",
			"{$wpdb->prefix}atora_crm_lists",
			"{$wpdb->prefix}atora_contact_list_pivot",
			// Secciones académicas
			"{$wpdb->prefix}atora_sections",
			"{$wpdb->prefix}atora_section_teachers",
			"{$wpdb->prefix}atora_section_students",
		);
	}

	/**
	 * Elimina todas las tablas v5 (usado en uninstall).
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$tables = self::get_tables();

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( self::OPTION_KEY );
	}
}
