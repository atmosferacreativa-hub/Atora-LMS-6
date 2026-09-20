<?php

declare( strict_types = 1 );

namespace ATORA\Tests\Rest;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/mobile/class-mobile-rest-controller.php';

final class MobileLessonVideoCompatibilityTest extends TestCase {
	private function reset_enrollment_cache(): void {
		$ref  = new \ReflectionClass( \ATORA_Mobile_REST_Controller::class );
		$prop = $ref->getProperty( 'enrollment_index_cache' );
		$prop->setAccessible( true );
		$prop->setValue( null, array() );
	}

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['__atora_test_current_user_id'] = 10;
		$GLOBALS['__atora_mobile_test_db']       = array(
			'enrollments'   => array(),
			'courses'       => array(),
			'lessons'       => array(),
			'progress'      => array(),
			'total_lessons' => array(),
			'completed_lesson_ids' => array(),
		);

		global $wpdb;
		$wpdb = new class {
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
				$db      = (array) ( $GLOBALS['__atora_mobile_test_db'] ?? array() );
				$courses = (array) ( $db['courses'] ?? array() );
				$lessons = (array) ( $db['lessons'] ?? array() );

				if ( is_string( $sql ) && str_contains( $sql, 'FROM wp_atora_courses WHERE id =' ) ) {
					if ( preg_match( '/WHERE id = (\\d+)/', $sql, $m ) ) {
						$id = (int) $m[1];
						return isset( $courses[ $id ] ) ? (array) $courses[ $id ] : null;
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
				$enrollments = (array) ( $db['enrollments'] ?? array() );

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
				$progress = (array) ( $db['progress'] ?? array() );

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
				$db        = (array) ( $GLOBALS['__atora_mobile_test_db'] ?? array() );
				$completed = (array) ( $db['completed_lesson_ids'] ?? array() );

				if ( is_string( $sql ) && str_contains( $sql, 'FROM wp_atora_lesson_progress' ) && str_contains( $sql, 'lesson_id' ) ) {
					if ( preg_match( '/WHERE user_id = (\\d+) AND course_id = (\\d+)/', $sql, $m ) ) {
						$user_id   = (int) $m[1];
						$course_id = (int) $m[2];
						return array_map( 'absint', (array) ( $completed[ $user_id ][ $course_id ] ?? array() ) );
					}
				}

				return array();
			}
		};

		atora_test_reset_posts();
		atora_test_reset_post_meta();
		$this->reset_enrollment_cache();
	}

	private function seed_enrolled_lesson( int $course_id, int $lesson_id, int $wp_post_id ): void {
		$GLOBALS['__atora_mobile_test_db']['courses'][ $course_id ] = array(
			'id'         => $course_id,
			'wp_post_id' => 0,
			'title'      => 'Curso',
			'status'     => 'published',
		);

		$GLOBALS['__atora_mobile_test_db']['lessons'][ $lesson_id ] = array(
			'id'         => $lesson_id,
			'wp_post_id' => $wp_post_id,
			'course_id'  => $course_id,
			'status'     => 'published',
			'type'       => 'video',
			'title'      => 'Lección',
			'video_url'  => '',
			'duration_min' => 0,
			'is_required'  => 1,
		);

		$GLOBALS['__atora_mobile_test_db']['enrollments'][10]['active'] = array(
			array( 'id' => 1, 'user_id' => 10, 'course_id' => $course_id, 'status' => 'active', 'last_activity' => '2026-01-01 00:00:00' ),
		);
	}

	public function test_drive_from_extra_videos_normalizes_to_preview(): void {
		$this->seed_enrolled_lesson( 77, 501, 9001 );
		atora_test_set_post( 9001, array(
			'post_type'    => 'lm_lesson',
			'post_content' => '<p>contenido</p>',
		) );
		atora_test_set_post_meta( 9001, '_clms_lesson_extra_videos', array(
			array( 'url' => 'https://drive.google.com/file/d/ABCdef12345/view?usp=drive_link' ),
		) );

		$response = \ATORA_Mobile_REST_Controller::lesson( new \WP_REST_Request( array( 'lesson_id' => 501 ) ) );
		$this->assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		$lesson = (array) ( $data['lesson'] ?? array() );

		$this->assertSame( 'google_drive', $lesson['video_provider'] );
		$this->assertSame( 'https://drive.google.com/file/d/ABCdef12345/preview', $lesson['video_embed_url'] );
	}

	public function test_drive_from_legacy_video_url_fallback_normalizes_to_preview(): void {
		$this->seed_enrolled_lesson( 77, 502, 9002 );
		atora_test_set_post( 9002, array(
			'post_type'    => 'lm_lesson',
			'post_content' => '',
		) );
		atora_test_set_post_meta( 9002, '_clms_lesson_video_url', 'https://docs.google.com/file/d/ZZZ999xxx888/view' );

		$response = \ATORA_Mobile_REST_Controller::lesson( new \WP_REST_Request( array( 'lesson_id' => 502 ) ) );
		$data = (array) $response->get_data();
		$lesson = (array) ( $data['lesson'] ?? array() );

		$this->assertSame( 'google_drive', $lesson['video_provider'] );
		$this->assertSame( 'https://drive.google.com/file/d/ZZZ999xxx888/preview', $lesson['video_embed_url'] );
	}

	public function test_rejects_external_hosts_for_embed(): void {
		$this->seed_enrolled_lesson( 77, 503, 9003 );
		atora_test_set_post( 9003, array(
			'post_type'    => 'lm_lesson',
			'post_content' => '<iframe src="https://evil.example/file/d/ABCdef12345/preview"></iframe>',
		) );
		atora_test_set_post_meta( 9003, '_clms_lesson_video_url', 'https://evil.example/file/d/ABCdef12345/view' );

		$response = \ATORA_Mobile_REST_Controller::lesson( new \WP_REST_Request( array( 'lesson_id' => 503 ) ) );
		$data = (array) $response->get_data();
		$lesson = (array) ( $data['lesson'] ?? array() );

		$this->assertSame( '', $lesson['video_embed_url'] );
		$this->assertNotSame( 'google_drive', $lesson['video_provider'] );
	}

	public function test_direct_video_url_keeps_direct_provider(): void {
		$this->seed_enrolled_lesson( 77, 504, 9004 );
		$GLOBALS['__atora_mobile_test_db']['lessons'][504]['video_url'] = 'https://cdn.example.test/video.mp4';
		atora_test_set_post( 9004, array(
			'post_type'    => 'lm_lesson',
			'post_content' => '',
		) );

		$response = \ATORA_Mobile_REST_Controller::lesson( new \WP_REST_Request( array( 'lesson_id' => 504 ) ) );
		$data = (array) $response->get_data();
		$lesson = (array) ( $data['lesson'] ?? array() );

		$this->assertSame( 'direct', $lesson['video_provider'] );
		$this->assertSame( '', $lesson['video_embed_url'] );
		$this->assertSame( 'https://cdn.example.test/video.mp4', $lesson['video_url'] );
	}
}

