<?php
/**
 * Miniatura de video de una lección (API móvil, 6.27.1).
 *
 * Orden de resolución:
 * 1. miniatura manual del video guardada en el editor de la lección;
 * 2. YouTube: imagen pública por identificador del video;
 * 3. Vimeo: miniatura obtenida una vez (en segundo plano) y guardada en caché;
 * 4. imagen destacada de la lección;
 * 5. portada del curso.
 *
 * Google Drive y MP4 directo no tienen miniatura fiable desde el servidor: caen a 4 y 5.
 * Nunca hay llamadas externas durante la petición.
 *
 * @package ATORA_LMS
 * @since 6.27.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Video_Thumbnail_Resolver {
	const VIMEO_FETCH_HOOK = 'atora_fetch_vimeo_thumbnail';
	const VIMEO_TTL        = 30 * DAY_IN_SECONDS;
	const VIMEO_FAIL_TTL   = DAY_IN_SECONDS;
	const VIMEO_NONE       = 'none';

	public static function resolve( int $wp_lesson_id, string $video_url, string $course_cover_url = '', int $wp_course_id = 0 ): string {
		$manual = self::manual_thumbnail( $wp_lesson_id, $video_url );
		if ( '' !== $manual ) {
			return $manual;
		}

		if ( '' !== $video_url ) {
			$youtube = self::youtube_id( $video_url );
			if ( '' !== $youtube ) {
				return 'https://img.youtube.com/vi/' . $youtube . '/hqdefault.jpg';
			}
			$vimeo = self::vimeo_id( $video_url );
			if ( '' !== $vimeo ) {
				$cached = self::vimeo_thumbnail( $vimeo );
				if ( '' !== $cached ) {
					return $cached;
				}
			}
		}

		if ( $wp_lesson_id > 0 ) {
			$featured = (string) get_the_post_thumbnail_url( $wp_lesson_id, 'large' );
			if ( '' !== $featured ) {
				return esc_url_raw( $featured );
			}
		}

		if ( '' !== $course_cover_url ) {
			return esc_url_raw( $course_cover_url );
		}
		if ( $wp_course_id > 0 ) {
			return esc_url_raw( (string) get_the_post_thumbnail_url( $wp_course_id, 'large' ) );
		}
		return '';
	}

	public static function youtube_id( string $url ): string {
		return preg_match( '#(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([a-zA-Z0-9_-]{11})#', $url, $m ) ? $m[1] : '';
	}

	public static function vimeo_id( string $url ): string {
		return preg_match( '#vimeo\.com/(?:video/|channels/[^/]+/|groups/[^/]+/videos/)?(\d+)#', $url, $m ) ? $m[1] : '';
	}

	/** Miniatura que el editor de la lección guardó para este video. */
	private static function manual_thumbnail( int $wp_lesson_id, string $video_url ): string {
		if ( $wp_lesson_id <= 0 ) {
			return '';
		}
		$items = get_post_meta( $wp_lesson_id, '_clms_lesson_extra_videos', true );
		if ( is_string( $items ) && '' !== trim( $items ) ) {
			$decoded = json_decode( $items, true );
			$items   = is_array( $decoded ) ? $decoded : ( function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $items ) : array() );
		}
		if ( ! is_array( $items ) ) {
			return '';
		}

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['thumb_id'] ) ) {
				continue;
			}
			if ( '' !== $video_url && ! self::same_video( (string) ( $item['url'] ?? '' ), $video_url ) ) {
				continue;
			}
			$url = (string) wp_get_attachment_image_url( absint( $item['thumb_id'] ), 'large' );
			if ( '' !== $url ) {
				return esc_url_raw( $url );
			}
		}
		return '';
	}

	private static function same_video( string $a, string $b ): bool {
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( rtrim( $a, '/' ) === rtrim( $b, '/' ) ) {
			return true;
		}
		$ya = self::youtube_id( $a );
		if ( '' !== $ya ) {
			return $ya === self::youtube_id( $b );
		}
		$va = self::vimeo_id( $a );
		return '' !== $va && $va === self::vimeo_id( $b );
	}

	/** Solo lee la caché; si falta, programa la consulta y devuelve '' para seguir con el siguiente paso. */
	private static function vimeo_thumbnail( string $vimeo_id ): string {
		$cached = get_transient( 'atora_vimeo_thumb_' . $vimeo_id );
		if ( is_string( $cached ) && '' !== $cached ) {
			return self::VIMEO_NONE === $cached ? '' : $cached;
		}
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::VIMEO_FETCH_HOOK, array( $vimeo_id ) ) ) {
			wp_schedule_single_event( time(), self::VIMEO_FETCH_HOOK, array( $vimeo_id ) );
		}
		return '';
	}

	/** Consulta oEmbed de Vimeo (en cron) y guarda el resultado; los fallos también se guardan, por menos tiempo. */
	public static function fetch_vimeo_thumbnail( string $vimeo_id ): void {
		if ( ! preg_match( '/^\d+$/', $vimeo_id ) ) {
			return;
		}
		$response = wp_remote_get(
			'https://vimeo.com/api/oembed.json?url=' . rawurlencode( 'https://vimeo.com/' . $vimeo_id ),
			array( 'timeout' => 5 )
		);
		$thumb = '';
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$thumb = is_array( $data ) ? esc_url_raw( (string) ( $data['thumbnail_url'] ?? '' ) ) : '';
		}
		if ( '' !== $thumb && 0 === strpos( $thumb, 'https://' ) ) {
			set_transient( 'atora_vimeo_thumb_' . $vimeo_id, $thumb, self::VIMEO_TTL );
		} else {
			set_transient( 'atora_vimeo_thumb_' . $vimeo_id, self::VIMEO_NONE, self::VIMEO_FAIL_TTL );
		}
	}
}
