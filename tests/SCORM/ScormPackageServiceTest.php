<?php

use PHPUnit\Framework\TestCase;

final class ScormPackageServiceTest extends TestCase {
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/scorm/class-scorm-package-service.php';
	}

	public function test_install_zip_validates_manifest_extracts_and_checks_launch_file(): void {
		$zip_path = $this->make_zip( array(
			'imsmanifest.xml' => $this->manifest_12( 'index.html' ),
			'index.html'      => '<html>OK</html>',
		) );
		$dest = $this->tmp_dir();
		$result = CLMS_SCORM_Package_Service::install_zip( $zip_path, $dest );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( '1.2', $result['scorm_version'] );
		$this->assertFileExists( $dest . '/index.html' );
	}

	public function test_install_zip_rejects_manifest_pointing_to_missing_launch_file(): void {
		$zip_path = $this->make_zip( array(
			'imsmanifest.xml' => $this->manifest_12( 'missing.html' ),
		) );
		$dest = $this->tmp_dir();
		$result = CLMS_SCORM_Package_Service::install_zip( $zip_path, $dest );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'clms_scorm_package_missing_launch', $result->get_error_code() );
	}

	/**
	 * Asegura que la defensa contra symlinks no dependa del sistema de archivos.
	 */
	public function test_rejects_zip_with_symlink_entry(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive no disponible.' );
		}
		$zip_path = sys_get_temp_dir() . '/atora_scorm_symlink_' . bin2hex( random_bytes( 6 ) ) . '.zip';
		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'imsmanifest.xml', $this->manifest_12( 'index.html' ) );
		$zip->addFromString( 'index.html', '<html></html>' );
		$zip->addFromString( 'link', 'target' );
		if ( method_exists( $zip, 'setExternalAttributesName' ) && defined( 'ZipArchive::OPSYS_UNIX' ) ) {
			// Marcar como symlink (0120000) en atributos UNIX.
			$zip->setExternalAttributesName( 'link', ZipArchive::OPSYS_UNIX, ( 0120777 << 16 ) );
		} else {
			$zip->close();
			$this->markTestSkipped( 'ZipArchive no soporta setExternalAttributesName().' );
		}
		$zip->close();

		$dest = $this->tmp_dir();
		$result = CLMS_SCORM_Package_Service::install_zip( $zip_path, $dest );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'clms_scorm_zip_symlink', $result->get_error_code() );
	}

	private function manifest_12( string $href ): string {
		$href = htmlspecialchars( $href, ENT_QUOTES );
		return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="com.example.scorm12" xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2"
  xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2" version="1.1">
  <metadata>
    <schema>ADL SCORM</schema>
    <schemaversion>1.2</schemaversion>
  </metadata>
  <resources>
    <resource identifier="RES1" type="webcontent" adlcp:scormtype="sco" href="{$href}" />
  </resources>
</manifest>
XML;
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

