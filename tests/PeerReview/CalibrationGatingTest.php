<?php
/**
 * Peer Review — calibración de revisores (umbrales pass/warn/fail y
 * gating de acceso).
 *
 * @package ATORA_LMS\Tests\PeerReview
 */

declare( strict_types = 1 );

namespace ATORA\Tests\PeerReview;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class FakeWpdbPeerReview {
	public string $prefix = 'wp_';
	public function prepare( string $sql, ...$args ): string { return $sql; }
	public function get_var( $sql ) { return null; } // sin tabla de auditoría -> audit() no-op
}

final class CalibrationGatingTest extends TestCase {

	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../includes/class-peer-review.php';

		atora_test_reset_post_meta();
		atora_test_reset_user_meta();
		atora_test_reset_posts();

		global $wpdb;
		$this->original_wpdb = $wpdb;
		$wpdb = new FakeWpdbPeerReview();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	private function seed_calibration_lesson( int $lesson_id, int $exemplar_submission_id, int $teacher_grade ): void {
		atora_test_set_post_meta( $lesson_id, '_clms_pr_calibration_enabled', '1' );
		atora_test_set_post_meta( $lesson_id, '_clms_pr_calibration_submission_id', $exemplar_submission_id );
		atora_test_set_post_meta( $lesson_id, '_clms_pr_calibration_teacher_grade', $teacher_grade );
		atora_test_set_post( $exemplar_submission_id, array( 'post_type' => 'clms_submission' ) );
	}

	/** @test */
	public function calibration_not_required_when_disabled(): void {
		$lesson_id = 1;
		atora_test_set_post_meta( $lesson_id, '_clms_pr_calibration_enabled', '0' );

		$this->assertFalse( \CLMS_Peer_Review::is_calibration_required_for_lesson( $lesson_id ) );
	}

	/** @test */
	public function calibration_not_required_when_enabled_but_no_exemplar_submission(): void {
		$lesson_id = 1;
		atora_test_set_post_meta( $lesson_id, '_clms_pr_calibration_enabled', '1' );
		atora_test_set_post_meta( $lesson_id, '_clms_pr_calibration_submission_id', 0 );

		$this->assertFalse( \CLMS_Peer_Review::is_calibration_required_for_lesson( $lesson_id ) );
	}

	/** @test */
	public function calibration_required_when_properly_configured(): void {
		$this->seed_calibration_lesson( 1, 900, 80 );
		$this->assertTrue( \CLMS_Peer_Review::is_calibration_required_for_lesson( 1 ) );
	}

	/**
	 * score_calibration_assignment(): delta <=10 -> pass, 11-20 -> warn,
	 * >20 -> fail. Cubre los 3 umbrales explícitamente.
	 *
	 * @test
	 * @dataProvider delta_status_provider
	 */
	public function score_calibration_assignment_applies_the_right_threshold( int $reviewer_percent, int $teacher_grade, string $expected_status ): void {
		$assignment_id = 500;
		$lesson_id     = 1;
		$reviewer_id   = 42;

		$this->seed_calibration_lesson( $lesson_id, 900, $teacher_grade );

		atora_test_set_post_meta( $assignment_id, '_clms_pr_is_calibration', '1' );
		atora_test_set_post_meta( $assignment_id, '_clms_pr_lesson_id', $lesson_id );
		atora_test_set_post_meta( $assignment_id, '_clms_pr_reviewer_id', $reviewer_id );
		// Rúbrica de 100 pts -> el % del assignment es directamente la suma de scores.
		atora_test_set_post_meta( $assignment_id, '_clms_pr_scores', array( $reviewer_percent ) );

		\CLMS_Peer_Review::score_calibration_assignment( $assignment_id );

		$this->assertSame( $expected_status, get_post_meta( $assignment_id, '_clms_pr_calibration_status', true ) );
		$this->assertSame( $expected_status, get_user_meta( $reviewer_id, '_clms_pr_calibration_status_' . $lesson_id, true ) );
	}

	public static function delta_status_provider(): array {
		return array(
			'delta 0 -> pass'   => array( 80, 80, 'pass' ),
			'delta 10 -> pass'  => array( 90, 80, 'pass' ),
			'delta 11 -> warn'  => array( 91, 80, 'warn' ),
			'delta 20 -> warn'  => array( 100, 80, 'warn' ),
			'delta 21 -> fail'  => array( 80, 59, 'fail' ), // |80-59|=21
		);
	}

	/** @test */
	public function reviewer_calibration_cache_pass_or_warn_grants_access(): void {
		update_user_meta( 42, '_clms_pr_calibration_status_1', 'pass' );
		$this->assertTrue( \CLMS_Peer_Review::is_reviewer_calibrated_for_lesson( 42, 1 ) );

		update_user_meta( 43, '_clms_pr_calibration_status_1', 'warn' );
		$this->assertTrue( \CLMS_Peer_Review::is_reviewer_calibrated_for_lesson( 43, 1 ) );
	}

	/** @test */
	public function reviewer_calibration_cache_fail_denies_access(): void {
		update_user_meta( 44, '_clms_pr_calibration_status_1', 'fail' );
		$this->assertFalse( \CLMS_Peer_Review::is_reviewer_calibrated_for_lesson( 44, 1 ) );
	}

	/** @test */
	public function reviewer_without_cache_and_no_completed_review_is_not_calibrated(): void {
		Functions\when( 'get_posts' )->justReturn( array() );
		$this->assertFalse( \CLMS_Peer_Review::is_reviewer_calibrated_for_lesson( 45, 1 ) );
	}
}
