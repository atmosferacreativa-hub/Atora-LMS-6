<?php
/**
 * Servicio de contactos CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Contact_Service {
	/**
	 * Lista contactos con filtros.
	 *
	 * @param array $args Filtros.
	 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
	 */
	public static function list_contacts( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'search'   => '',
			'status'   => '',
			'tag'      => '',
			'course_id'=> 0,
			'page'     => 1,
			'per_page' => 20,
			'limit'    => 0,
			'offset'   => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$contacts_table = $wpdb->prefix . 'atora_contacts';
		if ( ! DB_Service::table_exists( $contacts_table ) ) {
			return array(
				'items'    => array(),
				'total'    => 0,
				'page'     => 1,
				'per_page' => absint( $args['per_page'] ),
			);
		}

		$page     = max( 1, absint( $args['page'] ) );
		$per_page = max( 1, min( 200, absint( $args['per_page'] ) ) );
		$limit    = absint( $args['limit'] );
		$offset   = absint( $args['offset'] );
		if ( $limit <= 0 ) {
			$limit  = $per_page;
			$offset = ( $page - 1 ) * $per_page;
		}

		$where_data = self::build_where_clause( $args );
		$where      = $where_data['sql'];
		$params     = $where_data['params'];

		$count_sql = "SELECT COUNT(*) FROM {$contacts_table} c WHERE {$where}";
		$total     = (int) ( empty( $params )
			? $wpdb->get_var( $count_sql ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			: $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$list_sql = "SELECT c.* FROM {$contacts_table} c WHERE {$where} ORDER BY c.updated_at DESC LIMIT %d OFFSET %d";
		$list_params   = $params;
		$list_params[] = $limit;
		$list_params[] = $offset;
		$rows          = (array) $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$contact_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'id' ) ) ) );
		$tags_map    = self::get_tags_map( $contact_ids );

		foreach ( $rows as &$row ) {
			$contact_id    = absint( $row['id'] ?? 0 );
			$row['id']     = $contact_id;
			$row['user_id']= absint( $row['user_id'] ?? 0 );
			$row['tags']   = $tags_map[ $contact_id ] ?? array();
		}
		unset( $row );

		return array(
			'items'    => $rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Cuenta contactos por filtro.
	 *
	 * @param array $args Filtros.
	 * @return int
	 */
	public static function count_contacts( array $args = array() ): int {
		$result = self::list_contacts(
			array_merge(
				$args,
				array(
					'limit'  => 1,
					'offset' => 0,
				)
			)
		);

		return absint( $result['total'] ?? 0 );
	}

	/**
	 * Crea un lead rápido y enlaza un deal base.
	 *
	 * @param array $data Datos.
	 * @return int
	 */
	public static function create_lead_quick( array $data ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_contacts';
		if ( ! DB_Service::table_exists( $table ) ) {
			return 0;
		}

		$name   = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		$email  = sanitize_email( (string) ( $data['email'] ?? '' ) );
		$course = sanitize_text_field( (string) ( $data['interest'] ?? '' ) );
		$source = sanitize_key( (string) ( $data['source'] ?? 'crm_v2_hub' ) ) ?: 'crm_v2_hub';
		if ( '' === $name && '' === $email ) {
			return 0;
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'name'       => $name ?: __( 'Lead nuevo', 'atora-lms' ),
				'email'      => $email,
				'status'     => 'lead',
				'source'     => $source,
				'updated_at' => current_time( 'mysql', true ),
			)
		);
		if ( ! $inserted ) {
			return 0;
		}

		$contact_id = absint( $wpdb->insert_id );
		if ( class_exists( '\\ATORA\\CRM_V2\\Services\\Deal_Service' ) ) {
			Deal_Service::ensure_deal_for_contact(
				$contact_id,
				array(
					'title' => $name ?: __( 'Lead nuevo', 'atora-lms' ),
				)
			);
		}

		// Emitir hook para Automation Engine (Fase 7)
		if ( $contact_id ) {
			do_action( 'atora/crm/contact_created', $contact_id, 0 );
		}

		return $contact_id;
	}

	/**
	 * Busca contactos para autocompletado.
	 *
	 * @param string $term  Término.
	 * @param int    $limit Límite.
	 * @return array<int,array<string,mixed>>
	 */
	public static function search_contacts( string $term, int $limit = 12 ): array {
		$term  = sanitize_text_field( $term );
		$limit = max( 1, min( 30, absint( $limit ) ) );
		if ( '' === $term ) {
			return array();
		}

		$result = self::list_contacts(
			array(
				'search' => $term,
				'limit'  => $limit,
			)
		);

		return (array) ( $result['items'] ?? array() );
	}

	/**
	 * Obtiene ficha 360 de un contacto.
	 *
	 * @param int $contact_id ID.
	 * @return array<string,mixed>
	 */
	public static function get_contact_360( int $contact_id ): array {
		global $wpdb;

		$contact_id = absint( $contact_id );
		if ( ! $contact_id ) {
			return array();
		}

		$table   = $wpdb->prefix . 'atora_contacts';
		$contact = (array) $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $contact_id ),
			ARRAY_A
		);
		if ( empty( $contact ) ) {
			return array();
		}

		$user_id = absint( $contact['user_id'] ?? 0 );
		$tags    = self::get_tags_map( array( $contact_id ) );
		$notes   = self::get_notes( $contact_id );
		$timeline = array();
		if ( class_exists( '\\ATORA\\CRM\\CRM' ) && method_exists( '\\ATORA\\CRM\\CRM', 'get_timeline' ) ) {
			$timeline = (array) \ATORA\CRM\CRM::get_timeline( $contact_id, 120 );
		}

		$courses = self::get_course_snapshots( $user_id );
		$deals   = class_exists( '\\ATORA\\CRM_V2\\Services\\Deal_Service' )
			? Deal_Service::get_contact_deals( $contact_id, 5 )
			: array();
		$tasks   = class_exists( '\\ATORA\\CRM_V2\\Services\\Task_Service' )
			? Task_Service::list_tasks(
				array(
					'contact_id' => $contact_id,
					'limit'      => 8,
				)
			)
			: array( 'items' => array() );

		$recommended_action = __( 'Registrar siguiente seguimiento comercial.', 'atora-lms' );
		$status             = sanitize_key( (string) ( $contact['status'] ?? '' ) );
		if ( 'student' === $status && ! empty( $courses ) ) {
			$recommended_action = __( 'Revisar avance académico y crear tutoría si hay riesgo.', 'atora-lms' );
		} elseif ( 'lead' === $status || 'prospect' === $status ) {
			$recommended_action = __( 'Programar contacto con oferta y fecha de cierre.', 'atora-lms' );
		}

		return array(
			'contact'             => $contact,
			'tags'                => $tags[ $contact_id ] ?? array(),
			'notes'               => $notes,
			'timeline'            => $timeline,
			'courses'             => $courses,
			'deals'               => $deals,
			'tasks'               => (array) ( $tasks['items'] ?? array() ),
			'custom_fields'       => self::get_custom_fields(),
			'custom_values'       => self::get_contact_custom_values( $contact_id ),
			'recommended_action'  => $recommended_action,
			'contact_id'          => $contact_id,
			'user_id'             => $user_id,
		);
	}

	/**
	 * Detecta grupos de contactos duplicados.
	 *
	 * @param int $limit Límite.
	 * @return array<int,array<string,mixed>>
	 */
	public static function find_duplicates( int $limit = 50 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_contacts';
		if ( ! DB_Service::table_exists( $table ) ) {
			return array();
		}

		$limit = max( 1, min( 200, absint( $limit ) ) );
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT LOWER(TRIM(email)) AS email_key, COUNT(*) AS total, GROUP_CONCAT(id ORDER BY created_at ASC) AS ids
				 FROM {$table}
				 WHERE email <> ''
				 GROUP BY email_key
				 HAVING total > 1
				 ORDER BY total DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$duplicates = array();
		foreach ( $rows as $row ) {
			$ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) ( $row['ids'] ?? '' ) ) ) ) );
			if ( count( $ids ) < 2 ) {
				continue;
			}

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$contacts     = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE id IN ({$placeholders}) ORDER BY created_at ASC",
					...$ids
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( empty( $contacts ) ) {
				continue;
			}

			$duplicates[] = array(
				'email_key' => sanitize_email( (string) ( $row['email_key'] ?? '' ) ),
				'count'     => count( $contacts ),
				'contacts'  => $contacts,
				'canonical' => $contacts[0],
			);
		}

		return $duplicates;
	}

	/**
	 * Fusiona contactos duplicados.
	 *
	 * @param int   $keep_id    Contacto canónico.
	 * @param array $remove_ids IDs a eliminar.
	 * @return array<string,mixed>
	 */
	public static function merge_contacts( int $keep_id, array $remove_ids ): array {
		global $wpdb;

		$keep_id    = absint( $keep_id );
		$remove_ids = array_values( array_filter( array_map( 'absint', $remove_ids ) ) );
		if ( ! $keep_id || empty( $remove_ids ) ) {
			return array(
				'success' => false,
				'message' => __( 'IDs inválidos para la fusión.', 'atora-lms' ),
			);
		}

		$contacts_table     = $wpdb->prefix . 'atora_contacts';
		$tags_table         = $wpdb->prefix . 'atora_contact_tags';
		$notes_table        = $wpdb->prefix . 'atora_contact_notes';
		$activities_table   = $wpdb->prefix . 'atora_contact_activities';
		$deals_table        = $wpdb->prefix . 'atora_crm_deals';
		$followups_table    = $wpdb->prefix . 'atora_crm_student_followups';
		$tasks_table        = $wpdb->prefix . 'atora_crm_tasks';
		$field_values_table = $wpdb->prefix . 'atora_crm_contact_field_values';

		$merged = 0;
		foreach ( $remove_ids as $old_id ) {
			if ( $old_id === $keep_id ) {
				continue;
			}

			if ( DB_Service::table_exists( $tags_table ) ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE IGNORE {$tags_table} SET contact_id = %d WHERE contact_id = %d",
						$keep_id,
						$old_id
					)
				); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->delete( $tags_table, array( 'contact_id' => $old_id ), array( '%d' ) );
			}

			foreach ( array( $notes_table, $activities_table, $deals_table, $followups_table, $tasks_table, $field_values_table ) as $rel_table ) {
				if ( DB_Service::table_exists( $rel_table ) ) {
					$wpdb->update( $rel_table, array( 'contact_id' => $keep_id ), array( 'contact_id' => $old_id ), array( '%d' ), array( '%d' ) );
				}
			}

			$old = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$contacts_table} WHERE id = %d", $old_id ), ARRAY_A );
			if ( ! empty( $old ) ) {
				$updates = array();
				foreach ( array( 'phone', 'whatsapp', 'name', 'user_id' ) as $field ) {
					$current = $wpdb->get_var( $wpdb->prepare( "SELECT {$field} FROM {$contacts_table} WHERE id = %d", $keep_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( empty( $current ) && ! empty( $old[ $field ] ) ) {
						$updates[ $field ] = 'user_id' === $field ? absint( $old[ $field ] ) : sanitize_text_field( (string) $old[ $field ] );
					}
				}
				if ( ! empty( $updates ) ) {
					$wpdb->update( $contacts_table, $updates, array( 'id' => $keep_id ) );
				}
			}

			$wpdb->delete( $contacts_table, array( 'id' => $old_id ), array( '%d' ) );
			$merged++;
		}

		if ( $merged > 0 ) {
			Activity_Service::log_contact_activity(
				$keep_id,
				'contacts_merged',
				array( 'merged_count' => $merged )
			);
		}

		return array(
			'success' => true,
			'merged'  => $merged,
			'message' => sprintf( __( 'Se fusionaron %d contacto(s).', 'atora-lms' ), $merged ),
		);
	}

	/**
	 * Lista campos personalizados.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_custom_fields(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_crm_contact_fields';
		if ( ! DB_Service::table_exists( $table ) ) {
			return array();
		}

		return (array) $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY sort_order ASC, label ASC",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Obtiene valores custom por contacto.
	 *
	 * @param int $contact_id Contacto.
	 * @return array<string,string>
	 */
	public static function get_contact_custom_values( int $contact_id ): array {
		global $wpdb;

		$contact_id = absint( $contact_id );
		$table      = $wpdb->prefix . 'atora_crm_contact_field_values';
		if ( ! $contact_id || ! DB_Service::table_exists( $table ) ) {
			return array();
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT field_key, value FROM {$table} WHERE contact_id = %d", $contact_id ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$values = array();
		foreach ( $rows as $row ) {
			$values[ sanitize_key( (string) ( $row['field_key'] ?? '' ) ) ] = (string) ( $row['value'] ?? '' );
		}

		return $values;
	}

	/**
	 * Guarda valor custom.
	 *
	 * @param int    $contact_id Contacto.
	 * @param string $field_key  Campo.
	 * @param string $value      Valor.
	 * @return bool
	 */
	public static function save_contact_custom_value( int $contact_id, string $field_key, string $value ): bool {
		global $wpdb;

		$contact_id = absint( $contact_id );
		$field_key  = sanitize_key( $field_key );
		$value      = sanitize_textarea_field( $value );
		$table      = $wpdb->prefix . 'atora_crm_contact_field_values';
		if ( ! $contact_id || '' === $field_key || ! DB_Service::table_exists( $table ) ) {
			return false;
		}

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE contact_id = %d AND field_key = %s LIMIT 1",
				$contact_id,
				$field_key
			)
		);

		if ( $exists > 0 ) {
			return false !== $wpdb->update(
				$table,
				array( 'value' => $value ),
				array( 'id' => $exists ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return false !== $wpdb->insert(
			$table,
			array(
				'contact_id' => $contact_id,
				'field_key'  => $field_key,
				'value'      => $value,
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Guarda definición de campo custom.
	 *
	 * @param array $data Datos.
	 * @return bool
	 */
	public static function save_custom_field_definition( array $data ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_crm_contact_fields';
		if ( ! DB_Service::table_exists( $table ) ) {
			return false;
		}

		$field_key = sanitize_key( (string) ( $data['field_key'] ?? '' ) );
		$label     = sanitize_text_field( (string) ( $data['label'] ?? '' ) );
		$field_type = sanitize_key( (string) ( $data['field_type'] ?? 'text' ) );
		if ( '' === $field_key || '' === $label ) {
			return false;
		}

		$payload = array(
			'field_key'    => $field_key,
			'label'        => $label,
			'field_type'   => $field_type,
			'options'      => sanitize_textarea_field( (string) ( $data['options'] ?? '' ) ),
			'is_required'  => ! empty( $data['is_required'] ) ? 1 : 0,
			'show_in_360'  => ! empty( $data['show_in_360'] ) ? 1 : 0,
			'sort_order'   => absint( $data['sort_order'] ?? 0 ),
		);

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE field_key = %s LIMIT 1", $field_key )
		);
		if ( $exists > 0 ) {
			return false !== $wpdb->update( $table, $payload, array( 'id' => $exists ) );
		}

		return false !== $wpdb->insert( $table, $payload );
	}

	/**
	 * Guarda una nota interna.
	 *
	 * @param int    $contact_id Contacto.
	 * @param string $note       Nota.
	 * @param int    $author_id  Autor.
	 * @return bool
	 */
	public static function save_note( int $contact_id, string $note, int $author_id = 0 ): bool {
		global $wpdb;

		$contact_id = absint( $contact_id );
		$author_id  = absint( $author_id ?: get_current_user_id() );
		$note       = sanitize_textarea_field( $note );
		if ( ! $contact_id || '' === $note ) {
			return false;
		}

		$table = $wpdb->prefix . 'atora_contact_notes';
		if ( ! DB_Service::table_exists( $table ) ) {
			return false;
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'contact_id' => $contact_id,
				'user_id'    => $author_id,
				'note_text'  => $note,
				'is_pinned'  => 0,
			),
			array( '%d', '%d', '%s', '%d' )
		);

		if ( $inserted ) {
			Activity_Service::log_contact_activity(
				$contact_id,
				'internal_note',
				array( 'note' => wp_trim_words( $note, 18 ) ),
				$author_id
			);
		}

		return (bool) $inserted;
	}

	/**
	 * Devuelve notas internas recientes.
	 *
	 * @param int $contact_id Contacto.
	 * @param int $limit      Límite.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_notes( int $contact_id, int $limit = 25 ): array {
		global $wpdb;

		$contact_id = absint( $contact_id );
		$limit      = max( 1, min( 200, absint( $limit ) ) );
		if ( ! $contact_id ) {
			return array();
		}

		$table = $wpdb->prefix . 'atora_contact_notes';
		if ( ! DB_Service::table_exists( $table ) ) {
			return array();
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE contact_id = %d ORDER BY created_at DESC LIMIT %d",
				$contact_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Determina alcance visible por rol.
	 *
	 * @return array<int,int> Vacío para alcance global.
	 */
	public static function get_scope_user_ids(): array {
		$current_user_id = get_current_user_id();
		if ( ! $current_user_id ) {
			return array( -1 );
		}

		if ( self::can_manage() ) {
			return array();
		}

		if ( class_exists( '\\ATORA\\CRM\\CRM' ) && method_exists( '\\ATORA\\CRM\\CRM', 'get_accessible_contact_user_ids' ) ) {
			$ids = (array) \ATORA\CRM\CRM::get_accessible_contact_user_ids( $current_user_id );
			$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
			return ! empty( $ids ) ? $ids : array( -1 );
		}

		return array( -1 );
	}

	/**
	 * Usuario con control completo CRM.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		$current_user_id = get_current_user_id();
		if ( $current_user_id <= 0 ) {
			return false;
		}

		if ( class_exists( '\\ATORA\\CRM\\CRM' ) && method_exists( '\\ATORA\\CRM\\CRM', 'has_global_contact_scope' ) ) {
			return (bool) \ATORA\CRM\CRM::has_global_contact_scope( $current_user_id );
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Construye WHERE SQL de contactos.
	 *
	 * @param array $args Filtros.
	 * @return array{sql:string,params:array<int,mixed>}
	 */
	private static function build_where_clause( array $args ): array {
		global $wpdb;

		$clauses = array( '1=1' );
		$params  = array();

		$scope_user_ids = self::get_scope_user_ids();
		if ( ! empty( $scope_user_ids ) ) {
			$in = implode( ',', array_fill( 0, count( $scope_user_ids ), '%d' ) );
			$clauses[] = "c.user_id IN ({$in})";
			foreach ( $scope_user_ids as $uid ) {
				$params[] = absint( $uid );
			}
		}

		$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
		if ( '' !== $status ) {
			$clauses[] = 'c.status = %s';
			$params[]  = $status;
		}

		$search = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = '(c.name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s OR c.whatsapp LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
		}

		$tag = sanitize_text_field( (string) ( $args['tag'] ?? '' ) );
		if ( '' !== $tag ) {
			$clauses[] = "EXISTS (SELECT 1 FROM {$wpdb->prefix}atora_contact_tags ct WHERE ct.contact_id = c.id AND ct.tag_name = %s)";
			$params[]  = $tag;
		}

		$course_id = absint( $args['course_id'] ?? 0 );
		if ( $course_id > 0 ) {
			$user_ids = self::get_course_user_ids( $course_id );
			if ( empty( $user_ids ) ) {
				$clauses[] = '1=0';
			} else {
				$in = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
				$clauses[] = "c.user_id IN ({$in})";
				foreach ( $user_ids as $user_id ) {
					$params[] = absint( $user_id );
				}
			}
		}

		// ── Fase II S4: filtro por conversion_score ───────────────────────────
		$score_filters = (array) ( $args['score_filters'] ?? array() );
		foreach ( $score_filters as $sf ) {
			$field    = sanitize_key( (string) ( $sf['field'] ?? '' ) );
			$operator = sanitize_key( (string) ( $sf['operator'] ?? '' ) );
			$value    = absint( $sf['value'] ?? 0 );

			$allowed_fields = array( 'conversion_score', 'engagement_score', 'total_points' );
			if ( ! in_array( $field, $allowed_fields, true ) ) { continue; }

			if ( 'greater_than' === $operator ) {
				$clauses[] = "c.{$field} > %d";
				$params[]  = $value;
			} elseif ( 'less_than' === $operator ) {
				$clauses[] = "c.{$field} < %d";
				$params[]  = $value;
			} elseif ( 'equals' === $operator ) {
				$clauses[] = "c.{$field} = %d";
				$params[]  = $value;
			}
		}

		return array(
			'sql'    => implode( ' AND ', $clauses ),
			'params' => $params,
		);
	}

	/**
	 * Mapa de tags por contacto.
	 *
	 * @param array<int,int> $contact_ids IDs.
	 * @return array<int,array<int,string>>
	 */
	private static function get_tags_map( array $contact_ids ): array {
		global $wpdb;

		$contact_ids = array_values( array_filter( array_map( 'absint', $contact_ids ) ) );
		if ( empty( $contact_ids ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'atora_contact_tags';
		if ( ! DB_Service::table_exists( $table ) ) {
			return array();
		}

		$in   = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$sql  = "SELECT contact_id, tag_name FROM {$table} WHERE contact_id IN ({$in}) ORDER BY tag_name ASC";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$contact_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$map = array();
		foreach ( $rows as $row ) {
			$cid = absint( $row['contact_id'] ?? 0 );
			$tag = sanitize_text_field( (string) ( $row['tag_name'] ?? '' ) );
			if ( ! $cid || '' === $tag ) {
				continue;
			}
			if ( ! isset( $map[ $cid ] ) ) {
				$map[ $cid ] = array();
			}
			$map[ $cid ][] = $tag;
		}

		return $map;
	}

	/**
	 * Resumen académico por cursos inscritos.
	 *
	 * @param int $user_id Usuario.
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_course_snapshots( int $user_id ): array {
		$user_id = absint( $user_id );
		if ( ! $user_id || ! class_exists( 'CLMS_Helper' ) || ! method_exists( 'CLMS_Helper', 'get_user_enrolled_courses' ) ) {
			return array();
		}

		$course_ids = array_values( array_filter( array_map( 'absint', (array) ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $user_id ) ) ) ) );
		if ( empty( $course_ids ) ) {
			return array();
		}

		$academic_service = class_exists( 'CLMS_Academic_Status_Service' ) ? new \CLMS_Academic_Status_Service() : null;
		$snapshots        = array();

		foreach ( array_slice( $course_ids, 0, 6 ) as $course_id ) {
			$status = array();
			if ( $academic_service && method_exists( $academic_service, 'get_student_course_status' ) ) {
				$status = (array) $academic_service->get_student_course_status( $user_id, $course_id, array( 'skip_improvement_plan' => true ) );
			}

			$snapshots[] = array(
				'course_id'           => $course_id,
				'course_title'        => get_the_title( $course_id ),
				'progress_percent'    => absint( $status['progress_percent'] ?? get_user_meta( $user_id, 'clms_course_' . $course_id . '_progress', true ) ),
				'pending_activities'  => absint( $status['pending_activities'] ?? 0 ),
				'risk_level'          => sanitize_key( (string) ( $status['risk_level'] ?? 'normal' ) ),
				'last_access_at'      => sanitize_text_field( (string) ( $status['last_access_at'] ?? get_user_meta( $user_id, '_clms_last_access_course_' . $course_id, true ) ) ),
				'certificate_status'  => sanitize_key( (string) ( $status['certificate_status'] ?? 'pending' ) ),
				'course_url'          => get_permalink( $course_id ),
			);
		}

		return $snapshots;
	}

	/**
	 * Usuarios inscritos por curso.
	 *
	 * @param int $course_id Curso.
	 * @return array<int,int>
	 */
	private static function get_course_user_ids( int $course_id ): array {
		if ( ! class_exists( 'CLMS_Helper' ) || ! method_exists( 'CLMS_Helper', 'get_enrolled_student_ids' ) ) {
			return array();
		}

		$ids = (array) \CLMS_Helper::get_enrolled_student_ids( absint( $course_id ) );
		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Añade una etiqueta a un contacto. No duplica si ya existe.
	 *
	 * @param int    $contact_id ID del contacto.
	 * @param string $tag_name   Nombre de la etiqueta (se sanea a minúsculas con guión).
	 * @return bool
	 */
	public static function add_tag( int $contact_id, string $tag_name ): bool {
		global $wpdb;

		$contact_id = absint( $contact_id );
		$tag_name   = sanitize_text_field( strtolower( trim( $tag_name ) ) );
		$table      = $wpdb->prefix . 'atora_contact_tags';

		if ( ! $contact_id || '' === $tag_name || ! DB_Service::table_exists( $table ) ) {
			return false;
		}

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE contact_id = %d AND tag_name = %s LIMIT 1",
				$contact_id,
				$tag_name
			)
		);

		if ( $exists > 0 ) {
			return true;
		}

		$inserted = $wpdb->insert(
			$table,
			array( 'contact_id' => $contact_id, 'tag_name' => $tag_name ),
			array( '%d', '%s' )
		);

		if ( $inserted ) {
			Activity_Service::log_contact_activity(
				$contact_id,
				'tag_added',
				array( 'tag' => $tag_name ),
				0,
				'tag',
				0
			);
		}

		return (bool) $inserted;
	}

	/**
	 * Devuelve sugerencias de tags existentes (para autocomplete).
	 *
	 * @param string $search  Prefijo de búsqueda.
	 * @param int    $limit   Límite de resultados.
	 * @return array<int,string>
	 */
	public static function suggest_tags( string $search = '', int $limit = 20 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_contact_tags';
		if ( ! DB_Service::table_exists( $table ) ) {
			return array();
		}

		$limit = max( 1, min( 50, absint( $limit ) ) );

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%';
			$rows = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT tag_name FROM {$table} WHERE tag_name LIKE %s ORDER BY tag_name ASC LIMIT %d",
					$like,
					$limit
				)
			);
		} else {
			$rows = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT tag_name FROM {$table} ORDER BY tag_name ASC LIMIT %d",
					$limit
				)
			);
		}

		return array_values( array_map( 'sanitize_text_field', array_filter( $rows ) ) );
	}

	/**
	 * Actualiza last_activity_at de un contacto al momento actual.
	 *
	 * @param int $contact_id ID del contacto.
	 * @return void
	 */
	public static function touch_last_activity( int $contact_id ): void {
		global $wpdb;

		$contact_id = absint( $contact_id );
		if ( ! $contact_id ) {
			return;
		}

		$table = $wpdb->prefix . 'atora_contacts';
		if ( ! DB_Service::table_exists( $table ) ) {
			return;
		}

		$col_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE()
				   AND TABLE_NAME   = %s
				   AND COLUMN_NAME  = 'last_activity_at'",
				$table
			)
		);

		if ( 0 === $col_exists ) {
			return;
		}

		$wpdb->update(
			$table,
			array( 'last_activity_at' => current_time( 'mysql', true ) ),
			array( 'id' => $contact_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
	// ── Fase 9: empresa, LTV y puntos ────────────────────────────────────────

	public static function assign_company( int $contact_id, int $company_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_contacts';
		$col_exists = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME='company_id'", $table )
		);
		if ( ! $col_exists ) { return false; }
		return false !== $wpdb->update( $table, array( 'company_id' => $company_id ), array( 'id' => $contact_id ), array( '%d' ), array( '%d' ) );
	}

	public static function update_ltv( int $contact_id, float $amount, bool $increment = true ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_contacts';
		$col_exists = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME='life_time_value'", $table )
		);
		if ( ! $col_exists ) { return false; }
		if ( $increment ) {
			return false !== $wpdb->query(
				$wpdb->prepare( "UPDATE {$table} SET life_time_value = life_time_value + %f WHERE id = %d", $amount, $contact_id )
			);
		}
		return false !== $wpdb->update( $table, array( 'life_time_value' => max( 0.0, $amount ) ), array( 'id' => $contact_id ), array( '%f' ), array( '%d' ) );
	}

	public static function add_points( int $contact_id, int $points ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_contacts';
		$col_exists = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME='total_points'", $table )
		);
		if ( ! $col_exists ) { return false; }
		if ( $points >= 0 ) {
			return false !== $wpdb->query(
				$wpdb->prepare( "UPDATE {$table} SET total_points = total_points + %d WHERE id = %d", absint( $points ), $contact_id )
			);
		}
		return false !== $wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET total_points = GREATEST(0, total_points - %d) WHERE id = %d", absint( abs( $points ) ), $contact_id )
		);
	}

}
