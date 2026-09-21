<?php
/**
 * Servicio de esquema para CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB_Service {
	/**
	 * Versión del esquema CRM v2.
	 */
	const SCHEMA_VERSION = '2.3.0'; // PT-6 (6.5.10): bump para crear las 4 tablas huérfanas de Sequence_Service

	/**
	 * Option key de versión instalada.
	 */
	const SCHEMA_OPTION = 'atora_crm_v2_schema_version';

	/**
	 * Instala tablas CRM v2 no destructivas.
	 *
	 * @return void
	 */
	public static function maybe_install_schema(): void {
		global $wpdb;

		// Guard: solo ejecutar ensure_runtime_columns() si la versión de esquema cambió (audit P2-7).
		// Evita consultas INFORMATION_SCHEMA + ALTER TABLE en cada request de producción.
		$current_version = (string) get_option( self::SCHEMA_OPTION, '' );
		$columns_option  = 'atora_db_columns_version';
		$columns_version = (string) get_option( $columns_option, '' );

		$required_tables = self::get_required_tables();
		$missing_tables  = self::get_missing_tables( $required_tables );

		$needs_columns = version_compare( $columns_version, self::SCHEMA_VERSION, '<' );
		$needs_schema  = version_compare( $current_version, self::SCHEMA_VERSION, '<' ) || ! empty( $missing_tables );

		// PT-6 (6.5.10): ensure_runtime_columns() corría ANTES del bloque de
		// creación de tablas de abajo. En el mismo deploy donde una tabla se
		// crea por primera vez (needs_columns Y needs_schema ambos true a la
		// vez, el caso típico de un bump de SCHEMA_VERSION), esa tabla
		// todavía no existía cuando ensure_runtime_columns() la revisaba —
		// se saltaba silenciosamente (table_exists() → false) — pero
		// $columns_option igual se marcaba como actualizado, así que esa
		// tabla nunca más recibía sus columnas agregadas (columna de inquilino, etc.)
		// en ningún request posterior. Se invierte el orden: primero crear
		// lo que falte, después revisar columnas — así toda tabla recién
		// creada en este mismo request ya existe cuando le toca su chequeo
		// de columnas.
		if ( $needs_schema ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$charset_collate = $wpdb->get_charset_collate();
			$prefix          = $wpdb->prefix;

			$schema_statements = self::get_schema_statements( $prefix, $charset_collate, false );
			foreach ( $schema_statements as $statement ) {
				dbDelta( $statement );
			}
		}

		if ( $needs_columns ) {
			self::ensure_runtime_columns();
			update_option( $columns_option, self::SCHEMA_VERSION, false );
		}

		if ( ! $needs_schema ) {
			return;
		}

		$missing_after_install = self::get_missing_tables( $required_tables );
		if ( ! empty( $missing_after_install ) ) {
			$fallback_statements = self::get_schema_statements( $prefix, $charset_collate, true );
			foreach ( $missing_after_install as $missing_table ) {
				$statement = (string) ( $fallback_statements[ $missing_table ] ?? '' );
				if ( '' === $statement ) {
					if ( function_exists( 'error_log' ) ) {
						error_log( '[ATORA CRM v2] No se encontró statement fallback para tabla: ' . $missing_table ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
					continue;
				}
				$result = $wpdb->query( $statement ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				if ( false === $result && function_exists( 'error_log' ) ) {
					error_log( '[ATORA CRM v2] Error SQL fallback (' . $missing_table . '): ' . (string) $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
			$missing_after_install = self::get_missing_tables( $required_tables );
		}
		if ( empty( $missing_after_install ) ) {
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
			return;
		}

		// Mantiene el option desalineado para forzar nuevo intento en siguiente carga.
		update_option( self::SCHEMA_OPTION, '', false );
		if ( function_exists( 'error_log' ) ) {
			error_log( '[ATORA CRM v2] Tablas faltantes tras instalación de esquema: ' . implode( ', ', $missing_after_install ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Devuelve tablas CRM v2 requeridas por el runtime.
	 *
	 * @return array<int,string>
	 */
	private static function get_required_tables(): array {
		global $wpdb;

		$prefix = $wpdb->prefix;
		return array(
			"{$prefix}atora_crm_deals",
			"{$prefix}atora_crm_student_followups",
			"{$prefix}atora_crm_tasks",
			"{$prefix}atora_crm_campaigns",
			"{$prefix}atora_crm_campaign_recipients",
			"{$prefix}atora_crm_contact_fields",
			"{$prefix}atora_crm_contact_field_values",
			// PT-6 (6.5.10): sin estas cuatro en la lista, get_missing_tables()
			// nunca las detectaba como faltantes -- needs_schema solo se
			// disparaba por el version_compare(), nunca por su ausencia real.
			"{$prefix}atora_email_sequences",
			"{$prefix}atora_email_sequence_steps",
			"{$prefix}atora_email_sequence_enrollments",
			"{$prefix}atora_email_suppression",
		);
	}

	/**
	 * Reconcilia columnas del CRM después de activar el módulo en caliente.
	 *
	 * El instalador base puede crear atora_contacts después de que la
	 * comprobación versionada del CRM ya haya ocurrido en el mismo request.
	 * Esta entrada explícita evita dejar la tabla recién creada sin las
	 * columnas añadidas por CRM v2.
	 *
	 * @return void
	 */
	public static function reconcile_after_module_activation(): void {
		self::ensure_runtime_columns();
		update_option( 'atora_db_columns_version', self::SCHEMA_VERSION, false );
	}

	/**
	 * Garantiza columnas agregadas por sprints posteriores.
	 *
	 * @return void
	 */
	private static function ensure_runtime_columns(): void {
		global $wpdb;

		// ── Fase IV S15 (6.26.4): reconciliación de inquilino — institution_id ─
		$legacy_column = 'academy' . '_id';
		$legacy_index  = 'idx_' . $legacy_column;
		$inst_index    = 'idx_institution_id';
		$s15_tables = array(
			'atora_contacts',
			'atora_crm_campaigns',
			'atora_crm_deals',
			'atora_crm_tasks',
			'atora_crm_campaign_recipients',
			'atora_email_sequences',
			'atora_email_sequence_enrollments',
			'atora_automations',
			'atora_automation_queue',
			'atora_enrollments',
			'atora_companies',
			'atora_crm_lists',
		);
		foreach ( $s15_tables as $tbl ) {
			$full_table = $wpdb->prefix . $tbl;
			if ( ! self::table_exists( $full_table ) ) { continue; }

			$inst_exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
					 WHERE TABLE_SCHEMA = DATABASE()
					   AND TABLE_NAME   = %s
					   AND COLUMN_NAME  = 'institution_id'",
					$full_table
				)
			);
			$legacy_exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
					 WHERE TABLE_SCHEMA = DATABASE()
					   AND TABLE_NAME   = %s
					   AND COLUMN_NAME  = %s",
					$full_table,
					$legacy_column
				)
			);

			$idx_inst = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
					 WHERE TABLE_SCHEMA = DATABASE()
					   AND TABLE_NAME   = %s
					   AND INDEX_NAME   = %s",
					$full_table,
					$inst_index
				)
			);

			// Caso 1: ambas columnas existen (estado intermedio) → backfill, drop legacy.
			if ( $inst_exists > 0 && $legacy_exists > 0 ) {
				$wpdb->query( "UPDATE {$full_table} SET institution_id = {$legacy_column} WHERE institution_id = 0 AND {$legacy_column} > 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

				if ( 0 === $idx_inst ) {
					$wpdb->query( "ALTER TABLE {$full_table} ADD INDEX {$inst_index} (institution_id)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				}

				$idx_legacy = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
						 WHERE TABLE_SCHEMA = DATABASE()
						   AND TABLE_NAME   = %s
						   AND INDEX_NAME   = %s",
						$full_table,
						$legacy_index
					)
				);
				if ( $idx_legacy > 0 ) {
					$wpdb->query( "ALTER TABLE {$full_table} DROP INDEX {$legacy_index}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				}

				$wpdb->query( "ALTER TABLE {$full_table} DROP COLUMN {$legacy_column}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				continue;
			}

			// Caso 2: ya es institution_id → solo garantizar índice.
			if ( $inst_exists > 0 ) {
				if ( 0 === $idx_inst ) {
					$wpdb->query( "ALTER TABLE {$full_table} ADD INDEX {$inst_index} (institution_id)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				}
				continue;
			}

			// Caso 3: no existe columna legacy → agregar institution_id + índice.
			if ( 0 === $legacy_exists ) {
				$wpdb->query( "ALTER TABLE {$full_table} ADD COLUMN institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				if ( 0 === $idx_inst ) {
					$wpdb->query( "ALTER TABLE {$full_table} ADD INDEX {$inst_index} (institution_id)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
				}
				continue;
			}

			// Caso 4: solo existe legacy → renombrar y renombrar/crear índice.
			$wpdb->query( "ALTER TABLE {$full_table} CHANGE COLUMN {$legacy_column} institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange

			$idx_legacy = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
					 WHERE TABLE_SCHEMA = DATABASE()
					   AND TABLE_NAME   = %s
					   AND INDEX_NAME   = %s",
					$full_table,
					$legacy_index
				)
			);
			if ( $idx_legacy > 0 && 0 === $idx_inst ) {
				$wpdb->query( "ALTER TABLE {$full_table} RENAME INDEX {$legacy_index} TO {$inst_index}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			} elseif ( 0 === $idx_inst ) {
				$wpdb->query( "ALTER TABLE {$full_table} ADD INDEX {$inst_index} (institution_id)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			}
		}
		// ── /Fase IV S15 ──────────────────────────────────────────────────────

		// ── Fase II S7: email_html en atora_crm_campaigns ────────────────────
		$campaigns_table = $wpdb->prefix . 'atora_crm_campaigns';
		if ( self::table_exists( $campaigns_table ) ) {
			$html_exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
					 WHERE TABLE_SCHEMA = DATABASE()
					   AND TABLE_NAME   = %s
					   AND COLUMN_NAME  = 'email_html'",
					$campaigns_table
				)
			);
			if ( 0 === $html_exists ) {
				$wpdb->query( "ALTER TABLE {$campaigns_table} ADD COLUMN email_html LONGTEXT NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			}
		}

		$tracking_table = $wpdb->prefix . 'atora_crm_campaign_recipients';
		if ( self::table_exists( $tracking_table ) ) {
			$tracking_cols = array(
				'opened_at'  => "ALTER TABLE {$tracking_table} ADD COLUMN opened_at DATETIME NULL",
				'clicked_at' => "ALTER TABLE {$tracking_table} ADD COLUMN clicked_at DATETIME NULL",
				'bounced_at' => "ALTER TABLE {$tracking_table} ADD COLUMN bounced_at DATETIME NULL",
			);

			foreach ( $tracking_cols as $column => $sql ) {
				$exists = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
						 WHERE TABLE_SCHEMA = DATABASE()
						   AND TABLE_NAME = %s
						   AND COLUMN_NAME = %s",
						$tracking_table,
						$column
					)
				);
				if ( 0 === $exists ) {
					$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
			}
		}

		// ── Fase 4: columnas ficha 360 accionable ─────────────────────────
		$contacts_table = $wpdb->prefix . 'atora_contacts';
		if ( self::table_exists( $contacts_table ) ) {
			$contact_cols = array(
				'created_by_user_id' => "ALTER TABLE {$contacts_table} ADD COLUMN created_by_user_id INT(11) NOT NULL DEFAULT 0 COMMENT 'Usuario que capturó el lead' AFTER user_id",
				'last_activity_at'   => "ALTER TABLE {$contacts_table} ADD COLUMN last_activity_at DATETIME NULL DEFAULT NULL AFTER updated_at",
			);
			$contact_indexes = array(
				'idx_created_by'    => "ALTER TABLE {$contacts_table} ADD INDEX idx_created_by (created_by_user_id)",
				'idx_last_activity' => "ALTER TABLE {$contacts_table} ADD INDEX idx_last_activity (last_activity_at)",
			);

			foreach ( $contact_cols as $column => $sql ) {
				$exists = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
						 WHERE TABLE_SCHEMA = DATABASE()
						   AND TABLE_NAME   = %s
						   AND COLUMN_NAME  = %s",
						$contacts_table,
						$column
					)
				);
				if ( 0 === $exists ) {
					$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
			}

			foreach ( $contact_indexes as $index_name => $sql ) {
				$exists = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
						 WHERE TABLE_SCHEMA = DATABASE()
						   AND TABLE_NAME   = %s
						   AND INDEX_NAME   = %s",
						$contacts_table,
						$index_name
					)
				);
				if ( 0 === $exists ) {
					$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
			}
		}

		$activities_table = $wpdb->prefix . 'atora_contact_activities';
		if ( self::table_exists( $activities_table ) ) {
			$activity_cols = array(
				'linked_entity_type' => "ALTER TABLE {$activities_table} ADD COLUMN linked_entity_type VARCHAR(40) NULL DEFAULT NULL AFTER activity_data",
				'linked_entity_id'   => "ALTER TABLE {$activities_table} ADD COLUMN linked_entity_id INT(11) NULL DEFAULT NULL AFTER linked_entity_type",
			);
			foreach ( $activity_cols as $column => $sql ) {
				$exists = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
						 WHERE TABLE_SCHEMA = DATABASE()
						   AND TABLE_NAME   = %s
						   AND COLUMN_NAME  = %s",
						$activities_table,
						$column
					)
				);
				if ( 0 === $exists ) {
					$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
			}
		}

		$tasks_table = $wpdb->prefix . 'atora_crm_tasks';
		if ( self::table_exists( $tasks_table ) ) {
			$task_cols = array(
				'completed_at' => "ALTER TABLE {$tasks_table} ADD COLUMN completed_at DATETIME NULL DEFAULT NULL AFTER status",
			);
			foreach ( $task_cols as $column => $sql ) {
				$exists = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
						 WHERE TABLE_SCHEMA = DATABASE()
						   AND TABLE_NAME   = %s
						   AND COLUMN_NAME  = %s",
						$tasks_table,
						$column
					)
				);
				if ( 0 === $exists ) {
					$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
			}
		}
		// ── /Fase 4 ───────────────────────────────────────────────────────

		// ── Fase 9: atora_contacts v2 ────────────────────────────────────────
		$contacts_table = $wpdb->prefix . 'atora_contacts';
		if ( self::table_exists( $contacts_table ) ) {
			$f9_contact_cols = array(
				'contact_type'    => "ALTER TABLE {$contacts_table} ADD COLUMN contact_type ENUM('lead','student','customer','partner','other') NOT NULL DEFAULT 'lead' AFTER status",
				'timezone'        => "ALTER TABLE {$contacts_table} ADD COLUMN timezone VARCHAR(60) NOT NULL DEFAULT '' AFTER city",
				'address_line_1'  => "ALTER TABLE {$contacts_table} ADD COLUMN address_line_1 VARCHAR(255) NOT NULL DEFAULT '' AFTER timezone",
				'address_line_2'  => "ALTER TABLE {$contacts_table} ADD COLUMN address_line_2 VARCHAR(255) NOT NULL DEFAULT '' AFTER address_line_1",
				'postal_code'     => "ALTER TABLE {$contacts_table} ADD COLUMN postal_code VARCHAR(20) NOT NULL DEFAULT '' AFTER address_line_2",
				'state'           => "ALTER TABLE {$contacts_table} ADD COLUMN state VARCHAR(100) NOT NULL DEFAULT '' AFTER postal_code",
				'ip_address'      => "ALTER TABLE {$contacts_table} ADD COLUMN ip_address VARCHAR(45) NOT NULL DEFAULT '' AFTER country",
				'latitude'        => "ALTER TABLE {$contacts_table} ADD COLUMN latitude DECIMAL(10,8) DEFAULT NULL AFTER ip_address",
				'longitude'       => "ALTER TABLE {$contacts_table} ADD COLUMN longitude DECIMAL(11,8) DEFAULT NULL AFTER latitude",
				'total_points'    => "ALTER TABLE {$contacts_table} ADD COLUMN total_points INT UNSIGNED NOT NULL DEFAULT 0 AFTER phone",
				'life_time_value' => "ALTER TABLE {$contacts_table} ADD COLUMN life_time_value DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_points",
				'avatar'          => "ALTER TABLE {$contacts_table} ADD COLUMN avatar VARCHAR(500) NOT NULL DEFAULT '' AFTER name",
				'contact_hash'    => "ALTER TABLE {$contacts_table} ADD COLUMN contact_hash VARCHAR(90) DEFAULT NULL AFTER id",
				'contact_owner'   => "ALTER TABLE {$contacts_table} ADD COLUMN contact_owner BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER user_id",
				'company_id'        => "ALTER TABLE {$contacts_table} ADD COLUMN company_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER contact_owner",
				// Fase II S4 — Scoring predictivo
				'conversion_score'  => "ALTER TABLE {$contacts_table} ADD COLUMN conversion_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER life_time_value",
				'engagement_score'  => "ALTER TABLE {$contacts_table} ADD COLUMN engagement_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER conversion_score",
			);
			$f9_contact_indexes = array(
				'idx_contact_hash'     => "ALTER TABLE {$contacts_table} ADD UNIQUE INDEX idx_contact_hash (contact_hash)",
				'idx_contact_type'     => "ALTER TABLE {$contacts_table} ADD INDEX idx_contact_type (contact_type)",
				'idx_company_id'       => "ALTER TABLE {$contacts_table} ADD INDEX idx_company_id (company_id)",
				'idx_contact_owner'    => "ALTER TABLE {$contacts_table} ADD INDEX idx_contact_owner (contact_owner)",
				'idx_life_time_value'  => "ALTER TABLE {$contacts_table} ADD INDEX idx_life_time_value (life_time_value)",
				'idx_conversion_score' => "ALTER TABLE {$contacts_table} ADD INDEX idx_conversion_score (conversion_score)",
			);

			foreach ( $f9_contact_cols as $column => $sql ) {
				$exists = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
						 WHERE TABLE_SCHEMA = DATABASE()
						   AND TABLE_NAME   = %s
						   AND COLUMN_NAME  = %s",
						$contacts_table,
						$column
					)
				);
				if ( 0 === $exists ) {
					$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
			}

			foreach ( $f9_contact_indexes as $idx_name => $sql ) {
				$exists = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
						 WHERE TABLE_SCHEMA = DATABASE()
						   AND TABLE_NAME   = %s
						   AND INDEX_NAME   = %s",
						$contacts_table,
						$idx_name
					)
				);
				if ( 0 === $exists ) {
					$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				}
			}
		}
		// ── /Fase 9 ───────────────────────────────────────────────────────────
	}

	/**
	 * Calcula tablas faltantes de una lista.
	 *
	 * @param array<int,string> $tables Tablas completas.
	 * @return array<int,string>
	 */
	private static function get_missing_tables( array $tables ): array {
		$missing = array();
		foreach ( $tables as $table ) {
			$table = (string) $table;
			if ( '' === $table ) {
				continue;
			}
			if ( ! self::table_exists( $table ) ) {
				$missing[] = $table;
			}
		}

		return $missing;
	}

	/**
	 * SQL de creación de tablas CRM v2.
	 *
	 * @param string $prefix        Prefijo WP.
	 * @param string $charset       Charset/collate.
	 * @param bool   $if_not_exists Si incluye IF NOT EXISTS.
	 * @return array<string,string>
	 */
	private static function get_schema_statements( string $prefix, string $charset, bool $if_not_exists = false ): array {
		$create_kw = $if_not_exists ? 'create table if not exists' : 'CREATE TABLE';

		return array(
			"{$prefix}atora_crm_deals" => "{$create_kw} {$prefix}atora_crm_deals (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				title VARCHAR(190) NOT NULL DEFAULT '',
				course_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				channel VARCHAR(20) NOT NULL DEFAULT 'email',
				temperature VARCHAR(20) NOT NULL DEFAULT 'warm',
				stage VARCHAR(40) NOT NULL DEFAULT 'new_lead',
				next_action VARCHAR(255) NOT NULL DEFAULT '',
				assigned_to BIGINT UNSIGNED NOT NULL DEFAULT 0,
				estimated_value DECIMAL(12,2) NOT NULL DEFAULT 0,
				lost_reason TEXT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY contact_id (contact_id),
				KEY user_id (user_id),
				KEY stage (stage),
				KEY assigned_to (assigned_to),
				KEY updated_at (updated_at)
			) {$charset};",
			"{$prefix}atora_crm_student_followups" => "{$create_kw} {$prefix}atora_crm_student_followups (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				course_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				stage VARCHAR(40) NOT NULL DEFAULT 'new_enrolled',
				progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
				pending_activities SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				risk_level VARCHAR(20) NOT NULL DEFAULT 'normal',
				last_access_at DATETIME NULL,
				next_action VARCHAR(255) NOT NULL DEFAULT '',
				assigned_to BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY user_course (user_id, course_id),
				KEY contact_id (contact_id),
				KEY stage (stage),
				KEY risk_level (risk_level),
				KEY assigned_to (assigned_to)
			) {$charset};",
			"{$prefix}atora_crm_tasks" => "{$create_kw} {$prefix}atora_crm_tasks (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				title VARCHAR(190) NOT NULL DEFAULT '',
				contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				related_type VARCHAR(30) NOT NULL DEFAULT '',
				related_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				task_type VARCHAR(40) NOT NULL DEFAULT 'followup_email',
				priority VARCHAR(20) NOT NULL DEFAULT 'medium',
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				due_at DATETIME NULL,
				assigned_to BIGINT UNSIGNED NOT NULL DEFAULT 0,
				notes TEXT NULL,
				completed_at DATETIME NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY contact_id (contact_id),
				KEY user_id (user_id),
				KEY related_ref (related_type, related_id),
				KEY status (status),
				KEY due_at (due_at),
				KEY assigned_to (assigned_to)
			) {$charset};",
			"{$prefix}atora_crm_campaigns" => "{$create_kw} {$prefix}atora_crm_campaigns (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				campaign_key VARCHAR(80) NOT NULL DEFAULT '',
				name VARCHAR(190) NOT NULL DEFAULT '',
				template_key VARCHAR(80) NOT NULL DEFAULT 'crm_campaign',
				channel VARCHAR(20) NOT NULL DEFAULT 'email',
				execution_mode VARCHAR(20) NOT NULL DEFAULT 'simulate',
				status VARCHAR(20) NOT NULL DEFAULT 'draft',
				subject VARCHAR(255) NOT NULL DEFAULT '',
				message LONGTEXT NULL,
				cta_url VARCHAR(255) NOT NULL DEFAULT '',
				segment_json LONGTEXT NULL,
				created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				scheduled_at DATETIME NULL,
				launched_at DATETIME NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY campaign_key (campaign_key),
				KEY status (status),
				KEY channel (channel),
				KEY execution_mode (execution_mode),
				KEY created_by (created_by)
			) {$charset};",
			"{$prefix}atora_crm_campaign_recipients" => "{$create_kw} {$prefix}atora_crm_campaign_recipients (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				campaign_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				email VARCHAR(200) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				queued_email_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				error_message TEXT NULL,
				sent_at DATETIME NULL,
				opened_at DATETIME NULL,
				clicked_at DATETIME NULL,
				bounced_at DATETIME NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY campaign_contact (campaign_id, contact_id),
				KEY campaign_id (campaign_id),
				KEY user_id (user_id),
				KEY status (status)
			) {$charset};",
			"{$prefix}atora_crm_contact_fields" => "{$create_kw} {$prefix}atora_crm_contact_fields (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				field_key VARCHAR(80) NOT NULL DEFAULT '',
				label VARCHAR(190) NOT NULL DEFAULT '',
				field_type VARCHAR(30) NOT NULL DEFAULT 'text',
				options LONGTEXT NULL,
				is_required TINYINT(1) NOT NULL DEFAULT 0,
				show_in_360 TINYINT(1) NOT NULL DEFAULT 1,
				sort_order SMALLINT NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY field_key (field_key)
			) {$charset};",
			"{$prefix}atora_crm_contact_field_values" => "{$create_kw} {$prefix}atora_crm_contact_field_values (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				field_key VARCHAR(80) NOT NULL DEFAULT '',
				value LONGTEXT NULL,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY contact_field (contact_id, field_key),
				KEY contact_id (contact_id),
				KEY field_key (field_key)
			) {$charset};",
			// PT-6 (6.5.10): las cuatro tablas de Sequence_Service (Fase 6,
			// 5.28.0 — secuencias de email drip) nunca tuvieron un CREATE
			// TABLE en ningún instalador — un hallazgo distinto del de
			// atora_lms_parity_reads (PT-3) pero de la misma clase de raíz
			// ("tabla huérfana en runtime"), encontrado por el mismo
			// inventario tabla-por-tabla. Sequence_Service ya comprueba
			// table_exists() antes de cada operación, así que esto nunca
			// causó un fatal — la funcionalidad de secuencias simplemente
			// estuvo inactiva en silencio en cualquier instalación. Columnas
			// inferidas de los propios usos reales en
			// modules/crm-v2/services/class-sequence-service.php (inserts/
			// updates/selects), no de un diseño nuevo.
			"{$prefix}atora_email_sequences" => "{$create_kw} {$prefix}atora_email_sequences (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(190) NOT NULL DEFAULT '',
				description TEXT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'draft',
				created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY status (status)
			) {$charset};",
			"{$prefix}atora_email_sequence_steps" => "{$create_kw} {$prefix}atora_email_sequence_steps (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				sequence_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				step_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
				delay_hours INT UNSIGNED NOT NULL DEFAULT 24,
				send_condition VARCHAR(20) NOT NULL DEFAULT 'always',
				template_key VARCHAR(80) NOT NULL DEFAULT 'crm_campaign',
				subject VARCHAR(255) NOT NULL DEFAULT '',
				message LONGTEXT NULL,
				cta_url VARCHAR(500) NOT NULL DEFAULT '',
				identity VARCHAR(40) NOT NULL DEFAULT 'academia',
				PRIMARY KEY (id),
				KEY sequence_order (sequence_id, step_order)
			) {$charset};",
			"{$prefix}atora_email_sequence_enrollments" => "{$create_kw} {$prefix}atora_email_sequence_enrollments (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				sequence_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				current_step SMALLINT UNSIGNED NOT NULL DEFAULT 1,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				next_run_at DATETIME NULL,
				enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY sequence_contact (sequence_id, contact_id),
				KEY status_next_run (status, next_run_at),
				KEY contact_id (contact_id)
			) {$charset};",
			"{$prefix}atora_email_suppression" => "{$create_kw} {$prefix}atora_email_suppression (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				email VARCHAR(190) NOT NULL DEFAULT '',
				reason VARCHAR(20) NOT NULL DEFAULT 'manual',
				contact_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY email (email)
			) {$charset};",
		);
	}

	/**
	 * Verifica si una tabla existe.
	 *
	 * @param string $table_name Tabla completa.
	 * @return bool
	 */
	public static function table_exists( string $table_name ): bool {
		global $wpdb;

		if ( '' === $table_name ) {
			return false;
		}

		$exists = (string) $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $exists === $table_name;
	}
}
