<?php
/**
 * REST de bandeja CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Rest;

use ATORA\CRM_V2\Services\Contact_Service;
use ATORA\CRM_V2\Services\CRM_Email_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Inbox_REST_Controller {
	/**
	 * Registra rutas de inbox.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$ns = CRM_REST_Controller::REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/inbox/conversations',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_conversations' ),
				'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_access' ),
			)
		);

		register_rest_route(
			$ns,
			'/inbox/conversations/(?P<conversation_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_conversation' ),
				'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_access' ),
			)
		);

		register_rest_route(
			$ns,
			'/inbox/reply-email',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'reply_email' ),
				'permission_callback' => array( __CLASS__, 'can_send_email' ),
			)
		);
	}

	/**
	 * PT-2.3 (6.5.1): can_access no basta para disparar correo en nombre
	 * de la institución — exige además crm_send_email, la misma
	 * capability que ya gatea el envío individual desde la ficha 360
	 * (Contact_Actions_REST_Controller::send_email()). Se reutiliza en
	 * vez de crear una capability nueva para la misma acción.
	 *
	 * @return bool
	 */
	public static function can_send_email(): bool {
		if ( ! CRM_REST_Controller::can_access() ) {
			return false;
		}
		return current_user_can( 'crm_send_email' ) || current_user_can( 'clms_manage_crm' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Lista conversaciones.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function list_conversations( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_conversations';
		if ( ! class_exists( '\\ATORA\\CRM_V2\\Services\\DB_Service' ) || ! \ATORA\CRM_V2\Services\DB_Service::table_exists( $table ) ) {
			return rest_ensure_response( array( 'success' => true, 'items' => array() ) );
		}

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$limit  = max( 1, min( 120, absint( $request->get_param( 'limit' ) ?: 60 ) ) );

		$where  = '1=1';
		$params = array();
		$scope_user_ids = CRM_REST_Controller::get_scope_user_ids();
		if ( ! CRM_REST_Controller::has_global_scope() ) {
			$scope_user_ids = array_values( array_filter( array_map( 'absint', $scope_user_ids ) ) );
			if ( empty( $scope_user_ids ) ) {
				return rest_ensure_response( array( 'success' => true, 'items' => array() ) );
			}
			$in      = implode( ',', array_fill( 0, count( $scope_user_ids ), '%d' ) );
			$where  .= " AND (c.user_id IN ({$in}) OR ct.user_id IN ({$in}))";
			foreach ( $scope_user_ids as $scope_user_id ) {
				$params[] = $scope_user_id;
			}
			foreach ( $scope_user_ids as $scope_user_id ) {
				$params[] = $scope_user_id;
			}
		}

		if ( '' !== $status ) {
			$where   .= ' AND c.status = %s';
			$params[] = $status;
		}

		$sql = "SELECT c.*, ct.name AS contact_name, ct.email AS contact_email
			FROM {$table} c
			LEFT JOIN {$wpdb->prefix}atora_contacts ct ON ct.id = c.contact_id
			WHERE {$where}
			ORDER BY c.last_message_at DESC, c.updated_at DESC
			LIMIT %d";
		$params[] = $limit;

		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return rest_ensure_response( array( 'success' => true, 'items' => $rows ) );
	}

	/**
	 * Obtiene mensajes de una conversación.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_conversation( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$conversation_id = absint( $request->get_param( 'conversation_id' ) );
		if ( ! $conversation_id ) {
			return rest_ensure_response( array( 'success' => false, 'message' => __( 'Conversación no válida.', 'atora-lms' ) ) );
		}
		if ( ! CRM_REST_Controller::conversation_id_is_visible( $conversation_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'No tienes permisos para esta conversación.', 'atora-lms' ),
				),
				403
			);
		}

		$conv_table = $wpdb->prefix . 'atora_conversations';
		$msg_table  = $wpdb->prefix . 'atora_conversation_messages';
		$conversation = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$conv_table} WHERE id = %d LIMIT 1", $conversation_id ), ARRAY_A );
		$messages     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$msg_table} WHERE conversation_id = %d ORDER BY created_at ASC LIMIT 300", $conversation_id ), ARRAY_A );

		$contact = array();
		if ( ! empty( $conversation['contact_id'] ) ) {
			$contact = Contact_Service::get_contact_360( absint( $conversation['contact_id'] ) );
		}

		return rest_ensure_response(
			array(
				'success'      => true,
				'conversation' => $conversation,
				'messages'     => $messages,
				'contact'      => $contact,
			)
		);
	}

	/**
	 * Respuesta por email desde inbox.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function reply_email( \WP_REST_Request $request ): \WP_REST_Response {
		$to              = sanitize_email( (string) $request->get_param( 'to' ) );
		$subject         = sanitize_text_field( (string) $request->get_param( 'subject' ) );
		$message         = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$contact_id      = absint( $request->get_param( 'contact_id' ) );
		$conversation_id = absint( $request->get_param( 'conversation_id' ) );

		// PT-2.1 (6.5.1): sin contact_id ni conversation_id, no hay nada
		// que verificar — antes esto se saltaba las dos comprobaciones de
		// abajo y dejaba pasar cualquier destinatario.
		if ( ! $contact_id && ! $conversation_id ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Indica un contacto o una conversación para responder.', 'atora-lms' ),
				),
				400
			);
		}

		if ( $conversation_id && ! CRM_REST_Controller::conversation_id_is_visible( $conversation_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'No tienes permisos para esta conversación.', 'atora-lms' ),
				),
				403
			);
		}
		if ( $contact_id && ! CRM_REST_Controller::contact_id_is_visible( $contact_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'No tienes permisos para este contacto.', 'atora-lms' ),
				),
				403
			);
		}

		// PT-2.2 (6.5.1): el destinatario se deriva del contacto/
		// conversación en el servidor — nunca se confía en el parámetro
		// `to` para decidir a dónde se envía. Si `to` llegó y no coincide,
		// es una señal de mal uso: se rechaza en vez de enviarlo igual.
		// Si solo llegó conversation_id, aprovechamos para completar
		// contact_id con el de la conversación (para el log de actividad
		// más abajo).
		if ( ! $contact_id && $conversation_id ) {
			$contact_id = self::contact_id_for_conversation( $conversation_id );
		}
		$resolved_email = self::resolve_recipient_email( $contact_id, $conversation_id );
		if ( '' === $resolved_email ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'No se pudo determinar el email del contacto.', 'atora-lms' ),
				),
				404
			);
		}
		if ( '' !== $to && strtolower( $to ) !== strtolower( $resolved_email ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'El destinatario indicado no coincide con el email registrado del contacto.', 'atora-lms' ),
				),
				409
			);
		}
		$to = $resolved_email;

		if ( '' === $to || ! is_email( $to ) || '' === $message ) {
			return rest_ensure_response( array( 'success' => false, 'message' => __( 'Datos incompletos para enviar respuesta.', 'atora-lms' ) ) );
		}
		$identity = sanitize_key( (string) $request->get_param( 'identity' ) );
		$result   = CRM_Email_Service::enqueue_inbox_reply(
			$to,
			$subject ?: __( 'Seguimiento ATORA', 'atora-lms' ),
			$message,
			$identity ?: 'teacher',
			array(
				'contact_id'      => $contact_id,
				'conversation_id' => $conversation_id,
			)
		);
		$sent = ! empty( $result['success'] );

		return rest_ensure_response(
			array(
				'success'  => $sent,
				'status'   => sanitize_key( (string) ( $result['status'] ?? ( $sent ? 'queued' : 'error' ) ) ),
				'queue_id' => absint( $result['queue_id'] ?? 0 ),
				'message'  => sanitize_text_field(
					(string) (
						$result['message']
						?? ( $sent ? __( 'Respuesta encolada.', 'atora-lms' ) : __( 'No fue posible enviar la respuesta.', 'atora-lms' ) )
					)
				),
			)
		);
	}

	/**
	 * PT-2.2 (6.5.1): email real del contacto o de la conversación,
	 * resuelto en el servidor. `contact_id` tiene prioridad si llegan
	 * los dos.
	 *
	 * @param int $contact_id
	 * @param int $conversation_id
	 * @return string Email en minúsculas, o '' si no se pudo resolver.
	 */
	private static function resolve_recipient_email( int $contact_id, int $conversation_id ): string {
		global $wpdb;

		if ( $contact_id ) {
			$email = (string) $wpdb->get_var(
				$wpdb->prepare( "SELECT email FROM {$wpdb->prefix}atora_contacts WHERE id = %d LIMIT 1", $contact_id )
			);
			return sanitize_email( $email );
		}

		if ( $conversation_id ) {
			$email = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ct.email
					 FROM {$wpdb->prefix}atora_conversations c
					 LEFT JOIN {$wpdb->prefix}atora_contacts ct ON ct.id = c.contact_id
					 WHERE c.id = %d
					 LIMIT 1",
					$conversation_id
				)
			);
			return sanitize_email( $email );
		}

		return '';
	}

	/**
	 * @param int $conversation_id
	 * @return int contact_id vinculado, o 0.
	 */
	private static function contact_id_for_conversation( int $conversation_id ): int {
		global $wpdb;

		return absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT contact_id FROM {$wpdb->prefix}atora_conversations WHERE id = %d LIMIT 1",
					$conversation_id
				)
			)
		);
	}
}
