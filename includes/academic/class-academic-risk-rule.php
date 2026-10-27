<?php
/**
 * Regla canónica del riesgo académico (6.36.0).
 *
 * Una sola regla para todos los consumidores: estado académico (informes, gradebook,
 * CRM, perfil, analíticas de aprendizaje), SpeedGrader y la API docente de la app.
 * Antes había dos reglas con umbrales distintos (ver docs/ordenes/ORDEN-ANALITICAS.md).
 *
 * Señales (todas opcionales; una señal ausente no suma riesgo):
 * - average      ?int  Promedio 0–100. null = sin notas (null no es 0).
 * - missed       int   Entregas vencidas sin hacer (alerta early-warning abierta).
 * - pending      int   Actividades pendientes.
 * - progress     ?int  Progreso 0–100.
 * - total_lessons int  Lecciones del curso (sin lecciones, el progreso no cuenta).
 * - enrolled_days ?int Días desde la inscripción. null = desconocido: el progreso cuenta, "nunca activo" no.
 * - last_activity_ts ?int Última actividad registrada (web o app). null = nunca.
 * - now          int   Marca de tiempo actual (para pruebas).
 *
 * Niveles: `normal`, `medium`, `high`. Motivos: lista de `{code, text}` en orden de gravedad.
 *
 * @package ATORA_LMS
 * @since 6.36.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Academic_Risk_Rule {

	const LEVELS = array( 'normal' => 0, 'medium' => 1, 'high' => 2 );

	/** Traducción al vocabulario de `/teacher/*` y SpeedGrader (6.31.0). */
	const LEGACY_LEVELS = array( 'normal' => 'bajo', 'medium' => 'medio', 'high' => 'alto' );

	const DEFAULT_GRACE_DAYS      = 14;
	const DEFAULT_INACTIVITY_DAYS = 14;

	/**
	 * @param array<string,mixed> $signals
	 * @return array{level:string,reasons:array<int,array{code:string,text:string}>,inactive:bool}
	 */
	public static function evaluate( array $signals ): array {
		$now           = isset( $signals['now'] ) ? (int) $signals['now'] : time();
		$average       = isset( $signals['average'] ) && is_numeric( $signals['average'] ) ? (int) round( (float) $signals['average'] ) : null;
		$missed        = max( 0, (int) ( $signals['missed'] ?? 0 ) );
		$pending       = max( 0, (int) ( $signals['pending'] ?? 0 ) );
		$progress      = isset( $signals['progress'] ) && is_numeric( $signals['progress'] ) ? max( 0, min( 100, (int) $signals['progress'] ) ) : null;
		$total_lessons = max( 0, (int) ( $signals['total_lessons'] ?? 0 ) );
		$enrolled_days = isset( $signals['enrolled_days'] ) && is_numeric( $signals['enrolled_days'] ) ? max( 0, (int) $signals['enrolled_days'] ) : null;
		$last_ts       = isset( $signals['last_activity_ts'] ) && is_numeric( $signals['last_activity_ts'] ) && (int) $signals['last_activity_ts'] > 0 ? (int) $signals['last_activity_ts'] : null;

		$grace_days      = self::grace_days();
		$inactivity_days = self::inactivity_days();
		// Progreso: inscripción desconocida (datos antiguos) cuenta como fuera de gracia.
		$past_grace = null === $enrolled_days || $enrolled_days >= $grace_days;
		// "Nunca registró actividad" solo cuenta con una inscripción conocida y pasada la gracia: sin datos no se castiga.
		$never_active_counts = null !== $enrolled_days && $enrolled_days >= $grace_days;

		$level   = 'normal';
		$reasons = array();
		$raise   = static function ( string $to ) use ( &$level ): void {
			if ( self::LEVELS[ $to ] > self::LEVELS[ $level ] ) {
				$level = $to;
			}
		};

		// Promedio (solo con notas).
		if ( null !== $average && $average < 50 ) {
			$raise( 'high' );
			$reasons[] = self::reason( 'low_average', sprintf( __( 'Promedio %d/100, por debajo de 50.', 'atora-lms' ), $average ) );
		} elseif ( null !== $average && $average < 70 ) {
			$raise( 'medium' );
			$reasons[] = self::reason( 'attention_average', sprintf( __( 'Promedio %d/100, en zona de atención.', 'atora-lms' ), $average ) );
		}

		// Entregas vencidas sin hacer.
		if ( $missed > 0 ) {
			$raise( $missed >= 2 ? 'high' : 'medium' );
			$reasons[] = self::reason(
				'missed_submissions',
				1 === $missed ? __( '1 entrega vencida sin hacer.', 'atora-lms' ) : sprintf( __( '%d entregas vencidas sin hacer.', 'atora-lms' ), $missed )
			);
		}

		// Pendientes acumulados.
		if ( $pending >= 3 ) {
			$raise( 'medium' );
			$reasons[] = self::reason( 'pending_activities', sprintf( __( '%d actividades pendientes.', 'atora-lms' ), $pending ) );
		}

		// Progreso: solo pasado el período de gracia y si el curso tiene lecciones.
		if ( null !== $progress && $total_lessons > 0 && $past_grace && $progress < 30 ) {
			$raise( 'medium' );
			$reasons[] = self::reason( 'low_progress', sprintf( __( 'Progreso %d%% en el curso.', 'atora-lms' ), $progress ) );
		}

		// Inactividad: web o app. Nunca registrada solo cuenta pasado el período de gracia.
		$inactive = false;
		if ( null === $last_ts ) {
			$inactive = $never_active_counts;
		} else {
			$inactive = ( $now - $last_ts ) >= $inactivity_days * DAY_IN_SECONDS;
		}
		if ( $inactive ) {
			$combined = $pending >= 2 || ( null !== $progress && $total_lessons > 0 && $progress < 50 );
			$raise( $combined ? 'high' : 'medium' );
			$reasons[] = self::reason(
				'inactive',
				null === $last_ts
					? __( 'Sin actividad registrada desde la inscripción (la actividad sin conexión de la app se registra al sincronizar).', 'atora-lms' )
					: sprintf( __( 'Sin actividad registrada en %d días (la actividad sin conexión de la app se registra al sincronizar).', 'atora-lms' ), (int) floor( ( $now - $last_ts ) / DAY_IN_SECONDS ) )
			);
		}

		return array(
			'level'    => $level,
			'reasons'  => $reasons,
			'inactive' => $inactive,
		);
	}

	/**
	 * Señales a partir del estado académico de un estudiante en un curso. Es el único lugar
	 * donde se reúnen: el estado académico y la API docente pasan por aquí.
	 *
	 * @param array<string,mixed> $status Claves usadas: final_average, pending_activities,
	 *                                    progress_percent, total_lessons, course_last_access_at,
	 *                                    last_access_at.
	 * @return array<string,mixed>
	 */
	public static function signals_from_status( int $student_id, int $wp_course_id, array $status, ?int $now = null ): array {
		$now  = $now ?? time();
		$last = (string) ( $status['course_last_access_at'] ?? '' );
		if ( '' === trim( $last ) ) {
			$last = (string) ( $status['last_access_at'] ?? '' );
		}

		return array(
			'average'          => isset( $status['final_average'] ) && is_numeric( $status['final_average'] ) ? (float) $status['final_average'] : null,
			'missed'           => self::missed_submissions( $student_id, $wp_course_id ),
			'pending'          => absint( $status['pending_activities'] ?? 0 ),
			'progress'         => isset( $status['progress_percent'] ) && is_numeric( $status['progress_percent'] ) ? (int) $status['progress_percent'] : null,
			'total_lessons'    => absint( $status['total_lessons'] ?? 0 ),
			'enrolled_days'    => self::enrolled_days( $student_id, $wp_course_id, $now ),
			'last_activity_ts' => self::local_mysql_to_ts( $last ),
			'now'              => $now,
		);
	}

	/** Textos de los motivos, en orden. */
	public static function reason_texts( array $evaluation ): array {
		return array_values( array_map( static fn( $r ) => (string) $r['text'], (array) ( $evaluation['reasons'] ?? array() ) ) );
	}

	public static function legacy_level( string $level ): string {
		return self::LEGACY_LEVELS[ $level ] ?? 'bajo';
	}

	public static function grace_days(): int {
		$days = function_exists( 'get_option' ) ? (int) get_option( 'atora_risk_grace_days', self::DEFAULT_GRACE_DAYS ) : self::DEFAULT_GRACE_DAYS;
		if ( function_exists( 'apply_filters' ) ) {
			$days = (int) apply_filters( 'atora_risk_grace_days', $days );
		}
		return $days > 0 ? $days : self::DEFAULT_GRACE_DAYS;
	}

	public static function inactivity_days(): int {
		$days = function_exists( 'get_option' ) ? (int) get_option( 'clms_inactivity_days_threshold', self::DEFAULT_INACTIVITY_DAYS ) : self::DEFAULT_INACTIVITY_DAYS;
		if ( function_exists( 'apply_filters' ) ) {
			$days = (int) apply_filters( 'clms_inactivity_days_threshold', $days );
		}
		return $days > 0 ? $days : self::DEFAULT_INACTIVITY_DAYS;
	}

	/** Entregas vencidas según la alerta abierta de early-warning (antes en ATORA_Student_Risk_Service). */
	public static function missed_submissions( int $student_id, int $wp_course_id ): int {
		global $wpdb;
		if ( ! $wpdb || ! $student_id || ! $wp_course_id ) {
			return 0;
		}
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

	/** Días desde la inscripción vigente; null si no se conoce. */
	public static function enrolled_days( int $student_id, int $wp_course_id, ?int $now = null ): ?int {
		$now = $now ?? time();
		$ts  = class_exists( 'CLMS_Drip' ) && method_exists( 'CLMS_Drip', 'enrollment_timestamp' )
			? (int) CLMS_Drip::enrollment_timestamp( $student_id, $wp_course_id )
			: 0;
		if ( $ts <= 0 ) {
			return null;
		}
		return max( 0, (int) floor( ( $now - $ts ) / DAY_IN_SECONDS ) );
	}

	/** Convierte una fecha guardada (hora del sitio, `current_time( 'mysql' )`) en marca de tiempo. */
	public static function local_mysql_to_ts( string $value ): ?int {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		if ( function_exists( 'wp_timezone' ) ) {
			try {
				$dt = new DateTimeImmutable( $value, wp_timezone() );
				return $dt->getTimestamp();
			} catch ( Exception $e ) {
				return null;
			}
		}
		$ts = strtotime( $value );
		return false === $ts ? null : $ts;
	}

	private static function reason( string $code, string $text ): array {
		return array( 'code' => $code, 'text' => $text );
	}
}
