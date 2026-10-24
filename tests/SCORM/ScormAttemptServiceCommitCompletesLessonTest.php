<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ScormAttemptServiceCommitCompletesLessonTest extends TestCase {
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
	public function test_commit_marks_attempt_complete_and_calls_complete_lesson_override(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/scorm/class-scorm-attempt-service.php';

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
				$id = (int) ( $where['id'] ?? 0 );
				if ( $id && isset( $this->attempts[ $id ] ) ) {
					$this->attempts[ $id ] = array_merge( $this->attempts[ $id ], $data );
				}
				return 1;
			}

			public function query( $sql ): int { return 1; }
			public function insert( $table, $data, $format = null ): int { $this->insert_id++; return 1; }
			public function get_results( $sql, $output = ARRAY_A ): array { return array(); }
		};

		$service = new CLMS_SCORM_Attempt_Service();
		$result = $service->commit_values(
			1,
			10,
			array(
				'cmi.core.lesson_status' => 'passed',
				'cmi.core.score.raw' => '80',
				'cmi.core.score.max' => '100',
				'cmi.core.session_time' => '00:10:00',
			)
		);
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 1, (int) ( $result['attempt']['id'] ?? 0 ) );
		$this->assertSame( 1, (int) ( $result['attempt']['is_complete'] ?? 0 ) );
		$this->assertSame( 'graded', (string) ( $result['attempt']['grade_status'] ?? '' ) );
		$this->assertSame( 80.0, (float) ( $result['attempt']['score_percent'] ?? 0.0 ) );
		$this->assertSame( array( 55 ), get_user_meta( 10, '_clms_completed_lessons', true ) );
	}
}

