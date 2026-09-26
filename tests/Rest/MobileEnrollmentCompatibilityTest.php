<?php

declare( strict_types = 1 );

/**
 * Pruebas de compatibilidad: matrículas en tablas + legacy (router/helper canónico).
 *
 * Este test se ejecuta con el bootstrap global de PHPUnit que ya carga
 * `modules/lms/class-lms-enrollment-service.php` y `modules/lms/class-lms-course-service.php`.
 * Por eso NO se redefinen esas clases acá; en su lugar se stubea `$wpdb` para
 * devolver datasets determinísticos.
 */

namespace {
	if ( ! class_exists( 'CLMS_Helper' ) ) {
		class CLMS_Helper {
			public static array $enrolled  = array(); // [user_id] => wp_course_ids
			public static array $completed = array(); // [user_id] => wp_course_ids

			public static function get_user_enrolled_courses( $user_id ) {
				$user_id = (int) $user_id;
				return (array) ( self::$enrolled[ $user_id ] ?? array() );
			}

			public static function user_is_enrolled_in_course( $user_id, $course_id ): bool {
				$user_id   = (int) $user_id;
				$course_id = (int) $course_id;
				return in_array( $course_id, (array) ( self::$enrolled[ $user_id ] ?? array() ), true );
			}

				public static function is_course_completed( $user_id, $course_id ): bool {
					$user_id   = (int) $user_id;
					$course_id = (int) $course_id;
					return in_array( $course_id, (array) ( self::$completed[ $user_id ] ?? array() ), true );
				}

				public static function get_post_meta_first( $post_id, $key, $default = '' ) {
					$post_id = (int) $post_id;
					if ( is_array( $key ) ) {
						foreach ( $key as $candidate ) {
							$candidate = (string) $candidate;
							$value     = get_post_meta( $post_id, $candidate, true );
							if ( '' !== (string) $value ) {
								return $value;
							}
						}
						return $default;
					}
					$key   = (string) $key;
					$value = get_post_meta( $post_id, $key, true );
					return '' !== (string) $value ? $value : $default;
				}
			}
		}
	}

namespace ATORA\Tests\Rest {

	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/../../includes/mobile/class-mobile-rest-controller.php';

	final class MobileTestWpdb {
		public string $prefix = 'wp_';

		public function prepare( string $sql, ...$args ): string {
			$i = 0;
			return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
				return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
			}, $sql );
		}

		public function esc_like( string $s ): string {
			return addcslashes( $s, '_%\\' );
		}

		public function get_row( $sql, $output = OBJECT ) {
			$db            = (array) ( $GLOBALS['__atora_mobile_test_db'] ?? array() );
			$courses       = (array) ( $db['courses'] ?? array() );
			$courses_by_wp = (array) ( $db['courses_by_wp'] ?? array() );
			$lessons       = (array) ( $db['lessons'] ?? array() );

			if ( is_string( $sql ) && str_contains( $sql, 'FROM wp_atora_courses WHERE id =' ) ) {
				if ( preg_match( '/WHERE id = (\\d+)/', $sql, $m ) ) {
					$id = (int) $m[1];
					return isset( $courses[ $id ] ) ? (array) $courses[ $id ] : null;
				}
			}

			if ( is_string( $sql ) && str_contains( $sql, 'FROM wp_atora_courses WHERE wp_post_id =' ) ) {
				if ( preg_match( '/WHERE wp_post_id = (\\d+)/', $sql, $m ) ) {
					$wp = (int) $m[1];
					return isset( $courses_by_wp[ $wp ] ) ? (array) $courses_by_wp[ $wp ] : null;
				}
			}

			if ( is_string( $sql ) && str_contains( $sql, 'FROM wp_atora_lessons WHERE id =' ) ) {
				if ( preg_match( '/WHERE id = (\\d+)/', $sql, $m ) ) {
					$id = (int) $m[1];
					return isset( $lessons[ $id ] ) ? (array) $lessons[ $id ] : null;
				}
			}

			return null;
		}

		public function get_results( $sql, $output = OBJECT ): array {
			$db          = (array) ( $GLOBALS['__atora_mobile_test_db'] ?? array() );
			$enrollments = (array) ( $db['enrollments'] ?? array() ); // [user_id][status] => rows

			if ( is_string( $sql ) && str_contains( $sql, 'FROM wp_atora_enrollments e' ) ) {
				if ( preg_match( '/WHERE e\\.user_id = (\\d+) AND e\\.status = ([a-z0-9_\\-]+)/i', $sql, $m ) ) {
					$user_id = (int) $m[1];
					$status  = strtolower( (string) $m[2] );
					return (array) ( $enrollments[ $user_id ][ $status ] ?? array() );
				}
			}

			return array();
		}

		public function get_var( $sql ) {
			$db       = (array) ( $GLOBALS['__atora_mobile_test_db'] ?? array() );
			$progress = (array) ( $db['progress'] ?? array() ); // [user_id][course_id] => completed_lessons

			if ( is_string( $sql ) && str_contains( $sql, 'FROM wp_atora_lessons' ) && str_contains( $sql, 'COUNT(*)' ) ) {
				if ( preg_match( '/WHERE course_id = (\\d+)/', $sql, $m ) ) {
					$course_id = (int) $m[1];
					return (int) ( $db['total_lessons'][ $course_id ] ?? 0 );
				}
			}

			if ( is_string( $sql ) && str_contains( $sql, 'FROM wp_atora_lesson_progress lp' ) && str_contains( $sql, 'COUNT(*)' ) ) {
				if ( preg_match( '/WHERE lp\\.user_id = (\\d+) AND lp\\.course_id = (\\d+)/', $sql, $m ) ) {
					$user_id   = (int) $m[1];
					$course_id = (int) $m[2];
					return (int) ( $progress[ $user_id ][ $course_id ]['completed_lessons'] ?? 0 );
				}
			}

			return null;
		}

		public function get_col( $sql ): array {
			$db = (array) ( $GLOBALS['__atora_mobile_test_db'] ?? array() );
			$completed = (array) ( $db['completed_lesson_ids'] ?? array() ); // [user_id][course_id] => [lesson_id...]

			if ( is_string( $sql ) && str_contains( $sql, 'FROM wp_atora_lesson_progress' ) && str_contains( $sql, 'lesson_id' ) ) {
				if ( preg_match( '/WHERE user_id = (\\d+) AND course_id = (\\d+)/', $sql, $m ) ) {
					$user_id   = (int) $m[1];
					$course_id = (int) $m[2];
					return array_map( 'absint', (array) ( $completed[ $user_id ][ $course_id ] ?? array() ) );
				}
			}

			return array();
		}
	}

	final class MobileEnrollmentCompatibilityTest extends TestCase {
		protected function setUp(): void {
			parent::setUp();

			$GLOBALS['__atora_test_current_user_id'] = 10;
			$GLOBALS['__atora_mobile_test_db']       = array(
				'enrollments'   => array(),
				'courses'       => array(),
				'courses_by_wp' => array(),
				'lessons'       => array(),
				'progress'      => array(),
				'total_lessons' => array(),
				'completed_lesson_ids' => array(),
			);

			global $wpdb;
			$wpdb = new MobileTestWpdb();

			\CLMS_Helper::$enrolled  = array();
			\CLMS_Helper::$completed = array();

			$ref  = new \ReflectionClass( \ATORA_Mobile_REST_Controller::class );
			$prop = $ref->getProperty( 'enrollment_index_cache' );
			$prop->setAccessible( true );
			$prop->setValue( null, array() );
		}

		public function test_courses_includes_tables_and_legacy_and_dedupes(): void {
			$GLOBALS['__atora_mobile_test_db']['enrollments'][10]['active'] = array(
				array( 'id' => 501, 'user_id' => 10, 'course_id' => 1, 'status' => 'active', 'last_activity' => '2026-01-01 00:00:00' ),
				array( 'id' => 502, 'user_id' => 10, 'course_id' => 2, 'status' => 'active', 'last_activity' => '2026-01-02 00:00:00' ),
			);
			$GLOBALS['__atora_mobile_test_db']['enrollments'][10]['completed'] = array(
				array( 'id' => 503, 'user_id' => 10, 'course_id' => 3, 'status' => 'completed', 'last_activity' => '2026-01-03 00:00:00' ),
			);

			\CLMS_Helper::$enrolled[10]  = array( 1001, 1002 );
			\CLMS_Helper::$completed[10] = array( 1002 );

			$GLOBALS['__atora_mobile_test_db']['courses_by_wp'][1001] = array( 'id' => 2, 'wp_post_id' => 1001 );
			$GLOBALS['__atora_mobile_test_db']['courses_by_wp'][1002] = array( 'id' => 4, 'wp_post_id' => 1002 );

			foreach ( array( 1, 2, 3, 4 ) as $course_id ) {
				$GLOBALS['__atora_mobile_test_db']['courses'][ $course_id ] = array(
					'id'            => $course_id,
					'status'        => 'published',
					'title'         => "Curso {$course_id}",
					'excerpt'       => '',
					'thumbnail_url' => '',
					'duration_hours'=> 0,
					'level'         => '',
					'language'      => 'es',
					'wp_post_id'    => 0,
				);
				$GLOBALS['__atora_mobile_test_db']['total_lessons'][ $course_id ]     = 10;
				$GLOBALS['__atora_mobile_test_db']['progress'][10][ $course_id ]     = array( 'completed_lessons' => 0 );
			}

			$response = \ATORA_Mobile_REST_Controller::courses();
			$this->assertSame( 200, $response->get_status() );
			$data  = (array) $response->get_data();
			$items = (array) ( $data['items'] ?? array() );

			$this->assertCount( 4, $items );
			$this->assertSame( array( 1, 2, 3, 4 ), array_map( static fn( $i ) => (int) ( $i['id'] ?? 0 ), $items ) );

			$statuses = array();
			foreach ( $items as $item ) {
				$statuses[ (int) $item['id'] ] = (string) ( $item['enrollment_status'] ?? '' );
			}
			$this->assertSame( 'active', $statuses[1] );
			$this->assertSame( 'active', $statuses[2] );
			$this->assertSame( 'completed', $statuses[3] );
			$this->assertSame( 'completed', $statuses[4] );
		}

		public function test_course_denies_when_user_has_no_enrollment(): void {
			$GLOBALS['__atora_mobile_test_db']['courses'][99] = array(
				'id'            => 99,
				'status'        => 'published',
				'title'         => 'Curso 99',
				'excerpt'       => '',
				'thumbnail_url' => '',
				'duration_hours'=> 0,
				'level'         => '',
				'language'      => 'es',
				'wp_post_id'    => 0,
			);

			$result = \ATORA_Mobile_REST_Controller::course( new \WP_REST_Request( array( 'course_id' => 99 ) ) );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'atora_mobile_course_forbidden', $result->get_error_code() );
		}

		public function test_course_denies_when_table_enrollment_is_expired(): void {
			$GLOBALS['__atora_mobile_test_db']['enrollments'][10]['active'] = array(
				array(
					'id'            => 900,
					'user_id'       => 10,
					'course_id'     => 55,
					'status'        => 'active',
					'expires_at'    => '2000-01-01 00:00:00',
					'last_activity' => '2026-01-01 00:00:00',
				),
			);
			$GLOBALS['__atora_mobile_test_db']['courses'][55] = array(
				'id'             => 55,
				'status'         => 'published',
				'title'          => 'Curso 55',
				'excerpt'        => '',
				'thumbnail_url'  => '',
				'duration_hours' => 0,
				'level'          => '',
				'language'       => 'es',
				'wp_post_id'     => 0,
			);

			$result = \ATORA_Mobile_REST_Controller::course( new \WP_REST_Request( array( 'course_id' => 55 ) ) );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'atora_mobile_course_forbidden', $result->get_error_code() );
		}

		public function test_course_allows_via_legacy_fallback_even_if_index_is_empty(): void {
			$GLOBALS['__atora_mobile_test_db']['courses'][77] = array(
				'id'            => 77,
				'status'        => 'published',
				'title'         => 'Curso 77',
				'excerpt'       => '',
				'thumbnail_url' => '',
				'duration_hours'=> 0,
				'level'         => '',
				'language'      => 'es',
				'wp_post_id'    => 123,
			);

			\CLMS_Helper::$enrolled[10] = array(); // index vacío
			$this->assertTrue( \CLMS_Helper::user_is_enrolled_in_course( 10, 123 ) === false );
			\CLMS_Helper::$enrolled[10] = array( 123 ); // helper confirma legacy en fallback

			$result = \ATORA_Mobile_REST_Controller::course( new \WP_REST_Request( array( 'course_id' => 77 ) ) );
			$this->assertFalse( is_wp_error( $result ) );
			$this->assertSame( 200, $result->get_status() );
		}

		public function test_lesson_denies_when_user_not_enrolled_in_parent_course(): void {
			$GLOBALS['__atora_mobile_test_db']['lessons'][501] = array(
				'id'         => 501,
				'course_id'  => 77,
				'status'     => 'published',
				'wp_post_id' => 0,
				'title'      => 'Lección',
				'type'       => 'text',
			);

			$result = \ATORA_Mobile_REST_Controller::lesson( new \WP_REST_Request( array( 'lesson_id' => 501 ) ) );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'atora_mobile_course_forbidden', $result->get_error_code() );
		}

		public function test_complete_lesson_denies_without_enrollment(): void {
			$GLOBALS['__atora_mobile_test_db']['lessons'][777] = array(
				'id'         => 777,
				'course_id'  => 99,
				'status'     => 'published',
				'wp_post_id' => 0,
				'title'      => 'Lección',
				'type'       => 'text',
			);

			$result = \ATORA_Mobile_REST_Controller::complete_lesson( new \WP_REST_Request( array( 'lesson_id' => 777 ) ) );
			$this->assertTrue( is_wp_error( $result ) );
			$this->assertSame( 'atora_mobile_course_forbidden', $result->get_error_code() );
		}
	}
}
