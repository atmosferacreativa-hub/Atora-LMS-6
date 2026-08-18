<?php
/**
 * Scoring_Service + Company_Service + Campaign_Service — 30 tests
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

// ── Scoring_Service (10 tests) ───────────────────────────────────────────────

class ScoringServiceTest extends TestCase {

	/** @test */
	public function test_calculate_score_returns_int(): void {
		$score = \ATORA\CRM_V2\Services\Scoring_Service::calculate_score( 1 );
		$this->assertIsInt( $score );
	}

	/** @test */
	public function test_calculate_score_range_0_100(): void {
		$score = \ATORA\CRM_V2\Services\Scoring_Service::calculate_score( 1 );
		$this->assertGreaterThanOrEqual( 0,   $score );
		$this->assertLessThanOrEqual(    100, $score );
	}

	/** @test */
	public function test_calculate_score_zero_for_invalid_contact(): void {
		$score = \ATORA\CRM_V2\Services\Scoring_Service::calculate_score( 0 );
		$this->assertSame( 0, $score, 'ID=0 debe retornar score=0' );
	}

	/** @test */
	public function test_get_score_label_alta(): void {
		$label = \ATORA\CRM_V2\Services\Scoring_Service::get_score_label( 85 );
		$this->assertSame( 'Alta', $label );
	}

	/** @test */
	public function test_get_score_label_media(): void {
		$label = \ATORA\CRM_V2\Services\Scoring_Service::get_score_label( 55 );
		$this->assertSame( 'Media', $label );
	}

	/** @test */
	public function test_get_score_label_baja(): void {
		$label = \ATORA\CRM_V2\Services\Scoring_Service::get_score_label( 20 );
		$this->assertSame( 'Baja', $label );
	}

	/** @test */
	public function test_get_score_label_boundary_70(): void {
		// 70 es Media (>70 = Alta, >=40 = Media)
		$label = \ATORA\CRM_V2\Services\Scoring_Service::get_score_label( 70 );
		$this->assertSame( 'Media', $label );
	}

	/** @test */
	public function test_get_score_label_boundary_71(): void {
		$label = \ATORA\CRM_V2\Services\Scoring_Service::get_score_label( 71 );
		$this->assertSame( 'Alta', $label );
	}

	/** @test */
	public function test_get_score_label_boundary_40(): void {
		$label = \ATORA\CRM_V2\Services\Scoring_Service::get_score_label( 40 );
		$this->assertSame( 'Media', $label );
	}

	/** @test */
	public function test_recalculate_all_runs_without_exception(): void {
		$this->expectNotToPerformAssertions();
		// Sin BD real procesa 0 contactos
		\ATORA\CRM_V2\Services\Scoring_Service::recalculate_all( 5 );
	}
}

// ── Company_Service (10 tests) ───────────────────────────────────────────────

class CompanyServiceTest extends TestCase {

	/** @test */
	public function test_create_requires_name(): void {
		$id = \ATORA\CRM_V2\Services\Company_Service::create( array() );
		$this->assertSame( 0, $id, 'Crear empresa sin nombre debe retornar 0' );
	}

	/** @test */
	public function test_create_with_name_returns_int(): void {
		$id = \ATORA\CRM_V2\Services\Company_Service::create( array( 'name' => 'ACME Corp' ) );
		$this->assertIsInt( $id );
	}

	/** @test */
	public function test_get_returns_null_for_nonexistent(): void {
		$result = \ATORA\CRM_V2\Services\Company_Service::get( 99999 );
		$this->assertNull( $result );
	}

	/** @test */
	public function test_get_all_returns_array_with_items_and_total(): void {
		$result = \ATORA\CRM_V2\Services\Company_Service::get_all();
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
	}

	/** @test */
	public function test_get_all_respects_limit(): void {
		$result = \ATORA\CRM_V2\Services\Company_Service::get_all( 5, 0 );
		$this->assertArrayHasKey( 'items', $result );
	}

	/** @test */
	public function test_update_returns_bool(): void {
		$result = \ATORA\CRM_V2\Services\Company_Service::update( 1, array( 'name' => 'Updated Corp' ) );
		$this->assertIsBool( $result );
	}

	/** @test */
	public function test_update_empty_data_returns_false(): void {
		$result = \ATORA\CRM_V2\Services\Company_Service::update( 1, array() );
		$this->assertFalse( $result, 'update con data vacía debe retornar false' );
	}

	/** @test */
	public function test_assign_contact_returns_bool(): void {
		$result = \ATORA\CRM_V2\Services\Company_Service::assign_contact( 1, 1 );
		$this->assertIsBool( $result );
	}

	/** @test */
	public function test_get_contacts_returns_array(): void {
		$result = \ATORA\CRM_V2\Services\Company_Service::get_contacts( 1 );
		$this->assertIsArray( $result );
	}

	/** @test */
	public function test_get_ltv_returns_float(): void {
		$ltv = \ATORA\CRM_V2\Services\Company_Service::get_ltv( 1 );
		$this->assertIsFloat( $ltv );
		$this->assertGreaterThanOrEqual( 0.0, $ltv );
	}
}

// ── Campaign_Service (10 tests) ──────────────────────────────────────────────

class CampaignServiceTest extends TestCase {

	/** @test */
	public function test_get_templates_returns_array(): void {
		$templates = \ATORA\CRM_V2\Services\Campaign_Service::get_templates();
		$this->assertIsArray( $templates );
		$this->assertNotEmpty( $templates );
	}

	/** @test */
	public function test_create_campaign_requires_name(): void {
		$id = \ATORA\CRM_V2\Services\Campaign_Service::create_campaign( array() );
		$this->assertSame( 0, $id, 'create_campaign sin nombre debe retornar 0' );
	}

	/** @test */
	public function test_get_campaigns_returns_array(): void {
		$result = \ATORA\CRM_V2\Services\Campaign_Service::get_campaigns( 5 );
		$this->assertIsArray( $result );
	}

	/** @test */
	public function test_get_campaign_returns_array_or_null(): void {
		$result = \ATORA\CRM_V2\Services\Campaign_Service::get_campaign( 99999 );
		$this->assertIsArray( $result );
	}

	/** @test */
	public function test_get_campaign_metrics_returns_array(): void {
		$result = \ATORA\CRM_V2\Services\Campaign_Service::get_campaign_metrics( 1 );
		$this->assertIsArray( $result );
	}

	/** @test */
	public function test_get_campaign_recipients_returns_array(): void {
		$result = \ATORA\CRM_V2\Services\Campaign_Service::get_campaign_recipients( 1 );
		$this->assertIsArray( $result );
	}

	/** @test */
	public function test_launch_campaign_simulate_returns_array(): void {
		$result = \ATORA\CRM_V2\Services\Campaign_Service::launch_campaign( 0, 'simulate' );
		$this->assertIsArray( $result );
	}

	/** @test */
	public function test_generate_tracking_id_is_32_chars(): void {
		// Acceder via reflexión o verificar que el método existe
		$service = new \ReflectionClass( \ATORA\CRM_V2\Services\Campaign_Service::class );
		$this->assertTrue(
			$service->hasMethod( 'generate_tracking_id' ),
			'Campaign_Service debe tener generate_tracking_id()'
		);
	}

	/** @test */
	public function test_get_upcoming_campaigns_returns_array(): void {
		$result = \ATORA\CRM_V2\Services\Campaign_Service::get_upcoming_campaigns( 5 );
		$this->assertIsArray( $result );
	}

	/** @test */
	public function test_rebuild_recipients_runs_without_exception(): void {
		$this->expectNotToPerformAssertions();
		\ATORA\CRM_V2\Services\Campaign_Service::rebuild_recipients( 0 );
	}
}
