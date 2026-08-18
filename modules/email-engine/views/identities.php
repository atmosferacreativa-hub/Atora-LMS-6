<?php
/**
 * Tab: Identidades.
 *
 * @package ATORA_LMS\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rows = array(
	'academia' => array(
		'label'  => __( 'Academia / Admin', 'atora-lms' ),
		'prefix' => 'identity_academia',
	),
	'teacher'  => array(
		'label'  => __( 'Docencia', 'atora-lms' ),
		'prefix' => 'identity_teacher',
	),
	'admin'    => array(
		'label'  => __( 'Comercial', 'atora-lms' ),
		'prefix' => 'identity_admin',
	),
);

$identities_option = get_option( 'atora_email_identities', array() );
$identities_option = is_array( $identities_option ) ? $identities_option : array();
$option_key_map    = array(
	'academia' => 'academia',
	'teacher'  => 'comercio',
	'admin'    => 'administracion',
);
?>
<h2><?php esc_html_e( 'Identidades de correo', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Configura remitente, reply-to y credenciales SMTP/IMAP por área. Si dejas contraseña vacía, se mantiene la guardada.', 'atora-lms' ); ?></p>

<section class="atora-emails-admin__callout">
	<h3><?php esc_html_e( 'Guía rápida para usuarios básicos', 'atora-lms' ); ?></h3>
	<p><?php esc_html_e( 'Cada identidad controla desde qué buzón sale cada flujo:', 'atora-lms' ); ?></p>
	<ul style="margin:0 0 8px 18px;">
		<li><?php esc_html_e( 'Academia / Admin: operación de plataforma, tienda WooCommerce, afiliados y notificaciones institucionales.', 'atora-lms' ); ?></li>
		<li><?php esc_html_e( 'Docencia: seguimiento académico, bienvenida, estimulación y orientación de estudiantes inscritos.', 'atora-lms' ); ?></li>
		<li><?php esc_html_e( 'Comercial: captación y nurturing de leads/prospectos, campañas y cierres.', 'atora-lms' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'Si tu proveedor pide App Password, crea la clave y pégala en contraseña SMTP (y en IMAP si usarás lectura de bandeja).', 'atora-lms' ); ?></p>
	<?php if ( ! empty( $app_password_links ) && is_array( $app_password_links ) ) : ?>
		<div class="atora-emails-admin__help-grid">
			<?php foreach ( $app_password_links as $help_item ) : ?>
				<a class="atora-emails-admin__help-card" href="<?php echo esc_url( (string) ( $help_item['url'] ?? '' ) ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html( (string) ( $help_item['label'] ?? __( 'Proveedor', 'atora-lms' ) ) ); ?>
					<span><?php esc_html_e( 'Abrir guía oficial de App Password', 'atora-lms' ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>

<form method="post">
	<?php wp_nonce_field( 'atora_email_admin_action', 'atora_email_nonce' ); ?>
	<input type="hidden" name="atora_email_action" value="save_identities">

	<div class="atora-emails-admin__table-wrap">
		<table class="widefat striped" style="min-width:1180px;max-width:1200px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Identidad', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Nombre remitente', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Email remitente', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Reply-to', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Usuario SMTP', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Contraseña SMTP', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Usuario IMAP', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Contraseña IMAP', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Activa', 'atora-lms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $identity_key => $row ) : ?>
					<?php $prefix = (string) $row['prefix']; ?>
					<?php
					$option_key   = sanitize_key( (string) ( $option_key_map[ $identity_key ] ?? $identity_key ) );
					$identity_row = isset( $identities_option[ $option_key ] ) && is_array( $identities_option[ $option_key ] )
						? $identities_option[ $option_key ]
						: array();
					$smtp_user    = (string) ( $identity_row['smtp_username'] ?? $identity_row['smtp_user'] ?? $settings['smtp_user'] ?? '' );
					$imap_user    = (string) ( $identity_row['imap_username'] ?? $settings['imap_user'] ?? '' );
					$has_smtp_password = '' !== (string) ( $identity_row['smtp_password_encrypted'] ?? '' ) || '' !== (string) ( $identity_row['smtp_password'] ?? '' );
					$has_imap_password = '' !== (string) ( $identity_row['imap_password_encrypted'] ?? '' ) || '' !== (string) ( $identity_row['imap_password'] ?? '' );
					?>
					<tr>
						<td><strong><?php echo esc_html( (string) $row['label'] ); ?></strong></td>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix . '_from_name' ); ?>" value="<?php echo esc_attr( (string) ( $settings[ $prefix . '_from_name' ] ?? '' ) ); ?>"></td>
						<td><input type="email" class="regular-text" name="<?php echo esc_attr( $prefix . '_from_email' ); ?>" value="<?php echo esc_attr( (string) ( $settings[ $prefix . '_from_email' ] ?? '' ) ); ?>"></td>
						<td><input type="email" class="regular-text" name="<?php echo esc_attr( $prefix . '_reply_to' ); ?>" value="<?php echo esc_attr( (string) ( $settings[ $prefix . '_reply_to' ] ?? '' ) ); ?>"></td>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix . '_smtp_user' ); ?>" value="<?php echo esc_attr( $smtp_user ); ?>"></td>
						<td>
							<input type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr( $prefix . '_smtp_pass' ); ?>" value="" placeholder="<?php esc_attr_e( 'Escribe nueva contraseña o deja vacío', 'atora-lms' ); ?>">
							<p class="description" style="margin:4px 0 0;">
								<?php echo esc_html( $has_smtp_password ? __( 'Actualmente: contraseña guardada.', 'atora-lms' ) : __( 'Actualmente: sin contraseña guardada.', 'atora-lms' ) ); ?>
							</p>
						</td>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( $prefix . '_imap_user' ); ?>" value="<?php echo esc_attr( $imap_user ); ?>"></td>
						<td>
							<input type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr( $prefix . '_imap_pass' ); ?>" value="" placeholder="<?php esc_attr_e( 'Escribe nueva contraseña o deja vacío', 'atora-lms' ); ?>">
							<p class="description" style="margin:4px 0 0;">
								<?php echo esc_html( $has_imap_password ? __( 'Actualmente: contraseña guardada.', 'atora-lms' ) : __( 'Actualmente: sin contraseña guardada.', 'atora-lms' ) ); ?>
							</p>
						</td>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $prefix . '_active' ); ?>" value="1" <?php checked( ! empty( $settings[ $prefix . '_active' ] ) || ! isset( $settings[ $prefix . '_active' ] ) ); ?>> <?php esc_html_e( 'Sí', 'atora-lms' ); ?></label></td>
					</tr>
					<tr>
						<td colspan="9" style="background:#f8fafc;">
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-emails&tab=test-send&identity=' . $identity_key ) ); ?>">
								<?php esc_html_e( 'Probar esta identidad', 'atora-lms' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<p class="atora-emails-admin__note"><?php esc_html_e( 'Tip UX: en móviles usa scroll horizontal en la tabla y completa una identidad a la vez.', 'atora-lms' ); ?></p>

	<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar identidades', 'atora-lms' ); ?></button></p>
</form>
