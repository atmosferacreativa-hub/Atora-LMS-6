<?php
/**
 * Servicio de plan de mejora académico.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Improvement_Plan_Service {

	/**
	 * Genera plan desde una entrega.
	 *
	 * @param int $submission_id Entrega.
	 * @return array<string,mixed>
	 */
	public function build_from_submission( $submission_id ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return $this->empty_plan();
		}

		$grade    = get_post_meta( $submission_id, '_clms_submission_grade', true );
		$feedback = (string) get_post_meta( $submission_id, '_clms_submission_feedback', true );
		$rubric   = get_post_meta( $submission_id, '_clms_submission_rubric_scores', true );
		$rubric   = is_array( $rubric ) ? $rubric : array();

		$plan = $this->build_from_data(
			array(
				'grade'         => $grade,
				'feedback'      => $feedback,
				'rubric_scores' => $rubric,
			)
		);
		$plan = $this->enrich_plan_from_competencies( $plan, $submission_id );

		$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$url       = $lesson_id ? get_permalink( $lesson_id ) : '';
		if ( $url ) {
			$plan['related_resources'][] = array(
				'label' => __( 'Repasar la lección relacionada', 'atora-lms' ),
				'url'   => $url,
			);
		}

		return $this->normalize_plan( $plan );
	}

	/**
	 * Genera plan desde datos académicos normalizados.
	 *
	 * @param array<string,mixed> $data Datos.
	 * @return array<string,mixed>
	 */
	public function build_from_data( $data ) {
		$data = is_array( $data ) ? $data : array();

		$grade    = isset( $data['grade'] ) && is_numeric( $data['grade'] ) ? (float) $data['grade'] : null;
		$feedback = isset( $data['feedback'] ) ? sanitize_textarea_field( (string) $data['feedback'] ) : '';
		$rubric   = isset( $data['rubric_scores'] ) && is_array( $data['rubric_scores'] ) ? $data['rubric_scores'] : array();

		$strengths = array();
		$weaknesses = array();

		foreach ( $rubric as $criterion_raw ) {
			if ( ! is_array( $criterion_raw ) ) {
				continue;
			}
			$label = isset( $criterion_raw['name'] ) ? sanitize_text_field( (string) $criterion_raw['name'] ) : '';
			$score = isset( $criterion_raw['score'] ) && is_numeric( $criterion_raw['score'] ) ? (float) $criterion_raw['score'] : null;
			$max   = isset( $criterion_raw['max_points'] ) && is_numeric( $criterion_raw['max_points'] ) ? (float) $criterion_raw['max_points'] : 0;
			if ( '' === $label || null === $score || $max <= 0 ) {
				continue;
			}

			$ratio = $score / $max;
			if ( $ratio >= 0.75 ) {
				$strengths[] = $label;
			} elseif ( $ratio <= 0.55 ) {
				$weaknesses[] = $label;
			}
		}

		$recommendations = array();
		if ( $grade !== null ) {
			if ( $grade < 60 ) {
				$recommendations[] = __( 'Repite la actividad con foco en los criterios con menor puntaje.', 'atora-lms' );
			} elseif ( $grade < 80 ) {
				$recommendations[] = __( 'Refuerza los puntos intermedios para subir tu desempeño global.', 'atora-lms' );
			} else {
				$recommendations[] = __( 'Mantén el nivel y busca mayor precisión en tus próximos entregables.', 'atora-lms' );
			}
		}

		if ( ! empty( $weaknesses ) ) {
			$recommendations[] = sprintf(
				/* translators: %s: lista de aspectos */
				__( 'Prioriza estos aspectos: %s.', 'atora-lms' ),
				implode( ', ', array_slice( $weaknesses, 0, 3 ) )
			);
		}

		if ( '' !== $feedback ) {
			$recommendations[] = __( 'Usa el feedback docente como checklist antes de tu siguiente entrega.', 'atora-lms' );
		}

		$next_action = '';
		if ( ! empty( $weaknesses[0] ) ) {
			$next_action = sprintf(
				/* translators: %s: aspecto */
				__( 'Dedica 20 minutos hoy a reforzar: %s.', 'atora-lms' ),
				$weaknesses[0]
			);
		} elseif ( $grade !== null ) {
			$next_action = $grade < 70
				? __( 'Revisa la lección y vuelve a intentar una actividad similar.', 'atora-lms' )
				: __( 'Continúa con la próxima actividad para consolidar el avance.', 'atora-lms' );
		}

		$plan = array(
			'strengths'         => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $strengths ) ) ) ),
			'weaknesses'        => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $weaknesses ) ) ) ),
			'recommendations'   => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $recommendations ) ) ) ),
			'next_action'       => sanitize_text_field( $next_action ),
			'related_resources' => array(),
			'generated_by'      => 'rules',
		);

		return $this->normalize_plan( $this->maybe_enrich_with_ai( $plan, $data ) );
	}

	/**
	 * Devuelve estructura base vacía.
	 *
	 * @return array<string,mixed>
	 */
	public function empty_plan() {
		return $this->normalize_plan(
			array(
			'strengths'         => array(),
			'weaknesses'        => array(),
			'recommendations'   => array(),
			'next_action'       => '',
			'related_resources' => array(),
			'generated_by'      => 'manual',
			)
		);
	}

	/**
	 * Normaliza estructura del plan de mejora para consumo UI consistente.
	 *
	 * @param array  $plan               Plan base.
	 * @param string $fallback_next_step Fallback de próxima acción.
	 * @return array<string,mixed>
	 */
	public function normalize_plan( $plan, $fallback_next_step = '' ) {
		$plan = is_array( $plan ) ? $plan : array();
		$fallback_next_step = sanitize_text_field( (string) $fallback_next_step );

		$strengths = isset( $plan['strengths'] ) && is_array( $plan['strengths'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', $plan['strengths'] ) ) )
			: array();
		$weaknesses = isset( $plan['weaknesses'] ) && is_array( $plan['weaknesses'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', $plan['weaknesses'] ) ) )
			: array();
		$recommendations = isset( $plan['recommendations'] ) && is_array( $plan['recommendations'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', $plan['recommendations'] ) ) )
			: array();

		$next_action = isset( $plan['next_action'] ) ? sanitize_text_field( (string) $plan['next_action'] ) : '';
		$recommendation = isset( $plan['recommendation'] ) ? sanitize_text_field( (string) $plan['recommendation'] ) : '';
		$summary = isset( $plan['summary'] ) ? sanitize_text_field( (string) $plan['summary'] ) : '';

		if ( '' === $recommendation ) {
			if ( '' !== $next_action ) {
				$recommendation = $next_action;
			} elseif ( ! empty( $recommendations[0] ) ) {
				$recommendation = $recommendations[0];
			} elseif ( '' !== $fallback_next_step ) {
				$recommendation = $fallback_next_step;
			}
		}

		if ( '' === $summary ) {
			if ( ! empty( $strengths[0] ) || ! empty( $weaknesses[0] ) ) {
				$summary = sprintf(
					/* translators: 1: fortaleza, 2: aspecto a reforzar */
					__( 'Fortaleza: %1$s. A reforzar: %2$s.', 'atora-lms' ),
					! empty( $strengths[0] ) ? $strengths[0] : __( 'avance general', 'atora-lms' ),
					! empty( $weaknesses[0] ) ? $weaknesses[0] : __( 'consistencia en entregas', 'atora-lms' )
				);
			} elseif ( '' !== $recommendation ) {
				$summary = $recommendation;
			}
		}

		$related_resources = isset( $plan['related_resources'] ) && is_array( $plan['related_resources'] ) ? $plan['related_resources'] : array();
		$clean_resources = array();
		foreach ( $related_resources as $resource ) {
			if ( ! is_array( $resource ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $resource['label'] ?? '' ) );
			$url   = esc_url_raw( (string) ( $resource['url'] ?? '' ) );
			if ( '' === $label && '' === $url ) {
				continue;
			}
			$clean_resources[] = array(
				'label' => $label,
				'url'   => $url,
			);
		}

		$generated_by = isset( $plan['generated_by'] ) ? sanitize_key( (string) $plan['generated_by'] ) : 'fallback';
		if ( ! in_array( $generated_by, array( 'rules', 'manual', 'ai', 'fallback' ), true ) ) {
			$generated_by = 'fallback';
		}

		return array(
			'summary'           => $summary,
			'recommendation'    => $recommendation,
			'recommendations'   => $recommendations,
			'next_action'       => $next_action,
			'strengths'         => $strengths,
			'weaknesses'        => $weaknesses,
			'related_resources' => $clean_resources,
			'generated_by'      => $generated_by,
		);
	}

	/**
	 * Enriquecimiento opcional con IA (sin bloquear).
	 *
	 * @param array<string,mixed> $plan Plan actual.
	 * @param array<string,mixed> $data Datos base.
	 * @return array<string,mixed>
	 */
	protected function maybe_enrich_with_ai( $plan, $data ) {
		$allow_ai = (bool) apply_filters( 'clms_improvement_plan_use_ai', false, $data, $plan );
		if ( ! $allow_ai ) {
			return $plan;
		}

		$ai = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
		if ( ! $ai || ! method_exists( $ai, 'chat' ) ) {
			return $plan;
		}

		$messages = array(
			array(
				'role'    => 'user',
				'content' => sprintf(
					/* translators: 1: fortalezas, 2: debilidades */
					__( 'Resume en una recomendación breve y accionable este plan. Fortalezas: %1$s. Debilidades: %2$s. Responde en español.', 'atora-lms' ),
					implode( ', ', (array) $plan['strengths'] ),
					implode( ', ', (array) $plan['weaknesses'] )
				),
			),
		);

		$response = $ai->chat(
			$messages,
			array(
				'max_tokens'  => 120,
				'temperature' => 0.3,
			)
		);

		if ( is_wp_error( $response ) || '' === trim( (string) $response ) ) {
			return $plan;
		}

		$plan['recommendations'][] = sanitize_text_field( (string) $response );
		$plan['generated_by']      = 'ai';

		return $plan;
	}

	/**
	 * Enriquece plan con foco por competencias y evidencias.
	 *
	 * @param array $plan          Plan base.
	 * @param int   $submission_id Entrega.
	 * @return array
	 */
	protected function enrich_plan_from_competencies( $plan, $submission_id ) {
		$plan          = is_array( $plan ) ? $plan : array();
		$submission_id = absint( $submission_id );

		if ( ! $submission_id || ! class_exists( 'CLMS_Helper' ) ) {
			return $plan;
		}

		$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		$course_id  = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		if ( ! $course_id ) {
			$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$course_id = $lesson_id ? absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) ) : 0;
		}
		if ( ! $student_id || ! $course_id ) {
			return $plan;
		}

		$status_service = clms_core('CLMS_Academic_Status_Service');
		if ( ! $status_service || ! method_exists( $status_service, 'get_student_course_status' ) ) {
			return $plan;
		}

		$status       = (array) $status_service->get_student_course_status(
			$student_id,
			$course_id,
			array(
				'skip_improvement_plan' => true,
			)
		);
		$competencies = isset( $status['competencies'] ) && is_array( $status['competencies'] ) ? $status['competencies'] : array();
		$evidences    = isset( $status['evidences']['items'] ) && is_array( $status['evidences']['items'] ) ? $status['evidences']['items'] : array();

		if ( empty( $competencies ) ) {
			return $plan;
		}

		usort(
			$competencies,
			static function ( $a, $b ) {
				$a_score = absint( $a['score'] ?? 0 );
				$b_score = absint( $b['score'] ?? 0 );
				return $b_score <=> $a_score;
			}
		);

		$strong = isset( $competencies[0]['title'] ) ? sanitize_text_field( (string) $competencies[0]['title'] ) : '';
		$weak   = isset( $competencies[ count( $competencies ) - 1 ]['title'] ) ? sanitize_text_field( (string) $competencies[ count( $competencies ) - 1 ]['title'] ) : '';
		$pending_evidence = '';
		foreach ( $evidences as $evidence ) {
			$evidence = is_array( $evidence ) ? $evidence : array();
			if ( empty( $evidence['is_required_for_certificate'] ) || ! empty( $evidence['approved'] ) ) {
				continue;
			}
			$pending_evidence = sanitize_text_field( (string) ( $evidence['activity_title'] ?? '' ) );
			if ( '' !== $pending_evidence ) {
				break;
			}
		}

		if ( '' !== $strong ) {
			$plan['strengths'][] = $strong;
		}
		if ( '' !== $weak ) {
			$plan['weaknesses'][] = $weak;
		}

		if ( '' !== $strong && '' !== $weak ) {
			$plan['recommendations'][] = sprintf(
				/* translators: 1: competencia fuerte, 2: competencia por reforzar */
				__( 'Tu mayor avance está en %1$s. Conviene reforzar %2$s para consolidar el desempeño.', 'atora-lms' ),
				$strong,
				$weak
			);
		}

		if ( '' !== $pending_evidence ) {
			$plan['recommendations'][] = sprintf(
				/* translators: %s: evidencia pendiente */
				__( 'Prioriza la evidencia pendiente: %s.', 'atora-lms' ),
				$pending_evidence
			);
			if ( empty( $plan['next_action'] ) ) {
				$plan['next_action'] = sprintf(
					/* translators: %s: evidencia pendiente */
					__( 'Trabaja primero en la evidencia: %s.', 'atora-lms' ),
					$pending_evidence
				);
			}
		}

		$plan['strengths']       = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $plan['strengths'] ) ) ) );
		$plan['weaknesses']      = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $plan['weaknesses'] ) ) ) );
		$plan['recommendations'] = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $plan['recommendations'] ) ) ) );

		return $plan;
	}
}
