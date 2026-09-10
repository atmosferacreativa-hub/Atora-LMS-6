<?php
/**
 * F4 — table-backed enrollment readers.
 *
 * @package ATORA_LMS\Tests\LMS
 */
declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

require_once __DIR__ . '/Support/F4TestSupport.php';

class EnrollmentServiceReadersTest extends WpdbSwapTestCase {

	/** @test */
	public function test_get_enrolled_wp_course_ids_filters_expired_and_dedupes(): void {
		$fake = $this->swap_wpdb();
		$fake->get_results_queue[] = array(
			array( 'wp_course_id' => '5', 'expires_at' => '' ),
			array( 'wp_course_id' => '5', 'expires_at' => '' ),
			array( 'wp_course_id' => '9', 'expires_at' => '2000-01-01 00:00:00' ), // expirado
		);

		$ids = \ATORA\LMS\LMS_Enrollment_Service::get_enrolled_wp_course_ids( 1 );

		$this->assertSame( array( 5 ), $ids );
		$this->assertIsInt( $ids[0], 'Los IDs deben ser int, no string' );
	}

	/** @test */
	public function test_get_enrolled_wp_course_ids_empty_returns_empty_array(): void {
		$this->swap_wpdb();
		$this->assertSame( array(), \ATORA\LMS\LMS_Enrollment_Service::get_enrolled_wp_course_ids( 1 ) );
	}

	/** @test */
	public function test_is_enrolled_by_wp_id_true_when_active_row(): void {
		$fake = $this->swap_wpdb();
		$fake->get_row_queue[] = array( 'expires_at' => '' );
		$this->assertTrue( \ATORA\LMS\LMS_Enrollment_Service::is_enrolled_by_wp_id( 1, 5 ) );
	}

	/** @test */
	public function test_is_enrolled_by_wp_id_false_when_no_row(): void {
		$this->swap_wpdb();
		$this->assertFalse( \ATORA\LMS\LMS_Enrollment_Service::is_enrolled_by_wp_id( 1, 5 ) );
	}

	/** @test */
	public function test_is_enrolled_by_wp_id_false_when_expired(): void {
		$fake = $this->swap_wpdb();
		$fake->get_row_queue[] = array( 'expires_at' => '2000-01-01 00:00:00' );
		$this->assertFalse( \ATORA\LMS\LMS_Enrollment_Service::is_enrolled_by_wp_id( 1, 5 ) );
	}

	/**
	 * Regresión: get_access_expiry_by_wp_id() no filtraba por status, a
	 * diferencia de sus hermanos — podía devolver una caducidad "fantasma"
	 * de una fila 'unenrolled'. La query ahora debe incluir el mismo filtro
	 * `status IN ('active', 'completed')` que is_enrolled_by_wp_id().
	 *
	 * @test
	 */
	public function test_get_access_expiry_by_wp_id_query_filters_by_status(): void {
		$fake = $this->swap_wpdb();
		$fake->get_var_queue[] = '2099-01-01 00:00:00';

		\ATORA\LMS\LMS_Enrollment_Service::get_access_expiry_by_wp_id( 1, 5 );

		$this->assertNotEmpty( $fake->executed );
		$this->assertStringContainsString(
			"status IN ('active', 'completed')",
			$fake->executed[0],
			'get_access_expiry_by_wp_id debe filtrar por status igual que is_enrolled_by_wp_id'
		);
	}

	/** @test */
	public function test_get_access_expiry_by_wp_id_returns_string(): void {
		$fake = $this->swap_wpdb();
		$fake->get_var_queue[] = '2099-01-01 00:00:00';
		$expiry = \ATORA\LMS\LMS_Enrollment_Service::get_access_expiry_by_wp_id( 1, 5 );
		$this->assertSame( '2099-01-01 00:00:00', $expiry );
	}

	/** @test */
	public function test_get_access_expiry_by_wp_id_empty_means_perpetual(): void {
		$this->swap_wpdb();
		$this->assertSame( '', \ATORA\LMS\LMS_Enrollment_Service::get_access_expiry_by_wp_id( 1, 5 ) );
	}

	/** @test */
	public function test_is_course_completed_by_wp_id_returns_bool(): void {
		$fake = $this->swap_wpdb();
		$fake->get_var_queue[] = '1';
		$this->assertTrue( \ATORA\LMS\LMS_Enrollment_Service::is_course_completed_by_wp_id( 1, 5 ) );
	}

	/** @test */
	public function test_get_enrolled_wp_program_ids_returns_int_array(): void {
		$fake = $this->swap_wpdb();
		$fake->get_results_queue[] = array(
			array( 'wp_post_id' => '7', 'expires_at' => '' ),
		);
		$ids = \ATORA\LMS\LMS_Enrollment_Service::get_enrolled_wp_program_ids( 1 );
		$this->assertSame( array( 7 ), $ids );
	}
}
