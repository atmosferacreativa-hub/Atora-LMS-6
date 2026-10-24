<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ScormZipExtractorHtaccessTest extends TestCase {
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/scorm/class-scorm-zip-extractor.php';
	}

	/** @test */
	public function test_htaccess_is_written_with_correct_filesmatch_and_legacy_is_rewritten(): void {
		$zip_path = $this->make_zip( array(
			'imsmanifest.xml' => '<manifest />',
			'index.html' => '<html></html>',
		) );
		$dest = $this->tmp_dir();

		$legacy = "<FilesMatch \\\"\\.(php|phtml|phar|cgi|pl|py|rb|sh)\\$\\\">\nRequire all denied\n</FilesMatch>\nOptions -Indexes\n";
		file_put_contents( $dest . '/.htaccess', $legacy );

		$result = CLMS_SCORM_Zip_Extractor::extract( $zip_path, $dest );
		$this->assertFalse( is_wp_error( $result ) );

		$htaccess = (string) file_get_contents( $dest . '/.htaccess' );
		$this->assertSame( CLMS_SCORM_Zip_Extractor::expected_htaccess_contents(), $htaccess );
	}

	private function tmp_dir(): string {
		$dir = sys_get_temp_dir() . '/atora_scorm_test_' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir, 0777, true );
		return $dir;
	}

	/** @param array<string,string> $files */
	private function make_zip( array $files ): string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive no disponible.' );
		}
		$zip_path = sys_get_temp_dir() . '/atora_scorm_' . bin2hex( random_bytes( 6 ) ) . '.zip';
		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		foreach ( $files as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();
		return $zip_path;
	}
}

