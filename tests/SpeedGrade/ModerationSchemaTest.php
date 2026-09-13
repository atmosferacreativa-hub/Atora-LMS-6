<?php

use PHPUnit\Framework\TestCase;

final class SpeedGradeModerationSchemaTest extends TestCase {
	public function test_schema_and_cycle_gate_are_declared(): void {
		$installer = file_get_contents( dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php' );
		$service   = file_get_contents( dirname( __DIR__, 2 ) . '/includes/gradebook/class-institutional-gradebook-service.php' );

		$this->assertStringContainsString( 'atora_grade_moderations', $installer );
		$this->assertStringContainsString( 'submission_cycle', $installer );
		$this->assertStringContainsString( 'count_unresolved', $service );
		$this->assertStringContainsString( 'clms_cycle_moderation_pending', $service );
	}
}
