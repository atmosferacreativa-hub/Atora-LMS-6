<?php
/**
 * 6.41.0: la semilla del e2e de la app deja un curso del estudiante en un período cerrado y visible.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/LmsRowsCleanup.php';

final class PeriodsSeedTest extends WP_UnitTestCase {

	use LmsRowsCleanup;

	private int $course = 0;
	private int $student = 0;

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_period_courses" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_periods" );
		$this->cleanup_lms_rows( array( $this->course ), array( $this->student ) );
		parent::tearDown();
	}

	public function test_seed_puts_a_course_in_a_closed_visible_period_and_is_idempotent(): void {
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		( new CLMS_DB_Migration() )->run();
		if ( ! class_exists( 'ATORA_E2E_Seed_CLI' ) ) {
			require_once dirname( __DIR__, 2 ) . '/includes/modularity/class-e2e-seed-cli.php';
		}
		$teacher       = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->course = ATORA_E2E_Seed_CLI::past_period_course( $teacher, $this->student );
		$this->assertSame( $this->course, ATORA_E2E_Seed_CLI::past_period_course( $teacher, $this->student ), 'Volver a sembrar no duplica el curso.' );

		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ATORA_Periods_Service::table_periods() . ' WHERE name = %s', 'Período 2025-2 E2E' ) ), 'Ni el período.' );
		$period = ATORA_Periods_Service::period_payload( $this->course );
		$this->assertSame( 'closed', $period['status'] );
		$this->assertTrue( ATORA_Course_Access_Service::can_access( $this->student, $this->course ), 'Visible: el estudiante puede repasar.' );

		$grouped = ATORA_Periods_Service::grouped_courses_for_user( $this->student );
		$this->assertSame( 'Período 2025-2 E2E', $grouped['past'][0]['period']['name'] );
		$this->assertSame( 'Curso Anterior E2E', $grouped['past'][0]['courses'][0]['title'] );
	}
}
