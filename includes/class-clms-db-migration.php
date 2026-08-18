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
	const SCHEMA_VERSION     = '4.22.0';

	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'maybe_run' ), 5 );
		add_action( 'clms_db_migrate', array( $this, 'run' ) );
	}

	/**
	 * Corre la migración solo si la versión del schema cambió.
	 */
	public function maybe_run() {
		$current = get_option( self::SCHEMA_VERSION_KEY, '0' );
		if ( version_compare( $current, self::SCHEMA_VERSION, '>=' ) ) {
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

		update_option( self::SCHEMA_VERSION_KEY, self::SCHEMA_VERSION );

		do_action( 'clms_db_migration_completed', self::SCHEMA_VERSION );
	}

	// ── Tablas ───────────────────────────────────────────────────────────────

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
}
