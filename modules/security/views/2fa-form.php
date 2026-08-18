<?php
/**
 * Vista: formulario de verificación 2FA en la pantalla de login
 *
 * @package ATORA_LMS\Security
 * @since   5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user_id = ATORA\Security\Two_FA_Manager::get_pending_user();
$user    = $user_id ? get_userdata( $user_id ) : null;
?>
<div class="atora-2fa-wrap" style="max-width:360px;margin:0 auto;padding:24px;">
	<div style="text-align:center;margin-bottom:24px;">
		<span style="font-size:48px;">🔐</span>
		<h2 style="margin:8px 0 4px;"><?php esc_html_e( 'Verificación en dos pasos', 'atora-lms' ); ?></h2>
		<p style="color:#64748b;font-size:14px;margin:0;">
			<?php
			if ( $user ) {
				printf(
					/* translators: %s: email parcial */
					esc_html__( 'Código enviado a %s', 'atora-lms' ),
					esc_html( self::mask_email( $user->user_email ) )
				);
			} else {
				esc_html_e( 'Ingresa el código de verificación.', 'atora-lms' );
			}
			?>
		</p>
	</div>

	<form method="post" action="<?php echo esc_url( ATORA\Security\Two_FA_Manager::get_2fa_url() ); ?>" id="atora-2fa-form">
		<?php wp_nonce_field( 'atora_2fa_verify' ); ?>

		<p>
			<label for="atora_2fa_code" class="screen-reader-text">
				<?php esc_html_e( 'Código de 6 dígitos', 'atora-lms' ); ?>
			</label>
			<input type="text"
			       id="atora_2fa_code"
			       name="atora_2fa_code"
			       class="input"
			       inputmode="numeric"
			       autocomplete="one-time-code"
			       maxlength="6"
			       placeholder="000000"
			       autofocus
			       required
			       style="text-align:center;font-size:24px;letter-spacing:8px;width:100%;padding:12px;">
		</p>

		<p>
			<label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
				<input type="checkbox" name="atora_2fa_trust_device" value="1">
				<?php
				printf(
					/* translators: %d: días */
					esc_html__( 'Confiar en este dispositivo por %d días', 'atora-lms' ),
					ATORA\Security\Two_FA_Manager::TRUSTED_DEVICE_DAYS
				);
				?>
			</label>
		</p>

		<p>
			<input type="submit"
			       name="wp-submit"
			       class="button button-primary button-large"
			       value="<?php esc_attr_e( 'Verificar', 'atora-lms' ); ?>"
			       style="width:100%;">
		</p>
	</form>

	<div style="text-align:center;margin-top:16px;font-size:13px;">
		<a href="#" id="atora-2fa-resend"><?php esc_html_e( 'Reenviar código', 'atora-lms' ); ?></a>
		&nbsp;·&nbsp;
		<a href="#" id="atora-2fa-backup-toggle"><?php esc_html_e( 'Usar código de respaldo', 'atora-lms' ); ?></a>
	</div>

	<!-- Formulario de código de respaldo (oculto) -->
	<div id="atora-2fa-backup-form" style="display:none;margin-top:16px;">
		<form method="post" action="<?php echo esc_url( ATORA\Security\Two_FA_Manager::get_2fa_url() ); ?>">
			<?php wp_nonce_field( 'atora_2fa_verify' ); ?>
			<input type="hidden" name="atora_2fa_backup" value="1">
			<p>
				<input type="text"
				       name="atora_2fa_code"
				       class="input"
				       placeholder="<?php esc_attr_e( 'Código de respaldo', 'atora-lms' ); ?>"
				       style="width:100%;text-transform:uppercase;letter-spacing:4px;">
			</p>
			<p>
				<input type="submit"
				       class="button button-secondary"
				       value="<?php esc_attr_e( 'Usar código de respaldo', 'atora-lms' ); ?>"
				       style="width:100%;">
			</p>
		</form>
	</div>

	<p style="text-align:center;margin-top:16px;font-size:12px;color:#9ca3af;">
		<a href="<?php echo esc_url( wp_login_url() ); ?>">
			&larr; <?php esc_html_e( 'Volver al inicio de sesión', 'atora-lms' ); ?>
		</a>
	</p>
</div>

<script>
document.getElementById('atora-2fa-backup-toggle')?.addEventListener('click', function(e) {
	e.preventDefault();
	document.getElementById('atora-2fa-backup-form').style.display = 'block';
	this.style.display = 'none';
});

document.getElementById('atora-2fa-resend')?.addEventListener('click', function(e) {
	e.preventDefault();
	const btn = this;
	btn.textContent = '<?php echo esc_js( __( 'Enviando…', 'atora-lms' ) ); ?>';

	fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
		body: new URLSearchParams({
			action: 'atora_2fa_resend',
			nonce:  '<?php echo esc_js( wp_create_nonce( 'atora_2fa_resend' ) ); ?>'
		})
	})
	.then(r => r.json())
	.then(data => {
		btn.textContent = data.data?.message ?? '<?php echo esc_js( __( 'Reenviado', 'atora-lms' ) ); ?>';
		setTimeout(() => { btn.textContent = '<?php echo esc_js( __( 'Reenviar código', 'atora-lms' ) ); ?>'; }, 4000);
	});
});
</script>
<?php

/**
 * Enmascara parcialmente un email para mostrar en pantalla.
 *
 * @param string $email Email completo.
 * @return string
 */
function mask_email( string $email ): string {
	list( $local, $domain ) = explode( '@', $email, 2 );
	$masked_local = substr( $local, 0, 2 ) . str_repeat( '*', max( strlen( $local ) - 2, 2 ) );
	return $masked_local . '@' . $domain;
}
