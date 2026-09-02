<?php
/**
 * Google_Identity — P10.3 (sprint 6.13.0)
 *
 * Registro/login con Google Identity Services (GIS), scopes básicos —
 * no sensibles, verificación básica de la app.
 *
 * Regla de seguridad innegociable: PROHIBIDA la vinculación automática
 * por coincidencia de email. Una cuenta existente + login de Google que
 * fusiona por email = toma de cuenta (si alguien compromete/crea una
 * cuenta de Google con el mismo correo que una cuenta ATORA ajena, no
 * puede entrar a ella). La vinculación se hace SIEMPRE desde el perfil
 * del usuario ya autenticado (rest_link_account(), exige is_user_logged_in()).
 *
 * @package ATORA_LMS\Google
 * @since   6.13.0
 */

namespace ATORA\Google;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Google_Identity {

	/** usermeta: Google `sub` (subject ID) vinculado a esta cuenta WP — fuente de verdad para login, nunca el email. */
	const META_SUB = '_atora_google_sub';

	public static function init(): void {
		add_action( 'login_form', array( __CLASS__, 'render_button' ) );
		add_action( 'register_form', array( __CLASS__, 'render_button' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue_gis_script' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_link_section' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_link_section' ) );
	}

	public static function enqueue_gis_script(): void {
		if ( ! Google_Module::get_client_id() ) {
			return;
		}
		wp_enqueue_script( 'google-identity-services', 'https://accounts.google.com/gsi/client', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	}

	/**
	 * Botón "Iniciar sesión con Google" en wp-login.php (login y registro).
	 * Sin client_id configurado (BYO pendiente), no se muestra nada —
	 * fail-closed, no un botón roto.
	 */
	public static function render_button(): void {
		$client_id = Google_Module::get_client_id();
		if ( ! $client_id ) {
			return;
		}
		?>
		<div id="g_id_onload"
			data-client_id="<?php echo esc_attr( $client_id ); ?>"
			data-callback="atoraHandleGoogleCredential"
			data-auto_prompt="false">
		</div>
		<div class="g_id_signin" data-type="standard" style="margin:12px 0"></div>
		<script>
		function atoraHandleGoogleCredential( response ) {
			fetch( '<?php echo esc_url( rest_url( 'atora/v1/google/verify-identity' ) ); ?>', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { credential: response.credential } )
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					if ( data.redirect ) {
						window.location.href = data.redirect;
					} else if ( data.message ) {
						alert( data.message );
					}
				} );
		}
		</script>
		<?php
	}

	/**
	 * POST /google/verify-identity — sin sesión previa: es el propio login/registro.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function rest_verify_identity( \WP_REST_Request $request ): \WP_REST_Response {
		// C5 (6.13.1): ruta no autenticada que dispara una llamada
		// saliente a Google en cada invocación y puede crear usuarios —
		// mismo patrón que class-2fa-manager.php.
		if ( class_exists( 'ATORA_Rate_Limiter' ) && class_exists( 'ATORA_Client_IP' ) ) {
			$allowed = \ATORA_Rate_Limiter::consume(
				'google_verify_identity',
				\ATORA_Client_IP::get(),
				10,
				300,
				true
			);
			if ( ! $allowed ) {
				return new \WP_REST_Response( array( 'message' => __( 'Demasiados intentos. Espera unos minutos.', 'atora-lms' ) ), 429 );
			}
		}

		$credential = sanitize_text_field( (string) $request->get_param( 'credential' ) );
		$payload    = self::verify_id_token( $credential );

		if ( is_wp_error( $payload ) ) {
			return new \WP_REST_Response( array( 'message' => $payload->get_error_message() ), 400 );
		}

		$sub   = sanitize_text_field( (string) ( $payload['sub'] ?? '' ) );
		$email = sanitize_email( (string) ( $payload['email'] ?? '' ) );

		if ( ! $sub || ! $email || empty( $payload['email_verified'] ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Token de Google inválido.', 'atora-lms' ) ), 400 );
		}

		// 1) ¿Ya hay una cuenta vinculada a este sub? → login directo.
		$linked_users = get_users( array( 'meta_key' => self::META_SUB, 'meta_value' => $sub, 'number' => 1 ) );
		if ( $linked_users ) {
			self::log_in_user( $linked_users[0] );
			return new \WP_REST_Response( array( 'redirect' => admin_url() ), 200 );
		}

		// 2) Existe una cuenta WP con este email pero SIN vincular a
		// ningún sub de Google — regla de seguridad: nunca se fusiona
		// automáticamente. El usuario debe entrar con su contraseña y
		// vincular Google explícitamente desde su perfil.
		if ( email_exists( $email ) ) {
			return new \WP_REST_Response( array(
				'message' => __( 'Ya existe una cuenta con este email. Inicia sesión normalmente y vincula Google desde tu perfil.', 'atora-lms' ),
			), 409 );
		}

		// 3) Ni sub ni email conocidos → registro nuevo.
		return self::register_new_user( $email, $sub, (string) ( $payload['name'] ?? '' ), (string) ( $payload['hd'] ?? '' ) );
	}

	/**
	 * @param string $email
	 * @param string $sub
	 * @param string $name
	 * @param string $hd Dominio hospedado (Google Workspace), '' si es cuenta personal.
	 * @return \WP_REST_Response
	 */
	private static function register_new_user( string $email, string $sub, string $name, string $hd ): \WP_REST_Response {
		$opts = Google_Module::get_options();

		// C2 (6.13.1): el bypass de users_can_register para 'institucion'
		// solo es admisible cuando hay dominio configurado — de lo
		// contrario perfil institución + registro WP desactivado +
		// hd_domain vacío dejaba autoregistrarse a cualquier cuenta de
		// Google del mundo.
		$profile = class_exists( '\CLMS_Install_Profiles' ) ? \CLMS_Install_Profiles::current() : '';
		$hd_gate = 'institucion' === $profile && '' !== (string) $opts['hd_domain'];

		if ( ! get_option( 'users_can_register' ) && ! $hd_gate ) {
			return new \WP_REST_Response( array( 'message' => __( 'El registro está deshabilitado.', 'atora-lms' ) ), 403 );
		}

		// P10.3: restricción por dominio hospedado para 'institucion'.
		if ( $opts['hd_domain'] && $hd !== $opts['hd_domain'] ) {
			return new \WP_REST_Response( array(
				'message' => sprintf(
					/* translators: %s: dominio institucional configurado */
					__( 'Solo se admiten cuentas de Google del dominio %s.', 'atora-lms' ),
					$opts['hd_domain']
				),
			), 403 );
		}

		$login = self::unique_login_from_email( $email );
		$user_id = wp_insert_user( array(
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 32 ),
			'display_name' => $name ?: $login,
			'role'         => 'lms_student',
		) );

		if ( is_wp_error( $user_id ) ) {
			return new \WP_REST_Response( array( 'message' => $user_id->get_error_message() ), 500 );
		}

		update_user_meta( $user_id, self::META_SUB, $sub );

		// C2 (6.13.1): toda alta por esta vía queda en el audit log de P5.
		if ( class_exists( 'CLMS_Audit_Log_Service' ) ) {
			\CLMS_Audit_Log_Service::log( $user_id, 'google_identity_register', 'user', $user_id, array(
				'email' => $email,
				'hd'    => $hd,
			) );
		}

		if ( $opts['hd_domain'] && $opts['hd_auto_enroll'] ) {
			/**
			 * Matrícula automática por dominio (P10.3) — qué curso/programa
			 * corresponde es una decisión de configuración institucional,
			 * no algo que este módulo pueda asumir; se deja como punto de
			 * enganche.
			 *
			 * @param int    $user_id
			 * @param string $hd_domain
			 */
			do_action( 'atora/google/hd_auto_enroll', $user_id, $opts['hd_domain'] );
		}

		self::log_in_user( get_userdata( $user_id ) );

		return new \WP_REST_Response( array( 'redirect' => admin_url() ), 200 );
	}

	/**
	 * POST /google/link — SIEMPRE requiere sesión activa (permission_callback
	 * en Google_Module::register_rest_routes ya exige is_user_logged_in()).
	 * Este es el ÚNICO camino para asociar un sub de Google a una cuenta
	 * ya existente — nunca ocurre desde el flujo de login.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function rest_link_account( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! wp_verify_nonce( (string) $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Nonce inválido.', 'atora-lms' ) ), 403 );
		}

		$payload = self::verify_id_token( sanitize_text_field( (string) $request->get_param( 'credential' ) ) );
		if ( is_wp_error( $payload ) ) {
			return new \WP_REST_Response( array( 'message' => $payload->get_error_message() ), 400 );
		}

		$sub = sanitize_text_field( (string) ( $payload['sub'] ?? '' ) );
		if ( ! $sub ) {
			return new \WP_REST_Response( array( 'message' => __( 'Token de Google inválido.', 'atora-lms' ) ), 400 );
		}

		$already_linked = get_users( array( 'meta_key' => self::META_SUB, 'meta_value' => $sub, 'number' => 1 ) );
		if ( $already_linked && $already_linked[0]->ID !== get_current_user_id() ) {
			return new \WP_REST_Response( array( 'message' => __( 'Esta cuenta de Google ya está vinculada a otro usuario.', 'atora-lms' ) ), 409 );
		}

		update_user_meta( get_current_user_id(), self::META_SUB, $sub );

		return new \WP_REST_Response( array( 'message' => __( 'Cuenta de Google vinculada.', 'atora-lms' ) ), 200 );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function rest_unlink_account( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		delete_user_meta( get_current_user_id(), self::META_SUB );
		return new \WP_REST_Response( array( 'message' => __( 'Cuenta de Google desvinculada.', 'atora-lms' ) ), 200 );
	}

	/**
	 * Sección "Google" en el perfil del usuario — vincular/desvincular
	 * explícito, nunca automático.
	 *
	 * @param \WP_User $user
	 */
	public static function render_profile_link_section( \WP_User $user ): void {
		if ( get_current_user_id() !== $user->ID && ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$sub = get_user_meta( $user->ID, self::META_SUB, true );
		?>
		<h2><?php esc_html_e( 'Google', 'atora-lms' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Cuenta de Google', 'atora-lms' ); ?></th>
				<td>
					<?php if ( $sub ) : ?>
						<p><?php esc_html_e( 'Cuenta vinculada.', 'atora-lms' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'Sin vincular. Usa el botón de Google para asociar tu cuenta desde aquí, nunca se vincula automáticamente por email.', 'atora-lms' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * @param \WP_User $user
	 */
	private static function log_in_user( \WP_User $user ): void {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID );
		do_action( 'wp_login', $user->user_login, $user );
	}

	/**
	 * @param string $email
	 * @return string Login único derivado del email.
	 */
	private static function unique_login_from_email( string $email ): string {
		$base  = sanitize_user( current( explode( '@', $email ) ), true ) ?: 'user';
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . $i;
			$i++;
		}
		return $login;
	}

	/**
	 * Verifica el ID token de Google contra el endpoint tokeninfo — valida
	 * firma y audiencia en un solo paso, sin necesitar una librería JWT
	 * local. Rechaza si aud no coincide con el client_id configurado
	 * (evita aceptar tokens emitidos para OTRA app).
	 *
	 * @param string $credential JWT recibido del cliente.
	 * @return array|\WP_Error Payload decodificado, o WP_Error.
	 */
	private static function verify_id_token( string $credential ) {
		if ( '' === $credential ) {
			return new \WP_Error( 'google_no_credential', __( 'Falta el token de Google.', 'atora-lms' ) );
		}

		$response = wp_remote_get(
			'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode( $credential ),
			array( 'timeout' => 10 )
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'google_verify_failed', __( 'No se pudo verificar el token con Google.', 'atora-lms' ) );
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $payload['sub'] ) ) {
			return new \WP_Error( 'google_invalid_token', __( 'Token de Google inválido o expirado.', 'atora-lms' ) );
		}

		$client_id = Google_Module::get_client_id();
		if ( ! $client_id || ( $payload['aud'] ?? '' ) !== $client_id ) {
			return new \WP_Error( 'google_bad_audience', __( 'El token no corresponde a esta instalación.', 'atora-lms' ) );
		}

		// C5 (6.13.1): verificación explícita de iss/exp — no confiar
		// solo en que tokeninfo los rechace; si el endpoint cambia de
		// comportamiento o hay un proxy/caché de por medio, esto no
		// depende de eso.
		$iss = (string) ( $payload['iss'] ?? '' );
		if ( 'accounts.google.com' !== $iss && 'https://accounts.google.com' !== $iss ) {
			return new \WP_Error( 'google_bad_issuer', __( 'Emisor de token inesperado.', 'atora-lms' ) );
		}

		$exp = (int) ( $payload['exp'] ?? 0 );
		if ( ! $exp || time() > $exp ) {
			return new \WP_Error( 'google_expired_token', __( 'Token de Google expirado.', 'atora-lms' ) );
		}

		return $payload;
	}
}
