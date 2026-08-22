<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_REST_Academics_Core_Resources_Trait {
	protected function current_user_can_manage_post_resource( $post_id ) {
		return $this->permissions->current_user_can_manage_post_resource( $post_id );
	}

	protected function current_user_can_assign_author( $author_id ) {
		return $this->permissions->current_user_can_assign_author( $author_id );
	}

	protected function current_user_can_view_submission_resource( $submission_id, $requested_user_id = 0 ) {
		return $this->permissions->current_user_can_view_submission_resource( $submission_id, $requested_user_id );
	}

	protected function filter_course_ids_for_current_viewer( array $course_ids, $requested_user_id ) {
		return $this->permissions->filter_course_ids_for_current_viewer( $course_ids, $requested_user_id );
	}

	public function get_courses( WP_REST_Request $request ) {
		$page       = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page   = $this->sanitize_per_page( $request->get_param( 'per_page' ) );
		$search     = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$status     = sanitize_key( (string) $request->get_param( 'status' ) );
		$teacher_id = absint( $request->get_param( 'teacher_id' ) );
		$can_manage = CLMS_Access::can_manage_courses() || CLMS_Access::can_manage_lessons() || CLMS_Access::can_manage_submissions() || CLMS_Access::can_grade_submissions();
		$allowed_statuses = array( 'publish', 'draft', 'private' );

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'publish';
		}
		if ( ! $can_manage ) {
			$status = 'publish';
		}

		// PT-1 (6.5.7, "legacy REST hardening" — hallazgo real, no
		// atrapado en 6.5.6): clms_manage_courses/lessons/submissions/
		// grade_submissions es una capability GENÉRICA (cualquier
		// instructor la tiene, no solo edit_others_lm_courses/
		// manage_options). Con status=draft|private + un teacher_id
		// arbitrario provisto por el cliente, un instructor A obtenía
		// directamente los borradores del instructor B — $args['author']
		// se fijaba al valor del cliente sin verificar dueño. Un
		// instructor sin capability global, al pedir contenido no
		// público, solo puede ver LO SUYO — se ignora cualquier
		// teacher_id ajeno y se fuerza al usuario actual (en vez de
		// rechazar, para no habilitar enumeración por mensajes de error
		// distintos).
		$is_global_manager = current_user_can( 'manage_options' ) || current_user_can( 'edit_others_lm_courses' );

		if ( 'publish' !== $status && ! $is_global_manager ) {
			$teacher_id = get_current_user_id();
		}

		$args = array(
			'post_type'      => 'lm_course',
			'post_status'    => $status ? $status : 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			's'              => $search,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( $teacher_id ) {
			$args['author'] = $teacher_id;
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->prepare_course_response( $post );
		}

		$payload = array(
			'items'       => $items,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$payload = (array) CLMS_Helper::modular_apply( 'rest_academics_courses_payload', $payload, $request );
		}

		return rest_ensure_response( $payload );
	}

	public function get_course( WP_REST_Request $request ) {
		$post = get_post( absint( $request['id'] ) );

		if ( ! $post || 'lm_course' !== $post->post_type ) {
			return new WP_Error( 'clms_course_not_found', __( 'Curso no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( 'publish' !== $post->post_status && ! CLMS_Helper::user_can_manage_lms( $post->ID ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No autorizado.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response( $this->prepare_course_response( $post, true ) );
	}

	public function create_course( WP_REST_Request $request ) {
		$data = $this->get_json_or_body_params( $request );
		$author_id = ! empty( $data['teacher_id'] ) ? absint( $data['teacher_id'] ) : get_current_user_id();

		if ( ! $this->current_user_can_assign_author( $author_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No puedes asignar este curso a otro instructor.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$postarr = array(
			'post_type'    => 'lm_course',
			'post_status'  => ! empty( $data['status'] ) ? sanitize_key( $data['status'] ) : 'publish',
			'post_title'   => ! empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'post_content' => isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '',
			'post_excerpt' => isset( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : '',
			'menu_order'   => isset( $data['menu_order'] ) ? intval( $data['menu_order'] ) : 0,
			'post_author'  => $author_id,
		);

		if ( '' === $postarr['post_title'] ) {
			return new WP_Error( 'clms_invalid_title', __( 'El título del curso es obligatorio.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->save_course_meta( $post_id, $data );

		return new WP_REST_Response( $this->prepare_course_response( get_post( $post_id ), true ), 201 );
	}

	public function update_course( WP_REST_Request $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post || 'lm_course' !== $post->post_type ) {
			return new WP_Error( 'clms_course_not_found', __( 'Curso no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! $this->current_user_can_manage_post_resource( $post_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para editar este curso.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$data    = $this->get_json_or_body_params( $request );
		$postarr = array( 'ID' => $post_id );

		if ( isset( $data['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['content'] ) ) {
			$postarr['post_content'] = wp_kses_post( $data['content'] );
		}

		if ( isset( $data['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( $data['excerpt'] );
		}

		if ( isset( $data['status'] ) ) {
			$postarr['post_status'] = sanitize_key( $data['status'] );
		}

		if ( isset( $data['menu_order'] ) ) {
			$postarr['menu_order'] = intval( $data['menu_order'] );
		}

		if ( isset( $data['teacher_id'] ) && absint( $data['teacher_id'] ) ) {
			if ( ! $this->current_user_can_assign_author( $data['teacher_id'] ) ) {
				return new WP_Error( 'clms_forbidden', __( 'No puedes reasignar este curso a otro instructor.', 'atora-lms' ), array( 'status' => 403 ) );
			}
			$postarr['post_author'] = absint( $data['teacher_id'] );
		}

		$result = wp_update_post( $postarr, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->save_course_meta( $post_id, $data );

		return rest_ensure_response( $this->prepare_course_response( get_post( $post_id ), true ) );
	}

	public function delete_course( WP_REST_Request $request ) {
		$post_id = absint( $request['id'] );
		$force   = (bool) $request->get_param( 'force' );
		$post    = get_post( $post_id );

		if ( ! $post || 'lm_course' !== $post->post_type ) {
			return new WP_Error( 'clms_course_not_found', __( 'Curso no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! $this->current_user_can_manage_post_resource( $post_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para eliminar este curso.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$deleted = wp_delete_post( $post_id, $force );

		if ( ! $deleted ) {
			return new WP_Error( 'clms_delete_failed', __( 'No se pudo eliminar el curso.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $post_id,
			)
		);
	}

	public function enroll_in_course( WP_REST_Request $request ) {
		$course_id = absint( $request['id'] );
		$user_id   = get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'clms_not_logged_in', __( 'Debes iniciar sesión.', 'atora-lms' ), array( 'status' => 401 ) );
		}

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return new WP_Error( 'clms_course_not_found', __( 'Curso no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! class_exists( 'CLMS_Helper' ) ) {
			return new WP_Error( 'clms_helper_missing', __( 'El helper del LMS no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		if ( ! CLMS_Helper::can_self_enroll_in_course( $course_id, $user_id ) ) {
			return new WP_Error( 'clms_purchase_required', __( 'Debes completar la compra antes de inscribirte en este curso.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		if ( CLMS_Helper::user_is_enrolled_in_course( $user_id, $course_id ) ) {
			return rest_ensure_response(
				array(
					'success'   => true,
					'status'    => 'already_enrolled',
					'user_id'   => $user_id,
					'course_id' => $course_id,
				)
			);
		}

		$ok = CLMS_Helper::enroll_user_in_course( $user_id, $course_id );

		if ( ! $ok ) {
			return new WP_Error( 'clms_enroll_failed', __( 'No se pudo completar la inscripción.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'success'   => true,
				'status'    => 'enrolled',
				'user_id'   => $user_id,
				'course_id' => $course_id,
			)
		);
	}

	public function get_programs( WP_REST_Request $request ) {
		$page       = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page   = $this->sanitize_per_page( $request->get_param( 'per_page' ) );
		$search     = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$status     = sanitize_key( (string) $request->get_param( 'status' ) );
		$teacher_id = absint( $request->get_param( 'teacher_id' ) );
		$can_manage = CLMS_Access::can_manage_courses() || CLMS_Access::can_manage_lessons() || CLMS_Access::can_manage_submissions() || CLMS_Access::can_grade_submissions();
		$allowed_statuses = array( 'publish', 'draft', 'private' );

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'publish';
		}
		if ( ! $can_manage ) {
			$status = 'publish';
		}

		// PT-1 (6.5.7): mismo hallazgo/corrección que get_courses().
		$is_global_manager = current_user_can( 'manage_options' ) || current_user_can( 'edit_others_lm_courses' );

		if ( 'publish' !== $status && ! $is_global_manager ) {
			$teacher_id = get_current_user_id();
		}

		$args = array(
			'post_type'      => 'lm_program',
			'post_status'    => $status ? $status : 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			's'              => $search,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( $teacher_id ) {
			$args['author'] = $teacher_id;
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->prepare_program_response( $post );
		}

		return rest_ensure_response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			)
		);
	}

	public function get_program( WP_REST_Request $request ) {
		$post = get_post( absint( $request['id'] ) );

		if ( ! $post || 'lm_program' !== $post->post_type ) {
			return new WP_Error( 'clms_program_not_found', __( 'Programa no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( 'publish' !== $post->post_status && ! CLMS_Helper::user_can_manage_lms( $post->ID ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No autorizado.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response( $this->prepare_program_response( $post, true ) );
	}

	public function create_program( WP_REST_Request $request ) {
		$data      = $this->get_json_or_body_params( $request );
		$author_id = ! empty( $data['teacher_id'] ) ? absint( $data['teacher_id'] ) : get_current_user_id();

		if ( ! $this->current_user_can_assign_author( $author_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No puedes asignar este programa a otro instructor.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$postarr = array(
			'post_type'    => 'lm_program',
			'post_status'  => ! empty( $data['status'] ) ? sanitize_key( $data['status'] ) : 'publish',
			'post_title'   => ! empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'post_content' => isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '',
			'post_excerpt' => isset( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : '',
			'menu_order'   => isset( $data['menu_order'] ) ? intval( $data['menu_order'] ) : 0,
			'post_author'  => $author_id,
		);

		if ( '' === $postarr['post_title'] ) {
			return new WP_Error( 'clms_invalid_title', __( 'El título del programa es obligatorio.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->save_program_meta( $post_id, $data );

		return new WP_REST_Response( $this->prepare_program_response( get_post( $post_id ), true ), 201 );
	}

	public function update_program( WP_REST_Request $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post || 'lm_program' !== $post->post_type ) {
			return new WP_Error( 'clms_program_not_found', __( 'Programa no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! $this->current_user_can_manage_post_resource( $post_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para editar este programa.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$data    = $this->get_json_or_body_params( $request );
		$postarr = array( 'ID' => $post_id );

		if ( isset( $data['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['content'] ) ) {
			$postarr['post_content'] = wp_kses_post( $data['content'] );
		}

		if ( isset( $data['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( $data['excerpt'] );
		}

		if ( isset( $data['status'] ) ) {
			$postarr['post_status'] = sanitize_key( $data['status'] );
		}

		if ( isset( $data['menu_order'] ) ) {
			$postarr['menu_order'] = intval( $data['menu_order'] );
		}

		if ( isset( $data['teacher_id'] ) && absint( $data['teacher_id'] ) ) {
			if ( ! $this->current_user_can_assign_author( $data['teacher_id'] ) ) {
				return new WP_Error( 'clms_forbidden', __( 'No puedes reasignar este programa a otro instructor.', 'atora-lms' ), array( 'status' => 403 ) );
			}

			$postarr['post_author'] = absint( $data['teacher_id'] );
		}

		$result = wp_update_post( $postarr, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->save_program_meta( $post_id, $data );

		return rest_ensure_response( $this->prepare_program_response( get_post( $post_id ), true ) );
	}

	public function delete_program( WP_REST_Request $request ) {
		$post_id = absint( $request['id'] );
		$force   = (bool) $request->get_param( 'force' );
		$post    = get_post( $post_id );

		if ( ! $post || 'lm_program' !== $post->post_type ) {
			return new WP_Error( 'clms_program_not_found', __( 'Programa no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! $this->current_user_can_manage_post_resource( $post_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para eliminar este programa.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$deleted = wp_delete_post( $post_id, $force );

		if ( ! $deleted ) {
			return new WP_Error( 'clms_delete_failed', __( 'No se pudo eliminar el programa.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $post_id,
			)
		);
	}

	public function enroll_in_program( WP_REST_Request $request ) {
		$program_id = absint( $request['id'] );
		$user_id    = get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'clms_not_logged_in', __( 'Debes iniciar sesión.', 'atora-lms' ), array( 'status' => 401 ) );
		}

		if ( ! $program_id || 'lm_program' !== get_post_type( $program_id ) ) {
			return new WP_Error( 'clms_program_not_found', __( 'Programa no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! class_exists( 'CLMS_Helper' ) ) {
			return new WP_Error( 'clms_helper_missing', __( 'El helper del LMS no está disponible.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		if ( ! CLMS_Helper::can_self_enroll_in_program( $program_id, $user_id ) ) {
			return new WP_Error( 'clms_purchase_required', __( 'Debes completar la compra antes de inscribirte en este programa.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$result = CLMS_Helper::enroll_user_in_program( $user_id, $program_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success'                  => true,
				'status'                   => 'enrolled',
				'user_id'                  => $user_id,
				'program_id'               => $program_id,
				'newly_enrolled_courses'   => isset( $result['newly_enrolled_courses'] ) ? array_values( array_map( 'absint', (array) $result['newly_enrolled_courses'] ) ) : array(),
				'already_enrolled_courses' => isset( $result['already_enrolled_courses'] ) ? array_values( array_map( 'absint', (array) $result['already_enrolled_courses'] ) ) : array(),
			)
		);
	}

	public function get_lessons( WP_REST_Request $request ) {
		$page         = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page     = $this->sanitize_per_page( $request->get_param( 'per_page' ) );
		$search       = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$course_id    = absint( $request->get_param( 'course_id' ) );
		$teacher_id   = absint( $request->get_param( 'teacher_id' ) );
		$status       = sanitize_key( (string) $request->get_param( 'status' ) );
		$available_to = absint( $request->get_param( 'available_to' ) );
		$current_user_id = get_current_user_id();
		$can_manage = CLMS_Access::can_manage_lessons() || CLMS_Access::can_manage_courses();
		$allowed_statuses = array( 'publish', 'draft', 'private', 'all' );

		// PT-1 (6.5.7, "legacy REST hardening" — hallazgo real, no
		// atrapado en 6.5.6): $can_manage es una capability GENÉRICA
		// (clms_manage_lessons/clms_manage_courses — cualquier
		// instructor), no ownership. Con status=draft|private|all +
		// teacher_id o course_id de OTRO instructor, un instructor A
		// obtenía directamente las lecciones/borradores del instructor
		// B: $args['author'] se fijaba al teacher_id del cliente sin
		// verificar dueño, y la rama de course_id solo validaba acceso
		// para "!$can_manage" (nunca para un instructor con capability,
		// que es justo el atacante en este escenario). Se calcula ANTES
		// de que 'all' se expanda a un array, para no perder la señal.
		$requests_non_public = $can_manage && in_array( $status, array( 'draft', 'private', 'all' ), true );

		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'publish';
		}
		if ( ! $can_manage ) {
			// Non-managers can only see published lessons.
			$status = 'publish';
		} elseif ( 'all' === $status ) {
			// Managers requesting all statuses get publish + draft + private.
			$status = array( 'publish', 'draft', 'private' );
		}
		if ( is_user_logged_in() && ! $can_manage ) {
			$available_to = $current_user_id;
		}

		$is_global_manager = current_user_can( 'manage_options' ) || current_user_can( 'edit_others_lm_courses' );

		if ( $requests_non_public && ! $is_global_manager ) {
			$teacher_id = $current_user_id;
		}

		$args = array(
			'post_type'      => 'lm_lesson',
			'post_status'    => $status ? $status : 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			's'              => $search,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
		);

		if ( $teacher_id ) {
			$args['author'] = $teacher_id;
		}

		// PT-1 (6.5.7): el chequeo de dueño de course_id se evalúa
		// independientemente de si CLMS_Helper está cargado — antes
		// vivía dentro del `if ( $course_id && class_exists(
		// 'CLMS_Helper' ) )`, así que en cualquier contexto sin esa
		// clase (no debería ocurrir en producción, pero no hay razón
		// para que el gate de seguridad dependa de ello) el filtro de
		// post__in nunca se aplicaba y la comprobación tampoco corría.
		if ( $course_id && $requests_non_public && ! $is_global_manager && ! $this->current_user_can_manage_post_resource( $course_id ) ) {
			return rest_ensure_response(
				array(
					'items'       => array(),
					'total'       => 0,
					'total_pages' => 0,
					'page'        => $page,
					'per_page'    => $per_page,
				)
			);
		}

		if ( $course_id && class_exists( 'CLMS_Helper' ) ) {
			if ( ! $can_manage && $current_user_id && ! CLMS_Helper::user_can_access_course( $current_user_id, $course_id ) ) {
				return rest_ensure_response(
					array(
						'items'       => array(),
						'total'       => 0,
						'total_pages' => 0,
						'page'        => $page,
						'per_page'    => $per_page,
					)
				);
			}
			$ids              = CLMS_Helper::get_course_lessons( $course_id );
			$args['post__in'] = ! empty( $ids ) ? array_map( 'absint', $ids ) : array( 0 );
			$args['orderby']  = 'post__in';
		} elseif ( ! $can_manage && $current_user_id && class_exists( 'CLMS_Helper' ) ) {
			$course_ids = ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $current_user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $current_user_id ) );
			$course_ids = is_array( $course_ids ) ? array_map( 'absint', $course_ids ) : array();
			$lesson_ids = array();
			foreach ( $course_ids as $enrolled_course_id ) {
				$lesson_ids = array_merge( $lesson_ids, (array) CLMS_Helper::get_course_lessons( $enrolled_course_id ) );
			}
			$lesson_ids       = array_values( array_unique( array_filter( array_map( 'absint', $lesson_ids ) ) ) );
			$args['post__in'] = ! empty( $lesson_ids ) ? $lesson_ids : array( 0 );
			$args['orderby']  = 'post__in';
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			if ( $available_to && class_exists( 'CLMS_Helper' ) && ! CLMS_Helper::user_can_access_lesson( $available_to, $post->ID ) ) {
				continue;
			}

			$items[] = $this->prepare_lesson_response( $post );
		}

		return rest_ensure_response(
			array(
				'items'       => $items,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'page'        => $page,
				'per_page'    => $per_page,
			)
		);
	}

	public function get_lesson( WP_REST_Request $request ) {
		$post = get_post( absint( $request['id'] ) );

		if ( ! $post || 'lm_lesson' !== $post->post_type ) {
			return new WP_Error( 'clms_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( 'publish' !== $post->post_status && ! CLMS_Helper::user_can_manage_lms( $post->ID ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No autorizado.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response( $this->prepare_lesson_response( $post, true ) );
	}

	public function create_lesson( WP_REST_Request $request ) {
		$data = $this->get_json_or_body_params( $request );
		$author_id = ! empty( $data['teacher_id'] ) ? absint( $data['teacher_id'] ) : get_current_user_id();
		$course_id = isset( $data['course_id'] ) ? absint( $data['course_id'] ) : 0;

		if ( ! $this->current_user_can_assign_author( $author_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No puedes asignar esta lección a otro instructor.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		if ( $course_id && ! $this->current_user_can_manage_post_resource( $course_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para crear lecciones en este curso.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$postarr = array(
			'post_type'    => 'lm_lesson',
			'post_status'  => ! empty( $data['status'] ) ? sanitize_key( $data['status'] ) : 'publish',
			'post_title'   => ! empty( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'post_content' => isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '',
			'post_excerpt' => isset( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : '',
			'menu_order'   => isset( $data['menu_order'] ) ? intval( $data['menu_order'] ) : 0,
			'post_author'  => $author_id,
		);

		if ( '' === $postarr['post_title'] ) {
			return new WP_Error( 'clms_invalid_title', __( 'El título de la lección es obligatorio.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->save_lesson_meta( $post_id, $data );

		return new WP_REST_Response( $this->prepare_lesson_response( get_post( $post_id ), true ), 201 );
	}

	public function update_lesson( WP_REST_Request $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );

		if ( ! $post || 'lm_lesson' !== $post->post_type ) {
			return new WP_Error( 'clms_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! $this->current_user_can_manage_post_resource( $post_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para editar esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$data    = $this->get_json_or_body_params( $request );
		$postarr = array( 'ID' => $post_id );

		if ( isset( $data['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['content'] ) ) {
			$postarr['post_content'] = wp_kses_post( $data['content'] );
		}

		if ( isset( $data['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( $data['excerpt'] );
		}

		if ( isset( $data['status'] ) ) {
			$postarr['post_status'] = sanitize_key( $data['status'] );
		}

		if ( isset( $data['menu_order'] ) ) {
			$postarr['menu_order'] = intval( $data['menu_order'] );
		}

		if ( isset( $data['teacher_id'] ) && absint( $data['teacher_id'] ) ) {
			if ( ! $this->current_user_can_assign_author( $data['teacher_id'] ) ) {
				return new WP_Error( 'clms_forbidden', __( 'No puedes reasignar esta lección a otro instructor.', 'atora-lms' ), array( 'status' => 403 ) );
			}
			$postarr['post_author'] = absint( $data['teacher_id'] );
		}

		if ( isset( $data['course_id'] ) && absint( $data['course_id'] ) && ! $this->current_user_can_manage_post_resource( $data['course_id'] ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No puedes mover esta lección a un curso ajeno.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$result = wp_update_post( $postarr, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->save_lesson_meta( $post_id, $data );

		return rest_ensure_response( $this->prepare_lesson_response( get_post( $post_id ), true ) );
	}

	public function quick_edit_lesson( WP_REST_Request $request ) {
		$lesson_id = absint( $request['id'] );
		$lesson    = get_post( $lesson_id );

		if ( ! $lesson || 'lm_lesson' !== $lesson->post_type ) {
			return new WP_Error( 'clms_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! $this->current_user_can_manage_post_resource( $lesson_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para editar esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$data = $this->get_json_or_body_params( $request );
		if ( empty( $data ) ) {
			return new WP_Error( 'clms_quick_edit_empty', __( 'No se enviaron cambios para guardar.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$current_drip_type = (string) get_post_meta( $lesson_id, '_clms_drip_type', true );
		$normalized        = $this->normalize_lesson_quick_edit_data( $data, $current_drip_type );

		if ( ! empty( $normalized ) ) {
			$this->save_lesson_meta( $lesson_id, $normalized );
		}

		if ( array_key_exists( 'post_status', $data ) ) {
			$status = sanitize_key( (string) $data['post_status'] );
			$status = in_array( $status, array( 'draft', 'publish', 'private', 'pending', 'future' ), true ) ? $status : '';
			if ( $status ) {
				wp_update_post(
					array(
						'ID'          => $lesson_id,
						'post_status' => $status,
					)
				);
			}
		}

		$course_id = class_exists( 'CLMS_Helper' ) ? absint( CLMS_Helper::get_lesson_course_id( $lesson_id ) ) : 0;
		if ( class_exists( 'CLMS_Helper' ) ) {
			CLMS_Helper::flush_runtime_cache( $course_id, $lesson_id );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'lesson'  => $this->prepare_lesson_response( get_post( $lesson_id ), true ),
			)
		);
	}

	public function delete_lesson( WP_REST_Request $request ) {
		$post_id = absint( $request['id'] );
		$force   = (bool) $request->get_param( 'force' );
		$post    = get_post( $post_id );

		if ( ! $post || 'lm_lesson' !== $post->post_type ) {
			return new WP_Error( 'clms_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! $this->current_user_can_manage_post_resource( $post_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para eliminar esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$deleted = wp_delete_post( $post_id, $force );

		if ( ! $deleted ) {
			return new WP_Error( 'clms_delete_failed', __( 'No se pudo eliminar la lección.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $post_id,
			)
		);
	}

	public function complete_lesson( WP_REST_Request $request ) {
		$lesson_id = absint( $request['id'] );
		$user_id   = get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'clms_not_logged_in', __( 'Debes iniciar sesión.', 'atora-lms' ), array( 'status' => 401 ) );
		}

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'clms_lesson_not_found', __( 'Lección no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( class_exists( 'CLMS_Helper' ) && ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
			return new WP_Error( 'clms_lesson_locked', __( 'La lección aún no está disponible.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed = is_array( $completed ) ? array_map( 'absint', $completed ) : array();

		if ( ! in_array( $lesson_id, $completed, true ) ) {
			$completed[] = $lesson_id;
			update_user_meta( $user_id, '_clms_completed_lessons', array_values( array_unique( $completed ) ) );
		}

		do_action( 'clms_lesson_completed', $user_id, $lesson_id );

		return rest_ensure_response(
			array(
				'success'   => true,
				'user_id'   => $user_id,
				'lesson_id' => $lesson_id,
				'completed' => true,
			)
		);
	}

}
