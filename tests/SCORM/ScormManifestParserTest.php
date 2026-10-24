<?php

use PHPUnit\Framework\TestCase;

final class ScormManifestParserTest extends TestCase {
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/scorm/class-scorm-manifest-parser.php';
	}

	public function test_parses_scorm_12_manifest_and_resolves_launch_href(): void {
		$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="com.example.scorm12" xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2"
  xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2" version="1.1">
  <metadata>
    <schema>ADL SCORM</schema>
    <schemaversion>1.2</schemaversion>
  </metadata>
  <organizations default="ORG1">
    <organization identifier="ORG1">
      <item identifier="ITEM1" identifierref="RES1">
        <title>Lesson</title>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="RES1" type="webcontent" adlcp:scormtype="sco" href="index.html" />
  </resources>
</manifest>
XML;

		$parsed = CLMS_SCORM_Manifest_Parser::parse( $xml );
		$this->assertFalse( is_wp_error( $parsed ) );
		$this->assertSame( '1.2', $parsed['scorm_version'] );
		$this->assertSame( 'index.html', $parsed['launch_href'] );
	}

	public function test_parses_scorm_2004_manifest_and_resolves_launch_href(): void {
		$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="com.example.scorm2004" xmlns="http://www.imsglobal.org/xsd/imscp_v1p1"
  xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_v1p3" version="1.3">
  <metadata>
    <schema>ADL SCORM</schema>
    <schemaversion>2004 4th Edition</schemaversion>
  </metadata>
  <organizations default="ORG1">
    <organization identifier="ORG1">
      <item identifier="ITEM1" identifierref="RES1">
        <title>Lesson</title>
      </item>
    </organization>
  </organizations>
  <resources>
    <resource identifier="RES1" type="webcontent" adlcp:scormType="sco" href="story/index.html" />
  </resources>
</manifest>
XML;

		$parsed = CLMS_SCORM_Manifest_Parser::parse( $xml );
		$this->assertFalse( is_wp_error( $parsed ) );
		$this->assertSame( '2004', $parsed['scorm_version'] );
		$this->assertSame( 'story/index.html', $parsed['launch_href'] );
	}

	public function test_rejects_manifests_without_sco_resource(): void {
		$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<manifest identifier="com.example.invalid" xmlns="http://www.imsproject.org/xsd/imscp_rootv1p1p2"
  xmlns:adlcp="http://www.adlnet.org/xsd/adlcp_rootv1p2" version="1.1">
  <metadata>
    <schema>ADL SCORM</schema>
    <schemaversion>1.2</schemaversion>
  </metadata>
  <resources>
    <resource identifier="RES1" type="webcontent" adlcp:scormtype="asset" href="index.html" />
  </resources>
</manifest>
XML;

		$parsed = CLMS_SCORM_Manifest_Parser::parse( $xml );
		$this->assertTrue( is_wp_error( $parsed ) );
		$this->assertSame( 'clms_scorm_manifest_missing_sco', $parsed->get_error_code() );
	}
}

