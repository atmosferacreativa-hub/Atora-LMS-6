<?php

use PHPUnit\Framework\TestCase;

final class ScormZipExtractorSecurityTest extends TestCase {
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/scorm/class-scorm-zip-extractor.php';
	}

	public function test_rejects_zip_slip_paths(): void {
		$zip_path = $this->make_zip( array(
			'imsmanifest.xml' => '<manifest />',
			'../evil.txt'     => 'nope',
		) );
		$dest = $this->tmp_dir();
		$result = CLMS_SCORM_Zip_Extractor::extract( $zip_path, $dest );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'clms_scorm_zip_unsafe_path', $result->get_error_code() );
	}

	public function test_rejects_php_files_inside_zip(): void {
		$zip_path = $this->make_zip( array(
			'imsmanifest.xml' => '<manifest />',
			'index.php'       => '<?php echo "pwn";',
		) );
		$dest = $this->tmp_dir();
		$result = CLMS_SCORM_Zip_Extractor::extract( $zip_path, $dest );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'clms_scorm_zip_disallowed_extension', $result->get_error_code() );
	}

	public function test_rejects_high_compression_ratio_zip_bomb(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive no disponible.' );
		}
		$tmp_zip = tempnam( sys_get_temp_dir(), 'scorm_zip_' );
		$zip_path = is_string( $tmp_zip ) ? $tmp_zip . '.zip' : sys_get_temp_dir() . '/scorm.zip';
		@unlink( $zip_path );
		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'imsmanifest.xml', '<manifest />' );
		$zip->addFromString( 'big.txt', str_repeat( 'A', 5 * 1024 * 1024 ) );
		$zip->close();

		$dest = $this->tmp_dir();
		$result = CLMS_SCORM_Zip_Extractor::extract( $zip_path, $dest, array( 'max_compression_ratio' => 5 ) );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'clms_scorm_zip_suspected_bomb', $result->get_error_code() );
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

