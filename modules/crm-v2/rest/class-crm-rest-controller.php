<?php
/**
 * REST base CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Rest;

use ATORA\CRM_V2\Services\Contact_Service;
use ATORA\CRM_V2\Services\CRM_Email_Service;
use ATORA\CRM_V2\Services\Report_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CRM_REST_Controller {
	/**
	 * Namespace.
	 */
	const REST_NAMESPACE = 'atora-crm/v2';

	/**
	 * Registra rutas base.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/dashboard',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_dashboard' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/contacts/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'search_contacts' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/contacts/(?P<contact_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_contact' ),
				'permission_callback' => array( __CLASS__, 'can_access' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/contacts/(?P<contact_id>\d+)/note',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_note' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/contacts/duplicates',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_duplicates' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/contacts/(?P<contact_id>\d+)/merge',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'merge_contact' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/contacts/(?P<contact_id>\d+)/custom-field',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save_custom_field' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
	}

	/**
	 * Permiso base CRM.
	 *
	 * @return bool
	 */
	public static function can_access(): bool {
		if ( class_exists( '\\ATORA\\CRM\\CRM' ) && method_exists( '\\ATORA\\CRM\\CRM', 'can_access_crm' ) ) {
			return (bool) \ATORA\CRM\CRM::can_access_crm( get_current_user_id() );
		}
		$core = clms_core( 'CLMS_Contacts_Core_Service' );
		return $core ? (bool) $core->can_access_crm( get_current_user_id() ) : current_user_can( 'manage_options' );
	}

	/**
	 * Permiso de gestión avanzada.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		if ( class_exists( '\\ATORA\\CRM\\CRM' ) && method_exists( '\\ATORA\\CRM\\CRM', 'can_manage_crm' ) ) {
			return (bool) \ATORA\CRM\CRM::can_manage_crm( get_current_user_id() );
		}

		$core = clms_core( 'CLMS_Contacts_Core_Service' );
		return $core ? (bool) $core->can_manage_crm( get_current_user_id() ) : current_user_can( 'manage_options' );
	}

	/**
	 * Alcance de usuarios visibles para el usuario actual.
	 *
	 * Arreglo vacío = alcance global (admin).
	 *
	 * @return array<int,int>
	 */
	public static function get_scope_user_ids(): array {
		if ( ! self::can_access() ) {
			return array( -1 );
		}

		$current_user_id = get_current_user_id();
		if ( ! $current_user_id ) {
			return array( -1 );
		}

		if ( self::has_global_scope() ) {
			return array();
		}

		// ── Caché via transient (Fase 5) — TTL 5 minutos ──────────────────
		$transient_key = 'atora_crm_scope_' . $current_user_id;
		$cached        = get_transient( $transient_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$ids = array( -1 );

		if ( class_exists( '\\ATORA\\CRM\\CRM' ) && method_exists( '\\ATORA\\CRM\\CRM', 'get_accessible_contact_user_ids' ) ) {
			$raw = array_values(
				array_filter(
					array_map(
						'absint',
						(array) \ATORA\CRM\CRM::get_accessible_contact_user_ids( $current_user_id )
					)
				)
			);
			$ids = ! empty( $raw ) ? $raw : array( -1 );
		}

		if ( count( $ids ) <= 500 ) {
			set_transient( $transient_key, $ids, 5 * MINUTE_IN_SECONDS );
		}

		return $ids;
	}

	/**
	 * Determina si el usuario actual tiene alcance global.
	 *
	 * @return bool
	 */
	public static function has_global_scope(): bool {
		if ( ! self::can_access() ) {
			return false;
		}

		if ( class_exists( '\\ATORA\\CRM\\CRM' ) && method_exists( '\\ATORA\\CRM\\CRM', 'has_global_contact_scope' ) ) {
			return (bool) \ATORA\CRM\CRM::has_global_contact_scope( get_current_user_id() );
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Determina si un user_id está visible para el usuario actual.
	 *
	 * @param int $user_id ID de usuario asociado al recurso.
	 * @return bool
	 */
	public static function user_id_is_visible( int $user_id ): bool {
		$user_id = absint( $user_id );
		if ( self::has_global_scope() ) {
			return true;
		}

		if ( $user_id <= 0 ) {
			return false;
		}

		$scope = self::get_scope_user_ids();
		return in_array( $user_id, $scope, true );
	}

	/**
	 * Determina si un contacto está en alcance.
	 *
	 * @param int $contact_id Contacto.
	 * @return bool
	 */
	public static function contact_id_is_visible( int $contact_id ): bool {
		global $wpdb;

		$contact_id = absint( $contact_id );
		if ( ! $contact_id ) {
			return false;
		}

		if ( self::has_global_scope() ) {
			return true;
		}

		$contact_user_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}atora_contacts WHERE id = %d LIMIT 1",
				$contact_id
			)
		);

		return self::user_id_is_visible( $contact_user_id );
	}

	/**
	 * Determina si una conversación está en alcance.
	 *
	 * @param int $conversation_id Conversación.
	 * @return bool
	 */
	public static function conversation_id_is_visible( int $conversation_id ): bool {
		global $wpdb;

		$conversation_id = absint( $conversation_id );
		if ( ! $conversation_id ) {
			return false;
		}

		if ( self::has_global_scope() ) {
			return true;
		}

		$row = (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT c.user_id, c.contact_id, ct.user_id AS contact_user_id
				 FROM {$wpdb->prefix}atora_conversations c
				 LEFT JOIN {$wpdb->prefix}atora_contacts ct ON ct.id = c.contact_id
				 WHERE c.id = %d
				 LIMIT 1",
				$conversation_id
			),
			ARRAY_A
		);
		if ( empty( $row ) ) {
			return false;
		}

		$conversation_user_id = absint( $row['user_id'] ?? 0 );
		$contact_user_id      = absint( $row['contact_user_id'] ?? 0 );
		if ( self::user_id_is_visible( $conversation_user_id ) ) {
			return true;
		}

		return self::user_id_is_visible( $contact_user_id );
	}

	/**
	 * Determina si un deal está en alcance.
	 *
	 * @param int $deal_id Deal.
	 * @return bool
	 */
	public static function deal_id_is_visible( int $deal_id ): bool {
		global $wpdb;

		$deal_id = absint( $deal_id );
		if ( ! $deal_id ) {
			return false;
		}
		if ( self::has_global_scope() ) {
			return true;
		}

		$row = (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, contact_id FROM {$wpdb->prefix}atora_crm_deals WHERE id = %d LIMIT 1",
				$deal_id
			),
			ARRAY_A
		);
		if ( empty( $row ) ) {
			return false;
		}

		if ( self::user_id_is_visible( absint( $row['user_id'] ?? 0 ) ) ) {
			return true;
		}

		return self::contact_id_is_visible( absint( $row['contact_id'] ?? 0 ) );
	}

	/**
	 * Determina si un seguimiento académico está en alcance.
	 *
	 * @param int $followup_id Followup.
	 * @return bool
	 */
	public static function followup_id_is_visible( int $followup_id ): bool {
		global $wpdb;

		$followup_id = absint( $followup_id );
		if ( ! $followup_id ) {
			return false;
		}
		if ( self::has_global_scope() ) {
			return true;
		}

		$row = (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, contact_id FROM {$wpdb->prefix}atora_crm_student_followups WHERE id = %d LIMIT 1",
				$followup_id
			),
			ARRAY_A
		);
		if ( empty( $row ) ) {
			return false;
		}

		if ( self::user_id_is_visible( absint( $row['user_id'] ?? 0 ) ) ) {
			return true;
		}

		return self::contact_id_is_visible( absint( $row['contact_id'] ?? 0 ) );
	}

	/**
	 * Determina si una tarea está en alcance.
	 *
	 * @param int $task_id Task.
	 * @return bool
	 */
	public static function task_id_is_visible( int $task_id ): bool {
		global $wpdb;

		$task_id = absint( $task_id );
		if ( ! $task_id ) {
			return false;
		}
		if ( self::has_global_scope() ) {
			return true;
		}

		$row = (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, contact_id FROM {$wpdb->prefix}atora_crm_tasks WHERE id = %d LIMIT 1",
				$task_id
			),
			ARRAY_A
		);
		if ( empty( $row ) ) {
			return false;
		}

		if ( self::user_id_is_visible( absint( $row['user_id'] ?? 0 ) ) ) {
			return true;
		}

		return self::contact_id_is_visible( absint( $row['contact_id'] ?? 0 ) );
	}

	/**
	 * Filtra filas por alcance del usuario actual.
	 *
	 * @param array<int,array<string,mixed>> $rows         Filas.
	 * @param string                         $user_id_key  Clave user_id.
	 * @param string                         $contact_id_key Clave contact_id.
	 * @return array<int,array<string,mixed>>
	 */
	public static function filter_rows_by_scope( array $rows, string $user_id_key = 'user_id', string $contact_id_key = 'contact_id' ): array {
		if ( self::has_global_scope() ) {
			return array_values( $rows );
		}

		$filtered = array();
		foreach ( $rows as $row ) {
			$row = is_array( $row ) ? $row : array();
			$user_id = absint( $row[ $user_id_key ] ?? 0 );
			if ( self::user_id_is_visible( $user_id ) ) {
				$filtered[] = $row;
				continue;
			}

			$contact_id = absint( $row[ $contact_id_key ] ?? 0 );
			if ( $contact_id && self::contact_id_is_visible( $contact_id ) ) {
				$filtered[] = $row;
			}
		}

		return array_values( $filtered );
	}

	/**
	 * Dashboard CRM.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_dashboard(): \WP_REST_Response {
		CRM_Email_Service::sync_tracking_to_recipients();

		return rest_ensure_response(
			array(
				'success'  => true,
				'overview' => Report_Service::get_overview(),
			)
		);
	}

	/**
	 * Busca contactos.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function search_contacts( \WP_REST_Request $request ): \WP_REST_Response {
		$term  = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$limit = absint( $request->get_param( 'limit' ) ?: 12 );
		$items = Contact_Service::search_contacts( $term, $limit );

		return rest_ensure_response(
			array(
				'success' => true,
				'items'   => $items,
			)
		);
	}

	/**
	 * Contacto 360.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_contact( \WP_REST_Request $request ): \WP_REST_Response {
		$contact_id = absint( $request->get_param( 'contact_id' ) );
		if ( ! self::contact_id_is_visible( $contact_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'No tienes permisos para este contacto.', 'atora-lms' ),
				),
				403
			);
		}

		$data       = Contact_Service::get_contact_360( $contact_id );

		if ( empty( $data ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => __( 'Contacto no encontrado.', 'atora-lms' ),
				)
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}

	/**
	 * Guarda nota en contacto.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function save_note( \WP_REST_Request $request ): \WP_REST_Response {
		$contact_id = absint( $request->get_param( 'contact_id' ) );
		if ( ! self::contact_id_is_visible( $contact_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'No tienes permisos para este contacto.', 'atora-lms' ),
				),
				403
			);
		}

		$note       = sanitize_textarea_field( (string) $request->get_param( 'note' ) );
		$saved      = Contact_Service::save_note( $contact_id, $note, get_current_user_id() );

		return rest_ensure_response(
			array(
				'success' => $saved,
				'message' => $saved
					? __( 'Nota registrada.', 'atora-lms' )
					: __( 'No fue posible guardar la nota.', 'atora-lms' ),
			)
		);
	}

	/**
	 * Lista grupos duplicados.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_duplicates( \WP_REST_Request $request ): \WP_REST_Response {
		$limit = absint( $request->get_param( 'limit' ) ?: 50 );
		return rest_ensure_response(
			array(
				'success'    => true,
				'duplicates' => Contact_Service::find_duplicates( $limit ),
			)
		);
	}

	/**
	 * Fusiona contactos.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function merge_contact( \WP_REST_Request $request ): \WP_REST_Response {
		$contact_id = absint( $request->get_param( 'contact_id' ) );
		$params     = $request->get_json_params() ?: array();
		$result     = Contact_Service::merge_contacts( $contact_id, (array) ( $params['remove_ids'] ?? array() ) );

		return rest_ensure_response( $result );
	}

	/**
	 * Guarda campo personalizado de un contacto.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function save_custom_field( \WP_REST_Request $request ): \WP_REST_Response {
		$contact_id = absint( $request->get_param( 'contact_id' ) );
		if ( ! self::contact_id_is_visible( $contact_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'No tienes permisos para este contacto.', 'atora-lms' ),
				),
				403
			);
		}

		$field_key = sanitize_key( (string) $request->get_param( 'field_key' ) );
		$value     = sanitize_textarea_field( (string) $request->get_param( 'value' ) );
		$saved     = Contact_Service::save_contact_custom_value( $contact_id, $field_key, $value );

		return rest_ensure_response(
			array(
				'success' => $saved,
				'message' => $saved ? __( 'Campo actualizado.', 'atora-lms' ) : __( 'No se pudo guardar el campo.', 'atora-lms' ),
			)
		);
	}
}
