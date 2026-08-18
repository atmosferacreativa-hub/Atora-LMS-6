<?php
/**
 * Normalización de notas del Gradebook.
 *
 * Separa claramente:
 *   raw_score    → puntos obtenidos (ej: 16 de 20)
 *   max_points   → puntos máximos de la actividad (ej: 20)
 *   percent_score → nota normalizada 0–100 (ej: 80)
 *   weighted_score → aporte ponderado al total (ej: 80 × 30% = 24)
 *   final_score  → nota final del estudiante en el curso (0–100)
 *
 * Regla obligatoria:
 *   El cálculo ponderado se hace sobre percent_score, no sobre raw_score.
 *   Ejemplo: 16/20 = 80%, 80% × peso 30% = 24 puntos al total.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Gradebook_Normalizer {

	/**
	 * Normaliza una nota cruda a porcentaje 0–100.
	 *
	 * Si max_points = 0 o null, la nota se trata directamente como porcentaje (legado).
	 *
	 * @param mixed $raw_score  Puntos obtenidos.
	 * @param mixed $max_points Puntos máximos.
	 * @return float Porcentaje 0–100, o -1 si no hay nota.
	 */
	public static function to_percent( $raw_score, $max_points = 0 ): float {
		if ( '' === (string) $raw_score || null === $raw_score ) {
			return -1.0; // Sin nota.
		}

		$raw   = (float) $raw_score;
		$max   = (float) $max_points;

		if ( $max <= 0 ) {
			// Sin max_points definido: tratar raw como porcentaje legado.
			return max( 0.0, min( 100.0, $raw ) );
		}

		return max( 0.0, min( 100.0, ( $raw / $max ) * 100 ) );
	}

	/**
	 * Construye el mapa de scores normalizado para una celda.
	 *
	 * @param mixed $raw_score  Puntos obtenidos (puede ser porcentaje en sistema legado).
	 * @param mixed $max_points Puntos máximos de la actividad.
	 * @return array {raw_score, max_points, percent_score}
	 */
	public static function cell_scores( $raw_score, $max_points = 0 ): array {
		$max = absint( $max_points );

		if ( '' === (string) $raw_score || null === $raw_score ) {
			return array(
				'raw_score'    => '',
				'max_points'   => $max,
				'percent_score' => -1.0,
			);
		}

		$raw = (float) $raw_score;

		// Si no hay max_points, la nota ya es porcentaje (sistema legado).
		if ( $max <= 0 ) {
			return array(
				'raw_score'    => $raw,
				'max_points'   => 0,
				'percent_score' => max( 0.0, min( 100.0, $raw ) ),
			);
		}

		$percent = max( 0.0, min( 100.0, ( $raw / $max ) * 100 ) );

		return array(
			'raw_score'    => $raw,
			'max_points'   => $max,
			'percent_score' => round( $percent, 2 ),
		);
	}

	/**
	 * Calcula el total ponderado de un estudiante usando percent_score.
	 *
	 * @param array $cells   Celdas del estudiante [{lesson_id => {..., percent_score, ...}}].
	 * @param array $columns Columnas [{lesson_id, weight_group, max_points}].
	 * @param array $schema  Esquema del curso con weight_groups.
	 * @return array {final_score: float, weighted_scores: [], groups_used: []}
	 *               o ['final_score' => -1.0] si no hay datos suficientes.
	 */
	public static function calculate_weighted_total( array $cells, array $columns, array $schema ): array {
		$groups = is_array( $schema['weight_groups'] ?? null ) ? $schema['weight_groups'] : array();

		if ( empty( $groups ) || empty( $columns ) ) {
			return array( 'final_score' => -1.0, 'groups_used' => array() );
		}

		// Agrupar percent_scores por grupo.
		$per_group = array();
		foreach ( $columns as $column ) {
			$column    = is_array( $column ) ? $column : array();
			$lesson_id = absint( $column['lesson_id'] ?? 0 );
			$group_key = sanitize_key( (string) ( $column['weight_group'] ?? '' ) );

			if ( ! $lesson_id || '' === $group_key || ! isset( $groups[ $group_key ] ) ) {
				continue;
			}

			$cell       = isset( $cells[ $lesson_id ] ) && is_array( $cells[ $lesson_id ] ) ? $cells[ $lesson_id ] : array();
			$max_points = absint( $column['max_points'] ?? ( $cell['max_points'] ?? 0 ) );
			$raw_score  = $cell['raw_score'] ?? ( $cell['grade'] ?? '' );

			$scores      = self::cell_scores( $raw_score, $max_points );
			$pct         = $scores['percent_score'];

			if ( $pct < 0 ) {
				// Sin nota: ignorar en el promedio del grupo.
				continue;
			}

			if ( ! isset( $per_group[ $group_key ] ) ) {
				$per_group[ $group_key ] = array( 'total_pct' => 0.0, 'count' => 0 );
			}
			$per_group[ $group_key ]['total_pct'] += $pct;
			++$per_group[ $group_key ]['count'];
		}

		if ( empty( $per_group ) ) {
			return array( 'final_score' => -1.0, 'groups_used' => array() );
		}

		$weighted_total = 0.0;
		$weights_used   = 0.0;
		$groups_used    = array();

		foreach ( $per_group as $group_key => $stats ) {
			$weight = (float) ( $groups[ $group_key ]['weight'] ?? 0 );
			if ( $weight <= 0 || empty( $stats['count'] ) ) {
				continue;
			}

			$group_avg       = $stats['total_pct'] / max( 1, $stats['count'] );
			$weighted_contribution = $group_avg * ( $weight / 100.0 );
			$weighted_total += $weighted_contribution;
			$weights_used   += $weight;

			$groups_used[ $group_key ] = array(
				'avg_percent'   => round( $group_avg, 2 ),
				'weight'        => $weight,
				'contribution'  => round( $weighted_contribution, 2 ),
			);
		}

		if ( $weights_used <= 0 ) {
			return array( 'final_score' => -1.0, 'groups_used' => array() );
		}

		// Si los pesos no suman 100%, normalizar proporcionalmente.
		if ( abs( 100.0 - $weights_used ) > 0.01 ) {
			$weighted_total = $weighted_total * ( 100.0 / $weights_used );
		}

		return array(
			'final_score' => round( max( 0.0, min( 100.0, $weighted_total ) ), 2 ),
			'groups_used' => $groups_used,
		);
	}
}
