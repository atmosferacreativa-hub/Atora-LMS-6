<?php
/**
 * Mensajería Routing tab.
 *
 * @package ATORA_LMS\Messaging
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_user_id = get_current_user_id();
?>
<h2><?php esc_html_e( 'Enrutamiento y fallback multicanal', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Define el orden de fallback por canal principal. Formato: canales separados por coma o salto de línea (ej: email:academia, email:teacher, email:admin, whatsapp).', 'atora-lms' ); ?></p>

<form method="post" style="margin-bottom:18px;">
	<?php wp_nonce_field( 'atora_messaging_admin_action', 'atora_messaging_nonce' ); ?>
	<input type="hidden" name="atora_messaging_action" value="save_routing">

	<table class="form-table" role="presentation">
		<tr>
			<th><label for="fallback_email"><?php esc_html_e( 'Fallback si falla Email', 'atora-lms' ); ?></label></th>
			<td><textarea class="large-text code" rows="2" id="fallback_email" name="fallback_channels[email]"><?php echo esc_textarea( (string) ( $routing_values['email'] ?? '' ) ); ?></textarea></td>
		</tr>
		<tr>
			<th><label for="fallback_whatsapp"><?php esc_html_e( 'Fallback si falla WhatsApp', 'atora-lms' ); ?></label></th>
			<td><textarea class="large-text code" rows="2" id="fallback_whatsapp" name="fallback_channels[whatsapp]"><?php echo esc_textarea( (string) ( $routing_values['whatsapp'] ?? '' ) ); ?></textarea></td>
		</tr>
		<tr>
			<th><label for="fallback_telegram"><?php esc_html_e( 'Fallback si falla Telegram', 'atora-lms' ); ?></label></th>
			<td><textarea class="large-text code" rows="2" id="fallback_telegram" name="fallback_channels[telegram]"><?php echo esc_textarea( (string) ( $routing_values['telegram'] ?? '' ) ); ?></textarea></td>
		</tr>
		<tr>
			<th><label for="fallback_sms"><?php esc_html_e( 'Fallback si falla SMS', 'atora-lms' ); ?></label></th>
			<td><textarea class="large-text code" rows="2" id="fallback_sms" name="fallback_channels[sms]"><?php echo esc_textarea( (string) ( $routing_values['sms'] ?? '' ) ); ?></textarea></td>
		</tr>
	</table>

	<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar enrutamiento', 'atora-lms' ); ?></button></p>
</form>

<hr>

<h3><?php esc_html_e( 'Prueba operativa de enrutamiento', 'atora-lms' ); ?></h3>
<p><?php esc_html_e( 'Encola un mensaje de prueba usando el router inteligente. Luego revisa la pestaña Bandeja para ver canal usado, eventos y fallback aplicado.', 'atora-lms' ); ?></p>

<form method="post">
	<?php wp_nonce_field( 'atora_messaging_admin_action', 'atora_messaging_nonce' ); ?>
	<input type="hidden" name="atora_messaging_action" value="test_routing">

	<table class="form-table" role="presentation">
		<tr>
			<th><label for="routing_user_id"><?php esc_html_e( 'Usuario destino (ID)', 'atora-lms' ); ?></label></th>
			<td><input type="number" min="1" class="regular-text" id="routing_user_id" name="routing_user_id" value="<?php echo esc_attr( (string) $current_user_id ); ?>"></td>
		</tr>
		<tr>
			<th><label for="routing_type"><?php esc_html_e( 'Tipo', 'atora-lms' ); ?></label></th>
			<td>
				<select id="routing_type" name="routing_type">
					<option value="purchase"><?php esc_html_e( 'purchase', 'atora-lms' ); ?></option>
					<option value="grade"><?php esc_html_e( 'grade', 'atora-lms' ); ?></option>
					<option value="live_reminder_1h"><?php esc_html_e( 'live_reminder_1h', 'atora-lms' ); ?></option>
					<option value="new_course"><?php esc_html_e( 'new_course', 'atora-lms' ); ?></option>
					<option value="inactivity" selected><?php esc_html_e( 'inactivity', 'atora-lms' ); ?></option>
					<option value="marketing"><?php esc_html_e( 'marketing', 'atora-lms' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="routing_template"><?php esc_html_e( 'Template', 'atora-lms' ); ?></label></th>
			<td><input type="text" class="regular-text" id="routing_template" name="routing_template" value="inactivity_reminder"></td>
		</tr>
		<tr>
			<th><label for="routing_variables"><?php esc_html_e( 'Variables JSON', 'atora-lms' ); ?></label></th>
			<td>
				<textarea class="large-text code" rows="4" id="routing_variables" name="routing_variables">{"message":"Prueba de fallback ATORA"}</textarea>
				<p class="description"><?php esc_html_e( 'Opcional. Debe ser un objeto JSON válido.', 'atora-lms' ); ?></p>
			</td>
		</tr>
	</table>

	<p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Encolar prueba', 'atora-lms' ); ?></button></p>
</form>
