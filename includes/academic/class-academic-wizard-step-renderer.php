<?php
/**
 * Render modular de pasos avanzados del Asistente Académico.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Academic_Wizard_Step_Renderer {

	/**
	 * Renderiza pasos 4 a 10 del wizard.
	 *
	 * @param int   $step      Paso actual.
	 * @param int   $course_id Curso.
	 * @param array $context   Contexto adicional.
	 * @return bool
	 */
	public function render_step( $step, $course_id, $context = array() ) {
		$step      = absint( $step );
		$course_id = absint( $course_id );
		$context   = is_array( $context ) ? $context : array();

		if ( $step < 4 || $step > 10 ) {
			return false;
		}

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			echo '<h2>' . esc_html__( 'Curso no seleccionado', 'atora-lms' ) . '</h2>';
			echo '<p>' . esc_html__( 'Selecciona o crea un curso para completar este paso.', 'atora-lms' ) . '</p>';
			return true;
		}

		switch ( $step ) {
			case 4:
				$this->render_step_lessons( $course_id );
				return true;
			case 5:
				$this->render_step_evidences( $course_id );
				return true;
			case 6:
				$this->render_step_rubrics( $course_id );
				return true;
			case 7:
				$this->render_step_teacher( $course_id );
				return true;
			case 8:
				$this->render_step_certificate( $course_id );
				return true;
			case 9:
				$this->render_step_commercial( $course_id );
				return true;
			case 10:
				$this->render_step_publish( $course_id, $context );
				return true;
		}

		return false;
	}

	/**
	 * Paso 4: módulos y lecciones.
	 *
	 * @param int $course_id Curso.
	 * @return void
	 */
	protected function render_step_lessons( $course_id ) {
		$course_id = absint( $course_id );
		$lesson_ids = class_exists( 'CLMS_Helper' ) ? (array) CLMS_Helper::get_course_lessons( $course_id ) : array();
		$lesson_ids = array_values( array_filter( array_map( 'absint', $lesson_ids ) ) );

		$orphan_lessons = get_posts(
			array(
				'post_type'      => 'lm_lesson',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 12,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array(
						'key'     => '_clms_course_id',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_clms_course_id',
						'value'   => '0',
						'compare' => '=',
					),
					array(
						'key'     => '_clms_course_id',
						'value'   => '',
						'compare' => '=',
					),
				),
				'fields'         => 'ids',
			)
		);
		$orphan_lessons = array_values( array_filter( array_map( 'absint', (array) $orphan_lessons ) ) );

		echo '<h2>' . esc_html__( 'Paso 4: Módulos y lecciones', 'atora-lms' ) . '</h2>';
		echo '<p>' . esc_html__( 'Revisa la estructura actual del curso y completa las lecciones faltantes.', 'atora-lms' ) . '</p>';

		$this->render_status_grid(
			array(
				array(
					'label' => __( 'Lecciones asociadas', 'atora-lms' ),
					'value' => count( $lesson_ids ),
					'state' => empty( $lesson_ids ) ? 'critical' : 'complete',
				),
				array(
					'label' => __( 'Lecciones sin curso', 'atora-lms' ),
					'value' => count( $orphan_lessons ),
					'state' => empty( $orphan_lessons ) ? 'complete' : 'warning',
				),
			)
		);

		if ( empty( $lesson_ids ) ) {
			echo '<p><strong>' . esc_html__( 'Advertencia:', 'atora-lms' ) . '</strong> ' . esc_html__( 'Este curso todavía no tiene lecciones asociadas.', 'atora-lms' ) . '</p>';
		}

		echo '<div class="clms-admin-actions" style="margin:12px 0;">';
		echo '<a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=lm_lesson&clms_course_id=' . $course_id ) ) . '">' . esc_html__( 'Crear nueva lección para este curso', 'atora-lms' ) . '</a> ';
		echo '<a class="button button-secondary" href="' . esc_url( admin_url( 'edit.php?post_type=lm_lesson&clms_course_id=' . $course_id ) ) . '">' . esc_html__( 'Ver lecciones del curso', 'atora-lms' ) . '</a>';
		echo '</div>';

		echo '<h3>' . esc_html__( 'Lecciones actuales', 'atora-lms' ) . '</h3>';
		if ( empty( $lesson_ids ) ) {
			echo '<p>' . esc_html__( 'Aún no hay lecciones asociadas a este curso.', 'atora-lms' ) . '</p>';
		} else {
			echo '<ul style="margin-left:16px;list-style:disc">';
			foreach ( array_slice( $lesson_ids, 0, 30 ) as $lesson_id ) {
				echo '<li>';
				echo esc_html( get_the_title( $lesson_id ) );
				echo ' <a href="' . esc_url( get_edit_post_link( $lesson_id, '' ) ) . '">' . esc_html__( 'Editar', 'atora-lms' ) . '</a>';
				echo '</li>';
			}
			echo '</ul>';
		}

		if ( ! empty( $orphan_lessons ) ) {
			echo '<h3>' . esc_html__( 'Lecciones sin curso asociado (muestra)', 'atora-lms' ) . '</h3>';
			echo '<p>' . esc_html__( 'Selecciona lecciones para asociarlas al curso al guardar este paso.', 'atora-lms' ) . '</p>';
			echo '<ul style="margin-left:16px;list-style:disc">';
			foreach ( $orphan_lessons as $lesson_id ) {
				echo '<li>';
				echo '<label><input type="checkbox" name="wizard_attach_lessons[]" value="' . esc_attr( (string) $lesson_id ) . '"> ';
				echo esc_html( get_the_title( $lesson_id ) ) . '</label> ';
				echo '<a href="' . esc_url( get_edit_post_link( $lesson_id, '' ) ) . '">' . esc_html__( 'Revisar', 'atora-lms' ) . '</a>';
				echo '</li>';
			}
			echo '</ul>';
		}
	}

	/**
	 * Paso 5: actividades y evidencias.
	 *
	 * @param int $course_id Curso.
	 * @return void
	 */
	protected function render_step_evidences( $course_id ) {
		$course_id = absint( $course_id );
		$evidence_service   = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
		$competency_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Competency_Service') : null;
		$diagnostics_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Diagnostics_Service') : null;

		$competencies = ( $competency_service && method_exists( $competency_service, 'get_course_competencies' ) )
			? (array) $competency_service->get_course_competencies( $course_id )
			: array();
		$comp_map = array();
		foreach ( $competencies as $competency ) {
			$competency = is_array( $competency ) ? $competency : array();
			$id    = sanitize_key( (string) ( $competency['id'] ?? '' ) );
			$title = sanitize_text_field( (string) ( $competency['title'] ?? '' ) );
			if ( '' !== $id && '' !== $title ) {
				$comp_map[ $id ] = $title;
			}
		}

		$lesson_ids = class_exists( 'CLMS_Helper' ) ? (array) CLMS_Helper::get_course_lessons( $course_id ) : array();
		$lesson_ids = array_values( array_filter( array_map( 'absint', $lesson_ids ) ) );

		$evidence_rows = array();
		$used_competencies = array();
		foreach ( $lesson_ids as $lesson_id ) {
			$config = ( $evidence_service && method_exists( $evidence_service, 'get_activity_evidence_config' ) )
				? (array) $evidence_service->get_activity_evidence_config( $lesson_id )
				: array();
			$activity_type = sanitize_key( (string) get_post_meta( $lesson_id, 'lm_activity_type', true ) );
			$type = sanitize_key( (string) ( $config['evidence_type'] ?? 'practice' ) );
			$is_required = ! empty( $config['is_required_for_certificate'] );
			$comp_ids = isset( $config['competency_ids'] ) && is_array( $config['competency_ids'] ) ? array_values( array_filter( array_map( 'sanitize_key', $config['competency_ids'] ) ) ) : array();
			$is_evidence = $is_required || 'practice' !== $type || in_array( $activity_type, array( 'tarea', 'quiz' ), true );
			if ( ! $is_evidence ) {
				continue;
			}
			foreach ( $comp_ids as $comp_id ) {
				$used_competencies[] = $comp_id;
			}
			$evidence_rows[] = array(
				'lesson_id'   => $lesson_id,
				'title'       => get_the_title( $lesson_id ),
				'type'        => $type,
				'required'    => $is_required,
				'minimum'     => absint( $config['minimum_grade'] ?? 0 ),
				'comp_ids'    => $comp_ids,
			);
		}

		$used_competencies = array_values( array_unique( array_filter( $used_competencies ) ) );
		$comp_without_evidence = array_values( array_diff( array_keys( $comp_map ), $used_competencies ) );

		$diag = ( $diagnostics_service && method_exists( $diagnostics_service, 'diagnose_course' ) )
			? (array) $diagnostics_service->diagnose_course( $course_id )
			: array();

		echo '<h2>' . esc_html__( 'Paso 5: Actividades y evidencias', 'atora-lms' ) . '</h2>';
		echo '<p>' . esc_html__( 'Valida que cada evidencia mida competencias relevantes y que las obligatorias estén completas.', 'atora-lms' ) . '</p>';

		$this->render_status_grid(
			array(
				array(
					'label' => __( 'Evidencias detectadas', 'atora-lms' ),
					'value' => count( $evidence_rows ),
					'state' => empty( $evidence_rows ) ? 'warning' : 'complete',
				),
				array(
					'label' => __( 'Obligatorias', 'atora-lms' ),
					'value' => absint( $diag['required_evidences_count'] ?? 0 ),
					'state' => absint( $diag['required_evidences_count'] ?? 0 ) > 0 ? 'complete' : 'warning',
				),
				array(
					'label' => __( 'Evidencias sin competencia', 'atora-lms' ),
					'value' => absint( $diag['evidences_without_competency_count'] ?? 0 ),
					'state' => absint( $diag['evidences_without_competency_count'] ?? 0 ) > 0 ? 'warning' : 'complete',
				),
				array(
					'label' => __( 'Competencias sin evidencia', 'atora-lms' ),
					'value' => count( $comp_without_evidence ),
					'state' => count( $comp_without_evidence ) > 0 ? 'warning' : 'complete',
				),
			)
		);

		if ( empty( $evidence_rows ) ) {
			echo '<p>' . esc_html__( 'Este curso aún no tiene evidencias configuradas.', 'atora-lms' ) . '</p>';
		} else {
			$evidence_type_options = array(
				'practice'             => __( 'Práctica', 'atora-lms' ),
				'read_only'            => __( 'Solo lectura', 'atora-lms' ),
				'assignment'           => __( 'Tarea', 'atora-lms' ),
				'partial_exam'         => __( 'Evaluación parcial', 'atora-lms' ),
				'final_exam'           => __( 'Evaluación final', 'atora-lms' ),
				'certifiable_evidence' => __( 'Evidencia certificable', 'atora-lms' ),
				'required'             => __( 'Requisito obligatorio', 'atora-lms' ),
			);

			echo '<div class="clms-admin-card" style="margin:10px 0;padding:12px;">';
			echo '<h3>' . esc_html__( 'Aplicar configuración de evidencia (lote)', 'atora-lms' ) . '</h3>';
			echo '<p>' . esc_html__( 'Selecciona actividades y aplica tipo, obligatoriedad, nota mínima y competencias existentes.', 'atora-lms' ) . '</p>';
			echo '<p><label>' . esc_html__( 'Tipo de evidencia', 'atora-lms' ) . ' ';
			echo '<select name="wizard_evidence_type">';
			foreach ( $evidence_type_options as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select></label></p>';
			echo '<p><label><input type="checkbox" name="wizard_evidence_required" value="1"> ' . esc_html__( 'Marcar como obligatoria para certificado', 'atora-lms' ) . '</label></p>';
			echo '<p><label>' . esc_html__( 'Nota mínima (%)', 'atora-lms' ) . ' <input type="number" min="0" max="100" step="1" name="wizard_evidence_minimum_grade" value="70"></label></p>';
			if ( ! empty( $comp_map ) ) {
				echo '<p><strong>' . esc_html__( 'Competencias asociadas', 'atora-lms' ) . '</strong></p>';
				echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:6px">';
				foreach ( $comp_map as $comp_id => $comp_label ) {
					echo '<label><input type="checkbox" name="wizard_evidence_competency_ids[]" value="' . esc_attr( $comp_id ) . '"> ' . esc_html( $comp_label ) . '</label>';
				}
				echo '</div>';
			} else {
				echo '<p>' . esc_html__( 'Este curso aún no tiene competencias disponibles para asociar.', 'atora-lms' ) . '</p>';
			}
			echo '</div>';

			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Actividad', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Tipo', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Competencias', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Estado', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Edición rápida por fila', 'atora-lms' ) . '</th>';
			echo '<th>' . esc_html__( 'Acción', 'atora-lms' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $evidence_rows as $row ) {
				$row = is_array( $row ) ? $row : array();
				$lesson_id = absint( $row['lesson_id'] ?? 0 );
				$comp_ids  = isset( $row['comp_ids'] ) && is_array( $row['comp_ids'] ) ? $row['comp_ids'] : array();
				$comp_labels = array();
				foreach ( $comp_ids as $comp_id ) {
					$comp_labels[] = isset( $comp_map[ $comp_id ] ) ? $comp_map[ $comp_id ] : $comp_id;
				}
				$status = ! empty( $comp_labels ) ? __( 'Completo', 'atora-lms' ) : __( 'Sin competencia', 'atora-lms' );
				if ( ! empty( $row['required'] ) ) {
					$status .= ' · ' . sprintf( __( 'Mínimo %d%%', 'atora-lms' ), absint( $row['minimum'] ?? 0 ) );
				}

				echo '<tr>';
				echo '<td><label><input type="checkbox" name="wizard_evidence_selected[]" value="' . esc_attr( (string) $lesson_id ) . '"> <strong>' . esc_html( (string) ( $row['title'] ?? '' ) ) . '</strong></label>' . ( ! empty( $row['required'] ) ? '<br><span class="description">' . esc_html__( 'Obligatoria para certificado', 'atora-lms' ) . '</span>' : '' ) . '</td>';
				echo '<td>' . esc_html( sanitize_text_field( (string) ( $row['type'] ?? '' ) ) ) . '</td>';
				echo '<td>' . esc_html( ! empty( $comp_labels ) ? implode( ', ', array_map( 'sanitize_text_field', $comp_labels ) ) : __( 'No asociada', 'atora-lms' ) ) . '</td>';
				echo '<td>' . esc_html( $status ) . '</td>';
				echo '<td>';
				echo '<label><input type="checkbox" name="wizard_evidence_rows_apply[]" value="' . esc_attr( (string) $lesson_id ) . '"> ' . esc_html__( 'Aplicar', 'atora-lms' ) . '</label><br>';
				echo '<label>' . esc_html__( 'Tipo', 'atora-lms' ) . ' ';
				echo '<select name="wizard_evidence_rows[' . esc_attr( (string) $lesson_id ) . '][type]">';
				foreach ( $evidence_type_options as $value => $label ) {
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, (string) ( $row['type'] ?? 'practice' ), false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select></label><br>';
				echo '<label><input type="checkbox" name="wizard_evidence_rows[' . esc_attr( (string) $lesson_id ) . '][required]" value="1"' . checked( ! empty( $row['required'] ), true, false ) . '> ' . esc_html__( 'Obligatoria', 'atora-lms' ) . '</label><br>';
				echo '<label>' . esc_html__( 'Mínimo', 'atora-lms' ) . ' <input type="number" min="0" max="100" step="1" name="wizard_evidence_rows[' . esc_attr( (string) $lesson_id ) . '][minimum_grade]" value="' . esc_attr( (string) absint( $row['minimum'] ?? 0 ) ) . '" style="width:80px"></label>';
				if ( ! empty( $comp_map ) ) {
					echo '<div style="margin-top:6px">';
					foreach ( $comp_map as $comp_id => $comp_label ) {
						$is_checked = in_array( $comp_id, $comp_ids, true );
						echo '<label style="display:block"><input type="checkbox" name="wizard_evidence_rows[' . esc_attr( (string) $lesson_id ) . '][competency_ids][]" value="' . esc_attr( $comp_id ) . '"' . checked( $is_checked, true, false ) . '> ' . esc_html( $comp_label ) . '</label>';
					}
					echo '</div>';
				}
				echo '</td>';
				echo '<td>' . ( $lesson_id ? '<a class="button button-small" href="' . esc_url( get_edit_post_link( $lesson_id, '' ) ) . '">' . esc_html__( 'Editar actividad', 'atora-lms' ) . '</a>' : '' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $comp_without_evidence ) ) {
			echo '<p><strong>' . esc_html__( 'Competencias sin evidencia:', 'atora-lms' ) . '</strong> ';
			$labels = array();
			foreach ( $comp_without_evidence as $comp_id ) {
				$labels[] = isset( $comp_map[ $comp_id ] ) ? $comp_map[ $comp_id ] : $comp_id;
			}
			echo esc_html( implode( ', ', array_map( 'sanitize_text_field', $labels ) ) );
			echo '</p>';
		}
	}

	/**
	 * Paso 6: rúbrica y evaluación.
	 *
	 * @param int $course_id Curso.
	 * @return void
	 */
	protected function render_step_rubrics( $course_id ) {
		$course_id = absint( $course_id );
		$lesson_ids = class_exists( 'CLMS_Helper' ) ? (array) CLMS_Helper::get_course_lessons( $course_id ) : array();
		$lesson_ids = array_values( array_filter( array_map( 'absint', $lesson_ids ) ) );

		$rubric_map = array();
		$evaluable_without_rubric = array();

		foreach ( $lesson_ids as $lesson_id ) {
			$rubric_id = absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) );
			$activity  = sanitize_key( (string) get_post_meta( $lesson_id, 'lm_activity_type', true ) );
			if ( $rubric_id ) {
				if ( ! isset( $rubric_map[ $rubric_id ] ) ) {
					$rubric_map[ $rubric_id ] = array(
						'title'       => get_the_title( $rubric_id ),
						'criteria'    => class_exists( 'CLMS_Rubric' ) && method_exists( 'CLMS_Rubric', 'get_criteria' ) ? (array) CLMS_Rubric::get_criteria( $rubric_id ) : array(),
						'lessons'     => array(),
					);
				}
				$rubric_map[ $rubric_id ]['lessons'][] = $lesson_id;
			} elseif ( in_array( $activity, array( 'tarea', 'quiz' ), true ) ) {
				$evaluable_without_rubric[] = $lesson_id;
			}
		}

		echo '<h2>' . esc_html__( 'Paso 6: Rúbrica y evaluación', 'atora-lms' ) . '</h2>';
		echo '<p>' . esc_html__( 'Verifica que las actividades evaluables tengan rúbricas claras con criterios y competencias asociadas.', 'atora-lms' ) . '</p>';

		$this->render_status_grid(
			array(
				array(
					'label' => __( 'Rúbricas asociadas', 'atora-lms' ),
					'value' => count( $rubric_map ),
					'state' => empty( $rubric_map ) ? 'warning' : 'complete',
				),
				array(
					'label' => __( 'Evaluables sin rúbrica', 'atora-lms' ),
					'value' => count( $evaluable_without_rubric ),
					'state' => empty( $evaluable_without_rubric ) ? 'complete' : 'critical',
				),
			)
		);

		echo '<div class="clms-admin-actions" style="margin:12px 0;">';
		echo '<a class="button button-secondary" href="' . esc_url( admin_url( 'edit.php?post_type=clms_rubric' ) ) . '">' . esc_html__( 'Abrir rúbricas', 'atora-lms' ) . '</a>';
		echo '</div>';

		if ( empty( $rubric_map ) ) {
			echo '<p>' . esc_html__( 'Todavía no hay rúbricas asociadas a las lecciones de este curso.', 'atora-lms' ) . '</p>';
		} else {
			foreach ( $rubric_map as $rubric_id => $item ) {
				$item = is_array( $item ) ? $item : array();
				$criteria = isset( $item['criteria'] ) && is_array( $item['criteria'] ) ? $item['criteria'] : array();
				echo '<div class="clms-admin-card" style="margin:10px 0;padding:12px;">';
				echo '<h3>' . esc_html( (string) ( $item['title'] ?? __( 'Rúbrica', 'atora-lms' ) ) ) . '</h3>';
				echo '<p><a class="button button-small" href="' . esc_url( get_edit_post_link( absint( $rubric_id ), '' ) ) . '">' . esc_html__( 'Editar rúbrica', 'atora-lms' ) . '</a></p>';
				if ( empty( $criteria ) ) {
					echo '<p>' . esc_html__( 'Esta rúbrica no tiene criterios configurados.', 'atora-lms' ) . '</p>';
				} else {
					echo '<ul style="margin-left:16px;list-style:disc">';
					foreach ( array_slice( $criteria, 0, 8 ) as $criterion ) {
						$criterion = is_array( $criterion ) ? $criterion : array();
						$name = sanitize_text_field( (string) ( $criterion['name'] ?? '' ) );
						$max  = absint( $criterion['max_points'] ?? 0 );
						$comp = sanitize_text_field( (string) ( $criterion['competency'] ?? '' ) );
						echo '<li><strong>' . esc_html( $name ) . '</strong> (' . esc_html( (string) $max ) . ' pts)';
						if ( '' !== $comp ) {
							echo ' · ' . esc_html__( 'Competencia:', 'atora-lms' ) . ' ' . esc_html( $comp );
						}
						echo '</li>';
					}
					echo '</ul>';
				}
				echo '</div>';
			}
		}

		if ( ! empty( $evaluable_without_rubric ) ) {
			echo '<p><strong>' . esc_html__( 'Advertencia:', 'atora-lms' ) . '</strong> ' . esc_html__( 'Hay actividades evaluables sin rúbrica asociada.', 'atora-lms' ) . '</p>';
			echo '<ul style="margin-left:16px;list-style:disc">';
			foreach ( $evaluable_without_rubric as $lesson_id ) {
				echo '<li>' . esc_html( get_the_title( $lesson_id ) ) . ' <a href="' . esc_url( get_edit_post_link( $lesson_id, '' ) ) . '">' . esc_html__( 'Corregir', 'atora-lms' ) . '</a></li>';
			}
			echo '</ul>';
		}
	}

	/**
	 * Paso 7: profesor responsable.
	 *
	 * @param int $course_id Curso.
	 * @return void
	 */
	protected function render_step_teacher( $course_id ) {
		$course_id   = absint( $course_id );
		$teacher_ids = get_post_meta( $course_id, '_clms_course_teacher_ids', true );
		$teacher_ids = is_string( $teacher_ids ) ? preg_split( '/\s*,\s*/', trim( $teacher_ids ) ) : $teacher_ids;
		$teacher_ids = is_array( $teacher_ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $teacher_ids ) ) ) ) : array();

		echo '<h2>' . esc_html__( 'Paso 7: Profesor responsable', 'atora-lms' ) . '</h2>';
		echo '<p>' . esc_html__( 'Confirma el equipo docente y completa perfil académico visible para estudiantes.', 'atora-lms' ) . '</p>';

		$this->render_status_grid(
			array(
				array(
					'label' => __( 'Docentes asignados', 'atora-lms' ),
					'value' => count( $teacher_ids ),
					'state' => empty( $teacher_ids ) ? 'critical' : 'complete',
				),
			)
		);

		echo '<p><a class="button button-secondary" href="' . esc_url( get_edit_post_link( $course_id, '' ) ) . '#clms_course_teachers">' . esc_html__( 'Configurar docentes en el curso', 'atora-lms' ) . '</a></p>';

		if ( empty( $teacher_ids ) ) {
			echo '<p>' . esc_html__( 'Este curso no tiene docente asignado. Asigna al menos un profesor responsable.', 'atora-lms' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Docente', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Perfil', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Acción', 'atora-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $teacher_ids as $teacher_id ) {
			$teacher_post = get_post( $teacher_id );
			if ( ! $teacher_post || 'atora_teacher' !== $teacher_post->post_type ) {
				continue;
			}
			$bio         = trim( (string) get_post_field( 'post_excerpt', $teacher_id ) );
			$specialty   = trim( (string) get_post_meta( $teacher_id, '_clms_teacher_specialty', true ) );
			$photo_id    = absint( get_post_thumbnail_id( $teacher_id ) );

			$issues = array();
			if ( 0 === $photo_id ) {
				$issues[] = __( 'Falta foto', 'atora-lms' );
			}
			if ( '' === $bio ) {
				$issues[] = __( 'Falta bio corta', 'atora-lms' );
			}
			if ( '' === $specialty ) {
				$issues[] = __( 'Falta especialidad', 'atora-lms' );
			}

			echo '<tr>';
			echo '<td><strong>' . esc_html( get_the_title( $teacher_id ) ) . '</strong></td>';
			echo '<td>' . esc_html( empty( $issues ) ? __( 'Completo', 'atora-lms' ) : implode( ', ', $issues ) ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( get_edit_post_link( $teacher_id, '' ) ) . '">' . esc_html__( 'Editar docente', 'atora-lms' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Paso 8: certificado.
	 *
	 * @param int $course_id Curso.
	 * @return void
	 */
	protected function render_step_certificate( $course_id ) {
		$course_id = absint( $course_id );
		$rules = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Certificate_Rules') : null;
		$diagnostics_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Diagnostics_Service') : null;
		$diag = ( $diagnostics_service && method_exists( $diagnostics_service, 'diagnose_course' ) ) ? (array) $diagnostics_service->diagnose_course( $course_id ) : array();

		$min_progress = ( $rules && method_exists( $rules, 'get_min_progress' ) ) ? absint( $rules->get_min_progress() ) : absint( get_option( 'clms_certificate_min_progress', 100 ) );
		$min_average  = ( $rules && method_exists( $rules, 'get_min_average' ) ) ? absint( $rules->get_min_average() ) : absint( get_option( 'clms_certificate_passing_grade', 70 ) );
		$enabled      = ( $rules && method_exists( $rules, 'is_enabled' ) ) ? (bool) $rules->is_enabled() : (bool) get_option( 'clms_certificates_enabled', true );

		$required_evidences = absint( $diag['required_evidences_count'] ?? 0 );
		$required_competencies = absint( $diag['required_competencies_count'] ?? 0 );
		$course_ready = ! empty( $diag['course_ready_for_certificate'] );

		echo '<h2>' . esc_html__( 'Paso 8: Certificado', 'atora-lms' ) . '</h2>';
		echo '<p>' . esc_html__( 'Verifica reglas de elegibilidad antes de habilitar certificación del curso.', 'atora-lms' ) . '</p>';

		$this->render_status_grid(
			array(
				array(
					'label' => __( 'Certificados activos', 'atora-lms' ),
					'value' => $enabled ? __( 'Sí', 'atora-lms' ) : __( 'No', 'atora-lms' ),
					'state' => $enabled ? 'complete' : 'critical',
				),
				array(
					'label' => __( 'Progreso mínimo', 'atora-lms' ),
					'value' => $min_progress . '%',
					'state' => 'complete',
				),
				array(
					'label' => __( 'Nota mínima', 'atora-lms' ),
					'value' => $min_average . '%',
					'state' => 'complete',
				),
				array(
					'label' => __( 'Curso listo para certificar', 'atora-lms' ),
					'value' => $course_ready ? __( 'Sí', 'atora-lms' ) : __( 'No', 'atora-lms' ),
					'state' => $course_ready ? 'complete' : 'warning',
				),
			)
		);

		echo '<p><a class="button button-secondary" href="' . esc_url( admin_url( 'options-general.php?page=clms-certificates' ) ) . '">' . esc_html__( 'Configurar certificados', 'atora-lms' ) . '</a> ';
		echo '<a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=clms-academic-reports&course_id=' . $course_id ) ) . '">' . esc_html__( 'Ver diagnóstico de certificación', 'atora-lms' ) . '</a></p>';

		if ( ( $required_evidences > 0 || $required_competencies > 0 ) && ! $course_ready ) {
			echo '<p><strong>' . esc_html__( 'Advertencia:', 'atora-lms' ) . '</strong> ' . esc_html__( 'Este curso ya tiene estructura académica, pero la configuración de certificación aún requiere ajustes.', 'atora-lms' ) . '</p>';
		}

		if ( ! empty( $diag['warnings'] ) && is_array( $diag['warnings'] ) ) {
			echo '<ul style="margin-left:16px;list-style:disc">';
			foreach ( array_slice( $diag['warnings'], 0, 8 ) as $warning ) {
				echo '<li>' . esc_html( sanitize_text_field( (string) $warning ) ) . '</li>';
			}
			echo '</ul>';
		}
	}

	/**
	 * Paso 9: página comercial.
	 *
	 * @param int $course_id Curso.
	 * @return void
	 */
	protected function render_step_commercial( $course_id ) {
		$course_id = absint( $course_id );
		$summary   = trim( (string) get_post_meta( $course_id, '_clms_course_excerpt', true ) );
		$benefits  = trim( (string) get_post_meta( $course_id, '_clms_course_benefits', true ) );
		$cta_text  = trim( (string) get_post_meta( $course_id, '_clms_course_cta_text', true ) );
		$cta_url   = trim( (string) get_post_meta( $course_id, '_clms_commercial_cta_url', true ) );
		$cert_text = trim( (string) get_post_meta( $course_id, '_clms_course_certificate', true ) );
		$teacher_ids = get_post_meta( $course_id, '_clms_course_teacher_ids', true );
		$teacher_ids = is_string( $teacher_ids ) ? preg_split( '/\s*,\s*/', trim( $teacher_ids ) ) : $teacher_ids;
		$teacher_ids = is_array( $teacher_ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $teacher_ids ) ) ) ) : array();
		$linked_product_id = absint( get_post_meta( $course_id, '_clms_linked_product_id', true ) );

		$missing = array();
		if ( '' === $summary ) {
			$missing[] = __( 'Resumen comercial', 'atora-lms' );
		}
		if ( '' === $benefits ) {
			$missing[] = __( 'Beneficios del curso', 'atora-lms' );
		}
		if ( empty( $teacher_ids ) ) {
			$missing[] = __( 'Docente visible', 'atora-lms' );
		}
		if ( '' === $cert_text ) {
			$missing[] = __( 'Texto de certificado', 'atora-lms' );
		}
		if ( '' === $cta_text && '' === $cta_url ) {
			$missing[] = __( 'CTA comercial', 'atora-lms' );
		}

		echo '<h2>' . esc_html__( 'Paso 9: Página comercial', 'atora-lms' ) . '</h2>';
		echo '<p>' . esc_html__( 'Valida que la landing del curso tenga narrativa comercial clara y llamada a la acción.', 'atora-lms' ) . '</p>';

		$this->render_status_grid(
			array(
				array(
					'label' => __( 'Resumen', 'atora-lms' ),
					'value' => '' !== $summary ? __( 'Completo', 'atora-lms' ) : __( 'Falta', 'atora-lms' ),
					'state' => '' !== $summary ? 'complete' : 'warning',
				),
				array(
					'label' => __( 'CTA', 'atora-lms' ),
					'value' => ( '' !== $cta_text || '' !== $cta_url ) ? __( 'Completo', 'atora-lms' ) : __( 'Falta', 'atora-lms' ),
					'state' => ( '' !== $cta_text || '' !== $cta_url ) ? 'complete' : 'warning',
				),
				array(
					'label' => __( 'Producto WooCommerce', 'atora-lms' ),
					'value' => $linked_product_id ? sprintf( __( 'Vinculado (#%d)', 'atora-lms' ), $linked_product_id ) : __( 'No vinculado', 'atora-lms' ),
					'state' => $linked_product_id ? 'complete' : 'incomplete',
				),
			)
		);

		echo '<p><a class="button button-secondary" href="' . esc_url( get_edit_post_link( $course_id, '' ) ) . '#clms_course_meta_box">' . esc_html__( 'Editar ficha comercial del curso', 'atora-lms' ) . '</a></p>';

		if ( ! empty( $missing ) ) {
			echo '<p><strong>' . esc_html__( 'Faltan datos clave para vender:', 'atora-lms' ) . '</strong> ' . esc_html( implode( ', ', array_map( 'sanitize_text_field', $missing ) ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'La base comercial del curso está completa para este sprint.', 'atora-lms' ) . '</p>';
		}
	}

	/**
	 * Paso 10: publicación/checklist.
	 *
	 * @param int   $course_id Curso.
	 * @param array $context   Contexto del wizard.
	 * @return void
	 */
	protected function render_step_publish( $course_id, $context = array() ) {
		$course_id = absint( $course_id );
		$context   = is_array( $context ) ? $context : array();
		$diag      = isset( $context['diag'] ) && is_array( $context['diag'] ) ? $context['diag'] : array();

		if ( empty( $diag ) ) {
			$diagnostics_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Diagnostics_Service') : null;
			$diag = ( $diagnostics_service && method_exists( $diagnostics_service, 'diagnose_course' ) ) ? (array) $diagnostics_service->diagnose_course( $course_id ) : array();
		}

		$maturity = isset( $diag['maturity_checklist'] ) && is_array( $diag['maturity_checklist'] ) ? $diag['maturity_checklist'] : array();
		$blocks = array( 'publish', 'sell', 'evaluate', 'certify' );

		echo '<h2>' . esc_html__( 'Paso 10: Publicación y checklist final', 'atora-lms' ) . '</h2>';
		echo '<p>' . esc_html__( 'Revisa estado operativo del curso antes de publicarlo en entorno real.', 'atora-lms' ) . '</p>';

		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px">';
		foreach ( $blocks as $block_key ) {
			$item = isset( $maturity[ $block_key ] ) && is_array( $maturity[ $block_key ] ) ? $maturity[ $block_key ] : array();
			$label = sanitize_text_field( (string) ( $item['label'] ?? $block_key ) );
			$ready = ! empty( $item['ready'] );
			$status = sanitize_key( (string) ( $item['status'] ?? ( $ready ? 'ok' : 'warning' ) ) );
			$missing = isset( $item['missing'] ) && is_array( $item['missing'] ) ? $item['missing'] : array();
			$state = 'ok' === $status ? 'complete' : ( 'error' === $status ? 'critical' : 'warning' );

			echo '<div class="clms-admin-card" style="margin:0;padding:12px;">';
			echo '<p style="margin:0 0 6px 0;"><strong>' . esc_html( $label ) . '</strong> ' . $this->get_state_pill_html( $state ) . '</p>';
			if ( empty( $missing ) ) {
				echo '<p style="margin:0;">' . esc_html__( 'Sin faltantes críticos.', 'atora-lms' ) . '</p>';
			} else {
				echo '<ul style="margin:0 0 0 16px;list-style:disc">';
				foreach ( array_slice( $missing, 0, 4 ) as $miss ) {
					echo '<li>' . esc_html( sanitize_text_field( (string) $miss ) ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</div>';
		}
		echo '</div>';

		$errors = isset( $diag['errors'] ) && is_array( $diag['errors'] ) ? $diag['errors'] : array();
		if ( ! empty( $errors ) ) {
			echo '<p><strong>' . esc_html__( 'Faltantes críticos detectados:', 'atora-lms' ) . '</strong></p><ul style="margin-left:16px;list-style:disc">';
			foreach ( array_slice( $errors, 0, 8 ) as $error ) {
				echo '<li>' . esc_html( sanitize_text_field( (string) $error ) ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<div class="clms-admin-actions" style="margin-top:12px">';
		echo '<a class="button button-primary" href="' . esc_url( get_edit_post_link( $course_id, '' ) ) . '">' . esc_html__( 'Abrir editor para publicar', 'atora-lms' ) . '</a> ';
		echo '<a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=clms-academic-reports&course_id=' . $course_id ) ) . '">' . esc_html__( 'Ver reporte académico', 'atora-lms' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Grid de estados rápidos.
	 *
	 * @param array $items Items.
	 * @return void
	 */
	protected function render_status_grid( $items ) {
		$items = is_array( $items ) ? $items : array();
		if ( empty( $items ) ) {
			return;
		}

		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px;margin:12px 0;">';
		foreach ( $items as $item ) {
			$item  = is_array( $item ) ? $item : array();
			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			$value = isset( $item['value'] ) ? (string) $item['value'] : '';
			$state = sanitize_key( (string) ( $item['state'] ?? 'incomplete' ) );
			if ( '' === $label ) {
				continue;
			}
			echo '<div class="clms-admin-card" style="margin:0;padding:10px;">';
			echo '<p style="margin:0 0 6px 0;"><strong>' . esc_html( $label ) . '</strong> ' . $this->get_state_pill_html( $state ) . '</p>';
			echo '<p style="margin:0;font-size:18px;">' . esc_html( sanitize_text_field( $value ) ) . '</p>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Etiqueta visual de estado.
	 *
	 * @param string $state Estado.
	 * @return string
	 */
	protected function get_state_pill_html( $state ) {
		$state = sanitize_key( (string) $state );
		$map = array(
			'complete'   => array( __( 'Completo', 'atora-lms' ), '#166534', '#dcfce7' ),
			'incomplete' => array( __( 'Incompleto', 'atora-lms' ), '#374151', '#f3f4f6' ),
			'warning'    => array( __( 'Advertencia', 'atora-lms' ), '#92400e', '#fef3c7' ),
			'critical'   => array( __( 'Crítico', 'atora-lms' ), '#991b1b', '#fee2e2' ),
		);

		$item = isset( $map[ $state ] ) ? $map[ $state ] : $map['incomplete'];
		return '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;line-height:1.8;background:' . esc_attr( $item[2] ) . ';color:' . esc_attr( $item[1] ) . ';">' . esc_html( $item[0] ) . '</span>';
	}
}
