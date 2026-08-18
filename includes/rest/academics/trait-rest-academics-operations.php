<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_REST_Academics_Operations_Trait {
	public function get_progress( WP_REST_Request $request ) {
		$user_id = absint( $request['user_id'] );
		$user    = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'clms_user_not_found', __( 'Usuario no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$course_ids = class_exists( 'CLMS_Helper' ) ? ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $user_id ) ) : array();
		$course_ids = $this->filter_course_ids_for_current_viewer( $course_ids, $user_id );
		$grading    = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading') : null;
		$data       = array(
			'user_id' => $user_id,
			'courses' => array(),
		);

		foreach ( $course_ids as $course_id ) {
			$status = ( $grading && method_exists( $grading, 'get_student_course_status' ) )
				? $grading->get_student_course_status( $user_id, $course_id )
				: array();

			$data['courses'][] = array(
				'course_id'         => $course_id,
				'title'             => get_the_title( $course_id ),
				'total_lessons'     => isset( $status['total_lessons'] ) ? absint( $status['total_lessons'] ) : ( class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_lesson_count( $course_id ) : 0 ),
				'completed_lessons' => isset( $status['completed_lessons'] ) ? absint( $status['completed_lessons'] ) : 0,
				'progress_percent'  => isset( $status['progress_percent'] ) ? absint( $status['progress_percent'] ) : 0,
				'pending_activities'=> isset( $status['pending_activities'] ) ? absint( $status['pending_activities'] ) : 0,
				'next_step'         => isset( $status['next_step'] ) ? sanitize_text_field( (string) $status['next_step'] ) : '',
				'risk_level'        => isset( $status['risk_level'] ) ? sanitize_key( (string) $status['risk_level'] ) : 'normal',
				'competencies'      => $this->sanitize_rest_competencies( $status['competencies'] ?? array() ),
				'evidences'         => $this->sanitize_rest_evidences( $status['evidences'] ?? array() ),
				'certificate_requirements' => array(
					'missing'   => isset( $status['missing_certificate_requirements'] ) && is_array( $status['missing_certificate_requirements'] ) ? array_values( array_map( 'sanitize_text_field', $status['missing_certificate_requirements'] ) ) : array(),
					'status'    => isset( $status['certificate_status'] ) ? sanitize_key( (string) $status['certificate_status'] ) : 'pending',
				),
			);
		}

		return rest_ensure_response( $data );
	}

	public function get_grades( WP_REST_Request $request ) {
		$user_id    = absint( $request['user_id'] );
		$user       = get_user_by( 'id', $user_id );
		$grading    = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading') : null;
		$course_ids = class_exists( 'CLMS_Helper' ) ? ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $user_id ) ) : array();
		$course_ids = $this->filter_course_ids_for_current_viewer( $course_ids, $user_id );
		$items      = array();

		if ( ! $user ) {
			return new WP_Error( 'clms_user_not_found', __( 'Usuario no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		foreach ( $course_ids as $course_id ) {
			$status = ( $grading && method_exists( $grading, 'get_student_course_status' ) )
				? $grading->get_student_course_status( $user_id, $course_id )
				: array();

			$items[] = array(
				'course_id'         => $course_id,
				'course_title'      => get_the_title( $course_id ),
				'average_grade'     => isset( $status['final_average'] ) ? absint( $status['final_average'] ) : '',
				'total_lessons'     => isset( $status['total_lessons'] ) ? absint( $status['total_lessons'] ) : 0,
				'completed_lessons' => isset( $status['completed_lessons'] ) ? absint( $status['completed_lessons'] ) : 0,
				'progress_percent'  => isset( $status['progress_percent'] ) ? absint( $status['progress_percent'] ) : 0,
				'pending_activities'=> isset( $status['pending_activities'] ) ? absint( $status['pending_activities'] ) : 0,
				'last_feedback'     => isset( $status['last_feedback'] ) ? sanitize_text_field( (string) $status['last_feedback'] ) : '',
				'next_step'         => isset( $status['next_step'] ) ? sanitize_text_field( (string) $status['next_step'] ) : '',
				'risk_level'        => isset( $status['risk_level'] ) ? sanitize_key( (string) $status['risk_level'] ) : 'normal',
				'certificate_status'=> isset( $status['certificate_status'] ) ? sanitize_key( (string) $status['certificate_status'] ) : 'pending',
				'certificate_requirements' => array(
					'missing'   => isset( $status['missing_certificate_requirements'] ) && is_array( $status['missing_certificate_requirements'] ) ? array_values( array_map( 'sanitize_text_field', $status['missing_certificate_requirements'] ) ) : array(),
				),
				'competencies'      => $this->sanitize_rest_competencies( $status['competencies'] ?? array() ),
				'evidences'         => $this->sanitize_rest_evidences( $status['evidences'] ?? array() ),
				'gamification'      => isset( $status['gamification_summary'] ) && is_array( $status['gamification_summary'] ) ? $status['gamification_summary'] : array(),
			);
		}

		return rest_ensure_response(
			array(
				'user_id' => $user_id,
				'grades'  => $items,
			)
		);
	}

	/**
	 * Perfil académico institucional del estudiante.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_student_profile_report( WP_REST_Request $request ) {
		$student_id = absint( $request['user_id'] );
		if ( ! $student_id || ! get_user_by( 'id', $student_id ) ) {
			return new WP_Error( 'clms_user_not_found', __( 'Estudiante no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$service = $this->resolve_academic_report_service();
		if ( ! $service ) {
			return new WP_Error( 'clms_academic_report_unavailable', __( 'El servicio de analítica académica no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		$profile = (array) $service->get_student_profile_report( $student_id, get_current_user_id() );
		if ( empty( $profile ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para ver este perfil académico.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response( $profile );
	}

	/**
	 * Perfil docente institucional.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_teacher_profile_report( WP_REST_Request $request ) {
		$teacher_id = absint( $request['user_id'] );
		if ( ! $teacher_id || ! get_user_by( 'id', $teacher_id ) ) {
			return new WP_Error( 'clms_teacher_not_found', __( 'Docente no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$current_user_id = get_current_user_id();
		$can_view = ( $current_user_id === $teacher_id )
			|| current_user_can( 'manage_options' )
			|| CLMS_Access::can_manage_courses()
			|| CLMS_Access::can_view_teacher_dashboard();
		if ( ! $can_view ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para ver este perfil docente.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$service = $this->resolve_academic_report_service();
		if ( ! $service ) {
			return new WP_Error( 'clms_academic_report_unavailable', __( 'El servicio de analítica académica no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( (array) $service->get_teacher_profile_report( $teacher_id ) );
	}

	/**
	 * Reporte académico avanzado por curso.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_course_advanced_report( WP_REST_Request $request ) {
		$course_id = absint( $request['course_id'] );
		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return new WP_Error( 'clms_course_not_found', __( 'Curso no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'manage_options' ) && ! $this->current_user_can_manage_post_resource( $course_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para ver este reporte de curso.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$service = $this->resolve_academic_report_service();
		if ( ! $service ) {
			return new WP_Error( 'clms_academic_report_unavailable', __( 'El servicio de analítica académica no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		$report = (array) $service->get_course_advanced_report( $course_id, get_current_user_id() );
		if ( empty( $report ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No se pudo construir el reporte de curso para este usuario.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response( $report );
	}

	/**
	 * Reporte global administrativo.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_admin_advanced_report( WP_REST_Request $request ) {
		unset( $request );
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'clms_forbidden', __( 'Solo administradores pueden ver el reporte global.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$service = $this->resolve_academic_report_service();
		if ( ! $service ) {
			return new WP_Error( 'clms_academic_report_unavailable', __( 'El servicio de analítica académica no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( (array) $service->get_admin_report() );
	}

	/**
	 * Reporte de certificación.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_certification_report( WP_REST_Request $request ) {
		$course_id = absint( $request->get_param( 'course_id' ) );
		$is_admin  = current_user_can( 'manage_options' );

		if ( ! $is_admin && ! CLMS_Access::can_manage_courses() && ! CLMS_Access::can_view_teacher_dashboard() ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para ver reportes de certificación.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		if ( ! $is_admin && ! $course_id ) {
			return new WP_Error( 'clms_missing_course_id', __( 'Debes indicar un curso para consultar este reporte.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( $course_id && ! $is_admin && ! $this->current_user_can_manage_post_resource( $course_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para ver la certificación de este curso.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$service = $this->resolve_academic_report_service();
		if ( ! $service ) {
			return new WP_Error( 'clms_academic_report_unavailable', __( 'El servicio de analítica académica no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( (array) $service->get_certification_report( $course_id ) );
	}

	/**
	 * Indicadores de riesgo por estudiante/curso.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_risk_indicators( WP_REST_Request $request ) {
		$user_id   = absint( $request['user_id'] );
		$course_id = absint( $request['course_id'] );
		if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
			return new WP_Error( 'clms_user_not_found', __( 'Estudiante no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return new WP_Error( 'clms_course_not_found', __( 'Curso no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$current_user_id = get_current_user_id();
		$can_view = false;
		if ( $current_user_id && $current_user_id === $user_id ) {
			$can_view = true;
		} elseif ( current_user_can( 'manage_options' ) ) {
			$can_view = true;
		} elseif ( $this->current_user_can_manage_post_resource( $course_id ) ) {
			$can_view = true;
		}

		if ( ! $can_view ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para ver estos indicadores de riesgo.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$service = $this->resolve_academic_report_service();
		if ( ! $service ) {
			return new WP_Error( 'clms_academic_report_unavailable', __( 'El servicio de analítica académica no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( (array) $service->get_risk_indicators( $user_id, $course_id ) );
	}

	public function get_submissions( WP_REST_Request $request ) {
		$user_id = absint( $request['user_id'] );

		if ( ! class_exists( 'CLMS_Submission' ) ) {
			return new WP_Error( 'clms_submission_missing', __( 'El módulo de entregas no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		$ids = get_posts(
			array(
				'post_type'      => CLMS_Submission::CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $user_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		$items = array();

		foreach ( $ids as $submission_id ) {
			if ( ! $this->current_user_can_view_submission_resource( $submission_id, $user_id ) ) {
				continue;
			}

			$item = $this->prepare_submission_response( $submission_id );
			if ( ! empty( $item ) ) {
				$items[] = $item;
			}
		}

		return rest_ensure_response(
			array(
				'user_id'     => $user_id,
				'submissions' => $items,
			)
		);
	}

	public function get_submissions_by_lesson( WP_REST_Request $request ) {
		$user_id   = absint( $request['user_id'] );
		$lesson_id = absint( $request['lesson_id'] );

		if ( ! class_exists( 'CLMS_Submission' ) ) {
			return new WP_Error( 'clms_submission_missing', __( 'El módulo de entregas no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		if ( get_current_user_id() !== $user_id && ! current_user_can( 'manage_options' ) && ! $this->current_user_can_manage_post_resource( $lesson_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para ver entregas de esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$ids = get_posts(
			array(
				'post_type'      => CLMS_Submission::CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $user_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => '_clms_submission_lesson_id',
						'value' => $lesson_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		$items = array();

		foreach ( $ids as $submission_id ) {
			if ( ! $this->current_user_can_view_submission_resource( $submission_id, $user_id ) ) {
				continue;
			}

			$item = $this->prepare_submission_response( $submission_id, $lesson_id );
			if ( ! empty( $item ) ) {
				$items[] = $item;
			}
		}

		return rest_ensure_response(
			array(
				'user_id'     => $user_id,
				'lesson_id'   => $lesson_id,
				'submissions' => $items,
			)
		);
	}

	public function bulk_students_action( WP_REST_Request $request ) {
		$data        = $this->get_json_or_body_params( $request );
		$action      = sanitize_key( (string) ( $data['action'] ?? '' ) );
		$course_id   = absint( $data['course_id'] ?? 0 );
		$student_ids = isset( $data['student_ids'] ) && is_array( $data['student_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', $data['student_ids'] ) ) ) )
			: array();

		if ( ! in_array( $action, array( 'message', 'unenroll', 'export_csv', 'flag_followup' ), true ) ) {
			return new WP_Error( 'clms_bulk_action_invalid', __( 'Acción masiva no válida.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( ! $course_id ) {
			return new WP_Error( 'clms_bulk_course_missing', __( 'El curso es obligatorio.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$course = get_post( $course_id );
		if ( ! $course || 'lm_course' !== $course->post_type ) {
			return new WP_Error( 'clms_bulk_course_not_found', __( 'Curso no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! $this->current_user_can_manage_post_resource( $course_id ) ) {
			return new WP_Error( 'clms_bulk_forbidden', __( 'No tienes permisos para gestionar estudiantes en este curso.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		if ( empty( $student_ids ) ) {
			return new WP_Error( 'clms_bulk_students_missing', __( 'Debes seleccionar al menos un estudiante.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$enrolled_ids = $this->get_course_enrolled_student_ids( $course_id );
		$student_ids  = array_values( array_intersect( $student_ids, $enrolled_ids ) );

		if ( empty( $student_ids ) ) {
			return new WP_Error( 'clms_bulk_students_not_enrolled', __( 'Los estudiantes seleccionados no están inscritos en este curso.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( 'message' === $action ) {
			$message_text = sanitize_textarea_field( (string) ( $data['message_text'] ?? '' ) );

			if ( '' === $message_text ) {
				return new WP_Error( 'clms_bulk_message_empty', __( 'El mensaje no puede estar vacío.', 'atora-lms' ), array( 'status' => 400 ) );
			}

			$messaging = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Messaging') : null;
			if ( ! $messaging || ! method_exists( $messaging, 'send_message' ) ) {
				return new WP_Error( 'clms_bulk_message_unavailable', __( 'El módulo de mensajería no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
			}

			$current_user = wp_get_current_user();
			$sender_name  = $current_user instanceof WP_User ? ( $current_user->display_name ?: $current_user->user_login ) : '';
			$title        = sprintf(
				/* translators: %s: course title. */
				__( 'Mensaje del curso: %s', 'atora-lms' ),
				get_the_title( $course_id )
			);
			$link         = get_permalink( $course_id );
			$sent         = 0;

			foreach ( $student_ids as $student_id ) {
				$sent_item = $messaging->send_message(
					$student_id,
					array(
						'message_type'      => 'teacher_notice',
						'sender_type'       => 'teacher',
						'sender_id'         => get_current_user_id(),
						'sender_name'       => $sender_name,
						'title'             => $title,
						'message'           => $message_text,
						'link'              => is_string( $link ) ? $link : '',
						'course_id'         => $course_id,
						'thread_type'       => 'course',
						'thread_id'         => 'course-' . $course_id,
						'thread_label'      => get_the_title( $course_id ),
						'automation_source' => 'teacher_dashboard_bulk',
					)
				);

				if ( ! empty( $sent_item ) ) {
					$sent++;
				}
			}

			return rest_ensure_response(
				array(
					'success'   => true,
					'action'    => $action,
					'course_id' => $course_id,
					'processed' => count( $student_ids ),
					'sent'      => $sent,
					'failed'    => max( 0, count( $student_ids ) - $sent ),
					'message'   => sprintf(
						/* translators: %d: amount of sent messages. */
						_n( 'Se envió %d mensaje.', 'Se enviaron %d mensajes.', $sent, 'atora-lms' ),
						$sent
					),
				)
			);
		}

		if ( 'unenroll' === $action ) {
			$enrollment_manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Enrollment_Manager') : null;
			if ( ! $enrollment_manager && class_exists( 'CLMS_Enrollment_Manager' ) ) {
				$enrollment_manager = new CLMS_Enrollment_Manager();
			}
			if ( ! $enrollment_manager || ! method_exists( $enrollment_manager, 'unenroll' ) ) {
				return new WP_Error( 'clms_bulk_unenroll_unavailable', __( 'El módulo de matrículas no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
			}

			$unenrolled = 0;
			foreach ( $student_ids as $student_id ) {
				if ( $enrollment_manager->unenroll( $student_id, $course_id ) ) {
					$unenrolled++;
					delete_user_meta( $student_id, '_clms_flagged_for_followup_' . $course_id );
				}
			}

			return rest_ensure_response(
				array(
					'success'    => true,
					'action'     => $action,
					'course_id'  => $course_id,
					'processed'  => count( $student_ids ),
					'unenrolled' => $unenrolled,
					'failed'     => max( 0, count( $student_ids ) - $unenrolled ),
					'message'    => sprintf(
						/* translators: %d: amount of unenrolled students. */
						_n( 'Se desmatriculó a %d estudiante.', 'Se desmatriculó a %d estudiantes.', $unenrolled, 'atora-lms' ),
						$unenrolled
					),
				)
			);
		}

		if ( 'flag_followup' === $action ) {
			$timestamp = current_time( 'timestamp' );
			$flagged   = 0;

			foreach ( $student_ids as $student_id ) {
				$updated = update_user_meta( $student_id, '_clms_flagged_for_followup_' . $course_id, $timestamp );
				if ( false !== $updated ) {
					$flagged++;
				}
			}

			return rest_ensure_response(
				array(
					'success'   => true,
					'action'    => $action,
					'course_id' => $course_id,
					'processed' => count( $student_ids ),
					'flagged'   => $flagged,
					'failed'    => max( 0, count( $student_ids ) - $flagged ),
					'message'   => sprintf(
						/* translators: %d: amount of flagged students. */
						_n( 'Se marcó seguimiento para %d estudiante.', 'Se marcó seguimiento para %d estudiantes.', $flagged, 'atora-lms' ),
						$flagged
					),
				)
			);
		}

		$rows = $this->build_students_bulk_export_rows( $course_id, $student_ids );

		$stream = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_resource( $stream ) ) {
			return new WP_Error( 'clms_bulk_export_failed', __( 'No se pudo generar el archivo CSV.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		fwrite( $stream, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $stream, array( __( 'Nombre', 'atora-lms' ), __( 'Email', 'atora-lms' ), __( 'Progreso', 'atora-lms' ), __( 'Última actividad', 'atora-lms' ), __( 'Nota promedio', 'atora-lms' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		foreach ( $rows as $row ) {
			fputcsv(
				$stream,
				array(
					$row['name'],
					$row['email'],
					$row['progress'],
					$row['last_activity'],
					$row['average_grade'],
				)
			); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		rewind( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$csv = stream_get_contents( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! is_string( $csv ) ) {
			return new WP_Error( 'clms_bulk_export_content_failed', __( 'No se pudo leer el archivo CSV.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		$filename = sprintf( 'atora-students-course-%d-%s.csv', $course_id, gmdate( 'Ymd-His' ) );

		return rest_ensure_response(
			array(
				'success'    => true,
				'action'     => $action,
				'course_id'  => $course_id,
				'processed'  => count( $student_ids ),
				'count'      => count( $rows ),
				'filename'   => $filename,
				'csv_base64' => base64_encode( $csv ),
				'message'    => sprintf(
					/* translators: %d: amount of exported students. */
					_n( 'Se exportó %d estudiante.', 'Se exportaron %d estudiantes.', count( $rows ), 'atora-lms' ),
					count( $rows )
				),
			)
		);
	}

	public function get_lesson_presets( WP_REST_Request $request ) {
		unset( $request );

		return rest_ensure_response(
			array(
				'items' => array_values( $this->get_lesson_presets_store() ),
			)
		);
	}

	public function create_lesson_preset( WP_REST_Request $request ) {
		$data = $this->get_json_or_body_params( $request );
		$name = sanitize_text_field( (string) ( $data['name'] ?? '' ) );

		if ( '' === $name ) {
			return new WP_Error( 'clms_preset_name_required', __( 'El nombre de la plantilla es obligatorio.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$config = isset( $data['config'] ) && is_array( $data['config'] ) ? $data['config'] : array();
		$config = $this->normalize_lesson_quick_edit_data( $config );

		if ( empty( $config ) ) {
			return new WP_Error( 'clms_preset_config_required', __( 'La plantilla debe incluir al menos un ajuste.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$presets = $this->get_lesson_presets_store();
		$id      = sanitize_key( 'preset_' . wp_generate_password( 12, false, false ) );

		foreach ( $presets as $preset ) {
			if ( isset( $preset['id'] ) && $preset['id'] === $id ) {
				$id = sanitize_key( 'preset_' . wp_generate_password( 14, false, false ) );
				break;
			}
		}

		$new_preset = array(
			'id'     => $id,
			'name'   => $name,
			'config' => $config,
		);

		$presets[] = $new_preset;
		update_option( 'clms_lesson_presets', array_values( $presets ), false );

		return new WP_REST_Response(
			array(
				'success' => true,
				'item'    => $new_preset,
				'message' => __( 'Plantilla guardada correctamente.', 'atora-lms' ),
			),
			201
		);
	}

	public function delete_lesson_preset( WP_REST_Request $request ) {
		$preset_id = sanitize_key( (string) $request['id'] );
		$presets   = $this->get_lesson_presets_store();
		$kept      = array();
		$deleted   = false;

		foreach ( $presets as $preset ) {
			$current_id = isset( $preset['id'] ) ? sanitize_key( (string) $preset['id'] ) : '';
			if ( $current_id && $current_id === $preset_id ) {
				$deleted = true;
				continue;
			}
			$kept[] = $preset;
		}

		if ( ! $deleted ) {
			return new WP_Error( 'clms_preset_not_found', __( 'Plantilla no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		update_option( 'clms_lesson_presets', array_values( $kept ), false );

		return rest_ensure_response(
			array(
				'success' => true,
				'id'      => $preset_id,
				'message' => __( 'Plantilla eliminada.', 'atora-lms' ),
			)
		);
	}

	public function apply_lesson_preset( WP_REST_Request $request ) {
		$data        = $this->get_json_or_body_params( $request );
		$preset_id   = sanitize_key( (string) ( $data['preset_id'] ?? '' ) );
		$course_id   = absint( $data['course_id'] ?? 0 );
		$lesson_ids  = isset( $data['lesson_ids'] ) && is_array( $data['lesson_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', $data['lesson_ids'] ) ) ) )
			: array();

		if ( ! $preset_id ) {
			return new WP_Error( 'clms_apply_preset_missing', __( 'Debes seleccionar una plantilla.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return new WP_Error( 'clms_apply_preset_course', __( 'Curso inválido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( ! $this->current_user_can_manage_post_resource( $course_id ) ) {
			return new WP_Error( 'clms_apply_preset_forbidden', __( 'No tienes permisos para actualizar este curso.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		if ( empty( $lesson_ids ) ) {
			return new WP_Error( 'clms_apply_preset_lessons', __( 'Debes seleccionar al menos una lección.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$preset = null;
		foreach ( $this->get_lesson_presets_store() as $item ) {
			$item_id = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
			if ( $item_id && $item_id === $preset_id ) {
				$preset = $item;
				break;
			}
		}

		if ( ! $preset || empty( $preset['config'] ) || ! is_array( $preset['config'] ) ) {
			return new WP_Error( 'clms_apply_preset_not_found', __( 'No se encontró la plantilla solicitada.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$processed = 0;
		$updated   = 0;

		foreach ( $lesson_ids as $lesson_id ) {
			$lesson = get_post( $lesson_id );
			if ( ! $lesson || 'lm_lesson' !== $lesson->post_type ) {
				continue;
			}

			$processed++;

			if ( ! $this->current_user_can_manage_post_resource( $lesson_id ) ) {
				continue;
			}

			$lesson_course_id = class_exists( 'CLMS_Helper' ) ? absint( CLMS_Helper::get_lesson_course_id( $lesson_id ) ) : 0;
			if ( $lesson_course_id !== $course_id ) {
				continue;
			}

			$this->save_lesson_meta( $lesson_id, $preset['config'] );
			$updated++;
		}

		if ( class_exists( 'CLMS_Helper' ) ) {
			CLMS_Helper::flush_runtime_cache( $course_id );
		}

		return rest_ensure_response(
			array(
				'success'   => true,
				'preset_id' => $preset_id,
				'course_id' => $course_id,
				'processed' => $processed,
				'updated'   => $updated,
				'failed'    => max( 0, $processed - $updated ),
				'message'   => sprintf(
					/* translators: %d: updated lessons count. */
					_n( 'Se actualizó %d lección con la plantilla.', 'Se actualizaron %d lecciones con la plantilla.', $updated, 'atora-lms' ),
					$updated
				),
			)
		);
	}

	public function get_quiz( WP_REST_Request $request ) {
		$lesson_id = absint( $request['lesson_id'] );
		$user_id   = get_current_user_id();

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'clms_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( class_exists( 'CLMS_Helper' ) && ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
			return new WP_Error( 'clms_lesson_locked', __( 'La lección aún no está disponible.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$quiz = $this->get_quiz_data( $lesson_id );

		if ( empty( $quiz['enabled'] ) ) {
			return new WP_Error( 'clms_quiz_not_found', __( 'No hay quiz en esta lección.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$public_questions = array();

		foreach ( $quiz['questions'] as $index => $question ) {
			$public_questions[] = array(
				'index'    => $index,
				'question' => isset( $question['question'] ) ? $question['question'] : '',
				'options'  => isset( $question['options'] ) && is_array( $question['options'] ) ? array_values( $question['options'] ) : array(),
				'type'     => isset( $question['type'] ) ? $question['type'] : 'single',
			);
		}

		return rest_ensure_response(
			array(
				'lesson_id'     => $lesson_id,
				'lesson_title'  => get_the_title( $lesson_id ),
				'enabled'       => true,
				'passing_score' => $quiz['passing_score'],
				'questions'     => $public_questions,
			)
		);
	}

	public function update_quiz( WP_REST_Request $request ) {
		$lesson_id = absint( $request['lesson_id'] );

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'clms_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! $this->current_user_can_manage_post_resource( $lesson_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para editar el quiz de esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$data          = $this->get_json_or_body_params( $request );
		$enabled       = ! empty( $data['enabled'] ) ? 1 : 0;
		$passing_score = isset( $data['passing_score'] ) ? $this->sanitize_score( $data['passing_score'] ) : 70;
		$questions     = isset( $data['questions'] ) && is_array( $data['questions'] ) ? $this->sanitize_quiz_questions( $data['questions'] ) : array();

		update_post_meta( $lesson_id, '_clms_quiz_enabled', $enabled );
		update_post_meta( $lesson_id, '_clms_quiz_passing_score', $passing_score );
		update_post_meta( $lesson_id, '_clms_quiz_questions', $questions );

		return rest_ensure_response(
			array(
				'success'       => true,
				'lesson_id'     => $lesson_id,
				'enabled'       => (bool) $enabled,
				'passing_score' => $passing_score,
				'questions'     => count( $questions ),
			)
		);
	}

	public function submit_quiz( WP_REST_Request $request ) {
		$lesson_id = absint( $request['lesson_id'] );
		$user_id   = get_current_user_id();
		$quiz      = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Quiz') : null;

		if ( ! $quiz || ! method_exists( $quiz, 'grade_quiz_rest' ) ) {
			return new WP_Error( 'clms_quiz_unavailable', __( 'El módulo de quiz no está disponible para REST.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'clms_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( class_exists( 'CLMS_Helper' ) && ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
			return new WP_Error( 'clms_lesson_locked', __( 'No tienes acceso a esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$data    = $this->get_json_or_body_params( $request );
		$answers = isset( $data['answers'] ) && is_array( $data['answers'] ) ? $data['answers'] : array();

		$result = $quiz->grade_quiz_rest( $user_id, $lesson_id, $answers );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

}
