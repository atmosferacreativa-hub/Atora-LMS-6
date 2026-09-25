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

	final class DripLockedWpdb {
		public string $prefix = 'wp_';
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
			return null;
		}

		public function get_results( $sql, $output = OBJECT ): array {
			if ( is_string( $sql ) && str_contains( $sql, 'FROM wp_atora_enrollments e' ) ) {
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
			return 1;
		}
	}

	final class MobileDripQuizRegressionTest extends TestCase {
		protected function setUp(): void {
			parent::setUp();
			global $wpdb;
			$wpdb = new DripLockedWpdb();
			$GLOBALS['__atora_test_current_user_id'] = 10;
			atora_test_set_drip_available( false );
			atora_test_reset_post_types();
			atora_test_set_post_type( 5001, 'lm_lesson' );
			atora_test_reset_transients();
			atora_test_reset_options();
		}

		public function test_quiz_endpoint_denies_when_lesson_is_drip_locked(): void {
			$req = new \WP_REST_Request( array( 'lesson_id' => 12 ) );
			$result = \ATORA_Mobile_REST_Controller::quiz( $req );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'clms_lesson_locked', $result->get_error_code() );
		}

		public function test_submit_quiz_denies_and_does_not_persist_when_drip_locked(): void {
			global $wpdb;
			$req = new \WP_REST_Request( array( 'lesson_id' => 12, 'answers' => array( '4' ), 'token' => 'anything' ) );
			$result = \ATORA_Mobile_REST_Controller::submit_quiz( $req );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'clms_lesson_locked', $result->get_error_code() );
			$this->assertSame( 0, (int) $wpdb->insert_count );
		}
	}
}
