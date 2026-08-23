<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Enrollment_Manager_Access_Enrollment_Trait {
	public function create_access_link( $course_id, $mode = self::LINK_FREE, $password = '', $created_by = 0 ) {
		global $wpdb;

		$course_id  = absint( $course_id );
		$created_by = absint( $created_by ) ?: get_current_user_id();
		$mode       = in_array( $mode, array( self::LINK_FREE, self::LINK_PASSWORD, self::LINK_REGISTER ), true )
			? $mode : self::LINK_FREE;

		// Eliminar enlace anterior si existe.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table()} WHERE course_id = %d AND access_mode IN (%s, %s, %s)",
				$course_id,
				self::LINK_FREE,
				self::LINK_PASSWORD,
				self::LINK_REGISTER
			)
		);

		$token = $this->generate_token( 24 );

		$wpdb->insert(
			$this->table(),
			array(
				'token'           => $token,
				'course_id'       => $course_id,
				'access_mode'     => $mode,
				'invited_email'   => '',
				'access_password' => $mode === self::LINK_PASSWORD ? wp_hash_password( $password ) : '',
				'created_by'      => $created_by,
				'max_uses'        => 0,
				'used_count'      => 0,
				'expires_at'      => null,
				'status'          => 'active',
				'created_at'      => current_time( 'mysql', 1 ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		// Guardar referencia en el curso para localización rápida.
		update_post_meta( $course_id, '_clms_access_link_token', $token );
		update_post_meta( $course_id, '_clms_access_link_mode',  $mode );
		update_post_meta( $course_id, '_clms_access_link_enabled', '1' );

		return array(
			'token' => $token,
			'url'   => $this->get_join_url( $token ),
			'mode'  => $mode,
		);
	}

	/**
	 * Obtiene los datos del enlace de acceso de un curso.
	 */
	public function get_access_link_data( $course_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE course_id = %d AND access_mode IN (%s, %s, %s) AND status = %s ORDER BY created_at DESC LIMIT 1",
				absint( $course_id ),
				self::LINK_FREE,
				self::LINK_PASSWORD,
				self::LINK_REGISTER,
				'active'
			)
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'token' => $row->token,
			'mode'  => $row->access_mode,
			'url'   => $this->get_join_url( $row->token ),
			'uses'  => (int) $row->used_count,
		);
	}

	/**
	 * Regenera el token de acceso de un curso (invalida el anterior).
	 */
	public function regenerate_access_link( $course_id, $mode = null, $password = '', $created_by = 0 ) {
		if ( ! $mode ) {
			$mode = get_post_meta( absint( $course_id ), '_clms_access_link_mode', true ) ?: self::LINK_FREE;
		}

		return $this->create_access_link( $course_id, $mode, $password, $created_by );
	}

	/**
	 * Canjea un enlace de acceso y matricula al usuario.
	 *
	 * @param string $token            Token del enlace.
	 * @param int    $user_id          Usuario que canjea.
	 * @param string $password_attempt Contraseña ingresada (si mode = 'password').
	 * @return true|WP_Error
	 */
	public function redeem_access_link( $token, $user_id, $password_attempt = '' ) {
		global $wpdb;

		$token   = sanitize_text_field( $token );
		$user_id = absint( $user_id );

		if ( ! $token || ! $user_id ) {
			return new WP_Error( 'invalid_params', __( 'Parámetros inválidos.', 'atora-lms' ) );
		}

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
			return new WP_Error( 'invalid_link', __( 'El enlace no es válido o ha sido desactivado.', 'atora-lms' ) );
		}

		if ( $row->access_mode === self::LINK_PASSWORD ) {
			// PT-3 (6.5.5): wp_check_password() por sí solo no limita
			// intentos — un usuario autenticado con acceso al link podía
			// probar contraseñas indefinidamente. Contador por
			// usuario+token (nunca solo por IP, para no bloquear a otros
			// usuarios detrás de la misma IP ni depender de una señal
			// falsificable): 5 intentos fallidos por 15 minutos, se
			// limpia en el primer acierto.
			//
			// PT-4 (6.5.9): el par peek()-luego-consume() introducido en
			// 6.5.8 tenía una ventana de carrera real — varias
			// solicitudes concurrentes podían leer el mismo contador
			// (p.ej. 4) antes de que ninguna lo incrementara, permitiendo
			// más intentos efectivos de contraseña que el límite nominal
			// de 5. Se invierte el orden: se reserva el cupo con
			// consume() ANTES de evaluar la contraseña (atómico por
			// bloqueo de fila InnoDB, sin lectura previa separada); si no
			// queda cupo, se rechaza sin siquiera llamar a
			// wp_check_password(); si la contraseña resulta correcta, se
			// libera inmediatamente con reset() para no penalizar un
			// acierto legítimo. Fail-closed: consume() con fail_open=false
			// rechaza si el backend del limiter falla.
			$rl_scope      = 'enrollment_access_password';
			$rl_identifier = $user_id . '|' . $token;
			$rl_window     = 15 * MINUTE_IN_SECONDS;

			if ( ! \ATORA_Rate_Limiter::consume( $rl_scope, $rl_identifier, 5, $rl_window, false ) ) {
				return new WP_Error( 'too_many_attempts', __( 'Demasiados intentos. Intenta de nuevo más tarde.', 'atora-lms' ) );
			}

			if ( ! wp_check_password( $password_attempt, $row->access_password ) ) {
				return new WP_Error( 'wrong_password', __( 'Contraseña incorrecta.', 'atora-lms' ) );
			}

			\ATORA_Rate_Limiter::reset( $rl_scope, $rl_identifier, $rl_window );
		}

		if ( class_exists( 'CLMS_Helper' ) && CLMS_Helper::user_is_enrolled_in_course( $user_id, $row->course_id ) ) {
			return true;
		}

		$enrolled = class_exists( 'CLMS_Helper' )
			? CLMS_Helper::enroll_user_in_course( $user_id, $row->course_id )
			: false;

		if ( false === $enrolled ) {
			return new WP_Error( 'enroll_failed', __( 'No se pudo completar la matrícula.', 'atora-lms' ) );
		}

		$wpdb->update(
			$this->table(),
			array( 'used_count' => (int) $row->used_count + 1 ),
			array( 'id' => $row->id ),
			array( '%d' ),
			array( '%d' )
		);

		do_action( 'clms_user_enrolled_via_link', $user_id, $row->course_id, $row->access_mode, $token );

		return true;
	}

	// =========================================================================
	// MÉTODO 4 — MANUAL Y CSV
	// =========================================================================

	/**
	 * Matricula un usuario por email o username.
	 *
	 * @param string $identifier     Email o login del usuario.
	 * @param int    $course_id      ID del curso.
	 * @param bool   $create_missing Si true, crea una cuenta si no existe.
	 * @param array  $profile_data   Datos opcionales de perfil/contacto.
	 * @return array  ['success', 'user_id', 'user_email', 'display_name', 'message', 'created']
	 */
	public function enroll_by_email( $identifier, $course_id, $create_missing = false, $profile_data = array() ) {
		$identifier   = sanitize_text_field( $identifier );
		$course_id    = absint( $course_id );
		$profile_data = is_array( $profile_data ) ? $this->sanitize_student_profile_data( $profile_data ) : array();

		if ( ! $identifier || ! $course_id ) {
			return array( 'success' => false, 'message' => __( 'Identificador o curso inválido.', 'atora-lms' ) );
		}

		$user    = false;
		$created = false;

		// Buscar por email.
		if ( is_email( $identifier ) ) {
			$user = get_user_by( 'email', $identifier );
		}

		// Buscar por login.
		if ( ! $user ) {
			$user = get_user_by( 'login', $identifier );
		}

		// Crear si no existe y se permite.
		if ( ! $user && $create_missing && is_email( $identifier ) ) {
			$student_role = get_role( 'lms_student' ) ? 'lms_student' : 'subscriber';
			$password = wp_generate_password( 12, false );
			$username = sanitize_user( strstr( $identifier, '@', true ), true );

			// Asegurar username único.
			$base = $username;
			$i    = 1;
			while ( username_exists( $username ) ) {
				$username = $base . $i;
				$i++;
			}

			$user_id = wp_create_user( $username, $password, $identifier );

			if ( is_wp_error( $user_id ) ) {
				return array( 'success' => false, 'message' => $user_id->get_error_message() );
			}

			wp_update_user( array( 'ID' => $user_id, 'role' => $student_role ) );
			$user    = get_userdata( $user_id );
			$created = true;

			// Enviar credenciales al nuevo usuario.
			$this->send_welcome_email( $user, $password, $course_id );
		}

		if ( ! $user ) {
			return array( 'success' => false, 'message' => "No se encontró ningún usuario con '{$identifier}'." );
		}

		if ( ! empty( $profile_data ) ) {
			$this->persist_student_profile_data( (int) $user->ID, $profile_data );
			$user = get_userdata( (int) $user->ID );
		}

		if ( class_exists( 'CLMS_Helper' ) && CLMS_Helper::user_is_enrolled_in_course( $user->ID, $course_id ) ) {
			return array(
				'success'      => true,
				'user_id'      => $user->ID,
				'user_email'   => $user->user_email,
				'display_name' => $user->display_name,
				'message'      => __( 'Ya estaba matriculado.', 'atora-lms' ),
				'created'      => false,
			);
		}

		$enrolled = class_exists( 'CLMS_Helper' )
			? CLMS_Helper::enroll_user_in_course( $user->ID, $course_id )
			: false;

		if ( false === $enrolled ) {
			return array( 'success' => false, 'message' => __( 'Error interno al matricular.', 'atora-lms' ) );
		}

		// Establecer expiración si el curso tiene producto vinculado con duración limitada.
		if ( class_exists( 'CLMS_WooCommerce' ) ) {
			$product_id = CLMS_WooCommerce::get_course_product_id( $course_id );

			if ( $product_id ) {
				$access_map  = CLMS_WooCommerce::get_product_access_map( $product_id );
				$access_days = isset( $access_map['access_days'] ) ? absint( $access_map['access_days'] ) : 0;

				if ( $access_days > 0 ) {
					$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $access_days * DAY_IN_SECONDS ) );

					if ( method_exists( 'CLMS_Helper', 'set_user_course_access_expiration' ) ) {
						CLMS_Helper::set_user_course_access_expiration( $user->ID, $course_id, $expires_at );
					}

					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log(
							sprintf(
								'[CLMS] Expiración establecida para usuario #%d en curso #%d: %s (%d días)',
								$user->ID,
								$course_id,
								$expires_at,
								$access_days
							)
						);
					}
				}
			}
		}

		do_action( 'clms_user_enrolled_manually', $user->ID, $course_id, get_current_user_id() );

		return array(
			'success'      => true,
			'user_id'      => $user->ID,
			'user_email'   => $user->user_email,
			'display_name' => $user->display_name,
			'message'      => $created ? __( 'Usuario creado y matriculado.', 'atora-lms' ) : __( 'Matriculado correctamente.', 'atora-lms' ),
			'created'      => $created,
		);
	}

	/**
	 * Cuando un admin cambia el rol de un usuario a lms_student desde
	 * el perfil de WP y el curso tiene un meta _clms_default_course_id,
	 * lo enrola automáticamente.
	 *
	 * También sirve para que al crear un usuario desde Usuarios → Añadir nuevo
	 * con rol lms_student se registre la matrícula si hay un curso por defecto.
	 *
	 * @param int    $user_id   ID del usuario.
	 * @param string $role      Nuevo rol asignado.
	 * @param array  $old_roles Roles anteriores del usuario.
	 */
	public function maybe_sync_enrollment_on_role_change( $user_id, $role, $old_roles ) {
		if ( 'lms_student' !== $role ) {
			return;
		}

		if ( ! class_exists( 'CLMS_Helper' ) ) {
			return;
		}

		// Si el usuario ya tiene cursos inscritos, no interferir.
		$existing = ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $user_id ) );
		if ( ! empty( $existing ) ) {
			return;
		}

		// Buscar cursos marcados como "auto-enroll" (_clms_auto_enroll = 1).
		$auto_enroll_courses = get_posts( array(
			'post_type'      => 'lm_course',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_clms_auto_enroll',
					'value' => '1',
				),
			),
		) );

		foreach ( $auto_enroll_courses as $course_id ) {
			if ( ! CLMS_Helper::user_is_enrolled_in_course( $user_id, $course_id ) ) {
				CLMS_Helper::enroll_user_in_course( $user_id, $course_id );
				do_action( 'clms_user_enrolled_manually', $user_id, $course_id, 0 );
			}
		}
	}

	/**
	 * Desmatricula un usuario de un curso.
	 */
	public function unenroll( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id ) {
			return false;
		}

		// Eliminar del user meta.
		$enrolled = (array) get_user_meta( $user_id, CLMS_Helper::USER_ENROLLED_META, true );
		$enrolled = array_diff( $enrolled, array( $course_id ) );
		update_user_meta( $user_id, CLMS_Helper::USER_ENROLLED_META, array_values( $enrolled ) );

		// Eliminar del course meta.
		$users = (array) get_post_meta( $course_id, CLMS_Helper::COURSE_ENROLLED_META, true );
		$users = array_diff( $users, array( $user_id ) );
		update_post_meta( $course_id, CLMS_Helper::COURSE_ENROLLED_META, array_values( $users ) );

		do_action( 'clms_user_unenrolled', $user_id, $course_id );

		return true;
	}

	/**
	 * Procesa un CSV de matriculación masiva.
	 *
	 * Formato CSV esperado (cabecera opcional):
	 * email, nombre, apellido, whatsapp, telegram, telefono, pais, ciudad, estado, sexo, edad
	 *
	 * @param string $file_path     Ruta temporal del archivo CSV.
	 * @param int    $course_id     ID del curso.
	 * @param bool   $create_missing  Crear usuarios que no existan.
	 * @param bool   $notify_existing Notificar por email a usuarios existentes recién matriculados.
	 * @return array  ['enrolled', 'skipped', 'created', 'notified_existing', 'errors']
	 */
	public function process_csv( $file_path, $course_id, $create_missing = true, $notify_existing = false ) {
		$result = array(
			'enrolled'          => 0,
			'skipped'           => 0,
			'created'           => 0,
			'notified_existing' => 0,
			'errors'            => array(),
		);

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			$result['errors'][] = 'Archivo no encontrado o no legible.';
			return $result;
		}

		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $handle ) {
			$result['errors'][] = 'No se pudo abrir el archivo.';
			return $result;
		}

		$delimiter   = $this->detect_csv_delimiter( $file_path );
		$line_number = 0;
		$header_map  = array();

		while ( ( $row = fgetcsv( $handle, 1000, $delimiter, '"', '\\' ) ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$line_number++;

			if ( empty( $row ) || ! is_array( $row ) ) {
				continue;
			}

			if ( 1 === $line_number ) {
				$detected_map = $this->detect_student_csv_header_map( $row );
				if ( ! empty( $detected_map['email'] ) ) {
					$header_map = $detected_map;
					continue;
				}
			}

			$email_raw = $this->get_student_csv_column_value( $row, $header_map, 'email', 0 );
			$email     = sanitize_email( $email_raw );

			if ( '' === $email_raw ) {
				continue;
			}
			if ( ! is_email( $email ) ) {
				$result['errors'][] = "Línea {$line_number}: '{$email}' no es un email válido.";
				continue;
			}

			$profile_data = $this->sanitize_student_profile_data(
				array(
					'first_name' => $this->get_student_csv_column_value( $row, $header_map, 'first_name', 1 ),
					'last_name'  => $this->get_student_csv_column_value( $row, $header_map, 'last_name', 2 ),
					'whatsapp'   => $this->get_student_csv_column_value( $row, $header_map, 'whatsapp', 3 ),
					'telegram'   => $this->get_student_csv_column_value( $row, $header_map, 'telegram', 4 ),
					'phone'      => $this->get_student_csv_column_value( $row, $header_map, 'phone', 5 ),
					'country'    => $this->get_student_csv_column_value( $row, $header_map, 'country_code', 6 ),
					'city'       => $this->get_student_csv_column_value( $row, $header_map, 'city', 7 ),
					'state'      => $this->get_student_csv_column_value( $row, $header_map, 'state', 8 ),
					'sex'        => $this->get_student_csv_column_value( $row, $header_map, 'sex', 9 ),
					'age'        => $this->get_student_csv_column_value( $row, $header_map, 'age', 10 ),
				)
			);

			$enroll = $this->enroll_by_email( $email, $course_id, $create_missing, $profile_data );

			if ( ! $enroll['success'] ) {
				$result['errors'][] = "Línea {$line_number} ({$email}): {$enroll['message']}";
				continue;
			}

			if ( strpos( $enroll['message'], 'Ya estaba' ) !== false ) {
				$result['skipped']++;
			} else {
				$result['enrolled']++;
				if ( $enroll['created'] ) {
					$result['created']++;
				} elseif ( $notify_existing ) {
					$enrolled_user = get_userdata( absint( $enroll['user_id'] ?? 0 ) );
					if ( $this->send_existing_enrollment_email( $enrolled_user, $course_id ) ) {
						$result['notified_existing']++;
					}
				}
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return $result;
	}

	/**
	 * Detecta delimitador CSV usando la primera línea del archivo.
	 *
	 * @param string $file_path Ruta del CSV.
	 * @return string
	 */
	private function detect_csv_delimiter( $file_path ) {
		$file_path = (string) $file_path;
		if ( '' === $file_path || ! is_readable( $file_path ) ) {
			return ',';
		}

		$line = '';
		$h    = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $h ) {
			$line = (string) fgets( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		if ( '' === $line ) {
			return ',';
		}

		$candidates = array( ';', ',', "\t", '|' );
		$best       = ',';
		$best_count = 0;
		foreach ( $candidates as $candidate ) {
			$count = substr_count( $line, $candidate );
			if ( $count > $best_count ) {
				$best       = $candidate;
				$best_count = $count;
			}
		}

		return $best_count > 0 ? $best : ',';
	}

	/**
	 * Detecta mapeo de cabecera de CSV de estudiantes.
	 *
	 * @param array $row Fila de cabecera.
	 * @return array
	 */
	private function detect_student_csv_header_map( $row ) {
		$aliases = array(
			'email'        => array( 'email', 'correo', 'correo_electronico', 'mail', 'e_mail' ),
			'first_name'   => array( 'nombre', 'nombres', 'first_name', 'firstname' ),
			'last_name'    => array( 'apellido', 'apellidos', 'aellidos', 'last_name', 'lastname' ),
			'whatsapp'     => array( 'whatsapp', 'wa' ),
			'telegram'     => array( 'telegram', 'telegram_user', 'telegram_username' ),
			'phone'        => array( 'telefono', 'phone', 'movil', 'celular' ),
			'country_code' => array( 'pais', 'country', 'country_code', 'countrycode' ),
			'city'         => array( 'ciudad', 'city' ),
			'state'        => array( 'estado', 'state', 'provincia', 'region' ),
			'sex'          => array( 'sexo', 'sex', 'genero', 'gender' ),
			'age'          => array( 'edad', 'age' ),
		);
		$map     = array();

		foreach ( (array) $row as $index => $raw_label ) {
			$label = strtolower( trim( sanitize_text_field( (string) $raw_label ) ) );
			if ( '' === $label ) {
				continue;
			}
			$label = str_replace(
				array( 'á', 'é', 'í', 'ó', 'ú', 'ñ', ' ', '-', '.' ),
				array( 'a', 'e', 'i', 'o', 'u', 'n', '_', '_', '_' ),
				$label
			);
			foreach ( $aliases as $canonical => $allowed ) {
				if ( in_array( $label, $allowed, true ) ) {
					$map[ $canonical ] = (int) $index;
					break;
				}
			}
		}

		return $map;
	}

	/**
	 * Obtiene una columna de fila CSV usando cabecera o fallback posicional.
	 *
	 * @param array  $row            Fila CSV.
	 * @param array  $header_map     Mapeo cabecera.
	 * @param string $canonical      Clave canónica.
	 * @param int    $fallback_index Índice fallback.
	 * @return string
	 */
	private function get_student_csv_column_value( $row, $header_map, $canonical, $fallback_index = 0 ) {
		$row            = is_array( $row ) ? $row : array();
		$header_map     = is_array( $header_map ) ? $header_map : array();
		$canonical      = sanitize_key( (string) $canonical );
		$fallback_index = absint( $fallback_index );

		if ( isset( $header_map[ $canonical ] ) ) {
			$idx = absint( $header_map[ $canonical ] );
			return isset( $row[ $idx ] ) ? trim( (string) $row[ $idx ] ) : '';
		}

		return isset( $row[ $fallback_index ] ) ? trim( (string) $row[ $fallback_index ] ) : '';
	}

	/**
	 * Sanitiza y normaliza datos de perfil/contacto de estudiante.
	 *
	 * @param array $data Datos de entrada.
	 * @return array
	 */
	private function sanitize_student_profile_data( $data ) {
		$data = is_array( $data ) ? $data : array();

		$first_name = sanitize_text_field( (string) ( $data['first_name'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $data['last_name'] ?? '' ) );
		$phone      = sanitize_text_field( (string) ( $data['phone'] ?? '' ) );
		$whatsapp   = sanitize_text_field( (string) ( $data['whatsapp'] ?? '' ) );
		$telegram   = sanitize_text_field( (string) ( $data['telegram'] ?? '' ) );
		$country    = strtoupper( sanitize_text_field( (string) ( $data['country_code'] ?? ( $data['country'] ?? '' ) ) ) );
		$city       = sanitize_text_field( (string) ( $data['city'] ?? '' ) );
		$state      = sanitize_text_field( (string) ( $data['state'] ?? '' ) );
		$sex        = sanitize_key( (string) ( $data['sex'] ?? '' ) );
		$age        = absint( $data['age'] ?? 0 );

		$country = preg_replace( '/[^A-Z0-9_]/', '', $country );
		if ( ! is_string( $country ) ) {
			$country = '';
		}
		if ( strlen( $country ) > 5 ) {
			$country = substr( $country, 0, 5 );
		}
		$allowed_sexes = array( 'femenino', 'masculino', 'no_binario', 'prefiero_no_decir' );
		if ( '' !== $sex && ! in_array( $sex, $allowed_sexes, true ) ) {
			$sex = '';
		}
		if ( $age > 120 ) {
			$age = 120;
		}

		if ( '' === $phone && '' !== $whatsapp ) {
			$phone = $whatsapp;
		}
		if ( '' === $whatsapp && '' !== $phone ) {
			$whatsapp = $phone;
		}

		$normalized = array(
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'phone'        => $phone,
			'whatsapp'     => $whatsapp,
			'telegram'     => $telegram,
			'country_code' => $country,
			'city'         => $city,
			'state'        => $state,
			'sex'          => $sex,
			'age'          => $age > 0 ? (string) $age : '',
		);

		return array_filter(
			$normalized,
			static function ( $value ) {
				return '' !== (string) $value;
			}
		);
	}

	/**
	 * Persiste datos de contacto de estudiante en user_meta y sincroniza CRM.
	 *
	 * @param int   $user_id      Usuario.
	 * @param array $profile_data Datos sanitizados de perfil.
	 * @return void
	 */
	private function persist_student_profile_data( $user_id, $profile_data ) {
		$user_id      = absint( $user_id );
		$profile_data = $this->sanitize_student_profile_data( $profile_data );

		if ( ! $user_id || empty( $profile_data ) ) {
			return;
		}

		$first_name = isset( $profile_data['first_name'] ) ? (string) $profile_data['first_name'] : '';
		$last_name  = isset( $profile_data['last_name'] ) ? (string) $profile_data['last_name'] : '';
		if ( '' !== $first_name || '' !== $last_name ) {
			$update = array( 'ID' => $user_id );
			if ( '' !== $first_name ) {
				$update['first_name'] = $first_name;
			}
			if ( '' !== $last_name ) {
				$update['last_name'] = $last_name;
			}
			$display_name = trim( $first_name . ' ' . $last_name );
			if ( '' !== $display_name ) {
				$update['display_name'] = $display_name;
				$update['nickname']     = $display_name;
			}
			wp_update_user( $update );
		}

		$meta_map = array(
			'phone'        => 'atora_phone',
			'whatsapp'     => 'atora_whatsapp',
			'telegram'     => 'atora_telegram',
			'country_code' => 'atora_country_code',
			'city'         => 'atora_city',
			'state'        => 'atora_state',
			'sex'          => 'atora_sex',
			'age'          => 'atora_age',
		);
		foreach ( $meta_map as $key => $meta_key ) {
			if ( isset( $profile_data[ $key ] ) ) {
				update_user_meta( $user_id, $meta_key, $profile_data[ $key ] );
			}
		}

		if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'upsert_contact' ) ) {
			$user = get_userdata( $user_id );
			\ATORA\CRM\CRM::upsert_contact(
				array(
					'user_id'  => $user_id,
					'email'    => $user ? (string) $user->user_email : '',
					'name'     => $user ? (string) $user->display_name : '',
					'phone'    => (string) ( $profile_data['phone'] ?? '' ),
					'whatsapp' => (string) ( $profile_data['whatsapp'] ?? '' ),
					'country'  => (string) ( $profile_data['country_code'] ?? '' ),
					'city'     => (string) ( $profile_data['city'] ?? '' ),
					'source'   => 'registration',
				)
			);
		}
	}

	// =========================================================================
	// FRONTEND — HANDLER DE MATRICULACIÓN
	// =========================================================================

	/**
	 * Registra los query vars necesarios.
	 */
}
