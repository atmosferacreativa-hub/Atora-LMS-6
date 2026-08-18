<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inteligencia académica del Gradebook.
 * Opera primero con reglas locales; puede conectarse a CLMS_AI_Manager si está disponible.
 * La IA solo sugiere, resume o explica — nunca modifica notas.
 */
class CLMS_Gradebook_Intelligence_Service {

	const MAX_ALERTS_DISPLAY = 5;

	/**
	 * Genera alertas académicas a partir del grid del gradebook.
	 *
	 * @param array $grid Grid construido por CLMS_Gradebook_Service.
	 * @return array Array de alertas ordenadas por severidad.
	 */
	public function analyze_grid( $grid ) {
		$grid = is_array( $grid ) ? $grid : array();

		$rows    = is_array( $grid['rows'] ?? null ) ? $grid['rows'] : array();
		$columns = is_array( $grid['columns'] ?? null ) ? $grid['columns'] : array();

		if ( empty( $rows ) || empty( $columns ) ) {
			return array();
		}

		$alerts = array();

		$alerts = array_merge( $alerts, $this->check_students_at_risk( $rows, $columns ) );
		$alerts = array_merge( $alerts, $this->check_problematic_activities( $rows, $columns ) );
		$alerts = array_merge( $alerts, $this->check_missing_submissions( $rows, $columns ) );
		$alerts = array_merge( $alerts, $this->check_incomplete_rubrics( $rows, $columns ) );
		$alerts = array_merge( $alerts, $this->check_grade_inconsistencies( $rows ) );

		usort(
			$alerts,
			static function ( $a, $b ) {
				$priority = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
				return ( $priority[ $a['severity'] ] ?? 9 ) <=> ( $priority[ $b['severity'] ] ?? 9 );
			}
		);

		return apply_filters( 'clms_gradebook_intelligence_alerts', $alerts, $grid );
	}

	/**
	 * Detecta estudiantes en riesgo académico.
	 */
	protected function check_students_at_risk( $rows, $columns ) {
		$alerts        = array();
		$total_cols    = count( $columns );

		foreach ( $rows as $row ) {
			$student_id = absint( $row['student_id'] ?? 0 );
			$total      = absint( $row['total'] ?? 0 );
			$risk_level = sanitize_key( (string) ( $row['risk_level'] ?? 'low' ) );

			if ( 'high' !== $risk_level && 'medium' !== $risk_level ) {
				continue;
			}

			$cells   = is_array( $row['cells'] ?? null ) ? $row['cells'] : array();
			$missing = 0;
			foreach ( $cells as $cell ) {
				if ( ! empty( $cell['missing'] ) ) {
					++$missing;
				}
			}

			$severity = 'high' === $risk_level ? 'high' : 'medium';
			$message  = sprintf(
				/* translators: 1: student name, 2: total grade, 3: missing count */
				__( '%1$s tiene una nota promedio de %2$d%% y %3$d actividad(es) sin entregar.', 'atora-lms' ),
				esc_html( (string) ( $row['student_name'] ?? 'Estudiante' ) ),
				$total,
				$missing
			);

			$alerts[] = array(
				'type'               => 'student_risk',
				'severity'           => $severity,
				'student_id'         => $student_id,
				'title'              => __( 'Estudiante en riesgo', 'atora-lms' ),
				'message'            => $message,
				'recommended_action' => __( 'Envía un plan de mejora o recordatorio. Considera abrir una sesión de SpeedGrade.', 'atora-lms' ),
			);
		}

		return $alerts;
	}

	/**
	 * Detecta actividades con promedio bajo o muchos ceros.
	 */
	protected function check_problematic_activities( $rows, $columns ) {
		$alerts    = array();
		$per_col   = array();

		foreach ( $rows as $row ) {
			$cells = is_array( $row['cells'] ?? null ) ? $row['cells'] : array();
			foreach ( $cells as $lesson_id => $cell ) {
				$lesson_id = absint( $lesson_id );
				$grade     = '' !== (string) ( $cell['grade'] ?? '' ) ? absint( $cell['grade'] ) : null;
				if ( ! isset( $per_col[ $lesson_id ] ) ) {
					$per_col[ $lesson_id ] = array( 'grades' => array(), 'zeros' => 0 );
				}
				if ( null !== $grade ) {
					$per_col[ $lesson_id ]['grades'][] = $grade;
					if ( 0 === $grade ) {
						++$per_col[ $lesson_id ]['zeros'];
					}
				}
			}
		}

		foreach ( $columns as $column ) {
			$lesson_id = absint( $column['lesson_id'] ?? 0 );
			if ( ! $lesson_id || empty( $per_col[ $lesson_id ]['grades'] ) ) {
				continue;
			}

			$grades = $per_col[ $lesson_id ]['grades'];
			$avg    = count( $grades ) > 0 ? array_sum( $grades ) / count( $grades ) : 0;
			$zeros  = $per_col[ $lesson_id ]['zeros'];

			if ( $avg < 50 ) {
				$alerts[] = array(
					'type'               => 'activity_low_average',
					'severity'           => $avg < 30 ? 'high' : 'medium',
					'lesson_id'          => $lesson_id,
					'title'              => __( 'Actividad con promedio bajo', 'atora-lms' ),
					'message'            => sprintf(
						/* translators: 1: activity title, 2: average */
						__( 'La actividad "%1$s" tiene un promedio de %.1f%%.', 'atora-lms' ),
						esc_html( (string) ( $column['title'] ?? '' ) ),
						$avg
					),
					'recommended_action' => __( 'Revisa las rúbricas y considera una sesión de retroalimentación grupal.', 'atora-lms' ),
				);
			}

			if ( $zeros > 2 && count( $grades ) > 0 && ( $zeros / count( $grades ) ) > 0.3 ) {
				$alerts[] = array(
					'type'               => 'activity_many_zeros',
					'severity'           => 'medium',
					'lesson_id'          => $lesson_id,
					'title'              => __( 'Actividad con muchos ceros', 'atora-lms' ),
					'message'            => sprintf(
						/* translators: 1: activity title, 2: zero count */
						__( '"%1$s" tiene %2$d estudiante(s) con cero.', 'atora-lms' ),
						esc_html( (string) ( $column['title'] ?? '' ) ),
						$zeros
					),
					'recommended_action' => __( 'Verifica si la entrega fue configurada correctamente.', 'atora-lms' ),
				);
			}
		}

		return $alerts;
	}

	/**
	 * Detecta entregas faltantes en exceso.
	 */
	protected function check_missing_submissions( $rows, $columns ) {
		$alerts   = array();
		$total    = count( $columns );
		if ( $total < 3 ) {
			return $alerts;
		}

		foreach ( $rows as $row ) {
			$cells   = is_array( $row['cells'] ?? null ) ? $row['cells'] : array();
			$missing = 0;
			foreach ( $cells as $cell ) {
				if ( ! empty( $cell['missing'] ) ) {
					++$missing;
				}
			}

			$ratio = $total > 0 ? $missing / $total : 0;
			if ( $ratio < 0.5 ) {
				continue;
			}

			$alerts[] = array(
				'type'               => 'student_missing',
				'severity'           => $ratio >= 0.75 ? 'high' : 'medium',
				'student_id'         => absint( $row['student_id'] ?? 0 ),
				'title'              => __( 'Muchas actividades sin entregar', 'atora-lms' ),
				'message'            => sprintf(
					/* translators: 1: student name, 2: missing count, 3: total */
					__( '%1$s no ha entregado %2$d de %3$d actividades.', 'atora-lms' ),
					esc_html( (string) ( $row['student_name'] ?? 'Estudiante' ) ),
					$missing,
					$total
				),
				'recommended_action' => __( 'Contacta al estudiante y verifica su acceso al curso.', 'atora-lms' ),
			);
		}

		return $alerts;
	}

	/**
	 * Detecta rúbricas incompletas con nota publicada.
	 */
	protected function check_incomplete_rubrics( $rows, $columns ) {
		$alerts = array();

		foreach ( $rows as $row ) {
			$cells = is_array( $row['cells'] ?? null ) ? $row['cells'] : array();
			foreach ( $cells as $lesson_id => $cell ) {
				$rubric_id = absint( $cell['rubric_id'] ?? 0 );
				if ( ! $rubric_id ) {
					continue;
				}

				$has_grade   = '' !== (string) ( $cell['grade'] ?? '' );
				$completed   = ! empty( $cell['rubric_completed'] );
				$scored      = absint( $cell['rubric_criteria_scored'] ?? 0 );
				$total_crit  = absint( $cell['rubric_criteria_count'] ?? 0 );

				if ( $has_grade && ! $completed && $total_crit > 0 && $scored < $total_crit ) {
					$alerts[] = array(
						'type'               => 'rubric_incomplete',
						'severity'           => 'low',
						'student_id'         => absint( $row['student_id'] ?? 0 ),
						'lesson_id'          => absint( $lesson_id ),
						'title'              => __( 'Rúbrica incompleta con nota publicada', 'atora-lms' ),
						'message'            => sprintf(
							/* translators: 1: student name */
							__( 'La entrega de %1$s tiene nota pero la rúbrica no fue completada.', 'atora-lms' ),
							esc_html( (string) ( $row['student_name'] ?? 'Estudiante' ) )
						),
						'recommended_action' => __( 'Abre SpeedGrade y completa la rúbrica.', 'atora-lms' ),
					);
				}
			}
		}

		return $alerts;
	}

	/**
	 * Detecta inconsistencias entre rúbrica y nota final.
	 */
	protected function check_grade_inconsistencies( $rows ) {
		$alerts = array();

		foreach ( $rows as $row ) {
			$cells = is_array( $row['cells'] ?? null ) ? $row['cells'] : array();
			foreach ( $cells as $lesson_id => $cell ) {
				$grade        = '' !== (string) ( $cell['grade'] ?? '' ) ? absint( $cell['grade'] ) : null;
				$rubric_score = '' !== (string) ( $cell['rubric_score'] ?? '' ) ? absint( $cell['rubric_score'] ) : null;
				$manual       = ! empty( $cell['manual_override'] );

				if ( null === $grade || null === $rubric_score || $manual ) {
					continue;
				}

				if ( abs( $grade - $rubric_score ) > 15 ) {
					$alerts[] = array(
						'type'               => 'grade_inconsistency',
						'severity'           => 'medium',
						'student_id'         => absint( $row['student_id'] ?? 0 ),
						'lesson_id'          => absint( $lesson_id ),
						'title'              => __( 'Inconsistencia entre rúbrica y nota final', 'atora-lms' ),
						'message'            => sprintf(
							/* translators: 1: student name, 2: grade, 3: rubric score */
							__( '%1$s tiene nota final %2$d%% pero puntaje de rúbrica %3$d%%.', 'atora-lms' ),
							esc_html( (string) ( $row['student_name'] ?? 'Estudiante' ) ),
							$grade,
							$rubric_score
						),
						'recommended_action' => __( 'Verifica si se aplicó un override manual o si la rúbrica necesita revisión.', 'atora-lms' ),
					);
				}
			}
		}

		return $alerts;
	}

	/**
	 * Renderiza el bloque de alertas académicas en el Gradebook.
	 * Muestra máximo MAX_ALERTS_DISPLAY alertas con opción de expandir.
	 *
	 * @param array $alerts Alertas generadas por analyze_grid().
	 * @return string HTML.
	 */
	public function render_alerts_block( $alerts ) {
		$alerts = is_array( $alerts ) ? $alerts : array();

		if ( empty( $alerts ) ) {
			return '';
		}

		$display = array_slice( $alerts, 0, self::MAX_ALERTS_DISPLAY );
		$hidden  = count( $alerts ) > self::MAX_ALERTS_DISPLAY ? count( $alerts ) - self::MAX_ALERTS_DISPLAY : 0;

		$severity_icons = array(
			'high'   => '🔴',
			'medium' => '🟡',
			'low'    => '🔵',
		);

		ob_start();
		?>
		<div class="clms-gradebook-alerts atora-card" id="clms-gradebook-intelligence">
			<div class="atora-section-header">
				<h3 class="atora-section-title"><?php esc_html_e( 'Alertas académicas', 'atora-lms' ); ?></h3>
				<span class="atora-badge <?php echo count( $alerts ) > 0 ? 'is-pending' : ''; ?>">
					<?php echo esc_html( count( $alerts ) ); ?>
				</span>
			</div>
			<div class="clms-alerts-list">
				<?php foreach ( $display as $alert ) : ?>
					<div class="clms-alert-item severity-<?php echo esc_attr( sanitize_key( (string) ( $alert['severity'] ?? 'low' ) ) ); ?>">
						<div class="clms-alert-header">
							<span class="clms-alert-icon"><?php echo $severity_icons[ $alert['severity'] ] ?? '⚪'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<strong class="clms-alert-title"><?php echo esc_html( (string) ( $alert['title'] ?? '' ) ); ?></strong>
						</div>
						<p class="clms-alert-message"><?php echo esc_html( (string) ( $alert['message'] ?? '' ) ); ?></p>
						<?php if ( ! empty( $alert['recommended_action'] ) ) : ?>
							<p class="clms-alert-action"><em><?php echo esc_html( (string) $alert['recommended_action'] ); ?></em></p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				<?php if ( $hidden > 0 ) : ?>
					<p class="clms-alerts-more">
						<a href="#" data-clms-show-all-alerts="1">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of hidden alerts */
									_n( 'Ver %d alerta más', 'Ver %d alertas más', $hidden, 'atora-lms' ),
									$hidden
								)
							);
							?>
						</a>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
