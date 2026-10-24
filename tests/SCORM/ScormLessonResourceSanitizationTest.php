<?php

use PHPUnit\Framework\TestCase;

final class ScormLessonResourceSanitizationTest extends TestCase {
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/metabox-lesson/trait-metabox-lesson-save.php';
	}

	public function test_scorm_resource_keeps_library_item_and_version_ids(): void {
		$sut = new class {
			use CLMS_Metabox_Lesson_Save_Trait;
			public function sanitize( $raw, int $lesson_id ) { return $this->sanitize_resources( $raw, $lesson_id ); }
		};

		$raw = array(
			array(
				'title'              => 'Paquete SCORM',
				'type'               => 'scorm',
				'library_item_id'    => 123,
				'library_version_id' => 0,
			),
		);
		$clean = $sut->sanitize( $raw, 55 );
		$this->assertCount( 1, $clean );
		$this->assertSame( 'scorm', $clean[0]['type'] );
		$this->assertSame( 123, $clean[0]['library_item_id'] );
		$this->assertSame( 0, $clean[0]['library_version_id'] );
		$this->assertSame( '/?atora_scorm=1&lesson_id=55&library_item_id=123&library_version_id=0', $clean[0]['url'] );
	}

	public function test_scorm_resource_is_dropped_when_missing_library_item_id(): void {
		$sut = new class {
			use CLMS_Metabox_Lesson_Save_Trait;
			public function sanitize( $raw, int $lesson_id ) { return $this->sanitize_resources( $raw, $lesson_id ); }
		};

		$raw = array(
			array(
				'title'              => 'Paquete SCORM',
				'type'               => 'scorm',
				'library_item_id'    => 0,
				'library_version_id' => 0,
			),
		);
		$clean = $sut->sanitize( $raw, 55 );
		$this->assertCount( 0, $clean );
	}
}

