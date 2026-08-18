<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Teacher_Dashboard_Data_Trait {
	protected function get_dashboard_ui_schema( $user_id = 0 ) {
		if ( null !== self::$schema_cache ) {
			return self::$schema_cache;
		}

		$default_sections = array(
			'hero'          => true,
			'smart_panel'   => true,
			'quick_access'  => true,
			'pulse'         => true,
			'inbox'         => true,
			'reviewed'      => true,
			'recent_lessons'=> true,
			'courses'       => true,
			'priority'      => true,
			'incidents'     => true,
			'summary'       => true,
			'notifications' => true,
		);

		$schema = array(
			'version'  => 1,
			'sections' => $default_sections,
			'limits'   => array(
				'inbox_items'     => 24,
				'pending_items'   => 8,
				'reviewed_items'  => 6,
				'recent_lessons'  => 6,
				'notifications'   => 4,
				'course_cards'    => 6,
				'priority_queue_items' => 8,
			),
		);

		$schema_path = defined( 'ATORA_LMS_DIR' ) ? trailingslashit( ATORA_LMS_DIR ) . self::DASHBOARD_SCHEMA_FILE : '';
		if ( $schema_path && file_exists( $schema_path ) && is_readable( $schema_path ) ) {
			$raw = file_get_contents( $schema_path );
			$decoded = $raw ? json_decode( $raw, true ) : null;
			if ( is_array( $decoded ) ) {
				if ( isset( $decoded['version'] ) ) {
					$schema['version'] = absint( $decoded['version'] );
				}
				if ( isset( $decoded['sections'] ) ) {
					$schema['sections'] = $decoded['sections'];
				}
				if ( isset( $decoded['limits'] ) && is_array( $decoded['limits'] ) ) {
					$schema['limits'] = array_merge( $schema['limits'], $decoded['limits'] );
				}
			}
		}

		$schema['sections'] = $this->normalize_dashboard_sections( $schema['sections'], $default_sections );
		$schema['limits']   = $this->normalize_dashboard_limits( $schema['limits'] );

		$schema = apply_filters( 'clms_teacher_dashboard_ui_schema', $schema, $user_id );
		self::$schema_cache = $schema;

		return $schema;
	}

	protected function normalize_dashboard_limits( $limits ) {
		$limits = is_array( $limits ) ? $limits : array();
		$normalized = array();

		foreach ( $limits as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$normalized[ $key ] = max( 0, absint( $value ) );
		}

		return $normalized;
	}

	protected function normalize_dashboard_sections( $sections, $defaults ) {
		$defaults = is_array( $defaults ) ? $defaults : array();
		$normalized = $defaults;
		$sections = is_array( $sections ) ? $sections : array();

		if ( empty( $sections ) ) {
			return $normalized;
		}

		$is_assoc = array_keys( $sections ) !== range( 0, count( $sections ) - 1 );

		if ( $is_assoc ) {
			foreach ( $sections as $key => $value ) {
				$id = sanitize_key( (string) $key );
				if ( '' === $id || ! array_key_exists( $id, $normalized ) ) {
					continue;
				}
				$normalized[ $id ] = (bool) $value;
			}

			return $normalized;
		}

		foreach ( $sections as $section ) {
			if ( is_string( $section ) ) {
				$id = sanitize_key( $section );
				if ( '' === $id || ! array_key_exists( $id, $normalized ) ) {
					continue;
				}
				$normalized[ $id ] = true;
				continue;
			}

			if ( ! is_array( $section ) ) {
				continue;
			}

			$id = isset( $section['id'] ) ? sanitize_key( (string) $section['id'] ) : '';
			if ( '' === $id || ! array_key_exists( $id, $normalized ) ) {
				continue;
			}

			$normalized[ $id ] = ! array_key_exists( 'enabled', $section ) || (bool) $section['enabled'];
		}

		return $normalized;
	}

	protected function is_section_enabled( $schema, $section_id ) {
		$section_id = sanitize_key( (string) $section_id );
		if ( '' === $section_id ) {
			return true;
		}
		if ( ! is_array( $schema ) || empty( $schema['sections'] ) || ! is_array( $schema['sections'] ) ) {
			return true;
		}
		if ( ! array_key_exists( $section_id, $schema['sections'] ) ) {
			return true;
		}
		return (bool) $schema['sections'][ $section_id ];
	}

	protected function get_dashboard_limit( $schema, $key, $default ) {
		$default = max( 0, absint( $default ) );
		if ( ! is_array( $schema ) || empty( $schema['limits'] ) || ! is_array( $schema['limits'] ) ) {
			return $default;
		}
		$key = sanitize_key( (string) $key );
		if ( '' === $key || ! array_key_exists( $key, $schema['limits'] ) ) {
			return $default;
		}
		return max( 0, absint( $schema['limits'][ $key ] ) );
	}

	/* ---------------------------------------------------------------
	   DATA
	--------------------------------------------------------------- */

	protected function user_can_view_teacher_dashboard( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		if ( CLMS_Access::can_view_teacher_dashboard() ) {
			return true;
		}

		return ! empty( $this->get_teacher_courses( $user_id ) );
	}

	protected function get_teacher_courses( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		if ( CLMS_Access::can_manage_courses() ) {
			$all_courses = get_posts(
				array(
					'post_type'      => 'lm_course',
					'post_status'    => array( 'publish', 'private', 'draft' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			return array_map( 'absint', $all_courses );
		}

		$courses = get_posts(
			array(
				'post_type'      => 'lm_course',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'author'         => $user_id,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		return array_map( 'absint', $courses );
	}

	protected function get_teacher_lessons( $user_id, $course_ids ) {
		$user_id    = absint( $user_id );
		$course_ids = is_array( $course_ids ) ? array_map( 'absint', $course_ids ) : array();

		if ( empty( $course_ids ) ) {
			return array();
		}

		$lesson_ids = array();

		foreach ( $course_ids as $course_id ) {
			$course_lessons = CLMS_Helper::get_course_lessons( $course_id );

			foreach ( $course_lessons as $lesson_id ) {
				$lesson_ids[] = absint( $lesson_id );
			}
		}

		$lesson_ids = array_values( array_unique( array_filter( $lesson_ids ) ) );

		if ( empty( $lesson_ids ) ) {
			return array();
		}

		if ( current_user_can( 'edit_others_lm_lessons' ) ) {
			return $lesson_ids;
		}

		$filtered = array();

		foreach ( $lesson_ids as $lesson_id ) {
			$author_id = (int) get_post_field( 'post_author', $lesson_id );

			if ( $author_id === $user_id || CLMS_Helper::user_can_manage_lms( $lesson_id ) ) {
				$filtered[] = $lesson_id;
			}
		}

		return array_values( array_unique( $filtered ) );
	}

	protected function get_pending_submissions( $user_id, $course_ids, $lesson_ids, $limit = 8 ) {
		unset( $user_id );

		$course_ids = is_array( $course_ids ) ? array_map( 'absint', $course_ids ) : array();
		$lesson_ids = is_array( $lesson_ids ) ? array_map( 'absint', $lesson_ids ) : array();
		$limit      = max( 1, absint( $limit ) );

		if ( empty( $course_ids ) || empty( $lesson_ids ) || ! class_exists( 'CLMS_Submission' ) ) {
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
						'key'     => '_clms_submission_status',
						'value'   => array( 'submitted', 'in_review' ),
						'compare' => 'IN',
					),
					array(
						'key'     => '_clms_submission_lesson_id',
						'value'   => $lesson_ids,
						'compare' => 'IN',
					),
				),
			)
		);

		$items = array();
		$grading = clms_core('CLMS_Grading');

		foreach ( $ids as $submission_id ) {
			$submission_id = absint( $submission_id );
			$student_id    = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
			$lesson_id     = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$course_id     = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
			$status        = (string) get_post_meta( $submission_id, '_clms_submission_status', true );
			$student       = $student_id ? get_user_by( 'id', $student_id ) : false;

			if ( ! in_array( $lesson_id, $lesson_ids, true ) ) {
				continue;
			}

			$items[] = array(
				'submission_id'       => $submission_id,
				'student_id'          => $student_id,
				'student_name'        => $student ? ( $student->display_name ? $student->display_name : $student->user_login ) : __( 'N/D', 'atora-lms' ),
				'student_email'       => $student ? $student->user_email : '',
				'lesson_id'           => $lesson_id,
				'lesson_title'        => get_the_title( $lesson_id ),
				'lesson_url'          => get_permalink( $lesson_id ),
				'course_id'           => $course_id,
				'course_title'        => $course_id ? get_the_title( $course_id ) : '',
				'status'              => $status,
				'status_label'        => $this->get_submission_status_label( $status ),
				'submitted_at'        => $this->format_datetime( get_post_field( 'post_date', $submission_id ) ),
				'submitted_timestamp' => strtotime( get_post_field( 'post_date_gmt', $submission_id ) ),
				'speedgrade_url'      => $this->resolve_speedgrade_url( $grading, $submission_id ),
			);
		}

		return $items;
	}

	protected function get_reviewed_submissions( $user_id, $course_ids, $lesson_ids, $limit = 6 ) {
		unset( $user_id, $course_ids );

		$lesson_ids = is_array( $lesson_ids ) ? array_map( 'absint', $lesson_ids ) : array();
		$limit      = max( 1, absint( $limit ) );

		if ( empty( $lesson_ids ) || ! class_exists( 'CLMS_Submission' ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => CLMS_Submission::CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_status',
						'value' => 'graded',
					),
					array(
						'key'     => '_clms_submission_lesson_id',
						'value'   => $lesson_ids,
						'compare' => 'IN',
					),
				),
			)
		);

		$items = array();
		$grading = clms_core('CLMS_Grading');

		foreach ( $ids as $submission_id ) {
			$submission_id = absint( $submission_id );
			$student_id    = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
			$lesson_id     = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$status        = (string) get_post_meta( $submission_id, '_clms_submission_status', true );
			$grade         = get_post_meta( $submission_id, '_clms_submission_grade', true );
			$feedback      = (string) get_post_meta( $submission_id, '_clms_submission_feedback', true );
			$student       = $student_id ? get_user_by( 'id', $student_id ) : false;

			$items[] = array(
				'submission_id'       => $submission_id,
				'student_name'        => $student ? ( $student->display_name ? $student->display_name : $student->user_login ) : __( 'N/D', 'atora-lms' ),
				'lesson_title'        => $lesson_id ? get_the_title( $lesson_id ) : __( 'N/D', 'atora-lms' ),
				'status_label'        => $this->get_submission_status_label( $status ),
				'grade'               => '' !== (string) $grade ? absint( $grade ) : '',
				'feedback'            => $feedback,
				'updated_at'          => $this->format_datetime( get_post_field( 'post_modified', $submission_id ) ),
				'speedgrade_url'      => $this->resolve_speedgrade_url( $grading, $submission_id ),
			);
		}

		return $items;
	}

	protected function get_recent_lessons_data( $lesson_ids, $limit = 6 ) {
		$lesson_ids = is_array( $lesson_ids ) ? array_map( 'absint', $lesson_ids ) : array();
		$limit      = max( 1, absint( $limit ) );

		if ( empty( $lesson_ids ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => 'lm_lesson',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'post__in'       => $lesson_ids,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();

		foreach ( $ids as $lesson_id ) {
			$lesson_id = absint( $lesson_id );
			$course_id = CLMS_Helper::get_course_id_from_lesson( $lesson_id );
			$due_date  = CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_date', '_clms_due_date' ), '' );

			$items[] = array(
				'lesson_id'    => $lesson_id,
				'lesson_title' => get_the_title( $lesson_id ),
				'lesson_url'   => get_permalink( $lesson_id ),
				'course_id'    => $course_id,
				'course_title' => $course_id ? get_the_title( $course_id ) : '',
				'due_date'     => $due_date ? $this->format_date( $due_date ) : '',
			);
		}

		return $items;
	}

	protected function get_course_cards( $course_ids ) {
		$course_ids = is_array( $course_ids ) ? array_map( 'absint', $course_ids ) : array();

		if ( empty( $course_ids ) ) {
			return array();
		}

		$items = array();

		foreach ( $course_ids as $course_id ) {
			$lesson_ids       = CLMS_Helper::get_course_lessons( $course_id );
			$first_lesson_id  = ! empty( $lesson_ids ) ? absint( $lesson_ids[0] ) : 0;
			$student_count    = $this->get_course_student_count( $course_id );
			$lesson_count     = count( $lesson_ids );
			$meta_parts       = array();

			$meta_parts[] = sprintf(
				_n( '%d lección', '%d lecciones', $lesson_count, 'atora-lms' ),
				$lesson_count
			);
			if ( $student_count > 0 ) {
				$meta_parts[] = sprintf(
					_n( '%d estudiante', '%d estudiantes', $student_count, 'atora-lms' ),
					$student_count
				);
			}

			$items[] = array(
				'course_id'        => $course_id,
				'title'            => get_the_title( $course_id ),
				'course_url'       => get_permalink( $course_id ),
				'first_lesson_url' => $first_lesson_id ? get_permalink( $first_lesson_id ) : '',
				'meta'             => implode( ' · ', $meta_parts ),
			);
		}

		return $items;
	}

	protected function get_metrics( $course_ids, $lesson_ids, $pending_submissions, $reviewed_items ) {
		$course_ids          = is_array( $course_ids ) ? $course_ids : array();
		$lesson_ids          = is_array( $lesson_ids ) ? $lesson_ids : array();
		$pending_submissions = is_array( $pending_submissions ) ? $pending_submissions : array();
		$reviewed_items      = is_array( $reviewed_items ) ? $reviewed_items : array();

		return array(
			'courses'         => count( $course_ids ),
			'lessons'         => count( $lesson_ids ),
			'pending_reviews' => count( $pending_submissions ),
			'reviewed'        => count( $reviewed_items ),
		);
	}

	protected function get_group_overview( $course_ids, $lesson_ids ) {
		$course_ids = is_array( $course_ids ) ? array_map( 'absint', $course_ids ) : array();
		$lesson_ids = is_array( $lesson_ids ) ? array_map( 'absint', $lesson_ids ) : array();

		if ( empty( $course_ids ) ) {
			return array(
				'enrolled'        => 0,
				'active'          => 0,
				'pending'         => 0,
				'graded'          => 0,
				'avg_grade'       => 0,
				'completion_rate' => 0,
				'at_risk'         => 0,
				'low_grade'       => 0,
			);
		}

		$enrolled_ids = array();
		foreach ( $course_ids as $course_id ) {
			$users = get_post_meta( $course_id, '_clms_enrolled_users', true );
			if ( is_array( $users ) ) {
				foreach ( $users as $uid ) {
					$enrolled_ids[] = absint( $uid );
				}
			}
		}
		$enrolled_ids = array_values( array_unique( array_filter( $enrolled_ids ) ) );

		$pending          = 0;
		$graded           = 0;
		$grades           = array();
		$low_grade        = 0;
		$active_ids       = array();
		$low_grade_limit  = (int) apply_filters( 'clms_teacher_dashboard_low_grade_threshold', 70, $course_ids, $lesson_ids );
		$submission_type  = class_exists( 'CLMS_Submission' ) ? CLMS_Submission::CPT : 'clms_submission';

		if ( ! empty( $lesson_ids ) ) {
			$submission_ids = get_posts(
				array(
					'post_type'      => $submission_type,
					'post_status'    => array( 'publish', 'private' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'     => '_clms_submission_lesson_id',
							'value'   => $lesson_ids,
							'compare' => 'IN',
						),
					),
				)
			);

			foreach ( $submission_ids as $submission_id ) {
				$submission_id = absint( $submission_id );
				$status        = (string) get_post_meta( $submission_id, '_clms_submission_status', true );
				$grade         = get_post_meta( $submission_id, '_clms_submission_grade', true );
				$student_id    = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );

				if ( $student_id ) {
					$active_ids[ $student_id ] = true;
				}

				if ( in_array( $status, array( 'submitted', 'in_review' ), true ) ) {
					$pending++;
				}

				if ( 'graded' === $status && '' !== (string) $grade ) {
					$graded++;
					$grade_value = absint( $grade );
					$grades[] = $grade_value;
					if ( $low_grade_limit > 0 && $grade_value < $low_grade_limit ) {
						$low_grade++;
					}
				}
			}
		}

		$at_risk = 0;
		if ( ! empty( $enrolled_ids ) ) {
			foreach ( $enrolled_ids as $uid ) {
				if ( ! isset( $active_ids[ $uid ] ) ) {
					$at_risk++;
				}
			}
		}

		$avg_grade = ! empty( $grades ) ? (int) round( array_sum( $grades ) / count( $grades ) ) : 0;
		$completion_total = 0;
		$completion_count = 0;

		if ( ! empty( $enrolled_ids ) && ! empty( $lesson_ids ) ) {
			foreach ( $enrolled_ids as $uid ) {
				$completed = get_user_meta( $uid, '_clms_completed_lessons', true );
				$completed = is_array( $completed ) ? array_map( 'absint', $completed ) : array();
				$done      = count( array_intersect( $lesson_ids, $completed ) );
				$completion_total += $done;
				$completion_count++;
			}
		}

		$completion_rate = ( $completion_count > 0 && count( $lesson_ids ) > 0 )
			? (int) round( ( $completion_total / ( $completion_count * count( $lesson_ids ) ) ) * 100 )
			: 0;

		return array(
			'enrolled'        => count( $enrolled_ids ),
			'active'          => count( $active_ids ),
			'pending'         => $pending,
			'graded'          => $graded,
			'avg_grade'       => $avg_grade,
			'completion_rate' => $completion_rate,
			'at_risk'         => $at_risk,
			'low_grade'       => $low_grade,
		);
	}

	protected function get_competency_group_overview( $pending_submissions, $reviewed_items ) {
		$rows = array();
		foreach ( array_merge( is_array( $pending_submissions ) ? $pending_submissions : array(), is_array( $reviewed_items ) ? $reviewed_items : array() ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['submission_id'] ) ) {
				continue;
			}
			$rows[] = absint( $item['submission_id'] );
		}

		$rows = array_values( array_unique( array_filter( $rows ) ) );
		if ( empty( $rows ) ) {
			return array();
		}

		$competency_scores = array();
		foreach ( $rows as $submission_id ) {
			$scores = get_post_meta( $submission_id, '_clms_submission_rubric_scores', true );
			if ( ! is_array( $scores ) ) {
				continue;
			}
			foreach ( $scores as $score_row ) {
				if ( ! is_array( $score_row ) ) {
					continue;
				}
				$competency = isset( $score_row['competency'] ) ? sanitize_text_field( (string) $score_row['competency'] ) : '';
				if ( '' === $competency ) {
					continue;
				}
				$score = isset( $score_row['score'] ) ? floatval( $score_row['score'] ) : 0;
				$max   = isset( $score_row['max'] ) ? floatval( $score_row['max'] ) : ( isset( $score_row['max_points'] ) ? floatval( $score_row['max_points'] ) : 0 );
				if ( $max <= 0 ) {
					continue;
				}
				if ( ! isset( $competency_scores[ $competency ] ) ) {
					$competency_scores[ $competency ] = array();
				}
				$competency_scores[ $competency ][] = $score / $max;
			}
		}

		if ( empty( $competency_scores ) ) {
			return array();
		}

		$averages = array();
		foreach ( $competency_scores as $competency => $ratios ) {
			if ( empty( $ratios ) ) {
				continue;
			}
			$averages[ $competency ] = array_sum( $ratios ) / count( $ratios );
		}
		if ( empty( $averages ) ) {
			return array();
		}

		arsort( $averages );
		$strongest = (string) key( $averages );
		asort( $averages );
		$weakest = (string) key( $averages );

		return array(
			'strongest' => $strongest,
			'weakest'   => $weakest,
		);
	}

	protected function get_speedgrade_focus_item( $pending_submissions ) {
		$pending_submissions = is_array( $pending_submissions ) ? $pending_submissions : array();

		if ( empty( $pending_submissions ) ) {
			return array();
		}

		return $pending_submissions[0];
	}

	protected function get_smart_panel_summary( $queue_items, $group_overview, $notifications ) {
		$queue_items   = is_array( $queue_items ) ? $queue_items : array();
		$group_overview = is_array( $group_overview ) ? $group_overview : array();
		$notifications = is_array( $notifications ) ? $notifications : array();

		$urgent     = 0;
		$ai_pending = 0;
		$certificate_impact = 0;

		foreach ( $queue_items as $item ) {
			if ( ! empty( $item['is_urgent'] ) ) {
				$urgent++;
			}
			if ( ! empty( $item['ai_pending'] ) ) {
				$ai_pending++;
			}
			$flags = isset( $item['flags'] ) && is_array( $item['flags'] ) ? $item['flags'] : array();
			foreach ( $flags as $flag ) {
				$flag = sanitize_text_field( (string) $flag );
				if ( false !== stripos( $flag, 'certific' ) ) {
					$certificate_impact++;
					break;
				}
			}
		}

		return array(
			'pending'    => absint( $group_overview['pending'] ?? count( $queue_items ) ),
			'urgent'     => absint( $urgent ),
			'at_risk'    => absint( $group_overview['at_risk'] ?? 0 ),
			'ai_pending' => absint( $ai_pending ),
			'certificate_impact' => absint( $certificate_impact ),
			'alerts'     => absint( count( $notifications ) ),
		);
	}

	protected function get_prioritized_evaluation_queue( $course_ids, $lesson_ids, $limit = 8 ) {
		$course_ids = is_array( $course_ids ) ? array_values( array_filter( array_map( 'absint', $course_ids ) ) ) : array();
		$lesson_ids = is_array( $lesson_ids ) ? array_values( array_filter( array_map( 'absint', $lesson_ids ) ) ) : array();
		$limit      = max( 1, absint( $limit ) );

		if ( empty( $lesson_ids ) || ! class_exists( 'CLMS_Submission' ) ) {
			return array();
		}

		$query_limit = min( 80, max( $limit * 8, 24 ) );
		$submission_ids = get_posts(
			array(
				'post_type'      => CLMS_Submission::CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => $query_limit,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'     => '_clms_submission_lesson_id',
						'value'   => $lesson_ids,
						'compare' => 'IN',
					),
					array(
						'key'     => '_clms_submission_status',
						'value'   => array( 'submitted', 'in_review', 'graded' ),
						'compare' => 'IN',
					),
				),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);

		$grading = clms_core('CLMS_Grading');
		$items   = array();
		$now     = current_time( 'timestamp' );

		foreach ( (array) $submission_ids as $submission_id ) {
			$submission_id = absint( $submission_id );
			if ( ! $submission_id ) {
				continue;
			}

			$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
			$lesson_id  = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$course_id  = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
			$status     = (string) get_post_meta( $submission_id, '_clms_submission_status', true );
			$student    = $student_id ? get_user_by( 'id', $student_id ) : false;

			if ( ! $lesson_id || ! in_array( $lesson_id, $lesson_ids, true ) ) {
				continue;
			}

			if ( ! $course_id && $lesson_id ) {
				$course_id = CLMS_Helper::get_course_id_from_lesson( $lesson_id );
			}

			if ( ! empty( $course_ids ) && $course_id && ! in_array( $course_id, $course_ids, true ) ) {
				continue;
			}

			$submitted_timestamp = strtotime( (string) get_post_field( 'post_date_gmt', $submission_id ) );
			$submitted_timestamp = $submitted_timestamp ? absint( $submitted_timestamp ) : 0;
			$updated_timestamp   = strtotime( (string) get_post_field( 'post_modified_gmt', $submission_id ) );
			$updated_timestamp   = $updated_timestamp ? absint( $updated_timestamp ) : 0;
			$due_timestamp       = $this->get_submission_due_timestamp( $lesson_id );
			$is_overdue          = $due_timestamp > 0 && $now > $due_timestamp && 'graded' !== $status;
			$is_new              = $submitted_timestamp > 0 && ( $now - $submitted_timestamp ) <= DAY_IN_SECONDS && 'submitted' === $status;
			$is_recently_reviewed = 'graded' === $status && $updated_timestamp > 0 && ( $now - $updated_timestamp ) <= ( 3 * DAY_IN_SECONDS );
			$is_at_risk          = $this->is_student_at_risk_for_course( $student_id, $course_id );
			$ai_pending          = $this->is_submission_ai_pending_validation( $submission_id, $status );
			$is_returned         = in_array( $status, array( 'needs_revision', 'returned' ), true );
			$affects_certificate = $this->submission_affects_certificate_status( $submission_id, $student_id, $course_id );
			$evidence_context    = $this->get_submission_evidence_context( $lesson_id, $course_id );
			$is_required_evidence = ! empty( $evidence_context['is_required_for_certificate'] ) && 'graded' !== $status;

			$priority_rank  = 7;
			$priority_label = __( 'Seguimiento', 'atora-lms' );
			$priority_class = 'is-info';
			$priority_reason = __( 'Revisión de seguimiento docente.', 'atora-lms' );

			if ( $is_overdue ) {
				$priority_rank  = 0;
				$priority_label = __( 'Vencida', 'atora-lms' );
				$priority_class = 'is-urgent';
				$priority_reason = __( 'La fecha límite fue superada y requiere revisión inmediata.', 'atora-lms' );
			} elseif ( $is_required_evidence ) {
				$priority_rank  = 1;
				$priority_label = __( 'Evidencia obligatoria', 'atora-lms' );
				$priority_class = 'is-warning';
				$priority_reason = __( 'Esta evidencia es requisito de certificación y sigue pendiente.', 'atora-lms' );
			} elseif ( $is_at_risk ) {
				$priority_rank  = 2;
				$priority_label = __( 'Estudiante en riesgo', 'atora-lms' );
				$priority_class = 'is-warning';
				$priority_reason = __( 'El estudiante muestra señales de riesgo académico.', 'atora-lms' );
			} elseif ( $ai_pending ) {
				$priority_rank  = 3;
				$priority_label = __( 'IA por validar', 'atora-lms' );
				$priority_class = 'is-info';
				$priority_reason = __( 'Existe sugerencia IA pendiente de validación humana.', 'atora-lms' );
			} elseif ( $is_returned ) {
				$priority_rank  = 4;
				$priority_label = __( 'Devuelta para mejora', 'atora-lms' );
				$priority_class = 'is-warning';
				$priority_reason = __( 'La entrega fue devuelta y necesita nuevo ciclo de revisión.', 'atora-lms' );
			} elseif ( $affects_certificate ) {
				$priority_rank  = 5;
				$priority_label = __( 'Impacta certificado', 'atora-lms' );
				$priority_class = 'is-info';
				$priority_reason = __( 'Esta revisión puede modificar la elegibilidad de certificado.', 'atora-lms' );
			} elseif ( $is_new ) {
				$priority_rank  = 6;
				$priority_label = __( 'Nueva', 'atora-lms' );
				$priority_class = 'is-warning';
				$priority_reason = __( 'Entrega nueva pendiente de revisión inicial.', 'atora-lms' );
			} elseif ( $is_recently_reviewed ) {
				$priority_rank  = 7;
				$priority_label = __( 'Revisada recientemente', 'atora-lms' );
				$priority_class = 'is-success';
				$priority_reason = __( 'Entrega con revisión reciente para cierre de seguimiento.', 'atora-lms' );
			}

			if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
				$priority_meta = (array) CLMS_Helper::modular_apply(
					'teacher_priority_meta',
					array(
						'priority_rank'  => $priority_rank,
						'priority_label' => $priority_label,
						'priority_class' => $priority_class,
						'priority_reason'=> $priority_reason,
					),
					$submission_id,
					$student_id,
					$course_id
				);
				$priority_rank  = isset( $priority_meta['priority_rank'] ) ? absint( $priority_meta['priority_rank'] ) : $priority_rank;
				$priority_label = isset( $priority_meta['priority_label'] ) ? sanitize_text_field( (string) $priority_meta['priority_label'] ) : $priority_label;
				$priority_class = isset( $priority_meta['priority_class'] ) ? sanitize_html_class( (string) $priority_meta['priority_class'] ) : $priority_class;
				$priority_reason = isset( $priority_meta['priority_reason'] ) ? sanitize_text_field( (string) $priority_meta['priority_reason'] ) : $priority_reason;
			}

			$flags = array();
			if ( $is_overdue ) {
				$flags[] = __( 'Entrega vencida', 'atora-lms' );
			}
			if ( $is_required_evidence ) {
				$flags[] = __( 'Evidencia obligatoria pendiente', 'atora-lms' );
			}
			if ( $is_at_risk ) {
				$flags[] = __( 'Alumno con riesgo académico', 'atora-lms' );
			}
			if ( $ai_pending ) {
				$flags[] = __( 'Revisión IA pendiente de validación', 'atora-lms' );
			}
			if ( $affects_certificate ) {
				$flags[] = __( 'Puede afectar certificación', 'atora-lms' );
			}
			if ( ! empty( $evidence_context['competency_title'] ) ) {
				$flags[] = sprintf(
					/* translators: %s: competencia */
					__( 'Competencia foco: %s', 'atora-lms' ),
					$evidence_context['competency_title']
				);
			}

			$items[] = array(
				'submission_id'       => $submission_id,
				'student_id'          => $student_id,
				'student_name'        => $student ? ( $student->display_name ? $student->display_name : $student->user_login ) : __( 'N/D', 'atora-lms' ),
				'lesson_id'           => $lesson_id,
				'lesson_title'        => get_the_title( $lesson_id ),
				'course_id'           => $course_id,
				'course_title'        => $course_id ? get_the_title( $course_id ) : '',
				'status'              => $status,
				'status_label'        => $this->get_submission_status_label( $status ),
				'submitted_at'        => $this->format_datetime( get_post_field( 'post_date', $submission_id ) ),
				'submitted_timestamp' => $submitted_timestamp,
				'speedgrade_url'      => $this->resolve_speedgrade_url( $grading, $submission_id ),
				'priority_rank'       => $priority_rank,
				'priority_label'      => $priority_label,
				'priority_class'      => $priority_class,
				'priority_reason'     => $priority_reason,
				'is_urgent'           => $is_overdue,
				'ai_pending'          => $ai_pending,
				'affects_certificate' => $affects_certificate,
				'evidence_type'       => isset( $evidence_context['evidence_type'] ) ? $evidence_context['evidence_type'] : 'practice',
				'is_required_evidence'=> $is_required_evidence,
				'competency_title'    => isset( $evidence_context['competency_title'] ) ? $evidence_context['competency_title'] : '',
				'flags'               => $flags,
			);
		}

		usort(
			$items,
			static function( $a, $b ) {
				$a_rank = isset( $a['priority_rank'] ) ? absint( $a['priority_rank'] ) : 5;
				$b_rank = isset( $b['priority_rank'] ) ? absint( $b['priority_rank'] ) : 5;

				if ( $a_rank === $b_rank ) {
					$a_time = isset( $a['submitted_timestamp'] ) ? absint( $a['submitted_timestamp'] ) : 0;
					$b_time = isset( $b['submitted_timestamp'] ) ? absint( $b['submitted_timestamp'] ) : 0;
					return $b_time <=> $a_time;
				}

				return $a_rank <=> $b_rank;
			}
		);

		$result = array_slice( $items, 0, $limit );
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$result = (array) CLMS_Helper::modular_apply( 'teacher_prioritized_queue', $result, $course_ids, $lesson_ids, $limit );
		}

		return $result;
	}

	protected function get_submission_due_timestamp( $lesson_id ) {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id ) {
			return 0;
		}

		$due_date = CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_date', '_clms_due_date' ), '' );
		$due_time = CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_time', '_clms_due_time' ), '' );
		$due_raw  = trim( (string) $due_date . ' ' . (string) $due_time );

		if ( '' === trim( (string) $due_date ) ) {
			return 0;
		}

		$timestamp = strtotime( $due_raw );
		return $timestamp ? absint( $timestamp ) : 0;
	}

	/**
	 * Determina si la revisión podría impactar estado de certificación.
	 *
	 * @param int $submission_id Entrega.
	 * @param int $student_id    Estudiante.
	 * @param int $course_id     Curso.
	 * @return bool
	 */
	protected function submission_affects_certificate_status( $submission_id, $student_id, $course_id ) {
		$submission_id = absint( $submission_id );
		$student_id    = absint( $student_id );
		$course_id     = absint( $course_id );

		if ( ! $submission_id || ! $student_id || ! $course_id || ! class_exists( 'CLMS_Helper' ) ) {
			return false;
		}

		$certificates = clms_core('CLMS_Certificates');
		if ( ! $certificates || ! method_exists( $certificates, 'get_certificate_status_for_student_course' ) ) {
			return false;
		}

		$status = (array) $certificates->get_certificate_status_for_student_course( $student_id, $course_id );
		$key    = isset( $status['status'] ) ? sanitize_key( (string) $status['status'] ) : 'pending';

		return in_array( $key, array( 'eligible', 'pending' ), true );
	}

	/**
	 * Contexto de evidencia/competencia para priorización docente.
	 *
	 * @param int $lesson_id Lección/actividad.
	 * @param int $course_id Curso.
	 * @return array<string,mixed>
	 */
	protected function get_submission_evidence_context( $lesson_id, $course_id ) {
		$lesson_id = absint( $lesson_id );
		$course_id = absint( $course_id );

		$context = array(
			'evidence_type'               => 'practice',
			'is_required_for_certificate' => false,
			'competency_ids'              => array(),
			'competency_title'            => '',
		);

		if ( ! $lesson_id || ! class_exists( 'CLMS_Helper' ) ) {
			return $context;
		}

		$evidence_service = clms_core('CLMS_Evidence_Service');
		if ( ! $evidence_service || ! method_exists( $evidence_service, 'get_activity_evidence_config' ) ) {
			return $context;
		}

		$config  = (array) $evidence_service->get_activity_evidence_config( $lesson_id );
		$comp_ids = isset( $config['competency_ids'] ) && is_array( $config['competency_ids'] )
			? array_values( array_filter( array_map( 'sanitize_key', $config['competency_ids'] ) ) )
			: array();

		$context = array(
			'evidence_type'               => sanitize_key( (string) ( $config['evidence_type'] ?? 'practice' ) ),
			'is_required_for_certificate' => ! empty( $config['is_required_for_certificate'] ),
			'competency_ids'              => $comp_ids,
			'competency_title'            => '',
		);

		if ( empty( $comp_ids ) ) {
			return $context;
		}

		$competency_service = clms_core('CLMS_Competency_Service');
		if ( ! $competency_service || ! method_exists( $competency_service, 'get_course_competencies' ) ) {
			return $context;
		}

		$course_competencies = (array) $competency_service->get_course_competencies( $course_id );
		foreach ( $course_competencies as $competency ) {
			$competency = is_array( $competency ) ? $competency : array();
			$comp_id = sanitize_key( (string) ( $competency['id'] ?? '' ) );
			if ( '' === $comp_id || ! in_array( $comp_id, $comp_ids, true ) ) {
				continue;
			}
			$context['competency_title'] = sanitize_text_field( (string) ( $competency['title'] ?? '' ) );
			break;
		}

		return $context;
	}

	protected function is_submission_ai_pending_validation( $submission_id, $status = '' ) {
		$submission_id = absint( $submission_id );
		$status        = sanitize_key( (string) $status );

		if ( ! $submission_id ) {
			return false;
		}

		$assessment = clms_core('CLMS_Assessment_Engine');
		$record     = ( $assessment && method_exists( $assessment, 'get_submission_grade_record' ) )
			? $assessment->get_submission_grade_record( $submission_id )
			: array();
		$last_audit = isset( $record['assessment_audit'] ) && is_array( $record['assessment_audit'] ) ? $record['assessment_audit'] : array();
		$source     = isset( $record['grade_source'] ) ? sanitize_key( (string) $record['grade_source'] ) : '';

		if ( ! empty( $last_audit['review_required'] ) ) {
			return true;
		}

		if ( 'in_review' === $status && in_array( $source, array( 'ai_assisted', 'ai_auto_grade', 'hybrid' ), true ) ) {
			return true;
		}

		$ai_review_status = sanitize_key( (string) get_post_meta( $submission_id, '_clms_ai_review_status', true ) );
		if ( 'completed' === $ai_review_status && 'graded' !== $status ) {
			return true;
		}

		return false;
	}

	protected function is_student_at_risk_for_course( $student_id, $course_id ) {
		$student_id = absint( $student_id );
		$course_id  = absint( $course_id );

		if ( ! $student_id || ! $course_id ) {
			return false;
		}

		$lessons = CLMS_Helper::get_course_lessons( $course_id );
		$lessons = is_array( $lessons ) ? array_values( array_filter( array_map( 'absint', $lessons ) ) ) : array();

		if ( empty( $lessons ) ) {
			return false;
		}

		$completed = get_user_meta( $student_id, '_clms_completed_lessons', true );
		$completed = is_array( $completed ) ? array_values( array_filter( array_map( 'absint', $completed ) ) ) : array();
		$done      = count( array_intersect( $lessons, $completed ) );
		$progress  = (int) round( ( $done / max( 1, count( $lessons ) ) ) * 100 );

		$recent_submission = get_posts(
			array(
				'post_type'      => class_exists( 'CLMS_Submission' ) ? CLMS_Submission::CPT : 'clms_submission',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $student_id,
					),
					array(
						'key'     => '_clms_submission_lesson_id',
						'value'   => $lessons,
						'compare' => 'IN',
					),
				),
				'no_found_rows'  => true,
			)
		);

		$is_inactive = true;
		if ( ! empty( $recent_submission[0] ) ) {
			$updated_timestamp = strtotime( (string) get_post_field( 'post_modified_gmt', absint( $recent_submission[0] ) ) );
			if ( $updated_timestamp ) {
				$is_inactive = ( current_time( 'timestamp' ) - absint( $updated_timestamp ) ) > ( 10 * DAY_IN_SECONDS );
			}
		}

		return ( $progress < 35 ) || $is_inactive;
	}

	protected function get_course_student_count( $course_id ) {
		$course_id = absint( $course_id );

		if ( ! $course_id ) {
			return 0;
		}

		$students = get_post_meta( $course_id, '_clms_enrolled_users', true );

		if ( is_array( $students ) ) {
			return count( array_filter( array_map( 'absint', $students ) ) );
		}

		return 0;
	}

	protected function get_selected_course_id( $course_ids ) {
		$course_ids = is_array( $course_ids ) ? array_values( array_map( 'absint', $course_ids ) ) : array();

		if ( empty( $course_ids ) ) {
			return 0;
		}

		$selected = isset( $_GET['clms_td_course'] ) ? absint( wp_unslash( $_GET['clms_td_course'] ) ) : 0;

		if ( $selected && in_array( $selected, $course_ids, true ) ) {
			return $selected;
		}

		return absint( $course_ids[0] );
	}

	protected function get_student_table_data( $course_id ) {
		$course_id = absint( $course_id );

		if ( ! $course_id ) {
			return array();
		}

		$student_ids = array();
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_enrolled_student_ids' ) ) {
			$student_ids = CLMS_Helper::get_enrolled_student_ids( $course_id );
		}

		if ( empty( $student_ids ) ) {
			$student_ids = get_post_meta( $course_id, '_clms_enrolled_users', true );
			$student_ids = is_array( $student_ids ) ? array_values( array_filter( array_map( 'absint', $student_ids ) ) ) : array();
		}

		if ( empty( $student_ids ) ) {
			return array();
		}

		$lesson_ids = array();
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_lessons' ) ) {
			$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );
			$lesson_ids = is_array( $lesson_ids ) ? array_values( array_filter( array_map( 'absint', $lesson_ids ) ) ) : array();
		}

		$submission_type = class_exists( 'CLMS_Submission' ) ? CLMS_Submission::CPT : 'clms_submission';
		$grades_map      = array();
		$activity_map    = array();

		if ( ! empty( $lesson_ids ) ) {
			$submission_ids = get_posts(
				array(
					'post_type'      => $submission_type,
					'post_status'    => array( 'publish', 'private' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_query'     => array(
						'relation' => 'AND',
						array(
							'key'     => '_clms_submission_lesson_id',
							'value'   => $lesson_ids,
							'compare' => 'IN',
						),
						array(
							'key'     => '_clms_submission_user_id',
							'value'   => $student_ids,
							'compare' => 'IN',
						),
					),
				)
			);

			foreach ( $submission_ids as $submission_id ) {
				$submission_id = absint( $submission_id );
				if ( ! $submission_id ) {
					continue;
				}

				$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
				if ( ! $student_id ) {
					continue;
				}

				$updated_timestamp = get_post_modified_time( 'U', false, $submission_id );
				if ( $updated_timestamp ) {
					$updated_timestamp = absint( $updated_timestamp );
					if ( empty( $activity_map[ $student_id ] ) || $updated_timestamp > $activity_map[ $student_id ] ) {
						$activity_map[ $student_id ] = $updated_timestamp;
					}
				}

				$status = (string) get_post_meta( $submission_id, '_clms_submission_status', true );
				$grade  = get_post_meta( $submission_id, '_clms_submission_grade', true );
				if ( 'graded' === $status && '' !== (string) $grade ) {
					if ( ! isset( $grades_map[ $student_id ] ) ) {
						$grades_map[ $student_id ] = array();
					}
					$grades_map[ $student_id ][] = absint( $grade );
				}
			}
		}

		$now  = current_time( 'timestamp' );
		$rows = array();

		foreach ( $student_ids as $student_id ) {
			$student_id = absint( $student_id );
			if ( ! $student_id ) {
				continue;
			}

			$user = get_userdata( $student_id );
			if ( ! $user ) {
				continue;
			}

			$completed = get_user_meta( $student_id, '_clms_completed_lessons', true );
			$completed = is_array( $completed ) ? array_values( array_map( 'absint', $completed ) ) : array();
			$progress  = 0;

			if ( ! empty( $lesson_ids ) ) {
				$done     = count( array_intersect( $lesson_ids, $completed ) );
				$progress = (int) round( ( $done / count( $lesson_ids ) ) * 100 );
			}

			$last_activity = __( 'Sin actividad', 'atora-lms' );
			$last_seen     = isset( $activity_map[ $student_id ] ) ? absint( $activity_map[ $student_id ] ) : 0;
			if ( $last_seen > 0 ) {
				$last_activity = sprintf(
					/* translators: %s: relative elapsed time. */
					__( 'Hace %s', 'atora-lms' ),
					human_time_diff( $last_seen, $now )
				);
			}

			$avg_grade = __( 'Sin nota', 'atora-lms' );
			if ( ! empty( $grades_map[ $student_id ] ) ) {
				$avg_grade = (int) round( array_sum( $grades_map[ $student_id ] ) / count( $grades_map[ $student_id ] ) ) . '%';
			}

			$rows[] = array(
				'id'               => $student_id,
				'name'             => $user->display_name ? $user->display_name : $user->user_login,
				'email'            => $user->user_email,
				'progress'         => $progress,
				'last_activity'    => $last_activity,
				'average_grade'    => $avg_grade,
				'flagged_followup' => (bool) get_user_meta( $student_id, '_clms_flagged_for_followup_' . $course_id, true ),
			);
		}

		usort(
			$rows,
			static function( $a, $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return $rows;
	}

}
