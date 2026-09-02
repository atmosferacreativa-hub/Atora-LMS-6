<?php
/**
 * ATORA_Token_Crypto — P10.1 (sprint 6.13.0), bloqueante.
 *
 * @package ATORA_LMS\Tests\Security
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Security;

use PHPUnit\Framework\TestCase;

class TokenCryptoTest extends TestCase {

	/** @test */
	public function test_encrypt_then_decrypt_round_trips(): void {
		$plaintext  = 'ya29.example-access-token-value';
		$ciphertext = \ATORA_Token_Crypto::encrypt( $plaintext );

		$this->assertNotSame( $plaintext, $ciphertext, 'el valor cifrado no debe coincidir con el texto plano' );
		$this->assertSame( $plaintext, \ATORA_Token_Crypto::decrypt( $ciphertext ) );
	}

	/** @test */
	public function test_encrypted_value_carries_prefix_marker(): void {
		$ciphertext = \ATORA_Token_Crypto::encrypt( 'some-token' );
		$this->assertStringStartsWith( \ATORA_Token_Crypto::PREFIX, $ciphertext );
	}

	/** @test */
	public function test_empty_string_passes_through_both_ways(): void {
		$this->assertSame( '', \ATORA_Token_Crypto::encrypt( '' ) );
		$this->assertSame( '', \ATORA_Token_Crypto::decrypt( '' ) );
	}

	/** @test */
	public function test_legacy_plaintext_value_is_returned_as_is(): void {
		// Migración transparente: un valor guardado antes de 6.13.0, sin
		// el prefijo de marca, debe leerse tal cual en vez de fallar.
		$legacy = 'ya29.legacy-plaintext-token-from-before-encryption';
		$this->assertSame( $legacy, \ATORA_Token_Crypto::decrypt( $legacy ) );
	}

	/** @test */
	public function test_two_encryptions_of_the_same_plaintext_differ(): void {
		// IV aleatorio por llamada — nunca debe repetirse el ciphertext.
		$a = \ATORA_Token_Crypto::encrypt( 'same-token' );
		$b = \ATORA_Token_Crypto::encrypt( 'same-token' );

		$this->assertNotSame( $a, $b );
		$this->assertSame( 'same-token', \ATORA_Token_Crypto::decrypt( $a ) );
		$this->assertSame( 'same-token', \ATORA_Token_Crypto::decrypt( $b ) );
	}

	/** @test */
	public function test_tampered_ciphertext_fails_to_decrypt(): void {
		$ciphertext = \ATORA_Token_Crypto::encrypt( 'a-real-secret-token' );
		// Corromper el último carácter del payload base64 — GCM debe
		// rechazar la verificación de tag en vez de devolver basura.
		$tampered = substr( $ciphertext, 0, -1 ) . ( 'A' === substr( $ciphertext, -1 ) ? 'B' : 'A' );

		$this->assertSame( '', \ATORA_Token_Crypto::decrypt( $tampered ) );
	}

	/** @test */
	public function test_has_dedicated_key_reflects_atora_token_key_constant(): void {
		// ATORA_TOKEN_KEY no está definida en este entorno de test.
		$this->assertFalse( \ATORA_Token_Crypto::has_dedicated_key() );
	}
}
