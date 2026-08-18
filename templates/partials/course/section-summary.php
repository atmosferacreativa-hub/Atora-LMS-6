<?php
/**
 * Partial: Summary section — single-course-commercial
 * Variables: $course_include_items (array of strings)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-section cc-section--summary">
	<h2 class="cc-section-title"><?php esc_html_e( 'Resumen del curso', 'atora-lms' ); ?></h2>
	<div class="cc-include-grid">
		<?php foreach ( $course_include_items as $item ) : ?>
			<div class="cc-include-card"><?php echo esc_html( $item ); ?></div>
		<?php endforeach; ?>
	</div>
</div>
