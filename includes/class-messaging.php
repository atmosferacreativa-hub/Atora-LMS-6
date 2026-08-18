<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Messaging {

	const META_KEY             = 'clms_internal_messages';
	const META_LAST_RULES      = '_clms_messaging_last_rules';
	const MAX_ITEMS            = 100;
	const FOLLOWUP_CRON_HOOK   = 'clms_messaging_followups_daily';
	const MIN_HOURS_BETWEEN_RULES = 48;

	public function __construct() {
		add_action( 'init', array( $this, 'handle_mark_message_read' ) );
		add_action( 'admin_post_clms_send_internal_message', array( $this, 'handle_send_internal_message' ) );
		add_action( 'wp', array( $this, 'maybe_schedule_followup_cron' ) );
		add_action( self::FOLLOWUP_CRON_HOOK, array( $this, 'run_scheduled_followups' ) );

		add_action( 'clms_user_enrolled', array( $this, 'handle_course_enrollment_message' ), 10, 2 );
		add_action( 'clms_user_enrolled_in_program', array( $this, 'handle_program_enrollment_message' ), 10, 3 );
		add_action( 'clms_lesson_completed', array( $this, 'handle_lesson_completed_message' ), 10, 2 );
		add_action( 'clms_submission_graded', array( $this, 'handle_submission_graded_message' ), 20, 5 );
		add_action( 'clms_commerce_course_access_notified', array( $this, 'handle_course_access_message' ), 10, 4 );
		add_action( 'transition_post_status', array( $this, 'handle_lesson_published_message' ), 20, 3 );
		add_action( 'clms_ai_inactivity_alert_generated', array( $this, 'handle_ai_inactivity_alert_message' ), 10, 3 );
		add_action( 'clms_ai_low_grade_alert_generated', array( $this, 'handle_ai_low_grade_alert_message' ), 10, 3 );
		add_action( 'clms_ai_teacher_digest_generated', array( $this, 'handle_ai_teacher_digest_message' ), 10, 4 );
	}

	public function send_message( $recipient_user_id, $data = array() ) {
		$recipient_user_id = absint( $recipient_user_id );
		$data              = is_array( $data ) ? $data : array();

		if ( ! $recipient_user_id || ! get_user_by( 'id', $recipient_user_id ) ) {
			return false;
		}

		$messages = get_user_meta( $recipient_user_id, self::META_KEY, true );
		$messages = is_array( $messages ) ? $messages : array();

		$item = wp_parse_args(
			$data,
			array(
				'id'                  => wp_generate_uuid4(),
				'message_type'        => 'general',
				'sender_type'         => 'system',
				'sender_id'           => 0,
				'sender_name'         => '',
				'title'               => 'Mensaje',
				'message'             => '',
				'link'                => '',
				'course_id'           => 0,
				'program_id'          => 0,
				'lesson_id'           => 0,
				'submission_id'       => 0,
				'recommendation_type' => '',
				'priority'            => 'normal',
				'thread_id'           => '',
				'thread_type'         => '',
				'thread_label'        => '',
				'reply_to'            => '',
				'automation_source'   => '',
				'is_read'             => 0,
				'created_at'          => current_time( 'mysql' ),
				'dedupe_key'          => '',
				'mirror_notification' => false,
			)
		);
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$item = (array) CLMS_Helper::modular_apply( 'messaging_raw_item', $item, $recipient_user_id, $messages );
		}

		$item['id']                  = sanitize_text_field( $item['id'] );
		$item['message_type']        = sanitize_key( $item['message_type'] );
		$item['sender_type']         = sanitize_key( $item['sender_type'] );
		$item['sender_id']           = absint( $item['sender_id'] );
		$item['sender_name']         = sanitize_text_field( $item['sender_name'] ? $item['sender_name'] : $this->resolve_sender_name( $item['sender_type'], $item['sender_id'] ) );
		$item['title']               = sanitize_text_field( $item['title'] );
		$item['message']             = sanitize_textarea_field( $item['message'] );
		$item['link']                = esc_url_raw( $item['link'] );
		$item['course_id']           = absint( $item['course_id'] );
		$item['program_id']          = absint( $item['program_id'] );
		$item['lesson_id']           = absint( $item['lesson_id'] );
		$item['submission_id']       = absint( $item['submission_id'] );
		$item['recommendation_type'] = sanitize_key( $item['recommendation_type'] );
		$item['priority']            = sanitize_key( $item['priority'] );
		$item['thread_id']           = sanitize_key( $item['thread_id'] );
		$item['thread_type']         = sanitize_key( $item['thread_type'] );
		$item['thread_label']        = sanitize_text_field( $item['thread_label'] );
		$item['reply_to']            = sanitize_text_field( $item['reply_to'] );
		$item['automation_source']   = sanitize_key( $item['automation_source'] );
		$item['is_read']             = ! empty( $item['is_read'] ) ? 1 : 0;
		$item['created_at']          = sanitize_text_field( $item['created_at'] );
		$item['dedupe_key']          = sanitize_key( $item['dedupe_key'] );
		$item['mirror_notification'] = ! empty( $item['mirror_notification'] );
		$item                       = $this->normalize_thread_data( $item );
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$item = (array) CLMS_Helper::modular_apply( 'messaging_item', $item, $recipient_user_id, $messages );
		}

		if ( $item['dedupe_key'] && $this->has_recent_message_with_key( $messages, $item['dedupe_key'] ) ) {
			return false;
		}

		array_unshift( $messages, $item );

		if ( count( $messages ) > self::MAX_ITEMS ) {
			$messages = array_slice( $messages, 0, self::MAX_ITEMS );
		}

		update_user_meta( $recipient_user_id, self::META_KEY, $messages );

		if ( $item['mirror_notification'] ) {
			$this->mirror_as_notification( $recipient_user_id, $item );
		}

		return $item;
	}

	public function get_messages( $user_id, $args = array() ) {
		$user_id = absint( $user_id );
		$args    = is_array( $args ) ? $args : array();

		if ( ! $user_id ) {
			return array();
		}

		$messages = get_user_meta( $user_id, self::META_KEY, true );
		$messages = is_array( $messages ) ? $messages : array();
		$limit    = isset( $args['limit'] ) ? max( 1, absint( $args['limit'] ) ) : 0;
		$messages = $this->sanitize_messages( $messages );

		if ( ! empty( $args['unread_only'] ) ) {
			$messages = array_values(
				array_filter(
					$messages,
					static function( $item ) {
						return empty( $item['is_read'] );
					}
				)
			);
		}

		if ( ! empty( $args['sender_type'] ) ) {
			$sender_type = sanitize_key( (string) $args['sender_type'] );
			$messages    = array_values(
				array_filter(
					$messages,
					static function( $item ) use ( $sender_type ) {
						return $sender_type === $item['sender_type'];
					}
				)
			);
		}

		if ( ! empty( $args['recommendation_type'] ) ) {
			$recommendation_type = sanitize_key( (string) $args['recommendation_type'] );
			$messages            = array_values(
				array_filter(
					$messages,
					static function( $item ) use ( $recommendation_type ) {
						return $recommendation_type === $item['recommendation_type'];
					}
				)
			);
		}

		if ( ! empty( $args['thread_id'] ) ) {
			$thread_id = sanitize_key( (string) $args['thread_id'] );
			$messages  = array_values(
				array_filter(
					$messages,
					static function( $item ) use ( $thread_id ) {
						return $thread_id === sanitize_key( (string) ( $item['thread_id'] ?? '' ) );
					}
				)
			);
		}

		if ( $limit > 0 ) {
			$messages = array_slice( $messages, 0, $limit );
		}

		return $messages;
	}

	public function get_threads( $user_id, $args = array() ) {
		$user_id  = absint( $user_id );
		$args     = is_array( $args ) ? $args : array();
		$messages = $this->get_messages( $user_id );
		$threads  = array();
		$limit    = isset( $args['limit'] ) ? max( 1, absint( $args['limit'] ) ) : 0;

		foreach ( $messages as $message ) {
			$thread_id = ! empty( $message['thread_id'] ) ? sanitize_key( (string) $message['thread_id'] ) : 'general';

			if ( ! isset( $threads[ $thread_id ] ) ) {
				$threads[ $thread_id ] = array(
					'thread_id'     => $thread_id,
					'thread_type'   => isset( $message['thread_type'] ) ? sanitize_key( (string) $message['thread_type'] ) : 'general',
					'thread_label'  => isset( $message['thread_label'] ) ? sanitize_text_field( (string) $message['thread_label'] ) : 'General',
					'message_count' => 0,
					'unread_count'  => 0,
					'last_message'  => $message,
				);
			}

			++$threads[ $thread_id ]['message_count'];

			if ( empty( $message['is_read'] ) ) {
				++$threads[ $thread_id ]['unread_count'];
			}
		}

		$threads = array_values( $threads );

		usort(
			$threads,
			static function( $a, $b ) {
				$a_date = strtotime( (string) ( $a['last_message']['created_at'] ?? '' ) );
				$b_date = strtotime( (string) ( $b['last_message']['created_at'] ?? '' ) );

				return $b_date <=> $a_date;
			}
		);

		if ( $limit > 0 ) {
			$threads = array_slice( $threads, 0, $limit );
		}

		return $threads;
	}

	public function get_unread_count( $user_id ) {
		return count( $this->get_messages( $user_id, array( 'unread_only' => true ) ) );
	}

	public function get_message_stats( $user_id ) {
		$user_id  = absint( $user_id );
		$messages = $this->get_messages( $user_id );
		$stats    = array(
			'total'            => count( $messages ),
			'unread'           => 0,
			'from_system'      => 0,
			'from_teacher'     => 0,
			'from_ai'          => 0,
			'recommendations'  => 0,
		);

		foreach ( $messages as $item ) {
			if ( empty( $item['is_read'] ) ) {
				++$stats['unread'];
			}

			if ( 'system' === $item['sender_type'] ) {
				++$stats['from_system'];
			} elseif ( 'teacher' === $item['sender_type'] ) {
				++$stats['from_teacher'];
			} elseif ( 'ai' === $item['sender_type'] ) {
				++$stats['from_ai'];
			}

			if ( ! empty( $item['recommendation_type'] ) ) {
				++$stats['recommendations'];
			}
		}

		return $stats;
	}

	public function mark_message_read( $user_id, $message_id ) {
		$user_id    = absint( $user_id );
		$message_id = sanitize_text_field( $message_id );

		if ( ! $user_id || ! $message_id ) {
			return false;
		}

		$messages = get_user_meta( $user_id, self::META_KEY, true );
		$messages = is_array( $messages ) ? $messages : array();
		$updated  = false;

		foreach ( $messages as $index => $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}

			if ( $message_id === (string) $item['id'] ) {
				$messages[ $index ]['is_read'] = 1;
				$updated                       = true;
				break;
			}
		}

		if ( $updated ) {
			update_user_meta( $user_id, self::META_KEY, $messages );
		}

		return $updated;
	}

	public function get_compose_context_for_user( $user_id ) {
		$user_id   = absint( $user_id );
		$course_ids = $this->get_allowed_course_ids_for_user( $user_id );
		$courses    = array();
		$students   = array();

		foreach ( $course_ids as $course_id ) {
			$courses[ $course_id ] = get_the_title( $course_id );

			foreach ( CLMS_Helper::get_enrolled_student_ids( $course_id ) as $student_id ) {
				$user = get_user_by( 'id', $student_id );

				if ( ! $user ) {
					continue;
				}

				if ( ! isset( $students[ $student_id ] ) ) {
					$students[ $student_id ] = array(
						'label'   => $user->display_name ? $user->display_name : $user->user_login,
						'courses' => array(),
					);
				}

				$students[ $student_id ]['courses'][] = $course_id;
			}
		}

		foreach ( $students as $student_id => $item ) {
			$course_titles = array();
			foreach ( $item['courses'] as $course_id ) {
				$course_titles[] = get_the_title( $course_id );
			}
			$students[ $student_id ]['meta'] = implode( ', ', array_filter( $course_titles ) );
		}

		asort( $courses );
		uasort(
			$students,
			static function( $a, $b ) {
				return strcasecmp( $a['label'], $b['label'] );
			}
		);

		return array(
			'courses'  => $courses,
			'students' => $students,
		);
	}

	public function maybe_schedule_followup_cron() {
		if ( ! wp_next_scheduled( self::FOLLOWUP_CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 09:15:00' ), 'daily', self::FOLLOWUP_CRON_HOOK );
		}
	}

	public function run_scheduled_followups() {
		$courses  = get_posts(
			array(
				'post_type'              => 'lm_course',
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$grading  = clms_core('CLMS_Grading');
		$settings = $this->get_followup_settings();

		foreach ( $courses as $course_id ) {
			$course_id   = absint( $course_id );
			$student_ids = CLMS_Helper::get_enrolled_student_ids( $course_id );

			if ( ! $course_id || empty( $student_ids ) ) {
				continue;
			}

			foreach ( $student_ids as $student_id ) {
				$student_id = absint( $student_id );

				if ( ! $student_id ) {
					continue;
				}

				$this->maybe_send_scheduled_reminder( $student_id, $course_id, $settings );

				if ( $grading && method_exists( $grading, 'get_course_grade_summary' ) ) {
					$summary       = (array) $grading->get_course_grade_summary( $student_id, $course_id );
					$average       = isset( $summary['final_average'] ) ? (float) $summary['final_average'] : -1;
					$has_low_grade = $average >= 0 && $average < $settings['low_grade_pct'];

					if ( $has_low_grade && $this->should_send_rule_message( $student_id, 'reinforcement', $course_id ) ) {
						$this->send_low_grade_followup_message( $student_id, $course_id, $average );
					}
				}

				if ( method_exists( 'CLMS_Helper', 'is_course_completed' ) && CLMS_Helper::is_course_completed( $student_id, $course_id ) && $this->should_send_rule_message( $student_id, 'upsell', $course_id ) ) {
					$this->send_scheduled_upsell_message( $student_id, $course_id );
				}
			}
		}
	}

	public function handle_mark_message_read() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( empty( $_GET['clms_mark_message'] ) || empty( $_GET['_wpnonce'] ) ) {
			return;
		}

		$message_id = sanitize_text_field( wp_unslash( $_GET['clms_mark_message'] ) );
		$nonce      = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'clms_mark_message_' . $message_id ) ) {
			return;
		}

		$this->mark_message_read( get_current_user_id(), $message_id );

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=clms-messages' );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_send_internal_message() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		check_admin_referer( 'clms_send_internal_message' );

		$current_user_id = get_current_user_id();
		$recipient_id    = isset( $_POST['recipient_user_id'] ) ? absint( wp_unslash( $_POST['recipient_user_id'] ) ) : 0;
		$course_id       = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
		$title           = isset( $_POST['message_title'] ) ? sanitize_text_field( wp_unslash( $_POST['message_title'] ) ) : '';
		$message         = isset( $_POST['message_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message_body'] ) ) : '';
		$recommendation  = isset( $_POST['recommendation_type'] ) ? sanitize_key( wp_unslash( $_POST['recommendation_type'] ) ) : '';
		$redirect        = admin_url( 'admin.php?page=clms-messages' );

		if ( ! $recipient_id || '' === $title || '' === $message ) {
			wp_safe_redirect( add_query_arg( 'message_error', 'missing_fields', $redirect ) );
			exit;
		}

		if ( ! $this->current_user_can_message_student( $current_user_id, $recipient_id, $course_id ) ) {
			wp_safe_redirect( add_query_arg( 'message_error', 'forbidden', $redirect ) );
			exit;
		}

		$sender_type = current_user_can( 'manage_options' ) ? 'system' : 'teacher';
		$link        = $course_id ? get_permalink( $course_id ) : admin_url( 'admin.php?page=clms-messages' );

		$result = $this->send_message(
			$recipient_id,
			array(
				'message_type'        => 'manual',
				'sender_type'         => $sender_type,
				'sender_id'           => $current_user_id,
				'title'               => $title,
				'message'             => $message,
				'link'                => $link,
				'course_id'           => $course_id,
				'recommendation_type' => in_array( $recommendation, array( 'progress', 'reinforcement', 'upsell', 'reminder' ), true ) ? $recommendation : '',
				'priority'            => 'high',
				'mirror_notification' => true,
			)
		);

		wp_safe_redirect( add_query_arg( is_array( $result ) ? 'message_sent' : 'message_error', is_array( $result ) ? '1' : 'send_failed', $redirect ) );
		exit;
	}

	public function handle_course_enrollment_message( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id ) {
			return;
		}

		$this->send_message(
			$user_id,
			array(
				'message_type'        => 'enrollment',
				'sender_type'         => 'system',
				'title'               => 'Tu curso ya está activo',
				'message'             => sprintf( 'Ya puedes comenzar "%s". Entra al curso y avanza con tu primera lección.', get_the_title( $course_id ) ),
				'link'                => get_permalink( $course_id ),
				'course_id'           => $course_id,
				'recommendation_type' => 'progress',
				'dedupe_key'          => 'course_enrollment_' . $user_id . '_' . $course_id,
				'mirror_notification' => true,
			)
		);
	}

	public function handle_program_enrollment_message( $user_id, $program_id, $result = array() ) {
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );
		$result     = is_array( $result ) ? $result : array();

		if ( ! $user_id || ! $program_id ) {
			return;
		}

		$new_courses = isset( $result['newly_enrolled_courses'] ) && is_array( $result['newly_enrolled_courses'] ) ? count( $result['newly_enrolled_courses'] ) : 0;

		$this->send_message(
			$user_id,
			array(
				'message_type'        => 'program_enrollment',
				'sender_type'         => 'system',
				'title'               => 'Tu programa ya está listo',
				'message'             => sprintf( 'Entraste al programa "%1$s" y ya tienes acceso a %2$d curso(s) vinculados.', get_the_title( $program_id ), $new_courses ),
				'link'                => get_permalink( $program_id ),
				'program_id'          => $program_id,
				'recommendation_type' => 'progress',
				'dedupe_key'          => 'program_enrollment_' . $user_id . '_' . $program_id,
				'mirror_notification' => true,
			)
		);
	}

	public function handle_lesson_completed_message( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );
		$course_id = CLMS_Helper::get_course_id_from_lesson( $lesson_id );

		if ( ! $user_id || ! $lesson_id || ! $course_id ) {
			return;
		}

		$lesson_ids        = CLMS_Helper::get_course_lessons( $course_id );
		$completed_lessons = array_map( 'absint', (array) get_user_meta( $user_id, '_clms_completed_lessons', true ) );
		$progress_percent  = ! empty( $lesson_ids ) ? (int) round( ( count( array_intersect( $lesson_ids, $completed_lessons ) ) / count( $lesson_ids ) ) * 100 ) : 0;
		$next_lesson_id    = $this->get_next_pending_lesson_id( $user_id, $course_id );
		$link              = $next_lesson_id ? get_permalink( $next_lesson_id ) : get_permalink( $course_id );
		$message           = sprintf( 'Completaste "%1$s". Tu avance en "%2$s" va por %3$d%%.', get_the_title( $lesson_id ), get_the_title( $course_id ), $progress_percent );

		if ( $next_lesson_id ) {
			$message .= ' Siguiente paso: "' . get_the_title( $next_lesson_id ) . '".';
		}

		$this->send_message(
			$user_id,
			array(
				'message_type'        => 'progress_update',
				'sender_type'         => 'system',
				'title'               => 'Buen avance en tu ruta',
				'message'             => $message,
				'link'                => $link,
				'course_id'           => $course_id,
				'lesson_id'           => $next_lesson_id ? $next_lesson_id : $lesson_id,
				'recommendation_type' => 'progress',
				'dedupe_key'          => 'lesson_completed_' . $user_id . '_' . $lesson_id,
				'mirror_notification' => true,
			)
		);
	}

	public function handle_submission_graded_message( $submission_id, $user_id, $status, $grade, $feedback ) {
		$submission_id = absint( $submission_id );
		$user_id       = absint( $user_id );
		$status        = sanitize_key( (string) $status );
		$feedback      = sanitize_textarea_field( (string) $feedback );

		if ( ! $submission_id ) {
			return;
		}

		if ( ! $user_id ) {
			$user_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		}

		$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		$course_id = CLMS_Helper::get_course_id_from_lesson( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return;
		}

		$assessment = clms_core('CLMS_Assessment_Engine');
		$record     = ( $assessment && method_exists( $assessment, 'get_submission_grade_record' ) ) ? $assessment->get_submission_grade_record( $submission_id ) : array();
		$source     = isset( $record['grade_source'] ) ? sanitize_key( (string) $record['grade_source'] ) : '';
		$grade      = '' !== (string) $grade ? max( 0, min( 100, absint( $grade ) ) ) : ( isset( $record['grade'] ) ? absint( $record['grade'] ) : '' );
		$sender     = $this->get_sender_context_for_submission( $source, $course_id, $lesson_id );
		$title      = 'Tu evaluación fue actualizada';
		$message    = sprintf( 'Tu actividad en "%1$s" fue revisada. Estado: %2$s.', get_the_title( $lesson_id ), $this->get_status_label( $status ) );
		$recommendation_type = '';

		if ( '' !== (string) $grade ) {
			$message .= ' Nota actual: ' . absint( $grade ) . '/100.';
		}

		if ( $feedback ) {
			$message .= ' Feedback: ' . $this->truncate_text( $feedback, 180 );
		}

		if ( '' !== (string) $grade && $grade < 70 ) {
			$title               = 'Recomendación de refuerzo';
			$recommendation_type = 'reinforcement';
			$message            .= ' Te recomiendo repasar la lección y volver a intentar con base en la retroalimentación.';
		} elseif ( '' !== (string) $grade && $grade >= 70 ) {
			$recommendation_type = 'progress';
			$message            .= ' Vas bien. Continúa con la siguiente actividad para mantener el ritmo.';
		}

		$this->send_message(
			$user_id,
			array(
				'message_type'        => 'assessment_update',
				'sender_type'         => $sender['type'],
				'sender_id'           => $sender['id'],
				'title'               => $title,
				'message'             => $message,
				'link'                => get_permalink( $lesson_id ),
				'course_id'           => $course_id,
				'lesson_id'           => $lesson_id,
				'submission_id'       => $submission_id,
				'recommendation_type' => $recommendation_type,
				'dedupe_key'          => 'graded_' . $submission_id . '_' . $status . '_' . ( '' !== (string) $grade ? absint( $grade ) : 'na' ),
				'mirror_notification' => false,
			)
		);
	}

	public function handle_course_access_message( $user_id, $course_id, $order_id = 0, $is_new_access = true ) {
		$user_id       = absint( $user_id );
		$course_id     = absint( $course_id );
		$order_id      = absint( $order_id );
		$is_new_access = (bool) $is_new_access;

		if ( ! $user_id || ! $course_id || ! method_exists( 'CLMS_Helper', 'get_commercial_related_items' ) ) {
			return;
		}

		$related = CLMS_Helper::get_commercial_related_items( $course_id, 1 );
		$link    = get_permalink( $course_id );
		$message = $is_new_access
			? sprintf( 'Tu acceso a "%s" ya está activo.', get_the_title( $course_id ) )
			: sprintf( 'Tu curso "%s" sigue disponible en tu cuenta.', get_the_title( $course_id ) );

		if ( ! empty( $related[0]['url'] ) && ! empty( $related[0]['title'] ) ) {
			$link    = esc_url_raw( $related[0]['url'] );
			$message .= ' También podría interesarte "' . sanitize_text_field( $related[0]['title'] ) . '".';
		}

		if ( $order_id ) {
			$message .= ' Pedido #' . $order_id . '.';
		}

		$this->send_message(
			$user_id,
			array(
				'message_type'        => 'commerce_followup',
				'sender_type'         => 'system',
				'title'               => 'Siguiente recomendación en tu academia',
				'message'             => $message,
				'link'                => $link,
				'course_id'           => $course_id,
				'recommendation_type' => 'upsell',
				'dedupe_key'          => 'course_access_' . $user_id . '_' . $course_id . '_' . ( $is_new_access ? 'new' : 'existing' ),
				'mirror_notification' => true,
			)
		);
	}

	public function handle_lesson_published_message( $new_status, $old_status, $post ) {
		if ( ! $post || 'lm_lesson' !== $post->post_type ) {
			return;
		}

		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		$lesson_id   = absint( $post->ID );
		$course_id   = CLMS_Helper::get_course_id_from_lesson( $lesson_id );
		$student_ids = CLMS_Helper::get_enrolled_student_ids( $course_id );

		if ( ! $course_id || empty( $student_ids ) ) {
			return;
		}

		foreach ( $student_ids as $student_id ) {
			$this->send_message(
				$student_id,
				array(
					'message_type'        => 'lesson_available',
					'sender_type'         => 'system',
					'title'               => 'Nueva lección disponible',
					'message'             => sprintf( 'Ya puedes revisar "%1$s" dentro del curso "%2$s".', get_the_title( $lesson_id ), get_the_title( $course_id ) ),
					'link'                => get_permalink( $lesson_id ),
					'course_id'           => $course_id,
					'lesson_id'           => $lesson_id,
					'recommendation_type' => 'reminder',
					'dedupe_key'          => 'lesson_publish_' . $student_id . '_' . $lesson_id,
					'mirror_notification' => false,
				)
			);
		}
	}

	public function handle_ai_inactivity_alert_message( $user_id, $course_id, $payload = array() ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$payload   = is_array( $payload ) ? $payload : array();

		if ( ! $user_id || ! $course_id ) {
			return;
		}

		$this->send_message(
			$user_id,
			array(
				'message_type'        => 'ai_followup',
				'sender_type'         => 'ai',
				'title'               => ! empty( $payload['subject'] ) ? sanitize_text_field( (string) $payload['subject'] ) : 'Sugerencia para retomar tu curso',
				'message'             => ! empty( $payload['body'] ) ? sanitize_textarea_field( (string) $payload['body'] ) : 'Te dejo una recomendación breve para retomar tu curso.',
				'link'                => ! empty( $payload['link'] ) ? esc_url_raw( (string) $payload['link'] ) : get_permalink( $course_id ),
				'course_id'           => $course_id,
				'lesson_id'           => ! empty( $payload['lesson_id'] ) ? absint( $payload['lesson_id'] ) : 0,
				'recommendation_type' => 'reminder',
				'priority'            => 'high',
				'automation_source'   => 'ai_alerts',
				'dedupe_key'          => 'ai_inactivity_' . $user_id . '_' . $course_id . '_' . gmdate( 'Ymd' ),
				'mirror_notification' => true,
			)
		);
	}

	public function handle_ai_low_grade_alert_message( $user_id, $course_id, $payload = array() ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$payload   = is_array( $payload ) ? $payload : array();

		if ( ! $user_id || ! $course_id ) {
			return;
		}

		$this->send_message(
			$user_id,
			array(
				'message_type'        => 'ai_followup',
				'sender_type'         => 'ai',
				'title'               => ! empty( $payload['subject'] ) ? sanitize_text_field( (string) $payload['subject'] ) : 'Plan de refuerzo recomendado',
				'message'             => ! empty( $payload['body'] ) ? sanitize_textarea_field( (string) $payload['body'] ) : 'Te dejo una recomendación de refuerzo académico.',
				'link'                => ! empty( $payload['link'] ) ? esc_url_raw( (string) $payload['link'] ) : get_permalink( $course_id ),
				'course_id'           => $course_id,
				'lesson_id'           => ! empty( $payload['lesson_id'] ) ? absint( $payload['lesson_id'] ) : 0,
				'recommendation_type' => 'reinforcement',
				'priority'            => 'high',
				'automation_source'   => 'ai_alerts',
				'dedupe_key'          => 'ai_low_grade_' . $user_id . '_' . $course_id . '_' . gmdate( 'Ymd' ),
				'mirror_notification' => true,
			)
		);
	}

	public function handle_ai_teacher_digest_message( $teacher_id, $course_id, $payload = array(), $context = array() ) {
		$teacher_id = absint( $teacher_id );
		$course_id  = absint( $course_id );
		$payload    = is_array( $payload ) ? $payload : array();

		if ( ! $teacher_id || ! $course_id ) {
			return;
		}

		$this->send_message(
			$teacher_id,
			array(
				'message_type'        => 'digest',
				'sender_type'         => 'system',
				'title'               => ! empty( $payload['subject'] ) ? sanitize_text_field( (string) $payload['subject'] ) : 'Digest de seguimiento',
				'message'             => ! empty( $payload['body'] ) ? sanitize_textarea_field( (string) $payload['body'] ) : 'Hay novedades en el seguimiento de tus estudiantes.',
				'link'                => admin_url( 'admin.php?page=clms-messages' ),
				'course_id'           => $course_id,
				'recommendation_type' => '',
				'priority'            => ! empty( $context['inactives'] ) || ! empty( $context['low_grades'] ) ? 'high' : 'normal',
				'automation_source'   => 'ai_alerts',
				'dedupe_key'          => 'teacher_digest_' . $teacher_id . '_' . $course_id . '_' . gmdate( 'Ymd' ),
				'mirror_notification' => false,
			)
		);
	}

	protected function sanitize_messages( $messages ) {
		$messages = is_array( $messages ) ? $messages : array();
		$clean    = array();

		foreach ( $messages as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}

			$clean[] = array(
				'id'                  => sanitize_text_field( (string) $item['id'] ),
				'message_type'        => sanitize_key( (string) ( $item['message_type'] ?? 'general' ) ),
				'sender_type'         => sanitize_key( (string) ( $item['sender_type'] ?? 'system' ) ),
				'sender_id'           => absint( $item['sender_id'] ?? 0 ),
				'sender_name'         => sanitize_text_field( (string) ( $item['sender_name'] ?? '' ) ),
				'title'               => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
				'message'             => sanitize_textarea_field( (string) ( $item['message'] ?? '' ) ),
				'link'                => esc_url_raw( (string) ( $item['link'] ?? '' ) ),
				'course_id'           => absint( $item['course_id'] ?? 0 ),
				'program_id'          => absint( $item['program_id'] ?? 0 ),
				'lesson_id'           => absint( $item['lesson_id'] ?? 0 ),
				'submission_id'       => absint( $item['submission_id'] ?? 0 ),
				'recommendation_type' => sanitize_key( (string) ( $item['recommendation_type'] ?? '' ) ),
				'priority'            => sanitize_key( (string) ( $item['priority'] ?? 'normal' ) ),
				'thread_id'           => sanitize_key( (string) ( $item['thread_id'] ?? '' ) ),
				'thread_type'         => sanitize_key( (string) ( $item['thread_type'] ?? '' ) ),
				'thread_label'        => sanitize_text_field( (string) ( $item['thread_label'] ?? '' ) ),
				'reply_to'            => sanitize_text_field( (string) ( $item['reply_to'] ?? '' ) ),
				'automation_source'   => sanitize_key( (string) ( $item['automation_source'] ?? '' ) ),
				'is_read'             => ! empty( $item['is_read'] ) ? 1 : 0,
				'created_at'          => sanitize_text_field( (string) ( $item['created_at'] ?? '' ) ),
				'dedupe_key'          => sanitize_key( (string) ( $item['dedupe_key'] ?? '' ) ),
			);
		}

		return $clean;
	}

	protected function mirror_as_notification( $recipient_user_id, $message ) {
		$notifications = clms_core('CLMS_Notifications');

		if ( ! $notifications || ! method_exists( $notifications, 'add_notification' ) ) {
			return;
		}

		$notifications->add_notification(
			$recipient_user_id,
			array(
				'type'          => 'message_' . sanitize_key( (string) $message['message_type'] ),
				'title'         => sanitize_text_field( (string) $message['title'] ),
				'message'       => sanitize_textarea_field( (string) $message['message'] ),
				'link'          => esc_url_raw( (string) $message['link'] ),
				'course_id'     => absint( $message['course_id'] ),
				'lesson_id'     => absint( $message['lesson_id'] ),
				'submission_id' => absint( $message['submission_id'] ),
			)
		);
	}

	protected function has_recent_message_with_key( $messages, $dedupe_key ) {
		$messages   = is_array( $messages ) ? $messages : array();
		$dedupe_key = sanitize_key( (string) $dedupe_key );

		if ( ! $dedupe_key ) {
			return false;
		}

		foreach ( $messages as $item ) {
			if ( empty( $item['dedupe_key'] ) || $dedupe_key !== sanitize_key( (string) $item['dedupe_key'] ) ) {
				continue;
			}

			return true;
		}

		return false;
	}

	protected function resolve_sender_name( $sender_type, $sender_id = 0 ) {
		$sender_type = sanitize_key( (string) $sender_type );
		$sender_id   = absint( $sender_id );

		if ( 'teacher' === $sender_type && $sender_id ) {
			$user = get_user_by( 'id', $sender_id );
			if ( $user ) {
				return $user->display_name ? $user->display_name : $user->user_login;
			}
		}

		if ( 'ai' === $sender_type ) {
			return 'ATORA IA';
		}

		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	protected function current_user_can_message_student( $sender_user_id, $student_id, $course_id = 0 ) {
		$sender_user_id = absint( $sender_user_id );
		$student_id     = absint( $student_id );
		$course_id      = absint( $course_id );

		if ( ! $sender_user_id || ! $student_id ) {
			return false;
		}

		if ( user_can( $sender_user_id, 'manage_options' ) ) {
			return true;
		}

		if ( ! user_can( $sender_user_id, 'clms_view_teacher_dashboard' ) && ! user_can( $sender_user_id, 'clms_grade_submissions' ) ) {
			return false;
		}

		if ( $course_id ) {
			return $this->user_owns_course( $sender_user_id, $course_id ) && in_array( $student_id, CLMS_Helper::get_enrolled_student_ids( $course_id ), true );
		}

		foreach ( $this->get_allowed_course_ids_for_user( $sender_user_id ) as $allowed_course_id ) {
			if ( in_array( $student_id, CLMS_Helper::get_enrolled_student_ids( $allowed_course_id ), true ) ) {
				return true;
			}
		}

		return false;
	}

	protected function get_allowed_course_ids_for_user( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		$args = array(
			'post_type'              => 'lm_course',
			'post_status'            => array( 'publish', 'private', 'draft' ),
			'fields'                 => 'ids',
			'posts_per_page'         => 200,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			$args['author'] = $user_id;
		}

		return array_map( 'absint', (array) get_posts( $args ) );
	}

	protected function user_owns_course( $user_id, $course_id ) {
		$user_id  = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		return (int) get_post_field( 'post_author', $course_id ) === $user_id;
	}

	protected function get_next_pending_lesson_id( $user_id, $course_id ) {
		$user_id          = absint( $user_id );
		$course_id        = absint( $course_id );
		$completed_lessons = array_map( 'absint', (array) get_user_meta( $user_id, '_clms_completed_lessons', true ) );

		foreach ( CLMS_Helper::get_course_lessons( $course_id ) as $lesson_id ) {
			$lesson_id = absint( $lesson_id );

			if ( ! $lesson_id || in_array( $lesson_id, $completed_lessons, true ) ) {
				continue;
			}

			if ( method_exists( 'CLMS_Helper', 'user_can_access_lesson' ) && ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
				continue;
			}

			return $lesson_id;
		}

		return 0;
	}

	protected function get_sender_context_for_submission( $source, $course_id, $lesson_id ) {
		$source    = sanitize_key( (string) $source );
		$course_id = absint( $course_id );
		$lesson_id = absint( $lesson_id );

		if ( in_array( $source, array( 'ai_assisted', 'ai_auto_grade', 'hybrid' ), true ) ) {
			return array(
				'type' => 'ai',
				'id'   => 0,
			);
		}

		if ( 'manual' === $source ) {
			$teacher_id = $course_id ? absint( get_post_field( 'post_author', $course_id ) ) : 0;
			if ( ! $teacher_id && $lesson_id ) {
				$teacher_id = absint( get_post_field( 'post_author', $lesson_id ) );
			}

			return array(
				'type' => 'teacher',
				'id'   => $teacher_id,
			);
		}

		return array(
			'type' => 'system',
			'id'   => 0,
		);
	}

	protected function normalize_thread_data( $item ) {
		$item = is_array( $item ) ? $item : array();

		if ( ! empty( $item['thread_id'] ) && ! empty( $item['thread_type'] ) && ! empty( $item['thread_label'] ) ) {
			return $item;
		}

		if ( ! empty( $item['program_id'] ) ) {
			$item['thread_id']    = 'program_' . absint( $item['program_id'] );
			$item['thread_type']  = 'program';
			$item['thread_label'] = get_the_title( absint( $item['program_id'] ) );
			return $item;
		}

		if ( ! empty( $item['course_id'] ) ) {
			$item['thread_id']    = 'course_' . absint( $item['course_id'] );
			$item['thread_type']  = 'course';
			$item['thread_label'] = get_the_title( absint( $item['course_id'] ) );
			return $item;
		}

		$item['thread_id']    = 'general';
		$item['thread_type']  = 'general';
		$item['thread_label'] = 'General';

		return $item;
	}

	protected function get_followup_settings() {
		$defaults = array(
			'inactivity_days' => 7,
			'low_grade_pct'   => 60,
		);
		$settings = (array) get_option( 'clms_ai_alerts_settings', array() );

		return array(
			'inactivity_days' => max( 1, min( 30, absint( $settings['inactivity_days'] ?? $defaults['inactivity_days'] ) ) ),
			'low_grade_pct'   => max( 0, min( 100, absint( $settings['low_grade_pct'] ?? $defaults['low_grade_pct'] ) ) ),
		);
	}

	protected function maybe_send_scheduled_reminder( $user_id, $course_id, $settings ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$settings  = is_array( $settings ) ? $settings : array();

		if ( ! $user_id || ! $course_id ) {
			return;
		}

		$last_activity = $this->get_last_activity_timestamp( $user_id, $course_id );
		$days_inactive = $last_activity ? (int) floor( ( time() - $last_activity ) / DAY_IN_SECONDS ) : PHP_INT_MAX;

		if ( $days_inactive < absint( $settings['inactivity_days'] ?? 7 ) || ! $this->should_send_rule_message( $user_id, 'reminder', $course_id ) ) {
			return;
		}

		$next_lesson_id = $this->get_next_pending_lesson_id( $user_id, $course_id );

		if ( ! $next_lesson_id ) {
			return;
		}

		$student = get_user_by( 'id', $user_id );
		$name    = $student ? ( $student->display_name ? $student->display_name : $student->user_login ) : 'estudiante';
		$message = $this->generate_ai_followup_copy(
			array(
				'rule'          => 'reminder',
				'student_name'  => $name,
				'course_title'  => get_the_title( $course_id ),
				'lesson_title'  => get_the_title( $next_lesson_id ),
				'days_inactive' => $days_inactive,
			)
		);

		if ( ! $message ) {
			$message = sprintf(
				'%1$s, llevas %2$d días sin avanzar en "%3$s". Tu siguiente paso recomendado es "%4$s". Retómalo hoy con un bloque corto de estudio.',
				$name,
				$days_inactive,
				get_the_title( $course_id ),
				get_the_title( $next_lesson_id )
			);
		}

		$this->send_message(
			$user_id,
			array(
				'message_type'        => 'scheduled_followup',
				'sender_type'         => 'ai',
				'title'               => 'Recordatorio para retomar tu curso',
				'message'             => $message,
				'link'                => get_permalink( $next_lesson_id ),
				'course_id'           => $course_id,
				'lesson_id'           => $next_lesson_id,
				'recommendation_type' => 'reminder',
				'priority'            => 'high',
				'automation_source'   => 'scheduler',
				'dedupe_key'          => 'scheduled_reminder_' . $user_id . '_' . $course_id . '_' . gmdate( 'Ymd' ),
				'mirror_notification' => true,
			)
		);

		$this->mark_rule_message_sent( $user_id, 'reminder', $course_id );
	}

	protected function send_low_grade_followup_message( $user_id, $course_id, $average ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$average   = (float) $average;
		$student   = get_user_by( 'id', $user_id );
		$name      = $student ? ( $student->display_name ? $student->display_name : $student->user_login ) : 'estudiante';
		$next_lesson_id = $this->get_next_pending_lesson_id( $user_id, $course_id );
		$message   = $this->generate_ai_followup_copy(
			array(
				'rule'         => 'reinforcement',
				'student_name' => $name,
				'course_title' => get_the_title( $course_id ),
				'lesson_title' => $next_lesson_id ? get_the_title( $next_lesson_id ) : '',
				'average'      => $average,
			)
		);

		if ( ! $message ) {
			$message = sprintf(
				'%1$s, tu promedio actual en "%2$s" está en %3$s/100. Te recomiendo repasar el material y retomar la siguiente actividad con foco en los puntos de mejora.',
				$name,
				get_the_title( $course_id ),
				round( $average, 1 )
			);
		}

		$this->send_message(
			$user_id,
			array(
				'message_type'        => 'scheduled_followup',
				'sender_type'         => 'ai',
				'title'               => 'Refuerzo recomendado para mejorar tu promedio',
				'message'             => $message,
				'link'                => $next_lesson_id ? get_permalink( $next_lesson_id ) : get_permalink( $course_id ),
				'course_id'           => $course_id,
				'lesson_id'           => $next_lesson_id,
				'recommendation_type' => 'reinforcement',
				'priority'            => 'high',
				'automation_source'   => 'scheduler',
				'dedupe_key'          => 'scheduled_reinforcement_' . $user_id . '_' . $course_id . '_' . gmdate( 'Ymd' ),
				'mirror_notification' => true,
			)
		);

		$this->mark_rule_message_sent( $user_id, 'reinforcement', $course_id );
	}

	protected function send_scheduled_upsell_message( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$related   = method_exists( 'CLMS_Helper', 'get_commercial_related_items' ) ? CLMS_Helper::get_commercial_related_items( $course_id, 1 ) : array();

		if ( empty( $related ) ) {
			return;
		}

		$student = get_user_by( 'id', $user_id );
		$name    = $student ? ( $student->display_name ? $student->display_name : $student->user_login ) : 'estudiante';
		$item    = $related[0];
		$message = $this->generate_ai_followup_copy(
			array(
				'rule'          => 'upsell',
				'student_name'  => $name,
				'course_title'  => get_the_title( $course_id ),
				'related_title' => isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '',
			)
		);

		if ( ! $message ) {
			$message = sprintf(
				'%1$s, como ya cerraste "%2$s", puede interesarte continuar con "%3$s" para profundizar tu ruta de aprendizaje.',
				$name,
				get_the_title( $course_id ),
				sanitize_text_field( (string) ( $item['title'] ?? '' ) )
			);
		}

		$this->send_message(
			$user_id,
			array(
				'message_type'        => 'scheduled_followup',
				'sender_type'         => 'ai',
				'title'               => 'Siguiente recomendación para tu ruta',
				'message'             => $message,
				'link'                => ! empty( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : get_permalink( $course_id ),
				'course_id'           => $course_id,
				'recommendation_type' => 'upsell',
				'priority'            => 'normal',
				'automation_source'   => 'scheduler',
				'dedupe_key'          => 'scheduled_upsell_' . $user_id . '_' . $course_id . '_' . gmdate( 'Ym' ),
				'mirror_notification' => false,
			)
		);

		$this->mark_rule_message_sent( $user_id, 'upsell', $course_id );
	}

	protected function generate_ai_followup_copy( $args = array() ) {
		$args       = is_array( $args ) ? $args : array();
		$manager    = clms_core('CLMS_AI_Manager');
		$student    = sanitize_text_field( (string) ( $args['student_name'] ?? 'estudiante' ) );
		$course     = sanitize_text_field( (string) ( $args['course_title'] ?? '' ) );
		$lesson     = sanitize_text_field( (string) ( $args['lesson_title'] ?? '' ) );
		$rule       = sanitize_key( (string) ( $args['rule'] ?? 'reminder' ) );
		$extra      = '';

		if ( 'reminder' === $rule ) {
			$extra = 'Lleva ' . absint( $args['days_inactive'] ?? 0 ) . ' días sin actividad. Próxima lección: "' . $lesson . '".';
		} elseif ( 'reinforcement' === $rule ) {
			$extra = 'Su promedio actual es ' . round( (float) ( $args['average'] ?? 0 ), 1 ) . '/100. Próxima lección: "' . $lesson . '".';
		} elseif ( 'upsell' === $rule ) {
			$extra = 'Recomienda continuar con "' . sanitize_text_field( (string) ( $args['related_title'] ?? '' ) ) . '".';
		}

		if ( ! $manager || ! method_exists( $manager, 'chat' ) || ! method_exists( $manager, 'is_configured' ) || ! $manager->is_configured() ) {
			return '';
		}

		$result = $manager->chat(
			array(
				array(
					'role'    => 'user',
					'content' => sprintf(
						'Redacta un mensaje interno breve, empático y accionable para %1$s sobre su curso "%2$s". Contexto: %3$s',
						$student,
						$course,
						$extra
					),
				),
			),
			array(
				'max_tokens'  => 140,
				'temperature' => 0.4,
				'system'      => 'Eres un coach académico de ATORA. Escribe en español, con tono cercano, útil y sin vender agresivamente.',
				'timeout'     => 20,
			)
		);

		return is_wp_error( $result ) ? '' : sanitize_textarea_field( trim( (string) $result ) );
	}

	protected function get_last_activity_timestamp( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );

		if ( empty( $lesson_ids ) ) {
			return 0;
		}

		$submissions = get_posts(
			array(
				'post_type'              => 'clms_submission',
				'post_status'            => array( 'publish', 'private' ),
				'author'                 => $user_id,
				'posts_per_page'         => 1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'meta_query'             => array(
					array(
						'key'     => '_clms_submission_lesson_id',
						'value'   => array_map( 'absint', $lesson_ids ),
						'compare' => 'IN',
					),
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( ! empty( $submissions ) ) {
			return (int) strtotime( (string) get_post_field( 'post_date_gmt', absint( $submissions[0] ) ) );
		}

		$dates = get_user_meta( $user_id, '_clms_enrollment_dates', true );
		$dates = is_array( $dates ) ? $dates : array();

		if ( ! empty( $dates[ $course_id ] ) ) {
			return (int) strtotime( sanitize_text_field( (string) $dates[ $course_id ] ) );
		}

		$last_login = (int) get_user_meta( $user_id, 'last_login', true );

		return $last_login > 0 ? $last_login : 0;
	}

	protected function should_send_rule_message( $user_id, $rule, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$rule      = sanitize_key( (string) $rule );
		$last_sent = $this->get_last_rule_message_timestamp( $user_id, $rule, $course_id );

		if ( ! $last_sent ) {
			return true;
		}

		return ( time() - $last_sent ) >= ( self::MIN_HOURS_BETWEEN_RULES * HOUR_IN_SECONDS );
	}

	protected function get_last_rule_message_timestamp( $user_id, $rule, $course_id ) {
		$user_id    = absint( $user_id );
		$course_id  = absint( $course_id );
		$rule       = sanitize_key( (string) $rule );
		$rule_store = get_user_meta( $user_id, self::META_LAST_RULES, true );
		$rule_store = is_array( $rule_store ) ? $rule_store : array();
		$key        = $rule . '_' . $course_id;

		return ! empty( $rule_store[ $key ] ) ? absint( $rule_store[ $key ] ) : 0;
	}

	protected function mark_rule_message_sent( $user_id, $rule, $course_id ) {
		$user_id    = absint( $user_id );
		$course_id  = absint( $course_id );
		$rule       = sanitize_key( (string) $rule );
		$rule_store = get_user_meta( $user_id, self::META_LAST_RULES, true );
		$rule_store = is_array( $rule_store ) ? $rule_store : array();
		$key        = $rule . '_' . $course_id;

		$rule_store[ $key ] = time();
		update_user_meta( $user_id, self::META_LAST_RULES, $rule_store );
	}

	protected function get_status_label( $status ) {
		switch ( (string) $status ) {
			case 'graded':
				return 'Calificada';
			case 'in_review':
				return 'En revisión';
			case 'submitted':
				return 'Enviada';
			default:
				return 'Actualizada';
		}
	}

	protected function truncate_text( $text, $length = 180 ) {
		$text   = trim( wp_strip_all_tags( (string) $text ) );
		$length = max( 40, absint( $length ) );

		if ( strlen( $text ) <= $length ) {
			return $text;
		}

		return rtrim( substr( $text, 0, $length - 3 ) ) . '...';
	}
}
