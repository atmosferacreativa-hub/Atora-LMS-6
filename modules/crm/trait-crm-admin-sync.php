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
		$queue_id = absint( $queue_id );
		if ( $queue_id <= 0 ) {
			return;
		}

		global $wpdb;
		$queue_table   = "{$wpdb->prefix}atora_message_queue";
		$conv_table    = "{$wpdb->prefix}atora_conversations";
		$message_table = "{$wpdb->prefix}atora_conversation_messages";
		if ( ! self::table_exists( $queue_table ) || ! self::table_exists( $conv_table ) || ! self::table_exists( $message_table ) ) {
			return;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$queue_table} WHERE id = %d LIMIT 1",
				$queue_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || empty( $row ) ) {
			return;
		}

		$user_id    = absint( $row['user_id'] ?? 0 );
		$contact_id = 0;
		if ( $user_id > 0 ) {
			$contact_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}atora_contacts WHERE user_id = %d LIMIT 1",
					$user_id
				)
			);
		}

		$channel    = sanitize_key( (string) ( $row['channel'] ?? 'email' ) );
		$status     = sanitize_key( (string) ( $row['status'] ?? 'pending' ) );
		$template   = sanitize_key( (string) ( $row['template_key'] ?? '' ) );
		$created_at = sanitize_text_field( (string) ( $row['sent_at'] ?: $row['scheduled_at'] ?: current_time( 'mysql', true ) ) );
		$recipient  = sanitize_text_field( (string) ( $row['recipient_phone'] ?? '' ) );
		$provider_message_id = sanitize_text_field( (string) ( $row['provider_message_id'] ?? '' ) );

		$conversation_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$conv_table}
				 WHERE user_id = %d
				   AND channel = %s
				   AND status IN ('open','pending')
				 ORDER BY updated_at DESC
				 LIMIT 1",
				$user_id,
				$channel
			)
		);

		if ( $conversation_id <= 0 ) {
			$wpdb->insert(
				$conv_table,
				array(
					'contact_id'      => $contact_id,
					'user_id'         => $user_id,
					'channel'         => $channel,
					'identity_key'    => '',
					'subject'         => $template ?: __( 'Conversación', 'atora-lms' ),
					'status'          => in_array( $status, array( 'failed', 'pending' ), true ) ? 'pending' : 'open',
					'last_message_at' => $created_at,
					'assigned_to'     => 0,
					'course_id'       => 0,
					'order_id'        => 0,
					'created_at'      => current_time( 'mysql', true ),
					'updated_at'      => current_time( 'mysql', true ),
				),
				array( '%d','%d','%s','%s','%s','%s','%s','%d','%d','%d','%s','%s' )
			);
			$conversation_id = (int) $wpdb->insert_id;
		}

		if ( $conversation_id <= 0 ) {
			return;
		}

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$message_table}
				 WHERE channel = %s
				   AND provider_message_id = %s
				   AND provider_message_id <> ''
				 LIMIT 1",
				$channel,
				$provider_message_id
			)
		);
		if ( $exists > 0 ) {
			return;
		}

		$wpdb->insert(
			$message_table,
			array(
				'conversation_id'     => $conversation_id,
				'direction'           => 'outbound',
				'channel'             => $channel,
				'provider_message_id' => $provider_message_id,
				'sender'              => '',
				'recipient'           => $recipient,
				'subject'             => $template,
				'body_text'           => wp_json_encode( array( 'template' => $template ) ),
				'body_html'           => '',
				'raw_payload_json'    => wp_json_encode( $row ),
				'status'              => in_array( $status, array( 'sent', 'delivered', 'read', 'failed', 'pending' ), true ) ? $status : 'pending',
				'error_message'       => sanitize_text_field( (string) ( $row['error_message'] ?? '' ) ),
				'created_at'          => $created_at,
			),
			array( '%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' )
		);

		$wpdb->update(
			$conv_table,
			array(
				'last_message_at' => $created_at,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $conversation_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Sincroniza correo de atora_email_queue a modelo de conversación.
	 *
	 * @param int $queue_id ID de cola de email.
	 * @return void
	 */
	public static function sync_email_queue_to_conversation( int $queue_id ): void {
		$queue_id = absint( $queue_id );
		if ( $queue_id <= 0 ) {
			return;
		}

		global $wpdb;
		$queue_table   = "{$wpdb->prefix}atora_email_queue";
		$conv_table    = "{$wpdb->prefix}atora_conversations";
		$message_table = "{$wpdb->prefix}atora_conversation_messages";
		if ( ! self::table_exists( $queue_table ) || ! self::table_exists( $conv_table ) || ! self::table_exists( $message_table ) ) {
			return;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$queue_table} WHERE id = %d LIMIT 1",
				$queue_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || empty( $row ) ) {
			return;
		}

		$metadata = is_string( $row['metadata'] ?? '' ) ? json_decode( (string) $row['metadata'], true ) : array();
		$metadata = is_array( $metadata ) ? $metadata : array();
		$recipient_email = sanitize_email( (string) ( $row['recipient_email'] ?? '' ) );
		$recipient_name  = sanitize_text_field( (string) ( $row['recipient_name'] ?? '' ) );
		$user_id         = absint( $row['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			$user_id = absint( $metadata['user_id'] ?? 0 );
		}
		if ( $user_id <= 0 && '' !== $recipient_email && is_email( $recipient_email ) ) {
			$recipient_user = get_user_by( 'email', $recipient_email );
			if ( $recipient_user instanceof \WP_User ) {
				$user_id = absint( $recipient_user->ID );
			}
		}

		$contact_id = self::resolve_contact_id_for_email_queue( $user_id, $recipient_email, $recipient_name, $metadata );
		$identity = sanitize_key( (string) ( $row['identity_key'] ?? $metadata['email_identity'] ?? '' ) );
		$status   = sanitize_key( (string) ( $row['status'] ?? 'pending' ) );
		$created_at = sanitize_text_field( (string) ( $row['sent_at'] ?: $row['scheduled_at'] ?: current_time( 'mysql', true ) ) );
		$subject = sanitize_text_field( (string) ( $row['subject'] ?? '' ) );
		$conversation_status = in_array( $status, array( 'failed', 'pending' ), true ) ? 'pending' : 'open';
		$message_status = in_array( $status, array( 'sent', 'delivered', 'read', 'failed', 'pending', 'sending' ), true ) ? $status : 'pending';
		$opened_at = sanitize_text_field( (string) ( $row['opened_at'] ?? '' ) );
		$clicked_at = sanitize_text_field( (string) ( $row['clicked_at'] ?? '' ) );
		if ( '' !== $opened_at || '' !== $clicked_at ) {
			$message_status = 'read';
		} elseif ( 'bounced' === $status ) {
			$message_status = 'failed';
		}
		$course_id = absint( $metadata['course_id'] ?? 0 );
		$order_id  = absint( $metadata['order_id'] ?? 0 );

		$conversation_id = self::upsert_conversation(
			array(
				'contact_id'      => $contact_id,
				'user_id'         => $user_id,
				'channel'         => 'email',
				'identity_key'    => $identity,
				'subject'         => $subject,
				'status'          => $conversation_status,
				'last_message_at' => $created_at,
				'course_id'       => $course_id,
				'order_id'        => $order_id,
			)
		);
		if ( $conversation_id <= 0 ) {
			return;
		}

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$message_table}
				 WHERE conversation_id = %d
				   AND recipient = %s
				   AND subject = %s
				   AND created_at = %s
				 LIMIT 1",
				$conversation_id,
				$recipient_email,
				$subject,
				$created_at
			)
		);
		if ( $exists > 0 ) {
			$wpdb->update(
				$message_table,
				array(
					'status'        => $message_status,
					'error_message' => sanitize_text_field( (string) ( $row['error_message'] ?? '' ) ),
				),
				array( 'id' => $exists ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$wpdb->update(
				$conv_table,
				array(
					'last_message_at' => $created_at,
					'identity_key'    => $identity,
					'updated_at'      => current_time( 'mysql', true ),
				),
				array( 'id' => $conversation_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		$sender_email = sanitize_email(
			(string) (
				$metadata['from_email']
				?? $metadata['sender_email']
				?? ''
			)
		);
		$sender_name = sanitize_text_field( (string) ( $metadata['from_name'] ?? '' ) );
		$sender      = '';
		if ( '' !== $sender_name && '' !== $sender_email ) {
			$sender = sanitize_text_field( $sender_name . ' <' . $sender_email . '>' );
		} elseif ( '' !== $sender_email ) {
			$sender = $sender_email;
		} elseif ( '' !== $sender_name ) {
			$sender = $sender_name;
		}

		$wpdb->insert(
			$message_table,
			array(
				'conversation_id'     => $conversation_id,
				'direction'           => 'outbound',
				'channel'             => 'email',
				'provider_message_id' => '',
				'sender'              => $sender,
				'recipient'           => $recipient_email,
				'subject'             => $subject,
				'body_text'           => sanitize_textarea_field( (string) ( $row['body_text'] ?? '' ) ),
				'body_html'           => wp_kses_post( (string) ( $row['body_html'] ?? '' ) ),
				'raw_payload_json'    => wp_json_encode( $row ),
				'status'              => $message_status,
				'error_message'       => sanitize_text_field( (string) ( $row['error_message'] ?? '' ) ),
				'created_at'          => $created_at,
			),
			array( '%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s' )
		);

		$wpdb->update(
			$conv_table,
			array(
				'last_message_at' => $created_at,
				'identity_key'    => $identity,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $conversation_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
	}

	/**
	 * Resuelve o crea contacto CRM para un registro de cola de email.
	 *
	 * @param int                  $user_id         Usuario relacionado.
	 * @param string               $recipient_email Email destino.
	 * @param string               $recipient_name  Nombre destino.
	 * @param array<string,mixed>  $metadata        Metadata del envío.
	 * @return int
	 */
	private static function resolve_contact_id_for_email_queue( int $user_id, string $recipient_email, string $recipient_name, array $metadata = array() ): int {
		global $wpdb;

		$contacts_table = "{$wpdb->prefix}atora_contacts";
		if ( ! self::table_exists( $contacts_table ) ) {
			return 0;
		}

		$user_id         = absint( $user_id );
		$recipient_email = sanitize_email( $recipient_email );
		$recipient_name  = sanitize_text_field( $recipient_name );
		$metadata        = is_array( $metadata ) ? $metadata : array();
		$contact_id      = 0;

		if ( $user_id > 0 ) {
			$contact_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$contacts_table} WHERE user_id = %d LIMIT 1",
					$user_id
				)
			);
		}

		if ( $contact_id <= 0 && '' !== $recipient_email && is_email( $recipient_email ) ) {
			$contact_row = (array) $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, user_id FROM {$contacts_table} WHERE email = %s LIMIT 1",
					$recipient_email
				),
				ARRAY_A
			);
			if ( ! empty( $contact_row ) ) {
				$contact_id = absint( $contact_row['id'] ?? 0 );
				$existing_user_id = absint( $contact_row['user_id'] ?? 0 );
				if ( $contact_id > 0 && $user_id > 0 && $existing_user_id <= 0 ) {
					$wpdb->update(
						$contacts_table,
						array(
							'user_id'    => $user_id,
							'updated_at' => current_time( 'mysql', true ),
						),
						array( 'id' => $contact_id ),
						array( '%d', '%s' ),
						array( '%d' )
					);
				}
			}
		}

		if ( $contact_id <= 0 && $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof \WP_User ) {
				$contact_id = self::upsert_contact(
					array(
						'user_id' => $user_id,
						'email'   => sanitize_email( (string) $user->user_email ),
						'name'    => sanitize_text_field( (string) ( $user->display_name ?: $user->user_login ) ),
						'source'  => sanitize_key( (string) ( $metadata['source'] ?? 'email_engine' ) ),
						'status'  => 'student',
					)
				);
			}
		}

		if ( $contact_id <= 0 && '' !== $recipient_email && is_email( $recipient_email ) ) {
			$fallback_name = $recipient_name;
			if ( '' === $fallback_name ) {
				$local_part = strstr( $recipient_email, '@', true );
				$fallback_name = sanitize_text_field( false !== $local_part ? (string) $local_part : $recipient_email );
			}

			$contact_id = self::upsert_contact(
				array(
					'email'  => $recipient_email,
					'name'   => $fallback_name,
					'source' => sanitize_key( (string) ( $metadata['source'] ?? 'email_engine' ) ),
					'status' => 'lead',
				)
			);
		}

		return absint( $contact_id );
	}

}
