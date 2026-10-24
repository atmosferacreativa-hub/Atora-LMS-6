<?php
/**
 * SCORM: parser mínimo de imsmanifest.xml.
 *
 * Soporta SCORM 1.2 y SCORM 2004 (3ª/4ª edición) a través del campo
 * <schemaversion>. La meta del Bloque 1 no es implementar todo el árbol de
 * organizaciones, sino validar el manifiesto y resolver un SCO inicial.
 *
 * @package ATORA_LMS
 * @since 6.35.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLMS_SCORM_Manifest_Parser {
	/**
	 * @return array{scorm_version:string,schemaversion_raw:string,launch_href:string,sco_hrefs:string[]}|WP_Error
	 */
	public static function parse( string $xml ) {
		$xml = trim( $xml );
		if ( '' === $xml ) {
			return new WP_Error( 'clms_scorm_manifest_invalid_xml', __( 'Manifiesto SCORM vacío.', 'atora-lms' ) );
		}

		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$loaded = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $loaded ) {
			return new WP_Error( 'clms_scorm_manifest_invalid_xml', __( 'Manifiesto SCORM inválido.', 'atora-lms' ) );
		}

		$xpath = new DOMXPath( $doc );
		foreach ( $doc->documentElement ? $doc->documentElement->attributes : array() as $attr ) {
			// No-op: asegurar que DOM cargó.
		}

		// Registrar namespaces comunes.
		$xpath->registerNamespace( 'imscp', 'http://www.imsproject.org/xsd/imscp_rootv1p1p2' );
		$xpath->registerNamespace( 'imscp11', 'http://www.imsglobal.org/xsd/imscp_v1p1' );
		$xpath->registerNamespace( 'adlcp12', 'http://www.adlnet.org/xsd/adlcp_rootv1p2' );
		$xpath->registerNamespace( 'adlcp2004', 'http://www.adlnet.org/xsd/adlcp_v1p3' );

		$schemaversion_raw = '';
		$schemaversion_node = $xpath->query( '//*[local-name()="metadata"]/*[local-name()="schemaversion"][1]' );
		if ( $schemaversion_node instanceof DOMNodeList && $schemaversion_node->length > 0 ) {
			$schemaversion_raw = trim( (string) $schemaversion_node->item( 0 )->textContent );
		}

		$scorm_version = self::scorm_version_from_schemaversion( $schemaversion_raw );
		if ( '' === $scorm_version ) {
			return new WP_Error( 'clms_scorm_manifest_unsupported_version', __( 'No se pudo detectar la versión SCORM del manifiesto.', 'atora-lms' ) );
		}

		$sco_hrefs = self::list_sco_hrefs( $xpath );
		if ( is_wp_error( $sco_hrefs ) ) {
			return $sco_hrefs;
		}
		$launch_href = (string) ( $sco_hrefs[0] ?? '' );
		if ( '' === $launch_href ) {
			return new WP_Error( 'clms_scorm_manifest_missing_sco', __( 'El manifiesto no declara ningún SCO.', 'atora-lms' ) );
		}

		return array(
			'scorm_version'     => $scorm_version,
			'schemaversion_raw' => $schemaversion_raw,
			'launch_href'       => $launch_href,
			'sco_hrefs'         => $sco_hrefs,
		);
	}

	private static function scorm_version_from_schemaversion( string $schemaversion ): string {
		$schemaversion = strtolower( trim( $schemaversion ) );
		if ( '' === $schemaversion ) {
			return '';
		}
		if ( str_contains( $schemaversion, '1.2' ) ) {
			return '1.2';
		}
		if ( str_contains( $schemaversion, '2004' ) ) {
			return '2004';
		}
		return '';
	}

	/**
	 * @return string|WP_Error
	 */
	/**
	 * @return string[]|WP_Error
	 */
	private static function list_sco_hrefs( DOMXPath $xpath ) {
		$resource_nodes = $xpath->query( '//*[local-name()="resource"]' );
		if ( ! ( $resource_nodes instanceof DOMNodeList ) || 0 === $resource_nodes->length ) {
			return new WP_Error( 'clms_scorm_manifest_missing_sco', __( 'El manifiesto no declara recursos.', 'atora-lms' ) );
		}

		$hrefs = array();
		$by_identifier = array();

		for ( $i = 0; $i < $resource_nodes->length; $i++ ) {
			$node = $resource_nodes->item( $i );
			if ( ! ( $node instanceof DOMElement ) ) {
				continue;
			}
			$scorm_type = '';
			foreach ( array( 'adlcp:scormtype', 'adlcp:scormType', 'scormtype', 'scormType' ) as $attr_name ) {
				if ( $node->hasAttribute( $attr_name ) ) {
					$scorm_type = (string) $node->getAttribute( $attr_name );
					break;
				}
			}
			$scorm_type = strtolower( trim( $scorm_type ) );
			if ( 'sco' !== $scorm_type ) {
				continue;
			}
			$href = trim( (string) $node->getAttribute( 'href' ) );
			if ( '' === $href ) {
				continue;
			}
			if ( ! self::is_safe_relative_path( $href ) ) {
				return new WP_Error( 'clms_scorm_manifest_unsafe_launch', __( 'El manifiesto declara un SCO no seguro.', 'atora-lms' ) );
			}
			$hrefs[] = $href;
			$identifier = trim( (string) $node->getAttribute( 'identifier' ) );
			if ( '' !== $identifier ) {
				$by_identifier[ $identifier ] = $href;
			}
		}

		if ( empty( $hrefs ) ) {
			return new WP_Error( 'clms_scorm_manifest_missing_sco', __( 'El manifiesto no declara ningún SCO.', 'atora-lms' ) );
		}

		// Intentar ordenar según la organización por defecto (si existe) para habilitar navegación multi‑SCO.
		$ordered = self::ordered_sco_hrefs_from_organization( $xpath, $by_identifier );
		if ( ! empty( $ordered ) ) {
			return array_values( array_unique( $ordered ) );
		}

		return array_values( array_unique( $hrefs ) );
	}

	/**
	 * @param array<string,string> $by_identifier
	 * @return string[]
	 */
	private static function ordered_sco_hrefs_from_organization( DOMXPath $xpath, array $by_identifier ): array {
		if ( empty( $by_identifier ) ) {
			return array();
		}
		$items = $xpath->query( '//*[local-name()="organization"][1]//*[local-name()="item"][@identifierref]' );
		if ( ! ( $items instanceof DOMNodeList ) || 0 === $items->length ) {
			return array();
		}
		$out = array();
		for ( $i = 0; $i < $items->length; $i++ ) {
			$node = $items->item( $i );
			if ( ! ( $node instanceof DOMElement ) ) {
				continue;
			}
			$ref = trim( (string) $node->getAttribute( 'identifierref' ) );
			if ( '' === $ref || ! isset( $by_identifier[ $ref ] ) ) {
				continue;
			}
			$href = $by_identifier[ $ref ];
			$parameters = trim( (string) $node->getAttribute( 'parameters' ) );
			if ( '' !== $parameters && str_starts_with( $parameters, '?' ) && ! str_contains( $parameters, '://' ) ) {
				$href .= $parameters;
			}
			$out[] = $href;
		}
		return $out;
	}

	private static function is_safe_relative_path( string $path ): bool {
		$path = str_replace( '\\', '/', trim( $path ) );
		if ( '' === $path ) {
			return false;
		}
		if ( str_contains( $path, "\0" ) ) {
			return false;
		}
		if ( str_starts_with( $path, '/' ) || preg_match( '/^[a-zA-Z]:\\//', $path ) ) {
			return false;
		}
		if ( preg_match( '#(?:^|/)\.\.(?:/|$)#', $path ) ) {
			return false;
		}
		if ( preg_match( '#^[a-z]+://#i', $path ) ) {
			return false;
		}
		return true;
	}
}

