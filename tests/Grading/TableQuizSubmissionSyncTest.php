<?php

declare( strict_types = 1 );

namespace ATORA\Tests\Grading {
	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/../../includes/grading/class-table-quiz-submission-sync.php';

	final class TableQuizSubmissionSyncWpdb {
		public string $prefix = 'wp_';
		public string $last_error = '';
		public array $existing_wp_post_ids = array();
		public array $updates = array();
		public array $queries = array();
		public $update_return = 1;
		public $query_return = 1;

		public function prepare( string $sql, ...$args ): string {
			$i = 0;
			return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
				return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
			}, $sql );
		}

		public function esc_like( string $s ): string {
			return addcslashes( $s, '_%\\' );
		}

		public function get_var( $sql ) {
			if ( ! is_string( $sql ) ) {
				return null;
			}
			if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
				return 'wp_atora_quiz_submissions';
			}
			if ( str_contains( $sql, 'FROM wp_atora_quiz_submissions' ) && str_contains( $sql, 'wp_post_id' ) ) {
				if ( preg_match( '/wp_post_id\s*=\s*(\d+)/', $sql, $m ) ) {
					$wp_post_id = (int) $m[1];
					return in_array( $wp_post_id, $this->existing_wp_post_ids, true ) ? 777 : null;
				}
			}
			return null;
		}

		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			$this->updates[] = array(
				'table'        => $table,
				'data'         => $data,
				'where'        => $where,
				'format'       => $format,
				'where_format' => $where_format,
			);
			return $this->update_return;
		}

		public function query( $sql ) {
			$this->queries[] = $sql;
			return $this->query_return;
		}
	}

	final class TableQuizSubmissionSyncTest extends TestCase {
		protected function setUp(): void {
			parent::setUp();
			global $wpdb;
			$wpdb = new TableQuizSubmissionSyncWpdb();
			atora_test_reset_post_types();
			atora_test_set_post_type( 123, 'clms_submission' );
		}

		public function test_updates_only_row_linked_by_wp_post_id(): void {
			global $wpdb;
			$wpdb->existing_wp_post_ids = array( 123 );

			$this->assertTrue( \CLMS_Table_Quiz_Submission_Sync::sync( 123, 'graded', 85 ) );
			$this->assertCount( 1, $wpdb->updates );
			$u = (array) $wpdb->updates[0];
			$this->assertSame( 'wp_atora_quiz_submissions', (string) ( $u['table'] ?? '' ) );
			$this->assertSame( array( 'wp_post_id' => 123 ), $u['where'] );
			$this->assertSame( 85.0, (float) $u['data']['grade'] );
			$this->assertSame( 'graded', (string) $u['data']['status'] );
			$this->assertArrayHasKey( 'graded_at', $u['data'] );

			// Otro submission (legacy / sin fila) no debe tocar nada.
			$this->assertTrue( \CLMS_Table_Quiz_Submission_Sync::sync( 999, 'graded', 90 ) );
			$this->assertCount( 1, $wpdb->updates );
		}

		public function test_distinguishes_zero_grade_from_empty_grade(): void {
			global $wpdb;
			$wpdb->existing_wp_post_ids = array( 123 );

			$this->assertTrue( \CLMS_Table_Quiz_Submission_Sync::sync( 123, 'graded', 0 ) );
			$this->assertCount( 1, $wpdb->updates );
			$this->assertSame( 0.0, (float) $wpdb->updates[0]['data']['grade'] );

			$wpdb->updates = array();
			$this->assertTrue( \CLMS_Table_Quiz_Submission_Sync::sync( 123, 'graded', '' ) );
			$this->assertCount( 0, $wpdb->updates );
			$this->assertCount( 1, $wpdb->queries );
			$this->assertStringContainsString( 'grade = NULL', (string) $wpdb->queries[0] );
		}

		public function test_noop_for_legacy_submission_without_table_row(): void {
			global $wpdb;
			$wpdb->existing_wp_post_ids = array();

			$this->assertTrue( \CLMS_Table_Quiz_Submission_Sync::sync( 123, 'graded', 50 ) );
			$this->assertCount( 0, $wpdb->updates );
			$this->assertCount( 0, $wpdb->queries );
		}

		public function test_returns_false_when_db_update_fails(): void {
			global $wpdb;
			$wpdb->existing_wp_post_ids = array( 123 );
			$wpdb->update_return = false;
			$wpdb->last_error = 'boom';

			$this->assertFalse( \CLMS_Table_Quiz_Submission_Sync::sync( 123, 'graded', 80 ) );
		}
	}
}

