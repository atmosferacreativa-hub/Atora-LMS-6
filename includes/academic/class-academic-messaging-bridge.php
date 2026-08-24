<?php
/**
 * CLMS_Academic_Messaging_Bridge — PT-3 (sprint 6.4.0)
 *
 * Conecta los eventos académicos que ya existen en el código (grado
 * publicado, entrega recibida, lección publicada, alertas de IA) con
 * Messaging_Router::send(), sin tocar ni duplicar los listeners
 * existentes (CLMS_Messaging sigue intacto — este puente es un
 * segundo listener en paralelo sobre los mismos hooks, no un
 * reemplazo).
 *
 * Todo handler de aquí es un no-op si
 * Messaging_Router::is_academic_routing_enabled() es false (default)
 * — regla 3 del sprint: cero cambio de comportamiento hasta que un
 * admin active el flag.
 *
 * @package ATORA_LMS
 * @since   6.4.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Academic_Messaging_Bridge {

	public static function init(): void {
		if ( ! class_exists( '\ATORA\Messaging\Messaging_Router' ) ) { return; }

		// PT-3.1 — assignment_graded.
		add_action( 'clms_grade_published', array( __CLASS__, 'on_grade_published' ) );

		// PT-3.2 — submission_received.
		add_action( 'clms_submission_created', array( __CLASS__, 'on_submission_created' ), 10, 3 );

		// PT-3.4 — at_risk_flagged. Señal real: las alertas diarias de
		// CLMS_AI_Alerts (ver DEUDA-TECNICA.md — no existe un evento de
		// "marcar en riesgo" propio). Estos hooks YA tienen un listener
		// que notifica al estudiante (CLMS_Messaging, in-app) — este es
		// un segundo listener independiente que notifica docente +
		// coordinador, nunca al estudiante (PT-1.2).
		add_action( 'clms_ai_inactivity_alert_generated', array( __CLASS__, 'on_at_risk_signal' ), 10, 3 );
		add_action( 'clms_ai_low_grade_alert_generated', array( __CLASS__, 'on_at_risk_signal' ), 10, 3 );

		// PT-3.6 — lesson_published. Prioridad 30: después de
		// CLMS_Notifications (10) y CLMS_Messaging (20), mismo hook,
		// tercer listener independiente.
		add_action( 'transition_post_status', array( __CLASS__, 'on_lesson_published' ), 30, 3 );

		// PT-5 (6.6.0) — aviso al docente el día de una ocurrencia de un
		// plan de seguimiento. Mismo hook diario ya usado por el resto
		// del plugin (Calendar::cleanup_old_events(), 2FA, Abandoned
		// Cart) — modules/calendar/ en sí no envía recordatorios propios
		// hoy (confirmado: ningún Messaging_Router::send() en ese
		// árbol), así que no hay un canal de "recordatorio de
		// calendario" existente que extender; este puente de mensajería
		// académica es el punto de integración correcto y ya
		// establecido para el resto de las señales académicas.
		add_action( 'atora_daily_cron', array( __CLASS__, 'on_followup_occurrences_due_today' ) );
	}

	/**
	 * PT-5.1/5.2: recorre las ocurrencias de planes de seguimiento
	 * programadas para HOY y avisa a cada docente cuántos estudiantes
	 * le tocan y de qué secciones — nunca si la ocurrencia queda vacía
	 * (PT-5.2, criterio de aceptación explícito: "ningún aviso se
	 * dispara para una ocurrencia sin destinatarios").
	 *
	 * @return void
	 */
	public static function on_followup_occurrences_due_today(): void {
		if ( ! \ATORA\Messaging\Messaging_Router::is_academic_routing_enabled() ) { return; }
		if ( ! class_exists( '\ATORA\CRM_V2\Services\Followup_Plan_Resolver' ) ) { return; }

		global $wpdb;
		$today = current_time( 'Y-m-d' );
		$events_table = $wpdb->prefix . 'atora_calendar_events';

		$occurrences = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, followup_plan_id, user_id AS teacher_id, title
				 FROM {$events_table}
				 WHERE followup_plan_id IS NOT NULL AND DATE(start_datetime) = %s",
				$today
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( (array) $occurrences as $occurrence ) {
			$event_id   = absint( $occurrence['id'] ?? 0 );
			$plan_id    = absint( $occurrence['followup_plan_id'] ?? 0 );
			$teacher_id = absint( $occurrence['teacher_id'] ?? 0 );
			if ( ! $event_id || ! $plan_id || ! $teacher_id ) { continue; }

			$resolution = \ATORA\CRM_V2\Services\Followup_Plan_Resolver::resolve_recipients( $plan_id, $today, $event_id );
			$students   = (array) ( $resolution['students'] ?? array() );

			// PT-5.2: ocurrencia vacía -> sin aviso, sin excepción.
			if ( empty( $students ) ) { continue; }

			if ( ! \ATORA\Messaging\Messaging_Router::under_recipient_cap( $teacher_id ) ) { continue; }

			$section_names = array_values( array_unique( array_map(
				static fn( $s ) => (string) ( $s['title'] ?? '' ),
				(array) ( $resolution['sections'] ?? array() )
			) ) );

			\ATORA\Messaging\Messaging_Router::send(
				$teacher_id,
				'followup_occurrence_due',
				'atora_followup_occurrence_due',
				array(
					'teacher_name'  => self::display_name( $teacher_id ),
					'student_count' => count( $students ),
					'section_names' => implode( ', ', array_filter( $section_names ) ),
					'button_url'    => admin_url( 'admin.php?page=atora-followup-plans&event_id=' . $event_id ),
				),
				array(
					'dedupe_key'            => "followup_occurrence_due_{$event_id}",
					'dedupe_window_minutes' => 24 * 60,
				)
			);
		}
	}

	/**
	 * PT-3.6: lección publicada → estudiantes matriculados en el curso.
	 * Prioridad 'low' (candidato natural a resumen, PT-5) — sin el
	 * agrupador todavía construido, sale como mensaje individual, igual
	 * que hoy hacen CLMS_Notifications/CLMS_Messaging para este mismo
	 * evento.
	 *
	 * Nota: se notifica a nivel de curso (course-wide), no de sección
	 * — igual que los dos listeners existentes sobre este hook. Una
	 * lección no tiene una sección propia en el modelo de datos actual
	 * (pertenece al curso); "estudiantes de la sección" de la OT no
	 * tiene una asignación 1:1 sin ambigüedad aquí.
	 *
	 * @param string   $new_status
	 * @param string   $old_status
	 * @param \WP_Post $post
	 */
	public static function on_lesson_published( $new_status, $old_status, $post ): void {
		if ( ! \ATORA\Messaging\Messaging_Router::is_academic_routing_enabled() ) { return; }
		if ( 'publish' !== $new_status || 'publish' === $old_status ) { return; }
		if ( ! is_object( $post ) || 'lm_lesson' !== ( $post->post_type ?? '' ) ) { return; }

		$lesson_id = absint( $post->ID );
		$course_id = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_id_from_lesson' )
			? absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) )
			: 0;
		if ( ! $course_id ) { return; }

		$student_ids = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_enrolled_student_ids' )
			? (array) CLMS_Helper::get_enrolled_student_ids( $course_id )
			: array();

		$lesson_title = sanitize_text_field( (string) get_the_title( $lesson_id ) );
		$course_title = sanitize_text_field( (string) get_the_title( $course_id ) );
		$button_url   = (string) get_permalink( $lesson_id );

		foreach ( $student_ids as $student_id ) {
			$student_id = absint( $student_id );
			if ( ! $student_id ) { continue; }

			// PT-3.7: exactamente el escenario del ejemplo de la OT — un
			// docente que publica varias lecciones seguidas no debe
			// generar una notificación por cada una.
			if ( ! \ATORA\Messaging\Messaging_Router::under_recipient_cap( $student_id ) ) { continue; }

			\ATORA\Messaging\Messaging_Router::send(
				$student_id,
				'lesson_published',
				'atora_lesson_published',
				array(
					'student_name' => self::display_name( $student_id ),
					'lesson_title' => $lesson_title,
					'course_title' => $course_title,
					'button_url'   => $button_url,
				),
				array(
					'dedupe_key'            => "lesson_published_{$lesson_id}_{$student_id}",
					'dedupe_window_minutes' => 24 * 60,
				)
			);
		}
	}

	/**
	 * PT-3.4: estudiante en riesgo → docente + coordinador de su
	 * sección, nunca al estudiante mismo.
	 *
	 * @param int   $student_id
	 * @param int   $course_id
	 * @param array $payload
	 */
	public static function on_at_risk_signal( $student_id, $course_id, $payload = array() ): void {
		if ( ! \ATORA\Messaging\Messaging_Router::is_academic_routing_enabled() ) { return; }

		// PT-1.2, defensa en profundidad: aunque el resto de este
		// método nunca use $student_id como destinatario, se verifica
		// explícitamente antes de construir la lista de envío.
		if ( ! \ATORA\Messaging\Messaging_Router::is_never_student_facing( 'at_risk_flagged' ) ) {
			return; // el tipo dejó de estar en la lista de bloqueo — no enviar sin revisar por qué.
		}

		$student_id = absint( $student_id );
		$course_id  = absint( $course_id );
		if ( ! $student_id || ! $course_id ) { return; }

		$reason = isset( $payload['days_inactive'] )
			? sprintf( '%d días de inactividad', absint( $payload['days_inactive'] ) )
			: __( 'promedio bajo', 'atora-lms' );

		// Resolución correcta de docente (Section_Service), no el
		// post_author ingenuo que usa CLMS_AI_Alerts::run_daily_check()
		// para su propio digest (ese código no se toca en este sprint).
		$recipients = array();
		if ( class_exists( '\ATORA\LMS\Section_Service' ) ) {
			$teacher = \ATORA\LMS\Section_Service::get_effective_instructor( $student_id, $course_id );
			if ( $teacher && $teacher !== $student_id ) {
				$recipients[] = (int) $teacher;
			}
			$coordinator = \ATORA\LMS\Section_Service::get_effective_coordinator( $student_id, $course_id );
			if ( $coordinator && $coordinator !== $student_id && ! in_array( (int) $coordinator, $recipients, true ) ) {
				$recipients[] = (int) $coordinator;
			}
		}

		if ( empty( $recipients ) ) { return; }

		$variables = array(
			'student_name' => self::display_name( $student_id ),
			'course_title' => sanitize_text_field( (string) get_the_title( $course_id ) ),
			'reason'       => $reason,
			'button_url'   => admin_url( 'admin.php?page=clms-academic-content&student_id=' . $student_id ),
		);

		foreach ( $recipients as $recipient_id ) {
			if ( ! \ATORA\Messaging\Messaging_Router::under_recipient_cap( $recipient_id ) ) { continue; }

			\ATORA\Messaging\Messaging_Router::send(
				$recipient_id,
				'at_risk_flagged',
				'atora_at_risk_teacher',
				array_merge( $variables, array( 'recipient_name' => self::display_name( $recipient_id ) ) ),
				array(
					'dedupe_key'            => "at_risk_{$student_id}_{$course_id}_{$recipient_id}",
					'dedupe_window_minutes' => 24 * 60,
				)
			);
		}
	}

	/**
	 * PT-3.2: al recibir una entrega → docente de la sección.
	 *
	 * @param int $submission_id
	 * @param int $lesson_id
	 * @param int $student_id
	 */
	public static function on_submission_created( $submission_id, $lesson_id, $student_id ): void {
		if ( ! \ATORA\Messaging\Messaging_Router::is_academic_routing_enabled() ) { return; }

		$lesson_id  = absint( $lesson_id );
		$student_id = absint( $student_id );
		if ( ! $lesson_id || ! $student_id ) { return; }

		$course_id = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_id_from_lesson' )
			? absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) )
			: 0;
		if ( ! $course_id ) { return; }

		$teacher_id = class_exists( '\ATORA\LMS\Section_Service' )
			? \ATORA\LMS\Section_Service::get_effective_instructor( $student_id, $course_id )
			: null;
		if ( ! $teacher_id ) { return; }

		\ATORA\Messaging\Messaging_Router::send(
			(int) $teacher_id,
			'submission_received',
			'atora_submission_received',
			array(
				'teacher_name'    => self::display_name( (int) $teacher_id ),
				'student_name'    => self::display_name( $student_id ),
				'lesson_title'    => sanitize_text_field( (string) get_the_title( $lesson_id ) ),
				'course_title'    => sanitize_text_field( (string) get_the_title( $course_id ) ),
				'button_url'      => (string) get_permalink( $lesson_id ),
			),
			array(
				'dedupe_key'            => "submission_received_{$submission_id}",
				'dedupe_window_minutes' => 15,
			)
		);
	}

	/**
	 * PT-3.1: al publicarse una calificación → estudiante.
	 *
	 * @param array $payload {submission_id, student_id, lesson_id, course_id, status, grade, feedback, source, confidence, provider, model_version, is_published} — ver CLMS_Assessment_Engine::publish_submission_grade().
	 */
	public static function on_grade_published( array $payload ): void {
		if ( ! \ATORA\Messaging\Messaging_Router::is_academic_routing_enabled() ) { return; }

		$student_id = absint( $payload['student_id'] ?? 0 );
		$lesson_id  = absint( $payload['lesson_id'] ?? 0 );
		$course_id  = absint( $payload['course_id'] ?? 0 );
		if ( ! $student_id || ! $course_id ) { return; }

		$grade = $payload['grade'] ?? null;
		if ( null === $grade || '' === $grade ) { return; }

		\ATORA\Messaging\Messaging_Router::send(
			$student_id,
			'assignment_graded',
			'atora_assignment_graded',
			array(
				'student_name'  => self::display_name( $student_id ),
				'course_title'  => sanitize_text_field( (string) get_the_title( $course_id ) ),
				'lesson_title'  => sanitize_text_field( (string) get_the_title( $lesson_id ) ),
				'grade'         => sanitize_text_field( (string) $grade ),
				'button_url'    => (string) get_permalink( $lesson_id ),
			),
			array(
				// dedupe por submission: si se re-publica el mismo grade
				// (recalificación) dentro de la ventana, no duplica aviso.
				'dedupe_key'            => 'assignment_graded_' . absint( $payload['submission_id'] ?? 0 ),
				'dedupe_window_minutes' => 15,
			)
		);
	}

	/**
	 * @param int $user_id
	 * @return string
	 */
	private static function display_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? sanitize_text_field( (string) $user->display_name ) : '';
	}
}
