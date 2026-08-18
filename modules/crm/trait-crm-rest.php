<?php

namespace ATORA\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CRM_Rest_Trait {
	// ── REST ──────────────────────────────────────────────────────────────────

	/** @return void */
	public static function register_rest_routes(): void {
		$can_access = static fn() => self::can_access_crm( get_current_user_id() );

		register_rest_route( 'atora/v1', '/crm/contacts', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_list_contacts' ),
			'permission_callback' => $can_access,
			'args'                => array(
				's'        => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'status'   => array( 'sanitize_callback' => 'sanitize_key' ),
				'per_page' => array( 'sanitize_callback' => 'absint' ),
				'page'     => array( 'sanitize_callback' => 'absint' ),
				'offset'   => array( 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( 'atora/v1', '/crm/contacts/(?P<id>\d+)/timeline', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_get_timeline' ),
			'permission_callback' => $can_access,
			'args'                => array(
				'per_page' => array( 'sanitize_callback' => 'absint' ),
				'page'     => array( 'sanitize_callback' => 'absint' ),
				'offset'   => array( 'sanitize_callback' => 'absint' ),
				),
			) );

		register_rest_route( 'atora/v1', '/crm/messages/log', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_list_message_log' ),
			'permission_callback' => $can_access,
			'args'                => array(
				's'          => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'channel'    => array( 'sanitize_callback' => 'sanitize_key' ),
				'status'     => array( 'sanitize_callback' => 'sanitize_key' ),
				'event_type' => array( 'sanitize_callback' => 'sanitize_key' ),
				'user_id'    => array( 'sanitize_callback' => 'absint' ),
				'course_id'  => array( 'sanitize_callback' => 'absint' ),
				'per_page'   => array( 'sanitize_callback' => 'absint' ),
				'page'       => array( 'sanitize_callback' => 'absint' ),
				'offset'     => array( 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( 'atora/v1', '/crm/messages/log/summary', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_message_log_summary' ),
			'permission_callback' => $can_access,
			'args'                => array(
				's'          => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'channel'    => array( 'sanitize_callback' => 'sanitize_key' ),
				'status'     => array( 'sanitize_callback' => 'sanitize_key' ),
				'event_type' => array( 'sanitize_callback' => 'sanitize_key' ),
				'user_id'    => array( 'sanitize_callback' => 'absint' ),
				'course_id'  => array( 'sanitize_callback' => 'absint' ),
			),
		) );
	}

	/**
	 * Normaliza el tamaño de página para endpoints REST.
	 *
	 * @param \WP_REST_Request $request       Request.
	 * @param int              $default_limit Límite por defecto.
	 * @param int              $max_limit     Límite máximo.
	 * @return int
	 */
	private static function normalize_rest_limit( \WP_REST_Request $request, int $default_limit, int $max_limit ): int {
		$limit = absint( $request->get_param( 'per_page' ) ?? $default_limit );
		if ( $limit < 1 ) {
			$limit = $default_limit;
		}

		return min( $max_limit, $limit );
	}

	/**
	 * Normaliza el offset usando `offset` explícito o `page`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param int              $limit   Límite por página.
	 * @return int
	 */
	private static function normalize_rest_offset( \WP_REST_Request $request, int $limit ): int {
		$page   = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );
		$offset = absint( $request->get_param( 'offset' ) ?? ( ( $page - 1 ) * $limit ) );

		return min( 5000, $offset );
	}

	/**
	 * Construye respuesta REST con headers de paginación.
	 *
	 * @param array<int,mixed> $items    Elementos de respuesta.
	 * @param int              $total    Total de registros.
	 * @param int              $per_page Tamaño de página.
	 * @return \WP_REST_Response
	 */
	private static function prepare_paginated_rest_response( array $items, int $total, int $per_page ): \WP_REST_Response {
		$response = rest_ensure_response( $items );
		if ( ! $response instanceof \WP_REST_Response ) {
			$response = new \WP_REST_Response( $items );
		}

		$total       = max( 0, $total );
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 0, $total_pages ) );

		return $response;
	}

	/**
	 * Verifica si una tabla existe.
	 *
	 * @param string $table Nombre completo de tabla.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		$like   = $wpdb->esc_like( $table );
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return $exists === $table;
	}

	/** @param \WP_REST_Request $r Request. */
	public static function rest_list_contacts( \WP_REST_Request $r ): \WP_REST_Response {
		global $wpdb;

		$current_user_id = get_current_user_id();
		if ( ! self::can_access_crm( $current_user_id ) ) {
			return new \WP_REST_Response( array(), 403 );
		}

		$limit  = self::normalize_rest_limit( $r, 20, 50 );
		$offset = self::normalize_rest_offset( $r, $limit );
		if ( ! self::table_exists( "{$wpdb->prefix}atora_contacts" ) ) {
			return self::prepare_paginated_rest_response( array(), 0, $limit );
		}

		$search = sanitize_text_field( $r->get_param( 's' ) ?? '' );
		$status = sanitize_key( (string) ( $r->get_param( 'status' ) ?? '' ) );
		$valid_statuses = array( 'lead', 'prospect', 'student', 'alumni', 'blocked' );
		if ( '' !== $status && ! in_array( $status, $valid_statuses, true ) ) {
			$status = '';
		}

		$where  = '1=1';
		$params = array();

		if ( $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where  .= ' AND (name LIKE %s OR email LIKE %s)';
			$params  = array( $like, $like );
		}
		if ( '' !== $status ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}

		if ( ! self::can_manage_crm( $current_user_id ) ) {
			$allowed_user_ids = self::get_accessible_contact_user_ids( $current_user_id );
			if ( empty( $allowed_user_ids ) ) {
				return self::prepare_paginated_rest_response( array(), 0, $limit );
			}
			$in_clause = implode( ',', array_fill( 0, count( $allowed_user_ids ), '%d' ) );
			$where    .= " AND user_id IN ({$in_clause})";
			foreach ( $allowed_user_ids as $allowed_user_id ) {
				$params[] = absint( $allowed_user_id );
			}
		}

		$count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}atora_contacts WHERE {$where}";
		$total     = ! empty( $params )
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$sql = "SELECT * FROM {$wpdb->prefix}atora_contacts WHERE {$where} ORDER BY updated_at DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $limit, $offset ) );
		$rows        = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$list_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return self::prepare_paginated_rest_response( $rows, $total, $limit );
	}

	/** @param \WP_REST_Request $r Request. */
	public static function rest_get_timeline( \WP_REST_Request $r ): \WP_REST_Response {
		global $wpdb;

		$id = absint( $r->get_param( 'id' ) );
		if ( ! self::current_user_can_access_contact( $id, get_current_user_id() ) ) {
			return new \WP_REST_Response( array(), 403 );
		}

		$limit  = self::normalize_rest_limit( $r, 50, 200 );
		$offset = self::normalize_rest_offset( $r, $limit );
		if ( ! self::table_exists( "{$wpdb->prefix}atora_contact_activities" ) ) {
			return self::prepare_paginated_rest_response( array(), 0, $limit );
		}

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}atora_contact_activities WHERE contact_id = %d",
				$id
			)
		);

		$timeline = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}atora_contact_activities
				 WHERE contact_id = %d
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				$id,
				$limit,
				$offset
			)
		);

		return self::prepare_paginated_rest_response( $timeline, $total, $limit );
	}

	/**
	 * Lista eventos de atora_message_log con contexto de cola.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_list_message_log( \WP_REST_Request $r ): \WP_REST_Response {
		global $wpdb;

		$current_user_id = get_current_user_id();
		if ( ! self::can_access_crm( $current_user_id ) ) {
			return new \WP_REST_Response( array(), 403 );
		}

		$queue_table = "{$wpdb->prefix}atora_message_queue";
		$log_table   = "{$wpdb->prefix}atora_message_log";
		if ( ! self::table_exists( $queue_table ) || ! self::table_exists( $log_table ) ) {
			return self::prepare_paginated_rest_response( array(), 0, self::normalize_rest_limit( $r, 50, 200 ) );
		}

		$limit  = self::normalize_rest_limit( $r, 50, 200 );
		$offset = self::normalize_rest_offset( $r, $limit );
		$query_parts = self::build_message_log_where_parts( $r, $current_user_id );
		$where       = (string) ( $query_parts['where'] ?? '1=0' );
		$params      = (array) ( $query_parts['params'] ?? array() );

		$count_sql = "SELECT COUNT(*) FROM {$log_table} ml INNER JOIN {$queue_table} mq ON mq.id = ml.queue_id WHERE {$where}";
		$total     = ! empty( $params )
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$sql = "SELECT ml.id, ml.queue_id, ml.event_type, ml.event_data, ml.created_at, mq.user_id, mq.channel, mq.template_key, mq.status, mq.recipient_name, mq.recipient_phone
			FROM {$log_table} ml
			INNER JOIN {$queue_table} mq ON mq.id = ml.queue_id
			WHERE {$where}
			ORDER BY ml.created_at DESC, ml.id DESC
			LIMIT %d OFFSET %d";

		$list_params = array_merge( $params, array( $limit, $offset ) );
		$rows        = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$list_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return self::prepare_paginated_rest_response( $rows, $total, $limit );
	}

	/**
	 * Devuelve resumen agregado de logs de mensajería para dashboards.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
		public static function rest_message_log_summary( \WP_REST_Request $r ): \WP_REST_Response {
		global $wpdb;

		$current_user_id = get_current_user_id();
		if ( ! self::can_access_crm( $current_user_id ) ) {
			return new \WP_REST_Response( array(), 403 );
		}

		$queue_table = "{$wpdb->prefix}atora_message_queue";
		$log_table   = "{$wpdb->prefix}atora_message_log";
		if ( ! self::table_exists( $queue_table ) || ! self::table_exists( $log_table ) ) {
			return rest_ensure_response(
				array(
					'total'         => 0,
					'by_channel'    => array(),
					'by_status'     => array(),
					'by_event_type' => array(),
				)
			);
		}

		$query_parts = self::build_message_log_where_parts( $r, $current_user_id );
		$where       = (string) ( $query_parts['where'] ?? '1=0' );
		$params      = (array) ( $query_parts['params'] ?? array() );

		$count_sql = "SELECT COUNT(*) FROM {$log_table} ml INNER JOIN {$queue_table} mq ON mq.id = ml.queue_id WHERE {$where}";
		$total     = ! empty( $params )
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$channel_sql = "SELECT mq.channel AS bucket, COUNT(*) AS total
			FROM {$log_table} ml
			INNER JOIN {$queue_table} mq ON mq.id = ml.queue_id
			WHERE {$where}
			GROUP BY mq.channel";
		$status_sql = "SELECT mq.status AS bucket, COUNT(*) AS total
			FROM {$log_table} ml
			INNER JOIN {$queue_table} mq ON mq.id = ml.queue_id
			WHERE {$where}
			GROUP BY mq.status";
		$event_sql = "SELECT ml.event_type AS bucket, COUNT(*) AS total
			FROM {$log_table} ml
			INNER JOIN {$queue_table} mq ON mq.id = ml.queue_id
			WHERE {$where}
			GROUP BY ml.event_type";

		$channel_rows = ! empty( $params )
			? (array) $wpdb->get_results( $wpdb->prepare( $channel_sql, ...$params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (array) $wpdb->get_results( $channel_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$status_rows  = ! empty( $params )
			? (array) $wpdb->get_results( $wpdb->prepare( $status_sql, ...$params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (array) $wpdb->get_results( $status_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$event_rows   = ! empty( $params )
			? (array) $wpdb->get_results( $wpdb->prepare( $event_sql, ...$params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (array) $wpdb->get_results( $event_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			return rest_ensure_response(
				array(
					'total'         => max( 0, $total ),
					'by_channel'    => self::format_message_log_summary_map( $channel_rows ),
					'by_status'     => self::format_message_log_summary_map( $status_rows ),
					'by_event_type' => self::format_message_log_summary_map( $event_rows ),
				)
			);
		}

		/**
		 * Configuración de UI para resumen de logs CRM (labels/empty/error).
		 *
		 * @return array<string,mixed>
		 */
		public static function get_message_log_summary_ui_defaults(): array {
			return array(
				'labels' => array(
					'channel'    => array(
						'email'    => __( 'Email', 'atora-lms' ),
						'whatsapp' => __( 'WhatsApp', 'atora-lms' ),
						'telegram' => __( 'Telegram', 'atora-lms' ),
						'sms'      => __( 'SMS', 'atora-lms' ),
					),
					'status'     => array(
						'pending'   => __( 'Pendiente', 'atora-lms' ),
						'sending'   => __( 'En envío', 'atora-lms' ),
						'sent'      => __( 'Enviado', 'atora-lms' ),
						'delivered' => __( 'Entregado', 'atora-lms' ),
						'read'      => __( 'Leído', 'atora-lms' ),
						'failed'    => __( 'Error', 'atora-lms' ),
					),
					'event_type' => array(
						'queued'            => __( 'Encolado', 'atora-lms' ),
						'duplicate_skipped' => __( 'Duplicado omitido', 'atora-lms' ),
						'sent'              => __( 'Enviado', 'atora-lms' ),
						'failed'            => __( 'Error', 'atora-lms' ),
						'delivered'         => __( 'Entregado', 'atora-lms' ),
						'read'              => __( 'Leído', 'atora-lms' ),
					),
				),
				'empty'  => array(
					'channel'    => __( 'Sin actividad de canales.', 'atora-lms' ),
					'status'     => __( 'Sin estados registrados.', 'atora-lms' ),
					'event_type' => __( 'Sin eventos de trazabilidad.', 'atora-lms' ),
				),
				'error'  => __( 'No fue posible cargar la trazabilidad de mensajes.', 'atora-lms' ),
			);
		}

		/**
		 * Bootstrap completo para UI del resumen de logs (labels + endpoint + nonce).
		 *
		 * @param array<string,mixed> $args Opciones de bootstrap.
		 * @return array<string,mixed>
		 */
		public static function get_message_log_summary_bootstrap( array $args = array() ): array {
			$query         = is_array( $args['query'] ?? null ) ? $args['query'] : array();
			$overrides     = is_array( $args['overrides'] ?? null ) ? $args['overrides'] : array();
			$allowed_query = array( 's', 'channel', 'status', 'event_type', 'user_id', 'course_id' );
			$sanitized     = array();

			foreach ( $query as $raw_key => $raw_value ) {
				$key = sanitize_key( (string) $raw_key );
				if ( ! in_array( $key, $allowed_query, true ) ) {
					continue;
				}

				if ( in_array( $key, array( 'user_id', 'course_id' ), true ) ) {
					$numeric_value = absint( $raw_value );
					if ( $numeric_value > 0 ) {
						$sanitized[ $key ] = $numeric_value;
					}
					continue;
				}

				if ( ! is_scalar( $raw_value ) ) {
					continue;
				}

				$value = sanitize_text_field( (string) $raw_value );
				if ( '' === $value ) {
					continue;
				}
				$sanitized[ $key ] = $value;
			}

			$endpoint = rest_url( 'atora/v1/crm/messages/log/summary' );
			if ( ! empty( $sanitized ) ) {
				$endpoint = add_query_arg( $sanitized, $endpoint );
			}

			$bootstrap = self::get_message_log_summary_ui_defaults();
			if ( ! empty( $overrides ) ) {
				$bootstrap = array_replace_recursive( $bootstrap, $overrides );
			}

			$bootstrap['endpoint'] = esc_url_raw( $endpoint );
			$bootstrap['nonce']    = wp_create_nonce( 'wp_rest' );

			return $bootstrap;
		}

		/**
		 * Construye WHERE + params para consultas de message log con scope por rol.
	 *
	 * @param \WP_REST_Request $r               Request.
	 * @param int              $current_user_id Usuario actual.
	 * @return array{where:string,params:array<int,mixed>}
	 */
	private static function build_message_log_where_parts( \WP_REST_Request $r, int $current_user_id ): array {
		global $wpdb;

		$search     = sanitize_text_field( (string) ( $r->get_param( 's' ) ?? '' ) );
		$channel    = sanitize_key( (string) ( $r->get_param( 'channel' ) ?? '' ) );
		$status     = sanitize_key( (string) ( $r->get_param( 'status' ) ?? '' ) );
		$event_type = sanitize_key( (string) ( $r->get_param( 'event_type' ) ?? '' ) );
		$user_id    = absint( $r->get_param( 'user_id' ) ?? 0 );
		$course_id  = absint( $r->get_param( 'course_id' ) ?? 0 );

		$valid_channels = array( 'whatsapp', 'telegram', 'sms', 'email' );
		if ( '' !== $channel && ! in_array( $channel, $valid_channels, true ) ) {
			$channel = '';
		}

		$valid_statuses = array( 'pending', 'sending', 'sent', 'delivered', 'read', 'failed' );
		if ( '' !== $status && ! in_array( $status, $valid_statuses, true ) ) {
			$status = '';
		}

		$where  = '1=1';
		$params = array();

		if ( '' !== $channel ) {
			$where   .= ' AND mq.channel = %s';
			$params[] = $channel;
		}
		if ( '' !== $status ) {
			$where   .= ' AND mq.status = %s';
			$params[] = $status;
		}
		if ( '' !== $event_type ) {
			$where   .= ' AND ml.event_type = %s';
			$params[] = $event_type;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND (mq.template_key LIKE %s OR mq.recipient_name LIKE %s OR mq.recipient_phone LIKE %s OR ml.event_type LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$course_user_ids = array();
		if ( $course_id > 0 ) {
			$course_user_ids = self::get_contact_user_ids_by_course( $course_id, $current_user_id );
			if ( empty( $course_user_ids ) ) {
				return array( 'where' => '1=0', 'params' => array() );
			}
		}

		if ( self::can_manage_crm( $current_user_id ) ) {
			if ( ! empty( $course_user_ids ) ) {
				$course_user_ids = array_values( array_unique( array_map( 'absint', $course_user_ids ) ) );
				if ( $user_id > 0 && ! in_array( $user_id, $course_user_ids, true ) ) {
					return array( 'where' => '1=0', 'params' => array() );
				}
				if ( $user_id <= 0 ) {
					$in_clause = implode( ',', array_fill( 0, count( $course_user_ids ), '%d' ) );
					$where    .= " AND mq.user_id IN ({$in_clause})";
					foreach ( $course_user_ids as $course_user_id ) {
						$params[] = absint( $course_user_id );
					}
				}
			}

			if ( $user_id > 0 ) {
				$where   .= ' AND mq.user_id = %d';
				$params[] = $user_id;
			}
		} else {
			$allowed_user_ids = array_values(
				array_unique(
					array_filter(
						array_map( 'absint', self::get_accessible_contact_user_ids( $current_user_id ) )
					)
				)
			);
			if ( ! empty( $course_user_ids ) ) {
				$allowed_user_ids = array_values( array_intersect( $allowed_user_ids, array_map( 'absint', $course_user_ids ) ) );
			}
			if ( empty( $allowed_user_ids ) ) {
				return array( 'where' => '1=0', 'params' => array() );
			}

			$scoped_ids = $user_id > 0 ? array( $user_id ) : $allowed_user_ids;
			$in_clause  = implode( ',', array_fill( 0, count( $scoped_ids ), '%d' ) );
			$where     .= " AND mq.user_id IN ({$in_clause})";
			foreach ( $scoped_ids as $scoped_id ) {
				$params[] = absint( $scoped_id );
			}
		}

		return array(
			'where'  => $where,
			'params' => $params,
		);
	}

	/**
	 * Normaliza filas agregadas (bucket/total) a mapa key => count.
	 *
	 * @param array<int,array<string,mixed>> $rows Filas agregadas.
	 * @return array<string,int>
	 */
	private static function format_message_log_summary_map( array $rows ): array {
		$map = array();
		foreach ( $rows as $row ) {
			$key = sanitize_key( (string) ( $row['bucket'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			$map[ $key ] = (int) ( $row['total'] ?? 0 );
		}

		return $map;
	}

}
