<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ScormMobileGetValuesAuthorizationTest extends TestCase {
	private object $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->original_wpdb = $GLOBALS['wpdb'];
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		parent::tearDown();
	}

	/** @test */
	public function test_student_cannot_read_values_for_other_student_attempt(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/scorm/class-scorm-attempt-service.php';
		require_once dirname( __DIR__, 2 ) . '/includes/mobile/class-mobile-scorm-controller.php';

		$GLOBALS['__atora_test_current_user_id'] = 22; // student B

		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';
			public array $attempts = array(
				1 => array(
					'id' => 1,
					'user_id' => 11, // student A
					'lesson_id' => 55,
					'library_item_id' => 99,
					'library_version_id' => 101,
					'attempt_number' => 1,
					'scorm_version' => '1.2',
					'is_complete' => 0,
				),
			);

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
					return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
				}, $sql );
			}

			public function get_row( $sql, $output = ARRAY_A ) {
				if ( false !== strpos( (string) $sql, 'FROM wp_atora_scorm_attempts' ) && preg_match( '/WHERE id = (\d+)/', (string) $sql, $m ) ) {
					return $this->attempts[ (int) $m[1] ] ?? null;
				}
				return null;
			}

			public function get_results( $sql, $output = ARRAY_A ): array { return array(); }
		};

		$request = new WP_REST_Request( array( 'id' => 1 ) );
		$out = ATORA_Mobile_SCORM_Controller::get_values( $request );
		$this->assertTrue( is_wp_error( $out ) );
		$this->assertSame( 'clms_scorm_attempt_not_found', $out->get_error_code() );
		$this->assertSame( 404, (int) ( $out->get_error_data()['status'] ?? 0 ) );
	}
}

