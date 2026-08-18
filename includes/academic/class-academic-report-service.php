<?php
/**
 * Servicio de reportes académicos iniciales para profesor/admin.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Academic_Report_Service {

	const MAX_COURSES  = 30;
	const MAX_STUDENTS = 250;
	const MAX_ROWS     = 500;

	/**
	 * Reporte docente.
	 *
	 * @param int $teacher_id Docente.
	 * @param int $course_id  Curso opcional.
	 * @return array<string,mixed>
	 */
	public function get_teacher_report( $teacher_id, $course_id = 0 ) {
		$teacher_id = absint( $teacher_id );
		$course_id  = absint( $course_id );
		if ( ! $teacher_id ) {
			return $this->get_empty_teacher_report();
		}

		$course_ids = $course_id ? array( $course_id ) : $this->get_teacher_course_ids( $teacher_id );
		$course_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $course_ids ) ) ) ), 0, self::MAX_COURSES );
		if ( empty( $course_ids ) ) {
			return $this->get_empty_teacher_report();
		}

		$grading            = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading') : null;
		$status_service     = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Status_Service') : null;
		$diagnostics_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Diagnostics_Service') : null;
		$evidence_service   = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
		$certificates       = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Certificates') : null;

		$grade_values            = array();
		$risk_students           = array();
		$pending_reviews         = 0;
		$competency_totals       = array();
		$evidence_failure_totals = array();
		$next_certification      = 0;
		$dropoff_totals          = array();

		foreach ( $course_ids as $cid ) {
			$student_ids = $this->get_course_student_ids( $cid );
			if ( empty( $student_ids ) ) {
				continue;
			}

			$student_ids = array_slice( $student_ids, 0, self::MAX_STUDENTS );
			$dropoff_totals[ $cid ] = array( 'inactive' => 0, 'total' => count( $student_ids ) );
			$course_evidence_fail = array();

			foreach ( $student_ids as $student_id ) {
				$student_id = absint( $student_id );
				if ( ! $student_id ) {
					continue;
				}

				$summary = ( $grading && method_exists( $grading, 'get_course_grade_summary' ) )
					? (array) $grading->get_course_grade_summary( $student_id, $cid )
					: array();
				if ( isset( $summary['final_average'] ) && is_numeric( $summary['final_average'] ) ) {
					$grade_values[] = absint( $summary['final_average'] );
				}

				$status = ( $status_service && method_exists( $status_service, 'get_student_course_status' ) )
					? (array) $status_service->get_student_course_status( $student_id, $cid )
					: array();
				$risk_level = sanitize_key( (string) ( $status['risk_level'] ?? 'normal' ) );
				if ( in_array( $risk_level, array( 'high', 'medium' ), true ) ) {
					$risk_students[ $student_id . ':' . $cid ] = array(
						'student_id'   => $student_id,
						'student_name' => $this->get_user_label( $student_id ),
						'course_id'    => $cid,
						'course_title' => get_the_title( $cid ),
						'risk_level'   => $risk_level,
					);
				}
				if ( 'high' === $risk_level || $this->is_student_inactive_in_course( $student_id, $cid ) ) {
					$dropoff_totals[ $cid ]['inactive']++;
				}

				$competencies = isset( $status['competencies'] ) && is_array( $status['competencies'] ) ? $status['competencies'] : array();
				foreach ( $competencies as $competency ) {
					$competency = is_array( $competency ) ? $competency : array();
					$title = sanitize_text_field( (string) ( $competency['title'] ?? '' ) );
					$score = isset( $competency['score'] ) && is_numeric( $competency['score'] ) ? absint( $competency['score'] ) : 0;
					if ( '' === $title ) {
						continue;
					}
					if ( ! isset( $competency_totals[ $title ] ) ) {
						$competency_totals[ $title ] = array( 'sum' => 0, 'count' => 0 );
					}
					$competency_totals[ $title ]['sum'] += $score;
					$competency_totals[ $title ]['count']++;
				}

				$evidence_rows = ( $evidence_service && method_exists( $evidence_service, 'get_student_evidence_status' ) )
					? (array) $evidence_service->get_student_evidence_status( $student_id, $cid )
					: array();
				foreach ( $evidence_rows as $evidence_row ) {
					$evidence_row = is_array( $evidence_row ) ? $evidence_row : array();
					$is_required  = ! empty( $evidence_row['is_required_for_certificate'] );
					if ( ! $is_required ) {
						continue;
					}
					$title = sanitize_text_field( (string) ( $evidence_row['activity_title'] ?? '' ) );
					if ( '' === $title ) {
						continue;
					}
					if ( ! isset( $course_evidence_fail[ $title ] ) ) {
						$course_evidence_fail[ $title ] = 0;
					}
					if ( empty( $evidence_row['approved'] ) ) {
						$course_evidence_fail[ $title ]++;
					}
				}

				if ( $certificates && method_exists( $certificates, 'get_certificate_status_for_student_course' ) ) {
					$cert = (array) $certificates->get_certificate_status_for_student_course( $student_id, $cid );
					$status_key = sanitize_key( (string) ( $cert['status'] ?? 'pending' ) );
					if ( 'eligible' === $status_key ) {
						$next_certification++;
					}
				}
			}

			$pending_reviews += $this->count_pending_reviews_by_course( $cid );
			foreach ( $course_evidence_fail as $evidence_title => $fail_count ) {
				if ( ! isset( $evidence_failure_totals[ $evidence_title ] ) ) {
					$evidence_failure_totals[ $evidence_title ] = 0;
				}
				$evidence_failure_totals[ $evidence_title ] += absint( $fail_count );
			}

			if ( $diagnostics_service && method_exists( $diagnostics_service, 'get_course_warnings' ) ) {
				// Invocación ligera para asegurar que el diagnóstico esté disponible para UI.
				$diagnostics_service->get_course_warnings( $cid );
			}
		}

		$competency_stats = $this->build_competency_stats( $competency_totals );
		$weakest = isset( $competency_stats['weakest']['title'] ) ? $competency_stats['weakest']['title'] : '';
		$strongest = isset( $competency_stats['strongest']['title'] ) ? $competency_stats['strongest']['title'] : '';
		$evidence_most_fail = $this->max_key_by_value( $evidence_failure_totals );
		$module_dropoff = $this->build_dropoff_label( $dropoff_totals );

		return array(
			'average_course_grade'      => $this->average_int( $grade_values ),
			'students_at_risk'          => count( $risk_students ),
			'weakest_competency'        => $weakest,
			'strongest_competency'      => $strongest,
			'evidence_with_most_fail'   => $evidence_most_fail,
			'module_with_more_dropoff'  => $module_dropoff,
			'pending_reviews'           => absint( $pending_reviews ),
			'students_near_certificate' => absint( $next_certification ),
			'risk_items'                => array_slice( array_values( $risk_students ), 0, 20 ),
			'course_ids'                => $course_ids,
		);
	}

	/**
	 * Reporte administrador.
	 *
	 * @return array<string,mixed>
	 */
	public function get_admin_report() {
		$course_ids = get_posts(
			array(
				'post_type'      => 'lm_course',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'fields'         => 'ids',
				'posts_per_page' => self::MAX_COURSES,
				'no_found_rows'  => true,
			)
		);
		$course_ids = array_values( array_filter( array_map( 'absint', (array) $course_ids ) ) );
		if ( empty( $course_ids ) ) {
			return array(
				'courses_more_progress'      => array(),
				'courses_more_dropoff'       => array(),
				'teachers_review_load'       => array(),
				'certificates_issued'        => 0,
				'active_students'            => 0,
				'inactive_students'          => 0,
				'completion_rate'            => 0,
				'certification_rate'         => 0,
			);
		}

		$grading        = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading') : null;
		$status_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Status_Service') : null;
		$certificates   = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Certificates') : null;

		$progress_rows   = array();
		$dropoff_rows    = array();
		$teacher_load    = array();
		$active_students = array();
		$inactive_students = array();
		$total_complete  = 0;
		$total_courses_student = 0;
		$total_eligible_or_issued = 0;

		foreach ( $course_ids as $course_id ) {
			$student_ids = $this->get_course_student_ids( $course_id );
			$student_ids = array_slice( $student_ids, 0, self::MAX_STUDENTS );
			$teacher_id  = absint( get_post_field( 'post_author', $course_id ) );

			$progress_vals = array();
			$inactive_count = 0;

			foreach ( $student_ids as $student_id ) {
				$summary = ( $grading && method_exists( $grading, 'get_course_grade_summary' ) )
					? (array) $grading->get_course_grade_summary( $student_id, $course_id )
					: array();
				$progress = isset( $summary['progress_percent'] ) ? absint( $summary['progress_percent'] ) : 0;
				$progress_vals[] = $progress;
				if ( $progress > 0 ) {
					$active_students[ $student_id ] = true;
				} else {
					$inactive_students[ $student_id ] = true;
				}

				if ( $this->is_student_inactive_in_course( $student_id, $course_id ) ) {
					$inactive_count++;
				}

				if ( method_exists( 'CLMS_Helper', 'is_course_completed' ) && CLMS_Helper::is_course_completed( $student_id, $course_id ) ) {
					$total_complete++;
				}
				$total_courses_student++;

				if ( $certificates && method_exists( $certificates, 'get_certificate_status_for_student_course' ) ) {
					$cert = (array) $certificates->get_certificate_status_for_student_course( $student_id, $course_id );
					$status_key = sanitize_key( (string) ( $cert['status'] ?? 'pending' ) );
					if ( in_array( $status_key, array( 'eligible', 'valid', 'issued' ), true ) ) {
						$total_eligible_or_issued++;
					}
				}
			}

			$avg_progress = $this->average_int( $progress_vals );
			$dropoff_rate = ! empty( $student_ids ) ? (int) round( ( $inactive_count / count( $student_ids ) ) * 100 ) : 0;

			$progress_rows[] = array(
				'course_id'    => $course_id,
				'course_title' => get_the_title( $course_id ),
				'value'        => $avg_progress,
			);
			$dropoff_rows[] = array(
				'course_id'    => $course_id,
				'course_title' => get_the_title( $course_id ),
				'value'        => $dropoff_rate,
			);

			if ( $teacher_id ) {
				if ( ! isset( $teacher_load[ $teacher_id ] ) ) {
					$teacher_load[ $teacher_id ] = array(
						'teacher_id'   => $teacher_id,
						'teacher_name' => $this->get_user_label( $teacher_id ),
						'courses'      => 0,
						'pending_reviews' => 0,
					);
				}
				$teacher_load[ $teacher_id ]['courses']++;
				$teacher_load[ $teacher_id ]['pending_reviews'] += $this->count_pending_reviews_by_course( $course_id );
			}
		}

		usort(
			$progress_rows,
			static function ( $a, $b ) {
				return absint( $b['value'] ) <=> absint( $a['value'] );
			}
		);
		usort(
			$dropoff_rows,
			static function ( $a, $b ) {
				return absint( $b['value'] ) <=> absint( $a['value'] );
			}
		);
		usort(
			$teacher_load,
			static function ( $a, $b ) {
				return absint( $b['pending_reviews'] ) <=> absint( $a['pending_reviews'] );
			}
		);

		$issued_count = $this->count_issued_certificates();
		$completion_rate = $total_courses_student > 0 ? (int) round( ( $total_complete / $total_courses_student ) * 100 ) : 0;
		$certification_rate = $total_courses_student > 0 ? (int) round( ( $total_eligible_or_issued / $total_courses_student ) * 100 ) : 0;

		return array(
			'courses_more_progress'      => array_slice( $progress_rows, 0, 8 ),
			'courses_more_dropoff'       => array_slice( $dropoff_rows, 0, 8 ),
			'teachers_review_load'       => array_slice( array_values( $teacher_load ), 0, 10 ),
			'certificates_issued'        => $issued_count,
			'active_students'            => count( $active_students ),
			'inactive_students'          => count( $inactive_students ),
			'completion_rate'            => $completion_rate,
			'certification_rate'         => $certification_rate,
			'commerce'                   => $this->get_commerce_overview(),
		);
	}

	/**
	 * Perfil académico institucional del estudiante.
	 *
	 * @param int $student_id Estudiante.
	 * @param int $viewer_id  Usuario que consulta.
	 * @return array<string,mixed>
	 */
	public function get_student_profile_report( $student_id, $viewer_id = 0 ) {
		$student_id = absint( $student_id );
		$viewer_id  = absint( $viewer_id ? $viewer_id : get_current_user_id() );

		if ( ! $student_id ) {
			return array();
		}

		if ( ! $this->viewer_can_access_student_profile( $viewer_id, $student_id ) ) {
			return array();
		}

		$status_service      = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Status_Service') : null;
		$competency_service  = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Competency_Service') : null;
		$evidence_service    = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
		$certificates        = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Certificates') : null;
		$gamification        = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Gamification_Summary_Service') : null;
		$memory              = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Student_Memory') : null;
		$feedback_loop       = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Feedback_Loop') : null;
		$activity_tracker    = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Student_Activity_Tracker') : null;

		$course_ids = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_user_enrolled_courses' )
			? array_values( array_filter( array_map( 'absint', (array) ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $student_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $student_id ) ) ) ) )
			: array();
		$course_ids = array_slice( $course_ids, 0, self::MAX_COURSES );

		$completed_courses   = array();
		$active_courses      = array();
		$progress_values     = array();
		$average_values      = array();
		$competencies_done   = array();
		$competencies_active = array();
		$evidences_approved  = 0;
		$evidences_pending   = 0;
		$certificates_rows   = array();
		$feedback_history    = array();
		$risk_items          = array();
		$improvement_plan    = array();

		foreach ( $course_ids as $course_id ) {
			$status = ( $status_service && method_exists( $status_service, 'get_student_course_status' ) )
				? (array) $status_service->get_student_course_status( $student_id, $course_id )
				: array();

			$progress = isset( $status['progress_percent'] ) ? absint( $status['progress_percent'] ) : 0;
			$average  = isset( $status['final_average'] ) && is_numeric( $status['final_average'] ) ? absint( $status['final_average'] ) : 0;
			$risk     = sanitize_key( (string) ( $status['risk_level'] ?? 'unknown' ) );

			$progress_values[] = $progress;
			if ( $average > 0 ) {
				$average_values[] = $average;
			}

			if ( $progress >= 100 ) {
				$completed_courses[] = $course_id;
			} else {
				$active_courses[] = $course_id;
			}

			if ( ! empty( $status['last_feedback'] ) ) {
				$feedback_history[] = array(
					'course_id'    => $course_id,
					'course_title' => get_the_title( $course_id ),
					'feedback'     => sanitize_text_field( (string) $status['last_feedback'] ),
					'grade'        => isset( $status['last_grade'] ) && is_numeric( $status['last_grade'] ) ? absint( $status['last_grade'] ) : null,
				);
			}

			if ( in_array( $risk, array( 'high', 'medium' ), true ) ) {
				$risk_items[] = array(
					'course_id'    => $course_id,
					'course_title' => get_the_title( $course_id ),
					'risk_level'   => $risk,
					'risk_reasons' => isset( $status['risk_reasons'] ) && is_array( $status['risk_reasons'] ) ? $status['risk_reasons'] : array(),
					'action'       => isset( $status['recommended_action'] ) ? sanitize_text_field( (string) $status['recommended_action'] ) : '',
				);
			}

			$competencies = isset( $status['competencies'] ) && is_array( $status['competencies'] ) ? $status['competencies'] : array();
			if ( empty( $competencies ) && $competency_service && method_exists( $competency_service, 'get_student_competency_progress' ) ) {
				$competencies = (array) $competency_service->get_student_competency_progress( $student_id, $course_id );
			}
			foreach ( $competencies as $competency ) {
				$competency = is_array( $competency ) ? $competency : array();
				$title = sanitize_text_field( (string) ( $competency['title'] ?? '' ) );
				$status_key = sanitize_key( (string) ( $competency['status'] ?? 'en_desarrollo' ) );
				if ( '' === $title ) {
					continue;
				}
				if ( in_array( $status_key, array( 'competente', 'destacado', 'excellent', 'competent' ), true ) ) {
					$competencies_done[ $title ] = true;
				} else {
					$competencies_active[ $title ] = true;
				}
			}

			$evidences = isset( $status['evidences'] ) && is_array( $status['evidences'] ) ? $status['evidences'] : array();
			if ( empty( $evidences ) && $evidence_service && method_exists( $evidence_service, 'get_student_evidence_status' ) ) {
				$evidences = (array) $evidence_service->get_student_evidence_status( $student_id, $course_id );
			}
			foreach ( $evidences as $evidence_row ) {
				$evidence_row = is_array( $evidence_row ) ? $evidence_row : array();
				if ( ! empty( $evidence_row['approved'] ) ) {
					$evidences_approved++;
				} else {
					$evidences_pending++;
				}
			}

			if ( $certificates && method_exists( $certificates, 'get_certificate_status_for_student_course' ) ) {
				$cert_status = (array) $certificates->get_certificate_status_for_student_course( $student_id, $course_id );
				$status_key  = sanitize_key( (string) ( $cert_status['status'] ?? 'pending' ) );
				if ( in_array( $status_key, array( 'valid', 'issued', 'eligible', 'revoked', 'pending' ), true ) ) {
					$certificates_rows[] = array(
						'course_id'         => $course_id,
						'course_title'      => get_the_title( $course_id ),
						'status'            => $status_key,
						'view_url'          => isset( $cert_status['view_url'] ) ? esc_url_raw( (string) $cert_status['view_url'] ) : '',
						'verification_url'  => isset( $cert_status['verification_url'] ) ? esc_url_raw( (string) $cert_status['verification_url'] ) : '',
						'missing'           => isset( $cert_status['missing_requirements'] ) && is_array( $cert_status['missing_requirements'] ) ? $cert_status['missing_requirements'] : array(),
					);
				}
			}

			if ( empty( $improvement_plan ) && ! empty( $status['improvement_plan'] ) && is_array( $status['improvement_plan'] ) ) {
				$improvement_plan = $status['improvement_plan'];
			}
		}

		$memory_snapshot = array();
		if ( $memory && method_exists( $memory, 'get_memory_summary' ) ) {
			$memory_snapshot = (array) $memory->get_memory_summary( $student_id );
		}

		if ( empty( $improvement_plan ) && $feedback_loop && method_exists( $feedback_loop, 'get_student_trackings' ) ) {
			$trackings = (array) $feedback_loop->get_student_trackings( $student_id, 0 );
			if ( ! empty( $trackings[0] ) && is_array( $trackings[0] ) ) {
				$improvement_plan = array(
					'summary'        => sanitize_text_field( (string) ( $trackings[0]['status_summary'] ?? '' ) ),
					'recommendation' => sanitize_text_field( (string) ( $trackings[0]['adaptation_notes'] ?? '' ) ),
					'generated_by'   => 'rules',
				);
			}
		}

		$gamification_summary = array();
		if ( $gamification && method_exists( $gamification, 'get_student_summary' ) ) {
			$gamification_summary = (array) $gamification->get_student_summary( $student_id );
		}

		$recent_activity = $this->get_student_recent_activity( $student_id, 8 );
		$activity_summary = ( $activity_tracker && method_exists( $activity_tracker, 'get_student_activity_summary' ) )
			? (array) $activity_tracker->get_student_activity_summary( $student_id, ! empty( $course_ids[0] ) ? absint( $course_ids[0] ) : 0 )
			: array();

		return array(
			'student_id'                => $student_id,
			'student_name'              => $this->get_user_label( $student_id ),
			'courses_active'            => absint( count( $active_courses ) ),
			'courses_completed'         => absint( count( $completed_courses ) ),
			'progress_global'           => $this->average_int( $progress_values ),
			'average_global'            => $this->average_int( $average_values ),
			'competencies_developed'    => array_values( array_keys( $competencies_done ) ),
			'competencies_in_progress'  => array_values( array_keys( $competencies_active ) ),
			'evidences_approved'        => absint( $evidences_approved ),
			'evidences_pending'         => absint( $evidences_pending ),
			'certificates'              => $certificates_rows,
			'achievements'              => $gamification_summary,
			'feedback_history'          => array_slice( $feedback_history, 0, 12 ),
			'improvement_plan'          => $improvement_plan,
			'recommendations'           => ! empty( $improvement_plan['recommendations'] ) && is_array( $improvement_plan['recommendations'] ) ? $improvement_plan['recommendations'] : array(),
			'recent_activity'           => $recent_activity,
			'platform_activity'         => $activity_summary,
			'risk'                      => $this->aggregate_risk_items( $risk_items ),
			'risk_courses'              => array_slice( $risk_items, 0, 8 ),
			'memory'                    => $memory_snapshot,
		);
	}

	/**
	 * Perfil docente institucional.
	 *
	 * @param int $teacher_id Docente.
	 * @return array<string,mixed>
	 */
	public function get_teacher_profile_report( $teacher_id ) {
		$teacher_id = absint( $teacher_id );
		if ( ! $teacher_id ) {
			return array();
		}

		$teacher_report = $this->get_teacher_report( $teacher_id );
		$course_ids     = isset( $teacher_report['course_ids'] ) && is_array( $teacher_report['course_ids'] ) ? $teacher_report['course_ids'] : array();
		$student_ids    = array();
		$pending_reviews = 0;
		$reviewed_items  = 0;
		$risk_courses    = array();

		foreach ( $course_ids as $course_id ) {
			$course_id = absint( $course_id );
			if ( ! $course_id ) {
				continue;
			}
			$students = $this->get_course_student_ids( $course_id );
			foreach ( $students as $sid ) {
				$student_ids[ absint( $sid ) ] = true;
			}

			$pending_reviews += $this->count_pending_reviews_by_course( $course_id );
			$reviewed_items  += $this->count_reviewed_submissions_by_course( $course_id );

			$course_report = $this->get_course_advanced_report( $course_id, $teacher_id );
			if ( ! empty( $course_report['students_at_risk'] ) ) {
				$risk_courses[] = array(
					'course_id'          => $course_id,
					'course_title'       => get_the_title( $course_id ),
					'students_at_risk'   => absint( $course_report['students_at_risk'] ),
					'weakest_competency' => isset( $course_report['weakest_competency'] ) ? sanitize_text_field( (string) $course_report['weakest_competency'] ) : '',
				);
			}
		}

		$response_time = $this->estimate_teacher_response_time( $teacher_id, $course_ids );

		return array(
			'teacher_id'              => $teacher_id,
			'teacher_name'            => $this->get_user_label( $teacher_id ),
			'courses_taught'          => absint( count( $course_ids ) ),
			'students_active'         => absint( count( $student_ids ) ),
			'pending_reviews'         => absint( $pending_reviews ),
			'reviewed_submissions'    => absint( $reviewed_items ),
			'review_load'             => absint( $pending_reviews ),
			'avg_response_hours'      => $response_time,
			'courses_with_more_risk'  => array_slice( $risk_courses, 0, 8 ),
			'weakest_competency'      => isset( $teacher_report['weakest_competency'] ) ? sanitize_text_field( (string) $teacher_report['weakest_competency'] ) : '',
			'students_near_certificate'=> absint( $teacher_report['students_near_certificate'] ?? 0 ),
			'recent_activity'         => $this->get_teacher_recent_activity( $teacher_id, 10 ),
			'pending_actions'         => array(
				'pending_reviews' => absint( $pending_reviews ),
				'students_at_risk'=> absint( $teacher_report['students_at_risk'] ?? 0 ),
			),
		);
	}

	/**
	 * Reporte avanzado por curso.
	 *
	 * @param int $course_id  Curso.
	 * @param int $viewer_id  Viewer opcional para acotar permisos.
	 * @return array<string,mixed>
	 */
	public function get_course_advanced_report( $course_id, $viewer_id = 0 ) {
		$course_id = absint( $course_id );
		$viewer_id = absint( $viewer_id ? $viewer_id : get_current_user_id() );
		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return array();
		}

		if ( ! $this->viewer_can_access_course_report( $viewer_id, $course_id ) ) {
			return array();
		}

		$status_service    = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Status_Service') : null;
		$certificates      = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Certificates') : null;
		$evidence_service  = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;

		$student_ids       = $this->get_course_student_ids( $course_id );
		$student_ids       = array_slice( $student_ids, 0, self::MAX_STUDENTS );
		$students_count    = count( $student_ids );
		$active_count      = 0;
		$inactive_count    = 0;
		$completed_count   = 0;
		$certified_count   = 0;
		$progress_values   = array();
		$grade_values      = array();
		$risk_students     = array();
		$competency_totals = array();
		$evidence_fails    = array();

		foreach ( $student_ids as $student_id ) {
			$status = ( $status_service && method_exists( $status_service, 'get_student_course_status' ) )
				? (array) $status_service->get_student_course_status( $student_id, $course_id )
				: array();

			$progress = absint( $status['progress_percent'] ?? 0 );
			$grade    = isset( $status['final_average'] ) && is_numeric( $status['final_average'] ) ? absint( $status['final_average'] ) : 0;
			$risk     = sanitize_key( (string) ( $status['risk_level'] ?? 'unknown' ) );

			$progress_values[] = $progress;
			if ( $grade > 0 ) {
				$grade_values[] = $grade;
			}

			if ( $progress > 0 ) {
				$active_count++;
			} else {
				$inactive_count++;
			}
			if ( $progress >= 100 ) {
				$completed_count++;
			}

			if ( in_array( $risk, array( 'high', 'medium' ), true ) ) {
				$risk_students[] = $student_id;
			}

			$competencies = isset( $status['competencies'] ) && is_array( $status['competencies'] ) ? $status['competencies'] : array();
			foreach ( $competencies as $comp ) {
				$comp  = is_array( $comp ) ? $comp : array();
				$title = sanitize_text_field( (string) ( $comp['title'] ?? '' ) );
				$score = isset( $comp['score'] ) && is_numeric( $comp['score'] ) ? absint( $comp['score'] ) : 0;
				if ( '' === $title ) {
					continue;
				}
				if ( ! isset( $competency_totals[ $title ] ) ) {
					$competency_totals[ $title ] = array( 'sum' => 0, 'count' => 0 );
				}
				$competency_totals[ $title ]['sum'] += $score;
				$competency_totals[ $title ]['count']++;
			}

			$evidences = ( $evidence_service && method_exists( $evidence_service, 'get_student_evidence_status' ) )
				? (array) $evidence_service->get_student_evidence_status( $student_id, $course_id )
				: array();
			foreach ( $evidences as $evidence ) {
				$evidence = is_array( $evidence ) ? $evidence : array();
				$is_required = ! empty( $evidence['is_required_for_certificate'] );
				$title = sanitize_text_field( (string) ( $evidence['activity_title'] ?? '' ) );
				if ( ! $is_required || '' === $title ) {
					continue;
				}
				if ( empty( $evidence_fails[ $title ] ) ) {
					$evidence_fails[ $title ] = 0;
				}
				if ( empty( $evidence['approved'] ) ) {
					$evidence_fails[ $title ]++;
				}
			}

			if ( $certificates && method_exists( $certificates, 'get_certificate_status_for_student_course' ) ) {
				$cert_status = (array) $certificates->get_certificate_status_for_student_course( $student_id, $course_id );
				$key         = sanitize_key( (string) ( $cert_status['status'] ?? 'pending' ) );
				if ( in_array( $key, array( 'valid', 'issued' ), true ) ) {
					$certified_count++;
				}
			}
		}

		$completion_rate = $students_count > 0 ? (int) round( ( $completed_count / $students_count ) * 100 ) : 0;
		$cert_rate       = $students_count > 0 ? (int) round( ( $certified_count / $students_count ) * 100 ) : 0;
		$competencies    = $this->build_competency_stats( $competency_totals );

		return array(
			'course_id'                    => $course_id,
			'course_title'                 => get_the_title( $course_id ),
			'students_enrolled'            => absint( $students_count ),
			'students_active'              => absint( $active_count ),
			'students_inactive'            => absint( $inactive_count ),
			'average_progress'             => $this->average_int( $progress_values ),
			'average_grade'                => $this->average_int( $grade_values ),
			'completion_rate'              => $completion_rate,
			'certification_rate'           => $cert_rate,
			'pending_submissions'          => $this->count_pending_reviews_by_course( $course_id ),
			'late_submissions'             => $this->count_late_submissions_by_course( $course_id ),
			'strongest_competency'         => isset( $competencies['strongest']['title'] ) ? $competencies['strongest']['title'] : '',
			'weakest_competency'           => isset( $competencies['weakest']['title'] ) ? $competencies['weakest']['title'] : '',
			'evidence_with_most_fail'      => $this->max_key_by_value( $evidence_fails ),
			'activity_with_more_dropoff'   => $this->get_course_activity_with_more_dropoff( $course_id, $student_ids ),
			'students_at_risk'             => absint( count( $risk_students ) ),
			'students_near_certification'  => $this->count_students_near_certification( $course_id, $student_ids ),
		);
	}

	/**
	 * Reporte de certificación.
	 *
	 * @param int $course_id Curso opcional.
	 * @return array<string,mixed>
	 */
	public function get_certification_report( $course_id = 0 ) {
		$course_id = absint( $course_id );
		$courses = $course_id ? array( $course_id ) : get_posts(
			array(
				'post_type'      => 'lm_course',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'fields'         => 'ids',
				'posts_per_page' => self::MAX_COURSES,
				'no_found_rows'  => true,
			)
		);
		$courses = array_values( array_filter( array_map( 'absint', (array) $courses ) ) );
		$certificates = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Certificates') : null;
		$diagnostics  = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Diagnostics_Service') : null;
		$evidence_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;

		$issued = 0;
		$valid = 0;
		$revoked = 0;
		$eligible_no_issue = 0;
		$near_cert = 0;
		$blocking_evidences = array();
		$blocking_competencies = array();
		$ready_courses = array();
		$incomplete_courses = array();

		foreach ( $courses as $cid ) {
			$course_ready = true;
			$diag = array();
			if ( $diagnostics && method_exists( $diagnostics, 'diagnose_course' ) ) {
				$diag = (array) $diagnostics->diagnose_course( $cid );
				$course_ready = ! empty( $diag['course_ready_for_certificate'] );
			}
			if ( $course_ready ) {
				$ready_courses[] = $cid;
			} else {
				$incomplete_courses[] = $cid;
			}

			$students = $this->get_course_student_ids( $cid );
			$students = array_slice( $students, 0, self::MAX_STUDENTS );
			foreach ( $students as $sid ) {
				if ( ! $certificates || ! method_exists( $certificates, 'get_certificate_status_for_student_course' ) ) {
					continue;
				}
				$status = (array) $certificates->get_certificate_status_for_student_course( $sid, $cid );
				$key = sanitize_key( (string) ( $status['status'] ?? 'pending' ) );
				if ( in_array( $key, array( 'issued', 'valid' ), true ) ) {
					$issued++;
				}
				if ( 'valid' === $key ) {
					$valid++;
				}
				if ( 'revoked' === $key ) {
					$revoked++;
				}
				if ( 'eligible' === $key ) {
					$eligible_no_issue++;
					$near_cert++;
				}

				$missing = isset( $status['missing_requirements'] ) && is_array( $status['missing_requirements'] ) ? $status['missing_requirements'] : array();
				foreach ( $missing as $miss ) {
					$label = sanitize_text_field( (string) $miss );
					if ( '' === $label ) {
						continue;
					}
					if ( false !== stripos( $label, 'competenc' ) ) {
						if ( empty( $blocking_competencies[ $label ] ) ) {
							$blocking_competencies[ $label ] = 0;
						}
						$blocking_competencies[ $label ]++;
					} else {
						if ( empty( $blocking_evidences[ $label ] ) ) {
							$blocking_evidences[ $label ] = 0;
						}
						$blocking_evidences[ $label ]++;
					}
				}

				if ( $evidence_service && method_exists( $evidence_service, 'get_student_evidence_status' ) ) {
					$evidences = (array) $evidence_service->get_student_evidence_status( $sid, $cid );
					foreach ( $evidences as $evidence_row ) {
						$evidence_row = is_array( $evidence_row ) ? $evidence_row : array();
						if ( empty( $evidence_row['is_required_for_certificate'] ) || ! empty( $evidence_row['approved'] ) ) {
							continue;
						}
						$title = sanitize_text_field( (string) ( $evidence_row['activity_title'] ?? __( 'Evidencia obligatoria', 'atora-lms' ) ) );
						if ( empty( $blocking_evidences[ $title ] ) ) {
							$blocking_evidences[ $title ] = 0;
						}
						$blocking_evidences[ $title ]++;
					}
				}
			}
		}

		return array(
			'issued'                       => absint( $issued ),
			'valid'                        => absint( $valid ),
			'revoked'                      => absint( $revoked ),
			'eligible_without_issue'       => absint( $eligible_no_issue ),
			'students_near_certification'  => absint( $near_cert ),
			'blocking_evidences'           => $this->sort_map_desc( $blocking_evidences, 10 ),
			'blocking_competencies'        => $this->sort_map_desc( $blocking_competencies, 10 ),
			'courses_ready'                => array_map( 'absint', $ready_courses ),
			'courses_incomplete'           => array_map( 'absint', $incomplete_courses ),
			'courses_ready_count'          => absint( count( $ready_courses ) ),
			'courses_incomplete_count'     => absint( count( $incomplete_courses ) ),
		);
	}

	/**
	 * Indicadores de riesgo por estudiante/curso.
	 *
	 * @param int $user_id   Estudiante.
	 * @param int $course_id Curso.
	 * @return array<string,mixed>
	 */
	public function get_risk_indicators( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || ! $course_id ) {
			return array(
				'risk_level'         => 'unknown',
				'risk_reasons'       => array(),
				'recommended_action' => '',
			);
		}

		$status_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Status_Service') : null;
		if ( ! $status_service || ! method_exists( $status_service, 'get_student_course_status' ) ) {
			return array(
				'risk_level'         => 'unknown',
				'risk_reasons'       => array(),
				'recommended_action' => '',
			);
		}

		$status = (array) $status_service->get_student_course_status( $user_id, $course_id );
		return array(
			'risk_level'         => sanitize_key( (string) ( $status['risk_level'] ?? 'unknown' ) ),
			'risk_reasons'       => isset( $status['risk_reasons'] ) && is_array( $status['risk_reasons'] ) ? $status['risk_reasons'] : array(),
			'recommended_action' => isset( $status['recommended_action'] ) ? sanitize_text_field( (string) $status['recommended_action'] ) : '',
		);
	}

	/**
	 * Exporta CSV básico por curso.
	 *
	 * @param int    $course_id Curso.
	 * @param string $type      Tipo.
	 * @param int    $viewer_id Viewer.
	 * @return array<string,string>|array
	 */
	public function export_course_csv( $course_id, $type = 'students', $viewer_id = 0 ) {
		$course_id = absint( $course_id );
		$viewer_id = absint( $viewer_id ? $viewer_id : get_current_user_id() );
		$type      = sanitize_key( (string) $type );
		if ( ! $course_id || ! $this->viewer_can_access_course_report( $viewer_id, $course_id ) ) {
			return array();
		}

		$student_ids = array_slice( $this->get_course_student_ids( $course_id ), 0, self::MAX_ROWS );
		if ( empty( $student_ids ) ) {
			return array();
		}

		$rows = array();
		$headers = array();
		$status_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Status_Service') : null;
		$certificates   = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Certificates') : null;

		foreach ( $student_ids as $student_id ) {
			$status = ( $status_service && method_exists( $status_service, 'get_student_course_status' ) )
				? (array) $status_service->get_student_course_status( $student_id, $course_id )
				: array();
			$user = get_userdata( $student_id );
			$name = $user ? ( $user->display_name ? $user->display_name : $user->user_login ) : '';
			$email = $user ? sanitize_email( (string) $user->user_email ) : '';

			if ( 'progress' === $type ) {
				$headers = array( __( 'Nombre', 'atora-lms' ), __( 'Email', 'atora-lms' ), __( 'Progreso', 'atora-lms' ), __( 'Lecciones completadas', 'atora-lms' ), __( 'Lecciones totales', 'atora-lms' ) );
				$rows[] = array( $name, $email, absint( $status['progress_percent'] ?? 0 ) . '%', absint( $status['completed_lessons'] ?? 0 ), absint( $status['total_lessons'] ?? 0 ) );
			} elseif ( 'grades' === $type ) {
				$headers = array( __( 'Nombre', 'atora-lms' ), __( 'Email', 'atora-lms' ), __( 'Promedio', 'atora-lms' ), __( 'Última nota', 'atora-lms' ) );
				$rows[] = array( $name, $email, ( null !== ( $status['final_average'] ?? null ) ? absint( $status['final_average'] ) . '%' : '—' ), ( null !== ( $status['last_grade'] ?? null ) ? absint( $status['last_grade'] ) . '%' : '—' ) );
			} elseif ( 'certificates' === $type ) {
				$cert = ( $certificates && method_exists( $certificates, 'get_certificate_status_for_student_course' ) )
					? (array) $certificates->get_certificate_status_for_student_course( $student_id, $course_id )
					: array();
				$headers = array( __( 'Nombre', 'atora-lms' ), __( 'Email', 'atora-lms' ), __( 'Estado certificado', 'atora-lms' ), __( 'Requisitos pendientes', 'atora-lms' ) );
				$missing = isset( $cert['missing_requirements'] ) && is_array( $cert['missing_requirements'] ) ? implode( ' | ', array_map( 'sanitize_text_field', $cert['missing_requirements'] ) ) : '';
				$rows[] = array( $name, $email, sanitize_key( (string) ( $cert['status'] ?? 'pending' ) ), $missing );
			} elseif ( 'risk' === $type ) {
				$headers = array( __( 'Nombre', 'atora-lms' ), __( 'Email', 'atora-lms' ), __( 'Riesgo', 'atora-lms' ), __( 'Motivos', 'atora-lms' ), __( 'Acción recomendada', 'atora-lms' ) );
				$reasons = isset( $status['risk_reasons'] ) && is_array( $status['risk_reasons'] ) ? implode( ' | ', array_map( 'sanitize_text_field', $status['risk_reasons'] ) ) : '';
				$rows[] = array( $name, $email, $this->get_risk_level_label( sanitize_key( (string) ( $status['risk_level'] ?? 'unknown' ) ) ), $reasons, sanitize_text_field( (string) ( $status['recommended_action'] ?? '' ) ) );
			} else {
				$headers = array( __( 'Nombre', 'atora-lms' ), __( 'Email', 'atora-lms' ), __( 'Progreso', 'atora-lms' ), __( 'Promedio', 'atora-lms' ), __( 'Riesgo', 'atora-lms' ) );
				$rows[] = array( $name, $email, absint( $status['progress_percent'] ?? 0 ) . '%', ( null !== ( $status['final_average'] ?? null ) ? absint( $status['final_average'] ) . '%' : '—' ), $this->get_risk_level_label( sanitize_key( (string) ( $status['risk_level'] ?? 'unknown' ) ) ) );
			}
		}

		if ( empty( $rows ) || empty( $headers ) ) {
			return array();
		}

		$stream = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $stream ) {
			return array();
		}
		fputcsv( $stream, $headers ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		foreach ( $rows as $row ) {
			fputcsv( $stream, $row ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rewind( $stream );
		$csv = stream_get_contents( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! is_string( $csv ) || '' === $csv ) {
			return array();
		}

		return array(
			'filename' => sprintf( 'atora-%s-course-%d-%s.csv', $type, $course_id, gmdate( 'Ymd-His' ) ),
			'content'  => $csv,
		);
	}

	/**
	 * Cursos del docente.
	 *
	 * @param int $teacher_id Docente.
	 * @return array<int,int>
	 */
	protected function get_teacher_course_ids( $teacher_id ) {
		$teacher_id = absint( $teacher_id );
		if ( ! $teacher_id ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'lm_course',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'author'         => $teacher_id,
				'fields'         => 'ids',
				'posts_per_page' => self::MAX_COURSES,
				'no_found_rows'  => true,
			)
		);
		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Estudiantes inscritos por curso.
	 *
	 * @param int $course_id Curso.
	 * @return array<int,int>
	 */
	protected function get_course_student_ids( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id || ! class_exists( 'CLMS_Helper' ) || ! method_exists( 'CLMS_Helper', 'get_enrolled_student_ids' ) ) {
			return array();
		}
		$ids = CLMS_Helper::get_enrolled_student_ids( $course_id );
		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Cuenta pendientes por curso.
	 *
	 * @param int $course_id Curso.
	 * @return int
	 */
	protected function count_pending_reviews_by_course( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return 0;
		}
		$query = new WP_Query(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_clms_submission_course_id',
						'value' => $course_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'     => '_clms_submission_status',
						'value'   => array( 'submitted', 'in_review', 'needs_revision', 'returned' ),
						'compare' => 'IN',
					),
				),
				'no_found_rows' => false,
			)
		);
		return absint( $query->found_posts );
	}

	/**
	 * Cuenta certificados emitidos (válidos/revocados).
	 *
	 * @return int
	 */
	protected function count_issued_certificates() {
		if ( ! class_exists( 'CLMS_Certificates' ) ) {
			return 0;
		}
		$index = get_option( CLMS_Certificates::VERIFY_INDEX_OPTION, array() );
		$index = is_array( $index ) ? $index : array();
		return count( $index );
	}

	/**
	 * Promedio entero.
	 *
	 * @param array $values Valores.
	 * @return int
	 */
	protected function average_int( $values ) {
		$values = array_values(
			array_filter(
				array_map( 'absint', (array) $values ),
				static function ( $value ) {
					return $value >= 0;
				}
			)
		);
		if ( empty( $values ) ) {
			return 0;
		}
		return (int) round( array_sum( $values ) / count( $values ) );
	}

	/**
	 * Label de usuario.
	 *
	 * @param int $user_id Usuario.
	 * @return string
	 */
	protected function get_user_label( $user_id ) {
		$user_id = absint( $user_id );
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return __( 'Usuario', 'atora-lms' );
		}
		return sanitize_text_field( (string) ( $user->display_name ? $user->display_name : $user->user_login ) );
	}

	/**
	 * Estadística de competencias fuerte/débil.
	 *
	 * @param array $totals Totales.
	 * @return array<string,mixed>
	 */
	protected function build_competency_stats( $totals ) {
		$totals = is_array( $totals ) ? $totals : array();
		if ( empty( $totals ) ) {
			return array(
				'strongest' => array( 'title' => '', 'avg' => 0 ),
				'weakest'   => array( 'title' => '', 'avg' => 0 ),
			);
		}

		$rows = array();
		foreach ( $totals as $title => $row ) {
			$row = is_array( $row ) ? $row : array();
			$count = absint( $row['count'] ?? 0 );
			$sum   = absint( $row['sum'] ?? 0 );
			if ( '' === (string) $title || $count <= 0 ) {
				continue;
			}
			$rows[] = array(
				'title' => sanitize_text_field( (string) $title ),
				'avg'   => (int) round( $sum / $count ),
			);
		}

		if ( empty( $rows ) ) {
			return array(
				'strongest' => array( 'title' => '', 'avg' => 0 ),
				'weakest'   => array( 'title' => '', 'avg' => 0 ),
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return absint( $b['avg'] ) <=> absint( $a['avg'] );
			}
		);

		return array(
			'strongest' => $rows[0],
			'weakest'   => $rows[ count( $rows ) - 1 ],
		);
	}

	/**
	 * Devuelve key con mayor valor.
	 *
	 * @param array $map Mapa.
	 * @return string
	 */
	protected function max_key_by_value( $map ) {
		$map = is_array( $map ) ? $map : array();
		if ( empty( $map ) ) {
			return '';
		}
		arsort( $map );
		$key = key( $map );
		return sanitize_text_field( (string) $key );
	}

	/**
	 * Label de abandono.
	 *
	 * @param array $dropoff_totals Totales.
	 * @return string
	 */
	protected function build_dropoff_label( $dropoff_totals ) {
		$dropoff_totals = is_array( $dropoff_totals ) ? $dropoff_totals : array();
		if ( empty( $dropoff_totals ) ) {
			return '';
		}
		$max_rate = -1;
		$max_course = 0;
		foreach ( $dropoff_totals as $course_id => $row ) {
			$row   = is_array( $row ) ? $row : array();
			$total = absint( $row['total'] ?? 0 );
			$inactive = absint( $row['inactive'] ?? 0 );
			if ( $total <= 0 ) {
				continue;
			}
			$rate = (int) round( ( $inactive / $total ) * 100 );
			if ( $rate > $max_rate ) {
				$max_rate = $rate;
				$max_course = absint( $course_id );
			}
		}
		if ( ! $max_course ) {
			return '';
		}
		return sprintf(
			/* translators: 1: curso, 2: porcentaje */
			__( '%1$s (%2$d%% de inactividad)', 'atora-lms' ),
			get_the_title( $max_course ),
			max( 0, $max_rate )
		);
	}

	/**
	 * Inactividad simple por curso.
	 *
	 * @param int $student_id Estudiante.
	 * @param int $course_id  Curso.
	 * @return bool
	 */
	protected function is_student_inactive_in_course( $student_id, $course_id ) {
		$student_id = absint( $student_id );
		$course_id  = absint( $course_id );
		if ( ! $student_id || ! $course_id || ! class_exists( 'CLMS_Helper' ) ) {
			return false;
		}

		$tracker = clms_core('CLMS_Student_Activity_Tracker');
		if ( $tracker && method_exists( $tracker, 'get_student_activity_summary' ) ) {
			$snapshot = (array) $tracker->get_student_activity_summary( $student_id, $course_id );
			$raw_date = ! empty( $snapshot['course_last_access'] )
				? sanitize_text_field( (string) $snapshot['course_last_access'] )
				: sanitize_text_field( (string) ( $snapshot['last_access'] ?? '' ) );
			if ( '' !== $raw_date ) {
				$last_ts = strtotime( $raw_date );
				if ( $last_ts ) {
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
			}
		}

		$lesson_ids = (array) CLMS_Helper::get_course_lessons( $course_id );
		$lesson_ids = array_values( array_filter( array_map( 'absint', $lesson_ids ) ) );
		if ( empty( $lesson_ids ) ) {
			return false;
		}

		$submissions = get_posts(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $student_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'     => '_clms_submission_lesson_id',
						'value'   => $lesson_ids,
						'compare' => 'IN',
					),
				),
				'no_found_rows'  => true,
			)
		);

		if ( empty( $submissions ) ) {
			return true;
		}

		$last_submission_id = absint( $submissions[0] );
		$last_modified = get_post_field( 'post_modified_gmt', $last_submission_id );
		$last_ts = $last_modified ? strtotime( (string) $last_modified ) : 0;

		return ! $last_ts || ( time() - $last_ts ) >= ( 14 * DAY_IN_SECONDS );
	}

	/**
	 * Cuenta envíos revisados/graded por curso.
	 *
	 * @param int $course_id Curso.
	 * @return int
	 */
	protected function count_reviewed_submissions_by_course( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return 0;
		}
		$query = new WP_Query(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_clms_submission_course_id',
						'value' => $course_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'     => '_clms_submission_status',
						'value'   => array( 'graded', 'approved', 'published' ),
						'compare' => 'IN',
					),
				),
				'no_found_rows' => false,
			)
		);
		return absint( $query->found_posts );
	}

	/**
	 * Cuenta envíos vencidos por curso (si hay due date).
	 *
	 * @param int $course_id Curso.
	 * @return int
	 */
	protected function count_late_submissions_by_course( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return 0;
		}
		$query = new WP_Query(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array(
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
			return 0;
		}
		$count = 0;
		foreach ( $query->posts as $submission_id ) {
			$submission_id = absint( $submission_id );
			$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$due_raw   = $lesson_id ? (string) get_post_meta( $lesson_id, '_clms_due_date', true ) : '';
			if ( '' === trim( $due_raw ) ) {
				continue;
			}
			$due_ts = strtotime( $due_raw . ' 23:59:59' );
			$created = get_post_field( 'post_date_gmt', $submission_id );
			$created_ts = $created ? strtotime( (string) $created ) : 0;
			if ( $due_ts && $created_ts && $created_ts > $due_ts ) {
				$count++;
			}
		}
		return absint( $count );
	}

	protected function count_students_near_certification( $course_id, array $student_ids ) {
		$course_id = absint( $course_id );
		$student_ids = array_values( array_filter( array_map( 'absint', $student_ids ) ) );
		if ( ! $course_id || empty( $student_ids ) ) {
			return 0;
		}
		$certificates = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Certificates') : null;
		if ( ! $certificates || ! method_exists( $certificates, 'get_certificate_status_for_student_course' ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $student_ids as $student_id ) {
			$cert = (array) $certificates->get_certificate_status_for_student_course( $student_id, $course_id );
			$key  = sanitize_key( (string) ( $cert['status'] ?? 'pending' ) );
			if ( 'eligible' === $key ) {
				$count++;
				continue;
			}
			$progress = absint( $cert['progress_percent'] ?? 0 );
			$avg      = isset( $cert['final_average'] ) && is_numeric( $cert['final_average'] ) ? absint( $cert['final_average'] ) : 0;
			$req_prog = absint( $cert['required_progress'] ?? 100 );
			$req_avg  = absint( $cert['passing_grade'] ?? 70 );
			if ( $progress >= max( 0, $req_prog - 10 ) && $avg >= max( 0, $req_avg - 10 ) ) {
				$count++;
			}
		}
		return absint( $count );
	}

	protected function get_course_activity_with_more_dropoff( $course_id, array $student_ids ) {
		$course_id = absint( $course_id );
		$student_ids = array_values( array_filter( array_map( 'absint', $student_ids ) ) );
		if ( ! $course_id || empty( $student_ids ) || ! class_exists( 'CLMS_Helper' ) ) {
			return '';
		}

		$lesson_ids = (array) CLMS_Helper::get_course_lessons( $course_id );
		$lesson_ids = array_values( array_filter( array_map( 'absint', $lesson_ids ) ) );
		if ( empty( $lesson_ids ) ) {
			return '';
		}

		$max_missing = -1;
		$target_lesson = 0;
		foreach ( $lesson_ids as $lesson_id ) {
			$missing = 0;
			foreach ( $student_ids as $student_id ) {
				$completed = (array) get_user_meta( $student_id, '_clms_completed_lessons', true );
				$completed = array_map( 'absint', $completed );
				if ( ! in_array( $lesson_id, $completed, true ) ) {
					$missing++;
				}
			}
			if ( $missing > $max_missing ) {
				$max_missing = $missing;
				$target_lesson = $lesson_id;
			}
		}

		return $target_lesson ? sanitize_text_field( (string) get_the_title( $target_lesson ) ) : '';
	}

	protected function estimate_teacher_response_time( $teacher_id, array $course_ids ) {
		$teacher_id = absint( $teacher_id );
		$course_ids = array_values( array_filter( array_map( 'absint', $course_ids ) ) );
		if ( ! $teacher_id || empty( $course_ids ) ) {
			return null;
		}

		$submissions = get_posts(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 80,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_clms_submission_course_id',
						'value'   => $course_ids,
						'compare' => 'IN',
					),
				),
				'no_found_rows'  => true,
			)
		);
		if ( empty( $submissions ) ) {
			return null;
		}

		$hours = array();
		foreach ( $submissions as $submission_id ) {
			$submission_id = absint( $submission_id );
			$graded_by = absint( get_post_meta( $submission_id, '_clms_submission_graded_by', true ) );
			if ( $graded_by && $graded_by !== $teacher_id ) {
				continue;
			}
			$created_ts = strtotime( (string) get_post_field( 'post_date_gmt', $submission_id ) );
			$updated_ts = strtotime( (string) get_post_field( 'post_modified_gmt', $submission_id ) );
			if ( ! $created_ts || ! $updated_ts || $updated_ts <= $created_ts ) {
				continue;
			}
			$hours[] = ( $updated_ts - $created_ts ) / HOUR_IN_SECONDS;
		}
		if ( empty( $hours ) ) {
			return null;
		}
		return (int) round( array_sum( $hours ) / count( $hours ) );
	}

	protected function get_student_recent_activity( $student_id, $limit = 8 ) {
		$student_id = absint( $student_id );
		$limit = max( 1, min( 30, absint( $limit ) ) );
		if ( ! $student_id ) {
			return array();
		}
		$rows = array();
		$submissions = get_posts(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $student_id,
						'type'  => 'NUMERIC',
					),
				),
				'no_found_rows'  => true,
			)
		);
		foreach ( $submissions as $submission_id ) {
			$submission_id = absint( $submission_id );
			$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$rows[] = array(
				'type'      => 'submission',
				'title'     => $lesson_id ? get_the_title( $lesson_id ) : get_the_title( $submission_id ),
				'date'      => get_post_field( 'post_modified', $submission_id ),
				'status'    => sanitize_key( (string) get_post_meta( $submission_id, '_clms_submission_status', true ) ),
			);
		}
		return $rows;
	}

	protected function get_teacher_recent_activity( $teacher_id, $limit = 10 ) {
		$teacher_id = absint( $teacher_id );
		$limit = max( 1, min( 30, absint( $limit ) ) );
		$course_ids = $this->get_teacher_course_ids( $teacher_id );
		if ( empty( $course_ids ) ) {
			return array();
		}
		$submissions = get_posts(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_clms_submission_course_id',
						'value'   => $course_ids,
						'compare' => 'IN',
					),
				),
				'no_found_rows'  => true,
			)
		);
		$rows = array();
		foreach ( $submissions as $submission_id ) {
			$submission_id = absint( $submission_id );
			$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
			$course_id  = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
			$rows[] = array(
				'submission_id' => $submission_id,
				'student_name'  => $this->get_user_label( $student_id ),
				'course_title'  => get_the_title( $course_id ),
				'status'        => sanitize_key( (string) get_post_meta( $submission_id, '_clms_submission_status', true ) ),
				'date'          => get_post_field( 'post_modified', $submission_id ),
			);
		}
		return $rows;
	}

	protected function aggregate_risk_items( array $risk_items ) {
		$level = 'low';
		if ( empty( $risk_items ) ) {
			return array(
				'risk_level' => 'low',
				'risk_reasons' => array(),
				'recommended_action' => __( 'Mantener seguimiento regular.', 'atora-lms' ),
			);
		}
		$reasons = array();
		$action = __( 'Revisar alertas por curso y definir plan de acompañamiento.', 'atora-lms' );
		foreach ( $risk_items as $row ) {
			$row = is_array( $row ) ? $row : array();
			$item_level = sanitize_key( (string) ( $row['risk_level'] ?? 'low' ) );
			if ( 'high' === $item_level ) {
				$level = 'high';
			} elseif ( 'medium' === $item_level && 'high' !== $level ) {
				$level = 'medium';
			}
			if ( ! empty( $row['risk_reasons'] ) && is_array( $row['risk_reasons'] ) ) {
				$reasons = array_merge( $reasons, array_map( 'sanitize_text_field', $row['risk_reasons'] ) );
			}
			if ( empty( $row['action'] ) ) {
				continue;
			}
			$action = sanitize_text_field( (string) $row['action'] );
		}
		return array(
			'risk_level'         => $level,
			'risk_reasons'       => array_values( array_unique( array_filter( $reasons ) ) ),
			'recommended_action' => $action,
		);
	}

	protected function sort_map_desc( array $map, $limit = 10 ) {
		$clean = array();
		foreach ( $map as $key => $value ) {
			$label = sanitize_text_field( (string) $key );
			$count = absint( $value );
			if ( '' === $label || $count <= 0 ) {
				continue;
			}
			$clean[ $label ] = $count;
		}
		arsort( $clean );
		return array_slice( $clean, 0, absint( $limit ), true );
	}

	protected function viewer_can_access_student_profile( $viewer_id, $student_id ) {
		$viewer_id  = absint( $viewer_id );
		$student_id = absint( $student_id );
		if ( ! $viewer_id || ! $student_id ) {
			return false;
		}
		if ( $viewer_id === $student_id ) {
			return true;
		}
		if ( current_user_can( 'manage_options' ) || current_user_can( 'clms_access_admin' ) ) {
			return true;
		}
		if ( ! class_exists( 'CLMS_Helper' ) ) {
			return false;
		}
		$courses = (array) ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $student_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $student_id ) );
		foreach ( $courses as $course_id ) {
			$course_id = absint( $course_id );
			if ( $course_id && CLMS_Helper::user_can_manage_lms( $course_id ) ) {
				return true;
			}
		}
		return false;
	}

	protected function viewer_can_access_course_report( $viewer_id, $course_id ) {
		$viewer_id = absint( $viewer_id );
		$course_id = absint( $course_id );
		if ( ! $viewer_id || ! $course_id ) {
			return false;
		}
		if ( $viewer_id === absint( get_post_field( 'post_author', $course_id ) ) ) {
			return true;
		}
		$teacher_ids = get_post_meta( $course_id, '_clms_course_teacher_ids', true );
		$teacher_ids = array_values( array_filter( array_map( 'absint', (array) $teacher_ids ) ) );
		if ( in_array( $viewer_id, $teacher_ids, true ) ) {
			return true;
		}
		if ( current_user_can( 'manage_options' ) || current_user_can( 'clms_access_admin' ) ) {
			return true;
		}
		return class_exists( 'CLMS_Helper' ) && CLMS_Helper::user_can_manage_lms( $course_id );
	}

	protected function get_commerce_overview() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array(
				'active' => false,
			);
		}

		$orders = function_exists( 'wc_get_orders' )
			? wc_get_orders(
				array(
					'limit'  => 50,
					'status' => array( 'processing', 'completed' ),
					'return' => 'ids',
				)
			)
			: array();
		$orders = array_values( array_filter( array_map( 'absint', (array) $orders ) ) );

		$linked_products = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => '_clms_linked_course_id',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_clms_linked_program_id',
						'compare' => 'EXISTS',
					),
				),
				'no_found_rows'  => true,
			)
		);

		$activation_pending = 0;
		foreach ( $orders as $order_id ) {
			$courses = (array) get_post_meta( $order_id, '_clms_commerce_enrolled_courses', true );
			$programs = (array) get_post_meta( $order_id, '_clms_commerce_enrolled_programs', true );
			if ( empty( $courses ) && empty( $programs ) ) {
				$activation_pending++;
			}
		}

		return array(
			'active'               => true,
			'linked_products'      => count( array_unique( array_map( 'absint', (array) $linked_products ) ) ),
			'orders_processed'     => count( $orders ),
			'activations_pending'  => absint( $activation_pending ),
		);
	}

	/**
	 * Estructura vacía docente.
	 *
	 * @return array<string,mixed>
	 */
	protected function get_empty_teacher_report() {
		return array(
			'average_course_grade'      => 0,
			'students_at_risk'          => 0,
			'weakest_competency'        => '',
			'strongest_competency'      => '',
			'evidence_with_most_fail'   => '',
			'module_with_more_dropoff'  => '',
			'pending_reviews'           => 0,
			'students_near_certificate' => 0,
			'risk_items'                => array(),
			'course_ids'                => array(),
		);
	}

	/**
	 * Traduce nivel técnico de riesgo para uso visible.
	 *
	 * @param string $level Nivel de riesgo.
	 * @return string
	 */
	protected function get_risk_level_label( $level ) {
		$level = sanitize_key( (string) $level );
		$labels = array(
			'high'    => __( 'Alto', 'atora-lms' ),
			'medium'  => __( 'Medio', 'atora-lms' ),
			'low'     => __( 'Bajo', 'atora-lms' ),
			'normal'  => __( 'Bajo', 'atora-lms' ),
			'unknown' => __( 'Sin datos', 'atora-lms' ),
		);

		return isset( $labels[ $level ] ) ? $labels[ $level ] : $labels['unknown'];
	}
}
