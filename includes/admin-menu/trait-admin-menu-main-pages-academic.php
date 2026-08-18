<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Admin_Menu_Main_Pages_Academic_Trait {
	public function render_dashboard_page() {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$this->render_admin_styles();

		$role_context = $this->get_current_role_context();
		$role_label   = $this->get_role_context_label( $role_context );
		$summary      = $this->get_role_summary_metrics( $role_context, get_current_user_id() );
		$groups       = $this->get_navigation_groups( $role_context );
		$cache_status = ( class_exists( 'CLMS_Cache' ) && method_exists( 'CLMS_Cache', 'get_object_cache_status' ) )
			? CLMS_Cache::get_object_cache_status()
			: array( 'enabled' => false, 'driver' => 'none', 'dropin_path' => '' );
		$opcache_available = function_exists( 'opcache_get_status' );
		$opcache_enabled   = $opcache_available && (bool) ini_get( 'opcache.enable' );
		$wp_cache_defined  = defined( 'WP_CACHE' );
		$wp_cache_enabled  = $wp_cache_defined && WP_CACHE;
		$cache_driver      = '—';

		if ( ! empty( $cache_status['enabled'] ) ) {
			switch ( (string) ( $cache_status['driver'] ?? 'external' ) ) {
				case 'redis':
					$cache_driver = __( 'Redis', 'atora-lms' );
					break;
				case 'memcached':
					$cache_driver = __( 'Memcached', 'atora-lms' );
					break;
				case 'external':
					$cache_driver = __( 'Externo', 'atora-lms' );
					break;
				default:
					$cache_driver = __( 'Otro', 'atora-lms' );
					break;
			}
		}

		$cache_label   = ! empty( $cache_status['enabled'] ) ? sprintf( __( 'Activo · %s', 'atora-lms' ), $cache_driver ) : __( 'No detectado', 'atora-lms' );
		$opcache_label = $opcache_available
			? ( $opcache_enabled ? __( 'Activo', 'atora-lms' ) : __( 'Desactivado', 'atora-lms' ) )
			: __( 'No disponible', 'atora-lms' );
		$wp_cache_label = $wp_cache_defined
			? ( $wp_cache_enabled ? __( 'Activo', 'atora-lms' ) : __( 'Desactivado', 'atora-lms' ) )
			: __( 'No configurado', 'atora-lms' );

			echo '<div class="wrap clms-admin-wrap">';
			echo '<h1>' . esc_html__( 'ATORA', 'atora-lms' ) . '</h1>';
			echo '<p>' . esc_html__( 'Centro operativo por rol para navegar programas, cursos, lecciones, evaluaciones, IA, operación comercial, analítica y mensajes.', 'atora-lms' ) . '</p>';

		echo '<div class="clms-admin-card">';
		echo '<div class="clms-admin-role-banner">';
		echo '<div>';
		echo '<span class="clms-admin-kicker">' . esc_html__( 'Vista activa', 'atora-lms' ) . '</span>';
		echo '<h2>' . esc_html( $role_label ) . '</h2>';
		echo '<p>' . esc_html( $this->get_role_context_description( $role_context ) ) . '</p>';
		echo '</div>';
		echo '<div class="clms-admin-chip-group">';
		foreach ( $summary as $metric ) {
			echo '<div class="clms-admin-chip"><span>' . esc_html( $metric['label'] ) . '</span><strong>' . esc_html( $metric['value'] ) . '</strong></div>';
		}
		echo '</div>';
		echo '</div>';
		echo '</div>';

		echo '<div class="clms-admin-card">';
		echo '<div class="clms-admin-section-head">';
		echo '<div><span class="clms-admin-kicker">' . esc_html__( 'Rendimiento', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Estado de caché y PHP', 'atora-lms' ) . '</h2></div>';
		echo '</div>';
		echo '<div class="clms-admin-detail-grid">';
		echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'Object cache', 'atora-lms' ) . '</span><strong>' . esc_html( $cache_label ) . '</strong></div>';
		echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'OPcache', 'atora-lms' ) . '</span><strong>' . esc_html( $opcache_label ) . '</strong></div>';
		echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'WP_CACHE', 'atora-lms' ) . '</span><strong>' . esc_html( $wp_cache_label ) . '</strong></div>';
		echo '</div>';
		echo '<p class="clms-admin-note">' . esc_html__( 'Recomendación para más de 500 alumnos concurrentes: object cache (Redis/Memcached), OPcache y caché de página.', 'atora-lms' ) . '</p>';
		echo '</div>';

		foreach ( $groups as $group ) {
			if ( empty( $group['items'] ) ) {
				continue;
			}

			echo '<div class="clms-admin-card">';
			echo '<div class="clms-admin-section-head">';
			echo '<div><span class="clms-admin-kicker">' . esc_html( $group['eyebrow'] ) . '</span><h2>' . esc_html( $group['title'] ) . '</h2></div>';
			echo '</div>';
			echo '<div class="clms-admin-nav-grid">';

			foreach ( $group['items'] as $item ) {
				echo '<a class="clms-admin-nav-card" href="' . esc_url( $item['url'] ) . '">';
				echo '<strong>' . esc_html( $item['title'] ) . '</strong>';
				echo '<span>' . esc_html( $item['description'] ) . '</span>';
				echo '</a>';
			}

			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	public function render_my_profile_page() {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$this->render_admin_styles();

		$current_user_id  = get_current_user_id();
		$requested_user_id = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
		$user_id          = $current_user_id;
		if ( $requested_user_id > 0 && $requested_user_id !== $current_user_id ) {
			$can_view_requested = current_user_can( 'manage_options' )
				|| current_user_can( 'list_users' )
				|| current_user_can( 'edit_user', $requested_user_id );

			if ( ! $can_view_requested ) {
				$crm_class = '\ATORA\CRM\CRM';
				if ( class_exists( $crm_class ) && method_exists( $crm_class, 'get_accessible_contact_user_ids' ) ) {
					$visible_user_ids = array_values(
						array_filter(
							array_map(
								'absint',
								(array) $crm_class::get_accessible_contact_user_ids( $current_user_id )
							)
						)
					);
					$can_view_requested = in_array( $requested_user_id, $visible_user_ids, true );
				}
			}

			if ( ! $can_view_requested ) {
				wp_die( esc_html__( 'No tienes permisos para ver este perfil.', 'atora-lms' ) );
			}

			$user_id = $requested_user_id;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			wp_die( esc_html__( 'No se encontró el perfil solicitado.', 'atora-lms' ) );
		}

		$viewer_role_context = $this->get_current_role_context();
		$profile_role_context = $this->get_role_context_for_user( $user );
		$profile_role_label   = $this->get_role_context_label( $profile_role_context );
		$profile_wp_role      = $this->get_primary_wp_role_label( $user );
		$course_count  = class_exists( 'CLMS_Helper' ) ? count( ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $user_id ) ) ) : 0;
		$program_count = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_user_enrolled_programs' ) ? count( ( method_exists('CLMS_Helper','get_user_enrolled_programs') ? CLMS_Helper::get_user_enrolled_programs( $user_id ) : array() ) ) : 0;
		$display_name  = $user ? ( $user->display_name ? $user->display_name : $user->user_login ) : '';
		$member_since  = $user ? mysql2date( 'd/m/Y', $user->user_registered ) : '';
		$completed     = get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed     = is_array( $completed ) ? count( array_filter( array_map( 'absint', $completed ) ) ) : 0;
		$can_edit_profile = ( $current_user_id === $user_id ) || current_user_can( 'edit_user', $user_id ) || current_user_can( 'manage_options' );
		$profile_saved  = false;
		$profile_errors = array();

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['clms_profile_save'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$nonce_ok = isset( $_POST['clms_profile_nonce'] ) && wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['clms_profile_nonce'] ) ),
				'clms_admin_profile_update_' . $user_id
			);

			if ( ! $nonce_ok ) {
				$profile_errors[] = __( 'No se pudo validar el formulario. Intenta de nuevo.', 'atora-lms' );
			} elseif ( ! $can_edit_profile ) {
				$profile_errors[] = __( 'No tienes permisos para editar este perfil.', 'atora-lms' );
			} else {
				$first_name  = sanitize_text_field( (string) wp_unslash( $_POST['clms_first_name'] ?? '' ) );
				$last_name   = sanitize_text_field( (string) wp_unslash( $_POST['clms_last_name'] ?? '' ) );
				$display     = sanitize_text_field( (string) wp_unslash( $_POST['clms_display_name'] ?? '' ) );
				$user_email  = sanitize_email( (string) wp_unslash( $_POST['clms_user_email'] ?? '' ) );
				$description = sanitize_textarea_field( (string) wp_unslash( $_POST['clms_description'] ?? '' ) );
				$user_url    = esc_url_raw( (string) wp_unslash( $_POST['clms_user_url'] ?? '' ) );

				$phone       = sanitize_text_field( (string) wp_unslash( $_POST['clms_phone'] ?? '' ) );
				$whatsapp    = sanitize_text_field( (string) wp_unslash( $_POST['clms_whatsapp'] ?? '' ) );
				$telegram    = sanitize_text_field( (string) wp_unslash( $_POST['clms_telegram'] ?? '' ) );
				$country     = strtoupper( sanitize_text_field( (string) wp_unslash( $_POST['clms_country_code'] ?? '' ) ) );
				$state       = sanitize_text_field( (string) wp_unslash( $_POST['clms_state'] ?? '' ) );
				$city        = sanitize_text_field( (string) wp_unslash( $_POST['clms_city'] ?? '' ) );
				$sex         = sanitize_key( (string) wp_unslash( $_POST['clms_sex'] ?? '' ) );
				$age_raw     = (string) wp_unslash( $_POST['clms_age'] ?? '' );
				$age         = '' !== trim( $age_raw ) ? absint( $age_raw ) : 0;
				$avatar_url  = esc_url_raw( (string) wp_unslash( $_POST['clms_profile_photo_url'] ?? '' ) );
				$social_instagram = esc_url_raw( (string) wp_unslash( $_POST['clms_social_instagram'] ?? '' ) );
				$social_facebook  = esc_url_raw( (string) wp_unslash( $_POST['clms_social_facebook'] ?? '' ) );
				$social_linkedin  = esc_url_raw( (string) wp_unslash( $_POST['clms_social_linkedin'] ?? '' ) );
				$social_twitter   = esc_url_raw( (string) wp_unslash( $_POST['clms_social_twitter'] ?? '' ) );
				$social_tiktok    = esc_url_raw( (string) wp_unslash( $_POST['clms_social_tiktok'] ?? '' ) );

				if ( '' === $first_name ) {
					$profile_errors[] = __( 'El nombre es obligatorio.', 'atora-lms' );
				}
				if ( '' === $last_name ) {
					$profile_errors[] = __( 'El apellido es obligatorio.', 'atora-lms' );
				}
				if ( '' === $user_email || ! is_email( $user_email ) ) {
					$profile_errors[] = __( 'Debes indicar un correo válido.', 'atora-lms' );
				} else {
					$email_owner = email_exists( $user_email );
					if ( $email_owner && absint( $email_owner ) !== $user_id ) {
						$profile_errors[] = __( 'Ese correo ya está asociado a otra cuenta.', 'atora-lms' );
					}
				}
				if ( '' === $whatsapp ) {
					$profile_errors[] = __( 'El WhatsApp es obligatorio para iniciar cursos.', 'atora-lms' );
				}
				if ( '' === $country || '' === $state || '' === $city ) {
					$profile_errors[] = __( 'Completa país, estado/provincia y ciudad.', 'atora-lms' );
				}
				if ( $age > 0 && ( $age < 1 || $age > 120 ) ) {
					$profile_errors[] = __( 'La edad debe estar entre 1 y 120.', 'atora-lms' );
				}

				$phone_validator = static function ( string $value ): bool {
					$value = trim( $value );
					if ( '' === $value ) {
						return true;
					}
					if ( class_exists( '\ATORA\Security\Extended_Registration' ) && method_exists( '\ATORA\Security\Extended_Registration', 'is_valid_phone' ) ) {
						return (bool) \ATORA\Security\Extended_Registration::is_valid_phone( $value );
					}

					return (bool) preg_match( '/^\+[\d\s\-\(\)]{7,20}$/', $value );
				};

				if ( '' !== $whatsapp && ! $phone_validator( $whatsapp ) ) {
					$profile_errors[] = __( 'WhatsApp debe estar en formato internacional (ej: +58 412 1234567).', 'atora-lms' );
				}
				if ( '' !== $phone && ! $phone_validator( $phone ) ) {
					$profile_errors[] = __( 'Teléfono no válido. Usa formato internacional (ej: +58 212 1234567).', 'atora-lms' );
				}
				if ( '' === $phone && '' !== $whatsapp ) {
					$phone = $whatsapp;
				}
				if ( '' === $display ) {
					$display = trim( $first_name . ' ' . $last_name );
				}

				if ( empty( $profile_errors ) ) {
					$update = wp_update_user(
						array(
							'ID'           => $user_id,
							'display_name' => $display,
							'first_name'   => $first_name,
							'last_name'    => $last_name,
							'user_email'   => $user_email,
							'user_url'     => $user_url,
							'description'  => $description,
						)
					);

					if ( is_wp_error( $update ) ) {
						$profile_errors[] = $update->get_error_message();
					} else {
						$meta_updates = array(
							'atora_phone'             => $phone,
							'atora_whatsapp'          => $whatsapp,
							'atora_telegram'          => $telegram,
							'atora_country_code'      => $country,
							'atora_state'             => $state,
							'atora_city'              => $city,
							'atora_sex'               => $sex,
							'atora_age'               => $age > 0 ? (string) $age : '',
							'atora_profile_photo_url' => $avatar_url,
							'atora_social_instagram'  => $social_instagram,
							'atora_social_facebook'   => $social_facebook,
							'atora_social_linkedin'   => $social_linkedin,
							'atora_social_twitter'    => $social_twitter,
							'atora_social_tiktok'     => $social_tiktok,
						);

						foreach ( $meta_updates as $meta_key => $meta_value ) {
							// PT-4.3 (6.5.1): atora_phone pasa por
							// Preferences::update_phone() — invalida la
							// verificación si un admin cambia el número del
							// estudiante desde este panel.
							if ( 'atora_phone' === $meta_key && class_exists( '\ATORA\Messaging\Preferences' ) ) {
								\ATORA\Messaging\Preferences::update_phone( $user_id, (string) $meta_value );
								continue;
							}
							update_user_meta( $user_id, $meta_key, $meta_value );
						}

						if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'upsert_contact' ) ) {
							\ATORA\CRM\CRM::upsert_contact(
								array(
									'user_id'  => $user_id,
									'name'     => $display,
									'email'    => $user_email,
									'phone'    => $phone,
									'whatsapp' => $whatsapp,
									'telegram' => $telegram,
									'country'  => $country,
									'state'    => $state,
									'city'     => $city,
									'sex'      => $sex,
									'age'      => $age,
								)
							);
						}

						$user = get_userdata( $user_id );
						if ( $user instanceof WP_User ) {
							$profile_role_context = $this->get_role_context_for_user( $user );
							$profile_role_label   = $this->get_role_context_label( $profile_role_context );
							$profile_wp_role      = $this->get_primary_wp_role_label( $user );
							$display_name         = $user->display_name ? $user->display_name : $user->user_login;
						}
						$profile_saved = true;
					}
				}
			}
		}

		$profile_meta = array(
			'phone'      => sanitize_text_field( (string) get_user_meta( $user_id, 'atora_phone', true ) ),
			'whatsapp'   => sanitize_text_field( (string) get_user_meta( $user_id, 'atora_whatsapp', true ) ),
			'telegram'   => sanitize_text_field( (string) get_user_meta( $user_id, 'atora_telegram', true ) ),
			'country'    => sanitize_text_field( (string) get_user_meta( $user_id, 'atora_country_code', true ) ),
			'state'      => sanitize_text_field( (string) get_user_meta( $user_id, 'atora_state', true ) ),
			'city'       => sanitize_text_field( (string) get_user_meta( $user_id, 'atora_city', true ) ),
			'sex'        => sanitize_key( (string) get_user_meta( $user_id, 'atora_sex', true ) ),
			'age'        => absint( get_user_meta( $user_id, 'atora_age', true ) ),
			'avatar_url' => esc_url_raw( (string) get_user_meta( $user_id, 'atora_profile_photo_url', true ) ),
			'instagram'  => esc_url_raw( (string) get_user_meta( $user_id, 'atora_social_instagram', true ) ),
			'facebook'   => esc_url_raw( (string) get_user_meta( $user_id, 'atora_social_facebook', true ) ),
			'linkedin'   => esc_url_raw( (string) get_user_meta( $user_id, 'atora_social_linkedin', true ) ),
			'twitter'    => esc_url_raw( (string) get_user_meta( $user_id, 'atora_social_twitter', true ) ),
			'tiktok'     => esc_url_raw( (string) get_user_meta( $user_id, 'atora_social_tiktok', true ) ),
			'user_url'   => esc_url_raw( (string) $user->user_url ),
		);
		$avatar_html = '';
		if ( '' !== $profile_meta['avatar_url'] ) {
			$avatar_html = '<img class="clms-admin-profile-avatar" src="' . esc_url( $profile_meta['avatar_url'] ) . '" alt="' . esc_attr( $display_name ) . '">';
		} else {
			$avatar_html = get_avatar( $user_id, 120 );
		}
		$country_options = class_exists( '\ATORA\Security\Extended_Registration' ) && method_exists( '\ATORA\Security\Extended_Registration', 'get_countries' )
			? (array) \ATORA\Security\Extended_Registration::get_countries()
			: array();
		$sex_options = array(
			''                  => __( '— Selecciona —', 'atora-lms' ),
			'femenino'          => __( 'Femenino', 'atora-lms' ),
			'masculino'         => __( 'Masculino', 'atora-lms' ),
			'no_binario'        => __( 'No binario', 'atora-lms' ),
			'prefiero_no_decir' => __( 'Prefiero no decir', 'atora-lms' ),
		);

		echo '<div class="wrap clms-admin-wrap">';
		echo '<h1>' . esc_html( $current_user_id === $user_id ? __( 'Mi perfil ATORA', 'atora-lms' ) : __( 'Perfil ATORA', 'atora-lms' ) ) . '</h1>';
		echo '<div class="clms-admin-card">';
		echo '<div class="clms-admin-profile-head">';
		echo $avatar_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div>';
		echo '<h2>' . esc_html( $display_name ) . '</h2>';
		echo '<p>' . esc_html( $profile_role_label ) . ' · ' . esc_html__( 'Miembro desde', 'atora-lms' ) . ' ' . esc_html( $member_since ) . '</p>';
		if ( $profile_wp_role && $profile_wp_role !== $profile_role_label ) {
			echo '<p class="clms-admin-note">' . esc_html__( 'Rol WordPress:', 'atora-lms' ) . ' ' . esc_html( $profile_wp_role ) . '</p>';
		}
		echo '<p>' . esc_html( (string) get_user_meta( $user_id, 'description', true ) ) . '</p>';
		echo '</div>';
		echo '</div>';
		echo '</div>';

		echo '<div class="clms-admin-metrics">';
		echo '<div class="clms-admin-metric"><span>' . esc_html__( 'Programas', 'atora-lms' ) . '</span><strong>' . esc_html( $program_count ) . '</strong></div>';
		echo '<div class="clms-admin-metric"><span>' . esc_html__( 'Cursos', 'atora-lms' ) . '</span><strong>' . esc_html( $course_count ) . '</strong></div>';
		echo '<div class="clms-admin-metric"><span>' . esc_html__( 'Lecciones completadas', 'atora-lms' ) . '</span><strong>' . esc_html( $completed ) . '</strong></div>';
		echo '<div class="clms-admin-metric"><span>' . esc_html__( 'Rol', 'atora-lms' ) . '</span><strong>' . esc_html( $profile_role_label ) . '</strong></div>';
		echo '</div>';

		echo '<div class="clms-admin-card">';
		echo '<h2>' . esc_html__( 'Datos de contacto y perfil', 'atora-lms' ) . '</h2>';
		echo '<p class="clms-admin-note">' . esc_html__( 'Campos obligatorios para iniciar curso: nombre, apellido, correo, WhatsApp y localización (país, estado y ciudad).', 'atora-lms' ) . '</p>';
		if ( $profile_saved ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Perfil actualizado correctamente.', 'atora-lms' ) . '</p></div>';
		}
		if ( ! empty( $profile_errors ) ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'No se pudo guardar el perfil:', 'atora-lms' ) . '</strong></p><ul>';
			foreach ( $profile_errors as $profile_error ) {
				echo '<li>' . esc_html( (string) $profile_error ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( $can_edit_profile ) {
			echo '<form method="post" class="clms-admin-profile-form">';
			wp_nonce_field( 'clms_admin_profile_update_' . $user_id, 'clms_profile_nonce' );
			echo '<input type="hidden" name="clms_profile_save" value="1">';
			echo '<div class="clms-admin-profile-grid">';
			echo '<label><span>' . esc_html__( 'Nombre *', 'atora-lms' ) . '</span><input type="text" name="clms_first_name" required value="' . esc_attr( (string) get_user_meta( $user_id, 'first_name', true ) ) . '"></label>';
			echo '<label><span>' . esc_html__( 'Apellido *', 'atora-lms' ) . '</span><input type="text" name="clms_last_name" required value="' . esc_attr( (string) get_user_meta( $user_id, 'last_name', true ) ) . '"></label>';
			echo '<label><span>' . esc_html__( 'Nombre público', 'atora-lms' ) . '</span><input type="text" name="clms_display_name" value="' . esc_attr( $display_name ) . '"></label>';
			echo '<label><span>' . esc_html__( 'Correo *', 'atora-lms' ) . '</span><input type="email" name="clms_user_email" required value="' . esc_attr( (string) $user->user_email ) . '"></label>';
			echo '<label><span>' . esc_html__( 'Teléfono', 'atora-lms' ) . '</span><input type="tel" name="clms_phone" value="' . esc_attr( $profile_meta['phone'] ) . '" placeholder="+58 212 1234567"></label>';
			echo '<label><span>' . esc_html__( 'WhatsApp *', 'atora-lms' ) . '</span><input type="tel" name="clms_whatsapp" required value="' . esc_attr( $profile_meta['whatsapp'] ) . '" placeholder="+58 412 1234567"></label>';
			echo '<label><span>' . esc_html__( 'País *', 'atora-lms' ) . '</span>';
			if ( ! empty( $country_options ) ) {
				echo '<select name="clms_country_code" required>';
				echo '<option value="">' . esc_html__( 'Selecciona país', 'atora-lms' ) . '</option>';
				foreach ( $country_options as $country_code => $country_label ) {
					echo '<option value="' . esc_attr( (string) $country_code ) . '" ' . selected( strtoupper( (string) $profile_meta['country'] ), strtoupper( (string) $country_code ), false ) . '>' . esc_html( (string) $country_label ) . '</option>';
				}
				echo '</select>';
			} else {
				echo '<input type="text" name="clms_country_code" required value="' . esc_attr( $profile_meta['country'] ) . '">';
			}
			echo '</label>';
			echo '<label><span>' . esc_html__( 'Estado / Provincia *', 'atora-lms' ) . '</span><input type="text" name="clms_state" required value="' . esc_attr( $profile_meta['state'] ) . '"></label>';
			echo '<label><span>' . esc_html__( 'Ciudad *', 'atora-lms' ) . '</span><input type="text" name="clms_city" required value="' . esc_attr( $profile_meta['city'] ) . '"></label>';
			echo '<label><span>' . esc_html__( 'Telegram', 'atora-lms' ) . '</span><input type="text" name="clms_telegram" value="' . esc_attr( $profile_meta['telegram'] ) . '" placeholder="@usuario"></label>';
			echo '<label><span>' . esc_html__( 'Género', 'atora-lms' ) . '</span><select name="clms_sex">';
			foreach ( $sex_options as $sex_key => $sex_label ) {
				echo '<option value="' . esc_attr( (string) $sex_key ) . '" ' . selected( (string) $profile_meta['sex'], (string) $sex_key, false ) . '>' . esc_html( (string) $sex_label ) . '</option>';
			}
			echo '</select></label>';
			echo '<label><span>' . esc_html__( 'Edad', 'atora-lms' ) . '</span><input type="number" min="1" max="120" name="clms_age" value="' . esc_attr( $profile_meta['age'] > 0 ? (string) $profile_meta['age'] : '' ) . '"></label>';
			echo '<label><span>' . esc_html__( 'Foto (URL)', 'atora-lms' ) . '</span><input type="url" name="clms_profile_photo_url" value="' . esc_attr( $profile_meta['avatar_url'] ) . '" placeholder="https://.../foto.jpg"></label>';
			echo '<label><span>' . esc_html__( 'Sitio web', 'atora-lms' ) . '</span><input type="url" name="clms_user_url" value="' . esc_attr( $profile_meta['user_url'] ) . '" placeholder="https://"></label>';
			echo '<label><span>' . esc_html__( 'Instagram', 'atora-lms' ) . '</span><input type="url" name="clms_social_instagram" value="' . esc_attr( $profile_meta['instagram'] ) . '" placeholder="https://instagram.com/..."></label>';
			echo '<label><span>' . esc_html__( 'Facebook', 'atora-lms' ) . '</span><input type="url" name="clms_social_facebook" value="' . esc_attr( $profile_meta['facebook'] ) . '" placeholder="https://facebook.com/..."></label>';
			echo '<label><span>' . esc_html__( 'LinkedIn', 'atora-lms' ) . '</span><input type="url" name="clms_social_linkedin" value="' . esc_attr( $profile_meta['linkedin'] ) . '" placeholder="https://linkedin.com/in/..."></label>';
			echo '<label><span>' . esc_html__( 'X / Twitter', 'atora-lms' ) . '</span><input type="url" name="clms_social_twitter" value="' . esc_attr( $profile_meta['twitter'] ) . '" placeholder="https://x.com/..."></label>';
			echo '<label><span>' . esc_html__( 'TikTok', 'atora-lms' ) . '</span><input type="url" name="clms_social_tiktok" value="' . esc_attr( $profile_meta['tiktok'] ) . '" placeholder="https://tiktok.com/@..."></label>';
			echo '</div>';
			echo '<label class="clms-admin-profile-full"><span>' . esc_html__( 'Bio', 'atora-lms' ) . '</span><textarea name="clms_description" rows="4">' . esc_textarea( (string) get_user_meta( $user_id, 'description', true ) ) . '</textarea></label>';
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Guardar perfil', 'atora-lms' ) . '</button></p>';
			echo '</form>';
		} else {
			$location = trim( implode( ', ', array_filter( array( $profile_meta['city'], $profile_meta['state'], $profile_meta['country'] ) ) ) );
			echo '<div class="clms-admin-detail-grid">';
			echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'Correo', 'atora-lms' ) . '</span><strong>' . esc_html( (string) $user->user_email ) . '</strong></div>';
			echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'Teléfono', 'atora-lms' ) . '</span><strong>' . esc_html( $profile_meta['phone'] ?: '—' ) . '</strong></div>';
			echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'WhatsApp', 'atora-lms' ) . '</span><strong>' . esc_html( $profile_meta['whatsapp'] ?: '—' ) . '</strong></div>';
			echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'Telegram', 'atora-lms' ) . '</span><strong>' . esc_html( $profile_meta['telegram'] ?: '—' ) . '</strong></div>';
			echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'Ubicación', 'atora-lms' ) . '</span><strong>' . esc_html( $location ?: '—' ) . '</strong></div>';
			echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'Género', 'atora-lms' ) . '</span><strong>' . esc_html( $sex_options[ (string) $profile_meta['sex'] ] ?? '—' ) . '</strong></div>';
			echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'Edad', 'atora-lms' ) . '</span><strong>' . esc_html( $profile_meta['age'] > 0 ? (string) $profile_meta['age'] : '—' ) . '</strong></div>';
			echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'Sitio web', 'atora-lms' ) . '</span><strong>' . esc_html( $profile_meta['user_url'] ?: '—' ) . '</strong></div>';
			echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'Instagram', 'atora-lms' ) . '</span><strong>' . esc_html( $profile_meta['instagram'] ?: '—' ) . '</strong></div>';
			echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'Facebook', 'atora-lms' ) . '</span><strong>' . esc_html( $profile_meta['facebook'] ?: '—' ) . '</strong></div>';
			echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'LinkedIn', 'atora-lms' ) . '</span><strong>' . esc_html( $profile_meta['linkedin'] ?: '—' ) . '</strong></div>';
			echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'X / Twitter', 'atora-lms' ) . '</span><strong>' . esc_html( $profile_meta['twitter'] ?: '—' ) . '</strong></div>';
			echo '<div class="clms-admin-detail-row"><span>' . esc_html__( 'TikTok', 'atora-lms' ) . '</span><strong>' . esc_html( $profile_meta['tiktok'] ?: '—' ) . '</strong></div>';
			echo '</div>';
		}
		echo '</div>';

		echo '<div class="clms-admin-card">';
		echo '<h2>' . esc_html__( 'Siguientes accesos', 'atora-lms' ) . '</h2>';
		echo '<div class="clms-admin-nav-grid">';
		foreach ( $this->get_profile_quick_links( $viewer_role_context ) as $item ) {
			echo '<a class="clms-admin-nav-card" href="' . esc_url( $item['url'] ) . '"><strong>' . esc_html( $item['title'] ) . '</strong><span>' . esc_html( $item['description'] ) . '</span></a>';
		}
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	public function render_instructor_profile_page() {
		if ( ! current_user_can( 'clms_view_teacher_dashboard' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$this->render_admin_styles();

		$instructor = clms_core('CLMS_Instructor');
		if ( ! $instructor && class_exists( 'CLMS_Instructor' ) ) {
			$instructor = new CLMS_Instructor();
		}
		$user_id    = get_current_user_id();

		echo '<div class="wrap clms-admin-wrap">';
		echo '<h1>' . esc_html__( 'Perfil docente', 'atora-lms' ) . '</h1>';
		echo '<p>' . esc_html__( 'Vista explícita del perfil público y comercial del docente.', 'atora-lms' ) . '</p>';

		if ( $instructor && method_exists( $instructor, 'get_instructor_profile_html' ) ) {
			if ( method_exists( $instructor, 'get_inline_style_tag' ) ) {
				echo $instructor->get_inline_style_tag(); // phpcs:ignore WordPress.Security.EscapeOutput
			}

			echo '<div class="clms-admin-card">';
			echo $instructor->get_instructor_profile_html( $user_id, array( 'show_courses' => true, 'courses_limit' => 6, 'show_meta' => true, 'show_socials' => true, 'show_bio' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</div>';
		} else {
			echo '<div class="clms-admin-card"><p>' . esc_html__( 'El módulo de docentes no está disponible.', 'atora-lms' ) . '</p></div>';
		}

		echo '<div class="clms-admin-card">';
		echo '<h2>' . esc_html__( 'Visibilidad automática', 'atora-lms' ) . '</h2>';
		echo '<p>' . esc_html__( 'Este perfil se muestra automáticamente en cursos y programas cuando el docente está asociado.', 'atora-lms' ) . '</p>';
		echo '</div>';
		echo '</div>';
	}

	public function render_ai_hub_page() {
		if ( ! current_user_can( 'clms_manage_courses' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$this->render_admin_styles();

		$items = array(
			array(
				'title'       => __( 'Configuración IA', 'atora-lms' ),
				'description' => __( 'Proveedor activo, modelos, APIs y parámetros base de operación.', 'atora-lms' ),
				'url'         => admin_url( 'admin.php?page=clms-settings&tab=apis' ),
			),
			array(
				'title'       => __( 'Exámenes IA', 'atora-lms' ),
				'description' => __( 'Generación asistida de cuestionarios y evaluaciones automáticas.', 'atora-lms' ),
				'url'         => admin_url( 'admin.php?page=clms-ai-exams' ),
			),
			array(
				'title'       => __( 'Libro de calificaciones', 'atora-lms' ),
				'description' => __( 'Revisa cómo impactan los modos manual, asistido por IA y autoevaluado en las notas.', 'atora-lms' ),
				'url'         => admin_url( 'admin.php?page=clms-gradebook' ),
			),
		);

		echo '<div class="wrap clms-admin-wrap">';
		echo '<h1>' . esc_html__( 'IA en ATORA', 'atora-lms' ) . '</h1>';
		echo '<p>' . esc_html__( 'Centro de herramientas de IA para evaluación, asistencia y configuración académica.', 'atora-lms' ) . '</p>';
		echo '<div class="clms-admin-card"><p><strong>' . esc_html__( 'Asistente docente activo:', 'atora-lms' ) . '</strong> ' . esc_html__( 'en esta pantalla puedes abrir el panel flotante del asistente docente para investigar, planificar o redactar sin salir del hub.', 'atora-lms' ) . '</p></div>';
		echo '<div class="clms-admin-card"><div class="clms-admin-nav-grid">';
		foreach ( $items as $item ) {
			echo '<a class="clms-admin-nav-card" href="' . esc_url( $item['url'] ) . '"><strong>' . esc_html( $item['title'] ) . '</strong><span>' . esc_html( $item['description'] ) . '</span></a>';
		}
		echo '</div></div>';
		echo '</div>';
	}

	public function render_analytics_page() {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$this->render_admin_styles();

		$role_context = $this->get_current_role_context();
		$user_id      = get_current_user_id();
		$cards        = $this->get_analytics_cards( $role_context, $user_id );
		$analytics    = clms_core('CLMS_Analytics');
		if ( ! $analytics && class_exists( 'CLMS_Analytics' ) ) {
			$analytics = new CLMS_Analytics();
		}
		$snapshot     = ( $analytics && method_exists( $analytics, 'get_dashboard_snapshot' ) )
			? $analytics->get_dashboard_snapshot( $role_context, $user_id )
			: array();
		$academic      = isset( $snapshot['academic'] ) && is_array( $snapshot['academic'] ) ? $snapshot['academic'] : array( 'cards' => array(), 'rows' => array() );
		$commercial    = isset( $snapshot['commercial'] ) && is_array( $snapshot['commercial'] ) ? $snapshot['commercial'] : array( 'cards' => array(), 'rows' => array() );
		$observability = isset( $snapshot['observability'] ) && is_array( $snapshot['observability'] ) ? $snapshot['observability'] : array( 'cards' => array(), 'events' => array(), 'health' => array() );
		$view         = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'full';
		$is_compact   = 'compact' === $view;
		$base_url     = admin_url( 'admin.php?page=clms-analytics' );
		$full_url     = add_query_arg( 'view', 'full', $base_url );
		$compact_url  = add_query_arg( 'view', 'compact', $base_url );
		$trend_retention = ( $analytics && method_exists( $analytics, 'get_event_series' ) )
			? $analytics->get_event_series( array( 'lesson_completed' ), 42, 'week', 'count' )
			: array();
		$trend_engagement = ( $analytics && method_exists( $analytics, 'get_event_series' ) )
			? $analytics->get_event_series( array( 'submission_created' ), 42, 'week', 'count' )
			: array();
		$trend_performance = ( $analytics && method_exists( $analytics, 'get_event_series' ) )
			? $analytics->get_event_series( array( 'submission_graded' ), 42, 'week', 'avg', 'grade' )
			: array();

		echo '<div class="wrap clms-admin-wrap' . ( $is_compact ? ' clms-admin-compact' : '' ) . '">';
		echo '<h1>' . esc_html__( 'Analítica', 'atora-lms' ) . '</h1>';
		echo '<p>' . esc_html__( 'Resumen académico, comercial y de salud del sistema ajustado al rol actual.', 'atora-lms' ) . '</p>';
		echo '<div class="clms-admin-filter clms-admin-view-toggle">';
		echo '<span>' . esc_html__( 'Vista:', 'atora-lms' ) . '</span>';
		echo '<a class="clms-admin-view-link' . ( ! $is_compact ? ' is-active' : '' ) . '" href="' . esc_url( $full_url ) . '">' . esc_html__( 'Completa', 'atora-lms' ) . '</a>';
		echo '<a class="clms-admin-view-link' . ( $is_compact ? ' is-active' : '' ) . '" href="' . esc_url( $compact_url ) . '">' . esc_html__( 'Compacta', 'atora-lms' ) . '</a>';
		echo '</div>';
		echo '<div class="clms-admin-metrics">';
		foreach ( $cards as $card ) {
			echo '<div class="clms-admin-metric"><span>' . esc_html( $card['label'] ) . '</span><strong>' . esc_html( $card['value'] ) . '</strong></div>';
		}
		echo '</div>';

		echo '<div class="clms-admin-card">';
		echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Tendencias', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Retención, engagement y rendimiento', 'atora-lms' ) . '</h2></div></div>';
		echo '<div class="clms-admin-trend-grid">';
		$this->render_trend_card(
			__( 'Retención', 'atora-lms' ),
			$trend_retention,
			'',
			__( 'Lecciones completadas', 'atora-lms' )
		);
		$this->render_trend_card(
			__( 'Engagement', 'atora-lms' ),
			$trend_engagement,
			'',
			__( 'Entregas registradas', 'atora-lms' )
		);
		$this->render_trend_card(
			__( 'Rendimiento', 'atora-lms' ),
			$trend_performance,
			'%',
			__( 'Nota promedio', 'atora-lms' )
		);
		echo '</div>';
		echo '</div>';

			if ( ! empty( $academic['cards'] ) ) {
				echo '<div class="clms-admin-card">';
				echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Académico', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Progreso, completitud y carga docente', 'atora-lms' ) . '</h2></div></div>';
				echo '<div class="clms-admin-metrics">';
				foreach ( $academic['cards'] as $card ) {
					echo '<div class="clms-admin-metric"><span>' . esc_html( $card['label'] ) . '</span><strong>' . esc_html( $card['value'] ) . '</strong></div>';
				}
				echo '</div>';

				$this->render_bar_chart(
					__( 'Progreso promedio por curso', 'atora-lms' ),
					$academic['rows'] ?? array(),
					'course_title',
					'avg_progress',
					'%',
					100,
					$is_compact ? 4 : 0
				);
				$this->render_bar_chart(
					__( 'Entregas pendientes por curso', 'atora-lms' ),
					$academic['rows'] ?? array(),
					'course_title',
					'pending_submissions',
					'',
					0,
					$is_compact ? 4 : 0
				);

				if ( ! $is_compact && ! empty( $academic['rows'] ) ) {
					echo '<table class="widefat striped clms-admin-table">';
					echo '<thead><tr><th>' . esc_html__( 'Curso', 'atora-lms' ) . '</th><th>' . esc_html__( 'Inscritos', 'atora-lms' ) . '</th><th>' . esc_html__( 'Progreso', 'atora-lms' ) . '</th><th>' . esc_html__( 'Completitud', 'atora-lms' ) . '</th><th>' . esc_html__( 'Nota', 'atora-lms' ) . '</th><th>' . esc_html__( 'Pendientes', 'atora-lms' ) . '</th><th>' . esc_html__( 'Abandono', 'atora-lms' ) . '</th></tr></thead><tbody>';
					foreach ( $academic['rows'] as $row ) {
						echo '<tr>';
					echo '<td><strong>' . esc_html( $row['course_title'] ?? '' ) . '</strong></td>';
					echo '<td>' . esc_html( absint( $row['enrolled_students'] ?? 0 ) ) . '</td>';
					echo '<td>' . esc_html( absint( $row['avg_progress'] ?? 0 ) ) . '%</td>';
					echo '<td>' . esc_html( absint( $row['completion_rate'] ?? 0 ) ) . '%</td>';
					echo '<td>' . esc_html( absint( $row['avg_grade'] ?? 0 ) ) . '%</td>';
					echo '<td>' . esc_html( absint( $row['pending_submissions'] ?? 0 ) ) . '</td>';
					echo '<td>' . esc_html( absint( $row['inactive_students'] ?? 0 ) ) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			}
			echo '</div>';
		}

			if ( ! empty( $commercial['cards'] ) ) {
				echo '<div class="clms-admin-card">';
				echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Comercial', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Conversiones, ventas y cross-sell', 'atora-lms' ) . '</h2></div></div>';
				echo '<div class="clms-admin-metrics">';
				foreach ( $commercial['cards'] as $card ) {
					echo '<div class="clms-admin-metric"><span>' . esc_html( $card['label'] ) . '</span><strong>' . esc_html( $card['value'] ) . '</strong></div>';
				}
				echo '</div>';

				$this->render_bar_chart(
					__( 'Ingresos estimados por producto', 'atora-lms' ),
					$commercial['rows'] ?? array(),
					'title',
					'revenue',
					'$',
					0,
					$is_compact ? 4 : 0
				);

				if ( ! $is_compact && ! empty( $commercial['rows'] ) ) {
					echo '<table class="widefat striped clms-admin-table">';
					echo '<thead><tr><th>' . esc_html__( 'Entidad', 'atora-lms' ) . '</th><th>' . esc_html__( 'Tipo', 'atora-lms' ) . '</th><th>' . esc_html__( 'Órdenes', 'atora-lms' ) . '</th><th>' . esc_html__( 'Ingresos estimados', 'atora-lms' ) . '</th></tr></thead><tbody>';
					foreach ( $commercial['rows'] as $row ) {
						echo '<tr>';
					echo '<td><strong>' . esc_html( $row['title'] ?? '' ) . '</strong></td>';
					echo '<td>' . esc_html( $this->format_entity_type_label( $row['type'] ?? '' ) ) . '</td>';
					echo '<td>' . esc_html( absint( $row['orders'] ?? 0 ) ) . '</td>';
					echo '<td>$' . esc_html( number_format_i18n( (float) ( $row['revenue'] ?? 0 ), 2 ) ) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			}
			echo '</div>';
		}

		if ( ! empty( $observability['cards'] ) ) {
			echo '<div class="clms-admin-card">';
			echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Observabilidad', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Logs, salud y auditoría', 'atora-lms' ) . '</h2></div></div>';
			echo '<div class="clms-admin-metrics">';
			foreach ( $observability['cards'] as $card ) {
				echo '<div class="clms-admin-metric"><span>' . esc_html( $card['label'] ) . '</span><strong>' . esc_html( $card['value'] ) . '</strong></div>';
			}
			echo '</div>';

			if ( ! empty( $observability['health'] ) ) {
				echo '<div class="clms-admin-status-grid">';
				foreach ( $observability['health'] as $item ) {
					echo '<div class="clms-admin-status-card is-' . esc_attr( sanitize_key( (string) ( $item['status'] ?? 'info' ) ) ) . '">';
					echo '<strong>' . esc_html( $item['label'] ?? '' ) . '</strong>';
					echo '<span>' . esc_html( $item['detail'] ?? '' ) . '</span>';
					echo '</div>';
				}
				echo '</div>';
			}

				if ( ! $is_compact && ! empty( $observability['events'] ) ) {
					echo '<div class="clms-admin-audit-list" style="margin-top:16px">';
					foreach ( $observability['events'] as $event ) {
						echo '<div class="clms-admin-audit-item">';
						echo '<strong>' . esc_html( $this->format_event_type_label( $event['type'] ?? '' ) ) . '</strong> ';
					echo '<span class="clms-admin-status-pill is-' . esc_attr( sanitize_key( (string) ( $event['severity'] ?? 'info' ) ) ) . '">' . esc_html( strtoupper( (string) ( $event['severity'] ?? 'info' ) ) ) . '</span><br>';
					echo '<span>' . esc_html( $event['message'] ?? '' ) . '</span><br>';
					echo '<span>' . esc_html( $this->format_admin_datetime( $event['created_at'] ?? '' ) ) . '</span>';
					echo '</div>';
				}
				echo '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	public function render_messages_page() {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$this->render_admin_styles();

		$user_id   = get_current_user_id();
		$messaging = clms_core('CLMS_Messaging');
		$selected_thread = isset( $_GET['thread'] ) ? sanitize_key( wp_unslash( $_GET['thread'] ) ) : '';
		$items = array(
			array(
				'title'       => __( 'Panel del estudiante', 'atora-lms' ),
				'description' => __( 'Revisa avisos, progreso y feedback reciente desde la experiencia del alumno.', 'atora-lms' ),
				'url'         => admin_url( 'admin.php?page=clms-my-profile' ),
			),
			array(
				'title'       => __( 'SpeedGrade', 'atora-lms' ),
				'description' => __( 'Comunicación académica a través de feedback y revisión de entregas.', 'atora-lms' ),
				'url'         => admin_url( 'admin.php?page=clms-speedgrader' ),
			),
			array(
				'title'       => __( 'Asistente IA', 'atora-lms' ),
				'description' => __( 'Punto de entrada para acompañamiento docente y respuestas operativas.', 'atora-lms' ),
				'url'         => admin_url( 'admin.php?page=clms-ai-hub' ),
			),
		);
		$threads  = ( $messaging && method_exists( $messaging, 'get_threads' ) ) ? $messaging->get_threads( $user_id, array( 'limit' => 12 ) ) : array();
		if ( ! $selected_thread && ! empty( $threads[0]['thread_id'] ) ) {
			$selected_thread = sanitize_key( (string) $threads[0]['thread_id'] );
		}
		$messages = ( $messaging && method_exists( $messaging, 'get_messages' ) ) ? $messaging->get_messages(
			$user_id,
			array(
				'limit'     => 30,
				'thread_id' => $selected_thread,
			)
		) : array();
		$stats    = ( $messaging && method_exists( $messaging, 'get_message_stats' ) ) ? $messaging->get_message_stats( $user_id ) : array(
			'total'           => 0,
			'unread'          => 0,
			'from_system'     => 0,
			'from_teacher'    => 0,
			'from_ai'         => 0,
			'recommendations' => 0,
		);
		$compose = ( $messaging && method_exists( $messaging, 'get_compose_context_for_user' ) ) ? $messaging->get_compose_context_for_user( $user_id ) : array(
			'courses'  => array(),
			'students' => array(),
		);

		echo '<div class="wrap clms-admin-wrap">';
		echo '<h1>' . esc_html__( 'Mensajes y seguimiento', 'atora-lms' ) . '</h1>';
		echo '<p>' . esc_html__( 'Centro de comunicación operativa para feedback, avisos, seguimiento académico y recomendaciones inteligentes.', 'atora-lms' ) . '</p>';

		if ( ! empty( $_GET['message_sent'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Mensaje enviado correctamente.', 'atora-lms' ) . '</p></div>';
		}

		if ( ! empty( $_GET['message_error'] ) ) {
			$error = sanitize_key( wp_unslash( $_GET['message_error'] ) );
			$label = __( 'No se pudo enviar el mensaje.', 'atora-lms' );

			if ( 'forbidden' === $error ) {
				$label = __( 'No puedes escribirle a ese estudiante desde este contexto.', 'atora-lms' );
			} elseif ( 'missing_fields' === $error ) {
				$label = __( 'Completa estudiante, asunto y mensaje antes de enviar.', 'atora-lms' );
			}

			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $label ) . '</p></div>';
		}

		echo '<div class="clms-admin-card"><p><strong>' . esc_html__( 'Asistente docente activo:', 'atora-lms' ) . '</strong> ' . esc_html__( 'usa el panel flotante para redactar mensajes, resumir feedback o preparar comunicaciones académicas.', 'atora-lms' ) . '</p></div>';
		echo '<div class="clms-admin-card"><div class="clms-admin-nav-grid">';
		foreach ( $items as $item ) {
			if ( ! $this->current_user_can_visit_url( $item['url'] ) ) {
				continue;
			}
			echo '<a class="clms-admin-nav-card" href="' . esc_url( $item['url'] ) . '"><strong>' . esc_html( $item['title'] ) . '</strong><span>' . esc_html( $item['description'] ) . '</span></a>';
		}
		echo '</div></div>';

		echo '<div class="clms-admin-metrics">';
		echo '<div class="clms-admin-metric"><span>' . esc_html__( 'Total', 'atora-lms' ) . '</span><strong>' . esc_html( absint( $stats['total'] ) ) . '</strong></div>';
		echo '<div class="clms-admin-metric"><span>' . esc_html__( 'No leídos', 'atora-lms' ) . '</span><strong>' . esc_html( absint( $stats['unread'] ) ) . '</strong></div>';
		echo '<div class="clms-admin-metric"><span>' . esc_html__( 'Recomendaciones', 'atora-lms' ) . '</span><strong>' . esc_html( absint( $stats['recommendations'] ) ) . '</strong></div>';
		echo '<div class="clms-admin-metric"><span>' . esc_html__( 'IA / Docente', 'atora-lms' ) . '</span><strong>' . esc_html( absint( $stats['from_ai'] ) ) . ' / ' . esc_html( absint( $stats['from_teacher'] ) ) . '</strong></div>';
		echo '</div>';

		if ( ! empty( $threads ) ) {
			echo '<div class="clms-admin-card">';
			echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Conversaciones', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Hilos por contexto', 'atora-lms' ) . '</h2></div></div>';
			echo '<div class="clms-admin-thread-grid">';
			foreach ( $threads as $thread ) {
				$thread_id = sanitize_key( (string) $thread['thread_id'] );
				$url       = add_query_arg(
					array(
						'page'   => 'clms-messages',
						'thread' => $thread_id,
					),
					admin_url( 'admin.php' )
				);
				echo '<a class="clms-admin-thread-card' . ( $selected_thread === $thread_id ? ' is-active' : '' ) . '" href="' . esc_url( $url ) . '">';
				echo '<span class="clms-admin-kicker">' . esc_html( $this->format_thread_type_label( $thread['thread_type'] ?? 'general' ) ) . '</span>';
				echo '<strong>' . esc_html( $thread['thread_label'] ?? __( 'General', 'atora-lms' ) ) . '</strong>';
				echo '<span>' . esc_html( $this->truncate_admin_text( (string) ( $thread['last_message']['title'] ?? '' ), 72 ) ) . '</span>';
				echo '<small>' . esc_html( absint( $thread['message_count'] ) ) . ' ' . esc_html__( 'mensaje(s)', 'atora-lms' ) . ' · ' . esc_html( absint( $thread['unread_count'] ) ) . ' ' . esc_html__( 'sin leer', 'atora-lms' ) . '</small>';
				echo '</a>';
			}
			echo '</div>';
			echo '</div>';
		}

		if ( $this->can_compose_messages() ) {
			echo '<div class="clms-admin-card">';
			echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Docente → estudiante', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Enviar mensaje directo', 'atora-lms' ) . '</h2></div></div>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="clms-admin-message-form">';
			wp_nonce_field( 'clms_send_internal_message' );
			echo '<input type="hidden" name="action" value="clms_send_internal_message">';
			echo '<p><label for="clms-msg-course"><strong>' . esc_html__( 'Curso', 'atora-lms' ) . '</strong></label><br>';
			echo '<select id="clms-msg-course" name="course_id">';
			echo '<option value="">' . esc_html__( 'Selecciona un curso', 'atora-lms' ) . '</option>';
			foreach ( $compose['courses'] as $course_id => $course_title ) {
				echo '<option value="' . esc_attr( $course_id ) . '">' . esc_html( $course_title ) . '</option>';
			}
			echo '</select></p>';
			echo '<p><label for="clms-msg-student"><strong>' . esc_html__( 'Estudiante', 'atora-lms' ) . '</strong></label><br>';
			echo '<select id="clms-msg-student" name="recipient_user_id">';
			echo '<option value="">' . esc_html__( 'Selecciona un estudiante', 'atora-lms' ) . '</option>';
			foreach ( $compose['students'] as $student_id => $student ) {
				$label = $student['label'];
				if ( ! empty( $student['meta'] ) ) {
					$label .= ' · ' . $student['meta'];
				}
				echo '<option value="' . esc_attr( $student_id ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select></p>';
			echo '<p><label for="clms-msg-recommendation"><strong>' . esc_html__( 'Tipo de recomendación', 'atora-lms' ) . '</strong></label><br>';
			echo '<select id="clms-msg-recommendation" name="recommendation_type">';
			echo '<option value="">' . esc_html__( 'Sin clasificación', 'atora-lms' ) . '</option>';
			echo '<option value="progress">' . esc_html__( 'Avance', 'atora-lms' ) . '</option>';
			echo '<option value="reinforcement">' . esc_html__( 'Refuerzo', 'atora-lms' ) . '</option>';
			echo '<option value="reminder">' . esc_html__( 'Recordatorio', 'atora-lms' ) . '</option>';
			echo '<option value="upsell">' . esc_html__( 'Venta adicional', 'atora-lms' ) . '</option>';
			echo '</select></p>';
			echo '<p><label for="clms-msg-title"><strong>' . esc_html__( 'Asunto', 'atora-lms' ) . '</strong></label><br><input id="clms-msg-title" type="text" name="message_title" class="regular-text" maxlength="120"></p>';
			echo '<p><label for="clms-msg-body"><strong>' . esc_html__( 'Mensaje', 'atora-lms' ) . '</strong></label><br><textarea id="clms-msg-body" name="message_body" rows="6"></textarea></p>';
			echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Enviar mensaje', 'atora-lms' ) . '</button></p>';
			echo '</form>';
			echo '</div>';
		}

		echo '<div class="clms-admin-card">';
		echo '<div class="clms-admin-section-head"><div><span class="clms-admin-kicker">' . esc_html__( 'Bandeja ATORA', 'atora-lms' ) . '</span><h2>' . esc_html__( 'Mensajes recientes', 'atora-lms' ) . ( $selected_thread ? ' · ' . esc_html( $this->resolve_thread_title_from_messages( $messages, $threads, $selected_thread ) ) : '' ) . '</h2></div></div>';

		if ( empty( $messages ) ) {
			echo '<p>' . esc_html__( 'No hay mensajes todavía. Aquí aparecerán avisos del sistema, seguimiento docente, IA y recomendaciones.', 'atora-lms' ) . '</p>';
		} else {
			echo '<div class="clms-admin-message-list">';

			foreach ( $messages as $message ) {
				$mark_url = '';
				if ( empty( $message['is_read'] ) ) {
					$mark_url = wp_nonce_url(
						add_query_arg(
							array(
								'page'              => 'clms-messages',
								'thread'            => $selected_thread,
								'clms_mark_message' => sanitize_text_field( (string) $message['id'] ),
							),
							admin_url( 'admin.php' )
						),
						'clms_mark_message_' . sanitize_text_field( (string) $message['id'] )
					);
				}

				echo '<article class="clms-admin-message-card' . ( empty( $message['is_read'] ) ? ' is-unread' : '' ) . '">';
				echo '<div class="clms-admin-message-head">';
				echo '<div>';
				echo '<div class="clms-admin-message-meta">';
				echo '<span class="clms-admin-message-badge">' . esc_html( $this->format_message_sender_label( $message['sender_type'] ?? 'system', $message['sender_name'] ?? '' ) ) . '</span>';
				if ( ! empty( $message['thread_label'] ) ) {
					echo '<span class="clms-admin-message-badge clms-admin-message-badge--soft">' . esc_html( $this->format_thread_type_label( $message['thread_type'] ?? 'general' ) . ' · ' . $message['thread_label'] ) . '</span>';
				}
				if ( ! empty( $message['recommendation_type'] ) ) {
					echo '<span class="clms-admin-message-badge clms-admin-message-badge--soft">' . esc_html( $this->format_recommendation_label( $message['recommendation_type'] ) ) . '</span>';
				}
				echo '<span class="clms-admin-message-date">' . esc_html( $this->format_admin_datetime( $message['created_at'] ?? '' ) ) . '</span>';
				echo '</div>';
				echo '<h3>' . esc_html( $message['title'] ?? '' ) . '</h3>';
				echo '</div>';
				if ( ! empty( $message['link'] ) ) {
					echo '<a class="button button-small" href="' . esc_url( $message['link'] ) . '">' . esc_html__( 'Abrir', 'atora-lms' ) . '</a>';
				}
				echo '</div>';
				echo '<p>' . esc_html( $message['message'] ?? '' ) . '</p>';
				echo '<div class="clms-admin-message-foot">';
				echo '<span>' . esc_html__( 'Tipo:', 'atora-lms' ) . ' ' . esc_html( $this->format_message_type_label( $message['message_type'] ?? '' ) ) . '</span>';
				if ( ! empty( $message['course_id'] ) ) {
					echo '<span>' . esc_html__( 'Curso:', 'atora-lms' ) . ' ' . esc_html( get_the_title( absint( $message['course_id'] ) ) ) . '</span>';
				}
				if ( ! empty( $message['lesson_id'] ) ) {
					echo '<span>' . esc_html__( 'Lección:', 'atora-lms' ) . ' ' . esc_html( get_the_title( absint( $message['lesson_id'] ) ) ) . '</span>';
				}
				if ( $mark_url ) {
					echo '<a href="' . esc_url( $mark_url ) . '">' . esc_html__( 'Marcar como leído', 'atora-lms' ) . '</a>';
				}
				echo '</div>';
				echo '</article>';
			}

			echo '</div>';
		}
		echo '</div>';
		echo '</div>';
	}

	public function render_gradebook_page() {
		if ( ! CLMS_Access::can_grade_submissions() ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$this->render_admin_styles();

		$course_id  = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
		$student_id = isset( $_GET['student_id'] ) ? absint( wp_unslash( $_GET['student_id'] ) ) : 0;
		$courses    = $this->get_courses();
		$student_search  = isset( $_GET['student_search'] ) ? sanitize_text_field( wp_unslash( $_GET['student_search'] ) ) : '';
		$activity_search = isset( $_GET['activity_search'] ) ? sanitize_text_field( wp_unslash( $_GET['activity_search'] ) ) : '';
		$status          = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$focus           = isset( $_GET['focus'] ) ? sanitize_key( wp_unslash( $_GET['focus'] ) ) : '';

		$gradebook_renderer = class_exists( 'CLMS_Gradebook_Renderer' ) ? new CLMS_Gradebook_Renderer() : null;
		if ( $gradebook_renderer && method_exists( $gradebook_renderer, 'render' ) ) {
			$rendered = $gradebook_renderer->render(
				array(
					'course_id'       => $course_id,
					'courses'         => $courses,
					'recalculated'    => ! empty( $_GET['recalculated'] ),
					'student_search'  => $student_search,
					'activity_search' => $activity_search,
					'status'          => $status,
					'focus'           => $focus,
					'group'           => isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : '',
					'cohort_id'       => isset( $_GET['cohort_id'] ) ? absint( wp_unslash( $_GET['cohort_id'] ) ) : 0,
				)
			);

			if ( $rendered ) {
				return;
			}
		}

		echo '<div class="wrap clms-admin-wrap">';
		echo '<h1>' . esc_html__( 'Libro de calificaciones', 'atora-lms' ) . '</h1>';
		echo '<p>' . esc_html__( 'Consulta el progreso y las calificaciones por curso.', 'atora-lms' ) . '</p>';

		if ( ! empty( $_GET['recalculated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Libro de calificaciones recalculado correctamente.', 'atora-lms' ) . '</p></div>';
		}

		echo '<div class="clms-admin-card">';
		$this->render_course_filter( $courses, $course_id );
		echo '</div>';

		if ( ! $course_id ) {
			echo '<div class="clms-admin-card"><p>' . esc_html__( 'Selecciona un curso para ver el libro de calificaciones.', 'atora-lms' ) . '</p></div>';
			echo '</div>';
			return;
		}

		if ( 'lm_course' !== get_post_type( $course_id ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Curso no válido.', 'atora-lms' ) . '</p></div>';
			echo '</div>';
			return;
		}

		echo '<div class="clms-admin-card">';
		$this->render_recalculate_button( $course_id );
		echo '</div>';

		$rows = $this->get_gradebook_rows( $course_id );

		if ( empty( $rows ) ) {
			echo '<div class="clms-admin-card"><p>' . esc_html__( 'No hay alumnos inscritos en este curso.', 'atora-lms' ) . '</p></div>';
			echo '</div>';
			return;
		}

		echo '<div class="clms-admin-card">';
		echo '<table class="widefat striped clms-admin-table">';
		echo '<thead>';
		echo '<tr>';
		echo '<th>' . esc_html__( 'Alumno', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Progreso', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Lecciones', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Prom. Quiz', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Prom. Tareas', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Prom. Final', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Fuentes', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Lecciones calificadas', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Actualizado', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Detalle', 'atora-lms' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $row['student_name'] ) . '</strong></td>';
			echo '<td>' . esc_html( $row['student_email'] ) . '</td>';
			echo '<td>' . esc_html( $row['progress_percent'] ) . '%</td>';
			echo '<td>' . esc_html( $row['completed_lessons'] ) . '/' . esc_html( $row['total_lessons'] ) . '</td>';
			echo '<td>' . esc_html( $row['quiz_average'] ) . '%</td>';
			echo '<td>' . esc_html( $row['assignment_average'] ) . '%</td>';
			echo '<td><strong>' . esc_html( $row['final_average'] ) . '%</strong></td>';
			echo '<td>' . esc_html( $row['source_breakdown'] ) . '</td>';
			echo '<td>' . esc_html( $row['graded_lessons'] ) . '</td>';
			echo '<td>' . esc_html( $row['updated_at'] ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $row['detail_url'] ) . '">' . esc_html__( 'Ver detalle', 'atora-lms' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';

		if ( $student_id ) {
			echo '<div class="clms-admin-card">';
			$this->render_gradebook_detail( $course_id, $student_id );
			echo '</div>';
		}

		echo '</div>';
	}

	protected function render_course_filter( $courses, $selected_course_id ) {
		echo '<form method="get" action="" class="clms-admin-filter">';
		echo '<input type="hidden" name="page" value="clms-gradebook">';

		echo '<label for="clms-course-id"><strong>' . esc_html__( 'Curso', 'atora-lms' ) . '</strong></label>';
		echo '<select name="course_id" id="clms-course-id">';
		echo '<option value="">' . esc_html__( 'Selecciona un curso', 'atora-lms' ) . '</option>';

		foreach ( $courses as $course_id => $course_title ) {
			echo '<option value="' . esc_attr( $course_id ) . '" ' . selected( $selected_course_id, $course_id, false ) . '>';
			echo esc_html( $course_title );
			echo '</option>';
		}

		echo '</select> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Ver libro de calificaciones', 'atora-lms' ) . '</button>';
		echo '</form>';
	}

	protected function render_recalculate_button( $course_id ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=clms_recalculate_course_gradebook&course_id=' . absint( $course_id ) ),
			'clms_recalculate_course_gradebook_' . absint( $course_id )
		);

		echo '<p>';
		echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Recalcular libro de calificaciones', 'atora-lms' ) . '</a>';
		echo '</p>';
	}

	public function handle_recalculate_gradebook() {
		if ( ! CLMS_Access::can_grade_submissions() ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			wp_die( esc_html__( 'Curso no válido.', 'atora-lms' ) );
		}

		check_admin_referer( 'clms_recalculate_course_gradebook_' . $course_id );

		$student_ids = CLMS_Helper::get_enrolled_student_ids( $course_id );
		$grading     = $this->get_grading_instance();

		if ( ! $grading || ! method_exists( $grading, 'calculate_and_store_course_grade' ) ) {
			wp_die( esc_html__( 'No se encontró CLMS_Grading.', 'atora-lms' ) );
		}

		foreach ( $student_ids as $student_id ) {
			$grading->calculate_and_store_course_grade( $student_id, $course_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'clms-gradebook',
					'course_id'    => $course_id,
					'recalculated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Duplica un curso completo (curso, lecciones y rúbricas vinculadas).
	 *
	 * @return void
	 */
	public function handle_duplicate_course(): void {
		$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			wp_die( esc_html__( 'Curso inválido.', 'atora-lms' ) );
		}

		check_admin_referer( 'clms_duplicate_course_' . $course_id );

		if ( ! class_exists( 'CLMS_Helper' ) || ! CLMS_Helper::user_can_manage_lms( $course_id ) ) {
			wp_die( esc_html__( 'Sin permisos para duplicar este curso.', 'atora-lms' ) );
		}

		$new_course_id = $this->duplicate_course( $course_id );

		if ( is_wp_error( $new_course_id ) ) {
			wp_die( esc_html( $new_course_id->get_error_message() ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post'           => absint( $new_course_id ),
					'action'         => 'edit',
					'clms_duplicated' => 1,
				),
				admin_url( 'post.php' )
			)
		);
		exit;
	}

	/**
	 * Crea una copia completa del curso con sus lecciones.
	 *
	 * @param int $course_id ID del curso fuente.
	 * @return int|WP_Error
	 */
	protected function duplicate_course( int $course_id ) {
		$course = get_post( $course_id );
		if ( ! $course || 'lm_course' !== $course->post_type ) {
			return new WP_Error( 'clms_duplicate_invalid_course', __( 'No se encontró el curso original.', 'atora-lms' ) );
		}

		$new_course_id = wp_insert_post(
			array(
				'post_type'    => 'lm_course',
				'post_title'   => sprintf( __( '%s (copia)', 'atora-lms' ), $course->post_title ),
				'post_content' => $course->post_content,
				'post_excerpt' => $course->post_excerpt,
				'post_status'  => 'draft',
				'post_author'  => get_current_user_id(),
				'menu_order'   => (int) $course->menu_order,
			),
			true
		);

		if ( is_wp_error( $new_course_id ) ) {
			return $new_course_id;
		}

		$this->copy_post_meta(
			$course_id,
			$new_course_id,
			array(
				'_clms_enrolled_users',
				'_clms_enrollment_dates',
				'_clms_course_program_ids',
				'_clms_access_link_hash',
				'_clms_access_link_expires_at',
				'_clms_link_access_password',
				'_clms_link_access_enabled',
			)
		);

		$thumb_id = get_post_thumbnail_id( $course_id );
		if ( $thumb_id ) {
			set_post_thumbnail( $new_course_id, $thumb_id );
		}

		$taxonomies = get_object_taxonomies( 'lm_course' );
		foreach ( $taxonomies as $taxonomy ) {
			$term_ids = wp_get_object_terms( $course_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $term_ids ) ) {
				wp_set_object_terms( $new_course_id, $term_ids, $taxonomy );
			}
		}

		$lesson_ids = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_course_lessons( $course_id ) : array();
		$lesson_ids = is_array( $lesson_ids ) ? array_values( array_filter( array_map( 'absint', $lesson_ids ) ) ) : array();

		$rubric_map = array(); // old rubric id => new rubric id

		foreach ( $lesson_ids as $lesson_id ) {
			$lesson = get_post( $lesson_id );
			if ( ! $lesson || 'lm_lesson' !== $lesson->post_type ) {
				continue;
			}

			$new_lesson_id = wp_insert_post(
				array(
					'post_type'    => 'lm_lesson',
					'post_title'   => $lesson->post_title,
					'post_content' => $lesson->post_content,
					'post_excerpt' => $lesson->post_excerpt,
					'post_status'  => 'draft',
					'post_author'  => get_current_user_id(),
					'menu_order'   => (int) $lesson->menu_order,
				),
				true
			);

			if ( is_wp_error( $new_lesson_id ) ) {
				continue;
			}

			$this->copy_post_meta(
				$lesson_id,
				$new_lesson_id,
				array(
					'_clms_submission_user_id',
					'_clms_submission_lesson_id',
					'_clms_submission_course_id',
					'_clms_submission_status',
					'_clms_submission_grade',
					'_clms_submission_feedback',
					'_clms_submission_files',
					'_clms_submission_comment',
				)
			);

			if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'set_course_id_for_lesson' ) ) {
				CLMS_Helper::set_course_id_for_lesson( $new_lesson_id, $new_course_id );
			} else {
				update_post_meta( $new_lesson_id, '_clms_course_id', $new_course_id );
			}

			$rubric_id = absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) );
			if ( $rubric_id && 'clms_rubric' === get_post_type( $rubric_id ) ) {
				if ( ! isset( $rubric_map[ $rubric_id ] ) ) {
					$rubric_map[ $rubric_id ] = $this->duplicate_rubric( $rubric_id );
				}
				if ( ! empty( $rubric_map[ $rubric_id ] ) ) {
					update_post_meta( $new_lesson_id, '_clms_rubric_id', absint( $rubric_map[ $rubric_id ] ) );
				}
			}

			$lesson_thumb_id = get_post_thumbnail_id( $lesson_id );
			if ( $lesson_thumb_id ) {
				set_post_thumbnail( $new_lesson_id, $lesson_thumb_id );
			}
		}

		if ( class_exists( 'CLMS_Helper' ) ) {
			CLMS_Helper::flush_runtime_cache( $new_course_id );
		}

		return absint( $new_course_id );
	}

	/**
	 * Duplica una rúbrica y retorna el nuevo ID.
	 *
	 * @param int $rubric_id ID de la rúbrica original.
	 * @return int
	 */
	protected function duplicate_rubric( int $rubric_id ): int {
		$rubric = get_post( $rubric_id );
		if ( ! $rubric || 'clms_rubric' !== $rubric->post_type ) {
			return 0;
		}

		$new_rubric_id = wp_insert_post(
			array(
				'post_type'    => 'clms_rubric',
				'post_title'   => sprintf( __( '%s (copia)', 'atora-lms' ), $rubric->post_title ),
				'post_content' => $rubric->post_content,
				'post_excerpt' => $rubric->post_excerpt,
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $new_rubric_id ) ) {
			return 0;
		}

		$this->copy_post_meta( $rubric_id, $new_rubric_id );

		$thumb_id = get_post_thumbnail_id( $rubric_id );
		if ( $thumb_id ) {
			set_post_thumbnail( $new_rubric_id, $thumb_id );
		}

		return absint( $new_rubric_id );
	}

	/**
	 * Copia metas de un post origen a uno destino, excluyendo claves indicadas.
	 *
	 * @param int   $from_id   Post origen.
	 * @param int   $to_id     Post destino.
	 * @param array $excluded  Claves excluidas.
	 * @return void
	 */
	protected function copy_post_meta( int $from_id, int $to_id, array $excluded = array() ): void {
		$all_meta = get_post_meta( $from_id );
		if ( ! is_array( $all_meta ) || empty( $all_meta ) ) {
			return;
		}

		foreach ( $all_meta as $meta_key => $values ) {
			$meta_key = (string) $meta_key;
			if ( '' === $meta_key ) {
				continue;
			}
			if ( in_array( $meta_key, $excluded, true ) ) {
				continue;
			}
			if ( '_edit_lock' === $meta_key || '_edit_last' === $meta_key || str_starts_with( $meta_key, '_wp_' ) ) {
				continue;
			}
			if ( ! is_array( $values ) ) {
				continue;
			}

			delete_post_meta( $to_id, $meta_key );
			foreach ( $values as $value ) {
				add_post_meta( $to_id, $meta_key, maybe_unserialize( $value ) );
			}
		}
	}

	protected function get_gradebook_rows( $course_id ) {
		$student_ids = CLMS_Helper::get_enrolled_student_ids( $course_id );
		$grading     = $this->get_grading_instance();

		if ( empty( $student_ids ) || ! $grading || ! method_exists( $grading, 'get_course_grade_summary' ) ) {
			return array();
		}

		$rows = array();

		foreach ( $student_ids as $student_id ) {
			$user = get_user_by( 'id', $student_id );

			if ( ! $user ) {
				continue;
			}

			$summary = $grading->get_course_grade_summary( $student_id, $course_id );

			$rows[] = array(
				'student_id'         => $student_id,
				'student_name'       => $user->display_name ? $user->display_name : $user->user_login,
				'student_email'      => $user->user_email,
				'completed_lessons'  => isset( $summary['completed_lessons'] ) ? absint( $summary['completed_lessons'] ) : 0,
				'total_lessons'      => isset( $summary['total_lessons'] ) ? absint( $summary['total_lessons'] ) : 0,
				'progress_percent'   => isset( $summary['progress_percent'] ) ? absint( $summary['progress_percent'] ) : 0,
				'quiz_average'       => isset( $summary['quiz_average'] ) ? absint( $summary['quiz_average'] ) : 0,
				'assignment_average' => isset( $summary['assignment_average'] ) ? absint( $summary['assignment_average'] ) : 0,
				'final_average'      => isset( $summary['final_average'] ) ? absint( $summary['final_average'] ) : 0,
				'source_breakdown'   => $this->format_source_breakdown( isset( $summary['source_breakdown'] ) ? $summary['source_breakdown'] : array() ),
				'graded_lessons'     => isset( $summary['graded_lessons'] ) ? absint( $summary['graded_lessons'] ) : 0,
				'updated_at'         => isset( $summary['updated_at'] ) ? sanitize_text_field( $summary['updated_at'] ) : '',
				'detail_url'         => add_query_arg(
					array(
						'page'      => 'clms-gradebook',
						'course_id' => absint( $course_id ),
						'student_id' => absint( $student_id ),
					),
					admin_url( 'admin.php' )
				),
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return strcasecmp( $a['student_name'], $b['student_name'] );
			}
		);

		return $rows;
	}

	protected function render_gradebook_detail( $course_id, $student_id ) {
		$course_id  = absint( $course_id );
		$student_id = absint( $student_id );
		$user       = $student_id ? get_user_by( 'id', $student_id ) : false;
		$assessment = clms_core('CLMS_Assessment_Engine');
		$grading    = $this->get_grading_instance();

		if ( ! $course_id || ! $student_id || ! $user || ! $assessment || ! method_exists( $assessment, 'build_course_gradebook' ) ) {
			echo '<p>' . esc_html__( 'No se pudo cargar el detalle del estudiante.', 'atora-lms' ) . '</p>';
			return;
		}

		$gradebook = $assessment->build_course_gradebook( $student_id, $course_id );
		$summary   = isset( $gradebook['summary'] ) && is_array( $gradebook['summary'] ) ? $gradebook['summary'] : array();
		$entries   = isset( $gradebook['entries'] ) && is_array( $gradebook['entries'] ) ? $gradebook['entries'] : array();

		echo '<h2>' . sprintf( esc_html__( 'Detalle de %s', 'atora-lms' ), esc_html( $user->display_name ? $user->display_name : $user->user_login ) ) . '</h2>';
		echo '<p>' . esc_html__( 'Vista detallada de malla de calificaciones y auditoría por actividad.', 'atora-lms' ) . '</p>';
		echo '<p class="clms-admin-note">' . esc_html__( 'Tip: abre SpeedGrade desde cada fila para revisar y calificar sin salir del flujo.', 'atora-lms' ) . '</p>';

		echo '<div class="clms-admin-metrics">';
		echo '<div class="clms-admin-metric"><span>' . esc_html__( 'Progreso', 'atora-lms' ) . '</span><strong>' . esc_html( absint( $summary['progress_percent'] ?? 0 ) ) . '%</strong></div>';
		echo '<div class="clms-admin-metric"><span>' . esc_html__( 'Promedio final', 'atora-lms' ) . '</span><strong>' . esc_html( absint( $summary['final_average'] ?? 0 ) ) . '%</strong></div>';
		echo '<div class="clms-admin-metric"><span>' . esc_html__( 'Tareas calificadas', 'atora-lms' ) . '</span><strong>' . esc_html( absint( $summary['graded_lessons'] ?? 0 ) ) . '</strong></div>';
		echo '<div class="clms-admin-metric"><span>' . esc_html__( 'Fuentes', 'atora-lms' ) . '</span><strong>' . esc_html( $this->format_source_breakdown( $summary['source_breakdown'] ?? array() ) ) . '</strong></div>';
		echo '</div>';

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No hay actividades registradas para este estudiante en el curso.', 'atora-lms' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped clms-admin-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Lección', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Actividad', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Estado', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Nota', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Detalle', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Acción', 'atora-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$submission_id = isset( $entry['submission_id'] ) ? absint( $entry['submission_id'] ) : 0;
			$audit_log     = ( $submission_id && method_exists( $assessment, 'get_submission_audit_log' ) ) ? $assessment->get_submission_audit_log( $submission_id ) : array();
			$engine_label  = trim( (string) ( $entry['provider'] ?? '' ) . ' ' . (string) ( $entry['model_version'] ?? '' ) );
			$confidence    = __( '—', 'atora-lms' );

			if ( '' !== (string) ( $entry['confidence'] ?? '' ) ) {
				$confidence = round( (float) $entry['confidence'] * 100 ) . '%';
				if ( '' !== (string) ( $entry['raw_confidence'] ?? '' ) ) {
					$confidence .= ' (' . sprintf( __( 'crudo %s%%', 'atora-lms' ), round( (float) $entry['raw_confidence'] * 100 ) ) . ')';
				}
				if ( '' !== (string) ( $entry['confidence_threshold'] ?? '' ) ) {
					$confidence .= ' / ' . sprintf( __( 'umbral %s%%', 'atora-lms' ), round( (float) $entry['confidence_threshold'] * 100 ) );
				}
			}

			$action = __( '—', 'atora-lms' );
			if ( $submission_id && $grading && method_exists( $grading, 'get_speedgrade_url' ) ) {
				$action = '<a class="button button-small clms-admin-btn-speedgrade" href="' . esc_url( $grading->get_speedgrade_url( $submission_id, add_query_arg( array( 'page' => 'clms-gradebook', 'course_id' => $course_id, 'student_id' => $student_id ), admin_url( 'admin.php' ) ) ) ) . '">' . esc_html__( 'SpeedGrade', 'atora-lms' ) . '</a>';
			}

			$detail_rows = array(
				array( __( 'Modo', 'atora-lms' ), $this->format_source_label( (string) ( $entry['evaluation_mode'] ?? '' ) ) ),
				array( __( 'Fuente', 'atora-lms' ), $this->format_source_label( (string) ( $entry['grade_source'] ?? '' ) ) ),
				array( __( 'Confianza', 'atora-lms' ), $confidence ),
				array( __( 'Motor', 'atora-lms' ), $engine_label ? $engine_label : __( '—', 'atora-lms' ) ),
				array( __( 'Sobrescritura', 'atora-lms' ), ! empty( $entry['manual_override'] ) ? __( 'Sí', 'atora-lms' ) : __( 'No', 'atora-lms' ) ),
			);

			$detail_html = '<details class="clms-admin-details"><summary>' . esc_html__( 'Ver detalle', 'atora-lms' ) . '</summary>';
			$detail_html .= '<div class="clms-admin-detail-grid">';
			foreach ( $detail_rows as $row ) {
				if ( 'Confianza' === $row[0] && '—' === (string) $row[1] ) {
					continue;
				}
				$detail_html .= '<div class="clms-admin-detail-row"><span>' . esc_html( $row[0] ) . '</span><strong>' . esc_html( $row[1] ) . '</strong></div>';
			}
			$detail_html .= '</div>';
			if ( empty( $audit_log ) ) {
				$detail_html .= '<p class="clms-admin-note">' . esc_html__( 'Sin auditoría registrada.', 'atora-lms' ) . '</p>';
			} else {
				$detail_html .= '<div class="clms-admin-audit-list">';
				foreach ( $audit_log as $event ) {
					$event = is_array( $event ) ? $event : array();
					$parts = array();
					if ( ! empty( $event['status'] ) ) {
						$parts[] = sprintf( __( 'Estado: %s', 'atora-lms' ), $event['status'] );
					}
					if ( '' !== (string) ( $event['grade'] ?? '' ) ) {
						$parts[] = sprintf( __( 'Nota: %s', 'atora-lms' ), absint( $event['grade'] ) );
					}
					if ( '' !== (string) ( $event['confidence'] ?? '' ) ) {
						$parts[] = sprintf( __( 'Conf.: %s%%', 'atora-lms' ), round( (float) $event['confidence'] * 100 ) );
					}
					if ( ! empty( $event['model_version'] ) ) {
						$parts[] = sprintf( __( 'Modelo: %s', 'atora-lms' ), $event['model_version'] );
					}

					$detail_html .= '<div class="clms-admin-audit-item">';
					$detail_html .= '<strong>' . esc_html( ucfirst( str_replace( '_', ' ', (string) ( $event['source'] ?? __( 'evento', 'atora-lms' ) ) ) ) ) . '</strong><br>';
					$detail_html .= '<span>' . esc_html( sanitize_text_field( (string) ( $event['recorded_at'] ?? '' ) ) ) . '</span><br>';
					$detail_html .= '<span>' . esc_html( ! empty( $parts ) ? implode( ' | ', $parts ) : __( 'Evento registrado.', 'atora-lms' ) ) . '</span>';
					if ( ! empty( $event['note'] ) ) {
						$detail_html .= '<br><span>' . esc_html( (string) $event['note'] ) . '</span>';
					}
					$detail_html .= '</div>';
				}
				$detail_html .= '</div>';
			}
			$detail_html .= '</details>';

			echo '<tr>';
			echo '<td><strong>' . esc_html( $entry['lesson_title'] ?? '' ) . '</strong></td>';
			echo '<td>' . esc_html( ucfirst( (string) ( $entry['activity_type'] ?? __( 'lectura', 'atora-lms' ) ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $entry['submission_status'] ?? __( '—', 'atora-lms' ) ) ) . '</td>';
			echo '<td>' . esc_html( '' !== (string) ( $entry['assignment_grade'] ?? '' ) ? absint( $entry['assignment_grade'] ) . '%' : __( '—', 'atora-lms' ) ) . '</td>';
			echo '<td>' . $detail_html . '</td>';
			echo '<td>' . $action . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

}
