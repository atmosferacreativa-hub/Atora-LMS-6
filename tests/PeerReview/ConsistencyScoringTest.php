<?php
/**
 * Peer Review — CLMS_Peer_Review::score_consistency_for_submission():
 * marca cada revisión como ok/outlier/excluded según qué tan lejos esté
 * del promedio, y respeta el flag de exclusión manual.
 *
 * @package ATORA_LMS\Tests\PeerReview
 */

declare( strict_types = 1 );

namespace ATORA\Tests\PeerReview;

use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class FakeWpdbConsistency {
	public string $prefix = 'wp_';
	public function prepare( string $sql, ...$args ): string { return $sql; }
	public function get_var( $sql ) { return null; }
}

final class ConsistencyScoringTest extends TestCase {

	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../includes/class-peer-review.php';

		atora_test_reset_post_meta();
		atora_test_reset_posts();

		global $wpdb;
		$this->original_wpdb = $wpdb;
		$wpdb = new FakeWpdbConsistency();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	private function call_score_consistency( int $submission_id, int $peer_grade, int $lesson_id ): void {
		$m = new \ReflectionMethod( \CLMS_Peer_Review::class, 'score_consistency_for_submission' );
		$m->setAccessible( true );
		$m->invoke( null, $submission_id, $peer_grade, $lesson_id );
	}

	/** @test */
	public function assignment_within_threshold_is_flagged_ok(): void {
		$assignment_id = 700;
		atora_test_set_post_meta( $assignment_id, '_clms_pr_scores', array( 85 ) ); // 85%

		Functions\when( 'get_posts' )->justReturn( array( $assignment_id ) );

		$this->call_score_consistency( 900, 80, 1 ); // peer_grade=80, delta=|85-80|=5

		$this->assertSame( 'ok', get_post_meta( $assignment_id, '_clms_pr_consistency_flag', true ) );
		$this->assertSame( 5, (int) get_post_meta( $assignment_id, '_clms_pr_consistency_delta', true ) );
	}

	/** @test */
	public function assignment_20_points_off_is_flagged_outlier(): void {
		$assignment_id = 701;
		atora_test_set_post_meta( $assignment_id, '_clms_pr_scores', array( 100 ) ); // 100%

		Functions\when( 'get_posts' )->justReturn( array( $assignment_id ) );

		$this->call_score_consistency( 900, 80, 1 ); // delta=|100-80|=20 -> outlier (umbral >=20)

		$this->assertSame( 'outlier', get_post_meta( $assignment_id, '_clms_pr_consistency_flag', true ) );
	}

	/** @test */
	public function manually_excluded_assignment_stays_excluded_even_if_within_threshold(): void {
		$assignment_id = 702;
		atora_test_set_post_meta( $assignment_id, '_clms_pr_scores', array( 82 ) ); // solo 2 pts de diferencia
		atora_test_set_post_meta( $assignment_id, '_clms_pr_excluded', '1' );

		Functions\when( 'get_posts' )->justReturn( array( $assignment_id ) );

		$this->call_score_consistency( 900, 80, 1 );

		$this->assertSame( 'excluded', get_post_meta( $assignment_id, '_clms_pr_consistency_flag', true ) );
	}

	/** @test */
	public function no_op_when_submission_id_is_zero(): void {
		Functions\when( 'get_posts' )->alias( function () {
			$this->fail( 'no debería consultar assignments si submission_id es 0' );
		} );

		$this->call_score_consistency( 0, 80, 1 );
		$this->assertTrue( true ); // llegar aquí sin fallar es el éxito
	}
}
