<?php
/**
 * CLMS_Grading_Engine — Motor de calificaciones unificado para ATORA LMS.
 *
 * Centraliza toda la lógica de grading: esquemas por curso, cálculo de nota
 * final ponderada, desglose para el estudiante, sistema de apelaciones y
 * recomendaciones AI de mejora.
 *
 * Delega la lectura de scores brutos al sistema existente
 * (CLMS_Assessment_Engine) para mantener compatibilidad total.
 *
 * @package CustomLMSCore
 * @since   4.22
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Grading_Engine {

	const SCHEME_META_KEY  = '_clms_grading_scheme';
	const CACHE_GROUP      = 'gradebook';
	const CACHE_TTL        = 300;
	const APPEAL_ID_PREFIX = 'appeal_';

	private $course_id;
	private $student_id;
	private $grading_scheme;

	public function __construct( $course_id = null, $student_id = null ) {
		$this->course_id  = $course_id ? absint( $course_id ) : null;
		$this->student_id = $student_id ? absint( $student_id ) : null;

		if ( $this->course_id ) {
			$this->grading_scheme = $this->load_grading_scheme( $this->course_id );
		}
	}

	// ── Esquema de calificación ───────────────────────────────────────────────

	/**
	 * Carga el esquema de calificación de un curso.
	 */
	public function load_grading_scheme( $course_id ) {
		$saved = get_post_meta( absint( $course_id ), self::SCHEME_META_KEY, true );
		if ( is_array( $saved ) && ! empty( $saved ) ) {
			return wp_parse_args( $saved, $this->get_default_scheme() );
		}
		return $this->get_default_scheme();
	}

	/**
	 * Guarda el esquema de calificación de un curso.
	 *
	 * @param int   $course_id ID del curso.
	 * @param array $scheme    Configuración del esquema.
	 * @return bool
	 */
	public function configure_grading_scheme( $course_id, $scheme ) {
		$course_id = absint( $course_id );
		if ( ! $course_id ) {
			return false;
		}

		$scheme = wp_parse_args( $scheme, $this->get_default_scheme() );

		if ( ! $this->validate_grading_scheme( $scheme ) ) {
			return false;
		}

		update_post_meta( $course_id, self::SCHEME_META_KEY, $scheme );

		$this->invalidate_all_course_caches( $course_id );

		do_action( 'clms_grading_scheme_updated', $course_id, $scheme );

		return true;
	}

	/**
	 * Esquema por defecto: 60% tareas + 40% quizzes.
	 */
	private function get_default_scheme() {
		return array(
			'components'          => array(
				'assignments' => array(
					'weight'              => 60,
					'drop_lowest'         => 0,
					'late_penalty_enabled' => false,
				),
				'quizzes'     => array(
					'weight'              => 40,
					'drop_lowest'         => 0,
					'late_penalty_enabled' => false,
				),
			),
			'grading_scale'       => $this->get_default_scale(),
			'late_policy'         => array(
				'enabled'            => false,
				'penalty_per_day'    => 10,
				'max_days_late'      => 7,
			),
			'extra_credit'        => array(
				'enabled'    => false,
				'max_points' => 5,
			),
			'curve_settings'      => array(
				'enabled' => false,
				'method'  => 'linear',
			),
			'display_preferences' => array(
				'show_numeric'      => true,
				'show_letter'       => true,
				'show_percentile'   => false,
				'show_distribution' => true,
				'show_breakdown'    => true,
			),
		);
	}

	private function get_default_scale() {
		return array(
			'A'  => array( 'min' => 90, 'max' => 100 ),
			'B'  => array( 'min' => 80, 'max' => 89.99 ),
			'C'  => array( 'min' => 70, 'max' => 79.99 ),
			'D'  => array( 'min' => 60, 'max' => 69.99 ),
			'F'  => array( 'min' => 0,  'max' => 59.99 ),
		);
	}

	/**
	 * Valida que los pesos de los componentes sumen 100.
	 */
	private function validate_grading_scheme( $scheme ) {
		if ( empty( $scheme['components'] ) || ! is_array( $scheme['components'] ) ) {
			return false;
		}

		$total_weight = 0;
		foreach ( $scheme['components'] as $config ) {
			if ( ! isset( $config['weight'] ) ) {
				return false;
			}
			$total_weight += (float) $config['weight'];
		}

		return abs( $total_weight - 100 ) < 0.01;
	}

	// ── Cálculo de nota final ─────────────────────────────────────────────────

	/**
	 * Calcula la nota final ponderada de un estudiante en un curso.
	 *
	 * @param int $student_id ID del estudiante.
	 * @param int $course_id  ID del curso.
	 * @return array Nota con desglose completo.
	 */
	public function calculate_final_grade( $student_id, $course_id ) {
		$student_id = absint( $student_id );
		$course_id  = absint( $course_id );

		$cached = $this->get_cached_grade( $student_id, $course_id );
		if ( $cached ) {
			return $cached;
		}

		$scheme     = $this->load_grading_scheme( $course_id );
		$components = array();
		$total_weight = 0;

		foreach ( $scheme['components'] as $component_name => $config ) {
			$raw_score = $this->calculate_component_score( $student_id, $course_id, $component_name, $config );
			$weight    = isset( $config['weight'] ) ? (float) $config['weight'] : 0;

			$components[ $component_name ] = array(
				'raw_score'      => round( $raw_score, 2 ),
				'weight'         => $weight,
				'weighted_score' => round( $raw_score * ( $weight / 100 ), 4 ),
				'items_graded'   => $this->get_graded_items_count( $student_id, $course_id, $component_name ),
				'items_total'    => $this->get_total_items_count( $course_id, $component_name ),
			);

			$total_weight += $weight;
		}

		$final_score = array_sum( array_column( $components, 'weighted_score' ) );

		if ( ! empty( $scheme['curve_settings']['enabled'] ) ) {
			$final_score = $this->apply_curve( $final_score, $scheme['curve_settings'] );
		}

		if ( ! empty( $scheme['extra_credit']['enabled'] ) ) {
			$extra = $this->calculate_extra_credit( $student_id, $course_id );
			$final_score = min( 100, $final_score + $extra );
		}

		$letter_grade = $this->convert_to_letter( $final_score, $scheme['grading_scale'] );

		$result = array(
			'student_id'        => $student_id,
			'course_id'         => $course_id,
			'numeric_score'     => round( $final_score, 2 ),
			'letter_grade'      => $letter_grade,
			'percentile'        => null,
			'components'        => $components,
			'total_weight_used' => $total_weight,
			'breakdown'         => $this->generate_grade_breakdown( $components, $scheme ),
			'calculated_at'     => current_time( 'mysql' ),
		);

		if ( ! empty( $scheme['display_preferences']['show_percentile'] ) ) {
			$result['percentile'] = $this->calculate_percentile( $student_id, $course_id, $final_score );
		}

		$this->cache_grade( $student_id, $course_id, $result );

		do_action( 'clms_final_grade_calculated', $result, $student_id, $course_id );

		return $result;
	}

	/**
	 * Calcula el score de un componente (quizzes, assignments, etc.).
	 */
	private function calculate_component_score( $student_id, $course_id, $component, $config ) {
		$scores = $this->get_raw_scores_for_component( $student_id, $course_id, $component );

		if ( empty( $scores ) ) {
			return 0;
		}

		if ( ! empty( $config['late_penalty_enabled'] ) ) {
			$scores = $this->apply_late_penalties( $scores, $student_id, $course_id );
		}

		if ( ! empty( $config['drop_lowest'] ) && (int) $config['drop_lowest'] > 0 ) {
			$scores = $this->drop_lowest_scores( $scores, (int) $config['drop_lowest'] );
		}

		if ( empty( $scores ) ) {
			return 0;
		}

		return array_sum( $scores ) / count( $scores );
	}

	/**
	 * Obtiene scores brutos del sistema existente (CLMS_Assessment_Engine).
	 */
	private function get_raw_scores_for_component( $student_id, $course_id, $component ) {
		$assessment_engine = class_exists( 'CLMS_Helper' )
			? clms_core('CLMS_Assessment_Engine')
			: null;

		if ( ! $assessment_engine || ! method_exists( $assessment_engine, 'build_course_gradebook' ) ) {
			return array();
		}

		$gradebook = $assessment_engine->build_course_gradebook( $student_id, $course_id );

		if ( empty( $gradebook['entries'] ) ) {
			return array();
		}

		$scores = array();

		foreach ( $gradebook['entries'] as $entry ) {
			if ( 'quizzes' === $component && isset( $entry['quiz_grade'] ) && null !== $entry['quiz_grade'] ) {
				$scores[] = (float) $entry['quiz_grade'];
			} elseif ( 'assignments' === $component && isset( $entry['assignment_grade'] ) && '' !== (string) $entry['assignment_grade'] ) {
				$scores[] = (float) $entry['assignment_grade'];
			}
		}

		return $scores;
	}

	/**
	 * Descarta los N scores más bajos de un array.
	 *
	 * @param array $scores      Array de scores.
	 * @param int   $drop_count  Número de scores a descartar.
	 * @return array
	 */
	public function drop_lowest_scores( $scores, $drop_count ) {
		if ( $drop_count <= 0 || count( $scores ) <= $drop_count ) {
			return $scores;
		}

		sort( $scores );
		return array_slice( $scores, $drop_count );
	}

	// ── Desglose y recomendaciones ────────────────────────────────────────────

	/**
	 * Genera el desglose de nota para la vista del estudiante.
	 */
	public function generate_grade_breakdown( $components, $scheme ) {
		$breakdown = array(
			'summary'         => array(),
			'components'      => array(),
			'recommendations' => array(),
		);

		$breakdown['summary'] = array(
			'components_count'     => count( $components ),
			'total_weight'         => array_sum( array_column( $components, 'weight' ) ),
			'completed_components' => count(
				array_filter( $components, function( $c ) {
					return $c['items_graded'] > 0;
				} )
			),
		);

		foreach ( $components as $name => $data ) {
			$completion = ( $data['items_total'] > 0 )
				? round( ( $data['items_graded'] / $data['items_total'] ) * 100, 1 )
				: 0;

			$breakdown['components'][ $name ] = array(
				'name'                  => ucwords( str_replace( '_', ' ', $name ) ),
				'score'                 => $data['raw_score'],
				'weight'                => $data['weight'],
				'contribution'          => $data['weighted_score'],
				'progress'              => $data['items_graded'] . '/' . $data['items_total'],
				'completion_percentage' => $completion,
			);
		}

		$breakdown['recommendations'] = $this->generate_grade_recommendations( $components );

		return $breakdown;
	}

	/**
	 * Genera recomendaciones priorizadas por impacto potencial en la nota.
	 */
	private function generate_grade_recommendations( $components ) {
		$weak = array_filter( $components, function( $c ) {
			return $c['raw_score'] < 70;
		} );

		if ( empty( $weak ) ) {
			return array(
				'message' => __( 'Excelente trabajo. Tu rendimiento es sólido en todos los componentes.', 'atora-lms' ),
				'actions' => array(),
			);
		}

		$recommendations = array();

		foreach ( $weak as $name => $data ) {
			$potential_impact = ( ( 100 - $data['raw_score'] ) * ( $data['weight'] / 100 ) );

			$recommendations[] = array(
				'component'        => $name,
				'label'            => ucwords( str_replace( '_', ' ', $name ) ),
				'current_score'    => $data['raw_score'],
				'potential_impact' => round( $potential_impact, 1 ),
				'actions'          => $this->suggest_improvement_actions( $name, $data ),
			);
		}

		usort( $recommendations, function( $a, $b ) {
			return $b['potential_impact'] <=> $a['potential_impact'];
		} );

		$top = $recommendations[0];

		return array(
			'message'          => sprintf(
				/* translators: 1: component label, 2: potential grade increase */
				__( 'Enfócate en mejorar %1$s — podría subir tu nota final hasta %.1f puntos.', 'atora-lms' ),
				$top['label'],
				$top['potential_impact']
			),
			'priority_actions' => array_slice( $recommendations, 0, 3 ),
		);
	}

	/**
	 * Sugiere acciones concretas para mejorar un componente.
	 */
	private function suggest_improvement_actions( $component_name, $data ) {
		$actions = array();

		if ( $data['items_graded'] < $data['items_total'] ) {
			$pending = $data['items_total'] - $data['items_graded'];
			$actions[] = array(
				'type'  => 'complete_pending',
				'title' => sprintf(
					/* translators: %d: number of pending items */
					_n( 'Completar %d entrega pendiente', 'Completar %d entregas pendientes', $pending, 'atora-lms' ),
					$pending
				),
			);
		}

		if ( $data['raw_score'] < 70 ) {
			$actions[] = array(
				'type'  => 'review_materials',
				'title' => sprintf(
					/* translators: %s: component name */
					__( 'Revisar el material de %s', 'atora-lms' ),
					ucwords( str_replace( '_', ' ', $component_name ) )
				),
			);
		}

		$actions[] = array(
			'type'  => 'ai_practice',
			'title' => __( 'Practicar con ejercicios generados por IA', 'atora-lms' ),
		);

		return $actions;
	}

	// ── Conversión y escala ───────────────────────────────────────────────────

	/**
	 * Convierte un score numérico a letra según la escala del esquema.
	 */
	private function convert_to_letter( $score, $scale ) {
		foreach ( $scale as $letter => $range ) {
			if ( $score >= $range['min'] && $score <= $range['max'] ) {
				return $letter;
			}
		}
		return 'F';
	}

	private function apply_curve( $score, $curve_settings ) {
		if ( 'sqrt' === $curve_settings['method'] ) {
			return min( 100, round( sqrt( $score / 100 ) * 100, 2 ) );
		}
		// linear: sin transformación adicional por defecto
		return $score;
	}

	// ── Estadísticas del curso ────────────────────────────────────────────────

	private function calculate_percentile( $student_id, $course_id, $final_score ) {
		$all_grades = $this->get_all_course_numeric_grades( $course_id );
		if ( empty( $all_grades ) ) {
			return null;
		}
		$below = count( array_filter( $all_grades, function( $g ) use ( $final_score ) {
			return $g < $final_score;
		} ) );
		return round( ( $below / count( $all_grades ) ) * 100 );
	}

	private function get_all_course_numeric_grades( $course_id ) {
		$course_id = absint( $course_id );
		$enrollment_module = class_exists( 'CLMS_Helper' )
			? clms_core('CLMS_Enrollment_Manager')
			: null;

		if ( ! $enrollment_module || ! method_exists( $enrollment_module, 'get_enrolled_students' ) ) {
			return array();
		}

		$students = $enrollment_module->get_enrolled_students( $course_id );
		if ( empty( $students ) ) {
			return array();
		}

		$grades = array();
		foreach ( $students as $student ) {
			$uid = is_array( $student ) ? (int) $student['user_id'] : (int) $student;
			if ( ! $uid ) {
				continue;
			}
			$result = $this->calculate_final_grade( $uid, $course_id );
			if ( isset( $result['numeric_score'] ) ) {
				$grades[] = (float) $result['numeric_score'];
			}
		}

		return $grades;
	}

	/**
	 * Analiza la consistencia de calificaciones de un curso.
	 */
	public function analyze_grading_consistency( $course_id ) {
		$grades = $this->get_all_course_numeric_grades( $course_id );

		if ( empty( $grades ) ) {
			return array( 'error' => 'No hay calificaciones suficientes para analizar.' );
		}

		sort( $grades );
		$count = count( $grades );
		$mean  = array_sum( $grades ) / $count;

		$variance     = array_sum( array_map( fn( $g ) => pow( $g - $mean, 2 ), $grades ) ) / $count;
		$std_deviation = sqrt( $variance );
		$median       = ( $count % 2 === 0 )
			? ( $grades[ $count / 2 - 1 ] + $grades[ $count / 2 ] ) / 2
			: $grades[ (int) ( $count / 2 ) ];

		return array(
			'count'                 => $count,
			'mean'                  => round( $mean, 2 ),
			'median'                => round( $median, 2 ),
			'std_deviation'         => round( $std_deviation, 2 ),
			'min'                   => min( $grades ),
			'max'                   => max( $grades ),
			'grade_inflation_index' => $mean > 85 ? 'high' : ( $mean > 75 ? 'moderate' : 'low' ),
		);
	}

	// ── Sistema de apelaciones ────────────────────────────────────────────────

	/**
	 * Registra una apelación de calificación del estudiante.
	 *
	 * @param int    $student_id    ID del estudiante.
	 * @param int    $submission_id ID de la entrega.
	 * @param string $component     Componente ('assignment', 'quiz').
	 * @param string $reason        Motivo de la apelación.
	 * @return array|false
	 */
	public function submit_grade_appeal( $student_id, $submission_id, $component, $reason ) {
		global $wpdb;

		$student_id    = absint( $student_id );
		$submission_id = absint( $submission_id );

		if ( ! $student_id || ! $submission_id || empty( $reason ) ) {
			return false;
		}

		// PT-8 (6.5.5): submit_grade_appeal() no verificaba que la
		// entrega perteneciera al alumno que apela — cualquier usuario
		// autenticado podía leer (y "apelar") la calificación de otro
		// alumno pasando cualquier submission_id, porque la respuesta
		// del endpoint devuelve original_grade/course_id de la entrega.
		// La entrega usa post_author como dueño (mismo criterio que
		// Assessment_Engine).
		if ( absint( get_post_field( 'post_author', $submission_id ) ) !== $student_id
			&& ! current_user_can( 'manage_options' )
			&& ! current_user_can( 'edit_others_lm_courses' )
		) {
			return false;
		}

		$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );

		$appeal_id = self::APPEAL_ID_PREFIX . wp_generate_uuid4();

		$original_grade = 'quiz' === $component
			? get_post_meta( $submission_id, '_clms_quiz_score', true )
			: get_post_meta( $submission_id, '_clms_submission_grade', true );

		$appeal = array(
			'appeal_id'      => $appeal_id,
			'student_id'     => $student_id,
			'course_id'      => $course_id,
			'submission_id'  => $submission_id,
			'component'      => sanitize_key( $component ),
			'original_grade' => '' !== (string) $original_grade ? (float) $original_grade : null,
			'reason'         => sanitize_textarea_field( $reason ),
			'status'         => 'pending',
			'submitted_at'   => current_time( 'mysql' ),
		);

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'clms_grade_appeals',
			$appeal,
			array( '%s', '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		$appeal['id'] = $wpdb->insert_id;

		do_action( 'clms_grade_appeal_submitted', $appeal );

		return $appeal;
	}

	/**
	 * Procesa una apelación (acción del instructor).
	 *
	 * @param string     $appeal_id  ID único de la apelación.
	 * @param string     $decision   'approved' | 'rejected' | 'needs_review'.
	 * @param float|null $new_grade  Nueva calificación si se aprueba.
	 * @param string     $notes      Notas del revisor.
	 * @return bool
	 */
	public function process_appeal( $appeal_id, $decision, $new_grade = null, $notes = '' ) {
		global $wpdb;

		$appeal = $this->get_appeal( $appeal_id );

		if ( ! $appeal || 'pending' !== $appeal['status'] ) {
			return false;
		}

		// PT-9 (6.5.5): process_appeal() solo estaba gateado por
		// can_manage_content() — una capability genérica, no específica
		// del curso — así que cualquier instructor podía aprobar/
		// rechazar apelaciones de cursos ajenos y modificar la nota de
		// alumnos que no le pertenecen. Mismo criterio jerárquico que
		// LMS_REST_Controller::can_manage_this_course(): admin →
		// edit_others_lm_courses → dueño (post_author) del curso.
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_others_lm_courses' ) ) {
			$owner_id = absint( get_post_field( 'post_author', absint( $appeal['course_id'] ?? 0 ) ) );
			if ( ! $owner_id || $owner_id !== get_current_user_id() ) {
				return false;
			}
		}

		$update = array(
			'status'         => sanitize_key( $decision ),
			'reviewed_grade' => $new_grade !== null ? (float) $new_grade : null,
			'reviewer_notes' => sanitize_textarea_field( $notes ),
			'reviewed_by'    => get_current_user_id(),
			'reviewed_at'    => current_time( 'mysql' ),
		);

		$wpdb->update(
			$wpdb->prefix . 'clms_grade_appeals',
			$update,
			array( 'appeal_id' => $appeal_id )
		);

		if ( 'approved' === $decision && null !== $new_grade ) {
			$meta_key = 'quiz' === $appeal['component']
				? '_clms_quiz_score'
				: '_clms_submission_grade';

			update_post_meta( $appeal['submission_id'], $meta_key, (float) $new_grade );

			$this->invalidate_all_course_caches( $appeal['course_id'] );

			do_action( 'clms_grade_appeal_approved', $appeal, $new_grade );
		}

		do_action( 'clms_grade_appeal_processed', $appeal_id, $decision );

		return true;
	}

	/**
	 * Obtiene una apelación por su ID único.
	 */
	public function get_appeal( $appeal_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}clms_grade_appeals WHERE appeal_id = %s LIMIT 1",
				$appeal_id
			),
			ARRAY_A
		);
	}

	/**
	 * Obtiene todas las apelaciones de un curso con filtros opcionales.
	 */
	public function get_course_appeals( $course_id, $status = null ) {
		global $wpdb;

		$table = $wpdb->prefix . 'clms_grade_appeals';

		if ( $status ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE course_id = %d AND status = %s ORDER BY submitted_at DESC",
					absint( $course_id ),
					sanitize_key( $status )
				),
				ARRAY_A
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE course_id = %d ORDER BY submitted_at DESC",
				absint( $course_id )
			),
			ARRAY_A
		);
	}

	// ── Conteos de ítems ──────────────────────────────────────────────────────

	private function get_graded_items_count( $student_id, $course_id, $component ) {
		$scores = $this->get_raw_scores_for_component( $student_id, $course_id, $component );
		return count( $scores );
	}

	private function get_total_items_count( $course_id, $component ) {
		$lesson_ids = class_exists( 'CLMS_Helper' )
			? CLMS_Helper::get_course_lessons( $course_id )
			: array();

		if ( empty( $lesson_ids ) ) {
			return 0;
		}

		if ( 'quizzes' === $component ) {
			$count = 0;
			foreach ( $lesson_ids as $lesson_id ) {
				if ( get_post_meta( absint( $lesson_id ), '_clms_quiz_enabled', true ) ) {
					$count++;
				}
			}
			return $count;
		}

		if ( 'assignments' === $component ) {
			$count = 0;
			foreach ( $lesson_ids as $lesson_id ) {
				if ( get_post_meta( absint( $lesson_id ), '_clms_submission_required', true ) ) {
					$count++;
				}
			}
			return $count;
		}

		return count( $lesson_ids );
	}

	// ── Crédito extra y penalización tardía ───────────────────────────────────

	private function calculate_extra_credit( $student_id, $course_id ) {
		return (float) get_user_meta(
			absint( $student_id ),
			'_clms_extra_credit_' . absint( $course_id ),
			true
		);
	}

	private function apply_late_penalties( $scores, $student_id, $course_id ) {
		// Placeholder: la penalización real requiere timestamps por entrega.
		// Se delega a futuras implementaciones sin romper el flujo actual.
		return $scores;
	}

	// ── Caché ─────────────────────────────────────────────────────────────────

	private function get_cached_grade( $student_id, $course_id ) {
		if ( class_exists( 'CLMS_Cache' ) ) {
			return CLMS_Cache::get( self::CACHE_GROUP, array( 'engine_grade', $student_id, $course_id ), false );
		}
		$value = get_transient( $this->grade_cache_key( $student_id, $course_id ) );
		return ( false !== $value ) ? $value : false;
	}

	private function cache_grade( $student_id, $course_id, $result ) {
		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::set( self::CACHE_GROUP, array( 'engine_grade', $student_id, $course_id ), $result, self::CACHE_TTL );
		} else {
			set_transient( $this->grade_cache_key( $student_id, $course_id ), $result, self::CACHE_TTL );
		}
	}

	public function invalidate_grade_cache( $student_id, $course_id ) {
		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::delete( self::CACHE_GROUP, array( 'engine_grade', $student_id, $course_id ) );
		} else {
			delete_transient( $this->grade_cache_key( $student_id, $course_id ) );
		}
	}

	private function invalidate_all_course_caches( $course_id ) {
		do_action( 'clms_invalidate_course_grade_caches', $course_id );
	}

	private function grade_cache_key( $student_id, $course_id ) {
		return 'clms_engine_grade_' . absint( $student_id ) . '_' . absint( $course_id );
	}
}
