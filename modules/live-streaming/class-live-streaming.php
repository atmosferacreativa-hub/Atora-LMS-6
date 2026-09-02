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

use ATORA\LiveStreaming\Providers\Provider_Zoom;

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

	/** Etiquetas legibles por provider — para el selector del metabox (C4, 6.13.1). */
	const PROVIDER_LABELS = array(
		'zoom'    => 'Zoom',
		'meet'    => 'Google Meet',
		'teams'   => 'Microsoft Teams',
		'youtube' => 'YouTube Live',
		'custom'  => 'Enlace personalizado',
	);

	/**
	 * C4 (6.13.1): qué puede hacer cada provider — el metabox filtra la
	 * UI de asistencia según esto en vez de ofrecerla para todos. Teams y
	 * YouTube no tienen integración de API en este release (solo se
	 * guarda el link), así que declaran 'create' únicamente — misma idea
	 * que Provider_Interface::supports(), sin necesitar una clase
	 * Provider_* completa para providers que hoy no integran ninguna API.
	 *
	 * @return array<string,string[]>
	 */
	public static function get_provider_capabilities(): array {
		return array(
			'zoom'    => class_exists( '\ATORA\LiveStreaming\Providers\Provider_Zoom' ) ? Provider_Zoom::supports() : array( 'create' ),
			'meet'    => class_exists( '\ATORA\LiveStreaming\Providers\Provider_Meet' ) ? \ATORA\LiveStreaming\Providers\Provider_Meet::supports() : array( 'create' ),
			'teams'   => array( 'create' ),
			'youtube' => array( 'create' ),
			'custom'  => array( 'create' ),
		);
	}

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
		// P2 (6.12.0): gateado por módulo 'live-streaming'.
		if ( ! class_exists( '\CLMS_Module_Registry' ) || \CLMS_Module_Registry::is_active( 'live-streaming' ) ) {
			add_action( 'rest_api_init',            array( __CLASS__, 'register_rest_routes' ) );

			// Webhooks entrantes (Zoom).
			add_action( 'rest_api_init',            array( __CLASS__, 'register_webhook_routes' ) );
		}

		// Recordatorios: cron.
		add_action( 'atora_live_reminders_cron', array( __CLASS__, 'process_reminders' ) );
		if ( ! wp_next_scheduled( 'atora_live_reminders_cron' ) ) {
			wp_schedule_event( time(), 'every_5_minutes', 'atora_live_reminders_cron' );
		}

		// P8.4 (6.13.0): fallback de polling de asistencia Meet — reusa
		// este mismo cron de 5 minutos en vez de crear uno nuevo.
		if ( class_exists( '\ATORA\LiveStreaming\Providers\Provider_Meet' ) ) {
			add_action( 'atora_live_reminders_cron', array( '\ATORA\LiveStreaming\Providers\Provider_Meet', 'poll_active_sessions' ) );
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
			$lesson  = get_post( $post_id );
			$meeting = Provider_Zoom::create_session( array_merge( $session, array( 'title' => $lesson->post_title ) ) );

			if ( ! is_wp_error( $meeting ) ) {
				$session = array_merge( $session, $meeting );
			}
		}

		// P8 (6.13.0): igual que Zoom, pero SIEMPRE creado por ATORA — un
		// link de Meet pegado a mano (custom_url) nunca tiene asistencia,
		// ver la nota de restricción en Provider_Meet.
		if ( 'meet' === $provider && empty( $session['meeting_id'] ) && ! empty( $session['start_datetime'] )
			&& class_exists( '\ATORA\LiveStreaming\Providers\Provider_Meet' ) ) {
			$lesson  = get_post( $post_id );
			$meeting = \ATORA\LiveStreaming\Providers\Provider_Meet::create_session( array_merge( $session, array(
				'title'         => $lesson->post_title,
				'actor_user_id' => get_current_user_id(),
			) ) );

			if ( ! is_wp_error( $meeting ) ) {
				$session = array_merge( $session, $meeting );
			}
		}

		update_post_meta( $post_id, self::LESSON_META, $session );

		// P6 (6.13.0): escritura dual hacia atora_live_sessions, aditiva —
		// no cambia el postmeta que sigue siendo la fuente de verdad hasta
		// el cutover (sin cutover en este release).
		if ( class_exists( '\ATORA\LiveStreaming\Live_Session_Repository' ) ) {
			Live_Session_Repository::upsert_session( $post_id, $session );
		}

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

		// PT-1 (6.5.10): CLMS_Helper es global; este archivo vive bajo
		// namespace ATORA\LiveStreaming — sin backslash se resuelve a
		// ATORA\LiveStreaming\CLMS_Helper (inexistente), fatal en el cron
		// de recordatorios (atora_live_reminders_cron).
		$students = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_students' )
			? (array) \CLMS_Helper::get_course_students( $course_id )
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
		// La validación real se hace en Provider_Zoom::handle_webhook() por
		// firma (P7, 6.13.0: movido desde Live_Streaming sin cambios
		// funcionales).
		register_rest_route( 'atora/v1', '/webhooks/zoom', array(
			'methods'             => 'POST',
			'callback'            => array( Provider_Zoom::class, 'handle_webhook' ),
			'permission_callback' => '__return_true',
		) );
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

}
