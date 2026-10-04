<?php
/**
 * Posición de reproducción por usuario y lección (API móvil, 6.28.0).
 *
 * Regla (docs/SINCRONIZACION-OFFLINE.md): gana la marca más reciente según
 * `client_recorded_at`, aunque la posición sea menor (el estudiante puede
 * volver atrás a repasar). Lo monótono es la lección completada, no la posición.
 * Una marca más de 5 minutos en el futuro se acota. Reenviar el mismo
 * `client_event_id` no cambia nada.
 *
 * @package ATORA_LMS
 * @since 6.28.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Mobile_Position_Service {
	/** Un reloj adelantado más de esto no puede fijar la posición para siempre. */
	const MAX_CLOCK_SKEW_MS = 5 * 60 * 1000;

	/**
	 * @return array{applied:bool, replayed:bool, position:array{position_seconds:int, duration_seconds:int, client_recorded_at:string}}
	 */
	public static function save( int $user_id, int $lesson_id, int $course_id, int $position, int $duration, string $event_id, int $recorded_ms ): array {
		global $wpdb;
		$table       = $wpdb->prefix . 'atora_lesson_positions';
		$recorded_ms = min( $recorded_ms, (int) floor( microtime( true ) * 1000 ) + self::MAX_CLOCK_SKEW_MS );
		$data        = array(
			'institution_id'     => self::institution_id( $course_id ),
			'user_id'            => $user_id,
			'lesson_id'          => $lesson_id,
			'course_id'          => $course_id,
			'position_seconds'   => $position,
			'duration_seconds'   => $duration,
			'client_event_id'    => $event_id,
			'client_recorded_ms' => $recorded_ms,
			'updated_at'         => current_time( 'mysql', true ),
		);

		// 1) Primera marca de este usuario en esta lección.
		$inserted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (institution_id, user_id, lesson_id, course_id, position_seconds, duration_seconds, client_event_id, client_recorded_ms, updated_at)
				 VALUES (%d, %d, %d, %d, %d, %d, %s, %d, %s)",
				array_values( $data )
			)
		);
		if ( 1 === (int) $inserted ) {
			return array( 'applied' => true, 'replayed' => false, 'position' => self::shape( $data ) );
		}

		// 2) Solo reemplaza si es más reciente (desempate estable por client_event_id).
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table}
				 SET position_seconds = %d, duration_seconds = %d, client_event_id = %s, client_recorded_ms = %d, updated_at = %s
				 WHERE user_id = %d AND lesson_id = %d
				   AND ( client_recorded_ms < %d OR ( client_recorded_ms = %d AND client_event_id < %s ) )",
				$position, $duration, $event_id, $recorded_ms, $data['updated_at'],
				$user_id, $lesson_id,
				$recorded_ms, $recorded_ms, $event_id
			)
		);

		$current = self::get( $user_id, $lesson_id );
		return array(
			'applied'  => 1 === (int) $updated,
			'replayed' => 1 !== (int) $updated && $current && $current['client_event_id'] === $event_id,
			'position' => self::shape( $current ?: $data ),
		);
	}

	public static function get( int $user_id, int $lesson_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_lesson_positions WHERE user_id = %d AND lesson_id = %d", $user_id, $lesson_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function resume_seconds( int $user_id, int $lesson_id ): int {
		$row = self::get( $user_id, $lesson_id );
		return $row ? absint( $row['position_seconds'] ) : 0;
	}

	/** ISO 8601 → milisegundos UTC; null si no es válida. */
	public static function parse_recorded_at( string $value ): ?int {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		try {
			$date = new DateTimeImmutable( $value );
		} catch ( Exception $e ) {
			return null;
		}
		return (int) $date->format( 'U' ) * 1000 + (int) floor( (int) $date->format( 'u' ) / 1000 );
	}

	private static function shape( array $row ): array {
		$ms = (int) ( $row['client_recorded_ms'] ?? 0 );
		return array(
			'position_seconds'   => absint( $row['position_seconds'] ?? 0 ),
			'duration_seconds'   => absint( $row['duration_seconds'] ?? 0 ),
			'client_recorded_at' => gmdate( 'Y-m-d\TH:i:s', intdiv( $ms, 1000 ) ) . sprintf( '.%03dZ', $ms % 1000 ),
		);
	}

	private static function institution_id( int $course_id ): int {
		global $wpdb;
		return absint( $wpdb->get_var( $wpdb->prepare( "SELECT institution_id FROM {$wpdb->prefix}atora_courses WHERE id = %d", $course_id ) ) );
	}
}
