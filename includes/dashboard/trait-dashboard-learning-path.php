<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Dashboard_Learning_Path_Trait {
	protected function get_continue_item( $user_id, $course_ids ) {
		$user_id    = absint( $user_id );
		$course_ids = is_array( $course_ids ) ? array_map( 'absint', $course_ids ) : array();

		if ( empty( $course_ids ) || ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$grading = clms_core('CLMS_Grading');

		foreach ( $course_ids as $course_id ) {
			$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );

			foreach ( $lesson_ids as $lesson_id ) {
				$lesson_id = absint( $lesson_id );

				if ( ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
					continue;
				}

				$is_completed = false;

				if ( $grading && method_exists( $grading, 'is_lesson_completed_by_user' ) ) {
					$is_completed = (bool) $grading->is_lesson_completed_by_user( $user_id, $lesson_id );
				}

				if ( ! $is_completed ) {
					return array(
						'lesson_id'    => $lesson_id,
						'lesson_title' => get_the_title( $lesson_id ),
						'course_id'    => $course_id,
						'course_title' => get_the_title( $course_id ),
						'url'          => get_permalink( $lesson_id ),
					);
				}
			}
		}

		return array();
	}

	protected function get_next_step_item( $continue_item, $pending_items, $lesson_items, $user_id = 0, $course_ids = array() ) {
		$user_id    = absint( $user_id );
		$course_ids = is_array( $course_ids ) ? array_values( array_filter( array_map( 'absint', $course_ids ) ) ) : array();

		$next_step_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Student_Next_Step_Service') : null;
		if ( $next_step_service && method_exists( $next_step_service, 'resolve_next_step' ) ) {
			$step = (array) $next_step_service->resolve_next_step( $user_id, $continue_item, $pending_items, $lesson_items, $course_ids );
			if ( ! empty( $step['title'] ) ) {
				return array(
					'title'        => sanitize_text_field( (string) $step['title'] ),
					'meta'         => sanitize_text_field( (string) ( $step['meta'] ?? '' ) ),
					'description'  => sanitize_text_field( (string) ( $step['description'] ?? '' ) ),
					'url'          => esc_url_raw( (string) ( $step['url'] ?? '' ) ),
					'lesson_id'    => absint( $step['lesson_id'] ?? 0 ),
					'course_id'    => absint( $step['course_id'] ?? 0 ),
					'is_evaluable' => ! empty( $step['is_evaluable'] ),
					'type'         => sanitize_key( (string) ( $step['type'] ?? '' ) ),
					'button_label' => sanitize_text_field( (string) ( $step['button_label'] ?? '' ) ),
					'priority'     => sanitize_key( (string) ( $step['priority'] ?? '' ) ),
				);
			}
		}

		if ( ! empty( $pending_items ) ) {
			$first = $pending_items[0];
			return array(
				'title'       => $first['lesson_title'] ?? __( 'Actividad pendiente', 'atora-lms' ),
				'meta'        => $first['course_title'] ?? '',
				'description' => $first['description'] ?? __( 'Tienes una actividad por completar.', 'atora-lms' ),
				'url'         => $first['url'] ?? '',
				'lesson_id'   => $first['lesson_id'] ?? 0,
				'course_id'   => $first['course_id'] ?? 0,
				'is_evaluable' => ! empty( $first['is_evaluable'] ),
			);
		}

		if ( ! empty( $continue_item ) ) {
			$is_evaluable = false;
			if ( ! empty( $continue_item['lesson_id'] ) && class_exists( 'CLMS_Helper' ) ) {
				$activity_type = strtolower( (string) CLMS_Helper::get_post_meta_first( $continue_item['lesson_id'], array( 'lm_activity_type', '_clms_activity_mode' ), '' ) );
				$is_evaluable = in_array( $activity_type, array( 'quiz', 'evaluacion', 'evaluation', 'tarea', 'task', 'assignment' ), true );
			}
			return array(
				'title'       => $continue_item['lesson_title'] ?? __( 'Siguiente lección', 'atora-lms' ),
				'meta'        => $continue_item['course_title'] ?? '',
				'description' => __( 'Retoma el contenido para mantener el ritmo de avance.', 'atora-lms' ),
				'url'         => $continue_item['url'] ?? '',
				'lesson_id'   => $continue_item['lesson_id'] ?? 0,
				'course_id'   => $continue_item['course_id'] ?? 0,
				'is_evaluable' => $is_evaluable,
			);
		}

		if ( ! empty( $lesson_items ) ) {
			$first = $lesson_items[0];
			$is_evaluable = false;
			if ( ! empty( $first['lesson_id'] ) && class_exists( 'CLMS_Helper' ) ) {
				$activity_type = strtolower( (string) CLMS_Helper::get_post_meta_first( $first['lesson_id'], array( 'lm_activity_type', '_clms_activity_mode' ), '' ) );
				$is_evaluable = in_array( $activity_type, array( 'quiz', 'evaluacion', 'evaluation', 'tarea', 'task', 'assignment' ), true );
			}
			return array(
				'title'       => $first['lesson_title'] ?? __( 'Siguiente lección', 'atora-lms' ),
				'meta'        => $first['course_title'] ?? '',
				'description' => __( 'Explora esta lección para seguir avanzando.', 'atora-lms' ),
				'url'         => $first['url'] ?? '',
				'lesson_id'   => $first['lesson_id'] ?? 0,
				'course_id'   => $first['course_id'] ?? 0,
				'is_evaluable' => $is_evaluable,
			);
		}

		return array();
	}

	protected function get_primary_action( $continue_item, $next_step, $course_ids ) {
		if ( ! empty( $continue_item['url'] ) ) {
			return array(
				'url'   => $continue_item['url'],
				'label' => __( 'Continuar ahora', 'atora-lms' ),
			);
		}

		if ( ! empty( $next_step['url'] ) ) {
			return array(
				'url'   => $next_step['url'],
				'label' => ! empty( $next_step['button_label'] ) ? sanitize_text_field( (string) $next_step['button_label'] ) : __( 'Ir al siguiente paso', 'atora-lms' ),
			);
		}

		if ( ! empty( $course_ids ) ) {
			$course_id = absint( $course_ids[0] );
			if ( $course_id ) {
				return array(
					'url'   => get_permalink( $course_id ),
					'label' => __( 'Ver mi curso', 'atora-lms' ),
				);
			}
		}

		return array();
	}

	protected function build_journey_items( $continue_item, $lesson_items, $limit = 4 ) {
		$limit = max( 1, absint( $limit ) );
		$items = array();
		$seen  = array();

		if ( ! empty( $continue_item['lesson_id'] ) ) {
			$lesson_id = absint( $continue_item['lesson_id'] );
			$items[]   = array(
				'title'       => $continue_item['lesson_title'] ?? __( 'Lección en curso', 'atora-lms' ),
				'meta'        => $continue_item['course_title'] ?? '',
				'label'       => __( 'En curso', 'atora-lms' ),
				'badge_class' => 'is-info',
				'url'         => $continue_item['url'] ?? '',
			);
			if ( $lesson_id ) {
				$seen[ $lesson_id ] = true;
			}
		}

		$lesson_items = is_array( $lesson_items ) ? $lesson_items : array();
		foreach ( $lesson_items as $lesson ) {
			$lesson_id = ! empty( $lesson['lesson_id'] ) ? absint( $lesson['lesson_id'] ) : 0;
			if ( $lesson_id && isset( $seen[ $lesson_id ] ) ) {
				continue;
			}
			$meta = $lesson['course_title'] ?? '';
			if ( ! empty( $lesson['due_date'] ) ) {
				$meta = trim( $meta . ' · ' . sprintf( __( 'Vence %s', 'atora-lms' ), $lesson['due_date'] ) );
			}
			$items[] = array(
				'title'       => $lesson['lesson_title'] ?? __( 'Siguiente lección', 'atora-lms' ),
				'meta'        => $meta,
				'label'       => __( 'Siguiente', 'atora-lms' ),
				'badge_class' => ! empty( $lesson['due_date'] ) ? 'is-warning' : 'is-muted',
				'url'         => $lesson['url'] ?? '',
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	protected function build_learning_route_context( $continue_item, $next_step, $pending_items, $feedback_items, $course_cards, $lesson_items, $metrics, $memory_insights, $primary_cta ) {
		$course_title = '';
		$course_id    = 0;
		if ( ! empty( $continue_item['course_id'] ) ) {
			$course_id    = absint( $continue_item['course_id'] );
			$course_title = (string) ( $continue_item['course_title'] ?? '' );
		} elseif ( ! empty( $next_step['course_id'] ) ) {
			$course_id    = absint( $next_step['course_id'] );
			$course_title = (string) ( $next_step['meta'] ?? '' );
		} elseif ( ! empty( $course_cards[0]['title'] ) ) {
			$course_title = (string) $course_cards[0]['title'];
		}

		$next_lesson = array(
			'title'      => __( 'Próxima lección', 'atora-lms' ),
			'meta'       => ! empty( $next_step['title'] ) ? (string) $next_step['title'] : __( 'Sin lección disponible por ahora', 'atora-lms' ),
			'label'      => __( 'En progreso', 'atora-lms' ),
			'badge_class'=> 'is-info',
			'url'        => ! empty( $next_step['url'] ) ? (string) $next_step['url'] : '',
		);

		$next_submission = array(
			'title'       => __( 'Próxima entrega', 'atora-lms' ),
			'meta'        => __( 'No tienes entregas pendientes ahora mismo.', 'atora-lms' ),
			'label'       => __( 'Completado', 'atora-lms' ),
			'badge_class' => 'is-success',
			'url'         => '',
		);

		foreach ( (array) $pending_items as $pending_item ) {
			if ( ! empty( $pending_item['is_evaluable'] ) ) {
				$next_submission = array(
					'title'       => __( 'Próxima entrega', 'atora-lms' ),
					'meta'        => (string) ( $pending_item['lesson_title'] ?? __( 'Actividad evaluable pendiente', 'atora-lms' ) ),
					'label'       => __( 'Pendiente', 'atora-lms' ),
					'badge_class' => 'is-warning',
					'url'         => (string) ( $pending_item['url'] ?? '' ),
				);
				if ( ! empty( $pending_item['course_id'] ) ) {
					$course_id = absint( $pending_item['course_id'] );
				}
				break;
			}
		}

		$progress = isset( $metrics['progress'] ) ? absint( $metrics['progress'] ) : 0;
		if ( ! empty( $course_cards[0]['progress'] ) ) {
			$progress = absint( $course_cards[0]['progress'] );
		}
		$pending_count = isset( $metrics['pending'] ) ? absint( $metrics['pending'] ) : count( (array) $pending_items );

		$last_feedback = '';
		if ( ! empty( $feedback_items[0]['feedback'] ) ) {
			$last_feedback = $this->truncate_text( (string) $feedback_items[0]['feedback'], 140 );
		}

		$improvement_tip = '';
		if ( ! empty( $memory_insights['recommendation'] ) ) {
			$improvement_tip = (string) $memory_insights['recommendation'];
		} elseif ( ! empty( $feedback_items[0]['feedback'] ) ) {
			$improvement_tip = $this->truncate_text( (string) $feedback_items[0]['feedback'], 120 );
		}

		$resource = array();
		if ( ! empty( $memory_insights['cta']['url'] ) ) {
			$resource = array(
				'label' => ! empty( $memory_insights['cta']['label'] ) ? (string) $memory_insights['cta']['label'] : __( 'Ver recurso sugerido', 'atora-lms' ),
				'url'   => (string) $memory_insights['cta']['url'],
			);
		} elseif ( ! empty( $lesson_items[0]['url'] ) ) {
			$resource = array(
				'label' => __( 'Ver recurso sugerido', 'atora-lms' ),
				'url'   => (string) $lesson_items[0]['url'],
			);
		}

		$state = $this->get_learning_route_state( $pending_count, $progress, $memory_insights );

		return array(
			'primary'       => is_array( $primary_cta ) ? $primary_cta : array(),
			'course_id'     => $course_id,
			'course_title'  => $course_title,
			'progress'      => $progress,
			'pending_count' => $pending_count,
			'last_feedback' => $last_feedback,
			'improvement_tip' => $improvement_tip,
			'resource'      => $resource,
			'items'         => array( $next_lesson, $next_submission ),
			'state_label'   => $state['label'],
			'state_class'   => $state['class'],
		);
	}

	protected function get_learning_route_state( $pending_count, $progress, $memory_insights ) {
		$pending_count = absint( $pending_count );
		$progress      = absint( $progress );
		$needs_attention = ! empty( $memory_insights['needs_attention'] );

		if ( $progress >= 100 && 0 === $pending_count ) {
			return array(
				'label' => __( 'Completado', 'atora-lms' ),
				'class' => 'is-success',
			);
		}

		if ( $needs_attention ) {
			return array(
				'label' => __( 'En riesgo', 'atora-lms' ),
				'class' => 'is-warning',
			);
		}

		if ( $pending_count > 0 ) {
			return array(
				'label' => __( 'Pendiente', 'atora-lms' ),
				'class' => 'is-warning',
			);
		}

		if ( $progress > 0 ) {
			return array(
				'label' => __( 'Al día', 'atora-lms' ),
				'class' => 'is-info',
			);
		}

		return array(
			'label' => __( 'En progreso', 'atora-lms' ),
			'class' => 'is-info',
		);
	}

	protected function build_feedback_improvement_plan( $feedback_items, $memory_insights ) {
		$feedback_items = is_array( $feedback_items ) ? $feedback_items : array();
		if ( empty( $feedback_items[0] ) || ! is_array( $feedback_items[0] ) ) {
			return array();
		}

		$item          = $feedback_items[0];
		$submission_id = ! empty( $item['submission_id'] ) ? absint( $item['submission_id'] ) : 0;
		$plan_service  = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Improvement_Plan_Service') : null;
		$service_plan  = ( $plan_service && method_exists( $plan_service, 'build_from_submission' ) && $submission_id )
			? (array) $plan_service->build_from_submission( $submission_id )
			: array();
		if ( $plan_service && method_exists( $plan_service, 'normalize_plan' ) ) {
			$service_plan = (array) $plan_service->normalize_plan( $service_plan, isset( $item['description'] ) ? (string) $item['description'] : '' );
		}
		$criteria      = $submission_id ? $this->extract_submission_criteria_insights( $submission_id ) : array(
			'strengths' => array(),
			'reinforce' => array(),
		);

		$resource = array();
		if ( ! empty( $item['url'] ) ) {
			$resource = array(
				'label' => __( 'Ir a la lección relacionada', 'atora-lms' ),
				'url'   => (string) $item['url'],
			);
		} elseif ( ! empty( $memory_insights['cta']['url'] ) ) {
			$resource = array(
				'label' => ! empty( $memory_insights['cta']['label'] ) ? (string) $memory_insights['cta']['label'] : __( 'Ver recurso recomendado', 'atora-lms' ),
				'url'   => (string) $memory_insights['cta']['url'],
			);
		}

		$recommendation = '';
		if ( ! empty( $service_plan['next_action'] ) ) {
			$recommendation = (string) $service_plan['next_action'];
		} elseif ( ! empty( $service_plan['recommendation'] ) ) {
			$recommendation = (string) $service_plan['recommendation'];
		} elseif ( ! empty( $service_plan['recommendations'][0] ) ) {
			$recommendation = (string) $service_plan['recommendations'][0];
		} elseif ( ! empty( $memory_insights['recommendation'] ) ) {
			$recommendation = (string) $memory_insights['recommendation'];
		} elseif ( ! empty( $criteria['reinforce'][0] ) ) {
			$recommendation = sprintf(
				/* translators: %s: criterio a reforzar */
				__( 'Dedica una sesión corta a reforzar: %s.', 'atora-lms' ),
				$criteria['reinforce'][0]
			);
		}

		return array(
			'title'        => ! empty( $item['lesson_title'] ) ? (string) $item['lesson_title'] : __( 'Entrega revisada', 'atora-lms' ),
			'grade'        => ( '' !== (string) ( $item['grade'] ?? '' ) ) ? absint( $item['grade'] ) : '',
			'feedback'     => ! empty( $item['feedback'] ) ? $this->truncate_text( (string) $item['feedback'], 260 ) : '',
			'summary'      => ! empty( $service_plan['summary'] ) ? (string) $service_plan['summary'] : $recommendation,
			'strengths'    => ! empty( $service_plan['strengths'] ) ? array_slice( (array) $service_plan['strengths'], 0, 3 ) : array_slice( (array) $criteria['strengths'], 0, 3 ),
			'reinforce'    => ! empty( $service_plan['weaknesses'] ) ? array_slice( (array) $service_plan['weaknesses'], 0, 3 ) : array_slice( (array) $criteria['reinforce'], 0, 3 ),
			'weaknesses'   => ! empty( $service_plan['weaknesses'] ) ? array_slice( (array) $service_plan['weaknesses'], 0, 3 ) : array_slice( (array) $criteria['reinforce'], 0, 3 ),
			'next_action'  => ! empty( $service_plan['next_action'] ) ? (string) $service_plan['next_action'] : '',
			'recommendations' => ! empty( $service_plan['recommendations'] ) && is_array( $service_plan['recommendations'] ) ? $service_plan['recommendations'] : array(),
			'recommendation' => $recommendation,
			'generated_by' => ! empty( $service_plan['generated_by'] ) ? sanitize_key( (string) $service_plan['generated_by'] ) : 'fallback',
			'resource'     => $resource,
			'feedback_url' => ! empty( $item['url'] ) ? (string) $item['url'] : '',
		);
	}

	protected function extract_submission_criteria_insights( $submission_id ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return array(
				'strengths' => array(),
				'reinforce' => array(),
			);
		}

		$raw_scores = get_post_meta( $submission_id, '_clms_submission_rubric_scores', true );
		if ( ! is_array( $raw_scores ) || empty( $raw_scores ) ) {
			$raw_scores = get_post_meta( $submission_id, '_clms_ai_review_criteria_scores', true );
		}
		if ( ! is_array( $raw_scores ) || empty( $raw_scores ) ) {
			return array(
				'strengths' => array(),
				'reinforce' => array(),
			);
		}

		$items = array();
		foreach ( $raw_scores as $key => $criterion_raw ) {
			$normalized = $this->normalize_submission_criterion( $key, $criterion_raw );
			if ( empty( $normalized['label'] ) || $normalized['max'] <= 0 ) {
				continue;
			}
			$items[] = $normalized;
		}

		if ( empty( $items ) ) {
			return array(
				'strengths' => array(),
				'reinforce' => array(),
			);
		}

		usort(
			$items,
			static function( $a, $b ) {
				if ( $a['ratio'] === $b['ratio'] ) {
					return 0;
				}
				return ( $a['ratio'] > $b['ratio'] ) ? -1 : 1;
			}
		);

		$strengths = array();
		$reinforce = array();

		foreach ( $items as $item ) {
			if ( $item['ratio'] >= 0.75 ) {
				$strengths[] = $item['label'];
			} elseif ( $item['ratio'] <= 0.55 ) {
				$reinforce[] = $item['label'];
			}
		}

		if ( empty( $strengths ) ) {
			$strengths[] = $items[0]['label'];
		}
		if ( empty( $reinforce ) && ! empty( $items[ count( $items ) - 1 ]['label'] ) ) {
			$reinforce[] = $items[ count( $items ) - 1 ]['label'];
		}

		return array(
			'strengths' => array_values( array_unique( $strengths ) ),
			'reinforce' => array_values( array_unique( $reinforce ) ),
		);
	}

	protected function normalize_submission_criterion( $fallback_key, $criterion_raw ) {
		$fallback_key = is_string( $fallback_key ) ? $fallback_key : '';
		$data = is_array( $criterion_raw ) ? $criterion_raw : array( 'score' => $criterion_raw );

		$label = '';
		if ( ! empty( $data['label'] ) ) {
			$label = (string) $data['label'];
		} elseif ( ! empty( $data['criterion'] ) ) {
			$label = (string) $data['criterion'];
		} elseif ( $fallback_key ) {
			$label = ucwords( str_replace( '_', ' ', sanitize_text_field( $fallback_key ) ) );
		}

		$score = isset( $data['score'] ) ? floatval( $data['score'] ) : 0.0;
		if ( isset( $data['points'] ) ) {
			$score = floatval( $data['points'] );
		}

		$max = 0.0;
		if ( isset( $data['max_points'] ) ) {
			$max = floatval( $data['max_points'] );
		} elseif ( isset( $data['max'] ) ) {
			$max = floatval( $data['max'] );
		} elseif ( isset( $data['total'] ) ) {
			$max = floatval( $data['total'] );
		}

		if ( $max <= 0 && isset( $data['weight'] ) ) {
			$max = floatval( $data['weight'] );
		}
		if ( $max <= 0 ) {
			$max = 100.0;
		}

		$score = max( 0.0, min( $score, $max ) );
		$ratio = $max > 0 ? ( $score / $max ) : 0;

		return array(
			'label' => sanitize_text_field( $label ),
			'score' => $score,
			'max'   => $max,
			'ratio' => $ratio,
		);
	}

	protected function get_student_assistant_context( $course_id = 0 ) {
		$course_id = absint( $course_id );
		$enabled   = false;

		$ai_manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
		if ( $ai_manager && method_exists( $ai_manager, 'is_configured' ) ) {
			$enabled = (bool) $ai_manager->is_configured();
		}

		if ( ! $course_id && ! empty( $GLOBALS['post'] ) && is_object( $GLOBALS['post'] ) ) {
			$post_id = absint( $GLOBALS['post']->ID );
			if ( $post_id && 'lm_course' === get_post_type( $post_id ) ) {
				$course_id = $post_id;
			}
		}

		$widget = '';
		if ( $enabled && $course_id > 0 && function_exists( 'shortcode_exists' ) && shortcode_exists( 'clms_student_chat' ) ) {
			$widget = do_shortcode( '[clms_student_chat course_id="' . absint( $course_id ) . '" mode="academic"]' );
		}

		return array(
			'enabled'  => $enabled,
			'course_id'=> $course_id,
			'shortcut' => $course_id > 0 ? '#clms-sa-' . $course_id . '-panel' : '',
			'widget'   => $widget,
		);
	}

	protected function get_onboarding_context( $user_id, $course_ids, $continue_item, $profile_snapshot ) {
		$user_id    = absint( $user_id );
		$course_ids = is_array( $course_ids ) ? array_values( array_filter( array_map( 'absint', $course_ids ) ) ) : array();

		if ( ! $user_id || empty( $course_ids ) || ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$completed_lessons = get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed_lessons = is_array( $completed_lessons ) ? array_map( 'absint', $completed_lessons ) : array();
		$enrollment_dates  = get_user_meta( $user_id, CLMS_Helper::USER_ENROLLMENT_DATES_META, true );
		$enrollment_dates  = is_array( $enrollment_dates ) ? $enrollment_dates : array();

		$target_course_id = 0;
		$target_ts        = 0;

		foreach ( $course_ids as $course_id ) {
			$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );
			$lesson_ids = is_array( $lesson_ids ) ? array_map( 'absint', $lesson_ids ) : array();
			$completed_in_course = $lesson_ids ? count( array_intersect( $lesson_ids, $completed_lessons ) ) : 0;

			if ( $completed_in_course > 0 ) {
				continue;
			}

			$enrolled_at = isset( $enrollment_dates[ $course_id ] ) ? (string) $enrollment_dates[ $course_id ] : '';
			$timestamp   = $enrolled_at ? strtotime( $enrolled_at ) : 0;

			if ( ! $target_course_id || $timestamp >= $target_ts ) {
				$target_course_id = $course_id;
				$target_ts        = $timestamp;
			}
		}

		if ( ! $target_course_id ) {
			return array();
		}

		$course_title = get_the_title( $target_course_id );
		$course_url   = get_permalink( $target_course_id );

		$lesson_id    = 0;
		$lesson_title = '';
		$lesson_ids   = CLMS_Helper::get_course_lessons( $target_course_id );
		$lesson_ids   = is_array( $lesson_ids ) ? array_map( 'absint', $lesson_ids ) : array();

		if ( ! empty( $continue_item['lesson_id'] ) && absint( $continue_item['course_id'] ?? 0 ) === $target_course_id ) {
			$lesson_id    = absint( $continue_item['lesson_id'] );
			$lesson_title = $continue_item['lesson_title'] ?? '';
		}

		if ( ! $lesson_id ) {
			foreach ( $lesson_ids as $lid ) {
				if ( CLMS_Helper::user_can_access_lesson( $user_id, $lid ) ) {
					$lesson_id    = $lid;
					$lesson_title = get_the_title( $lid );
					break;
				}
			}
		}

		$profile_url  = '';
		if ( function_exists( 'clms_core' ) && clms_core() && method_exists( clms_core(), 'get_module' ) ) {
			$student_profile = clms_core()->get_module( 'CLMS_Student_Profile' );
			$profile_url     = $student_profile && method_exists( $student_profile, 'get_profile_url' )
				? $student_profile->get_profile_url( $user_id )
				: '';
		}

		$profile_ready = ! empty( $profile_snapshot['has_details'] );

		$items = array(
			array(
				'title' => __( 'Inscripción confirmada', 'atora-lms' ),
				'meta'  => $course_title,
				'done'  => true,
			),
			array(
				'title' => __( 'Comenzar tu primera lección', 'atora-lms' ),
				'meta'  => $lesson_title ? $lesson_title : __( 'Primer contenido disponible', 'atora-lms' ),
				'done'  => false,
			),
			array(
				'title' => __( 'Completar tu perfil', 'atora-lms' ),
				'meta'  => __( 'Personaliza recomendaciones y acompañamiento', 'atora-lms' ),
				'done'  => $profile_ready,
			),
		);

		$primary = array();
		if ( $lesson_id ) {
			$primary = array(
				'url'   => get_permalink( $lesson_id ),
				'label' => __( 'Empezar ahora', 'atora-lms' ),
			);
		} elseif ( $course_url ) {
			$primary = array(
				'url'   => $course_url,
				'label' => __( 'Ver el curso', 'atora-lms' ),
			);
		}

		$secondary = array();
		if ( ! $profile_ready && $profile_url ) {
			$secondary = array(
				'url'   => $profile_url,
				'label' => __( 'Completar perfil', 'atora-lms' ),
			);
		}

		return array(
			'course_id'    => $target_course_id,
			'course_title' => $course_title,
			'course_url'   => $course_url,
			'items'        => $items,
			'message'      => __( 'Ya tienes tu curso listo. Te dejamos una ruta rápida para comenzar con claridad.', 'atora-lms' ),
			'primary'      => $primary,
			'secondary'    => $secondary,
		);
	}

	protected function get_recent_submission_overview( $user_id, $next_step = array() ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! class_exists( 'CLMS_Submission' ) ) {
			return array();
		}

		$next_step = is_array( $next_step ) ? $next_step : array();
		$lesson_id = ! empty( $next_step['lesson_id'] ) ? absint( $next_step['lesson_id'] ) : 0;

		if ( $lesson_id && ! empty( $next_step['is_evaluable'] ) ) {
			$submission_module = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Submission') : null;
			if ( $submission_module && method_exists( $submission_module, 'get_user_submission_for_grading' ) ) {
				$submission = $submission_module->get_user_submission_for_grading( $user_id, $lesson_id );
				if ( ! empty( $submission['submission_id'] ) ) {
					$submission_id = absint( $submission['submission_id'] );
					$course_id     = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
					$grade         = $this->get_submission_grade_value( $submission_id );

					return array(
						'submission_id' => $submission_id,
						'lesson_title'  => get_the_title( $lesson_id ),
						'course_title'  => $course_id ? get_the_title( $course_id ) : '',
						'grade'         => $grade,
						'timeline'      => $this->build_evaluation_timeline( $submission_id, $grade ),
					);
				}
			}
		}

		$submission_ids = get_posts(
			array(
				'post_type'      => CLMS_Submission::CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $user_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		if ( empty( $submission_ids ) ) {
			return array();
		}

		$submission_id = absint( $submission_ids[0] );
		$lesson_id     = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$course_id     = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		$grade         = $this->get_submission_grade_value( $submission_id );

		return array(
			'submission_id' => $submission_id,
			'lesson_title'  => $lesson_id ? get_the_title( $lesson_id ) : __( 'Entrega reciente', 'atora-lms' ),
			'course_title'  => $course_id ? get_the_title( $course_id ) : '',
			'grade'         => $grade,
			'timeline'      => $this->build_evaluation_timeline( $submission_id, $grade ),
		);
	}

	protected function get_submission_grade_value( $submission_id ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return '';
		}

		$grade = get_post_meta( $submission_id, '_clms_submission_grade', true );
		if ( '' === (string) $grade ) {
			$grade = get_post_meta( $submission_id, '_clms_final_grade', true );
		}

		return '' !== (string) $grade ? absint( $grade ) : '';
	}

	protected function build_evaluation_timeline( $submission_id, $grade ) {
		$submission_id = absint( $submission_id );
		$grade         = '' !== (string) $grade ? absint( $grade ) : '';

		if ( ! $submission_id ) {
			return array();
		}

		$status          = (string) get_post_meta( $submission_id, '_clms_submission_status', true );
		$ai_review_status = (string) get_post_meta( $submission_id, '_clms_ai_review_status', true );
		$grade_source    = (string) get_post_meta( $submission_id, '_clms_grade_source', true );
		$manual_override = (bool) get_post_meta( $submission_id, '_clms_grade_manual_override', true );

		$submitted = true;
		$graded    = ( 'graded' === $status ) || ( '' !== (string) $grade );
		$in_review = ! $graded;

		$ai_evaluated     = false;
		$teacher_reviewed = false;

		$assessment = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Assessment_Engine') : null;
		if ( $assessment && method_exists( $assessment, 'get_submission_audit_log' ) ) {
			$audit_log = $assessment->get_submission_audit_log( $submission_id );
			if ( is_array( $audit_log ) ) {
				foreach ( $audit_log as $event ) {
					$source = sanitize_key( (string) ( $event['source'] ?? '' ) );
					$mode   = sanitize_key( (string) ( $event['evaluation_mode'] ?? '' ) );
					$override = ! empty( $event['override_manual'] );

					if ( $override || 'manual' === $source ) {
						$teacher_reviewed = true;
					}
					if ( false !== strpos( $source, 'ai' ) || false !== strpos( $mode, 'ai' ) ) {
						$ai_evaluated = true;
					}
				}
			}
		}

		if ( ! $ai_evaluated ) {
			if ( in_array( $ai_review_status, array( 'completed', 'graded', 'done', 'success' ), true ) ) {
				$ai_evaluated = true;
			} elseif ( false !== strpos( $grade_source, 'ai' ) ) {
				$ai_evaluated = true;
			}
		}

		if ( ! $teacher_reviewed ) {
			$teacher_reviewed = $manual_override || ( 'manual' === $grade_source && $graded );
		}

		$steps = array(
			array( 'label' => __( 'Entregado', 'atora-lms' ), 'show' => $submitted ),
			array( 'label' => __( 'En revisión', 'atora-lms' ), 'show' => $in_review ),
			array( 'label' => __( 'Evaluado por IA', 'atora-lms' ), 'show' => $ai_evaluated ),
			array( 'label' => __( 'Revisado por docente', 'atora-lms' ), 'show' => $teacher_reviewed ),
			array( 'label' => __( 'Nota final', 'atora-lms' ), 'show' => $graded ),
		);

		$visible = array_values( array_filter( $steps, static function( $step ) {
			return ! empty( $step['show'] );
		} ) );

		$last_index = count( $visible ) - 1;

		foreach ( $visible as $index => &$step ) {
			$step['done']   = $index < $last_index;
			$step['active'] = $index === $last_index;
		}
		unset( $step );

		return $visible;
	}

	protected function get_alert_summary( $metrics, $alert_items ) {
		$metrics = is_array( $metrics ) ? $metrics : array();
		$pending = isset( $metrics['pending'] ) ? absint( $metrics['pending'] ) : 0;
		$feedback = isset( $metrics['feedback'] ) ? absint( $metrics['feedback'] ) : 0;

		if ( $pending <= 0 && $feedback <= 0 && empty( $alert_items ) ) {
			return '';
		}

		if ( $pending > 0 && $feedback > 0 ) {
			return sprintf(
				esc_html__( 'Tienes %1$d pendiente(s) y %2$d entrega(s) con feedback reciente.', 'atora-lms' ),
				$pending,
				$feedback
			);
		}
		if ( $pending > 0 ) {
			return sprintf(
				esc_html__( 'Tienes %d pendiente(s) por completar.', 'atora-lms' ),
				$pending
			);
		}
		if ( $feedback > 0 ) {
			return sprintf(
				esc_html__( 'Tienes %d entrega(s) con feedback reciente.', 'atora-lms' ),
				$feedback
			);
		}

		return '';
	}
	protected function render_evaluation_timeline( $steps ) {
		$steps = is_array( $steps ) ? $steps : array();
		if ( empty( $steps ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="clms-submission-timeline" role="list">
			<?php foreach ( $steps as $step ) :
				$classes = array( 'clms-submission-step' );
				if ( ! empty( $step['done'] ) ) { $classes[] = 'is-done'; }
				if ( ! empty( $step['active'] ) ) { $classes[] = 'is-active'; }
				?>
				<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" role="listitem">
					<span class="clms-submission-dot" aria-hidden="true"></span>
					<span class="clms-submission-label"><?php echo esc_html( $step['label'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	protected function get_alert_items( $pending_items, $feedback_items, $notification_items, $limit = 3 ) {
		$alerts = array();
		$limit  = max( 1, absint( $limit ) );

		foreach ( (array) $pending_items as $item ) {
			$alerts[] = array(
				'title'       => $item['lesson_title'] ?? __( 'Actividad pendiente', 'atora-lms' ),
				'meta'        => $item['course_title'] ?? '',
				'description' => $item['description'] ?? __( 'Tienes una actividad por completar.', 'atora-lms' ),
				'label'       => $item['label'] ?? __( 'Pendiente', 'atora-lms' ),
				'badge_class' => $item['badge_class'] ?? 'is-warning',
				'url'         => $item['url'] ?? '',
			);
			if ( count( $alerts ) >= $limit ) {
				return array_slice( $alerts, 0, $limit );
			}
		}

		foreach ( (array) $feedback_items as $item ) {
			$alerts[] = array(
				'title'       => $item['lesson_title'] ?? __( 'Revisión disponible', 'atora-lms' ),
				'meta'        => $item['course_title'] ?? '',
				'description' => __( 'Ya tienes comentarios que pueden ayudarte a mejorar.', 'atora-lms' ),
				'label'       => $item['status_label'] ?? __( 'Revisión', 'atora-lms' ),
				'badge_class' => $item['badge_class'] ?? 'is-info',
				'url'         => $item['url'] ?? '',
			);
			if ( count( $alerts ) >= $limit ) {
				return array_slice( $alerts, 0, $limit );
			}
		}

		foreach ( (array) $notification_items as $item ) {
			$alerts[] = array(
				'title'       => $item['title'] ?? __( 'Aviso reciente', 'atora-lms' ),
				'meta'        => ! empty( $item['created_at'] ) ? $this->format_datetime( $item['created_at'] ) : '',
				'description' => $this->truncate_text( (string) ( $item['message'] ?? '' ), 120 ),
				'label'       => __( 'Aviso', 'atora-lms' ),
				'badge_class' => 'is-info',
				'url'         => $item['link'] ?? '',
			);
			if ( count( $alerts ) >= $limit ) {
				return array_slice( $alerts, 0, $limit );
			}
		}

		return array_slice( $alerts, 0, $limit );
	}

	protected function get_student_profile_snapshot( $user_id ) {
		$user_id = absint( $user_id );

		$profile = array(
			'interests' => (string) get_user_meta( $user_id, '_clms_student_interests', true ),
			'level'     => (string) get_user_meta( $user_id, '_clms_student_level', true ),
			'goals'     => (string) get_user_meta( $user_id, '_clms_student_goals', true ),
			'area'      => (string) get_user_meta( $user_id, '_clms_student_area', true ),
			'consent'   => (bool) get_user_meta( $user_id, '_clms_student_personalization_consent', true ),
		);

		$summary_parts = array();

		if ( $profile['area'] ) {
			$summary_parts[] = $profile['area'];
		}

		if ( $profile['level'] ) {
			$summary_parts[] = ucfirst( $profile['level'] );
		}

		if ( $profile['interests'] ) {
			$summary_parts[] = $this->truncate_text( $profile['interests'], 90 );
		}

		return array(
			'summary' => implode( ' · ', array_filter( $summary_parts ) ),
			'goals'   => $profile['goals'] ? $this->truncate_text( $profile['goals'], 140 ) : '',
			'consent' => $profile['consent'],
			'has_details' => (bool) ( $profile['interests'] || $profile['level'] || $profile['goals'] || $profile['area'] ),
		);
	}

}
