<?php
/**
 * Portfolios — Portfolios_Service::viewer_can_access_course(): quién
 * puede ver el portafolio de un curso (admin, dueño del curso, docentes
 * listados) vs. quién no.
 *
 * @package ATORA_LMS\Tests\Portfolios
 */

declare( strict_types = 1 );

namespace {
	require_once __DIR__ . '/../stubs/class-clms-helper-stub.php';
}

namespace ATORA\Tests\Portfolios {

use PHPUnit\Framework\TestCase;

final class VisibilityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../modules/portfolios/class-portfolios-service.php';

		atora_test_reset_user_caps();
		atora_test_reset_posts();
		atora_test_reset_post_meta();
		\atora_test_reset_clms_helper_stub();
	}

	protected function tearDown(): void {
		\atora_test_reset_clms_helper_stub();
		parent::tearDown();
	}

	/** @test */
	public function manage_options_can_always_access(): void {
		atora_test_set_user_cap( 1, 'manage_options', true );
		$service = new \ATORA\Portfolios\Portfolios_Service();
		$this->assertTrue( $service->viewer_can_access_course( 1, 100 ) );
	}

	/** @test */
	public function course_owner_can_access(): void {
		atora_test_set_post( 100, array( 'post_type' => 'lm_course', 'post_author' => 7 ) );
		$service = new \ATORA\Portfolios\Portfolios_Service();
		$this->assertTrue( $service->viewer_can_access_course( 7, 100 ) );
	}

	/** @test */
	public function listed_teacher_can_access(): void {
		atora_test_set_post( 100, array( 'post_type' => 'lm_course', 'post_author' => 7 ) );
		atora_test_set_post_meta( 100, '_clms_course_teacher_ids', '8,9' );

		$service = new \ATORA\Portfolios\Portfolios_Service();
		$this->assertTrue( $service->viewer_can_access_course( 8, 100 ) );
		$this->assertTrue( $service->viewer_can_access_course( 9, 100 ) );
	}

	/** @test */
	public function unrelated_user_cannot_access(): void {
		atora_test_set_post( 100, array( 'post_type' => 'lm_course', 'post_author' => 7 ) );
		atora_test_set_post_meta( 100, '_clms_course_teacher_ids', '8,9' );

		$service = new \ATORA\Portfolios\Portfolios_Service();
		$this->assertFalse( $service->viewer_can_access_course( 20, 100 ) );
	}

	/** @test */
	public function user_who_manages_the_course_via_clms_helper_can_access(): void {
		atora_test_set_post( 100, array( 'post_type' => 'lm_course', 'post_author' => 7 ) );
		atora_test_set_can_manage_course( true ); // CLMS_Helper::user_can_manage_lms() stub

		$service = new \ATORA\Portfolios\Portfolios_Service();
		$this->assertTrue( $service->viewer_can_access_course( 20, 100 ) );
	}

	/** @test */
	public function invalid_viewer_or_course_id_denies_access(): void {
		$service = new \ATORA\Portfolios\Portfolios_Service();
		$this->assertFalse( $service->viewer_can_access_course( 0, 100 ) );
		$this->assertFalse( $service->viewer_can_access_course( 20, 0 ) );
	}
}
}
