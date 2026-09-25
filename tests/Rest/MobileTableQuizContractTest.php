<?php

declare( strict_types = 1 );

namespace {
	if ( ! function_exists( 'wp_insert_post' ) ) {
		function wp_insert_post( array $postarr, $wp_error = false ) {
			return 10001;
		}
	}
}

namespace ATORA\Tests\Rest {

	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/../../includes/mobile/class-mobile-rest-controller.php';

	final class TableQuizWpdb {
		public string $prefix   = 'wp_';
		public int $insert_id   = 9001;
		public array $inserts   = array();

		public function prepare( string $sql, ...$args ): string {
			$i = 0;
			return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
				$val = $args[ $i++ ] ?? '?';
				return is_scalar( $val ) ? (string) $val : json_encode( $val );
			}, $sql );
		}

		public function esc_like( string $s ): string {
			return addcslashes( $s, '_%\\' );
		}

		public function get_var( $sql ) {
			if ( is_string( $sql ) && str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
				if ( str_contains( $sql, 'atora_quiz_submissions' ) || str_contains( $sql, 'atora\\_quiz\\_submissions' ) ) {
					return 'wp_atora_quiz_submissions';
				}
			}
			return null;
		}

		public function get_row( $sql, $output = OBJECT ) {
			if ( ! is_string( $sql ) ) {
				return null;
			}
			if ( str_contains( $sql, 'COUNT(*) AS attempts_used' ) ) {
				return array(
					'attempts_used' => (int) ( $GLOBALS['__atora_table_quiz_attempts_used'] ?? 0 ),
					'best_score'    => (int) ( $GLOBALS['__atora_table_quiz_best_score'] ?? 0 ),
				);
			}
			if ( str_contains( $sql, 'FROM wp_atora_lessons WHERE id =' ) ) {
				return array( 'id' => 12, 'wp_post_id' => 5001, 'course_id' => 29, 'status' => 'published', 'title' => 'Lección' );
			}
			if ( str_contains( $sql, 'FROM wp_atora_courses WHERE id =' ) ) {
				return array( 'id' => 29, 'wp_post_id' => 428, 'status' => 'published', 'title' => 'Curso' );
			}
			return null;
		}

		public function insert( $table, $data, $format = null ): int {
			$this->inserts[] = array( 'table' => $table, 'data' => $data );
			$this->insert_id++;
			return 1;
		}
	}

	final class MobileTableQuizContractTest extends TestCase {
		private function invoke( string $method, array $args ) {
			$ref = new \ReflectionClass( \ATORA_Mobile_REST_Controller::class );
			$m   = $ref->getMethod( $method );
			$m->setAccessible( true );
			return $m->invokeArgs( null, $args );
		}

		protected function setUp(): void {
			parent::setUp();
			global $wpdb;
			$wpdb = new TableQuizWpdb();
			$GLOBALS['__atora_table_quiz_attempts_used'] = 0;
			$GLOBALS['__atora_table_quiz_best_score']    = 0;
			atora_test_reset_transients();
		}

		public function test_table_quiz_token_is_one_time(): void {
			$token = (string) $this->invoke( 'issue_table_quiz_token', array( 10, 12 ) );
			$this->assertNotSame( '', $token );
			$this->assertTrue( (bool) $this->invoke( 'validate_table_quiz_token', array( 10, 12, $token ) ) );
			$this->invoke( 'consume_table_quiz_token', array( 10, 12 ) );
			$this->assertFalse( (bool) $this->invoke( 'validate_table_quiz_token', array( 10, 12, $token ) ) );
		}

		public function test_table_quiz_stats_and_grade_use_attempts_and_best_score(): void {
			$GLOBALS['__atora_table_quiz_attempts_used'] = 1;
			$GLOBALS['__atora_table_quiz_best_score']    = 80;
			$stats = (array) $this->invoke( 'table_quiz_stats', array( 10, 12, 2 ) );
			$this->assertSame( 1, (int) ( $stats['attempts_used'] ?? 0 ) );
			$this->assertSame( 80, (int) ( $stats['best_score'] ?? 0 ) );

			$row = array(
				'id'            => 2,
				'questions_json' => json_encode( array(
					array( 'id' => 1, 'type' => 'single', 'weight' => 1, 'answer' => '4' ),
				) ),
				'settings_json'  => json_encode( array( 'attempts' => 2 ) ),
			);

			$result = (array) $this->invoke( 'grade_table_quiz', array( 10, 12, 29, $row, array( '4' ), 2, 80, 2 ) );
			$this->assertSame( 2, (int) ( $result['attempt'] ?? 0 ) );
			$this->assertSame( 100, (int) ( $result['score'] ?? 0 ) );
			$this->assertSame( 100, (int) ( $result['best_score'] ?? 0 ) );
			$this->assertFalse( (bool) ( $result['can_retry'] ?? true ) );
		}
	}
}
