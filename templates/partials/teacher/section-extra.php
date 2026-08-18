<?php
/**
 * Partial: teacher extra section (título H2 + contenido libre)
 * Variables disponibles: $data, $section, $schema, $ctx
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** @var array $data @var array $section @var array $schema @var object $ctx */
?>
<section class="tp-extra">
	<div class="tp-extra__inner">
		<?php if ( $data['extra_title'] ) : ?>
			<h2 class="tp-extra__title"><?php echo esc_html( $data['extra_title'] ); ?></h2>
		<?php endif; ?>
		<?php if ( $data['extra_content'] ) : ?>
			<div class="tp-extra__content">
				<?php echo wp_kses_post( wpautop( $data['extra_content'] ) ); ?>
			</div>
		<?php endif; ?>
	</div>
</section>
