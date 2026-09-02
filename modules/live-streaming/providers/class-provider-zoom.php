<?php
/**
 * Provider_Zoom — P7 (sprint 6.13.0)
 *
 * Zoom movido tal cual desde Live_Streaming a su propio provider, sin
 * cambios funcionales: mismas opciones ('atora_live_streaming_options'),
 * mismo transient de access token, misma verificación de firma de
 * webhook, mismos hooks disparados (atora/live/zoom_webhook,
 * atora/live/meeting_ended, atora/live/recording_available,
 * atora/live/attendance_recorded) y misma escritura en usermeta
 * (_atora_live_attendance_{lesson_id}) para no romper nada que ya la lea.
 * La única adición es la escritura dual hacia atora_live_sessions /
 * atora_attendance (P6) — puramente aditiva.
 *
 * @package ATORA_LMS\LiveStreaming\Providers
 * @since   6.13.0
 */

namespace ATORA\LiveStreaming\Providers;

use ATORA\LiveStreaming\Live_Session_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Provider_Zoom implements Provider_Interface {

	/**
	 * @param array $data Datos de la reunión: title, start_datetime,
	 *                     duration_minutes, timezone, record.
	 * @return array|\WP_Error
	 */
	public static function create_session( array $data ) {
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
				'auto_recording'   => ! empty( $data['record'] ) ? 'cloud' : 'none',
				'waiting_room'     => true,
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
			'external_id' => $result['id'],
			'join_url'    => $result['join_url'],
			'start_url'   => $result['start_url'],
			'password'    => $result['password'] ?? '',
		);
	}

	/**
	 * Zoom no calcula join URLs por usuario — se lee la ya guardada en
	 * atora_live_sessions.
	 *
	 * @param int $session_id
	 * @param int $user_id Sin uso: Zoom no diferencia join_url por usuario.
	 * @return string|\WP_Error
	 */
	public static function get_join_url( int $session_id, int $user_id ) {
		unset( $user_id );

		global $wpdb;
		$table = $wpdb->prefix . 'atora_live_sessions';
		$url   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT join_url FROM {$table} WHERE id = %d", $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( '' === $url ) {
			return new \WP_Error( 'zoom_no_session', __( 'Sesión no encontrada.', 'atora-lms' ) );
		}

		return $url;
	}

	/**
	 * @param string $external_id Meeting ID de Zoom.
	 * @return array
	 */
	public static function fetch_attendance( string $external_id ) {
		$token = self::get_zoom_access_token();
		if ( ! $token ) {
			return array();
		}

		$response = wp_remote_get(
			"https://api.zoom.us/v2/past_meetings/{$external_id}/participants",
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

	/**
	 * @return string[]
	 */
	public static function supports(): array {
		return array( 'create', 'attendance', 'recording', 'webhook' );
	}

	// ── Webhooks ──────────────────────────────────────────────────────────────

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$signature = $request->get_header( 'x-zm-signature' );
		if ( ! self::verify_zoom_webhook_signature( $request->get_body(), $signature ) ) {
			return new \WP_REST_Response( array( 'status' => 'invalid_signature' ), 403 );
		}

		$payload    = $request->get_json_params();
		$event_type = sanitize_text_field( $payload['event'] ?? '' );

		switch ( $event_type ) {
			case 'meeting.ended':
				self::handle_meeting_ended( $payload['payload'] ?? array() );
				break;

			case 'recording.completed':
				self::handle_recording_completed( $payload['payload'] ?? array() );
				break;
		}

		do_action( 'atora/live/zoom_webhook', $event_type, $payload );

		return rest_ensure_response( array( 'status' => 'ok' ) );
	}

	/**
	 * @param array $payload
	 * @return void
	 */
	private static function handle_meeting_ended( array $payload ): void {
		$meeting_id = sanitize_text_field( $payload['object']['id'] ?? '' );
		if ( ! $meeting_id ) {
			return;
		}

		$lessons = get_posts( array(
			'post_type'      => 'lm_lesson',
			'meta_key'       => \ATORA\LiveStreaming\Live_Streaming::LESSON_META,
			'fields'         => 'ids',
			'posts_per_page' => 5,
		) );

		foreach ( $lessons as $lesson_id ) {
			$session = get_post_meta( $lesson_id, \ATORA\LiveStreaming\Live_Streaming::LESSON_META, true );
			if ( ( $session['meeting_id'] ?? '' ) !== $meeting_id ) {
				continue;
			}

			$participants = self::fetch_attendance( $meeting_id );
			self::record_attendance( (int) $lesson_id, $participants, $payload );

			do_action( 'atora/live/meeting_ended', $lesson_id, $meeting_id, $participants );
			break;
		}
	}

	/**
	 * @param array $payload
	 * @return void
	 */
	private static function handle_recording_completed( array $payload ): void {
		$meeting_id   = sanitize_text_field( $payload['object']['id'] ?? '' );
		$download_url = esc_url_raw( $payload['object']['recording_files'][0]['download_url'] ?? '' );

		if ( ! $meeting_id || ! $download_url ) {
			return;
		}

		do_action( 'atora/live/recording_available', $meeting_id, $download_url );
	}

	/**
	 * Registra la asistencia de los estudiantes a una clase — usermeta
	 * (comportamiento existente, sin cambios) + escritura dual en
	 * atora_attendance (P6, aditiva).
	 *
	 * @param int   $lesson_id
	 * @param array $participants
	 * @param array $payload
	 * @return void
	 */
	private static function record_attendance( int $lesson_id, array $participants, array $payload ): void {
		$duration_mins = (int) ( $payload['object']['duration'] ?? 60 );
		$course_id     = absint( get_post_meta( $lesson_id, '_clms_course_id', true ) );
		$session_row   = Live_Session_Repository::get_by_lesson( $lesson_id );
		$session_id    = $session_row['id'] ?? 0;

		// E1 (6.13.3): agregar por identidad ANTES de recorrer — igual que
		// Meet ya hace con participantSessions. /past_meetings/{id}/participants
		// devuelve un registro crudo por tramo de conexión: quien se
		// desconecta y vuelve a entrar aparece varias veces, y sin
		// agregar, cada tramo se evaluaba por separado contra la
		// duración TOTAL de la clase — un alumno presente la clase
		// completa pero con un corte de conexión a la mitad podía
		// evaluarse como "parcial" en cada tramo, no como "presente" una
		// vez sobre el total. Con la conectividad real de Venezuela esto
		// no es un caso de borde, es el caso normal.
		$groups = array();
		foreach ( $participants as $participant ) {
			$email = sanitize_email( $participant['user_email'] ?? '' );
			// `id` es el user ID de la cuenta de Zoom — vacío para quien
			// no inició sesión. `user_id` es el UUID de participante de
			// ESA reunión, la única clave que Zoom da para un invitado
			// sin cuenta (D1.1, 6.13.2).
			$raw_uid = (string) ( $participant['user_id'] ?? ( $participant['id'] ?? '' ) );
			$name    = sanitize_text_field( (string) ( $participant['name'] ?? '' ) );

			if ( '' !== $email ) {
				$key = 'email:' . strtolower( $email );
			} elseif ( '' !== $raw_uid ) {
				$key = 'anon:' . $raw_uid;
			} else {
				// Último recurso: dos anónimos sin user_id y el mismo
				// nombre de pantalla se agregan como una sola persona —
				// no hay forma de distinguirlos con lo que da Zoom, y es
				// preferible a inflar el conteo.
				$key = 'anon:name:' . $name;
			}

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'email'          => $email,
					'name'           => $name,
					'raw_uid'        => $raw_uid,
					'duration_mins'  => 0,
					'join_time'      => null,
					'leave_time'     => null,
				);
			}

			$groups[ $key ]['duration_mins'] += (int) ( $participant['duration'] ?? 0 );
			$groups[ $key ]['name']           = $groups[ $key ]['name'] ?: $name;

			$join_time  = ! empty( $participant['join_time'] ) ? strtotime( (string) $participant['join_time'] ) : 0;
			$leave_time = ! empty( $participant['leave_time'] ) ? strtotime( (string) $participant['leave_time'] ) : 0;

			if ( $join_time && ( null === $groups[ $key ]['join_time'] || $join_time < $groups[ $key ]['join_time'] ) ) {
				$groups[ $key ]['join_time'] = $join_time;
			}
			if ( $leave_time && ( null === $groups[ $key ]['leave_time'] || $leave_time > $groups[ $key ]['leave_time'] ) ) {
				$groups[ $key ]['leave_time'] = $leave_time;
			}
		}

		foreach ( $groups as $group ) {
			$p_mins = $group['duration_mins'];
			$ratio  = $duration_mins > 0 ? $p_mins / $duration_mins : 0;

			$status = 'absent';
			if ( $ratio >= 0.8 ) {
				$status = 'attended';
			} elseif ( $ratio >= 0.5 ) {
				$status = 'partial';
			}

			// D1.2 (6.13.2): resolución en cascada, sin descartar a nadie
			// — antes un invitado sin email o sin cuenta WP se perdía en
			// silencio, asimetría con Meet, que sí registra anónimos con
			// user_id = 0. El usermeta legado y el hook attendance_recorded
			// siguen limitados al caso identificado: los anónimos nunca
			// tuvieron un lugar en usermeta, y C7.3 del bridge ya los
			// excluye del cálculo de racha.
			$user = $group['email'] ? get_user_by( 'email', $group['email'] ) : false;

			if ( $user ) {
				update_user_meta( $user->ID, '_atora_live_attendance_' . $lesson_id, array(
					'status'     => $status,
					'duration'   => $p_mins,
					'ratio'      => round( $ratio * 100, 1 ),
					'updated_at' => current_time( 'mysql' ),
				) );
			}

			if ( $session_id ) {
				Live_Session_Repository::record_attendance( array(
					'user_id'                 => $user ? $user->ID : 0,
					'session_id'              => $session_id,
					// E1 (6.13.3): con la agregación ya hecha, no hace
					// falta distinguir identificados por participante —
					// vuelve a ser correcto dejarlo vacío, como antes de
					// D1.1. Es seguro sin importar cómo se comporte
					// user_id en los reingresos: si es estable, el
					// resultado es idéntico; si no, evita la fila
					// duplicada. Los anónimos SÍ conservan su clave —
					// es lo que impide que colapsen entre sí (B1).
					'external_participant_id' => $user ? '' : $group['raw_uid'],
					'display_name'            => $user ? '' : $group['name'],
					'course_id'               => $course_id,
					'source'                  => 'zoom',
					'joined_at'               => $group['join_time'] ? gmdate( 'Y-m-d H:i:s', $group['join_time'] ) : null,
					'left_at'                 => $group['leave_time'] ? gmdate( 'Y-m-d H:i:s', $group['leave_time'] ) : null,
					'duration_seconds'        => $p_mins * 60,
					'status'                  => self::map_status_to_es( $status ),
				) );
			}

			if ( $user ) {
				do_action( 'atora/live/attendance_recorded', $user->ID, $lesson_id, $status );
			}
		}
	}

	/**
	 * atora_attendance.status usa el vocabulario en español del OT
	 * (presente|tarde|ausente|justificado) — Zoom internamente ya
	 * calculaba attended|partial|absent para el usermeta legado; se
	 * traduce solo para la tabla nueva, sin tocar el valor en usermeta.
	 *
	 * @param string $status attended|partial|absent
	 * @return string
	 */
	private static function map_status_to_es( string $status ): string {
		switch ( $status ) {
			case 'attended':
				return 'presente';
			case 'partial':
				return 'tarde';
			default:
				return 'ausente';
		}
	}

	// ── Auth ──────────────────────────────────────────────────────────────────

	/**
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
	 * @param string $body
	 * @param string $signature
	 * @return bool
	 */
	private static function verify_zoom_webhook_signature( string $body, string $signature ): bool {
		$opts   = get_option( 'atora_live_streaming_options', array() );
		$secret = (string) ( $opts['zoom_webhook_secret'] ?? '' );
		$secret = trim( $secret );

		if ( ! $secret ) {
			return defined( 'ATORA_DEV_MODE' ) && ATORA_DEV_MODE;
		}

		$expected = hash_hmac( 'sha256', $body, $secret );

		return hash_equals( 'v0=' . $expected, $signature );
	}
}
