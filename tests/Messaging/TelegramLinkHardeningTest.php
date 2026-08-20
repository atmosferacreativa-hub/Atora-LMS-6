<?php
/**
 * Telegram_Bot — entropía del código de vinculación + tope de intentos — PT-3 (sprint 6.5.4).
 *
 * Hallazgo confirmado: generate_link_code() usaba
 * substr(md5($chat_id . wp_salt() . time()), 0, 6) — ~24 bits de
 * entropía, derivados de entrada parcialmente predecible (time()).
 * El endpoint que recibe el código no tenía límite de intentos
 * fallidos — mismo patrón que el bug de WhatsApp corregido en 6.5.1.
 *
 * @package ATORA_LMS\Tests\Messaging
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Messaging;

use PHPUnit\Framework\TestCase;

class TelegramLinkHardeningTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_user_meta();
		atora_test_reset_transients();
	}

	protected function tearDown(): void {
		atora_test_reset_user_meta();
		atora_test_reset_transients();
		parent::tearDown();
	}

	private function locked( int $user_id ): bool {
		$ref = new \ReflectionMethod( \ATORA\Messaging\Telegram_Bot::class, 'link_attempts_locked' );
		$ref->setAccessible( true );
		return (bool) $ref->invoke( null, $user_id );
	}

	private function register_failure( int $user_id ): void {
		$ref = new \ReflectionMethod( \ATORA\Messaging\Telegram_Bot::class, 'register_link_attempt_failure' );
		$ref->setAccessible( true );
		$ref->invoke( null, $user_id );
	}

	private function reset( int $user_id ): void {
		$ref = new \ReflectionMethod( \ATORA\Messaging\Telegram_Bot::class, 'reset_link_attempts' );
		$ref->setAccessible( true );
		$ref->invoke( null, $user_id );
	}

	/** @test */
	public function test_link_code_has_at_least_40_bits_of_entropy(): void {
		$code = \ATORA\Messaging\Telegram_Bot::generate_link_code( '12345' );

		// 10 caracteres hexadecimales = 40 bits (bin2hex(random_bytes(5))).
		$this->assertSame( 10, strlen( $code ) );
		$this->assertMatchesRegularExpression( '/^[0-9A-F]{10}$/', $code, 'debe ser hex mayúsculas, alfabeto de 16 símbolos — 10 caracteres = 40 bits' );
	}

	/** @test */
	public function test_link_codes_are_not_predictable_from_time(): void {
		// Dos códigos generados en el mismo segundo para el mismo
		// chat_id no deben coincidir — si derivaran de time(), sí lo harían.
		$code_a = \ATORA\Messaging\Telegram_Bot::generate_link_code( '999' );
		$code_b = \ATORA\Messaging\Telegram_Bot::generate_link_code( '999' );

		$this->assertNotSame( $code_a, $code_b );
	}

	/** @test */
	public function test_locked_after_five_failed_attempts(): void {
		for ( $i = 0; $i < 4; $i++ ) {
			$this->register_failure( 1 );
			$this->assertFalse( $this->locked( 1 ), "no debería bloquearse antes del 5º intento (van " . ( $i + 1 ) . ')' );
		}
		$this->register_failure( 1 );

		$this->assertTrue( $this->locked( 1 ) );
	}

	/** @test */
	public function test_successful_link_resets_attempt_counter(): void {
		for ( $i = 0; $i < 4; $i++ ) {
			$this->register_failure( 2 );
		}
		$this->assertFalse( $this->locked( 2 ) );

		$this->reset( 2 ); // lo que hace ajax_link_account() en el camino exitoso

		$this->register_failure( 2 );
		$this->assertFalse( $this->locked( 2 ), 'tras un reset, hace falta agotar los 5 intentos de nuevo' );
	}

	/** @test */
	public function test_lockout_expires_after_window(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->register_failure( 3 );
		}
		$this->assertTrue( $this->locked( 3 ) );

		// Envejecer la ventana más allá de los 15 minutos.
		update_user_meta( 3, \ATORA\Messaging\Telegram_Bot::LINK_ATTEMPTS_WINDOW_META, time() - 20 * MINUTE_IN_SECONDS );

		$this->assertFalse( $this->locked( 3 ), 'pasada la ventana de lockout, debe permitir intentar de nuevo' );
	}

	/** @test */
	public function test_attempts_are_scoped_per_user(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->register_failure( 4 );
		}
		$this->assertTrue( $this->locked( 4 ) );
		$this->assertFalse( $this->locked( 5 ), 'los intentos fallidos de un usuario no deben afectar a otro' );
	}

	/** @test */
	public function test_valid_code_links_end_to_end(): void {
		$code = \ATORA\Messaging\Telegram_Bot::generate_link_code( '777888' );

		$this->assertSame( '777888', get_transient( 'atora_tg_link_' . $code ) );
	}
}
