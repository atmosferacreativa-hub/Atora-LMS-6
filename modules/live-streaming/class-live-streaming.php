<?php
/**
 * ATORA LMS v5 — Módulo Live Streaming
 *
 * Integra Zoom, Google Meet, Microsoft Teams, YouTube Live y embeds custom.
 * Gestiona creación de reuniones, recordatorios automáticos, grabaciones y asistencia.
 *
 * @package ATORA_LMS\LiveStreaming
 * @since   5.0.0
 */

namespace ATORA\LiveStreaming;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Live_Streaming
 *
 * @since 5.0.0
 */
class Live_Streaming {

	/** Meta key que almacena los datos del live en la lección. */
	const LESSON_META = '_atora_live_session';

	/** Providers disponibles. */
	const PROVIDERS = array( 'zoom', 'meet', 'teams', 'youtube', 'custom' );

	/**
	 * Inicializa el módulo.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Registrar intervalo antes de programar cron para evitar dependencia externa.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_intervals' ) );

		// Metabox en lecciones para crear clases en vivo.
		add_action( 'add_meta_boxes',           array( __CLASS__, 'register_metabox' ) );
		add_action( 'save_post_lm_lesson',      array( __CLASS__, 'save_metabox' ) );

		// REST endpoints.
		add_action( 'rest_api_init',            array( __CLASS__, 'register_rest_routes' ) );

		// Webhooks entrantes (Zoom).
		add_action( 'rest_api_init',            array( __CLASS__, 'register_webhook_routes' ) );

		// Recordatorios: cron.
		add_action( 'atora_live_reminders_cron', array( __CLASS__, 'process_reminders' ) );
		if ( ! wp_next_scheduled( 'atora_live_reminders_cron' ) ) {
			wp_schedule_event( time(), 'every_5_minutes', 'atora_live_reminders_cron' );
		}

		// Crear evento en calendario al guardar lección live.
		add_action( 'atora/live/session_created', array( __CLASS__, 'create_calendar_event' ), 10, 2 );

		// Admin settings.
		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		}

		// Frontend: encolar assets solo en páginas de lecciones live.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Añade intervalos de cron usados por el módulo live.
	 *
	 * @param array $schedules Schedules existentes.
	 * @return array
	 */
	public static function add_cron_intervals( array $schedules ): array {
		$schedules['every_5_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Cada 5 minutos', 'atora-lms' ),
		);
		return $schedules;
	}

	// ── Zoom API ──────────────────────────────────────────────────────────────

	/**
	 * Crea una reunión en Zoom.
	 *
	 * @param array $data Datos de la reunión.
	 * @return array|WP_Error Respuesta de la API de Zoom o error.
	 */
	public static function create_zoom_meeting( array $data ) {
		$token = self::get_zoom_access_token();
		if ( ! $token ) {
			return new \WP_Error( 'zoom_auth', __( 'No se pudo autenticar con Zoom.', 'atora-lms' ) );
		}

		$body = wp_json_encode( array(
			'topic'      => sanitize_text_field( $data['title'] ?? 'Clase ATORA LMS' ),
			'type'       => 2, // Scheduled.
			'start_time' => $data['start_datetime'] ?? '',
			'duration'   => absint( $data['duration_minutes'] ?? 60 ),
			'timezone'   => $data['timezone'] ?? 'UTC',
			'settings'   => array(
				'auto_recording' => ! empty( $data['record'] ) ? 'cloud' : 'none',
				'waiting_room'   => true,
				'join_before_host' => false,
			),
		) );

		$response = wp_remote_post(
			'https://api.zoom.us/v2/users/me/meetings',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => $body,
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $result['id'] ) ) {
			return new \WP_Error( 'zoom_api', __( 'Error al crear la reunión en Zoom.', 'atora-lms' ) );
		}

		return array(
			'meeting_id'  => $result['id'],
			'join_url'    => $result['join_url'],
			'start_url'   => $result['start_url'],
			'password'    => $result['password'] ?? '',
		);
	}

	/**
	 * Obtiene el listado de participantes de una reunión Zoom.
	 *
	 * @param string $meeting_id ID de la reunión.
	 * @return array
	 */
	public static function get_zoom_participants( string $meeting_id ): array {
		$token = self::get_zoom_access_token();
		if ( ! $token ) {
			return array();
		}

		$response = wp_remote_get(
			"https://api.zoom.us/v2/past_meetings/{$meeting_id}/participants",
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return $data['participants'] ?? array();
	}

	// ── Metabox en lecciones ──────────────────────────────────────────────────

	/**
	 * Registra el metabox de clase en vivo.
	 *
	 * @return void
	 */
	public static function register_metabox(): void {
		add_meta_box(
			'atora_live_session',
			__( '🎥 Clase en vivo', 'atora-lms' ),
			array( __CLASS__, 'render_metabox' ),
			'lm_lesson',
			'normal',
			'high'
		);
	}

	/**
	 * Renderiza el metabox.
	 *
	 * @param \WP_Post $post Post actual.
	 * @return void
	 */
	public static function render_metabox( \WP_Post $post ): void {
		wp_nonce_field( 'atora_live_save', 'atora_live_nonce' );
		$session  = get_post_meta( $post->ID, self::LESSON_META, true );
		$session  = is_array( $session ) ? $session : array();
		$provider = $session['provider'] ?? '';

		$view = ATORA_LMS_MODULES_DIR . 'live-streaming/views/metabox.php';
		if ( file_exists( $view ) ) {
			require $view;
		}
	}

	/**
	 * Guarda los datos del metabox.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function save_metabox( int $post_id ): void {
		if ( ! isset( $_POST['atora_live_nonce'] ) ||
		     ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['atora_live_nonce'] ) ), 'atora_live_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$provider = sanitize_key( wp_unslash( $_POST['atora_live_provider'] ?? '' ) );

		if ( ! $provider || ! in_array( $provider, self::PROVIDERS, true ) ) {
			delete_post_meta( $post_id, self::LESSON_META );
			return;
		}

		$session = array(
			'provider'         => $provider,
			'start_datetime'   => sanitize_text_field( wp_unslash( $_POST['atora_live_start'] ?? '' ) ),
			'duration_minutes' => absint( wp_unslash( $_POST['atora_live_duration'] ?? 60 ) ),
			'timezone'         => sanitize_text_field( wp_unslash( $_POST['atora_live_tz'] ?? 'UTC' ) ),
			'record'           => ! empty( $_POST['atora_live_record'] ),
			'max_participants' => absint( wp_unslash( $_POST['atora_live_max'] ?? 0 ) ),
			'custom_url'       => esc_url_raw( wp_unslash( $_POST['atora_live_custom_url'] ?? '' ) ),
		);

		// Si es Zoom y aún no tiene meeting_id, crear la reunión.
		if ( 'zoom' === $provider && empty( $session['meeting_id'] ) && ! empty( $session['start_datetime'] ) ) {
			$lesson = get_post( $post_id );
			$meeting = self::create_zoom_meeting( array_merge( $session, array( 'title' => $lesson->post_title ) ) );

			if ( ! is_wp_error( $meeting ) ) {
				$session = array_merge( $session, $meeting );
			}
		}

		update_post_meta( $post_id, self::LESSON_META, $session );

		do_action( 'atora/live/session_created', $post_id, $session );
	}

	// ── Recordatorios ─────────────────────────────────────────────────────────

	/**
	 * Procesa la cola de recordatorios pendientes.
	 *
	 * @return void
	 */
	public static function process_reminders(): void {
		// Buscar lecciones live en las próximas 25 horas sin recordatorio enviado.
		$args = array(
			'post_type'  => 'lm_lesson',
			'meta_query' => array(
				array(
					'key'     => self::LESSON_META,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_atora_live_reminder_24h_sent',
					'compare' => 'NOT EXISTS',
				),
			),
			'posts_per_page' => 50,
			'fields'         => 'ids',
		);

		$lesson_ids = get_posts( $args );

		$now   = time();
		$in24h = $now + 25 * HOUR_IN_SECONDS;

		foreach ( $lesson_ids as $lesson_id ) {
			$session = get_post_meta( $lesson_id, self::LESSON_META, true );
			if ( empty( $session['start_datetime'] ) ) {
				continue;
			}

			$start = strtotime( $session['start_datetime'] );

			// Recordatorio 24h antes.
			if ( $start > $now && $start <= $in24h ) {
				self::send_reminder( (int) $lesson_id, $session, '24h' );
				update_post_meta( $lesson_id, '_atora_live_reminder_24h_sent', 1 );
			}

			// Recordatorio 1h antes.
			if ( $start > $now && $start <= $now + HOUR_IN_SECONDS + 300 && ! get_post_meta( $lesson_id, '_atora_live_reminder_1h_sent', true ) ) {
				self::send_reminder( (int) $lesson_id, $session, '1h' );
				update_post_meta( $lesson_id, '_atora_live_reminder_1h_sent', 1 );
			}
		}
	}

	/**
	 * Envía recordatorio a estudiantes matriculados en el curso de la lección.
	 *
	 * @param int    $lesson_id ID de la lección.
	 * @param array  $session   Datos de la sesión.
	 * @param string $when      '24h' o '1h'.
	 * @return void
	 */
	private static function send_reminder( int $lesson_id, array $session, string $when ): void {
		$course_id = absint( get_post_meta( $lesson_id, '_clms_course_id', true ) );
		if ( ! $course_id ) {
			return;
		}

		$students = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_students' )
			? (array) CLMS_Helper::get_course_students( $course_id )
			: array();

		$lesson_title = get_the_title( $lesson_id );
		$join_url     = $session['join_url'] ?? $session['custom_url'] ?? get_permalink( $lesson_id );

		$subject = '1h' === $when
			? sprintf( __( '⏰ Clase en vivo en 1 hora: %s', 'atora-lms' ), $lesson_title )
			: sprintf( __( '📅 Recordatorio: clase mañana — %s', 'atora-lms' ), $lesson_title );

		foreach ( $students as $student ) {
			$user_id = is_object( $student ) ? $student->ID : absint( $student );
			$user    = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}

			$body  = '<p>' . sprintf( __( 'Hola %s,', 'atora-lms' ), esc_html( $user->display_name ) ) . '</p>';
			$body .= '<p>' . sprintf( __( 'Tu clase en vivo "%s" comienza pronto.', 'atora-lms' ), esc_html( $lesson_title ) ) . '</p>';
			$body .= '<p><a href="' . esc_url( $join_url ) . '">' . __( 'Unirse a la clase', 'atora-lms' ) . '</a></p>';

			if ( class_exists( 'ATORA_Email_Gateway' ) && method_exists( 'ATORA_Email_Gateway', 'send' ) ) {
				\ATORA_Email_Gateway::send( $user->user_email, $subject, $body, array(
					'headline'    => $lesson_title,
					'button_text' => __( 'Unirse ahora', 'atora-lms' ),
					'button_url'  => $join_url,
					'preheader'   => $subject,
				) );
			} elseif ( class_exists( 'CLMS_Email' ) && method_exists( 'CLMS_Email', 'send' ) ) {
				\CLMS_Email::send( $user->user_email, $subject, $body, array(
					'headline'    => $lesson_title,
					'button_text' => __( 'Unirse ahora', 'atora-lms' ),
					'button_url'  => $join_url,
				) );
			}
		}
	}

	// ── Webhooks Zoom ─────────────────────────────────────────────────────────

	/**
	 * Registra el endpoint de webhooks de Zoom.
	 *
	 * @return void
	 */
	public static function register_webhook_routes(): void {
		// __return_true es intencional: Zoom no autentica vía cookie/cap.
		// La validación real se hace en handle_zoom_webhook() por firma.
		register_rest_route( 'atora/v1', '/webhooks/zoom', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_zoom_webhook' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Procesa webhooks entrantes de Zoom.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_zoom_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		// Validar firma del webhook.
		$signature = $request->get_header( 'x-zm-signature' );
		if ( ! self::verify_zoom_webhook_signature( $request->get_body(), $signature ) ) {
			return new \WP_REST_Response( array( 'status' => 'invalid_signature' ), 403 );
		}

		$payload    = $request->get_json_params();
		$event_type = sanitize_text_field( $payload['event'] ?? '' );

		switch ( $event_type ) {
			case 'meeting.ended':
				self::handle_zoom_meeting_ended( $payload['payload'] ?? array() );
				break;

			case 'recording.completed':
				self::handle_zoom_recording_completed( $payload['payload'] ?? array() );
				break;
		}

		do_action( 'atora/live/zoom_webhook', $event_type, $payload );

		return rest_ensure_response( array( 'status' => 'ok' ) );
	}

	/**
	 * Procesa el evento meeting.ended de Zoom y actualiza asistencia.
	 *
	 * @param array $payload Payload del evento.
	 * @return void
	 */
	private static function handle_zoom_meeting_ended( array $payload ): void {
		$meeting_id = sanitize_text_field( $payload['object']['id'] ?? '' );
		if ( ! $meeting_id ) {
			return;
		}

		// Buscar lección asociada a este meeting_id.
		$lessons = get_posts( array(
			'post_type'  => 'lm_lesson',
			'meta_key'   => self::LESSON_META,
			'fields'     => 'ids',
			'posts_per_page' => 5,
		) );

		foreach ( $lessons as $lesson_id ) {
			$session = get_post_meta( $lesson_id, self::LESSON_META, true );
			if ( ( $session['meeting_id'] ?? '' ) !== $meeting_id ) {
				continue;
			}

			// Marcar asistencia usando participantes.
			$participants = self::get_zoom_participants( $meeting_id );
			self::record_attendance( (int) $lesson_id, $participants, $payload );

			do_action( 'atora/live/meeting_ended', $lesson_id, $meeting_id, $participants );
			break;
		}
	}

	/**
	 * Procesa el evento recording.completed de Zoom.
	 *
	 * @param array $payload Payload del evento.
	 * @return void
	 */
	private static function handle_zoom_recording_completed( array $payload ): void {
		$meeting_id   = sanitize_text_field( $payload['object']['id'] ?? '' );
		$download_url = esc_url_raw( $payload['object']['recording_files'][0]['download_url'] ?? '' );

		if ( ! $meeting_id || ! $download_url ) {
			return;
		}

		// Encolar descarga en background.
		do_action( 'atora/live/recording_available', $meeting_id, $download_url );
	}

	/**
	 * Registra la asistencia de los estudiantes a una clase.
	 *
	 * @param int   $lesson_id    ID de la lección.
	 * @param array $participants Participantes de Zoom.
	 * @param array $payload      Payload completo.
	 * @return void
	 */
	private static function record_attendance( int $lesson_id, array $participants, array $payload ): void {
		$duration_mins = (int) ( $payload['object']['duration'] ?? 60 );

		foreach ( $participants as $participant ) {
			$email   = sanitize_email( $participant['user_email'] ?? '' );
			$p_mins  = (int) ( $participant['duration'] ?? 0 );
			$ratio   = $duration_mins > 0 ? $p_mins / $duration_mins : 0;

			$status = 'absent';
			if ( $ratio >= 0.8 ) {
				$status = 'attended';
			} elseif ( $ratio >= 0.5 ) {
				$status = 'partial';
			}

			if ( $email ) {
				$user = get_user_by( 'email', $email );
				if ( $user ) {
					update_user_meta( $user->ID, '_atora_live_attendance_' . $lesson_id, array(
						'status'     => $status,
						'duration'   => $p_mins,
						'ratio'      => round( $ratio * 100, 1 ),
						'updated_at' => current_time( 'mysql' ),
					) );

					do_action( 'atora/live/attendance_recorded', $user->ID, $lesson_id, $status );
				}
			}
		}
	}

	// ── REST público ──────────────────────────────────────────────────────────

	/**
	 * Registra endpoints REST para el frontend.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		register_rest_route( 'atora/v1', '/live/(?P<lesson_id>\d+)/join-url', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_get_join_url' ),
			'permission_callback' => array( __CLASS__, 'can_access_join_url' ),
		) );
	}

	/**
	 * Permission callback: valida acceso contextual a la lección live.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_access_join_url( \WP_REST_Request $request ): bool {
		$user_id   = get_current_user_id();
		$lesson_id = absint( $request->get_param( 'lesson_id' ) );

		if ( ! $user_id || ! $lesson_id ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'user_can_manage_lms' ) && \CLMS_Helper::user_can_manage_lms( $lesson_id ) ) {
			return true;
		}

		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'user_can_access_lesson' ) ) {
			return (bool) \CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id );
		}

		return false;
	}

	/**
	 * Devuelve la URL de unirse si la clase está activa (dentro de los 10 min previos).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_get_join_url( \WP_REST_Request $request ) {
		$lesson_id = absint( $request->get_param( 'lesson_id' ) );

		// Defensa en profundidad: evita exponer join_url si el handler se invoca fuera del router REST.
		if ( ! self::can_access_join_url( $request ) ) {
			return new \WP_Error( 'forbidden', __( 'No tienes acceso a esta clase en vivo.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$session   = get_post_meta( $lesson_id, self::LESSON_META, true );

		if ( empty( $session['start_datetime'] ) ) {
			return new \WP_Error( 'no_session', __( 'Esta lección no tiene clase en vivo.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$start   = strtotime( $session['start_datetime'] );
		$now     = time();
		$open_at = $start - 10 * MINUTE_IN_SECONDS;

		if ( $now < $open_at ) {
			$diff = $start - $now;
			return rest_ensure_response( array(
				'status'        => 'not_started',
				'starts_in_sec' => $diff,
				'message'       => sprintf( __( 'La clase comienza en %d minutos.', 'atora-lms' ), ceil( $diff / 60 ) ),
			) );
		}

		$url = $session['join_url'] ?? $session['custom_url'] ?? '';

		return rest_ensure_response( array(
			'status'   => 'active',
			'join_url' => $url,
			'provider' => $session['provider'],
		) );
	}

	// ── Admin ─────────────────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function register_admin_menu(): void {
		// Sprint UX: Live Streaming se opera desde el metabox de lecciones.
		// Se mantiene esta ruta vacía para compatibilidad del hook legacy.
	}

	// ── Calendario ────────────────────────────────────────────────────────────

	/**
	 * Crea un evento en el calendario ATORA al guardar una lección live.
	 *
	 * @param int   $lesson_id ID de la lección.
	 * @param array $session   Datos de la sesión.
	 * @return void
	 */
	public static function create_calendar_event( int $lesson_id, array $session ): void {
		if ( empty( $session['start_datetime'] ) || ! class_exists( 'ATORA\Calendar\Calendar' ) ) {
			return;
		}

		$course_id = absint( get_post_meta( $lesson_id, '_clms_course_id', true ) );

		\ATORA\Calendar\Calendar::create_event( array(
			'title'          => sprintf( __( 'Clase en vivo: %s', 'atora-lms' ), get_the_title( $lesson_id ) ),
			'event_type'     => 'live_class',
			'start_datetime' => $session['start_datetime'],
			'end_datetime'   => gmdate(
				'Y-m-d H:i:s',
				strtotime( $session['start_datetime'] ) + absint( $session['duration_minutes'] ?? 60 ) * 60
			),
			'course_id'      => $course_id,
			'lesson_id'      => $lesson_id,
			'location'       => $session['join_url'] ?? $session['custom_url'] ?? '',
		) );
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function maybe_enqueue_assets(): void {
		if ( ! is_singular( 'lm_lesson' ) ) {
			return;
		}

		$session = get_post_meta( get_the_ID(), self::LESSON_META, true );
		if ( empty( $session['provider'] ) ) {
			return;
		}

		wp_enqueue_script(
			'atora-live',
			ATORA_LMS_MODULES_URL . 'live-streaming/assets/live.js',
			array( 'jquery' ),
			ATORA_LMS_VERSION,
			true
		);

		wp_localize_script( 'atora-live', 'atoraLive', array(
			'rest_url'  => esc_url( rest_url( 'atora/v1/live/' . get_the_ID() . '/join-url' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'lesson_id' => get_the_ID(),
			'i18n'      => array(
				'join'        => __( 'Unirse a la clase', 'atora-lms' ),
				'not_started' => __( 'La clase aún no ha comenzado', 'atora-lms' ),
				'loading'     => __( 'Verificando estado de la clase…', 'atora-lms' ),
			),
		) );
	}

	// ── Zoom helpers ──────────────────────────────────────────────────────────

	/**
	 * Obtiene un access token de Zoom (Server-to-Server OAuth).
	 *
	 * @return string|null
	 */
	private static function get_zoom_access_token(): ?string {
		$cached = get_transient( 'atora_zoom_access_token' );
		if ( $cached ) {
			return $cached;
		}

		$opts = get_option( 'atora_live_streaming_options', array() );

		if ( empty( $opts['zoom_client_id'] ) || empty( $opts['zoom_client_secret'] ) || empty( $opts['zoom_account_id'] ) ) {
			return null;
		}

		$credentials = base64_encode( $opts['zoom_client_id'] . ':' . $opts['zoom_client_secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$response = wp_remote_post(
			'https://zoom.us/oauth/token?grant_type=account_credentials&account_id=' . rawurlencode( $opts['zoom_account_id'] ),
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . $credentials,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['access_token'] ) ) {
			return null;
		}

		$expires = (int) ( $data['expires_in'] ?? 3600 ) - 60;
		set_transient( 'atora_zoom_access_token', $data['access_token'], $expires );

		return $data['access_token'];
	}

	/**
	 * Verifica la firma del webhook de Zoom.
	 *
	 * @param string $body      Body del request.
	 * @param string $signature Firma recibida en el header.
	 * @return bool
	 */
	private static function verify_zoom_webhook_signature( string $body, string $signature ): bool {
		$opts = get_option( 'atora_live_streaming_options', array() );
		$secret = (string) ( $opts['zoom_webhook_secret'] ?? '' );
		$secret = trim( $secret );

		if ( ! $secret ) {
			return defined( 'ATORA_DEV_MODE' ) && ATORA_DEV_MODE;
		}

		$expected = hash_hmac( 'sha256', $body, $secret );

		return hash_equals( 'v0=' . $expected, $signature );
	}
}
