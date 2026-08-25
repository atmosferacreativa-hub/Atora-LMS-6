<?php
/**
 * REST Controller — Planes de seguimiento (PT-4 backend, sprint 6.6.0).
 *
 * Mismo namespace y convención que Calendar_Events_REST_Controller
 * (Fase 2): `atora-crm/v2`, permission_callback base vía
 * CRM_REST_Controller::can_access()/can_manage(), endpoint de
 * reprogramar por POST dedicado para drag-and-drop (PT-4.3).
 *
 * Todo endpoint que opera sobre UN plan o UNA ocurrencia específica
 * verifica ownership explícitamente (el docente dueño del plan, o un
 * usuario con can_manage() global) — can_access()/can_manage() por sí
 * solos son capacidades amplias de CRM, no bastan para autorizar una
 * acción sobre el plan de OTRO docente.
 *
 * @package ATORA_LMS\CRM_V2\Rest
 * @since   6.6.0
 */

namespace ATORA\CRM_V2\Rest;

use ATORA\CRM_V2\Services\Followup_Plan_Service;
use ATORA\CRM_V2\Services\Followup_Plan_Resolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Followup_Plans_REST_Controller {

	const REST_NAMESPACE = 'atora-crm/v2';

	/**
	 * Registra las rutas. Llamar desde CRM_V2_App::register_rest_routes().
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$ns = self::REST_NAMESPACE;
		$access = array( 'ATORA\CRM_V2\Rest\CRM_REST_Controller', 'can_access' );

		register_rest_route( $ns, '/followup-plans/templates', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_templates' ),
			'permission_callback' => $access,
		) );

		// Fuente de eventos propia para el widget FullCalendar de esta
		// pantalla (PT-4.1) — deliberadamente NO reutiliza
		// GET /calendar/events de Calendar_Events_REST_Controller: ese
		// endpoint da forma a tareas/campañas con su propia lógica de
		// color/scope; las ocurrencias de un plan tienen su propia forma
		// (estado por estudiante contactado/no) y no calzan ahí sin
		// tocar ese archivo — se mantiene autocontenido en este mismo
		// controlador, cero riesgo para ese endpoint existente.
		register_rest_route( $ns, '/followup-plans/calendar-events', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_calendar_events' ),
			'permission_callback' => $access,
		) );

		register_rest_route( $ns, '/followup-plans/preview', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'preview_recipients' ),
			'permission_callback' => $access,
		) );

		register_rest_route( $ns, '/followup-plans', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_plans' ),
				'permission_callback' => $access,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_plan' ),
				'permission_callback' => $access,
			),
		) );

		register_rest_route( $ns, '/followup-plans/(?P<id>\d+)/pause', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'pause_plan' ),
			'permission_callback' => $access,
		) );

		register_rest_route( $ns, '/followup-plans/(?P<id>\d+)/resume', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'resume_plan' ),
			'permission_callback' => $access,
		) );

		register_rest_route( $ns, '/followup-plans/occurrence/(?P<event_id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_occurrence' ),
			'permission_callback' => $access,
		) );

		register_rest_route( $ns, '/followup-plans/occurrence/(?P<event_id>\d+)/reschedule', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'reschedule_occurrence' ),
			'permission_callback' => $access,
		) );

		register_rest_route( $ns, '/followup-plans/occurrence/(?P<event_id>\d+)/skip', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'skip_occurrence' ),
			'permission_callback' => $access,
		) );

		register_rest_route( $ns, '/followup-plans/occurrence/(?P<event_id>\d+)/contact', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'mark_contacted' ),
			'permission_callback' => $access,
		) );

		register_rest_route( $ns, '/followup-plans/occurrence/(?P<event_id>\d+)/exclude', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'exclude_student' ),
			'permission_callback' => $access,
		) );
	}

	/**
	 * GET /followup-plans/templates — PT-3 (6.6.0) / PT-2 (6.7.0),
	 * biblioteca de plantillas. ?domain=academic|commercial, default
	 * 'academic' — comportamiento idéntico a 6.6.0 si no se pasa.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_templates( \WP_REST_Request $request ): \WP_REST_Response {
		$domain = sanitize_key( (string) ( $request->get_param( 'domain' ) ?: 'academic' ) );
		return rest_ensure_response( array( 'success' => true, 'templates' => Followup_Plan_Service::get_templates( $domain ) ) );
	}

	/**
	 * GET /followup-plans/calendar-events — PT-4.1, fuente de eventos
	 * de FullCalendar para esta pantalla. Solo ocurrencias del docente
	 * actual (o de todos, si puede gestionar CRM globalmente).
	 *
	 * ?domain=academic|commercial (PT-5.1, 6.7.0): filtra por el
	 * dominio del plan dueño de cada ocurrencia — el selector de
	 * dominio de la UI, para quien tiene planes de ambos tipos. Sin el
	 * parámetro, devuelve todas las ocurrencias del usuario sin filtrar
	 * — comportamiento idéntico a 6.6.0.
	 *
	 * @param \WP_REST_Request $request Params: start, end (ISO8601, provistos por FullCalendar), domain?.
	 * @return \WP_REST_Response
	 */
	public static function get_calendar_events( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$start        = sanitize_text_field( (string) $request->get_param( 'start' ) );
		$end          = sanitize_text_field( (string) $request->get_param( 'end' ) );
		$domain_filter = sanitize_key( (string) ( $request->get_param( 'domain' ) ?: '' ) );
		$user_id      = get_current_user_id();
		$table        = $wpdb->prefix . 'atora_calendar_events';

		$can_manage = CRM_REST_Controller::can_manage();

		if ( $can_manage ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, title, start_datetime, user_id AS teacher_id
					 FROM {$table}
					 WHERE followup_plan_id IS NOT NULL AND start_datetime >= %s AND start_datetime <= %s",
					$start,
					$end
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, title, start_datetime, user_id AS teacher_id
					 FROM {$table}
					 WHERE followup_plan_id IS NOT NULL AND user_id = %d AND start_datetime >= %s AND start_datetime <= %s",
					$user_id,
					$start,
					$end
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$events = array();
		foreach ( (array) $rows as $row ) {
			$event_id = absint( $row['id'] ?? 0 );
			$date     = gmdate( 'Y-m-d', strtotime( (string) $row['start_datetime'] ) );

			$contacted  = Followup_Plan_Service::get_contacted_students( $event_id );
			$resolution = null;
			$domain     = 'academic';
			// Resolución completa solo si hace falta clasificar el color
			// (contactados vs. pendientes) — se evita para eventos fuera
			// del rango visible actual salvo que el llamador los pida.
			$plan_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT followup_plan_id FROM {$table} WHERE id = %d", $event_id ) );
			if ( $plan_id && class_exists( '\ATORA\CRM_V2\Services\Followup_Plan_Resolver' ) ) {
				$plan   = Followup_Plan_Service::get_plan( $plan_id );
				$domain = $plan ? $plan['domain'] : 'academic';

				if ( '' !== $domain_filter && $domain_filter !== $domain ) {
					continue; // fuera del dominio pedido por el selector de la UI.
				}

				$resolution = \ATORA\CRM_V2\Services\Followup_Plan_Resolver::resolve_recipients( $plan_id, $date, $event_id );
			} elseif ( '' !== $domain_filter && 'academic' !== $domain_filter ) {
				continue; // ocurrencia sin plan resoluble: tratada como académica por default, se omite si se pidió otro dominio.
			}

			$students       = $resolution ? (array) $resolution['students'] : array();
			$contacted_ids  = array_map( static fn( $c ) => $c['user_id'], $contacted );
			$total          = count( $students );
			$contacted_cnt  = count( array_intersect( array_map( static fn( $s ) => (int) $s['user_id'], $students ), $contacted_ids ) );

			// PT-3.1: en el dominio comercial, el bloque refleja el score
			// del contacto de MAYOR urgencia incluido — reutiliza los
			// mismos umbrales que Scoring_Service::get_score_label()
			// (score bajo = lead frío = necesita atención = urgente),
			// nunca una segunda categorización de score paralela.
			$urgency = null;
			if ( 'commercial' === $domain && $total > 0 ) {
				$lowest_score = null;
				foreach ( $students as $s ) {
					$score = (int) ( $s['meta']['score'] ?? 100 );
					if ( null === $lowest_score || $score < $lowest_score ) {
						$lowest_score = $score;
					}
				}
				if ( null !== $lowest_score ) {
					$urgency = $lowest_score > 70 ? 'low' : ( $lowest_score >= 40 ? 'medium' : 'high' );
				}
			}

			$events[] = array(
				'id'    => $event_id,
				'title' => sanitize_text_field( (string) $row['title'] ),
				'start' => $date,
				'extendedProps' => array(
					'event_id'         => $event_id,
					'domain'           => $domain,
					'empty'            => 0 === $total,
					'all_contacted'    => $total > 0 && $contacted_cnt === $total,
					'any_uncontacted'  => $total > 0 && $contacted_cnt < $total,
					'urgency'          => $urgency,
				),
			);
		}

		return rest_ensure_response( array( 'success' => true, 'events' => $events ) );
	}

	/**
	 * POST /followup-plans/preview — PT-2.2/PT-4.2 paso 2: vista
	 * previa "hoy esto tocaría a N estudiantes/contactos" contra una
	 * definición TODAVÍA NO GUARDADA.
	 *
	 * @param \WP_REST_Request $request Body: section_ids[], stage_filter[], domain?, domain_config?.
	 * @return \WP_REST_Response
	 */
	public static function preview_recipients( \WP_REST_Request $request ): \WP_REST_Response {
		$section_ids   = array_map( 'absint', (array) $request->get_param( 'section_ids' ) );
		$stage_filter  = array_map( 'sanitize_key', (array) $request->get_param( 'stage_filter' ) );
		$domain        = sanitize_key( (string) ( $request->get_param( 'domain' ) ?: 'academic' ) );
		$domain_config = (array) ( $request->get_param( 'domain_config' ) ?: array() );

		$result = Followup_Plan_Resolver::resolve_for_definition(
			$section_ids,
			$stage_filter,
			0,
			'',
			$domain,
			$domain_config,
			get_current_user_id()
		);

		return rest_ensure_response( array(
			'success'             => true,
			'sections'            => array_values( $result['sections'] ),
			'students'            => $result['students'],
			'count'               => count( $result['students'] ),
			'empty_reason'        => $result['empty_reason'],
			'filtered_out_count'  => $result['filtered_out_count'] ?? 0,
		) );
	}

	/**
	 * GET /followup-plans — planes del docente/vendedor actual. Filtro
	 * opcional ?domain=academic|commercial (PT-5.1: el selector de
	 * dominio de la UI, relevante solo para quien tiene planes de
	 * ambos). Sin el parámetro, devuelve todos los dominios del usuario
	 * — comportamiento idéntico a 6.6.0 para instalaciones sin CRM
	 * comercial o sin planes comerciales todavía.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function list_plans( \WP_REST_Request $request ): \WP_REST_Response {
		$teacher_id = get_current_user_id();
		$domain     = sanitize_key( (string) ( $request->get_param( 'domain' ) ?: '' ) );

		$plans = Followup_Plan_Service::get_plans_for_teacher( $teacher_id, $domain );

		return rest_ensure_response( array( 'success' => true, 'plans' => $plans ) );
	}

	/**
	 * POST /followup-plans — PT-4.2 paso 4: nombrar y aplicar.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_plan( \WP_REST_Request $request ) {
		$data = array(
			'teacher_id'      => get_current_user_id(),
			'domain'          => (string) ( $request->get_param( 'domain' ) ?: 'academic' ),
			'name'            => (string) $request->get_param( 'name' ),
			'template_key'    => $request->get_param( 'template_key' ),
			'section_ids'     => (array) $request->get_param( 'section_ids' ),
			'stage_filter'    => (array) $request->get_param( 'stage_filter' ),
			'domain_config'   => (array) ( $request->get_param( 'domain_config' ) ?: array() ),
			'recurrence_rule' => (string) $request->get_param( 'recurrence_rule' ),
			'action_type'     => (string) ( $request->get_param( 'action_type' ) ?: 'checkin' ),
			'end_date'        => $request->get_param( 'end_date' ),
		);

		$plan_id = Followup_Plan_Service::create_plan( $data );

		if ( is_wp_error( $plan_id ) ) {
			return $plan_id;
		}

		return rest_ensure_response( array( 'success' => true, 'plan_id' => $plan_id, 'plan' => Followup_Plan_Service::get_plan( $plan_id ) ) );
	}

	/**
	 * POST /followup-plans/{id}/pause
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function pause_plan( \WP_REST_Request $request ) {
		$plan_id = absint( $request->get_param( 'id' ) );
		$plan    = self::require_owned_plan( $plan_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		Followup_Plan_Service::pause_plan( $plan_id );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * POST /followup-plans/{id}/resume
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function resume_plan( \WP_REST_Request $request ) {
		$plan_id = absint( $request->get_param( 'id' ) );
		$plan    = self::require_owned_plan( $plan_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		Followup_Plan_Service::resume_plan( $plan_id );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * GET /followup-plans/occurrence/{event_id} — PT-4.4 panel lateral.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_occurrence( \WP_REST_Request $request ) {
		$event_id = absint( $request->get_param( 'event_id' ) );
		$event    = self::require_owned_occurrence( $event_id );
		if ( is_wp_error( $event ) ) {
			return $event;
		}

		$plan_id      = (int) $event['followup_plan_id'];
		$plan         = Followup_Plan_Service::get_plan( $plan_id );
		$date         = gmdate( 'Y-m-d', strtotime( (string) $event['start_datetime'] ) );
		$resolution   = Followup_Plan_Resolver::resolve_recipients( $plan_id, $date, $event_id );
		$contacted    = Followup_Plan_Service::get_contacted_students( $event_id );
		$contacted_ids = array_map( static fn( $c ) => $c['user_id'], $contacted );

		$students = array_map(
			static function ( $student ) use ( $contacted_ids ) {
				$student['contacted'] = in_array( $student['user_id'], $contacted_ids, true );
				return $student;
			},
			$resolution['students']
		);

		return rest_ensure_response( array(
			'success'             => true,
			'event_id'            => $event_id,
			'plan_id'             => $plan_id,
			'domain'              => $plan ? $plan['domain'] : 'academic',
			'date'                => $date,
			'title'               => sanitize_text_field( (string) $event['title'] ),
			'sections'            => array_values( $resolution['sections'] ),
			'students'            => $students,
			'empty_reason'        => $resolution['empty_reason'],
			'filtered_out_count'  => $resolution['filtered_out_count'] ?? 0,
		) );
	}

	/**
	 * POST /followup-plans/occurrence/{event_id}/reschedule — PT-4.3.
	 *
	 * @param \WP_REST_Request $request Body: date (Y-m-d).
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function reschedule_occurrence( \WP_REST_Request $request ) {
		$event_id = absint( $request->get_param( 'event_id' ) );
		$event    = self::require_owned_occurrence( $event_id );
		if ( is_wp_error( $event ) ) {
			return $event;
		}

		$new_date = sanitize_text_field( (string) $request->get_param( 'date' ) );
		$ok       = Followup_Plan_Service::reschedule_occurrence( $event_id, $new_date );

		if ( ! $ok ) {
			return new \WP_Error( 'reschedule_failed', __( 'No se pudo reprogramar la ocurrencia.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * POST /followup-plans/occurrence/{event_id}/skip — PT-4.6.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function skip_occurrence( \WP_REST_Request $request ) {
		$event_id = absint( $request->get_param( 'event_id' ) );
		$event    = self::require_owned_occurrence( $event_id );
		if ( is_wp_error( $event ) ) {
			return $event;
		}

		Followup_Plan_Service::skip_occurrence( $event_id );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * POST /followup-plans/occurrence/{event_id}/contact — PT-4.4.
	 *
	 * @param \WP_REST_Request $request Body: user_id.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function mark_contacted( \WP_REST_Request $request ) {
		$event_id = absint( $request->get_param( 'event_id' ) );
		$event    = self::require_owned_occurrence( $event_id );
		if ( is_wp_error( $event ) ) {
			return $event;
		}

		$user_id = absint( $request->get_param( 'user_id' ) );
		if ( ! $user_id ) {
			return new \WP_Error( 'invalid_student', __( 'Estudiante no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		Followup_Plan_Service::mark_contacted( $event_id, $user_id, get_current_user_id() );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * POST /followup-plans/occurrence/{event_id}/exclude — PT-4.7.
	 *
	 * @param \WP_REST_Request $request Body: user_id.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function exclude_student( \WP_REST_Request $request ) {
		$event_id = absint( $request->get_param( 'event_id' ) );
		$event    = self::require_owned_occurrence( $event_id );
		if ( is_wp_error( $event ) ) {
			return $event;
		}

		$user_id = absint( $request->get_param( 'user_id' ) );
		if ( ! $user_id ) {
			return new \WP_Error( 'invalid_student', __( 'Estudiante no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		Followup_Plan_Service::exclude_student_from_occurrence( $event_id, $user_id );
		return rest_ensure_response( array( 'success' => true ) );
	}

	// ── Ownership guards ─────────────────────────────────────────────────────

	/**
	 * @param int $plan_id
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function require_owned_plan( int $plan_id ) {
		$plan = Followup_Plan_Service::get_plan( $plan_id );
		if ( ! $plan ) {
			return new \WP_Error( 'not_found', __( 'Plan no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		if ( (int) $plan['teacher_id'] !== get_current_user_id() && ! CRM_REST_Controller::can_manage() ) {
			return new \WP_Error( 'forbidden', __( 'No tenés permiso sobre este plan.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		return $plan;
	}

	/**
	 * @param int $event_id
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function require_owned_occurrence( int $event_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_calendar_events';
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND followup_plan_id IS NOT NULL LIMIT 1", $event_id ), ARRAY_A );

		if ( ! $event ) {
			return new \WP_Error( 'not_found', __( 'Ocurrencia no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		if ( (int) $event['user_id'] !== get_current_user_id() && ! CRM_REST_Controller::can_manage() ) {
			return new \WP_Error( 'forbidden', __( 'No tenés permiso sobre esta ocurrencia.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		return $event;
	}
}
