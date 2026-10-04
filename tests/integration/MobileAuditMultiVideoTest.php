<?php
/**
 * Integración 6.28.2: errores de base de datos en la posición (503), progreso
 * público para el tema y varios videos por lección en la API móvil.
 */

declare( strict_types = 1 );

use ATORA\LMS\LMS_Course_Service;
use ATORA\LMS\LMS_Enrollment_Service;

final class MobileAuditMultiVideoTest extends WP_UnitTestCase {

	private int $student = 0;
	private int $wp_course = 0;
	private int $course = 0;

	private const V1 = 'https://www.youtube.com/watch?v=aaaaaaaaaaa';
	private const V2 = 'https://drive.google.com/file/d/DRIVEFILE1234567/view';
	private const V3 = 'https://vimeo.com/76979871';

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		global $wpdb;
		foreach ( array( 'atora_lessons', 'atora_courses', 'atora_enrollments', 'atora_lesson_positions', 'atora_lesson_progress' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		$this->student   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_title' => 'Curso' ) );
		$this->course    = absint( LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'] );
		LMS_Enrollment_Service::enroll( $this->student, $this->course );
	}

	private function lesson( array $videos = array(), string $title = 'Lección' ): array {
		$wp_id = self::factory()->post->create( array(
			'post_type'   => 'lm_lesson',
			'post_status' => 'publish',
			'post_title'  => $title,
			'meta_input'  => array( '_clms_course_id' => $this->wp_course, '_clms_lesson_extra_videos' => $videos ),
		) );
		return array( 'wp' => $wp_id, 'id' => absint( LMS_Course_Service::get_lesson_by_wp_post( $wp_id )['id'] ) );
	}

	private static function videos( string ...$urls ): array {
		return array_map( static fn( string $url, int $i ) => array( 'source' => 'x', 'url' => $url, 'title' => 'Video ' . ( $i + 1 ) ), $urls, array_keys( $urls ) );
	}

	private function get_lesson( int $lesson_id ): array {
		wp_set_current_user( $this->student );
		$request = new WP_REST_Request( 'GET', "/atora-mobile/v1/lessons/{$lesson_id}" );
		$request->set_url_params( array( 'lesson_id' => $lesson_id ) );
		return ATORA_Mobile_REST_Controller::lesson( $request )->get_data()['lesson'];
	}

	private function put_position( int $lesson_id, int $seconds, string $at, string $event, ?string $key = null ) {
		wp_set_current_user( $this->student );
		$params = array( 'position_seconds' => $seconds, 'duration_seconds' => 600, 'client_event_id' => $event, 'client_recorded_at' => $at );
		if ( null !== $key ) {
			$params['video_key'] = $key;
		}
		$request = new WP_REST_Request( 'PUT', "/atora-mobile/v1/lessons/{$lesson_id}/position" );
		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}
		$request->set_url_params( array( 'lesson_id' => $lesson_id ) );
		return ATORA_Mobile_REST_Controller::save_position( $request );
	}

	// ── 1. Posición: error de base de datos → 503 ────────────────────────────

	public function test_position_db_error_answers_503_not_200(): void {
		$lesson = $this->lesson( self::videos( self::V1 ) );
		$break  = static function ( string $query ): string {
			return false !== strpos( $query, 'atora_lesson_positions' ) && 0 !== stripos( ltrim( $query ), 'SELECT' )
				? str_replace( 'atora_lesson_positions', 'atora_lesson_positions_no_existe', $query )
				: $query;
		};
		add_filter( 'query', $break );
		$result = $this->put_position( $lesson['id'], 30, '2026-01-01T10:00:00Z', 'evt_dbfail_0001' );
		remove_filter( 'query', $break );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	// ── 2. Progreso público para el tema ─────────────────────────────────────

	public function test_public_progress_uses_wp_post_id(): void {
		$lessons = array();
		for ( $i = 1; $i <= 4; $i++ ) {
			$lessons[] = $this->lesson( array(), "L{$i}" );
		}
		LMS_Enrollment_Service::complete_lesson( $this->student, $lessons[0]['id'] );
		LMS_Enrollment_Service::complete_lesson( $this->student, $lessons[1]['id'] );

		$this->assertTrue( function_exists( 'atora_lms_get_progress' ) );
		$this->assertSame( 50, atora_lms_get_progress( $this->student, $this->wp_course ) );
		$this->assertSame( 0, atora_lms_get_progress( $this->student, $this->course + 999999 ), 'Un ID que no es de post de curso no da progreso.' );
	}

	// ── 7. Varios videos por lección ─────────────────────────────────────────

	public function test_lesson_returns_all_videos_in_order_and_respects_limit(): void {
		$lesson = $this->lesson( self::videos( self::V1, self::V2, self::V3 ) );
		$videos = $this->get_lesson( $lesson['id'] )['videos'];

		$this->assertCount( 3, $videos );
		$this->assertSame( array( 'youtube', 'google_drive', 'vimeo' ), array_column( $videos, 'provider' ) );
		$this->assertSame( array( 'Video 1', 'Video 2', 'Video 3' ), array_column( $videos, 'title' ) );
		$this->assertNotSame( '', $videos[1]['embed_url'] );
		$this->assertStringContainsString( 'aaaaaaaaaaa', $videos[0]['thumbnail_url'] );

		update_post_meta( $lesson['wp'], '_clms_lesson_ui_limit_videos', 2 );
		$this->assertCount( 2, $this->get_lesson( $lesson['id'] )['videos'], 'Mismo límite que la web.' );
	}

	public function test_reordering_keeps_keys_and_repeated_urls_get_a_suffix(): void {
		$lesson = $this->lesson( self::videos( self::V1, self::V2, self::V3 ) );
		$before = array_column( $this->get_lesson( $lesson['id'] )['videos'], 'key', 'url' );

		update_post_meta( $lesson['wp'], '_clms_lesson_extra_videos', self::videos( self::V3, self::V1, self::V2 ) );
		$after = array_column( $this->get_lesson( $lesson['id'] )['videos'], 'key', 'url' );
		ksort( $before );
		ksort( $after );
		$this->assertSame( $before, $after, 'Mismas claves para las mismas URLs, en cualquier orden.' );

		$keys = array_column( ATORA_Lesson_Videos::with_keys( self::videos( self::V1, 'https://youtu.be/aaaaaaaaaaa' ) ), 'key' );
		$this->assertSame( $keys[0] . '-2', $keys[1], 'La misma URL (en otra forma) repetida lleva sufijo.' );
	}

	public function test_positions_are_per_video_and_old_apps_still_save_the_first(): void {
		$lesson = $this->lesson( self::videos( self::V1, self::V2, self::V3 ) );
		$keys   = array_column( $this->get_lesson( $lesson['id'] )['videos'], 'key' );

		$this->assertTrue( $this->put_position( $lesson['id'], 120, '2026-01-01T10:00:00Z', 'evt_video1_0001', $keys[0] )->get_data()['applied'] );
		$this->assertTrue( $this->put_position( $lesson['id'], 300, '2026-01-01T10:01:00Z', 'evt_video2_0001', $keys[1] )->get_data()['applied'] );

		$videos = $this->get_lesson( $lesson['id'] )['videos'];
		$this->assertSame( array( 120, 300, 0 ), array_column( $videos, 'resume_position_seconds' ), 'El video 2 no pisa al 1.' );

		// App 0.4.0: sin video_key → primer video; la marca más reciente gana para el primero.
		$this->assertTrue( $this->put_position( $lesson['id'], 45, '2026-01-01T10:05:00Z', 'evt_oldapp_0001' )->get_data()['applied'] );
		$data = $this->get_lesson( $lesson['id'] );
		$this->assertSame( 45, $data['videos'][0]['resume_position_seconds'] );
		$this->assertSame( 45, $data['resume_position_seconds'], 'El campo de compatibilidad es el del primer video.' );
		$this->assertSame( 300, $data['videos'][1]['resume_position_seconds'] );

		$unknown = $this->put_position( $lesson['id'], 10, '2026-01-01T10:06:00Z', 'evt_unknown_001', 'ffffffffffff' );
		$this->assertSame( 404, $unknown->get_error_data()['status'] );
	}

	public function test_curriculum_reports_video_count_and_compat_fields_stay(): void {
		$lesson = $this->lesson( self::videos( self::V2, self::V1, self::V3 ) );
		wp_set_current_user( $this->student );
		$request = new WP_REST_Request( 'GET', "/atora-mobile/v1/courses/{$this->course}" );
		$request->set_url_params( array( 'course_id' => $this->course ) );
		$curriculum = ATORA_Mobile_REST_Controller::course( $request )->get_data()['curriculum'];
		$this->assertSame( 3, $curriculum[0]['video_count'] );

		$data = $this->get_lesson( $lesson['id'] );
		$this->assertSame( self::V2, $data['video_url'], 'La APK 0.4.0 sigue recibiendo el primer video.' );
		$this->assertSame( 'google_drive', $data['video_provider'] );
	}
}
