<?php
/**
 * ATORA\Messaging\Preferences — PT-4 (sprint 6.4.0).
 *
 * @package ATORA_LMS\Tests\Messaging
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Messaging;

use PHPUnit\Framework\TestCase;

class PreferencesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_user_meta();
	}

	protected function tearDown(): void {
		atora_test_reset_user_meta();
		parent::tearDown();
	}

	/** @test */
	public function test_defaults_have_all_three_categories_enabled(): void {
		$prefs = \ATORA\Messaging\Preferences::get( 1 );
		$this->assertTrue( $prefs['categories']['academico'] );
		$this->assertTrue( $prefs['categories']['recordatorios'] );
		$this->assertTrue( $prefs['categories']['institucional'] );
	}

	/** @test */
	public function test_defaults_are_instant_frequency_no_dnd(): void {
		$prefs = \ATORA\Messaging\Preferences::get( 1 );
		$this->assertSame( 'instant', $prefs['frequency']['academico'] );
		$this->assertSame( '', $prefs['dnd_start'] );
		$this->assertSame( '', $prefs['dnd_end'] );
	}

	/** @test */
	public function test_save_and_get_roundtrip(): void {
		\ATORA\Messaging\Preferences::save( 5, array(
			'categories' => array( 'academico' => true, 'recordatorios' => false, 'institucional' => true ),
			'frequency'  => array( 'academico' => 'digest' ),
			'dnd_start'  => '22:00',
			'dnd_end'    => '07:00',
		) );

		$prefs = \ATORA\Messaging\Preferences::get( 5 );
		$this->assertFalse( $prefs['categories']['recordatorios'] );
		$this->assertTrue( $prefs['categories']['institucional'] );
		$this->assertSame( 'digest', $prefs['frequency']['academico'] );
		$this->assertSame( 'instant', $prefs['frequency']['recordatorios'], 'no tocado, debe seguir en default' );
		$this->assertSame( '22:00', $prefs['dnd_start'] );
		$this->assertSame( '07:00', $prefs['dnd_end'] );
	}

	/** @test */
	public function test_invalid_dnd_time_is_rejected(): void {
		\ATORA\Messaging\Preferences::save( 5, array( 'dnd_start' => 'no-es-una-hora' ) );
		$prefs = \ATORA\Messaging\Preferences::get( 5 );
		$this->assertSame( '', $prefs['dnd_start'] );
	}

	/** @test */
	public function test_unsubscribe_all_disables_all_categories_only(): void {
		\ATORA\Messaging\Preferences::save( 7, array( 'frequency' => array( 'academico' => 'digest' ) ) );
		\ATORA\Messaging\Preferences::unsubscribe_all( 7 );

		$prefs = \ATORA\Messaging\Preferences::get( 7 );
		foreach ( $prefs['categories'] as $enabled ) {
			$this->assertFalse( $enabled );
		}
		// La frecuencia guardada antes no se pierde ni se toca.
		$this->assertSame( 'digest', $prefs['frequency']['academico'] );
	}

	/** @test */
	public function test_whatsapp_inactive_without_verification(): void {
		update_user_meta( 3, 'atora_consent_whatsapp', true );
		update_user_meta( 3, 'atora_phone', '+584121234567' );
		// Sin atora_phone_verified.
		$this->assertFalse( \ATORA\Messaging\Preferences::is_whatsapp_active( 3 ) );
	}

	/** @test */
	public function test_whatsapp_active_only_with_consent_phone_and_verification(): void {
		update_user_meta( 3, 'atora_consent_whatsapp', true );
		update_user_meta( 3, 'atora_phone', '+584121234567' );
		update_user_meta( 3, \ATORA\Messaging\Preferences::META_PHONE_VERIFIED, true );

		$this->assertTrue( \ATORA\Messaging\Preferences::is_whatsapp_active( 3 ) );
	}

	/** @test */
	public function test_verify_phone_code_wrong_code_fails(): void {
		update_user_meta( 9, \ATORA\Messaging\Preferences::META_VERIFY_CODE_HASH, wp_hash( '123456' ) );
		update_user_meta( 9, \ATORA\Messaging\Preferences::META_VERIFY_EXPIRES, time() + 600 );

		$result = \ATORA\Messaging\Preferences::verify_phone_code( 9, '000000' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'codigo_incorrecto', $result['reason'] );
		$this->assertFalse( \ATORA\Messaging\Preferences::is_phone_verified( 9 ) );
	}

	/** @test */
	public function test_verify_phone_code_correct_code_succeeds(): void {
		update_user_meta( 9, \ATORA\Messaging\Preferences::META_VERIFY_CODE_HASH, wp_hash( '123456' ) );
		update_user_meta( 9, \ATORA\Messaging\Preferences::META_VERIFY_EXPIRES, time() + 600 );

		$result = \ATORA\Messaging\Preferences::verify_phone_code( 9, '123456' );
		$this->assertTrue( $result['ok'] );
		$this->assertTrue( \ATORA\Messaging\Preferences::is_phone_verified( 9 ) );
	}

	/** @test */
	public function test_verify_phone_code_expired_fails(): void {
		update_user_meta( 9, \ATORA\Messaging\Preferences::META_VERIFY_CODE_HASH, wp_hash( '123456' ) );
		update_user_meta( 9, \ATORA\Messaging\Preferences::META_VERIFY_EXPIRES, time() - 1 );

		$result = \ATORA\Messaging\Preferences::verify_phone_code( 9, '123456' );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'codigo_expirado', $result['reason'] );
	}

	/** @test */
	public function test_request_verification_without_phone_fails(): void {
		$result = \ATORA\Messaging\Preferences::request_phone_verification( 11 );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'sin_telefono', $result['reason'] );
	}

	/** @test */
	public function test_request_verification_rate_limited_after_3_attempts(): void {
		update_user_meta( 11, 'atora_phone', '+584121234567' );

		// Sin la clase WhatsApp cargada en el bootstrap de test, cada
		// solicitud falla en el envío — pero el intento ya se registró
		// antes de llegar ahí, que es lo que se está probando aquí.
		\ATORA\Messaging\Preferences::request_phone_verification( 11 );
		\ATORA\Messaging\Preferences::request_phone_verification( 11 );
		\ATORA\Messaging\Preferences::request_phone_verification( 11 );
		$fourth = \ATORA\Messaging\Preferences::request_phone_verification( 11 );

		$this->assertFalse( $fourth['ok'] );
		$this->assertSame( 'limite_intentos', $fourth['reason'] );
	}

	/** @test */
	public function test_invalidate_phone_verification_clears_verified_flag(): void {
		update_user_meta( 3, \ATORA\Messaging\Preferences::META_PHONE_VERIFIED, true );
		\ATORA\Messaging\Preferences::invalidate_phone_verification( 3 );
		$this->assertFalse( \ATORA\Messaging\Preferences::is_phone_verified( 3 ) );
	}

	/** @test */
	public function test_unsubscribe_token_roundtrip(): void {
		$token  = \ATORA\Messaging\Preferences::generate_unsubscribe_token( 42, 'academico' );
		$result = \ATORA\Messaging\Preferences::verify_unsubscribe_token( $token );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 42, $result['user_id'] );
		$this->assertSame( 'academico', $result['category'] );
	}

	/** @test */
	public function test_unsubscribe_token_tampered_fails(): void {
		$token = \ATORA\Messaging\Preferences::generate_unsubscribe_token( 42, 'academico' );
		$tampered = substr( $token, 0, -2 ) . 'xx';

		$result = \ATORA\Messaging\Preferences::verify_unsubscribe_token( $tampered );
		$this->assertFalse( $result['ok'] );
	}

	/** @test */
	public function test_unsubscribe_token_expired_fails(): void {
		$token  = \ATORA\Messaging\Preferences::generate_unsubscribe_token( 42, 'all', 0 );
		// ttl_days=0 se normaliza a 1 día mínimo internamente, así que
		// generamos uno ya vencido manipulando el reloj no es viable sin
		// mocks — en su lugar, verificamos que un token con expiración
		// pasada explícita (construido a mano con la misma firma) falla.
		$expires = time() - 10;
		$payload = '42|all|' . $expires;
		$sig     = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
		$expired_token = rtrim( strtr( base64_encode( $payload . '|' . $sig ), '+/', '-_' ), '=' );

		$result = \ATORA\Messaging\Preferences::verify_unsubscribe_token( $expired_token );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'token_expirado', $result['reason'] );
	}

	/** @test */
	public function test_unsubscribe_token_garbage_input_fails_cleanly(): void {
		$result = \ATORA\Messaging\Preferences::verify_unsubscribe_token( 'no-es-un-token-valido!!!' );
		$this->assertFalse( $result['ok'] );
	}
}
