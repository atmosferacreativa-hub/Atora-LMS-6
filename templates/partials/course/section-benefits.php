<?php
/**
 * Partial: Benefits section — single-course-commercial
 * Variables: $benefit_items (array of strings)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-section cc-section--benefits">
	<h2 class="cc-section-title"><?php esc_html_e( '¿Qué aprenderás?', 'atora-lms' ); ?></h2>
	<ul class="cc-benefits-list">
		<?php foreach ( $benefit_items as $item ) : ?>
			<li><?php echo esc_html( $item ); ?></li>
		<?php endforeach; ?>
	</ul>
</div>
