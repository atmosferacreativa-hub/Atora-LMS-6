<?php
/**
 * Promedio del curso (6.29.5): una sola regla para el motor de evaluación y
 * el resumen de CLMS_Grading.
 *
 * Qué se combina se decide por **cantidad de notas existentes**, no por el
 * valor del promedio: un quiz en 0 es una nota, no "sin notas". Tres estados:
 * `null` (sin notas), `0` (cero) y nota positiva. Un quiz no intentado o una
 * tarea no calificada no llegan aquí: solo se pasan las notas que existen.
 *
 * La nota es entera 0–100 (decisión de escala vigente, docs/GRADE-SCALE.md).
 *
 * @package ATORA_LMS
 * @since 6.29.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLMS_Grade_Average {

	/** Promedio entero 0–100, o null si no hay notas. */
	public static function average( array $scores ): ?int {
		$scores = array_values( array_filter( $scores, static fn( $v ) => is_numeric( $v ) ) );
		if ( empty( $scores ) ) {
			return null;
		}
		$scores = array_map( static fn( $v ) => max( 0, min( 100, (float) $v ) ), $scores );
		return (int) round( array_sum( $scores ) / count( $scores ) );
	}

	/**
	 * @param array $quiz_scores       Notas de quizzes que existen.
	 * @param array $assignment_scores Notas de tareas liberadas.
	 * @return array{quiz_average:?int, assignment_average:?int, final_average:?int}
	 */
	public static function combine( array $quiz_scores, array $assignment_scores ): array {
		$quiz = self::average( $quiz_scores );
		$task = self::average( $assignment_scores );

		if ( null !== $quiz && null !== $task ) {
			$final = (int) round( ( $quiz + $task ) / 2 );
		} else {
			$final = $quiz ?? $task;
		}

		return array(
			'quiz_average'       => $quiz,
			'assignment_average' => $task,
			'final_average'      => $final,
		);
	}
}
