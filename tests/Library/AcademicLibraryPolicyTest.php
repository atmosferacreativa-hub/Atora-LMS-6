<?php

use PHPUnit\Framework\TestCase;

final class AcademicLibraryPolicyTest extends TestCase {
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/library/class-academic-library-policy.php';
	}

	public function test_editorial_lifecycle_prevents_direct_publish(): void {
		$this->assertTrue( CLMS_Academic_Library_Policy::can_transition( 'draft', 'review' ) );
		$this->assertTrue( CLMS_Academic_Library_Policy::can_transition( 'review', 'published' ) );
		$this->assertFalse( CLMS_Academic_Library_Policy::can_transition( 'draft', 'published' ) );
		$this->assertFalse( CLMS_Academic_Library_Policy::can_transition( 'archived', 'draft' ) );
	}

	public function test_resource_types_are_allowlisted(): void {
		$this->assertSame( 'video', CLMS_Academic_Library_Policy::normalize_resource_type( 'video' ) );
		$this->assertSame( 'document', CLMS_Academic_Library_Policy::normalize_resource_type( 'executable' ) );
	}

	public function test_hash_is_canonical_and_sensitive_to_content(): void {
		$left = array( 'content_url' => 'https://example.test/a.pdf', 'mime_type' => 'application/pdf', 'metadata' => array( 'author' => 'A', 'year' => 2026 ) );
		$right = array( 'metadata' => array( 'year' => 2026, 'author' => 'A' ), 'mime_type' => 'application/pdf', 'content_url' => 'https://example.test/a.pdf' );
		$this->assertSame( CLMS_Academic_Library_Policy::canonical_hash( $left ), CLMS_Academic_Library_Policy::canonical_hash( $right ) );
		$right['content_url'] = 'https://example.test/b.pdf';
		$this->assertNotSame( CLMS_Academic_Library_Policy::canonical_hash( $left ), CLMS_Academic_Library_Policy::canonical_hash( $right ) );
	}
}
