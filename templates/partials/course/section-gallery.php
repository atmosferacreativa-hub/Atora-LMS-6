<?php
/**
 * Partial: Gallery section — single-course-commercial
 * Variables: $gallery_image_ids (array of int)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-section">
	<h2 class="cc-section-title"><?php esc_html_e( 'Galería', 'atora-lms' ); ?></h2>
	<div class="cc-gallery">
		<?php foreach ( $gallery_image_ids as $img_id ) :
			echo wp_get_attachment_image( $img_id, 'medium', false, array( 'loading' => 'lazy' ) );
		endforeach; ?>
	</div>
</div>
