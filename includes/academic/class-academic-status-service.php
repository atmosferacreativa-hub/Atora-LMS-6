<?php
/**
 * Servicio de estado académico unificado por estudiante y curso.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Academic_Status_Service {
	/**
	 * Guard de recursión por par estudiante/curso.
	 *
	 * @var array<string,bool>
	 */
	protected static $status_call_guard = array();

	/**
	 * Obtiene estado académico consolidado.
	 *
	 * @param int $user_id   Estudiante.
	 * @param int $course_id Curso.
	 * @return array<string,mixed>
	 */
	public function get_student_course_status( $user_id, $course_id, $args = array() ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$args      = is_array( $args ) ? $args : array();

		$defaults = $this->get_default_status();
		if ( ! $user_id || ! $course_id ) {
			return $defaults;
		}

		$guard_key = $user_id . ':' . $course_id;
		if ( isset( self::$status_call_guard[ $guard_key ] ) ) {
			return $defaults;
		}
		self::$status_call_guard[ $guard_key ] = true;

		try {
			$summary = $this->get_grade_summary( $user_id, $course_id );

			$lesson_ids = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_lessons( $course_id ) : array();
			$lesson_ids = is_array( $lesson_ids ) ? array_values( array_filter( array_map( 'absint', $lesson_ids ) ) ) : array();

			$pending   = $this->get_pending_activity_count( $user_id, $lesson_ids );
			$last_data = $this->get_last_feedback_and_grade( $user_id, $course_id );
			$next_step = $this->get_next_step_label( $user_id, $lesson_ids );
			$activity_snapshot = $this->get_activity_snapshot( $user_id, $course_id );
			$risk      = $this->get_risk_level( $summary, $pending, $activity_snapshot );
			$risk_data = $this->build_risk_indicators( $summary, $pending, $next_step, $activity_snapshot );

			$skip_improvement = ! empty( $args['skip_improvement_plan'] );
			$improvement_plan = array();
			if ( ! $skip_improvement ) {
				$improvement_plan = $this->get_latest_improvement_plan( $user_id, $course_id );
				if ( empty( $improvement_plan ) ) {
					$improvement_plan = $this->build_improvement_plan_fallback( $user_id, $course_id );
				}
			}
			$improvement_plan = $this->normalize_improvement_plan( $improvement_plan, $next_step );
			$competencies     = $this->get_competency_status( $user_id, $course_id );
			$evidences        = $this->get_evidence_summary( $user_id, $course_id );
			$gamification     = $this->get_gamification_summary( $user_id );
			$certificate      = $this->get_certificate_status( $user_id, $course_id );
			$final_average    = $this->normalize_final_average( $summary );

			if ( '' === $next_step ) {
				$next_step = $pending > 0
					? __( 'Tienes actividades pendientes por completar.', 'atora-lms' )
					: __( 'Continúa con tu ritmo de estudio para mantener el avance.', 'atora-lms' );
			}

			return array(
				'progress_percent'                 => isset( $summary['progress_percent'] ) ? absint( $summary['progress_percent'] ) : 0,
				'completed_lessons'                => isset( $summary['completed_lessons'] ) ? absint( $summary['completed_lessons'] ) : 0,
				'total_lessons'                    => isset( $summary['total_lessons'] ) ? absint( $summary['total_lessons'] ) : count( $lesson_ids ),
				'final_average'                    => $final_average,
				'pending_activities'               => $pending,
				'last_feedback'                    => (string) $last_data['feedback'],
				'last_grade'                       => '' !== (string) $last_data['grade'] ? absint( $last_data['grade'] ) : null,
				'next_step'                        => $next_step,
				'risk_level'                       => $risk,
				'risk_reasons'                     => isset( $risk_data['risk_reasons'] ) ? (array) $risk_data['risk_reasons'] : array(),
				'recommended_action'               => isset( $risk_data['recommended_action'] ) ? sanitize_text_field( (string) $risk_data['recommended_action'] ) : '',
				'last_access_at'                   => isset( $activity_snapshot['last_access'] ) ? sanitize_text_field( (string) $activity_snapshot['last_access'] ) : '',
				'course_time_seconds'              => isset( $activity_snapshot['course_time_seconds'] ) ? absint( $activity_snapshot['course_time_seconds'] ) : 0,
				'improvement_plan'                 => $improvement_plan,
				'competencies'                     => $competencies,
				'evidences'                        => $evidences,
				'gamification_summary'             => $gamification,
				'certificate_status'               => isset( $certificate['status'] ) ? sanitize_key( (string) $certificate['status'] ) : 'pending',
				'certificate_status_detail'        => $certificate,
				'missing_certificate_requirements' => isset( $certificate['missing_requirements'] ) && is_array( $certificate['missing_requirements'] ) ? $certificate['missing_requirements'] : array(),
			);
		} finally {
			unset( self::$status_call_guard[ $guard_key ] );
		}
	}

	/**
	 * PT-2 (6.10.0, insignias de estudiante): tasa de entregas a tiempo
	 * de un estudiante en un curso — 0-100, o null si no tiene ninguna
	 * entrega con fecha límite todavía (no hay base para calcular, no
	 * es lo mismo que "0% puntual"). Mismo criterio de "a tiempo" que
	 * ya usa `count_late_submissions_by_course()` en
	 * CLMS_Academic_Report_Service (comparar post_date_gmt de la
	 * entrega contra _clms_due_date de la lección, hasta las 23:59:59
	 * del día límite) — no se reimplementa el criterio, solo se
	 * recorta a un estudiante en vez de agregar por curso.
	 *
	 * Método nuevo, sibling de get_student_course_status() — no toca
	 * ese método existente.
	 *
	 * @param int $user_id
	 * @param int $course_id
	 * @return int|null
	 */
	public function get_on_time_rate_for_student( $user_id, $course_id ) {
		global $wpdb;

		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || ! $course_id ) {
			return null;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $user_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => '_clms_submission_course_id',
						'value' => $course_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return null;
		}

		$with_due_date = 0;
		$on_time       = 0;

		foreach ( $query->posts as $submission_id ) {
			$submission_id = absint( $submission_id );
			$lesson_id     = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$due_raw       = $lesson_id ? (string) get_post_meta( $lesson_id, '_clms_due_date', true ) : '';
			if ( '' === trim( $due_raw ) ) {
				continue; // sin fecha límite -- no cuenta para puntualidad, no penaliza ni beneficia.
			}

			$due_ts     = strtotime( $due_raw . ' 23:59:59' );
			$created    = get_post_field( 'post_date_gmt', $submission_id );
			$created_ts = $created ? strtotime( (string) $created ) : 0;
			if ( ! $due_ts || ! $created_ts ) {
				continue;
			}

			++$with_due_date;
			if ( $created_ts <= $due_ts ) {
				++$on_time;
			}
		}

		if ( 0 === $with_due_date ) {
			return null;
		}

		return (int) round( ( $on_time / $with_due_date ) * 100 );
	}

	/**
	 * Estado base.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_default_status() {
		return array(
			'progress_percent'                 => 0,
			'completed_lessons'                => 0,
			'total_lessons'                    => 0,
			'final_average'                    => null,
			'pending_activities'               => 0,
			'last_feedback'                    => '',
			'last_grade'                       => null,
			'next_step'                        => '',
			'risk_level'                       => 'normal',
			'risk_reasons'                     => array(),
			'recommended_action'               => '',
			'last_access_at'                   => '',
			'course_time_seconds'              => 0,
			'improvement_plan'                 => array(),
			'competencies'                     => array(),
			'evidences'                        => array(),
			'gamification_summary'             => array(),
			'certificate_status'               => 'pending',
			'certificate_status_detail'        => array(),
			'missing_certificate_requirements' => array(),
		);
	}

	/**
	 * Obtiene resumen de calificaciones con fallback al motor central.
	 *
	 * @param int $user_id   Estudiante.
	 * @param int $course_id Curso.
	 * @return array<string,mixed>
	 */
	protected function get_grade_summary( $user_id, $course_id ) {
		$summary = array();

		$grading = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading') : null;
		if ( $grading && method_exists( $grading, 'get_course_grade_summary' ) ) {
			$summary = (array) $grading->get_course_grade_summary( $user_id, $course_id );
		}

		if ( ! empty( $summary ) ) {
			return $summary;
		}

		$grading_engine = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading_Engine') : null;
		if ( ! $grading_engine || ! method_exists( $grading_engine, 'calculate_final_grade' ) ) {
			return array();
		}

		$grade_result = (array) $grading_engine->calculate_final_grade( $user_id, $course_id );
		if ( empty( $grade_result ) ) {
			return array();
		}

		return array(
			'progress_percent' => absint( get_user_meta( $user_id, 'clms_course_' . $course_id . '_progress', true ) ),
			'completed_lessons'=> absint( get_user_meta( $user_id, 'clms_course_' . $course_id . '_completed_lessons', true ) ),
			'total_lessons'    => absint( get_user_meta( $user_id, 'clms_course_' . $course_id . '_total_lessons', true ) ),
			'final_average'    => isset( $grade_result['numeric_score'] ) && is_numeric( $grade_result['numeric_score'] ) ? (float) $grade_result['numeric_score'] : null,
		);
	}

	/**
	 * Normaliza promedio final para exponer null cuando no hay datos.
	 *
	 * @param array $summary Resumen base.
	 * @return int|null
	 */
	protected function normalize_final_average( $summary ) {
		$summary = is_array( $summary ) ? $summary : array();
		if ( ! isset( $summary['final_average'] ) || '' === (string) $summary['final_average'] ) {
			return null;
		}
		if ( ! is_numeric( $summary['final_average'] ) ) {
			return null;
		}

		return max( 0, min( 100, absint( round( (float) $summary['final_average'] ) ) ) );
	}

	/**
	 * Cuenta actividades pendientes en lecciones accesibles.
	 *
	 * @param int   $user_id    Estudiante.
	 * @param array $lesson_ids Lecciones del curso.
	 * @return int
	 */
	protected function get_pending_activity_count( $user_id, $lesson_ids ) {
		$user_id    = absint( $user_id );
		$lesson_ids = is_array( $lesson_ids ) ? array_values( array_filter( array_map( 'absint', $lesson_ids ) ) ) : array();
		if ( ! $user_id || empty( $lesson_ids ) || ! class_exists( 'CLMS_Helper' ) ) {
			return 0;
		}

		$submission = clms_core('CLMS_Submission');
		$pending    = 0;

		foreach ( $lesson_ids as $lesson_id ) {
			if ( ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
				continue;
			}

			if ( $submission && method_exists( $submission, 'get_user_submission_for_grading' ) ) {
				$item   = (array) $submission->get_user_submission_for_grading( $user_id, $lesson_id );
				$status = isset( $item['status'] ) ? sanitize_key( (string) $item['status'] ) : '';
				if ( empty( $item ) || in_array( $status, array( 'submitted', 'in_review', 'needs_revision', 'returned', '' ), true ) ) {
					++$pending;
				}
				continue;
			}

			$attempt_key = 'clms_quiz_attempt_' . $lesson_id;
			$attempt     = get_user_meta( $user_id, $attempt_key, true );
			if ( ! is_array( $attempt ) || ! isset( $attempt['score'] ) ) {
				++$pending;
			}
		}

		return max( 0, absint( $pending ) );
	}

	/**
	 * Último feedback y nota desde submissions del curso.
	 *
	 * @param int $user_id   Estudiante.
	 * @param int $course_id Curso.
	 * @return array<string,mixed>
	 */
	protected function get_last_feedback_and_grade( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || ! $course_id ) {
			return array( 'feedback' => '', 'grade' => '' );
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => 'publish',
				'posts_per_page' => 5,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $user_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => '_clms_submission_course_id',
						'value' => $course_id,
						'type'  => 'NUMERIC',
					),
				),
				'no_found_rows'  => true,
			)
		);

		if ( empty( $query->posts ) ) {
			return array( 'feedback' => '', 'grade' => '' );
		}

		foreach ( $query->posts as $submission_id ) {
			$feedback = (string) get_post_meta( absint( $submission_id ), '_clms_submission_feedback', true );
			$grade    = get_post_meta( absint( $submission_id ), '_clms_submission_grade', true );
			$has_data = '' !== trim( $feedback ) || '' !== (string) $grade;
			if ( $has_data ) {
				return array(
					'feedback' => sanitize_textarea_field( $feedback ),
					'grade'    => '' !== (string) $grade ? max( 0, min( 100, absint( $grade ) ) ) : '',
				);
			}
		}

		return array( 'feedback' => '', 'grade' => '' );
	}

	/**
	 * Próxima acción sugerida en el curso.
	 *
	 * @param int   $user_id    Estudiante.
	 * @param array $lesson_ids Lecciones.
	 * @return string
	 */
	protected function get_next_step_label( $user_id, $lesson_ids ) {
		$user_id    = absint( $user_id );
		$lesson_ids = is_array( $lesson_ids ) ? array_values( array_filter( array_map( 'absint', $lesson_ids ) ) ) : array();
		if ( ! $user_id || empty( $lesson_ids ) || ! class_exists( 'CLMS_Helper' ) ) {
			return '';
		}

		$completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed = is_array( $completed ) ? array_map( 'absint', $completed ) : array();

		foreach ( $lesson_ids as $lesson_id ) {
			if ( in_array( $lesson_id, $completed, true ) ) {
				continue;
			}
			if ( CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
				$title = get_the_title( $lesson_id );
				if ( $title ) {
					return sprintf(
						/* translators: %s: título de lección */
						__( 'Continúa con: %s', 'atora-lms' ),
						$title
					);
				}
			}
		}

		return '';
	}

	/**
	 * Nivel de riesgo académico simple.
	 *
	 * @param array $summary        Resumen de curso.
	 * @param int   $pending_count  Pendientes.
	 * @return string
	 */
	protected function get_risk_level( $summary, $pending_count, $activity_snapshot = array() ) {
		$summary       = is_array( $summary ) ? $summary : array();
		$progress      = isset( $summary['progress_percent'] ) ? absint( $summary['progress_percent'] ) : 0;
		$average       = isset( $summary['final_average'] ) ? absint( $summary['final_average'] ) : 0;
		$pending_count = absint( $pending_count );
		$activity_snapshot = is_array( $activity_snapshot ) ? $activity_snapshot : array();
		$is_inactive = $this->is_activity_snapshot_inactive( $activity_snapshot );

		if ( $average > 0 && $average < 50 ) {
			return 'high';
		}
		if ( $is_inactive && ( $pending_count >= 2 || $progress < 50 ) ) {
			return 'high';
		}
		if ( $pending_count >= 3 || $progress < 30 ) {
			return 'medium';
		}
		if ( $is_inactive ) {
			return 'medium';
		}
		return 'normal';
	}

	/**
	 * Construye indicadores de riesgo académicos legibles.
	 *
	 * @param array  $summary      Resumen académico.
	 * @param int    $pending_count Actividades pendientes.
	 * @param string $next_step    Próximo paso detectado.
	 * @return array<string,mixed>
	 */
	protected function build_risk_indicators( $summary, $pending_count, $next_step = '', $activity_snapshot = array() ) {
		$summary       = is_array( $summary ) ? $summary : array();
		$pending_count = absint( $pending_count );
		$next_step     = sanitize_text_field( (string) $next_step );
		$activity_snapshot = is_array( $activity_snapshot ) ? $activity_snapshot : array();
		$progress      = isset( $summary['progress_percent'] ) ? absint( $summary['progress_percent'] ) : 0;
		$average       = isset( $summary['final_average'] ) && is_numeric( $summary['final_average'] ) ? absint( $summary['final_average'] ) : 0;
		$completed     = isset( $summary['completed_lessons'] ) ? absint( $summary['completed_lessons'] ) : 0;
		$total         = isset( $summary['total_lessons'] ) ? absint( $summary['total_lessons'] ) : 0;
		$is_inactive   = $this->is_activity_snapshot_inactive( $activity_snapshot );

		$reasons = array();

		if ( $average > 0 && $average < 50 ) {
			$reasons[] = __( 'Promedio académico bajo.', 'atora-lms' );
		} elseif ( $average >= 50 && $average < 70 ) {
			$reasons[] = __( 'Promedio en zona de atención.', 'atora-lms' );
		}

		if ( $progress > 0 && $progress < 30 ) {
			$reasons[] = __( 'Progreso insuficiente en el curso.', 'atora-lms' );
		}

		if ( $pending_count >= 3 ) {
			$reasons[] = __( 'Acumulación de actividades pendientes.', 'atora-lms' );
		}

		if ( $total > 0 && $completed <= 0 ) {
			$reasons[] = __( 'Aún no registra lecciones completadas.', 'atora-lms' );
		}

		if ( $is_inactive ) {
			$reasons[] = __( 'Sin actividad reciente en la plataforma.', 'atora-lms' );
		}

		$risk_level = $this->get_risk_level( $summary, $pending_count, $activity_snapshot );
		$recommended_action = '';

		if ( 'high' === $risk_level ) {
			$recommended_action = __( 'Prioriza una tutoría y define un plan de mejora con evidencias concretas.', 'atora-lms' );
		} elseif ( 'medium' === $risk_level ) {
			$recommended_action = __( 'Refuerza la próxima actividad clave y revisa el feedback más reciente.', 'atora-lms' );
		} else {
			$recommended_action = __( 'Mantén el ritmo y completa la siguiente actividad planificada.', 'atora-lms' );
		}

		if ( '' !== $next_step && 'normal' !== $risk_level ) {
			$recommended_action = $next_step;
		}

		return array(
			'risk_level'         => $risk_level,
			'risk_reasons'       => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $reasons ) ) ) ),
			'recommended_action' => sanitize_text_field( $recommended_action ),
		);
	}

	/**
	 * Snapshot de actividad para riesgo (acceso y permanencia).
	 *
	 * @param int $user_id   Usuario.
	 * @param int $course_id Curso.
	 * @return array<string,mixed>
	 */
	protected function get_activity_snapshot( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || ! $course_id ) {
			return array(
				'last_access'         => '',
				'course_last_access'  => '',
				'course_time_seconds' => 0,
			);
		}

		$tracker = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Student_Activity_Tracker') : null;
		if ( $tracker && method_exists( $tracker, 'get_student_activity_summary' ) ) {
			$summary = (array) $tracker->get_student_activity_summary( $user_id, $course_id );
			return array(
				'last_access'         => sanitize_text_field( (string) ( $summary['last_access'] ?? '' ) ),
				'course_last_access'  => sanitize_text_field( (string) ( $summary['course_last_access'] ?? '' ) ),
				'course_time_seconds' => absint( $summary['course_time_seconds'] ?? 0 ),
			);
		}

		return array(
			'last_access'         => sanitize_text_field( (string) get_user_meta( $user_id, '_clms_last_access_at', true ) ),
			'course_last_access'  => sanitize_text_field( (string) get_user_meta( $user_id, '_clms_last_access_course_' . $course_id, true ) ),
			'course_time_seconds' => absint( get_user_meta( $user_id, '_clms_time_course_' . $course_id, true ) ),
		);
	}

	/**
	 * Evalúa inactividad con umbral institucional configurable.
	 *
	 * @param array $activity_snapshot Snapshot de actividad.
	 * @return bool
	 */
	protected function is_activity_snapshot_inactive( $activity_snapshot ) {
		$activity_snapshot = is_array( $activity_snapshot ) ? $activity_snapshot : array();
		$raw_date = ! empty( $activity_snapshot['course_last_access'] )
			? (string) $activity_snapshot['course_last_access']
			: (string) ( $activity_snapshot['last_access'] ?? '' );
		$raw_date = sanitize_text_field( $raw_date );
		if ( '' === $raw_date ) {
			return true;
		}

		$last_ts = strtotime( $raw_date );
		if ( ! $last_ts ) {
			return true;
		}

		$threshold_days = absint( get_option( 'clms_inactivity_days_threshold', 14 ) );
		if ( $threshold_days <= 0 ) {
			$threshold_days = 14;
		}
		$threshold_days = absint( apply_filters( 'clms_inactivity_days_threshold', $threshold_days ) );
		if ( $threshold_days <= 0 ) {
			$threshold_days = 14;
		}

		return ( time() - $last_ts ) >= ( $threshold_days * DAY_IN_SECONDS );
	}

	/**
	 * Último plan de mejora desde feedback loop.
	 *
	 * @param int $user_id   Estudiante.
	 * @param int $course_id Curso.
	 * @return array<string,mixed>
	 */
	protected function get_latest_improvement_plan( $user_id, $course_id ) {
		$feedback_loop = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Feedback_Loop') : null;
		if ( ! $feedback_loop || ! method_exists( $feedback_loop, 'get_student_trackings' ) ) {
			return array();
		}

		$trackings = (array) $feedback_loop->get_student_trackings( absint( $user_id ), absint( $course_id ) );
		if ( empty( $trackings[0] ) || ! is_array( $trackings[0] ) ) {
			return array();
		}

		$latest      = $trackings[0];
		$gaps        = ! empty( $latest['gaps_tracked'] ) ? json_decode( (string) $latest['gaps_tracked'], true ) : array();
		$checkpoints = ! empty( $latest['checkpoints'] ) ? json_decode( (string) $latest['checkpoints'], true ) : array();

		$recommendation = '';
		if ( ! empty( $gaps[0]['label'] ) ) {
			$recommendation = sprintf(
				/* translators: %s: área de mejora */
				__( 'Refuerza primero: %s', 'atora-lms' ),
				sanitize_text_field( (string) $gaps[0]['label'] )
			);
		}

		return array(
			'tracking_id'    => isset( $latest['tracking_id'] ) ? sanitize_text_field( (string) $latest['tracking_id'] ) : '',
			'gaps'           => is_array( $gaps ) ? $gaps : array(),
			'checkpoints'    => is_array( $checkpoints ) ? $checkpoints : array(),
			'recommendations'=> '' !== $recommendation ? array( $recommendation ) : array(),
			'recommendation' => $recommendation,
			'next_action'    => $recommendation,
			'strengths'      => array(),
			'weaknesses'     => ! empty( $gaps[0]['label'] ) ? array( sanitize_text_field( (string) $gaps[0]['label'] ) ) : array(),
			'related_resources' => array(),
			'generated_by'   => 'fallback',
			'last_updated'   => isset( $latest['last_updated'] ) ? sanitize_text_field( (string) $latest['last_updated'] ) : '',
		);
	}

	/**
	 * Fallback de plan de mejora desde la última entrega del curso.
	 *
	 * @param int $user_id   Estudiante.
	 * @param int $course_id Curso.
	 * @return array<string,mixed>
	 */
	protected function build_improvement_plan_fallback( $user_id, $course_id ) {
		$plan_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Improvement_Plan_Service') : null;
		if ( ! $plan_service || ! method_exists( $plan_service, 'build_from_submission' ) ) {
			return array();
		}

		$submission_id = $this->get_last_submission_id( $user_id, $course_id );
		if ( ! $submission_id ) {
			return array();
		}

		return (array) $plan_service->build_from_submission( $submission_id );
	}

	/**
	 * Normaliza plan de mejora para interfaz consistente.
	 *
	 * @param array  $plan               Plan base.
	 * @param string $fallback_next_step Siguiente paso sugerido.
	 * @return array<string,mixed>
	 */
	protected function normalize_improvement_plan( $plan, $fallback_next_step = '' ) {
		$plan_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Improvement_Plan_Service') : null;
		if ( $plan_service && method_exists( $plan_service, 'normalize_plan' ) ) {
			return (array) $plan_service->normalize_plan( (array) $plan, (string) $fallback_next_step );
		}

		$plan = is_array( $plan ) ? $plan : array();
		$recommendation = isset( $plan['recommendation'] ) ? sanitize_text_field( (string) $plan['recommendation'] ) : '';
		if ( '' === $recommendation ) {
			$recommendation = sanitize_text_field( (string) $fallback_next_step );
		}

		return array(
			'summary'           => $recommendation,
			'recommendation'    => $recommendation,
			'recommendations'   => '' !== $recommendation ? array( $recommendation ) : array(),
			'next_action'       => $recommendation,
			'strengths'         => array(),
			'weaknesses'        => array(),
			'related_resources' => array(),
			'generated_by'      => 'fallback',
		);
	}

	/**
	 * Obtiene ID de la última entrega con feedback o calificación.
	 *
	 * @param int $user_id   Estudiante.
	 * @param int $course_id Curso.
	 * @return int
	 */
	protected function get_last_submission_id( $user_id, $course_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_clms_submission_user_id',
						'value' => absint( $user_id ),
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => '_clms_submission_course_id',
						'value' => absint( $course_id ),
						'type'  => 'NUMERIC',
					),
				),
				'no_found_rows'  => true,
			)
		);

		if ( empty( $query->posts[0] ) ) {
			return 0;
		}

		return absint( $query->posts[0] );
	}

	/**
	 * Resumen gamificación.
	 *
	 * @param int $user_id Estudiante.
	 * @return array<string,mixed>
	 */
	protected function get_gamification_summary( $user_id ) {
		$module = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Gamification_Core') : null;
		if ( ! $module || ! method_exists( $module, 'get_user_summary' ) ) {
			return array();
		}
		return (array) $module->get_user_summary( absint( $user_id ) );
	}

	/**
	 * Estado de certificado.
	 *
	 * @param int $user_id   Estudiante.
	 * @param int $course_id Curso.
	 * @return array<string,mixed>
	 */
	protected function get_certificate_status( $user_id, $course_id ) {
		$certificates = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Certificates') : null;
		if ( ! $certificates || ! method_exists( $certificates, 'get_certificate_status_for_student_course' ) ) {
			return array(
				'status'               => 'pending',
				'missing_requirements' => array(),
			);
		}

		$status = (array) $certificates->get_certificate_status_for_student_course( absint( $user_id ), absint( $course_id ) );
		$status['status'] = isset( $status['status'] ) ? sanitize_key( (string) $status['status'] ) : 'pending';
		$status['missing_requirements'] = isset( $status['missing_requirements'] ) && is_array( $status['missing_requirements'] ) ? $status['missing_requirements'] : array();

		return $status;
	}

	/**
	 * Estado por competencia del estudiante dentro del curso.
	 *
	 * @param int $user_id   Estudiante.
	 * @param int $course_id Curso.
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_competency_status( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || ! $course_id ) {
			return array();
		}

		$competency_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Competency_Service') : null;
		if ( ! $competency_service || ! method_exists( $competency_service, 'get_course_competencies' ) ) {
			return array();
		}

		$competencies = (array) $competency_service->get_course_competencies( $course_id );
		if ( empty( $competencies ) ) {
			return array();
		}

		$evidence_rows = array();
		$evidence_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
		if ( $evidence_service && method_exists( $evidence_service, 'get_student_evidence_status' ) ) {
			$evidence_rows = (array) $evidence_service->get_student_evidence_status( $user_id, $course_id );
		}

		$submission_ids = $this->get_recent_submission_ids_for_course( $user_id, $course_id, 60 );
		$scores_map     = array();
		$feedback_map   = array();

		foreach ( $submission_ids as $submission_id ) {
			$submission_id = absint( $submission_id );
			if ( ! $submission_id ) {
				continue;
			}

			$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$grade     = get_post_meta( $submission_id, '_clms_submission_grade', true );
			$feedback  = sanitize_textarea_field( (string) get_post_meta( $submission_id, '_clms_submission_feedback', true ) );
			$rubric    = get_post_meta( $submission_id, '_clms_submission_rubric_scores', true );
			$rubric    = is_array( $rubric ) ? $rubric : array();

			foreach ( $rubric as $score_row ) {
				if ( ! is_array( $score_row ) ) {
					continue;
				}
				$raw_ref = isset( $score_row['competency_id'] ) ? (string) $score_row['competency_id'] : (string) ( $score_row['competency'] ?? '' );
				$comp_id = $this->resolve_competency_reference( $raw_ref, $competencies );
				if ( '' === $comp_id ) {
					continue;
				}
				$score = isset( $score_row['score'] ) && '' !== (string) $score_row['score'] && is_numeric( $score_row['score'] ) ? (float) $score_row['score'] : null;
				$max   = isset( $score_row['max_points'] ) && is_numeric( $score_row['max_points'] ) ? (float) $score_row['max_points'] : 0;
				if ( null === $score || $max <= 0 ) {
					continue;
				}
				$ratio = max( 0, min( 100, ( $score / $max ) * 100 ) );
				if ( ! isset( $scores_map[ $comp_id ] ) ) {
					$scores_map[ $comp_id ] = array();
				}
				$scores_map[ $comp_id ][] = $ratio;
				if ( '' !== $feedback && empty( $feedback_map[ $comp_id ] ) ) {
					$feedback_map[ $comp_id ] = $feedback;
				}
			}

			$grade_comp_ids = $this->get_activity_competency_ids( $lesson_id );
			if ( ! empty( $grade_comp_ids ) && '' !== (string) $grade && is_numeric( $grade ) ) {
				$grade_value = max( 0, min( 100, absint( round( (float) $grade ) ) ) );
				foreach ( $grade_comp_ids as $comp_id ) {
					if ( '' === $comp_id ) {
						continue;
					}
					if ( ! isset( $scores_map[ $comp_id ] ) ) {
						$scores_map[ $comp_id ] = array();
					}
					$scores_map[ $comp_id ][] = $grade_value;
					if ( '' !== $feedback && empty( $feedback_map[ $comp_id ] ) ) {
						$feedback_map[ $comp_id ] = $feedback;
					}
				}
			}
		}

		$result = array();
		foreach ( $competencies as $competency ) {
			$comp_id    = isset( $competency['id'] ) ? sanitize_key( (string) $competency['id'] ) : '';
			$title      = isset( $competency['title'] ) ? sanitize_text_field( (string) $competency['title'] ) : '';
			$scores     = isset( $scores_map[ $comp_id ] ) && is_array( $scores_map[ $comp_id ] ) ? $scores_map[ $comp_id ] : array();
			$avg_score  = ! empty( $scores ) ? (int) round( array_sum( $scores ) / count( $scores ) ) : 0;
			$required   = 0;
			$completed  = 0;

			foreach ( $evidence_rows as $evidence_row ) {
				$evidence_row = is_array( $evidence_row ) ? $evidence_row : array();
				$evidence_comp_ids = isset( $evidence_row['competency_ids'] ) && is_array( $evidence_row['competency_ids'] ) ? $evidence_row['competency_ids'] : array();
				if ( ! in_array( $comp_id, $evidence_comp_ids, true ) ) {
					continue;
				}
				if ( ! empty( $evidence_row['is_required_for_certificate'] ) ) {
					++$required;
					if ( ! empty( $evidence_row['approved'] ) ) {
						++$completed;
					}
				}
			}

			$status = 'sin_evidencia';
			if ( $avg_score >= 85 && ( 0 === $required || $completed >= $required ) ) {
				$status = 'destacado';
			} elseif ( $avg_score >= 70 ) {
				$status = 'competente';
			} elseif ( $avg_score > 0 || $completed > 0 ) {
				$status = 'en_desarrollo';
			}

			$recommendation = 'destacado' === $status
				? __( 'Mantén este desempeño y apóyate en evidencias de mayor complejidad.', 'atora-lms' )
				: __( 'Refuerza esta competencia con práctica guiada y revisión de feedback reciente.', 'atora-lms' );

			if ( $required > $completed ) {
				$recommendation = sprintf(
					/* translators: 1: competencia, 2: faltantes */
					__( 'Te conviene reforzar %1$s: te faltan %2$d evidencias obligatorias por aprobar.', 'atora-lms' ),
					$title,
					max( 0, $required - $completed )
				);
			}

			$result[] = array(
				'competency_id'        => $comp_id,
				'title'                => $title,
				'status'               => $status,
				'score'                => $avg_score,
				'completed_evidences'  => $completed,
				'required_evidences'   => $required,
				'last_feedback'        => isset( $feedback_map[ $comp_id ] ) ? sanitize_textarea_field( (string) $feedback_map[ $comp_id ] ) : '',
				'recommendation'       => $recommendation,
			);
		}

		return $result;
	}

	/**
	 * Resumen de evidencias del estudiante en el curso.
	 *
	 * @param int $user_id   Estudiante.
	 * @param int $course_id Curso.
	 * @return array<string,mixed>
	 */
	protected function get_evidence_summary( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		$summary = array(
			'required_total'   => 0,
			'required_approved'=> 0,
			'required_pending' => 0,
			'items'            => array(),
		);

		$evidence_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
		if ( ! $evidence_service || ! method_exists( $evidence_service, 'get_student_evidence_status' ) ) {
			return $summary;
		}

		$items = (array) $evidence_service->get_student_evidence_status( $user_id, $course_id );
		$summary['items'] = $items;

		foreach ( $items as $item ) {
			$item = is_array( $item ) ? $item : array();
			if ( empty( $item['is_required_for_certificate'] ) ) {
				continue;
			}
			$summary['required_total']++;
			if ( ! empty( $item['approved'] ) ) {
				$summary['required_approved']++;
			}
		}

		$summary['required_pending'] = max( 0, $summary['required_total'] - $summary['required_approved'] );

		return $summary;
	}

	/**
	 * IDs recientes de entregas por curso/estudiante.
	 *
	 * @param int $user_id   Estudiante.
	 * @param int $course_id Curso.
	 * @param int $limit     Límite.
	 * @return array<int,int>
	 */
	protected function get_recent_submission_ids_for_course( $user_id, $course_id, $limit = 60 ) {
		$limit = max( 1, min( 100, absint( $limit ) ) );
		$query = new WP_Query(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_clms_submission_user_id',
						'value' => absint( $user_id ),
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => '_clms_submission_course_id',
						'value' => absint( $course_id ),
						'type'  => 'NUMERIC',
					),
				),
				'no_found_rows' => true,
			)
		);

		return array_values( array_filter( array_map( 'absint', (array) $query->posts ) ) );
	}

	/**
	 * Resuelve referencia de competencia (id/título) a ID interno.
	 *
	 * @param string $reference    Referencia.
	 * @param array  $competencies Competencias normalizadas.
	 * @return string
	 */
	protected function resolve_competency_reference( $reference, $competencies ) {
		$reference = sanitize_text_field( (string) $reference );
		$ref_key   = sanitize_key( $reference );
		$ref_title = sanitize_title( $reference );
		$competencies = is_array( $competencies ) ? $competencies : array();

		foreach ( $competencies as $competency ) {
			$id    = isset( $competency['id'] ) ? sanitize_key( (string) $competency['id'] ) : '';
			$title = isset( $competency['title'] ) ? sanitize_title( (string) $competency['title'] ) : '';
			if ( '' !== $id && ( $id === $ref_key || $id === $ref_title ) ) {
				return $id;
			}
			if ( '' !== $title && $title === $ref_title ) {
				return $id;
			}
		}

		return '';
	}

	/**
	 * Competencias asociadas a una actividad.
	 *
	 * @param int $lesson_id Lección.
	 * @return array<int,string>
	 */
	protected function get_activity_competency_ids( $lesson_id ) {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id ) {
			return array();
		}

		$evidence_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
		if ( ! $evidence_service || ! method_exists( $evidence_service, 'get_activity_evidence_config' ) ) {
			return array();
		}

		$config = (array) $evidence_service->get_activity_evidence_config( $lesson_id );
		$ids    = isset( $config['competency_ids'] ) && is_array( $config['competency_ids'] ) ? $config['competency_ids'] : array();
		return array_values( array_filter( array_map( 'sanitize_key', $ids ) ) );
	}
}
