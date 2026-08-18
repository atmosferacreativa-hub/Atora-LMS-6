<?php
/**
 * Tab: SMTP / Proveedor.
 *
 * @package ATORA_LMS\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$provider = sanitize_key( (string) ( $settings['provider'] ?? 'smtp' ) );
?>
<h2><?php esc_html_e( 'SMTP / Proveedor', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'La configuración avanzada vive en Configuración de Atora → Canales para mantener un único owner.', 'atora-lms' ); ?></p>

<table class="widefat striped" style="max-width:720px;">
	<tbody>
		<tr>
			<th style="width:180px;"><?php esc_html_e( 'Proveedor activo', 'atora-lms' ); ?></th>
			<td><?php echo esc_html( strtoupper( $provider ) ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'SMTP Host', 'atora-lms' ); ?></th>
			<td><?php echo esc_html( (string) ( $settings['smtp_host'] ?? '—' ) ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'SMTP Puerto', 'atora-lms' ); ?></th>
			<td><?php echo esc_html( (string) absint( $settings['smtp_port'] ?? 0 ) ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'SMTP Seguridad', 'atora-lms' ); ?></th>
			<td><?php echo esc_html( (string) ( $settings['smtp_encryption'] ?? '—' ) ); ?></td>
		</tr>
	</tbody>
</table>

<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
	<form method="post" style="display:inline-block;margin:0;">
		<?php wp_nonce_field( 'atora_email_admin_action', 'atora_email_nonce' ); ?>
		<input type="hidden" name="atora_email_action" value="test_provider_connection">
		<button type="submit" class="button"><?php esc_html_e( 'Probar conexión del provider', 'atora-lms' ); ?></button>
	</form>
	<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=clms-settings&tab=channels' ) ); ?>"><?php esc_html_e( 'Abrir configuración de canales', 'atora-lms' ); ?></a>
</div>
