<?php

declare( strict_types = 1 );

namespace {
	if ( ! function_exists( 'home_url' ) ) {
		function home_url( string $path = '' ): string { return 'https://academia.test/' . ltrim( $path, '/' ); }
	}
	if ( ! function_exists( 'attachment_url_to_postid' ) ) {
		function attachment_url_to_postid( $url ) { return $GLOBALS['__dl']['by_url'][ $url ] ?? 0; }
	}
	if ( ! function_exists( 'get_attached_file' ) ) {
		function get_attached_file( $id ) { return $GLOBALS['__dl']['paths'][ (int) $id ] ?? false; }
	}
	if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
		function wp_get_attachment_metadata( $id ) { return $GLOBALS['__dl']['meta'][ (int) $id ] ?? false; }
	}
	if ( ! function_exists( 'get_post_mime_type' ) ) {
		function get_post_mime_type( $id = null ) { return $GLOBALS['__dl']['mime'][ (int) $id ] ?? false; }
	}
}

namespace ATORA\Tests\Media {

	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/../../includes/media/class-download-info.php';

	final class DownloadInfoTest extends TestCase {
		protected function setUp(): void {
			parent::setUp();
			$GLOBALS['__dl'] = array( 'by_url' => array(), 'paths' => array(), 'meta' => array(), 'mime' => array() );
			atora_test_reset_posts();
		}

		public function test_external_links_are_not_downloadable(): void {
			foreach ( array(
				'https://drive.google.com/file/d/abc/view',
				'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
				'https://vimeo.com/76979871',
				'https://otro-sitio.org/guia.pdf',
			) as $url ) {
				$this->assertFalse( \ATORA_Download_Info::for_resource( 0, $url )['downloadable'], $url );
			}
		}

		public function test_library_attachment_reports_size_and_date(): void {
			$file = tempnam( sys_get_temp_dir(), 'dl' );
			file_put_contents( $file, str_repeat( 'x', 2048 ) );
			$GLOBALS['__dl']['paths'][42] = $file;
			atora_test_set_post( 42, array( 'post_modified_gmt' => '2026-09-30 12:00:00' ) );

			$info = \ATORA_Download_Info::for_resource( 42, 'https://cdn.example.com/uploads/guia.pdf' );
			unlink( $file );

			$this->assertSame( array( 'downloadable' => true, 'bytes' => 2048, 'updated_at' => '2026-09-30T12:00:00Z' ), $info );
		}

		public function test_same_domain_file_url_is_downloadable_but_pages_are_not(): void {
			$this->assertTrue( \ATORA_Download_Info::for_resource( 0, 'https://www.academia.test/wp-content/uploads/plan.docx' )['downloadable'] );
			$this->assertFalse( \ATORA_Download_Info::for_resource( 0, 'https://academia.test/curso/intro/' )['downloadable'] );
		}

		public function test_only_local_mp4_video_is_downloadable(): void {
			$GLOBALS['__dl']['by_url']['https://academia.test/wp-content/uploads/clase.mp4'] = 9;
			$GLOBALS['__dl']['meta'][9] = array( 'filesize' => 5000000 );

			$this->assertSame( array( 'video_downloadable' => true, 'video_bytes' => 5000000 ), \ATORA_Download_Info::for_video( 'https://academia.test/wp-content/uploads/clase.mp4' ) );
			$this->assertFalse( \ATORA_Download_Info::for_video( 'https://otro.org/clase.mp4' )['video_downloadable'] );
			$this->assertFalse( \ATORA_Download_Info::for_video( 'https://academia.test/wp-content/uploads/clase.webm' )['video_downloadable'] );
			$this->assertFalse( \ATORA_Download_Info::for_video( '' )['video_downloadable'] );
		}
	}
}
