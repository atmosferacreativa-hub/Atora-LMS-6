<?php
/**
 * Provider_Teams — Microsoft Teams por Microsoft Graph (6.39.0).
 *
 * Credenciales de aplicación de la academia (inquilino, aplicación, secreto) y un organizador (usuario de Microsoft
 * 365 en cuyo nombre se crean las reuniones). Se guardan en `atora_live_streaming_options`, nunca en el código.
 *
 * Permisos de aplicación necesarios en Entra ID: OnlineMeetings.ReadWrite.All,
 * OnlineMeetingArtifact.Read.All (asistencia), OnlineMeetingRecording.Read.All (grabación), y una
 * «application access policy» de Teams que autorice a la aplicación sobre el organizador.
 *
 * Probado con respuestas de Graph simuladas; sin cuenta real.
 *
 * @package ATORA_LMS\LiveStreaming\Providers
 * @since   6.39.0
 */

namespace ATORA\LiveStreaming\Providers;

use ATORA\LiveStreaming\Live_Session_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Provider_Teams implements Provider_Interface {

	const GRAPH = 'https://graph.microsoft.com/v1.0';

	/** @return array{tenant:string,client:string,secret:string,organizer:string} */
	public static function settings(): array {
		$opts = (array) get_option( 'atora_live_streaming_options', array() );
		return array(
			'tenant'    => trim( (string) ( $opts['teams_tenant_id'] ?? '' ) ),
			'client'    => trim( (string) ( $opts['teams_client_id'] ?? '' ) ),
			'secret'    => trim( (string) ( $opts['teams_client_secret'] ?? '' ) ),
			'organizer' => trim( (string) ( $opts['teams_organizer'] ?? '' ) ),
		);
	}

	public static function configured(): bool {
		$s = self::settings();
		return '' !== $s['tenant'] && '' !== $s['client'] && '' !== $s['secret'] && '' !== $s['organizer'];
	}

	/** Token de aplicación (client credentials), en caché hasta un minuto antes de vencer. */
	public static function access_token(): ?string {
		$s = self::settings();
		if ( ! self::configured() ) {
			return null;
		}
		$cache_key = 'atora_teams_token_' . md5( $s['tenant'] . '|' . $s['client'] );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$response = wp_remote_post( 'https://login.microsoftonline.com/' . rawurlencode( $s['tenant'] ) . '/oauth2/v2.0/token', array(
			'body'    => array(
				'client_id'     => $s['client'],
				'client_secret' => $s['secret'],
				'scope'         => 'https://graph.microsoft.com/.default',
				'grant_type'    => 'client_credentials',
			),
			'timeout' => 10,
		) );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			return null;
		}
		set_transient( $cache_key, (string) $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 60 ) );
		return (string) $data['access_token'];
	}

	/** @return array|\WP_Error cuerpo decodificado */
	private static function graph( string $method, string $path, ?array $body = null ) {
		$token = self::access_token();
		if ( ! $token ) {
			return new \WP_Error( 'teams_auth', __( 'Teams no está configurado o las credenciales no son válidas.', 'atora-lms' ) );
		}
		$args = array(
			'method'  => $method,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'timeout' => 15,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( self::GRAPH . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
			/* translators: 1: status 2: message */
			return new \WP_Error( 'teams_api', sprintf( __( 'Microsoft Graph respondió %1$d. %2$s', 'atora-lms' ), $code, $message ), array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	private static function meetings_path( string $meeting_id = '' ): string {
		$base = '/users/' . rawurlencode( self::settings()['organizer'] ) . '/onlineMeetings';
		return '' === $meeting_id ? $base : $base . '/' . rawurlencode( $meeting_id );
	}

	private static function times( array $data ): array {
		$start = (int) ( $data['start_utc'] ?? 0 );
		$end   = $start + absint( $data['duration_minutes'] ?? 60 ) * MINUTE_IN_SECONDS;
		return array(
			'startDateTime' => gmdate( 'Y-m-d\TH:i:s\Z', $start ),
			'endDateTime'   => gmdate( 'Y-m-d\TH:i:s\Z', $end ),
		);
	}

	/**
	 * @param array $data title, start_utc, duration_minutes, record.
	 * @return array|\WP_Error
	 */
	public static function create_session( array $data ) {
		if ( empty( $data['start_utc'] ) ) {
			return new \WP_Error( 'teams_missing_start', __( 'Falta la fecha/hora de inicio.', 'atora-lms' ) );
		}
		$result = self::graph( 'POST', self::meetings_path(), array_merge( self::times( $data ), array(
			'subject'              => sanitize_text_field( (string) ( $data['title'] ?? 'Clase ATORA LMS' ) ),
			'recordAutomatically'  => ! empty( $data['record'] ),
			'allowedPresenters'    => 'organizer',
			'lobbyBypassSettings'  => array( 'scope' => 'organization', 'isDialInBypassEnabled' => false ),
		) ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['id'] ) || empty( $result['joinWebUrl'] ) ) {
			return new \WP_Error( 'teams_api', __( 'Teams no devolvió la reunión creada.', 'atora-lms' ) );
		}
		return array(
			'meeting_id'  => (string) $result['id'],
			'external_id' => (string) $result['id'],
			'join_url'    => esc_url_raw( (string) $result['joinWebUrl'] ),
		);
	}

	/** @return array|\WP_Error */
	public static function update_session( string $meeting_id, array $data ) {
		$result = self::graph( 'PATCH', self::meetings_path( $meeting_id ), array_merge( self::times( $data ), array(
			'subject'             => sanitize_text_field( (string) ( $data['title'] ?? 'Clase ATORA LMS' ) ),
			'recordAutomatically' => ! empty( $data['record'] ),
		) ) );
		return is_wp_error( $result ) ? $result : array();
	}

	/** Teams, igual que Zoom y Meet, no diferencia el enlace por persona. */
	public static function get_join_url( int $session_id, int $user_id ) {
		unset( $user_id );
		global $wpdb;
		$url = (string) $wpdb->get_var( $wpdb->prepare( "SELECT join_url FROM {$wpdb->prefix}atora_live_sessions WHERE id = %d", $session_id ) ); // phpcs:ignore WordPress.DB
		return '' === $url ? new \WP_Error( 'teams_no_session', __( 'Sesión no encontrada.', 'atora-lms' ) ) : $url;
	}

	/**
	 * Asistencia desde el último informe de asistencia de la reunión.
	 *
	 * @return array|\WP_Error [{email, display_name, external_participant_id, duration_seconds, joined_at, left_at}]
	 */
	public static function fetch_attendance( string $external_id ) {
		$reports = self::graph( 'GET', self::meetings_path( $external_id ) . '/attendanceReports' );
		if ( is_wp_error( $reports ) ) {
			return $reports;
		}
		$list = (array) ( $reports['value'] ?? array() );
		if ( ! $list ) {
			return array();
		}
		usort( $list, static fn( $a, $b ) => strcmp( (string) ( $b['meetingEndDateTime'] ?? '' ), (string) ( $a['meetingEndDateTime'] ?? '' ) ) );
		$records = self::graph( 'GET', self::meetings_path( $external_id ) . '/attendanceReports/' . rawurlencode( (string) $list[0]['id'] ) . '/attendanceRecords' );
		if ( is_wp_error( $records ) ) {
			return $records;
		}
		$out = array();
		foreach ( (array) ( $records['value'] ?? array() ) as $r ) {
			$joined = null;
			$left   = null;
			foreach ( (array) ( $r['attendanceIntervals'] ?? array() ) as $interval ) {
				$j      = strtotime( (string) ( $interval['joinDateTime'] ?? '' ) );
				$l      = strtotime( (string) ( $interval['leaveDateTime'] ?? '' ) );
				$joined = $j && ( null === $joined || $j < $joined ) ? $j : $joined;
				$left   = $l && ( null === $left || $l > $left ) ? $l : $left;
			}
			$out[] = array(
				'email'                   => sanitize_email( (string) ( $r['emailAddress'] ?? '' ) ),
				'display_name'            => sanitize_text_field( (string) ( $r['identity']['displayName'] ?? '' ) ),
				'external_participant_id' => sanitize_text_field( (string) ( $r['identity']['id'] ?? $r['id'] ?? '' ) ),
				'duration_seconds'        => absint( $r['totalAttendanceInSeconds'] ?? 0 ),
				'joined_at'               => $joined ? gmdate( 'Y-m-d H:i:s', $joined ) : null,
				'left_at'                 => $left ? gmdate( 'Y-m-d H:i:s', $left ) : null,
			);
		}
		return $out;
	}

	/** @return true|\WP_Error */
	public static function collect_attendance( array $row ) {
		$participants = self::fetch_attendance( (string) $row['external_id'] );
		if ( is_wp_error( $participants ) ) {
			return $participants;
		}
		if ( ! $participants ) {
			return new \WP_Error( 'teams_attendance_pending', __( 'Teams aún no generó el informe de asistencia.', 'atora-lms' ) );
		}
		$class_seconds = max( 60, absint( $row['duration_minutes'] ?? 60 ) * 60 );
		foreach ( $participants as $p ) {
			$user  = '' !== $p['email'] ? get_user_by( 'email', $p['email'] ) : false;
			$ratio = $p['duration_seconds'] / $class_seconds;
			Live_Session_Repository::record_attendance( array(
				'user_id'                 => $user ? $user->ID : 0,
				'session_id'              => (int) $row['id'],
				'external_participant_id' => $user ? '' : $p['external_participant_id'],
				'display_name'            => $user ? '' : $p['display_name'],
				'course_id'               => (int) $row['course_id'],
				'source'                  => 'teams',
				'joined_at'               => $p['joined_at'],
				'left_at'                 => $p['left_at'],
				'duration_seconds'        => $p['duration_seconds'],
				'status'                  => $ratio >= 0.8 ? 'presente' : ( $ratio >= 0.5 ? 'tarde' : 'ausente' ),
			) );
			if ( $user ) {
				do_action( 'atora/live/attendance_recorded', $user->ID, (int) $row['lesson_id'], $ratio >= 0.8 ? 'attended' : ( $ratio >= 0.5 ? 'partial' : 'absent' ) );
			}
		}
		return true;
	}

	/**
	 * Si la reunión tiene grabación, el enlace para verla es el de la reunión (Teams publica la grabación en el
	 * chat de la reunión; el archivo vive en el OneDrive del organizador).
	 *
	 * @return array{url:string,note:string}|null
	 */
	public static function fetch_recording( array $row ): ?array {
		$list = self::graph( 'GET', self::meetings_path( (string) $row['external_id'] ) . '/recordings' );
		if ( is_wp_error( $list ) || empty( $list['value'] ) ) {
			return null;
		}
		$url = esc_url_raw( (string) ( $row['join_url'] ?? '' ) );
		return '' === $url ? null : array( 'url' => $url, 'note' => __( 'La grabación está en el chat de la reunión de Teams; la ven quienes tienen acceso a esa reunión.', 'atora-lms' ) );
	}

	/** Teams no usa webhook aquí: la asistencia y la grabación se consultan al cerrar la sesión. */
	public static function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		return new \WP_REST_Response( array( 'status' => 'not_supported' ), 404 );
	}

	public static function supports(): array {
		return array( 'create', 'attendance', 'recording' );
	}
}
