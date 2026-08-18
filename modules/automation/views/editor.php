<?php
/**
 * Automation editor tab.
 *
 * @package ATORA_LMS\Automation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h2><?php esc_html_e( 'Crear workflow', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Editor visual simple: trigger + acción principal + prioridad.', 'atora-lms' ); ?></p>

<form method="post">
	<?php wp_nonce_field( 'atora_automation_admin_action', 'atora_automation_nonce' ); ?>
	<input type="hidden" name="atora_automation_action" value="save_workflow">

	<table class="form-table" role="presentation">
		<tr>
			<th><label for="workflow_name"><?php esc_html_e( 'Nombre', 'atora-lms' ); ?></label></th>
			<td><input type="text" class="regular-text" id="workflow_name" name="name" required></td>
		</tr>
		<tr>
			<th><label for="workflow_description"><?php esc_html_e( 'Descripción', 'atora-lms' ); ?></label></th>
			<td><textarea class="large-text" id="workflow_description" name="description" rows="3"></textarea></td>
		</tr>
		<tr>
			<th><label for="workflow_trigger"><?php esc_html_e( 'Trigger', 'atora-lms' ); ?></label></th>
			<td>
				<select id="workflow_trigger" name="trigger_type">
					<option value="user_registered"><?php esc_html_e( 'Usuario registrado', 'atora-lms' ); ?></option>
					<option value="course_enrolled"><?php esc_html_e( 'Usuario inscrito en curso', 'atora-lms' ); ?></option>
					<option value="lesson_completed"><?php esc_html_e( 'Lección completada', 'atora-lms' ); ?></option>
					<option value="course_completed"><?php esc_html_e( 'Curso completado', 'atora-lms' ); ?></option>
					<option value="grade_published"><?php esc_html_e( 'Calificación publicada', 'atora-lms' ); ?></option>
					<option value="purchase_completed"><?php esc_html_e( 'Compra completada', 'atora-lms' ); ?></option>
					<option value="email_opened"><?php esc_html_e( 'Email abierto', 'atora-lms' ); ?></option>
					<option value="email_clicked"><?php esc_html_e( 'Email clicado', 'atora-lms' ); ?></option>
					<option value="tag_added"><?php esc_html_e( 'Tag agregado', 'atora-lms' ); ?></option>
					<option value="form_submitted"><?php esc_html_e( 'Formulario enviado', 'atora-lms' ); ?></option>
					<option value="inactivity_detected"><?php esc_html_e( 'Inactividad detectada', 'atora-lms' ); ?></option>
					<option value="deal_stage_changed"><?php esc_html_e( 'Deal: cambio de etapa en pipeline', 'atora-lms' ); ?></option>
					<option value="contact_created"><?php esc_html_e( 'CRM: contacto nuevo creado', 'atora-lms' ); ?></option>
					<option value="campaign_opened"><?php esc_html_e( 'Email: apertura de campaña', 'atora-lms' ); ?></option>
					<option value="grade_below_threshold"><?php esc_html_e( 'Calificación: nota por debajo del umbral', 'atora-lms' ); ?></option>
					<option value="inactivity_academic"><?php esc_html_e( 'Académico: inactividad en curso', 'atora-lms' ); ?></option>
					<option value="enrollment_completed"><?php esc_html_e( 'Matrícula: inscripción completada', 'atora-lms' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="workflow_action"><?php esc_html_e( 'Acción', 'atora-lms' ); ?></label></th>
			<td>
				<select id="workflow_action" name="action_type">
					<option value="send_email"><?php esc_html_e( 'Enviar email', 'atora-lms' ); ?></option>
					<option value="send_whatsapp"><?php esc_html_e( 'Enviar WhatsApp', 'atora-lms' ); ?></option>
					<option value="send_telegram"><?php esc_html_e( 'Enviar Telegram', 'atora-lms' ); ?></option>
					<option value="add_tag"><?php esc_html_e( 'Agregar tag', 'atora-lms' ); ?></option>
					<option value="remove_tag"><?php esc_html_e( 'Quitar tag', 'atora-lms' ); ?></option>
					<option value="internal_notification"><?php esc_html_e( 'Notificación interna', 'atora-lms' ); ?></option>
					<option value="start_email_sequence"><?php esc_html_e( 'Iniciar secuencia de email drip', 'atora-lms' ); ?></option>
					<option value="move_pipeline_stage"><?php esc_html_e( 'Mover etapa de pipeline', 'atora-lms' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="workflow_template"><?php esc_html_e( 'Template/tag', 'atora-lms' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="workflow_template" name="template" value="welcome_course">
				<p class="description"><?php esc_html_e( 'Para add/remove tag usa el nombre del tag. Para envío usa la plantilla.', 'atora-lms' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="workflow_message_type"><?php esc_html_e( 'Tipo de mensaje', 'atora-lms' ); ?></label></th>
			<td>
				<select id="workflow_message_type" name="message_type">
					<option value="purchase"><?php esc_html_e( 'purchase', 'atora-lms' ); ?></option>
					<option value="grade"><?php esc_html_e( 'grade', 'atora-lms' ); ?></option>
					<option value="live_reminder_1h"><?php esc_html_e( 'live_reminder_1h', 'atora-lms' ); ?></option>
					<option value="new_course"><?php esc_html_e( 'new_course', 'atora-lms' ); ?></option>
					<option value="inactivity"><?php esc_html_e( 'inactivity', 'atora-lms' ); ?></option>
					<option value="marketing" selected><?php esc_html_e( 'marketing', 'atora-lms' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="workflow_message_priority"><?php esc_html_e( 'Prioridad de envío', 'atora-lms' ); ?></label></th>
			<td>
				<select id="workflow_message_priority" name="message_priority">
					<option value="critical"><?php esc_html_e( 'Crítica', 'atora-lms' ); ?></option>
					<option value="high"><?php esc_html_e( 'Alta', 'atora-lms' ); ?></option>
					<option value="medium" selected><?php esc_html_e( 'Media', 'atora-lms' ); ?></option>
					<option value="low"><?php esc_html_e( 'Baja', 'atora-lms' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="workflow_identity"><?php esc_html_e( 'Identidad email', 'atora-lms' ); ?></label></th>
			<td>
				<select id="workflow_identity" name="email_identity">
					<option value="academia"><?php esc_html_e( 'Academia / Admin', 'atora-lms' ); ?></option>
					<option value="teacher"><?php esc_html_e( 'Docencia', 'atora-lms' ); ?></option>
					<option value="admin"><?php esc_html_e( 'Comercial', 'atora-lms' ); ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="workflow_fallback_channels"><?php esc_html_e( 'Fallback canales', 'atora-lms' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="workflow_fallback_channels" name="fallback_channels" value="email:academia,email:teacher,email:admin">
				<p class="description"><?php esc_html_e( 'Solo para WhatsApp/Telegram. Separar por comas.', 'atora-lms' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="workflow_email_identity_fallback"><?php esc_html_e( 'Fallback identidades email', 'atora-lms' ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="workflow_email_identity_fallback" name="email_identity_fallback" value="academia,teacher,admin">
				<p class="description"><?php esc_html_e( 'Orden para fallback de identidad en email (ej: academia,teacher,admin).', 'atora-lms' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="workflow_internal_message"><?php esc_html_e( 'Mensaje interno', 'atora-lms' ); ?></label></th>
			<td>
				<textarea class="large-text" id="workflow_internal_message" name="internal_message" rows="2"><?php esc_textarea_e( 'Notificación interna de automatización.', 'atora-lms' ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Se usa cuando la acción es Notificación interna.', 'atora-lms' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="workflow_delay"><?php esc_html_e( 'Delay (min)', 'atora-lms' ); ?></label></th>
			<td><input type="number" min="0" id="workflow_delay" name="delay_minutes" value="0"></td>
		</tr>
		<tr>
			<th><label for="workflow_priority"><?php esc_html_e( 'Prioridad', 'atora-lms' ); ?></label></th>
			<td><input type="number" min="1" max="99" id="workflow_priority" name="priority" value="10"></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Activo', 'atora-lms' ); ?></th>
			<td><label><input type="checkbox" name="active" value="1"> <?php esc_html_e( 'Activar workflow al guardar', 'atora-lms' ); ?></label></td>
		</tr>
	</table>

	<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar workflow', 'atora-lms' ); ?></button></p>
</form>
