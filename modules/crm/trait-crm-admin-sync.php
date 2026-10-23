<?php

namespace ATORA\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CRM_Admin_Sync_Trait {
	// ── Admin AJAX ────────────────────────────────────────────────────────────

	/** @return void */
	public static function ajax_search(): void {
		check_ajax_referer( 'atora_crm_admin' );
		$current_user_id = get_current_user_id();
		if ( ! self::can_access_crm( $current_user_id ) ) { wp_send_json_error(); }

		global $wpdb;
		$q    = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
		$like = '%' . $wpdb->esc_like( $q ) . '%';

		$where  = '(name LIKE %s OR email LIKE %s)';
		$params = array( $like, $like );

		if ( ! self::can_manage_crm( $current_user_id ) ) {
			$allowed_user_ids = self::get_accessible_contact_user_ids( $current_user_id );
			if ( empty( $allowed_user_ids ) ) {
				wp_send_json_success( array() );
			}
			$in_clause = implode( ',', array_fill( 0, count( $allowed_user_ids ), '%d' ) );
			$where    .= " AND user_id IN ({$in_clause})";
			foreach ( $allowed_user_ids as $allowed_user_id ) {
				$params[] = absint( $allowed_user_id );
			}
		}

		$params[] = 50;
		$rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT id, name, email, status, country, updated_at
				 FROM {$wpdb->prefix}atora_contacts
				 WHERE {$where}
				 ORDER BY updated_at DESC LIMIT %d",
				...$params
			)
		);
		wp_send_json_success( $rows );
	}

	/** @return void */
	public static function ajax_save_note(): void {
		check_ajax_referer( 'atora_crm_admin' );
		$current_user_id = get_current_user_id();
		if ( ! self::can_access_crm( $current_user_id ) ) { wp_send_json_error(); }
		$contact_id = absint( wp_unslash( $_POST['contact_id'] ?? 0 ) );
		if ( ! self::current_user_can_access_contact( $contact_id, $current_user_id ) ) { wp_send_json_error(); }
		$text       = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
		$pinned     = ! empty( $_POST['pinned'] );
		$id = self::save_note( $contact_id, $text, $pinned );
		$id ? wp_send_json_success( array( 'id' => $id ) ) : wp_send_json_error();
	}

	/** @return void */
	public static function ajax_add_tag(): void {
		check_ajax_referer( 'atora_crm_admin' );
		$current_user_id = get_current_user_id();
		if ( ! self::can_access_crm( $current_user_id ) ) { wp_send_json_error(); }
		$user_id  = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
		if ( ! $user_id ) { wp_send_json_error(); }

		if ( ! self::can_manage_crm( $current_user_id ) ) {
			$allowed_user_ids = self::get_accessible_contact_user_ids( $current_user_id );
			if ( empty( $allowed_user_ids ) || ! in_array( $user_id, $allowed_user_ids, true ) ) {
				wp_send_json_error();
			}
		}

		$tag      = sanitize_text_field( wp_unslash( $_POST['tag'] ?? '' ) );
		$action   = sanitize_key( wp_unslash( $_POST['action_type'] ?? 'add' ) );
		'remove' === $action ? self::remove_tag( $user_id, $tag ) : self::add_tag( $user_id, $tag );
		wp_send_json_success();
	}

	/**
	 * Marca un correo de la cola como abierto, preservando `opened_at` existente.
	 *
	 * @param int $queue_id ID en `atora_email_queue`.
	 * @return bool
	 */
	public static function mark_email_opened( int $queue_id ): bool {
		$queue_id = absint( $queue_id );
		if ( $queue_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$table = "{$wpdb->prefix}atora_email_queue";
		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT id, opened_at FROM {$table} WHERE id = %d LIMIT 1",
				$queue_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || empty( $row ) ) {
			return false;
		}

		$opened_at = sanitize_text_field( (string) ( $row['opened_at'] ?? '' ) );
		if ( '' === $opened_at ) {
			$opened_at = current_time( 'mysql', true );
		}

		$updated = $wpdb->update(
			$table,
			array( 'opened_at' => $opened_at ),
			array( 'id' => $queue_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Marca un mensaje de cola como leído, preservando `read_at` existente.
	 *
	 * @param int $queue_id ID en `atora_message_queue`.
	 * @return bool
	 */
	public static function mark_message_read( int $queue_id ): bool {
		$queue_id = absint( $queue_id );
		if ( $queue_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$table = "{$wpdb->prefix}atora_message_queue";
		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"SELECT id, read_at FROM {$table} WHERE id = %d LIMIT 1",
				$queue_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || empty( $row ) ) {
			return false;
		}

		$read_at = sanitize_text_field( (string) ( $row['read_at'] ?? '' ) );
		if ( '' === $read_at ) {
			$read_at = current_time( 'mysql', true );
		}

		$updated = $wpdb->update(
			$table,
			array(
				'status'  => 'read',
				'read_at' => $read_at,
			),
			array( 'id' => $queue_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Sincroniza mensaje de atora_message_queue a modelo de conversación.
	 *
	 * @param int $queue_id ID de cola.
	 * @return void
	 */
	public static function sync_message_queue_to_conversation( int $queue_id ): void {
		clms_core( 'CLMS_Contacts_Core_Service' )->sync_message_queue_to_conversation( $queue_id );
	}

	/**
	 * Sincroniza correo de atora_email_queue a modelo de conversación.
	 *
	 * @param int $queue_id ID de cola de email.
	 * @return void
	 */
	public static function sync_email_queue_to_conversation( int $queue_id ): void {
		clms_core( 'CLMS_Contacts_Core_Service' )->sync_email_queue_to_conversation( $queue_id );
	}

}
