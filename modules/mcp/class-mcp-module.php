<?php
/**
 * ATORA MCP Module — Model Context Protocol (Fase 12C)
 *
 * Expone herramientas para que agentes de IA operen el CRM y el LMS.
 * Autenticación: Bearer <api_key> en el header Authorization.
 *
 * Endpoint base: /wp-json/atora/mcp/v1/
 *
 * Tools:
 *   GET  /tools                         → lista de tools disponibles
 *   POST /tools/get_contact             → obtener contacto por email o id
 *   POST /tools/list_contacts           → lista paginada con filtros
 *   POST /tools/search_contacts         → búsqueda full-text
 *   POST /tools/get_pipeline_summary    → resumen del pipeline comercial
 *   POST /tools/get_campaign_stats      → métricas de una campaña
 *   POST /tools/enroll_in_sequence      → enrolar contacto en secuencia drip
 *   POST /tools/add_tag                 → añadir tag a contacto
 *   POST /tools/get_automation_log      → log de ejecuciones recientes
 *
 * @package ATORA_LMS\MCP
 * @since   5.29.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ATORA_MCP_Module {

	const NS = 'atora/mcp/v1';

	public static function init(): void {
		// P2 (6.12.0): gateado por módulo 'mcp'.
		if ( ! class_exists( 'CLMS_Module_Registry' ) || CLMS_Module_Registry::is_active( 'mcp' ) ) {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		}
	}

	public static function register_routes(): void {
		$ns   = self::NS;
		$auth = array( __CLASS__, 'authenticate' );

		register_rest_route( $ns, '/tools', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_tools' ),
			'permission_callback' => $auth,
		) );

		$tools = array(
			// Original 8
			'get_contact', 'list_contacts', 'search_contacts',
			'get_pipeline_summary', 'get_campaign_stats',
			'enroll_in_sequence', 'add_tag', 'get_automation_log',
			// Fase IV S14 — 7 nuevos (get_leaderboard retirado en PT-1,
			// 6.10.0 -- el leaderboard público que respaldaba se eliminó
			// por decisión de producto, ver atora_lms.php)
			'get_enrollment', 'list_automations', 'trigger_automation',
			'send_campaign', 'get_course_progress', 'get_analytics_summary',
			'list_certificates',
		);

		foreach ( $tools as $tool ) {
			register_rest_route( $ns, '/tools/' . $tool, array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'dispatch_tool' ),
				'permission_callback' => $auth,
			) );
		}

		// OpenAPI schema
		register_rest_route( $ns, '/openapi.json', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'openapi_schema' ),
			'permission_callback' => '__return_true',
		) );
	}

	// ── Autenticación ────────────────────────────────────────────────────────

	public static function authenticate( WP_REST_Request $r ): bool|WP_Error {
		if ( ! class_exists( 'ATORA_API_Key_Service' ) ) {
			return new WP_Error( 'mcp_unavailable', 'MCP no disponible.', array( 'status' => 503 ) );
		}

		$auth_header = $r->get_header( 'Authorization' );
		if ( empty( $auth_header ) || ! str_starts_with( $auth_header, 'Bearer ' ) ) {
			return new WP_Error( 'mcp_unauthorized', 'API key requerida.', array( 'status' => 401 ) );
		}

		$raw_key = substr( $auth_header, 7 );
		// PT-6.2 (6.5.1): la operación real (read|write) se deriva del
		// tool pedido en la ruta — el mismo cálculo que dispatch_tool()
		// usa más abajo para el check de scope, para que el límite de
		// rate limiting corresponda a la operación que se va a ejecutar.
		$tool      = basename( $r->get_route() );
		$operation = self::tool_scope( $tool );
		$key_row   = ATORA_API_Key_Service::validate( $raw_key, $operation );

		if ( ! $key_row ) {
			return new WP_Error( 'mcp_invalid_key', 'API key inválida o expirada.', array( 'status' => 401 ) );
		}

		$r->set_param( '__mcp_key', $key_row );
		return true;
	}

	// ── Dispatcher ───────────────────────────────────────────────────────────

	public static function dispatch_tool( WP_REST_Request $r ): WP_REST_Response {
		$tool    = basename( $r->get_route() );
		$key_row = $r->get_param( '__mcp_key' ) ?: array();
		$input   = $r->get_json_params() ?: array();

		$handler = array( __CLASS__, 'tool_' . $tool );
		if ( ! is_callable( $handler ) ) {
			return new WP_REST_Response( array( 'error' => "Tool '{$tool}' no encontrado." ), 404 );
		}

		if ( ! ATORA_API_Key_Service::has_scope( $key_row, self::tool_scope( $tool ) ) ) {
			return new WP_REST_Response( array( 'error' => 'Scope insuficiente para este tool.' ), 403 );
		}

		$result = call_user_func( $handler, $input, $key_row );
		return new WP_REST_Response( array( 'tool' => $tool, 'result' => $result ), 200 );
	}

	private static function tool_scope( string $tool ): string {
		$write_tools = array( 'enroll_in_sequence', 'add_tag', 'trigger_automation', 'send_campaign' );
		return in_array( $tool, $write_tools, true ) ? 'write' : 'read';
	}

	// ── Tools ────────────────────────────────────────────────────────────────

	public static function list_tools( WP_REST_Request $r ): WP_REST_Response {
		return new WP_REST_Response( array( 'tools' => self::tools_definition() ), 200 );
	}

	private static function tools_definition(): array {
		return array(
			array( 'name' => 'get_contact',           'description' => 'Obtiene un contacto por email o ID. Input: {email?, id?}',                         'scope' => 'read'  ),
			array( 'name' => 'list_contacts',         'description' => 'Lista contactos con filtros. Input: {status?, tag?, limit?, offset?}',             'scope' => 'read'  ),
			array( 'name' => 'search_contacts',       'description' => 'Búsqueda de contactos. Input: {q, limit?}',                                       'scope' => 'read'  ),
			array( 'name' => 'get_pipeline_summary',  'description' => 'Resumen del pipeline. Input: {pipeline?=sales|academic}',                          'scope' => 'read'  ),
			array( 'name' => 'get_campaign_stats',    'description' => 'Métricas de campaña. Input: {campaign_id}',                                       'scope' => 'read'  ),
			array( 'name' => 'enroll_in_sequence',    'description' => 'Enrola contacto en drip. Input: {contact_id, sequence_id}',                       'scope' => 'write' ),
			array( 'name' => 'add_tag',               'description' => 'Añade tag a contacto. Input: {contact_id, tag}',                                  'scope' => 'write' ),
			array( 'name' => 'get_automation_log',    'description' => 'Log de automatizaciones. Input: {automation_id?, limit?}',                        'scope' => 'read'  ),
			// Fase IV S14 — nuevos tools
			array( 'name' => 'get_enrollment',        'description' => 'Matrícula de un usuario en un curso. Input: {user_id, course_id}',                'scope' => 'read'  ),
			array( 'name' => 'list_automations',      'description' => 'Lista automatizaciones activas. Input: {limit?}',                                 'scope' => 'read'  ),
			array( 'name' => 'trigger_automation',    'description' => 'Activa una automatización. Input: {automation_id, user_id}',                      'scope' => 'write' ),
			array( 'name' => 'send_campaign',         'description' => 'Lanza una campaña de email. Input: {campaign_id}',                               'scope' => 'write' ),
			array( 'name' => 'get_course_progress',   'description' => 'Progreso de un usuario en un curso. Input: {user_id, course_id}',                 'scope' => 'read'  ),
			array( 'name' => 'get_analytics_summary', 'description' => 'Resumen de analytics. Input: {period?=30}',                                      'scope' => 'read'  ),
			array( 'name' => 'list_certificates',     'description' => 'Certificados de un usuario. Input: {user_id}',                                   'scope' => 'read'  ),
		);
	}

	private static function tool_get_contact( array $input ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_contacts';
		$row   = null;

		if ( ! empty( $input['id'] ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $input['id'] ) ), ARRAY_A );
		} elseif ( ! empty( $input['email'] ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s LIMIT 1", sanitize_email( (string) $input['email'] ) ), ARRAY_A );
		}

		if ( ! $row ) { return array( 'found' => false ); }

		return array(
			'found'         => true,
			'id'            => absint( $row['id'] ),
			'name'          => sanitize_text_field( (string) ( $row['name']             ?? '' ) ),
			'email'         => sanitize_email(      (string) ( $row['email']            ?? '' ) ),
			'status'        => sanitize_key(        (string) ( $row['status']           ?? '' ) ),
			'contact_type'  => sanitize_key(        (string) ( $row['contact_type']     ?? '' ) ),
			'total_points'  => absint(                        $row['total_points']      ?? 0 ),
			'ltv'           => (float)                       ( $row['life_time_value']  ?? 0 ),
			'last_activity' => sanitize_text_field( (string) ( $row['last_activity_at'] ?? '' ) ),
		);
	}

	private static function tool_list_contacts( array $input ): array {
		if ( ! class_exists( '\ATORA\CRM_V2\Services\Contact_Service' ) ) {
			return array( 'error' => 'Contact_Service no disponible.' );
		}
		$args = array(
			'status' => sanitize_key(        (string) ( $input['status'] ?? '' ) ),
			'tag'    => sanitize_text_field( (string) ( $input['tag']    ?? '' ) ),
			'limit'  => absint( $input['limit']  ?? 20 ),
			'offset' => absint( $input['offset'] ?? 0 ),
		);
		return \ATORA\CRM_V2\Services\Contact_Service::list_contacts( $args );
	}

	private static function tool_search_contacts( array $input ): array {
		if ( ! class_exists( '\ATORA\CRM_V2\Services\Contact_Service' ) ) {
			return array( 'error' => 'Contact_Service no disponible.' );
		}
		$q = sanitize_text_field( (string) ( $input['q'] ?? '' ) );
		if ( strlen( $q ) < 2 ) { return array( 'error' => 'Query demasiado corto (mín 2 chars).' ); }
		return \ATORA\CRM_V2\Services\Contact_Service::list_contacts( array(
			'search' => $q,
			'limit'  => absint( $input['limit'] ?? 10 ),
		) );
	}

	private static function tool_get_pipeline_summary( array $input ): array {
		global $wpdb;
		$pipeline = sanitize_key( (string) ( $input['pipeline'] ?? 'sales' ) );
		$table    = 'sales' === $pipeline
			? $wpdb->prefix . 'atora_crm_deals'
			: $wpdb->prefix . 'atora_crm_student_followups';

		$rows = (array) $wpdb->get_results(
			"SELECT stage, COUNT(*) AS count FROM {$table} GROUP BY stage ORDER BY count DESC", // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return array( 'pipeline' => $pipeline, 'stages' => $rows );
	}

	private static function tool_get_campaign_stats( array $input ): array {
		$campaign_id = absint( $input['campaign_id'] ?? 0 );
		if ( ! $campaign_id ) { return array( 'error' => 'campaign_id requerido.' ); }

		if ( ! class_exists( '\ATORA\CRM_V2\Services\Campaign_Service' ) ) {
			return array( 'error' => 'Campaign_Service no disponible.' );
		}
		return \ATORA\CRM_V2\Services\Campaign_Service::get_campaign_metrics( $campaign_id );
	}

	private static function tool_enroll_in_sequence( array $input ): array {
		$contact_id  = absint( $input['contact_id']  ?? 0 );
		$sequence_id = absint( $input['sequence_id'] ?? 0 );

		if ( ! $contact_id || ! $sequence_id ) {
			return array( 'error' => 'contact_id y sequence_id son requeridos.' );
		}
		if ( ! class_exists( '\ATORA\CRM_V2\Services\Sequence_Service' ) ) {
			return array( 'error' => 'Sequence_Service no disponible.' );
		}
		$id = \ATORA\CRM_V2\Services\Sequence_Service::enroll_contact( $sequence_id, $contact_id );
		return $id
			? array( 'enrolled' => true, 'enrollment_id' => $id )
			: array( 'enrolled' => false, 'error' => 'No se pudo enrolar. Verifica que la secuencia esté activa.' );
	}

	private static function tool_add_tag( array $input ): array {
		$contact_id = absint( $input['contact_id'] ?? 0 );
		$tag        = sanitize_text_field( (string) ( $input['tag'] ?? '' ) );

		if ( ! $contact_id || '' === $tag ) {
			return array( 'error' => 'contact_id y tag son requeridos.' );
		}
		if ( ! class_exists( '\ATORA\CRM_V2\Services\Contact_Service' ) ) {
			return array( 'error' => 'Contact_Service no disponible.' );
		}
		$ok = \ATORA\CRM_V2\Services\Contact_Service::add_tag( $contact_id, $tag );
		return array( 'tagged' => $ok );
	}

	private static function tool_get_automation_log( array $input ): array {
		global $wpdb;
		$log_table    = $wpdb->prefix . 'atora_automation_execution_log';
		$like         = $wpdb->esc_like( $log_table );
		$table_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) === $log_table;

		if ( ! $table_exists ) { return array( 'error' => 'Log table no disponible.' ); }

		$auto_id = absint( $input['automation_id'] ?? 0 );
		$limit   = absint( $input['limit'] ?? 20 );
		$where   = $auto_id ? $wpdb->prepare( 'WHERE automation_id = %d', $auto_id ) : '';

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT automation_id, trigger_type, action_type, status, error_message, executed_at FROM {$log_table} {$where} ORDER BY executed_at DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		return array( 'count' => count( $rows ), 'entries' => $rows );
	}

	// ── Fase IV S14: 8 tools nuevos ─────────────────────────────────────────

	private static function tool_get_enrollment( array $input ): array {
		$user_id   = absint( $input['user_id']   ?? 0 );
		$course_id = absint( $input['course_id'] ?? 0 );
		if ( ! $user_id || ! $course_id ) { return array( 'error' => 'user_id y course_id requeridos.' ); }
		if ( class_exists( '\ATORA\LMS\LMS_Enrollment_Service' ) ) {
			$e = \ATORA\LMS\LMS_Enrollment_Service::get_enrollment( $user_id, $course_id );
			return $e ?? array( 'found' => false );
		}
		return array( 'error' => 'LMS_Enrollment_Service no disponible.' );
	}

	private static function tool_list_automations( array $input ): array {
		global $wpdb;
		$limit = absint( $input['limit'] ?? 20 );
		$rows  = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id, name, trigger_type, is_active FROM {$wpdb->prefix}atora_automations WHERE is_active = 1 ORDER BY created_at DESC LIMIT %d", $limit ),
			ARRAY_A
		);
		return array( 'automations' => $rows, 'count' => count( $rows ) );
	}

	private static function tool_trigger_automation( array $input ): array {
		$auto_id = absint( $input['automation_id'] ?? 0 );
		$user_id = absint( $input['user_id']       ?? 0 );
		if ( ! $auto_id || ! $user_id ) { return array( 'error' => 'automation_id y user_id requeridos.' ); }
		if ( class_exists( 'ATORA\CRM_V2\Automation_Engine' ) || class_exists( 'ATORA_Automation_Engine' ) ) {
			do_action( 'atora/automation/manual_trigger', $auto_id, $user_id );
			return array( 'triggered' => true, 'automation_id' => $auto_id, 'user_id' => $user_id );
		}
		return array( 'triggered' => false, 'note' => 'Automation Engine no disponible.' );
	}

	private static function tool_send_campaign( array $input ): array {
		$campaign_id = absint( $input['campaign_id'] ?? 0 );
		if ( ! $campaign_id ) { return array( 'error' => 'campaign_id requerido.' ); }
		if ( class_exists( '\ATORA\CRM_V2\Services\Campaign_Service' ) ) {
			$ok = \ATORA\CRM_V2\Services\Campaign_Service::launch( $campaign_id );
			return array( 'launched' => (bool) $ok, 'campaign_id' => $campaign_id );
		}
		return array( 'error' => 'Campaign_Service no disponible.' );
	}

	private static function tool_get_course_progress( array $input ): array {
		$user_id   = absint( $input['user_id']   ?? 0 );
		$course_id = absint( $input['course_id'] ?? 0 );
		if ( ! $user_id || ! $course_id ) { return array( 'error' => 'user_id y course_id requeridos.' ); }
		if ( class_exists( '\ATORA\LMS\LMS_Enrollment_Service' ) ) {
			return \ATORA\LMS\LMS_Enrollment_Service::get_progress( $user_id, $course_id );
		}
		return array( 'error' => 'LMS_Enrollment_Service no disponible.' );
	}

	private static function tool_get_analytics_summary( array $input ): array {
		$period = absint( $input['period'] ?? 30 );
		if ( class_exists( 'ATORA_Analytics_Engine' ) ) {
			return array(
				'email'    => ATORA_Analytics_Engine::get_email_metrics( $period ),
				'period'   => $period,
			);
		}
		return array( 'error' => 'Analytics_Engine no disponible.' );
	}

	private static function tool_list_certificates( array $input ): array {
		$user_id = absint( $input['user_id'] ?? get_current_user_id() );
		if ( ! $user_id ) { return array( 'error' => 'user_id requerido.' ); }
		// Leer desde usermeta usando el prefijo de CLMS_Certificates
		$prefix = '_clms_certificate_record_';
		$keys   = (array) get_user_meta( $user_id );
		$certs  = array();
		foreach ( array_keys( $keys ) as $key ) {
			if ( str_starts_with( $key, $prefix ) ) {
				$record = get_user_meta( $user_id, $key, true );
				if ( is_array( $record ) ) { $certs[] = $record; }
			}
		}
		return array( 'user_id' => $user_id, 'certificates' => $certs, 'count' => count( $certs ) );
	}

	// tool_get_leaderboard() retirado en PT-1 (6.10.0) junto con el
	// shortcode público que respaldaba (includes/class-atora-gamification-public.php,
	// eliminado) -- decisión de producto: el leaderboard competitivo se
	// reemplaza por insignias individuales no comparativas (ver
	// includes/gamification/class-student-badge-service.php).

	// ── OpenAPI 3.0 Schema ───────────────────────────────────────────────────

	public static function openapi_schema( WP_REST_Request $r ): WP_REST_Response {
		$base_url = rest_url( self::NS );
		$paths    = array();

		foreach ( self::tools_definition() as $tool ) {
			$name = sanitize_key( (string) ( $tool['name'] ?? '' ) );
			$desc = sanitize_text_field( (string) ( $tool['description'] ?? '' ) );
			$scope = sanitize_key( (string) ( $tool['scope'] ?? 'read' ) );

			$paths[ '/tools/' . $name ] = array(
				'post' => array(
					'summary'     => $desc,
					'operationId' => $name,
					'tags'        => array( $scope ),
					'security'    => array( array( 'BearerAuth' => array() ) ),
					'requestBody' => array(
						'content' => array(
							'application/json' => array(
								'schema' => array( 'type' => 'object', 'additionalProperties' => true ),
							),
						),
					),
					'responses' => array(
						'200' => array( 'description' => 'Resultado del tool.' ),
						'401' => array( 'description' => 'API key inválida.' ),
						'403' => array( 'description' => 'Scope insuficiente.' ),
					),
				),
			);
		}

		$schema = array(
			'openapi' => '3.0.3',
			'info'    => array(
				'title'   => 'ATORA LMS MCP API',
				'version' => '1.0.0',
				'description' => 'Model Context Protocol — 16 tools para CRM, LMS y Analytics.',
			),
			'servers' => array( array( 'url' => $base_url ) ),
			'components' => array(
				'securitySchemes' => array(
					'BearerAuth' => array( 'type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'ATORA API Key' ),
				),
			),
			'paths' => $paths,
		);

		return new WP_REST_Response( $schema, 200 );
	}
}
