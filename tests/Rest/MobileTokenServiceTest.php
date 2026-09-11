<?php

declare( strict_types = 1 );

namespace ATORA\Tests\Rest;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/mobile/class-mobile-token-service.php';

final class MobileTokenServiceTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		\atora_test_reset_user_meta();
	}

	public function test_issue_validate_and_revoke_access_token(): void {
		$issued = \ATORA_Mobile_Token_Service::issue( 27, 'Android de prueba' );

		$this->assertSame( 'Bearer', $issued['token_type'] );
		$this->assertSame( 900, $issued['expires_in'] );
		$this->assertStringNotContainsString( 'Android', $issued['access_token'] );

		$validated = \ATORA_Mobile_Token_Service::validate( $issued['access_token'] );
		$this->assertFalse( is_wp_error( $validated ) );
		$this->assertSame( 27, $validated['user_id'] );

		$this->assertTrue( \ATORA_Mobile_Token_Service::revoke_token( $issued['access_token'] ) );
		$this->assertTrue( is_wp_error( \ATORA_Mobile_Token_Service::validate( $issued['access_token'] ) ) );
	}

	public function test_refresh_rotation_invalidates_previous_session(): void {
		$issued  = \ATORA_Mobile_Token_Service::issue( 32, 'iPhone de prueba' );
		$rotated = \ATORA_Mobile_Token_Service::rotate( $issued['refresh_token'] );

		$this->assertFalse( is_wp_error( $rotated ) );
		$this->assertNotSame( $issued['session_id'], $rotated['session_id'] );
		$this->assertTrue( is_wp_error( \ATORA_Mobile_Token_Service::validate( $issued['access_token'] ) ) );
		$this->assertFalse( is_wp_error( \ATORA_Mobile_Token_Service::validate( $rotated['access_token'] ) ) );
	}

	public function test_rejects_malformed_token(): void {
		$result = \ATORA_Mobile_Token_Service::validate( 'not-a-token' );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'atora_mobile_invalid_token', $result->get_error_code() );
	}
}
