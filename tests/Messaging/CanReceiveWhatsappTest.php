<?php
/**
 * Preferences::can_receive_whatsapp() como única fuente de verdad — PT-3 (sprint 6.5.1).
 *
 * Regresión del hallazgo: Messaging_Router::user_accepts_channel() y
 * Campaign_Service::recipient_allows_channel() (y, encontrado al
 * revisar todo el árbol, CRM_V2::contact_can_receive_whatsapp()) solo
 * miraban atora_consent_whatsapp — un registro con consentimiento
 * heredado y atora_phone_verified=0 recibía WhatsApp real.
 *
 * Regla 6 del sprint (compatibilidad de datos): un usuario con
 * atora_consent_whatsapp=1 y atora_phone_verified=0 debe degradar a
 * email, no perder la cuenta ni forzar re-registro — se verifica que
 * simulate() resuelve a 'email' en ese caso, no a null/error.
 *
 * @package ATORA_LMS\Tests\Messaging
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Messaging;

use PHPUnit\Framework\TestCase;

class CanReceiveWhatsappTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_user_meta();
	}

	protected function tearDown(): void {
		atora_test_reset_user_meta();
		parent::tearDown();
	}

	private function set_consented_unverified( int $user_id ): void {
		update_user_meta( $user_id, 'atora_consent_whatsapp', 1 );
		update_user_meta( $user_id, 'atora_phone', '+58 412 1234567' );
		update_user_meta( $user_id, 'atora_phone_verified', 0 );
	}

	private function set_consented_verified( int $user_id ): void {
		update_user_meta( $user_id, 'atora_consent_whatsapp', 1 );
		update_user_meta( $user_id, 'atora_phone', '+58 412 1234567' );
		update_user_meta( $user_id, 'atora_phone_verified', 1 );
	}

	/** @test */
	public function test_can_receive_whatsapp_false_when_consent_but_not_verified(): void {
		$this->set_consented_unverified( 10 );
		$this->assertFalse( \ATORA\Messaging\Preferences::can_receive_whatsapp( 10 ) );
	}

	/** @test */
	public function test_can_receive_whatsapp_true_when_consent_and_verified(): void {
		$this->set_consented_verified( 11 );
		$this->assertTrue( \ATORA\Messaging\Preferences::can_receive_whatsapp( 11 ) );
	}

	/** @test */
	public function test_can_receive_whatsapp_false_when_verified_but_empty_phone(): void {
		update_user_meta( 12, 'atora_consent_whatsapp', 1 );
		update_user_meta( 12, 'atora_phone_verified', 1 );
		update_user_meta( 12, 'atora_phone', '' );
		$this->assertFalse( \ATORA\Messaging\Preferences::can_receive_whatsapp( 12 ) );
	}

	/** @test */
	public function test_can_receive_whatsapp_false_without_consent(): void {
		update_user_meta( 13, 'atora_phone', '+58 412 1234567' );
		update_user_meta( 13, 'atora_phone_verified', 1 );
		$this->assertFalse( \ATORA\Messaging\Preferences::can_receive_whatsapp( 13 ) );
	}

	/** @test */
	public function test_is_whatsapp_active_delegates_to_can_receive_whatsapp(): void {
		$this->set_consented_verified( 14 );
		$this->assertTrue( \ATORA\Messaging\Preferences::is_whatsapp_active( 14 ) );

		$this->set_consented_unverified( 15 );
		$this->assertFalse( \ATORA\Messaging\Preferences::is_whatsapp_active( 15 ) );
	}

	/** @test */
	public function test_router_simulate_falls_back_to_email_when_not_verified(): void {
		// Regla 6: sin migración de datos — el registro viejo sigue
		// existiendo y funcionando, solo degrada de canal.
		$this->set_consented_unverified( 20 );

		$sim = \ATORA\Messaging\Messaging_Router::simulate( 20, 'assignment_graded' );

		$this->assertNotContains( 'whatsapp', $sim['consented_channels'] );
		$this->assertSame( 'email', $sim['resolved_channel'] );
	}

	/** @test */
	public function test_router_simulate_uses_whatsapp_when_verified(): void {
		$this->set_consented_verified( 21 );

		$sim = \ATORA\Messaging\Messaging_Router::simulate( 21, 'assignment_graded' );

		$this->assertContains( 'whatsapp', $sim['consented_channels'] );
		$this->assertSame( 'whatsapp', $sim['resolved_channel'] );
	}

	/** @test */
	public function test_campaign_service_recipient_allows_channel_respects_verification(): void {
		$this->set_consented_unverified( 30 );
		$this->assertFalse( $this->invoke_recipient_allows_channel( 'whatsapp', 30, 0 ) );

		$this->set_consented_verified( 31 );
		$this->assertTrue( $this->invoke_recipient_allows_channel( 'whatsapp', 31, 0 ) );
	}

	/** @test */
	public function test_crm_v2_contact_can_receive_whatsapp_respects_verification(): void {
		$this->set_consented_unverified( 40 );
		$contact_unverified = array( 'user_id' => 40, 'phone' => '+58 412 1234567' );
		$this->assertFalse( $this->invoke_contact_can_receive_whatsapp( $contact_unverified ) );

		$this->set_consented_verified( 41 );
		$contact_verified = array( 'user_id' => 41, 'phone' => '+58 412 1234567' );
		$this->assertTrue( $this->invoke_contact_can_receive_whatsapp( $contact_verified ) );
	}

	private function invoke_recipient_allows_channel( string $channel, int $user_id, int $contact_id ): bool {
		$ref = new \ReflectionMethod( \ATORA\CRM_V2\Services\Campaign_Service::class, 'recipient_allows_channel' );
		$ref->setAccessible( true );
		return (bool) $ref->invoke( null, $channel, $user_id, $contact_id );
	}

	private function invoke_contact_can_receive_whatsapp( array $contact ): bool {
		$ref = new \ReflectionMethod( \ATORA\CRM_V2\CRM_V2::class, 'contact_can_receive_whatsapp' );
		$ref->setAccessible( true );
		return (bool) $ref->invoke( null, $contact );
	}
}
