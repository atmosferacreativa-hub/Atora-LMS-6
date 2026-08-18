<?php
/**
 * Template email: CRM campaign.
 *
 * Variables esperadas:
 * - string $display_name
 * - string $custom_subject
 * - string $custom_message
 * - string $cta_url
 * - string $site_name
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$subject = isset( $custom_subject ) ? sanitize_text_field( (string) $custom_subject ) : '';
$message = isset( $custom_message ) ? sanitize_textarea_field( (string) $custom_message ) : '';
$cta_url = isset( $cta_url ) ? esc_url( (string) $cta_url ) : '';
$name    = isset( $display_name ) ? sanitize_text_field( (string) $display_name ) : '';
$site    = isset( $site_name ) ? sanitize_text_field( (string) $site_name ) : '';

$lines = preg_split( '/\R+/', $message ) ?: array();
?>
<h1 style="margin:0 0 16px;font-size:26px;color:#0f172a;line-height:1.2;">
	<?php echo esc_html( '' !== $subject ? $subject : __( 'Nueva comunicación de tu academia', 'atora-lms' ) ); ?>
</h1>

<p style="margin:0 0 14px;font-size:16px;color:#334155;line-height:1.55;">
	<?php echo esc_html( sprintf( __( 'Hola %s,', 'atora-lms' ), '' !== $name ? $name : __( 'estudiante', 'atora-lms' ) ) ); ?>
</p>

<?php foreach ( $lines as $line ) : ?>
	<?php $line = trim( (string) $line ); ?>
	<?php if ( '' === $line ) : ?>
		<?php continue; ?>
	<?php endif; ?>
	<p style="margin:0 0 12px;font-size:15px;color:#334155;line-height:1.6;">
		<?php echo esc_html( $line ); ?>
	</p>
<?php endforeach; ?>

<?php if ( '' !== $cta_url ) : ?>
	<p style="margin:20px 0 0;">
		<a href="<?php echo esc_url( $cta_url ); ?>" style="display:inline-block;padding:11px 18px;background:#1d4ed8;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:700;">
			<?php esc_html_e( 'Ver detalle', 'atora-lms' ); ?>
		</a>
	</p>
<?php endif; ?>

<p style="margin:18px 0 0;font-size:12px;color:#64748b;line-height:1.5;">
	<?php echo esc_html( sprintf( __( 'Mensaje enviado desde %s.', 'atora-lms' ), $site ) ); ?>
</p>
