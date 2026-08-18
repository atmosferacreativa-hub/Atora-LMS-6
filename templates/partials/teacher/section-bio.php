<?php
/**
 * Partial: teacher bio
 * Variables disponibles: $data, $section, $schema, $ctx
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** @var array $data @var array $section @var array $schema @var object $ctx */
?>
<section class="tp-bio">
	<div class="tp-bio__inner">
		<h2 class="tp-section-title"><?php esc_html_e( 'Sobre el docente', 'atora-lms' ); ?></h2>
		<div class="tp-bio__content">
			<?php echo wp_kses_post( wpautop( $data['long_bio'] ) ); ?>
		</div>
	</div>
</section>
