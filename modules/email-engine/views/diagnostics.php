<?php
/**
 * Tab: Entregabilidad (6.38.0): métricas por día y proveedor, y verificador DNS (SPF, DKIM, DMARC) con la
 * corrección para el proveedor configurado.
 *
 * @package ATORA_LMS\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ATORA\EmailEngine\Email_Deliverability;

$settings   = isset( $settings ) && is_array( $settings ) ? $settings : (array) get_option( 'atora_email_engine_options', array() );
$from_email = sanitize_email( (string) ( $settings['identity_academia_from_email'] ?? $settings['from_email'] ?? get_option( 'admin_email' ) ) );
$domain     = false !== strpos( $from_email, '@' ) ? substr( $from_email, strrpos( $from_email, '@' ) + 1 ) : '';
$domain     = isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['domain'] ) ) : $domain; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$provider   = sanitize_key( (string) ( $settings['provider'] ?? 'smtp' ) );
$selector   = sanitize_key( (string) ( $settings['dkim_selector'] ?? '' ) );
$days       = isset( $_GET['days'] ) ? max( 1, min( 90, absint( $_GET['days'] ) ) ) : 14; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$stats      = class_exists( Email_Deliverability::class ) ? Email_Deliverability::daily_stats( $days ) : array();
$checks     = ( '' !== $domain && class_exists( Email_Deliverability::class ) ) ? Email_Deliverability::dns_check( $domain, $provider, $selector ) : array();
$labels     = array(
	'ok'      => __( 'Correcto', 'atora-lms' ),
	'warning' => __( 'Revisar', 'atora-lms' ),
	'missing' => __( 'Falta', 'atora-lms' ),
);
$pct = static fn( int $part, int $total ): string => $total > 0 ? number_format_i18n( 100 * $part / $total, 1 ) . ' %' : '—';
?>
<h2><?php esc_html_e( 'Entregabilidad', 'atora-lms' ); ?></h2>

<h3><?php esc_html_e( 'Envíos por día y proveedor', 'atora-lms' ); ?></h3>
<form method="get" style="margin-bottom:8px;">
	<input type="hidden" name="page" value="atora-emails">
	<input type="hidden" name="tab" value="diagnostics">
	<label for="atora-deliv-days"><?php esc_html_e( 'Días', 'atora-lms' ); ?></label>
	<select id="atora-deliv-days" name="days" onchange="this.form.submit()">
		<?php foreach ( array( 7, 14, 30, 90 ) as $d ) : ?>
			<option value="<?php echo (int) $d; ?>" <?php selected( $days, $d ); ?>><?php echo (int) $d; ?></option>
		<?php endforeach; ?>
	</select>
</form>
<?php if ( ! $stats ) : ?>
	<p><?php esc_html_e( 'Sin envíos en el periodo.', 'atora-lms' ); ?></p>
<?php else : ?>
	<table class="widefat striped" style="max-width:1100px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Día', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Proveedor', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Enviados', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Entregados', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Rebotes', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Quejas', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Aperturas', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Clics', 'atora-lms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $stats as $row ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $row['day'] ); ?></td>
					<td><?php echo esc_html( (string) $row['provider'] ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $row['sent'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $row['delivered'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $row['bounced'] ) . ' (' . $pct( $row['bounced'], $row['sent'] ) . ')' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $row['complained'] ) . ' (' . $pct( $row['complained'], $row['sent'] ) . ')' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $row['opened'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $row['clicked'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'Entregados, rebotes y quejas dependen de que el webhook del proveedor esté configurado. Las aperturas son aproximadas (muchos clientes de correo bloquean imágenes).', 'atora-lms' ); ?></p>
<?php endif; ?>

<h3 style="margin-top:24px;"><?php esc_html_e( 'DNS del dominio remitente', 'atora-lms' ); ?></h3>
<form method="get" style="margin-bottom:12px;">
	<input type="hidden" name="page" value="atora-emails">
	<input type="hidden" name="tab" value="diagnostics">
	<label for="atora-deliv-domain" class="screen-reader-text"><?php esc_html_e( 'Dominio', 'atora-lms' ); ?></label>
	<input id="atora-deliv-domain" type="text" name="domain" class="regular-text" value="<?php echo esc_attr( $domain ); ?>" placeholder="tudominio.com">
	<button type="submit" class="button"><?php esc_html_e( 'Revisar', 'atora-lms' ); ?></button>
	<span class="description"><?php echo esc_html( sprintf( /* translators: 1: provider 2: selector */ __( 'Proveedor: %1$s · Selector DKIM: %2$s', 'atora-lms' ), $provider, '' !== $selector ? $selector : __( 'habituales', 'atora-lms' ) ) ); ?></span>
</form>
<?php if ( $checks ) : ?>
	<table class="widefat striped" style="max-width:1100px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Registro', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Encontrado', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Cómo corregirlo', 'atora-lms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $checks as $check ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $check['check'] ); ?></strong></td>
					<td><?php echo esc_html( $labels[ $check['status'] ] ?? $check['status'] ); ?></td>
					<td><code style="word-break:break-all;"><?php echo esc_html( '' !== $check['found'] ? $check['found'] : '—' ); ?></code></td>
					<td><?php echo esc_html( $check['fix'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
<p style="margin-top:12px;"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-emails&tab=test-send' ) ); ?>"><?php esc_html_e( 'Enviar un correo de prueba', 'atora-lms' ); ?></a></p>
