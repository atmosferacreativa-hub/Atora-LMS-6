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

		$body = wp_json_encode( array_merge( self::schedule_fields( $data ), array(
			'topic'    => sanitize_text_field( $data['title'] ?? 'Clase ATORA LMS' ),
			'type'     => 2, // Scheduled.
			'settings' => array(
				'auto_recording'   => ! empty( $data['record'] ) ? 'cloud' : 'none',
				'waiting_room'     => true,
				'join_before_host' => false,
			),
		) ) );

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

	/** Hora de inicio en UTC (Zoom acepta `Z`), duración y zona para mostrar. */
	private static function schedule_fields( array $data ): array {
		$start = ! empty( $data['start_utc'] ) ? gmdate( 'Y-m-d\\TH:i:s\\Z', (int) $data['start_utc'] ) : (string) ( $data['start_datetime'] ?? '' );
		return array(
			'start_time' => $start,
			'duration'   => absint( $data['duration_minutes'] ?? 60 ),
			'timezone'   => (string) ( $data['timezone'] ?? 'UTC' ),
		);
	}

	/**
	 * 6.39.0: reprogramar la reunión existente (antes cada guardado creaba otra).
	 *
	 * @return array|\WP_Error
	 */
	public static function update_session( string $meeting_id, array $data ) {
		$token = self::get_zoom_access_token();
		if ( ! $token ) {
			return new \WP_Error( 'zoom_auth', __( 'No se pudo autenticar con Zoom.', 'atora-lms' ) );
		}
		$response = wp_remote_request( 'https://api.zoom.us/v2/meetings/' . rawurlencode( $meeting_id ), array(
			'method'  => 'PATCH',
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array_merge( self::schedule_fields( $data ), array(
				'topic'    => sanitize_text_field( $data['title'] ?? 'Clase ATORA LMS' ),
				'settings' => array( 'auto_recording' => ! empty( $data['record'] ) ? 'cloud' : 'none' ),
			) ) ),
			'timeout' => 15,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300 ? array() : new \WP_Error( 'zoom_api', __( 'Zoom no aceptó el cambio de la reunión.', 'atora-lms' ) );
	}

	/**
	 * 6.39.0: asistencia definitiva al cerrar la sesión (si el webhook no llegó).
	 *
	 * @param array $row Fila de atora_live_sessions.
	 * @return true|\WP_Error
	 */
	public static function collect_attendance( array $row ) {
		$participants = self::fetch_attendance( (string) $row['external_id'] );
		if ( ! $participants ) {
			return new \WP_Error( 'zoom_attendance_pending', __( 'Zoom aún no tiene el informe de participantes.', 'atora-lms' ) );
		}
		self::record_attendance( (int) $row['lesson_id'], $participants, array( 'object' => array( 'duration' => (int) $row['duration_minutes'] ) ) );
		return true;
	}

	/**
	 * Enlace para ver la grabación en la nube (`share_url`).
	 *
	 * @return array{url:string,note:string}|null
	 */
	public static function fetch_recording( array $row ): ?array {
		$token = self::get_zoom_access_token();
		if ( ! $token ) {
			return null;
		}
		$response = wp_remote_get( 'https://api.zoom.us/v2/meetings/' . rawurlencode( (string) $row['external_id'] ) . '/recordings', array(
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			'timeout' => 10,
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return self::recording_from_object( is_array( $data ) ? $data : array() );
	}

	/** @return array{url:string,note:string}|null */
	private static function recording_from_object( array $object ): ?array {
		$url = esc_url_raw( (string) ( $object['share_url'] ?? '' ) );
		if ( '' === $url ) {
			return null;
		}
		$pass = (string) ( $object['recording_play_passcode'] ?? $object['password'] ?? '' );
		/* translators: %s: passcode */
		return array( 'url' => $url, 'note' => '' !== $pass ? sprintf( __( 'Código de acceso: %s', 'atora-lms' ), $pass ) : '' );
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
	 * Webhook de Zoom (6.39.0).
	 *
	 * - Firma correcta: Zoom firma `v0:{x-zm-request-timestamp}:{cuerpo}` (antes se firmaba solo el cuerpo y ningún
	 *   webhook real pasaba).
	 * - Vigencia de 5 minutos y cada evento una sola vez.
	 * - Responde la validación de URL (`endpoint.url_validation`), sin la cual Zoom no activa el webhook.
	 * - Fin de reunión: la lección se busca por el ID de la reunión en la tabla de sesiones (antes solo entre 5).
	 * - Grabación: si el docente eligió el enlace automático, se guarda el enlace para verla.
	 */
	public static function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$timestamp = (string) $request->get_header( 'x-zm-request-timestamp' );
		$body      = (string) $request->get_body();
		if ( ! self::verify_zoom_webhook_signature( $body, (string) $request->get_header( 'x-zm-signature' ), $timestamp ) ) {
			return new \WP_REST_Response( array( 'status' => 'invalid_signature' ), 403 );
		}
		$payload    = json_decode( $body, true );
		$payload    = is_array( $payload ) ? $payload : array();
		$event_type = sanitize_text_field( (string) ( $payload['event'] ?? '' ) );

		if ( 'endpoint.url_validation' === $event_type ) {
			$plain = (string) ( $payload['payload']['plainToken'] ?? '' );
			return new \WP_REST_Response( array(
				'plainToken'     => $plain,
				'encryptedToken' => hash_hmac( 'sha256', $plain, self::webhook_secret() ),
			), 200 );
		}

		$key = $event_type . ':' . (string) ( $payload['payload']['object']['uuid'] ?? $payload['payload']['object']['id'] ?? '' ) . ':' . (string) ( $payload['event_ts'] ?? $timestamp );
		if ( ! self::remember_event( $key ) ) {
			return rest_ensure_response( array( 'status' => 'duplicate' ) );
		}

		switch ( $event_type ) {
			case 'meeting.ended':
				self::handle_meeting_ended( (array) ( $payload['payload'] ?? array() ) );
				break;
			case 'recording.completed':
				self::handle_recording_completed( (array) ( $payload['payload'] ?? array() ) );
				break;
		}

		do_action( 'atora/live/zoom_webhook', $event_type, $payload );
		return rest_ensure_response( array( 'status' => 'ok' ) );
	}

	/** Cada evento se procesa una vez (Zoom reintenta si no recibe 200 a tiempo). */
	private static function remember_event( string $key ): bool {
		$flag = 'atora_zoom_evt_' . md5( $key );
		if ( get_transient( $flag ) ) {
			return false;
		}
		set_transient( $flag, 1, HOUR_IN_SECONDS );
		return true;
	}

	/** @return array|null Fila de atora_live_sessions de una reunión de Zoom. */
	private static function session_for_meeting( string $meeting_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_live_sessions WHERE provider = 'zoom' AND external_id = %s LIMIT 1", $meeting_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	private static function handle_meeting_ended( array $payload ): void {
		$meeting_id = sanitize_text_field( (string) ( $payload['object']['id'] ?? '' ) );
		$row        = '' !== $meeting_id ? self::session_for_meeting( $meeting_id ) : null;
		if ( ! $row ) {
			return;
		}
		$participants = self::fetch_attendance( $meeting_id );
		self::record_attendance( (int) $row['lesson_id'], $participants, $payload );
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'atora_live_sessions', array( 'status' => 'ended' ), array( 'id' => (int) $row['id'] ) );
		do_action( 'atora/live/meeting_ended', (int) $row['lesson_id'], $meeting_id, $participants );
	}

	private static function handle_recording_completed( array $payload ): void {
		$object     = (array) ( $payload['object'] ?? array() );
		$meeting_id = sanitize_text_field( (string) ( $object['id'] ?? '' ) );
		$row        = '' !== $meeting_id ? self::session_for_meeting( $meeting_id ) : null;
		$rec        = self::recording_from_object( $object );
		if ( $row && $rec ) {
			\ATORA\LiveStreaming\Live_Streaming::store_provider_recording( (int) $row['lesson_id'], 'zoom', $rec['url'], $rec['note'] );
		}
		do_action( 'atora/live/recording_available', $meeting_id, $rec['url'] ?? '' );
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

	private static function webhook_secret(): string {
		$opts = get_option( 'atora_live_streaming_options', array() );
		return trim( (string) ( $opts['zoom_webhook_secret'] ?? '' ) );
	}

	/** Firma de Zoom: `v0=` + HMAC-SHA256(`v0:{timestamp}:{cuerpo}`), con la marca dentro de 5 minutos. */
	public static function verify_zoom_webhook_signature( string $body, string $signature, string $timestamp = '', ?int $now = null ): bool {
		$secret = self::webhook_secret();
		if ( '' === $secret || '' === $signature || ! ctype_digit( $timestamp ) ) {
			return false; // 6.39.0: sin secreto se rechaza siempre (antes el modo desarrollo lo dejaba pasar).
		}
		$now = $now ?? time();
		if ( abs( $now - (int) $timestamp ) > 300 ) {
			return false;
		}
		return hash_equals( 'v0=' . hash_hmac( 'sha256', 'v0:' . $timestamp . ':' . $body, $secret ), $signature );
	}
}
