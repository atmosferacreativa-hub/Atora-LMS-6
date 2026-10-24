<?php

declare( strict_types = 1 );

use ATORA\SCORM\REST\SCORM_Controller;
use PHPUnit\Framework\TestCase;

final class ScormStartAttemptRequiresLinkedPackageTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_post_meta();
	}

	/** @test */
	public function test_start_attempt_returns_404_when_package_not_linked_to_lesson(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/scorm/class-scorm-attempt-service.php';
		require_once dirname( __DIR__, 2 ) . '/modules/scorm/rest/class-scorm-controller.php';

		atora_test_set_post_meta(
			55,
			'_clms_lesson_resources',
			array(
				array(
					'type' => 'scorm',
					'library_item_id' => 123,
					'library_version_id' => 0,
				),
			)
		);

		$controller = new SCORM_Controller( new CLMS_SCORM_Attempt_Service() );
		$request = new class {
			public function get_param( string $key ) {
				return match ( $key ) {
					'lesson_id' => 55,
					'library_item_id' => 999,
					default => 0,
				};
			}
		};

		$out = $controller->start_attempt( $request );
		$this->assertTrue( is_wp_error( $out ) );
		$this->assertSame( 'atora_scorm_resource_not_found', $out->get_error_code() );
		$this->assertSame( 404, (int) ( $out->get_error_data()['status'] ?? 0 ) );
	}
}

