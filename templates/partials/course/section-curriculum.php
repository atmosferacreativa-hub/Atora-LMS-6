<?php
/**
 * Partial: Curriculum preview — single-course-commercial
 * Variables: $lesson_ids (array of int), $preview_max (int)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-section">
	<h2 class="cc-section-title"><?php esc_html_e( 'Contenido del curso', 'atora-lms' ); ?></h2>
	<ul class="cc-lessons-list">
		<?php
		$shown = 0;
		foreach ( $lesson_ids as $index => $lid ) :
			$lesson = get_post( $lid );
			if ( ! $lesson || 'publish' !== $lesson->post_status ) { continue; }
			if ( $preview_max > 0 && $shown >= $preview_max ) { break; }
			$shown++;
		?>
			<li>
				<span class="cc-lesson-num"><?php echo esc_html( $index + 1 ); ?></span>
				<?php echo esc_html( get_the_title( $lid ) ); ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php if ( $preview_max > 0 && count( $lesson_ids ) > $preview_max ) : ?>
		<p class="cc-lessons-locked">
			<?php
			printf(
				esc_html__( '+ %d lecciones más disponibles tras inscribirse.', 'atora-lms' ),
				count( $lesson_ids ) - $preview_max
			);
			?>
		</p>
	<?php endif; ?>
</div>
