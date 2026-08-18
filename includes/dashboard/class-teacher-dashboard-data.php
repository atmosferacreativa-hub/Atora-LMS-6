<?php
/**
 * Servicio de datos para dashboard docente.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Teacher_Dashboard_Data {

	/**
	 * Resume el panel inteligente con top de acciones.
	 *
	 * @param int   $user_id         Docente.
	 * @param array $smart_summary   Resumen base.
	 * @param int   $selected_course Curso seleccionado.
	 * @return array<string,mixed>
	 */
	public function build_hub_data( $user_id, $smart_summary, $selected_course = 0 ) {
		$user_id         = absint( $user_id );
		$smart_summary   = is_array( $smart_summary ) ? $smart_summary : array();
		$selected_course = absint( $selected_course );

		$summary = array(
			'pending'    => absint( $smart_summary['pending'] ?? 0 ),
			'urgent'     => absint( $smart_summary['urgent'] ?? 0 ),
			'at_risk'    => absint( $smart_summary['at_risk'] ?? 0 ),
			'ai_pending' => absint( $smart_summary['ai_pending'] ?? 0 ),
			'alerts'     => absint( $smart_summary['alerts'] ?? 0 ),
		);
		$ai_enabled = false;
		$ai_manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
		if ( $ai_manager && method_exists( $ai_manager, 'is_configured' ) ) {
			$ai_enabled = (bool) $ai_manager->is_configured();
		}

		$actions = array();
		$actions[] = array(
			'label'       => __( 'Abrir SpeedGrade', 'atora-lms' ),
			'description' => __( 'Ir al centro de corrección docente.', 'atora-lms' ),
			'url'         => admin_url( 'admin.php?page=clms-speedgrader' ),
			'enabled'     => current_user_can( 'clms_grade_submissions' ),
		);
		$actions[] = array(
			'label'       => __( 'Ver estudiantes en riesgo', 'atora-lms' ),
			'description' => __( 'Revisar seguimiento y alumnos que requieren atención.', 'atora-lms' ),
			'url'         => '#clms-td-students',
			'enabled'     => true,
		);
		$actions[] = array(
			'label'       => __( 'Ver evidencias obligatorias', 'atora-lms' ),
			'description' => __( 'Priorizar entregas que impactan la certificación.', 'atora-lms' ),
			'url'         => '#clms-td-smart-panel',
			'enabled'     => true,
		);
		$actions[] = array(
			'label'       => __( 'Validar IA', 'atora-lms' ),
			'description' => __( 'Revisar sugerencias IA pendientes con contexto.', 'atora-lms' ),
			'url'         => '#clms-td-ai',
			'enabled'     => $ai_enabled,
		);
		$actions[] = array(
			'label'       => __( 'Reporte académico', 'atora-lms' ),
			'description' => __( 'Consultar desempeño y tendencias del grupo.', 'atora-lms' ),
			'url'         => admin_url( 'admin.php?page=clms-analytics' ),
			'enabled'     => true,
		);
		$actions[] = array(
			'label'       => __( 'Ver curso', 'atora-lms' ),
			'description' => __( 'Abrir el curso activo del panel.', 'atora-lms' ),
			'url'         => $selected_course ? get_permalink( $selected_course ) : '',
			'enabled'     => $selected_course > 0,
		);
		$actions[] = array(
			'label'       => __( 'Crear actividad', 'atora-lms' ),
			'description' => __( 'Diseñar una nueva actividad de refuerzo.', 'atora-lms' ),
			'url'         => admin_url( 'post-new.php?post_type=lm_lesson' ),
			'enabled'     => current_user_can( 'edit_lm_lessons' ),
		);

		$actions = array_values(
			array_filter(
				$actions,
				static function ( $action ) {
					return ! empty( $action['enabled'] ) && ! empty( $action['url'] );
				}
			)
		);
		$actions = array_slice( $actions, 0, 5 );

		return array(
			'summary'       => $summary,
			'focus_today'   => array(
				'pending_reviews'   => $summary['pending'],
				'urgent_submissions'=> $summary['urgent'],
				'students_at_risk'  => $summary['at_risk'],
				'ai_to_validate'    => $summary['ai_pending'],
				'academic_alerts'   => $summary['alerts'],
			),
			'quick_actions' => $actions,
			'teacher_id'    => $user_id,
		);
	}

	/**
	 * Payload integral para dashboard docente.
	 *
	 * @param int   $user_id Usuario docente.
	 * @param array $context Contexto operativo.
	 * @return array<string,mixed>
	 */
	public function build_dashboard_payload( $user_id, $context = array() ) {
		$user_id = absint( $user_id );
		$context = is_array( $context ) ? $context : array();

		$summary          = isset( $context['summary'] ) && is_array( $context['summary'] ) ? $context['summary'] : array();
		$selected_course  = absint( $context['selected_course_id'] ?? 0 );
		$hub              = $this->build_hub_data( $user_id, $summary, $selected_course );
		$priority_queue   = isset( $context['priority_queue'] ) && is_array( $context['priority_queue'] ) ? $context['priority_queue'] : array();
		$students_at_risk = isset( $context['students_at_risk'] ) && is_array( $context['students_at_risk'] ) ? $context['students_at_risk'] : array();
		$ai_pending       = isset( $context['ai_pending_reviews'] ) && is_array( $context['ai_pending_reviews'] ) ? $context['ai_pending_reviews'] : array();
		$peer_review_analytics = array();
		if ( class_exists( 'CLMS_Peer_Review' ) && method_exists( 'CLMS_Peer_Review', 'get_effectiveness_analytics' ) ) {
			$peer_review_analytics = (array) CLMS_Peer_Review::get_effectiveness_analytics( array( 'limit' => 200 ) );
		}

		return array(
			'teacher'            => array(
				'id' => $user_id,
			),
			'summary'            => isset( $hub['summary'] ) ? $hub['summary'] : array(),
			'priority_queue'     => array_slice( $priority_queue, 0, 8 ),
			'students_at_risk'   => array_slice( $students_at_risk, 0, 6 ),
			'ai_pending_reviews' => array_slice( $ai_pending, 0, 6 ),
			'courses'            => isset( $context['course_cards'] ) && is_array( $context['course_cards'] ) ? array_slice( $context['course_cards'], 0, 8 ) : array(),
			'recent_activity'    => isset( $context['reviewed_items'] ) && is_array( $context['reviewed_items'] ) ? array_slice( $context['reviewed_items'], 0, 6 ) : array(),
			'quick_actions'      => isset( $hub['quick_actions'] ) ? $hub['quick_actions'] : array(),
			'academic_report'    => isset( $context['academic_report'] ) && is_array( $context['academic_report'] ) ? $context['academic_report'] : array(),
			'peer_review_analytics' => $peer_review_analytics,
		);
	}
}
