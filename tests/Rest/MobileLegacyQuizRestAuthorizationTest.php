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

	require_once __DIR__ . '/../../includes/class-quiz.php';
	require_once __DIR__ . '/../../modules/lms/class-lms-enrollment-service.php';

	final class QuizRestTestWpdb {
		public string $prefix = 'wp_';

		public function prepare( string $sql, ...$args ): string {
			$i = 0;
			return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
				return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
			}, $sql );
		}

		public function get_row( $sql, $output = OBJECT ) {
			if ( ! is_string( $sql ) ) {
				return null;
			}
			if ( ! str_contains( $sql, 'FROM wp_atora_enrollments' ) || ! str_contains( $sql, 'wp_course_id' ) ) {
				return null;
			}
			if ( ! preg_match( '/user_id\s*=\s*(\d+)/', $sql, $m_user ) ) {
				return null;
			}
			if ( ! preg_match( '/wp_course_id\s*=\s*(\d+)/', $sql, $m_course ) ) {
				return null;
			}
			$user_id      = (int) $m_user[1];
			$wp_course_id = (int) $m_course[1];

			$map = (array) ( $GLOBALS['__atora_quiz_rest_enrollments'] ?? array() );
			$expires_at = (string) ( $map[ $user_id ][ $wp_course_id ] ?? '' );
			if ( '' === $expires_at ) {
				return null;
			}
			return array( 'expires_at' => $expires_at );
		}
	}

	final class MobileLegacyQuizRestAuthorizationTest extends TestCase {
		protected function setUp(): void {
			parent::setUp();
			global $wpdb;
			$wpdb = new QuizRestTestWpdb();

			$GLOBALS['__atora_quiz_rest_enrollments'] = array();
			$GLOBALS['__atora_test_post_meta']        = array();
		}

		private function seed_lesson_meta( int $lesson_id, int $wp_course_id ): void {
			update_post_meta( $lesson_id, '_clms_lesson_course_id', $wp_course_id );
			update_post_meta( $lesson_id, '_clms_quiz_enabled', '1' );
			update_post_meta( $lesson_id, '_clms_quiz_questions', array(
				array(
					'id'       => 1,
					'question' => '2+2=?',
					'type'     => 'single',
					'options'  => array( '3', '4' ),
					'answer'   => '4',
					'weight'   => 1,
				),
			) );
		}

		private function engine(): \CLMS_Quiz {
			$ref = new \ReflectionClass( \CLMS_Quiz::class );
			/** @var \CLMS_Quiz */
			return $ref->newInstanceWithoutConstructor();
		}

		public function test_get_quiz_rest_allows_when_table_enrolled_and_not_expired(): void {
			$lesson_id    = 9001;
			$user_id      = 10;
			$wp_course_id = 1234;

			$this->seed_lesson_meta( $lesson_id, $wp_course_id );
			$GLOBALS['__atora_quiz_rest_enrollments'][ $user_id ][ $wp_course_id ] = '2999-01-01 00:00:00';

			$engine = $this->engine();
			$result = $engine->get_quiz_rest( $user_id, $lesson_id );
			$this->assertFalse( is_wp_error( $result ) );
			$this->assertSame( $lesson_id, (int) ( $result['lesson_id'] ?? 0 ) );
			$this->assertNotSame( '', (string) ( $result['token'] ?? '' ) );
		}

		public function test_get_quiz_rest_denies_when_table_enrollment_is_expired(): void {
			$lesson_id    = 9002;
			$user_id      = 10;
			$wp_course_id = 1234;

			$this->seed_lesson_meta( $lesson_id, $wp_course_id );
			$GLOBALS['__atora_quiz_rest_enrollments'][ $user_id ][ $wp_course_id ] = '2000-01-01 00:00:00';

			$engine = $this->engine();
			$result = $engine->get_quiz_rest( $user_id, $lesson_id );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'clms_quiz_forbidden', $result->get_error_code() );
		}

		public function test_get_quiz_rest_denies_when_enrolled_in_other_course(): void {
			$lesson_id = 9003;
			$user_id   = 10;

			$this->seed_lesson_meta( $lesson_id, 2222 );
			$GLOBALS['__atora_quiz_rest_enrollments'][ $user_id ][ 1234 ] = '2999-01-01 00:00:00';

			$engine = $this->engine();
			$result = $engine->get_quiz_rest( $user_id, $lesson_id );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'clms_quiz_forbidden', $result->get_error_code() );
		}

		public function test_grade_mobile_quiz_rest_denies_when_not_enrolled(): void {
			$lesson_id = 9004;
			$user_id   = 10;
			$this->seed_lesson_meta( $lesson_id, 1234 );

			$engine = $this->engine();
			$result = $engine->grade_mobile_quiz_rest( $user_id, $lesson_id, array(), 'tok' );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'clms_quiz_forbidden', $result->get_error_code() );
		}

		public function test_grade_mobile_quiz_rest_passes_access_check_when_enrolled_but_invalid_token(): void {
			$lesson_id    = 9005;
			$user_id      = 10;
			$wp_course_id = 1234;

			$this->seed_lesson_meta( $lesson_id, $wp_course_id );
			$GLOBALS['__atora_quiz_rest_enrollments'][ $user_id ][ $wp_course_id ] = '2999-01-01 00:00:00';

			$engine = $this->engine();
			$result = $engine->grade_mobile_quiz_rest( $user_id, $lesson_id, array(), 'invalid-token' );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'clms_quiz_token_expired', $result->get_error_code() );
		}
	}
}
