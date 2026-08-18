<?php
/**
 * Mensajería WhatsApp tab.
 *
 * @package ATORA_LMS\Messaging
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$opts = (array) get_option( 'atora_whatsapp_options', array() );
$webhook_url = rest_url( 'atora/v1/webhooks/whatsapp' );
?>
<h2><?php esc_html_e( 'WhatsApp Business', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Configura credenciales en Configuración → Canales. Aquí puedes validar conexión operativa.', 'atora-lms' ); ?></p>

<table class="widefat striped" style="max-width:780px;">
	<tbody>
		<tr><th style="width:220px;"><?php esc_html_e( 'Phone Number ID', 'atora-lms' ); ?></th><td><?php echo esc_html( (string) ( $opts['phone_number_id'] ?? '—' ) ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Webhook verify token', 'atora-lms' ); ?></th><td><?php echo esc_html( ! empty( $opts['webhook_verify_token'] ) ? __( 'Configurado', 'atora-lms' ) : __( 'Pendiente', 'atora-lms' ) ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Webhook URL', 'atora-lms' ); ?></th><td><code><?php echo esc_html( $webhook_url ); ?></code></td></tr>
		<tr><th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th><td><?php echo esc_html( ! empty( $opts['access_token'] ) && ! empty( $opts['phone_number_id'] ) ? __( 'Listo para pruebas', 'atora-lms' ) : __( 'Faltan credenciales', 'atora-lms' ) ); ?></td></tr>
	</tbody>
</table>

<p style="margin-top:12px;">
	<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=clms-settings&tab=channels' ) ); ?>"><?php esc_html_e( 'Editar configuración', 'atora-lms' ); ?></a>
</p>

<h3><?php esc_html_e( 'Enviar prueba', 'atora-lms' ); ?></h3>
<form method="post">
	<?php wp_nonce_field( 'atora_messaging_admin_action', 'atora_messaging_nonce' ); ?>
	<input type="hidden" name="atora_messaging_action" value="test_whatsapp">
	<input type="text" class="regular-text" name="test_phone" placeholder="+584121234567" required>
	<input type="text" class="regular-text" name="test_template" placeholder="inactivity_reminder" value="inactivity_reminder">
	<button type="submit" class="button button-primary"><?php esc_html_e( 'Probar conexión', 'atora-lms' ); ?></button>
</form>
