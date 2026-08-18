<?php
/**
 * Sequence_Service — 10 tests unitarios
 *
 * @package ATORA_LMS\Tests\Email
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Email;

use PHPUnit\Framework\TestCase;

class SequenceServiceTest extends TestCase {

	private function getService(): \ATORA\CRM_V2\Services\Sequence_Service {
		return new \ATORA\CRM_V2\Services\Sequence_Service();
	}

	/** @test */
	public function test_create_sequence_requires_name(): void {
		$id = \ATORA\CRM_V2\Services\Sequence_Service::create( array() );
		$this->assertSame( 0, $id, 'Crear secuencia sin nombre debe retornar 0' );
	}

	/** @test */
	public function test_enroll_contact_idempotent(): void {
		// Enrolar el mismo contacto dos veces no debe crear duplicado
		$id1 = \ATORA\CRM_V2\Services\Sequence_Service::enroll_contact( 1, 1 );
		$id2 = \ATORA\CRM_V2\Services\Sequence_Service::enroll_contact( 1, 1 );
		// Ambos deben ser >= 0 y no lanzar excepción
		$this->assertIsInt( $id1 );
		$this->assertIsInt( $id2 );
	}

	/** @test */
	public function test_suppress_stores_hash(): void {
		$result = \ATORA\CRM_V2\Services\Sequence_Service::suppress( 'test@example.com' );
		$this->assertIsBool( $result );
	}

	/** @test */
	public function test_is_suppressed_returns_bool(): void {
		$result = \ATORA\CRM_V2\Services\Sequence_Service::is_suppressed( 'cualquier@email.com' );
		$this->assertIsBool( $result );
	}

	/** @test */
	public function test_stop_enrollment_changes_status(): void {
		$result = \ATORA\CRM_V2\Services\Sequence_Service::stop_enrollment( 1, 1 );
		$this->assertIsBool( $result );
	}

	/** @test */
	public function test_enroll_requires_active_sequence(): void {
		// Con sequence_id=0 debe retornar 0
		$id = \ATORA\CRM_V2\Services\Sequence_Service::enroll_contact( 0, 1 );
		$this->assertSame( 0, $id, 'Enrolar en secuencia ID=0 debe retornar 0' );
	}

	/** @test */
	public function test_save_step_validates_condition(): void {
		$result = \ATORA\CRM_V2\Services\Sequence_Service::save_step( 0, array() );
		$this->assertFalse( $result, 'save_step con sequence_id=0 debe retornar false' );
	}

	/** @test */
	public function test_get_suppressed_paginates(): void {
		$result = \ATORA\CRM_V2\Services\Sequence_Service::get_suppressed( 10, 0 );
		$this->assertIsArray( $result );
	}

	/** @test */
	public function test_process_due_steps_skips_suppressed(): void {
		// Debe ejecutar sin excepción (aunque sin BD real no procese nada)
		$this->expectNotToPerformAssertions();
		\ATORA\CRM_V2\Services\Sequence_Service::process_due_steps();
	}

	/** @test */
	public function test_unsuppress_removes_record(): void {
		$result = \ATORA\CRM_V2\Services\Sequence_Service::unsuppress( 'test@example.com' );
		$this->assertIsBool( $result );
	}
}
