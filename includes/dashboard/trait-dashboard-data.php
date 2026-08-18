<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Dashboard_Data_Trait {
	protected function get_recent_feedback_items( $user_id, $limit = 4 ) {
		$user_id = absint( $user_id );
		$limit   = max( 1, absint( $limit ) );

		if ( ! class_exists( 'CLMS_Helper' ) || ! class_exists( 'CLMS_Submission' ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => CLMS_Submission::CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $user_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'     => '_clms_submission_feedback',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);

		$items = array();

		foreach ( $ids as $submission_id ) {
			$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
			$status    = (string) get_post_meta( $submission_id, '_clms_submission_status', true );
			$grade     = get_post_meta( $submission_id, '_clms_submission_grade', true );
			$feedback  = (string) get_post_meta( $submission_id, '_clms_submission_feedback', true );

			if ( ! $lesson_id || '' === $feedback ) {
				continue;
			}

			$items[] = array(
				'submission_id'=> absint( $submission_id ),
				'lesson_title' => get_the_title( $lesson_id ),
				'course_title' => $course_id ? get_the_title( $course_id ) : '',
				'lesson_id'    => $lesson_id,
				'course_id'    => $course_id,
				'status_label' => $this->get_submission_status_label( $status ),
				'badge_class'  => $this->get_submission_badge_class( $status ),
				'grade'        => '' !== (string) $grade ? absint( $grade ) : '',
				'feedback'     => $feedback,
				'date'         => get_post_field( 'post_modified', $submission_id ),
				'url'          => get_permalink( $lesson_id ),
			);
		}

		return $items;
	}

	protected function get_pending_items( $user_id, $course_ids, $limit = 6 ) {
		$user_id    = absint( $user_id );
		$course_ids = is_array( $course_ids ) ? array_map( 'absint', $course_ids ) : array();
		$limit      = max( 1, absint( $limit ) );

		if ( empty( $course_ids ) || ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$items      = array();
		$grading    = clms_core('CLMS_Grading');
		$submission = clms_core('CLMS_Submission');

		foreach ( $course_ids as $course_id ) {
			$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );

			foreach ( $lesson_ids as $lesson_id ) {
				$lesson_id = absint( $lesson_id );

				if ( ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
					continue;
				}

				$activity_type = strtolower( (string) CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_activity_type', '_clms_activity_mode' ), 'lectura' ) );
				$is_completed  = false;

				if ( $grading && method_exists( $grading, 'is_lesson_completed_by_user' ) ) {
					$is_completed = (bool) $grading->is_lesson_completed_by_user( $user_id, $lesson_id );
				}

				if ( $is_completed ) {
					continue;
				}

				$label       = __( 'Pendiente', 'atora-lms' );
				$description = __( 'Completa esta actividad para continuar tu avance.', 'atora-lms' );
				$badge_class = 'is-warning';

					$is_evaluable = false;
					if ( in_array( $activity_type, array( 'quiz', 'evaluacion', 'evaluation' ), true ) ) {
						$label       = __( 'Quiz pendiente', 'atora-lms' );
						$description = __( 'Aún no has respondido esta evaluación.', 'atora-lms' );
						$is_evaluable = true;
					} elseif ( in_array( $activity_type, array( 'tarea', 'task', 'assignment' ), true ) ) {
						$label       = __( 'Tarea pendiente', 'atora-lms' );
						$description = __( 'Aún no has enviado esta tarea.', 'atora-lms' );
						$is_evaluable = true;

						if ( $submission && method_exists( $submission, 'get_user_submission_for_grading' ) ) {
							$current_submission = $submission->get_user_submission_for_grading( $user_id, $lesson_id );

							if ( ! empty( $current_submission ) ) {
							continue;
						}
					}
					} else {
						$label       = __( 'Lección pendiente', 'atora-lms' );
						$description = __( 'Revisa esta lección para avanzar en tu curso.', 'atora-lms' );
						$is_evaluable = false;
					}

				$due_date_raw = CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_date', '_clms_due_date' ), '' );

					$items[] = array(
						'lesson_title' => get_the_title( $lesson_id ),
						'course_title' => get_the_title( $course_id ),
						'lesson_id'    => $lesson_id,
						'course_id'    => $course_id,
						'label'        => $label,
						'description'  => $description,
						'badge_class'  => $badge_class,
						'due_date'     => $due_date_raw ? $this->format_date( $due_date_raw ) : '',
						'url'          => get_permalink( $lesson_id ),
						'is_evaluable' => $is_evaluable,
					);

				if ( count( $items ) >= $limit ) {
					break 2;
				}
			}
		}

		return $items;
	}

	protected function get_recent_notifications( $user_id, $limit = 5 ) {
		$user_id = absint( $user_id );
		$limit   = max( 1, absint( $limit ) );

		if ( ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$notifications = clms_core('CLMS_Notifications');

		if ( ! $notifications || ! method_exists( $notifications, 'get_notifications' ) ) {
			return array();
		}

		$items = $notifications->get_notifications( $user_id, false );

		if ( empty( $items ) || ! is_array( $items ) ) {
			return array();
		}

		return array_slice( $items, 0, $limit );
	}

	protected function get_academic_status_map( $user_id, $course_ids ) {
		$user_id    = absint( $user_id );
		$course_ids = is_array( $course_ids ) ? array_map( 'absint', $course_ids ) : array();

		if ( ! $user_id || empty( $course_ids ) || ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$grading = clms_core('CLMS_Grading');
		if ( ! $grading || ! method_exists( $grading, 'get_student_course_status' ) ) {
			return array();
		}

		$map = array();
		foreach ( $course_ids as $course_id ) {
			$course_id = absint( $course_id );
			if ( ! $course_id ) {
				continue;
			}

			$status = $grading->get_student_course_status( $user_id, $course_id );
			$map[ $course_id ] = is_array( $status ) ? $status : array();
		}

		return $map;
	}

	protected function get_primary_academic_status( $academic_statuses, $primary_course_id = 0 ) {
		$academic_statuses = is_array( $academic_statuses ) ? $academic_statuses : array();
		$primary_course_id = absint( $primary_course_id );

		if ( $primary_course_id && isset( $academic_statuses[ $primary_course_id ] ) && is_array( $academic_statuses[ $primary_course_id ] ) ) {
			return $academic_statuses[ $primary_course_id ];
		}

		foreach ( $academic_statuses as $status ) {
			if ( is_array( $status ) ) {
				return $status;
			}
		}

		return array(
			'certificate_status'                => 'pending',
			'missing_certificate_requirements'  => array(),
			'gamification_summary'              => array(),
			'improvement_plan'                  => array(),
			'next_step'                         => '',
		);
	}

	protected function build_certificate_panel( $status ) {
		$status = is_array( $status ) ? $status : array();
		$certificate_status = isset( $status['certificate_status'] ) ? sanitize_key( (string) $status['certificate_status'] ) : 'pending';
		$missing = isset( $status['missing_certificate_requirements'] ) && is_array( $status['missing_certificate_requirements'] ) ? $status['missing_certificate_requirements'] : array();
		$detail  = isset( $status['certificate_status_detail'] ) && is_array( $status['certificate_status_detail'] ) ? $status['certificate_status_detail'] : array();
		$record  = isset( $detail['record'] ) && is_array( $detail['record'] ) ? $detail['record'] : array();
		$missing = array_values(
			array_filter(
				array_map(
					static function ( $item ) {
						return sanitize_text_field( (string) $item );
					},
					$missing
				)
			)
		);
		$required_progress = isset( $detail['required_progress'] ) ? absint( $detail['required_progress'] ) : 100;
		$required_average  = isset( $detail['passing_grade'] ) ? absint( $detail['passing_grade'] ) : 70;
		$required_evidences = isset( $detail['required_evidences'] ) && is_array( $detail['required_evidences'] ) ? count( $detail['required_evidences'] ) : 0;
		$completed_evidences = isset( $detail['completed_evidences'] ) && is_array( $detail['completed_evidences'] ) ? count( $detail['completed_evidences'] ) : 0;
		$required_competencies = isset( $detail['required_competencies'] ) && is_array( $detail['required_competencies'] ) ? count( $detail['required_competencies'] ) : 0;
		$completed_competencies = isset( $detail['completed_competencies'] ) && is_array( $detail['completed_competencies'] ) ? count( $detail['completed_competencies'] ) : 0;
		$configuration_warnings = isset( $detail['configuration_warnings'] ) && is_array( $detail['configuration_warnings'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', $detail['configuration_warnings'] ) ) )
			: array();
		$course_ready_for_certificate = ! empty( $detail['course_ready_for_certificate'] );
		$verification_url  = isset( $detail['verification_url'] ) ? esc_url_raw( (string) $detail['verification_url'] ) : '';
		$share_actions = isset( $detail['share_actions'] ) && is_array( $detail['share_actions'] ) ? $detail['share_actions'] : array();
		$share_url     = ! empty( $share_actions['share_url'] ) ? esc_url_raw( (string) $share_actions['share_url'] ) : '';
		$linkedin_url  = ! empty( $share_actions['linkedin'] ) ? esc_url_raw( (string) $share_actions['linkedin'] ) : '';
		$whatsapp_url  = ! empty( $share_actions['whatsapp'] ) ? esc_url_raw( (string) $share_actions['whatsapp'] ) : '';
		$certificate_url   = '';
		if ( ! empty( $detail['view_url'] ) ) {
			$certificate_url = esc_url_raw( (string) $detail['view_url'] );
		} elseif ( ! empty( $record['view_url'] ) ) {
			$certificate_url = esc_url_raw( (string) $record['view_url'] );
		} elseif ( ! empty( $record['certificate_url'] ) ) {
			$certificate_url = esc_url_raw( (string) $record['certificate_url'] );
		}

		$meta = array(
			'label'      => __( 'En progreso', 'atora-lms' ),
			'badge_class'=> 'is-warning',
		);

		switch ( $certificate_status ) {
			case 'eligible':
				$meta = array(
					'label'      => __( 'Elegible para certificado', 'atora-lms' ),
					'badge_class'=> 'is-success',
				);
				break;
			case 'issued':
			case 'valid':
				$meta = array(
					'label'      => __( 'Certificado válido', 'atora-lms' ),
					'badge_class'=> 'is-success',
				);
				break;
			case 'revoked':
				$meta = array(
					'label'      => __( 'Certificado revocado', 'atora-lms' ),
					'badge_class'=> 'is-warning',
				);
				break;
			case 'pending':
			default:
				$meta = array(
					'label'      => __( 'En progreso', 'atora-lms' ),
					'badge_class'=> 'is-warning',
				);
				break;
		}

		$friendly_notice = '';
		if ( ! $course_ready_for_certificate && ! in_array( $certificate_status, array( 'valid', 'issued', 'eligible' ), true ) ) {
			$friendly_notice = __( 'Tu certificado está en revisión académica. Hay requisitos pendientes de evaluación.', 'atora-lms' );
		}

		return array(
			'status'            => $certificate_status,
			'label'             => $meta['label'],
			'badge_class'       => $meta['badge_class'],
			'missing'           => $missing,
			'required_progress' => $required_progress,
			'required_average'  => $required_average,
			'certificate_url'   => $certificate_url,
			'verification_url'  => $verification_url,
			'share_url'         => $share_url,
			'linkedin_url'      => $linkedin_url,
			'whatsapp_url'      => $whatsapp_url,
			'is_revoked'        => 'revoked' === $certificate_status,
			'course_ready_for_certificate' => $course_ready_for_certificate,
			'configuration_warnings' => $configuration_warnings,
			'friendly_notice'   => $friendly_notice,
			'required_evidences' => $required_evidences,
			'completed_evidences'=> $completed_evidences,
			'required_competencies' => $required_competencies,
			'completed_competencies'=> $completed_competencies,
		);
	}

	protected function build_gamification_panel( $user_id, $status ) {
		$user_id = absint( $user_id );
		$status  = is_array( $status ) ? $status : array();

		$summary_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Gamification_Summary_Service') : null;
		if ( $summary_service && method_exists( $summary_service, 'get_dashboard_summary' ) ) {
			$panel = (array) $summary_service->get_dashboard_summary( $user_id, $status );
			$latest = isset( $panel['latest_event'] ) && is_array( $panel['latest_event'] ) ? $panel['latest_event'] : array();

			return array(
				'points'           => absint( $panel['points'] ?? 0 ),
				'level'            => sanitize_text_field( (string) ( $panel['level'] ?? __( 'Nivel 1', 'atora-lms' ) ) ),
				'streak'           => absint( $panel['streak'] ?? 0 ),
				'next_level_note'  => sanitize_text_field( (string) ( $panel['next_level_note'] ?? '' ) ),
				'last_event_title' => sanitize_text_field( (string) ( $latest['label'] ?? __( 'Sin eventos recientes', 'atora-lms' ) ) ),
				'last_event_date'  => ! empty( $latest['date'] ) ? $this->format_datetime( sanitize_text_field( (string) $latest['date'] ) ) : __( 'Aún sin registro visible', 'atora-lms' ),
				'next_badge'       => sanitize_text_field( (string) ( $panel['next_badge'] ?? '' ) ),
				'pending_badges'   => isset( $panel['pending_badges'] ) && is_array( $panel['pending_badges'] ) ? array_values( array_map( 'sanitize_text_field', array_slice( $panel['pending_badges'], 0, 3 ) ) ) : array(),
				'pathway_progress' => absint( $panel['pathway_progress'] ?? 0 ),
				'narrative'        => sanitize_text_field( (string) ( $panel['narrative'] ?? '' ) ),
			);
		}

		$summary = isset( $status['gamification_summary'] ) && is_array( $status['gamification_summary'] ) ? $status['gamification_summary'] : array();

		$points = isset( $summary['points'] ) ? absint( $summary['points'] ) : 0;
		$level  = isset( $summary['level'] ) ? max( 1, absint( $summary['level'] ) ) : 1;
		$streak = isset( $summary['streak_days'] ) ? absint( $summary['streak_days'] ) : 0;
		$next_points = isset( $summary['points_to_next_level'] ) ? absint( $summary['points_to_next_level'] ) : 0;
		$next_level = isset( $summary['next_level'] ) ? max( $level, absint( $summary['next_level'] ) ) : $level;

		$next_level_note = $next_points > 0
			? sprintf(
				/* translators: 1: puntos faltantes, 2: próximo nivel */
				__( '%1$d pts para nivel %2$d', 'atora-lms' ),
				$next_points,
				$next_level
			)
			: __( 'Ya alcanzaste tu nivel actual máximo', 'atora-lms' );

		$last_event_title = __( 'Sin eventos recientes', 'atora-lms' );
		$last_event_date  = '';

		if ( class_exists( 'CLMS_Gamification_Core' ) && defined( 'CLMS_Gamification_Core::META_LEDGER' ) ) {
			$ledger = get_user_meta( $user_id, constant( 'CLMS_Gamification_Core::META_LEDGER' ), true );
			$ledger = is_array( $ledger ) ? $ledger : array();
			if ( ! empty( $ledger[0] ) && is_array( $ledger[0] ) ) {
				$last_event = $ledger[0];
				$raw_type   = isset( $last_event['event_type'] )
					? sanitize_key( (string) $last_event['event_type'] )
					: ( isset( $last_event['event'] ) ? sanitize_key( (string) $last_event['event'] ) : '' );
				$last_event_title = $this->get_gamification_event_label( $raw_type );
				$raw_date = ! empty( $last_event['date_gmt'] ) ? $last_event['date_gmt'] : ( $last_event['recorded_at'] ?? '' );
				$last_event_date  = ! empty( $raw_date )
					? $this->format_datetime( sanitize_text_field( (string) $raw_date ) )
					: '';
			}
		}

		return array(
			'points'          => $points,
			'level'           => sprintf( __( 'Nivel %d', 'atora-lms' ), $level ),
			'streak'          => $streak,
			'next_level_note' => $next_level_note,
			'last_event_title'=> $last_event_title,
			'last_event_date' => $last_event_date ? $last_event_date : __( 'Aún sin registro visible', 'atora-lms' ),
			'next_badge'      => '',
			'pending_badges'  => array(),
			'pathway_progress'=> 0,
			'narrative'       => __( 'Has demostrado constancia y mejora en tu ruta de aprendizaje.', 'atora-lms' ),
		);
	}

	protected function get_gamification_event_label( $event_type ) {
		$event_type = sanitize_key( (string) $event_type );
		$labels = array(
			'lesson_completed'    => __( 'Lección completada', 'atora-lms' ),
			'submission_sent'     => __( 'Entrega enviada', 'atora-lms' ),
			'assessment_passed'   => __( 'Evaluación aprobada', 'atora-lms' ),
			'evaluation_passed'   => __( 'Evaluación aprobada', 'atora-lms' ),
			'feedback_received'   => __( 'Feedback recibido', 'atora-lms' ),
			'course_completed'    => __( 'Curso completado', 'atora-lms' ),
			'program_completed'   => __( 'Programa completado', 'atora-lms' ),
			'certificate_issued'  => __( 'Certificado emitido', 'atora-lms' ),
			'peer_review_quality' => __( 'Peer review de calidad', 'atora-lms' ),
		);
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$labels = (array) CLMS_Helper::modular_apply( 'dashboard_gamification_labels', $labels, $event_type );
		}

		if ( isset( $labels[ $event_type ] ) ) {
			return $labels[ $event_type ];
		}

		return __( 'Evento académico', 'atora-lms' );
	}

	/**
	 * Etiqueta legible para estado de competencia.
	 *
	 * @param string $status Estado.
	 * @return string
	 */
	protected function get_competency_status_label( $status ) {
		$status = sanitize_key( (string) $status );
		$labels = array(
			'sin_evidencia' => __( 'Sin evidencia', 'atora-lms' ),
			'en_desarrollo' => __( 'En desarrollo', 'atora-lms' ),
			'competente'    => __( 'Competente', 'atora-lms' ),
			'destacado'     => __( 'Destacado', 'atora-lms' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Sin evidencia', 'atora-lms' );
	}

	protected function get_course_cards( $user_id, $course_ids, $academic_statuses = array() ) {
		$user_id    = absint( $user_id );
		$course_ids = is_array( $course_ids ) ? array_map( 'absint', $course_ids ) : array();
		$academic_statuses = is_array( $academic_statuses ) ? $academic_statuses : array();

		if ( empty( $course_ids ) || ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$grading = clms_core('CLMS_Grading');
		$items   = array();

		foreach ( $course_ids as $course_id ) {
			$lessons       = CLMS_Helper::get_course_lessons( $course_id );
				$summary       = array();
				$status_data   = isset( $academic_statuses[ $course_id ] ) && is_array( $academic_statuses[ $course_id ] ) ? $academic_statuses[ $course_id ] : array();
				$progress      = isset( $status_data['progress_percent'] ) ? absint( $status_data['progress_percent'] ) : 0;
				$continue_item = $this->get_continue_item( $user_id, array( $course_id ) );

			if ( 0 === $progress && $grading && method_exists( $grading, 'get_course_grade_summary' ) ) {
				$summary  = $grading->get_course_grade_summary( $user_id, $course_id );
				$progress = isset( $summary['progress_percent'] ) ? absint( $summary['progress_percent'] ) : 0;
			}

				$status_label = __( 'Pendiente', 'atora-lms' );
				$status_class = 'is-warning';
				$has_access   = false;
				if ( ! empty( $lessons ) ) {
					foreach ( array_slice( $lessons, 0, 3 ) as $lesson_id ) {
						if ( CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
							$has_access = true;
							break;
						}
					}
				}

				if ( $progress >= 100 ) {
					$status_label = __( 'Completado', 'atora-lms' );
					$status_class = 'is-success';
				} elseif ( $progress > 0 ) {
					$status_label = __( 'En progreso', 'atora-lms' );
					$status_class = 'is-info';
				} elseif ( ! $has_access && ! empty( $lessons ) ) {
					$status_label = __( 'Bloqueado', 'atora-lms' );
					$status_class = 'is-muted';
				}

				$lessons_count = count( $lessons );
				$lessons_text  = sprintf(
					_n( '%d lección', '%d lecciones', $lessons_count, 'atora-lms' ),
					$lessons_count
				);

				$items[] = array(
					'title'        => get_the_title( $course_id ),
					'course_url'   => get_permalink( $course_id ),
					'continue_url' => ! empty( $continue_item['url'] ) ? $continue_item['url'] : '',
					'progress'     => $progress,
					'lessons_text' => $lessons_text,
					'status_label' => $status_label,
					'status_class' => $status_class,
				);
			}

		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$items = (array) CLMS_Helper::modular_apply( 'dashboard_course_cards', $items, $user_id, $course_ids, $academic_statuses );
		}

		return $items;
	}

	protected function get_upcoming_lessons( $user_id, $course_ids, $limit = 5 ) {
		$user_id    = absint( $user_id );
		$course_ids = is_array( $course_ids ) ? array_map( 'absint', $course_ids ) : array();
		$limit      = max( 1, absint( $limit ) );

		if ( empty( $course_ids ) || ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$items = array();

		foreach ( $course_ids as $course_id ) {
			$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );

			foreach ( $lesson_ids as $lesson_id ) {
				$lesson_id = absint( $lesson_id );

				if ( ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
					continue;
				}

				$due_date_raw = CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_date', '_clms_due_date' ), '' );

					$items[] = array(
						'lesson_title' => get_the_title( $lesson_id ),
						'course_title' => get_the_title( $course_id ),
						'lesson_id'    => $lesson_id,
						'course_id'    => $course_id,
						'due_date'     => $due_date_raw ? $this->format_date( $due_date_raw ) : '',
						'url'          => get_permalink( $lesson_id ),
					);

				if ( count( $items ) >= $limit ) {
					break 2;
				}
			}
		}

		return $items;
	}

	protected function get_metrics( $user_id, $course_ids, $feedback_items, $pending_items, $notification_items, $academic_statuses = array() ) {
		$user_id            = absint( $user_id );
		$course_ids         = is_array( $course_ids ) ? array_map( 'absint', $course_ids ) : array();
		$feedback_items     = is_array( $feedback_items ) ? $feedback_items : array();
		$pending_items      = is_array( $pending_items ) ? $pending_items : array();
		$notification_items = is_array( $notification_items ) ? $notification_items : array();
		$academic_statuses  = is_array( $academic_statuses ) ? $academic_statuses : array();

		$grading        = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading') : null;
		$total_progress = array();
		$total_average  = array();

		foreach ( $course_ids as $course_id ) {
			$course_id = absint( $course_id );
			if ( isset( $academic_statuses[ $course_id ] ) && is_array( $academic_statuses[ $course_id ] ) ) {
				$status_data = $academic_statuses[ $course_id ];
				$total_progress[] = isset( $status_data['progress_percent'] ) ? absint( $status_data['progress_percent'] ) : 0;
				$total_average[]  = isset( $status_data['final_average'] ) ? absint( $status_data['final_average'] ) : 0;
				continue;
			}

			if ( $grading && method_exists( $grading, 'get_course_grade_summary' ) ) {
				$summary = $grading->get_course_grade_summary( $user_id, $course_id );
				$total_progress[] = isset( $summary['progress_percent'] ) ? absint( $summary['progress_percent'] ) : 0;
				$total_average[]  = isset( $summary['final_average'] ) ? absint( $summary['final_average'] ) : 0;
			}
		}

		$progress = ! empty( $total_progress ) ? round( array_sum( $total_progress ) / count( $total_progress ) ) : 0;
		$average  = ! empty( $total_average ) ? round( array_sum( $total_average ) / count( $total_average ) ) : 0;

		$notifications = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Notifications') : null;
		$unread        = 0;

		if ( $notifications && method_exists( $notifications, 'get_unread_count' ) ) {
			$unread = absint( $notifications->get_unread_count( $user_id ) );
		} else {
			foreach ( $notification_items as $item ) {
				if ( empty( $item['is_read'] ) ) {
					$unread++;
				}
			}
		}

		$metrics = array(
			'courses'  => count( $course_ids ),
			'pending'  => count( $pending_items ),
			'feedback' => count( $feedback_items ),
			'unread'   => $unread,
			'progress' => (int) $progress,
			'average'  => (int) $average,
		);

		return apply_filters( 'clms_dashboard_metrics', $metrics, $user_id );
	}

	protected function build_lead_text( $metrics ) {
		$progress = isset( $metrics['progress'] ) ? absint( $metrics['progress'] ) : 0;
		$pending  = isset( $metrics['pending'] ) ? absint( $metrics['pending'] ) : 0;
		$average  = isset( $metrics['average'] ) ? absint( $metrics['average'] ) : 0;

		if ( $pending > 0 ) {
			return sprintf(
				esc_html__( 'Tienes %1$d actividad(es) pendiente(s), un progreso global de %2$d%% y un promedio actual de %3$d%%.', 'atora-lms' ),
				$pending,
				$progress,
				$average
			);
		}

		return sprintf(
			esc_html__( 'Llevas un progreso global de %1$d%% y un promedio actual de %2$d%%.', 'atora-lms' ),
			$progress,
			$average
		);
	}

	protected function get_submission_status_label( $status ) {
		switch ( (string) $status ) {
			case 'graded':
				return __( 'Feedback recibido', 'atora-lms' );
			case 'in_review':
				return __( 'Requiere revisión', 'atora-lms' );
			case 'submitted':
				return __( 'Entrega enviada', 'atora-lms' );
			default:
				return __( 'Pendiente', 'atora-lms' );
		}
	}

	protected function get_submission_badge_class( $status ) {
		switch ( (string) $status ) {
			case 'graded':
				return 'is-success';
			case 'in_review':
				return 'is-warning';
			case 'submitted':
				return 'is-info';
			default:
				return 'is-muted';
		}
	}

	protected function truncate_text( $text, $length = 180 ) {
		$text   = wp_strip_all_tags( (string) $text );
		$length = max( 1, absint( $length ) );

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text ) <= $length ) {
				return $text;
			}
			return mb_substr( $text, 0, $length - 1 ) . '…';
		}

		if ( strlen( $text ) <= $length ) {
			return $text;
		}

		return substr( $text, 0, $length - 1 ) . '…';
	}

	protected function format_datetime( $datetime ) {
		$datetime = (string) $datetime;

		if ( ! $datetime ) {
			return '';
		}

		$timestamp = strtotime( $datetime );

		if ( ! $timestamp ) {
			return $datetime;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	protected function format_date( $date ) {
		$date = (string) $date;

		if ( ! $date ) {
			return '';
		}

		$timestamp = strtotime( $date );

		if ( ! $timestamp ) {
			return $date;
		}

		return wp_date( get_option( 'date_format' ), $timestamp );
	}

}
