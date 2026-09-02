<?php
/**
 * ATORA LMS v5 — Módulo Calendario
 *
 * Gestiona eventos académicos, bookings de slots y sincronización
 * con calendarios externos (Google, Outlook, ICS).
 *
 * @package ATORA_LMS\Calendar
 * @since   5.0.0
 */

namespace ATORA\Calendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Calendar
 *
 * @since 5.0.0
 */
class Calendar {

	/** Tipos de evento soportados. */
	const EVENT_TYPES = array(
		'assignment_deadline' => 'Entrega de tarea',
		'exam'                => 'Examen',
		'live_class'          => 'Clase en vivo',
		'webinar'             => 'Webinar',
		'meeting'             => 'Reunión 1:1',
		'academy_event'       => 'Evento general',
	);

	/**
	 * Inicializa el módulo.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Poblar automáticamente desde deadlines y lecciones.
		add_action( 'save_post',                   array( __CLASS__, 'maybe_create_event_from_post' ), 20, 2 );
		add_action( 'clms_lesson_completed',        array( __CLASS__, 'maybe_update_progress' ), 10, 2 );

		// REST API pública para el widget FullCalendar.
		// P2 (6.12.0): gateado por módulo 'calendar'.
		if ( ! class_exists( '\CLMS_Module_Registry' ) || \CLMS_Module_Registry::is_active( 'calendar' ) ) {
			add_action( 'rest_api_init',               array( __CLASS__, 'register_rest_routes' ) );
		}

		// Shortcode de calendario.
		add_shortcode( 'atora_calendar',           array( __CLASS__, 'render_shortcode' ) );

		// ICS feed con clave por usuario.
		add_action( 'init',                        array( __CLASS__, 'register_ics_endpoint' ) );
		add_action( 'template_redirect',           array( __CLASS__, 'handle_ics_feed' ) );

		// Admin.
		if ( is_admin() ) {
			// PT-4.2.2 (6.3.0): sin hook a register_admin_menu() — el hub
			// (trait-admin-menu-hubs.php) ya registra 'atora-calendar' con
			// cap 'read' + su propia lógica de permisos granular
			// (can_access_calendar_page()); esta clase, al registrarse
			// después vía el puente atora_lms_admin_menu, siempre se
			// auto-bloqueaba (ver guard en register_admin_menu()) y nunca
			// llegó a ejecutarse — era ya código muerto. Se deja el método
			// por si se necesita reactivar, pero sin el hook.
			add_action( 'wp_ajax_atora_cal_save',  array( __CLASS__, 'ajax_save_event' ) );
			add_action( 'wp_ajax_atora_cal_delete',array( __CLASS__, 'ajax_delete_event' ) );
		}

		// Cron: limpiar eventos antiguos.
		add_action( 'atora_daily_cron',            array( __CLASS__, 'cleanup_old_events' ) );

		// Assets.
		add_action( 'wp_enqueue_scripts',          array( __CLASS__, 'maybe_enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts',       array( __CLASS__, 'admin_enqueue_assets' ) );
	}

	// ── REST API ──────────────────────────────────────────────────────────────

	/**
	 * Registra los endpoints REST para FullCalendar.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		register_rest_route( 'atora/v1', '/calendar/events', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_get_events' ),
			// Requiere login: los eventos incluyen datos de curso y sesiones privadas.
			'permission_callback' => array( __CLASS__, 'can_access_calendar_rest' ),
			'args'                => array(
				'start'     => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'end'       => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'course_id' => array( 'sanitize_callback' => 'absint' ),
				'type'      => array( 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		register_rest_route( 'atora/v1', '/calendar/bookings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_get_bookings' ),
				'permission_callback' => array( __CLASS__, 'can_access_calendar_rest' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_create_booking' ),
				'permission_callback' => array( __CLASS__, 'can_access_calendar_rest' ),
			),
		) );

		register_rest_route( 'atora/v1', '/calendar/bookings/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'rest_cancel_booking' ),
			'permission_callback' => array( __CLASS__, 'can_access_calendar_rest' ),
		) );
	}

	/**
	 * Permission callback común para REST de calendario.
	 *
	 * @return bool
	 */
	public static function can_access_calendar_rest(): bool {
		return is_user_logged_in();
	}

	/**
	 * GET /atora/v1/calendar/events
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_get_events( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id   = get_current_user_id();
		$start     = sanitize_text_field( $request->get_param( 'start' ) ?? '' );
		$end       = sanitize_text_field( $request->get_param( 'end' ) ?? '' );
		$course_id = absint( $request->get_param( 'course_id' ) );
		$type      = sanitize_key( $request->get_param( 'type' ) ?? '' );

		$events = self::get_events( array(
			'start'     => $start,
			'end'       => $end,
			'course_id' => $course_id,
			'type'      => $type,
			'user_id'   => $user_id,
		) );
		$events = self::filter_events_for_user( $events, $user_id );

		$data = array_map( array( __CLASS__, 'format_event_for_fullcalendar' ), $events );

		return rest_ensure_response( $data );
	}

	/**
	 * GET /atora/v1/calendar/bookings
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rest_get_bookings( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		global $wpdb;

		$user_id = get_current_user_id();
		$rows    = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.event_id, b.status, b.booked_at, b.cancelled_at,
				        e.title, e.event_type, e.start_datetime, e.end_datetime, e.course_id, e.lesson_id, e.location
				   FROM {$wpdb->prefix}atora_calendar_bookings b
				   LEFT JOIN {$wpdb->prefix}atora_calendar_events e ON e.id = b.event_id
				  WHERE b.user_id = %d
				  ORDER BY b.booked_at DESC",
				$user_id
			)
		);

		$data = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row->event_id ) && ! self::user_can_access_event( $user_id, $row ) ) {
				continue;
			}

			$item = array(
				'id'           => absint( $row->id ),
				'event_id'     => absint( $row->event_id ),
				'status'       => sanitize_key( (string) $row->status ),
				'booked_at'    => sanitize_text_field( (string) $row->booked_at ),
				'cancelled_at' => sanitize_text_field( (string) $row->cancelled_at ),
				'event'        => null,
			);

			if ( ! empty( $row->title ) ) {
				$item['event'] = array(
					'title'          => sanitize_text_field( (string) $row->title ),
					'event_type'     => sanitize_key( (string) $row->event_type ),
					'start_datetime' => sanitize_text_field( (string) $row->start_datetime ),
					'end_datetime'   => sanitize_text_field( (string) $row->end_datetime ),
					'course_id'      => absint( $row->course_id ),
					'lesson_id'      => absint( $row->lesson_id ),
					'location'       => sanitize_text_field( (string) $row->location ),
				);
			}

			$data[] = $item;
		}

		return rest_ensure_response( $data );
	}

	/**
	 * POST /atora/v1/calendar/bookings
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_create_booking( \WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$event_id = absint( $request->get_param( 'event_id' ) );

		if ( ! $event_id ) {
			return new \WP_Error( 'invalid_event', __( 'Evento no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$result = self::create_booking( $user_id, $event_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array(
			'booking_id' => $result,
			'message'    => __( 'Reserva confirmada.', 'atora-lms' ),
		) );
	}

	/**
	 * DELETE /atora/v1/calendar/bookings/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_cancel_booking( \WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$booking_id = absint( $request->get_param( 'id' ) );

		$result = self::cancel_booking( $user_id, $booking_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'message' => __( 'Reserva cancelada.', 'atora-lms' ) ) );
	}

	// ── CRUD eventos ─────────────────────────────────────────────────────────

	/**
	 * Crea un evento en el calendario.
	 *
	 * @param array $data Datos del evento.
	 * @return int|false ID del evento creado o false en error.
	 */
	public static function create_event( array $data ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			"{$wpdb->prefix}atora_calendar_events",
			array(
				'title'            => sanitize_text_field( $data['title'] ?? '' ),
				'description'      => sanitize_textarea_field( $data['description'] ?? '' ),
				'event_type'       => sanitize_key( $data['event_type'] ?? 'academy_event' ),
				'start_datetime'   => sanitize_text_field( $data['start_datetime'] ?? '' ),
				'end_datetime'     => sanitize_text_field( $data['end_datetime'] ?? '' ),
				'course_id'        => absint( $data['course_id'] ?? 0 ),
				'lesson_id'        => absint( $data['lesson_id'] ?? 0 ),
				'user_id'          => absint( $data['user_id'] ?? get_current_user_id() ),
				'location'         => sanitize_text_field( $data['location'] ?? '' ),
				'max_participants' => absint( $data['max_participants'] ?? 0 ),
				'recurrence_rule'  => sanitize_text_field( $data['recurrence_rule'] ?? '' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		$event_id = (int) $wpdb->insert_id;

		do_action( 'atora/calendar/event_created', $event_id, $data );

		return $event_id;
	}

	/**
	 * Obtiene eventos con filtros opcionales.
	 *
	 * @param array $args Filtros: start, end, course_id, type, user_id.
	 * @return array
	 */
	public static function get_events( array $args = array() ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['start'] ) ) {
			$where[]  = 'start_datetime >= %s';
			$params[] = sanitize_text_field( $args['start'] );
		}

		if ( ! empty( $args['end'] ) ) {
			$where[]  = 'end_datetime <= %s';
			$params[] = sanitize_text_field( $args['end'] );
		}

		if ( ! empty( $args['course_id'] ) ) {
			$where[]  = 'course_id = %d';
			$params[] = absint( $args['course_id'] );
		}

		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'event_type = %s';
			$params[] = sanitize_key( $args['type'] );
		}

		// P10.5 (6.13.0): filtro incremental — solo eventos creados o
		// modificados desde la última sincronización, para que
		// Calendar_Sync::sync_user_calendar() deje de reenviar a Google/
		// Outlook eventos que no cambiaron en cada corrida del cron.
		if ( ! empty( $args['updated_since'] ) ) {
			$where[]  = 'updated_at > %s';
			$params[] = sanitize_text_field( $args['updated_since'] );
		}

		$sql = "SELECT * FROM {$wpdb->prefix}atora_calendar_events WHERE " . implode( ' AND ', $where ) . ' ORDER BY start_datetime ASC';

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $sql );
	}

	/**
	 * Formatea un evento para FullCalendar.
	 *
	 * @param object $event Evento DB.
	 * @return array
	 */
	private static function format_event_for_fullcalendar( object $event ): array {
		$colors = array(
			'assignment_deadline' => '#f59e0b',
			'exam'                => '#ef4444',
			'live_class'          => '#6366f1',
			'webinar'             => '#8b5cf6',
			'meeting'             => '#22c55e',
			'academy_event'       => '#64748b',
		);

		return array(
			'id'          => absint( $event->id ),
			'title'       => $event->title,
			'start'       => $event->start_datetime,
			'end'         => $event->end_datetime,
			'color'       => $colors[ $event->event_type ] ?? '#6366f1',
			'extendedProps' => array(
				'type'       => $event->event_type,
				'course_id'  => $event->course_id,
				'location'   => $event->location,
				'description'=> $event->description,
			),
		);
	}

	/**
	 * Evalúa si el usuario actual puede gestionar contexto académico de calendario.
	 *
	 * @param int $course_id Curso opcional para validar ownership.
	 * @return bool
	 */
	private static function current_user_can_manage_calendar_context( int $course_id = 0 ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( class_exists( 'CLMS_Access' )
			&& ( \CLMS_Access::can_manage_courses() || \CLMS_Access::can_manage_lessons() || \CLMS_Access::can_view_teacher_dashboard() ) ) {
			if ( $course_id > 0 && class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'user_can_manage_lms' ) ) {
				return (bool) \CLMS_Helper::user_can_manage_lms( $course_id );
			}
			return true;
		}

		return false;
	}

	/**
	 * Valida si un usuario puede ver/interactuar con un evento concreto.
	 *
	 * @param int    $user_id Usuario que solicita acceso.
	 * @param object $event   Evento (o fila con metadatos de evento).
	 * @return bool
	 */
	private static function user_can_access_event( int $user_id, object $event ): bool {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}

		$event_user_id = isset( $event->user_id ) ? absint( $event->user_id ) : 0;
		$course_id     = isset( $event->course_id ) ? absint( $event->course_id ) : 0;

		if ( $event_user_id > 0 && $event_user_id === $user_id ) {
			return true;
		}

		if ( self::current_user_can_manage_calendar_context( $course_id ) ) {
			return true;
		}

		if ( $course_id > 0 && class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'user_can_access_course' ) ) {
			return (bool) \CLMS_Helper::user_can_access_course( $user_id, $course_id );
		}

		return 0 === $event_user_id && 0 === $course_id;
	}

	/**
	 * Filtra una lista de eventos al contexto permitido del usuario.
	 *
	 * @param array $events  Lista de eventos DB.
	 * @param int   $user_id Usuario objetivo.
	 * @return array
	 */
	private static function filter_events_for_user( array $events, int $user_id ): array {
		$filtered = array();

		foreach ( $events as $event ) {
			if ( ! is_object( $event ) || ! self::user_can_access_event( $user_id, $event ) ) {
				continue;
			}
			$filtered[] = $event;
		}

		return $filtered;
	}

	// ── Bookings ──────────────────────────────────────────────────────────────

	/**
	 * Crea una reserva de slot.
	 *
	 * @param int $user_id  ID del usuario.
	 * @param int $event_id ID del evento.
	 * @return int|\WP_Error
	 */
	public static function create_booking( int $user_id, int $event_id ) {
		global $wpdb;

		$event = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_calendar_events WHERE id = %d LIMIT 1",
			$event_id
		) );

		if ( ! $event ) {
			return new \WP_Error( 'event_not_found', __( 'Evento no encontrado.', 'atora-lms' ) );
		}

		if ( ! self::user_can_access_event( $user_id, $event ) ) {
			return new \WP_Error( 'forbidden_event', __( 'No tienes acceso a este evento.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		// Verificar duplicado.
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}atora_calendar_bookings
			 WHERE event_id = %d AND user_id = %d AND status != 'cancelled' LIMIT 1",
			$event_id, $user_id
		) );

		if ( $exists ) {
			return new \WP_Error( 'already_booked', __( 'Ya tienes una reserva para este evento.', 'atora-lms' ) );
		}

		// Verificar aforo.
		if ( $event->max_participants > 0 ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}atora_calendar_bookings
				 WHERE event_id = %d AND status = 'confirmed'",
				$event_id
			) );

			if ( $count >= $event->max_participants ) {
				return new \WP_Error( 'event_full', __( 'El evento está lleno.', 'atora-lms' ) );
			}
		}

		$wpdb->insert(
			"{$wpdb->prefix}atora_calendar_bookings",
			array(
				'event_id'  => $event_id,
				'user_id'   => $user_id,
				'status'    => 'confirmed',
				'booked_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		$booking_id = (int) $wpdb->insert_id;

		do_action( 'atora/calendar/booking_created', $booking_id, $user_id, $event_id );

		return $booking_id;
	}

	/**
	 * Cancela una reserva.
	 *
	 * @param int $user_id    ID del usuario.
	 * @param int $booking_id ID de la reserva.
	 * @return true|\WP_Error
	 */
	public static function cancel_booking( int $user_id, int $booking_id ) {
		global $wpdb;

		$booking = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_calendar_bookings WHERE id = %d LIMIT 1",
			$booking_id
		) );

		if ( ! $booking || (int) $booking->user_id !== $user_id ) {
			return new \WP_Error( 'not_found', __( 'Reserva no encontrada.', 'atora-lms' ) );
		}

		$wpdb->update(
			"{$wpdb->prefix}atora_calendar_bookings",
			array(
				'status'           => 'cancelled',
				'cancelled_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => $booking_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		do_action( 'atora/calendar/booking_cancelled', $booking_id, $user_id );

		return true;
	}

	// ── Hooks automáticos ─────────────────────────────────────────────────────

	/**
	 * Crea evento de calendario al guardar un post con deadline.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function maybe_create_event_from_post( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$deadline = get_post_meta( $post_id, '_clms_assignment_deadline', true );
		if ( $deadline && 'lm_lesson' === $post->post_type ) {
			self::create_event( array(
				'title'          => sprintf( __( 'Entrega: %s', 'atora-lms' ), $post->post_title ),
				'event_type'     => 'assignment_deadline',
				'start_datetime' => $deadline,
				'end_datetime'   => $deadline,
				'lesson_id'      => $post_id,
				'course_id'      => absint( get_post_meta( $post_id, '_clms_course_id', true ) ),
			) );
		}
	}

	// ── ICS Feed ─────────────────────────────────────────────────────────────

	/**
	 * Registra el endpoint para el feed ICS.
	 *
	 * @return void
	 */
	public static function register_ics_endpoint(): void {
		add_rewrite_rule( '^calendar/user/([0-9]+)/feed\.ics$', 'index.php?atora_ics_user=$matches[1]', 'top' );
		add_filter( 'query_vars', static function ( $vars ) {
			$vars[] = 'atora_ics_user';
			return $vars;
		} );
	}

	/**
	 * Valida acceso al feed ICS de un usuario.
	 *
	 * @param int    $feed_user_id ID del usuario dueño del feed.
	 * @param string $provided_key Clave entregada en query param.
	 * @return bool
	 */
	private static function can_access_ics_feed( int $feed_user_id, string $provided_key ): bool {
		$current_user_id = get_current_user_id();
		if ( $current_user_id ) {
			if ( $current_user_id === $feed_user_id || current_user_can( 'manage_options' ) ) {
				return true;
			}

			if ( class_exists( 'CLMS_Access' ) && \CLMS_Access::can_manage_courses() ) {
				return true;
			}
		}

		if ( '' === $provided_key ) {
			return false;
		}

		$expected_key = self::get_user_ics_feed_key( $feed_user_id, false );
		if ( '' === $expected_key ) {
			return false;
		}

		return hash_equals( $expected_key, $provided_key );
	}

	/**
	 * Obtiene (y opcionalmente crea) la clave privada del feed ICS de un usuario.
	 *
	 * @param int  $user_id ID de usuario.
	 * @param bool $create  Si true, crea clave si no existe.
	 * @return string
	 */
	private static function get_user_ics_feed_key( int $user_id, bool $create = false ): string {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return '';
		}

		$key = (string) get_user_meta( $user_id, '_atora_calendar_ics_key', true );
		if ( '' !== $key ) {
			return $key;
		}

		if ( ! $create ) {
			return '';
		}

		$key = wp_generate_password( 40, false, false );
		update_user_meta( $user_id, '_atora_calendar_ics_key', $key );

		return $key;
	}

	/**
	 * Genera la URL del feed ICS para un usuario.
	 *
	 * @param int $user_id ID de usuario (0 = actual).
	 * @return string
	 */
	public static function get_user_ics_feed_url( int $user_id = 0 ): string {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $user_id ) {
			return '';
		}

		$key = self::get_user_ics_feed_key( $user_id, true );
		if ( '' === $key ) {
			return '';
		}

		return add_query_arg(
			array( 'key' => $key ),
			home_url( '/calendar/user/' . $user_id . '/feed.ics' )
		);
	}

	/**
	 * Sirve el feed ICS.
	 *
	 * @return void
	 */
	public static function handle_ics_feed(): void {
		$user_id = absint( get_query_var( 'atora_ics_user' ) );
		if ( ! $user_id ) {
			return;
		}

		$provided_key = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
		if ( ! self::can_access_ics_feed( $user_id, $provided_key ) ) {
			status_header( 403 );
			nocache_headers();
			echo esc_html__( 'Acceso no autorizado al feed de calendario.', 'atora-lms' );
			exit;
		}

		$events = self::get_events( array( 'user_id' => $user_id ) );
		$events = self::filter_events_for_user( $events, $user_id );

		header( 'Content-Type: text/calendar; charset=UTF-8' );
		header( 'Content-Disposition: inline; filename=atora-calendar.ics' );

		echo "BEGIN:VCALENDAR\r\n";
		echo "VERSION:2.0\r\n";
		echo 'PRODID:-//ATORA LMS//Calendar//EN' . "\r\n";
		echo "CALSCALE:GREGORIAN\r\n";
		echo "METHOD:PUBLISH\r\n";

		foreach ( $events as $event ) {
			$dtstart = gmdate( 'Ymd\THis\Z', strtotime( $event->start_datetime ) );
			$dtend   = gmdate( 'Ymd\THis\Z', strtotime( $event->end_datetime ?: $event->start_datetime ) );
			$uid     = 'atora-' . $event->id . '@atoralms.com';

			echo "BEGIN:VEVENT\r\n";
			echo "UID:{$uid}\r\n";
			echo "DTSTART:{$dtstart}\r\n";
			echo "DTEND:{$dtend}\r\n";
			echo 'SUMMARY:' . str_replace( "\n", '\n', wp_strip_all_tags( $event->title ) ) . "\r\n";
			if ( $event->description ) {
				echo 'DESCRIPTION:' . str_replace( "\n", '\n', wp_strip_all_tags( $event->description ) ) . "\r\n";
			}
			if ( $event->location ) {
				echo 'LOCATION:' . wp_strip_all_tags( $event->location ) . "\r\n";
			}
			echo "END:VEVENT\r\n";
		}

		echo "END:VCALENDAR\r\n";
		exit;
	}

	// ── Admin ─────────────────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function register_admin_menu(): void {
		global $submenu;
		$existing_items = (array) ( $submenu['clms-dashboard'] ?? array() );
		foreach ( $existing_items as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( 'atora-calendar' === $slug ) {
				return;
			}
		}

		add_submenu_page(
			'clms-dashboard',
			__( 'Calendario', 'atora-lms' ),
			__( 'Calendario', 'atora-lms' ),
			'manage_options',
			'atora-calendar',
			static function () {
				$view = ATORA_LMS_MODULES_DIR . 'calendar/views/admin.php';
				if ( file_exists( $view ) ) {
					require $view;
				}
			}
		);
	}

	/**
	 * AJAX: guardar evento.
	 *
	 * @return void
	 */
	public static function ajax_save_event(): void {
		check_ajax_referer( 'atora_calendar_admin' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$data = array(
			'title'          => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'description'    => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'event_type'     => sanitize_key( wp_unslash( $_POST['event_type'] ?? 'academy_event' ) ),
			'start_datetime' => sanitize_text_field( wp_unslash( $_POST['start_datetime'] ?? '' ) ),
			'end_datetime'   => sanitize_text_field( wp_unslash( $_POST['end_datetime'] ?? '' ) ),
			'course_id'      => absint( wp_unslash( $_POST['course_id'] ?? 0 ) ),
			'location'       => sanitize_text_field( wp_unslash( $_POST['location'] ?? '' ) ),
			'max_participants' => absint( wp_unslash( $_POST['max_participants'] ?? 0 ) ),
		);

		$id = self::create_event( $data );
		$id ? wp_send_json_success( array( 'id' => $id ) ) : wp_send_json_error();
	}

	/**
	 * AJAX: eliminar evento.
	 *
	 * @return void
	 */
	public static function ajax_delete_event(): void {
		global $wpdb;

		check_ajax_referer( 'atora_calendar_admin' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$id = absint( wp_unslash( $_POST['event_id'] ?? 0 ) );
		$wpdb->delete( "{$wpdb->prefix}atora_calendar_events", array( 'id' => $id ), array( '%d' ) );
		wp_send_json_success();
	}

	// ── Shortcode ─────────────────────────────────────────────────────────────

	/**
	 * Renderiza el widget de calendario (FullCalendar).
	 *
	 * @param array $atts Atributos del shortcode.
	 * @return string
	 */
	public static function render_shortcode( array $atts = array() ): string {
		$atts = shortcode_atts( array(
			'course_id' => 0,
			'view'      => 'dayGridMonth',
		), $atts );
		$ics_url = self::get_user_ics_feed_url();

		wp_enqueue_script( 'atora-fullcalendar' );
		wp_enqueue_style( 'atora-fullcalendar' );

		ob_start();
		?>
		<div id="atora-calendar-widget"
		     data-course-id="<?php echo absint( $atts['course_id'] ); ?>"
		     data-initial-view="<?php echo esc_attr( $atts['view'] ); ?>"
		     data-events-url="<?php echo esc_url( rest_url( 'atora/v1/calendar/events' ) ); ?>"
		     data-ics-url="<?php echo esc_url( $ics_url ); ?>"
		     data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
		     style="max-width:900px;margin:0 auto;">
		</div>
		<?php
		return ob_get_clean();
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function maybe_enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		global $post;
		if ( $post && has_shortcode( $post->post_content, 'atora_calendar' ) ) {
			self::enqueue_fullcalendar();
		}
	}

	/**
	 * @return void
	 */
	public static function admin_enqueue_assets( string $hook ): void {
		if ( str_contains( $hook, 'atora-calendar' ) ) {
			self::enqueue_fullcalendar();
		}
	}

	/**
	 * Encola FullCalendar desde CDN.
	 *
	 * @return void
	 */
	private static function enqueue_fullcalendar(): void {
		wp_register_style(
			'atora-fullcalendar',
			'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css',
			array(),
			'6.1.11'
		);

		wp_register_script(
			'atora-fullcalendar',
			'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js',
			array(),
			'6.1.11',
			true
		);

		wp_enqueue_style( 'atora-fullcalendar' );
		wp_enqueue_script( 'atora-fullcalendar' );

		wp_add_inline_script( 'atora-fullcalendar', self::get_calendar_init_js(), 'after' );
	}

	/**
	 * JS de inicialización del calendario.
	 *
	 * @return string
	 */
	private static function get_calendar_init_js(): string {
		return <<<JS
document.addEventListener('DOMContentLoaded', function() {
  const el = document.getElementById('atora-calendar-widget');
  if (!el) return;

  const calendar = new FullCalendar.Calendar(el, {
    initialView: el.dataset.initialView || 'dayGridMonth',
    locale: document.documentElement.lang || 'es',
    headerToolbar: {
      left: 'prev,next today',
      center: 'title',
      right: 'dayGridMonth,timeGridWeek,listMonth'
    },
    events: {
      url: el.dataset.eventsUrl,
      extraParams: { course_id: el.dataset.courseId },
      method: 'GET',
      extraHeaders: { 'X-WP-Nonce': el.dataset.nonce }
    },
    eventClick: function(info) {
      const p = info.event.extendedProps;
      alert(info.event.title + (p.description ? '\n\n' + p.description : ''));
    },
    height: 'auto'
  });
  calendar.render();
});
JS;
	}

	// ── Mantenimiento ─────────────────────────────────────────────────────────

	/**
	 * Elimina eventos con más de 1 año de antigüedad.
	 *
	 * @return void
	 */
	public static function cleanup_old_events(): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-1 year' ) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}atora_calendar_events WHERE end_datetime < %s",
			$cutoff
		) );
	}

	/**
	 * @return void
	 */
	public static function maybe_update_progress( int $user_id, int $lesson_id ): void {
		// Placeholder para sincronizar progreso con eventos al completar lección.
		do_action( 'atora/calendar/lesson_completed', $user_id, $lesson_id );
	}
}
