<?php
/**
 * Configuración CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$channels_data = is_array( $channels ?? null ) ? $channels : array();
$settings_hub  = esc_url( (string) ( $settings_hub_url ?? admin_url( 'admin.php?page=clms-settings-hub' ) ) );
$custom_fields = (array) ( $custom_fields ?? array() );

require __DIR__ . '/partials/header.php';
?>
<section class="atora-crm-v2-panel">
	<div class="atora-crm-v2-panel__head">
		<h2><?php esc_html_e( 'Configuración CRM', 'atora-lms' ); ?></h2>
		<p><?php esc_html_e( 'Conecta el CRM con los hubs de configuración sin perder trazabilidad operativa.', 'atora-lms' ); ?></p>
	</div>

	<div class="atora-crm-v2-grid atora-crm-v2-grid--three">
		<article class="atora-crm-v2-card atora-crm-v2-card--blue">
			<h3><?php esc_html_e( 'Ajustes globales', 'atora-lms' ); ?></h3>
			<p><?php esc_html_e( 'Controla permisos, APIs y parámetros centrales desde Hub de ajustes.', 'atora-lms' ); ?></p>
			<a class="atora-crm-v2-btn" href="<?php echo esc_url( $settings_hub ); ?>"><?php esc_html_e( 'Abrir Hub de ajustes', 'atora-lms' ); ?></a>
		</article>

		<article class="atora-crm-v2-card atora-crm-v2-card--teal">
			<h3><?php esc_html_e( 'Canales habilitados', 'atora-lms' ); ?></h3>
			<ul class="atora-crm-v2-list">
				<?php foreach ( $channels_data as $channel ) : ?>
					<?php
					$label = sanitize_text_field( (string) ( $channel['label'] ?? '' ) );
					$ready = ! empty( $channel['ready'] );
					$note  = sanitize_text_field( (string) ( $channel['note'] ?? '' ) );
					?>
					<li>
						<strong><?php echo esc_html( $label ); ?></strong>
						<span><?php echo esc_html( $ready ? __( 'Activo', 'atora-lms' ) : __( 'Pendiente', 'atora-lms' ) ); ?></span>
						<small><?php echo esc_html( $note ); ?></small>
					</li>
				<?php endforeach; ?>
			</ul>
		</article>

		<article class="atora-crm-v2-card atora-crm-v2-card--slate">
			<h3><?php esc_html_e( 'Checklist de consistencia', 'atora-lms' ); ?></h3>
			<ul class="atora-crm-v2-checklist">
				<li><?php esc_html_e( 'Permisos CRM por rol validados.', 'atora-lms' ); ?></li>
				<li><?php esc_html_e( 'Email Engine activo para campañas y respuestas.', 'atora-lms' ); ?></li>
				<li><?php esc_html_e( 'WhatsApp y Telegram preparados como canal futuro (sin API).', 'atora-lms' ); ?></li>
			</ul>
		</article>
	</div>
</section>

<section class="atora-crm-v2-panel">
	<div class="atora-crm-v2-panel__head">
		<h2><?php esc_html_e( 'Campos de contacto', 'atora-lms' ); ?></h2>
		<p><?php esc_html_e( 'Crea campos adicionales para adaptar la ficha 360 a tu operación.', 'atora-lms' ); ?></p>
	</div>

	<form method="post" class="atora-crm-v2-form-stack">
		<?php wp_nonce_field( 'atora_crm_v2_ui_action', 'atora_crm_v2_nonce' ); ?>
		<input type="hidden" name="atora_crm_v2_form_action" value="save_custom_field_definition">
		<div class="atora-crm-v2-grid atora-crm-v2-grid--three">
			<input type="text" name="field_key" placeholder="<?php esc_attr_e( 'field_key', 'atora-lms' ); ?>">
			<input type="text" name="field_label" placeholder="<?php esc_attr_e( 'Etiqueta visible', 'atora-lms' ); ?>">
			<select name="field_type">
				<option value="text"><?php esc_html_e( 'Texto', 'atora-lms' ); ?></option>
				<option value="textarea"><?php esc_html_e( 'Textarea', 'atora-lms' ); ?></option>
				<option value="number"><?php esc_html_e( 'Número', 'atora-lms' ); ?></option>
				<option value="date"><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></option>
				<option value="select"><?php esc_html_e( 'Select', 'atora-lms' ); ?></option>
				<option value="checkbox"><?php esc_html_e( 'Checkbox', 'atora-lms' ); ?></option>
			</select>
		</div>
		<textarea name="field_options" rows="3" placeholder="<?php esc_attr_e( 'Opciones para select, una por línea', 'atora-lms' ); ?>"></textarea>
		<label><input type="checkbox" name="field_required" value="1"> <?php esc_html_e( 'Requerido', 'atora-lms' ); ?></label>
		<label><input type="checkbox" name="field_show_in_360" value="1" checked> <?php esc_html_e( 'Mostrar en ficha 360', 'atora-lms' ); ?></label>
		<input type="number" name="field_sort_order" min="0" step="1" value="0">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar campo', 'atora-lms' ); ?></button>
	</form>

	<?php if ( ! empty( $custom_fields ) ) : ?>
		<table class="atora-crm-v2-table" style="margin-top:16px;">
			<thead><tr><th><?php esc_html_e( 'Key', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Etiqueta', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Tipo', 'atora-lms' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $custom_fields as $field ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $field['field_key'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $field['label'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( strtoupper( (string) ( $field['field_type'] ?? 'text' ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
