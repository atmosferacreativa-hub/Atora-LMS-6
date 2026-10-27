<?php
/**
 * Provider_Meet — P8 (sprint 6.13.0)
 *
 * No hace falta la Meet API para crear la reunión: se crea con
 * `conferenceData.createRequest` en el evento de Google Calendar
 * (conferenceDataVersion=1), reusando el OAuth de Calendar que ya
 * funciona (Calendar_Sync::get_valid_access_token()).
 *
 * Restricción que define el flujo de UI (documentada en el metabox de la
 * lección, ver views/metabox.php): el listado de conferencias de la Meet
 * REST API v2 solo devuelve aquellas donde el usuario autenticado es el
 * organizador, y con el scope `meetings.space.created` solo se ven los
 * espacios que creó la propia app. Por eso la sesión la crea SIEMPRE
 * ATORA — si el docente pega un link de Meet creado a mano, no hay
 * asistencia, y la UI debe decirlo explícitamente en vez de fallar en
 * silencio (ver Live_Streaming::render_metabox()).
 *
 * @package ATORA_LMS\LiveStreaming\Providers
 * @since   6.13.0
 */

namespace ATORA\LiveStreaming\Providers;

use ATORA\Calendar\Calendar_Sync;
use ATORA\LiveStreaming\Live_Session_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Provider_Meet implements Provider_Interface {

	/**
	 * Crea el evento de Calendar con conferenceData.createRequest —
	 * Google resuelve el espacio de Meet al procesar el insert.
	 *
	 * @param array $data {
	 *     @type int    lesson_id
	 *     @type int    actor_user_id  Docente que crea la sesión — dueño del token OAuth usado.
	 *     @type string title
	 *     @type string start_datetime MySQL datetime.
	 *     @type int    duration_minutes
	 *     @type string timezone
	 * }
	 * @return array|\WP_Error
	 */
	public static function create_session( array $data ) {
		$actor_user_id = absint( $data['actor_user_id'] ?? get_current_user_id() );
		$token         = Calendar_Sync::get_valid_access_token( $actor_user_id, 'google' );

		if ( ! $token ) {
			return new \WP_Error(
				'meet_no_calendar_token',
				__( 'Conecta Google Calendar (ATORA → Calendario) antes de crear una clase con Meet.', 'atora-lms' )
			);
		}

		$tz    = (string) ( $data['timezone'] ?? 'UTC' );
		$start = (string) ( $data['start_datetime'] ?? '' );
		if ( ! $start ) {
			return new \WP_Error( 'meet_missing_start', __( 'Falta la fecha/hora de inicio.', 'atora-lms' ) );
		}
		$end = gmdate( 'Y-m-d\TH:i:s', strtotime( $start ) + absint( $data['duration_minutes'] ?? 60 ) * 60 );

		$body = wp_json_encode( array(
			'summary'        => sanitize_text_field( $data['title'] ?? 'Clase ATORA LMS' ),
			'start'          => array( 'dateTime' => gmdate( 'Y-m-d\TH:i:s', strtotime( $start ) ), 'timeZone' => $tz ),
			'end'            => array( 'dateTime' => $end, 'timeZone' => $tz ),
			'conferenceData' => array(
				'createRequest' => array(
					'requestId'             => wp_generate_uuid4(),
					'conferenceSolutionKey' => array( 'type' => 'hangoutsMeet' ),
				),
			),
		) );

		$response = wp_remote_post(
			'https://www.googleapis.com/calendar/v3/calendars/primary/events?conferenceDataVersion=1',
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

		$join_url = $result['hangoutLink'] ?? ( $result['conferenceData']['entryPoints'][0]['uri'] ?? '' );
		if ( empty( $result['id'] ) || ! $join_url ) {
			return new \WP_Error( 'meet_api', __( 'Error al crear la reunión de Meet en Google Calendar.', 'atora-lms' ) );
		}

		// El código de espacio (meetingCode / conferenceId) es lo que
		// permite luego ubicar el conferenceRecord en la Meet API v2.
		// C0.3/C1.5 (6.13.1): quién es el organizador (dueño del token que
		// puede ver este conferenceRecord — restricción de
		// meetings.space.created) se guarda en la columna
		// created_by_user_id de atora_live_sessions, no en wp_options —
		// upsert_session() la escribe a partir de 'created_by_user_id' abajo.
		$meeting_code = $result['conferenceData']['conferenceId'] ?? '';

		return array(
			'meeting_id'         => $meeting_code ?: $result['id'],
			'external_id'        => $meeting_code ?: $result['id'],
			'join_url'           => $join_url,
			'calendar_event_id'  => $result['id'],
			'created_by_user_id' => $actor_user_id,
		);
	}

	/**
	 * @param int $session_id
	 * @param int $user_id Sin uso: Meet, igual que Zoom, no diferencia join_url por usuario.
	 * @return string|\WP_Error
	 */
	public static function get_join_url( int $session_id, int $user_id ) {
		unset( $user_id );

		global $wpdb;
		$table = $wpdb->prefix . 'atora_live_sessions';
		$url   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT join_url FROM {$table} WHERE id = %d", $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( '' === $url ) {
			return new \WP_Error( 'meet_no_session', __( 'Sesión no encontrada.', 'atora-lms' ) );
		}

		return $url;
	}

	/**
	 * Meet REST API v2: conferenceRecords filtrado por meeting code,
	 * luego participants y participantSessions. Requiere que el
	 * conferenceRecord pertenezca a un espacio creado por esta app — de
	 * lo contrario la API simplemente no lo devuelve (restricción de
	 * `meetings.space.created`, no un error).
	 *
	 * @param string $external_id meeting code (conferenceId) devuelto por create_session().
	 * @return array|\WP_Error Lista de participantes normalizada: [{ email, display_name, user_id, joined_at, left_at, duration_seconds }].
	 */
	public static function fetch_attendance( string $external_id ) {
		global $wpdb;

		// C0.3/C1.5 (6.13.1): el organizador vive en la columna
		// created_by_user_id de atora_live_sessions, ya no en wp_options.
		$actor_user_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT created_by_user_id FROM {$wpdb->prefix}atora_live_sessions WHERE provider = 'meet' AND external_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$external_id
		) );
		$token = $actor_user_id ? Calendar_Sync::get_valid_access_token( $actor_user_id, 'google' ) : null;

		if ( ! $token ) {
			return new \WP_Error( 'meet_no_token', __( 'No hay token de Google válido para consultar asistencia de Meet.', 'atora-lms' ) );
		}

		$records = wp_remote_get(
			'https://meet.googleapis.com/v2/conferenceRecords?' . http_build_query( array(
				'filter' => 'space.meeting_code="' . $external_id . '"',
			) ),
			array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ), 'timeout' => 10 )
		);

		if ( is_wp_error( $records ) ) {
			return $records;
		}

		$records_data = json_decode( wp_remote_retrieve_body( $records ), true );
		$record_name  = $records_data['conferenceRecords'][0]['name'] ?? '';

		if ( ! $record_name ) {
			// Sin registro: la clase no ocurrió, o el espacio no fue
			// creado por esta app (docente pegó un link manual) — no es
			// un error de red, es "no hay nada que reportar".
			return array();
		}

		$participants_resp = wp_remote_get(
			"https://meet.googleapis.com/v2/{$record_name}/participants",
			array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ), 'timeout' => 10 )
		);

		if ( is_wp_error( $participants_resp ) ) {
			return $participants_resp;
		}

		$participants_data = json_decode( wp_remote_retrieve_body( $participants_resp ), true );
		$normalized        = array();

		foreach ( (array) ( $participants_data['participants'] ?? array() ) as $participant ) {
			$signed_in_user = $participant['signedinUser'] ?? null;

			$sessions_resp = wp_remote_get(
				"https://meet.googleapis.com/v2/{$participant['name']}/participantSessions",
				array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ), 'timeout' => 10 )
			);
			$sessions_data = is_wp_error( $sessions_resp ) ? array() : json_decode( wp_remote_retrieve_body( $sessions_resp ), true );

			$duration_seconds = 0;
			$joined_at        = null;
			$left_at          = null;
			foreach ( (array) ( $sessions_data['participantSessions'] ?? array() ) as $session ) {
				$start = isset( $session['startTime'] ) ? strtotime( $session['startTime'] ) : 0;
				$end   = isset( $session['endTime'] ) ? strtotime( $session['endTime'] ) : time();
				if ( $start ) {
					$duration_seconds += max( 0, $end - $start );
					$joined_at         = $joined_at ?? gmdate( 'Y-m-d H:i:s', $start );
					$left_at           = gmdate( 'Y-m-d H:i:s', $end );
				}
			}

			$normalized[] = array(
				// P8.3 (6.13.0): invitados no autenticados llegan sin
				// signedinUser — email/user_id null, se registran como
				// "no identificado" (user_id = 0) en Live_Session_Repository.
				'email'                   => $signed_in_user['email'] ?? null,
				'display_name'            => $participant['anonymousUser']['displayName'] ?? ( $participant['phoneUser']['displayName'] ?? null ),
				// C1.2 (6.13.1): `name` (participants/{id}) para TODOS los
				// participantes, no solo anónimos — es la clave estable
				// de deduplicación si alguien se reconecta a mitad de
				// clase, evita que un reprocesamiento del mismo
				// conferenceRecord duplique filas de asistencia.
				'external_participant_id' => (string) ( $participant['name'] ?? '' ),
				'duration_seconds'        => $duration_seconds,
				'joined_at'               => $joined_at,
				'left_at'                 => $left_at,
			);
		}

		return $normalized;
	}

	/**
	 * Meet no tiene un webhook REST tradicional — usa Workspace Events
	 * API (suscripción + Pub/Sub) o, como fallback, el polling del cron
	 * de 5 minutos ya existente (ver poll_active_sessions()). No se
	 * declara 'webhook' en supports() por esto mismo.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		return new \WP_REST_Response( array( 'status' => 'not_supported' ), 404 );
	}

	/**
	 * @return string[]
	 */
	public static function supports(): array {
		return array( 'create', 'attendance', 'recording' );
	}

	/**
	 * 6.39.0: reprogramar el evento de Calendar (y con él la reunión de Meet).
	 *
	 * @return array|\WP_Error
	 */
	public static function update_session( string $meeting_id, array $data ) {
		global $wpdb;
		$event_id = (string) ( $data['calendar_event_id'] ?? '' );
		$actor    = absint( $data['created_by_user_id'] ?? $data['actor_user_id'] ?? 0 );
		$token    = $actor ? Calendar_Sync::get_valid_access_token( $actor, 'google' ) : null;
		if ( '' === $event_id || ! $token ) {
			return new \WP_Error( 'meet_no_calendar_token', __( 'No se pudo reprogramar en Google Calendar (falta la conexión de quien creó la clase).', 'atora-lms' ) );
		}
		$start    = (int) ( $data['start_utc'] ?? 0 );
		$end      = $start + absint( $data['duration_minutes'] ?? 60 ) * MINUTE_IN_SECONDS;
		$response = wp_remote_request( 'https://www.googleapis.com/calendar/v3/calendars/primary/events/' . rawurlencode( $event_id ), array(
			'method'  => 'PATCH',
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'summary' => sanitize_text_field( $data['title'] ?? 'Clase ATORA LMS' ),
				'start'   => array( 'dateTime' => gmdate( 'Y-m-d\\TH:i:s\\Z', $start ) ),
				'end'     => array( 'dateTime' => gmdate( 'Y-m-d\\TH:i:s\\Z', $end ) ),
			) ),
			'timeout' => 15,
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300 ? array() : new \WP_Error( 'meet_api', __( 'Google Calendar no aceptó el cambio.', 'atora-lms' ) );
	}

	/**
	 * 6.39.0: asistencia definitiva al terminar la clase, con estado por tiempo conectado (antes todos «presente»
	 * y la sesión se cerraba en la primera consulta, apenas empezaba).
	 *
	 * @param array $row Fila de atora_live_sessions.
	 * @return true|\WP_Error
	 */
	public static function collect_attendance( array $row ) {
		$participants = self::fetch_attendance( (string) $row['external_id'] );
		if ( is_wp_error( $participants ) ) {
			return $participants;
		}
		if ( ! $participants ) {
			return new \WP_Error( 'meet_attendance_pending', __( 'Meet aún no tiene el registro de la reunión.', 'atora-lms' ) );
		}
		$class_seconds = max( 60, absint( $row['duration_minutes'] ?? 60 ) * 60 );
		foreach ( $participants as $p ) {
			$user  = ! empty( $p['email'] ) ? get_user_by( 'email', (string) $p['email'] ) : false;
			$ratio = (int) ( $p['duration_seconds'] ?? 0 ) / $class_seconds;
			Live_Session_Repository::record_attendance( array(
				'user_id'                 => $user ? $user->ID : 0,
				'session_id'              => (int) $row['id'],
				'external_participant_id' => $user ? '' : (string) ( $p['external_participant_id'] ?? '' ),
				'display_name'            => $user ? '' : (string) ( $p['display_name'] ?? '' ),
				'course_id'               => (int) $row['course_id'],
				'source'                  => 'meet',
				'joined_at'               => $p['joined_at'] ?? null,
				'left_at'                 => $p['left_at'] ?? null,
				'duration_seconds'        => (int) ( $p['duration_seconds'] ?? 0 ),
				'status'                  => $ratio >= 0.8 ? 'presente' : ( $ratio >= 0.5 ? 'tarde' : 'ausente' ),
			) );
		}
		return true;
	}

	/**
	 * Grabación de Meet: la inicia quien organiza (la API de Calendar no puede activarla). Si existe, se toma el
	 * enlace del archivo en Drive.
	 *
	 * @return array{url:string,note:string}|null
	 */
	public static function fetch_recording( array $row ): ?array {
		$actor = absint( $row['created_by_user_id'] ?? 0 );
		$token = $actor ? Calendar_Sync::get_valid_access_token( $actor, 'google' ) : null;
		if ( ! $token ) {
			return null;
		}
		$records = wp_remote_get( 'https://meet.googleapis.com/v2/conferenceRecords?' . http_build_query( array( 'filter' => 'space.meeting_code="' . (string) $row['external_id'] . '"' ) ), array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ), 'timeout' => 10 ) );
		$name    = is_wp_error( $records ) ? '' : (string) ( json_decode( (string) wp_remote_retrieve_body( $records ), true )['conferenceRecords'][0]['name'] ?? '' );
		if ( '' === $name ) {
			return null;
		}
		$list = wp_remote_get( "https://meet.googleapis.com/v2/{$name}/recordings", array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ), 'timeout' => 10 ) );
		$url  = is_wp_error( $list ) ? '' : (string) ( json_decode( (string) wp_remote_retrieve_body( $list ), true )['recordings'][0]['driveDestination']['exportUri'] ?? '' );
		return '' === $url ? null : array( 'url' => esc_url_raw( $url ), 'note' => __( 'Está en Google Drive de quien organizó la clase; se ve si está compartida con el curso.', 'atora-lms' ) );
	}
}
