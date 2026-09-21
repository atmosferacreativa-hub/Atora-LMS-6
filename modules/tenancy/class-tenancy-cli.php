<?php
/**
 * Tenancy CLI — X-01
 *
 * `wp atora tenancy rollback [--yes]`
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
}

