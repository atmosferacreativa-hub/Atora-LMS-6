<?php

declare( strict_types = 1 );

namespace {
	require_once __DIR__ . '/Fixtures/table-quiz-wp-insert-post.php';

	if ( ! class_exists( 'CLMS_Assessment_Engine' ) ) {
		final class CLMS_Assessment_Engine {
			public function build_course_gradebook( int $student_id, int $course_id ): array {
				$entries = array();
				foreach ( (array) ( $GLOBALS['__atora_test_posts'] ?? array() ) as $post_id => $post ) {
					if ( 'clms_submission' !== (string) ( $post->post_type ?? '' ) ) {
						continue;
					}
					$post_id = absint( $post_id );
					if ( ! $post_id ) {
						continue;
					}
					$meta_user   = absint( get_post_meta( $post_id, '_clms_submission_user_id', true ) );
					$meta_course = absint( get_post_meta( $post_id, '_clms_submission_course_id', true ) );
					$meta_lesson = absint( get_post_meta( $post_id, '_clms_submission_lesson_id', true ) );
					if ( $student_id !== $meta_user || $course_id !== $meta_course || ! $meta_lesson ) {
						continue;
					}
					$entries[] = array(
						'lesson_id'         => $meta_lesson,
						'submission_id'     => $post_id,
						'submission_status' => (string) get_post_meta( $post_id, '_clms_submission_status', true ),
						'assignment_grade'  => get_post_meta( $post_id, '_clms_submission_grade', true ),
						'grade_source'      => 'clms_submission',
					);
				}
				return array( 'entries' => $entries );
			}

			public function get_submission_grade_record( int $submission_id ): array {
				unset( $submission_id );
				return array();
			}

			public function get_submission_audit_log( int $submission_id ): array {
				unset( $submission_id );
				return array();
			}

			public function get_lesson_evaluation_settings( int $lesson_id ): array {
				unset( $lesson_id );
				return array();
			}
		}
	}
}

namespace ATORA\Tests\Rest {

	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/../stubs/class-clms-helper-stub.php';
	require_once __DIR__ . '/../../includes/mobile/class-mobile-rest-controller.php';
	require_once __DIR__ . '/../../includes/gradebook/class-gradebook-normalizer.php';
	require_once __DIR__ . '/../../includes/gradebook/class-gradebook-schema-service.php';
	require_once __DIR__ . '/../../includes/gradebook/class-gradebook-calculation-service.php';
	require_once __DIR__ . '/../../includes/gradebook/class-gradebook-grid-service.php';
	require_once __DIR__ . '/../../includes/gradebook/class-gradebook-service.php';

	final class LinkageWpdb {
		public static array $advisory_locks = array();
		public string $connection_id;
		public string $prefix = 'wp_';
		public string $posts = 'wp_posts';
		public string $postmeta = 'wp_postmeta';
		public string $usermeta = 'wp_usermeta';
		public int $insert_id = 900;
		public int $insert_count = 0;
		public array $inserts = array();
		public int $lesson_wp_post_id = 5001;
		public int $course_wp_post_id = 428;

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
				return array(
					'id'         => 12,
					'course_id'  => 29,
					'status'     => 'published',
					'wp_post_id' => absint( $this->lesson_wp_post_id ),
					'title'      => 'Lección',
					'type'       => 'text',
				);
			}
			if ( str_contains( $sql, 'FROM wp_atora_courses WHERE id =' ) ) {
				return array(
					'id'            => 29,
					'wp_post_id'    => absint( $this->course_wp_post_id ),
					'status'        => 'published',
					'title'         => 'Curso',
					'excerpt'       => '',
					'thumbnail_url' => '',
					'duration_hours'=> 0,
					'level'         => '',
					'language'      => 'es',
				);
			}
			if ( str_contains( $sql, 'FROM wp_atora_quizzes' ) && str_contains( $sql, 'lesson_id' ) ) {
				return array(
					'id'            => 2,
					'lesson_id'     => 12,
					'course_id'     => 29,
					'questions_json'=> json_encode( array( array( 'id' => 1, 'type' => 'single', 'question' => '2+2', 'options' => array( '4' ), 'answer' => '4', 'weight' => 1 ) ) ),
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
			$this->inserts[] = array( 'table' => $table, 'data' => $data );
			$this->insert_id++;
			return 1;
		}
	}

	final class MobileTableQuizWpIdentityLinkageTest extends TestCase {
		protected function setUp(): void {
			parent::setUp();
			global $wpdb;
			LinkageWpdb::$advisory_locks = array();
			$wpdb = new LinkageWpdb( 'conn-a' );
			$GLOBALS['__atora_test_current_user_id'] = 10;
			$GLOBALS['__atora_test_wp_insert_post_fail'] = false;
			$GLOBALS['__atora_test_wp_insert_post_id'] = 70001;
			$GLOBALS['__atora_test_wp_insert_post_calls'] = 0;
			$GLOBALS['__atora_test_wp_insert_post_last'] = array();
			atora_test_reset_transients();
			atora_test_reset_options();
			atora_test_reset_post_meta();
			atora_test_reset_posts();
			atora_test_reset_post_types();
			atora_test_reset_users();
			atora_test_reset_clms_helper_stub();
			atora_test_set_drip_available( true );

			atora_test_set_user( 10, array( 'user_email' => 'student@example.test', 'display_name' => 'Estudiante' ) );
			atora_test_set_user( 99, array( 'user_email' => 'admin@example.test', 'display_name' => 'Docente' ) );

			atora_test_set_post( 428, array( 'post_type' => 'lm_course', 'post_title' => 'Curso WP' ) );
			atora_test_set_post( 5001, array( 'post_type' => 'lm_lesson', 'post_title' => 'Lección WP' ) );
			atora_test_set_post_meta( 5001, '_clms_course_id', 428 );
			atora_test_set_post_meta( 5001, '_clms_gradebook_points', 100 );
			atora_test_set_course_lessons( 428, array( 5001 ) );
			atora_test_set_enrolled_students( 428, array( 10 ) );
		}

		public function test_denies_without_wp_identity_and_does_not_write_anything(): void {
			global $wpdb;
			$wpdb->lesson_wp_post_id = 0;

			$req = new \WP_REST_Request( array( 'lesson_id' => 12 ) );
			$result = \ATORA_Mobile_REST_Controller::quiz( $req );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'atora_mobile_quiz_requires_wp_identity', $result->get_error_code() );
			$this->assertSame( 0, (int) $wpdb->insert_count );
			$this->assertSame( 0, (int) ( $GLOBALS['__atora_test_wp_insert_post_calls'] ?? 0 ) );
			$this->assertFalse( (bool) get_transient( 'atora_table_quiz_token_10_12' ) );
		}

		public function test_drip_locked_denies_get_and_post_without_writes_or_tokens(): void {
			global $wpdb;
			$wpdb->lesson_wp_post_id = 5001;
			atora_test_set_drip_available( false );

			$get = \ATORA_Mobile_REST_Controller::quiz( new \WP_REST_Request( array( 'lesson_id' => 12 ) ) );
			$this->assertTrue( is_wp_error( $get ) );
			$this->assertSame( 'clms_lesson_locked', $get->get_error_code() );
			$this->assertSame( 0, (int) $wpdb->insert_count );
			$this->assertSame( 0, (int) ( $GLOBALS['__atora_test_wp_insert_post_calls'] ?? 0 ) );
			$this->assertFalse( (bool) get_transient( 'atora_table_quiz_token_10_12' ) );

			$post = \ATORA_Mobile_REST_Controller::submit_quiz( new \WP_REST_Request( array( 'lesson_id' => 12, 'answers' => array( '4' ), 'token' => 'any' ) ) );
			$this->assertTrue( is_wp_error( $post ) );
			$this->assertSame( 'clms_lesson_locked', $post->get_error_code() );
			$this->assertSame( 0, (int) $wpdb->insert_count );
			$this->assertSame( 0, (int) ( $GLOBALS['__atora_test_wp_insert_post_calls'] ?? 0 ) );
		}

		public function test_table_quiz_persists_linked_submission_and_appears_in_gradebook_cell(): void {
			global $wpdb;
			$wpdb->lesson_wp_post_id = 5001;
			atora_test_set_drip_available( true );

			$resp = \ATORA_Mobile_REST_Controller::quiz( new \WP_REST_Request( array( 'lesson_id' => 12 ) ) );
			$this->assertFalse( is_wp_error( $resp ) );
			$data = (array) $resp->get_data();
			$token = (string) ( $data['quiz']['token'] ?? '' );
			$this->assertNotSame( '', $token );
			$this->assertNotFalse( get_transient( 'atora_table_quiz_token_10_12' ) );

			$result = \ATORA_Mobile_REST_Controller::submit_quiz( new \WP_REST_Request( array( 'lesson_id' => 12, 'answers' => array( '4' ), 'token' => $token ) ) );
			$this->assertFalse( is_wp_error( $result ) );
			$this->assertSame( 1, (int) $wpdb->insert_count );
			$this->assertSame( 1, (int) ( $GLOBALS['__atora_test_wp_insert_post_calls'] ?? 0 ) );
			$this->assertFalse( (bool) get_transient( 'atora_table_quiz_token_10_12' ) );

			$submission_id = absint( $GLOBALS['__atora_test_wp_insert_post_id'] ?? 0 );
			$this->assertGreaterThan( 0, $submission_id );
			$this->assertSame( 'clms_submission', (string) get_post_type( $submission_id ) );
			$this->assertSame( 10, absint( get_post_field( 'post_author', $submission_id ) ) );
			$this->assertSame( 10, absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) ) );
			$this->assertSame( 5001, absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) ) );
			$this->assertSame( 428, absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) ) );
			$this->assertSame( 'graded', (string) get_post_meta( $submission_id, '_clms_submission_status', true ) );
			$this->assertSame( 100, absint( get_post_meta( $submission_id, '_clms_submission_grade', true ) ) );

			$this->assertCount( 1, $wpdb->inserts );
			$insert = $wpdb->inserts[0];
			$this->assertSame( 'wp_atora_quiz_submissions', (string) $insert['table'] );
			$this->assertSame( $submission_id, absint( $insert['data']['wp_post_id'] ?? 0 ) );
			$this->assertSame( 10, absint( $insert['data']['user_id'] ?? 0 ) );
			$this->assertSame( 2, absint( $insert['data']['quiz_id'] ?? 0 ) );
			$this->assertSame( 12, absint( $insert['data']['lesson_id'] ?? 0 ) );
			$this->assertSame( 29, absint( $insert['data']['course_id'] ?? 0 ) );
			$this->assertSame( 5001, absint( $insert['data']['wp_lesson_id'] ?? 0 ) );
			$this->assertSame( 428, absint( $insert['data']['wp_course_id'] ?? 0 ) );
			$this->assertSame( 'graded', (string) ( $insert['data']['status'] ?? '' ) );
			$this->assertSame( 100, (int) round( (float) ( $insert['data']['grade'] ?? 0 ) ) );

			$grid = ( new \CLMS_Gradebook_Service() )->build_grid( 428 );
			$this->assertCount( 1, (array) ( $grid['rows'] ?? array() ) );
			$row = $grid['rows'][0];
			$this->assertSame( 10, absint( $row['student_id'] ?? 0 ) );
			$this->assertSame( 100, absint( $row['cells'][5001]['grade'] ?? 0 ) );
			$this->assertSame( $submission_id, absint( $row['cells'][5001]['submission_id'] ?? 0 ) );
			$this->assertSame( 'graded', (string) ( $row['cells'][5001]['status'] ?? '' ) );
		}
	}
}
