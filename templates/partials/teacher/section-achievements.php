<?php
/**
 * Partial: teacher achievements + video
 * Variables disponibles: $data, $section, $schema, $ctx
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** @var array $data @var array $section @var array $schema @var object $ctx */

$has_video = ! empty( $data['video_url'] );
$embed     = $has_video ? CLMS_UI_Teacher_Sections::get_video_embed( $data['video_url'] ) : '';
?>
<section class="tp-achievements">
	<div class="tp-achievements__inner<?php echo ( $has_video && $embed ) ? ' tp-achievements__inner--split' : ''; ?>">

		<div class="tp-achievements__col">
			<h2 class="tp-section-title"><?php esc_html_e( 'Logros y experiencia', 'atora-lms' ); ?></h2>
			<ul class="tp-achievements__list">
				<?php foreach ( $data['achievements'] as $item ) : ?>
					<li class="tp-achievements__item">
						<span class="tp-achievements__icon" aria-hidden="true">✓</span>
						<?php echo esc_html( $item ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>

		<?php if ( $has_video && $embed ) : ?>
			<div class="tp-achievements__video">
				<div class="tp-video-embed">
					<?php echo $embed; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			</div>
		<?php endif; ?>

	</div>
</section>
