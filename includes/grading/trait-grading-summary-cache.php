<?php

/**
 * CLMS_Grading — Calificaciones, resumen académico y SpeedGrade.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Grading_Summary_Cache_Trait {
	public function get_student_course_status( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		$status_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Status_Service') : null;
		if ( $status_service && method_exists( $status_service, 'get_student_course_status' ) ) {
			$status = $status_service->get_student_course_status( $user_id, $course_id );
			if ( is_array( $status ) && ! empty( $status ) ) {
				return $status;
			}
		}

		if ( ! $user_id || ! $course_id ) {
			return array(
				'progress_percent'                  => 0,
				'completed_lessons'                 => 0,
				'total_lessons'                     => 0,
				'final_average'                     => 0,
				'pending_activities'                => 0,
				'last_feedback'                     => '',
				'next_step'                         => '',
				'risk_level'                        => 'normal',
				'gamification_summary'              => array(),
				'certificate_status'                => 'pending',
				'missing_certificate_requirements'  => array(),
				'improvement_plan'                  => array(),
			);
		}

		$summary     = $this->get_course_grade_summary( $user_id, $course_id );
		$lesson_ids  = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_lessons( $course_id ) : array();
		$lesson_ids  = is_array( $lesson_ids ) ? array_values( array_filter( array_map( 'absint', $lesson_ids ) ) ) : array();
		$pending     = $this->get_pending_activity_count( $user_id, $lesson_ids );
		$last_fb     = $this->get_last_feedback_for_course( $user_id, $lesson_ids );
		$next_step   = $this->get_next_course_step_label( $user_id, $lesson_ids );
		$risk        = $this->get_course_risk_level( $summary, $pending );
		$improvement = $this->get_latest_improvement_plan( $user_id, $course_id );

		$gamification_summary = array();
		$gamification = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Gamification_Core') : null;
		if ( $gamification && method_exists( $gamification, 'get_user_summary' ) ) {
			$gamification_summary = (array) $gamification->get_user_summary( $user_id );
		}

		$certificate_status = 'pending';
		$missing_certificate_requirements = array();
		$certificates = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Certificates') : null;
		if ( $certificates && method_exists( $certificates, 'get_certificate_status_for_student_course' ) ) {
			$cert_status = (array) $certificates->get_certificate_status_for_student_course( $user_id, $course_id );
			$certificate_status = isset( $cert_status['status'] ) ? sanitize_key( (string) $cert_status['status'] ) : 'pending';
			$missing_certificate_requirements = isset( $cert_status['missing_requirements'] ) && is_array( $cert_status['missing_requirements'] ) ? $cert_status['missing_requirements'] : array();
		}

		return array(
			'progress_percent'                 => isset( $summary['progress_percent'] ) ? absint( $summary['progress_percent'] ) : 0,
			'completed_lessons'                => isset( $summary['completed_lessons'] ) ? absint( $summary['completed_lessons'] ) : 0,
			'total_lessons'                    => isset( $summary['total_lessons'] ) ? absint( $summary['total_lessons'] ) : 0,
			'final_average'                    => isset( $summary['final_average'] ) ? absint( $summary['final_average'] ) : 0,
			'pending_activities'               => $pending,
			'last_feedback'                    => $last_fb,
			'next_step'                        => $next_step,
			'risk_level'                       => $risk,
			'gamification_summary'             => $gamification_summary,
			'certificate_status'               => $certificate_status,
			'missing_certificate_requirements' => $missing_certificate_requirements,
			'improvement_plan'                 => $improvement,
		);
	}

	public function get_course_grade_summary( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id ) {
			return $this->get_empty_summary();
		}

		$cached = class_exists( 'CLMS_Cache' )
			? CLMS_Cache::get( 'gradebook', array( 'summary', $user_id, $course_id ), array() )
			: get_transient( $this->get_grade_summary_cache_key( $user_id, $course_id ) );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return array_merge( $this->get_empty_summary(), $cached );
		}

		$assessment_engine = clms_core('CLMS_Assessment_Engine');
		if ( $assessment_engine && method_exists( $assessment_engine, 'build_course_gradebook' ) ) {
			$gradebook = $assessment_engine->build_course_gradebook( $user_id, $course_id );
			if ( ! empty( $gradebook['summary'] ) && is_array( $gradebook['summary'] ) ) {
				$summary = array_merge( $this->get_empty_summary(), $gradebook['summary'] );
				if ( class_exists( 'CLMS_Cache' ) ) {
					CLMS_Cache::set( 'gradebook', array( 'summary', $user_id, $course_id ), $summary, self::CACHE_TTL );
				} else {
					set_transient( $this->get_grade_summary_cache_key( $user_id, $course_id ), $summary, self::CACHE_TTL );
				}
				return $summary;
			}
		}

		$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );
		$lesson_ids = is_array( $lesson_ids ) ? array_map( 'absint', $lesson_ids ) : array();

		if ( empty( $lesson_ids ) ) {
			return $this->get_empty_summary();
		}

		$total_lessons   = count( $lesson_ids );
		$user_completed  = get_user_meta( $user_id, '_clms_completed_lessons', true );
		$user_completed  = is_array( $user_completed ) ? array_map( 'absint', $user_completed ) : array();
		$completed_count = count( array_intersect( $lesson_ids, $user_completed ) );

		$quiz_scores       = array();
		$assignment_scores = array();

		foreach ( $lesson_ids as $lesson_id ) {
			$quiz = $this->get_quiz_grade( $user_id, $lesson_id );
			if ( null !== $quiz ) {
				$quiz_scores[] = $quiz;
			}

			$submission = $this->get_user_submission_for_grading( $user_id, $lesson_id );
			if ( isset( $submission['grade'] ) && '' !== (string) $submission['grade'] ) {
				$assignment_scores[] = max( 0, min( 100, absint( $submission['grade'] ) ) );
			}
		}

		$quiz_average       = $this->calculate_average( $quiz_scores );
		$assignment_average = $this->calculate_average( $assignment_scores );

		if ( $quiz_average > 0 && $assignment_average > 0 ) {
			$final_average = (int) round( ( $quiz_average + $assignment_average ) / 2 );
		} elseif ( $quiz_average > 0 ) {
			$final_average = $quiz_average;
		} elseif ( $assignment_average > 0 ) {
			$final_average = $assignment_average;
		} else {
			$final_average = 0;
		}

		$summary = array(
			'completed_lessons'  => $completed_count,
			'total_lessons'      => $total_lessons,
			'progress_percent'   => $total_lessons > 0 ? (int) round( ( $completed_count / $total_lessons ) * 100 ) : 0,
			'quiz_average'       => $quiz_average,
			'assignment_average' => $assignment_average,
			'final_average'      => $final_average,
			'graded_lessons'     => count( $assignment_scores ),
			'updated_at'         => current_time( 'mysql' ),
		);

		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::set( 'gradebook', array( 'summary', $user_id, $course_id ), $summary, self::CACHE_TTL );
		} else {
			set_transient( $this->get_grade_summary_cache_key( $user_id, $course_id ), $summary, self::CACHE_TTL );
		}

		return $summary;
	}

	// ── Caché ────────────────────────────────────────────────────────────────────

	protected function get_grade_summary_cache_key( $user_id, $course_id ) {
		return 'clms_grade_summary_' . absint( $user_id ) . '_' . absint( $course_id );
	}

	protected function get_pending_activity_count( $user_id, $lesson_ids ) {
		$user_id   = absint( $user_id );
		$lesson_ids = is_array( $lesson_ids ) ? array_values( array_filter( array_map( 'absint', $lesson_ids ) ) ) : array();
		if ( ! $user_id || empty( $lesson_ids ) ) {
			return 0;
		}

		$submission_ids = get_posts(
			array(
				'post_type'      => self::SUBMISSION_CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => min( 200, count( $lesson_ids ) * 2 ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $user_id,
					),
					array(
						'key'     => '_clms_submission_lesson_id',
						'value'   => $lesson_ids,
						'compare' => 'IN',
					),
				),
			)
		);

		$pending = 0;
		foreach ( (array) $submission_ids as $submission_id ) {
			$status = sanitize_key( (string) get_post_meta( absint( $submission_id ), '_clms_submission_status', true ) );
			if ( in_array( $status, array( 'submitted', 'in_review', 'needs_revision', 'returned' ), true ) ) {
				++$pending;
			}
		}

		return $pending;
	}

	protected function get_last_feedback_for_course( $user_id, $lesson_ids ) {
		$user_id    = absint( $user_id );
		$lesson_ids = is_array( $lesson_ids ) ? array_values( array_filter( array_map( 'absint', $lesson_ids ) ) ) : array();
		if ( ! $user_id || empty( $lesson_ids ) ) {
			return '';
		}

		$ids = get_posts(
			array(
				'post_type'      => self::SUBMISSION_CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $user_id,
					),
					array(
						'key'     => '_clms_submission_lesson_id',
						'value'   => $lesson_ids,
						'compare' => 'IN',
					),
				),
			)
		);

		foreach ( (array) $ids as $submission_id ) {
			$feedback = (string) get_post_meta( absint( $submission_id ), '_clms_submission_feedback', true );
			if ( '' !== trim( $feedback ) ) {
				return sanitize_text_field( $feedback );
			}
		}

		return '';
	}

	protected function get_next_course_step_label( $user_id, $lesson_ids ) {
		$user_id    = absint( $user_id );
		$lesson_ids = is_array( $lesson_ids ) ? array_values( array_filter( array_map( 'absint', $lesson_ids ) ) ) : array();
		if ( ! $user_id || empty( $lesson_ids ) ) {
			return '';
		}

		$completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed = is_array( $completed ) ? array_map( 'absint', $completed ) : array();

		foreach ( $lesson_ids as $lesson_id ) {
			if ( ! in_array( $lesson_id, $completed, true ) ) {
				return get_the_title( $lesson_id );
			}
		}

		return __( 'Curso completado', 'atora-lms' );
	}

	protected function get_course_risk_level( $summary, $pending_count ) {
		$summary       = is_array( $summary ) ? $summary : array();
		$pending_count = absint( $pending_count );
		$progress      = isset( $summary['progress_percent'] ) ? absint( $summary['progress_percent'] ) : 0;
		$average       = isset( $summary['final_average'] ) ? absint( $summary['final_average'] ) : 0;

		if ( $progress >= 100 && $average >= 70 ) {
			return 'al_dia';
		}
		if ( $average < 60 || $pending_count >= 3 ) {
			return 'en_riesgo';
		}
		if ( $pending_count > 0 || $progress < 70 ) {
			return 'pendiente';
		}

		return 'normal';
	}

	protected function get_latest_improvement_plan( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$loop      = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Feedback_Loop') : null;
		if ( ! $loop || ! method_exists( $loop, 'get_student_trackings' ) ) {
			return array();
		}

		$trackings = $loop->get_student_trackings( $user_id, $course_id );
		if ( ! is_array( $trackings ) || empty( $trackings ) ) {
			return array();
		}

		return (array) $trackings[0];
	}

	public function invalidate_cache_for_user_course( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || ! $course_id ) {
			return;
		}
		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::delete( 'gradebook', array( 'summary', $user_id, $course_id ) );
			CLMS_Cache::delete( 'gradebook', array( 'entries', $user_id, $course_id ) );
			return;
		}
		delete_transient( $this->get_grade_summary_cache_key( $user_id, $course_id ) );
	}

	public function invalidate_cache_from_lesson( $user_id, $lesson_id ) {
		$course_id = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_lesson_course_id( $lesson_id ) : 0;
		$this->invalidate_cache_for_user_course( $user_id, $course_id );
	}

	public function invalidate_cache_from_submission( $submission_id, $lesson_id, $user_id ) {
		unset( $submission_id );
		$course_id = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_lesson_course_id( $lesson_id ) : 0;
		$this->invalidate_cache_for_user_course( $user_id, $course_id );
	}

	/**
	 * Invalida caché tras calificación.
	 *
	 * Firma del hook clms_submission_graded:
	 * (submission_id, user_id, status, grade, feedback)
	 *
	 * @param int $submission_id Entrega.
	 * @param int $user_id       Usuario propietario de la entrega.
	 * @return void
	 */
	public function invalidate_cache_from_graded_submission( $submission_id, $user_id ) {
		$submission_id = absint( $submission_id );
		$user_id       = absint( $user_id );

		if ( ! $submission_id || ! $user_id ) {
			return;
		}

		$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$course_id = $lesson_id && class_exists( 'CLMS_Helper' )
			? CLMS_Helper::get_lesson_course_id( $lesson_id )
			: absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );

		$this->invalidate_cache_for_user_course( $user_id, $course_id );
	}

	public function calculate_and_store_course_grade( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id ) {
			return false;
		}

		$summary = $this->get_course_grade_summary( $user_id, $course_id );

		update_user_meta( $user_id, '_clms_course_grade_' . $course_id, $summary );

		$assessment_engine = clms_core('CLMS_Assessment_Engine');
		if ( $assessment_engine && method_exists( $assessment_engine, 'build_course_gradebook' ) && method_exists( $assessment_engine, 'store_course_gradebook' ) ) {
			$assessment_engine->store_course_gradebook( $user_id, $course_id, $assessment_engine->build_course_gradebook( $user_id, $course_id ) );
		}

		return $summary;
	}

}
