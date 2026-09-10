<?php
/**
 * Vista: ATORA → Google — P10.2 (sprint 6.13.0)
 *
 * Asistente BYO: muestra el redirect URI exacto y los scopes listos
 * para copiar al crear el proyecto en Google Cloud Console, y el botón
 * de conexión una vez que hay credenciales guardadas.
 *
 * @package ATORA_LMS\Google
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

use ATORA\Google\Google_Module;

$opts         = Google_Module::get_options();
$redirect_uri = Google_Module::get_redirect_uri();
$authorize    = Google_Module::get_authorize_url();
$effective_scopes = Google_Module::get_scopes();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Google', 'atora-lms' ); ?></h1>
	<p><?php esc_html_e( 'Cada instalación usa su propio proyecto de Google Cloud. Crea uno (o reusa uno existente), habilita las APIs de Calendar, Meet y Drive (y opcionalmente Classroom), y pega aquí el Client ID/Secret.', 'atora-lms' ); ?></p>

	<?php
	// C2 (6.13.1): perfil institución con hd_domain vacío deja el
	// autoregistro por Google inactivo (aunque users_can_register esté
	// apagado, que es lo habitual en ese perfil) — visible, no silencioso.
	$current_profile = class_exists( '\CLMS_Install_Profiles' ) ? \CLMS_Install_Profiles::current() : '';
	if ( 'institucion' === $current_profile && '' === $opts['hd_domain'] && ! get_option( 'users_can_register' ) ) :
		?>
		<div class="notice notice-error inline">
			<p><?php esc_html_e( 'El autoregistro por Google está inactivo: perfil Institución con "Dominio institucional" vacío y el registro de WordPress desactivado. Configura el dominio abajo para habilitarlo.', 'atora-lms' ); ?></p>
		</div>
	<?php endif; ?>

	<?php
	// C6 (6.13.1): conexiones cifradas bajo una clave que ya no coincide
	// con ATORA_TOKEN_KEY/AUTH_KEY actual (rotación de clave) — antes esto
	// fallaba en silencio, indistinguible de "nunca se conectó".
	$reauth_needed = class_exists( '\ATORA\Calendar\Calendar_Sync' ) ? \ATORA\Calendar\Calendar_Sync::get_connections_needing_reauth() : array();
	if ( $reauth_needed ) :
		?>
		<div class="notice notice-error inline">
			<p>
				<?php echo esc_html( sprintf(
					/* translators: %d: número de conexiones */
					_n(
						'%d conexión de Google necesita reautorización — la clave de cifrado cambió desde que se conectó. Usa el botón "Conectar con Google" de nuevo.',
						'%d conexiones de Google necesitan reautorización — la clave de cifrado cambió desde que se conectaron. Usa el botón "Conectar con Google" de nuevo.',
						count( $reauth_needed ),
						'atora-lms'
					),
					count( $reauth_needed )
				) ); ?>
			</p>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( '1. Configura tu proyecto en Google Cloud Console', 'atora-lms' ); ?></h2>
	<table class="form-table">
		<tr>
			<th><?php esc_html_e( 'Redirect URI autorizado', 'atora-lms' ); ?></th>
			<td><code><?php echo esc_html( $redirect_uri ); ?></code></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Scopes a habilitar', 'atora-lms' ); ?></th>
			<td>
				<code><?php echo esc_html( implode( ' ', $effective_scopes ) ); ?></code>
				<p class="description"><?php esc_html_e( 'drive.file (no drive ni drive.readonly) mantiene la verificación en básica — acceso solo a los archivos que el usuario elige explícitamente por Picker.', 'atora-lms' ); ?></p>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( '2. Credenciales', 'atora-lms' ); ?></h2>
	<form method="post" action="options.php">
		<?php settings_fields( 'atora_google' ); ?>
		<table class="form-table">
			<tr>
				<th><label for="atora_google_client_id"><?php esc_html_e( 'Client ID', 'atora-lms' ); ?></label></th>
				<td><input type="text" id="atora_google_client_id" name="<?php echo esc_attr( Google_Module::OPTION ); ?>[client_id]" value="<?php echo esc_attr( $opts['client_id'] ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th><label for="atora_google_client_secret"><?php esc_html_e( 'Client Secret', 'atora-lms' ); ?></label></th>
				<td><input type="password" id="atora_google_client_secret" name="<?php echo esc_attr( Google_Module::OPTION ); ?>[client_secret]" value="<?php echo esc_attr( $opts['client_secret'] ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th><label for="atora_google_hd_domain"><?php esc_html_e( 'Dominio institucional (opcional)', 'atora-lms' ); ?></label></th>
				<td>
					<input type="text" id="atora_google_hd_domain" name="<?php echo esc_attr( Google_Module::OPTION ); ?>[hd_domain]" value="<?php echo esc_attr( $opts['hd_domain'] ); ?>" class="regular-text" placeholder="miuniversidad.edu">
					<p class="description"><?php esc_html_e( 'Restringe el registro con Google a cuentas de este dominio (Google Workspace).', 'atora-lms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Matrícula automática', 'atora-lms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( Google_Module::OPTION ); ?>[hd_auto_enroll]" value="1" <?php checked( $opts['hd_auto_enroll'] ); ?>>
						<?php esc_html_e( 'Matricular automáticamente a quien se registre con una cuenta del dominio anterior.', 'atora-lms' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Epic 6 — Google Classroom', 'atora-lms' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( Google_Module::OPTION ); ?>[classroom_enabled]" value="1" <?php checked( ! empty( $opts['classroom_enabled'] ) ); ?>>
						<?php esc_html_e( 'Habilitar Google Classroom (agrega scopes a la conexión; requiere reconectar).', 'atora-lms' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Al habilitar Classroom, debes: (1) activar la API de Google Classroom en tu proyecto, y (2) volver a “Conectar con Google” para consentir los nuevos scopes.', 'atora-lms' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Guardar', 'atora-lms' ) ); ?>
	</form>

	<h2><?php esc_html_e( '3. Conectar', 'atora-lms' ); ?></h2>
	<?php if ( $authorize ) : ?>
		<p><a href="<?php echo esc_url( $authorize ); ?>" class="button button-primary"><?php esc_html_e( 'Conectar con Google →', 'atora-lms' ); ?></a></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Guarda el Client ID y Client Secret primero.', 'atora-lms' ); ?></p>
	<?php endif; ?>
</div>
