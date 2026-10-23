<?php
/**
 * CRM_V2_App::run_daily_maintenance() — auto-tags + scoring.
 *
 * @package ATORA_LMS\Tests\CRM
 */

declare( strict_types = 1 );

namespace ATORA\Tests\CRM;

use PHPUnit\Framework\TestCase;

class CrmV2DailyMaintenanceTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_options();
	}

	protected function tearDown(): void {
		atora_test_reset_options();
		parent::tearDown();
	}

	/** @test */
	public function test_runs_auto_tags_and_scoring_callbacks_when_configured(): void {
		update_option( 'clms_crm_v2_enabled', true );
		$GLOBALS['__atora_test_auto_tags_called'] = false;
		$GLOBALS['__atora_test_scoring_batch']    = 0;

		\Brain\Monkey\Filters\expectApplied( 'atora_crm_v2_auto_tags_callback' )
			->once()
			->andReturn( function() { $GLOBALS['__atora_test_auto_tags_called'] = true; } );
		\Brain\Monkey\Filters\expectApplied( 'atora_crm_v2_scoring_recalculate_callback' )
			->once()
			->andReturn( function( int $batch ): void { $GLOBALS['__atora_test_scoring_batch'] = $batch; } );

		\ATORA\CRM_V2\CRM_V2_App::run_daily_maintenance();

		$this->assertTrue( (bool) ( $GLOBALS['__atora_test_auto_tags_called'] ?? false ) );
		$this->assertSame( 100, (int) ( $GLOBALS['__atora_test_scoring_batch'] ?? 0 ) );
	}
}

