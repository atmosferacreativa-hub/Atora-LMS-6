<?php
/**
 * Integración: `wp atora tenancy migrate/verify/rollback` contra DB real.
 *
 * Nota: este archivo se ejecuta aislado en CI (un proceso PHPUnit por archivo),
 * porque rollback elimina tablas.
 */

declare( strict_types = 1 );

final class TenancyCliMigrationRollbackTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();

		// Asegurar punto de partida limpio para la detección de IDs legacy:
		// el instalador de WP no limpia tablas custom del plugin entre ejecuciones.
		global $wpdb;
		$tables = array(
			$wpdb->prefix . 'atora_academic_periods',
			$wpdb->prefix . 'atora_gradebook_cycles',
			$wpdb->prefix . 'atora_grading_scales',
			$wpdb->prefix . 'atora_library_items',
		);
		foreach ( $tables as $table ) {
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $exists === $table ) {
				$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching
			}
		}
		delete_option( 'atora_active_academy_id' );
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => '_atora_academy_id' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test_migrate_dry_run_does_not_write_and_verify_and_rollback_work(): void {
		global $wpdb;

		// Estado previo: opciones relevantes.
		$before_default = (int) get_option( 'atora_default_institution', 0 );
		$before_source  = (string) get_option( 'atora_cohort_source', '' );

		\ATORA\LMS\Tenancy_CLI::migrate( array(), array( 'dry-run' => true ) );

		$this->assertSame( $before_default, (int) get_option( 'atora_default_institution', 0 ) );
		$this->assertSame( $before_source, (string) get_option( 'atora_cohort_source', '' ) );

		// Migración real.
		\ATORA\LMS\Tenancy_CLI::migrate( array(), array( 'yes' => true ) );
		$this->assertNotSame( 0, (int) get_option( 'atora_default_institution', 0 ) );
		$this->assertSame( 'tables', (string) get_option( 'atora_cohort_source', '' ) );

		// Verify no debe lanzar excepción (WP_CLI::error()).
		\ATORA\LMS\Tenancy_CLI::verify( array(), array() );

		// Rollback elimina tablas principales de tenencia.
		\ATORA\LMS\Tenancy_CLI::rollback( array(), array( 'yes' => true ) );

		$tables = array(
			$wpdb->prefix . 'atora_institutions',
			$wpdb->prefix . 'atora_institution_members',
			$wpdb->prefix . 'atora_cohorts',
			$wpdb->prefix . 'atora_cohort_members',
			$wpdb->prefix . 'atora_cohort_courses',
		);
		foreach ( $tables as $table ) {
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			$this->assertNotSame( $table, $exists );
		}
	}
}
