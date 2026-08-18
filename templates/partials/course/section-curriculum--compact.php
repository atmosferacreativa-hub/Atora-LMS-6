<?php
/**
 * Partial: Curriculum — variante "compact"
 * Lista numerada simple <ol>, sin fondo/card, mínimo espacio.
 * Variables: $lesson_ids (array of int), $preview_max (int)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-section cc-section--curriculum-compact">
	<h2 class="cc-section-title"><?php esc_html_e( 'Contenido del curso', 'atora-lms' ); ?></h2>
	<ol class="cc-curriculum-ol">
		<?php
		$shown = 0;
		foreach ( $lesson_ids as $index => $lid ) :
			$lesson = get_post( $lid );
			if ( ! $lesson || 'publish' !== $lesson->post_status ) { continue; }
			if ( $preview_max > 0 && $shown >= $preview_max ) { break; }
			$shown++;
		?>
			<li><?php echo esc_html( get_the_title( $lid ) ); ?></li>
		<?php endforeach; ?>
	</ol>
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
.cc-curriculum-ol{padding-left:22px;margin:0;display:grid;gap:6px}
.cc-curriculum-ol li{font-size:14px;color:var(--cc-muted);line-height:1.6;padding:2px 0}
</style>
