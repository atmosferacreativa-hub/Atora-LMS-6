<?php
/**
 * Servicio de enriquecimiento de contexto para SpeedGrade.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Speedgrade_Context_Service {

	/**
	 * Enriquece el contexto base de la entrega.
	 *
	 * @param array<string,mixed> $context Contexto base.
	 * @return array<string,mixed>
	 */
	public function enrich_submission_context( $context ) {
		$context = is_array( $context ) ? $context : array();
		if ( empty( $context ) ) {
			return array();
		}

		$student_id = absint( $context['student_id'] ?? 0 );
		$course_id  = absint( $context['course_id'] ?? 0 );
		$submission_id = absint( $context['submission_id'] ?? 0 );

		$academic = array();
		$status_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Status_Service') : null;
		if ( $status_service && method_exists( $status_service, 'get_student_course_status' ) && $student_id && $course_id ) {
			$academic = (array) $status_service->get_student_course_status( $student_id, $course_id );
		}

		$plan = array();
		$plan_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Improvement_Plan_Service') : null;
		if ( $plan_service && method_exists( $plan_service, 'build_from_submission' ) && $submission_id ) {
			$plan = (array) $plan_service->build_from_submission( $submission_id );
		}
		if ( empty( $plan ) && ! empty( $academic['improvement_plan'] ) && is_array( $academic['improvement_plan'] ) ) {
			$plan = $academic['improvement_plan'];
		}
		if ( $plan_service && method_exists( $plan_service, 'normalize_plan' ) ) {
			$plan = (array) $plan_service->normalize_plan(
				$plan,
				isset( $academic['next_step'] ) ? (string) $academic['next_step'] : ''
			);
		}

		$context['academic_status'] = $academic;
		$context['improvement_plan'] = $plan;
		$context['risk_level'] = isset( $academic['risk_level'] ) ? sanitize_key( (string) $academic['risk_level'] ) : 'normal';
		$context['certificate_status'] = isset( $academic['certificate_status'] ) ? sanitize_key( (string) $academic['certificate_status'] ) : 'pending';
		$context['last_feedback_previous'] = isset( $academic['last_feedback'] ) ? sanitize_textarea_field( (string) $academic['last_feedback'] ) : '';
		$context['last_grade_previous'] = isset( $academic['last_grade'] ) && is_numeric( $academic['last_grade'] ) ? absint( $academic['last_grade'] ) : null;
		$context['pending_activities'] = isset( $academic['pending_activities'] ) ? absint( $academic['pending_activities'] ) : 0;
		$context['missing_certificate_requirements'] = isset( $academic['missing_certificate_requirements'] ) && is_array( $academic['missing_certificate_requirements'] )
			? $academic['missing_certificate_requirements']
			: array();
		$context['competencies'] = isset( $academic['competencies'] ) && is_array( $academic['competencies'] )
			? $academic['competencies']
			: array();
		$context['evidence'] = $this->get_evidence_context( absint( $context['lesson_id'] ?? 0 ) );
		$context['competency_focus'] = $this->get_competency_focus_for_activity(
			$context['competencies'],
			isset( $context['evidence']['competency_ids'] ) ? $context['evidence']['competency_ids'] : array()
		);
		$context['ai_pending_validation'] = in_array( sanitize_key( (string) ( $context['status'] ?? '' ) ), array( 'in_review', 'pending' ), true )
			&& in_array( sanitize_key( (string) ( $context['grade_source'] ?? '' ) ), array( 'ai_assisted', 'ai_auto_grade', 'hybrid' ), true );
		$context['configuration_warnings'] = $this->get_configuration_warnings( $context );

		return $context;
	}

	/**
	 * Contexto de evidencia de la actividad.
	 *
	 * @param int $lesson_id Lección.
	 * @return array<string,mixed>
	 */
	protected function get_evidence_context( $lesson_id ) {
		$lesson_id = absint( $lesson_id );
		$default = array(
			'evidence_type'               => 'practice',
			'is_required_for_certificate' => false,
			'competency_ids'              => array(),
			'minimum_grade'               => 0,
			'allow_resubmission'          => true,
		);

		if ( ! $lesson_id || ! class_exists( 'CLMS_Helper' ) ) {
			return $default;
		}

		$evidence_service = clms_core('CLMS_Evidence_Service');
		if ( ! $evidence_service || ! method_exists( $evidence_service, 'get_activity_evidence_config' ) ) {
			return $default;
		}

		$config = (array) $evidence_service->get_activity_evidence_config( $lesson_id );

		return array(
			'evidence_type'               => sanitize_key( (string) ( $config['evidence_type'] ?? 'practice' ) ),
			'is_required_for_certificate' => ! empty( $config['is_required_for_certificate'] ),
			'competency_ids'              => isset( $config['competency_ids'] ) && is_array( $config['competency_ids'] ) ? array_values( array_map( 'sanitize_key', $config['competency_ids'] ) ) : array(),
			'minimum_grade'               => absint( $config['minimum_grade'] ?? 0 ),
			'allow_resubmission'          => ! empty( $config['allow_resubmission'] ),
		);
	}

	/**
	 * Competencias del estudiante relevantes para la actividad.
	 *
	 * @param array $competencies   Estado por competencia.
	 * @param array $activity_ids   IDs de competencia de la actividad.
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_competency_focus_for_activity( $competencies, $activity_ids ) {
		$competencies = is_array( $competencies ) ? $competencies : array();
		$activity_ids = is_array( $activity_ids ) ? array_values( array_filter( array_map( 'sanitize_key', $activity_ids ) ) ) : array();

		if ( empty( $competencies ) || empty( $activity_ids ) ) {
			return array();
		}

		$list = array();
		foreach ( $competencies as $competency ) {
			$competency = is_array( $competency ) ? $competency : array();
			$comp_id = isset( $competency['competency_id'] ) ? sanitize_key( (string) $competency['competency_id'] ) : '';
			if ( '' === $comp_id || ! in_array( $comp_id, $activity_ids, true ) ) {
				continue;
			}
			$list[] = array(
				'competency_id' => $comp_id,
				'title'         => sanitize_text_field( (string) ( $competency['title'] ?? '' ) ),
				'status'        => sanitize_key( (string) ( $competency['status'] ?? 'sin_evidencia' ) ),
				'score'         => absint( $competency['score'] ?? 0 ),
				'recommendation'=> sanitize_text_field( (string) ( $competency['recommendation'] ?? '' ) ),
			);
		}

		return $list;
	}

	/**
	 * Advertencias de configuración académica relevantes para SpeedGrade.
	 *
	 * @param array<string,mixed> $context Contexto base.
	 * @return array<int,string>
	 */
	protected function get_configuration_warnings( $context ) {
		$context   = is_array( $context ) ? $context : array();
		$course_id = absint( $context['course_id'] ?? 0 );
		$lesson_id = absint( $context['lesson_id'] ?? 0 );
		$rubric_id = absint( $context['rubric_id'] ?? 0 );
		$warnings  = array();

		if ( ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$diagnostics_service = clms_core('CLMS_Academic_Diagnostics_Service');
		if ( $diagnostics_service && method_exists( $diagnostics_service, 'diagnose_course' ) && $course_id ) {
			$diag = (array) $diagnostics_service->diagnose_course( $course_id );
			$diag_warnings = isset( $diag['warnings'] ) && is_array( $diag['warnings'] ) ? $diag['warnings'] : array();
			$warnings = array_merge( $warnings, array_map( 'sanitize_text_field', $diag_warnings ) );
		}

		$evidence = isset( $context['evidence'] ) && is_array( $context['evidence'] ) ? $context['evidence'] : array();
		$is_required = ! empty( $evidence['is_required_for_certificate'] );
		$competency_ids = isset( $evidence['competency_ids'] ) && is_array( $evidence['competency_ids'] )
			? array_values( array_filter( array_map( 'sanitize_key', $evidence['competency_ids'] ) ) )
			: array();

		if ( $is_required && empty( $competency_ids ) ) {
			$warnings[] = __( 'Esta evidencia obligatoria no tiene competencias asociadas.', 'atora-lms' );
		}
		if ( $is_required && ! $rubric_id ) {
			$warnings[] = __( 'Esta evidencia obligatoria no tiene rúbrica asociada.', 'atora-lms' );
		}

		if ( $rubric_id && class_exists( 'CLMS_Rubric' ) && method_exists( 'CLMS_Rubric', 'get_criteria' ) ) {
			$criteria = (array) CLMS_Rubric::get_criteria( $rubric_id );
			if ( ! empty( $criteria ) && $course_id ) {
				$comp_service = clms_core('CLMS_Competency_Service');
				$course_competencies = ( $comp_service && method_exists( $comp_service, 'get_course_competencies' ) )
					? (array) $comp_service->get_course_competencies( $course_id )
					: array();
				$course_comp_ids = array();
				foreach ( $course_competencies as $course_competency ) {
					$course_competency = is_array( $course_competency ) ? $course_competency : array();
					$course_comp_ids[] = sanitize_key( (string) ( $course_competency['id'] ?? '' ) );
				}
				$course_comp_ids = array_values( array_filter( array_unique( $course_comp_ids ) ) );

				foreach ( $criteria as $criterion ) {
					$criterion = is_array( $criterion ) ? $criterion : array();
					$legacy = sanitize_text_field( (string) ( $criterion['competency'] ?? '' ) );
					$comp_id = sanitize_key( (string) ( $criterion['competency_id'] ?? '' ) );
					if ( $comp_service && method_exists( $comp_service, 'resolve_competency_id' ) ) {
						$comp_id = sanitize_key( (string) $comp_service->resolve_competency_id( $course_id, $comp_id, $legacy ) );
					}
					if ( '' === $comp_id ) {
						$comp_id = sanitize_key( sanitize_title( $legacy ) );
					}
					if ( '' !== $comp_id && ! in_array( $comp_id, $course_comp_ids, true ) ) {
						$warnings[] = sprintf(
							/* translators: %s: competencia */
							__( 'La rúbrica usa una competencia no válida para este curso: %s.', 'atora-lms' ),
							$comp_id
						);
						break;
					}
				}
			}
		}

		if ( $lesson_id && $is_required ) {
			$warnings[] = __( 'Esta entrega impacta directamente la elegibilidad del certificado.', 'atora-lms' );
		}

		$warnings = array_values( array_filter( array_unique( array_map( 'sanitize_text_field', $warnings ) ) ) );
		return array_slice( $warnings, 0, 6 );
	}
}
