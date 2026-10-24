<?php

use PHPUnit\Framework\TestCase;

final class ScormSchemaTest extends TestCase {
	public function test_scorm_attempts_and_cmi_tables_exist_in_installer(): void {
		$installer = file_get_contents( dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php' );
		$this->assertIsString( $installer );
		$this->assertStringContainsString( 'atora_scorm_attempts', $installer );
		$this->assertStringContainsString( 'atora_scorm_cmi', $installer );
		$this->assertStringContainsString( 'atora_scorm_commit_events', $installer );
		$this->assertStringContainsString( 'UNIQUE KEY attempt_key', $installer );
	}
}

