<?php
/**
 * Audio como recurso de la lección (6.37.0).
 *
 * - Formatos aceptados: MP3, M4A y OGG (WAV no). Se validan extensión, MIME real del archivo,
 *   tamaño (100 MB por defecto, opción `atora_audio_max_mb`) y que sea un adjunto.
 * - **Aceptar no es garantizar la reproducción**: depende del códec y del dispositivo. La matriz de
 *   compatibilidad está en `docs/AUDIO.md`; `compatibility()` la expone a la web y a la app.
 * - Duración y tamaño visibles antes de descargar; transcripción o texto alternativo manual.
 * - Posición: la app la guarda con la cola de posiciones existente (clave `audio-{adjunto}`).
 *
 * @package ATORA_LMS
 * @since 6.37.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Lesson_Audio {

	/** Extensión => MIME aceptados (el MIME real lo decide WordPress al leer el archivo). */
	const FORMATS = array(
		'mp3' => array( 'audio/mpeg', 'audio/mp3', 'audio/mpeg3' ),
		'm4a' => array( 'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/aac' ),
		'ogg' => array( 'audio/ogg', 'audio/vorbis', 'audio/opus', 'application/ogg' ),
	);

	const DEFAULT_MAX_MB      = 100;
	const MAX_TRANSCRIPT_CHARS = 60000;

	/** Aviso en el editor cuando un audio no se acepta al guardar la lección. */
	public static function boot(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_errors' ) );
	}

	public static function queue_admin_error( string $message ): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$errors   = (array) get_transient( 'atora_audio_errors_' . $user_id );
		$errors[] = $message;
		set_transient( 'atora_audio_errors_' . $user_id, array_values( array_filter( $errors ) ), 120 );
	}

	public static function render_admin_errors(): void {
		$user_id = get_current_user_id();
		$errors  = $user_id ? (array) get_transient( 'atora_audio_errors_' . $user_id ) : array();
		$errors  = array_values( array_filter( array_map( 'strval', $errors ) ) );
		if ( empty( $errors ) ) {
			return;
		}
		delete_transient( 'atora_audio_errors_' . $user_id );
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Audio no aceptado (se guardó la lección sin ese archivo):', 'atora-lms' ) . '</strong></p><ul>';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	public static function max_bytes(): int {
		$mb = (int) get_option( 'atora_audio_max_mb', self::DEFAULT_MAX_MB );
		$mb = (int) apply_filters( 'atora_audio_max_mb', $mb );
		return ( $mb > 0 ? $mb : self::DEFAULT_MAX_MB ) * 1024 * 1024;
	}

	public static function extension_of( string $name ): string {
		$path = (string) strtok( $name, '?#' );
		return preg_match( '/\.([a-z0-9]{2,5})$/i', $path, $m ) ? strtolower( $m[1] ) : '';
	}

	/**
	 * Valida un adjunto de audio para la lección.
	 *
	 * @return array{ext:string,mime:string,bytes:?int,duration_seconds:?int}|WP_Error
	 */
	public static function validate_attachment( int $attachment_id ) {
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'atora_audio_not_attachment', __( 'El audio debe ser un archivo de la biblioteca de medios.', 'atora-lms' ) );
		}
		$path = (string) get_attached_file( $attachment_id );
		$name = '' !== $path ? $path : (string) wp_get_attachment_url( $attachment_id );
		$ext  = self::extension_of( $name );
		if ( ! isset( self::FORMATS[ $ext ] ) ) {
			return new WP_Error( 'atora_audio_format', __( 'Formato de audio no admitido. Usa MP3, M4A u OGG.', 'atora-lms' ) );
		}

		// MIME real del contenido (finfo). WordPress no lo comprueba para audio y además registra `.m4a`
		// como `audio/mpeg`; sin finfo se usa el MIME del adjunto.
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( '' !== $path && is_readable( $path ) && function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$real  = $finfo ? (string) finfo_file( $finfo, $path ) : '';
			if ( $finfo ) {
				finfo_close( $finfo );
			}
			if ( '' !== $real ) {
				$mime = $real;
			}
		}
		if ( ! in_array( strtolower( $mime ), self::FORMATS[ $ext ], true ) ) {
			return new WP_Error( 'atora_audio_mime', __( 'El contenido del archivo no corresponde a un audio MP3, M4A u OGG.', 'atora-lms' ) );
		}

		$bytes = self::bytes( $attachment_id, $path );
		if ( null !== $bytes && $bytes > self::max_bytes() ) {
			return new WP_Error(
				'atora_audio_size',
				sprintf(
					/* translators: %d: tamaño máximo en MB */
					__( 'El audio supera el tamaño máximo de %d MB.', 'atora-lms' ),
					(int) round( self::max_bytes() / 1048576 )
				)
			);
		}

		return array(
			'ext'              => $ext,
			'mime'             => strtolower( $mime ),
			'bytes'            => $bytes,
			'duration_seconds' => self::duration_seconds( $attachment_id, $path ),
		);
	}

	/** Duración en segundos según los metadatos del adjunto (WordPress los lee al subir). */
	public static function duration_seconds( int $attachment_id, string $path = '' ): ?int {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && isset( $meta['length'] ) && is_numeric( $meta['length'] ) && (float) $meta['length'] > 0 ) {
			return (int) round( (float) $meta['length'] );
		}
		$path = '' !== $path ? $path : (string) get_attached_file( $attachment_id );
		if ( '' !== $path && is_readable( $path ) ) {
			if ( ! function_exists( 'wp_read_audio_metadata' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/media.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
			}
			if ( function_exists( 'wp_read_audio_metadata' ) ) {
				$read = wp_read_audio_metadata( $path );
				if ( is_array( $read ) && isset( $read['length'] ) && is_numeric( $read['length'] ) && (float) $read['length'] > 0 ) {
					return (int) round( (float) $read['length'] );
				}
			}
		}
		return null;
	}

	/**
	 * Compatibilidad declarada por formato (matriz de docs/AUDIO.md). No es una prueba de reproducción.
	 *
	 * @return array{status:string,note:string}
	 */
	public static function compatibility( string $ext ): array {
		switch ( $ext ) {
			case 'mp3':
			case 'm4a':
				return array( 'status' => 'broad', 'note' => '' );
			case 'ogg':
				return array(
					'status' => 'limited',
					'note'   => __( 'Formato OGG: puede no reproducirse en iPhone y iPad ni en Safari. Para todos los dispositivos, súbelo en MP3 o M4A.', 'atora-lms' ),
				);
		}
		return array( 'status' => 'unknown', 'note' => '' );
	}

	public static function position_key( int $attachment_id ): string {
		return 'audio-' . absint( $attachment_id );
	}

	/** Claves de posición de los audios (adjuntos) de una lección. */
	public static function position_keys( int $wp_lesson_id ): array {
		$resources = $wp_lesson_id > 0 ? get_post_meta( $wp_lesson_id, '_clms_lesson_resources', true ) : array();
		$keys      = array();
		foreach ( (array) $resources as $resource ) {
			if ( is_array( $resource ) && 'audio' === ( $resource['type'] ?? '' ) && absint( $resource['file_id'] ?? 0 ) > 0 ) {
				$keys[] = self::position_key( absint( $resource['file_id'] ) );
			}
		}
		return array_values( array_unique( $keys ) );
	}

	public static function sanitize_transcript( $value ): string {
		$text = sanitize_textarea_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_TRANSCRIPT_CHARS ) : substr( $text, 0, self::MAX_TRANSCRIPT_CHARS );
	}

	/**
	 * Datos de un recurso de audio para la API móvil y la web.
	 *
	 * @param array<string,mixed> $resource Recurso guardado en `_clms_lesson_resources`.
	 * @return array<string,mixed>
	 */
	public static function describe( array $resource ): array {
		$file_id = absint( $resource['file_id'] ?? 0 );
		$out     = array(
			'transcript'       => self::sanitize_transcript( $resource['transcript'] ?? '' ),
			'duration_seconds' => null,
			'audio_format'     => '',
			'compatibility'    => array( 'status' => 'unknown', 'note' => '' ),
			'position_key'     => $file_id > 0 ? self::position_key( $file_id ) : '',
		);
		if ( $file_id > 0 ) {
			$valid = self::validate_attachment( $file_id );
			if ( ! is_wp_error( $valid ) ) {
				$out['duration_seconds'] = $valid['duration_seconds'];
				$out['audio_format']     = $valid['ext'];
				$out['compatibility']    = self::compatibility( $valid['ext'] );
			}
		} else {
			$ext = self::extension_of( (string) ( $resource['url'] ?? '' ) );
			if ( isset( self::FORMATS[ $ext ] ) ) {
				$out['audio_format']  = $ext;
				$out['compatibility'] = self::compatibility( $ext );
			}
		}
		return $out;
	}

	/**
	 * Reproductor web: controles nativos accesibles, velocidad, posición guardada en el navegador,
	 * tamaño y duración visibles, aviso de compatibilidad y transcripción.
	 *
	 * @param array<string,mixed> $resource
	 */
	public static function render_web_player( array $resource, string $src, int $lesson_id ): void {
		$info     = self::describe( $resource );
		$file_id  = absint( $resource['file_id'] ?? 0 );
		$bytes    = $file_id > 0 ? self::bytes( $file_id, (string) get_attached_file( $file_id ) ) : null;
		$key      = '' !== $info['position_key'] ? $info['position_key'] : 'audio-' . md5( $src );
		$storage  = 'atora_audio_pos_' . get_current_user_id() . '_' . $lesson_id . '_' . $key;
		$meta     = array();
		if ( null !== $info['duration_seconds'] ) {
			$meta[] = self::format_duration( (int) $info['duration_seconds'] );
		}
		if ( null !== $bytes ) {
			$meta[] = size_format( $bytes, 1 );
		}
		if ( '' !== $info['audio_format'] ) {
			$meta[] = strtoupper( $info['audio_format'] );
		}
		$title = (string) ( $resource['title'] ?? '' );
		?>
		<div class="atora-audio" data-atora-audio data-storage-key="<?php echo esc_attr( $storage ); ?>">
			<?php if ( $meta ) : ?>
				<p class="atora-audio-meta"><?php echo esc_html( implode( ' · ', $meta ) ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $info['compatibility']['note'] ) : ?>
				<p class="atora-audio-note" role="note"><?php echo esc_html( $info['compatibility']['note'] ); ?></p>
			<?php endif; ?>
			<audio controls preload="metadata" src="<?php echo esc_url( $src ); ?>" aria-label="<?php echo esc_attr( $title ); ?>">
				<a href="<?php echo esc_url( $src ); ?>"><?php esc_html_e( 'Descargar el audio', 'atora-lms' ); ?></a>
			</audio>
			<div class="atora-audio-controls">
				<label><?php esc_html_e( 'Velocidad', 'atora-lms' ); ?>
					<select data-atora-audio-rate>
						<?php foreach ( array( '0.75', '1', '1.25', '1.5', '2' ) as $rate ) : ?>
							<option value="<?php echo esc_attr( $rate ); ?>"<?php selected( $rate, '1' ); ?>><?php echo esc_html( $rate . '×' ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="button" class="atora-audio-skip" data-atora-audio-skip="-15" aria-label="<?php esc_attr_e( 'Retroceder 15 segundos', 'atora-lms' ); ?>">−15 s</button>
				<button type="button" class="atora-audio-skip" data-atora-audio-skip="15" aria-label="<?php esc_attr_e( 'Avanzar 15 segundos', 'atora-lms' ); ?>">+15 s</button>
				<a class="atora-resource-link" href="<?php echo esc_url( $src ); ?>" download><?php esc_html_e( 'Descargar', 'atora-lms' ); ?></a>
			</div>
			<?php if ( '' !== $info['transcript'] ) : ?>
				<details class="atora-audio-transcript">
					<summary><?php esc_html_e( 'Transcripción', 'atora-lms' ); ?></summary>
					<div><?php echo wp_kses_post( wpautop( esc_html( $info['transcript'] ) ) ); ?></div>
				</details>
			<?php endif; ?>
		</div>
		<?php
		self::print_web_script_once();
	}

	public static function format_duration( int $seconds ): string {
		$seconds = max( 0, $seconds );
		$h = intdiv( $seconds, 3600 );
		$m = intdiv( $seconds % 3600, 60 );
		$s = $seconds % 60;
		return $h > 0 ? sprintf( '%d:%02d:%02d', $h, $m, $s ) : sprintf( '%d:%02d', $m, $s );
	}

	private static function print_web_script_once(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script>
		(function(){
			function store(){ try { return window.localStorage; } catch (e) { return null; } }
			function init(){ document.querySelectorAll('[data-atora-audio]').forEach(function(box){
				if (box.dataset.ready) { return; }
				box.dataset.ready = '1';
				var audio = box.querySelector('audio'), key = box.getAttribute('data-storage-key'), s = store();
				if (!audio) { return; }
				audio.addEventListener('loadedmetadata', function(){
					var saved = s ? parseFloat(s.getItem(key) || '0') : 0;
					if (saved > 0 && (!audio.duration || saved < audio.duration - 2)) { audio.currentTime = saved; }
				}, { once: true });
				var last = 0;
				audio.addEventListener('timeupdate', function(){
					var now = Date.now();
					if (s && now - last > 5000) { last = now; try { s.setItem(key, String(Math.floor(audio.currentTime))); } catch (e) {} }
				});
				audio.addEventListener('pause', function(){ if (s) { try { s.setItem(key, String(Math.floor(audio.currentTime))); } catch (e) {} } });
				audio.addEventListener('ended', function(){ if (s) { try { s.removeItem(key); } catch (e) {} } });
				var rate = box.querySelector('[data-atora-audio-rate]');
				if (rate) { rate.addEventListener('change', function(){ audio.playbackRate = parseFloat(rate.value) || 1; }); }
				box.querySelectorAll('[data-atora-audio-skip]').forEach(function(btn){
					btn.addEventListener('click', function(){
						var d = parseFloat(btn.getAttribute('data-atora-audio-skip')) || 0;
						audio.currentTime = Math.max(0, Math.min((audio.duration || Infinity), audio.currentTime + d));
					});
				});
			}); }
			if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
		})();
		</script>
		<?php
	}

	private static function bytes( int $attachment_id, string $path ): ?int {
		if ( '' !== $path && is_readable( $path ) ) {
			return (int) filesize( $path );
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		return is_array( $meta ) && isset( $meta['filesize'] ) ? absint( $meta['filesize'] ) : null;
	}
}
