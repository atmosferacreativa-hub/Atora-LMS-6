<?php
/**
 * Tab: Importar CSV (previsualización).
 *
 * @package ATORA_LMS\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h2><?php esc_html_e( 'Importar CSV con previsualización', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Paso seguro: detecta separador/codificación, muestra 10 filas y sugiere mapeo. No crea usuarios ni envía invitaciones en esta pantalla.', 'atora-lms' ); ?></p>

<form method="post" enctype="multipart/form-data">
	<?php wp_nonce_field( 'atora_email_admin_action', 'atora_email_nonce' ); ?>
	<input type="hidden" name="atora_email_action" value="preview_csv">
	<input type="file" name="csv_file" accept=".csv,.txt" required>
	<button type="submit" class="button button-primary"><?php esc_html_e( 'Previsualizar', 'atora-lms' ); ?></button>
</form>

<?php if ( ! empty( $csv_preview['rows'] ) ) : ?>
	<div style="margin-top:16px;">
		<p><strong><?php esc_html_e( 'Separador detectado:', 'atora-lms' ); ?></strong> <code><?php echo esc_html( (string) $csv_preview['delimiter'] ); ?></code></p>
		<p><strong><?php esc_html_e( 'Codificación:', 'atora-lms' ); ?></strong> <code><?php echo esc_html( (string) $csv_preview['encoding'] ); ?></code></p>
	</div>

	<?php if ( ! empty( $csv_preview['mapping'] ) ) : ?>
		<h3><?php esc_html_e( 'Mapeo sugerido', 'atora-lms' ); ?></h3>
		<ul>
			<?php foreach ( $csv_preview['mapping'] as $canonical => $column ) : ?>
				<li><strong><?php echo esc_html( (string) $canonical ); ?>:</strong> <?php echo esc_html( (string) $column ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Primeras 10 filas', 'atora-lms' ); ?></h3>
	<table class="widefat striped">
		<tbody>
			<?php foreach ( $csv_preview['rows'] as $index => $row ) : ?>
				<tr>
					<?php foreach ( (array) $row as $cell ) : ?>
						<?php if ( 0 === $index ) : ?>
							<th><?php echo esc_html( (string) $cell ); ?></th>
						<?php else : ?>
							<td><?php echo esc_html( (string) $cell ); ?></td>
						<?php endif; ?>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<div class="notice inline notice-warning" style="margin-top:14px;">
		<p><?php esc_html_e( 'Esta vista solo previsualiza. La importación ejecutable sigue disponible en los flujos de matrícula manual/CSV del LMS.', 'atora-lms' ); ?></p>
	</div>
<?php endif; ?>
