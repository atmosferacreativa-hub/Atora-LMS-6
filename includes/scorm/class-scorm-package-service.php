<?php
/**
 * Servicio SCORM: valida e instala un paquete (manifest + extracción).
 *
 * @package ATORA_LMS
 * @since 6.35.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-scorm-manifest-parser.php';
require_once __DIR__ . '/class-scorm-zip-extractor.php';

final class CLMS_SCORM_Package_Service {
	/**
	 * Analiza un ZIP SCORM y devuelve su metadata canónica.
	 *
	 * @return array{scorm_version:string,schemaversion_raw:string,launch_href:string}|WP_Error
	 */
	public static function analyze_zip( string $zip_path ) {
		$manifest = self::read_manifest_from_zip( $zip_path );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		return CLMS_SCORM_Manifest_Parser::parse( $manifest );
	}

	/**
	 * Instala un ZIP SCORM en un directorio ya resuelto (por versión).
	 *
	 * @return array{scorm_version:string,schemaversion_raw:string,launch_href:string,launch_path:string}|WP_Error
	 */
	public static function install_zip( string $zip_path, string $dest_dir ) {
		$analysis = self::analyze_zip( $zip_path );
		if ( is_wp_error( $analysis ) ) {
			return $analysis;
		}
		$extracted = CLMS_SCORM_Zip_Extractor::extract( $zip_path, $dest_dir );
		if ( is_wp_error( $extracted ) ) {
			return $extracted;
		}

		$launch_path = rtrim( $dest_dir, '/\\' ) . '/' . str_replace( '\\', '/', (string) $analysis['launch_href'] );
		if ( ! file_exists( $launch_path ) ) {
			return new WP_Error( 'clms_scorm_package_missing_launch', __( 'El manifiesto apunta a un SCO que no existe en el ZIP.', 'atora-lms' ) );
		}

		return array_merge( $analysis, array( 'launch_path' => $launch_path ) );
	}

	/**
	 * @return string|WP_Error
	 */
	private static function read_manifest_from_zip( string $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'clms_scorm_zip_unavailable', __( 'ZipArchive no está disponible en el servidor.', 'atora-lms' ) );
		}
		$zip = new ZipArchive();
		$opened = $zip->open( $zip_path );
		if ( true !== $opened ) {
			return new WP_Error( 'clms_scorm_zip_open_failed', __( 'No se pudo abrir el ZIP SCORM.', 'atora-lms' ) );
		}

		$manifest_index = -1;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			$name = str_replace( '\\', '/', $name );
			if ( 'imsmanifest.xml' === strtolower( basename( $name ) ) ) {
				$manifest_index = $i;
				break;
			}
		}
		if ( $manifest_index < 0 ) {
			$zip->close();
			return new WP_Error( 'clms_scorm_manifest_missing', __( 'El ZIP no contiene imsmanifest.xml.', 'atora-lms' ) );
		}

		$contents = $zip->getFromIndex( $manifest_index );
		$zip->close();
		if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
			return new WP_Error( 'clms_scorm_manifest_missing', __( 'No se pudo leer imsmanifest.xml.', 'atora-lms' ) );
		}
		return $contents;
	}
}

