<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Helper_Grading_Trait {
	/**
	 * Formatea una nota numérica (0-100 interno) según la escala configurada en la lección.
	 *
	 * @param float  $score     Nota en escala 0-100.
	 * @param int    $lesson_id ID de la lección (para leer su escala).
	 * @return string Nota formateada lista para mostrar.
	 */
	public static function format_grade( $score, $lesson_id = 0 ) {
		$score     = (float) $score;
		$lesson_id = absint( $lesson_id );
		$scale     = $lesson_id ? (string) get_post_meta( $lesson_id, '_lm_grade_scale', true ) : '0_20';

		if ( '' === $scale ) {
			$scale = '0_20';
		}

		switch ( $scale ) {
			case '0_10':
				return number_format( $score / 10, 1 );

			case '0_100':
				return number_format( $score, 1 );

			case 'a_f':
				if ( $score >= 90 ) { return 'A'; }
				if ( $score >= 80 ) { return 'B'; }
				if ( $score >= 70 ) { return 'C'; }
				if ( $score >= 60 ) { return 'D'; }
				return 'F';

			case 'logros':
				if ( $score >= 90 ) { return 'D'; }  // Destacado
				if ( $score >= 70 ) { return 'S'; }  // Satisfactorio
				if ( $score >= 50 ) { return 'EP'; } // En Proceso
				return 'I';                           // Inicio

			case '0_20':
			default:
				return number_format( $score / 5, 1 );
		}
	}

	/**
	 * Devuelve el máximo de la escala para mostrar (ej. "/20", "/100").
	 *
	 * @param string $scale Valor de _lm_grade_scale.
	 * @return string
	 */
	public static function grade_scale_label( $scale ) {
		$map = array(
			'0_10'   => '/10',
			'0_20'   => '/20',
			'0_100'  => '/100',
			'a_f'    => '',
			'logros' => '',
		);
		return isset( $map[ $scale ] ) ? $map[ $scale ] : '/20';
	}
}
