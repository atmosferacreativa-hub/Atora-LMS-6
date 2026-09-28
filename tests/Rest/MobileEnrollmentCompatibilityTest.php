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
	require_once __DIR__ . '/../stubs/class-clms-helper-stub.php';
	if ( ! function_exists( 'wp_get_current_user' ) ) {
		function wp_get_current_user() {
			return (object) array(
				'ID'           => get_current_user_id(),
				'user_email'   => 'student@example.test',
				'display_name' => 'Student',
			);
		}
	}
	if ( ! function_exists( 'get_avatar_url' ) ) {
		function get_avatar_url( $id_or_email = null, $args = null ): string {
			unset( $id_or_email, $args );
			return '';
		}
	}
}

namespace ATORA\Tests\Rest {

	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/../../includes/mobile/class-mobile-rest-controller.php';

	final class MobileTestWpdb {
		public string $prefix = 'wp_';
		public array $insert_calls = array();

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

		public function insert( $table, $data, $format = null ): int {
			$this->insert_calls[] = array( 'table' => $table, 'data' => $data );
			return 1;
		}
		public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
	}

	final class MobileEnrollmentCompatibilityTest extends TestCase {
		protected function setUp(): void {
			parent::setUp();
			atora_test_reset_posts();
			atora_test_reset_post_types();

			$GLOBALS['__atora_test_current_user_id'] = 10;
			atora_test_reset_transients();
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
			atora_test_reset_clms_helper_stub();
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
			// El fallback legacy solo debe aplicar si el CPT existe y está publicado.
			atora_test_set_post( 123, array( 'post_type' => 'lm_course', 'post_status' => 'publish' ) );

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

		public function test_tables_course_with_broken_wp_link_is_hidden_and_inaccessible(): void {
			// course_id=1 → wp_post_id=123 en trash; course_id=2 → wp_post_id=999 ausente.
			// course_id=5 → wp_post_id=124 draft; course_id=6 → wp_post_id=125 private.
			atora_test_set_post( 123, array( 'post_type' => 'lm_course', 'post_status' => 'trash' ) );
			atora_test_set_post( 124, array( 'post_type' => 'lm_course', 'post_status' => 'draft' ) );
			atora_test_set_post( 125, array( 'post_type' => 'lm_course', 'post_status' => 'private' ) );
			atora_test_set_post( 555, array( 'post_type' => 'lm_course', 'post_status' => 'publish' ) );

			$GLOBALS['__atora_mobile_test_db']['enrollments'][10]['active'] = array(
				array( 'id' => 501, 'user_id' => 10, 'course_id' => 1, 'status' => 'active', 'last_activity' => '2026-01-01 00:00:00' ),
				array( 'id' => 502, 'user_id' => 10, 'course_id' => 2, 'status' => 'active', 'last_activity' => '2026-01-02 00:00:00' ),
				array( 'id' => 503, 'user_id' => 10, 'course_id' => 3, 'status' => 'active', 'last_activity' => '2026-01-03 00:00:00' ),
				array( 'id' => 504, 'user_id' => 10, 'course_id' => 4, 'status' => 'active', 'last_activity' => '2026-01-04 00:00:00' ),
				array( 'id' => 505, 'user_id' => 10, 'course_id' => 5, 'status' => 'active', 'last_activity' => '2026-01-05 00:00:00' ),
				array( 'id' => 506, 'user_id' => 10, 'course_id' => 6, 'status' => 'active', 'last_activity' => '2026-01-06 00:00:00' ),
			);

			$GLOBALS['__atora_mobile_test_db']['courses'][1] = array(
				'id'            => 1,
				'status'        => 'published',
				'title'         => 'Curso roto (trash)',
				'excerpt'       => '',
				'thumbnail_url' => '',
				'duration_hours'=> 0,
				'level'         => '',
				'language'      => 'es',
				'wp_post_id'    => 123,
			);
			$GLOBALS['__atora_mobile_test_db']['courses'][2] = array(
				'id'            => 2,
				'status'        => 'published',
				'title'         => 'Curso roto (missing)',
				'excerpt'       => '',
				'thumbnail_url' => '',
				'duration_hours'=> 0,
				'level'         => '',
				'language'      => 'es',
				'wp_post_id'    => 999,
			);
			$GLOBALS['__atora_mobile_test_db']['courses'][3] = array(
				'id'            => 3,
				'status'        => 'published',
				'title'         => 'Curso válido (publicado en WP)',
				'excerpt'       => '',
				'thumbnail_url' => '',
				'duration_hours'=> 0,
				'level'         => '',
				'language'      => 'es',
				'wp_post_id'    => 555,
			);
			$GLOBALS['__atora_mobile_test_db']['courses'][4] = array(
				'id'            => 4,
				'status'        => 'published',
				'title'         => 'Curso válido (nativo tablas)',
				'excerpt'       => '',
				'thumbnail_url' => '',
				'duration_hours'=> 0,
				'level'         => '',
				'language'      => 'es',
				'wp_post_id'    => 0,
			);
			$GLOBALS['__atora_mobile_test_db']['courses'][5] = array(
				'id'            => 5,
				'status'        => 'published',
				'title'         => 'Curso roto (draft)',
				'excerpt'       => '',
				'thumbnail_url' => '',
				'duration_hours'=> 0,
				'level'         => '',
				'language'      => 'es',
				'wp_post_id'    => 124,
			);
			$GLOBALS['__atora_mobile_test_db']['courses'][6] = array(
				'id'            => 6,
				'status'        => 'published',
				'title'         => 'Curso roto (private)',
				'excerpt'       => '',
				'thumbnail_url' => '',
				'duration_hours'=> 0,
				'level'         => '',
				'language'      => 'es',
				'wp_post_id'    => 125,
			);

			foreach ( array( 1, 2, 3, 4, 5, 6 ) as $course_id ) {
				$GLOBALS['__atora_mobile_test_db']['total_lessons'][ $course_id ] = 0;
				$GLOBALS['__atora_mobile_test_db']['progress'][10][ $course_id ] = array( 'completed_lessons' => 0 );
			}

			$GLOBALS['__atora_mobile_test_db']['lessons'][501] = array(
				'id'         => 501,
				'course_id'  => 1,
				'status'     => 'published',
				'wp_post_id' => 0,
				'title'      => 'Lección en curso roto',
				'type'       => 'text',
			);

			$response = \ATORA_Mobile_REST_Controller::courses();
			$this->assertSame( 200, $response->get_status() );
			$data  = (array) $response->get_data();
			$items = (array) ( $data['items'] ?? array() );
			$this->assertSame( array( 3, 4 ), array_map( static fn( $i ) => (int) ( $i['id'] ?? 0 ), $items ) );

			$course_1 = \ATORA_Mobile_REST_Controller::course( new \WP_REST_Request( array( 'course_id' => 1 ) ) );
			$this->assertTrue( is_wp_error( $course_1 ) );
			$this->assertSame( 'atora_mobile_course_forbidden', $course_1->get_error_code() );

			$course_2 = \ATORA_Mobile_REST_Controller::course( new \WP_REST_Request( array( 'course_id' => 2 ) ) );
			$this->assertTrue( is_wp_error( $course_2 ) );
			$this->assertSame( 'atora_mobile_course_forbidden', $course_2->get_error_code() );

			$course_5 = \ATORA_Mobile_REST_Controller::course( new \WP_REST_Request( array( 'course_id' => 5 ) ) );
			$this->assertTrue( is_wp_error( $course_5 ) );
			$this->assertSame( 'atora_mobile_course_forbidden', $course_5->get_error_code() );

			$course_6 = \ATORA_Mobile_REST_Controller::course( new \WP_REST_Request( array( 'course_id' => 6 ) ) );
			$this->assertTrue( is_wp_error( $course_6 ) );
			$this->assertSame( 'atora_mobile_course_forbidden', $course_6->get_error_code() );

			$lesson = \ATORA_Mobile_REST_Controller::lesson( new \WP_REST_Request( array( 'lesson_id' => 501 ) ) );
			$this->assertTrue( is_wp_error( $lesson ) );
			$this->assertSame( 'atora_mobile_course_forbidden', $lesson->get_error_code() );

			$quiz = \ATORA_Mobile_REST_Controller::quiz( new \WP_REST_Request( array( 'lesson_id' => 501 ) ) );
			$this->assertTrue( is_wp_error( $quiz ) );
			$this->assertSame( 'atora_mobile_course_forbidden', $quiz->get_error_code() );

			$submit = \ATORA_Mobile_REST_Controller::submit_quiz( new \WP_REST_Request( array( 'lesson_id' => 501, 'answers' => array(), 'token' => 'x' ) ) );
			$this->assertTrue( is_wp_error( $submit ) );
			$this->assertSame( 'atora_mobile_course_forbidden', $submit->get_error_code() );

			$this->assertSame( array(), $GLOBALS['__atora_test_transients'] ?? array(), 'No debe emitir token ni escribir transients si el curso está bloqueado' );
			global $wpdb;
			$this->assertSame( array(), $wpdb->insert_calls, 'No debe escribir en DB si el curso está bloqueado' );
		}

		public function test_dashboard_hides_broken_wp_links_and_counts_pending_only_for_visible_courses(): void {
			atora_test_set_post( 123, array( 'post_type' => 'lm_course', 'post_status' => 'trash' ) );
			atora_test_set_post( 124, array( 'post_type' => 'lm_course', 'post_status' => 'draft' ) );
			atora_test_set_post( 125, array( 'post_type' => 'lm_course', 'post_status' => 'private' ) );
			atora_test_set_post( 555, array( 'post_type' => 'lm_course', 'post_status' => 'publish' ) );

			$GLOBALS['__atora_mobile_test_db']['enrollments'][10]['active'] = array(
				array( 'id' => 501, 'user_id' => 10, 'course_id' => 1, 'status' => 'active', 'last_activity' => '2026-01-01 00:00:00' ),
				array( 'id' => 502, 'user_id' => 10, 'course_id' => 2, 'status' => 'active', 'last_activity' => '2026-01-02 00:00:00' ),
				array( 'id' => 503, 'user_id' => 10, 'course_id' => 3, 'status' => 'active', 'last_activity' => '2026-01-03 00:00:00' ),
				array( 'id' => 504, 'user_id' => 10, 'course_id' => 4, 'status' => 'active', 'last_activity' => '2026-01-04 00:00:00' ),
				array( 'id' => 505, 'user_id' => 10, 'course_id' => 5, 'status' => 'active', 'last_activity' => '2026-01-05 00:00:00' ),
				array( 'id' => 506, 'user_id' => 10, 'course_id' => 6, 'status' => 'active', 'last_activity' => '2026-01-06 00:00:00' ),
			);

			$GLOBALS['__atora_mobile_test_db']['courses'][1] = array( 'id' => 1, 'status' => 'published', 'title' => 'Trash', 'excerpt' => '', 'thumbnail_url' => '', 'duration_hours' => 0, 'level' => '', 'language' => 'es', 'wp_post_id' => 123 );
			$GLOBALS['__atora_mobile_test_db']['courses'][2] = array( 'id' => 2, 'status' => 'published', 'title' => 'Missing', 'excerpt' => '', 'thumbnail_url' => '', 'duration_hours' => 0, 'level' => '', 'language' => 'es', 'wp_post_id' => 999 );
			$GLOBALS['__atora_mobile_test_db']['courses'][3] = array( 'id' => 3, 'status' => 'published', 'title' => 'Published', 'excerpt' => '', 'thumbnail_url' => '', 'duration_hours' => 0, 'level' => '', 'language' => 'es', 'wp_post_id' => 555 );
			$GLOBALS['__atora_mobile_test_db']['courses'][4] = array( 'id' => 4, 'status' => 'published', 'title' => 'Native', 'excerpt' => '', 'thumbnail_url' => '', 'duration_hours' => 0, 'level' => '', 'language' => 'es', 'wp_post_id' => 0 );
			$GLOBALS['__atora_mobile_test_db']['courses'][5] = array( 'id' => 5, 'status' => 'published', 'title' => 'Draft', 'excerpt' => '', 'thumbnail_url' => '', 'duration_hours' => 0, 'level' => '', 'language' => 'es', 'wp_post_id' => 124 );
			$GLOBALS['__atora_mobile_test_db']['courses'][6] = array( 'id' => 6, 'status' => 'published', 'title' => 'Private', 'excerpt' => '', 'thumbnail_url' => '', 'duration_hours' => 0, 'level' => '', 'language' => 'es', 'wp_post_id' => 125 );

			// Inflar pendientes en cursos NO visibles, no deben contarse.
			$GLOBALS['__atora_mobile_test_db']['total_lessons'][1] = 10;
			$GLOBALS['__atora_mobile_test_db']['total_lessons'][2] = 10;
			$GLOBALS['__atora_mobile_test_db']['total_lessons'][5] = 10;
			$GLOBALS['__atora_mobile_test_db']['total_lessons'][6] = 10;
			$GLOBALS['__atora_mobile_test_db']['progress'][10][1] = array( 'completed_lessons' => 0 );
			$GLOBALS['__atora_mobile_test_db']['progress'][10][2] = array( 'completed_lessons' => 0 );
			$GLOBALS['__atora_mobile_test_db']['progress'][10][5] = array( 'completed_lessons' => 0 );
			$GLOBALS['__atora_mobile_test_db']['progress'][10][6] = array( 'completed_lessons' => 0 );

			$GLOBALS['__atora_mobile_test_db']['total_lessons'][3] = 3;
			$GLOBALS['__atora_mobile_test_db']['total_lessons'][4] = 2;
			$GLOBALS['__atora_mobile_test_db']['progress'][10][3] = array( 'completed_lessons' => 1 );
			$GLOBALS['__atora_mobile_test_db']['progress'][10][4] = array( 'completed_lessons' => 0 );

			$resp = \ATORA_Mobile_REST_Controller::dashboard();
			$this->assertSame( 200, $resp->get_status() );
			$data = (array) $resp->get_data();
			$courses = (array) ( $data['courses'] ?? array() );
			$this->assertSame( array( 3, 4 ), array_map( static fn( $c ) => (int) ( $c['id'] ?? 0 ), $courses ) );
			$this->assertSame( (3 - 1) + (2 - 0), (int) ( $data['pending_activities'] ?? -1 ) );
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
