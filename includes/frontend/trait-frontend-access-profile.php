<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Frontend_Access_Profile_Trait {
	public function redirect_students_from_admin() {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$role = $this->get_frontend_role_context();

		if ( 'student' !== $role && 'guest' !== $role ) {
			return;
		}

		// Determine redirect destination: profile page, or home as fallback.
		$dest = $this->resolve_frontend_page_url( 'dashboard' );
		if ( ! $dest ) {
			$dest = home_url( '/' );
		}

		wp_safe_redirect( $dest );
		exit;
	}

	/**
	 * Hide the admin bar for students and guests.
	 *
	 * @param bool $show
	 * @return bool
	 */
	public function hide_admin_bar_for_students( $show ) {
		$role = $this->get_frontend_role_context();

		if ( 'student' === $role || 'guest' === $role ) {
			return false;
		}

		return $show;
	}

	// -------------------------------------------------------------------------
	// Frontend page URL resolver
	// -------------------------------------------------------------------------

	/**
	 * Tries to find the URL of a frontend page for a given key.
	 *
	 * Order of resolution:
	 *   1. WordPress option `clms_frontend_pages` (array of page IDs keyed by key)
	 *   2. A published page whose slug matches known candidates for that key
	 *   3. Returns empty string if nothing found
	 *
	 * Keys: 'dashboard', 'profile', 'messages', 'courses'
	 *
	 * @param string $key
	 * @return string URL or empty string
	 */
	protected function resolve_frontend_page_url( $key ) {
		// Check saved option first
		$option = get_option( 'clms_frontend_pages', array() );

		if ( ! empty( $option[ $key ] ) ) {
			$page = get_post( absint( $option[ $key ] ) );
			if ( $page && 'publish' === $page->post_status ) {
				return get_permalink( $page->ID );
			}
		}

		// Try well-known slugs
		$slug_map = array(
			'dashboard' => array( 'mi-panel', 'panel-estudiante', 'panel', 'dashboard' ),
			'profile'   => array( 'mi-perfil', 'perfil', 'profile', 'cuenta' ),
			'messages'  => array( 'mensajes', 'messages', 'bandeja' ),
			'courses'   => array( 'mis-cursos', 'cursos', 'courses' ),
		);

		if ( isset( $slug_map[ $key ] ) ) {
			foreach ( $slug_map[ $key ] as $slug ) {
				$page = get_page_by_path( $slug );
				if ( $page && 'publish' === $page->post_status ) {
					return get_permalink( $page->ID );
				}
			}
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// [clms_my_profile] — Frontend profile editor
	// -------------------------------------------------------------------------

	/**
	 * Renders a clean frontend form to edit the current user's profile.
	 * If the user is not logged in, shows the login form instead.
	 *
	 * @param array $atts
	 * @return string
	 */
	public function shortcode_my_profile( $atts ) {
		if ( ! is_user_logged_in() ) {
			return $this->shortcode_login_form( array() );
		}

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		$errors  = array();
		$success = false;
		$require_notice = ! empty( $_GET['clms_profile_required'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$return_to = wp_validate_redirect(
			(string) wp_unslash( $_GET['return_to'] ?? '' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			''
		);
		$missing_raw = sanitize_text_field( (string) wp_unslash( $_GET['missing'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$missing_keys = array_values( array_filter( array_map( 'sanitize_key', explode( ',', $missing_raw ) ) ) );
		$missing_map = array(
			'first_name'   => __( 'nombre', 'atora-lms' ),
			'last_name'    => __( 'apellido', 'atora-lms' ),
			'user_email'   => __( 'correo', 'atora-lms' ),
			'whatsapp'     => __( 'whatsapp', 'atora-lms' ),
			'country_code' => __( 'país', 'atora-lms' ),
			'state'        => __( 'estado/provincia', 'atora-lms' ),
			'city'         => __( 'ciudad', 'atora-lms' ),
		);
		$missing_labels = array();
		foreach ( $missing_keys as $missing_key ) {
			$missing_labels[] = $missing_map[ $missing_key ] ?? $missing_key;
		}

		// Handle form submission
		if (
			isset( $_POST['clms_profile_nonce'] ) &&
			wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['clms_profile_nonce'] ) ),
				'clms_update_profile_' . $user_id
			)
		) {
			$display_name = isset( $_POST['clms_display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_display_name'] ) ) : '';
			$first_name   = isset( $_POST['clms_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_first_name'] ) ) : '';
			$last_name    = isset( $_POST['clms_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_last_name'] ) ) : '';
			$user_email   = isset( $_POST['clms_user_email'] ) ? sanitize_email( wp_unslash( $_POST['clms_user_email'] ) ) : '';
			$description  = isset( $_POST['clms_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['clms_description'] ) ) : '';
			$user_url     = isset( $_POST['clms_user_url'] ) ? esc_url_raw( wp_unslash( $_POST['clms_user_url'] ) ) : '';
			$pass1        = isset( $_POST['clms_pass1'] ) ? (string) $_POST['clms_pass1'] : '';
			$pass2        = isset( $_POST['clms_pass2'] ) ? (string) $_POST['clms_pass2'] : '';

			$phone        = isset( $_POST['clms_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_phone'] ) ) : '';
			$whatsapp     = isset( $_POST['clms_whatsapp'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_whatsapp'] ) ) : '';
			$telegram     = isset( $_POST['clms_telegram'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_telegram'] ) ) : '';
			$country_code = isset( $_POST['clms_country_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['clms_country_code'] ) ) ) : '';
			$state        = isset( $_POST['clms_state'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_state'] ) ) : '';
			$city         = isset( $_POST['clms_city'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_city'] ) ) : '';
			$sex          = isset( $_POST['clms_sex'] ) ? sanitize_key( wp_unslash( $_POST['clms_sex'] ) ) : '';
			$age_raw      = isset( $_POST['clms_age'] ) ? (string) wp_unslash( $_POST['clms_age'] ) : '';
			$age          = '' !== trim( $age_raw ) ? absint( $age_raw ) : 0;
			$avatar_url   = isset( $_POST['clms_profile_photo_url'] ) ? esc_url_raw( wp_unslash( $_POST['clms_profile_photo_url'] ) ) : '';
			$socials      = array(
				'atora_social_instagram' => isset( $_POST['clms_social_instagram'] ) ? esc_url_raw( wp_unslash( $_POST['clms_social_instagram'] ) ) : '',
				'atora_social_facebook'  => isset( $_POST['clms_social_facebook'] ) ? esc_url_raw( wp_unslash( $_POST['clms_social_facebook'] ) ) : '',
				'atora_social_linkedin'  => isset( $_POST['clms_social_linkedin'] ) ? esc_url_raw( wp_unslash( $_POST['clms_social_linkedin'] ) ) : '',
				'atora_social_twitter'   => isset( $_POST['clms_social_twitter'] ) ? esc_url_raw( wp_unslash( $_POST['clms_social_twitter'] ) ) : '',
				'atora_social_tiktok'    => isset( $_POST['clms_social_tiktok'] ) ? esc_url_raw( wp_unslash( $_POST['clms_social_tiktok'] ) ) : '',
			);

			if ( '' === $first_name ) {
				$errors[] = __( 'El nombre es obligatorio.', 'atora-lms' );
			}
			if ( '' === $last_name ) {
				$errors[] = __( 'El apellido es obligatorio.', 'atora-lms' );
			}
			if ( '' === $user_email || ! is_email( $user_email ) ) {
				$errors[] = __( 'Debes indicar un correo válido.', 'atora-lms' );
			} else {
				$email_owner = email_exists( $user_email );
				if ( $email_owner && absint( $email_owner ) !== $user_id ) {
					$errors[] = __( 'Ese correo ya pertenece a otra cuenta.', 'atora-lms' );
				}
			}
			if ( '' === $whatsapp ) {
				$errors[] = __( 'WhatsApp es obligatorio para iniciar cursos.', 'atora-lms' );
			}
			if ( '' === $country_code || '' === $state || '' === $city ) {
				$errors[] = __( 'Completa país, estado/provincia y ciudad.', 'atora-lms' );
			}
			if ( '' !== $whatsapp && ! $this->is_valid_profile_phone( $whatsapp ) ) {
				$errors[] = __( 'WhatsApp debe tener formato internacional (ej: +58 412 1234567).', 'atora-lms' );
			}
			if ( '' !== $phone && ! $this->is_valid_profile_phone( $phone ) ) {
				$errors[] = __( 'Teléfono no válido. Usa formato internacional (ej: +58 212 1234567).', 'atora-lms' );
			}
			if ( $age > 0 && ( $age < 1 || $age > 120 ) ) {
				$errors[] = __( 'La edad debe estar entre 1 y 120.', 'atora-lms' );
			}
			if ( '' === $phone && '' !== $whatsapp ) {
				$phone = $whatsapp;
			}
			if ( '' === $display_name ) {
				$display_name = trim( $first_name . ' ' . $last_name );
			}

			$update_data = array(
				'ID'           => $user_id,
				'display_name' => $display_name,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'user_email'   => $user_email,
				'user_url'     => $user_url,
				'description'  => $description,
			);

			if ( '' !== $pass1 || '' !== $pass2 ) {
				if ( $pass1 !== $pass2 ) {
					$errors[] = __( 'Las contraseñas no coinciden.', 'atora-lms' );
				} elseif ( strlen( $pass1 ) < 8 ) {
					$errors[] = __( 'La contraseña debe tener al menos 8 caracteres.', 'atora-lms' );
				} else {
					$update_data['user_pass'] = $pass1;
				}
			}

			if ( empty( $errors ) ) {
				$result = wp_update_user( $update_data );
				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				} else {
					// PT-4.3 (6.5.1): pasa por Preferences::update_phone() en
					// vez de update_user_meta() directo — invalida la
					// verificación si el número cambió de verdad.
					if ( class_exists( '\ATORA\Messaging\Preferences' ) ) {
						\ATORA\Messaging\Preferences::update_phone( $user_id, $phone );
					} else {
						update_user_meta( $user_id, 'atora_phone', $phone );
					}
					update_user_meta( $user_id, 'atora_whatsapp', $whatsapp );
					update_user_meta( $user_id, 'atora_telegram', $telegram );
					update_user_meta( $user_id, 'atora_country_code', $country_code );
					update_user_meta( $user_id, 'atora_state', $state );
					update_user_meta( $user_id, 'atora_city', $city );
					update_user_meta( $user_id, 'atora_sex', $sex );
					update_user_meta( $user_id, 'atora_age', $age > 0 ? (string) $age : '' );
					update_user_meta( $user_id, 'atora_profile_photo_url', $avatar_url );
					foreach ( $socials as $social_key => $social_value ) {
						update_user_meta( $user_id, $social_key, $social_value );
					}

					if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'upsert_contact' ) ) {
						\ATORA\CRM\CRM::upsert_contact(
							array(
								'user_id'  => $user_id,
								'name'     => $display_name,
								'email'    => $user_email,
								'phone'    => $phone,
								'whatsapp' => $whatsapp,
								'telegram' => $telegram,
								'country'  => $country_code,
								'state'    => $state,
								'city'     => $city,
								'sex'      => $sex,
								'age'      => $age,
							)
						);
					}

					$success = true;
					$user    = get_userdata( $user_id ); // Refresh after update
					if ( ! empty( $update_data['user_pass'] ) ) {
						wp_set_auth_cookie( $user_id, true ); // Keep logged in after password change
					}

					$completion = $this->get_profile_completion_status( $user_id );
					if ( $completion['complete'] && '' !== $return_to ) {
						wp_safe_redirect( add_query_arg( 'clms_profile_updated', 1, $return_to ) );
						exit;
					}
				}
			}
		}

		$completion_status = $this->get_profile_completion_status( $user_id );
		$avatar_url  = (string) get_user_meta( $user_id, 'atora_profile_photo_url', true );
		if ( '' === trim( $avatar_url ) ) {
			$avatar_url = (string) get_avatar_url( $user_id, array( 'size' => 96 ) );
		}
		$first_name  = get_user_meta( $user_id, 'first_name', true );
		$last_name   = get_user_meta( $user_id, 'last_name', true );
		$description = get_user_meta( $user_id, 'description', true );
		$phone       = sanitize_text_field( (string) get_user_meta( $user_id, 'atora_phone', true ) );
		$whatsapp    = sanitize_text_field( (string) get_user_meta( $user_id, 'atora_whatsapp', true ) );
		$telegram    = sanitize_text_field( (string) get_user_meta( $user_id, 'atora_telegram', true ) );
		$country     = sanitize_text_field( (string) get_user_meta( $user_id, 'atora_country_code', true ) );
		$state       = sanitize_text_field( (string) get_user_meta( $user_id, 'atora_state', true ) );
		$city        = sanitize_text_field( (string) get_user_meta( $user_id, 'atora_city', true ) );
		$sex         = sanitize_key( (string) get_user_meta( $user_id, 'atora_sex', true ) );
		$age         = absint( get_user_meta( $user_id, 'atora_age', true ) );
		$user_url    = esc_url_raw( (string) $user->user_url );
		$social_instagram = esc_url_raw( (string) get_user_meta( $user_id, 'atora_social_instagram', true ) );
		$social_facebook  = esc_url_raw( (string) get_user_meta( $user_id, 'atora_social_facebook', true ) );
		$social_linkedin  = esc_url_raw( (string) get_user_meta( $user_id, 'atora_social_linkedin', true ) );
		$social_twitter   = esc_url_raw( (string) get_user_meta( $user_id, 'atora_social_twitter', true ) );
		$social_tiktok    = esc_url_raw( (string) get_user_meta( $user_id, 'atora_social_tiktok', true ) );
		$country_options  = class_exists( '\ATORA\Security\Extended_Registration' ) && method_exists( '\ATORA\Security\Extended_Registration', 'get_countries' )
			? (array) \ATORA\Security\Extended_Registration::get_countries()
			: array();

		ob_start();

		// Enqueue profile form CSS via WP styles to avoid editors or filters
		// escaping <style> tags and rendering the CSS as visible text.
		if ( empty( $GLOBALS['clms_profile_form_css_added'] ) ) {
			$inline_css = <<<'CSS'
.clms-profile-wrap{max-width:560px;margin:0 auto;font-family:inherit}
.clms-profile-avatar-row{display:flex;align-items:center;gap:16px;margin-bottom:24px}
.clms-profile-avatar-row img{width:72px;height:72px;border-radius:50%;object-fit:cover}
.clms-profile-avatar-note{font-size:13px;color:#6b7280}
.clms-profile-avatar-note a{color:#1d4ed8}
.clms-profile-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:28px 24px;margin-bottom:20px}
.clms-profile-card h3{margin:0 0 20px;font-size:16px;font-weight:700;color:#111827;border-bottom:1px solid #f3f4f6;padding-bottom:12px}
.clms-profile-row{margin-bottom:16px}
.clms-profile-row label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px}
.clms-profile-row input[type=text],.clms-profile-row input[type=email],.clms-profile-row input[type=password],.clms-profile-row input[type=tel],.clms-profile-row input[type=url],.clms-profile-row input[type=number],.clms-profile-row select,.clms-profile-row textarea{width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:15px;box-sizing:border-box;background:#fafafa;color:#111827}
.clms-profile-row input[readonly]{background:#f3f4f6;color:#6b7280;cursor:not-allowed}
.clms-profile-row textarea{resize:vertical;min-height:90px}
.clms-profile-cols{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.clms-profile-submit{width:100%;padding:11px;background:#1d4ed8;color:#fff;border:none;border-radius:9px;font-size:15px;font-weight:600;cursor:pointer;margin-top:4px}
.clms-profile-submit:hover{background:#1e40af}
.clms-profile-success{padding:14px 18px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;color:#166534;margin-bottom:20px;font-size:14px}
.clms-profile-warning{padding:14px 18px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;color:#92400e;margin-bottom:20px;font-size:14px}
.clms-profile-errors{padding:14px 18px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;color:#991b1b;margin-bottom:20px;font-size:14px}
.clms-profile-errors ul{margin:6px 0 0 18px;padding:0}
@media(max-width:540px){.clms-profile-cols{grid-template-columns:1fr}}
CSS;

			if ( ! wp_style_is( 'atora-frontend', 'registered' ) ) {
				$css_url = defined( 'ATORA_LMS_URL' ) ? ATORA_LMS_URL . 'assets/css/frontend.css' : '';
				wp_register_style( 'atora-frontend', $css_url, array(), defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0' );
			}
			if ( ! wp_style_is( 'atora-frontend', 'enqueued' ) ) {
				wp_enqueue_style( 'atora-frontend' );
			}

			wp_add_inline_style( 'atora-frontend', $inline_css );
			$GLOBALS['clms_profile_form_css_added'] = true;
		}

		?>
		<div class="clms-profile-wrap">
			<?php if ( $require_notice ) : ?>
				<div class="clms-profile-warning" role="alert" aria-live="assertive">
					<strong><?php esc_html_e( 'Completa tu perfil para iniciar el curso.', 'atora-lms' ); ?></strong>
					<?php if ( ! empty( $missing_labels ) ) : ?>
						<br><?php echo esc_html( sprintf( __( 'Te faltan: %s.', 'atora-lms' ), implode( ', ', $missing_labels ) ) ); ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $completion_status['missing'] ) && ! $require_notice ) : ?>
				<div class="clms-profile-warning" role="status" aria-live="polite">
					<?php esc_html_e( 'Completa los campos obligatorios para poder iniciar cursos.', 'atora-lms' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( $success ) : ?>
				<div class="clms-profile-success" role="status" aria-live="polite"><?php esc_html_e( 'Perfil actualizado correctamente.', 'atora-lms' ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="clms-profile-errors" role="alert" aria-live="assertive" id="clms-profile-errors">
					<strong><?php esc_html_e( 'Corrige los siguientes errores:', 'atora-lms' ); ?></strong>
					<ul>
						<?php foreach ( $errors as $err ) : ?>
							<li><?php echo esc_html( $err ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'clms_update_profile_' . $user_id, 'clms_profile_nonce' ); ?>

				<div class="clms-profile-avatar-row">
					<img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $user->display_name ); ?>">
					<div class="clms-profile-avatar-note">
						<strong><?php echo esc_html( $user->display_name ); ?></strong><br>
						<span><?php echo esc_html( $user->user_email ); ?></span><br>
						<a href="https://gravatar.com" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Cambiar foto en Gravatar.com', 'atora-lms' ); ?>
						</a>
					</div>
				</div>

				<div class="clms-profile-card">
					<h3><?php esc_html_e( 'Datos personales', 'atora-lms' ); ?></h3>

					<div class="clms-profile-cols">
						<div class="clms-profile-row">
							<label for="clms_first_name"><?php esc_html_e( 'Nombre *', 'atora-lms' ); ?></label>
							<input type="text" id="clms_first_name" name="clms_first_name" required value="<?php echo esc_attr( $first_name ); ?>">
						</div>
						<div class="clms-profile-row">
							<label for="clms_last_name"><?php esc_html_e( 'Apellido *', 'atora-lms' ); ?></label>
							<input type="text" id="clms_last_name" name="clms_last_name" required value="<?php echo esc_attr( $last_name ); ?>">
						</div>
					</div>

					<div class="clms-profile-row">
						<label for="clms_display_name"><?php esc_html_e( 'Nombre público', 'atora-lms' ); ?></label>
						<input type="text" id="clms_display_name" name="clms_display_name" value="<?php echo esc_attr( $user->display_name ); ?>">
					</div>

					<div class="clms-profile-row">
						<label for="clms_user_email"><?php esc_html_e( 'Correo electrónico *', 'atora-lms' ); ?></label>
						<input type="email" id="clms_user_email" name="clms_user_email" required value="<?php echo esc_attr( $user->user_email ); ?>">
					</div>

					<div class="clms-profile-cols">
						<div class="clms-profile-row">
							<label for="clms_phone"><?php esc_html_e( 'Teléfono', 'atora-lms' ); ?></label>
							<input type="tel" id="clms_phone" name="clms_phone" value="<?php echo esc_attr( $phone ); ?>" placeholder="+58 212 1234567">
						</div>
						<div class="clms-profile-row">
							<label for="clms_whatsapp"><?php esc_html_e( 'WhatsApp *', 'atora-lms' ); ?></label>
							<input type="tel" id="clms_whatsapp" name="clms_whatsapp" required value="<?php echo esc_attr( $whatsapp ); ?>" placeholder="+58 412 1234567">
						</div>
					</div>

					<div class="clms-profile-cols">
						<div class="clms-profile-row">
							<label for="clms_country_code"><?php esc_html_e( 'País *', 'atora-lms' ); ?></label>
							<?php if ( ! empty( $country_options ) ) : ?>
								<select id="clms_country_code" name="clms_country_code" required>
									<option value=""><?php esc_html_e( 'Selecciona país', 'atora-lms' ); ?></option>
									<?php foreach ( $country_options as $country_key => $country_label ) : ?>
										<option value="<?php echo esc_attr( (string) $country_key ); ?>" <?php selected( strtoupper( (string) $country ), strtoupper( (string) $country_key ) ); ?>><?php echo esc_html( (string) $country_label ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="text" id="clms_country_code" name="clms_country_code" required value="<?php echo esc_attr( $country ); ?>">
							<?php endif; ?>
						</div>
						<div class="clms-profile-row">
							<label for="clms_state"><?php esc_html_e( 'Estado / Provincia *', 'atora-lms' ); ?></label>
							<input type="text" id="clms_state" name="clms_state" required value="<?php echo esc_attr( $state ); ?>">
						</div>
					</div>

					<div class="clms-profile-cols">
						<div class="clms-profile-row">
							<label for="clms_city"><?php esc_html_e( 'Ciudad *', 'atora-lms' ); ?></label>
							<input type="text" id="clms_city" name="clms_city" required value="<?php echo esc_attr( $city ); ?>">
						</div>
						<div class="clms-profile-row">
							<label for="clms_telegram"><?php esc_html_e( 'Telegram', 'atora-lms' ); ?></label>
							<input type="text" id="clms_telegram" name="clms_telegram" value="<?php echo esc_attr( $telegram ); ?>" placeholder="@usuario">
						</div>
					</div>

					<div class="clms-profile-cols">
						<div class="clms-profile-row">
							<label for="clms_sex"><?php esc_html_e( 'Género', 'atora-lms' ); ?></label>
							<select id="clms_sex" name="clms_sex">
								<option value=""><?php esc_html_e( '— Selecciona —', 'atora-lms' ); ?></option>
								<option value="femenino" <?php selected( $sex, 'femenino' ); ?>><?php esc_html_e( 'Femenino', 'atora-lms' ); ?></option>
								<option value="masculino" <?php selected( $sex, 'masculino' ); ?>><?php esc_html_e( 'Masculino', 'atora-lms' ); ?></option>
								<option value="no_binario" <?php selected( $sex, 'no_binario' ); ?>><?php esc_html_e( 'No binario', 'atora-lms' ); ?></option>
								<option value="prefiero_no_decir" <?php selected( $sex, 'prefiero_no_decir' ); ?>><?php esc_html_e( 'Prefiero no decir', 'atora-lms' ); ?></option>
							</select>
						</div>
						<div class="clms-profile-row">
							<label for="clms_age"><?php esc_html_e( 'Edad', 'atora-lms' ); ?></label>
							<input type="number" id="clms_age" name="clms_age" min="1" max="120" value="<?php echo esc_attr( $age > 0 ? (string) $age : '' ); ?>">
						</div>
					</div>

					<div class="clms-profile-row">
						<label for="clms_profile_photo_url"><?php esc_html_e( 'Foto (URL)', 'atora-lms' ); ?></label>
						<input type="url" id="clms_profile_photo_url" name="clms_profile_photo_url" value="<?php echo esc_attr( (string) get_user_meta( $user_id, 'atora_profile_photo_url', true ) ); ?>" placeholder="https://.../foto.jpg">
					</div>

					<div class="clms-profile-row">
						<label for="clms_user_url"><?php esc_html_e( 'Sitio web', 'atora-lms' ); ?></label>
						<input type="url" id="clms_user_url" name="clms_user_url" value="<?php echo esc_attr( $user_url ); ?>" placeholder="https://">
					</div>

					<div class="clms-profile-cols">
						<div class="clms-profile-row">
							<label for="clms_social_instagram"><?php esc_html_e( 'Instagram', 'atora-lms' ); ?></label>
							<input type="url" id="clms_social_instagram" name="clms_social_instagram" value="<?php echo esc_attr( $social_instagram ); ?>" placeholder="https://instagram.com/...">
						</div>
						<div class="clms-profile-row">
							<label for="clms_social_facebook"><?php esc_html_e( 'Facebook', 'atora-lms' ); ?></label>
							<input type="url" id="clms_social_facebook" name="clms_social_facebook" value="<?php echo esc_attr( $social_facebook ); ?>" placeholder="https://facebook.com/...">
						</div>
					</div>

					<div class="clms-profile-cols">
						<div class="clms-profile-row">
							<label for="clms_social_linkedin"><?php esc_html_e( 'LinkedIn', 'atora-lms' ); ?></label>
							<input type="url" id="clms_social_linkedin" name="clms_social_linkedin" value="<?php echo esc_attr( $social_linkedin ); ?>" placeholder="https://linkedin.com/in/...">
						</div>
						<div class="clms-profile-row">
							<label for="clms_social_twitter"><?php esc_html_e( 'X / Twitter', 'atora-lms' ); ?></label>
							<input type="url" id="clms_social_twitter" name="clms_social_twitter" value="<?php echo esc_attr( $social_twitter ); ?>" placeholder="https://x.com/...">
						</div>
					</div>

					<div class="clms-profile-row">
						<label for="clms_social_tiktok"><?php esc_html_e( 'TikTok', 'atora-lms' ); ?></label>
						<input type="url" id="clms_social_tiktok" name="clms_social_tiktok" value="<?php echo esc_attr( $social_tiktok ); ?>" placeholder="https://tiktok.com/@...">
					</div>

					<div class="clms-profile-row">
						<label for="clms_description"><?php esc_html_e( 'Sobre mí', 'atora-lms' ); ?></label>
						<textarea id="clms_description" name="clms_description"><?php echo esc_textarea( $description ); ?></textarea>
					</div>
				</div>

				<div class="clms-profile-card">
					<h3><?php esc_html_e( 'Cambiar contraseña', 'atora-lms' ); ?></h3>
					<div class="clms-profile-row">
						<label for="clms_pass1"><?php esc_html_e( 'Nueva contraseña', 'atora-lms' ); ?></label>
						<input type="password" id="clms_pass1" name="clms_pass1" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Mínimo 8 caracteres', 'atora-lms' ); ?>">
					</div>
					<div class="clms-profile-row">
						<label for="clms_pass2"><?php esc_html_e( 'Confirmar contraseña', 'atora-lms' ); ?></label>
						<input type="password" id="clms_pass2" name="clms_pass2" autocomplete="new-password">
					</div>
				</div>

				<button type="submit" class="clms-profile-submit"><?php esc_html_e( 'Guardar cambios', 'atora-lms' ); ?></button>
			</form>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Exige perfil completo para estudiantes antes de iniciar curso/lección.
	 *
	 * @return void
	 */
	public function enforce_profile_completion_before_learning(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( 'student' !== $this->get_frontend_role_context() ) {
			return;
		}
		if ( ! is_singular( array( 'lm_course', 'lm_lesson' ) ) ) {
			return;
		}

		$profile_status = $this->get_profile_completion_status( get_current_user_id() );
		if ( ! empty( $profile_status['complete'] ) ) {
			return;
		}

		$profile_url = $this->resolve_frontend_page_url( 'profile' );
		if ( '' === $profile_url ) {
			return;
		}

		$current_path = (string) wp_parse_url( home_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ), PHP_URL_PATH );
		$profile_path = (string) wp_parse_url( $profile_url, PHP_URL_PATH );
		if ( '' !== $profile_path && false !== strpos( $current_path, $profile_path ) ) {
			return;
		}

		$redirect_url = add_query_arg(
			array(
				'clms_profile_required' => 1,
				'missing'               => implode( ',', (array) ( $profile_status['missing'] ?? array() ) ),
				'return_to'             => rawurlencode( home_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) ),
			),
			$profile_url
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Determina si el perfil del usuario cumple los mínimos obligatorios.
	 *
	 * @param int $user_id Usuario.
	 * @return array{complete:bool,missing:array<int,string>}
	 */
	protected function get_profile_completion_status( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return array(
				'complete' => false,
				'missing'  => array( 'user' ),
			);
		}

		$required = array(
			'first_name'   => sanitize_text_field( (string) get_user_meta( $user_id, 'first_name', true ) ),
			'last_name'    => sanitize_text_field( (string) get_user_meta( $user_id, 'last_name', true ) ),
			'user_email'   => sanitize_email( (string) $user->user_email ),
			'whatsapp'     => sanitize_text_field( (string) get_user_meta( $user_id, 'atora_whatsapp', true ) ),
			'country_code' => sanitize_text_field( (string) get_user_meta( $user_id, 'atora_country_code', true ) ),
			'state'        => sanitize_text_field( (string) get_user_meta( $user_id, 'atora_state', true ) ),
			'city'         => sanitize_text_field( (string) get_user_meta( $user_id, 'atora_city', true ) ),
		);

		$missing = array();
		foreach ( $required as $key => $value ) {
			if ( '' === trim( (string) $value ) ) {
				$missing[] = $key;
			}
		}
		if ( ! empty( $required['whatsapp'] ) && ! $this->is_valid_profile_phone( (string) $required['whatsapp'] ) ) {
			$missing[] = 'whatsapp';
		}

		$missing = array_values( array_unique( array_map( 'sanitize_key', $missing ) ) );

		return array(
			'complete' => empty( $missing ),
			'missing'  => $missing,
		);
	}

	/**
	 * Valida teléfono/WhatsApp con fallback local.
	 *
	 * @param string $phone Número.
	 * @return bool
	 */
	protected function is_valid_profile_phone( string $phone ): bool {
		$phone = trim( $phone );
		if ( '' === $phone ) {
			return false;
		}

		if ( class_exists( '\ATORA\Security\Extended_Registration' ) && method_exists( '\ATORA\Security\Extended_Registration', 'is_valid_phone' ) ) {
			return (bool) \ATORA\Security\Extended_Registration::is_valid_phone( $phone );
		}

		return (bool) preg_match( '/^\+[\d\s\-\(\)]{7,20}$/', $phone );
	}

	// -------------------------------------------------------------------------
	// Role context
	// -------------------------------------------------------------------------

	/**
	 * Returns a simple role key for the current visitor:
	 * 'guest' | 'student' | 'collaborator' | 'instructor' | 'admin'
	 *
	 * @return string
	 */
	protected function get_frontend_role_context() {
		if ( ! is_user_logged_in() ) {
			return 'guest';
		}

		$user = wp_get_current_user();
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return 'guest';
		}

		return $this->get_frontend_role_context_for_user( $user );
	}

	/**
	 * Calcula el contexto de rol para un usuario específico.
	 *
	 * @param WP_User $user Usuario autenticado.
	 * @return string
	 */
	protected function get_frontend_role_context_for_user( WP_User $user ): string {
		if ( user_can( $user, 'manage_options' ) || user_can( $user, 'clms_access_admin' ) ) {
			return 'admin';
		}

		if (
			user_can( $user, 'clms_view_teacher_dashboard' ) ||
			user_can( $user, 'clms_manage_courses' ) ||
			user_can( $user, 'clms_grade_submissions' )
		) {
			return 'instructor';
		}

		if (
			user_can( $user, 'clms_manage_commerce' ) ||
			user_can( $user, 'clms_manage_enrollments' ) ||
			user_can( $user, 'clms_manage_course_access' ) ||
			user_can( $user, 'clms_manage_enrollment_access' )
		) {
			return 'collaborator';
		}

		return 'student';
	}

	/**
	 * Redirige post-login al hub más útil por rol cuando la URL solicitada es genérica.
	 *
	 * @param string          $redirect_to URL final propuesta por WP.
	 * @param string          $requested   URL solicitada originalmente.
	 * @param WP_User|WP_Error $user       Usuario autenticado.
	 * @return string
	 */
	public function redirect_after_login_by_role( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return (string) $redirect_to;
		}

		$requested = is_string( $requested ) ? trim( $requested ) : '';
		if ( '' !== $requested ) {
			$requested_safe = wp_validate_redirect( $requested, '' );
			if ( '' !== $requested_safe && ! $this->is_generic_login_redirect_target( $requested_safe ) ) {
				return $requested_safe;
			}
		}

		$target = $this->get_login_hub_url_for_user( $user );
		if ( '' !== $target ) {
			return $target;
		}

		return (string) $redirect_to;
	}

	/**
	 * Detecta si el destino solicitado es un backend genérico que conviene reemplazar por hub.
	 *
	 * @param string $url URL solicitada.
	 * @return bool
	 */
	protected function is_generic_login_redirect_target( string $url ): bool {
		$url = wp_validate_redirect( $url, '' );
		if ( '' === $url ) {
			return true;
		}

		$parts = wp_parse_url( $url );
		$path  = (string) ( $parts['path'] ?? '' );
		$query = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$page = sanitize_key( (string) ( $query['page'] ?? '' ) );

		if ( '' !== $page && ! in_array( $page, array( 'clms-dashboard' ), true ) ) {
			return false;
		}

		return false !== strpos( $path, '/wp-admin/' ) || '/wp-admin' === rtrim( $path, '/' );
	}

	/**
	 * Resuelve hub de aterrizaje por rol.
	 *
	 * @param WP_User $user Usuario autenticado.
	 * @return string
	 */
	protected function get_login_hub_url_for_user( WP_User $user ): string {
		$role = $this->get_frontend_role_context_for_user( $user );

		if ( 'admin' === $role || 'instructor' === $role ) {
			return admin_url( 'admin.php?page=clms-dashboard' );
		}

		if ( 'collaborator' === $role ) {
			if ( user_can( $user, 'clms_manage_commerce' ) || user_can( $user, 'manage_options' ) ) {
				return admin_url( 'admin.php?page=clms-commercial-hub' );
			}
			return admin_url( 'admin.php?page=clms-crm-hub' );
		}

		$student_dashboard = $this->resolve_frontend_page_url( 'dashboard' );
		return $student_dashboard ? $student_dashboard : home_url( '/' );
	}

	// -------------------------------------------------------------------------
	// [clms_user_nav] shortcode
	// -------------------------------------------------------------------------

	/**
	 * Renders a role-aware navigation list.
	 *
	 * Attributes:
	 *   courses_url  — URL for the public course catalog (default: /cursos/)
	 *   login_url    — override login page URL
	 *   register_url — override register page URL
	 *   class        — extra CSS class added to the <nav> wrapper
	 *
	 * @param array $atts
	 * @return string
	 */
}
