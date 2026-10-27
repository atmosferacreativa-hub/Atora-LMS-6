<?php
/**
 * 6.37.0 — partes puras de ATORA_Lesson_Audio.
 *
 * @package ATORA_LMS\Tests\Media
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Media;

use PHPUnit\Framework\TestCase;

final class LessonAudioUnitTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/../../includes/media/class-lesson-audio.php';
	}

	public function test_extension_ignores_query_and_case(): void {
		$this->assertSame( 'mp3', \ATORA_Lesson_Audio::extension_of( 'https://x.test/a/Clase.MP3?ver=2' ) );
		$this->assertSame( 'm4a', \ATORA_Lesson_Audio::extension_of( '/var/uploads/2026/10/voz.m4a' ) );
		$this->assertSame( '', \ATORA_Lesson_Audio::extension_of( 'https://x.test/sin-extension' ) );
	}

	public function test_wav_is_not_an_accepted_format(): void {
		$this->assertArrayNotHasKey( 'wav', \ATORA_Lesson_Audio::FORMATS );
		$this->assertSame( array( 'mp3', 'm4a', 'ogg' ), array_keys( \ATORA_Lesson_Audio::FORMATS ) );
	}

	public function test_compatibility_marks_ogg_as_limited(): void {
		$this->assertSame( 'broad', \ATORA_Lesson_Audio::compatibility( 'm4a' )['status'] );
		$this->assertSame( 'limited', \ATORA_Lesson_Audio::compatibility( 'ogg' )['status'] );
		$this->assertSame( 'unknown', \ATORA_Lesson_Audio::compatibility( 'flac' )['status'] );
	}

	public function test_format_duration(): void {
		$this->assertSame( '0:03', \ATORA_Lesson_Audio::format_duration( 3 ) );
		$this->assertSame( '12:05', \ATORA_Lesson_Audio::format_duration( 725 ) );
		$this->assertSame( '1:00:00', \ATORA_Lesson_Audio::format_duration( 3600 ) );
	}

	public function test_position_key(): void {
		$this->assertSame( 'audio-42', \ATORA_Lesson_Audio::position_key( 42 ) );
	}
}
