<?php
/**
 * CLMS_AI_Alerts — Alertas proactivas generadas por IA
 *
 * Cron diario que detecta:
 *   - Alumnos inactivos X días (sin completar lecciones)
 *   - Alumnos con calificaciones por debajo del umbral
 *   - Cursos con alta tasa de abandono
 *
 * Por cada alerta genera un email personalizado con IA y lo envía.
 * Los profesores reciben un digest diario con el resumen del grupo.
 *
 * Configuración (wp_options: clms_ai_alerts_settings):
 *   inactivity_days   int   Días sin actividad para alertar (default 7)
 *   low_grade_pct     int   Porcentaje mínimo aprobatorio (default 50)
 *   enabled           bool  Activo/inactivo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_AI_Alerts {

	const CRON_HOOK      = 'clms_ai_alerts_daily';
	const OPTION_SETTINGS = 'clms_ai_alerts_settings';
	const META_LAST_ALERT = '_clms_last_alert_sent';
	const MIN_HOURS_BTW_ALERTS = 48; // No molestar más de una vez cada 2 días

	public function __construct() {
		add_action( self::CRON_HOOK,       array( $this, 'run_daily_check' ) );
		add_action( 'wp',                  array( $this, 'maybe_schedule_cron' ) );
		add_action( 'wp_ajax_clms_alerts_test', array( $this, 'ajax_test_run' ) );
		add_action( 'admin_init',          array( $this, 'register_settings' ) );
	}

	// ── Cron ─────────────────────────────────────────────────────────────────────

	public function maybe_schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Ejecutar cada día a las 8:00 AM UTC
			wp_schedule_event( strtotime( 'tomorrow 08:00:00' ), 'daily', self::CRON_HOOK );
		}
	}

	public function ajax_test_run() {
		if ( ! CLMS_Access::can_access_admin() ) {
			wp_send_json_error( 'Sin permisos.', 403 );
		}
		check_ajax_referer( 'clms_alerts_test' );
		$results = $this->run_daily_check( true );
		wp_send_json_success( $results );
	}

	public function run_daily_check( $dry_run = false ) {
		$settings = $this->get_settings();
		if ( ! $settings['enabled'] && ! $dry_run ) {
			return array();
		}

		$results = array(
			'inactivity'  => array(),
			'low_grade'   => array(),
			'digest_sent' => array(),
		);

		$courses = get_posts( array(
			'post_type'      => 'lm_course',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		foreach ( $courses as $course_id ) {
			$enrolled_ids = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_enrolled_student_ids' )
				? CLMS_Helper::get_enrolled_student_ids( $course_id )
				: array();

			if ( empty( $enrolled_ids ) ) {
				continue;
			}

			$inactives  = array();
			$low_grades = array();

			foreach ( $enrolled_ids as $user_id ) {
				$user = get_userdata( $user_id );
				if ( ! $user ) { continue; }

				// Comprobar inactividad
				$last_activity = $this->get_last_activity( $user_id, $course_id );
				$days_inactive = $last_activity
					? (int) floor( ( time() - $last_activity ) / DAY_IN_SECONDS )
					: PHP_INT_MAX;

				if ( $days_inactive >= $settings['inactivity_days'] ) {
					$inactives[] = array(
						'user_id'       => $user_id,
						'user_name'     => $user->display_name,
						'user_email'    => $user->user_email,
						'days_inactive' => $days_inactive,
					);
				}

				// Comprobar nota baja reutilizando la instancia central de grading.
				$grading = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading') : null;

				if ( $grading && method_exists( $grading, 'get_course_grade_summary' ) ) {
					$summary = $grading->get_course_grade_summary( $user_id, $course_id );
					$avg     = isset( $summary['overall_average'] ) ? (float) $summary['overall_average'] : -1;

					if ( $avg >= 0 && $avg < $settings['low_grade_pct'] ) {
						$low_grades[] = array(
							'user_id'    => $user_id,
							'user_name'  => $user->display_name,
							'user_email' => $user->user_email,
							'average'    => round( $avg, 1 ),
						);
					}
				}
			}

			// Alertas de inactividad
			foreach ( $inactives as $student ) {
				$sent = $this->send_inactivity_alert( $student, $course_id, $dry_run );
				if ( $sent ) {
					$results['inactivity'][] = array(
						'course_id' => $course_id,
						'user_id'   => $student['user_id'],
						'days'      => $student['days_inactive'],
					);
				}
			}

			// Alertas de nota baja
			foreach ( $low_grades as $student ) {
				$sent = $this->send_low_grade_alert( $student, $course_id, $dry_run );
				if ( $sent ) {
					$results['low_grade'][] = array(
						'course_id' => $course_id,
						'user_id'   => $student['user_id'],
						'average'   => $student['average'],
					);
				}
			}

			// Digest para el profesor
			if ( ! empty( $inactives ) || ! empty( $low_grades ) ) {
				$sent = $this->send_teacher_digest( $course_id, $inactives, $low_grades, $dry_run );
				if ( $sent ) {
					$results['digest_sent'][] = $course_id;
				}
			}
		}

		return $results;
	}

	// ── Generación de emails con IA ───────────────────────────────────────────────

	protected function send_inactivity_alert( $student, $course_id, $dry_run ) {
		$user_id = $student['user_id'];

		// Anti-spam: no enviar si ya se envió hace menos de MIN_HOURS
		$last = (int) get_user_meta( $user_id, self::META_LAST_ALERT . '_inactivity_' . $course_id, true );
		if ( $last && ( time() - $last ) < ( self::MIN_HOURS_BTW_ALERTS * HOUR_IN_SECONDS ) ) {
			return false;
		}

		$course_title = get_the_title( $course_id );
		$name         = $student['user_name'];
		$days         = $student['days_inactive'];

		// Obtener próxima lección recomendada
		$next_lesson = $this->get_next_pending_lesson( $user_id, $course_id );
		$next_title  = $next_lesson ? get_the_title( $next_lesson ) : '';

		$body = $this->generate_email_with_ai(
			"Escribe un email breve y motivador (máx 3 párrafos) para un estudiante llamado {$name} " .
			"que lleva {$days} días sin actividad en el curso \"{$course_title}\". " .
			( $next_title ? "Su próxima lección pendiente es \"{$next_title}\". " : '' ) .
			'El tono debe ser cálido, empático y motivador. No uses lenguaje de ventas. ' .
			'Incluye: (1) reconocer su ausencia sin juzgar, (2) recordar el valor del curso, ' .
			'(3) un pequeño empujón para retomar hoy. No incluyas asunto ni firma.',
			80
		);

		if ( ! $body ) {
			$body = "Hola {$name},\n\nNotamos que llevas {$days} días sin actividad en \"{$course_title}\". "
				. "Tu progreso te espera. ¡Vuelve cuando puedas!";
		}

		$subject = "Te extrañamos en \"{$course_title}\" 👋";

		if ( ! $dry_run ) {
			$sent = CLMS_Email::send(
				$student['user_email'],
				$subject,
				$body,
				array(
					'headline'    => sprintf( 'Retoma tu aprendizaje en "%s"', $course_title ),
					'button_text' => $next_lesson ? 'Continuar aprendizaje' : 'Ir al curso',
					'button_url'  => $next_lesson ? get_permalink( $next_lesson ) : get_permalink( $course_id ),
				)
			);
			if ( $sent ) {
				update_user_meta( $user_id, self::META_LAST_ALERT . '_inactivity_' . $course_id, time() );
				do_action(
					'clms_ai_inactivity_alert_generated',
					$user_id,
					$course_id,
					array(
						'subject'   => $subject,
						'body'      => $body,
						'lesson_id' => $next_lesson ? absint( $next_lesson ) : 0,
						'link'      => $next_lesson ? get_permalink( $next_lesson ) : get_permalink( $course_id ),
					)
				);
			}
			return $sent;
		}

		return true; // dry_run
	}

	protected function send_low_grade_alert( $student, $course_id, $dry_run ) {
		$user_id = $student['user_id'];

		$last = (int) get_user_meta( $user_id, self::META_LAST_ALERT . '_grade_' . $course_id, true );
		if ( $last && ( time() - $last ) < ( self::MIN_HOURS_BTW_ALERTS * HOUR_IN_SECONDS ) ) {
			return false;
		}

		$course_title = get_the_title( $course_id );
		$name         = $student['user_name'];
		$avg          = $student['average'];

		$body = $this->generate_email_with_ai(
			"Escribe un email de apoyo académico (máx 3 párrafos) para {$name}, " .
			"estudiante del curso \"{$course_title}\" que tiene un promedio de {$avg}/100. " .
			'El tono debe ser alentador, no condescendiente. ' .
			'Incluye: (1) reconocer el esfuerzo, (2) sugerir que pida ayuda al profesor o revise el material, ' .
			'(3) recordar que mejorar es parte del proceso. No incluyas asunto ni firma.',
			80
		);

		if ( ! $body ) {
			$body = "Hola {$name},\n\nVemos que tu promedio en \"{$course_title}\" es {$avg}/100. "
				. "¡No te preocupes! Estamos aquí para ayudarte a mejorar.";
		}

		$subject = "Tu progreso en \"{$course_title}\" — te ayudamos a mejorar";

		if ( ! $dry_run ) {
			$sent = CLMS_Email::send(
				$student['user_email'],
				$subject,
				$body,
				array(
					'headline'    => sprintf( 'Tu progreso en "%s"', $course_title ),
					'button_text' => 'Ir al curso',
					'button_url'  => get_permalink( $course_id ),
				)
			);
			if ( $sent ) {
				update_user_meta( $user_id, self::META_LAST_ALERT . '_grade_' . $course_id, time() );
				do_action(
					'clms_ai_low_grade_alert_generated',
					$user_id,
					$course_id,
					array(
						'subject' => $subject,
						'body'    => $body,
						'link'    => get_permalink( $course_id ),
						'average' => $avg,
					)
				);
			}
			return $sent;
		}

		return true;
	}

	protected function send_teacher_digest( $course_id, $inactives, $low_grades, $dry_run ) {
		$author_id = (int) get_post_field( 'post_author', $course_id );
		if ( ! $author_id ) { return false; }

		$author = get_userdata( $author_id );
		if ( ! $author ) { return false; }

		$course_title = get_the_title( $course_id );
		$subject      = sprintf( '[ATORA] Digest de alumnos — %s', $course_title );

		$digest_html  = '<p><strong>Fecha:</strong> ' . esc_html( current_time( 'j F Y' ) ) . '</p>';

		if ( ! empty( $inactives ) ) {
			$digest_html .= '<h3 style="margin:1.5em 0 .5em;font-size:16px;color:#1a202c;">Alumnos inactivos</h3>';
			$digest_html .= '<ul style="margin:0;padding-left:1.25em;">';
			foreach ( $inactives as $s ) {
				$digest_html .= '<li><strong>' . esc_html( $s['user_name'] ) . '</strong> — '
					. absint( $s['days_inactive'] ) . ' días sin actividad</li>';
			}
			$digest_html .= '</ul>';
		}

		if ( ! empty( $low_grades ) ) {
			$digest_html .= '<h3 style="margin:1.5em 0 .5em;font-size:16px;color:#1a202c;">Alumnos con promedio bajo</h3>';
			$digest_html .= '<ul style="margin:0;padding-left:1.25em;">';
			foreach ( $low_grades as $s ) {
				$digest_html .= '<li><strong>' . esc_html( $s['user_name'] ) . '</strong> — promedio '
					. esc_html( (string) $s['average'] ) . '/100</li>';
			}
			$digest_html .= '</ul>';
		}

		$digest_html .= '<p style="margin-top:1.5em;">Se han enviado emails de apoyo automáticos a los alumnos listados.</p>';

		if ( ! $dry_run ) {
			$sent = CLMS_Email::send(
				$author->user_email,
				$subject,
				$digest_html,
				array(
					'headline'    => sprintf( 'Resumen diario — %s', $course_title ),
					'footer_note' => 'Este resumen es generado automáticamente por ATORA LMS.',
				)
			);

			if ( $sent ) {
				do_action(
					'clms_ai_teacher_digest_generated',
					absint( $author_id ),
					absint( $course_id ),
					array(
						'subject' => $subject,
						'body'    => $digest_html,
					),
					array(
						'inactives'  => $inactives,
						'low_grades' => $low_grades,
					)
				);
			}

			return $sent;
		}

		return true;
	}

	// ── IA helper ─────────────────────────────────────────────────────────────────

	protected function generate_email_with_ai( $prompt, $max_tokens = 200 ) {
		$copilots = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Copilots') : null;
		if ( $copilots && method_exists( $copilots, 'run_text' ) ) {
			$result = $copilots->run_text(
				'teacher',
				'draft_student_message',
				array( array( 'role' => 'user', 'content' => (string) $prompt ) ),
				array(
					'max_tokens'  => absint( $max_tokens ),
					'temperature' => 0.3,
					'timeout'     => 30,
				),
				array(
					'screen' => 'ai_alerts',
				)
			);
			return is_wp_error( $result ) ? '' : trim( (string) $result );
		}

		$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;

		if ( $manager && method_exists( $manager, 'chat' ) ) {
			$result = $manager->chat(
				array( array( 'role' => 'user', 'content' => (string) $prompt ) ),
				array(
					'max_tokens'  => absint( $max_tokens ),
					'temperature' => 0.3,
					'timeout'     => 30,
				)
			);

			return is_wp_error( $result ) ? '' : trim( (string) $result );
		}

		return '';
	}

	// ── Helpers ───────────────────────────────────────────────────────────────────

	protected function get_last_activity( $user_id, $course_id ) {
		$lesson_ids = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_lessons( $course_id ) : array();
		if ( empty( $lesson_ids ) ) { return null; }

		// Buscar última entrega
		$submissions = get_posts( array(
			'post_type'      => 'clms_submission',
			'post_status'    => array( 'publish', 'private' ),
			'author'         => $user_id,
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'meta_query'     => array( array(
				'key'     => '_clms_submission_lesson_id',
				'value'   => $lesson_ids,
				'compare' => 'IN',
			) ),
		) );

		if ( ! empty( $submissions ) ) {
			return strtotime( get_post_field( 'post_date_gmt', $submissions[0] ) );
		}

		// Fallback: último login
		$last_login = get_user_meta( $user_id, 'last_login', true );
		return $last_login ? (int) $last_login : null;
	}

	protected function get_next_pending_lesson( $user_id, $course_id ) {
		$lesson_ids = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_lessons( $course_id ) : array();
		$completed  = (array) get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed  = array_map( 'absint', $completed );

		foreach ( $lesson_ids as $lid ) {
			if ( ! in_array( absint( $lid ), $completed, true ) ) {
				return $lid;
			}
		}
		return null;
	}

	public function register_settings() {
		register_setting( 'clms_ai_alerts_group', self::OPTION_SETTINGS, array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_settings' ),
			'default'           => $this->get_default_settings(),
		) );
	}

	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		return array(
			'enabled'         => ! empty( $input['enabled'] ),
			'inactivity_days' => max( 1, min( 30, absint( $input['inactivity_days'] ?? 7 ) ) ),
			'low_grade_pct'   => max( 0, min( 100, absint( $input['low_grade_pct'] ?? 50 ) ) ),
		);
	}

	public function get_settings() {
		return array_merge(
			$this->get_default_settings(),
			(array) get_option( self::OPTION_SETTINGS, array() )
		);
	}

	protected function get_default_settings() {
		return array(
			'enabled'         => true,
			'inactivity_days' => 7,
			'low_grade_pct'   => 50,
		);
	}
}
