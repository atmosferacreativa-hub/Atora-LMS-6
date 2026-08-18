<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Certificates_Events_Email_Trait {
	public function handle_grade_published( $payload ) {
		$payload   = is_array( $payload ) ? $payload : array();
		$student_id = isset( $payload['student_id'] ) ? absint( $payload['student_id'] ) : 0;
		$course_id  = isset( $payload['course_id'] ) ? absint( $payload['course_id'] ) : 0;

		if ( $student_id && $course_id ) {
			$this->maybe_issue_certificate( $student_id, $course_id );
		}
	}

	public function handle_course_completed( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || ! $course_id ) {
			return;
		}
		$this->maybe_issue_certificate( $user_id, $course_id );

		$program_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Program_Certificate_Service') : null;
		if ( $program_service && method_exists( $program_service, 'maybe_issue_from_course_completion' ) ) {
			$records = (array) $program_service->maybe_issue_from_course_completion( $user_id, $course_id );
			foreach ( $records as $record ) {
				if ( ! is_array( $record ) || empty( $record['verification_code'] ) || empty( $record['program_id'] ) ) {
					continue;
				}
				$this->index_certificate_verification_code( (string) $record['verification_code'], $user_id, absint( $record['program_id'] ), 'program' );
			}
		}
	}

	public function handle_program_completed( $user_id, $program_id ) {
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );
		if ( ! $user_id || ! $program_id ) {
			return;
		}

		$program_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Program_Certificate_Service') : null;
		if ( $program_service && method_exists( $program_service, 'maybe_issue_program_certificate' ) ) {
			$record = $program_service->maybe_issue_program_certificate( $user_id, $program_id );
			if ( is_array( $record ) && ! empty( $record['verification_code'] ) ) {
				$this->index_certificate_verification_code( (string) $record['verification_code'], $user_id, $program_id, 'program' );
			}
		}
	}

	public function handle_program_certificate_issued( $record, $user_id, $program_id ) {
		$record     = is_array( $record ) ? $record : array();
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );
		if ( ! $user_id || ! $program_id || empty( $record['verification_code'] ) ) {
			return;
		}
		$this->index_certificate_verification_code( (string) $record['verification_code'], $user_id, $program_id, 'program' );
	}

	/**
	 * Envía email automático al emitir certificado.
	 *
	 * @param array $record    Registro emitido.
	 * @param int   $user_id   Estudiante.
	 * @param int   $course_id Curso (0 cuando es programa).
	 * @return void
	 */
	public function maybe_send_certificate_email( $record, $user_id, $course_id ) {
		$record    = is_array( $record ) ? $record : array();
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || empty( $record ) || empty( $record['certificate_code'] ) ) {
			return;
		}

		$target_type = isset( $record['target_type'] ) ? sanitize_key( (string) $record['target_type'] ) : 'course';
		$target_id   = absint( $record['target_id'] ?? $course_id );
		if ( ! $target_id ) {
			return;
		}

		if ( ! $this->should_send_certificate_email( $course_id, $target_type, $target_id ) ) {
			return;
		}

		$stored = 'program' === $target_type
			? $this->get_program_certificate_record_safe( $user_id, $target_id )
			: $this->get_certificate_record( $user_id, $target_id );
		if ( ! empty( $stored['email_sent_at'] ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$title = 'program' === $target_type
			? sanitize_text_field( (string) get_the_title( $target_id ) )
			: $this->get_certificate_target_title( $target_id );
		$academy_name = class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_academy_settings' )
			? sanitize_text_field( (string) ( CLMS_Settings::get_academy_settings()['academy_name'] ?? get_bloginfo( 'name' ) ) )
			: sanitize_text_field( (string) get_bloginfo( 'name' ) );
		$verification_url = ! empty( $record['verification_code'] ) ? $this->get_public_verification_url( (string) $record['verification_code'] ) : '';
		$view_url = 'program' === $target_type
			? $this->get_view_program_certificate_url( $user_id, $target_id )
			: $this->get_view_certificate_url( $user_id, $target_id );

		$subject_tpl = sanitize_text_field( (string) get_option( self::EMAIL_SUBJECT_OPTION, __( 'Tu certificado ya está disponible', 'atora-lms' ) ) );
		$headline_tpl = sanitize_text_field( (string) get_option( self::EMAIL_HEADLINE_OPTION, __( '¡Felicitaciones por tu logro académico!', 'atora-lms' ) ) );
		$button_tpl = sanitize_text_field( (string) get_option( self::EMAIL_BUTTON_OPTION, __( 'Ver mi certificado', 'atora-lms' ) ) );
		$footer_tpl = sanitize_text_field( (string) get_option( self::EMAIL_FOOTER_OPTION, __( 'Este certificado forma parte de tu historial académico en ATORA.', 'atora-lms' ) ) );
		$body_tpl = sanitize_textarea_field( (string) get_option( self::EMAIL_BODY_OPTION, __( 'Hola {student_name}, ya está disponible tu certificado de {course_title}. Código: {certificate_code}. Puedes verificarlo en: {verification_url}.', 'atora-lms' ) ) );

		$replacements = array(
			'{student_name}'    => sanitize_text_field( (string) $user->display_name ),
			'{course_title}'    => $title,
			'{certificate_code}'=> sanitize_text_field( (string) ( $record['certificate_code'] ?? '' ) ),
			'{academy_name}'    => $academy_name,
			'{verification_url}'=> $verification_url,
		);

		$subject = strtr( $subject_tpl, $replacements );
		$headline = strtr( $headline_tpl, $replacements );
		$button = strtr( $button_tpl, $replacements );
		$footer = strtr( $footer_tpl, $replacements );
		$body = strtr( $body_tpl, $replacements );

		$sent = CLMS_Email::send(
			$user->user_email,
			$subject,
			$body,
			array(
				'headline'    => $headline,
				'button_text' => $button,
				'button_url'  => $view_url,
				'footer_note' => $footer,
			)
		);

		if ( ! $sent ) {
			return;
		}

		$stored['email_sent_at'] = current_time( 'mysql' );
		if ( 'program' === $target_type ) {
			$this->save_program_certificate_record_safe( $user_id, $target_id, $stored );
		} else {
			$this->save_certificate_record( $user_id, $target_id, $stored );
		}
	}

	/**
	 * Determina si está habilitado el envío automático del certificado.
	 *
	 * @param int    $course_id   Curso.
	 * @param string $target_type Tipo de destino.
	 * @param int    $target_id   Destino.
	 * @return bool
	 */
	protected function should_send_certificate_email( $course_id, $target_type, $target_id ) {
		if ( ! (bool) get_option( self::EMAIL_AUTO_OPTION, true ) ) {
			return false;
		}

		$target_type = sanitize_key( (string) $target_type );
		$target_id   = absint( $target_id );
		$course_id   = absint( $course_id );
		if ( 'course' === $target_type ) {
			$value = get_post_meta( $target_id ? $target_id : $course_id, '_clms_course_certificate_auto_email', true );
			if ( '' !== (string) $value ) {
				return '1' === (string) $value;
			}
		}

		return true;
	}

	/**
	 * Shortcode:
	 * [clms_certificate]
	 * [clms_certificate course_id="123"]
	 */
}
