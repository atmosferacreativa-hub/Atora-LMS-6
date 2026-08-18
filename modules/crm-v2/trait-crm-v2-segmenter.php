<?php
/**
 * CRM_V2 Segmenter Trait — Fase V S16 (extraído de class-crm-v2.php)
 * Métodos de segmentación, filtros y consultas de contactos.
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2;

if ( ! defined( 'ABSPATH' ) ) { exit; }

trait CRM_V2_Segmenter_Trait {

	protected static function get_segment_options(): array {
		return array(
			'todos'       => __( 'Todos los contactos', 'atora-lms' ),
			'leads'       => __( 'Leads y prospectos', 'atora-lms' ),
			'estudiantes' => __( 'Estudiantes activos', 'atora-lms' ),
			'inactivos'   => __( 'Riesgo / inactivos', 'atora-lms' ),
		);
	}

	/**
	 * Canales disponibles.
	 *
	 * @return array<string,string>
	 */
	protected static function get_channel_options(): array {
		return array(
			'email'    => __( 'Email', 'atora-lms' ),
			'whatsapp' => __( 'WhatsApp', 'atora-lms' ),
			'telegram' => __( 'Telegram', 'atora-lms' ),
			'hybrid'   => __( 'Híbrido (email + mensajería)', 'atora-lms' ),
		);
	}

	/**
	 * Modos de entrega.
	 *
	 * @return array<string,string>
	 */
	protected static function get_delivery_options(): array {
		return array(
			'now'      => __( 'Ahora', 'atora-lms' ),
			'schedule' => __( 'Programado', 'atora-lms' ),
		);
	}

	/**
	 * Modos de ejecución de campaña.
	 *
	 * @return array<string,string>
	 */
	protected static function get_execution_options(): array {
		return array(
			'simulate' => __( 'Simular (recomendado)', 'atora-lms' ),
			'queue'    => __( 'Encolar', 'atora-lms' ),
		);
	}

	/**
	 * Estados de contacto válidos.
	 *
	 * @return array<string,string>
	 */
	protected static function get_status_options(): array {
		return array(
			'lead'     => __( 'Lead', 'atora-lms' ),
			'prospect' => __( 'Prospecto', 'atora-lms' ),
			'student'  => __( 'Estudiante', 'atora-lms' ),
			'alumni'   => __( 'Egresado', 'atora-lms' ),
			'blocked'  => __( 'Archivado', 'atora-lms' ),
		);
	}

	/**
	 * Acciones masivas disponibles.
	 *
	 * @return array<string,string>
	 */
	protected static function get_bulk_action_options(): array {
		return array(
			'none'       => __( 'Sin acción masiva', 'atora-lms' ),
			'add_tag'    => __( 'Agregar etiqueta', 'atora-lms' ),
			'remove_tag' => __( 'Quitar etiqueta', 'atora-lms' ),
			'set_status' => __( 'Cambiar estado', 'atora-lms' ),
			'cleanup'    => __( 'Depurar contactos', 'atora-lms' ),
		);
	}

	/**
	 * Construye resultado de segmentación.
	 *
	 * @param array $draft Borrador.
	 * @return array<string,mixed>
	 */
	public static function build_segment_result( array $draft ): array {
		$contacts = self::query_contacts( $draft, self::MAX_CONTACTS_PREVIEW, 0 );
		$total    = self::count_contacts( $draft );

		$stats = array(
			'total'           => $total,
			'email_ready'     => 0,
			'messaging_ready' => 0,
			'linked_users'    => 0,
		);

		$stats_sample_limit = min( self::MAX_CONTACTS_ACTION, max( self::MAX_CONTACTS_PREVIEW, $total ) );
		$target_rows        = self::query_contacts( $draft, $stats_sample_limit, 0 );
		foreach ( $target_rows as $row ) {
			if ( ! empty( $row['user_id'] ) ) {
				$stats['linked_users']++;
			}
			if ( self::contact_can_receive_email( $row, ! empty( $draft['respect_email_prefs'] ) ) ) {
				$stats['email_ready']++;
			}
			if ( self::contact_can_receive_message( $row ) ) {
				$stats['messaging_ready']++;
			}
		}

		return array(
			'total'    => $total,
			'stats'    => $stats,
			'contacts' => $contacts,
		);
	}

	/**
	 * Ejecuta una acción masiva sobre el segmento activo.
	 *
	 * @param array $draft Configuración actual.
	 * @return array<string,mixed>
	 */
	protected static function get_course_options(): array {
		$courses = get_posts(
			array(
				'post_type'              => 'lm_course',
				'post_status'            => array( 'publish', 'private', 'draft' ),
				'posts_per_page'         => 200,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$options = array();
		foreach ( (array) $courses as $course_id ) {
			$course_id = absint( $course_id );
			if ( ! $course_id ) {
				continue;
			}
			$options[ $course_id ] = get_the_title( $course_id );
		}

		return $options;
	}

	/**
	 * Obtiene lista de etiquetas existentes.
	 *
	 * @return array<int,string>
	 */
	protected static function get_tag_options(): array {
		global $wpdb;

		$tags_table = "{$wpdb->prefix}atora_contact_tags";
		if ( ! self::table_exists( $tags_table ) ) {
			return array();
		}

		$rows = (array) $wpdb->get_col( "SELECT DISTINCT tag_name FROM {$tags_table} WHERE tag_name <> '' ORDER BY tag_name ASC LIMIT 200" );
		$rows = array_values( array_filter( array_map( 'sanitize_text_field', $rows ) ) );

		return $rows;
	}

	/**
	 * Consulta contactos por filtro.
	 *
	 * @param array $draft  Filtro.
	 * @param int   $limit  Límite.
	 * @param int   $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function query_contacts( array $draft, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$contacts_table = "{$wpdb->prefix}atora_contacts";
		if ( ! self::table_exists( $contacts_table ) ) {
			return array();
		}

		$limit  = max( 1, min( self::MAX_CONTACTS_ACTION, absint( $limit ) ) );
		$offset = max( 0, absint( $offset ) );

		$where_data = self::build_contacts_where_clause( $draft );
		$where      = (string) ( $where_data['sql'] ?? '1=1' );
		$params     = (array) ( $where_data['params'] ?? array() );

		$sql = "SELECT c.id, c.user_id, c.email, c.name, c.phone, c.whatsapp, c.source, c.status, c.updated_at
			FROM {$contacts_table} c
			WHERE {$where}
			ORDER BY c.updated_at DESC
			LIMIT %d OFFSET %d";

		$params[] = $limit;
		$params[] = $offset;

		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( empty( $rows ) ) {
			return array();
		}

		$contact_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'id' ) ) ) );
		$tags_map    = self::get_tags_map_for_contact_ids( $contact_ids );

		foreach ( $rows as &$row ) {
			$contact_id = absint( $row['id'] ?? 0 );
			$row['id']       = $contact_id;
			$row['user_id']  = absint( $row['user_id'] ?? 0 );
			$row['tags']     = $tags_map[ $contact_id ] ?? array();
			$row['channels'] = array(
				'email'    => self::contact_can_receive_email( $row, true ),
				'whatsapp' => self::contact_can_receive_whatsapp( $row ),
				'telegram' => self::contact_can_receive_telegram( $row ),
			);
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Cuenta contactos según filtro.
	 *
	 * @param array $draft Filtro.
	 * @return int
	 */
	protected static function count_contacts( array $draft ): int {
		global $wpdb;

		$contacts_table = "{$wpdb->prefix}atora_contacts";
		if ( ! self::table_exists( $contacts_table ) ) {
			return 0;
		}

		$where_data = self::build_contacts_where_clause( $draft );
		$where      = (string) ( $where_data['sql'] ?? '1=1' );
		$params     = (array) ( $where_data['params'] ?? array() );

		$sql = "SELECT COUNT(*) FROM {$contacts_table} c WHERE {$where}";
		if ( empty( $params ) ) {
			return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Construye WHERE SQL según filtros CRM v2.
	 *
	 * @param array $draft Borrador.
	 * @return array{sql:string,params:array<int,mixed>}
	 */
	private static function build_contacts_where_clause( array $draft ): array {
		global $wpdb;

		$clauses = array( '1=1' );
		$params  = array();

		$audience = sanitize_key( (string) ( $draft['audience'] ?? 'todos' ) );
		switch ( $audience ) {
			case 'leads':
				$clauses[] = "c.status IN ('lead','prospect')";
				break;
			case 'estudiantes':
				$clauses[] = "c.status = 'student'";
				break;
			case 'inactivos':
				$clauses[] = "(c.status = 'alumni' OR EXISTS (SELECT 1 FROM {$wpdb->prefix}atora_contact_tags t1 WHERE t1.contact_id = c.id AND t1.tag_name = '#inactive-30d'))";
				break;
		}

		$status_filter = sanitize_key( (string) ( $draft['status_filter'] ?? '' ) );
		if ( '' !== $status_filter && array_key_exists( $status_filter, self::get_status_options() ) ) {
			$clauses[] = 'c.status = %s';
			$params[]  = $status_filter;
		}

		$search = sanitize_text_field( (string) ( $draft['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = '(c.name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s OR c.whatsapp LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
		}

		$tags = self::parse_tags_csv( (string) ( $draft['segment_tags'] ?? '' ) );
		if ( ! empty( $tags ) ) {
			$tag_placeholders = implode( ',', array_fill( 0, count( $tags ), '%s' ) );
			$clauses[]        = "EXISTS (
				SELECT 1 FROM {$wpdb->prefix}atora_contact_tags t2
				WHERE t2.contact_id = c.id
				  AND t2.tag_name IN ({$tag_placeholders})
			)";
			foreach ( $tags as $tag ) {
				$params[] = $tag;
			}
		}

		$course_id = absint( $draft['course_id'] ?? 0 );
		if ( $course_id > 0 ) {
			$user_ids = self::get_course_user_ids_for_filter( $course_id );
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

		$scope_user_ids = self::get_scope_user_ids();
		if ( ! empty( $scope_user_ids ) ) {
			$in = implode( ',', array_fill( 0, count( $scope_user_ids ), '%d' ) );
			$clauses[] = "c.user_id IN ({$in})";
			foreach ( $scope_user_ids as $scope_user_id ) {
				$params[] = absint( $scope_user_id );
			}
		}

		return array(
			'sql'    => implode( ' AND ', $clauses ),
			'params' => $params,
		);
	}

	/**
	 * IDs de usuarios visibles por scope (vacío => sin restricción).
	 *
	 * @return array<int,int>
	 */
	private static function get_scope_user_ids(): array {
		$current_user_id = get_current_user_id();
		if ( ! $current_user_id ) {
			return array( -1 );
		}

		if ( class_exists( '\ATORA\\CRM\\CRM' ) && method_exists( '\ATORA\\CRM\\CRM', 'can_manage_crm' ) && \ATORA\CRM\CRM::can_manage_crm( $current_user_id ) ) {
			return array();
		}

		if ( class_exists( '\ATORA\\CRM\\CRM' ) && method_exists( '\ATORA\\CRM\\CRM', 'get_accessible_contact_user_ids' ) ) {
			return array_values(
				array_filter(
					array_map( 'absint', (array) \ATORA\CRM\CRM::get_accessible_contact_user_ids( $current_user_id ) )
				)
			);
		}

		return array();
	}

	/**
	 * Usuarios inscritos en curso para filtro CRM.
	 *
	 * @param int $course_id Curso.
	 * @return array<int,int>
	 */
	private static function get_course_user_ids_for_filter( int $course_id ): array {
		if ( ! class_exists( '\CLMS_Helper' ) || ! method_exists( '\CLMS_Helper', 'get_enrolled_student_ids' ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'absint', (array) \CLMS_Helper::get_enrolled_student_ids( $course_id ) )
			)
		);
	}

	/**
	 * Retorna mapa contact_id => tags.
	 *
	 * @param array<int,int> $contact_ids IDs.
	 * @return array<int,array<int,string>>
	 */
	private static function get_tags_map_for_contact_ids( array $contact_ids ): array {
		global $wpdb;

		$contact_ids = array_values( array_filter( array_map( 'absint', $contact_ids ) ) );
		if ( empty( $contact_ids ) ) {
			return array();
		}

		$in  = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$sql = "SELECT contact_id, tag_name FROM {$wpdb->prefix}atora_contact_tags WHERE contact_id IN ({$in}) ORDER BY tag_name ASC";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$contact_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$map = array();
		foreach ( $rows as $row ) {
			$contact_id = absint( $row['contact_id'] ?? 0 );
			$tag_name   = sanitize_text_field( (string) ( $row['tag_name'] ?? '' ) );
			if ( ! $contact_id || '' === $tag_name ) {
				continue;
			}
			if ( ! isset( $map[ $contact_id ] ) ) {
				$map[ $contact_id ] = array();
			}
			$map[ $contact_id ][] = $tag_name;
		}

		return $map;
	}

	/**
	 * Guarda un segmento reutilizable.
	 *
	 * @param array  $draft Draft.
	 * @param string $name  Nombre.
	 * @return bool
	 */
	public static function save_named_segment( array $draft, string $name ): bool {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return false;
		}

		$segments = self::get_saved_segments();
		$base_id  = sanitize_title( $name );
		if ( '' === $base_id ) {
			$base_id = 'segmento';
		}

		$segment_id = $base_id;
		$counter    = 2;
		while ( isset( $segments[ $segment_id ] ) ) {
			$segment_id = $base_id . '-' . $counter;
			$counter++;
		}

		$segments[ $segment_id ] = array(
			'id'         => $segment_id,
			'name'       => $name,
			'filters'    => self::extract_segment_filters( $draft ),
			'created_at' => current_time( 'mysql' ),
		);

		update_option( self::SEGMENTS_OPTION, $segments, false );
		return true;
	}

	/**
	 * Carga filtros de segmento guardado.
	 *
	 * @param string $segment_id ID.
	 * @return array<string,mixed>
	 */
	public static function load_segment_filters( string $segment_id ): array {
		$segment_id = sanitize_key( $segment_id );
		$segments   = self::get_saved_segments();
		if ( '' === $segment_id || empty( $segments[ $segment_id ]['filters'] ) ) {
			return array();
		}

		$filters = (array) $segments[ $segment_id ]['filters'];
		return self::sanitize_draft( array_merge( self::get_default_draft(), $filters ) );
	}

	/**
	 * Elimina segmento guardado.
	 *
	 * @param string $segment_id Segmento.
	 * @return bool
	 */
	public static function delete_named_segment( string $segment_id ): bool {
		$segment_id = sanitize_key( $segment_id );
		if ( '' === $segment_id ) {
			return false;
		}

		$segments = self::get_saved_segments();
		if ( ! isset( $segments[ $segment_id ] ) ) {
			return false;
		}

		unset( $segments[ $segment_id ] );
		update_option( self::SEGMENTS_OPTION, $segments, false );
		return true;
	}

	/**
	 * Segmentos guardados.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_saved_segments(): array {
		$stored = get_option( self::SEGMENTS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$segments = array();
		foreach ( $stored as $segment_id => $payload ) {
			$segment_id = sanitize_key( (string) $segment_id );
			$payload    = is_array( $payload ) ? $payload : array();
			if ( '' === $segment_id ) {
				continue;
			}

			$segments[ $segment_id ] = array(
				'id'         => $segment_id,
				'name'       => sanitize_text_field( (string) ( $payload['name'] ?? $segment_id ) ),
				'filters'    => self::extract_segment_filters( (array) ( $payload['filters'] ?? array() ) ),
				'created_at' => sanitize_text_field( (string) ( $payload['created_at'] ?? '' ) ),
			);
		}

		return $segments;
	}

	/**
	 * Retorna solo campos de filtros segmentables.
	 *
	 * @param array $data Datos.
	 * @return array<string,mixed>
	 */
	private static function extract_segment_filters( array $data ): array {
		$allowed = array(
			'audience',
			'status_filter',
			'course_id',
			'segment_tags',
			'search',
			'channel',
			'respect_email_prefs',
		);

		$filters = array();
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$filters[ $field ] = $data[ $field ];
			}
		}

		return self::sanitize_draft( array_merge( self::get_default_draft(), $filters ) );
	}

	/**
	 * Historial de campañas.
	 *
	 * @return array<int,array<string,mixed>>
	 */
}
