<?php
/**
 * Bandeja unificada CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$inbox_data     = is_array( $inbox ?? null ) ? $inbox : array();
$conversations  = (array) ( $inbox_data['conversations'] ?? array() );
$messages       = (array) ( $inbox_data['messages'] ?? array() );
$selected       = (array) ( $inbox_data['selected'] ?? array() );
$selected_id    = absint( $selected['id'] ?? 0 );
$selected_contact_id = absint( $selected['contact_id'] ?? 0 );
$identity_options_data = is_array( $identity_options ?? null ) ? $identity_options : array(
	'academia' => __( 'Academia (plataforma y tienda)', 'atora-lms' ),
	'teacher'  => __( 'Docencia (estudiantes inscritos)', 'atora-lms' ),
	'admin'    => __( 'Comercial (leads y prospectos)', 'atora-lms' ),
);

require __DIR__ . '/partials/header.php';
?>
<section class="atora-crm-v2-panel">
	<div class="atora-crm-v2-panel__head">
		<h2><?php esc_html_e( 'Bandeja unificada', 'atora-lms' ); ?></h2>
		<p><?php esc_html_e( 'Email activo con historial. WhatsApp y Telegram quedan preparados para integración futura.', 'atora-lms' ); ?></p>
	</div>

	<div class="atora-crm-v2-inbox">
		<aside class="atora-crm-v2-inbox__list">
			<h3><?php esc_html_e( 'Conversaciones', 'atora-lms' ); ?></h3>
			<?php if ( empty( $conversations ) ) : ?>
				<p class="atora-crm-v2-empty atora-crm-v2-empty--mini"><?php esc_html_e( 'No hay conversaciones registradas.', 'atora-lms' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $conversations as $conversation ) : ?>
				<?php
				$conversation_id = absint( $conversation['id'] ?? 0 );
				$is_active       = $selected_id === $conversation_id;
				?>
				<a class="atora-crm-v2-inbox__item <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-inbox&conversation_id=' . $conversation_id ) ); ?>">
					<strong><?php echo esc_html( (string) ( $conversation['contact_name'] ?: __( 'Sin nombre', 'atora-lms' ) ) ); ?></strong>
					<span><?php echo esc_html( (string) ( $conversation['subject'] ?: strtoupper( (string) ( $conversation['channel'] ?? 'email' ) ) ) ); ?></span>
					<small><?php echo esc_html( (string) ( $conversation['last_message_at'] ?? '' ) ); ?></small>
				</a>
			<?php endforeach; ?>
		</aside>

		<div class="atora-crm-v2-inbox__messages">
			<h3><?php esc_html_e( 'Historial', 'atora-lms' ); ?></h3>
			<?php if ( empty( $messages ) ) : ?>
				<p class="atora-crm-v2-empty atora-crm-v2-empty--mini"><?php esc_html_e( 'Selecciona una conversación para ver el historial.', 'atora-lms' ); ?></p>
			<?php else : ?>
				<div class="atora-crm-v2-inbox__thread">
					<?php foreach ( $messages as $message ) : ?>
						<article class="atora-crm-v2-msg <?php echo 'outbound' === sanitize_key( (string) ( $message['direction'] ?? '' ) ) ? 'is-out' : 'is-in'; ?>">
							<header>
								<strong><?php echo esc_html( (string) ( $message['subject'] ?: strtoupper( (string) ( $message['channel'] ?? 'email' ) ) ) ); ?></strong>
								<small><?php echo esc_html( (string) ( $message['created_at'] ?? '' ) ); ?></small>
							</header>
							<p><?php echo esc_html( (string) ( $message['body_text'] ?: __( 'Mensaje sin texto', 'atora-lms' ) ) ); ?></p>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<form method="post" class="atora-crm-v2-form atora-crm-v2-form--reply" data-reply-email>
				<?php wp_nonce_field( 'atora_crm_v2_ui_action', 'atora_crm_v2_nonce' ); ?>
				<input type="hidden" name="atora_crm_v2_form_action" value="reply_inbox_email">
				<input type="hidden" name="conversation_id" value="<?php echo esc_attr( (string) $selected_id ); ?>">
				<input type="hidden" name="contact_id" value="<?php echo esc_attr( (string) $selected_contact_id ); ?>">
				<label>
					<?php esc_html_e( 'Responder por email', 'atora-lms' ); ?>
					<input type="email" name="reply_email" value="<?php echo esc_attr( (string) ( $selected['contact_email'] ?? '' ) ); ?>" placeholder="email@dominio.com" required>
				</label>
				<label>
					<?php esc_html_e( 'Identidad de envío', 'atora-lms' ); ?>
					<select name="reply_identity">
						<?php foreach ( $identity_options_data as $identity_key => $identity_label ) : ?>
							<option value="<?php echo esc_attr( (string) $identity_key ); ?>" <?php selected( (string) $identity_key, 'teacher' ); ?>><?php echo esc_html( (string) $identity_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Asunto', 'atora-lms' ); ?>
					<input type="text" name="reply_subject" value="<?php echo esc_attr( (string) ( $selected['subject'] ?? __( 'Seguimiento ATORA', 'atora-lms' ) ) ); ?>" required>
				</label>
				<label>
					<?php esc_html_e( 'Mensaje', 'atora-lms' ); ?>
					<textarea name="reply_message" rows="4" required><?php echo esc_textarea( (string) __( 'Gracias por escribirnos. Te respondemos para continuar el seguimiento.', 'atora-lms' ) ); ?></textarea>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Enviar email', 'atora-lms' ); ?></button>
				<p class="atora-crm-v2-muted"><?php esc_html_e( 'Al enviar, el mensaje se encola y queda trazado en la conversación con estado de salida.', 'atora-lms' ); ?></p>
			</form>
		</div>

		<aside class="atora-crm-v2-inbox__contact">
			<h3><?php esc_html_e( 'Ficha rápida', 'atora-lms' ); ?></h3>
			<?php if ( ! $selected_contact_id ) : ?>
				<p class="atora-crm-v2-empty atora-crm-v2-empty--mini"><?php esc_html_e( 'Sin contacto seleccionado.', 'atora-lms' ); ?></p>
			<?php else : ?>
				<p><strong><?php echo esc_html( (string) ( $selected['contact_name'] ?? '' ) ); ?></strong></p>
				<p><?php echo esc_html( (string) ( $selected['contact_email'] ?? '' ) ); ?></p>
				<p><?php esc_html_e( 'Canal email activo. WhatsApp/Telegram: próximos.', 'atora-lms' ); ?></p>
				<p><a class="atora-crm-v2-link" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2-contacts&contact_id=' . $selected_contact_id ) ); ?>"><?php esc_html_e( 'Abrir perfil 360', 'atora-lms' ); ?></a></p>

				<form method="post" class="atora-crm-v2-form">
					<?php wp_nonce_field( 'atora_crm_v2_ui_action', 'atora_crm_v2_nonce' ); ?>
					<input type="hidden" name="atora_crm_v2_form_action" value="save_contact_note">
					<input type="hidden" name="note_contact_id" value="<?php echo esc_attr( (string) $selected_contact_id ); ?>">
					<label><?php esc_html_e( 'Nota interna', 'atora-lms' ); ?>
						<textarea name="note_content" rows="4" placeholder="<?php esc_attr_e( 'Registra contexto para el siguiente responsable.', 'atora-lms' ); ?>"></textarea>
					</label>
					<button type="submit" class="button"><?php esc_html_e( 'Guardar nota', 'atora-lms' ); ?></button>
				</form>
			<?php endif; ?>
		</aside>
	</div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
