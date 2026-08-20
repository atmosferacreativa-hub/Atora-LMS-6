<?php
/**
 * Forms_Builder — throttle por IP/formulario — PT-5 (sprint 6.5.4).
 *
 * Hallazgo confirmado: los formularios tienen nonce y honeypot, pero
 * ningún límite de tasa. El nonce se obtiene de la propia página
 * pública, así que un script puede solicitarla, extraerlo y enviar
 * entradas repetidamente — llenando atora_form_entries y disparando
 * notificaciones al administrador por cada envío.
 *
 * @package ATORA_LMS\Tests\Analytics
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Analytics;

use PHPUnit\Framework\TestCase;

class FormsThrottleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_transients();
		atora_test_reset_post_meta();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CLIENT_IP'] );
	}

	protected function tearDown(): void {
		atora_test_reset_transients();
		atora_test_reset_post_meta();
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CLIENT_IP'] );
		parent::tearDown();
	}

	private function is_throttled( int $form_id ): bool {
		$ref = new \ReflectionMethod( \ATORA\Analytics\Forms_Builder::class, 'is_throttled' );
		$ref->setAccessible( true );
		return (bool) $ref->invoke( null, $form_id );
	}

	/** @test */
	public function test_eleventh_submission_from_same_ip_is_throttled(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertFalse( $this->is_throttled( 1 ), "intento " . ( $i + 1 ) . " no debería bloquearse todavía" );
		}

		$this->assertTrue( $this->is_throttled( 1 ), 'el 11º envío en la ventana debe rechazarse' );
	}

	/** @test */
	public function test_different_ips_have_independent_counters(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->is_throttled( 2 );
		}
		$this->assertTrue( $this->is_throttled( 2 ) );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->assertFalse( $this->is_throttled( 2 ), 'una IP distinta no debe verse afectada por el consumo de otra' );
	}

	/** @test */
	public function test_different_forms_have_independent_counters_for_same_ip(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->is_throttled( 3 );
		}
		$this->assertTrue( $this->is_throttled( 3 ) );

		$this->assertFalse( $this->is_throttled( 4 ), 'un formulario distinto, misma IP, debe tener su propio contador' );
	}

	/** @test */
	public function test_form_specific_throttle_limit_from_schema_is_respected(): void {
		atora_test_set_post_meta( 5, 'atora_form_schema', wp_json_encode( array( 'throttle_per_15min' => 3 ) ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertFalse( $this->is_throttled( 5 ) );
		}
		$this->assertTrue( $this->is_throttled( 5 ), 'debe respetar el límite configurado en el schema, no el default de 10' );
	}

	/** @test */
	public function test_no_ip_available_does_not_block(): void {
		unset( $_SERVER['REMOTE_ADDR'] );

		$this->assertFalse( $this->is_throttled( 6 ), 'sin IP determinable, no se bloquea (regla 5.3 — nada confiable que contar)' );
	}
}
