<?php

declare( strict_types = 1 );

namespace {
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

	final class AtomicityWpdb {
		public static array $advisory_locks = array();
		public string $connection_id;
		public string $prefix = 'wp_';
		public string $posts = 'wp_posts';
		public string $postmeta = 'wp_postmeta';
		public string $usermeta = 'wp_usermeta';
		public int $insert_id = 200;

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
				return array( 'id' => 29, 'wp_post_id' => 428, 'status' => 'published', 'title' => 'Curso' );
			}
			if ( str_contains( $sql, 'FROM wp_atora_quizzes' ) && str_contains( $sql, 'lesson_id' ) ) {
				return array(
					'id'            => 2,
					'lesson_id'     => 12,
					'course_id'     => 29,
					'questions_json'=> json_encode( array( array( 'id' => 1, 'type' => 'single', 'options' => array( '4' ), 'answer' => '4', 'weight' => 1 ) ) ),
					'settings_json' => json_encode( array( 'attempts' => 2, 'time_limit_seconds' => 0 ) ),
				);
			}
			if ( str_contains( $sql, 'COUNT(*) AS attempts_used' ) ) {
				return array( 'attempts_used' => 0, 'best_score' => 0 );
			}
			return null;
		}

		public function get_results( $sql, $output = OBJECT ): array {
			if ( is_string( $sql ) && str_contains( $sql, 'atora_enrollments' ) ) {
				return array( array( 'id' => 1, 'user_id' => 10, 'course_id' => 29, 'wp_course_id' => 428, 'status' => 'active', 'expires_at' => '', 'last_activity' => '' ) );
			}
			return array();
		}

		public function insert( $table, $data, $format = null ) {
			$this->insert_id++;
			return 1;
		}
	}

	final class MobileTableQuizAtomicityTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		AtomicityWpdb::$advisory_locks = array();
		$wpdb = new AtomicityWpdb( 'conn-a' );
		$GLOBALS['__atora_test_current_user_id'] = 10;
		$ref = new \ReflectionClass( \ATORA_Mobile_REST_Controller::class );
		$p = $ref->getProperty( 'enrollment_index_cache' );
		$p->setAccessible( true );
		$p->setValue( null, array() );
		atora_test_set_drip_available( true );
		atora_test_reset_post_types();
		atora_test_set_post_type( 5001, 'lm_lesson' );
		atora_test_set_post_type( 428, 'lm_course' );
		atora_test_reset_transients();
		atora_test_reset_options();
		atora_test_reset_post_meta();
		atora_test_set_post_meta( 5001, '_clms_lesson_course_id', 428 );
	}

	public function test_submit_quiz_denies_when_lock_is_held(): void {
		global $wpdb;
		$ref = new \ReflectionClass( \ATORA_Mobile_REST_Controller::class );
		$m   = $ref->getMethod( 'acquire_table_quiz_lock' );
		$m->setAccessible( true );
		$m->invokeArgs( null, array( 10, 12 ) );

		$wpdb = new AtomicityWpdb( 'conn-b' );

		$req = new \WP_REST_Request( array( 'lesson_id' => 12, 'answers' => array( '4' ), 'token' => 'anything' ) );
		$result = \ATORA_Mobile_REST_Controller::submit_quiz( $req );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'atora_mobile_quiz_submission_locked', $result->get_error_code() );
	}

	public function test_lock_release_does_not_delete_other_owners_lock(): void {
		global $wpdb;
		$ref = new \ReflectionClass( \ATORA_Mobile_REST_Controller::class );
		$acquire = $ref->getMethod( 'acquire_table_quiz_lock' );
		$acquire->setAccessible( true );
		$lock_key = (string) $acquire->invokeArgs( null, array( 10, 12 ) );
		$this->assertNotSame( '', $lock_key );

		$wpdb = new AtomicityWpdb( 'conn-b' );

		$m = $ref->getMethod( 'release_table_quiz_lock' );
		$m->setAccessible( true );
		$m->invokeArgs( null, array( $lock_key ) );

		$req = new \WP_REST_Request( array( 'lesson_id' => 12, 'answers' => array( '4' ), 'token' => 'anything' ) );
		$result = \ATORA_Mobile_REST_Controller::submit_quiz( $req );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'atora_mobile_quiz_submission_locked', $result->get_error_code() );
	}
	}
}
