<?php
/**
 * Reglas de elegibilidad y configuración para certificados.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Certificate_Rules {

	const OPT_ENABLED                 = 'clms_certificates_enabled';
	const OPT_MIN_PROGRESS            = 'clms_certificate_min_progress';
	const OPT_MIN_AVERAGE             = 'clms_certificate_passing_grade';
	const OPT_ALLOW_PUBLIC_VERIFY     = 'clms_certificate_public_verify_enabled';
	const OPT_ALLOW_REISSUE           = 'clms_certificate_allow_reissue';
	const OPT_ALLOW_PROGRAM_CERTS     = 'clms_certificate_enable_programs';
	const OPT_REQUIRE_COURSE_ACTIVE   = 'clms_certificate_require_course_active';

	/**
	 * Evalúa elegibilidad de certificado para curso.
	 *
	 * @param int   $user_id   Estudiante.
	 * @param int   $course_id Curso.
	 * @param array $summary   Resumen académico del curso.
	 * @return array<string,mixed>
	 */
	public function evaluate_course( $user_id, $course_id, $summary = array() ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$summary   = is_array( $summary ) ? $summary : array();

		$progress_percent = isset( $summary['progress_percent'] ) ? absint( $summary['progress_percent'] ) : 0;
		$final_average    = isset( $summary['final_average'] ) ? absint( $summary['final_average'] ) : 0;
		$total_lessons    = isset( $summary['total_lessons'] ) ? absint( $summary['total_lessons'] ) : 0;
		$completed        = isset( $summary['completed_lessons'] ) ? absint( $summary['completed_lessons'] ) : 0;

		$min_progress = $this->get_course_min_progress( $course_id );
		$min_average  = $this->get_course_min_average( $course_id );

		$missing = array();
		$configuration_warnings = array();
		$required_evidences = array();
		$completed_evidences = array();
		$required_competencies = array();
		$completed_competencies = array();
		$course_ready_for_certificate = true;

		if ( ! $this->is_enabled_for_course( $course_id ) ) {
			$missing[] = __( 'La emisión de certificados está desactivada.', 'atora-lms' );
		}

		if ( ! $user_id || ! $course_id ) {
			$missing[] = __( 'Contexto académico incompleto para certificar.', 'atora-lms' );
		}

		if ( $this->require_course_active() && 'publish' !== get_post_status( $course_id ) ) {
			$missing[] = __( 'El curso no está activo para emisión de certificados.', 'atora-lms' );
		}

		if ( $total_lessons <= 0 ) {
			$missing[] = __( 'El curso no tiene lecciones configuradas.', 'atora-lms' );
		}

		if ( $completed < $total_lessons ) {
			$missing[] = __( 'Debes completar todas las lecciones del curso.', 'atora-lms' );
		}

		if ( $progress_percent < $min_progress ) {
			$missing[] = sprintf(
				/* translators: %d: porcentaje mínimo */
				__( 'Debes alcanzar al menos %d%% de progreso.', 'atora-lms' ),
				$min_progress
			);
		}

		if ( $final_average < $min_average ) {
			$missing[] = sprintf(
				/* translators: %d: promedio mínimo */
				__( 'Debes alcanzar un promedio mínimo de %d%%.', 'atora-lms' ),
				$min_average
			);
		}

		$evidence_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
		if ( $evidence_service && method_exists( $evidence_service, 'get_course_required_evidences' ) && method_exists( $evidence_service, 'get_student_evidence_status' ) ) {
			$required_rows = (array) $evidence_service->get_course_required_evidences( $course_id );
			$student_rows  = (array) $evidence_service->get_student_evidence_status( $user_id, $course_id );

			foreach ( $required_rows as $required_row ) {
				$required_row = is_array( $required_row ) ? $required_row : array();
				$activity_id  = absint( $required_row['activity_id'] ?? 0 );
				if ( ! $activity_id ) {
					continue;
				}
				$required_evidences[] = $activity_id;
			}

			$required_evidences = array_values( array_unique( array_filter( $required_evidences ) ) );

			foreach ( $student_rows as $student_row ) {
				$student_row = is_array( $student_row ) ? $student_row : array();
				$activity_id = absint( $student_row['activity_id'] ?? 0 );
				if ( ! $activity_id || ! in_array( $activity_id, $required_evidences, true ) ) {
					continue;
				}
				if ( ! empty( $student_row['approved'] ) ) {
					$completed_evidences[] = $activity_id;
				}
			}

			$completed_evidences = array_values( array_unique( array_filter( $completed_evidences ) ) );

			if ( count( $completed_evidences ) < count( $required_evidences ) ) {
				$missing[] = __( 'Aún tienes evidencias obligatorias sin aprobar.', 'atora-lms' );
			}
		}

		$competency_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Competency_Service') : null;
		if ( $competency_service && method_exists( $competency_service, 'get_course_competencies' ) ) {
			$course_competencies = (array) $competency_service->get_course_competencies( $course_id );
			foreach ( $course_competencies as $course_competency ) {
				$course_competency = is_array( $course_competency ) ? $course_competency : array();
				$competency_id = sanitize_key( (string) ( $course_competency['id'] ?? '' ) );
				if ( '' === $competency_id || empty( $course_competency['required'] ) ) {
					continue;
				}
				$required_competencies[] = $competency_id;
			}

			$required_competencies = array_values( array_unique( array_filter( $required_competencies ) ) );
			if ( ! empty( $required_competencies ) ) {
				$completed_competencies = $this->get_completed_required_competencies( $user_id, $course_id, $required_competencies, $completed_evidences );
				if ( count( $completed_competencies ) < count( $required_competencies ) ) {
					$missing[] = __( 'Faltan competencias requeridas por demostrar en evidencias calificadas.', 'atora-lms' );
				}
			}
		}

		$diagnostics_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Diagnostics_Service') : null;
		if ( ! $diagnostics_service && class_exists( 'CLMS_Academic_Diagnostics_Service' ) ) {
			$diagnostics_service = new CLMS_Academic_Diagnostics_Service();
		}
		if ( $diagnostics_service && method_exists( $diagnostics_service, 'diagnose_course' ) ) {
			$diag = (array) $diagnostics_service->diagnose_course( $course_id );
			$configuration_warnings = isset( $diag['warnings'] ) && is_array( $diag['warnings'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $diag['warnings'] ) ) ) : array();
			$course_ready_for_certificate = ! empty( $diag['course_ready_for_certificate'] );
			if ( ! $course_ready_for_certificate ) {
				$missing[] = __( 'La configuración académica del curso requiere ajustes para certificar.', 'atora-lms' );
			}
		}

		$result = array(
			'eligible'             => empty( $missing ),
			'missing_requirements' => $missing,
			'configuration_warnings' => $configuration_warnings,
			'can_issue'            => $this->is_enabled(),
			'reason'               => empty( $missing ) ? '' : implode( ' ', $missing ),
			'required_progress'    => $min_progress,
			'progress_percent'     => $progress_percent,
			'final_average'        => $final_average,
			'passing_grade'        => $min_average,
			'required_evidences'   => $required_evidences,
			'completed_evidences'  => $completed_evidences,
			'required_competencies'=> $required_competencies,
			'completed_competencies'=> $completed_competencies,
			'completed_lessons'    => $completed,
			'total_lessons'        => $total_lessons,
			'course_ready_for_certificate' => $course_ready_for_certificate,
			'summary'              => $summary,
		);

		return apply_filters( 'clms_certificate_course_eligibility', $result, $user_id, $course_id, $summary );
	}

	/**
	 * Evalúa elegibilidad de certificado de programa.
	 *
	 * @param int   $user_id    Estudiante.
	 * @param int   $program_id Programa.
	 * @param array $context    Contexto agregado.
	 * @return array<string,mixed>
	 */
	public function evaluate_program( $user_id, $program_id, $context = array() ) {
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );
		$context    = is_array( $context ) ? $context : array();

		$min_average  = $this->get_min_average();
		$min_progress = $this->get_min_progress();
		$missing      = array();
		$configuration_warnings = array();
		$course_ready_for_certificate = true;

		$progress_percent = isset( $context['progress_percent'] ) ? absint( $context['progress_percent'] ) : 0;
		$final_average    = isset( $context['final_average'] ) ? absint( $context['final_average'] ) : 0;
		$completed_items  = isset( $context['completed_courses'] ) ? absint( $context['completed_courses'] ) : 0;
		$total_items      = isset( $context['total_courses'] ) ? absint( $context['total_courses'] ) : 0;

		if ( ! $this->is_enabled() || ! $this->allow_program_certificates() ) {
			$missing[] = __( 'Los certificados de programa están desactivados.', 'atora-lms' );
		}

		if ( ! $user_id || ! $program_id ) {
			$missing[] = __( 'Contexto de programa incompleto para certificar.', 'atora-lms' );
		}

		if ( $total_items <= 0 ) {
			$missing[] = __( 'El programa no tiene cursos configurados.', 'atora-lms' );
		}

		if ( $completed_items < $total_items ) {
			$missing[] = __( 'Debes completar todos los cursos del programa.', 'atora-lms' );
		}

		if ( $progress_percent < $min_progress ) {
			$missing[] = sprintf(
				/* translators: %d: progreso mínimo */
				__( 'Debes alcanzar al menos %d%% de avance global.', 'atora-lms' ),
				$min_progress
			);
		}

		if ( $final_average < $min_average ) {
			$missing[] = sprintf(
				/* translators: %d: promedio mínimo */
				__( 'Debes alcanzar un promedio global mínimo de %d%%.', 'atora-lms' ),
				$min_average
			);
		}

		$diagnostics_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Diagnostics_Service') : null;
		if ( ! $diagnostics_service && class_exists( 'CLMS_Academic_Diagnostics_Service' ) ) {
			$diagnostics_service = new CLMS_Academic_Diagnostics_Service();
		}
		if ( $diagnostics_service && method_exists( $diagnostics_service, 'diagnose_program' ) ) {
			$diag = (array) $diagnostics_service->diagnose_program( $program_id );
			$configuration_warnings = isset( $diag['warnings'] ) && is_array( $diag['warnings'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $diag['warnings'] ) ) ) : array();
			$course_ready_for_certificate = ! empty( $diag['program_ready'] );
			if ( ! $course_ready_for_certificate ) {
				$missing[] = __( 'La configuración académica del programa requiere ajustes para certificar.', 'atora-lms' );
			}
		}

		$result = array(
			'eligible'             => empty( $missing ),
			'missing_requirements' => $missing,
			'configuration_warnings' => $configuration_warnings,
			'can_issue'            => $this->allow_program_certificates(),
			'reason'               => empty( $missing ) ? '' : implode( ' ', $missing ),
			'progress_percent'     => $progress_percent,
			'final_average'        => $final_average,
			'passing_grade'        => $min_average,
			'completed_courses'    => $completed_items,
			'total_courses'        => $total_items,
			'course_ready_for_certificate' => $course_ready_for_certificate,
		);

		return apply_filters( 'clms_certificate_program_eligibility', $result, $user_id, $program_id, $context );
	}

	public function is_enabled() {
		return (bool) get_option( self::OPT_ENABLED, true );
	}

	public function is_public_verification_enabled() {
		return (bool) get_option( self::OPT_ALLOW_PUBLIC_VERIFY, true );
	}

	public function allow_reissue() {
		return (bool) get_option( self::OPT_ALLOW_REISSUE, true );
	}

	public function allow_program_certificates() {
		return (bool) get_option( self::OPT_ALLOW_PROGRAM_CERTS, true );
	}

	public function require_course_active() {
		return (bool) get_option( self::OPT_REQUIRE_COURSE_ACTIVE, true );
	}

	public function get_min_progress() {
		$value = absint( get_option( self::OPT_MIN_PROGRESS, 100 ) );
		if ( $value < 1 ) {
			$value = 100;
		}
		return min( 100, $value );
	}

	public function get_min_average() {
		$value = absint( get_option( self::OPT_MIN_AVERAGE, 70 ) );
		if ( $value < 1 ) {
			$value = 70;
		}
		return min( 100, $value );
	}

	/**
	 * Indica si los certificados están habilitados para el curso.
	 *
	 * @param int $course_id Curso.
	 * @return bool
	 */
	public function is_enabled_for_course( $course_id ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return $this->is_enabled();
		}

		$override = get_post_meta( $course_id, '_clms_course_certificate_enabled', true );
		if ( '' === (string) $override ) {
			return $this->is_enabled();
		}

		return '1' === (string) $override;
	}

	/**
	 * Progreso mínimo para certificar en un curso (con override por curso).
	 *
	 * @param int $course_id Curso.
	 * @return int
	 */
	public function get_course_min_progress( $course_id ) {
		$course_id = absint( $course_id );
		$global    = $this->get_min_progress();
		if ( ! $course_id ) {
			return $global;
		}

		$value = get_post_meta( $course_id, '_clms_course_certificate_min_progress', true );
		if ( '' === (string) $value ) {
			return $global;
		}

		$value = absint( $value );
		if ( $value < 1 ) {
			$value = $global;
		}
		return min( 100, $value );
	}

	/**
	 * Promedio mínimo para certificar en un curso (con override por curso).
	 *
	 * @param int $course_id Curso.
	 * @return int
	 */
	public function get_course_min_average( $course_id ) {
		$course_id = absint( $course_id );
		$global    = $this->get_min_average();
		if ( ! $course_id ) {
			return $global;
		}

		$value = get_post_meta( $course_id, '_clms_course_certificate_min_grade', true );
		if ( '' === (string) $value ) {
			return $global;
		}

		$value = absint( $value );
		if ( $value < 1 ) {
			$value = $global;
		}
		return min( 100, $value );
	}

	/**
	 * Competencias requeridas ya logradas.
	 *
	 * @param int   $user_id               Estudiante.
	 * @param int   $course_id             Curso.
	 * @param array $required_competencies IDs requeridos.
	 * @param array $completed_evidences   Evidencias requeridas aprobadas.
	 * @return array<int,string>
	 */
	protected function get_completed_required_competencies( $user_id, $course_id, $required_competencies, $completed_evidences ) {
		$user_id  = absint( $user_id );
		$course_id = absint( $course_id );
		$required_competencies = array_values( array_filter( array_map( 'sanitize_key', (array) $required_competencies ) ) );
		$completed_evidences   = array_values( array_filter( array_map( 'absint', (array) $completed_evidences ) ) );
		$result = array();

		if ( ! $user_id || ! $course_id || empty( $required_competencies ) ) {
			return $result;
		}

		$evidence_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
		foreach ( $completed_evidences as $activity_id ) {
			if ( ! $evidence_service || ! method_exists( $evidence_service, 'get_activity_evidence_config' ) ) {
				continue;
			}
			$config = (array) $evidence_service->get_activity_evidence_config( $activity_id );
			$ids    = isset( $config['competency_ids'] ) && is_array( $config['competency_ids'] ) ? $config['competency_ids'] : array();
			foreach ( $ids as $id ) {
				$id = sanitize_key( (string) $id );
				if ( in_array( $id, $required_competencies, true ) ) {
					$result[] = $id;
				}
			}
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 40,
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
					array(
						'key'   => '_clms_submission_status',
						'value' => 'graded',
					),
				),
				'no_found_rows'  => true,
			)
		);

		foreach ( (array) $query->posts as $submission_id ) {
			$submission_id = absint( $submission_id );
			if ( ! $submission_id ) {
				continue;
			}
			$rubric_scores = get_post_meta( $submission_id, '_clms_submission_rubric_scores', true );
			$rubric_scores = is_array( $rubric_scores ) ? $rubric_scores : array();
			foreach ( $rubric_scores as $score_row ) {
				$score_row = is_array( $score_row ) ? $score_row : array();
				$comp_ref_raw = isset( $score_row['competency_id'] ) ? (string) $score_row['competency_id'] : (string) ( $score_row['competency'] ?? '' );
				$comp_ref = sanitize_key( sanitize_title( $comp_ref_raw ) );
				if ( '' === $comp_ref || ! in_array( $comp_ref, $required_competencies, true ) ) {
					continue;
				}
				$score = isset( $score_row['score'] ) && is_numeric( $score_row['score'] ) ? (float) $score_row['score'] : null;
				$max   = isset( $score_row['max_points'] ) && is_numeric( $score_row['max_points'] ) ? (float) $score_row['max_points'] : 0;
				if ( null === $score || $max <= 0 ) {
					continue;
				}
				if ( ( $score / $max ) >= 0.7 ) {
					$result[] = $comp_ref;
				}
			}
		}

		return array_values( array_unique( array_filter( $result ) ) );
	}
}
