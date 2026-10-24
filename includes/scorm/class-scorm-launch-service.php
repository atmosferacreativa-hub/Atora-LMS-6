<?php
/**
 * SCORM: helpers compartidos para resolver recursos enlazados a una lección.
 *
 * Se usa tanto por la API web (`atora/v1`) como por la API móvil
 * (`atora-mobile/v1`) para evitar duplicar reglas.
 *
 * @package ATORA_LMS
 * @since 6.35.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLMS_SCORM_Launch_Service {
	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function find_scorm_resource_for_lesson( int $wp_lesson_id, int $library_item_id ) {
		$wp_lesson_id   = absint( $wp_lesson_id );
		$library_item_id = absint( $library_item_id );
		if ( ! $wp_lesson_id || ! $library_item_id ) {
			return new WP_Error( 'atora_scorm_invalid_params', __( 'Parámetros inválidos.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$resources = get_post_meta( $wp_lesson_id, '_clms_lesson_resources', true );
		$resources = is_array( $resources ) ? $resources : array();
		foreach ( $resources as $resource ) {
			if ( ! is_array( $resource ) ) {
				continue;
			}
			if ( 'scorm' !== (string) ( $resource['type'] ?? '' ) ) {
				continue;
			}
			if ( absint( $resource['library_item_id'] ?? 0 ) !== $library_item_id ) {
				continue;
			}
			return $resource;
		}
		return new WP_Error( 'atora_scorm_resource_not_found', __( 'El paquete no está enlazado a esta lección.', 'atora-lms' ), array( 'status' => 404 ) );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public static function resolve_library_version( int $item_id, int $version_id ) {
		global $wpdb;
		$item_id    = absint( $item_id );
		$version_id = absint( $version_id );
		if ( ! $item_id ) {
			return new WP_Error( 'atora_scorm_invalid_item', __( 'Recurso inválido.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$item = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_library_items WHERE id = %d LIMIT 1", $item_id ), ARRAY_A );
		if ( empty( $item ) ) {
			return new WP_Error( 'atora_scorm_item_not_found', __( 'Recurso no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		if ( ! $version_id ) {
			$version_id = absint( $item['current_version_id'] ?? 0 );
		}
		$version = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_library_versions WHERE id = %d AND item_id = %d LIMIT 1", $version_id, $item_id ), ARRAY_A );
		if ( empty( $version ) ) {
			return new WP_Error( 'atora_scorm_version_not_found', __( 'Versión no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		return $version;
	}

	/**
	 * @return array{scorm_version:string,launch_url:string,content_base_url:string,launch_href:string}|WP_Error
	 */
	public static function extract_scorm_meta( array $version ) {
		$raw  = $version['metadata_json'] ?? null;
		$meta = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
		$meta = is_array( $meta ) ? $meta : array();
		$scorm = isset( $meta['scorm'] ) && is_array( $meta['scorm'] ) ? (array) $meta['scorm'] : array();
		$scorm_version = isset( $scorm['scorm_version'] ) ? (string) $scorm['scorm_version'] : '';
		$launch_url    = isset( $scorm['launch_url'] ) ? (string) $scorm['launch_url'] : '';
		$launch_href   = isset( $scorm['launch_href'] ) ? (string) $scorm['launch_href'] : '';
		$content_base  = isset( $scorm['content_base_url'] ) ? (string) $scorm['content_base_url'] : '';
		if ( '' === $scorm_version || '' === $launch_url || '' === $launch_href || '' === $content_base ) {
			return new WP_Error( 'atora_scorm_meta_missing', __( 'La versión SCORM no tiene metadata instalada.', 'atora-lms' ), array( 'status' => 409 ) );
		}
		return array(
			'scorm_version'    => $scorm_version,
			'launch_url'       => $launch_url,
			'content_base_url' => $content_base,
			'launch_href'      => $launch_href,
		);
	}

	/**
	 * @return string[]|WP_Error
	 */
	public static function list_sco_hrefs_from_install( string $content_base_url ) {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return new WP_Error( 'atora_scorm_wp_required', __( 'WordPress no está disponible para resolver el paquete.', 'atora-lms' ) );
		}
		$uploads = wp_upload_dir();
		$baseurl = rtrim( (string) ( $uploads['baseurl'] ?? '' ), '/' );
		$basedir = rtrim( (string) ( $uploads['basedir'] ?? '' ), '/' );
		if ( '' === $baseurl || '' === $basedir || '' === $content_base_url ) {
			return new WP_Error( 'atora_scorm_uploads_unavailable', __( 'Uploads no disponibles.', 'atora-lms' ) );
		}
		if ( ! str_starts_with( $content_base_url, $baseurl ) ) {
			return new WP_Error( 'atora_scorm_unexpected_base', __( 'Ruta SCORM inválida.', 'atora-lms' ) );
		}
		$rel = ltrim( substr( $content_base_url, strlen( $baseurl ) ), '/' );
		$dir = $basedir . '/' . $rel;
		$manifest = rtrim( $dir, '/' ) . '/imsmanifest.xml';
		if ( ! file_exists( $manifest ) ) {
			return new WP_Error( 'atora_scorm_manifest_missing', __( 'imsmanifest.xml no encontrado.', 'atora-lms' ) );
		}
		$xml = (string) file_get_contents( $manifest );
		$parsed = \CLMS_SCORM_Manifest_Parser::parse( $xml );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		return (array) ( $parsed['sco_hrefs'] ?? array() );
	}
}

