<?php

declare( strict_types = 1 );

namespace {
	if ( ! function_exists( 'wp_insert_post' ) ) {
		function wp_insert_post( array $postarr, $wp_error = false ) {
			if ( ! empty( $GLOBALS['__atora_test_wp_insert_post_fail'] ) ) {
				return new \WP_Error( 'wp_insert_post_failed', 'forced failure' );
			}
			return 12345;
		}
	}
	if ( ! function_exists( 'wp_generate_password' ) ) {
		function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ): string {
			$length = max( 1, (int) $length );
			return str_repeat( 'a', $length );
		}
	}
}

namespace ATORA\Tests\Rest {

	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/../../includes/mobile/class-mobile-rest-controller.php';

	final class PersistenceFailureWpdb {
		public string $prefix = 'wp_';
		public string $posts = 'wp_posts';
		public string $postmeta = 'wp_postmeta';
		public string $usermeta = 'wp_usermeta';
		public int $insert_id = 123;
		public bool $fail_insert = false;
		public int $insert_count = 0;

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
			if ( is_string( $sql ) && str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
				if ( str_contains( $sql, 'atora_quizzes' ) || str_contains( $sql, 'atora\\_quizzes' ) ) {
					return 'wp_atora_quizzes';
				}
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
			if ( str_contains( $sql, 'FROM wp_atora_lessons WHERE id =' ) ) {
				return array( 'id' => 12, 'course_id' => 29, 'status' => 'published', 'wp_post_id' => 5001, 'title' => 'Lección', 'type' => 'text' );
			}
			if ( str_contains( $sql, 'FROM wp_atora_courses WHERE id =' ) ) {
				return array( 'id' => 29, 'wp_post_id' => 428, 'status' => 'published', 'title' => 'Curso', 'excerpt' => '', 'thumbnail_url' => '', 'duration_hours' => 0, 'level' => '', 'language' => 'es' );
			}
			if ( str_contains( $sql, 'FROM wp_atora_quizzes' ) && str_contains( $sql, 'lesson_id' ) ) {
				return array(
					'id'           => 2,
					'lesson_id'    => 12,
					'course_id'    => 29,
					'questions_json' => json_encode( array( array( 'id' => 1, 'type' => 'single', 'question' => '2+2', 'options' => array( '4' ), 'answer' => '4', 'weight' => 1 ) ) ),
					'settings_json'  => json_encode( array( 'attempts' => 2, 'time_limit_seconds' => 0 ) ),
				);
			}
			if ( str_contains( $sql, 'COUNT(*) AS attempts_used' ) ) {
				return array( 'attempts_used' => 0, 'best_score' => 0 );
			}
			return null;
		}

		public function get_results( $sql, $output = OBJECT ): array {
			if ( is_string( $sql ) && str_contains( $sql, 'FROM wp_atora_enrollments e' ) ) {
				return array(
					array(
						'id'          => 1,
						'user_id'     => 10,
						'course_id'   => 29,
						'wp_course_id'=> 428,
						'status'      => 'active',
						'expires_at'  => '',
						'last_activity' => '2026-01-01 00:00:00',
					),
				);
			}
			return array();
		}

		public function insert( $table, $data, $format = null ) {
			if ( $this->fail_insert ) {
				return 0;
			}
			$this->insert_count++;
			$this->insert_id++;
			return 1;
		}
	}

	final class MobileTableQuizPersistenceFailureTest extends TestCase {
		protected function setUp(): void {
			parent::setUp();
			global $wpdb;
			$wpdb = new PersistenceFailureWpdb();
			$GLOBALS['__atora_test_current_user_id'] = 10;
			atora_test_set_drip_available( true );
			atora_test_reset_post_types();
			atora_test_set_post_type( 5001, 'lm_lesson' );
			atora_test_reset_transients();
			atora_test_reset_options();
			atora_test_reset_deleted_posts();
			atora_test_reset_post_meta();
		}

		public function test_submit_quiz_returns_error_when_wp_insert_post_fails(): void {
			$GLOBALS['__atora_test_wp_insert_post_fail'] = true;

			$req_quiz = new \WP_REST_Request( array( 'lesson_id' => 12 ) );
			$response = \ATORA_Mobile_REST_Controller::quiz( $req_quiz );
			$this->assertFalse( is_wp_error( $response ) );
			$quiz = (array) $response->get_data();
			$token = (string) ( ( $quiz['quiz']['token'] ?? '' ) ?: '' );
			$this->assertNotSame( '', $token );

			$req = new \WP_REST_Request( array( 'lesson_id' => 12, 'answers' => array( '4' ), 'token' => $token ) );
			$result = \ATORA_Mobile_REST_Controller::submit_quiz( $req );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'atora_mobile_quiz_persist_failed', $result->get_error_code() );
		}

		public function test_submit_quiz_returns_error_when_table_insert_fails(): void {
			$GLOBALS['__atora_test_wp_insert_post_fail'] = false;
			global $wpdb;
			$wpdb->fail_insert = true;

			$req_quiz = new \WP_REST_Request( array( 'lesson_id' => 12 ) );
			$response = \ATORA_Mobile_REST_Controller::quiz( $req_quiz );
			$this->assertFalse( is_wp_error( $response ) );
			$quiz  = (array) $response->get_data();
			$token = (string) ( ( $quiz['quiz']['token'] ?? '' ) ?: '' );
			$this->assertNotSame( '', $token );

			$req = new \WP_REST_Request( array( 'lesson_id' => 12, 'answers' => array( '4' ), 'token' => $token ) );
			$result = \ATORA_Mobile_REST_Controller::submit_quiz( $req );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'atora_mobile_quiz_persist_failed', $result->get_error_code() );

			$this->assertSame( array( 12345 ), array_values( (array) ( $GLOBALS['__atora_test_deleted_posts'] ?? array() ) ) );
			$this->assertSame( '', (string) get_post_meta( 12345, '_clms_submission_status', true ) );
		}

		public function test_retry_after_persist_failure_creates_a_single_final_attempt(): void {
			global $wpdb;
			$wpdb->fail_insert = true;

			$req_quiz = new \WP_REST_Request( array( 'lesson_id' => 12 ) );
			$response = \ATORA_Mobile_REST_Controller::quiz( $req_quiz );
			$this->assertFalse( is_wp_error( $response ) );
			$quiz  = (array) $response->get_data();
			$token = (string) ( ( $quiz['quiz']['token'] ?? '' ) ?: '' );
			$this->assertNotSame( '', $token );

			$req = new \WP_REST_Request( array( 'lesson_id' => 12, 'answers' => array( '4' ), 'token' => $token ) );
			$result = \ATORA_Mobile_REST_Controller::submit_quiz( $req );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'atora_mobile_quiz_persist_failed', $result->get_error_code() );
			$this->assertSame( 0, (int) $wpdb->insert_count );
			$this->assertSame( array( 12345 ), array_values( (array) ( $GLOBALS['__atora_test_deleted_posts'] ?? array() ) ) );
			$this->assertSame( '', (string) get_post_meta( 12345, '_clms_submission_status', true ) );

			// New GET issues a fresh transient and allows retry.
			$wpdb->fail_insert = false;
			atora_test_reset_transients();
			$response2 = \ATORA_Mobile_REST_Controller::quiz( new \WP_REST_Request( array( 'lesson_id' => 12 ) ) );
			$this->assertFalse( is_wp_error( $response2 ) );
			$quiz2  = (array) $response2->get_data();
			$token2 = (string) ( ( $quiz2['quiz']['token'] ?? '' ) ?: '' );
			$this->assertNotSame( '', $token2 );
			$result2 = \ATORA_Mobile_REST_Controller::submit_quiz( new \WP_REST_Request( array( 'lesson_id' => 12, 'answers' => array( '4' ), 'token' => $token2 ) ) );
			$this->assertFalse( is_wp_error( $result2 ) );
			$this->assertSame( 1, (int) $wpdb->insert_count );
		}
	}
}
