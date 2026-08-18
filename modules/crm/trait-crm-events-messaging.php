<?php

namespace ATORA\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CRM_Events_Messaging_Trait {
	// ── Hooks de actividad ────────────────────────────────────────────────────

	/**
	 * Crea o actualiza un contacto cuando se registra un nuevo usuario.
	 *
	 * @param int $user_id ID del usuario.
	 * @return void
	 */
	public static function on_user_registered( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) { return; }

		self::upsert_contact( array(
			'user_id' => $user_id,
			'email'   => $user->user_email,
			'name'    => $user->display_name,
			'source'  => 'registration',
			'status'  => 'lead',
		) );

		self::log_activity( $user_id, 'user_registered', array( 'email' => $user->user_email ) );
	}

	/**
	 * Sincroniza CRM cuando el registro extendido guarda metadatos de perfil.
	 *
	 * @param int   $user_id     ID del usuario.
	 * @param array $consent_log Bitácora de consentimiento.
	 * @return void
	 */
	public static function on_registration_profile_saved( int $user_id, array $consent_log = array() ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		self::upsert_contact(
			array(
				'user_id'  => $user_id,
				'email'    => (string) $user->user_email,
				'name'     => (string) $user->display_name,
				'phone'    => sanitize_text_field( (string) get_user_meta( $user_id, 'atora_phone', true ) ),
				'whatsapp' => sanitize_text_field( (string) get_user_meta( $user_id, 'atora_whatsapp', true ) ),
				'country'  => sanitize_text_field( (string) get_user_meta( $user_id, 'atora_country_code', true ) ),
				'city'     => sanitize_text_field( (string) get_user_meta( $user_id, 'atora_city', true ) ),
				'state'    => sanitize_text_field( (string) get_user_meta( $user_id, 'atora_state', true ) ),
				'sex'      => sanitize_key( (string) get_user_meta( $user_id, 'atora_sex', true ) ),
				'age'      => absint( get_user_meta( $user_id, 'atora_age', true ) ),
				'source'   => 'registration',
				'status'   => 'lead',
			)
		);

		self::log_activity(
			$user_id,
			'profile_registration_saved',
			array(
				'has_phone'    => '' !== (string) get_user_meta( $user_id, 'atora_phone', true ),
				'has_whatsapp' => '' !== (string) get_user_meta( $user_id, 'atora_whatsapp', true ),
				'has_telegram' => '' !== (string) get_user_meta( $user_id, 'atora_telegram', true ),
				'has_state'    => '' !== (string) get_user_meta( $user_id, 'atora_state', true ),
				'has_sex'      => '' !== (string) get_user_meta( $user_id, 'atora_sex', true ),
				'has_age'      => '' !== (string) get_user_meta( $user_id, 'atora_age', true ),
			)
		);
	}

	/**
	 * Registra una compra en el timeline CRM.
	 *
	 * @param int $order_id ID del pedido.
	 * @return void
	 */
	public static function on_purchase( int $order_id ): void {
		$order   = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$user_id = $order ? absint( $order->get_customer_id() ) : 0;

		if ( ! $user_id ) { return; }

		self::upsert_contact( array(
			'user_id' => $user_id,
			'status'  => 'student',
		) );

		self::log_activity( $user_id, 'course_purchased', array(
			'order_id' => $order_id,
			'total'    => $order ? $order->get_total() : 0,
		) );

		self::add_tag( $user_id, 'comprador' );
	}

	/**
	 * Registra la inscripción en un curso.
	 *
	 * @param int $user_id   ID del usuario.
	 * @param int $course_id ID del curso.
	 * @return void
	 */
	public static function on_course_enrolled( int $user_id, int $course_id ): void {
		self::log_activity( $user_id, 'course_enrolled', array( 'course_id' => $course_id ) );
		self::upsert_contact( array( 'user_id' => $user_id, 'status' => 'student' ) );
	}

	/**
	 * Registra el envío de un formulario.
	 *
	 * @param int   $form_id  ID del formulario.
	 * @param int   $entry_id ID de la entrada.
	 * @param array $data     Datos enviados.
	 * @return void
	 */
	public static function on_form_submitted( int $form_id, int $entry_id, array $data ): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) { return; }

		self::log_activity( $user_id, 'form_submitted', array(
			'form_id'  => $form_id,
			'entry_id' => $entry_id,
		) );
	}

	/**
	 * Registra aperturas y clics de email en el timeline.
	 *
	 * @param string $event_type Tipo de evento.
	 * @param int    $queue_id   ID en la queue.
	 * @param array  $event      Datos del evento.
	 * @return void
	 */
	public static function on_email_event( string $event_type, int $queue_id, array $event ): void {
		global $wpdb;

		if ( ! in_array( $event_type, array( 'opened', 'clicked' ), true ) ) { return; }

		$user_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->prefix}atora_email_queue WHERE id = %d LIMIT 1",
			$queue_id
		) );

		if ( $user_id ) {
			self::log_activity( $user_id, 'email_' . $event_type, array( 'queue_id' => $queue_id ) );
		}
	}

	/**
	 * Registra mensajes inbound de WhatsApp en la conversación unificada.
	 *
	 * @param array $message Mensaje recibido.
	 * @param array $context Contexto del webhook.
	 * @return void
	 */
	public static function on_whatsapp_message_received( array $message, array $context = array() ): void {
		global $wpdb;

		$conv_table    = "{$wpdb->prefix}atora_conversations";
		$message_table = "{$wpdb->prefix}atora_conversation_messages";
		if ( ! self::table_exists( $conv_table ) || ! self::table_exists( $message_table ) ) {
			return;
		}

		$from_raw = sanitize_text_field( (string) ( $message['from'] ?? '' ) );
		$from     = self::normalize_contact_phone( $from_raw );
		if ( '' === $from ) {
			return;
		}

		$provider_message_id = sanitize_text_field( (string) ( $message['id'] ?? '' ) );
		$timestamp           = sanitize_text_field( (string) ( $message['timestamp'] ?? '' ) );
		$created_at          = current_time( 'mysql', true );
		if ( '' !== $timestamp && ctype_digit( $timestamp ) ) {
			$created_at = gmdate( 'Y-m-d H:i:s', absint( $timestamp ) );
		}

		$message_type = sanitize_key( (string) ( $message['type'] ?? 'text' ) );
		$body_text    = sanitize_textarea_field( (string) ( $message['text']['body'] ?? '' ) );
		if ( '' === $body_text && 'button' === $message_type ) {
			$body_text = sanitize_textarea_field( (string) ( $message['button']['text'] ?? '' ) );
		}
		if ( '' === $body_text && 'interactive' === $message_type ) {
			$body_text = sanitize_textarea_field( (string) ( $message['interactive']['button_reply']['title'] ?? '' ) );
		}
		if ( '' === $body_text ) {
			$body_text = sanitize_textarea_field( (string) wp_json_encode( $message ) );
		}

		$sender_name = sanitize_text_field( (string) ( $context['contacts'][0]['profile']['name'] ?? '' ) );
		$user_id     = self::find_user_id_by_phone( $from );
		if ( $user_id <= 0 && self::$last_phone_user_match_ambiguous ) {
			self::log_inbound_resolution_issue(
				'inbound_phone_collision_skip',
				array(
					'channel' => 'whatsapp',
					'phone'   => $from,
					'reason'  => 'multiple_users',
				)
			);
			return;
		}
		$user        = $user_id > 0 ? get_userdata( $user_id ) : null;
		if ( '' === $sender_name && $user instanceof \WP_User ) {
			$sender_name = sanitize_text_field( (string) $user->display_name );
		}

		$contact_email = $user instanceof \WP_User
			? sanitize_email( (string) $user->user_email )
			: self::build_shadow_contact_email( 'whatsapp', $from );

		$contact_id = self::upsert_contact(
			array(
				'user_id'  => $user_id,
				'email'    => $contact_email,
				'name'     => $sender_name,
				'phone'    => $from,
				'whatsapp' => $from,
				'source'   => 'whatsapp',
				'status'   => 'lead',
			)
		);
		if ( $contact_id <= 0 ) {
			$contact_id = self::find_contact_id_by_phone( $from );
		}
		if ( $contact_id <= 0 ) {
			return;
		}

		$conversation_id = self::upsert_conversation(
			array(
				'contact_id'      => $contact_id,
				'user_id'         => $user_id,
				'channel'         => 'whatsapp',
				'subject'         => __( 'WhatsApp', 'atora-lms' ),
				'status'          => 'open',
				'last_message_at' => $created_at,
			)
		);
		if ( $conversation_id <= 0 ) {
			return;
		}

		if ( '' !== $provider_message_id ) {
			$existing_message = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$message_table}
					 WHERE channel = %s
					   AND provider_message_id = %s
					   AND provider_message_id <> ''
					 LIMIT 1",
					'whatsapp',
					$provider_message_id
				)
			);
			if ( $existing_message > 0 ) {
				return;
			}
		}

		$wpdb->insert(
			$message_table,
			array(
				'conversation_id'     => $conversation_id,
				'direction'           => 'inbound',
				'channel'             => 'whatsapp',
				'provider_message_id' => $provider_message_id,
				'sender'              => $from,
				'recipient'           => sanitize_text_field( (string) get_bloginfo( 'name' ) ),
				'subject'             => __( 'Mensaje entrante WhatsApp', 'atora-lms' ),
				'body_text'           => $body_text,
				'body_html'           => '',
				'raw_payload_json'    => wp_json_encode(
					array(
						'message' => $message,
						'context' => $context,
					)
				),
				'status'              => 'received',
				'error_message'       => '',
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

		if ( $user_id > 0 ) {
			self::log_activity(
				$user_id,
				'whatsapp_received',
				array(
					'conversation_id'     => $conversation_id,
					'provider_message_id' => $provider_message_id,
				)
			);
		}
	}

	/**
	 * Registra mensajes inbound de Telegram en la conversación unificada.
	 *
	 * @param string $chat_id  Chat ID de Telegram.
	 * @param string $text     Texto normalizado.
	 * @param array  $message  Payload del mensaje.
	 * @return void
	 */
	public static function on_telegram_message_received( string $chat_id, string $text, array $message = array() ): void {
		global $wpdb;

		$conv_table    = "{$wpdb->prefix}atora_conversations";
		$message_table = "{$wpdb->prefix}atora_conversation_messages";
		if ( ! self::table_exists( $conv_table ) || ! self::table_exists( $message_table ) ) {
			return;
		}

		$chat_id = (string) preg_replace( '/[^0-9\-]/', '', (string) $chat_id );
		if ( '' === $chat_id ) {
			return;
		}

		$body_text = sanitize_textarea_field( $text );
		if ( '' === $body_text ) {
			$body_text = sanitize_textarea_field( (string) ( $message['caption'] ?? '' ) );
		}
		if ( '' === $body_text ) {
			$body_text = sanitize_textarea_field( (string) wp_json_encode( $message ) );
		}

		$message_id          = absint( $message['message_id'] ?? 0 );
		$provider_message_id = $message_id > 0 ? ( $chat_id . ':' . $message_id ) : '';
		$timestamp           = absint( $message['date'] ?? 0 );
		$created_at          = $timestamp > 0 ? gmdate( 'Y-m-d H:i:s', $timestamp ) : current_time( 'mysql', true );

		$user_id = self::find_user_id_by_telegram_chat( $chat_id );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;

		$from_username = sanitize_text_field( (string) ( $message['from']['username'] ?? '' ) );
		$from_name     = sanitize_text_field(
			trim(
				(string) ( $message['from']['first_name'] ?? '' ) . ' ' . (string) ( $message['from']['last_name'] ?? '' )
			)
		);
		if ( '' === $from_name && $user instanceof \WP_User ) {
			$from_name = sanitize_text_field( (string) $user->display_name );
		}

		$contact_email = $user instanceof \WP_User
			? sanitize_email( (string) $user->user_email )
			: self::build_shadow_contact_email( 'telegram', $chat_id );

		$contact_id = self::upsert_contact(
			array(
				'user_id' => $user_id,
				'email'   => $contact_email,
				'name'    => $from_name,
				'source'  => 'telegram',
				'status'  => 'lead',
			)
		);
		if ( $contact_id <= 0 ) {
			return;
		}

		$conversation_id = self::upsert_conversation(
			array(
				'contact_id'      => $contact_id,
				'user_id'         => $user_id,
				'channel'         => 'telegram',
				'subject'         => __( 'Telegram', 'atora-lms' ),
				'status'          => 'open',
				'last_message_at' => $created_at,
			)
		);
		if ( $conversation_id <= 0 ) {
			return;
		}

		if ( '' !== $provider_message_id ) {
			$existing_message = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$message_table}
					 WHERE channel = %s
					   AND provider_message_id = %s
					   AND provider_message_id <> ''
					 LIMIT 1",
					'telegram',
					$provider_message_id
				)
			);
			if ( $existing_message > 0 ) {
				return;
			}
		}

		$sender = $chat_id;
		if ( '' !== $from_username ) {
			$sender = '@' . ltrim( $from_username, '@' ) . ' (' . $chat_id . ')';
		}

		$wpdb->insert(
			$message_table,
			array(
				'conversation_id'     => $conversation_id,
				'direction'           => 'inbound',
				'channel'             => 'telegram',
				'provider_message_id' => $provider_message_id,
				'sender'              => sanitize_text_field( $sender ),
				'recipient'           => sanitize_text_field( (string) get_bloginfo( 'name' ) ),
				'subject'             => __( 'Mensaje entrante Telegram', 'atora-lms' ),
				'body_text'           => $body_text,
				'body_html'           => '',
				'raw_payload_json'    => wp_json_encode( $message ),
				'status'              => 'received',
				'error_message'       => '',
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

		if ( $user_id > 0 ) {
			self::log_activity(
				$user_id,
				'telegram_received',
				array(
					'conversation_id'     => $conversation_id,
					'provider_message_id' => $provider_message_id,
				)
			);
		}
	}

	/**
	 * Crea o actualiza una conversación para canal/contacto.
	 *
	 * @param array<string,mixed> $args Datos de conversación.
	 * @return int
	 */
	private static function upsert_conversation( array $args ): int {
		global $wpdb;

		$conv_table = "{$wpdb->prefix}atora_conversations";
		if ( ! self::table_exists( $conv_table ) ) {
			return 0;
		}

		$contact_id      = absint( $args['contact_id'] ?? 0 );
		$user_id         = absint( $args['user_id'] ?? 0 );
		$channel         = sanitize_key( (string) ( $args['channel'] ?? 'email' ) );
		$identity_key    = sanitize_key( (string) ( $args['identity_key'] ?? '' ) );
		$subject         = sanitize_text_field( (string) ( $args['subject'] ?? '' ) );
		$status          = sanitize_key( (string) ( $args['status'] ?? 'open' ) );
		$last_message_at = sanitize_text_field( (string) ( $args['last_message_at'] ?? current_time( 'mysql', true ) ) );
		$course_id       = absint( $args['course_id'] ?? 0 );
		$order_id        = absint( $args['order_id'] ?? 0 );

		if ( '' === $channel || ( 0 === $contact_id && 0 === $user_id ) ) {
			return 0;
		}

		$conversation_id = 0;
		if ( $user_id > 0 ) {
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
		}
		if ( $conversation_id <= 0 && $contact_id > 0 ) {
			$conversation_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$conv_table}
					 WHERE contact_id = %d
					   AND channel = %s
					   AND status IN ('open','pending')
					 ORDER BY updated_at DESC
					 LIMIT 1",
					$contact_id,
					$channel
				)
			);
		}

		if ( $conversation_id > 0 ) {
			$update_data = array(
				'last_message_at' => $last_message_at,
				'updated_at'      => current_time( 'mysql', true ),
			);
			if ( $contact_id > 0 ) {
				$update_data['contact_id'] = $contact_id;
			}
			if ( $user_id > 0 ) {
				$update_data['user_id'] = $user_id;
			}
			if ( '' !== $identity_key ) {
				$update_data['identity_key'] = $identity_key;
			}
			if ( '' !== $subject ) {
				$update_data['subject'] = $subject;
			}
			$wpdb->update( $conv_table, $update_data, array( 'id' => $conversation_id ) );
			return $conversation_id;
		}

		$inserted = $wpdb->insert(
			$conv_table,
			array(
				'contact_id'      => $contact_id,
				'user_id'         => $user_id,
				'channel'         => $channel,
				'identity_key'    => $identity_key,
				'subject'         => '' !== $subject ? $subject : __( 'Conversación', 'atora-lms' ),
				'status'          => in_array( $status, array( 'open', 'pending', 'closed', 'archived' ), true ) ? $status : 'open',
				'last_message_at' => $last_message_at,
				'assigned_to'     => 0,
				'course_id'       => $course_id,
				'order_id'        => $order_id,
				'created_at'      => current_time( 'mysql', true ),
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( '%d','%d','%s','%s','%s','%s','%s','%d','%d','%d','%s','%s' )
		);

		if ( ! $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Busca un usuario por teléfono (meta `atora_phone`).
	 *
	 * @param string $phone Teléfono.
	 * @return int
	 */
	private static function find_user_id_by_phone( string $phone ): int {
		global $wpdb;
		self::$last_phone_user_match_ambiguous = false;

		$phone = self::normalize_contact_phone( $phone );
		if ( '' === $phone ) {
			return 0;
		}

		$digits = (string) preg_replace( '/\D+/', '', $phone );
		if ( '' === $digits ) {
			return 0;
		}

		$suffix = strlen( $digits ) >= 8 ? substr( $digits, -8 ) : $digits;
		$rows   = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value
				 FROM {$wpdb->usermeta}
				 WHERE meta_key = 'atora_phone'
				   AND (
					   meta_value = %s
					   OR meta_value = %s
					   OR meta_value LIKE %s
				 )
				 LIMIT 100",
				$phone,
				ltrim( $phone, '+' ),
				'%' . $suffix
			),
			ARRAY_A
		);

		$matches = array();
		foreach ( $rows as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			if ( $user_id <= 0 ) {
				continue;
			}
			$candidate_phone  = self::normalize_contact_phone( (string) ( $row['meta_value'] ?? '' ) );
			$candidate_digits = (string) preg_replace( '/\D+/', '', $candidate_phone );
			if ( '' !== $candidate_digits && hash_equals( $digits, $candidate_digits ) ) {
				$matches[ $user_id ] = true;
			}
		}

		$match_ids = array_map( 'absint', array_keys( $matches ) );
		if ( 1 === count( $match_ids ) ) {
			return (int) $match_ids[0];
		}

		if ( count( $match_ids ) > 1 ) {
			self::$last_phone_user_match_ambiguous = true;
			self::log_inbound_resolution_issue(
				'inbound_phone_ambiguous_user',
				array(
					'phone'      => $phone,
					'candidates' => $match_ids,
				)
			);
		}

		return 0;
	}

	/**
	 * Busca un usuario por chat ID de Telegram.
	 *
	 * @param string $chat_id Chat ID.
	 * @return int
	 */
	private static function find_user_id_by_telegram_chat( string $chat_id ): int {
		global $wpdb;

		$chat_id = (string) preg_replace( '/[^0-9\-]/', '', $chat_id );
		if ( '' === $chat_id ) {
			return 0;
		}

		$user_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id
				 FROM {$wpdb->usermeta}
				 WHERE meta_key = 'atora_telegram_chat_id'
				   AND meta_value = %s
				 LIMIT 1",
				$chat_id
			)
		);

		return $user_id > 0 ? $user_id : 0;
	}

	/**
	 * Busca contacto por teléfono/whatsapp.
	 *
	 * @param string $phone Teléfono normalizado.
	 * @return int
	 */
	private static function find_contact_id_by_phone( string $phone ): int {
		global $wpdb;
		self::$last_phone_contact_match_ambiguous = false;

		$contacts_table = "{$wpdb->prefix}atora_contacts";
		if ( ! self::table_exists( $contacts_table ) ) {
			return 0;
		}

		$phone  = self::normalize_contact_phone( $phone );
		$digits = (string) preg_replace( '/\D+/', '', $phone );
		if ( '' === $digits ) {
			return 0;
		}

		$suffix = strlen( $digits ) >= 8 ? substr( $digits, -8 ) : $digits;
		$rows   = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, phone, whatsapp
				 FROM {$contacts_table}
				 WHERE phone = %s
				    OR whatsapp = %s
				    OR phone LIKE %s
				    OR whatsapp LIKE %s
				 LIMIT 100",
				$phone,
				$phone,
				'%' . $suffix,
				'%' . $suffix
			),
			ARRAY_A
		);

		$matches = array();
		foreach ( $rows as $row ) {
			$contact_id = absint( $row['id'] ?? 0 );
			if ( $contact_id <= 0 ) {
				continue;
			}

			$phone_digits    = (string) preg_replace( '/\D+/', '', self::normalize_contact_phone( (string) ( $row['phone'] ?? '' ) ) );
			$whatsapp_digits = (string) preg_replace( '/\D+/', '', self::normalize_contact_phone( (string) ( $row['whatsapp'] ?? '' ) ) );

			if ( ( '' !== $phone_digits && hash_equals( $digits, $phone_digits ) ) || ( '' !== $whatsapp_digits && hash_equals( $digits, $whatsapp_digits ) ) ) {
				$matches[ $contact_id ] = true;
			}
		}

		$match_ids = array_map( 'absint', array_keys( $matches ) );
		if ( 1 === count( $match_ids ) ) {
			return (int) $match_ids[0];
		}

		if ( count( $match_ids ) > 1 ) {
			self::$last_phone_contact_match_ambiguous = true;
			self::log_inbound_resolution_issue(
				'inbound_phone_ambiguous_contact',
				array(
					'phone'      => $phone,
					'candidates' => $match_ids,
				)
			);
		}

		return 0;
	}

	/**
	 * Normaliza teléfono para matching entre canales.
	 *
	 * @param string $phone Teléfono.
	 * @return string
	 */
	private static function normalize_contact_phone( string $phone ): string {
		$phone = sanitize_text_field( $phone );
		if ( '' === $phone ) {
			return '';
		}

		$has_plus = 0 === strpos( $phone, '+' );
		$digits   = (string) preg_replace( '/\D+/', '', $phone );
		if ( '' === $digits ) {
			return '';
		}

		if ( ! $has_plus && 0 === strpos( $digits, '00' ) ) {
			$digits   = substr( $digits, 2 );
			$has_plus = true;
		}

		return $has_plus ? '+' . ltrim( $digits, '+' ) : ltrim( $digits, '+' );
	}

	/**
	 * Construye email placeholder estable para leads sin email explícito.
	 *
	 * @param string $channel    Canal origen.
	 * @param string $identifier Identificador externo.
	 * @return string
	 */
	private static function build_shadow_contact_email( string $channel, string $identifier ): string {
		$channel    = sanitize_key( $channel );
		$identifier = sanitize_text_field( $identifier );
		$hash       = substr( md5( $channel . ':' . $identifier ), 0, 20 );

		return $channel . '-' . $hash . '@atora.local';
	}

	/**
	 * Registra incidencias de resolución inbound para trazabilidad operativa.
	 *
	 * @param string $event_type Tipo de incidencia.
	 * @param array  $event_data Datos asociados.
	 * @return void
	 */
	private static function log_inbound_resolution_issue( string $event_type, array $event_data = array() ): void {
		global $wpdb;

		$table = "{$wpdb->prefix}atora_message_log";
		if ( ! self::table_exists( $table ) ) {
			return;
		}

		$event_type = sanitize_key( $event_type );
		if ( '' === $event_type ) {
			$event_type = 'inbound_resolution_issue';
		}

		$wpdb->insert(
			$table,
			array(
				'queue_id'   => 0,
				'event_type' => $event_type,
				'event_data' => wp_json_encode( $event_data ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

}
