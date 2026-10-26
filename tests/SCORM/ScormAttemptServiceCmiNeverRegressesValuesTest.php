<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ScormAttemptServiceCmiNeverRegressesValuesTest extends TestCase {
	private object $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->original_wpdb = $GLOBALS['wpdb'];
		atora_test_reset_options();
		atora_test_reset_filters();
		atora_test_reset_post_meta();
		atora_test_reset_user_meta();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		parent::tearDown();
	}

	/** @test */
	public function test_commit_values_does_not_regress_cmi_snapshot_on_out_of_order_events(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/scorm/class-scorm-attempt-service.php';
		require_once dirname( __DIR__, 2 ) . '/includes/mobile/class-mobile-scorm-controller.php';

		$GLOBALS['__atora_test_current_user_id'] = 10;

		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';
			public int $insert_id = 1;
			public array $attempts = array(
				1 => array(
					'id' => 1,
					'institution_id' => 1,
					'user_id' => 10,
					'lesson_id' => 55,
					'library_item_id' => 99,
					'library_version_id' => 101,
					'attempt_number' => 1,
					'scorm_version' => '1.2',
					'grade_status' => 'in_review',
					'completion_status' => 'unknown',
					'success_status' => 'unknown',
					'is_complete' => 0,
				),
			);
			public array $cmi = array();

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

			public function update( $table, $data, $where, $format = null, $where_format = null ): int {
				$id = (int) ( is_array( $where ) ? ( $where['id'] ?? 0 ) : 0 );
				if ( $id && isset( $this->attempts[ $id ] ) && is_array( $data ) ) {
					$this->attempts[ $id ] = array_merge( $this->attempts[ $id ], $data );
				}
				return 1;
			}

			public function query( $sql ): int {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, 'INSERT INTO wp_atora_scorm_cmi' ) && preg_match( '/VALUES \((\d+),\s*([^,]+),\s*([^\)]+)\)/', $sql, $m ) ) {
					$attempt_id = (int) $m[1];
					$key = trim( (string) $m[2] );
					$val = trim( (string) $m[3] );
					$this->cmi[ $attempt_id ][ $key ] = $val;
					return 1;
				}
				return 1;
			}

			public function insert( $table, $data, $format = null ): int { $this->insert_id++; return 1; }
			public function get_var( $sql ) { return 0; }

			public function get_results( $sql, $output = ARRAY_A ): array {
				$sql = (string) $sql;
				if ( false === strpos( $sql, 'FROM wp_atora_scorm_cmi' ) ) {
					return array();
				}
				$attempt_id = 0;
				if ( preg_match( '/attempt_id = (\d+)/', $sql, $m ) ) {
					$attempt_id = (int) $m[1];
				}
				$rows = array();
				if ( $attempt_id <= 0 || empty( $this->cmi[ $attempt_id ] ) ) {
					return array();
				}
				$allowed_keys = null;
				if ( preg_match( '/cmi_key IN \(([^\)]+)\)/', $sql, $in ) ) {
					$allowed_keys = array_map( 'trim', explode( ',', (string) $in[1] ) );
				}
				foreach ( $this->cmi[ $attempt_id ] as $key => $value ) {
					if ( is_array( $allowed_keys ) && ! in_array( $key, $allowed_keys, true ) ) {
						continue;
					}
					$rows[] = array( 'cmi_key' => (string) $key, 'cmi_value' => (string) $value );
				}
				return $rows;
			}
		};

		$service = new CLMS_SCORM_Attempt_Service();
		$result = $service->commit_values(
			1,
			10,
			array(
				'cmi.core.lesson_status' => 'completed',
				'cmi.core.score.raw' => '80',
				'cmi.core.score.max' => '100',
			)
		);
		$this->assertFalse( is_wp_error( $result ) );

		$result = $service->commit_values(
			1,
			10,
			array(
				'cmi.core.lesson_status' => 'incomplete',
			)
		);
		$this->assertFalse( is_wp_error( $result ) );

		$response = ATORA_Mobile_SCORM_Controller::get_values( new WP_REST_Request( array( 'id' => 1 ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'completed', (string) ( $data['values']['cmi.core.lesson_status'] ?? '' ) );
		$this->assertSame( '80', (string) ( $data['values']['cmi.core.score.raw'] ?? '' ) );
	}
}

