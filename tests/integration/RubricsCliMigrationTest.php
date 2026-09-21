<?php
/**
 * Integración: `wp atora rubrics migrate/verify` contra DB real.
 */

declare( strict_types = 1 );

final class RubricsCliMigrationTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();

		// Estado limpio.
		delete_option( 'atora_rubric_source' );
	}

	public function test_migrate_dry_run_does_not_write_and_verify_passes_after_migrate(): void {
		global $wpdb;

		$rubric_post_id = self::factory()->post->create( array(
			'post_type'   => 'clms_rubric',
			'post_status' => 'publish',
			'post_title'  => 'Rúbrica 1',
		) );
		update_post_meta( $rubric_post_id, '_clms_rubric_scale_type', '0_100' );
		update_post_meta( $rubric_post_id, '_clms_rubric_is_holistic', '0' );
		update_post_meta( $rubric_post_id, '_clms_rubric_criteria', array(
			array(
				'name'        => 'Claridad',
				'description' => 'Se entiende.',
				'max_points'  => 10,
				'weight'      => 50,
				'levels'      => array(
					array( 'label' => 'Ok', 'points' => 10, 'descriptor' => '' ),
				),
			),
			array(
				'name'        => 'Estructura',
				'description' => 'Orden.',
				'max_points'  => 10,
				'weight'      => 50,
				'levels'      => array(),
			),
		) );

		$table = $wpdb->prefix . 'atora_rubrics';
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		\ATORA\LMS\Rubrics_CLI::migrate( array(), array( 'dry-run' => true, 'batch' => 50 ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		\ATORA\LMS\Rubrics_CLI::migrate( array(), array( 'yes' => true, 'batch' => 50 ) );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		\ATORA\LMS\Rubrics_CLI::verify( array(), array() );
	}
}

