<?php
/**
 * Servicio de email para CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CRM_Email_Service {
	/**
	 * Encola email de campaña con template CRM.
	 *
	 * @param int    $user_id      Destinatario.
	 * @param string $subject      Asunto.
	 * @param string $message      Mensaje.
	 * @param string $cta_url      URL CTA.
	 * @param array  $extra        Metadata extra.
	 * @param string $priority     Prioridad.
	 * @return bool
	 */
	public static function enqueue_campaign_email( int $user_id, string $subject, string $message, string $cta_url = '', array $extra = array(), string $priority = 'medium' ): bool {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		$result = self::enqueue_campaign_email_result(
			sanitize_email( (string) $user->user_email ),
			$user_id,
			$subject,
			$message,
			$cta_url,
			$extra,
			$priority
		);

		return ! empty( $result['success'] );
	}

	/**
	 * Encola email de campaña y retorna metadata de resultado.
	 *
	 * @param string              $recipient_email Email destino.
	 * @param int                 $user_id         Usuario relacionado (0 permitido).
	 * @param string              $subject         Asunto.
	 * @param string              $message         Mensaje base.
	 * @param string              $cta_url         CTA opcional.
	 * @param array<string,mixed> $extra           Metadata extra.
	 * @param string              $priority        Prioridad (high|medium|low).
	 * @param string              $scheduled_at    Fecha UTC `Y-m-d H:i:s`.
	 * @return array<string,mixed>
	 */
	public static function enqueue_campaign_email_result(
		string $recipient_email,
		int $user_id,
		string $subject,
		string $message,
		string $cta_url = '',
		array $extra = array(),
		string $priority = 'medium',
		string $scheduled_at = ''
	): array {
		$recipient_email = sanitize_email( $recipient_email );
		$user_id         = absint( $user_id );
		$subject         = sanitize_text_field( $subject );
		$message         = (string) $message;
		$cta_url         = esc_url_raw( $cta_url );
		$extra           = is_array( $extra ) ? $extra : array();

		if ( '' === $recipient_email || ! is_email( $recipient_email ) ) {
			return array(
				'success'  => false,
				'status'   => 'error',
				'queue_id' => 0,
				'message'  => __( 'Email destino inválido para campaña.', 'atora-lms' ),
			);
		}

		$identity = self::resolve_campaign_identity( $extra );
		$priority = sanitize_key( $priority );
		if ( ! in_array( $priority, array( 'high', 'medium', 'low' ), true ) ) {
			$priority = 'medium';
		}

		$body = self::build_campaign_email_html(
			$subject,
			$message,
			$cta_url,
			array(),
			$extra['blocks_json'] ?? ''
		);

		$metadata = array_merge(
			array(
				'custom_subject' => $subject,
				'custom_message' => wp_json_encode( self::normalize_message_payload( $message, $extra['blocks_json'] ?? '' ) ),
				'cta_url'        => $cta_url,
				'source'         => 'crm_v2_campaign',
				'email_identity' => $identity,
				'identity'       => $identity,
				'identity_key'   => $identity,
			),
			$extra
		);
		if ( $user_id > 0 ) {
			$metadata['user_id'] = $user_id;
		}

		$queue_id = self::insert_direct_queue_row(
			$recipient_email,
			$subject,
			$body,
			$identity,
			$metadata,
			'pending',
			$scheduled_at,
			$priority
		);
		if ( $queue_id <= 0 ) {
			return array(
				'success'  => false,
				'status'   => 'error',
				'queue_id' => 0,
				'message'  => __( 'No fue posible encolar el email de campaña.', 'atora-lms' ),
			);
		}

		return array(
			'success'  => true,
			'status'   => 'queued',
			'queue_id' => $queue_id,
			'message'  => __( 'Email encolado correctamente.', 'atora-lms' ),
		);
	}

	/**
	 * Envío de prueba inmediato.
	 *
	 * @param string $to      Destino.
	 * @param string $subject Asunto.
	 * @param string $message Mensaje.
	 * @param string $cta_url URL.
	 * @return bool
	 */
	public static function send_test_email( string $to, string $subject, string $message, string $cta_url = '', string $identity = 'teacher' ): bool {
		$to      = sanitize_email( $to );
		$subject = sanitize_text_field( $subject );
		$message = (string) $message;
		$cta_url = esc_url_raw( $cta_url );
		$identity = self::normalize_identity_key( $identity );

		if ( '' === $to || ! is_email( $to ) || ! class_exists( '\\ATORA_Email_Gateway' ) ) {
			return false;
		}

		$body = self::build_campaign_email_html( $subject, $message, $cta_url );

		$sent = self::send_email_with_identity( $to, $subject, $body, $identity );
		self::register_test_delivery(
			$to,
			$subject,
			$body,
			$identity,
			$sent,
			array(
				'cta_url' => $cta_url,
				'source'  => 'crm_v2_test',
			)
		);

		return $sent;
	}

	/**
	 * Encola una respuesta de Inbox (sin envío inmediato) y retorna estado.
	 *
	 * @param string              $to      Destinatario.
	 * @param string              $subject Asunto.
	 * @param string              $message Mensaje plano.
	 * @param string              $identity Identidad de envío.
	 * @param array<string,mixed> $context Contexto adicional.
	 * @return array<string,mixed>
	 */
	public static function enqueue_inbox_reply( string $to, string $subject, string $message, string $identity = 'teacher', array $context = array() ): array {
		$to       = sanitize_email( $to );
		$subject  = sanitize_text_field( $subject );
		$message  = sanitize_textarea_field( $message );
		$identity = self::normalize_identity_key( $identity );
		$context  = is_array( $context ) ? $context : array();

		if ( '' === $to || ! is_email( $to ) || '' === $message ) {
			return array(
				'success' => false,
				'status'  => 'error',
				'queue_id'=> 0,
				'message' => __( 'Datos incompletos para responder por email.', 'atora-lms' ),
			);
		}

		$user_id = absint( $context['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			$user = get_user_by( 'email', $to );
			if ( $user instanceof \WP_User ) {
				$user_id = absint( $user->ID );
			}
		}

		$metadata = array(
			'source'          => 'crm_v2_inbox',
			'template'        => 'crm_campaign',
			'email_identity'  => $identity,
			'identity'        => $identity,
			'identity_key'    => $identity,
			'contact_id'      => absint( $context['contact_id'] ?? 0 ),
			'conversation_id' => absint( $context['conversation_id'] ?? 0 ),
			'sent_by_user_id' => get_current_user_id(),
			'custom_message'  => $message,
			'custom_subject'  => $subject,
		);
		if ( $user_id > 0 ) {
			$metadata['user_id'] = $user_id;
		}
		if ( ! empty( $context['course_id'] ) ) {
			$metadata['course_id'] = absint( $context['course_id'] );
		}

		$queue_id = self::insert_direct_queue_row(
			$to,
			$subject ?: __( 'Seguimiento ATORA', 'atora-lms' ),
			wpautop( esc_html( $message ) ),
			$identity,
			$metadata,
			'pending'
		);
		if ( $queue_id <= 0 ) {
			return array(
				'success' => false,
				'status'  => 'error',
				'queue_id'=> 0,
				'message' => __( 'No fue posible encolar la respuesta.', 'atora-lms' ),
			);
		}

		$contact_id = absint( $metadata['contact_id'] ?? 0 );
		if ( $contact_id > 0 ) {
			Activity_Service::log_contact_activity(
				$contact_id,
				'email_sent',
				array(
					'conversation_id' => absint( $metadata['conversation_id'] ?? 0 ),
					'source'          => 'crm_v2_inbox',
					'status'          => 'queued',
					'queue_id'        => $queue_id,
				)
			);
		}

		return array(
			'success'  => true,
			'status'   => 'queued',
			'queue_id' => $queue_id,
			'message'  => __( 'Respuesta encolada. Se ha enviado a la cola de salida.', 'atora-lms' ),
		);
	}

	/**
	 * Renderiza bloques del email a HTML.
	 *
	 * @param array $blocks Bloques.
	 * @param array $vars   Variables dinámicas.
	 * @return string
	 */
	public static function render_blocks_to_html( array $blocks, array $vars = array() ): string {
		$html = '';
		foreach ( $blocks as $block ) {
			$block   = is_array( $block ) ? $block : array();
			$type    = sanitize_key( (string) ( $block['type'] ?? 'text' ) );
			$content = (string) ( $block['content'] ?? '' );
			$content = self::replace_dynamic_vars( $content, $vars );
			$content = wp_kses_post( $content );

			switch ( $type ) {
				case 'heading':
					$html .= '<h2 style="margin:0 0 12px;color:#0f172a;font-size:20px;font-weight:600;">' . $content . '</h2>';
					break;
				case 'button':
					$url = esc_url( self::replace_dynamic_vars( (string) ( $block['url'] ?? '' ), $vars ) );
					if ( '' !== $url ) {
						$html .= '<p style="margin:16px 0;"><a href="' . $url . '" style="display:inline-block;background:#0ea5e9;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px;">' . $content . '</a></p>';
					}
					break;
				case 'divider':
					$html .= '<hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">';
					break;
				case 'image':
					$src = esc_url( $content );
					if ( '' !== $src ) {
						$html .= '<p style="margin:12px 0;"><img src="' . $src . '" style="max-width:100%;border-radius:6px;" alt=""></p>';
					}
					break;
				case 'spacer':
					$html .= '<div style="height:24px;"></div>';
					break;
				case 'text':
				default:
					$html .= '<p style="margin:0 0 14px;color:#334155;font-size:15px;line-height:1.6;">' . nl2br( $content ) . '</p>';
					break;
			}
		}

		return $html;
	}

	/**
	 * Sincroniza aperturas/clics de la cola a recipients.
	 *
	 * @return void
	 */
	public static function sync_tracking_to_recipients(): void {
		global $wpdb;

		$recipients_table = $wpdb->prefix . 'atora_crm_campaign_recipients';
		$queue_table      = $wpdb->prefix . 'atora_email_queue';

		if ( ! DB_Service::table_exists( $recipients_table ) || ! DB_Service::table_exists( $queue_table ) ) {
			return;
		}

		$wpdb->query(
			"UPDATE {$recipients_table} r
			 INNER JOIN {$queue_table} q ON q.id = r.queued_email_id
			 SET r.opened_at = q.opened_at,
			     r.clicked_at = q.clicked_at,
			     r.bounced_at = q.bounced_at
			 WHERE r.queued_email_id > 0
			   AND (
				   (q.opened_at IS NOT NULL AND r.opened_at IS NULL)
				OR (q.clicked_at IS NOT NULL AND r.clicked_at IS NULL)
				OR (q.bounced_at IS NOT NULL AND r.bounced_at IS NULL)
			   )"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	/**
	 * Registra envío de prueba CRM v2 en cola + sincroniza conversación.
	 *
	 * @param string               $recipient Destino.
	 * @param string               $subject   Asunto.
	 * @param string               $body_html Cuerpo.
	 * @param string               $identity  Identidad.
	 * @param bool                 $sent      Resultado.
	 * @param array<string,mixed>  $extra     Metadata adicional.
	 * @return void
	 */
	private static function register_test_delivery( string $recipient, string $subject, string $body_html, string $identity, bool $sent, array $extra = array() ): void {
		$recipient = sanitize_email( $recipient );
		if ( '' === $recipient || ! is_email( $recipient ) ) {
			return;
		}

		$metadata = array(
			'source'          => sanitize_key( (string) ( $extra['source'] ?? 'crm_v2_test' ) ),
			'template'        => 'crm_campaign',
			'test_send'       => 1,
			'cta_url'         => esc_url_raw( (string) ( $extra['cta_url'] ?? '' ) ),
			'sent_by_user_id' => get_current_user_id(),
		);
		$queue_id = self::insert_direct_queue_row(
			$recipient,
			$subject,
			$body_html,
			$identity,
			$metadata,
			$sent ? 'sent' : 'failed'
		);
		if ( $queue_id <= 0 ) {
			return;
		}

		global $wpdb;
		$event_table = "{$wpdb->prefix}atora_email_events";
		$identity = self::normalize_identity_key( $identity );
		$profile  = self::get_identity_profile( $identity );
		$provider = sanitize_key( (string) ( $profile['provider'] ?? 'smtp' ) );
		$status   = $sent ? 'sent' : 'failed';
		$timestamp = current_time( 'mysql', true );
		$event_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $event_table ) ) === $event_table;
		if ( $event_exists ) {
			$wpdb->insert(
				$event_table,
				array(
					'queue_id'   => $queue_id,
					'event_type' => $sent ? 'test_sent' : 'test_failed',
					'event_data' => wp_json_encode(
						array(
							'source'       => (string) ( $metadata['source'] ?? 'crm_v2_test' ),
							'provider'     => $provider,
							'identity_key' => $identity,
							'status'       => $status,
						)
					),
					'created_at' => $timestamp,
				),
				array( '%d', '%s', '%s', '%s' )
			);
		}

	}

	/**
	 * Construye el HTML final de una campaña.
	 *
	 * @param string       $subject     Asunto.
	 * @param string       $message     Mensaje o JSON.
	 * @param string       $cta_url     URL CTA.
	 * @param array        $vars        Variables.
	 * @param string|array $blocks_json Bloques opcionales.
	 * @return string
	 */
	private static function build_campaign_email_html( string $subject, string $message, string $cta_url = '', array $vars = array(), $blocks_json = '' ): string {
		$body = '<h2 style="margin:0 0 14px;font-size:22px;color:#0f172a;">' . esc_html( self::replace_dynamic_vars( $subject, $vars ) ) . '</h2>';
		$blocks = self::parse_blocks_payload( $blocks_json );
		if ( empty( $blocks ) ) {
			$blocks = self::parse_blocks_payload( $message );
		}

		if ( ! empty( $blocks ) ) {
			$body .= self::render_blocks_to_html( $blocks, $vars );
		} else {
			$lines = preg_split( '/\R+/', self::replace_dynamic_vars( $message, $vars ) ) ?: array();
			foreach ( $lines as $line ) {
				$line = trim( (string) $line );
				if ( '' === $line ) {
					continue;
				}
				$body .= '<p style="margin:0 0 12px;color:#334155;line-height:1.55;">' . esc_html( $line ) . '</p>';
			}
		}

		if ( '' !== $cta_url ) {
			$body .= '<p><a style="display:inline-block;padding:10px 16px;background:#1d4ed8;color:#fff;border-radius:10px;text-decoration:none;font-weight:700;" href="' . esc_url( self::replace_dynamic_vars( $cta_url, $vars ) ) . '">' . esc_html__( 'Ver detalle', 'atora-lms' ) . '</a></p>';
		}

		return $body;
	}

	/**
	 * Normaliza payload de mensaje para metadata.
	 *
	 * @param string       $message     Mensaje base.
	 * @param string|array $blocks_json Bloques.
	 * @return array<string,mixed>
	 */
	private static function normalize_message_payload( string $message, $blocks_json = '' ): array {
		$blocks = self::parse_blocks_payload( $blocks_json );
		if ( empty( $blocks ) ) {
			$blocks = self::parse_blocks_payload( $message );
		}

		return array(
			'message' => sanitize_textarea_field( $message ),
			'blocks'  => $blocks,
		);
	}

	/**
	 * Parsea bloques serializados.
	 *
	 * @param string|array $payload Payload.
	 * @return array<int,array<string,mixed>>
	 */
	private static function parse_blocks_payload( $payload ): array {
		if ( is_array( $payload ) ) {
			return array_values( array_filter( $payload, 'is_array' ) );
		}

		$payload = trim( (string) $payload );
		if ( '' === $payload || '[' !== substr( $payload, 0, 1 ) ) {
			return array();
		}

		$decoded = json_decode( $payload, true );
		return is_array( $decoded ) ? array_values( array_filter( $decoded, 'is_array' ) ) : array();
	}

	/**
	 * Reemplaza variables dinámicas.
	 *
	 * @param string $content Contenido.
	 * @param array  $vars    Variables.
	 * @return string
	 */
	private static function replace_dynamic_vars( string $content, array $vars = array() ): string {
		foreach ( $vars as $key => $value ) {
			$content = str_replace( '{{' . sanitize_key( (string) $key ) . '}}', (string) $value, $content );
		}

		return $content;
	}

	/**
	 * Inserta un email directo en `atora_email_queue` con identidad explícita.
	 *
	 * @param string              $recipient Destinatario.
	 * @param string              $subject   Asunto.
	 * @param string              $body_html HTML.
	 * @param string              $identity  Identidad canonical.
	 * @param array<string,mixed> $metadata  Metadata adicional.
	 * @param string              $status    Estado inicial.
	 * @return int
	 */
	private static function insert_direct_queue_row(
		string $recipient,
		string $subject,
		string $body_html,
		string $identity,
		array $metadata = array(),
		string $status = 'pending',
		string $scheduled_at = '',
		string $priority = 'medium'
	): int {
		global $wpdb;

		$queue_table  = "{$wpdb->prefix}atora_email_queue";
		$queue_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table ) ) === $queue_table;
		if ( ! $queue_exists ) {
			return 0;
		}

		$recipient = sanitize_email( $recipient );
		if ( '' === $recipient || ! is_email( $recipient ) ) {
			return 0;
		}

		$identity = self::normalize_identity_key( $identity );
		$status   = sanitize_key( $status );
		$priority = sanitize_key( $priority );
		if ( ! in_array( $status, array( 'pending', 'sending', 'sent', 'failed' ), true ) ) {
			$status = 'pending';
		}
		if ( ! in_array( $priority, array( 'high', 'medium', 'low' ), true ) ) {
			$priority = 'medium';
		}
		$metadata = is_array( $metadata ) ? $metadata : array();
		$metadata['email_identity'] = $identity;
		$metadata['identity']       = $identity;
		$metadata['identity_key']   = $identity;

		$recipient_user = get_user_by( 'email', $recipient );
		$user_id        = $recipient_user instanceof \WP_User ? absint( $recipient_user->ID ) : absint( $metadata['user_id'] ?? 0 );
		$recipient_name = $recipient_user instanceof \WP_User
			? sanitize_text_field( (string) ( $recipient_user->display_name ?: $recipient_user->user_login ) )
			: '';
		if ( '' === $recipient_name && ! empty( $metadata['recipient_name'] ) ) {
			$recipient_name = sanitize_text_field( (string) $metadata['recipient_name'] );
		}

		$subject  = sanitize_text_field( substr( $subject, 0, 500 ) );
		$body_html = (string) $body_html;
		$profile  = self::get_identity_profile( $identity );
		$provider = sanitize_key( (string) ( $profile['provider'] ?? 'smtp' ) );
		if ( ! in_array( $provider, array( 'smtp', 'brevo', 'sendgrid', 'mailgun', 'ses', 'postmark' ), true ) ) {
			$provider = 'smtp';
		}

		$timestamp = current_time( 'mysql', true );
		$scheduled_at = sanitize_text_field( $scheduled_at );
		if ( '' !== $scheduled_at ) {
			$dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $scheduled_at, new \DateTimeZone( 'UTC' ) );
			if ( $dt instanceof \DateTime ) {
				$scheduled_at = $dt->format( 'Y-m-d H:i:s');
			} else {
				$scheduled_at = '';
			}
		}
		if ( '' === $scheduled_at ) {
			$scheduled_at = $timestamp;
		}
		$priority_map = array( 'high' => 1, 'medium' => 5, 'low' => 10 );

		$insert_data = array(
			'recipient_email' => $recipient,
			'recipient_name'  => $recipient_name,
			'user_id'         => $user_id,
			'template_id'     => 0,
			'subject'         => $subject,
			'body_html'       => $body_html,
			'body_text'       => sanitize_textarea_field( wp_strip_all_tags( $body_html ) ),
			'provider'        => $provider,
			'status'          => $status,
			'scheduled_at'    => $scheduled_at,
			'error_message'   => '',
			'retry_count'     => 0,
			'priority'        => 'pending' === $status ? ( $priority_map[ $priority ] ?? 5 ) : 5,
			'metadata'        => wp_json_encode( $metadata ),
			'created_at'      => $timestamp,
		);
		$formats = array(
			'%s',
			'%s',
			'%d',
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%d',
			'%d',
			'%s',
			'%s',
		);
		if ( 'sent' === $status ) {
			$insert_data['sent_at'] = $timestamp;
			$formats[]              = '%s';
		}

		$identity_column = (string) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$queue_table} LIKE %s", 'identity_key' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 'identity_key' === $identity_column ) {
			$insert_data['identity_key'] = $identity;
			$formats[] = '%s';
		}

		$inserted = $wpdb->insert( $queue_table, $insert_data, $formats );
		if ( ! $inserted ) {
			return 0;
		}

		$queue_id = (int) $wpdb->insert_id;
		if ( class_exists( '\\ATORA\\CRM\\CRM' ) && method_exists( '\\ATORA\\CRM\\CRM', 'sync_email_queue_to_conversation' ) ) {
			\ATORA\CRM\CRM::sync_email_queue_to_conversation( $queue_id );
		}

		return $queue_id;
	}

	/**
	 * Verifica si usuario acepta correos de marketing.
	 *
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public static function user_accepts_marketing( int $user_id ): bool {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}

		$raw = get_user_meta( $user_id, 'atora_consent_marketing', true );
		if ( '' === (string) $raw ) {
			return false;
		}
		if ( is_bool( $raw ) ) {
			return $raw;
		}
		if ( is_numeric( $raw ) ) {
			return absint( $raw ) > 0;
		}

		$normalized = sanitize_key( (string) $raw );
		return in_array( $normalized, array( '1', 'true', 'yes', 'on', 'si', 's' ), true );
	}

	/**
	 * Normaliza identidad de envío para rutas CRM.
	 *
	 * @param string $identity Identidad solicitada.
	 * @return string
	 */
	private static function normalize_identity_key( string $identity ): string {
		self::ensure_identity_resolver_loaded();
		if ( class_exists( '\ATORA\EmailEngine\Email_Identity_Resolver' ) ) {
			return \ATORA\EmailEngine\Email_Identity_Resolver::normalize( $identity, 'teacher' );
		}

		$identity = sanitize_key( $identity );
		return in_array( $identity, array( 'academia', 'teacher', 'admin' ), true ) ? $identity : 'teacher';
	}

	/**
	 * Resuelve identidad por metadata/contexto para campañas CRM.
	 *
	 * @param array<string,mixed> $extra Metadata extra.
	 * @return string
	 */
	private static function resolve_campaign_identity( array $extra ): string {
		$extra = is_array( $extra ) ? $extra : array();

		$raw_explicit = sanitize_key(
			(string) (
				$extra['identity_key']
				?? $extra['email_identity']
				?? $extra['identity']
				?? ''
			)
		);
		if ( '' !== $raw_explicit ) {
			return self::normalize_identity_key( $raw_explicit );
		}

		$context = sanitize_key( (string) ( $extra['source_context'] ?? '' ) );
		if ( in_array( $context, array( 'admin', 'academia', 'administracion', 'administration', 'ops', 'operations' ), true ) ) {
			return 'academia';
		}
		if ( in_array( $context, array( 'teacher', 'docencia', 'seguimiento' ), true ) ) {
			return 'teacher';
		}
		if ( in_array( $context, array( 'commercial', 'comercial', 'comercio', 'sales', 'venta', 'ventas', 'lead', 'leads', 'crm' ), true ) ) {
			return 'admin';
		}

		return 'academia';
	}

	/**
	 * Obtiene ajustes de email desde owner central con fallback.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_email_settings(): array {
		if ( class_exists( '\\CLMS_Settings' ) && method_exists( '\\CLMS_Settings', 'get_email_engine_settings' ) ) {
			$settings = \CLMS_Settings::get_email_engine_settings();
			return is_array( $settings ) ? $settings : array();
		}

		$settings = get_option( 'atora_email_engine_options', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Perfil de remitente por identidad.
	 *
	 * @param string $identity Identidad canonical.
	 * @return array<string,mixed>
	 */
	private static function get_identity_profile( string $identity ): array {
		$identity = self::normalize_identity_key( $identity );
		$settings = self::get_email_settings();

		$prefix_map = array(
			'academia' => 'identity_academia',
			'teacher'  => 'identity_teacher',
			'admin'    => 'identity_admin',
		);
		$prefix = $prefix_map[ $identity ] ?? 'identity_teacher';

		$identities_option = get_option( 'atora_email_identities', array() );
		$identities_option = is_array( $identities_option ) ? $identities_option : array();

		self::ensure_identity_resolver_loaded();
		$key_map = array(
			'academia' => array( 'academia', 'administracion' ),
			'teacher'  => array( 'teacher', 'docencia' ),
			'admin'    => class_exists( '\ATORA\EmailEngine\Email_Identity_Resolver' )
				? \ATORA\EmailEngine\Email_Identity_Resolver::option_candidates( 'admin' )
				: array( 'administracion', 'admin', 'comercio' ),
		);
		$identity_row = array();
		foreach ( (array) ( $key_map[ $identity ] ?? array() ) as $candidate_key ) {
			$candidate_key = sanitize_key( (string) $candidate_key );
			$candidate_row = $identities_option[ $candidate_key ] ?? null;
			if ( is_array( $candidate_row ) ) {
				$identity_row = $candidate_row;
				break;
			}
		}

		$provider = sanitize_key( (string) ( $identity_row['provider'] ?? $settings['provider'] ?? 'smtp' ) );
		if ( ! in_array( $provider, array( 'smtp', 'brevo', 'sendgrid', 'mailgun', 'ses', 'postmark' ), true ) ) {
			$provider = 'smtp';
		}

		$from_email = sanitize_email(
			(string) (
				$settings[ $prefix . '_from_email' ]
				?? $identity_row['from_email']
				?? $settings['from_email']
				?? get_option( 'admin_email' )
			)
		);
		if ( ! $from_email || ! is_email( $from_email ) ) {
			$from_email = sanitize_email( (string) get_option( 'admin_email' ) );
		}

		$from_name = sanitize_text_field(
			(string) (
				$settings[ $prefix . '_from_name' ]
				?? $identity_row['from_name']
				?? $settings['from_name']
				?? get_bloginfo( 'name' )
			)
		);
		$reply_to = sanitize_email(
			(string) (
				$settings[ $prefix . '_reply_to' ]
				?? $identity_row['reply_to']
				?? $settings['reply_to']
				?? ''
			)
		);
		if ( '' !== $reply_to && ! is_email( $reply_to ) ) {
			$reply_to = '';
		}

		$smtp_user = sanitize_text_field(
			(string) (
				$identity_row['smtp_username']
				?? $identity_row['smtp_user']
				?? $settings['smtp_user']
				?? ''
			)
		);
		$smtp_pass = '';
		if ( isset( $identity_row['smtp_password'] ) ) {
			$smtp_pass = (string) $identity_row['smtp_password'];
		}
		if ( '' === trim( $smtp_pass ) && ! empty( $identity_row['smtp_password_encrypted'] ) ) {
			$smtp_pass = self::decrypt_identity_secret( (string) $identity_row['smtp_password_encrypted'] );
		}
		if ( '' === trim( $smtp_pass ) ) {
			$smtp_pass = (string) ( $settings['smtp_pass'] ?? '' );
		}
		$smtp_host = sanitize_text_field( (string) ( $identity_row['smtp_host'] ?? $settings['smtp_host'] ?? '' ) );
		$smtp_port = max( 1, absint( $identity_row['smtp_port'] ?? $settings['smtp_port'] ?? 587 ) );
		$smtp_encryption = sanitize_key( (string) ( $identity_row['smtp_secure'] ?? $settings['smtp_encryption'] ?? '' ) );
		if ( ! in_array( $smtp_encryption, array( 'ssl', 'tls' ), true ) ) {
			$smtp_encryption = '';
		}
		$smtp_auth = array_key_exists( 'smtp_auth', $identity_row )
			? ! empty( $identity_row['smtp_auth'] )
			: ! empty( $settings['smtp_auth'] );
		if ( ! $smtp_auth && '' !== $smtp_user ) {
			$smtp_auth = true;
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
	 * Desencripta credenciales de identidad.
	 *
	 * @param string $value Valor encriptado.
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

		return is_string( $decrypted ) ? $decrypted : sanitize_text_field( $value );
	}

	/**
	 * Clase provider por key.
	 *
	 * @param string $provider Key provider.
	 * @return string
	 */
	private static function resolve_provider_class( string $provider ): string {
		$provider = sanitize_key( $provider );
		$map = array(
			'smtp'     => '\\ATORA\\EmailEngine\\Providers\\SMTP',
			'brevo'    => '\\ATORA\\EmailEngine\\Providers\\Brevo',
			'sendgrid' => '\\ATORA\\EmailEngine\\Providers\\SendGrid',
			'mailgun'  => '\\ATORA\\EmailEngine\\Providers\\Mailgun',
			'ses'      => '\\ATORA\\EmailEngine\\Providers\\Amazon_SES',
			'postmark' => '\\ATORA\\EmailEngine\\Providers\\Postmark',
		);

		return $map[ $provider ] ?? '';
	}

	/**
	 * Envía correo usando provider activo con identidad explícita.
	 *
	 * @param string $to        Destinatario.
	 * @param string $subject   Asunto.
	 * @param string $body_html Cuerpo HTML.
	 * @param string $identity  Identidad de envío.
	 * @return bool
	 */
	private static function send_email_with_identity( string $to, string $subject, string $body_html, string $identity ): bool {
		$profile = self::get_identity_profile( $identity );
		$provider = sanitize_key( (string) ( $profile['provider'] ?? 'smtp' ) );
		$provider_class = self::resolve_provider_class( $provider );

		$interface_file = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'email-engine/providers/class-provider-interface.php' : '';
		if ( $interface_file && file_exists( $interface_file ) ) {
			require_once $interface_file;
		}

		$provider_file_map = array(
			'smtp'     => 'class-smtp.php',
			'brevo'    => 'class-brevo.php',
			'sendgrid' => 'class-sendgrid.php',
			'mailgun'  => 'class-mailgun.php',
			'ses'      => 'class-amazon-ses.php',
			'postmark' => 'class-postmark.php',
		);
		$provider_file_name = $provider_file_map[ $provider ] ?? '';
		$provider_file = ( $provider_file_name && defined( 'ATORA_LMS_MODULES_DIR' ) ) ? ATORA_LMS_MODULES_DIR . 'email-engine/providers/' . $provider_file_name : '';
		if ( $provider_file && file_exists( $provider_file ) ) {
			require_once $provider_file;
		}

		if ( $provider_class && class_exists( $provider_class ) && method_exists( $provider_class, 'send' ) ) {
			return (bool) $provider_class::send(
				$to,
				$subject,
				$body_html,
				wp_strip_all_tags( $body_html ),
				array(
					'from_email'      => (string) ( $profile['from_email'] ?? '' ),
					'from_name'       => (string) ( $profile['from_name'] ?? '' ),
					'reply_to'        => (string) ( $profile['reply_to'] ?? '' ),
					'smtp_host'       => (string) ( $profile['smtp_host'] ?? '' ),
					'smtp_port'       => (int) ( $profile['smtp_port'] ?? 587 ),
					'smtp_user'       => (string) ( $profile['smtp_user'] ?? '' ),
					'smtp_pass'       => (string) ( $profile['smtp_pass'] ?? '' ),
					'smtp_auth'       => (int) ( $profile['smtp_auth'] ?? 0 ),
					'smtp_encryption' => (string) ( $profile['smtp_encryption'] ?? '' ),
					'email_identity'  => (string) ( $profile['identity'] ?? 'teacher' ),
					'identity_key'    => (string) ( $profile['identity'] ?? 'teacher' ),
				)
			);
		}

		return (bool) \ATORA_Email_Gateway::send(
			$to,
			$subject,
			$body_html,
			array(
				'source'         => 'crm_v2_test',
				'reply_to'       => (string) ( $profile['reply_to'] ?? '' ),
				'email_identity' => (string) ( $profile['identity'] ?? 'teacher' ),
				'identity_key'   => (string) ( $profile['identity'] ?? 'teacher' ),
			)
		);
	}

	/**
	 * Carga resolver único de identidades de Email Engine.
	 *
	 * @return void
	 */
	private static function ensure_identity_resolver_loaded(): void {
		if ( class_exists( '\ATORA\EmailEngine\Email_Identity_Resolver' ) ) {
			return;
		}

		$file = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'email-engine/class-email-identity-resolver.php' : '';
		if ( $file && file_exists( $file ) ) {
			require_once $file;
		}
	}
}
