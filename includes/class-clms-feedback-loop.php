<?php
/**
 * CLMS_Feedback_Loop — Transforma el feedback de calificación en un plan de
 * acción personalizado para el estudiante.
 *
 * Se engancha a clms_submission_graded y:
 *   1. Identifica gaps de aprendizaje en los criterios de la rúbrica.
 *   2. Prioriza los gaps por impacto potencial en la nota.
 *   3. Genera un plan de acción con recursos, práctica y re-evaluaciones.
 *   4. Registra el tracking en wp_clms_progress_tracking.
 *
 * @package CustomLMSCore
 * @since   4.22
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Feedback_Loop {

	const TRACKING_ID_PREFIX = 'track_';
	const GAP_THRESHOLD      = 70;
	const MAJOR_GAP_SIZE     = 50;

	private $course_id;
	/**
	 * Cache interno de existencia de tabla de tracking.
	 *
	 * @var bool|null
	 */
	private $progress_tracking_table_available = null;

	public function __construct() {
		add_action( 'clms_submission_graded', array( $this, 'handle_submission_graded' ), 20, 5 );
	}

	// ── Entry point desde el hook ─────────────────────────────────────────────

	/**
	 * Responde al hook clms_submission_graded.
	 * Firma: (submission_id, student_id, status, grade, feedback)
	 */
	public function handle_submission_graded( $submission_id, $student_id_from_hook, $status, $grade, $feedback ) {
		$submission_id = absint( $submission_id );

		if ( ! $submission_id ) {
			return;
		}

		if ( in_array( $status, array( 'in_review', 'pending' ), true ) ) {
			return;
		}

		$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		if ( ! $student_id ) {
			$student_id = absint( get_post_meta( $submission_id, '_clms_submission_student_id', true ) );
		}
		if ( ! $student_id ) {
			$student_id = absint( $student_id_from_hook );
		}
		$lesson_id  = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$course_id  = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		if ( ! $course_id && $lesson_id && class_exists( 'CLMS_Helper' ) ) {
			$course_id = absint( CLMS_Helper::get_lesson_course_id( $lesson_id ) );
		}

		if ( ! $student_id || ! $course_id ) {
			return;
		}

		$grading_result = array(
			'submission_id' => $submission_id,
			'grade'         => $grade,
			'status'        => $status,
			'feedback'      => $feedback,
			'rubric_scores' => $this->get_rubric_scores_from_submission( $submission_id ),
			'ai_feedback'   => $this->get_ai_feedback_from_submission( $submission_id ),
		);

		$this->process_feedback( $grading_result, $student_id, $course_id );
	}

	// ── Proceso principal ─────────────────────────────────────────────────────

	/**
	 * Procesa el feedback de una calificación y genera el plan de acción completo.
	 *
	 * @param array $grading_result Resultado de la calificación.
	 * @param int   $student_id     ID del estudiante.
	 * @param int   $course_id      ID del curso.
	 * @return array Plan de acción con gaps, recursos, práctica, re-evaluaciones y tracking_id.
	 */
	public function process_feedback( $grading_result, $student_id, $course_id ) {
		$grading_result = is_array( $grading_result ) ? $grading_result : array();
		$student_id     = absint( $student_id );
		$course_id      = absint( $course_id );

		$submission_id = isset( $grading_result['submission_id'] ) ? absint( $grading_result['submission_id'] ) : 0;
		$grade         = isset( $grading_result['grade'] ) && '' !== (string) $grading_result['grade'] && is_numeric( $grading_result['grade'] )
			? (float) $grading_result['grade']
			: null;
		$status        = isset( $grading_result['status'] ) ? sanitize_key( (string) $grading_result['status'] ) : '';
		$feedback      = isset( $grading_result['feedback'] ) ? sanitize_textarea_field( (string) $grading_result['feedback'] ) : '';

		$this->course_id = absint( $course_id );

		$gaps             = $this->identify_learning_gaps( $grading_result );
		$prioritized_gaps = $this->prioritize_gaps( $gaps, $student_id );

		if ( empty( $prioritized_gaps ) ) {
			$result = array(
				'submission_id' => $submission_id,
				'student_id'    => $student_id,
				'course_id'     => $course_id,
				'grade'         => $grade,
				'status'        => $status,
				'feedback'      => $feedback,
				'gaps'          => array(),
				'action_plan'   => null,
				'resources'     => array(),
				'practice'      => array(),
				'reassessments' => array(),
				'tracking_id'   => null,
				'message'       => __( 'Sin gaps detectados. ¡Excelente trabajo!', 'atora-lms' ),
			);

			do_action( 'clms_feedback_loop_processed', $result, $student_id, $course_id );

			return $result;
		}

		$action_plan    = $this->generate_action_plan( $prioritized_gaps, $student_id );
		$resources      = $this->curate_resources( $prioritized_gaps, $student_id );
		$practice       = $this->generate_practice( $prioritized_gaps, $student_id );
		$reassessments  = $this->create_reassessment_opportunities( $prioritized_gaps );
		$tracking       = $this->setup_progress_tracking( $student_id, $prioritized_gaps );

		$result = array(
			'submission_id' => $submission_id,
			'student_id'    => $student_id,
			'course_id'     => $course_id,
			'grade'         => $grade,
			'status'        => $status,
			'feedback'      => $feedback,
			'gaps'          => $prioritized_gaps,
			'action_plan'   => $action_plan,
			'resources'     => $resources,
			'practice'      => $practice,
			'reassessments' => $reassessments,
			'tracking_id'   => $tracking['id'],
		);

		do_action( 'clms_feedback_loop_processed', $result, $student_id, $course_id );

		return $result;
	}

	// ── Identificación y priorización de gaps ─────────────────────────────────

	/**
	 * Identifica gaps de aprendizaje desde el resultado de calificación.
	 */
	private function identify_learning_gaps( $grading_result ) {
		$gaps = array();

		if ( ! empty( $grading_result['rubric_scores'] ) && is_array( $grading_result['rubric_scores'] ) ) {
			foreach ( $grading_result['rubric_scores'] as $criterion => $score ) {
				$pct = isset( $score['percentage'] ) ? (float) $score['percentage'] : 0;

				if ( $pct < self::GAP_THRESHOLD ) {
					$gaps[] = array(
						'type'          => 'rubric_criterion',
						'area'          => sanitize_key( $criterion ),
						'label'         => isset( $score['label'] ) ? (string) $score['label'] : ucwords( str_replace( '_', ' ', $criterion ) ),
						'current_level' => $pct,
						'target_level'  => 100,
						'gap_size'      => 100 - $pct,
						'evidence'      => isset( $score['feedback'] ) ? (string) $score['feedback'] : '',
					);
				}
			}
		}

		if ( isset( $grading_result['grade'] ) && '' !== (string) $grading_result['grade'] && is_numeric( $grading_result['grade'] ) ) {
			$grade = (float) $grading_result['grade'];
			if ( $grade < self::GAP_THRESHOLD && empty( $gaps ) ) {
				$gaps[] = array(
					'type'          => 'overall_grade',
					'area'          => 'overall_performance',
					'label'         => __( 'Rendimiento general', 'atora-lms' ),
					'current_level' => $grade,
					'target_level'  => 100,
					'gap_size'      => 100 - $grade,
					'evidence'      => is_string( $grading_result['feedback'] ) ? $grading_result['feedback'] : '',
				);
			}
		}

		return $gaps;
	}

	/**
	 * Prioriza gaps por tamaño (mayor gap = mayor prioridad).
	 */
	private function prioritize_gaps( $gaps, $student_id ) {
		if ( empty( $gaps ) ) {
			return array();
		}

		usort( $gaps, function( $a, $b ) {
			return $b['gap_size'] <=> $a['gap_size'];
		} );

		return array_slice( $gaps, 0, 5 );
	}

	// ── Plan de acción ────────────────────────────────────────────────────────

	/**
	 * Genera un plan de acción paso a paso para cerrar los gaps.
	 */
	private function generate_action_plan( $gaps, $student_id ) {
		$plan = array(
			'overview'   => $this->ai_generate_plan_overview( $gaps, $student_id ),
			'steps'      => array(),
			'milestones' => array(),
		);

		foreach ( $gaps as $index => $gap ) {
			$plan['steps'][] = array(
				'step_number'      => $index + 1,
				'goal'             => sprintf(
					/* translators: %s: gap area label */
					__( 'Mejorar en: %s', 'atora-lms' ),
					$gap['label']
				),
				'gap_area'         => $gap['area'],
				'current_score'    => $gap['current_level'],
				'target_score'     => $gap['target_level'],
				'estimated_time'   => $this->estimate_time_to_close( $gap ),
				'success_criteria' => sprintf(
					/* translators: %s: gap area label */
					__( 'Alcanzar al menos 70%% en %s', 'atora-lms' ),
					$gap['label']
				),
			);
		}

		$plan['milestones'] = $this->create_milestones( $plan['steps'] );

		return $plan;
	}

	/**
	 * Genera el resumen introductorio del plan usando IA.
	 */
	private function ai_generate_plan_overview( $gaps, $student_id ) {
		$ai = $this->get_ai_manager();

		if ( ! $ai || is_wp_error( $ai ) || ! method_exists( $ai, 'chat' ) ) {
			return $this->fallback_plan_overview( $gaps );
		}

		$gap_list = implode( ', ', array_column( $gaps, 'label' ) );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => sprintf(
					'Eres un tutor educativo motivador. El estudiante tiene gaps en: %s. Escribe un párrafo breve (máx. 3 oraciones) que lo motive a mejorar, sin ser condescendiente. En español.',
					$gap_list
				),
			),
		);

		$response = $ai->chat( $messages, array( 'max_tokens' => 150, 'temperature' => 0.7 ) );

		return is_wp_error( $response ) ? $this->fallback_plan_overview( $gaps ) : trim( $response );
	}

	private function fallback_plan_overview( $gaps ) {
		$count = count( $gaps );
		return sprintf(
			/* translators: %d: number of areas to improve */
			_n(
				'Hemos identificado %d área de mejora. Con práctica enfocada puedes alcanzar tus objetivos.',
				'Hemos identificado %d áreas de mejora. Con práctica enfocada puedes alcanzar tus objetivos.',
				$count,
				'atora-lms'
			),
			$count
		);
	}

	private function create_milestones( $steps ) {
		$milestones = array();
		$mid        = (int) ceil( count( $steps ) / 2 );

		if ( isset( $steps[ $mid - 1 ] ) ) {
			$milestones[] = array(
				'after_step'  => $mid,
				'label'       => __( 'Punto de revisión intermedio', 'atora-lms' ),
				'action'      => __( 'Revisa tu progreso y ajusta el plan si es necesario.', 'atora-lms' ),
			);
		}

		$milestones[] = array(
			'after_step' => count( $steps ),
			'label'      => __( 'Revisión final', 'atora-lms' ),
			'action'     => __( 'Completa las re-evaluaciones disponibles para confirmar tu mejora.', 'atora-lms' ),
		);

		return $milestones;
	}

	private function estimate_time_to_close( $gap ) {
		$hours = (int) ceil( $gap['gap_size'] / 10 );
		return sprintf(
			/* translators: %d: estimated hours */
			_n( 'Aprox. %d hora de estudio', 'Aprox. %d horas de estudio', $hours, 'atora-lms' ),
			$hours
		);
	}

	// ── Recursos ──────────────────────────────────────────────────────────────

	/**
	 * Recupera y sugiere recursos para cada gap.
	 */
	private function curate_resources( $gaps, $student_id ) {
		$resources = array();

		foreach ( $gaps as $gap ) {
			$internal = $this->find_internal_resources( $this->course_id, $gap['area'] );
			$external = $this->ai_suggest_external_resources( $gap, $student_id );

			$resources[ $gap['area'] ] = array(
				'gap_label'          => $gap['label'],
				'internal_resources' => $internal,
				'external_resources' => $external,
				'study_time_estimate' => $this->estimate_time_to_close( $gap ),
			);
		}

		return $resources;
	}

	/**
	 * Busca recursos internos del curso en wp_clms_learning_resources.
	 */
	private function find_internal_resources( $course_id, $gap_area ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'clms_learning_resources';
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, resource_type, url, estimated_time, difficulty_level
				 FROM {$table}
				 WHERE course_id = %d AND gap_area = %s
				 ORDER BY difficulty_level ASC
				 LIMIT 5",
				absint( $course_id ),
				sanitize_key( $gap_area )
			),
			ARRAY_A
		);
	}

	/**
	 * Sugiere recursos externos via IA (títulos y descripciones).
	 */
	private function ai_suggest_external_resources( $gap, $student_id ) {
		$ai = $this->get_ai_manager();

		if ( ! $ai || is_wp_error( $ai ) || ! method_exists( $ai, 'chat' ) ) {
			return array();
		}

		$messages = array(
			array(
				'role'    => 'user',
				'content' => sprintf(
					'Sugiere 3 recursos de estudio gratuitos (con título y URL real) para mejorar en: "%s". Responde en JSON con el formato: [{"title":"...","url":"...","type":"video|article|exercise"}]. Solo JSON, sin explicaciones.',
					$gap['label']
				),
			),
		);

		$response = $ai->chat( $messages, array( 'max_tokens' => 400, 'temperature' => 0.3 ) );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$parsed = json_decode( trim( $response ), true );
		return is_array( $parsed ) ? $parsed : array();
	}

	// ── Práctica adaptativa ───────────────────────────────────────────────────

	/**
	 * Genera sets de práctica adaptativa para cada gap.
	 */
	private function generate_practice( $gaps, $student_id ) {
		$practice_sets = array();

		foreach ( $gaps as $gap ) {
			$difficulty = $this->determine_starting_difficulty( $gap );

			$practice_sets[ $gap['area'] ] = array(
				'gap_label'      => $gap['label'],
				'introduction'   => $this->ai_generate_practice_intro( $gap ),
				'items'          => $this->ai_generate_practice_items( $gap, $difficulty ),
				'adaptive_logic' => array(
					'initial_difficulty' => $difficulty,
					'progression_rule'   => 'mastery_based',
					'success_threshold'  => 0.8,
				),
				'feedback_mode'    => 'immediate',
				'hints_available'  => true,
				'unlimited_attempts' => true,
			);
		}

		return $practice_sets;
	}

	private function determine_starting_difficulty( $gap ) {
		if ( $gap['current_level'] < 40 ) {
			return 'beginner';
		}
		if ( $gap['current_level'] < 65 ) {
			return 'intermediate';
		}
		return 'advanced';
	}

	private function ai_generate_practice_intro( $gap ) {
		return sprintf(
			/* translators: %s: gap area label */
			__( 'Practica los conceptos clave de %s con estos ejercicios adaptativos.', 'atora-lms' ),
			$gap['label']
		);
	}

	/**
	 * Genera ítems de práctica usando IA.
	 */
	public function ai_generate_practice_items( $gap, $difficulty ) {
		$ai = $this->get_ai_manager();

		if ( ! $ai || is_wp_error( $ai ) || ! method_exists( $ai, 'chat' ) ) {
			return array();
		}

		$messages = array(
			array(
				'role'    => 'user',
				'content' => sprintf(
					'Crea 3 preguntas de práctica de nivel %s sobre el tema: "%s". Responde en JSON con el formato: [{"question":"...","type":"multiple_choice|open","options":["a)...","b)...","c)...","d)..."],"correct_index":0,"hint":"...","explanation":"..."}]. Solo JSON.',
					$difficulty,
					$gap['label']
				),
			),
		);

		$response = $ai->chat( $messages, array( 'max_tokens' => 1000, 'temperature' => 0.7 ) );

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$parsed = json_decode( trim( $response ), true );
		return is_array( $parsed ) ? $parsed : array();
	}

	// ── Re-evaluaciones ───────────────────────────────────────────────────────

	/**
	 * Crea oportunidades de re-evaluación para gaps significativos (> 50 puntos).
	 */
	private function create_reassessment_opportunities( $gaps ) {
		$reassessments = array();

		foreach ( $gaps as $gap ) {
			if ( $gap['gap_size'] <= self::MAJOR_GAP_SIZE ) {
				continue;
			}

			$reassessments[] = array(
				'gap_area'         => $gap['area'],
				'gap_label'        => $gap['label'],
				'type'             => 'mini_assessment',
				'available_after'  => date( 'Y-m-d H:i:s', strtotime( '+24 hours' ) ),
				'max_attempts'     => 3,
				'questions_count'  => $this->determine_question_count( $gap ),
				'passing_score'    => 80,
				'weight_in_grade'  => 0.5,
				'expires_at'       => date( 'Y-m-d H:i:s', strtotime( '+14 days' ) ),
			);
		}

		return $reassessments;
	}

	private function determine_question_count( $gap ) {
		if ( $gap['gap_size'] > 70 ) {
			return 10;
		}
		if ( $gap['gap_size'] > 50 ) {
			return 7;
		}
		return 5;
	}

	// ── Progress Tracking ─────────────────────────────────────────────────────

	/**
	 * Registra un registro de tracking de progreso en la DB.
	 */
	private function setup_progress_tracking( $student_id, $gaps ) {
		global $wpdb;

		if ( ! $this->has_progress_tracking_table() ) {
			return array(
				'id'          => '',
				'db_id'       => 0,
				'checkpoints' => array(),
			);
		}

		$tracking_id = self::TRACKING_ID_PREFIX . wp_generate_uuid4();

		$checkpoints = array();
		foreach ( $gaps as $index => $gap ) {
			$checkpoints[] = array(
				'index'        => $index + 1,
				'gap_area'     => $gap['area'],
				'target_score' => $gap['target_level'],
				'reached'      => false,
			);
		}

		$tracking = array(
			'tracking_id'  => $tracking_id,
			'student_id'   => absint( $student_id ),
			'course_id'    => $this->course_id,
			'gaps_tracked' => wp_json_encode( $gaps ),
			'start_date'   => current_time( 'mysql' ),
			'checkpoints'  => wp_json_encode( $checkpoints ),
			'metrics'      => wp_json_encode( array(
				'resources_accessed'     => 0,
				'practice_completed'     => 0,
				'reassessments_taken'    => 0,
				'improvement_percentage' => 0,
			) ),
			'last_updated' => current_time( 'mysql' ),
		);

		$wpdb->insert(
			$wpdb->prefix . 'clms_progress_tracking',
			$tracking
		);

		return array(
			'id'          => $tracking_id,
			'db_id'       => $wpdb->insert_id,
			'checkpoints' => $checkpoints,
		);
	}

	/**
	 * Actualiza el progreso del estudiante en un tracking activo.
	 *
	 * @param string $tracking_id  ID único del tracking.
	 * @param string $event_type   'resource_accessed' | 'practice_completed' | 'reassessment_taken'.
	 * @param array  $event_data   Datos del evento.
	 * @return array|false
	 */
	public function update_progress( $tracking_id, $event_type, $event_data = array() ) {
		global $wpdb;
		if ( ! $this->has_progress_tracking_table() ) {
			return false;
		}

		$record = $this->get_tracking_record( $tracking_id );
		if ( ! $record ) {
			return false;
		}

		$metrics = json_decode( $record['metrics'], true );
		if ( ! is_array( $metrics ) ) {
			$metrics = array( 'resources_accessed' => 0, 'practice_completed' => 0, 'reassessments_taken' => 0, 'improvement_percentage' => 0 );
		}

		switch ( $event_type ) {
			case 'resource_accessed':
				$metrics['resources_accessed']++;
				break;

			case 'practice_completed':
				$metrics['practice_completed']++;
				break;

			case 'reassessment_taken':
				$metrics['reassessments_taken']++;
				if ( isset( $event_data['original_score'], $event_data['new_score'] ) ) {
					$orig       = (float) $event_data['original_score'];
					$new        = (float) $event_data['new_score'];
					$range      = 100 - $orig;
					$improvement = $range > 0 ? round( ( ( $new - $orig ) / $range ) * 100, 1 ) : 0;
					$metrics['improvement_percentage'] = max( $metrics['improvement_percentage'], $improvement );
				}
				break;
		}

		$wpdb->update(
			$wpdb->prefix . 'clms_progress_tracking',
			array(
				'metrics'      => wp_json_encode( $metrics ),
				'last_updated' => current_time( 'mysql' ),
			),
			array( 'tracking_id' => $tracking_id )
		);

		$updated = $this->get_tracking_record( $tracking_id );
		do_action( 'clms_feedback_progress_updated', $tracking_id, $event_type, $metrics );

		return $updated;
	}

	/**
	 * Obtiene un registro de tracking por su ID único.
	 */
	public function get_tracking_record( $tracking_id ) {
		global $wpdb;
		if ( ! $this->has_progress_tracking_table() ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}clms_progress_tracking WHERE tracking_id = %s LIMIT 1",
				$tracking_id
			),
			ARRAY_A
		);
	}

	/**
	 * Obtiene todos los trackings activos de un estudiante en un curso.
	 */
	public function get_student_trackings( $student_id, $course_id ) {
		global $wpdb;
		if ( ! $this->has_progress_tracking_table() ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}clms_progress_tracking
				 WHERE student_id = %d AND course_id = %d
				 ORDER BY start_date DESC",
				absint( $student_id ),
				absint( $course_id )
			),
			ARRAY_A
		);
	}

	// ── Helpers internos ──────────────────────────────────────────────────────

	private function get_rubric_scores_from_submission( $submission_id ) {
		$submission_id = absint( $submission_id );
		$scores = get_post_meta( $submission_id, '_clms_submission_rubric_scores', true );
		if ( ! is_array( $scores ) ) {
			$scores = get_post_meta( $submission_id, '_clms_rubric_scores', true );
		}
		return is_array( $scores ) ? $scores : array();
	}

	private function get_ai_feedback_from_submission( $submission_id ) {
		return (string) get_post_meta( absint( $submission_id ), '_clms_submission_feedback', true );
	}

	private function get_ai_manager() {
		return class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
	}

	/**
	 * Verifica existencia de tabla de tracking para evitar errores SQL en entornos parciales.
	 *
	 * @return bool
	 */
	private function has_progress_tracking_table() {
		global $wpdb;

		if ( null !== $this->progress_tracking_table_available ) {
			return (bool) $this->progress_tracking_table_available;
		}

		$table = $wpdb->prefix . 'clms_progress_tracking';
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		$this->progress_tracking_table_available = ( $exists === $table );
		return (bool) $this->progress_tracking_table_available;
	}
}
