<?php
/**
 * LMS Migration Admin — Fase V S16 / F1.7
 *
 * Página admin + handlers AJAX para ejecutar la migración LMS desde el panel,
 * y cron de continuación auto-detenible (F1.7: corre migrate_all() por lotes
 * hasta que reconcile() llega a 0 pendientes, y entonces se autodesactiva).
 *
 * Acceso: Admin → ATORA → Ajustes → Migración LMS
 *
 * @package ATORA_LMS\LMS
 * @since   5.30.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ATORA_LMS_Migration_Admin {

	/** Hook de cron de continuación (F1.7). */
	const CRON_HOOK = 'atora_lms_migration_cron';

	/** Option: cron de continuación activo (bool). */
	const OPT_CRON_ENABLED = 'atora_lms_migration_cron_enabled';

	/** Option: tamaño de batch usado por el cron de continuación. */
	const OPT_CRON_BATCH = 'atora_lms_migration_cron_batch';

	/** Hook de cron de reconciliación diaria (F2.4). */
	const RECONCILE_CRON_HOOK = 'atora_lms_reconcile_check';

	/** Option donde se guarda el último resultado de reconcile() (F2.4). */
	const OPT_RECONCILE_RESULT = 'atora_lms_reconcile_result';

	/** Option de fuente de lectura (F3). */
	const OPT_READ_SOURCE = \ATORA\LMS\LMS_Read_Router::OPT_SOURCE;

	public static function init(): void {
		add_action( 'wp_ajax_atora_lms_run_migration',         array( __CLASS__, 'ajax_run_migration' ) );
		add_action( 'wp_ajax_atora_lms_toggle_migration_cron', array( __CLASS__, 'ajax_toggle_cron' ) );
		add_action( 'wp_ajax_atora_lms_toggle_dualwrite',      array( __CLASS__, 'ajax_toggle_dualwrite' ) );
		// F3: parity
		add_action( 'wp_ajax_atora_lms_parity_stats',  array( __CLASS__, 'ajax_parity_stats' ) );
		add_action( 'wp_ajax_atora_lms_parity_export', array( __CLASS__, 'ajax_parity_export' ) );
		// F4: read-source toggle (flip + rollback)
		add_action( 'wp_ajax_atora_lms_toggle_read_source', array( __CLASS__, 'ajax_toggle_read_source' ) );
		add_action( 'admin_menu',                              array( __CLASS__, 'register_menu' ), 99 );

		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );
		add_action( self::CRON_HOOK,          array( __CLASS__, 'run_cron_batch' ) );
		add_action( self::RECONCILE_CRON_HOOK, array( __CLASS__, 'run_reconcile_check' ) );

		self::sync_cron_schedule();
		self::sync_reconcile_schedule();
		self::ensure_parity_table();
	}

	/**
	 * Registra el subítem en el menú de ATORA bajo Ajustes.
	 */
	public static function register_menu(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Migración LMS', 'atora-lms' ),
			__( '🗄 Migración LMS', 'atora-lms' ),
			'manage_options',
			'atora-lms-migration',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Renderiza la vista de la página admin.
	 */
	public static function render_page(): void {
		$view = defined( 'ATORA_LMS_MODULES_DIR' )
			? ATORA_LMS_MODULES_DIR . 'lms/views/migration-admin.php'
			: __DIR__ . '/views/migration-admin.php';

		if ( file_exists( $view ) ) {
			require $view;
		} else {
			echo '<div class="wrap"><h1>Migración LMS</h1><p>Vista no encontrada.</p></div>';
		}
	}

	/**
	 * @param array $schedules Schedules existentes.
	 * @return array
	 */
	public static function add_cron_interval( array $schedules ): array {
		if ( ! isset( $schedules['every_5_minutes'] ) ) {
			$schedules['every_5_minutes'] = array(
				'interval' => 300,
				'display'  => __( 'Cada 5 minutos', 'atora-lms' ),
			);
		}
		return $schedules;
	}

	/**
	 * Sincroniza el evento de cron con la option OPT_CRON_ENABLED: lo
	 * programa si está activo y no hay evento pendiente, o lo limpia si está
	 * inactivo y quedó uno programado (p.ej. tras desactivar manualmente).
	 */
	private static function sync_cron_schedule(): void {
		$enabled   = (bool) get_option( self::OPT_CRON_ENABLED, false );
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( $enabled && ! $scheduled ) {
			wp_schedule_event( time(), 'every_5_minutes', self::CRON_HOOK );
		} elseif ( ! $enabled && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Carga LMS_Migrator y sus dependencias si el autoload del plugin aún no
	 * las registró. Público: también lo usa la vista para mostrar
	 * get_status()/reconcile() (F1.7).
	 *
	 * @return bool true si la clase queda disponible.
	 */
	public static function ensure_migrator_loaded(): bool {
		if ( class_exists( '\ATORA\LMS\LMS_Migrator' ) ) { return true; }

		$base = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'lms/' : __DIR__ . '/';

		$course_file   = $base . 'class-lms-course-service.php';
		$migrator_file = $base . 'class-lms-migrator.php';

		if ( file_exists( $course_file ) )   { require_once $course_file; }
		if ( file_exists( $migrator_file ) ) { require_once $migrator_file; }

		return class_exists( '\ATORA\LMS\LMS_Migrator' );
	}

	/**
	 * Tick de cron de continuación (F1.7). Corre un lote de migrate_all() y,
	 * en cuanto get_status()['is_complete'] sea true (reconcile() en 0
	 * pendientes), se autodesactiva: limpia el evento y apaga la option.
	 */
	public static function run_cron_batch(): void {
		if ( ! self::ensure_migrator_loaded() ) { return; }

		$batch = max( 5, min( 100, absint( get_option( self::OPT_CRON_BATCH, 30 ) ) ) );
		\ATORA\LMS\LMS_Migrator::migrate_all( $batch );

		$status = \ATORA\LMS\LMS_Migrator::get_status();
		if ( ! empty( $status['is_complete'] ) ) {
			update_option( self::OPT_CRON_ENABLED, false );
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Programa el cron de reconciliación diaria (F2.4) si no está programado.
	 */
	private static function sync_reconcile_schedule(): void {
		if ( ! wp_next_scheduled( self::RECONCILE_CRON_HOOK ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::RECONCILE_CRON_HOOK );
		}
	}

	/**
	 * Tick del cron diario: llama a reconcile() y guarda el resultado (F2.4).
	 */
	public static function run_reconcile_check(): void {
		if ( ! self::ensure_migrator_loaded() ) { return; }
		$result = \ATORA\LMS\LMS_Migrator::reconcile();
		update_option( self::OPT_RECONCILE_RESULT, array(
			'reconcile'  => $result,
			'total'      => array_sum( $result ),
			'checked_at' => current_time( 'mysql', true ),
		), false );
	}

	/**
	 * Handler AJAX — activa/desactiva dualwrite (F2).
	 */
	public static function ajax_toggle_dualwrite(): void {
		check_ajax_referer( 'atora_lms_migration', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'atora-lms' ) ), 403 );
		}
		$enabled = ! empty( $_POST['enabled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_option( 'atora_lms_dualwrite', $enabled );
		wp_send_json_success( array( 'enabled' => $enabled ) );
	}

	/**
	 * Handler AJAX — activa/desactiva el cron de continuación.
	 * Nonce: atora_lms_migration
	 */
	public static function ajax_toggle_cron(): void {
		check_ajax_referer( 'atora_lms_migration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'atora-lms' ) ), 403 );
		}

		$enabled = ! empty( $_POST['enabled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		update_option( self::OPT_CRON_ENABLED, $enabled );
		self::sync_cron_schedule();

		wp_send_json_success( array(
			'enabled'  => (bool) get_option( self::OPT_CRON_ENABLED, false ),
			'next_run' => wp_next_scheduled( self::CRON_HOOK ),
		) );
	}

	/**
	 * Handler AJAX — ejecuta la migración en batches.
	 * Nonce: atora_lms_migration
	 */
	public static function ajax_run_migration(): void {
		check_ajax_referer( 'atora_lms_migration', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'atora-lms' ) ), 403 );
		}

		$batch = min( 100, max( 5, absint( $_POST['batch'] ?? 30 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! self::ensure_migrator_loaded() ) {
			wp_send_json_error( array( 'message' => 'LMS_Migrator no disponible. Verifica que el plugin está activo.' ), 500 );
		}

		// Ejecutar migración
		$result = \ATORA\LMS\LMS_Migrator::migrate_all( $batch );
		$status = \ATORA\LMS\LMS_Migrator::get_status();

		wp_send_json_success( array(
			'courses_migrated'    => absint( $result['courses']['migrated']              ?? 0 ),
			'courses_skipped'     => absint( $result['courses']['skipped']               ?? 0 ),
			'courses_errors'      => absint( $result['courses']['errors']                ?? 0 ),
			'lessons_migrated'    => absint( $status['migrated_lessons']                 ?? 0 ),
			'enroll_migrated'     => absint( $result['enrollments']['migrated']          ?? 0 ),
			'progress_migrated'   => absint( $result['lesson_progress']['migrated']      ?? 0 ),
			'programs_migrated'   => absint( $result['programs']['migrated']             ?? 0 ),
			'prog_enroll_migrated'=> absint( $result['program_enrollments']['migrated']  ?? 0 ),
			'quizzes_migrated'    => absint( $result['quizzes']['migrated']              ?? 0 ),
			'submissions_migrated'=> absint( $result['quiz_submissions']['migrated']     ?? 0 ),
			'gradebook_upserted'  => absint( $result['gradebook']['upserted']            ?? 0 ),
			'certs_upserted'      => absint( $result['certificates']['upserted']         ?? 0 ),
			'terms_upserted'      => absint( $result['course_terms']['upserted']         ?? 0 ),
			'courses_pct'         => (float) ( $status['courses_pct']    ?? 0 ),
			'lessons_pct'         => (float) ( $status['lessons_pct']    ?? 0 ),
			'cpt_courses'         => absint( $status['cpt_courses']      ?? 0 ),
			'migrated_courses'    => absint( $status['migrated_courses'] ?? 0 ),
			'migrated_lessons'    => absint( $status['migrated_lessons'] ?? 0 ),
			'migrated_enroll'     => absint( $status['migrated_enroll']  ?? 0 ),
			'reconcile'           => $status['reconcile']     ?? array(),
			'pending_total'       => absint( $status['pending_total'] ?? 0 ),
			'is_complete'         => (bool) ( $status['is_complete']  ?? false ),
			'executed_at'         => current_time( 'mysql' ),
		) );
	}

	// ── F3: paridad ───────────────────────────────────────────────────────────

	/**
	 * Asegura que la tabla de paridad existe. Llamado en init() con coste mínimo
	 * (solo crea si no existe, verificado con SHOW TABLES en LMS_Parity).
	 */
	private static function ensure_parity_table(): void {
		if ( class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
			\ATORA\LMS\LMS_Parity::ensure_table();
		}
	}

	/**
	 * Handler AJAX — stats de paridad para el panel (F3.3).
	 */
	public static function ajax_parity_stats(): void {
		check_ajax_referer( 'atora_lms_migration', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		if ( ! class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
			wp_send_json_error( array( 'message' => 'LMS_Parity no disponible.' ), 500 );
		}
		wp_send_json_success( array(
			'reader_stats'  => \ATORA\LMS\LMS_Parity::get_reader_stats(),
			'daily_counts'  => \ATORA\LMS\LMS_Parity::get_daily_counts(),
			'total'         => \ATORA\LMS\LMS_Parity::total_divergences(),
			'recent'        => \ATORA\LMS\LMS_Parity::get_recent( 20 ),
			'volume'        => \ATORA\LMS\LMS_Parity::get_volume_stats(),
			'read_source'   => \ATORA\LMS\LMS_Read_Router::source(),
			'checked_at'    => current_time( 'mysql' ),
		) );
	}

	/**
	 * Handler AJAX — export CSV del log de paridad (F3.3).
	 */
	public static function ajax_parity_export(): void {
		check_ajax_referer( 'atora_lms_migration', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.', 403 );
		}
		if ( ! class_exists( '\ATORA\LMS\LMS_Parity' ) ) {
			wp_die( 'LMS_Parity no disponible.', 500 );
		}

		$rows = \ATORA\LMS\LMS_Parity::get_recent( 1000 );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="parity-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'reader', 'user_id', 'wp_course_id', 'legacy_summary', 'table_summary', 'logged_at' ) );
		foreach ( $rows as $row ) {
			fputcsv( $out, array(
				$row['reader'],
				$row['user_id'],
				$row['wp_course_id'],
				$row['legacy_summary'],
				$row['table_summary'],
				$row['logged_at'],
			) );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Handler AJAX — flip/rollback de la fuente de lectura (F4.2 / D-007).
	 *
	 * Requiere manage_options. Acepta 'source' = 'legacy' | 'tables'.
	 * Al hacer flip a 'tables', guarda timestamp en atora_lms_cutover_at.
	 */
	public static function ajax_toggle_read_source(): void {
		check_ajax_referer( 'atora_lms_migration', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
		}

		$source = sanitize_key( (string) ( $_POST['source'] ?? '' ) );
		if ( ! in_array( $source, array( 'legacy', 'tables' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Valor de fuente inválido.' ) );
		}

		if ( 'tables' === $source ) {
			// Gate D-006 completo (F4 — task 1.3), no solo dualwrite.
			$gate = class_exists( '\ATORA\LMS\LMS_Parity' )
				? \ATORA\LMS\LMS_Parity::cutover_ready()
				: array( 'ready' => false, 'reasons' => array( 'LMS_Parity no disponible.' ) );

			if ( empty( $gate['ready'] ) ) {
				wp_send_json_error( array(
					'message' => 'Gate de cutover no superado: ' . implode( ' ', $gate['reasons'] ),
					'reasons' => $gate['reasons'],
				) );
			}

			// Guardar snapshot de la fuente anterior antes del flip (F4 — task 1.4).
			$snapshot   = get_option( 'atora_lms_cutover_log', array() );
			$snapshot[] = array(
				'action'     => 'flip',
				'from'       => \ATORA\LMS\LMS_Read_Router::source(),
				'to'         => 'tables',
				'at'         => current_time( 'mysql', true ),
				'by_user_id' => get_current_user_id(),
			);
			update_option( 'atora_lms_cutover_log', $snapshot, false );

			// Guardar timestamp de cutover para el contador de días estables (F4.3).
			update_option( 'atora_lms_cutover_at', current_time( 'mysql', true ), false );
		} else {
			$snapshot   = get_option( 'atora_lms_cutover_log', array() );
			$snapshot[] = array(
				'action'     => 'rollback',
				'from'       => \ATORA\LMS\LMS_Read_Router::source(),
				'to'         => 'legacy',
				'at'         => current_time( 'mysql', true ),
				'by_user_id' => get_current_user_id(),
			);
			update_option( 'atora_lms_cutover_log', $snapshot, false );
		}

		\ATORA\LMS\LMS_Read_Router::set_source( $source );

		wp_send_json_success( array(
			'source'     => $source,
			'cutover_at' => 'tables' === $source ? (string) get_option( 'atora_lms_cutover_at', '' ) : '',
		) );
	}
}
