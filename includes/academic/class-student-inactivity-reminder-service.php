<?php
/**
 * Recordatorios de inactividad para estudiantes.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Student_Inactivity_Reminder_Service {

	const CRON_HOOK      = 'clms_student_inactivity_reminder_daily';
	const META_LAST_SENT = '_clms_last_inactivity_reminder_';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'maybe_schedule_cron' ), 20 );
		add_action( self::CRON_HOOK, array( $this, 'run_daily_check' ) );
	}

	/**
	 * Programa cron diario.
	 *
	 * @return void
	 */
	public function maybe_schedule_cron() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( strtotime( 'tomorrow 07:00:00' ), 'daily', self::CRON_HOOK );
	}

	/**
	 * Ejecuta validación y envío de recordatorios.
	 *
	 * @return array<string,mixed>
	 */
	public function run_daily_check() {
		$settings = $this->get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return array(
				'enabled'  => false,
				'checked'  => 0,
				'notified' => 0,
			);
		}

		$courses = $this->get_candidate_courses();
		if ( empty( $courses ) ) {
			return array(
				'enabled'  => true,
				'checked'  => 0,
				'notified' => 0,
			);
		}

		$tracker = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Student_Activity_Tracker') : null;
		$checked = 0;
		$sent    = 0;

		foreach ( $courses as $course_id ) {
			$student_ids = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_enrolled_student_ids' )
				? CLMS_Helper::get_enrolled_student_ids( $course_id )
				: array();
			if ( empty( $student_ids ) ) {
				continue;
			}

			foreach ( $student_ids as $student_id ) {
				$student_id = absint( $student_id );
				if ( ! $student_id ) {
					continue;
				}
				++$checked;

				$user = get_userdata( $student_id );
				if ( ! $user || ! is_email( $user->user_email ) ) {
					continue;
				}
				if ( ! $this->can_send_reminder( $student_id, $course_id, $settings ) ) {
					continue;
				}

				$summary = $this->get_activity_summary( $tracker, $student_id, $course_id );
				$days_inactive = $this->get_days_inactive( $summary );
				if ( $days_inactive < $settings['days_threshold'] ) {
					continue;
				}

				// PT-2.4 (6.4.0): detrás del flag maestro de enrutamiento
				// académico. Con el flag apagado (default), llama al mismo
				// método que en 6.3.0 — comportamiento idéntico.
				$reminder_sent = $this->should_use_router()
					? $this->send_reminder_via_router( $user, $course_id, $days_inactive, $settings )
					: $this->send_reminder_email( $user, $course_id, $days_inactive, $settings );

				if ( $reminder_sent ) {
					update_user_meta( $student_id, self::META_LAST_SENT . $course_id, time() );
					++$sent;
					do_action(
						'clms_student_inactivity_reminder_sent',
						$student_id,
						$course_id,
						array(
							'days_inactive' => $days_inactive,
						)
					);
				}
			}
		}

		return array(
			'enabled'  => true,
			'checked'  => $checked,
			'notified' => $sent,
		);
	}

	/**
	 * Obtiene cursos a revisar.
	 *
	 * @return array<int,int>
	 */
	protected function get_candidate_courses() {
		$max_courses = absint( apply_filters( 'clms_inactivity_reminder_max_courses', 100 ) );
		$args = array(
			'post_type'      => 'lm_course',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);
		if ( $max_courses > 0 ) {
			$args['posts_per_page'] = $max_courses;
		} else {
			$args['posts_per_page'] = -1;
		}

		$course_ids = get_posts( $args );
		return array_values( array_filter( array_map( 'absint', (array) $course_ids ) ) );
	}

	/**
	 * Obtiene configuración consolidada.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_settings() {
		$advanced = class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_advanced_settings' )
			? CLMS_Settings::get_advanced_settings()
			: (array) get_option( 'clms_advanced_settings', array() );

		$days_threshold = isset( $advanced['inactivity_days_threshold'] ) ? absint( $advanced['inactivity_days_threshold'] ) : absint( get_option( 'clms_inactivity_days_threshold', 14 ) );
		$days_threshold = max( 1, min( 90, $days_threshold ) );

		$settings = array(
			'enabled'         => ! empty( $advanced['inactivity_email_enabled'] ),
			'days_threshold'  => $days_threshold,
			'cooldown_hours'  => max( 1, min( 720, absint( $advanced['inactivity_email_cooldown_hours'] ?? 72 ) ) ),
			'subject'         => sanitize_text_field( (string) ( $advanced['inactivity_email_subject'] ?? '' ) ),
			'headline'        => sanitize_text_field( (string) ( $advanced['inactivity_email_headline'] ?? '' ) ),
			'button_text'     => sanitize_text_field( (string) ( $advanced['inactivity_email_button_text'] ?? '' ) ),
			'footer_note'     => sanitize_text_field( (string) ( $advanced['inactivity_email_footer_note'] ?? '' ) ),
			'body'            => sanitize_textarea_field( (string) ( $advanced['inactivity_email_body'] ?? '' ) ),
		);

		if ( '' === $settings['subject'] ) {
			$settings['subject'] = 'Te extrañamos en {course_title}';
		}
		if ( '' === $settings['headline'] ) {
			$settings['headline'] = 'Retoma tu ruta de aprendizaje';
		}
		if ( '' === $settings['button_text'] ) {
			$settings['button_text'] = 'Continuar curso';
		}
		if ( '' === $settings['footer_note'] ) {
			$settings['footer_note'] = 'Este recordatorio fue enviado automáticamente por tu academia.';
		}
		if ( '' === $settings['body'] ) {
			$settings['body'] = 'Hola {student_name}, notamos que llevas {days_inactive} días sin ingresar a {course_title}. Te recomendamos retomar hoy con un bloque corto de estudio para mantener tu avance.';
		}

		return apply_filters( 'clms_inactivity_reminder_settings', $settings );
	}

	/**
	 * Determina si puede enviarse un nuevo recordatorio.
	 *
	 * @param int                $student_id Estudiante.
	 * @param int                $course_id  Curso.
	 * @param array<string,mixed> $settings  Ajustes.
	 * @return bool
	 */
	protected function can_send_reminder( $student_id, $course_id, $settings ) {
		$last_sent = absint( get_user_meta( $student_id, self::META_LAST_SENT . $course_id, true ) );
		if ( ! $last_sent ) {
			return true;
		}

		$cooldown_seconds = max( HOUR_IN_SECONDS, absint( $settings['cooldown_hours'] ) * HOUR_IN_SECONDS );
		return ( time() - $last_sent ) >= $cooldown_seconds;
	}

	/**
	 * Resumen de actividad desde tracker o fallback.
	 *
	 * @param object|null $tracker    Tracker.
	 * @param int         $student_id Estudiante.
	 * @param int         $course_id  Curso.
	 * @return array<string,mixed>
	 */
	protected function get_activity_summary( $tracker, $student_id, $course_id ) {
		if ( $tracker && method_exists( $tracker, 'get_student_activity_summary' ) ) {
			return (array) $tracker->get_student_activity_summary( $student_id, $course_id );
		}

		return array(
			'last_access'        => sanitize_text_field( (string) get_user_meta( $student_id, '_clms_last_access_at', true ) ),
			'course_last_access' => sanitize_text_field( (string) get_user_meta( $student_id, '_clms_last_access_course_' . $course_id, true ) ),
		);
	}

	/**
	 * Calcula días de inactividad.
	 *
	 * @param array<string,mixed> $summary Resumen.
	 * @return int
	 */
	protected function get_days_inactive( $summary ) {
		$raw_date = ! empty( $summary['course_last_access'] )
			? sanitize_text_field( (string) $summary['course_last_access'] )
			: sanitize_text_field( (string) ( $summary['last_access'] ?? '' ) );

		if ( '' === $raw_date ) {
			return 9999;
		}

		$timestamp = strtotime( $raw_date );
		if ( ! $timestamp ) {
			return 9999;
		}

		return max( 0, (int) floor( ( time() - $timestamp ) / DAY_IN_SECONDS ) );
	}

	/**
	 * Envía recordatorio usando CLMS_Email (plantilla institucional).
	 *
	 * @param WP_User            $user          Usuario.
	 * @param int                $course_id     Curso.
	 * @param int                $days_inactive Días.
	 * @param array<string,mixed> $settings      Ajustes.
	 * @return bool
	 */
	protected function send_reminder_email( WP_User $user, $course_id, $days_inactive, $settings ) {
		$course_id     = absint( $course_id );
		$course_title  = sanitize_text_field( (string) get_the_title( $course_id ) );
		$student_name  = sanitize_text_field( (string) $user->display_name );
		$academy_name  = class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_academy_settings' )
			? sanitize_text_field( (string) ( CLMS_Settings::get_academy_settings()['academy_name'] ?? '' ) )
			: sanitize_text_field( (string) get_bloginfo( 'name' ) );

		$vars = array(
			'{student_name}'  => $student_name,
			'{course_title}'  => $course_title,
			'{days_inactive}' => (string) absint( $days_inactive ),
			'{academy_name}'  => $academy_name,
		);

		$subject     = strtr( (string) $settings['subject'], $vars );
		$headline    = strtr( (string) $settings['headline'], $vars );
		$button_text = strtr( (string) $settings['button_text'], $vars );
		$footer_note = strtr( (string) $settings['footer_note'], $vars );
		$body        = strtr( (string) $settings['body'], $vars );

		$button_url = $this->resolve_continue_url( $user->ID, $course_id );
		$payload = array(
			'headline'    => $headline,
			'button_text' => $button_text,
			'button_url'  => $button_url,
			'footer_note' => $footer_note,
		);

		$payload = apply_filters(
			'clms_inactivity_reminder_email_payload',
			$payload,
			$user->ID,
			$course_id,
			array(
				'days_inactive' => $days_inactive,
				'subject'       => $subject,
				'body'          => $body,
			)
		);

		return CLMS_Email::send(
			$user->user_email,
			$subject,
			$body,
			$payload
		);
	}

	/**
	 * PT-2.4/2.5 (6.4.0): único punto de decisión de qué método envía
	 * el recordatorio — extraído para poder probarlo en aislamiento sin
	 * mockear todo run_daily_check(). Con el flag apagado (default) o
	 * la clase Messaging_Router ausente, siempre false: comportamiento
	 * 6.3.0 exacto.
	 *
	 * @return bool
	 */
	protected function should_use_router() {
		return class_exists( '\ATORA\Messaging\Messaging_Router' )
			&& \ATORA\Messaging\Messaging_Router::is_academic_routing_enabled();
	}

	/**
	 * PT-2.1/2.3 (6.4.0): mismo recordatorio, despachado vía
	 * Messaging_Router::send() en vez de CLMS_Email::send() directo.
	 * Multicanal (WhatsApp primero si hay consentimiento verificado,
	 * cae a email si no), con deduplicación y cola. Solo se usa si
	 * Messaging_Router::is_academic_routing_enabled() — ver
	 * run_daily_check().
	 *
	 * Se usa Messaging_Router::send() y NO enqueue() directo a
	 * propósito: send() resuelve el canal filtrando por consentimiento
	 * verificado (resolve_channels()/user_accepts_channel()) antes de
	 * encolar — enqueue() por sí solo no lo hace para el intento
	 * primario. Es la única forma de cumplir la regla 4 del sprint
	 * ("ningún mensaje sale sin consentimiento verificado, sin
	 * excepción") sin re-implementar ese chequeo aquí.
	 *
	 * @param WP_User             $user          Usuario.
	 * @param int                 $course_id     Curso.
	 * @param int                 $days_inactive Días.
	 * @param array<string,mixed> $settings      Ajustes (mismos subject/headline/button_text/footer_note/body que el canal email).
	 * @return bool
	 */
	protected function send_reminder_via_router( WP_User $user, $course_id, $days_inactive, $settings ) {
		$course_id    = absint( $course_id );
		$course_title = sanitize_text_field( (string) get_the_title( $course_id ) );
		$student_name = sanitize_text_field( (string) $user->display_name );
		$academy_name = class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_academy_settings' )
			? sanitize_text_field( (string) ( CLMS_Settings::get_academy_settings()['academy_name'] ?? '' ) )
			: sanitize_text_field( (string) get_bloginfo( 'name' ) );

		$vars = array(
			'{student_name}'  => $student_name,
			'{course_title}'  => $course_title,
			'{days_inactive}' => (string) absint( $days_inactive ),
			'{academy_name}'  => $academy_name,
		);

		$button_url = $this->resolve_continue_url( $user->ID, $course_id );

		// Las mismas variables configurables que el canal email (PT-2.3)
		// — quien construya la plantilla de email en Email Engine con
		// template_key 'atora_student_inactive' las usa igual que hoy;
		// la plantilla de WhatsApp usa la lista posicional documentada
		// en docs/PLANTILLAS-WHATSAPP.md.
		$variables = array(
			'student_name'  => $student_name,
			'course_title'  => $course_title,
			'days_inactive' => absint( $days_inactive ),
			'academy_name'  => $academy_name,
			'subject'       => strtr( (string) $settings['subject'], $vars ),
			'headline'      => strtr( (string) $settings['headline'], $vars ),
			'button_text'   => strtr( (string) $settings['button_text'], $vars ),
			'footer_note'   => strtr( (string) $settings['footer_note'], $vars ),
			'body'          => strtr( (string) $settings['body'], $vars ),
			'button_url'    => $button_url,
		);

		// PT-2.2: dedupe_key reemplaza el control manual del router en
		// adelante; META_LAST_SENT se conserva en paralelo (ver
		// run_daily_check()) durante un ciclo para comparar, igual que
		// se hizo con la paridad de F4 en el sprint anterior.
		return \ATORA\Messaging\Messaging_Router::send(
			$user->ID,
			'student_inactive',
			'atora_student_inactive',
			$variables,
			array(
				'dedupe_key'            => "inactive_{$user->ID}_{$course_id}",
				'dedupe_window_minutes' => max( 60, absint( $settings['cooldown_hours'] ) * 60 ),
			)
		);
	}

	/**
	 * URL de continuidad recomendada.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $course_id Curso.
	 * @return string
	 */
	protected function resolve_continue_url( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		$lesson_ids = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_lessons' )
			? CLMS_Helper::get_course_lessons( $course_id )
			: array();
		$lesson_ids = array_values( array_filter( array_map( 'absint', (array) $lesson_ids ) ) );

		if ( ! empty( $lesson_ids ) ) {
			$completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
			$completed = array_values( array_filter( array_map( 'absint', (array) $completed ) ) );
			foreach ( $lesson_ids as $lesson_id ) {
				if ( ! in_array( $lesson_id, $completed, true ) ) {
					$url = get_permalink( $lesson_id );
					if ( is_string( $url ) && '' !== $url ) {
						return $url;
					}
					break;
				}
			}
		}

		$url = get_permalink( $course_id );
		return is_string( $url ) ? $url : '';
	}
}

