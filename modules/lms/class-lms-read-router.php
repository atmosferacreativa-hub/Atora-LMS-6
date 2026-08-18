<?php
/**
 * LMS Read Router — F3.0
 *
 * Punto único de resolución de la fuente de lectura del LMS.
 * Valores: 'legacy' (default, F3) | 'tables' (activado en F4 tras gate de paridad).
 * Nada lee la option directamente; todo pasa por aquí.
 *
 * @package ATORA_LMS\LMS
 * @since   6.0.9
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LMS_Read_Router {

	const OPT_SOURCE = 'atora_lms_read_source';

	public static function source(): string {
		return (string) get_option( self::OPT_SOURCE, 'legacy' );
	}

	public static function is_tables(): bool {
		return 'tables' === self::source();
	}

	public static function set_source( string $source ): void {
		$clean = in_array( $source, array( 'legacy', 'tables' ), true ) ? $source : 'legacy';
		update_option( self::OPT_SOURCE, $clean, false );
	}
}
