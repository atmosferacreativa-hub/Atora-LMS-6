<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Settings_Channels_Helpers_Trait {
	protected function render_menu_assignment_row( $field_name, $label, $nav, $menus ) {
		$current = isset( $nav[ $field_name ] ) ? absint( $nav[ $field_name ] ) : 0;
		?>
		<tr>
			<th><label for="<?php echo esc_attr( $field_name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>">
					<option value="0"><?php esc_html_e( '— No reemplazar —', 'atora-lms' ); ?></option>
					<?php foreach ( $menus as $menu_id => $menu_label ) : ?>
						<option value="<?php echo esc_attr( $menu_id ); ?>" <?php selected( $current, $menu_id ); ?>><?php echo esc_html( $menu_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
	}

	protected function get_available_wp_menus() {
		$menus = wp_get_nav_menus();
		$data  = array();

		if ( empty( $menus ) || is_wp_error( $menus ) ) {
			return $data;
		}

		foreach ( $menus as $menu ) {
			$data[ (int) $menu->term_id ] = $menu->name;
		}

		return $data;
	}

	protected function get_registered_menu_locations() {
		$locations = get_registered_nav_menus();
		return is_array( $locations ) ? $locations : array();
	}

	/**
	 * Devuelve configuración consolidada de canales.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_channel_settings() {
		$email = self::get_email_engine_settings();
		$whatsapp = self::get_whatsapp_settings();
		$telegram = self::get_telegram_settings();

		return array(
			'email'    => $email,
			'whatsapp' => $whatsapp,
			'telegram' => $telegram,
			'status'   => array(
				'email_ready'    => ! empty( $email['from_email'] ) && ! empty( $email['provider'] ),
				'whatsapp_ready' => ! empty( $whatsapp['access_token'] ) && ! empty( $whatsapp['phone_number_id'] ),
				'telegram_ready' => ! empty( $telegram['bot_token'] ) && ! empty( $telegram['webhook_secret'] ),
			),
		);
	}

	/**
	 * Mantiene sincronizada la option legacy `atora_email_identities` con
	 * el owner central `atora_email_engine_options`.
	 *
	 * @param array<string,mixed> $email_settings Ajustes normalizados de email.
	 * @return void
	 */
	protected static function sync_email_identities_from_settings( array $email_settings ): void {
		$email_settings = is_array( $email_settings ) ? $email_settings : array();

		$provider = sanitize_key( (string) ( $email_settings['provider'] ?? 'smtp' ) );
		if ( ! in_array( $provider, array( 'smtp', 'brevo', 'sendgrid', 'mailgun', 'ses', 'postmark' ), true ) ) {
			$provider = 'smtp';
		}

		$smtp_host  = sanitize_text_field( (string) ( $email_settings['smtp_host'] ?? '' ) );
		$smtp_port  = max( 1, min( 65535, absint( $email_settings['smtp_port'] ?? 587 ) ) );
		$smtp_secure = sanitize_key( (string) ( $email_settings['smtp_encryption'] ?? '' ) );
		if ( ! in_array( $smtp_secure, array( 'ssl', 'tls' ), true ) ) {
			$smtp_secure = '';
		}
		$smtp_auth = ! empty( $email_settings['smtp_auth'] ) ? 1 : 0;
		$smtp_user = sanitize_text_field( (string) ( $email_settings['smtp_user'] ?? '' ) );

		$identity_map = array(
			'academia'       => array(
				'label'    => __( 'Academia / Admin', 'atora-lms' ),
				'prefix'   => 'identity_academia',
				'aliases'  => array( 'academia' ),
			),
			'comercio'       => array(
				'label'    => __( 'Docencia', 'atora-lms' ),
				'prefix'   => 'identity_teacher',
				'aliases'  => array( 'comercio', 'teacher', 'docencia' ),
			),
			'administracion' => array(
				'label'    => __( 'Comercial', 'atora-lms' ),
				'prefix'   => 'identity_admin',
				'aliases'  => array( 'administracion', 'admin', 'comercial', 'commercial' ),
			),
		);

		$existing = get_option( 'atora_email_identities', array() );
		$existing = is_array( $existing ) ? $existing : array();
		$next     = array();

		foreach ( $identity_map as $public_key => $info ) {
			$prefix = sanitize_key( (string) ( $info['prefix'] ?? '' ) );
			if ( '' === $prefix ) {
				continue;
			}

			$existing_row = array();
			foreach ( (array) ( $info['aliases'] ?? array() ) as $alias ) {
				$alias = sanitize_key( (string) $alias );
				if ( '' === $alias ) {
					continue;
				}
				$value = $existing[ $alias ] ?? null;
				if ( is_array( $value ) ) {
					$existing_row = $value;
					break;
				}
			}

			$from_name  = sanitize_text_field( (string) ( $email_settings[ $prefix . '_from_name' ] ?? $existing_row['from_name'] ?? '' ) );
			$from_email = sanitize_email( (string) ( $email_settings[ $prefix . '_from_email' ] ?? $existing_row['from_email'] ?? '' ) );
			$reply_to   = sanitize_email( (string) ( $email_settings[ $prefix . '_reply_to' ] ?? $existing_row['reply_to'] ?? '' ) );

			if ( '' === $from_name ) {
				$from_name = sanitize_text_field( (string) get_bloginfo( 'name' ) );
			}
			if ( ! $from_email || ! is_email( $from_email ) ) {
				$from_email = sanitize_email( (string) get_option( 'admin_email' ) );
			}
			if ( '' !== $reply_to && ! is_email( $reply_to ) ) {
				$reply_to = '';
			}

			$smtp_username = sanitize_text_field(
				(string) (
					$existing_row['smtp_username']
					?? $existing_row['smtp_user']
					?? $smtp_user
				)
			);
			$smtp_password_encrypted = sanitize_text_field( (string) ( $existing_row['smtp_password_encrypted'] ?? '' ) );
			$smtp_password_plain     = '';
			if ( '' === $smtp_password_encrypted ) {
				$smtp_password_plain = (string) ( $existing_row['smtp_password'] ?? '' );
			}

			$active = isset( $existing_row['active'] ) ? ( ! empty( $existing_row['active'] ) ? 1 : 0 ) : 1;
			if ( isset( $email_settings[ $prefix . '_active' ] ) ) {
				$active = ! empty( $email_settings[ $prefix . '_active' ] ) ? 1 : 0;
			}

			$next[ $public_key ] = array(
				'label'                   => sanitize_text_field( (string) ( $info['label'] ?? ucfirst( $public_key ) ) ),
				'from_name'               => $from_name,
				'from_email'              => $from_email,
				'reply_to'                => $reply_to,
				'provider'                => $provider,
				'smtp_host'               => $smtp_host,
				'smtp_port'               => $smtp_port,
				'smtp_secure'             => $smtp_secure,
				'smtp_auth'               => $smtp_auth,
				'smtp_username'           => $smtp_username,
				'smtp_user'               => $smtp_username,
				'smtp_password_encrypted' => $smtp_password_encrypted,
				'smtp_password'           => $smtp_password_plain,
				'imap_enabled'            => ! empty( $existing_row['imap_enabled'] ) ? 1 : 0,
				'imap_host'               => sanitize_text_field( (string) ( $existing_row['imap_host'] ?? '' ) ),
				'imap_port'               => max( 1, absint( $existing_row['imap_port'] ?? 993 ) ),
				'imap_secure'             => sanitize_key( (string) ( $existing_row['imap_secure'] ?? '' ) ),
				'imap_username'           => sanitize_text_field( (string) ( $existing_row['imap_username'] ?? '' ) ),
				'imap_password_encrypted' => sanitize_text_field( (string) ( $existing_row['imap_password_encrypted'] ?? '' ) ),
				'imap_password'           => (string) ( $existing_row['imap_password'] ?? '' ),
				'active'                  => $active,
			);
		}

		update_option( 'atora_email_identities', $next );
	}

	/**
	 * Devuelve opciones normalizadas del Email Engine.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_email_engine_settings() {
		$academy = self::get_academy_settings();
		$defaults = array(
			'provider'                    => 'smtp',
			'from_email'                  => sanitize_email( (string) ( $academy['contact_email'] ?? get_option( 'admin_email' ) ) ),
			'from_name'                   => sanitize_text_field( (string) ( $academy['academy_name'] ?? get_bloginfo( 'name' ) ) ),
			'reply_to'                    => '',
			'identity_academia_from_name' => sanitize_text_field( (string) ( $academy['academy_name'] ?? get_bloginfo( 'name' ) ) ),
			'identity_academia_from_email'=> sanitize_email( (string) ( $academy['contact_email'] ?? get_option( 'admin_email' ) ) ),
			'identity_academia_reply_to'  => '',
			'identity_teacher_from_name'  => sanitize_text_field( (string) ( $academy['academy_name'] ?? get_bloginfo( 'name' ) ) ),
			'identity_teacher_from_email' => sanitize_email( (string) ( $academy['teacher_contact_email'] ?? $academy['contact_email'] ?? get_option( 'admin_email' ) ) ),
			'identity_teacher_reply_to'   => '',
			'identity_admin_from_name'    => sanitize_text_field( (string) ( $academy['academy_name'] ?? get_bloginfo( 'name' ) ) ),
			'identity_admin_from_email'   => sanitize_email( (string) ( $academy['admin_contact_email'] ?? get_option( 'admin_email' ) ) ),
			'identity_admin_reply_to'     => '',
			'brand_name'                  => sanitize_text_field( (string) ( $academy['academy_name'] ?? get_bloginfo( 'name' ) ) ),
			'brand_logo_url'              => '',
			'brand_primary_color'         => sanitize_hex_color( (string) ( $academy['primary_color'] ?? '' ) ) ?: '#3f98ee',
			'smtp_host'                   => '',
			'smtp_port'                   => 587,
			'smtp_auth'                   => 1,
			'smtp_user'                   => '',
			'smtp_pass'                   => '',
			'smtp_encryption'             => 'tls',
			'brevo_api_key'               => '',
			'sendgrid_api_key'            => '',
			'sendgrid_webhook_key'        => '',
			'mailgun_api_key'             => '',
			'mailgun_domain'              => '',
			'mailgun_region'              => 'us',
			'mailgun_webhook_signing_key' => '',
			'postmark_api_key'            => '',
			'postmark_webhook_secret'     => '',
			'ses_access_key'              => '',
			'ses_secret_key'              => '',
			'ses_region'                  => 'us-east-1',
		);

		$stored = get_option( self::OPTION_EMAIL_ENGINE, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$data   = array_merge( $defaults, $stored );

		$data['provider'] = sanitize_key( (string) $data['provider'] );
		if ( ! in_array( $data['provider'], array( 'smtp', 'brevo', 'sendgrid', 'mailgun', 'ses', 'postmark' ), true ) ) {
			$data['provider'] = 'smtp';
		}

		foreach ( array(
			'from_email',
			'reply_to',
			'identity_academia_from_email',
			'identity_academia_reply_to',
			'identity_teacher_from_email',
			'identity_teacher_reply_to',
			'identity_admin_from_email',
			'identity_admin_reply_to',
		) as $email_field ) {
			$value = sanitize_email( (string) $data[ $email_field ] );
			$data[ $email_field ] = $value && is_email( $value ) ? $value : '';
		}

		foreach ( array(
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
		) as $text_field ) {
			$data[ $text_field ] = sanitize_text_field( (string) $data[ $text_field ] );
		}
		$data['smtp_pass'] = (string) $data['smtp_pass'];

		$data['brand_logo_url'] = esc_url_raw( (string) $data['brand_logo_url'] );
		$data['brand_primary_color'] = sanitize_hex_color( (string) $data['brand_primary_color'] ) ?: '#3f98ee';
		$data['smtp_port'] = max( 1, min( 65535, absint( $data['smtp_port'] ) ) );
		$data['smtp_auth'] = ! empty( $data['smtp_auth'] ) ? 1 : 0;
		$data['smtp_encryption'] = sanitize_key( (string) $data['smtp_encryption'] );
		if ( ! in_array( $data['smtp_encryption'], array( 'tls', 'ssl' ), true ) ) {
			$data['smtp_encryption'] = '';
		}

		$data['mailgun_region'] = sanitize_key( (string) $data['mailgun_region'] );
		if ( ! in_array( $data['mailgun_region'], array( 'us', 'eu' ), true ) ) {
			$data['mailgun_region'] = 'us';
		}

		if ( empty( $data['identity_academia_from_email'] ) ) {
			$data['identity_academia_from_email'] = sanitize_email( (string) ( $academy['contact_email'] ?? get_option( 'admin_email' ) ) );
		}
		if ( empty( $data['identity_teacher_from_email'] ) ) {
			$data['identity_teacher_from_email'] = sanitize_email( (string) ( $academy['teacher_contact_email'] ?? $data['identity_academia_from_email'] ) );
		}
		if ( empty( $data['identity_admin_from_email'] ) ) {
			$data['identity_admin_from_email'] = sanitize_email( (string) ( $academy['admin_contact_email'] ?? get_option( 'admin_email' ) ) );
		}
		if ( empty( $data['identity_academia_from_name'] ) ) {
			$data['identity_academia_from_name'] = sanitize_text_field( (string) ( $academy['academy_name'] ?? get_bloginfo( 'name' ) ) );
		}
		if ( empty( $data['identity_teacher_from_name'] ) ) {
			$data['identity_teacher_from_name'] = $data['identity_academia_from_name'];
		}
		if ( empty( $data['identity_admin_from_name'] ) ) {
			$data['identity_admin_from_name'] = $data['identity_academia_from_name'];
		}

		$data['from_email'] = $data['identity_academia_from_email'];
		$data['from_name']  = $data['identity_academia_from_name'];
		if ( empty( $data['brand_name'] ) ) {
			$data['brand_name'] = $data['identity_academia_from_name'];
		}

		return $data;
	}

	/**
	 * Devuelve opciones normalizadas de WhatsApp.
	 *
	 * @return array<string,string>
	 */
	public static function get_whatsapp_settings() {
		$defaults = array(
			'access_token'               => '',
			'phone_number_id'            => '',
			'app_secret'                 => '',
			'webhook_verify_token'       => '',
			'language'                   => 'es',
			'default_template_inactivity'=> 'inactivity_reminder',
			'default_template_grade'     => 'grade_published',
			'default_template_purchase'  => 'purchase_completed',
		);
		$stored = get_option( self::OPTION_WHATSAPP, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$data   = array_merge( $defaults, $stored );

		foreach ( array(
			'access_token',
			'phone_number_id',
			'app_secret',
			'webhook_verify_token',
		) as $text_field ) {
			$data[ $text_field ] = sanitize_text_field( (string) $data[ $text_field ] );
		}

		$data['language'] = (string) preg_replace( '/[^A-Za-z_]/', '', (string) $data['language'] );
		if ( '' === $data['language'] ) {
			$data['language'] = 'es';
		}
		$data['default_template_inactivity'] = sanitize_key( (string) $data['default_template_inactivity'] );
		$data['default_template_grade']      = sanitize_key( (string) $data['default_template_grade'] );
		$data['default_template_purchase']   = sanitize_key( (string) $data['default_template_purchase'] );

		return $data;
	}

	/**
	 * Devuelve opciones normalizadas de Telegram.
	 *
	 * @return array<string,string>
	 */
	public static function get_telegram_settings() {
		$defaults = array(
			'bot_token'      => '',
			'webhook_secret' => '',
			'bot_username'   => '',
			'admin_chat_id'  => '',
		);
		$stored = get_option( self::OPTION_TELEGRAM, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$data   = array_merge( $defaults, $stored );

		$data['bot_token']      = sanitize_text_field( (string) $data['bot_token'] );
		$data['webhook_secret'] = sanitize_text_field( (string) $data['webhook_secret'] );
		$data['bot_username']   = (string) preg_replace( '/[^A-Za-z0-9_]/', '', ltrim( sanitize_text_field( (string) $data['bot_username'] ), '@' ) );
		$data['admin_chat_id']  = (string) preg_replace( '/[^0-9\-]/', '', (string) $data['admin_chat_id'] );

		return $data;
	}

}
