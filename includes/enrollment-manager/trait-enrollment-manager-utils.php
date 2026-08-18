<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Enrollment_Manager_Utils_Trait {
	private function generate_token( $length = 32 ) {
		return substr( str_replace( array( '+', '/', '=' ), array( 'a', 'b', 'c' ), base64_encode( random_bytes( $length ) ) ), 0, $length );
	}

	/**
	 * Construye la URL de unirse con un token de invitación.
	 */
	public function get_join_url( $token, $type = 'access' ) {
		$param = ( 'invite' === $type ) ? 'clms_invite' : 'clms_access';
		// Auto-detectar tipo desde el token.
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT access_mode FROM {$this->table()} WHERE token = %s LIMIT 1", $token ) );
		if ( $row && $row->access_mode === 'invite' ) {
			$param = 'clms_invite';
		}
		return add_query_arg( $param, rawurlencode( $token ), home_url( '/' ) );
	}

	/**
	 * Obtiene los estudiantes matriculados en un curso con sus datos.
	 */
	public function get_enrolled_students( $course_id ) {
		$course_id = absint( $course_id );

		if ( ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}

		$user_ids = (array) get_post_meta( $course_id, CLMS_Helper::COURSE_ENROLLED_META, true );
		$students = array();

		foreach ( array_filter( $user_ids ) as $uid ) {
			$user = get_userdata( absint( $uid ) );
			if ( $user ) {
				$students[] = array(
					'id'           => $user->ID,
					'email'        => $user->user_email,
					'display_name' => $user->display_name,
					'login'        => $user->user_login,
				);
			}
		}

		return $students;
	}

	/**
	 * Envía credenciales al nuevo usuario creado mediante CSV o matrícula manual.
	 */
	private function send_welcome_email( $user, $password, $course_id = 0 ) {
		$course_title = $course_id ? get_the_title( $course_id ) : '';
		$site_name    = get_bloginfo( 'name' );
		$login_url    = wp_login_url();

		$subject  = sprintf( 'Tu cuenta en %s', $site_name );

		$message  = '<p>Hola ' . esc_html( $user->display_name ) . ',</p>';
		$message .= '<p>Se ha creado una cuenta para ti en <strong>' . esc_html( $site_name ) . '</strong>.</p>';
		$message .= '<p><strong>Usuario:</strong> ' . esc_html( $user->user_login ) . '<br>'
			. '<strong>Contraseña temporal:</strong> ' . esc_html( $password ) . '</p>';

		if ( $course_title ) {
			$message .= '<p>Ya estás matriculado/a en el curso <strong>' . esc_html( $course_title ) . '</strong>.</p>';
		}

		$message .= '<p>Te recomendamos cambiar tu contraseña al iniciar sesión por primera vez.</p>';

		CLMS_Email::send(
			$user->user_email,
			$subject,
			$message,
			array(
				'headline'    => '¡Bienvenido/a!',
				'button_text' => 'Iniciar sesión',
				'button_url'  => $login_url,
			)
		);
	}

	/**
	 * Envía confirmación de matrícula a usuarios existentes importados por CSV.
	 *
	 * @param WP_User|false $user      Usuario.
	 * @param int           $course_id Curso matriculado.
	 * @return bool
	 */
	private function send_existing_enrollment_email( $user, $course_id = 0 ) {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		$course_id = absint( $course_id );
		if ( $course_id <= 0 ) {
			return false;
		}

		$course_title = get_the_title( $course_id );
		$course_url   = get_permalink( $course_id );
		$subject      = sprintf(
			/* translators: %s: título del curso */
			__( 'Matrícula confirmada: %s', 'atora-lms' ),
			$course_title ? $course_title : __( 'Tu curso', 'atora-lms' )
		);
		$message      = sprintf(
			/* translators: %s: nombre del usuario */
			__( 'Hola %s,', 'atora-lms' ),
			esc_html( $user->display_name )
		);
		$message     .= '<br><br>' . sprintf(
			/* translators: %s: título del curso */
			__( 'Te confirmamos que has sido matriculado/a en %s.', 'atora-lms' ),
			'<strong>' . esc_html( $course_title ) . '</strong>'
		);
		$message     .= '<br><br>' . __( 'Puedes acceder desde tu panel de estudiante.', 'atora-lms' );

		return CLMS_Email::send(
			$user->user_email,
			$subject,
			$message,
			array(
				'headline'    => __( 'Matrícula confirmada', 'atora-lms' ),
				'button_text' => __( 'Ir al curso', 'atora-lms' ),
				'button_url'  => $course_url ? $course_url : wp_login_url(),
			)
		);
	}

	/**
	 * Log interno — nunca registra contraseñas.
	 *
	 * @param string $message Mensaje a registrar.
	 * @return void
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// Ofuscar cualquier patrón "password: valor" antes de escribir en el log.
			$message = preg_replace(
				'/password[:\s]+[\w\d!@#$%^&*()_+\-=\[\]{};\'"\\|,.<>\/?`~]+/i',
				'password: [REDACTED]',
				(string) $message
			);

			error_log( '[CLMS_EM] ' . $message );
		}
	}
}
