<?php
/**
 * Tenancy CLI — X-01
 *
 * `wp atora tenancy rollback [--yes]`
 * `wp atora tenancy migrate [--dry-run] [--yes]`
 * `wp atora tenancy verify`
 *
 * Rollback limpio (aditivo): elimina solo el esquema agregado por X-01/E-10
 * sin tocar posts/postmeta/usermeta.
 *
 * @package ATORA_LMS\Tenancy
 * @since   6.26.3
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }

class Tenancy_CLI {

	const OPT_PREV_SCHEMA = 'atora_tenancy_prev_v5_schema_version';

	public static function init(): void {
		\WP_CLI::add_command( 'atora tenancy rollback', array( __CLASS__, 'rollback' ) );
		\WP_CLI::add_command( 'atora tenancy migrate', array( __CLASS__, 'migrate' ) );
		\WP_CLI::add_command( 'atora tenancy verify', array( __CLASS__, 'verify' ) );
	}

	/**
	 * Rollback limpio del esquema de tenencia/delegación.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : No pedir confirmación.
	 *
	 * ## EXAMPLES
	 *
	 *     wp atora tenancy rollback --yes
	 *
	 * @param array $args       Argumentos posicionales (sin uso).
	 * @param array $assoc_args Argumentos con nombre.
	 */
	public static function rollback( array $args, array $assoc_args ): void {
		global $wpdb;

		if ( ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm( 'Esto eliminará tablas/columnas de tenencia/delegación. ¿Continuar?', $assoc_args );
		}

		$tables = array(
			$wpdb->prefix . 'atora_instructor_delegations',
			$wpdb->prefix . 'atora_tenancy_audit',
			$wpdb->prefix . 'atora_cohort_courses',
			$wpdb->prefix . 'atora_cohort_members',
			$wpdb->prefix . 'atora_cohorts',
			$wpdb->prefix . 'atora_institution_members',
			$wpdb->prefix . 'atora_institutions',
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		$column_tables = array(
			$wpdb->prefix . 'atora_programs'            => array( 'institution_id' ),
			$wpdb->prefix . 'atora_courses'             => array( 'institution_id', 'scope' ),
			$wpdb->prefix . 'atora_enrollments'         => array( 'institution_id', 'cohort_id' ),
			$wpdb->prefix . 'atora_program_enrollments' => array( 'institution_id', 'cohort_id' ),
			$wpdb->prefix . 'clms_invitations'          => array( 'institution_id', 'cohort_id', 'batch_id' ),
		);

		foreach ( $column_tables as $table => $columns ) {
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $exists !== $table ) { continue; }

			foreach ( (array) $columns as $column ) {
				$column = sanitize_key( (string) $column );
				if ( '' === $column ) { continue; }

				$has = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
						 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
						$table,
						$column
					)
				) > 0;

				if ( $has ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
					$wpdb->query( "ALTER TABLE {$table} DROP COLUMN {$column}" );
				}
			}
		}

		delete_option( 'atora_default_institution' );
		delete_option( 'atora_cohort_source' );

		$prev = (string) get_option( self::OPT_PREV_SCHEMA, '' );
		if ( '' !== $prev ) {
			update_option( \ATORA\V5_Installer::OPTION_KEY, $prev, false );
			delete_option( self::OPT_PREV_SCHEMA );
			\WP_CLI::log( "Restaurado atora_v5_schema_version a: {$prev}" );
		}

		\WP_CLI::success( 'Rollback de tenencia/delegación completado.' );
	}

	/**
	 * Migra datos legacy a tablas de tenencia (cohortes + backfill institution_id).
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : No escribe; solo reporta el plan.
	 *
	 * [--yes]
	 * : No pedir confirmación (cuando no es dry-run).
	 *
	 * ## EXAMPLES
	 *
	 *     wp atora tenancy migrate --dry-run
	 *     wp atora tenancy migrate --yes
	 *
	 * @param array $args       Argumentos posicionales (sin uso).
	 * @param array $assoc_args Argumentos con nombre.
	 */
	public static function migrate( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );

		$plan = self::build_migration_plan();
		\WP_CLI::log( 'Plan:' );
		foreach ( $plan as $line ) {
			\WP_CLI::log( ' - ' . $line );
		}

		if ( $dry_run ) {
			\WP_CLI::success( 'Dry-run: no se escribió nada.' );
			return;
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm( 'Esto escribirá en tablas de tenencia. ¿Continuar?', $assoc_args );
		}

		$default_inst = Institution_Service::ensure_default_institution();
		if ( $default_inst <= 0 ) {
			\WP_CLI::error( 'No se pudo asegurar la institución por defecto.' );
			return;
		}
		update_option( 'atora_default_institution', $default_inst, false );

		$academy_report = self::migrate_legacy_academy_context( $default_inst, $assoc_args );
		if ( is_wp_error( $academy_report ) ) {
			\WP_CLI::error( $academy_report->get_error_message() );
			return;
		}
		if ( is_array( $academy_report ) && ! empty( $academy_report['notes'] ) ) {
			foreach ( (array) $academy_report['notes'] as $line ) {
				\WP_CLI::log( (string) $line );
			}
		}

		$backfilled = Institution_Service::backfill_institution_ids( $default_inst );
		\WP_CLI::log( 'Backfill institution_id: ' . wp_json_encode( $backfilled ) );

		$cohorts = Cohort_Migrator::migrate_from_postmeta( $default_inst );
		\WP_CLI::log( 'Cohortes migradas: ' . absint( $cohorts['cohorts'] ?? 0 ) );
		\WP_CLI::log( 'Miembros migrados: ' . absint( $cohorts['members'] ?? 0 ) );
		\WP_CLI::log( 'Cursos vinculados: ' . absint( $cohorts['courses'] ?? 0 ) );

		$finalized = Institution_Service::finalize_institution_columns();
		\WP_CLI::log( 'Finalize institution_id (no default): ' . wp_json_encode( $finalized ) );

		update_option( 'atora_cohort_source', 'tables', false );

		\WP_CLI::success( 'Migración completada.' );
	}

	/**
	 * Verifica paridad básica entre legacy (postmeta) y tablas para cohortes.
	 *
	 * @param array $args       Argumentos posicionales (sin uso).
	 * @param array $assoc_args Argumentos con nombre.
	 */
	public static function verify( array $args, array $assoc_args ): void {
		$legacy_errors = self::verify_no_legacy_tenant_leftovers();
		if ( ! empty( $legacy_errors ) ) {
			foreach ( $legacy_errors as $err ) {
				\WP_CLI::log( 'ERROR: ' . $err );
			}
			\WP_CLI::error( 'Verify falló: quedan rastros legacy del inquilino.' );
			return;
		}

		$report = Cohort_Migrator::verify_parity();
		if ( ! empty( $report['errors'] ) ) {
			foreach ( (array) $report['errors'] as $err ) {
				\WP_CLI::log( 'ERROR: ' . $err );
			}
			\WP_CLI::error( 'Verify falló.' );
			return;
		}

		\WP_CLI::success( 'Verify OK: cero divergencias detectadas (chequeo básico).' );
	}

	private static function build_migration_plan(): array {
		$default = (int) get_option( 'atora_default_institution', 0 );
		$lines   = array();
		$lines[] = $default > 0 ? "Institución por defecto ya existe: {$default}" : 'Crear/asegurar institución por defecto.';
		$lines[] = 'Reconciliar legacy de inquilino (gradebook/biblioteca + option/usermeta legacy) hacia institution_id.';
		$lines[] = 'Backfill institution_id en atora_programs/courses/enrollments/program_enrollments/clms_invitations (si está en 0).';
		$lines[] = 'Migrar cohortes desde lm_cohort postmeta a atora_cohorts/atora_cohort_members/atora_cohort_courses.';
		$lines[] = 'Set option atora_cohort_source=tables.';
		return $lines;
	}

	/**
	 * Migra contexto legacy de "academy" hacia instituciones canónicas.
	 *
	 * - Si se detectan múltiples IDs distintos, exige `--academy-map=OLD:NEW,...`
	 *   para no adivinar el mapeo.
	 * - Migra option y usermeta legacy a atora_institution_members.
	 *
	 * @param int   $default_inst Institución por defecto (target para mapeo trivial).
	 * @param array $assoc_args   Args de WP-CLI.
	 * @return array{notes:array<int,string>}|\WP_Error
	 */
	private static function migrate_legacy_academy_context( int $default_inst, array $assoc_args ) {
		global $wpdb;

		$default_inst = absint( $default_inst );
		if ( $default_inst <= 0 ) {
			return new \WP_Error( 'atora_tenancy_missing_default', 'Institución por defecto inválida.' );
		}

		$legacy_col   = 'academy' . '_id';
		$legacy_opt   = 'atora_active_' . $legacy_col;
		$legacy_meta  = '_atora_' . $legacy_col;
		$mapping_arg  = isset( $assoc_args['academy-map'] ) ? sanitize_text_field( (string) $assoc_args['academy-map'] ) : '';

		$notes = array();

		// 1) Detectar IDs legacy presentes.
		$counts = self::collect_legacy_tenant_ids( $legacy_col, $legacy_opt, $legacy_meta );
		$ids    = array_keys( $counts );

		if ( count( $ids ) >= 2 && '' === $mapping_arg ) {
			$lines = array();
			foreach ( $counts as $id => $count ) {
				$lines[] = sprintf( '%d => %d', absint( $id ), absint( $count ) );
			}
			return new \WP_Error(
				'atora_tenancy_map_required',
				"Se detectaron múltiples IDs legacy del inquilino. Proveer mapeo explícito con --academy-map=OLD:NEW,...\n" .
				'Detectados (id => ocurrencias): ' . implode( ', ', $lines )
			);
		}

		$map = array();
		if ( count( $ids ) <= 1 ) {
			if ( 1 === count( $ids ) ) {
				$map[ absint( $ids[0] ) ] = $default_inst;
			}
		} else {
			$map = self::parse_id_map_arg( $mapping_arg );
			if ( empty( $map ) ) {
				return new \WP_Error( 'atora_tenancy_bad_map', 'Parámetro --academy-map inválido.' );
			}
			foreach ( $ids as $legacy_id ) {
				$legacy_id = absint( $legacy_id );
				if ( ! isset( $map[ $legacy_id ] ) ) {
					return new \WP_Error( 'atora_tenancy_bad_map', 'El mapeo no cubre el ID legacy: ' . $legacy_id );
				}
			}
			foreach ( $map as $legacy_id => $institution_id ) {
				$institution_id = absint( $institution_id );
				if ( $institution_id <= 0 ) {
					return new \WP_Error( 'atora_tenancy_bad_map', 'institution_id inválido en mapeo.' );
				}
				$exists = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT 1 FROM {$wpdb->prefix}atora_institutions WHERE id = %d LIMIT 1",
						$institution_id
					)
				);
				if ( 1 !== $exists ) {
					return new \WP_Error( 'atora_tenancy_bad_map', 'institution_id no existe: ' . $institution_id );
				}
			}
		}

		// 2) Aplicar mapeo en tablas reconciliadas (y backfill de 0 a default).
		$tables = array(
			'atora_academic_periods',
			'atora_gradebook_cycles',
			'atora_grading_scales',
			'atora_library_items',
		);

		foreach ( $tables as $short ) {
			$table = $wpdb->prefix . $short;
			if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				continue;
			}

			$tenant_col = self::resolve_tenant_column_for_table( $table, $legacy_col );
			if ( '' === $tenant_col ) {
				continue;
			}

			$affected = (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET {$tenant_col} = %d WHERE {$tenant_col} = 0",
					$default_inst
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $affected > 0 ) {
				$notes[] = sprintf( '%s: %d filas con %s=0 → %d', $short, $affected, $tenant_col, $default_inst );
			}

			foreach ( $map as $legacy_id => $institution_id ) {
				$legacy_id      = absint( $legacy_id );
				$institution_id = absint( $institution_id );
				if ( $legacy_id <= 0 || $institution_id <= 0 || $legacy_id === $institution_id ) {
					continue;
				}

				$affected = (int) $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET {$tenant_col} = %d WHERE {$tenant_col} = %d",
						$institution_id,
						$legacy_id
					)
				); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

				if ( $affected > 0 ) {
					$notes[] = sprintf( '%s: %d filas %s=%d → %d', $short, $affected, $tenant_col, $legacy_id, $institution_id );
				}
			}
		}

		// 3) Migrar option legacy a institución por defecto (si aplica).
		$opt_value = absint( get_option( $legacy_opt, 0 ) );
		if ( $opt_value > 0 ) {
			$mapped = isset( $map[ $opt_value ] ) ? absint( $map[ $opt_value ] ) : $default_inst;
			if ( absint( get_option( 'atora_default_institution', 0 ) ) <= 0 ) {
				update_option( 'atora_default_institution', $mapped, false );
				$notes[] = sprintf( 'Option atora_default_institution establecido a %d (desde %s).', $mapped, $legacy_opt );
			}
			delete_option( $legacy_opt );
			$notes[] = sprintf( 'Option legacy eliminada: %s', $legacy_opt );
		}

		// 4) Migrar usermeta legacy a atora_institution_members.
		$members = $wpdb->prefix . 'atora_institution_members';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $members ) ) ) === $members ) {
			foreach ( $map as $legacy_id => $institution_id ) {
				$legacy_id      = absint( $legacy_id );
				$institution_id = absint( $institution_id );
				if ( $legacy_id <= 0 || $institution_id <= 0 ) {
					continue;
				}

				$sql = $wpdb->prepare(
					"INSERT INTO {$members} (institution_id, user_id, role, status)
					 SELECT %d, user_id, %s, %s
					   FROM {$wpdb->usermeta}
					  WHERE meta_key = %s AND meta_value = %s
					 ON DUPLICATE KEY UPDATE status = VALUES(status)",
					$institution_id,
					'student',
					'active',
					$legacy_meta,
					(string) $legacy_id
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $sql );
			}
		}

		// Eliminar la meta legacy para evitar doble fuente.
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
				$legacy_meta
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $deleted > 0 ) {
			$notes[] = sprintf( 'Usermeta legacy eliminada: %s (%d filas).', $legacy_meta, $deleted );
		}

		return array( 'notes' => $notes );
	}

	/**
	 * Recolecta IDs legacy encontrados en tablas reconciliadas + option + usermeta.
	 *
	 * @param string $legacy_col  Nombre legacy de columna.
	 * @param string $legacy_opt  Option legacy.
	 * @param string $legacy_meta Usermeta legacy.
	 * @return array<int,int> legacy_id => ocurrencias
	 */
	private static function collect_legacy_tenant_ids( string $legacy_col, string $legacy_opt, string $legacy_meta ): array {
		global $wpdb;

		$out = array();
		$tables = array(
			'atora_academic_periods',
			'atora_gradebook_cycles',
			'atora_grading_scales',
			'atora_library_items',
		);

		foreach ( $tables as $short ) {
			$table = $wpdb->prefix . $short;
			if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				continue;
			}

			$tenant_col = self::resolve_tenant_column_for_table( $table, $legacy_col );
			if ( '' === $tenant_col ) {
				continue;
			}

			$rows = (array) $wpdb->get_results(
				"SELECT {$tenant_col} AS legacy_id, COUNT(*) AS c
				   FROM {$table}
				  GROUP BY {$tenant_col}",
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$id = absint( $row['legacy_id'] ?? 0 );
				if ( $id <= 0 ) { continue; }
				$out[ $id ] = ( $out[ $id ] ?? 0 ) + absint( $row['c'] ?? 0 );
			}
		}

		$opt_val = absint( get_option( $legacy_opt, 0 ) );
		if ( $opt_val > 0 ) {
			$out[ $opt_val ] = ( $out[ $opt_val ] ?? 0 ) + 1;
		}

		$meta_rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS legacy_id, COUNT(*) AS c
				   FROM {$wpdb->usermeta}
				  WHERE meta_key = %s
				  GROUP BY meta_value",
				$legacy_meta
			),
			ARRAY_A
		);
		foreach ( $meta_rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$id = absint( $row['legacy_id'] ?? 0 );
			if ( $id <= 0 ) { continue; }
			$out[ $id ] = ( $out[ $id ] ?? 0 ) + absint( $row['c'] ?? 0 );
		}

		ksort( $out );
		return $out;
	}

	/**
	 * Determina el nombre de columna de inquilino disponible en una tabla.
	 *
	 * @param string $table      Tabla completa.
	 * @param string $legacy_col Columna legacy.
	 * @return string 'institution_id' o legacy_col o '' si ninguna.
	 */
	private static function resolve_tenant_column_for_table( string $table, string $legacy_col ): string {
		global $wpdb;

		$has = static function( string $col ) use ( $wpdb, $table ): bool {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
					 WHERE TABLE_SCHEMA = DATABASE()
					   AND TABLE_NAME   = %s
					   AND COLUMN_NAME  = %s",
					$table,
					$col
				)
			) > 0;
		};

		if ( $has( 'institution_id' ) ) {
			return 'institution_id';
		}
		if ( $has( $legacy_col ) ) {
			return $legacy_col;
		}
		return '';
	}

	/**
	 * Parsea `OLD:NEW,OLD2:NEW2` en un mapa.
	 *
	 * @param string $value String.
	 * @return array<int,int>
	 */
	private static function parse_id_map_arg( string $value ): array {
		$value = trim( (string) $value );
		if ( '' === $value ) { return array(); }

		$map = array();
		$pairs = array_filter( array_map( 'trim', explode( ',', $value ) ) );
		foreach ( $pairs as $pair ) {
			$parts = array_map( 'trim', explode( ':', (string) $pair ) );
			if ( 2 !== count( $parts ) ) { continue; }
			$from = absint( $parts[0] );
			$to   = absint( $parts[1] );
			if ( $from <= 0 || $to <= 0 ) { continue; }
			$map[ $from ] = $to;
		}

		ksort( $map );
		return $map;
	}

	/**
	 * Verifica que no queden rastros legacy del inquilino en esquema o código.
	 *
	 * @return array<int,string> Lista de errores.
	 */
	private static function verify_no_legacy_tenant_leftovers(): array {
		$errors = array();

		$legacy_col = 'academy' . '_id';
		$errors = array_merge( $errors, self::verify_no_legacy_column_in_schema( $legacy_col ) );
		$errors = array_merge( $errors, self::verify_no_legacy_string_in_code( $legacy_col ) );

		return $errors;
	}

	/**
	 * Falla si queda cualquier columna legacy en la base.
	 *
	 * @param string $legacy_col Columna legacy.
	 * @return array<int,string>
	 */
	private static function verify_no_legacy_column_in_schema( string $legacy_col ): array {
		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE()
				   AND COLUMN_NAME = %s
				 ORDER BY TABLE_NAME ASC",
				$legacy_col
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$tables = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$name = (string) ( $row['TABLE_NAME'] ?? '' );
			if ( '' !== $name ) {
				$tables[] = $name;
			}
		}

		$tables = array_values( array_unique( $tables ) );
		return array(
			'Queda columna legacy en esquema: ' . $legacy_col . ' (tablas: ' . implode( ', ', array_slice( $tables, 0, 20 ) ) . ')',
		);
	}

	/**
	 * Falla si el string legacy aparece en el código fuera de la envoltura/compat.
	 *
	 * @param string $legacy_col Columna legacy.
	 * @return array<int,string>
	 */
	private static function verify_no_legacy_string_in_code( string $legacy_col ): array {
		$needle = $legacy_col;

		$base = defined( 'ATORA_LMS_DIR' ) ? (string) ATORA_LMS_DIR : dirname( __DIR__, 3 ) . '/';
		$base = rtrim( $base, '/' ) . '/';

		$allowed = array(
			'modules/crm-v2/class-academy-context.php',
			'includes/gradebook/class-institutional-gradebook-rest-controller.php',
			'includes/library/class-academic-library-rest-controller.php',
		);
		$allowed = array_map( static function( string $p ) use ( $base ): string {
			return $base . ltrim( $p, '/' );
		}, $allowed );

		$errors = array();
		$rii = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $rii as $file ) {
			/** @var \SplFileInfo $file */
			if ( ! $file->isFile() ) { continue; }
			if ( 'php' !== strtolower( (string) $file->getExtension() ) ) { continue; }

			$path = (string) $file->getPathname();
			if ( str_contains( $path, '/vendor/' ) || str_contains( $path, '/dist/' ) || str_contains( $path, '/tests/' ) ) {
				continue;
			}
			if ( in_array( $path, $allowed, true ) ) {
				continue;
			}

			$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $contents || '' === $contents ) { continue; }
			if ( false !== strpos( $contents, $needle ) ) {
				$rel = ltrim( str_replace( $base, '', $path ), '/' );
				$errors[] = 'Aparición de string legacy fuera de compat: ' . $rel;
			}
		}

		return $errors;
	}
}
