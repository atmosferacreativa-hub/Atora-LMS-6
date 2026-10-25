<?php

declare( strict_types = 1 );

use ATORA\SCORM\REST\SCORM_Controller;
use PHPUnit\Framework\TestCase;

final class ScormMaxAttemptsLimitTest extends TestCase {
	private object $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->original_wpdb = $GLOBALS['wpdb'];
		atora_test_reset_post_meta();
		atora_test_reset_options();
		update_option( 'atora_default_institution', 1 );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		parent::tearDown();
	}

	/** @test */
	public function test_start_attempt_returns_409_when_no_attempts_left(): void {
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
					'scorm_max_attempts' => 1,
				),
			)
		);
		atora_test_set_post_meta( 55, '_clms_scorm_max_attempts', 1 );
		atora_test_set_post_type( 55, 'lm_lesson' );
		$GLOBALS['__atora_test_current_user_id'] = 10;

		$meta = array(
			'scorm' => array(
				'scorm_version' => '1.2',
				'launch_url' => 'https://example.test/scorm/index.html',
				'launch_href' => 'index.html',
				'content_base_url' => 'https://example.test/scorm',
			),
		);

		$GLOBALS['wpdb'] = new class( $meta ) {
			public string $prefix = 'wp_';
			private array $meta;

			public function __construct( array $meta ) { $this->meta = $meta; }

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
					return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
				}, $sql );
			}

			public function get_row( $sql, $output = ARRAY_A ) {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, 'FROM wp_atora_library_items' ) ) {
					return array(
						'id' => 123,
						'current_version_id' => 900,
						'status' => 'published',
						'resource_type' => 'scorm',
					);
				}
				if ( false !== strpos( $sql, 'FROM wp_atora_library_versions' ) ) {
					return array( 'id' => 900, 'item_id' => 123, 'metadata_json' => wp_json_encode( $this->meta ) );
				}
				if ( false !== strpos( $sql, 'FROM wp_atora_scorm_attempts' ) ) {
					return array(
						'id' => 1,
						'user_id' => 10,
						'lesson_id' => 55,
						'library_item_id' => 123,
						'library_version_id' => 900,
						'attempt_number' => 1,
						'scorm_version' => '1.2',
						'is_complete' => 1,
					);
				}
				return null;
			}

			public function get_var( $sql ) {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) && false !== strpos( $sql, 'atora_institutions' ) ) {
					return 'wp_atora_institutions';
				}
				if ( false !== strpos( $sql, 'SELECT 1 FROM wp_atora_institutions' ) ) {
					return 1;
				}
				if ( false !== strpos( $sql, 'SELECT COUNT(*) FROM wp_atora_scorm_attempts' ) ) {
					return 1;
				}
				return '';
			}

			public function esc_like( string $text ): string {
				return $text;
			}

			public function insert( $table, $data, $format = null ): int { return 0; }
			public function update( $table, $data, $where, $format = null, $where_format = null ): int { return 0; }
			public function query( $sql ): int { return 0; }
			public function get_results( $sql, $output = ARRAY_A ): array { return array(); }
		};

		$controller = new SCORM_Controller( new CLMS_SCORM_Attempt_Service() );
		$request = new class {
			public function get_param( string $key ) {
				return match ( $key ) {
					'lesson_id' => 55,
					'library_item_id' => 123,
					'library_version_id' => 999,
					default => 0,
				};
			}
		};

		$out = $controller->start_attempt( $request );
		$this->assertTrue( is_wp_error( $out ) );
		$this->assertSame( 'atora_scorm_no_attempts', $out->get_error_code() );
		$this->assertSame( 409, (int) ( $out->get_error_data()['status'] ?? 0 ) );
	}
}

