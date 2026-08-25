<?php
/**
 * Insignias individuales de estudiante por curso (PT-2, sprint 6.10.0).
 *
 * Reemplaza al leaderboard competitivo retirado en PT-1: cinco
 * niveles NO comparativos (un estudiante nunca se compara contra
 * otro, cada uno se evalúa contra su propio desempeño) según tres
 * ejes reales — puntualidad, promedio, interacción — pedidos
 * explícitamente por decisión de producto.
 *
 * No duplica ningún cálculo ya existente: promedio e interacción
 * salen de CLMS_Academic_Status_Service::get_student_course_status()
 * (que el panel de estudiante ya calcula por curso), puntualidad de
 * su método nuevo get_on_time_rate_for_student() (mismo sprint,
 * mismo criterio que ya usa el reporte académico) — este servicio
 * solo combina y clasifica.
 *
 * @package ATORA_LMS
 * @since   6.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Student_Badge_Service {

	public function __construct() {
		// PT-2 (6.10.0): único punto de integración con el panel de
		// estudiante -- un filtro estándar de WordPress sobre el hook
		// modular que includes/dashboard/trait-dashboard-data.php ya
		// expone (get_course_cards()), sin tocar esa función más allá
		// del campo aditivo 'course_id' agregado en el mismo sprint.
		add_filter( 'clms_modularity_dashboard_course_cards', array( $this, 'attach_badges_to_course_cards' ), 10, 4 );
	}

	/**
	 * @param array $items             Cards de curso ya construidas (get_course_cards()).
	 * @param int   $user_id
	 * @param array $course_ids
	 * @param array $academic_statuses Mapa course_id => status, ya calculado por el dashboard.
	 * @return array
	 */
	public function attach_badges_to_course_cards( $items, $user_id, $course_ids, $academic_statuses ) {
		unset( $course_ids );
		$items = is_array( $items ) ? $items : array();
		$academic_statuses = is_array( $academic_statuses ) ? $academic_statuses : array();

		foreach ( $items as &$item ) {
			$course_id = isset( $item['course_id'] ) ? absint( $item['course_id'] ) : 0;
			if ( ! $course_id ) {
				continue; // card sin course_id (p. ej. agregada por otro listener) -- no es nuestra.
			}

			$status = isset( $academic_statuses[ $course_id ] ) && is_array( $academic_statuses[ $course_id ] )
				? $academic_statuses[ $course_id ]
				: array();

			$badge = $this->get_badge_for_course( $user_id, $course_id, $status );

			$item['badge_tier']  = $badge['tier'];
			$item['badge_label'] = $badge['label'];
		}
		unset( $item );

		return $items;
	}

	/**
	 * Umbrales del puntaje combinado (0-100) → nivel. Documentado acá
	 * como la decisión de producto explícita, no un valor mágico
	 * disperso en el código — si el criterio cambia, cambia en un solo
	 * lugar.
	 */
	const TIERS = array(
		'sobresaliente' => array( 'min' => 90, 'label' => 'Estudiante sobresaliente' ),
		'destacado'     => array( 'min' => 75, 'label' => 'Estudiante destacado' ),
		'aplicado'      => array( 'min' => 60, 'label' => 'Estudiante aplicado' ),
		'regular'       => array( 'min' => 40, 'label' => 'Estudiante regular' ),
		'en_atencion'   => array( 'min' => 0,  'label' => 'Estudiante en atención' ),
	);

	/**
	 * Peso de cada eje en el puntaje combinado — igual peso por
	 * defecto (1/3 cada uno), filtrable si en la práctica algún eje
	 * debe pesar más.
	 *
	 * @return array{puntualidad:float,promedio:float,interaccion:float}
	 */
	protected function get_axis_weights() {
		$weights = apply_filters( 'atora_student_badge_axis_weights', array(
			'puntualidad' => 1 / 3,
			'promedio'    => 1 / 3,
			'interaccion' => 1 / 3,
		) );

		return is_array( $weights ) ? $weights : array( 'puntualidad' => 1 / 3, 'promedio' => 1 / 3, 'interaccion' => 1 / 3 );
	}

	/**
	 * Insignia de un estudiante en un curso.
	 *
	 * @param int        $user_id
	 * @param int        $course_id
	 * @param array|null $status Resultado ya calculado de
	 *                           get_student_course_status(), si el
	 *                           llamador ya lo tiene (el panel de
	 *                           estudiante lo calcula para todo el
	 *                           dashboard) — evita recalcularlo.
	 * @return array{tier:string,label:string,score:int|null,axes:array,has_data:bool}
	 */
	public function get_badge_for_course( $user_id, $course_id, $status = null ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id ) {
			return $this->empty_badge();
		}

		if ( null === $status ) {
			$status_service = class_exists( 'CLMS_Helper' ) ? clms_core( 'CLMS_Academic_Status_Service' ) : null;
			$status = ( $status_service && method_exists( $status_service, 'get_student_course_status' ) )
				? (array) $status_service->get_student_course_status( $user_id, $course_id, array( 'skip_improvement_plan' => true ) )
				: array();
		}
		$status = is_array( $status ) ? $status : array();

		$axes = $this->compute_axes( $user_id, $course_id, $status );

		// Sin ningún dato real en ningún eje (estudiante recién
		// inscrito, sin entregas ni actividad): no hay base para
		// clasificar -- "aplicado" (neutro) en vez de "en atención"
		// (que sería juzgar con cero información, no con bajo
		// desempeño real).
		if ( ! $axes['puntualidad']['has_data'] && ! $axes['promedio']['has_data'] && ! $axes['interaccion']['has_data'] ) {
			return array(
				'tier'     => 'aplicado',
				'label'    => self::TIERS['aplicado']['label'],
				'score'    => null,
				'axes'     => $axes,
				'has_data' => false,
			);
		}

		$score = $this->compute_combined_score( $axes );
		$tier  = $this->score_to_tier( $score );

		return array(
			'tier'     => $tier,
			'label'    => self::TIERS[ $tier ]['label'],
			'score'    => $score,
			'axes'     => $axes,
			'has_data' => true,
		);
	}

	/**
	 * @param int   $user_id
	 * @param int   $course_id
	 * @param array $status
	 * @return array<string,array{value:int|null,has_data:bool}>
	 */
	protected function compute_axes( $user_id, $course_id, array $status ) {
		$status_service = class_exists( 'CLMS_Helper' ) ? clms_core( 'CLMS_Academic_Status_Service' ) : null;

		$on_time = ( $status_service && method_exists( $status_service, 'get_on_time_rate_for_student' ) )
			? $status_service->get_on_time_rate_for_student( $user_id, $course_id )
			: null;

		$average = isset( $status['final_average'] ) && null !== $status['final_average']
			? absint( $status['final_average'] )
			: null;

		// Interacción: participación real sobre el contenido
		// disponible (lecciones completadas / total) -- ya calculado
		// por get_student_course_status(), no una métrica nueva de
		// tracking. No se usa "progress_percent" tal cual porque ese
		// campo puede venir de otra fuente (get_course_grade_summary())
		// en algunos flujos; se deriva directo de completed/total para
		// que el eje sea siempre comparable entre estudiantes del
		// mismo curso.
		$completed = isset( $status['completed_lessons'] ) ? absint( $status['completed_lessons'] ) : 0;
		$total     = isset( $status['total_lessons'] ) ? absint( $status['total_lessons'] ) : 0;
		$interaction = $total > 0 ? (int) round( ( $completed / $total ) * 100 ) : null;

		return array(
			'puntualidad' => array( 'value' => $on_time, 'has_data' => null !== $on_time ),
			'promedio'    => array( 'value' => $average, 'has_data' => null !== $average ),
			'interaccion' => array( 'value' => $interaction, 'has_data' => null !== $interaction ),
		);
	}

	/**
	 * Combina los ejes con datos disponibles, re-normalizando los
	 * pesos si falta algún eje (p. ej. un curso sin fechas límite
	 * nunca tendría puntualidad -- no debe arrastrar el puntaje hacia
	 * abajo por un eje que estructuralmente no aplica).
	 *
	 * @param array $axes
	 * @return int 0-100
	 */
	protected function compute_combined_score( array $axes ) {
		$weights   = $this->get_axis_weights();
		$available = array();
		$weight_sum = 0.0;

		foreach ( $axes as $axis_key => $axis ) {
			if ( ! empty( $axis['has_data'] ) ) {
				$w = isset( $weights[ $axis_key ] ) ? (float) $weights[ $axis_key ] : 0;
				$available[ $axis_key ] = array( 'value' => (int) $axis['value'], 'weight' => $w );
				$weight_sum += $w;
			}
		}

		if ( empty( $available ) || $weight_sum <= 0 ) {
			return 0;
		}

		$score = 0.0;
		foreach ( $available as $axis ) {
			$score += $axis['value'] * ( $axis['weight'] / $weight_sum );
		}

		return max( 0, min( 100, (int) round( $score ) ) );
	}

	/**
	 * @param int $score 0-100
	 * @return string Clave de self::TIERS.
	 */
	protected function score_to_tier( $score ) {
		$score = max( 0, min( 100, absint( $score ) ) );
		foreach ( self::TIERS as $key => $tier ) {
			if ( $score >= $tier['min'] ) {
				return $key;
			}
		}
		return 'en_atencion';
	}

	/**
	 * @return array
	 */
	protected function empty_badge() {
		return array(
			'tier'     => 'aplicado',
			'label'    => self::TIERS['aplicado']['label'],
			'score'    => null,
			'axes'     => array(
				'puntualidad' => array( 'value' => null, 'has_data' => false ),
				'promedio'    => array( 'value' => null, 'has_data' => false ),
				'interaccion' => array( 'value' => null, 'has_data' => false ),
			),
			'has_data' => false,
		);
	}
}
