<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Settings_Core_Trait {
	public function register_menu(): void {
		// Si la página ya está registrada por el menú principal de ATORA,
		// no añadimos un segundo callback para evitar render duplicado.
		if ( $this->is_settings_page_registered() ) {
			return;
		}

		// La entrada visible en el sidebar está registrada por CLMS_Admin_Menu::register_admin_pages()
		// bajo clms-dashboard. Aquí solo vinculamos el callback de la página con su slug,
		// usando parent = null para no crear una segunda entrada duplicada.
		add_submenu_page(
			null,
			__( 'Configuración de Atora', 'atora-lms' ),
			__( 'Ajustes', 'atora-lms' ),
			'clms_access_admin',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Comprueba si el slug de ajustes ya fue registrado en el menú admin.
	 *
	 * @return bool
	 */
	protected function is_settings_page_registered(): bool {
		global $submenu;

		if ( ! is_array( $submenu ) ) {
			return false;
		}

		foreach ( $submenu as $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) || empty( $item[2] ) ) {
					continue;
				}
				if ( self::PAGE_SLUG === (string) $item[2] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Si CLMS_AI_Admin sigue registrando su propio submenu, lo eliminamos
	 * para evitar duplicados (el panel de Configuración lo reemplaza).
	 */
	public function maybe_remove_old_ai_submenu() {
		remove_submenu_page( 'clms-dashboard', 'clms-ai-settings' );
	}

	/**
	 * Registra feature flags de forma centralizada.
	 *
	 * @return void
	 */
	public function register_feature_flags(): void {
		register_setting(
			'clms_feature_flags',
			self::OPTION_CRM_V2_ENABLED,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( __CLASS__, 'sanitize_feature_flag_boolean' ),
				'default'           => false,
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitiza un feature flag booleano.
	 *
	 * @param mixed $value Valor entrante.
	 * @return bool
	 */
	public static function sanitize_feature_flag_boolean( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}

		if ( is_string( $value ) ) {
			$normalized = strtolower( trim( $value ) );
			return in_array( $normalized, array( '1', 'true', 'yes', 'on' ), true );
		}

		return ! empty( $value );
	}

	/**
	 * Redirige la ruta legacy /wp-admin/clms-settings al slug real de WP admin.php?page=clms-settings.
	 * Evita el 404 cuando se usa un bookmark antiguo o URL escrita manualmente.
	 */
	public function maybe_redirect_legacy_settings_path() {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		if ( empty( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_uri  = wp_unslash( (string) $_SERVER['REQUEST_URI'] );
		$request_path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( '' === $request_path ) {
			return;
		}

		$request_path = untrailingslashit( strtolower( $request_path ) );
		if ( ! preg_match( '#/wp-admin/clms-settings$#', $request_path ) ) {
			return;
		}

		if ( class_exists( 'CLMS_Access' ) && method_exists( 'CLMS_Access', 'can_access_admin' ) ) {
			if ( ! CLMS_Access::can_access_admin() ) {
				return;
			}
		} elseif ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$args = array(
			'page' => self::PAGE_SLUG,
		);

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $tab, array( 'academia', 'channels', 'navigation', 'apis', 'advanced' ), true ) ) {
			$args['tab'] = $tab;
		}

		$target_url = add_query_arg( $args, admin_url( 'admin.php' ) );
		wp_safe_redirect( $target_url, 302 );
		exit;
	}

	/**
	 * Carga assets del panel de ajustes (incluyendo wp.media para selector de logo).
	 */
	public function enqueue_admin_assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		wp_enqueue_media();
	}

	// ── Guardar ─────────────────────────────────────────────────────────────────

	public function handle_save() {
		if ( ! CLMS_Access::can_access_admin() ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$tab = isset( $_POST['clms_settings_tab'] ) ? sanitize_key( wp_unslash( $_POST['clms_settings_tab'] ) ) : 'academia';
		if ( ! in_array( $tab, array( 'academia', 'channels', 'navigation', 'apis', 'advanced' ), true ) ) {
			$tab = 'academia';
		}

		switch ( $tab ) {
			case 'academia':
				$this->save_academia();
				break;
			case 'navigation':
				$this->save_navigation();
				break;
			case 'channels':
				$this->save_channels();
				break;
			case 'apis':
				$this->save_apis();
				break;
			case 'advanced':
				$this->save_advanced();
				break;
		}

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'tab' => $tab, 'saved' => '1' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	protected function save_academia() {
		$allowed_themes = array( 'light', 'dark', 'vibrant', 'pastel', 'elegant', 'custom' );
		$ui_theme = isset( $_POST['ui_theme'] ) ? sanitize_key( wp_unslash( $_POST['ui_theme'] ) ) : 'light';
		if ( ! in_array( $ui_theme, $allowed_themes, true ) ) {
			$ui_theme = 'light';
		}

		$ui_color_bg           = isset( $_POST['ui_color_bg'] ) ? sanitize_hex_color( wp_unslash( $_POST['ui_color_bg'] ) ) : '#ffffff';
		$ui_color_surface      = isset( $_POST['ui_color_surface'] ) ? sanitize_hex_color( wp_unslash( $_POST['ui_color_surface'] ) ) : '#f8fafc';
		$ui_color_text         = isset( $_POST['ui_color_text'] ) ? sanitize_hex_color( wp_unslash( $_POST['ui_color_text'] ) ) : '#0f172a';
		$ui_color_muted        = isset( $_POST['ui_color_muted'] ) ? sanitize_hex_color( wp_unslash( $_POST['ui_color_muted'] ) ) : '#475569';
		$ui_color_border       = isset( $_POST['ui_color_border'] ) ? sanitize_hex_color( wp_unslash( $_POST['ui_color_border'] ) ) : '#e2e8f0';
		$ui_color_accent       = isset( $_POST['ui_color_accent'] ) ? sanitize_hex_color( wp_unslash( $_POST['ui_color_accent'] ) ) : '#6366f1';
		$ui_color_accent_hover = isset( $_POST['ui_color_accent_hover'] ) ? sanitize_hex_color( wp_unslash( $_POST['ui_color_accent_hover'] ) ) : '#4f46e5';
		$ui_color_accent_soft  = isset( $_POST['ui_color_accent_soft'] ) ? sanitize_hex_color( wp_unslash( $_POST['ui_color_accent_soft'] ) ) : '#eef2ff';

		$data = array(
			'academy_name'   => isset( $_POST['academy_name'] )   ? sanitize_text_field( wp_unslash( $_POST['academy_name'] ) ) : '',
			'academy_tagline'=> isset( $_POST['academy_tagline'] ) ? sanitize_text_field( wp_unslash( $_POST['academy_tagline'] ) ) : '',
			'primary_color'  => isset( $_POST['primary_color'] )   ? sanitize_hex_color( wp_unslash( $_POST['primary_color'] ) ) : '#6366f1',
			'logo_id'        => isset( $_POST['logo_id'] )         ? absint( wp_unslash( $_POST['logo_id'] ) ) : 0,
			'contact_email'  => isset( $_POST['contact_email'] )   ? sanitize_email( wp_unslash( $_POST['contact_email'] ) ) : '',
			'teacher_contact_email' => isset( $_POST['teacher_contact_email'] ) ? sanitize_email( wp_unslash( $_POST['teacher_contact_email'] ) ) : '',
			'admin_contact_email' => isset( $_POST['admin_contact_email'] ) ? sanitize_email( wp_unslash( $_POST['admin_contact_email'] ) ) : '',
			'student_profile_page_id' => isset( $_POST['student_profile_page_id'] ) ? absint( wp_unslash( $_POST['student_profile_page_id'] ) ) : 0,
			'ui_theme'       => $ui_theme,
			'ui_color_bg'           => $ui_color_bg ?: '#ffffff',
			'ui_color_surface'      => $ui_color_surface ?: '#f8fafc',
			'ui_color_text'         => $ui_color_text ?: '#0f172a',
			'ui_color_muted'        => $ui_color_muted ?: '#475569',
			'ui_color_border'       => $ui_color_border ?: '#e2e8f0',
			'ui_color_accent'       => $ui_color_accent ?: '#6366f1',
			'ui_color_accent_hover' => $ui_color_accent_hover ?: '#4f46e5',
			'ui_color_accent_soft'  => $ui_color_accent_soft ?: '#eef2ff',
		);

		if ( empty( $data['teacher_contact_email'] ) || ! is_email( $data['teacher_contact_email'] ) ) {
			$data['teacher_contact_email'] = $data['contact_email'];
		}

		if ( empty( $data['admin_contact_email'] ) || ! is_email( $data['admin_contact_email'] ) ) {
			$data['admin_contact_email'] = sanitize_email( (string) get_option( 'admin_email' ) );
		}

		update_option( self::OPTION_ACADEMY, $data );
	}

	protected function save_navigation() {
		$registered_locations = array_keys( $this->get_registered_menu_locations() );

		$header_location = isset( $_POST['header_location'] ) ? sanitize_key( wp_unslash( $_POST['header_location'] ) ) : '';
		$footer_location = isset( $_POST['footer_location'] ) ? sanitize_key( wp_unslash( $_POST['footer_location'] ) ) : '';

		if ( ! in_array( $header_location, $registered_locations, true ) ) {
			$header_location = '';
		}

		if ( ! in_array( $footer_location, $registered_locations, true ) ) {
			$footer_location = '';
		}

		$data = array(
			'header_location'           => $header_location,
			'footer_location'           => $footer_location,
			'header_menu_guest'         => isset( $_POST['header_menu_guest'] ) ? absint( wp_unslash( $_POST['header_menu_guest'] ) ) : 0,
			'header_menu_student'       => isset( $_POST['header_menu_student'] ) ? absint( wp_unslash( $_POST['header_menu_student'] ) ) : 0,
			'header_menu_instructor'    => isset( $_POST['header_menu_instructor'] ) ? absint( wp_unslash( $_POST['header_menu_instructor'] ) ) : 0,
			'header_menu_collaborator'  => isset( $_POST['header_menu_collaborator'] ) ? absint( wp_unslash( $_POST['header_menu_collaborator'] ) ) : 0,
			'header_menu_admin'         => isset( $_POST['header_menu_admin'] ) ? absint( wp_unslash( $_POST['header_menu_admin'] ) ) : 0,
			'footer_menu'               => isset( $_POST['footer_menu'] ) ? absint( wp_unslash( $_POST['footer_menu'] ) ) : 0,
		);

		update_option( self::OPTION_NAV, $data );
	}

	protected function save_channels() {
		$email_input = isset( $_POST['clms_email_engine'] ) && is_array( $_POST['clms_email_engine'] )
			? (array) wp_unslash( $_POST['clms_email_engine'] )
			: array();
		$whatsapp_input = isset( $_POST['clms_whatsapp'] ) && is_array( $_POST['clms_whatsapp'] )
			? (array) wp_unslash( $_POST['clms_whatsapp'] )
			: array();
		$telegram_input = isset( $_POST['clms_telegram'] ) && is_array( $_POST['clms_telegram'] )
			? (array) wp_unslash( $_POST['clms_telegram'] )
			: array();

		$email_settings = self::get_email_engine_settings();
		$allowed_providers = array( 'smtp', 'brevo', 'sendgrid', 'mailgun', 'ses', 'postmark' );
		$provider = sanitize_key( (string) ( $email_input['provider'] ?? $email_settings['provider'] ) );
		$email_settings['provider'] = in_array( $provider, $allowed_providers, true ) ? $provider : 'smtp';

		$email_fields = array(
			'from_email',
			'reply_to',
			'identity_academia_from_email',
			'identity_academia_reply_to',
			'identity_teacher_from_email',
			'identity_teacher_reply_to',
			'identity_admin_from_email',
			'identity_admin_reply_to',
		);
		foreach ( $email_fields as $field ) {
			$raw = sanitize_email( (string) ( $email_input[ $field ] ?? $email_settings[ $field ] ) );
			$email_settings[ $field ] = $raw && is_email( $raw ) ? $raw : '';
		}

		$text_fields = array(
			'from_name',
			'identity_academia_from_name',
			'identity_teacher_from_name',
			'identity_admin_from_name',
			'brand_name',
			'smtp_host',
			'smtp_user',
			'brevo_api_key',
			'sendgrid_api_key',
			'sendgrid_webhook_key',
			'mailgun_api_key',
			'mailgun_domain',
			'mailgun_webhook_signing_key',
			'postmark_api_key',
			'postmark_webhook_secret',
			'ses_access_key',
			'ses_secret_key',
			'ses_region',
		);
		foreach ( $text_fields as $field ) {
			$email_settings[ $field ] = sanitize_text_field( (string) ( $email_input[ $field ] ?? $email_settings[ $field ] ) );
		}
		$email_settings['smtp_pass'] = (string) ( $email_input['smtp_pass'] ?? $email_settings['smtp_pass'] );

		$email_settings['brand_logo_url'] = esc_url_raw( (string) ( $email_input['brand_logo_url'] ?? $email_settings['brand_logo_url'] ) );
		$email_settings['brand_primary_color'] = sanitize_hex_color( (string) ( $email_input['brand_primary_color'] ?? $email_settings['brand_primary_color'] ) ) ?: '#3f98ee';
		$email_settings['smtp_port'] = max( 1, min( 65535, absint( $email_input['smtp_port'] ?? $email_settings['smtp_port'] ) ) );
		$email_settings['smtp_auth'] = ! empty( $email_input['smtp_auth'] ) ? 1 : 0;

		$smtp_encryption = sanitize_key( (string) ( $email_input['smtp_encryption'] ?? $email_settings['smtp_encryption'] ) );
		$email_settings['smtp_encryption'] = in_array( $smtp_encryption, array( 'tls', 'ssl' ), true ) ? $smtp_encryption : '';

		$mailgun_region = sanitize_key( (string) ( $email_input['mailgun_region'] ?? $email_settings['mailgun_region'] ) );
		$email_settings['mailgun_region'] = in_array( $mailgun_region, array( 'us', 'eu' ), true ) ? $mailgun_region : 'us';

		$academy = self::get_academy_settings();
		$academy_name = sanitize_text_field( (string) ( $academy['academy_name'] ?? get_bloginfo( 'name' ) ) );
		$academy_email = sanitize_email( (string) ( $academy['contact_email'] ?? get_option( 'admin_email' ) ) );
		$teacher_email = sanitize_email( (string) ( $academy['teacher_contact_email'] ?? $academy_email ) );
		$admin_email = sanitize_email( (string) ( $academy['admin_contact_email'] ?? get_option( 'admin_email' ) ) );

		if ( empty( $email_settings['identity_academia_from_email'] ) ) {
			$email_settings['identity_academia_from_email'] = $academy_email;
		}
		if ( empty( $email_settings['identity_teacher_from_email'] ) ) {
			$email_settings['identity_teacher_from_email'] = $teacher_email ?: $academy_email;
		}
		if ( empty( $email_settings['identity_admin_from_email'] ) ) {
			$email_settings['identity_admin_from_email'] = $admin_email ?: $academy_email;
		}
		if ( empty( $email_settings['identity_academia_from_name'] ) ) {
			$email_settings['identity_academia_from_name'] = $academy_name;
		}
		if ( empty( $email_settings['identity_teacher_from_name'] ) ) {
			$email_settings['identity_teacher_from_name'] = $academy_name;
		}
		if ( empty( $email_settings['identity_admin_from_name'] ) ) {
			$email_settings['identity_admin_from_name'] = $academy_name;
		}

		// Compatibilidad con providers actuales (single identity).
		$email_settings['from_email'] = $email_settings['identity_academia_from_email'];
		$email_settings['from_name']  = $email_settings['identity_academia_from_name'];
		$email_settings['reply_to']   = $email_settings['identity_academia_reply_to'];
		if ( empty( $email_settings['brand_name'] ) ) {
			$email_settings['brand_name'] = $academy_name;
		}

		update_option( self::OPTION_EMAIL_ENGINE, $email_settings );
		self::sync_email_identities_from_settings( $email_settings );

		$academy['contact_email']         = $email_settings['identity_academia_from_email'] ?: $academy_email;
		$academy['teacher_contact_email'] = $email_settings['identity_teacher_from_email'] ?: $teacher_email;
		$academy['admin_contact_email']   = $email_settings['identity_admin_from_email'] ?: $admin_email;
		update_option( self::OPTION_ACADEMY, $academy );

		$whatsapp_settings = self::get_whatsapp_settings();
		$whatsapp_text_fields = array(
			'access_token',
			'phone_number_id',
			'app_secret',
			'webhook_verify_token',
			'default_template_inactivity',
			'default_template_grade',
			'default_template_purchase',
		);
		foreach ( $whatsapp_text_fields as $field ) {
			$raw = (string) ( $whatsapp_input[ $field ] ?? $whatsapp_settings[ $field ] );
			$whatsapp_settings[ $field ] = in_array( $field, array( 'default_template_inactivity', 'default_template_grade', 'default_template_purchase' ), true )
				? sanitize_key( $raw )
				: sanitize_text_field( $raw );
		}

		$lang = (string) preg_replace( '/[^A-Za-z_]/', '', (string) ( $whatsapp_input['language'] ?? $whatsapp_settings['language'] ) );
		$whatsapp_settings['language'] = '' !== $lang ? $lang : 'es';
		update_option( self::OPTION_WHATSAPP, $whatsapp_settings );

		$telegram_settings = self::get_telegram_settings();
		$telegram_settings['bot_token'] = sanitize_text_field( (string) ( $telegram_input['bot_token'] ?? $telegram_settings['bot_token'] ) );
		$telegram_settings['webhook_secret'] = sanitize_text_field( (string) ( $telegram_input['webhook_secret'] ?? $telegram_settings['webhook_secret'] ) );
		$bot_username = ltrim( sanitize_text_field( (string) ( $telegram_input['bot_username'] ?? $telegram_settings['bot_username'] ) ), '@' );
		$telegram_settings['bot_username'] = (string) preg_replace( '/[^A-Za-z0-9_]/', '', $bot_username );
		$admin_chat_id = (string) preg_replace( '/[^0-9\-]/', '', (string) ( $telegram_input['admin_chat_id'] ?? $telegram_settings['admin_chat_id'] ) );
		$telegram_settings['admin_chat_id'] = (string) $admin_chat_id;
		update_option( self::OPTION_TELEGRAM, $telegram_settings );
	}

	protected function save_apis() {
		$input = array();
		foreach ( array(
			'provider',
			'openai_api_key',
			'openai_model',
			'anthropic_api_key',
			'anthropic_model',
			'gemini_api_key',
			'gemini_model',
			'whisper_api_key',
			'ai_enabled',
			'ai_copilot_commercial_enabled',
			'ai_copilot_teacher_enabled',
			'ai_copilot_evaluator_enabled',
			'ai_copilot_student_enabled',
			'ai_evaluation_mode',
			'ai_logs_enabled',
			'ai_limit_role_admin_hour',
			'ai_limit_role_teacher_hour',
			'ai_limit_role_student_hour',
			'ai_limit_role_guest_hour',
		) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$input[ $field ] = wp_unslash( $_POST[ $field ] );
			}
		}

		foreach ( array(
			'ai_enabled',
			'ai_copilot_commercial_enabled',
			'ai_copilot_teacher_enabled',
			'ai_copilot_evaluator_enabled',
			'ai_copilot_student_enabled',
			'ai_logs_enabled',
		) as $checkbox_field ) {
			if ( ! isset( $input[ $checkbox_field ] ) ) {
				$input[ $checkbox_field ] = '0';
			}
		}

		// Source of truth: toda normalización pasa por pre_update_option_{OPTION_AI}.
		update_option( self::get_ai_option_key(), $input );
	}

	public function ajax_test_ai_connection() {
		if ( ! CLMS_Access::can_access_admin() ) {
			wp_send_json_error(
				array(
					'message' => __( 'No tienes permisos para ejecutar esta prueba.', 'atora-lms' ),
				),
				403
			);
		}

		check_ajax_referer( 'clms_ai_test_connection', 'nonce' );

		$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
		if ( ! $manager || ! method_exists( $manager, 'chat' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'CLMS_AI_Manager no está disponible.', 'atora-lms' ),
				),
				500
			);
		}

		$result = $manager->chat(
			array(
				array(
					'role'    => 'user',
					'content' => __( 'Responde solo con: CONEXIÓN OK', 'atora-lms' ),
				),
			),
			array(
				'max_tokens'  => 20,
				'temperature' => 0,
				'timeout'     => 20,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: provider error */
						__( 'Error al conectar con el proveedor IA: %s', 'atora-lms' ),
						$result->get_error_message()
					),
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Conexión IA verificada correctamente.', 'atora-lms' ),
				'reply'   => sanitize_text_field( (string) $result ),
			)
		);
	}

	/**
	 * Sanitiza de forma centralizada cualquier actualización de clms_ai_settings.
	 *
	 * @param mixed $new_value Nuevo valor.
	 * @param mixed $old_value Valor anterior.
	 * @return array
	 */
	public function filter_pre_update_ai_settings( $new_value, $old_value ) {
		return self::prepare_ai_settings_for_storage( $new_value, $old_value );
	}

	protected function save_advanced() {
		$inactivity_days = isset( $_POST['inactivity_days_threshold'] ) ? absint( wp_unslash( $_POST['inactivity_days_threshold'] ) ) : 14;
		$inactivity_days = max( 1, min( 90, $inactivity_days ) );

		$inactivity_cooldown_hours = isset( $_POST['inactivity_email_cooldown_hours'] ) ? absint( wp_unslash( $_POST['inactivity_email_cooldown_hours'] ) ) : 72;
		$inactivity_cooldown_hours = max( 1, min( 720, $inactivity_cooldown_hours ) );

		$data = array(
			'disable_rest_api' => isset( $_POST['disable_rest_api'] ) ? '1' : '0',
			'enable_debug_log' => isset( $_POST['enable_debug_log'] ) ? '1' : '0',
			'inactivity_days_threshold'      => $inactivity_days,
			'inactivity_email_enabled'       => isset( $_POST['inactivity_email_enabled'] ) ? '1' : '0',
			'inactivity_email_cooldown_hours'=> $inactivity_cooldown_hours,
			'inactivity_email_subject'       => isset( $_POST['inactivity_email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['inactivity_email_subject'] ) ) : '',
			'inactivity_email_headline'      => isset( $_POST['inactivity_email_headline'] ) ? sanitize_text_field( wp_unslash( $_POST['inactivity_email_headline'] ) ) : '',
			'inactivity_email_button_text'   => isset( $_POST['inactivity_email_button_text'] ) ? sanitize_text_field( wp_unslash( $_POST['inactivity_email_button_text'] ) ) : '',
			'inactivity_email_footer_note'   => isset( $_POST['inactivity_email_footer_note'] ) ? sanitize_text_field( wp_unslash( $_POST['inactivity_email_footer_note'] ) ) : '',
			'inactivity_email_body'          => isset( $_POST['inactivity_email_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['inactivity_email_body'] ) ) : '',
		);
		update_option( self::OPTION_ADV, $data );
		update_option( self::OPTION_CRM_V2_ENABLED, isset( $_POST['crm_v2_enabled'] ) ? 1 : 0 );
		update_option( 'clms_inactivity_days_threshold', $inactivity_days );
	}

	// ── Render ───────────────────────────────────────────────────────────────────

}
