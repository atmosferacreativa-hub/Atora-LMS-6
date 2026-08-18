<?php
/**
 * Partial: Video / tagline — single-course (overview)
 * Variables: $embed_src, $hero_direct_video_url, $hero_fallback_iframe_src, $tagline
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$has_video = $embed_src || $hero_direct_video_url || $hero_fallback_iframe_src;
?>
<div class="cov-video">
	<?php if ( $tagline ) : ?>
		<p class="cov-tagline<?php echo $has_video ? '' : ' cov-tagline--standalone'; ?>"><?php echo esc_html( $tagline ); ?></p>
	<?php endif; ?>

	<?php if ( $has_video ) : ?>
		<div class="cov-video-wrap">
			<?php if ( $embed_src ) : ?>
				<iframe
					src="<?php echo esc_url( $embed_src ); ?>"
					title="<?php esc_attr_e( 'Video del curso', 'atora-lms' ); ?>"
					allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
					allowfullscreen
					loading="lazy"
				></iframe>
			<?php elseif ( $hero_fallback_iframe_src ) : ?>
				<iframe
					src="<?php echo esc_url( $hero_fallback_iframe_src ); ?>"
					title="<?php esc_attr_e( 'Video del curso', 'atora-lms' ); ?>"
					allowfullscreen
					loading="lazy"
				></iframe>
			<?php elseif ( $hero_direct_video_url ) : ?>
				<video controls preload="metadata" style="position:absolute;top:0;left:0;width:100%;height:100%;">
					<source src="<?php echo esc_url( $hero_direct_video_url ); ?>" type="<?php echo esc_attr( wp_check_filetype( $hero_direct_video_url )['type'] ?: 'video/mp4' ); ?>">
					<?php esc_html_e( 'Tu navegador no admite reproducción de video.', 'atora-lms' ); ?>
				</video>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div><!-- .cov-video -->
