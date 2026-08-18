<?php
/**
 * REST de tareas CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Rest;

use ATORA\CRM_V2\Services\Task_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tasks_REST_Controller {
	/**
	 * Registra rutas de tareas.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$ns = CRM_REST_Controller::REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/tasks',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_tasks' ),
					'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_access' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_task' ),
					'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_manage' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/tasks/(?P<task_id>\d+)/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'complete_task' ),
				'permission_callback' => array( '\\ATORA\\CRM_V2\\Rest\\CRM_REST_Controller', 'can_manage' ),
			)
		);
	}

	/**
	 * Lista tareas.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function list_tasks( \WP_REST_Request $request ): \WP_REST_Response {
		$scope_user_ids = CRM_REST_Controller::has_global_scope() ? null : CRM_REST_Controller::get_scope_user_ids();

		$data = Task_Service::list_tasks(
			array(
				'status'      => sanitize_key( (string) $request->get_param( 'status' ) ),
				'assigned_to' => absint( $request->get_param( 'assigned_to' ) ),
				'contact_id'  => absint( $request->get_param( 'contact_id' ) ),
				'scope_user_ids' => $scope_user_ids,
				'limit'       => absint( $request->get_param( 'limit' ) ?: 40 ),
				'offset'      => absint( $request->get_param( 'offset' ) ?: 0 ),
				'date_from'   => sanitize_text_field( (string) $request->get_param( 'date_from' ) ),
				'date_to'     => sanitize_text_field( (string) $request->get_param( 'date_to' ) ),
			)
		);

		return rest_ensure_response( array( 'success' => true ) + $data );
	}

	/**
	 * Crea tarea.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function create_task( \WP_REST_Request $request ): \WP_REST_Response {
		$contact_id = absint( $request->get_param( 'contact_id' ) );
		$user_id    = absint( $request->get_param( 'user_id' ) );
		if ( $contact_id > 0 && ! CRM_REST_Controller::contact_id_is_visible( $contact_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'No tienes permisos para este contacto.', 'atora-lms' ),
				),
				403
			);
		}
		if ( $user_id > 0 && ! CRM_REST_Controller::user_id_is_visible( $user_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'No tienes permisos para este usuario.', 'atora-lms' ),
				),
				403
			);
		}

		$task_id = Task_Service::create_task(
			array(
				'title'       => sanitize_text_field( (string) $request->get_param( 'title' ) ),
				'contact_id'  => $contact_id,
				'user_id'     => $user_id,
				'related_type'=> sanitize_key( (string) $request->get_param( 'related_type' ) ),
				'related_id'  => absint( $request->get_param( 'related_id' ) ),
				'task_type'   => sanitize_key( (string) $request->get_param( 'task_type' ) ),
				'priority'    => sanitize_key( (string) $request->get_param( 'priority' ) ),
				'due_at'      => sanitize_text_field( (string) $request->get_param( 'due_at' ) ),
				'assigned_to' => absint( $request->get_param( 'assigned_to' ) ),
				'notes'       => sanitize_textarea_field( (string) $request->get_param( 'notes' ) ),
			)
		);

		return rest_ensure_response(
			array(
				'success' => $task_id > 0,
				'task_id' => $task_id,
				'message' => $task_id > 0
					? __( 'Tarea creada.', 'atora-lms' )
					: __( 'No se pudo crear la tarea.', 'atora-lms' ),
			)
		);
	}

	/**
	 * Completa tarea.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function complete_task( \WP_REST_Request $request ): \WP_REST_Response {
		$task_id   = absint( $request->get_param( 'task_id' ) );
		if ( ! CRM_REST_Controller::task_id_is_visible( $task_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'No tienes permisos para esta tarea.', 'atora-lms' ),
				),
				403
			);
		}

		$completed = Task_Service::complete_task( $task_id );

		return rest_ensure_response(
			array(
				'success' => $completed,
				'message' => $completed
					? __( 'Tarea completada.', 'atora-lms' )
					: __( 'No fue posible completar la tarea.', 'atora-lms' ),
			)
		);
	}
}
