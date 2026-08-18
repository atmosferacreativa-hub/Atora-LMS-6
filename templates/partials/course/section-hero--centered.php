<?php
/**
 * Partial: Hero section — variante "centered"
 * Layout centrado: imagen de fondo difuminada, título y CTA al centro.
 * Variables: mismas que section-hero.php (via extract en render_hero).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-hero cc-hero--centered">
	<?php if ( ! empty( $hero_featured_image_url ) ) : ?>
		<div class="cc-hero-bg" style="background-image:url('<?php echo esc_url( $hero_featured_image_url ); ?>');" aria-hidden="true"></div>
	<?php endif; ?>

	<div class="cc-hero-centered-body">
		<p class="cc-hero-kicker"><?php esc_html_e( 'Curso', 'atora-lms' ); ?></p>
		<h1 class="cc-hero-title"><?php echo esc_html( $title ); ?></h1>

		<?php if ( $tagline ) : ?>
			<p class="cc-hero-tagline"><?php echo esc_html( $tagline ); ?></p>
		<?php elseif ( $subtitle ) : ?>
			<p class="cc-hero-tagline"><?php echo esc_html( $subtitle ); ?></p>
		<?php endif; ?>

		<div class="cc-hero-meta">
			<?php if ( $duration ) : ?>
				<span>⏱ <?php echo esc_html( $duration ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $lesson_ids ) ) : ?>
				<span>📚 <?php echo esc_html( count( $lesson_ids ) ); ?> <?php esc_html_e( 'lecciones', 'atora-lms' ); ?></span>
			<?php endif; ?>
			<?php if ( $certificate ) : ?>
				<span>🎓 <?php esc_html_e( 'Certificado incluido', 'atora-lms' ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $price ) : ?>
			<p class="cc-hero-price"><?php echo esc_html( $price ); ?></p>
		<?php endif; ?>

		<?php if ( $cta_url ) : ?>
			<a class="cc-btn-primary cc-btn-centered" href="<?php echo esc_url( $cta_url ); ?>">
				<?php echo esc_html( $cta_label ); ?>
			</a>
		<?php elseif ( ! is_user_logged_in() ) : ?>
			<a class="cc-btn-primary cc-btn-centered" href="<?php echo esc_url( wp_login_url( $course_permalink ) ); ?>">
				<?php esc_html_e( 'Iniciar sesión', 'atora-lms' ); ?>
			</a>
		<?php endif; ?>
	</div>
</div>
<style>
.cc-hero--centered{position:relative;text-align:center;padding:56px 20px 48px;overflow:hidden;border-radius:16px}
.cc-hero-bg{position:absolute;inset:0;background-size:cover;background-position:center;filter:blur(4px) brightness(.35);transform:scale(1.05)}
.cc-hero-centered-body{position:relative;z-index:1;max-width:720px;margin:0 auto}
.cc-hero--centered .cc-hero-kicker{color:rgba(255,255,255,.8)}
.cc-hero--centered .cc-hero-title{color:#fff}
.cc-hero--centered .cc-hero-tagline{color:rgba(255,255,255,.85)}
.cc-hero--centered .cc-hero-meta{justify-content:center;color:rgba(255,255,255,.75)}
.cc-hero--centered .cc-hero-price{color:#fff;font-size:28px;font-weight:800;margin:16px 0 6px}
.cc-btn-centered{display:inline-flex;width:auto;padding:14px 36px;margin-top:12px}
</style>
