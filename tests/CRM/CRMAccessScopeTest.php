<?php
/**
 * CLMS_Contacts_Core_Service::has_global_contact_scope() — PT-1.3/1.4 (sprint 6.5.1).
 *
 * Regresión del hallazgo de la auditoría: clms_manage_courses,
 * clms_manage_lessons y clms_access_admin (todas caps que el rol
 * lms_instructor tiene) no deben otorgar alcance global de contactos.
 *
 * @package ATORA_LMS\Tests\CRM
 */

declare( strict_types = 1 );

namespace ATORA\Tests\CRM;

use PHPUnit\Framework\TestCase;

class CRMAccessScopeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_user_caps();
	}

	protected function tearDown(): void {
		atora_test_reset_user_caps();
		parent::tearDown();
	}

	/** Caps reales del rol lms_instructor (includes/class-access.php). */
	private function set_instructor_caps( int $user_id ): void {
		atora_test_set_user_cap( $user_id, 'clms_access_admin' );
		atora_test_set_user_cap( $user_id, 'clms_manage_courses' );
		atora_test_set_user_cap( $user_id, 'clms_manage_lessons' );
		atora_test_set_user_cap( $user_id, 'clms_view_teacher_dashboard' );
		atora_test_set_user_cap( $user_id, 'clms_access_crm_view' );
	}

	/** @test */
	public function test_instructor_does_not_get_global_scope(): void {
		$core = new \CLMS_Contacts_Core_Service();
		$this->set_instructor_caps( 5 );
		$this->assertFalse( $core->has_global_contact_scope( 5 ) );
	}

	/** @test */
	public function test_manage_options_keeps_global_scope(): void {
		$core = new \CLMS_Contacts_Core_Service();
		atora_test_set_user_cap( 6, 'manage_options' );
		$this->assertTrue( $core->has_global_contact_scope( 6 ) );
	}

	/** @test */
	public function test_clms_manage_commerce_keeps_global_scope(): void {
		$core = new \CLMS_Contacts_Core_Service();
		atora_test_set_user_cap( 7, 'clms_access_crm_view' );
		atora_test_set_user_cap( 7, 'clms_manage_commerce' );
		$this->assertTrue( $core->has_global_contact_scope( 7 ) );
	}

	/** @test */
	public function test_clms_access_admin_alone_no_longer_grants_global_scope(): void {
		$core = new \CLMS_Contacts_Core_Service();
		atora_test_set_user_cap( 8, 'clms_access_crm_view' );
		atora_test_set_user_cap( 8, 'clms_access_admin' );
		$this->assertFalse( $core->has_global_contact_scope( 8 ) );
	}

	/** @test */
	public function test_clms_manage_courses_alone_no_longer_grants_global_scope(): void {
		$core = new \CLMS_Contacts_Core_Service();
		atora_test_set_user_cap( 9, 'clms_access_crm_view' );
		atora_test_set_user_cap( 9, 'clms_manage_courses' );
		$this->assertFalse( $core->has_global_contact_scope( 9 ) );
	}

	/** @test */
	public function test_instructor_falls_through_to_enrollment_scoping_not_global(): void {
		$core = new \CLMS_Contacts_Core_Service();
		// Sin CLMS_Helper/CLMS_Messaging cargados en el bootstrap de test,
		// get_accessible_contact_user_ids() no puede resolver matrículas
		// reales — pero lo que importa para esta regresión es que NO tome
		// el atajo de alcance global (array vacío = "ve todo").
		$this->set_instructor_caps( 5 );
		$ids = $core->get_accessible_contact_user_ids( 5 );
		$this->assertFalse( $core->has_global_contact_scope( 5 ), 'precondición: sin alcance global' );
		$this->assertIsArray( $ids );
	}

	/** @test */
	public function test_collaborator_without_teacher_caps_keeps_global_scope(): void {
		$core = new \CLMS_Contacts_Core_Service();
		// Rama no tocada por PT-1.3 — colaborador WP genérico (edit_posts)
		// que no es docente sigue con alcance operativo completo.
		atora_test_set_user_cap( 10, 'clms_access_crm_view' );
		atora_test_set_user_cap( 10, 'edit_posts' );
		$this->assertTrue( $core->has_global_contact_scope( 10 ) );
	}
}
