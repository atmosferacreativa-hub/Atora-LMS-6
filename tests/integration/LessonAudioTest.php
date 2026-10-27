<?php
/**
 * Integración 6.37.0: audio como recurso de la lección.
 * MP3, M4A y OGG se aceptan (con duración y tamaño); WAV, un archivo disfrazado y uno demasiado
 * grande no. La API móvil expone duración, formato, compatibilidad, transcripción y posición.
 */

declare( strict_types = 1 );

final class LessonAudioTest extends WP_UnitTestCase {

	private function upload( string $name ): int {
		$source = dirname( __DIR__ ) . '/fixtures/audio/' . $name;
		$copy   = get_temp_dir() . wp_unique_filename( get_temp_dir(), $name );
		copy( $source, $copy );
		return $this->attach( $copy );
	}

	private function attach( string $path ): int {
		$id = self::factory()->attachment->create_upload_object( $path );
		return is_int( $id ) ? $id : 0;
	}

	public function test_accepts_mp3_m4a_ogg_with_duration_and_size(): void {
		foreach ( array( 'tono.mp3' => 'mp3', 'tono.m4a' => 'm4a', 'tono.ogg' => 'ogg' ) as $file => $ext ) {
			$id    = $this->upload( $file );
			$valid = ATORA_Lesson_Audio::validate_attachment( $id );
			$this->assertIsArray( $valid, $file . ': ' . ( is_wp_error( $valid ) ? $valid->get_error_message() : '' ) );
			$this->assertSame( $ext, $valid['ext'] );
			$this->assertGreaterThan( 0, (int) $valid['bytes'] );
			if ( null !== $valid['duration_seconds'] ) {
				$this->assertSame( 3, $valid['duration_seconds'], $file . ': duración del tono de prueba.' );
			}
		}
	}

	public function test_rejects_wav_disguised_and_oversized(): void {
		$wav = ATORA_Lesson_Audio::validate_attachment( $this->upload( 'tono.wav' ) );
		$this->assertWPError( $wav );
		$this->assertSame( 'atora_audio_format', $wav->get_error_code() );

		// Un texto con extensión .mp3: el contenido no es audio.
		$fake = get_temp_dir() . 'falso-' . wp_generate_password( 6, false ) . '.mp3';
		file_put_contents( $fake, str_repeat( 'no es audio ', 200 ) );
		$fake_id = $this->attach( $fake );
		if ( $fake_id > 0 ) {
			$this->assertWPError( ATORA_Lesson_Audio::validate_attachment( $fake_id ) );
		}

		add_filter( 'atora_audio_max_mb', static fn() => 1 );
		$big = get_temp_dir() . 'grande-' . wp_generate_password( 6, false ) . '.mp3';
		$mp3 = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/audio/tono.mp3' );
		file_put_contents( $big, str_repeat( $mp3, (int) ceil( 1100000 / strlen( $mp3 ) ) ) );
		$big_id = $this->attach( $big );
		$this->assertGreaterThan( 0, $big_id );
		$result = ATORA_Lesson_Audio::validate_attachment( $big_id );
		$this->assertWPError( $result );
		$this->assertSame( 'atora_audio_size', $result->get_error_code() );
		remove_all_filters( 'atora_audio_max_mb' );
	}

	public function test_lesson_save_drops_rejected_audio_and_keeps_transcript(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$mp3 = $this->upload( 'tono.mp3' );
		$wav = $this->upload( 'tono.wav' );

		$metabox = new CLMS_Metabox_Lesson();
		$method  = new ReflectionMethod( $metabox, 'sanitize_resources' );
		$method->setAccessible( true );
		$clean = $method->invoke( $metabox, array(
			array( 'type' => 'audio', 'title' => 'Bueno', 'file_id' => $mp3, 'transcript' => "Línea 1\nLínea 2" ),
			array( 'type' => 'audio', 'title' => 'WAV', 'file_id' => $wav ),
		), 0 );

		$this->assertSame( $mp3, $clean[0]['file_id'] );
		$this->assertSame( "Línea 1\nLínea 2", $clean[0]['transcript'] );
		$this->assertSame( 0, $clean[1]['file_id'], 'El WAV se quita del recurso.' );
		$this->assertNotEmpty( get_transient( 'atora_audio_errors_' . $admin ), 'El editor avisa por qué.' );
	}

	public function test_compatibility_and_position_keys(): void {
		$this->assertSame( 'broad', ATORA_Lesson_Audio::compatibility( 'mp3' )['status'] );
		$this->assertSame( 'limited', ATORA_Lesson_Audio::compatibility( 'ogg' )['status'] );
		$this->assertStringContainsString( 'iPhone', ATORA_Lesson_Audio::compatibility( 'ogg' )['note'] );

		$lesson = self::factory()->post->create( array( 'post_type' => 'lm_lesson', 'post_status' => 'publish' ) );
		$mp3    = $this->upload( 'tono.mp3' );
		update_post_meta( $lesson, '_clms_lesson_resources', array(
			array( 'type' => 'audio', 'title' => 'A', 'file_id' => $mp3 ),
			array( 'type' => 'pdf', 'title' => 'B', 'file_id' => 99 ),
		) );
		$this->assertSame( array( 'audio-' . $mp3 ), ATORA_Lesson_Audio::position_keys( $lesson ) );

		$described = ATORA_Lesson_Audio::describe( array( 'type' => 'audio', 'file_id' => $mp3, 'transcript' => 'Hola' ) );
		$this->assertSame( 'mp3', $described['audio_format'] );
		$this->assertSame( 'Hola', $described['transcript'] );
		$this->assertSame( 'audio-' . $mp3, $described['position_key'] );
	}
}
