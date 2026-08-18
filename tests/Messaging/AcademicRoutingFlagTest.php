<?php
/**
 * PT-2.4/2.5 (sprint 6.4.0): con el flag de enrutamiento académico
 * apagado (default), el recordatorio de inactividad debe comportarse
 * exactamente como en 6.3.0 — regresión de "cero cambio de
 * comportamiento por defecto".
 *
 * @package ATORA_LMS\Tests\Messaging
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Messaging;

use PHPUnit\Framework\TestCase;

class AcademicRoutingFlagTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_options();
	}

	protected function tearDown(): void {
		atora_test_reset_options();
		parent::tearDown();
	}

	private function should_use_router( \CLMS_Student_Inactivity_Reminder_Service $service ): bool {
		$method = new \ReflectionMethod( $service, 'should_use_router' );
		$method->setAccessible( true );
		return (bool) $method->invoke( $service );
	}

	/** @test */
	public function test_router_disabled_by_default(): void {
		$this->assertFalse( \ATORA\Messaging\Messaging_Router::is_academic_routing_enabled() );
	}

	/** @test */
	public function test_service_does_not_use_router_by_default(): void {
		// No se llama al constructor (registra hooks de cron) — solo se
		// prueba la decisión de enrutamiento en aislamiento.
		$service = ( new \ReflectionClass( \CLMS_Student_Inactivity_Reminder_Service::class ) )->newInstanceWithoutConstructor();
		$this->assertFalse( $this->should_use_router( $service ), 'con el flag apagado, debe usar send_reminder_email() (6.3.0), no el router' );
	}

	/** @test */
	public function test_service_uses_router_once_flag_enabled(): void {
		update_option( \ATORA\Messaging\Messaging_Router::OPT_ACADEMIC_ROUTING, true );

		$service = ( new \ReflectionClass( \CLMS_Student_Inactivity_Reminder_Service::class ) )->newInstanceWithoutConstructor();
		$this->assertTrue( $this->should_use_router( $service ) );
	}

	/** @test */
	public function test_flag_off_again_restores_default_path(): void {
		update_option( \ATORA\Messaging\Messaging_Router::OPT_ACADEMIC_ROUTING, true );
		update_option( \ATORA\Messaging\Messaging_Router::OPT_ACADEMIC_ROUTING, false );

		$service = ( new \ReflectionClass( \CLMS_Student_Inactivity_Reminder_Service::class ) )->newInstanceWithoutConstructor();
		$this->assertFalse( $this->should_use_router( $service ) );
	}

	/** @test */
	public function test_both_send_methods_still_exist_on_the_service(): void {
		// send_reminder_email() (6.3.0, intacto) y send_reminder_via_router()
		// (nuevo) deben coexistir — PT-2.2: no se elimina el camino viejo.
		$reflection = new \ReflectionClass( \CLMS_Student_Inactivity_Reminder_Service::class );
		$this->assertTrue( $reflection->hasMethod( 'send_reminder_email' ) );
		$this->assertTrue( $reflection->hasMethod( 'send_reminder_via_router' ) );
	}
}
