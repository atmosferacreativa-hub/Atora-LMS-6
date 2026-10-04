<?php

declare( strict_types = 1 );

namespace {
	// Stubs controlados por $GLOBALS['__vt'] (definidos solo si no existen).
	if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
		function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) { return $GLOBALS['__vt']['attachments'][ (int) $id ] ?? false; }
	}
	if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
		function get_the_post_thumbnail_url( $post = null, $size = 'post-thumbnail' ) { return $GLOBALS['__vt']['featured'][ (int) $post ] ?? false; }
	}
	if ( ! function_exists( 'wp_next_scheduled' ) ) {
		function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['__vt']['scheduled'][ $hook . '|' . implode( ',', $args ) ] ?? false; }
	}
	if ( ! function_exists( 'wp_schedule_single_event' ) ) {
		function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
			$GLOBALS['__vt']['scheduled'][ $hook . '|' . implode( ',', $args ) ] = $timestamp;
			return true;
		}
	}
	if ( ! function_exists( 'wp_remote_get' ) ) {
		function wp_remote_get( $url, $args = array() ) {
			$GLOBALS['__vt']['remote_calls'][] = $url;
			return $GLOBALS['__vt']['remote_response'];
		}
	}
	if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
		function wp_remote_retrieve_response_code( $response ) { return is_array( $response ) ? ( $response['code'] ?? 0 ) : 0; }
	}
	if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
		function wp_remote_retrieve_body( $response ) { return is_array( $response ) ? ( $response['body'] ?? '' ) : ''; }
	}
}

namespace ATORA\Tests\Media {

	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/../../includes/media/class-course-cover-resolver.php';
	require_once __DIR__ . '/../../includes/media/class-video-thumbnail-resolver.php';

	final class VideoThumbnailResolverTest extends TestCase {
		private const LESSON = 501;
		private const COURSE = 428;
		private const COVER  = 'https://academia.test/uploads/portada-curso.jpg';
		private const YT     = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['__vt'] = array(
				'attachments'     => array(),
				'featured'        => array(),
				'scheduled'       => array(),
				'remote_calls'    => array(),
				'remote_response' => array( 'code' => 500, 'body' => '' ),
			);
			$GLOBALS['__atora_test_post_meta'] = array();
			atora_test_reset_transients();
		}

		private function manual( string $video_url, int $thumb_id, string $image ): void {
			atora_test_set_post_meta( self::LESSON, '_clms_lesson_extra_videos', array( array( 'source' => 'youtube', 'url' => $video_url, 'thumb_id' => $thumb_id ) ) );
			$GLOBALS['__vt']['attachments'][ $thumb_id ] = $image;
		}

		private function resolve( string $video_url, string $cover = self::COVER ): string {
			return \ATORA_Video_Thumbnail_Resolver::resolve( self::LESSON, $video_url, $cover, self::COURSE );
		}

		public function test_1_manual_thumbnail_wins_over_youtube(): void {
			$this->manual( 'https://youtu.be/dQw4w9WgXcQ', 77, 'https://academia.test/uploads/manual.jpg' );
			$this->assertSame( 'https://academia.test/uploads/manual.jpg', $this->resolve( self::YT ) );
		}

		public function test_manual_thumbnail_of_another_video_is_ignored(): void {
			$this->manual( 'https://youtu.be/AAAAAAAAAAA', 77, 'https://academia.test/uploads/otro.jpg' );
			$this->assertSame( 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $this->resolve( self::YT ) );
		}

		public function test_2_youtube_uses_public_image_without_remote_calls(): void {
			$this->assertSame( 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $this->resolve( self::YT ) );
			$this->assertSame( 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $this->resolve( 'https://www.youtube.com/shorts/dQw4w9WgXcQ' ) );
			$this->assertSame( array(), $GLOBALS['__vt']['remote_calls'] );
		}

		public function test_3_vimeo_uses_cache_and_schedules_a_single_fetch_on_miss(): void {
			$GLOBALS['__vt']['featured'][ self::LESSON ] = 'https://academia.test/uploads/destacada.jpg';

			// Sin caché: no llama a Vimeo en la petición, programa la consulta y cae a la destacada.
			$this->assertSame( 'https://academia.test/uploads/destacada.jpg', $this->resolve( 'https://vimeo.com/76979871' ) );
			$this->assertArrayHasKey( 'atora_fetch_vimeo_thumbnail|76979871', $GLOBALS['__vt']['scheduled'] );
			$this->assertSame( array(), $GLOBALS['__vt']['remote_calls'] );

			// La consulta en segundo plano guarda la miniatura; la siguiente petición la usa.
			$GLOBALS['__vt']['remote_response'] = array( 'code' => 200, 'body' => json_encode( array( 'thumbnail_url' => 'https://i.vimeocdn.com/video/1.jpg' ) ) );
			\ATORA_Video_Thumbnail_Resolver::fetch_vimeo_thumbnail( '76979871' );
			$this->assertSame( 'https://i.vimeocdn.com/video/1.jpg', $this->resolve( 'https://vimeo.com/76979871' ) );
			$this->assertCount( 1, $GLOBALS['__vt']['remote_calls'] );
		}

		public function test_vimeo_failure_is_cached_and_falls_back(): void {
			\ATORA_Video_Thumbnail_Resolver::fetch_vimeo_thumbnail( '123' );
			$this->assertSame( 'none', get_transient( 'atora_vimeo_thumb_123' ) );
			$this->assertSame( self::COVER, $this->resolve( 'https://vimeo.com/123' ) );
			$this->assertArrayNotHasKey( 'atora_fetch_vimeo_thumbnail|123', $GLOBALS['__vt']['scheduled'], 'Un fallo reciente no reprograma.' );
		}

		public function test_4_drive_and_direct_mp4_fall_back_to_lesson_featured_image(): void {
			$GLOBALS['__vt']['featured'][ self::LESSON ] = 'https://academia.test/uploads/destacada.jpg';
			$this->assertSame( 'https://academia.test/uploads/destacada.jpg', $this->resolve( 'https://drive.google.com/file/d/abc/view' ) );
			$this->assertSame( 'https://academia.test/uploads/destacada.jpg', $this->resolve( 'https://academia.test/videos/clase.mp4' ) );
		}

		public function test_5_course_cover_is_the_last_resort_and_never_empty_when_present(): void {
			$this->assertSame( self::COVER, $this->resolve( 'https://academia.test/videos/clase.mp4' ) );
			$this->assertSame( self::COVER, $this->resolve( '' ) );
			$GLOBALS['__vt']['featured'][ self::COURSE ] = 'https://academia.test/uploads/destacada-curso.jpg';
			$this->assertSame( 'https://academia.test/uploads/destacada-curso.jpg', $this->resolve( '', '' ) );
		}
	}
}
