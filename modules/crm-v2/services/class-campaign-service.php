<?php
/**
 * Servicio de campañas CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Campaign_Service {
	/**
	 * Templates predefinidos de campaña.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function get_templates(): array {
		return array(
			'lead_welcome' => array(
				'label'   => __( 'Bienvenida a lead', 'atora-lms' ),
				'subject' => __( 'Bienvenido/a a nuestra comunidad ATORA', 'atora-lms' ),
				'body'    => __( 'Gracias por tu interés. Te compartimos la mejor ruta para comenzar hoy.', 'atora-lms' ),
			),
			'course_offer' => array(
				'label'   => __( 'Oferta de curso', 'atora-lms' ),
				'subject' => __( 'Tu próxima formación ya está lista', 'atora-lms' ),
				'body'    => __( 'Preparamos una propuesta de curso alineada a tu objetivo académico y profesional.', 'atora-lms' ),
			),
			'payment_pending' => array(
				'label'   => __( 'Pago pendiente', 'atora-lms' ),
				'subject' => __( 'Tu inscripción está reservada, completa el pago', 'atora-lms' ),
				'body'    => __( 'Tu cupo está disponible. Finaliza el pago para activar tu acceso de inmediato.', 'atora-lms' ),
			),
			'inactive_student' => array(
				'label'   => __( 'Recuperación de estudiante inactivo', 'atora-lms' ),
				'subject' => __( 'Retoma tu avance con un plan simple', 'atora-lms' ),
				'body'    => __( 'Queremos ayudarte a retomar el ritmo. Te recomendamos revisar la próxima actividad clave.', 'atora-lms' ),
			),
			'late_submission' => array(
				'label'   => __( 'Entrega vencida', 'atora-lms' ),
				'subject' => __( 'Aún puedes presentar tu actividad', 'atora-lms' ),
				'body'    => __( 'Tu entrega sigue abierta. Revisa la rúbrica y vuelve a enviar con más precisión.', 'atora-lms' ),
			),
			'graduate_next' => array(
				'label'   => __( 'Egresado / siguiente curso', 'atora-lms' ),
				'subject' => __( 'Felicitaciones por tu avance. Sigue al siguiente nivel', 'atora-lms' ),
				'body'    => __( 'Tu progreso te permite avanzar a una nueva ruta formativa con impacto profesional.', 'atora-lms' ),
			),
			'program_invitation' => array(
				'label'   => __( 'Invitación a programa', 'atora-lms' ),
				'subject' => __( 'Te invitamos a un programa de formación avanzada', 'atora-lms' ),
				'body'    => __( 'Diseñamos esta invitación según tu perfil e intereses actuales.', 'atora-lms' ),
			),
		);
	}

	/**
	 * Crea campaña en borrador.
	 *
	 * @param array $data Datos.
	 * @return int
	 */
	public static function create_campaign( array $data ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_crm_campaigns';
		if ( ! DB_Service::table_exists( $table ) ) {
			DB_Service::maybe_install_schema();
			if ( ! DB_Service::table_exists( $table ) ) {
				return 0;
			}
		}

		$name      = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		$subject   = sanitize_text_field( (string) ( $data['subject'] ?? '' ) );
		$message   = (string) ( $data['message'] ?? '' );
		$blocks_json = $data['blocks_json'] ?? '';
		$template  = sanitize_key( (string) ( $data['template_key'] ?? 'lead_welcome' ) );
		$channel   = sanitize_key( (string) ( $data['channel'] ?? 'email' ) );
		if ( ! in_array( $channel, array( 'email', 'whatsapp', 'telegram' ), true ) ) {
			$channel = 'email';
		}
		$cta_url   = esc_url_raw( (string) ( $data['cta_url'] ?? '' ) );
		$exec_mode = sanitize_key( (string) ( $data['execution_mode'] ?? 'simulate' ) );
		if ( ! in_array( $exec_mode, array( 'simulate', 'queue' ), true ) ) {
			$exec_mode = 'simulate';
		}

		if ( '' === $name ) {
			$name = sprintf( __( 'Campaña %s', 'atora-lms' ), wp_date( 'Y-m-d H:i' ) );
		}

		$templates = self::get_templates();
		if ( isset( $templates[ $template ] ) ) {
			if ( '' === $subject ) {
				$subject = (string) $templates[ $template ]['subject'];
			}
			if ( '' === $message && empty( $blocks_json ) ) {
				$message = (string) $templates[ $template ]['body'];
			}
		}

		if ( is_array( $blocks_json ) ) {
			$blocks_json = wp_json_encode( $blocks_json );
		}
		$blocks_json = trim( (string) $blocks_json );
		if ( '' !== $blocks_json && '[' === substr( $blocks_json, 0, 1 ) ) {
			$message = $blocks_json;
		} else {
			$message = sanitize_textarea_field( $message );
		}

		$segment = array(
			'audience'   => sanitize_key( (string) ( $data['audience'] ?? 'todos' ) ),
			'status'     => sanitize_key( (string) ( $data['status'] ?? '' ) ),
			'tag'        => sanitize_text_field( (string) ( $data['tag'] ?? '' ) ),
			'course_id'  => absint( $data['course_id'] ?? 0 ),
			'search'     => sanitize_text_field( (string) ( $data['search'] ?? '' ) ),
			'identity'   => sanitize_key( (string) ( $data['identity'] ?? '' ) ),
		);

		$campaign_key = 'crmv2_' . wp_generate_password( 10, false, false );

		$inserted = $wpdb->insert(
			$table,
			array(
				'campaign_key'   => $campaign_key,
				'name'           => $name,
				'template_key'   => $template,
					'channel'        => $channel,
				'execution_mode' => $exec_mode,
				'status'         => 'draft',
				'subject'        => $subject,
				'message'        => $message,
				'cta_url'        => $cta_url,
				'segment_json'   => wp_json_encode( $segment ),
				'created_by'     => get_current_user_id(),
				'scheduled_at'   => self::sanitize_datetime( (string) ( $data['scheduled_at'] ?? '' ) ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return 0;
		}

		$campaign_id = absint( $wpdb->insert_id );
		self::prepare_recipients( $campaign_id );

		return $campaign_id;
	}

	/**
	 * Ejecuta campaña.
	 *
	 * @param int    $campaign_id ID.
	 * @param string $mode        simulate|queue.
	 * @return array<string,mixed>
	 */
	public static function launch_campaign( int $campaign_id, string $mode = 'simulate' ): array {
		global $wpdb;

		$campaign_id = absint( $campaign_id );
		$mode        = sanitize_key( $mode );
		if ( ! in_array( $mode, array( 'simulate', 'queue' ), true ) ) {
			$mode = 'simulate';
		}

		$campaign = self::get_campaign( $campaign_id );
		if ( empty( $campaign ) ) {
			return array( 'success' => false, 'message' => __( 'Campaña no encontrada.', 'atora-lms' ) );
		}

		$channel = sanitize_key( (string) ( $campaign['channel'] ?? 'email' ) );
		if ( ! in_array( $channel, array( 'email', 'whatsapp', 'telegram' ), true ) ) {
			$channel = 'email';
		}
		if ( 'simulate' !== $mode && 'email' !== $channel && ! self::channel_is_ready( $channel ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s channel label */
					__( 'El canal %s aún no está operativo para ejecución real.', 'atora-lms' ),
					strtoupper( $channel )
				),
			);
		}

		$recipients = self::get_campaign_recipients( $campaign_id );
		if ( empty( $recipients ) ) {
			self::prepare_recipients( $campaign_id );
			$recipients = self::get_campaign_recipients( $campaign_id );
		}
		if ( empty( $recipients ) ) {
			return array( 'success' => false, 'message' => __( 'No hay destinatarios elegibles para esta campaña.', 'atora-lms' ) );
		}

		$queued    = 0;
		$simulated = 0;
		$skipped   = 0;

		$segment        = json_decode( (string) ( $campaign['segment_json'] ?? '{}' ), true );
		$segment        = is_array( $segment ) ? $segment : array();
		$source_context = self::resolve_source_context();
		$identity       = self::resolve_campaign_identity( $segment, $source_context );
		$scheduled_at = sanitize_text_field( (string) ( $campaign['scheduled_at'] ?? '' ) );
		$scheduled_ok = \DateTime::createFromFormat( 'Y-m-d H:i:s', $scheduled_at, new \DateTimeZone( 'UTC' ) );
		if ( ! ( $scheduled_ok instanceof \DateTime ) ) {
			$scheduled_at = '';
		}
		if ( '' === $scheduled_at ) {
			$scheduled_at = current_time( 'mysql', true );
		}

		foreach ( $recipients as $recipient ) {
			$recipient_id = absint( $recipient['id'] ?? 0 );
			$user_id      = absint( $recipient['user_id'] ?? 0 );
			$contact_id   = absint( $recipient['contact_id'] ?? 0 );

			if ( 'simulate' === $mode ) {
				$simulated++;
				self::update_recipient_status( $recipient_id, 'simulated' );
				continue;
			}

			$recipient_email = sanitize_email( (string) ( $recipient['email'] ?? '' ) );
			if ( '' === $recipient_email && $user_id > 0 ) {
				$user = get_userdata( $user_id );
				if ( $user instanceof \WP_User ) {
					$recipient_email = sanitize_email( (string) $user->user_email );
				}
			}

			if ( '' === $recipient_email ) {
				$skipped++;
				self::update_recipient_status( $recipient_id, 'skipped', __( 'Destinatario sin email válido.', 'atora-lms' ) );
				continue;
			}

			if ( ! self::recipient_allows_channel( $channel, $user_id, $contact_id ) ) {
				$skipped++;
				self::update_recipient_status( $recipient_id, 'skipped', __( 'Sin consentimiento para el canal seleccionado.', 'atora-lms' ) );
				continue;
			}

			if ( 'email' !== $channel ) {
				$skipped++;
				self::update_recipient_status( $recipient_id, 'skipped', __( 'Canal no implementado en esta fase.', 'atora-lms' ) );
				continue;
			}

			$result = CRM_Email_Service::enqueue_campaign_email_result(
				$recipient_email,
				$user_id,
				(string) ( $campaign['subject'] ?? '' ),
				(string) ( $campaign['message'] ?? '' ),
				(string) ( $campaign['cta_url'] ?? '' ),
				array(
					'campaign_id'    => $campaign_id,
					'campaign_name'  => sanitize_text_field( (string) ( $campaign['name'] ?? '' ) ),
					'template_key'   => sanitize_key( (string) ( $campaign['template_key'] ?? 'crm_campaign' ) ),
					'source_context' => $source_context,
					'identity_key'   => $identity,
					'channel'        => $channel,
				),
				'medium',
				$scheduled_at
			);
			$queue_id = absint( $result['queue_id'] ?? 0 );

			if ( ! empty( $result['success'] ) && $queue_id > 0 ) {
				$queued++;
				self::update_recipient_status( $recipient_id, 'queued', '', $queue_id );
				if ( $contact_id ) {
					Activity_Service::log_contact_activity(
						$contact_id,
						'email_sent',
						array(
							'campaign_id' => $campaign_id,
							'template'    => 'crm_campaign',
							'status'      => 'queued',
							'queue_id'    => $queue_id,
						)
					);
				}
			} else {
				$skipped++;
				$error_msg = sanitize_text_field( (string) ( $result['message'] ?? __( 'No fue posible encolar el email.', 'atora-lms' ) ) );
				self::update_recipient_status( $recipient_id, 'failed', $error_msg );
			}
		}

		$table = $wpdb->prefix . 'atora_crm_campaigns';
		$wpdb->update(
			$table,
			array(
					'status'      => 'simulate' === $mode
						? 'simulated'
						: ( self::is_future_schedule( $scheduled_at ) ? 'scheduled' : 'queued' ),
					'launched_at' => current_time( 'mysql', true ),
				),
			array( 'id' => $campaign_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return array(
			'success'   => true,
			'queued'    => $queued,
			'simulated' => $simulated,
			'skipped'   => $skipped,
				'message'   => 'simulate' === $mode
					? sprintf( __( 'Simulación completada en %d destinatario(s).', 'atora-lms' ), $simulated )
					: sprintf(
						__( 'Campaña encolada. Canal: %1$s · Programada: %2$s · Encolados: %3$d · Omitidos: %4$d.', 'atora-lms' ),
						strtoupper( $channel ),
						esc_html( $scheduled_at ),
						$queued,
						$skipped
					),
		);
	}

	/**
	 * Envía prueba.
	 *
	 * @param string $email   Correo.
	 * @param string $subject Asunto.
	 * @param string $message Mensaje.
	 * @param string $cta_url  Cta.
	 * @param string $identity Identidad opcional.
	 * @return bool
	 */
	public static function send_test( string $email, string $subject, string $message, string $cta_url = '', string $identity = '' ): bool {
		$identity = sanitize_key( $identity );
		if ( '' === $identity ) {
			$identity = self::resolve_campaign_identity( array(), self::resolve_source_context() );
		}

		return CRM_Email_Service::send_test_email( $email, $subject, $message, $cta_url, $identity );
	}

	/**
	 * Devuelve métricas agregadas por campaña.
	 *
	 * @param int $campaign_id Campaña.
	 * @return array<string,mixed>
	 */
	public static function get_campaign_metrics( int $campaign_id ): array {
		global $wpdb;

		$campaign_id = absint( $campaign_id );
		$table       = $wpdb->prefix . 'atora_crm_campaign_recipients';
		if ( ! $campaign_id || ! DB_Service::table_exists( $table ) ) {
			return array();
		}

		$row = (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(status IN ('queued','sent')) AS sent,
					SUM(opened_at IS NOT NULL) AS opened,
					SUM(clicked_at IS NOT NULL) AS clicked,
					SUM(bounced_at IS NOT NULL) AS bounced,
					SUM(status = 'failed') AS failed
				 FROM {$table}
				 WHERE campaign_id = %d",
				$campaign_id
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$total   = absint( $row['total'] ?? 0 );
		$sent    = absint( $row['sent'] ?? 0 );
		$opened  = absint( $row['opened'] ?? 0 );
		$clicked = absint( $row['clicked'] ?? 0 );

		return array(
			'total'         => $total,
			'sent'          => $sent,
			'opened'        => $opened,
			'clicked'       => $clicked,
			'bounced'       => absint( $row['bounced'] ?? 0 ),
			'failed'        => absint( $row['failed'] ?? 0 ),
			'open_rate'     => $sent > 0 ? round( ( $opened / $sent ) * 100, 1 ) : 0,
			'click_rate'    => $opened > 0 ? round( ( $clicked / $opened ) * 100, 1 ) : 0,
			'click_to_open' => $sent > 0 ? round( ( $clicked / $sent ) * 100, 1 ) : 0,
		);
	}

	/**
	 * Devuelve campañas recientes.
	 *
	 * @param int $limit Límite.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_campaigns( int $limit = 30 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_crm_campaigns';
		if ( ! DB_Service::table_exists( $table ) ) {
			return array();
		}

		$limit = max( 1, min( 200, absint( $limit ) ) );
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		return $rows;
	}

	/**
	 * Devuelve campañas programadas próximas (vista de calendario).
	 *
	 * Incluye campañas en borrador con `scheduled_at` definido, campañas marcadas como `scheduled`
	 * y campañas encoladas con fecha futura.
	 *
	 * @param int    $limit    Límite.
	 * @param string $from_utc Fecha desde (UTC mysql). Default: ahora.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_upcoming_campaigns( int $limit = 30, string $from_utc = '' ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_crm_campaigns';
		if ( ! DB_Service::table_exists( $table ) ) {
			return array();
		}

		$limit = max( 1, min( 200, absint( $limit ) ) );
		$from_utc = sanitize_text_field( $from_utc );
		if ( '' === $from_utc ) {
			$from_utc = current_time( 'mysql', true );
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE scheduled_at IS NOT NULL
				   AND scheduled_at >= %s
				   AND status IN ('draft','scheduled','queued')
				 ORDER BY scheduled_at ASC
				 LIMIT %d",
				$from_utc,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Obtiene campaña por ID.
	 *
	 * @param int $campaign_id ID.
	 * @return array<string,mixed>
	 */
	public static function get_campaign( int $campaign_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_crm_campaigns';
		if ( ! DB_Service::table_exists( $table ) || ! $campaign_id ) {
			return array();
		}

		return (array) $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", absint( $campaign_id ) ),
			ARRAY_A
		);
	}

	/**
	 * Destinatarios por campaña.
	 *
	 * @param int $campaign_id ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_campaign_recipients( int $campaign_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_crm_campaign_recipients';
		if ( ! DB_Service::table_exists( $table ) || ! $campaign_id ) {
			return array();
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE campaign_id = %d ORDER BY id ASC", absint( $campaign_id ) ),
			ARRAY_A
		);
	}

	/**
	 * Reconstruye destinatarios de una campaña.
	 *
	 * @param int $campaign_id Campaña.
	 * @return void
	 */
	public static function rebuild_recipients( int $campaign_id ): void {
		global $wpdb;

		$campaign_id      = absint( $campaign_id );
		$recipient_table  = $wpdb->prefix . 'atora_crm_campaign_recipients';
		if ( ! $campaign_id || ! DB_Service::table_exists( $recipient_table ) ) {
			return;
		}

		$wpdb->delete( $recipient_table, array( 'campaign_id' => $campaign_id ), array( '%d' ) );
		self::prepare_recipients( $campaign_id );
	}

	/**
	 * Construye destinatarios iniciales de campaña.
	 *
	 * @param int $campaign_id ID campaña.
	 * @return void
	 */
	private static function prepare_recipients( int $campaign_id ): void {
		global $wpdb;

		$campaign = self::get_campaign( $campaign_id );
		if ( empty( $campaign ) ) {
			return;
		}

		$segment = json_decode( (string) ( $campaign['segment_json'] ?? '{}' ), true );
		$segment = is_array( $segment ) ? $segment : array();
		$channel = sanitize_key( (string) ( $campaign['channel'] ?? 'email' ) );
		if ( ! in_array( $channel, array( 'email', 'whatsapp', 'telegram' ), true ) ) {
			$channel = 'email';
		}

		$contacts = Contact_Service::list_contacts(
			array(
				'search'    => sanitize_text_field( (string) ( $segment['search'] ?? '' ) ),
				'status'    => sanitize_key( (string) ( $segment['status'] ?? '' ) ),
				'tag'       => sanitize_text_field( (string) ( $segment['tag'] ?? '' ) ),
				'course_id' => absint( $segment['course_id'] ?? 0 ),
				'limit'     => 1200,
			)
		);

		$recipient_table = $wpdb->prefix . 'atora_crm_campaign_recipients';
		if ( ! DB_Service::table_exists( $recipient_table ) ) {
			return;
		}

		foreach ( (array) ( $contacts['items'] ?? array() ) as $contact ) {
			$contact_id = absint( $contact['id'] ?? 0 );
			if ( ! $contact_id ) {
				continue;
			}
			$user_id = absint( $contact['user_id'] ?? 0 );
			$email   = sanitize_email( (string) ( $contact['email'] ?? '' ) );

			if ( 'email' === $channel && '' === $email ) {
				continue;
			}
			if ( ! self::recipient_allows_channel( $channel, $user_id, $contact_id ) ) {
				continue;
			}

			$wpdb->insert(
				$recipient_table,
				array(
					'campaign_id'  => $campaign_id,
					'contact_id'   => $contact_id,
					'user_id'      => $user_id,
					'email'        => $email,
					'status'       => 'pending',
					'tracking_id'  => self::generate_tracking_id(), // Fase 10
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Actualiza estado de destinatario.
	 *
	 * @param int    $recipient_id ID.
	 * @param string $status       Estado.
	 * @param string $error        Error.
	 * @param int    $queue_id     Queue ID.
	 * @return void
	 */
	private static function update_recipient_status( int $recipient_id, string $status, string $error = '', int $queue_id = 0 ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_crm_campaign_recipients';
		if ( ! DB_Service::table_exists( $table ) || ! $recipient_id ) {
			return;
		}

		$payload = array(
			'status'        => sanitize_key( $status ),
			'error_message' => sanitize_text_field( $error ),
			'queued_email_id' => absint( $queue_id ),
		);
		if ( in_array( $status, array( 'queued', 'failed' ), true ) ) {
			$payload['sent_at'] = current_time( 'mysql', true );
		}

		$formats = array( '%s', '%s', '%d' );
		if ( isset( $payload['sent_at'] ) ) {
			$formats[] = '%s';
		}

		$wpdb->update( $table, $payload, array( 'id' => $recipient_id ), $formats, array( '%d' ) );
	}

	/**
	 * Contexto de origen para identidad por defecto.
	 *
	 * @return string
	 */
	private static function resolve_source_context(): string {
		if ( current_user_can( 'clms_manage_commerce' ) ) {
			return 'commercial';
		}
		if ( current_user_can( 'clms_view_teacher_dashboard' ) || current_user_can( 'clms_grade_submissions' ) ) {
			return 'teacher';
		}

		return 'admin';
	}

	/**
	 * Identidad a usar para la campaña.
	 *
	 * @param array<string,mixed> $segment        Segmento.
	 * @param string              $source_context Contexto.
	 * @return string
	 */
	private static function resolve_campaign_identity( array $segment, string $source_context ): string {
		$explicit = sanitize_key( (string) ( $segment['identity'] ?? '' ) );
		if ( '' !== $explicit ) {
			return $explicit;
		}

		if ( 'teacher' === $source_context ) {
			return 'teacher';
		}
		if ( 'commercial' === $source_context ) {
			return 'admin';
		}

		return 'academia';
	}

	/**
	 * Verifica si un canal está operativo para ejecución real.
	 *
	 * @param string $channel Canal.
	 * @return bool
	 */
	private static function channel_is_ready( string $channel ): bool {
		$channel = sanitize_key( $channel );
		if ( 'email' === $channel ) {
			return class_exists( '\\ATORA_Email_Gateway' );
		}

		return false;
	}

	/**
	 * Valida consentimiento del destinatario para el canal.
	 *
	 * @param string $channel    Canal.
	 * @param int    $user_id    Usuario.
	 * @param int    $contact_id Contacto.
	 * @return bool
	 */
	private static function recipient_allows_channel( string $channel, int $user_id, int $contact_id ): bool {
		$channel    = sanitize_key( $channel );
		$user_id    = absint( $user_id );
		$contact_id = absint( $contact_id );

		if ( 'email' === $channel && $user_id > 0 ) {
			return CRM_Email_Service::user_accepts_marketing( $user_id );
		}
		if ( 'email' === $channel ) {
			return self::contact_has_channel_consent( $contact_id, 'consent_marketing' );
		}
		if ( 'whatsapp' === $channel ) {
			if ( $user_id > 0 ) {
				return (bool) get_user_meta( $user_id, 'atora_consent_whatsapp', true );
			}
			return self::contact_has_channel_consent( $contact_id, 'consent_whatsapp' );
		}
		if ( 'telegram' === $channel ) {
			if ( $user_id > 0 ) {
				return (bool) get_user_meta( $user_id, 'atora_consent_telegram', true );
			}
			return self::contact_has_channel_consent( $contact_id, 'consent_telegram' );
		}

		return false;
	}

	/**
	 * Busca consentimiento anónimo en actividad del contacto.
	 *
	 * @param int    $contact_id Contacto.
	 * @param string $key        Clave de consentimiento.
	 * @return bool
	 */
	private static function contact_has_channel_consent( int $contact_id, string $key ): bool {
		global $wpdb;

		$contact_id = absint( $contact_id );
		$key        = sanitize_key( $key );
		if ( $contact_id <= 0 || '' === $key ) {
			return false;
		}

		$table = $wpdb->prefix . 'atora_contact_activities';
		if ( ! DB_Service::table_exists( $table ) ) {
			return false;
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT activity_data
				 FROM {$table}
				 WHERE contact_id = %d
				   AND activity_type = %s
				 ORDER BY created_at DESC
				 LIMIT 5",
				$contact_id,
				'form_submitted'
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$data = json_decode( (string) ( $row['activity_data'] ?? '{}' ), true );
			$data = is_array( $data ) ? $data : array();
			$value = $data[ $key ] ?? $data[ str_replace( 'consent_', '', $key ) ] ?? null;
			if ( null === $value ) {
				continue;
			}
			if ( is_bool( $value ) ) {
				return $value;
			}
			if ( is_numeric( $value ) ) {
				return absint( $value ) > 0;
			}
			$value = sanitize_key( (string) $value );
			return in_array( $value, array( '1', 'true', 'yes', 'on', 'si', 's' ), true );
		}

		return false;
	}

	/**
	 * Indica si la fecha programada es futura.
	 *
	 * @param string $scheduled_at UTC datetime.
	 * @return bool
	 */
	private static function is_future_schedule( string $scheduled_at ): bool {
		$scheduled_at = sanitize_text_field( $scheduled_at );
		if ( '' === $scheduled_at ) {
			return false;
		}
		$ts = strtotime( $scheduled_at . ' UTC' );
		if ( false === $ts ) {
			return false;
		}

		return $ts > ( current_time( 'timestamp', true ) + 60 );
	}

	/**
	 * Normaliza datetime.
	 *
	 * @param string $raw Valor.
	 * @return string
	 */
	private static function sanitize_datetime( string $raw ): string {
		$raw = sanitize_text_field( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$dt = date_create( $raw, wp_timezone() );
		if ( ! $dt ) {
			return '';
		}
		$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Genera un tracking ID único para el seguimiento de emails (Fase 10).
	 *
	 * @return string UUID v4 sin guiones (32 chars).
	 */
	private static function generate_tracking_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return str_replace( '-', '', wp_generate_uuid4() );
		}
		return bin2hex( random_bytes( 16 ) );
	}
}
