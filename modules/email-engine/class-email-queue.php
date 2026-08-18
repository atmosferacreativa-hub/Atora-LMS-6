<?php
/**
 * ATORA LMS v5 — Cola de emails asíncrona
 *
 * Añade emails a la BD de forma instantánea (< 100ms) y los procesa
 * en background vía WP-Cron cada 5 minutos en batches de 50.
 *
 * @package ATORA_LMS\EmailEngine
 * @since   5.0.0
 */

namespace ATORA\EmailEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-email-identity-resolver.php';

/**
 * Class Email_Queue
 *
 * @since 5.0.0
 */
class Email_Queue {

	/** Batch máximo por ciclo de cron. */
	const BATCH_SIZE = 50;

	/** Número máximo de reintentos. */
	const MAX_RETRIES = 3;

	/**
	 * Cache local para disponibilidad de columna identity_key.
	 *
	 * @var bool|null
	 */
	private static ?bool $has_identity_key_column = null;

	/**
	 * Inicializa la queue.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Registrar intervalo antes de cualquier programación de eventos.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );
		self::ensure_identity_key_column();

		add_action( 'atora_email_queue_cron', array( __CLASS__, 'process_batch' ) );

		if ( ! wp_next_scheduled( 'atora_email_queue_cron' ) ) {
			wp_schedule_event( time(), 'every_5_minutes', 'atora_email_queue_cron' );
		}
	}

	/**
	 * @param array $schedules Schedules existentes.
	 * @return array
	 */
	public static function add_cron_interval( array $schedules ): array {
		$schedules['every_5_minutes'] = array(
			'interval' => 300,
			'display'  => __( 'Cada 5 minutos', 'atora-lms' ),
		);
		return $schedules;
	}

	/**
	 * Añade un email a la cola.
	 *
	 * @param array $data Datos del email: template, user_id, priority, metadata, scheduled_at.
	 * @return int|false ID en la cola o false en error.
	 */
	public static function enqueue( array $data ) {
		global $wpdb;

		$user_id  = absint( $data['user_id'] ?? 0 );
		$user     = $user_id ? get_userdata( $user_id ) : null;

		if ( ! $user ) {
			return false;
		}

		$template_key = sanitize_key( $data['template'] ?? '' );
		$template     = Email_Templates::get( $template_key );

		if ( ! $template ) {
			return false;
		}

		$metadata = is_array( $data['metadata'] ?? null ) ? $data['metadata'] : array();
		$resolved_identity = self::normalize_email_identity(
			(string) (
				$data['identity']
				?? $metadata['email_identity']
				?? $metadata['identity']
				?? $metadata['identity_key']
				?? (
					class_exists( '\ATORA\EmailEngine\Email_Engine' ) && method_exists( '\ATORA\EmailEngine\Email_Engine', 'resolve_identity_for_template' )
						? Email_Engine::resolve_identity_for_template( $template_key, $metadata )
						: 'academia'
				)
			)
		);

		$metadata['email_identity'] = $resolved_identity;
		$metadata['identity_key']   = $resolved_identity;
		$metadata['template'] = $template_key;
		$metadata['source'] = sanitize_key( (string) ( $data['source'] ?? ( $metadata['source'] ?? 'system' ) ) );

		$context = Email_Templates::build_context( $user, $metadata );
		$dedupe_window = absint( $data['dedupe_window_minutes'] ?? 10 );
		$allow_duplicate = ! empty( $data['allow_duplicate'] );

		$priority_map = array( 'high' => 1, 'medium' => 5, 'low' => 10 );
		$priority     = $priority_map[ $data['priority'] ?? 'medium' ] ?? 5;

		$subject   = Email_Templates::render_string( $template['subject'], $context );
		$body_html = Email_Templates::render_html( $template_key, $context );
		$body_text = Email_Templates::render_text( $template_key, $context );

		if ( ! $allow_duplicate && $dedupe_window > 0 ) {
			$existing = self::find_recent_duplicate(
				(string) $user->user_email,
				$template_key,
				$metadata,
				$dedupe_window
			);
			if ( $existing > 0 ) {
				self::log_event(
					$existing,
					'duplicate_skipped',
					array(
						'template'      => $template_key,
						'dedupe_window' => $dedupe_window,
						'user_id'       => $user_id,
					)
				);
				return $existing;
			}
		}

		$insert_data = array(
			'recipient_email' => sanitize_email( $user->user_email ),
			'recipient_name'  => sanitize_text_field( $user->display_name ),
			'user_id'         => $user_id,
			'template_id'     => $template['id'] ?? 0,
			'subject'         => sanitize_text_field( $subject ),
			'body_html'       => self::sanitize_email_html( $body_html ),
			'body_text'       => self::sanitize_email_text( $body_text ),
			'provider'        => self::resolve_provider_for_identity( $resolved_identity ),
			'status'          => 'pending',
			'scheduled_at'    => $data['scheduled_at'] ?? current_time( 'mysql', true ),
			'priority'        => $priority,
			'metadata'        => wp_json_encode( $metadata ),
		);
		$insert_formats = array( '%s','%s','%d','%d','%s','%s','%s','%s','%s','%s','%d','%s' );
		if ( self::has_identity_key_column() ) {
			$insert_data['identity_key'] = $resolved_identity;
			$insert_formats[]            = '%s';
		}

		$inserted = $wpdb->insert(
			"{$wpdb->prefix}atora_email_queue",
			$insert_data,
			$insert_formats
		);

		if ( ! $inserted ) {
			return false;
		}

		$queue_id = (int) $wpdb->insert_id;
		if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'sync_email_queue_to_conversation' ) ) {
			\ATORA\CRM\CRM::sync_email_queue_to_conversation( $queue_id );
		}
		self::log_event(
			$queue_id,
			'queued',
			array(
				'template' => $template_key,
				'provider' => (string) $insert_data['provider'],
				'priority' => $priority,
				'source'   => $metadata['source'] ?? 'system',
			)
		);

		do_action( 'atora/email/queued', $queue_id, $template_key, $user_id );

		return $queue_id;
	}

	/**
	 * Procesa un batch de la cola.
	 *
	 * @return void
	 */
	public static function process_batch(): void {
		// Cron lock: evitar ejecuciones concurrentes (Fase 8)
		$lock_key = 'atora_eq_cron_lock';
		if ( get_transient( $lock_key ) ) {
			return; // Ya hay un batch en proceso
		}
		set_transient( $lock_key, 1, 4 * MINUTE_IN_SECONDS );
		
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_email_queue
			 WHERE status = 'pending'
			   AND scheduled_at <= %s
			   AND retry_count < %d
			 ORDER BY priority ASC, scheduled_at ASC
			 LIMIT %d",
			current_time( 'mysql', true ),
			self::MAX_RETRIES,
			self::BATCH_SIZE
		) );

		foreach ( $rows as $row ) {
			self::send_row( $row );
		}

		delete_transient( $lock_key ); // liberar lock
	}

	/**
	 * Envía un email de la cola.
	 *
	 * @param object $row Fila de la queue.
	 * @return void
	 */
	private static function send_row( object $row ): void {
		global $wpdb;

		$metadata       = self::decode_metadata( (string) ( $row->metadata ?? '' ) );
		if ( empty( $metadata['email_identity'] ) && ! empty( $row->identity_key ) ) {
			$metadata['email_identity'] = sanitize_key( (string) $row->identity_key );
		}
		$sender_profile = self::resolve_sender_profile( $metadata );
		$identity_key   = (string) ( $sender_profile['identity'] ?? 'academia' );

		// Marcar como procesando para evitar doble envío.
		$updated = $wpdb->update(
			"{$wpdb->prefix}atora_email_queue",
			array( 'status' => 'sending' ),
			array( 'id' => $row->id, 'status' => 'pending' ),
			array( '%s' ),
			array( '%d', '%s' )
		);

		if ( ! $updated ) {
			return; // Otro proceso ya lo tomó.
		}
		$provider_key = sanitize_key( (string) ( $row->provider ?: ( $sender_profile['provider'] ?? self::get_active_provider() ) ) );
		$body_html    = self::normalize_email_html_payload( (string) ( $row->body_html ?? '' ) );
		$body_text    = self::sanitize_email_text( (string) ( $row->body_text ?? '' ) );

		if ( $body_html !== (string) ( $row->body_html ?? '' ) || $body_text !== (string) ( $row->body_text ?? '' ) ) {
			$wpdb->update(
				"{$wpdb->prefix}atora_email_queue",
				array(
					'body_html' => $body_html,
					'body_text' => $body_text,
				),
				array( 'id' => (int) $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		self::log_event(
			(int) $row->id,
			'sending',
			array(
				'provider'       => $provider_key,
				'email_identity' => $identity_key,
				'identity_key'   => $identity_key,
			)
		);

		$provider_class = self::get_provider_class( $provider_key );
		$sent           = false;
		$provider_options = array(
			'atora_queue_id' => (int) $row->id,
			'from_email'     => (string) ( $sender_profile['from_email'] ?? '' ),
			'from_name'      => (string) ( $sender_profile['from_name'] ?? '' ),
			'reply_to'       => (string) ( $sender_profile['reply_to'] ?? '' ),
			'smtp_host'      => (string) ( $sender_profile['smtp_host'] ?? '' ),
			'smtp_port'      => (int) ( $sender_profile['smtp_port'] ?? 0 ),
			'smtp_encryption'=> (string) ( $sender_profile['smtp_encryption'] ?? '' ),
			'smtp_user'      => (string) ( $sender_profile['smtp_user'] ?? '' ),
			'smtp_pass'      => (string) ( $sender_profile['smtp_pass'] ?? '' ),
			'smtp_auth'      => ! empty( $sender_profile['smtp_auth'] ) ? 1 : 0,
			'email_identity' => $identity_key,
			'identity'       => $identity_key,
			'identity_key'   => $identity_key,
		);

		if ( $provider_class && class_exists( $provider_class ) && method_exists( $provider_class, 'send' ) ) {
			$sent = $provider_class::send(
				$row->recipient_email,
				$row->subject,
				$body_html,
				$body_text,
				$provider_options
			);
		}

		if ( $sent ) {
			$wpdb->update(
				"{$wpdb->prefix}atora_email_queue",
				array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ) ),
				array( 'id' => $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'sync_email_queue_to_conversation' ) ) {
				\ATORA\CRM\CRM::sync_email_queue_to_conversation( (int) $row->id );
			}
			self::log_event( (int) $row->id, 'sent', array( 'provider' => $provider_key ) );
		} else {
			$retry = (int) $row->retry_count + 1;

			// Último intento: fallback a wp_mail si el provider externo falló.
			if ( $retry >= self::MAX_RETRIES && $body_html && $row->recipient_email ) {
				$headers      = array( 'Content-Type: text/html; charset=UTF-8' );
				$reply_to     = (string) ( $sender_profile['reply_to'] ?? '' );
				if ( '' !== $reply_to ) {
					$headers[] = 'Reply-To: ' . $reply_to;
				}

				$from_email = (string) ( $sender_profile['from_email'] ?? '' );
				$from_name  = (string) ( $sender_profile['from_name'] ?? '' );
				$from_filter = static function () use ( $from_email ): string {
					return $from_email;
				};
				$from_name_filter = static function () use ( $from_name ): string {
					return $from_name;
				};

				add_filter( 'wp_mail_from', $from_filter );
				add_filter( 'wp_mail_from_name', $from_name_filter );
				$fallback_sent = wp_mail(
					sanitize_email( $row->recipient_email ),
					sanitize_text_field( $row->subject ),
					$body_html,
					$headers
				);
				remove_filter( 'wp_mail_from', $from_filter );
				remove_filter( 'wp_mail_from_name', $from_name_filter );

				if ( $fallback_sent ) {
					$wpdb->update(
						"{$wpdb->prefix}atora_email_queue",
						array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ) ),
						array( 'id' => $row->id ),
						array( '%s', '%s' ),
						array( '%d' )
					);
					if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'sync_email_queue_to_conversation' ) ) {
						\ATORA\CRM\CRM::sync_email_queue_to_conversation( (int) $row->id );
					}
					self::log_event( (int) $row->id, 'sent_via_fallback', array( 'provider' => 'wp_mail' ) );
					return;
				}
			}

			$status = $retry >= self::MAX_RETRIES ? 'failed' : 'pending';

			// Exponential backoff: 5, 25, 125 minutos.
			$backoff = (int) pow( 5, min( $retry, 3 ) );
			$next    = gmdate( 'Y-m-d H:i:s', time() + $backoff * MINUTE_IN_SECONDS );

			$wpdb->update(
				"{$wpdb->prefix}atora_email_queue",
				array( 'status' => $status, 'retry_count' => $retry, 'scheduled_at' => $next ),
				array( 'id' => $row->id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'sync_email_queue_to_conversation' ) ) {
				\ATORA\CRM\CRM::sync_email_queue_to_conversation( (int) $row->id );
			}
			self::log_event(
				(int) $row->id,
				'failed' === $status ? 'failed' : 'retry_scheduled',
				array(
					'retry_count'   => $retry,
					'next_attempt'  => $next,
					'original_provider' => (string) $row->provider,
				)
			);
		}
	}

	/**
	 * Devuelve el nombre del provider activo.
	 *
	 * @return string
	 */
	public static function get_active_provider(): string {
		$opts     = self::get_email_engine_settings();
		$provider = sanitize_key( (string) ( $opts['provider'] ?? 'smtp' ) );
		if ( ! in_array( $provider, array( 'smtp', 'brevo', 'sendgrid', 'mailgun', 'ses', 'postmark' ), true ) ) {
			$provider = 'smtp';
		}

		return $provider;
	}

	/**
	 * Devuelve el FQCN de la clase del provider.
	 *
	 * @param string $provider Nombre del provider.
	 * @return string|null
	 */
	private static function get_provider_class( string $provider ): ?string {
		$map = array(
			'brevo'    => 'ATORA\EmailEngine\Providers\Brevo',
			'sendgrid' => 'ATORA\EmailEngine\Providers\SendGrid',
			'mailgun'  => 'ATORA\EmailEngine\Providers\Mailgun',
			'ses'      => 'ATORA\EmailEngine\Providers\Amazon_SES',
			'postmark' => 'ATORA\EmailEngine\Providers\Postmark',
			'smtp'     => 'ATORA\EmailEngine\Providers\SMTP',
		);

		$class = $map[ $provider ] ?? null;

		if ( $class ) {
			$interface_file = ATORA_LMS_MODULES_DIR . 'email-engine/providers/class-provider-interface.php';
			if ( file_exists( $interface_file ) ) {
				require_once $interface_file;
			}

			$file = ATORA_LMS_MODULES_DIR . 'email-engine/providers/class-' . str_replace( '_', '-', strtolower( basename( str_replace( '\\', '/', $class ) ) ) ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		return $class;
	}

	/**
	 * Decodifica metadata serializada de la cola.
	 *
	 * @param string $raw JSON serializado.
	 * @return array<string,mixed>
	 */
	private static function decode_metadata( string $raw ): array {
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Sanitiza HTML del email preservando estructura (incluye <style>).
	 *
	 * @param string $html HTML renderizado internamente.
	 * @return string
	 */
	private static function sanitize_email_html( string $html ): string {
		$html = str_replace( "\0", '', (string) $html );
		$html = (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
		$html = (string) preg_replace( '#<iframe\b[^>]*>.*?</iframe>#is', '', $html );
		return trim( $html );
	}

	/**
	 * Repara payloads legacy donde <style> fue eliminado y quedó CSS en texto.
	 *
	 * @param string $html HTML de la cola.
	 * @return string
	 */
	private static function normalize_email_html_payload( string $html ): string {
		$html = self::sanitize_email_html( $html );
		if ( '' === $html ) {
			return '';
		}

		if ( false !== stripos( $html, '<style' ) ) {
			return $html;
		}

		$wrap_plain_css = static function ( string $candidate ): string {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				return '';
			}
			if ( false === strpos( $candidate, '{' ) || false === strpos( $candidate, '}' ) ) {
				return '';
			}
			return '<style>' . $candidate . '</style>';
		};

		// Caso legacy típico: CSS plano entre </title> y </head>.
		$head_replaced = 0;
		$html = (string) preg_replace_callback(
			'#(</title>\s*)([^<]+?)(\s*</head>)#is',
			static function ( array $matches ) use ( $wrap_plain_css ): string {
				$css = $wrap_plain_css( (string) ( $matches[2] ?? '' ) );
				if ( '' === $css ) {
					return (string) ( $matches[0] ?? '' );
				}
				return (string) ( $matches[1] ?? '' ) . $css . (string) ( $matches[3] ?? '' );
			},
			$html,
			1,
			$head_replaced
		);
		if ( $head_replaced > 0 ) {
			return $html;
		}

		// Fallback: CSS plano inyectado al inicio del <body>.
		$html = (string) preg_replace_callback(
			'#(<body\b[^>]*>\s*)([^<]+?)(\s*<)#is',
			static function ( array $matches ) use ( $wrap_plain_css ): string {
				$css = $wrap_plain_css( (string) ( $matches[2] ?? '' ) );
				if ( '' === $css ) {
					return (string) ( $matches[0] ?? '' );
				}
				return (string) ( $matches[1] ?? '' ) . $css . (string) ( $matches[3] ?? '' );
			},
			$html,
			1
		);

		return $html;
	}

	/**
	 * Sanitiza versión texto plano del email.
	 *
	 * @param string $text Texto plano.
	 * @return string
	 */
	private static function sanitize_email_text( string $text ): string {
		$text = str_replace( "\0", '', (string) $text );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = (string) preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );
		return sanitize_textarea_field( trim( $text ) );
	}

	/**
	 * Devuelve el primer valor string no vacío de una lista.
	 *
	 * @param array<int,mixed> $candidates Lista de candidatos.
	 * @param string           $fallback   Valor por defecto.
	 * @return string
	 */
	private static function first_non_empty_string( array $candidates, string $fallback = '' ): string {
		foreach ( $candidates as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}
			$value = trim( (string) $candidate );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return $fallback;
	}

	/**
	 * Devuelve el primer entero positivo de una lista.
	 *
	 * @param array<int,mixed> $candidates Lista de candidatos.
	 * @param int              $fallback   Valor por defecto.
	 * @return int
	 */
	private static function first_positive_int( array $candidates, int $fallback = 0 ): int {
		foreach ( $candidates as $candidate ) {
			$value = absint( $candidate );
			if ( $value > 0 ) {
				return $value;
			}
		}

		return absint( $fallback );
	}

	/**
	 * Resuelve remitente/reply-to según identidad configurada.
	 *
	 * @param array<string,mixed> $metadata Metadata del email.
	 * @return array<string,mixed>
	 */
	private static function resolve_sender_profile( array $metadata ): array {
		$settings = self::get_email_engine_settings();
		$identity = self::normalize_email_identity( (string) ( $metadata['email_identity'] ?? 'academia' ) );
		$identity_settings = self::get_identity_settings( $identity );

		$identity_prefix_map = array(
			'academia' => 'identity_academia',
			'teacher'  => 'identity_teacher',
			'admin'    => 'identity_admin',
		);
		$prefix = $identity_prefix_map[ $identity ] ?? 'identity_academia';

		$default_from_email = sanitize_email( (string) get_option( 'admin_email' ) );
		$from_email = sanitize_email(
			self::first_non_empty_string(
				array(
					(string) ( $settings[ $prefix . '_from_email' ] ?? '' ),
					(string) ( $identity_settings['from_email'] ?? '' ),
					(string) ( $settings['from_email'] ?? '' ),
					$default_from_email,
				),
				$default_from_email
			)
		);
		if ( ! $from_email || ! is_email( $from_email ) ) {
			$from_email = $default_from_email;
		}

		$default_from_name = sanitize_text_field( (string) get_bloginfo( 'name' ) );
		$from_name = sanitize_text_field(
			self::first_non_empty_string(
				array(
					(string) ( $settings[ $prefix . '_from_name' ] ?? '' ),
					(string) ( $identity_settings['from_name'] ?? '' ),
					(string) ( $settings['from_name'] ?? '' ),
					$default_from_name,
				),
				$default_from_name
			)
		);
		if ( '' === $from_name ) {
			$from_name = $default_from_name;
		}

		$reply_to = sanitize_email(
			self::first_non_empty_string(
				array(
					(string) ( $settings[ $prefix . '_reply_to' ] ?? '' ),
					(string) ( $identity_settings['reply_to'] ?? '' ),
					(string) ( $settings['reply_to'] ?? '' ),
					$from_email,
				),
				$from_email
			)
		);
		if ( $reply_to && ! is_email( $reply_to ) ) {
			$reply_to = $from_email;
		}

		$smtp_host = sanitize_text_field(
			self::first_non_empty_string(
				array(
					(string) ( $settings['smtp_host'] ?? '' ),
					(string) ( $identity_settings['smtp_host'] ?? '' ),
				)
			)
		);
		$smtp_port = self::first_positive_int(
			array(
				$settings['smtp_port'] ?? 0,
				$identity_settings['smtp_port'] ?? 0,
				587,
			),
			587
		);
		if ( $smtp_port <= 0 ) {
			$smtp_port = 587;
		}
		$smtp_encryption = sanitize_key(
			self::first_non_empty_string(
				array(
					(string) ( $settings['smtp_encryption'] ?? '' ),
					(string) ( $identity_settings['smtp_secure'] ?? '' ),
					(string) ( $identity_settings['smtp_encryption'] ?? '' ),
					'tls',
				),
				'tls'
			)
		);
		if ( ! in_array( $smtp_encryption, array( 'ssl', 'tls' ), true ) ) {
			$smtp_encryption = 'tls';
		}
		$smtp_user = sanitize_text_field(
			self::first_non_empty_string(
				array(
					(string) ( $identity_settings['smtp_username'] ?? '' ),
					(string) ( $identity_settings['smtp_user'] ?? '' ),
					(string) ( $settings['smtp_user'] ?? '' ),
				)
			)
		);
		$smtp_pass = self::first_non_empty_string(
			array(
				(string) ( $identity_settings['smtp_password'] ?? '' ),
				(string) ( $settings['smtp_pass'] ?? '' ),
			)
		);
		$smtp_auth = array_key_exists( 'smtp_auth', $identity_settings )
			? ( ! empty( $identity_settings['smtp_auth'] ) )
			: ! empty( $settings['smtp_auth'] );
		if ( ! $smtp_auth && '' !== $smtp_user ) {
			$smtp_auth = true;
		}
		$provider  = self::resolve_provider_for_identity( $identity );
		if ( ! in_array( $provider, array( 'smtp', 'brevo', 'sendgrid', 'mailgun', 'ses', 'postmark' ), true ) ) {
			$provider = 'smtp';
		}

		return array(
			'identity'        => $identity,
			'provider'        => $provider,
			'from_email'      => $from_email,
			'from_name'       => $from_name,
			'reply_to'        => $reply_to,
			'smtp_host'       => $smtp_host,
			'smtp_port'       => $smtp_port,
			'smtp_encryption' => $smtp_encryption,
			'smtp_user'       => $smtp_user,
			'smtp_pass'       => $smtp_pass,
			'smtp_auth'       => $smtp_auth ? 1 : 0,
		);
	}

	/**
	 * Obtiene la configuración de Email Engine desde owner central.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_email_engine_settings(): array {
		if ( class_exists( 'CLMS_Settings' ) && method_exists( '\CLMS_Settings', 'get_email_engine_settings' ) ) {
			$settings = \CLMS_Settings::get_email_engine_settings();
			return is_array( $settings ) ? $settings : array();
		}

		$settings = get_option( 'atora_email_engine_options', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Normaliza identidades permitidas y aliases en español.
	 *
	 * @param string $identity Identidad solicitada.
	 * @return string
	 */
	private static function normalize_email_identity( string $identity ): string {
		return Email_Identity_Resolver::normalize( $identity, 'academia' );
	}

	/**
	 * Resuelve provider por identidad con fallback a provider global.
	 *
	 * @param string $identity Identidad.
	 * @return string
	 */
	private static function resolve_provider_for_identity( string $identity ): string {
		$identity         = self::normalize_email_identity( $identity );
		$settings         = self::get_email_engine_settings();
		$default_provider = sanitize_key( (string) ( $settings['provider'] ?? 'smtp' ) );
		if ( ! in_array( $default_provider, array( 'smtp', 'brevo', 'sendgrid', 'mailgun', 'ses', 'postmark' ), true ) ) {
			$default_provider = 'smtp';
		}

		/*
		 * El provider es global en la configuración actual de ATORA.
		 * Ignoramos valores legacy por identidad para evitar desalineaciones
		 * entre `atora_email_engine_options` y `atora_email_identities`.
		 */
		return $default_provider;
	}

	/**
	 * Devuelve la clave principal usada para `atora_email_identities`.
	 *
	 * @param string $identity Identidad canonical.
	 * @return string
	 */
	private static function get_identity_option_key( string $identity ): string {
		$identity = self::normalize_email_identity( $identity );
		return Email_Identity_Resolver::to_option_key( $identity );
	}

	/**
	 * Obtiene configuración por identidad desde option dedicada (si existe).
	 *
	 * @param string $identity Identidad solicitada.
	 * @return array<string,mixed>
	 */
	private static function get_identity_settings( string $identity ): array {
		$identity = self::normalize_email_identity( $identity );
		$option   = get_option( 'atora_email_identities', array() );
		$option   = is_array( $option ) ? $option : array();

		$candidates = array(
			self::get_identity_option_key( $identity ),
			$identity,
		);
		$candidates = array_merge( $candidates, Email_Identity_Resolver::option_candidates( $identity ) );
		$candidates = array_values( array_unique( array_map( 'sanitize_key', $candidates ) ) );

		$raw = array();
		foreach ( $candidates as $candidate ) {
			$value = $option[ $candidate ] ?? null;
			if ( is_array( $value ) ) {
				$raw = $value;
				break;
			}
		}

		if ( empty( $raw ) ) {
			return array();
		}

		$smtp_password = '';
		if ( isset( $raw['smtp_password'] ) ) {
			$smtp_password = (string) $raw['smtp_password'];
		} elseif ( isset( $raw['smtp_password_encrypted'] ) ) {
			$smtp_password = self::decrypt_identity_secret( (string) $raw['smtp_password_encrypted'] );
		}

		return array(
			'from_name'     => sanitize_text_field( (string) ( $raw['from_name'] ?? '' ) ),
			'from_email'    => sanitize_email( (string) ( $raw['from_email'] ?? '' ) ),
			'reply_to'      => sanitize_email( (string) ( $raw['reply_to'] ?? '' ) ),
			'provider'      => sanitize_key( (string) ( $raw['provider'] ?? '' ) ),
			'smtp_host'     => sanitize_text_field( (string) ( $raw['smtp_host'] ?? '' ) ),
			'smtp_port'     => absint( $raw['smtp_port'] ?? 0 ),
			'smtp_secure'   => sanitize_key( (string) ( $raw['smtp_secure'] ?? $raw['smtp_encryption'] ?? '' ) ),
			'smtp_username' => sanitize_text_field( (string) ( $raw['smtp_username'] ?? $raw['smtp_user'] ?? '' ) ),
			'smtp_password' => $smtp_password,
			'smtp_auth'     => ! empty( $raw['smtp_auth'] ),
		);
	}

	/**
	 * Descifra secretos almacenados en `atora_email_identities`.
	 *
	 * @param string $value Valor cifrado.
	 * @return string
	 */
	private static function decrypt_identity_secret( string $value ): string {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return sanitize_text_field( $value );
		}

		$key = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ), true ), 0, 16 );
		$decrypted = openssl_decrypt( $value, 'AES-256-CBC', $key, 0, $iv ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_string( $decrypted ) ) {
			return $decrypted;
		}

		// Compat: algunas instalaciones antiguas guardaron el valor en claro.
		return sanitize_text_field( $value );
	}

	/**
	 * Garantiza columna identity_key en la cola (migración no destructiva).
	 *
	 * @return void
	 */
	private static function ensure_identity_key_column(): void {
		global $wpdb;

		$table = "{$wpdb->prefix}atora_email_queue";
		$like  = $wpdb->esc_like( $table );
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		if ( $exists !== $table ) {
			return;
		}

		$column_name = (string) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'identity_key' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 'identity_key' === $column_name ) {
			self::$has_identity_key_column = true;
			return;
		}

		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN identity_key VARCHAR(40) NOT NULL DEFAULT 'academia' AFTER metadata" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::$has_identity_key_column = true;
	}

	/**
	 * Verifica si la tabla de cola dispone de identity_key.
	 *
	 * @return bool
	 */
	private static function has_identity_key_column(): bool {
		if ( null !== self::$has_identity_key_column ) {
			return self::$has_identity_key_column;
		}

		global $wpdb;
		$table = "{$wpdb->prefix}atora_email_queue";
		$like  = $wpdb->esc_like( $table );
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		if ( $exists !== $table ) {
			self::$has_identity_key_column = false;
			return false;
		}

		$column_name = (string) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'identity_key' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::$has_identity_key_column = ( 'identity_key' === $column_name );

		return self::$has_identity_key_column;
	}

	/**
	 * Busca duplicados recientes para evitar envíos repetidos por race o hooks duplicados.
	 *
	 * @param string $recipient_email Email destinatario.
	 * @param string $template_key    Template lógico.
	 * @param array  $metadata        Metadata del envío.
	 * @param int    $window_minutes  Ventana en minutos.
	 * @return int
	 */
	private static function find_recent_duplicate( string $recipient_email, string $template_key, array $metadata, int $window_minutes ): int {
		global $wpdb;

		$recipient_email = sanitize_email( $recipient_email );
		$template_key    = sanitize_key( $template_key );
		$window_minutes  = max( 1, absint( $window_minutes ) );
		$hash            = md5( wp_json_encode( array( $template_key, $metadata ) ) );

		$since = gmdate( 'Y-m-d H:i:s', time() - ( $window_minutes * MINUTE_IN_SECONDS ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, metadata
				 FROM {$wpdb->prefix}atora_email_queue
				 WHERE recipient_email = %s
				   AND created_at >= %s
				   AND status IN ('pending','sending','sent')
				 ORDER BY id DESC
				 LIMIT 20",
				$recipient_email,
				$since
			)
		);

		if ( empty( $rows ) ) {
			return 0;
		}

		foreach ( $rows as $row ) {
			$row       = is_object( $row ) ? $row : (object) array();
			$row_meta  = is_string( $row->metadata ?? '' ) ? $row->metadata : '';
			$decoded   = json_decode( $row_meta, true );
			$decoded   = is_array( $decoded ) ? $decoded : array();
			$stored_key = sanitize_key( (string) ( $decoded['template'] ?? '' ) );
			if ( $stored_key && $stored_key !== $template_key ) {
				continue;
			}
			$existing_hash = md5( wp_json_encode( array( $template_key, $decoded ) ) );
			if ( hash_equals( $hash, $existing_hash ) ) {
				return absint( $row->id ?? 0 );
			}
		}

		return 0;
	}

	/**
	 * Guarda un evento de lifecycle del email.
	 *
	 * @param int    $queue_id   ID del email en cola.
	 * @param string $event_type Tipo de evento.
	 * @param array  $event_data Datos mínimos del evento.
	 * @return void
	 */
	private static function log_event( int $queue_id, string $event_type, array $event_data = array() ): void {
		global $wpdb;

		$queue_id = absint( $queue_id );
		$event_type = sanitize_key( $event_type );
		if ( ! $queue_id || '' === $event_type ) {
			return;
		}

		$wpdb->insert(
			"{$wpdb->prefix}atora_email_events",
			array(
				'queue_id'   => $queue_id,
				'event_type' => $event_type,
				'event_data' => wp_json_encode( $event_data ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}
}
