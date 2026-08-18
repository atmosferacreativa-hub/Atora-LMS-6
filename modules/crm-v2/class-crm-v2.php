<?php
/**
 * ATORA LMS v5.21 — CRM v2 (beta)
 *
 * Evolución del andamiaje inicial:
 * - Segmentación real sobre contactos CRM.
 * - Guardado de segmentos reutilizables.
 * - Acciones masivas seguras (tag, estado, depuración controlada).
 * - Campañas con ejecución simulada o encolada.
 * - Trazabilidad local sin reemplazar el CRM actual.
 *
 * @package ATORA_LMS\CRM_V2
 * @since   5.21.0
 */

namespace ATORA\CRM_V2;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Fase V S16: traits extraídos para reducir tamaño del archivo original (1726L → ~300L)
require_once __DIR__ . '/trait-crm-v2-pipeline.php';
require_once __DIR__ . '/trait-crm-v2-segmenter.php';

/**
 * Class CRM_V2
 *
 * Clase principal CRM v2. Los métodos de pipeline y segmentación
 * se han extraído a traits (Fase V S16) para mejorar la mantenibilidad.
 *
 * @since 5.21.0
 */
class CRM_V2 {

	use CRM_V2_Pipeline_Trait;
	use CRM_V2_Segmenter_Trait;
	const FLAG_OPTION      = 'clms_crm_v2_enabled';
	const DRAFT_OPTION     = 'clms_crm_v2_beta_draft';
	const SEGMENTS_OPTION  = 'clms_crm_v2_beta_segments';
	const CAMPAIGNS_OPTION = 'clms_crm_v2_beta_campaigns';

	const MAX_CONTACTS_PREVIEW = 40;
	const MAX_CONTACTS_ACTION  = 1200;
	const MAX_CAMPAIGNS_STORED = 120;

	/**
	 * Cache para columna identity_key de la cola de email.
	 *
	 * @var bool|null
	 */
	private static ?bool $has_email_queue_identity_column = null;

	/**
	 * Indica si CRM v2 está habilitado por feature flag.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( class_exists( '\CLMS_Settings' ) && method_exists( '\CLMS_Settings', 'is_crm_v2_enabled' ) ) {
			return (bool) \CLMS_Settings::is_crm_v2_enabled();
		}

		return ! empty( get_option( self::FLAG_OPTION, false ) );
	}

	/**
	 * Control de acceso para la pantalla beta.
	 *
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public static function can_access( int $user_id = 0 ): bool {
		$user_id = absint( $user_id ?: get_current_user_id() );
		if ( ! $user_id || ! self::is_enabled() ) {
			return false;
		}

		if ( class_exists( '\ATORA\\CRM\\CRM' ) && method_exists( '\ATORA\\CRM\\CRM', 'can_manage_crm' ) ) {
			return (bool) \ATORA\CRM\CRM::can_manage_crm( $user_id );
		}

		return user_can( $user_id, 'manage_options' );
	}

	/**
	 * Renderiza la pantalla admin de CRM v2.
	 *
	 * @return void
	 */
	public static function render_admin_page(): void {
		if ( ! self::can_access() ) {
			wp_die( esc_html__( 'No tienes permisos para acceder a CRM v2.', 'atora-lms' ) );
		}

		$draft          = self::get_draft();
		$notice_type    = '';
		$notice_message = '';

		if ( 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) && isset( $_POST['atora_crm_v2_action'] ) ) {
			$action = sanitize_key( wp_unslash( (string) $_POST['atora_crm_v2_action'] ) );
			$nonce  = isset( $_POST['atora_crm_v2_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['atora_crm_v2_nonce'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'atora_crm_v2_save' ) ) {
				$notice_type    = 'error';
				$notice_message = __( 'No se pudo validar la solicitud. Recarga la página e inténtalo de nuevo.', 'atora-lms' );
			} else {
				$input = isset( $_POST['crm_v2'] ) && is_array( $_POST['crm_v2'] )
					? (array) wp_unslash( $_POST['crm_v2'] )
					: array();

				$draft = self::sanitize_draft( array_merge( $draft, $input ) );

				switch ( $action ) {
					case 'save_draft':
						update_option( self::DRAFT_OPTION, $draft, false );
						$notice_type    = 'success';
						$notice_message = __( 'Borrador guardado. Puedes seguir afinando antes de ejecutar.', 'atora-lms' );
						break;

					case 'reset_draft':
						$draft = self::get_default_draft();
						update_option( self::DRAFT_OPTION, $draft, false );
						$notice_type    = 'success';
						$notice_message = __( 'Se restauró la configuración base del flujo CRM v2.', 'atora-lms' );
						break;

					case 'save_segment':
						update_option( self::DRAFT_OPTION, $draft, false );
						$saved = self::save_named_segment( $draft, sanitize_text_field( (string) ( $input['segment_name'] ?? '' ) ) );
						if ( $saved ) {
							$notice_type    = 'success';
							$notice_message = __( 'Segmento guardado para reutilizarlo en campañas futuras.', 'atora-lms' );
						} else {
							$notice_type    = 'error';
							$notice_message = __( 'No se pudo guardar el segmento. Verifica el nombre.', 'atora-lms' );
						}
						break;

					case 'apply_segment':
						$segment_id = sanitize_key( (string) ( $input['saved_segment_id'] ?? '' ) );
						$loaded     = self::load_segment_filters( $segment_id );
						if ( ! empty( $loaded ) ) {
							$draft         = self::sanitize_draft( array_merge( $draft, $loaded ) );
							$notice_type   = 'success';
							$notice_message = __( 'Segmento aplicado al borrador actual.', 'atora-lms' );
							update_option( self::DRAFT_OPTION, $draft, false );
						} else {
							$notice_type    = 'error';
							$notice_message = __( 'No se encontró el segmento solicitado.', 'atora-lms' );
						}
						break;

					case 'delete_segment':
						$segment_id = sanitize_key( (string) ( $input['saved_segment_id'] ?? '' ) );
						$deleted    = self::delete_named_segment( $segment_id );
						$notice_type    = $deleted ? 'success' : 'error';
						$notice_message = $deleted
							? __( 'Segmento eliminado.', 'atora-lms' )
							: __( 'No se pudo eliminar el segmento.', 'atora-lms' );
						update_option( self::DRAFT_OPTION, $draft, false );
						break;

					case 'run_bulk_action':
						update_option( self::DRAFT_OPTION, $draft, false );
						$result = self::run_bulk_action( $draft );
						$notice_type    = ! empty( $result['success'] ) ? 'success' : 'error';
						$notice_message = (string) ( $result['message'] ?? __( 'No se pudo ejecutar la acción masiva.', 'atora-lms' ) );
						break;

					case 'launch_campaign':
						update_option( self::DRAFT_OPTION, $draft, false );
						$result = self::launch_campaign( $draft );
						$notice_type    = ! empty( $result['success'] ) ? 'success' : 'error';
						$notice_message = (string) ( $result['message'] ?? __( 'No se pudo registrar la campaña.', 'atora-lms' ) );
						break;

					default:
						update_option( self::DRAFT_OPTION, $draft, false );
						$notice_type    = 'info';
						$notice_message = __( 'Acción reconocida, sin cambios adicionales.', 'atora-lms' );
						break;
				}
			}
		}

		$segments         = self::get_segment_options();
		$channels         = self::get_channel_options();
		$delivery_modes   = self::get_delivery_options();
		$execution_modes  = self::get_execution_options();
		$statuses         = self::get_status_options();
		$courses          = self::get_course_options();
		$available_tags   = self::get_tag_options();
		$saved_segments   = self::get_saved_segments();
		$segment_result   = self::build_segment_result( $draft );
		$recent_campaigns = self::get_campaign_history();

		$view = __DIR__ . '/views/admin.php';

		if ( file_exists( $view ) ) {
			require $view;
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'CRM v2 (beta)', 'atora-lms' ) . '</h1><p>' . esc_html__( 'Vista no disponible.', 'atora-lms' ) . '</p></div>';
	}

	/**
	 * Lee el borrador actual normalizado.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_draft(): array {
		$stored = get_option( self::DRAFT_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return self::sanitize_draft( $stored );
	}

	/**
	 * Sanitiza el estado del borrador CRM v2.
	 *
	 * @param array $input Input.
	 * @return array<string,mixed>
	 */
	public static function sanitize_draft( array $input ): array {
		$defaults = self::get_default_draft();
		$data     = array_merge( $defaults, $input );

		$audience = sanitize_key( (string) $data['audience'] );
		if ( ! array_key_exists( $audience, self::get_segment_options() ) ) {
			$audience = 'todos';
		}
		$data['audience'] = $audience;

		$channel = sanitize_key( (string) $data['channel'] );
		if ( ! array_key_exists( $channel, self::get_channel_options() ) ) {
			$channel = 'email';
		}
		$data['channel'] = $channel;

		$delivery = sanitize_key( (string) $data['delivery'] );
		if ( ! array_key_exists( $delivery, self::get_delivery_options() ) ) {
			$delivery = 'now';
		}
		$data['delivery'] = $delivery;

		$execution_mode = sanitize_key( (string) $data['execution_mode'] );
		if ( ! array_key_exists( $execution_mode, self::get_execution_options() ) ) {
			$execution_mode = 'simulate';
		}
		$data['execution_mode'] = $execution_mode;

		$status_filter = sanitize_key( (string) $data['status_filter'] );
		if ( '' !== $status_filter && ! array_key_exists( $status_filter, self::get_status_options() ) ) {
			$status_filter = '';
		}
		$data['status_filter'] = $status_filter;

		$bulk_action = sanitize_key( (string) $data['bulk_action'] );
		if ( ! array_key_exists( $bulk_action, self::get_bulk_action_options() ) ) {
			$bulk_action = 'none';
		}
		$data['bulk_action'] = $bulk_action;

		$data['course_id']              = absint( $data['course_id'] );
		$data['segment_tags']           = self::sanitize_tags_csv( (string) $data['segment_tags'] );
		$data['search']                 = sanitize_text_field( (string) $data['search'] );
		$data['subject']                = sanitize_text_field( (string) $data['subject'] );
		$data['message']                = sanitize_textarea_field( (string) $data['message'] );
		$data['cta_url']                = esc_url_raw( (string) $data['cta_url'] );
		$data['scheduled_at']           = sanitize_text_field( (string) $data['scheduled_at'] );
		$data['campaign_name']          = sanitize_text_field( (string) $data['campaign_name'] );
		$data['segment_name']           = sanitize_text_field( (string) $data['segment_name'] );
		$data['saved_segment_id']       = sanitize_key( (string) $data['saved_segment_id'] );
		$data['bulk_value']             = sanitize_text_field( (string) $data['bulk_value'] );
		$data['respect_email_prefs']    = ! empty( $data['respect_email_prefs'] ) ? 1 : 0;
		$data['updated_at']             = current_time( 'mysql' );

		return $data;
	}

	/**
	 * Draft base del flujo.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_default_draft(): array {
		return array(
			'audience'             => 'todos',
			'status_filter'        => '',
			'course_id'            => 0,
			'segment_tags'         => '',
			'search'               => '',
			'channel'              => 'email',
			'subject'              => '',
			'message'              => '',
			'cta_url'              => '',
			'delivery'             => 'now',
			'execution_mode'       => 'simulate',
			'scheduled_at'         => '',
			'campaign_name'        => '',
			'segment_name'         => '',
			'saved_segment_id'     => '',
			'bulk_action'          => 'none',
			'bulk_value'           => '',
			'respect_email_prefs'  => 1,
			'updated_at'           => '',
		);
	}

	/**
	 * Segmentos base.
	 *
	 * @return array<string,string>
	 */
	protected static function get_segment_options(): array {
		return array(
			'todos'       => __( 'Todos los contactos', 'atora-lms' ),
			'leads'       => __( 'Leads y prospectos', 'atora-lms' ),
			'estudiantes' => __( 'Estudiantes activos', 'atora-lms' ),
			'inactivos'   => __( 'Riesgo / inactivos', 'atora-lms' ),
		);
	}

	/**
	 * Canales disponibles.
	 *
	 * @return array<string,string>
	 */
	protected static function get_channel_options(): array {
		return array(
			'email'    => __( 'Email', 'atora-lms' ),
			'whatsapp' => __( 'WhatsApp', 'atora-lms' ),
			'telegram' => __( 'Telegram', 'atora-lms' ),
			'hybrid'   => __( 'Híbrido (email + mensajería)', 'atora-lms' ),
		);
	}

	/**
	 * Modos de entrega.
	 *
	 * @return array<string,string>
	 */
	protected static function get_delivery_options(): array {
		return array(
			'now'      => __( 'Ahora', 'atora-lms' ),
			'schedule' => __( 'Programado', 'atora-lms' ),
		);
	}

	/**
	 * Modos de ejecución de campaña.
	 *
	 * @return array<string,string>
	 */
	protected static function get_execution_options(): array {
		return array(
			'simulate' => __( 'Simular (recomendado)', 'atora-lms' ),
			'queue'    => __( 'Encolar', 'atora-lms' ),
		);
	}

	/**
	 * Estados de contacto válidos.
	 *
	 * @return array<string,string>
	 */
	protected static function get_status_options(): array {
		return array(
			'lead'     => __( 'Lead', 'atora-lms' ),
			'prospect' => __( 'Prospecto', 'atora-lms' ),
			'student'  => __( 'Estudiante', 'atora-lms' ),
			'alumni'   => __( 'Egresado', 'atora-lms' ),
			'blocked'  => __( 'Archivado', 'atora-lms' ),
		);
	}

	/**
	 * Acciones masivas disponibles.
	 *
	 * @return array<string,string>
	 */
	protected static function get_bulk_action_options(): array {
		return array(
			'none'       => __( 'Sin acción masiva', 'atora-lms' ),
			'add_tag'    => __( 'Agregar etiqueta', 'atora-lms' ),
			'remove_tag' => __( 'Quitar etiqueta', 'atora-lms' ),
			'set_status' => __( 'Cambiar estado', 'atora-lms' ),
			'cleanup'    => __( 'Depurar contactos', 'atora-lms' ),
		);
	}

	/**
	 * Construye resultado de segmentación.
	 *
	 * @param array $draft Borrador.
	 * @return array<string,mixed>
	 */
	public static function build_segment_result( array $draft ): array {
		$contacts = self::query_contacts( $draft, self::MAX_CONTACTS_PREVIEW, 0 );
		$total    = self::count_contacts( $draft );

		$stats = array(
			'total'           => $total,
			'email_ready'     => 0,
			'messaging_ready' => 0,
			'linked_users'    => 0,
		);

		$stats_sample_limit = min( self::MAX_CONTACTS_ACTION, max( self::MAX_CONTACTS_PREVIEW, $total ) );
		$target_rows        = self::query_contacts( $draft, $stats_sample_limit, 0 );
		foreach ( $target_rows as $row ) {
			if ( ! empty( $row['user_id'] ) ) {
				$stats['linked_users']++;
			}
			if ( self::contact_can_receive_email( $row, ! empty( $draft['respect_email_prefs'] ) ) ) {
				$stats['email_ready']++;
			}
			if ( self::contact_can_receive_message( $row ) ) {
				$stats['messaging_ready']++;
			}
		}

		return array(
			'total'    => $total,
			'stats'    => $stats,
			'contacts' => $contacts,
		);
	}

	/**
	 * Ejecuta una acción masiva sobre el segmento activo.
	 *
	 * @param array $draft Configuración actual.
	 * @return array<string,mixed>
	 */
	public static function run_bulk_action( array $draft ): array {
		$action = sanitize_key( (string) ( $draft['bulk_action'] ?? 'none' ) );
		$value  = sanitize_text_field( (string) ( $draft['bulk_value'] ?? '' ) );

		if ( 'none' === $action ) {
			return array(
				'success' => false,
				'message' => __( 'Selecciona una acción masiva antes de ejecutar.', 'atora-lms' ),
			);
		}

		$contacts = self::query_contacts( $draft, self::MAX_CONTACTS_ACTION, 0 );
		if ( empty( $contacts ) ) {
			return array(
				'success' => false,
				'message' => __( 'No hay contactos para aplicar la acción seleccionada.', 'atora-lms' ),
			);
		}

		$done = 0;
		switch ( $action ) {
			case 'add_tag':
				$value = sanitize_text_field( $value );
				if ( '' === $value ) {
					return array(
						'success' => false,
						'message' => __( 'Indica una etiqueta válida para agregar.', 'atora-lms' ),
					);
				}
				foreach ( $contacts as $contact ) {
					if ( self::add_tag_to_contact( $contact, $value ) ) {
						$done++;
					}
				}
				return array(
					'success' => true,
					'message' => sprintf( __( 'Etiqueta aplicada en %d contacto(s).', 'atora-lms' ), $done ),
				);

			case 'remove_tag':
				$value = sanitize_text_field( $value );
				if ( '' === $value ) {
					return array(
						'success' => false,
						'message' => __( 'Indica una etiqueta válida para quitar.', 'atora-lms' ),
					);
				}
				foreach ( $contacts as $contact ) {
					if ( self::remove_tag_from_contact( $contact, $value ) ) {
						$done++;
					}
				}
				return array(
					'success' => true,
					'message' => sprintf( __( 'Etiqueta removida en %d contacto(s).', 'atora-lms' ), $done ),
				);

			case 'set_status':
				if ( ! array_key_exists( $value, self::get_status_options() ) ) {
					return array(
						'success' => false,
						'message' => __( 'Estado no válido para actualización masiva.', 'atora-lms' ),
					);
				}
				$done = self::bulk_set_status( $contacts, $value );
				return array(
					'success' => true,
					'message' => sprintf( __( 'Estado actualizado en %d contacto(s).', 'atora-lms' ), $done ),
				);

			case 'cleanup':
				$result = self::bulk_cleanup_contacts( $contacts );
				return array(
					'success' => true,
					'message' => sprintf(
						/* translators: 1: deleted anonymous contacts, 2: archived linked contacts */
						__( 'Depuración completada: %1$d eliminados sin usuario y %2$d archivados con cuenta activa.', 'atora-lms' ),
						absint( $result['deleted'] ?? 0 ),
						absint( $result['archived'] ?? 0 )
					),
				);
		}

		return array(
			'success' => false,
			'message' => __( 'Acción masiva no soportada.', 'atora-lms' ),
		);
	}

	/**
	 * Lanza una campaña en modo simulado o encolado.
	 *
	 * @param array $draft Configuración.
	 * @return array<string,mixed>
	 */
	public static function launch_campaign( array $draft ): array {
		$contacts = self::query_contacts( $draft, self::MAX_CONTACTS_ACTION, 0 );
		if ( empty( $contacts ) ) {
			return array(
				'success' => false,
				'message' => __( 'No hay contactos en el segmento seleccionado para lanzar campaña.', 'atora-lms' ),
			);
		}

		$channel = sanitize_key( (string) ( $draft['channel'] ?? 'email' ) );
		$subject = sanitize_text_field( (string) ( $draft['subject'] ?? '' ) );
		$message = sanitize_textarea_field( (string) ( $draft['message'] ?? '' ) );
		if ( '' === $message ) {
			return array(
				'success' => false,
				'message' => __( 'Escribe un mensaje antes de ejecutar la campaña.', 'atora-lms' ),
			);
		}

		$execution_mode = sanitize_key( (string) ( $draft['execution_mode'] ?? 'simulate' ) );
		if ( ! array_key_exists( $execution_mode, self::get_execution_options() ) ) {
			$execution_mode = 'simulate';
		}

		$scheduled_at_utc = self::resolve_scheduled_at( (string) ( $draft['delivery'] ?? 'now' ), (string) ( $draft['scheduled_at'] ?? '' ) );
		if ( 'schedule' === sanitize_key( (string) ( $draft['delivery'] ?? 'now' ) ) && '' === $scheduled_at_utc ) {
			return array(
				'success' => false,
				'message' => __( 'La fecha programada no es válida para ejecución diferida.', 'atora-lms' ),
			);
		}
		if ( '' === $scheduled_at_utc ) {
			$scheduled_at_utc = current_time( 'mysql', true );
		}

		$campaign_id   = 'crmv2_' . wp_generate_password( 10, false, false );
		$queued_email  = 0;
		$queued_msg    = 0;
		$skipped       = 0;
		$simulated     = 0;

		foreach ( $contacts as $contact ) {
			if ( 'simulate' === $execution_mode ) {
				$simulated++;
				continue;
			}

			if ( in_array( $channel, array( 'email', 'hybrid' ), true ) ) {
				if ( self::queue_email_for_contact( $contact, $draft, $campaign_id, $scheduled_at_utc ) ) {
					$queued_email++;
				} else {
					$skipped++;
				}
			}

			if ( in_array( $channel, array( 'whatsapp', 'telegram', 'hybrid' ), true ) ) {
				if ( self::queue_message_for_contact( $contact, $draft, $campaign_id, $scheduled_at_utc, $channel ) ) {
					$queued_msg++;
				} else {
					$skipped++;
				}
			}
		}

		$campaign = array(
			'id'              => $campaign_id,
			'name'            => sanitize_text_field( (string) ( $draft['campaign_name'] ?: $subject ?: __( 'Campaña CRM v2', 'atora-lms' ) ) ),
			'channel'         => $channel,
			'audience'        => sanitize_key( (string) ( $draft['audience'] ?? 'todos' ) ),
			'tags'            => (string) ( $draft['segment_tags'] ?? '' ),
			'status'          => 'simulate' === $execution_mode ? 'simulated' : 'queued',
			'execution_mode'  => $execution_mode,
			'delivery'        => sanitize_key( (string) ( $draft['delivery'] ?? 'now' ) ),
			'subject'         => $subject,
			'preview'         => wp_trim_words( $message, 20 ),
			'contacts_total'  => count( $contacts ),
			'queued_email'    => $queued_email,
			'queued_message'  => $queued_msg,
			'simulated'       => $simulated,
			'skipped'         => $skipped,
			'scheduled_at'    => $scheduled_at_utc,
			'created_at'      => current_time( 'mysql' ),
			'created_by'      => get_current_user_id(),
		);

		self::append_campaign_history( $campaign );

		$message_text = 'simulate' === $execution_mode
			? sprintf( __( 'Simulación lista: %d contacto(s) impactados sin envío real.', 'atora-lms' ), $simulated )
			: sprintf(
				/* translators: 1: queued emails, 2: queued messages, 3: skipped */
				__( 'Campaña encolada. Emails: %1$d · Mensajes: %2$d · Saltados: %3$d.', 'atora-lms' ),
				$queued_email,
				$queued_msg,
				$skipped
			);

		return array(
			'success' => true,
			'message' => $message_text,
		);
	}

	/**
	 * Ejecuta actualización masiva de estado.
	 *
	 * @param array  $contacts Contactos.
	 * @param string $status   Estado destino.
	 * @return int
	 */
	private static function bulk_set_status( array $contacts, string $status ): int {
		global $wpdb;

		$status = sanitize_key( $status );
		if ( '' === $status ) {
			return 0;
		}

		$done = 0;
		foreach ( $contacts as $contact ) {
			$contact_id = absint( $contact['id'] ?? 0 );
			if ( ! $contact_id ) {
				continue;
			}

			$updated = $wpdb->update(
				"{$wpdb->prefix}atora_contacts",
				array(
					'status'     => $status,
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $contact_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			if ( false !== $updated ) {
				$done++;
				$user_id = absint( $contact['user_id'] ?? 0 );
				if ( $user_id && class_exists( '\ATORA\\CRM\\CRM' ) && method_exists( '\ATORA\\CRM\\CRM', 'log_activity' ) ) {
					\ATORA\CRM\CRM::log_activity(
						$user_id,
						'crm_v2_status_updated',
						array(
							'status' => $status,
						)
					);
				}
			}
		}

		return $done;
	}

	/**
	 * Depura contactos del segmento.
	 *
	 * - Contactos sin usuario: eliminación completa.
	 * - Contactos con usuario: archivado (status blocked).
	 *
	 * @param array $contacts Contactos.
	 * @return array<string,int>
	 */
	private static function bulk_cleanup_contacts( array $contacts ): array {
		global $wpdb;

		$deleted  = 0;
		$archived = 0;

		foreach ( $contacts as $contact ) {
			$contact_id = absint( $contact['id'] ?? 0 );
			$user_id    = absint( $contact['user_id'] ?? 0 );
			if ( ! $contact_id ) {
				continue;
			}

			if ( $user_id > 0 ) {
				$updated = $wpdb->update(
					"{$wpdb->prefix}atora_contacts",
					array(
						'status'     => 'blocked',
						'updated_at' => current_time( 'mysql', true ),
					),
					array( 'id' => $contact_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
				if ( false !== $updated ) {
					$archived++;
					if ( class_exists( '\ATORA\\CRM\\CRM' ) && method_exists( '\ATORA\\CRM\\CRM', 'log_activity' ) ) {
						\ATORA\CRM\CRM::log_activity(
							$user_id,
							'crm_v2_contact_archived',
							array( 'contact_id' => $contact_id )
						);
					}
				}
				continue;
			}

			$wpdb->delete( "{$wpdb->prefix}atora_contact_tags", array( 'contact_id' => $contact_id ), array( '%d' ) );
			$wpdb->delete( "{$wpdb->prefix}atora_contact_notes", array( 'contact_id' => $contact_id ), array( '%d' ) );
			$wpdb->delete( "{$wpdb->prefix}atora_contact_activities", array( 'contact_id' => $contact_id ), array( '%d' ) );
			$removed = $wpdb->delete( "{$wpdb->prefix}atora_contacts", array( 'id' => $contact_id ), array( '%d' ) );
			if ( $removed ) {
				$deleted++;
			}
		}

		return array(
			'deleted'  => $deleted,
			'archived' => $archived,
		);
	}

	/**
	 * Agrega etiqueta a contacto.
	 *
	 * @param array  $contact Contacto.
	 * @param string $tag     Etiqueta.
	 * @return bool
	 */
	private static function add_tag_to_contact( array $contact, string $tag ): bool {
		global $wpdb;

		$tag = sanitize_text_field( $tag );
		if ( '' === $tag ) {
			return false;
		}

		$user_id = absint( $contact['user_id'] ?? 0 );
		if ( $user_id && class_exists( '\ATORA\\CRM\\CRM' ) && method_exists( '\ATORA\\CRM\\CRM', 'add_tag' ) ) {
			\ATORA\CRM\CRM::add_tag( $user_id, $tag );
			return true;
		}

		$contact_id = absint( $contact['id'] ?? 0 );
		if ( ! $contact_id ) {
			return false;
		}

		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}atora_contact_tags WHERE contact_id = %d AND tag_name = %s LIMIT 1",
				$contact_id,
				$tag
			)
		);

		if ( $existing ) {
			return true;
		}

		$inserted = $wpdb->insert(
			"{$wpdb->prefix}atora_contact_tags",
			array(
				'contact_id' => $contact_id,
				'tag_name'   => $tag,
			),
			array( '%d', '%s' )
		);

		return (bool) $inserted;
	}

	/**
	 * Remueve etiqueta de contacto.
	 *
	 * @param array  $contact Contacto.
	 * @param string $tag     Etiqueta.
	 * @return bool
	 */
	private static function remove_tag_from_contact( array $contact, string $tag ): bool {
		global $wpdb;

		$tag = sanitize_text_field( $tag );
		if ( '' === $tag ) {
			return false;
		}

		$user_id = absint( $contact['user_id'] ?? 0 );
		if ( $user_id && class_exists( '\ATORA\\CRM\\CRM' ) && method_exists( '\ATORA\\CRM\\CRM', 'remove_tag' ) ) {
			\ATORA\CRM\CRM::remove_tag( $user_id, $tag );
			return true;
		}

		$contact_id = absint( $contact['id'] ?? 0 );
		if ( ! $contact_id ) {
			return false;
		}

		$removed = $wpdb->delete(
			"{$wpdb->prefix}atora_contact_tags",
			array(
				'contact_id' => $contact_id,
				'tag_name'   => $tag,
			),
			array( '%d', '%s' )
		);

		return false !== $removed;
	}

	/**
	 * Encola email directo en atora_email_queue.
	 *
	 * @param array  $contact      Contacto.
	 * @param array  $draft        Configuración campaña.
	 * @param string $campaign_id  ID campaña.
	 * @param string $scheduled_at Fecha UTC.
	 * @return bool
	 */
	private static function queue_email_for_contact( array $contact, array $draft, string $campaign_id, string $scheduled_at ): bool {
		global $wpdb;

		if ( ! self::contact_can_receive_email( $contact, ! empty( $draft['respect_email_prefs'] ) ) ) {
			return false;
		}

		$email = sanitize_email( (string) ( $contact['email'] ?? '' ) );
		if ( '' === $email ) {
			return false;
		}

		$subject = sanitize_text_field( (string) ( $draft['subject'] ?? '' ) );
		if ( '' === $subject ) {
			$subject = __( 'Nueva actualización de ATORA', 'atora-lms' );
		}

		$name      = sanitize_text_field( (string) ( $contact['name'] ?? '' ) );
		$message   = sanitize_textarea_field( (string) ( $draft['message'] ?? '' ) );
		$cta_url   = esc_url_raw( (string) ( $draft['cta_url'] ?? '' ) );
		$body_html = self::build_campaign_email_html( $subject, $message, $name, $cta_url );
		$body_text = self::build_campaign_email_text( $subject, $message, $cta_url );

		$provider = class_exists( '\ATORA\\EmailEngine\\Email_Queue' ) && method_exists( '\ATORA\\EmailEngine\\Email_Queue', 'get_active_provider' )
			? sanitize_key( (string) \ATORA\EmailEngine\Email_Queue::get_active_provider() )
			: 'smtp';
		$identity_key = self::resolve_campaign_email_identity();

		$user_id = absint( $contact['user_id'] ?? 0 );

		$metadata = array(
			'source'          => 'crm_v2_campaign',
			'campaign_id'     => $campaign_id,
			'channel'         => 'email',
			'email_identity'  => $identity_key,
			'identity'        => $identity_key,
			'identity_key'    => $identity_key,
			'segment_audience'=> sanitize_key( (string) ( $draft['audience'] ?? 'todos' ) ),
			'segment_tags'    => (string) ( $draft['segment_tags'] ?? '' ),
		);

		$insert_data = array(
			'recipient_email' => $email,
			'recipient_name'  => $name,
			'user_id'         => $user_id,
			'template_id'     => 0,
			'subject'         => $subject,
			'body_html'       => $body_html,
			'body_text'       => $body_text,
			'provider'        => $provider ?: 'smtp',
			'status'          => 'pending',
			'scheduled_at'    => $scheduled_at,
			'priority'        => 5,
			'metadata'        => wp_json_encode( $metadata ),
		);
		$formats = array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

		if ( self::email_queue_has_identity_key_column() ) {
			$insert_data['identity_key'] = $identity_key;
			$formats[]                   = '%s';
		}

		$inserted = $wpdb->insert( "{$wpdb->prefix}atora_email_queue", $insert_data, $formats );
		if ( ! $inserted ) {
			return false;
		}

		$queue_id = (int) $wpdb->insert_id;
		if ( class_exists( '\ATORA\\CRM\\CRM' ) && method_exists( '\ATORA\\CRM\\CRM', 'sync_email_queue_to_conversation' ) ) {
			\ATORA\CRM\CRM::sync_email_queue_to_conversation( $queue_id );
		}

		return true;
	}

	/**
	 * Encola mensaje para WhatsApp/Telegram usando router actual.
	 *
	 * @param array  $contact      Contacto.
	 * @param array  $draft        Configuración.
	 * @param string $campaign_id  ID campaña.
	 * @param string $scheduled_at Fecha UTC.
	 * @param string $channel      Canal solicitado.
	 * @return bool
	 */
	private static function queue_message_for_contact( array $contact, array $draft, string $campaign_id, string $scheduled_at, string $channel ): bool {
		if ( ! class_exists( '\ATORA\\Messaging\\Messaging_Router' ) || ! method_exists( '\ATORA\\Messaging\\Messaging_Router', 'enqueue' ) ) {
			return false;
		}

		$user_id = absint( $contact['user_id'] ?? 0 );
		if ( ! $user_id ) {
			return false;
		}

		$message_text = sanitize_textarea_field( (string) ( $draft['message'] ?? '' ) );
		$subject      = sanitize_text_field( (string) ( $draft['subject'] ?? '' ) );
		$cta_url      = esc_url_raw( (string) ( $draft['cta_url'] ?? '' ) );
		$final_channel = 'hybrid' === $channel ? self::resolve_hybrid_channel_for_contact( $contact ) : $channel;
		if ( '' === $final_channel ) {
			return false;
		}

		$result = \ATORA\Messaging\Messaging_Router::enqueue(
			array(
				'user_id'   => $user_id,
				'channel'   => $final_channel,
				'type'      => 'marketing',
				'template'  => 'marketing',
				'variables' => array(
					'message'       => $message_text,
					'subject'       => $subject,
					'cta_url'       => $cta_url,
					'source'        => 'crm_v2_campaign',
					'campaign_id'   => $campaign_id,
					'email_identity'=> self::resolve_campaign_email_identity(),
				),
				'options'   => array(
					'allow_duplicate'      => true,
					'scheduled_at'         => $scheduled_at,
					'priority'             => 'low',
					'enforce_preferences'  => true,
					'dedupe_window_minutes'=> 0,
				),
			)
		);

		return false !== $result;
	}

	/**
	 * Resuelve identidad para envíos de campaña CRM v2 legacy.
	 *
	 * @return string
	 */
	private static function resolve_campaign_email_identity(): string {
		if ( current_user_can( 'clms_manage_commerce' ) || current_user_can( 'clms_view_teacher_dashboard' ) || current_user_can( 'clms_grade_submissions' ) ) {
			return 'teacher';
		}

		if ( current_user_can( 'manage_options' ) ) {
			return 'admin';
		}

		return 'teacher';
	}

	/**
	 * Determina canal de mensajería para modo híbrido.
	 *
	 * @param array $contact Contacto.
	 * @return string
	 */
	private static function resolve_hybrid_channel_for_contact( array $contact ): string {
		if ( self::contact_can_receive_whatsapp( $contact ) ) {
			return 'whatsapp';
		}

		if ( self::contact_can_receive_telegram( $contact ) ) {
			return 'telegram';
		}

		return '';
	}

	/**
	 * Contacto apto para email.
	 *
	 * @param array $contact Contacto.
	 * @param bool  $respect_pref Respetar preferencias email.
	 * @return bool
	 */
	private static function contact_can_receive_email( array $contact, bool $respect_pref = true ): bool {
		$email = sanitize_email( (string) ( $contact['email'] ?? '' ) );
		if ( '' === $email ) {
			return false;
		}

		if ( ! $respect_pref ) {
			return true;
		}

		$user_id = absint( $contact['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			return true;
		}

		if ( class_exists( '\ATORA\\EmailEngine\\Email_Engine' ) && method_exists( '\ATORA\\EmailEngine\\Email_Engine', 'user_accepts_emails' ) ) {
			return (bool) \ATORA\EmailEngine\Email_Engine::user_accepts_emails( $user_id, 'marketing_newsletter' );
		}

		return true;
	}

	/**
	 * Contacto apto para mensajería.
	 *
	 * @param array $contact Contacto.
	 * @return bool
	 */
	private static function contact_can_receive_message( array $contact ): bool {
		return self::contact_can_receive_whatsapp( $contact ) || self::contact_can_receive_telegram( $contact );
	}

	/**
	 * Contacto apto para WhatsApp.
	 *
	 * @param array $contact Contacto.
	 * @return bool
	 */
	private static function contact_can_receive_whatsapp( array $contact ): bool {
		$whatsapp = sanitize_text_field( (string) ( $contact['whatsapp'] ?? '' ) );
		$phone    = sanitize_text_field( (string) ( $contact['phone'] ?? '' ) );
		$phone    = '' !== $whatsapp ? $whatsapp : $phone;
		if ( '' === $phone ) {
			return false;
		}

		$user_id = absint( $contact['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			return false;
		}

		return (bool) get_user_meta( $user_id, 'atora_consent_whatsapp', true );
	}

	/**
	 * Contacto apto para Telegram.
	 *
	 * @param array $contact Contacto.
	 * @return bool
	 */
	private static function contact_can_receive_telegram( array $contact ): bool {
		$user_id = absint( $contact['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			return false;
		}

		$chat_id = sanitize_text_field( (string) get_user_meta( $user_id, 'atora_telegram_chat_id', true ) );
		if ( '' === $chat_id ) {
			return false;
		}

		return (bool) get_user_meta( $user_id, 'atora_consent_telegram', true );
	}

	/**
	 * Obtiene los cursos disponibles para filtro.
	 *
	 * @return array<int,string>
	 */
	protected static function get_course_options(): array {
		$courses = get_posts(
			array(
				'post_type'              => 'lm_course',
				'post_status'            => array( 'publish', 'private', 'draft' ),
				'posts_per_page'         => 200,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$options = array();
		foreach ( (array) $courses as $course_id ) {
			$course_id = absint( $course_id );
			if ( ! $course_id ) {
				continue;
			}
			$options[ $course_id ] = get_the_title( $course_id );
		}

		return $options;
	}

	/**
	 * Obtiene lista de etiquetas existentes.
	 *
	 * @return array<int,string>
	 */
	protected static function get_tag_options(): array {
		global $wpdb;

		$tags_table = "{$wpdb->prefix}atora_contact_tags";
		if ( ! self::table_exists( $tags_table ) ) {
			return array();
		}

		$rows = (array) $wpdb->get_col( "SELECT DISTINCT tag_name FROM {$tags_table} WHERE tag_name <> '' ORDER BY tag_name ASC LIMIT 200" );
		$rows = array_values( array_filter( array_map( 'sanitize_text_field', $rows ) ) );

		return $rows;
	}

	/**
	 * Consulta contactos por filtro.
	 *
	 * @param array $draft  Filtro.
	 * @param int   $limit  Límite.
	 * @param int   $offset Offset.
	 * @return array<int,array<string,mixed>>
	 */
	protected static function query_contacts( array $draft, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$contacts_table = "{$wpdb->prefix}atora_contacts";
		if ( ! self::table_exists( $contacts_table ) ) {
			return array();
		}

		$limit  = max( 1, min( self::MAX_CONTACTS_ACTION, absint( $limit ) ) );
		$offset = max( 0, absint( $offset ) );

		$where_data = self::build_contacts_where_clause( $draft );
		$where      = (string) ( $where_data['sql'] ?? '1=1' );
		$params     = (array) ( $where_data['params'] ?? array() );

		$sql = "SELECT c.id, c.user_id, c.email, c.name, c.phone, c.whatsapp, c.source, c.status, c.updated_at
			FROM {$contacts_table} c
			WHERE {$where}
			ORDER BY c.updated_at DESC
			LIMIT %d OFFSET %d";

		$params[] = $limit;
		$params[] = $offset;

		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( empty( $rows ) ) {
			return array();
		}

		$contact_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'id' ) ) ) );
		$tags_map    = self::get_tags_map_for_contact_ids( $contact_ids );

		foreach ( $rows as &$row ) {
			$contact_id = absint( $row['id'] ?? 0 );
			$row['id']       = $contact_id;
			$row['user_id']  = absint( $row['user_id'] ?? 0 );
			$row['tags']     = $tags_map[ $contact_id ] ?? array();
			$row['channels'] = array(
				'email'    => self::contact_can_receive_email( $row, true ),
				'whatsapp' => self::contact_can_receive_whatsapp( $row ),
				'telegram' => self::contact_can_receive_telegram( $row ),
			);
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Cuenta contactos según filtro.
	 *
	 * @param array $draft Filtro.
	 * @return int
	 */
	protected static function count_contacts( array $draft ): int {
		global $wpdb;

		$contacts_table = "{$wpdb->prefix}atora_contacts";
		if ( ! self::table_exists( $contacts_table ) ) {
			return 0;
		}

		$where_data = self::build_contacts_where_clause( $draft );
		$where      = (string) ( $where_data['sql'] ?? '1=1' );
		$params     = (array) ( $where_data['params'] ?? array() );

		$sql = "SELECT COUNT(*) FROM {$contacts_table} c WHERE {$where}";
		if ( empty( $params ) ) {
			return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Construye WHERE SQL según filtros CRM v2.
	 *
	 * @param array $draft Borrador.
	 * @return array{sql:string,params:array<int,mixed>}
	 */
	private static function build_contacts_where_clause( array $draft ): array {
		global $wpdb;

		$clauses = array( '1=1' );
		$params  = array();

		$audience = sanitize_key( (string) ( $draft['audience'] ?? 'todos' ) );
		switch ( $audience ) {
			case 'leads':
				$clauses[] = "c.status IN ('lead','prospect')";
				break;
			case 'estudiantes':
				$clauses[] = "c.status = 'student'";
				break;
			case 'inactivos':
				$clauses[] = "(c.status = 'alumni' OR EXISTS (SELECT 1 FROM {$wpdb->prefix}atora_contact_tags t1 WHERE t1.contact_id = c.id AND t1.tag_name = '#inactive-30d'))";
				break;
		}

		$status_filter = sanitize_key( (string) ( $draft['status_filter'] ?? '' ) );
		if ( '' !== $status_filter && array_key_exists( $status_filter, self::get_status_options() ) ) {
			$clauses[] = 'c.status = %s';
			$params[]  = $status_filter;
		}

		$search = sanitize_text_field( (string) ( $draft['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = '(c.name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s OR c.whatsapp LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
		}

		$tags = self::parse_tags_csv( (string) ( $draft['segment_tags'] ?? '' ) );
		if ( ! empty( $tags ) ) {
			$tag_placeholders = implode( ',', array_fill( 0, count( $tags ), '%s' ) );
			$clauses[]        = "EXISTS (
				SELECT 1 FROM {$wpdb->prefix}atora_contact_tags t2
				WHERE t2.contact_id = c.id
				  AND t2.tag_name IN ({$tag_placeholders})
			)";
			foreach ( $tags as $tag ) {
				$params[] = $tag;
			}
		}

		$course_id = absint( $draft['course_id'] ?? 0 );
		if ( $course_id > 0 ) {
			$user_ids = self::get_course_user_ids_for_filter( $course_id );
			if ( empty( $user_ids ) ) {
				$clauses[] = '1=0';
			} else {
				$in = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
				$clauses[] = "c.user_id IN ({$in})";
				foreach ( $user_ids as $user_id ) {
					$params[] = absint( $user_id );
				}
			}
		}

		$scope_user_ids = self::get_scope_user_ids();
		if ( ! empty( $scope_user_ids ) ) {
			$in = implode( ',', array_fill( 0, count( $scope_user_ids ), '%d' ) );
			$clauses[] = "c.user_id IN ({$in})";
			foreach ( $scope_user_ids as $scope_user_id ) {
				$params[] = absint( $scope_user_id );
			}
		}

		return array(
			'sql'    => implode( ' AND ', $clauses ),
			'params' => $params,
		);
	}

	/**
	 * IDs de usuarios visibles por scope (vacío => sin restricción).
	 *
	 * @return array<int,int>
	 */
	private static function get_scope_user_ids(): array {
		$current_user_id = get_current_user_id();
		if ( ! $current_user_id ) {
			return array( -1 );
		}

		if ( class_exists( '\ATORA\\CRM\\CRM' ) && method_exists( '\ATORA\\CRM\\CRM', 'can_manage_crm' ) && \ATORA\CRM\CRM::can_manage_crm( $current_user_id ) ) {
			return array();
		}

		if ( class_exists( '\ATORA\\CRM\\CRM' ) && method_exists( '\ATORA\\CRM\\CRM', 'get_accessible_contact_user_ids' ) ) {
			return array_values(
				array_filter(
					array_map( 'absint', (array) \ATORA\CRM\CRM::get_accessible_contact_user_ids( $current_user_id ) )
				)
			);
		}

		return array();
	}

	/**
	 * Usuarios inscritos en curso para filtro CRM.
	 *
	 * @param int $course_id Curso.
	 * @return array<int,int>
	 */
	private static function get_course_user_ids_for_filter( int $course_id ): array {
		if ( ! class_exists( '\CLMS_Helper' ) || ! method_exists( '\CLMS_Helper', 'get_enrolled_student_ids' ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'absint', (array) \CLMS_Helper::get_enrolled_student_ids( $course_id ) )
			)
		);
	}

	/**
	 * Retorna mapa contact_id => tags.
	 *
	 * @param array<int,int> $contact_ids IDs.
	 * @return array<int,array<int,string>>
	 */
	private static function get_tags_map_for_contact_ids( array $contact_ids ): array {
		global $wpdb;

		$contact_ids = array_values( array_filter( array_map( 'absint', $contact_ids ) ) );
		if ( empty( $contact_ids ) ) {
			return array();
		}

		$in  = implode( ',', array_fill( 0, count( $contact_ids ), '%d' ) );
		$sql = "SELECT contact_id, tag_name FROM {$wpdb->prefix}atora_contact_tags WHERE contact_id IN ({$in}) ORDER BY tag_name ASC";
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$contact_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$map = array();
		foreach ( $rows as $row ) {
			$contact_id = absint( $row['contact_id'] ?? 0 );
			$tag_name   = sanitize_text_field( (string) ( $row['tag_name'] ?? '' ) );
			if ( ! $contact_id || '' === $tag_name ) {
				continue;
			}
			if ( ! isset( $map[ $contact_id ] ) ) {
				$map[ $contact_id ] = array();
			}
			$map[ $contact_id ][] = $tag_name;
		}

		return $map;
	}

	/**
	 * Guarda un segmento reutilizable.
	 *
	 * @param array  $draft Draft.
	 * @param string $name  Nombre.
	 * @return bool
	 */
	public static function save_named_segment( array $draft, string $name ): bool {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return false;
		}

		$segments = self::get_saved_segments();
		$base_id  = sanitize_title( $name );
		if ( '' === $base_id ) {
			$base_id = 'segmento';
		}

		$segment_id = $base_id;
		$counter    = 2;
		while ( isset( $segments[ $segment_id ] ) ) {
			$segment_id = $base_id . '-' . $counter;
			$counter++;
		}

		$segments[ $segment_id ] = array(
			'id'         => $segment_id,
			'name'       => $name,
			'filters'    => self::extract_segment_filters( $draft ),
			'created_at' => current_time( 'mysql' ),
		);

		update_option( self::SEGMENTS_OPTION, $segments, false );
		return true;
	}

	/**
	 * Carga filtros de segmento guardado.
	 *
	 * @param string $segment_id ID.
	 * @return array<string,mixed>
	 */
	public static function load_segment_filters( string $segment_id ): array {
		$segment_id = sanitize_key( $segment_id );
		$segments   = self::get_saved_segments();
		if ( '' === $segment_id || empty( $segments[ $segment_id ]['filters'] ) ) {
			return array();
		}

		$filters = (array) $segments[ $segment_id ]['filters'];
		return self::sanitize_draft( array_merge( self::get_default_draft(), $filters ) );
	}

	/**
	 * Elimina segmento guardado.
	 *
	 * @param string $segment_id Segmento.
	 * @return bool
	 */
	public static function delete_named_segment( string $segment_id ): bool {
		$segment_id = sanitize_key( $segment_id );
		if ( '' === $segment_id ) {
			return false;
		}

		$segments = self::get_saved_segments();
		if ( ! isset( $segments[ $segment_id ] ) ) {
			return false;
		}

		unset( $segments[ $segment_id ] );
		update_option( self::SEGMENTS_OPTION, $segments, false );
		return true;
	}

	/**
	 * Segmentos guardados.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_saved_segments(): array {
		$stored = get_option( self::SEGMENTS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$segments = array();
		foreach ( $stored as $segment_id => $payload ) {
			$segment_id = sanitize_key( (string) $segment_id );
			$payload    = is_array( $payload ) ? $payload : array();
			if ( '' === $segment_id ) {
				continue;
			}

			$segments[ $segment_id ] = array(
				'id'         => $segment_id,
				'name'       => sanitize_text_field( (string) ( $payload['name'] ?? $segment_id ) ),
				'filters'    => self::extract_segment_filters( (array) ( $payload['filters'] ?? array() ) ),
				'created_at' => sanitize_text_field( (string) ( $payload['created_at'] ?? '' ) ),
			);
		}

		return $segments;
	}

	/**
	 * Retorna solo campos de filtros segmentables.
	 *
	 * @param array $data Datos.
	 * @return array<string,mixed>
	 */
	private static function extract_segment_filters( array $data ): array {
		$allowed = array(
			'audience',
			'status_filter',
			'course_id',
			'segment_tags',
			'search',
			'channel',
			'respect_email_prefs',
		);

		$filters = array();
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$filters[ $field ] = $data[ $field ];
			}
		}

		return self::sanitize_draft( array_merge( self::get_default_draft(), $filters ) );
	}

	/**
	 * Historial de campañas.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_campaign_history(): array {
		$stored = get_option( self::CAMPAIGNS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$history = array();
		foreach ( $stored as $campaign ) {
			if ( ! is_array( $campaign ) ) {
				continue;
			}

			$history[] = array(
				'id'             => sanitize_text_field( (string) ( $campaign['id'] ?? '' ) ),
				'name'           => sanitize_text_field( (string) ( $campaign['name'] ?? '' ) ),
				'channel'        => sanitize_key( (string) ( $campaign['channel'] ?? 'email' ) ),
				'audience'       => sanitize_key( (string) ( $campaign['audience'] ?? 'todos' ) ),
				'status'         => sanitize_key( (string) ( $campaign['status'] ?? 'simulated' ) ),
				'execution_mode' => sanitize_key( (string) ( $campaign['execution_mode'] ?? 'simulate' ) ),
				'delivery'       => sanitize_key( (string) ( $campaign['delivery'] ?? 'now' ) ),
				'subject'        => sanitize_text_field( (string) ( $campaign['subject'] ?? '' ) ),
				'preview'        => sanitize_text_field( (string) ( $campaign['preview'] ?? '' ) ),
				'contacts_total' => absint( $campaign['contacts_total'] ?? 0 ),
				'queued_email'   => absint( $campaign['queued_email'] ?? 0 ),
				'queued_message' => absint( $campaign['queued_message'] ?? 0 ),
				'simulated'      => absint( $campaign['simulated'] ?? 0 ),
				'skipped'        => absint( $campaign['skipped'] ?? 0 ),
				'created_at'     => sanitize_text_field( (string) ( $campaign['created_at'] ?? '' ) ),
				'scheduled_at'   => sanitize_text_field( (string) ( $campaign['scheduled_at'] ?? '' ) ),
			);
		}

		return array_slice( $history, 0, self::MAX_CAMPAIGNS_STORED );
	}

	/**
	 * Guarda campaña en historial.
	 *
	 * @param array $campaign Campaña.
	 * @return void
	 */
	private static function append_campaign_history( array $campaign ): void {
		$history = self::get_campaign_history();
		array_unshift( $history, $campaign );
		$history = array_slice( $history, 0, self::MAX_CAMPAIGNS_STORED );
		update_option( self::CAMPAIGNS_OPTION, $history, false );
	}

	/**
	 * Sanitiza tags CSV.
	 *
	 * @param string $raw Texto.
	 * @return string
	 */
	private static function sanitize_tags_csv( string $raw ): string {
		$tags = self::parse_tags_csv( $raw );
		return implode( ', ', $tags );
	}

	/**
	 * Parsea tags separadas por coma, punto y coma o salto.
	 *
	 * @param string $raw Texto.
	 * @return array<int,string>
	 */
	private static function parse_tags_csv( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$chunks = preg_split( '/[,;\n\r]+/', $raw ) ?: array();
		$tags   = array();
		foreach ( $chunks as $chunk ) {
			$tag = sanitize_text_field( trim( (string) $chunk ) );
			if ( '' !== $tag ) {
				$tags[] = $tag;
			}
		}

		$tags = array_values( array_unique( $tags ) );
		return array_slice( $tags, 0, 30 );
	}

	/**
	 * Determina fecha UTC de programación.
	 *
	 * @param string $delivery    now|schedule.
	 * @param string $scheduled_at datetime-local.
	 * @return string
	 */
	private static function resolve_scheduled_at( string $delivery, string $scheduled_at ): string {
		$delivery = sanitize_key( $delivery );
		if ( 'schedule' !== $delivery ) {
			return current_time( 'mysql', true );
		}

		$scheduled_at = sanitize_text_field( $scheduled_at );
		if ( '' === $scheduled_at ) {
			return '';
		}

		$timezone = wp_timezone();
		$dt       = \DateTime::createFromFormat( 'Y-m-d\\TH:i', $scheduled_at, $timezone );
		if ( ! $dt ) {
			return '';
		}

		$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Verifica tabla existente.
	 *
	 * @param string $table Tabla.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		$like = $wpdb->esc_like( $table );
		$val  = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		return $val === $table;
	}

	/**
	 * Verifica columna identity_key en cola email.
	 *
	 * @return bool
	 */
	private static function email_queue_has_identity_key_column(): bool {
		if ( null !== self::$has_email_queue_identity_column ) {
			return self::$has_email_queue_identity_column;
		}

		global $wpdb;
		$table = "{$wpdb->prefix}atora_email_queue";
		if ( ! self::table_exists( $table ) ) {
			self::$has_email_queue_identity_column = false;
			return false;
		}

		$column = (string) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'identity_key' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::$has_email_queue_identity_column = ( 'identity_key' === $column );
		return self::$has_email_queue_identity_column;
	}

	/**
	 * Genera HTML simple y legible para campañas CRM v2.
	 *
	 * @param string $subject Asunto.
	 * @param string $message Mensaje.
	 * @param string $name    Nombre.
	 * @param string $cta_url URL CTA.
	 * @return string
	 */
	private static function build_campaign_email_html( string $subject, string $message, string $name = '', string $cta_url = '' ): string {
		$subject = esc_html( $subject );
		$name    = esc_html( $name );
		$cta_url = esc_url( $cta_url );

		$paragraphs_raw = preg_split( '/\R+/', trim( $message ) ) ?: array();
		$paragraphs     = '';
		foreach ( $paragraphs_raw as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$paragraphs .= '<p style="margin:0 0 14px;color:#334155;line-height:1.6;font-size:15px;">' . esc_html( $line ) . '</p>';
		}

		$cta_html = '';
		if ( '' !== $cta_url ) {
			$cta_html = '<p style="margin:20px 0 0;">'
				. '<a href="' . $cta_url . '" style="display:inline-block;background:#0ea5e9;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:700;">'
				. esc_html__( 'Ver detalle', 'atora-lms' )
				. '</a></p>';
		}

		$greeting = '' !== $name
			? '<p style="margin:0 0 14px;color:#0f172a;line-height:1.6;font-size:16px;">' . sprintf( esc_html__( 'Hola %s,', 'atora-lms' ), $name ) . '</p>'
			: '';

		$html = '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
			. '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;"><tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border:1px solid #dbeafe;border-radius:14px;overflow:hidden;">'
			. '<tr><td style="padding:20px 24px;background:linear-gradient(135deg,#0f4fa8 0%,#1d4ed8 60%,#0ea5e9 100%);color:#ffffff;">'
			. '<h1 style="margin:0;font-size:22px;line-height:1.3;">' . $subject . '</h1>'
			. '</td></tr>'
			. '<tr><td style="padding:24px;">'
			. $greeting
			. $paragraphs
			. $cta_html
			. '<p style="margin:22px 0 0;color:#64748b;font-size:12px;line-height:1.5;">'
			. esc_html__( 'Mensaje enviado desde CRM Hub de ATORA LMS.', 'atora-lms' )
			. '</p>'
			. '</td></tr></table></td></tr></table></body></html>';

		return $html;
	}

	/**
	 * Texto plano del email.
	 *
	 * @param string $subject Asunto.
	 * @param string $message Mensaje.
	 * @param string $cta_url URL.
	 * @return string
	 */
	private static function build_campaign_email_text( string $subject, string $message, string $cta_url = '' ): string {
		$text = trim( $subject ) . "\n\n" . trim( $message );
		if ( '' !== $cta_url ) {
			$text .= "\n\n" . __( 'Ver detalle:', 'atora-lms' ) . ' ' . esc_url_raw( $cta_url );
		}
		return $text;
	}
}
