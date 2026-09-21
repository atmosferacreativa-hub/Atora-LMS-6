<?php
/**
 * Integración: sesiones móviles en tabla, rotación con gracia bajo concurrencia.
 */

declare( strict_types = 1 );

final class MobileSessionsConcurrencyTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
	}

	public function test_two_rotations_with_same_refresh_token_both_succeed_within_grace_window(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$issued = ATORA_Mobile_Token_Service::issue( $user_id, 'Device A' );
		$this->assertNotEmpty( $issued['refresh_token'] ?? '' );

		$refresh = (string) $issued['refresh_token'];

		$r1 = ATORA_Mobile_Token_Service::rotate( $refresh, 'Device A' );
		$this->assertFalse( is_wp_error( $r1 ) );
		$this->assertNotEmpty( $r1['refresh_token'] ?? '' );

		// Simula "concurrencia": segundo refresh usando el mismo token viejo.
		$r2 = ATORA_Mobile_Token_Service::rotate( $refresh, 'Device A' );
		$this->assertFalse( is_wp_error( $r2 ) );
		$this->assertNotEmpty( $r2['refresh_token'] ?? '' );

		// Ambos access tokens emitidos deben validar.
		$v1 = ATORA_Mobile_Token_Service::validate( (string) ( $r1['access_token'] ?? '' ), 'access' );
		$v2 = ATORA_Mobile_Token_Service::validate( (string) ( $r2['access_token'] ?? '' ), 'access' );
		$this->assertFalse( is_wp_error( $v1 ) );
		$this->assertFalse( is_wp_error( $v2 ) );
	}
}

