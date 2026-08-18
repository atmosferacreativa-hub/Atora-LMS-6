<?php
/**
 * ATORA LMS v5 — Sincronización de calendarios externos
 *
 * Gestiona la integración OAuth con Google Calendar y Microsoft Outlook,
 * y la exportación ICS para Apple Calendar y otros clientes.
 *
 * @package ATORA_LMS\Calendar
 * @since   5.0.0
 */

namespace ATORA\Calendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Calendar_Sync
 *
 * @since 5.0.0
 */
class Calendar_Sync {

	/** Providers soportados. */
	const PROVIDERS = array( 'google', 'outlook' );

	/**
	 * Registra hooks de sincronización.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Registrar intervalos antes de cualquier programación de eventos.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_intervals' ) );

		// Cron de sincronización cada 15 minutos.
		add_action( 'atora_calendar_sync_cron', array( __CLASS__, 'run_sync_batch' ) );

		if ( ! wp_next_scheduled( 'atora_calendar_sync_cron' ) ) {
			wp_schedule_event( time(), 'every_15_minutes', 'atora_calendar_sync_cron' );
		}

		// OAuth callbacks.
		add_action( 'init', array( __CLASS__, 'handle_oauth_callback' ) );

		// Disparar sincronización cuando se crea un evento.
		add_action( 'atora/calendar/event_created', array( __CLASS__, 'push_event_to_external', ), 20, 2 );
	}

	/**
	 * Añade intervalos de cron personalizados.
	 *
	 * @param array $schedules Schedules existentes.
	 * @return array
	 */
	public static function add_cron_intervals( array $schedules ): array {
		$schedules['every_15_minutes'] = array(
			'interval' => 900,
			'display'  => __( 'Cada 15 minutos', 'atora-lms' ),
		);
		return $schedules;
	}

	/**
	 * Procesa el callback OAuth de Google o Outlook.
	 *
	 * @return void
	 */
	public static function handle_oauth_callback(): void {
		if ( ! isset( $_GET['atora_oauth_provider'] ) ) {
			return;
		}

		$provider = sanitize_key( wp_unslash( $_GET['atora_oauth_provider'] ) );
		$code     = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
		$state    = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );

		if ( ! in_array( $provider, self::PROVIDERS, true ) || ! $code ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		// Verificar state (CSRF).
		if ( ! wp_verify_nonce( $state, 'atora_oauth_' . $provider . '_' . $user_id ) ) {
			wp_die( esc_html__( 'Estado OAuth inválido.', 'atora-lms' ) );
		}

		$tokens = self::exchange_code_for_tokens( $provider, $code );

		if ( $tokens ) {
			self::save_tokens( $user_id, $provider, $tokens );
			do_action( 'atora/calendar/oauth_connected', $user_id, $provider );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=atora-calendar&connected=' . $provider ) );
		exit;
	}

	/**
	 * Ejecuta un batch de sincronización para todos los usuarios con sync activo.
	 *
	 * @return void
	 */
	public static function run_sync_batch(): void {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			"SELECT * FROM {$wpdb->prefix}atora_calendar_sync
			 WHERE sync_enabled = 1
			   AND (last_sync_at IS NULL OR last_sync_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE))
			 LIMIT 20"
		);

		foreach ( $rows as $row ) {
			self::sync_user_calendar( (int) $row->user_id, $row->provider );
		}
	}

	/**
	 * Sincroniza el calendario de un usuario con su provider externo.
	 *
	 * @param int    $user_id  ID del usuario.
	 * @param string $provider Provider (google|outlook).
	 * @return void
	 */
	public static function sync_user_calendar( int $user_id, string $provider ): void {
		global $wpdb;

		$tokens = self::get_tokens( $user_id, $provider );
		if ( ! $tokens ) {
			return;
		}

		// Refrescar token si ha expirado.
		if ( time() > (int) $tokens['expires_at'] ) {
			$tokens = self::refresh_tokens( $provider, $tokens['refresh_token'] );
			if ( ! $tokens ) {
				return;
			}
			self::save_tokens( $user_id, $provider, $tokens );
		}

		// Obtener eventos ATORA del usuario para el próximo mes.
		$events = Calendar::get_events( array(
			'start'   => current_time( 'Y-m-d H:i:s' ),
			'end'     => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) ),
			'user_id' => $user_id,
		) );

		foreach ( $events as $event ) {
			self::push_event( $provider, $tokens['access_token'], $event, $user_id );
		}

		$wpdb->update(
			"{$wpdb->prefix}atora_calendar_sync",
			array( 'last_sync_at' => current_time( 'mysql', true ) ),
			array( 'user_id' => $user_id, 'provider' => $provider ),
			array( '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Envía un evento al calendario externo del usuario.
	 *
	 * @param int   $event_id ID del evento.
	 * @param array $data     Datos del evento.
	 * @return void
	 */
	public static function push_event_to_external( int $event_id, array $data ): void {
		global $wpdb;

		$user_id = absint( $data['user_id'] ?? get_current_user_id() );

		$syncs = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_calendar_sync
			 WHERE user_id = %d AND sync_enabled = 1",
			$user_id
		) );

		foreach ( $syncs as $sync ) {
			$tokens = self::get_tokens( $user_id, $sync->provider );
			if ( ! $tokens ) {
				continue;
			}

			$event = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}atora_calendar_events WHERE id = %d",
				$event_id
			) );

			if ( $event ) {
				self::push_event( $sync->provider, $tokens['access_token'], $event, $user_id );
			}
		}
	}

	// ── API helpers ───────────────────────────────────────────────────────────

	/**
	 * Intercambia un código OAuth por tokens de acceso.
	 *
	 * @param string $provider Provider.
	 * @param string $code     Código OAuth.
	 * @return array|null
	 */
	private static function exchange_code_for_tokens( string $provider, string $code ): ?array {
		$opts = get_option( 'atora_calendar_oauth_' . $provider, array() );

		if ( empty( $opts['client_id'] ) || empty( $opts['client_secret'] ) ) {
			return null;
		}

		$endpoints = array(
			'google'  => 'https://oauth2.googleapis.com/token',
			'outlook' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
		);

		$body = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'client_id'     => $opts['client_id'],
			'client_secret' => $opts['client_secret'],
			'redirect_uri'  => add_query_arg( 'atora_oauth_provider', $provider, home_url() ),
		);

		$response = wp_remote_post( $endpoints[ $provider ], array(
			'body'    => $body,
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['access_token'] ) ) {
			return null;
		}

		return array(
			'access_token'  => $data['access_token'],
			'refresh_token' => $data['refresh_token'] ?? '',
			'expires_at'    => time() + (int) ( $data['expires_in'] ?? 3600 ),
		);
	}

	/**
	 * Refresca tokens expirados.
	 *
	 * @param string $provider      Provider.
	 * @param string $refresh_token Refresh token.
	 * @return array|null
	 */
	private static function refresh_tokens( string $provider, string $refresh_token ): ?array {
		$opts = get_option( 'atora_calendar_oauth_' . $provider, array() );

		$endpoints = array(
			'google'  => 'https://oauth2.googleapis.com/token',
			'outlook' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
		);

		$response = wp_remote_post( $endpoints[ $provider ], array(
			'body' => array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => $opts['client_id'] ?? '',
				'client_secret' => $opts['client_secret'] ?? '',
			),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['access_token'] ) ) {
			return null;
		}

		return array(
			'access_token'  => $data['access_token'],
			'refresh_token' => $data['refresh_token'] ?? $refresh_token,
			'expires_at'    => time() + (int) ( $data['expires_in'] ?? 3600 ),
		);
	}

	/**
	 * Envía un evento a Google Calendar o Microsoft Graph.
	 *
	 * @param string $provider     Provider.
	 * @param string $access_token Access token.
	 * @param object $event        Evento.
	 * @param int    $user_id      ID del usuario (para recuperar timezone).
	 * @return void
	 */
	private static function push_event( string $provider, string $access_token, object $event, int $user_id ): void {
		$tz    = get_user_meta( $user_id, 'atora_timezone', true ) ?: 'UTC';
		$title = '[ATORA] ' . $event->title;

		if ( 'google' === $provider ) {
			$body = wp_json_encode( array(
				'summary'     => $title,
				'description' => $event->description,
				'location'    => $event->location,
				'start'       => array( 'dateTime' => $event->start_datetime, 'timeZone' => $tz ),
				'end'         => array( 'dateTime' => $event->end_datetime ?: $event->start_datetime, 'timeZone' => $tz ),
			) );

			wp_remote_post(
				'https://www.googleapis.com/calendar/v3/calendars/primary/events',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $access_token,
						'Content-Type'  => 'application/json',
					),
					'body'    => $body,
					'timeout' => 10,
				)
			);
		} elseif ( 'outlook' === $provider ) {
			$body = wp_json_encode( array(
				'subject' => $title,
				'body'    => array( 'contentType' => 'Text', 'content' => $event->description ),
				'start'   => array( 'dateTime' => $event->start_datetime, 'timeZone' => $tz ),
				'end'     => array( 'dateTime' => $event->end_datetime ?: $event->start_datetime, 'timeZone' => $tz ),
				'location'=> array( 'displayName' => $event->location ),
			) );

			wp_remote_post(
				'https://graph.microsoft.com/v1.0/me/events',
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $access_token,
						'Content-Type'  => 'application/json',
					),
					'body'    => $body,
					'timeout' => 10,
				)
			);
		}
	}

	/**
	 * Guarda tokens OAuth en la BD.
	 *
	 * @param int    $user_id  ID del usuario.
	 * @param string $provider Provider.
	 * @param array  $tokens   Tokens.
	 * @return void
	 */
	private static function save_tokens( int $user_id, string $provider, array $tokens ): void {
		global $wpdb;

		$wpdb->replace(
			"{$wpdb->prefix}atora_calendar_sync",
			array(
				'user_id'       => $user_id,
				'provider'      => $provider,
				'access_token'  => $tokens['access_token'],
				'refresh_token' => $tokens['refresh_token'] ?? '',
				'expires_at'    => gmdate( 'Y-m-d H:i:s', (int) $tokens['expires_at'] ),
				'sync_enabled'  => 1,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Obtiene los tokens OAuth almacenados.
	 *
	 * @param int    $user_id  ID del usuario.
	 * @param string $provider Provider.
	 * @return array|null
	 */
	private static function get_tokens( int $user_id, string $provider ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_calendar_sync
			 WHERE user_id = %d AND provider = %s LIMIT 1",
			$user_id, $provider
		) );

		if ( ! $row ) {
			return null;
		}

		return array(
			'access_token'  => $row->access_token,
			'refresh_token' => $row->refresh_token,
			'expires_at'    => strtotime( $row->expires_at ),
		);
	}
}
