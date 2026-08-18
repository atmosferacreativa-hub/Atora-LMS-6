<?php
/**
 * Tab: Prueba de envío.
 *
 * @package ATORA_LMS\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$catalog = class_exists( '\ATORA\EmailEngine\Email_Templates' )
	? (array) \ATORA\EmailEngine\Email_Templates::get_catalog()
	: array();
$selected_identity = isset( $_GET['identity'] ) ? sanitize_key( (string) wp_unslash( $_GET['identity'] ) ) : 'academia';
if ( in_array( $selected_identity, array( 'docencia', 'docente', 'teacher', 'comercio', 'seguimiento' ), true ) ) {
	$selected_identity = 'teacher';
} elseif ( in_array( $selected_identity, array( 'comercial', 'commercial', 'admin', 'administracion', 'sales', 'venta', 'leads', 'prospecto' ), true ) ) {
	$selected_identity = 'admin';
} else {
	$selected_identity = 'academia';
}
?>
<h2><?php esc_html_e( 'Prueba de envío por identidad', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Envía una prueba real inmediata con la identidad seleccionada. Si escoges plantilla, se renderiza y envía al instante.', 'atora-lms' ); ?></p>

<section class="atora-emails-admin__callout">
	<h3><?php esc_html_e( 'Antes de hacer clic en "Enviar prueba"', 'atora-lms' ); ?></h3>
	<p><?php esc_html_e( '1) Valida host/puerto/seguridad en Asistente o Provider. 2) Usa App Password si tu proveedor la exige. 3) Revisa spam/promociones en el destinatario de prueba.', 'atora-lms' ); ?></p>
	<p><?php esc_html_e( 'El estado "Solicitud aceptada por el proveedor" significa que ATORA entregó el mensaje al servicio SMTP/API, no garantiza apertura ni entrega final en inbox.', 'atora-lms' ); ?></p>
	<?php if ( ! empty( $app_password_links ) && is_array( $app_password_links ) ) : ?>
		<div class="atora-emails-admin__help-grid">
			<?php foreach ( $app_password_links as $help_item ) : ?>
				<a class="atora-emails-admin__help-card" href="<?php echo esc_url( (string) ( $help_item['url'] ?? '' ) ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html( (string) ( $help_item['label'] ?? __( 'Proveedor', 'atora-lms' ) ) ); ?>
					<span><?php esc_html_e( 'Cómo generar App Password', 'atora-lms' ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>

	<?php if ( ! empty( $identity_health_rows ) && is_array( $identity_health_rows ) ) : ?>
		<div style="margin:12px 0 18px;">
			<table class="widefat striped" style="max-width:980px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Identidad', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Estado conexión', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Resumen', 'atora-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $identity_health_rows as $identity_key => $health ) : ?>
						<?php
						$label = (string) ( $identity_labels[ $identity_key ] ?? ucfirst( (string) $identity_key ) );
						$issues = is_array( $health['issues'] ?? null ) ? $health['issues'] : array();
						?>
						<tr>
							<td><strong><?php echo esc_html( $label ); ?></strong></td>
							<td>
								<?php if ( ! empty( $health['ready'] ) ) : ?>
									<span style="color:#166534;"><?php esc_html_e( 'Lista', 'atora-lms' ); ?></span>
								<?php else : ?>
									<span style="color:#b91c1c;"><?php esc_html_e( 'Incompleta', 'atora-lms' ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $health['provider'] ) ) : ?>
									<small style="display:block;color:#64748b;"><?php echo esc_html( strtoupper( (string) $health['provider'] ) ); ?></small>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( empty( $issues ) ) : ?>
									<?php if ( 'api' === (string) ( $health['mode'] ?? '' ) ) : ?>
										<?php esc_html_e( 'Remitente e integración API configurados.', 'atora-lms' ); ?>
									<?php else : ?>
										<?php esc_html_e( 'Host, puerto, usuario y contraseña SMTP configurados.', 'atora-lms' ); ?>
									<?php endif; ?>
								<?php else : ?>
									<?php echo esc_html( implode( ' ', array_map( 'sanitize_text_field', $issues ) ) ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>

<form method="post" autocomplete="off">
	<?php wp_nonce_field( 'atora_email_admin_action', 'atora_email_nonce' ); ?>
	<input type="hidden" name="atora_email_action" value="send_test">

	<table class="form-table" role="presentation">
		<tr>
			<th><label for="test_identity"><?php esc_html_e( 'Identidad', 'atora-lms' ); ?></label></th>
			<td>
				<select id="test_identity" name="test_identity">
					<option value="academia" <?php selected( $selected_identity, 'academia' ); ?>><?php esc_html_e( 'Academia / Admin', 'atora-lms' ); ?></option>
					<option value="teacher" <?php selected( $selected_identity, 'teacher' ); ?>><?php esc_html_e( 'Docencia', 'atora-lms' ); ?></option>
					<option value="admin" <?php selected( $selected_identity, 'admin' ); ?>><?php esc_html_e( 'Comercial', 'atora-lms' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="test_recipient"><?php esc_html_e( 'Destinatario', 'atora-lms' ); ?></label></th>
			<td><input type="email" required class="regular-text" id="test_recipient" name="test_recipient"></td>
		</tr>
		<tr>
			<th><label for="test_template"><?php esc_html_e( 'Plantilla (opcional)', 'atora-lms' ); ?></label></th>
			<td>
				<select id="test_template" name="test_template">
					<option value=""><?php esc_html_e( 'Sin plantilla (mensaje simple)', 'atora-lms' ); ?></option>
					<?php foreach ( $catalog as $key => $template ) : ?>
						<option value="<?php echo esc_attr( (string) $key ); ?>"><?php echo esc_html( (string) ( $template['name'] ?? $key ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="test_subject"><?php esc_html_e( 'Asunto', 'atora-lms' ); ?></label></th>
			<td><input type="text" class="regular-text" id="test_subject" name="test_subject" value="<?php esc_attr_e( 'Prueba de canal ATORA', 'atora-lms' ); ?>"></td>
		</tr>
		<tr>
			<th><label for="test_message"><?php esc_html_e( 'Mensaje', 'atora-lms' ); ?></label></th>
			<td>
				<textarea class="large-text" rows="5" id="test_message" name="test_message"><?php esc_textarea_e( 'Este es un envío de prueba desde ATORA.', 'atora-lms' ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Si seleccionas una plantilla, este mensaje se ignora automáticamente.', 'atora-lms' ); ?></p>
			</td>
		</tr>
	</table>

		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Enviar prueba', 'atora-lms' ); ?></button></p>
</form>
<p class="atora-emails-admin__note"><?php esc_html_e( 'Cada prueba se registra en Cola y logs para trazabilidad. Además se intenta sincronizar en CRM por usuario y también por contacto (email externo).', 'atora-lms' ); ?></p>

<hr style="margin:24px 0;">

<h2><?php esc_html_e( 'Prueba de todas las plantillas', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Envía una prueba de cada plantilla activa al correo indicado para validar la configuración del canal.', 'atora-lms' ); ?></p>

<form method="post">
	<?php wp_nonce_field( 'atora_email_admin_action', 'atora_email_nonce' ); ?>
	<input type="hidden" name="atora_email_action" value="send_test_all">

	<table class="form-table" role="presentation">
		<tr>
			<th><label for="test_identity_all"><?php esc_html_e( 'Identidad', 'atora-lms' ); ?></label></th>
			<td>
				<select id="test_identity_all" name="test_identity_all">
					<option value="academia" <?php selected( $selected_identity, 'academia' ); ?>><?php esc_html_e( 'Academia / Admin', 'atora-lms' ); ?></option>
					<option value="teacher" <?php selected( $selected_identity, 'teacher' ); ?>><?php esc_html_e( 'Docencia', 'atora-lms' ); ?></option>
					<option value="admin" <?php selected( $selected_identity, 'admin' ); ?>><?php esc_html_e( 'Comercial', 'atora-lms' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="test_recipient_all"><?php esc_html_e( 'Destinatario', 'atora-lms' ); ?></label></th>
			<td><input type="email" required class="regular-text" id="test_recipient_all" name="test_recipient_all"></td>
		</tr>
	</table>

	<p>
		<button type="submit" class="button button-secondary">
			<?php esc_html_e( 'Enviar prueba de todas las plantillas', 'atora-lms' ); ?>
		</button>
	</p>
</form>

<script>
(function() {
	var templateSelect = document.getElementById('test_template');
	var messageField = document.getElementById('test_message');
	if (!templateSelect || !messageField) return;
	var defaultMessage = <?php echo wp_json_encode( __( 'Este es un envío de prueba desde ATORA.', 'atora-lms' ) ); ?>;
	var templateMessage = <?php echo wp_json_encode( __( 'Se usará la plantilla seleccionada para esta prueba.', 'atora-lms' ) ); ?>;
	var syncMessageState = function() {
		if (templateSelect.value) {
			messageField.value = templateMessage;
			messageField.setAttribute('readonly', 'readonly');
		} else {
			if (messageField.value === templateMessage || messageField.value === '') {
				messageField.value = defaultMessage;
			}
			messageField.removeAttribute('readonly');
		}
	};
	templateSelect.addEventListener('change', syncMessageState);
	syncMessageState();
})();
</script>
