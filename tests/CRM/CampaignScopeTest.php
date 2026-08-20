<?php
/**
 * Campaign_Builder_Rest_Controller::campaign_is_visible() — scoping por
 * dueño — PT-9 (sprint 6.5.5, hallazgo del barrido de auditoría P8/P9).
 *
 * Hallazgo confirmado: get/update/launch/clone/pause/metrics de
 * campañas solo exigían la capability genérica de la ruta
 * (crm_manage_campaigns/clms_manage_crm/manage_options) — ninguna
 * verificaba que la campaña perteneciera a quien la pedía, pese a que
 * atora_crm_campaigns.created_by ya existe justamente para eso.
 *
 * @package ATORA_LMS\Tests\CRM
 */

declare( strict_types = 1 );

namespace ATORA\Tests\CRM;

use PHPUnit\Framework\TestCase;

class CampaignScopeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\atora_test_reset_user_caps();
		$GLOBALS['__atora_test_current_user_id'] = 0;
	}

	protected function tearDown(): void {
		\atora_test_reset_user_caps();
		parent::tearDown();
	}

	private function is_visible( array $row ): bool {
		$ref = new \ReflectionMethod( \ATORA\CRM_V2\Rest\Campaign_Builder_Rest_Controller::class, 'campaign_is_visible' );
		$ref->setAccessible( true );
		return (bool) $ref->invoke( null, $row );
	}

	/** @test */
	public function test_owner_can_see_their_own_campaign(): void {
		$GLOBALS['__atora_test_current_user_id'] = 5;
		\atora_test_set_user_cap( 5, 'crm_manage_campaigns' );

		$this->assertTrue( $this->is_visible( array( 'created_by' => 5 ) ) );
	}

	/**
	 * Caso confirmado del hallazgo: crm_manage_campaigns por sí solo no
	 * debe dar acceso a campañas de OTRO usuario.
	 *
	 * @test
	 */
	public function test_non_owner_with_only_campaigns_capability_cannot_see_others_campaign(): void {
		$GLOBALS['__atora_test_current_user_id'] = 6;
		\atora_test_set_user_cap( 6, 'crm_manage_campaigns' );

		$this->assertFalse( $this->is_visible( array( 'created_by' => 5 ) ) );
	}

	/** @test */
	public function test_clms_manage_crm_sees_any_campaign(): void {
		$GLOBALS['__atora_test_current_user_id'] = 7;
		\atora_test_set_user_cap( 7, 'clms_manage_crm' );

		$this->assertTrue( $this->is_visible( array( 'created_by' => 5 ) ) );
	}

	/** @test */
	public function test_manage_options_sees_any_campaign(): void {
		$GLOBALS['__atora_test_current_user_id'] = 1;
		\atora_test_set_user_cap( 1, 'manage_options' );

		$this->assertTrue( $this->is_visible( array( 'created_by' => 5 ) ) );
	}

	/** @test */
	public function test_empty_row_is_never_visible(): void {
		$GLOBALS['__atora_test_current_user_id'] = 1;
		\atora_test_set_user_cap( 1, 'manage_options' );

		$this->assertFalse( $this->is_visible( array() ), 'campaña vacía (no encontrada) nunca debe considerarse visible' );
	}
}
