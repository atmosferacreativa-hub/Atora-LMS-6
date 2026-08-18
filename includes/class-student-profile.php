<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CLMS_Student_Profile
 *
 * Perfil público/privado del estudiante.
 * Shortcodes:
 *   [clms_student_profile]           — perfil del usuario actual
 *   [clms_student_profile user_id=5] — perfil de otro usuario (solo admins/teachers)
 *
 * Secciones:
 *   - Header: avatar, nombre, bio, métricas globales
 *   - Cursos en progreso con barra de avance
 *   - Cursos completados
 *   - Certificados obtenidos
 *   - Últimas entregas
 */
class CLMS_Student_Profile {

	const SHORTCODE = 'clms_student_profile';

	protected static $assets_enqueued = false;

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
	}

	public function get_profile_url( $user_id = 0 ) {
		$user_id = absint( $user_id );
		$url     = '';
		$page_id = 0;

		$option_key = class_exists( 'CLMS_Settings' ) ? CLMS_Settings::OPTION_ACADEMY : 'clms_academy_settings';
		$settings   = get_option( $option_key, array() );
		$settings   = is_array( $settings ) ? $settings : array();
		$page_id    = ! empty( $settings['student_profile_page_id'] ) ? absint( $settings['student_profile_page_id'] ) : 0;

		if ( $page_id && $this->is_valid_profile_page( $page_id ) ) {
			$url = get_permalink( $page_id );
		}
		/**
		 * Permite ajustar la URL del perfil de alumno.
		 *
		 * @param string $url
		 * @param int    $user_id
		 */
		if ( ! $url ) {
			$page_id = absint( apply_filters( 'clms_student_profile_page_id', 0 ) );
			if ( $page_id && $this->is_valid_profile_page( $page_id ) ) {
				$url = get_permalink( $page_id );
			}
			$url = apply_filters( 'clms_student_profile_url', $url, $user_id );
		}

		if ( ! $url ) {
			$page_id = $this->find_profile_page_id_by_shortcode();
			if ( ! $page_id ) {
				$page_id = $this->find_profile_page_id_by_slug();
			}
			if ( $page_id ) {
				$url = get_permalink( $page_id );
			}
		}

		return $url ?: '';
	}

	protected function is_valid_profile_page( $page_id ) {
		$page_id = absint( $page_id );
		if ( ! $page_id ) {
			return false;
		}
		$post = get_post( $page_id );
		return $post && 'page' === $post->post_type && 'publish' === $post->post_status;
	}

	protected function find_profile_page_id_by_shortcode() {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 5,
				's'              => self::SHORTCODE,
			)
		);

		foreach ( $pages as $page ) {
			if ( $page && has_shortcode( $page->post_content, self::SHORTCODE ) ) {
				return absint( $page->ID );
			}
		}

		return 0;
	}

	protected function find_profile_page_id_by_slug() {
		$slugs = array( 'perfil-alumno', 'perfil-del-alumno', 'mi-perfil', 'perfil' );

		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && 'page' === $page->post_type && 'publish' === $page->post_status ) {
				if ( has_shortcode( $page->post_content, self::SHORTCODE ) ) {
					return absint( $page->ID );
				}
			}
		}

		return 0;
	}

	// ── Shortcode ─────────────────────────────────────────────────────────────

	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array( 'user_id' => 0 ),
			$atts,
			self::SHORTCODE
		);

		if ( ! is_user_logged_in() ) {
			return $this->gate_html(
				__( 'Inicia sesión para ver tu perfil.', 'atora-lms' ),
				wp_login_url( get_permalink() ),
				__( 'Iniciar sesión', 'atora-lms' )
			);
		}

		$viewer_id = get_current_user_id();
		$target_id = absint( $atts['user_id'] );

		// Si piden otro perfil, solo admins/teachers pueden verlo
		if ( $target_id && $target_id !== $viewer_id ) {
			if ( ! $this->viewer_can_access_student_profile( $viewer_id, $target_id ) ) {
				return $this->gate_html( __( 'No tienes permisos para ver este perfil.', 'atora-lms' ) );
			}
			$profile_user_id = $target_id;
			$is_own_profile  = false;
		} else {
			$profile_user_id = $viewer_id;
			$is_own_profile  = true;
		}

		$user = get_userdata( $profile_user_id );
		if ( ! $user ) {
			return '<p>' . esc_html__( 'Usuario no encontrado.', 'atora-lms' ) . '</p>';
		}

		$this->enqueue_assets();

		// ── Datos ──────────────────────────────────────────────────────────────
		$display_name   = $user->display_name ?: $user->user_login;
		$bio            = get_user_meta( $profile_user_id, 'description', true );
		$avatar_url     = get_avatar_url( $profile_user_id, array( 'size' => 120 ) );
		$member_since   = mysql2date( 'd/m/Y', $user->user_registered );
		$profile_meta   = $this->get_profile_fields( $profile_user_id );

		// Cursos inscritos
		$enrolled_ids = get_user_meta( $profile_user_id, '_clms_enrolled_courses', true );
		$enrolled_ids = is_array( $enrolled_ids ) ? array_unique( array_map( 'absint', $enrolled_ids ) ) : array();

		// Si también están en post meta del curso
		if ( empty( $enrolled_ids ) ) {
			$enrolled_ids = $this->get_enrolled_courses_from_posts( $profile_user_id );
		}

		// Lecciones completadas
		$completed_lessons = get_user_meta( $profile_user_id, '_clms_completed_lessons', true );
		$completed_lessons = is_array( $completed_lessons ) ? array_map( 'absint', $completed_lessons ) : array();

		// Cursos con progreso
		$courses_in_progress = array();
		$courses_completed   = array();

		foreach ( $enrolled_ids as $course_id ) {
			if ( 'lm_course' !== get_post_type( $course_id ) ) {
				continue;
			}

			$lesson_ids = array();
			if ( class_exists( 'CLMS_Helper' ) ) {
				$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );
				$lesson_ids = is_array( $lesson_ids ) ? array_map( 'absint', $lesson_ids ) : array();
			}

			$total    = count( $lesson_ids );
			$done     = $total > 0 ? count( array_intersect( $lesson_ids, $completed_lessons ) ) : 0;
			$progress = $total > 0 ? (int) round( $done / $total * 100 ) : 0;

			$thumb = has_post_thumbnail( $course_id )
				? get_the_post_thumbnail_url( $course_id, 'medium' )
				: '';

			$entry = array(
				'id'       => $course_id,
				'title'    => get_the_title( $course_id ),
				'url'      => get_permalink( $course_id ),
				'thumb'    => $thumb,
				'total'    => $total,
				'done'     => $done,
				'progress' => $progress,
			);

			if ( $progress >= 100 ) {
				$courses_completed[] = $entry;
			} else {
				$courses_in_progress[] = $entry;
			}
		}

		// Certificados obtenidos
		$certificates = array();
		foreach ( $courses_completed as $c ) {
			$cert_url = $this->maybe_get_certificate_url( $profile_user_id, $c['id'] );
			if ( $cert_url ) {
				$certificates[] = array_merge( $c, array( 'cert_url' => $cert_url ) );
			}
		}

		// Últimas entregas
		$submissions = $this->get_recent_submissions( $profile_user_id, 5 );

		// Métricas globales
		$metrics = array(
			'courses_total'    => count( $enrolled_ids ),
			'courses_done'     => count( $courses_completed ),
			'lessons_done'     => count( $completed_lessons ),
			'certificates'     => count( $certificates ),
		);

		$institutional = array();
		$report_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Report_Service') : null;
		if ( $report_service && method_exists( $report_service, 'get_student_profile_report' ) ) {
			$institutional = (array) $report_service->get_student_profile_report( $profile_user_id, $viewer_id );
		}

		// ── Render ─────────────────────────────────────────────────────────────
		ob_start();
		?>
		<div class="clms-sp">

			<!-- ── Header de perfil ─────────────────────────────────────────── -->
			<div class="clms-sp-hero">

				<div class="clms-sp-hero__identity">
					<div class="clms-sp-avatar">
						<img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $display_name ); ?>" width="96" height="96">
					</div>
					<div class="clms-sp-hero__info">
						<h1 class="clms-sp-hero__name"><?php echo esc_html( $display_name ); ?></h1>
						<p class="clms-sp-hero__since"><?php esc_html_e( 'Miembro desde', 'atora-lms' ); ?> <?php echo esc_html( $member_since ); ?></p>

						<?php if ( $bio ) : ?>
							<p class="clms-sp-hero__bio" id="clms-sp-bio-text" data-atora-bio-empty="<?php echo esc_attr__( 'Añade una pequeña presentación sobre ti.', 'atora-lms' ); ?>"><?php echo esc_html( $bio ); ?></p>
						<?php elseif ( $is_own_profile ) : ?>
							<p class="clms-sp-hero__bio clms-sp-hero__bio--placeholder" id="clms-sp-bio-text" data-atora-bio-empty="<?php echo esc_attr__( 'Añade una pequeña presentación sobre ti.', 'atora-lms' ); ?>"><?php esc_html_e( 'Añade una pequeña presentación sobre ti.', 'atora-lms' ); ?></p>
						<?php endif; ?>

						<?php if ( $is_own_profile ) : ?>
							<button class="clms-sp-edit-bio-btn" id="clms-sp-edit-bio-btn" type="button" data-label-edit="<?php echo esc_attr__( 'Editar presentación', 'atora-lms' ); ?>" data-label-add="<?php echo esc_attr__( 'Añadir presentación', 'atora-lms' ); ?>">
								<?php echo $bio ? esc_html__( 'Editar presentación', 'atora-lms' ) : esc_html__( 'Añadir presentación', 'atora-lms' ); ?>
							</button>
							<div class="clms-sp-bio-form" id="clms-sp-bio-form" style="display:none" data-atora-bio-form>
								<textarea class="clms-sp-bio-input" id="clms-sp-bio-input" rows="3" maxlength="300" placeholder="<?php echo esc_attr__( 'Cuéntale algo a tus compañeros...', 'atora-lms' ); ?>" data-atora-bio-input><?php echo esc_textarea( $bio ); ?></textarea>
								<div class="clms-sp-bio-form__actions">
									<button class="clms-sp-btn clms-sp-btn--primary" id="clms-sp-bio-save" type="button" data-atora-bio-save><?php esc_html_e( 'Guardar', 'atora-lms' ); ?></button>
									<button class="clms-sp-btn clms-sp-btn--ghost" id="clms-sp-bio-cancel" type="button" data-atora-bio-cancel><?php esc_html_e( 'Cancelar', 'atora-lms' ); ?></button>
								</div>
								<span class="clms-sp-bio-status" data-atora-status></span>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="clms-sp-hero__metrics">
					<div class="clms-sp-metric">
						<span class="clms-sp-metric__val"><?php echo esc_html( $metrics['courses_total'] ); ?></span>
						<span class="clms-sp-metric__lbl"><?php esc_html_e( 'Cursos inscritos', 'atora-lms' ); ?></span>
					</div>
					<div class="clms-sp-metric">
						<span class="clms-sp-metric__val"><?php echo esc_html( $metrics['courses_done'] ); ?></span>
						<span class="clms-sp-metric__lbl"><?php esc_html_e( 'Completados', 'atora-lms' ); ?></span>
					</div>
					<div class="clms-sp-metric">
						<span class="clms-sp-metric__val"><?php echo esc_html( $metrics['lessons_done'] ); ?></span>
						<span class="clms-sp-metric__lbl"><?php esc_html_e( 'Lecciones', 'atora-lms' ); ?></span>
					</div>
					<div class="clms-sp-metric">
						<span class="clms-sp-metric__val"><?php echo esc_html( $metrics['certificates'] ); ?></span>
						<span class="clms-sp-metric__lbl"><?php esc_html_e( 'Certificados', 'atora-lms' ); ?></span>
					</div>
				</div>

			</div><!-- .clms-sp-hero -->

			<!-- ── Perfil de aprendizaje ───────────────────────────────────── -->
			<section class="clms-sp-section clms-sp-section--profile">
				<h2 class="clms-sp-section__title"><?php esc_html_e( 'Perfil de aprendizaje', 'atora-lms' ); ?></h2>
				<p class="clms-sp-section__desc"><?php esc_html_e( 'Cuéntanos tu enfoque para personalizar tu experiencia.', 'atora-lms' ); ?></p>

				<?php if ( $is_own_profile ) : ?>
					<div class="clms-sp-profile"
						data-atora-student-profile
						data-atora-profile-endpoint="<?php echo esc_url( rest_url( 'clms/v1/me/profile' ) ); ?>"
						data-atora-profile-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
						<label class="clms-sp-field">
							<span class="clms-sp-field__label"><?php esc_html_e( 'Intereses', 'atora-lms' ); ?></span>
							<textarea class="clms-sp-field__input" rows="3" maxlength="400" placeholder="<?php echo esc_attr__( 'Ej. UX, marketing, IA aplicada', 'atora-lms' ); ?>" data-atora-profile-field="interests"><?php echo esc_textarea( $profile_meta['interests'] ); ?></textarea>
						</label>
						<label class="clms-sp-field">
							<span class="clms-sp-field__label"><?php esc_html_e( 'Nivel previo', 'atora-lms' ); ?></span>
							<select class="clms-sp-field__input" data-atora-profile-field="level">
								<option value=""><?php esc_html_e( 'Selecciona tu nivel', 'atora-lms' ); ?></option>
								<option value="principiante" <?php selected( $profile_meta['level'], 'principiante' ); ?>><?php esc_html_e( 'Principiante', 'atora-lms' ); ?></option>
								<option value="intermedio" <?php selected( $profile_meta['level'], 'intermedio' ); ?>><?php esc_html_e( 'Intermedio', 'atora-lms' ); ?></option>
								<option value="avanzado" <?php selected( $profile_meta['level'], 'avanzado' ); ?>><?php esc_html_e( 'Avanzado', 'atora-lms' ); ?></option>
							</select>
						</label>
						<label class="clms-sp-field">
							<span class="clms-sp-field__label"><?php esc_html_e( 'Objetivos', 'atora-lms' ); ?></span>
							<textarea class="clms-sp-field__input" rows="3" maxlength="400" placeholder="<?php echo esc_attr__( 'Ej. Terminar este programa en 8 semanas', 'atora-lms' ); ?>" data-atora-profile-field="goals"><?php echo esc_textarea( $profile_meta['goals'] ); ?></textarea>
						</label>
						<label class="clms-sp-field">
							<span class="clms-sp-field__label"><?php esc_html_e( 'Área profesional', 'atora-lms' ); ?></span>
							<input class="clms-sp-field__input" type="text" maxlength="120" placeholder="<?php echo esc_attr__( 'Ej. Producto, Ingeniería, Educación', 'atora-lms' ); ?>" value="<?php echo esc_attr( $profile_meta['area'] ); ?>" data-atora-profile-field="area">
						</label>
						<label class="clms-sp-check">
							<input type="checkbox" value="1" data-atora-profile-field="consent" <?php checked( ! empty( $profile_meta['consent'] ) ); ?>>
							<span><?php esc_html_e( 'Quiero recomendaciones personalizadas y recordatorios útiles.', 'atora-lms' ); ?></span>
						</label>
						<div class="clms-sp-profile__actions">
							<button class="clms-sp-btn clms-sp-btn--primary" type="button" data-atora-profile-save><?php esc_html_e( 'Guardar perfil', 'atora-lms' ); ?></button>
							<span class="clms-sp-profile__status" data-atora-status></span>
						</div>
					</div>
				<?php else : ?>
					<div class="clms-sp-profile clms-sp-profile--readonly">
						<?php if ( $this->has_profile_data( $profile_meta ) ) : ?>
							<?php if ( $profile_meta['interests'] ) : ?>
							<p class="clms-sp-profile__line"><strong><?php esc_html_e( 'Intereses:', 'atora-lms' ); ?></strong> <?php echo esc_html( $profile_meta['interests'] ); ?></p>
						<?php endif; ?>
						<?php if ( $profile_meta['level'] ) : ?>
							<p class="clms-sp-profile__line"><strong><?php esc_html_e( 'Nivel:', 'atora-lms' ); ?></strong> <?php echo esc_html( ucfirst( $profile_meta['level'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( $profile_meta['goals'] ) : ?>
							<p class="clms-sp-profile__line"><strong><?php esc_html_e( 'Objetivos:', 'atora-lms' ); ?></strong> <?php echo esc_html( $profile_meta['goals'] ); ?></p>
						<?php endif; ?>
						<?php if ( $profile_meta['area'] ) : ?>
							<p class="clms-sp-profile__line"><strong><?php esc_html_e( 'Área profesional:', 'atora-lms' ); ?></strong> <?php echo esc_html( $profile_meta['area'] ); ?></p>
						<?php endif; ?>
					<?php else : ?>
						<p class="clms-sp-section__desc"><?php esc_html_e( 'Este estudiante aún no ha completado su perfil.', 'atora-lms' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			</section>

			<?php if ( ! empty( $institutional ) ) : ?>
				<section class="clms-sp-section clms-sp-section--institutional">
					<h2 class="clms-sp-section__title"><?php esc_html_e( 'Estado académico institucional', 'atora-lms' ); ?></h2>
					<div class="clms-sp-inst-grid">
						<div class="clms-sp-inst-item">
							<strong><?php esc_html_e( 'Progreso global', 'atora-lms' ); ?></strong>
							<span><?php echo esc_html( absint( $institutional['progress_global'] ?? 0 ) ); ?>%</span>
						</div>
						<div class="clms-sp-inst-item">
							<strong><?php esc_html_e( 'Promedio global', 'atora-lms' ); ?></strong>
							<span><?php echo esc_html( absint( $institutional['average_global'] ?? 0 ) ); ?>%</span>
						</div>
						<div class="clms-sp-inst-item">
							<strong><?php esc_html_e( 'Evidencias aprobadas', 'atora-lms' ); ?></strong>
							<span><?php echo esc_html( absint( $institutional['evidences_approved'] ?? 0 ) ); ?></span>
						</div>
						<div class="clms-sp-inst-item">
							<strong><?php esc_html_e( 'Evidencias pendientes', 'atora-lms' ); ?></strong>
							<span><?php echo esc_html( absint( $institutional['evidences_pending'] ?? 0 ) ); ?></span>
						</div>
					</div>

					<?php
					$risk = isset( $institutional['risk'] ) && is_array( $institutional['risk'] ) ? $institutional['risk'] : array();
					$risk_level = sanitize_key( (string) ( $risk['risk_level'] ?? 'low' ) );
					$risk_label_map = array(
						'high'    => __( 'Alto', 'atora-lms' ),
						'medium'  => __( 'Medio', 'atora-lms' ),
						'low'     => __( 'Bajo', 'atora-lms' ),
						'normal'  => __( 'Bajo', 'atora-lms' ),
						'unknown' => __( 'Sin datos', 'atora-lms' ),
					);
					$risk_label = isset( $risk_label_map[ $risk_level ] ) ? $risk_label_map[ $risk_level ] : $risk_label_map['unknown'];
					?>
					<p class="clms-sp-inst-risk"><strong><?php esc_html_e( 'Riesgo académico:', 'atora-lms' ); ?></strong> <?php echo esc_html( $risk_label ); ?></p>
					<?php if ( ! empty( $risk['risk_reasons'] ) && is_array( $risk['risk_reasons'] ) ) : ?>
						<p class="clms-sp-section__desc"><?php echo esc_html( implode( ' · ', array_map( 'sanitize_text_field', $risk['risk_reasons'] ) ) ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $risk['recommended_action'] ) ) : ?>
						<p class="clms-sp-section__desc"><strong><?php esc_html_e( 'Acción recomendada:', 'atora-lms' ); ?></strong> <?php echo esc_html( sanitize_text_field( (string) $risk['recommended_action'] ) ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $institutional['competencies_developed'] ) && is_array( $institutional['competencies_developed'] ) ) : ?>
						<p class="clms-sp-section__desc"><strong><?php esc_html_e( 'Competencias desarrolladas:', 'atora-lms' ); ?></strong> <?php echo esc_html( implode( ', ', array_slice( array_map( 'sanitize_text_field', $institutional['competencies_developed'] ), 0, 6 ) ) ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $institutional['competencies_in_progress'] ) && is_array( $institutional['competencies_in_progress'] ) ) : ?>
						<p class="clms-sp-section__desc"><strong><?php esc_html_e( 'Competencias en progreso:', 'atora-lms' ); ?></strong> <?php echo esc_html( implode( ', ', array_slice( array_map( 'sanitize_text_field', $institutional['competencies_in_progress'] ), 0, 6 ) ) ); ?></p>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<!-- ── Cursos en progreso ────────────────────────────────────────── -->
			<?php if ( ! empty( $courses_in_progress ) ) : ?>
				<section class="clms-sp-section">
					<h2 class="clms-sp-section__title">
						<?php esc_html_e( 'En progreso', 'atora-lms' ); ?>
						<span class="clms-sp-section__count"><?php echo esc_html( count( $courses_in_progress ) ); ?></span>
					</h2>
					<div class="clms-sp-courses">
						<?php foreach ( $courses_in_progress as $c ) : ?>
							<a href="<?php echo esc_url( $c['url'] ); ?>" class="clms-sp-course">
								<div class="clms-sp-course__thumb">
									<?php if ( $c['thumb'] ) : ?>
										<img src="<?php echo esc_url( $c['thumb'] ); ?>" alt="" loading="lazy">
									<?php else : ?>
										<div class="clms-sp-course__thumb-placeholder">📚</div>
									<?php endif; ?>
									<div class="clms-sp-course__progress-overlay">
										<span><?php echo esc_html( $c['progress'] ); ?>%</span>
									</div>
								</div>
								<div class="clms-sp-course__body">
									<h3 class="clms-sp-course__title"><?php echo esc_html( $c['title'] ); ?></h3>
									<p class="clms-sp-course__meta"><?php echo esc_html( $c['done'] ); ?>/<?php echo esc_html( $c['total'] ); ?> <?php esc_html_e( 'lecciones', 'atora-lms' ); ?></p>
									<div class="clms-sp-progress">
										<div class="clms-sp-progress__fill" style="width:<?php echo esc_attr( $c['progress'] ); ?>%"></div>
									</div>
								</div>
							</a>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<!-- ── Cursos completados ────────────────────────────────────────── -->
			<?php if ( ! empty( $courses_completed ) ) : ?>
				<section class="clms-sp-section">
					<h2 class="clms-sp-section__title">
						<?php esc_html_e( 'Completados', 'atora-lms' ); ?>
						<span class="clms-sp-section__count"><?php echo esc_html( count( $courses_completed ) ); ?></span>
					</h2>
					<div class="clms-sp-courses">
						<?php foreach ( $courses_completed as $c ) : ?>
							<a href="<?php echo esc_url( $c['url'] ); ?>" class="clms-sp-course clms-sp-course--done">
								<div class="clms-sp-course__thumb">
									<?php if ( $c['thumb'] ) : ?>
										<img src="<?php echo esc_url( $c['thumb'] ); ?>" alt="" loading="lazy">
									<?php else : ?>
										<div class="clms-sp-course__thumb-placeholder">🎓</div>
									<?php endif; ?>
									<div class="clms-sp-course__done-badge">✓</div>
								</div>
								<div class="clms-sp-course__body">
									<h3 class="clms-sp-course__title"><?php echo esc_html( $c['title'] ); ?></h3>
									<p class="clms-sp-course__meta clms-sp-course__meta--green">100% <?php esc_html_e( 'completado', 'atora-lms' ); ?> · <?php echo esc_html( $c['total'] ); ?> <?php esc_html_e( 'lecciones', 'atora-lms' ); ?></p>
									<div class="clms-sp-progress">
										<div class="clms-sp-progress__fill clms-sp-progress__fill--green" style="width:100%"></div>
									</div>
								</div>
							</a>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<!-- Sin cursos -->
			<?php if ( empty( $courses_in_progress ) && empty( $courses_completed ) ) : ?>
				<section class="clms-sp-section">
					<div class="clms-sp-empty">
						<div class="clms-sp-empty__icon">📚</div>
						<p><?php $is_own_profile ? esc_html_e( 'Aún no estás inscrito en ningún curso.', 'atora-lms' ) : esc_html_e( 'Este estudiante aún no tiene cursos.', 'atora-lms' ); ?></p>
					</div>
				</section>
			<?php endif; ?>

			<!-- ── Certificados ──────────────────────────────────────────────── -->
			<?php if ( ! empty( $certificates ) ) : ?>
				<section class="clms-sp-section">
					<h2 class="clms-sp-section__title">
						<?php esc_html_e( 'Certificados', 'atora-lms' ); ?>
						<span class="clms-sp-section__count"><?php echo esc_html( count( $certificates ) ); ?></span>
					</h2>
					<div class="clms-sp-certs">
						<?php foreach ( $certificates as $cert ) : ?>
							<div class="clms-sp-cert">
								<div class="clms-sp-cert__icon">🎓</div>
								<div class="clms-sp-cert__info">
									<p class="clms-sp-cert__course"><?php echo esc_html( $cert['title'] ); ?></p>
									<p class="clms-sp-cert__label"><?php esc_html_e( 'Certificado obtenido', 'atora-lms' ); ?></p>
								</div>
								<a href="<?php echo esc_url( $cert['cert_url'] ); ?>" class="clms-sp-btn clms-sp-btn--outline clms-sp-btn--sm" target="_blank" rel="noopener">
									<?php esc_html_e( 'Ver', 'atora-lms' ); ?>
								</a>
							</div>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<!-- ── Últimas entregas ──────────────────────────────────────────── -->
			<?php if ( ! empty( $submissions ) ) : ?>
				<section class="clms-sp-section">
					<h2 class="clms-sp-section__title"><?php esc_html_e( 'Últimas entregas', 'atora-lms' ); ?></h2>
					<div class="clms-sp-submissions">
						<?php foreach ( $submissions as $sub ) : ?>
							<div class="clms-sp-sub">
								<div class="clms-sp-sub__info">
									<p class="clms-sp-sub__lesson"><?php echo esc_html( $sub['lesson_title'] ); ?></p>
									<p class="clms-sp-sub__course"><?php echo esc_html( $sub['course_title'] ); ?></p>
								</div>
								<div class="clms-sp-sub__aside">
									<?php if ( '' !== (string) $sub['grade'] ) : ?>
										<span class="clms-sp-sub__grade"><?php echo esc_html( $sub['grade'] ); ?><small>/100</small></span>
									<?php endif; ?>
									<span class="clms-sp-badge clms-sp-badge--<?php echo esc_attr( $sub['status_class'] ); ?>">
										<?php echo esc_html( $sub['status_label'] ); ?>
									</span>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

		</div><!-- .clms-sp -->

		<?php
		return ob_get_clean();
	}

	// ── Helpers de datos ─────────────────────────────────────────────────────
	protected function get_profile_fields( $user_id ) {
		$user_id = absint( $user_id );

		return array(
			'interests' => (string) get_user_meta( $user_id, '_clms_student_interests', true ),
			'level'     => (string) get_user_meta( $user_id, '_clms_student_level', true ),
			'goals'     => (string) get_user_meta( $user_id, '_clms_student_goals', true ),
			'area'      => (string) get_user_meta( $user_id, '_clms_student_area', true ),
			'consent'   => (bool) get_user_meta( $user_id, '_clms_student_personalization_consent', true ),
		);
	}

	protected function has_profile_data( $profile ) {
		if ( ! is_array( $profile ) ) {
			return false;
		}

		foreach ( array( 'interests', 'level', 'goals', 'area' ) as $key ) {
			if ( ! empty( $profile[ $key ] ) ) {
				return true;
			}
		}

		return ! empty( $profile['consent'] );
	}

	protected function get_enrolled_courses_from_posts( $user_id ) {
		$user_id = absint( $user_id );
		$courses = get_posts(
			array(
				'post_type'      => 'lm_course',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => '_clms_enrolled_users',
						'value'   => '"' . absint( $user_id ) . '"',
						'compare' => 'LIKE',
					),
				),
			)
		);

		return is_array( $courses ) ? array_map( 'absint', $courses ) : array();
	}

	protected function maybe_get_certificate_url( $user_id, $course_id ) {
		$cert = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Certificates') : null;

		if ( ! $cert || ! method_exists( $cert, 'get_certificate_eligibility' ) ) {
			return '';
		}

		$eligibility = $cert->get_certificate_eligibility( $user_id, $course_id );

		if ( empty( $eligibility['eligible'] ) ) {
			return '';
		}

		return wp_nonce_url(
			admin_url( 'admin-post.php?action=clms_view_certificate&course_id=' . absint( $course_id ) ),
			'clms_view_certificate_' . absint( $user_id ) . '_' . absint( $course_id )
		);
	}

	protected function viewer_can_access_student_profile( $viewer_id, $student_id ) {
		$viewer_id  = absint( $viewer_id );
		$student_id = absint( $student_id );

		if ( ! $viewer_id || ! $student_id ) {
			return false;
		}

		if ( $viewer_id === $student_id ) {
			return true;
		}

		if ( current_user_can( 'manage_options' ) || current_user_can( 'clms_access_admin' ) ) {
			return true;
		}

		if ( ! class_exists( 'CLMS_Helper' ) ) {
			return false;
		}

		$student_courses = (array) ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $student_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $student_id ) );
		foreach ( $student_courses as $course_id ) {
			$course_id = absint( $course_id );
			if ( $course_id && CLMS_Helper::user_can_manage_lms( $course_id ) ) {
				return true;
			}
		}

		return false;
	}

	protected function get_recent_submissions( $user_id, $limit = 5 ) {
		$user_id = absint( $user_id );
		$limit   = max( 1, absint( $limit ) );

		$posts = get_posts(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => 'publish',
				'author'         => $user_id,
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( empty( $posts ) ) {
			return array();
		}

		$result = array();

		foreach ( $posts as $post ) {
			$lesson_id   = absint( get_post_meta( $post->ID, '_clms_submission_lesson_id', true ) );
			$course_id   = $lesson_id && class_exists( 'CLMS_Helper' )
				? absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) )
				: 0;
			$grade       = get_post_meta( $post->ID, '_clms_submission_grade', true );
			$status      = get_post_meta( $post->ID, '_clms_submission_status', true ) ?: 'pending';

			$status_map = array(
				'pending'   => array( 'label' => __( 'Pendiente', 'atora-lms' ), 'class' => 'pending' ),
				'reviewed'  => array( 'label' => __( 'Revisada', 'atora-lms' ), 'class' => 'reviewed' ),
				'graded'    => array( 'label' => __( 'Calificada', 'atora-lms' ), 'class' => 'graded' ),
			);

			$status_info = isset( $status_map[ $status ] ) ? $status_map[ $status ] : $status_map['pending'];

			$result[] = array(
				'id'           => $post->ID,
				'lesson_title' => $lesson_id ? get_the_title( $lesson_id ) : '—',
				'course_title' => $course_id ? get_the_title( $course_id ) : '—',
				'grade'        => '' !== (string) $grade ? absint( $grade ) : '',
				'status_label' => $status_info['label'],
				'status_class' => $status_info['class'],
				'date'         => get_the_date( 'd/m/Y', $post->ID ),
			);
		}

		return $result;
	}

	// ── Assets ───────────────────────────────────────────────────────────────

	protected function gate_html( $message, $link = '', $link_label = '' ) {
		$btn = $link
			? '<a href="' . esc_url( $link ) . '" class="clms-sp-btn clms-sp-btn--primary">' . esc_html( $link_label ) . '</a>'
			: '';

		return '<div class="clms-sp clms-sp--gate"><p>' . esc_html( $message ) . '</p>' . $btn . '</div>';
	}

	protected function enqueue_assets() {
		if ( self::$assets_enqueued ) {
			return;
		}
		self::$assets_enqueued = true;

		$base_url = defined( 'ATORA_LMS_URL' )
			? ATORA_LMS_URL
			: ATORA_LMS_URL;
		wp_enqueue_script(
			'atora-ui',
			$base_url . 'assets/js/atora-ui.js',
			array(),
			defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0',
			true
		);
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'add_ui_i18n_script' ) ) {
			CLMS_Helper::add_ui_i18n_script( 'atora-ui' );
		}
		$rest_config = array(
			'root'  => esc_url_raw( rest_url( 'clms/v1' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		);
		$rest_inline = 'window.ATORA = window.ATORA || {}; window.ATORA.rest = window.ATORA.rest || ' . wp_json_encode( $rest_config ) . ';';
		wp_add_inline_script( 'atora-ui', $rest_inline, 'before' );

		$css = '
		.clms-sp,.clms-sp *{box-sizing:border-box}
		.clms-sp{
			--sp-brand:#4353ff;
			--sp-brand-lt:#eef0ff;
			--sp-green:#22c55e;
			--sp-green-lt:#dcfce7;
			--sp-ink:#111827;
			--sp-ink-2:#374151;
			--sp-muted:#6b7280;
			--sp-border:#e5e7eb;
			--sp-bg:#f9fafb;
			--sp-white:#ffffff;
			--sp-shadow:0 4px 12px rgba(0,0,0,.06);
			--sp-radius:16px;
			font-family:inherit;
			max-width:880px;
			margin:0 auto;
			padding:0 0 48px;
			display:grid;
			gap:32px;
		}
		.clms-sp a{text-decoration:none}
		.clms-sp--gate{
			padding:48px 24px;
			text-align:center;
			display:grid;
			gap:16px;
			place-items:center;
		}
		/* Hero */
		.clms-sp-hero{
			background:linear-gradient(135deg,#0f172a 0%,#1e1b4b 60%,#312e81 100%);
			border-radius:24px;
			padding:32px;
			display:grid;
			gap:28px;
			color:#fff;
		}
		.clms-sp-hero__identity{
			display:flex;
			gap:20px;
			align-items:flex-start;
		}
		.clms-sp-avatar{
			flex-shrink:0;
		}
		.clms-sp-avatar img{
			width:96px;height:96px;
			border-radius:50%;
			border:3px solid rgba(255,255,255,.25);
			display:block;
			object-fit:cover;
		}
		.clms-sp-hero__info{
			min-width:0;
			display:grid;
			gap:6px;
			align-content:start;
		}
		.clms-sp-hero__name{
			margin:0;
			font-size:clamp(22px,3vw,32px);
			font-weight:800;
			line-height:1.15;
			color:#fff;
			letter-spacing:-.03em;
		}
		.clms-sp-hero__since{
			margin:0;
			font-size:13px;
			color:rgba(255,255,255,.55);
		}
		.clms-sp-hero__bio{
			margin:4px 0 0;
			font-size:15px;
			line-height:1.65;
			color:rgba(255,255,255,.82);
			overflow-wrap:anywhere;
		}
		.clms-sp-hero__bio--placeholder{
			color:rgba(255,255,255,.38);
			font-style:italic;
		}
		.clms-sp-edit-bio-btn{
			display:inline-flex;
			align-items:center;
			gap:6px;
			padding:6px 14px;
			border-radius:99px;
			background:rgba(255,255,255,.12);
			border:1px solid rgba(255,255,255,.2);
			color:#fff;
			font-size:13px;
			font-weight:600;
			cursor:pointer;
			margin-top:4px;
			transition:background .15s;
		}
		.clms-sp-edit-bio-btn:hover{background:rgba(255,255,255,.2)}
		.clms-sp-bio-form{display:grid;gap:10px;margin-top:8px}
		.clms-sp-bio-status{font-size:12px;color:rgba(255,255,255,.65)}
		.clms-sp-bio-input{
			width:100%;
			padding:10px 14px;
			border-radius:10px;
			border:1px solid rgba(255,255,255,.25);
			background:rgba(255,255,255,.1);
			color:#fff;
			font-size:14px;
			font-family:inherit;
			resize:vertical;
		}
		.clms-sp-bio-input::placeholder{color:rgba(255,255,255,.4)}
		.clms-sp-bio-form__actions{display:flex;gap:10px}
		.clms-sp-section--profile{background:var(--sp-white);border:1px solid var(--sp-border);border-radius:var(--sp-radius);padding:18px 20px}
		.clms-sp-section__desc{margin:0;color:var(--sp-muted);font-size:13px}
		.clms-sp-profile{display:grid;gap:14px;margin-top:14px}
		.clms-sp-profile--readonly{margin-top:8px}
		.clms-sp-profile__line{margin:0 0 8px;font-size:14px;color:var(--sp-ink-2)}
		.clms-sp-field{display:grid;gap:6px}
		.clms-sp-field__label{font-size:12px;font-weight:700;color:var(--sp-ink-2);text-transform:uppercase;letter-spacing:.04em}
		.clms-sp-field__input{
			width:100%;
			border:1px solid var(--sp-border);
			border-radius:12px;
			padding:10px 12px;
			font-size:14px;
			font-family:inherit;
			background:#fff;
			color:var(--sp-ink);
			resize:vertical;
		}
		.clms-sp-check{display:flex;align-items:flex-start;gap:10px;font-size:13px;color:var(--sp-ink-2)}
		.clms-sp-check input{margin-top:3px}
		.clms-sp-profile__actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
		.clms-sp-profile__status{font-size:12px;color:var(--sp-muted)}
		.clms-sp .atora-status-error{color:#ef4444}
		.clms-sp .atora-is-loading{opacity:.7;pointer-events:none}
		/* Metrics */
		.clms-sp-hero__metrics{
			display:grid;
			grid-template-columns:repeat(4,1fr);
			gap:12px;
		}
		.clms-sp-metric{
			background:rgba(255,255,255,.08);
			border:1px solid rgba(255,255,255,.12);
			border-radius:14px;
			padding:14px;
			display:grid;
			gap:4px;
			text-align:center;
		}
		.clms-sp-metric__val{
			font-size:28px;
			font-weight:800;
			color:#fff;
			line-height:1;
			letter-spacing:-.03em;
		}
		.clms-sp-metric__lbl{
			font-size:12px;
			color:rgba(255,255,255,.6);
			line-height:1.4;
		}
		/* Section */
		.clms-sp-section{display:grid;gap:16px}
		.clms-sp-section__title{
			margin:0;
			font-size:18px;
			font-weight:800;
			color:var(--sp-ink);
			display:flex;
			align-items:center;
			gap:8px;
		}
		.clms-sp-section__count{
			font-size:13px;
			font-weight:700;
			background:var(--sp-brand-lt);
			color:var(--sp-brand);
			padding:2px 10px;
			border-radius:99px;
		}
		/* Courses grid */
		.clms-sp-courses{
			display:grid;
			grid-template-columns:repeat(auto-fill,minmax(220px,1fr));
			gap:16px;
		}
		.clms-sp-course{
			background:var(--sp-white);
			border:1px solid var(--sp-border);
			border-radius:var(--sp-radius);
			overflow:hidden;
			display:flex;
			flex-direction:column;
			color:var(--sp-ink);
			transition:box-shadow .18s,transform .18s;
		}
		.clms-sp-course:hover{
			box-shadow:var(--sp-shadow);
			transform:translateY(-2px);
		}
		.clms-sp-course--done{border-color:#bbf7d0}
		.clms-sp-course__thumb{
			aspect-ratio:16/9;
			overflow:hidden;
			background:var(--sp-bg);
			position:relative;
			flex-shrink:0;
		}
		.clms-sp-course__thumb img{
			width:100%;height:100%;
			object-fit:cover;
			display:block;
			transition:transform .3s;
		}
		.clms-sp-course:hover .clms-sp-course__thumb img{transform:scale(1.04)}
		.clms-sp-course__thumb-placeholder{
			width:100%;height:100%;
			display:flex;align-items:center;justify-content:center;
			font-size:32px;
			background:linear-gradient(135deg,#eef0ff,#dde0ff);
		}
		.clms-sp-course__progress-overlay{
			position:absolute;bottom:8px;right:8px;
			background:rgba(0,0,0,.72);
			color:#fff;
			font-size:12px;font-weight:700;
			padding:3px 8px;
			border-radius:6px;
		}
		.clms-sp-course__done-badge{
			position:absolute;top:8px;right:8px;
			width:28px;height:28px;
			background:var(--sp-green);
			color:#fff;
			border-radius:50%;
			display:flex;align-items:center;justify-content:center;
			font-size:14px;font-weight:700;
		}
		.clms-sp-course__body{
			padding:14px 14px 16px;
			display:grid;
			gap:6px;
		}
		.clms-sp-course__title{
			margin:0;
			font-size:15px;
			font-weight:700;
			line-height:1.35;
			color:var(--sp-ink);
		}
		.clms-sp-course__meta{
			margin:0;
			font-size:12px;
			color:var(--sp-muted);
		}
		.clms-sp-course__meta--green{color:#166534}
		/* Progress bar */
		.clms-sp-progress{
			height:5px;
			background:var(--sp-border);
			border-radius:99px;
			overflow:hidden;
			margin-top:4px;
		}
		.clms-sp-progress__fill{
			height:100%;
			background:var(--sp-brand);
			border-radius:99px;
			transition:width .5s ease;
		}
		.clms-sp-progress__fill--green{background:var(--sp-green)}
		/* Certificates */
		.clms-sp-certs{display:grid;gap:12px}
		.clms-sp-cert{
			display:flex;
			align-items:center;
			gap:14px;
			padding:16px 20px;
			background:var(--sp-white);
			border:1px solid #bbf7d0;
			border-radius:var(--sp-radius);
		}
		.clms-sp-cert__icon{font-size:28px;flex-shrink:0}
		.clms-sp-cert__info{flex:1;min-width:0}
		.clms-sp-cert__course{margin:0;font-size:15px;font-weight:700;color:var(--sp-ink)}
		.clms-sp-cert__label{margin:0;font-size:12px;color:#166534;font-weight:600}
		/* Submissions */
		.clms-sp-submissions{display:grid;gap:10px}
		.clms-sp-sub{
			display:flex;
			align-items:center;
			justify-content:space-between;
			gap:16px;
			padding:14px 18px;
			background:var(--sp-white);
			border:1px solid var(--sp-border);
			border-radius:14px;
		}
		.clms-sp-sub__info{min-width:0}
		.clms-sp-sub__lesson{margin:0;font-size:14px;font-weight:700;color:var(--sp-ink)}
		.clms-sp-sub__course{margin:0;font-size:12px;color:var(--sp-muted)}
		.clms-sp-sub__aside{display:flex;align-items:center;gap:10px;flex-shrink:0}
		.clms-sp-sub__grade{
			font-size:18px;font-weight:800;
			color:var(--sp-ink);
			line-height:1;
		}
		.clms-sp-sub__grade small{font-size:11px;font-weight:500;color:var(--sp-muted)}
		/* Badges */
		.clms-sp-badge{
			display:inline-flex;
			padding:4px 10px;
			border-radius:99px;
			font-size:11px;font-weight:700;
			text-transform:uppercase;
			letter-spacing:.04em;
		}
		.clms-sp-badge--pending{background:#fef3c7;color:#92400e}
		.clms-sp-badge--reviewed,.clms-sp-badge--graded{background:#dcfce7;color:#166534}
		/* Buttons */
		.clms-sp-btn{
			display:inline-flex;align-items:center;justify-content:center;
			padding:9px 18px;
			border-radius:10px;
			font-size:14px;font-weight:600;
			border:2px solid transparent;
			cursor:pointer;
			transition:all .15s;
		}
		.clms-sp-btn--primary{
			background:var(--sp-brand);
			color:#fff;
			border-color:var(--sp-brand);
		}
		.clms-sp-btn--primary:hover{background:#3241e0;color:#fff}
		.clms-sp-btn--ghost{
			background:rgba(255,255,255,.12);
			color:#fff;
			border-color:rgba(255,255,255,.2);
		}
		.clms-sp-btn--ghost:hover{background:rgba(255,255,255,.2);color:#fff}
		.clms-sp-btn--outline{
			background:transparent;
			color:var(--sp-brand);
			border-color:var(--sp-brand);
		}
		.clms-sp-btn--outline:hover{background:var(--sp-brand-lt)}
		.clms-sp-btn--sm{padding:6px 12px;font-size:12px}
		/* Empty state */
		.clms-sp-empty{
			padding:40px 20px;
			text-align:center;
			color:var(--sp-muted);
			border:1px dashed var(--sp-border);
			border-radius:var(--sp-radius);
		}
		.clms-sp-empty__icon{font-size:36px;margin-bottom:12px}
		/* Responsive */
		@media (max-width:600px){
			.clms-sp-hero{padding:20px}
			.clms-sp-hero__identity{flex-direction:column;align-items:center;text-align:center}
			.clms-sp-hero__metrics{grid-template-columns:repeat(2,1fr)}
			.clms-sp-courses{grid-template-columns:1fr}
			.clms-sp-sub{flex-direction:column;align-items:flex-start}
			.clms-sp-section--profile{padding:16px}
			.clms-sp-profile__actions{flex-direction:column;align-items:stretch}
			.clms-sp-profile__actions .clms-sp-btn{width:100%}
		}
		';

		wp_register_style( 'clms-student-profile', false, array(), defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0' );
		wp_enqueue_style( 'clms-student-profile' );
		wp_add_inline_style( 'clms-student-profile', $css );
	}
}
