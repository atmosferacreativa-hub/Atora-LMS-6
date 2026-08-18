<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_REST_Permissions {

	public function can_read_course( WP_REST_Request $request ) {
		unset( $request );

		if ( is_user_logged_in() ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Debes iniciar sesión para acceder a este recurso.', 'atora-lms' ),
			array( 'status' => 401 )
		);
	}

	public function can_read_program( WP_REST_Request $request ) {
		unset( $request );

		if ( is_user_logged_in() ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Debes iniciar sesión para acceder a este recurso.', 'atora-lms' ),
			array( 'status' => 401 )
		);
	}

	public function can_read_public( WP_REST_Request $request ) {
		unset( $request );
		return true;
	}

	public function can_read_lesson( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			$this->log_permission_denied(
				'lesson_read',
				array(
					'reason' => 'not_logged_in',
					'route'  => $request->get_route(),
				)
			);
			return new WP_Error(
				'rest_forbidden',
				__( 'Debes iniciar sesión para acceder a las lecciones.', 'atora-lms' ),
				array( 'status' => 401 )
			);
		}

		$lesson_id = ! empty( $request['id'] ) ? absint( $request['id'] ) : 0;

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( $lesson_id && $this->current_user_can_manage_post_resource( $lesson_id ) ) {
			return true;
		}

		if ( $lesson_id && class_exists( 'CLMS_Helper' ) && ! CLMS_Helper::user_can_access_lesson( get_current_user_id(), $lesson_id ) ) {
			$this->log_permission_denied(
				'lesson_read',
				array(
					'reason'    => 'lesson_access_denied',
					'lesson_id' => $lesson_id,
					'route'     => $request->get_route(),
				)
			);
			return new WP_Error(
				'rest_forbidden',
				__( 'No tienes acceso a esta lección.', 'atora-lms' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function can_access_logged_in() {
		$allowed = is_user_logged_in();

		if ( ! $allowed ) {
			$this->log_permission_denied(
				'logged_in_required',
				array(
					'reason' => 'not_logged_in',
				)
			);
		}

		return $allowed;
	}

	public function can_manage_content() {
		$allowed = CLMS_Access::can_manage_courses()
			|| CLMS_Access::can_manage_lessons()
			|| CLMS_Access::can_manage_submissions()
			|| CLMS_Access::can_grade_submissions();

		if ( ! $allowed ) {
			$this->log_permission_denied(
				'manage_content',
				array(
					'reason' => 'missing_capability',
				)
			);
		}

		return $allowed;
	}

	public function can_read_lesson_meta( $allowed = false, $meta_key = '', $post_id = 0, $user_id = 0 ) {
		unset( $allowed, $meta_key );

		$post_id = absint( $post_id );
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

		if ( ! $user_id || ! $post_id ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) || $this->current_user_can_manage_post_resource( $post_id ) ) {
			return true;
		}

		return class_exists( 'CLMS_Helper' ) && CLMS_Helper::user_can_access_lesson( $user_id, $post_id );
	}

	public function can_view_user_resource( WP_REST_Request $request ) {
		$user_id = absint( $request['user_id'] );
		$current = get_current_user_id();

		if ( ! $current || ! $user_id ) {
			$this->log_permission_denied(
				'user_resource',
				array(
					'reason'           => 'not_logged_in',
					'requested_user_id' => $user_id,
				)
			);
			return false;
		}

		if ( $current === $user_id ) {
			return true;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( CLMS_Access::can_grade_submissions() || CLMS_Access::can_view_teacher_dashboard() || CLMS_Access::can_manage_courses() || CLMS_Access::can_manage_lessons() ) {
			if ( class_exists( 'CLMS_Helper' ) ) {
				$course_ids = ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $user_id ) );
				$visible    = $this->filter_course_ids_for_current_viewer( is_array( $course_ids ) ? $course_ids : array(), $user_id );
				if ( ! empty( $visible ) ) {
					return true;
				}
			}
		}

		$this->log_permission_denied(
			'user_resource',
			array(
				'reason'            => 'viewer_scope_denied',
				'requested_user_id' => $user_id,
				'current_user_id'   => $current,
			)
		);

		return false;
	}

	public function current_user_can_manage_post_resource( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return false;
		}

		if ( class_exists( 'CLMS_Helper' ) && CLMS_Helper::user_can_manage_lms( $post_id ) ) {
			return true;
		}

		return 'clms_rubric' === $post->post_type
			&& CLMS_Access::can_manage_lessons()
			&& (int) $post->post_author === get_current_user_id();
	}

	public function current_user_can_assign_author( $author_id ) {
		$author_id = absint( $author_id );

		if ( ! $author_id ) {
			return true;
		}

		return current_user_can( 'manage_options' ) || get_current_user_id() === $author_id;
	}

	public function current_user_can_view_submission_resource( $submission_id, $requested_user_id = 0 ) {
		$submission_id      = absint( $submission_id );
		$requested_user_id  = absint( $requested_user_id );
		$current_user_id    = get_current_user_id();

		if ( ! $submission_id || ! $current_user_id ) {
			return false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( $requested_user_id && $current_user_id === $requested_user_id ) {
			return true;
		}

		$grading = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Grading') : null;

		if ( $grading && method_exists( $grading, 'current_user_can_grade_submission' ) ) {
			return (bool) $grading->current_user_can_grade_submission( $submission_id, $current_user_id );
		}

		$course_id = absint( get_post_meta( $submission_id, '_clms_submission_course_id', true ) );
		$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );

		if ( $course_id && $this->current_user_can_manage_post_resource( $course_id ) ) {
			return true;
		}

		return $lesson_id && $this->current_user_can_manage_post_resource( $lesson_id );
	}

	public function filter_course_ids_for_current_viewer( array $course_ids, $requested_user_id ) {
		$requested_user_id = absint( $requested_user_id );
		$current_user_id   = get_current_user_id();

		if ( ! $current_user_id || current_user_can( 'manage_options' ) || $current_user_id === $requested_user_id ) {
			return array_values( array_unique( array_map( 'absint', $course_ids ) ) );
		}

		$visible = array();

		foreach ( $course_ids as $course_id ) {
			$course_id = absint( $course_id );

			if ( $course_id && $this->current_user_can_manage_post_resource( $course_id ) ) {
				$visible[] = $course_id;
			}
		}

		return array_values( array_unique( $visible ) );
	}

	public function guard_native_lesson_rest_access( $result, $server, $request ) {
		unset( $server );

		if ( ! ( $request instanceof WP_REST_Request ) ) {
			return $result;
		}

		$route = '/' . ltrim( (string) $request->get_route(), '/' );

		if ( 0 !== strpos( $route, '/wp/v2/lm_lesson' ) ) {
			return $result;
		}

		if ( current_user_can( 'manage_options' ) || CLMS_Access::can_manage_courses() || CLMS_Access::can_manage_lessons() ) {
			return $result;
		}

		if ( ! is_user_logged_in() ) {
			$this->log_permission_denied(
				'native_lesson_rest',
				array(
					'reason' => 'not_logged_in',
					'route'  => $route,
				)
			);
			return new WP_Error(
				'rest_forbidden',
				__( 'Debes iniciar sesión para acceder a las lecciones.', 'atora-lms' ),
				array( 'status' => 401 )
			);
		}

		$lesson_id = 0;
		if ( isset( $request['id'] ) ) {
			$lesson_id = absint( $request['id'] );
		} elseif ( isset( $request['parent'] ) ) {
			$lesson_id = absint( $request['parent'] );
		}

		if (
			$lesson_id &&
			in_array( strtoupper( $request->get_method() ), array( 'GET', 'HEAD' ), true ) &&
			class_exists( 'CLMS_Helper' ) &&
			CLMS_Helper::user_can_access_lesson( get_current_user_id(), $lesson_id )
		) {
			return $result;
		}

		$this->log_permission_denied(
			'native_lesson_rest',
			array(
				'reason'    => 'native_lesson_access_denied',
				'route'     => $route,
				'lesson_id' => $lesson_id,
			)
		);

		return new WP_Error(
			'rest_forbidden',
			__( 'No tienes acceso a este recurso de la lección.', 'atora-lms' ),
			array( 'status' => 403 )
		);
	}

	protected function log_permission_denied( $scope, $context = array() ) {
		do_action(
			'clms_permission_denied',
			array_merge(
				array(
					'scope'           => sanitize_key( (string) $scope ),
					'current_user_id' => get_current_user_id(),
					'message'         => __( 'Acceso denegado en capa REST.', 'atora-lms' ),
				),
				is_array( $context ) ? $context : array()
			)
		);
	}
}
