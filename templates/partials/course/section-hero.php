<?php
/**
 * Partial: Hero section — single-course-commercial
 * Variables en scope (via extract en CLMS_UI_Course_Commercial_Sections::render_hero):
 *   $title, $subtitle, $tagline, $duration, $lesson_ids, $certificate, $program_ids,
 *   $price, $price_label, $cta_url, $cta_label, $course_permalink,
 *   $hero_featured_image_html, $hero_featured_image_url,
 *   $embed_src, $hero_fallback_iframe_src, $hero_direct_video_url,
 *   $hero_has_video_media, $hero_has_direct_video
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-hero">
	<?php if ( ! empty( $hero_featured_image_html ) ) : ?>
		<figure class="cc-hero-media-top">
			<?php echo $hero_featured_image_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</figure>
	<?php endif; ?>

	<div class="cc-hero-heading">
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

		<?php if ( ! empty( $program_ids ) ) : ?>
			<div class="cc-program-chips">
				<?php foreach ( $program_ids as $program_id ) : ?>
					<a class="cc-program-chip" href="<?php echo esc_url( get_permalink( $program_id ) ); ?>">
						<?php echo esc_html( get_the_title( $program_id ) ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $hero_has_video_media || $price || $cta_url || ! is_user_logged_in() ) : ?>
		<div class="cc-hero-media-grid<?php echo $hero_has_video_media ? '' : ' cc-hero-media-grid--single'; ?>">
			<?php if ( $hero_has_video_media ) : ?>
				<div class="cc-hero-media-card">
					<?php if ( $embed_src ) : ?>
						<div class="cc-hero-video-ratio">
							<iframe src="<?php echo esc_url( $embed_src ); ?>" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
						</div>
					<?php elseif ( $hero_fallback_iframe_src ) : ?>
						<div class="cc-hero-video-ratio">
							<iframe src="<?php echo esc_url( $hero_fallback_iframe_src ); ?>" allowfullscreen loading="lazy" referrerpolicy="strict-origin-when-cross-origin"></iframe>
						</div>
					<?php elseif ( $hero_has_direct_video ) : ?>
						<div class="cc-hero-video-ratio">
							<video controls preload="metadata"<?php echo ! empty( $hero_featured_image_url ) ? ' poster="' . esc_url( $hero_featured_image_url ) . '"' : ''; ?>>
								<source src="<?php echo esc_url( $hero_direct_video_url ); ?>">
							</video>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="cc-hero-cta-box">
				<?php if ( $price ) : ?>
					<p class="cc-hero-price"><?php echo esc_html( $price ); ?></p>
					<?php if ( $price_label ) : ?>
						<p class="cc-hero-price-label"><?php echo esc_html( $price_label ); ?></p>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( $cta_url ) : ?>
					<a class="cc-btn-primary" href="<?php echo esc_url( $cta_url ); ?>">
						<?php echo esc_html( $cta_label ); ?>
					</a>
				<?php elseif ( ! is_user_logged_in() ) : ?>
					<a class="cc-btn-primary" href="<?php echo esc_url( wp_login_url( $course_permalink ) ); ?>">
						<?php esc_html_e( 'Iniciar sesión', 'atora-lms' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
</div>
