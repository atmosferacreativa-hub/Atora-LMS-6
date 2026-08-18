<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Certificates_Render_Trait {
	public function render_certificate_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="clms-certificate-wrap"><p>' . esc_html__( 'Debes iniciar sesión para ver tu certificado.', 'atora-lms' ) . '</p></div>';
		}

		$atts = shortcode_atts(
			array(
				'course_id' => 0,
			),
			$atts,
			'clms_certificate'
		);

		$course_id = absint( $atts['course_id'] );

		if ( ! $course_id && is_singular( 'lm_course' ) ) {
			$course_id = get_the_ID();
		}

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return '<div class="clms-certificate-wrap"><p>' . esc_html__( 'No se encontró un curso válido.', 'atora-lms' ) . '</p></div>';
		}

		$user_id = get_current_user_id();

		if ( ! $this->user_is_enrolled_in_course( $user_id, $course_id ) && ! CLMS_Helper::user_can_manage_lms( $course_id ) ) {
			return '<div class="clms-certificate-wrap"><p>' . esc_html__( 'No estás inscrito en este curso.', 'atora-lms' ) . '</p></div>';
		}

		$eligibility = $this->get_certificate_eligibility( $user_id, $course_id );

		$this->enqueue_assets();

		ob_start();
		?>
		<div class="clms-certificate-wrap">
			<div class="clms-certificate-card <?php echo $eligibility['eligible'] ? 'clms-certificate-card--eligible' : ''; ?>">
				<div class="clms-certificate-card__header">
					<?php if ( $eligibility['eligible'] ) : ?>
						<svg class="clms-certificate-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0"/></svg>
					<?php else : ?>
						<svg class="clms-certificate-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
					<?php endif; ?>
					<div>
						<h3 class="clms-certificate-title"><?php echo esc_html( $this->get_certificate_target_title( $course_id ) ); ?></h3>
						<?php if ( $eligibility['eligible'] ) : ?>
							<span class="clms-certificate-status clms-certificate-status--ok"><?php esc_html_e( '✓ Certificado disponible', 'atora-lms' ); ?></span>
						<?php else : ?>
							<span class="clms-certificate-status clms-certificate-status--no"><?php esc_html_e( 'En progreso', 'atora-lms' ); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<div class="clms-certificate-stats">
					<div class="clms-certificate-stat">
						<span class="clms-certificate-stat__val"><?php echo esc_html( $eligibility['progress_percent'] ); ?>%</span>
						<span class="clms-certificate-stat__lbl"><?php esc_html_e( 'Progreso', 'atora-lms' ); ?></span>
					</div>
					<div class="clms-certificate-stat">
						<span class="clms-certificate-stat__val"><?php echo esc_html( $eligibility['final_average'] ); ?>%</span>
						<span class="clms-certificate-stat__lbl"><?php esc_html_e( 'Promedio', 'atora-lms' ); ?></span>
					</div>
					<div class="clms-certificate-stat">
						<span class="clms-certificate-stat__val"><?php echo esc_html( $eligibility['passing_grade'] ); ?>%</span>
						<span class="clms-certificate-stat__lbl"><?php esc_html_e( 'Mínimo', 'atora-lms' ); ?></span>
					</div>
				</div>

				<?php if ( $eligibility['eligible'] ) : ?>
					<a class="clms-certificate-btn" href="<?php echo esc_url( $this->get_certificate_url( $user_id, $course_id ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Ver e imprimir certificado', 'atora-lms' ); ?>
					</a>
				<?php else : ?>
					<div class="clms-certificate-progress">
						<div class="clms-certificate-progress__bar">
							<div class="clms-certificate-progress__fill" style="width:<?php echo esc_attr( min( 100, $eligibility['progress_percent'] ) ); ?>%"></div>
						</div>
						<p class="clms-certificate-hint">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: promedio mínimo requerido */
									__( 'Necesitas %d%% de promedio y 100%% de progreso.', 'atora-lms' ),
									absint( $eligibility['passing_grade'] )
								)
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Shortcode:
	 * [clms_my_certificates]
	 */
	public function render_my_certificates_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<div class="clms-certificate-wrap"><p>' . esc_html__( 'Debes iniciar sesión para ver tus certificados.', 'atora-lms' ) . '</p></div>';
		}

		$user_id    = get_current_user_id();
		$course_ids = $this->get_user_enrolled_courses( $user_id );

		if ( CLMS_Helper::user_can_manage_lms() && empty( $course_ids ) ) {
			$course_ids = get_posts(
				array(
					'post_type'      => 'lm_course',
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);
		}

		if ( empty( $course_ids ) ) {
			return '<div class="clms-certificate-wrap"><p>' . esc_html__( 'No tienes cursos inscritos todavía.', 'atora-lms' ) . '</p></div>';
		}

		$this->enqueue_assets();

		ob_start();
		?>
		<div class="clms-certificate-wrap">
			<div class="clms-certificate-list">
				<?php foreach ( $course_ids as $course_id ) :
					$course_id   = absint( $course_id );
					$eligibility = $this->get_certificate_eligibility( $user_id, $course_id );
					$status_detail = $this->get_certificate_status_for_student_course( $user_id, $course_id );

					if ( 'lm_course' !== get_post_type( $course_id ) ) {
						continue;
					}
				?>
					<div class="clms-certificate-card <?php echo $eligibility['eligible'] ? 'clms-certificate-card--eligible' : ''; ?>">
						<div class="clms-certificate-card__header">
							<?php if ( $eligibility['eligible'] ) : ?>
								<svg class="clms-certificate-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0"/></svg>
							<?php else : ?>
								<svg class="clms-certificate-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
							<?php endif; ?>
							<div>
								<h3 class="clms-certificate-title"><?php echo esc_html( $this->get_certificate_target_title( $course_id ) ); ?></h3>
								<?php if ( $eligibility['eligible'] ) : ?>
									<span class="clms-certificate-status clms-certificate-status--ok"><?php esc_html_e( '✓ Disponible', 'atora-lms' ); ?></span>
								<?php else : ?>
									<span class="clms-certificate-status clms-certificate-status--no">
										<?php echo esc_html( $eligibility['progress_percent'] ); ?>% <?php esc_html_e( 'completado', 'atora-lms' ); ?>
									</span>
								<?php endif; ?>
							</div>
						</div>

						<div class="clms-certificate-stats">
							<div class="clms-certificate-stat">
								<span class="clms-certificate-stat__val"><?php echo esc_html( $eligibility['progress_percent'] ); ?>%</span>
								<span class="clms-certificate-stat__lbl"><?php esc_html_e( 'Progreso', 'atora-lms' ); ?></span>
							</div>
							<div class="clms-certificate-stat">
								<span class="clms-certificate-stat__val"><?php echo esc_html( $eligibility['final_average'] ); ?>%</span>
								<span class="clms-certificate-stat__lbl"><?php esc_html_e( 'Promedio', 'atora-lms' ); ?></span>
							</div>
						</div>

						<?php if ( $eligibility['eligible'] ) : ?>
							<a class="clms-certificate-btn" href="<?php echo esc_url( $this->get_certificate_url( $user_id, $course_id ) ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Ver certificado', 'atora-lms' ); ?>
							</a>
							<?php if ( ! empty( $status_detail['verification_url'] ) ) : ?>
								<a class="clms-certificate-btn clms-certificate-btn--ghost" href="<?php echo esc_url( (string) $status_detail['verification_url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Verificar', 'atora-lms' ); ?>
								</a>
							<?php endif; ?>
							<?php if ( ! empty( $status_detail['share_actions']['linkedin'] ) ) : ?>
								<a class="clms-certificate-btn clms-certificate-btn--ghost" href="<?php echo esc_url( (string) $status_detail['share_actions']['linkedin'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'LinkedIn', 'atora-lms' ); ?>
								</a>
							<?php endif; ?>
						<?php else : ?>
							<div class="clms-certificate-progress">
								<div class="clms-certificate-progress__bar">
									<div class="clms-certificate-progress__fill" style="width:<?php echo esc_attr( min( 100, $eligibility['progress_percent'] ) ); ?>%"></div>
								</div>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<?php
				$program_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Program_Certificate_Service') : null;
				$program_ids = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_user_enrolled_programs' )
					? (array) ( method_exists('CLMS_Helper','get_user_enrolled_programs') ? CLMS_Helper::get_user_enrolled_programs( $user_id ) : array() )
					: array();
				$program_ids = array_values( array_filter( array_map( 'absint', $program_ids ) ) );
				?>
				<?php if ( $this->is_program_certificates_enabled() && $program_service && method_exists( $program_service, 'get_program_certificate_record' ) ) : ?>
					<?php foreach ( $program_ids as $program_id ) : ?>
						<?php
						$record = (array) $program_service->get_program_certificate_record( $user_id, $program_id );
						if ( empty( $record ) ) {
							continue;
						}
						$status = sanitize_key( (string) ( $record['status'] ?? 'valid' ) );
						?>
						<div class="clms-certificate-card clms-certificate-card--eligible">
							<div class="clms-certificate-card__header">
								<svg class="clms-certificate-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0"/></svg>
								<div>
									<h3 class="clms-certificate-title"><?php echo esc_html( get_the_title( $program_id ) ); ?></h3>
									<span class="clms-certificate-status clms-certificate-status--ok">
										<?php echo esc_html( 'revoked' === $status ? __( 'Programa revocado', 'atora-lms' ) : __( 'Programa certificado', 'atora-lms' ) ); ?>
									</span>
								</div>
							</div>
							<div class="clms-certificate-stats">
								<div class="clms-certificate-stat">
									<span class="clms-certificate-stat__val"><?php echo esc_html( absint( $record['progress_percent'] ?? 0 ) ); ?>%</span>
									<span class="clms-certificate-stat__lbl"><?php esc_html_e( 'Avance', 'atora-lms' ); ?></span>
								</div>
								<div class="clms-certificate-stat">
									<span class="clms-certificate-stat__val"><?php echo esc_html( absint( $record['final_average'] ?? 0 ) ); ?>%</span>
									<span class="clms-certificate-stat__lbl"><?php esc_html_e( 'Promedio', 'atora-lms' ); ?></span>
								</div>
							</div>
							<a class="clms-certificate-btn" href="<?php echo esc_url( $this->get_view_program_certificate_url( $user_id, $program_id ) ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Ver certificado del programa', 'atora-lms' ); ?>
							</a>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render imprimible del certificado.
	 */
	public function handle_view_certificate() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Debes iniciar sesión.', 'atora-lms' ) );
		}

		$user_id     = get_current_user_id();
		$target_type = isset( $_GET['target_type'] ) ? sanitize_key( wp_unslash( $_GET['target_type'] ) ) : 'course';
		if ( ! in_array( $target_type, array( 'course', 'program' ), true ) ) {
			$target_type = 'course';
		}

		$course_id   = 0;
		$program_id  = 0;
		$record      = array();
		$eligibility = array(
			'final_average'    => 0,
			'progress_percent' => 0,
		);

		if ( 'program' === $target_type ) {
			$program_id = isset( $_GET['program_id'] ) ? absint( wp_unslash( $_GET['program_id'] ) ) : 0;

			if ( ! $program_id || 'lm_program' !== get_post_type( $program_id ) ) {
				wp_die( esc_html__( 'Programa no válido.', 'atora-lms' ) );
			}

			check_admin_referer( 'clms_view_program_certificate_' . $user_id . '_' . $program_id );

			$is_admin_view = CLMS_Helper::user_can_manage_lms( $program_id );
			$is_enrolled_program = class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'user_is_enrolled_in_program' )
				? (bool) CLMS_Helper::user_is_enrolled_in_program( $user_id, $program_id )
				: false;

			if ( ! $is_admin_view && ! $is_enrolled_program ) {
				wp_die( esc_html__( 'No tienes permiso para ver este certificado.', 'atora-lms' ) );
			}

			$program_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Program_Certificate_Service') : null;
			if ( $program_service && method_exists( $program_service, 'get_program_certificate_record' ) ) {
				$record = (array) $program_service->get_program_certificate_record( $user_id, $program_id );
			}
			if ( empty( $record ) && $program_service && method_exists( $program_service, 'maybe_issue_program_certificate' ) ) {
				$issued = $program_service->maybe_issue_program_certificate( $user_id, $program_id );
				$record = is_array( $issued ) ? $issued : array();
			}
			if ( empty( $record ) ) {
				wp_die( esc_html__( 'No se pudo preparar el certificado.', 'atora-lms' ) );
			}

			$eligibility = array(
				'final_average'    => absint( $record['final_average'] ?? 0 ),
				'progress_percent' => absint( $record['progress_percent'] ?? 0 ),
				'completed_evidences' => array(),
				'required_evidences'  => array(),
			);
		} else {
			$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;

			if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
				wp_die( esc_html__( 'Curso no válido.', 'atora-lms' ) );
			}

			check_admin_referer( 'clms_view_certificate_' . $user_id . '_' . $course_id );

			$is_admin_view = CLMS_Helper::user_can_manage_lms( $course_id );

			if ( ! $is_admin_view && ! $this->user_is_enrolled_in_course( $user_id, $course_id ) ) {
				wp_die( esc_html__( 'No tienes permiso para ver este certificado.', 'atora-lms' ) );
			}

			$eligibility = $this->get_certificate_eligibility( $user_id, $course_id );

			if ( ! $eligibility['eligible'] ) {
				wp_die( esc_html__( 'Aún no cumples los requisitos para este certificado.', 'atora-lms' ) );
			}

			$record = $this->maybe_issue_certificate( $user_id, $course_id );
			if ( empty( $record ) ) {
				wp_die( esc_html__( 'No se pudo preparar el certificado.', 'atora-lms' ) );
			}
		}

		$user         = get_userdata( $user_id );
		$course       = 'program' === $target_type ? get_the_title( $program_id ) : get_the_title( $course_id );
		$issue_date   = ! empty( $record['issued_at'] ) ? (string) $record['issued_at'] : '';
		$verify_url   = ! empty( $record['verification_code'] ) ? $this->get_public_verification_url( (string) $record['verification_code'] ) : '';
		$view_url     = 'program' === $target_type ? $this->get_view_program_certificate_url( $user_id, $program_id ) : $this->get_view_certificate_url( $user_id, $course_id );
		$status       = isset( $record['status'] ) ? sanitize_key( (string) $record['status'] ) : 'valid';
		$status_labels = array(
			'issued'  => __( 'Emitido', 'atora-lms' ),
			'valid'   => __( 'Válido', 'atora-lms' ),
			'revoked' => __( 'Revocado', 'atora-lms' ),
		);

		$presentation = $this->get_presentation_service();
		$branding     = $presentation && method_exists( $presentation, 'get_branding_context' )
			? (array) $presentation->get_branding_context()
			: array();
		$share_actions = $presentation && method_exists( $presentation, 'build_share_actions' )
			? (array) $presentation->build_share_actions(
				array(
					'title'            => $course,
					'academy_name'     => isset( $record['academy'] ) ? (string) $record['academy'] : get_bloginfo( 'name' ),
					'certificate_code' => isset( $record['certificate_code'] ) ? (string) $record['certificate_code'] : '',
					'issued_at'        => $issue_date,
					'verification_url' => $verify_url,
					'view_url'         => $view_url,
				)
			)
			: array();

		$site_name      = ! empty( $branding['academy_name'] ) ? sanitize_text_field( (string) $branding['academy_name'] ) : sanitize_text_field( (string) get_bloginfo( 'name' ) );
		$logo_url       = ! empty( $branding['logo_url'] ) ? esc_url_raw( (string) $branding['logo_url'] ) : '';
		$signature_name = ! empty( $branding['signature_name'] ) ? sanitize_text_field( (string) $branding['signature_name'] ) : '';
		$signature_role = ! empty( $branding['signature_role'] ) ? sanitize_text_field( (string) $branding['signature_role'] ) : '';
		$seal_text      = ! empty( $branding['seal_text'] ) ? sanitize_text_field( (string) $branding['seal_text'] ) : '';
		$share_url      = ! empty( $share_actions['share_url'] ) ? esc_url_raw( (string) $share_actions['share_url'] ) : '';
		$linkedin_url   = ! empty( $share_actions['linkedin'] ) ? esc_url_raw( (string) $share_actions['linkedin'] ) : '';
		$whatsapp_url   = ! empty( $share_actions['whatsapp'] ) ? esc_url_raw( (string) $share_actions['whatsapp'] ) : '';
		$hours          = absint( $record['academic_hours'] ?? get_post_meta( $course_id, '_clms_course_hours', true ) );
		$certified_competencies = isset( $record['competencies_certified'] ) && is_array( $record['competencies_certified'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', $record['competencies_certified'] ) ) )
			: array();

		if ( empty( $certified_competencies ) && $presentation && method_exists( $presentation, 'get_course_competency_labels' ) ) {
			$completed_competencies = isset( $eligibility['completed_competencies'] ) && is_array( $eligibility['completed_competencies'] ) ? $eligibility['completed_competencies'] : array();
			$certified_competencies = $presentation->get_course_competency_labels( $course_id, $completed_competencies, 8 );
		}

		$evidences_done  = absint( $record['completed_evidences_count'] ?? ( is_array( $eligibility['completed_evidences'] ?? null ) ? count( $eligibility['completed_evidences'] ) : 0 ) );
		$evidences_total = absint( $record['required_evidences_count'] ?? ( is_array( $eligibility['required_evidences'] ?? null ) ? count( $eligibility['required_evidences'] ) : 0 ) );

		nocache_headers();
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: nombre del curso */
						__( 'Certificado · %s', 'atora-lms' ),
						$course
					)
				);
				?>
			</title>
			<style>
				body{
					margin:0;
					padding:24px;
					background:#f1f5f9;
					font-family:"Segoe UI",-apple-system,BlinkMacSystemFont,"Helvetica Neue",Arial,sans-serif;
					color:#111827;
				}
				.clms-cert-page{
					max-width:1100px;
					margin:0 auto;
				}
				.clms-cert-actions{
					margin-bottom:20px;
					display:flex;
					flex-wrap:wrap;
					justify-content:space-between;
					gap:8px;
				}
				.clms-cert-actions .clms-cert-actions-main{
					display:flex;
					flex-wrap:wrap;
					gap:8px;
				}
				.clms-cert-actions button,
				.clms-cert-actions a{
					padding:10px 16px;
					border:0;
					border-radius:10px;
					background:#111827;
					color:#fff;
					cursor:pointer;
					text-decoration:none;
					display:inline-flex;
					align-items:center;
					font-size:13px;
					font-weight:600;
				}
				.clms-cert-actions .is-soft{
					background:#e2e8f0;
					color:#0f172a;
				}
				.clms-cert-actions-copy{
					display:flex;
					align-items:center;
					gap:8px;
					min-width:260px;
					flex:1;
				}
				.clms-cert-actions-copy input{
					flex:1;
					min-width:180px;
					padding:10px 12px;
					border:1px solid #cbd5e1;
					border-radius:10px;
					background:#fff;
					font-size:12px;
				}
				.clms-cert{
					background:#fff;
					border:10px solid #0f172a;
					padding:56px 42px;
					box-shadow:0 10px 35px rgba(0,0,0,.08);
				}
				.clms-cert-inner{
					border:2px solid #dbeafe;
					padding:42px 30px;
				}
				.clms-cert-header{
					display:flex;
					justify-content:space-between;
					gap:20px;
					align-items:flex-start;
					margin-bottom:10px;
				}
				.clms-cert-kicker{
					font-size:13px;
					letter-spacing:2px;
					text-transform:uppercase;
					color:#475569;
					margin:0 0 10px;
				}
				.clms-cert-logo{
					display:block;
					max-height:64px;
					max-width:200px;
					width:auto;
					margin:0 0 12px;
				}
				.clms-cert-title{
					font-size:36px;
					line-height:1.15;
					margin:0 0 18px;
					font-family:Georgia, "Times New Roman", serif;
				}
				.clms-cert-status{
					display:inline-flex;
					padding:5px 10px;
					border-radius:999px;
					font-size:12px;
					font-weight:700;
					background:#dcfce7;
					color:#166534;
				}
				.clms-cert-status.is-revoked{
					background:#fee2e2;
					color:#b91c1c;
				}
				.clms-cert-text{
					font-size:18px;
					line-height:1.6;
					margin:0 0 14px;
				}
				.clms-cert-name{
					font-size:34px;
					margin:14px 0;
					font-weight:bold;
					font-family:Georgia, "Times New Roman", serif;
				}
				.clms-cert-course{
					font-size:26px;
					margin:12px 0;
					font-family:Georgia, "Times New Roman", serif;
				}
				.clms-cert-highlights{
					display:grid;
					grid-template-columns:repeat(4,minmax(120px,1fr));
					gap:10px;
					margin:24px 0;
				}
				.clms-cert-highlight{
					border:1px solid #e2e8f0;
					border-radius:10px;
					padding:10px;
					text-align:center;
					background:#f8fafc;
				}
				.clms-cert-highlight strong{
					display:block;
					font-size:18px;
				}
				.clms-cert-highlight span{
					font-size:12px;
					color:#475569;
				}
				.clms-cert-competencies{
					margin:10px 0 0;
					padding:0;
					list-style:none;
					display:flex;
					flex-wrap:wrap;
					gap:8px;
				}
				.clms-cert-competencies li{
					display:inline-flex;
					padding:6px 10px;
					border-radius:999px;
					background:#eef2ff;
					color:#3730a3;
					font-size:12px;
					font-weight:600;
				}
				.clms-cert-meta{
					margin-top:40px;
					display:flex;
					justify-content:space-between;
					gap:20px;
					font-size:15px;
					text-align:left;
				}
				.clms-cert-meta div{
					flex:1 1 50%;
				}
				.clms-cert-sign{
					margin-top:36px;
					padding-top:20px;
					border-top:1px dashed #cbd5e1;
					display:flex;
					justify-content:space-between;
					gap:24px;
					align-items:flex-end;
				}
				.clms-cert-signature{
					flex:1;
				}
				.clms-cert-signature-line{
					border-top:1px solid #0f172a;
					max-width:280px;
					margin-bottom:8px;
				}
				.clms-cert-seal{
					min-width:180px;
					min-height:98px;
					border:2px dashed #1e3a8a;
					border-radius:16px;
					display:flex;
					align-items:center;
					justify-content:center;
					padding:10px;
					text-align:center;
					color:#1e3a8a;
					font-size:12px;
					font-weight:700;
					background:#eff6ff;
				}
				.clms-cert-revoked{
					margin-top:16px;
					padding:10px 12px;
					background:#fee2e2;
					color:#991b1b;
					border-radius:10px;
					font-size:13px;
					font-weight:600;
				}
				@media print{
					body{
						background:#fff;
						padding:0;
					}
					.clms-cert-actions{
						display:none;
					}
					.clms-cert-page{
						max-width:none;
					}
					.clms-cert{
						box-shadow:none;
						border-width:8px;
					}
				}
				@media (max-width:860px){
					.clms-cert-highlights{
						grid-template-columns:repeat(2,minmax(120px,1fr));
					}
					.clms-cert-meta,
					.clms-cert-sign{
						flex-direction:column;
					}
				}
			</style>
		</head>
		<body <?php body_class(); ?>>
			<div class="clms-cert-page">
				<div class="clms-cert-actions">
					<div class="clms-cert-actions-main">
						<button type="button" onclick="window.print();"><?php esc_html_e( 'Imprimir certificado', 'atora-lms' ); ?></button>
						<?php if ( $verify_url ) : ?>
							<a href="<?php echo esc_url( $verify_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Verificar certificado', 'atora-lms' ); ?></a>
						<?php endif; ?>
						<?php if ( $linkedin_url ) : ?>
							<a class="is-soft" href="<?php echo esc_url( $linkedin_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Agregar a LinkedIn', 'atora-lms' ); ?></a>
						<?php endif; ?>
						<?php if ( $whatsapp_url ) : ?>
							<a class="is-soft" href="<?php echo esc_url( $whatsapp_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Compartir en WhatsApp', 'atora-lms' ); ?></a>
						<?php endif; ?>
					</div>
					<?php if ( $share_url ) : ?>
						<div class="clms-cert-actions-copy">
							<input id="clms-cert-share-url" type="text" readonly value="<?php echo esc_attr( $share_url ); ?>" aria-label="<?php esc_attr_e( 'Enlace público del certificado', 'atora-lms' ); ?>">
							<button type="button" class="is-soft" id="clms-copy-cert-link"><?php esc_html_e( 'Copiar enlace', 'atora-lms' ); ?></button>
						</div>
					<?php endif; ?>
				</div>

				<div class="clms-cert">
					<div class="clms-cert-inner">
						<div class="clms-cert-header">
							<div>
								<?php if ( $logo_url ) : ?>
									<img class="clms-cert-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $site_name ); ?>">
								<?php endif; ?>
								<p class="clms-cert-kicker"><?php esc_html_e( 'Credencial académica verificable', 'atora-lms' ); ?></p>
								<h1 class="clms-cert-title"><?php echo esc_html( $site_name ); ?></h1>
							</div>
							<span class="clms-cert-status <?php echo esc_attr( 'revoked' === $status ? 'is-revoked' : '' ); ?>">
								<?php echo esc_html( $status_labels[ $status ] ?? __( 'Válido', 'atora-lms' ) ); ?>
							</span>
						</div>

						<p class="clms-cert-text"><?php esc_html_e( 'Otorga el presente certificado a', 'atora-lms' ); ?></p>

						<div class="clms-cert-name"><?php echo esc_html( $user ? $user->display_name : '' ); ?></div>

						<p class="clms-cert-text"><?php esc_html_e( 'por haber completado satisfactoriamente el curso', 'atora-lms' ); ?></p>

						<div class="clms-cert-course"><?php echo esc_html( $course ); ?></div>

						<div class="clms-cert-highlights">
							<div class="clms-cert-highlight">
								<strong><?php echo esc_html( absint( $eligibility['final_average'] ) ); ?>%</strong>
								<span><?php esc_html_e( 'Promedio final', 'atora-lms' ); ?></span>
							</div>
							<div class="clms-cert-highlight">
								<strong><?php echo esc_html( absint( $eligibility['progress_percent'] ) ); ?>%</strong>
								<span><?php esc_html_e( 'Progreso', 'atora-lms' ); ?></span>
							</div>
							<div class="clms-cert-highlight">
								<strong><?php echo esc_html( $hours ); ?></strong>
								<span><?php esc_html_e( 'Horas académicas', 'atora-lms' ); ?></span>
							</div>
							<div class="clms-cert-highlight">
								<strong><?php echo esc_html( $evidences_done ); ?>/<?php echo esc_html( $evidences_total ); ?></strong>
								<span><?php esc_html_e( 'Evidencias certificables', 'atora-lms' ); ?></span>
							</div>
						</div>

						<?php if ( ! empty( $certified_competencies ) ) : ?>
							<p class="clms-cert-text" style="margin-bottom:8px;"><?php esc_html_e( 'Competencias validadas', 'atora-lms' ); ?></p>
							<ul class="clms-cert-competencies">
								<?php foreach ( $certified_competencies as $competency_label ) : ?>
									<li><?php echo esc_html( $competency_label ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>

						<div class="clms-cert-meta">
							<div>
								<strong><?php esc_html_e( 'Fecha de emisión', 'atora-lms' ); ?></strong><br>
								<?php echo esc_html( $issue_date ); ?>
								<br><strong><?php esc_html_e( 'Código', 'atora-lms' ); ?></strong><br>
								<?php echo esc_html( (string) $record['certificate_code'] ); ?>
							</div>
							<div style="text-align:right;">
								<strong><?php esc_html_e( 'Emitido por', 'atora-lms' ); ?></strong><br>
								<?php echo esc_html( $site_name ); ?>
								<?php if ( $hours > 0 ) : ?>
									<br><strong><?php esc_html_e( 'Carga académica', 'atora-lms' ); ?></strong><br>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: horas académicas */
											_n( '%d hora', '%d horas', $hours, 'atora-lms' ),
											$hours
										)
									);
									?>
								<?php endif; ?>
								<strong><?php esc_html_e( 'Código de verificación', 'atora-lms' ); ?></strong><br>
								<?php echo esc_html( (string) $record['verification_code'] ); ?>
								<?php if ( $verify_url ) : ?>
									<br><strong><?php esc_html_e( 'Verificación', 'atora-lms' ); ?></strong><br>
									<?php echo esc_html( $verify_url ); ?>
								<?php endif; ?>
							</div>
						</div>
						<div class="clms-cert-sign">
							<div class="clms-cert-signature">
								<div class="clms-cert-signature-line"></div>
								<strong><?php echo esc_html( $signature_name ); ?></strong><br>
								<span><?php echo esc_html( $signature_role ); ?></span>
							</div>
							<div class="clms-cert-seal">
								<?php echo esc_html( $seal_text ); ?>
							</div>
						</div>
						<?php if ( 'revoked' === $status ) : ?>
							<p class="clms-cert-revoked">
								<?php esc_html_e( 'Este certificado fue revocado por la institución académica.', 'atora-lms' ); ?>
								<?php if ( ! empty( $record['revocation_reason'] ) ) : ?>
									<?php echo esc_html( ' ' . sanitize_text_field( (string) $record['revocation_reason'] ) ); ?>
								<?php endif; ?>
							</p>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<script>
				(function(){
					var btn = document.getElementById('clms-copy-cert-link');
					var input = document.getElementById('clms-cert-share-url');
					if(!btn || !input){ return; }
					var copiedLabel = <?php echo wp_json_encode( __( 'Enlace copiado', 'atora-lms' ) ); ?>;
					btn.addEventListener('click', function(){
						if(navigator.clipboard && navigator.clipboard.writeText){
							navigator.clipboard.writeText(input.value).then(function(){
								btn.textContent = copiedLabel;
							}).catch(function(){});
							return;
						} else {
							input.focus();
							input.select();
							try{ document.execCommand('copy'); }catch(e){}
							btn.textContent = copiedLabel;
						}
					});
				})();
			</script>
		</body>
		</html>
		<?php
		exit;
	}

}
