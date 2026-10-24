<?php

use PHPUnit\Framework\TestCase;

final class ScormFixturePackagesTest extends TestCase {
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/scorm/class-scorm-manifest-parser.php';
	}

	/** @test */
	public function test_fixture_packages_parse_and_contain_multiple_scos(): void {
		$base = dirname( __DIR__ ) . '/fixtures/scorm/';
		$scorm12 = $base . 'RuntimeMinimumCalls_SCORM12.zip';
		$scorm2004 = $base . 'RuntimeBasicCalls_SCORM20043rdEdition.zip';
		$this->assertFileExists( $scorm12 );
		$this->assertFileExists( $scorm2004 );

		$xml12 = $this->read_manifest_from_zip( $scorm12 );
		$parsed12 = CLMS_SCORM_Manifest_Parser::parse( $xml12 );
		$this->assertFalse( is_wp_error( $parsed12 ) );
		$this->assertSame( '1.2', $parsed12['scorm_version'] );
		$this->assertGreaterThanOrEqual( 2, count( $parsed12['sco_hrefs'] ) );

		$xml2004 = $this->read_manifest_from_zip( $scorm2004 );
		$parsed2004 = CLMS_SCORM_Manifest_Parser::parse( $xml2004 );
		$this->assertFalse( is_wp_error( $parsed2004 ) );
		$this->assertSame( '2004', $parsed2004['scorm_version'] );
		$this->assertGreaterThanOrEqual( 1, count( $parsed2004['sco_hrefs'] ) );
	}

	private function read_manifest_from_zip( string $zip_path ): string {
		$zip = new ZipArchive();
		$ok = $zip->open( $zip_path );
		$this->assertTrue( true === $ok, 'No se pudo abrir ZIP fixture: ' . $zip_path );
		$xml = $zip->getFromName( 'imsmanifest.xml' );
		$zip->close();
		$this->assertIsString( $xml );
		$this->assertNotSame( '', trim( (string) $xml ) );
		return (string) $xml;
	}
}

