<?php
/**
 * Datos de descarga de recursos y videos para la API móvil (6.28.0).
 *
 * Descargable solo si el archivo es de la propia academia: un adjunto de la
 * biblioteca o una URL del mismo dominio que apunta a un archivo. Google Drive,
 * YouTube, Vimeo y otros sitios: no descargables ("Solo con conexión").
 *
 * @package ATORA_LMS
 * @since 6.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Download_Info {
	const VIDEO_EXTENSIONS = array( 'mp4', 'm4v' );

	/** @return array{downloadable:bool, bytes:?int, updated_at:?string} */
	public static function for_resource( int $file_id, string $url ): array {
		$attachment = $file_id > 0 ? $file_id : self::local_attachment_id( $url );
		$downloadable = $attachment > 0 || ( self::is_local( $url ) && '' !== self::extension( $url ) );
		return array(
			'downloadable' => $downloadable,
			'bytes'        => $attachment > 0 ? self::bytes( $attachment ) : null,
			'updated_at'   => $attachment > 0 ? self::updated_at( $attachment ) : null,
		);
	}

	/** @return array{video_downloadable:bool, video_bytes:?int} */
	public static function for_video( string $url ): array {
		if ( '' === $url || ! self::is_local( $url ) ) {
			return array( 'video_downloadable' => false, 'video_bytes' => null );
		}
		$attachment = self::local_attachment_id( $url );
		$is_mp4     = in_array( self::extension( $url ), self::VIDEO_EXTENSIONS, true )
			|| ( $attachment > 0 && 'video/mp4' === (string) get_post_mime_type( $attachment ) );
		return array(
			'video_downloadable' => $is_mp4,
			'video_bytes'        => $is_mp4 && $attachment > 0 ? self::bytes( $attachment ) : null,
		);
	}

	public static function is_local( string $url ): bool {
		$host = strtolower( self::url_part( $url, 'host' ) );
		$home = strtolower( self::url_part( home_url( '/' ), 'host' ) );
		return '' !== $host && preg_replace( '/^www\./', '', $host ) === preg_replace( '/^www\./', '', $home );
	}

	public static function extension( string $url ): string {
		$path = self::url_part( $url, 'path' );
		return preg_match( '/\.([a-z0-9]{2,5})$/i', $path, $m ) ? strtolower( $m[1] ) : '';
	}

	private static function url_part( string $url, string $part ): string {
		$parts = wp_parse_url( $url );
		return is_array( $parts ) ? (string) ( $parts[ $part ] ?? '' ) : '';
	}

	private static function local_attachment_id( string $url ): int {
		if ( '' === $url || ! self::is_local( $url ) || ! function_exists( 'attachment_url_to_postid' ) ) {
			return 0;
		}
		return absint( attachment_url_to_postid( $url ) );
	}

	private static function bytes( int $attachment_id ): ?int {
		$path = function_exists( 'get_attached_file' ) ? (string) get_attached_file( $attachment_id ) : '';
		if ( '' !== $path && is_readable( $path ) ) {
			return (int) filesize( $path );
		}
		$meta = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $attachment_id ) : array();
		return is_array( $meta ) && isset( $meta['filesize'] ) ? absint( $meta['filesize'] ) : null;
	}

	private static function updated_at( int $attachment_id ): ?string {
		$modified = (string) get_post_field( 'post_modified_gmt', $attachment_id );
		return '' !== $modified && '0000-00-00 00:00:00' !== $modified ? str_replace( ' ', 'T', $modified ) . 'Z' : null;
	}
}
