<?php
/**
 * Ajustes de clases en vivo (6.39.0): credenciales de Zoom y de Microsoft Teams de la academia.
 *
 * Antes no había ninguna pantalla para las credenciales de Zoom (`atora_live_streaming_options` no se escribía en
 * ningún sitio). Los secretos no se muestran: un campo vacío conserva el valor guardado.
 *
 * @package ATORA_LMS\LiveStreaming
 * @since 6.39.0
 */

namespace ATORA\LiveStreaming;

use ATORA\LiveStreaming\Providers\Provider_Teams;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Live_Settings {

	const OPTION  = 'atora_live_streaming_options';
	const SLUG    = 'atora-live-classes';
	const FIELDS  = array(
		'zoom_account_id'     => false,
		'zoom_client_id'      => false,
		'zoom_client_secret'  => true,
		'zoom_webhook_secret' => true,
		'teams_tenant_id'     => false,
		'teams_client_id'     => false,
		'teams_client_secret' => true,
		'teams_organizer'     => false,
	);

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 30 );
	}

	public static function register_page(): void {
		add_submenu_page( 'clms-dashboard', __( 'Clases en vivo', 'atora-lms' ), __( 'Clases en vivo', 'atora-lms' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Guarda los ajustes. Un secreto vacío conserva el anterior; «borrar» lo elimina.
	 *
	 * @param array<string,mixed> $input
	 */
	public static function save( array $input ): void {
		$current = (array) get_option( self::OPTION, array() );
		foreach ( self::FIELDS as $key => $secret ) {
			$value = trim( (string) ( $input[ $key ] ?? '' ) );
			if ( $secret && '' === $value && empty( $input[ 'clear_' . $key ] ) ) {
				continue;
			}
			$current[ $key ] = sanitize_text_field( $value );
		}
		update_option( self::OPTION, $current, false );
		delete_transient( 'atora_zoom_access_token' );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}
		$notice = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['atora_live_settings_nonce'] ) ) {
			check_admin_referer( 'atora_live_settings', 'atora_live_settings_nonce' );
			$op = sanitize_key( (string) wp_unslash( $_POST['op'] ?? 'save' ) );
			if ( 'save' === $op ) {
				self::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- save() sanea cada campo.
				$notice = __( 'Ajustes guardados.', 'atora-lms' );
			} elseif ( 'test_teams' === $op ) {
				$notice = class_exists( Provider_Teams::class ) && Provider_Teams::access_token()
					? __( 'Teams: credenciales válidas (se obtuvo un token). Falta comprobar la política de acceso al organizador al crear la primera clase.', 'atora-lms' )
					: __( 'Teams: no se pudo obtener un token con esas credenciales.', 'atora-lms' );
			}
		}
		$opts = (array) get_option( self::OPTION, array() );
		$text = static function ( string $key, string $label, string $placeholder = '' ) use ( $opts ): void {
			printf(
				'<p><label for="%1$s"><strong>%2$s</strong></label><br><input id="%1$s" type="text" class="regular-text" name="%1$s" value="%3$s" placeholder="%4$s" autocomplete="off"></p>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( (string) ( $opts[ $key ] ?? '' ) ),
				esc_attr( $placeholder )
			);
		};
		$secret = static function ( string $key, string $label ) use ( $opts ): void {
			$has = '' !== (string) ( $opts[ $key ] ?? '' );
			printf(
				'<p><label for="%1$s"><strong>%2$s</strong></label><br><input id="%1$s" type="password" class="regular-text" name="%1$s" value="" placeholder="%3$s" autocomplete="new-password"> %4$s</p>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $has ? __( 'guardado — escribe para cambiarlo', 'atora-lms' ) : '' ),
				$has ? '<label><input type="checkbox" name="clear_' . esc_attr( $key ) . '" value="1"> ' . esc_html__( 'borrar', 'atora-lms' ) . '</label>' : ''
			);
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Clases en vivo', 'atora-lms' ); ?></h1>
			<p class="description"><?php esc_html_e( 'ATORA se integra con Zoom, Google Meet y Microsoft Teams: crea la reunión, entrega el enlace a los matriculados, registra la asistencia y la grabación. El video lo transmite el proveedor.', 'atora-lms' ); ?></p>
			<?php if ( $notice ) : ?>
				<div class="notice notice-info inline"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( 'atora_live_settings', 'atora_live_settings_nonce' ); ?>
				<h2>Zoom</h2>
				<p class="description"><?php esc_html_e( 'App «Server-to-Server OAuth» del Marketplace de Zoom (scopes de reuniones y grabaciones).', 'atora-lms' ); ?></p>
				<?php
				$text( 'zoom_account_id', __( 'Account ID', 'atora-lms' ) );
				$text( 'zoom_client_id', __( 'Client ID', 'atora-lms' ) );
				$secret( 'zoom_client_secret', __( 'Client secret', 'atora-lms' ) );
				$secret( 'zoom_webhook_secret', __( 'Secret token del webhook', 'atora-lms' ) );
				?>
				<p><?php esc_html_e( 'URL del webhook (eventos: meeting.ended, recording.completed):', 'atora-lms' ); ?> <code><?php echo esc_html( rest_url( 'atora/v1/webhooks/zoom' ) ); ?></code></p>

				<h2>Microsoft Teams</h2>
				<p class="description"><?php esc_html_e( 'Aplicación de Entra ID con permisos de aplicación OnlineMeetings.ReadWrite.All, OnlineMeetingArtifact.Read.All y OnlineMeetingRecording.Read.All, y una application access policy de Teams sobre el organizador.', 'atora-lms' ); ?></p>
				<?php
				$text( 'teams_tenant_id', __( 'ID del inquilino (tenant)', 'atora-lms' ) );
				$text( 'teams_client_id', __( 'ID de la aplicación (client)', 'atora-lms' ) );
				$secret( 'teams_client_secret', __( 'Secreto de la aplicación', 'atora-lms' ) );
				$text( 'teams_organizer', __( 'Organizador (correo o ID del usuario)', 'atora-lms' ), 'clases@tuacademia.edu' );
				?>
				<h2>Google Meet</h2>
				<p class="description"><?php esc_html_e( 'Meet usa la conexión de Google Calendar de cada docente (Integraciones → Google). La grabación de Meet la inicia quien organiza; ATORA guarda el enlace si existe.', 'atora-lms' ); ?></p>
				<p>
					<button type="submit" class="button button-primary" name="op" value="save"><?php esc_html_e( 'Guardar', 'atora-lms' ); ?></button>
					<button type="submit" class="button" name="op" value="test_teams"><?php esc_html_e( 'Probar credenciales de Teams', 'atora-lms' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
