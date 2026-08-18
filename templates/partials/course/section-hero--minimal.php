<?php
/**
 * Partial: Hero section — variante "minimal"
 * Solo título, tagline y CTA. Sin imagen, sin meta, sin precio.
 * Variables: mismas que section-hero.php (via extract en render_hero).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-hero cc-hero--minimal">
	<p class="cc-hero-kicker"><?php esc_html_e( 'Curso', 'atora-lms' ); ?></p>
	<h1 class="cc-hero-title"><?php echo esc_html( $title ); ?></h1>

	<?php if ( $tagline ) : ?>
		<p class="cc-hero-tagline"><?php echo esc_html( $tagline ); ?></p>
	<?php elseif ( $subtitle ) : ?>
		<p class="cc-hero-tagline"><?php echo esc_html( $subtitle ); ?></p>
	<?php endif; ?>

	<?php if ( $cta_url ) : ?>
		<a class="cc-btn-primary cc-btn-minimal" href="<?php echo esc_url( $cta_url ); ?>">
			<?php echo esc_html( $cta_label ); ?>
		</a>
	<?php elseif ( ! is_user_logged_in() ) : ?>
		<a class="cc-btn-primary cc-btn-minimal" href="<?php echo esc_url( wp_login_url( $course_permalink ) ); ?>">
			<?php esc_html_e( 'Iniciar sesión', 'atora-lms' ); ?>
		</a>
	<?php endif; ?>
</div>
<style>
.cc-hero--minimal{padding:24px 0 8px}
.cc-btn-minimal{display:inline-flex;width:auto;padding:12px 28px;margin-top:10px}
</style>
