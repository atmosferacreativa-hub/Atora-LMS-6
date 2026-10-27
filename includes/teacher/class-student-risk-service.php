<?php
/**
 * Riesgo de un estudiante en un curso, para la web docente, SpeedGrader y `/teacher/*`.
 *
 * Desde 6.36.0 no tiene regla propia: traduce la regla canónica `ATORA_Academic_Risk_Rule`
 * (la misma que usa el estado académico, los informes y las analíticas) al vocabulario de la
 * API docente: `alto`, `medio` o `bajo`, con motivos legibles. Sin notas no es riesgo por nota
 * (6.29.5: `null` no es 0).
 *
 * @package ATORA_LMS
 * @since 6.31.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Student_Risk_Service {

	const LEVELS = array( 'bajo' => 0, 'medio' => 1, 'alto' => 2 );

	const LABELS = array(
		'bajo'  => 'Sin riesgo',
		'medio' => 'Riesgo medio',
		'alto'  => 'Riesgo alto',
	);

	/**
	 * @param array|null $status Estado académico (`get_student_course_status()`); si falta, se calcula.
	 * @return array{level:string,label:string,reasons:string[],codes:string[],missed:int,average:?int,pending:int}
	 */
	public static function for_student( int $student_id, int $wp_course_id, ?array $status = null ): array {
		if ( null === $status ) {
			$grading = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Grading' ) : null;
			$status  = $grading && method_exists( $grading, 'get_student_course_status' ) ? (array) $grading->get_student_course_status( $student_id, $wp_course_id ) : array();
		}

		$signals = ATORA_Academic_Risk_Rule::signals_from_status( $student_id, $wp_course_id, $status );
		return self::from_evaluation( ATORA_Academic_Risk_Rule::evaluate( $signals ), $signals );
	}

	/**
	 * Regla con solo vencidas, promedio y pendientes (sin inactividad ni progreso).
	 * Se mantiene por compatibilidad; delega en la regla canónica.
	 */
	public static function evaluate( int $missed, ?int $average, int $pending ): array {
		$signals = array(
			'missed'           => $missed,
			'average'          => $average,
			'pending'          => $pending,
			'last_activity_ts' => time(),
		);
		return self::from_evaluation( ATORA_Academic_Risk_Rule::evaluate( $signals ), $signals );
	}

	/** Entregas vencidas según la alerta abierta de early-warning. */
	public static function missed_submissions( int $student_id, int $wp_course_id ): int {
		return ATORA_Academic_Risk_Rule::missed_submissions( $student_id, $wp_course_id );
	}

	private static function from_evaluation( array $evaluation, array $signals ): array {
		$level = ATORA_Academic_Risk_Rule::legacy_level( (string) $evaluation['level'] );
		return array(
			'level'   => $level,
			'label'   => self::LABELS[ $level ],
			'reasons' => ATORA_Academic_Risk_Rule::reason_texts( $evaluation ),
			'codes'   => array_values( array_map( static fn( $r ) => (string) $r['code'], (array) $evaluation['reasons'] ) ),
			'missed'  => (int) ( $signals['missed'] ?? 0 ),
			'average' => isset( $signals['average'] ) && is_numeric( $signals['average'] ) ? (int) round( (float) $signals['average'] ) : null,
			'pending' => (int) ( $signals['pending'] ?? 0 ),
		);
	}
}
