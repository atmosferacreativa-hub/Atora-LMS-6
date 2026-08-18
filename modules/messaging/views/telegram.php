<?php
/**
 * Mensajería Telegram tab.
 *
 * @package ATORA_LMS\Messaging
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$opts = (array) get_option( 'atora_telegram_options', array() );
$webhook_url = rest_url( 'atora/v1/telegram/webhook' );
?>
<h2><?php esc_html_e( 'Telegram Bot', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Administra token/secreto en Configuración → Canales y valida con pruebas rápidas.', 'atora-lms' ); ?></p>

<table class="widefat striped" style="max-width:780px;">
	<tbody>
		<tr><th style="width:220px;"><?php esc_html_e( 'Bot username', 'atora-lms' ); ?></th><td><?php echo esc_html( (string) ( $opts['bot_username'] ?? '—' ) ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Webhook secret', 'atora-lms' ); ?></th><td><?php echo esc_html( ! empty( $opts['webhook_secret'] ) ? __( 'Configurado', 'atora-lms' ) : __( 'Pendiente', 'atora-lms' ) ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Webhook URL', 'atora-lms' ); ?></th><td><code><?php echo esc_html( $webhook_url ); ?></code></td></tr>
		<tr><th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th><td><?php echo esc_html( ! empty( $opts['bot_token'] ) ? __( 'Listo para pruebas', 'atora-lms' ) : __( 'Falta bot token', 'atora-lms' ) ); ?></td></tr>
	</tbody>
</table>

<p style="margin-top:12px;">
	<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=clms-settings&tab=channels' ) ); ?>"><?php esc_html_e( 'Editar configuración', 'atora-lms' ); ?></a>
</p>

<h3><?php esc_html_e( 'Enviar prueba', 'atora-lms' ); ?></h3>
<form method="post">
	<?php wp_nonce_field( 'atora_messaging_admin_action', 'atora_messaging_nonce' ); ?>
	<input type="hidden" name="atora_messaging_action" value="test_telegram">
	<input type="text" class="regular-text" name="test_chat_id" placeholder="-1001234567890" required>
	<input type="text" class="regular-text" name="test_message" placeholder="<?php esc_attr_e( 'Prueba Telegram ATORA', 'atora-lms' ); ?>">
	<button type="submit" class="button button-primary"><?php esc_html_e( 'Probar bot', 'atora-lms' ); ?></button>
</form>
