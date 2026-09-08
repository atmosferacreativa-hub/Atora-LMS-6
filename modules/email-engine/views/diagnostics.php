<?php
/**
 * Tab: Diagnóstico DNS.
 *
 * @package ATORA_LMS\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$from_email = sanitize_email( (string) ( $settings['identity_academia_from_email'] ?? $settings['from_email'] ?? get_option( 'admin_email' ) ) );
$domain     = sanitize_text_field( (string) wp_parse_url( 'mailto:' . $from_email, PHP_URL_PATH ) );
if ( strpos( $from_email, '@' ) !== false ) {
	$parts  = explode( '@', $from_email );
	$domain = sanitize_text_field( (string) end( $parts ) );
}
$domain = sanitize_text_field( (string) ( $_GET['domain'] ?? $domain ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$has_mx   = $domain ? checkdnsrr( $domain, 'MX' ) : false;
$has_txt  = $domain ? checkdnsrr( $domain, 'TXT' ) : false;
$has_dmarc = $domain ? checkdnsrr( '_dmarc.' . $domain, 'TXT' ) : false;
?>
<h2><?php esc_html_e( 'Diagnóstico DNS', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Revisión rápida de registros para entregabilidad de correo.', 'atora-lms' ); ?></p>

	<form method="get" style="margin-bottom:12px;">
		<input type="hidden" name="page" value="atora-emails">
		<input type="hidden" name="tab" value="diagnostics">
		<input type="text" name="domain" class="regular-text" value="<?php echo esc_attr( $domain ); ?>" placeholder="tudominio.com">
		<button type="submit" class="button"><?php esc_html_e( 'Revisar', 'atora-lms' ); ?></button>
	</form>

<?php if ( $domain ) : ?>
<table class="widefat striped" style="max-width:760px;">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Chequeo', 'atora-lms' ); ?></th>
			<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
			<th><?php esc_html_e( 'Detalle', 'atora-lms' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td>MX</td>
			<td><?php echo esc_html( $has_mx ? __( 'OK', 'atora-lms' ) : __( 'Falta', 'atora-lms' ) ); ?></td>
			<td><?php echo esc_html( sprintf( __( 'Dominio consultado: %s', 'atora-lms' ), $domain ) ); ?></td>
		</tr>
		<tr>
			<td>SPF/TXT</td>
			<td><?php echo esc_html( $has_txt ? __( 'OK', 'atora-lms' ) : __( 'Falta', 'atora-lms' ) ); ?></td>
			<td><?php esc_html_e( 'Valida que exista un TXT SPF correcto para tu proveedor.', 'atora-lms' ); ?></td>
		</tr>
		<tr>
			<td>DMARC</td>
			<td><?php echo esc_html( $has_dmarc ? __( 'OK', 'atora-lms' ) : __( 'Falta', 'atora-lms' ) ); ?></td>
			<td><?php esc_html_e( 'Se recomienda publicar _dmarc para mejorar reputación.', 'atora-lms' ); ?></td>
		</tr>
	</tbody>
</table>
<?php endif; ?>

	<div class="notice inline notice-info" style="margin-top:14px;">
		<p><strong><?php esc_html_e( 'Ejemplos rápidos de configuración', 'atora-lms' ); ?></strong></p>
		<p><code>Dominio propio: SMTP smtp.tudominio.com:465 SSL · IMAP imap.tudominio.com:993 SSL</code></p>
		<p><code>Gmail: smtp.gmail.com:465 SSL · imap.gmail.com:993 SSL</code></p>
	</div>
