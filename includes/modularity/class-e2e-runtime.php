<?php
/**
 * Ayudas de ejecución para las pruebas de pantalla (6.33.0). Solo actúan en el
 * WordPress temporal del CI (constante `ATORA_E2E`); en cualquier otro sitio no
 * hacen nada.
 *
 * - Un curso marcado por `wp atora seed-e2e` (`_atora_e2e_certificate`) es
 *   certificable, para el recorrido `certificado-pdf` de la app.
 *
 * @package ATORA_LMS
 * @since 6.33.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_E2E_Runtime {

	const CERTIFICATE_META = '_atora_e2e_certificate';

	public static function boot(): void {
		if ( ! defined( 'ATORA_E2E' ) || ! ATORA_E2E ) {
			return;
		}
		add_filter( 'clms_certificate_course_eligibility', array( __CLASS__, 'eligibility' ), 99, 3 );
	}

	/** @param array $result Elegibilidad calculada por las reglas. */
	public static function eligibility( $result, $user_id, $course_id ) {
		if ( is_array( $result ) && '1' === (string) get_post_meta( absint( $course_id ), self::CERTIFICATE_META, true ) ) {
			$result['eligible']             = true;
			$result['missing_requirements'] = array();
		}
		return $result;
	}
}
