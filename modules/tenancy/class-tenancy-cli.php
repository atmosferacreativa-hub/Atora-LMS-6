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
			$wpdb->prefix . 'atora_programs',
			$wpdb->prefix . 'atora_courses',
			$wpdb->prefix . 'atora_enrollments',
			$wpdb->prefix . 'atora_program_enrollments',
			$wpdb->prefix . 'clms_invitations',
		);

		foreach ( $column_tables as $table ) {
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $exists !== $table ) { continue; }

			$has = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
					 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'institution_id'",
					$table
				)
			) > 0;

			if ( $has ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$table} DROP COLUMN institution_id" );
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
		$lines[] = 'Backfill institution_id en atora_programs/courses/enrollments/program_enrollments/clms_invitations (si está en 0).';
		$lines[] = 'Migrar cohortes desde lm_cohort postmeta a atora_cohorts/atora_cohort_members/atora_cohort_courses.';
		$lines[] = 'Set option atora_cohort_source=tables.';
		return $lines;
	}
}
