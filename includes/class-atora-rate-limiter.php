<?php
/**
 * ATORA_Rate_Limiter — contador de rate limit atómico, reutilizable.
 *
 * Sprint 6.5.7 (Prioridad 6): extrae a un helper central el mismo
 * patrón de tabla dedicada + INSERT ... ON DUPLICATE KEY UPDATE que
 * ya usan Forms_Builder::is_throttled() (6.5.5, PT-1) y
 * ATORA_API_Key_Service::validate() (6.5.5, PT-5) — evita que cada
 * módulo nuevo reinvente su propia versión (o, peor, vuelva a caer en
 * el patrón get_transient()+set_transient() no atómico).
 *
 * @package ATORA_LMS
 * @since   6.5.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ATORA_Rate_Limiter
 *
 * @since 6.5.7
 */
class ATORA_Rate_Limiter {

	/**
	 * Consume un cupo del contador (scope, identificador, ventana
	 * fija) e informa si la operación debe permitirse.
	 *
	 * Atómico por bloqueo de fila InnoDB: el INSERT ... ON DUPLICATE
	 * KEY UPDATE incrementa attempts sin una lectura previa separada,
	 * así que dos peticiones concurrentes con la misma clave no pueden
	 * perder un incremento entre sí.
	 *
	 * @param string $scope          Espacio de nombres del límite (p.ej. 'student_assistant', 'telegram_link').
	 * @param string $identifier     Identificador del sujeto limitado (user_id, IP, token...). Se hashea, nunca se persiste en claro.
	 * @param int    $limit          Máximo de intentos permitidos dentro de la ventana.
	 * @param int    $window_seconds Tamaño de la ventana fija, en segundos.
	 * @param bool   $fail_open      Qué devolver si la tabla/consulta falla (migración incompleta, etc.).
	 *                               false (default) = fail-closed: ante una duda, bloquear — correcto para
	 *                               operaciones costosas o sensibles (IA, auth, brute-force). Pasar true
	 *                               explícitamente solo para límites de conveniencia sin riesgo real.
	 * @return bool true si la operación debe permitirse.
	 */
	public static function consume( string $scope, string $identifier, int $limit, int $window_seconds, bool $fail_open = false ): bool {
		global $wpdb;

		if ( $limit < 1 || $window_seconds < 1 ) {
			return false;
		}

		$table            = $wpdb->prefix . 'atora_rate_limit_counters';
		$window_start     = (int) ( floor( time() / $window_seconds ) * $window_seconds );
		$identifier_hash  = hash( 'sha256', $scope . '|' . $identifier . '|' . wp_salt( 'auth' ) );
		$scope_key        = sanitize_key( $scope );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (scope, identifier_hash, window_start, attempts) VALUES (%s, %s, %d, 1)
				 ON DUPLICATE KEY UPDATE attempts = attempts + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$scope_key,
				$identifier_hash,
				$window_start
			)
		);

		if ( false === $inserted ) {
			self::log_failure( $scope_key );
			return $fail_open;
		}

		$attempts = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attempts FROM {$table} WHERE scope = %s AND identifier_hash = %s AND window_start = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$scope_key,
				$identifier_hash,
				$window_start
			)
		);

		if ( null === $attempts ) {
			self::log_failure( $scope_key );
			return $fail_open;
		}

		return (int) $attempts <= $limit;
	}

	/**
	 * PT-5 (6.5.8): lee el conteo actual SIN incrementar — para casos
	 * como intentos de contraseña, donde solo debe contarse un fallo
	 * (no cada intento), así que hace falta decidir "¿ya estoy
	 * bloqueado?" antes de saber si este intento en particular cuenta.
	 *
	 * @param string $scope
	 * @param string $identifier
	 * @param int    $window_seconds
	 * @return int Intentos ya registrados en la ventana actual; 0 si
	 *             ninguno; -1 si la tabla/consulta no existe o falla
	 *             (distinguible de "0 intentos" a propósito, para que
	 *             el llamador pueda fallar cerrado en vez de
	 *             interpretar un fallo de infraestructura como "sin
	 *             intentos registrados").
	 */
	public static function peek( string $scope, string $identifier, int $window_seconds ): int {
		global $wpdb;

		if ( $window_seconds < 1 ) {
			return -1;
		}

		$table = $wpdb->prefix . 'atora_rate_limit_counters';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			self::log_failure( sanitize_key( $scope ) );
			return -1;
		}

		$window_start    = (int) ( floor( time() / $window_seconds ) * $window_seconds );
		$identifier_hash = hash( 'sha256', $scope . '|' . $identifier . '|' . wp_salt( 'auth' ) );
		$scope_key       = sanitize_key( $scope );

		$attempts = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attempts FROM {$table} WHERE scope = %s AND identifier_hash = %s AND window_start = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$scope_key,
				$identifier_hash,
				$window_start
			)
		);

		return null === $attempts ? 0 : (int) $attempts;
	}

	/**
	 * PT-5 (6.5.8): borra el contador de la ventana actual — usado tras
	 * un intento exitoso (p.ej. contraseña correcta), para que el
	 * cupo de intentos fallidos se reinicie en vez de arrastrarse hasta
	 * que la ventana venza por tiempo.
	 *
	 * @param string $scope
	 * @param string $identifier
	 * @param int    $window_seconds
	 * @return void
	 */
	public static function reset( string $scope, string $identifier, int $window_seconds ): void {
		global $wpdb;

		if ( $window_seconds < 1 ) {
			return;
		}

		$table           = $wpdb->prefix . 'atora_rate_limit_counters';
		$window_start    = (int) ( floor( time() / $window_seconds ) * $window_seconds );
		$identifier_hash = hash( 'sha256', $scope . '|' . $identifier . '|' . wp_salt( 'auth' ) );
		$scope_key       = sanitize_key( $scope );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE scope = %s AND identifier_hash = %s AND window_start = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$scope_key,
				$identifier_hash,
				$window_start
			)
		);
	}

	/**
	 * Borra contadores cuya ventana ya venció, para que las tablas de
	 * rate limit no crezcan sin límite. Usado por el cron de
	 * mantenimiento (ATORA_Security_Maintenance). Genérico — sirve
	 * para cualquier tabla de rate limit cuya columna de ventana sea un
	 * entero de timestamp Unix (atora_rate_limit_counters.window_start,
	 * atora_form_throttle.window_start).
	 *
	 * @param string $table_suffix     Nombre de tabla sin el prefijo de WP.
	 * @param string $window_column    Columna de ventana (entero, timestamp Unix).
	 * @param int    $older_than_seconds Ventanas anteriores a (ahora - esto) se eliminan.
	 * @return int Filas eliminadas, o -1 si la tabla no existe todavía.
	 */
	public static function purge_expired( string $table_suffix, string $window_column, int $older_than_seconds ): int {
		global $wpdb;

		$table = $wpdb->prefix . $table_suffix;
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return -1;
		}

		$threshold = time() - max( 0, $older_than_seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE {$window_column} < %d", $threshold ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Igual que purge_expired(), pero para tablas cuya ventana se
	 * guarda como una clave de minuto en texto ('YmdHi', p.ej.
	 * atora_api_rate_limit.minute_key) en vez de un entero — el
	 * formato ordena correctamente como string, así que una
	 * comparación lexicográfica basta.
	 *
	 * @param string $table_suffix       Nombre de tabla sin el prefijo de WP.
	 * @param string $minute_key_column  Columna CHAR(12) 'YmdHi'.
	 * @param int    $older_than_seconds Minutos anteriores a (ahora - esto) se eliminan.
	 * @return int Filas eliminadas, o -1 si la tabla no existe todavía.
	 */
	public static function purge_expired_minute_key( string $table_suffix, string $minute_key_column, int $older_than_seconds ): int {
		global $wpdb;

		$table = $wpdb->prefix . $table_suffix;
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return -1;
		}

		$threshold_key = gmdate( 'YmdHi', time() - max( 0, $older_than_seconds ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE {$minute_key_column} < %s", $threshold_key ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * @param string $scope
	 * @return void
	 */
	private static function log_failure( string $scope ): void {
		if ( function_exists( 'error_log' ) ) {
			error_log( '[ATORA_Rate_Limiter] consulta fallida para scope "' . $scope . '" — fail-closed salvo que el llamador pida fail_open explícito.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
