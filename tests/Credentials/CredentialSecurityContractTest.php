<?php

use PHPUnit\Framework\TestCase;

final class CredentialSecurityContractTest extends TestCase {
	public function test_public_verification_is_privacy_safe_and_rate_limited(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/credentials/class-credential-rest-controller.php' );
		$this->assertStringContainsString( "'permission_callback' => '__return_true'", $source );
		$this->assertStringContainsString( 'consume_public_quota', $source );
		$this->assertStringContainsString( "'status' => 429", $source );
		$this->assertStringNotContainsString( "'email' =>", file_get_contents( dirname( __DIR__, 2 ) . '/includes/credentials/class-credential-service.php' ) );
	}

	public function test_storage_uses_token_and_snapshot_hashes(): void {
		$installer = file_get_contents( dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php' );
		$this->assertStringContainsString( 'verification_token_hash', $installer );
		$this->assertStringContainsString( 'snapshot_hash', $installer );
		$this->assertStringContainsString( 'atora_credential_revocations', $installer );
		$this->assertStringContainsString( 'atora_credential_events', $installer );
	}

	public function test_audit_chain_and_separation_are_enforced(): void {
		$service = file_get_contents( dirname( __DIR__, 2 ) . '/includes/credentials/class-credential-service.php' );
		$this->assertStringContainsString( 'previous_hash', $service );
		$this->assertStringContainsString( 'can_decide', $service );
		$this->assertStringNotContainsString( "['status'] = 'valid'", $service );
	}
}
