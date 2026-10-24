<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ScormAttemptServiceClientEventIdempotencyTest extends TestCase {
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
	public function test_commits_are_idempotent_per_client_event_and_out_of_order_commits_do_not_regress(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/scorm/class-scorm-attempt-service.php';

		$GLOBALS['wpdb'] = new class {
			public string $prefix = 'wp_';
			public int $insert_id = 100;
			public int $attempt_updates = 0;
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
			public array $commit_events = array();
			public array $cmi = array();

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function( array $m ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) {
						return '?';
					}
					$arg = $args[ $i++ ];
					return '%s' === $m[0] ? ( "'" . (string) $arg . "'" ) : (string) $arg;
				}, $sql );
			}

			public function get_row( $sql, $output = ARRAY_A ) {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, 'FROM wp_atora_scorm_attempts' ) && preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
					return $this->attempts[ (int) $m[1] ] ?? null;
				}
				if ( false !== strpos( $sql, 'FROM wp_atora_scorm_commit_events' )
					&& preg_match( '/attempt_id = (\d+)/', $sql, $ma )
					&& preg_match( "/client_event_id = '([^']+)'/", $sql, $me ) ) {
					$attempt_id = (int) $ma[1];
					$event_id   = (string) $me[1];
					$key = $attempt_id . '|' . $event_id;
					return $this->commit_events[ $key ] ?? null;
				}
				return null;
			}

			public function get_results( $sql, $output = ARRAY_A ): array {
				return array();
			}

			public function get_var( $sql ) { return 0; }

			public function query( $sql ): int|false {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, 'INSERT INTO wp_atora_scorm_cmi' )
					&& preg_match( "/VALUES \((\d+), '([^']+)', '([^']*)'\)/", $sql, $m ) ) {
					$attempt_id = (int) $m[1];
					$key = (string) $m[2];
					$value = (string) $m[3];
					$this->cmi[ $attempt_id ][ $key ] = $value;
					return 1;
				}
				return 1;
			}

			public function insert( $table, $data, $format = null ): int|false {
				if ( 'wp_atora_scorm_commit_events' === (string) $table ) {
					$attempt_id = (int) ( $data['attempt_id'] ?? 0 );
					$event_id   = (string) ( $data['client_event_id'] ?? '' );
					$key = $attempt_id . '|' . $event_id;
					if ( isset( $this->commit_events[ $key ] ) ) {
						return false;
					}
					$this->insert_id++;
					$this->commit_events[ $key ] = array_merge( array( 'id' => $this->insert_id ), $data );
					return 1;
				}
				return 1;
			}

			public function update( $table, $data, $where, $format = null, $where_format = null ): int|false {
				if ( 'wp_atora_scorm_attempts' === (string) $table ) {
					$id = (int) ( $where['id'] ?? 0 );
					if ( $id && isset( $this->attempts[ $id ] ) ) {
						$this->attempt_updates++;
						$this->attempts[ $id ] = array_merge( $this->attempts[ $id ], $data );
					}
					return 1;
				}
				if ( 'wp_atora_scorm_commit_events' === (string) $table ) {
					$attempt_id = (int) ( $where['attempt_id'] ?? 0 );
					$event_id   = (string) ( $where['client_event_id'] ?? '' );
					$key = $attempt_id . '|' . $event_id;
					if ( isset( $this->commit_events[ $key ] ) ) {
						$this->commit_events[ $key ] = array_merge( $this->commit_events[ $key ], $data );
					}
					return 1;
				}
				return 1;
			}

			public function delete( $table, $where, $where_format = null ): int|false {
				if ( 'wp_atora_scorm_commit_events' === (string) $table ) {
					$attempt_id = (int) ( $where['attempt_id'] ?? 0 );
					$event_id   = (string) ( $where['client_event_id'] ?? '' );
					unset( $this->commit_events[ $attempt_id . '|' . $event_id ] );
					return 1;
				}
				return 1;
			}
		};

		$service = new CLMS_SCORM_Attempt_Service();

		$result = $service->commit_values_with_client_event(
			1,
			10,
			array(
				'cmi.core.lesson_status' => 'passed',
				'cmi.core.score.raw' => '80',
				'cmi.core.score.max' => '100',
			),
			'e1',
			'2026-10-24T10:00:00-04:00'
		);
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 1, (int) ( $result['attempt']['is_complete'] ?? 0 ) );
		$this->assertSame( 'passed', (string) ( $result['attempt']['success_status'] ?? '' ) );
		$this->assertSame( array( 55 ), get_user_meta( 10, '_clms_completed_lessons', true ) );
		$this->assertNotEmpty( $GLOBALS['wpdb']->commit_events['1|e1']['response_json'] ?? '' );

		$result = $service->commit_values_with_client_event(
			1,
			10,
			array(
				'cmi.core.lesson_status' => 'incomplete',
			),
			'e2',
			'2026-10-24T10:05:00-04:00'
		);
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 1, (int) ( $result['attempt']['is_complete'] ?? 0 ) );
		$this->assertSame( 'passed', (string) ( $result['attempt']['success_status'] ?? '' ) );
		$this->assertSame( array( 55 ), get_user_meta( 10, '_clms_completed_lessons', true ) );
		$attempt_updates_after_e2 = (int) $GLOBALS['wpdb']->attempt_updates;

		$result = $service->commit_values_with_client_event(
			1,
			10,
			array(
				'cmi.core.lesson_status' => 'failed',
			),
			'e2',
			'2026-10-24T10:10:00-04:00'
		);
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( $attempt_updates_after_e2, (int) $GLOBALS['wpdb']->attempt_updates );
		$this->assertSame( 1, (int) ( $GLOBALS['wpdb']->attempts[1]['is_complete'] ?? 0 ) );
		$this->assertSame( 'passed', (string) ( $GLOBALS['wpdb']->attempts[1]['success_status'] ?? '' ) );
	}
}

