<?php
/**
 * Persistencia de entregas móviles y sesiones de subida reanudable.
 *
 * Tablas (6.26.5): atora_assignment_submissions y atora_upload_sessions.
 * La interfaz existe para que la lógica de ATORA_Mobile_Assignment_Service
 * se pruebe con un almacén en memoria.
 *
 * @package ATORA_LMS
 * @since 6.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ATORA_Mobile_Assignment_Store {
	public function find_submission_by_event( int $user_id, string $client_event_id ): ?array;

	public function max_attempt( int $user_id, int $lesson_id ): int;

	/** @return array<int, array> Intentos del usuario en la lección, del más reciente al más antiguo. */
	public function list_submissions( int $user_id, int $lesson_id ): array;

	/** @return int ID insertado, o 0 si (user_id, client_event_id) ya existía. */
	public function insert_submission( array $row ): int;

	/** @return bool false ante un error de base de datos (6.28.2). */
	public function update_submission( int $id, array $fields ): bool;

	public function delete_submission( int $id ): void;

	public function insert_upload( array $row ): int;

	public function find_upload( string $upload_token ): ?array;

	/** @return bool false ante un error de base de datos (6.28.2). */
	public function update_upload( int $id, array $fields ): bool;

	/**
	 * Compare-and-set de received_bytes: solo avanza si el valor actual es $from.
	 *
	 * @return bool|null true si avanzó, false si otro valor ganó, null ante un error de base de datos (6.28.2).
	 */
	public function advance_upload( int $id, int $from, int $to ): ?bool;

	/** @return array<int, array> Sesiones vencidas (expires_at < $now_utc) que no quedaron adjuntas. */
	public function expired_uploads( string $now_utc, int $limit ): array;

	public function delete_upload( int $id ): void;
}

final class ATORA_Mobile_Assignment_Wpdb_Store implements ATORA_Mobile_Assignment_Store {
	private function submissions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_assignment_submissions';
	}

	private function uploads_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_upload_sessions';
	}

	public function find_submission_by_event( int $user_id, string $client_event_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->submissions_table()} WHERE user_id = %d AND client_event_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$user_id,
			$client_event_id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function max_attempt( int $user_id, int $lesson_id ): int {
		global $wpdb;
		return absint( $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(attempt) FROM {$this->submissions_table()} WHERE user_id = %d AND lesson_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$user_id,
			$lesson_id
		) ) );
	}

	public function list_submissions( int $user_id, int $lesson_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->submissions_table()} WHERE user_id = %d AND lesson_id = %d AND status <> 'processing' ORDER BY attempt DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$user_id,
			$lesson_id
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function insert_submission( array $row ): int {
		global $wpdb;
		$ok = $wpdb->insert( $this->submissions_table(), $row );
		return false === $ok ? 0 : absint( $wpdb->insert_id );
	}

	public function update_submission( int $id, array $fields ): bool {
		global $wpdb;
		return false !== $wpdb->update( $this->submissions_table(), $fields, array( 'id' => $id ) );
	}

	public function delete_submission( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->submissions_table(), array( 'id' => $id ), array( '%d' ) );
	}

	public function insert_upload( array $row ): int {
		global $wpdb;
		$ok = $wpdb->insert( $this->uploads_table(), $row );
		return false === $ok ? 0 : absint( $wpdb->insert_id );
	}

	public function find_upload( string $upload_token ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->uploads_table()} WHERE upload_token = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$upload_token
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function update_upload( int $id, array $fields ): bool {
		global $wpdb;
		return false !== $wpdb->update( $this->uploads_table(), $fields, array( 'id' => $id ) );
	}

	public function advance_upload( int $id, int $from, int $to ): ?bool {
		global $wpdb;
		$affected = $wpdb->query( $wpdb->prepare(
			"UPDATE {$this->uploads_table()} SET received_bytes = %d WHERE id = %d AND received_bytes = %d AND status = 'open'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$to,
			$id,
			$from
		) );
		if ( false === $affected ) {
			return null;
		}
		return 1 === (int) $affected;
	}

	public function expired_uploads( string $now_utc, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->uploads_table()} WHERE expires_at IS NOT NULL AND expires_at < %s AND status <> 'attached' ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$now_utc,
			max( 1, $limit )
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function delete_upload( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->uploads_table(), array( 'id' => $id ), array( '%d' ) );
	}
}
