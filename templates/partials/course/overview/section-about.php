<?php
/**
 * Partial: About / descripción del curso — single-course (overview)
 * Variables: $course_content
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="cov-about">
	<h2 class="cov-section-title"><?php esc_html_e( 'Acerca de este curso', 'atora-lms' ); ?></h2>
	<div class="cov-about-content">
		<?php echo apply_filters( 'the_content', $course_content ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</div>
</div>
