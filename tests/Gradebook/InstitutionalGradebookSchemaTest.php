<?php
/**
 * Gradebook institucional — contrato estructural.
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Gradebook;

use PHPUnit\Framework\TestCase;

final class InstitutionalGradebookSchemaTest extends TestCase {

	public function test_installer_declares_all_institutional_tables(): void {
		$installer = (string) file_get_contents( __DIR__ . '/../../modules/class-v5-installer.php' );
		$tables = array(
			'atora_academic_periods',
			'atora_grading_scales',
			'atora_gradebook_cycles',
			'atora_institutional_grades',
			'atora_grade_rectifications',
			'atora_gradebook_events',
		);

		foreach ( $tables as $table ) {
			$this->assertStringContainsString( $table, $installer );
		}
		$this->assertStringContainsString( "const SCHEMA_VERSION = '6.22.0-institutional-gradebook';", $installer );
	}

	public function test_rest_controller_is_admin_scoped_and_isolated(): void {
		$controller = (string) file_get_contents( __DIR__ . '/../../includes/gradebook/class-institutional-gradebook-rest-controller.php' );

		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $controller );
		$this->assertStringContainsString( "'/gradebook/institutional", $controller );
		$this->assertStringContainsString( "'permission_callback' => array( \$this, 'can_manage' )", $controller );
	}

	public function test_rectification_requires_separation_of_duties(): void {
		$service = (string) file_get_contents( __DIR__ . '/../../includes/gradebook/class-institutional-gradebook-service.php' );

		$this->assertStringContainsString( 'clms_rectification_separation_of_duties', $service );
		$this->assertStringContainsString( "'START TRANSACTION'", $service );
		$this->assertStringContainsString( "'ROLLBACK'", $service );
		$this->assertStringContainsString( "'COMMIT'", $service );
	}
}
