<?php
/**
 * Servicio operativo para widgets/admin UX de ATORA.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Admin_Operations_Service {

	const MAX_COURSES          = 12;
	const MAX_STUDENTS_PER_RUN = 80;
	const MAX_PENDING_SCAN     = 120;

	/**
	 * Resumen operativo de escritorio.
	 *
	 * @param int $viewer_id Usuario actual.
	 * @return array<string,mixed>
	 */
	public function get_operational_snapshot( $viewer_id = 0 ) {
		$viewer_id = absint( $viewer_id );
		if ( ! $viewer_id ) {
			$viewer_id = get_current_user_id();
		}

		$report_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Report_Service') : null;
		$teacher_report = ( $report_service && method_exists( $report_service, 'get_teacher_report' ) )
			? (array) $report_service->get_teacher_report( $viewer_id, 0 )
			: array();
		$teacher_profile = ( $report_service && method_exists( $report_service, 'get_teacher_profile_report' ) )
			? (array) $report_service->get_teacher_profile_report( $viewer_id )
			: array();
		$cert_report = ( $report_service && method_exists( $report_service, 'get_certification_report' ) )
			? (array) $report_service->get_certification_report( 0 )
			: array();

		$course_ids = $this->get_accessible_course_ids( $viewer_id, $teacher_report );
		$ai_pending = $this->count_ai_pending_reviews( $course_ids );
		$warnings   = absint( $teacher_report['students_at_risk'] ?? 0 );
		if ( absint( $cert_report['courses_incomplete_count'] ?? 0 ) > 0 ) {
			$warnings++;
		}

		$students_active = absint( $teacher_profile['students_active'] ?? 0 );
		if ( 0 === $students_active && current_user_can( 'manage_options' ) ) {
			$students_active = absint( $this->safe_report_value( $report_service, 'get_admin_report', 'active_students' ) );
		}

		$commerce = $this->get_commerce_snapshot( $report_service );
		$cohort_snapshot = array();
		$cohort_service  = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Cohort_Service') : null;
		if ( ! $cohort_service && class_exists( 'CLMS_Cohort_Service' ) ) {
			$cohort_service = new CLMS_Cohort_Service();
		}
		if ( $cohort_service && method_exists( $cohort_service, 'get_operational_snapshot' ) ) {
			$cohort_snapshot = (array) $cohort_service->get_operational_snapshot( $viewer_id );
		}
		if ( ! empty( $cohort_snapshot['alerts'] ) ) {
			$warnings += absint( $cohort_snapshot['alerts'] );
		}

		return array(
			'students_active'             => $students_active,
			'students_at_risk'            => absint( $teacher_report['students_at_risk'] ?? 0 ),
			'pending_submissions'         => absint( $teacher_report['pending_reviews'] ?? 0 ),
			'ai_pending_reviews'          => $ai_pending,
			'certificates_issued'         => absint( $cert_report['issued'] ?? 0 ),
			'certificates_pending'        => absint( $cert_report['eligible_without_issue'] ?? 0 ),
			'courses_ready_certificate'   => absint( $cert_report['courses_ready_count'] ?? 0 ),
			'courses_incomplete'          => absint( $cert_report['courses_incomplete_count'] ?? 0 ),
			'woo_active'                  => class_exists( 'WooCommerce' ),
			'recent_enrollments'          => absint( $commerce['recent_enrollments'] ?? 0 ),
			'activations_pending'         => absint( $commerce['activations_pending'] ?? 0 ),
			'cohorts_active'             => absint( $cohort_snapshot['activo'] ?? 0 ),
			'cohorts_closing'            => absint( $cohort_snapshot['en_cierre'] ?? 0 ),
			'cohorts_finished'           => absint( $cohort_snapshot['finalizado'] ?? 0 ),
			'cohorts_total'              => absint( $cohort_snapshot['total'] ?? 0 ),
			'configuration_alerts'        => absint( $warnings ),
			'accessible_course_ids'       => $course_ids,
		);
	}

	/**
	 * Tarjetas de acciones operativas.
	 *
	 * @param int $viewer_id Usuario.
	 * @param int $limit     Límite.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_action_cards( $viewer_id = 0, $limit = 8 ) {
		$viewer_id = absint( $viewer_id );
		if ( ! $viewer_id ) {
			$viewer_id = get_current_user_id();
		}
		$limit    = max( 1, absint( $limit ) );
		$snapshot = $this->get_operational_snapshot( $viewer_id );

		$cards = array(
			array(
				'type'        => 'academic',
				'priority'    => $snapshot['pending_submissions'] > 0 ? 'high' : 'medium',
				'title'       => __( 'Revisión académica pendiente', 'atora-lms' ),
				'description' => __( 'Atiende la cola de entregas y cierra feedback en SpeedGrade.', 'atora-lms' ),
				'status'      => $snapshot['pending_submissions'] > 0
					? sprintf( _n( '%d entrega pendiente', '%d entregas pendientes', $snapshot['pending_submissions'], 'atora-lms' ), $snapshot['pending_submissions'] )
					: __( 'No hay entregas pendientes por revisar.', 'atora-lms' ),
				'cta_label'   => __( 'Revisar en SpeedGrade', 'atora-lms' ),
				'cta_url'     => admin_url( 'admin.php?page=clms-speedgrader' ),
				'icon'        => 'dashicons-yes-alt',
			),
			array(
				'type'        => 'students',
				'priority'    => $snapshot['students_at_risk'] > 0 ? 'critical' : 'medium',
				'title'       => __( 'Estudiantes que requieren atención', 'atora-lms' ),
				'description' => __( 'Prioriza estudiantes con riesgo académico o baja actividad.', 'atora-lms' ),
				'status'      => $snapshot['students_at_risk'] > 0
					? sprintf( _n( '%d estudiante en riesgo', '%d estudiantes en riesgo', $snapshot['students_at_risk'], 'atora-lms' ), $snapshot['students_at_risk'] )
					: __( 'No hay estudiantes en riesgo en este momento.', 'atora-lms' ),
				'cta_label'   => __( 'Ver reporte académico', 'atora-lms' ),
				'cta_url'     => admin_url( 'admin.php?page=clms-academic-reports' ),
				'icon'        => 'dashicons-groups',
			),
			array(
				'type'        => 'ai',
				'priority'    => $snapshot['ai_pending_reviews'] > 0 ? 'high' : 'low',
				'title'       => __( 'Validaciones IA', 'atora-lms' ),
				'description' => __( 'Revisa sugerencias IA pendientes antes de publicar notas.', 'atora-lms' ),
				'status'      => $snapshot['ai_pending_reviews'] > 0
					? sprintf( _n( '%d revisión IA pendiente', '%d revisiones IA pendientes', $snapshot['ai_pending_reviews'], 'atora-lms' ), $snapshot['ai_pending_reviews'] )
					: __( 'No hay revisiones IA pendientes.', 'atora-lms' ),
				'cta_label'   => __( 'Abrir cola IA', 'atora-lms' ),
				'cta_url'     => admin_url( 'admin.php?page=clms-speedgrader' ),
				'icon'        => 'dashicons-superhero',
			),
			array(
				'type'        => 'certification',
				'priority'    => $snapshot['courses_incomplete'] > 0 ? 'high' : 'medium',
				'title'       => __( 'Estado de certificación', 'atora-lms' ),
				'description' => __( 'Asegura cursos listos para certificar y pendientes por emitir.', 'atora-lms' ),
				'status'      => sprintf(
					/* translators: 1: ready courses 2: incomplete courses */
					__( '%1$d cursos listos · %2$d con ajustes pendientes', 'atora-lms' ),
					absint( $snapshot['courses_ready_certificate'] ),
					absint( $snapshot['courses_incomplete'] )
				),
				'cta_label'   => __( 'Ver certificación', 'atora-lms' ),
				'cta_url'     => admin_url( 'admin.php?page=clms-academic-reports' ),
				'icon'        => 'dashicons-awards',
			),
			array(
				'type'        => 'commerce',
				'priority'    => ! empty( $snapshot['woo_active'] ) ? 'medium' : 'low',
					'title'       => __( 'Operación comercial y matrículas', 'atora-lms' ),
				'description' => __( 'Supervisa activaciones postcompra y matrículas recientes.', 'atora-lms' ),
				'status'      => ! empty( $snapshot['woo_active'] )
					? sprintf(
						/* translators: 1: recent enrollments 2: pending activations */
						__( '%1$d activaciones recientes · %2$d pendientes', 'atora-lms' ),
						absint( $snapshot['recent_enrollments'] ),
						absint( $snapshot['activations_pending'] )
					)
					: __( 'WooCommerce no está activo.', 'atora-lms' ),
					'cta_label'   => __( 'Ir al Hub comercial', 'atora-lms' ),
					'cta_url'     => admin_url( 'admin.php?page=clms-commercial-hub' ),
				'icon'        => 'dashicons-cart',
			),
			array(
				'type'        => 'academic',
				'priority'    => ( $snapshot['cohorts_closing'] > 0 || $snapshot['cohorts_active'] > 0 ) ? 'medium' : 'low',
				'title'       => __( 'Cohortes y generaciones', 'atora-lms' ),
				'description' => __( 'Haz seguimiento de cohortes activas, en cierre y finalizadas.', 'atora-lms' ),
				'status'      => sprintf(
					/* translators: 1: active cohorts 2: closing cohorts 3: finished cohorts */
					__( '%1$d activas · %2$d en cierre · %3$d finalizadas', 'atora-lms' ),
					absint( $snapshot['cohorts_active'] ),
					absint( $snapshot['cohorts_closing'] ),
					absint( $snapshot['cohorts_finished'] )
				),
				'cta_label'   => __( 'Ver cohortes', 'atora-lms' ),
				'cta_url'     => admin_url( 'edit.php?post_type=lm_cohort' ),
				'icon'        => 'dashicons-groups',
			),
			array(
				'type'        => 'system',
				'priority'    => $snapshot['configuration_alerts'] > 0 ? 'high' : 'low',
				'title'       => __( 'Alertas de configuración', 'atora-lms' ),
				'description' => __( 'Confirma ajustes académicos para evitar bloqueos operativos.', 'atora-lms' ),
				'status'      => $snapshot['configuration_alerts'] > 0
					? sprintf( _n( '%d alerta abierta', '%d alertas abiertas', $snapshot['configuration_alerts'], 'atora-lms' ), $snapshot['configuration_alerts'] )
					: __( 'Sin alertas críticas de configuración.', 'atora-lms' ),
				'cta_label'   => __( 'Revisar ajustes', 'atora-lms' ),
				'cta_url'     => admin_url( 'admin.php?page=clms-settings' ),
				'icon'        => 'dashicons-admin-tools',
			),
		);

		$cards = array_values(
			array_filter(
				$cards,
				static function( $card ) {
					return ! empty( $card['cta_url'] ) && ! empty( $card['cta_label'] );
				}
			)
		);

		usort(
			$cards,
			array( $this, 'sort_action_card_priority' )
		);

		return array_slice( $cards, 0, $limit );
	}

	/**
	 * Filas para visibilidad de estudiantes.
	 *
	 * @param int $viewer_id Usuario.
	 * @param int $limit     Límite.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_students_visibility_rows( $viewer_id = 0, $limit = 10, $filters = array() ) {
		$viewer_id = absint( $viewer_id );
		if ( ! $viewer_id ) {
			$viewer_id = get_current_user_id();
		}
		$limit = max( 1, absint( $limit ) );
		$filters = $this->normalize_students_filters( $filters );

		$report_service  = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Report_Service') : null;
		$teacher_report  = ( $report_service && method_exists( $report_service, 'get_teacher_report' ) )
			? (array) $report_service->get_teacher_report( $viewer_id, 0 )
			: array();
		$status_service  = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Status_Service') : null;
		$grading         = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading') : null;

		$course_ids      = $this->get_accessible_course_ids( $viewer_id, $teacher_report );
		$cohort_service  = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Cohort_Service') : null;
		if ( ! $cohort_service && class_exists( 'CLMS_Cohort_Service' ) ) {
			$cohort_service = new CLMS_Cohort_Service();
		}
		$cohort_students = array();

		if ( $filters['course_id'] > 0 ) {
			$course_ids = array_values( array_intersect( $course_ids, array( $filters['course_id'] ) ) );
		}

		if ( $filters['program_id'] > 0 && class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_program_courses' ) ) {
			$program_courses = array_values( array_filter( array_map( 'absint', (array) CLMS_Helper::get_program_courses( $filters['program_id'] ) ) ) );
			$course_ids = array_values( array_intersect( $course_ids, $program_courses ) );
		}

		if ( $filters['cohort_id'] > 0 && $cohort_service ) {
			$cohort_courses = method_exists( $cohort_service, 'get_cohort_course_ids' ) ? (array) $cohort_service->get_cohort_course_ids( $filters['cohort_id'] ) : array();
			$course_ids = array_values( array_intersect( $course_ids, array_values( array_filter( array_map( 'absint', $cohort_courses ) ) ) ) );
			$cohort_students = method_exists( $cohort_service, 'get_cohort_student_ids' ) ? array_values( array_filter( array_map( 'absint', (array) $cohort_service->get_cohort_student_ids( $filters['cohort_id'] ) ) ) ) : array();
		}

		$pending_map     = $this->get_pending_submissions_map( $course_ids );
		$rows            = array();
		$seen            = array();
		$certificates    = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Certificates') : null;

		foreach ( $course_ids as $course_id ) {
			$course_id = absint( $course_id );
			if ( ! $course_id ) {
				continue;
			}

			$student_ids = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_enrolled_student_ids( $course_id ) : array();
			$student_ids = is_array( $student_ids ) ? array_slice( array_values( array_unique( array_map( 'absint', $student_ids ) ) ), 0, self::MAX_STUDENTS_PER_RUN ) : array();

			foreach ( $student_ids as $student_id ) {
				$student_id = absint( $student_id );
				if ( ! $student_id ) {
					continue;
				}
				if ( ! empty( $cohort_students ) && ! in_array( $student_id, $cohort_students, true ) ) {
					continue;
				}

				$key = $student_id . ':' . $course_id;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;

				$status = ( $status_service && method_exists( $status_service, 'get_student_course_status' ) )
					? (array) $status_service->get_student_course_status( $student_id, $course_id )
					: array();
				$user   = get_userdata( $student_id );
				$name   = $user ? ( $user->display_name ?: $user->user_login ) : '#' . $student_id;
				$risk   = sanitize_key( (string) ( $status['risk_level'] ?? 'unknown' ) );
				$risk_l = $this->get_risk_label( $risk );
				$certificate_status = sanitize_key( (string) ( $status['certificate_status'] ?? 'pending' ) );
				$certificate_label  = $this->get_certificate_status_label( $certificate_status );
				$certificate_view_url = '';
				$certificate_verify_url = '';
				$certificate_linkedin_url = '';
				$certificate_whatsapp_url = '';
				if ( $certificates && method_exists( $certificates, 'get_certificate_status_for_student_course' ) ) {
					$certificate_detail = (array) $certificates->get_certificate_status_for_student_course( $student_id, $course_id );
					$certificate_status = sanitize_key( (string) ( $certificate_detail['status'] ?? $certificate_status ) );
					$certificate_label  = $this->get_certificate_status_label( $certificate_status );
					$certificate_view_url = isset( $certificate_detail['view_url'] ) ? esc_url_raw( (string) $certificate_detail['view_url'] ) : '';
					$certificate_verify_url = isset( $certificate_detail['verification_url'] ) ? esc_url_raw( (string) $certificate_detail['verification_url'] ) : '';
					$share_actions = isset( $certificate_detail['share_actions'] ) && is_array( $certificate_detail['share_actions'] ) ? $certificate_detail['share_actions'] : array();
					$certificate_linkedin_url = ! empty( $share_actions['linkedin'] ) ? esc_url_raw( (string) $share_actions['linkedin'] ) : '';
					$certificate_whatsapp_url = ! empty( $share_actions['whatsapp'] ) ? esc_url_raw( (string) $share_actions['whatsapp'] ) : '';
				}

				if ( '' !== $filters['certificate_status'] ) {
					$requested = $filters['certificate_status'];
					$matches = ( $requested === $certificate_status );
					if ( 'issued' === $requested ) {
						$matches = in_array( $certificate_status, array( 'issued', 'valid' ), true );
					}
					if ( ! $matches ) {
						continue;
					}
				}

				$pending_key = $course_id . ':' . $student_id;
				$pending_row = isset( $pending_map[ $pending_key ] ) && is_array( $pending_map[ $pending_key ] ) ? $pending_map[ $pending_key ] : array( 'count' => 0, 'submission_id' => 0 );
				$submission_id = absint( $pending_row['submission_id'] ?? 0 );
				$speedgrade_url = '';
				if ( $submission_id && $grading && method_exists( $grading, 'get_speedgrade_url' ) ) {
					$speedgrade_url = $grading->get_speedgrade_url( $submission_id, admin_url( 'admin.php?page=clms-speedgrader' ) );
				}

				$memory_last_seen = '';
				if ( class_exists( 'CLMS_Student_Memory' ) && method_exists( 'CLMS_Student_Memory', 'get_memory_insights' ) ) {
					$memory = (array) CLMS_Student_Memory::get_memory_insights( $student_id, $course_id );
					$memory_last_seen = isset( $memory['last_seen'] ) ? sanitize_text_field( (string) $memory['last_seen'] ) : '';
				}

				$rows[] = array(
					'student_id'            => $student_id,
					'student_name'          => sanitize_text_field( (string) $name ),
					'course_id'             => $course_id,
					'course_title'          => sanitize_text_field( (string) get_the_title( $course_id ) ),
					'progress_percent'      => absint( $status['progress_percent'] ?? 0 ),
					'last_activity'         => '' !== $memory_last_seen ? $this->format_admin_datetime( $memory_last_seen ) : __( 'Sin actividad reciente', 'atora-lms' ),
					'academic_status'       => $this->get_academic_status_label( $risk ),
					'risk_level'            => $risk,
					'risk_label'            => $risk_l,
					'pending_submissions'   => absint( $pending_row['count'] ?? 0 ),
					'certificate_status'    => $certificate_status,
					'certificate_label'     => $certificate_label,
					'certificate_view_url'  => $certificate_view_url,
					'certificate_verify_url'=> $certificate_verify_url,
					'certificate_linkedin_url' => $certificate_linkedin_url,
					'certificate_whatsapp_url' => $certificate_whatsapp_url,
					'profile_url'           => add_query_arg(
						array(
							'page'       => 'clms-academic-reports',
							'course_id'  => $course_id,
							'student_id' => $student_id,
						),
						admin_url( 'admin.php' )
					),
					'course_url'            => get_edit_post_link( $course_id, '' ),
					'speedgrade_url'        => $speedgrade_url,
					'submissions_url'       => add_query_arg(
						array(
							'page' => 'clms-speedgrader',
						),
						admin_url( 'admin.php' )
					),
					'improvement_plan_url'  => add_query_arg(
						array(
							'page'       => 'clms-academic-reports',
							'student_id' => $student_id,
							'course_id'  => $course_id,
						),
						admin_url( 'admin.php' )
					),
				);
			}
		}

		usort(
			$rows,
			static function( $a, $b ) {
				$risk_order = array(
					'high'    => 1,
					'medium'  => 2,
					'low'     => 3,
					'normal'  => 4,
					'unknown' => 5,
				);
				$a_risk = isset( $risk_order[ $a['risk_level'] ?? 'unknown' ] ) ? $risk_order[ $a['risk_level'] ] : 5;
				$b_risk = isset( $risk_order[ $b['risk_level'] ?? 'unknown' ] ) ? $risk_order[ $b['risk_level'] ] : 5;
				if ( $a_risk !== $b_risk ) {
					return $a_risk - $b_risk;
				}
				$a_pending = absint( $a['pending_submissions'] ?? 0 );
				$b_pending = absint( $b['pending_submissions'] ?? 0 );
				if ( $a_pending !== $b_pending ) {
					return $b_pending - $a_pending;
				}
				return strcasecmp( (string) ( $a['student_name'] ?? '' ), (string) ( $b['student_name'] ?? '' ) );
			}
		);

		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Opciones de filtros para panel operativo de estudiantes.
	 *
	 * @param int $viewer_id Usuario.
	 * @return array<string,mixed>
	 */
	public function get_students_filter_options( $viewer_id = 0 ) {
		$viewer_id = absint( $viewer_id );
		if ( ! $viewer_id ) {
			$viewer_id = get_current_user_id();
		}

		$report_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Report_Service') : null;
		$teacher_report = ( $report_service && method_exists( $report_service, 'get_teacher_report' ) )
			? (array) $report_service->get_teacher_report( $viewer_id, 0 )
			: array();
		$course_ids = $this->get_accessible_course_ids( $viewer_id, $teacher_report );

		$courses = array();
		foreach ( $course_ids as $course_id ) {
			$title = get_the_title( $course_id );
			if ( $title ) {
				$courses[ absint( $course_id ) ] = sanitize_text_field( (string) $title );
			}
		}
		asort( $courses );

		$programs = array();
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_program_ids' ) ) {
			foreach ( array_keys( $courses ) as $course_id ) {
				foreach ( (array) CLMS_Helper::get_course_program_ids( $course_id ) as $program_id ) {
					$program_id = absint( $program_id );
					if ( ! $program_id ) {
						continue;
					}
					$program_title = get_the_title( $program_id );
					if ( $program_title ) {
						$programs[ $program_id ] = sanitize_text_field( (string) $program_title );
					}
				}
			}
			asort( $programs );
		}

		$cohorts = array();
		$cohort_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Cohort_Service') : null;
		if ( ! $cohort_service && class_exists( 'CLMS_Cohort_Service' ) ) {
			$cohort_service = new CLMS_Cohort_Service();
		}
		if ( $cohort_service && method_exists( $cohort_service, 'get_cohort_options_for_user' ) ) {
			$cohorts = (array) $cohort_service->get_cohort_options_for_user( $viewer_id );
		}

		return array(
			'courses'             => $courses,
			'programs'            => $programs,
			'cohorts'             => $cohorts,
			'certificate_statuses'=> array(
				''         => __( 'Todos los estados de certificado', 'atora-lms' ),
				'pending'  => __( 'En progreso', 'atora-lms' ),
				'eligible' => __( 'Elegible', 'atora-lms' ),
				'issued'   => __( 'Emitido', 'atora-lms' ),
				'revoked'  => __( 'Revocado', 'atora-lms' ),
			),
		);
	}

	/**
	 * Normaliza filtros de estudiantes.
	 *
	 * @param array<string,mixed> $filters Filtros crudos.
	 * @return array<string,mixed>
	 */
	protected function normalize_students_filters( $filters ) {
		$filters = is_array( $filters ) ? $filters : array();
		$certificate_status = sanitize_key( (string) ( $filters['certificate_status'] ?? '' ) );
		if ( ! in_array( $certificate_status, array( '', 'pending', 'eligible', 'issued', 'valid', 'revoked' ), true ) ) {
			$certificate_status = '';
		}

		return array(
			'course_id'          => absint( $filters['course_id'] ?? 0 ),
			'program_id'         => absint( $filters['program_id'] ?? 0 ),
			'cohort_id'          => absint( $filters['cohort_id'] ?? 0 ),
			'certificate_status' => $certificate_status,
		);
	}

	/**
	 * Cursos accesibles para el usuario.
	 *
	 * @param int   $viewer_id      Usuario.
	 * @param array $teacher_report Reporte docente.
	 * @return array<int,int>
	 */
	protected function get_accessible_course_ids( $viewer_id, $teacher_report ) {
		$viewer_id = absint( $viewer_id );
		$teacher_report = is_array( $teacher_report ) ? $teacher_report : array();

		$course_ids = isset( $teacher_report['course_ids'] ) && is_array( $teacher_report['course_ids'] )
			? array_map( 'absint', $teacher_report['course_ids'] )
			: array();

		if ( current_user_can( 'manage_options' ) || current_user_can( 'clms_access_admin' ) ) {
			$all_courses = get_posts(
				array(
					'post_type'      => 'lm_course',
					'post_status'    => array( 'publish', 'private' ),
					'fields'         => 'ids',
					'posts_per_page' => self::MAX_COURSES,
					'no_found_rows'  => true,
				)
			);
			if ( is_array( $all_courses ) && ! empty( $all_courses ) ) {
				$course_ids = array_merge( $course_ids, array_map( 'absint', $all_courses ) );
			}
		}

		if ( empty( $course_ids ) && class_exists( 'CLMS_Helper' ) ) {
			$enrolled = ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $viewer_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $viewer_id ) );
			if ( is_array( $enrolled ) ) {
				$course_ids = array_merge( $course_ids, array_map( 'absint', $enrolled ) );
			}
		}

		$course_ids = array_values( array_unique( array_filter( $course_ids ) ) );

		return array_slice( $course_ids, 0, self::MAX_COURSES );
	}

	/**
	 * Cuenta revisiones IA pendientes por cursos.
	 *
	 * @param array<int,int> $course_ids Cursos.
	 * @return int
	 */
	protected function count_ai_pending_reviews( $course_ids ) {
		$course_ids = is_array( $course_ids ) ? array_values( array_filter( array_map( 'absint', $course_ids ) ) ) : array();
		if ( empty( $course_ids ) ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => self::MAX_PENDING_SCAN,
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_clms_submission_course_id',
						'value'   => $course_ids,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => '_clms_ai_review_status',
						'value'   => array( 'completed', 'pending' ),
						'compare' => 'IN',
					),
					array(
						'key'     => '_clms_submission_status',
						'value'   => 'graded',
						'compare' => '!=',
					),
				),
			)
		);

		return absint( is_array( $query->posts ) ? count( $query->posts ) : 0 );
	}

	/**
	 * Mapa de pendientes por estudiante y curso.
	 *
	 * @param array<int,int> $course_ids Cursos.
	 * @return array<string,array<string,int>>
	 */
	protected function get_pending_submissions_map( $course_ids ) {
		$course_ids = is_array( $course_ids ) ? array_values( array_filter( array_map( 'absint', $course_ids ) ) ) : array();
		if ( empty( $course_ids ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'              => 'clms_submission',
				'post_status'            => 'publish',
				'posts_per_page'         => self::MAX_PENDING_SCAN,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'     => '_clms_submission_status',
						'value'   => array( 'submitted', 'in_review', 'needs_revision' ),
						'compare' => 'IN',
					),
					array(
						'key'     => '_clms_submission_course_id',
						'value'   => $course_ids,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$map = array();
		foreach ( $posts as $submission_id ) {
			$submission_id = absint( $submission_id );
			$course_id     = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
			$student_id    = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
			if ( ! $course_id || ! $student_id ) {
				continue;
			}
			$key = $course_id . ':' . $student_id;
			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = array(
					'count'         => 0,
					'submission_id' => 0,
				);
			}
			$map[ $key ]['count']++;
			if ( 0 === absint( $map[ $key ]['submission_id'] ) ) {
				$map[ $key ]['submission_id'] = $submission_id;
			}
		}

		return $map;
	}

	/**
	 * Snapshot de comercio.
	 *
	 * @param object|null $report_service Servicio reportes.
	 * @return array<string,int>
	 */
	protected function get_commerce_snapshot( $report_service ) {
		$data = array(
			'recent_enrollments'   => 0,
			'activations_pending'  => 0,
		);

		if ( ! class_exists( 'WooCommerce' ) ) {
			return $data;
		}

		if ( $report_service && method_exists( $report_service, 'get_admin_report' ) ) {
			$admin_report = (array) $report_service->get_admin_report();
			$commerce     = isset( $admin_report['commerce'] ) && is_array( $admin_report['commerce'] ) ? $admin_report['commerce'] : array();
			$data['activations_pending'] = absint( $commerce['activations_pending'] ?? 0 );
		}

		$orders = function_exists( 'wc_get_orders' )
			? wc_get_orders(
				array(
					'limit'  => 20,
					'status' => array( 'wc-processing', 'wc-completed' ),
					'return' => 'ids',
				)
			)
			: array();
		$data['recent_enrollments'] = is_array( $orders ) ? count( $orders ) : 0;

		return $data;
	}

	/**
	 * Valor seguro desde método de reporte.
	 *
	 * @param object|null $service Servicio.
	 * @param string      $method  Método.
	 * @param string      $key     Clave.
	 * @return mixed
	 */
	protected function safe_report_value( $service, $method, $key ) {
		if ( ! $service || ! method_exists( $service, $method ) ) {
			return 0;
		}
		$data = (array) $service->$method();
		return $data[ $key ] ?? 0;
	}

	/**
	 * Ordena por prioridad.
	 *
	 * @param array<string,mixed> $a Item A.
	 * @param array<string,mixed> $b Item B.
	 * @return int
	 */
	protected function sort_action_card_priority( $a, $b ) {
		$order = array(
			'critical' => 1,
			'high'     => 2,
			'medium'   => 3,
			'low'      => 4,
		);
		$a_priority = sanitize_key( (string) ( $a['priority'] ?? 'low' ) );
		$b_priority = sanitize_key( (string) ( $b['priority'] ?? 'low' ) );
		$a_weight   = isset( $order[ $a_priority ] ) ? $order[ $a_priority ] : 5;
		$b_weight   = isset( $order[ $b_priority ] ) ? $order[ $b_priority ] : 5;
		if ( $a_weight === $b_weight ) {
			return 0;
		}
		return ( $a_weight < $b_weight ) ? -1 : 1;
	}

	/**
	 * Label de riesgo para UI.
	 *
	 * @param string $risk Nivel técnico.
	 * @return string
	 */
	protected function get_risk_label( $risk ) {
		$risk = sanitize_key( (string) $risk );
		$map = array(
			'high'    => __( 'En riesgo', 'atora-lms' ),
			'medium'  => __( 'Atención', 'atora-lms' ),
			'low'     => __( 'Bajo', 'atora-lms' ),
			'normal'  => __( 'Al día', 'atora-lms' ),
			'unknown' => __( 'Sin datos', 'atora-lms' ),
		);
		return isset( $map[ $risk ] ) ? $map[ $risk ] : $map['unknown'];
	}

	/**
	 * Estado académico legible.
	 *
	 * @param string $risk Nivel técnico.
	 * @return string
	 */
	protected function get_academic_status_label( $risk ) {
		$risk = sanitize_key( (string) $risk );
		if ( 'high' === $risk ) {
			return __( 'Requiere intervención', 'atora-lms' );
		}
		if ( 'medium' === $risk ) {
			return __( 'Seguimiento activo', 'atora-lms' );
		}
		if ( 'low' === $risk || 'normal' === $risk ) {
			return __( 'Evolución estable', 'atora-lms' );
		}
		return __( 'Sin señal suficiente', 'atora-lms' );
	}

	/**
	 * Estado certificado legible.
	 *
	 * @param string $status Estado técnico.
	 * @return string
	 */
	protected function get_certificate_status_label( $status ) {
		$status = sanitize_key( (string) $status );
		$labels = array(
			'valid'    => __( 'Emitido', 'atora-lms' ),
			'issued'   => __( 'Emitido', 'atora-lms' ),
			'eligible' => __( 'Elegible', 'atora-lms' ),
			'pending'  => __( 'En progreso', 'atora-lms' ),
			'revoked'  => __( 'Revocado', 'atora-lms' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'En progreso', 'atora-lms' );
	}

	/**
	 * Formato fecha admin.
	 *
	 * @param string $datetime Datetime.
	 * @return string
	 */
	protected function format_admin_datetime( $datetime ) {
		$datetime = sanitize_text_field( (string) $datetime );
		if ( '' === $datetime ) {
			return '';
		}
		$timestamp = strtotime( $datetime );
		if ( ! $timestamp ) {
			return $datetime;
		}
		$format = get_option( 'date_format', 'd/m/Y' ) . ' ' . get_option( 'time_format', 'H:i' );
		return wp_date( $format, $timestamp );
	}
}
