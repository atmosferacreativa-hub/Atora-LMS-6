<?php

namespace ATORA\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CRM_Contacts_Trait {
	// ── Contactos ─────────────────────────────────────────────────────────────

	/**
	 * Crea o actualiza un contacto en la tabla CRM.
	 *
	 * @param array $data Datos del contacto.
	 * @return int ID del contacto.
	 */
	public static function upsert_contact( array $data ): int {
		global $wpdb;

		$user_id      = absint( $data['user_id'] ?? 0 );
		$email        = sanitize_email( $data['email'] ?? '' );
		$raw_phone    = sanitize_text_field( (string) ( $data['phone'] ?? get_user_meta( $user_id, 'atora_phone', true ) ) );
		$raw_whatsapp = sanitize_text_field( (string) ( $data['whatsapp'] ?? '' ) );
		$phone        = self::normalize_contact_phone( $raw_phone );
		$whatsapp     = self::normalize_contact_phone( $raw_whatsapp );
		$state   = sanitize_text_field( (string) ( $data['state'] ?? get_user_meta( $user_id, 'atora_state', true ) ) );
		$sex     = sanitize_key( (string) ( $data['sex'] ?? get_user_meta( $user_id, 'atora_sex', true ) ) );
		$age     = absint( $data['age'] ?? get_user_meta( $user_id, 'atora_age', true ) );
		$allowed_sexes = array( 'femenino', 'masculino', 'no_binario', 'prefiero_no_decir' );
		if ( '' !== $sex && ! in_array( $sex, $allowed_sexes, true ) ) {
			$sex = '';
		}
		if ( $age > 120 ) {
			$age = 120;
		}

		if ( ! $user_id && ! $email ) { return 0; }

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
				if ( 'atora_phone' === $meta_key && class_exists( '\ATORA\Messaging\Preferences' ) ) {
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
			$existing_id = self::find_contact_id_by_phone( $phone );
			if ( ! $existing_id && self::$last_phone_contact_match_ambiguous ) {
				return 0;
			}
		}

		if ( ! $existing_id && '' !== $whatsapp ) {
			$existing_id = self::find_contact_id_by_phone( $whatsapp );
			if ( ! $existing_id && self::$last_phone_contact_match_ambiguous ) {
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
	 * Obtiene el contacto CRM de un usuario.
	 *
	 * @param int $user_id ID del usuario.
	 * @return object|null
	 */
	public static function get_contact( int $user_id ): ?object {
		// PT-1 (6.11.0): implementación real movida a
		// CLMS_Contacts_Core_Service (includes/contacts-core/) -- ver su
		// docblock. Delegado para no romper los call sites existentes.
		return clms_core( 'CLMS_Contacts_Core_Service' )->get_contact( $user_id );
	}

	// ── Timeline de actividad ────────────────────────────────────────────────

	/**
	 * Registra una actividad en el timeline del contacto.
	 *
	 * @param int    $user_id       ID del usuario.
	 * @param string $activity_type Tipo de actividad.
	 * @param array  $data          Datos adicionales.
	 * @return void
	 */
	public static function log_activity( int $user_id, string $activity_type, array $data = array() ): void {
		// PT-1 (6.11.0): ver get_contact() arriba. Nota: el servicio
		// neutro usa una creación de contacto más simple que
		// upsert_contact() (sin matching por teléfono) cuando el usuario
		// todavía no tiene contacto CRM -- ver
		// CLMS_Contacts_Core_Service::ensure_contact_for_user().
		clms_core( 'CLMS_Contacts_Core_Service' )->log_activity( $user_id, $activity_type, $data );
	}

	/**
	 * Obtiene el timeline de actividades de un contacto.
	 *
	 * @param int $contact_id ID del contacto CRM.
	 * @param int $limit      Máximo de resultados.
	 * @return array
	 */
	public static function get_timeline( int $contact_id, int $limit = 50 ): array {
		// PT-1 (6.11.0): ver get_contact() arriba.
		return clms_core( 'CLMS_Contacts_Core_Service' )->get_timeline( $contact_id, $limit );
	}

	// ── Tagging ───────────────────────────────────────────────────────────────

	/**
	 * Añade un tag a un contacto.
	 *
	 * @param int    $user_id  ID del usuario.
	 * @param string $tag_name Nombre del tag.
	 * @return void
	 */
	public static function add_tag( int $user_id, string $tag_name ): void {
		global $wpdb;
		$tags_table = "{$wpdb->prefix}atora_contact_tags";
		if ( ! self::table_exists( $tags_table ) ) {
			return;
		}

		$contact = self::get_contact( $user_id );
		if ( ! $contact ) { return; }

		$tag_name = sanitize_text_field( $tag_name );
		if ( '' === $tag_name ) {
			return;
		}

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tags_table} WHERE contact_id = %d AND tag_name = %s LIMIT 1",
			$contact->id, $tag_name
		) );

		if ( ! $exists ) {
			$inserted = $wpdb->insert(
				$tags_table,
				array( 'contact_id' => $contact->id, 'tag_name' => $tag_name ),
				array( '%d', '%s' )
			);

			if ( $inserted ) {
				self::log_activity( $user_id, 'tag_added', array( 'tag' => $tag_name ) );
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
	public static function remove_tag( int $user_id, string $tag_name ): void {
		global $wpdb;
		$tags_table = "{$wpdb->prefix}atora_contact_tags";
		if ( ! self::table_exists( $tags_table ) ) {
			return;
		}

		$contact = self::get_contact( $user_id );
		if ( ! $contact ) { return; }

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
			self::log_activity( $user_id, 'tag_removed', array( 'tag' => $tag_name ) );
			do_action( 'atora/crm/tag_removed', $user_id, $tag_name, (int) $contact->id );
		}
	}

	/**
	 * Obtiene los tags de un contacto.
	 *
	 * @param int $contact_id ID del contacto CRM.
	 * @return array
	 */
	public static function get_tags( int $contact_id ): array {
		global $wpdb;
		$tags_table = "{$wpdb->prefix}atora_contact_tags";

		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 || ! self::table_exists( $tags_table ) ) {
			return array();
		}

		return (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT tag_name FROM {$tags_table} WHERE contact_id = %d ORDER BY tag_name ASC",
			$contact_id
		) );
	}

	/**
	 * Obtiene IDs de usuario asociados a un tag, respetando scope CRM del usuario actual.
	 *
	 * @param string $tag_name        Nombre del tag.
	 * @param int    $current_user_id Usuario que consulta (0 usa sesión actual).
	 * @return array<int,int>
	 */
	public static function get_contact_user_ids_by_tag( string $tag_name, int $current_user_id = 0 ): array {
		$tag_name = sanitize_text_field( $tag_name );
		if ( '' === $tag_name ) {
			return array();
		}

		$current_user_id = absint( $current_user_id ?: get_current_user_id() );
		global $wpdb;

		$contacts_table = "{$wpdb->prefix}atora_contacts";
		$tags_table     = "{$wpdb->prefix}atora_contact_tags";
		if ( ! self::table_exists( $contacts_table ) || ! self::table_exists( $tags_table ) ) {
			return array();
		}

		$where  = 'ct.tag_name = %s AND c.user_id > 0';
		$params = array( $tag_name );

		if ( $current_user_id > 0 && ! self::can_manage_crm( $current_user_id ) ) {
			if ( ! self::can_access_crm( $current_user_id ) ) {
				return array();
			}
			$allowed_user_ids = self::get_accessible_contact_user_ids( $current_user_id );
			if ( empty( $allowed_user_ids ) ) {
				return array();
			}
			$in_clause = implode( ',', array_fill( 0, count( $allowed_user_ids ), '%d' ) );
			$where    .= " AND c.user_id IN ({$in_clause})";
			foreach ( $allowed_user_ids as $allowed_user_id ) {
				$params[] = absint( $allowed_user_id );
			}
		}

		$sql = "SELECT DISTINCT c.user_id
			FROM {$contacts_table} c
			INNER JOIN {$tags_table} ct ON ct.contact_id = c.id
			WHERE {$where}
			ORDER BY c.user_id ASC
			LIMIT 2000";
		$rows = (array) $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $rows )
				)
			)
		);
	}

	/**
	 * Obtiene IDs de usuario inscritos en un curso, respetando scope CRM del usuario actual.
	 *
	 * @param int $course_id       ID del curso.
	 * @param int $current_user_id Usuario que consulta (0 usa sesión actual).
	 * @return array<int,int>
	 */
	public static function get_contact_user_ids_by_course( int $course_id, int $current_user_id = 0 ): array {
		$course_id = absint( $course_id );
		if ( $course_id <= 0 || 'lm_course' !== get_post_type( $course_id ) ) {
			return array();
		}

		if ( ! class_exists( '\CLMS_Helper' ) || ! method_exists( '\CLMS_Helper', 'get_enrolled_student_ids' ) ) {
			return array();
		}

		$current_user_id = absint( $current_user_id ?: get_current_user_id() );
		$user_ids        = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', (array) \CLMS_Helper::get_enrolled_student_ids( $course_id ) )
				)
			)
		);

		if ( empty( $user_ids ) ) {
			return array();
		}

		if ( $current_user_id > 0 && ! self::can_manage_crm( $current_user_id ) ) {
			if ( ! self::can_access_crm( $current_user_id ) ) {
				return array();
			}

			$allowed_user_ids = array_values(
				array_unique(
					array_filter(
						array_map( 'absint', self::get_accessible_contact_user_ids( $current_user_id ) )
					)
				)
			);
			if ( empty( $allowed_user_ids ) ) {
				return array();
			}

			$user_ids = array_values( array_intersect( $user_ids, $allowed_user_ids ) );
		}

		sort( $user_ids );
		return $user_ids;
	}

	// ── Auto-tags ─────────────────────────────────────────────────────────────

	/**
	 * Aplica y actualiza tags automáticos a todos los contactos.
	 *
	 * @return void
	 */
	public static function process_auto_tags(): void {
		global $wpdb;

		$contacts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT id, user_id FROM {$wpdb->prefix}atora_contacts WHERE user_id > 0 LIMIT 500"
		);

		foreach ( $contacts as $contact ) {
			self::apply_auto_tags( (int) $contact->user_id, (int) $contact->id );
		}
	}

	/**
	 * Aplica auto-tags a un contacto específico.
	 *
	 * @param int $user_id    ID del usuario.
	 * @param int $contact_id ID del contacto CRM.
	 * @return void
	 */
	private static function apply_auto_tags( int $user_id, int $contact_id ): void {
		global $wpdb;

		// #inactive-30d: sin emails abiertos en 30 días.
		$last_open = $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(opened_at) FROM {$wpdb->prefix}atora_email_queue WHERE user_id = %d",
			$user_id
		) );

		$days_since_open = $last_open
			? (int) floor( ( time() - strtotime( $last_open ) ) / DAY_IN_SECONDS )
			: 999;

		if ( $days_since_open >= 30 ) {
			self::add_tag( $user_id, '#inactive-30d' );
		} else {
			self::remove_tag( $user_id, '#inactive-30d' );
		}

		// #high-engagement: score >= 80.
		$score_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT engagement_score FROM {$wpdb->prefix}atora_user_engagement WHERE user_id = %d LIMIT 1",
			$user_id
		) );

		if ( $score_row ) {
			$score = (int) $score_row->engagement_score;
			if ( $score >= 80 ) {
				self::add_tag( $user_id, '#high-engagement' );
			} else {
				self::remove_tag( $user_id, '#high-engagement' );
			}

			if ( $score < 40 ) {
				self::add_tag( $user_id, '#potential-churn' );
			} else {
				self::remove_tag( $user_id, '#potential-churn' );
			}
		}
	}

	/**
	 * Verifica tablas mínimas del módulo CRM.
	 *
	 * @return bool
	 */
	private static function has_required_tables(): bool {
		global $wpdb;

		$required = array(
			"{$wpdb->prefix}atora_contacts",
			"{$wpdb->prefix}atora_contact_tags",
			"{$wpdb->prefix}atora_contact_activities",
			"{$wpdb->prefix}atora_contact_notes",
		);

		foreach ( $required as $table ) {
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
	 * Intenta recuperar tablas CRM faltantes a través del instalador v5.
	 *
	 * @return void
	 */
	private static function maybe_install_required_tables(): void {
		if ( ! class_exists( '\ATORA\V5_Installer' ) ) {
			$installer_file = defined( 'ATORA_LMS_MODULES_DIR' )
				? ATORA_LMS_MODULES_DIR . 'class-v5-installer.php'
				: '';
			if ( $installer_file && file_exists( $installer_file ) ) {
				require_once $installer_file;
			}
		}

		if ( class_exists( '\ATORA\V5_Installer' ) && method_exists( '\ATORA\V5_Installer', 'force_install' ) ) {
			\ATORA\V5_Installer::force_install();
		}
	}

	// ── Notas ─────────────────────────────────────────────────────────────────

	/**
	 * Guarda una nota para un contacto.
	 *
	 * @param int    $contact_id ID del contacto CRM.
	 * @param string $note_text  Texto de la nota.
	 * @param bool   $is_pinned  Si está fijada.
	 * @return int|false
	 */
	public static function save_note( int $contact_id, string $note_text, bool $is_pinned = false ) {
		global $wpdb;
		$notes_table    = "{$wpdb->prefix}atora_contact_notes";
		$contacts_table = "{$wpdb->prefix}atora_contacts";

		$contact_id = absint( $contact_id );
		if ( ! $contact_id || ! self::table_exists( $notes_table ) || ! self::table_exists( $contacts_table ) ) {
			return false;
		}

		$inserted = $wpdb->insert(
			$notes_table,
			array(
				'contact_id' => $contact_id,
				'user_id'    => get_current_user_id(),
				'note_text'  => wp_kses_post( $note_text ),
				'is_pinned'  => $is_pinned ? 1 : 0,
			),
			array( '%d', '%d', '%s', '%d' )
		);

		if ( ! $inserted ) {
			return false;
		}

		$contact_user_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$contacts_table} WHERE id = %d LIMIT 1",
				$contact_id
			)
		);
		if ( $contact_user_id ) {
			self::log_activity(
				$contact_user_id,
				'note_added',
				array(
					'contact_id' => $contact_id,
					'pinned'     => $is_pinned ? 1 : 0,
				)
			);
		}

		return (int) $wpdb->insert_id;
	}

}
