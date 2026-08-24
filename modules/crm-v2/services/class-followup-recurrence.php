<?php
/**
 * Followup_Recurrence — PT-1/PT-3 (sprint 6.6.0).
 *
 * Expande un recurrence_rule a una lista de fechas concretas dentro
 * de un rango. modules/calendar/class-calendar.php ya tiene una
 * columna `recurrence_rule` (VARCHAR) en atora_calendar_events desde
 * v5, pero NINGÚN código existente la interpreta hoy — se guarda,
 * nunca se expande (confirmado por búsqueda en todo modules/calendar/:
 * el único uso es sanitize_text_field() al guardar). Esta clase no
 * reemplaza nada existente porque no había nada que reemplazar; es la
 * pieza que faltaba, escrita una sola vez para que tanto los planes de
 * seguimiento (este sprint) como el propio Calendar (si algún sprint
 * futuro decide expandir recurrence_rule ahí también) puedan
 * reutilizarla sin duplicar el parser.
 *
 * Formato soportado (subconjunto deliberadamente pequeño de la
 * sintaxis RRULE de RFC 5545 — el nombre del campo ya la evoca, así
 * que se sigue esa convención en vez de inventar una propia; FIXED es
 * una extensión propia de Atora, RRULE no tiene ese FREQ):
 *
 *   WEEKLY;BYDAY=MO              — semanal, un día de la semana.
 *   WEEKLY;BYDAY=MO,WE,FR        — semanal, varios días.
 *   WEEKLY;BYDAY=MO;INTENSIFY_DAYS=14
 *                                 — semanal, y si se pasa una fecha de
 *                                   referencia (el end_date del plan)
 *                                   dentro del rango solicitado, agrega
 *                                   una ocurrencia extra a mitad de
 *                                   semana durante los últimos N días
 *                                   antes de esa fecha.
 *   DAILY;INTERVAL=3             — cada N días, contando desde el
 *                                   inicio del rango solicitado.
 *   FIXED;DATES=2026-09-01,2026-09-15
 *                                 — fechas puntuales, sin patrón
 *                                   automático (plantilla "Solo hitos").
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Followup_Recurrence {

	const WEEKDAYS = array( 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 0 );

	/**
	 * @param string      $rule                Ver formato soportado arriba.
	 * @param string      $range_start          Y-m-d, inclusive.
	 * @param string      $range_end            Y-m-d, inclusive.
	 * @param string|null $intensify_reference  Y-m-d — el end_date del plan, si tiene uno, para INTENSIFY_DAYS.
	 * @return array<int,string> Fechas Y-m-d, ordenadas, sin duplicados.
	 */
	public static function expand( string $rule, string $range_start, string $range_end, ?string $intensify_reference = null ): array {
		$rule = trim( $rule );
		if ( '' === $rule || ! self::is_valid_date( $range_start ) || ! self::is_valid_date( $range_end ) || $range_start > $range_end ) {
			return array();
		}

		$parts = self::parse_rule( $rule );
		$freq  = $parts['FREQ'] ?? '';

		switch ( $freq ) {
			case 'WEEKLY':
				return self::expand_weekly( $parts, $range_start, $range_end, $intensify_reference );
			case 'DAILY':
				return self::expand_daily( $parts, $range_start, $range_end );
			case 'FIXED':
				return self::expand_fixed( $parts, $range_start, $range_end );
			default:
				return array();
		}
	}

	/**
	 * @param string $rule
	 * @return array<string,string>
	 */
	private static function parse_rule( string $rule ): array {
		$parts = array();

		// Primer segmento sin '=' es el FREQ (WEEKLY;... / DAILY;... / FIXED;...).
		$segments = explode( ';', $rule );
		if ( ! empty( $segments ) && false === strpos( $segments[0], '=' ) ) {
			$parts['FREQ'] = strtoupper( trim( array_shift( $segments ) ) );
		}

		foreach ( $segments as $segment ) {
			$segment = trim( $segment );
			if ( '' === $segment || false === strpos( $segment, '=' ) ) {
				continue;
			}
			list( $key, $value ) = explode( '=', $segment, 2 );
			$parts[ strtoupper( trim( $key ) ) ] = trim( $value );
		}

		return $parts;
	}

	/**
	 * @param array<string,string> $parts
	 * @param string                $range_start
	 * @param string                $range_end
	 * @param string|null           $intensify_reference
	 * @return array<int,string>
	 */
	private static function expand_weekly( array $parts, string $range_start, string $range_end, ?string $intensify_reference ): array {
		$days = array_values( array_filter( array_map( 'trim', explode( ',', strtoupper( (string) ( $parts['BYDAY'] ?? 'MO' ) ) ) ) ) );
		if ( empty( $days ) ) {
			$days = array( 'MO' );
		}
		$weekday_numbers = array_values( array_filter( array_map( static fn( $d ) => self::WEEKDAYS[ $d ] ?? null, $days ), static fn( $n ) => null !== $n ) );
		if ( empty( $weekday_numbers ) ) {
			return array();
		}

		$dates = array();
		foreach ( self::each_day( $range_start, $range_end ) as $date ) {
			$dow = (int) gmdate( 'w', strtotime( $date ) );
			if ( in_array( $dow, $weekday_numbers, true ) ) {
				$dates[] = $date;
			}
		}

		$intensify_days = absint( $parts['INTENSIFY_DAYS'] ?? 0 );
		if ( $intensify_days > 0 && $intensify_reference && self::is_valid_date( $intensify_reference ) ) {
			$window_start = gmdate( 'Y-m-d', strtotime( $intensify_reference . " -{$intensify_days} days" ) );
			// Día "de refuerzo": Jueves (mitad de semana respecto a un
			// patrón anclado en Lunes) — elección simple y predecible,
			// documentada acá en vez de hacerla configurable (la OT pide
			// "opción de intensificar", no un segundo selector de día).
			foreach ( self::each_day( max( $range_start, $window_start ), min( $range_end, $intensify_reference ) ) as $date ) {
				if ( 4 === (int) gmdate( 'w', strtotime( $date ) ) && ! in_array( $date, $dates, true ) ) {
					$dates[] = $date;
				}
			}
		}

		sort( $dates );
		return array_values( array_unique( $dates ) );
	}

	/**
	 * @param array<string,string> $parts
	 * @param string                $range_start
	 * @param string                $range_end
	 * @return array<int,string>
	 */
	private static function expand_daily( array $parts, string $range_start, string $range_end ): array {
		$interval = max( 1, absint( $parts['INTERVAL'] ?? 1 ) );

		$dates = array();
		$i     = 0;
		foreach ( self::each_day( $range_start, $range_end ) as $date ) {
			if ( 0 === $i % $interval ) {
				$dates[] = $date;
			}
			++$i;
		}

		return $dates;
	}

	/**
	 * @param array<string,string> $parts
	 * @param string                $range_start
	 * @param string                $range_end
	 * @return array<int,string>
	 */
	private static function expand_fixed( array $parts, string $range_start, string $range_end ): array {
		$raw   = (string) ( $parts['DATES'] ?? '' );
		$dates = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );

		return array_values( array_filter(
			$dates,
			static fn( $d ) => self::is_valid_date( $d ) && $d >= $range_start && $d <= $range_end
		) );
	}

	/**
	 * Generador de fechas Y-m-d de $start a $end, ambos inclusive.
	 *
	 * @param string $start
	 * @param string $end
	 * @return \Generator<int,string>
	 */
	private static function each_day( string $start, string $end ) {
		$cursor = strtotime( $start );
		$limit  = strtotime( $end );
		if ( false === $cursor || false === $limit ) {
			return;
		}
		while ( $cursor <= $limit ) {
			yield gmdate( 'Y-m-d', $cursor );
			$cursor = strtotime( '+1 day', $cursor );
		}
	}

	/**
	 * @param string $value
	 * @return bool
	 */
	private static function is_valid_date( string $value ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
	}
}
