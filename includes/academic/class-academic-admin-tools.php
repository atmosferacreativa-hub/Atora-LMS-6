<?php
/**
 * Herramientas admin: wizard académico y reportes iniciales.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Academic_Admin_Tools {

	const WIZARD_PAGE  = 'clms-academic-wizard';
	const REPORTS_PAGE = 'clms-academic-reports';
	const POST_ACTION  = 'clms_save_academic_wizard';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_pages' ) );
		add_action( 'admin_post_' . self::POST_ACTION, array( $this, 'handle_wizard_save' ) );
		add_action( 'admin_post_clms_export_academic_report', array( $this, 'handle_export_report' ) );
	}

	/**
	 * Registra páginas admin.
	 *
	 * @return void
	 */
	public function register_pages() {
		// Páginas ocultas del sidebar: accesibles por URL directa.
		add_submenu_page(
			'',
			__( 'Asistente Académico', 'atora-lms' ),
			__( 'Asistente Académico', 'atora-lms' ),
			'clms_manage_courses',
			self::WIZARD_PAGE,
			array( $this, 'render_wizard_page' )
		);

		add_submenu_page(
			'',
			__( 'Reportes Académicos', 'atora-lms' ),
			__( 'Reportes Académicos', 'atora-lms' ),
			'clms_view_teacher_dashboard',
			self::REPORTS_PAGE,
			array( $this, 'render_reports_page' )
		);
	}

	/**
	 * Render de wizard académico.
	 *
	 * @return void
	 */
	public function render_wizard_page() {
		if ( ! $this->can_manage_academic_setup() ) {
			wp_die( esc_html__( 'No tienes permisos para usar el asistente académico.', 'atora-lms' ) );
		}

		$wizard_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Wizard_Service') : null;
		$diagnostics_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Diagnostics_Service') : null;
		$competency_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Competency_Service') : null;
		$evidence_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;

		$requested_course_id  = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		$requested_program_id = isset( $_GET['program_id'] ) ? absint( wp_unslash( $_GET['program_id'] ) ) : 0;
		$wizard_steps = $this->get_wizard_steps();
		$max_steps = count( $wizard_steps );
		$step = isset( $_GET['step'] ) ? max( 1, min( $max_steps, absint( wp_unslash( $_GET['step'] ) ) ) ) : 1;

		if ( $requested_course_id && 'lm_course' !== get_post_type( $requested_course_id ) ) {
			$requested_course_id = 0;
		}
		if ( $requested_program_id && 'lm_program' !== get_post_type( $requested_program_id ) ) {
			$requested_program_id = 0;
		}

		$available_courses  = $this->get_wizard_accessible_posts( 'lm_course' );
		$available_programs = $this->get_wizard_accessible_posts( 'lm_program' );
		$available_course_ids = array_map(
			static function ( $row ) {
				return absint( $row['id'] ?? 0 );
			},
			$available_courses
		);
		$available_program_ids = array_map(
			static function ( $row ) {
				return absint( $row['id'] ?? 0 );
			},
			$available_programs
		);

		if ( $requested_course_id && ! in_array( $requested_course_id, $available_course_ids, true ) ) {
			$requested_course_id = 0;
		}
		if ( $requested_program_id && ! in_array( $requested_program_id, $available_program_ids, true ) ) {
			$requested_program_id = 0;
		}

		if ( ! $requested_course_id && $requested_program_id && class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_program_courses' ) ) {
			$program_courses = array_values( array_filter( array_map( 'absint', (array) CLMS_Helper::get_program_courses( $requested_program_id ) ) ) );
			foreach ( $program_courses as $program_course_id ) {
				if ( in_array( $program_course_id, $available_course_ids, true ) ) {
					$requested_course_id = $program_course_id;
					break;
				}
			}
		}

		$progress_state = array();
		if ( $wizard_service && method_exists( $wizard_service, 'get_progress_state' ) && $requested_course_id ) {
			$progress_state = (array) $wizard_service->get_progress_state( get_current_user_id(), $requested_course_id );
			if ( empty( $_GET['step'] ) && ! empty( $progress_state['current_step'] ) ) {
				$step = max( 1, min( $max_steps, absint( $progress_state['current_step'] ) ) );
			}
		}

		$course_title = $requested_course_id ? get_the_title( $requested_course_id ) : '';
		$course_subtitle = $requested_course_id ? (string) get_post_meta( $requested_course_id, '_clms_course_subtitle', true ) : '';
		$objective_general = $requested_course_id ? (string) get_post_meta( $requested_course_id, '_clms_course_objective_general', true ) : '';
		$competencies_lines = '';
		if ( $requested_course_id && $competency_service && method_exists( $competency_service, 'get_course_competencies' ) ) {
			$competencies = (array) $competency_service->get_course_competencies( $requested_course_id );
			$titles = array();
			foreach ( $competencies as $competency ) {
				$competency = is_array( $competency ) ? $competency : array();
				$title = sanitize_text_field( (string) ( $competency['title'] ?? '' ) );
				if ( '' !== $title ) {
					$titles[] = $title;
				}
			}
			$competencies_lines = implode( "\n", $titles );
		}

		$diag = array();
		if ( $requested_course_id && $diagnostics_service && method_exists( $diagnostics_service, 'diagnose_course' ) ) {
			$diag = (array) $diagnostics_service->diagnose_course( $requested_course_id );
		}
		$warnings = isset( $diag['warnings'] ) && is_array( $diag['warnings'] ) ? $diag['warnings'] : array();
		$checklist = isset( $diag['checklist'] ) && is_array( $diag['checklist'] ) ? $diag['checklist'] : array();
		$maturity = isset( $diag['maturity_checklist'] ) && is_array( $diag['maturity_checklist'] ) ? $diag['maturity_checklist'] : array();
		$errors = isset( $diag['errors'] ) && is_array( $diag['errors'] ) ? $diag['errors'] : array();

		echo '<div class="wrap clms-admin-wrap">';
		echo '<h1>' . esc_html__( 'Asistente Académico de Curso', 'atora-lms' ) . '</h1>';
		echo '<p>' . esc_html__( 'Configura tu curso en pasos claros sin perder compatibilidad con la edición clásica.', 'atora-lms' ) . '</p>';

		$this->render_wizard_admin_notice();
		$this->render_wizard_scope_selector( $requested_course_id, $requested_program_id, $available_courses, $available_programs, $step );
		$this->render_wizard_stepper( $wizard_steps, $step, $requested_course_id );

		echo '<div class="clms-admin-card">';
		echo '<p><strong>' . esc_html__( 'Curso activo:', 'atora-lms' ) . '</strong> ' . esc_html( $requested_course_id ? $course_title : __( 'Ninguno', 'atora-lms' ) ) . '</p>';
		echo '<p>' . esc_html__( 'Paso actual:', 'atora-lms' ) . ' ' . esc_html( (string) $step ) . '/' . esc_html( (string) $max_steps ) . '</p>';
		if ( ! empty( $maturity['summary'] ) ) {
			$summary = is_array( $maturity['summary'] ) ? $maturity['summary'] : array();
			$status = sanitize_key( (string) ( $summary['status'] ?? 'warning' ) );
			$label = 'warning' === $status ? __( 'Requiere ajustes', 'atora-lms' ) : ( 'error' === $status ? __( 'Crítico', 'atora-lms' ) : __( 'Saludable', 'atora-lms' ) );
			echo '<p><strong>' . esc_html__( 'Estado general del curso:', 'atora-lms' ) . '</strong> ' . esc_html( $label ) . '</p>';
		}
		if ( ! empty( $errors ) ) {
			echo '<p><strong>' . esc_html__( 'Faltantes críticos:', 'atora-lms' ) . '</strong> ' . esc_html( (string) count( $errors ) ) . '</p>';
		}
		if ( ! empty( $warnings ) ) {
			echo '<p><strong>' . esc_html__( 'Mejoras recomendadas:', 'atora-lms' ) . '</strong> ' . esc_html( (string) count( $warnings ) ) . '</p>';
		}
		if ( $requested_course_id ) {
			echo '<p><a class="button" href="' . esc_url( get_edit_post_link( $requested_course_id, '' ) ) . '">' . esc_html__( 'Abrir edición completa del curso', 'atora-lms' ) . '</a></p>';
		}
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="clms-admin-card">';
		wp_nonce_field( self::POST_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::POST_ACTION ) . '">';
		echo '<input type="hidden" name="course_id" value="' . esc_attr( (string) $requested_course_id ) . '">';
		echo '<input type="hidden" name="program_id" value="' . esc_attr( (string) $requested_program_id ) . '">';
		echo '<input type="hidden" name="step" value="' . esc_attr( (string) $step ) . '">';

		if ( 1 === $step ) {
			echo '<h2>' . esc_html__( 'Paso 1: Información básica', 'atora-lms' ) . '</h2>';
			echo '<p>' . esc_html__( 'Define nombre, subtítulo y objetivo general del curso.', 'atora-lms' ) . '</p>';
			echo '<p><label><strong>' . esc_html__( 'Título del curso', 'atora-lms' ) . '</strong><br>';
			echo '<input type="text" name="wizard_title" class="regular-text" value="' . esc_attr( $course_title ) . '"></label></p>';
			echo '<p><label><strong>' . esc_html__( 'Subtítulo', 'atora-lms' ) . '</strong><br>';
			echo '<input type="text" name="wizard_subtitle" class="regular-text" value="' . esc_attr( $course_subtitle ) . '"></label></p>';
			echo '<p><label><strong>' . esc_html__( 'Objetivo general', 'atora-lms' ) . '</strong><br>';
			echo '<textarea name="wizard_objective_general" rows="4" class="large-text">' . esc_textarea( $objective_general ) . '</textarea></label></p>';
		} elseif ( 2 === $step ) {
			echo '<h2>' . esc_html__( 'Paso 2: Objetivos y competencias', 'atora-lms' ) . '</h2>';
			echo '<p>' . esc_html__( 'Registra competencias una por línea para habilitar seguimiento por evidencia y certificación.', 'atora-lms' ) . '</p>';
			echo '<p><label><strong>' . esc_html__( 'Competencias', 'atora-lms' ) . '</strong><br>';
			echo '<textarea name="wizard_competencies" rows="8" class="large-text" placeholder="' . esc_attr__( "Comunicación visual aplicada\nStorytelling visual\nAnálisis crítico", 'atora-lms' ) . '">' . esc_textarea( $competencies_lines ) . '</textarea></label></p>';
			echo '<p><label><input type="checkbox" name="wizard_mark_required" value="1"> ' . esc_html__( 'Marcar todas como requeridas en este primer guardado', 'atora-lms' ) . '</label></p>';
			echo '<p><label>' . esc_html__( 'Peso por defecto (%)', 'atora-lms' ) . ' <input type="number" min="0" max="100" step="1" name="wizard_default_weight" value="20"></label></p>';
			$competency_catalog = $this->get_wizard_competency_catalog( $requested_course_id, $competency_service );
			$coverage           = $this->get_wizard_competency_coverage( $requested_course_id, $competency_catalog, $evidence_service );
			if ( ! empty( $competency_catalog ) ) {
				echo '<p><strong>' . esc_html__( 'Selector visual de competencias del curso', 'atora-lms' ) . '</strong></p>';
				echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px">';
				foreach ( $competency_catalog as $item ) {
					$item = is_array( $item ) ? $item : array();
					$title = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
					if ( '' === $title ) {
						continue;
					}
					$id = sanitize_key( (string) ( $item['id'] ?? sanitize_title( $title ) ) );
					$total_refs = absint( $coverage[ $id ]['total'] ?? 0 );
					$required_refs = absint( $coverage[ $id ]['required'] ?? 0 );
					echo '<label style="display:block;border:1px solid #dcdcde;border-radius:6px;padding:8px;background:#fff">';
					echo '<input type="checkbox" name="wizard_competency_selected_titles[]" value="' . esc_attr( $title ) . '" checked> ';
					echo '<strong>' . esc_html( $title ) . '</strong><br>';
					echo '<span class="description">' . esc_html( sprintf( __( 'Evidencias: %1$d · Obligatorias: %2$d', 'atora-lms' ), $total_refs, $required_refs ) ) . '</span>';
					if ( 0 === $total_refs ) {
						echo '<br><span style="color:#a16207">' . esc_html__( 'Sin evidencia asociada todavía.', 'atora-lms' ) . '</span>';
					}
					echo '</label>';
				}
				echo '</div>';
			}
		} elseif ( 3 === $step ) {
			echo '<h2>' . esc_html__( 'Paso 3: Revisión académica y certificación', 'atora-lms' ) . '</h2>';
			echo '<p>' . esc_html__( 'Revisa advertencias antes de publicar y certificar.', 'atora-lms' ) . '</p>';
			if ( empty( $checklist ) ) {
				echo '<p>' . esc_html__( 'Aún no hay diagnóstico disponible para este curso.', 'atora-lms' ) . '</p>';
			} else {
				echo '<ul style="margin-left:16px;list-style:disc">';
				foreach ( $checklist as $item ) {
					$item = is_array( $item ) ? $item : array();
					$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
					$value = sanitize_text_field( (string) ( $item['value'] ?? '' ) );
					if ( '' === $label ) {
						continue;
					}
					echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</li>';
				}
				echo '</ul>';
			}
			if ( ! empty( $warnings ) ) {
				echo '<p><strong>' . esc_html__( 'Advertencias detectadas', 'atora-lms' ) . '</strong></p><ul style="margin-left:16px;list-style:disc">';
				foreach ( array_slice( $warnings, 0, 10 ) as $warning ) {
					echo '<li>' . esc_html( sanitize_text_field( (string) $warning ) ) . '</li>';
				}
				echo '</ul>';
			}
			if ( $requested_course_id ) {
				echo '<p><a class="button button-secondary" href="' . esc_url( admin_url( 'post.php?post=' . $requested_course_id . '&action=edit' ) ) . '">' . esc_html__( 'Ir al editor de curso', 'atora-lms' ) . '</a> ';
				echo '<a class="button button-secondary" href="' . esc_url( admin_url( 'edit.php?post_type=lm_lesson' ) ) . '">' . esc_html__( 'Configurar evidencias y rúbricas', 'atora-lms' ) . '</a></p>';
			}
		} else {
			$current = isset( $wizard_steps[ $step ] ) ? $wizard_steps[ $step ] : array();
			$label = isset( $current['label'] ) ? sanitize_text_field( (string) $current['label'] ) : __( 'Paso guiado', 'atora-lms' );
			$description = isset( $current['description'] ) ? sanitize_text_field( (string) $current['description'] ) : __( 'Sigue este paso desde la edición clásica.', 'atora-lms' );
			echo '<h2>' . esc_html( sprintf( __( 'Paso %1$d: %2$s', 'atora-lms' ), $step, $label ) ) . '</h2>';
			echo '<p>' . esc_html( $description ) . '</p>';
			$step_renderer = $this->get_wizard_step_renderer();
			$rendered      = false;
			if ( $step_renderer && method_exists( $step_renderer, 'render_step' ) ) {
				$rendered = (bool) $step_renderer->render_step(
					$step,
					$requested_course_id,
					array(
						'wizard_steps' => $wizard_steps,
						'diag'         => $diag,
						'warnings'     => $warnings,
						'errors'       => $errors,
						'checklist'    => $checklist,
						'maturity'     => $maturity,
					)
				);
			}
			if ( ! $rendered ) {
				echo '<p>' . esc_html__( 'Este paso está disponible como guía operativa en este sprint. Usa los accesos rápidos para completarlo sin romper el flujo actual de WordPress.', 'atora-lms' ) . '</p>';
				$this->render_wizard_quick_actions( $requested_course_id, $step );
			}
		}

		echo '<p>';
		if ( $step > 1 ) {
			echo '<button type="submit" name="wizard_nav" value="prev" class="button">' . esc_html__( 'Paso anterior', 'atora-lms' ) . '</button> ';
		}
		echo '<button type="submit" name="wizard_nav" value="save" class="button button-primary">' . esc_html__( 'Guardar', 'atora-lms' ) . '</button> ';
		if ( $step < $max_steps ) {
			echo '<button type="submit" name="wizard_nav" value="next" class="button button-secondary">' . esc_html__( 'Guardar y continuar', 'atora-lms' ) . '</button>';
		}
		echo '</p>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render reportes académicos.
	 *
	 * @return void
	 */
	public function render_reports_page() {
		if ( ! $this->can_view_reports() ) {
			wp_die( esc_html__( 'No tienes permisos para ver reportes académicos.', 'atora-lms' ) );
		}

		$report_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Report_Service') : null;
		if ( ! $report_service ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Reportes Académicos', 'atora-lms' ) . '</h1><p>' . esc_html__( 'Servicio de reportes no disponible.', 'atora-lms' ) . '</p></div>';
			return;
		}

		$current_user_id = get_current_user_id();
		$selected_course = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		$selected_student = isset( $_GET['student_id'] ) ? absint( wp_unslash( $_GET['student_id'] ) ) : 0;
		$teacher_report  = method_exists( $report_service, 'get_teacher_report' ) ? (array) $report_service->get_teacher_report( $current_user_id, $selected_course ) : array();
		$teacher_profile = method_exists( $report_service, 'get_teacher_profile_report' ) ? (array) $report_service->get_teacher_profile_report( $current_user_id ) : array();
		$course_report   = $selected_course && method_exists( $report_service, 'get_course_advanced_report' ) ? (array) $report_service->get_course_advanced_report( $selected_course, $current_user_id ) : array();
		$cert_report     = method_exists( $report_service, 'get_certification_report' ) ? (array) $report_service->get_certification_report( $selected_course ) : array();
		$student_profile = $selected_student && method_exists( $report_service, 'get_student_profile_report' ) ? (array) $report_service->get_student_profile_report( $selected_student, $current_user_id ) : array();
		$admin_report    = array();
		$is_admin_view   = current_user_can( 'clms_access_admin' ) || current_user_can( 'manage_options' );
		if ( $is_admin_view && method_exists( $report_service, 'get_admin_report' ) ) {
			$admin_report = (array) $report_service->get_admin_report();
		}
		$report_notice = isset( $_GET['report_notice'] ) ? sanitize_key( wp_unslash( $_GET['report_notice'] ) ) : '';
		$metric_average = absint( $teacher_report['average_course_grade'] ?? 0 );
		$metric_risk = absint( $teacher_report['students_at_risk'] ?? 0 );
		$metric_pending = absint( $teacher_report['pending_reviews'] ?? 0 );
		$metric_near_cert = absint( $teacher_report['students_near_certificate'] ?? 0 );
		$metric_completion = absint(
			$course_report['completion_rate']
			?? $admin_report['completion_rate']
			?? 0
		);
		$metric_cert_rate = absint(
			$course_report['certification_rate']
			?? $admin_report['certification_rate']
			?? 0
		);

		echo '<div class="wrap clms-admin-wrap">';
		echo '<style>
		.clms-admin-wrap .clms-report-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin:18px 0 22px}
		.clms-admin-wrap .clms-report-kpi{border:1px solid #dbe3ff;background:#fff;border-radius:16px;padding:14px 14px 12px;display:grid;gap:8px;box-shadow:0 6px 18px rgba(15,23,42,.04)}
		.clms-admin-wrap .clms-report-kpi__label{font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:#475569;font-weight:700}
		.clms-admin-wrap .clms-report-kpi__value{font-size:30px;line-height:1;font-weight:800;color:#0f172a}
		.clms-admin-wrap .clms-report-kpi__bar{height:8px;border-radius:999px;background:#e2e8f0;overflow:hidden}
		.clms-admin-wrap .clms-report-kpi__bar span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#6366f1,#4338ca)}
		.clms-admin-wrap .clms-report-chart{display:grid;gap:10px}
		.clms-admin-wrap .clms-report-chart__row{display:grid;grid-template-columns:minmax(150px,220px) 1fr auto;gap:10px;align-items:center}
		.clms-admin-wrap .clms-report-chart__row strong{font-size:13px;color:#334155}
		.clms-admin-wrap .clms-report-chart__track{height:11px;border-radius:999px;background:#e2e8f0;overflow:hidden}
		.clms-admin-wrap .clms-report-chart__track span{display:block;height:100%;background:linear-gradient(90deg,#60a5fa,#2563eb)}
		.clms-admin-wrap .clms-report-chip{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px;font-weight:700}
		.clms-admin-wrap .clms-admin-card{border-radius:16px}
		.clms-admin-wrap .clms-admin-card p{margin:0 0 10px;line-height:1.5}
		.clms-admin-wrap .clms-admin-card p:last-child{margin-bottom:0}
		</style>';
		echo '<h1>' . esc_html__( 'Reportes Académicos Iniciales', 'atora-lms' ) . '</h1>';
		echo '<p>' . esc_html__( 'Estos reportes te ayudan a priorizar revisión, refuerzo académico y certificación.', 'atora-lms' ) . '</p>';
		if ( 'export_error' === $report_notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'No se pudo generar el exportable solicitado.', 'atora-lms' ) . '</p></div>';
		}

		echo '<div class="clms-report-kpi-grid">';
		echo '<div class="clms-report-kpi"><span class="clms-report-kpi__label">' . esc_html__( 'Promedio del curso', 'atora-lms' ) . '</span><strong class="clms-report-kpi__value">' . esc_html( $metric_average ) . '%</strong><div class="clms-report-kpi__bar"><span style="width:' . esc_attr( min( 100, max( 0, $metric_average ) ) ) . '%"></span></div></div>';
		echo '<div class="clms-report-kpi"><span class="clms-report-kpi__label">' . esc_html__( 'Estudiantes en riesgo', 'atora-lms' ) . '</span><strong class="clms-report-kpi__value">' . esc_html( $metric_risk ) . '</strong><div class="clms-report-kpi__bar"><span style="width:' . esc_attr( min( 100, $metric_risk * 10 ) ) . '%"></span></div></div>';
		echo '<div class="clms-report-kpi"><span class="clms-report-kpi__label">' . esc_html__( 'Entregas pendientes', 'atora-lms' ) . '</span><strong class="clms-report-kpi__value">' . esc_html( $metric_pending ) . '</strong><div class="clms-report-kpi__bar"><span style="width:' . esc_attr( min( 100, $metric_pending * 10 ) ) . '%"></span></div></div>';
		echo '<div class="clms-report-kpi"><span class="clms-report-kpi__label">' . esc_html__( 'Próximos a certificar', 'atora-lms' ) . '</span><strong class="clms-report-kpi__value">' . esc_html( $metric_near_cert ) . '</strong><div class="clms-report-kpi__bar"><span style="width:' . esc_attr( min( 100, $metric_near_cert * 10 ) ) . '%"></span></div></div>';
		echo '</div>';

		echo '<div class="clms-admin-card clms-report-chart">';
		echo '<h2>' . esc_html__( 'Lectura gráfica rápida', 'atora-lms' ) . '</h2>';
		echo '<div class="clms-report-chart__row"><strong>' . esc_html__( 'Promedio académico', 'atora-lms' ) . '</strong><div class="clms-report-chart__track"><span style="width:' . esc_attr( min( 100, max( 0, $metric_average ) ) ) . '%"></span></div><span class="clms-report-chip">' . esc_html( $metric_average ) . '%</span></div>';
		echo '<div class="clms-report-chart__row"><strong>' . esc_html__( 'Finalización', 'atora-lms' ) . '</strong><div class="clms-report-chart__track"><span style="width:' . esc_attr( min( 100, max( 0, $metric_completion ) ) ) . '%"></span></div><span class="clms-report-chip">' . esc_html( $metric_completion ) . '%</span></div>';
		echo '<div class="clms-report-chart__row"><strong>' . esc_html__( 'Certificación', 'atora-lms' ) . '</strong><div class="clms-report-chart__track"><span style="width:' . esc_attr( min( 100, max( 0, $metric_cert_rate ) ) ) . '%"></span></div><span class="clms-report-chip">' . esc_html( $metric_cert_rate ) . '%</span></div>';
		echo '</div>';

		echo '<div class="clms-admin-card">';
		echo '<h2>' . esc_html__( 'Resumen docente', 'atora-lms' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Competencia más débil:', 'atora-lms' ) . '</strong> ' . esc_html( (string) ( $teacher_report['weakest_competency'] ?? __( 'Sin datos suficientes', 'atora-lms' ) ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Competencia más fuerte:', 'atora-lms' ) . '</strong> ' . esc_html( (string) ( $teacher_report['strongest_competency'] ?? __( 'Sin datos suficientes', 'atora-lms' ) ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Evidencia con más fallos:', 'atora-lms' ) . '</strong> ' . esc_html( (string) ( $teacher_report['evidence_with_most_fail'] ?? __( 'Sin datos suficientes', 'atora-lms' ) ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Módulo/curso con mayor abandono:', 'atora-lms' ) . '</strong> ' . esc_html( (string) ( $teacher_report['module_with_more_dropoff'] ?? __( 'Sin datos suficientes', 'atora-lms' ) ) ) . '</p>';
		echo '</div>';

		echo '<div class="clms-admin-card">';
		echo '<h2>' . esc_html__( 'Perfil docente institucional', 'atora-lms' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Cursos impartidos:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $teacher_profile['courses_taught'] ?? 0 ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Estudiantes activos:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $teacher_profile['students_active'] ?? 0 ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Entregas revisadas:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $teacher_profile['reviewed_submissions'] ?? 0 ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Carga de revisión pendiente:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $teacher_profile['review_load'] ?? 0 ) ) . '</p>';
		if ( isset( $teacher_profile['avg_response_hours'] ) && null !== $teacher_profile['avg_response_hours'] ) {
			echo '<p><strong>' . esc_html__( 'Tiempo de respuesta estimado:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $teacher_profile['avg_response_hours'] ) ) . 'h</p>';
		}
		echo '</div>';

		if ( $selected_course && ! empty( $course_report ) ) {
			echo '<div class="clms-admin-card">';
			echo '<h2>' . esc_html__( 'Reporte avanzado por curso', 'atora-lms' ) . '</h2>';
			echo '<p><strong>' . esc_html__( 'Curso:', 'atora-lms' ) . '</strong> ' . esc_html( (string) ( $course_report['course_title'] ?? '' ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Estudiantes inscritos:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $course_report['students_enrolled'] ?? 0 ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Activos / inactivos:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $course_report['students_active'] ?? 0 ) ) . ' / ' . esc_html( absint( $course_report['students_inactive'] ?? 0 ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Avance promedio:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $course_report['average_progress'] ?? 0 ) ) . '%</p>';
			echo '<p><strong>' . esc_html__( 'Promedio de calificaciones:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $course_report['average_grade'] ?? 0 ) ) . '%</p>';
			echo '<p><strong>' . esc_html__( 'Tasa de finalización / certificación:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $course_report['completion_rate'] ?? 0 ) ) . '% / ' . esc_html( absint( $course_report['certification_rate'] ?? 0 ) ) . '%</p>';
			echo '<p><strong>' . esc_html__( 'Entregas pendientes / vencidas:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $course_report['pending_submissions'] ?? 0 ) ) . ' / ' . esc_html( absint( $course_report['late_submissions'] ?? 0 ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Competencia más fuerte / débil:', 'atora-lms' ) . '</strong> ' . esc_html( (string) ( $course_report['strongest_competency'] ?? __( 'Sin datos', 'atora-lms' ) ) ) . ' / ' . esc_html( (string) ( $course_report['weakest_competency'] ?? __( 'Sin datos', 'atora-lms' ) ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Evidencia con más fallos:', 'atora-lms' ) . '</strong> ' . esc_html( (string) ( $course_report['evidence_with_most_fail'] ?? __( 'Sin datos', 'atora-lms' ) ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Actividad con más abandono:', 'atora-lms' ) . '</strong> ' . esc_html( (string) ( $course_report['activity_with_more_dropoff'] ?? __( 'Sin datos', 'atora-lms' ) ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Estudiantes en riesgo:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $course_report['students_at_risk'] ?? 0 ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Próximos a certificar:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $course_report['students_near_certification'] ?? 0 ) ) . '</p>';
			echo '</div>';
		}

		echo '<div class="clms-admin-card">';
		echo '<h2>' . esc_html__( 'Reporte de certificación', 'atora-lms' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Emitidos / válidos / revocados:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $cert_report['issued'] ?? 0 ) ) . ' / ' . esc_html( absint( $cert_report['valid'] ?? 0 ) ) . ' / ' . esc_html( absint( $cert_report['revoked'] ?? 0 ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Elegibles sin emisión:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $cert_report['eligible_without_issue'] ?? 0 ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Próximos a certificar:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $cert_report['students_near_certification'] ?? 0 ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Cursos listos / incompletos para certificar:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $cert_report['courses_ready_count'] ?? 0 ) ) . ' / ' . esc_html( absint( $cert_report['courses_incomplete_count'] ?? 0 ) ) . '</p>';
		echo '</div>';

		if ( ! empty( $student_profile ) ) {
			echo '<div class="clms-admin-card">';
			echo '<h2>' . esc_html__( 'Perfil académico del estudiante', 'atora-lms' ) . '</h2>';
			echo '<p><strong>' . esc_html__( 'Estudiante:', 'atora-lms' ) . '</strong> ' . esc_html( (string) ( $student_profile['student_name'] ?? '' ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Cursos activos / completados:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $student_profile['courses_active'] ?? 0 ) ) . ' / ' . esc_html( absint( $student_profile['courses_completed'] ?? 0 ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Progreso global / promedio global:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $student_profile['progress_global'] ?? 0 ) ) . '% / ' . esc_html( absint( $student_profile['average_global'] ?? 0 ) ) . '%</p>';
			echo '<p><strong>' . esc_html__( 'Evidencias aprobadas / pendientes:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $student_profile['evidences_approved'] ?? 0 ) ) . ' / ' . esc_html( absint( $student_profile['evidences_pending'] ?? 0 ) ) . '</p>';
			$platform_activity = isset( $student_profile['platform_activity'] ) && is_array( $student_profile['platform_activity'] ) ? $student_profile['platform_activity'] : array();
			$last_access       = sanitize_text_field( (string) ( $platform_activity['last_access'] ?? '' ) );
			$total_seconds     = absint( $platform_activity['total_time_seconds'] ?? 0 );
			if ( '' !== $last_access ) {
				echo '<p><strong>' . esc_html__( 'Último acceso en plataforma:', 'atora-lms' ) . '</strong> ' . esc_html( $last_access ) . '</p>';
			}
			if ( $total_seconds > 0 ) {
				echo '<p><strong>' . esc_html__( 'Tiempo acumulado en plataforma:', 'atora-lms' ) . '</strong> ' . esc_html( gmdate( 'H:i:s', $total_seconds ) ) . '</p>';
			}
			$risk = isset( $student_profile['risk'] ) && is_array( $student_profile['risk'] ) ? $student_profile['risk'] : array();
			$risk_level = sanitize_key( (string) ( $risk['risk_level'] ?? 'unknown' ) );
			$risk_labels = array(
				'high'    => __( 'Alto', 'atora-lms' ),
				'medium'  => __( 'Medio', 'atora-lms' ),
				'low'     => __( 'Bajo', 'atora-lms' ),
				'normal'  => __( 'Bajo', 'atora-lms' ),
				'unknown' => __( 'Sin datos', 'atora-lms' ),
			);
			$risk_label = isset( $risk_labels[ $risk_level ] ) ? $risk_labels[ $risk_level ] : $risk_labels['unknown'];
			echo '<p><strong>' . esc_html__( 'Riesgo académico:', 'atora-lms' ) . '</strong> ' . esc_html( $risk_label ) . '</p>';
			echo '</div>';
		}

		if ( $is_admin_view ) {
			echo '<div class="clms-admin-card">';
			echo '<h2>' . esc_html__( 'Resumen administrador', 'atora-lms' ) . '</h2>';
			echo '<p><strong>' . esc_html__( 'Certificados emitidos', 'atora-lms' ) . ':</strong> ' . esc_html( absint( $admin_report['certificates_issued'] ?? 0 ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Estudiantes activos', 'atora-lms' ) . ':</strong> ' . esc_html( absint( $admin_report['active_students'] ?? 0 ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Estudiantes inactivos', 'atora-lms' ) . ':</strong> ' . esc_html( absint( $admin_report['inactive_students'] ?? 0 ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Tasa de finalización', 'atora-lms' ) . ':</strong> ' . esc_html( absint( $admin_report['completion_rate'] ?? 0 ) ) . '%</p>';
			echo '<p><strong>' . esc_html__( 'Tasa de certificación', 'atora-lms' ) . ':</strong> ' . esc_html( absint( $admin_report['certification_rate'] ?? 0 ) ) . '%</p>';
			$commerce = isset( $admin_report['commerce'] ) && is_array( $admin_report['commerce'] ) ? $admin_report['commerce'] : array();
			if ( ! empty( $commerce['active'] ) ) {
				echo '<p><strong>' . esc_html__( 'WooCommerce vinculado:', 'atora-lms' ) . '</strong> ' . esc_html__( 'Sí', 'atora-lms' ) . '</p>';
				echo '<p><strong>' . esc_html__( 'Productos vinculados:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $commerce['linked_products'] ?? 0 ) ) . '</p>';
				echo '<p><strong>' . esc_html__( 'Activaciones pendientes:', 'atora-lms' ) . '</strong> ' . esc_html( absint( $commerce['activations_pending'] ?? 0 ) ) . '</p>';
			} else {
				echo '<p><strong>' . esc_html__( 'WooCommerce vinculado:', 'atora-lms' ) . '</strong> ' . esc_html__( 'No', 'atora-lms' ) . '</p>';
			}
			echo '</div>';
		}

		if ( $selected_course ) {
			echo '<div class="clms-admin-card">';
			echo '<h2>' . esc_html__( 'Exportables CSV', 'atora-lms' ) . '</h2>';
			echo '<p>' . esc_html__( 'Exporta datos académicos del curso seleccionado.', 'atora-lms' ) . '</p>';
			$export_types = array(
				'students'     => __( 'Estudiantes del curso', 'atora-lms' ),
				'progress'     => __( 'Progreso por curso', 'atora-lms' ),
				'grades'       => __( 'Calificaciones', 'atora-lms' ),
				'certificates' => __( 'Certificados', 'atora-lms' ),
				'risk'         => __( 'Estudiantes en riesgo', 'atora-lms' ),
			);
			echo '<div class="clms-admin-actions">';
			foreach ( $export_types as $type => $label ) {
				$url = wp_nonce_url(
					add_query_arg(
						array(
							'action'    => 'clms_export_academic_report',
							'course_id' => $selected_course,
							'type'      => $type,
							'back'      => self::REPORTS_PAGE,
						),
						admin_url( 'admin-post.php' )
					),
					'clms_export_academic_report_' . $selected_course . '_' . $type
				);
				echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
			}
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Pasos del wizard guiado.
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function get_wizard_steps() {
		return array(
			1  => array(
				'label'       => __( 'Datos básicos', 'atora-lms' ),
				'description' => __( 'Título, subtítulo y objetivo general.', 'atora-lms' ),
			),
			2  => array(
				'label'       => __( 'Objetivos y competencias', 'atora-lms' ),
				'description' => __( 'Define competencias y su cobertura inicial.', 'atora-lms' ),
			),
			3  => array(
				'label'       => __( 'Revisión académica', 'atora-lms' ),
				'description' => __( 'Verifica advertencias para certificar.', 'atora-lms' ),
			),
			4  => array(
				'label'       => __( 'Módulos y lecciones', 'atora-lms' ),
				'description' => __( 'Confirma estructura académica del curso.', 'atora-lms' ),
			),
			5  => array(
				'label'       => __( 'Actividades y evidencias', 'atora-lms' ),
				'description' => __( 'Define evidencias obligatorias y mínimas.', 'atora-lms' ),
			),
			6  => array(
				'label'       => __( 'Rúbrica y evaluación', 'atora-lms' ),
				'description' => __( 'Asocia rúbricas y criterios de evaluación.', 'atora-lms' ),
			),
			7  => array(
				'label'       => __( 'Profesor responsable', 'atora-lms' ),
				'description' => __( 'Asigna docente y valida carga académica.', 'atora-lms' ),
			),
			8  => array(
				'label'       => __( 'Certificado', 'atora-lms' ),
				'description' => __( 'Configura reglas y elegibilidad.', 'atora-lms' ),
			),
			9  => array(
				'label'       => __( 'Página comercial', 'atora-lms' ),
				'description' => __( 'Ajusta venta/inscripción del curso.', 'atora-lms' ),
			),
			10 => array(
				'label'       => __( 'Publicación', 'atora-lms' ),
				'description' => __( 'Publica con checklist académico validado.', 'atora-lms' ),
			),
		);
	}

	/**
	 * Render de stepper guiado.
	 *
	 * @param array $wizard_steps Pasos.
	 * @param int   $current_step Paso actual.
	 * @param int   $course_id    Curso.
	 * @return void
	 */
	protected function render_wizard_stepper( $wizard_steps, $current_step, $course_id ) {
		$wizard_steps = is_array( $wizard_steps ) ? $wizard_steps : array();
		$current_step = absint( $current_step );
		$course_id    = absint( $course_id );
		if ( empty( $wizard_steps ) ) {
			return;
		}

		echo '<div class="clms-admin-card"><h2>' . esc_html__( 'Ruta guiada del curso', 'atora-lms' ) . '</h2>';
		echo '<p>' . esc_html__( 'Usa esta ruta para saber qué falta antes de publicar, vender, evaluar y certificar.', 'atora-lms' ) . '</p>';
		echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:8px">';
		foreach ( $wizard_steps as $index => $item ) {
			$index = absint( $index );
			$item  = is_array( $item ) ? $item : array();
			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			$desc  = sanitize_text_field( (string) ( $item['description'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$is_current = $index === $current_step;
			$href = add_query_arg(
				array(
					'page'      => self::WIZARD_PAGE,
					'course_id' => $course_id,
					'step'      => $index,
				),
				admin_url( 'admin.php' )
			);
			echo '<a href="' . esc_url( $href ) . '" style="display:block;padding:8px;border:1px solid ' . esc_attr( $is_current ? '#2271b1' : '#dcdcde' ) . ';border-radius:8px;background:' . esc_attr( $is_current ? '#f0f6fc' : '#fff' ) . ';text-decoration:none">';
			echo '<strong>' . esc_html( sprintf( __( 'Paso %d', 'atora-lms' ), $index ) ) . ' — ' . esc_html( $label ) . '</strong>';
			if ( '' !== $desc ) {
				echo '<br><span class="description">' . esc_html( $desc ) . '</span>';
			}
			echo '</a>';
		}
		echo '</div></div>';
	}

	/**
	 * Selector de alcance del wizard (curso/programa).
	 *
	 * @param int   $course_id         Curso seleccionado.
	 * @param int   $program_id        Programa seleccionado.
	 * @param array $available_courses Cursos disponibles.
	 * @param array $available_programs Programas disponibles.
	 * @param int   $step              Paso actual.
	 * @return void
	 */
	protected function render_wizard_scope_selector( $course_id, $program_id, $available_courses, $available_programs, $step ) {
		$course_id          = absint( $course_id );
		$program_id         = absint( $program_id );
		$available_courses  = is_array( $available_courses ) ? $available_courses : array();
		$available_programs = is_array( $available_programs ) ? $available_programs : array();
		$step               = max( 1, absint( $step ) );

		echo '<div class="clms-admin-card">';
		echo '<h2>' . esc_html__( 'Alcance de configuración', 'atora-lms' ) . '</h2>';
		echo '<p>' . esc_html__( 'Selecciona un curso o programa existente para trabajar sobre su configuración académica.', 'atora-lms' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;align-items:end">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::WIZARD_PAGE ) . '">';
		echo '<input type="hidden" name="step" value="' . esc_attr( (string) $step ) . '">';

		echo '<p><label><strong>' . esc_html__( 'Curso activo', 'atora-lms' ) . '</strong><br>';
		echo '<select name="course_id" class="regular-text">';
		echo '<option value="0">' . esc_html__( 'Seleccionar curso…', 'atora-lms' ) . '</option>';
		foreach ( $available_courses as $course_row ) {
			$course_row = is_array( $course_row ) ? $course_row : array();
			$id         = absint( $course_row['id'] ?? 0 );
			$title      = sanitize_text_field( (string) ( $course_row['title'] ?? '' ) );
			$status     = sanitize_key( (string) ( $course_row['status'] ?? 'draft' ) );
			if ( ! $id || '' === $title ) {
				continue;
			}
			$label = $title . ' — ' . $this->get_post_status_label( $status );
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $course_id, $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';

		echo '<p><label><strong>' . esc_html__( 'Programa activo', 'atora-lms' ) . '</strong><br>';
		echo '<select name="program_id" class="regular-text">';
		echo '<option value="0">' . esc_html__( 'Seleccionar programa…', 'atora-lms' ) . '</option>';
		foreach ( $available_programs as $program_row ) {
			$program_row = is_array( $program_row ) ? $program_row : array();
			$id          = absint( $program_row['id'] ?? 0 );
			$title       = sanitize_text_field( (string) ( $program_row['title'] ?? '' ) );
			$status      = sanitize_key( (string) ( $program_row['status'] ?? 'draft' ) );
			if ( ! $id || '' === $title ) {
				continue;
			}
			$label = $title . ' — ' . $this->get_post_status_label( $status );
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $program_id, $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';

		echo '<p><button type="submit" class="button button-secondary">' . esc_html__( 'Cargar configuración', 'atora-lms' ) . '</button></p>';
		echo '</form>';

		echo '<p style="margin-top:8px">';
		echo '<a class="button button-secondary" href="' . esc_url( admin_url( 'post-new.php?post_type=lm_course' ) ) . '">' . esc_html__( 'Crear curso', 'atora-lms' ) . '</a> ';
		echo '<a class="button button-secondary" href="' . esc_url( admin_url( 'post-new.php?post_type=lm_program' ) ) . '">' . esc_html__( 'Crear programa', 'atora-lms' ) . '</a>';
		echo '</p>';

		if ( $program_id ) {
			$program_courses = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_program_courses' )
				? array_values( array_filter( array_map( 'absint', (array) CLMS_Helper::get_program_courses( $program_id ) ) ) )
				: array();
			echo '<p><strong>' . esc_html__( 'Cursos vinculados al programa:', 'atora-lms' ) . '</strong> ' . esc_html( absint( count( $program_courses ) ) ) . '</p>';
			if ( ! empty( $program_courses ) ) {
				echo '<ul style="margin-left:16px;list-style:disc">';
				foreach ( array_slice( $program_courses, 0, 12 ) as $program_course_id ) {
					echo '<li>' . esc_html( get_the_title( $program_course_id ) ) . ' <a href="' . esc_url( add_query_arg( array( 'page' => self::WIZARD_PAGE, 'course_id' => $program_course_id, 'program_id' => $program_id, 'step' => $step ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Configurar', 'atora-lms' ) . '</a></li>';
				}
				echo '</ul>';
			} else {
				echo '<p>' . esc_html__( 'Este programa aún no tiene cursos vinculados.', 'atora-lms' ) . '</p>';
			}
		}

		echo '</div>';
	}

	/**
	 * Obtiene cursos o programas accesibles para el usuario actual.
	 *
	 * @param string $post_type CPT.
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_wizard_accessible_posts( $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		if ( ! in_array( $post_type, array( 'lm_course', 'lm_program' ), true ) ) {
			return array();
		}

		$rows  = array();
		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'private', 'draft', 'pending' ),
				'posts_per_page' => 120,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( (array) $posts as $post_id ) {
			$post_id = absint( $post_id );
			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			$rows[] = array(
				'id'     => $post_id,
				'title'  => get_the_title( $post_id ),
				'status' => get_post_status( $post_id ),
			);
		}

		return $rows;
	}

	/**
	 * Etiqueta legible de estado de post.
	 *
	 * @param string $status Estado.
	 * @return string
	 */
	protected function get_post_status_label( $status ) {
		$status = sanitize_key( (string) $status );
		$map    = array(
			'publish' => __( 'Publicado', 'atora-lms' ),
			'private' => __( 'Privado', 'atora-lms' ),
			'draft'   => __( 'Borrador', 'atora-lms' ),
			'pending' => __( 'Pendiente', 'atora-lms' ),
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : __( 'Sin estado', 'atora-lms' );
	}

	/**
	 * Catálogo visual de competencias para wizard.
	 *
	 * @param int   $course_id           Curso.
	 * @param mixed $competency_service  Servicio.
	 * @return array<int,array<string,string>>
	 */
	protected function get_wizard_competency_catalog( $course_id, $competency_service ) {
		$course_id = absint( $course_id );
		$catalog   = array();
		if ( ! $course_id ) {
			return $catalog;
		}

		if ( $competency_service && method_exists( $competency_service, 'get_course_competencies' ) ) {
			$rows = (array) $competency_service->get_course_competencies( $course_id );
			foreach ( $rows as $row ) {
				$row = is_array( $row ) ? $row : array();
				$title = sanitize_text_field( (string) ( $row['title'] ?? '' ) );
				$id    = sanitize_key( (string) ( $row['id'] ?? sanitize_title( $title ) ) );
				if ( '' === $title ) {
					continue;
				}
				$catalog[ $id ] = array(
					'id'    => $id,
					'title' => $title,
				);
			}
		}

		$lesson_ids = class_exists( 'CLMS_Helper' ) ? (array) CLMS_Helper::get_course_lessons( $course_id ) : array();
		$lesson_ids = array_values( array_filter( array_map( 'absint', $lesson_ids ) ) );
		foreach ( $lesson_ids as $lesson_id ) {
			$legacy = (string) get_post_meta( $lesson_id, '_clms_lesson_competencies', true );
			$lines  = preg_split( '/\r\n|\r|\n/', $legacy );
			foreach ( (array) $lines as $line ) {
				$title = sanitize_text_field( (string) $line );
				if ( '' === $title ) {
					continue;
				}
				$id = sanitize_key( sanitize_title( $title ) );
				if ( '' === $id || isset( $catalog[ $id ] ) ) {
					continue;
				}
				$catalog[ $id ] = array(
					'id'    => $id,
					'title' => $title,
				);
			}
		}

		return array_values( $catalog );
	}

	/**
	 * Cobertura de evidencias por competencia para wizard.
	 *
	 * @param int   $course_id          Curso.
	 * @param array $competency_catalog Competencias.
	 * @param mixed $evidence_service   Servicio.
	 * @return array<string,array<string,int>>
	 */
	protected function get_wizard_competency_coverage( $course_id, $competency_catalog, $evidence_service ) {
		$course_id          = absint( $course_id );
		$competency_catalog = is_array( $competency_catalog ) ? $competency_catalog : array();
		$coverage           = array();
		foreach ( $competency_catalog as $comp ) {
			$comp = is_array( $comp ) ? $comp : array();
			$id   = sanitize_key( (string) ( $comp['id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			$coverage[ $id ] = array( 'total' => 0, 'required' => 0 );
		}

		if ( ! $course_id || empty( $coverage ) || ! $evidence_service || ! method_exists( $evidence_service, 'get_activity_evidence_config' ) ) {
			return $coverage;
		}

		$lesson_ids = class_exists( 'CLMS_Helper' ) ? (array) CLMS_Helper::get_course_lessons( $course_id ) : array();
		$lesson_ids = array_values( array_filter( array_map( 'absint', $lesson_ids ) ) );
		foreach ( $lesson_ids as $lesson_id ) {
			$config = (array) $evidence_service->get_activity_evidence_config( $lesson_id );
			$ids = isset( $config['competency_ids'] ) && is_array( $config['competency_ids'] ) ? $config['competency_ids'] : array();
			$ids = array_values( array_filter( array_map( 'sanitize_key', $ids ) ) );
			if ( empty( $ids ) ) {
				continue;
			}
			$is_required = ! empty( $config['is_required_for_certificate'] );
			foreach ( $ids as $id ) {
				if ( ! isset( $coverage[ $id ] ) ) {
					continue;
				}
				$coverage[ $id ]['total']++;
				if ( $is_required ) {
					$coverage[ $id ]['required']++;
				}
			}
		}

		return $coverage;
	}

	/**
	 * Acciones rápidas para pasos guiados no instrumentados.
	 *
	 * @param int $course_id Curso.
	 * @param int $step      Paso.
	 * @return void
	 */
	protected function render_wizard_quick_actions( $course_id, $step ) {
		$course_id = absint( $course_id );
		$step      = absint( $step );
		$actions = array(
			array(
				'label' => __( 'Abrir editor del curso', 'atora-lms' ),
				'url'   => $course_id ? admin_url( 'post.php?post=' . $course_id . '&action=edit' ) : admin_url( 'edit.php?post_type=lm_course' ),
			),
			array(
				'label' => __( 'Configurar lecciones y evidencias', 'atora-lms' ),
				'url'   => admin_url( 'edit.php?post_type=lm_lesson' ),
			),
			array(
				'label' => __( 'Revisar rúbricas', 'atora-lms' ),
				'url'   => admin_url( 'edit.php?post_type=clms_rubric' ),
			),
			array(
				'label' => __( 'Ver reporte académico', 'atora-lms' ),
				'url'   => admin_url( 'admin.php?page=' . self::REPORTS_PAGE . ( $course_id ? '&course_id=' . $course_id : '' ) ),
			),
		);

		if ( 9 === $step ) {
			$actions[] = array(
				'label' => __( 'Configurar página comercial', 'atora-lms' ),
				'url'   => $course_id ? admin_url( 'post.php?post=' . $course_id . '&action=edit#clms_course_commercial' ) : admin_url( 'edit.php?post_type=lm_course' ),
			);
		}

		echo '<div class="clms-admin-actions">';
		foreach ( $actions as $action ) {
			$action = is_array( $action ) ? $action : array();
			$label  = sanitize_text_field( (string) ( $action['label'] ?? '' ) );
			$url    = isset( $action['url'] ) ? esc_url_raw( (string) $action['url'] ) : '';
			if ( '' === $label || '' === $url ) {
				continue;
			}
			echo '<a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
		}
		echo '</div>';
	}

	/**
	 * Resuelve renderer modular de pasos del wizard.
	 *
	 * @return object|null
	 */
	protected function get_wizard_step_renderer() {
		$renderer = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Wizard_Step_Renderer') : null;
		if ( $renderer ) {
			return $renderer;
		}
		if ( class_exists( 'CLMS_Academic_Wizard_Step_Renderer' ) ) {
			return new CLMS_Academic_Wizard_Step_Renderer();
		}
		return null;
	}

	/**
	 * Resuelve servicio de guardado para pasos avanzados del wizard.
	 *
	 * @return object|null
	 */
	protected function get_wizard_step_save_service() {
		$service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Wizard_Step_Save_Service') : null;
		if ( $service ) {
			return $service;
		}
		if ( class_exists( 'CLMS_Academic_Wizard_Step_Save_Service' ) ) {
			return new CLMS_Academic_Wizard_Step_Save_Service();
		}
		return null;
	}

	/**
	 * Exportador CSV de reportes académicos.
	 *
	 * @return void
	 */
	public function handle_export_report() {
		if ( ! $this->can_view_reports() ) {
			wp_die( esc_html__( 'No tienes permisos para exportar este reporte.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		$type      = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'students';
		$back      = isset( $_GET['back'] ) ? sanitize_key( wp_unslash( $_GET['back'] ) ) : self::REPORTS_PAGE;

		check_admin_referer( 'clms_export_academic_report_' . $course_id . '_' . $type );

		$report_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Report_Service') : null;
		if ( ! $report_service || ! method_exists( $report_service, 'export_course_csv' ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => $back, 'report_notice' => 'export_error' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$export = (array) $report_service->export_course_csv( $course_id, $type, get_current_user_id() );
		if ( empty( $export['filename'] ) || empty( $export['content'] ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => $back, 'report_notice' => 'export_error' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( (string) $export['filename'] ) );
		echo (string) $export['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Guardado del wizard.
	 *
	 * @return void
	 */
	public function handle_wizard_save() {
		if ( ! $this->can_manage_academic_setup() ) {
			wp_die( esc_html__( 'No tienes permisos para esta acción.', 'atora-lms' ) );
		}

		check_admin_referer( self::POST_ACTION );

		$wizard_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Wizard_Service') : null;
		if ( ! $wizard_service ) {
			$this->set_wizard_notice( 'error', __( 'No se pudo cargar el servicio del asistente académico.', 'atora-lms' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::WIZARD_PAGE ) );
			exit;
		}

		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
		$program_id = isset( $_POST['program_id'] ) ? absint( wp_unslash( $_POST['program_id'] ) ) : 0;
		$wizard_steps = $this->get_wizard_steps();
		$max_steps = count( $wizard_steps );
		$step      = isset( $_POST['step'] ) ? max( 1, min( $max_steps, absint( wp_unslash( $_POST['step'] ) ) ) ) : 1;
		$nav       = isset( $_POST['wizard_nav'] ) ? sanitize_key( wp_unslash( $_POST['wizard_nav'] ) ) : 'save';
		$user_id   = get_current_user_id();

		$course_id = method_exists( $wizard_service, 'ensure_course' )
			? absint( $wizard_service->ensure_course( $course_id, $user_id ) )
			: $course_id;

		if ( ! $course_id ) {
			$this->set_wizard_notice( 'error', __( 'No se pudo crear o resolver el curso del asistente.', 'atora-lms' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::WIZARD_PAGE ) );
			exit;
		}

		if ( 1 === $step && method_exists( $wizard_service, 'save_basic_info' ) ) {
			$wizard_service->save_basic_info(
				$course_id,
				array(
					'title'             => isset( $_POST['wizard_title'] ) ? wp_unslash( $_POST['wizard_title'] ) : '',
					'subtitle'          => isset( $_POST['wizard_subtitle'] ) ? wp_unslash( $_POST['wizard_subtitle'] ) : '',
					'objective_general' => isset( $_POST['wizard_objective_general'] ) ? wp_unslash( $_POST['wizard_objective_general'] ) : '',
				)
			);
		}

		if ( 2 === $step && method_exists( $wizard_service, 'save_competencies_from_lines' ) ) {
			$lines_raw = isset( $_POST['wizard_competencies'] ) ? wp_unslash( $_POST['wizard_competencies'] ) : '';
			$selected_titles = isset( $_POST['wizard_competency_selected_titles'] ) ? (array) wp_unslash( $_POST['wizard_competency_selected_titles'] ) : array();
			$selected_titles = array_values(
				array_filter(
					array_map(
						static function( $title ) {
							return sanitize_text_field( (string) $title );
						},
						$selected_titles
					)
				)
			);
			if ( ! empty( $selected_titles ) ) {
				$lines_raw = trim( (string) $lines_raw );
				$lines_raw .= ( '' === $lines_raw ? '' : "\n" ) . implode( "\n", $selected_titles );
			}
			$wizard_service->save_competencies_from_lines(
				$course_id,
				$lines_raw,
				! empty( $_POST['wizard_mark_required'] ),
				isset( $_POST['wizard_default_weight'] ) ? absint( wp_unslash( $_POST['wizard_default_weight'] ) ) : 0
			);
		}

		if ( in_array( $step, array( 4, 5 ), true ) ) {
			$step_save_service = $this->get_wizard_step_save_service();
			if ( $step_save_service && method_exists( $step_save_service, 'save_step' ) ) {
				$step_save_result = (array) $step_save_service->save_step( $step, $course_id, wp_unslash( $_POST ) );
				$step_message = sanitize_text_field( (string) ( $step_save_result['message'] ?? '' ) );
				if ( '' !== $step_message ) {
					$this->set_wizard_notice( 'success', $step_message );
				}
			}
		}

		if ( 'next' === $nav ) {
			$step = min( $max_steps, $step + 1 );
		} elseif ( 'prev' === $nav ) {
			$step = max( 1, $step - 1 );
		}

		if ( method_exists( $wizard_service, 'save_progress_state' ) ) {
			$wizard_service->save_progress_state(
				$user_id,
				$course_id,
				array(
					'current_step'   => $step,
					'completed_step' => max( 0, $step - 1 ),
				)
			);
		}

		if ( ! in_array( $step, array( 4, 5 ), true ) ) {
			$this->set_wizard_notice( 'success', __( 'Asistente académico: cambios guardados correctamente.', 'atora-lms' ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::WIZARD_PAGE,
					'course_id' => $course_id,
					'program_id'=> $program_id,
					'step'      => $step,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Puede administrar configuración académica.
	 *
	 * @return bool
	 */
	protected function can_manage_academic_setup() {
		if ( class_exists( 'CLMS_Access' ) && method_exists( 'CLMS_Access', 'can_manage_courses' ) && CLMS_Access::can_manage_courses() ) {
			return true;
		}
		return current_user_can( 'clms_manage_courses' ) || current_user_can( 'clms_access_admin' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Puede ver reportes.
	 *
	 * @return bool
	 */
	protected function can_view_reports() {
		if ( class_exists( 'CLMS_Access' ) && method_exists( 'CLMS_Access', 'can_view_teacher_dashboard' ) && CLMS_Access::can_view_teacher_dashboard() ) {
			return true;
		}
		return current_user_can( 'clms_view_teacher_dashboard' ) || current_user_can( 'clms_access_admin' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Guarda aviso del wizard.
	 *
	 * @param string $type    Tipo.
	 * @param string $message Mensaje.
	 * @return void
	 */
	protected function set_wizard_notice( $type, $message ) {
		$type = sanitize_key( (string) $type );
		$message = sanitize_text_field( (string) $message );
		set_transient(
			'clms_academic_wizard_notice_' . absint( get_current_user_id() ),
			array(
				'type'    => in_array( $type, array( 'success', 'warning', 'error', 'info' ), true ) ? $type : 'info',
				'message' => $message,
			),
			MINUTE_IN_SECONDS * 5
		);
	}

	/**
	 * Renderiza aviso.
	 *
	 * @return void
	 */
	protected function render_wizard_admin_notice() {
		$key = 'clms_academic_wizard_notice_' . absint( get_current_user_id() );
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		$type = sanitize_key( (string) ( $notice['type'] ?? 'info' ) );
		$class = 'notice notice-info';
		if ( 'success' === $type ) {
			$class = 'notice notice-success';
		} elseif ( 'warning' === $type ) {
			$class = 'notice notice-warning';
		} elseif ( 'error' === $type ) {
			$class = 'notice notice-error';
		}
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( sanitize_text_field( (string) $notice['message'] ) ) . '</p></div>';
	}
}
