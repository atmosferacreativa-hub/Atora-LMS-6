<?php
/**
 * REST Controller — Campaign Builder CRM v2 (Fase 3)
 *
 * Expone el CRUD completo de campañas para el builder multi-paso:
 *
 *   GET  /atora-crm/v2/campaigns              → lista paginada
 *   POST /atora-crm/v2/campaigns              → crear borrador
 *   GET  /atora-crm/v2/campaigns/{id}         → obtener campaña
 *   POST /atora-crm/v2/campaigns/{id}         → actualizar borrador
 *   POST /atora-crm/v2/campaigns/{id}/launch  → lanzar (simulate|queue)
 *   POST /atora-crm/v2/campaigns/{id}/clone   → duplicar campaña
 *   POST /atora-crm/v2/campaigns/{id}/pause   → pausar campaña programada
 *   POST /atora-crm/v2/campaigns/test         → enviar email de prueba
 *   GET  /atora-crm/v2/campaigns/audience     → estimar audiencia en tiempo real
 *   GET  /atora-crm/v2/campaigns/meta         → templates, canales, identidades
 *
 * @package ATORA_LMS\CRM_V2\Rest
 * @since   5.22.0
 */

namespace ATORA\CRM_V2\Rest;

use ATORA\CRM_V2\Services\Campaign_Service;
use ATORA\CRM_V2\Services\Contact_Service;
use ATORA\CRM_V2\CRM_V2;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Campaign_Builder_REST_Controller {

	const REST_NAMESPACE = 'atora-crm/v2';

	public static function register_routes(): void {
		$ns = self::REST_NAMESPACE;
		$cb = array( __CLASS__, 'can_access' );

		// Meta: templates, canales, identidades (no requiere ID)
		register_rest_route( $ns, '/campaigns/meta',     array( 'methods' => 'GET',  'callback' => array( __CLASS__, 'get_meta' ),           'permission_callback' => $cb ) );
		// Estimación de audiencia sin guardar
		register_rest_route( $ns, '/campaigns/audience', array( 'methods' => 'GET',  'callback' => array( __CLASS__, 'estimate_audience' ),  'permission_callback' => $cb ) );
		// Envío de prueba sin campaña guardada
		register_rest_route( $ns, '/campaigns/test',     array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'send_test' ),          'permission_callback' => $cb ) );

		// Colección
		register_rest_route( $ns, '/campaigns', array(
			array( 'methods' => 'GET',  'callback' => array( __CLASS__, 'list_campaigns' ),  'permission_callback' => $cb ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_campaign' ), 'permission_callback' => $cb ),
		) );

		// Elemento
		register_rest_route( $ns, '/campaigns/(?P<campaign_id>\d+)', array(
			array( 'methods' => 'GET',  'callback' => array( __CLASS__, 'get_campaign' ),    'permission_callback' => $cb ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'update_campaign' ), 'permission_callback' => $cb ),
		) );

		// Acciones sobre un elemento
		register_rest_route( $ns, '/campaigns/(?P<campaign_id>\d+)/launch', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'launch_campaign' ), 'permission_callback' => $cb ) );
		register_rest_route( $ns, '/campaigns/(?P<campaign_id>\d+)/clone',  array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'clone_campaign' ),  'permission_callback' => $cb ) );
		register_rest_route( $ns, '/campaigns/(?P<campaign_id>\d+)/pause',  array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'pause_campaign' ),  'permission_callback' => $cb ) );
		register_rest_route( $ns, '/campaigns/(?P<campaign_id>\d+)/metrics', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_metrics' ), 'permission_callback' => $cb ) );
	}

	/* ─── Permisos ──────────────────────────────────────────────── */

	public static function can_access(): bool {
		return current_user_can( 'crm_manage_campaigns' )
			|| current_user_can( 'clms_manage_crm' )
			|| current_user_can( 'manage_options' );
	}

	/* ─── Helpers ───────────────────────────────────────────────── */

	private static function ok( string $message, array $data = array() ): \WP_REST_Response {
		return new \WP_REST_Response( array_merge( array( 'success' => true, 'message' => $message ), $data ), 200 );
	}

	private static function fail( string $message, int $status = 400 ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'success' => false, 'message' => $message ), $status );
	}

	/** Extrae y sanea los campos de campaña del body JSON */
	private static function parse_campaign_body( \WP_REST_Request $request ): array {
		$b = $request->get_json_params() ?: array();
		return array(
			'name'           => sanitize_text_field( (string) ( $b['name']           ?? '' ) ),
			'template_key'   => sanitize_key(        (string) ( $b['template_key']   ?? 'lead_welcome' ) ),
			'channel'        => sanitize_key(        (string) ( $b['channel']        ?? 'email' ) ),
			'execution_mode' => sanitize_key(        (string) ( $b['execution_mode'] ?? 'simulate' ) ),
			'identity'       => sanitize_key(        (string) ( $b['identity']       ?? '' ) ),
			'subject'        => sanitize_text_field( (string) ( $b['subject']        ?? '' ) ),
			'message'        => sanitize_textarea_field( (string) ( $b['message']    ?? '' ) ),
			'blocks_json'    => $b['blocks_json'] ?? ( $b['campaign_blocks'] ?? '' ),
			'cta_url'        => esc_url_raw(         (string) ( $b['cta_url']        ?? '' ) ),
			'scheduled_at'   => sanitize_text_field( (string) ( $b['scheduled_at']   ?? '' ) ),
			// Segmento
			'audience'       => sanitize_key(        (string) ( $b['audience']       ?? 'all' ) ),
			'status'         => sanitize_key(        (string) ( $b['status_filter']  ?? '' ) ),
			'tag'            => sanitize_text_field( (string) ( $b['tag']            ?? '' ) ),
			'course_id'      => absint(                        $b['course_id']       ?? 0 ),
			'search'         => sanitize_text_field( (string) ( $b['search']         ?? '' ) ),
		);
	}

	/** Formatea una campaña DB para el frontend */
	private static function format_campaign( array $row ): array {
		$segment = json_decode( (string) ( $row['segment_json'] ?? '{}' ), true );
		$segment = is_array( $segment ) ? $segment : array();

		$scheduled_at = sanitize_text_field( (string) ( $row['scheduled_at'] ?? '' ) );
		$scheduled_display = '';
		if ( $scheduled_at ) {
			$dt = date_create( $scheduled_at, new \DateTimeZone( 'UTC' ) );
			if ( $dt ) {
				$dt->setTimezone( wp_timezone() );
				$scheduled_display = $dt->format( 'Y-m-d\TH:i' );
			}
		}

		$status_labels = array(
			'draft'     => __( 'Borrador',   'atora-lms' ),
			'simulated' => __( 'Simulada',   'atora-lms' ),
			'scheduled' => __( 'Programada', 'atora-lms' ),
			'queued'    => __( 'Encolada',   'atora-lms' ),
			'sent'      => __( 'Enviada',    'atora-lms' ),
			'paused'    => __( 'Pausada',    'atora-lms' ),
			'failed'    => __( 'Fallida',    'atora-lms' ),
		);

		$status = sanitize_key( (string) ( $row['status'] ?? 'draft' ) );

		return array(
			'id'             => absint( $row['id'] ),
			'name'           => sanitize_text_field( (string) ( $row['name']           ?? '' ) ),
			'template_key'   => sanitize_key(        (string) ( $row['template_key']   ?? '' ) ),
			'channel'        => sanitize_key(        (string) ( $row['channel']        ?? 'email' ) ),
			'execution_mode' => sanitize_key(        (string) ( $row['execution_mode'] ?? 'simulate' ) ),
			'status'         => $status,
			'status_label'   => $status_labels[ $status ] ?? strtoupper( $status ),
			'subject'        => sanitize_text_field( (string) ( $row['subject']        ?? '' ) ),
			'message'        => sanitize_textarea_field( (string) ( $row['message']    ?? '' ) ),
			'blocks_json'    => ( '[' === substr( trim( (string) ( $row['message'] ?? '' ) ), 0, 1 ) ) ? (string) ( $row['message'] ?? '' ) : '',
			'cta_url'        => esc_url_raw( (string) ( $row['cta_url']               ?? '' ) ),
			'scheduled_at'   => $scheduled_display,
			'created_at'     => sanitize_text_field( (string) ( $row['created_at']    ?? '' ) ),
			'launched_at'    => sanitize_text_field( (string) ( $row['launched_at']   ?? '' ) ),
			// Segmento decodificado
			'audience'       => sanitize_key(        (string) ( $segment['audience']  ?? 'all' ) ),
			'status_filter'  => sanitize_key(        (string) ( $segment['status']    ?? '' ) ),
			'tag'            => sanitize_text_field( (string) ( $segment['tag']       ?? '' ) ),
			'course_id'      => absint( $segment['course_id'] ?? 0 ),
			'search'         => sanitize_text_field( (string) ( $segment['search']    ?? '' ) ),
			'identity'       => sanitize_key(        (string) ( $segment['identity']  ?? '' ) ),
			// Métricas (si existen)
			'recipients_total'   => absint( $row['recipients_total']   ?? 0 ),
			'recipients_queued'  => absint( $row['recipients_queued']  ?? 0 ),
			'recipients_skipped' => absint( $row['recipients_skipped'] ?? 0 ),
			'metrics'            => Campaign_Service::get_campaign_metrics( absint( $row['id'] ?? 0 ) ),
		);
	}

	/** Cuenta destinatarios estimados para los filtros dados */
	private static function count_audience( array $filters ): array {
		$contacts = Contact_Service::list_contacts( array(
			'search'    => $filters['search']    ?? '',
			'status'    => $filters['status']    ?? '',
			'tag'       => $filters['tag']       ?? '',
			'course_id' => $filters['course_id'] ?? 0,
			'limit'     => 1,   // solo necesitamos el total
		) );

		$total = absint( $contacts['total'] ?? 0 );

		// Estimación de elegibles por email (aprox 80% tiene email)
		$email_est = (int) round( $total * 0.8 );

		return array(
			'total'     => $total,
			'email_est' => $email_est,
		);
	}

	/* ─── GET /campaigns/meta ───────────────────────────────────── */

	public static function get_meta( \WP_REST_Request $request ): \WP_REST_Response {
		$templates = Campaign_Service::get_templates();
		$formatted_tpl = array();
		foreach ( $templates as $k => $t ) {
			$formatted_tpl[] = array(
				'key'     => $k,
				'label'   => $t['label'],
				'subject' => $t['subject'],
				'body'    => $t['body'],
			);
		}

		return self::ok( '', array(
			'templates' => $formatted_tpl,
			'channels'  => array(
				array( 'key' => 'email',    'label' => 'Email',    'ready' => true ),
				array( 'key' => 'whatsapp', 'label' => 'WhatsApp', 'ready' => false ),
				array( 'key' => 'telegram', 'label' => 'Telegram', 'ready' => false ),
			),
			'identities' => array(
				array( 'key' => 'academia', 'label' => __( 'Academia (plataforma y tienda)', 'atora-lms' ) ),
				array( 'key' => 'teacher',  'label' => __( 'Docencia (estudiantes inscritos)', 'atora-lms' ) ),
				array( 'key' => 'admin',    'label' => __( 'Comercial (leads y prospectos)', 'atora-lms' ) ),
			),
			'statuses' => array(
				array( 'key' => '',         'label' => __( 'Todos los estados', 'atora-lms' ) ),
				array( 'key' => 'lead',     'label' => __( 'Lead',     'atora-lms' ) ),
				array( 'key' => 'prospect', 'label' => __( 'Prospecto','atora-lms' ) ),
				array( 'key' => 'student',  'label' => __( 'Estudiante','atora-lms' ) ),
				array( 'key' => 'alumni',   'label' => __( 'Egresado', 'atora-lms' ) ),
			),
		) );
	}

	/* ─── GET /campaigns/audience ───────────────────────────────── */

	public static function estimate_audience( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = array(
			'search'    => sanitize_text_field( (string) $request->get_param( 'search'    ) ),
			'status'    => sanitize_key(        (string) $request->get_param( 'status'    ) ),
			'tag'       => sanitize_text_field( (string) $request->get_param( 'tag'       ) ),
			'course_id' => absint(                        $request->get_param( 'course_id' ) ),
		);

		return self::ok( '', array( 'audience' => self::count_audience( $filters ) ) );
	}

	/* ─── POST /campaigns/test ──────────────────────────────────── */

	public static function send_test( \WP_REST_Request $request ): \WP_REST_Response {
		$b        = $request->get_json_params() ?: array();
		$email    = sanitize_email(              (string) ( $b['email']    ?? '' ) );
		$subject  = sanitize_text_field(         (string) ( $b['subject']  ?? '' ) );
		$message  = sanitize_textarea_field(     (string) ( $b['message']  ?? '' ) );
		$cta_url  = esc_url_raw(                 (string) ( $b['cta_url']  ?? '' ) );
		$identity = sanitize_key(                (string) ( $b['identity'] ?? '' ) );

		if ( ! $email ) {
			return self::fail( __( 'Indica un email válido para el envío de prueba.', 'atora-lms' ) );
		}
		if ( ! $subject ) {
			return self::fail( __( 'El asunto es obligatorio para el envío de prueba.', 'atora-lms' ) );
		}
		if ( ! $message ) {
			return self::fail( __( 'El mensaje no puede estar vacío.', 'atora-lms' ) );
		}

		$sent = Campaign_Service::send_test( $email, $subject, $message, $cta_url, $identity );

		if ( ! $sent ) {
			return self::fail( __( 'No se pudo enviar el email de prueba. Verifica la configuración de correo.', 'atora-lms' ) );
		}

		return self::ok( sprintf( __( 'Email de prueba enviado a %s.', 'atora-lms' ), $email ) );
	}

	/* ─── GET /campaigns ────────────────────────────────────────── */

	public static function list_campaigns( \WP_REST_Request $request ): \WP_REST_Response {
		$limit  = absint( $request->get_param( 'limit' ) ?: 30 );
		$rows   = Campaign_Service::get_campaigns( $limit );
		$items  = array_map( array( __CLASS__, 'format_campaign' ), $rows );

		return self::ok( '', array( 'campaigns' => $items, 'total' => count( $items ) ) );
	}

	/* ─── POST /campaigns ───────────────────────────────────────── */

	public static function create_campaign( \WP_REST_Request $request ): \WP_REST_Response {
		$data = self::parse_campaign_body( $request );

		if ( '' === $data['name'] ) {
			return self::fail( __( 'El nombre de la campaña es obligatorio.', 'atora-lms' ) );
		}
		if ( '' === $data['subject'] ) {
			return self::fail( __( 'El asunto es obligatorio.', 'atora-lms' ) );
		}
		if ( '' === $data['message'] ) {
			return self::fail( __( 'El mensaje no puede estar vacío.', 'atora-lms' ) );
		}

		$campaign_id = Campaign_Service::create_campaign( $data );

		if ( ! $campaign_id ) {
			return self::fail( __( 'No se pudo crear la campaña. Inténtalo de nuevo.', 'atora-lms' ), 500 );
		}

		$row      = Campaign_Service::get_campaign( $campaign_id );
		$audience = self::count_audience( $data );

		return self::ok(
			__( 'Campaña creada como borrador. Revisa la audiencia antes de lanzar.', 'atora-lms' ),
			array(
				'campaign' => self::format_campaign( $row ),
				'audience' => $audience,
			)
		);
	}

	/* ─── GET /campaigns/{id} ───────────────────────────────────── */

	public static function get_campaign( \WP_REST_Request $request ): \WP_REST_Response {
		$campaign_id = absint( $request->get_param( 'campaign_id' ) );
		$row         = Campaign_Service::get_campaign( $campaign_id );

		if ( empty( $row ) ) {
			return self::fail( __( 'Campaña no encontrada.', 'atora-lms' ), 404 );
		}

		$formatted = self::format_campaign( $row );
		$audience  = self::count_audience( array(
			'search'    => $formatted['search'],
			'status'    => $formatted['status_filter'],
			'tag'       => $formatted['tag'],
			'course_id' => $formatted['course_id'],
		) );

		return self::ok( '', array( 'campaign' => $formatted, 'audience' => $audience ) );
	}

	/* ─── POST /campaigns/{id} (actualizar borrador) ────────────── */

	public static function update_campaign( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$campaign_id = absint( $request->get_param( 'campaign_id' ) );
		$existing    = Campaign_Service::get_campaign( $campaign_id );

		if ( empty( $existing ) ) {
			return self::fail( __( 'Campaña no encontrada.', 'atora-lms' ), 404 );
		}

		// Solo se pueden editar borradores o pausadas
		$status = sanitize_key( (string) ( $existing['status'] ?? 'draft' ) );
		if ( ! in_array( $status, array( 'draft', 'paused', 'simulated' ), true ) ) {
			return self::fail( __( 'Solo se pueden editar campañas en estado borrador, pausada o simulada.', 'atora-lms' ) );
		}

		$data    = self::parse_campaign_body( $request );
		$segment = array(
			'audience'  => $data['audience'],
			'status'    => $data['status'],
			'tag'       => $data['tag'],
			'course_id' => $data['course_id'],
			'search'    => $data['search'],
			'identity'  => $data['identity'],
		);

		// Normalizar scheduled_at a UTC
		$scheduled_utc = '';
		if ( $data['scheduled_at'] ) {
			$dt = date_create( $data['scheduled_at'], wp_timezone() );
			if ( $dt ) {
				$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
				$scheduled_utc = $dt->format( 'Y-m-d H:i:s' );
			}
		}

		$table   = $wpdb->prefix . 'atora_crm_campaigns';
		$updated = $wpdb->update(
			$table,
			array(
				'name'           => $data['name']           ?: $existing['name'],
				'template_key'   => $data['template_key'],
				'channel'        => $data['channel'],
				'execution_mode' => $data['execution_mode'],
				'subject'        => $data['subject']        ?: $existing['subject'],
				'message'        => ( ! empty( $data['blocks_json'] ) && ( is_array( $data['blocks_json'] ) || '[' === substr( trim( (string) $data['blocks_json'] ), 0, 1 ) ) )
					? wp_json_encode( is_array( $data['blocks_json'] ) ? $data['blocks_json'] : json_decode( (string) $data['blocks_json'], true ) )
					: ( $data['message'] ?: $existing['message'] ),
				'cta_url'        => $data['cta_url'],
				'segment_json'   => wp_json_encode( $segment ),
				'scheduled_at'   => $scheduled_utc ?: null,
			),
			array( 'id' => $campaign_id ),
			array( '%s','%s','%s','%s','%s','%s','%s','%s','%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return self::fail( __( 'No se pudo actualizar la campaña.', 'atora-lms' ), 500 );
		}

		// Reconstruir destinatarios si cambió el segmento
		$old_seg = json_decode( (string) ( $existing['segment_json'] ?? '{}' ), true );
		$old_seg = is_array( $old_seg ) ? $old_seg : array();
		$seg_changed = ( wp_json_encode( $old_seg ) !== wp_json_encode( $segment ) );

		if ( $seg_changed ) {
			Campaign_Service::rebuild_recipients( $campaign_id );
		}

		$row      = Campaign_Service::get_campaign( $campaign_id );
		$audience = self::count_audience( $segment );

		return self::ok(
			__( 'Campaña actualizada.', 'atora-lms' ),
			array( 'campaign' => self::format_campaign( $row ), 'audience' => $audience )
		);
	}

	/* ─── POST /campaigns/{id}/launch ──────────────────────────── */

	public static function launch_campaign( \WP_REST_Request $request ): \WP_REST_Response {
		$campaign_id = absint( $request->get_param( 'campaign_id' ) );
		$b           = $request->get_json_params() ?: array();
		$mode        = sanitize_key( (string) ( $b['mode'] ?? 'simulate' ) );

		if ( ! in_array( $mode, array( 'simulate', 'queue' ), true ) ) {
			$mode = 'simulate';
		}

		$existing = Campaign_Service::get_campaign( $campaign_id );
		if ( empty( $existing ) ) {
			return self::fail( __( 'Campaña no encontrada.', 'atora-lms' ), 404 );
		}

		$status = sanitize_key( (string) ( $existing['status'] ?? 'draft' ) );
		if ( in_array( $status, array( 'queued', 'sent' ), true ) ) {
			return self::fail( __( 'Esta campaña ya fue enviada o está encolada. Clónala para reutilizarla.', 'atora-lms' ) );
		}

		$result = Campaign_Service::launch_campaign( $campaign_id, $mode );

		if ( empty( $result['success'] ) ) {
			return self::fail( (string) ( $result['message'] ?? __( 'No se pudo lanzar la campaña.', 'atora-lms' ) ) );
		}

		$row = Campaign_Service::get_campaign( $campaign_id );

		return self::ok(
			(string) ( $result['message'] ?? '' ),
			array(
				'campaign'  => self::format_campaign( $row ),
				'queued'    => absint( $result['queued']    ?? 0 ),
				'simulated' => absint( $result['simulated'] ?? 0 ),
				'skipped'   => absint( $result['skipped']   ?? 0 ),
			)
		);
	}

	/**
	 * GET /campaigns/{id}/metrics
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_metrics( \WP_REST_Request $request ): \WP_REST_Response {
		$campaign_id = absint( $request->get_param( 'campaign_id' ) );
		if ( $campaign_id <= 0 ) {
			return self::fail( __( 'Campaña inválida.', 'atora-lms' ), 400 );
		}

		return self::ok(
			'',
			array(
				'metrics' => Campaign_Service::get_campaign_metrics( $campaign_id ),
			)
		);
	}

	/* ─── POST /campaigns/{id}/clone ───────────────────────────── */

	public static function clone_campaign( \WP_REST_Request $request ): \WP_REST_Response {
		$campaign_id = absint( $request->get_param( 'campaign_id' ) );
		$original    = Campaign_Service::get_campaign( $campaign_id );

		if ( empty( $original ) ) {
			return self::fail( __( 'Campaña no encontrada.', 'atora-lms' ), 404 );
		}

		$segment = json_decode( (string) ( $original['segment_json'] ?? '{}' ), true );
		$segment = is_array( $segment ) ? $segment : array();

		$new_id = Campaign_Service::create_campaign( array(
			'name'           => sprintf( __( 'Copia de %s', 'atora-lms' ), sanitize_text_field( (string) $original['name'] ) ),
			'template_key'   => sanitize_key(    (string) ( $original['template_key']   ?? '' ) ),
			'channel'        => sanitize_key(    (string) ( $original['channel']        ?? 'email' ) ),
			'execution_mode' => sanitize_key(    (string) ( $original['execution_mode'] ?? 'simulate' ) ),
			'subject'        => sanitize_text_field( (string) ( $original['subject']    ?? '' ) ),
			'message'        => sanitize_textarea_field( (string) ( $original['message'] ?? '' ) ),
			'cta_url'        => esc_url_raw( (string) ( $original['cta_url']            ?? '' ) ),
			'scheduled_at'   => '',  // la copia no hereda fecha
			'audience'       => sanitize_key(    (string) ( $segment['audience']        ?? 'all' ) ),
			'status'         => sanitize_key(    (string) ( $segment['status']          ?? '' ) ),
			'tag'            => sanitize_text_field( (string) ( $segment['tag']         ?? '' ) ),
			'course_id'      => absint( $segment['course_id'] ?? 0 ),
			'search'         => sanitize_text_field( (string) ( $segment['search']      ?? '' ) ),
			'identity'       => sanitize_key(    (string) ( $segment['identity']        ?? '' ) ),
		) );

		if ( ! $new_id ) {
			return self::fail( __( 'No se pudo clonar la campaña.', 'atora-lms' ), 500 );
		}

		$row = Campaign_Service::get_campaign( $new_id );

		return self::ok(
			__( 'Campaña clonada como borrador. Ya puedes editarla.', 'atora-lms' ),
			array( 'campaign' => self::format_campaign( $row ) )
		);
	}

	/* ─── POST /campaigns/{id}/pause ───────────────────────────── */

	public static function pause_campaign( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$campaign_id = absint( $request->get_param( 'campaign_id' ) );
		$existing    = Campaign_Service::get_campaign( $campaign_id );

		if ( empty( $existing ) ) {
			return self::fail( __( 'Campaña no encontrada.', 'atora-lms' ), 404 );
		}

		$status = sanitize_key( (string) ( $existing['status'] ?? 'draft' ) );
		if ( ! in_array( $status, array( 'scheduled', 'draft' ), true ) ) {
			return self::fail( __( 'Solo se pueden pausar campañas programadas o en borrador.', 'atora-lms' ) );
		}

		$table   = $wpdb->prefix . 'atora_crm_campaigns';
		$updated = $wpdb->update(
			$table,
			array( 'status' => 'paused' ),
			array( 'id'     => $campaign_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return self::fail( __( 'No se pudo pausar la campaña.', 'atora-lms' ), 500 );
		}

		$row = Campaign_Service::get_campaign( $campaign_id );

		return self::ok(
			__( 'Campaña pausada. Puedes reactivarla desde el builder.', 'atora-lms' ),
			array( 'campaign' => self::format_campaign( $row ) )
		);
	}
}
