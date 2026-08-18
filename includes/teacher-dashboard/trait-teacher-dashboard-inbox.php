<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Teacher_Dashboard_Inbox_Trait {
	protected function get_inbox_filters() {
		$filters = array(
			'student_id' => isset( $_GET['clms_td_student'] ) ? absint( wp_unslash( $_GET['clms_td_student'] ) ) : 0,
			'lesson_id'  => isset( $_GET['clms_td_lesson'] ) ? absint( wp_unslash( $_GET['clms_td_lesson'] ) ) : 0,
			'scope'      => isset( $_GET['clms_td_scope'] ) ? sanitize_text_field( wp_unslash( $_GET['clms_td_scope'] ) ) : '',
			'status'     => isset( $_GET['clms_td_status'] ) ? sanitize_key( wp_unslash( $_GET['clms_td_status'] ) ) : '',
			'date_from'  => isset( $_GET['clms_td_date_from'] ) ? $this->sanitize_date_input( wp_unslash( $_GET['clms_td_date_from'] ) ) : '',
			'date_to'    => isset( $_GET['clms_td_date_to'] ) ? $this->sanitize_date_input( wp_unslash( $_GET['clms_td_date_to'] ) ) : '',
		);

		if ( ! in_array( $filters['status'], array( '', 'all', 'submitted', 'in_review', 'graded' ), true ) ) {
			$filters['status'] = '';
		}

		return $filters;
	}

	protected function get_inbox_page() {
		$page = isset( $_GET['clms_td_page'] ) ? absint( wp_unslash( $_GET['clms_td_page'] ) ) : 1;
		return max( 1, $page );
	}

	protected function has_inbox_filters( $filters ) {
		$filters = is_array( $filters ) ? $filters : array();
		foreach ( array( 'student_id', 'lesson_id', 'scope', 'status', 'date_from', 'date_to' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	protected function get_submission_inbox_data( $lesson_ids, $filters, $page = 1, $limit = 24 ) {
		$lesson_ids = is_array( $lesson_ids ) ? array_map( 'absint', $lesson_ids ) : array();
		$filters    = is_array( $filters ) ? $filters : array();
		$limit      = max( 1, absint( $limit ) );
		$page       = max( 1, absint( $page ) );

		if ( empty( $lesson_ids ) || ! class_exists( 'CLMS_Submission' ) ) {
			return array(
				'items' => array(),
				'total' => 0,
				'page'  => $page,
				'pages' => 0,
			);
		}

		$allowed_lessons = $lesson_ids;
		$scope           = $this->parse_inbox_scope_filter( isset( $filters['scope'] ) ? $filters['scope'] : '' );

		if ( 'course' === $scope['type'] && $scope['id'] ) {
			$course_lessons = CLMS_Helper::get_course_lessons( $scope['id'] );
			$course_lessons = is_array( $course_lessons ) ? array_map( 'absint', $course_lessons ) : array();
			$allowed_lessons = array_values( array_intersect( $allowed_lessons, $course_lessons ) );
		}

		if ( 'program' === $scope['type'] && $scope['id'] ) {
			$program_lessons = array();
			foreach ( CLMS_Helper::get_program_courses( $scope['id'] ) as $course_id ) {
				foreach ( (array) CLMS_Helper::get_course_lessons( $course_id ) as $lesson_id ) {
					$program_lessons[] = absint( $lesson_id );
				}
			}
			$program_lessons = array_values( array_unique( array_filter( $program_lessons ) ) );
			$allowed_lessons = array_values( array_intersect( $allowed_lessons, $program_lessons ) );
		}

		if ( ! empty( $filters['lesson_id'] ) ) {
			$allowed_lessons = array_values( array_intersect( $allowed_lessons, array( absint( $filters['lesson_id'] ) ) ) );
		}

		if ( empty( $allowed_lessons ) ) {
			return array(
				'items' => array(),
				'total' => 0,
				'page'  => $page,
				'pages' => 0,
			);
		}

		$meta_query = array(
			array(
				'key'     => '_clms_submission_lesson_id',
				'value'   => $allowed_lessons,
				'compare' => 'IN',
			),
		);

		if ( ! empty( $filters['student_id'] ) ) {
			$meta_query[] = array(
				'key'   => '_clms_submission_user_id',
				'value' => absint( $filters['student_id'] ),
			);
		}

		if ( isset( $filters['status'] ) && '' !== $filters['status'] ) {
			if ( 'all' !== $filters['status'] ) {
				$meta_query[] = array(
					'key'   => '_clms_submission_status',
					'value' => $filters['status'],
				);
			}
		} else {
			$meta_query[] = array(
				'key'     => '_clms_submission_status',
				'value'   => array( 'submitted', 'in_review' ),
				'compare' => 'IN',
			);
		}

		$args = array(
			'post_type'      => CLMS_Submission::CPT,
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => $meta_query,
			'paged'          => $page,
			'no_found_rows'  => false,
			'ignore_sticky_posts' => true,
		);

		$date_query = array();
		if ( ! empty( $filters['date_from'] ) ) {
			$date_query[] = array(
				'after'     => $filters['date_from'],
				'inclusive' => true,
			);
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$date_query[] = array(
				'before'    => $filters['date_to'],
				'inclusive' => true,
			);
		}
		if ( ! empty( $date_query ) ) {
			$args['date_query'] = $date_query;
		}

		$query   = new WP_Query( $args );
		$ids     = (array) $query->posts;
		$total   = absint( $query->found_posts );
		$pages   = absint( $query->max_num_pages );
		$grading = clms_core('CLMS_Grading');
		$items = array();

		foreach ( (array) $ids as $submission_id ) {
			$submission_id = absint( $submission_id );
			$student_id    = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
			$lesson_id     = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
			$course_id     = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
			$status        = (string) get_post_meta( $submission_id, '_clms_submission_status', true );
			$student       = $student_id ? get_user_by( 'id', $student_id ) : false;

			if ( ! $lesson_id || ! in_array( $lesson_id, $allowed_lessons, true ) ) {
				continue;
			}

			if ( ! $course_id && $lesson_id ) {
				$course_id = CLMS_Helper::get_course_id_from_lesson( $lesson_id );
			}

			$program_ids    = $course_id ? CLMS_Helper::get_course_program_ids( $course_id ) : array();
			$program_titles = array();
			foreach ( $program_ids as $program_id ) {
				$program_title = get_the_title( $program_id );
				if ( $program_title ) {
					$program_titles[] = $program_title;
				}
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
				'program_titles'      => ! empty( $program_titles ) ? implode( ' · ', $program_titles ) : '',
				'status'              => $status,
				'status_label'        => $this->get_submission_status_label( $status ),
				'submitted_at'        => $this->format_datetime( get_post_field( 'post_date', $submission_id ) ),
				'submitted_timestamp' => strtotime( get_post_field( 'post_date_gmt', $submission_id ) ),
				'speedgrade_url'      => $this->resolve_speedgrade_url( $grading, $submission_id ),
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
			'page'  => $page,
			'pages' => $pages,
		);
	}

	protected function parse_inbox_scope_filter( $scope ) {
		$scope = sanitize_text_field( (string) $scope );
		if ( preg_match( '/^(course|program)\-(\d+)$/', $scope, $matches ) ) {
			return array(
				'type' => sanitize_key( $matches[1] ),
				'id'   => absint( $matches[2] ),
			);
		}
		return array(
			'type' => '',
			'id'   => 0,
		);
	}

	protected function get_submission_inbox_filter_options( $course_ids, $lesson_ids, $items ) {
		$course_ids = is_array( $course_ids ) ? array_map( 'absint', $course_ids ) : array();
		$lesson_ids = is_array( $lesson_ids ) ? array_map( 'absint', $lesson_ids ) : array();
		$items      = is_array( $items ) ? $items : array();

		$students = array();
		$lessons  = array();
		$courses  = array();
		$programs = array();

		foreach ( $items as $item ) {
			if ( ! empty( $item['student_id'] ) && ! empty( $item['student_name'] ) ) {
				$students[ absint( $item['student_id'] ) ] = $item['student_name'];
			}
			if ( ! empty( $item['lesson_id'] ) && ! empty( $item['lesson_title'] ) ) {
				$lessons[ absint( $item['lesson_id'] ) ] = $item['lesson_title'];
			}
		}

		if ( empty( $lessons ) && ! empty( $lesson_ids ) ) {
			foreach ( array_slice( $lesson_ids, 0, 30 ) as $lesson_id ) {
				$lesson_title = get_the_title( $lesson_id );
				if ( $lesson_title ) {
					$lessons[ absint( $lesson_id ) ] = $lesson_title;
				}
			}
		}

		foreach ( $course_ids as $course_id ) {
			$course_title = get_the_title( $course_id );
			if ( $course_title ) {
				$courses[ absint( $course_id ) ] = $course_title;
			}
			foreach ( CLMS_Helper::get_course_program_ids( $course_id ) as $program_id ) {
				$program_title = get_the_title( $program_id );
				if ( $program_title ) {
					$programs[ absint( $program_id ) ] = $program_title;
				}
			}
		}

		asort( $students );
		asort( $lessons );
		asort( $courses );
		asort( $programs );

		return array(
			'students' => $students,
			'lessons'  => $lessons,
			'courses'  => $courses,
			'programs' => $programs,
		);
	}

	protected function get_teacher_notifications( $user_id, $limit = 4 ) {
		$user_id = absint( $user_id );
		$limit   = max( 1, absint( $limit ) );

		if ( ! $user_id ) {
			return array();
		}

		$notifications = clms_core('CLMS_Notifications');
		if ( ! $notifications || ! method_exists( $notifications, 'get_notifications' ) ) {
			return array();
		}

		$items   = $notifications->get_notifications( $user_id, true );
		$items   = array_slice( (array) $items, 0, $limit );
		$grading = clms_core('CLMS_Grading');
		$list    = array();

		foreach ( $items as $item ) {
			$item = is_array( $item ) ? $item : array();
			$submission_id = isset( $item['submission_id'] ) ? absint( $item['submission_id'] ) : 0;
			$list[] = array(
				'title'         => sanitize_text_field( $item['title'] ?? '' ),
				'message'       => sanitize_text_field( $item['message'] ?? '' ),
				'created_at'    => sanitize_text_field( $item['created_at'] ?? '' ),
				'link'          => ! empty( $item['link'] ) ? esc_url_raw( $item['link'] ) : '',
				'speedgrade_url'=> $submission_id ? $this->resolve_speedgrade_url( $grading, $submission_id ) : '',
			);
		}

		return $list;
	}

	protected function resolve_speedgrade_url( $grading, $submission_id ) {
		$submission_id = absint( $submission_id );
		if ( ! $submission_id ) {
			return '';
		}
		if ( $grading && method_exists( $grading, 'get_speedgrade_url' ) ) {
			return $grading->get_speedgrade_url( $submission_id );
		}
		return get_edit_post_link( $submission_id, '' );
	}

	protected function sanitize_date_input( $date ) {
		$date = sanitize_text_field( (string) $date );
		if ( ! preg_match( '/^\d{4}\-\d{2}\-\d{2}$/', $date ) ) {
			return '';
		}
		return $date;
	}

	/* ---------------------------------------------------------------
	   HELPERS
	--------------------------------------------------------------- */

	protected function build_lead_text( $metrics ) {
		$pending = isset( $metrics['pending_reviews'] ) ? absint( $metrics['pending_reviews'] ) : 0;
		$courses = isset( $metrics['courses'] ) ? absint( $metrics['courses'] ) : 0;
		$lessons = isset( $metrics['lessons'] ) ? absint( $metrics['lessons'] ) : 0;

		if ( $pending > 0 ) {
			return sprintf(
				esc_html__( 'Tienes %1$d entrega(s) pendiente(s) por revisar en %2$d curso(s) y %3$d lección(es) activas.', 'atora-lms' ),
				$pending,
				$courses,
				$lessons
			);
		}

		return sprintf(
			esc_html__( 'Administras %1$d curso(s) con %2$d lección(es) activas.', 'atora-lms' ),
			$courses,
			$lessons
		);
	}

	protected function get_submission_status_label( $status ) {
		switch ( (string) $status ) {
			case 'graded':
				return __( 'Calificada', 'atora-lms' );
			case 'in_review':
				return __( 'En revisión', 'atora-lms' );
			case 'submitted':
				return __( 'Enviada', 'atora-lms' );
			default:
				return __( 'Actualizada', 'atora-lms' );
		}
	}

	protected function truncate_text( $text, $length = 160 ) {
		$text   = wp_strip_all_tags( (string) $text );
		$length = max( 1, absint( $length ) );

		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}

		return mb_substr( $text, 0, $length - 1 ) . '…';
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

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
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

	/* ---------------------------------------------------------------
	   ASSETS
	--------------------------------------------------------------- */

}
