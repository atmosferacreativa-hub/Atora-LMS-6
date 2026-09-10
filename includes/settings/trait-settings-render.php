<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Settings_Render_Trait {
	public function render_page() {
		if ( self::$page_rendered ) {
			return;
		}
		self::$page_rendered = true;

		if ( ! CLMS_Access::can_access_admin() ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'academia';
		$saved   = ! empty( $_GET['saved'] );
		$academy = (array) get_option( self::OPTION_ACADEMY, array() );
		$nav     = self::get_navigation_settings();
		$channels = self::get_channel_settings();
		$ai      = self::get_ai_settings();
		$adv     = self::get_advanced_settings();

		$tabs = array(
			'academia'   => __( 'Academia', 'atora-lms' ),
			'channels'   => __( 'Canales', 'atora-lms' ),
			'navigation' => __( 'Navegación', 'atora-lms' ),
			'apis'       => __( 'APIs e IA', 'atora-lms' ),
			'advanced'   => __( 'Avanzado', 'atora-lms' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'academia';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Configuración de Atora', 'atora-lms' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ajustes guardados correctamente.', 'atora-lms' ); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper" style="margin-bottom:20px">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $key ) ); ?>"
						class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="clms_save_settings">
				<input type="hidden" name="clms_settings_tab" value="<?php echo esc_attr( $tab ); ?>">

				<?php if ( 'academia' === $tab ) : ?>
					<?php $this->render_tab_academia( $academy ); ?>
				<?php elseif ( 'channels' === $tab ) : ?>
					<?php $this->render_tab_channels( $channels ); ?>
				<?php elseif ( 'navigation' === $tab ) : ?>
					<?php $this->render_tab_navigation( $nav ); ?>
				<?php elseif ( 'apis' === $tab ) : ?>
					<?php $this->render_tab_apis( $ai ); ?>
				<?php elseif ( 'advanced' === $tab ) : ?>
					<?php $this->render_tab_advanced( $adv ); ?>
				<?php endif; ?>

				<?php submit_button( __( 'Guardar ajustes', 'atora-lms' ) ); ?>
			</form>
		</div>
		<?php
	}

	// ── Tab: Academia ────────────────────────────────────────────────────────────

	protected function render_tab_academia( $a ) {
		$name    = isset( $a['academy_name'] )    ? $a['academy_name']    : '';
		$tagline = isset( $a['academy_tagline'] ) ? $a['academy_tagline'] : '';
		$color   = isset( $a['primary_color'] )   ? $a['primary_color']   : '#6366f1';
		$logo_id = isset( $a['logo_id'] )         ? absint( $a['logo_id'] ) : 0;
		$email   = isset( $a['contact_email'] )   ? $a['contact_email']   : '';
		$teacher_email = isset( $a['teacher_contact_email'] ) ? $a['teacher_contact_email'] : '';
		$admin_email = isset( $a['admin_contact_email'] ) ? $a['admin_contact_email'] : sanitize_email( (string) get_option( 'admin_email' ) );
		$student_profile_page_id = isset( $a['student_profile_page_id'] ) ? absint( $a['student_profile_page_id'] ) : 0;
		$ui_theme = isset( $a['ui_theme'] ) ? $a['ui_theme'] : 'light';
		$ui_color_bg           = isset( $a['ui_color_bg'] ) ? $a['ui_color_bg'] : '#ffffff';
		$ui_color_surface      = isset( $a['ui_color_surface'] ) ? $a['ui_color_surface'] : '#f8fafc';
		$ui_color_text         = isset( $a['ui_color_text'] ) ? $a['ui_color_text'] : '#0f172a';
		$ui_color_muted        = isset( $a['ui_color_muted'] ) ? $a['ui_color_muted'] : '#475569';
		$ui_color_border       = isset( $a['ui_color_border'] ) ? $a['ui_color_border'] : '#e2e8f0';
		$ui_color_accent       = isset( $a['ui_color_accent'] ) ? $a['ui_color_accent'] : '#6366f1';
		$ui_color_accent_hover = isset( $a['ui_color_accent_hover'] ) ? $a['ui_color_accent_hover'] : '#4f46e5';
		$ui_color_accent_soft  = isset( $a['ui_color_accent_soft'] ) ? $a['ui_color_accent_soft'] : '#eef2ff';
		?>
		<h2><?php esc_html_e( 'Identidad de la academia', 'atora-lms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="academy_name"><?php esc_html_e( 'Nombre de la academia', 'atora-lms' ); ?></label></th>
				<td><input type="text" class="regular-text" id="academy_name" name="academy_name" value="<?php echo esc_attr( $name ); ?>"></td>
			</tr>
			<tr>
				<th><label for="academy_tagline"><?php esc_html_e( 'Eslogan', 'atora-lms' ); ?></label></th>
				<td><input type="text" class="regular-text" id="academy_tagline" name="academy_tagline" value="<?php echo esc_attr( $tagline ); ?>"></td>
			</tr>
			<tr>
				<th><label for="contact_email"><?php esc_html_e( 'Email de contacto', 'atora-lms' ); ?></label></th>
				<td><input type="email" class="regular-text" id="contact_email" name="contact_email" value="<?php echo esc_attr( $email ); ?>"></td>
			</tr>
			<tr>
				<th><label for="teacher_contact_email"><?php esc_html_e( 'Email docente (remitente)', 'atora-lms' ); ?></label></th>
				<td>
					<input type="email" class="regular-text" id="teacher_contact_email" name="teacher_contact_email" value="<?php echo esc_attr( $teacher_email ); ?>">
					<p class="description"><?php esc_html_e( 'Se usa como contacto académico para correos dirigidos a estudiantes desde el frente docente.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="admin_contact_email"><?php esc_html_e( 'Email administrador (remitente)', 'atora-lms' ); ?></label></th>
				<td>
					<input type="email" class="regular-text" id="admin_contact_email" name="admin_contact_email" value="<?php echo esc_attr( $admin_email ); ?>">
					<p class="description"><?php esc_html_e( 'Canal institucional para avisos operativos y notificaciones administrativas.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="student_profile_page_id"><?php esc_html_e( 'Página de perfil del alumno', 'atora-lms' ); ?></label></th>
				<td>
					<?php
					echo wp_dropdown_pages(
						array(
							'name'              => 'student_profile_page_id',
							'id'                => 'student_profile_page_id',
							'selected'          => $student_profile_page_id,
							'show_option_none'  => __( 'Selecciona una página', 'atora-lms' ),
							'option_none_value' => '0',
							'echo'              => 0,
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'Debe contener el shortcode [clms_student_profile].', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="primary_color"><?php esc_html_e( 'Color principal', 'atora-lms' ); ?></label></th>
				<td>
					<input type="color" id="primary_color" name="primary_color" value="<?php echo esc_attr( $color ); ?>">
					<p class="description"><?php esc_html_e( 'Color de botones, badges y énfasis en la plataforma.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="ui_theme"><?php esc_html_e( 'Tema del LMS', 'atora-lms' ); ?></label></th>
				<td>
					<select id="ui_theme" name="ui_theme">
						<option value="light" <?php selected( $ui_theme, 'light' ); ?>><?php esc_html_e( 'Claro', 'atora-lms' ); ?></option>
						<option value="dark" <?php selected( $ui_theme, 'dark' ); ?>><?php esc_html_e( 'Oscuro', 'atora-lms' ); ?></option>
						<option value="vibrant" <?php selected( $ui_theme, 'vibrant' ); ?>><?php esc_html_e( 'Vibrante', 'atora-lms' ); ?></option>
						<option value="pastel" <?php selected( $ui_theme, 'pastel' ); ?>><?php esc_html_e( 'Pastel', 'atora-lms' ); ?></option>
						<option value="elegant" <?php selected( $ui_theme, 'elegant' ); ?>><?php esc_html_e( 'Elegante', 'atora-lms' ); ?></option>
						<option value="custom" <?php selected( $ui_theme, 'custom' ); ?>><?php esc_html_e( 'Personalizado', 'atora-lms' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Define el aspecto global del LMS en frontend.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr class="clms-theme-custom-row">
				<th><?php esc_html_e( 'Paleta personalizada', 'atora-lms' ); ?></th>
				<td>
					<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;max-width:720px">
						<label><?php esc_html_e( 'Fondo', 'atora-lms' ); ?><br>
							<input type="color" name="ui_color_bg" value="<?php echo esc_attr( $ui_color_bg ); ?>">
						</label>
						<label><?php esc_html_e( 'Superficie', 'atora-lms' ); ?><br>
							<input type="color" name="ui_color_surface" value="<?php echo esc_attr( $ui_color_surface ); ?>">
						</label>
						<label><?php esc_html_e( 'Texto', 'atora-lms' ); ?><br>
							<input type="color" name="ui_color_text" value="<?php echo esc_attr( $ui_color_text ); ?>">
						</label>
						<label><?php esc_html_e( 'Texto secundario', 'atora-lms' ); ?><br>
							<input type="color" name="ui_color_muted" value="<?php echo esc_attr( $ui_color_muted ); ?>">
						</label>
						<label><?php esc_html_e( 'Borde', 'atora-lms' ); ?><br>
							<input type="color" name="ui_color_border" value="<?php echo esc_attr( $ui_color_border ); ?>">
						</label>
						<label><?php esc_html_e( 'Acento', 'atora-lms' ); ?><br>
							<input type="color" name="ui_color_accent" value="<?php echo esc_attr( $ui_color_accent ); ?>">
						</label>
						<label><?php esc_html_e( 'Acento hover', 'atora-lms' ); ?><br>
							<input type="color" name="ui_color_accent_hover" value="<?php echo esc_attr( $ui_color_accent_hover ); ?>">
						</label>
						<label><?php esc_html_e( 'Acento suave', 'atora-lms' ); ?><br>
							<input type="color" name="ui_color_accent_soft" value="<?php echo esc_attr( $ui_color_accent_soft ); ?>">
						</label>
					</div>
					<p class="description"><?php esc_html_e( 'Solo se aplica cuando el tema está en Personalizado.', 'atora-lms' ); ?></p>
				</td>
			</tr>
				<tr>
					<th><label><?php esc_html_e( 'Logo', 'atora-lms' ); ?></label></th>
					<td>
						<input type="hidden" name="logo_id" id="clms_logo_id" value="<?php echo esc_attr( $logo_id ); ?>">
						<div id="clms_logo_preview" style="margin-bottom:10px;">
							<?php if ( $logo_id ) : ?>
								<?php echo wp_get_attachment_image( $logo_id, 'thumbnail' ); ?>
							<?php endif; ?>
						</div>
						<button type="button" class="button" id="clms_logo_pick">
							<?php echo $logo_id ? esc_html__( 'Cambiar logo', 'atora-lms' ) : esc_html__( 'Seleccionar logo', 'atora-lms' ); ?>
						</button>
						<button type="button" class="button" id="clms_logo_clear" style="<?php echo $logo_id ? '' : 'display:none;'; ?>">
							<?php esc_html_e( 'Quitar', 'atora-lms' ); ?>
						</button>
						<p id="clms_logo_msg" class="description" style="display:none;color:#b32d2e;margin-top:8px;">
							<?php esc_html_e( 'No se pudo abrir la librería de medios de WordPress.', 'atora-lms' ); ?>
						</p>
						<script>
						(function(){
							var btn   = document.getElementById('clms_logo_pick');
							var clr   = document.getElementById('clms_logo_clear');
							var input = document.getElementById('clms_logo_id');
							var preview = document.getElementById('clms_logo_preview');
							var msg = document.getElementById('clms_logo_msg');
							var frame = null;
							var labels = {
								title: <?php echo wp_json_encode( __( 'Seleccionar logo', 'atora-lms' ) ); ?>,
								change: <?php echo wp_json_encode( __( 'Cambiar logo', 'atora-lms' ) ); ?>,
								use: <?php echo wp_json_encode( __( 'Usar', 'atora-lms' ) ); ?>
							};

							if (!btn || !input || !preview) return;

							function setError(show){
								if(!msg) return;
								msg.style.display = show ? 'block' : 'none';
							}

							function toggleClear(){
								if(!clr) return;
								clr.style.display = input.value ? '' : 'none';
							}

							function renderPreview(att){
								preview.innerHTML = '';
								if(!att || !att.url) return;
								var img = document.createElement('img');
								var thumb = att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url ? att.sizes.thumbnail.url : att.url;
								img.src = thumb;
								img.alt = '';
								img.style.maxWidth = '120px';
								img.style.height = 'auto';
								img.style.display = 'block';
								preview.appendChild(img);
							}

							btn.addEventListener('click', function(){
								setError(false);
								if (!window.wp || !wp.media) {
									setError(true);
									return;
								}

								if (!frame) {
									frame = wp.media({ title: labels.title, button: { text: labels.use }, multiple: false });
									frame.on('select', function(){
										var att = frame.state().get('selection').first().toJSON();
										input.value = att.id || '';
										btn.textContent = labels.change;
										renderPreview(att);
										toggleClear();
									});
								}

								if (input.value) {
									var selection = frame.state().get('selection');
									selection.reset();
								}

								frame.open();
							});

							if (clr) {
								clr.addEventListener('click', function(){
									input.value = '';
									preview.innerHTML = '';
									btn.textContent = labels.title;
									toggleClear();
									setError(false);
								});
							}

							toggleClear();
							if (!input.value) {
								preview.innerHTML = '';
							} else if (!preview.innerHTML.trim()) {
								<?php if ( $logo_id ) : ?>
								renderPreview({
									url: <?php echo wp_json_encode( wp_get_attachment_url( $logo_id ) ); ?>
								});
								<?php endif; ?>
							}
						})();
						</script>
					</td>
				</tr>
		</table>
		<script>
		(function(){
			var themeSelect = document.getElementById('ui_theme');
			if (!themeSelect) return;
			function toggleCustom(){
				var show = themeSelect.value === 'custom';
				document.querySelectorAll('.clms-theme-custom-row').forEach(function(row){
					row.style.display = show ? '' : 'none';
				});
			}
			themeSelect.addEventListener('change', toggleCustom);
			toggleCustom();
		})();
		</script>
		<?php
	}

	// ── Tab: Canales ────────────────────────────────────────────────────────────

	protected function render_tab_channels( $channels ) {
		$email     = isset( $channels['email'] ) && is_array( $channels['email'] ) ? $channels['email'] : self::get_email_engine_settings();
		$whatsapp  = isset( $channels['whatsapp'] ) && is_array( $channels['whatsapp'] ) ? $channels['whatsapp'] : self::get_whatsapp_settings();
		$telegram  = isset( $channels['telegram'] ) && is_array( $channels['telegram'] ) ? $channels['telegram'] : self::get_telegram_settings();
		$teams     = isset( $channels['teams'] ) && is_array( $channels['teams'] ) ? $channels['teams'] : self::get_teams_settings();
		$statuses  = isset( $channels['status'] ) && is_array( $channels['status'] ) ? $channels['status'] : array();

		$email_ready    = ! empty( $statuses['email_ready'] );
		$whatsapp_ready = ! empty( $statuses['whatsapp_ready'] );
		$telegram_ready = ! empty( $statuses['telegram_ready'] );
		$teams_ready    = ! empty( $statuses['teams_ready'] );
		?>
		<h2><?php esc_html_e( 'Canales de comunicación', 'atora-lms' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Configura Email, WhatsApp y Telegram desde una sola vista. Este panel opera en conjunto con CRM Hub y la bandeja unificada.', 'atora-lms' ); ?></p>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:16px 0 18px;max-width:980px;">
			<div style="border:1px solid #d1fae5;background:#f0fdf4;border-radius:12px;padding:12px 14px;">
				<strong style="display:block;color:#065f46;"><?php esc_html_e( 'Email Engine', 'atora-lms' ); ?></strong>
				<span style="color:#047857;"><?php echo esc_html( $email_ready ? __( 'Listo para enviar', 'atora-lms' ) : __( 'Pendiente de configuración', 'atora-lms' ) ); ?></span>
			</div>
			<div style="border:1px solid #dbeafe;background:#eff6ff;border-radius:12px;padding:12px 14px;">
				<strong style="display:block;color:#1e3a8a;"><?php esc_html_e( 'WhatsApp', 'atora-lms' ); ?></strong>
				<span style="color:#1d4ed8;"><?php echo esc_html( $whatsapp_ready ? __( 'Conectado', 'atora-lms' ) : __( 'Sin credenciales completas', 'atora-lms' ) ); ?></span>
			</div>
				<div style="border:1px solid #ede9fe;background:#f5f3ff;border-radius:12px;padding:12px 14px;">
					<strong style="display:block;color:#5b21b6;"><?php esc_html_e( 'Telegram', 'atora-lms' ); ?></strong>
					<span style="color:#6d28d9;"><?php echo esc_html( $telegram_ready ? __( 'Conectado', 'atora-lms' ) : __( 'Sin token o secreto', 'atora-lms' ) ); ?></span>
				</div>
				<div style="border:1px solid #fee2e2;background:#fff1f2;border-radius:12px;padding:12px 14px;">
					<strong style="display:block;color:#9f1239;"><?php esc_html_e( 'Microsoft Teams', 'atora-lms' ); ?></strong>
					<span style="color:#be123c;"><?php echo esc_html( $teams_ready ? __( 'Conectado', 'atora-lms' ) : __( 'Sin webhook', 'atora-lms' ) ); ?></span>
				</div>
			</div>

		<h3 style="margin-top:22px"><?php esc_html_e( 'Email Engine e identidades por área', 'atora-lms' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="clms_email_provider"><?php esc_html_e( 'Proveedor activo', 'atora-lms' ); ?></label></th>
				<td>
					<select id="clms_email_provider" name="clms_email_engine[provider]">
						<option value="smtp" <?php selected( (string) $email['provider'], 'smtp' ); ?>><?php esc_html_e( 'SMTP', 'atora-lms' ); ?></option>
						<option value="brevo" <?php selected( (string) $email['provider'], 'brevo' ); ?>><?php esc_html_e( 'Brevo', 'atora-lms' ); ?></option>
						<option value="sendgrid" <?php selected( (string) $email['provider'], 'sendgrid' ); ?>><?php esc_html_e( 'SendGrid', 'atora-lms' ); ?></option>
						<option value="mailgun" <?php selected( (string) $email['provider'], 'mailgun' ); ?>><?php esc_html_e( 'Mailgun', 'atora-lms' ); ?></option>
						<option value="ses" <?php selected( (string) $email['provider'], 'ses' ); ?>><?php esc_html_e( 'Amazon SES', 'atora-lms' ); ?></option>
						<option value="postmark" <?php selected( (string) $email['provider'], 'postmark' ); ?>><?php esc_html_e( 'Postmark', 'atora-lms' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'El motor de cola de correos usará este proveedor para envíos transaccionales.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Identidad Academia / Admin', 'atora-lms' ); ?></th>
				<td>
					<input type="text" class="regular-text" name="clms_email_engine[identity_academia_from_name]" value="<?php echo esc_attr( (string) $email['identity_academia_from_name'] ); ?>" placeholder="<?php esc_attr_e( 'Nombre remitente', 'atora-lms' ); ?>">
					<input type="email" class="regular-text" name="clms_email_engine[identity_academia_from_email]" value="<?php echo esc_attr( (string) $email['identity_academia_from_email'] ); ?>" placeholder="academia@dominio.com">
					<input type="email" class="regular-text" name="clms_email_engine[identity_academia_reply_to]" value="<?php echo esc_attr( (string) $email['identity_academia_reply_to'] ); ?>" placeholder="reply@dominio.com">
					<p class="description"><?php esc_html_e( 'Operación de plataforma, tienda WooCommerce, afiliados y notificaciones institucionales.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Identidad Docencia', 'atora-lms' ); ?></th>
				<td>
					<input type="text" class="regular-text" name="clms_email_engine[identity_teacher_from_name]" value="<?php echo esc_attr( (string) $email['identity_teacher_from_name'] ); ?>" placeholder="<?php esc_attr_e( 'Nombre remitente', 'atora-lms' ); ?>">
					<input type="email" class="regular-text" name="clms_email_engine[identity_teacher_from_email]" value="<?php echo esc_attr( (string) $email['identity_teacher_from_email'] ); ?>" placeholder="docencia@dominio.com">
					<input type="email" class="regular-text" name="clms_email_engine[identity_teacher_reply_to]" value="<?php echo esc_attr( (string) $email['identity_teacher_reply_to'] ); ?>" placeholder="reply-docente@dominio.com">
					<p class="description"><?php esc_html_e( 'Para flujos de acompañamiento y seguimiento académico de estudiantes.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Identidad Comercial', 'atora-lms' ); ?></th>
				<td>
					<input type="text" class="regular-text" name="clms_email_engine[identity_admin_from_name]" value="<?php echo esc_attr( (string) $email['identity_admin_from_name'] ); ?>" placeholder="<?php esc_attr_e( 'Nombre remitente', 'atora-lms' ); ?>">
					<input type="email" class="regular-text" name="clms_email_engine[identity_admin_from_email]" value="<?php echo esc_attr( (string) $email['identity_admin_from_email'] ); ?>" placeholder="admin@dominio.com">
					<input type="email" class="regular-text" name="clms_email_engine[identity_admin_reply_to]" value="<?php echo esc_attr( (string) $email['identity_admin_reply_to'] ); ?>" placeholder="reply-admin@dominio.com">
					<p class="description"><?php esc_html_e( 'Captación y nurturing de leads/prospectos, campañas y cierre comercial.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="clms_brand_name"><?php esc_html_e( 'Branding de correo', 'atora-lms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="clms_brand_name" name="clms_email_engine[brand_name]" value="<?php echo esc_attr( (string) $email['brand_name'] ); ?>" placeholder="<?php esc_attr_e( 'Nombre de marca', 'atora-lms' ); ?>">
					<input type="url" class="regular-text" name="clms_email_engine[brand_logo_url]" value="<?php echo esc_attr( (string) $email['brand_logo_url'] ); ?>" placeholder="https://.../logo.png">
					<input type="color" name="clms_email_engine[brand_primary_color]" value="<?php echo esc_attr( (string) $email['brand_primary_color'] ); ?>">
					<p class="description"><?php esc_html_e( 'Color, logo y nombre usados por las plantillas del Email Engine.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'SMTP', 'atora-lms' ); ?></th>
				<td>
					<input type="text" class="regular-text" name="clms_email_engine[smtp_host]" value="<?php echo esc_attr( (string) $email['smtp_host'] ); ?>" placeholder="smtp.dominio.com">
					<input type="number" min="1" max="65535" step="1" style="width:110px" name="clms_email_engine[smtp_port]" value="<?php echo esc_attr( (string) absint( $email['smtp_port'] ) ); ?>">
					<select name="clms_email_engine[smtp_encryption]">
						<option value="" <?php selected( (string) $email['smtp_encryption'], '' ); ?>><?php esc_html_e( 'Sin cifrado', 'atora-lms' ); ?></option>
						<option value="tls" <?php selected( (string) $email['smtp_encryption'], 'tls' ); ?>>TLS</option>
						<option value="ssl" <?php selected( (string) $email['smtp_encryption'], 'ssl' ); ?>>SSL</option>
					</select>
					<label style="margin-left:10px">
						<input type="checkbox" name="clms_email_engine[smtp_auth]" value="1" <?php checked( ! empty( $email['smtp_auth'] ) ); ?>>
						<?php esc_html_e( 'Autenticación', 'atora-lms' ); ?>
					</label>
					<br>
					<input type="text" class="regular-text" name="clms_email_engine[smtp_user]" value="<?php echo esc_attr( (string) $email['smtp_user'] ); ?>" placeholder="<?php esc_attr_e( 'Usuario SMTP', 'atora-lms' ); ?>">
					<input type="password" class="regular-text" autocomplete="off" name="clms_email_engine[smtp_pass]" value="<?php echo esc_attr( (string) $email['smtp_pass'] ); ?>" placeholder="<?php esc_attr_e( 'Contraseña SMTP', 'atora-lms' ); ?>">
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Credenciales API', 'atora-lms' ); ?></th>
				<td>
					<p style="margin:0 0 6px"><strong><?php esc_html_e( 'Brevo', 'atora-lms' ); ?></strong></p>
					<input type="password" class="regular-text" autocomplete="off" name="clms_email_engine[brevo_api_key]" value="<?php echo esc_attr( (string) $email['brevo_api_key'] ); ?>" placeholder="<?php esc_attr_e( 'API key Brevo', 'atora-lms' ); ?>">
					<p style="margin:12px 0 6px"><strong><?php esc_html_e( 'SendGrid', 'atora-lms' ); ?></strong></p>
					<input type="password" class="regular-text" autocomplete="off" name="clms_email_engine[sendgrid_api_key]" value="<?php echo esc_attr( (string) $email['sendgrid_api_key'] ); ?>" placeholder="<?php esc_attr_e( 'API key SendGrid', 'atora-lms' ); ?>">
					<input type="text" class="regular-text" name="clms_email_engine[sendgrid_webhook_key]" value="<?php echo esc_attr( (string) $email['sendgrid_webhook_key'] ); ?>" placeholder="<?php esc_attr_e( 'Webhook signing key', 'atora-lms' ); ?>">
					<p style="margin:12px 0 6px"><strong><?php esc_html_e( 'Mailgun', 'atora-lms' ); ?></strong></p>
					<input type="password" class="regular-text" autocomplete="off" name="clms_email_engine[mailgun_api_key]" value="<?php echo esc_attr( (string) $email['mailgun_api_key'] ); ?>" placeholder="<?php esc_attr_e( 'API key Mailgun', 'atora-lms' ); ?>">
					<input type="text" class="regular-text" name="clms_email_engine[mailgun_domain]" value="<?php echo esc_attr( (string) $email['mailgun_domain'] ); ?>" placeholder="mg.dominio.com">
					<select name="clms_email_engine[mailgun_region]">
						<option value="us" <?php selected( (string) $email['mailgun_region'], 'us' ); ?>>US</option>
						<option value="eu" <?php selected( (string) $email['mailgun_region'], 'eu' ); ?>>EU</option>
					</select>
					<input type="text" class="regular-text" name="clms_email_engine[mailgun_webhook_signing_key]" value="<?php echo esc_attr( (string) $email['mailgun_webhook_signing_key'] ); ?>" placeholder="<?php esc_attr_e( 'Webhook signing key', 'atora-lms' ); ?>">
					<p style="margin:12px 0 6px"><strong><?php esc_html_e( 'Postmark', 'atora-lms' ); ?></strong></p>
					<input type="password" class="regular-text" autocomplete="off" name="clms_email_engine[postmark_api_key]" value="<?php echo esc_attr( (string) $email['postmark_api_key'] ); ?>" placeholder="<?php esc_attr_e( 'API key Postmark', 'atora-lms' ); ?>">
					<input type="text" class="regular-text" name="clms_email_engine[postmark_webhook_secret]" value="<?php echo esc_attr( (string) $email['postmark_webhook_secret'] ); ?>" placeholder="<?php esc_attr_e( 'Webhook secret', 'atora-lms' ); ?>">
					<p style="margin:12px 0 6px"><strong><?php esc_html_e( 'Amazon SES', 'atora-lms' ); ?></strong></p>
					<input type="text" class="regular-text" name="clms_email_engine[ses_access_key]" value="<?php echo esc_attr( (string) $email['ses_access_key'] ); ?>" placeholder="<?php esc_attr_e( 'Access key', 'atora-lms' ); ?>">
					<input type="password" class="regular-text" autocomplete="off" name="clms_email_engine[ses_secret_key]" value="<?php echo esc_attr( (string) $email['ses_secret_key'] ); ?>" placeholder="<?php esc_attr_e( 'Secret key', 'atora-lms' ); ?>">
					<input type="text" class="regular-text" name="clms_email_engine[ses_region]" value="<?php echo esc_attr( (string) $email['ses_region'] ); ?>" placeholder="us-east-1">
					<p class="description"><?php esc_html_e( 'Puedes completar solo el proveedor que usarás. Los demás pueden quedar vacíos.', 'atora-lms' ); ?></p>
				</td>
			</tr>
		</table>

		<h3 style="margin-top:26px"><?php esc_html_e( 'WhatsApp Business', 'atora-lms' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="clms_whatsapp_access_token"><?php esc_html_e( 'Access token', 'atora-lms' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" autocomplete="off" id="clms_whatsapp_access_token" name="clms_whatsapp[access_token]" value="<?php echo esc_attr( (string) $whatsapp['access_token'] ); ?>">
				</td>
			</tr>
			<tr>
				<th><label for="clms_whatsapp_phone_id"><?php esc_html_e( 'Phone number ID', 'atora-lms' ); ?></label></th>
				<td><input type="text" class="regular-text" id="clms_whatsapp_phone_id" name="clms_whatsapp[phone_number_id]" value="<?php echo esc_attr( (string) $whatsapp['phone_number_id'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="clms_whatsapp_app_secret"><?php esc_html_e( 'App secret', 'atora-lms' ); ?></label></th>
				<td><input type="password" class="regular-text" autocomplete="off" id="clms_whatsapp_app_secret" name="clms_whatsapp[app_secret]" value="<?php echo esc_attr( (string) $whatsapp['app_secret'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="clms_whatsapp_verify_token"><?php esc_html_e( 'Webhook verify token', 'atora-lms' ); ?></label></th>
				<td><input type="text" class="regular-text" id="clms_whatsapp_verify_token" name="clms_whatsapp[webhook_verify_token]" value="<?php echo esc_attr( (string) $whatsapp['webhook_verify_token'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="clms_whatsapp_language"><?php esc_html_e( 'Idioma plantilla', 'atora-lms' ); ?></label></th>
				<td>
					<input type="text" class="small-text" id="clms_whatsapp_language" name="clms_whatsapp[language]" value="<?php echo esc_attr( (string) $whatsapp['language'] ); ?>">
					<p class="description"><?php esc_html_e( 'Ejemplos: es, es_MX, en. Endpoint de webhook: /wp-json/atora/v1/webhooks/whatsapp', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Plantillas por defecto', 'atora-lms' ); ?></th>
				<td>
					<input type="text" class="regular-text" name="clms_whatsapp[default_template_inactivity]" value="<?php echo esc_attr( (string) $whatsapp['default_template_inactivity'] ); ?>" placeholder="inactivity_reminder">
					<input type="text" class="regular-text" name="clms_whatsapp[default_template_grade]" value="<?php echo esc_attr( (string) $whatsapp['default_template_grade'] ); ?>" placeholder="grade_published">
					<input type="text" class="regular-text" name="clms_whatsapp[default_template_purchase]" value="<?php echo esc_attr( (string) $whatsapp['default_template_purchase'] ); ?>" placeholder="purchase_completed">
				</td>
			</tr>
		</table>

			<h3 style="margin-top:26px"><?php esc_html_e( 'Telegram Bot', 'atora-lms' ); ?></h3>
			<table class="form-table" role="presentation">
			<tr>
				<th><label for="clms_tg_token"><?php esc_html_e( 'Bot token', 'atora-lms' ); ?></label></th>
				<td><input type="password" class="regular-text" autocomplete="off" id="clms_tg_token" name="clms_telegram[bot_token]" value="<?php echo esc_attr( (string) $telegram['bot_token'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="clms_tg_secret"><?php esc_html_e( 'Webhook secret', 'atora-lms' ); ?></label></th>
				<td><input type="text" class="regular-text" id="clms_tg_secret" name="clms_telegram[webhook_secret]" value="<?php echo esc_attr( (string) $telegram['webhook_secret'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="clms_tg_username"><?php esc_html_e( 'Usuario del bot', 'atora-lms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="clms_tg_username" name="clms_telegram[bot_username]" value="<?php echo esc_attr( (string) $telegram['bot_username'] ); ?>" placeholder="atora_bot">
					<p class="description"><?php esc_html_e( 'Sin @. Endpoint de webhook: /wp-json/atora/v1/telegram/webhook', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="clms_tg_admin_chat"><?php esc_html_e( 'Chat ID admin', 'atora-lms' ); ?></label></th>
				<td><input type="text" class="regular-text" id="clms_tg_admin_chat" name="clms_telegram[admin_chat_id]" value="<?php echo esc_attr( (string) $telegram['admin_chat_id'] ); ?>" placeholder="-1001234567890"></td>
			</tr>
		</table>

		<div class="notice inline notice-info" style="margin-top:20px;max-width:980px;">
			<p><strong><?php esc_html_e( 'Siguiente paso recomendado:', 'atora-lms' ); ?></strong> <?php esc_html_e( 'Después de guardar, valida disparadores desde CRM Hub y la bandeja unificada para confirmar entrega por canal y rol.', 'atora-lms' ); ?></p>
			<p style="margin:8px 0 0;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm&tab=inbox' ) ); ?>"><?php esc_html_e( 'Abrir bandeja unificada', 'atora-lms' ); ?></a>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-crm-hub' ) ); ?>"><?php esc_html_e( 'Abrir CRM Hub', 'atora-lms' ); ?></a>
			</p>
		</div>
		<?php
	}

	protected function render_tab_navigation( $nav ) {
		$menus     = $this->get_available_wp_menus();
		$locations = $this->get_registered_menu_locations();
		?>
		<h2><?php esc_html_e( 'Menús por contexto de usuario', 'atora-lms' ); ?></h2>

		<?php if ( empty( $locations ) ) : ?>
			<div class="notice inline notice-warning"><p><?php esc_html_e( 'El tema activo no ha registrado ubicaciones de menú. No es posible aplicar la conmutación automática hasta que el tema defina al menos una ubicación para cabecera o footer.', 'atora-lms' ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! empty( $locations ) ) : ?>
		<div class="notice inline notice-info" style="margin:0 0 18px;padding:12px 14px;border-radius:6px">
			<p style="margin:0 0 8px"><strong><?php esc_html_e( 'Paso 1 — Conectar con tu tema', 'atora-lms' ); ?></strong><br>
			<?php esc_html_e( 'Indica a Atora qué "ranura" de menú de tu tema debe tomar el control. Elige la ubicación que tu tema usa para la cabecera y para el footer. Si lo dejas en "Usar … del tema", el tema seguirá gestionando ese menú por su cuenta y los ajustes del Paso 2 NO tendrán efecto.', 'atora-lms' ); ?></p>
			<p style="margin:0"><strong><?php esc_html_e( 'Paso 2 — Asignar menú por tipo de usuario', 'atora-lms' ); ?></strong><br>
			<?php esc_html_e( 'Una vez conectada la ranura (Paso 1), elige qué menú concreto verá cada tipo de usuario. Atora intercambia el menú en tiempo real según quién esté conectado.', 'atora-lms' ); ?></p>
		</div>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Paso 1 · Conectar ranuras del tema', 'atora-lms' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="header_location"><?php esc_html_e( 'Ranura de cabecera', 'atora-lms' ); ?></label></th>
				<td>
					<select name="header_location" id="header_location">
						<option value=""><?php esc_html_e( '— Usar cabecera del tema (sin cambios) —', 'atora-lms' ); ?></option>
						<?php foreach ( $locations as $location_key => $location_label ) : ?>
							<option value="<?php echo esc_attr( $location_key ); ?>" <?php selected( $nav['header_location'], $location_key ); ?>><?php echo esc_html( $location_label . ' (' . $location_key . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Selecciona la ubicación que tu tema usa para mostrar el menú principal. Atora controlará esa ranura con los menús del Paso 2.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="footer_location"><?php esc_html_e( 'Ranura de footer', 'atora-lms' ); ?></label></th>
				<td>
					<select name="footer_location" id="footer_location">
						<option value=""><?php esc_html_e( '— Usar footer del tema (sin cambios) —', 'atora-lms' ); ?></option>
						<?php foreach ( $locations as $location_key => $location_label ) : ?>
							<option value="<?php echo esc_attr( $location_key ); ?>" <?php selected( $nav['footer_location'], $location_key ); ?>><?php echo esc_html( $location_label . ' (' . $location_key . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Selecciona la ubicación que tu tema usa para el footer. Si no tienes menú de footer o prefieres que el tema lo gestione, déjalo sin seleccionar.', 'atora-lms' ); ?></p>
				</td>
			</tr>
		</table>

		<h3 style="margin-top:32px"><?php esc_html_e( 'Paso 2 · Asignar menú por tipo de usuario (cabecera)', 'atora-lms' ); ?></h3>
		<table class="form-table" role="presentation">
			<?php $this->render_menu_assignment_row( 'header_menu_guest', __( 'Visitante no autenticado', 'atora-lms' ), $nav, $menus ); ?>
			<?php $this->render_menu_assignment_row( 'header_menu_student', __( 'Estudiante', 'atora-lms' ), $nav, $menus ); ?>
			<?php $this->render_menu_assignment_row( 'header_menu_instructor', __( 'Docente', 'atora-lms' ), $nav, $menus ); ?>
			<?php $this->render_menu_assignment_row( 'header_menu_collaborator', __( 'Colaborador / editor', 'atora-lms' ), $nav, $menus ); ?>
			<?php $this->render_menu_assignment_row( 'header_menu_admin', __( 'Administrador', 'atora-lms' ), $nav, $menus ); ?>
		</table>

		<h3 style="margin-top:28px"><?php esc_html_e( 'Paso 2 · Menú del footer', 'atora-lms' ); ?></h3>
		<table class="form-table" role="presentation">
			<?php $this->render_menu_assignment_row( 'footer_menu', __( 'Menú del footer', 'atora-lms' ), $nav, $menus ); ?>
		</table>
		<?php
	}

	// ── Tab: APIs e IA ───────────────────────────────────────────────────────────

	protected function render_tab_apis( $ai ) {
		$whisper_key = isset( $ai['whisper_api_key'] ) ? $ai['whisper_api_key'] : '';
		$ai_enabled  = ! empty( $ai['ai_enabled'] );
		$evaluation_mode = isset( $ai['ai_evaluation_mode'] ) ? sanitize_key( (string) $ai['ai_evaluation_mode'] ) : 'assisted';
		?>
		<h2><?php esc_html_e( 'Control general de IA', 'atora-lms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="ai_enabled"><?php esc_html_e( 'Estado general', 'atora-lms' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" id="ai_enabled" name="ai_enabled" value="1" <?php checked( $ai_enabled ); ?>>
						<?php esc_html_e( 'Activar capa IA de ATORA', 'atora-lms' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Cuando está desactivado, los copilotos no responderán aunque haya provider configurado.', 'atora-lms' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Proveedor de IA activo', 'atora-lms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="clms_provider"><?php esc_html_e( 'Proveedor', 'atora-lms' ); ?></label></th>
				<td>
					<select name="provider" id="clms_provider">
						<option value="openai"    <?php selected( $ai['provider'], 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'atora-lms' ); ?></option>
						<option value="anthropic" <?php selected( $ai['provider'], 'anthropic' ); ?>><?php esc_html_e( 'Anthropic / Claude', 'atora-lms' ); ?></option>
						<option value="gemini"    <?php selected( $ai['provider'], 'gemini' ); ?>><?php esc_html_e( 'Google Gemini', 'atora-lms' ); ?></option>
						<option value="deepseek"  <?php selected( $ai['provider'], 'deepseek' ); ?>><?php esc_html_e( 'DeepSeek', 'atora-lms' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Usado para revisiones automáticas y el Asistente de Profesor.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Prueba de conexión', 'atora-lms' ); ?></label></th>
				<td>
					<button type="button" class="button button-secondary" id="clms-ai-test-connection"><?php esc_html_e( 'Probar conexión IA', 'atora-lms' ); ?></button>
					<p class="description" id="clms-ai-test-feedback" aria-live="polite"><?php esc_html_e( 'Verifica API key, modelo y conectividad del proveedor activo.', 'atora-lms' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 style="margin-top:28px"><?php esc_html_e( 'OpenAI', 'atora-lms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="openai_api_key"><?php esc_html_e( 'API Key', 'atora-lms' ); ?></label></th>
				<td><input type="password" class="regular-text" id="openai_api_key" name="openai_api_key" value="<?php echo esc_attr( $ai['openai_api_key'] ); ?>" autocomplete="off"></td>
			</tr>
			<tr>
				<th><label for="openai_model"><?php esc_html_e( 'Modelo', 'atora-lms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="openai_model" name="openai_model" value="<?php echo esc_attr( $ai['openai_model'] ); ?>">
					<p class="description"><?php printf( esc_html__( 'Recomendado: %s', 'atora-lms' ), '<code>gpt-4o-mini</code>' ); ?></p>
				</td>
			</tr>
		</table>

			<h2 style="margin-top:28px"><?php esc_html_e( 'Whisper (transcripción de audio/video)', 'atora-lms' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="whisper_api_key"><?php esc_html_e( 'API Key de Whisper', 'atora-lms' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" id="whisper_api_key" name="whisper_api_key" value="<?php echo esc_attr( $whisper_key ); ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Whisper requiere su propia API key en este campo. Sin esta key, la transcripción con Whisper permanecerá deshabilitada.', 'atora-lms' ); ?></p>
					</td>
				</tr>
			</table>

			<h3 style="margin-top:26px"><?php esc_html_e( 'Microsoft Teams (webhook)', 'atora-lms' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Activar', 'atora-lms' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="clms_teams[enabled]" value="1" <?php checked( ! empty( $teams['enabled'] ) ); ?>>
							<?php esc_html_e( 'Enviar alertas/notificaciones importantes a Teams', 'atora-lms' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="clms_teams_webhook"><?php esc_html_e( 'Webhook URL', 'atora-lms' ); ?></label></th>
					<td>
						<input type="url" class="large-text" id="clms_teams_webhook" name="clms_teams[webhook_url]" value="<?php echo esc_attr( (string) $teams['webhook_url'] ); ?>" placeholder="https://outlook.office.com/webhook/...">
						<p class="description"><?php esc_html_e( 'Crea un Incoming Webhook en Teams y pega aquí la URL. Recomendado para coordinación y docentes.', 'atora-lms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Eventos a enviar', 'atora-lms' ); ?></th>
					<td>
						<?php $send_types = isset( $teams['send_types'] ) && is_array( $teams['send_types'] ) ? $teams['send_types'] : array(); ?>
						<label style="display:block;margin:0 0 6px">
							<input type="checkbox" name="clms_teams[send_types][]" value="early_warning" <?php checked( in_array( 'early_warning', $send_types, true ) ); ?>>
							<?php esc_html_e( 'Alertas tempranas (entregas perdidas)', 'atora-lms' ); ?>
						</label>
						<label style="display:block;margin:0 0 6px">
							<input type="checkbox" name="clms_teams[send_types][]" value="submission_created" <?php checked( in_array( 'submission_created', $send_types, true ) ); ?>>
							<?php esc_html_e( 'Nueva entrega', 'atora-lms' ); ?>
						</label>
						<label style="display:block">
							<input type="checkbox" name="clms_teams[send_types][]" value="submission_graded" <?php checked( in_array( 'submission_graded', $send_types, true ) ); ?>>
							<?php esc_html_e( 'Entrega calificada', 'atora-lms' ); ?>
						</label>
					</td>
				</tr>
			</table>

		<h2 style="margin-top:28px"><?php esc_html_e( 'Anthropic / Claude', 'atora-lms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="anthropic_api_key"><?php esc_html_e( 'API Key', 'atora-lms' ); ?></label></th>
				<td><input type="password" class="regular-text" id="anthropic_api_key" name="anthropic_api_key" value="<?php echo esc_attr( $ai['anthropic_api_key'] ); ?>" autocomplete="off"></td>
			</tr>
			<tr>
				<th><label for="anthropic_model"><?php esc_html_e( 'Modelo', 'atora-lms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="anthropic_model" name="anthropic_model" value="<?php echo esc_attr( $ai['anthropic_model'] ); ?>">
					<p class="description"><?php printf( esc_html__( 'Recomendado: %s', 'atora-lms' ), '<code>claude-sonnet-4-6</code>' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 style="margin-top:28px"><?php esc_html_e( 'Google Gemini', 'atora-lms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="gemini_api_key"><?php esc_html_e( 'API Key', 'atora-lms' ); ?></label></th>
				<td><input type="password" class="regular-text" id="gemini_api_key" name="gemini_api_key" value="<?php echo esc_attr( $ai['gemini_api_key'] ); ?>" autocomplete="off"></td>
			</tr>
			<tr>
				<th><label for="gemini_model"><?php esc_html_e( 'Modelo', 'atora-lms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="gemini_model" name="gemini_model" value="<?php echo esc_attr( $ai['gemini_model'] ); ?>">
					<p class="description"><?php printf( esc_html__( 'Recomendado: %s', 'atora-lms' ), '<code>gemini-2.5-flash</code>' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 style="margin-top:28px"><?php esc_html_e( 'DeepSeek', 'atora-lms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="deepseek_api_key"><?php esc_html_e( 'API Key', 'atora-lms' ); ?></label></th>
				<td><input type="password" class="regular-text" id="deepseek_api_key" name="deepseek_api_key" value="<?php echo esc_attr( $ai['deepseek_api_key'] ); ?>" autocomplete="off"></td>
			</tr>
			<tr>
				<th><label for="deepseek_model"><?php esc_html_e( 'Modelo', 'atora-lms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="deepseek_model" name="deepseek_model" value="<?php echo esc_attr( $ai['deepseek_model'] ); ?>">
					<p class="description"><?php printf( esc_html__( 'Recomendado: %s', 'atora-lms' ), '<code>deepseek-v4-flash</code>' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 style="margin-top:28px"><?php esc_html_e( 'Copilotos ATORA', 'atora-lms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Copiloto comercial', 'atora-lms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="ai_copilot_commercial_enabled" value="1" <?php checked( ! empty( $ai['ai_copilot_commercial_enabled'] ) ); ?>>
						<?php esc_html_e( 'Activar IA para ventas y orientación de inscripción', 'atora-lms' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Copiloto docente', 'atora-lms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="ai_copilot_teacher_enabled" value="1" <?php checked( ! empty( $ai['ai_copilot_teacher_enabled'] ) ); ?>>
						<?php esc_html_e( 'Activar borradores pedagógicos para profesor', 'atora-lms' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Copiloto evaluador', 'atora-lms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="ai_copilot_evaluator_enabled" value="1" <?php checked( ! empty( $ai['ai_copilot_evaluator_enabled'] ) ); ?>>
						<?php esc_html_e( 'Activar sugerencias de evaluación por rúbrica', 'atora-lms' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Copiloto estudiantil', 'atora-lms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="ai_copilot_student_enabled" value="1" <?php checked( ! empty( $ai['ai_copilot_student_enabled'] ) ); ?>>
						<?php esc_html_e( 'Activar tutor IA para estudiantes', 'atora-lms' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><label for="ai_evaluation_mode"><?php esc_html_e( 'Modo de evaluación IA', 'atora-lms' ); ?></label></th>
				<td>
					<select id="ai_evaluation_mode" name="ai_evaluation_mode">
						<option value="manual" <?php selected( $evaluation_mode, 'manual' ); ?>><?php esc_html_e( 'Asistencia manual', 'atora-lms' ); ?></option>
						<option value="assisted" <?php selected( $evaluation_mode, 'assisted' ); ?>><?php esc_html_e( 'IA asistida', 'atora-lms' ); ?></option>
						<option value="automatic" <?php selected( $evaluation_mode, 'automatic' ); ?>><?php esc_html_e( 'IA automática', 'atora-lms' ); ?></option>
						<option value="hybrid" <?php selected( $evaluation_mode, 'hybrid' ); ?>><?php esc_html_e( 'Híbrido', 'atora-lms' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Recomendado: IA asistida. La evaluación automática nunca debe activarse sin política institucional explícita.', 'atora-lms' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 style="margin-top:28px"><?php esc_html_e( 'Límites y trazabilidad', 'atora-lms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="ai_limit_role_admin_hour"><?php esc_html_e( 'Límite admin por hora', 'atora-lms' ); ?></label></th>
				<td><input type="number" min="1" max="500" step="1" id="ai_limit_role_admin_hour" name="ai_limit_role_admin_hour" value="<?php echo esc_attr( (string) $ai['ai_limit_role_admin_hour'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="ai_limit_role_teacher_hour"><?php esc_html_e( 'Límite docente por hora', 'atora-lms' ); ?></label></th>
				<td><input type="number" min="1" max="500" step="1" id="ai_limit_role_teacher_hour" name="ai_limit_role_teacher_hour" value="<?php echo esc_attr( (string) $ai['ai_limit_role_teacher_hour'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="ai_limit_role_student_hour"><?php esc_html_e( 'Límite estudiante por hora', 'atora-lms' ); ?></label></th>
				<td><input type="number" min="1" max="500" step="1" id="ai_limit_role_student_hour" name="ai_limit_role_student_hour" value="<?php echo esc_attr( (string) $ai['ai_limit_role_student_hour'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="ai_limit_role_guest_hour"><?php esc_html_e( 'Límite visitante por hora', 'atora-lms' ); ?></label></th>
				<td><input type="number" min="1" max="500" step="1" id="ai_limit_role_guest_hour" name="ai_limit_role_guest_hour" value="<?php echo esc_attr( (string) $ai['ai_limit_role_guest_hour'] ); ?>"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Logs de IA', 'atora-lms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="ai_logs_enabled" value="1" <?php checked( ! empty( $ai['ai_logs_enabled'] ) ); ?>>
						<?php esc_html_e( 'Registrar acciones IA (usuario, rol, contexto, resultado, uso y costo estimado)', 'atora-lms' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<script>
		(function(){
			var btn = document.getElementById('clms-ai-test-connection');
			var feedback = document.getElementById('clms-ai-test-feedback');
			if (!btn || !feedback) {
				return;
			}
			btn.addEventListener('click', function(){
				btn.disabled = true;
				feedback.textContent = <?php echo wp_json_encode( __( 'Probando conexión con el proveedor activo...', 'atora-lms' ) ); ?>;
				var data = new window.FormData();
				data.append('action', 'clms_ai_test_connection');
				data.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'clms_ai_test_connection' ) ); ?>);

				window.fetch(ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data
				}).then(function(res){
					return res.json();
				}).then(function(payload){
					if (payload && payload.success) {
						feedback.textContent = payload.data && payload.data.message ? payload.data.message : <?php echo wp_json_encode( __( 'Conexión IA exitosa.', 'atora-lms' ) ); ?>;
						feedback.style.color = '#0a7a2f';
						return;
					}
					var msg = payload && payload.data && payload.data.message ? payload.data.message : <?php echo wp_json_encode( __( 'No se pudo verificar la conexión IA.', 'atora-lms' ) ); ?>;
					feedback.textContent = msg;
					feedback.style.color = '#b32d2e';
				}).catch(function(){
					feedback.textContent = <?php echo wp_json_encode( __( 'Error de red al probar conexión IA.', 'atora-lms' ) ); ?>;
					feedback.style.color = '#b32d2e';
				}).finally(function(){
					btn.disabled = false;
				});
			});
		})();
		</script>
		<?php
	}

	// ── Tab: Avanzado ────────────────────────────────────────────────────────────

	protected function render_tab_advanced( $adv ) {
		$cache_status = class_exists( 'CLMS_Cache' ) ? CLMS_Cache::get_object_cache_status() : array(
			'enabled'     => false,
			'driver'      => 'none',
			'dropin_path' => '',
		);
		$crm_v2_enabled = false;
		if ( defined( 'CLMS_Settings::OPTION_CRM_V2_ENABLED' ) ) {
			$crm_v2_enabled = (bool) self::sanitize_feature_flag_boolean( get_option( self::OPTION_CRM_V2_ENABLED, false ) );
		} else {
			$crm_v2_enabled = (bool) self::sanitize_feature_flag_boolean( get_option( 'clms_crm_v2_enabled', false ) );
		}
		$cache_label  = $cache_status['enabled'] ? esc_html__( 'Activo', 'atora-lms' ) : esc_html__( 'No detectado', 'atora-lms' );
		$driver_label = 'redis' === $cache_status['driver']
			? esc_html__( 'Redis', 'atora-lms' )
			: ( 'memcached' === $cache_status['driver'] ? esc_html__( 'Memcached', 'atora-lms' ) : esc_html__( 'Otro', 'atora-lms' ) );
		$opcache_available = function_exists( 'opcache_get_status' );
		$opcache_enabled   = $opcache_available ? (bool) ini_get( 'opcache.enable' ) : false;
		$wp_cache_enabled  = defined( 'WP_CACHE' ) ? (bool) WP_CACHE : false;
		?>
		<h2><?php esc_html_e( 'Opciones avanzadas', 'atora-lms' ); ?></h2>
		<h3 style="margin-top:12px"><?php esc_html_e( 'Feature flags', 'atora-lms' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="crm_v2_enabled"><?php esc_html_e( 'CRM v2 (beta)', 'atora-lms' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="crm_v2_enabled" id="crm_v2_enabled" value="1" <?php checked( $crm_v2_enabled, true ); ?>>
						<?php esc_html_e( 'Activar la experiencia visual (pipeline, campañas, calendario y contactos).', 'atora-lms' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<?php
		$microsoft = get_option( self::OPTION_MICROSOFT, array() );
		$microsoft = is_array( $microsoft ) ? $microsoft : array();
		$ms_enabled = ! empty( $microsoft['enabled'] );
		$ms_tenant  = sanitize_text_field( (string) ( $microsoft['tenant'] ?? 'common' ) );
		$ms_client  = sanitize_text_field( (string) ( $microsoft['client_id'] ?? '' ) );
		$ms_secret  = sanitize_text_field( (string) ( $microsoft['client_secret'] ?? '' ) );
		$ms_domain  = sanitize_text_field( (string) ( $microsoft['allowed_domain'] ?? '' ) );
		?>

		<h3 style="margin-top:24px"><?php esc_html_e( 'Microsoft Entra (SSO)', 'atora-lms' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Activar', 'atora-lms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="microsoft_enabled" value="1" <?php checked( $ms_enabled, true ); ?>>
						<?php esc_html_e( 'Habilitar inicio de sesión con Microsoft (Entra ID)', 'atora-lms' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><label for="microsoft_tenant"><?php esc_html_e( 'Tenant', 'atora-lms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="microsoft_tenant" name="microsoft_tenant" value="<?php echo esc_attr( $ms_tenant ); ?>" placeholder="common">
					<p class="description"><?php esc_html_e( 'Usa "common" para multi-tenant o coloca tu tenant ID/dominio para restringir.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="microsoft_client_id"><?php esc_html_e( 'Client ID', 'atora-lms' ); ?></label></th>
				<td><input type="text" class="regular-text" id="microsoft_client_id" name="microsoft_client_id" value="<?php echo esc_attr( $ms_client ); ?>"></td>
			</tr>
			<tr>
				<th><label for="microsoft_client_secret"><?php esc_html_e( 'Client Secret', 'atora-lms' ); ?></label></th>
				<td><input type="password" class="regular-text" autocomplete="off" id="microsoft_client_secret" name="microsoft_client_secret" value="<?php echo esc_attr( $ms_secret ); ?>"></td>
			</tr>
			<tr>
				<th><label for="microsoft_allowed_domain"><?php esc_html_e( 'Dominio permitido (opcional)', 'atora-lms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="microsoft_allowed_domain" name="microsoft_allowed_domain" value="<?php echo esc_attr( $ms_domain ); ?>" placeholder="miinstitucion.edu">
					<p class="description"><?php esc_html_e( 'Si se indica, solo se aceptan cuentas con email de ese dominio.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Redirect URI', 'atora-lms' ); ?></th>
				<td>
					<code><?php echo esc_html( rest_url( 'atora/v1/microsoft/oauth/callback' ) ); ?></code>
				</td>
			</tr>
		</table>

		<table class="form-table" role="presentation">
			<tr>
				<th><label for="enable_debug_log"><?php esc_html_e( 'Log de depuración', 'atora-lms' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="enable_debug_log" id="enable_debug_log" value="1" <?php checked( $adv['enable_debug_log'], '1' ); ?>>
						<?php printf( esc_html__( 'Activar logs de CLMS en %s', 'atora-lms' ), '<code>wp-content/debug.log</code>' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><label for="disable_rest_api"><?php esc_html_e( 'REST API', 'atora-lms' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="disable_rest_api" id="disable_rest_api" value="1" <?php checked( $adv['disable_rest_api'], '1' ); ?>>
						<?php esc_html_e( 'Deshabilitar los endpoints REST de Atora para usuarios no autenticados', 'atora-lms' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<h3 style="margin-top:24px"><?php esc_html_e( 'Recordatorios por inactividad', 'atora-lms' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="inactivity_email_enabled"><?php esc_html_e( 'Activar recordatorios', 'atora-lms' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="inactivity_email_enabled" id="inactivity_email_enabled" value="1" <?php checked( $adv['inactivity_email_enabled'], '1' ); ?>>
						<?php esc_html_e( 'Enviar correo al estudiante cuando no ingrese por varios días.', 'atora-lms' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><label for="inactivity_days_threshold"><?php esc_html_e( 'Días de inactividad', 'atora-lms' ); ?></label></th>
				<td>
					<input type="number" min="1" max="90" step="1" id="inactivity_days_threshold" name="inactivity_days_threshold" value="<?php echo esc_attr( (string) $adv['inactivity_days_threshold'] ); ?>">
					<p class="description"><?php esc_html_e( 'Umbral en días sin acceso para disparar el correo.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="inactivity_email_cooldown_hours"><?php esc_html_e( 'Horas entre recordatorios', 'atora-lms' ); ?></label></th>
				<td>
					<input type="number" min="1" max="720" step="1" id="inactivity_email_cooldown_hours" name="inactivity_email_cooldown_hours" value="<?php echo esc_attr( (string) $adv['inactivity_email_cooldown_hours'] ); ?>">
					<p class="description"><?php esc_html_e( 'Evita enviar múltiples correos seguidos al mismo estudiante en el mismo curso.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="inactivity_email_subject"><?php esc_html_e( 'Asunto del correo', 'atora-lms' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="inactivity_email_subject" name="inactivity_email_subject" value="<?php echo esc_attr( (string) $adv['inactivity_email_subject'] ); ?>">
					<p class="description"><?php esc_html_e( 'Variables disponibles: {student_name}, {course_title}, {days_inactive}, {academy_name}.', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="inactivity_email_headline"><?php esc_html_e( 'Título del correo', 'atora-lms' ); ?></label></th>
				<td><input type="text" class="regular-text" id="inactivity_email_headline" name="inactivity_email_headline" value="<?php echo esc_attr( (string) $adv['inactivity_email_headline'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="inactivity_email_button_text"><?php esc_html_e( 'Texto del botón', 'atora-lms' ); ?></label></th>
				<td><input type="text" class="regular-text" id="inactivity_email_button_text" name="inactivity_email_button_text" value="<?php echo esc_attr( (string) $adv['inactivity_email_button_text'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="inactivity_email_footer_note"><?php esc_html_e( 'Nota de pie', 'atora-lms' ); ?></label></th>
				<td><input type="text" class="regular-text" id="inactivity_email_footer_note" name="inactivity_email_footer_note" value="<?php echo esc_attr( (string) $adv['inactivity_email_footer_note'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="inactivity_email_body"><?php esc_html_e( 'Mensaje base', 'atora-lms' ); ?></label></th>
				<td>
					<textarea id="inactivity_email_body" name="inactivity_email_body" class="large-text" rows="6"><?php echo esc_textarea( (string) $adv['inactivity_email_body'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Se inserta dentro de la plantilla institucional de ATORA.', 'atora-lms' ); ?></p>
				</td>
			</tr>
		</table>
		<h3 style="margin-top:24px"><?php esc_html_e( 'Performance: caché de objetos', 'atora-lms' ); ?></h3>
		<div class="notice inline <?php echo $cache_status['enabled'] ? 'notice-success' : 'notice-warning'; ?>">
			<p>
				<strong><?php esc_html_e( 'Estado actual:', 'atora-lms' ); ?></strong>
				<?php echo esc_html( $cache_label ); ?>
				<?php if ( $cache_status['enabled'] ) : ?>
					&nbsp;·&nbsp;<?php printf( esc_html__( 'Driver: %s', 'atora-lms' ), $driver_label ); ?>
				<?php endif; ?>
			</p>
			<?php if ( ! $cache_status['enabled'] ) : ?>
				<p><?php esc_html_e( 'Para más de 500 alumnos concurrentes, se recomienda habilitar Redis o Memcached con un object-cache persistente.', 'atora-lms' ); ?></p>
			<?php elseif ( $cache_status['dropin_path'] ) : ?>
				<p><?php printf( esc_html__( 'Drop-in detectado en: %s', 'atora-lms' ), '<code>' . esc_html( $cache_status['dropin_path'] ) . '</code>' ); ?></p>
			<?php endif; ?>
		</div>
		<ul style="margin:12px 0 0 18px">
			<li><?php printf( esc_html__( 'OPcache: %s', 'atora-lms' ), $opcache_enabled ? esc_html__( 'Activo', 'atora-lms' ) : esc_html__( 'No detectado', 'atora-lms' ) ); ?></li>
			<li><?php printf( esc_html__( 'WP_CACHE (page cache): %s', 'atora-lms' ), $wp_cache_enabled ? esc_html__( 'Activo', 'atora-lms' ) : esc_html__( 'No configurado', 'atora-lms' ) ); ?></li>
			<li><?php esc_html_e( 'Recomendación +500 concurrentes: Redis/Memcached + OPcache + page cache.', 'atora-lms' ); ?></li>
		</ul>
		<?php
	}

	// ── Helpers ──────────────────────────────────────────────────────────────────

}
