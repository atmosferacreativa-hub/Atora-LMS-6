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

	require_once __DIR__ . '/../../includes/mobile/class-mobile-rest-controller.php';

	final class TimeLimitWpdb {
		public static array $advisory_locks = array();
		public string $connection_id;
		public string $prefix = 'wp_';
		public string $posts = 'wp_posts';
		public string $postmeta = 'wp_postmeta';
		public string $usermeta = 'wp_usermeta';
		public int $insert_id = 99;
		public int $insert_count = 0;

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
				return array( 'id' => 12, 'course_id' => 29, 'status' => 'published', 'wp_post_id' => 5001, 'title' => 'Lección', 'type' => 'text' );
			}
			if ( str_contains( $sql, 'FROM wp_atora_courses WHERE id =' ) ) {
				return array( 'id' => 29, 'wp_post_id' => 428, 'status' => 'published', 'title' => 'Curso', 'excerpt' => '', 'thumbnail_url' => '', 'duration_hours' => 0, 'level' => '', 'language' => 'es' );
			}
			if ( str_contains( $sql, 'FROM wp_atora_quizzes' ) && str_contains( $sql, 'lesson_id' ) ) {
				return array(
					'id'            => 2,
					'lesson_id'     => 12,
					'course_id'     => 29,
					'questions_json'=> json_encode( array( array( 'id' => 1, 'type' => 'single', 'question' => '2+2', 'options' => array( '4' ), 'answer' => '4', 'weight' => 1 ) ) ),
					'settings_json' => json_encode( array( 'attempts' => 2, 'time_limit_seconds' => (int) ( $GLOBALS['__atora_time_limit_seconds'] ?? 2 ) ) ),
				);
			}
			if ( str_contains( $sql, 'COUNT(*) AS attempts_used' ) ) {
				return array(
					'attempts_used' => (int) ( $GLOBALS['__atora_time_limit_attempts_used'] ?? 0 ),
					'best_score'    => 0,
				);
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
						'wp_course_id'  => 428,
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
			$this->insert_id++;
			return 1;
		}
	}

	final class MobileTableQuizTimeLimitTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		TimeLimitWpdb::$advisory_locks = array();
		$wpdb = new TimeLimitWpdb( 'conn-a' );
		$GLOBALS['__atora_test_current_user_id'] = 10;
		$GLOBALS['__atora_test_wp_insert_post_fail'] = false;
		$GLOBALS['__atora_test_wp_insert_post_id'] = 55555;
		$GLOBALS['__atora_test_wp_insert_post_calls'] = 0;
		$GLOBALS['__atora_test_wp_insert_post_last'] = array();
		$ref = new \ReflectionClass( \ATORA_Mobile_REST_Controller::class );
		$p = $ref->getProperty( 'enrollment_index_cache' );
		$p->setAccessible( true );
		$p->setValue( null, array() );
		atora_test_set_drip_available( true );
		atora_test_reset_post_types();
		atora_test_set_post_type( 5001, 'lm_lesson' );
		atora_test_reset_transients();
		atora_test_reset_options();
			$GLOBALS['__atora_time_limit_attempts_used'] = 0;
			$GLOBALS['__atora_time_limit_seconds'] = 2;
		}

		public function test_submit_quiz_denies_when_time_limit_expired(): void {
			$req_quiz = new \WP_REST_Request( array( 'lesson_id' => 12 ) );
			$response = \ATORA_Mobile_REST_Controller::quiz( $req_quiz );
			$this->assertFalse( is_wp_error( $response ) );
			$quiz = (array) $response->get_data();
			$token = (string) ( ( $quiz['quiz']['token'] ?? '' ) ?: '' );
			$this->assertNotSame( '', $token );

			$key = 'atora_table_quiz_token_10_12';
			$GLOBALS['__atora_test_transients'][ $key ] = array( 'token' => $token, 'issued_at' => time() - 999 );

			global $wpdb;
			$req = new \WP_REST_Request( array( 'lesson_id' => 12, 'answers' => array( '4' ), 'token' => $token ) );
			$result = \ATORA_Mobile_REST_Controller::submit_quiz( $req );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'atora_mobile_quiz_time_expired', $result->get_error_code() );
			$this->assertSame( 0, (int) $wpdb->insert_count );
			$this->assertFalse( isset( $GLOBALS['__atora_test_transients'][ $key ] ) );
		}

		public function test_quiz_get_does_not_reset_time_window(): void {
			$req_quiz = new \WP_REST_Request( array( 'lesson_id' => 12 ) );
			$response = \ATORA_Mobile_REST_Controller::quiz( $req_quiz );
			$this->assertFalse( is_wp_error( $response ) );
			$quiz = (array) $response->get_data();
			$token = (string) ( ( $quiz['quiz']['token'] ?? '' ) ?: '' );
			$this->assertNotSame( '', $token );

			$key = 'atora_table_quiz_token_10_12';
			$GLOBALS['__atora_test_transients'][ $key ] = array( 'token' => $token, 'issued_at' => time() - 1 );

			$response2 = \ATORA_Mobile_REST_Controller::quiz( $req_quiz );
			$this->assertFalse( is_wp_error( $response2 ) );
			$quiz2 = (array) $response2->get_data();
			$this->assertSame( $token, (string) ( $quiz2['quiz']['token'] ?? '' ) );
			$this->assertLessThanOrEqual( 1, (int) ( $quiz2['quiz']['remaining_seconds'] ?? 999 ) );
		}

		public function test_quiz_get_does_not_renew_after_expiry(): void {
			$req_quiz = new \WP_REST_Request( array( 'lesson_id' => 12 ) );
			$response = \ATORA_Mobile_REST_Controller::quiz( $req_quiz );
			$this->assertFalse( is_wp_error( $response ) );
			$quiz = (array) $response->get_data();
			$token = (string) ( ( $quiz['quiz']['token'] ?? '' ) ?: '' );
			$this->assertNotSame( '', $token );

			$key = 'atora_table_quiz_token_10_12';
			$GLOBALS['__atora_test_transients'][ $key ] = array( 'token' => $token, 'issued_at' => time() - 999 );

			$response2 = \ATORA_Mobile_REST_Controller::quiz( $req_quiz );
			$this->assertFalse( is_wp_error( $response2 ) );
			$quiz2 = (array) $response2->get_data();
			$this->assertSame( $token, (string) ( $quiz2['quiz']['token'] ?? '' ) );
			$this->assertSame( 0, (int) ( $quiz2['quiz']['remaining_seconds'] ?? -1 ) );
		}

		public function test_token_outlives_a_quiz_limit_longer_than_one_hour(): void {
			$GLOBALS['__atora_time_limit_seconds'] = 7200;
			$quiz = \ATORA_Mobile_REST_Controller::quiz( new \WP_REST_Request( array( 'lesson_id' => 12 ) ) );
			$this->assertFalse( is_wp_error( $quiz ) );
			$key = 'atora_table_quiz_token_10_12';
			$this->assertGreaterThan( 7200, (int) ( $GLOBALS['__atora_test_transient_expirations'][ $key ] ?? 0 ) );
		}

		public function test_submit_quiz_denies_at_exact_time_boundary_and_allows_new_get(): void {
			$req_quiz = new \WP_REST_Request( array( 'lesson_id' => 12 ) );
			$response = \ATORA_Mobile_REST_Controller::quiz( $req_quiz );
			$this->assertFalse( is_wp_error( $response ) );
			$quiz = (array) $response->get_data();
			$token = (string) ( $quiz['quiz']['token'] ?? '' );
			$this->assertNotSame( '', $token );

			$key = 'atora_table_quiz_token_10_12';
			$GLOBALS['__atora_test_transients'][ $key ] = array( 'token' => $token, 'issued_at' => time() - 2 );
			$expired = \ATORA_Mobile_REST_Controller::quiz( $req_quiz );
			$this->assertSame( 0, (int) ( $expired->get_data()['quiz']['remaining_seconds'] ?? -1 ) );

			$result = \ATORA_Mobile_REST_Controller::submit_quiz( new \WP_REST_Request( array( 'lesson_id' => 12, 'answers' => array( '4' ), 'token' => $token ) ) );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'atora_mobile_quiz_time_expired', $result->get_error_code() );
			$this->assertFalse( isset( $GLOBALS['__atora_test_transients'][ $key ] ) );

			$retry = \ATORA_Mobile_REST_Controller::quiz( $req_quiz );
			$this->assertFalse( is_wp_error( $retry ) );
			$this->assertNotSame( '', (string) ( $retry->get_data()['quiz']['token'] ?? '' ) );
		}

		public function test_submit_quiz_allows_within_time_limit(): void {
			$req_quiz = new \WP_REST_Request( array( 'lesson_id' => 12 ) );
			$response = \ATORA_Mobile_REST_Controller::quiz( $req_quiz );
			$this->assertFalse( is_wp_error( $response ) );
			$quiz = (array) $response->get_data();
			$token = (string) ( ( $quiz['quiz']['token'] ?? '' ) ?: '' );
			$this->assertNotSame( '', $token );

			$key = 'atora_table_quiz_token_10_12';
			$GLOBALS['__atora_test_transients'][ $key ] = array( 'token' => $token, 'issued_at' => time() - 1 );

			global $wpdb;
			$req = new \WP_REST_Request( array( 'lesson_id' => 12, 'answers' => array( '4' ), 'token' => $token ) );
			$result = \ATORA_Mobile_REST_Controller::submit_quiz( $req );
			$this->assertFalse( is_wp_error( $result ) );
			$this->assertInstanceOf( \WP_REST_Response::class, $result );
			$this->assertGreaterThan( 0, (int) $wpdb->insert_count );
		}

		public function test_submit_quiz_reports_attempts_exceeded_even_without_token(): void {
			$GLOBALS['__atora_time_limit_attempts_used'] = 2;

			$req = new \WP_REST_Request( array( 'lesson_id' => 12, 'answers' => array( '4' ), 'token' => '' ) );
			$result = \ATORA_Mobile_REST_Controller::submit_quiz( $req );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'atora_mobile_quiz_attempts_exceeded', $result->get_error_code() );
		}
	}
}
