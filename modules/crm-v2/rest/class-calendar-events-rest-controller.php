<?php
/**
 * REST Controller — Eventos de Calendario CRM v2 (Fase 2)
 *
 * Amplía Calendar_REST_Controller con:
 *   GET  /atora-crm/v2/calendar/events  → tareas + campañas en formato FullCalendar
 *   GET  /atora-crm/v2/calendar/task/{id} → detalle de una tarea
 *   POST /atora-crm/v2/calendar/task/{id}/reschedule → mover tarea de fecha (drag-and-drop)
 *
 * El endpoint unificado /calendar/events es el que consume crm-calendar.js.
 * Los endpoints existentes de Calendar_REST_Controller y Tasks_REST_Controller
 * no se modifican — este controlador es aditivo.
 *
 * @package ATORA_LMS\CRM_V2\Rest
 * @since   5.22.0
 */

namespace ATORA\CRM_V2\Rest;

use ATORA\CRM_V2\Services\Task_Service;
use ATORA\CRM_V2\Services\Campaign_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Calendar_Events_REST_Controller {

	const REST_NAMESPACE = 'atora-crm/v2';

	/**
	 * Mapa de tipo de tarea → color de evento en FullCalendar.
	 * Paleta coherente con el sistema de diseño del CRM v2.
	 */
	const TASK_COLORS = array(
		'commercial_call' => '#059669', // verde esmeralda — ventas
		'followup_email'  => '#2563eb', // azul — seguimiento
		'tutoring'        => '#7c3aed', // violeta — académico
		'grading'         => '#d97706', // ámbar — evaluación
		'campaign'        => '#db2777', // fucsia — campaña
		'payment'         => '#dc2626', // rojo — pago pendiente
		'academic_event'  => '#0891b2', // cyan — evento académico
	);

	const PRIORITY_BORDER = array(
		'low'    => '#94a3b8',
		'medium' => '#3b82f6',
		'high'   => '#f59e0b',
		'urgent' => '#ef4444',
	);

	/**
	 * Registra las rutas.
	 * Llamar desde CRM_V2_App::register_rest_routes().
	 */
	public static function register_routes(): void {
		$ns = self::REST_NAMESPACE;
		$cb = array( 'ATORA\CRM_V2\Rest\CRM_REST_Controller', 'can_access' );

		// Fuente unificada de eventos para FullCalendar
		register_rest_route( $ns, '/calendar/events', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_events' ),
			'permission_callback' => $cb,
		) );

		// Detalle de tarea individual (popover del calendario)
		register_rest_route( $ns, '/calendar/task/(?P<task_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_task_detail' ),
			'permission_callback' => $cb,
		) );

		// Drag-and-drop: actualizar due_at de una tarea
		register_rest_route( $ns, '/calendar/task/(?P<task_id>\d+)/reschedule', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'reschedule_task' ),
			'permission_callback' => array( 'ATORA\CRM_V2\Rest\CRM_REST_Controller', 'can_manage' ),
		) );
	}

	/* ----------------------------------------------------------------
	 * GET /calendar/events
	 *
	 * Parámetros GET:
	 *   start  — ISO8601 inicio del rango visible (enviado por FullCalendar)
	 *   end    — ISO8601 fin del rango visible (enviado por FullCalendar)
	 *   types  — CSV: 'tasks,campaigns' (por defecto ambos)
	 * ---------------------------------------------------------------- */
	public static function get_events( \WP_REST_Request $request ): \WP_REST_Response {
		$start = sanitize_text_field( (string) $request->get_param( 'start' ) );
		$end   = sanitize_text_field( (string) $request->get_param( 'end' ) );
		$types = sanitize_text_field( (string) ( $request->get_param( 'types' ) ?: 'tasks,campaigns' ) );
		$types = array_map( 'trim', explode( ',', $types ) );

		$events = array();

		// ── Tareas ──────────────────────────────────────────────────
		if ( in_array( 'tasks', $types, true ) ) {
			$scope = CRM_REST_Controller::has_global_scope() ? null : CRM_REST_Controller::get_scope_user_ids();
			$tasks = Task_Service::list_tasks( array(
				'date_from'      => $start,
				'date_to'        => $end,
				'scope_user_ids' => $scope,
				'limit'          => 300,
			) );

			foreach ( (array) ( $tasks['items'] ?? array() ) as $task ) {
				$events[] = self::task_to_event( $task );
			}
		}

		// ── Campañas programadas ────────────────────────────────────
		if ( in_array( 'campaigns', $types, true ) ) {
			$campaigns = Campaign_Service::get_upcoming_campaigns( 60, $start );
			foreach ( (array) $campaigns as $campaign ) {
				$events[] = self::campaign_to_event( $campaign );
			}
		}

		return rest_ensure_response( array(
			'success' => true,
			'events'  => $events,
		) );
	}

	/* ----------------------------------------------------------------
	 * GET /calendar/task/{task_id}
	 * ---------------------------------------------------------------- */
	public static function get_task_detail( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$task_id = absint( $request->get_param( 'task_id' ) );
		// PT-1 (6.5.1): hallazgo adicional no cubierto por la auditoría —
		// esta ruta no verificaba alcance, a diferencia de
		// Tasks_REST_Controller::complete_task() sobre la misma tabla.
		// Cualquier can_access podía ver el detalle de tareas de otros
		// docentes.
		if ( ! CRM_REST_Controller::task_id_is_visible( $task_id ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => __( 'No tienes permisos para esta tarea.', 'atora-lms' ) ),
				403
			);
		}

		$table   = $wpdb->prefix . 'atora_crm_tasks';

		$task = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $task_id ),
			ARRAY_A
		);

		if ( empty( $task ) ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Tarea no encontrada.' ), 404 );
		}

		// Resolver nombre del contacto si lo hay
		$contact_name = '';
		if ( ! empty( $task['contact_id'] ) ) {
			$contacts_table = $wpdb->prefix . 'atora_contacts';
			$contact = $wpdb->get_row( $wpdb->prepare(
				"SELECT name, email FROM {$contacts_table} WHERE id = %d LIMIT 1",
				absint( $task['contact_id'] )
			), ARRAY_A );
			if ( $contact ) {
				$contact_name = sanitize_text_field( $contact['name'] ?? $contact['email'] ?? '' );
			}
		}

		$task_types = Task_Service::get_task_types();

		return rest_ensure_response( array(
			'success'      => true,
			'task'         => array(
				'id'           => absint( $task['id'] ),
				'title'        => sanitize_text_field( $task['title'] ?? '' ),
				'task_type'    => sanitize_key( $task['task_type'] ?? '' ),
				'task_type_label' => sanitize_text_field( $task_types[ $task['task_type'] ?? '' ] ?? '' ),
				'priority'     => sanitize_key( $task['priority'] ?? 'medium' ),
				'status'       => sanitize_key( $task['status'] ?? 'pending' ),
				'due_at'       => sanitize_text_field( $task['due_at'] ?? '' ),
				'notes'        => sanitize_textarea_field( $task['notes'] ?? '' ),
				'contact_id'   => absint( $task['contact_id'] ?? 0 ),
				'contact_name' => $contact_name,
				'color'        => self::TASK_COLORS[ $task['task_type'] ?? '' ] ?? '#64748b',
			),
		) );
	}

	/* ----------------------------------------------------------------
	 * POST /calendar/task/{task_id}/reschedule
	 * Body JSON: { "due_at": "2026-05-20T10:00:00" }
	 * ---------------------------------------------------------------- */
	public static function reschedule_task( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$task_id = absint( $request->get_param( 'task_id' ) );
		$body    = $request->get_json_params();
		$due_at  = sanitize_text_field( (string) ( $body['due_at'] ?? '' ) );

		if ( ! $task_id || ! $due_at ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Datos incompletos.' ), 400 );
		}

		// PT-1 (6.5.1): mismo hallazgo que get_task_detail() — sin esto,
		// cualquier can_manage podía reprogramar la tarea de otro docente.
		if ( ! CRM_REST_Controller::task_id_is_visible( $task_id ) ) {
			return new \WP_REST_Response(
				array( 'success' => false, 'message' => __( 'No tienes permisos para esta tarea.', 'atora-lms' ) ),
				403
			);
		}

		// Normalizar a UTC Y-m-d H:i:s
		$dt = date_create( $due_at, wp_timezone() );
		if ( ! $dt ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Fecha no válida.' ), 400 );
		}
		$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
		$due_utc = $dt->format( 'Y-m-d H:i:s' );

		$table   = $wpdb->prefix . 'atora_crm_tasks';
		$updated = $wpdb->update(
			$table,
			array( 'due_at' => $due_utc ),
			array( 'id'     => $task_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'No se pudo reprogramar la tarea.' ), 500 );
		}

		return rest_ensure_response( array(
			'success' => true,
			'message' => __( 'Tarea reprogramada.', 'atora-lms' ),
			'due_at'  => $due_utc,
		) );
	}

	/* ----------------------------------------------------------------
	 * Helpers de transformación
	 * ---------------------------------------------------------------- */

	/**
	 * Convierte una tarea al formato de evento FullCalendar.
	 * extendedProps se usa en el popover/click del JS.
	 */
	private static function task_to_event( array $task ): array {
		$task_type = sanitize_key( (string) ( $task['task_type'] ?? '' ) );
		$priority  = sanitize_key( (string) ( $task['priority'] ?? 'medium' ) );
		$status    = sanitize_key( (string) ( $task['status'] ?? 'pending' ) );
		$due_at    = sanitize_text_field( (string) ( $task['due_at'] ?? '' ) );

		// Convertir UTC → ISO8601 con timezone local de WordPress
		$start_iso = '';
		if ( $due_at ) {
			$dt = date_create( $due_at, new \DateTimeZone( 'UTC' ) );
			if ( $dt ) {
				$dt->setTimezone( wp_timezone() );
				$start_iso = $dt->format( 'c' );
			}
		}

		$color = self::TASK_COLORS[ $task_type ] ?? '#64748b';
		$border_color = self::PRIORITY_BORDER[ $priority ] ?? '#64748b';

		// Tareas completadas: aspecto atenuado
		$text_color    = '#ffffff';
		$background    = $color;
		$class_names   = array( 'crm-cal-event', 'crm-cal-event--task', 'crm-cal-event--' . $priority );
		if ( 'completed' === $status ) {
			$background  = '#94a3b8';
			$border_color = '#94a3b8';
			$class_names[] = 'crm-cal-event--done';
		}

		$task_types_labels = Task_Service::get_task_types();

		return array(
			'id'              => 'task-' . absint( $task['id'] ),
			'title'           => sanitize_text_field( (string) ( $task['title'] ?? '' ) ),
			'start'           => $start_iso,
			'allDay'          => false,
			'backgroundColor' => $background,
			'borderColor'     => $border_color,
			'textColor'       => $text_color,
			'classNames'      => $class_names,
			'editable'        => 'completed' !== $status, // drag-and-drop solo si pendiente
			'extendedProps'   => array(
				'type'         => 'task',
				'task_id'      => absint( $task['id'] ),
				'task_type'    => $task_type,
				'task_type_label' => sanitize_text_field( $task_types_labels[ $task_type ] ?? $task_type ),
				'priority'     => $priority,
				'status'       => $status,
				'notes'        => sanitize_textarea_field( (string) ( $task['notes'] ?? '' ) ),
				'contact_id'   => absint( $task['contact_id'] ?? 0 ),
				'contact_name' => '',  // se resuelve en el popover via GET /calendar/task/{id}
			),
		);
	}

	/**
	 * Convierte una campaña programada al formato de evento FullCalendar.
	 */
	private static function campaign_to_event( array $campaign ): array {
		$scheduled_at = sanitize_text_field( (string) ( $campaign['scheduled_at'] ?? '' ) );
		$status       = sanitize_key( (string) ( $campaign['status'] ?? 'draft' ) );

		$start_iso = '';
		if ( $scheduled_at ) {
			$dt = date_create( $scheduled_at, new \DateTimeZone( 'UTC' ) );
			if ( $dt ) {
				$dt->setTimezone( wp_timezone() );
				$start_iso = $dt->format( 'c' );
			}
		}

		// Campañas siempre no editables (solo se reprograman desde la pantalla de campañas)
		return array(
			'id'              => 'campaign-' . absint( $campaign['id'] ),
			'title'           => '📧 ' . sanitize_text_field( (string) ( $campaign['name'] ?? '' ) ),
			'start'           => $start_iso,
			'allDay'          => false,
			'backgroundColor' => '#db2777',
			'borderColor'     => '#9d174d',
			'textColor'       => '#ffffff',
			'classNames'      => array( 'crm-cal-event', 'crm-cal-event--campaign' ),
			'editable'        => false,
			'extendedProps'   => array(
				'type'       => 'campaign',
				'campaign_id'=> absint( $campaign['id'] ),
				'channel'    => sanitize_key( (string) ( $campaign['channel'] ?? 'email' ) ),
				'status'     => $status,
				'subject'    => sanitize_text_field( (string) ( $campaign['subject'] ?? '' ) ),
			),
		);
	}
}
