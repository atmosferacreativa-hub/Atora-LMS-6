<?php
/**
 * Videos de una lección: una sola lista para la web y la app (6.28.2).
 *
 * Antes, la web mostraba todos los videos del editor
 * (`CLMS_UI_Lesson_Sections::normalize_videos()`) y la API móvil se quedaba con
 * el primero. Ahora ambas leen esta lista, en el orden del editor y con el
 * mismo límite de videos de la lección.
 *
 * Cada video lleva una `key` estable: los primeros 12 caracteres del sha1 de
 * su URL normalizada (YouTube, Vimeo y Drive por su identificador), con un
 * sufijo `-2`, `-3`… solo si la misma URL se repite. Reordenar los videos no
 * cambia su `key`.
 *
 * @package ATORA_LMS
 * @since 6.28.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Lesson_Videos {

	/** Todos los videos del editor, en su orden (o el video heredado de `_clms_lesson_video_url`). */
	public static function all( int $wp_lesson_id ): array {
		$extra_videos = get_post_meta( $wp_lesson_id, '_clms_lesson_extra_videos', true );
		if ( is_string( $extra_videos ) && '' !== trim( $extra_videos ) ) {
			$decoded      = json_decode( $extra_videos, true );
			$extra_videos = is_array( $decoded ) ? $decoded : ( function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $extra_videos ) : array() );
		}
		$extra_videos = is_array( $extra_videos )
			? array_values(
				array_filter(
					$extra_videos,
					static function ( $video ) {
						return is_array( $video ) && ! empty( $video['url'] );
					}
				)
			)
			: array();

		if ( ! empty( $extra_videos ) ) {
			return $extra_videos;
		}

		$legacy_url    = (string) get_post_meta( $wp_lesson_id, '_clms_lesson_video_url', true );
		$legacy_source = (string) ( get_post_meta( $wp_lesson_id, '_clms_lesson_video_source', true ) ?: 'youtube' );

		if ( ! $legacy_url ) {
			return array();
		}

		return array(
			array(
				'source'      => $legacy_source,
				'url'         => $legacy_url,
				'description' => '',
				'tip_1'       => get_post_meta( $wp_lesson_id, '_clms_tip_1', true ),
				'tip_2'       => get_post_meta( $wp_lesson_id, '_clms_tip_2', true ),
				'tip_3'       => get_post_meta( $wp_lesson_id, '_clms_tip_3', true ),
			),
		);
	}

	/** Límite de videos de la lección (0 = sin límite), el mismo que aplica la web. */
	public static function limit( int $wp_lesson_id ): int {
		if ( class_exists( 'CLMS_UI_Lesson_Sections' ) && method_exists( 'CLMS_UI_Lesson_Sections', 'videos_limit' ) ) {
			return CLMS_UI_Lesson_Sections::videos_limit( $wp_lesson_id );
		}
		$raw = get_post_meta( $wp_lesson_id, '_clms_lesson_ui_limit_videos', true );
		return '' !== (string) $raw ? max( 0, absint( $raw ) ) : 0;
	}

	/** Lo que ve el estudiante: la lista con el límite aplicado y una `key` por video. */
	public static function visible( int $wp_lesson_id ): array {
		$videos = self::all( $wp_lesson_id );
		$limit  = self::limit( $wp_lesson_id );
		if ( $limit > 0 ) {
			$videos = array_slice( $videos, 0, $limit );
		}
		return self::with_keys( $videos );
	}

	/** @param array<int, array> $videos */
	public static function with_keys( array $videos ): array {
		$seen = array();
		foreach ( $videos as $i => $video ) {
			$base = self::key_for( (string) ( $video['url'] ?? '' ) );
			$seen[ $base ] = ( $seen[ $base ] ?? 0 ) + 1;
			$videos[ $i ]['key'] = 1 === $seen[ $base ] ? $base : $base . '-' . $seen[ $base ];
		}
		return array_values( $videos );
	}

	public static function key_for( string $url ): string {
		return substr( sha1( self::canonical_url( $url ) ), 0, 12 );
	}

	/** Misma identidad para variantes de URL del mismo video. */
	public static function canonical_url( string $url ): string {
		$url = trim( $url );
		if ( preg_match( '#(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([a-zA-Z0-9_-]{11})#', $url, $m ) ) {
			return 'youtube:' . $m[1];
		}
		if ( preg_match( '#vimeo\.com/(?:video/|channels/[^/]+/|groups/[^/]+/videos/)?(\d+)#', $url, $m ) ) {
			return 'vimeo:' . $m[1];
		}
		if ( preg_match( '#(?:drive|docs)\.google\.com/(?:file/d/|open\?id=|uc\?(?:.*&)?id=)([a-zA-Z0-9_-]{10,})#', $url, $m ) ) {
			return 'drive:' . $m[1];
		}
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return strtolower( $url );
		}
		$host = preg_replace( '/^www\./', '', strtolower( (string) $parts['host'] ) );
		$path = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return $host . $path . $query;
	}
}
