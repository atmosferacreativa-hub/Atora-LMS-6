<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Certificates_Issuance_Verification_Trait {
	/**
	 * Reglas de elegibilidad.
	 */
	public function get_certificate_eligibility( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		$summary       = $this->get_course_summary( $user_id, $course_id );
		$rules_service = $this->get_rules_service();

		if ( $rules_service && method_exists( $rules_service, 'evaluate_course' ) ) {
			return (array) $rules_service->evaluate_course( $user_id, $course_id, $summary );
		}

		$passing_grade = $this->get_passing_grade();
		$progress_percent = isset( $summary['progress_percent'] ) ? absint( $summary['progress_percent'] ) : 0;
		$final_average    = isset( $summary['final_average'] ) ? absint( $summary['final_average'] ) : 0;
		$total_lessons    = isset( $summary['total_lessons'] ) ? absint( $summary['total_lessons'] ) : 0;
		$completed        = isset( $summary['completed_lessons'] ) ? absint( $summary['completed_lessons'] ) : 0;
		$eligible         = $total_lessons > 0 && $completed >= $total_lessons && $progress_percent >= 100 && $final_average >= $passing_grade;

		return array(
			'eligible'          => $eligible,
			'progress_percent'  => $progress_percent,
			'final_average'     => $final_average,
			'passing_grade'     => $passing_grade,
			'completed_lessons' => $completed,
			'total_lessons'     => $total_lessons,
			'summary'           => $summary,
		);
	}

	public function get_certificate_status_for_student_course( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$record    = $this->get_certificate_record( $user_id, $course_id );
		$eligibility = $this->get_certificate_eligibility( $user_id, $course_id );

		$missing = array();
		if ( ! $eligibility['eligible'] ) {
			if ( ! empty( $eligibility['missing_requirements'] ) && is_array( $eligibility['missing_requirements'] ) ) {
				$missing = array_map( 'sanitize_text_field', $eligibility['missing_requirements'] );
			}
			$required_progress = isset( $eligibility['required_progress'] ) ? absint( $eligibility['required_progress'] ) : $this->get_min_progress();
			if ( (int) $eligibility['progress_percent'] < $required_progress ) {
				$missing[] = sprintf(
					/* translators: %d: progreso mínimo requerido */
					__( 'Completar al menos %d%% del curso.', 'atora-lms' ),
					$required_progress
				);
			}
			if ( (int) $eligibility['final_average'] < (int) $eligibility['passing_grade'] ) {
				$missing[] = sprintf(
					/* translators: %d: passing grade */
					__( 'Alcanzar promedio mínimo de %d%%.', 'atora-lms' ),
					(int) $eligibility['passing_grade']
				);
			}
		}

		$status = 'pending';
		if ( ! empty( $record ) ) {
			$status = isset( $record['status'] ) ? sanitize_key( (string) $record['status'] ) : 'issued';
		} elseif ( $eligibility['eligible'] ) {
			$status = 'eligible';
		}

		$view_url = ! empty( $record ) ? $this->get_view_certificate_url( $user_id, $course_id ) : '';
		$verification_url = ! empty( $record['verification_code'] ) ? $this->get_public_verification_url( (string) $record['verification_code'] ) : '';
		$share_actions = array();
		$presentation = $this->get_presentation_service();
		if ( ! empty( $record ) && $presentation && method_exists( $presentation, 'build_share_actions' ) ) {
			$share_actions = $presentation->build_share_actions(
				array(
					'title'            => $this->get_certificate_target_title( $course_id ),
					'academy_name'     => isset( $record['academy'] ) ? (string) $record['academy'] : get_bloginfo( 'name' ),
					'certificate_code' => isset( $record['certificate_code'] ) ? (string) $record['certificate_code'] : '',
					'issued_at'        => isset( $record['issued_at'] ) ? (string) $record['issued_at'] : '',
					'verification_url' => $verification_url,
					'view_url'         => $view_url,
				)
			);
		}

		return array(
			'status'               => $status,
			'record'               => $record,
			'eligible'             => ! empty( $eligibility['eligible'] ),
			'missing_requirements' => $missing,
			'configuration_warnings' => isset( $eligibility['configuration_warnings'] ) && is_array( $eligibility['configuration_warnings'] ) ? array_values( array_map( 'sanitize_text_field', $eligibility['configuration_warnings'] ) ) : array(),
			'course_ready_for_certificate' => ! empty( $eligibility['course_ready_for_certificate'] ),
			'required_progress'    => isset( $eligibility['required_progress'] ) ? absint( $eligibility['required_progress'] ) : $this->get_min_progress(),
			'passing_grade'        => isset( $eligibility['passing_grade'] ) ? absint( $eligibility['passing_grade'] ) : $this->get_passing_grade(),
			'required_evidences'   => isset( $eligibility['required_evidences'] ) && is_array( $eligibility['required_evidences'] ) ? array_values( array_map( 'absint', $eligibility['required_evidences'] ) ) : array(),
			'completed_evidences'  => isset( $eligibility['completed_evidences'] ) && is_array( $eligibility['completed_evidences'] ) ? array_values( array_map( 'absint', $eligibility['completed_evidences'] ) ) : array(),
			'required_competencies'=> isset( $eligibility['required_competencies'] ) && is_array( $eligibility['required_competencies'] ) ? array_values( array_map( 'sanitize_key', $eligibility['required_competencies'] ) ) : array(),
			'completed_competencies'=> isset( $eligibility['completed_competencies'] ) && is_array( $eligibility['completed_competencies'] ) ? array_values( array_map( 'sanitize_key', $eligibility['completed_competencies'] ) ) : array(),
			'verification_url'     => $verification_url,
			'view_url'             => $view_url,
			'share_actions'        => is_array( $share_actions ) ? $share_actions : array(),
		);
	}

	protected function maybe_issue_certificate( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || ! $course_id || ! $this->is_certificates_enabled() ) {
			return false;
		}

		$record = $this->get_certificate_record( $user_id, $course_id );
		if ( ! empty( $record ) ) {
			return $record;
		}

		$eligibility = $this->get_certificate_eligibility( $user_id, $course_id );
		if ( empty( $eligibility['eligible'] ) ) {
			return false;
		}
		$academic = $this->build_certificate_academic_summary( $course_id, $eligibility );

		$record = array(
			'certificate_code'  => $this->generate_certificate_code( $user_id, $course_id ),
			'verification_code' => wp_generate_password( 20, false, false ),
			'verification_hash' => '',
			'issued_at'         => current_time( 'mysql' ),
			'issue_date'        => current_time( 'Y-m-d' ),
			'status'            => 'valid',
			'type'              => 'course',
			'student_id'        => $user_id,
			'course_id'         => $course_id,
			'target_type'       => 'course',
			'target_id'         => $course_id,
			'student_name'      => $this->get_frozen_student_name( $user_id ),
			'target_title'      => $this->get_certificate_target_title( $course_id ),
			'final_average'     => (int) $eligibility['final_average'],
			'progress_percent'  => (int) $eligibility['progress_percent'],
			'academic_hours'    => absint( $academic['academic_hours'] ?? 0 ),
			'required_evidences_count'  => absint( $academic['required_evidences_count'] ?? 0 ),
			'completed_evidences_count' => absint( $academic['completed_evidences_count'] ?? 0 ),
			'required_competencies_count'  => absint( $academic['required_competencies_count'] ?? 0 ),
			'completed_competencies_count' => absint( $academic['completed_competencies_count'] ?? 0 ),
			'competencies_certified' => isset( $academic['competencies_certified'] ) && is_array( $academic['competencies_certified'] ) ? array_values( $academic['competencies_certified'] ) : array(),
			'competencies_required_labels' => isset( $academic['competencies_required_labels'] ) && is_array( $academic['competencies_required_labels'] ) ? array_values( $academic['competencies_required_labels'] ) : array(),
			'academy'           => get_bloginfo( 'name' ),
			'template'          => 'course-default',
			'template_id'       => 'course-default',
			'revoked_at'        => '',
			'revocation_reason' => '',
			'issued_by'         => get_current_user_id() ? absint( get_current_user_id() ) : 0,
			'email_sent_at'     => '',
		);
		$record['verification_hash'] = hash_hmac( 'sha256', (string) $record['verification_code'], wp_salt( 'auth' ) );

		$this->save_certificate_record( $user_id, $course_id, $record );
		$this->index_certificate_verification_code( (string) $record['verification_code'], $user_id, $course_id, 'course' );

		do_action( 'clms_certificate_issued', $record, $user_id, $course_id );

		return $record;
	}

	protected function get_certificate_record( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || ! $course_id ) {
			return array();
		}

		$record = get_user_meta( $user_id, self::CERT_META_PREFIX . $course_id, true );
		return is_array( $record ) ? $record : array();
	}

	protected function save_certificate_record( $user_id, $course_id, $record ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$record    = is_array( $record ) ? $record : array();
		if ( ! $user_id || ! $course_id || empty( $record ) ) {
			return false;
		}

		update_user_meta( $user_id, self::CERT_META_PREFIX . $course_id, $record );
		return true;
	}

	protected function generate_certificate_code( $user_id, $course_id ) {
		$year     = gmdate( 'Y' );
		$sequence = $this->next_certificate_sequence();
		return sprintf( 'ATORA-%s-%06d', $year, $sequence );
	}

	/**
	 * Secuencia incremental para códigos de certificado.
	 *
	 * @return int
	 */
	protected function next_certificate_sequence() {
		$counter = absint( get_option( self::CODE_COUNTER_OPTION, 0 ) );
		$counter = max( 0, $counter ) + 1;
		update_option( self::CODE_COUNTER_OPTION, $counter, false );
		return $counter;
	}

	/**
	 * URL segura del certificado.
	 */
	protected function get_certificate_url( $user_id, $course_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=clms_view_certificate&course_id=' . absint( $course_id ) ),
			'clms_view_certificate_' . absint( $user_id ) . '_' . absint( $course_id )
		);
	}

	/**
	 * URL pública/privada para visualizar certificado de curso.
	 *
	 * @param int $user_id   Estudiante.
	 * @param int $course_id Curso.
	 * @return string
	 */
	public function get_view_certificate_url( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id ) {
			return '';
		}

		return $this->get_certificate_url( $user_id, $course_id );
	}

	/**
	 * URL para visualizar certificado de programa.
	 *
	 * @param int $user_id    Estudiante.
	 * @param int $program_id Programa.
	 * @return string
	 */
	public function get_view_program_certificate_url( $user_id, $program_id ) {
		$user_id    = absint( $user_id );
		$program_id = absint( $program_id );

		if ( ! $user_id || ! $program_id ) {
			return '';
		}

		return wp_nonce_url(
			admin_url( 'admin-post.php?action=clms_view_certificate&target_type=program&program_id=' . $program_id ),
			'clms_view_program_certificate_' . $user_id . '_' . $program_id
		);
	}

	protected function get_public_verification_url( $verification_code ) {
		if ( ! $this->is_public_verification_enabled() ) {
			return '';
		}
		$verification_code = sanitize_text_field( (string) $verification_code );
		$page_id = absint( get_option( self::VERIFY_PAGE_OPTION, 0 ) );
		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			$page_url = get_permalink( $page_id );
			if ( is_string( $page_url ) && '' !== $page_url ) {
				return add_query_arg(
					array(
						'code' => rawurlencode( $verification_code ),
					),
					$page_url
				);
			}
		}
		return add_query_arg(
			array(
				'action' => 'clms_verify_certificate',
				'code'   => rawurlencode( $verification_code ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public function render_verify_certificate_shortcode( $atts ) {
		if ( ! $this->is_public_verification_enabled() ) {
			return '<p>' . esc_html__( 'La verificación pública de certificados está desactivada.', 'atora-lms' ) . '</p>';
		}

		$atts = shortcode_atts(
			array(
				'code' => isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '',
			),
			(array) $atts,
			'clms_verify_certificate'
		);

		$code = sanitize_text_field( (string) $atts['code'] );
		if ( '' === $code ) {
			ob_start();
			?>
			<div class="clms-certificate-wrap">
				<div class="clms-certificate-card">
					<h3 class="clms-certificate-title"><?php esc_html_e( 'Verificación pública de certificado', 'atora-lms' ); ?></h3>
					<p class="clms-certificate-hint"><?php esc_html_e( 'Ingresa el código visible en la credencial para validar su autenticidad.', 'atora-lms' ); ?></p>
					<form method="get" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;flex-wrap:wrap;">
						<input type="hidden" name="action" value="clms_verify_certificate">
						<input type="text" name="code" value="" placeholder="<?php echo esc_attr__( 'Código de verificación', 'atora-lms' ); ?>" style="min-width:220px;flex:1;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;">
						<button type="submit" class="clms-certificate-btn"><?php esc_html_e( 'Verificar', 'atora-lms' ); ?></button>
					</form>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		$result = $this->find_certificate_by_verification_code( $code );
		if ( empty( $result ) ) {
			return '<p>' . esc_html__( 'Certificado no encontrado.', 'atora-lms' ) . '</p>';
		}

		$record   = $result['record'];
		$user_id  = absint( $result['user_id'] );
		$course_id = absint( $result['course_id'] );
		$program_id = isset( $result['program_id'] ) ? absint( $result['program_id'] ) : 0;
		$target_type = isset( $record['target_type'] ) ? sanitize_key( (string) $record['target_type'] ) : 'course';
		$user     = get_userdata( $user_id );
		$status    = isset( $record['status'] ) ? sanitize_key( (string) $record['status'] ) : 'valid';
		$status_labels = array(
			'issued'  => __( 'Emitido', 'atora-lms' ),
			'valid'   => __( 'Válido', 'atora-lms' ),
			'revoked' => __( 'Revocado', 'atora-lms' ),
		);
		$target_title = 'program' === $target_type ? get_the_title( $program_id ) : $this->get_certificate_target_title( $course_id );
		$academy_name = sanitize_text_field( (string) ( $record['academy'] ?? get_bloginfo( 'name' ) ) );
		$view_url     = '';
		if ( 'program' !== $target_type ) {
			$view_url = $this->get_view_certificate_url( $user_id, $course_id );
		}

		ob_start();
		?>
		<div class="clms-certificate-wrap">
			<div class="clms-certificate-card">
				<h3 class="clms-certificate-title"><?php esc_html_e( 'Verificación de certificado', 'atora-lms' ); ?></h3>
				<p><strong><?php esc_html_e( 'Código:', 'atora-lms' ); ?></strong> <?php echo esc_html( (string) $record['certificate_code'] ); ?></p>
				<p><strong><?php esc_html_e( 'Estado:', 'atora-lms' ); ?></strong> <?php echo esc_html( isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status ); ?></p>
				<p><strong><?php esc_html_e( 'Estudiante:', 'atora-lms' ); ?></strong> <?php echo esc_html( $user ? $user->display_name : '' ); ?></p>
				<p><strong><?php echo esc_html( 'program' === $target_type ? __( 'Programa:', 'atora-lms' ) : __( 'Curso:', 'atora-lms' ) ); ?></strong> <?php echo esc_html( $target_title ); ?></p>
				<p><strong><?php esc_html_e( 'Institución emisora:', 'atora-lms' ); ?></strong> <?php echo esc_html( $academy_name ); ?></p>
				<p><strong><?php esc_html_e( 'Fecha de emisión:', 'atora-lms' ); ?></strong> <?php echo esc_html( (string) $record['issued_at'] ); ?></p>
				<?php if ( ! empty( $record['competencies_certified'] ) && is_array( $record['competencies_certified'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Competencias certificadas:', 'atora-lms' ); ?></strong> <?php echo esc_html( implode( ', ', array_slice( array_map( 'sanitize_text_field', (array) $record['competencies_certified'] ), 0, 6 ) ) ); ?></p>
				<?php endif; ?>
				<div class="clms-certificate-card__actions" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
					<a class="clms-certificate-btn" href="<?php echo esc_url( $this->get_public_verification_url( (string) ( $record['verification_code'] ?? '' ) ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir verificación pública', 'atora-lms' ); ?></a>
					<?php if ( $view_url ) : ?>
						<a class="clms-certificate-btn" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ver certificado', 'atora-lms' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function handle_verify_certificate() {
		if ( ! $this->is_public_verification_enabled() ) {
			wp_die( esc_html__( 'La verificación pública está desactivada.', 'atora-lms' ) );
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$content = do_shortcode( '[clms_verify_certificate code="' . esc_attr( $code ) . '"]' );
		nocache_headers();
		echo '<!doctype html><html><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '"><title>' . esc_html__( 'Verificación de certificado', 'atora-lms' ) . '</title></head><body>' . wp_kses_post( $content ) . '</body></html>';
		exit;
	}

	public function handle_revoke_certificate() {
		if ( ! CLMS_Access::can_access_admin() ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}
		$user_id   = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
		check_admin_referer( 'clms_revoke_certificate_' . $user_id . '_' . $course_id );
		$record = $this->get_certificate_record( $user_id, $course_id );
		if ( empty( $record ) ) {
			wp_die( esc_html__( 'Certificado no encontrado.', 'atora-lms' ) );
		}
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$record['status'] = 'revoked';
		$record['revoked_at'] = current_time( 'mysql' );
		$record['revocation_reason'] = $reason;
		$this->save_certificate_record( $user_id, $course_id, $record );
		if ( ! empty( $record['verification_code'] ) ) {
			$this->index_certificate_verification_code( (string) $record['verification_code'], $user_id, $course_id, 'course' );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	public function handle_reissue_certificate() {
		if ( ! CLMS_Access::can_access_admin() ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}
		if ( ! $this->is_reissue_allowed() ) {
			wp_die( esc_html__( 'La reemisión de certificados está desactivada.', 'atora-lms' ) );
		}
		$user_id   = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
		check_admin_referer( 'clms_reissue_certificate_' . $user_id . '_' . $course_id );
		$record = $this->get_certificate_record( $user_id, $course_id );
		if ( empty( $record ) ) {
			$this->maybe_issue_certificate( $user_id, $course_id );
		} else {
			$record['status'] = 'valid';
			$record['revoked_at'] = '';
			$record['revocation_reason'] = '';
			if ( empty( $record['verification_code'] ) ) {
				$record['verification_code'] = wp_generate_password( 20, false, false );
			}
			$this->save_certificate_record( $user_id, $course_id, $record );
			$this->index_certificate_verification_code( (string) $record['verification_code'], $user_id, $course_id, 'course' );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

}
