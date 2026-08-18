<?php
/**
 * Partial: CTA bottom section — single-course-commercial
 * Variables: $cta_url, $cta_label, $tagline, $subtitle, $course_permalink
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-cta-bottom">
	<p class="cc-cta-title"><?php esc_html_e( '¿Listo para comenzar?', 'atora-lms' ); ?></p>
	<p><?php echo esc_html( $tagline ?: $subtitle ); ?></p>
	<?php if ( $cta_url ) : ?>
		<a class="cc-btn-white" href="<?php echo esc_url( $cta_url ); ?>">
			<?php echo esc_html( $cta_label ); ?>
		</a>
	<?php elseif ( ! is_user_logged_in() ) : ?>
		<a class="cc-btn-white" href="<?php echo esc_url( wp_login_url( $course_permalink ) ); ?>">
			<?php esc_html_e( 'Iniciar sesión', 'atora-lms' ); ?>
		</a>
	<?php endif; ?>
</div>
