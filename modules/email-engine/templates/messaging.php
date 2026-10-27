<?php
/**
 * Vista común de las plantillas académicas del enrutador de mensajes (6.38.0).
 *
 * Usa `body` y `button_text` del catálogo con las variables del envío. Todo se escapa al reemplazar.
 *
 * @package ATORA_LMS\EmailEngine
 *
 * @var array $atora_template Plantilla del catálogo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$atora_template = isset( $atora_template ) && is_array( $atora_template ) ? $atora_template : array();
$atora_ctx      = get_defined_vars();
$atora_name     = '';
foreach ( array( 'recipient_name', 'student_name', 'teacher_name', 'display_name' ) as $atora_key ) {
	if ( ! empty( $atora_ctx[ $atora_key ] ) && is_scalar( $atora_ctx[ $atora_key ] ) ) {
		$atora_name = (string) $atora_ctx[ $atora_key ];
		break;
	}
}
$atora_headline = ! empty( $atora_ctx['headline'] ) && is_scalar( $atora_ctx['headline'] )
	? esc_html( (string) $atora_ctx['headline'] )
	: esc_html( (string) ( $atora_template['name'] ?? '' ) );
$atora_body     = \ATORA\EmailEngine\Email_Templates::render_string( (string) ( $atora_template['body'] ?? '' ), $atora_ctx );
$atora_button   = \ATORA\EmailEngine\Email_Templates::render_string( (string) ( $atora_template['button_text'] ?? '' ), $atora_ctx );
$atora_link     = ! empty( $atora_ctx['button_url'] ) ? $atora_ctx['button_url'] : ( $atora_ctx['dashboard_url'] ?? '' );
$atora_url      = is_scalar( $atora_link ) ? esc_url( (string) $atora_link ) : '';
?>
<h1 style="color:#0f172a;font-size:22px;margin:0 0 16px;"><?php echo $atora_headline; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado arriba. ?></h1>
<?php if ( '' !== $atora_name ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: name */ __( 'Hola %s,', 'atora-lms' ), $atora_name ) ); ?></p>
<?php endif; ?>
<p style="white-space:pre-line;"><?php echo $atora_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_string escapa cada variable. ?></p>
<?php if ( '' !== $atora_url && '' !== trim( wp_strip_all_tags( $atora_button ) ) ) : ?>
<p><a href="<?php echo $atora_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" style="display:inline-block;background:#0f172a;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;"><?php echo $atora_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></p>
<?php endif; ?>
<?php if ( ! empty( $atora_ctx['footer_note'] ) && is_scalar( $atora_ctx['footer_note'] ) ) : ?>
<p style="color:#64748b;font-size:13px;"><?php echo esc_html( (string) $atora_ctx['footer_note'] ); ?></p>
<?php endif; ?>
