<?php
/**
 * Partial: Instructor section — single-course-commercial
 * Variables: $instructor_html (string), $teacher_section_title (string)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-section cc-section--instructor">
	<h2 class="cc-section-title"><?php echo esc_html( $teacher_section_title ); ?></h2>
	<?php echo $instructor_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
</div>
