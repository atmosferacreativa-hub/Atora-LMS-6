<?php
/**
 * CRM_REST_Controller::can_access()/can_manage() — PT-1.2 (sprint 6.5.1).
 *
 * Todas las 15 rutas de escritura subidas a can_manage en este sprint
 * referencian este método por nombre — probarlo aquí cubre la
 * regresión de cada una sin necesitar un WP_REST_Server real (no
 * disponible en este entorno de test). Un usuario con solo can_access
 * (sin can_manage) es, por diseño, el "403 en escritura" que pide la
 * OT: el permission_callback de la ruta llama exactamente a este
 * método y nada más.
 *
 * @package ATORA_LMS\Tests\CRM
 */

declare( strict_types = 1 );

namespace ATORA\Tests\CRM;

use PHPUnit\Framework\TestCase;

class CRMRestPermissionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_user_caps();
		$GLOBALS['__atora_test_current_user_id'] = 0;
	}

	protected function tearDown(): void {
		atora_test_reset_user_caps();
		parent::tearDown();
	}

	/** @test */
	public function test_can_access_true_for_view_only_cap(): void {
		$this->as_user( 1 );
		atora_test_set_user_cap( 1, 'clms_access_crm_view' );
		$this->assertTrue( \ATORA\CRM_V2\Rest\CRM_REST_Controller::can_access() );
	}

	/** @test */
	public function test_can_manage_false_for_view_only_cap(): void {
		$this->as_user( 1 );
		atora_test_set_user_cap( 1, 'clms_access_crm_view' );
		$this->assertFalse(
			\ATORA\CRM_V2\Rest\CRM_REST_Controller::can_manage(),
			'un usuario con solo can_access debe recibir false en can_manage — equivalente al 403 de la OT en rutas de escritura'
		);
	}

	/** @test */
	public function test_can_manage_true_for_clms_manage_crm(): void {
		$this->as_user( 2 );
		atora_test_set_user_cap( 2, 'clms_manage_crm' );
		$this->assertTrue( \ATORA\CRM_V2\Rest\CRM_REST_Controller::can_manage() );
	}

	/** @test */
	public function test_manage_options_passes_both_access_and_manage(): void {
		$this->as_user( 3 );
		atora_test_set_user_cap( 3, 'manage_options' );
		$this->assertTrue( \ATORA\CRM_V2\Rest\CRM_REST_Controller::can_access() );
		$this->assertTrue( \ATORA\CRM_V2\Rest\CRM_REST_Controller::can_manage() );
	}

	/** @test */
	public function test_instructor_caps_pass_can_access_but_not_can_manage(): void {
		// Mismo set de caps reales del rol lms_instructor — confirma que
		// un instructor puede leer el CRM pero no escribir en las 15
		// rutas subidas a can_manage en PT-1.2.
		$this->as_user( 4 );
		atora_test_set_user_cap( 4, 'clms_access_admin' );
		atora_test_set_user_cap( 4, 'clms_manage_courses' );
		atora_test_set_user_cap( 4, 'clms_manage_lessons' );
		atora_test_set_user_cap( 4, 'clms_view_teacher_dashboard' );
		atora_test_set_user_cap( 4, 'clms_access_crm_view' );

		$this->assertTrue( \ATORA\CRM_V2\Rest\CRM_REST_Controller::can_access() );
		$this->assertFalse( \ATORA\CRM_V2\Rest\CRM_REST_Controller::can_manage() );
	}

	/** @test */
	public function test_no_caps_at_all_fails_both(): void {
		$this->as_user( 9 );
		$this->assertFalse( \ATORA\CRM_V2\Rest\CRM_REST_Controller::can_access() );
		$this->assertFalse( \ATORA\CRM_V2\Rest\CRM_REST_Controller::can_manage() );
	}

	private function as_user( int $user_id ): void {
		$GLOBALS['__atora_test_current_user_id'] = $user_id;
	}
}
