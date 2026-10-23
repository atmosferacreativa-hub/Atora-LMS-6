<?php
/**
 * REST de logs de mensajería CRM v2.
 *
 * Port de los endpoints legacy de CRM v1:
 * - GET /crm/messages/log
 * - GET /crm/messages/log/summary
 *
 * @package ATORA_LMS\\CRM_V2
 */

namespace ATORA\CRM_V2\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Messages_Log_REST_Controller {
	/**
	 * Registra rutas.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$ns = CRM_REST_Controller::REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/messages/log',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_log' ),
				'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_access' ),
			)
		);

		register_rest_route(
			$ns,
			'/messages/log/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_summary' ),
				'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_access' ),
			)
		);
	}

	/**
	 * Lista eventos de atora_message_log con contexto de cola.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function list_log( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$queue_table = $wpdb->prefix . 'atora_message_queue';
		$log_table   = $wpdb->prefix . 'atora_message_log';
		if ( ! class_exists( '\\ATORA\\CRM_V2\\Services\\DB_Service' ) || ! \ATORA\CRM_V2\Services\DB_Service::table_exists( $queue_table ) || ! \ATORA\CRM_V2\Services\DB_Service::table_exists( $log_table ) ) {
			return rest_ensure_response( array( 'success' => true, 'items' => array(), 'total' => 0, 'per_page' => 50, 'page' => 1 ) );
		}

		$per_page = max( 1, min( 200, absint( $request->get_param( 'per_page' ) ?: 50 ) ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
		$offset   = min( 5000, ( $page - 1 ) * $per_page );

		$query = self::build_where_parts( $request );
		$where = (string) ( $query['where'] ?? '1=0' );
		$params = (array) ( $query['params'] ?? array() );

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

		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$items       = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return rest_ensure_response(
			array(
				'success'  => true,
				'items'    => $items,
				'total'    => max( 0, $total ),
				'per_page' => $per_page,
				'page'     => $page,
			)
		);
	}

	/**
	 * Devuelve resumen agregado de logs de mensajería.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_summary( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$queue_table = $wpdb->prefix . 'atora_message_queue';
		$log_table   = $wpdb->prefix . 'atora_message_log';
		if ( ! class_exists( '\\ATORA\\CRM_V2\\Services\\DB_Service' ) || ! \ATORA\CRM_V2\Services\DB_Service::table_exists( $queue_table ) || ! \ATORA\CRM_V2\Services\DB_Service::table_exists( $log_table ) ) {
			return rest_ensure_response(
				array(
					'success'       => true,
					'total'         => 0,
					'by_channel'    => array(),
					'by_status'     => array(),
					'by_event_type' => array(),
				)
			);
		}

		$query = self::build_where_parts( $request );
		$where = (string) ( $query['where'] ?? '1=0' );
		$params = (array) ( $query['params'] ?? array() );

		$count_sql = "SELECT COUNT(*) FROM {$log_table} ml INNER JOIN {$queue_table} mq ON mq.id = ml.queue_id WHERE {$where}";
		$total     = ! empty( $params )
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$channel_sql = "SELECT mq.channel AS bucket, COUNT(*) AS total
			FROM {$log_table} ml
			INNER JOIN {$queue_table} mq ON mq.id = ml.queue_id
			WHERE {$where}
			GROUP BY mq.channel";
		$status_sql  = "SELECT mq.status AS bucket, COUNT(*) AS total
			FROM {$log_table} ml
			INNER JOIN {$queue_table} mq ON mq.id = ml.queue_id
			WHERE {$where}
			GROUP BY mq.status";
		$event_sql   = "SELECT ml.event_type AS bucket, COUNT(*) AS total
			FROM {$log_table} ml
			INNER JOIN {$queue_table} mq ON mq.id = ml.queue_id
			WHERE {$where}
			GROUP BY ml.event_type";

		$channel_rows = ! empty( $params )
			? (array) $wpdb->get_results( $wpdb->prepare( $channel_sql, ...$params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (array) $wpdb->get_results( $channel_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$status_rows = ! empty( $params )
			? (array) $wpdb->get_results( $wpdb->prepare( $status_sql, ...$params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (array) $wpdb->get_results( $status_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$event_rows  = ! empty( $params )
			? (array) $wpdb->get_results( $wpdb->prepare( $event_sql, ...$params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (array) $wpdb->get_results( $event_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return rest_ensure_response(
			array(
				'success'       => true,
				'total'         => max( 0, $total ),
				'by_channel'    => self::format_summary_map( $channel_rows ),
				'by_status'     => self::format_summary_map( $status_rows ),
				'by_event_type' => self::format_summary_map( $event_rows ),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return array{where:string,params:array<int,mixed>}
	 */
	private static function build_where_parts( \WP_REST_Request $request ): array {
		global $wpdb;

		$search     = sanitize_text_field( (string) ( $request->get_param( 's' ) ?? '' ) );
		$channel    = sanitize_key( (string) ( $request->get_param( 'channel' ) ?? '' ) );
		$status     = sanitize_key( (string) ( $request->get_param( 'status' ) ?? '' ) );
		$event_type = sanitize_key( (string) ( $request->get_param( 'event_type' ) ?? '' ) );
		$user_id    = absint( $request->get_param( 'user_id' ) ?? 0 );
		$course_id  = absint( $request->get_param( 'course_id' ) ?? 0 );

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
			$course_user_ids = self::get_scoped_course_user_ids( $course_id );
			if ( empty( $course_user_ids ) ) {
				return array( 'where' => '1=0', 'params' => array() );
			}
		}

		$scope_user_ids = $user_id > 0 ? array( $user_id ) : CRM_REST_Controller::get_scope_user_ids();
		if ( ! CRM_REST_Controller::has_global_scope() ) {
			$scope_user_ids = array_values( array_filter( array_map( 'absint', $scope_user_ids ) ) );
			if ( empty( $scope_user_ids ) ) {
				return array( 'where' => '1=0', 'params' => array() );
			}
		}

		if ( ! empty( $course_user_ids ) ) {
			$scope_user_ids = array_values( array_intersect( array_map( 'absint', $scope_user_ids ), array_map( 'absint', $course_user_ids ) ) );
			if ( empty( $scope_user_ids ) ) {
				return array( 'where' => '1=0', 'params' => array() );
			}
		}

		if ( ! empty( $scope_user_ids ) ) {
			$in_clause = implode( ',', array_fill( 0, count( $scope_user_ids ), '%d' ) );
			$where    .= " AND mq.user_id IN ({$in_clause})";
			foreach ( $scope_user_ids as $scope_user_id ) {
				$params[] = absint( $scope_user_id );
			}
		}

		return array(
			'where'  => $where,
			'params' => $params,
		);
	}

	/**
	 * @param int $course_id
	 * @return array<int,int>
	 */
	private static function get_scoped_course_user_ids( int $course_id ): array {
		$course_id = absint( $course_id );
		if ( $course_id <= 0 ) {
			return array();
		}
		if ( ! function_exists( 'get_post_type' ) || 'lm_course' !== get_post_type( $course_id ) ) {
			return array();
		}
		if ( ! class_exists( '\\CLMS_Helper' ) || ! method_exists( '\\CLMS_Helper', 'get_enrolled_student_ids' ) ) {
			return array();
		}

		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) \CLMS_Helper::get_enrolled_student_ids( $course_id ) ) ) ) );
		if ( empty( $user_ids ) ) {
			return array();
		}
		if ( CRM_REST_Controller::has_global_scope() ) {
			sort( $user_ids );
			return $user_ids;
		}

		$scope_user_ids = array_values( array_filter( array_map( 'absint', CRM_REST_Controller::get_scope_user_ids() ) ) );
		$user_ids       = array_values( array_intersect( $user_ids, $scope_user_ids ) );
		sort( $user_ids );
		return $user_ids;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<string,int>
	 */
	private static function format_summary_map( array $rows ): array {
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

