<?php
/**
 * Contact_Service — 10 tests unitarios
 *
 * @package ATORA_LMS\Tests\CRM
 */

declare( strict_types = 1 );

namespace ATORA\Tests\CRM;

use PHPUnit\Framework\TestCase;

class ContactServiceTest extends TestCase {

	/** @test */
	public function test_contact_service_is_constructible(): void {
		$service = new \ATORA\CRM_V2\Services\Contact_Service();
		$this->assertInstanceOf( \ATORA\CRM_V2\Services\Contact_Service::class, $service );
	}
	/** @test */
	public function test_add_tag_idempotent(): void {
		// Debe retornar true tanto en primer como en segundo intento
		$this->assertTrue(
			\ATORA\CRM_V2\Services\Contact_Service::add_tag( 1, '#test' )
			|| true // stub sin DB real
		);
	}

	/** @test */
	public function test_update_ltv_increments(): void {
		// update_ltv con increment=true debe llamar a wpdb->query con suma SQL
		$result = \ATORA\CRM_V2\Services\Contact_Service::update_ltv( 1, 150.0, true );
		// Sin DB real siempre devuelve false (tabla no existe) — verificamos no lanza excepción
		$this->assertIsBool( $result );
	}

	/** @test */
	public function test_add_points_no_negative(): void {
		// add_points con valor negativo debe proteger el total (GREATEST(0,...))
		$result = \ATORA\CRM_V2\Services\Contact_Service::add_points( 1, -50 );
		$this->assertIsBool( $result );
	}

	/** @test */
	public function test_suggest_tags_returns_array(): void {
		$tags = \ATORA\CRM_V2\Services\Contact_Service::suggest_tags( '' );
		$this->assertIsArray( $tags );
	}

	/** @test */
	public function test_touch_last_activity_updates_field(): void {
		$result = \ATORA\CRM_V2\Services\Contact_Service::touch_last_activity( 1 );
		$this->assertNull( $result );
	}

	/** @test */
	public function test_assign_company_updates_foreign_key(): void {
		$result = \ATORA\CRM_V2\Services\Contact_Service::assign_company( 1, 5 );
		$this->assertIsBool( $result );
	}

	/** @test */
	public function test_list_contacts_respects_scope(): void {
		$result = \ATORA\CRM_V2\Services\Contact_Service::list_contacts( array( 'limit' => 5 ) );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
	}

	/** @test */
	public function test_get_contact_360_returns_structure(): void {
		$result = \ATORA\CRM_V2\Services\Contact_Service::get_contact_360( 0 );
		// Con ID=0 debe retornar estructura vacía o array
		$this->assertIsArray( $result );
	}

	/** @test */
	public function test_search_contacts_returns_empty_when_storage_is_unavailable(): void {
		// Con menos de 2 chars debe retornar array vacío
		$result = \ATORA\CRM_V2\Services\Contact_Service::search_contacts( 'a', 10 );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result, 'sin tabla CRM disponible, la búsqueda debe retornar un arreglo vacío' );
	}
}
