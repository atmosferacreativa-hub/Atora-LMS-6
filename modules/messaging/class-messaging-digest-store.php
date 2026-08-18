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

	/**
	 * Crea la tabla si no existe. Se llama en Messaging_Router::init().
	 *
	 * @return void
	 */
	public static function ensure_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			  user_id        BIGINT UNSIGNED NOT NULL,
			  type           VARCHAR(60)     NOT NULL,
			  template_key   VARCHAR(80)     NOT NULL,
			  variables      TEXT,
			  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY (id),
			  KEY user_id (user_id)
			) {$charset};"
		);
	}

	/**
	 * @param int    $user_id
	 * @param string $type
	 * @param string $template_key
	 * @param array  $variables
	 * @return void
	 */
	public static function add_item( int $user_id, string $type, string $template_key, array $variables ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'user_id'      => $user_id,
				'type'         => sanitize_key( $type ),
				'template_key' => sanitize_key( $template_key ),
				'variables'    => wp_json_encode( $variables ),
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
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
	 * @return int[] user_id con al menos un ítem pendiente.
	 */
	public static function get_users_with_pending_items(): array {
		global $wpdb;
		$ids = (array) $wpdb->get_col( "SELECT DISTINCT user_id FROM {$wpdb->prefix}" . self::TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_values( array_filter( array_map( 'absint', $ids ) ) );
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
