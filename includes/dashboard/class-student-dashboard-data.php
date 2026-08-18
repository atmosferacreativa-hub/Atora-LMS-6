<?php
/**
 * Servicio de datos para dashboard de estudiante.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Student_Dashboard_Data {

	/**
	 * Construye estado de cabecera del estudiante.
	 *
	 * @param int   $user_id        Estudiante.
	 * @param array $academic_state Estado académico consolidado.
	 * @return array<string,mixed>
	 */
	public function build_hero_state( $user_id, $academic_state ) {
		$user_id        = absint( $user_id );
		$academic_state = is_array( $academic_state ) ? $academic_state : array();

		$progress = absint( $academic_state['progress_percent'] ?? 0 );
		$risk     = sanitize_key( (string) ( $academic_state['risk_level'] ?? 'normal' ) );
		$pending  = absint( $academic_state['pending_activities'] ?? 0 );
		$cert     = sanitize_key( (string) ( $academic_state['certificate_status'] ?? 'pending' ) );

		$status_label = __( 'En progreso', 'atora-lms' );
		$status_class = 'is-info';

		if ( in_array( $cert, array( 'valid', 'issued' ), true ) ) {
			$status_label = __( 'Certificado emitido', 'atora-lms' );
			$status_class = 'is-success';
		} elseif ( 'high' === $risk ) {
			$status_label = __( 'Requiere atención', 'atora-lms' );
			$status_class = 'is-warning';
		} elseif ( $progress >= 100 && 0 === $pending ) {
			$status_label = __( 'Al día', 'atora-lms' );
			$status_class = 'is-success';
		} elseif ( $pending > 0 ) {
			$status_label = __( 'Pendiente', 'atora-lms' );
			$status_class = 'is-warning';
		}

		return array(
			'user_id'      => $user_id,
			'status_label' => $status_label,
			'status_class' => $status_class,
			'next_action'  => isset( $academic_state['next_step'] ) ? sanitize_text_field( (string) $academic_state['next_step'] ) : '',
			'progress_percent' => $progress,
			'pending_activities' => $pending,
			'final_average' => isset( $academic_state['final_average'] ) && is_numeric( $academic_state['final_average'] ) ? absint( $academic_state['final_average'] ) : null,
			'last_feedback' => isset( $academic_state['last_feedback'] ) ? sanitize_textarea_field( (string) $academic_state['last_feedback'] ) : '',
			'improvement_plan' => isset( $academic_state['improvement_plan'] ) && is_array( $academic_state['improvement_plan'] ) ? $academic_state['improvement_plan'] : array(),
			'gamification_summary' => isset( $academic_state['gamification_summary'] ) && is_array( $academic_state['gamification_summary'] ) ? $academic_state['gamification_summary'] : array(),
			'certificate_status' => $cert,
			'missing_certificate_requirements' => isset( $academic_state['missing_certificate_requirements'] ) && is_array( $academic_state['missing_certificate_requirements'] ) ? $academic_state['missing_certificate_requirements'] : array(),
		);
	}

	/**
	 * Construye payload consolidado para dashboard estudiantil.
	 *
	 * @param int   $user_id Usuario estudiante.
	 * @param array $context Contexto recolectado desde dashboard.
	 * @return array<string,mixed>
	 */
	public function build_dashboard_payload( $user_id, $context = array() ) {
		$user_id = absint( $user_id );
		$context = is_array( $context ) ? $context : array();

		$active_status = isset( $context['active_status'] ) && is_array( $context['active_status'] ) ? $context['active_status'] : array();
		$next_step     = isset( $context['next_step'] ) && is_array( $context['next_step'] ) ? $context['next_step'] : array();
		$courses       = isset( $context['course_cards'] ) && is_array( $context['course_cards'] ) ? $context['course_cards'] : array();
		$alerts        = isset( $context['alert_items'] ) && is_array( $context['alert_items'] ) ? $context['alert_items'] : array();
		$assistant     = isset( $context['assistant_context'] ) && is_array( $context['assistant_context'] ) ? $context['assistant_context'] : array();
		$peer_review_summary = array();
		if ( class_exists( 'CLMS_Peer_Review' ) && method_exists( 'CLMS_Peer_Review', 'get_reviewer_summary' ) ) {
			$peer_review_summary = (array) CLMS_Peer_Review::get_reviewer_summary( $user_id );
		}

		$improvement = isset( $active_status['improvement_plan'] ) && is_array( $active_status['improvement_plan'] )
			? $active_status['improvement_plan']
			: array();
		$plan_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Improvement_Plan_Service') : null;
		if ( $plan_service && method_exists( $plan_service, 'normalize_plan' ) ) {
			$fallback = isset( $next_step['title'] ) ? (string) $next_step['title'] : '';
			$improvement = (array) $plan_service->normalize_plan( $improvement, $fallback );
		}

		$recent_feedback = array(
			'feedback' => isset( $context['feedback_plan']['feedback'] ) ? sanitize_textarea_field( (string) $context['feedback_plan']['feedback'] ) : '',
			'grade'    => isset( $context['feedback_plan']['grade'] ) && is_numeric( $context['feedback_plan']['grade'] ) ? absint( $context['feedback_plan']['grade'] ) : null,
			'title'    => isset( $context['feedback_plan']['title'] ) ? sanitize_text_field( (string) $context['feedback_plan']['title'] ) : '',
		);

		return array(
			'user'              => array(
				'id' => $user_id,
			),
			'active_course'     => array(
				'id'    => absint( $context['learning_route']['course_id'] ?? 0 ),
				'title' => sanitize_text_field( (string) ( $context['learning_route']['course_title'] ?? '' ) ),
			),
			'next_step'         => $next_step,
			'academic_status'   => $active_status,
			'recent_feedback'   => $recent_feedback,
			'improvement_plan'  => $improvement,
			'competencies'      => isset( $active_status['competencies'] ) && is_array( $active_status['competencies'] ) ? $active_status['competencies'] : array(),
			'evidences'         => isset( $active_status['evidences'] ) && is_array( $active_status['evidences'] ) ? $active_status['evidences'] : array(),
			'gamification'      => isset( $context['gamification_panel'] ) && is_array( $context['gamification_panel'] ) ? $context['gamification_panel'] : array(),
			'certificate'       => isset( $context['certificate_panel'] ) && is_array( $context['certificate_panel'] ) ? $context['certificate_panel'] : array(),
			'courses'           => array_slice( $courses, 0, 6 ),
			'alerts'            => array_slice( $alerts, 0, 5 ),
			'assistant_actions' => $this->get_assistant_actions( $assistant ),
			'peer_review'       => $peer_review_summary,
		);
	}

	/**
	 * Acciones contextuales del asistente estudiantil.
	 *
	 * @param array $assistant_context Contexto IA.
	 * @return array<int,array<string,string>>
	 */
	protected function get_assistant_actions( $assistant_context ) {
		$assistant_context = is_array( $assistant_context ) ? $assistant_context : array();

		if ( empty( $assistant_context['enabled'] ) ) {
			return array();
		}

		return array(
			array(
				'label' => __( 'Explícame este feedback', 'atora-lms' ),
				'prompt'=> __( 'Explícame este feedback con lenguaje simple y dame una acción concreta para hoy.', 'atora-lms' ),
			),
			array(
				'label' => __( 'Ayúdame a organizar mi estudio', 'atora-lms' ),
				'prompt'=> __( 'Organiza un plan de estudio breve para esta semana con base en mis pendientes.', 'atora-lms' ),
			),
			array(
				'label' => __( 'Qué debo repasar ahora', 'atora-lms' ),
				'prompt'=> __( 'Indícame qué tema debo repasar antes de continuar y por qué.', 'atora-lms' ),
			),
			array(
				'label' => __( 'Cómo mejorar mi próxima entrega', 'atora-lms' ),
				'prompt'=> __( 'Dame una checklist corta para mejorar mi próxima entrega.', 'atora-lms' ),
			),
			array(
				'label' => __( 'Qué competencia reforzar', 'atora-lms' ),
				'prompt'=> __( 'Indícame qué competencia debo reforzar ahora y una acción práctica para hacerlo.', 'atora-lms' ),
			),
		);
	}
}
