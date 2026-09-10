<?php
/**
 * Microsoft_Identity — Epic 7 (Entra SSO).
 *
 * Reglas de seguridad:
 * - PROHIBIDO vincular automáticamente por coincidencia de email.
 * - El linking se hace solo con sesión iniciada (rest_link_account()).
 *
 * @package ATORA_LMS
 * @since   6.20.0
 */

namespace ATORA\Microsoft;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Microsoft_Identity {

	/** usermeta: Object ID (o id estable) de Microsoft vinculado a esta cuenta WP. */
	const META_OID = '_atora_microsoft_oid';

	/** transient prefix para estados OAuth. */
	const STATE_T_PREFIX = 'atora_ms_state_';

	public static function init(): void {
		add_action( 'login_form', array( __CLASS__, 'render_button' ) );
		add_action( 'register_form', array( __CLASS__, 'render_button' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_link_section' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_link_section' ) );
	}

	public static function render_button(): void {
		if ( ! Microsoft_Module::is_enabled() ) {
			return;
		}

		$start_url = add_query_arg(
			array(
				'redirect' => admin_url(),
			),
			rest_url( 'atora/v1/microsoft/oauth/start' )
		);
		?>
		<p style="margin:12px 0 8px">
			<a class="button button-secondary" href="<?php echo esc_url( $start_url ); ?>" style="width:100%;text-align:center;padding:10px 12px;">
				<?php esc_html_e( 'Iniciar sesión con Microsoft', 'atora-lms' ); ?>
			</a>
		</p>
		<?php
	}

	public static function render_profile_link_section( $user ): void {
		if ( ! $user || ! ( $user instanceof \WP_User ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		if ( ! Microsoft_Module::is_enabled() ) {
			return;
		}

		$oid = (string) get_user_meta( $user->ID, self::META_OID, true );
		?>
		<h2><?php esc_html_e( 'Microsoft Entra', 'atora-lms' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Cuenta vinculada', 'atora-lms' ); ?></th>
				<td>
					<?php if ( '' !== $oid ) : ?>
						<p style="margin:0 0 8px"><strong><?php esc_html_e( 'Sí', 'atora-lms' ); ?></strong></p>
						<form method="post" action="<?php echo esc_url( rest_url( 'atora/v1/microsoft/unlink' ) ); ?>">
							<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
							<button type="button" class="button" onclick="atoraMicrosoftUnlink(this.form)"><?php esc_html_e( 'Desvincular', 'atora-lms' ); ?></button>
						</form>
					<?php else : ?>
						<p style="margin:0 0 8px"><strong><?php esc_html_e( 'No', 'atora-lms' ); ?></strong></p>
						<p class="description"><?php esc_html_e( 'Para vincular tu cuenta, inicia el flujo y autoriza con Microsoft. No se vincula por email automáticamente.', 'atora-lms' ); ?></p>
						<form method="post" action="<?php echo esc_url( rest_url( 'atora/v1/microsoft/link' ) ); ?>">
							<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
							<button type="button" class="button button-primary" onclick="atoraMicrosoftLink(this.form)"><?php esc_html_e( 'Vincular Microsoft', 'atora-lms' ); ?></button>
						</form>
					<?php endif; ?>

					<script>
					function atoraMicrosoftLink(form){
						var nonce = form.querySelector('input[name="nonce"]').value;
						fetch(form.action, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce':nonce}, body: JSON.stringify({})})
							.then(function(r){return r.json();})
							.then(function(data){
								if (data && data.redirect) { window.location.href = data.redirect; return; }
								alert((data && data.message) ? data.message : 'No se pudo iniciar el flujo.');
							});
					}
					function atoraMicrosoftUnlink(form){
						var nonce = form.querySelector('input[name="nonce"]').value;
						fetch(form.action, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce':nonce}, body: JSON.stringify({})})
							.then(function(r){return r.json();})
							.then(function(data){
								if (data && data.ok) { window.location.reload(); return; }
								alert((data && data.message) ? data.message : 'No se pudo desvincular.');
							});
					}
					</script>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * GET /microsoft/oauth/start
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_oauth_start( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Microsoft_Module::is_enabled() ) {
			return new WP_REST_Response( array( 'message' => __( 'Microsoft SSO no está configurado.', 'atora-lms' ) ), 400 );
		}

		$opts   = Microsoft_Module::get_options();
		$tenant = $opts['tenant'] ? $opts['tenant'] : 'common';

		$redirect = (string) $request->get_param( 'redirect' );
		$redirect = $redirect ? esc_url_raw( $redirect ) : admin_url();

		$link = (bool) $request->get_param( 'link' );
		if ( $link && ! is_user_logged_in() ) {
			return new WP_REST_Response( array( 'message' => __( 'Debes iniciar sesión para vincular Microsoft.', 'atora-lms' ) ), 401 );
		}

		$state = wp_generate_password( 24, false, false );
		$payload = array(
			'redirect' => $redirect ?: admin_url(),
			'link'     => $link ? 1 : 0,
			'user_id'  => $link ? get_current_user_id() : 0,
			'created'  => time(),
		);
		set_transient( self::STATE_T_PREFIX . $state, $payload, 10 * MINUTE_IN_SECONDS );

		$params = array(
			'client_id'     => $opts['client_id'],
			'response_type' => 'code',
			'redirect_uri'  => Microsoft_Module::get_redirect_uri(),
			'response_mode' => 'query',
			'scope'         => 'openid profile email User.Read',
			'state'         => $state,
		);

		$auth_url = 'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/authorize?' . http_build_query( $params, '', '&' );

		$resp = new WP_REST_Response( null, 302 );
		$resp->header( 'Location', $auth_url );
		return $resp;
	}

	/**
	 * GET /microsoft/oauth/callback
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_oauth_callback( WP_REST_Request $request ): WP_REST_Response {
		$code  = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$state = sanitize_text_field( (string) $request->get_param( 'state' ) );

		if ( '' === $code || '' === $state ) {
			return new WP_REST_Response( array( 'message' => __( 'Callback inválido.', 'atora-lms' ) ), 400 );
		}

		$state_key = self::STATE_T_PREFIX . $state;
		$payload   = get_transient( $state_key );
		delete_transient( $state_key );

		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Estado expirado. Intenta de nuevo.', 'atora-lms' ) ), 400 );
		}

		$opts = Microsoft_Module::get_options();
		$tenant = $opts['tenant'] ? $opts['tenant'] : 'common';

		$token = self::exchange_code_for_token( $tenant, $code );
		if ( is_wp_error( $token ) ) {
			return new WP_REST_Response( array( 'message' => $token->get_error_message() ), 400 );
		}

		$userinfo = self::fetch_user_info( (string) ( $token['access_token'] ?? '' ) );
		if ( is_wp_error( $userinfo ) ) {
			return new WP_REST_Response( array( 'message' => $userinfo->get_error_message() ), 400 );
		}

		$oid   = sanitize_text_field( (string) ( $userinfo['id'] ?? '' ) );
		$email = sanitize_email( (string) ( $userinfo['mail'] ?? $userinfo['userPrincipalName'] ?? '' ) );

		if ( '' === $oid || ! $email ) {
			return new WP_REST_Response( array( 'message' => __( 'No se pudo obtener el perfil de Microsoft.', 'atora-lms' ) ), 400 );
		}

		$allowed_domain = (string) ( $opts['allowed_domain'] ?? '' );
		if ( '' !== $allowed_domain && ! str_ends_with( strtolower( $email ), '@' . strtolower( $allowed_domain ) ) ) {
			return new WP_REST_Response(
				array(
					'message' => sprintf(
						/* translators: %s: dominio permitido */
						__( 'Solo se admiten cuentas del dominio %s.', 'atora-lms' ),
						$allowed_domain
					),
				),
				403
			);
		}

		$is_link_flow = ! empty( $payload['link'] );
		$target_redirect = esc_url_raw( (string) ( $payload['redirect'] ?? admin_url() ) );

		if ( $is_link_flow ) {
			$user_id = absint( $payload['user_id'] ?? 0 );
			if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
				return new WP_REST_Response( array( 'message' => __( 'Sesión inválida para vincular.', 'atora-lms' ) ), 401 );
			}

			// Si ya está vinculado a otra cuenta, bloquear.
			$linked_users = get_users( array( 'meta_key' => self::META_OID, 'meta_value' => $oid, 'number' => 1 ) );
			if ( $linked_users && absint( $linked_users[0]->ID ) !== $user_id ) {
				return new WP_REST_Response( array( 'message' => __( 'Este Microsoft ya está vinculado a otra cuenta.', 'atora-lms' ) ), 409 );
			}

			update_user_meta( $user_id, self::META_OID, $oid );
			$resp = new WP_REST_Response( null, 302 );
			$resp->header( 'Location', $target_redirect ?: admin_url( 'profile.php' ) );
			return $resp;
		}

		// 1) login directo si sub/oid ya vinculado.
		$linked_users = get_users( array( 'meta_key' => self::META_OID, 'meta_value' => $oid, 'number' => 1 ) );
		if ( $linked_users ) {
			self::log_in_user( $linked_users[0] );
			$resp = new WP_REST_Response( null, 302 );
			$resp->header( 'Location', $target_redirect ?: admin_url() );
			return $resp;
		}

		// 2) Email existe pero sin vincular: NO fusionar (seguridad).
		if ( email_exists( $email ) ) {
			return new WP_REST_Response(
				array(
					'message' => __( 'Ya existe una cuenta con este email. Inicia sesión normalmente y vincula Microsoft desde tu perfil.', 'atora-lms' ),
				),
				409
			);
		}

		// 3) Registro nuevo si el registro WP lo permite.
		if ( ! get_option( 'users_can_register' ) ) {
			return new WP_REST_Response( array( 'message' => __( 'El registro está deshabilitado.', 'atora-lms' ) ), 403 );
		}

		$login = self::unique_login_from_email( $email );
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32 ),
				'display_name' => sanitize_text_field( (string) ( $userinfo['displayName'] ?? $login ) ),
				'role'         => 'lms_student',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return new WP_REST_Response( array( 'message' => $user_id->get_error_message() ), 500 );
		}

		update_user_meta( (int) $user_id, self::META_OID, $oid );
		self::log_in_user( get_userdata( (int) $user_id ) );

		$resp = new WP_REST_Response( null, 302 );
		$resp->header( 'Location', $target_redirect ?: admin_url() );
		return $resp;
	}

	public static function rest_link_account( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		if ( ! Microsoft_Module::is_enabled() ) {
			return new WP_REST_Response( array( 'message' => __( 'Microsoft SSO no está configurado.', 'atora-lms' ) ), 400 );
		}
		return new WP_REST_Response(
			array(
				'redirect' => add_query_arg(
					array(
						'link'     => 1,
						'redirect' => admin_url( 'profile.php' ),
					),
					rest_url( 'atora/v1/microsoft/oauth/start' )
				),
			),
			200
		);
	}

	public static function rest_unlink_account( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_REST_Response( array( 'message' => __( 'Sesión no válida.', 'atora-lms' ) ), 401 );
		}
		delete_user_meta( $user_id, self::META_OID );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private static function exchange_code_for_token( string $tenant, string $code ) {
		$opts = Microsoft_Module::get_options();
		$url  = 'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/token';

		$resp = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $opts['client_id'],
					'client_secret' => $opts['client_secret'],
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => Microsoft_Module::get_redirect_uri(),
					'scope'         => 'openid profile email User.Read',
				),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code_http = (int) wp_remote_retrieve_response_code( $resp );
		$body      = (string) wp_remote_retrieve_body( $resp );
		$json      = json_decode( $body, true );
		$json      = is_array( $json ) ? $json : array();

		if ( $code_http < 200 || $code_http >= 300 ) {
			$msg = isset( $json['error_description'] ) ? (string) $json['error_description'] : ( $body ?: 'token_error' );
			return new WP_Error( 'atora_microsoft_token_error', sanitize_text_field( $msg ) );
		}

		if ( empty( $json['access_token'] ) ) {
			return new WP_Error( 'atora_microsoft_token_error', __( 'Token inválido.', 'atora-lms' ) );
		}

		return $json;
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private static function fetch_user_info( string $access_token ) {
		$access_token = sanitize_text_field( $access_token );
		if ( '' === $access_token ) {
			return new WP_Error( 'atora_microsoft_userinfo_error', __( 'Access token vacío.', 'atora-lms' ) );
		}

		$resp = wp_remote_get(
			'https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code_http = (int) wp_remote_retrieve_response_code( $resp );
		$body      = (string) wp_remote_retrieve_body( $resp );
		$json      = json_decode( $body, true );
		$json      = is_array( $json ) ? $json : array();

		if ( $code_http < 200 || $code_http >= 300 ) {
			$msg = isset( $json['error']['message'] ) ? (string) $json['error']['message'] : ( $body ?: 'userinfo_error' );
			return new WP_Error( 'atora_microsoft_userinfo_error', sanitize_text_field( $msg ) );
		}

		return $json;
	}

	private static function unique_login_from_email( string $email ): string {
		$email = sanitize_email( $email );
		$base  = preg_replace( '/[^a-z0-9_\.]/', '', strtolower( (string) strstr( $email, '@', true ) ) );
		$base  = $base ? $base : 'user';

		$login = $base;
		for ( $i = 0; $i < 20; $i++ ) {
			if ( ! username_exists( $login ) ) {
				return $login;
			}
			$login = $base . '_' . wp_generate_password( 4, false, false );
		}

		return $base . '_' . wp_generate_password( 6, false, false );
	}

	private static function log_in_user( \WP_User $user ): void {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
	}
}
