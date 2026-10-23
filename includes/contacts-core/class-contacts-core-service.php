<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PT-1 (6.11.0): implementación real de primitivas de contacto que
 * históricamente vivían en el CRM legacy (v1). Este servicio se registra
 * en el grupo 'core' del loader (sin condition: 'module:crm') para que
 * exista siempre, sea cual sea el estado del módulo CRM — así el módulo
 * académico (y cualquier código nuevo) puede depender de "puedo ver este
 * contacto" / "registrá esta actividad" sin acoplarse a la UI legacy.
 *
 * upsert_contact() deliberadamente NO se migró: su resolución de contacto
 * por teléfono/WhatsApp (find_contact_id_by_phone/normalize_contact_phone,
 * en trait-crm-events-messaging.php) está entrelazada con el pipeline de
 * ingestión de mensajes entrantes de v1 -- migrarla habría significado
 * arrastrar esa maquinaria completa por una sola primitiva que este sprint
 * no necesita. log_activity() (abajo) sí necesita poder crear un contacto
 * cuando no existe, así que usa ensure_contact_for_user(), una versión
 * mínima (sin matching por teléfono, sin sync de user_meta) suficiente para
 * ese único uso interno.
 */
class CLMS_Contacts_Core_Service {
	/**
	 * Indica si el último match por teléfono tuvo múltiples candidatos.
	 *
	 * Se usa por upsert_contact() (portado de CRM v1) para mantener el
	 * comportamiento de rechazo en casos ambiguos.
	 */
	protected bool $last_phone_contact_match_ambiguous = false;

	/**
	 * Indica si el último match de usuario por teléfono tuvo múltiples candidatos.
	 */
	protected bool $last_phone_user_match_ambiguous = false;

	/**
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public function can_access_crm( int $user_id = 0 ): bool {
		$user_id = absint( $user_id ?: get_current_user_id() );
		if ( ! $user_id ) {
			return false;
		}

		$is_super_admin = function_exists( 'is_super_admin' ) && is_super_admin( $user_id );
		if ( user_can( $user_id, 'manage_options' ) || $is_super_admin ) {
			return true;
		}

		$allowed_caps = array(
			'manage_options',
			'clms_access_crm_view',
			'clms_manage_crm',
			'clms_access_admin',
			'clms_manage_commerce',
			'clms_manage_courses',
			'clms_manage_lessons',
			'clms_view_teacher_dashboard',
			'clms_grade_submissions',
		);

		$can_access = false;
		foreach ( $allowed_caps as $capability ) {
			if ( user_can( $user_id, $capability ) ) {
				$can_access = true;
				break;
			}
		}

		/** Ver docblock de atora_crm_can_access_user en el CRM v1 original -- mismo filtro. */
		return (bool) apply_filters( 'atora_crm_can_access_user', $can_access, $user_id, $allowed_caps );
	}

	/**
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public function can_manage_crm( int $user_id = 0 ): bool {
		$user_id = absint( $user_id ?: get_current_user_id() );
		return $user_id && ( user_can( $user_id, 'clms_manage_crm' ) || user_can( $user_id, 'manage_options' ) );
	}

	/**
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public function has_global_contact_scope( int $user_id = 0 ): bool {
		$user_id = absint( $user_id ?: get_current_user_id() );
		if ( ! $user_id ) {
			return false;
		}

		if ( ! $this->can_access_crm( $user_id ) ) {
			return false;
		}

		$is_super_admin = function_exists( 'is_super_admin' ) && is_super_admin( $user_id );
		if ( user_can( $user_id, 'manage_options' ) || $is_super_admin ) {
			return true;
		}

		// PT-1.3 (6.5.1, heredado de v1): clms_manage_courses/clms_manage_lessons
		// son caps docentes -- cualquier lms_instructor las tiene y no debía
		// heredar alcance global de contactos por eso. clms_access_admin
		// tampoco cuenta: solo significa "puede entrar al panel ATORA".
		if ( user_can( $user_id, 'clms_manage_commerce' ) ) {
			return true;
		}

		if (
			user_can( $user_id, 'edit_posts' )
			&& ! user_can( $user_id, 'clms_view_teacher_dashboard' )
			&& ! user_can( $user_id, 'clms_grade_submissions' )
		) {
			return true;
		}

		return false;
	}

	/**
	 * @param int $user_id Usuario.
	 * @return array<int,int>
	 */
	public function get_accessible_contact_user_ids( int $user_id = 0 ): array {
		$user_id = absint( $user_id ?: get_current_user_id() );
		if ( ! $user_id ) {
			return array();
		}

		if ( $this->can_manage_crm( $user_id ) ) {
			return array();
		}

		if ( ! $this->can_access_crm( $user_id ) ) {
			return array();
		}

		if ( $this->has_global_contact_scope( $user_id ) ) {
			return array();
		}

		$student_ids = array();
		$messaging   = null;

		// PT-1 (6.11.0): guard idéntico al original de v1 (movido tal
		// cual, no reescrito) -- method_exists('\CLMS_Helper', 'module')
		// no tiene relación obvia con clms_core(), pero se preserva sin
		// tocar para no introducir una diferencia de comportamiento.
		if ( class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'module' ) ) {
			$messaging = clms_core( 'CLMS_Messaging' );
		}
		if ( ! $messaging && class_exists( '\CLMS_Messaging' ) ) {
			$messaging = new \CLMS_Messaging();
		}

		if ( $messaging && method_exists( $messaging, 'get_compose_context_for_user' ) ) {
			$context     = (array) $messaging->get_compose_context_for_user( $user_id );
			$student_ids = array_map( 'absint', array_keys( (array) ( $context['students'] ?? array() ) ) );
		}

		if ( empty( $student_ids ) && class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'get_enrolled_student_ids' ) ) {
			$course_ids = get_posts(
				array(
					'post_type'              => 'lm_course',
					'post_status'            => array( 'publish', 'private', 'draft' ),
					'author'                 => $user_id,
					'fields'                 => 'ids',
					'posts_per_page'         => 200,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( (array) $course_ids as $course_id ) {
				foreach ( (array) \CLMS_Helper::get_enrolled_student_ids( absint( $course_id ) ) as $student_id ) {
					$student_ids[] = absint( $student_id );
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $student_ids ) ) ) );
	}

	/**
	 * @param int $user_id Usuario.
	 * @return object|null
	 */
	public function get_contact( int $user_id ): ?object {
		global $wpdb;
		$contacts_table = "{$wpdb->prefix}atora_contacts";

		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! $this->table_exists( $contacts_table ) ) {
			return null;
		}

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$contacts_table} WHERE user_id = %d LIMIT 1",
			$user_id
		) ) ?: null;
	}

	/**
	 * Devuelve el ID del contacto asociado a un usuario.
	 *
	 * Si aún no existe una fila CRM para el usuario, crea una fila mínima
	 * (misma estrategia usada por log_activity()).
	 *
	 * @param int $user_id Usuario.
	 * @return int ID del contacto, o 0 si no se pudo resolver.
	 */
	public function get_contact_id_by_user( int $user_id ): int {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return 0;
		}

		$contact = $this->get_contact( $user_id );
		$contact_id = $contact ? absint( $contact->id ?? 0 ) : 0;
		if ( $contact_id > 0 ) {
			return $contact_id;
		}

		$contact = $this->ensure_contact_for_user( $user_id );
		return $contact ? absint( $contact->id ?? 0 ) : 0;
	}

	/**
	 * Crea o actualiza un contacto en la tabla CRM.
	 *
	 * Portado desde CRM v1 como primitiva compartida.
	 *
	 * @param array<string,mixed> $data Datos del contacto.
	 * @return int ID del contacto.
	 */
	public function upsert_contact( array $data ): int {
		global $wpdb;

		$user_id      = absint( $data['user_id'] ?? 0 );
		$email        = sanitize_email( $data['email'] ?? '' );
		$raw_phone    = sanitize_text_field( (string) ( $data['phone'] ?? get_user_meta( $user_id, 'atora_phone', true ) ) );
		$raw_whatsapp = sanitize_text_field( (string) ( $data['whatsapp'] ?? '' ) );
		$phone        = $this->normalize_contact_phone( $raw_phone );
		$whatsapp     = $this->normalize_contact_phone( $raw_whatsapp );
		$state        = sanitize_text_field( (string) ( $data['state'] ?? get_user_meta( $user_id, 'atora_state', true ) ) );
		$sex          = sanitize_key( (string) ( $data['sex'] ?? get_user_meta( $user_id, 'atora_sex', true ) ) );
		$age          = absint( $data['age'] ?? get_user_meta( $user_id, 'atora_age', true ) );
		$allowed_sexes = array( 'femenino', 'masculino', 'no_binario', 'prefiero_no_decir' );
		if ( '' !== $sex && ! in_array( $sex, $allowed_sexes, true ) ) {
			$sex = '';
		}
		if ( $age > 120 ) {
			$age = 120;
		}

		if ( ! $user_id && ! $email ) {
			return 0;
		}

		// Mantener user_meta coherente cuando llegan datos explícitos desde flujos UI/CSV/invitación.
		if ( $user_id > 0 ) {
			$profile_meta_map = array(
				'phone'    => array( 'atora_phone', $raw_phone ),
				'whatsapp' => array( 'atora_whatsapp', $raw_whatsapp ),
				'country'  => array( 'atora_country_code', sanitize_text_field( (string) ( $data['country'] ?? '' ) ) ),
				'city'     => array( 'atora_city', sanitize_text_field( (string) ( $data['city'] ?? '' ) ) ),
				'telegram' => array( 'atora_telegram', sanitize_text_field( (string) ( $data['telegram'] ?? '' ) ) ),
				'state'    => array( 'atora_state', $state ),
				'sex'      => array( 'atora_sex', $sex ),
				'age'      => array( 'atora_age', $age > 0 ? (string) $age : '' ),
			);
			foreach ( $profile_meta_map as $data_key => $meta_pair ) {
				if ( ! array_key_exists( $data_key, $data ) ) {
					continue;
				}
				$meta_key = (string) $meta_pair[0];
				$value    = (string) $meta_pair[1];
				// PT-4.3 (6.5.1): atora_phone pasa por
				// Preferences::update_phone() — invalida la verificación
				// si el número cambió de verdad, en vez de un
				// update_user_meta() directo que dejaba "verificado" un
				// número que el CRM acababa de sobreescribir.
				if ( 'atora_phone' === $meta_key && class_exists( '\\ATORA\\Messaging\\Preferences' ) ) {
					\ATORA\Messaging\Preferences::update_phone( $user_id, $value );
					continue;
				}
				update_user_meta( $user_id, $meta_key, $value );
			}
		}

		// Buscar contacto existente.
		$existing_id = 0;
		if ( $user_id ) {
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}atora_contacts WHERE user_id = %d LIMIT 1",
				$user_id
			) );
		}

		if ( ! $existing_id && $email ) {
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}atora_contacts WHERE email = %s LIMIT 1",
				$email
			) );
		}

		if ( ! $existing_id && '' !== $phone ) {
			$existing_id = $this->find_contact_id_by_phone( $phone );
			if ( ! $existing_id && $this->last_phone_contact_match_ambiguous ) {
				return 0;
			}
		}

		if ( ! $existing_id && '' !== $whatsapp ) {
			$existing_id = $this->find_contact_id_by_phone( $whatsapp );
			if ( ! $existing_id && $this->last_phone_contact_match_ambiguous ) {
				return 0;
			}
		}

		$record = array(
			'user_id'    => $user_id ?: null,
			'email'      => $email,
			'name'       => sanitize_text_field( $data['name'] ?? '' ),
			'phone'      => $phone,
			'whatsapp'   => $whatsapp,
			'country'    => sanitize_text_field( $data['country'] ?? get_user_meta( $user_id, 'atora_country_code', true ) ),
			'city'       => sanitize_text_field( $data['city'] ?? get_user_meta( $user_id, 'atora_city', true ) ),
			'source'     => sanitize_key( $data['source'] ?? 'manual' ),
			'status'     => sanitize_key( $data['status'] ?? 'lead' ),
			'updated_at' => current_time( 'mysql', true ),
		);

		if ( $existing_id ) {
			// Actualizar solo campos no vacíos.
			$update = array_filter( $record );
			$wpdb->update(
				"{$wpdb->prefix}atora_contacts",
				$update,
				array( 'id' => $existing_id )
			);
			return $existing_id;
		}

		$record['created_at'] = current_time( 'mysql', true );
		$wpdb->insert( "{$wpdb->prefix}atora_contacts", $record );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Añade un tag a un contacto.
	 *
	 * @param int    $user_id  ID del usuario.
	 * @param string $tag_name Nombre del tag.
	 * @return void
	 */
	public function add_tag( int $user_id, string $tag_name ): void {
		global $wpdb;
		$tags_table = "{$wpdb->prefix}atora_contact_tags";
		if ( ! $this->table_exists( $tags_table ) ) {
			return;
		}

		$contact = $this->get_contact( $user_id );
		if ( ! $contact ) {
			return;
		}

		$tag_name = sanitize_text_field( $tag_name );
		if ( '' === $tag_name ) {
			return;
		}

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tags_table} WHERE contact_id = %d AND tag_name = %s LIMIT 1",
			$contact->id,
			$tag_name
		) );

		if ( ! $exists ) {
			$inserted = $wpdb->insert(
				$tags_table,
				array( 'contact_id' => $contact->id, 'tag_name' => $tag_name ),
				array( '%d', '%s' )
			);

			if ( $inserted ) {
				$this->log_activity( $user_id, 'tag_added', array( 'tag' => $tag_name ) );
				do_action( 'atora/crm/tag_added', $user_id, $tag_name, (int) $contact->id );
			}
		}
	}

	/**
	 * Elimina un tag de un contacto.
	 *
	 * @param int    $user_id  ID del usuario.
	 * @param string $tag_name Nombre del tag.
	 * @return void
	 */
	public function remove_tag( int $user_id, string $tag_name ): void {
		global $wpdb;
		$tags_table = "{$wpdb->prefix}atora_contact_tags";
		if ( ! $this->table_exists( $tags_table ) ) {
			return;
		}

		$contact = $this->get_contact( $user_id );
		if ( ! $contact ) {
			return;
		}

		$tag_name = sanitize_text_field( $tag_name );
		if ( '' === $tag_name ) {
			return;
		}

		$deleted = $wpdb->delete(
			$tags_table,
			array( 'contact_id' => $contact->id, 'tag_name' => $tag_name ),
			array( '%d', '%s' )
		);

		if ( $deleted ) {
			$this->log_activity( $user_id, 'tag_removed', array( 'tag' => $tag_name ) );
			do_action( 'atora/crm/tag_removed', $user_id, $tag_name, (int) $contact->id );
		}
	}

	// ── Auto-tags (port CRM v1) ────────────────────────────────────────────

	/**
	 * Aplica y actualiza tags automáticos a todos los contactos.
	 *
	 * @return void
	 */
	public function process_auto_tags(): void {
		global $wpdb;

		$contacts_table = "{$wpdb->prefix}atora_contacts";
		if ( ! $this->table_exists( $contacts_table ) ) {
			return;
		}

		$contacts = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT id, user_id FROM {$contacts_table} WHERE user_id > 0 LIMIT 500"
		);

		foreach ( $contacts as $contact ) {
			$this->apply_auto_tags( (int) ( $contact->user_id ?? 0 ), (int) ( $contact->id ?? 0 ) );
		}
	}

	/**
	 * Aplica auto-tags a un contacto específico.
	 *
	 * @param int $user_id    Usuario.
	 * @param int $contact_id Contacto CRM.
	 * @return void
	 */
	private function apply_auto_tags( int $user_id, int $contact_id ): void {
		global $wpdb;

		$user_id    = absint( $user_id );
		$contact_id = absint( $contact_id );
		if ( $user_id <= 0 || $contact_id <= 0 ) {
			return;
		}

		$email_table = "{$wpdb->prefix}atora_email_queue";
		if ( $this->table_exists( $email_table ) ) {
			$last_open = $wpdb->get_var( $wpdb->prepare(
				"SELECT MAX(opened_at) FROM {$email_table} WHERE user_id = %d",
				$user_id
			) );

			$days_since_open = $last_open
				? (int) floor( ( time() - strtotime( (string) $last_open ) ) / DAY_IN_SECONDS )
				: 999;

			if ( $days_since_open >= 30 ) {
				$this->add_tag( $user_id, '#inactive-30d' );
			} else {
				$this->remove_tag( $user_id, '#inactive-30d' );
			}
		}

		$score_table = "{$wpdb->prefix}atora_user_engagement";
		if ( ! $this->table_exists( $score_table ) ) {
			return;
		}

		$score_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT engagement_score FROM {$score_table} WHERE user_id = %d LIMIT 1",
			$user_id
		) );
		if ( ! $score_row ) {
			return;
		}

		$score = (int) ( $score_row->engagement_score ?? 0 );
		if ( $score >= 80 ) {
			$this->add_tag( $user_id, '#high-engagement' );
		} else {
			$this->remove_tag( $user_id, '#high-engagement' );
		}

		if ( $score < 40 ) {
			$this->add_tag( $user_id, '#potential-churn' );
		} else {
			$this->remove_tag( $user_id, '#potential-churn' );
		}
	}

	// ── Flags manuales (port CRM v1) ────────────────────────────────────────

	/**
	 * Marca un correo de la cola como abierto, preservando `opened_at` existente.
	 *
	 * @param int $queue_id ID en `atora_email_queue`.
	 * @return bool
	 */
	public function mark_email_opened( int $queue_id ): bool {
		$queue_id = absint( $queue_id );
		if ( $queue_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$table = "{$wpdb->prefix}atora_email_queue";
		if ( ! $this->table_exists( $table ) ) {
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
	public function mark_message_read( int $queue_id ): bool {
		$queue_id = absint( $queue_id );
		if ( $queue_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$table = "{$wpdb->prefix}atora_message_queue";
		if ( ! $this->table_exists( $table ) ) {
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
	public function sync_message_queue_to_conversation( int $queue_id ): void {
		$queue_id = absint( $queue_id );
		if ( $queue_id <= 0 ) {
			return;
		}

		global $wpdb;
		$queue_table   = "{$wpdb->prefix}atora_message_queue";
		$conv_table    = "{$wpdb->prefix}atora_conversations";
		$message_table = "{$wpdb->prefix}atora_conversation_messages";
		if ( ! $this->table_exists( $queue_table ) || ! $this->table_exists( $conv_table ) || ! $this->table_exists( $message_table ) ) {
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

		$channel             = sanitize_key( (string) ( $row['channel'] ?? 'email' ) );
		$status              = sanitize_key( (string) ( $row['status'] ?? 'pending' ) );
		$template            = sanitize_key( (string) ( $row['template_key'] ?? '' ) );
		$created_at          = sanitize_text_field( (string) ( $row['sent_at'] ?: $row['scheduled_at'] ?: current_time( 'mysql', true ) ) );
		$recipient           = sanitize_text_field( (string) ( $row['recipient_phone'] ?? '' ) );
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
	public function sync_email_queue_to_conversation( int $queue_id ): void {
		$queue_id = absint( $queue_id );
		if ( $queue_id <= 0 ) {
			return;
		}

		global $wpdb;
		$queue_table   = "{$wpdb->prefix}atora_email_queue";
		$conv_table    = "{$wpdb->prefix}atora_conversations";
		$message_table = "{$wpdb->prefix}atora_conversation_messages";
		if ( ! $this->table_exists( $queue_table ) || ! $this->table_exists( $conv_table ) || ! $this->table_exists( $message_table ) ) {
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

		$contact_id = $this->resolve_contact_id_for_email_queue( $user_id, $recipient_email, $recipient_name, $metadata );
		$identity   = sanitize_key( (string) ( $row['identity_key'] ?? $metadata['email_identity'] ?? '' ) );
		$status     = sanitize_key( (string) ( $row['status'] ?? 'pending' ) );
		$created_at = sanitize_text_field( (string) ( $row['sent_at'] ?: $row['scheduled_at'] ?: current_time( 'mysql', true ) ) );
		$subject    = sanitize_text_field( (string) ( $row['subject'] ?? '' ) );
		$conversation_status = in_array( $status, array( 'failed', 'pending' ), true ) ? 'pending' : 'open';
		$message_status      = in_array( $status, array( 'sent', 'delivered', 'read', 'failed', 'pending', 'sending' ), true ) ? $status : 'pending';
		$opened_at           = sanitize_text_field( (string) ( $row['opened_at'] ?? '' ) );
		$clicked_at          = sanitize_text_field( (string) ( $row['clicked_at'] ?? '' ) );
		if ( '' !== $opened_at || '' !== $clicked_at ) {
			$message_status = 'read';
		} elseif ( 'bounced' === $status ) {
			$message_status = 'failed';
		}
		$course_id = absint( $metadata['course_id'] ?? 0 );
		$order_id  = absint( $metadata['order_id'] ?? 0 );

		$conversation_id = $this->upsert_conversation(
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

	// ── Inbound WhatsApp/Telegram → conversación (port CRM v1) ─────────────

	/**
	 * Registra mensajes inbound de WhatsApp en la conversación unificada.
	 *
	 * @param array<string,mixed> $message Mensaje recibido.
	 * @param array<string,mixed> $context Contexto del webhook.
	 * @return void
	 */
	public function on_whatsapp_message_received( array $message, array $context = array() ): void {
		global $wpdb;

		$conv_table    = "{$wpdb->prefix}atora_conversations";
		$message_table = "{$wpdb->prefix}atora_conversation_messages";
		if ( ! $this->table_exists( $conv_table ) || ! $this->table_exists( $message_table ) ) {
			return;
		}

		$from_raw = sanitize_text_field( (string) ( $message['from'] ?? '' ) );
		$from     = $this->normalize_contact_phone( $from_raw );
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
		$user_id     = $this->find_user_id_by_phone( $from );
		if ( $user_id <= 0 && $this->last_phone_user_match_ambiguous ) {
			$this->log_inbound_resolution_issue(
				'inbound_phone_collision_skip',
				array(
					'channel' => 'whatsapp',
					'phone'   => $from,
					'reason'  => 'multiple_users',
				)
			);
			return;
		}

		$user = $user_id > 0 ? get_userdata( $user_id ) : null;
		if ( '' === $sender_name && $user instanceof \WP_User ) {
			$sender_name = sanitize_text_field( (string) $user->display_name );
		}

		$contact_email = $user instanceof \WP_User
			? sanitize_email( (string) $user->user_email )
			: $this->build_shadow_contact_email( 'whatsapp', $from );

		$contact_id = $this->upsert_contact(
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
			$contact_id = $this->find_contact_id_by_phone( $from );
		}
		if ( $contact_id <= 0 ) {
			return;
		}

		$conversation_id = $this->upsert_conversation(
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
			$this->log_activity(
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
	 * @param string              $chat_id Chat ID de Telegram.
	 * @param string              $text    Texto normalizado.
	 * @param array<string,mixed> $message Payload del mensaje.
	 * @return void
	 */
	public function on_telegram_message_received( string $chat_id, string $text, array $message = array() ): void {
		global $wpdb;

		$conv_table    = "{$wpdb->prefix}atora_conversations";
		$message_table = "{$wpdb->prefix}atora_conversation_messages";
		if ( ! $this->table_exists( $conv_table ) || ! $this->table_exists( $message_table ) ) {
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

		$user_id = $this->find_user_id_by_telegram_chat( $chat_id );
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
			: $this->build_shadow_contact_email( 'telegram', $chat_id );

		$contact_id = $this->upsert_contact(
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

		$conversation_id = $this->upsert_conversation(
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
			$this->log_activity(
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
	 * Resuelve o crea contacto CRM para un registro de cola de email.
	 *
	 * @param int                 $user_id         Usuario relacionado.
	 * @param string              $recipient_email Email destino.
	 * @param string              $recipient_name  Nombre destino.
	 * @param array<string,mixed> $metadata        Metadata del envío.
	 * @return int
	 */
	private function resolve_contact_id_for_email_queue( int $user_id, string $recipient_email, string $recipient_name, array $metadata = array() ): int {
		global $wpdb;

		$contacts_table = "{$wpdb->prefix}atora_contacts";
		if ( ! $this->table_exists( $contacts_table ) ) {
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
				$contact_id       = absint( $contact_row['id'] ?? 0 );
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
				$contact_id = $this->upsert_contact(
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
				$local_part     = strstr( $recipient_email, '@', true );
				$fallback_name  = sanitize_text_field( false !== $local_part ? (string) $local_part : $recipient_email );
			}

			$contact_id = $this->upsert_contact(
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

	/**
	 * Crea o actualiza una conversación para canal/contacto.
	 *
	 * @param array<string,mixed> $args Datos de conversación.
	 * @return int
	 */
	private function upsert_conversation( array $args ): int {
		global $wpdb;

		$conv_table = "{$wpdb->prefix}atora_conversations";
		if ( ! $this->table_exists( $conv_table ) ) {
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
	private function find_user_id_by_phone( string $phone ): int {
		global $wpdb;
		$this->last_phone_user_match_ambiguous = false;

		$phone = $this->normalize_contact_phone( $phone );
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
			$candidate_phone  = $this->normalize_contact_phone( (string) ( $row['meta_value'] ?? '' ) );
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
			$this->last_phone_user_match_ambiguous = true;
			$this->log_inbound_resolution_issue(
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
	private function find_user_id_by_telegram_chat( string $chat_id ): int {
		$chat_id = (string) preg_replace( '/[^0-9\-]/', '', $chat_id );
		if ( '' === $chat_id ) {
			return 0;
		}
		if ( ! class_exists( '\\ATORA\\Messaging\\Telegram_Bot' ) ) {
			return 0;
		}
		return (int) ( \ATORA\Messaging\Telegram_Bot::get_user_by_chat( $chat_id ) ?? 0 );
	}

	/**
	 * Construye email placeholder estable para leads sin email explícito.
	 *
	 * @param string $channel    Canal origen.
	 * @param string $identifier Identificador externo.
	 * @return string
	 */
	private function build_shadow_contact_email( string $channel, string $identifier ): string {
		$channel    = sanitize_key( $channel );
		$identifier = sanitize_text_field( $identifier );
		$hash       = substr( md5( $channel . ':' . $identifier ), 0, 20 );
		return $channel . '-' . $hash . '@atora.local';
	}

	/**
	 * Busca contacto por teléfono/whatsapp.
	 *
	 * @param string $phone Teléfono normalizado.
	 * @return int
	 */
	private function find_contact_id_by_phone( string $phone ): int {
		global $wpdb;
		$this->last_phone_contact_match_ambiguous = false;

		$contacts_table = "{$wpdb->prefix}atora_contacts";
		if ( ! $this->table_exists( $contacts_table ) ) {
			return 0;
		}

		$phone  = $this->normalize_contact_phone( $phone );
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

			$phone_digits    = (string) preg_replace( '/\D+/', '', $this->normalize_contact_phone( (string) ( $row['phone'] ?? '' ) ) );
			$whatsapp_digits = (string) preg_replace( '/\D+/', '', $this->normalize_contact_phone( (string) ( $row['whatsapp'] ?? '' ) ) );

			if ( ( '' !== $phone_digits && hash_equals( $digits, $phone_digits ) ) || ( '' !== $whatsapp_digits && hash_equals( $digits, $whatsapp_digits ) ) ) {
				$matches[ $contact_id ] = true;
			}
		}

		$match_ids = array_map( 'absint', array_keys( $matches ) );
		if ( 1 === count( $match_ids ) ) {
			return (int) $match_ids[0];
		}

		if ( count( $match_ids ) > 1 ) {
			$this->last_phone_contact_match_ambiguous = true;
			$this->log_inbound_resolution_issue(
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
	private function normalize_contact_phone( string $phone ): string {
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
	 * Registra incidencias de resolución inbound para trazabilidad operativa.
	 *
	 * @param string $event_type Tipo de incidencia.
	 * @param array  $event_data Datos asociados.
	 * @return void
	 */
	private function log_inbound_resolution_issue( string $event_type, array $event_data = array() ): void {
		global $wpdb;

		$table = "{$wpdb->prefix}atora_message_log";
		if ( ! $this->table_exists( $table ) ) {
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

	/**
	 * @param int    $user_id       Usuario.
	 * @param string $activity_type Tipo de actividad.
	 * @param array  $data          Datos adicionales.
	 * @return void
	 */
	public function log_activity( int $user_id, string $activity_type, array $data = array() ): void {
		global $wpdb;
		$activities_table = "{$wpdb->prefix}atora_contact_activities";

		if ( ! $this->table_exists( $activities_table ) ) {
			return;
		}

		$contact = $this->get_contact( $user_id );
		if ( ! $contact ) {
			$contact = $this->ensure_contact_for_user( $user_id );
		}

		if ( ! $contact ) {
			return;
		}

		$activity_type = sanitize_key( $activity_type );
		$inserted      = $wpdb->insert(
			$activities_table,
			array(
				'contact_id'    => $contact->id,
				'activity_type' => $activity_type,
				'activity_data' => wp_json_encode( $data ),
				'created_by'    => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%d' )
		);

		if ( $inserted ) {
			do_action( 'atora/crm/activity_logged', (int) $contact->id, $activity_type, $data, $user_id );
		}
	}

	/**
	 * @param int $contact_id Contacto CRM.
	 * @param int $limit      Máximo de resultados.
	 * @return array
	 */
	public function get_timeline( int $contact_id, int $limit = 50 ): array {
		global $wpdb;
		$activities_table = "{$wpdb->prefix}atora_contact_activities";

		$contact_id = absint( $contact_id );
		$limit      = max( 1, absint( $limit ) );
		if ( $contact_id <= 0 || ! $this->table_exists( $activities_table ) ) {
			return array();
		}

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$activities_table}
			 WHERE contact_id = %d
			 ORDER BY created_at DESC
			 LIMIT %d",
			$contact_id,
			$limit
		) );
	}

	/**
	 * PT-2 (6.11.0): conveniencia para la ficha de estudiante -- resuelve
	 * user_id -> contact_id -> timeline en un solo llamado.
	 *
	 * @param int $user_id Usuario/estudiante.
	 * @param int $limit   Máximo de resultados.
	 * @return array
	 */
	public function get_timeline_for_student( int $user_id, int $limit = 50 ): array {
		$contact = $this->get_contact( absint( $user_id ) );
		if ( ! $contact ) {
			return array();
		}

		return $this->get_timeline( (int) $contact->id, $limit );
	}

	/**
	 * PT-3 (6.11.0): cuenta actividades recientes de un tipo dado entre un
	 * conjunto de usuarios -- usado por
	 * CLMS_Today_Aggregator_Service::collect_coordinator_items() para
	 * contar alertas 'academic_at_risk_alert' de los estudiantes de las
	 * secciones que coordina, sin loopear get_student_course_status() por
	 * estudiante (que sí sería costoso -- ver el plan de este sprint).
	 * Una sola consulta con JOIN, no N+1.
	 *
	 * @param array<int> $user_ids      Usuarios a considerar.
	 * @param string     $activity_type Tipo de actividad.
	 * @param int        $since_days    Ventana en días hacia atrás.
	 * @return int
	 */
	public function count_recent_activity_for_users( array $user_ids, string $activity_type, int $since_days = 7 ): int {
		global $wpdb;
		$contacts_table    = "{$wpdb->prefix}atora_contacts";
		$activities_table  = "{$wpdb->prefix}atora_contact_activities";

		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
		if ( empty( $user_ids ) || ! $this->table_exists( $contacts_table ) || ! $this->table_exists( $activities_table ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$since        = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $since_days ) ) * DAY_IN_SECONDS ) );

		$args = array_merge( array( sanitize_key( $activity_type ) ), $user_ids, array( $since ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$activities_table} a
				 INNER JOIN {$contacts_table} c ON c.id = a.contact_id
				 WHERE a.activity_type = %s AND c.user_id IN ({$placeholders}) AND a.created_at >= %s",
				$args
			)
		);
	}

	/**
	 * PT-2 (6.11.0): registra una nota manual (coordinador/docente) en el
	 * timeline del estudiante, con tipo 'academic_note' para poder
	 * distinguirla del resto del historial comercial en la ficha.
	 *
	 * @param int    $student_id Estudiante.
	 * @param int    $course_id  Curso en el que se registra la nota.
	 * @param string $note       Texto de la nota.
	 * @param int    $author_id  Autor de la nota.
	 * @return void
	 */
	public function log_academic_note( int $student_id, int $course_id, string $note, int $author_id ): void {
		$note = trim( wp_kses_post( $note ) );
		if ( '' === $note ) {
			return;
		}

		$this->log_activity(
			absint( $student_id ),
			'academic_note',
			array(
				'course_id' => absint( $course_id ),
				'note'      => $note,
				'author_id' => absint( $author_id ),
			)
		);
	}

	/**
	 * Versión mínima de creación de contacto, usada solo por log_activity()
	 * cuando el usuario todavía no tiene un contacto CRM -- a diferencia de
	 * CRM::upsert_contact() (v1), no resuelve por teléfono/WhatsApp ni
	 * sincroniza user_meta; solo garantiza que exista una fila para poder
	 * asociarle la actividad.
	 *
	 * @param int $user_id Usuario.
	 * @return object|null
	 */
	protected function ensure_contact_for_user( int $user_id ): ?object {
		global $wpdb;
		$contacts_table = "{$wpdb->prefix}atora_contacts";

		$user_id = absint( $user_id );
		if ( ! $user_id || ! $this->table_exists( $contacts_table ) ) {
			return null;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		$wpdb->insert(
			$contacts_table,
			array(
				'user_id'    => $user_id,
				'email'      => sanitize_email( $user->user_email ),
				'name'       => sanitize_text_field( $user->display_name ),
				'source'     => 'manual',
				'status'     => 'student',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $this->get_contact( $user_id );
	}

	/**
	 * @param string $table Nombre completo de tabla.
	 * @return bool
	 */
	protected function table_exists( string $table ): bool {
		global $wpdb;

		$like   = $wpdb->esc_like( $table );
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return $exists === $table;
	}
}
