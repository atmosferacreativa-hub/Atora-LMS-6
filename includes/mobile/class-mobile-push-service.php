<?php
/**
 * Notificaciones al teléfono (6.30.0), por el servicio de Expo.
 *
 * - Nunca dentro de la petición que crea el mensaje: el aviso nuevo se encola
 *   (Action Scheduler si está, si no WP-Cron) y se envía después.
 * - Contenido mínimo y sin datos sensibles: título genérico ("Nuevo mensaje",
 *   "Nota publicada"…) y los ids para abrir la pantalla correcta. Nunca el
 *   cuerpo del mensaje ni la nota.
 * - Preferencias por tipo (mensajes, avisos, notas, fechas límite) en el servidor.
 * - Reintento con espera creciente; un token que Expo marca como
 *   `DeviceNotRegistered` se borra.
 * - Token de acceso de Expo opcional (ajustes → canales). Si no hay
 *   configuración o el envío falla, nada se rompe: el mensaje ya está en el buzón.
 *
 * @package ATORA_LMS
 * @since 6.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Mobile_Push_Service {

	const SEND_HOOK    = 'atora_mobile_push_send';
	const EXPO_URL     = 'https://exp.host/--/api/v2/push/send';
	const PREFS_META   = 'atora_mobile_notification_prefs';
	const MAX_ATTEMPTS = 5;
	const CATEGORIES   = array( 'messages', 'notices', 'grades', 'deadlines' );

	public static function boot(): void {
		add_action( 'atora/inbox_message_created', array( __CLASS__, 'on_message_created' ), 10, 2 );
		add_action( self::SEND_HOOK, array( __CLASS__, 'send_job' ), 10, 3 );
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_mobile_push_tokens';
	}

	// ── Dispositivos ─────────────────────────────────────────────────────

	public static function valid_token( string $token ): bool {
		return (bool) preg_match( '/^(ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_\-]+\]$/', $token );
	}

	/** Registra (o reasigna al usuario actual) un token. @return int|WP_Error id */
	public static function register_device( int $user_id, string $token, string $platform ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . ' (institution_id, user_id, token, platform, academy, created_at, last_seen_at) VALUES (%d, %d, %s, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform), academy = VALUES(academy), last_seen_at = VALUES(last_seen_at)',
				absint( (int) get_option( 'atora_default_institution', 0 ) ),
				$user_id,
				$token,
				substr( sanitize_key( $platform ), 0, 20 ),
				substr( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 0, 190 ),
				$now,
				$now
			)
		);
		if ( false === $ok ) {
			return new WP_Error( 'atora_mobile_device_unavailable', __( 'No se pudo registrar el dispositivo.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE token = %s', $token ) ); // phpcs:ignore WordPress.DB
	}

	/** Borra un dispositivo propio. */
	public static function delete_device( int $user_id, int $device_id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => $device_id, 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB
	}

	/** @return array<int,array{id:int,token:string}> */
	public static function tokens_for( int $user_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, token FROM ' . self::table() . ' WHERE user_id = %d', $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	// ── Preferencias ─────────────────────────────────────────────────────

	/** @return array<string,bool> */
	public static function preferences( int $user_id ): array {
		$saved = get_user_meta( $user_id, self::PREFS_META, true );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();
		foreach ( self::CATEGORIES as $category ) {
			$out[ $category ] = ! array_key_exists( $category, $saved ) || (bool) $saved[ $category ];
		}
		return $out;
	}

	public static function save_preferences( int $user_id, array $input ): array {
		$current = self::preferences( $user_id );
		foreach ( self::CATEGORIES as $category ) {
			if ( array_key_exists( $category, $input ) ) {
				$current[ $category ] = (bool) $input[ $category ];
			}
		}
		update_user_meta( $user_id, self::PREFS_META, $current );
		return $current;
	}

	/** Tipo de preferencia de un mensaje según su hilo y su `kind`. */
	public static function category_for( string $thread_type, string $kind ): string {
		if ( 'system' !== $thread_type ) {
			return 'messages';
		}
		if ( in_array( $kind, array( 'submission_graded', 'assessment_update', 'grade' ), true ) ) {
			return 'grades';
		}
		if ( 0 === strpos( $kind, 'deadline' ) ) {
			return 'deadlines';
		}
		return 'notices';
	}

	/** Título genérico (sin el contenido). */
	public static function title_for( string $category ): string {
		switch ( $category ) {
			case 'messages':
				return __( 'Nuevo mensaje', 'atora-lms' );
			case 'grades':
				return __( 'Nota publicada', 'atora-lms' );
			case 'deadlines':
				return __( 'Fecha límite próxima', 'atora-lms' );
			default:
				return __( 'Nuevo aviso', 'atora-lms' );
		}
	}

	// ── Cola ─────────────────────────────────────────────────────────────

	/** Al crear un mensaje: encola un envío por destinatario (no envía aquí). */
	public static function on_message_created( array $row, int $thread_id ): void {
		if ( ! class_exists( 'ATORA_Inbox_Store' ) ) {
			return;
		}
		global $wpdb;
		$author = (int) $row['author_id'];
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, muted FROM {$wpdb->prefix}atora_message_participants WHERE thread_id = %d", $thread_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $participant ) {
			$user_id = (int) $participant['user_id'];
			if ( $user_id === $author || ! empty( $participant['muted'] ) ) {
				continue;
			}
			self::enqueue( $user_id, (int) $row['id'], 0 );
		}
	}

	public static function enqueue( int $user_id, int $message_id, int $attempt, int $delay = 0 ): void {
		$args = array( $user_id, $message_id, $attempt );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::SEND_HOOK, $args, 'atora-mobile-push' );
			return;
		}
		wp_schedule_single_event( time() + $delay, self::SEND_HOOK, $args );
	}

	/**
	 * Envío (lo ejecuta la cola). Devuelve qué pasó, para pruebas y registros.
	 *
	 * @return string sent|skipped|retry|failed
	 */
	public static function send_job( $user_id, $message_id, $attempt = 0 ): string {
		$user_id    = absint( $user_id );
		$message_id = absint( $message_id );
		$attempt    = absint( $attempt );
		$message    = class_exists( 'ATORA_Inbox_Store' ) ? ATORA_Inbox_Store::message( $message_id ) : null;
		if ( ! $message ) {
			return 'skipped';
		}
		$thread   = ATORA_Inbox_Store::thread( (int) $message['thread_id'] );
		$category = self::category_for( (string) ( $thread['type'] ?? 'direct' ), (string) $message['kind'] );
		if ( ! self::preferences( $user_id )[ $category ] ) {
			return 'skipped';
		}
		$tokens = self::tokens_for( $user_id );
		if ( ! $tokens ) {
			return 'skipped';
		}

		$link = class_exists( 'ATORA_Mobile_Messages_Controller' ) ? ATORA_Mobile_Messages_Controller::link_for( $message ) : null;
		$data = array(
			'type'       => 'messages' === $category ? 'message' : 'notice',
			'category'   => $category,
			'thread_id'  => (int) $message['thread_id'],
			'message_id' => $message_id,
			'kind'       => (string) $message['kind'],
			'link'       => $link,
		);
		$payload = array();
		foreach ( $tokens as $token ) {
			$payload[] = array(
				'to'        => (string) $token['token'],
				'title'     => self::title_for( $category ),
				'sound'     => 'default',
				'priority'  => 'high',
				'channelId' => 'default',
				'data'      => $data,
			);
		}

		$headers = array( 'Accept' => 'application/json', 'Content-Type' => 'application/json' );
		$access  = (string) get_option( 'atora_expo_access_token', '' );
		if ( '' !== $access ) {
			$headers['Authorization'] = 'Bearer ' . $access;
		}
		$response = wp_remote_post( self::EXPO_URL, array( 'timeout' => 15, 'headers' => $headers, 'body' => wp_json_encode( $payload ) ) );
		$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( 0 === $status || 429 === $status || $status >= 500 ) {
			if ( $attempt + 1 < self::MAX_ATTEMPTS ) {
				self::enqueue( $user_id, $message_id, $attempt + 1, (int) min( HOUR_IN_SECONDS, 60 * ( 2 ** $attempt ) ) );
				return 'retry';
			}
			return 'failed';
		}
		if ( $status >= 400 ) {
			return 'failed';
		}

		// Tickets en el mismo orden que los mensajes: borrar los tokens que Expo ya no reconoce.
		$body    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$tickets = is_array( $body['data'] ?? null ) ? $body['data'] : array();
		global $wpdb;
		foreach ( $tickets as $index => $ticket ) {
			if ( 'error' === ( $ticket['status'] ?? '' ) && 'DeviceNotRegistered' === ( $ticket['details']['error'] ?? '' ) && isset( $tokens[ $index ] ) ) {
				$wpdb->delete( self::table(), array( 'id' => (int) $tokens[ $index ]['id'] ) ); // phpcs:ignore WordPress.DB
			}
		}
		return 'sent';
	}
}
