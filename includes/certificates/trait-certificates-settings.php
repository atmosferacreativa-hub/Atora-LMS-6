<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Certificates_Settings_Trait {
	public function register_settings() {
		register_setting(
			'general',
			self::PASSING_GRADE_OPTION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_passing_grade' ),
				'default'           => 70,
			)
		);
		register_setting(
			'general',
			self::MIN_PROGRESS_OPTION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_min_progress' ),
				'default'           => 100,
			)
		);
		register_setting(
			'general',
			self::ENABLED_OPTION,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_toggle' ),
				'default'           => true,
			)
		);
		register_setting(
			'general',
			self::PUBLIC_VERIFY_OPTION,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_toggle' ),
				'default'           => true,
			)
		);
		register_setting(
			'general',
			self::VERIFY_PAGE_OPTION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
		register_setting(
			'general',
			self::ALLOW_REISSUE_OPTION,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_toggle' ),
				'default'           => true,
			)
		);
		register_setting(
			'general',
			self::PROGRAMS_OPTION,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_toggle' ),
				'default'           => true,
			)
		);
		register_setting(
			'general',
			self::SIGNATURE_NAME_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'general',
			self::SIGNATURE_ROLE_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'general',
			self::SEAL_TEXT_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'general',
			self::EMAIL_AUTO_OPTION,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_toggle' ),
				'default'           => true,
			)
		);
		register_setting(
			'general',
			self::EMAIL_SUBJECT_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => __( 'Tu certificado ya está disponible', 'atora-lms' ),
			)
		);
		register_setting(
			'general',
			self::EMAIL_HEADLINE_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => __( '¡Felicitaciones por tu logro académico!', 'atora-lms' ),
			)
		);
		register_setting(
			'general',
			self::EMAIL_BUTTON_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => __( 'Ver mi certificado', 'atora-lms' ),
			)
		);
		register_setting(
			'general',
			self::EMAIL_FOOTER_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => __( 'Este certificado forma parte de tu historial académico en ATORA.', 'atora-lms' ),
			)
		);
		register_setting(
			'general',
			self::EMAIL_BODY_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => __( 'Hola {student_name}, ya está disponible tu certificado de {course_title}. Código: {certificate_code}. Puedes verificarlo en: {verification_url}.', 'atora-lms' ),
			)
		);

		add_settings_field(
			self::PASSING_GRADE_OPTION,
			__( 'Nota mínima certificado LMS', 'atora-lms' ),
			array( $this, 'render_passing_grade_field' ),
			'general'
		);
		add_settings_field(
			self::MIN_PROGRESS_OPTION,
			__( 'Progreso mínimo para certificado', 'atora-lms' ),
			array( $this, 'render_min_progress_field' ),
			'general'
		);
		add_settings_field(
			self::ENABLED_OPTION,
			__( 'Activar certificados', 'atora-lms' ),
			array( $this, 'render_enabled_field' ),
			'general'
		);
		add_settings_field(
			self::PUBLIC_VERIFY_OPTION,
			__( 'Permitir verificación pública', 'atora-lms' ),
			array( $this, 'render_public_verification_field' ),
			'general'
		);
		add_settings_field(
			self::VERIFY_PAGE_OPTION,
			__( 'Página pública de verificación', 'atora-lms' ),
			array( $this, 'render_verify_page_field' ),
			'general'
		);
		add_settings_field(
			self::ALLOW_REISSUE_OPTION,
			__( 'Permitir reemisión', 'atora-lms' ),
			array( $this, 'render_allow_reissue_field' ),
			'general'
		);
		add_settings_field(
			self::PROGRAMS_OPTION,
			__( 'Permitir certificados de programa', 'atora-lms' ),
			array( $this, 'render_enable_programs_field' ),
			'general'
		);
		add_settings_field(
			self::SIGNATURE_NAME_OPTION,
			__( 'Nombre de firma en certificado', 'atora-lms' ),
			array( $this, 'render_signature_name_field' ),
			'general'
		);
		add_settings_field(
			self::SIGNATURE_ROLE_OPTION,
			__( 'Cargo de firma en certificado', 'atora-lms' ),
			array( $this, 'render_signature_role_field' ),
			'general'
		);
		add_settings_field(
			self::SEAL_TEXT_OPTION,
			__( 'Texto de sello en certificado', 'atora-lms' ),
			array( $this, 'render_seal_text_field' ),
			'general'
		);
		add_settings_field(
			self::EMAIL_AUTO_OPTION,
			__( 'Enviar certificado por email', 'atora-lms' ),
			array( $this, 'render_email_auto_field' ),
			'general'
		);
		add_settings_field(
			self::EMAIL_SUBJECT_OPTION,
			__( 'Asunto email certificado', 'atora-lms' ),
			array( $this, 'render_email_subject_field' ),
			'general'
		);
		add_settings_field(
			self::EMAIL_HEADLINE_OPTION,
			__( 'Título email certificado', 'atora-lms' ),
			array( $this, 'render_email_headline_field' ),
			'general'
		);
		add_settings_field(
			self::EMAIL_BUTTON_OPTION,
			__( 'Botón email certificado', 'atora-lms' ),
			array( $this, 'render_email_button_field' ),
			'general'
		);
		add_settings_field(
			self::EMAIL_FOOTER_OPTION,
			__( 'Pie email certificado', 'atora-lms' ),
			array( $this, 'render_email_footer_field' ),
			'general'
		);
		add_settings_field(
			self::EMAIL_BODY_OPTION,
			__( 'Mensaje email certificado', 'atora-lms' ),
			array( $this, 'render_email_body_field' ),
			'general'
		);
	}

	/**
	 * Sanitiza nota mínima.
	 */
	public function sanitize_passing_grade( $value ) {
		$value = absint( $value );

		if ( $value < 1 ) {
			$value = 70;
		}

		if ( $value > 100 ) {
			$value = 100;
		}

		return $value;
	}

	public function sanitize_min_progress( $value ) {
		$value = absint( $value );
		if ( $value < 1 ) {
			$value = 100;
		}
		return min( 100, $value );
	}

	public function sanitize_toggle( $value ) {
		return ! empty( $value );
	}

	/**
	 * Campo admin.
	 */
	public function render_passing_grade_field() {
		$value = $this->get_passing_grade();

		echo '<input type="number" min="1" max="100" name="' . esc_attr( self::PASSING_GRADE_OPTION ) . '" value="' . esc_attr( $value ) . '" class="small-text">';
		echo '<p class="description">' . esc_html__( 'Promedio final mínimo requerido para emitir certificado.', 'atora-lms' ) . '</p>';
	}

	public function render_min_progress_field() {
		$value = $this->get_min_progress();
		echo '<input type="number" min="1" max="100" name="' . esc_attr( self::MIN_PROGRESS_OPTION ) . '" value="' . esc_attr( $value ) . '" class="small-text">';
		echo '<p class="description">' . esc_html__( 'Progreso mínimo requerido para emitir certificado.', 'atora-lms' ) . '</p>';
	}

	public function render_enabled_field() {
		$enabled = $this->is_certificates_enabled();
		echo '<label><input type="checkbox" name="' . esc_attr( self::ENABLED_OPTION ) . '" value="1" ' . checked( $enabled, true, false ) . '> ' . esc_html__( 'Habilitar emisión de certificados', 'atora-lms' ) . '</label>';
	}

	public function render_public_verification_field() {
		$enabled = $this->is_public_verification_enabled();
		echo '<label><input type="checkbox" name="' . esc_attr( self::PUBLIC_VERIFY_OPTION ) . '" value="1" ' . checked( $enabled, true, false ) . '> ' . esc_html__( 'Permitir validación pública por código', 'atora-lms' ) . '</label>';
	}

	public function render_verify_page_field() {
		$page_id = absint( get_option( self::VERIFY_PAGE_OPTION, 0 ) );
		echo wp_dropdown_pages(
			array(
				'name'              => self::VERIFY_PAGE_OPTION,
				'id'                => self::VERIFY_PAGE_OPTION,
				'selected'          => $page_id,
				'show_option_none'  => __( 'Usar endpoint interno (admin-post)', 'atora-lms' ),
				'option_none_value' => '0',
				'echo'              => 0,
			)
		);
		echo '<p class="description">' . esc_html__( 'Si eliges una página con el shortcode [clms_verify_certificate], se usará esa URL para validar certificados.', 'atora-lms' ) . '</p>';
	}

	public function render_allow_reissue_field() {
		$enabled = $this->is_reissue_allowed();
		echo '<label><input type="checkbox" name="' . esc_attr( self::ALLOW_REISSUE_OPTION ) . '" value="1" ' . checked( $enabled, true, false ) . '> ' . esc_html__( 'Permitir reemisión de certificados revocados', 'atora-lms' ) . '</label>';
	}

	public function render_enable_programs_field() {
		$enabled = $this->is_program_certificates_enabled();
		echo '<label><input type="checkbox" name="' . esc_attr( self::PROGRAMS_OPTION ) . '" value="1" ' . checked( $enabled, true, false ) . '> ' . esc_html__( 'Habilitar certificados de programas académicos', 'atora-lms' ) . '</label>';
	}

	public function render_signature_name_field() {
		$value = sanitize_text_field( (string) get_option( self::SIGNATURE_NAME_OPTION, '' ) );
		echo '<input type="text" class="regular-text" name="' . esc_attr( self::SIGNATURE_NAME_OPTION ) . '" value="' . esc_attr( $value ) . '">';
		echo '<p class="description">' . esc_html__( 'Nombre visible junto a la firma del certificado.', 'atora-lms' ) . '</p>';
	}

	public function render_signature_role_field() {
		$value = sanitize_text_field( (string) get_option( self::SIGNATURE_ROLE_OPTION, '' ) );
		echo '<input type="text" class="regular-text" name="' . esc_attr( self::SIGNATURE_ROLE_OPTION ) . '" value="' . esc_attr( $value ) . '">';
		echo '<p class="description">' . esc_html__( 'Cargo académico de quien firma el certificado.', 'atora-lms' ) . '</p>';
	}

	public function render_seal_text_field() {
		$value = sanitize_text_field( (string) get_option( self::SEAL_TEXT_OPTION, '' ) );
		echo '<input type="text" class="regular-text" name="' . esc_attr( self::SEAL_TEXT_OPTION ) . '" value="' . esc_attr( $value ) . '">';
		echo '<p class="description">' . esc_html__( 'Texto breve para el sello institucional del certificado.', 'atora-lms' ) . '</p>';
	}

	public function render_email_auto_field() {
		$enabled = (bool) get_option( self::EMAIL_AUTO_OPTION, true );
		echo '<label><input type="checkbox" name="' . esc_attr( self::EMAIL_AUTO_OPTION ) . '" value="1" ' . checked( $enabled, true, false ) . '> ' . esc_html__( 'Enviar email automático cuando se emite el certificado.', 'atora-lms' ) . '</label>';
	}

	public function render_email_subject_field() {
		$value = sanitize_text_field( (string) get_option( self::EMAIL_SUBJECT_OPTION, __( 'Tu certificado ya está disponible', 'atora-lms' ) ) );
		echo '<input type="text" class="regular-text" name="' . esc_attr( self::EMAIL_SUBJECT_OPTION ) . '" value="' . esc_attr( $value ) . '">';
		echo '<p class="description">' . esc_html__( 'Variables: {student_name}, {course_title}, {certificate_code}, {academy_name}.', 'atora-lms' ) . '</p>';
	}

	public function render_email_headline_field() {
		$value = sanitize_text_field( (string) get_option( self::EMAIL_HEADLINE_OPTION, __( '¡Felicitaciones por tu logro académico!', 'atora-lms' ) ) );
		echo '<input type="text" class="regular-text" name="' . esc_attr( self::EMAIL_HEADLINE_OPTION ) . '" value="' . esc_attr( $value ) . '">';
	}

	public function render_email_button_field() {
		$value = sanitize_text_field( (string) get_option( self::EMAIL_BUTTON_OPTION, __( 'Ver mi certificado', 'atora-lms' ) ) );
		echo '<input type="text" class="regular-text" name="' . esc_attr( self::EMAIL_BUTTON_OPTION ) . '" value="' . esc_attr( $value ) . '">';
	}

	public function render_email_footer_field() {
		$value = sanitize_text_field( (string) get_option( self::EMAIL_FOOTER_OPTION, __( 'Este certificado forma parte de tu historial académico en ATORA.', 'atora-lms' ) ) );
		echo '<input type="text" class="regular-text" name="' . esc_attr( self::EMAIL_FOOTER_OPTION ) . '" value="' . esc_attr( $value ) . '">';
	}

	public function render_email_body_field() {
		$value = sanitize_textarea_field( (string) get_option( self::EMAIL_BODY_OPTION, __( 'Hola {student_name}, ya está disponible tu certificado de {course_title}. Código: {certificate_code}. Puedes verificarlo en: {verification_url}.', 'atora-lms' ) ) );
		echo '<textarea class="large-text" rows="4" name="' . esc_attr( self::EMAIL_BODY_OPTION ) . '">' . esc_textarea( $value ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Mensaje dentro de la plantilla institucional de correo.', 'atora-lms' ) . '</p>';
	}

	/**
	 * Nota mínima.
	 */
	protected function get_passing_grade() {
		$rules = $this->get_rules_service();
		if ( $rules && method_exists( $rules, 'get_min_average' ) ) {
			return absint( $rules->get_min_average() );
		}

		$value = get_option( self::PASSING_GRADE_OPTION, 70 );
		$value = absint( $value );

		if ( $value < 1 || $value > 100 ) {
			$value = 70;
		}

		return $value;
	}

	protected function get_min_progress() {
		$rules = $this->get_rules_service();
		if ( $rules && method_exists( $rules, 'get_min_progress' ) ) {
			return absint( $rules->get_min_progress() );
		}

		$value = absint( get_option( self::MIN_PROGRESS_OPTION, 100 ) );
		if ( $value < 1 ) {
			$value = 100;
		}
		return min( 100, $value );
	}

	protected function is_certificates_enabled() {
		$rules = $this->get_rules_service();
		if ( $rules && method_exists( $rules, 'is_enabled' ) ) {
			return (bool) $rules->is_enabled();
		}
		return (bool) get_option( self::ENABLED_OPTION, true );
	}

	protected function is_public_verification_enabled() {
		$rules = $this->get_rules_service();
		if ( $rules && method_exists( $rules, 'is_public_verification_enabled' ) ) {
			return (bool) $rules->is_public_verification_enabled();
		}
		return (bool) get_option( self::PUBLIC_VERIFY_OPTION, true );
	}

	protected function is_reissue_allowed() {
		$rules = $this->get_rules_service();
		if ( $rules && method_exists( $rules, 'allow_reissue' ) ) {
			return (bool) $rules->allow_reissue();
		}
		return (bool) get_option( self::ALLOW_REISSUE_OPTION, true );
	}

	protected function is_program_certificates_enabled() {
		$rules = $this->get_rules_service();
		if ( $rules && method_exists( $rules, 'allow_program_certificates' ) ) {
			return (bool) $rules->allow_program_certificates();
		}
		return (bool) get_option( self::PROGRAMS_OPTION, true );
	}

}
