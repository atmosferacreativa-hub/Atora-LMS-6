<?php
/**
 * ATORA LMS v5 — Motor de Automatizaciones
 *
 * Ejecuta automatizaciones basadas en triggers, condiciones y acciones
 * en secuencia con delays. Procesamiento asíncrono (no bloquea requests).
 * Incluye workflows pre-construidos: Onboarding, Engagement, Re-engagement, Ventas.
 *
 * @package ATORA_LMS\Automation
 * @since   5.0.0
 */

namespace ATORA\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Automation_Engine
 *
 * @since 5.0.0
 */
class Automation_Engine {

	/**
	 * Triggers soportados.
	 */
	const TRIGGERS = array(
		// Existentes
		'user_registered', 'course_enrolled', 'lesson_completed', 'course_completed',
		'grade_published', 'purchase_completed', 'email_opened', 'email_clicked',
		'inactivity_detected', 'tag_added', 'tag_removed', 'form_submitted', 'webinar_registered',
		// Fase 7 — nuevos triggers académico-comerciales
		'deal_stage_changed',
		'contact_created',
		'campaign_opened',
		'grade_below_threshold',
		'inactivity_academic',
		'enrollment_completed',
		// Fase 10
		'cart_abandoned',    // Carrito abandonado en WooCommerce
		'ltv_updated',       // LTV del contacto actualizado por compra
		// Fase 11 — LMS propio
		'lesson_completed_v2',  // Lección completada en tabla propia
		'course_completed_v2',  // Curso completado en tabla propia
		'enrollment_v2',        // Matrícula en tabla propia
		// Fase III S9 — Gamificación
		'badge_earned',         // Badge desbloqueado por el estudiante
		// Fase III S10 — Learning Paths
		'learning_path_updated', // Ruta de aprendizaje regenerada
	);

	/**
	 * Tipos de acción soportados.
	 */
	const ACTION_TYPES = array(
		// Existentes
		'send_email', 'send_whatsapp', 'send_telegram', 'add_tag', 'remove_tag',
		'internal_notification', 'webhook', 'enroll_course', 'unenroll_course',
		// Fase 7 — nuevas acciones
		'start_email_sequence',
		'move_pipeline_stage',
	);

	/**
	 * Inicializa el motor de automatizaciones.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! self::has_required_tables() ) {
			return;
		}

		// Registrar intervalo antes de programar cron para evitar dependencia externa.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_intervals' ) );

		// Registrar listeners para todos los triggers.
		self::register_trigger_hooks();

		// Cron: procesar acciones pendientes (cada 5 min).
		add_action( 'atora_automation_cron', array( __CLASS__, 'process_pending_actions' ) );
		// Inactividad académica — verificación diaria
		add_action( 'atora_daily_cron', array( __CLASS__, 'on_check_academic_inactivity' ) );
		if ( ! wp_next_scheduled( 'atora_automation_cron' ) ) {
			wp_schedule_event( time(), 'every_5_minutes', 'atora_automation_cron' );
		}

		// REST API.
		// P2 (6.12.0): gateado por módulo 'automation'.
		if ( ! class_exists( '\CLMS_Module_Registry' ) || \CLMS_Module_Registry::is_active( 'automation' ) ) {
			add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		}

		// Webhooks salientes.
		if ( class_exists( 'ATORA\Automation\Outbound_Webhooks' ) ) {
			Outbound_Webhooks::init();
		}

		// Admin.
		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu',           array( __CLASS__, 'register_admin_menu' ) );
			add_action( 'wp_ajax_atora_automation_save',  array( __CLASS__, 'ajax_save' ) );
			add_action( 'wp_ajax_atora_automation_test',  array( __CLASS__, 'ajax_test_run' ) );
			add_action( 'wp_ajax_atora_automation_toggle',array( __CLASS__, 'ajax_toggle' ) );
			add_action( 'wp_ajax_atora_automation_import',array( __CLASS__, 'ajax_import_preset' ) );
		add_action( 'wp_ajax_atora_automation_retry_failed', array( __CLASS__, 'ajax_retry_failed' ) );
		}
	}

	/**
	 * Añade intervalos de cron usados por el motor.
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

	// ── Registro de triggers ──────────────────────────────────────────────────

	/**
	 * Conecta cada trigger con el handler correspondiente.
	 *
	 * @return void
	 */
	private static function register_trigger_hooks(): void {
		$map = array(
			'user_register'                   => 'user_registered',
			'clms_user_enrolled_in_course'    => 'course_enrolled',
			'clms_lesson_completed'           => 'lesson_completed',
			'clms_course_completed'           => 'course_completed',
			'clms_submission_graded'          => 'grade_published',
			'woocommerce_order_status_completed' => 'purchase_completed',
			'atora/email/webhook_event'       => 'email_event_proxy',
				'atora_daily_cron'                => 'check_inactivity',
				'atora/crm/tag_added'             => 'tag_added',
				'atora/crm/tag_removed'           => 'tag_removed',
				'atora/forms/submitted'           => 'form_submitted',
			// Fase 7 — nuevos hooks
			'atora/crm/deal_stage_changed'    => 'deal_stage_changed',
			'atora/crm/contact_created'       => 'contact_created',
			// Fase 10
			'atora/cart/abandoned'            => 'cart_abandoned',
			'atora/contact/ltv_updated'       => 'ltv_updated',
			// Fase 11 — LMS propio
			'atora/lms/lesson_completed'        => 'lesson_completed_v2',
			'atora/lms/course_completed'        => 'course_completed_v2',
			'atora/lms/enrolled'                => 'enrollment_v2',
			// Fase III S9 — Gamificación
			'atora/gamification/badge_earned'   => 'badge_earned',
			// Fase III S10 — Learning Paths
			'atora/lms/learning_path_updated'   => 'learning_path_updated',
			'atora/email/opened'              => 'campaign_opened',
			'clms_submission_graded'          => 'grade_below_threshold_check',
			'clms_lesson_completed'           => 'inactivity_academic_check',
			'clms_user_enrolled_in_course'    => 'enrollment_completed',
			);

		foreach ( $map as $wp_hook => $handler ) {
			$method = 'on_' . $handler;
			if ( method_exists( __CLASS__, $method ) ) {
				add_action( $wp_hook, array( __CLASS__, $method ), 20, PHP_INT_MAX );
			}
		}
	}

	/**
	 * Verifica tablas mínimas del motor de automatizaciones.
	 *
	 * @return bool
	 */
	private static function has_required_tables(): bool {
		global $wpdb;

		$required = array(
			"{$wpdb->prefix}atora_automations",
			"{$wpdb->prefix}atora_automation_queue",
		);

		foreach ( $required as $table ) {
			$like = $wpdb->esc_like( $table );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
			if ( $exists !== $table ) {
				return false;
			}
		}

		return true;
	}

	// ── Handlers de triggers ──────────────────────────────────────────────────

	/** @param int $user_id */
	public static function on_user_registered( int $user_id ): void {
		self::fire_trigger( 'user_registered', $user_id, array() );
	}

	/** @param int $user_id @param int $course_id */
	public static function on_course_enrolled( int $user_id, int $course_id ): void {
		self::fire_trigger( 'course_enrolled', $user_id, array( 'course_id' => $course_id ) );
	}

	/** @param int $user_id @param int $lesson_id */
	public static function on_lesson_completed( int $user_id, int $lesson_id ): void {
		self::fire_trigger( 'lesson_completed', $user_id, array( 'lesson_id' => $lesson_id ) );
	}

	/** @param int $user_id @param int $course_id */
	public static function on_course_completed( int $user_id, int $course_id ): void {
		self::fire_trigger( 'course_completed', $user_id, array( 'course_id' => $course_id ) );
	}

	/**
	 * Trigger al publicarse una calificación de entrega.
	 * Firma esperada desde clms_submission_graded:
	 * ($submission_id, $student_id, $status, $grade, $feedback)
	 *
	 * @param mixed $submission_id ID de entrega.
	 * @param mixed $student_id    ID de estudiante.
	 * @param mixed $status        Estado de la entrega.
	 * @param mixed $grade         Nota.
	 * @param mixed $feedback      Feedback.
	 * @return void
	 */
	public static function on_grade_published( $submission_id, $student_id = 0, $status = '', $grade = '', $feedback = '' ): void {
		unset( $feedback );

		$submission_id = absint( $submission_id );
		$student_id    = absint( $student_id );
		$status        = sanitize_key( (string) $status );

		if ( ! $student_id && $submission_id ) {
			$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		}
		if ( ! $student_id ) {
			return;
		}

		if ( 'graded' !== $status ) {
			return;
		}

		if ( '' === (string) $grade && $submission_id ) {
			$grade = get_post_meta( $submission_id, '_clms_submission_grade', true );
		}
		if ( '' === (string) $grade || ! is_numeric( $grade ) ) {
			return;
		}

		$lesson_id = $submission_id ? absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) ) : 0;
		$course_id = $submission_id ? absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) ) : 0;

		self::fire_trigger(
			'grade_published',
			$student_id,
			array(
				'submission_id' => $submission_id,
				'lesson_id'     => $lesson_id,
				'course_id'     => $course_id,
				'grade'         => (float) $grade,
				'status'        => $status,
			)
		);
	}

	/** @param int $order_id */
	public static function on_purchase_completed( int $order_id ): void {
		$order   = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$user_id = $order ? absint( $order->get_customer_id() ) : 0;
		if ( $user_id ) {
			self::fire_trigger( 'purchase_completed', $user_id, array( 'order_id' => $order_id ) );
		}
	}

	/**
	 * Trigger desde CRM cuando se añade un tag a un contacto.
	 *
	 * @param int    $user_id    Usuario vinculado al contacto.
	 * @param string $tag_name   Tag agregado.
	 * @param int    $contact_id Contacto CRM.
	 * @return void
	 */
	public static function on_tag_added( int $user_id, string $tag_name = '', int $contact_id = 0 ): void {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}

		self::fire_trigger(
			'tag_added',
			$user_id,
			array(
				'tag'        => sanitize_text_field( $tag_name ),
				'contact_id' => absint( $contact_id ),
				)
			);
	}

	/**
	 * Trigger desde CRM cuando se elimina un tag de un contacto.
	 *
	 * @param int    $user_id    Usuario vinculado al contacto.
	 * @param string $tag_name   Tag eliminado.
	 * @param int    $contact_id Contacto CRM.
	 * @return void
	 */
	public static function on_tag_removed( int $user_id, string $tag_name = '', int $contact_id = 0 ): void {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}

		self::fire_trigger(
			'tag_removed',
			$user_id,
			array(
				'tag'        => sanitize_text_field( $tag_name ),
				'contact_id' => absint( $contact_id ),
			)
		);
	}

	/** @param string $event_type @param int $queue_id @param array $event */
	public static function on_email_event_proxy( string $event_type, int $queue_id, array $event ): void {
		global $wpdb;

		if ( ! in_array( $event_type, array( 'opened', 'clicked' ), true ) ) { return; }

		$user_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->prefix}atora_email_queue WHERE id = %d LIMIT 1",
			$queue_id
		) );

		if ( $user_id ) {
			self::fire_trigger( 'email_' . $event_type, $user_id, array( 'queue_id' => $queue_id ) );
		}
	}

	/** Revisa inactividad diariamente. */
	public static function on_check_inactivity(): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

		$inactive = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT u.ID FROM {$wpdb->users} u
			 LEFT JOIN {$wpdb->prefix}atora_email_queue q ON q.user_id = u.ID
			 WHERE (q.opened_at IS NULL OR q.opened_at < %s)
			   AND u.user_registered < %s
			 LIMIT 100",
			$cutoff, $cutoff
		) );

		foreach ( $inactive as $user_id ) {
			self::fire_trigger( 'inactivity_detected', (int) $user_id, array( 'days_inactive' => 7 ) );
		}
	}

	/**
	 * Trigger de formularios (fuente Analytics/Forms Builder).
	 *
	 * @param int   $form_id  Formulario.
	 * @param int   $entry_id Entrada.
	 * @param array $data     Payload del envío.
	 * @return void
	 */
	public static function on_form_submitted( int $form_id, int $entry_id, array $data = array() ): void {
		$data    = is_array( $data ) ? $data : array();
		$user_id = absint( $data['user_id'] ?? 0 );
		$email   = sanitize_email( (string) ( $data['email'] ?? $data['user_email'] ?? '' ) );
		$phone   = sanitize_text_field( (string) ( $data['phone'] ?? $data['telefono'] ?? $data['whatsapp'] ?? '' ) );
		$name    = sanitize_text_field( (string) ( $data['name'] ?? $data['full_name'] ?? '' ) );

		$consent_marketing = self::to_bool( $data['consent_marketing'] ?? $data['marketing_consent'] ?? false );
		$consent_whatsapp  = self::to_bool( $data['consent_whatsapp'] ?? $data['whatsapp_consent'] ?? false );
		$consent_telegram  = self::to_bool( $data['consent_telegram'] ?? $data['telegram_consent'] ?? false );

		if ( ! $user_id && '' !== $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$user_id = absint( $user->ID );
			}
		}
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		$contact_id = self::capture_form_contact(
			$user_id,
			array(
				'email'             => $email,
				'phone'             => $phone,
				'name'              => $name,
				'form_id'           => $form_id,
				'entry_id'          => $entry_id,
				'consent_marketing' => $consent_marketing,
				'consent_whatsapp'  => $consent_whatsapp,
				'consent_telegram'  => $consent_telegram,
			)
		);

		$trigger_context = array(
			'form_id'            => $form_id,
			'entry_id'           => $entry_id,
			'contact_id'         => $contact_id,
			'lead_email'         => $email,
			'lead_phone'         => $phone,
			'consent_marketing'  => $consent_marketing ? 1 : 0,
			'consent_whatsapp'   => $consent_whatsapp ? 1 : 0,
			'consent_telegram'   => $consent_telegram ? 1 : 0,
			'is_anonymous_lead'  => $user_id <= 0 ? 1 : 0,
		);

		if ( $user_id > 0 ) {
			self::fire_trigger( 'form_submitted', $user_id, $trigger_context );
			return;
		}

		/**
		 * Hook para integraciones que consumen leads anónimos sin usuario WP.
		 */
		do_action( 'atora/automation/form_submitted_anonymous', $contact_id, $trigger_context );
	}

	// ── Handlers Fase 7 ───────────────────────────────────────────────────────

	public static function on_deal_stage_changed( int $deal_id, string $new_stage = '', int $contact_id = 0, int $user_id = 0 ): void {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( ! $user_id && ! $contact_id ) {
			return;
		}
		$effective_user = $user_id ?: 1;
		self::fire_trigger( 'deal_stage_changed', $effective_user, array(
			'deal_id'    => absint( $deal_id ),
			'stage'      => sanitize_key( $new_stage ),
			'contact_id' => absint( $contact_id ),
		) );
	}

	public static function on_contact_created( int $contact_id, int $user_id = 0 ): void {
		$effective_user = $user_id > 0 ? $user_id : 1;
		self::fire_trigger( 'contact_created', $effective_user, array(
			'contact_id' => absint( $contact_id ),
		) );
	}

	public static function on_campaign_opened( string $tracking_id ): void {
		if ( '' === $tracking_id ) {
			return;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT r.user_id, r.contact_id, r.campaign_id
				 FROM {$wpdb->prefix}atora_crm_campaign_recipients r
				 WHERE r.tracking_id = %s LIMIT 1",
				$tracking_id
			),
			ARRAY_A
		);
		if ( empty( $row ) ) {
			return;
		}
		$user_id = absint( $row['user_id'] ?? 0 );
		if ( ! $user_id ) {
			return;
		}
		self::fire_trigger( 'campaign_opened', $user_id, array(
			'tracking_id' => $tracking_id,
			'campaign_id' => absint( $row['campaign_id'] ?? 0 ),
			'contact_id'  => absint( $row['contact_id']  ?? 0 ),
		) );
	}

	public static function on_grade_below_threshold_check( $submission_id, $student_id = 0, $status = '', $grade = '' ): void {
		$student_id = absint( $student_id );
		$status     = sanitize_key( (string) $status );
		$grade      = (float) $grade;

		if ( ! $student_id || 'graded' !== $status ) {
			return;
		}

		$submission_id = absint( $submission_id );
		if ( '' === (string) $grade && $submission_id ) {
			$grade = (float) get_post_meta( $submission_id, '_clms_submission_grade', true );
		}

		if ( $grade <= 0 && $grade !== 0.0 ) {
			return;
		}

		$lesson_id = $submission_id ? absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) ) : 0;
		$course_id = $submission_id ? absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) ) : 0;

		self::fire_trigger( 'grade_below_threshold', $student_id, array(
			'submission_id' => $submission_id,
			'lesson_id'     => $lesson_id,
			'course_id'     => $course_id,
			'grade'         => $grade,
		) );
	}

	public static function on_inactivity_academic_check( int $user_id, int $lesson_id ): void {
		// Handler de mapa — la inactividad académica real se verifica vía cron diario.
	}

	public static function on_check_academic_inactivity(): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

		$inactive = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT sf.user_id, sf.course_id, sf.progress_percent
				 FROM {$wpdb->prefix}atora_crm_student_followups sf
				 WHERE sf.status NOT IN ('completed','dropped')
				   AND (sf.updated_at < %s OR sf.updated_at IS NULL)
				   AND sf.progress_percent < 100
				 LIMIT 100",
				$cutoff
			),
			ARRAY_A
		);

		foreach ( $inactive as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			if ( ! $user_id ) { continue; }
			self::fire_trigger( 'inactivity_academic', $user_id, array(
				'course_id'        => absint( $row['course_id'] ?? 0 ),
				'progress_percent' => absint( $row['progress_percent'] ?? 0 ),
				'days_inactive'    => 7,
			) );
		}
	}

	public static function on_enrollment_completed( int $user_id, int $course_id ): void {
		$user       = get_userdata( $user_id );
		$contact_id = 0;
		if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'get_contact_id_by_user' ) ) {
			$contact_id = absint( \ATORA\CRM\CRM::get_contact_id_by_user( $user_id ) );
		}
		self::fire_trigger( 'enrollment_completed', $user_id, array(
			'course_id'    => absint( $course_id ),
			'contact_id'   => $contact_id,
			'user_email'   => $user ? sanitize_email( $user->user_email ) : '',
			'display_name' => $user ? sanitize_text_field( $user->display_name ) : '',
		) );
	}

	// ── Handlers Fase 10 ─────────────────────────────────────────────────────

	/**
	 * Trigger: carrito abandonado (WooCommerce).
	 *
	 * @param int   $cart_id    ID en atora_abandoned_carts.
	 * @param int   $contact_id ID del contacto.
	 * @param int   $user_id    ID del usuario WordPress.
	 * @param float $total      Total del carrito.
	 */
	public static function on_cart_abandoned( int $cart_id, int $contact_id = 0, int $user_id = 0, float $total = 0.0 ): void {
		$effective_user = $user_id > 0 ? $user_id : 1;
		self::fire_trigger( 'cart_abandoned', $effective_user, array(
			'cart_id'    => $cart_id,
			'contact_id' => $contact_id,
			'total'      => $total,
		) );
	}

	/**
	 * Trigger: LTV del contacto actualizado tras una compra.
	 *
	 * @param int   $contact_id ID del contacto.
	 * @param float $amount     Monto de la compra.
	 * @param int   $order_id   ID de la orden WooCommerce.
	 */
	public static function on_ltv_updated( int $contact_id, float $amount = 0.0, int $order_id = 0 ): void {
		global $wpdb;
		$user_id        = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}atora_contacts WHERE id = %d LIMIT 1", $contact_id )
		);
		$effective_user = $user_id > 0 ? $user_id : 1;
		self::fire_trigger( 'ltv_updated', $effective_user, array(
			'contact_id' => $contact_id,
			'amount'     => $amount,
			'order_id'   => $order_id,
		) );
	}

	// ── Motor de ejecución ────────────────────────────────────────────────────

	/**
	 * Dispara las automatizaciones para un trigger dado.
	 *
	 * @param string $trigger Nombre del trigger.
	 * @param int    $user_id ID del usuario.
	 * @param array  $context Datos contextuales del trigger.
	 * @return void
	 */
	public static function fire_trigger( string $trigger, int $user_id, array $context = array() ): void {
		global $wpdb;

		$automations = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_automations
			 WHERE trigger_type = %s AND active = 1
			 ORDER BY priority ASC",
			$trigger
		) );

		foreach ( $automations as $automation ) {
			if ( ! self::evaluate_conditions( $automation, $user_id, $context ) ) {
				continue;
			}

			self::enqueue_actions( (int) $automation->id, $user_id, $context );
		}
	}

	/**
	 * Evalúa las condiciones de una automatización para un usuario.
	 *
	 * @param object $automation Fila de la automatización.
	 * @param int    $user_id    ID del usuario.
	 * @param array  $context    Contexto del trigger.
	 * @return bool
	 */
	private static function evaluate_conditions( object $automation, int $user_id, array $context ): bool {
		$conditions = self::decode_json_array( (string) ( $automation->conditions ?? '{}' ), array() );
		$rules      = isset( $conditions['rules'] ) && is_array( $conditions['rules'] )
			? $conditions['rules']
			: array();

		if ( empty( $rules ) ) {
			return true;
		}

		$operator = strtoupper( sanitize_key( (string) ( $conditions['operator'] ?? 'AND' ) ) );
		$results  = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$field    = sanitize_key( $rule['field'] ?? '' );
			$op       = sanitize_key( $rule['operator'] ?? 'equals' );
			$value    = $rule['value'] ?? '';
			$actual   = $context[ $field ] ?? get_user_meta( $user_id, 'atora_' . $field, true );

			switch ( $op ) {
				case 'equals':
					$results[] = (string) $actual === (string) $value;
					break;
				case 'not_equals':
					$results[] = (string) $actual !== (string) $value;
					break;
				case 'greater_than':
					$results[] = (float) $actual > (float) $value;
					break;
				case 'less_than':
					$results[] = (float) $actual < (float) $value;
					break;
				case 'contains':
					$results[] = str_contains( (string) $actual, (string) $value );
					break;
				default:
					$results[] = true;
			}
		}

		if ( empty( $results ) ) { return true; }

		return 'OR' === $operator ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	/**
	 * Encola las acciones de una automatización para ejecución con delays.
	 *
	 * @param int   $automation_id ID de la automatización.
	 * @param int   $user_id       ID del usuario.
	 * @param array $context       Contexto del trigger.
	 * @return void
	 */
	private static function enqueue_actions( int $automation_id, int $user_id, array $context ): void {
		global $wpdb;

		$automation = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_automations WHERE id = %d LIMIT 1",
			$automation_id
		) );

		if ( ! $automation ) { return; }

		$actions = self::normalize_action_list( self::decode_json_array( (string) ( $automation->actions ?? '[]' ), array() ) );
		$now     = time();
		if ( empty( $actions ) ) {
			return;
		}

		foreach ( $actions as $index => $action ) {
			$type = sanitize_key( (string) ( $action['type'] ?? '' ) );
			if ( '' === $type || ! in_array( $type, self::ACTION_TYPES, true ) ) {
				continue;
			}

			$action['type'] = $type;
			$delay_minutes = absint( $action['delay_minutes'] ?? 0 );
			$execute_at    = gmdate( 'Y-m-d H:i:s', $now + $delay_minutes * MINUTE_IN_SECONDS );

			$wpdb->insert(
				"{$wpdb->prefix}atora_automation_queue",
				array(
					'automation_id' => $automation_id,
					'user_id'       => $user_id,
					'action_index'  => $index,
					'action_data'   => wp_json_encode( $action ),
					'context'       => wp_json_encode( $context ),
					'execute_at'    => $execute_at,
					'status'        => 'pending',
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Procesa las acciones pendientes (invocado cada 5 min por cron).
	 *
	 * @return void
	 */
	public static function process_pending_actions(): void {
		// Cron lock: evitar ejecuciones concurrentes (Fase 8)
		$lock_key = 'atora_auto_cron_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 4 * MINUTE_IN_SECONDS );
		
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_automation_queue
			 WHERE status = 'pending'
			   AND execute_at <= %s
			   AND retry_count < 3
			 ORDER BY execute_at ASC
			 LIMIT 50",
			current_time( 'mysql', true )
		) );

		foreach ( $rows as $row ) {
			self::execute_action( $row );
		}

		delete_transient( $lock_key ); // liberar lock
	}

	/**
	 * Ejecuta una acción de la cola.
	 *
	 * @param object $row Fila de la cola.
	 * @return void
	 */
	private static function execute_action( object $row ): void {
		global $wpdb;

		$wpdb->update(
			"{$wpdb->prefix}atora_automation_queue",
			array( 'status' => 'running' ),
			array( 'id' => $row->id, 'status' => 'pending' ),
			array( '%s' ), array( '%d', '%s' )
		);

		$action  = self::decode_json_array( (string) ( $row->action_data ?? '{}' ), array() );
		$context = self::decode_json_array( (string) ( $row->context ?? '{}' ), array() );
		$user_id = (int) $row->user_id;
		$type    = sanitize_key( $action['type'] ?? '' );
		$success = false;

			switch ( $type ) {
				case 'send_email':
					$email_metadata = $context;
					$email_metadata['email_identity'] = self::resolve_email_identity( $action );
					if ( empty( $email_metadata['source'] ) ) {
						$email_metadata['source'] = 'automation';
					}

					$success = (bool) \ATORA\EmailEngine\Email_Queue::enqueue( array(
						'template' => sanitize_key( $action['template'] ?? '' ),
						'user_id'  => $user_id,
						'priority' => 'medium',
						'metadata' => $email_metadata,
						'source'   => 'automation',
					) );
					break;

				case 'send_whatsapp':
					$success = self::enqueue_channel_message( $user_id, 'whatsapp', $action, $context );
					break;

				case 'send_telegram':
					$success = self::enqueue_channel_message( $user_id, 'telegram', $action, $context );
					break;

			case 'add_tag':
				if ( class_exists( 'ATORA\CRM\CRM' ) ) {
					\ATORA\CRM\CRM::add_tag( $user_id, sanitize_text_field( $action['tag'] ?? '' ) );
					$success = true;
				}
				break;

			case 'remove_tag':
				if ( class_exists( 'ATORA\CRM\CRM' ) ) {
					\ATORA\CRM\CRM::remove_tag( $user_id, sanitize_text_field( $action['tag'] ?? '' ) );
					$success = true;
				}
				break;

			case 'webhook':
				if ( class_exists( 'ATORA\Automation\Outbound_Webhooks' ) ) {
					$success = Outbound_Webhooks::dispatch(
						esc_url_raw( $action['url'] ?? '' ),
						sanitize_key( $action['method'] ?? 'POST' ),
						self::interpolate_variables( $action['payload'] ?? '{}', $user_id, $context )
					);
				}
				break;

			case 'internal_notification':
				$message = self::interpolate_string( $action['message'] ?? '', $user_id, $context );
				do_action( 'atora/notification/add', $user_id, array(
					'type'    => 'automation',
					'message' => sanitize_text_field( $message ),
				) );
				$success = true;
				break;

			case 'enroll_course':
				if ( class_exists( 'CLMS_Helper' ) ) {
					// PT-1 (6.5.10): CLMS_Helper es global; este archivo vive
					// bajo namespace ATORA\Automation — sin backslash se
					// resuelve a ATORA\Automation\CLMS_Helper (inexistente).
					$success = (bool) \CLMS_Helper::enroll_user_in_course( $user_id, absint( $action['course_id'] ?? 0 ) );
				}
				break;

			case 'start_email_sequence':
				$sequence_id = absint( $action['sequence_id'] ?? 0 );
				$contact_id  = absint( $context['contact_id'] ?? 0 );
				if ( ! $contact_id && $user_id ) {
					if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'get_contact_id_by_user' ) ) {
						$contact_id = absint( \ATORA\CRM\CRM::get_contact_id_by_user( $user_id ) );
					}
				}
				if ( $sequence_id > 0 && $contact_id > 0 ) {
					$seq_file = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'crm-v2/services/class-sequence-service.php' : '';
					if ( $seq_file && file_exists( $seq_file ) && ! class_exists( '\ATORA\CRM_V2\Services\Sequence_Service' ) ) {
						require_once $seq_file;
					}
					if ( class_exists( '\ATORA\CRM_V2\Services\Sequence_Service' ) ) {
						$enrollment_id = \ATORA\CRM_V2\Services\Sequence_Service::enroll_contact( $sequence_id, $contact_id );
						$success       = (bool) $enrollment_id;
					}
				}
				break;

			case 'move_pipeline_stage':
				$pipeline     = sanitize_key( (string) ( $action['pipeline'] ?? 'sales' ) );
				$target_stage = sanitize_key( (string) ( $action['stage']    ?? '' ) );
				$contact_id   = absint( $context['contact_id'] ?? 0 );
				if ( ! $contact_id && $user_id ) {
					if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'get_contact_id_by_user' ) ) {
						$contact_id = absint( \ATORA\CRM\CRM::get_contact_id_by_user( $user_id ) );
					}
				}
				if ( $contact_id && $target_stage ) {
					if ( 'sales' === $pipeline ) {
						$success = (bool) $wpdb->update(
							"{$wpdb->prefix}atora_crm_deals",
							array( 'stage' => $target_stage ),
							array( 'contact_id' => $contact_id ),
							array( '%s' ), array( '%d' )
						);
					} elseif ( 'academic' === $pipeline ) {
						$success = (bool) $wpdb->update(
							"{$wpdb->prefix}atora_crm_student_followups",
							array( 'stage' => $target_stage ),
							array( 'contact_id' => $contact_id ),
							array( '%s' ), array( '%d' )
						);
					}
				}
				break;

			default:
				$success = (bool) apply_filters( 'atora/automation/execute_action', false, $type, $action, $user_id, $context );
				break;
		}

		$status = $success ? 'completed' : 'failed';
		$retry  = $success ? (int) $row->retry_count : (int) $row->retry_count + 1;

		if ( ! $success && $retry < 3 ) {
			$status  = 'pending';
			// Exponential backoff.
			$backoff  = (int) pow( 5, $retry );
			$execute_at = gmdate( 'Y-m-d H:i:s', time() + $backoff * MINUTE_IN_SECONDS );
			$wpdb->update(
				"{$wpdb->prefix}atora_automation_queue",
				array( 'status' => $status, 'retry_count' => $retry, 'execute_at' => $execute_at ),
				array( 'id' => $row->id ),
				null, array( '%d' )
			);
			return;
		}

		$wpdb->update(
			"{$wpdb->prefix}atora_automation_queue",
			array(
				'status'       => $status,
				'retry_count'  => $retry,
				'executed_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $row->id ),
			null, array( '%d' )
		);

		do_action( 'atora/automation/action_executed', (int) $row->id, $type, $success, $user_id );

		// ── Fase 7: Log de ejecución ──────────────────────────────────────
		self::write_execution_log( $row, $type, $success, $user_id, $context );
	}

	private static function write_execution_log( object $row, string $type, bool $success, int $user_id, array $context ): void {
		global $wpdb;

		$log_table = "{$wpdb->prefix}atora_automation_execution_log";
		$like      = $wpdb->esc_like( $log_table );
		$exists    = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		if ( $exists !== $log_table ) {
			return;
		}

		$automation_id = absint( $row->automation_id ?? 0 );
		$contact_id    = absint( $context['contact_id'] ?? 0 );

		if ( ! $contact_id && $user_id > 0 ) {
			if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'get_contact_id_by_user' ) ) {
				$contact_id = absint( \ATORA\CRM\CRM::get_contact_id_by_user( $user_id ) );
			}
		}

		$trigger_type = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT trigger_type FROM {$wpdb->prefix}atora_automations WHERE id = %d LIMIT 1",
				$automation_id
			)
		);

		$wpdb->insert(
			$log_table,
			array(
				'automation_id' => $automation_id,
				'queue_id'      => absint( $row->id ?? 0 ),
				'contact_id'    => $contact_id,
				'user_id'       => $user_id,
				'trigger_type'  => sanitize_key( $trigger_type ),
				'action_type'   => sanitize_key( $type ),
				'action_index'  => absint( $row->action_index ?? 0 ),
				'status'        => $success ? 'success' : 'failed',
				'error_message' => $success ? null : __( 'Acción fallida — ver retry_count en automation_queue.', 'atora-lms' ),
				'context_json'  => wp_json_encode( $context ),
				'executed_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	// ── Workflows pre-construidos ─────────────────────────────────────────────

	/**
	 * Devuelve la biblioteca de workflows pre-construidos.
	 *
	 * @return array<string, array>
	 */
	public static function get_preset_workflows(): array {
		return array(
			'onboarding' => array(
				'name'         => __( 'Onboarding — Bienvenida al curso', 'atora-lms' ),
				'description'  => __( 'Secuencia de bienvenida para nuevos estudiantes.', 'atora-lms' ),
				'trigger_type' => 'course_enrolled',
				'conditions'   => array(),
				'actions'      => array(
					array( 'type' => 'send_email',    'template' => 'welcome_course',       'delay_minutes' => 0 ),
					array( 'type' => 'send_email',    'template' => 'inactivity_reminder',  'delay_minutes' => 1440 ),
					array( 'type' => 'send_email',    'template' => 'inactivity_reminder',  'delay_minutes' => 4320 ),
					array( 'type' => 'add_tag',       'tag'      => 'onboarding-complete',  'delay_minutes' => 10080 ),
				),
			),

			'engagement' => array(
				'name'         => __( 'Engagement — Completar lección', 'atora-lms' ),
				'description'  => __( 'Motiva al estudiante tras completar su primera lección.', 'atora-lms' ),
				'trigger_type' => 'lesson_completed',
				'conditions'   => array(),
				'actions'      => array(
					array( 'type' => 'send_email',    'template' => 'new_lesson_unlocked',  'delay_minutes' => 0 ),
					array( 'type' => 'send_email',    'template' => 'inactivity_reminder',  'delay_minutes' => 2880 ),
					array( 'type' => 'add_tag',       'tag'      => 'estudiante-activo',    'delay_minutes' => 0 ),
				),
			),

			're_engagement' => array(
				'name'         => __( 'Re-engagement — Inactividad 7 días', 'atora-lms' ),
				'description'  => __( 'Recupera estudiantes que llevan 7 días sin actividad.', 'atora-lms' ),
				'trigger_type' => 'inactivity_detected',
				'conditions'   => array(),
				'actions'      => array(
					array( 'type' => 'send_email',    'template' => 'inactivity_reminder',  'delay_minutes' => 0 ),
					array( 'type' => 'send_whatsapp', 'template' => 'inactivity_reminder',  'delay_minutes' => 2880 ),
					array( 'type' => 'send_email',    'template' => 'special_offer',        'delay_minutes' => 7200 ),
					array( 'type' => 'add_tag',       'tag'      => '#potential-churn',     'delay_minutes' => 14400 ),
				),
			),

			'cart_recovery' => array(
				'name'         => __( 'Recuperación de carrito abandonado', 'atora-lms' ),
				'description'  => __( 'Secuencia para recuperar carritos abandonados en WooCommerce.', 'atora-lms' ),
				'trigger_type' => 'cart_abandoned', // Fase V S17: corregido de 'form_submitted'
				'conditions'   => array(),
				'actions'      => array(
					array( 'type' => 'send_email', 'template' => 'cart_recovery',  'delay_minutes' => 60    ),
					array( 'type' => 'send_email', 'template' => 'cart_recovery',  'delay_minutes' => 1440  ),
					array( 'type' => 'send_email', 'template' => 'special_offer',  'delay_minutes' => 4320  ),
				),
			),

			// ── Fase 7 — presets nuevos ───────────────────────────────────────
			'alumni_journey' => array(
				'name'         => __( 'Egresado — Journey post-certificación', 'atora-lms' ),
				'description'  => __( 'Automatización para egresados: tag, felicitación y oferta de curso avanzado.', 'atora-lms' ),
				'trigger_type' => 'course_completed',
				'conditions'   => array(),
				'actions'      => array(
					array( 'type' => 'add_tag',             'tag'      => '#egresado',          'delay_minutes' => 0 ),
					array( 'type' => 'send_email',          'template' => 'certificate_issued', 'delay_minutes' => 0 ),
					array( 'type' => 'move_pipeline_stage', 'pipeline' => 'academic', 'stage' => 'alumni', 'delay_minutes' => 0 ),
					array( 'type' => 'send_email',          'template' => 'special_offer',      'delay_minutes' => 4320 ),
				),
			),

			'grade_recovery' => array(
				'name'         => __( 'Recuperación académica — nota baja', 'atora-lms' ),
				'description'  => __( 'Detecta notas bajas y asigna tutoría + notificación al instructor.', 'atora-lms' ),
				'trigger_type' => 'grade_below_threshold',
				'conditions'   => array(
					'operator' => 'AND',
					'rules'    => array(
						array( 'field' => 'grade', 'operator' => 'less_than', 'value' => '60' ),
					),
				),
				'actions'      => array(
					array( 'type' => 'add_tag',               'tag'     => '#nota-baja',          'delay_minutes' => 0 ),
					array( 'type' => 'internal_notification', 'message' => 'Estudiante {{user_name}} obtuvo nota {{grade}} en el curso.', 'delay_minutes' => 0 ),
					array( 'type' => 'send_email',            'template'=> 'inactivity_reminder', 'delay_minutes' => 60 ),
				),
			),

			'lead_welcome_sequence' => array(
				'name'         => __( 'Lead nuevo — Secuencia de bienvenida', 'atora-lms' ),
				'description'  => __( 'Enrola automáticamente los leads nuevos en una secuencia drip.', 'atora-lms' ),
				'trigger_type' => 'contact_created',
				'conditions'   => array(),
				'actions'      => array(
					array( 'type' => 'add_tag',              'tag'         => '#lead-nuevo', 'delay_minutes' => 0 ),
					array( 'type' => 'start_email_sequence', 'sequence_id' => 1,             'delay_minutes' => 0 ),
				),
			),
		);
	}

	// ── Admin AJAX ────────────────────────────────────────────────────────────

	/** @return void */
	public static function ajax_save(): void {
		check_ajax_referer( 'atora_automation_admin' );
		if ( ! current_user_can( 'clms_manage_crm' ) && ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

		global $wpdb;

		$id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$data = array(
			'name'          => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'description'   => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'trigger_type'  => sanitize_key( wp_unslash( $_POST['trigger_type'] ?? '' ) ),
			'trigger_config'=> wp_json_encode( $_POST['trigger_config'] ?? array() ),
			'conditions'    => wp_json_encode( $_POST['conditions'] ?? array() ),
			'actions'       => wp_json_encode( $_POST['actions'] ?? array() ),
			'active'        => ! empty( $_POST['active'] ) ? 1 : 0,
			'priority'      => absint( wp_unslash( $_POST['priority'] ?? 10 ) ),
			'updated_at'    => current_time( 'mysql', true ),
		);

		if ( $id ) {
			$wpdb->update( "{$wpdb->prefix}atora_automations", $data, array( 'id' => $id ), null, array( '%d' ) );
		} else {
			$data['created_at'] = current_time( 'mysql', true );
			$wpdb->insert( "{$wpdb->prefix}atora_automations", $data );
			$id = (int) $wpdb->insert_id;
		}

		wp_send_json_success( array( 'id' => $id ) );
	}

	/** @return void */
	public static function ajax_toggle(): void {
		check_ajax_referer( 'atora_automation_admin' );
		if ( ! current_user_can( 'clms_manage_crm' ) && ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }
		global $wpdb;
		$id     = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$active = absint( wp_unslash( $_POST['active'] ?? 0 ) );
		$wpdb->update( "{$wpdb->prefix}atora_automations", array( 'active' => $active ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
		wp_send_json_success();
	}

	/** @return void */
	public static function ajax_import_preset(): void {
		check_ajax_referer( 'atora_automation_admin' );
		if ( ! current_user_can( 'clms_manage_crm' ) && ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

		global $wpdb;
		$preset_key = sanitize_key( wp_unslash( $_POST['preset'] ?? '' ) );
		$presets    = self::get_preset_workflows();

		if ( empty( $presets[ $preset_key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Preset no encontrado.', 'atora-lms' ) ) );
		}

		$preset = $presets[ $preset_key ];
		$wpdb->insert(
			"{$wpdb->prefix}atora_automations",
			array(
				'name'          => sanitize_text_field( $preset['name'] ),
				'description'   => sanitize_textarea_field( $preset['description'] ),
				'trigger_type'  => sanitize_key( $preset['trigger_type'] ),
				'trigger_config'=> '{}',
				'conditions'    => wp_json_encode( $preset['conditions'] ),
				'actions'       => wp_json_encode( $preset['actions'] ),
				'active'        => 0, // Desactivado por defecto: el admin lo activa.
				'priority'      => 10,
				'created_at'    => current_time( 'mysql', true ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( '%s','%s','%s','%s','%s','%s','%d','%d','%s','%s' )
		);

		wp_send_json_success( array(
			'id'      => (int) $wpdb->insert_id,
			'message' => __( 'Workflow importado. Actívalo cuando estés listo.', 'atora-lms' ),
		) );
	}

	public static function ajax_retry_failed(): void {
		check_ajax_referer( 'atora_automation_admin' );
		if ( ! current_user_can( 'clms_manage_crm' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		global $wpdb;

		$updated = $wpdb->update(
			"{$wpdb->prefix}atora_automation_queue",
			array( 'status' => 'pending', 'retry_count' => 0, 'execute_at' => current_time( 'mysql', true ) ),
			array( 'status' => 'failed' ),
			array( '%s', '%d', '%s' ),
			array( '%s' )
		);

		$count = absint( $updated );
		wp_send_json_success( array(
			'count'   => $count,
			'message' => sprintf(
				_n( '%d acción encolada para reintento.', '%d acciones encoladas para reintento.', $count, 'atora-lms' ),
				$count
			),
		) );
	}

	/** @return void */
	public static function ajax_test_run(): void {
		check_ajax_referer( 'atora_automation_admin' );
		if ( ! current_user_can( 'clms_manage_crm' ) && ! current_user_can( 'manage_options' ) ) { wp_send_json_error(); }

		$id      = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$user_id = get_current_user_id();

		global $wpdb;
		$automation = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_automations WHERE id = %d LIMIT 1",
			$id
		) );

		if ( ! $automation ) { wp_send_json_error(); }

		$actions = self::normalize_action_list( self::decode_json_array( (string) ( $automation->actions ?? '[]' ), array() ) );
		$log     = array();

		foreach ( $actions as $action ) {
			$log[] = array(
				'type'          => $action['type'],
				'delay_minutes' => $action['delay_minutes'] ?? 0,
				'note'          => __( '[SIMULACIÓN - no enviado]', 'atora-lms' ),
			);
		}

		wp_send_json_success( array(
			'actions_count' => count( $actions ),
			'log'           => $log,
			'message'       => __( 'Simulación completada. Revisa el log arriba.', 'atora-lms' ),
		) );
	}

	/**
	 * Decodifica JSON y garantiza un array para consumo interno.
	 *
	 * @param string $json    JSON.
	 * @param array  $default Fallback.
	 * @return array
	 */
	private static function decode_json_array( string $json, array $default = array() ): array {
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : $default;
	}

	/**
	 * Normaliza acciones para soportar lista o una sola acción asociativa.
	 *
	 * @param array $actions Decodificación JSON de acciones.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_action_list( array $actions ): array {
		if ( isset( $actions['type'] ) ) {
			$actions = array( $actions );
		}

		$normalized = array();
		foreach ( $actions as $action ) {
			if ( is_array( $action ) ) {
				$normalized[] = $action;
			}
		}

		return $normalized;
	}

	/**
	 * Normaliza la identidad de email enviada por la acción.
	 *
	 * @param array $action Acción de automatización.
	 * @return string
	 */
	private static function resolve_email_identity( array $action ): string {
		$identity = sanitize_key(
			(string) (
				$action['email_identity']
				?? $action['identity']
				?? 'academia'
			)
		);

		self::ensure_email_identity_resolver_loaded();
		if ( class_exists( '\ATORA\EmailEngine\Email_Identity_Resolver' ) ) {
			return \ATORA\EmailEngine\Email_Identity_Resolver::normalize( $identity, 'academia' );
		}

		if ( ! in_array( $identity, array( 'academia', 'teacher', 'admin' ), true ) ) {
			return 'academia';
		}

		return $identity;
	}

	/**
	 * Convierte valores mixtos a bool (checkbox/forms).
	 *
	 * @param mixed $value Valor raw.
	 * @return bool
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return absint( $value ) > 0;
		}
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( '1', 'true', 'yes', 'on', 'si', 's' ), true );
	}

	/**
	 * Crea/actualiza contacto para envíos de formularios y siembra pipeline.
	 *
	 * @param int                $user_id Usuario WP (0 si anónimo).
	 * @param array<string,mixed> $payload Payload saneado.
	 * @return int
	 */
	private static function capture_form_contact( int $user_id, array $payload ): int {
		$user_id = absint( $user_id );
		$email   = sanitize_email( (string) ( $payload['email'] ?? '' ) );
		$phone   = sanitize_text_field( (string) ( $payload['phone'] ?? '' ) );
		$name    = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );
		if ( '' === $name && $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof \WP_User ) {
				$name = sanitize_text_field( (string) ( $user->display_name ?: $user->user_login ) );
			}
		}

		if ( ! class_exists( '\ATORA\CRM\CRM' ) ) {
			$crm_file = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'crm/class-crm.php' : '';
			if ( $crm_file && file_exists( $crm_file ) ) {
				require_once $crm_file;
			}
		}
		if ( ! class_exists( '\ATORA\CRM\CRM' ) || ! method_exists( '\ATORA\CRM\CRM', 'upsert_contact' ) ) {
			return 0;
		}

		if ( 0 === $user_id && '' === $email && '' === $phone ) {
			return 0;
		}

		$contact_id = \ATORA\CRM\CRM::upsert_contact(
			array(
				'user_id' => $user_id,
				'email'   => $email,
				'name'    => $name ?: __( 'Lead sin nombre', 'atora-lms' ),
				'phone'   => $phone,
				'source'  => 'form_submitted',
				'status'  => 'lead',
			)
		);
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return 0;
		}

		self::log_form_submission_activity( $contact_id, $payload );
		self::seed_lead_pipeline_for_contact( $contact_id, $user_id, $name );

		return $contact_id;
	}

	/**
	 * Registra actividad + consentimiento con dedupe por form_id/entry_id.
	 *
	 * @param int                $contact_id Contacto.
	 * @param array<string,mixed> $payload   Payload de formulario.
	 * @return void
	 */
	private static function log_form_submission_activity( int $contact_id, array $payload ): void {
		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return;
		}

		if ( ! class_exists( '\ATORA\CRM_V2\Services\Activity_Service' ) ) {
			$file = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'crm-v2/services/class-activity-service.php' : '';
			if ( $file && file_exists( $file ) ) {
				require_once $file;
			}
		}
		if ( ! class_exists( '\ATORA\CRM_V2\Services\Activity_Service' ) ) {
			return;
		}

		global $wpdb;
		$activities_table = $wpdb->prefix . 'atora_contact_activities';
		$form_id          = absint( $payload['form_id'] ?? 0 );
		$entry_id         = absint( $payload['entry_id'] ?? 0 );
		if ( $entry_id > 0 ) {
			$marker = '"entry_id":' . $entry_id;
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$activities_table}
					 WHERE contact_id = %d
					   AND activity_type = %s
					   AND activity_data LIKE %s
					 LIMIT 1",
					$contact_id,
					'form_submitted',
					'%' . $wpdb->esc_like( $marker ) . '%'
				)
			);
			if ( $exists > 0 ) {
				return;
			}
		}

		\ATORA\CRM_V2\Services\Activity_Service::log_contact_activity(
			$contact_id,
			'form_submitted',
			array(
				'form_id'           => $form_id,
				'entry_id'          => $entry_id,
				'source'            => 'automation_engine',
				'email'             => sanitize_email( (string) ( $payload['email'] ?? '' ) ),
				'phone'             => sanitize_text_field( (string) ( $payload['phone'] ?? '' ) ),
				'consent_marketing' => ! empty( $payload['consent_marketing'] ) ? 1 : 0,
				'consent_whatsapp'  => ! empty( $payload['consent_whatsapp'] ) ? 1 : 0,
				'consent_telegram'  => ! empty( $payload['consent_telegram'] ) ? 1 : 0,
			)
		);
	}

	/**
	 * Siembra deal comercial base para contacto lead/prospecto.
	 *
	 * @param int    $contact_id Contacto.
	 * @param int    $user_id    Usuario asociado.
	 * @param string $name       Nombre contacto.
	 * @return void
	 */
	private static function seed_lead_pipeline_for_contact( int $contact_id, int $user_id, string $name ): void {
		$contact_id = absint( $contact_id );
		$user_id    = absint( $user_id );
		if ( $contact_id <= 0 ) {
			return;
		}

		if ( ! class_exists( '\ATORA\CRM_V2\Services\Deal_Service' ) ) {
			$base = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'crm-v2/services/' : '';
			$files = array(
				$base . 'class-db-service.php',
				$base . 'class-task-service.php',
				$base . 'class-activity-service.php',
				$base . 'class-deal-service.php',
			);
			foreach ( $files as $file ) {
				if ( $file && file_exists( $file ) ) {
					require_once $file;
				}
			}
		}
		if ( class_exists( '\ATORA\CRM_V2\Services\DB_Service' ) && method_exists( '\ATORA\CRM_V2\Services\DB_Service', 'maybe_install_schema' ) ) {
			\ATORA\CRM_V2\Services\DB_Service::maybe_install_schema();
		}
		if ( ! class_exists( '\ATORA\CRM_V2\Services\Deal_Service' ) || ! method_exists( '\ATORA\CRM_V2\Services\Deal_Service', 'ensure_deal_for_contact' ) ) {
			return;
		}

		\ATORA\CRM_V2\Services\Deal_Service::ensure_deal_for_contact(
			$contact_id,
			array(
				'user_id'     => $user_id,
				'title'       => '' !== trim( $name ) ? $name : __( 'Lead sin nombre', 'atora-lms' ),
				'channel'     => 'email',
				'temperature' => 'warm',
				'stage'       => 'new_lead',
				'source'      => 'form_submitted',
			)
		);
	}

	/**
	 * Carga resolver unificado de identidad cuando está disponible.
	 *
	 * @return void
	 */
	private static function ensure_email_identity_resolver_loaded(): void {
		if ( class_exists( '\ATORA\EmailEngine\Email_Identity_Resolver' ) ) {
			return;
		}

		$file = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'email-engine/class-email-identity-resolver.php' : '';
		if ( $file && file_exists( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Encola un mensaje directo para un canal específico.
	 *
	 * @param int    $user_id Usuario destino.
	 * @param string $channel Canal destino.
	 * @param array  $action  Acción en ejecución.
	 * @param array  $context Contexto del trigger.
	 * @return bool
	 */
	private static function enqueue_channel_message( int $user_id, string $channel, array $action, array $context ): bool {
		if ( ! class_exists( 'ATORA\Messaging\Messaging_Router' ) ) {
			$router_file = defined( 'ATORA_LMS_MODULES_DIR' ) ? ATORA_LMS_MODULES_DIR . 'messaging/class-messaging-router.php' : '';
			if ( $router_file && file_exists( $router_file ) ) {
				require_once $router_file;
			}
		}
		if ( ! class_exists( 'ATORA\Messaging\Messaging_Router' ) ) {
			return false;
		}

		$template = sanitize_key( (string) ( $action['template'] ?? '' ) );
		if ( '' === $template ) {
			return false;
		}
		$allow_duplicate = ! empty( $action['allow_duplicate'] );
		$dedupe_window   = absint( $action['dedupe_window_minutes'] ?? 10 );
		$dedupe_key      = sanitize_key( (string) ( $action['dedupe_key'] ?? '' ) );
		if ( '' === $dedupe_key ) {
			$dedupe_key = sanitize_key(
				'auto_' . substr(
					md5(
						wp_json_encode(
							array(
								'user'     => $user_id,
								'channel'  => sanitize_key( $channel ),
								'template' => $template,
								'context'  => $context,
							)
						)
					),
					0,
					24
				)
			);
		}
		$priority        = sanitize_key( (string) ( $action['priority'] ?? self::default_message_priority_for_action( $channel ) ) );
		$fallback_channels = array();
		if ( is_array( $action['fallback_channels'] ?? null ) ) {
			$fallback_channels = array_values( array_filter( array_map( 'strval', $action['fallback_channels'] ) ) );
		} elseif ( ! empty( $action['fallback_channels'] ) ) {
			$fallback_channels = array_values(
				array_filter(
					array_map(
						'trim',
						explode( ',', (string) $action['fallback_channels'] )
					)
				)
			);
		}
		$email_identity_fallback = is_array( $action['email_identity_fallback'] ?? null )
			? array_values( array_filter( array_map( 'strval', $action['email_identity_fallback'] ) ) )
			: array();

		$queued = \ATORA\Messaging\Messaging_Router::enqueue(
			array(
				'user_id'   => $user_id,
				'channel'   => sanitize_key( $channel ),
				'type'      => sanitize_key( (string) ( $action['message_type'] ?? 'marketing' ) ),
				'template'  => $template,
				'variables' => $context,
				'options'   => array(
					'allow_duplicate'      => $allow_duplicate,
					'dedupe_window_minutes'=> $dedupe_window,
					'dedupe_key'           => $dedupe_key,
					'priority'             => $priority,
					'fallback_channels'    => $fallback_channels,
					'email_identity_fallback' => $email_identity_fallback,
				),
			)
		);

		return false !== $queued;
	}

	/**
	 * Prioridad por defecto para acciones de mensajería.
	 *
	 * @param string $channel Canal destino.
	 * @return string
	 */
	private static function default_message_priority_for_action( string $channel ): string {
		$channel = sanitize_key( $channel );
		if ( in_array( $channel, array( 'whatsapp', 'telegram' ), true ) ) {
			return 'high';
		}

		return 'medium';
	}

	// ── REST ──────────────────────────────────────────────────────────────────

	/** @return void */
	public static function register_rest_routes(): void {
		register_rest_route( 'atora/v1', '/automations', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_list' ),
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'args'                => array(
					's'            => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'trigger_type' => array( 'sanitize_callback' => 'sanitize_key' ),
					'active'       => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'per_page'     => array( 'sanitize_callback' => 'absint' ),
					'page'         => array( 'sanitize_callback' => 'absint' ),
					'offset'       => array( 'sanitize_callback' => 'absint' ),
				),
			),
		) );
	}

	/**
	 * Normaliza el tamaño de página para endpoints REST.
	 *
	 * @param \WP_REST_Request $request       Request.
	 * @param int              $default_limit Límite por defecto.
	 * @param int              $max_limit     Límite máximo.
	 * @return int
	 */
	private static function normalize_rest_limit( \WP_REST_Request $request, int $default_limit, int $max_limit ): int {
		$limit = absint( $request->get_param( 'per_page' ) ?? $default_limit );
		if ( $limit < 1 ) {
			$limit = $default_limit;
		}

		return min( $max_limit, $limit );
	}

	/**
	 * Normaliza el offset usando `offset` explícito o `page`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param int              $limit   Límite por página.
	 * @return int
	 */
	private static function normalize_rest_offset( \WP_REST_Request $request, int $limit ): int {
		$page   = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );
		$offset = absint( $request->get_param( 'offset' ) ?? ( ( $page - 1 ) * $limit ) );

		return min( 5000, $offset );
	}

	/**
	 * Verifica si una tabla existe.
	 *
	 * @param string $table Nombre completo de tabla.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		$like   = $wpdb->esc_like( $table );
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return $exists === $table;
	}

	/**
	 * Construye respuesta REST con headers de paginación.
	 *
	 * @param array<int,mixed> $items    Elementos de respuesta.
	 * @param int              $total    Total de registros.
	 * @param int              $per_page Tamaño de página.
	 * @return \WP_REST_Response
	 */
	private static function prepare_paginated_rest_response( array $items, int $total, int $per_page ): \WP_REST_Response {
		$response = rest_ensure_response( $items );
		if ( ! $response instanceof \WP_REST_Response ) {
			$response = new \WP_REST_Response( $items );
		}

		$total       = max( 0, $total );
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 0, $total_pages ) );

		return $response;
	}

	/** @param \WP_REST_Request $r */
	public static function rest_list( \WP_REST_Request $r ): \WP_REST_Response {
		global $wpdb;

		$limit  = self::normalize_rest_limit( $r, 20, 100 );
		$offset = self::normalize_rest_offset( $r, $limit );
		if ( ! self::table_exists( "{$wpdb->prefix}atora_automations" ) ) {
			return self::prepare_paginated_rest_response( array(), 0, $limit );
		}

		$search       = sanitize_text_field( $r->get_param( 's' ) ?? '' );
		$trigger_type = sanitize_key( (string) ( $r->get_param( 'trigger_type' ) ?? '' ) );
		$active_raw   = (string) ( $r->get_param( 'active' ) ?? '' );

		$where  = '1=1';
		$params = array();

		if ( $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where  .= ' AND name LIKE %s';
			$params[] = $like;
		}

		if ( '' !== $trigger_type && in_array( $trigger_type, self::TRIGGERS, true ) ) {
			$where   .= ' AND trigger_type = %s';
			$params[] = $trigger_type;
		}

		if ( in_array( $active_raw, array( '0', '1' ), true ) ) {
			$where   .= ' AND active = %d';
			$params[] = (int) $active_raw;
		}

		$count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}atora_automations WHERE {$where}";
		$total     = ! empty( $params )
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$list_sql    = "SELECT id, name, trigger_type, active, priority, created_at, updated_at FROM {$wpdb->prefix}atora_automations WHERE {$where} ORDER BY priority ASC, id DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $limit, $offset ) );
		$rows        = (array) $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return self::prepare_paginated_rest_response( $rows, $total, $limit );
	}

	/** @return void */
	public static function register_admin_menu(): void {
		global $submenu;
		$existing_items = (array) ( $submenu['clms-dashboard'] ?? array() );
		foreach ( $existing_items as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( 'atora-automations' === $slug ) {
				return;
			}
		}

		add_submenu_page(
			'', // PT-4.4.3: reubicado bajo el hub "Crecimiento" (atora-growth-hub).
			__( 'Automatizaciones', 'atora-lms' ),
			__( 'Automatizaciones', 'atora-lms' ),
			'manage_options',
			'atora-automations',
			static function () {
				$view = ATORA_LMS_MODULES_DIR . 'automation/views/admin.php';
				if ( file_exists( $view ) ) { require $view; }
			}
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Interpola variables {{user_name}}, {{course_title}}… en un string.
	 *
	 * @param string $template String con placeholders.
	 * @param int    $user_id  ID del usuario.
	 * @param array  $context  Contexto adicional.
	 * @return string
	 */
	private static function interpolate_string( string $template, int $user_id, array $context ): string {
		$user = get_userdata( $user_id );
		$vars = array_merge( array(
			'user_name'    => $user ? $user->display_name : '',
			'user_email'   => $user ? $user->user_email : '',
			'site_name'    => get_bloginfo( 'name' ),
			'course_title' => isset( $context['course_id'] ) ? get_the_title( absint( $context['course_id'] ) ) : '',
		), $context );

		return preg_replace_callback( '/\{\{(\w+)\}\}/', static function ( $m ) use ( $vars ) {
			return sanitize_text_field( (string) ( $vars[ $m[1] ] ?? '' ) );
		}, $template );
	}

	/**
	 * Interpola variables en un payload JSON.
	 *
	 * @param string $json    Payload JSON.
	 * @param int    $user_id ID del usuario.
	 * @param array  $context Contexto.
	 * @return string
	 */
	private static function interpolate_variables( string $json, int $user_id, array $context ): string {
		return self::interpolate_string( $json, $user_id, $context );
	}
}
