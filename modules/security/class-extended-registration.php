<?php
/**
 * ATORA LMS v5 — Registro extendido
 *
 * Añade campos obligatorios y opcionales al formulario de registro de WordPress:
 * país, ciudad, estado, sexo, edad, teléfono, WhatsApp, zona horaria, idioma y consentimientos GDPR.
 *
 * @package ATORA_LMS\Security
 * @since   5.0.0
 */

namespace ATORA\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Extended_Registration
 *
 * @since 5.0.0
 */
class Extended_Registration {

	/**
	 * Registra todos los hooks necesarios.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Añadir campos al formulario nativo de WP.
		add_action( 'register_form',                array( __CLASS__, 'render_fields' ) );
		add_filter( 'registration_errors',          array( __CLASS__, 'validate_fields' ), 10, 3 );
		add_action( 'user_register',                array( __CLASS__, 'save_fields' ) );

		// Perfil de usuario en admin.
		add_action( 'show_user_profile',            array( __CLASS__, 'render_profile_fields' ) );
		add_action( 'edit_user_profile',            array( __CLASS__, 'render_profile_fields' ) );
		add_action( 'personal_options_update',      array( __CLASS__, 'save_profile_fields' ) );
		add_action( 'edit_user_profile_update',     array( __CLASS__, 'save_profile_fields' ) );

		// AJAX: autocompletar ciudad.
		add_action( 'wp_ajax_atora_city_suggest',        array( __CLASS__, 'ajax_city_suggest' ) );
		add_action( 'wp_ajax_nopriv_atora_city_suggest', array( __CLASS__, 'ajax_city_suggest' ) );

		// Encolar assets solo en páginas de registro/perfil.
		add_action( 'login_enqueue_scripts',  array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_scripts',     array( __CLASS__, 'maybe_enqueue_profile_assets' ) );

		// Permite que los enlaces de "configura tu contraseña" (enviados al
		// crear cuentas automáticamente desde una compra) muestren una página
		// propia para definir la contraseña, inicien sesión automáticamente y
		// redirijan al curso recién comprado. Se maneja en 'init' para evitar
		// depender de wp-login.php (cachés/seguridad pueden interferir con él).
		add_action( 'init', array( __CLASS__, 'maybe_handle_set_password' ) );
	}

	/**
	 * Maneja el enlace propio de "configura tu contraseña" enviado por email
	 * tras una compra que creó la cuenta automáticamente. Evita por completo
	 * wp-login.php para no depender de cómo cada hosting/cache/seguridad lo
	 * trate.
	 *
	 * URL esperada: home_url('/') con los parámetros:
	 *   clms_set_password=1&login=...&key=...&redirect_to=...
	 *
	 * @return void
	 */
	public static function maybe_handle_set_password(): void {
		if ( empty( $_GET['clms_set_password'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$login = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key   = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect_to = is_string( $redirect_to ) && '' !== $redirect_to
			? wp_validate_redirect( $redirect_to, home_url( '/' ) )
			: home_url( '/' );

		nocache_headers();

		$user = check_password_reset_key( $key, $login );

		$is_submit = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD']
			&& isset( $_POST['clms_set_password_submit'] );

		if ( $is_submit ) {
			if ( is_wp_error( $user ) ) {
				self::render_set_password_page( $login, $key, $redirect_to, __( 'Este enlace para configurar tu contraseña ya no es válido o expiró. Solicita uno nuevo desde "¿Olvidaste tu contraseña?".', 'atora-lms' ) );
				exit;
			}

			$pass1 = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '';
			$pass2 = isset( $_POST['pass2'] ) ? (string) wp_unslash( $_POST['pass2'] ) : '';

			if ( '' === $pass1 || $pass1 !== $pass2 ) {
				self::render_set_password_page( $login, $key, $redirect_to, __( 'Las contraseñas no coinciden o están vacías. Intenta de nuevo.', 'atora-lms' ) );
				exit;
			}

			if ( strlen( $pass1 ) < 6 ) {
				self::render_set_password_page( $login, $key, $redirect_to, __( 'La contraseña debe tener al menos 6 caracteres.', 'atora-lms' ) );
				exit;
			}

			reset_password( $user, $pass1 );

			wp_clear_auth_cookie();
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID );
			do_action( 'wp_login', $user->user_login, $user );

			wp_safe_redirect( $redirect_to );
			exit;
		}

		if ( is_wp_error( $user ) ) {
			self::render_set_password_page( $login, $key, $redirect_to, __( 'Este enlace para configurar tu contraseña ya no es válido o expiró. Solicita uno nuevo desde "¿Olvidaste tu contraseña?".', 'atora-lms' ) );
			exit;
		}

		self::render_set_password_page( $login, $key, $redirect_to );
		exit;
	}

	/**
	 * Renderiza una página propia (independiente del theme) para que el
	 * usuario defina su contraseña tras una compra.
	 *
	 * @param string $login       Login del usuario (para reenviar en el formulario).
	 * @param string $key         Llave de restablecimiento (para reenviar en el formulario).
	 * @param string $redirect_to URL a la que ir tras configurar la contraseña.
	 * @param string $error       Mensaje de error, si corresponde.
	 * @return void
	 */
	protected static function render_set_password_page( string $login, string $key, string $redirect_to, string $error = '' ): void {
		$action_url = isset( $_SERVER['REQUEST_URI'] ) ? esc_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : esc_url( home_url( '/' ) );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html__( 'Configura tu contraseña', 'atora-lms' ); ?> &mdash; <?php bloginfo( 'name' ); ?></title>
	<style>
		body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f4f5f7; margin: 0; padding: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
		.clms-card { background: #fff; max-width: 420px; width: 100%; margin: 24px; padding: 32px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,.08); box-sizing: border-box; }
		.clms-card h1 { font-size: 1.25rem; margin: 0 0 16px; }
		.clms-card label { display: block; font-weight: 600; margin-bottom: 6px; font-size: .9rem; }
		.clms-card input[type="password"] { width: 100%; padding: 10px 12px; margin-bottom: 16px; border: 1px solid #ccd0d4; border-radius: 4px; font-size: 1rem; box-sizing: border-box; }
		.clms-card button { width: 100%; padding: 12px; background: #2271b1; color: #fff; border: none; border-radius: 4px; font-size: 1rem; cursor: pointer; }
		.clms-card button:hover { background: #135e96; }
		.clms-error { background: #fcf0f1; border-left: 4px solid #d63638; padding: 10px 12px; margin-bottom: 16px; font-size: .9rem; }
		.clms-error a { color: #2271b1; }
		.clms-hint { font-size: .8rem; color: #646970; margin-top: -8px; margin-bottom: 16px; }
	</style>
</head>
<body>
	<div class="clms-card">
		<h1><?php echo esc_html__( 'Configura tu contraseña', 'atora-lms' ); ?></h1>
		<?php if ( $error ) : ?>
			<div class="clms-error">
				<?php echo esc_html( $error ); ?>
				<br>
				<a href="<?php echo esc_url( wp_lostpassword_url( $redirect_to ) ); ?>"><?php echo esc_html__( '¿Olvidaste tu contraseña?', 'atora-lms' ); ?></a>
			</div>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %s: nombre de usuario */
					esc_html__( 'Hola %s, define una contraseña para tu cuenta y entra directo a tu curso.', 'atora-lms' ),
					'<strong>' . esc_html( $login ) . '</strong>'
				);
				?>
			</p>
			<form method="post" action="<?php echo $action_url; ?>" autocomplete="off">
				<label for="clms-pass1"><?php echo esc_html__( 'Nueva contraseña', 'atora-lms' ); ?></label>
				<input type="password" name="pass1" id="clms-pass1" autocomplete="new-password" required>

				<label for="clms-pass2"><?php echo esc_html__( 'Confirmar contraseña', 'atora-lms' ); ?></label>
				<input type="password" name="pass2" id="clms-pass2" autocomplete="new-password" required>

				<p class="clms-hint"><?php echo esc_html__( 'Mínimo 6 caracteres.', 'atora-lms' ); ?></p>

				<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>">
				<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
				<input type="hidden" name="clms_set_password_submit" value="1">

				<button type="submit"><?php echo esc_html__( 'Guardar y entrar al curso', 'atora-lms' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
</body>
</html>
		<?php
	}

	// ── Renderizado ───────────────────────────────────────────────────────────

	/**
	 * Renderiza los campos extendidos en el formulario de registro nativo de WP.
	 *
	 * @return void
	 */
	public static function render_fields(): void {
		$view = ATORA_LMS_MODULES_DIR . 'security/views/registration-fields.php';
		if ( file_exists( $view ) ) {
			require $view;
		}
	}

	/**
	 * Renderiza los campos extendidos en la página de perfil de usuario.
	 *
	 * @param \WP_User $user Usuario actual.
	 * @return void
	 */
	public static function render_profile_fields( \WP_User $user ): void {
		$view = ATORA_LMS_MODULES_DIR . 'security/views/profile-fields.php';
		if ( file_exists( $view ) ) {
			require $view;
		}
	}

	// ── Validación ────────────────────────────────────────────────────────────

	/**
	 * Valida los campos extendidos durante el registro.
	 *
	 * @param \WP_Error $errors   Errores acumulados.
	 * @param string    $sanitized_user_login Login sanitizado.
	 * @param string    $user_email           Email.
	 * @return \WP_Error
	 */
	public static function validate_fields( \WP_Error $errors, string $sanitized_user_login, string $user_email ): \WP_Error {
		// País obligatorio.
		if ( empty( $_POST['atora_country_code'] ) ) {
			$errors->add( 'atora_country_required', __( 'Por favor selecciona tu país.', 'atora-lms' ) );
		} else {
			$countries = self::get_countries();
			$code      = strtoupper( sanitize_text_field( wp_unslash( $_POST['atora_country_code'] ) ) );
			if ( ! array_key_exists( $code, $countries ) ) {
				$errors->add( 'atora_country_invalid', __( 'País no válido.', 'atora-lms' ) );
			}
		}

		// Sexo opcional, si viene debe estar en la lista permitida.
		if ( isset( $_POST['atora_sex'] ) ) {
			$sex = self::sanitize_sex( wp_unslash( (string) $_POST['atora_sex'] ) );
			$raw = sanitize_key( wp_unslash( (string) $_POST['atora_sex'] ) );
			if ( '' !== $raw && '' === $sex ) {
				$errors->add( 'atora_sex_invalid', __( 'Selecciona un valor válido para sexo.', 'atora-lms' ) );
			}
		}

		// Edad opcional, si viene debe estar entre 1 y 120.
		if ( isset( $_POST['atora_age'] ) ) {
			$age_raw = wp_unslash( (string) $_POST['atora_age'] );
			$age     = self::sanitize_age( $age_raw );
			if ( '' !== trim( (string) $age_raw ) && '' === $age ) {
				$errors->add( 'atora_age_invalid', __( 'La edad debe estar entre 1 y 120.', 'atora-lms' ) );
			}
		}

		// Teléfono: obligatorio, formato internacional.
		if ( empty( $_POST['atora_phone'] ) ) {
			$errors->add( 'atora_phone_required', __( 'El número de teléfono es obligatorio.', 'atora-lms' ) );
		} elseif ( ! self::is_valid_phone( sanitize_text_field( wp_unslash( $_POST['atora_phone'] ) ) ) ) {
			$errors->add( 'atora_phone_invalid', __( 'Número de teléfono no válido. Usa formato internacional: +52 555 123 4567', 'atora-lms' ) );
		}

		// Consentimiento de Términos.
		if ( empty( $_POST['atora_consent_terms'] ) ) {
			$errors->add( 'atora_terms_required', __( 'Debes aceptar los Términos y Condiciones.', 'atora-lms' ) );
		}

		// Consentimiento de Privacidad.
		if ( empty( $_POST['atora_consent_privacy'] ) ) {
			$errors->add( 'atora_privacy_required', __( 'Debes aceptar la Política de Privacidad.', 'atora-lms' ) );
		}

		// Emails académicos: obligatorio.
		if ( empty( $_POST['atora_consent_academic_emails'] ) ) {
			$errors->add( 'atora_academic_email_required', __( 'Debes aceptar recibir emails académicos del curso.', 'atora-lms' ) );
		}

		return $errors;
	}

	// ── Guardar ───────────────────────────────────────────────────────────────

	/**
	 * Guarda los campos extendidos después de un registro exitoso.
	 *
	 * @param int $user_id ID del usuario recién creado.
	 * @return void
	 */
	public static function save_fields( int $user_id ): void {
		$timestamp = current_time( 'mysql', true ); // UTC.
		$ip        = self::get_client_ip();

		// Campos de perfil.
		$fields = array(
			'atora_country_code' => 'strtoupper',
			'atora_city'         => 'sanitize_text_field',
			'atora_state'        => 'sanitize_text_field',
			'atora_sex'          => array( __CLASS__, 'sanitize_sex' ),
			'atora_age'          => array( __CLASS__, 'sanitize_age' ),
			'atora_phone'        => array( __CLASS__, 'sanitize_phone_display' ),
			'atora_whatsapp'     => array( __CLASS__, 'sanitize_phone_display' ),
			'atora_telegram'     => 'sanitize_text_field',
			'atora_timezone'     => 'sanitize_text_field',
			'atora_language'     => 'sanitize_text_field',
		);

		foreach ( $fields as $key => $sanitizer ) {
			if ( ! empty( $_POST[ $key ] ) ) {
				$value = call_user_func( $sanitizer, wp_unslash( $_POST[ $key ] ) );
				update_user_meta( $user_id, $key, $value );
			}
		}

		update_user_meta( $user_id, 'atora_phone_verified', 0 );

		// Consentimientos GDPR — guardamos timestamp + IP.
		$consents = array(
			'atora_consent_terms'           => 'terms',
			'atora_consent_privacy'         => 'privacy',
			'atora_consent_academic_emails' => 'academic_emails',
			'atora_consent_marketing'       => 'marketing',
			'atora_consent_whatsapp'        => 'whatsapp',
			'atora_consent_telegram'        => 'telegram',
		);

		$consent_log = array();
		foreach ( $consents as $post_key => $consent_key ) {
			$given = ! empty( $_POST[ $post_key ] );
			update_user_meta( $user_id, $post_key, $given ? 1 : 0 );
			$consent_log[ $consent_key ] = array(
				'value'      => $given,
				'timestamp'  => $timestamp,
				'ip_address' => $ip,
			);
		}

		update_user_meta( $user_id, 'atora_consent_log', $consent_log );

		/**
		 * Acción disparada tras guardar los campos extendidos de registro.
		 *
		 * @param int   $user_id     ID del usuario.
		 * @param array $consent_log Log de consentimientos.
		 */
		do_action( 'atora/security/registration_saved', $user_id, $consent_log );
	}

	/**
	 * Guarda los campos extendidos desde la página de perfil.
	 *
	 * @param int $user_id ID del usuario.
	 * @return void
	 */
	public static function save_profile_fields( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		check_admin_referer( 'update-user_' . $user_id );

		$fields = array(
			'atora_country_code' => 'sanitize_text_field',
			'atora_city'         => 'sanitize_text_field',
			'atora_state'        => 'sanitize_text_field',
			'atora_sex'          => array( __CLASS__, 'sanitize_sex' ),
			'atora_age'          => array( __CLASS__, 'sanitize_age' ),
			'atora_phone'        => array( __CLASS__, 'sanitize_phone_display' ),
			'atora_whatsapp'     => array( __CLASS__, 'sanitize_phone_display' ),
			'atora_telegram'     => 'sanitize_text_field',
			'atora_timezone'     => 'sanitize_text_field',
			'atora_language'     => 'sanitize_text_field',
		);

		foreach ( $fields as $key => $sanitizer ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_user_meta( $user_id, $key, call_user_func( $sanitizer, wp_unslash( $_POST[ $key ] ) ) );
			}
		}
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────

	/**
	 * Sugiere ciudades usando la API de Nominatim (OpenStreetMap, gratis).
	 *
	 * @return void
	 */
	public static function ajax_city_suggest(): void {
		check_ajax_referer( 'atora_city_suggest', 'nonce' );

		$query   = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$country = sanitize_text_field( wp_unslash( $_GET['country'] ?? '' ) );

		if ( strlen( $query ) < 2 ) {
			wp_send_json_success( array() );
		}

		$cache_key = 'atora_city_' . md5( $query . $country );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			wp_send_json_success( $cached );
		}

		$url = add_query_arg(
			array(
				'q'              => rawurlencode( $query ),
				'format'         => 'json',
				'addressdetails' => 1,
				'limit'          => 8,
				'featureCode'    => 'PPLC,PPL',
				'countrycodes'   => strtolower( $country ),
			),
			'https://nominatim.openstreetmap.org/search'
		);

		$response = wp_remote_get( $url, array(
			'timeout'    => 5,
			'user-agent' => 'ATORA LMS/' . ATORA_LMS_VERSION . ' (contact@atoralms.com)',
		) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_success( array() );
		}

		$body    = wp_remote_retrieve_body( $response );
		$data    = json_decode( $body, true );
		$results = array();

		if ( is_array( $data ) ) {
			foreach ( $data as $place ) {
				$city = $place['address']['city']
					?? $place['address']['town']
					?? $place['address']['village']
					?? $place['display_name'];

				$results[] = array(
					'label' => sanitize_text_field( $city ),
					'value' => sanitize_text_field( $city ),
				);
			}
		}

		set_transient( $cache_key, $results, HOUR_IN_SECONDS );
		wp_send_json_success( $results );
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	/**
	 * Encola scripts y estilos en la página de login/registro.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		wp_enqueue_script(
			'atora-registration',
			ATORA_LMS_MODULES_URL . 'security/assets/registration.js',
			array( 'jquery' ),
			ATORA_LMS_VERSION,
			true
		);

		wp_localize_script( 'atora-registration', 'atoraReg', array(
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'atora_city_suggest' ),
			'i18n'       => array(
				'select_country' => __( 'Selecciona un país primero', 'atora-lms' ),
				'searching'      => __( 'Buscando…', 'atora-lms' ),
				'no_results'     => __( 'Sin resultados', 'atora-lms' ),
			),
		) );

		wp_enqueue_style(
			'atora-registration',
			ATORA_LMS_MODULES_URL . 'security/assets/registration.css',
			array(),
			ATORA_LMS_VERSION
		);
	}

	/**
	 * Encola assets del perfil solo en la página de perfil del frontend.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_profile_assets(): void {
		$is_woo_account_page = function_exists( 'is_account_page' ) && \is_account_page();

		if ( $is_woo_account_page || is_page( 'perfil' ) || is_page( 'profile' ) ) {
			self::enqueue_assets();
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Valida un número de teléfono en formato internacional.
	 *
	 * @param string $phone Número a validar.
	 * @return bool
	 */
	public static function is_valid_phone( string $phone ): bool {
		// Eliminar espacios, guiones y paréntesis para validar.
		$cleaned = preg_replace( '/[\s\-\(\)\.]+/', '', $phone );

		// Debe comenzar con + seguido de 7-15 dígitos (ITU-T E.164 aproximado).
		return (bool) preg_match( '/^\+\d{7,15}$/', $cleaned );
	}

	/**
	 * Sanitiza teléfonos preservando el formato legible que el usuario ingresó.
	 *
	 * @param string $value Valor recibido.
	 * @return string
	 */
	private static function sanitize_phone_display( string $value ): string {
		$value = sanitize_text_field( $value );
		$value = preg_replace( '/[^\d\+\s\-\(\)\.]/', '', $value );
		$value = preg_replace( '/\s+/', ' ', (string) $value );

		return trim( (string) $value );
	}

	/**
	 * Sanitiza sexo con un catálogo controlado.
	 *
	 * @param string $value Valor recibido.
	 * @return string
	 */
	private static function sanitize_sex( string $value ): string {
		$value   = sanitize_key( $value );
		$allowed = array( 'femenino', 'masculino', 'no_binario', 'prefiero_no_decir' );

		return in_array( $value, $allowed, true ) ? $value : '';
	}

	/**
	 * Sanitiza edad (1-120). Devuelve string para persistencia uniforme.
	 *
	 * @param string $value Valor recibido.
	 * @return string
	 */
	private static function sanitize_age( string $value ): string {
		$age = absint( $value );
		if ( $age < 1 || $age > 120 ) {
			return '';
		}

		return (string) $age;
	}

	/**
	 * Obtiene la IP del cliente de forma segura.
	 *
	 * @return string
	 */
	private static function get_client_ip(): string {
		// PT-1 (6.5.5): antes confiaba en X-Forwarded-For/Client-IP sin
		// verificar que la petición viniera realmente de un proxy
		// confiable — evadible falsificando la cabecera. Delega en el
		// resolutor centralizado (REMOTE_ADDR como fuente de verdad).
		return \ATORA_Client_IP::get();
	}

	/**
	 * Devuelve la lista de países (código ISO 3166-1 alpha-2 → nombre).
	 *
	 * @return array<string,string>
	 */
	public static function get_countries(): array {
		return array(
			'AD' => 'Andorra',             'AE' => 'Emiratos Árabes Unidos',
			'AF' => 'Afganistán',          'AG' => 'Antigua y Barbuda',
			'AL' => 'Albania',             'AM' => 'Armenia',
			'AO' => 'Angola',              'AR' => 'Argentina',
			'AT' => 'Austria',             'AU' => 'Australia',
			'AZ' => 'Azerbaiyán',         'BA' => 'Bosnia y Herzegovina',
			'BB' => 'Barbados',            'BD' => 'Bangladés',
			'BE' => 'Bélgica',            'BF' => 'Burkina Faso',
			'BG' => 'Bulgaria',            'BH' => 'Baréin',
			'BI' => 'Burundi',             'BJ' => 'Benín',
			'BN' => 'Brunéi',             'BO' => 'Bolivia',
			'BR' => 'Brasil',              'BS' => 'Bahamas',
			'BT' => 'Bután',              'BW' => 'Botsuana',
			'BY' => 'Bielorrusia',         'BZ' => 'Belice',
			'CA' => 'Canadá',             'CD' => 'Congo (Rep. Dem.)',
			'CF' => 'República Centroafricana', 'CG' => 'Congo',
			'CH' => 'Suiza',              'CI' => 'Costa de Marfil',
			'CL' => 'Chile',               'CM' => 'Camerún',
			'CN' => 'China',               'CO' => 'Colombia',
			'CR' => 'Costa Rica',          'CU' => 'Cuba',
			'CV' => 'Cabo Verde',          'CY' => 'Chipre',
			'CZ' => 'República Checa',     'DE' => 'Alemania',
			'DJ' => 'Yibuti',             'DK' => 'Dinamarca',
			'DM' => 'Dominica',            'DO' => 'República Dominicana',
			'DZ' => 'Argelia',             'EC' => 'Ecuador',
			'EE' => 'Estonia',             'EG' => 'Egipto',
			'ER' => 'Eritrea',             'ES' => 'España',
			'ET' => 'Etiopía',            'FI' => 'Finlandia',
			'FJ' => 'Fiyi',               'FM' => 'Micronesia',
			'FR' => 'Francia',             'GA' => 'Gabón',
			'GB' => 'Reino Unido',         'GD' => 'Granada',
			'GE' => 'Georgia',             'GH' => 'Ghana',
			'GM' => 'Gambia',              'GN' => 'Guinea',
			'GQ' => 'Guinea Ecuatorial',   'GR' => 'Grecia',
			'GT' => 'Guatemala',           'GW' => 'Guinea-Bisáu',
			'GY' => 'Guyana',              'HN' => 'Honduras',
			'HR' => 'Croacia',             'HT' => 'Haití',
			'HU' => 'Hungría',            'ID' => 'Indonesia',
			'IE' => 'Irlanda',             'IL' => 'Israel',
			'IN' => 'India',               'IQ' => 'Iraq',
			'IR' => 'Irán',               'IS' => 'Islandia',
			'IT' => 'Italia',              'JM' => 'Jamaica',
			'JO' => 'Jordania',            'JP' => 'Japón',
			'KE' => 'Kenia',              'KG' => 'Kirguistán',
			'KH' => 'Camboya',             'KI' => 'Kiribati',
			'KM' => 'Comoras',             'KN' => 'San Cristóbal y Nieves',
			'KP' => 'Corea del Norte',     'KR' => 'Corea del Sur',
			'KW' => 'Kuwait',              'KZ' => 'Kazajistán',
			'LA' => 'Laos',               'LB' => 'Líbano',
			'LC' => 'Santa Lucía',        'LI' => 'Liechtenstein',
			'LK' => 'Sri Lanka',           'LR' => 'Liberia',
			'LS' => 'Lesoto',             'LT' => 'Lituania',
			'LU' => 'Luxemburgo',          'LV' => 'Letonia',
			'LY' => 'Libia',              'MA' => 'Marruecos',
			'MC' => 'Mónaco',             'MD' => 'Moldavia',
			'ME' => 'Montenegro',          'MG' => 'Madagascar',
			'MH' => 'Islas Marshall',      'MK' => 'Macedonia del Norte',
			'ML' => 'Malí',               'MM' => 'Myanmar',
			'MN' => 'Mongolia',            'MR' => 'Mauritania',
			'MT' => 'Malta',               'MU' => 'Mauricio',
			'MV' => 'Maldivas',            'MW' => 'Malaui',
			'MX' => 'México',             'MY' => 'Malasia',
			'MZ' => 'Mozambique',          'NA' => 'Namibia',
			'NE' => 'Níger',              'NG' => 'Nigeria',
			'NI' => 'Nicaragua',           'NL' => 'Países Bajos',
			'NO' => 'Noruega',             'NP' => 'Nepal',
			'NR' => 'Nauru',               'NZ' => 'Nueva Zelanda',
			'OM' => 'Omán',               'PA' => 'Panamá',
			'PE' => 'Perú',               'PG' => 'Papúa Nueva Guinea',
			'PH' => 'Filipinas',           'PK' => 'Pakistán',
			'PL' => 'Polonia',             'PT' => 'Portugal',
			'PW' => 'Palaos',             'PY' => 'Paraguay',
			'QA' => 'Catar',              'RO' => 'Rumanía',
			'RS' => 'Serbia',              'RU' => 'Rusia',
			'RW' => 'Ruanda',             'SA' => 'Arabia Saudita',
			'SB' => 'Islas Salomón',       'SC' => 'Seychelles',
			'SD' => 'Sudán',              'SE' => 'Suecia',
			'SG' => 'Singapur',            'SI' => 'Eslovenia',
			'SK' => 'Eslovaquia',          'SL' => 'Sierra Leona',
			'SM' => 'San Marino',          'SN' => 'Senegal',
			'SO' => 'Somalia',             'SR' => 'Surinam',
			'SS' => 'Sudán del Sur',       'ST' => 'Santo Tomé y Príncipe',
			'SV' => 'El Salvador',         'SY' => 'Siria',
			'SZ' => 'Suazilandia',         'TD' => 'Chad',
			'TG' => 'Togo',               'TH' => 'Tailandia',
			'TJ' => 'Tayikistán',         'TL' => 'Timor Oriental',
			'TM' => 'Turkmenistán',       'TN' => 'Túnez',
			'TO' => 'Tonga',              'TR' => 'Turquía',
			'TT' => 'Trinidad y Tobago',   'TV' => 'Tuvalu',
			'TZ' => 'Tanzania',            'UA' => 'Ucrania',
			'UG' => 'Uganda',              'US' => 'Estados Unidos',
			'UY' => 'Uruguay',             'UZ' => 'Uzbekistán',
			'VA' => 'Ciudad del Vaticano', 'VC' => 'San Vicente y las Granadinas',
			'VE' => 'Venezuela',           'VN' => 'Vietnam',
			'VU' => 'Vanuatu',             'WS' => 'Samoa',
			'YE' => 'Yemen',              'ZA' => 'Sudáfrica',
			'ZM' => 'Zambia',              'ZW' => 'Zimbabue',
		);
	}

	/**
	 * Devuelve la lista de zonas horarias compatibles con PHP.
	 *
	 * @return array<string,string>
	 */
	public static function get_timezones(): array {
		$identifiers = \DateTimeZone::listIdentifiers();
		$list        = array();
		foreach ( $identifiers as $tz ) {
			$list[ $tz ] = str_replace( '_', ' ', $tz );
		}
		return $list;
	}
}
