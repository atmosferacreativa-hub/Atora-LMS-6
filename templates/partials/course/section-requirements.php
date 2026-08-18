<?php
/**
 * Partial: Requirements section — single-course-commercial
 * Variables: $requirement_items (array of strings)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-section">
	<h2 class="cc-section-title"><?php esc_html_e( 'Requisitos previos', 'atora-lms' ); ?></h2>
	<ul class="cc-req-list">
		<?php foreach ( $requirement_items as $item ) : ?>
			<li><?php echo esc_html( $item ); ?></li>
		<?php endforeach; ?>
	</ul>
</div>
