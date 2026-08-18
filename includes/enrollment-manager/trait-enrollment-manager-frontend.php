<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Enrollment_Manager_Frontend_Trait {
	public function register_query_vars( $vars ) {
		$vars[] = 'clms_invite';
		$vars[] = 'clms_access';
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$vars = (array) CLMS_Helper::modular_apply( 'enrollment_query_vars', $vars );
		}
		return $vars;
	}

	/**
	 * Intercepta las URLs de matrícula antes de cargar cualquier template.
	 */
	public function handle_enrollment_redirect() {
		$invite = get_query_var( 'clms_invite' );
		$access = get_query_var( 'clms_access' );

		if ( $invite ) {
			$this->process_invite_page( sanitize_text_field( $invite ) );
		} elseif ( $access ) {
			$this->process_access_page( sanitize_text_field( $access ) );
		}
	}

	/**
	 * Procesa la página de canje de invitación.
	 */
	private function process_invite_page( $token ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE token = %s AND access_mode = 'invite'",
				$token
			)
		);

		if ( ! $row ) {
			$this->render_enrollment_page( 'error', array( 'message' => __( 'La invitación no existe o no es válida.', 'atora-lms' ) ) );
			exit;
		}

		$target         = $this->resolve_invitation_target_from_row( $row );
		$resource_id    = (int) $target['id'];
		$resource_title = (string) $target['title'];
		$resource_label = (string) $target['label'];
		$resource_url   = $resource_id > 0 ? get_permalink( $resource_id ) : '';
		$invite_email   = sanitize_email( (string) ( $row->invited_email ?? '' ) );

		// Si no está logueado, redirigir al login con el token de vuelta.
		if ( ! is_user_logged_in() ) {
			$return_url = add_query_arg( 'clms_invite', rawurlencode( $token ), home_url( '/' ) );

			if ( 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_POST['clms_invite_register'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$signup = $this->handle_invitation_registration_submission( $token, $row );

				if ( is_wp_error( $signup ) ) {
					$this->render_enrollment_page(
						'invite_register',
						array(
							'course_title'   => $resource_title,
							'resource_label' => $resource_label,
							'token'          => $token,
							'message'        => $signup->get_error_message(),
							'login_url'      => wp_login_url( $return_url ),
							'form_values'    => $this->get_invitation_registration_form_values_from_request( $invite_email ),
							'invite_email'   => $invite_email,
						)
					);
					exit;
				}

				$new_user_id = isset( $signup['user_id'] ) ? absint( $signup['user_id'] ) : 0;
				if ( ! $new_user_id ) {
					$this->render_enrollment_page(
						'error',
						array(
							'message'        => __( 'No se pudo crear la cuenta invitada.', 'atora-lms' ),
							'course_title'   => $resource_title,
							'resource_label' => $resource_label,
						)
					);
					exit;
				}

				wp_set_current_user( $new_user_id );
				wp_set_auth_cookie( $new_user_id, true );

				$result = $this->redeem_invitation( $token, $new_user_id );
				if ( is_wp_error( $result ) ) {
					$this->render_enrollment_page(
						'error',
						array(
							'message'        => $result->get_error_message(),
							'course_title'   => $resource_title,
							'resource_label' => $resource_label,
						)
					);
					exit;
				}

				$this->render_enrollment_page(
					'success',
					array(
						'course_title'   => $resource_title,
						'course_url'     => $resource_url,
						'resource_label' => $resource_label,
						'action_label'   => sprintf( __( 'Ir al %s', 'atora-lms' ), $resource_label ),
					)
				);
				exit;
			}

			$this->render_enrollment_page(
				'invite_register',
				array(
					'course_title'   => $resource_title,
					'resource_label' => $resource_label,
					'token'          => $token,
					'login_url'      => wp_login_url( $return_url ),
					'form_values'    => $this->get_invitation_registration_form_values_from_request( $invite_email ),
					'invite_email'   => $invite_email,
				)
			);
			exit;
		}

		$result = $this->redeem_invitation( $token, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->render_enrollment_page( 'error', array(
				'message'        => $result->get_error_message(),
				'course_title'   => $resource_title,
				'resource_label' => $resource_label,
			) );
			exit;
		}

		$this->render_enrollment_page( 'success', array(
			'course_title'   => $resource_title,
			'course_url'     => $resource_url,
			'resource_label' => $resource_label,
			'action_label'   => sprintf( __( 'Ir al %s', 'atora-lms' ), $resource_label ),
		) );
		exit;
	}

	/**
	 * Sanitiza los valores del formulario de onboarding por invitación.
	 *
	 * @param string $default_email Email invitado por defecto.
	 * @return array{first_name:string,last_name:string,email:string,phone:string,whatsapp:string,telegram:string,country_code:string,city:string,state:string,sex:string,age:string}
	 */
	private function get_invitation_registration_form_values_from_request( $default_email = '' ) {
		$default_email = sanitize_email( (string) $default_email );
		$post_values   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$email         = isset( $post_values['invite_email'] ) ? sanitize_email( (string) $post_values['invite_email'] ) : $default_email;
		$country       = isset( $post_values['invite_country_code'] ) ? strtoupper( sanitize_text_field( (string) $post_values['invite_country_code'] ) ) : '';
		$country       = preg_replace( '/[^A-Z0-9_]/', '', (string) $country );
		if ( ! is_string( $country ) ) {
			$country = '';
		}
		if ( strlen( $country ) > 5 ) {
			$country = substr( $country, 0, 5 );
		}

		return array(
			'first_name'   => isset( $post_values['invite_first_name'] ) ? sanitize_text_field( (string) $post_values['invite_first_name'] ) : '',
			'last_name'    => isset( $post_values['invite_last_name'] ) ? sanitize_text_field( (string) $post_values['invite_last_name'] ) : '',
			'email'        => $email,
			'phone'        => isset( $post_values['invite_phone'] ) ? sanitize_text_field( (string) $post_values['invite_phone'] ) : '',
			'whatsapp'     => isset( $post_values['invite_whatsapp'] ) ? sanitize_text_field( (string) $post_values['invite_whatsapp'] ) : '',
			'telegram'     => isset( $post_values['invite_telegram'] ) ? sanitize_text_field( (string) $post_values['invite_telegram'] ) : '',
			'country_code' => $country,
			'city'         => isset( $post_values['invite_city'] ) ? sanitize_text_field( (string) $post_values['invite_city'] ) : '',
			'state'        => isset( $post_values['invite_state'] ) ? sanitize_text_field( (string) $post_values['invite_state'] ) : '',
			'sex'          => isset( $post_values['invite_sex'] ) ? sanitize_key( (string) $post_values['invite_sex'] ) : '',
			'age'          => isset( $post_values['invite_age'] ) ? (string) absint( $post_values['invite_age'] ) : '',
		);
	}

	/**
	 * Crea la cuenta del estudiante desde el formulario de invitación.
	 *
	 * @param string $token Token de invitación.
	 * @param object $row   Fila de invitación.
	 * @return array|WP_Error
	 */
	private function handle_invitation_registration_submission( $token, $row ) {
		$token = sanitize_text_field( (string) $token );
		if ( '' === $token ) {
			return new WP_Error( 'invalid_invite_token', __( 'Invitación inválida.', 'atora-lms' ) );
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'clms_invite_register_' . $token ) ) {
			return new WP_Error( 'invalid_nonce', __( 'La sesión de registro expiró. Vuelve a intentarlo.', 'atora-lms' ) );
		}

		$first_name       = isset( $_POST['invite_first_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['invite_first_name'] ) ) : '';
		$last_name        = isset( $_POST['invite_last_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['invite_last_name'] ) ) : '';
		$email            = isset( $_POST['invite_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['invite_email'] ) ) : '';
		$password         = isset( $_POST['invite_password'] ) ? (string) wp_unslash( $_POST['invite_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$password_confirm = isset( $_POST['invite_password_confirm'] ) ? (string) wp_unslash( $_POST['invite_password_confirm'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$invited_email    = sanitize_email( (string) ( $row->invited_email ?? '' ) );
		$contact_data     = $this->sanitize_student_profile_data(
			array(
				'phone'      => isset( $_POST['invite_phone'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['invite_phone'] ) ) : '',
				'whatsapp'   => isset( $_POST['invite_whatsapp'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['invite_whatsapp'] ) ) : '',
				'telegram'   => isset( $_POST['invite_telegram'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['invite_telegram'] ) ) : '',
				'country'    => isset( $_POST['invite_country_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['invite_country_code'] ) ) : '',
				'city'       => isset( $_POST['invite_city'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['invite_city'] ) ) : '',
				'state'      => isset( $_POST['invite_state'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['invite_state'] ) ) : '',
				'sex'        => isset( $_POST['invite_sex'] ) ? sanitize_key( wp_unslash( (string) $_POST['invite_sex'] ) ) : '',
				'age'        => isset( $_POST['invite_age'] ) ? absint( wp_unslash( (string) $_POST['invite_age'] ) ) : 0,
				'first_name' => $first_name,
				'last_name'  => $last_name,
			)
		);

		if ( '' === $first_name || '' === $last_name ) {
			return new WP_Error( 'invalid_name', __( 'Completa nombre y apellido para continuar.', 'atora-lms' ) );
		}
		if ( ! $email || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Introduce un email válido.', 'atora-lms' ) );
		}
		if ( $invited_email && strtolower( $email ) !== strtolower( $invited_email ) ) {
			return new WP_Error( 'invite_email_mismatch', __( 'Este enlace está asociado a otro email invitado.', 'atora-lms' ) );
		}
		if ( strlen( $password ) < 8 ) {
			return new WP_Error( 'invalid_password_length', __( 'La contraseña debe tener al menos 8 caracteres.', 'atora-lms' ) );
		}
		if ( $password !== $password_confirm ) {
			return new WP_Error( 'password_mismatch', __( 'La confirmación de contraseña no coincide.', 'atora-lms' ) );
		}
		if ( empty( $contact_data['country_code'] ) ) {
			return new WP_Error( 'invalid_country_code', __( 'Selecciona un país para continuar.', 'atora-lms' ) );
		}
		if ( empty( $contact_data['city'] ) ) {
			return new WP_Error( 'invalid_city', __( 'Completa la ciudad para continuar.', 'atora-lms' ) );
		}
		if ( empty( $contact_data['phone'] ) ) {
			return new WP_Error( 'invalid_phone', __( 'Indica un teléfono o WhatsApp para continuar.', 'atora-lms' ) );
		}

		if ( class_exists( '\ATORA\Security\Extended_Registration' ) && method_exists( '\ATORA\Security\Extended_Registration', 'is_valid_phone' ) ) {
			if ( ! empty( $contact_data['phone'] ) && ! \ATORA\Security\Extended_Registration::is_valid_phone( (string) $contact_data['phone'] ) ) {
				return new WP_Error( 'invalid_phone_format', __( 'El teléfono debe estar en formato internacional (ej: +58 412 1234567).', 'atora-lms' ) );
			}
			if ( ! empty( $contact_data['whatsapp'] ) && ! \ATORA\Security\Extended_Registration::is_valid_phone( (string) $contact_data['whatsapp'] ) ) {
				return new WP_Error( 'invalid_whatsapp_format', __( 'El WhatsApp debe estar en formato internacional (ej: +58 412 1234567).', 'atora-lms' ) );
			}
		}

		$existing_user = get_user_by( 'email', $email );
		if ( $existing_user && ! empty( $existing_user->ID ) ) {
			return new WP_Error( 'user_exists', __( 'Ya existe una cuenta con este email. Inicia sesión para canjear tu invitación.', 'atora-lms' ) );
		}

		$base_login = sanitize_user( current( explode( '@', $email ) ), true );
		if ( '' === $base_login ) {
			$base_login = 'estudiante';
		}
		$login = $base_login;
		$idx   = 1;
		while ( username_exists( $login ) ) {
			$idx++;
			$login = $base_login . $idx;
		}

		$user_id = wp_create_user( $login, $password, $email );
		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'user_create_failed', __( 'No pudimos crear tu cuenta en este momento.', 'atora-lms' ) );
		}

		wp_update_user(
			array(
				'ID'           => (int) $user_id,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim( $first_name . ' ' . $last_name ),
				'nickname'     => trim( $first_name . ' ' . $last_name ),
			)
		);

		$user = get_userdata( (int) $user_id );
		if ( $user && is_object( $user ) ) {
			$role = get_role( 'lms_student' ) ? 'lms_student' : 'subscriber';
			$user->set_role( $role );
		}
		$this->persist_student_profile_data(
			(int) $user_id,
			array_merge(
				$contact_data,
				array(
					'first_name' => $first_name,
					'last_name'  => $last_name,
				)
			)
		);

		return array(
			'user_id' => (int) $user_id,
		);
	}

	/**
	 * Procesa la página de canje de enlace de acceso.
	 */
	private function process_access_page( $token ) {
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
			$this->render_enrollment_page( 'error', array( 'message' => __( 'El enlace no existe o ha sido desactivado.', 'atora-lms' ) ) );
			exit;
		}

		$course_id    = (int) $row->course_id;
		$course_title = get_the_title( $course_id );

		// Modo register: si no está logueado, forzar registro.
		if ( $row->access_mode === self::LINK_REGISTER && ! is_user_logged_in() ) {
			$return_url   = add_query_arg( 'clms_access', rawurlencode( $token ), home_url( '/' ) );
			$register_url = add_query_arg( 'redirect_to', rawurlencode( $return_url ), wp_registration_url() );

			$this->render_enrollment_page( 'register_required', array(
				'course_title' => $course_title,
				'register_url' => $register_url,
				'login_url'    => wp_login_url( $return_url ),
			) );
			exit;
		}

		// Modo free/register: si está logueado, matricular directamente.
		if ( in_array( $row->access_mode, array( self::LINK_FREE, self::LINK_REGISTER ), true ) && is_user_logged_in() ) {
			$result = $this->redeem_access_link( $token, get_current_user_id() );

			if ( is_wp_error( $result ) ) {
				$this->render_enrollment_page( 'error', array(
					'message'      => $result->get_error_message(),
					'course_title' => $course_title,
				) );
				exit;
			}

			$this->render_enrollment_page( 'success', array(
				'course_title' => $course_title,
				'course_url'   => get_permalink( $course_id ),
			) );
			exit;
		}

		// Modo free pero no logueado: pedir login.
		if ( $row->access_mode === self::LINK_FREE && ! is_user_logged_in() ) {
			$return_url = add_query_arg( 'clms_access', rawurlencode( $token ), home_url( '/' ) );

			$this->render_enrollment_page( 'login_required', array(
				'course_title' => $course_title,
				'login_url'    => wp_login_url( $return_url ),
				'register_url' => wp_registration_url(),
			) );
			exit;
		}

		// Modo password: mostrar formulario de contraseña.
		if ( $row->access_mode === self::LINK_PASSWORD ) {
			$this->render_enrollment_page( 'password_required', array(
				'course_title' => $course_title,
				'token'        => $token,
				'logged_in'    => is_user_logged_in(),
				'login_url'    => wp_login_url( add_query_arg( 'clms_access', rawurlencode( $token ), home_url( '/' ) ) ),
			) );
			exit;
		}
	}

	/**
	 * Renderiza una página de matriculación in-buffer (sin template del tema).
	 */
	private function render_enrollment_page( $type, $data ) {
		$course_title   = ! empty( $data['course_title'] ) ? esc_html( $data['course_title'] ) : '';
		$message        = ! empty( $data['message'] ) ? esc_html( $data['message'] ) : '';
		$course_url     = ! empty( $data['course_url'] ) ? esc_url( $data['course_url'] ) : '';
		$login_url      = ! empty( $data['login_url'] ) ? esc_url( $data['login_url'] ) : wp_login_url();
		$register_url   = ! empty( $data['register_url'] ) ? esc_url( $data['register_url'] ) : wp_registration_url();
		$token          = ! empty( $data['token'] ) ? esc_attr( $data['token'] ) : '';
		$resource_label = ! empty( $data['resource_label'] ) ? esc_html( (string) $data['resource_label'] ) : esc_html__( 'curso', 'atora-lms' );
		$action_label   = ! empty( $data['action_label'] ) ? esc_html( (string) $data['action_label'] ) : esc_html__( 'Ir al curso', 'atora-lms' );
		$form_values    = isset( $data['form_values'] ) && is_array( $data['form_values'] ) ? $data['form_values'] : array();
		$invite_email   = ! empty( $data['invite_email'] ) ? sanitize_email( (string) $data['invite_email'] ) : '';
		$first_name     = isset( $form_values['first_name'] ) ? esc_attr( (string) $form_values['first_name'] ) : '';
		$last_name      = isset( $form_values['last_name'] ) ? esc_attr( (string) $form_values['last_name'] ) : '';
		$email_value    = isset( $form_values['email'] ) ? sanitize_email( (string) $form_values['email'] ) : $invite_email;
		$email_value    = esc_attr( $email_value );
		$phone_value    = isset( $form_values['phone'] ) ? esc_attr( (string) $form_values['phone'] ) : '';
		$whatsapp_value = isset( $form_values['whatsapp'] ) ? esc_attr( (string) $form_values['whatsapp'] ) : '';
		$telegram_value = isset( $form_values['telegram'] ) ? esc_attr( (string) $form_values['telegram'] ) : '';
		$country_value  = isset( $form_values['country_code'] ) ? esc_attr( (string) $form_values['country_code'] ) : '';
		$city_value     = isset( $form_values['city'] ) ? esc_attr( (string) $form_values['city'] ) : '';
		$state_value    = isset( $form_values['state'] ) ? esc_attr( (string) $form_values['state'] ) : '';
		$sex_value      = isset( $form_values['sex'] ) ? esc_attr( (string) $form_values['sex'] ) : '';
		$age_value      = isset( $form_values['age'] ) ? esc_attr( (string) $form_values['age'] ) : '';
		$countries      = array();
		if ( class_exists( '\ATORA\Security\Extended_Registration' ) && method_exists( '\ATORA\Security\Extended_Registration', 'get_countries' ) ) {
			$countries = (array) \ATORA\Security\Extended_Registration::get_countries();
		}

		status_header( 200 );
		nocache_headers();

		get_header();

		echo '<div id="clms-enroll-wrap" style="max-width:560px;margin:60px auto;padding:0 20px;font-family:system-ui,sans-serif;">';

		switch ( $type ) {
			case 'invite_register':
				echo '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:26px;">';
				echo '<h2 style="margin:0 0 10px;color:#1d4ed8;">Completa tu perfil para continuar</h2>';
				if ( $course_title ) {
					echo '<p style="margin:0 0 14px;color:#334155;">Has sido invitado/a al ' . $resource_label . ' <strong>' . $course_title . '</strong>.</p>';
				}
				if ( $message ) {
					echo '<p style="margin:0 0 14px;padding:10px 12px;border-radius:8px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;">' . $message . '</p>';
				}
				echo '<form method="post" style="display:grid;gap:12px">';
				echo '<input type="hidden" name="clms_invite_register" value="1">';
				echo wp_nonce_field( 'clms_invite_register_' . $token, '_wpnonce', true, false );
				echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
				echo '<label style="display:grid;gap:4px;font-size:13px;color:#334155;"><span>Nombre</span><input type="text" name="invite_first_name" value="' . $first_name . '" required style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;"></label>';
				echo '<label style="display:grid;gap:4px;font-size:13px;color:#334155;"><span>Apellido</span><input type="text" name="invite_last_name" value="' . $last_name . '" required style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;"></label>';
				echo '</div>';
				echo '<label style="display:grid;gap:4px;font-size:13px;color:#334155;"><span>Email</span><input type="email" name="invite_email" value="' . $email_value . '" ' . ( $invite_email ? 'readonly' : '' ) . ' required style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;background:' . ( $invite_email ? '#f8fafc' : '#fff' ) . ';"></label>';
				echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
				if ( ! empty( $countries ) ) {
					echo '<label style="display:grid;gap:4px;font-size:13px;color:#334155;"><span>País</span><select name="invite_country_code" required style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;">';
					echo '<option value="">' . esc_html__( 'Selecciona país', 'atora-lms' ) . '</option>';
					foreach ( $countries as $country_code => $country_name ) {
						echo '<option value="' . esc_attr( (string) $country_code ) . '"' . selected( $country_value, (string) $country_code, false ) . '>' . esc_html( (string) $country_name ) . '</option>';
					}
					echo '</select></label>';
				} else {
					echo '<label style="display:grid;gap:4px;font-size:13px;color:#334155;"><span>País (ISO)</span><input type="text" name="invite_country_code" value="' . $country_value . '" required maxlength="5" style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;"></label>';
				}
				echo '<label style="display:grid;gap:4px;font-size:13px;color:#334155;"><span>Ciudad</span><input type="text" name="invite_city" value="' . $city_value . '" required style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;"></label>';
				echo '</div>';
				echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
				echo '<label style="display:grid;gap:4px;font-size:13px;color:#334155;"><span>Teléfono</span><input type="tel" name="invite_phone" value="' . $phone_value . '" required placeholder="+58 412 1234567" style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;"></label>';
				echo '<label style="display:grid;gap:4px;font-size:13px;color:#334155;"><span>WhatsApp (opcional)</span><input type="tel" name="invite_whatsapp" value="' . $whatsapp_value . '" placeholder="+58 412 1234567" style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;"></label>';
				echo '</div>';
				echo '<label style="display:grid;gap:4px;font-size:13px;color:#334155;"><span>Telegram (opcional)</span><input type="text" name="invite_telegram" value="' . $telegram_value . '" placeholder="@usuario" style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;"></label>';
				echo '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">';
				echo '<label style="display:grid;gap:4px;font-size:13px;color:#334155;"><span>Estado</span><input type="text" name="invite_state" value="' . $state_value . '" placeholder="Estado / Provincia" style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;"></label>';
				echo '<label style="display:grid;gap:4px;font-size:13px;color:#334155;"><span>Sexo</span><select name="invite_sex" style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;">';
				echo '<option value="">' . esc_html__( 'Seleccionar', 'atora-lms' ) . '</option>';
				echo '<option value="femenino"' . selected( $sex_value, 'femenino', false ) . '>' . esc_html__( 'Femenino', 'atora-lms' ) . '</option>';
				echo '<option value="masculino"' . selected( $sex_value, 'masculino', false ) . '>' . esc_html__( 'Masculino', 'atora-lms' ) . '</option>';
				echo '<option value="no_binario"' . selected( $sex_value, 'no_binario', false ) . '>' . esc_html__( 'No binario', 'atora-lms' ) . '</option>';
				echo '<option value="prefiero_no_decir"' . selected( $sex_value, 'prefiero_no_decir', false ) . '>' . esc_html__( 'Prefiero no decir', 'atora-lms' ) . '</option>';
				echo '</select></label>';
				echo '<label style="display:grid;gap:4px;font-size:13px;color:#334155;"><span>Edad</span><input type="number" min="1" max="120" name="invite_age" value="' . $age_value . '" placeholder="Edad" style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;"></label>';
				echo '</div>';
				echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';
				echo '<label style="display:grid;gap:4px;font-size:13px;color:#334155;"><span>Contraseña</span><input type="password" name="invite_password" minlength="8" required style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;"></label>';
				echo '<label style="display:grid;gap:4px;font-size:13px;color:#334155;"><span>Confirmar contraseña</span><input type="password" name="invite_password_confirm" minlength="8" required style="padding:10px;border:1px solid #cbd5e1;border-radius:8px;"></label>';
				echo '</div>';
				echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">';
				echo '<button type="submit" style="background:#2563eb;color:#fff;padding:11px 20px;border:none;border-radius:8px;cursor:pointer;font-weight:600;">Crear perfil y acceder</button>';
				echo '<a href="' . $login_url . '" style="padding:11px 16px;border-radius:8px;text-decoration:none;background:#e2e8f0;color:#0f172a;">Ya tengo cuenta</a>';
				echo '</div>';
				echo '</form>';
				echo '</div>';
				break;

			case 'success':
				echo '<div style="background:#ecfdf5;border:1px solid #6ee7b7;border-radius:10px;padding:30px;text-align:center;">';
				echo '<div style="font-size:48px;">🎓</div>';
				echo '<h2 style="color:#065f46;margin:10px 0 6px;">¡Matriculación exitosa!</h2>';
				if ( $course_title ) {
					echo '<p style="color:#374151;">Ahora tienes acceso al ' . $resource_label . ' <strong>' . $course_title . '</strong>.</p>';
				}
				if ( $course_url ) {
					echo '<a href="' . $course_url . '" style="display:inline-block;margin-top:16px;background:#059669;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">' . $action_label . '</a>';
				}
				echo '</div>';
				break;

			case 'error':
				echo '<div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:30px;text-align:center;">';
				echo '<div style="font-size:48px;">⚠️</div>';
				echo '<h2 style="color:#991b1b;margin:10px 0 6px;">Acceso no disponible</h2>';
				echo '<p style="color:#374151;">' . $message . '</p>';
				echo '</div>';
				break;

			case 'login_required':
				echo '<div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:10px;padding:30px;text-align:center;">';
				echo '<div style="font-size:48px;">🔑</div>';
				echo '<h2 style="color:#1d4ed8;margin:10px 0 6px;">Inicia sesión para continuar</h2>';
				if ( $course_title ) {
					echo '<p style="color:#374151;">Para acceder al ' . $resource_label . ' <strong>' . $course_title . '</strong> necesitas una cuenta.</p>';
				}
				echo '<a href="' . $login_url . '" style="display:inline-block;margin:12px 8px 0;background:#2563eb;color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;">Iniciar sesión</a>';
				echo '<a href="' . $register_url . '" style="display:inline-block;margin:12px 8px 0;background:#e5e7eb;color:#111;padding:11px 22px;border-radius:8px;text-decoration:none;">Crear cuenta</a>';
				echo '</div>';
				break;

			case 'register_required':
				echo '<div style="background:#eff6ff;border:1px solid #93c5fd;border-radius:10px;padding:30px;text-align:center;">';
				echo '<div style="font-size:48px;">📝</div>';
				echo '<h2 style="color:#1d4ed8;margin:10px 0 6px;">Regístrate para acceder</h2>';
				if ( $course_title ) {
					echo '<p style="color:#374151;">Crea tu cuenta para acceder al ' . $resource_label . ' <strong>' . $course_title . '</strong>.</p>';
				}
				echo '<a href="' . $register_url . '" style="display:inline-block;margin:12px 8px 0;background:#2563eb;color:#fff;padding:11px 22px;border-radius:8px;text-decoration:none;">Crear cuenta</a>';
				echo '<a href="' . $login_url . '" style="display:inline-block;margin:12px 8px 0;background:#e5e7eb;color:#111;padding:11px 22px;border-radius:8px;text-decoration:none;">Ya tengo cuenta</a>';
				echo '</div>';
				break;

			case 'password_required':
				$logged_in = ! empty( $data['logged_in'] );
				echo '<div style="background:#fdfae8;border:1px solid #fbbf24;border-radius:10px;padding:30px;text-align:center;">';
				echo '<div style="font-size:48px;">🔐</div>';
				echo '<h2 style="color:#92400e;margin:10px 0 6px;">Acceso con código</h2>';
				if ( $course_title ) {
					echo '<p style="color:#374151;">Introduce el código de acceso para el ' . $resource_label . ' <strong>' . $course_title . '</strong>.</p>';
				}
				if ( ! $logged_in ) {
					echo '<p style="color:#6b7280;font-size:13px;">También puedes <a href="' . $login_url . '">iniciar sesión</a> primero.</p>';
				}
				echo '<form id="clms-pw-form" style="margin-top:16px;">';
				echo '<input type="hidden" name="clms_token" value="' . $token . '">';
				echo '<input type="text" id="clms-pw-input" placeholder="Código de acceso" style="width:200px;padding:10px;border:1px solid #d1d5db;border-radius:6px;margin-right:8px;">';
				echo wp_nonce_field( 'clms_join_password', '_wpnonce', true, false );
				echo '<button type="submit" style="background:#d97706;color:#fff;padding:10px 20px;border:none;border-radius:6px;cursor:pointer;font-weight:600;">Acceder</button>';
				echo '</form>';
				echo '<p id="clms-pw-msg" style="color:#b91c1c;margin-top:10px;display:none;"></p>';
				echo '<script>
				document.getElementById("clms-pw-form").addEventListener("submit", function(e){
					e.preventDefault();
					var pw = document.getElementById("clms-pw-input").value;
					var nonce = document.querySelector("[name=_wpnonce]").value;
					var token = document.querySelector("[name=clms_token]").value;
					fetch("' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", {
						method: "POST",
						headers: {"Content-Type":"application/x-www-form-urlencoded"},
						body: "action=clms_em_join_password&token="+encodeURIComponent(token)+"&password="+encodeURIComponent(pw)+"&_wpnonce="+encodeURIComponent(nonce)
					}).then(r=>r.json()).then(function(d){
						if(d.success){
							window.location.href = d.data.redirect;
						} else {
							var msg = document.getElementById("clms-pw-msg");
							msg.textContent = d.data.message || "Código incorrecto.";
							msg.style.display = "block";
						}
					});
				});
				</script>';
				echo '</div>';
				break;
		}

		echo '</div>';

		get_footer();
	}

	// =========================================================================
	// AJAX HANDLERS
	// =========================================================================

	/**
	 * Autoriza operación sensible de matrícula/acceso en contexto de curso.
	 *
	 * @param int    $course_id ID del curso.
	 * @param string $scope     enrollment|access.
	 * @return void
	 */
}
