<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Instructor {

	protected static $assets_enqueued = false;

	public function __construct() {
		add_shortcode( 'clms_course_instructor', array( $this, 'render_course_instructor_shortcode' ) );
		add_shortcode( 'clms_instructor_profile', array( $this, 'render_instructor_profile_shortcode' ) );
		add_shortcode( 'clms_teachers', array( $this, 'render_teachers_shortcode' ) );
		add_action( 'init', array( $this, 'register_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'template_include', array( $this, 'maybe_load_profile_template' ), 30 );
		add_action( 'add_meta_boxes', array( $this, 'add_teacher_meta_boxes' ) );
		add_action( 'save_post_atora_teacher', array( $this, 'save_teacher_meta' ), 20, 2 );

		add_action( 'show_user_profile', array( $this, 'render_user_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_fields' ) );

		add_action( 'personal_options_update', array( $this, 'save_user_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_fields' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets() {
		wp_register_style( 'clms-instructor', false, array( 'clms-ui' ), defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0' );
	}

	public function add_teacher_meta_boxes() {
		add_meta_box(
			'clms_teacher_profile',
			'Perfil del Docente',
			array( $this, 'render_teacher_meta_box' ),
			'atora_teacher',
			'normal',
			'high'
		);
	}

	public function render_teacher_meta_box( $post ) {
		if ( ! $post || 'atora_teacher' !== $post->post_type ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		wp_nonce_field( 'clms_save_teacher_meta', 'clms_teacher_nonce' );

		$short_bio    = (string) get_post_field( 'post_excerpt', $post->ID );
		$specialty    = (string) get_post_meta( $post->ID, '_clms_teacher_specialty', true );
		$achievements = (string) get_post_meta( $post->ID, '_clms_teacher_achievements', true );
		$socials      = (string) get_post_meta( $post->ID, '_clms_teacher_socials', true );
		$is_public    = get_post_meta( $post->ID, '_clms_teacher_public', true );
		$video_url    = (string) get_post_meta( $post->ID, '_clms_teacher_video', true );
		$extra_title  = (string) get_post_meta( $post->ID, '_clms_teacher_extra_title', true );
		$extra_content = (string) get_post_meta( $post->ID, '_clms_teacher_extra_content', true );
		?>
		<p class="description">Nombre y foto: se gestionan desde el título y la imagen destacada.</p>

		<p>
			<label for="clms_teacher_short_bio"><strong>Bio corta</strong></label><br>
			<textarea id="clms_teacher_short_bio" name="clms_teacher_short_bio" rows="3" style="width:100%"><?php echo esc_textarea( $short_bio ); ?></textarea>
		</p>

		<p>
			<label for="clms_teacher_specialty"><strong>Especialidad</strong></label><br>
			<input id="clms_teacher_specialty" type="text" name="clms_teacher_specialty" value="<?php echo esc_attr( $specialty ); ?>" style="width:100%">
		</p>

		<p>
			<label for="clms_teacher_achievements"><strong>Logros</strong></label><br>
			<textarea id="clms_teacher_achievements" name="clms_teacher_achievements" rows="3" style="width:100%" placeholder="Uno por línea"><?php echo esc_textarea( $achievements ); ?></textarea>
		</p>

		<p>
			<label for="clms_teacher_video"><strong>Video (junto a los logros)</strong></label><br>
			<input id="clms_teacher_video" type="url" name="clms_teacher_video" value="<?php echo esc_attr( $video_url ); ?>" style="width:100%" placeholder="https://youtube.com/watch?v=... o https://vimeo.com/...">
			<span class="description">YouTube, Vimeo o URL directa de video. Aparece en columna derecha junto a los logros.</span>
		</p>

		<p>
			<label for="clms_teacher_socials"><strong>Redes</strong></label><br>
			<textarea id="clms_teacher_socials" name="clms_teacher_socials" rows="3" style="width:100%" placeholder="LinkedIn | https://linkedin.com/in/..."><?php echo esc_textarea( $socials ); ?></textarea>
			<span class="description">Formato: Nombre | URL (una por línea).</span>
		</p>

		<hr style="margin:16px 0">
		<p><strong>Sección extra (aparece al final del perfil, antes del footer)</strong></p>

		<p>
			<label for="clms_teacher_extra_title"><strong>Título (H2)</strong></label><br>
			<input id="clms_teacher_extra_title" type="text" name="clms_teacher_extra_title" value="<?php echo esc_attr( $extra_title ); ?>" style="width:100%" placeholder="Ej: ¿Quieres trabajar conmigo?">
		</p>

		<p>
			<label for="clms_teacher_extra_content"><strong>Contenido</strong></label><br>
			<textarea id="clms_teacher_extra_content" name="clms_teacher_extra_content" rows="5" style="width:100%" placeholder="Texto libre, acepta HTML básico"><?php echo esc_textarea( $extra_content ); ?></textarea>
		</p>

		<hr style="margin:16px 0">

		<p>
			<label>
				<input type="checkbox" name="clms_teacher_public" value="1" <?php checked( (string) $is_public, '1' ); ?>>
				Visible en páginas públicas
			</label>
		</p>

		<p class="description">Bio larga: usa el editor principal de contenido del docente.</p>
		<?php
	}

	public function save_teacher_meta( $post_id, $post ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || ! $post || 'atora_teacher' !== $post->post_type ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( empty( $_POST['clms_teacher_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['clms_teacher_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'clms_save_teacher_meta' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$short_bio     = isset( $_POST['clms_teacher_short_bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['clms_teacher_short_bio'] ) ) : '';
		$specialty     = isset( $_POST['clms_teacher_specialty'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_teacher_specialty'] ) ) : '';
		$achievements  = isset( $_POST['clms_teacher_achievements'] ) ? sanitize_textarea_field( wp_unslash( $_POST['clms_teacher_achievements'] ) ) : '';
		$socials       = isset( $_POST['clms_teacher_socials'] ) ? sanitize_textarea_field( wp_unslash( $_POST['clms_teacher_socials'] ) ) : '';
		$is_public     = ! empty( $_POST['clms_teacher_public'] ) ? '1' : '0';
		$video_url     = isset( $_POST['clms_teacher_video'] ) ? esc_url_raw( wp_unslash( $_POST['clms_teacher_video'] ) ) : '';
		$extra_title   = isset( $_POST['clms_teacher_extra_title'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_teacher_extra_title'] ) ) : '';
		$extra_content = isset( $_POST['clms_teacher_extra_content'] ) ? wp_kses_post( wp_unslash( $_POST['clms_teacher_extra_content'] ) ) : '';

		// Temporarily remove this hook to avoid an infinite loop when wp_update_post
		// re-fires save_post_atora_teacher for the same post ID.
		remove_action( 'save_post_atora_teacher', array( $this, 'save_teacher_meta' ), 20, 2 );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_excerpt' => $short_bio,
			)
		);
		add_action( 'save_post_atora_teacher', array( $this, 'save_teacher_meta' ), 20, 2 );

		update_post_meta( $post_id, '_clms_teacher_specialty', $specialty );
		update_post_meta( $post_id, '_clms_teacher_achievements', $achievements );
		update_post_meta( $post_id, '_clms_teacher_socials', $socials );
		update_post_meta( $post_id, '_clms_teacher_public', $is_public );
		update_post_meta( $post_id, '_clms_teacher_video', $video_url );
		update_post_meta( $post_id, '_clms_teacher_extra_title', $extra_title );
		update_post_meta( $post_id, '_clms_teacher_extra_content', $extra_content );
	}

	/**
	 * Crea un docente desde metabox con validaciones y deduplicación segura.
	 *
	 * @param array $payload Datos del docente.
	 * @return array{success:bool,teacher_id:int,message:string,code:string,reused:bool}
	 */
	public function create_teacher_from_metabox( $payload = array() ) {
		$result = array(
			'success'    => false,
			'teacher_id' => 0,
			'message'    => '',
			'code'       => '',
			'reused'     => false,
		);

		$name = isset( $payload['name'] ) ? sanitize_text_field( (string) $payload['name'] ) : '';
		$name = trim( $name );

		if ( '' === $name || strlen( $name ) < 2 ) {
			$result['message'] = __( 'El nombre del docente es obligatorio.', 'atora-lms' );
			$result['code']    = 'invalid_name';
			return $result;
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'create_atora_teachers' ) && ! current_user_can( 'edit_atora_teachers' ) ) {
			$result['message'] = __( 'No tienes permisos para crear docentes.', 'atora-lms' );
			$result['code']    = 'forbidden';
			return $result;
		}

		$user_id   = get_current_user_id();
		$dedupe_id = 0;
		$dedupe_key = 'clms_teacher_quick_' . md5( strtolower( $name ) . '|' . absint( $user_id ) );
		$cached_id  = absint( get_transient( $dedupe_key ) );
		if ( $cached_id && 'atora_teacher' === get_post_type( $cached_id ) ) {
			$dedupe_id = $cached_id;
		} else {
			$existing = get_page_by_title( $name, OBJECT, 'atora_teacher' );
			if ( $existing && absint( $existing->post_author ) === absint( $user_id ) ) {
				$created_at = $existing->post_date_gmt ? strtotime( $existing->post_date_gmt ) : strtotime( $existing->post_date );
				if ( $created_at && ( time() - $created_at ) < 15 * MINUTE_IN_SECONDS ) {
					$dedupe_id = absint( $existing->ID );
				}
			}
		}

		if ( $dedupe_id ) {
			set_transient( $dedupe_key, $dedupe_id, 15 * MINUTE_IN_SECONDS );
			$result['success']    = true;
			$result['teacher_id'] = $dedupe_id;
			$result['message']    = __( 'Se reutilizó un docente reciente con el mismo nombre.', 'atora-lms' );
			$result['code']       = 'reused_recent';
			$result['reused']     = true;
			return $result;
		}

		$short_bio = isset( $payload['short_bio'] ) ? sanitize_textarea_field( (string) $payload['short_bio'] ) : '';
		$specialty = isset( $payload['specialty'] ) ? sanitize_text_field( (string) $payload['specialty'] ) : '';
		$is_public = ! empty( $payload['public'] ) ? '1' : '0';
		$status    = '1' === $is_public ? 'publish' : 'draft';

		$insert = wp_insert_post(
			array(
				'post_type'    => 'atora_teacher',
				'post_status'  => $status,
				'post_title'   => $name,
				'post_excerpt' => $short_bio,
				'post_author'  => $user_id,
			),
			true
		);

		if ( is_wp_error( $insert ) ) {
			$result['message'] = __( 'No se pudo crear el docente. Intenta nuevamente.', 'atora-lms' );
			$result['code']    = 'insert_failed';
			return $result;
		}

		$teacher_id = absint( $insert );

		if ( $specialty ) {
			update_post_meta( $teacher_id, '_clms_teacher_specialty', $specialty );
		}
		update_post_meta( $teacher_id, '_clms_teacher_public', $is_public );
		set_transient( $dedupe_key, $teacher_id, 15 * MINUTE_IN_SECONDS );

		$result['success']    = true;
		$result['teacher_id'] = $teacher_id;
		$result['message']    = __( 'Docente creado correctamente.', 'atora-lms' );
		$result['code']       = 'created';
		return $result;
	}

	protected function enqueue_assets() {
		if ( ! wp_style_is( 'clms-instructor', 'registered' ) ) {
			$this->register_assets();
		}

		wp_enqueue_style( 'clms-instructor' );

		if ( self::$assets_enqueued ) {
			return;
		}

		wp_add_inline_style( 'clms-instructor', $this->get_inline_css() );

		self::$assets_enqueued = true;
	}

	protected function get_inline_css() {
		return '
		.clms-instructor-box{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:20px;margin:0 0 20px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
		.clms-instructor-box *{box-sizing:border-box}
		.clms-instructor-head{display:grid;grid-template-columns:88px minmax(0,1fr);gap:16px;align-items:start;margin-bottom:16px}
		.clms-instructor-avatar img{width:88px;height:88px;border-radius:999px;display:block;object-fit:cover}
		.clms-instructor-name{margin:0 0 6px;font-size:22px;line-height:1.2;font-weight:700;color:#111827}
		.clms-instructor-role,.clms-instructor-tagline,.clms-instructor-credentials{margin:0 0 6px;color:#4b5563;line-height:1.5}
		.clms-instructor-bio{color:#111827;line-height:1.7;margin-top:14px}
		.clms-instructor-meta{display:flex;flex-wrap:wrap;gap:10px 14px;margin-top:12px;font-size:14px;color:#4b5563}
		.clms-instructor-meta a{color:#111827;text-decoration:none}
		.clms-instructor-meta a:hover{text-decoration:underline}
		.clms-instructor-courses{margin-top:18px;padding-top:18px;border-top:1px solid #e5e7eb}
		.clms-instructor-courses h4{margin:0 0 12px;font-size:16px;line-height:1.3;color:#111827}
		.clms-instructor-course-list{margin:0;padding-left:18px}
		.clms-instructor-course-list li{margin:0 0 8px;color:#111827}
		.clms-instructor-course-list a{color:#111827;text-decoration:none}
		.clms-instructor-course-list a:hover{text-decoration:underline}
		.clms-instructor-sales{margin-top:18px;padding-top:18px;border-top:1px solid #e5e7eb}
		.clms-instructor-sales h4{margin:0 0 12px;font-size:16px;line-height:1.3;color:#111827}
		.clms-instructor-sales-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
		.clms-instructor-sales-card{background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;padding:14px}
		.clms-instructor-sales-card-title{margin:0 0 8px;font-size:14px;font-weight:700;color:#111827;text-transform:uppercase;letter-spacing:.04em}
		.clms-instructor-sales-card ul{margin:0;padding-left:18px}
		.clms-instructor-sales-card li{margin:0 0 8px;color:#111827}
		.clms-instructor-sales-card a{color:#111827;text-decoration:none}
		.clms-instructor-sales-card a:hover{text-decoration:underline}
		@media (max-width:640px){
			.clms-instructor-head{grid-template-columns:1fr}
			.clms-instructor-avatar img{width:72px;height:72px}
			.clms-instructor-name{font-size:20px}
		}';
	}

	public function get_inline_style_tag() {
		return '<style>' . $this->get_inline_css() . '</style>';
	}

	public function register_rewrite() {
		add_rewrite_tag( '%clms_instructor%', '([^&]+)' );
		add_rewrite_rule( '^docentes/([^/]+)/?$', 'index.php?clms_instructor=$matches[1]', 'top' );
	}

	public function register_query_vars( $vars ) {
		$vars[] = 'clms_instructor';

		return $vars;
	}

	public function maybe_load_profile_template( $template ) {
		if ( is_singular( 'atora_teacher' ) ) {
			$custom = '';
			if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'module' ) ) {
				$loader = clms_core('CLMS_Loader');
				if ( $loader && method_exists( $loader, 'resolve_file_path' ) ) {
					$custom = $loader->resolve_file_path( 'templates/instructor-profile.php' );
				}
			}

			if ( ! $custom ) {
				$custom = trailingslashit( dirname( dirname( __FILE__ ) ) ) . 'templates/instructor-profile.php';
			}

			return file_exists( $custom ) ? $custom : $template;
		}

		$slug = get_query_var( 'clms_instructor', '' );

		if ( ! is_string( $slug ) || '' === trim( $slug ) ) {
			return $template;
		}

		$custom = '';
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'module' ) ) {
			$loader = clms_core('CLMS_Loader');
			if ( $loader && method_exists( $loader, 'resolve_file_path' ) ) {
				$custom = $loader->resolve_file_path( 'templates/instructor-profile.php' );
			}
		}

		if ( ! $custom ) {
			$custom = trailingslashit( dirname( dirname( __FILE__ ) ) ) . 'templates/instructor-profile.php';
		}

		return file_exists( $custom ) ? $custom : $template;
	}

	public function get_profile_url( $user_id ) {
		$user_id = absint( $user_id );
		$user    = $user_id ? get_userdata( $user_id ) : false;

		if ( ! $user ) {
			return '';
		}

		if ( ! get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'clms_instructor', $user->user_nicename, home_url( '/' ) );
		}

		return home_url( user_trailingslashit( 'docentes/' . $user->user_nicename ) );
	}

	public function get_profile_user_from_request() {
		$slug = get_query_var( 'clms_instructor', '' );
		$slug = is_string( $slug ) ? sanitize_title( $slug ) : '';

		if ( '' === $slug ) {
			return false;
		}

		return get_user_by( 'slug', $slug );
	}

	public function render_course_instructor_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'course_id'     => 0,
				'show_courses'  => 'yes',
				'courses_limit' => 4,
				'show_bio'      => 'yes',
				'show_socials'  => 'yes',
				'show_meta'     => 'yes',
			),
			(array) $atts,
			'clms_course_instructor'
		);

		$course_id = absint( $atts['course_id'] );

		if ( ! $course_id && is_singular( 'lm_course' ) ) {
			$course_id = get_the_ID();
		}

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return '';
		}

		return $this->get_instructor_box_html(
			$course_id,
			array(
				'show_courses'  => ( 'no' !== strtolower( (string) $atts['show_courses'] ) ),
				'courses_limit' => max( 1, absint( $atts['courses_limit'] ) ),
				'show_bio'      => ( 'no' !== strtolower( (string) $atts['show_bio'] ) ),
				'show_socials'  => ( 'no' !== strtolower( (string) $atts['show_socials'] ) ),
				'show_meta'     => ( 'no' !== strtolower( (string) $atts['show_meta'] ) ),
			)
		);
	}

	public function render_instructor_profile_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'user_id'       => 0,
				'show_courses'  => 'yes',
				'courses_limit' => 6,
				'show_bio'      => 'yes',
				'show_socials'  => 'yes',
				'show_meta'     => 'yes',
			),
			(array) $atts,
			'clms_instructor_profile'
		);

		$user_id = absint( $atts['user_id'] );

		if ( ! $user_id && is_user_logged_in() && ( current_user_can( 'clms_view_teacher_dashboard' ) || current_user_can( 'manage_options' ) ) ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return '';
		}

		return $this->get_instructor_profile_html(
			$user_id,
			array(
				'show_courses'  => ( 'no' !== strtolower( (string) $atts['show_courses'] ) ),
				'courses_limit' => max( 1, absint( $atts['courses_limit'] ) ),
				'show_bio'      => ( 'no' !== strtolower( (string) $atts['show_bio'] ) ),
				'show_socials'  => ( 'no' !== strtolower( (string) $atts['show_socials'] ) ),
				'show_meta'     => ( 'no' !== strtolower( (string) $atts['show_meta'] ) ),
			)
		);
	}

	public function get_instructor_box_html( $course_id, $args = array() ) {
		$course_id = absint( $course_id );

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return '';
		}

		$args = wp_parse_args(
			$args,
			array(
				'show_courses'  => true,
				'courses_limit' => 4,
				'show_bio'      => true,
				'show_socials'  => true,
				'show_meta'     => true,
			)
		);

		$data = $this->get_instructor_data( $course_id, $args['courses_limit'] );

		return $this->render_instructor_html_from_data( $data, $args );
	}

	public function get_instructor_profile_html( $user_id, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'show_courses'  => true,
				'courses_limit' => 6,
				'show_bio'      => true,
				'show_socials'  => true,
				'show_meta'     => true,
			)
		);

		$data = $this->get_instructor_data_by_user( $user_id, $args['courses_limit'] );

		return $this->render_instructor_html_from_data( $data, $args );
	}

	protected function render_instructor_html_from_data( $data, $args ) {

		if ( empty( $data ) || empty( $data['user_id'] ) ) {
			return '';
		}

		$this->enqueue_assets();

		ob_start();
		?>
		<div class="clms-ui">
			<div class="clms-instructor-box">
				<div class="clms-instructor-head">
					<div class="clms-instructor-avatar">
						<?php echo get_avatar( $data['user_id'], 176 ); ?>
					</div>

					<div class="clms-instructor-main">
						<h3 class="clms-instructor-name"><?php echo esc_html( $data['display_name'] ); ?></h3>

						<?php if ( $data['title'] ) : ?>
							<p class="clms-instructor-role"><?php echo esc_html( $data['title'] ); ?></p>
						<?php endif; ?>

						<?php if ( $data['tagline'] ) : ?>
							<p class="clms-instructor-tagline"><?php echo esc_html( $data['tagline'] ); ?></p>
						<?php endif; ?>

						<?php if ( $data['credentials'] ) : ?>
							<p class="clms-instructor-credentials"><?php echo esc_html( $data['credentials'] ); ?></p>
						<?php endif; ?>

						<?php if ( $args['show_meta'] ) : ?>
							<div class="clms-instructor-meta">
								<span>
									<strong><?php esc_html_e( 'Cursos:', 'atora-lms' ); ?></strong>
									<?php echo esc_html( $data['published_courses_count'] ); ?>
								</span>

								<?php if ( $data['archive_url'] ) : ?>
									<a href="<?php echo esc_url( $data['archive_url'] ); ?>">
										<?php esc_html_e( 'Ver perfil', 'atora-lms' ); ?>
									</a>
								<?php endif; ?>

								<?php if ( $args['show_socials'] && $data['website'] ) : ?>
									<a href="<?php echo esc_url( $data['website'] ); ?>" target="_blank" rel="noopener noreferrer">
										<?php esc_html_e( 'Sitio web', 'atora-lms' ); ?>
									</a>
								<?php endif; ?>

								<?php if ( $args['show_socials'] && $data['linkedin'] ) : ?>
									<a href="<?php echo esc_url( $data['linkedin'] ); ?>" target="_blank" rel="noopener noreferrer">
										LinkedIn
									</a>
								<?php endif; ?>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( $args['show_bio'] && $data['bio'] ) : ?>
					<div class="clms-instructor-bio">
						<?php echo wp_kses_post( wpautop( $data['bio'] ) ); ?>
					</div>
				<?php endif; ?>

				<?php if ( $args['show_courses'] && ! empty( $data['other_courses'] ) ) : ?>
					<div class="clms-instructor-courses">
			<h4><?php esc_html_e( 'Otros cursos del docente', 'atora-lms' ); ?></h4>
						<ul class="clms-instructor-course-list">
							<?php foreach ( $data['other_courses'] as $course ) : ?>
								<li>
									<a href="<?php echo esc_url( get_permalink( $course->ID ) ); ?>">
										<?php echo esc_html( get_the_title( $course->ID ) ); ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php echo $this->get_sales_profile_html( $data['user_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	public function get_teachers_html_for_course( $course_id, $args = array() ) {
		$course_id = absint( $course_id );

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return '';
		}

		$teacher_ids = $this->get_teacher_ids_for_entity( $course_id );
		if ( empty( $teacher_ids ) ) {
			return $this->get_instructor_box_html( $course_id, $args );
		}

		return $this->render_teacher_cards_html( $teacher_ids, $args );
	}

	public function get_teachers_html_for_program( $program_id, $args = array() ) {
		$program_id = absint( $program_id );

		if ( ! $program_id || 'lm_program' !== get_post_type( $program_id ) ) {
			return '';
		}

		$teacher_ids = $this->get_teacher_ids_for_entity( $program_id );
		if ( empty( $teacher_ids ) ) {
			return '';
		}

		return $this->render_teacher_cards_html( $teacher_ids, $args );
	}

	protected function get_teacher_ids_for_entity( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return array();
		}

		$post_type = get_post_type( $post_id );
		$meta_key  = 'lm_course' === $post_type ? '_clms_course_teacher_ids' : ( 'lm_program' === $post_type ? '_clms_program_teacher_ids' : '' );

		if ( ! $meta_key ) {
			return array();
		}

		$ids = get_post_meta( $post_id, $meta_key, true );
		$ids = is_string( $ids ) ? preg_split( '/\s*,\s*/', trim( $ids ) ) : $ids;
		$ids = is_array( $ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) : array();

		$filtered = array();
		foreach ( $ids as $teacher_id ) {
			if ( $teacher_id && 'atora_teacher' === get_post_type( $teacher_id ) ) {
				$filtered[] = $teacher_id;
			}
		}

		return $filtered;
	}

	protected function render_teacher_cards_html( $teacher_ids, $args = array() ) {
		$teacher_ids = is_array( $teacher_ids ) ? array_values( array_unique( array_map( 'absint', $teacher_ids ) ) ) : array();

		if ( empty( $teacher_ids ) ) {
			return '';
		}

		$args = wp_parse_args(
			$args,
			array(
				'show_bio'          => true,
				'show_specialty'    => true,
				'show_achievements' => true,
				'show_socials'      => true,
				'show_profile_link' => true,
			)
		);

		$cards = array();
		foreach ( $teacher_ids as $teacher_id ) {
			$data = $this->get_teacher_card_data( $teacher_id );
			if ( empty( $data ) || ! $data['is_public'] ) {
				continue;
			}
			$cards[] = $data;
		}

		if ( empty( $cards ) ) {
			return '';
		}

		$this->enqueue_assets();

		ob_start();
		?>
		<div class="atora-teacher-grid">
			<?php foreach ( $cards as $teacher ) : ?>
				<article class="atora-teacher-card">

					<!-- Banda de color superior -->
					<div class="atora-teacher-card__band" aria-hidden="true"></div>

					<!-- Avatar centrado que solapa la banda -->
					<div class="atora-teacher-avatar">
						<?php if ( $teacher['photo_id'] ) : ?>
							<?php echo wp_get_attachment_image( $teacher['photo_id'], 'medium', false, array( 'alt' => esc_attr( $teacher['name'] ), 'loading' => 'lazy' ) ); ?>
						<?php else : ?>
							<span class="atora-teacher-avatar-fallback" aria-hidden="true"><?php echo esc_html( mb_substr( $teacher['name'], 0, 1 ) ); ?></span>
						<?php endif; ?>
					</div>

					<!-- Info: nombre + especialidad + bio + redes -->
					<div class="atora-teacher-card__body">
						<h3 class="atora-teacher-name"><?php echo esc_html( $teacher['name'] ); ?></h3>

						<?php if ( $args['show_specialty'] && $teacher['specialty'] ) : ?>
							<p class="atora-teacher-specialty"><?php echo esc_html( $teacher['specialty'] ); ?></p>
						<?php endif; ?>

						<?php if ( $args['show_bio'] && $teacher['short_bio'] ) : ?>
							<p class="atora-teacher-bio"><?php echo esc_html( $teacher['short_bio'] ); ?></p>
						<?php endif; ?>

						<?php if ( $args['show_socials'] && ! empty( $teacher['socials'] ) ) : ?>
							<div class="atora-teacher-socials">
								<?php foreach ( $teacher['socials'] as $social ) : ?>
									<a href="<?php echo esc_url( $social['url'] ); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( $social['label'] ); ?>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>

					<!-- Botón al fondo, siempre en la misma posición -->
					<?php if ( $args['show_profile_link'] && $teacher['url'] ) : ?>
						<div class="atora-teacher-card__footer">
							<a class="atora-teacher-link" href="<?php echo esc_url( $teacher['url'] ); ?>">
								<?php esc_html_e( 'Ver perfil', 'atora-lms' ); ?>
								<svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
							</a>
						</div>
					<?php endif; ?>

				</article>
			<?php endforeach; ?>
		</div>
		<?php

		return ob_get_clean();
	}

	protected function get_teacher_card_data( $teacher_id ) {
		$teacher_id = absint( $teacher_id );
		$post       = $teacher_id ? get_post( $teacher_id ) : null;

		if ( ! $post || 'atora_teacher' !== $post->post_type ) {
			return array();
		}

		$name        = $post->post_title ? $post->post_title : __( 'Docente', 'atora-lms' );
		$short_bio   = (string) get_post_field( 'post_excerpt', $teacher_id );
		$specialty   = (string) get_post_meta( $teacher_id, '_clms_teacher_specialty', true );
		$achievements_raw = (string) get_post_meta( $teacher_id, '_clms_teacher_achievements', true );
		$socials_raw = (string) get_post_meta( $teacher_id, '_clms_teacher_socials', true );
		$is_public   = $this->is_teacher_public( $teacher_id );
		$photo_id    = get_post_thumbnail_id( $teacher_id );

		return array(
			'id'           => $teacher_id,
			'name'         => sanitize_text_field( $name ),
			'short_bio'    => sanitize_text_field( $short_bio ),
			'specialty'    => sanitize_text_field( $specialty ),
			'achievements' => $this->parse_teacher_lines( $achievements_raw ),
			'socials'      => $this->parse_teacher_socials( $socials_raw ),
			'is_public'    => $is_public,
			'photo_id'     => absint( $photo_id ),
			'url'          => $this->get_teacher_profile_url( $teacher_id ),
		);
	}

	protected function parse_teacher_lines( $raw ) {
		$raw   = is_string( $raw ) ? trim( $raw ) : '';
		$items = array();

		if ( '' === $raw ) {
			return $items;
		}

		foreach ( preg_split( "/\r\n|\n|\r/", $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$items[] = sanitize_text_field( $line );
			}
		}

		return $items;
	}

	protected function parse_teacher_socials( $raw ) {
		$raw   = is_string( $raw ) ? trim( $raw ) : '';
		$items = array();

		if ( '' === $raw ) {
			return $items;
		}

		foreach ( preg_split( "/\r\n|\n|\r/", $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$label = sanitize_text_field( $parts[0] );
			$url   = ! empty( $parts[1] ) ? esc_url_raw( $parts[1] ) : '';
			if ( $label && $url ) {
				$items[] = array(
					'label' => $label,
					'url'   => $url,
				);
			}
		}

		return $items;
	}

	protected function is_teacher_public( $teacher_id ) {
		$teacher_id = absint( $teacher_id );
		$flag       = get_post_meta( $teacher_id, '_clms_teacher_public', true );

		if ( '' !== (string) $flag ) {
			return '1' === (string) $flag;
		}

		$post = get_post( $teacher_id );
		return $post && 'publish' === $post->post_status;
	}

	protected function get_teacher_profile_url( $teacher_id ) {
		$teacher_id = absint( $teacher_id );
		return $teacher_id ? get_permalink( $teacher_id ) : '';
	}

	public function get_instructor_data( $course_id, $courses_limit = 4 ) {
		$course_id = absint( $course_id );

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return array();
		}

		$user_id = (int) get_post_field( 'post_author', $course_id );

		if ( ! $user_id ) {
			return array();
		}

		return $this->get_instructor_data_by_user( $user_id, $courses_limit, $course_id );
	}

	protected function get_instructor_data_by_user( $user_id, $courses_limit = 4, $exclude_course_id = 0 ) {
		$user_id           = absint( $user_id );
		$exclude_course_id = absint( $exclude_course_id );

		if ( ! $user_id ) {
			return array();
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return array();
		}

		$display_name = $user->display_name ? $user->display_name : $user->user_login;
		$bio          = get_user_meta( $user_id, 'description', true );
		$title        = get_user_meta( $user_id, '_clms_instructor_title', true );
		$tagline      = get_user_meta( $user_id, '_clms_instructor_tagline', true );
		$credentials  = get_user_meta( $user_id, '_clms_instructor_credentials', true );
		$website      = get_user_meta( $user_id, '_clms_instructor_website', true );
		$linkedin     = get_user_meta( $user_id, '_clms_instructor_linkedin', true );

		if ( empty( $website ) ) {
			$website = get_the_author_meta( 'user_url', $user_id );
		}

		return array(
			'user_id'                 => $user_id,
			'display_name'            => sanitize_text_field( $display_name ),
			'bio'                     => is_string( $bio ) ? $bio : '',
			'title'                   => sanitize_text_field( (string) $title ),
			'tagline'                 => sanitize_text_field( (string) $tagline ),
			'credentials'             => sanitize_text_field( (string) $credentials ),
			'website'                 => esc_url_raw( (string) $website ),
			'linkedin'                => esc_url_raw( (string) $linkedin ),
			'archive_url'             => $this->get_profile_url( $user_id ),
			'published_courses_count' => $this->get_instructor_courses_count( $user_id ),
			'other_courses'           => $this->get_other_courses_by_instructor( $user_id, $exclude_course_id, $courses_limit ),
		);
	}

	protected function get_instructor_courses_count( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'lm_course',
				'post_status'            => 'publish',
				'author'                 => $user_id,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return absint( $query->found_posts );
	}

	protected function get_other_courses_by_instructor( $user_id, $exclude_course_id = 0, $limit = 4 ) {
		$user_id           = absint( $user_id );
		$exclude_course_id = absint( $exclude_course_id );
		$limit             = max( 1, absint( $limit ) );

		if ( ! $user_id ) {
			return array();
		}

		return get_posts(
			array(
				'post_type'              => 'lm_course',
				'post_status'            => 'publish',
				'author'                 => $user_id,
				'post__not_in'           => $exclude_course_id ? array( $exclude_course_id ) : array(),
				'posts_per_page'         => $limit,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
	}

	public function get_sales_profile_html( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return '';
		}

		$featured_products = $this->get_featured_products( $user_id );
		$mentoring_offers  = $this->parse_offer_lines( get_user_meta( $user_id, '_clms_instructor_mentoring_offers', true ) );
		$podcasts          = $this->parse_offer_lines( get_user_meta( $user_id, '_clms_instructor_podcasts', true ) );
		$books             = $this->parse_offer_lines( get_user_meta( $user_id, '_clms_instructor_books', true ) );

		if ( empty( $featured_products ) && empty( $mentoring_offers ) && empty( $podcasts ) && empty( $books ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="clms-instructor-sales">
			<h4><?php esc_html_e( 'Otros recursos del docente', 'atora-lms' ); ?></h4>
			<div class="clms-instructor-sales-grid">
				<?php if ( ! empty( $featured_products ) ) : ?>
					<div class="clms-instructor-sales-card">
						<p class="clms-instructor-sales-card-title"><?php esc_html_e( 'Productos recomendados', 'atora-lms' ); ?></p>
						<ul>
							<?php foreach ( $featured_products as $item ) : ?>
								<li>
									<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
									<?php if ( $item['subtitle'] ) : ?>
										<br><small><?php echo esc_html( $item['subtitle'] ); ?></small>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $mentoring_offers ) ) : ?>
					<div class="clms-instructor-sales-card">
						<p class="clms-instructor-sales-card-title"><?php esc_html_e( 'Mentorías', 'atora-lms' ); ?></p>
						<ul>
							<?php foreach ( $mentoring_offers as $item ) : ?>
								<li>
									<?php if ( $item['url'] ) : ?>
										<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $item['label'] ); ?>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $podcasts ) ) : ?>
					<div class="clms-instructor-sales-card">
						<p class="clms-instructor-sales-card-title"><?php esc_html_e( 'Podcasts', 'atora-lms' ); ?></p>
						<ul>
							<?php foreach ( $podcasts as $item ) : ?>
								<li>
									<?php if ( $item['url'] ) : ?>
										<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $item['label'] ); ?>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $books ) ) : ?>
					<div class="clms-instructor-sales-card">
						<p class="clms-instructor-sales-card-title"><?php esc_html_e( 'Libros', 'atora-lms' ); ?></p>
						<ul>
							<?php foreach ( $books as $item ) : ?>
								<li>
									<?php if ( $item['url'] ) : ?>
										<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $item['label'] ); ?>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	protected function get_featured_products( $user_id ) {
		$product_ids = get_user_meta( $user_id, '_clms_instructor_featured_products', true );
		$product_ids = is_string( $product_ids ) ? preg_split( '/\s*,\s*/', trim( $product_ids ) ) : $product_ids;
		$product_ids = is_array( $product_ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) ) : array();
		$items       = array();

		foreach ( $product_ids as $product_id ) {
			$product = get_post( $product_id );

			if ( ! $product || 'product' !== $product->post_type || 'publish' !== $product->post_status ) {
				continue;
			}

			$items[] = array(
				'id'       => $product_id,
				'title'    => get_the_title( $product_id ),
				'subtitle' => wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $product_id ) ),
				'url'      => get_permalink( $product_id ),
			);
		}

		return $items;
	}

	/**
	 * Shortcode [clms_teachers] — grid público de docentes.
	 *
	 * Parámetros:
	 *   limit           (int)  — máximo de docentes a mostrar (default 12)
	 *   columns         (int)  — columnas del grid: 2, 3 o 4 (default 3)
	 *   show_bio        (yes|no)
	 *   show_achievements (yes|no)
	 *   show_socials    (yes|no)
	 *   show_profile_link (yes|no)
	 *   ids             — lista de IDs de atora_teacher separados por coma (opcional)
	 */
	public function render_teachers_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'               => 12,
				'columns'             => 3,
				'show_bio'            => 'yes',
				'show_achievements'   => 'yes',
				'show_socials'        => 'yes',
				'show_profile_link'   => 'yes',
				'ids'                 => '',
			),
			(array) $atts,
			'clms_teachers'
		);

		$limit   = max( 1, absint( $atts['limit'] ) );
		$columns = max( 1, min( 4, absint( $atts['columns'] ) ) );
		$args    = array(
			'show_bio'          => 'no' !== strtolower( (string) $atts['show_bio'] ),
			'show_achievements' => 'no' !== strtolower( (string) $atts['show_achievements'] ),
			'show_socials'      => 'no' !== strtolower( (string) $atts['show_socials'] ),
			'show_profile_link' => 'no' !== strtolower( (string) $atts['show_profile_link'] ),
		);

		// IDs específicos
		if ( '' !== trim( (string) $atts['ids'] ) ) {
			$teacher_ids = array_values( array_unique( array_filter( array_map(
				'absint',
				explode( ',', (string) $atts['ids'] )
			) ) ) );
			$teacher_ids = array_slice( $teacher_ids, 0, $limit );
		} else {
			// Todos los docentes públicos publicados
			$posts = get_posts( array(
				'post_type'              => 'atora_teacher',
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'orderby'                => 'menu_order title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'fields'                 => 'ids',
			) );
			$teacher_ids = array_values( array_filter( array_map( 'absint', $posts ) ) );
		}

		if ( empty( $teacher_ids ) ) {
			return '';
		}

		// Filtrar por visibilidad pública
		$public_ids = array();
		foreach ( $teacher_ids as $tid ) {
			if ( $this->is_teacher_public( $tid ) ) {
				$public_ids[] = $tid;
			}
		}

		if ( empty( $public_ids ) ) {
			return '';
		}

		$html = $this->render_teacher_cards_html( $public_ids, $args );

		if ( '' === $html ) {
			return '';
		}

		// Envuelve con clase de columnas para CSS
		return '<div class="clms-teachers-wrap clms-teachers-cols-' . $columns . '">' . $html . '</div>';
	}

	protected function parse_offer_lines( $raw ) {
		$raw   = is_string( $raw ) ? trim( $raw ) : '';
		$items = array();

		if ( '' === $raw ) {
			return $items;
		}

		foreach ( preg_split( "/\r\n|\n|\r/", $raw ) as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$items[] = array(
				'label' => sanitize_text_field( $parts[0] ),
				'url'   => ! empty( $parts[1] ) ? esc_url_raw( $parts[1] ) : '',
			);
		}

		return $items;
	}

	public function render_user_fields( $user ) {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Perfil de docente LMS', 'atora-lms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="clms_instructor_title"><?php esc_html_e( 'Título profesional', 'atora-lms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="clms_instructor_title" name="clms_instructor_title" value="<?php echo esc_attr( get_user_meta( $user->ID, '_clms_instructor_title', true ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Ejemplo: Docente de Finanzas, Mentor de Marketing, Arquitecto de Software.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="clms_instructor_tagline"><?php esc_html_e( 'Subtítulo', 'atora-lms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="clms_instructor_tagline" name="clms_instructor_tagline" value="<?php echo esc_attr( get_user_meta( $user->ID, '_clms_instructor_tagline', true ) ); ?>" />
				</td>
			</tr>
			<tr>
				<th><label for="clms_instructor_credentials"><?php esc_html_e( 'Credenciales', 'atora-lms' ); ?></label></th>
				<td>
					<textarea class="large-text" rows="4" id="clms_instructor_credentials" name="clms_instructor_credentials"><?php echo esc_textarea( get_user_meta( $user->ID, '_clms_instructor_credentials', true ) ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th><label for="clms_instructor_website"><?php esc_html_e( 'Sitio web', 'atora-lms' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="clms_instructor_website" name="clms_instructor_website" value="<?php echo esc_attr( get_user_meta( $user->ID, '_clms_instructor_website', true ) ); ?>" />
				</td>
			</tr>
			<tr>
				<th><label for="clms_instructor_linkedin"><?php esc_html_e( 'LinkedIn', 'atora-lms' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="clms_instructor_linkedin" name="clms_instructor_linkedin" value="<?php echo esc_attr( get_user_meta( $user->ID, '_clms_instructor_linkedin', true ) ); ?>" />
				</td>
			</tr>
			<tr>
				<th><label for="clms_instructor_featured_products"><?php esc_html_e( 'Productos destacados', 'atora-lms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="clms_instructor_featured_products" name="clms_instructor_featured_products" value="<?php echo esc_attr( get_user_meta( $user->ID, '_clms_instructor_featured_products', true ) ); ?>" />
					<p class="description"><?php esc_html_e( 'IDs de productos WooCommerce separados por coma.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="clms_instructor_mentoring_offers"><?php esc_html_e( 'Mentorías', 'atora-lms' ); ?></label></th>
				<td>
					<textarea class="large-text" rows="4" id="clms_instructor_mentoring_offers" name="clms_instructor_mentoring_offers"><?php echo esc_textarea( get_user_meta( $user->ID, '_clms_instructor_mentoring_offers', true ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Una oferta por línea. Formato opcional: Título | URL', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="clms_instructor_podcasts"><?php esc_html_e( 'Podcasts', 'atora-lms' ); ?></label></th>
				<td>
					<textarea class="large-text" rows="4" id="clms_instructor_podcasts" name="clms_instructor_podcasts"><?php echo esc_textarea( get_user_meta( $user->ID, '_clms_instructor_podcasts', true ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Una referencia por línea. Formato opcional: Título | URL', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="clms_instructor_books"><?php esc_html_e( 'Libros', 'atora-lms' ); ?></label></th>
				<td>
					<textarea class="large-text" rows="4" id="clms_instructor_books" name="clms_instructor_books"><?php echo esc_textarea( get_user_meta( $user->ID, '_clms_instructor_books', true ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Una referencia por línea. Formato opcional: Título | URL', 'atora-lms' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_user_fields( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$title       = isset( $_POST['clms_instructor_title'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_instructor_title'] ) ) : '';
		$tagline     = isset( $_POST['clms_instructor_tagline'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_instructor_tagline'] ) ) : '';
		$credentials = isset( $_POST['clms_instructor_credentials'] ) ? sanitize_textarea_field( wp_unslash( $_POST['clms_instructor_credentials'] ) ) : '';
		$website     = isset( $_POST['clms_instructor_website'] ) ? esc_url_raw( wp_unslash( $_POST['clms_instructor_website'] ) ) : '';
		$linkedin    = isset( $_POST['clms_instructor_linkedin'] ) ? esc_url_raw( wp_unslash( $_POST['clms_instructor_linkedin'] ) ) : '';
		$featured_products = isset( $_POST['clms_instructor_featured_products'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_instructor_featured_products'] ) ) : '';
		$mentoring_offers  = isset( $_POST['clms_instructor_mentoring_offers'] ) ? sanitize_textarea_field( wp_unslash( $_POST['clms_instructor_mentoring_offers'] ) ) : '';
		$podcasts          = isset( $_POST['clms_instructor_podcasts'] ) ? sanitize_textarea_field( wp_unslash( $_POST['clms_instructor_podcasts'] ) ) : '';
		$books             = isset( $_POST['clms_instructor_books'] ) ? sanitize_textarea_field( wp_unslash( $_POST['clms_instructor_books'] ) ) : '';

		update_user_meta( $user_id, '_clms_instructor_title', $title );
		update_user_meta( $user_id, '_clms_instructor_tagline', $tagline );
		update_user_meta( $user_id, '_clms_instructor_credentials', $credentials );
		update_user_meta( $user_id, '_clms_instructor_website', $website );
		update_user_meta( $user_id, '_clms_instructor_linkedin', $linkedin );
		update_user_meta( $user_id, '_clms_instructor_featured_products', $featured_products );
		update_user_meta( $user_id, '_clms_instructor_mentoring_offers', $mentoring_offers );
		update_user_meta( $user_id, '_clms_instructor_podcasts', $podcasts );
		update_user_meta( $user_id, '_clms_instructor_books', $books );
	}
}
