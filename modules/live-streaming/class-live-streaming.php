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
			'teams'   => class_exists( '\\ATORA\\LiveStreaming\\Providers\\Provider_Teams' ) ? \ATORA\LiveStreaming\Providers\Provider_Teams::supports() : array( 'create' ),
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
		add_action( 'post_edit_form_tag',       array( __CLASS__, 'form_enctype' ) );

		// REST endpoints.
		// P2 (6.12.0): gateado por módulo 'live-streaming'.
		if ( ! class_exists( '\CLMS_Module_Registry' ) || \CLMS_Module_Registry::is_active( 'live-streaming' ) ) {
			add_action( 'rest_api_init',            array( __CLASS__, 'register_rest_routes' ) );

			// Webhooks entrantes (Zoom).
			add_action( 'rest_api_init',            array( __CLASS__, 'register_webhook_routes' ) );
		}

		// Recordatorios y cierre de sesiones (asistencia y grabación): cron.
		add_action( 'atora_live_reminders_cron', array( __CLASS__, 'process_reminders' ) );
		add_action( 'atora_live_reminders_cron', array( __CLASS__, 'finalize_sessions' ) );
		if ( ! wp_next_scheduled( 'atora_live_reminders_cron' ) ) {
			wp_schedule_event( time(), 'every_5_minutes', 'atora_live_reminders_cron' );
		}

		// 6.39.0: la asistencia de Meet (y Teams) se toma al cerrar la sesión, en finalize_sessions(): antes Meet
		// marcaba la sesión terminada en la primera consulta, apenas empezaba la clase.

		// Crear evento en calendario al guardar lección live.
		add_action( 'atora/live/session_created', array( __CLASS__, 'create_calendar_event' ), 10, 2 );

		// Admin settings.
		if ( is_admin() && class_exists( '\\ATORA\\LiveStreaming\\Live_Settings' ) ) {
			Live_Settings::init();
		}
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
	 * Guarda la clase en vivo de la lección (6.39.0).
	 *
	 * - La reunión se crea UNA vez: antes la sesión se rearmaba desde el formulario sin el `meeting_id` guardado y
	 *   cada guardado creaba otra reunión en Zoom o Meet.
	 * - Reprogramar actualiza la reunión existente; cambiar de proveedor crea una nueva.
	 * - La hora se guarda también en UTC (`start_utc`) con la zona de la clase.
	 * - La grabación es decisión del docente (`recording_mode`): none | upload | provider.
	 */
	public static function save_metabox( int $post_id ): void {
		if ( ! isset( $_POST['atora_live_nonce'] ) ||
		     ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['atora_live_nonce'] ) ), 'atora_live_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$input = array(
			'provider'         => sanitize_key( wp_unslash( $_POST['atora_live_provider'] ?? '' ) ),
			'start_datetime'   => sanitize_text_field( wp_unslash( $_POST['atora_live_start'] ?? '' ) ),
			'duration_minutes' => absint( wp_unslash( $_POST['atora_live_duration'] ?? 60 ) ),
			'timezone'         => sanitize_text_field( wp_unslash( $_POST['atora_live_tz'] ?? '' ) ),
			'recording_mode'   => sanitize_key( wp_unslash( $_POST['atora_live_recording_mode'] ?? 'none' ) ),
			'max_participants' => absint( wp_unslash( $_POST['atora_live_max'] ?? 0 ) ),
			'custom_url'       => esc_url_raw( wp_unslash( $_POST['atora_live_custom_url'] ?? '' ) ),
			'notes'            => sanitize_textarea_field( wp_unslash( $_POST['atora_live_notes'] ?? '' ) ),
		);
		self::save_session( $post_id, $input, get_current_user_id() );
		self::save_recording_from_form( $post_id );
	}

	/**
	 * Guarda la sesión sin duplicar la reunión. También la usan la REST y las pruebas.
	 *
	 * @return array|\WP_Error La sesión guardada; WP_Error si el proveedor falló (la sesión se guarda igual, sin
	 *                         reunión, para no perder los datos del docente).
	 */
	public static function save_session( int $lesson_id, array $input, int $actor_user_id ) {
		$provider = sanitize_key( (string) ( $input['provider'] ?? '' ) );
		if ( ! $provider || ! in_array( $provider, self::PROVIDERS, true ) ) {
			delete_post_meta( $lesson_id, self::LESSON_META );
			return array();
		}
		$existing = get_post_meta( $lesson_id, self::LESSON_META, true );
		$existing = is_array( $existing ) ? $existing : array();
		$tz       = \ATORA_Live_Session_Service::tz( (string) ( $input['timezone'] ?? '' ) );
		$mode     = in_array( (string) ( $input['recording_mode'] ?? '' ), \ATORA_Live_Session_Service::RECORDING_MODES, true ) ? (string) $input['recording_mode'] : 'none';
		$session  = array(
			'provider'         => $provider,
			'start_datetime'   => str_replace( 'T', ' ', (string) ( $input['start_datetime'] ?? '' ) ),
			'duration_minutes' => max( 5, absint( $input['duration_minutes'] ?? 60 ) ),
			'timezone'         => $tz,
			'recording_mode'   => $mode,
			'record'           => 'provider' === $mode, // Compatibilidad con lectores anteriores.
			'max_participants' => absint( $input['max_participants'] ?? 0 ),
			'custom_url'       => esc_url_raw( (string) ( $input['custom_url'] ?? '' ) ),
			'notes'            => sanitize_textarea_field( (string) ( $input['notes'] ?? '' ) ),
		);
		$session['start_utc'] = \ATORA_Live_Session_Service::utc_from_local( $session['start_datetime'], $tz );

		$same_provider = ( $existing['provider'] ?? '' ) === $provider;
		$keep          = array( 'meeting_id', 'external_id', 'join_url', 'start_url', 'password', 'calendar_event_id', 'created_by_user_id', 'atora_calendar_event_id' );
		if ( $same_provider ) {
			foreach ( $keep as $key ) {
				if ( isset( $existing[ $key ] ) ) {
					$session[ $key ] = $existing[ $key ];
				}
			}
		} elseif ( isset( $existing['atora_calendar_event_id'] ) ) {
			$session['atora_calendar_event_id'] = $existing['atora_calendar_event_id'];
		}
		if ( 'custom' === $provider || 'youtube' === $provider ) {
			$session['join_url'] = $session['custom_url'];
		}

		$changed = (int) ( $existing['start_utc'] ?? 0 ) !== (int) $session['start_utc']
			|| (int) ( $existing['duration_minutes'] ?? 0 ) !== (int) $session['duration_minutes']
			|| \ATORA_Live_Session_Service::recording_mode( $existing ) !== $mode;

		$error = null;
		$class = self::provider_class( $provider );
		if ( $class && $session['start_utc'] ) {
			$payload = array_merge( $session, array(
				'title'         => get_the_title( $lesson_id ),
				'record'        => 'provider' === $mode,
				'actor_user_id' => $actor_user_id,
				'lesson_id'     => $lesson_id,
			) );
			if ( empty( $session['meeting_id'] ) ) {
				$meeting = $class::create_session( $payload );
				if ( is_wp_error( $meeting ) ) {
					$error = $meeting;
				} else {
					$session = array_merge( $session, $meeting );
				}
			} elseif ( $changed && method_exists( $class, 'update_session' ) ) {
				$updated = $class::update_session( (string) $session['meeting_id'], $payload );
				if ( is_wp_error( $updated ) ) {
					$error = $updated;
				} elseif ( is_array( $updated ) ) {
					$session = array_merge( $session, $updated );
				}
			}
		}

		if ( (int) ( $existing['start_utc'] ?? 0 ) !== (int) $session['start_utc'] ) {
			// Reprogramada: los recordatorios vuelven a salir para la nueva fecha.
			delete_post_meta( $lesson_id, '_atora_live_reminder_24h_sent' );
			delete_post_meta( $lesson_id, '_atora_live_reminder_1h_sent' );
		}

		update_post_meta( $lesson_id, self::LESSON_META, $session );
		if ( class_exists( '\\ATORA\\LiveStreaming\\Live_Session_Repository' ) ) {
			Live_Session_Repository::upsert_session( $lesson_id, $session );
		}
		do_action( 'atora/live/session_created', $lesson_id, $session );
		if ( $error ) {
			update_post_meta( $lesson_id, '_atora_live_provider_error', $error->get_error_message() );
			return $error;
		}
		delete_post_meta( $lesson_id, '_atora_live_provider_error' );
		return $session;
	}

	/**
	 * Cierre de sesiones terminadas (cron cada 5 min, 6.39.0): asistencia definitiva y, si el docente eligió el enlace
	 * automático, la grabación del proveedor (se busca hasta 48 h después; no reemplaza la que el docente puso o quitó).
	 *
	 * @return int sesiones atendidas
	 */
	public static function finalize_sessions( ?int $now = null ): int {
		global $wpdb;
		$now   = $now ?? time();
		$table = $wpdb->prefix . 'atora_live_sessions';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return 0;
		}
		$done = 0;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT * FROM {$table} WHERE status IN ('scheduled','live') AND start_datetime IS NOT NULL
			   AND DATE_ADD(start_datetime, INTERVAL (duration_minutes + 15) MINUTE) < %s
			 ORDER BY start_datetime ASC LIMIT 20",
			gmdate( 'Y-m-d H:i:s', $now )
		), ARRAY_A );
		foreach ( $rows as $row ) {
			$class = self::provider_class( (string) $row['provider'] );
			if ( $class && method_exists( $class, 'collect_attendance' ) && ! empty( $row['external_id'] ) ) {
				$result = $class::collect_attendance( $row );
				if ( is_wp_error( $result ) && $now < strtotime( $row['start_datetime'] . ' UTC' ) + DAY_IN_SECONDS ) {
					continue; // El proveedor aún no tiene el informe: se reintenta en el próximo ciclo (hasta 24 h).
				}
			}
			$wpdb->update( $table, array( 'status' => 'ended' ), array( 'id' => (int) $row['id'] ) );
			do_action( 'atora/live/session_ended', (int) $row['lesson_id'], $row );
			$done++;
		}

		$recent = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT * FROM {$table} WHERE status = 'ended' AND (recording_url = '' OR recording_url IS NULL)
			   AND external_id IS NOT NULL AND start_datetime > %s ORDER BY start_datetime DESC LIMIT 20",
			gmdate( 'Y-m-d H:i:s', $now - 2 * DAY_IN_SECONDS )
		), ARRAY_A );
		foreach ( $recent as $row ) {
			$lesson = (int) $row['lesson_id'];
			$class  = self::provider_class( (string) $row['provider'] );
			if ( ! $class || ! method_exists( $class, 'fetch_recording' ) || ! \ATORA_Live_Session_Service::provider_may_set_recording( $lesson ) || \ATORA_Live_Session_Service::recording( $lesson ) ) {
				continue;
			}
			$rec = $class::fetch_recording( $row );
			if ( is_array( $rec ) && ! empty( $rec['url'] ) ) {
				self::store_provider_recording( $lesson, (string) $row['provider'], (string) $rec['url'], (string) ( $rec['note'] ?? '' ) );
			}
		}
		return $done;
	}

	/** Guarda la grabación que entrega el proveedor (si el docente la pidió y no la cambió a mano). */
	public static function store_provider_recording( int $lesson_id, string $provider, string $url, string $note = '' ): bool {
		global $wpdb;
		if ( ! \ATORA_Live_Session_Service::provider_may_set_recording( $lesson_id ) ) {
			return false;
		}
		$ok = \ATORA_Live_Session_Service::set_recording( $lesson_id, array( 'url' => $url, 'source' => $provider, 'note' => $note ) );
		if ( true === $ok ) {
			$wpdb->update( $wpdb->prefix . 'atora_live_sessions', array( 'recording_url' => esc_url_raw( $url ) ), array( 'lesson_id' => $lesson_id, 'provider' => $provider ) );
		}
		return true === $ok;
	}

	/** Clase del proveedor con API (null si solo se guarda un enlace). */
	public static function provider_class( string $provider ): ?string {
		$map = array(
			'zoom'  => '\\ATORA\\LiveStreaming\\Providers\\Provider_Zoom',
			'meet'  => '\\ATORA\\LiveStreaming\\Providers\\Provider_Meet',
			'teams' => '\\ATORA\\LiveStreaming\\Providers\\Provider_Teams',
		);
		$class = $map[ $provider ] ?? null;
		return $class && class_exists( $class ) ? $class : null;
	}

	/** Grabación desde el metabox: subir video, pegar enlace o quitarla. */
	private static function save_recording_from_form( int $lesson_id ): void {
		if ( ! empty( $_POST['atora_live_recording_clear'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificado en save_metabox().
			\ATORA_Live_Session_Service::clear_recording( $lesson_id );
			return;
		}
		if ( ! empty( $_FILES['atora_live_recording_file']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachment = media_handle_upload( 'atora_live_recording_file', $lesson_id, array(), array( 'test_form' => false, 'mimes' => \ATORA_Live_Session_Service::VIDEO_FORMATS ) );
			if ( is_wp_error( $attachment ) ) {
				update_post_meta( $lesson_id, '_atora_live_provider_error', $attachment->get_error_message() );
				return;
			}
			\ATORA_Live_Session_Service::set_recording( $lesson_id, array( 'attachment_id' => (int) $attachment ), get_current_user_id() );
			return;
		}
		$url = trim( (string) wp_unslash( $_POST['atora_live_recording_url'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$now = \ATORA_Live_Session_Service::recording( $lesson_id );
		if ( '' !== $url && ( ! $now || $now['url'] !== $url ) ) {
			\ATORA_Live_Session_Service::set_recording( $lesson_id, array( 'url' => $url ), get_current_user_id() );
		}
	}

	/** El formulario de la lección debe poder subir archivos (video de la grabación). */
	public static function form_enctype( \WP_Post $post ): void {
		if ( 'lm_lesson' === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	// ── Recordatorios ─────────────────────────────────────────────────────────

	/**
	 * Recordatorios 24 h y 1 h antes (6.39.0). Antes el de 1 h no salía nunca después del de 24 h (la consulta
	 * excluía esas lecciones), la hora local se leía como UTC y se enviaban de forma síncrona. Ahora salen por la
	 * cola de correo, con la hora real, y se reinician al reprogramar.
	 */
	public static function process_reminders( ?int $now = null ): int {
		global $wpdb;
		$now   = $now ?? time();
		$table = $wpdb->prefix . 'atora_live_sessions';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return 0;
		}
		$lessons = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT DISTINCT lesson_id FROM {$table} WHERE status = 'scheduled' AND start_datetime BETWEEN %s AND %s LIMIT 200",
			gmdate( 'Y-m-d H:i:s', $now ),
			gmdate( 'Y-m-d H:i:s', $now + 25 * HOUR_IN_SECONDS )
		) ) );
		$sent = 0;
		foreach ( $lessons as $lesson_id ) {
			$session = \ATORA_Live_Session_Service::get( $lesson_id );
			if ( ! $session || $session['start_utc'] <= $now ) {
				continue;
			}
			$left = $session['start_utc'] - $now;
			if ( $left <= HOUR_IN_SECONDS + 300 && ! get_post_meta( $lesson_id, '_atora_live_reminder_1h_sent', true ) ) {
				$sent += self::send_reminder( $lesson_id, $session, '1h' );
				update_post_meta( $lesson_id, '_atora_live_reminder_1h_sent', 1 );
				update_post_meta( $lesson_id, '_atora_live_reminder_24h_sent', 1 ); // Ya no tiene sentido el de 24 h.
			} elseif ( $left <= 25 * HOUR_IN_SECONDS && $left > 2 * HOUR_IN_SECONDS && ! get_post_meta( $lesson_id, '_atora_live_reminder_24h_sent', true ) ) {
				$sent += self::send_reminder( $lesson_id, $session, '24h' );
				update_post_meta( $lesson_id, '_atora_live_reminder_24h_sent', 1 );
			}
		}
		return $sent;
	}
	private static function send_reminder( int $lesson_id, array $session, string $when ): int {
		$course_id = absint( get_post_meta( $lesson_id, '_clms_course_id', true ) );
		if ( ! $course_id || ! class_exists( '\\ATORA\\EmailEngine\\Email_Queue' ) ) {
			return 0;
		}
		// 6.39.0: antes llamaba a CLMS_Helper::get_course_students, que no existe: ningún recordatorio salía.
		$students = class_exists( 'CLMS_Helper' ) ? (array) \CLMS_Helper::get_enrolled_student_ids( $course_id ) : array();
		$n = 0;
		foreach ( $students as $student ) {
			$user_id = is_object( $student ) ? (int) $student->ID : absint( $student );
			$user    = $user_id ? get_userdata( $user_id ) : null;
			if ( ! $user ) {
				continue;
			}
			// La hora en la zona del estudiante si la tiene; si no, en la de la clase.
			$zone = (string) get_user_meta( $user_id, 'atora_timezone', true );
			$zone = \ATORA_Live_Session_Service::tz( '' !== $zone ? $zone : $session['timezone'] );
			$when_text = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $session['start_utc'], new \DateTimeZone( $zone ) ) . ' (' . $zone . ')';
			$queued    = \ATORA\EmailEngine\Email_Queue::enqueue( array(
				'template' => 'atora_live_reminder',
				'user_id'  => $user_id,
				'priority' => '1h' === $when ? 'high' : 'medium',
				'metadata' => array(
					'lesson_id'      => $lesson_id,
					'course_id'      => $course_id,
					'student_name'   => $user->display_name,
					'lesson_title'   => get_the_title( $lesson_id ),
					'starts_at'      => $when_text,
					'provider_label' => $session['provider_label'],
					'reminder'       => $when,
					// Enlace a la lección (el de la reunión solo se entrega a matriculados con la clase abierta).
					'button_url'     => (string) get_permalink( $lesson_id ),
				),
			) );
			$n += $queued ? 1 : 0;
		}
		return $n;
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
		register_rest_route( 'atora/v1', '/live/(?P<lesson_id>\d+)/recording', array(
			'methods'             => array( 'POST', 'DELETE' ),
			'callback'            => array( __CLASS__, 'rest_set_recording' ),
			'permission_callback' => static fn() => is_user_logged_in(),
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
	 * Estado de la clase y enlace (6.39.0): se abre 10 min antes y se cierra 15 min después del final; la hora se
	 * calcula en UTC con la zona de la clase; al docente se le da el enlace de anfitrión si existe.
	 */
	public static function rest_get_join_url( \WP_REST_Request $request ) {
		$lesson_id = absint( $request->get_param( 'lesson_id' ) );
		if ( ! self::can_access_join_url( $request ) ) {
			return new \WP_Error( 'forbidden', __( 'No tienes acceso a esta clase en vivo.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$info = \ATORA_Live_Session_Service::join_info( $lesson_id, get_current_user_id() );
		if ( ! $info ) {
			return new \WP_Error( 'no_session', __( 'Esta lección no tiene clase en vivo.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$info['message'] = 'scheduled' === $info['status']
			/* translators: %d: minutes */
			? sprintf( __( 'La clase comienza en %d minutos.', 'atora-lms' ), (int) ceil( (int) $info['starts_in_sec'] / 60 ) )
			: ( 'ended' === $info['status'] ? __( 'La clase terminó.', 'atora-lms' ) : '' );
		// Compatibilidad: 'active' era el estado abierto.
		$info['state'] = $info['status'];
		if ( 'open' === $info['status'] ) {
			$info['status'] = 'active';
		} elseif ( 'scheduled' === $info['status'] ) {
			$info['status'] = 'not_started';
		}
		return rest_ensure_response( $info );
	}

	/** Publicar o quitar la grabación (docente del curso). */
	public static function rest_set_recording( \WP_REST_Request $request ) {
		$lesson_id = absint( $request->get_param( 'lesson_id' ) );
		if ( ! \ATORA_Live_Session_Service::can_manage( get_current_user_id(), $lesson_id ) ) {
			return new \WP_Error( 'forbidden', __( 'Solo el docente del curso puede cambiar la grabación.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		if ( 'DELETE' === $request->get_method() ) {
			\ATORA_Live_Session_Service::clear_recording( $lesson_id );
			return rest_ensure_response( array( 'recording' => null ) );
		}
		$result = \ATORA_Live_Session_Service::set_recording( $lesson_id, array(
			'url'           => (string) $request->get_param( 'url' ),
			'attachment_id' => absint( $request->get_param( 'attachment_id' ) ),
		), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return rest_ensure_response( array( 'recording' => \ATORA_Live_Session_Service::recording( $lesson_id ) ) );
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

	/** Un solo evento de calendario por clase; reprogramar lo actualiza (antes cada guardado creaba otro). */
	public static function create_calendar_event( int $lesson_id, array $session ): void {
		global $wpdb;
		if ( empty( $session['start_datetime'] ) || ! class_exists( 'ATORA\\Calendar\\Calendar' ) ) {
			return;
		}
		$table = $wpdb->prefix . 'atora_calendar_events';
		$data  = array(
			'title'          => sprintf( /* translators: %s: lesson */ __( 'Clase en vivo: %s', 'atora-lms' ), get_the_title( $lesson_id ) ),
			'event_type'     => 'live_class',
			'start_datetime' => (string) $session['start_datetime'],
			'end_datetime'   => gmdate( 'Y-m-d H:i:s', strtotime( (string) $session['start_datetime'] ) + absint( $session['duration_minutes'] ?? 60 ) * 60 ),
			'course_id'      => absint( get_post_meta( $lesson_id, '_clms_course_id', true ) ),
			'lesson_id'      => $lesson_id,
			'location'       => (string) get_permalink( $lesson_id ),
		);
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE lesson_id = %d AND event_type = 'live_class' ORDER BY id ASC LIMIT 1", $lesson_id ) ); // phpcs:ignore WordPress.DB
		if ( $existing ) {
			$wpdb->update( $table, array( 'title' => $data['title'], 'start_datetime' => $data['start_datetime'], 'end_datetime' => $data['end_datetime'], 'location' => $data['location'] ), array( 'id' => $existing ) );
			return;
		}
		\ATORA\Calendar\Calendar::create_event( $data );
	}
	// ── Assets ────────────────────────────────────────────────────────────────

	/** 6.39.0: el script que se encolaba aquí no existía; la tarjeta de la lección se dibuja en el servidor. */
	public static function maybe_enqueue_assets(): void {
	}
}
