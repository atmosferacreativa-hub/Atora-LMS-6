<?php
/**
 * ATORA LMS v5 — Email Engine
 *
 * Sistema de emails transaccionales con:
 * - Queue asíncrona (no bloquea requests)
 * - 6 providers: Brevo, SendGrid, Mailgun, Amazon SES, Postmark, SMTP
 * - 16 templates HTML + texto
 * - Compliance GDPR/CAN-SPAM automático
 * - Webhooks entrantes para tracking (opens, clicks, bounces)
 * - Multi-dominio (hasta 3 dominios por academia)
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
 * Class Email_Engine
 *
 * @since 5.0.0
 */
class Email_Engine {

	/**
	 * Inicializa el motor de email.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Iniciar el procesador de la queue.
		if ( class_exists( 'ATORA\EmailEngine\Email_Queue' ) ) {
			Email_Queue::init();
		}

		// Webhooks entrantes de todos los providers.
		add_action( 'rest_api_init', array( __CLASS__, 'register_webhook_routes' ) );

		// Endpoints de compliance (unsub, preferences).
		add_action( 'init',          array( __CLASS__, 'register_compliance_endpoints' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_compliance_pages' ) );

		// Admin settings.
		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		}

		// Hookear eventos LMS a la cola de emails.
		self::register_lms_hooks();
	}

	// ── Hooks LMS → Queue ────────────────────────────────────────────────────

	/**
	 * Conecta eventos del LMS con plantillas de email.
	 *
	 * @return void
	 */
	private static function register_lms_hooks(): void {
		$map = array(
			'clms_user_enrolled_in_course'         => array( __CLASS__, 'on_course_enrolled' ),
			'clms_submission_saved'                => array( __CLASS__, 'on_submission_saved_hook' ),
			'clms_submission_graded'               => array( __CLASS__, 'on_submission_graded_hook' ),
			'clms_quiz_submitted'                  => array( __CLASS__, 'on_quiz_submitted_hook' ),
			'clms_lesson_completed'                => array( __CLASS__, 'on_lesson_completed' ),
			'clms_certificate_issued'              => array( __CLASS__, 'on_certificate_issued_hook' ),
			'atora/affiliates/commission_created'  => array( __CLASS__, 'on_commission_created' ),
		);

		foreach ( $map as $hook => $callback ) {
			add_action( $hook, $callback, 20, PHP_INT_MAX );
		}
	}

	/**
	 * Adaptador del hook clms_submission_graded.
	 * Firma real: ($submission_id, $student_id, $status, $grade, $feedback)
	 *
	 * @param mixed $submission_id ID de la entrega.
	 * @param mixed $user_id       ID del estudiante.
	 * @param mixed $status        Estado de calificación.
	 * @param mixed $grade         Nota (puede llegar como string numérico).
	 * @param mixed $feedback      Feedback textual.
	 * @return void
	 */
	public static function on_submission_graded_hook( $submission_id, $user_id, $status = '', $grade = 0, $feedback = '' ): void {
		unset( $feedback );

		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}

		if ( 'graded' !== sanitize_key( (string) $status ) ) {
			return;
		}

		$submission_id = absint( $submission_id );
		$lesson_id     = $submission_id ? absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) ) : 0;
		$source        = $submission_id ? sanitize_key( (string) get_post_meta( $submission_id, '_clms_grade_source', true ) ) : '';
		$retry_context = self::get_submission_retry_context( $lesson_id );
		$student_message = self::build_grade_student_message( (float) $grade, $retry_context );

		self::on_grade_published(
			$user_id,
			$lesson_id,
			(float) $grade,
			array(
				'submission_id'   => $submission_id,
				'source'          => $source ? $source : 'manual',
				'evaluation_type' => 'submission',
				'student_message' => $student_message,
				'retry_hint'      => self::build_retry_hint_text( $retry_context ),
				'retry_available' => ! empty( $retry_context['can_retry'] ) ? 1 : 0,
				'next_deadline'   => isset( $retry_context['deadline_label'] ) ? (string) $retry_context['deadline_label'] : '',
			)
		);
	}

	/**
	 * Adaptador del hook clms_submission_saved.
	 * Firma real: ($submission_id, $user_id, $lesson_id, $course_id)
	 *
	 * @param mixed $submission_id ID de la entrega.
	 * @param mixed $user_id       ID del estudiante.
	 * @param mixed $lesson_id     ID de la lección.
	 * @param mixed $course_id     ID del curso.
	 * @return void
	 */
	public static function on_submission_saved_hook( $submission_id, $user_id, $lesson_id = 0, $course_id = 0 ): void {
		$submission_id = absint( $submission_id );
		$user_id       = absint( $user_id );
		$lesson_id     = absint( $lesson_id );
		$course_id     = absint( $course_id );

		if ( ! $submission_id || ! $user_id ) {
			return;
		}

		$status = sanitize_key( (string) get_post_meta( $submission_id, '_clms_submission_status', true ) );
		if ( 'submitted' !== $status ) {
			return;
		}

		if ( ! $lesson_id ) {
			$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		}
		if ( ! $course_id ) {
			$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		}
		if ( ! $lesson_id ) {
			return;
		}

		$submitted_at = (string) get_post_meta( $submission_id, '_clms_submission_submitted_at', true );
		self::on_submission_received( $user_id, $lesson_id, $course_id, $submission_id, $submitted_at );
	}

	/**
	 * Adaptador del hook clms_quiz_submitted.
	 * Firma real: ($user_id, $lesson_id, $result)
	 *
	 * @param mixed $user_id   ID del estudiante.
	 * @param mixed $lesson_id ID de la lección.
	 * @param mixed $result    Resultado del quiz.
	 * @return void
	 */
	public static function on_quiz_submitted_hook( $user_id, $lesson_id, $result = array() ): void {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );
		$result    = is_array( $result ) ? $result : array();

		if ( ! $user_id || ! $lesson_id ) {
			return;
		}

		if ( ! self::user_accepts_emails( $user_id, 'grade_notifications' ) ) {
			return;
		}

		$best_score    = isset( $result['best_score'] ) ? (float) $result['best_score'] : ( isset( $result['score'] ) ? (float) $result['score'] : 0.0 );
		$latest_score  = isset( $result['latest_score'] ) ? (float) $result['latest_score'] : $best_score;
		$attempts      = isset( $result['attempts'] ) ? absint( $result['attempts'] ) : 1;
		$retry_context = isset( $result['retry_context'] ) && is_array( $result['retry_context'] ) ? $result['retry_context'] : array();

		$metadata = array(
			'lesson_id'        => $lesson_id,
			'grade'            => $best_score,
			'evaluation_type'  => 'quiz',
			'is_quiz'          => 1,
			'best_score'       => round( $best_score, 2 ),
			'latest_score'     => round( $latest_score, 2 ),
			'attempts'         => $attempts,
			'student_message'  => isset( $result['student_message'] ) && '' !== (string) $result['student_message']
				? sanitize_text_field( (string) $result['student_message'] )
				: self::build_grade_student_message( $latest_score, $retry_context ),
			'retry_hint'       => self::build_retry_hint_text( $retry_context ),
			'retry_available'  => ! empty( $retry_context['can_retry'] ) ? 1 : 0,
			'remaining_attempts' => isset( $retry_context['remaining_attempts'] ) ? (int) $retry_context['remaining_attempts'] : -1,
			'next_deadline'    => isset( $retry_context['deadline_label'] ) ? sanitize_text_field( (string) $retry_context['deadline_label'] ) : '',
			'source'           => 'quiz',
		);

		Email_Queue::enqueue( array(
			'template'        => 'grade_published',
			'user_id'         => $user_id,
			'priority'        => 'high',
			'allow_duplicate' => true,
			'metadata'        => $metadata,
		) );
	}

	/**
	 * Encola email de bienvenida al curso.
	 *
	 * @param int $user_id   ID del usuario.
	 * @param int $course_id ID del curso.
	 * @return void
	 */
	public static function on_course_enrolled( int $user_id, int $course_id ): void {
		if ( ! self::user_accepts_emails( $user_id, 'academic_notifications' ) ) {
			return;
		}

		Email_Queue::enqueue( array(
			'template'  => 'welcome_course',
			'user_id'   => $user_id,
			'priority'  => 'high',
			'metadata'  => array( 'course_id' => $course_id ),
		) );
	}

	/**
	 * Encola email de calificación publicada.
	 *
	 * @param int   $user_id    ID del usuario.
	 * @param int   $lesson_id  ID de la lección.
	 * @param float $grade      Calificación.
	 * @param array $extra_metadata Metadata adicional del evento.
	 * @return void
	 */
	public static function on_grade_published( int $user_id, int $lesson_id, float $grade = 0, array $extra_metadata = array() ): void {
		if ( ! self::user_accepts_emails( $user_id, 'grade_notifications' ) ) {
			return;
		}

		$lesson_id       = absint( $lesson_id );
		$retry_context   = self::get_submission_retry_context( $lesson_id );
		$student_message = self::build_grade_student_message( $grade, $retry_context );
		$metadata        = array_merge(
			array(
				'lesson_id'       => $lesson_id,
				'grade'           => $grade,
				'evaluation_type' => 'submission',
				'source'          => 'manual',
				'student_message' => $student_message,
				'retry_hint'      => self::build_retry_hint_text( $retry_context ),
				'retry_available' => ! empty( $retry_context['can_retry'] ) ? 1 : 0,
				'next_deadline'   => isset( $retry_context['deadline_label'] ) ? (string) $retry_context['deadline_label'] : '',
			),
			is_array( $extra_metadata ) ? $extra_metadata : array()
		);

		Email_Queue::enqueue( array(
			'template' => 'grade_published',
			'user_id'  => $user_id,
			'priority' => 'high',
			'metadata' => $metadata,
		) );
	}

	/**
	 * Encola email de confirmación de entrega recibida.
	 *
	 * @param int    $user_id       ID del usuario.
	 * @param int    $lesson_id     ID de la lección.
	 * @param int    $course_id     ID del curso.
	 * @param int    $submission_id ID de la entrega.
	 * @param string $submitted_at  Fecha/hora de envío.
	 * @return void
	 */
	public static function on_submission_received( int $user_id, int $lesson_id, int $course_id = 0, int $submission_id = 0, string $submitted_at = '' ): void {
		if ( ! self::user_accepts_emails( $user_id, 'academic_notifications' ) ) {
			return;
		}

		$lesson_id     = absint( $lesson_id );
		$course_id     = absint( $course_id );
		$submission_id = absint( $submission_id );
		$submitted_at  = sanitize_text_field( $submitted_at );

		$evaluation_mode = self::get_lesson_evaluation_mode( $lesson_id );
		$retry_context   = self::get_submission_retry_context( $lesson_id );
		$student_message = self::build_submission_received_student_message( $evaluation_mode, $retry_context );
		$submitted_label = '';
		if ( '' !== $submitted_at ) {
			$submitted_ts = strtotime( $submitted_at );
			if ( $submitted_ts ) {
				$submitted_label = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $submitted_ts );
			}
		}

		Email_Queue::enqueue( array(
			'template' => 'submission_received',
			'user_id'  => $user_id,
			'priority' => 'high',
			'metadata' => array(
				'lesson_id'        => $lesson_id,
				'course_id'        => $course_id,
				'submission_id'    => $submission_id,
				'submitted_at'     => $submitted_at,
				'submitted_label'  => $submitted_label,
				'evaluation_type'  => 'submission',
				'evaluation_mode'  => $evaluation_mode,
				'student_message'  => $student_message,
				'retry_hint'       => self::build_retry_hint_text( $retry_context ),
				'retry_available'  => ! empty( $retry_context['can_retry'] ) ? 1 : 0,
				'next_deadline'    => isset( $retry_context['deadline_label'] ) ? (string) $retry_context['deadline_label'] : '',
				'source'           => 'submission',
			),
		) );
	}

	/**
	 * Mensaje optimista según la nota obtenida.
	 *
	 * @param float $grade         Nota lograda.
	 * @param array $retry_context Contexto de reintento.
	 * @return string
	 */
	private static function build_grade_student_message( float $grade, array $retry_context = array() ): string {
		$grade = max( 0.0, min( 100.0, $grade ) );

		if ( $grade >= 90 ) {
			$message = __( 'Excelente nota. Tu desempeño fue muy sólido.', 'atora-lms' );
		} elseif ( $grade >= 70 ) {
			$message = __( 'Muy buen avance. Vas por un camino consistente.', 'atora-lms' );
		} else {
			$message = __( 'En esta oportunidad no fuiste tan preciso. Repasa tus apuntes y el material de apoyo para volver a intentarlo con más claridad.', 'atora-lms' );
		}

		$retry_hint = self::build_retry_hint_text( $retry_context );
		if ( '' !== $retry_hint ) {
			$message .= ' ' . $retry_hint;
		}

		return sanitize_text_field( $message );
	}

	/**
	 * Mensaje de recepción de entrega según modo de evaluación.
	 *
	 * @param string $evaluation_mode Modo configurado.
	 * @param array  $retry_context   Contexto de reenvío.
	 * @return string
	 */
	private static function build_submission_received_student_message( string $evaluation_mode, array $retry_context = array() ): string {
		if ( 'ai_auto_grade' === $evaluation_mode ) {
			$message = __( 'Recibimos tu entrega. La evaluación automática está en proceso y pronto verás tu rendimiento.', 'atora-lms' );
		} else {
			$message = __( 'Recibimos tu entrega correctamente. Tu docente la revisará y te notificaremos cuando haya actualización.', 'atora-lms' );
		}

		$retry_hint = self::build_retry_hint_text( $retry_context );
		if ( '' !== $retry_hint ) {
			$message .= ' ' . $retry_hint;
		}

		return sanitize_text_field( $message );
	}

	/**
	 * Obtiene el modo de evaluación configurado para una lección.
	 *
	 * @param int $lesson_id Lección.
	 * @return string
	 */
	private static function get_lesson_evaluation_mode( int $lesson_id ): string {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id || ! class_exists( '\CLMS_Helper' ) ) {
			return 'manual';
		}

		$assessment = \clms_core('CLMS_Assessment_Engine');
		if ( ! $assessment || ! method_exists( $assessment, 'get_lesson_evaluation_settings' ) ) {
			return 'manual';
		}

		$settings = (array) $assessment->get_lesson_evaluation_settings( $lesson_id );
		$mode     = sanitize_key( (string) ( $settings['mode'] ?? 'manual' ) );

		return '' !== $mode ? $mode : 'manual';
	}

	/**
	 * Contexto de oportunidad de reenvío para entregas.
	 *
	 * @param int $lesson_id Lección.
	 * @return array<string,mixed>
	 */
	private static function get_submission_retry_context( int $lesson_id ): array {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id ) {
			return array(
				'allow_resubmission' => true,
				'deadline_ts'        => 0,
				'deadline_label'     => '',
				'deadline_expired'   => false,
				'can_retry'          => true,
			);
		}

		$allow_resubmission = true;
		if ( class_exists( '\CLMS_Helper' ) ) {
			$evidence = \clms_core('CLMS_Evidence_Service');
			if ( $evidence && method_exists( $evidence, 'get_activity_evidence_config' ) ) {
				$config = (array) $evidence->get_activity_evidence_config( $lesson_id );
				if ( array_key_exists( 'allow_resubmission', $config ) ) {
					$allow_resubmission = ! empty( $config['allow_resubmission'] );
				}
			}
		}

		$deadline_ts      = self::get_lesson_deadline_timestamp( $lesson_id );
		$deadline_expired = $deadline_ts > 0 && time() > $deadline_ts;
		$deadline_label   = $deadline_ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $deadline_ts ) : '';

		return array(
			'allow_resubmission' => $allow_resubmission,
			'deadline_ts'        => $deadline_ts,
			'deadline_label'     => $deadline_label,
			'deadline_expired'   => $deadline_expired,
			'can_retry'          => $allow_resubmission && ! $deadline_expired,
		);
	}

	/**
	 * Construye texto breve de oportunidad/plazo.
	 *
	 * @param array $retry_context Contexto de reintento.
	 * @return string
	 */
	private static function build_retry_hint_text( array $retry_context = array() ): string {
		$retry_context = is_array( $retry_context ) ? $retry_context : array();
		$deadline      = isset( $retry_context['deadline_label'] ) ? sanitize_text_field( (string) $retry_context['deadline_label'] ) : '';

		if ( ! empty( $retry_context['can_retry'] ) ) {
			$message = __( 'Aún puedes volver a intentarlo para mejorar tu resultado.', 'atora-lms' );
			if ( '' !== $deadline ) {
				$message .= ' ' . sprintf( __( 'Tienes hasta %s para una nueva presentación.', 'atora-lms' ), $deadline );
			}
			return sanitize_text_field( $message );
		}

		if ( ! empty( $retry_context['deadline_expired'] ) ) {
			return sanitize_text_field( __( 'El plazo de esta actividad ya finalizó y no admite nuevos intentos.', 'atora-lms' ) );
		}

		if ( array_key_exists( 'allow_resubmission', $retry_context ) && empty( $retry_context['allow_resubmission'] ) ) {
			return sanitize_text_field( __( 'Esta actividad no tiene nuevas oportunidades de envío.', 'atora-lms' ) );
		}

		if ( array_key_exists( 'remaining_attempts', $retry_context ) ) {
			$remaining = (int) $retry_context['remaining_attempts'];
			if ( $remaining > 0 ) {
				return sanitize_text_field(
					sprintf(
						/* translators: %d: intentos restantes */
						__( 'Aún tienes %d intento(s) disponibles.', 'atora-lms' ),
						$remaining
					)
				);
			}
			if ( 0 === $remaining ) {
				return sanitize_text_field( __( 'Ya alcanzaste el límite de intentos para esta evaluación.', 'atora-lms' ) );
			}
		}

		return '';
	}

	/**
	 * Obtiene la fecha/hora límite efectiva de una actividad.
	 *
	 * @param int $lesson_id Lección.
	 * @return int
	 */
	private static function get_lesson_deadline_timestamp( int $lesson_id ): int {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id || ! class_exists( '\CLMS_Helper' ) ) {
			return 0;
		}

		$late_date = (string) \CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_late_date', '_clms_due_date_late' ), '' );
		$late_time = (string) \CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_late_time' ), '' );
		$due_date  = (string) \CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_date', '_clms_due_date' ), '' );
		$due_time  = (string) \CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_time', '_clms_due_time' ), '' );

		$date = trim( $late_date );
		$time = trim( $late_time );
		if ( '' === $date ) {
			$date = trim( $due_date );
			$time = trim( $due_time );
		}

		if ( '' === $date ) {
			return 0;
		}

		if ( '' === $time ) {
			$time = '23:59';
		}

		$ts = strtotime( $date . ' ' . $time );
		return $ts ? (int) $ts : 0;
	}

	/**
	 * Encola notificación de nueva lección disponible.
	 *
	 * @param int $user_id   ID del usuario.
	 * @param int $lesson_id ID de la lección completada (la anterior).
	 * @return void
	 */
	public static function on_lesson_completed( int $user_id, int $lesson_id ): void {
		if ( ! self::user_accepts_emails( $user_id, 'new_content_notifications' ) ) {
			return;
		}

		Email_Queue::enqueue( array(
			'template' => 'new_lesson_unlocked',
			'user_id'  => $user_id,
			'priority' => 'medium',
			'metadata' => array( 'lesson_id' => $lesson_id ),
		) );
	}

	/**
	 * Encola email de certificado listo.
	 *
	 * @param int $user_id   ID del usuario.
	 * @param int $course_id ID del curso.
	 * @return void
	 */
	public static function on_certificate_ready( int $user_id, int $course_id ): void {
		if ( ! $user_id || ! $course_id ) {
			return;
		}

		if ( ! self::user_accepts_emails( $user_id, 'certificate_notifications' ) ) {
			return;
		}

		Email_Queue::enqueue( array(
			'template' => 'certificate_ready',
			'user_id'  => $user_id,
			'priority' => 'high',
			'metadata' => array( 'course_id' => $course_id ),
		) );
	}

	/**
	 * Adaptador del hook clms_certificate_issued.
	 * Firma real: ($record, $user_id, $course_id)
	 *
	 * @param mixed $record    Registro de certificado.
	 * @param mixed $user_id   ID del usuario.
	 * @param mixed $course_id ID del curso.
	 * @return void
	 */
	public static function on_certificate_issued_hook( $record, $user_id = 0, $course_id = 0 ): void {
		unset( $record );
		self::on_certificate_ready( absint( $user_id ), absint( $course_id ) );
	}

	/**
	 * Encola email de comisión de afiliado.
	 *
	 * @param int   $affiliate_id ID del afiliado.
	 * @param int   $order_id     ID del pedido.
	 * @param float $commission   Comisión.
	 * @return void
	 */
	public static function on_commission_created( int $affiliate_id, int $order_id, float $commission ): void {
		global $wpdb;

		$affiliate = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_affiliates WHERE id = %d LIMIT 1",
			$affiliate_id
		) );

		if ( ! $affiliate ) {
			return;
		}

		Email_Queue::enqueue( array(
			'template' => 'affiliate_commission',
			'user_id'  => (int) $affiliate->user_id,
			'priority' => 'medium',
			'metadata' => array(
				'order_id'   => $order_id,
				'commission' => $commission,
			),
		) );
	}

	// ── Webhooks ──────────────────────────────────────────────────────────────

	/**
	 * Registra los endpoints de webhooks de todos los providers.
	 *
	 * @return void
	 */
	public static function register_webhook_routes(): void {
		$providers = array( 'brevo', 'sendgrid', 'mailgun', 'ses', 'postmark' );

		foreach ( $providers as $provider ) {
			// __return_true es necesario: los providers de email no pueden autenticarse con cookie/cap.
			// La verificación de firma se realiza dentro del handler para cada provider.
			register_rest_route( 'atora/v1', '/webhooks/' . $provider, array(
				'methods'             => 'POST',
				'callback'            => static function ( \WP_REST_Request $req ) use ( $provider ) {
					return Email_Engine::handle_provider_webhook( $provider, $req );
				},
				'permission_callback' => '__return_true',
			) );
		}
	}

	/**
	 * Verifica la firma del webhook de un provider de email.
	 * Si no hay clave configurada en dev mode, permite pasar.
	 *
	 * @param string           $provider Provider.
	 * @param \WP_REST_Request $request  Request.
	 * @return bool
	 */
	private static function verify_provider_webhook_signature( string $provider, \WP_REST_Request $request ): bool {
		$opts = (array) get_option( 'atora_email_engine_options', array() );

		switch ( $provider ) {
			case 'sendgrid':
				// SendGrid usa ECDSA sobre timestamp + body (Event Webhook Signature Verification).
				$key = (string) ( $opts['sendgrid_webhook_key'] ?? '' );
				$key = trim( $key );
				if ( ! $key ) {
					return defined( 'ATORA_DEV_MODE' ) && ATORA_DEV_MODE;
				}
				if ( false === strpos( $key, 'BEGIN PUBLIC KEY' ) ) {
					$normalized = preg_replace( '/\s+/', '', $key );
					if ( ! is_string( $normalized ) || '' === $normalized ) {
						return false;
					}
					$key = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( $normalized, 64, "\n" ) . "-----END PUBLIC KEY-----";
				}

				$signature = (string) $request->get_header( 'x-twilio-email-event-webhook-signature' );
				$timestamp = (string) $request->get_header( 'x-twilio-email-event-webhook-timestamp' );
				$decoded   = base64_decode( $signature, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

				if ( '' === $timestamp || '' === $signature || false === $decoded || ! function_exists( 'openssl_verify' ) ) {
					return false;
				}

				$payload = $timestamp . $request->get_body();
				$result  = openssl_verify( $payload, $decoded, $key, OPENSSL_ALGO_SHA256 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return 1 === $result;

			case 'mailgun':
				// Mailgun firma con HMAC-SHA256 del timestamp + token.
				$signing_key = sanitize_text_field( $opts['mailgun_webhook_signing_key'] ?? '' );
				if ( ! $signing_key ) {
					return defined( 'ATORA_DEV_MODE' ) && ATORA_DEV_MODE;
				}
				$body      = $request->get_json_params();
				$timestamp = sanitize_text_field( $body['signature']['timestamp'] ?? '' );
				$token     = sanitize_text_field( $body['signature']['token'] ?? '' );
				$sig       = sanitize_text_field( $body['signature']['signature'] ?? '' );
				$expected  = hash_hmac( 'sha256', $timestamp . $token, $signing_key );
				return hash_equals( $expected, $sig );

			case 'postmark':
				// Postmark usa un header X-Postmark-Signature con clave HMAC.
				$key = sanitize_text_field( $opts['postmark_webhook_secret'] ?? '' );
				if ( ! $key ) {
					return defined( 'ATORA_DEV_MODE' ) && ATORA_DEV_MODE;
				}
				$sig      = (string) $request->get_header( 'x-postmark-signature' );
				$expected = base64_encode( hash_hmac( 'sha256', $request->get_body(), $key, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				return hash_equals( $expected, $sig );

			case 'brevo':
			case 'ses':
				// Brevo y SES no exponen una firma estándar simple; confiar en IP allowlist o clave de query.
				$token = sanitize_text_field( $opts[ $provider . '_webhook_token' ] ?? '' );
				if ( ! $token ) {
					return defined( 'ATORA_DEV_MODE' ) && ATORA_DEV_MODE;
				}
				$provided = sanitize_text_field( $request->get_param( 'token' ) ?? '' );
				return hash_equals( $token, $provided );

			default:
				return false;
		}
	}

	/**
	 * Procesa un webhook de un provider de email.
	 *
	 * @param string           $provider Provider.
	 * @param \WP_REST_Request $request  Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_provider_webhook( string $provider, \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		if ( ! self::verify_provider_webhook_signature( $provider, $request ) ) {
			return new \WP_REST_Response( array( 'status' => 'invalid_signature' ), 403 );
		}

		$events = self::parse_webhook_events( $provider, $request );

		foreach ( $events as $event ) {
			$queue_id   = absint( $event['queue_id'] ?? 0 );
			$event_type = sanitize_key( $event['type'] ?? '' );

			if ( ! $queue_id || ! $event_type ) {
				continue;
			}

			// Actualizar queue.
			$update = array();
			if ( 'delivered' === $event_type ) {
				$update = array( 'status' => 'sent', 'sent_at' => current_time( 'mysql', true ) );
			} elseif ( 'opened' === $event_type ) {
				$update = array( 'opened_at' => current_time( 'mysql', true ) );
			} elseif ( 'clicked' === $event_type ) {
				$update = array( 'clicked_at' => current_time( 'mysql', true ) );
			} elseif ( 'bounced' === $event_type ) {
				$update = array( 'status' => 'bounced' );
			} elseif ( 'complained' === $event_type ) {
				$update = array( 'status' => 'failed' );
				// Auto-unsub.
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT user_id FROM {$wpdb->prefix}atora_email_queue WHERE id = %d LIMIT 1",
					$queue_id
				) );
				if ( $row ) {
					self::unsubscribe_all( (int) $row->user_id );
				}
			}

			if ( $update ) {
				$wpdb->update(
					"{$wpdb->prefix}atora_email_queue",
					$update,
					array( 'id' => $queue_id ),
					null,
					array( '%d' )
				);
			}

			// Log del evento.
			$wpdb->insert(
				"{$wpdb->prefix}atora_email_events",
				array(
					'queue_id'   => $queue_id,
					'event_type' => $event_type,
					'event_data' => wp_json_encode( $event['raw'] ?? array() ),
				),
				array( '%d', '%s', '%s' )
			);
			if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'sync_email_queue_to_conversation' ) ) {
				\ATORA\CRM\CRM::sync_email_queue_to_conversation( $queue_id );
			}

			do_action( 'atora/email/webhook_event', $event_type, $queue_id, $event );
		}

		return rest_ensure_response( array( 'status' => 'ok' ) );
	}

	// ── Compliance ────────────────────────────────────────────────────────────

	/**
	 * Registra los rewrite rules para las páginas de compliance.
	 *
	 * @return void
	 */
	public static function register_compliance_endpoints(): void {
		add_rewrite_rule( '^email/unsubscribe/?$', 'index.php?atora_email_action=unsubscribe', 'top' );
		add_rewrite_rule( '^email/preferences/?$', 'index.php?atora_email_action=preferences', 'top' );
		add_rewrite_rule( '^email/click/?$',       'index.php?atora_email_action=click',       'top' );

		add_filter( 'query_vars', static function ( $vars ) {
			$vars[] = 'atora_email_action';
			$vars[] = 'atora_email_token';
			return $vars;
		} );
	}

	/**
	 * Gestiona las páginas de compliance (unsub, preferences, click tracking).
	 *
	 * @return void
	 */
	public static function handle_compliance_pages(): void {
		$action = sanitize_key( get_query_var( 'atora_email_action' ) );
		$token  = sanitize_text_field( get_query_var( 'atora_email_token' ) );

		if ( ! $action ) {
			return;
		}

		if ( 'click' === $action && $token ) {
			self::handle_click_redirect( $token );
			return;
		}

		if ( 'unsubscribe' === $action ) {
			self::handle_unsubscribe( $token );
			return;
		}

		if ( 'preferences' === $action ) {
			self::render_preferences_page();
			exit;
		}
	}

	/**
	 * Procesa un click en un link de email y redirige.
	 *
	 * @param string $token Token del link.
	 * @return void
	 */
	private static function handle_click_redirect( string $token ): void {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_email_events WHERE event_data LIKE %s LIMIT 1",
			'%' . $wpdb->esc_like( $token ) . '%'
		) );

		$url = home_url( '/' );

		if ( $row ) {
			$data = json_decode( $row->event_data, true );
			$url  = esc_url_raw( $data['link'] ?? home_url( '/' ) );

			// Actualizar analytics.
			$wpdb->update(
				"{$wpdb->prefix}atora_email_queue",
				array( 'clicked_at' => current_time( 'mysql', true ) ),
				array( 'id' => $row->queue_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Procesa una solicitud de unsub.
	 *
	 * @param string $token Token de unsub.
	 * @return void
	 */
	private static function handle_unsubscribe( string $token ): void {
		global $wpdb;

		if ( ! $token ) {
			return;
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->prefix}atora_email_queue WHERE id = %s LIMIT 1",
			$token
		) );

		if ( $row ) {
			self::unsubscribe_all( (int) $row->user_id );
		}

		include ATORA_LMS_MODULES_DIR . 'email-engine/views/unsubscribed.php';
		exit;
	}

	/**
	 * Renderiza la página de preferencias de email.
	 *
	 * @return void
	 */
	private static function render_preferences_page(): void {
		$view = ATORA_LMS_MODULES_DIR . 'email-engine/views/preferences.php';
		if ( file_exists( $view ) ) {
			get_header();
			require $view;
			get_footer();
		}
	}

	// ── Admin ─────────────────────────────────────────────────────────────────

	/**
	 * Registra el menú admin del Email Engine.
	 *
	 * @return void
	 */
	public static function register_admin_menu(): void {
		global $submenu;
		$existing_items = (array) ( $submenu['clms-dashboard'] ?? array() );
		foreach ( $existing_items as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( 'atora-emails' === $slug ) {
				return;
			}
		}

		add_submenu_page(
			'', // PT-4.4.3: reubicado bajo el hub "Comunicación" (atora-communication-hub).
			__( 'Email Engine', 'atora-lms' ),
			__( 'Emails', 'atora-lms' ),
			'manage_options',
			'atora-emails',
			static function () {
				$view = ATORA_LMS_MODULES_DIR . 'email-engine/views/admin.php';
				if ( file_exists( $view ) ) {
					try {
						require $view;
					} catch ( \Throwable $e ) {
						if ( function_exists( 'error_log' ) ) {
							error_log( '[ATORA Emails] Error al renderizar admin: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
						echo '<div class="wrap"><h1>' . esc_html__( 'Emails', 'atora-lms' ) . '</h1><div class="notice notice-error"><p>' . esc_html__( 'No se pudo renderizar el panel de Emails. Revisa el log de errores.', 'atora-lms' ) . '</p></div></div>';
					}
					return;
				}

				echo '<div class="wrap"><h1>' . esc_html__( 'Emails', 'atora-lms' ) . '</h1><div class="notice notice-warning"><p>' . esc_html__( 'Vista de Emails no disponible. Falta el archivo modules/email-engine/views/admin.php.', 'atora-lms' ) . '</p></div></div>';
			}
		);
	}

	// ── Helpers públicos ─────────────────────────────────────────────────────

	/**
	 * Cancela todos los emails de marketing para un usuario.
	 *
	 * @param int $user_id ID del usuario.
	 * @return void
	 */
	public static function unsubscribe_all( int $user_id ): void {
		global $wpdb;

		$wpdb->replace(
			"{$wpdb->prefix}atora_email_preferences",
			array(
				'user_id'          => $user_id,
				'unsubscribed_all' => 1,
				'unsubscribed_at'  => current_time( 'mysql', true ),
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		do_action( 'atora/email/unsubscribed', $user_id );
	}

	/**
	 * Verifica si un usuario acepta un tipo específico de email.
	 *
	 * @param int    $user_id    ID del usuario.
	 * @param string $preference Clave de preferencia.
	 * @return bool
	 */
	public static function user_accepts_emails( int $user_id, string $preference ): bool {
		global $wpdb;

		$prefs = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}atora_email_preferences WHERE user_id = %d LIMIT 1",
			$user_id
		) );

		if ( ! $prefs ) {
			return true; // Sin preferencias guardadas: acepta todo.
		}

		if ( $prefs->unsubscribed_all ) {
			return false;
		}

		$key = sanitize_key( $preference );
		return ! isset( $prefs->$key ) || (bool) $prefs->$key;
	}

	/**
	 * Resuelve la identidad de envío según plantilla y metadata.
	 *
	 * Mantiene compatibilidad interna con las identidades de transporte:
	 * - academia
	 * - teacher  (docencia / seguimiento académico)
	 * - admin    (comercial / leads)
	 *
	 * @param string               $template_key Clave de plantilla.
	 * @param array<string,mixed>  $metadata     Metadata opcional.
	 * @return string
	 */
	public static function resolve_identity_for_template( string $template_key, array $metadata = array() ): string {
		$template_key = sanitize_key( $template_key );
		$metadata     = is_array( $metadata ) ? $metadata : array();
		$to_scalar    = static function ( $value ): string {
			return is_scalar( $value ) ? (string) $value : '';
		};

		$explicit = sanitize_key(
			$to_scalar(
				$metadata['email_identity']
				?? $metadata['identity']
				?? $metadata['identity_key']
				?? ''
			)
		);
		if ( '' !== $explicit ) {
			return Email_Identity_Resolver::normalize( $explicit, 'academia' );
		}

		$academic_templates = array(
			'welcome_course',
			'new_lesson_unlocked',
			'grade_published',
			'certificate_ready',
			'submission_received',
			'program_started',
			'program_module_completed',
			'inactivity_reminder',
		);
		$commercial_templates = array(
			'purchase_confirmation',
			'cart_abandonment',
			'special_offer',
			'new_course_launch',
			'personalized_recommendation',
			'affiliate_commission',
			'newsletter',
			'crm_campaign',
		);
		$admin_templates = array(
			'manual_invitation',
			'password_recovery',
			'account_changes',
			'2fa_code',
			'admin_notice',
			'system_alert',
		);

		$identity = 'academia';
		if ( in_array( $template_key, $commercial_templates, true ) ) {
			$identity = 'admin';
		} elseif ( in_array( $template_key, $admin_templates, true ) ) {
			$identity = 'academia';
		} elseif ( in_array( $template_key, $academic_templates, true ) ) {
			$identity = 'academia';
		} elseif ( class_exists( '\ATORA\EmailEngine\Email_Templates' ) && method_exists( '\ATORA\EmailEngine\Email_Templates', 'get' ) ) {
			$template = \ATORA\EmailEngine\Email_Templates::get( $template_key );
			$category = sanitize_key( (string) ( $template['category'] ?? '' ) );
			if ( in_array( $category, array( 'commercial', 'affiliates' ), true ) ) {
				$identity = 'admin';
			} elseif ( in_array( $category, array( 'system', 'security', 'admin' ), true ) ) {
				$identity = 'academia';
			}
		}

		/**
		 * Permite customizar la identidad resuelta por plantilla.
		 *
		 * @param string              $identity     Identidad resuelta.
		 * @param string              $template_key Template solicitado.
		 * @param array<string,mixed> $metadata     Metadata del envío.
		 */
		$identity = apply_filters( 'atora/email/resolve_identity', $identity, $template_key, $metadata );
		return Email_Identity_Resolver::normalize( (string) $identity, 'academia' );
	}

	// ── Parseo de webhooks ────────────────────────────────────────────────────

	/**
	 * Parsea los eventos según el formato de cada provider.
	 *
	 * @param string           $provider Provider.
	 * @param \WP_REST_Request $request  Request.
	 * @return array
	 */
	private static function parse_webhook_events( string $provider, \WP_REST_Request $request ): array {
		$body   = $request->get_json_params() ?? array();
		$events = array();

		switch ( $provider ) {
			case 'brevo':
				// Brevo envía un array de eventos.
				$items = is_array( $body ) && isset( $body[0] ) ? $body : array( $body );
				foreach ( $items as $item ) {
					$events[] = array(
						'type'     => self::normalize_event_type( $item['event'] ?? '' ),
						'queue_id' => absint( $item['X-Mailin-custom'] ?? $item['tags'][0] ?? 0 ),
						'raw'      => $item,
					);
				}
				break;

			case 'sendgrid':
				foreach ( (array) $body as $item ) {
					$events[] = array(
						'type'     => self::normalize_event_type( $item['event'] ?? '' ),
						'queue_id' => absint( $item['atora_queue_id'] ?? 0 ),
						'raw'      => $item,
					);
				}
				break;

			case 'mailgun':
				$events[] = array(
					'type'     => self::normalize_event_type( $body['event-data']['event'] ?? '' ),
					'queue_id' => absint( $body['event-data']['user-variables']['atora_queue_id'] ?? 0 ),
					'raw'      => $body,
				);
				break;

			case 'postmark':
				$events[] = array(
					'type'     => self::normalize_event_type( $body['RecordType'] ?? '' ),
					'queue_id' => absint( $body['Metadata']['atora_queue_id'] ?? 0 ),
					'raw'      => $body,
				);
				break;

			default:
				// SES usa SNS, se parsea el cuerpo SNS.
				$sns_message = json_decode( $body['Message'] ?? '{}', true );
				$events[]    = array(
					'type'     => self::normalize_event_type( $sns_message['notificationType'] ?? '' ),
					'queue_id' => absint( $sns_message['mail']['headers'][0]['value'] ?? 0 ),
					'raw'      => $body,
				);
				break;
		}

		return $events;
	}

	/**
	 * Normaliza los nombres de eventos de distintos providers a tipos internos.
	 *
	 * @param string $raw_type Tipo del provider.
	 * @return string
	 */
	private static function normalize_event_type( string $raw_type ): string {
		$map = array(
			// Delivered.
			'delivered'  => 'delivered',
			'Delivery'   => 'delivered',
			'delivery'   => 'delivered',

			// Opens.
			'open'       => 'opened',
			'opens'      => 'opened',
			'opened'     => 'opened',
			'Open'       => 'opened',

			// Clicks.
			'click'      => 'clicked',
			'clicks'     => 'clicked',
			'clicked'    => 'clicked',
			'Click'      => 'clicked',

			// Bounces.
			'bounce'     => 'bounced',
			'bounced'    => 'bounced',
			'Bounce'     => 'bounced',
			'hard_bounce'=> 'bounced',

			// Spam complaints.
			'spam'       => 'complained',
			'complaint'  => 'complained',
			'SpamComplaint' => 'complained',
			'Complaint'  => 'complained',
		);

		return $map[ $raw_type ] ?? sanitize_key( $raw_type );
	}
}
