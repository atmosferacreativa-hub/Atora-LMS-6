<?php
/**
 * Rubric_Read_Router — resuelve la fuente canónica de lectura de rúbricas.
 *
 * Option: atora_rubric_source = postmeta|tables
 *
 * @package ATORA_LMS\Rubrics
 * @since   6.26.5
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rubric_Read_Router {

	public const OPT_SOURCE = 'atora_rubric_source';

	/**
	 * @return string postmeta|tables
	 */
	public static function get_source(): string {
		$raw = sanitize_key( (string) get_option( self::OPT_SOURCE, 'postmeta' ) );
		return in_array( $raw, array( 'postmeta', 'tables' ), true ) ? $raw : 'postmeta';
	}

	public static function is_tables(): bool {
		return 'tables' === self::get_source();
	}
}

