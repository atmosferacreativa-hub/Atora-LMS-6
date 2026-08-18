<?php
/**
 * Partial: Curriculum — variante "accordion"
 * Lecciones dentro de <details>/<summary>. Sin JS, solo HTML nativo.
 * Variables: $lesson_ids (array of int), $preview_max (int)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-section cc-section--curriculum-accordion">
	<h2 class="cc-section-title"><?php esc_html_e( 'Contenido del curso', 'atora-lms' ); ?></h2>
	<div class="cc-accordion">
		<?php
		$shown = 0;
		foreach ( $lesson_ids as $index => $lid ) :
			$lesson = get_post( $lid );
			if ( ! $lesson || 'publish' !== $lesson->post_status ) { continue; }
			if ( $preview_max > 0 && $shown >= $preview_max ) { break; }
			$shown++;
		?>
			<details class="cc-accordion-item" <?php echo 0 === $index ? 'open' : ''; ?>>
				<summary class="cc-accordion-summary">
					<span class="cc-lesson-num"><?php echo esc_html( $index + 1 ); ?></span>
					<?php echo esc_html( get_the_title( $lid ) ); ?>
				</summary>
				<div class="cc-accordion-body">
					<?php
					$excerpt = get_the_excerpt( $lid );
					if ( $excerpt ) {
						echo '<p class="cc-accordion-excerpt">' . esc_html( $excerpt ) . '</p>';
					} else {
						esc_html_e( 'Contenido disponible al inscribirse.', 'atora-lms' );
					}
					?>
				</div>
			</details>
		<?php endforeach; ?>
	</div>
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
<style>
.cc-accordion{display:grid;gap:6px}
.cc-accordion-item{background:var(--cc-card);border:1px solid var(--cc-border);border-radius:10px;overflow:hidden}
.cc-accordion-summary{display:flex;align-items:center;gap:10px;padding:12px 16px;font-size:14px;font-weight:600;color:var(--cc-text);cursor:pointer;list-style:none;user-select:none}
.cc-accordion-summary::-webkit-details-marker{display:none}
.cc-accordion-summary::after{content:"▾";margin-left:auto;font-size:12px;color:var(--cc-subtle);transition:transform .2s}
details[open] .cc-accordion-summary::after{transform:rotate(-180deg)}
.cc-accordion-body{padding:0 16px 14px;font-size:13px;color:var(--cc-muted);line-height:1.6}
.cc-accordion-excerpt{margin:0}
</style>
