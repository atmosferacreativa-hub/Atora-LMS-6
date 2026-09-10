<?php
/**
 * Reglas puras del Gradebook institucional.
 *
 * Centraliza estados y validaciones para que REST, UI, CLI y procesos
 * automáticos apliquen exactamente las mismas reglas.
 *
 * @package ATORA_LMS
 * @since 6.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Institutional_Gradebook_Policy {

	const PERIOD_TRANSITIONS = array(
		'draft'    => array( 'open' ),
		'open'     => array( 'closed' ),
		'closed'   => array( 'archived' ),
		'archived' => array(),
	);

	const CYCLE_TRANSITIONS = array(
		'draft'     => array( 'open' ),
		'open'      => array( 'review' ),
		'review'    => array( 'open', 'published' ),
		'published' => array( 'closed' ),
		'closed'    => array(),
	);

	public static function can_transition_period( $from, $to ) {
		$from = sanitize_key( (string) $from );
		$to   = sanitize_key( (string) $to );

		return isset( self::PERIOD_TRANSITIONS[ $from ] )
			&& in_array( $to, self::PERIOD_TRANSITIONS[ $from ], true );
	}

	public static function can_transition_cycle( $from, $to ) {
		$from = sanitize_key( (string) $from );
		$to   = sanitize_key( (string) $to );

		return isset( self::CYCLE_TRANSITIONS[ $from ] )
			&& in_array( $to, self::CYCLE_TRANSITIONS[ $from ], true );
	}

	public static function cycle_accepts_grades( $status ) {
		return in_array( sanitize_key( (string) $status ), array( 'draft', 'open' ), true );
	}

	public static function cycle_accepts_rectifications( $status ) {
		return in_array( sanitize_key( (string) $status ), array( 'published', 'closed' ), true );
	}

	/**
	 * Valida y normaliza bandas de una escala.
	 *
	 * Las bandas deben cubrir todo el intervalo sin huecos ni solapamientos.
	 * Los límites son inclusivos; por eso se admite continuidad decimal.
	 *
	 * @param mixed $bands Bandas recibidas.
	 * @param float $minimum Mínimo de la escala.
	 * @param float $maximum Máximo de la escala.
	 * @return array|WP_Error
	 */
	public static function normalize_scale_bands( $bands, $minimum = 0.0, $maximum = 100.0 ) {
		$minimum = (float) $minimum;
		$maximum = (float) $maximum;

		if ( $maximum <= $minimum ) {
			return new WP_Error( 'clms_scale_invalid_range', __( 'El máximo de la escala debe ser mayor que el mínimo.', 'atora-lms' ) );
		}

		if ( ! is_array( $bands ) || empty( $bands ) ) {
			return new WP_Error( 'clms_scale_empty_bands', __( 'La escala debe contener al menos una banda.', 'atora-lms' ) );
		}

		$normalized = array();
		foreach ( $bands as $band ) {
			$band = is_array( $band ) ? $band : array();
			$label = sanitize_text_field( (string) ( $band['label'] ?? '' ) );
			$code  = sanitize_key( (string) ( $band['code'] ?? $label ) );
			$min   = isset( $band['min'] ) && is_numeric( $band['min'] ) ? (float) $band['min'] : null;
			$max   = isset( $band['max'] ) && is_numeric( $band['max'] ) ? (float) $band['max'] : null;

			if ( '' === $label || '' === $code || null === $min || null === $max || $max < $min ) {
				return new WP_Error( 'clms_scale_invalid_band', __( 'Una banda de la escala es inválida.', 'atora-lms' ) );
			}

			$normalized[] = array(
				'code'   => $code,
				'label'  => $label,
				'min'    => $min,
				'max'    => $max,
				'passed' => ! empty( $band['passed'] ),
			);
		}

		usort(
			$normalized,
			static function ( $left, $right ) {
				return $left['min'] <=> $right['min'];
			}
		);

		$tolerance = 0.00001;
		if ( abs( $normalized[0]['min'] - $minimum ) > $tolerance ) {
			return new WP_Error( 'clms_scale_uncovered_minimum', __( 'Las bandas no cubren el mínimo de la escala.', 'atora-lms' ) );
		}

		$last = count( $normalized ) - 1;
		if ( abs( $normalized[ $last ]['max'] - $maximum ) > $tolerance ) {
			return new WP_Error( 'clms_scale_uncovered_maximum', __( 'Las bandas no cubren el máximo de la escala.', 'atora-lms' ) );
		}

		for ( $index = 1; $index <= $last; $index++ ) {
			$previous_max = (float) $normalized[ $index - 1 ]['max'];
			$current_min  = (float) $normalized[ $index ]['min'];
			if ( $current_min <= $previous_max || ( $current_min - $previous_max ) > 1.00001 ) {
				return new WP_Error( 'clms_scale_discontinuous', __( 'Las bandas de la escala tienen huecos o solapamientos.', 'atora-lms' ) );
			}
		}

		return $normalized;
	}

	public static function resolve_band( $grade, $bands ) {
		if ( ! is_numeric( $grade ) || ! is_array( $bands ) ) {
			return array();
		}

		$value = (float) $grade;
		foreach ( $bands as $band ) {
			$band = is_array( $band ) ? $band : array();
			if ( isset( $band['min'], $band['max'] ) && $value >= (float) $band['min'] && $value <= (float) $band['max'] ) {
				return $band;
			}
		}

		return array();
	}

	public static function canonical_snapshot_hash( $records ) {
		$records = is_array( $records ) ? array_values( $records ) : array();
		usort(
			$records,
			static function ( $left, $right ) {
				return absint( $left['student_id'] ?? 0 ) <=> absint( $right['student_id'] ?? 0 );
			}
		);

		$canonical = array_map(
			static function ( $record ) {
				return array(
					'student_id' => absint( $record['student_id'] ?? 0 ),
					'grade'      => isset( $record['grade'] ) ? number_format( (float) $record['grade'], 4, '.', '' ) : null,
					'scale_code' => sanitize_key( (string) ( $record['scale_code'] ?? '' ) ),
					'status'     => sanitize_key( (string) ( $record['status'] ?? '' ) ),
					'revision'   => absint( $record['revision'] ?? 1 ),
				);
			},
			$records
		);

		return hash( 'sha256', (string) wp_json_encode( $canonical ) );
	}
}
