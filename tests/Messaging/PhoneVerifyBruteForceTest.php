<?php
/**
 * Tope de intentos de validación del código de verificación — PT-5 (sprint 6.5.1).
 *
 * Hallazgo confirmado: verify_phone_code() no incrementaba ningún
 * contador ante código incorrecto — el límite de 3 solicitudes/hora
 * ya existía, pero nada limitaba cuántas veces se podía intentar
 * *adivinar* el código de 6 dígitos dentro de su ventana de 10
 * minutos.
 *
 * PT-5.3: verificado que /wp-admin/admin-ajax.php?action=
 * atora_verify_phone_code solo está registrado como wp_ajax_ (no
 * wp_ajax_nopriv_) en class-messaging-preferences-shortcode.php —
 * exige sesión autenticada, así que un tope por IP adicional no
 * aplica aquí (regla 5.3: "verificar eso primero").
 *
 * @package ATORA_LMS\Tests\Messaging
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Messaging;

use PHPUnit\Framework\TestCase;

class PhoneVerifyBruteForceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_user_meta();
	}

	protected function tearDown(): void {
		atora_test_reset_user_meta();
		parent::tearDown();
	}

	private function issue_code( int $user_id, string $code = '123456' ): void {
		update_user_meta( $user_id, 'atora_phone', '+58 412 1234567' );
		update_user_meta( $user_id, \ATORA\Messaging\Preferences::META_VERIFY_EXPIRES, time() + 600 );
		update_user_meta( $user_id, \ATORA\Messaging\Preferences::META_VERIFY_CODE_HASH, wp_hash( $code ) );
	}

	/** @test */
	public function test_sixth_attempt_is_rejected_even_with_correct_code(): void {
		$this->issue_code( 1, '123456' );

		for ( $i = 0; $i < 5; $i++ ) {
			$result = \ATORA\Messaging\Preferences::verify_phone_code( 1, '000000' );
			$this->assertFalse( $result['ok'] );
		}

		// El código sigue siendo el correcto, pero ya se agotaron los 5 intentos.
		$result = \ATORA\Messaging\Preferences::verify_phone_code( 1, '123456' );
		$this->assertFalse( $result['ok'], 'el 6º intento debe rechazarse aunque el código sea el correcto' );
	}

	/** @test */
	public function test_fifth_wrong_attempt_reports_too_many_attempts(): void {
		$this->issue_code( 2, '123456' );

		for ( $i = 0; $i < 4; $i++ ) {
			\ATORA\Messaging\Preferences::verify_phone_code( 2, '000000' );
		}
		$result = \ATORA\Messaging\Preferences::verify_phone_code( 2, '000000' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'demasiados_intentos', $result['reason'] );
	}

	/** @test */
	public function test_correct_code_within_attempt_limit_still_succeeds(): void {
		$this->issue_code( 3, '123456' );

		\ATORA\Messaging\Preferences::verify_phone_code( 3, '000000' );
		\ATORA\Messaging\Preferences::verify_phone_code( 3, '111111' );

		$result = \ATORA\Messaging\Preferences::verify_phone_code( 3, '123456' );
		$this->assertTrue( $result['ok'] );
	}

	/** @test */
	public function test_requesting_new_code_resets_the_attempt_counter(): void {
		$this->issue_code( 4, '123456' );

		for ( $i = 0; $i < 4; $i++ ) {
			\ATORA\Messaging\Preferences::verify_phone_code( 4, '000000' );
		}

		// Pedir un código nuevo — el contador de intentos vuelve a cero.
		\ATORA\Messaging\Preferences::request_phone_verification( 4 );

		$new_hash = get_user_meta( 4, \ATORA\Messaging\Preferences::META_VERIFY_CODE_HASH, true );
		$this->assertNotSame( wp_hash( '123456' ), $new_hash, 'debe haberse generado un código distinto' );

		$attempts = get_user_meta( 4, \ATORA\Messaging\Preferences::META_VERIFY_CODE_ATTEMPTS, true );
		$this->assertEmpty( $attempts );
	}
}
