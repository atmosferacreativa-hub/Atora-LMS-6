<?php
/**
 * Bandas de nivel de una rúbrica (6.29.3): la regla de umbral de SpeedGrader
 * (6.26.5) en un solo lugar, para el panel del docente y la devolución que ve
 * el estudiante (web y app).
 *
 * Un puntaje exacto marca su nivel; entre dos niveles se resalta el de abajo
 * con la etiqueta "entre X y Y"; bajo el primero, "por debajo de X". El puntaje
 * se lee decimal, sin truncar.
 *
 * @package ATORA_LMS
 * @since 6.29.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLMS_Rubric_Level_Bands {

	/**
	 * Bandas por criterio (las mismas que SpeedGrader envía en `data-bands`).
	 *
	 * @param array $levels
	 * @param int   $max_points
	 * @return array<int,array{min:float,max:float,label:string,active_points:float|null,between:string,below:string}>
	 */
	public static function build( $levels, $max_points ) {
		$levels = is_array( $levels ) ? array_values( $levels ) : array();
		$max_points = absint( $max_points );
		if ( empty( $levels ) || $max_points <= 0 ) {
			return array();
		}

		$ordered = array();
		foreach ( $levels as $idx => $lv ) {
			$lv = is_array( $lv ) ? $lv : array();
			$ordered[] = array(
				'i'     => (int) $idx,
				'label' => sanitize_text_field( (string) ( $lv['label'] ?? '' ) ),
				'pts'   => (float) absint( $lv['points'] ?? 0 ),
			);
		}
		usort( $ordered, static function( $a, $b ) {
			if ( (float) $a['pts'] === (float) $b['pts'] ) {
				return (int) $a['i'] <=> (int) $b['i'];
			}
			return (float) $a['pts'] <=> (float) $b['pts'];
		} );

		// Niveles únicos por puntos, preservando el primero del schema.
		$unique = array();
		foreach ( $ordered as $row ) {
			$key = (string) $row['pts'];
			if ( isset( $unique[ $key ] ) ) {
				continue;
			}
			$unique[ $key ] = $row;
		}
		$unique = array_values( $unique );
		if ( empty( $unique ) ) {
			return array();
		}

		$eps = 0.0001;
		$bands = array();
		$first = $unique[0];

		if ( $first['pts'] > 0 ) {
			$bands[] = array(
				'min' => 0.0,
				'max' => max( 0.0, (float) $first['pts'] - $eps ),
				'label' => '',
				'active_points' => null,
				'between' => '',
				'below' => sprintf( __( 'por debajo de %s', 'atora-lms' ), $first['label'] ?: __( 'el primer nivel', 'atora-lms' ) ),
			);
		}

		// Exact match del primer nivel.
		$bands[] = array(
			'min' => (float) $first['pts'],
			'max' => (float) $first['pts'],
			'label' => (string) $first['label'],
			'active_points' => (float) $first['pts'],
			'between' => '',
			'below' => '',
		);

		for ( $j = 1; $j < count( $unique ); $j++ ) {
			$prev = $unique[ $j - 1 ];
			$cur  = $unique[ $j ];
			$prev_pts = (float) $prev['pts'];
			$cur_pts  = (float) $cur['pts'];

			// Entre niveles: resalta el de abajo.
			if ( $cur_pts - $prev_pts > $eps ) {
				$bands[] = array(
					'min' => $prev_pts + $eps,
					'max' => $cur_pts - $eps,
					'label' => (string) $prev['label'],
					'active_points' => (float) $prev_pts,
					'between' => sprintf(
						/* translators: 1: lower label, 2: upper label */
						__( 'entre %1$s y %2$s', 'atora-lms' ),
						$prev['label'] ?: __( 'nivel anterior', 'atora-lms' ),
						$cur['label'] ?: __( 'nivel siguiente', 'atora-lms' )
					),
					'below' => '',
				);
			}

			// Exact match del nivel actual.
			$bands[] = array(
				'min' => $cur_pts,
				'max' => $cur_pts,
				'label' => (string) $cur['label'],
				'active_points' => (float) $cur_pts,
				'between' => '',
				'below' => '',
			);
		}

		// Clamp de banda máxima.
		foreach ( $bands as &$b ) {
			$b['min'] = max( 0.0, (float) $b['min'] );
			$b['max'] = min( (float) $max_points, (float) $b['max'] );
		}
		unset( $b );

		return $bands;
	}

	/**
	 * Lo que muestra SpeedGrader para un puntaje: el nivel exacto, "entre X y Y"
	 * o "por debajo de X". Misma lectura que `syncActiveLevelFromValue()` del panel:
	 * gana la última banda que contiene el valor. Vacío si ninguna lo contiene.
	 */
	public static function describe( array $bands, float $value ): string {
		$match = null;
		foreach ( $bands as $band ) {
			if ( $value >= (float) $band['min'] && $value <= (float) $band['max'] ) {
				$match = $band;
			}
		}
		if ( null === $match ) {
			return '';
		}
		if ( '' !== (string) ( $match['below'] ?? '' ) ) {
			return (string) $match['below'];
		}
		if ( '' !== (string) ( $match['between'] ?? '' ) ) {
			return (string) $match['between'];
		}
		return (string) ( $match['label'] ?? '' );
	}

	/** Atajo: nivel (o "entre…") para un criterio con sus niveles y puntaje decimal. */
	public static function level_for( $levels, $max_points, float $value ): string {
		return self::describe( self::build( $levels, $max_points ), $value );
	}
}
