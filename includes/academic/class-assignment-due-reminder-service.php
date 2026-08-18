<?php
/**
 * Recordatorio de vencimiento de tareas — PT-3.3 (sprint 6.4.0).
 *
 * Cron propio (no existía ningún scan de fechas de vencimiento antes
 * de este sprint — confirmado por auditoría). Sigue el mismo patrón
 * estructural que CLMS_Student_Inactivity_Reminder_Service: cron,
 * ajustes con default, cooldown por usermeta.
 *
 * No-op completo si Messaging_Router::is_academic_routing_enabled()
 * es false (default) — regla 3 del sprint.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Assignment_Due_Reminder_Service {

	const CRON_HOOK  = 'clms_assignment_due_soon_check';
	const META_NOTIFIED = '_clms_due_soon_notified_';
	const OPT_HOURS_BEFORE = 'atora_assignment_due_soon_hours';

	public function __construct() {
		add_action( 'init', array( $this, 'maybe_schedule_cron' ), 20 );
		add_action( self::CRON_HOOK, array( $this, 'run_check' ) );
	}

	/** @return void */
	public function maybe_schedule_cron() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		// Chequeo horario: la ventana "N horas antes" (default 24)
		// necesita más resolución que un cron diario para no saltarse
		// tareas que vencen a media mañana.
		wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function run_check() {
		if ( ! class_exists( '\ATORA\Messaging\Messaging_Router' ) || ! \ATORA\Messaging\Messaging_Router::is_academic_routing_enabled() ) {
			return array( 'enabled' => false, 'checked' => 0, 'notified' => 0 );
		}

		$hours_before = max( 1, min( 168, absint( get_option( self::OPT_HOURS_BEFORE, 24 ) ) ) );
		$lessons      = $this->get_lessons_due_soon( $hours_before );

		$checked = 0;
		$sent    = 0;

		foreach ( $lessons as $lesson_id ) {
			$course_id = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_id_from_lesson' )
				? absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) )
				: 0;
			if ( ! $course_id ) {
				continue;
			}

			$student_ids = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_enrolled_student_ids' )
				? CLMS_Helper::get_enrolled_student_ids( $course_id )
				: array();

			foreach ( (array) $student_ids as $student_id ) {
				$student_id = absint( $student_id );
				if ( ! $student_id ) {
					continue;
				}
				++$checked;

				if ( $this->already_notified( $student_id, $lesson_id ) ) {
					continue;
				}
				if ( $this->has_submitted( $student_id, $lesson_id ) ) {
					continue;
				}

				// PT-3.7: tope de seguridad, independiente del dedupe_key.
				if ( ! \ATORA\Messaging\Messaging_Router::under_recipient_cap( $student_id ) ) {
					continue;
				}

				$sent_ok = \ATORA\Messaging\Messaging_Router::send(
					$student_id,
					'assignment_due_soon',
					'atora_assignment_due_soon',
					array(
						'student_name' => $this->display_name( $student_id ),
						'lesson_title' => sanitize_text_field( (string) get_the_title( $lesson_id ) ),
						'course_title' => sanitize_text_field( (string) get_the_title( $course_id ) ),
						'due_at'       => $this->get_due_date( $lesson_id ),
						'button_url'   => (string) get_permalink( $lesson_id ),
					),
					array(
						'dedupe_key'            => "due_soon_{$lesson_id}_{$student_id}",
						'dedupe_window_minutes' => $hours_before * 60,
					)
				);

				if ( $sent_ok ) {
					update_user_meta( $student_id, self::META_NOTIFIED . $lesson_id, time() );
					++$sent;
				}
			}
		}

		return array( 'enabled' => true, 'checked' => $checked, 'notified' => $sent );
	}

	/**
	 * Lecciones publicadas cuya fecha de vencimiento cae dentro de la
	 * próxima hora, contada desde `$hours_before` horas en el futuro
	 * (ventana de 1h porque el cron corre cada hora).
	 *
	 * @param int $hours_before Horas de antelación configuradas.
	 * @return int[]
	 */
	protected function get_lessons_due_soon( $hours_before ) {
		global $wpdb;

		$window_start = gmdate( 'Y-m-d H:i:s', strtotime( "+{$hours_before} hours" ) );
		$window_end   = gmdate( 'Y-m-d H:i:s', strtotime( "+{$hours_before} hours +1 hour" ) );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_type = 'lm_lesson' AND p.post_status = 'publish'
				   AND pm.meta_key IN ('_clms_due_date', 'lm_due_date')
				   AND pm.meta_value >= %s AND pm.meta_value < %s",
				$window_start,
				$window_end
			)
		);

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * @param int $student_id
	 * @param int $lesson_id
	 * @return bool
	 */
	protected function already_notified( $student_id, $lesson_id ) {
		return (bool) absint( get_user_meta( $student_id, self::META_NOTIFIED . absint( $lesson_id ), true ) );
	}

	/**
	 * @param int $student_id
	 * @param int $lesson_id
	 * @return bool
	 */
	protected function has_submitted( $student_id, $lesson_id ) {
		global $wpdb;

		$submission_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_user ON pm_user.post_id = p.ID AND pm_user.meta_key = '_clms_submission_user_id'
				 INNER JOIN {$wpdb->postmeta} pm_lesson ON pm_lesson.post_id = p.ID AND pm_lesson.meta_key = '_clms_submission_lesson_id'
				 WHERE p.post_type = 'clms_submission'
				   AND pm_user.meta_value = %d AND pm_lesson.meta_value = %d
				 LIMIT 1",
				absint( $student_id ),
				absint( $lesson_id )
			)
		);

		return (bool) $submission_id;
	}

	/**
	 * @param int $lesson_id
	 * @return string
	 */
	protected function get_due_date( $lesson_id ) {
		$raw = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_post_meta_first' )
			? CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_date', '_clms_due_date' ), '' )
			: get_post_meta( $lesson_id, '_clms_due_date', true );

		return sanitize_text_field( (string) $raw );
	}

	/**
	 * @param int $user_id
	 * @return string
	 */
	protected function display_name( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		return $user ? sanitize_text_field( (string) $user->display_name ) : '';
	}
}
