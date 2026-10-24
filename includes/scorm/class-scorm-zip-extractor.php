<?php
/**
 * SCORM: extracción segura de ZIP.
 *
 * Defensa en profundidad:
 * - zip slip (.., rutas absolutas)
 * - enlaces simbólicos
 * - allowlist de extensiones (bloquea PHP/scripts)
 * - límites de archivos, tamaño total y ratio compresión
 *
 * @package ATORA_LMS
 * @since 6.35.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLMS_SCORM_Zip_Extractor {
	private const HTACCESS_CONTENTS = "<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|rb|sh)$\">\nRequire all denied\n</FilesMatch>\nOptions -Indexes\n";
	private const HTACCESS_CONTENTS_LEGACY = "<FilesMatch \\\"\\.(php|phtml|phar|cgi|pl|py|rb|sh)\\$\\\">\nRequire all denied\n</FilesMatch>\nOptions -Indexes\n";
	private const DEFAULT_MAX_FILES = 4000;
	private const DEFAULT_MAX_UNCOMPRESSED_BYTES = 200 * 1024 * 1024;
	private const DEFAULT_MAX_COMPRESSION_RATIO = 100;

	private const ALLOWED_EXTENSIONS = array(
		'html', 'htm', 'css', 'js', 'json',
		'xml', 'xsd', 'xsl',
		'txt', 'csv', 'dat',
		'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico',
		'mp3', 'wav', 'ogg',
		'mp4', 'm4v', 'webm', 'mov',
		'woff', 'woff2', 'ttf', 'otf', 'eot',
		'pdf',
	);

	/**
	 * @param array{max_files?:int,max_uncompressed_bytes?:int,max_compression_ratio?:int} $options
	 * @return array<int,string>|WP_Error Lista de paths extraídos (relativos al ZIP).
	 */
	public static function extract( string $zip_path, string $dest_dir, array $options = array() ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'clms_scorm_zip_unavailable', __( 'ZipArchive no está disponible en el servidor.', 'atora-lms' ) );
		}
		$zip_path = (string) $zip_path;
		$dest_dir = rtrim( (string) $dest_dir, '/\\' );
		$max_files = isset( $options['max_files'] ) ? max( 1, absint( $options['max_files'] ) ) : self::DEFAULT_MAX_FILES;
		$max_uncompressed = isset( $options['max_uncompressed_bytes'] ) ? max( 1, absint( $options['max_uncompressed_bytes'] ) ) : self::DEFAULT_MAX_UNCOMPRESSED_BYTES;
		$max_ratio = isset( $options['max_compression_ratio'] ) ? max( 1, absint( $options['max_compression_ratio'] ) ) : self::DEFAULT_MAX_COMPRESSION_RATIO;

		$zip = new ZipArchive();
		$opened = $zip->open( $zip_path );
		if ( true !== $opened ) {
			return new WP_Error( 'clms_scorm_zip_open_failed', __( 'No se pudo abrir el ZIP SCORM.', 'atora-lms' ) );
		}

		$real_dest = realpath( $dest_dir );
		if ( false === $real_dest ) {
			if ( ! self::mkdir_p( $dest_dir ) ) {
				$zip->close();
				return new WP_Error( 'clms_scorm_zip_dest_unwritable', __( 'No se pudo crear el directorio destino.', 'atora-lms' ) );
			}
			$real_dest = realpath( $dest_dir );
		}
		if ( false === $real_dest ) {
			$zip->close();
			return new WP_Error( 'clms_scorm_zip_dest_unwritable', __( 'El directorio destino no es accesible.', 'atora-lms' ) );
		}

		$paths = array();
		$total_uncompressed = 0;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			$name = str_replace( '\\', '/', $name );
			$name = ltrim( $name, '/' );
			if ( '' === $name ) {
				continue;
			}
			if ( str_contains( $name, "\0" ) ) {
				$zip->close();
				return new WP_Error( 'clms_scorm_zip_unsafe_path', __( 'El ZIP contiene rutas inválidas.', 'atora-lms' ) );
			}
			if ( preg_match( '/^[a-zA-Z]:\//', $name ) ) {
				$zip->close();
				return new WP_Error( 'clms_scorm_zip_unsafe_path', __( 'El ZIP contiene rutas absolutas.', 'atora-lms' ) );
			}
			if ( preg_match( '#(?:^|/)\.\.(?:/|$)#', $name ) ) {
				$zip->close();
				return new WP_Error( 'clms_scorm_zip_unsafe_path', __( 'El ZIP contiene rutas inseguras.', 'atora-lms' ) );
			}
			if ( str_starts_with( basename( $name ), '.' ) ) {
				$zip->close();
				return new WP_Error( 'clms_scorm_zip_unsafe_path', __( 'El ZIP contiene archivos ocultos.', 'atora-lms' ) );
			}
			if ( str_ends_with( $name, '/' ) ) {
				continue;
			}
			if ( count( $paths ) >= $max_files ) {
				$zip->close();
				return new WP_Error( 'clms_scorm_zip_too_many_files', __( 'El ZIP contiene demasiados archivos.', 'atora-lms' ) );
			}

			$stat = $zip->statIndex( $i );
			$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			$comp = isset( $stat['comp_size'] ) ? (int) $stat['comp_size'] : 0;
			$total_uncompressed += max( 0, $size );
			if ( $total_uncompressed > $max_uncompressed ) {
				$zip->close();
				return new WP_Error( 'clms_scorm_zip_too_large', __( 'El ZIP excede el tamaño máximo permitido.', 'atora-lms' ) );
			}
			$ratio = $size / max( 1, $comp );
			if ( $ratio > $max_ratio ) {
				$zip->close();
				return new WP_Error( 'clms_scorm_zip_suspected_bomb', __( 'El ZIP parece una bomba de compresión.', 'atora-lms' ) );
			}

			if ( self::is_symlink_entry( $zip, $i ) ) {
				$zip->close();
				return new WP_Error( 'clms_scorm_zip_symlink', __( 'El ZIP contiene enlaces simbólicos.', 'atora-lms' ) );
			}

			$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( '' !== $ext && ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
				$zip->close();
				return new WP_Error( 'clms_scorm_zip_disallowed_extension', __( 'El ZIP contiene tipos de archivo no permitidos.', 'atora-lms' ) );
			}
			if ( '' === $ext && 'imsmanifest.xml' !== strtolower( basename( $name ) ) ) {
				$zip->close();
				return new WP_Error( 'clms_scorm_zip_disallowed_extension', __( 'El ZIP contiene archivos sin extensión no permitidos.', 'atora-lms' ) );
			}

			$paths[] = $name;
		}

		foreach ( $paths as $name ) {
			$target = $dest_dir . '/' . $name;
			$target_dir = dirname( $target );
			if ( ! self::mkdir_p( $target_dir ) ) {
				$zip->close();
				return new WP_Error( 'clms_scorm_zip_dest_unwritable', __( 'No se pudo crear una carpeta del paquete.', 'atora-lms' ) );
			}
			$real_target_dir = realpath( $target_dir );
			if ( false === $real_target_dir || ! str_starts_with( $real_target_dir, $real_dest ) ) {
				$zip->close();
				return new WP_Error( 'clms_scorm_zip_unsafe_path', __( 'El ZIP intenta escribir fuera del destino.', 'atora-lms' ) );
			}
			$stream = $zip->getStream( $name );
			if ( ! is_resource( $stream ) ) {
				$zip->close();
				return new WP_Error( 'clms_scorm_zip_extract_failed', __( 'No se pudo leer un archivo del ZIP.', 'atora-lms' ) );
			}
			$out = fopen( $target, 'wb' );
			if ( false === $out ) {
				fclose( $stream );
				$zip->close();
				return new WP_Error( 'clms_scorm_zip_dest_unwritable', __( 'No se pudo escribir un archivo del paquete.', 'atora-lms' ) );
			}
			stream_copy_to_stream( $stream, $out );
			fclose( $out );
			fclose( $stream );
			@chmod( $target, 0644 );
		}

		$zip->close();
		self::harden_directory( $dest_dir );
		return $paths;
	}

	public static function expected_htaccess_contents(): string {
		return self::HTACCESS_CONTENTS;
	}

	public static function ensure_protected_directory( string $dir ): void {
		self::harden_directory( $dir );
	}

	private static function mkdir_p( string $dir ): bool {
		if ( '' === $dir ) {
			return false;
		}
		if ( is_dir( $dir ) ) {
			return true;
		}
		if ( function_exists( 'wp_mkdir_p' ) ) {
			return (bool) wp_mkdir_p( $dir );
		}
		return mkdir( $dir, 0777, true );
	}

	private static function is_symlink_entry( ZipArchive $zip, int $index ): bool {
		if ( method_exists( $zip, 'getExternalAttributesIndex' ) ) {
			$opsys = 0;
			$attr  = 0;
			if ( $zip->getExternalAttributesIndex( $index, $opsys, $attr ) ) {
				$mode = ( $attr >> 16 ) & 0xF000;
				return 0xA000 === $mode;
			}
		}
		return false;
	}

	private static function harden_directory( string $dir ): void {
		$dir = rtrim( $dir, '/\\' );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$index = $dir . '/index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<!doctype html><meta charset=\"utf-8\"><title></title>" );
		}
		$htaccess = $dir . '/.htaccess';
		$should_write = ! file_exists( $htaccess );
		if ( ! $should_write ) {
			$current = @file_get_contents( $htaccess );
			if ( is_string( $current ) && self::HTACCESS_CONTENTS_LEGACY === $current ) {
				$should_write = true;
			}
		}
		if ( $should_write ) {
			@file_put_contents( $htaccess, self::HTACCESS_CONTENTS );
		}
	}
}

