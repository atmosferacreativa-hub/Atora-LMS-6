<?php

declare( strict_types = 1 );

/**
 * Pruebas de compatibilidad: matrículas en tablas + legacy (router/helper canónico).
 *
 * Nota: este archivo define stubs mínimos de servicios LMS para probar la lógica
 * del controller móvil sin depender de una instalación completa de WordPress.
 */

namespace ATORA\LMS {

if ( ! class_exists( LMS_Enrollment_Service::class ) ) {
	class LMS_Enrollment_Service {
		public static array $enrollments = array(); // [user_id][status] => rows
		public static function get_user_enrollments( int $user_id, string $status = 'active' ): array {
			return (array) ( self::$enrollments[ $user_id ][ $status ] ?? array() );
		}
		public static function get_progress( int $user_id, int $course_id ): array {
			return array(
				'total_lessons'     => 10,
				'completed_lessons' => 0,
				'progress_pct'      => 0,
				'is_complete'       => false,
			);
		}
		public static function complete_lesson( int $user_id, int $lesson_id ): bool { return true; }
	}
}

if ( ! class_exists( LMS_Course_Service::class ) ) {
	class LMS_Course_Service {
		public static array $courses = array(); // [course_id] => course array
		public static array $lessons = array(); // [lesson_id] => lesson array
		public static array $by_wp   = array(); // [wp_post_id] => course array (must include id)

		public static function get( int $course_id ): ?array {
			return isset( self::$courses[ $course_id ] ) ? (array) self::$courses[ $course_id ] : null;
		}
		public static function get_by_wp_post( int $wp_post_id ): ?array {
			return isset( self::$by_wp[ $wp_post_id ] ) ? (array) self::$by_wp[ $wp_post_id ] : null;
		}
		public static function get_lessons( int $course_id ): array { return array(); }
		public static function get_lesson( int $lesson_id ): ?array {
			return isset( self::$lessons[ $lesson_id ] ) ? (array) self::$lessons[ $lesson_id ] : null;
		}
	}
}
}

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
		}
	}
}

namespace ATORA\Tests\Rest {

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/mobile/class-mobile-rest-controller.php';

final class MobileEnrollmentCompatibilityTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['__atora_test_current_user_id'] = 10;

		\ATORA\LMS\LMS_Enrollment_Service::$enrollments = array();
		\ATORA\LMS\LMS_Course_Service::$courses = array();
		\ATORA\LMS\LMS_Course_Service::$lessons = array();
		\ATORA\LMS\LMS_Course_Service::$by_wp   = array();
		\CLMS_Helper::$enrolled  = array();
		\CLMS_Helper::$completed = array();

		$ref = new \ReflectionClass( \ATORA_Mobile_REST_Controller::class );
		$prop = $ref->getProperty( 'enrollment_index_cache' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	public function test_courses_includes_tables_and_legacy_and_dedupes(): void {
		\ATORA\LMS\LMS_Enrollment_Service::$enrollments[10]['active'] = array(
			array( 'course_id' => 1, 'status' => 'active', 'last_activity' => '2026-01-01 00:00:00' ),
			array( 'course_id' => 2, 'status' => 'active', 'last_activity' => '2026-01-02 00:00:00' ),
		);
		\ATORA\LMS\LMS_Enrollment_Service::$enrollments[10]['completed'] = array(
			array( 'course_id' => 3, 'status' => 'completed', 'last_activity' => '2026-01-03 00:00:00' ),
		);

		\CLMS_Helper::$enrolled[10] = array( 1001, 1002 );
		\CLMS_Helper::$completed[10] = array( 1002 );

		\ATORA\LMS\LMS_Course_Service::$by_wp[1001] = array( 'id' => 2 );
		\ATORA\LMS\LMS_Course_Service::$by_wp[1002] = array( 'id' => 4 );

		foreach ( array( 1, 2, 3, 4 ) as $course_id ) {
			\ATORA\LMS\LMS_Course_Service::$courses[ $course_id ] = array(
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
		}

		$response = \ATORA_Mobile_REST_Controller::courses();
		$this->assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
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
		\ATORA\LMS\LMS_Course_Service::$courses[99] = array(
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

	public function test_course_allows_via_legacy_fallback_even_if_index_is_empty(): void {
		\ATORA\LMS\LMS_Course_Service::$courses[77] = array(
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
		\ATORA\LMS\LMS_Course_Service::$lessons[501] = array(
			'id'        => 501,
			'course_id' => 77,
			'status'    => 'published',
			'wp_post_id'=> 0,
			'title'     => 'Lección',
			'type'      => 'text',
		);

		$result = \ATORA_Mobile_REST_Controller::lesson( new \WP_REST_Request( array( 'lesson_id' => 501 ) ) );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'atora_mobile_course_forbidden', $result->get_error_code() );
	}

	public function test_complete_lesson_denies_without_enrollment(): void {
		\ATORA\LMS\LMS_Course_Service::$lessons[601] = array(
			'id'        => 601,
			'course_id' => 88,
			'status'    => 'published',
			'wp_post_id'=> 0,
			'title'     => 'Lección',
			'type'      => 'text',
		);

		$result = \ATORA_Mobile_REST_Controller::complete_lesson( new \WP_REST_Request( array( 'lesson_id' => 601 ) ) );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'atora_mobile_course_forbidden', $result->get_error_code() );
	}
}
}
