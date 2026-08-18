<?php
/**
 * Tab: Asistente rápido.
 *
 * @package ATORA_LMS\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wizard_email = sanitize_email( (string) ( $settings['smtp_user'] ?? $settings['from_email'] ?? get_option( 'admin_email' ) ) );
$wizard_provider = sanitize_key( (string) ( $settings['provider'] ?? 'smtp' ) );
if ( ! in_array( $wizard_provider, array( 'smtp', 'brevo', 'sendgrid', 'mailgun', 'ses', 'postmark' ), true ) ) {
	$wizard_provider = 'smtp';
}
$wizard_identity = sanitize_key( (string) ( $settings['imap_identity'] ?? $settings['identity_key'] ?? 'academia' ) );
if ( in_array( $wizard_identity, array( 'docencia', 'docente', 'teacher', 'comercio', 'seguimiento' ), true ) ) {
	$wizard_identity = 'teacher';
} elseif ( in_array( $wizard_identity, array( 'admin', 'comercial', 'commercial', 'sales', 'venta', 'leads', 'prospecto' ), true ) ) {
	$wizard_identity = 'admin';
} else {
	$wizard_identity = 'academia';
}
?>
<h2><?php esc_html_e( 'Asistente rápido de configuración', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Configura correo paso a paso sin tocar código: Gmail, Outlook, Yahoo, iCloud, dominios propios (como Atmósfera) y más.', 'atora-lms' ); ?></p>

<form method="post">
	<?php wp_nonce_field( 'atora_email_admin_action', 'atora_email_nonce' ); ?>
	<input type="hidden" name="atora_email_action" value="save_wizard">

	<table class="form-table" role="presentation">
		<tr>
			<th><label for="wizard_preset"><?php esc_html_e( 'Preset recomendado', 'atora-lms' ); ?></label></th>
			<td>
				<select id="wizard_preset" name="wizard_preset">
					<option value="manual"><?php esc_html_e( 'Manual (cualquier proveedor)', 'atora-lms' ); ?></option>
					<option value="atmosfera"><?php esc_html_e( 'Atmósfera Creativa (dominio propio)', 'atora-lms' ); ?></option>
					<option value="gmail"><?php esc_html_e( 'Gmail / Google Workspace', 'atora-lms' ); ?></option>
					<option value="outlook"><?php esc_html_e( 'Outlook / Microsoft 365', 'atora-lms' ); ?></option>
					<option value="yahoo"><?php esc_html_e( 'Yahoo Mail', 'atora-lms' ); ?></option>
					<option value="icloud"><?php esc_html_e( 'iCloud Mail', 'atora-lms' ); ?></option>
					<option value="zoho"><?php esc_html_e( 'Zoho Mail', 'atora-lms' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'El preset completa host/puertos/seguridad. Puedes editar cualquier valor manualmente.', 'atora-lms' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="wizard_provider"><?php esc_html_e( 'Motor de envío', 'atora-lms' ); ?></label></th>
			<td>
				<select id="wizard_provider" name="wizard_provider">
					<option value="smtp" <?php selected( $wizard_provider, 'smtp' ); ?>><?php esc_html_e( 'SMTP (recomendado para usuarios básicos)', 'atora-lms' ); ?></option>
					<option value="brevo" <?php selected( $wizard_provider, 'brevo' ); ?>><?php esc_html_e( 'Brevo API', 'atora-lms' ); ?></option>
					<option value="sendgrid" <?php selected( $wizard_provider, 'sendgrid' ); ?>><?php esc_html_e( 'SendGrid API', 'atora-lms' ); ?></option>
					<option value="mailgun" <?php selected( $wizard_provider, 'mailgun' ); ?>><?php esc_html_e( 'Mailgun API', 'atora-lms' ); ?></option>
					<option value="ses" <?php selected( $wizard_provider, 'ses' ); ?>><?php esc_html_e( 'Amazon SES API', 'atora-lms' ); ?></option>
					<option value="postmark" <?php selected( $wizard_provider, 'postmark' ); ?>><?php esc_html_e( 'Postmark API', 'atora-lms' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Si usas Gmail/Outlook/Yahoo/dominio propio, deja SMTP.', 'atora-lms' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="email_main"><?php esc_html_e( 'Email principal', 'atora-lms' ); ?></label></th>
			<td><input type="email" required class="regular-text" id="email_main" name="email_main" value="<?php echo esc_attr( $wizard_email ); ?>"></td>
		</tr>
		<tr>
			<th><label for="smtp_pass"><?php esc_html_e( 'Contraseña SMTP / API key', 'atora-lms' ); ?></label></th>
			<td>
				<input type="password" class="regular-text" id="smtp_pass" name="smtp_pass" value="">
				<p class="description"><?php esc_html_e( 'Para Gmail/Yahoo/iCloud/Outlook usa contraseña de aplicación (no tu contraseña normal).', 'atora-lms' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="smtp_host"><?php esc_html_e( 'Servidor SMTP', 'atora-lms' ); ?></label></th>
			<td><input type="text" class="regular-text" id="smtp_host" name="smtp_host" value="<?php echo esc_attr( (string) ( $settings['smtp_host'] ?? 'atmosferacreativa.com' ) ); ?>"></td>
		</tr>
		<tr>
			<th><label for="smtp_port"><?php esc_html_e( 'Puerto SMTP', 'atora-lms' ); ?></label></th>
			<td><input type="number" min="1" max="65535" id="smtp_port" name="smtp_port" value="<?php echo esc_attr( (string) absint( $settings['smtp_port'] ?? 465 ) ); ?>"></td>
		</tr>
		<tr>
			<th><label for="smtp_secure"><?php esc_html_e( 'Seguridad SMTP', 'atora-lms' ); ?></label></th>
			<td>
				<select id="smtp_secure" name="smtp_secure">
					<option value="ssl" <?php selected( (string) ( $settings['smtp_encryption'] ?? 'ssl' ), 'ssl' ); ?>>SSL/TLS</option>
					<option value="tls" <?php selected( (string) ( $settings['smtp_encryption'] ?? 'ssl' ), 'tls' ); ?>>TLS</option>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="imap_host"><?php esc_html_e( 'Servidor IMAP (opcional)', 'atora-lms' ); ?></label></th>
			<td><input type="text" class="regular-text" id="imap_host" name="imap_host" value="<?php echo esc_attr( (string) ( $settings['imap_host'] ?? 'atmosferacreativa.com' ) ); ?>"></td>
		</tr>
		<tr>
			<th><label for="imap_port"><?php esc_html_e( 'Puerto IMAP', 'atora-lms' ); ?></label></th>
			<td><input type="number" min="1" max="65535" id="imap_port" name="imap_port" value="<?php echo esc_attr( (string) absint( $settings['imap_port'] ?? 993 ) ); ?>"></td>
		</tr>
		<tr>
			<th><label for="imap_secure"><?php esc_html_e( 'Seguridad IMAP', 'atora-lms' ); ?></label></th>
			<td>
				<select id="imap_secure" name="imap_secure">
					<option value="ssl" <?php selected( (string) ( $settings['imap_secure'] ?? 'ssl' ), 'ssl' ); ?>>SSL/TLS</option>
					<option value="tls" <?php selected( (string) ( $settings['imap_secure'] ?? 'ssl' ), 'tls' ); ?>>TLS</option>
				</select>
			</td>
		</tr>
		<tr>
			<th><label for="imap_pass"><?php esc_html_e( 'Contraseña IMAP', 'atora-lms' ); ?></label></th>
			<td>
				<input type="password" class="regular-text" id="imap_pass" name="imap_pass" value="">
				<p class="description"><?php esc_html_e( 'Opcional. Solo si vas a leer bandeja IMAP desde ATORA.', 'atora-lms' ); ?></p>
			</td>
		</tr>
			<tr>
				<th><label for="identity_key"><?php esc_html_e( 'Identidad principal', 'atora-lms' ); ?></label></th>
				<td>
					<select id="identity_key" name="identity_key">
						<option value="academia" <?php selected( $wizard_identity, 'academia' ); ?>><?php esc_html_e( 'Academia / Admin', 'atora-lms' ); ?></option>
						<option value="teacher" <?php selected( $wizard_identity, 'teacher' ); ?>><?php esc_html_e( 'Docencia', 'atora-lms' ); ?></option>
						<option value="admin" <?php selected( $wizard_identity, 'admin' ); ?>><?php esc_html_e( 'Comercial', 'atora-lms' ); ?></option>
					</select>
				</td>
			</tr>
	</table>

	<p style="display:flex;gap:8px;align-items:center;">
		<button type="button" class="button" id="atora-wizard-apply"><?php esc_html_e( 'Aplicar preset', 'atora-lms' ); ?></button>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar asistente', 'atora-lms' ); ?></button>
	</p>
</form>

<?php if ( ! empty( $app_password_links ) && is_array( $app_password_links ) ) : ?>
	<section class="atora-emails-admin__callout" style="margin-top:12px;">
		<h3><?php esc_html_e( '¿Tu cuenta pide App Password?', 'atora-lms' ); ?></h3>
		<p><?php esc_html_e( 'Si Gmail, Outlook, Yahoo o iCloud rechazan tu clave normal, crea una contraseña de aplicación y úsala en SMTP/IMAP.', 'atora-lms' ); ?></p>
		<div class="atora-emails-admin__help-grid">
			<?php foreach ( $app_password_links as $help_item ) : ?>
				<a class="atora-emails-admin__help-card" href="<?php echo esc_url( (string) ( $help_item['url'] ?? '' ) ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html( (string) ( $help_item['label'] ?? __( 'Proveedor', 'atora-lms' ) ) ); ?>
					<span><?php esc_html_e( 'Abrir guía de App Password', 'atora-lms' ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<script>
(() => {
	const btn = document.getElementById('atora-wizard-apply');
	const preset = document.getElementById('wizard_preset');
	const provider = document.getElementById('wizard_provider');
	if (!btn || !preset) return;

	const presets = {
		manual: null,
		atmosfera: { smtp_host: 'atmosferacreativa.com', smtp_port: '465', smtp_secure: 'ssl', imap_host: 'atmosferacreativa.com', imap_port: '993', imap_secure: 'ssl', provider: 'smtp' },
		gmail: { smtp_host: 'smtp.gmail.com', smtp_port: '465', smtp_secure: 'ssl', imap_host: 'imap.gmail.com', imap_port: '993', imap_secure: 'ssl', provider: 'smtp' },
		outlook: { smtp_host: 'smtp.office365.com', smtp_port: '587', smtp_secure: 'tls', imap_host: 'outlook.office365.com', imap_port: '993', imap_secure: 'ssl', provider: 'smtp' },
		yahoo: { smtp_host: 'smtp.mail.yahoo.com', smtp_port: '465', smtp_secure: 'ssl', imap_host: 'imap.mail.yahoo.com', imap_port: '993', imap_secure: 'ssl', provider: 'smtp' },
		icloud: { smtp_host: 'smtp.mail.me.com', smtp_port: '587', smtp_secure: 'tls', imap_host: 'imap.mail.me.com', imap_port: '993', imap_secure: 'ssl', provider: 'smtp' },
		zoho: { smtp_host: 'smtp.zoho.com', smtp_port: '465', smtp_secure: 'ssl', imap_host: 'imap.zoho.com', imap_port: '993', imap_secure: 'ssl', provider: 'smtp' }
	};

	btn.addEventListener('click', () => {
		const selected = presets[preset.value] || null;
		if (!selected) return;
		const set = (id, value) => {
			const el = document.getElementById(id);
			if (el) el.value = value;
		};
		set('smtp_host', selected.smtp_host);
		set('smtp_port', selected.smtp_port);
		set('smtp_secure', selected.smtp_secure);
		set('imap_host', selected.imap_host);
		set('imap_port', selected.imap_port);
		set('imap_secure', selected.imap_secure);
		if (provider && selected.provider) provider.value = selected.provider;
	});
})();
</script>
