<?php

use PHPUnit\Framework\TestCase;

final class AcademicLibrarySchemaTest extends TestCase {
	public function test_versioned_schema_and_academic_links_exist(): void {
		$installer = file_get_contents( dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php' );
		foreach ( array( 'atora_library_items', 'atora_library_versions', 'atora_library_links', 'atora_library_events' ) as $table ) {
			$this->assertStringContainsString( $table, $installer );
		}
		$this->assertStringContainsString( 'UNIQUE KEY item_version', $installer );
		$this->assertStringContainsString( 'checksum_sha256', $installer );
		$this->assertStringContainsString( 'competency', file_get_contents( dirname( __DIR__, 2 ) . '/includes/library/class-academic-library-service.php' ) );
		$this->assertStringContainsString( 'evidence', file_get_contents( dirname( __DIR__, 2 ) . '/includes/library/class-academic-library-service.php' ) );
	}

	public function test_rest_api_is_isolated_and_admin_scoped(): void {
		$rest = file_get_contents( dirname( __DIR__, 2 ) . '/includes/library/class-academic-library-rest-controller.php' );
		$this->assertStringContainsString( "const BASE      = '/library';", $rest );
		$this->assertStringContainsString( "'manage_options'", $rest );
		$this->assertStringContainsString( "'permission_callback' => array( \$this, 'can_manage' )", $rest );
	}
}
