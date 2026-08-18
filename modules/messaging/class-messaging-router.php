<?php
/**
 * ATORA LMS v5 — Router inteligente de mensajería multi-canal
 *
 * Decide automáticamente qué canal usar (Email, WhatsApp, Telegram, SMS)
 * según el tipo de mensaje, urgencia y preferencias del usuario.
 * Gestiona la cola compartida para todos los canales.
 *
 * @package ATORA_LMS\Messaging
 * @since   5.0.0
 */

namespace ATORA\Messaging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Messaging_Router
 *
 * @since 5.0.0
 */
class Messaging_Router {

	/**
	 * Tabla de reglas: tipo de mensaje → canales en orden de prioridad.
	 *
	 * @var array<string, array>
	 */
	private static array $routing_rules = array(
		'purchase'         => array( 'whatsapp', 'email' ),
		'2fa_code'         => array( 'whatsapp', 'email', 'sms' ),
		'grade'            => array( 'email', 'whatsapp', 'telegram' ),
		'live_reminder_1h' => array( 'whatsapp', 'telegram', 'email' ),
		'live_reminder_24h'=> array( 'email' ),
		'new_course'       => array( 'email', 'telegram' ),
		'marketing'        => array( 'email', 'telegram' ),
		'inactivity'       => array( 'email', 'whatsapp' ),
	);

	/**
	 * Cache de existencia de columna `priority` en la cola.
	 *
	 * @var bool|null
	 */
	private static ?bool $has_priority_column = null;

	/**
	 * Inicializa el router.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Registrar intervalo antes de programar cron para evitar acoplamiento entre módulos.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_intervals' ) );
		self::ensure_queue_schema();

		// Cron: procesar la cola de mensajes cada 5 minutos.
		add_action( 'atora_messaging_cron', array( __CLASS__, 'process_queue' ) );
		if ( ! wp_next_scheduled( 'atora_messaging_cron' ) ) {
			wp_schedule_event( time(), 'every_5_minutes', 'atora_messaging_cron' );
		}

		// Admin settings.
		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		}

		// Inicializar canales.
		if ( class_exists( 'ATORA\Messaging\WhatsApp' ) ) {
			WhatsApp::init();
		}
		if ( class_exists( 'ATORA\Messaging\Telegram_Bot' ) ) {
			Telegram_Bot::init();
		}
	}

	/**
	 * Añade intervalos de cron usados por mensajería.
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

	/**
	 * Envía un mensaje al usuario por el mejor canal disponible.
	 *
	 * @param int    $user_id     ID del usuario.
	 * @param string $type        Tipo de mensaje.
	 * @param string $template    Clave de template.
	 * @param array  $variables   Variables para el template.
	 * @param array  $options     Opciones adicionales.
	 * @return bool
	 */
	public static function send( int $user_id, string $type, string $template, array $variables = array(), array $options = array() ): bool {
		$options  = is_array( $options ) ? $options : array();
		$channels = self::resolve_channels( $user_id, $type );

		if ( empty( $channels ) ) {
			return false;
		}

		$primary_channel  = (string) array_shift( $channels );
		$fallback_channels = array_values(
			array_unique(
				array_merge(
					is_array( $options['fallback_channels'] ?? null ) ? array_map( 'strval', $options['fallback_channels'] ) : array(),
					array_map( 'strval', $channels )
				)
			)
		);
		$options['fallback_channels'] = $fallback_channels;

		if ( empty( $options['priority'] ) ) {
			$options['priority'] = self::default_priority_label_for_type( $type );
		}

		$queued = self::enqueue(
			array(
				'user_id'   => $user_id,
				'channel'   => $primary_channel,
				'type'      => $type,
				'template'  => $template,
				'variables' => $variables,
				'options'   => $options,
			)
		);

		return false !== $queued;
	}

	/**
	 * Añade un mensaje a la cola.
	 *
	 * @param array $data Datos del mensaje.
	 * @return int|false ID en la cola o false en error.
	 */
	public static function enqueue( array $data ) {
		global $wpdb;

		$user_id = absint( $data['user_id'] ?? 0 );
		$user    = get_userdata( $user_id );
		if ( ! $user ) { return false; }

		$channel   = sanitize_key( (string) ( $data['channel'] ?? 'email' ) );
		$type      = sanitize_key( (string) ( $data['type'] ?? 'marketing' ) );
		$template  = sanitize_key( (string) ( $data['template'] ?? '' ) );
		$variables = is_array( $data['variables'] ?? null ) ? $data['variables'] : array();
		$options   = is_array( $data['options'] ?? null ) ? $data['options'] : array();
		$phone     = sanitize_text_field( (string) get_user_meta( $user_id, 'atora_phone', true ) );
		$priority_label  = self::normalize_priority_label( (string) ( $options['priority'] ?? $data['priority'] ?? self::default_priority_label_for_type( $type ) ) );
		$priority_weight = self::priority_to_weight( $priority_label );

		if ( '' === $template ) {
			return false;
		}

		// Verificar que el canal está disponible para el usuario.
		if ( in_array( $channel, array( 'whatsapp', 'sms' ), true ) && '' === $phone ) {
			return false;
		}
		$fallback_channels = self::resolve_fallback_channels(
			$user_id,
			$channel,
			$type,
			$options
		);

		$allow_duplicate = ! empty( $options['allow_duplicate'] );
		$dedupe_window   = absint( $options['dedupe_window_minutes'] ?? 0 );
		$dedupe_key      = sanitize_key( (string) ( $options['dedupe_key'] ?? '' ) );

		if ( $dedupe_key ) {
			$variables['_dedupe_key'] = $dedupe_key;
		}
		$variables['_fallback_channels'] = $fallback_channels;
		$variables['_message_type']      = $type;
		$variables['_priority']          = $priority_label;
		$variables['_priority_weight']   = $priority_weight;

		if ( ! $allow_duplicate && $dedupe_key ) {
			$window       = $dedupe_window > 0 ? $dedupe_window : 10;
			$duplicate_id = self::find_recent_queue_by_dedupe_key( $dedupe_key, $window );
			if ( $duplicate_id > 0 ) {
				self::log_queue_event(
					$duplicate_id,
					'duplicate_skipped',
					array(
						'user_id'       => $user_id,
						'channel'       => $channel,
						'template'      => $template,
						'dedupe_window' => $window,
						'dedupe_key'    => $dedupe_key,
						'priority'      => $priority_label,
					)
				);
				return $duplicate_id;
			}
		}

		if ( ! $allow_duplicate && $dedupe_window > 0 ) {
			$duplicate_id = self::find_recent_duplicate( $user_id, $channel, $template, $variables, $dedupe_window );
			if ( $duplicate_id > 0 ) {
				self::log_queue_event(
					$duplicate_id,
					'duplicate_skipped',
					array(
						'user_id'       => $user_id,
						'channel'       => $channel,
						'template'      => $template,
						'dedupe_window' => $dedupe_window,
						'priority'      => $priority_label,
					)
				);
				return $duplicate_id;
			}
		}

		$insert_data = array(
			'recipient_phone' => $phone,
			'recipient_name'  => sanitize_text_field( (string) $user->display_name ),
			'user_id'         => $user_id,
			'channel'         => $channel,
			'template_key'    => $template,
			'variables'       => wp_json_encode( $variables ),
			'status'          => 'pending',
			'scheduled_at'    => $options['scheduled_at'] ?? current_time( 'mysql', true ),
		);
		$insert_format = array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );

		if ( self::has_priority_column() ) {
			$insert_data['priority'] = $priority_weight;
			$insert_format[]         = '%d';
		}

		$inserted = $wpdb->insert(
			"{$wpdb->prefix}atora_message_queue",
			$insert_data,
			$insert_format
		);
		if ( ! $inserted ) {
			return false;
		}

		$queue_id = (int) $wpdb->insert_id;
		if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'sync_message_queue_to_conversation' ) ) {
			\ATORA\CRM\CRM::sync_message_queue_to_conversation( $queue_id );
		}
		self::log_queue_event(
			$queue_id,
			'queued',
			array(
				'user_id'  => $user_id,
				'channel'  => $channel,
				'template' => $template,
				'fallback_channels' => $fallback_channels,
				'priority' => $priority_label,
			)
		);

		return $queue_id;
	}

	/**
	 * Procesa la cola de mensajes.
	 *
	 * @return void
	 */
	public static function process_queue(): void {
		global $wpdb;

		$order_clause = self::has_priority_column()
			? 'ORDER BY priority ASC, scheduled_at ASC, id ASC'
			: 'ORDER BY scheduled_at ASC, id ASC';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_message_queue
			 WHERE status = 'pending'
			   AND scheduled_at <= %s
			 {$order_clause}
			 LIMIT 50",
			current_time( 'mysql', true )
		) );

		foreach ( $rows as $row ) {
			self::dispatch( $row );
		}
	}

	/**
	 * Despacha un mensaje de la cola al canal correspondiente.
	 *
	 * @param object $row Fila de la cola.
	 * @return void
	 */
	private static function dispatch( object $row ): void {
		global $wpdb;

		$updated = $wpdb->update(
			"{$wpdb->prefix}atora_message_queue",
			array( 'status' => 'sending' ),
			array( 'id' => $row->id, 'status' => 'pending' ),
			array( '%s' ), array( '%d', '%s' )
		);
		if ( ! $updated ) {
			return;
		}

		$decoded_variables = json_decode( (string) ( $row->variables ?? '' ), true );
		$variables         = is_array( $decoded_variables ) ? $decoded_variables : array();
		$channels          = self::build_dispatch_channel_sequence( $row, $variables );
		$sent              = false;
		$sent_channel      = sanitize_key( (string) ( $row->channel ?? 'email' ) );

		foreach ( $channels as $attempt_index => $dispatch_channel ) {
			$base_channel = self::normalize_dispatch_channel( $dispatch_channel );
			$enforce_preferences = $attempt_index > 0;
			if ( '' === $base_channel || ! self::user_accepts_dispatch_channel( (int) $row->user_id, $dispatch_channel, $enforce_preferences ) ) {
				self::log_queue_event(
					(int) $row->id,
					'fallback_skipped',
					array(
						'attempt_channel' => $dispatch_channel,
						'reason'          => 'channel_not_allowed',
						'attempt_index'   => $attempt_index,
					)
				);
				continue;
			}

			$attempt_sent = self::dispatch_channel( $dispatch_channel, $row, $variables );
			self::log_queue_event(
				(int) $row->id,
				$attempt_sent ? 'attempt_sent' : 'attempt_failed',
				array(
					'attempt_channel' => $dispatch_channel,
					'base_channel'    => $base_channel,
					'attempt_index'   => $attempt_index,
				)
			);

			if ( $attempt_sent ) {
				$sent         = true;
				$sent_channel = $base_channel;
				if ( $attempt_index > 0 ) {
					self::log_queue_event(
						(int) $row->id,
						'fallback_applied',
						array(
							'original_channel' => sanitize_key( (string) ( $row->channel ?? '' ) ),
							'applied_channel'  => $dispatch_channel,
							'attempt_index'    => $attempt_index,
						)
					);
				}
				break;
			}
		}

		$wpdb->update(
			"{$wpdb->prefix}atora_message_queue",
			array(
				'channel' => $sent ? $sent_channel : sanitize_key( (string) ( $row->channel ?? '' ) ),
				'status'  => $sent ? 'sent' : 'failed',
				'sent_at' => $sent ? current_time( 'mysql', true ) : null,
				'retry_count' => $sent ? (int) ( $row->retry_count ?? 0 ) : ( (int) ( $row->retry_count ?? 0 ) + 1 ),
			),
			array( 'id' => $row->id ),
			array( '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);
		if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'sync_message_queue_to_conversation' ) ) {
			\ATORA\CRM\CRM::sync_message_queue_to_conversation( (int) $row->id );
		}
	}

	/**
	 * Determina los canales a usar para un tipo de mensaje y usuario.
	 *
	 * @param int    $user_id ID del usuario.
	 * @param string $type    Tipo de mensaje.
	 * @return array
	 */
	private static function resolve_channels( int $user_id, string $type ): array {
		$preferred = (array) apply_filters(
			'atora/messaging/routing_rules',
			self::$routing_rules,
			$user_id
		);

		$candidates = $preferred[ $type ] ?? array( 'email' );

		// Filtrar por preferencias del usuario.
		return array_values( array_filter( $candidates, static function ( $channel ) use ( $user_id ) {
			return self::user_accepts_channel( $user_id, $channel );
		} ) );
	}

	/**
	 * Determina prioridades por tipo de mensaje.
	 *
	 * @param string $type Tipo de mensaje.
	 * @return string
	 */
	private static function default_priority_label_for_type( string $type ): string {
		$type = sanitize_key( $type );

		$critical_types = array( '2fa_code' );
		$high_types     = array( 'purchase', 'grade', 'live_reminder_1h' );
		$low_types      = array( 'marketing' );

		if ( in_array( $type, $critical_types, true ) ) {
			return 'critical';
		}
		if ( in_array( $type, $high_types, true ) ) {
			return 'high';
		}
		if ( in_array( $type, $low_types, true ) ) {
			return 'low';
		}

		return 'medium';
	}

	/**
	 * Normaliza etiqueta de prioridad.
	 *
	 * @param string $priority Prioridad.
	 * @return string
	 */
	private static function normalize_priority_label( string $priority ): string {
		$priority = sanitize_key( $priority );
		if ( ! in_array( $priority, array( 'critical', 'high', 'medium', 'low' ), true ) ) {
			return 'medium';
		}

		return $priority;
	}

	/**
	 * Convierte prioridad semántica a peso numérico (menor = más urgente).
	 *
	 * @param string $priority Prioridad.
	 * @return int
	 */
	private static function priority_to_weight( string $priority ): int {
		$map = array(
			'critical' => 0,
			'high'     => 10,
			'medium'   => 30,
			'low'      => 50,
		);
		$priority = self::normalize_priority_label( $priority );

		return (int) ( $map[ $priority ] ?? 30 );
	}

	/**
	 * Resuelve fallback de canales para un mensaje.
	 *
	 * @param int    $user_id         Usuario.
	 * @param string $primary_channel Canal principal.
	 * @param string $type            Tipo de mensaje.
	 * @param array  $options         Opciones de envío.
	 * @return array<int,string>
	 */
	private static function resolve_fallback_channels( int $user_id, string $primary_channel, string $type, array $options ): array {
		$primary_channel = sanitize_key( $primary_channel );
		$type            = sanitize_key( $type );

		$requested = is_array( $options['fallback_channels'] ?? null ) ? $options['fallback_channels'] : array();
		if ( empty( $requested ) ) {
			$settings = get_option( 'atora_messaging_options', array() );
			$settings = is_array( $settings ) ? $settings : array();
			$configured = is_array( $settings['fallback_channels'] ?? null ) ? $settings['fallback_channels'] : array();
			if ( ! empty( $configured[ $primary_channel ] ) && is_array( $configured[ $primary_channel ] ) ) {
				$requested = $configured[ $primary_channel ];
			}
		}
		if ( empty( $requested ) ) {
			$defaults = array(
				'email'    => array( 'email:academia', 'email:teacher', 'whatsapp' ),
				'whatsapp' => array( 'email:teacher', 'email:academia', 'telegram' ),
				'telegram' => array( 'email:teacher', 'email:academia', 'whatsapp' ),
				'sms'      => array( 'email:academia', 'email:teacher' ),
			);
			$requested = $defaults[ $primary_channel ] ?? array( 'email:academia' );
		}

		if ( ! empty( $options['email_identity_fallback'] ) && is_array( $options['email_identity_fallback'] ) ) {
			$email_fallback = array();
			foreach ( $options['email_identity_fallback'] as $identity ) {
				$normalized = self::normalize_email_identity( (string) $identity );
				$email_fallback[] = 'email:' . $normalized;
			}
			$requested = array_values( array_merge( $email_fallback, $requested ) );
		}

		$resolved = array();
		foreach ( $requested as $channel_candidate ) {
			$dispatch_channel = self::sanitize_dispatch_channel( (string) $channel_candidate );
			if ( '' === $dispatch_channel ) {
				continue;
			}
			if ( $primary_channel === self::normalize_dispatch_channel( $dispatch_channel ) && $dispatch_channel === $primary_channel ) {
				continue;
			}
			if ( ! self::user_accepts_dispatch_channel( $user_id, $dispatch_channel ) ) {
				continue;
			}
			$resolved[] = $dispatch_channel;
		}

		return array_values( array_unique( $resolved ) );
	}

	/**
	 * Normaliza canal de despacho (acepta `email:identidad`).
	 *
	 * @param string $channel Canal bruto.
	 * @return string
	 */
	private static function sanitize_dispatch_channel( string $channel ): string {
		$channel = strtolower( trim( $channel ) );
		if ( '' === $channel ) {
			return '';
		}

		if ( false !== strpos( $channel, ':' ) ) {
			list( $base, $identity ) = array_pad( explode( ':', $channel, 2 ), 2, '' );
			$base = sanitize_key( $base );
			if ( 'email' !== $base ) {
				return '';
			}
			$identity = self::normalize_email_identity( $identity );
			return 'email:' . $identity;
		}

		$channel = sanitize_key( $channel );
		if ( ! in_array( $channel, array( 'email', 'whatsapp', 'telegram', 'sms' ), true ) ) {
			return '';
		}

		return $channel;
	}

	/**
	 * Obtiene canal base desde canal de despacho.
	 *
	 * @param string $dispatch_channel Canal interno.
	 * @return string
	 */
	private static function normalize_dispatch_channel( string $dispatch_channel ): string {
		$dispatch_channel = self::sanitize_dispatch_channel( $dispatch_channel );
		if ( '' === $dispatch_channel ) {
			return '';
		}
		if ( false !== strpos( $dispatch_channel, ':' ) ) {
			$parts = explode( ':', $dispatch_channel );
			return sanitize_key( (string) ( $parts[0] ?? '' ) );
		}

		return sanitize_key( $dispatch_channel );
	}

	/**
	 * Normaliza identidad de email para fallback.
	 *
	 * @param string $identity Identidad.
	 * @return string
	 */
	private static function normalize_email_identity( string $identity ): string {
		$identity = sanitize_key( $identity );
		$aliases  = array(
			'docencia'       => 'teacher',
			'comercio'       => 'admin',
			'comercial'      => 'admin',
			'commercial'     => 'admin',
			'administracion' => 'academia',
			'administration' => 'academia',
		);
		if ( isset( $aliases[ $identity ] ) ) {
			$identity = $aliases[ $identity ];
		}

		if ( ! in_array( $identity, array( 'academia', 'teacher', 'admin' ), true ) ) {
			return 'academia';
		}

		return $identity;
	}

	/**
	 * Valida aceptación y condiciones mínimas de un canal interno.
	 *
	 * @param int    $user_id               Usuario.
	 * @param string $dispatch_channel      Canal interno.
	 * @param bool   $enforce_preferences   Si debe exigir consentimiento del canal.
	 * @return bool
	 */
	private static function user_accepts_dispatch_channel( int $user_id, string $dispatch_channel, bool $enforce_preferences = true ): bool {
		$base_channel = self::normalize_dispatch_channel( $dispatch_channel );
		if ( '' === $base_channel ) {
			return false;
		}

		if ( $enforce_preferences && ! self::user_accepts_channel( $user_id, $base_channel ) ) {
			return false;
		}

		if ( in_array( $base_channel, array( 'whatsapp', 'sms' ), true ) ) {
			$phone = sanitize_text_field( (string) get_user_meta( $user_id, 'atora_phone', true ) );
			return '' !== $phone;
		}

		return true;
	}

	/**
	 * Construye secuencia de envío: canal principal + fallback(s).
	 *
	 * @param object $row       Fila de cola.
	 * @param array  $variables Variables del mensaje.
	 * @return array<int,string>
	 */
	private static function build_dispatch_channel_sequence( object $row, array $variables ): array {
		$primary_raw = self::sanitize_dispatch_channel( (string) ( $row->channel ?? '' ) );
		$primary_raw = '' !== $primary_raw ? $primary_raw : 'email';
		$sequence    = array( $primary_raw );

		$fallbacks = is_array( $variables['_fallback_channels'] ?? null )
			? array_map( 'strval', $variables['_fallback_channels'] )
			: array();

		foreach ( $fallbacks as $fallback_channel ) {
			$sanitized = self::sanitize_dispatch_channel( $fallback_channel );
			if ( '' === $sanitized ) {
				continue;
			}
			$sequence[] = $sanitized;
		}

		$resolved = array();
		foreach ( $sequence as $candidate ) {
			$key = $candidate;
			if ( 'email' === self::normalize_dispatch_channel( $candidate ) ) {
				$key = 'email:' . self::resolve_dispatch_email_identity( $candidate, $variables );
			}
			if ( ! isset( $resolved[ $key ] ) ) {
				$resolved[ $key ] = $candidate;
			}
		}

		return array_values( $resolved );
	}

	/**
	 * Resuelve identidad final usada por un canal `email` interno.
	 *
	 * @param string $dispatch_channel Canal interno.
	 * @param array  $variables        Variables del mensaje.
	 * @return string
	 */
	private static function resolve_dispatch_email_identity( string $dispatch_channel, array $variables ): string {
		$dispatch_channel = self::sanitize_dispatch_channel( $dispatch_channel );
		if ( false !== strpos( $dispatch_channel, ':' ) ) {
			$parts = explode( ':', $dispatch_channel, 2 );
			return self::normalize_email_identity( (string) ( $parts[1] ?? 'academia' ) );
		}

		return self::normalize_email_identity(
			(string) (
				$variables['email_identity']
				?? $variables['identity']
				?? $variables['identity_key']
				?? 'academia'
			)
		);
	}

	/**
	 * Ejecuta envío por canal interno.
	 *
	 * @param string $dispatch_channel Canal interno.
	 * @param object $row              Fila de cola.
	 * @param array  $variables        Variables del mensaje.
	 * @return bool
	 */
	private static function dispatch_channel( string $dispatch_channel, object $row, array $variables ): bool {
		$base_channel = self::normalize_dispatch_channel( $dispatch_channel );
		if ( '' === $base_channel ) {
			return false;
		}

		switch ( $base_channel ) {
			case 'whatsapp':
				if ( ! class_exists( 'ATORA\Messaging\WhatsApp' ) ) {
					return false;
				}
				$phone = sanitize_text_field( (string) ( $row->recipient_phone ?? '' ) );
				if ( '' === $phone ) {
					$phone = sanitize_text_field( (string) get_user_meta( (int) ( $row->user_id ?? 0 ), 'atora_phone', true ) );
				}
				if ( '' === $phone ) {
					return false;
				}
				return WhatsApp::send_template(
					$phone,
					sanitize_key( (string) ( $row->template_key ?? '' ) ),
					$variables
				);

			case 'telegram':
				if ( ! class_exists( 'ATORA\Messaging\Telegram_Bot' ) ) {
					return false;
				}
				return Telegram_Bot::send_message(
					(int) ( $row->user_id ?? 0 ),
					sanitize_text_field( (string) ( $variables['message'] ?? $row->template_key ?? '' ) )
				);

			case 'sms':
				$phone = sanitize_text_field( (string) ( $row->recipient_phone ?? '' ) );
				if ( '' === $phone ) {
					$phone = sanitize_text_field( (string) get_user_meta( (int) ( $row->user_id ?? 0 ), 'atora_phone', true ) );
				}
				if ( '' === $phone ) {
					return false;
				}
				return (bool) apply_filters( 'atora/messaging/send_sms', false, $phone, $variables );

			case 'email':
			default:
				$metadata = $variables;
				$metadata['email_identity'] = self::resolve_dispatch_email_identity( $dispatch_channel, $variables );
				if ( empty( $metadata['source'] ) ) {
					$metadata['source'] = 'messaging_router';
				}
				return (bool) \ATORA\EmailEngine\Email_Queue::enqueue(
					array(
						'template'        => sanitize_key( (string) ( $row->template_key ?? '' ) ),
						'user_id'         => (int) ( $row->user_id ?? 0 ),
						'priority'        => 'high',
						'allow_duplicate' => true,
						'metadata'        => $metadata,
						'source'          => 'messaging_router',
					)
				);
		}
	}

	/**
	 * Verifica si el usuario acepta el canal dado.
	 *
	 * @param int    $user_id ID del usuario.
	 * @param string $channel Canal.
	 * @return bool
	 */
	private static function user_accepts_channel( int $user_id, string $channel ): bool {
		switch ( $channel ) {
			case 'whatsapp':
				return (bool) get_user_meta( $user_id, 'atora_consent_whatsapp', true );
			case 'telegram':
				return (bool) get_user_meta( $user_id, 'atora_consent_telegram', true );
			case 'email':
				if ( class_exists( '\ATORA\EmailEngine\Email_Engine' ) && method_exists( '\ATORA\EmailEngine\Email_Engine', 'user_accepts_emails' ) ) {
					return \ATORA\EmailEngine\Email_Engine::user_accepts_emails( $user_id, 'academic_notifications' );
				}
				return true;
			default:
				return true;
		}
	}

	/**
	 * Busca duplicados recientes en la cola de mensajes.
	 *
	 * @param int    $user_id         Usuario destino.
	 * @param string $channel         Canal.
	 * @param string $template        Template.
	 * @param array  $variables       Variables del mensaje.
	 * @param int    $window_minutes  Ventana en minutos.
	 * @return int
	 */
	private static function find_recent_duplicate( int $user_id, string $channel, string $template, array $variables, int $window_minutes ): int {
		global $wpdb;

		$user_id        = absint( $user_id );
		$channel        = sanitize_key( $channel );
		$template       = sanitize_key( $template );
		$window_minutes = max( 1, absint( $window_minutes ) );
		if ( ! $user_id || '' === $channel || '' === $template ) {
			return 0;
		}

		$hash  = md5( wp_json_encode( $variables ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $window_minutes * MINUTE_IN_SECONDS ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, variables
				 FROM {$wpdb->prefix}atora_message_queue
				 WHERE user_id = %d
				   AND channel = %s
				   AND template_key = %s
				   AND status IN ('pending','sending','sent','delivered','read')
				   AND scheduled_at >= %s
				 ORDER BY id DESC
				 LIMIT 20",
				$user_id,
				$channel,
				$template,
				$since
			)
		);

		foreach ( (array) $rows as $row ) {
			$raw            = is_object( $row ) ? (string) ( $row->variables ?? '' ) : '';
			$decoded        = json_decode( $raw, true );
			$row_variables  = is_array( $decoded ) ? $decoded : array();
			$existing_hash  = md5( wp_json_encode( $row_variables ) );
			if ( hash_equals( $hash, $existing_hash ) ) {
				return absint( is_object( $row ) ? ( $row->id ?? 0 ) : 0 );
			}
		}

		return 0;
	}

	/**
	 * Verifica si se envió recientemente un dedupe_key de mensajería.
	 *
	 * @param string $dedupe_key     Clave de deduplicación.
	 * @param int    $window_minutes Ventana en minutos.
	 * @return bool
	 */
	public static function already_sent_recently( $dedupe_key, $window_minutes = 10 ): bool {
		$dedupe_key = sanitize_key( (string) $dedupe_key );
		$window_minutes = max( 1, absint( $window_minutes ) );
		if ( '' === $dedupe_key ) {
			return false;
		}

		return self::find_recent_queue_by_dedupe_key( $dedupe_key, $window_minutes ) > 0;
	}

	/**
	 * Busca en cola reciente por dedupe_key embebido en variables.
	 *
	 * @param string $dedupe_key     Clave de deduplicación.
	 * @param int    $window_minutes Ventana en minutos.
	 * @return int
	 */
	private static function find_recent_queue_by_dedupe_key( string $dedupe_key, int $window_minutes ): int {
		global $wpdb;

		$dedupe_key     = sanitize_key( $dedupe_key );
		$window_minutes = max( 1, absint( $window_minutes ) );
		if ( '' === $dedupe_key ) {
			return 0;
		}

		$since = gmdate( 'Y-m-d H:i:s', time() - ( $window_minutes * MINUTE_IN_SECONDS ) );
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, variables
				 FROM {$wpdb->prefix}atora_message_queue
				 WHERE status IN ('pending','sending','sent','delivered','read')
				   AND scheduled_at >= %s
				 ORDER BY id DESC
				 LIMIT 100",
				$since
			)
		);

		foreach ( $rows as $row ) {
			$raw      = is_object( $row ) ? (string) ( $row->variables ?? '' ) : '';
			$decoded  = json_decode( $raw, true );
			$decoded  = is_array( $decoded ) ? $decoded : array();
			$row_key  = sanitize_key( (string) ( $decoded['_dedupe_key'] ?? '' ) );
			if ( '' !== $row_key && hash_equals( $dedupe_key, $row_key ) ) {
				return absint( is_object( $row ) ? ( $row->id ?? 0 ) : 0 );
			}
		}

		return 0;
	}

	/**
	 * Garantiza columna de prioridad para ordenación por urgencia.
	 *
	 * @return void
	 */
	private static function ensure_queue_schema(): void {
		global $wpdb;

		$table = "{$wpdb->prefix}atora_message_queue";
		$like  = $wpdb->esc_like( $table );
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		if ( $exists !== $table ) {
			self::$has_priority_column = false;
			return;
		}

		$has_column = self::table_has_column( $table, 'priority' );
		if ( ! $has_column ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN priority TINYINT UNSIGNED NOT NULL DEFAULT 30 AFTER template_key" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has_column = self::table_has_column( $table, 'priority' );
		}

		self::$has_priority_column = $has_column;
	}

	/**
	 * Informa si la cola dispone de columna `priority`.
	 *
	 * @return bool
	 */
	private static function has_priority_column(): bool {
		if ( null !== self::$has_priority_column ) {
			return self::$has_priority_column;
		}
		self::ensure_queue_schema();
		if ( null !== self::$has_priority_column ) {
			return self::$has_priority_column;
		}

		global $wpdb;
		$table = "{$wpdb->prefix}atora_message_queue";
		$like  = $wpdb->esc_like( $table );
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		if ( $exists !== $table ) {
			self::$has_priority_column = false;
			return false;
		}

		self::$has_priority_column = self::table_has_column( $table, 'priority' );
		return self::$has_priority_column;
	}

	/**
	 * Verifica existencia de columna en tabla.
	 *
	 * @param string $table  Tabla completa.
	 * @param string $column Columna.
	 * @return bool
	 */
	private static function table_has_column( string $table, string $column ): bool {
		global $wpdb;

		$column_name = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table} LIKE %s",
				$column
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $column_name === $column;
	}

	/**
	 * Registra eventos mínimos de cola en atora_message_log.
	 *
	 * @param int    $queue_id   ID de cola.
	 * @param string $event_type Tipo de evento.
	 * @param array  $event_data Datos del evento.
	 * @return void
	 */
	private static function log_queue_event( int $queue_id, string $event_type, array $event_data = array() ): void {
		global $wpdb;

		$queue_id   = absint( $queue_id );
		$event_type = sanitize_key( $event_type );
		if ( ! $queue_id || '' === $event_type ) {
			return;
		}

		$table = "{$wpdb->prefix}atora_message_log";
		$like  = $wpdb->esc_like( $table );
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		if ( $exists !== $table ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'queue_id'   => $queue_id,
				'event_type' => $event_type,
				'event_data' => wp_json_encode( $event_data ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/** @return void */
	public static function register_admin_menu(): void {
		global $submenu;
		$existing_items = (array) ( $submenu['clms-dashboard'] ?? array() );
		foreach ( $existing_items as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( 'atora-messaging' === $slug ) {
				return;
			}
		}

		add_submenu_page(
			'clms-dashboard',
			__( 'Mensajería', 'atora-lms' ),
			__( 'Mensajería', 'atora-lms' ),
			'manage_options',
			'atora-messaging',
			static function () {
				$view = ATORA_LMS_MODULES_DIR . 'messaging/views/settings.php';
				if ( file_exists( $view ) ) { require $view; }
			}
		);
	}
}
