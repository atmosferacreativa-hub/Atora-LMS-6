<?php
/**
 * LMS Parity — F3.2 / F3.3
 *
 * Dos tablas:
 *  - atora_lms_parity_log   → solo divergencias (legacy ≠ tabla).
 *  - atora_lms_parity_reads → 1 fila/día por (reader, user_id); volumen observado
 *    para que "0 divergencias" no sea consecuencia de falta de tráfico (gate D-006).
 *
 * Regla: ninguna excepción aquí puede afectar la respuesta real — todas las
 * llamadas desde lectores canónicos van en try/catch.
 *
 * @package ATORA_LMS\LMS
 * @since   6.0.9
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LMS_Parity {

	const TABLE_SUFFIX = 'atora_lms_parity_log';
	const TABLE_READS  = 'atora_lms_parity_reads';

	/** Lectores críticos instrumentados (D-006). */
	const READERS = array(
		'enrolled_courses'     => 'Cursos matriculados',
		'is_enrolled'          => 'is_enrolled (individual)',
		'access_expiry'        => 'Caducidad de acceso',
		'course_completed'     => 'Completación de curso',
		'enrolled_programs'    => 'Programas matriculados',
		// F4.1 — post-cutover (PC): tablas es canónico, legacy es sombra.
		'pc_enrolled_courses'  => '[PC] Cursos matriculados',
		'pc_is_enrolled'       => '[PC] is_enrolled (individual)',
		'pc_access_expiry'     => '[PC] Caducidad de acceso',
		'pc_course_completed'  => '[PC] Completación de curso',
		'pc_enrolled_programs' => '[PC] Programas matriculados',
	);

	/** Throttle divergencias: 1 check por (reader, user, course) cada 5 min. */
	const THROTTLE_TTL = 300;

	// ── Instalación ──────────────────────────────────────────────────────────

	/**
	 * Crea las dos tablas si no existen.
	 * Se llama en ATORA_LMS_Migration_Admin::init().
	 */
	public static function ensure_table(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$log   = $wpdb->prefix . self::TABLE_SUFFIX;
		$reads = $wpdb->prefix . self::TABLE_READS;

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$log}'" ) !== $log ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			dbDelta(
				"CREATE TABLE {$log} (
				  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				  reader        VARCHAR(60)     NOT NULL,
				  user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
				  wp_course_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
				  legacy_digest CHAR(8)         NOT NULL DEFAULT '',
				  table_digest  CHAR(8)         NOT NULL DEFAULT '',
				  legacy_summary VARCHAR(500)   NOT NULL DEFAULT '',
				  table_summary  VARCHAR(500)   NOT NULL DEFAULT '',
				  logged_at     DATETIME        NOT NULL,
				  KEY idx_reader_day (reader, logged_at),
				  KEY idx_user (user_id)
				) {$charset};"
			);
		}

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$reads}'" ) !== $reads ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			dbDelta(
				"CREATE TABLE {$reads} (
				  reader      VARCHAR(60)  NOT NULL,
				  user_id     BIGINT UNSIGNED NOT NULL,
				  logged_date DATE         NOT NULL,
				  PRIMARY KEY (reader, user_id, logged_date),
				  KEY idx_reader_date (reader, logged_date)
				) {$charset};"
			);
		}
	}

	// ── Shadow-checks (llamados desde lectores canónicos) ────────────────────

	/**
	 * Shadow-check de 'enrolled_courses'.
	 *
	 * @param int   $user_id    WP user ID.
	 * @param array $legacy_ids Resultado de CLMS_Helper::get_user_enrolled_courses().
	 */
	public static function shadow_enrolled_courses( int $user_id, array $legacy_ids ): void {
		self::log_read( 'enrolled_courses', $user_id );
		if ( ! self::throttle_ok( 'enrolled_courses', $user_id, 0 ) ) { return; }
		if ( ! class_exists( LMS_Enrollment_Service::class ) ) { return; }

		$table_ids = LMS_Enrollment_Service::get_enrolled_wp_course_ids( $user_id );
		self::log_if_diff(
			'enrolled_courses', $user_id, 0,
			self::digest_ids( $legacy_ids ), self::digest_ids( $table_ids ),
			self::summarize_ids( $legacy_ids ), self::summarize_ids( $table_ids )
		);
	}

	/**
	 * Shadow-check de 'is_enrolled'.
	 *
	 * @param int  $user_id       WP user ID.
	 * @param int  $wp_course_id  WP post ID del curso.
	 * @param bool $legacy_result Resultado de CLMS_Helper::user_is_enrolled_in_course().
	 */
	public static function shadow_is_enrolled( int $user_id, int $wp_course_id, bool $legacy_result ): void {
		self::log_read( 'is_enrolled', $user_id );
		if ( ! self::throttle_ok( 'is_enrolled', $user_id, $wp_course_id ) ) { return; }
		if ( ! class_exists( LMS_Enrollment_Service::class ) ) { return; }

		$table_result = LMS_Enrollment_Service::is_enrolled_by_wp_id( $user_id, $wp_course_id );
		self::log_if_diff(
			'is_enrolled', $user_id, $wp_course_id,
			$legacy_result ? '1' : '0', $table_result ? '1' : '0',
			$legacy_result ? 'true' : 'false', $table_result ? 'true' : 'false'
		);
	}

	/**
	 * Shadow-check de 'access_expiry'.
	 *
	 * @param int    $user_id       WP user ID.
	 * @param int    $wp_course_id  WP post ID del curso.
	 * @param string $legacy_expiry Resultado de CLMS_Helper::get_user_course_access_expiration().
	 */
	public static function shadow_access_expiry( int $user_id, int $wp_course_id, string $legacy_expiry ): void {
		self::log_read( 'access_expiry', $user_id );
		if ( ! self::throttle_ok( 'access_expiry', $user_id, $wp_course_id ) ) { return; }
		if ( ! class_exists( LMS_Enrollment_Service::class ) ) { return; }

		$table_expiry = LMS_Enrollment_Service::get_access_expiry_by_wp_id( $user_id, $wp_course_id );
		self::log_if_diff(
			'access_expiry', $user_id, $wp_course_id,
			self::str_digest( $legacy_expiry ), self::str_digest( $table_expiry ),
			$legacy_expiry ?: '(perpetuo)', $table_expiry ?: '(perpetuo)'
		);
	}

	/**
	 * Shadow-check de 'course_completed' (lector crítico D-006).
	 *
	 * @param int  $user_id       WP user ID.
	 * @param int  $wp_course_id  WP post ID del curso.
	 * @param bool $legacy_result Resultado de CLMS_Helper::is_course_completed().
	 */
	public static function shadow_course_completed( int $user_id, int $wp_course_id, bool $legacy_result ): void {
		self::log_read( 'course_completed', $user_id );
		if ( ! self::throttle_ok( 'course_completed', $user_id, $wp_course_id ) ) { return; }
		if ( ! class_exists( LMS_Enrollment_Service::class ) ) { return; }

		$table_result = LMS_Enrollment_Service::is_course_completed_by_wp_id( $user_id, $wp_course_id );
		self::log_if_diff(
			'course_completed', $user_id, $wp_course_id,
			$legacy_result ? '1' : '0', $table_result ? '1' : '0',
			$legacy_result ? 'true' : 'false', $table_result ? 'true' : 'false'
		);
	}

	/**
	 * Shadow-check de 'enrolled_programs'.
	 *
	 * @param int   $user_id    WP user ID.
	 * @param array $legacy_ids Resultado de CLMS_Helper::get_user_enrolled_programs().
	 */
	public static function shadow_enrolled_programs( int $user_id, array $legacy_ids ): void {
		self::log_read( 'enrolled_programs', $user_id );
		if ( ! self::throttle_ok( 'enrolled_programs', $user_id, 0 ) ) { return; }
		if ( ! class_exists( LMS_Enrollment_Service::class ) ) { return; }

		$table_ids = LMS_Enrollment_Service::get_enrolled_wp_program_ids( $user_id );
		self::log_if_diff(
			'enrolled_programs', $user_id, 0,
			self::digest_ids( $legacy_ids ), self::digest_ids( $table_ids ),
			self::summarize_ids( $legacy_ids ), self::summarize_ids( $table_ids )
		);
	}

	// ── Shadow-checks post-cutover (F4.1) — tablas es canónico, legacy es sombra ──

	/**
	 * Post-cutover shadow-check de 'enrolled_courses'.
	 *
	 * @param int   $user_id   WP user ID.
	 * @param array $table_ids Resultado de LMS_Enrollment_Service::get_enrolled_wp_course_ids() (canónico).
	 */
	public static function shadow_enrolled_courses_pc( int $user_id, array $table_ids ): void {
		self::log_read( 'pc_enrolled_courses', $user_id );
		if ( ! self::throttle_ok( 'pc_enrolled_courses', $user_id, 0 ) ) { return; }

		$raw        = get_user_meta( $user_id, '_clms_enrolled_courses', true );
		$legacy_ids = is_array( $raw ) ? array_values( array_filter( array_map( 'absint', $raw ) ) ) : array();

		self::log_if_diff(
			'pc_enrolled_courses', $user_id, 0,
			self::digest_ids( $legacy_ids ), self::digest_ids( $table_ids ),
			self::summarize_ids( $legacy_ids ), self::summarize_ids( $table_ids )
		);
	}

	/**
	 * Post-cutover shadow-check de 'is_enrolled'.
	 *
	 * @param int  $user_id       WP user ID.
	 * @param int  $wp_course_id  WP post ID del curso.
	 * @param bool $table_result  Resultado de LMS_Enrollment_Service::is_enrolled_by_wp_id() (canónico).
	 */
	public static function shadow_is_enrolled_pc( int $user_id, int $wp_course_id, bool $table_result ): void {
		self::log_read( 'pc_is_enrolled', $user_id );
		if ( ! self::throttle_ok( 'pc_is_enrolled', $user_id, $wp_course_id ) ) { return; }

		$raw        = get_user_meta( $user_id, '_clms_enrolled_courses', true );
		$course_ids = is_array( $raw ) ? array_map( 'absint', $raw ) : array();
		$legacy     = in_array( $wp_course_id, $course_ids, true );

		if ( $legacy ) {
			$exps = get_user_meta( $user_id, '_clms_course_access_expiry', true );
			if ( is_array( $exps ) && isset( $exps[ $wp_course_id ] ) ) {
				$exp = (string) $exps[ $wp_course_id ];
				if ( $exp && strtotime( $exp ) < time() ) {
					$legacy = false;
				}
			}
		}

		self::log_if_diff(
			'pc_is_enrolled', $user_id, $wp_course_id,
			$legacy ? '1' : '0', $table_result ? '1' : '0',
			$legacy ? 'true' : 'false', $table_result ? 'true' : 'false'
		);
	}

	/**
	 * Post-cutover shadow-check de 'access_expiry'.
	 *
	 * @param int    $user_id       WP user ID.
	 * @param int    $wp_course_id  WP post ID del curso.
	 * @param string $table_expiry  Resultado de LMS_Enrollment_Service::get_access_expiry_by_wp_id() (canónico).
	 */
	public static function shadow_access_expiry_pc( int $user_id, int $wp_course_id, string $table_expiry ): void {
		self::log_read( 'pc_access_expiry', $user_id );
		if ( ! self::throttle_ok( 'pc_access_expiry', $user_id, $wp_course_id ) ) { return; }

		$exps         = get_user_meta( $user_id, '_clms_course_access_expiry', true );
		$legacy_expiry = ( is_array( $exps ) && isset( $exps[ $wp_course_id ] ) )
			? sanitize_text_field( (string) $exps[ $wp_course_id ] )
			: '';

		self::log_if_diff(
			'pc_access_expiry', $user_id, $wp_course_id,
			self::str_digest( $legacy_expiry ), self::str_digest( $table_expiry ),
			$legacy_expiry ?: '(perpetuo)', $table_expiry ?: '(perpetuo)'
		);
	}

	/**
	 * Post-cutover shadow-check de 'course_completed'.
	 *
	 * @param int  $user_id       WP user ID.
	 * @param int  $wp_course_id  WP post ID del curso.
	 * @param bool $table_result  Resultado de LMS_Enrollment_Service::is_course_completed_by_wp_id() (canónico).
	 */
	public static function shadow_course_completed_pc( int $user_id, int $wp_course_id, bool $table_result ): void {
		self::log_read( 'pc_course_completed', $user_id );
		if ( ! self::throttle_ok( 'pc_course_completed', $user_id, $wp_course_id ) ) { return; }

		$raw_completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed_ids = is_array( $raw_completed ) ? array_values( array_filter( array_map( 'absint', $raw_completed ) ) ) : array();
		$legacy_result = false;

		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_lessons' ) ) {
			// PT-1 (6.5.10): CLMS_Helper es global; este archivo vive bajo
			// namespace ATORA\LMS — sin el backslash, PHP resuelve esto a
			// ATORA\LMS\CLMS_Helper (inexistente) y produce un fatal.
			$lesson_ids = array_values( array_filter( array_map( 'absint', (array) \CLMS_Helper::get_course_lessons( $wp_course_id ) ) ) );
			$legacy_result = ! empty( $lesson_ids )
				&& count( array_intersect( $lesson_ids, $completed_ids ) ) === count( $lesson_ids );
		}

		self::log_if_diff(
			'pc_course_completed', $user_id, $wp_course_id,
			$legacy_result ? '1' : '0', $table_result ? '1' : '0',
			$legacy_result ? 'true' : 'false', $table_result ? 'true' : 'false'
		);
	}

	/**
	 * Post-cutover shadow-check de 'enrolled_programs'.
	 *
	 * @param int   $user_id   WP user ID.
	 * @param array $table_ids Resultado de LMS_Enrollment_Service::get_enrolled_wp_program_ids() (canónico).
	 */
	public static function shadow_enrolled_programs_pc( int $user_id, array $table_ids ): void {
		self::log_read( 'pc_enrolled_programs', $user_id );
		if ( ! self::throttle_ok( 'pc_enrolled_programs', $user_id, 0 ) ) { return; }

		$raw        = get_user_meta( $user_id, '_clms_enrolled_programs', true );
		$legacy_ids = is_array( $raw ) ? array_values( array_filter( array_map( 'absint', $raw ) ) ) : array();

		self::log_if_diff(
			'pc_enrolled_programs', $user_id, 0,
			self::digest_ids( $legacy_ids ), self::digest_ids( $table_ids ),
			self::summarize_ids( $legacy_ids ), self::summarize_ids( $table_ids )
		);
	}

	// ── Stats para el panel (F3.3) ────────────────────────────────────────────

	/**
	 * Divergencias por lector (últimos 14 días).
	 *
	 * @return array<string,array{count:int,last_at:string}>
	 */
	public static function get_reader_stats(): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-14 days' ) );

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT reader, COUNT(*) AS cnt, MAX(logged_at) AS last_at
				 FROM {$table}
				 WHERE logged_at >= %s
				 GROUP BY reader",
				$since
			),
			ARRAY_A
		);

		$stats = array();
		foreach ( array_keys( self::READERS ) as $r ) {
			$stats[ $r ] = array( 'count' => 0, 'last_at' => '' );
		}
		foreach ( $rows as $row ) {
			if ( isset( $stats[ $row['reader'] ] ) ) {
				$stats[ $row['reader'] ] = array(
					'count'   => absint( $row['cnt'] ),
					'last_at' => (string) $row['last_at'],
				);
			}
		}
		return $stats;
	}

	/**
	 * Estadísticas de volumen observado vs. alumnos activos (gate D-006).
	 *
	 * Compara usuarios distintos observados en `atora_lms_parity_reads` con el
	 * total de usuarios activos en `atora_enrollments`. Si observed >= active,
	 * el resultado de "0 divergencias" es estadísticamente significativo.
	 *
	 * @return array{active_users:int, observed_users:int, volume_ok:bool}
	 */
	public static function get_volume_stats(): array {
		global $wpdb;
		$reads = $wpdb->prefix . self::TABLE_READS;
		$since = gmdate( 'Y-m-d', strtotime( '-14 days' ) );

		// Alumnos observados (union de todos los readers en 14 días).
		$observed = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$reads} WHERE logged_date >= %s",
				$since
			)
		);

		// Alumnos activos en tablas.
		$enroll_table = $wpdb->prefix . 'atora_enrollments';
		$active       = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(DISTINCT user_id) FROM {$enroll_table} WHERE status IN ('active','completed')"
		);

		return array(
			'active_users'   => $active,
			'observed_users' => $observed,
			'volume_ok'      => $active > 0 && $observed >= $active,
		);
	}

	/**
	 * Total global de divergencias (últimos 14 días).
	 */
	public static function total_divergences(): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-14 days' ) );
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE logged_at >= %s", $since )
		);
	}

	/**
	 * Divergencias por día (últimos 14 días) para gráfico.
	 *
	 * @return array<string,int>  día ISO → count
	 */
	public static function get_daily_counts(): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-14 days' ) );

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT DATE(logged_at) AS day, COUNT(*) AS cnt
				 FROM {$table}
				 WHERE logged_at >= %s
				 GROUP BY DATE(logged_at)
				 ORDER BY day ASC",
				$since
			),
			ARRAY_A
		);

		$days = array();
		$d    = new \DateTime( '-13 days', new \DateTimeZone( 'UTC' ) );
		for ( $i = 0; $i < 14; $i++ ) {
			$days[ $d->format( 'Y-m-d' ) ] = 0;
			$d->modify( '+1 day' );
		}
		foreach ( $rows as $row ) {
			if ( isset( $days[ $row['day'] ] ) ) {
				$days[ $row['day'] ] = absint( $row['cnt'] );
			}
		}
		return $days;
	}

	/**
	 * Últimas divergencias registradas (para la tabla del panel).
	 *
	 * @param int $limit Máximo de filas.
	 * @return array<int,array>
	 */
	public static function get_recent( int $limit = 50 ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT reader, user_id, wp_course_id, legacy_summary, table_summary, logged_at
				 FROM {$table}
				 ORDER BY logged_at DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Días consecutivos sin divergencias post-cutover (F4.3).
	 *
	 * Si hay divergencias PC, devuelve 0.
	 * Si no hay ninguna, cuenta desde la fecha de cutover guardada en options.
	 *
	 * @return int Días estables desde el último pc_* divergente (0 si hoy hay alguno).
	 */
	public static function get_postcutover_stable_days(): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SUFFIX;
		$last  = $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT MAX(logged_at) FROM {$table} WHERE reader LIKE 'pc\\_%'"
		);
		if ( ! $last ) {
			$cutover_at = (string) get_option( 'atora_lms_cutover_at', '' );
			if ( ! $cutover_at ) { return 0; }
			return max( 0, (int) floor( ( time() - strtotime( $cutover_at ) ) / DAY_IN_SECONDS ) );
		}
		return max( 0, (int) floor( ( time() - strtotime( $last ) ) / DAY_IN_SECONDS ) );
	}

	// ── Gate de cutover (F4 — task 1.3) ───────────────────────────────────────

	/**
	 * Tablas núcleo cuyo conteo de filas debe ser > 0 antes del flip.
	 */
	const CORE_TABLES = array( 'atora_courses', 'atora_lessons', 'atora_enrollments', 'atora_program_enrollments' );

	/**
	 * Evalúa si el cutover a lectura de tablas puede ejecutarse (D-006).
	 *
	 * Condiciones: `atora_lms_dualwrite` activo, 0 divergencias en los
	 * últimos 14 días, reconciliación diaria sin pendientes, y las 4 tablas
	 * núcleo con filas > 0. No usa estado en caché aparte de la option de
	 * reconciliación, que ya se refresca por cron diario (F2.4).
	 *
	 * @return array{ready:bool, reasons:string[]}
	 */
	public static function cutover_ready(): array {
		$reasons = array();

		if ( ! (bool) get_option( 'atora_lms_dualwrite', false ) ) {
			$reasons[] = 'atora_lms_dualwrite está inactivo.';
		}

		$divergences = self::total_divergences();
		if ( $divergences > 0 ) {
			$reasons[] = sprintf( '%d divergencia(s) registradas en los últimos 14 días.', $divergences );
		}

		$reconcile = (array) get_option( 'atora_lms_reconcile_result', array() );
		if ( empty( $reconcile ) ) {
			$reasons[] = 'La reconciliación diaria aún no se ha ejecutado.';
		} else {
			$pending = isset( $reconcile['total'] ) ? (int) $reconcile['total'] : array_sum( (array) ( $reconcile['reconcile'] ?? array() ) );
			if ( $pending > 0 ) {
				$reasons[] = sprintf( 'La última reconciliación reportó %d pendiente(s)/huérfano(s).', $pending );
			}
		}

		foreach ( self::core_table_counts() as $table => $count ) {
			if ( $count <= 0 ) {
				$reasons[] = sprintf( 'La tabla %s no tiene filas.', $table );
			}
		}

		return array(
			'ready'   => empty( $reasons ),
			'reasons' => $reasons,
		);
	}

	/**
	 * Conteo de filas de las tablas núcleo (prefijadas), 0 si la tabla no existe.
	 *
	 * @return array<string,int>  nombre de tabla (con prefijo) => filas.
	 */
	private static function core_table_counts(): array {
		global $wpdb;
		$counts = array();
		foreach ( self::CORE_TABLES as $name ) {
			$table  = $wpdb->prefix . $name;
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$counts[ $table ] = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		return $counts;
	}

	// ── Internos ──────────────────────────────────────────────────────────────

	/**
	 * Registra que este usuario fue observado hoy para el lector dado.
	 * INSERT IGNORE: deduplica automáticamente por (reader, user_id, logged_date).
	 * Coste mínimo — el PK ya es el índice único.
	 */
	private static function log_read( string $reader, int $user_id ): void {
		if ( ! $user_id ) { return; }
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}atora_lms_parity_reads (reader, user_id, logged_date) VALUES (%s, %d, %s)",
				$reader, $user_id, gmdate( 'Y-m-d' )
			)
		);
	}

	/**
	 * Throttle con WP object cache: devuelve true si se puede hacer el diff ahora.
	 * La tabla de reads ya registró la visita; esto solo limita los diff checks.
	 */
	private static function throttle_ok( string $reader, int $user_id, int $wp_course_id ): bool {
		$key = "atora_parity_{$reader}_{$user_id}_{$wp_course_id}";
		if ( false !== wp_cache_get( $key, 'atora_parity' ) ) {
			return false;
		}
		wp_cache_set( $key, 1, 'atora_parity', self::THROTTLE_TTL );
		return true;
	}

	/**
	 * Escribe una fila en el log de divergencias solo si los digests difieren.
	 */
	private static function log_if_diff(
		string $reader,
		int    $user_id,
		int    $wp_course_id,
		string $legacy_digest,
		string $table_digest,
		string $legacy_summary,
		string $table_summary
	): void {
		if ( $legacy_digest === $table_digest ) { return; }

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . self::TABLE_SUFFIX,
			array(
				'reader'         => $reader,
				'user_id'        => $user_id,
				'wp_course_id'   => $wp_course_id,
				'legacy_digest'  => $legacy_digest,
				'table_digest'   => $table_digest,
				'legacy_summary' => substr( $legacy_summary, 0, 500 ),
				'table_summary'  => substr( $table_summary,  0, 500 ),
				'logged_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private static function digest_ids( array $ids ): string {
		sort( $ids );
		return substr( md5( implode( ',', $ids ) ), 0, 8 );
	}

	private static function summarize_ids( array $ids ): string {
		sort( $ids );
		$str = implode( ',', $ids );
		return strlen( $str ) > 200 ? substr( $str, 0, 197 ) . '...' : $str;
	}

	private static function str_digest( string $s ): string {
		return substr( md5( $s ), 0, 8 );
	}
}
