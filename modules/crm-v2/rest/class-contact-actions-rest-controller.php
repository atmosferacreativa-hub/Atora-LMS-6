<?php
/**
 * REST Controller — Acciones de la ficha 360 (Fase 4)
 *
 * Expone las acciones accionables desde la ficha 360 del contacto:
 *   POST /atora-crm/v2/contacts/{id}/email    → enqueue email al contacto
 *   POST /atora-crm/v2/contacts/{id}/tag      → añadir etiqueta
 *   POST /atora-crm/v2/contacts/{id}/stage    → mover de etapa en pipeline
 *   GET  /atora-crm/v2/contacts/{id}/timeline → timeline paginado con filtro
 *   GET  /atora-crm/v2/contacts/autocomplete  → búsqueda rápida para lista lateral
 *   GET  /atora-crm/v2/contacts/tags/suggest  → sugerencias de tags para autocomplete
 *
 * @package ATORA_LMS\CRM_V2\Rest
 * @since   5.28.0
 */

namespace ATORA\CRM_V2\Rest;

use ATORA\CRM_V2\Services\Contact_Service;
use ATORA\CRM_V2\Services\Activity_Service;
use ATORA\CRM_V2\Services\CRM_Email_Service;
use ATORA\CRM_V2\Services\Deal_Service;
use ATORA\CRM_V2\Services\Student_Followup_Service;
use ATORA\CRM_V2\Services\Task_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Contact_Actions_REST_Controller {

	const REST_NAMESPACE = 'atora-crm/v2';

	public static function register_routes(): void {
		$ns  = self::REST_NAMESPACE;
		$can = array( 'ATORA\CRM_V2\Rest\CRM_REST_Controller', 'can_access' );

		register_rest_route( $ns, '/contacts/autocomplete', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'autocomplete' ),
			'permission_callback' => $can,
		) );

		register_rest_route( $ns, '/contacts/tags/suggest', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'suggest_tags' ),
			'permission_callback' => $can,
		) );

		register_rest_route( $ns, '/contacts/(?P<contact_id>\d+)/email', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'send_email' ),
			'permission_callback' => $can,
		) );

		register_rest_route( $ns, '/contacts/(?P<contact_id>\d+)/tag', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'add_tag' ),
			'permission_callback' => $can,
		) );

		register_rest_route( $ns, '/contacts/(?P<contact_id>\d+)/stage', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'move_stage' ),
			'permission_callback' => $can,
		) );

		register_rest_route( $ns, '/contacts/(?P<contact_id>\d+)/timeline', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_timeline' ),
			'permission_callback' => $can,
		) );

		// Fase II S5 — IA: resumen del contacto
		register_rest_route( $ns, '/contacts/(?P<contact_id>\d+)/ai-summary', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'ai_summary' ),
			'permission_callback' => $can,
		) );
	}

	/* ─── Helpers ──────────────────────────────────────────────── */

	private static function ok( string $message, array $data = array() ): \WP_REST_Response {
		return new \WP_REST_Response( array_merge( array( 'success' => true, 'message' => $message ), $data ), 200 );
	}

	private static function fail( string $message, int $status = 400 ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'success' => false, 'message' => $message ), $status );
	}

	private static function guard_contact( int $contact_id ): ?\WP_REST_Response {
		if ( ! $contact_id ) {
			return self::fail( __( 'ID de contacto no válido.', 'atora-lms' ) );
		}
		if ( ! CRM_REST_Controller::contact_id_is_visible( $contact_id ) ) {
			return self::fail( __( 'No tienes permisos para este contacto.', 'atora-lms' ), 403 );
		}
		return null;
	}

	/* ─── GET /contacts/autocomplete ───────────────────────────── */

	public static function autocomplete( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$search = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$limit  = absint( $request->get_param( 'limit' ) ?: 15 );
		$limit  = max( 1, min( 30, $limit ) );

		if ( strlen( $search ) < 2 ) {
			return self::ok( '', array( 'contacts' => array() ) );
		}

		$table = $wpdb->prefix . 'atora_contacts';
		$like  = '%' . $wpdb->esc_like( $search ) . '%';

		$scope_ids = CRM_REST_Controller::has_global_scope()
			? array()
			: CRM_REST_Controller::get_scope_user_ids();

		$where  = 'WHERE (name LIKE %s OR email LIKE %s OR phone LIKE %s)';
		$params = array( $like, $like, $like );

		if ( ! empty( $scope_ids ) ) {
			$in      = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
			$where  .= " AND user_id IN ({$in})";
			$params  = array_merge( $params, $scope_ids );
		}

		$params[] = $limit;
		$rows     = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT id, name, email, phone, status FROM {$table} {$where} ORDER BY name ASC LIMIT %d", ...$params ),
			ARRAY_A
		);

		$contacts = array_map( function ( array $row ) {
			return array(
				'id'     => absint( $row['id'] ),
				'name'   => sanitize_text_field( (string) ( $row['name']   ?? '' ) ),
				'email'  => sanitize_email(      (string) ( $row['email']  ?? '' ) ),
				'phone'  => sanitize_text_field( (string) ( $row['phone']  ?? '' ) ),
				'status' => sanitize_key(        (string) ( $row['status'] ?? '' ) ),
			);
		}, $rows );

		return self::ok( '', array( 'contacts' => $contacts ) );
	}

	/* ─── GET /contacts/tags/suggest ───────────────────────────── */

	public static function suggest_tags( \WP_REST_Request $request ): \WP_REST_Response {
		$search = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$tags   = Contact_Service::suggest_tags( $search, 25 );
		return self::ok( '', array( 'tags' => $tags ) );
	}

	/* ─── POST /contacts/{id}/email ────────────────────────────── */

	public static function send_email( \WP_REST_Request $request ): \WP_REST_Response {
		$contact_id = absint( $request->get_param( 'contact_id' ) );
		$guard      = self::guard_contact( $contact_id );
		if ( $guard ) { return $guard; }
		// Cap de envío (Fase 5)
		if ( ! current_user_can( 'crm_send_email' ) && ! current_user_can( 'clms_manage_crm' ) && ! current_user_can( 'manage_options' ) ) {
			return self::fail( __( 'No tienes permiso para enviar emails desde el CRM.', 'atora-lms' ), 403 );
		}

		$b        = $request->get_json_params() ?: array();
		$subject  = sanitize_text_field(     (string) ( $b['subject']  ?? '' ) );
		$message  = sanitize_textarea_field( (string) ( $b['message']  ?? '' ) );
		$cta_url  = esc_url_raw(             (string) ( $b['cta_url']  ?? '' ) );
		$identity = sanitize_key(            (string) ( $b['identity'] ?? 'teacher' ) );

		if ( '' === $subject ) {
			return self::fail( __( 'El asunto es obligatorio.', 'atora-lms' ) );
		}
		if ( '' === $message ) {
			return self::fail( __( 'El mensaje no puede estar vacío.', 'atora-lms' ) );
		}

		global $wpdb;
		$contact = (array) $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_contacts WHERE id = %d LIMIT 1", $contact_id ),
			ARRAY_A
		);

		if ( empty( $contact ) ) {
			return self::fail( __( 'Contacto no encontrado.', 'atora-lms' ), 404 );
		}

		$to = sanitize_email( (string) ( $contact['email'] ?? '' ) );
		if ( '' === $to || ! is_email( $to ) ) {
			return self::fail( __( 'El contacto no tiene email válido.', 'atora-lms' ) );
		}

		if ( ! class_exists( '\ATORA\CRM_V2\Services\CRM_Email_Service' ) ) {
			return self::fail( __( 'Email Engine no disponible.', 'atora-lms' ), 503 );
		}

		$result = CRM_Email_Service::enqueue_inbox_reply(
			$to,
			$subject,
			$message,
			$identity,
			array( 'contact_id' => $contact_id, 'cta_url' => $cta_url )
		);

		if ( empty( $result['success'] ) ) {
			return self::fail( (string) ( $result['message'] ?? __( 'No se pudo encolar el email.', 'atora-lms' ) ) );
		}

		Activity_Service::log_contact_activity(
			$contact_id,
			'email_sent',
			array( 'subject' => $subject, 'identity' => $identity ),
			0,
			'email_queue',
			absint( $result['queue_id'] ?? 0 )
		);

		Contact_Service::touch_last_activity( $contact_id );

		return self::ok( __( 'Email encolado correctamente.', 'atora-lms' ) );
	}

	/* ─── POST /contacts/{id}/tag ───────────────────────────────── */

	public static function add_tag( \WP_REST_Request $request ): \WP_REST_Response {
		$contact_id = absint( $request->get_param( 'contact_id' ) );
		$guard      = self::guard_contact( $contact_id );
		if ( $guard ) { return $guard; }

		$b        = $request->get_json_params() ?: array();
		$tag_name = sanitize_text_field( (string) ( $b['tag'] ?? '' ) );

		if ( '' === $tag_name ) {
			return self::fail( __( 'El nombre del tag no puede estar vacío.', 'atora-lms' ) );
		}

		$added = Contact_Service::add_tag( $contact_id, $tag_name );

		if ( ! $added ) {
			return self::fail( __( 'No se pudo añadir el tag.', 'atora-lms' ) );
		}

		Contact_Service::touch_last_activity( $contact_id );

		global $wpdb;
		$contact_tags = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT tag_name FROM {$wpdb->prefix}atora_contact_tags WHERE contact_id = %d ORDER BY tag_name ASC",
				$contact_id
			)
		);

		return self::ok(
			__( 'Tag añadido al contacto.', 'atora-lms' ),
			array( 'tags' => array_values( array_map( 'sanitize_text_field', $contact_tags ) ) )
		);
	}

	/* ─── POST /contacts/{id}/stage ─────────────────────────────── */

	public static function move_stage( \WP_REST_Request $request ): \WP_REST_Response {
		$contact_id = absint( $request->get_param( 'contact_id' ) );
		$guard      = self::guard_contact( $contact_id );
		if ( $guard ) { return $guard; }

		$b         = $request->get_json_params() ?: array();
		$pipeline  = sanitize_key( (string) ( $b['pipeline'] ?? 'sales' ) );
		$new_stage = sanitize_key( (string) ( $b['stage']    ?? '' ) );

		if ( '' === $new_stage ) {
			return self::fail( __( 'La etapa de destino es obligatoria.', 'atora-lms' ) );
		}

		if ( ! in_array( $pipeline, array( 'sales', 'academic' ), true ) ) {
			$pipeline = 'sales';
		}

		global $wpdb;

		if ( 'sales' === $pipeline ) {
			$valid_stages = array_keys( Deal_Service::get_stages() );
			if ( ! in_array( $new_stage, $valid_stages, true ) ) {
				return self::fail( __( 'Etapa de pipeline de ventas no válida.', 'atora-lms' ) );
			}

			$table = $wpdb->prefix . 'atora_crm_deals';
			if ( ! \ATORA\CRM_V2\Services\DB_Service::table_exists( $table ) ) {
				return self::fail( __( 'Tabla de pipeline no disponible.', 'atora-lms' ), 503 );
			}

			$deal_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE contact_id = %d ORDER BY updated_at DESC LIMIT 1", $contact_id )
			);

			if ( ! $deal_id ) {
				return self::fail( __( 'No hay deal activo para este contacto en el pipeline de ventas.', 'atora-lms' ) );
			}

			$wpdb->update( $table, array( 'stage' => $new_stage ), array( 'id' => $deal_id ), array( '%s' ), array( '%d' ) );

		} else {
			$valid_stages = array_keys( Student_Followup_Service::get_stages() );
			if ( ! in_array( $new_stage, $valid_stages, true ) ) {
				return self::fail( __( 'Etapa de pipeline académico no válida.', 'atora-lms' ) );
			}

			$table = $wpdb->prefix . 'atora_crm_student_followups';
			if ( ! \ATORA\CRM_V2\Services\DB_Service::table_exists( $table ) ) {
				return self::fail( __( 'Tabla de seguimiento académico no disponible.', 'atora-lms' ), 503 );
			}

			$user_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}atora_contacts WHERE id = %d LIMIT 1", $contact_id )
			);

			if ( ! $user_id ) {
				return self::fail( __( 'El contacto no tiene usuario vinculado para el pipeline académico.', 'atora-lms' ) );
			}

			$followup_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC LIMIT 1", $user_id )
			);

			if ( ! $followup_id ) {
				return self::fail( __( 'No hay seguimiento activo para este estudiante.', 'atora-lms' ) );
			}

			$wpdb->update( $table, array( 'stage' => $new_stage ), array( 'id' => $followup_id ), array( '%s' ), array( '%d' ) );
		}

		Activity_Service::log_contact_activity(
			$contact_id,
			'stage_changed',
			array( 'pipeline' => $pipeline, 'stage' => $new_stage ),
			0,
			'sales' === $pipeline ? 'deal' : 'followup',
			0
		);

		Contact_Service::touch_last_activity( $contact_id );

		// Emitir hook para Automation Engine (Fase 7)
		do_action( 'atora/crm/deal_stage_changed', 0, $new_stage, $contact_id, get_current_user_id() );

		return self::ok(
			__( 'Contacto movido a la nueva etapa.', 'atora-lms' ),
			array( 'pipeline' => $pipeline, 'stage' => $new_stage )
		);
	}

	/* ─── GET /contacts/{id}/timeline ──────────────────────────── */

	public static function get_timeline( \WP_REST_Request $request ): \WP_REST_Response {
		$contact_id = absint( $request->get_param( 'contact_id' ) );
		$guard      = self::guard_contact( $contact_id );
		if ( $guard ) { return $guard; }

		global $wpdb;

		$type_filter = sanitize_key( (string) $request->get_param( 'type' ) );
		$limit       = absint( $request->get_param( 'limit' ) ?: 30 );
		$offset      = absint( $request->get_param( 'offset' ) ?: 0 );
		$limit       = max( 1, min( 100, $limit ) );

		$table  = $wpdb->prefix . 'atora_contact_activities';
		$params = array( $contact_id );
		$where  = 'WHERE contact_id = %d';

		if ( '' !== $type_filter ) {
			$where   .= ' AND activity_type = %s';
			$params[] = $type_filter;
		}

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", ...$params )
		);

		$params[] = $limit;
		$params[] = $offset;
		$rows     = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", ...$params ),
			ARRAY_A
		);

		$user_ids = array_unique( array_filter( array_map( 'absint', array_column( $rows, 'created_by' ) ) ) );
		$users    = array();
		if ( ! empty( $user_ids ) ) {
			foreach ( $user_ids as $uid ) {
				$u = get_userdata( $uid );
				if ( $u ) {
					$users[ $uid ] = sanitize_text_field( $u->display_name );
				}
			}
		}

		$items = array_map( function ( array $row ) use ( $users ) {
			$data = json_decode( (string) ( $row['activity_data'] ?? '{}' ), true );
			return array(
				'id'            => absint( $row['id'] ),
				'activity_type' => sanitize_key(        (string) ( $row['activity_type'] ?? '' ) ),
				'activity_data' => is_array( $data ) ? $data : array(),
				'created_by'    => absint(              $row['created_by'] ?? 0 ),
				'author_name'   => sanitize_text_field( (string) ( $users[ absint( $row['created_by'] ?? 0 ) ] ?? '' ) ),
				'created_at'    => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
				'linked_type'   => sanitize_key(        (string) ( $row['linked_entity_type'] ?? '' ) ),
				'linked_id'     => absint(              $row['linked_entity_id'] ?? 0 ),
			);
		}, $rows );

		return self::ok( '', array( 'items' => $items, 'total' => $total ) );
	}

	/* ─── POST /contacts/{id}/ai-summary (Fase II S5) ──────────────── */

	public static function ai_summary( \WP_REST_Request $r ): \WP_REST_Response {
		$contact_id = absint( $r->get_param( 'contact_id' ) );
		$guard      = self::guard_contact( $contact_id );
		if ( $guard ) { return $guard; }

		global $wpdb;
		$contact = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT name, email, status, contact_type, total_points, life_time_value, conversion_score
				 FROM {$wpdb->prefix}atora_contacts WHERE id = %d LIMIT 1",
				$contact_id
			),
			ARRAY_A
		);

		if ( empty( $contact ) ) {
			return self::fail( __( 'Contacto no encontrado.', 'atora-lms' ), 404 );
		}

		// Contexto del contacto para la IA
		$score_label = '';
		if ( class_exists( '\ATORA\CRM_V2\Services\Scoring_Service' ) ) {
			$score_label = \ATORA\CRM_V2\Services\Scoring_Service::get_score_label(
				absint( $contact['conversion_score'] ?? 0 )
			);
		}

		$prompt = sprintf(
			"Eres un asistente de CRM. Resume en 3-4 líneas el perfil de este contacto:\n" .
			"Nombre: %s\nEmail: %s\nEstado: %s\nTipo: %s\n" .
			"Puntos: %d\nLTV: $%s\nScore conversión: %d/100 (%s)\n" .
			"Sé conciso, en español, enfocado en oportunidades de conversión.",
			sanitize_text_field( (string) ( $contact['name']            ?? '' ) ),
			sanitize_email(      (string) ( $contact['email']           ?? '' ) ),
			sanitize_key(        (string) ( $contact['status']          ?? '' ) ),
			sanitize_key(        (string) ( $contact['contact_type']    ?? '' ) ),
			absint(                        $contact['total_points']     ?? 0 ),
			number_format( (float) ( $contact['life_time_value'] ?? 0 ), 2 ),
			absint(                        $contact['conversion_score'] ?? 0 ),
			$score_label
		);

		$summary = '';
		if ( class_exists( 'CLMS_AI_Manager' ) ) {
			try {
				$ai      = new CLMS_AI_Manager();
				$summary = $ai->complete( $prompt );
			} catch ( \Throwable $e ) {
				return self::fail( __( 'Error IA: ', 'atora-lms' ) . esc_html( $e->getMessage() ), 500 );
			}
		} else {
			// Fallback sin IA: resumen estructurado
			$summary = sprintf(
				"Contacto: %s (%s) — Estado: %s. Score: %d/100 (%s). LTV: $%s. Sin motor IA configurado.",
				sanitize_text_field( (string) ( $contact['name'] ?? '' ) ),
				sanitize_email( (string) ( $contact['email'] ?? '' ) ),
				sanitize_key( (string) ( $contact['status'] ?? '' ) ),
				absint( $contact['conversion_score'] ?? 0 ),
				$score_label,
				number_format( (float) ( $contact['life_time_value'] ?? 0 ), 2 )
			);
		}

		return self::ok( '', array( 'summary' => sanitize_textarea_field( $summary ) ) );
	}
}
