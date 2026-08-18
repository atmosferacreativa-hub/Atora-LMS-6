<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Enrollment_Manager_Invitations_Trait {
	public function maybe_install_db() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install_db();
		}
	}

	/**
	 * Garantiza que la tabla de invitaciones existe antes de cualquier operación.
	 *
	 * Flujo:
	 *  1. Si la bandera estática está activa, la tabla ya se verificó en esta
	 *     solicitud: retorna inmediatamente (coste cero).
	 *  2. Comprueba la existencia real con SHOW TABLES LIKE.
	 *  3. Si no existe, llama a install_db() y verifica de nuevo.
	 *  4. Solo activa la bandera cuando la tabla está confirmada en BD.
	 *     Si install_db() falla silenciosamente (sin permisos CREATE TABLE),
	 *     la bandera no se activa y cada operación reintentará la comprobación
	 *     sin bloquear la solicitud, mientras se registra el error en el log.
	 *
	 * @return void
	 */
	public static function ensure_db(): void {
		if ( self::$db_ensured ) {
			return;
		}

		global $wpdb;

		// Compatibilidad defensiva: si la instancia se creó después de plugins_loaded,
		// forzar migración pendiente antes de consultar columnas nuevas.
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install_db();
		}

		$table = $wpdb->prefix . 'clms_invitations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists === $table ) {
			self::$db_ensured = true;
			return;
		}

		// La tabla no existe: intentar crearla.
		self::install_db();

		// Verificar que la creación fue exitosa antes de marcar la bandera.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists_after = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists_after === $table ) {
			self::$db_ensured = true;
			return;
		}

		// install_db() falló (p. ej. permisos restringidos en hosting compartido).
		// No activar la bandera: cada llamada siguiente reintentará la comprobación.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				sprintf(
					'[CLMS] No se pudo crear la tabla %s. Verifique los permisos de base de datos del usuario MySQL.',
					$table
				)
			);
		}
	}

	/**
	 * Crea la tabla usando dbDelta.
	 * Se puede llamar también desde el hook de activación del plugin.
	 */
	public static function install_db() {
		global $wpdb;

		$table   = $wpdb->prefix . 'clms_invitations';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id          bigint(20)    NOT NULL AUTO_INCREMENT,
			token       varchar(64)   NOT NULL,
			course_id   bigint(20)    NOT NULL DEFAULT 0,
			program_id  bigint(20)    NOT NULL DEFAULT 0,
			access_mode varchar(20)   NOT NULL DEFAULT 'invite',
			invited_email varchar(200) NOT NULL DEFAULT '',
			access_password varchar(200) NOT NULL DEFAULT '',
			created_by  bigint(20)    NOT NULL DEFAULT 0,
			max_uses    int(11)       NOT NULL DEFAULT 1,
			used_count  int(11)       NOT NULL DEFAULT 0,
			expires_at  datetime      DEFAULT NULL,
			status      varchar(20)   NOT NULL DEFAULT 'active',
			created_at  datetime      NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY course_id (course_id),
			KEY program_id (program_id),
			KEY status (status)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			$fallback_sql = "create table if not exists `{$table}` (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				token varchar(64) NOT NULL,
				course_id bigint(20) NOT NULL DEFAULT 0,
				program_id bigint(20) NOT NULL DEFAULT 0,
				access_mode varchar(20) NOT NULL DEFAULT 'invite',
				invited_email varchar(200) NOT NULL DEFAULT '',
				access_password varchar(200) NOT NULL DEFAULT '',
				created_by bigint(20) NOT NULL DEFAULT 0,
				max_uses int(11) NOT NULL DEFAULT 1,
				used_count int(11) NOT NULL DEFAULT 0,
				expires_at datetime DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY token (token),
				KEY course_id (course_id),
				KEY program_id (program_id),
				KEY status (status)
			) {$charset};";
			$fallback_result = $wpdb->query( $fallback_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$fallback_error  = (string) $wpdb->last_error;
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table && defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
				error_log( sprintf( '[CLMS] install_db fallback invitations failed (%s): %s', (string) $fallback_result, $fallback_error ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		if ( $exists === $table ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Nombre de la tabla de invitaciones.
	 * Garantiza que la tabla existe antes de devolverla (coste real solo en la
	 * primera llamada por solicitud gracias a la bandera estática de ensure_db).
	 */
	private function table() {
		global $wpdb;
		self::ensure_db();
		return $wpdb->prefix . 'clms_invitations';
	}

	// =========================================================================
	// MÉTODO 1 — WOOCOMMERCE
	// =========================================================================

	/**
	 * Matricula al comprador cuando una orden pasa a "processing" o "completed".
	 */
	public function handle_woo_order( $order_id ) {
		if ( ! class_exists( 'CLMS_Commerce_Enrollment' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$user_id = $order->get_customer_id();

		if ( ! $user_id ) {
			return;
		}

		$enrollment = new CLMS_Commerce_Enrollment();
		$result     = $enrollment->enroll_user_from_order( $user_id, $order );

		if ( ! empty( $result['newly_enrolled'] ) ) {
			$this->log(
				sprintf(
					'WooCommerce: usuario #%d matriculado en cursos %s desde orden #%d.',
					$user_id,
					implode( ', ', $result['newly_enrolled'] ),
					$order_id
				)
			);
		}
	}

	// =========================================================================
	// MÉTODO 2 — INVITACIONES POR EMAIL
	// =========================================================================

	/**
	 * Crea un token de invitación para un email y un recurso (curso o programa).
	 *
	 * @param int    $course_id     ID del curso (legacy).
	 * @param string $email         Email del invitado (puede quedar vacío para enlace genérico).
	 * @param int    $created_by    ID del usuario que genera la invitación.
	 * @param int    $expires_hours Horas hasta expiración (0 = sin expiración).
	 * @param int    $max_uses      Número máximo de usos (1 para invitación nominal).
	 * @param int    $program_id    ID del programa (opcional).
	 * @return int|WP_Error ID del registro creado o error.
	 */
	public function create_invitation( $course_id, $email = '', $created_by = 0, $expires_hours = 72, $max_uses = 1, $program_id = 0 ) {
		global $wpdb;

		$course_id  = absint( $course_id );
		$program_id = absint( $program_id );
		$created_by = absint( $created_by ) ?: get_current_user_id();

		if ( $program_id > 0 ) {
			if ( 'lm_program' !== get_post_type( $program_id ) ) {
				return new WP_Error( 'invalid_program', __( 'Programa no válido.', 'atora-lms' ) );
			}
			$course_id = 0;
		} elseif ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return new WP_Error( 'invalid_course', __( 'Curso no válido.', 'atora-lms' ) );
		}

		$email = sanitize_email( $email );
		if ( $email ) {
			$existing_invitation_id = $this->find_recent_active_invitation_id( $course_id, $program_id, $email, 60 );
			if ( $existing_invitation_id > 0 ) {
				return $existing_invitation_id;
			}
		}

		$token = $this->generate_token( 40 );

		$expires_at = $expires_hours > 0
			? gmdate( 'Y-m-d H:i:s', time() + ( $expires_hours * HOUR_IN_SECONDS ) )
			: null;

		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'token'        => $token,
				'course_id'    => $course_id,
				'program_id'   => $program_id,
				'access_mode'  => 'invite',
				'invited_email'=> $email,
				'created_by'   => $created_by,
				'max_uses'     => max( 1, absint( $max_uses ) ),
				'used_count'   => 0,
				'expires_at'   => $expires_at,
				'status'       => 'active',
				'created_at'   => current_time( 'mysql', 1 ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', __( 'No se pudo crear la invitación.', 'atora-lms' ) );
		}

		return $wpdb->insert_id;
	}

	/**
	 * Busca invitación activa reciente para evitar duplicados.
	 *
	 * @param int    $course_id      Curso.
	 * @param int    $program_id     Programa.
	 * @param string $email          Email invitado.
	 * @param int    $window_minutes Ventana de deduplicación.
	 * @return int
	 */
	private function find_recent_active_invitation_id( $course_id, $program_id, $email, $window_minutes = 60 ) {
		global $wpdb;

		$course_id      = absint( $course_id );
		$program_id     = absint( $program_id );
		$email          = sanitize_email( (string) $email );
		$window_minutes = max( 1, absint( $window_minutes ) );

		if ( ! $email || ( ! $course_id && ! $program_id ) ) {
			return 0;
		}

		$since = gmdate( 'Y-m-d H:i:s', time() - ( $window_minutes * MINUTE_IN_SECONDS ) );

		$invitation_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id
				 FROM {$this->table()}
				 WHERE course_id = %d
				   AND program_id = %d
				   AND access_mode = 'invite'
				   AND invited_email = %s
				   AND status = 'active'
				   AND used_count < max_uses
				   AND (expires_at IS NULL OR expires_at > %s)
				   AND created_at >= %s
				 ORDER BY id DESC
				 LIMIT 1",
				$course_id,
				$program_id,
				$email,
				current_time( 'mysql', 1 ),
				$since
			)
		);

		return $invitation_id > 0 ? $invitation_id : 0;
	}

	/**
	 * Obtiene el objetivo real de una invitación (curso/programa).
	 *
	 * @param object $row Fila de la invitación.
	 * @return array{type:string,id:int,title:string,label:string}
	 */
	private function resolve_invitation_target_from_row( $row ) {
		$program_id = isset( $row->program_id ) ? absint( $row->program_id ) : 0;
		$course_id  = isset( $row->course_id ) ? absint( $row->course_id ) : 0;

		if ( $program_id > 0 && 'lm_program' === get_post_type( $program_id ) ) {
			return array(
				'type'  => 'program',
				'id'    => $program_id,
				'title' => (string) get_the_title( $program_id ),
				'label' => __( 'programa', 'atora-lms' ),
			);
		}

		return array(
			'type'  => 'course',
			'id'    => $course_id,
			'title' => (string) get_the_title( $course_id ),
			'label' => __( 'curso', 'atora-lms' ),
		);
	}

	/**
	 * Envía el email de invitación con el enlace de acceso.
	 *
	 * @param int $invitation_id  ID de la fila en la tabla.
	 * @return bool
	 */
	public function send_invitation_email( $invitation_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", absint( $invitation_id ) )
		);

		if ( ! $row || 'invite' !== $row->access_mode ) {
			return false;
		}

		$target      = $this->resolve_invitation_target_from_row( $row );
		$resource_id = (int) $target['id'];
		$join_url    = add_query_arg( 'clms_invite', rawurlencode( (string) $row->token ), home_url( '/' ) );
		$identity    = $this->resolve_invitation_email_identity( (int) ( $row->course_id ?? 0 ), (int) ( $row->program_id ?? 0 ) );
		$dedupe_key  = 'manual_invitation:' . sanitize_key( (string) $target['type'] ) . ':' . $resource_id . ':' . strtolower( (string) $row->invited_email );
		$recipient   = get_user_by( 'email', sanitize_email( (string) $row->invited_email ) );

		$resource_title = (string) $target['title'];
		$resource_label = (string) $target['label'];

		$subject  = sprintf( 'Invitación al %s: %s', $resource_label, $resource_title );

		$message  = '<p>Hola,</p>';
		$message .= '<p>Has sido invitado/a a acceder al ' . esc_html( $resource_label ) . ' <strong>' . esc_html( $resource_title ) . '</strong>.</p>';

		if ( $row->expires_at ) {
			$message .= '<p>Este enlace es válido hasta el ' . date_i18n( 'd/m/Y H:i', strtotime( $row->expires_at ) ) . '.</p>';
		}

		$message .= '<p>O copia este enlace en tu navegador:<br><code>' . esc_html( $join_url ) . '</code></p>';

		if ( class_exists( 'ATORA\EmailEngine\Email_Queue' ) && $recipient && ! empty( $recipient->ID ) ) {
			$queued = \ATORA\EmailEngine\Email_Queue::enqueue(
				array(
					'template' => 'manual_invitation',
					'user_id'  => absint( $recipient->ID ),
					'priority' => 'high',
					'metadata' => array(
						'course_id'      => absint( $row->course_id ),
						'program_id'     => absint( $row->program_id ?? 0 ),
						'course_title'   => $resource_title,
						'program_title'  => 'program' === $target['type'] ? $resource_title : '',
						'invitation_id'  => absint( $row->id ),
						'invitation_key' => sanitize_text_field( $dedupe_key ),
						'invitation_url' => esc_url_raw( $join_url ),
						'email_identity' => $identity,
						'dedupe_key'     => sanitize_key( str_replace( ':', '_', $dedupe_key ) ),
						'source'         => 'manual_invitation',
					),
					'dedupe_window_minutes' => 60,
				)
			);

			if ( false !== $queued ) {
				return true;
			}
		}

		$sent = $this->send_invitation_email_direct(
			sanitize_email( (string) $row->invited_email ),
			$subject,
			$message,
			$join_url,
			$identity,
			$resource_title,
			$resource_label
		);

		return $sent;
	}

	/**
	 * Resuelve identidad de invitación.
	 *
	 * @param int $course_id  Curso.
	 * @param int $program_id Programa.
	 * @return string
	 */
	private function resolve_invitation_email_identity( $course_id, $program_id = 0 ) {
		$course_id  = absint( $course_id );
		$program_id = absint( $program_id );
		return ( $course_id > 0 || $program_id > 0 ) ? 'academia' : 'admin';
	}

	/**
	 * Envía invitación directa respetando identidad y reply-to cuando no aplica cola.
	 *
	 * @param string $email        Destinatario.
	 * @param string $subject      Asunto.
	 * @param string $message      Mensaje HTML.
	 * @param string $join_url     URL de acceso.
	 * @param string $identity     Identidad.
	 * @param string $resource_title Título de recurso.
	 * @param string $resource_label Etiqueta de recurso (curso/programa).
	 * @return bool
	 */
	private function send_invitation_email_direct( $email, $subject, $message, $join_url, $identity, $resource_title, $resource_label ) {
		$email = sanitize_email( (string) $email );
		if ( ! $email || ! is_email( $email ) ) {
			return false;
		}

		$identity = sanitize_key( (string) $identity );
		if ( ! in_array( $identity, array( 'academia', 'teacher', 'admin' ), true ) ) {
			$identity = 'academia';
		}

		$settings = class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_email_engine_settings' )
			? (array) CLMS_Settings::get_email_engine_settings()
			: (array) get_option( 'atora_email_engine_options', array() );

		$prefix_map = array(
			'academia' => 'identity_academia',
			'teacher'  => 'identity_teacher',
			'admin'    => 'identity_admin',
		);
		$prefix = $prefix_map[ $identity ] ?? 'identity_academia';

		$from_email = sanitize_email(
			(string) (
				$settings[ $prefix . '_from_email' ]
				?? $settings['from_email']
				?? get_option( 'admin_email' )
			)
		);
		$from_name = sanitize_text_field(
			(string) (
				$settings[ $prefix . '_from_name' ]
				?? $settings['from_name']
				?? get_bloginfo( 'name' )
			)
		);
		$reply_to = sanitize_email(
			(string) (
				$settings[ $prefix . '_reply_to' ]
				?? $settings['reply_to']
				?? ''
			)
		);

		$from_filter = static function () use ( $from_email ) {
			return $from_email;
		};
		$from_name_filter = static function () use ( $from_name ) {
			return $from_name;
		};

		add_filter( 'wp_mail_from', $from_filter );
		add_filter( 'wp_mail_from_name', $from_name_filter );

		try {
			return (bool) CLMS_Email::send(
				$email,
				$subject,
				$message,
				array(
					'headline'    => sprintf( 'Invitación al %s: %s', $resource_label, $resource_title ),
					'button_text' => sprintf( 'Acceder al %s', $resource_label ),
					'button_url'  => $join_url,
					'reply_to'    => $reply_to,
				)
			);
		} finally {
			remove_filter( 'wp_mail_from', $from_filter );
			remove_filter( 'wp_mail_from_name', $from_name_filter );
		}
	}

	/**
	 * Canjea un token de invitación y matricula al usuario.
	 *
	 * @param string $token   Token recibido.
	 * @param int    $user_id Usuario que canjea.
	 * @return true|WP_Error
	 */
	public function redeem_invitation( $token, $user_id ) {
		global $wpdb;

		$token   = sanitize_text_field( $token );
		$user_id = absint( $user_id );

		if ( ! $token || ! $user_id ) {
			return new WP_Error( 'invalid_params', __( 'Parámetros inválidos.', 'atora-lms' ) );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE token = %s AND access_mode = 'invite' AND status = 'active'",
				$token
			)
		);

		if ( ! $row ) {
			return new WP_Error( 'invalid_token', __( 'La invitación no existe o ya no es válida.', 'atora-lms' ) );
		}

		if ( $row->expires_at && strtotime( $row->expires_at ) < time() ) {
			return new WP_Error( 'expired_token', __( 'La invitación ha caducado.', 'atora-lms' ) );
		}

		if ( $row->used_count >= $row->max_uses ) {
			return new WP_Error( 'max_uses', __( 'Esta invitación ya alcanzó el límite de usos.', 'atora-lms' ) );
		}

		// Si la invitación es nominal, verificar que el email coincida.
		if ( $row->invited_email ) {
			$user = get_userdata( $user_id );
			if ( ! $user || strtolower( $user->user_email ) !== strtolower( $row->invited_email ) ) {
				return new WP_Error( 'email_mismatch', __( 'Esta invitación no corresponde a tu cuenta.', 'atora-lms' ) );
			}
		}

		$target = $this->resolve_invitation_target_from_row( $row );

		// Ya estaba matriculado — éxito igualmente.
		if (
			class_exists( 'CLMS_Helper' ) &&
			(
				( 'program' === $target['type'] && CLMS_Helper::user_is_enrolled_in_program( $user_id, (int) $target['id'] ) ) ||
				( 'course' === $target['type'] && CLMS_Helper::user_is_enrolled_in_course( $user_id, (int) $target['id'] ) )
			)
		) {
			return true;
		}

		if ( class_exists( 'CLMS_Helper' ) ) {
			$enrolled = 'program' === $target['type']
				? CLMS_Helper::enroll_user_in_program( $user_id, (int) $target['id'] )
				: CLMS_Helper::enroll_user_in_course( $user_id, (int) $target['id'] );
		} else {
			$enrolled = false;
		}

		if ( false === $enrolled || is_wp_error( $enrolled ) ) {
			return new WP_Error( 'enroll_failed', __( 'No se pudo completar la matrícula.', 'atora-lms' ) );
		}

		// Incrementar contador.
		$wpdb->update(
			$this->table(),
			array( 'used_count' => (int) $row->used_count + 1 ),
			array( 'id' => $row->id ),
			array( '%d' ),
			array( '%d' )
		);

		// Si alcanzó el máximo de usos, marcar como agotado.
		if ( (int) $row->used_count + 1 >= $row->max_uses ) {
			$wpdb->update(
				$this->table(),
				array( 'status' => 'exhausted' ),
				array( 'id' => $row->id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		do_action( 'clms_user_enrolled_via_invitation', $user_id, (int) $row->course_id, (int) $row->id );
		do_action( 'clms_user_enrolled_via_invitation_target', $user_id, (string) $target['type'], (int) $target['id'], (int) $row->id );

		return true;
	}

	/**
	 * Obtiene todas las invitaciones de un curso.
	 */
	public function get_course_invitations( $course_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE course_id = %d AND program_id = 0 AND access_mode = 'invite' ORDER BY created_at DESC",
				absint( $course_id )
			)
		);
	}

	/**
	 * Obtiene todas las invitaciones de un programa.
	 *
	 * @param int $program_id ID del programa.
	 * @return array
	 */
	public function get_program_invitations( $program_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE program_id = %d AND access_mode = 'invite' ORDER BY created_at DESC",
				absint( $program_id )
			)
		);
	}

	/**
	 * Revoca un token (lo marca como inactivo).
	 */
	public function revoke_invitation( $token ) {
		global $wpdb;

		return $wpdb->update(
			$this->table(),
			array( 'status' => 'revoked' ),
			array( 'token' => sanitize_text_field( $token ) ),
			array( '%s' ),
			array( '%s' )
		);
	}

	// =========================================================================
	// MÉTODO 3 — ENLACE DE ACCESO (LIBRE / CONTRASEÑA / CON REGISTRO)
	// =========================================================================

	/**
	 * Crea o recupera el enlace de acceso para un curso.
	 *
	 * @param int    $course_id  ID del curso.
	 * @param string $mode       'free' | 'password' | 'register'
	 * @param string $password   Contraseña (solo si mode = 'password').
	 * @param int    $created_by ID del creador.
	 * @return array  ['token' => ..., 'url' => ...]
	 */
}
