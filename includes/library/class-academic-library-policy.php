<?php
/**
 * Reglas puras de la Biblioteca académica.
 *
 * @package ATORA_LMS
 * @since 6.24.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Academic_Library_Policy {

	const TRANSITIONS = array(
		'draft'     => array( 'review', 'archived' ),
		'review'    => array( 'draft', 'published' ),
		'published' => array( 'archived' ),
		'archived'  => array(),
	);

	const RESOURCE_TYPES = array( 'document', 'video', 'audio', 'image', 'link', 'dataset', 'interactive' );
	const LINK_TYPES     = array( 'competency', 'evidence' );

	public static function can_transition( $from, $to ) {
		$from = sanitize_key( (string) $from );
		$to   = sanitize_key( (string) $to );
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}

	public static function normalize_resource_type( $type ) {
		$type = sanitize_key( (string) $type );
		return in_array( $type, self::RESOURCE_TYPES, true ) ? $type : 'document';
	}

	public static function normalize_mime_type( $mime_type ) {
		return strtolower( (string) preg_replace( '/[^a-z0-9.+\\-\\/]/i', '', (string) $mime_type ) );
	}

	public static function canonical_hash( $payload ) {
		$payload = is_array( $payload ) ? $payload : array();
		$canonical = array(
			'attachment_id' => absint( $payload['attachment_id'] ?? 0 ),
			'content_url'   => esc_url_raw( (string) ( $payload['content_url'] ?? '' ) ),
			'mime_type'     => self::normalize_mime_type( $payload['mime_type'] ?? '' ),
			'metadata'      => self::sort_recursive( is_array( $payload['metadata'] ?? null ) ? $payload['metadata'] : array() ),
		);
		return hash( 'sha256', (string) wp_json_encode( $canonical ) );
	}

	protected static function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sort_recursive( $item );
		}
		return $value;
	}
}
