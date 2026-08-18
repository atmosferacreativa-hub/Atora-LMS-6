<?php
/**
 * Diagnóstico académico de configuración (curso/programa).
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Academic_Diagnostics_Service {

	/**
	 * Diagnóstico integral de curso.
	 *
	 * @param int $course_id Curso.
	 * @return array<string,mixed>
	 */
	public function diagnose_course( $course_id ) {
		$course_id = absint( $course_id );
		$result = array(
			'course_id'                    => $course_id,
			'lessons_count'                => 0,
			'competencies_count'           => 0,
			'required_competencies_count'  => 0,
			'evidences_count'              => 0,
			'required_evidences_count'     => 0,
			'evidences_without_competency_count' => 0,
			'required_evidences_without_rubric_count' => 0,
			'competencies_without_evidence_count' => 0,
			'required_competencies_without_required_evidence_count' => 0,
			'rubrics_count'                => 0,
			'certificate_ready'            => false,
			'course_ready_for_certificate' => false,
			'publish_ready'                => false,
			'sell_ready'                   => false,
			'evaluate_ready'               => false,
			'warnings'                     => array(),
			'errors'                       => array(),
			'recommendations'              => array(),
			'checklist'                    => array(),
			'maturity_checklist'           => array(),
		);

		if ( ! $course_id || ! class_exists( 'CLMS_Helper' ) ) {
			$result['errors'][] = __( 'No se pudo resolver el curso para diagnóstico académico.', 'atora-lms' );
			return $result;
		}

		$competency_service = clms_core('CLMS_Competency_Service');
		$evidence_service   = clms_core('CLMS_Evidence_Service');
		$competencies       = ( $competency_service && method_exists( $competency_service, 'get_course_competencies' ) )
			? (array) $competency_service->get_course_competencies( $course_id )
			: array();
		$lesson_ids         = (array) CLMS_Helper::get_course_lessons( $course_id );
		$lesson_ids         = array_values( array_filter( array_map( 'absint', $lesson_ids ) ) );
		$result['lessons_count'] = count( $lesson_ids );

		$comp_ids           = array();
		$required_comp_ids  = array();
		foreach ( $competencies as $competency ) {
			$competency = is_array( $competency ) ? $competency : array();
			$comp_id    = sanitize_key( (string) ( $competency['id'] ?? '' ) );
			$title      = sanitize_text_field( (string) ( $competency['title'] ?? '' ) );
			$weight     = absint( $competency['weight'] ?? 0 );
			$required   = ! empty( $competency['required'] );

			if ( '' === $title ) {
				$result['warnings'][] = __( 'Hay competencias sin título visible.', 'atora-lms' );
				continue;
			}
			if ( '' === $comp_id ) {
				$result['warnings'][] = sprintf(
					/* translators: %s: competencia */
					__( 'La competencia "%s" no tiene ID técnico válido.', 'atora-lms' ),
					$title
				);
				continue;
			}
			if ( in_array( $comp_id, $comp_ids, true ) ) {
				$result['warnings'][] = sprintf(
					/* translators: %s: competencia */
					__( 'La competencia "%s" está duplicada en el curso.', 'atora-lms' ),
					$title
				);
			}
			if ( $required && 0 === $weight ) {
				$result['warnings'][] = sprintf(
					/* translators: %s: competencia */
					__( 'La competencia requerida "%s" tiene peso 0.', 'atora-lms' ),
					$title
				);
			}
			$comp_ids[] = $comp_id;
			if ( $required ) {
				$required_comp_ids[] = $comp_id;
			}
		}

		$comp_ids          = array_values( array_unique( array_filter( $comp_ids ) ) );
		$required_comp_ids = array_values( array_unique( array_filter( $required_comp_ids ) ) );
		$result['competencies_count']          = count( $comp_ids );
		$result['required_competencies_count'] = count( $required_comp_ids );

		if ( 0 === $result['competencies_count'] ) {
			$result['warnings'][] = __( 'Este curso no tiene competencias configuradas.', 'atora-lms' );
		}

		$used_comp_ids          = array();
		$used_required_comp_ids = array();
		$rubric_ids             = array();

		foreach ( $lesson_ids as $lesson_id ) {
			$lesson_id = absint( $lesson_id );
			if ( ! $lesson_id ) {
				continue;
			}

			$activity_type = sanitize_key( (string) get_post_meta( $lesson_id, 'lm_activity_type', true ) );
			$rubric_id     = absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) );
			if ( $rubric_id ) {
				$rubric_ids[] = $rubric_id;
			}

			$evidence = ( $evidence_service && method_exists( $evidence_service, 'get_activity_evidence_config' ) )
				? (array) $evidence_service->get_activity_evidence_config( $lesson_id )
				: array();

			$evidence_type  = sanitize_key( (string) ( $evidence['evidence_type'] ?? 'practice' ) );
			$is_required    = ! empty( $evidence['is_required_for_certificate'] );
			$minimum_grade  = absint( $evidence['minimum_grade'] ?? 0 );
			$competency_ids = isset( $evidence['competency_ids'] ) && is_array( $evidence['competency_ids'] )
				? array_values( array_filter( array_map( 'sanitize_key', $evidence['competency_ids'] ) ) )
				: array();
			$is_evaluable   = in_array( $activity_type, array( 'tarea', 'quiz' ), true ) || $rubric_id > 0;
			$is_evidence    = $is_required || 'practice' !== $evidence_type || in_array( $activity_type, array( 'tarea', 'quiz' ), true );

			if ( ! $is_evidence ) {
				continue;
			}

			$result['evidences_count']++;
			if ( $is_required ) {
				$result['required_evidences_count']++;
			}

			if ( $is_required && empty( $competency_ids ) ) {
				$result['evidences_without_competency_count']++;
				$result['warnings'][] = sprintf(
					/* translators: %s: actividad */
					__( 'La evidencia obligatoria "%s" no tiene competencias asociadas.', 'atora-lms' ),
					get_the_title( $lesson_id )
				);
			}

			if ( $is_required && ! $is_evaluable ) {
				$result['warnings'][] = sprintf(
					/* translators: %s: actividad */
					__( 'La evidencia obligatoria "%s" no tiene una actividad evaluable.', 'atora-lms' ),
					get_the_title( $lesson_id )
				);
			}

			if ( $is_required && ! $rubric_id ) {
				$result['required_evidences_without_rubric_count']++;
				$result['warnings'][] = sprintf(
					/* translators: %s: actividad */
					__( 'La evidencia obligatoria "%s" no tiene rúbrica asociada.', 'atora-lms' ),
					get_the_title( $lesson_id )
				);
			}

			if ( in_array( $evidence_type, array( 'certifiable_evidence', 'final_exam', 'required' ), true ) && $minimum_grade <= 0 ) {
				$result['warnings'][] = sprintf(
					/* translators: %s: actividad */
					__( 'La evidencia certificable "%s" no tiene nota mínima definida.', 'atora-lms' ),
					get_the_title( $lesson_id )
				);
			}

			foreach ( $competency_ids as $competency_id ) {
				if ( ! in_array( $competency_id, $comp_ids, true ) ) {
					$result['warnings'][] = sprintf(
						/* translators: 1: competencia, 2: actividad */
						__( 'La evidencia "%2$s" referencia la competencia inexistente "%1$s".', 'atora-lms' ),
						$competency_id,
						get_the_title( $lesson_id )
					);
					continue;
				}
				$used_comp_ids[] = $competency_id;
				if ( $is_required ) {
					$used_required_comp_ids[] = $competency_id;
				}
			}

			$this->diagnose_rubric_for_lesson( $lesson_id, $rubric_id, $comp_ids, $result );
		}

		$used_comp_ids          = array_values( array_unique( array_filter( $used_comp_ids ) ) );
		$used_required_comp_ids = array_values( array_unique( array_filter( $used_required_comp_ids ) ) );
		$result['rubrics_count'] = count( array_unique( array_filter( array_map( 'absint', $rubric_ids ) ) ) );

		$required_without_evidence = array_values( array_diff( $required_comp_ids, $used_comp_ids ) );
		$result['competencies_without_evidence_count'] = count( $required_without_evidence );
		if ( ! empty( $required_without_evidence ) ) {
			$result['warnings'][] = __( 'Hay competencias requeridas sin evidencia asociada.', 'atora-lms' );
		}

		$required_without_required_evidence = array_values( array_diff( $required_comp_ids, $used_required_comp_ids ) );
		$result['required_competencies_without_required_evidence_count'] = count( $required_without_required_evidence );
		if ( ! empty( $required_without_required_evidence ) ) {
			$result['warnings'][] = __( 'El certificado exige competencias requeridas que no están ligadas a evidencias obligatorias.', 'atora-lms' );
		}

		if ( 0 === $result['required_evidences_count'] && ! empty( $required_comp_ids ) ) {
			$result['warnings'][] = __( 'Hay competencias requeridas pero no existen evidencias obligatorias configuradas.', 'atora-lms' );
		}

		$result['warnings'] = $this->unique_text_list( $result['warnings'] );
		$result['errors']   = $this->unique_text_list( $result['errors'] );

		if ( empty( $result['warnings'] ) && empty( $result['errors'] ) ) {
			$result['recommendations'][] = __( 'El curso está listo para certificar.', 'atora-lms' );
		} else {
			$result['recommendations'][] = __( 'Revisa competencias, evidencias y rúbricas antes de certificar.', 'atora-lms' );
		}

		$result['certificate_ready']            = empty( $result['errors'] );
		$result['course_ready_for_certificate'] = empty( $result['errors'] );
		$result['maturity_checklist']           = $this->build_maturity_checklist( $course_id, $result );
		$result['publish_ready']                = ! empty( $result['maturity_checklist']['publish']['ready'] );
		$result['sell_ready']                   = ! empty( $result['maturity_checklist']['sell']['ready'] );
		$result['evaluate_ready']               = ! empty( $result['maturity_checklist']['evaluate']['ready'] );
		$result['course_ready_for_certificate'] = ! empty( $result['maturity_checklist']['certify']['ready'] );
		$result['certificate_ready']            = ! empty( $result['maturity_checklist']['certify']['ready'] );
		$result['checklist']                    = $this->build_course_checklist( $result );

		return $result;
	}

	/**
	 * Diagnóstico agregado de programa.
	 *
	 * @param int $program_id Programa.
	 * @return array<string,mixed>
	 */
	public function diagnose_program( $program_id ) {
		$program_id = absint( $program_id );
		$result = array(
			'program_id'       => $program_id,
			'courses_count'    => 0,
			'ready_courses'    => 0,
			'warnings'         => array(),
			'errors'           => array(),
			'courses'          => array(),
			'program_ready'    => false,
		);

		if ( ! $program_id || ! class_exists( 'CLMS_Helper' ) || ! method_exists( 'CLMS_Helper', 'get_program_courses' ) ) {
			$result['errors'][] = __( 'No se pudo resolver el programa para diagnóstico.', 'atora-lms' );
			return $result;
		}

		$course_ids = (array) CLMS_Helper::get_program_courses( $program_id );
		$course_ids = array_values( array_filter( array_map( 'absint', $course_ids ) ) );
		$result['courses_count'] = count( $course_ids );

		if ( empty( $course_ids ) ) {
			$result['warnings'][] = __( 'El programa no tiene cursos asociados.', 'atora-lms' );
		}

		foreach ( $course_ids as $course_id ) {
			$course_diag = $this->diagnose_course( $course_id );
			$course_title = get_the_title( $course_id );
			$result['courses'][] = array(
				'course_id'   => $course_id,
				'course_title'=> $course_title,
				'ready'       => ! empty( $course_diag['course_ready_for_certificate'] ),
				'warnings'    => $course_diag['warnings'],
				'errors'      => $course_diag['errors'],
			);
			foreach ( (array) ( $course_diag['warnings'] ?? array() ) as $warning ) {
				$warning = sanitize_text_field( (string) $warning );
				if ( '' === $warning ) {
					continue;
				}
				$result['warnings'][] = sprintf(
					/* translators: 1: curso, 2: advertencia */
					__( '%1$s: %2$s', 'atora-lms' ),
					$course_title,
					$warning
				);
			}
			if ( ! empty( $course_diag['course_ready_for_certificate'] ) ) {
				$result['ready_courses']++;
			} else {
				$result['warnings'][] = sprintf(
					/* translators: %s: curso */
					__( 'El curso "%s" no está listo para certificar dentro del programa.', 'atora-lms' ),
					get_the_title( $course_id )
				);
			}
		}

		$result['warnings']     = $this->unique_text_list( $result['warnings'] );
		$result['errors']       = $this->unique_text_list( $result['errors'] );
		$result['program_ready'] = empty( $result['errors'] ) && $result['ready_courses'] === $result['courses_count'] && $result['courses_count'] > 0;

		return $result;
	}

	/**
	 * Advertencias de curso.
	 *
	 * @param int $course_id Curso.
	 * @return array<int,string>
	 */
	public function get_course_warnings( $course_id ) {
		$diag = $this->diagnose_course( $course_id );
		return isset( $diag['warnings'] ) && is_array( $diag['warnings'] ) ? $diag['warnings'] : array();
	}

	/**
	 * Curso listo para certificar.
	 *
	 * @param int $course_id Curso.
	 * @return bool
	 */
	public function is_course_certificate_ready( $course_id ) {
		$diag = $this->diagnose_course( $course_id );
		return ! empty( $diag['course_ready_for_certificate'] );
	}

	/**
	 * Diagnóstico de rúbrica por actividad.
	 *
	 * @param int   $lesson_id  Lección.
	 * @param int   $rubric_id  Rúbrica.
	 * @param array $course_comp_ids IDs de competencia del curso.
	 * @param array $result     Acumulador.
	 * @return void
	 */
	protected function diagnose_rubric_for_lesson( $lesson_id, $rubric_id, $course_comp_ids, &$result ) {
		$lesson_id        = absint( $lesson_id );
		$rubric_id        = absint( $rubric_id );
		$course_comp_ids  = is_array( $course_comp_ids ) ? $course_comp_ids : array();
		$result           = is_array( $result ) ? $result : array();

		if ( ! $lesson_id || ! $rubric_id || ! class_exists( 'CLMS_Rubric' ) || ! method_exists( 'CLMS_Rubric', 'get_criteria' ) ) {
			return;
		}

		$criteria = (array) CLMS_Rubric::get_criteria( $rubric_id );
		if ( empty( $criteria ) ) {
			$result['warnings'][] = sprintf(
				/* translators: %s: actividad */
				__( 'La rúbrica de "%s" no tiene criterios configurados.', 'atora-lms' ),
				get_the_title( $lesson_id )
			);
			return;
		}

		foreach ( $criteria as $criterion ) {
			$criterion = is_array( $criterion ) ? $criterion : array();
			$name      = sanitize_text_field( (string) ( $criterion['name'] ?? '' ) );
			$max       = absint( $criterion['max_points'] ?? 0 );
			$levels    = isset( $criterion['levels'] ) && is_array( $criterion['levels'] ) ? $criterion['levels'] : array();
			$legacy    = sanitize_text_field( (string) ( $criterion['competency'] ?? '' ) );
			$comp_id   = sanitize_key( (string) ( $criterion['competency_id'] ?? '' ) );
			if ( class_exists( 'CLMS_Helper' ) ) {
				$competency_service = clms_core('CLMS_Competency_Service');
				if ( $competency_service && method_exists( $competency_service, 'resolve_competency_id' ) ) {
					$course_id = class_exists( 'CLMS_Helper' ) ? absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) ) : 0;
					$comp_id = sanitize_key( (string) $competency_service->resolve_competency_id( $course_id, $comp_id, $legacy ) );
				}
			}
			if ( '' === $comp_id ) {
				$comp_id = sanitize_key( sanitize_title( $legacy ) );
			}

			if ( '' === $name ) {
				$result['warnings'][] = sprintf(
					/* translators: %s: actividad */
					__( 'La rúbrica de "%s" tiene criterios sin título.', 'atora-lms' ),
					get_the_title( $lesson_id )
				);
			}

			if ( $max <= 0 ) {
				$result['warnings'][] = sprintf(
					/* translators: %s: actividad */
					__( 'La rúbrica de "%s" tiene criterios sin puntaje máximo válido.', 'atora-lms' ),
					get_the_title( $lesson_id )
				);
			}

			if ( empty( $levels ) ) {
				$result['warnings'][] = sprintf(
					/* translators: %s: actividad */
					__( 'La rúbrica de "%s" no define niveles de desempeño.', 'atora-lms' ),
					get_the_title( $lesson_id )
				);
			}

			if ( '' === $comp_id ) {
				$result['warnings'][] = sprintf(
					/* translators: %s: actividad */
					__( 'La rúbrica de "%s" tiene criterios sin competencia asociada.', 'atora-lms' ),
					get_the_title( $lesson_id )
				);
			} elseif ( ! in_array( $comp_id, $course_comp_ids, true ) ) {
				$result['warnings'][] = sprintf(
					/* translators: 1: competencia, 2: actividad */
					__( 'La rúbrica de "%2$s" usa la competencia "%1$s" que no existe en el curso.', 'atora-lms' ),
					$comp_id,
					get_the_title( $lesson_id )
				);
			}
		}
	}

	/**
	 * Checklist visual de preparación.
	 *
	 * @param array $diag Diagnóstico.
	 * @return array<int,array<string,mixed>>
	 */
	protected function build_course_checklist( $diag ) {
		$diag = is_array( $diag ) ? $diag : array();
		$warnings = isset( $diag['warnings'] ) && is_array( $diag['warnings'] ) ? $diag['warnings'] : array();
		$errors   = isset( $diag['errors'] ) && is_array( $diag['errors'] ) ? $diag['errors'] : array();
		$inactivity_days = absint( get_option( 'clms_inactivity_days_threshold', 14 ) );
		if ( $inactivity_days <= 0 ) {
			$inactivity_days = 14;
		}

		return array(
			array(
				'label'  => __( 'Competencias configuradas', 'atora-lms' ),
				'status' => ( absint( $diag['competencies_count'] ?? 0 ) > 0 ) ? 'ok' : 'warning',
				'value'  => absint( $diag['competencies_count'] ?? 0 ),
			),
			array(
				'label'  => __( 'Evidencias obligatorias', 'atora-lms' ),
				'status' => ( absint( $diag['required_evidences_count'] ?? 0 ) > 0 ) ? 'ok' : 'warning',
				'value'  => absint( $diag['required_evidences_count'] ?? 0 ),
			),
			array(
				'label'  => __( 'Rúbricas disponibles', 'atora-lms' ),
				'status' => ( absint( $diag['rubrics_count'] ?? 0 ) > 0 ) ? 'ok' : 'warning',
				'value'  => absint( $diag['rubrics_count'] ?? 0 ),
			),
			array(
				'label'  => __( 'Curso listo para certificar', 'atora-lms' ),
				'status' => ! empty( $diag['course_ready_for_certificate'] ) ? 'ok' : ( ! empty( $errors ) ? 'error' : 'warning' ),
				'value'  => ! empty( $diag['course_ready_for_certificate'] ) ? __( 'Sí', 'atora-lms' ) : __( 'Requiere ajustes', 'atora-lms' ),
			),
			array(
				'label'  => __( 'Advertencias', 'atora-lms' ),
				'status' => empty( $warnings ) ? 'ok' : 'warning',
				'value'  => count( $warnings ),
			),
			array(
				'label'  => __( 'Errores críticos', 'atora-lms' ),
				'status' => empty( $errors ) ? 'ok' : 'error',
				'value'  => count( $errors ),
			),
			array(
				'label'  => __( 'Umbral de inactividad (días)', 'atora-lms' ),
				'status' => $inactivity_days > 0 ? 'ok' : 'warning',
				'value'  => $inactivity_days,
			),
		);
	}

	/**
	 * Checklist de madurez por propósito operativo.
	 *
	 * @param int   $course_id Curso.
	 * @param array $diag      Diagnóstico.
	 * @return array<string,array<string,mixed>>
	 */
	protected function build_maturity_checklist( $course_id, $diag ) {
		$course_id = absint( $course_id );
		$diag      = is_array( $diag ) ? $diag : array();
		$status    = get_post_status( $course_id );
		$title     = trim( wp_strip_all_tags( (string) get_the_title( $course_id ) ) );
		$content   = trim( (string) get_post_field( 'post_content', $course_id ) );
		$excerpt   = trim( (string) get_post_meta( $course_id, '_clms_course_excerpt', true ) );
		$objective = trim( (string) get_post_meta( $course_id, '_clms_course_academic_objective_general', true ) );
		if ( '' === $objective ) {
			$objective = trim( (string) get_post_meta( $course_id, '_clms_course_objective_general', true ) );
		}

		$has_teacher = ! empty( array_filter( array_map( 'absint', (array) get_post_meta( $course_id, '_clms_course_teacher_ids', true ) ) ) );
		$commercial_mode = sanitize_key( (string) get_post_meta( $course_id, '_clms_commercial_mode', true ) );
		$linked_product  = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_entity_linked_product_id' )
			? absint( CLMS_Helper::get_entity_linked_product_id( $course_id ) )
			: absint( get_post_meta( $course_id, '_clms_linked_product_id', true ) );
		$cta_url         = trim( (string) get_post_meta( $course_id, '_clms_commercial_cta_url', true ) );
		$lessons_count   = absint( $diag['lessons_count'] ?? 0 );
		$warnings_count  = count( isset( $diag['warnings'] ) && is_array( $diag['warnings'] ) ? $diag['warnings'] : array() );
		$errors_count    = count( isset( $diag['errors'] ) && is_array( $diag['errors'] ) ? $diag['errors'] : array() );

		$publish_missing = array();
		if ( '' === $title ) {
			$publish_missing[] = __( 'Título del curso', 'atora-lms' );
		}
		if ( '' === $content && '' === $excerpt ) {
			$publish_missing[] = __( 'Descripción o resumen del curso', 'atora-lms' );
		}
		if ( '' === $objective ) {
			$publish_missing[] = __( 'Objetivo académico', 'atora-lms' );
		}
		if ( $lessons_count <= 0 ) {
			$publish_missing[] = __( 'Módulos o lecciones', 'atora-lms' );
		}

		$sell_missing = array();
		if ( 'commercial' === $commercial_mode && ! $linked_product && '' === $cta_url ) {
			$sell_missing[] = __( 'CTA comercial o producto vinculado', 'atora-lms' );
		}
		if ( '' === $excerpt && 'commercial' === $commercial_mode ) {
			$sell_missing[] = __( 'Resumen comercial del curso', 'atora-lms' );
		}

		$evaluate_missing = array();
		if ( $lessons_count <= 0 ) {
			$evaluate_missing[] = __( 'Lecciones evaluables', 'atora-lms' );
		}
		if ( absint( $diag['evidences_count'] ?? 0 ) <= 0 ) {
			$evaluate_missing[] = __( 'Evidencias o actividades de evaluación', 'atora-lms' );
		}
		if ( absint( $diag['required_evidences_without_rubric_count'] ?? 0 ) > 0 ) {
			$evaluate_missing[] = __( 'Rúbricas en evidencias obligatorias', 'atora-lms' );
		}
		if ( ! $has_teacher ) {
			$evaluate_missing[] = __( 'Profesor responsable del curso', 'atora-lms' );
		}

		$certify_missing = array();
		if ( absint( $diag['required_evidences_count'] ?? 0 ) <= 0 ) {
			$certify_missing[] = __( 'Evidencias obligatorias para certificación', 'atora-lms' );
		}
		if ( absint( $diag['competencies_without_evidence_count'] ?? 0 ) > 0 ) {
			$certify_missing[] = __( 'Competencias requeridas con evidencia medible', 'atora-lms' );
		}
		if ( absint( $diag['required_competencies_without_required_evidence_count'] ?? 0 ) > 0 ) {
			$certify_missing[] = __( 'Competencias requeridas ligadas a evidencias obligatorias', 'atora-lms' );
		}
		if ( $errors_count > 0 ) {
			$certify_missing[] = __( 'Errores críticos de configuración académica', 'atora-lms' );
		}

		$publish_ready  = empty( $publish_missing );
		$sell_ready     = empty( $sell_missing );
		$evaluate_ready = empty( $evaluate_missing );
		$certify_ready  = empty( $certify_missing ) && $errors_count <= 0;

		return array(
			'publish'  => array(
				'label'          => __( 'Listo para publicar', 'atora-lms' ),
				'ready'          => $publish_ready,
				'status'         => $publish_ready ? 'ok' : 'warning',
				'missing'        => $publish_missing,
				'action'         => __( 'Completa ficha básica y estructura de lecciones.', 'atora-lms' ),
				'action_target'  => 'course_editor',
			),
			'sell'     => array(
				'label'          => __( 'Listo para vender', 'atora-lms' ),
				'ready'          => $sell_ready,
				'status'         => $sell_ready ? 'ok' : 'warning',
				'missing'        => $sell_missing,
				'action'         => __( 'Configura CTA/comercial o vinculación WooCommerce.', 'atora-lms' ),
				'action_target'  => 'commercial',
			),
			'evaluate' => array(
				'label'          => __( 'Listo para evaluar', 'atora-lms' ),
				'ready'          => $evaluate_ready,
				'status'         => $evaluate_ready ? 'ok' : 'warning',
				'missing'        => $evaluate_missing,
				'action'         => __( 'Asegura evidencias, rúbricas y docente responsable.', 'atora-lms' ),
				'action_target'  => 'lessons',
			),
			'certify'  => array(
				'label'          => __( 'Listo para certificar', 'atora-lms' ),
				'ready'          => $certify_ready,
				'status'         => $certify_ready ? 'ok' : ( $errors_count > 0 ? 'error' : 'warning' ),
				'missing'        => $certify_missing,
				'action'         => __( 'Corrige competencias/evidencias y valida reglas de certificado.', 'atora-lms' ),
				'action_target'  => 'certification',
			),
			'summary'  => array(
				'label'           => __( 'Estado general', 'atora-lms' ),
				'status'          => ( $publish_ready && $sell_ready && $evaluate_ready && $certify_ready ) ? 'ok' : ( $errors_count > 0 ? 'error' : 'warning' ),
				'post_status'     => sanitize_key( (string) $status ),
				'warnings_count'  => $warnings_count,
				'errors_count'    => $errors_count,
			),
		);
	}

	/**
	 * Lista de textos únicos saneados.
	 *
	 * @param array $items Items.
	 * @return array<int,string>
	 */
	protected function unique_text_list( $items ) {
		$items = is_array( $items ) ? $items : array();
		$items = array_map(
			static function ( $item ) {
				return sanitize_text_field( (string) $item );
			},
			$items
		);

		return array_values( array_filter( array_unique( $items ) ) );
	}
}
