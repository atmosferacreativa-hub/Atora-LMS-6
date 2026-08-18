<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Enrollment_Manager_Ajax_Trait {
	private function authorize_course_context_or_die( $course_id, $scope = 'enrollment' ) {
		$course_id = absint( $course_id );

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			wp_send_json_error( CLMS_Access::enrollment_context_error(), 400 );
		}

		$scope = ( 'access' === $scope ) ? 'access' : 'enrollment';
		$allowed = CLMS_Access::can_manage_resource_context( $course_id, $scope, 'course' );

		if ( ! $allowed ) {
			wp_send_json_error( CLMS_Access::enrollment_permission_error(), 403 );
		}
	}

	/**
	 * Autoriza operación sensible de matrícula/acceso en contexto de programa.
	 *
	 * @param int    $program_id ID del programa.
	 * @param string $scope      enrollment|access.
	 * @return void
	 */
	private function authorize_program_context_or_die( $program_id, $scope = 'enrollment' ) {
		$program_id = absint( $program_id );

		if ( ! $program_id || 'lm_program' !== get_post_type( $program_id ) ) {
			wp_send_json_error( CLMS_Access::enrollment_context_error(), 400 );
		}

		$scope = ( 'access' === $scope ) ? 'access' : 'enrollment';
		$allowed = CLMS_Access::can_manage_resource_context( $program_id, $scope, 'program' );

		if ( ! $allowed ) {
			wp_send_json_error( CLMS_Access::enrollment_permission_error(), 403 );
		}
	}

	/**
	 * Valida nonce de invitaciones para curso/programa.
	 *
	 * @return void
	 */
	private function verify_invitation_ajax_nonce_or_die() {
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';

		if (
			! wp_verify_nonce( $nonce, 'clms_enrollment_nonce' ) &&
			! wp_verify_nonce( $nonce, 'clms_program_enrollment_nonce' )
		) {
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'atora-lms' ) ), 403 );
		}
	}

	/**
	 * Resuelve recurso asociado a token de invitación.
	 *
	 * @param string $token Token.
	 * @return array{course_id:int,program_id:int}
	 */
	private function get_invitation_resource_from_token( $token ) {
		$token = sanitize_text_field( (string) $token );

		if ( '' === $token ) {
			return array(
				'course_id'  => 0,
				'program_id' => 0,
			);
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT course_id, program_id FROM {$this->table()} WHERE token = %s AND access_mode = 'invite' LIMIT 1",
				$token
			)
		);

		if ( ! $row ) {
			return array(
				'course_id'  => 0,
				'program_id' => 0,
			);
		}

		return array(
			'course_id'  => absint( $row->course_id ?? 0 ),
			'program_id' => absint( $row->program_id ?? 0 ),
		);
	}

	/** Enviar invitación por email. */
	public function ajax_send_invitation() {
		$this->verify_invitation_ajax_nonce_or_die();

		$course_id  = absint( $_POST['course_id'] ?? 0 );
		$program_id = absint( $_POST['program_id'] ?? 0 );

		if ( $program_id > 0 ) {
			$this->authorize_program_context_or_die( $program_id, 'access' );
			$course_id = 0;
		} else {
			$this->authorize_course_context_or_die( $course_id, 'access' );
		}

		$email        = sanitize_email( $_POST['email'] ?? '' );
		$expires_h    = absint( $_POST['expires_hours'] ?? 72 );
		$max_uses     = absint( $_POST['max_uses'] ?? 1 );
		$send_now     = ! empty( $_POST['send_now'] );

		if ( ! $email || ( ! $course_id && ! $program_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Email y recurso son requeridos.', 'atora-lms' ) ) );
		}

		$id = $this->create_invitation( $course_id, $email, get_current_user_id(), $expires_h, $max_uses, $program_id );

		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ) );
		}

		$sent = false;

		if ( $send_now ) {
			$sent = $this->send_invitation_email( $id );
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );
		$target = $this->resolve_invitation_target_from_row( $row );

		wp_send_json_success( array(
			'id'          => $id,
			'token'       => $row->token,
			'url'         => $this->get_join_url( $row->token ),
			'email'       => $email,
			'sent'        => $sent,
			'entity_type' => $target['type'],
			'entity_id'   => $target['id'],
			'message'     => $sent ? __( 'Invitación enviada.', 'atora-lms' ) : __( 'Token generado (no se envió email).', 'atora-lms' ),
		) );
	}

	/** Revocar una invitación. */
	public function ajax_revoke_invitation() {
		$this->verify_invitation_ajax_nonce_or_die();

		$token = sanitize_text_field( $_POST['token'] ?? '' );

		if ( ! $token ) {
			wp_send_json_error( array( 'message' => __( 'Token requerido.', 'atora-lms' ) ) );
		}

		// Validar cap contextual: obtener el recurso del token antes de autorizar.
		$resource = $this->get_invitation_resource_from_token( $token );
		$course_id = (int) ( $resource['course_id'] ?? 0 );
		$program_id = (int) ( $resource['program_id'] ?? 0 );

		if ( ! $course_id && ! $program_id ) {
			wp_send_json_error( array( 'message' => __( 'Token no válido.', 'atora-lms' ) ), 400 );
		}
		if ( $program_id > 0 ) {
			$this->authorize_program_context_or_die( $program_id, 'access' );
		} else {
			$this->authorize_course_context_or_die( $course_id, 'access' );
		}

		$this->revoke_invitation( $token );

		wp_send_json_success( array( 'message' => __( 'Invitación revocada.', 'atora-lms' ) ) );
	}

	/** Regenerar enlace de acceso. */
	public function ajax_regenerate_link() {
		check_ajax_referer( 'clms_enrollment_nonce', 'nonce' );

		$course_id = absint( $_POST['course_id'] ?? 0 );
		$this->authorize_course_context_or_die( $course_id, 'access' );

		$mode     = sanitize_key( $_POST['mode'] ?? self::LINK_FREE );
		$password = sanitize_text_field( $_POST['link_password'] ?? '' );

		if ( ! $course_id ) {
			wp_send_json_error( array( 'message' => __( 'Curso requerido.', 'atora-lms' ) ) );
		}

		$link = $this->create_access_link( $course_id, $mode, $password, get_current_user_id() );

		wp_send_json_success( array(
			'url'     => $link['url'],
			'token'   => $link['token'],
			'mode'    => $link['mode'],
			'message' => __( 'Enlace regenerado.', 'atora-lms' ),
		) );
	}

	/** Matriculación manual por email. */
	public function ajax_enroll_manual() {
		check_ajax_referer( 'clms_enrollment_nonce', 'nonce' );

		$course_id = absint( $_POST['course_id'] ?? 0 );
		$this->authorize_course_context_or_die( $course_id, 'enrollment' );

		$identifier     = sanitize_text_field( $_POST['identifier'] ?? '' );
		$create_missing = ! empty( $_POST['create_missing'] );

		$result = $this->enroll_by_email( $identifier, $course_id, $create_missing );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/** Desmatricular un usuario. */
	public function ajax_unenroll() {
		check_ajax_referer( 'clms_enrollment_nonce', 'nonce' );

		$user_id   = absint( $_POST['user_id'] ?? 0 );
		$course_id = absint( $_POST['course_id'] ?? 0 );
		$this->authorize_course_context_or_die( $course_id, 'enrollment' );

		if ( ! $user_id || ! $course_id ) {
			wp_send_json_error( array( 'message' => __( 'Parámetros requeridos.', 'atora-lms' ) ) );
		}

		$this->unenroll( $user_id, $course_id );

		wp_send_json_success( array( 'message' => __( 'Desmatriculado.', 'atora-lms' ) ) );
	}

	/** Procesar CSV de matriculación masiva. */
	public function ajax_process_csv() {
		check_ajax_referer( 'clms_enrollment_nonce', 'nonce' );

		$course_id      = absint( $_POST['course_id'] ?? 0 );
		$create_missing = ! empty( $_POST['create_missing'] );
		$notify_existing = ! empty( $_POST['notify_existing'] );
		$this->authorize_course_context_or_die( $course_id, 'enrollment' );

		if ( ! $course_id || empty( $_FILES['csv_file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Archivo y curso requeridos.', 'atora-lms' ) ) );
		}

		$file = $_FILES['csv_file'];

		// Validar extensión.
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Solo se permiten archivos CSV o TXT.', 'atora-lms' ) ) );
		}

		$result = $this->process_csv( $file['tmp_name'], $course_id, $create_missing, $notify_existing );

		wp_send_json_success( $result );
	}

	/** Unirse con contraseña (frontend, puede ser no logueado). */
	public function ajax_join_password() {
		check_ajax_referer( 'clms_join_password', '_wpnonce' );

		$token    = sanitize_text_field( $_POST['token'] ?? '' );
		$password = sanitize_text_field( $_POST['password'] ?? '' );

		if ( ! $token || ! $password ) {
			wp_send_json_error( array( 'message' => __( 'Faltan datos.', 'atora-lms' ) ) );
		}

		if ( ! is_user_logged_in() ) {
			// Guardar token en sesión para después del login.
			wp_send_json_error( array(
				'message'   => __( 'Debes iniciar sesión primero.', 'atora-lms' ),
				'login_url' => wp_login_url( add_query_arg( 'clms_access', rawurlencode( $token ), home_url( '/' ) ) ),
			) );
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE token = %s AND access_mode IN (%s, %s, %s) AND status = %s",
				$token,
				self::LINK_FREE,
				self::LINK_PASSWORD,
				self::LINK_REGISTER,
				'active'
			)
		);

		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Enlace no válido.', 'atora-lms' ) ) );
		}

		$result = $this->redeem_access_link( $token, get_current_user_id(), $password );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'redirect' => get_permalink( $row->course_id ),
		) );
	}

	// =========================================================================
	// UTILIDADES
	// =========================================================================

	/**
	 * Genera un token alfanumérico aleatorio.
	 */
}
