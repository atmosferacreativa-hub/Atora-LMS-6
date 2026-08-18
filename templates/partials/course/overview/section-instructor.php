<?php
/**
 * Partial: Instructor — single-course (overview)
 * Variables: $overview_instructor_html, $overview_instructor_title
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="cov-instructor">
	<h2 class="cov-section-title"><?php echo esc_html( $overview_instructor_title ); ?></h2>
	<?php echo $overview_instructor_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
</div>
