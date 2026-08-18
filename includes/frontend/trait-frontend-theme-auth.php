<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Frontend_Theme_Auth_Trait {
	public function enqueue_frontend_styles() {
		$css_url = defined( 'ATORA_LMS_URL' )
			? ATORA_LMS_URL . 'assets/css/frontend.css'
			: ATORA_LMS_URL;
		// Resolve URL from constant path
		if ( defined( 'ATORA_LMS_URL' ) ) {
			$css_url = ATORA_LMS_URL;
		}
		wp_enqueue_style(
			'atora-frontend',
			$css_url . 'assets/css/frontend.css',
			array(),
			defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0'
		);

		$theme_css = $this->get_custom_theme_css();
		if ( $theme_css ) {
			wp_add_inline_style( 'atora-frontend', $theme_css );
		}
	}

	public function add_theme_body_class( $classes ) {
		$theme = $this->get_ui_theme();
		$classes[] = 'atora-theme-' . $theme;
		return $classes;
	}

	protected function get_ui_theme() {
		$settings = class_exists( 'CLMS_Settings' ) ? CLMS_Settings::get_academy_settings() : array();
		$theme    = isset( $settings['ui_theme'] ) ? sanitize_key( (string) $settings['ui_theme'] ) : 'light';
		$allowed  = array( 'light', 'dark', 'vibrant', 'pastel', 'elegant', 'custom' );
		return in_array( $theme, $allowed, true ) ? $theme : 'light';
	}

	protected function get_custom_theme_css() {
		if ( 'custom' !== $this->get_ui_theme() ) {
			return '';
		}

		$settings = class_exists( 'CLMS_Settings' ) ? CLMS_Settings::get_academy_settings() : array();
		$bg      = isset( $settings['ui_color_bg'] ) ? sanitize_hex_color( (string) $settings['ui_color_bg'] ) : '#ffffff';
		$surface = isset( $settings['ui_color_surface'] ) ? sanitize_hex_color( (string) $settings['ui_color_surface'] ) : '#f8fafc';
		$text    = isset( $settings['ui_color_text'] ) ? sanitize_hex_color( (string) $settings['ui_color_text'] ) : '#0f172a';
		$muted   = isset( $settings['ui_color_muted'] ) ? sanitize_hex_color( (string) $settings['ui_color_muted'] ) : '#475569';
		$border  = isset( $settings['ui_color_border'] ) ? sanitize_hex_color( (string) $settings['ui_color_border'] ) : '#e2e8f0';
		$accent  = isset( $settings['ui_color_accent'] ) ? sanitize_hex_color( (string) $settings['ui_color_accent'] ) : '#6366f1';
		$accent_hover = isset( $settings['ui_color_accent_hover'] ) ? sanitize_hex_color( (string) $settings['ui_color_accent_hover'] ) : '#4f46e5';
		$accent_soft  = isset( $settings['ui_color_accent_soft'] ) ? sanitize_hex_color( (string) $settings['ui_color_accent_soft'] ) : '#eef2ff';

		$bg      = $bg ? $bg : '#ffffff';
		$surface = $surface ? $surface : '#f8fafc';
		$text    = $text ? $text : '#0f172a';
		$muted   = $muted ? $muted : '#475569';
		$border  = $border ? $border : '#e2e8f0';
		$accent  = $accent ? $accent : '#6366f1';
		$accent_hover = $accent_hover ? $accent_hover : '#4f46e5';
		$accent_soft  = $accent_soft ? $accent_soft : '#eef2ff';

		$color_scheme = $this->get_color_scheme_from_hex( $bg );

		return sprintf(
			'body.atora-theme-custom{--atora-bg:%1$s;--atora-surface:%2$s;--atora-surface-2:%2$s;--atora-text:%3$s;--atora-text-muted:%4$s;--atora-text-subtle:%4$s;--atora-border:%5$s;--atora-border-strong:%5$s;--atora-accent:%6$s;--atora-accent-hover:%7$s;--atora-accent-soft:%8$s;color-scheme:%9$s;}',
			$bg,
			$surface,
			$text,
			$muted,
			$border,
			$accent,
			$accent_hover,
			$accent_soft,
			$color_scheme
		);
	}

	protected function get_color_scheme_from_hex( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return 'light';
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		$brightness = ( $r * 299 + $g * 587 + $b * 114 ) / 1000;

		return $brightness < 140 ? 'dark' : 'light';
	}

	public function shortcode_logout( $atts ) {
		$atts = shortcode_atts(
			array(
				'label'    => 'Cerrar sesión',
				'redirect' => '',
			),
			(array) $atts,
			'clms_logout'
		);

		if ( ! is_user_logged_in() ) {
			return '';
		}

		$label    = sanitize_text_field( (string) $atts['label'] );
		$redirect = $this->get_logout_redirect_url( $atts['redirect'] );

		return $this->get_logout_markup( $label, $redirect );
	}

	protected function get_logout_markup( $label = 'Cerrar sesión', $redirect = '' ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$label    = sanitize_text_field( (string) $label );
		$redirect = $redirect ? esc_url_raw( $redirect ) : $this->get_logout_redirect_url();
		$url      = wp_logout_url( $redirect );

		ob_start();
		?>
		<div class="clms-logout-wrap">
			<a class="clms-logout-link" href="<?php echo esc_url( $url ); ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		</div>
		<?php
		return ob_get_clean();
	}

	protected function get_logout_redirect_url( $custom = '' ) {
		$custom = is_string( $custom ) ? trim( $custom ) : '';

		if ( '' !== $custom ) {
			return esc_url_raw( $custom );
		}

		return home_url( '/' );
	}

	// -------------------------------------------------------------------------
	// [clms_login_form] / [ac_lms_auth] — Login form
	// -------------------------------------------------------------------------

	/**
	 * Renders a login form. If the user is already logged in shows a
	 * personalised welcome message with a logout link instead.
	 *
	 * Attributes:
	 *   redirect      — URL to go after login (default: current page)
	 *   logged_in_msg — Text to show when already logged in. Use {name} as placeholder.
	 *   dashboard_url — URL for the "go to panel" button shown when already logged in.
	 *   dashboard_label — Label for that button.
	 *
	 * @param array $atts
	 * @return string
	 */
	public function shortcode_login_form( $atts ) {
		$atts = shortcode_atts(
			array(
				'redirect'        => '',
				'logged_in_msg'   => __( 'Hola, {name}. Ya tienes sesión iniciada.', 'atora-lms' ),
				'dashboard_url'   => admin_url( 'admin.php?page=clms-dashboard' ),
				'dashboard_label' => __( 'Ir a mi panel', 'atora-lms' ),
				'logout_label'    => __( 'Cerrar sesión', 'atora-lms' ),
			),
			(array) $atts,
			'clms_login_form'
		);

		if ( is_user_logged_in() ) {
			$user        = wp_get_current_user();
			$name        = $user->display_name ? $user->display_name : $user->user_login;
			$msg         = str_replace( '{name}', esc_html( $name ), sanitize_text_field( (string) $atts['logged_in_msg'] ) );
			$dash_url    = esc_url( $atts['dashboard_url'] );
			$dash_label  = sanitize_text_field( (string) $atts['dashboard_label'] );
			$out_label   = sanitize_text_field( (string) $atts['logout_label'] );
			$logout_url  = wp_logout_url( home_url( '/' ) );

			ob_start();
			?>
			<div class="clms-already-logged-in">
				<p><?php echo esc_html( $msg ); ?></p>
				<p>
					<a class="clms-btn clms-btn-primary" href="<?php echo $dash_url; ?>"><?php echo esc_html( $dash_label ); ?></a>
					&nbsp;
					<a class="clms-btn clms-btn-secondary" href="<?php echo esc_url( $logout_url ); ?>"><?php echo esc_html( $out_label ); ?></a>
				</p>
			</div>
			<?php
			return ob_get_clean();
		}

		$redirect = $atts['redirect'] ? esc_url_raw( $atts['redirect'] ) : ( is_singular() ? get_permalink() : home_url( '/wp-admin/' ) );

		ob_start();
		// Render any WP login error as an accessible alert above the form
		$login_errors = $GLOBALS['wp_login_errors'] ?? null;
		echo '<div class="clms-login-form-wrap">';
		if ( $login_errors instanceof WP_Error && $login_errors->has_errors() ) {
			$raw_msg   = $login_errors->get_error_message();
			$clean_msg = wp_strip_all_tags( (string) $raw_msg );
			$clean_msg = trim( preg_replace( '/\\s+/', ' ', $clean_msg ) );
			if ( '' !== $clean_msg ) {
				echo '<div class="clms-login-error" role="alert" aria-live="assertive">' . esc_html( $clean_msg ) . '</div>';
			}
		}

		wp_login_form(
			array(
				'redirect'       => $redirect,
				'label_username' => __( 'Usuario o correo', 'atora-lms' ),
				'label_password' => __( 'Contraseña', 'atora-lms' ),
				'label_remember' => __( 'Recuérdame', 'atora-lms' ),
				'label_log_in'   => __( 'Ingresar', 'atora-lms' ),
				'remember'       => true,
			)
		);

		// Lost password link
		echo '<p class="clms-lost-password"><a href="' . esc_url( wp_lostpassword_url( $redirect ) ) . '">' . esc_html__( '¿Olvidaste tu contraseña?', 'atora-lms' ) . '</a></p>';

		echo '</div>';

		// Instead of printing a <style> tag (which some editors/page filters may escape
		// and render as plain text), enqueue the CSS via the WP style system so it
		// is properly applied. Use a global flag to avoid adding it multiple times.

		if ( empty( $GLOBALS['clms_login_form_css_added'] ) ) {
			$inline_css = <<<'CSS'
.clms-login-form-wrap{max-width:100%;width:100%;margin:0 auto;padding:18px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;box-sizing:border-box}
@media(min-width:700px){.clms-login-form-wrap{max-width:380px;padding:28px 24px}}
.clms-login-form-wrap p{margin:0 0 16px}
.clms-login-form-wrap label{display:block;font-size:14px;font-weight:600;margin-bottom:4px;color:#111827}
.clms-login-form-wrap input[type=text],.clms-login-form-wrap input[type=password]{width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:15px;box-sizing:border-box;background:#ffffff;color:#111827}
.clms-login-form-wrap input[type=text]::placeholder,.clms-login-form-wrap input[type=password]::placeholder{color:#6b7280}
.clms-login-form-wrap input[type=submit]{width:100%;padding:10px;background:#1d4ed8;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;margin-top:4px}
.clms-login-form-wrap input[type=submit]:hover{background:#1e40af}
.clms-lost-password{text-align:center;font-size:13px;margin-top:12px}
.clms-login-error{padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;font-size:13px;margin-bottom:14px;word-break:break-word}
.clms-already-logged-in{padding:20px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px}
.clms-btn{display:inline-block;padding:9px 18px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none}
.clms-btn-primary{background:#1d4ed8;color:#fff}
.clms-btn-primary:hover{background:#1e40af;color:#fff}
.clms-btn-secondary{background:#e5e7eb;color:#111827}
.clms-btn-secondary:hover{background:#d1d5db;color:#111827}
.clms-login-form-wrap code, .clms-login-form-wrap pre{display:none !important}
CSS;

			// Ensure the front-end stylesheet handle exists and is enqueued so the
			// inline styles are attached reliably. If the handle is not registered
			// (edge cases), register it using the same URL used elsewhere.
			if ( ! wp_style_is( 'atora-frontend', 'registered' ) ) {
				$css_url = defined( 'ATORA_LMS_URL' ) ? ATORA_LMS_URL . 'assets/css/frontend.css' : '';
				wp_register_style( 'atora-frontend', $css_url, array(), defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0' );
			}
			if ( ! wp_style_is( 'atora-frontend', 'enqueued' ) ) {
				wp_enqueue_style( 'atora-frontend' );
			}

			wp_add_inline_style( 'atora-frontend', $inline_css );
			$GLOBALS['clms_login_form_css_added'] = true;
		}

		// Fallback: if the server-rendered login form is stripped (some caches
		// or page builders may remove <input> tags), inject a functional form
		// client-side. We register a tiny inline script so the form appears for
		// users even when inputs are missing from the cached HTML.
		if ( empty( $GLOBALS['clms_login_form_fallback_added'] ) ) {
			$redirect_js    = wp_json_encode( $redirect );
			$action_js      = wp_json_encode( site_url( 'wp-login.php', 'login_post' ) );
			$label_user_js  = wp_json_encode( __( 'Usuario o correo', 'atora-lms' ) );
			$label_pass_js  = wp_json_encode( __( 'Contraseña', 'atora-lms' ) );
			$label_rem_js   = wp_json_encode( __( 'Recuérdame', 'atora-lms' ) );
			$label_log_js   = wp_json_encode( __( 'Ingresar', 'atora-lms' ) );
			$lost_url_js    = wp_json_encode( esc_url( wp_lostpassword_url( $redirect ) ) );

			$fallback_js = "(function(){document.addEventListener('DOMContentLoaded',function(){var wrap=document.querySelector('.clms-login-form-wrap');if(!wrap)return; if(wrap.querySelector('input')) return; var form=document.createElement('form'); form.method='post'; form.action=" . $action_js . "; form.className='clms-login-fallback-form';\n";
			$fallback_js .= "var p1=document.createElement('p'); p1.className='login-username'; var l1=document.createElement('label'); l1.htmlFor='user_login_clms'; l1.textContent=" . $label_user_js . "; p1.appendChild(l1); var i1=document.createElement('input'); i1.type='text'; i1.name='log'; i1.id='user_login_clms'; i1.className='input'; i1.size=20; p1.appendChild(i1); form.appendChild(p1);\n";
			$fallback_js .= "var p2=document.createElement('p'); p2.className='login-password'; var l2=document.createElement('label'); l2.htmlFor='user_pass_clms'; l2.textContent=" . $label_pass_js . "; p2.appendChild(l2); var i2=document.createElement('input'); i2.type='password'; i2.name='pwd'; i2.id='user_pass_clms'; i2.className='input'; i2.size=20; p2.appendChild(i2); form.appendChild(p2);\n";
			$fallback_js .= "var p3=document.createElement('p'); p3.className='login-remember'; var lab=document.createElement('label'); var cb=document.createElement('input'); cb.type='checkbox'; cb.name='rememberme'; cb.id='rememberme_clms'; lab.appendChild(cb); var span=document.createTextNode(' ' + " . $label_rem_js . "); lab.appendChild(span); p3.appendChild(lab); form.appendChild(p3);\n";
			$fallback_js .= "var p4=document.createElement('p'); p4.className='login-submit'; var sub=document.createElement('input'); sub.type='submit'; sub.name='wp-submit'; sub.value=" . $label_log_js . "; sub.id='wp-submit-clms'; sub.className='button button-primary'; p4.appendChild(sub); form.appendChild(p4);\n";
			$fallback_js .= "var hidden=document.createElement('input'); hidden.type='hidden'; hidden.name='redirect_to'; hidden.value=" . $redirect_js . "; form.appendChild(hidden);\n";
			$fallback_js .= "wrap.innerHTML=''; wrap.appendChild(form); var lostp=document.createElement('p'); lostp.className='clms-lost-password'; var a=document.createElement('a'); a.href=" . $lost_url_js . "; a.textContent='¿Olvidaste tu contraseña?'; lostp.appendChild(a); wrap.appendChild(lostp); });})();";

			if ( ! wp_script_is( 'clms-login-fallback', 'registered' ) ) {
				wp_register_script( 'clms-login-fallback', false, array(), null, true );
			}
			if ( ! wp_script_is( 'clms-login-fallback', 'enqueued' ) ) {
				wp_enqueue_script( 'clms-login-fallback' );
			}
			wp_add_inline_script( 'clms-login-fallback', $fallback_js );
			$GLOBALS['clms_login_form_fallback_added'] = true;
		}

		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// [clms_auth_button] — Smart login / logout button
	// -------------------------------------------------------------------------

	/**
	 * Shows a "Login" button to guests and a "Logout" button to logged-in users.
	 *
	 * Attributes:
	 *   login_label    — Label for guests (default: "Ingresar")
	 *   login_url      — Where to send guests (default: wp_login_url)
	 *   logout_label   — Label for logged-in users (default: "Cerrar sesión")
	 *   logout_redirect — Where to go after logout (default: home)
	 *   class          — Extra CSS classes on the <a> tag
	 *
	 * @param array $atts
	 * @return string
	 */
	public function shortcode_auth_button( $atts ) {
		$atts = shortcode_atts(
			array(
				'login_label'      => __( 'Ingresar', 'atora-lms' ),
				'login_url'        => '',
				'logout_label'     => __( 'Cerrar sesión', 'atora-lms' ),
				'logout_redirect'  => '',
				'class'            => 'clms-auth-btn',
			),
			(array) $atts,
			'clms_auth_button'
		);

		$css = sanitize_html_class( $atts['class'] );

		if ( is_user_logged_in() ) {
			$redirect = $atts['logout_redirect'] ? esc_url_raw( $atts['logout_redirect'] ) : home_url( '/' );
			$url      = wp_logout_url( $redirect );
			$label    = sanitize_text_field( (string) $atts['logout_label'] );
		} else {
			$url   = $atts['login_url'] ? esc_url_raw( $atts['login_url'] ) : wp_login_url( get_permalink() );
			$label = sanitize_text_field( (string) $atts['login_label'] );
		}

		return '<a class="' . esc_attr( $css ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	// -------------------------------------------------------------------------
	// Admin access control — block students from wp-admin
	// -------------------------------------------------------------------------

	/**
	 * Redirect students and guests who try to access wp-admin to the frontend.
	 * AJAX requests are always allowed through.
	 */
}
