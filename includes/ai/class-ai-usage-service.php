<?php
/**
 * IA (6.32.0): funciones activadas, límites y registro de uso.
 *
 * - Funciones por academia (`assistant`, `grading_suggestion`): desactivadas
 *   hasta que el administrador las active. Una función está disponible solo si
 *   está activada y hay un proveedor configurado.
 * - Límites: preguntas por estudiante y día (30), sugerencias por docente y día
 *   (100) y tope mensual de costo estimado por academia (vacío = sin tope).
 *   Al pasarse: 429 con la hora de reinicio. Al 80 % del tope mensual, aviso al
 *   administrador por el buzón (una vez por mes).
 * - Registro en `atora_ai_usage`: institución, usuario, función, proveedor,
 *   modelo, tokens, costo estimado y resultado (ok, error, límite). La web y la
 *   app comparten el mismo contador.
 * - 6.33.1 (E.5): cada llamada reserva su consumo antes de salir (fila
 *   `reserved`, creada bajo un bloqueo por academia) y lo cierra en ok o error.
 *   Lo reservado cuenta para el límite diario y, con su costo estimado, para el
 *   tope mensual; una reserva sin cerrar caduca a los 5 minutos.
 *
 * @package ATORA_LMS
 * @since 6.32.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_AI_Usage_Service {

	const ASSISTANT  = 'assistant';
	const SUGGESTION = 'grading_suggestion';

	const FEATURES_OPTION = 'atora_ai_features';
	const LIMITS_OPTION   = 'atora_ai_limits';
	const ALERT_OPTION    = 'atora_ai_budget_alerted';

	/**
	 * 6.32.1: funciones de procesamiento masivo (indexar la base de conocimiento,
	 * transcribir clases). Solo las frena el tope mensual: un límite diario por
	 * persona cortaría una indexación a medias.
	 */
	const BULK_FEATURES = array( 'embeddings', 'knowledge_base', 'transcription' );

	/** Nombres para el panel de consumo (lo no listado se muestra con su clave). */
	const LABELS = array(
		'assistant'          => 'Asistente del estudiante',
		'grading_suggestion' => 'Sugerencia de calificación',
		'ai_review'          => 'Revisión con IA (SpeedGrader)',
		'ai_grading'         => 'Corrección con IA',
		'alerts'             => 'Alertas',
		'messaging'          => 'Mensajes automáticos',
		'sentiment'          => 'Sentimiento',
		'feedback_loop'      => 'Retroalimentación',
		'improvement_plan'   => 'Plan de mejora',
		'exams'              => 'Exámenes con IA',
		'copilot'            => 'Copilotos',
		'quick_wins'         => 'Quick wins',
		'learning_path'      => 'Ruta de aprendizaje',
		'teacher_assistant'  => 'Asistente docente',
		'settings_test'      => 'Prueba de conexión (ajustes)',
		'assessment'         => 'Motor de evaluación',
		'analytics'          => 'Analítica',
		'crm'                => 'CRM',
		'embeddings'         => 'Base de conocimiento (indexación)',
		'knowledge_base'     => 'Base de conocimiento (búsqueda)',
		'transcription'      => 'Transcripción',
		'other'              => 'Otras',
	);

	/** Segundos que vive una reserva sin cerrar. */
	const RESERVATION_TTL = 300;

	const DEFAULT_LIMITS = array(
		'student_daily'    => 30,
		'teacher_daily'    => 100,
		'monthly_cost_cap' => '',
	);

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_ai_usage';
	}

	public static function features(): array {
		$saved = get_option( self::FEATURES_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			self::ASSISTANT  => ! empty( $saved[ self::ASSISTANT ] ),
			self::SUGGESTION => ! empty( $saved[ self::SUGGESTION ] ),
		);
	}

	public static function set_features( array $features ): void {
		update_option( self::FEATURES_OPTION, array(
			self::ASSISTANT  => ! empty( $features[ self::ASSISTANT ] ),
			self::SUGGESTION => ! empty( $features[ self::SUGGESTION ] ),
		), false );
	}

	public static function limits(): array {
		$saved  = get_option( self::LIMITS_OPTION, array() );
		$saved  = is_array( $saved ) ? $saved : array();
		$limits = array_merge( self::DEFAULT_LIMITS, array_intersect_key( $saved, self::DEFAULT_LIMITS ) );
		$limits['student_daily']    = max( 0, (int) $limits['student_daily'] );
		$limits['teacher_daily']    = max( 0, (int) $limits['teacher_daily'] );
		$limits['monthly_cost_cap'] = '' === (string) $limits['monthly_cost_cap'] ? '' : max( 0.0, (float) $limits['monthly_cost_cap'] );
		return $limits;
	}

	public static function set_limits( array $limits ): void {
		$clean = array_intersect_key( $limits, self::DEFAULT_LIMITS );
		update_option( self::LIMITS_OPTION, array_merge( self::limits(), $clean ), false );
	}

	/** ¿Hay un proveedor de IA utilizable? (en pruebas, el simulado). */
	public static function provider_configured(): bool {
		// Sin el módulo de IA activo no hay gestor que atienda las llamadas.
		if ( class_exists( 'CLMS_Module_Registry' ) && ! CLMS_Module_Registry::is_active( 'ai' ) ) {
			return false;
		}
		if ( class_exists( 'ATORA_AI_Fake_Provider' ) && ATORA_AI_Fake_Provider::enabled() ) {
			return true;
		}
		return class_exists( 'CLMS_AI_Settings_Service' ) && CLMS_AI_Settings_Service::has_any_generation_key();
	}

	/** Función activada y con proveedor: la declara `/discovery` y responden sus rutas. */
	public static function available( string $feature ): bool {
		return ! empty( self::features()[ $feature ] ) && self::provider_configured();
	}

	private static function institution(): int {
		if ( class_exists( '\\ATORA\\LMS\\Tenant_Context' ) ) {
			$inst = \ATORA\LMS\Tenant_Context::current_institution_id();
			if ( ! is_wp_error( $inst ) ) {
				return absint( $inst );
			}
		}
		return absint( get_option( 'atora_default_institution', 0 ) );
	}

	/** Inicio del día y del mes en la zona de la academia, en UTC. @return array{0:string,1:string,2:int} */
	private static function windows(): array {
		$now        = new DateTimeImmutable( 'now', wp_timezone() );
		$day_start  = $now->setTime( 0, 0 );
		$month      = $now->modify( 'first day of this month' )->setTime( 0, 0 );
		$utc        = new DateTimeZone( 'UTC' );
		return array(
			$day_start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$month->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$day_start->modify( '+1 day' )->getTimestamp(),
		);
	}

	/** Desde cuándo una reserva sigue viva (UTC). */
	private static function live_since(): string {
		return gmdate( 'Y-m-d H:i:s', time() - self::RESERVATION_TTL );
	}

	/** Usos del día: los terminados bien y los que están en curso. */
	public static function used_today( int $user_id, string $feature ): int {
		global $wpdb;
		list( $day ) = self::windows();
		return (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT COUNT(*) FROM ' . self::table() . " WHERE user_id = %d AND feature = %s AND created_at >= %s AND ( result = 'ok' OR ( result = 'reserved' AND created_at >= %s ) )",
			$user_id,
			$feature,
			$day,
			self::live_since()
		) );
	}

	/** Costo del mes: el real de lo terminado más el estimado de lo que está en curso. */
	public static function month_cost(): float {
		global $wpdb;
		list( , $month ) = self::windows();
		return (float) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT COALESCE(SUM(cost), 0) FROM ' . self::table() . " WHERE institution_id = %d AND created_at >= %s AND ( result <> 'reserved' OR created_at >= %s )",
			self::institution(),
			$month,
			self::live_since()
		) );
	}

	/**
	 * Costo estimado de una llamada, para reservarlo contra el tope mensual: el
	 * promedio de las últimas 20 terminadas bien de esa función (0 sin historial).
	 */
	public static function estimate( string $feature ): float {
		global $wpdb;
		$avg = (float) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT COALESCE(AVG(cost), 0) FROM ( SELECT cost FROM ' . self::table() . " WHERE feature = %s AND result = 'ok' ORDER BY id DESC LIMIT 20 ) AS recent",
			$feature
		) );
		return max( 0.0, (float) apply_filters( 'atora_ai_reservation_estimate', $avg, $feature ) );
	}

	/**
	 * 6.33.1 (E.5): reserva una llamada de forma atómica. Bajo un bloqueo por
	 * academia, comprueba los límites contando lo que está en curso y deja una
	 * fila `reserved` con el costo estimado. Se cierra con finish().
	 *
	 * @return int|WP_Error Id de la reserva, o 429 si se alcanzó un límite.
	 */
	public static function reserve( int $user_id, string $feature ) {
		global $wpdb;
		$lock = 'atora_ai_quota_' . self::institution();
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			return new WP_Error( 'atora_ai_busy', __( 'El servicio de IA está ocupado. Intenta de nuevo en unos segundos.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		try {
			// Las colgadas (el proceso murió) dejan de contar y quedan marcadas.
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET result = 'expired', cost = 0 WHERE result = 'reserved' AND created_at < %s", self::live_since() ) ); // phpcs:ignore WordPress.DB
			$estimate = self::estimate( $feature );
			$allowed  = self::check( $user_id, $feature, $estimate );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
			$ok = $wpdb->insert( self::table(), array( // phpcs:ignore WordPress.DB
				'institution_id' => self::institution(),
				'user_id'        => $user_id,
				'feature'        => $feature,
				'provider'       => '',
				'model'          => '',
				'tokens_in'      => 0,
				'tokens_out'     => 0,
				'cost'           => $estimate,
				'result'         => 'reserved',
				'created_at'     => current_time( 'mysql', true ),
			) );
			// 6.33.2: sin reserva escrita no hay llamada (no contaría para los límites).
			if ( 1 !== (int) $ok || (int) $wpdb->insert_id <= 0 ) {
				return new WP_Error( 'atora_ai_unavailable', __( 'El servicio de IA no está disponible en este momento. Intenta de nuevo.', 'atora-lms' ), array( 'status' => 503 ) );
			}
			return (int) $wpdb->insert_id;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** Cierra una reserva: ok (cuenta con su costo real) o error (libera el cupo). */
	public static function finish( int $reservation_id, string $provider, string $model, array $usage, string $result, ?float $cost = null ): void {
		global $wpdb;
		list( $in, $out ) = self::tokens( $usage );
		$updated = $reservation_id > 0 ? $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'UPDATE ' . self::table() . " SET provider = %s, model = %s, tokens_in = %d, tokens_out = %d, cost = %f, result = %s WHERE id = %d AND result IN ('reserved', 'expired')",
			substr( $provider, 0, 40 ),
			substr( $model, 0, 100 ),
			$in,
			$out,
			'ok' === $result ? ( null !== $cost ? $cost : self::estimate_cost( $provider, $usage ) ) : 0,
			$result,
			$reservation_id
		) ) : 0;
		if ( ! $updated ) {
			$row = $reservation_id > 0 ? $wpdb->get_row( $wpdb->prepare( 'SELECT user_id, feature FROM ' . self::table() . ' WHERE id = %d', $reservation_id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB
			if ( ! $row ) {
				return;
			}
			self::record( (int) $row['user_id'], (string) $row['feature'], $provider, $model, $usage, $result, $cost );
			return;
		}
		if ( 'ok' === $result ) {
			self::maybe_alert_budget();
		}
	}

	/**
	 * ¿Puede usar la función ahora? 429 con la hora de reinicio si no.
	 *
	 * @return true|WP_Error
	 */
	public static function check( int $user_id, string $feature, float $estimate = 0.0 ) {
		$limits = self::limits();
		list( , , $reset_ts ) = self::windows();
		$daily = self::daily_limit( $user_id, $feature );
		if ( null !== $daily && self::used_today( $user_id, $feature ) >= $daily ) {
			self::record( $user_id, $feature, '', '', array(), 'limit' );
			$reset = wp_date( 'H:i', $reset_ts );
			return new WP_Error(
				'atora_ai_limit',
				self::SUGGESTION === $feature
					? sprintf( __( 'Alcanzaste el límite de %1$d sugerencias por día. Se reinicia a las %2$s.', 'atora-lms' ), $daily, $reset )
					: ( self::ASSISTANT === $feature
						? sprintf( __( 'Alcanzaste el límite de %1$d preguntas por día. Se reinicia a las %2$s.', 'atora-lms' ), $daily, $reset )
						: sprintf( __( 'Alcanzaste el límite diario de %1$d usos de IA en "%2$s". Se reinicia a las %3$s.', 'atora-lms' ), $daily, self::label( $feature ), $reset ) ),
				array( 'status' => 429, 'reset_at' => gmdate( 'c', $reset_ts ) )
			);
		}
		$cap = $limits['monthly_cost_cap'];
		$spent = '' !== $cap ? self::month_cost() : 0.0;
		if ( '' !== $cap && ( $spent >= (float) $cap || ( $estimate > 0 && $spent + $estimate > (float) $cap ) ) ) {
			self::record( $user_id, $feature, '', '', array(), 'limit' );
			$next = ( new DateTimeImmutable( 'first day of next month', wp_timezone() ) )->setTime( 0, 0 );
			return new WP_Error(
				'atora_ai_budget',
				sprintf( __( 'La academia alcanzó el tope mensual de uso de IA. Se reinicia el %s.', 'atora-lms' ), wp_date( 'j \d\e F', $next->getTimestamp() ) ),
				array( 'status' => 429, 'reset_at' => gmdate( 'c', $next->getTimestamp() ) )
			);
		}
		return true;
	}

	/**
	 * Límite diario de una persona en una función (null = sin límite diario).
	 * Asistente: el de estudiantes; sugerencia: el de docentes; el resto de los
	 * módulos, según el rol de quien la usa. Las llamadas sin persona (tareas
	 * programadas) y las masivas solo cuentan para el tope mensual.
	 */
	public static function daily_limit( int $user_id, string $feature ): ?int {
		$limits = self::limits();
		// Sin persona (visitante o tarea programada): solo el tope mensual. Los
		// visitantes del asistente web conservan su límite por IP.
		if ( $user_id <= 0 ) {
			return null;
		}
		if ( self::ASSISTANT === $feature ) {
			return $limits['student_daily'];
		}
		if ( self::SUGGESTION === $feature ) {
			return $limits['teacher_daily'];
		}
		if ( in_array( $feature, self::BULK_FEATURES, true ) ) {
			return null;
		}
		$teacher = user_can( $user_id, 'manage_options' ) || ( class_exists( 'ATORA_Teacher_Scope' ) && ATORA_Teacher_Scope::has_teacher_role( $user_id ) );
		return $teacher ? $limits['teacher_daily'] : $limits['student_daily'];
	}

	public static function label( string $feature ): string {
		return isset( self::LABELS[ $feature ] ) ? __( self::LABELS[ $feature ], 'atora-lms' ) : $feature; // phpcs:ignore WordPress.WP.I18n
	}

	/** Clave de función de una llamada: la que declara (`feature`), o su `source`, o "other". */
	public static function feature_of( array $options, string $fallback = 'other' ): string {
		$feature = sanitize_key( (string) ( $options['feature'] ?? $options['source'] ?? '' ) );
		return '' !== $feature ? substr( $feature, 0, 40 ) : $fallback;
	}

	/** Tokens del proveedor (cada uno los llama distinto). @return array{0:int,1:int} */
	public static function tokens( array $usage ): array {
		$in  = (int) ( $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? $usage['promptTokenCount'] ?? 0 );
		$out = (int) ( $usage['completion_tokens'] ?? $usage['output_tokens'] ?? $usage['candidatesTokenCount'] ?? 0 );
		return array( max( 0, $in ), max( 0, $out ) );
	}

	/** Costo estimado con la misma tabla de precios del registro de IA (`CLMS_AI_Log`). */
	public static function estimate_cost( string $provider, array $usage ): float {
		return class_exists( 'CLMS_AI_Log' ) ? CLMS_AI_Log::estimate_usage_cost( $provider, $usage ) : 0.0;
	}

	public static function record( int $user_id, string $feature, string $provider, string $model, array $usage, string $result, ?float $cost = null ): void {
		global $wpdb;
		list( $in, $out ) = self::tokens( $usage );
		$wpdb->insert( self::table(), array( // phpcs:ignore WordPress.DB
			'institution_id' => self::institution(),
			'user_id'        => $user_id,
			'feature'        => $feature,
			'provider'       => substr( $provider, 0, 40 ),
			'model'          => substr( $model, 0, 100 ),
			'tokens_in'      => $in,
			'tokens_out'     => $out,
			'cost'           => 'ok' === $result ? ( null !== $cost ? $cost : self::estimate_cost( $provider, $usage ) ) : 0,
			'result'         => $result,
			'created_at'     => current_time( 'mysql', true ),
		) );
		if ( 'ok' === $result ) {
			self::maybe_alert_budget();
		}
	}

	/** Al 80 % del tope mensual: aviso a los administradores, una vez por mes. */
	private static function maybe_alert_budget(): void {
		$cap = self::limits()['monthly_cost_cap'];
		if ( '' === $cap || (float) $cap <= 0 ) {
			return;
		}
		$month = wp_date( 'Y-m' );
		if ( get_option( self::ALERT_OPTION ) === $month || self::month_cost() < 0.8 * (float) $cap ) {
			return;
		}
		update_option( self::ALERT_OPTION, $month, false );
		$notifications = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Notifications' ) : null;
		if ( ! $notifications || ! method_exists( $notifications, 'add_notification' ) ) {
			return;
		}
		foreach ( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) as $admin_id ) {
			$notifications->add_notification( (int) $admin_id, array(
				'type'       => 'ai_budget',
				'title'      => __( 'Uso de IA al 80 % del tope mensual', 'atora-lms' ),
				'message'    => sprintf( __( 'La academia lleva %1$s de un tope mensual de %2$s (costo estimado).', 'atora-lms' ), number_format_i18n( self::month_cost(), 2 ), number_format_i18n( (float) $cap, 2 ) ),
				'link'       => admin_url( 'admin.php?page=atora-ai-usage' ),
				'dedupe_key' => 'ai_budget_' . $month,
			) );
		}
	}

	/** Resumen del mes para la administración. @return array{features:array,top:array,total:float} */
	public static function month_report(): array {
		global $wpdb;
		list( , $month ) = self::windows();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT feature, result, COUNT(*) AS calls, SUM(tokens_in) AS tokens_in, SUM(tokens_out) AS tokens_out, SUM(cost) AS cost FROM ' . self::table() . ' WHERE institution_id = %d AND created_at >= %s GROUP BY feature, result',
			self::institution(),
			$month
		), ARRAY_A );
		$top  = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT user_id, COUNT(*) AS calls, SUM(cost) AS cost FROM ' . self::table() . " WHERE institution_id = %d AND created_at >= %s AND result = 'ok' GROUP BY user_id ORDER BY cost DESC, calls DESC LIMIT 10",
			self::institution(),
			$month
		), ARRAY_A );
		return array( 'features' => $rows, 'top' => $top, 'total' => self::month_cost() );
	}
}
