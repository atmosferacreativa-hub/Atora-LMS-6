<?php
/**
 * Learning Analytics — risk score.
 *
 * @package ATORA_LMS\Tests\LearningAnalytics
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LearningAnalytics;

use PHPUnit\Framework\TestCase;

final class RiskScoreTest extends TestCase {

	/** @test */
	public function it_assigns_low_score_for_normal_status(): void {
		require_once __DIR__ . '/../../modules/learning-analytics/class-learning-analytics-service.php';

		$score = \ATORA\LearningAnalytics\Learning_Analytics_Service::calculate_risk_score_from_status(
			array(
				'risk_level'         => 'normal',
				'pending_activities' => 0,
				'progress_percent'   => 80,
				'final_average'      => 90,
				'last_access_at'     => date( 'Y-m-d H:i:s', time() - 3600 ),
			)
		);

		$this->assertSame( 20, $score );
	}

	/** @test */
	public function it_increases_score_for_pending_and_low_progress(): void {
		require_once __DIR__ . '/../../modules/learning-analytics/class-learning-analytics-service.php';

		$score = \ATORA\LearningAnalytics\Learning_Analytics_Service::calculate_risk_score_from_status(
			array(
				'risk_level'         => 'medium',
				'pending_activities' => 3,
				'progress_percent'   => 20,
				'final_average'      => 75,
				'last_access_at'     => date( 'Y-m-d H:i:s', time() - 3600 ),
			)
		);

		$this->assertSame( 80, $score );
	}

	/** @test */
	public function it_boosts_score_for_low_average_and_inactivity(): void {
		require_once __DIR__ . '/../../modules/learning-analytics/class-learning-analytics-service.php';

		$score = \ATORA\LearningAnalytics\Learning_Analytics_Service::calculate_risk_score_from_status(
			array(
				'risk_level'         => 'high',
				'pending_activities' => 5,
				'progress_percent'   => 10,
				'final_average'      => 40,
				'last_access_at'     => date( 'Y-m-d H:i:s', time() - ( 15 * DAY_IN_SECONDS ) ),
			)
		);

		$this->assertSame( 100, $score );
	}
}

