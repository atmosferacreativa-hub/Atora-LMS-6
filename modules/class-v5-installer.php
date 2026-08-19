<?php
/**
 * ATORA LMS v5 — Instalador de base de datos
 *
 * Crea / actualiza todas las tablas nuevas introducidas en v5.
 * Se invoca desde el activation hook y también al hacer upgrade.
 *
 * @package ATORA_LMS
 * @since   5.0.0
 */

namespace ATORA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class V5_Installer
 *
 * @since 5.0.0
 */
class V5_Installer {

	/** Versión del esquema. Incrementar para forzar re-instalación. */
	const SCHEMA_VERSION = '5.1.1-course-wp-post-id-nullable';

	/** Option key que almacena la versión instalada. */
	const OPTION_KEY = 'atora_v5_schema_version';

	/**
	 * Ejecuta la instalación si la versión de esquema cambió.
	 *
	 * @return void
	 */
	public static function install(): void {
		if ( get_option( self::OPTION_KEY ) === self::SCHEMA_VERSION ) {
			return;
		}

		if ( self::create_tables() ) {
			self::migrate_course_wp_post_id_nullable();
			update_option( self::OPTION_KEY, self::SCHEMA_VERSION );
		}
	}

	/**
	 * Fuerza la re-creación de todas las tablas (usado en testing y re-instalaciones).
	 *
	 * @return void
	 */
	public static function force_install(): void {
		if ( self::create_tables() ) {
			self::migrate_course_wp_post_id_nullable();
			update_option( self::OPTION_KEY, self::SCHEMA_VERSION );
		}
	}

	/**
	 * PT-2 (6.5.3): wp_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0 con
	 * UNIQUE KEY solo permitía una fila con 0 — un segundo curso
	 * nativo sin CPT asociado fallaba al crearse. dbDelta() no
	 * modifica de forma confiable NOT NULL/DEFAULT de una columna ya
	 * existente (limitación conocida), así que el cambio de esquema
	 * en atora_courses se hace explícito acá, con ALTER TABLE directo
	 * — install nuevo ya crea la columna nullable vía create_tables(),
	 * esto es solo para instalaciones existentes.
	 *
	 * Alcance: solo atora_courses, que es el hallazgo confirmado de
	 * este sprint. atora_lessons/atora_programs/atora_quiz_submissions
	 * comparten el mismo defecto de esquema — documentado en
	 * docs/DEUDA-TECNICA.md, no tocado acá (fuera del hallazgo que
	 * ordena este sprint).
	 *
	 * @return void
	 */
	private static function migrate_course_wp_post_id_nullable(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_courses';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN wp_post_id BIGINT UNSIGNED NULL DEFAULT NULL" );

		// Todo registro con 0 "sin vínculo legado" pasa a NULL — a lo
		// sumo una fila podía tener 0 bajo el UNIQUE KEY anterior, así
		// que esto nunca choca con el propio índice único.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "UPDATE {$table} SET wp_post_id = NULL WHERE wp_post_id = 0" );
	}

	/**
	 * Crea o actualiza las tablas v5 usando dbDelta().
	 *
	 * @return bool True cuando todas las tablas v5 esperadas existen.
	 */
	private static function create_tables(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// ── Seguridad: 2FA ─────────────────────────────────────────────────────

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_2fa_tokens (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id       BIGINT UNSIGNED NOT NULL,
			token         VARCHAR(12)     NOT NULL DEFAULT '',
			method        VARCHAR(20)     NOT NULL DEFAULT 'email' COMMENT 'totp|email|sms|whatsapp',
			expires_at    DATETIME        NOT NULL,
			verified      TINYINT(1)      NOT NULL DEFAULT 0,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_method (user_id, method),
			KEY expires_at (expires_at)
		) $charset_collate;" );

		// ── Seguridad: Dispositivos de confianza ───────────────────────────────

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_trusted_devices (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id       BIGINT UNSIGNED NOT NULL,
			device_hash   VARCHAR(64)     NOT NULL DEFAULT '',
			device_label  VARCHAR(120)    NOT NULL DEFAULT '',
			ip_address    VARCHAR(45)     NOT NULL DEFAULT '',
			user_agent    TEXT            NOT NULL,
			expires_at    DATETIME        NOT NULL,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id  (user_id),
			KEY device_hash (device_hash),
			KEY expires_at (expires_at)
		) $charset_collate;" );

		// ── Afiliados ──────────────────────────────────────────────────────────

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_affiliates (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id         BIGINT UNSIGNED NOT NULL,
			referral_code   VARCHAR(30)     NOT NULL DEFAULT '',
			status          VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending|active|suspended|rejected',
			commission_rate DECIMAL(5,2)    NOT NULL DEFAULT 20.00,
			payout_method   VARCHAR(30)     NOT NULL DEFAULT 'paypal' COMMENT 'paypal|bank_transfer',
			payout_details  TEXT            NOT NULL,
			notes           TEXT            NOT NULL,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY referral_code (referral_code),
			KEY user_id (user_id),
			KEY status (status)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_affiliate_clicks (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			affiliate_id BIGINT UNSIGNED NOT NULL,
			ip           VARCHAR(45)     NOT NULL DEFAULT '',
			user_agent   TEXT            NOT NULL,
			landing_url  TEXT            NOT NULL,
			referrer_url TEXT            NOT NULL,
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY affiliate_id (affiliate_id),
			KEY created_at   (created_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_affiliate_commissions (
			id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			affiliate_id      BIGINT UNSIGNED NOT NULL,
			order_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
			amount            DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			commission_amount DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			currency          VARCHAR(3)      NOT NULL DEFAULT 'USD',
			status            VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|paid|rejected',
			approved_at       DATETIME                 DEFAULT NULL,
			paid_at           DATETIME                 DEFAULT NULL,
			created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY affiliate_id (affiliate_id),
			KEY order_id     (order_id),
			KEY status       (status)
		) $charset_collate;" );

		// ── Sprint 3-4: Calendario ─────────────────────────────────────────────

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_calendar_events (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title            VARCHAR(255)    NOT NULL DEFAULT '',
			description      TEXT            NOT NULL,
			event_type       VARCHAR(40)     NOT NULL DEFAULT 'academy_event',
			start_datetime   DATETIME        NOT NULL,
			end_datetime     DATETIME                 DEFAULT NULL,
			course_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			lesson_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
			location         VARCHAR(500)    NOT NULL DEFAULT '',
			max_participants INT UNSIGNED    NOT NULL DEFAULT 0,
			recurrence_rule  VARCHAR(500)    NOT NULL DEFAULT '',
			created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY start_datetime (start_datetime),
			KEY course_id      (course_id),
			KEY event_type     (event_type)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_calendar_bookings (
			id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id           BIGINT UNSIGNED NOT NULL,
			user_id            BIGINT UNSIGNED NOT NULL,
			status             VARCHAR(20)     NOT NULL DEFAULT 'confirmed' COMMENT 'confirmed|cancelled',
			booked_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			cancelled_at       DATETIME                 DEFAULT NULL,
			cancellation_reason TEXT           NOT NULL,
			PRIMARY KEY  (id),
			KEY event_id  (event_id),
			KEY user_id   (user_id),
			KEY status    (status)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_calendar_sync (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id        BIGINT UNSIGNED NOT NULL,
			provider       VARCHAR(20)     NOT NULL DEFAULT 'google' COMMENT 'google|outlook',
			access_token   TEXT            NOT NULL,
			refresh_token  TEXT            NOT NULL,
			expires_at     DATETIME                 DEFAULT NULL,
			sync_enabled   TINYINT(1)      NOT NULL DEFAULT 1,
			last_sync_at   DATETIME                 DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_provider (user_id, provider)
		) $charset_collate;" );

		// ── Sprint 5-6: Email Engine ──────────────────────────────────────────

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_email_queue (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			recipient_email  VARCHAR(200)    NOT NULL DEFAULT '',
			recipient_name   VARCHAR(200)    NOT NULL DEFAULT '',
			user_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
			template_id      INT UNSIGNED    NOT NULL DEFAULT 0,
			subject          VARCHAR(500)    NOT NULL DEFAULT '',
			body_html        LONGTEXT        NOT NULL,
			body_text        LONGTEXT        NOT NULL,
			provider         VARCHAR(30)     NOT NULL DEFAULT 'smtp',
			status           VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending|sending|sent|failed|bounced',
			scheduled_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			sent_at          DATETIME                 DEFAULT NULL,
			opened_at        DATETIME                 DEFAULT NULL,
			clicked_at       DATETIME                 DEFAULT NULL,
			error_message    TEXT            NOT NULL,
			retry_count      TINYINT UNSIGNED NOT NULL DEFAULT 0,
			priority         TINYINT UNSIGNED NOT NULL DEFAULT 5,
			metadata         JSON,
			identity_key     VARCHAR(40)     NOT NULL DEFAULT 'academia',
			created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status_scheduled (status, scheduled_at),
			KEY user_id     (user_id),
			KEY priority    (priority)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_email_templates (
			id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
			key_slug   VARCHAR(80)     NOT NULL DEFAULT '',
			name       VARCHAR(200)    NOT NULL DEFAULT '',
			type       VARCHAR(30)     NOT NULL DEFAULT 'transactional',
			subject    VARCHAR(500)    NOT NULL DEFAULT '',
			body_html  LONGTEXT        NOT NULL,
			body_text  LONGTEXT        NOT NULL,
			variables  JSON,
			category   VARCHAR(40)     NOT NULL DEFAULT 'academic',
			active     TINYINT(1)      NOT NULL DEFAULT 1,
			is_system  TINYINT(1)      NOT NULL DEFAULT 0,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY key_slug (key_slug)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_email_preferences (
			user_id                     BIGINT UNSIGNED NOT NULL,
			academic_notifications      TINYINT(1)      NOT NULL DEFAULT 1,
			grade_notifications         TINYINT(1)      NOT NULL DEFAULT 1,
			new_content_notifications   TINYINT(1)      NOT NULL DEFAULT 1,
			certificate_notifications   TINYINT(1)      NOT NULL DEFAULT 1,
			inactivity_reminders        TINYINT(1)      NOT NULL DEFAULT 1,
			marketing_offers            TINYINT(1)      NOT NULL DEFAULT 0,
			marketing_newsletter        TINYINT(1)      NOT NULL DEFAULT 0,
			marketing_promotions        TINYINT(1)      NOT NULL DEFAULT 0,
			frequency_mode              VARCHAR(20)      NOT NULL DEFAULT 'immediate',
			unsubscribed_all            TINYINT(1)      NOT NULL DEFAULT 0,
			unsubscribed_at             DATETIME                  DEFAULT NULL,
			updated_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (user_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_email_consent_log (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id        BIGINT UNSIGNED NOT NULL,
			action         VARCHAR(30)     NOT NULL DEFAULT '',
			preference_key VARCHAR(60)     NOT NULL DEFAULT '',
			old_value      TINYINT(1)               DEFAULT NULL,
			new_value      TINYINT(1)               DEFAULT NULL,
			ip_address     VARCHAR(45)     NOT NULL DEFAULT '',
			user_agent     TEXT            NOT NULL,
			source         VARCHAR(60)     NOT NULL DEFAULT '',
			created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_email_analytics (
			id                 INT UNSIGNED    NOT NULL AUTO_INCREMENT,
			period_type        VARCHAR(10)     NOT NULL DEFAULT 'day' COMMENT 'day|week|month',
			period_start       DATETIME        NOT NULL,
			period_end         DATETIME        NOT NULL,
			emails_sent        INT UNSIGNED    NOT NULL DEFAULT 0,
			emails_delivered   INT UNSIGNED    NOT NULL DEFAULT 0,
			emails_opened      INT UNSIGNED    NOT NULL DEFAULT 0,
			emails_clicked     INT UNSIGNED    NOT NULL DEFAULT 0,
			emails_bounced     INT UNSIGNED    NOT NULL DEFAULT 0,
			emails_complained  INT UNSIGNED    NOT NULL DEFAULT 0,
			unique_opens       INT UNSIGNED    NOT NULL DEFAULT 0,
			unique_clicks      INT UNSIGNED    NOT NULL DEFAULT 0,
			calculated_metrics JSON,
			PRIMARY KEY  (id),
			UNIQUE KEY period (period_type, period_start)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_email_events (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			queue_id   BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(30)     NOT NULL DEFAULT '',
			event_data JSON,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY queue_type (queue_id, event_type)
		) $charset_collate;" );

		// ── Sprint 7-8: Newsletter ────────────────────────────────────────────

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_newsletters (
			id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
			title           VARCHAR(300)    NOT NULL DEFAULT '',
			type            VARCHAR(20)     NOT NULL DEFAULT 'academic' COMMENT 'academic|commercial',
			sections        JSON,
			template_id     INT UNSIGNED    NOT NULL DEFAULT 0,
			status          VARCHAR(20)     NOT NULL DEFAULT 'draft' COMMENT 'draft|scheduled|sent',
			schedule_type   VARCHAR(20)     NOT NULL DEFAULT 'once',
			schedule_config JSON,
			target_segment  JSON,
			ab_test_enabled TINYINT(1)      NOT NULL DEFAULT 0,
			ab_variants     JSON,
			sent_count      INT UNSIGNED    NOT NULL DEFAULT 0,
			sent_at         DATETIME                 DEFAULT NULL,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status)
		) $charset_collate;" );

		// ── Sprint 9-10: Analytics / Engagement ──────────────────────────────

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_user_engagement (
			user_id          BIGINT UNSIGNED NOT NULL,
			emails_received  INT UNSIGNED    NOT NULL DEFAULT 0,
			emails_opened    INT UNSIGNED    NOT NULL DEFAULT 0,
			emails_clicked   INT UNSIGNED    NOT NULL DEFAULT 0,
			last_open_date   DATETIME                 DEFAULT NULL,
			last_click_date  DATETIME                 DEFAULT NULL,
			engagement_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
			risk_level       VARCHAR(10)     NOT NULL DEFAULT 'medium' COMMENT 'low|medium|high',
			updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (user_id),
			KEY engagement_score (engagement_score),
			KEY risk_level       (risk_level)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_form_entries (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id    BIGINT UNSIGNED NOT NULL,
			user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			entry_data JSON,
			ip_address VARCHAR(45)     NOT NULL DEFAULT '',
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY form_id (form_id)
		) $charset_collate;" );

		// ── Sprint 11-12: Messaging / CRM ────────────────────────────────────

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_message_queue (
			id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			recipient_phone     VARCHAR(30)     NOT NULL DEFAULT '',
			recipient_name      VARCHAR(200)    NOT NULL DEFAULT '',
			user_id             BIGINT UNSIGNED NOT NULL DEFAULT 0,
			channel             VARCHAR(20)     NOT NULL DEFAULT 'email' COMMENT 'whatsapp|telegram|sms|email',
			template_key        VARCHAR(80)     NOT NULL DEFAULT '',
			variables           JSON,
			provider_message_id VARCHAR(100)    NOT NULL DEFAULT '',
			status              VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending|sending|sent|delivered|read|failed',
			scheduled_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			sent_at             DATETIME                 DEFAULT NULL,
			delivered_at        DATETIME                 DEFAULT NULL,
			read_at             DATETIME                 DEFAULT NULL,
			error_message       TEXT            NOT NULL,
			retry_count         TINYINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY status_scheduled (status, scheduled_at),
			KEY user_id          (user_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_message_log (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			queue_id   BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(30)     NOT NULL DEFAULT '',
			event_data JSON,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY queue_id (queue_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_conversations (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			channel         VARCHAR(20)     NOT NULL DEFAULT 'email' COMMENT 'email|whatsapp|telegram|sms|system',
			identity_key    VARCHAR(40)     NOT NULL DEFAULT '',
			subject         VARCHAR(255)    NOT NULL DEFAULT '',
			status          VARCHAR(20)     NOT NULL DEFAULT 'open' COMMENT 'open|pending|closed|archived',
			last_message_at DATETIME                 DEFAULT NULL,
			assigned_to     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			course_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
			order_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY contact_id (contact_id),
			KEY user_id (user_id),
			KEY channel (channel),
			KEY status (status),
			KEY last_message_at (last_message_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_conversation_messages (
			id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id     BIGINT UNSIGNED NOT NULL,
			direction           VARCHAR(20)     NOT NULL DEFAULT 'outbound' COMMENT 'inbound|outbound|system',
			channel             VARCHAR(20)     NOT NULL DEFAULT 'email',
			provider_message_id VARCHAR(100)    NOT NULL DEFAULT '',
			sender              VARCHAR(200)    NOT NULL DEFAULT '',
			recipient           VARCHAR(200)    NOT NULL DEFAULT '',
			subject             VARCHAR(255)    NOT NULL DEFAULT '',
			body_text           LONGTEXT        NOT NULL,
			body_html           LONGTEXT        NOT NULL,
			raw_payload_json    LONGTEXT        NOT NULL,
			status              VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending|sent|delivered|read|failed|received',
			error_message       TEXT            NOT NULL,
			created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY channel (channel),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_contacts (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id    BIGINT UNSIGNED          DEFAULT NULL,
			email      VARCHAR(200)    NOT NULL DEFAULT '',
			name       VARCHAR(200)    NOT NULL DEFAULT '',
			phone      VARCHAR(30)     NOT NULL DEFAULT '',
			whatsapp   VARCHAR(30)     NOT NULL DEFAULT '',
			country    VARCHAR(5)      NOT NULL DEFAULT '',
			city       VARCHAR(100)    NOT NULL DEFAULT '',
			company    VARCHAR(200)    NOT NULL DEFAULT '',
			job_title  VARCHAR(200)    NOT NULL DEFAULT '',
			source     VARCHAR(30)     NOT NULL DEFAULT 'manual' COMMENT 'form|import|woocommerce|manual|registration',
			status     VARCHAR(20)     NOT NULL DEFAULT 'lead' COMMENT 'lead|prospect|student|alumni',
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY email   (email),
				KEY user_id (user_id),
				KEY phone   (phone),
				KEY whatsapp (whatsapp),
				KEY status  (status)
			) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_contact_tags (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			tag_name   VARCHAR(100)    NOT NULL DEFAULT '',
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY contact_tag (contact_id, tag_name),
			KEY tag_name (tag_name)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_contact_activities (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id    BIGINT UNSIGNED NOT NULL,
			activity_type VARCHAR(60)     NOT NULL DEFAULT '',
			activity_data JSON,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_by    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY contact_id    (contact_id),
			KEY activity_type (activity_type),
			KEY created_at    (created_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_contact_notes (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT UNSIGNED NOT NULL,
			user_id    BIGINT UNSIGNED NOT NULL,
			note_text  LONGTEXT        NOT NULL,
			is_pinned  TINYINT(1)      NOT NULL DEFAULT 0,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY contact_id (contact_id)
		) $charset_collate;" );

		// ── Sprint 13-14: Automatizaciones ────────────────────────────────────

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_automations (
			id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
			name            VARCHAR(200) NOT NULL DEFAULT '',
			description     TEXT         NOT NULL,
			trigger_type    VARCHAR(60)  NOT NULL DEFAULT '',
			trigger_config  JSON,
			conditions      JSON,
			actions         JSON,
			active          TINYINT(1)   NOT NULL DEFAULT 0,
			priority        TINYINT UNSIGNED NOT NULL DEFAULT 10,
			created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY trigger_type (trigger_type),
			KEY active       (active)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_automation_queue (
				id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				automation_id  INT UNSIGNED    NOT NULL,
				user_id        BIGINT UNSIGNED NOT NULL,
			action_index   TINYINT UNSIGNED NOT NULL DEFAULT 0,
			action_data    JSON,
			context        JSON,
			execute_at     DATETIME        NOT NULL,
			status         VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT 'pending|running|completed|failed',
			retry_count    TINYINT UNSIGNED NOT NULL DEFAULT 0,
			executed_at    DATETIME                  DEFAULT NULL,
			created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
				KEY status_execute (status, execute_at),
				KEY automation_id  (automation_id)
			) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_automation_execution_log (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			automation_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			queue_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			contact_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			trigger_type    VARCHAR(60)     NOT NULL DEFAULT '',
			action_type     VARCHAR(60)     NOT NULL DEFAULT '',
			action_index    TINYINT UNSIGNED NOT NULL DEFAULT 0,
			status          ENUM('success','failed','skipped') NOT NULL DEFAULT 'success',
			error_message   TEXT            NULL,
			duration_ms     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			context_json    JSON,
			executed_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY automation_id (automation_id),
			KEY user_id       (user_id),
			KEY contact_id    (contact_id),
			KEY status        (status),
			KEY executed_at   (executed_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_companies (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name            VARCHAR(200)    NOT NULL DEFAULT '',
			industry        VARCHAR(100)    NOT NULL DEFAULT '',
			email           VARCHAR(200)    NOT NULL DEFAULT '',
			phone           VARCHAR(30)     NOT NULL DEFAULT '',
			website         VARCHAR(300)    NOT NULL DEFAULT '',
			linkedin_url    VARCHAR(300)    NOT NULL DEFAULT '',
			country         VARCHAR(5)      NOT NULL DEFAULT '',
			city            VARCHAR(100)    NOT NULL DEFAULT '',
			state           VARCHAR(100)    NOT NULL DEFAULT '',
			address         VARCHAR(300)    NOT NULL DEFAULT '',
			employees_count INT UNSIGNED    NOT NULL DEFAULT 0,
			description     TEXT            NOT NULL,
			owner_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_by      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY name (name(100)),
			KEY owner_id (owner_id),
			KEY country (country)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_crm_lists (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title       VARCHAR(200)    NOT NULL DEFAULT '',
			slug        VARCHAR(200)    NOT NULL DEFAULT '',
			description TEXT            NOT NULL,
			type        VARCHAR(30)     NOT NULL DEFAULT 'marketing',
			is_public   TINYINT(1)      NOT NULL DEFAULT 0,
			created_by  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug),
			KEY type (type)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_contact_list_pivot (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id  BIGINT UNSIGNED NOT NULL,
			list_id     BIGINT UNSIGNED NOT NULL,
			status      ENUM('subscribed','unsubscribed','pending') NOT NULL DEFAULT 'subscribed',
			joined_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY contact_list (contact_id, list_id),
			KEY list_id (list_id),
			KEY status (status)
		) $charset_collate;" );

		// ── Fase 10: Carritos abandonados + URL tracking ──────────────────────
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_abandoned_carts (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			checkout_key    VARCHAR(64)     NOT NULL DEFAULT '',
			cart_hash       VARCHAR(64)     NOT NULL DEFAULT '',
			email           VARCHAR(200)    NOT NULL DEFAULT '',
			contact_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			provider        VARCHAR(30)     NOT NULL DEFAULT 'woocommerce',
			cart_data       LONGTEXT        NOT NULL,
			subtotal        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			total           DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			currency        VARCHAR(3)      NOT NULL DEFAULT 'USD',
			status          VARCHAR(20)     NOT NULL DEFAULT 'active',
			order_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			automation_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			is_optout       TINYINT(1)      NOT NULL DEFAULT 0,
			checkout_url    VARCHAR(500)    NOT NULL DEFAULT '',
			abandoned_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			recovered_at    DATETIME                 DEFAULT NULL,
			expires_at      DATETIME                 DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY checkout_key (checkout_key),
			KEY email       (email),
			KEY contact_id  (contact_id),
			KEY status      (status),
			KEY abandoned_at (abandoned_at)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_url_store (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			short_key    VARCHAR(32)     NOT NULL DEFAULT '',
			original_url VARCHAR(2000)   NOT NULL DEFAULT '',
			campaign_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sequence_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY short_key (short_key),
			KEY campaign_id (campaign_id),
			KEY sequence_id (sequence_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_url_clicks (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			url_id          BIGINT UNSIGNED NOT NULL,
			recipient_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			contact_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			clicked_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ip_address      VARCHAR(45)     NOT NULL DEFAULT '',
			user_agent      VARCHAR(500)    NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY url_id      (url_id),
			KEY contact_id  (contact_id),
			KEY clicked_at  (clicked_at)
		) $charset_collate;" );
		// ── /Fase 10 ───────────────────────────────────────────────────────────

		// ── Fase 11: LMS — tablas propias (desacopla wp_posts) ───────────────
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_courses (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_post_id      BIGINT UNSIGNED NULL DEFAULT NULL,
			title           VARCHAR(500)    NOT NULL DEFAULT '',
			slug            VARCHAR(500)    NOT NULL DEFAULT '',
			description     LONGTEXT        NOT NULL,
			excerpt         TEXT            NOT NULL,
			status          VARCHAR(20)     NOT NULL DEFAULT 'draft',
			visibility      VARCHAR(20)     NOT NULL DEFAULT 'public',
			type            VARCHAR(20)     NOT NULL DEFAULT 'self_paced',
			instructor_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
			price           DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			currency        VARCHAR(3)      NOT NULL DEFAULT 'USD',
			duration_hours  DECIMAL(5,1)    NOT NULL DEFAULT 0.0,
			level           VARCHAR(20)     NOT NULL DEFAULT 'beginner',
			language        VARCHAR(5)      NOT NULL DEFAULT 'es',
			thumbnail_url   VARCHAR(500)    NOT NULL DEFAULT '',
			certificate_tpl VARCHAR(200)    NOT NULL DEFAULT '',
			passing_grade   TINYINT UNSIGNED NOT NULL DEFAULT 70,
			settings_json   LONGTEXT        DEFAULT NULL,
			meta_json       LONGTEXT        DEFAULT NULL,
			published_at    DATETIME                 DEFAULT NULL,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY wp_post_id  (wp_post_id),
			KEY status         (status),
			KEY instructor_id  (instructor_id),
			KEY slug           (slug(200))
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_lessons (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_post_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			course_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title           VARCHAR(500)    NOT NULL DEFAULT '',
			slug            VARCHAR(500)    NOT NULL DEFAULT '',
			content         LONGTEXT        NOT NULL,
			lesson_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			section         VARCHAR(200)    NOT NULL DEFAULT '',
			section_order   TINYINT UNSIGNED NOT NULL DEFAULT 0,
			type            VARCHAR(20)     NOT NULL DEFAULT 'text',
			duration_min    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			is_free_preview TINYINT(1)      NOT NULL DEFAULT 0,
			is_required     TINYINT(1)      NOT NULL DEFAULT 1,
			video_url       VARCHAR(500)    NOT NULL DEFAULT '',
			resources_json  LONGTEXT        DEFAULT NULL,
			settings_json   LONGTEXT        DEFAULT NULL,
			status          VARCHAR(20)     NOT NULL DEFAULT 'published',
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY wp_post_id   (wp_post_id),
			KEY course_id       (course_id),
			KEY lesson_order    (course_id, lesson_order),
			KEY section         (course_id, section_order)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_enrollments (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id         BIGINT UNSIGNED NOT NULL,
			course_id       BIGINT UNSIGNED NOT NULL,
			wp_course_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status          VARCHAR(20)     NOT NULL DEFAULT 'active',
			progress_pct    TINYINT UNSIGNED NOT NULL DEFAULT 0,
			grade           DECIMAL(5,2)             DEFAULT NULL,
			order_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			enrolled_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			completed_at    DATETIME                 DEFAULT NULL,
			expires_at      DATETIME                 DEFAULT NULL,
			last_activity   DATETIME                 DEFAULT NULL,
			meta_json       LONGTEXT        DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY user_course  (user_id, course_id),
			KEY status      (status),
			KEY course_id   (course_id),
			KEY enrolled_at (enrolled_at),
			KEY last_activity (last_activity)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_lesson_progress (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id         BIGINT UNSIGNED NOT NULL,
			lesson_id       BIGINT UNSIGNED NOT NULL,
			course_id       BIGINT UNSIGNED NOT NULL,
			wp_lesson_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status          VARCHAR(20)     NOT NULL DEFAULT 'in_progress',
			time_spent_sec  INT UNSIGNED    NOT NULL DEFAULT 0,
			attempts        TINYINT UNSIGNED NOT NULL DEFAULT 1,
			score           DECIMAL(5,2)             DEFAULT NULL,
			completed_at    DATETIME                 DEFAULT NULL,
			last_viewed_at  DATETIME                 DEFAULT NULL,
			meta_json       LONGTEXT        DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY user_lesson  (user_id, lesson_id),
			KEY course_id       (course_id),
			KEY status          (status),
			KEY completed_at    (completed_at)
		) $charset_collate;" );
		// ── /Fase 11 ──────────────────────────────────────────────────────────

		// ── Fase 11b: Tablas LMS extendidas (D-001/D-002/D-003, 2026-06-15) ──

		// Taxonomías/categorías de cursos — tabla pivote (D-003 = B).
		// Reemplaza el enfoque meta_json.categories[] (D-003 = A, descartado).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_course_terms (
			id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			course_id   BIGINT UNSIGNED  NOT NULL,
			taxonomy    VARCHAR(100)     NOT NULL,
			term_slug   VARCHAR(200)     NOT NULL,
			term_name   VARCHAR(200)     NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			UNIQUE KEY uq_course_tax_slug (course_id, taxonomy(80), term_slug(200)),
			KEY idx_taxonomy_slug         (taxonomy(80), term_slug(200))
		) $charset_collate;" );

		// Programas/diplomados (D-001 = A).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_programs (
			id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			wp_post_id      BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			title           VARCHAR(500)     NOT NULL DEFAULT '',
			slug            VARCHAR(500)     NOT NULL DEFAULT '',
			description     LONGTEXT         NOT NULL,
			excerpt         TEXT             NOT NULL,
			status          VARCHAR(20)      NOT NULL DEFAULT 'draft',
			instructor_id   BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			price           DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
			currency        VARCHAR(3)       NOT NULL DEFAULT 'USD',
			duration_hours  DECIMAL(5,1)     NOT NULL DEFAULT 0.0,
			thumbnail_url   VARCHAR(500)     NOT NULL DEFAULT '',
			passing_grade   TINYINT UNSIGNED NOT NULL DEFAULT 70,
			settings_json   LONGTEXT                  DEFAULT NULL,
			meta_json       LONGTEXT                  DEFAULT NULL,
			published_at    DATETIME                  DEFAULT NULL,
			created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY wp_post_id    (wp_post_id),
			KEY status               (status),
			KEY instructor_id        (instructor_id),
			KEY slug                 (slug(200))
		) $charset_collate;" );

		// Matrículas a programa (D-001 = A).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_program_enrollments (
			id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			user_id         BIGINT UNSIGNED  NOT NULL,
			program_id      BIGINT UNSIGNED  NOT NULL,
			wp_program_id   BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			status          VARCHAR(20)      NOT NULL DEFAULT 'active',
			enrolled_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			completed_at    DATETIME                  DEFAULT NULL,
			expires_at      DATETIME                  DEFAULT NULL,
			last_activity   DATETIME                  DEFAULT NULL,
			meta_json       LONGTEXT                  DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY user_program  (user_id, program_id),
			KEY status               (status),
			KEY program_id           (program_id),
			KEY enrolled_at          (enrolled_at)
		) $charset_collate;" );

		// Definición de quiz por lección (D-002; una fila por lección con quiz activo).
		// Las preguntas en JSON provienen de _clms_quiz_questions (postmeta de lm_lesson).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_quizzes (
			id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			lesson_id       BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			wp_lesson_id    BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			course_id       BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			questions_json  LONGTEXT                  DEFAULT NULL,
			settings_json   LONGTEXT                  DEFAULT NULL,
			created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY lesson_id  (lesson_id),
			KEY course_id         (course_id),
			KEY wp_lesson_id      (wp_lesson_id)
		) $charset_collate;" );

		// Intentos de quiz por alumno (D-002; migra CPT clms_submission).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_quiz_submissions (
			id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			wp_post_id      BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			user_id         BIGINT UNSIGNED  NOT NULL,
			quiz_id         BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			lesson_id       BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			course_id       BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			wp_lesson_id    BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			wp_course_id    BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			status          VARCHAR(30)      NOT NULL DEFAULT 'pending',
			grade           DECIMAL(5,2)              DEFAULT NULL,
			feedback        TEXT                      DEFAULT NULL,
			rubric_json     LONGTEXT                  DEFAULT NULL,
			submitted_at    DATETIME                  DEFAULT NULL,
			graded_at       DATETIME                  DEFAULT NULL,
			meta_json       LONGTEXT                  DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY wp_post_id  (wp_post_id),
			KEY user_id            (user_id),
			KEY lesson_id          (lesson_id),
			KEY course_id          (course_id),
			KEY status             (status)
		) $charset_collate;" );

		// Calificaciones finales por alumno/curso (D-002; migra _clms_gradebook_course_{id}).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_gradebook (
			id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			user_id         BIGINT UNSIGNED  NOT NULL,
			course_id       BIGINT UNSIGNED  NOT NULL,
			wp_course_id    BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			final_grade     DECIMAL(5,2)              DEFAULT NULL,
			status          VARCHAR(20)      NOT NULL DEFAULT 'in_progress',
			grade_json      LONGTEXT                  DEFAULT NULL,
			calculated_at   DATETIME                  DEFAULT NULL,
			created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY user_course  (user_id, course_id),
			KEY course_id           (course_id),
			KEY status              (status)
		) $charset_collate;" );

		// Certificados emitidos (D-002; migra _clms_certificate_record_{course_id}).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_certificates (
			id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			user_id           BIGINT UNSIGNED  NOT NULL,
			course_id         BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			program_id        BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			wp_target_id      BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			target_type       VARCHAR(20)      NOT NULL DEFAULT 'course',
			cert_code         VARCHAR(100)     NOT NULL DEFAULT '',
			verification_code VARCHAR(100)     NOT NULL DEFAULT '',
			verification_hash VARCHAR(64)      NOT NULL DEFAULT '',
			status            VARCHAR(20)      NOT NULL DEFAULT 'valid',
			final_grade       DECIMAL(5,2)              DEFAULT NULL,
			progress_pct      TINYINT UNSIGNED NOT NULL DEFAULT 0,
			issued_at         DATETIME                  DEFAULT NULL,
			revoked_at        DATETIME                  DEFAULT NULL,
			meta_json         LONGTEXT                  DEFAULT NULL,
			created_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY cert_code             (cert_code),
			UNIQUE KEY user_target_type      (user_id, target_type, wp_target_id),
			KEY user_id                      (user_id),
			KEY course_id                    (course_id),
			KEY program_id                   (program_id),
			KEY status                       (status),
			KEY verification_code            (verification_code(20))
		) $charset_collate;" );

		// ── /Fase 11b ─────────────────────────────────────────────────────────

		// ── Fase 12C: API Keys para MCP y acceso externo ─────────────────────
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_api_keys (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			name       VARCHAR(200)    NOT NULL DEFAULT '',
			key_hash   VARCHAR(64)     NOT NULL,
			key_prefix VARCHAR(8)      NOT NULL,
			scopes     VARCHAR(500)    NOT NULL DEFAULT 'read',
			last_used  DATETIME                 DEFAULT NULL,
			expires_at DATETIME                 DEFAULT NULL,
			is_active  TINYINT(1)      NOT NULL DEFAULT 1,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY key_hash  (key_hash),
			KEY user_id    (user_id),
			KEY key_prefix (key_prefix),
			KEY is_active  (is_active)
		) $charset_collate;" );
		// ── /Fase 12C ─────────────────────────────────────────────────────────

		// ── Fase III S9: Badges de gamificación ───────────────────────────────
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}clms_badges (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug        VARCHAR(80)     NOT NULL DEFAULT '',
			label       VARCHAR(200)    NOT NULL DEFAULT '',
			icon_emoji  VARCHAR(10)     NOT NULL DEFAULT '🏅',
			description TEXT            NOT NULL,
			criterio    VARCHAR(50)     NOT NULL DEFAULT '' COMMENT 'event_type o condicion',
			threshold   INT UNSIGNED    NOT NULL DEFAULT 1 COMMENT 'cantidad de eventos necesaria',
			is_active   TINYINT(1)      NOT NULL DEFAULT 1,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug),
			KEY criterio (criterio)
		) $charset_collate;" );

		// Insertar 12 badges base (idempotente)
		$badges_table = $wpdb->prefix . 'clms_badges';
		$base_badges  = array(
			array( 'primer_paso',         '🐣', 'Primer Paso',          'Completaste tu primera lección.',       'lesson_completed',    1  ),
			array( 'maratonista',          '🏃', 'Maratonista',          'Completaste 10 lecciones.',             'lesson_completed',    10 ),
			array( 'imparable',            '🚀', 'Imparable',            'Completaste 25 lecciones.',             'lesson_completed',    25 ),
			array( 'entrega_enviada',      '📬', 'Entrega Enviada',      'Enviaste tu primera entrega.',          'submission_sent',     1  ),
			array( 'evaluacion_aprobada',  '✅', 'Evaluación Aprobada',  'Aprobaste tu primera evaluación.',      'evaluation_passed',   1  ),
			array( 'racha_semanal',        '🔥', 'Racha Semanal',        '7 días consecutivos de actividad.',     'daily_login',         7  ),
			array( 'colaborador',          '🤝', 'Colaborador',          'Completaste una revisión por pares.',   'peer_review_done',    1  ),
			array( 'primer_curso',         '🎓', 'Primer Curso',         'Completaste un curso completo.',        'course_completed',    1  ),
			array( 'explorador',           '🗺', 'Explorador',           'Te matriculaste en 3 cursos.',          'course_enrolled',     3  ),
			array( 'feedback_loop',        '💡', 'Feedback Loop',        'Completaste un ciclo de práctica IA.',  'ai_practice_done',    1  ),
			array( 'puntuador',            '⭐', 'Puntuador',            'Acumulaste 500 puntos.',                'points_milestone',    500 ),
			array( 'leyenda',              '🏆', 'Leyenda',              'Acumulaste 2000 puntos.',               'points_milestone',    2000 ),
		);

		$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$badges_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 0 === $existing ) {
			foreach ( $base_badges as $b ) {
				$wpdb->insert(
					$badges_table,
					array(
						'slug'        => sanitize_key( $b[0] ),
						'icon_emoji'  => $b[1],
						'label'       => sanitize_text_field( $b[2] ),
						'description' => sanitize_text_field( $b[3] ),
						'criterio'    => sanitize_key( $b[4] ),
						'threshold'   => absint( $b[5] ),
					),
					array( '%s', '%s', '%s', '%s', '%s', '%d' )
				);
			}
		}
		// ── /Fase III S9 ──────────────────────────────────────────────────────

		// ── Secciones académicas (atora-cohort-sections / DC-1) ──────────────
		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_sections (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_course_id  BIGINT UNSIGNED NOT NULL,
			cohort_id     BIGINT UNSIGNED          DEFAULT NULL,
			title         VARCHAR(200)    NOT NULL DEFAULT '',
			schedule_json LONGTEXT                 DEFAULT NULL,
			capacity      INT UNSIGNED    NOT NULL DEFAULT 0,
			status        VARCHAR(20)     NOT NULL DEFAULT 'active',
			start_date    DATE                     DEFAULT NULL,
			end_date      DATE                     DEFAULT NULL,
			meta_json     LONGTEXT                 DEFAULT NULL,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY wp_course_id (wp_course_id),
			KEY cohort_id    (cohort_id),
			KEY status       (status)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_section_teachers (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			section_id BIGINT UNSIGNED NOT NULL,
			user_id    BIGINT UNSIGNED NOT NULL,
			role       VARCHAR(20)     NOT NULL DEFAULT 'lead',
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY section_user (section_id, user_id),
			KEY user_id (user_id)
		) $charset_collate;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}atora_section_students (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			section_id BIGINT UNSIGNED NOT NULL,
			user_id    BIGINT UNSIGNED NOT NULL,
			status     VARCHAR(30)     NOT NULL DEFAULT 'active',
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY section_user (section_id, user_id),
			KEY user_id (user_id)
		) $charset_collate;" );
		// ── /Secciones académicas ─────────────────────────────────────────────

		// ── Fase IV S13: Webhooks ─────────────────────────────────────────────
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}atora_webhooks (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type VARCHAR(60)     NOT NULL DEFAULT '',
			url        VARCHAR(500)    NOT NULL DEFAULT '',
			secret     VARCHAR(64)     NOT NULL DEFAULT '',
			is_active  TINYINT(1)      NOT NULL DEFAULT 1,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY event_type (event_type),
			KEY is_active  (is_active)
		) $charset_collate;" );
		// ── /Fase IV S13 ──────────────────────────────────────────────────────

		return self::all_tables_exist();
	}

	/**
	 * Verifica que todas las tablas del esquema v5 existan en la base de datos.
	 *
	 * @return bool
	 */
	private static function all_tables_exist(): bool {
		global $wpdb;

		$tables = self::get_tables();
		foreach ( $tables as $table ) {
			$like = $wpdb->esc_like( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
			if ( $exists !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Lista única de tablas v5.
	 *
	 * @return array<int,string>
	 */
	private static function get_tables(): array {
		global $wpdb;

		return array(
			// Security.
			"{$wpdb->prefix}atora_2fa_tokens",
			"{$wpdb->prefix}atora_trusted_devices",
			// Affiliates.
			"{$wpdb->prefix}atora_affiliates",
			"{$wpdb->prefix}atora_affiliate_clicks",
			"{$wpdb->prefix}atora_affiliate_commissions",
			// Calendar.
			"{$wpdb->prefix}atora_calendar_events",
			"{$wpdb->prefix}atora_calendar_bookings",
			"{$wpdb->prefix}atora_calendar_sync",
			// Email Engine.
			"{$wpdb->prefix}atora_email_queue",
			"{$wpdb->prefix}atora_email_templates",
			"{$wpdb->prefix}atora_email_preferences",
			"{$wpdb->prefix}atora_email_consent_log",
			"{$wpdb->prefix}atora_email_analytics",
			"{$wpdb->prefix}atora_email_events",
			// Newsletter.
			"{$wpdb->prefix}atora_newsletters",
			// Analytics.
			"{$wpdb->prefix}atora_user_engagement",
			"{$wpdb->prefix}atora_form_entries",
			// Messaging.
			"{$wpdb->prefix}atora_message_queue",
			"{$wpdb->prefix}atora_message_log",
			"{$wpdb->prefix}atora_conversations",
			"{$wpdb->prefix}atora_conversation_messages",
			// CRM.
			"{$wpdb->prefix}atora_contacts",
			"{$wpdb->prefix}atora_contact_tags",
			"{$wpdb->prefix}atora_contact_activities",
			"{$wpdb->prefix}atora_contact_notes",
			// Automation.
			"{$wpdb->prefix}atora_automations",
			"{$wpdb->prefix}atora_automation_queue",
			"{$wpdb->prefix}atora_automation_execution_log",
			// Fase 9
			"{$wpdb->prefix}atora_companies",
			"{$wpdb->prefix}atora_crm_lists",
			"{$wpdb->prefix}atora_contact_list_pivot",
			// Secciones académicas
			"{$wpdb->prefix}atora_sections",
			"{$wpdb->prefix}atora_section_teachers",
			"{$wpdb->prefix}atora_section_students",
		);
	}

	/**
	 * Elimina todas las tablas v5 (usado en uninstall).
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$tables = self::get_tables();

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( self::OPTION_KEY );
	}
}
