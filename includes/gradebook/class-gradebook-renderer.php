<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Gradebook_Renderer {

	/** @var CLMS_Gradebook_Service */
	protected $service;

	/** @var CLMS_Gradebook_Intelligence_Service|null */
	protected $intelligence;

	/** @var CLMS_Gradebook_Certificate_Eligibility_Service|null */
	protected $eligibility;

	public function __construct( $service = null ) {
		$this->service      = $service instanceof CLMS_Gradebook_Service ? $service : new CLMS_Gradebook_Service();
		$this->intelligence = class_exists( 'CLMS_Gradebook_Intelligence_Service' ) ? new CLMS_Gradebook_Intelligence_Service() : null;
		$this->eligibility  = class_exists( 'CLMS_Gradebook_Certificate_Eligibility_Service' ) ? new CLMS_Gradebook_Certificate_Eligibility_Service() : null;
	}

	/**
	 * Renderiza la vista de gradebook tipo Canvas (solo lectura).
	 *
	 * @param array $args Configuración de render.
	 * @return bool
	 */
	public function render( $args = array() ) {
		$args = is_array( $args ) ? $args : array();

		$course_id       = absint( $args['course_id'] ?? 0 );
		$courses         = is_array( $args['courses'] ?? null ) ? $args['courses'] : array();
		$recalculated    = ! empty( $args['recalculated'] );
		$student_search  = sanitize_text_field( (string) ( $args['student_search'] ?? '' ) );
		$activity_search = sanitize_text_field( (string) ( $args['activity_search'] ?? '' ) );
		$status          = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$focus           = sanitize_key( (string) ( $args['focus'] ?? '' ) );
		$group           = sanitize_key( (string) ( $args['group'] ?? '' ) );
		$cohort_id       = absint( $args['cohort_id'] ?? 0 );

		echo '<div class="wrap clms-admin-wrap clms-gradebook-wrap">';
		echo '<h1>' . esc_html__( 'Libro de calificaciones', 'atora-lms' ) . '</h1>';
		if ( 'rubrics' === $focus ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Modo rúbricas activo: usa este flujo para revisar criterios y su impacto en evaluación.', 'atora-lms' ) . '</p></div>';
		}

		if ( $course_id ) {
			echo '<div class="clms-gradebook-course-header atora-stats-row" style="margin-bottom:12px;">';
			echo '<div class="atora-stat-block">';
			echo '<span class="atora-stat-block__label">' . esc_html__( 'Curso', 'atora-lms' ) . '</span>';
			echo '<strong style="font-size:14px;">' . esc_html( get_the_title( $course_id ) ) . '</strong>';
			echo '</div>';

			if ( $cohort_id && 'clms_cohort' === get_post_type( $cohort_id ) ) {
				echo '<div class="atora-stat-block">';
				echo '<span class="atora-stat-block__label">' . esc_html__( 'Grupo / Cohorte', 'atora-lms' ) . '</span>';
				echo '<strong style="font-size:14px;">' . esc_html( get_the_title( $cohort_id ) ) . '</strong>';
				echo '</div>';
			}

			echo '</div>';
		}

		if ( $recalculated ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Libro de calificaciones recalculado correctamente.', 'atora-lms' ) . '</p></div>';
		}

		$this->render_filters( $courses, $course_id, $student_search, $activity_search, $status );

		if ( ! $course_id ) {
			echo '<div class="clms-admin-card"><p>' . esc_html__( 'Selecciona un curso para ver el libro de calificaciones.', 'atora-lms' ) . '</p></div>';
			echo '</div>';
			return true;
		}

		if ( 'lm_course' !== get_post_type( $course_id ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Curso no válido.', 'atora-lms' ) . '</p></div>';
			echo '</div>';
			return true;
		}

		echo '<div class="clms-admin-card atora-card">';
		$recalculate_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=clms_recalculate_course_gradebook&course_id=' . absint( $course_id ) ),
			'clms_recalculate_course_gradebook_' . absint( $course_id )
		);
		echo '<p class="clms-gradebook-cta-row">';
		echo '<a class="button" href="' . esc_url( $recalculate_url ) . '">' . esc_html__( 'Recalcular libro de calificaciones', 'atora-lms' ) . '</a>';
		echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'clms-speedgrader', 'course_id' => $course_id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Ver pendientes / SpeedGrade', 'atora-lms' ) . '</a>';
		echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=clms_rubric' ) ) . '">' . esc_html__( 'Gestionar rúbricas', 'atora-lms' ) . '</a>';
		echo '</p>';
		echo '</div>';

		$grid = $this->service->build_grid(
			$course_id,
			array(
				'student_search'  => $student_search,
				'activity_search' => $activity_search,
				'status'          => $status,
				'group'           => $group,
				'cohort_id'       => $cohort_id,
			)
		);

		$columns = is_array( $grid['columns'] ?? null ) ? $grid['columns'] : array();
		$rows    = is_array( $grid['rows'] ?? null ) ? $grid['rows'] : array();

		if ( empty( $rows ) || empty( $columns ) ) {
			echo '<div class="clms-admin-card"><p>' . esc_html__( 'No hay datos disponibles para los filtros seleccionados.', 'atora-lms' ) . '</p></div>';
			echo '</div>';
			return true;
		}

		if ( $this->intelligence ) {
			$alerts       = $this->intelligence->analyze_grid( $grid );
			$alerts_block = $this->intelligence->render_alerts_block( $alerts );
			if ( $alerts_block ) {
				echo $alerts_block; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}

		echo '<div class="clms-admin-card atora-card clms-gradebook-shell">';
		echo '<div class="clms-gradebook-table-wrap atora-table-wrap">';
		echo '<table class="clms-gradebook-table atora-table">';
		$show_eligibility = $this->eligibility !== null;

		echo '<thead><tr>';
		echo '<th class="is-sticky-col">' . esc_html__( 'Estudiante', 'atora-lms' ) . '</th>';
		foreach ( $columns as $column ) {
			echo '<th>' . esc_html( (string) ( $column['title'] ?? '' ) ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Total', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Progreso', 'atora-lms' ) . '</th>';
		if ( $show_eligibility ) {
			echo '<th>' . esc_html__( 'Certificado', 'atora-lms' ) . '</th>';
		}
		echo '</tr></thead>';

		echo '<tbody>';
		foreach ( $rows as $row ) {
			$cells = is_array( $row['cells'] ?? null ) ? $row['cells'] : array();
			echo '<tr>';
			echo '<td class="is-sticky-col">';
			echo '<div class="clms-gradebook-student">';
			echo '<strong>' . esc_html( (string) ( $row['student_name'] ?? '' ) ) . '</strong>';
			echo '<small>' . esc_html( (string) ( $row['student_email'] ?? '' ) ) . '</small>';
			echo '</div>';
			echo '</td>';

			foreach ( $columns as $column ) {
				$lesson_id = absint( $column['lesson_id'] ?? 0 );
				$cell      = isset( $cells[ $lesson_id ] ) && is_array( $cells[ $lesson_id ] ) ? $cells[ $lesson_id ] : array();
				echo $this->render_cell( $cell, absint( $row['student_id'] ?? 0 ), absint( $column['max_points'] ?? 100 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			echo '<td class="clms-gradebook-total">' . esc_html( absint( $row['total'] ?? 0 ) ) . '%</td>';
			echo '<td class="clms-gradebook-progress">' . esc_html( absint( $row['progress'] ?? 0 ) ) . '%</td>';
			if ( $show_eligibility ) {
				$elig         = $this->eligibility->check_row_eligibility( $row, $columns, $grid['schema'] ?? array(), $course_id );
				$elig_status  = sanitize_key( (string) ( $elig['status'] ?? '' ) );
				$elig_labels  = array(
					'eligible'       => __( 'Elegible', 'atora-lms' ),
					'not_eligible'   => __( 'No elegible', 'atora-lms' ),
					'pending_review' => __( 'Pendiente de revisión', 'atora-lms' ),
					'needs_approval' => __( 'Requiere aprobación', 'atora-lms' ),
				);
				$elig_classes = array(
					'eligible'       => 'atora-badge cert-eligible',
					'not_eligible'   => 'atora-badge cert-not-eligible',
					'pending_review' => 'atora-badge cert-pending-review',
					'needs_approval' => 'atora-badge cert-needs-approval',
				);
				$elig_label = isset( $elig_labels[ $elig_status ] ) ? $elig_labels[ $elig_status ] : esc_html__( '—', 'atora-lms' );
				$elig_class = isset( $elig_classes[ $elig_status ] ) ? $elig_classes[ $elig_status ] : 'atora-badge';
				echo '<td class="clms-gradebook-cert"><span class="' . esc_attr( $elig_class ) . '">' . esc_html( $elig_label ) . '</span></td>';
			}
			echo '</tr>';
		}
		echo '</tbody>';

		echo '</table>';
		echo '</div>';
		echo '</div>';
		echo '</div>';

		return true;
	}

	/**
	 * Renderiza filtros principales de UX (máximo 5 decisiones).
	 */
	protected function render_filters( $courses, $course_id, $student_search, $activity_search, $status ) {
		$cohort_id   = absint( $_GET['cohort_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cohort_opts = array();
		if ( $course_id && class_exists( 'CLMS_Cohort_Service' ) ) {
			$cohort_service = new CLMS_Cohort_Service();
			$visible_ids    = $cohort_service->get_visible_cohort_ids( get_current_user_id() );
			foreach ( $visible_ids as $cid ) {
				$cid = absint( $cid );
				if ( ! $cid ) {
					continue;
				}
				$cohort_courses = $cohort_service->get_cohort_course_ids( $cid );
				if ( in_array( $course_id, array_map( 'absint', $cohort_courses ), true ) ) {
					$cohort_opts[ $cid ] = get_the_title( $cid );
				}
			}
		}

		echo '<div class="clms-admin-card atora-card">';
		echo '<form method="get" class="clms-gradebook-filters atora-filter-row">';
		echo '<input type="hidden" name="page" value="clms-gradebook">';

		echo '<div class="clms-gradebook-filter">';
		echo '<label for="clms-course-id"><strong>' . esc_html__( 'Curso', 'atora-lms' ) . '</strong></label>';
		echo '<select name="course_id" id="clms-course-id">';
		echo '<option value="">' . esc_html__( 'Selecciona un curso', 'atora-lms' ) . '</option>';
		foreach ( $courses as $id => $title ) {
			echo '<option value="' . esc_attr( absint( $id ) ) . '" ' . selected( $course_id, absint( $id ), false ) . '>' . esc_html( (string) $title ) . '</option>';
		}
		echo '</select>';
		echo '</div>';

		echo '<div class="clms-gradebook-filter">';
		echo '<label for="clms-student-search"><strong>' . esc_html__( 'Buscar estudiante', 'atora-lms' ) . '</strong></label>';
		echo '<input id="clms-student-search" type="search" name="student_search" value="' . esc_attr( $student_search ) . '" placeholder="' . esc_attr__( 'Nombre o correo', 'atora-lms' ) . '">';
		echo '</div>';

		echo '<div class="clms-gradebook-filter">';
		echo '<label for="clms-activity-search"><strong>' . esc_html__( 'Buscar actividad', 'atora-lms' ) . '</strong></label>';
		echo '<input id="clms-activity-search" type="search" name="activity_search" value="' . esc_attr( $activity_search ) . '" placeholder="' . esc_attr__( 'Título de actividad', 'atora-lms' ) . '">';
		echo '</div>';

		echo '<div class="clms-gradebook-filter">';
		echo '<label for="clms-status-filter"><strong>' . esc_html__( 'Estado', 'atora-lms' ) . '</strong></label>';
		echo '<select id="clms-status-filter" name="status">';
		echo '<option value="">' . esc_html__( 'Todos', 'atora-lms' ) . '</option>';
		echo '<option value="submitted" ' . selected( $status, 'submitted', false ) . '>' . esc_html__( 'Enviada', 'atora-lms' ) . '</option>';
		echo '<option value="in_review" ' . selected( $status, 'in_review', false ) . '>' . esc_html__( 'En revisión', 'atora-lms' ) . '</option>';
		echo '<option value="needs_revision" ' . selected( $status, 'needs_revision', false ) . '>' . esc_html__( 'Requiere revisión', 'atora-lms' ) . '</option>';
		echo '<option value="returned" ' . selected( $status, 'returned', false ) . '>' . esc_html__( 'Devuelta', 'atora-lms' ) . '</option>';
		echo '<option value="graded" ' . selected( $status, 'graded', false ) . '>' . esc_html__( 'Calificada', 'atora-lms' ) . '</option>';
		echo '</select>';
		echo '</div>';

		if ( ! empty( $cohort_opts ) ) {
			echo '<div class="clms-gradebook-filter">';
			echo '<label for="clms-cohort-filter"><strong>' . esc_html__( 'Grupo / Cohorte', 'atora-lms' ) . '</strong></label>';
			echo '<select id="clms-cohort-filter" name="cohort_id">';
			echo '<option value="">' . esc_html__( 'Todos los grupos', 'atora-lms' ) . '</option>';
			foreach ( $cohort_opts as $cid => $clabel ) {
				echo '<option value="' . esc_attr( absint( $cid ) ) . '" ' . selected( $cohort_id, absint( $cid ), false ) . '>' . esc_html( (string) $clabel ) . '</option>';
			}
			echo '</select>';
			echo '</div>';
		}

		echo '<div class="clms-gradebook-actions">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Aplicar filtros', 'atora-lms' ) . '</button>';
		echo '<button type="button" class="button" data-clms-gradebook-export="csv">' . esc_html__( 'Exportar', 'atora-lms' ) . '</button>';
		echo '</div>';

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renderiza una celda de actividad.
	 *
	 * @param array $cell       Datos de celda.
	 * @param int   $student_id ID del estudiante (para data attributes JS).
	 * @param int   $max_points Puntos máximos de la actividad.
	 * @return string
	 */
	protected function render_cell( $cell, $student_id = 0, $max_points = 100 ) {
		$cell = is_array( $cell ) ? $cell : array();

		$lesson_id     = absint( $cell['lesson_id'] ?? 0 );
		$submission_id = absint( $cell['submission_id'] ?? 0 );
		$rubric_id     = absint( $cell['rubric_id'] ?? 0 );
		$status        = sanitize_key( (string) ( $cell['status'] ?? '' ) );
		$grade         = '' !== (string) ( $cell['grade'] ?? '' ) ? absint( $cell['grade'] ) : null;
		$source        = sanitize_key( (string) ( $cell['source'] ?? '' ) );
		$speedgrade    = (string) ( $cell['speedgrade_url'] ?? '' );
		$cell_key      = absint( $student_id ) . '_' . $lesson_id;
		$class_names   = array( 'clms-gradebook-cell' );

		if ( ! empty( $cell['missing'] ) ) {
			$class_names[] = 'is-missing';
		}
		if ( in_array( $status, array( 'submitted', 'in_review', 'returned', 'needs_revision' ), true ) ) {
			$class_names[] = 'is-pending';
		}
		if ( 'graded' === $status ) {
			$class_names[] = 'is-graded';
		}
		if ( ! empty( $cell['needs_review'] ) ) {
			$class_names[] = 'needs-review';
		}
		if ( $rubric_id ) {
			$class_names[] = 'has-rubric';
			if ( empty( $cell['rubric_completed'] ) && $submission_id ) {
				$class_names[] = 'rubric-incomplete';
			}
		}
		if ( ! empty( $cell['manual_override'] ) ) {
			$class_names[] = 'manual-override';
		}
		if ( null !== $grade && $grade >= 60 ) {
			$class_names[] = 'is-approved';
		}
		if ( null !== $grade && $grade < 60 ) {
			$class_names[] = 'is-failed';
		}

		$rubric_criteria_count  = absint( $cell['rubric_criteria_count'] ?? 0 );
		$rubric_criteria_scored = absint( $cell['rubric_criteria_scored'] ?? 0 );
		$rubric_completed       = ! empty( $cell['rubric_completed'] );
		$manual_override        = ! empty( $cell['manual_override'] );

		ob_start();
		?>
		<td
			class="<?php echo esc_attr( implode( ' ', $class_names ) ); ?>"
			data-clms-cell="<?php echo esc_attr( $cell_key ); ?>"
			data-clms-student-id="<?php echo esc_attr( absint( $student_id ) ); ?>"
			data-clms-lesson-id="<?php echo esc_attr( $lesson_id ); ?>"
			data-clms-submission-id="<?php echo esc_attr( $submission_id ); ?>"
			data-clms-max-points="<?php echo esc_attr( $max_points ); ?>"
		>
			<div class="clms-gradebook-cell__main" data-grade="<?php echo esc_attr( null !== $grade ? (string) $grade : '' ); ?>">
				<?php echo null === $grade ? esc_html__( '—', 'atora-lms' ) : esc_html( $grade . '%' ); ?>
			</div>
			<div class="clms-gradebook-cell__indicators">
				<?php if ( $rubric_id ) : ?>
					<span class="clms-cell-indicator is-rubric" title="<?php
						$rubric_status_text = $rubric_completed
							? esc_attr__( 'Rúbrica completa', 'atora-lms' )
							: sprintf( esc_attr__( 'Rúbrica: %d/%d criterios', 'atora-lms' ), $rubric_criteria_scored, $rubric_criteria_count );
						echo $rubric_status_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>">&#x1F4CB;</span>
				<?php endif; ?>
				<?php if ( $manual_override ) : ?>
					<span class="clms-cell-indicator is-manual" title="<?php esc_attr_e( 'Override manual', 'atora-lms' ); ?>">&#x270E;</span>
				<?php endif; ?>
				<?php if ( ! empty( $cell['needs_review'] ) ) : ?>
					<span class="clms-cell-indicator needs-review" title="<?php esc_attr_e( 'Requiere revisión', 'atora-lms' ); ?>">&#x26A0;</span>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $speedgrade ) ) : ?>
				<a class="clms-gradebook-cell__action" href="<?php echo esc_url( $speedgrade ); ?>"><?php esc_html_e( 'SpeedGrade', 'atora-lms' ); ?></a>
			<?php endif; ?>
			<?php if ( $submission_id ) : ?>
				<button
					class="clms-gradebook-cell__detail"
					data-clms-cell-detail="1"
					data-clms-submission-id="<?php echo esc_attr( $submission_id ); ?>"
					data-clms-lesson-id="<?php echo esc_attr( $lesson_id ); ?>"
					data-clms-student-id="<?php echo esc_attr( absint( $student_id ) ); ?>"
					style="background:none;border:none;cursor:pointer;font-size:10px;color:var(--atora-muted);display:block;margin-top:2px;"
					title="<?php esc_attr_e( 'Ver detalle', 'atora-lms' ); ?>"
				>&#x2139;</button>
			<?php endif; ?>
		</td>
		<?php
		return ob_get_clean();
	}
}
