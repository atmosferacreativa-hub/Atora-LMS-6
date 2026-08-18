<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CLMS_Transcription
 *
 * Transcribe el audio/video de una lección y guarda el resultado en post meta.
 *
 * Providers:
 *   youtube_captions — Extrae captions automáticos de YouTube sin API key.
 *   whisper          — OpenAI Whisper API (requiere API key configurada en ajustes IA).
 *
 * Meta keys:
 *   _clms_transcription_text       Texto completo de la transcripción.
 *   _clms_transcription_status     not_requested | pending | processing | completed | failed
 *   _clms_transcription_provider   youtube_captions | whisper
 *   _clms_transcription_words      Conteo de palabras.
 *   _clms_transcription_lang       Idioma detectado (código ISO 639-1).
 *   _clms_transcription_updated_at Timestamp MySQL de la última actualización.
 *   _clms_transcription_error      Mensaje de error cuando status = failed.
 */
class CLMS_Transcription {

	const META_TEXT     = '_clms_transcription_text';
	const META_STATUS   = '_clms_transcription_status';
	const META_PROVIDER = '_clms_transcription_provider';
	const META_WORDS    = '_clms_transcription_words';
	const META_LANG     = '_clms_transcription_lang';
	const META_UPDATED  = '_clms_transcription_updated_at';
	const META_ERROR    = '_clms_transcription_error';

	const CRON_HOOK     = 'clms_transcription_process';

	const MAX_WHISPER_BYTES = 25165824; // 24 MB (Whisper limit: 25 MB con margen)

	// ── Bootstrap ────────────────────────────────────────────────────────────────

	public function __construct() {
		add_action( 'wp_ajax_clms_transcription_trigger', array( $this, 'ajax_trigger' ) );
		add_action( 'wp_ajax_clms_transcription_status',  array( $this, 'ajax_status' ) );
		add_action( self::CRON_HOOK, array( $this, 'process' ), 10, 1 );
	}

	// ── AJAX: lanzar transcripción ────────────────────────────────────────────

	public function ajax_trigger() {
		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( wp_unslash( $_POST['lesson_id'] ) ) : 0;
		$provider  = isset( $_POST['provider'] )  ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'auto';
		$nonce     = isset( $_POST['nonce'] )      ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! $lesson_id ) {
			wp_send_json_error( array( 'message' => __( 'Lección no válida.', 'atora-lms' ) ), 400 );
		}

		if ( ! wp_verify_nonce( $nonce, 'clms_transcription_' . $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'atora-lms' ) ), 403 );
		}

		if ( ! CLMS_Helper::user_can_manage_lms( $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'atora-lms' ) ), 403 );
		}

		if ( 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'El post no es una lección.', 'atora-lms' ) ), 400 );
		}

		// Determinar provider final
		$provider = $this->resolve_provider( $lesson_id, $provider );
		if ( is_wp_error( $provider ) ) {
			wp_send_json_error( array( 'message' => $provider->get_error_message() ), 400 );
		}

		// Guardar estado pending y provider
		update_post_meta( $lesson_id, self::META_STATUS,   'pending' );
		update_post_meta( $lesson_id, self::META_PROVIDER, $provider );
		update_post_meta( $lesson_id, self::META_ERROR,    '' );

		// Limpiar cualquier cron anterior fallido antes de reprogramar
		$scheduled = wp_next_scheduled( self::CRON_HOOK, array( $lesson_id ) );
		if ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_HOOK, array( $lesson_id ) );
		}
		wp_schedule_single_event( time(), self::CRON_HOOK, array( $lesson_id ) );

		// Disparar cron ahora mismo (no esperar al siguiente tick)
		spawn_cron();

		wp_send_json_success( array(
			'status'   => 'pending',
			'provider' => $provider,
			'message'  => __( 'Transcripción programada.', 'atora-lms' ),
		) );
	}

	// ── AJAX: consultar estado ────────────────────────────────────────────────

	public function ajax_status() {
		$lesson_id = isset( $_GET['lesson_id'] ) ? absint( wp_unslash( $_GET['lesson_id'] ) ) : 0;
		$nonce     = isset( $_GET['nonce'] )      ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

		if ( ! $lesson_id || ! wp_verify_nonce( $nonce, 'clms_transcription_' . $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Solicitud inválida.', 'atora-lms' ) ), 400 );
		}

		if ( ! CLMS_Helper::user_can_manage_lms( $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'atora-lms' ) ), 403 );
		}

		wp_send_json_success( $this->get_status_payload( $lesson_id ) );
	}

	// ── Cron: procesar transcripción ─────────────────────────────────────────

	public function process( $lesson_id ) {
		$lesson_id = absint( $lesson_id );

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return;
		}

		update_post_meta( $lesson_id, self::META_STATUS, 'processing' );

		$provider = (string) get_post_meta( $lesson_id, self::META_PROVIDER, true );

		$result = $this->run_provider( $lesson_id, $provider );

		if ( is_wp_error( $result ) ) {
			update_post_meta( $lesson_id, self::META_STATUS, 'failed' );
			update_post_meta( $lesson_id, self::META_ERROR,  $result->get_error_message() );
			update_post_meta( $lesson_id, self::META_UPDATED, current_time( 'mysql' ) );
			return;
		}

		$text  = isset( $result['text'] ) ? (string) $result['text'] : '';
		$lang  = isset( $result['lang'] ) ? sanitize_text_field( $result['lang'] ) : '';
		$words = $text ? str_word_count( $text ) : 0;

		update_post_meta( $lesson_id, self::META_TEXT,     $text );
		update_post_meta( $lesson_id, self::META_WORDS,    $words );
		update_post_meta( $lesson_id, self::META_LANG,     $lang );
		update_post_meta( $lesson_id, self::META_STATUS,   'completed' );
		update_post_meta( $lesson_id, self::META_ERROR,    '' );
		update_post_meta( $lesson_id, self::META_UPDATED,  current_time( 'mysql' ) );
	}

	// ── Resolución de provider ────────────────────────────────────────────────

	/**
	 * Decide qué provider usar según la fuente del primer video de la lección.
	 *
	 * @param int    $lesson_id
	 * @param string $requested 'auto' | 'youtube_captions' | 'whisper'
	 * @return string|WP_Error
	 */
	protected function resolve_provider( $lesson_id, $requested ) {
		$allowed = array( 'auto', 'youtube_captions', 'whisper' );
		if ( ! in_array( $requested, $allowed, true ) ) {
			$requested = 'auto';
		}

		if ( 'whisper' === $requested ) {
			if ( ! $this->whisper_is_configured() ) {
				return new WP_Error( 'whisper_not_configured', __( 'La API key de Whisper no está configurada.', 'atora-lms' ) );
			}
			return 'whisper';
		}

		if ( 'youtube_captions' === $requested ) {
			return 'youtube_captions';
		}

		// Auto: detectar por fuente del video
		$source = $this->get_lesson_video_source( $lesson_id );
		if ( 'youtube' === $source ) {
			return 'youtube_captions';
		}

		if ( $this->whisper_is_configured() ) {
			return 'whisper';
		}

		return new WP_Error(
			'no_provider',
			__( 'La fuente del video no es YouTube y Whisper no está configurado. Añade una API key de Whisper en Configuración de Atora > APIs e IA.', 'atora-lms' )
		);
	}

	/**
	 * Devuelve la fuente del primer video de la lección (youtube, vimeo, bunny, drive, url).
	 */
	protected function get_lesson_video_source( $lesson_id ) {
		$extra = get_post_meta( $lesson_id, '_clms_lesson_extra_videos', true );
		if ( is_array( $extra ) && ! empty( $extra[0]['source'] ) ) {
			return sanitize_key( $extra[0]['source'] );
		}

		$legacy = get_post_meta( $lesson_id, '_clms_lesson_video_source', true );
		return $legacy ? sanitize_key( $legacy ) : 'youtube';
	}

	/**
	 * Devuelve la URL del primer video de la lección.
	 */
	protected function get_lesson_video_url( $lesson_id ) {
		$extra = get_post_meta( $lesson_id, '_clms_lesson_extra_videos', true );
		if ( is_array( $extra ) && ! empty( $extra[0]['url'] ) ) {
			return esc_url_raw( $extra[0]['url'] );
		}

		return esc_url_raw( (string) get_post_meta( $lesson_id, '_clms_lesson_video_url', true ) );
	}

	// ── Ejecución del provider ────────────────────────────────────────────────

	protected function run_provider( $lesson_id, $provider ) {
		$url    = $this->get_lesson_video_url( $lesson_id );
		$source = $this->get_lesson_video_source( $lesson_id );

		if ( ! $url ) {
			return new WP_Error( 'no_video_url', __( 'La lección no tiene URL de video.', 'atora-lms' ) );
		}

		if ( 'youtube_captions' === $provider ) {
			return $this->transcribe_youtube( $url );
		}

		if ( 'whisper' === $provider ) {
			return $this->transcribe_whisper( $url, $source );
		}

		return new WP_Error( 'unknown_provider', sprintf( __( 'Provider desconocido: %s', 'atora-lms' ), $provider ) );
	}

	// ── Provider: YouTube Captions ────────────────────────────────────────────

	/**
	 * Extrae el texto de los captions automáticos de YouTube.
	 *
	 * Flujo:
	 *   1. Descarga la página del video.
	 *   2. Extrae `ytInitialPlayerResponse` del HTML.
	 *   3. Obtiene la URL del primer caption track.
	 *   4. Descarga el XML de captions y lo convierte a texto plano.
	 *
	 * @param string $video_url URL del video de YouTube.
	 * @return array|WP_Error
	 */
	protected function transcribe_youtube( $video_url ) {
		$video_id = $this->extract_youtube_id( $video_url );

		if ( ! $video_id ) {
			return new WP_Error( 'invalid_youtube_url', __( 'No se pudo extraer el ID del video de YouTube.', 'atora-lms' ) );
		}

		// Descarga la página del video
		$page_response = wp_remote_get(
			'https://www.youtube.com/watch?v=' . rawurlencode( $video_id ),
			array(
				'timeout'    => 30,
				'user-agent' => 'Mozilla/5.0 (compatible; ATORA-LMS/1.0)',
				'headers'    => array(
					'Accept-Language' => 'es,en;q=0.9',
				),
			)
		);

		if ( is_wp_error( $page_response ) ) {
			return $page_response;
		}

		$html = wp_remote_retrieve_body( $page_response );

		if ( empty( $html ) ) {
			return new WP_Error( 'empty_yt_page', __( 'YouTube no devolvió contenido para el video.', 'atora-lms' ) );
		}

		// Extraer ytInitialPlayerResponse JSON del HTML
		$player_data = $this->extract_yt_player_response( $html );

		if ( is_wp_error( $player_data ) ) {
			return $player_data;
		}

		// Navegar a captions
		$caption_tracks = $this->extract_caption_tracks( $player_data );

		if ( is_wp_error( $caption_tracks ) ) {
			return $caption_tracks;
		}

		// Elegir el mejor track (preferir español, luego el primero)
		$track = $this->pick_best_caption_track( $caption_tracks );

		if ( ! $track ) {
			return new WP_Error( 'no_caption_track', __( 'Este video de YouTube no tiene captions disponibles.', 'atora-lms' ) );
		}

		$caption_url  = isset( $track['baseUrl'] ) ? $track['baseUrl'] : '';
		$caption_lang = isset( $track['languageCode'] ) ? sanitize_text_field( $track['languageCode'] ) : '';

		if ( ! $caption_url ) {
			return new WP_Error( 'no_caption_url', __( 'El track de captions no tiene URL.', 'atora-lms' ) );
		}

		// Forzar formato XML para parseo más limpio
		$caption_url = add_query_arg( 'fmt', 'srv3', $caption_url );

		$caption_response = wp_remote_get(
			$caption_url,
			array(
				'timeout'    => 20,
				'user-agent' => 'Mozilla/5.0 (compatible; ATORA-LMS/1.0)',
			)
		);

		if ( is_wp_error( $caption_response ) ) {
			return $caption_response;
		}

		$caption_xml = wp_remote_retrieve_body( $caption_response );

		if ( empty( $caption_xml ) ) {
			return new WP_Error( 'empty_caption_xml', __( 'Los captions de YouTube están vacíos.', 'atora-lms' ) );
		}

		$text = $this->parse_caption_xml( $caption_xml );

		if ( '' === $text ) {
			return new WP_Error( 'empty_caption_text', __( 'No se pudo extraer texto de los captions.', 'atora-lms' ) );
		}

		return array(
			'text' => $text,
			'lang' => $caption_lang,
		);
	}

	/**
	 * Extrae el ID de un video de YouTube de su URL.
	 */
	protected function extract_youtube_id( $url ) {
		if ( preg_match( '#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([a-zA-Z0-9_-]{11})#', $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Extrae y decodifica el JSON de `ytInitialPlayerResponse` del HTML.
	 *
	 * Usa un contador de llaves en lugar de regex para manejar el JSON
	 * multilínea que YouTube genera actualmente.
	 */
	protected function extract_yt_player_response( $html ) {
		// Localizar el inicio de ytInitialPlayerResponse
		$needle = 'ytInitialPlayerResponse';
		$marker = strpos( $html, $needle );

		if ( false === $marker ) {
			return new WP_Error(
				'yt_player_response_not_found',
				__( 'No se encontró ytInitialPlayerResponse en la página de YouTube.', 'atora-lms' )
			);
		}

		// Encontrar la primera llave de apertura tras el marcador
		$brace_start = strpos( $html, '{', $marker );

		if ( false === $brace_start ) {
			return new WP_Error(
				'yt_player_response_no_brace',
				__( 'No se encontró el inicio del JSON en la respuesta de YouTube.', 'atora-lms' )
			);
		}

		// Contar llaves para extraer el JSON completo (soporta multilínea y anidado)
		$depth       = 0;
		$in_string   = false;
		$escape_next = false;
		$len         = strlen( $html );
		$end         = -1;

		for ( $i = $brace_start; $i < $len; $i++ ) {
			$char = $html[ $i ];

			if ( $escape_next ) {
				$escape_next = false;
				continue;
			}

			if ( '\\' === $char && $in_string ) {
				$escape_next = true;
				continue;
			}

			if ( '"' === $char ) {
				$in_string = ! $in_string;
				continue;
			}

			if ( $in_string ) {
				continue;
			}

			if ( '{' === $char ) {
				$depth++;
			} elseif ( '}' === $char ) {
				$depth--;
				if ( 0 === $depth ) {
					$end = $i;
					break;
				}
			}
		}

		if ( $end < 0 ) {
			return new WP_Error(
				'yt_player_json_unclosed',
				__( 'El JSON de YouTube está incompleto o es demasiado largo.', 'atora-lms' )
			);
		}

		$raw  = substr( $html, $brace_start, $end - $brace_start + 1 );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'yt_player_json_parse_error',
				__( 'No se pudo parsear ytInitialPlayerResponse (JSON inválido).', 'atora-lms' )
			);
		}

		return $data;
	}

	/**
	 * Extrae los caption tracks del player response.
	 *
	 * @param array $player_data
	 * @return array|WP_Error
	 */
	protected function extract_caption_tracks( $player_data ) {
		$tracks = isset( $player_data['captions']['playerCaptionsTracklistRenderer']['captionTracks'] )
			? $player_data['captions']['playerCaptionsTracklistRenderer']['captionTracks']
			: array();

		if ( empty( $tracks ) || ! is_array( $tracks ) ) {
			return new WP_Error(
				'no_caption_tracks',
				__( 'Este video no tiene captions activados. Para habilitar captions ve a YouTube Studio > Subtítulos.', 'atora-lms' )
			);
		}

		return $tracks;
	}

	/**
	 * Elige el mejor track: prefiere español (es/es-*), luego el primero.
	 */
	protected function pick_best_caption_track( $tracks ) {
		foreach ( $tracks as $track ) {
			$lang = isset( $track['languageCode'] ) ? $track['languageCode'] : '';
			if ( 0 === strpos( $lang, 'es' ) ) {
				return $track;
			}
		}

		// Fallback: primer track disponible
		return isset( $tracks[0] ) ? $tracks[0] : null;
	}

	/**
	 * Convierte el XML de captions a texto plano.
	 * Soporta formatos srv3 y timedtext.
	 */
	protected function parse_caption_xml( $xml ) {
		// Intentar parsear como XML
		$prev = libxml_use_internal_errors( true );
		$doc  = simplexml_load_string( $xml );
		libxml_use_internal_errors( $prev );

		if ( $doc ) {
			$lines = array();

			// Formato srv3: <timedtext><body><p>texto</p></body></timedtext>
			foreach ( $doc->body->p as $p ) {
				$text = (string) $p;
				if ( '' !== trim( $text ) ) {
					$lines[] = trim( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				}
			}

			if ( ! empty( $lines ) ) {
				return implode( ' ', $lines );
			}

			// Formato timedtext alternativo: <transcript><text>
			foreach ( $doc->text as $t ) {
				$text = (string) $t;
				if ( '' !== trim( $text ) ) {
					$lines[] = trim( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				}
			}

			if ( ! empty( $lines ) ) {
				return implode( ' ', $lines );
			}
		}

		// Fallback: strip tags y limpiar
		$text = wp_strip_all_tags( $xml );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( $text );
	}

	// ── Provider: OpenAI Whisper ──────────────────────────────────────────────

	/**
	 * Transcribe un video/audio usando la API de OpenAI Whisper.
	 *
	 * @param string $video_url URL del archivo de video o audio.
	 * @param string $source    Fuente (bunny, url, drive, vimeo…).
	 * @return array|WP_Error
	 */
	protected function transcribe_whisper( $video_url, $source ) {
		if ( ! $this->whisper_is_configured() ) {
			return new WP_Error( 'whisper_not_configured', __( 'La API key de Whisper no está configurada.', 'atora-lms' ) );
		}

		// Verificar tamaño antes de descargar (HEAD request)
		$head = wp_remote_head( $video_url, array( 'timeout' => 10 ) );
		if ( ! is_wp_error( $head ) ) {
			$content_length = (int) wp_remote_retrieve_header( $head, 'content-length' );
			if ( $content_length > 0 && $content_length > self::MAX_WHISPER_BYTES ) {
				return new WP_Error(
					'file_too_large',
					sprintf(
						'El archivo es demasiado grande para Whisper (%s). Límite: 24 MB.',
						size_format( $content_length )
					)
				);
			}
		}

		// Descargar a archivo temporal
		$temp_path = $this->download_to_temp( $video_url );

		if ( is_wp_error( $temp_path ) ) {
			return $temp_path;
		}

		// Verificar tamaño del archivo descargado
		$file_size = file_exists( $temp_path ) ? filesize( $temp_path ) : 0;
		if ( $file_size > self::MAX_WHISPER_BYTES ) {
			@unlink( $temp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error(
				'file_too_large',
				sprintf( 'El archivo descargado es demasiado grande (%s). Límite: 24 MB.', size_format( $file_size ) )
			);
		}

		if ( 0 === $file_size ) {
			@unlink( $temp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'empty_download', __( 'El archivo descargado está vacío.', 'atora-lms' ) );
		}

		// Enviar a Whisper
		$result = $this->call_whisper_api( $temp_path );

		@unlink( $temp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return $result;
	}

	/**
	 * Descarga una URL a un archivo temporal usando la API HTTP de WP con streaming.
	 *
	 * @param string $url URL del archivo.
	 * @return string|WP_Error Ruta al temp file o error.
	 */
	protected function download_to_temp( $url ) {
		$upload_dir = wp_upload_dir();
		$temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'atora-tmp/';

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );

			// Añadir .htaccess para bloquear acceso web
			$htaccess = $temp_dir . '.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				file_put_contents( $htaccess, 'Deny from all' ); // phpcs:ignore
			}
		}

		$temp_path = $temp_dir . 'whisper_' . wp_generate_password( 12, false ) . '.tmp';

		$response = wp_remote_get(
			$url,
			array(
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $temp_path,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			@unlink( $temp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'download_failed', sprintf( __( 'Error al descargar el archivo (HTTP %d).', 'atora-lms' ), $status ) );
		}

		if ( ! file_exists( $temp_path ) || 0 === filesize( $temp_path ) ) {
			return new WP_Error( 'empty_file', __( 'El archivo descargado está vacío.', 'atora-lms' ) );
		}

		return $temp_path;
	}

	/**
	 * Llama a la API de Whisper con un archivo temporal.
	 * Usa curl para el upload multipart/form-data binario.
	 *
	 * @param string $file_path Ruta al archivo temporal.
	 * @return array|WP_Error
	 */
	protected function call_whisper_api( $file_path ) {
		$api_key = $this->get_openai_api_key();

		if ( ! $api_key ) {
			return new WP_Error( 'no_api_key', __( 'API key de Whisper no configurada.', 'atora-lms' ) );
		}

		return $this->call_whisper_wp_http( $file_path, $api_key );
	}

	/**
	 * Verifica si las funciones cURL están disponibles y no están en disable_functions.
	 *
	 * @return bool
	 */
	protected function curl_available() {
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_exec' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return ! in_array( 'curl_exec', $disabled, true ) && ! in_array( 'curl_init', $disabled, true );
	}

	/**
	 * Llama a Whisper via curl.
	 */
	protected function call_whisper_curl( $file_path, $api_key ) {
		return $this->call_whisper_wp_http( $file_path, $api_key );
	}

	/**
	 * Llama a Whisper via wp_remote_post con multipart manual.
	 */
	protected function call_whisper_wp_http( $file_path, $api_key ) {
		unset( $api_key );

		$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
		if ( ! $manager || ! method_exists( $manager, 'transcribe_audio' ) ) {
			return new WP_Error( 'clms_ai_manager_missing', __( 'CLMS_AI_Manager no está disponible.', 'atora-lms' ) );
		}

		$result = $manager->transcribe_audio(
			$file_path,
			array(
				'response_format' => 'verbose_json',
				'timeout'         => 300,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'text' => $result['text'] ?? '',
			'lang' => $result['language'] ?? '',
		);
	}

	/**
	 * Parsea la respuesta JSON de Whisper.
	 */
	protected function parse_whisper_response( $raw, $http_code ) {
		$data = json_decode( $raw, true );

		if ( $http_code < 200 || $http_code >= 300 ) {
			$api_error = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Error desconocido de la API.', 'atora-lms' );
			return new WP_Error(
				'whisper_api_error',
				sprintf( __( 'OpenAI Whisper (HTTP %1$d): %2$s', 'atora-lms' ), $http_code, $api_error )
			);
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'whisper_parse_error', __( 'Respuesta de Whisper no válida.', 'atora-lms' ) );
		}

		$text = isset( $data['text'] ) ? trim( (string) $data['text'] ) : '';
		$lang = isset( $data['language'] ) ? sanitize_text_field( $data['language'] ) : '';

		if ( '' === $text ) {
			return new WP_Error( 'whisper_empty', __( 'Whisper no devolvió texto. El audio podría no tener voz inteligible.', 'atora-lms' ) );
		}

		return array(
			'text' => $text,
			'lang' => $lang,
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	protected function whisper_is_configured() {
		return '' !== trim( (string) $this->get_openai_api_key() );
	}

	protected function get_openai_api_key() {
		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_whisper_key' ) ) {
			return trim( (string) CLMS_Settings::get_whisper_key() );
		}

		$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
		if ( $manager && method_exists( $manager, 'get_whisper_api_key' ) ) {
			return trim( (string) $manager->get_whisper_api_key() );
		}

		return '';
	}

	protected function get_status_payload( $lesson_id ) {
		$lesson_id  = absint( $lesson_id );
		$status     = (string) get_post_meta( $lesson_id, self::META_STATUS, true );
		$status     = $status ? $status : 'not_requested';
		$provider   = (string) get_post_meta( $lesson_id, self::META_PROVIDER, true );
		$words      = (int) get_post_meta( $lesson_id, self::META_WORDS, true );
		$lang       = (string) get_post_meta( $lesson_id, self::META_LANG, true );
		$updated_at = (string) get_post_meta( $lesson_id, self::META_UPDATED, true );
		$error      = (string) get_post_meta( $lesson_id, self::META_ERROR, true );
		$text       = (string) get_post_meta( $lesson_id, self::META_TEXT, true );
		$preview    = $text ? wp_trim_words( $text, 80 ) : '';

		return array(
			'status'     => $status,
			'provider'   => $provider,
			'words'      => $words,
			'lang'       => $lang,
			'updated_at' => $updated_at,
			'error'      => $error,
			'preview'    => $preview,
			'has_text'   => '' !== $text,
		);
	}

	// ── Static API pública ────────────────────────────────────────────────────

	/**
	 * Devuelve el texto completo de la transcripción de una lección.
	 *
	 * @param int $lesson_id
	 * @return string Texto o string vacío si no hay transcripción.
	 */
	public static function get_text( $lesson_id ) {
		$status = (string) get_post_meta( absint( $lesson_id ), self::META_STATUS, true );
		if ( 'completed' !== $status ) {
			return '';
		}
		return (string) get_post_meta( absint( $lesson_id ), self::META_TEXT, true );
	}

	/**
	 * @param int $lesson_id
	 * @return string not_requested | pending | processing | completed | failed
	 */
	public static function get_status( $lesson_id ) {
		$s = (string) get_post_meta( absint( $lesson_id ), self::META_STATUS, true );
		return $s ? $s : 'not_requested';
	}
}
