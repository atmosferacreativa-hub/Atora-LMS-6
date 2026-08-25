<?php
/**
 * Resumen diario del docente — PT-5.3 (sprint 6.4.0).
 *
 * "Es el que más valor entrega y el que menos existe hoy" — la OT.
 * Distinto del agrupador del estudiante (Digest_Store): no agrupa
 * eventos que fueron pasando, calcula en vivo el estado actual cada
 * vez que corre (mismo patrón que CLMS_AI_Alerts::run_daily_check()
 * para su propio digest de docente, que este sprint no toca).
 *
 * Abre con la acción pendiente, no con estadísticas — regla de UX del
 * sprint: "12 entregas por calificar", no "tuviste 3 alertas hoy".
 *
 * No-op si Messaging_Router::is_academic_routing_enabled() es false
 * (default) — regla 3 del sprint.
 *
 * @package ATORA_LMS
 * @since   6.4.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Teacher_Digest_Service {

	const CRON_HOOK = 'clms_teacher_digest_daily';
	const OPT_SEND_HOUR = 'atora_teacher_digest_send_hour';

	public function __construct() {
		add_action( 'init', array( $this, 'maybe_schedule_cron' ), 20 );
		add_action( self::CRON_HOOK, array( $this, 'run_daily_check' ) );
	}

	/** @return void */
	public function maybe_schedule_cron() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) { return; }
		$hour = max( 0, min( 23, absint( get_option( self::OPT_SEND_HOUR, 7 ) ) ) );
		wp_schedule_event( strtotime( "tomorrow {$hour}:00:00" ), 'daily', self::CRON_HOOK );
	}

	/**
	 * @return array{enabled:bool, teachers_checked:int, notified:int}
	 */
	public function run_daily_check() {
		if ( ! class_exists( '\ATORA\Messaging\Messaging_Router' ) || ! \ATORA\Messaging\Messaging_Router::is_academic_routing_enabled() ) {
			return array( 'enabled' => false, 'teachers_checked' => 0, 'notified' => 0 );
		}

		$teacher_ids = $this->get_active_teacher_ids();
		$checked = 0;
		$sent    = 0;

		foreach ( $teacher_ids as $teacher_id ) {
			++$checked;
			$counts = $this->get_pending_counts( $teacher_id );

			// PT-5.5: resumen vacío, no se envía nada.
			if ( 0 === array_sum( $counts ) ) {
				continue;
			}

			$ok = \ATORA\Messaging\Messaging_Router::send(
				$teacher_id,
				'teacher_digest',
				'atora_daily_digest_teacher',
				array(
					'teacher_name'         => $this->display_name( $teacher_id ),
					'submissions_pending'  => $counts['submissions_pending'],
					'students_inactive'    => $counts['students_inactive'],
					'quizzes_pending'      => $counts['quizzes_pending'],
					// PT-3.4 (6.8.0): apunta a "Hoy" en vez del hub genérico
					// -- el docente llega directo a la lista priorizada en
					// vez de un listado donde todavía tiene que buscar.
					// La plantilla aprobada (atora_daily_digest_teacher,
					// ver docs/PLANTILLAS-WHATSAPP.md) usa {{4}} como
					// variable posicional genérica ("Ver panel"), no un
					// texto fijo describiendo el destino -- el cambio es
					// transparente para el mensaje ya aprobado.
					'button_url'           => admin_url( 'admin.php?page=atora-hoy' ),
				),
				array(
					'dedupe_key'            => 'teacher_digest_' . $teacher_id . '_' . current_time( 'Y-m-d' ),
					'dedupe_window_minutes' => 20 * 60,
					'priority'              => 'low',
					'skip_digest'           => true, // este ES el digest, no se vuelve a agrupar.
				)
			);

			if ( $ok ) { ++$sent; }
		}

		return array( 'enabled' => true, 'teachers_checked' => $checked, 'notified' => $sent );
	}

	/**
	 * Docentes con al menos una sección con estudiantes activos —
	 * evita mandar el cron a instructores sin nada asignado.
	 *
	 * @return int[]
	 */
	protected function get_active_teacher_ids() {
		global $wpdb;
		if ( ! class_exists( '\ATORA\LMS\Section_Service' ) ) { return array(); }

		$ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$wpdb->prefix}atora_section_teachers WHERE role IN ('lead','assistant')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * PT-1 (6.8.0, agregador "Hoy"): envoltorio público de
	 * get_pending_counts() — ese método sigue siendo protected y sin
	 * ningún cambio de comportamiento; esto solo permite que otro
	 * servicio de lectura lo consulte sin llamar a un método protected
	 * desde afuera (mismo criterio ya usado en 6.5.10 PT-2 para
	 * get_db_stats()).
	 *
	 * @param int $teacher_id
	 * @return array{submissions_pending:int, students_inactive:int, quizzes_pending:int}
	 */
	public function get_pending_counts_for_teacher( $teacher_id ) {
		return $this->get_pending_counts( $teacher_id );
	}

	/**
	 * PT-1 (6.8.0, agregador "Hoy"): entregas sin calificar con más de
	 * $hours horas de espera — get_pending_counts() no distingue por
	 * antigüedad, así que este es un método de lectura puntual nuevo,
	 * no una modificación de ese método. Reutiliza la misma resolución
	 * de sección → estudiantes que get_pending_counts() ya hace (no se
	 * factoriza a un helper compartido para no arriesgar el
	 * comportamiento ya en producción de ese método — ver docblock de
	 * la clase, principio general de este archivo).
	 *
	 * @param int $teacher_id
	 * @param int $hours Antigüedad mínima en horas. Default 48 (PT-2.1 de 6.8.0).
	 * @return int
	 */
	public function get_stale_submission_count_for_teacher( $teacher_id, $hours = 48 ) {
		global $wpdb;
		$teacher_id = absint( $teacher_id );
		$hours      = max( 1, absint( $hours ) );

		$section_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT section_id FROM {$wpdb->prefix}atora_section_teachers WHERE user_id = %d", $teacher_id )
		);
		$section_ids = array_values( array_filter( array_map( 'absint', (array) $section_ids ) ) );
		if ( empty( $section_ids ) || ! class_exists( '\ATORA\LMS\Section_Service' ) ) {
			return 0;
		}

		$student_ids = array();
		foreach ( $section_ids as $section_id ) {
			if ( method_exists( '\ATORA\LMS\Section_Service', 'get_section_student_ids' ) ) {
				$student_ids = array_merge( $student_ids, \ATORA\LMS\Section_Service::get_section_student_ids( $section_id ) );
			}
		}
		$student_ids = array_values( array_unique( array_filter( array_map( 'absint', $student_ids ) ) ) );
		if ( empty( $student_ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $student_ids ), '%d' ) );
		$cutoff       = gmdate( 'Y-m-d H:i:s', strtotime( "-{$hours} hours", current_time( 'timestamp', true ) ) );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_user ON pm_user.post_id = p.ID AND pm_user.meta_key = '_clms_submission_user_id'
				 WHERE p.post_type = 'clms_submission' AND p.post_status = 'publish'
				   AND p.post_date <= %s
				   AND pm_user.meta_value IN ({$placeholders})
				   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pg WHERE pg.post_id = p.ID AND pg.meta_key = '_clms_submission_grade' AND pg.meta_value != '' )",
				array_merge( array( $cutoff ), $student_ids )
			)
		);
	}

	/**
	 * @param int $teacher_id
	 * @return array{submissions_pending:int, students_inactive:int, quizzes_pending:int}
	 */
	protected function get_pending_counts( $teacher_id ) {
		global $wpdb;
		$teacher_id = absint( $teacher_id );

		$section_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT section_id FROM {$wpdb->prefix}atora_section_teachers WHERE user_id = %d", $teacher_id )
		);
		$section_ids = array_values( array_filter( array_map( 'absint', (array) $section_ids ) ) );
		if ( empty( $section_ids ) ) {
			return array( 'submissions_pending' => 0, 'students_inactive' => 0, 'quizzes_pending' => 0 );
		}

		$student_ids = array();
		foreach ( $section_ids as $section_id ) {
			if ( method_exists( '\ATORA\LMS\Section_Service', 'get_section_student_ids' ) ) {
				$student_ids = array_merge( $student_ids, \ATORA\LMS\Section_Service::get_section_student_ids( $section_id ) );
			}
		}
		$student_ids = array_values( array_unique( array_filter( array_map( 'absint', $student_ids ) ) ) );
		if ( empty( $student_ids ) ) {
			return array( 'submissions_pending' => 0, 'students_inactive' => 0, 'quizzes_pending' => 0 );
		}

		$placeholders = implode( ',', array_fill( 0, count( $student_ids ), '%d' ) );

		// Entregas sin calificar de esos estudiantes.
		$submissions_pending = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_user ON pm_user.post_id = p.ID AND pm_user.meta_key = '_clms_submission_user_id'
				 WHERE p.post_type = 'clms_submission' AND p.post_status = 'publish'
				   AND pm_user.meta_value IN ({$placeholders})
				   AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pg WHERE pg.post_id = p.ID AND pg.meta_key = '_clms_submission_grade' AND pg.meta_value != '' )",
				...$student_ids
			)
		);

		// Estudiantes inactivos: mismo umbral que el recordatorio de
		// inactividad (CLMS_Student_Inactivity_Reminder_Service), sin
		// duplicar su lógica de envío — solo se cuenta aquí.
		$days_threshold = absint( get_option( 'clms_inactivity_days_threshold', 14 ) );
		$cutoff         = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_threshold} days" ) );
		$students_inactive = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
				 WHERE user_id IN ({$placeholders})
				   AND meta_key = '_clms_last_access_at'
				   AND ( meta_value = '' OR meta_value < %s )",
				array_merge( $student_ids, array( $cutoff ) )
			)
		);

		// Exámenes pendientes: mejor esfuerzo desde atora_quiz_submissions
		// (tablas F1-F4) — puede estar vacía en instalaciones que aún
		// leen en modo 'legacy'; no es un error, es la información
		// disponible hoy.
		$quiz_table = $wpdb->prefix . 'atora_quiz_submissions';
		$has_quiz_table = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $quiz_table ) ) ) === $quiz_table; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$quizzes_pending = 0;
		if ( $has_quiz_table ) {
			$quizzes_pending = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$quiz_table} WHERE user_id IN ({$placeholders}) AND status = 'pending'",
					...$student_ids
				)
			);
		}

		return array(
			'submissions_pending' => $submissions_pending,
			'students_inactive'   => $students_inactive,
			'quizzes_pending'     => $quizzes_pending,
		);
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
