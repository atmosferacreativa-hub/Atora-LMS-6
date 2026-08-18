<?php
/**
 * Partial: teacher hero
 * Variables disponibles: $data, $section, $schema, $ctx
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** @var array $data @var array $section @var array $schema @var object $ctx */
?>
<section class="tp-hero">
	<div class="tp-hero__inner">
		<div class="tp-hero__avatar">
			<?php if ( $data['photo_id'] ) : ?>
				<?php echo wp_get_attachment_image( $data['photo_id'], 'large', false, array( 'alt' => esc_attr( $data['name'] ), 'class' => 'tp-hero__photo' ) ); ?>
			<?php else : ?>
				<span class="tp-hero__initials"><?php echo esc_html( mb_substr( $data['name'], 0, 1 ) ); ?></span>
			<?php endif; ?>
		</div>

		<div class="tp-hero__content">
			<?php if ( $data['specialty'] ) : ?>
				<p class="tp-hero__kicker"><?php echo esc_html( $data['specialty'] ); ?></p>
			<?php endif; ?>

			<h1 class="tp-hero__name"><?php echo esc_html( $data['name'] ); ?></h1>

			<?php if ( $data['short_bio'] ) : ?>
				<p class="tp-hero__tagline"><?php echo esc_html( $data['short_bio'] ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $data['socials'] ) ) : ?>
				<nav class="tp-hero__socials" aria-label="<?php esc_attr_e( 'Redes del docente', 'atora-lms' ); ?>">
					<?php foreach ( $data['socials'] as $social ) : ?>
						<a href="<?php echo esc_url( $social['url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $social['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>

			<?php if ( $data['published_courses_n'] > 0 ) : ?>
				<p class="tp-hero__meta">
					<?php printf(
						esc_html( _n( '%d curso publicado', '%d cursos publicados', $data['published_courses_n'], 'atora-lms' ) ),
						(int) $data['published_courses_n']
					); ?>
				</p>
			<?php endif; ?>
		</div>
	</div>
</section>
