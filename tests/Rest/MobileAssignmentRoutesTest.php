<?php

declare( strict_types = 1 );

namespace {
	// discovery() también consulta ATORA_Build_Info si otro test ya cargó la clase.
	if ( ! function_exists( 'plugin_dir_path' ) ) {
		function plugin_dir_path( string $file ): string { return rtrim( dirname( $file ), '/' ) . '/'; }
	}
	if ( ! function_exists( 'home_url' ) ) {
		function home_url( string $path = '' ): string { return 'https://example.test/' . ltrim( $path, '/' ); }
	}
}

namespace ATORA\Tests\Rest {

	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/../../includes/mobile/class-mobile-token-service.php';
	require_once __DIR__ . '/../../includes/mobile/class-mobile-assignment-store.php';
	require_once __DIR__ . '/../../includes/mobile/class-mobile-assignment-service.php';
	require_once __DIR__ . '/../../includes/mobile/class-mobile-rest-controller.php';

	/** Lección 12 (post 5001) del curso 29; solo el usuario 10 está matriculado. */
	final class AssignmentRoutesWpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;

		public function prepare( string $sql, ...$args ): string {
			$i = 0;
			return preg_replace_callback( '/%[ds]/', function () use ( &$i, $args ) {
				return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
			}, $sql );
		}

		public function get_var( $sql ) {
			return null;
		}

		public function get_row( $sql, $output = OBJECT ) {
			if ( is_string( $sql ) && str_contains( $sql, 'FROM wp_atora_lessons WHERE id =' ) ) {
				return array( 'id' => 12, 'course_id' => 29, 'status' => 'published', 'wp_post_id' => 5001, 'title' => 'Ensayo final', 'type' => 'text' );
			}
			if ( is_string( $sql ) && str_contains( $sql, 'FROM wp_atora_courses WHERE id =' ) ) {
				return array( 'id' => 29, 'wp_post_id' => 428, 'status' => 'published', 'title' => 'Curso', 'excerpt' => '', 'thumbnail_url' => '', 'duration_hours' => 0, 'level' => '', 'language' => 'es' );
			}
			return null;
		}

		public function get_results( $sql, $output = OBJECT ): array {
			if ( is_string( $sql ) && str_contains( $sql, 'atora_enrollments' ) && str_contains( $sql, 'user_id = 10' ) ) {
				return array( array( 'id' => 1, 'user_id' => 10, 'course_id' => 29, 'wp_course_id' => 428, 'status' => 'active', 'expires_at' => '', 'last_activity' => '2026-01-01 00:00:00' ) );
			}
			return array();
		}

		public function insert( $table, $data, $format = null ) {
			return false;
		}
	}

	final class MobileAssignmentRoutesTest extends TestCase {
		protected function setUp(): void {
			parent::setUp();
			global $wpdb;
			$wpdb = new AssignmentRoutesWpdb();
			$ref  = new \ReflectionClass( \ATORA_Mobile_REST_Controller::class );
			$prop = $ref->getProperty( 'enrollment_index_cache' );
			$prop->setAccessible( true );
			$prop->setValue( null, array() );
			atora_test_reset_posts();
			atora_test_set_post( 5001, array( 'post_type' => 'lm_lesson', 'post_status' => 'publish' ) );
			atora_test_set_post( 428, array( 'post_type' => 'lm_course', 'post_status' => 'publish' ) );
			atora_test_set_post_meta( 5001, 'lm_activity_type', 'tarea' );
			atora_test_set_post_meta( 5001, 'lm_due_date', '2026-10-20' );
		}

		private function as_user( int $user_id ): void {
			$GLOBALS['__atora_test_current_user_id'] = $user_id;
		}

		public function test_student_not_enrolled_gets_403_on_every_assignment_route(): void {
			$this->as_user( 11 );

			$routes = array(
				fn () => \ATORA_Mobile_REST_Controller::assignment( new \WP_REST_Request( array( 'lesson_id' => 12 ) ) ),
				fn () => \ATORA_Mobile_REST_Controller::create_assignment_submission( new \WP_REST_Request( array( 'lesson_id' => 12, 'client_event_id' => 'evt-nope-0001', 'body_text' => 'x' ) ) ),
				fn () => \ATORA_Mobile_REST_Controller::create_upload_session( new \WP_REST_Request( array( 'lesson_id' => 12, 'filename' => 'a.pdf', 'total_bytes' => 10 ) ) ),
			);
			foreach ( $routes as $call ) {
				$result = $call();
				$this->assertTrue( is_wp_error( $result ) );
				$this->assertSame( 403, $result->get_error_data()['status'] );
			}
		}

		public function test_lesson_without_assignment_is_not_found(): void {
			$this->as_user( 10 );
			atora_test_set_post_meta( 5001, 'lm_activity_type', 'lectura' );

			$result = \ATORA_Mobile_REST_Controller::assignment( new \WP_REST_Request( array( 'lesson_id' => 12 ) ) );

			$this->assertSame( 'atora_mobile_assignment_not_found', $result->get_error_code() );
			$this->assertSame( 404, $result->get_error_data()['status'] );
		}

		public function test_enrolled_student_reads_the_assignment(): void {
			$this->as_user( 10 );

			$response = \ATORA_Mobile_REST_Controller::assignment( new \WP_REST_Request( array( 'lesson_id' => 12 ) ) );
			$data     = $response->get_data();

			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( 12, $data['assignment']['lesson_id'] );
			$this->assertSame( 'Ensayo final', $data['assignment']['title'] );
			$this->assertSame( 0, $data['assignment']['attempts_used'] );
			$this->assertTrue( $data['assignment']['can_submit'] );
			$this->assertNotNull( $data['assignment']['due_at'] );
			$this->assertSame( array(), $data['submissions'] );
		}

		public function test_discovery_declares_the_assignments_capability(): void {
			$data = \ATORA_Mobile_REST_Controller::discovery()->get_data();
			$this->assertTrue( $data['capabilities']['assignments'] );
			$this->assertContains( 'assignments', $data['features'] );
		}
	}
}
