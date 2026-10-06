<?php
/**
 * Riesgo de un estudiante en un curso (6.31.0), para la web y `/teacher/*`.
 *
 * Combina:
 * - early-warning: entregas vencidas sin hacer (`atora_early_warning`, abiertas);
 * - el resumen de notas (`CLMS_Grading::get_student_course_status()`): nota
 *   acumulada y actividades pendientes.
 *
 * Nivel: `alto`, `medio` o `bajo`, el más alto que dé cualquiera de las fuentes,
 * con motivos legibles ("2 entregas vencidas", "nota acumulada 48/100").
 * Sin notas no es riesgo por nota (6.29.5: `null` no es 0).
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
	 * @return array{level:string,label:string,reasons:string[],missed:int,average:?int,pending:int}
	 */
	public static function for_student( int $student_id, int $wp_course_id, ?array $status = null ): array {
		$missed = self::missed_submissions( $student_id, $wp_course_id );
		if ( null === $status ) {
			$grading = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Grading' ) : null;
			$status  = $grading && method_exists( $grading, 'get_student_course_status' ) ? (array) $grading->get_student_course_status( $student_id, $wp_course_id ) : array();
		}
		$average = isset( $status['final_average'] ) && is_numeric( $status['final_average'] ) ? (int) $status['final_average'] : null;
		$pending = absint( $status['pending_activities'] ?? 0 );

		return self::evaluate( $missed, $average, $pending );
	}

	/** Regla pura (probada aparte). */
	public static function evaluate( int $missed, ?int $average, int $pending ): array {
		$level   = 'bajo';
		$reasons = array();
		$raise   = static function ( string $to ) use ( &$level ) {
			if ( self::LEVELS[ $to ] > self::LEVELS[ $level ] ) {
				$level = $to;
			}
		};

		if ( $missed > 0 ) {
			$raise( $missed >= 2 ? 'alto' : 'medio' );
			$reasons[] = 1 === $missed ? '1 entrega vencida' : sprintf( '%d entregas vencidas', $missed );
		}
		if ( null !== $average && $average < 70 ) {
			$raise( $average < 60 ? 'alto' : 'medio' );
			$reasons[] = sprintf( 'nota acumulada %d/100', $average );
		}
		if ( $pending >= 3 ) {
			$raise( 'medio' );
			$reasons[] = sprintf( '%d actividades pendientes', $pending );
		}

		return array(
			'level'   => $level,
			'label'   => self::LABELS[ $level ],
			'reasons' => $reasons,
			'missed'  => $missed,
			'average' => $average,
			'pending' => $pending,
		);
	}

	/** Entregas vencidas según la alerta abierta de early-warning. */
	public static function missed_submissions( int $student_id, int $wp_course_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_early_warning';
		$row   = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT data FROM {$table} WHERE course_id = %d AND user_id = %d AND warning_type = 'missed_submission' AND status = 'open' LIMIT 1",
			$wp_course_id,
			$student_id
		), ARRAY_A );
		if ( ! $row ) {
			return 0;
		}
		$data = json_decode( (string) $row['data'], true );
		return absint( is_array( $data ) ? ( $data['count'] ?? count( (array) ( $data['missed'] ?? array() ) ) ) : 0 );
	}
}
