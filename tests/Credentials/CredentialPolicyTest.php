<?php

use PHPUnit\Framework\TestCase;

final class CredentialPolicyTest extends TestCase {
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/credentials/class-credential-policy.php';
	}

	public function test_revocation_requires_separation_of_duties(): void {
		$this->assertFalse( CLMS_Credential_Policy::can_decide( 17, 17 ) );
		$this->assertTrue( CLMS_Credential_Policy::can_decide( 17, 18 ) );
	}

	public function test_revoked_credentials_are_not_reactivated(): void {
		$this->assertTrue( CLMS_Credential_Policy::can_reissue( 'revoked' ) );
		$this->assertFalse( CLMS_Credential_Policy::can_request_revocation( 'revoked' ) );
		$this->assertFalse( CLMS_Credential_Policy::can_request_revocation( 'superseded' ) );
	}

	public function test_snapshot_hash_is_canonical_and_detects_mutation(): void {
		$left = array( 'holder' => 'Ada', 'evidence' => array( 'b' => 2, 'a' => 1 ) );
		$right = array( 'evidence' => array( 'a' => 1, 'b' => 2 ), 'holder' => 'Ada' );
		$this->assertSame( CLMS_Credential_Policy::snapshot_hash( $left ), CLMS_Credential_Policy::snapshot_hash( $right ) );
		$right['holder'] = 'Grace';
		$this->assertNotSame( CLMS_Credential_Policy::snapshot_hash( $left ), CLMS_Credential_Policy::snapshot_hash( $right ) );
	}

	public function test_only_explicit_decisions_are_accepted(): void {
		$this->assertTrue( CLMS_Credential_Policy::is_decision( 'approved' ) );
		$this->assertTrue( CLMS_Credential_Policy::is_decision( 'rejected' ) );
		$this->assertFalse( CLMS_Credential_Policy::is_decision( 'pending' ) );
	}
}
