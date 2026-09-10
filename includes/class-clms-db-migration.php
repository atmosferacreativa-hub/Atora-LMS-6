<?php
/**
 * CLMS_DB_Migration — Migraciones de base de datos para ATORA LMS.
 *
 * Crea las tablas necesarias para el Grading Engine v2 y el Feedback Loop.
 * Se ejecuta en plugins_loaded vía dbDelta, por lo que es seguro de correr
 * múltiples veces (solo crea si no existe, actualiza si cambia la definición).
 *
 * @package CustomLMSCore
 * @since   4.22
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_DB_Migration {

	const SCHEMA_VERSION_KEY = 'clms_db_schema_version';
	const SCHEMA_VERSION_FALLBACK = '6.13.3';

	public function __construct() {
		// Nota: este módulo se instancia a través del loader en `init`, por lo que
		// cuando llega aquí `plugins_loaded` ya pudo haber corrido. Si solo
		// enganchamos a `plugins_loaded`, la migración no se ejecuta nunca.
		add_action( 'plugins_loaded', array( $this, 'maybe_run' ), 5 );
		if ( did_action( 'plugins_loaded' ) ) {
			$this->maybe_run();
		}
		add_action( 'clms_db_migrate', array( $this, 'run' ) );
	}

	/**
	 * Corre la migración solo si la versión del schema cambió.
	 */
	public function maybe_run() {
		$schema_version = $this->get_schema_version();
		$current        = get_option( self::SCHEMA_VERSION_KEY, '0' );
		if ( version_compare( $current, $schema_version, '>=' ) ) {
			return;
		}
		$this->run();
	}

	/**
	 * Ejecuta todas las migraciones pendientes.
	 */
	public function run() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$this->create_grade_appeals_table();
		$this->create_progress_tracking_table();
		$this->create_learning_resources_table();
		$this->create_gamification_profiles_table();
		$this->create_groups_tables();
		$this->create_early_warning_table();
		$this->create_student_analytics_table();
		$this->create_peer_review_audit_log_table();
		$this->create_portfolios_tables();
		$this->create_google_classroom_tables();
		$this->create_h5p_content_table();
		$this->create_h5p_library_table();
		$this->create_h5p_tracking_table();

		if ( ! $this->schema_is_complete() ) {
			// No se marca la migración como completa: si dbDelta() falló en
			// crear alguna tabla esperada (p. ej. por permisos de DB), la
			// opción de versión de esquema se queda como está para que
			// maybe_run() lo vuelva a intentar en la siguiente carga.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[ATORA][DB_MIGRATION] Migración incompleta: faltan tablas esperadas tras dbDelta().' );
			add_action( 'admin_notices', array( $this, 'render_incomplete_migration_notice' ) );
			return;
		}

		update_option( self::SCHEMA_VERSION_KEY, $this->get_schema_version() );

		do_action( 'clms_db_migration_completed', $this->get_schema_version() );
	}

	/**
	 * Nombres de tabla (sin prefijo) que la migración debe dejar creados.
	 *
	 * @return string[]
	 */
	private function expected_tables(): array {
		return array(
			'clms_grade_appeals',
			'clms_progress_tracking',
			'clms_learning_resources',
			'clms_gamification_profiles',
			'clms_groups',
			'clms_group_members',
			'clms_group_submissions',
			'clms_group_grade_overrides',
			'clms_group_audit_log',
			'atora_early_warning',
			'atora_student_analytics',
			'clms_peer_review_audit_log',
			'atora_portfolios',
			'atora_portfolio_assessments',
			'atora_portfolio_items',
			'atora_portfolio_feedback',
			'atora_google_classroom_course_map',
			'atora_google_classroom_sync_log',
			'atora_google_classroom_coursework_map',
			'atora_h5p_content',
			'atora_h5p_library',
			'atora_h5p_tracking',
		);
	}

	/**
	 * Verifica que todas las tablas esperadas existan realmente en la DB.
	 */
	private function schema_is_complete(): bool {
		global $wpdb;
		foreach ( $this->expected_tables() as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $found !== $table ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Aviso en el admin cuando la migración no pudo completarse.
	 */
	public function render_incomplete_migration_notice(): void {
		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'ATORA LMS: la migración de base de datos no se completó correctamente. Revisa los permisos de la base de datos y recarga esta página para reintentar.', 'atora-lms' ) .
			'</p></div>';
	}

	// ── Tablas ───────────────────────────────────────────────────────────────

	/**
	 * Versión efectiva del schema.
	 *
	 * Se alinea con la versión del plugin cuando está disponible para evitar
	 * inconsistencias entre ATORA_LMS_VERSION y la opción de migración.
	 */
	private function get_schema_version(): string {
		if ( defined( 'ATORA_LMS_VERSION' ) && '' !== (string) ATORA_LMS_VERSION ) {
			return (string) ATORA_LMS_VERSION;
		}
		return self::SCHEMA_VERSION_FALLBACK;
	}

	private function create_grade_appeals_table() {
		global $wpdb;

		$table   = $wpdb->prefix . 'clms_grade_appeals';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			appeal_id varchar(50) NOT NULL,
			student_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned NOT NULL,
			submission_id bigint(20) unsigned NOT NULL,
			component varchar(50) NOT NULL DEFAULT 'assignment',
			original_grade decimal(5,2) DEFAULT NULL,
			reviewed_grade decimal(5,2) DEFAULT NULL,
			reason text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			reviewer_notes text,
			reviewed_by bigint(20) unsigned DEFAULT NULL,
			submitted_at datetime NOT NULL,
			reviewed_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY appeal_id (appeal_id),
			KEY student_id (student_id),
			KEY course_id (course_id),
			KEY status (status)
		) {$charset};";

		dbDelta( $sql );
	}

	private function create_progress_tracking_table() {
		global $wpdb;

		$table   = $wpdb->prefix . 'clms_progress_tracking';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			tracking_id varchar(50) NOT NULL,
			student_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned NOT NULL,
			gaps_tracked longtext NOT NULL,
			start_date datetime NOT NULL,
			checkpoints longtext,
			metrics longtext,
			last_updated datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY tracking_id (tracking_id),
			KEY student_id (student_id),
			KEY course_id (course_id)
		) {$charset};";

		dbDelta( $sql );
	}

	private function create_learning_resources_table() {
		global $wpdb;

		$table   = $wpdb->prefix . 'clms_learning_resources';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			course_id bigint(20) unsigned NOT NULL,
			gap_area varchar(100) NOT NULL,
			resource_type varchar(50) NOT NULL,
			title varchar(255) NOT NULL,
			description text,
			url varchar(500),
			content longtext,
			difficulty_level varchar(20),
			estimated_time int(11),
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY course_id (course_id),
			KEY gap_area (gap_area)
		) {$charset};";

		dbDelta( $sql );
	}

	private function create_gamification_profiles_table() {
		global $wpdb;

		$table   = $wpdb->prefix . 'clms_gamification_profiles';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			student_id bigint(20) unsigned NOT NULL,
			gamer_type varchar(50),
			motivation_profile longtext,
			preferences longtext,
			engagement_history longtext,
			last_updated datetime DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY student_id (student_id)
		) {$charset};";

		dbDelta( $sql );
	}

	private function create_groups_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$groups = $wpdb->prefix . 'clms_groups';
		$sql = "CREATE TABLE {$groups} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			course_id bigint(20) unsigned NOT NULL,
			name varchar(255) NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			locked_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY course_id (course_id),
			KEY locked_at (locked_at)
		) {$charset};";
		dbDelta( $sql );

		$members = $wpdb->prefix . 'clms_group_members';
		$sql = "CREATE TABLE {$members} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			group_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			joined_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY group_user (group_id, user_id),
			KEY user_id (user_id)
		) {$charset};";
		dbDelta( $sql );

		$subs = $wpdb->prefix . 'clms_group_submissions';
		$sql = "CREATE TABLE {$subs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			group_id bigint(20) unsigned NOT NULL,
			lesson_id bigint(20) unsigned NOT NULL,
			submission_id bigint(20) unsigned NOT NULL,
			submitted_by bigint(20) unsigned NOT NULL DEFAULT 0,
			submitted_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY group_lesson (group_id, lesson_id),
			KEY submission_id (submission_id),
			KEY lesson_id (lesson_id)
		) {$charset};";
		dbDelta( $sql );

		$overrides = $wpdb->prefix . 'clms_group_grade_overrides';
		$sql = "CREATE TABLE {$overrides} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			group_id bigint(20) unsigned NOT NULL,
			lesson_id bigint(20) unsigned NOT NULL,
			student_id bigint(20) unsigned NOT NULL,
			override_grade int(11) DEFAULT NULL,
			reason text,
			set_by bigint(20) unsigned NOT NULL DEFAULT 0,
			set_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY group_lesson (group_id, lesson_id),
			KEY student_id (student_id)
		) {$charset};";
		dbDelta( $sql );

		$audit = $wpdb->prefix . 'clms_group_audit_log';
		$sql = "CREATE TABLE {$audit} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			group_id bigint(20) unsigned NOT NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(50) NOT NULL,
			data longtext,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY group_id (group_id),
			KEY actor_id (actor_id),
			KEY action (action)
		) {$charset};";
		dbDelta( $sql );
	}

	private function create_h5p_content_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'atora_h5p_content';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_post_id bigint(20) unsigned NOT NULL,
			provider varchar(50) NOT NULL DEFAULT 'wp_h5p',
			external_id varchar(190) NOT NULL DEFAULT '',
			visibility varchar(20) NOT NULL DEFAULT 'private',
			status varchar(20) NOT NULL DEFAULT 'active',
			license varchar(100) NOT NULL DEFAULT '',
			content_json longtext,
			tags_json longtext,
			author_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY wp_post_id (wp_post_id),
			KEY provider (provider),
			KEY status (status)
		) {$charset};";

		dbDelta( $sql );
	}

	private function create_h5p_library_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'atora_h5p_library';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			machine_name varchar(120) NOT NULL,
			major_version int(11) NOT NULL DEFAULT 0,
			minor_version int(11) NOT NULL DEFAULT 0,
			patch_version int(11) NOT NULL DEFAULT 0,
			title varchar(255) NOT NULL DEFAULT '',
			license varchar(100) NOT NULL DEFAULT '',
			metadata_json longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY lib_version (machine_name, major_version, minor_version, patch_version),
			KEY machine_name (machine_name)
		) {$charset};";

		dbDelta( $sql );
	}

	private function create_h5p_tracking_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'atora_h5p_tracking';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			lesson_id bigint(20) unsigned NOT NULL,
			course_id bigint(20) unsigned NOT NULL DEFAULT 0,
			h5p_content_id bigint(20) unsigned NOT NULL,
			attempts int(11) NOT NULL DEFAULT 0,
			score_raw decimal(10,2) DEFAULT NULL,
			score_max decimal(10,2) DEFAULT NULL,
			score_percent int(11) DEFAULT NULL,
			completion_status varchar(20) NOT NULL DEFAULT '',
			last_verb varchar(200) NOT NULL DEFAULT '',
			first_event_at datetime DEFAULT NULL,
			last_event_at datetime DEFAULT NULL,
			last_statement_json longtext,
			data_json longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY user_lesson_content (user_id, lesson_id, h5p_content_id),
			KEY course_id (course_id),
			KEY lesson_id (lesson_id),
			KEY last_event_at (last_event_at)
		) {$charset};";

		dbDelta( $sql );
	}

	private function create_early_warning_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'atora_early_warning';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			course_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			warning_type varchar(50) NOT NULL,
			data longtext,
			severity int(11) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'open',
			last_notified_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY course_user_type (course_id, user_id, warning_type),
			KEY status (status),
			KEY course_id (course_id),
			KEY user_id (user_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Snapshots de analítica académica (riesgo) por estudiante/curso.
	 *
	 * @return void
	 */
	private function create_student_analytics_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'atora_student_analytics';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			course_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			risk_level varchar(20) NOT NULL DEFAULT 'unknown',
			risk_score int(10) unsigned NOT NULL DEFAULT 0,
			risk_score_prev int(10) unsigned DEFAULT NULL,
			risk_score_delta int(11) DEFAULT NULL,
			risk_trend varchar(10) NOT NULL DEFAULT 'new',
			last_alert_type varchar(30) DEFAULT NULL,
			signals_json longtext,
			last_activity_at datetime DEFAULT NULL,
			last_notified_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY course_user (course_id, user_id),
			KEY course_id (course_id),
			KEY user_id (user_id),
			KEY risk_score (risk_score),
			KEY risk_trend (risk_trend),
			KEY updated_at (updated_at)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Auditoría de coevaluación (peer review).
	 *
	 * @return void
	 */
	private function create_peer_review_audit_log_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'clms_peer_review_audit_log';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(40) NOT NULL,
			lesson_id bigint(20) unsigned NOT NULL DEFAULT 0,
			submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
			assignment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reviewer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reviewee_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			meta_json longtext,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY event_type (event_type),
			KEY lesson_id (lesson_id),
			KEY submission_id (submission_id),
			KEY reviewer_id (reviewer_id),
			KEY reviewee_id (reviewee_id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Portafolios (E-portfolios): colecciones de evidencias por estudiante/curso.
	 *
	 * @return void
	 */
	private function create_portfolios_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$portfolios = $wpdb->prefix . 'atora_portfolios';
		$sql = "CREATE TABLE {$portfolios} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			course_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			title varchar(255) NOT NULL,
			visibility varchar(20) NOT NULL DEFAULT 'teachers',
			public_slug varchar(64) DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY course_user (course_id, user_id),
			UNIQUE KEY public_slug (public_slug),
			KEY course_id (course_id),
			KEY user_id (user_id),
			KEY visibility (visibility),
			KEY updated_at (updated_at)
		) {$charset};";
		dbDelta( $sql );

		$assess = $wpdb->prefix . 'atora_portfolio_assessments';
		$sql = "CREATE TABLE {$assess} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			portfolio_id bigint(20) unsigned NOT NULL,
			rubric_id bigint(20) unsigned NOT NULL DEFAULT 0,
			assessed_by bigint(20) unsigned NOT NULL DEFAULT 0,
			is_final tinyint(1) NOT NULL DEFAULT 1,
			total_percent int(10) unsigned NOT NULL DEFAULT 0,
			scores_json longtext,
			comment longtext,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY portfolio_id (portfolio_id),
			KEY rubric_id (rubric_id),
			KEY assessed_by (assessed_by),
			KEY is_final (is_final),
			KEY created_at (created_at)
		) {$charset};";
		dbDelta( $sql );

		$items = $wpdb->prefix . 'atora_portfolio_items';
		$sql = "CREATE TABLE {$items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			portfolio_id bigint(20) unsigned NOT NULL,
			submission_id bigint(20) unsigned NOT NULL,
			lesson_id bigint(20) unsigned NOT NULL DEFAULT 0,
			position int(11) NOT NULL DEFAULT 0,
			title_override varchar(255) DEFAULT NULL,
			reflection longtext,
			tags_json longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY portfolio_submission (portfolio_id, submission_id),
			KEY portfolio_id (portfolio_id),
			KEY submission_id (submission_id),
			KEY lesson_id (lesson_id),
			KEY position (position),
			KEY updated_at (updated_at)
		) {$charset};";
		dbDelta( $sql );

		$feedback = $wpdb->prefix . 'atora_portfolio_feedback';
		$sql = "CREATE TABLE {$feedback} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			portfolio_id bigint(20) unsigned NOT NULL,
			item_id bigint(20) unsigned DEFAULT NULL,
			author_id bigint(20) unsigned NOT NULL,
			author_role varchar(20) NOT NULL DEFAULT 'teacher',
			comment longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY portfolio_id (portfolio_id),
			KEY item_id (item_id),
			KEY author_id (author_id),
			KEY created_at (created_at)
		) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Epic 6 — Google Classroom: mapeo de curso WP ↔ Classroom + logs de sync.
	 *
	 * @return void
	 */
	private function create_google_classroom_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$map = $wpdb->prefix . 'atora_google_classroom_course_map';
		$sql = "CREATE TABLE {$map} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_course_id bigint(20) unsigned NOT NULL,
			gc_course_id varchar(64) NOT NULL,
			gc_course_name varchar(255) DEFAULT NULL,
			owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			last_roster_sync_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY wp_course_id (wp_course_id),
			KEY gc_course_id (gc_course_id),
			KEY owner_user_id (owner_user_id),
			KEY last_roster_sync_at (last_roster_sync_at),
			KEY updated_at (updated_at)
		) {$charset};";
		dbDelta( $sql );

		$log = $wpdb->prefix . 'atora_google_classroom_sync_log';
		$sql = "CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_course_id bigint(20) unsigned NOT NULL DEFAULT 0,
			gc_course_id varchar(64) NOT NULL DEFAULT '',
			sync_type varchar(30) NOT NULL,
			status varchar(20) NOT NULL,
			message text,
			meta_json longtext,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY wp_course_id (wp_course_id),
			KEY gc_course_id (gc_course_id),
			KEY sync_type (sync_type),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";
		dbDelta( $sql );

		$coursework = $wpdb->prefix . 'atora_google_classroom_coursework_map';
		$sql = "CREATE TABLE {$coursework} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_course_id bigint(20) unsigned NOT NULL,
			gc_course_id varchar(64) NOT NULL,
			gc_coursework_id varchar(64) NOT NULL,
			wp_lesson_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title varchar(255) DEFAULT NULL,
			due_date varchar(20) DEFAULT NULL,
			due_time varchar(20) DEFAULT NULL,
			state varchar(30) DEFAULT NULL,
			payload_json longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY gc_coursework (gc_course_id, gc_coursework_id),
			KEY wp_course_id (wp_course_id),
			KEY wp_lesson_id (wp_lesson_id),
			KEY updated_at (updated_at)
		) {$charset};";
		dbDelta( $sql );
	}
}
