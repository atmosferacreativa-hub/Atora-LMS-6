<?php
/**
 * CLMS_Migration_6131 — C0 (sprint 6.13.1)
 *
 * dbDelta() no puede aplicar estos cambios: su parser captura el nombre
 * de tabla con `CREATE TABLE ([^ ]*)` y con `IF NOT EXISTS` lee "IF" —
 * nunca diffea columnas ni índices en actualizaciones. Estas tres
 * alteraciones van con ALTER TABLE explícito, guardadas tras
 * `atora_schema_version` para que corran una sola vez y sean
 * idempotentes (se verifica INFORMATION_SCHEMA antes de cada ALTER, así
 * que correr esto dos veces no falla ni repite trabajo).
 *
 * @package ATORA_LMS
 * @since   6.13.1
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Migration_6131 {

	const OPTION = 'atora_schema_version';
	const VERSION = '6.13.1';

	/**
	 * @return void
	 */
	public static function maybe_run(): void {
		if ( version_compare( (string) get_option( self::OPTION, '0' ), self::VERSION, '>=' ) ) {
			return;
		}

		self::migrate_attendance_anonymous_participants();
		self::migrate_live_sessions_nullable_external_id();
		self::migrate_live_sessions_organizer_column();

		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * C0.1 (B1): desbloquea participantes anónimos — antes el UNIQUE
	 * KEY(session_id, user_id) con user_id=0 solo dejaba UN anónimo por
	 * sesión, colapsando al resto.
	 *
	 * @return void
	 */
	private static function migrate_attendance_anonymous_participants(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_attendance';
		if ( ! self::table_exists( $table ) ) {
			return;
		}

		if ( ! self::column_exists( $table, 'external_participant_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN external_participant_id VARCHAR(160) NOT NULL DEFAULT '' AFTER session_id" );
		}

		// C4 (6.13.1): el metabox necesita mostrar "No identificado —
		// <nombre de pantalla>" para anónimos (user_id = 0); sin esta
		// columna esa información nunca queda persistida en ningún lado.
		// Misma tabla que ya se altera arriba, se aprovecha el pase.
		if ( ! self::column_exists( $table, 'display_name' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN display_name VARCHAR(200) NOT NULL DEFAULT '' AFTER external_participant_id" );
		}

		if ( self::index_exists( $table, 'session_user' ) && ! self::index_exists( $table, 'session_participant' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX session_user, ADD UNIQUE KEY session_participant (session_id, user_id, external_participant_id)" );
		} elseif ( ! self::index_exists( $table, 'session_participant' ) ) {
			// El índice viejo ya no existe (re-ejecución tras un ALTER
			// parcial) pero el nuevo tampoco — completar solo la parte que falta.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY session_participant (session_id, user_id, external_participant_id)" );
		}
	}

	/**
	 * C0.2 (B2): permite proveedores sin external_id (custom, YouTube,
	 * Teams sin webhook) — MySQL admite múltiples NULL bajo un índice
	 * único, a diferencia de múltiples ''.
	 *
	 * @return void
	 */
	private static function migrate_live_sessions_nullable_external_id(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_live_sessions';
		if ( ! self::table_exists( $table ) ) {
			return;
		}

		if ( ! self::column_is_nullable( $table, 'external_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN external_id VARCHAR(120) NULL DEFAULT NULL" );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "UPDATE {$table} SET external_id = NULL WHERE external_id = ''" );
		}
	}

	/**
	 * C0.3: columna created_by_user_id — sustituye
	 * update_option('atora_meet_organizer_user_id_'.$code) de
	 * Provider_Meet::create_session(), que creaba una fila de wp_options
	 * por cada clase Meet sin límite ni limpieza. Migra las options
	 * existentes a la columna y las borra en la misma pasada.
	 *
	 * D2 (6.13.2): solo se borra la option cuando el UPDATE realmente
	 * encontró y actualizó una fila — antes delete_option() corría
	 * incondicionalmente, así que una sesión que nunca llegó a
	 * persistirse en atora_live_sessions (p.ej. una creación de Meet
	 * fallida a medias) perdía el vínculo con el organizador de forma
	 * irreversible. La columna acaba de crearse con DEFAULT 0, así que un
	 * $wpdb->update() que devuelve 0 con $user_id != 0 solo puede
	 * significar "no hay fila que matchee" — no "sin cambios que
	 * aplicar" (ambigüedad real de wpdb::update(), pero no en este caso
	 * concreto). Las huérfanas quedan en wp_options y en el audit log en
	 * vez de perderse.
	 *
	 * @return void
	 */
	private static function migrate_live_sessions_organizer_column(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_live_sessions';
		if ( ! self::table_exists( $table ) ) {
			return;
		}

		if ( ! self::column_exists( $table, 'created_by_user_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN created_by_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER status" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$organizer_options = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'atora\\_meet\\_organizer\\_user\\_id\\_%'"
		);

		foreach ( (array) $organizer_options as $row ) {
			$meeting_code = substr( $row->option_name, strlen( 'atora_meet_organizer_user_id_' ) );
			$user_id      = absint( $row->option_value );

			$updated = 0;
			if ( $meeting_code && $user_id ) {
				$updated = (int) $wpdb->update(
					$table,
					array( 'created_by_user_id' => $user_id ),
					array( 'provider' => 'meet', 'external_id' => $meeting_code ),
					array( '%d' ),
					array( '%s', '%s' )
				);
			}

			if ( $updated > 0 ) {
				delete_option( $row->option_name );
			} elseif ( class_exists( 'CLMS_Audit_Log_Service' ) ) {
				CLMS_Audit_Log_Service::log( 0, 'meet_organizer_option_orphan', 'live_session', 0, array( 'option' => $row->option_name ) );
			}
		}
	}

	/**
	 * @param string $table Nombre completo (con prefijo).
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	/**
	 * @param string $table Nombre completo (con prefijo).
	 * @param string $column
	 * @return bool
	 */
	private static function column_exists( string $table, string $column ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
			$table, $column
		) ) > 0;
	}

	/**
	 * @param string $table Nombre completo (con prefijo).
	 * @param string $column
	 * @return bool
	 */
	private static function column_is_nullable( string $table, string $column ): bool {
		global $wpdb;
		$nullable = $wpdb->get_var( $wpdb->prepare(
			"SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
			$table, $column
		) );
		return 'YES' === $nullable;
	}

	/**
	 * @param string $table Nombre completo (con prefijo).
	 * @param string $index_name
	 * @return bool
	 */
	private static function index_exists( string $table, string $index_name ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
			$table, $index_name
		) ) > 0;
	}
}
