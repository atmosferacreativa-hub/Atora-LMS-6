<?php
/**
 * ATORA\Messaging\Digest_Store — PT-5.1 (sprint 6.4.0)
 *
 * Cola de ítems agrupables: mensajes que no se despachan de inmediato
 * porque son de prioridad baja o el usuario configuró esa categoría
 * como "resumen" (PT-4.2). Un cron aparte (PT-5.2) los consume por
 * usuario y construye un solo mensaje.
 *
 * Tabla propia, no reutiliza atora_message_queue — estos ítems no son
 * "mensajes en cola para enviar", son "cosas pendientes de agrupar",
 * un concepto distinto (mismo criterio que se usó para
 * atora_lms_parity_reads en el sprint anterior: una tabla auxiliar
 * separada en vez de sobrecargar la tabla operativa existente).
 *
 * @package ATORA_LMS\Messaging
 * @since   6.4.0
 */

namespace ATORA\Messaging;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Digest_Store {

	const TABLE = 'atora_message_digest_items';

	/** PT-2 (6.5.4/6.5.5): versión de esquema — cambiar fuerza el ALTER de migrate_add_status_columns()/migrate_add_claim_token_column(). */
	const SCHEMA_VERSION        = '3';
	const OPT_SCHEMA_VERSION    = 'atora_digest_store_schema_version';

	/** PT-2.3: umbral para liberar filas 'claimed' cuyo proceso murió sin completar. */
	const STALE_CLAIM_MINUTES = 15;
	/** PT-2.4: filas 'pending' más viejas que esto ya no tiene sentido enviarlas. */
	const TTL_DAYS = 7;
	/** PT-2.4: tope duro por usuario — un fallo persistente no debe crecer la tabla sin límite. */
	const MAX_ROWS_PER_USER = 200;

	/**
	 * Crea la tabla si no existe, o la migra si ya existe con el
	 * esquema anterior (sin status/claimed_at). Se llama en
	 * Messaging_Router::init().
	 *
	 * PT-2.1 (6.5.4): igual que
	 * V5_Installer::migrate_course_wp_post_id_nullable() (6.5.3), el
	 * ALTER se verifica antes de marcar el esquema como actualizado —
	 * si falla (permisos, etc.), se reintenta en la próxima llamada en
	 * vez de darlo por hecho.
	 *
	 * @return void
	 */
	public static function ensure_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset = $wpdb->get_charset_collate();

			dbDelta(
				"CREATE TABLE {$table} (
				  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				  user_id        BIGINT UNSIGNED NOT NULL,
				  type           VARCHAR(60)     NOT NULL,
				  template_key   VARCHAR(80)     NOT NULL,
				  variables      TEXT,
				  status         VARCHAR(20)     NOT NULL DEFAULT 'pending',
				  claimed_at     DATETIME        NULL,
				  claim_token    VARCHAR(64)     NULL,
				  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
				  PRIMARY KEY (id),
				  KEY user_id (user_id),
				  KEY status_user (status, user_id),
				  KEY claim_token (claim_token)
				) {$charset};"
			);
			update_option( self::OPT_SCHEMA_VERSION, self::SCHEMA_VERSION );
			return;
		}

		if ( get_option( self::OPT_SCHEMA_VERSION ) === self::SCHEMA_VERSION ) {
			return;
		}

		$ok = self::migrate_add_status_columns( $table );
		$ok = self::migrate_add_claim_token_column( $table ) && $ok;

		if ( $ok ) {
			update_option( self::OPT_SCHEMA_VERSION, self::SCHEMA_VERSION );
		}
	}

	/**
	 * PT-2.1 (6.5.4): añade status/claimed_at a una tabla existente
	 * del esquema anterior (6.4.0, sin locking). No usa dbDelta() —
	 * igual que en 6.5.3, no es confiable para alterar una tabla ya
	 * existente; ALTER TABLE explícito, con verificación posterior de
	 * que la columna quedó de verdad antes de reportar éxito.
	 *
	 * @param string $table
	 * @return bool true si la tabla ya tiene (o quedó con) las columnas nuevas.
	 */
	private static function migrate_add_status_columns( string $table ): bool {
		global $wpdb;

		$has_status = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'status' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $has_status ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"ALTER TABLE {$table}
			 ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending',
			 ADD COLUMN claimed_at DATETIME NULL,
			 ADD KEY status_user (status, user_id)"
		);

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'status' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * PT-2 (6.5.5): añade claim_token a una tabla existente que ya
	 * tiene status/claimed_at (esquema '2', 6.5.4) pero no el token —
	 * mismo patrón: ALTER TABLE explícito, verificado antes de reportar
	 * éxito, no depende de que dbDelta() altere una tabla existente.
	 *
	 * @param string $table
	 * @return bool true si la tabla ya tiene (o quedó con) la columna.
	 */
	private static function migrate_add_claim_token_column( string $table ): bool {
		global $wpdb;

		$has_token = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'claim_token' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $has_token ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"ALTER TABLE {$table}
			 ADD COLUMN claim_token VARCHAR(64) NULL,
			 ADD KEY claim_token (claim_token)"
		);

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'claim_token' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * PT-2.2 (6.5.4): diagnóstico de solo lectura — el diseño de
	 * claim_items_for_user() asume que un UPDATE ... WHERE status =
	 * 'pending' actúa como sección crítica bajo concurrencia, lo cual
	 * depende de que el motor de almacenamiento sea InnoDB (bloqueo de
	 * fila) y no MyISAM (bloqueo de tabla completa, donde igual
	 * funcionaría pero serializando todo acceso a la tabla en vez de
	 * solo la fila). Ninguna sentencia CREATE TABLE de este proyecto
	 * fija ENGINE=, así que hereda el default del servidor — InnoDB
	 * desde MySQL 5.5 (2010) salvo que un administrador lo haya
	 * cambiado a propósito. No verificable en este entorno de
	 * desarrollo (sin conexión a MySQL real); expuesto para que un
	 * panel de diagnóstico o un chequeo de salud lo confirme en
	 * producción.
	 *
	 * @return string|null Nombre del motor, o null si no se pudo determinar.
	 */
	public static function get_storage_engine(): ?string {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);

		return $engine ? (string) $engine : null;
	}

	/**
	 * PT-2.4 (6.5.4): tope duro por usuario antes de insertar — un
	 * fallo persistente de un usuario específico no debe hacer crecer
	 * la tabla sin límite.
	 *
	 * @param int    $user_id
	 * @param string $type
	 * @param string $template_key
	 * @param array  $variables
	 * @return void
	 */
	public static function add_item( int $user_id, string $type, string $template_key, array $variables ): void {
		self::enforce_row_cap( $user_id );

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'user_id'      => $user_id,
				'type'         => sanitize_key( $type ),
				'template_key' => sanitize_key( $template_key ),
				'variables'    => wp_json_encode( $variables ),
				'status'       => 'pending',
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * @param int $user_id
	 * @return void
	 */
	private static function enforce_row_cap( int $user_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( $count < self::MAX_ROWS_PER_USER ) {
			return;
		}

		$overflow = ( $count - self::MAX_ROWS_PER_USER ) + 1;
		$stale_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d ORDER BY id ASC LIMIT %d", $user_id, $overflow ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		self::clear_items( array_map( 'absint', (array) $stale_ids ) );
	}

	/**
	 * @param int $user_id
	 * @return array<int,array{id:int,type:string,template_key:string,variables:array}>
	 */
	public static function get_items( int $user_id ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, template_key, variables FROM {$wpdb->prefix}" . self::TABLE . ' WHERE user_id = %d ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			),
			ARRAY_A
		);

		return self::map_rows( $rows );
	}

	/**
	 * PT-2.2 (6.5.4) / PT-2 (6.5.5): reclama de forma atómica los ítems
	 * 'pending' de un usuario — el propio UPDATE actúa como sección
	 * crítica (ver get_storage_engine()). Una segunda llamada solapada
	 * (cron duplicado) para el mismo usuario no encuentra nada que
	 * reclamar, porque el primer UPDATE ya movió esas filas a
	 * 'claimed'.
	 *
	 * PT-2 (6.5.5): el lote reclamado ya no se identifica solo por
	 * claimed_at (precisión de 1 segundo — dos workers podían reclamar
	 * dentro del mismo segundo y, en teoría, leer de vuelta filas del
	 * otro) — cada llamada genera un claim_token único (CSPRNG) y el
	 * SELECT de lectura filtra por ese token exacto, no por timestamp.
	 *
	 * @param int $user_id
	 * @return array<int,array{id:int,type:string,template_key:string,variables:array}>
	 */
	public static function claim_items_for_user( int $user_id ): array {
		global $wpdb;
		$table       = $wpdb->prefix . self::TABLE;
		$claimed_at  = current_time( 'mysql', true );
		$claim_token = bin2hex( random_bytes( 16 ) );

		$updated = $wpdb->update(
			$table,
			array( 'status' => 'claimed', 'claimed_at' => $claimed_at, 'claim_token' => $claim_token ),
			array( 'user_id' => $user_id, 'status' => 'pending' ),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( ! $updated ) {
			return array();
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, template_key, variables FROM {$table} WHERE user_id = %d AND status = 'claimed' AND claim_token = %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$claim_token
			),
			ARRAY_A
		);

		return self::map_rows( $rows );
	}

	/**
	 * @param array $rows
	 * @return array<int,array{id:int,type:string,template_key:string,variables:array}>
	 */
	private static function map_rows( array $rows ): array {
		return array_map(
			static function ( $row ) {
				$decoded = json_decode( (string) ( $row['variables'] ?? '' ), true );
				return array(
					'id'           => absint( $row['id'] ?? 0 ),
					'type'         => (string) ( $row['type'] ?? '' ),
					'template_key' => (string) ( $row['template_key'] ?? '' ),
					'variables'    => is_array( $decoded ) ? $decoded : array(),
				);
			},
			$rows
		);
	}

	/**
	 * @return int[] user_id con al menos un ítem 'pending' (no incluye 'claimed').
	 */
	public static function get_users_with_pending_items(): array {
		global $wpdb;
		$ids = (array) $wpdb->get_col( "SELECT DISTINCT user_id FROM {$wpdb->prefix}" . self::TABLE . " WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * PT-2.3 (6.5.4): libera filas 'claimed' cuyo proceso murió sin
	 * completar (envío falló a mitad de camino, PHP se cortó, etc.) —
	 * de vuelta a 'pending' para que el próximo cron las recoja.
	 *
	 * @return int Filas liberadas.
	 */
	public static function release_stale_claims(): int {
		global $wpdb;
		$table     = $wpdb->prefix . self::TABLE;
		$threshold = gmdate( 'Y-m-d H:i:s', time() - self::STALE_CLAIM_MINUTES * MINUTE_IN_SECONDS );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', claimed_at = NULL, claim_token = NULL WHERE status = 'claimed' AND claimed_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$threshold
			)
		);
	}

	/**
	 * PT-2.4 (6.5.4): descarta ítems 'pending' más viejos que el TTL —
	 * un resumen de una semana ya no tiene sentido enviarlo. Se llama
	 * después de release_stale_claims() en cada corrida, para que las
	 * filas 'claimed' recién liberadas también entren en la evaluación
	 * de antigüedad.
	 *
	 * @return int Filas descartadas.
	 */
	public static function purge_expired(): int {
		global $wpdb;
		$table     = $wpdb->prefix . self::TABLE;
		$threshold = gmdate( 'Y-m-d H:i:s', time() - self::TTL_DAYS * DAY_IN_SECONDS );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = 'pending' AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$threshold
			)
		);
	}

	/**
	 * PT-2.2 (6.5.4): libera de vuelta a 'pending' un reclamo puntual
	 * — usado cuando Digest_Cron decide diferir el envío (ventana de
	 * no molestar) en vez de enviarlo o descartarlo. Sin esto, esas
	 * filas quedarían 'claimed' hasta que release_stale_claims() las
	 * libere por antigüedad (funciona igual, pero una hora más tarde
	 * de lo necesario en el caso normal de diferimiento).
	 *
	 * @param int[] $ids
	 * @return void
	 */
	public static function release_claim( array $ids ): void {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) { return; }

		global $wpdb;
		$table        = $wpdb->prefix . self::TABLE;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', claimed_at = NULL, claim_token = NULL WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$ids
			)
		);
	}

	/**
	 * PT-2.3/2.4 (6.5.4): mantenimiento — liberar reclamos abandonados
	 * y descartar lo vencido, en ese orden, al inicio de cada corrida
	 * del cron de digest.
	 *
	 * @return array{released:int,purged:int}
	 */
	public static function run_maintenance(): array {
		$released = self::release_stale_claims();
		$purged   = self::purge_expired();
		return array( 'released' => $released, 'purged' => $purged );
	}

	/**
	 * @param int[] $ids
	 * @return void
	 */
	public static function clear_items( array $ids ): void {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) { return; }

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}" . self::TABLE . " WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$ids
			)
		);
	}
}
