<?php
/**
 * Servicio para normalizar y resumir la cola priorizada docente.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Teacher_Priority_Queue_Service {

	/**
	 * Normaliza cola priorizada para UI docente.
	 *
	 * @param array $items Cola base.
	 * @param int   $limit Límite visible.
	 * @return array<int,array<string,mixed>>
	 */
	public function normalize_queue( $items, $limit = 8 ) {
		$items = is_array( $items ) ? array_values( $items ) : array();
		$limit = max( 1, absint( $limit ) );

		$normalized = array();

		foreach ( $items as $item ) {
			$item = is_array( $item ) ? $item : array();
			$priority_rank = $this->resolve_priority_rank( $item );

			$normalized[] = array(
				'submission_id'       => absint( $item['submission_id'] ?? 0 ),
				'student_id'          => absint( $item['student_id'] ?? 0 ),
				'student_name'        => sanitize_text_field( (string) ( $item['student_name'] ?? __( 'Estudiante', 'atora-lms' ) ) ),
				'lesson_id'           => absint( $item['lesson_id'] ?? 0 ),
				'lesson_title'        => sanitize_text_field( (string) ( $item['lesson_title'] ?? __( 'Actividad', 'atora-lms' ) ) ),
				'course_id'           => absint( $item['course_id'] ?? 0 ),
				'course_title'        => sanitize_text_field( (string) ( $item['course_title'] ?? '' ) ),
				'status'              => sanitize_key( (string) ( $item['status'] ?? '' ) ),
				'status_label'        => sanitize_text_field( (string) ( $item['status_label'] ?? __( 'Pendiente', 'atora-lms' ) ) ),
				'submitted_at'        => sanitize_text_field( (string) ( $item['submitted_at'] ?? '' ) ),
				'submitted_timestamp' => absint( $item['submitted_timestamp'] ?? 0 ),
				'speedgrade_url'      => esc_url_raw( (string) ( $item['speedgrade_url'] ?? '' ) ),
				'priority_rank'       => $priority_rank,
				'priority_label'      => sanitize_text_field( (string) ( $item['priority_label'] ?? __( 'Seguimiento', 'atora-lms' ) ) ),
				'priority_class'      => sanitize_html_class( (string) ( $item['priority_class'] ?? 'is-info' ) ),
				'priority_reason'     => sanitize_text_field( (string) ( $item['priority_reason'] ?? '' ) ),
				'is_urgent'           => ! empty( $item['is_urgent'] ),
				'ai_pending'          => ! empty( $item['ai_pending'] ),
				'affects_certificate' => ! empty( $item['affects_certificate'] ),
				'evidence_type'       => sanitize_key( (string) ( $item['evidence_type'] ?? 'practice' ) ),
				'is_required_evidence'=> ! empty( $item['is_required_evidence'] ),
				'competency_title'    => sanitize_text_field( (string) ( $item['competency_title'] ?? '' ) ),
				'flags'               => $this->sanitize_flags( $item['flags'] ?? array() ),
				'primary_action'      => array(
					'label' => __( 'Revisar en SpeedGrade', 'atora-lms' ),
					'url'   => esc_url_raw( (string) ( $item['speedgrade_url'] ?? '' ) ),
				),
			);
		}

		usort(
			$normalized,
			static function ( $a, $b ) {
				$a_rank = absint( $a['priority_rank'] ?? 5 );
				$b_rank = absint( $b['priority_rank'] ?? 5 );

				if ( $a_rank === $b_rank ) {
					$a_time = absint( $a['submitted_timestamp'] ?? 0 );
					$b_time = absint( $b['submitted_timestamp'] ?? 0 );
					return $b_time <=> $a_time;
				}
				return $a_rank <=> $b_rank;
			}
		);

		return array_slice( $normalized, 0, $limit );
	}

	/**
	 * Resuelve prioridad con fallback pedagógico seguro.
	 *
	 * @param array $item Item de cola.
	 * @return int
	 */
	protected function resolve_priority_rank( $item ) {
		$item = is_array( $item ) ? $item : array();
		$explicit_rank = absint( $item['priority_rank'] ?? 0 );
		if ( $explicit_rank > 0 ) {
			return $explicit_rank;
		}

		$status = sanitize_key( (string) ( $item['status'] ?? '' ) );
		$flags  = isset( $item['flags'] ) && is_array( $item['flags'] ) ? $item['flags'] : array();
		$joined = strtolower( implode( ' ', array_map( 'sanitize_text_field', $flags ) ) );

		if ( ! empty( $item['is_urgent'] ) || false !== strpos( $joined, 'vencida' ) || false !== strpos( $joined, 'urgente' ) ) {
			return 1;
		}
		if ( ! empty( $item['is_required_evidence'] ) ) {
			return 2;
		}
		if ( false !== strpos( $joined, 'riesgo' ) ) {
			return 3;
		}
		if ( ! empty( $item['ai_pending'] ) ) {
			return 4;
		}
		if ( in_array( $status, array( 'needs_revision', 'returned' ), true ) ) {
			return 5;
		}
		if ( ! empty( $item['affects_certificate'] ) ) {
			return 6;
		}
		if ( in_array( $status, array( 'submitted', 'in_review' ), true ) ) {
			return 7;
		}

		return 8;
	}

	/**
	 * Estudiantes únicos en riesgo.
	 *
	 * @param array $queue Cola normalizada.
	 * @param int   $limit Máximo.
	 * @return array<int,array<string,mixed>>
	 */
	public function extract_students_at_risk( $queue, $limit = 6 ) {
		$queue = is_array( $queue ) ? $queue : array();
		$limit = max( 1, absint( $limit ) );
		$seen  = array();
		$list  = array();

		foreach ( $queue as $item ) {
			$item = is_array( $item ) ? $item : array();
			$student_id = absint( $item['student_id'] ?? 0 );
			if ( ! $student_id || isset( $seen[ $student_id ] ) ) {
				continue;
			}

			$flags = isset( $item['flags'] ) && is_array( $item['flags'] ) ? $item['flags'] : array();
			$joined_flags = strtolower( implode( ' ', $flags ) );
			$is_risk = false !== strpos( $joined_flags, 'riesgo' ) || false !== strpos( $joined_flags, 'vencida' ) || ! empty( $item['is_required_evidence'] );
			if ( ! $is_risk ) {
				continue;
			}

			$reason = ! empty( $flags[0] ) ? sanitize_text_field( (string) $flags[0] ) : __( 'Riesgo académico detectado', 'atora-lms' );
			if ( ! empty( $item['is_required_evidence'] ) ) {
				$reason = __( 'Evidencia obligatoria pendiente de revisión.', 'atora-lms' );
			}

			$seen[ $student_id ] = true;
			$list[] = array(
				'student_id'   => $student_id,
				'student_name' => sanitize_text_field( (string) ( $item['student_name'] ?? __( 'Estudiante', 'atora-lms' ) ) ),
				'course_id'    => absint( $item['course_id'] ?? 0 ),
				'course_title' => sanitize_text_field( (string) ( $item['course_title'] ?? '' ) ),
				'reason'       => $reason,
				'priority_reason' => sanitize_text_field( (string) ( $item['priority_reason'] ?? '' ) ),
				'speedgrade_url' => esc_url_raw( (string) ( $item['speedgrade_url'] ?? '' ) ),
			);

			if ( count( $list ) >= $limit ) {
				break;
			}
		}

		return $list;
	}

	/**
	 * Evaluaciones con IA pendientes de validación.
	 *
	 * @param array $queue Cola normalizada.
	 * @param int   $limit Máximo.
	 * @return array<int,array<string,mixed>>
	 */
	public function extract_ai_pending_reviews( $queue, $limit = 6 ) {
		$queue = is_array( $queue ) ? $queue : array();
		$limit = max( 1, absint( $limit ) );
		$list  = array();

		foreach ( $queue as $item ) {
			$item = is_array( $item ) ? $item : array();
			if ( empty( $item['ai_pending'] ) ) {
				continue;
			}
			$list[] = array(
				'submission_id'  => absint( $item['submission_id'] ?? 0 ),
				'student_id'     => absint( $item['student_id'] ?? 0 ),
				'student_name'   => sanitize_text_field( (string) ( $item['student_name'] ?? __( 'Estudiante', 'atora-lms' ) ) ),
				'lesson_title'   => sanitize_text_field( (string) ( $item['lesson_title'] ?? __( 'Actividad', 'atora-lms' ) ) ),
				'course_title'   => sanitize_text_field( (string) ( $item['course_title'] ?? '' ) ),
				'status_label'   => sanitize_text_field( (string) ( $item['status_label'] ?? __( 'Pendiente', 'atora-lms' ) ) ),
				'speedgrade_url' => esc_url_raw( (string) ( $item['speedgrade_url'] ?? '' ) ),
			);

			if ( count( $list ) >= $limit ) {
				break;
			}
		}

		return $list;
	}

	/**
	 * Construye reporte académico breve para cabina docente.
	 *
	 * @param array $summary        Resumen panel.
	 * @param array $group_overview Resumen de grupo.
	 * @return array<string,mixed>
	 */
	public function build_academic_report( $summary, $group_overview, $competency_overview = array() ) {
		$summary        = is_array( $summary ) ? $summary : array();
		$group_overview = is_array( $group_overview ) ? $group_overview : array();
		$competency_overview = is_array( $competency_overview ) ? $competency_overview : array();

		$pending   = absint( $summary['pending'] ?? 0 );
		$at_risk   = absint( $summary['at_risk'] ?? 0 );
		$avg_grade = absint( $group_overview['avg_grade'] ?? 0 );
		$progress  = absint( $group_overview['completion_rate'] ?? 0 );

		$recommendation = __( 'Mantén el seguimiento semanal del grupo.', 'atora-lms' );
		if ( $at_risk >= 3 ) {
			$recommendation = __( 'Prioriza intervención con estudiantes en riesgo esta semana.', 'atora-lms' );
		} elseif ( $pending >= 6 ) {
			$recommendation = __( 'Enfoca tiempo en la cola de revisión para evitar retrasos.', 'atora-lms' );
		} elseif ( $avg_grade > 0 && $avg_grade < 70 ) {
			$recommendation = __( 'Planifica una actividad de refuerzo para elevar el desempeño.', 'atora-lms' );
		}

		$peer_analytics = array();
		if ( class_exists( 'CLMS_Peer_Review' ) && method_exists( 'CLMS_Peer_Review', 'get_effectiveness_analytics' ) ) {
			$peer_analytics = (array) CLMS_Peer_Review::get_effectiveness_analytics(
				array(
					'limit' => 200,
				)
			);
		}

		$peer_quality = 0;
		if ( ! empty( $peer_analytics['top_reviewers'][0]['avg_quality'] ) && is_numeric( $peer_analytics['top_reviewers'][0]['avg_quality'] ) ) {
			$peer_quality = (float) $peer_analytics['top_reviewers'][0]['avg_quality'];
		}

		return array(
			'average_grade'  => $avg_grade,
			'progress_rate'  => $progress,
			'at_risk'        => $at_risk,
			'pending_reviews'=> $pending,
			'certificate_impact' => absint( $summary['certificate_impact'] ?? 0 ),
			'peer_reviews_total' => absint( $peer_analytics['total_completed'] ?? 0 ),
			'peer_reviews_late'  => absint( $peer_analytics['late_reviews'] ?? 0 ),
			'peer_reviews_useful'=> absint( $peer_analytics['useful_reviews'] ?? 0 ),
			'peer_quality_top'   => $peer_quality,
			'strongest_competency' => sanitize_text_field( (string) ( $competency_overview['strongest'] ?? '' ) ),
			'weakest_competency'   => sanitize_text_field( (string) ( $competency_overview['weakest'] ?? '' ) ),
			'recommendation' => $recommendation,
		);
	}

	/**
	 * Sanitiza banderas visibles.
	 *
	 * @param mixed $flags Flags.
	 * @return array<int,string>
	 */
	protected function sanitize_flags( $flags ) {
		$flags = is_array( $flags ) ? $flags : array();
		$flags = array_map(
			static function ( $flag ) {
				return sanitize_text_field( (string) $flag );
			},
			$flags
		);

		return array_values( array_filter( array_unique( $flags ) ) );
	}
}
