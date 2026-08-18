<?php
/**
 * Partial: Instructor section — variante "minimal"
 * Igual que la variante default pero con modificador CSS que oculta
 * bio extendida y logros, mostrando solo avatar + nombre + especialidad.
 * Variables: $instructor_html (string), $teacher_section_title (string)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-section cc-section--instructor cc-section--instructor-minimal">
	<h2 class="cc-section-title"><?php echo esc_html( $teacher_section_title ); ?></h2>
	<?php echo $instructor_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
</div>
<style>
.cc-section--instructor-minimal .atora-teacher-bio,
.cc-section--instructor-minimal .clms-instructor-bio,
.cc-section--instructor-minimal .atora-teacher-achievements,
.cc-section--instructor-minimal .clms-instructor-achievements,
.cc-section--instructor-minimal .atora-teacher-socials,
.cc-section--instructor-minimal .clms-instructor-socials{display:none}
.cc-section--instructor-minimal .atora-teacher-grid,
.cc-section--instructor-minimal .clms-instructor-grid{display:flex;flex-wrap:wrap;gap:12px}
.cc-section--instructor-minimal .atora-teacher-card,
.cc-section--instructor-minimal .clms-instructor-box{flex:1;min-width:200px}
</style>
