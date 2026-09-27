<?php

declare( strict_types = 1 );

namespace {
	require_once __DIR__ . '/Fixtures/table-quiz-wp-insert-post.php';

	if ( ! function_exists( 'wp_generate_password' ) ) {
		function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ): string {
			$length = max( 1, (int) $length );
			return str_repeat( 'a', $length );
		}
	}
}

namespace ATORA\Tests\Rest {

	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/../stubs/class-clms-helper-stub.php';
	require_once __DIR__ . '/../../includes/mobile/class-mobile-rest-controller.php';

	final class IdentityMismatchWpdb {
		public static array $advisory_locks = array();
		public string $connection_id;
		public string $prefix = 'wp_';
		public string $posts = 'wp_posts';
		public string $postmeta = 'wp_postmeta';
		public string $usermeta = 'wp_usermeta';
		public int $insert_count = 0;
		public int $lesson_wp_post_id = 5001;
		public int $course_wp_post_id = 428;
		public int $lesson_course_id = 29;
		public int $quiz_course_id = 29;

		public function __construct( string $connection_id = 'conn' ) {
			$this->connection_id = $connection_id;
		}

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
			if ( is_string( $sql ) && str_contains( $sql, 'GET_LOCK(' ) ) {
				preg_match( '/GET_LOCK\(([^,]+),/i', $sql, $m );
				$lock_key = trim( (string) ( $m[1] ?? '' ), " \t\n\r\0\x0B'\"" );
				if ( '' === $lock_key ) {
					return 0;
				}
				if ( ! isset( self::$advisory_locks[ $lock_key ] ) ) {
					self::$advisory_locks[ $lock_key ] = $this->connection_id;
					return 1;
				}
				return 0;
			}
			if ( is_string( $sql ) && str_contains( $sql, 'RELEASE_LOCK(' ) ) {
				preg_match( '/RELEASE_LOCK\(([^\)]+)\)/i', $sql, $m );
				$lock_key = trim( (string) ( $m[1] ?? '' ), " \t\n\r\0\x0B'\"" );
				if ( '' === $lock_key ) {
					return 0;
				}
				if ( isset( self::$advisory_locks[ $lock_key ] ) && self::$advisory_locks[ $lock_key ] === $this->connection_id ) {
					unset( self::$advisory_locks[ $lock_key ] );
					return 1;
				}
				return 0;
			}
			if ( is_string( $sql ) && str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
				if ( str_contains( $sql, 'atora\\_quizzes' ) || str_contains( $sql, 'atora_quizzes' ) ) {
					return 'wp_atora_quizzes';
				}
				if ( str_contains( $sql, 'atora\\_quiz\\_submissions' ) || str_contains( $sql, 'atora_quiz_submissions' ) ) {
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
				return array( 'id' => 12, 'course_id' => $this->lesson_course_id, 'status' => 'published', 'wp_post_id' => absint( $this->lesson_wp_post_id ), 'title' => 'Lección', 'type' => 'text' );
			}
			if ( str_contains( $sql, 'FROM wp_atora_courses WHERE id =' ) ) {
				return array( 'id' => 29, 'wp_post_id' => absint( $this->course_wp_post_id ), 'status' => 'published', 'title' => 'Curso', 'excerpt' => '', 'thumbnail_url' => '', 'duration_hours' => 0, 'level' => '', 'language' => 'es' );
			}
			if ( str_contains( $sql, 'FROM wp_atora_quizzes' ) && str_contains( $sql, 'lesson_id' ) ) {
				return array(
					'id'             => 2,
					'lesson_id'      => 12,
					'course_id'      => $this->quiz_course_id,
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
			if ( is_string( $sql ) && str_contains( $sql, 'atora_enrollments' ) ) {
				return array(
					array(
						'id'            => 1,
						'user_id'       => 10,
						'course_id'     => 29,
						'wp_course_id'  => absint( $this->course_wp_post_id ),
						'status'        => 'active',
						'expires_at'    => '',
						'last_activity' => '2026-01-01 00:00:00',
					),
				);
			}
			return array();
		}

		public function insert( $table, $data, $format = null ) {
			$this->insert_count++;
			return 1;
		}
	}

	final class MobileTableQuizIdentityValidationTest extends TestCase {
		protected function setUp(): void {
			parent::setUp();
			global $wpdb;
			IdentityMismatchWpdb::$advisory_locks = array();
			$wpdb = new IdentityMismatchWpdb( 'conn-a' );
			$GLOBALS['__atora_test_current_user_id'] = 10;
			$GLOBALS['__atora_test_wp_insert_post_fail'] = false;
			$GLOBALS['__atora_test_wp_insert_post_id'] = 55555;
			$GLOBALS['__atora_test_wp_insert_post_calls'] = 0;
			$GLOBALS['__atora_test_wp_insert_post_last'] = array();
			atora_test_set_drip_available( true );
			atora_test_reset_post_types();
			atora_test_set_post_type( 5001, 'lm_lesson' );
			atora_test_set_post_type( 428, 'lm_course' );
			atora_test_reset_transients();
			atora_test_reset_options();
			atora_test_reset_post_meta();
			atora_test_reset_clms_helper_stub();
			atora_test_set_post_meta( 5001, '_clms_lesson_course_id', 428 );
		}

		private function assert_denied_without_side_effects( string $expected_code ): void {
			global $wpdb;
			$get = \ATORA_Mobile_REST_Controller::quiz( new \WP_REST_Request( array( 'lesson_id' => 12 ) ) );
			$this->assertTrue( is_wp_error( $get ) );
			$this->assertSame( $expected_code, $get->get_error_code() );
			$this->assertSame( 0, (int) $wpdb->insert_count );
			$this->assertSame( 0, (int) ( $GLOBALS['__atora_test_wp_insert_post_calls'] ?? 0 ) );
			$this->assertSame( array(), (array) ( $GLOBALS['__atora_test_transients'] ?? array() ) );

			$post = \ATORA_Mobile_REST_Controller::submit_quiz( new \WP_REST_Request( array( 'lesson_id' => 12, 'answers' => array( '4' ), 'token' => 'any' ) ) );
			$this->assertTrue( is_wp_error( $post ) );
			$this->assertSame( $expected_code, $post->get_error_code() );
			$this->assertSame( 0, (int) $wpdb->insert_count );
			$this->assertSame( 0, (int) ( $GLOBALS['__atora_test_wp_insert_post_calls'] ?? 0 ) );
			$this->assertSame( array(), (array) ( $GLOBALS['__atora_test_transients'] ?? array() ) );
		}

		public function test_denies_when_course_wp_identity_is_missing_or_not_lm_course(): void {
			global $wpdb;
			$wpdb->course_wp_post_id = 0;
			$this->assert_denied_without_side_effects( 'atora_mobile_quiz_requires_wp_course_identity' );
		}

		public function test_denies_when_lesson_wp_course_link_does_not_match_course_wp_post(): void {
			atora_test_set_post_meta( 5001, '_clms_lesson_course_id', 9999 );
			$this->assert_denied_without_side_effects( 'atora_mobile_quiz_identity_mismatch' );
		}

		public function test_denies_when_table_quiz_course_id_differs_from_lesson_course_id(): void {
			global $wpdb;
			$wpdb->quiz_course_id = 999;
			$this->assert_denied_without_side_effects( 'atora_mobile_quiz_identity_mismatch' );
		}

		public function test_denies_when_relation_keys_are_contradictory_non_empty(): void {
			atora_test_reset_post_meta();
			atora_test_set_post_meta( 5001, '_clms_course_id', 428 );
			atora_test_set_post_meta( 5001, 'lm_course_id', 9999 );
			$this->assert_denied_without_side_effects( 'atora_mobile_quiz_identity_conflict' );
		}

		public function test_allows_when_only_legacy_key_is_present_and_matches_course(): void {
			atora_test_reset_post_meta();
			atora_test_set_post_meta( 5001, 'lm_course_id', 428 );

			$response = \ATORA_Mobile_REST_Controller::quiz( new \WP_REST_Request( array( 'lesson_id' => 12 ) ) );
			$this->assertFalse( is_wp_error( $response ) );
			$data = (array) $response->get_data();
			$token = (string) ( $data['quiz']['token'] ?? '' );
			$this->assertNotSame( '', $token );
		}
	}
}
